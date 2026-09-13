#!/usr/bin/env python3
"""Rebuild preview.html: mock Astra shell + a CONSTRAINED .ast-container around
the page body, so the full-bleed break-out is actually exercised."""
import re, subprocess, sys, pathlib

root = pathlib.Path("/home/user/bdzl")
body = subprocess.run([sys.executable, "site/tools/build_home.py", "--preview"],
                      cwd=root, capture_output=True, text=True, check=True).stdout
body = body.replace("<!-- wp:html -->", "").replace("<!-- /wp:html -->", "")

HEAD = """<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Playfair:ital,wght@0,400..900;1,400..900&family=Reddit+Sans:wght@300..800&display=swap" rel="stylesheet">
<style>
body{margin:0;background:#faf7f2;font-family:'Reddit Sans',system-ui,sans-serif;overflow-x:clip}
.site-header{background:#fff;border-bottom:1px solid #ebe1d6}
.shdr{max-width:1160px;margin:0 auto;padding:16px 28px;display:flex;align-items:center;justify-content:space-between;gap:20px}
.brand{font-family:'Playfair',serif;font-size:1.45rem;font-weight:600;color:#16121c;letter-spacing:-.015em}
.brand small{display:block;font-family:'Reddit Sans',sans-serif;font-size:.7rem;letter-spacing:.1em;text-transform:uppercase;color:#847b90;font-weight:500;margin-top:2px}
nav a{color:#16121c;text-decoration:none;font-size:.95rem;font-weight:500;margin-left:26px}
/* This is the thing under test: Astra's content container, still capped. */
.ast-container{max-width:1160px;margin:0 auto;padding-left:20px;padding-right:20px}
.site-footer{background:#16121c;color:#9c93a8;font-size:.9rem;padding:26px 0}
.sftr{max-width:1160px;margin:0 auto;padding:0 28px}
</style></head><body>
<header class="site-header"><div class="shdr"><div class="brand">Bedazzle Book Kits<small>The original Book Bedazzle Kit</small></div><nav><a href="#">Shop</a><a href="#">Custom kits</a><a href="#">About</a><a href="#">Contact</a></nav></div></header>
<div id="content" class="site-content"><div class="ast-container"><div id="primary">
"""
FOOT = """
</div></div></div>
<footer class="site-footer"><div class="sftr">Copyright &copy; 2026 Bedazzle Book Kits</div></footer>
</body></html>
"""
pathlib.Path(__file__).with_name("preview.html").write_text(HEAD + body + FOOT)
print("preview.html rebuilt")
