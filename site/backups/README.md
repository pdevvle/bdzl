# Pre-change backups (bedazzlekits.com)

Captured before the site rebuild. Restore by writing these values back with
the Bedazzle MCP tools (`wp_update_option`, `wp_update_post`).

- `custom_css_96.css` — previous Appearance > Additional CSS (post ID 96).
  Dead Elementor selectors (Elementor is no longer installed) plus two
  single-product tweaks that the new design layer covers.
- `theme_mods_astra.json` — theme mods at the time of the rebuild.
  `nav_menu_locations` was empty, i.e. no menu was assigned to any location.

Not modified, so not backed up: `astra-settings` (all Astra/Astra Pro
customizer settings), WooCommerce settings, Stripe settings, products, orders.
The old front page ("Home 2019", page 12) was not deleted; it was unpublished
from the front-page slot and kept as a draft.
