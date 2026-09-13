# bedazzlekits.com — site build

Source of truth for the site-wide work applied through the Bedazzle MCP tools.

## What lives where

| File | Applied to |
|---|---|
| `design-system.css` | Appearance > Customize > Additional CSS (`custom_css` post **96**) |
| `pages/home.html` | Page **11295**, the site front page |
| `pages/about.html` | Page **11302** `/about` |
| `pages/contact.html` | Page **11303** `/contact` |
| `tools/spectra_responsive.py` | Build step for the page sources |
| `backups/` | Previous values of everything that was overwritten |

`../harleys_books_page.html` was the earlier front-page source; `pages/home.html`
replaced it once the Etsy importer filled the catalogue.

## The Spectra gotcha

Page sources are authored with normal root-level `style` attributes, then run
through the transform before publishing:

    python3 site/tools/spectra_responsive.py site/pages/home.html out.html hm

spectra-blocks 1.0.x strips `style.spacing`, `style.typography`,
`style.border` and `style.shadow` from root attributes at `render_block_data`
and generates per-breakpoint CSS from `responsiveControls.{lg,md,sm}`, keyed on
a `data-spectra-id`. Publish the raw source and the page renders with colours
but no padding, type sizes or borders. The same extension strips inline styles
from `core/image`, which is why image crops are CSS classes (`hb-tile`,
`hb-shot`, `hb-4x3`, `hb-card-media`) rather than block attributes.

## Site changes applied

- Front page switched from "Home 2019" (page 12) to page 11295.
  Page 12 was left in place as a draft, renamed "Home 2019 (retired)".
- Primary menu created (term **109**, "Primary"): Shop, About, Contact.
  Assigned to Astra's `primary` location. Nothing had been assigned before.
- Tagline set to "The original Book Bedazzle Kit".
- Shop page (7) given a one-line intro above the product loop.
- Homepage rebuilt around the imported catalogue. Featured kits link to
  product IDs 11490, 11575, 11567, 11414, 11560, 11387; the ways-to-buy row
  points at 11506 (spine only), 11590 (gems only) and 11431 (custom). Copy is
  drawn from the imported Etsy descriptions.

## Not touched

WooCommerce settings, Stripe, products, orders, the `astra-settings` option
(every Astra/Astra Pro customizer setting), and the active theme. The design
layer restyles WooCommerce entirely through CSS, so there are no template
overrides to maintain and nothing that can break checkout.

Product statuses are also untouched: the importer leaves every kit as a draft
for Harley to publish.
