<?php
/**
 * The resumable sync job.
 *
 * Importing ~52 listings means ~52 inventory calls plus sideloading a few
 * hundred images. That does not fit in one PHP execution window, so the work is
 * a state machine driven in time-boxed ticks. The admin screen drives it over
 * AJAX; cron drives the same ticks unattended. Either way progress is stored,
 * so a timeout costs one tick rather than the whole run.
 *
 * Phases:
 *   listings   resolve the shop, pull every active listing (1-2 requests)
 *   inventory  per listing: inventory + images, normalized into the catalogue
 *   push       per item: create or update the WooCommerce product
 *   finalize   draft withdrawn listings, diff against the previous run
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BDZ_Etsy_Job {

	const STATE_OPTION    = 'bdz_etsy_job';
	const CATALOG_OPTION  = 'bdz_etsy_catalog';
	const PREVIOUS_OPTION = 'bdz_etsy_catalog_previous';
	const REPORT_OPTION   = 'bdz_etsy_report';

	/** Listings normalized per tick. Cheap: two API calls each. */
	const INVENTORY_BATCH = 6;

	/** Products imported per tick. Small: image sideloading dominates. */
	const PUSH_BATCH = 3;

	private $state;

	public function __construct() {
		$state = get_option( self::STATE_OPTION, array() );
		$this->state = is_array( $state ) ? $state : array();
	}

	public static function blank_state() {
		return array(
			'status'    => 'idle',
			'phase'     => '',
			'cursor'    => 0,
			'total'     => 0,
			'started'   => 0,
			'finished'  => 0,
			'dry_run'   => false,
			'message'   => '',
			'shop'      => array(),
			'sections'  => array(),
			'listings'  => array(),
			'stats'     => array(
				'created' => 0,
				'updated' => 0,
				'skipped' => 0,
				'failed'  => 0,
				'review'  => 0,
			),
		);
	}

	public function state() {
		return array_merge( self::blank_state(), $this->state );
	}

	public function is_running() {
		$state = $this->state();
		return 'running' === $state['status'];
	}

	private function save() {
		update_option( self::STATE_OPTION, $this->state, false );
	}

	public function start( $dry_run = false ) {
		if ( $this->is_running() ) {
			return new WP_Error( 'bdz_already_running', 'A sync is already running.' );
		}

		$this->state            = self::blank_state();
		$this->state['status']  = 'running';
		$this->state['phase']   = 'listings';
		$this->state['started'] = time();
		$this->state['dry_run'] = (bool) $dry_run;
		$this->state['message'] = 'Starting…';
		$this->save();

		// Deliberately not cleared: clearing destroyed the record of which Etsy
		// account connected, which is the first thing you want when a run
		// fails. The ring buffer caps growth on its own.
		BDZ_Etsy_Logger::add( '--- ' . ( $dry_run ? 'DRY RUN — nothing will be written to the store' : 'Sync run' ) . ' ---' );

		return true;
	}

	public function cancel() {
		$this->state['status']   = 'idle';
		$this->state['phase']    = '';
		$this->state['message']  = 'Cancelled.';
		$this->state['finished'] = time();
		$this->save();
		BDZ_Etsy_Logger::warn( 'Sync cancelled.' );
	}

	private function fail( $message ) {
		$this->state['status']   = 'error';
		$this->state['message']  = $message;
		$this->state['finished'] = time();
		$this->save();
		BDZ_Etsy_Logger::error( $message );
	}

	/**
	 * Run ticks until the time budget is spent or the job finishes.
	 */
	public function run_for( $seconds ) {
		$deadline = microtime( true ) + max( 5, (int) $seconds );

		while ( $this->is_running() && microtime( true ) < $deadline ) {
			$continue = $this->tick();
			if ( ! $continue ) {
				break;
			}
		}

		return $this->state();
	}

	/**
	 * One unit of work. Returns false when the job is finished or broken.
	 */
	public function tick() {
		if ( ! $this->is_running() ) {
			return false;
		}

		switch ( $this->state['phase'] ) {
			case 'listings':
				return $this->tick_listings();
			case 'inventory':
				return $this->tick_inventory();
			case 'push':
				return $this->tick_push();
			case 'finalize':
				return $this->tick_finalize();
			default:
				$this->fail( 'Unknown sync phase: ' . $this->state['phase'] );
				return false;
		}
	}

	private function tick_listings() {
		$client = new BDZ_Etsy_Client();

		$shop = $client->resolve_shop();
		if ( is_wp_error( $shop ) ) {
			$this->fail( $shop->get_error_message() );
			return false;
		}
		BDZ_Etsy_Logger::add( sprintf( 'Shop resolved: %s (%d).', $shop['shop_name'], $shop['shop_id'] ) );

		$sections = $client->sections( $shop['shop_id'] );
		if ( is_wp_error( $sections ) ) {
			BDZ_Etsy_Logger::warn( 'Could not read shop sections: ' . $sections->get_error_message() );
			$sections = array();
		}

		$listings = $client->active_listings( $shop['shop_id'] );
		if ( is_wp_error( $listings ) ) {
			$this->fail( $listings->get_error_message() );
			return false;
		}

		BDZ_Etsy_Logger::add( sprintf( 'Found %d active listing(s).', count( $listings ) ) );

		// Keep the last good catalogue so the run can report what changed.
		update_option( self::PREVIOUS_OPTION, get_option( self::CATALOG_OPTION, array() ), false );
		update_option( self::CATALOG_OPTION, array(), false );

		$this->state['shop']     = $shop;
		$this->state['sections'] = $sections;
		$this->state['listings'] = $listings;
		$this->state['total']    = count( $listings );
		$this->state['cursor']   = 0;
		$this->state['phase']    = 'inventory';
		$this->state['message']  = sprintf( 'Reading %d listing(s) from Etsy…', count( $listings ) );
		$this->save();

		return true;
	}

	private function tick_inventory() {
		$client   = new BDZ_Etsy_Client();
		$catalog  = get_option( self::CATALOG_OPTION, array() );
		$catalog  = is_array( $catalog ) ? $catalog : array();
		$listings = $this->state['listings'];
		$sections = $this->state['sections'];

		$processed = 0;
		while ( $processed < self::INVENTORY_BATCH && $this->state['cursor'] < count( $listings ) ) {
			$listing = $listings[ $this->state['cursor'] ];

			$images = isset( $listing['images'] ) ? $listing['images'] : array();
			if ( ! $images ) {
				$fetched = $client->listing_images( $listing['listing_id'] );
				$images  = is_wp_error( $fetched ) ? array() : $fetched;
			}

			$inventory = $client->listing_inventory( $listing['listing_id'] );
			if ( is_wp_error( $inventory ) ) {
				BDZ_Etsy_Logger::warn(
					sprintf( 'Inventory unavailable for listing %s: %s', $listing['listing_id'], $inventory->get_error_message() )
				);
				$inventory = null;
			}

			$item      = BDZ_Etsy_Normalize::listing( $listing, $images, $inventory, $sections );
			$catalog[] = $item;

			if ( $item['review'] ) {
				$this->state['stats']['review']++;
				BDZ_Etsy_Logger::warn( sprintf( 'REVIEW %s — %s', $item['sku'], implode( '; ', $item['review_reasons'] ) ) );
			}

			$this->state['cursor']++;
			$processed++;
		}

		update_option( self::CATALOG_OPTION, $catalog, false );

		$this->state['message'] = sprintf( 'Read %d of %d listing(s).', $this->state['cursor'], count( $listings ) );

		if ( $this->state['cursor'] >= count( $listings ) ) {
			$this->state['phase']    = 'push';
			$this->state['cursor']   = 0;
			$this->state['listings'] = array(); // free the raw payloads
			$this->state['message']  = 'Importing products…';
		}

		$this->save();
		return true;
	}

	private function tick_push() {
		$catalog = get_option( self::CATALOG_OPTION, array() );
		$catalog = is_array( $catalog ) ? $catalog : array();
		$total   = count( $catalog );

		$importer   = new BDZ_Etsy_Importer( $this->state['dry_run'] );
		$skip_review = BDZ_Etsy_Settings::get_bool( 'skip_review' );

		$processed = 0;
		while ( $processed < self::PUSH_BATCH && $this->state['cursor'] < $total ) {
			$item = $catalog[ $this->state['cursor'] ];

			if ( $skip_review && ! empty( $item['review'] ) ) {
				$this->state['stats']['skipped']++;
				BDZ_Etsy_Logger::add( sprintf( 'Skipped %s (flagged for review).', $item['sku'] ) );
			} else {
				$result = $importer->import_item( $item );

				switch ( $result['action'] ) {
					case 'created':
						$this->state['stats']['created']++;
						BDZ_Etsy_Logger::ok( $result['message'] );
						break;
					case 'updated':
						$this->state['stats']['updated']++;
						BDZ_Etsy_Logger::add( $result['message'] );
						break;
					case 'skipped':
						$this->state['stats']['skipped']++;
						BDZ_Etsy_Logger::warn( $result['message'] );
						break;
					default:
						$this->state['stats']['failed']++;
						BDZ_Etsy_Logger::error( $result['message'] );
				}
			}

			$this->state['cursor']++;
			$processed++;
		}

		$this->state['message'] = sprintf( 'Imported %d of %d.', $this->state['cursor'], $total );

		if ( $this->state['cursor'] >= $total ) {
			$this->state['phase']   = 'finalize';
			$this->state['message'] = 'Finishing up…';
		}

		$this->save();
		return true;
	}

	private function tick_finalize() {
		$catalog = get_option( self::CATALOG_OPTION, array() );
		$catalog = is_array( $catalog ) ? $catalog : array();

		$drafted = array();
		if ( ! $this->state['dry_run'] && BDZ_Etsy_Settings::get_bool( 'draft_missing' ) ) {
			$skus = array();
			foreach ( $catalog as $item ) {
				$skus[] = $item['sku'];
			}
			$drafted = BDZ_Etsy_Importer::draft_missing( $skus );
		}

		$previous = get_option( self::PREVIOUS_OPTION, array() );
		$changes  = self::diff( is_array( $previous ) ? $previous : array(), $catalog );

		$report = array(
			'finished_at' => time(),
			'dry_run'     => $this->state['dry_run'],
			'count'       => count( $catalog ),
			'stats'       => $this->state['stats'],
			'drafted'     => $drafted,
			'changes'     => $changes,
			'shop'        => $this->state['shop'],
		);
		update_option( self::REPORT_OPTION, $report, false );

		foreach ( $changes['added'] as $entry ) {
			BDZ_Etsy_Logger::add( sprintf( 'NEW on Etsy: %s — %s', $entry['sku'], $entry['title'] ) );
		}
		foreach ( $changes['removed'] as $entry ) {
			BDZ_Etsy_Logger::add( sprintf( 'GONE from Etsy: %s — %s', $entry['sku'], $entry['title'] ) );
		}
		foreach ( $changes['modified'] as $entry ) {
			BDZ_Etsy_Logger::add( sprintf( 'CHANGED %s — %s', $entry['sku'], implode( ', ', $entry['changes'] ) ) );
		}

		$stats = $this->state['stats'];
		BDZ_Etsy_Logger::ok(
			sprintf(
				'Done. created=%d updated=%d skipped=%d failed=%d%s',
				$stats['created'],
				$stats['updated'],
				$stats['skipped'],
				$stats['failed'],
				$this->state['dry_run'] ? ' (dry run — nothing was written)' : ''
			)
		);

		$this->state['status']   = 'done';
		$this->state['phase']    = '';
		$this->state['finished'] = time();
		$this->state['message']  = 'Finished.';
		$this->save();

		return false;
	}

	/**
	 * What changed on Etsy since the previous run. Pure local comparison —
	 * costs no API calls.
	 */
	public static function diff( array $previous, array $current ) {
		$before = array();
		foreach ( $previous as $item ) {
			$before[ $item['sku'] ] = $item;
		}
		$after = array();
		foreach ( $current as $item ) {
			$after[ $item['sku'] ] = $item;
		}

		$added    = array();
		$removed  = array();
		$modified = array();

		foreach ( $after as $sku => $item ) {
			if ( ! isset( $before[ $sku ] ) ) {
				$added[] = array( 'sku' => $sku, 'title' => $item['title'] );
			}
		}
		foreach ( $before as $sku => $item ) {
			if ( ! isset( $after[ $sku ] ) ) {
				$removed[] = array( 'sku' => $sku, 'title' => $item['title'] );
			}
		}
		foreach ( $after as $sku => $item ) {
			if ( ! isset( $before[ $sku ] ) ) {
				continue;
			}
			$old     = $before[ $sku ];
			$changes = array();

			foreach ( array( 'price' => 'price', 'quantity' => 'stock', 'title' => 'title', 'section' => 'section' ) as $field => $label ) {
				$old_value = isset( $old[ $field ] ) ? $old[ $field ] : null;
				$new_value = isset( $item[ $field ] ) ? $item[ $field ] : null;
				if ( $old_value !== $new_value ) {
					$changes[] = sprintf( '%s: %s -> %s', $label, (string) $old_value, (string) $new_value );
				}
			}

			if ( BDZ_Etsy_Normalize::image_signature( $old ) !== BDZ_Etsy_Normalize::image_signature( $item ) ) {
				$changes[] = sprintf( 'images: %d -> %d', count( $old['images'] ), count( $item['images'] ) );
			}

			if ( $changes ) {
				$modified[] = array( 'sku' => $sku, 'title' => $item['title'], 'changes' => $changes );
			}
		}

		return array(
			'added'     => $added,
			'removed'   => $removed,
			'modified'  => $modified,
			'unchanged' => count( array_intersect_key( $after, $before ) ) - count( $modified ),
		);
	}

	public static function last_report() {
		$report = get_option( self::REPORT_OPTION, array() );
		return is_array( $report ) ? $report : array();
	}
}
