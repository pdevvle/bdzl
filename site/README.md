# bedazzlekits.com — site build

Source of truth for the site-wide work applied through the Bedazzle MCP tools.

## What lives where

| File | Applied to |
|---|---|
| `design-system.css` | Appearance > Customize > Additional CSS (`custom_css` post **96**) |
| `pages/about.html` | Page **11302** `/about` (pre-transform source) |
| `pages/contact.html` | Page **11303** `/contact` (pre-transform source) |
| `../harleys_books_page.html` | Page **11295**, now the site front page |
| `backups/` | Previous values of everything that was overwritten |

`pages/*.html` are authored with root-level `style` attributes. Spectra
strips those at render, so run them through the responsive-controls
transform before publishing (see the commit history for the rule:
spacing/typography/border must live under `responsiveControls.{lg,md,sm}`).

## Site changes applied

- Front page switched from "Home 2019" (page 12) to page 11295.
  Page 12 was left in place as a draft, renamed "Home 2019 (retired)".
- Primary menu created (term **109**, "Primary"): Shop, About, Contact.
  Assigned to Astra's `primary` location. Nothing had been assigned before.
- Tagline set to "The original Book Bedazzle Kit".
- Shop page (7) given a one-line intro above the product loop.

## Not touched

WooCommerce settings, Stripe, products, orders, the `astra-settings` option
(every Astra/Astra Pro customizer setting), and the active theme. The design
layer restyles WooCommerce entirely through CSS, so there are no template
overrides to maintain and nothing that can break checkout.
