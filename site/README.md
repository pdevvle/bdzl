# bedazzlekits.com — site build

Source of truth for the site-wide work applied through the Bedazzle MCP tools.

## What lives where

| File | Applied to |
|---|---|
| `design-system.css` | Appearance > Customize > Additional CSS (`custom_css` post **96**) |
| `pages/home-body.html` | Page **11295**, the site front page (build with `tools/build_home.py`) |
| `pages/about.html` | Page **11302** `/about` |
| `pages/contact.html` | Page **11303** `/contact` |
| `tools/build_home.py` | Substitutes live image URLs and product links into the homepage |
| `tools/spectra_responsive.py` | Build step for the Spectra-block page sources (about, contact) |
| `tools/preview/` | Renders pages locally in Chromium — the only way to see them |
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
- Homepage sections run full bleed. `.bk` carries
  `margin-inline: calc(50% - 50vw)`, which breaks out of Astra's content column
  and resolves to zero on its own when the column is already full width, so it
  is correct either way. `body { overflow-x: clip }` trims the scrollbar-width
  overhang that 100vw leaves on desktop; `clip` rather than `hidden` so body
  does not become a scroll container and `position: sticky` keeps working.
- Single product pages given a velvet summary panel: the `.summary` block picks
  up the homepage gradient and pink bloom, with the variation selects, quantity
  field and meta restyled for a dark surface. CSS only — see "Not touched".
- All 59 imported products published; nothing is left in draft. Only
  `post_status` changed — names, prices, descriptions, images, variations and
  categories are exactly as the importer left them.
- Hero count changed from "57 kits" to "50+ kits" and the grid button from
  "See all 57" to "See all kits". The catalogue is 59 listings, one of which
  (11600, Bedazzling Glue Upgrade) is an add-on rather than a kit, so a fixed
  number was both wrong and brittle as Harley adds listings.
- Footer credit replaced. See `../wp-plugin/bdz-site-footer/`.
- Single product page: fixed the overlapping columns, and moved the price
  above the short description. See "The single product page" below.

## The single product page

Three Astra Pro settings interact badly here, and the symptom is the gallery
and the velvet summary panel sitting on top of the tabs and the related
products:

| setting | value |
| --- | --- |
| `single-product-gallery-layout` | `vertical-slider` |
| `single-product-tabs-layout` | `vertical` |
| `single-product-sticky-product-image` | `true` |
| `single-product-sticky-summary` | `true` |

`sticky-product-image.js` builds a wrapper around the gallery and the summary
and hard-sets `wrapper.style.height` **in pixels** from `summary.scrollHeight`,
measured once at `DOMContentLoaded` and refreshed only on `resize`. Every kit
is a variable product, so the summary grows when a shopper picks a variation
and WooCommerce reveals `.single_variation_wrap` — and the webfonts land after
the measurement too. The frozen height is always short, so the columns spill
out of the wrapper and over whatever follows.

Separately, `single-product-sticky-summary` turns `div.product` into
`display: flex`, which makes the tabs and the related products flex items that
shrink instead of spanning the row.

Both are corrected in `design-system.css` (search "layout corrections"); the
comments there carry the detail. The fix is CSS only — the premium plugin's JS
is left alone so Astra Pro updates do not clobber it.

Turning either sticky setting off in the customizer would also resolve it, and
is worth considering if these rules ever start fighting an Astra Pro update.

One of the overlapping rules was mine: an earlier revision styled the
flexslider `.flex-control-thumbs` nav as a horizontal strip. This layout does
not use that nav at all — Astra Pro's `templates/single-product-gallery.php`
replaces it with `#ast-gallery-thumbnails` — and Astra sizes
`.flex-control-thumbs` as a `calc(25% - 1em)` column, so forcing
`display: flex` on it sent the thumbnails across the main image.

## Not touched

WooCommerce settings, Stripe, products, orders, the `astra-settings` option
(every Astra/Astra Pro customizer setting), and the active theme. The design
layer restyles WooCommerce entirely through CSS, so there are no template
overrides to maintain and nothing that can break checkout.

Product content is untouched — names, prices, descriptions, images, variations
and categories are all exactly as the importer left them. Nine products had
their status flipped from draft to publish (listed above) and nothing else.

## Why the homepage is not Spectra blocks

The rest of the pages are Spectra blocks. The homepage is a single `wp:html`
block with its own scoped stylesheet, because this environment cannot reach
bedazzlekits.com: the only way to see the design before publishing is to render
it locally in Chromium, and that requires owning every rule rather than relying
on CSS that Spectra generates server-side at render time.

    python3 site/tools/build_home.py --preview   # local stand-in images
    python3 site/tools/build_home.py             # production

Editing the homepage therefore means editing `pages/home-body.html`, not the
block editor.
