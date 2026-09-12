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

Products are matched by the SKU `etsy-<listing_id>`, so re-running updates in
place instead of creating a second copy of everything.

* New products are created as **drafts** by default. A listing appearing on Etsy
  should not publish itself into the store unreviewed.
* An existing product's **status is never overwritten**. Unpublishing something
  sticks.
* Listings withdrawn from Etsy have their product set to **draft, never
  deleted**. Pulling something from sale is reversible.
* A store product that is **not simple is skipped**, never flattened. Importing
  a simple product over a variable one would orphan its variations and the
  pricing that lives on them.
* Images are re-imported **only when the Etsy image set actually changed**,
  tracked by a signature. Without that, every run would re-download the whole
  gallery.
* Listings with real variations, or with price/quantity varying by property, or
  with no images, are **flagged for review** and imported as simple products at
  the cheapest enabled offering price.

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
`etsy-<listing_id>` SKU to adopt it, or draft it and let the import create a
clean one.

== Relationship to the command-line importer ==

This plugin and `harleys_books_importer.py` implement the same rules and are
cross-checked against each other: given identical Etsy input, both produce
byte-identical titles, prices, descriptions, review flags and image signatures.
Use whichever suits — the plugin for on-site and unattended operation, the CLI
for scripted or offline work.
