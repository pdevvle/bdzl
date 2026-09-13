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
- Nine products published so every homepage link resolves: 11490, 11575, 11387,
  11567, 11414, 11560 (featured grid), 11506, 11590, 11431 (ways to buy). The
  other 50 are still drafts. The "See all 57" button was relabelled "See all
  kits" to stop promising a catalogue the shop does not yet list; the hero's
  "57 kits" line should be revisited if the rest stay unpublished.

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
