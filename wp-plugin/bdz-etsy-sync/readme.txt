=== Etsy Sync for WooCommerce ===
Requires PHP: 7.4
Requires at least: 6.0
Tested up to: 6.8
License: GPL-2.0-or-later

Imports the Etsy catalogue into WooCommerce and keeps it in sync. Etsy stays the
source of truth. Nothing here ever deletes a product.

== Install ==

1. Copy the `bdz-etsy-sync` folder to `wp-content/plugins/` and activate it.
2. Go to **WooCommerce -> Etsy Sync**.
3. Paste the Etsy app keystring and save. (Better: define it in wp-config.php —
   see Credentials below.)
4. Copy the callback URL shown on that screen and register it on the Etsy app,
   exactly as shown.
5. Press **Connect to Etsy**. Sign in as the *shop owner*, approve, and you land
   back on the settings screen connected.
6. Press **Dry run** first. It reports what it would do and writes nothing.
7. Press **Sync now**.

== Credentials ==

The keystring can live in wp-config.php instead of the database, which is the
recommended posture — a database dump then carries nothing usable:

    define( 'BDZ_ETSY_KEYSTRING', '...' );

The field becomes read-only when the constant is defined.

Etsy wants `keystring:shared_secret` in the `x-api-key` header on API calls, so
both values are needed. The OAuth handshake itself uses PKCE and does not need
the secret — the two are separate credentials.

Tokens are stored in a non-autoloaded option. Access tokens last an hour and
refresh automatically; the refresh token lasts ninety days. If nothing syncs for
ninety days, the shop owner reconnects.

== What it does, and what it refuses to do ==

Products are matched by SKU, which is the **bare Etsy listing id** (e.g.
`4438592263`); variations are `<listing_id>-<inventory_product_id>`. Re-running
updates in place instead of creating a second copy of everything.

Products imported before 1.2.0 used an `etsy-` prefix. They are found, adopted
and renamed on the next sync — not duplicated. Ownership is decided by the
listing-id meta, never by the SKU, so products the plugin did not create are
never claimed.

* New products are created as **drafts** by default. A listing appearing on Etsy
  should not publish itself into the store unreviewed.
* An existing product's **status is never overwritten**. Unpublishing something
  sticks.
* Listings withdrawn from Etsy have their product set to **draft, never
  deleted**. Pulling something from sale is reversible.
* A product this plugin **did not create is never converted** between simple
  and variable; it is skipped. A listing that gains or loses its Etsy options
  does convert its own product, because Etsy is the source of truth.
* Images are re-imported **only when the Etsy image set actually changed**,
  tracked by a signature. Without that, every run would re-download the whole
  gallery.
* Listings with Etsy variations become **WooCommerce variable products**. Each
  Etsy property (Book, Amount of Gems, Tools) becomes an attribute, and each
  buyable combination becomes a variation carrying its own price and stock.
  Disabled or unpriced offerings are left out, so the storefront never offers a
  choice that cannot be bought.
* Variations are the one thing that is ever deleted, and only this plugin's
  own: a combination Etsy no longer offers must stop being buyable. Deletion is
  scoped by SKU to the listing's own variations.
* **Review** is reserved for what cannot be represented faithfully — no price,
  no images, or offerings that exist but are all disabled.

== Shipping weight ==

Etsy has no way to make a property affect shipping weight, and its listing
weights are not reliable for shipping anyway, so the figures are set here:

* **Kit only** — 22 oz by default
* **Kit with the book** — 80 oz (5 lb) by default

Variations whose *Book* option starts with "with" get the second figure;
everything else, including simple products, gets the first. Only the *Book*
property counts — the spine-only kits also offer "With Tools", and tools are
not a book.

Figures are entered in the store's own weight unit. Set one to `0` to fall back
to whatever weight the Etsy listing carries.

**The figures are per kit.** A listing holding more than one kit multiplies
them by its kit count, set in *Bundle kit counts* as one
`<listing id> = <kits>` per line. Five kits with books is five times the
with-book figure, not one of it.

The nine multi-kit listings in this shop ship configured:

    4463553748 = 8   Throne of Glass 8 Bedazzle Kit bundle
    4550005746 = 8   Throne of Glass HARDCOVER set
    4569859586 = 7   Dungeon Crawler Carl Spine Only (full set)
    4551858837 = 6   Red Rising Series
    4302308611 = 5   ACOTAR all five
    4300717084 = 5   ACOTAR all five B&N Editions
    4485449813 = 5   ACOTAR HARDCOVER
    1853002208 = 3   All 3 Fourth Wing Hardcover
    4385844332 = 3   OUABH UK (gem options only, so no with-book variant)

Each was found two independent ways — a title naming more than one kit, and a
top variant price far above a single kit — and the count then checked against
price per kit. A listing whose title suggests several kits but has no count
configured is **warned about** on every run rather than quietly shipping at the
weight of one kit.

A hand correction is safe: **a weight already set on a product or variation is
never overwritten.** Products with no weight yet are filled in, which is what an
already-imported catalogue needs. Tick *Reapply these weights to existing
products* only when the figures themselves have changed and should be pushed
over everything — including the hand corrections.

Dimensions matter mainly for the bundles, and Etsy carries none at all for this
shop, so they are set by hand on those nine products. As with weight, **a
dimension already set is never overwritten** — a bundle box measured once stays
measured. Dimensions are not adjusted for the book.

== Running time ==

Importing a few dozen listings with images does not fit in one PHP execution
window. The work is a resumable state machine run in short slices, so a timeout
costs one slice rather than the whole run. Closing the browser mid-run is safe:
the hourly schedule picks the job up where it stopped.

== Scheduling ==

Tick **Sync automatically every hour** to run unattended via WP-Cron. On a quiet
site WP-Cron only fires when someone visits, so for reliable timing disable it
and drive wp-cron.php from a real cron job:

    define( 'DISABLE_WP_CRON', true );

    */15 * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron >/dev/null

== Troubleshooting ==

*Etsy returned 403* — the app is probably not approved for the Open API v3 yet,
or the keystring is wrong.

*Could not determine the Etsy shop* — the connected account most likely does not
own a shop. Check the Etsy user id shown on the status panel: connecting with
the wrong login succeeds and only fails at this point. Disconnect and reconnect
as the shop owner.

*Etsy rejected the token request: invalid_grant* — the callback URL does not
match the Etsy app registration byte for byte, including any trailing slash.

*Products have no SKU* warning — those products cannot be matched, so an import
will create duplicates alongside them. Either give a product the matching
listing-id SKU to adopt it, or draft it and let the import create a clean one.

== Relationship to the command-line importer ==

This plugin and `harleys_books_importer.py` implement the same rules and are
cross-checked against each other: given identical Etsy input, both produce
byte-identical titles, prices, descriptions, review flags and image signatures.
Use whichever suits — the plugin for on-site and unattended operation, the CLI
for scripted or offline work.
