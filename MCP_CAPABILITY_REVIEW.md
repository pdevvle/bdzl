# Bedazzlekits.com MCP — Capability Review vs. the Etsy Brief

Audit date: 2026-07-26
Connector: `Bedazzlekits_com` (AI Engine 3.6.2 MCP), 58 tools
Site: https://bedazzlekits.com — "Bedazzle Book Kits"

---

## 0. Read this part first

Three things about the target site contradict assumptions in the brief. They
matter more than the tool inventory does.

### 0.1 This is not a fresh install. It is a recycled live store.

The brief describes a **new, isolated Cloudways app** with WordPress +
WooCommerce standing up clean. What is actually behind the connector is an
existing site with someone else's history in it:

| Evidence | What it means |
|---|---|
| `wp_count_posts(shop_order)` → **351 `wc-completed`**, 8 cancelled, 3 refunded, 9 failed | A real store with real customer order history and PII |
| Stripe `statement_descriptor` = `ChronicallyLexi.com` | The gateway was configured for a *different brand* |
| Pages `Home 2019` (×2) on template `elementor_header_footer` | Elementor site from 2019; Elementor itself is no longer active, so those pages render on a missing template |
| Taxonomies `pa_nail-size`, `pa_length`, `pa_color` | Attribute residue from a nail/beauty catalog |
| Taxonomy `pos_product_visibility` | A point-of-sale plugin was installed and removed |
| Product `Metal Straw Bundle` (ID 2367) | Leftover non-book product |
| `blogdescription` = "A side project." | Placeholder tagline |
| Yoast SEO Premium **18.3** (2022) *and* Rank Math both active | Two SEO plugins fighting; Yoast is ~4 years stale |
| Printify Shipping Method, Nextend Social Login, Mailchimp for Woo active | Residue, unrelated to this build |

**This needs a decision before any build work.** Either:
- **(a)** wipe/rebuild as the brief actually specified (fresh app, own DB), or
- **(b)** explicitly accept the inheritance and budget a cleanup pass.

Option (b) is not free — it means auditing 351 orders' worth of customer data,
untangling two SEO plugins, and removing a dead Elementor dependency. Option (a)
is what the brief's "blast-radius protection" line was asking for.

### 0.2 Live payment secrets are readable through the connector

`wp_get_option("woocommerce_stripe_settings")` returns, in plaintext:
`secret_key` (`sk_live_…`), `webhook_secret` (`whsec_…`), and the test-mode
equivalents.

The brief says: *"You never handle secrets — expect them as env vars."* That
control does not currently hold. Any agent with this connector can read the live
Stripe secret key.

**Action for Preston:** rotate the live secret key and webhook signing secret in
the Stripe dashboard, and decide whether `wp_get_option` should be allowlisted
rather than unrestricted. (Values are deliberately not reproduced in this repo.)

Also note the gateway is currently **live-mode enabled** (`enabled: yes`,
`testmode: no`) on a site that is not ready to take orders.

### 0.3 The theme decision in the brief is already foreclosed

The brief's open decision is *"lean custom block theme (FSE)"* vs *"classic child
theme."* Active theme is **`astra` / `astra` (parent, no child)** with **Astra
Pro 4.13.5**. Astra is a classic theme — the FSE Site Editor path does not exist
here without swapping themes wholesale.

Also: `stylesheet` == `template` means **there is no child theme**. Any hand-
written CSS/PHP dropped into `astra/` is destroyed on the next theme update.

Recommended resolution: **classic Astra child theme.** It matches what is
installed, keeps Astra Pro's header/footer builder available for Harley, and
satisfies "hand-written CSS/JS, no build step." It does *not* give Harley Site
Editor layout control — that tradeoff should be stated to her explicitly.

---

## 1. What the connector currently has (58 tools)

| Group | Tools |
|---|---|
| **Posts / CPTs** | `wp_create_post`, `wp_update_post`, `wp_alter_post`, `wp_get_post`, `wp_get_posts`, `wp_get_post_snapshot`, `wp_delete_post`, `wp_count_posts` |
| **Meta** | `wp_get_post_meta`, `wp_update_post_meta`, `wp_delete_post_meta` |
| **Terms / taxonomy** | `wp_get_terms`, `wp_create_term`, `wp_update_term`, `wp_delete_term`, `wp_count_terms`, `wp_get_taxonomies`, `wp_get_post_terms`, `wp_add_post_terms` |
| **Media** | `wp_upload_media`, `wp_upload_request`, `wp_get_media`, `wp_update_media`, `wp_delete_media`, `wp_count_media`, `wp_set_featured_image` + REST twins `create/get/list/update/delete_media` |
| **Pages / posts (REST twins)** | `create/get/list/update/delete_pages`, `create/get/list/update/delete_posts` |
| **Blocks** | `wp_write_blocks`, `wp_list_block_patterns`, `wp_insert_block_pattern` |
| **Users / comments** | `wp_get_users`, `wp_create_user`, `wp_update_user`, `wp_get_comments`, `wp_create_comment`, `wp_update_comment`, `wp_delete_comment` |
| **Site config** | `wp_get_option`, `wp_update_option`, `wp_get_post_types`, `wp_list_plugins` |
| **AI Engine** | `mwai_image`, `mwai_vision` |
| **Health** | `mcp_ping` |

This is the **stock AI Engine MCP surface**. None of the custom `pps_*`
extensions that exist on the PPS / priority-print connectors are present here.

---

## 2. Brief task → capability mapping

| # | Brief task | Status | Notes |
|---|---|---|---|
| 4 | Scaffold chosen theme, hand-written CSS/JS | 🔴 **Blocked** | No file read/write of any kind. Cannot create a child theme, `style.css`, `functions.php`, `single-product.php`, or `theme.json`. |
| 5 | Importer Phase 2 → products land correctly | 🟡 **Partial** | The *push* runs over the WooCommerce REST API (out of band, needs ck/cs — unaffected by MCP). **Verification** through MCP is possible but crude: `wp_get_posts(post_type=product)` + `wp_get_post_snapshot` exposes meta/terms/thumbnail, so `_price`, `_sku`, `_stock` are readable. There is no Woo-aware product view, no gallery-as-images view, no bulk price/stock diff. |
| 6 | Product page: swatch UI, image switching, add-to-cart JS | 🔴 **Blocked** | Same root cause as #4 — needs template + asset files. |
| 7 | Store pages (home, shop, product, about, cart/checkout) | 🟡 **Partial** | **Page bodies: yes** — `create_pages` + `wp_write_blocks` + block patterns work well. **Navigation menus: no tool.** **Header/footer (Astra HFB): no tool.** **Site identity/logo: no tool.** Menus and Astra settings are technically reachable by hand-writing serialized values through `wp_update_option`, but that is unsafe and unreviewable — not a real answer. |
| 8 | Payments + shipping zones | 🟡 **Partial** | Stripe settings live in the `woocommerce_stripe_settings` **option** → `wp_update_option` can write it. **Shipping zones cannot be touched** — they live in custom tables (`wp_woocommerce_shipping_zones`, `_zone_methods`, `_zone_locations`), not options. Same for tax rates (`wp_woocommerce_tax_rates`). |
| 9 | QA pass / checkout end-to-end | 🟡 **Partial** | HPOS is **off** (`woocommerce_feature_custom_order_tables_enabled` = `false`), so orders are still in `wp_posts` and `wp_get_posts(post_type=shop_order)` *does* reach them. But there is no order-detail, line-item, status-transition, or order-note tool — QA and Harley's day-to-day order handling both stay manual. ⚠️ If anyone flips HPOS on later, orders become **completely invisible** to this connector. |

Not blocked, works today: brand/content authoring, product categories and tags,
media upload and featured images, SEO/option tweaks, and reading current state.

---

## 3. What needs to be added to the connector

Ranked by what actually unblocks the brief. The PPS connectors already implement
most of these — this is largely a matter of porting the same plugin code onto
this install.

### Tier 1 — hard blockers (cannot ship the brief without these)

1. **Theme file access** — `theme_list_files` / `theme_read_file` /
   `theme_write_file`
   *Unblocks tasks 4 and 6 outright.* Exists verbatim on PPS-Production and
   priority-print as `pps_theme_*`. Without it, every line of the hand-written
   CSS/JS the brief mandates has to be pasted in by hand.

2. **Shipping zone management** — `woo_list/create/update_shipping_zone`,
   `..._zone_method`, `..._zone_location`
   *Unblocks task 8.* Custom tables, so no option-level workaround exists.
   Not present on any of the existing connectors — this one has to be written.

### Tier 2 — high value (turns "clunky and unverifiable" into "reviewable")

3. **WooCommerce product tools** — `woo_list_products`, `woo_get_product`,
   `woo_update_product`, `woo_list_categories`
   Makes importer verification (task 5, and the Definition of Done's
   "correct price, stock, images, and `etsy-<id>` SKUs") a real check instead of
   meta-key archaeology. `pps_woo_*` equivalents already exist.

4. **Navigation menus** — `list/create_nav_menu`, `save_menu_item`,
   `assign_menu_location`
   Task 7 is not done without a nav menu. `pps_*` equivalents exist on
   priority-print.

5. **Custom CSS** — `get_custom_css` / `set_custom_css`
   A safe fallback surface for styling while theme-file access is pending, and
   the correct home for small tweaks afterward. Exists on priority-print.

### Tier 3 — operational quality

6. **Order tools** — `woo_list_orders`, `woo_get_order`,
   `woo_update_order_status`, `woo_add_order_note`
   Needed for task 9 QA and for Harley running the shop. Also the thing that
   stops HPOS from becoming a cliff edge later.

7. **Astra + theme mods** — `get/set_astra_setting`, `get/set_theme_mod`,
   `get/set_site_identity`
   Header/footer builder, logo, colors. Exists on priority-print.

8. **Update management** — `wp_check_updates`, `wp_update_plugin/theme/core`
   Yoast 18.3 is roughly four years stale on a site that takes payments.

### Explicitly *not* recommended

- **Plugin file write** (`pps_plugin_write_file`). Present on the PPS
  connectors; there is no task in this brief that needs it. Leave it off.

---

## 4. Non-MCP items still owed by Preston

Unchanged from the brief, but now with specifics:

- **Rotate the Stripe live secret + webhook secret** (§0.2), and re-point the
  statement descriptor away from `ChronicallyLexi.com`.
- **Disable the live gateway** until the store is ready to transact.
- WooCommerce REST consumer key/secret for importer Phase 2 (not readable or
  creatable through MCP by design — correct).
- Etsy keystring + Harley's one-time OAuth consent.
- Confirm **All-In-One Security (AIOS 5.4.9)** is not blocking `/wp-json/wc/v3/`
  — it commonly is, and that would fail the importer push with a 403 that looks
  like an auth problem.
- Ratify the fresh-install vs. inherit-and-clean decision in §0.1.
- Ratify the classic-child-theme resolution in §0.3.

---

## 5. Summary

The connector is a **stock AI Engine MCP**. It is genuinely good at the content
half of the brief — pages, products-as-posts, media, taxonomy, options — and can
do task 7's page bodies today.

It cannot do the **build** half at all. Tasks 4 and 6 are the core of the brief
("chosen theme live, hand-written CSS/JS") and both are blocked on a single
missing capability: **theme file write**. Task 8 is blocked on shipping zones,
which no existing connector implements.

Adding Tier 1 (two capabilities, one of which is a straight port from the PPS
connector) moves the brief from *"cannot start"* to *"buildable."* Tier 2 moves
it from *"buildable"* to *"verifiable against the Definition of Done."*

Before any of that: §0.1 and §0.2 need Preston's call. The site behind this
connector is not the site the brief describes, and it is holding live payment
credentials in a readable option.
