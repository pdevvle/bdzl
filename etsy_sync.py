#!/usr/bin/env python3
"""
etsy_sync.py — the one command that keeps the store level with Etsy.

Etsy stays the source of truth indefinitely. This wraps the two importer
phases into a single unattended run, diffs the catalog against the previous
run so a change is visible rather than silent, and is safe to put on cron.

    python3 etsy_sync.py run --dry-run     rehearse; writes nothing to the store
    python3 etsy_sync.py run               fetch, diff, push, report
    python3 etsy_sync.py report            re-read the last run report

What a run does, in order
------------------------
1. Take a lock, so two runs can never overlap.
2. Read the existing catalog.json and keep it in memory as "before".
3. Fetch from Etsy, rewriting catalog.json.
4. Diff before/after: added, removed, price, stock, title, images.
5. Push to WooCommerce.
6. Write a report and print a summary.

Defaults are the conservative ones, because this runs unattended:

    new products land as DRAFT     a listing appearing on Etsy should not
                                   publish itself into the store unreviewed
    missing listings get DRAFTED   a listing pulled from Etsy stops selling
                                   here too, but is never deleted
    variable products are skipped  the importer refuses to flatten them

Nothing in a sync run deletes anything, ever. The worst case is a product
set to draft, which is one click to undo.

Exit codes (for cron / monitoring)
----------------------------------
    0   clean run
    1   the run completed but something failed — read the report
    2   could not start: lock held, bad config, missing credentials

Credentials come from the environment, same as the importer. See
harleys_books_importer.py for the full list.
"""

import argparse
import errno
import json
import os
import sys
import time
from datetime import datetime, timezone

import harleys_books_importer as imp

DEFAULT_LOCK = ".etsy_sync.lock"
DEFAULT_REPORT = "sync_report.json"

# Catalog fields worth telling a human about when they change.
WATCHED_FIELDS = (
    ("price", "price"),
    ("quantity", "stock"),
    ("title", "title"),
    ("section", "section"),
)


class SyncError(Exception):
    """Could not start, or could not finish safely."""


def _now_iso():
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def _log(message=""):
    sys.stdout.write("%s\n" % message)
    sys.stdout.flush()


# --------------------------------------------------------------------------
# Locking — cron will eventually overlap a slow run with the next one
# --------------------------------------------------------------------------


def _process_alive(pid):
    try:
        os.kill(pid, 0)
    except OSError as exc:
        if exc.errno == errno.ESRCH:
            return False
        if exc.errno == errno.EPERM:
            return True  # exists, owned by someone else
        raise
    return True


class RunLock:
    def __init__(self, path):
        self.path = path
        self.acquired = False

    def __enter__(self):
        for attempt in (0, 1):
            try:
                fd = os.open(self.path, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o644)
            except FileExistsError:
                holder = self._holder()
                if holder and _process_alive(holder):
                    raise SyncError(
                        "another sync is already running (pid %d, lock %s). "
                        "Refusing to run two at once." % (holder, self.path)
                    )
                if attempt:
                    raise SyncError("could not take the lock at %s" % self.path)
                # Stale lock from a killed run: clear it and try once more.
                _log("Clearing stale lock from pid %s." % holder)
                try:
                    os.unlink(self.path)
                except OSError:
                    pass
                continue
            with os.fdopen(fd, "w") as handle:
                handle.write("%d\n%s\n" % (os.getpid(), _now_iso()))
            self.acquired = True
            return self
        raise SyncError("could not take the lock at %s" % self.path)

    def _holder(self):
        try:
            with open(self.path, encoding="utf-8") as handle:
                return int((handle.readline() or "").strip())
        except (OSError, ValueError):
            return None

    def __exit__(self, *exc_info):
        if self.acquired:
            try:
                os.unlink(self.path)
            except OSError:
                pass
        return False


# --------------------------------------------------------------------------
# Diff — what actually changed on Etsy since last time
# --------------------------------------------------------------------------


def _items_by_sku(catalog):
    if not catalog:
        return {}
    return {item["sku"]: item for item in catalog.get("items") or []}


def _image_urls(item):
    return [image["url"] for image in item.get("images") or []]


def diff_catalogs(previous, current):
    """Compare two catalogs. Pure local work — costs no API calls."""
    before = _items_by_sku(previous)
    after = _items_by_sku(current)

    added = [after[s] for s in sorted(set(after) - set(before))]
    removed = [before[s] for s in sorted(set(before) - set(after))]

    modified = []
    for sku in sorted(set(before) & set(after)):
        old, new = before[sku], after[sku]
        changes = []
        for field, label in WATCHED_FIELDS:
            if old.get(field) != new.get(field):
                changes.append(
                    {"field": label, "from": old.get(field), "to": new.get(field)}
                )
        if _image_urls(old) != _image_urls(new):
            changes.append(
                {
                    "field": "images",
                    "from": len(_image_urls(old)),
                    "to": len(_image_urls(new)),
                }
            )
        if bool(old.get("review")) != bool(new.get("review")):
            changes.append(
                {
                    "field": "review",
                    "from": bool(old.get("review")),
                    "to": bool(new.get("review")),
                }
            )
        if changes:
            modified.append(
                {"sku": sku, "title": new.get("title", ""), "changes": changes}
            )

    return {
        "added": [{"sku": i["sku"], "title": i.get("title", "")} for i in added],
        "removed": [{"sku": i["sku"], "title": i.get("title", "")} for i in removed],
        "modified": modified,
        "unchanged": len(set(before) & set(after)) - len(modified),
        "had_previous": previous is not None,
    }


def _describe(change):
    return "%s: %s -> %s" % (change["field"], change["from"], change["to"])


# --------------------------------------------------------------------------
# The run
# --------------------------------------------------------------------------


def _read_catalog(path):
    if not os.path.exists(path):
        return None
    try:
        with open(path, encoding="utf-8") as handle:
            data = json.load(handle)
    except (OSError, ValueError):
        return None
    if isinstance(data, list):
        return {"items": data}
    return data if isinstance(data, dict) else None


def _importer_args(argv):
    """Build the importer's own Namespace so its defaults stay authoritative."""
    return imp.build_parser().parse_args(argv)


def cmd_run(args):
    started = time.time()
    report = {
        "started_at": _now_iso(),
        "dry_run": bool(args.dry_run),
        "catalog": {},
        "changes": {},
        "push": {},
        "review": [],
        "errors": [],
    }

    with RunLock(args.lock_file):
        previous = _read_catalog(args.catalog)

        # -- fetch ---------------------------------------------------------
        if not args.push_only:
            _log("== Fetch ==")
            fetch_argv = ["fetch", "--catalog", args.catalog,
                          "--images-dir", args.images_dir]
            if args.token_file:
                fetch_argv += ["--token-file", args.token_file]
            if args.no_images:
                fetch_argv.append("--no-images")
            if args.skip_inventory:
                fetch_argv.append("--skip-inventory")
            if args.limit:
                fetch_argv += ["--limit", str(args.limit)]
            try:
                imp.cmd_fetch(_importer_args(fetch_argv))
            except imp.ImporterError as exc:
                raise SyncError("fetch failed: %s" % exc)
        else:
            _log("== Fetch skipped (--push-only) ==")

        current = _read_catalog(args.catalog)
        if not current:
            raise SyncError("no catalog at %s after fetch" % args.catalog)

        report["catalog"] = {
            "count": len(current.get("items") or []),
            "generated_at": current.get("generated_at"),
            "source": current.get("source", {}),
        }
        report["review"] = [
            {
                "sku": i["sku"],
                "title": i.get("title", ""),
                "reasons": i.get("review_reasons", []),
            }
            for i in current.get("items") or []
            if i.get("review")
        ]

        # -- diff ----------------------------------------------------------
        changes = diff_catalogs(previous, current)
        report["changes"] = changes
        _log("")
        _log("== Changes since last run ==")
        if not changes["had_previous"]:
            _log("  No previous catalog — treating everything as new.")
        elif not (changes["added"] or changes["removed"] or changes["modified"]):
            _log("  Nothing changed on Etsy.")
        else:
            for entry in changes["added"]:
                _log("  NEW      %s  %s" % (entry["sku"], entry["title"][:55]))
            for entry in changes["removed"]:
                _log("  GONE     %s  %s" % (entry["sku"], entry["title"][:55]))
            for entry in changes["modified"]:
                _log("  CHANGED  %s  %s" % (entry["sku"], entry["title"][:45]))
                for change in entry["changes"]:
                    _log("             %s" % _describe(change))

        # -- push ----------------------------------------------------------
        if args.fetch_only:
            _log("")
            _log("== Push skipped (--fetch-only) ==")
        else:
            _log("")
            _log("== Push ==")
            push_argv = ["push", "--catalog", args.catalog,
                         "--status", args.new_status]
            if args.dry_run:
                push_argv.append("--dry-run")
            if not args.keep_missing:
                push_argv.append("--deactivate-missing")
            if args.skip_review:
                push_argv.append("--skip-review")
            if args.stock_buffer is not None:
                push_argv += ["--stock-buffer", str(args.stock_buffer)]
            if args.limit:
                push_argv += ["--limit", str(args.limit)]
            push_args = _importer_args(push_argv)
            try:
                imp.cmd_push(push_args)
            except imp.ImporterError as exc:
                raise SyncError("push failed: %s" % exc)
            report["push"] = getattr(push_args, "summary", {})

    # -- report ------------------------------------------------------------
    report["finished_at"] = _now_iso()
    report["duration_seconds"] = round(time.time() - started, 1)

    push = report["push"]
    failed = int(push.get("failed") or 0)
    report["exit_code"] = 1 if failed else 0

    try:
        with open(args.report, "w", encoding="utf-8") as handle:
            json.dump(report, handle, indent=2, ensure_ascii=False)
            handle.write("\n")
    except OSError as exc:
        _log("warning: could not write %s: %s" % (args.report, exc))

    _print_summary(report, args.report)
    return report["exit_code"]


def _print_summary(report, report_path):
    changes = report.get("changes") or {}
    push = report.get("push") or {}

    _log("")
    _log("== Summary ==")
    _log("  catalog        %s listing(s)" % report.get("catalog", {}).get("count", "?"))
    _log("  new / gone     %d / %d"
         % (len(changes.get("added") or []), len(changes.get("removed") or [])))
    _log("  changed        %d" % len(changes.get("modified") or []))
    if push:
        _log("  created        %d" % (push.get("created") or 0))
        _log("  updated        %d" % (push.get("updated") or 0))
        if push.get("skipped_not_simple"):
            _log("  skipped        %d (not simple products)"
                 % push["skipped_not_simple"])
        if push.get("drafted_missing"):
            _log("  drafted        %d (listing gone from Etsy)"
                 % len(push["drafted_missing"]))
        if push.get("failed"):
            _log("  FAILED         %d" % push["failed"])
    if report.get("review"):
        _log("  REVIEW         %d listing(s) need a human" % len(report["review"]))
        for entry in report["review"]:
            _log("     %s  %s" % (entry["sku"], "; ".join(entry["reasons"])))
    if report.get("dry_run"):
        _log("")
        _log("  DRY RUN — nothing was written to the store.")
    _log("")
    _log("  report: %s" % report_path)


def cmd_report(args):
    if not os.path.exists(args.report):
        _log("No report at %s. Run a sync first." % args.report)
        return 1
    with open(args.report, encoding="utf-8") as handle:
        report = json.load(handle)
    _log("Run started %s (%.1fs)"
         % (report.get("started_at"), report.get("duration_seconds") or 0))
    _print_summary(report, args.report)
    return int(report.get("exit_code") or 0)


# --------------------------------------------------------------------------
# CLI
# --------------------------------------------------------------------------


def build_parser():
    parser = argparse.ArgumentParser(
        prog="etsy_sync.py",
        description="Keep the WooCommerce store level with Etsy, unattended.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=(
            "Nothing here deletes anything. Listings that vanish from Etsy are\n"
            "set to draft, never removed."
        ),
    )
    sub = parser.add_subparsers(dest="command")

    run = sub.add_parser("run", help="fetch, diff, push, report")
    run.add_argument("--catalog", default=imp.DEFAULT_CATALOG,
                     help="catalog file (default: %(default)s)")
    run.add_argument("--images-dir", default=imp.DEFAULT_IMAGE_DIR,
                     help="local image backups (default: %(default)s)")
    run.add_argument("--token-file", help="Etsy token store")
    run.add_argument("--report", default=DEFAULT_REPORT,
                     help="where to write the run report (default: %(default)s)")
    run.add_argument("--lock-file", default=DEFAULT_LOCK,
                     help="run lock (default: %(default)s)")
    run.add_argument(
        "--new-status", default="draft",
        choices=["draft", "publish", "pending", "private"],
        help="status for products created this run (default: %(default)s)",
    )
    run.add_argument(
        "--keep-missing", action="store_true",
        help="do not draft products whose Etsy listing is gone",
    )
    run.add_argument("--skip-review", action="store_true",
                     help="do not push listings flagged REVIEW")
    run.add_argument("--stock-buffer", type=int,
                     help="override $STOCK_BUFFER for this run")
    run.add_argument("--no-images", action="store_true",
                     help="skip local image backups")
    run.add_argument("--skip-inventory", action="store_true",
                     help="skip per-listing inventory calls")
    run.add_argument("--fetch-only", action="store_true",
                     help="refresh the catalog and diff, but do not push")
    run.add_argument("--push-only", action="store_true",
                     help="push the existing catalog without re-fetching")
    run.add_argument("--limit", type=int, help="cap items (testing)")
    run.add_argument("--dry-run", action="store_true",
                     help="rehearse the push without writing to the store")
    run.set_defaults(func=cmd_run)

    report = sub.add_parser("report", help="re-read the last run report")
    report.add_argument("--report", default=DEFAULT_REPORT)
    report.set_defaults(func=cmd_report)

    return parser


def main(argv=None):
    parser = build_parser()
    args = parser.parse_args(argv)
    if not getattr(args, "func", None):
        parser.print_help()
        return 2
    if getattr(args, "fetch_only", False) and getattr(args, "push_only", False):
        sys.stderr.write("error: --fetch-only and --push-only are exclusive\n")
        return 2
    try:
        return args.func(args)
    except SyncError as exc:
        sys.stderr.write("error: %s\n" % exc)
        return 2
    except imp.ImporterError as exc:
        sys.stderr.write("error: %s\n" % exc)
        return 2
    except KeyboardInterrupt:
        sys.stderr.write("\ninterrupted\n")
        return 130


if __name__ == "__main__":
    raise SystemExit(main())
