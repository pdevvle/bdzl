<?php
/**
 * Plugin Name: Etsy Sync for WooCommerce
 * Description: Imports and keeps in sync the Etsy catalogue as WooCommerce products. Etsy stays the source of truth; nothing here ever deletes a product.
 * Version:     1.0.4
 * Requires PHP: 7.4
 * Author:      HarleysBooks
 * License:     GPL-2.0-or-later
 * Text Domain: bdz-etsy-sync
 *
 * GENERATED FILE — built from wp-plugin/bdz-etsy-sync/ by
 * wp-plugin/build-single-file.py. Edit the sources, not this file.
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

define( 'BDZ_ETSY_VERSION', '1.0.4' );
define( 'BDZ_ETSY_FILE', __FILE__ );
define( 'BDZ_ETSY_DIR', plugin_dir_path( __FILE__ ) );
define( 'BDZ_ETSY_URL', plugin_dir_url( __FILE__ ) );

/** Every product this plugin manages carries this SKU prefix. */
define( 'BDZ_ETSY_SKU_PREFIX', 'etsy-' );

/**
 * Admin CSS and JS, compiled in because a single-file plugin has no
 * assets directory. Generated — see build-single-file.py.
 */
class BDZ_Etsy_Assets {

	public static function css() {
		return <<<'BDZ_CSS_LITERAL'
/* Etsy Sync admin screen. Hand-written; no framework, no build step. */

.bdz-etsy .bdz-grid {
	display: grid;
	grid-template-columns: repeat( auto-fit, minmax( 22rem, 1fr ) );
	gap: 1rem;
	margin-bottom: 1rem;
}

.bdz-etsy .bdz-card {
	background: #fff;
	border: 1px solid #dcdcde;
	border-radius: 4px;
	padding: 1rem 1.25rem 1.25rem;
	margin-bottom: 1rem;
}

.bdz-etsy .bdz-card h2 {
	margin-top: 0;
	font-size: 1.05rem;
}

.bdz-etsy .bdz-card h3 {
	font-size: 0.95rem;
	margin-bottom: 0.25rem;
}

.bdz-etsy .bdz-checks {
	margin: 0 0 1rem;
	padding: 0;
	list-style: none;
}

.bdz-etsy .bdz-checks li {
	display: flex;
	align-items: flex-start;
	gap: 0.5rem;
	padding: 0.35rem 0;
	border-bottom: 1px solid #f0f0f1;
	line-height: 1.5;
}

.bdz-etsy .bdz-checks li:last-child {
	border-bottom: 0;
}

.bdz-etsy .bdz-badge {
	flex: 0 0 auto;
	font-size: 0.7rem;
	font-weight: 600;
	letter-spacing: 0.04em;
	padding: 0.1rem 0.4rem;
	border-radius: 3px;
	background: #f0f0f1;
	color: #50575e;
	min-width: 3.1rem;
	text-align: center;
}

.bdz-etsy .bdz-ok .bdz-badge {
	background: #edfaef;
	color: #00652c;
}

.bdz-etsy .bdz-warn .bdz-badge {
	background: #fcf3e3;
	color: #8a5700;
}

.bdz-etsy .bdz-fail .bdz-badge {
	background: #fcebea;
	color: #a30000;
}

.bdz-etsy .bdz-hint {
	color: #50575e;
	font-size: 0.85rem;
	margin: 0.5rem 0 0;
}

.bdz-etsy .bdz-hint code {
	display: inline-block;
	margin-top: 0.2rem;
	word-break: break-all;
}

.bdz-etsy .bdz-connect,
.bdz-etsy .bdz-run {
	margin: 0.75rem 0 0;
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
}

.bdz-etsy .bdz-cancel {
	margin-left: auto;
}

.bdz-etsy .bdz-progress {
	margin-top: 1rem;
}

.bdz-etsy .bdz-bar {
	height: 0.5rem;
	background: #f0f0f1;
	border-radius: 999px;
	overflow: hidden;
}

.bdz-etsy .bdz-bar span {
	display: block;
	height: 100%;
	width: 0;
	background: #2271b1;
	transition: width 0.3s ease;
}

.bdz-etsy .bdz-message {
	margin: 0.5rem 0 0;
	font-weight: 600;
}

.bdz-etsy .bdz-stats {
	margin: 0.15rem 0 0;
	color: #50575e;
	font-size: 0.85rem;
}

.bdz-etsy .bdz-log {
	max-height: 22rem;
	overflow: auto;
	background: #1d2327;
	color: #e6e6e6;
	padding: 0.75rem 1rem;
	border-radius: 4px;
	font-size: 0.8rem;
	line-height: 1.6;
	white-space: pre-wrap;
	word-break: break-word;
	margin: 0;
}

@media screen and ( max-width: 782px ) {
	.bdz-etsy .bdz-grid {
		grid-template-columns: 1fr;
	}
}
BDZ_CSS_LITERAL;
	}

	public static function js() {
		return <<<'BDZ_JS_LITERAL'
/**
 * Drives the sync from the admin screen.
 *
 * Each request runs a short, time-boxed slice of work on the server and comes
 * back with progress plus any new log lines. Polling in a chain like this (not
 * on a timer) means requests can never stack up on a slow server.
 *
 * Vanilla JS, no dependencies, no build step.
 */
( function () {
	'use strict';

	var progress = document.getElementById( 'bdz-progress' );
	var logBox = document.getElementById( 'bdz-log' );
	if ( ! progress || ! logBox ) {
		return;
	}

	var bar = progress.querySelector( '.bdz-bar span' );
	var message = progress.querySelector( '.bdz-message' );
	var statsBox = progress.querySelector( '.bdz-stats' );
	var since = logBox.textContent.trim() ? logBox.textContent.trim().split( '\n' ).length : 0;
	var stopped = false;

	function post( action ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', window.bdzEtsy.nonce );
		body.append( 'since', String( since ) );

		return fetch( window.bdzEtsy.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}
			return response.json();
		} );
	}

	function appendLog( lines ) {
		if ( ! lines || ! lines.length ) {
			return;
		}
		var text = '';
		lines.forEach( function ( line ) {
			text += '[' + line.level.toUpperCase() + '] ' + line.message + '\n';
		} );
		logBox.textContent += text;
		logBox.scrollTop = logBox.scrollHeight;
	}

	function paint( data ) {
		bar.style.width = ( data.percent || 0 ) + '%';
		message.textContent = data.message || '';

		if ( data.stats ) {
			statsBox.textContent =
				'created ' + data.stats.created +
				' · updated ' + data.stats.updated +
				' · skipped ' + data.stats.skipped +
				' · failed ' + data.stats.failed;
		}

		appendLog( data.log );
		if ( typeof data.next === 'number' ) {
			since = data.next;
		}
	}

	function pump() {
		if ( stopped ) {
			return;
		}

		post( 'bdz_etsy_tick' )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					throw new Error( 'unexpected response' );
				}
				paint( payload.data );

				if ( 'running' === payload.data.status ) {
					pump();
					return;
				}

				stopped = true;
				progress.setAttribute( 'data-running', '0' );

				if ( 'done' === payload.data.status ) {
					// Reload so the readiness panel and last-run summary refresh.
					window.setTimeout( function () {
						window.location.reload();
					}, 1200 );
				}
			} )
			.catch( function ( error ) {
				stopped = true;
				message.textContent = 'Lost contact with the server (' + error.message +
					'). The sync keeps running in the background — reload to check on it.';
			} );
	}

	if ( '1' === progress.getAttribute( 'data-running' ) ) {
		pump();
	}
} )();
BDZ_JS_LITERAL;
	}
}

/* ---- includes/class-bdz-logger.php ---- */

/**
 * A small ring-buffer log, kept in an option so the admin screen can show what
 * the last run actually did. Capped so it can never grow without bound.
 */

class BDZ_Etsy_Logger {

	const OPTION = 'bdz_etsy_log';
	const LIMIT  = 400;

	public static function add( $message, $level = 'info' ) {
		$lines = get_option( self::OPTION, array() );
		if ( ! is_array( $lines ) ) {
			$lines = array();
		}

		$lines[] = array(
			'time'    => time(),
			'level'   => in_array( $level, array( 'info', 'warn', 'error', 'ok' ), true ) ? $level : 'info',
			'message' => (string) $message,
		);

		if ( count( $lines ) > self::LIMIT ) {
			$lines = array_slice( $lines, -self::LIMIT );
		}

		update_option( self::OPTION, $lines, false );
	}

	public static function warn( $message ) {
		self::add( $message, 'warn' );
	}

	public static function error( $message ) {
		self::add( $message, 'error' );
	}

	public static function ok( $message ) {
		self::add( $message, 'ok' );
	}

	public static function all() {
		$lines = get_option( self::OPTION, array() );
		return is_array( $lines ) ? $lines : array();
	}

	/** Lines added after a given index — lets the admin page tail the log. */
	public static function since( $index ) {
		$lines = self::all();
		$index = max( 0, (int) $index );
		return array(
			'lines' => array_slice( $lines, $index ),
			'next'  => count( $lines ),
		);
	}

	public static function clear() {
		update_option( self::OPTION, array(), false );
	}
}

/* ---- includes/class-bdz-settings.php ---- */

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

class BDZ_Etsy_Settings {

	const OPTION = 'bdz_etsy_settings';

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
				return $value ? 1 : 0;
			case 'redirect_uri':
				return esc_url_raw( trim( (string) $value ) );
			default:
				return sanitize_text_field( trim( (string) $value ) );
		}
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

/* ---- includes/class-bdz-oauth.php ---- */

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

class BDZ_Etsy_OAuth {

	const CONNECT_URL = 'https://www.etsy.com/oauth/connect';
	const TOKEN_URL   = 'https://api.etsy.com/v3/public/oauth/token';

	const TOKEN_OPTION   = 'bdz_etsy_tokens';
	const PENDING_OPTION = 'bdz_etsy_oauth_pending';

	/**
	 * listings_r reads the listings and their inventory; shops_r is needed to
	 * resolve which shop the connected account owns (/users/me and the shop
	 * endpoints). Requesting only listings_r gets as far as a working API key
	 * and then fails with "Access token lacks scope for this request".
	 */
	const SCOPE = 'listings_r shops_r';

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

/* ---- includes/class-bdz-etsy-client.php ---- */

/**
 * Thin Etsy Open API v3 client.
 *
 * Every request carries both the app keystring (x-api-key) and the OAuth
 * bearer token — Etsy wants both, and sending only one produces a 403 that
 * looks like an approval problem.
 */

class BDZ_Etsy_Client {

	const API_BASE = 'https://openapi.etsy.com/v3/application';

	private $keystring;
	private $token;

	/**
	 * @param string|null $api_key_override Value to send as x-api-key instead
	 *                                      of the configured one. Used by the
	 *                                      connection test to determine which
	 *                                      credential Etsy actually accepts.
	 */
	public function __construct( $api_key_override = null ) {
		$this->keystring = $api_key_override ? $api_key_override : BDZ_Etsy_Settings::api_key();
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

/* ---- includes/class-bdz-normalize.php ---- */

/**
 * Etsy -> neutral catalogue item.
 *
 * This is the ONE place in the plugin where raw Etsy field names are allowed to
 * appear. If Etsy's v3 fields drift, fix them here and nowhere else — the same
 * rule the command-line importer follows, so the two cannot diverge.
 */

class BDZ_Etsy_Normalize {

	/**
	 * Etsy v3 returns money as { amount, divisor, currency_code }.
	 */
	public static function money( $value ) {
		if ( ! is_array( $value ) || ! isset( $value['amount'] ) ) {
			return array( null, null );
		}
		$divisor = ! empty( $value['divisor'] ) ? (float) $value['divisor'] : 100.0;
		if ( 0.0 === $divisor ) {
			return array( null, null );
		}
		return array(
			round( (float) $value['amount'] / $divisor, 2 ),
			isset( $value['currency_code'] ) ? $value['currency_code'] : null,
		);
	}

	/**
	 * @param array      $listing   Raw Etsy listing.
	 * @param array      $images    Raw Etsy listing images.
	 * @param array|null $inventory Raw Etsy inventory, or null.
	 * @param array      $sections  shop_section_id => title.
	 */
	public static function listing( array $listing, $images, $inventory, array $sections ) {
		$listing_id = (int) $listing['listing_id'];

		list( $price, $currency ) = self::money( isset( $listing['price'] ) ? $listing['price'] : null );

		$property_names = array();
		$offerings      = array();

		if ( is_array( $inventory ) && ! empty( $inventory['products'] ) ) {
			foreach ( $inventory['products'] as $product ) {
				foreach ( ( isset( $product['property_values'] ) ? $product['property_values'] : array() ) as $value ) {
					if ( ! empty( $value['property_name'] ) && ! in_array( $value['property_name'], $property_names, true ) ) {
						$property_names[] = $value['property_name'];
					}
				}
				foreach ( ( isset( $product['offerings'] ) ? $product['offerings'] : array() ) as $offering ) {
					list( $offer_price, $offer_currency ) = self::money( isset( $offering['price'] ) ? $offering['price'] : null );
					if ( null === $offer_price ) {
						continue;
					}
					$offerings[] = array(
						'price'    => $offer_price,
						'currency' => $offer_currency,
						'enabled'  => ! isset( $offering['is_enabled'] ) || $offering['is_enabled'],
					);
				}
			}
		}

		// Some price-on-property listings carry no listing-level price; fall
		// back to the cheapest enabled offering.
		if ( null === $price && $offerings ) {
			$enabled = array_filter(
				$offerings,
				function ( $offering ) {
					return $offering['enabled'];
				}
			);
			$pool = $enabled ? $enabled : $offerings;
			usort(
				$pool,
				function ( $a, $b ) {
					return $a['price'] <=> $b['price'];
				}
			);
			$price    = $pool[0]['price'];
			$currency = $currency ? $currency : $pool[0]['currency'];
		}

		$offering_count = 0;
		if ( is_array( $inventory ) && ! empty( $inventory['products'] ) ) {
			foreach ( $inventory['products'] as $product ) {
				if ( ! empty( $product['offerings'] ) ) {
					$offering_count++;
				}
			}
		}

		$price_on_property    = ( is_array( $inventory ) && ! empty( $inventory['price_on_property'] ) ) ? $inventory['price_on_property'] : array();
		$quantity_on_property = ( is_array( $inventory ) && ! empty( $inventory['quantity_on_property'] ) ) ? $inventory['quantity_on_property'] : array();

		$has_real_variations = ( $property_names && $offering_count > 1 );

		// The catalogue mirrors Etsy 1:1, so a listing with real variations is
		// still imported as a simple product — flagged, so consolidating it into
		// a variable product stays a deliberate later decision.
		$review_reasons = array();
		if ( $has_real_variations ) {
			$review_reasons[] = sprintf( 'listing has %d offerings across %s', $offering_count, implode( ', ', $property_names ) );
		}
		if ( $price_on_property ) {
			$review_reasons[] = 'price varies by property';
		}
		if ( $quantity_on_property ) {
			$review_reasons[] = 'quantity varies by property';
		}
		if ( null === $price ) {
			$review_reasons[] = 'no price could be resolved';
		}

		$normalized_images = array();
		$images            = is_array( $images ) ? $images : array();
		usort(
			$images,
			function ( $a, $b ) {
				$ra = isset( $a['rank'] ) ? (int) $a['rank'] : 0;
				$rb = isset( $b['rank'] ) ? (int) $b['rank'] : 0;
				return $ra <=> $rb;
			}
		);
		foreach ( $images as $image ) {
			$url = '';
			foreach ( array( 'url_fullxfull', 'url_570xN', 'url_170x135' ) as $key ) {
				if ( ! empty( $image[ $key ] ) ) {
					$url = $image[ $key ];
					break;
				}
			}
			if ( ! $url ) {
				continue;
			}
			$normalized_images[] = array(
				'etsy_image_id' => isset( $image['listing_image_id'] ) ? $image['listing_image_id'] : 0,
				'rank'          => isset( $image['rank'] ) ? (int) $image['rank'] : count( $normalized_images ) + 1,
				'url'           => $url,
				'alt'           => isset( $image['alt_text'] ) ? trim( $image['alt_text'] ) : '',
			);
		}
		if ( ! $normalized_images ) {
			$review_reasons[] = 'listing has no images';
		}

		$section_id    = isset( $listing['shop_section_id'] ) ? (int) $listing['shop_section_id'] : 0;
		$section_title = ( $section_id && isset( $sections[ $section_id ] ) ) ? $sections[ $section_id ] : '';

		$tags = array();
		foreach ( ( isset( $listing['tags'] ) ? $listing['tags'] : array() ) as $tag ) {
			$tag = trim( (string) $tag );
			if ( '' !== $tag ) {
				$tags[] = $tag;
			}
		}

		$materials = array();
		foreach ( ( isset( $listing['materials'] ) ? $listing['materials'] : array() ) as $material ) {
			$material = trim( (string) $material );
			if ( '' !== $material ) {
				$materials[] = $material;
			}
		}

		return array(
			'etsy_listing_id' => $listing_id,
			'sku'             => BDZ_ETSY_SKU_PREFIX . $listing_id,
			'title'           => isset( $listing['title'] ) ? trim( $listing['title'] ) : '',
			'description'     => isset( $listing['description'] ) ? $listing['description'] : '',
			'price'           => ( null !== $price ) ? number_format( $price, 2, '.', '' ) : '',
			'currency'        => $currency ? $currency : 'USD',
			'quantity'        => isset( $listing['quantity'] ) ? (int) $listing['quantity'] : 0,
			'url'             => isset( $listing['url'] ) ? $listing['url'] : '',
			'tags'            => $tags,
			'materials'       => $materials,
			'section'         => $section_title,
			'images'          => $normalized_images,
			'variations'      => array(
				'has_variations'  => ( ! empty( $listing['has_variations'] ) || $has_real_variations ),
				'offering_count'  => $offering_count,
				'property_names'  => $property_names,
			),
			'review'          => ! empty( $review_reasons ),
			'review_reasons'  => $review_reasons,
		);
	}

	/** Etsy descriptions are plain text; WooCommerce wants HTML. */
	public static function text_to_html( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}
		$blocks = preg_split( '/\n\s*\n/', $text );
		$out    = array();
		foreach ( $blocks as $block ) {
			$block = trim( $block );
			if ( '' === $block ) {
				continue;
			}
			// Not nl2br(): that keeps the newline after the <br />, which would
			// make this output differ byte-for-byte from the command-line
			// importer's for the same listing.
			$out[] = '<p>' . str_replace( "\n", '<br />', esc_html( $block ) ) . '</p>';
		}
		return implode( "\n", $out );
	}

	public static function first_paragraph( $text, $limit = 240 ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}
		$blocks = preg_split( '/\n\s*\n/', $text );
		$block  = trim( preg_replace( '/\s+/', ' ', $blocks[0] ) );
		if ( strlen( $block ) <= $limit ) {
			return $block;
		}
		$cut = substr( $block, 0, $limit );
		$cut = substr( $cut, 0, strrpos( $cut, ' ' ) ?: $limit );
		return rtrim( $cut, ",.;:- " ) . '…';
	}

	/** Stable fingerprint of an image set, so unchanged galleries are skipped. */
	public static function image_signature( array $item ) {
		$urls = array();
		foreach ( ( isset( $item['images'] ) ? $item['images'] : array() ) as $image ) {
			$urls[] = $image['url'];
		}
		return sha1( implode( '|', $urls ) );
	}
}

/* ---- includes/class-bdz-importer.php ---- */

/**
 * Neutral catalogue item -> WooCommerce product.
 *
 * The safety rules here are the same ones the command-line importer follows,
 * and they exist because this runs unattended:
 *
 *   - Products are matched by SKU (etsy-<listing_id>), so a re-run updates in
 *     place instead of creating a second copy.
 *   - A store product that is not simple is SKIPPED, never flattened. Pushing
 *     a simple product at a variable one orphans its variations.
 *   - Status is set on create only, so a product the owner unpublished stays
 *     unpublished.
 *   - Images are only re-imported when the Etsy image set actually changed.
 *   - Nothing is ever deleted.
 */

class BDZ_Etsy_Importer {

	const META_LISTING_ID = '_bdz_etsy_listing_id';
	const META_URL        = '_bdz_etsy_url';
	const META_SIGNATURE  = '_bdz_etsy_image_signature';
	const META_SYNCED_AT  = '_bdz_etsy_synced_at';
	const META_REVIEW     = '_bdz_etsy_review';

	private $stock_buffer;
	private $new_status;
	private $sync_images;
	private $dry_run;

	public function __construct( $dry_run = false ) {
		$this->stock_buffer = BDZ_Etsy_Settings::get_int( 'stock_buffer' );
		$this->new_status   = BDZ_Etsy_Settings::get( 'new_status' );
		$this->sync_images  = BDZ_Etsy_Settings::get_bool( 'sync_images' );
		$this->dry_run      = (bool) $dry_run;
	}

	/**
	 * Import one catalogue item.
	 *
	 * @return array { action: created|updated|skipped|failed, message: string }
	 */
	public function import_item( array $item ) {
		$sku   = $item['sku'];
		$title = $item['title'];

		if ( '' === $item['price'] ) {
			return $this->outcome( 'failed', sprintf( '%s has no price — skipped. %s', $sku, $title ) );
		}

		$product_id = wc_get_product_id_by_sku( $sku );
		$existing   = $product_id ? wc_get_product( $product_id ) : null;

		if ( $existing && 'simple' !== $existing->get_type() ) {
			return $this->outcome(
				'skipped',
				sprintf(
					'%s is a "%s" product in the store (#%d) — importing would convert it to simple and orphan its variations. Skipped.',
					$sku,
					$existing->get_type(),
					$product_id
				)
			);
		}

		if ( $this->dry_run ) {
			return $this->outcome(
				$existing ? 'updated' : 'created',
				sprintf( '%s would %s — %s', $sku, $existing ? 'update #' . $product_id : 'be created', $title )
			);
		}

		$product = $existing ? $existing : new WC_Product_Simple();
		$is_new  = ! $existing;

		$product->set_name( $title );
		$product->set_sku( $sku );
		$product->set_description( BDZ_Etsy_Normalize::text_to_html( $item['description'] ) );
		$product->set_short_description(
			BDZ_Etsy_Normalize::text_to_html( BDZ_Etsy_Normalize::first_paragraph( $item['description'] ) )
		);
		$product->set_regular_price( $item['price'] );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( max( 0, (int) $item['quantity'] - $this->stock_buffer ) );
		$product->set_backorders( 'no' );
		$product->set_catalog_visibility( 'visible' );

		// Only on create — an owner unpublishing something must stick.
		if ( $is_new ) {
			$product->set_status( $this->new_status );
		}

		$product_id = $product->save();
		if ( ! $product_id ) {
			return $this->outcome( 'failed', sprintf( '%s could not be saved.', $sku ) );
		}

		$this->apply_terms( $product_id, $item );

		$signature = BDZ_Etsy_Normalize::image_signature( $item );
		if ( $this->sync_images && $item['images'] ) {
			$stored = get_post_meta( $product_id, self::META_SIGNATURE, true );
			if ( $stored !== $signature || ! $product->get_image_id() ) {
				$this->apply_images( $product_id, $item );
				update_post_meta( $product_id, self::META_SIGNATURE, $signature );
			}
		}

		update_post_meta( $product_id, self::META_LISTING_ID, (string) $item['etsy_listing_id'] );
		update_post_meta( $product_id, self::META_URL, $item['url'] );
		update_post_meta( $product_id, self::META_SYNCED_AT, gmdate( 'c' ) );

		if ( ! empty( $item['review'] ) ) {
			update_post_meta( $product_id, self::META_REVIEW, implode( '; ', $item['review_reasons'] ) );
		} else {
			delete_post_meta( $product_id, self::META_REVIEW );
		}

		return $this->outcome(
			$is_new ? 'created' : 'updated',
			sprintf( '%s %s #%d — %s', $sku, $is_new ? 'created' : 'updated', $product_id, $title )
		);
	}

	private function outcome( $action, $message ) {
		return array( 'action' => $action, 'message' => $message );
	}

	/**
	 * Tags from Etsy tags, one category from the Etsy shop section. Terms are
	 * created on demand and reused thereafter.
	 */
	private function apply_terms( $product_id, array $item ) {
		if ( ! empty( $item['tags'] ) ) {
			$ids = $this->term_ids( $item['tags'], 'product_tag' );
			if ( $ids ) {
				wp_set_object_terms( $product_id, $ids, 'product_tag', false );
			}
		}

		if ( ! empty( $item['section'] ) ) {
			$ids = $this->term_ids( array( $item['section'] ), 'product_cat' );
			if ( $ids ) {
				wp_set_object_terms( $product_id, $ids, 'product_cat', false );
			}
		}
	}

	private function term_ids( array $names, $taxonomy ) {
		$ids = array();
		foreach ( $names as $name ) {
			$name = trim( $name );
			if ( '' === $name ) {
				continue;
			}
			$term = get_term_by( 'name', $name, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
				continue;
			}
			$created = wp_insert_term( $name, $taxonomy );
			if ( is_wp_error( $created ) ) {
				// Term already exists under a colliding slug: reuse it.
				$data = $created->get_error_data();
				if ( is_array( $data ) && isset( $data['term_id'] ) ) {
					$ids[] = (int) $data['term_id'];
				} elseif ( is_numeric( $data ) ) {
					$ids[] = (int) $data;
				} else {
					BDZ_Etsy_Logger::warn( sprintf( 'Could not create %s term "%s": %s', $taxonomy, $name, $created->get_error_message() ) );
				}
				continue;
			}
			$ids[] = (int) $created['term_id'];
		}
		return $ids;
	}

	/**
	 * Sideload the Etsy images into the media library. Slow, which is why the
	 * signature check above matters: without it every run re-downloads the
	 * whole gallery.
	 *
	 * Previously imported attachments are left in the media library rather than
	 * deleted — this plugin does not delete things.
	 */
	private function apply_images( $product_id, array $item ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_ids = array();

		foreach ( $item['images'] as $image ) {
			$alt = $image['alt'] ? $image['alt'] : $item['title'];
			$id  = media_sideload_image( $image['url'], $product_id, $alt, 'id' );

			if ( is_wp_error( $id ) ) {
				BDZ_Etsy_Logger::warn(
					sprintf( '%s: could not import image %s (%s)', $item['sku'], $image['url'], $id->get_error_message() )
				);
				continue;
			}

			$attachment_ids[] = (int) $id;
		}

		if ( ! $attachment_ids ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$product->set_image_id( array_shift( $attachment_ids ) );
		$product->set_gallery_image_ids( $attachment_ids );
		$product->save();
	}

	/**
	 * Products whose Etsy listing is no longer active are set to draft. They
	 * are never deleted — pulling something from sale is reversible.
	 *
	 * @return array SKUs drafted.
	 */
	public static function draft_missing( array $known_skus ) {
		$drafted = array();
		$known   = array_flip( $known_skus );

		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'pending', 'private' ),
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => self::META_LISTING_ID,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( $query->posts as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}
			$sku = $product->get_sku();
			if ( ! $sku || 0 !== strpos( $sku, BDZ_ETSY_SKU_PREFIX ) ) {
				continue;
			}
			if ( isset( $known[ $sku ] ) ) {
				continue;
			}

			$product->set_status( 'draft' );
			$product->save();
			$drafted[] = $sku;

			BDZ_Etsy_Logger::add(
				sprintf( 'Drafted #%d %s — the Etsy listing is no longer active.', $product_id, $sku )
			);
		}

		return $drafted;
	}

	/**
	 * Store products carrying no SKU. A first import can duplicate these,
	 * because there is nothing for the SKU match to find.
	 */
	public static function products_without_sku( $limit = 20 ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$found = array();
		foreach ( $query->posts as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}
			if ( '' === trim( (string) $product->get_sku() ) ) {
				$found[] = array(
					'id'   => $product_id,
					'name' => $product->get_name(),
					'type' => $product->get_type(),
				);
			}
		}
		return $found;
	}
}

/* ---- includes/class-bdz-job.php ---- */

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

/* ---- includes/class-bdz-admin.php ---- */

/**
 * Admin screen: credentials, connection, readiness, and the run controls.
 *
 * All writes go through a nonce and a manage_woocommerce capability check.
 * Secrets are never echoed back into the page — stored keys render as a masked
 * placeholder, and submitting the placeholder unchanged leaves them alone.
 */

class BDZ_Etsy_Admin {

	const PAGE  = 'bdz-etsy-sync';
	const NONCE = 'bdz_etsy_admin';
	const MASK  = '••••••••';

	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_post' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_oauth_callback' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		add_action( 'wp_ajax_bdz_etsy_tick', array( $this, 'ajax_tick' ) );
		add_action( 'wp_ajax_bdz_etsy_status', array( $this, 'ajax_status' ) );
	}

	public function capability() {
		return current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	public function menu() {
		add_submenu_page(
			'woocommerce',
			'Etsy Sync',
			'Etsy Sync',
			$this->capability(),
			self::PAGE,
			array( $this, 'render' )
		);
	}

	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		// The single-file build has no assets/ directory: build-single-file.py
		// compiles the CSS and JS into a BDZ_Etsy_Assets class instead. Its
		// presence is the signal for which form this plugin is running in.
		if ( class_exists( 'BDZ_Etsy_Assets' ) ) {
			wp_register_style( 'bdz-etsy-admin', false, array(), BDZ_ETSY_VERSION );
			wp_enqueue_style( 'bdz-etsy-admin' );
			wp_add_inline_style( 'bdz-etsy-admin', BDZ_Etsy_Assets::css() );

			wp_register_script( 'bdz-etsy-admin', false, array(), BDZ_ETSY_VERSION, true );
			wp_enqueue_script( 'bdz-etsy-admin' );
			wp_add_inline_script( 'bdz-etsy-admin', BDZ_Etsy_Assets::js() );
		} else {
			wp_enqueue_style( 'bdz-etsy-admin', BDZ_ETSY_URL . 'assets/admin.css', array(), BDZ_ETSY_VERSION );
			wp_enqueue_script( 'bdz-etsy-admin', BDZ_ETSY_URL . 'assets/admin.js', array(), BDZ_ETSY_VERSION, true );
		}
		wp_localize_script(
			'bdz-etsy-admin',
			'bdzEtsy',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
			)
		);
	}

	// ---------------------------------------------------------------- actions

	public function maybe_handle_post() {
		if ( empty( $_POST['bdz_etsy_action'] ) ) {
			return;
		}
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( 'You do not have permission to manage the Etsy sync.' );
		}
		check_admin_referer( self::NONCE );

		$action = sanitize_text_field( wp_unslash( $_POST['bdz_etsy_action'] ) );
		$notice = '';
		$type   = 'success';

		switch ( $action ) {
			case 'save':
				$values = array(
					'shop_name'     => isset( $_POST['shop_name'] ) ? wp_unslash( $_POST['shop_name'] ) : '',
					'shop_id'       => isset( $_POST['shop_id'] ) ? wp_unslash( $_POST['shop_id'] ) : '',
					'redirect_uri'  => isset( $_POST['redirect_uri'] ) ? wp_unslash( $_POST['redirect_uri'] ) : '',
					'stock_buffer'  => isset( $_POST['stock_buffer'] ) ? wp_unslash( $_POST['stock_buffer'] ) : 0,
					'new_status'    => isset( $_POST['new_status'] ) ? wp_unslash( $_POST['new_status'] ) : 'draft',
					'sync_enabled'  => ! empty( $_POST['sync_enabled'] ) ? 1 : 0,
					'sync_images'   => ! empty( $_POST['sync_images'] ) ? 1 : 0,
					'skip_review'   => ! empty( $_POST['skip_review'] ) ? 1 : 0,
					'draft_missing' => ! empty( $_POST['draft_missing'] ) ? 1 : 0,
				);

				// Only overwrite a stored credential if something other than the
				// mask was typed, so saving the form does not wipe it.
				foreach ( array( 'keystring', 'shared_secret' ) as $secret_key ) {
					$submitted = isset( $_POST[ $secret_key ] ) ? trim( wp_unslash( $_POST[ $secret_key ] ) ) : '';
					$values[ $secret_key ] = ( '' !== $submitted && self::MASK !== $submitted )
						? $submitted
						: BDZ_Etsy_Settings::all()[ $secret_key ];
				}

				BDZ_Etsy_Settings::update( $values );
				$notice = 'Settings saved.';
				break;

			case 'connect':
				$url = BDZ_Etsy_OAuth::authorize_url();
				if ( is_wp_error( $url ) ) {
					$notice = $url->get_error_message();
					$type   = 'error';
					break;
				}
				wp_redirect( $url );
				exit;

			case 'disconnect':
				BDZ_Etsy_OAuth::disconnect();
				$notice = 'Disconnected from Etsy.';
				break;

			case 'test':
				BDZ_Etsy_Logger::add( '--- Connection test ---' );

				$tokens = BDZ_Etsy_OAuth::tokens();
				BDZ_Etsy_Logger::add(
					'Connected Etsy user id: ' . ( ! empty( $tokens['etsy_user_id'] ) ? $tokens['etsy_user_id'] : 'unknown' )
				);

				// Which credential Etsy wants in x-api-key is not something to
				// guess at: the keystring alone is refused for missing the
				// secret, and the secret alone is refused for not matching a
				// key. Try every plausible arrangement and report which one
				// Etsy accepts.
				$keystring = BDZ_Etsy_Settings::get( 'keystring' );
				$secret    = BDZ_Etsy_Settings::get( 'shared_secret' );

				$candidates = array();
				if ( $keystring && $secret ) {
					$candidates['keystring:secret'] = $keystring . ':' . $secret;
					$candidates['secret:keystring'] = $secret . ':' . $keystring;
				}
				if ( $keystring ) {
					$candidates['keystring alone'] = $keystring;
				}
				if ( $secret ) {
					$candidates['secret alone'] = $secret;
				}

				if ( ! $candidates ) {
					BDZ_Etsy_Logger::error( 'No keystring or shared secret is configured.' );
					$notice = 'Nothing to test — set the credentials first.';
					break;
				}

				$working = null;
				foreach ( $candidates as $label => $value ) {
					$probe = new BDZ_Etsy_Client( $value );
					$ping  = $probe->ping();
					if ( is_wp_error( $ping ) ) {
						BDZ_Etsy_Logger::warn( sprintf( 'x-api-key = %s: %s', $label, $ping->get_error_message() ) );
						continue;
					}
					BDZ_Etsy_Logger::ok( sprintf( 'x-api-key = %s: ping OK — Etsy accepts this one.', $label ) );
					$working = $value;
					break;
				}

				if ( null === $working ) {
					BDZ_Etsy_Logger::error( 'Etsy accepted none of the credential arrangements. If both values are set and correct, the app itself is most likely still awaiting approval for the Open API v3.' );
					$notice = 'Connection test finished — see Activity below.';
					break;
				}

				$shop = ( new BDZ_Etsy_Client( $working ) )->resolve_shop();
				if ( is_wp_error( $shop ) ) {
					BDZ_Etsy_Logger::error( 'Shop lookup failed — ' . $shop->get_error_message() );
					BDZ_Etsy_Logger::add( 'The app credential works, so this is about the connected account or the configured shop name/id.' );
				} else {
					BDZ_Etsy_Logger::ok( sprintf( 'Shop resolved: %s (%d).', $shop['shop_name'], $shop['shop_id'] ) );
				}

				$notice = 'Connection test finished — see Activity below.';
				break;

			case 'run':
			case 'dry_run':
				$job    = new BDZ_Etsy_Job();
				$result = $job->start( 'dry_run' === $action );
				if ( is_wp_error( $result ) ) {
					$notice = $result->get_error_message();
					$type   = 'error';
					break;
				}
				$notice = ( 'dry_run' === $action )
					? 'Dry run started — nothing will be written to the store.'
					: 'Sync started.';
				break;

			case 'cancel':
				$job = new BDZ_Etsy_Job();
				$job->cancel();
				$notice = 'Sync cancelled.';
				break;
		}

		$args = array( 'page' => self::PAGE );
		if ( $notice ) {
			$args['bdz_notice'] = rawurlencode( $notice );
			$args['bdz_type']   = $type;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Etsy redirects back to this screen with ?code=&state=.
	 */
	public function maybe_handle_oauth_callback() {
		if ( ! isset( $_GET['page'] ) || self::PAGE !== $_GET['page'] ) {
			return;
		}
		if ( ! isset( $_GET['code'] ) || ! isset( $_GET['state'] ) ) {
			return;
		}
		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}

		$code  = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ) );

		$result = BDZ_Etsy_OAuth::handle_callback( $code, $state );

		$args = array( 'page' => self::PAGE );
		if ( is_wp_error( $result ) ) {
			$args['bdz_notice'] = rawurlencode( $result->get_error_message() );
			$args['bdz_type']   = 'error';
		} else {
			$args['bdz_notice'] = rawurlencode(
				sprintf(
					'Connected to Etsy as user %s. Check this is the shop owner\'s account.',
					$result['etsy_user_id'] ? $result['etsy_user_id'] : 'unknown'
				)
			);
			$args['bdz_type'] = 'success';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	// ------------------------------------------------------------------ ajax

	private function verify_ajax() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_send_json_error( array( 'message' => 'Not permitted.' ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	public function ajax_tick() {
		$this->verify_ajax();

		$job = new BDZ_Etsy_Job();
		if ( $job->is_running() ) {
			// Short budget: the browser is waiting on this request.
			$job->run_for( 12 );
		}

		$this->send_status( $job );
	}

	public function ajax_status() {
		$this->verify_ajax();
		$this->send_status( new BDZ_Etsy_Job() );
	}

	private function send_status( BDZ_Etsy_Job $job ) {
		$state = $job->state();
		$since = isset( $_POST['since'] ) ? (int) $_POST['since'] : 0;
		$log   = BDZ_Etsy_Logger::since( $since );

		$percent = 0;
		if ( $state['total'] > 0 ) {
			$base = ( 'push' === $state['phase'] || 'finalize' === $state['phase'] ) ? 50 : 0;
			$percent = (int) min( 100, $base + ( ( $state['cursor'] / max( 1, $state['total'] ) ) * 50 ) );
		}
		if ( 'done' === $state['status'] ) {
			$percent = 100;
		}

		wp_send_json_success(
			array(
				'status'  => $state['status'],
				'phase'   => $state['phase'],
				'message' => $state['message'],
				'stats'   => $state['stats'],
				'percent' => $percent,
				'log'     => $log['lines'],
				'next'    => $log['next'],
			)
		);
	}

	// ---------------------------------------------------------------- render

	private function readiness() {
		$rows = array();

		$keystring = BDZ_Etsy_Settings::get( 'keystring' );
		$rows[]    = $keystring
			? array( 'ok', 'Etsy keystring is set' . ( BDZ_Etsy_Settings::is_locked( 'keystring' ) ? ' (from wp-config.php)' : '' ) )
			: array( 'fail', 'Etsy keystring is not set' );

		if ( BDZ_Etsy_OAuth::is_connected() ) {
			$tokens  = BDZ_Etsy_OAuth::tokens();
			$refresh = isset( $tokens['refresh_expires_at'] ) ? (int) $tokens['refresh_expires_at'] - time() : 0;
			if ( $refresh > 0 ) {
				$rows[] = array(
					'ok',
					sprintf(
						'Connected as Etsy user %s — reconnect needed in %d day(s)',
						$tokens['etsy_user_id'] ? $tokens['etsy_user_id'] : 'unknown',
						(int) floor( $refresh / DAY_IN_SECONDS )
					),
				);

				// A token minted before the scope widened keeps the old, too
				// narrow grant. Etsy only says so at the point of use, so say
				// it here instead.
				$granted = isset( $tokens['scope'] ) ? (string) $tokens['scope'] : '';
				$missing = array_diff(
					explode( ' ', BDZ_Etsy_OAuth::SCOPE ),
					explode( ' ', $granted )
				);
				if ( $missing ) {
					$rows[] = array(
						'fail',
						sprintf(
							'This connection is missing the %s scope — disconnect and reconnect to grant it',
							implode( ', ', $missing )
						),
					);
				}
			} else {
				$rows[] = array( 'fail', 'The Etsy refresh token has expired — reconnect' );
			}
		} else {
			$rows[] = array( 'fail', 'Not connected to Etsy' );
		}

		$rows[] = bdz_etsy_requirements_met()
			? array( 'ok', 'WooCommerce is active' )
			: array( 'fail', 'WooCommerce is not active' );

		$no_sku = BDZ_Etsy_Importer::products_without_sku();
		if ( $no_sku ) {
			$names = array();
			foreach ( array_slice( $no_sku, 0, 5 ) as $product ) {
				$names[] = sprintf( '#%d %s (%s)', $product['id'], $product['name'], $product['type'] );
			}
			$rows[] = array(
				'warn',
				sprintf(
					'%d product(s) have no SKU, so an import cannot match them and may create duplicates: %s',
					count( $no_sku ),
					implode( '; ', $names )
				),
			);
		}

		$next = wp_next_scheduled( BDZ_ETSY_CRON_HOOK );
		$rows[] = $next
			? array( 'ok', 'Next scheduled sync: ' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'Y-m-d H:i' ) )
			: array( 'warn', 'No scheduled sync — automatic sync is off' );

		return $rows;
	}

	public function render() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( 'You do not have permission to view this page.' );
		}

		$settings  = BDZ_Etsy_Settings::all();
		$connected = BDZ_Etsy_OAuth::is_connected();
		$job       = new BDZ_Etsy_Job();
		$state     = $job->state();
		$report    = BDZ_Etsy_Job::last_report();

		$notice = isset( $_GET['bdz_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['bdz_notice'] ) ) : '';
		$type   = ( isset( $_GET['bdz_type'] ) && 'error' === $_GET['bdz_type'] ) ? 'error' : 'success';
		?>
		<div class="wrap bdz-etsy">
			<h1>Etsy Sync</h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<div class="bdz-grid">
				<div class="bdz-card">
					<h2>Status</h2>
					<ul class="bdz-checks">
						<?php foreach ( $this->readiness() as $row ) : ?>
							<li class="bdz-<?php echo esc_attr( $row[0] ); ?>">
								<span class="bdz-badge"><?php echo esc_html( strtoupper( $row[0] ) ); ?></span>
								<?php echo esc_html( $row[1] ); ?>
							</li>
						<?php endforeach; ?>
					</ul>

					<form method="post" class="bdz-connect">
						<?php wp_nonce_field( self::NONCE ); ?>
						<?php if ( $connected ) : ?>
							<button class="button" name="bdz_etsy_action" value="disconnect">Disconnect from Etsy</button>
						<?php else : ?>
							<button class="button button-primary" name="bdz_etsy_action" value="connect">Connect to Etsy</button>
						<?php endif; ?>
					</form>

					<p class="bdz-hint">
						Register this exact callback URL on the Etsy app — it must match byte for byte:<br>
						<code><?php echo esc_html( BDZ_Etsy_Settings::redirect_uri() ); ?></code>
					</p>
					<p class="bdz-hint">
						Sign in as the <strong>shop owner</strong> when approving. Whoever is logged in is
						who the token belongs to, and connecting with the wrong account
						succeeds and then finds no shop.
					</p>
				</div>

				<div class="bdz-card">
					<h2>Run</h2>
					<form method="post" class="bdz-run">
						<?php wp_nonce_field( self::NONCE ); ?>
						<button class="button" name="bdz_etsy_action" value="test" <?php disabled( ! $connected ); ?>>Test connection</button>
						<button class="button" name="bdz_etsy_action" value="dry_run" <?php disabled( ! $connected ); ?>>Dry run</button>
						<button class="button button-primary" name="bdz_etsy_action" value="run" <?php disabled( ! $connected ); ?>>Sync now</button>
						<?php if ( 'running' === $state['status'] ) : ?>
							<button class="button bdz-cancel" name="bdz_etsy_action" value="cancel">Cancel</button>
						<?php endif; ?>
					</form>

					<div id="bdz-progress" class="bdz-progress" data-running="<?php echo esc_attr( 'running' === $state['status'] ? '1' : '0' ); ?>">
						<div class="bdz-bar"><span style="width:0%"></span></div>
						<p class="bdz-message"><?php echo esc_html( $state['message'] ); ?></p>
						<p class="bdz-stats"></p>
					</div>

					<?php if ( $report ) : ?>
						<h3>Last run</h3>
						<p class="bdz-hint">
							<?php
							printf(
								'%s — %d listing(s); created %d, updated %d, skipped %d, failed %d%s',
								esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $report['finished_at'] ), 'Y-m-d H:i' ) ),
								(int) $report['count'],
								(int) $report['stats']['created'],
								(int) $report['stats']['updated'],
								(int) $report['stats']['skipped'],
								(int) $report['stats']['failed'],
								! empty( $report['dry_run'] ) ? ' (dry run)' : ''
							);
							?>
						</p>
						<?php if ( ! empty( $report['changes'] ) ) : ?>
							<p class="bdz-hint">
								<?php
								printf(
									'Changes since the run before: %d new, %d gone, %d modified.',
									count( $report['changes']['added'] ),
									count( $report['changes']['removed'] ),
									count( $report['changes']['modified'] )
								);
								?>
							</p>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>

			<div class="bdz-card">
				<h2>Activity</h2>
				<pre id="bdz-log" class="bdz-log"><?php
				foreach ( BDZ_Etsy_Logger::all() as $line ) {
					printf(
						"[%s] %s\n",
						esc_html( strtoupper( $line['level'] ) ),
						esc_html( $line['message'] )
					);
				}
				?></pre>
			</div>

			<div class="bdz-card">
				<h2>Settings</h2>
				<form method="post">
					<?php wp_nonce_field( self::NONCE ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="keystring">Etsy keystring</label></th>
							<td>
								<?php if ( BDZ_Etsy_Settings::is_locked( 'keystring' ) ) : ?>
									<input type="text" class="regular-text" value="set in wp-config.php" disabled>
									<p class="description">Defined as <code>BDZ_ETSY_KEYSTRING</code>. Remove that constant to manage it here.</p>
								<?php else : ?>
									<input type="text" id="keystring" name="keystring" class="regular-text" autocomplete="off"
										value="<?php echo esc_attr( $settings['keystring'] ? self::MASK : '' ); ?>">
									<p class="description">
										The app keystring. The shared secret is <strong>not</strong> needed — this uses PKCE.
										For a stronger posture, define <code>BDZ_ETSY_KEYSTRING</code> in wp-config.php instead.
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="shared_secret">Etsy shared secret</label></th>
							<td>
								<?php if ( BDZ_Etsy_Settings::is_locked( 'shared_secret' ) ) : ?>
									<input type="text" class="regular-text" value="set in wp-config.php" disabled>
									<p class="description">Defined as <code>BDZ_ETSY_SHARED_SECRET</code>.</p>
								<?php else : ?>
									<input type="text" id="shared_secret" name="shared_secret" class="regular-text" autocomplete="off"
										value="<?php echo esc_attr( $settings['shared_secret'] ? self::MASK : '' ); ?>">
									<p class="description">
										Not used by the OAuth handshake — that uses PKCE. But Etsy rejects API
										calls with <em>"Shared secret is required in x-api-key header"</em> unless
										this is set. When present it is sent as <code>x-api-key</code>; use
										<strong>Test connection</strong> to confirm which credential Etsy accepts.
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="shop_name">Shop name</label></th>
							<td>
								<input type="text" id="shop_name" name="shop_name" class="regular-text" value="<?php echo esc_attr( $settings['shop_name'] ); ?>">
								<p class="description">Optional. Leave both blank to use whichever shop the connected account owns.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="shop_id">Shop id</label></th>
							<td><input type="text" id="shop_id" name="shop_id" class="regular-text" value="<?php echo esc_attr( $settings['shop_id'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="redirect_uri">Callback URL</label></th>
							<td>
								<input type="text" id="redirect_uri" name="redirect_uri" class="large-text" value="<?php echo esc_attr( $settings['redirect_uri'] ); ?>"
									placeholder="<?php echo esc_attr( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>">
								<p class="description">Leave blank to use this page's URL. Must match the Etsy app registration exactly.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="stock_buffer">Stock buffer</label></th>
							<td>
								<input type="number" id="stock_buffer" name="stock_buffer" min="0" value="<?php echo esc_attr( $settings['stock_buffer'] ); ?>">
								<p class="description">Units held back from the store, so it runs out before Etsy does on made-to-order stock.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="new_status">New products</label></th>
							<td>
								<select id="new_status" name="new_status">
									<?php foreach ( array( 'draft' => 'Draft (recommended)', 'publish' => 'Published', 'pending' => 'Pending', 'private' => 'Private' ) as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['new_status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description">Applied to newly created products only. An existing product's status is never overwritten.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Behaviour</th>
							<td>
								<label><input type="checkbox" name="sync_enabled" value="1" <?php checked( $settings['sync_enabled'] ); ?>> Sync automatically every hour</label><br>
								<label><input type="checkbox" name="sync_images" value="1" <?php checked( $settings['sync_images'] ); ?>> Import images</label><br>
								<label><input type="checkbox" name="draft_missing" value="1" <?php checked( $settings['draft_missing'] ); ?>> Draft products whose Etsy listing is gone</label><br>
								<label><input type="checkbox" name="skip_review" value="1" <?php checked( $settings['skip_review'] ); ?>> Skip listings flagged for review</label>
							</td>
						</tr>
					</table>
					<p><button class="button button-primary" name="bdz_etsy_action" value="save">Save settings</button></p>
				</form>
			</div>
		</div>
		<?php
	}
}

/* ---- bootstrap ---- */
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
