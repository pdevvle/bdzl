<?php
/**
 * Etsy OAuth 2.0 with PKCE, and the token store it refreshes against.
 *
 * Etsy access tokens last one hour; refresh tokens last ninety days. So the
 * shop owner consents once in a browser and this keeps itself alive after
 * that, refreshing on demand — including part-way through a long import.
 *
 * The app's shared secret is not used anywhere in this flow. PKCE authenticates
 * with the code verifier instead, so that secret never needs to be stored here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BDZ_Etsy_OAuth {

	const CONNECT_URL = 'https://www.etsy.com/oauth/connect';
	const TOKEN_URL   = 'https://api.etsy.com/v3/public/oauth/token';

	const TOKEN_OPTION   = 'bdz_etsy_tokens';
	const PENDING_OPTION = 'bdz_etsy_oauth_pending';

	const SCOPE = 'listings_r';

	/** Etsy does not report refresh expiry. Documented lifetime is 90 days. */
	const REFRESH_LIFETIME = 7776000;

	/** Refresh a little early rather than racing the clock mid-import. */
	const EXPIRY_MARGIN = 120;

	public static function tokens() {
		$stored = get_option( self::TOKEN_OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	public static function is_connected() {
		$tokens = self::tokens();
		return ! empty( $tokens['access_token'] ) && ! empty( $tokens['refresh_token'] );
	}

	public static function disconnect() {
		delete_option( self::TOKEN_OPTION );
		delete_option( self::PENDING_OPTION );
		BDZ_Etsy_Logger::add( 'Disconnected from Etsy; stored tokens removed.' );
	}

	/**
	 * Build the consent URL and remember the PKCE verifier for the round trip.
	 */
	public static function authorize_url() {
		$keystring = BDZ_Etsy_Settings::get( 'keystring' );
		if ( ! $keystring ) {
			return new WP_Error( 'bdz_no_keystring', 'Set the Etsy keystring before connecting.' );
		}

		$verifier  = self::base64url( random_bytes( 32 ) );
		$challenge = self::base64url( hash( 'sha256', $verifier, true ) );
		$state     = self::base64url( random_bytes( 16 ) );

		update_option(
			self::PENDING_OPTION,
			array(
				'verifier'     => $verifier,
				'state'        => $state,
				'redirect_uri' => BDZ_Etsy_Settings::redirect_uri(),
				'created'      => time(),
			),
			false
		);

		return add_query_arg(
			array(
				'response_type'         => 'code',
				'client_id'             => rawurlencode( $keystring ),
				'redirect_uri'          => rawurlencode( BDZ_Etsy_Settings::redirect_uri() ),
				'scope'                 => rawurlencode( self::SCOPE ),
				'state'                 => rawurlencode( $state ),
				'code_challenge'        => rawurlencode( $challenge ),
				'code_challenge_method' => 'S256',
			),
			self::CONNECT_URL
		);
	}

	/**
	 * Handle Etsy redirecting back with ?code=&state=.
	 */
	public static function handle_callback( $code, $state ) {
		$pending = get_option( self::PENDING_OPTION, array() );
		delete_option( self::PENDING_OPTION );

		if ( empty( $pending['verifier'] ) || empty( $pending['state'] ) ) {
			return new WP_Error( 'bdz_no_pending', 'No authorization was in progress. Start again from Connect to Etsy.' );
		}

		if ( ! hash_equals( (string) $pending['state'], (string) $state ) ) {
			return new WP_Error( 'bdz_state_mismatch', 'The state value did not match. Discarding this response and changing nothing.' );
		}

		$response = self::post_token(
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => BDZ_Etsy_Settings::get( 'keystring' ),
				'redirect_uri'  => $pending['redirect_uri'],
				'code'          => $code,
				'code_verifier' => $pending['verifier'],
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$record = self::store( $response );
		BDZ_Etsy_Logger::ok( sprintf( 'Connected to Etsy as user %s.', $record['etsy_user_id'] ? $record['etsy_user_id'] : 'unknown' ) );
		return $record;
	}

	/**
	 * A live access token, refreshed if it is expired or nearly so.
	 */
	public static function access_token( $force_refresh = false ) {
		$tokens = self::tokens();
		if ( empty( $tokens['access_token'] ) ) {
			return new WP_Error( 'bdz_not_connected', 'Not connected to Etsy. Use Connect to Etsy first.' );
		}

		$expires_at = isset( $tokens['expires_at'] ) ? (float) $tokens['expires_at'] : 0;
		if ( ! $force_refresh && time() < ( $expires_at - self::EXPIRY_MARGIN ) ) {
			return $tokens['access_token'];
		}

		if ( empty( $tokens['refresh_token'] ) ) {
			return new WP_Error( 'bdz_no_refresh', 'The Etsy token has expired and there is no refresh token. Reconnect.' );
		}

		if ( ! empty( $tokens['refresh_expires_at'] ) && time() > (float) $tokens['refresh_expires_at'] ) {
			return new WP_Error( 'bdz_refresh_expired', 'The Etsy refresh token is older than 90 days. The shop owner needs to reconnect.' );
		}

		$response = self::post_token(
			array(
				'grant_type'    => 'refresh_token',
				'client_id'     => BDZ_Etsy_Settings::get( 'keystring' ),
				'refresh_token' => $tokens['refresh_token'],
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$record = self::store( $response, $tokens );
		return $record['access_token'];
	}

	private static function store( array $payload, array $previous = array() ) {
		$expires_in = isset( $payload['expires_in'] ) ? (float) $payload['expires_in'] : 3600;

		$record = array(
			'access_token'  => $payload['access_token'],
			'refresh_token' => ! empty( $payload['refresh_token'] )
				? $payload['refresh_token']
				: ( isset( $previous['refresh_token'] ) ? $previous['refresh_token'] : '' ),
			'token_type'    => isset( $payload['token_type'] ) ? $payload['token_type'] : 'Bearer',
			'expires_at'    => time() + $expires_in,
			'obtained_at'   => time(),
			'scope'         => self::SCOPE,
		);

		// A fresh refresh token restarts the 90-day clock; a reused one does not.
		if ( ! empty( $payload['refresh_token'] ) ) {
			$record['refresh_expires_at'] = time() + self::REFRESH_LIFETIME;
		} elseif ( isset( $previous['refresh_expires_at'] ) ) {
			$record['refresh_expires_at'] = $previous['refresh_expires_at'];
		}

		$record['etsy_user_id'] = self::user_id_from_token( $record['access_token'] );

		update_option( self::TOKEN_OPTION, $record, false );
		return $record;
	}

	private static function post_token( array $body ) {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'bdz_token_http', 'Could not reach Etsy: ' . $response->get_error_message() );
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'bdz_token_body', sprintf( 'Etsy returned a non-JSON token response (HTTP %d).', $code ) );
		}

		if ( $code >= 400 || empty( $decoded['access_token'] ) ) {
			$detail = isset( $decoded['error_description'] )
				? $decoded['error_description']
				: ( isset( $decoded['error'] ) ? $decoded['error'] : 'unknown error' );

			if ( isset( $decoded['error'] ) && 'invalid_grant' === $decoded['error'] ) {
				$detail .= ' — this usually means the redirect URI does not exactly match the one registered on the Etsy app, or the code was already used.';
			}

			return new WP_Error( 'bdz_token_rejected', 'Etsy rejected the token request: ' . $detail );
		}

		return $decoded;
	}

	/** Etsy tokens are "<user_id>.<random>" — useful for proving who consented. */
	public static function user_id_from_token( $token ) {
		if ( $token && false !== strpos( $token, '.' ) ) {
			$head = strtok( $token, '.' );
			if ( ctype_digit( $head ) ) {
				return $head;
			}
		}
		return '';
	}

	private static function base64url( $bytes ) {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
