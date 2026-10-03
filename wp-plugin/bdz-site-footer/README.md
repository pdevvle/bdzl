# bdz-site-footer

Replaces the "Powered by Astra WordPress Theme" credit in the footer bar with
the shop's own line: `© <year> Bedazzle Book Kits · Shop · About · Contact ·
Instagram · TikTok`.

Installed on the live site as a **single-file plugin** at
`wp-content/plugins/bdz-site-footer.php` (not a folder — the MCP write tool
rejects paths for plugin folders that do not exist yet, and WordPress loads a
bare `.php` file in the plugins directory as a plugin perfectly well). It is
activated through the `active_plugins` option. The copy here is the source of
truth; keep the two in step.

## Why a plugin and not the customizer

The footer credit is `footer-sml-section-1-credit` inside the **`astra-settings`
option**, which is one serialised array holding every Astra and Astra Pro
customizer setting — fonts, colours, button styles, the WooCommerce layout, all
of it. The available tool (`wp_update_option`) replaces an option wholesale,
with no partial update, and Astra falls back to its *defaults* for any key
missing from that array. Writing one key therefore means re-sending roughly 250
keys by hand on a live store, where a single transcription slip silently resets
settings to Astra defaults.

Astra reads the credit through `astra_get_option()`, which ends in:

    return apply_filters( "astra_get_option_{$option}", $value, $option, $default );

— `inc/core/common-functions.php:662` in 4.13.11. Filtering there changes the one
value and touches nothing else. It also survives Astra updates, which an edit to
a parent-theme file would not. (There is an `astra-child` theme folder on the
server, but `astra` is the active theme, so a child `functions.php` would never
load.)

Two details worth knowing, both confirmed against the 4.13.11 source rather than
assumed:

- The placeholder is `[current_year]`, **not** `[copyright_year]`. Astra also
  substitutes `[site_title]` and wraps it in `.ast-footer-site-title`.
- The result passes through `wp_kses_post()`, which keeps `<a>` with `target`
  and `rel`, so the links survive intact.

This only applies to the legacy footer bar. The site has
`is-header-footer-builder: false`; if that is ever switched on, Astra renders
the footer through the builder instead and reads `footer-copyright-editor`, so
this filter would stop having any effect.

## Caching

`breeze` (Cloudways) is active, so the old footer can persist in page cache
after a change. Purge Breeze if the footer still shows the Astra credit.
