<?php
/**
 * Thin Etsy Open API v3 client.
 *
 * Every request carries both the app keystring (x-api-key) and the OAuth
 * bearer token — Etsy wants both, and sending only one produces a 403 that
 * looks like an approval problem.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BDZ_Etsy_Client {

	const API_BASE = 'https://openapi.etsy.com/v3/application';

	private $keystring;
	private $token;

	public function __construct() {
		$this->keystring = BDZ_Etsy_Settings::get( 'keystring' );
	}

	private function token( $force_refresh = false ) {
		if ( null === $this->token || $force_refresh ) {
			$token = BDZ_Etsy_OAuth::access_token( $force_refresh );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$this->token = $token;
		}
		return $this->token;
	}

	/**
	 * GET a path under the application base. Retries once on 401 by refreshing
	 * the access token — a long import can outlive its one-hour token.
	 */
	public function get( $path, array $params = array(), $retried = false ) {
		if ( ! $this->keystring ) {
			return new WP_Error( 'bdz_no_keystring', 'The Etsy keystring is not set.' );
		}

		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = self::API_BASE . $path;
		if ( $params ) {
			$url = add_query_arg( array_map( 'rawurlencode', $params ), $url );
		}

		$response = $this->request_with_retries( $url, $token );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 401 === $code && ! $retried ) {
			BDZ_Etsy_Logger::warn( 'Etsy rejected the access token (401) — refreshing and retrying.' );
			$refreshed = $this->token( true );
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
			return $this->get( $path, $params, true );
		}

		if ( 401 === $code ) {
			return new WP_Error( 'bdz_etsy_401', 'Etsy returned 401. The token is expired or lacks the listings_r scope — reconnect.' );
		}

		if ( 403 === $code ) {
			return new WP_Error(
				'bdz_etsy_403',
				sprintf(
					'Etsy returned 403 for %s. Usually the app is not yet approved for the Open API v3, or the token does not own this shop. Etsy said: %s',
					$path,
					$this->body_excerpt( $response )
				)
			);
		}

		if ( 404 === $code ) {
			return null;
		}

		if ( $code >= 400 ) {
			return new WP_Error(
				'bdz_etsy_http',
				sprintf( 'Etsy GET %s failed: HTTP %d %s', $path, $code, $this->body_excerpt( $response ) )
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( null === $decoded ) {
			return new WP_Error( 'bdz_etsy_json', sprintf( 'Etsy GET %s returned a non-JSON body.', $path ) );
		}

		return $decoded;
	}

	/**
	 * Etsy explains itself in the response body — "app not approved", a bad
	 * shop id, a missing scope. Discarding it turns a specific failure into a
	 * guess, so surface it.
	 */
	private function body_excerpt( $response ) {
		$body = trim( (string) wp_remote_retrieve_body( $response ) );
		if ( '' === $body ) {
			return '(empty response body)';
		}
		$decoded = json_decode( $body, true );
		if ( is_array( $decoded ) ) {
			foreach ( array( 'error_description', 'error', 'message' ) as $key ) {
				if ( ! empty( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ) {
					return $decoded[ $key ];
				}
			}
		}
		return substr( $body, 0, 300 );
	}

	/**
	 * Transport-level retries for rate limiting and upstream wobble. Etsy
	 * allows 10 requests a second; a 52-listing shop is not in a hurry.
	 */
	private function request_with_retries( $url, $token ) {
		$attempts = 3;
		$delay    = 2;

		for ( $attempt = 0; $attempt < $attempts; $attempt++ ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 30,
					'headers' => array(
						'x-api-key'     => $this->keystring,
						'Authorization' => 'Bearer ' . $token,
						'Accept'        => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				if ( $attempt === $attempts - 1 ) {
					return new WP_Error( 'bdz_etsy_transport', 'Could not reach Etsy: ' . $response->get_error_message() );
				}
				sleep( $delay );
				$delay *= 2;
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( 429 === $code || $code >= 500 ) {
				if ( $attempt === $attempts - 1 ) {
					return $response;
				}
				BDZ_Etsy_Logger::warn( sprintf( 'Etsy returned HTTP %d — retrying in %ds.', $code, $delay ) );
				sleep( $delay );
				$delay *= 2;
				continue;
			}

			return $response;
		}

		return new WP_Error( 'bdz_etsy_retries', 'Exhausted retries talking to Etsy.' );
	}

	/** Cheap connectivity probe; needs only the keystring to be valid. */
	public function ping() {
		return $this->get( '/openapi-ping' );
	}

	/**
	 * Resolve the shop: explicit id wins, then name, then whatever shop the
	 * consenting user owns.
	 */
	public function resolve_shop() {
		$shop_id   = BDZ_Etsy_Settings::get( 'shop_id' );
		$shop_name = BDZ_Etsy_Settings::get( 'shop_name' );

		if ( $shop_id ) {
			$data = $this->get( '/shops/' . rawurlencode( $shop_id ) );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			if ( ! $data ) {
				return new WP_Error( 'bdz_no_shop', sprintf( 'Etsy shop id %s was not found.', $shop_id ) );
			}
			return array( 'shop_id' => (int) $data['shop_id'], 'shop_name' => isset( $data['shop_name'] ) ? $data['shop_name'] : '' );
		}

		if ( $shop_name ) {
			$data = $this->get( '/shops', array( 'shop_name' => $shop_name, 'limit' => 25 ) );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$results = isset( $data['results'] ) ? $data['results'] : array();
			foreach ( $results as $shop ) {
				if ( strtolower( $shop['shop_name'] ) === strtolower( $shop_name ) ) {
					return array( 'shop_id' => (int) $shop['shop_id'], 'shop_name' => $shop['shop_name'] );
				}
			}
			if ( $results ) {
				BDZ_Etsy_Logger::warn( sprintf( 'No exact match for shop name "%s"; using "%s".', $shop_name, $results[0]['shop_name'] ) );
				return array( 'shop_id' => (int) $results[0]['shop_id'], 'shop_name' => $results[0]['shop_name'] );
			}
			return new WP_Error( 'bdz_no_shop', sprintf( 'No Etsy shop found named "%s".', $shop_name ) );
		}

		$me = $this->get( '/users/me' );
		if ( is_wp_error( $me ) ) {
			return $me;
		}
		if ( ! empty( $me['shop_id'] ) ) {
			$shop = $this->get( '/shops/' . rawurlencode( $me['shop_id'] ) );
			return array(
				'shop_id'   => (int) $me['shop_id'],
				'shop_name' => ( ! is_wp_error( $shop ) && isset( $shop['shop_name'] ) ) ? $shop['shop_name'] : '',
			);
		}

		return new WP_Error(
			'bdz_no_shop',
			'Could not determine the Etsy shop. The connected account may not own one — check that the shop owner, not an admin, approved the consent screen. Otherwise set the shop name or id.'
		);
	}

	/** shop_section_id => title. These become product categories. */
	public function sections( $shop_id ) {
		$data = $this->get( '/shops/' . rawurlencode( $shop_id ) . '/sections' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$sections = array();
		foreach ( ( isset( $data['results'] ) ? $data['results'] : array() ) as $section ) {
			if ( ! empty( $section['shop_section_id'] ) && ! empty( $section['title'] ) ) {
				$sections[ (int) $section['shop_section_id'] ] = trim( $section['title'] );
			}
		}
		return $sections;
	}

	/** All active listings, paged. */
	public function active_listings( $shop_id ) {
		$listings  = array();
		$offset    = 0;
		$page_size = 100;

		do {
			$data = $this->get(
				'/shops/' . rawurlencode( $shop_id ) . '/listings',
				array(
					'state'    => 'active',
					'limit'    => $page_size,
					'offset'   => $offset,
					'includes' => 'Images',
				)
			);
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$results  = isset( $data['results'] ) ? $data['results'] : array();
			$listings = array_merge( $listings, $results );
			$offset  += $page_size;
		} while ( count( $results ) === $page_size );

		return $listings;
	}

	public function listing_images( $listing_id ) {
		$data = $this->get( '/listings/' . rawurlencode( $listing_id ) . '/images' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return isset( $data['results'] ) ? $data['results'] : array();
	}

	public function listing_inventory( $listing_id ) {
		return $this->get( '/listings/' . rawurlencode( $listing_id ) . '/inventory' );
	}
}
