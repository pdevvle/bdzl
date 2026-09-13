<?php
/**
 * Plugin Name: Etsy Sync for WooCommerce
 * Description: Imports and keeps in sync the Etsy catalogue as WooCommerce products. Etsy stays the source of truth; nothing here ever deletes a product.
 * Version:     1.1.0
 * Requires PHP: 7.4
 * Author:      HarleysBooks
 * License:     GPL-2.0-or-later
 * Text Domain: bdz-etsy-sync
 *
 * Behaviour is deliberately identical to the command-line importer that
 * accompanies it: products are matched by the SKU etsy-<listing_id>, so runs
 * update in place instead of duplicating; new products are created as drafts;
 * listings withdrawn from Etsy are set to draft, never deleted; and a store
 * product that is not simple is skipped rather than flattened.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BDZ_ETSY_VERSION', '1.1.0' );
define( 'BDZ_ETSY_FILE', __FILE__ );
define( 'BDZ_ETSY_DIR', plugin_dir_path( __FILE__ ) );
define( 'BDZ_ETSY_URL', plugin_dir_url( __FILE__ ) );

/** Every product this plugin manages carries this SKU prefix. */
define( 'BDZ_ETSY_SKU_PREFIX', 'etsy-' );

require_once BDZ_ETSY_DIR . 'includes/class-bdz-logger.php';
require_once BDZ_ETSY_DIR . 'includes/class-bdz-settings.php';
require_once BDZ_ETSY_DIR . 'includes/class-bdz-oauth.php';
require_once BDZ_ETSY_DIR . 'includes/class-bdz-etsy-client.php';
require_once BDZ_ETSY_DIR . 'includes/class-bdz-normalize.php';
require_once BDZ_ETSY_DIR . 'includes/class-bdz-importer.php';
require_once BDZ_ETSY_DIR . 'includes/class-bdz-job.php';
require_once BDZ_ETSY_DIR . 'includes/class-bdz-admin.php';

/**
 * The scheduled sync hook. Kept as a constant so activation, deactivation and
 * the settings screen cannot drift apart on the name.
 */
define( 'BDZ_ETSY_CRON_HOOK', 'bdz_etsy_sync_event' );
define( 'BDZ_ETSY_CRON_CONTINUE', 'bdz_etsy_sync_continue' );

/**
 * WooCommerce is a hard requirement — without it there is nothing to import
 * into. Fail visibly rather than fatally.
 */
function bdz_etsy_requirements_met() {
	return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
}

add_action(
	'admin_notices',
	function () {
		if ( bdz_etsy_requirements_met() ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>Etsy Sync:</strong> WooCommerce is not active, so there is nowhere to import products. The plugin is idle until it is.</p></div>';
	}
);

add_action(
	'plugins_loaded',
	function () {
		BDZ_Etsy_Admin::instance()->hooks();

		add_action( BDZ_ETSY_CRON_HOOK, 'bdz_etsy_run_scheduled' );
		add_action( BDZ_ETSY_CRON_CONTINUE, 'bdz_etsy_run_scheduled' );
	}
);

/**
 * Cron entry point. Work is time-boxed and resumable, so a catalogue larger
 * than one PHP execution window simply continues on the next tick.
 */
function bdz_etsy_run_scheduled() {
	if ( ! bdz_etsy_requirements_met() ) {
		return;
	}

	$job = new BDZ_Etsy_Job();

	if ( ! $job->is_running() ) {
		if ( ! BDZ_Etsy_Settings::get_bool( 'sync_enabled' ) ) {
			return;
		}
		$job->start( false );
	}

	$job->run_for( 40 );

	if ( $job->is_running() ) {
		// Still work to do: come straight back rather than waiting an hour.
		if ( ! wp_next_scheduled( BDZ_ETSY_CRON_CONTINUE ) ) {
			wp_schedule_single_event( time() + 60, BDZ_ETSY_CRON_CONTINUE );
		}
	}
}

register_activation_hook(
	BDZ_ETSY_FILE,
	function () {
		if ( ! wp_next_scheduled( BDZ_ETSY_CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', BDZ_ETSY_CRON_HOOK );
		}
	}
);

register_deactivation_hook(
	BDZ_ETSY_FILE,
	function () {
		wp_clear_scheduled_hook( BDZ_ETSY_CRON_HOOK );
		wp_clear_scheduled_hook( BDZ_ETSY_CRON_CONTINUE );
	}
);
