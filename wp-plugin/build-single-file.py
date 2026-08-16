#!/usr/bin/env python3
"""
Build the single-file form of the Etsy Sync plugin.

WordPress accepts a plugin as one PHP file directly in wp-content/plugins/,
which is the only shape that can be pushed through a connector whose
file-write tool cannot create a new plugin directory.

The multi-file plugin under bdz-etsy-sync/ stays canonical. This produces a
generated artifact from it, so the two cannot drift by hand:

    python3 build-single-file.py            -> build/bdz-etsy-sync.php
    python3 build-single-file.py --check    -> verify without writing

The assets have nowhere to live in a single file, so they are compiled into a
BDZ_Etsy_Assets class. class-bdz-admin.php branches on that class existing.

Standard library only.
"""

import argparse
import os
import re
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, "bdz-etsy-sync")
OUT_DIR = os.path.join(HERE, "build")
OUT = os.path.join(OUT_DIR, "bdz-etsy-sync.php")

# Order matters: classes are concatenated, so anything referenced at load time
# must already be defined. Nothing here runs at include time except the
# bootstrap in the main file, which is emitted last.
INCLUDES = [
    "includes/class-bdz-logger.php",
    "includes/class-bdz-settings.php",
    "includes/class-bdz-oauth.php",
    "includes/class-bdz-etsy-client.php",
    "includes/class-bdz-normalize.php",
    "includes/class-bdz-importer.php",
    "includes/class-bdz-job.php",
    "includes/class-bdz-admin.php",
]

ABSPATH_GUARD = re.compile(
    r"if\s*\(\s*!\s*defined\(\s*'ABSPATH'\s*\)\s*\)\s*\{\s*\n\s*exit;\s*\n\}\s*\n",
    re.MULTILINE,
)

REQUIRE_LINE = re.compile(r"^require_once BDZ_ETSY_DIR .*\n", re.MULTILINE)


def read(rel):
    with open(os.path.join(SRC, rel), encoding="utf-8") as handle:
        return handle.read()


def strip_php_open(text):
    text = text.lstrip()
    if text.startswith("<?php"):
        text = text[len("<?php"):]
    return text.lstrip("\n")


def body_of(rel):
    """A source file with its opening tag and redundant ABSPATH guard removed."""
    text = strip_php_open(read(rel))
    text = ABSPATH_GUARD.sub("", text, count=1)
    return text.strip() + "\n"


def nowdoc(name, content):
    """Embed literal text with no interpolation and no escaping surprises."""
    delimiter = "BDZ_%s_LITERAL" % name.upper()
    if delimiter in content:
        raise SystemExit("delimiter %s collides with content" % delimiter)
    if content.endswith("\n"):
        content = content[:-1]
    return "<<<'%s'\n%s\n%s" % (delimiter, content, delimiter)


def build():
    main = read("bdz-etsy-sync.php")

    # The single file defines everything itself; the requires have no targets.
    main = REQUIRE_LINE.sub("", main)
    main = main.replace(
        " * Text Domain: bdz-etsy-sync\n",
        " * Text Domain: bdz-etsy-sync\n *\n"
        " * GENERATED FILE — built from wp-plugin/bdz-etsy-sync/ by\n"
        " * wp-plugin/build-single-file.py. Edit the sources, not this file.\n",
    )

    header, _, bootstrap = main.partition("define( 'BDZ_ETSY_SKU_PREFIX', 'etsy-' );")
    if not bootstrap:
        raise SystemExit("could not find the SKU prefix define in the main file")

    parts = [
        header.rstrip() + "\n",
        "define( 'BDZ_ETSY_SKU_PREFIX', 'etsy-' );\n",
    ]

    # Compiled assets first, so class_exists() is already true when the admin
    # class is defined and, more importantly, when it enqueues.
    parts.append(
        "\n/**\n"
        " * Admin CSS and JS, compiled in because a single-file plugin has no\n"
        " * assets directory. Generated — see build-single-file.py.\n"
        " */\n"
        "class BDZ_Etsy_Assets {\n\n"
        "\tpublic static function css() {\n\t\treturn %s;\n\t}\n\n"
        "\tpublic static function js() {\n\t\treturn %s;\n\t}\n}\n"
        % (
            nowdoc("css", read("assets/admin.css")),
            nowdoc("js", read("assets/admin.js")),
        )
    )

    for rel in INCLUDES:
        parts.append("\n/* ---- %s ---- */\n\n" % rel)
        parts.append(body_of(rel))

    parts.append("\n/* ---- bootstrap ---- */\n")
    parts.append(bootstrap.lstrip("\n"))

    return "".join(parts)


def main_cli():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true",
                        help="build and lint, but do not write the artifact")
    parser.add_argument("--out", default=OUT, help="output path")
    args = parser.parse_args()

    source = build()

    os.makedirs(os.path.dirname(args.out), exist_ok=True)
    tmp = args.out + ".tmp"
    with open(tmp, "w", encoding="utf-8") as handle:
        handle.write(source)

    lint = subprocess.run(["php", "-l", tmp], capture_output=True, text=True)
    if lint.returncode != 0:
        sys.stderr.write(lint.stdout + lint.stderr)
        os.unlink(tmp)
        raise SystemExit("generated file does not lint")

    if args.check:
        os.unlink(tmp)
        print("lint OK — %d bytes, %d lines (not written)"
              % (len(source.encode("utf-8")), source.count("\n") + 1))
        return 0

    os.replace(tmp, args.out)
    print("wrote %s — %d bytes, %d lines"
          % (args.out, len(source.encode("utf-8")), source.count("\n") + 1))
    return 0


if __name__ == "__main__":
    raise SystemExit(main_cli())
