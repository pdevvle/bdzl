<?php
/**
 * Settings and credential storage.
 *
 * Secrets can live in wp-config.php instead of the database. If the constant
 * is defined it wins and the field becomes read-only in the admin screen —
 * that is the recommended posture, because a database dump then carries no
 * usable credentials.
 *
 *     define( 'BDZ_ETSY_KEYSTRING', '...' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BDZ_Etsy_Settings {

	const OPTION = 'bdz_etsy_settings';

	/**
	 * Multi-kit listings, as "<listing id> = <kits in the box>".
	 *
	 * Every one of these was found two independent ways — a title naming more
	 * than one kit, and a top variant price far above a single kit — and the
	 * count then checked against price per kit.
	 */
	const DEFAULT_KIT_COUNTS = "4463553748 = 8   # Throne of Glass 8 Bedazzle Kit bundle\n4550005746 = 8   # Throne of Glass HARDCOVER set\n4569859586 = 7   # Dungeon Crawler Carl Spine Only (full set)\n4551858837 = 6   # Red Rising Series\n4302308611 = 5   # ACOTAR all five\n4300717084 = 5   # ACOTAR all five B&N Editions\n4485449813 = 5   # ACOTAR HARDCOVER\n1853002208 = 3   # All 3 Fourth Wing Hardcover\n4385844332 = 3   # OUABH UK (gem options only, no book variant)";

	/** Settings that may be overridden by a wp-config.php constant. */
	const CONSTANTS = array(
		'keystring'     => 'BDZ_ETSY_KEYSTRING',
		'shared_secret' => 'BDZ_ETSY_SHARED_SECRET',
		'shop_name'     => 'BDZ_ETSY_SHOP_NAME',
		'shop_id'       => 'BDZ_ETSY_SHOP_ID',
	);

	public static function defaults() {
		return array(
			'keystring'      => '',
			'shared_secret'  => '',
			'shop_name'      => '',
			'shop_id'        => '',
			'redirect_uri'   => '',
			'stock_buffer'   => 0,
			'new_status'     => 'draft',
			'sync_enabled'   => 0,
			'sync_images'    => 1,
			'skip_review'    => 0,
			'draft_missing'  => 1,
			// Shipping weights in the store's own unit (oz here). 22 oz is the
			// kit; 80 oz is 5 lb, the kit with the book in the box.
			'kit_weight'           => 22,
			'kit_with_book_weight' => 80,
			'force_weights'        => 0,
			// Listings holding more than one kit. The configured kit weights are
			// per kit, so a bundle multiplies them: five kits and five books is
			// not one of each. Counts come from the listing titles and were
			// cross-checked against price per kit.
			'bundle_kit_counts'    => self::DEFAULT_KIT_COUNTS,
		);
	}

	/**
	 * The value Etsy wants in the x-api-key header.
	 *
	 * This is NOT the OAuth client_id. The client_id is the keystring and PKCE
	 * needs no secret, which is why connecting can succeed while every API call
	 * still 403s.
	 *
	 * Etsy rejects the keystring alone with "Shared secret is required in
	 * x-api-key header", and the secret alone with "API key not found or not
	 * active, or incorrect shared secret for API key" — the second wording says
	 * it is looking for a key and a secret and matching them against each
	 * other. So when both are configured they are sent colon-joined. Test
	 * connection tries the alternatives and reports which Etsy accepts.
	 */
	public static function api_key() {
		$keystring = self::get( 'keystring' );
		$secret    = self::get( 'shared_secret' );

		if ( $keystring && $secret ) {
			return $keystring . ':' . $secret;
		}
		return $secret ? $secret : $keystring;
	}

	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * A wp-config constant always beats the stored value.
	 */
	public static function get( $key, $default = null ) {
		if ( isset( self::CONSTANTS[ $key ] ) && defined( self::CONSTANTS[ $key ] ) ) {
			return constant( self::CONSTANTS[ $key ] );
		}
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public static function get_bool( $key ) {
		return (bool) self::get( $key );
	}

	public static function get_int( $key ) {
		return (int) self::get( $key );
	}

	public static function is_locked( $key ) {
		return isset( self::CONSTANTS[ $key ] ) && defined( self::CONSTANTS[ $key ] );
	}

	public static function update( array $values ) {
		$current = self::all();
		$clean   = array();

		foreach ( self::defaults() as $key => $default ) {
			if ( self::is_locked( $key ) ) {
				// Never persist something a constant already governs.
				$clean[ $key ] = '';
				continue;
			}
			if ( ! array_key_exists( $key, $values ) ) {
				$clean[ $key ] = $current[ $key ];
				continue;
			}
			$clean[ $key ] = self::sanitize( $key, $values[ $key ] );
		}

		// autoload = false: this is not needed on every front-end request.
		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	private static function sanitize( $key, $value ) {
		switch ( $key ) {
			case 'stock_buffer':
				return max( 0, (int) $value );
			case 'new_status':
				$allowed = array( 'draft', 'publish', 'pending', 'private' );
				$value   = sanitize_text_field( $value );
				return in_array( $value, $allowed, true ) ? $value : 'draft';
			case 'sync_enabled':
			case 'sync_images':
			case 'skip_review':
			case 'draft_missing':
			case 'force_weights':
				return $value ? 1 : 0;
			case 'kit_weight':
			case 'kit_with_book_weight':
				// Zero is meaningful: it means "use whatever Etsy reported".
				$number = is_numeric( $value ) ? (float) $value : 0.0;
				return max( 0.0, round( $number, 3 ) );
			case 'redirect_uri':
				return esc_url_raw( trim( (string) $value ) );
			case 'bundle_kit_counts':
				return sanitize_textarea_field( (string) $value );
			default:
				return sanitize_text_field( trim( (string) $value ) );
		}
	}

	/**
	 * Parsed multi-kit counts: listing id => kits in the box.
	 *
	 * Lenient on purpose — "id = n", "id: n" and "id n" all parse, blank lines
	 * and # comments are ignored — because this is hand-maintained.
	 */
	public static function kit_counts() {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) self::get( 'bundle_kit_counts' ) ) as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( ! preg_match( '/^(\d+)\s*[=:,]?\s*(\d+)\s*$/', $line, $m ) ) {
				continue;
			}
			$count = (int) $m[2];
			if ( $count > 0 ) {
				$out[ $m[1] ] = $count;
			}
		}
		return $out;
	}

	/**
	 * The OAuth callback. Defaults to this plugin's own admin screen, which is
	 * a real HTTPS URL — the whole reason running on-site beats a laptop.
	 */
	public static function redirect_uri() {
		$configured = self::get( 'redirect_uri' );
		if ( $configured ) {
			return $configured;
		}
		return admin_url( 'admin.php?page=bdz-etsy-sync' );
	}
}
