#!/usr/bin/env python3
"""
etsy_auth.py — one-shot Etsy OAuth 2.0 (PKCE) helper for the HarleysBooks
importer, plus the token store the importer refreshes against.

Etsy access tokens live one hour. Refresh tokens live ninety days. So
"Harley authorizes once" is only true if the refresh token is persisted and
used automatically — which is what this module is for. She consents once in
a browser; after that `harleys_books_importer.py fetch` refreshes silently
for the next ninety days.

    python3 etsy_auth.py authorize     consent once, store the tokens
    python3 etsy_auth.py show          who authorized, and how long is left
    python3 etsy_auth.py refresh       force a refresh now

Run it as a library and it is the token store:

    import etsy_auth
    token = etsy_auth.resolve_access_token(keystring)

Flow, in the order it happens
-----------------------------
1. Generate a PKCE verifier and its S256 challenge. Etsy rejects `plain`.
2. Open https://www.etsy.com/oauth/connect with the challenge.
3. Harley approves in *her* browser. Etsy redirects to the loopback
   listener this script runs, carrying a short-lived code.
4. POST the code plus the original verifier to the token endpoint.
5. Store access + refresh tokens, 0600, next to the importer.

The app's *shared secret* is not used by this OAuth flow — PKCE authenticates
with the verifier instead. It IS required elsewhere, though: Etsy wants
``keystring:shared_secret`` in the ``x-api-key`` header on API calls, so the
importer needs ETSY_SHARED_SECRET even though this handshake does not.

Environment
-----------
    ETSY_KEYSTRING     the app keystring (required)
    ETSY_TOKEN_FILE    token store path (default: .etsy_tokens.json)

The token file holds live credentials. It is gitignored — keep it that way.
"""

import argparse
import base64
import hashlib
import json
import os
import secrets
import sys
import time
import webbrowser
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlencode, urlparse

try:
    import requests
except ImportError:  # pragma: no cover
    sys.stderr.write(
        "This helper needs `requests`. Install it with:\n"
        "    python3 -m pip install requests\n"
    )
    raise SystemExit(2)


CONNECT_URL = "https://www.etsy.com/oauth/connect"
TOKEN_URL = "https://api.etsy.com/v3/public/oauth/token"

DEFAULT_SCOPE = "listings_r shops_r"
DEFAULT_PORT = 3003
DEFAULT_REDIRECT_PATH = "/oauth/redirect"
DEFAULT_TOKEN_FILE = ".etsy_tokens.json"

# Etsy does not report refresh-token expiry. Documented lifetime is 90 days.
REFRESH_TOKEN_LIFETIME = 90 * 24 * 3600

# Refresh a little early rather than racing the clock mid-run.
EXPIRY_MARGIN = 120

CALLBACK_TIMEOUT = 300


class AuthError(Exception):
    """Anything that should stop the flow with a readable message."""


# --------------------------------------------------------------------------
# Token store
# --------------------------------------------------------------------------


def token_file_path(path=None):
    return path or os.environ.get("ETSY_TOKEN_FILE") or DEFAULT_TOKEN_FILE


def load_tokens(path=None):
    path = token_file_path(path)
    if not os.path.exists(path):
        return None
    try:
        with open(path, encoding="utf-8") as handle:
            return json.load(handle)
    except (OSError, ValueError) as exc:
        raise AuthError("Could not read token file %s: %s" % (path, exc))


def save_tokens(record, path=None):
    path = token_file_path(path)
    payload = json.dumps(record, indent=2, sort_keys=True) + "\n"
    # Create 0600 from the outset; never widen an existing file.
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as handle:
        handle.write(payload)
    try:
        os.chmod(path, 0o600)
    except OSError:
        pass
    return path


def _record_from_response(payload, scope=None, previous=None):
    now = time.time()
    expires_in = payload.get("expires_in") or 3600
    refresh_token = payload.get("refresh_token") or (previous or {}).get("refresh_token")

    record = {
        "access_token": payload["access_token"],
        "refresh_token": refresh_token,
        "token_type": payload.get("token_type", "Bearer"),
        "expires_at": now + float(expires_in),
        "obtained_at": _iso(now),
        "scope": scope or (previous or {}).get("scope") or DEFAULT_SCOPE,
    }

    # A fresh refresh_token restarts the 90-day clock; a reused one does not.
    if payload.get("refresh_token"):
        record["refresh_expires_at"] = now + REFRESH_TOKEN_LIFETIME
        record["refresh_expires_estimated"] = True
    elif previous:
        record["refresh_expires_at"] = previous.get("refresh_expires_at")
        record["refresh_expires_estimated"] = previous.get(
            "refresh_expires_estimated", True
        )

    record["etsy_user_id"] = _user_id(record["access_token"])
    return record


def _iso(epoch):
    return datetime.fromtimestamp(epoch, timezone.utc).isoformat(timespec="seconds")


def _user_id(token):
    """Etsy tokens are '<user_id>.<random>'. Handy for proving *who* consented."""
    if token and "." in token:
        head = token.split(".", 1)[0]
        if head.isdigit():
            return head
    return None


def _mask(token):
    if not token:
        return "(none)"
    if len(token) <= 12:
        return "***"
    return "%s…%s" % (token[:8], token[-4:])


def _humanize(seconds):
    seconds = int(seconds)
    if seconds <= 0:
        return "expired"
    if seconds < 3600:
        return "%dm" % (seconds // 60)
    if seconds < 86400:
        return "%dh %dm" % (seconds // 3600, (seconds % 3600) // 60)
    return "%dd %dh" % (seconds // 86400, (seconds % 86400) // 3600)


# --------------------------------------------------------------------------
# Token endpoint
# --------------------------------------------------------------------------


def _post_token(body):
    try:
        response = requests.post(
            TOKEN_URL,
            json=body,
            headers={"Accept": "application/json"},
            timeout=45,
        )
    except requests.RequestException as exc:
        raise AuthError("Token request failed: %s" % exc)

    try:
        payload = response.json()
    except ValueError:
        raise AuthError(
            "Token endpoint returned non-JSON (HTTP %d): %s"
            % (response.status_code, response.text[:300])
        )

    if response.status_code >= 400 or "access_token" not in payload:
        detail = payload.get("error_description") or payload.get("error") or payload
        hint = ""
        if payload.get("error") == "invalid_grant":
            hint = (
                "\nThis usually means the redirect_uri did not match the one "
                "registered on the app (byte for byte), the code was already "
                "used, or the code expired."
            )
        raise AuthError("Etsy rejected the token request: %s%s" % (detail, hint))

    return payload


def exchange_code(keystring, code, code_verifier, redirect_uri, scope=None):
    payload = _post_token(
        {
            "grant_type": "authorization_code",
            "client_id": keystring,
            "redirect_uri": redirect_uri,
            "code": code,
            "code_verifier": code_verifier,
        }
    )
    return _record_from_response(payload, scope=scope)


def refresh_access_token(keystring, refresh_token, previous=None):
    payload = _post_token(
        {
            "grant_type": "refresh_token",
            "client_id": keystring,
            "refresh_token": refresh_token,
        }
    )
    return _record_from_response(payload, previous=previous)


# --------------------------------------------------------------------------
# The bit the importer calls
# --------------------------------------------------------------------------


def resolve_access_token(keystring=None, path=None, force_refresh=False):
    """Return a live access token, refreshing from the stored refresh token
    if the current one is expired or about to be."""
    keystring = keystring or os.environ.get("ETSY_KEYSTRING")
    record = load_tokens(path)

    if not record:
        raise AuthError(
            "No Etsy tokens stored at %s. Run:\n"
            "    python3 etsy_auth.py authorize" % token_file_path(path)
        )

    expires_at = record.get("expires_at") or 0
    still_good = time.time() < (expires_at - EXPIRY_MARGIN)

    if still_good and not force_refresh:
        return record["access_token"]

    refresh_token = record.get("refresh_token")
    if not refresh_token:
        raise AuthError(
            "The stored Etsy access token has expired and there is no refresh "
            "token. Re-authorize:\n    python3 etsy_auth.py authorize"
        )
    if not keystring:
        raise AuthError(
            "ETSY_KEYSTRING is not set, so the expired access token cannot be "
            "refreshed."
        )

    refresh_expires_at = record.get("refresh_expires_at")
    if refresh_expires_at and time.time() > refresh_expires_at:
        raise AuthError(
            "The refresh token looks expired (issued more than 90 days ago). "
            "Harley needs to consent again:\n    python3 etsy_auth.py authorize"
        )

    updated = refresh_access_token(keystring, refresh_token, previous=record)
    save_tokens(updated, path)
    return updated["access_token"]


# --------------------------------------------------------------------------
# PKCE + the loopback consent flow
# --------------------------------------------------------------------------


def generate_pkce():
    verifier = base64.urlsafe_b64encode(os.urandom(32)).decode("ascii").rstrip("=")
    digest = hashlib.sha256(verifier.encode("ascii")).digest()
    challenge = base64.urlsafe_b64encode(digest).decode("ascii").rstrip("=")
    return verifier, challenge


def build_authorize_url(keystring, redirect_uri, challenge, state, scope=DEFAULT_SCOPE):
    query = urlencode(
        {
            "response_type": "code",
            "client_id": keystring,
            "redirect_uri": redirect_uri,
            "scope": scope,
            "state": state,
            "code_challenge": challenge,
            "code_challenge_method": "S256",
        }
    )
    return "%s?%s" % (CONNECT_URL, query)


_PAGE = """<!doctype html>
<html><head><meta charset="utf-8"><title>%(title)s</title></head>
<body style="font:16px/1.5 system-ui,sans-serif;max-width:34rem;margin:4rem auto;padding:0 1rem">
<h1 style="font-size:1.25rem">%(title)s</h1>
<p>%(body)s</p>
</body></html>
"""


class _CallbackHandler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def _respond(self, code, title, body):
        page = (_PAGE % {"title": title, "body": body}).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(page)))
        self.end_headers()
        self.wfile.write(page)

    def do_GET(self):
        parsed = urlparse(self.path)
        if parsed.path != self.server.expected_path:
            self._respond(404, "Not here", "Nothing is served at this path.")
            return

        params = {k: v[0] for k, v in parse_qs(parsed.query).items()}
        self.server.result = params

        if params.get("error"):
            self._respond(
                400,
                "Authorization declined",
                "Etsy reported: <code>%s</code>. You can close this tab."
                % params.get("error_description", params["error"]),
            )
        else:
            self._respond(
                200,
                "HarleysBooks is connected",
                "Authorization complete — you can close this tab and return "
                "to the terminal.",
            )


def wait_for_callback(port, expected_path, timeout=CALLBACK_TIMEOUT):
    try:
        server = HTTPServer(("127.0.0.1", port), _CallbackHandler)
    except OSError as exc:
        raise AuthError(
            "Could not listen on 127.0.0.1:%d (%s). Free the port, or pass "
            "--port and register the matching redirect URI on the app."
            % (port, exc)
        )

    server.expected_path = expected_path
    server.result = None
    server.timeout = 1
    deadline = time.time() + timeout

    try:
        while server.result is None and time.time() < deadline:
            server.handle_request()
    except KeyboardInterrupt:
        raise AuthError("Cancelled while waiting for Etsy to redirect back.")
    finally:
        server.server_close()

    if server.result is None:
        raise AuthError(
            "Timed out after %ds waiting for the redirect. If the browser is "
            "on another machine, re-run with --manual." % timeout
        )
    return server.result


def parse_redirect(url_or_query):
    """Accept a full redirect URL or a bare query string (--manual mode)."""
    text = url_or_query.strip()
    if not text:
        raise AuthError("Nothing pasted.")
    parsed = urlparse(text)
    query = parsed.query or (text if "=" in text else "")
    params = {k: v[0] for k, v in parse_qs(query).items()}
    if not params:
        raise AuthError("Could not find any query parameters in %r" % text[:80])
    return params


# --------------------------------------------------------------------------
# Commands
# --------------------------------------------------------------------------


def _log(message=""):
    sys.stdout.write("%s\n" % message)
    sys.stdout.flush()


def _keystring(args):
    keystring = getattr(args, "keystring", None) or os.environ.get("ETSY_KEYSTRING")
    if not keystring:
        raise AuthError(
            "ETSY_KEYSTRING is not set. Export the app keystring first:\n"
            "    export ETSY_KEYSTRING='...'"
        )
    return keystring


def cmd_authorize(args):
    keystring = _keystring(args)
    redirect_uri = args.redirect_uri or "http://localhost:%d%s" % (
        args.port,
        DEFAULT_REDIRECT_PATH,
    )
    verifier, challenge = generate_pkce()
    state = secrets.token_urlsafe(16)
    url = build_authorize_url(keystring, redirect_uri, challenge, state, args.scope)

    _log("Redirect URI : %s" % redirect_uri)
    _log("Scope        : %s" % args.scope)
    _log("")
    _log("This URI must match the one registered on the Etsy app exactly —")
    _log("including the scheme and any trailing slash.")
    _log("")
    _log("Open this URL as HARLEY (her Etsy login, not yours):")
    _log("")
    _log("    %s" % url)
    _log("")

    if args.manual:
        _log("After approving, Etsy sends the browser to a URL that will not")
        _log("load. Copy that URL from the address bar and paste it here.")
        _log("")
        try:
            pasted = input("Redirect URL: ")
        except (EOFError, KeyboardInterrupt):
            raise AuthError("Cancelled.")
        params = parse_redirect(pasted)
    else:
        if not args.no_browser:
            try:
                webbrowser.open(url)
            except Exception:
                pass
        _log("Waiting up to %ds for the redirect…" % args.timeout)
        params = wait_for_callback(
            args.port, urlparse(redirect_uri).path or "/", timeout=args.timeout
        )

    if params.get("error"):
        raise AuthError(
            "Authorization declined: %s"
            % params.get("error_description", params["error"])
        )

    returned_state = params.get("state")
    if returned_state != state:
        raise AuthError(
            "State mismatch — expected %r, got %r. Discarding this response."
            % (state, returned_state)
        )

    code = params.get("code")
    if not code:
        raise AuthError("No authorization code came back in the redirect.")

    _log("")
    _log("Exchanging the code for tokens…")
    record = exchange_code(keystring, code, verifier, redirect_uri, scope=args.scope)
    path = save_tokens(record, args.token_file)

    _log("")
    _log("Stored in %s (mode 0600)." % path)
    _log("  Etsy user id  : %s" % (record.get("etsy_user_id") or "unknown"))
    _log("  access token  : %s (expires in %s)"
         % (_mask(record["access_token"]),
            _humanize(record["expires_at"] - time.time())))
    _log("  refresh token : %s (~90 days)" % _mask(record.get("refresh_token")))
    _log("")
    _log("Confirm that user id is Harley's account, not yours — a token for the")
    _log("wrong account authorizes fine and then finds no shop.")
    _log("")
    _log("Now smoke-test the importer:")
    _log("    python3 harleys_books_importer.py fetch --limit 3 --skip-inventory")
    return 0


def cmd_refresh(args):
    keystring = _keystring(args)
    record = load_tokens(args.token_file)
    if not record:
        raise AuthError(
            "No tokens stored at %s. Run `authorize` first."
            % token_file_path(args.token_file)
        )
    if not record.get("refresh_token"):
        raise AuthError("No refresh token stored. Run `authorize` again.")

    updated = refresh_access_token(
        keystring, record["refresh_token"], previous=record
    )
    path = save_tokens(updated, args.token_file)
    _log("Refreshed. %s" % path)
    _log("  access token : %s (expires in %s)"
         % (_mask(updated["access_token"]),
            _humanize(updated["expires_at"] - time.time())))
    return 0


def cmd_show(args):
    record = load_tokens(args.token_file)
    path = token_file_path(args.token_file)
    if not record:
        _log("No tokens stored at %s." % path)
        _log("Run: python3 etsy_auth.py authorize")
        return 1

    now = time.time()
    expires_at = record.get("expires_at") or 0
    refresh_expires_at = record.get("refresh_expires_at")

    _log("Token file : %s" % path)
    _log("Etsy user  : %s" % (record.get("etsy_user_id") or "unknown"))
    _log("Scope      : %s" % record.get("scope", "?"))
    _log("Obtained   : %s" % record.get("obtained_at", "?"))
    _log("")
    _log("access token  %s" % _mask(record.get("access_token")))
    _log("  expires %s (%s)" % (_iso(expires_at), _humanize(expires_at - now)))
    _log("refresh token %s" % _mask(record.get("refresh_token")))
    if refresh_expires_at:
        note = " (estimated)" if record.get("refresh_expires_estimated") else ""
        _log("  expires %s (%s)%s"
             % (_iso(refresh_expires_at), _humanize(refresh_expires_at - now), note))

    if now > (expires_at - EXPIRY_MARGIN):
        _log("")
        _log("Access token is stale — the importer will refresh it on next use.")

    if args.print_token:
        _log("")
        _log(record.get("access_token", ""))
    return 0


def build_parser():
    parser = argparse.ArgumentParser(
        prog="etsy_auth.py",
        description="Etsy OAuth (PKCE) helper and token store for the importer.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=(
            "The keystring comes from ETSY_KEYSTRING. The app's shared secret\n"
            "is not used by this flow at all."
        ),
    )
    sub = parser.add_subparsers(dest="command")

    common = argparse.ArgumentParser(add_help=False)
    common.add_argument(
        "--token-file", help="token store path (default: $ETSY_TOKEN_FILE or %s)"
        % DEFAULT_TOKEN_FILE
    )
    common.add_argument("--keystring", help="override ETSY_KEYSTRING")

    authorize = sub.add_parser(
        "authorize", parents=[common], help="run the one-time consent flow"
    )
    authorize.add_argument(
        "--port", type=int, default=DEFAULT_PORT,
        help="loopback port for the redirect (default: %(default)s)"
    )
    authorize.add_argument(
        "--redirect-uri",
        help="full redirect URI; must match the app registration exactly",
    )
    authorize.add_argument(
        "--scope", default=DEFAULT_SCOPE, help="OAuth scope (default: %(default)s)"
    )
    authorize.add_argument(
        "--manual", action="store_true",
        help="do not listen locally; paste the redirect URL back instead"
    )
    authorize.add_argument(
        "--no-browser", action="store_true", help="print the URL, do not open it"
    )
    authorize.add_argument(
        "--timeout", type=int, default=CALLBACK_TIMEOUT,
        help="seconds to wait for the redirect (default: %(default)s)"
    )
    authorize.set_defaults(func=cmd_authorize)

    refresh = sub.add_parser(
        "refresh", parents=[common], help="force an access-token refresh now"
    )
    refresh.set_defaults(func=cmd_refresh)

    show = sub.add_parser(
        "show", parents=[common], help="show stored token status"
    )
    show.add_argument(
        "--print-token", action="store_true",
        help="also print the raw access token (it will be visible on screen)"
    )
    show.set_defaults(func=cmd_show)

    return parser


def main(argv=None):
    parser = build_parser()
    args = parser.parse_args(argv)
    if not getattr(args, "func", None):
        parser.print_help()
        return 2
    try:
        return args.func(args)
    except AuthError as exc:
        sys.stderr.write("error: %s\n" % exc)
        return 1
    except KeyboardInterrupt:
        sys.stderr.write("\ninterrupted\n")
        return 130


if __name__ == "__main__":
    raise SystemExit(main())
