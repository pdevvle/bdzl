#!/usr/bin/env python3
"""Substitute live asset URLs and product links into the homepage source.

    python3 site/tools/build_home.py                 # production HTML on stdout
    python3 site/tools/build_home.py --preview DIR   # local SVG stand-ins, for screenshots

Tokens are replaced longest-first: IMG_RR must not clobber IMG_RRWIDE.
"""
import os, pathlib, re, sys

UPLOADS = "https://bedazzlekits.com/wp-content/uploads/2026/09/"
IMAGES = {
    "IMG_ONYX":   "il_fullxfull.6628848322_659h.jpg",      # 11495 Onyx Storm
    # Throne of Glass series, lined up. LANDSCAPE 2560x1920 — the hero's main
    # slot is 4/3 to suit it. The obvious alternative (11572) is the listing's
    # own thumbnail and has "THRONE OF GLASS HARDCOVER FULL SERIES BOOK
    # BEDAZZLE KITS" burned into it, which does not belong on a hero.
    "IMG_TOG":    "il_fullxfull.7725328383_p17p-scaled.jpg",
    "IMG_TOGB":   "il_fullxfull.7750064596_hni6-1-scaled.jpg",  # 11573 Kingdom of Ash
    "IMG_TOGC":   "il_fullxfull.7750064600_hrwk-1-scaled.jpg",  # 11574 Empire of Storms
    "IMG_ACOTAR": "il_fullxfull.6846470854_exom.jpg",      # 11421 ACOTAR all five
    "IMG_RR":     "il_fullxfull.8353422732_fhh6.jpg",      # 11565 Red Rising series
    "IMG_FW":     "il_fullxfull.6672530593_6i4c.jpg",      # 11580 Fourth Wing hardcover
    "IMG_DCC":    "il_fullxfull.8165618459_8xow.jpg",      # 11392 Dungeon Crawler Carl
    "IMG_RRWIDE": "il_fullxfull.8401304399_8xhg-scaled.jpg",  # 11566 Red Rising, landscape
    # Was the Fourth Wing spine (il_fullxfull.6350839958_rytc). Harley asked for
    # that one off the site: it is years old and not representative of her work
    # now. No kit-contents flat-lay exists in the catalogue, so this section
    # shows a finished cover instead.
    "IMG_KIT":    "il_fullxfull.6564782704_n48o-scaled.jpg",  # ACOTAR, no overlay
    "IMG_CUSTOM": "il_fullxfull.6581460135_eszl-scaled.jpg",  # Lightlark, no overlay
}
# Preview uses the real photos when they have been pulled down into real/,
# and falls back to the SVG stand-ins otherwise. Seeing the actual images
# matters: several catalogue photos have Etsy marketing text burned in,
# which a coloured placeholder cannot show.
# NB: the stand-in filenames must not contain "IMG_", or the
# leftover-token assertion below matches its own substitutions.
PREVIEW = {t: (f"real/{t[4:].lower()}.jpg"
               if os.path.exists(f"real/{t[4:].lower()}.jpg")
               else f"img/{t[4:].lower()}.svg") for t in IMAGES}

# anchored to the script, so the build can run from any directory
ROOT = pathlib.Path(__file__).resolve().parents[2]
src = (ROOT / "site/pages/home-body.html").read_text()
preview = "--preview" in sys.argv
table = PREVIEW if preview else {t: UPLOADS + f for t, f in IMAGES.items()}

for token in sorted(table, key=len, reverse=True):
    src = src.replace(token, table[token])
src = re.sub(r"PRODUCT_(\d+)",
             "#" if preview else r"https://bedazzlekits.com/?post_type=product&p=\1", src)
assert "IMG_" not in src and "PRODUCT_" not in src

src = src.replace('<img class="', '<img decoding="async" class="')
src = re.sub(r'<img src="', '<img loading="lazy" decoding="async" src="', src)
sys.stdout.write("<!-- wp:html -->\n" + src.rstrip("\n") + "\n<!-- /wp:html -->\n")
