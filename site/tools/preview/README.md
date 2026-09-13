# Local visual check

bedazzlekits.com is not reachable from the agent sandbox (the outbound proxy
403s the domain), so pages cannot be reviewed after publishing. These scripts
render the same markup and CSS locally in Chromium instead, which is the only
way to see a layout before it goes live.

Both scripts write their page next to themselves and expect the placeholder
SVGs in an adjacent `img/` directory (one per `IMG_*` token in
`build_home.py`, named after the token in lower case: `onyx.svg`, `tog.svg`,
`rrwide.svg`, …). Any aspect-matched stand-in works; the point is layout, not
photography.

    python3 site/tools/preview/home.py       # homepage inside a capped .ast-container
    python3 site/tools/preview/product.py    # single-product page with design-system.css
    node     site/tools/preview/shoot.mjs    # screenshots at 1440 / 900 / 390

`home.py` deliberately wraps the page in a 1160px `.ast-container`, the width
Astra caps content at, so the full-bleed break-out is actually exercised
rather than trivially satisfied by a full-width body.

`shoot.mjs` also reports whether the document overflows horizontally at each
width — the cheapest check that a full-bleed section has not introduced a
sideways scrollbar.

Two traps worth remembering, both of which produced false alarms before:

- Lazy images do not load during a `fullPage` screenshot. Flip them to eager
  and scroll the page first, or sections near the bottom capture blank.
- Mock chrome is not the page. A header or nav written only for the harness
  can overflow on its own and look like a bug in the real layout.
