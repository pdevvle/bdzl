=== Bedazzle MCP Tools ===

Companion plugin for AI Engine that adds server-management tools to the MCP
connector on bedazzlekits.com. Ported from the working "Priority Print MCP
Tools" plugin, rebranded (bdz_ prefix) and re-scoped for this store.

WHY THIS EXISTS
---------------
The stock AI Engine MCP connector (the 58 tools currently exposed) can manage
posts, products-as-posts, meta, terms, media, options and blocks, but it
cannot:
  - read or write files on the server  -> blocks the theme scaffold and the
    hand-written product page (brief tasks 4 and 6)
  - show a WooCommerce-aware product/order view -> makes importer verification
    (task 5) and order QA (task 9) guesswork
  - run core/plugin/theme updates

This plugin closes those gaps by registering extra MCP tools through AI
Engine's own extension filters (mwai_mcp_tools + mwai_mcp_callback), returning
proper JSON-RPC result envelopes.

The connector cannot install this itself — there is no file-write tool to
bootstrap from. It is placed on the server once, by hand (below). After that,
bdz_plugin_write_file can add later tool groups (nav menus, shipping zones,
Astra settings) over the wire, no more manual installs.

TOOLS ADDED (v1.0.0)
--------------------
Health:
  bdz_mcp_ping              version + registered tool list (call first to verify)

WooCommerce (read):
  bdz_woo_list_products     supports category_slug / search / sku / status / limit
  bdz_woo_get_product       full product incl. sku, price, stock, images, meta
  bdz_woo_list_categories
  bdz_woo_get_category
  bdz_woo_list_orders
  bdz_woo_get_order         line items, billing/shipping, notes

WooCommerce (write):
  bdz_woo_update_product
  bdz_woo_update_order_status
  bdz_woo_add_order_note

Theme files (scoped to the child theme; default "astra-child"):
  bdz_theme_list_files      exists:false when the theme isn't scaffolded yet
  bdz_theme_read_file
  bdz_theme_write_file      creates the theme folder + subfolders on first write

Plugin files (scoped to wp-content/plugins):
  bdz_plugin_list_files
  bdz_plugin_read_file
  bdz_plugin_write_file
  bdz_plugin_download_url   https only, 12MB cap, 60s timeout

Updates:
  bdz_wp_check_updates
  bdz_wp_get_plugin_versions
  bdz_wp_update_plugin
  bdz_wp_update_theme
  bdz_wp_update_core

SAFETY MODEL
------------
  - Theme writes restricted to one theme dir (default "astra-child"; override
    with:  define('BDZ_MCP_THEME_SLUG', 'your-child-theme');  in wp-config.php).
    The folder may not exist yet — the first write scaffolds it.
  - Plugin writes/downloads restricted to wp-content/plugins.
  - Path traversal blocked via realpath() containment.
  - Read/write ops carry MCP annotations (readOnlyHint / destructiveHint).
  - Update tools wrap WordPress's own Plugin/Theme/Core upgraders.
  - Auth is handled by AI Engine's MCP layer (bearer token) — same trust model
    as the tools already exposed.

INSTALL (Preston, one time)
---------------------------
1. Copy the `bedazzle-mcp/` folder to:  wp-content/plugins/bedazzle-mcp/
   via SFTP, the Cloudways file manager, or wp-admin > Plugins > Add New >
   Upload (zip the folder first for the upload route).
2. Activate "Bedazzle MCP Tools" in wp-admin > Plugins.
3. If AI Engine caches its MCP tool list, toggle the MCP module off/on under
   Meow Apps > AI Engine > Settings (the list is otherwise rebuilt per request).

VERIFY (through the connector, after activation)
------------------------------------------------
Call bdz_mcp_ping. Healthy response includes plugin/version, ai_engine version,
woocommerce:true, theme_slug:"astra-child", and the full tools list. If the
connector can't see bdz_mcp_ping, AI Engine hasn't picked up the filter yet —
re-check activation and the MCP module toggle.

NOTES
-----
  - No dependencies beyond WordPress + AI Engine + WooCommerce. Plain PHP,
    no build step.
  - Active theme on this install is Astra (parent) with no child theme, so the
    first real use is scaffolding astra-child:
      bdz_theme_write_file(relative_path:"style.css", contents:"/* Theme Name:
      Astra Child ... Template: astra */")
      bdz_theme_write_file(relative_path:"functions.php", contents:"<?php ...")
    then activate Astra Child.
