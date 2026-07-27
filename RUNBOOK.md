# Etsy → WooCommerce sync — runbook

Etsy stays the source of truth. The store mirrors it. Everything here is
re-runnable and nothing deletes anything.

| file | what it is |
| --- | --- |
| `etsy_auth.py` | one-time Etsy consent, and the token store it refreshes against |
| `harleys_books_importer.py` | the two phases — `fetch` (Etsy → catalog) and `push` (catalog → store) |
| `etsy_sync.py` | the thing you actually run: fetch + diff + push + report |

---

## One-time setup

**1. Etsy app.** Register at <https://www.etsy.com/developers/register>. Etsy
reviews the request, so start it early. You need the **keystring**; the shared
secret is never used (PKCE authenticates with a code verifier instead).

Register `http://localhost:3003/oauth/redirect` as the callback. It must match
byte for byte later — a trailing slash difference is a rejected request.

**2. WooCommerce key.** WooCommerce → Settings → Advanced → REST API → Add key,
permissions **Read/Write**.

**3. Environment.** Never commit these.

```bash
export ETSY_KEYSTRING='...'
export WOO_STORE_URL='https://bedazzlekits.com'
export WOO_CONSUMER_KEY='ck_...'
export WOO_CONSUMER_SECRET='cs_...'
export STOCK_BUFFER='0'        # units held back from the store
```

**4. Consent, once.**

```bash
python3 etsy_auth.py authorize
```

Harley opens the printed URL **in her own browser, logged into her Etsy
account**. Whoever is logged in is who the token belongs to — authorizing as
the wrong account succeeds and then fails later with "could not determine
shop". Confirm the Etsy user id it prints is hers.

Tokens land in `.etsy_tokens.json` (mode 0600, gitignored). Access tokens last
an hour and refresh automatically. The refresh token lasts 90 days; if nothing
syncs for 90 days, Harley re-consents.

If her browser is on a different machine, use `authorize --manual` and paste
the redirect URL back.

---

## First import

Go one step at a time. Do not skip the dry run.

```bash
python3 etsy_sync.py run --dry-run     # writes nothing to the store
```

Read the output. Every listing should say **would create**. Anything saying
*would update* means a SKU collision you did not expect — stop and find out
why before continuing.

```bash
python3 etsy_sync.py run               # new products land as DRAFT
```

Review the drafts in wp-admin — prices, images, stock — then bulk-publish.
Run it once more; it should report `created=0` and update in place. That is
the idempotency check, on real data.

---

## Ongoing sync

```bash
python3 etsy_sync.py run
```

On cron, hourly, with a log:

```cron
0 * * * * cd /path/to/bdzl && /usr/bin/python3 etsy_sync.py run >> sync.log 2>&1
```

Cron needs the environment variables — put the exports in the crontab, a
wrapper script, or an EnvironmentFile. Cron does not read your shell profile.

Two runs can never overlap: the second exits **2** and does nothing.

### Defaults, and why

| behaviour | reason |
| --- | --- |
| new products created as **draft** | a listing appearing on Etsy should not publish itself into the store unreviewed |
| withdrawn listings **drafted, never deleted** | pulling from sale is reversible; deleting is not |
| variable products **skipped** | pushing a simple product at one would orphan its variations |
| images re-sent **only when changed** | Woo re-sideloads the whole gallery otherwise |
| product **status never overwritten** on update | Harley unpublishing something must stick |

To publish new products automatically instead: `--new-status publish`.

### Exit codes

| code | meaning |
| --- | --- |
| 0 | clean |
| 1 | ran, but something failed — read the report |
| 2 | could not start: lock held, missing credentials, bad config |

---

## Reading a run

```bash
python3 etsy_sync.py report      # re-read the last run
```

The run prints what changed since last time — `NEW`, `GONE`, `CHANGED` with
old → new values for price, stock, title, section and image count. Full detail
lands in `sync_report.json`.

---

## When something needs a human

**`REVIEW` on a listing.** The listing has real variations, or price/quantity
varies by property, or it has no images. It is still imported as a simple
product at the cheapest enabled offering price. Decide whether it should
become a proper variable product in the store, then handle it by hand.

**`skipped: not a simple product`.** The store product with that SKU is
variable. Pushing would flatten it and orphan its variations. Decide what
happens to those variations, then re-run with `--allow-type-change`.

**`could not determine the Etsy shop`.** Usually the token belongs to the wrong
Etsy account. Check with `python3 etsy_auth.py show` and re-authorize as
Harley.

**401 from Etsy that does not resolve itself.** The refresh token is past 90
days. `python3 etsy_auth.py authorize` again.

**403 from Etsy.** The app is not approved for the Open API v3 yet, or the
keystring is wrong.

**`another sync is already running`.** A previous run is still going, or was
killed. A lock from a dead process is cleared automatically on the next run.

---

## Recovery

**A run made a mess of the store.** Nothing was deleted. Products are drafted,
not removed; the catalog is a plain JSON file you can inspect and re-push.

**Etsy is down or the catalog looks wrong.** `catalog.json` is only rewritten
after a *successful* fetch, so a failed fetch leaves the last good catalog in
place. Push from it with `--push-only`.

**You want to re-import one listing.** Edit nothing — just re-run. The SKU
(`etsy-<listing_id>`) makes every push an update.

---

## Constraints this build holds to

Plain Python, `requests` as the only dependency. No frameworks, no build step.
Secrets live in the environment and are never committed. All Etsy field mapping
lives in the two `_normalize_*` helpers in the importer — if Etsy's v3 fields
drift, fix them there and nowhere else.
