=== Bedazzle MCP Extension ===

Adds server-side tools to the AI Engine MCP connector on bedazzlekits.com.

WHY THIS EXISTS
---------------
The stock AI Engine MCP connector (the 58 tools currently exposed) can manage
posts, products-as-posts, meta, terms, media, options and blocks, but it cannot
read or write files on the server. That gap blocks the two core build tasks:
scaffolding the theme and hand-writing the product page. This plugin adds the
missing capability by registering extra tools through AI Engine's own extension
filters (mwai_mcp_tools + mwai_mcp_callback).

The connector cannot install this itself — there is no file-write tool to
bootstrap from. It has to be placed on the server once, by hand. After that,
the connector gains theme-file access and the rest of the build is
self-serviceable.

WHAT IT ADDS (v0.1.0 — first move only)
---------------------------------------
- bdz_mcp_ping        health check; returns version + the tools it registered
- bdz_theme_list_files list files in a theme folder (recursive)
- bdz_theme_read_file  read one file from a theme folder
- bdz_theme_write_file create/overwrite a text file, creating folders as needed
                       (this is how a new child theme gets scaffolded)

Writes are scoped to the WordPress theme root, path-traversal is rejected, and
only source-file extensions are writable (php, css, js, json, html, txt, md,
svg, pot, po). Every tool requires edit_themes/manage_options on the MCP user.

Later moves add their own files under inc/ (WooCommerce product/order views,
nav menus, shipping zones, Astra settings). Not in this version — one move at
a time.

INSTALL (Preston, one time)
---------------------------
1. Copy the whole `bedazzle-mcp/` folder to:
       wp-content/plugins/bedazzle-mcp/
   via SFTP, the Cloudways file manager, or wp-admin > Plugins > Add New >
   Upload (zip the folder first for the upload route).
2. Activate "Bedazzle MCP Extension" in wp-admin > Plugins.
3. If AI Engine caches its MCP tool list, toggle the MCP module off/on under
   Meow Apps > AI Engine > Settings, or just reload — the tool list is built
   from the filter on each request.

VERIFY (through the connector, after activation)
------------------------------------------------
Call bdz_mcp_ping. A healthy response looks like:
   { "extension": "Bedazzle MCP Extension", "version": "0.1.0",
     "ai_engine": "...", "tools": [ "bdz_mcp_ping", "bdz_theme_list_files",
     "bdz_theme_read_file", "bdz_theme_write_file" ], ... }
If the ping tool is not visible to the connector, AI Engine has not picked up
the filter yet — re-check activation and the MCP module toggle.

NOTES
-----
- No dependencies beyond WordPress + AI Engine. Plain PHP, no build step.
- On this install the active theme is Astra with no child theme, so the first
  real use is:  bdz_theme_write_file(theme:"astra-child", relative_path:
  "style.css", ...) to scaffold the child theme the build needs.
