#!/usr/bin/env python3
"""Substitute live asset URLs and product links into the homepage source.

    python3 site/tools/build_home.py                 # production HTML on stdout
    python3 site/tools/build_home.py --preview DIR   # local SVG stand-ins, for screenshots

Tokens are replaced longest-first: IMG_RR must not clobber IMG_RRWIDE.
"""
import re, sys

UPLOADS = "https://bedazzlekits.com/wp-content/uploads/2026/09/"
IMAGES = {
    "IMG_ONYX":   "il_fullxfull.6628848322_659h.jpg",      # 11495 Onyx Storm
    "IMG_TOG":    "il_fullxfull.8340035680_etlc.jpg",      # 11572 Throne of Glass set
    "IMG_ACOTAR": "il_fullxfull.6846470854_exom.jpg",      # 11421 ACOTAR all five
    "IMG_RR":     "il_fullxfull.8353422732_fhh6.jpg",      # 11565 Red Rising series
    "IMG_FW":     "il_fullxfull.6672530593_6i4c.jpg",      # 11580 Fourth Wing hardcover
    "IMG_DCC":    "il_fullxfull.8165618459_8xow.jpg",      # 11392 Dungeon Crawler Carl
    "IMG_RRWIDE": "il_fullxfull.8401304399_8xhg-scaled.jpg",  # 11566 Red Rising, landscape
    "IMG_KIT":    "il_fullxfull.6350839958_rytc-scaled.jpg",  # 11514 kit contents
    "IMG_CUSTOM": "il_fullxfull.7725328383_p17p-scaled.jpg",  # 11439 custom cover
}
PREVIEW = {t: f"img/{t[4:].lower()}.svg" for t in IMAGES}

src = open("site/pages/home-body.html").read()
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
