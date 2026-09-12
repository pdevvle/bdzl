<?php
/**
 * Admin screen: credentials, connection, readiness, and the run controls.
 *
 * All writes go through a nonce and a manage_woocommerce capability check.
 * Secrets are never echoed back into the page — stored keys render as a masked
 * placeholder, and submitting the placeholder unchanged leaves them alone.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
