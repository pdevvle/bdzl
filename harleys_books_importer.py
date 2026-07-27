#!/usr/bin/env python3
"""
harleys_books_importer.py — two-phase Etsy -> WooCommerce catalog importer
for the HarleysBooks storefront.

The Etsy shop stays live indefinitely; this store is a parallel channel.
The importer therefore mirrors Etsy 1:1 — one active listing becomes one
simple WooCommerce product — and is safe to re-run on a schedule.

    Phase 1  fetch   Etsy Open API v3  ->  catalog.json + local image backups
    Phase 2  push    catalog.json      ->  WooCommerce products (REST API)

Re-runnability
--------------
Every product carries the Etsy listing id in two places:

    SKU        etsy-<listing_id>
    meta_data  etsy_listing_id

Phase 2 looks a product up by SKU first, so a second run updates in place
instead of creating duplicates.

Field mapping
-------------
All Etsy-specific field knowledge lives in exactly two helpers:

    _normalize_listing()   Etsy listing payload   -> neutral catalog item
    _normalize_product()   neutral catalog item   -> WooCommerce product body

If Etsy's v3 fields drift, fix them there. Do not scatter mapping logic
anywhere else in this file.

Dependencies
------------
`requests`, and nothing else. Everything else is the standard library.
Hold that line.

Credentials
-----------
Read from the environment only — never hardcode, never commit.

    ETSY_KEYSTRING          Etsy app keystring            (phase 1)
    ETSY_SHOP_ID            optional; skips shop lookup
    ETSY_SHOP_NAME          optional; used if no shop id

    WOO_STORE_URL           https://example.com           (phase 2)
    WOO_CONSUMER_KEY        ck_...                        (phase 2)
    WOO_CONSUMER_SECRET     cs_...                        (phase 2)

    STOCK_BUFFER            units held back from Woo stock (default 0)
    WOO_WEIGHT_UNIT         store weight unit    (default oz)
    WOO_DIMENSION_UNIT      store dimension unit (default in)

Etsy authorization
------------------
Etsy access tokens expire after one hour, so there is nothing useful to put
in a long-lived variable. Authorize once with the companion helper:

    python3 etsy_auth.py authorize

That stores an access token and a 90-day refresh token in .etsy_tokens.json
(overridable with ETSY_TOKEN_FILE). This importer reads that store and
refreshes automatically — including mid-run, if a long fetch outlives its
token.

    ETSY_OAUTH_TOKEN        optional escape hatch: use this exact token and
                            do not attempt any refresh. Handy for a one-off
                            with a token from elsewhere; it will start
                            failing an hour after it was issued.

Usage
-----
    python3 etsy_auth.py authorize
    python3 harleys_books_importer.py fetch
    python3 harleys_books_importer.py push --dry-run
    python3 harleys_books_importer.py push
    python3 harleys_books_importer.py status

Made-to-order stock is soft. STOCK_BUFFER holds units back from Woo so the
store runs out before Etsy does, rather than the two channels racing to
oversell the same made-to-order slot.
"""

import argparse
import hashlib
import html
import json
import os
import re
import sys
import time
from datetime import datetime, timezone

try:
    import requests
except ImportError:  # pragma: no cover - environment problem, not a code path
    sys.stderr.write(
        "This importer needs `requests`. Install it with:\n"
        "    python3 -m pip install requests\n"
    )
    raise SystemExit(2)

# The OAuth helper doubles as the token store. It is optional: without it the
# importer still runs from an explicit ETSY_OAUTH_TOKEN.
try:
    import etsy_auth
except ImportError:  # pragma: no cover
    etsy_auth = None


# --------------------------------------------------------------------------
# Configuration
# --------------------------------------------------------------------------

ETSY_API_BASE = "https://openapi.etsy.com/v3/application"
WOO_API_PATH = "/wp-json/wc/v3"

DEFAULT_CATALOG = "catalog.json"
DEFAULT_IMAGE_DIR = "images"

SKU_PREFIX = "etsy-"

# Etsy allows 10 requests/second. Stay well under it; a 52-listing shop is
# not in a hurry.
ETSY_MIN_INTERVAL = 0.15
WOO_MIN_INTERVAL = 0.05

MAX_RETRIES = 4
RETRY_BACKOFF = 2.0  # seconds, doubled each attempt

SHORT_DESCRIPTION_CHARS = 240

# Etsy reports weight/dimension units per listing; the Woo store has one
# configured unit. Convert rather than silently importing wrong numbers.
WEIGHT_TO_GRAMS = {"g": 1.0, "kg": 1000.0, "oz": 28.349523125, "lb": 453.59237}
LENGTH_TO_MM = {"mm": 1.0, "cm": 10.0, "m": 1000.0, "in": 25.4, "ft": 304.8}


class ImporterError(Exception):
    """Anything that should stop the run with a readable message."""


# --------------------------------------------------------------------------
# Small helpers
# --------------------------------------------------------------------------


def _now_iso():
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def _env(name, default=None, required=False):
    value = os.environ.get(name, default)
    if required and not value:
        raise ImporterError(
            "Missing required environment variable %s. Secrets are read from "
            "the environment only — see the module docstring." % name
        )
    return value


def _env_int(name, default):
    raw = os.environ.get(name)
    if raw is None or raw == "":
        return default
    try:
        return int(raw)
    except ValueError:
        raise ImporterError("%s must be an integer, got %r" % (name, raw))


def _log(message):
    sys.stdout.write("%s\n" % message)
    sys.stdout.flush()


def _warn(message):
    sys.stderr.write("warning: %s\n" % message)
    sys.stderr.flush()


def _money(value):
    """Etsy v3 returns money as {amount, divisor, currency_code}."""
    if not isinstance(value, dict):
        return None, None
    amount = value.get("amount")
    divisor = value.get("divisor") or 100
    if amount is None:
        return None, None
    try:
        return round(float(amount) / float(divisor), 2), value.get("currency_code")
    except (TypeError, ValueError, ZeroDivisionError):
        return None, None


def _text_to_html(text):
    """Etsy descriptions are plain text. Woo wants HTML. Keep it minimal."""
    if not text:
        return ""
    blocks = [b.strip() for b in re.split(r"\n\s*\n", text.strip())]
    out = []
    for block in blocks:
        if not block:
            continue
        out.append("<p>%s</p>" % html.escape(block).replace("\n", "<br />"))
    return "\n".join(out)


def _first_paragraph(text, limit=SHORT_DESCRIPTION_CHARS):
    if not text:
        return ""
    block = re.split(r"\n\s*\n", text.strip())[0].strip()
    block = " ".join(block.split())
    if len(block) <= limit:
        return block
    cut = block[:limit].rsplit(" ", 1)[0]
    return cut.rstrip(",.;:—-") + "…"


def _convert_weight(value, from_unit, to_unit):
    try:
        value = float(value)
    except (TypeError, ValueError):
        return None
    if value <= 0:
        return None
    from_unit = (from_unit or "").lower()
    to_unit = (to_unit or "").lower()
    if from_unit not in WEIGHT_TO_GRAMS or to_unit not in WEIGHT_TO_GRAMS:
        return None
    grams = value * WEIGHT_TO_GRAMS[from_unit]
    return round(grams / WEIGHT_TO_GRAMS[to_unit], 3)


def _convert_length(value, from_unit, to_unit):
    try:
        value = float(value)
    except (TypeError, ValueError):
        return None
    if value <= 0:
        return None
    from_unit = (from_unit or "").lower()
    to_unit = (to_unit or "").lower()
    if from_unit not in LENGTH_TO_MM or to_unit not in LENGTH_TO_MM:
        return None
    mm = value * LENGTH_TO_MM[from_unit]
    return round(mm / LENGTH_TO_MM[to_unit], 3)


def _image_signature(urls):
    joined = "|".join(urls)
    return hashlib.sha1(joined.encode("utf-8")).hexdigest()


def _safe_filename(name):
    return re.sub(r"[^A-Za-z0-9._-]", "_", name)


# --------------------------------------------------------------------------
# HTTP plumbing
# --------------------------------------------------------------------------


class _HttpClient:
    """Shared throttling + retry behaviour for both APIs."""

    min_interval = 0.0

    def __init__(self, timeout=45):
        self.session = requests.Session()
        self.timeout = timeout
        self._last_call = 0.0

    def _throttle(self):
        if not self.min_interval:
            return
        elapsed = time.monotonic() - self._last_call
        if elapsed < self.min_interval:
            time.sleep(self.min_interval - elapsed)
        self._last_call = time.monotonic()

    def request(self, method, url, **kwargs):
        kwargs.setdefault("timeout", self.timeout)
        attempt = 0
        while True:
            self._throttle()
            try:
                response = self.session.request(method, url, **kwargs)
            except requests.RequestException as exc:
                if attempt >= MAX_RETRIES:
                    raise ImporterError("%s %s failed: %s" % (method, url, exc))
                delay = RETRY_BACKOFF * (2 ** attempt)
                _warn("%s %s: %s — retrying in %.0fs" % (method, url, exc, delay))
                time.sleep(delay)
                attempt += 1
                continue

            if response.status_code == 429 or response.status_code >= 500:
                if attempt >= MAX_RETRIES:
                    raise ImporterError(
                        "%s %s failed after %d retries: HTTP %d %s"
                        % (method, url, MAX_RETRIES, response.status_code,
                           response.text[:400])
                    )
                retry_after = response.headers.get("Retry-After")
                try:
                    delay = float(retry_after) if retry_after else RETRY_BACKOFF * (2 ** attempt)
                except ValueError:
                    delay = RETRY_BACKOFF * (2 ** attempt)
                _warn(
                    "HTTP %d from %s — retrying in %.0fs"
                    % (response.status_code, url, delay)
                )
                time.sleep(delay)
                attempt += 1
                continue

            return response


# --------------------------------------------------------------------------
# Etsy authorization
# --------------------------------------------------------------------------


def _resolve_oauth_token(keystring, token_file=None):
    """Return (access_token, refresh_callback).

    An explicit ETSY_OAUTH_TOKEN wins and disables refreshing — the caller
    owns that token's lifetime. Otherwise the token store written by
    etsy_auth.py is used, and the callback can mint a fresh token mid-run.
    """
    explicit = os.environ.get("ETSY_OAUTH_TOKEN")
    if explicit:
        _log("Using ETSY_OAUTH_TOKEN from the environment (no auto-refresh).")
        return explicit, None

    if etsy_auth is None:
        raise ImporterError(
            "etsy_auth.py is not importable and ETSY_OAUTH_TOKEN is not set, "
            "so there is no way to authenticate to Etsy. Keep etsy_auth.py "
            "beside this file and run:\n    python3 etsy_auth.py authorize"
        )

    try:
        token = etsy_auth.resolve_access_token(keystring=keystring, path=token_file)
    except etsy_auth.AuthError as exc:
        raise ImporterError(str(exc))

    def _refresh():
        try:
            return etsy_auth.resolve_access_token(
                keystring=keystring, path=token_file, force_refresh=True
            )
        except etsy_auth.AuthError as exc:
            raise ImporterError(str(exc))

    return token, _refresh


class EtsyClient(_HttpClient):
    min_interval = ETSY_MIN_INTERVAL

    def __init__(self, keystring, oauth_token, refresh_callback=None):
        super().__init__()
        self.keystring = keystring
        self.oauth_token = oauth_token
        self.refresh_callback = refresh_callback

    def _headers(self):
        headers = {
            "x-api-key": self.keystring,
            "Accept": "application/json",
            "User-Agent": "harleys-books-importer/1.0",
        }
        if self.oauth_token:
            headers["Authorization"] = "Bearer %s" % self.oauth_token
        return headers

    def get(self, path, params=None, _retried=False):
        url = "%s%s" % (ETSY_API_BASE, path)
        response = self.request("GET", url, headers=self._headers(), params=params)

        if response.status_code == 401 and self.refresh_callback and not _retried:
            # A long fetch can outlive its one-hour token. Refresh once and
            # carry on rather than losing the whole run.
            _warn("Etsy access token rejected (401) — refreshing and retrying.")
            self.oauth_token = self.refresh_callback()
            return self.get(path, params=params, _retried=True)

        if response.status_code == 401:
            raise ImporterError(
                "Etsy returned 401 for %s. The token is expired or lacks the "
                "listings_r scope. Re-authorize:\n"
                "    python3 etsy_auth.py authorize" % path
            )
        if response.status_code == 403:
            raise ImporterError(
                "Etsy returned 403 for %s. The keystring may not be approved for "
                "the Open API v3, or the token does not own this shop." % path
            )
        if response.status_code == 404:
            return None
        if response.status_code >= 400:
            raise ImporterError(
                "Etsy GET %s failed: HTTP %d %s"
                % (path, response.status_code, response.text[:400])
            )
        try:
            return response.json()
        except ValueError:
            raise ImporterError("Etsy GET %s returned non-JSON body" % path)

    # -- discovery ---------------------------------------------------------

    def resolve_shop(self, shop_id=None, shop_name=None):
        """Return (shop_id, shop_name). Explicit id wins, then name, then token."""
        if shop_id:
            data = self.get("/shops/%s" % shop_id)
            if not data:
                raise ImporterError("Etsy shop id %s not found" % shop_id)
            return int(data["shop_id"]), data.get("shop_name")

        if shop_name:
            data = self.get("/shops", params={"shop_name": shop_name, "limit": 25})
            results = (data or {}).get("results") or []
            for shop in results:
                if (shop.get("shop_name") or "").lower() == shop_name.lower():
                    return int(shop["shop_id"]), shop.get("shop_name")
            if results:
                shop = results[0]
                _warn(
                    "No exact match for shop name %r; using closest result %r"
                    % (shop_name, shop.get("shop_name"))
                )
                return int(shop["shop_id"]), shop.get("shop_name")
            raise ImporterError("No Etsy shop found for name %r" % shop_name)

        me = self.get("/users/me")
        if me and me.get("shop_id"):
            shop = self.get("/shops/%s" % me["shop_id"])
            return int(me["shop_id"]), (shop or {}).get("shop_name")

        raise ImporterError(
            "Could not determine the Etsy shop. The token may belong to an "
            "account with no shop — check that Harley, not you, approved the "
            "consent screen. Otherwise set ETSY_SHOP_ID or ETSY_SHOP_NAME."
        )

    def fetch_sections(self, shop_id):
        """shop_section_id -> section title. Becomes the Woo category."""
        data = self.get("/shops/%s/sections" % shop_id)
        sections = {}
        for section in (data or {}).get("results") or []:
            sid = section.get("shop_section_id")
            title = (section.get("title") or "").strip()
            if sid is not None and title:
                sections[int(sid)] = title
        return sections

    def fetch_active_listings(self, shop_id, limit=None):
        listings = []
        offset = 0
        page_size = 100
        while True:
            params = {
                "state": "active",
                "limit": page_size,
                "offset": offset,
                "includes": "Images",
            }
            data = self.get("/shops/%s/listings" % shop_id, params=params)
            if data is None:
                break
            results = data.get("results") or []
            listings.extend(results)
            total = data.get("count")
            _log("  fetched %d listing(s)%s"
                 % (len(listings), " of %s" % total if total else ""))
            if limit and len(listings) >= limit:
                listings = listings[:limit]
                break
            if len(results) < page_size:
                break
            offset += page_size
        return listings

    def fetch_images(self, listing_id):
        data = self.get("/listings/%s/images" % listing_id)
        return (data or {}).get("results") or []

    def fetch_inventory(self, listing_id):
        """None when the listing has no inventory record (plain simple listing)."""
        data = self.get("/listings/%s/inventory" % listing_id)
        if not data:
            return None
        return data


# --------------------------------------------------------------------------
# NORMALIZATION #1 — Etsy listing -> neutral catalog item
#
# This is one of the two places where Etsy field names are allowed to appear.
# --------------------------------------------------------------------------


def _normalize_listing(listing, images, inventory, sections):
    """Map a raw Etsy v3 listing into the neutral catalog schema.

    `images`    list of Etsy listing-image payloads
    `inventory` Etsy inventory payload, or None
    `sections`  {shop_section_id: title}
    """
    listing_id = int(listing["listing_id"])

    price, currency = _money(listing.get("price"))

    # Fall back to the cheapest enabled offering when the listing-level price
    # is absent (happens on some price-on-property listings).
    offerings = []
    property_names = []
    if inventory:
        for product in inventory.get("products") or []:
            for value in product.get("property_values") or []:
                name = value.get("property_name")
                if name and name not in property_names:
                    property_names.append(name)
            for offering in product.get("offerings") or []:
                offer_price, offer_currency = _money(offering.get("price"))
                if offer_price is None:
                    continue
                offerings.append(
                    {
                        "price": offer_price,
                        "currency": offer_currency,
                        "quantity": offering.get("quantity") or 0,
                        "enabled": bool(offering.get("is_enabled", True)),
                    }
                )
    if price is None and offerings:
        enabled = [o for o in offerings if o["enabled"]] or offerings
        cheapest = min(enabled, key=lambda o: o["price"])
        price = cheapest["price"]
        currency = currency or cheapest["currency"]

    # Variation detection. The catalog is deliberately mirrored 1:1, so a
    # listing with real variations still becomes a simple product — it is
    # flagged REVIEW so consolidation can happen later, deliberately.
    distinct_offerings = len(
        [p for p in (inventory or {}).get("products") or [] if p.get("offerings")]
    )
    has_real_variations = bool(property_names) and distinct_offerings > 1
    price_on_property = list((inventory or {}).get("price_on_property") or [])
    quantity_on_property = list((inventory or {}).get("quantity_on_property") or [])

    review_reasons = []
    if has_real_variations:
        review_reasons.append(
            "listing has %d offerings across %s"
            % (distinct_offerings, ", ".join(property_names))
        )
    if price_on_property:
        review_reasons.append("price varies by property")
    if quantity_on_property:
        review_reasons.append("quantity varies by property")
    if price is None:
        review_reasons.append("no price could be resolved")

    # Images. rank 1 is the Etsy primary image and becomes the Woo thumbnail.
    normalized_images = []
    for image in sorted(images or [], key=lambda i: i.get("rank") or 0):
        url = (
            image.get("url_fullxfull")
            or image.get("url_570xN")
            or image.get("url_170x135")
        )
        if not url:
            continue
        normalized_images.append(
            {
                "etsy_image_id": image.get("listing_image_id"),
                "rank": image.get("rank") or (len(normalized_images) + 1),
                "url": url,
                "alt": (image.get("alt_text") or "").strip() or None,
                "local_path": None,  # filled in by the download step
            }
        )
    if not normalized_images:
        review_reasons.append("listing has no images")

    section_id = listing.get("shop_section_id")
    section_title = sections.get(int(section_id)) if section_id else None

    weight = None
    raw_weight = listing.get("item_weight")
    if raw_weight:
        weight = {"value": raw_weight, "unit": listing.get("item_weight_unit") or ""}

    dimensions = None
    if any(
        listing.get(k) for k in ("item_length", "item_width", "item_height")
    ):
        dimensions = {
            "length": listing.get("item_length"),
            "width": listing.get("item_width"),
            "height": listing.get("item_height"),
            "unit": listing.get("item_dimensions_unit") or "",
        }

    tags = [t.strip() for t in (listing.get("tags") or []) if t and t.strip()]
    materials = [m.strip() for m in (listing.get("materials") or []) if m and m.strip()]

    return {
        "etsy_listing_id": listing_id,
        "sku": "%s%d" % (SKU_PREFIX, listing_id),
        "title": (listing.get("title") or "").strip(),
        "description": listing.get("description") or "",
        "price": ("%.2f" % price) if price is not None else None,
        "currency": currency or "USD",
        "quantity": int(listing.get("quantity") or 0),
        "state": listing.get("state"),
        "url": listing.get("url"),
        "tags": tags,
        "materials": materials,
        "section": section_title,
        "weight": weight,
        "dimensions": dimensions,
        "images": normalized_images,
        "variations": {
            "has_variations": bool(listing.get("has_variations")) or has_real_variations,
            "offering_count": distinct_offerings,
            "property_names": property_names,
            "price_on_property": price_on_property,
            "quantity_on_property": quantity_on_property,
        },
        "who_made": listing.get("who_made"),
        "when_made": listing.get("when_made"),
        "is_customizable": bool(listing.get("is_customizable")),
        "is_personalizable": bool(listing.get("is_personalizable")),
        "processing_min": listing.get("processing_min"),
        "processing_max": listing.get("processing_max"),
        "created_timestamp": listing.get("created_timestamp")
        or listing.get("original_creation_timestamp"),
        "review": bool(review_reasons),
        "review_reasons": review_reasons,
        "fetched_at": _now_iso(),
    }


# --------------------------------------------------------------------------
# Phase 1 driver
# --------------------------------------------------------------------------


def _download_images(item, images_dir, refresh=False):
    """Back the Etsy CDN images up locally. Archival: push uses the CDN URLs."""
    if not item["images"]:
        return 0
    target_dir = os.path.join(images_dir, item["sku"])
    os.makedirs(target_dir, exist_ok=True)
    downloaded = 0
    for image in item["images"]:
        url = image["url"]
        extension = os.path.splitext(url.split("?")[0])[1] or ".jpg"
        filename = _safe_filename(
            "%02d-%s%s" % (image["rank"], image["etsy_image_id"], extension)
        )
        path = os.path.join(target_dir, filename)
        image["local_path"] = path
        if os.path.exists(path) and not refresh:
            continue
        try:
            response = requests.get(url, timeout=60)
        except requests.RequestException as exc:
            _warn("image download failed for %s: %s" % (url, exc))
            continue
        if response.status_code != 200:
            _warn("image download failed for %s: HTTP %d" % (url, response.status_code))
            continue
        with open(path, "wb") as handle:
            handle.write(response.content)
        downloaded += 1
    return downloaded


def cmd_fetch(args):
    keystring = _env("ETSY_KEYSTRING", required=True)
    oauth_token, refresh_callback = _resolve_oauth_token(keystring, args.token_file)
    shop_id = args.shop_id or _env("ETSY_SHOP_ID")
    shop_name = args.shop_name or _env("ETSY_SHOP_NAME")

    client = EtsyClient(keystring, oauth_token, refresh_callback)

    _log("Resolving shop…")
    shop_id, shop_name = client.resolve_shop(shop_id, shop_name)
    _log("  shop %s (%s)" % (shop_id, shop_name or "unnamed"))

    _log("Fetching shop sections…")
    sections = client.fetch_sections(shop_id)
    _log("  %d section(s)" % len(sections))

    _log("Fetching active listings…")
    listings = client.fetch_active_listings(shop_id, limit=args.limit)
    _log("  %d active listing(s)" % len(listings))

    items = []
    for index, listing in enumerate(listings, start=1):
        listing_id = listing.get("listing_id")
        title = (listing.get("title") or "")[:60]
        _log("[%d/%d] %s — %s" % (index, len(listings), listing_id, title))

        images = listing.get("images")
        if not images:
            images = client.fetch_images(listing_id)

        inventory = None
        if not args.skip_inventory:
            try:
                inventory = client.fetch_inventory(listing_id)
            except ImporterError as exc:
                _warn("inventory unavailable for %s: %s" % (listing_id, exc))

        item = _normalize_listing(listing, images, inventory, sections)

        if not args.no_images:
            count = _download_images(item, args.images_dir, refresh=args.refresh_images)
            if count:
                _log("      backed up %d new image(s)" % count)

        if item["review"]:
            _log("      REVIEW: %s" % "; ".join(item["review_reasons"]))

        items.append(item)

    catalog = {
        "generated_at": _now_iso(),
        "source": {"shop_id": shop_id, "shop_name": shop_name},
        "count": len(items),
        "items": items,
    }

    with open(args.catalog, "w", encoding="utf-8") as handle:
        json.dump(catalog, handle, indent=2, ensure_ascii=False)
        handle.write("\n")

    flagged = [i for i in items if i["review"]]
    _log("")
    _log("Wrote %s — %d item(s), %d flagged REVIEW."
         % (args.catalog, len(items), len(flagged)))
    for item in flagged:
        _log("  REVIEW %s  %s" % (item["sku"], item["title"][:60]))
    return 0


# --------------------------------------------------------------------------
# Phase 2 — WooCommerce
# --------------------------------------------------------------------------


class WooClient(_HttpClient):
    min_interval = WOO_MIN_INTERVAL

    def __init__(self, store_url, consumer_key, consumer_secret):
        super().__init__()
        self.base = store_url.rstrip("/") + WOO_API_PATH
        self.consumer_key = consumer_key
        self.consumer_secret = consumer_secret
        # Some hosts strip the Authorization header; fall back to query auth
        # once, then latch the working mode.
        self._use_query_auth = False
        self._term_cache = {}

    def _call(self, method, path, params=None, body=None):
        url = "%s%s" % (self.base, path)
        params = dict(params or {})
        kwargs = {"params": params}
        if self._use_query_auth:
            params["consumer_key"] = self.consumer_key
            params["consumer_secret"] = self.consumer_secret
        else:
            kwargs["auth"] = (self.consumer_key, self.consumer_secret)
        if body is not None:
            kwargs["json"] = body
        kwargs["headers"] = {"Accept": "application/json"}
        return self.request(method, url, **kwargs)

    def call(self, method, path, params=None, body=None):
        response = self._call(method, path, params=params, body=body)

        if response.status_code == 401 and not self._use_query_auth:
            _warn(
                "WooCommerce rejected basic auth (401) — retrying with query-string "
                "credentials. Some hosts strip the Authorization header."
            )
            self._use_query_auth = True
            response = self._call(method, path, params=params, body=body)

        if response.status_code == 404 and method == "GET":
            return None

        if response.status_code >= 400:
            payload = None
            try:
                payload = response.json()
            except ValueError:
                pass
            if isinstance(payload, dict) and payload.get("code"):
                raise WooApiError(response.status_code, payload)
            raise ImporterError(
                "WooCommerce %s %s failed: HTTP %d %s"
                % (method, path, response.status_code, response.text[:400])
            )

        if response.status_code == 204 or not response.content:
            return None
        try:
            return response.json()
        except ValueError:
            raise ImporterError("WooCommerce %s %s returned non-JSON body" % (method, path))

    # -- products ----------------------------------------------------------

    def find_product_by_sku(self, sku):
        results = self.call("GET", "/products", params={"sku": sku})
        if not results:
            return None
        for product in results:
            if product.get("sku") == sku:
                return product
        return None

    def create_product(self, body):
        return self.call("POST", "/products", body=body)

    def update_product(self, product_id, body):
        return self.call("PUT", "/products/%d" % product_id, body=body)

    def iter_products(self, per_page=100):
        page = 1
        while True:
            results = self.call(
                "GET", "/products", params={"per_page": per_page, "page": page}
            )
            if not results:
                return
            for product in results:
                yield product
            if len(results) < per_page:
                return
            page += 1

    # -- taxonomy ----------------------------------------------------------

    def resolve_term(self, taxonomy, name):
        """Return the term id for a tag/category name, creating it if needed.

        `taxonomy` is "tags" or "categories".
        """
        key = (taxonomy, name.strip().lower())
        if key in self._term_cache:
            return self._term_cache[key]

        path = "/products/%s" % taxonomy
        results = self.call("GET", path, params={"search": name, "per_page": 100}) or []
        for term in results:
            if (term.get("name") or "").strip().lower() == key[1]:
                self._term_cache[key] = term["id"]
                return term["id"]

        try:
            created = self.call("POST", path, body={"name": name})
        except WooApiError as exc:
            # Race or near-match on slug: Woo hands back the existing id.
            existing = exc.resource_id()
            if existing is None:
                raise
            self._term_cache[key] = existing
            return existing

        self._term_cache[key] = created["id"]
        return created["id"]


class WooApiError(ImporterError):
    def __init__(self, status, payload):
        self.status = status
        self.payload = payload or {}
        super().__init__(
            "WooCommerce error %s (HTTP %d): %s"
            % (self.payload.get("code"), status, self.payload.get("message"))
        )

    def resource_id(self):
        data = self.payload.get("data") or {}
        value = data.get("resource_id") or data.get("unique_sku")
        try:
            return int(value)
        except (TypeError, ValueError):
            return None


# --------------------------------------------------------------------------
# NORMALIZATION #2 — neutral catalog item -> WooCommerce product body
#
# This is the other of the two places where field mapping is allowed to live.
# --------------------------------------------------------------------------


def _normalize_product(item, options):
    """Map a neutral catalog item into a WooCommerce product payload.

    `options` carries store-level settings: stock buffer, unit targets and
    the status to use on creation. Term ids are resolved by the caller, which
    is the only part that needs to talk to the store.
    """
    stock_buffer = options["stock_buffer"]
    quantity = max(0, int(item.get("quantity") or 0) - stock_buffer)

    body = {
        "name": item["title"],
        "type": "simple",
        "sku": item["sku"],
        "description": _text_to_html(item.get("description")),
        "short_description": _text_to_html(_first_paragraph(item.get("description"))),
        "manage_stock": True,
        "stock_quantity": quantity,
        "backorders": "no",
        "catalog_visibility": "visible",
        "virtual": False,
        "downloadable": False,
    }

    if item.get("price"):
        body["regular_price"] = item["price"]

    # Weight / dimensions, converted into the store's configured units. Values
    # that cannot be converted are dropped rather than imported wrong.
    weight = item.get("weight")
    if weight and weight.get("value"):
        converted = _convert_weight(
            weight["value"], weight.get("unit"), options["weight_unit"]
        )
        if converted:
            body["weight"] = str(converted)

    dimensions = item.get("dimensions")
    if dimensions:
        converted = {}
        for key in ("length", "width", "height"):
            value = _convert_length(
                dimensions.get(key), dimensions.get("unit"), options["dimension_unit"]
            )
            converted[key] = str(value) if value else ""
        if any(converted.values()):
            body["dimensions"] = converted

    images = []
    for image in item.get("images") or []:
        entry = {"src": image["url"]}
        if image.get("alt"):
            entry["alt"] = image["alt"]
        images.append(entry)
    if images:
        body["images"] = images

    meta = [
        {"key": "etsy_listing_id", "value": str(item["etsy_listing_id"])},
        {"key": "etsy_url", "value": item.get("url") or ""},
        {"key": "etsy_synced_at", "value": _now_iso()},
        {
            "key": "etsy_image_signature",
            "value": _image_signature([i["url"] for i in item.get("images") or []]),
        },
    ]
    if item.get("review"):
        meta.append(
            {"key": "etsy_review", "value": "; ".join(item.get("review_reasons") or [])}
        )
    if item.get("materials"):
        meta.append({"key": "etsy_materials", "value": ", ".join(item["materials"])})
    body["meta_data"] = meta

    return body


# --------------------------------------------------------------------------
# Phase 2 driver
# --------------------------------------------------------------------------


def _load_catalog(path):
    if not os.path.exists(path):
        raise ImporterError(
            "Catalog %s not found. Run `fetch` first." % path
        )
    with open(path, encoding="utf-8") as handle:
        data = json.load(handle)
    if isinstance(data, list):
        return {"items": data, "count": len(data), "generated_at": None, "source": {}}
    if not isinstance(data, dict) or "items" not in data:
        raise ImporterError("Catalog %s is not in a recognised format." % path)
    return data


def _existing_meta(product, key):
    for entry in product.get("meta_data") or []:
        if entry.get("key") == key:
            return entry.get("value")
    return None


def _attach_terms(client, body, item, options):
    """Resolve tag/category names to ids. Kept out of _normalize_product so
    that normalization stays pure and testable."""
    if options["tags"] and item.get("tags"):
        ids = []
        for name in item["tags"]:
            try:
                ids.append({"id": client.resolve_term("tags", name)})
            except ImporterError as exc:
                _warn("could not resolve tag %r: %s" % (name, exc))
        if ids:
            body["tags"] = ids

    if options["categories"] and item.get("section"):
        try:
            body["categories"] = [
                {"id": client.resolve_term("categories", item["section"])}
            ]
        except ImporterError as exc:
            _warn("could not resolve category %r: %s" % (item["section"], exc))


def cmd_push(args):
    store_url = args.store_url or _env("WOO_STORE_URL", required=True)
    consumer_key = _env("WOO_CONSUMER_KEY", required=True)
    consumer_secret = _env("WOO_CONSUMER_SECRET", required=True)

    options = {
        "stock_buffer": args.stock_buffer
        if args.stock_buffer is not None
        else _env_int("STOCK_BUFFER", 0),
        "weight_unit": _env("WOO_WEIGHT_UNIT", "oz"),
        "dimension_unit": _env("WOO_DIMENSION_UNIT", "in"),
        "status": args.status,
        "tags": not args.no_tags,
        "categories": not args.no_categories,
    }

    catalog = _load_catalog(args.catalog)
    items = catalog["items"]
    if args.limit:
        items = items[: args.limit]
    if args.skip_review:
        skipped = [i for i in items if i.get("review")]
        items = [i for i in items if not i.get("review")]
        for item in skipped:
            _log("SKIP (review) %s  %s" % (item["sku"], item["title"][:60]))

    _log(
        "Pushing %d item(s) to %s%s"
        % (len(items), store_url, " [dry run]" if args.dry_run else "")
    )
    _log("  stock buffer: %d unit(s)" % options["stock_buffer"])
    _log("")

    client = WooClient(store_url, consumer_key, consumer_secret)

    created = updated = unchanged_images = failed = skipped_type = 0
    seen_ids = set()

    for index, item in enumerate(items, start=1):
        sku = item["sku"]
        label = item["title"][:60]
        prefix = "[%d/%d] %s" % (index, len(items), sku)

        if not item.get("price"):
            _warn("%s has no price — skipping. %s" % (sku, label))
            failed += 1
            continue

        try:
            existing = client.find_product_by_sku(sku)
        except ImporterError as exc:
            _warn("%s lookup failed: %s" % (sku, exc))
            failed += 1
            continue

        # Everything here is pushed as a simple product. Sending that at an
        # existing variable product converts it and orphans its variations,
        # taking the variation pricing with them. Refuse by default — an
        # adopted legacy product is exactly where this bites.
        existing_type = (existing or {}).get("type")
        if existing and existing_type not in (None, "", "simple") and not args.allow_type_change:
            _warn(
                "%s is a '%s' product in the store (#%s) — pushing would convert "
                "it to simple and orphan its variations. Skipping. Override with "
                "--allow-type-change once you have decided what happens to them."
                % (sku, existing_type, existing["id"])
            )
            skipped_type += 1
            continue

        body = _normalize_product(item, options)

        if args.dry_run:
            action = "update #%d" % existing["id"] if existing else "create"
            _log("%s  would %s — %s" % (prefix, action, label))
            if existing:
                seen_ids.add(existing["id"])
            continue

        try:
            _attach_terms(client, body, item, options)
        except ImporterError as exc:
            _warn("%s taxonomy failed: %s" % (sku, exc))

        try:
            if existing:
                # Re-sending `images` makes Woo re-sideload every file. Only
                # send them when the Etsy image set actually changed.
                signature = _existing_meta(existing, "etsy_image_signature")
                new_signature = _image_signature(
                    [i["url"] for i in item.get("images") or []]
                )
                if (
                    signature == new_signature
                    and existing.get("images")
                    and not args.force_images
                ):
                    body.pop("images", None)
                    unchanged_images += 1

                # Status is Harley's to control once a product exists. Only
                # force it when explicitly asked.
                if not args.force_status:
                    body.pop("status", None)
                else:
                    body["status"] = options["status"]

                result = client.update_product(existing["id"], body)
                seen_ids.add(existing["id"])
                updated += 1
                _log("%s  updated #%s — %s" % (prefix, result["id"], label))
            else:
                body["status"] = options["status"]
                result = client.create_product(body)
                seen_ids.add(result["id"])
                created += 1
                _log("%s  created #%s — %s" % (prefix, result["id"], label))
        except ImporterError as exc:
            _warn("%s push failed: %s" % (sku, exc))
            failed += 1

    _log("")
    _log(
        "Done. created=%d updated=%d failed=%d (images unchanged on %d update(s))"
        % (created, updated, failed, unchanged_images)
    )
    if skipped_type:
        _log(
            "  %d item(s) skipped: the store product is not simple. Nothing was "
            "changed for those." % skipped_type
        )

    if args.deactivate_missing and not args.dry_run:
        _deactivate_missing(client, catalog["items"])

    return 1 if failed else 0


def _deactivate_missing(client, items):
    """Draft any etsy-* product whose listing is no longer active on Etsy."""
    known = {item["sku"] for item in items}
    _log("")
    _log("Checking for products whose Etsy listing is gone…")
    stale = []
    for product in client.iter_products():
        sku = product.get("sku") or ""
        if not sku.startswith(SKU_PREFIX):
            continue
        if sku in known:
            continue
        if product.get("status") == "draft":
            continue
        stale.append(product)

    if not stale:
        _log("  none found.")
        return

    for product in stale:
        try:
            client.update_product(product["id"], {"status": "draft"})
            _log("  drafted #%s %s — %s"
                 % (product["id"], product["sku"], (product.get("name") or "")[:50]))
        except ImporterError as exc:
            _warn("could not draft #%s: %s" % (product["id"], exc))


# --------------------------------------------------------------------------
# status — read-only reconciliation between catalog.json and the store
# --------------------------------------------------------------------------


def cmd_status(args):
    catalog = _load_catalog(args.catalog)
    items = catalog["items"]

    _log("Catalog: %s" % args.catalog)
    _log("  generated: %s" % (catalog.get("generated_at") or "unknown"))
    _log("  items:     %d" % len(items))
    flagged = [i for i in items if i.get("review")]
    _log("  flagged:   %d" % len(flagged))
    for item in flagged:
        _log("    REVIEW %s  %s — %s"
             % (item["sku"], item["title"][:45], "; ".join(item["review_reasons"])))

    store_url = args.store_url or os.environ.get("WOO_STORE_URL")
    consumer_key = os.environ.get("WOO_CONSUMER_KEY")
    consumer_secret = os.environ.get("WOO_CONSUMER_SECRET")
    if not (store_url and consumer_key and consumer_secret):
        _log("")
        _log("Store credentials not set — skipping the live comparison.")
        return 0

    client = WooClient(store_url, consumer_key, consumer_secret)
    _log("")
    _log("Store: %s" % store_url)

    store_skus = {}
    for product in client.iter_products():
        sku = product.get("sku") or ""
        if sku.startswith(SKU_PREFIX):
            store_skus[sku] = product
    _log("  imported products: %d" % len(store_skus))

    catalog_skus = {item["sku"] for item in items}
    missing = sorted(catalog_skus - set(store_skus))
    orphaned = sorted(set(store_skus) - catalog_skus)
    duplicates = [
        sku for sku, product in store_skus.items() if product.get("sku") != sku
    ]

    _log("  in catalog, not in store: %d" % len(missing))
    for sku in missing:
        _log("    %s" % sku)
    _log("  in store, not in catalog: %d" % len(orphaned))
    for sku in orphaned:
        _log("    %s (%s)" % (sku, store_skus[sku].get("status")))
    if duplicates:
        _log("  SKU mismatches: %s" % ", ".join(duplicates))

    return 0


# --------------------------------------------------------------------------
# CLI
# --------------------------------------------------------------------------


def build_parser():
    parser = argparse.ArgumentParser(
        prog="harleys_books_importer.py",
        description="Two-phase Etsy -> WooCommerce catalog importer for HarleysBooks.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=(
            "Credentials come from the environment. Authorize Etsy once with\n"
            "`python3 etsy_auth.py authorize`; tokens refresh automatically."
        ),
    )
    sub = parser.add_subparsers(dest="command")

    common = argparse.ArgumentParser(add_help=False)
    common.add_argument(
        "--catalog", default=DEFAULT_CATALOG, help="catalog file (default: %(default)s)"
    )

    fetch = sub.add_parser(
        "fetch", parents=[common], help="Etsy -> catalog.json + image backups"
    )
    fetch.add_argument("--shop-id", help="override ETSY_SHOP_ID")
    fetch.add_argument("--shop-name", help="override ETSY_SHOP_NAME")
    fetch.add_argument(
        "--token-file", help="Etsy token store (default: $ETSY_TOKEN_FILE or "
        ".etsy_tokens.json)"
    )
    fetch.add_argument(
        "--images-dir",
        default=DEFAULT_IMAGE_DIR,
        help="local image backup directory (default: %(default)s)",
    )
    fetch.add_argument(
        "--no-images", action="store_true", help="skip local image backups"
    )
    fetch.add_argument(
        "--refresh-images",
        action="store_true",
        help="re-download images that already exist locally",
    )
    fetch.add_argument(
        "--skip-inventory",
        action="store_true",
        help="skip the per-listing inventory call (faster, weaker REVIEW detection)",
    )
    fetch.add_argument("--limit", type=int, help="stop after N listings (testing)")
    fetch.set_defaults(func=cmd_fetch)

    push = sub.add_parser(
        "push", parents=[common], help="catalog.json -> WooCommerce products"
    )
    push.add_argument("--store-url", help="override WOO_STORE_URL")
    push.add_argument(
        "--status",
        default="publish",
        choices=["publish", "draft", "pending", "private"],
        help="status for newly created products (default: %(default)s)",
    )
    push.add_argument(
        "--force-status",
        action="store_true",
        help="also apply --status to existing products (overwrites manual changes)",
    )
    push.add_argument(
        "--stock-buffer",
        type=int,
        help="units held back from Woo stock (default: $STOCK_BUFFER or 0)",
    )
    push.add_argument(
        "--force-images",
        action="store_true",
        help="re-send images even when the Etsy image set is unchanged",
    )
    push.add_argument("--no-tags", action="store_true", help="do not sync tags")
    push.add_argument(
        "--no-categories", action="store_true", help="do not sync shop sections"
    )
    push.add_argument(
        "--skip-review", action="store_true", help="skip items flagged REVIEW"
    )
    push.add_argument(
        "--allow-type-change",
        action="store_true",
        help="permit converting an existing variable product to simple "
        "(orphans its variations)",
    )
    push.add_argument(
        "--deactivate-missing",
        action="store_true",
        help="draft store products whose Etsy listing is no longer active",
    )
    push.add_argument("--limit", type=int, help="stop after N items (testing)")
    push.add_argument(
        "--dry-run", action="store_true", help="report actions without writing"
    )
    push.set_defaults(func=cmd_push)

    status = sub.add_parser(
        "status", parents=[common], help="compare catalog.json against the store"
    )
    status.add_argument("--store-url", help="override WOO_STORE_URL")
    status.set_defaults(func=cmd_status)

    return parser


def main(argv=None):
    parser = build_parser()
    args = parser.parse_args(argv)
    if not getattr(args, "func", None):
        parser.print_help()
        return 2
    try:
        return args.func(args)
    except ImporterError as exc:
        sys.stderr.write("error: %s\n" % exc)
        return 1
    except KeyboardInterrupt:
        sys.stderr.write("\ninterrupted\n")
        return 130


if __name__ == "__main__":
    raise SystemExit(main())
