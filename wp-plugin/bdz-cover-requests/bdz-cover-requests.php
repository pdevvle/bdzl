<?php
/**
 * Plugin Name: Cover Requests
 * Description: A form where customers send a picture of, or a link to, a book cover they would like bedazzled. Requests land in the dashboard, where they can be answered by email, tracked, and deleted.
 * Version:     1.0.0
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Author:      HarleysBooks
 * License:     GPL-2.0-or-later
 * Text Domain: bdz-cover-requests
 *
 * One file on purpose: WordPress accepts a plugin as a single PHP file in
 * wp-content/plugins/, which is the shape the site connector can write.
 *
 * Put the form on any page with the shortcode [bdz_cover_request].
 *
 * Requests are a private post type, so they never appear on the storefront.
 * Uploaded covers go into the media library attached to their request, and
 * are deleted with it when the request is permanently deleted.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BDZ_COVER_VERSION', '1.0.0' );
define( 'BDZ_COVER_FILE', __FILE__ );

class BDZ_Cover_Requests {

	const POST_TYPE = 'bdz_cover_request';
	const VIEW_PAGE = 'bdz-cover-request';
	const SETTINGS  = 'bdz_cover_requests_settings';
	const ANCHOR    = 'bdz-cover-request';

	const MAX_FILES = 5;
	const MAX_LINKS = 5;
	const MAX_BYTES = 10485760; // 10 MB per picture.

	/** Submissions allowed from one address per hour. */
	const RATE_LIMIT = 5;

	/** A human cannot fill the form in less than this many seconds. */
	const MIN_SECONDS = 3;

	const STATUSES = array(
		'new'     => 'New',
		'replied' => 'Replied',
		'closed'  => 'Done',
	);

	const IMAGE_MIMES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
	);

	private static $instance;

	/** Errors and submitted values from a failed post, for re-rendering. */
	private $errors = array();
	private $values = array();

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function hooks() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_shortcode( 'bdz_cover_request', array( $this, 'shortcode' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle_submission' ) );

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_menu', array( $this, 'menu_badge' ), 99 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_bdz_cover_reply', array( $this, 'handle_reply' ) );
		add_action( 'admin_post_bdz_cover_status', array( $this, 'handle_status' ) );
		add_action( 'load-post.php', array( $this, 'redirect_edit_screen' ) );

		add_filter( 'get_edit_post_link', array( $this, 'edit_link' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-edit-' . self::POST_TYPE, array( $this, 'bulk_actions' ) );
		add_action( 'restrict_manage_posts', array( $this, 'status_filter' ) );
		add_action( 'pre_get_posts', array( $this, 'apply_status_filter' ) );

		add_action( 'before_delete_post', array( $this, 'delete_attachments' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Storage                                                             */
	/* ------------------------------------------------------------------ */

	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => 'Cover Requests',
					'singular_name'      => 'Cover Request',
					'menu_name'          => 'Cover Requests',
					'all_items'          => 'All Requests',
					'search_items'       => 'Search Requests',
					'not_found'          => 'No cover requests yet.',
					'not_found_in_trash' => 'No cover requests in the trash.',
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_position'       => 56,
				'menu_icon'           => 'dashicons-book-alt',
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'        => true,
				'rewrite'             => false,
				'query_var'           => false,
			)
		);
	}

	public static function settings() {
		$saved = get_option( self::SETTINGS, array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'notify_email' => get_option( 'admin_email' ),
				'thank_you'    => "Thank you! Your cover is on its way to us. We'll be in touch by email soon.",
			)
		);
	}

	private static function status_of( $post_id ) {
		$status = get_post_meta( $post_id, '_bdz_status', true );
		return isset( self::STATUSES[ $status ] ) ? $status : 'new';
	}

	private static function image_ids( $post_id ) {
		$ids = get_post_meta( $post_id, '_bdz_images', true );
		return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
	}

	private static function links( $post_id ) {
		$links = get_post_meta( $post_id, '_bdz_links', true );
		return is_array( $links ) ? $links : array();
	}

	private static function thread( $post_id ) {
		$thread = get_post_meta( $post_id, '_bdz_thread', true );
		return is_array( $thread ) ? $thread : array();
	}

	/* ------------------------------------------------------------------ */
	/* Public form                                                         */
	/* ------------------------------------------------------------------ */

	public function shortcode() {
		$settings = self::settings();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['bdz_cover'] ) && 'sent' === $_GET['bdz_cover'] ) {
			return $this->styles() . '<div class="bdz-cover" id="' . esc_attr( self::ANCHOR ) . '">'
				. '<div class="bdz-cover__notice bdz-cover__notice--ok" role="status">'
				. wpautop( esc_html( $settings['thank_you'] ) )
				. '</div></div>';
		}

		$v = wp_parse_args(
			$this->values,
			array(
				'name'   => '',
				'email'  => '',
				'book'   => '',
				'author' => '',
				'links'  => '',
				'notes'  => '',
			)
		);

		$action = add_query_arg( 'bdz_cover', 'submit', remove_query_arg( 'bdz_cover' ) ) . '#' . self::ANCHOR;

		ob_start();
		echo $this->styles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS.
		?>
		<div class="bdz-cover" id="<?php echo esc_attr( self::ANCHOR ); ?>">
			<?php if ( $this->errors ) : ?>
				<div class="bdz-cover__notice bdz-cover__notice--error" role="alert">
					<p><strong>Please check the form:</strong></p>
					<ul>
						<?php foreach ( $this->errors as $error ) : ?>
							<li><?php echo esc_html( $error ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<form class="bdz-cover__form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( $action ); ?>" novalidate>
				<input type="hidden" name="bdz_cover_form" value="1">
				<input type="hidden" name="bdz_cover_t" value="<?php echo esc_attr( self::signed_time() ); ?>">

				<div class="bdz-cover__trap" aria-hidden="true">
					<label>Leave this empty <input type="text" name="bdz_cover_website" tabindex="-1" autocomplete="off"></label>
				</div>

				<div class="bdz-cover__row">
					<p class="bdz-cover__field">
						<label for="bdz-cover-name">Your name <span class="bdz-cover__req">*</span></label>
						<input id="bdz-cover-name" type="text" name="bdz_cover_name" required maxlength="100" autocomplete="name" value="<?php echo esc_attr( $v['name'] ); ?>">
					</p>
					<p class="bdz-cover__field">
						<label for="bdz-cover-email">Email <span class="bdz-cover__req">*</span></label>
						<input id="bdz-cover-email" type="email" name="bdz_cover_email" required maxlength="200" autocomplete="email" value="<?php echo esc_attr( $v['email'] ); ?>">
					</p>
				</div>

				<div class="bdz-cover__row">
					<p class="bdz-cover__field">
						<label for="bdz-cover-book">Book title <span class="bdz-cover__req">*</span></label>
						<input id="bdz-cover-book" type="text" name="bdz_cover_book" required maxlength="200" value="<?php echo esc_attr( $v['book'] ); ?>">
					</p>
					<p class="bdz-cover__field">
						<label for="bdz-cover-author">Author</label>
						<input id="bdz-cover-author" type="text" name="bdz_cover_author" maxlength="200" value="<?php echo esc_attr( $v['author'] ); ?>">
					</p>
				</div>

				<fieldset class="bdz-cover__covers">
					<legend>The cover <span class="bdz-cover__req">*</span></legend>
					<p class="bdz-cover__hint">Upload a picture, paste a link, or both.</p>

					<p class="bdz-cover__field">
						<label for="bdz-cover-files">Picture(s) of the cover</label>
						<input id="bdz-cover-files" type="file" name="bdz_cover_files[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
						<span class="bdz-cover__hint">JPG, PNG, GIF or WebP · up to <?php echo (int) self::MAX_FILES; ?> pictures, <?php echo (int) ( self::MAX_BYTES / 1048576 ); ?> MB each<?php echo $this->errors ? ' · please re-attach any pictures' : ''; ?></span>
					</p>
					<div class="bdz-cover__previews" aria-live="polite"></div>

					<p class="bdz-cover__field">
						<label for="bdz-cover-links">Link(s) to the cover</label>
						<textarea id="bdz-cover-links" name="bdz_cover_links" rows="3" placeholder="https://… (one per line)"><?php echo esc_textarea( $v['links'] ); ?></textarea>
					</p>
				</fieldset>

				<p class="bdz-cover__field">
					<label for="bdz-cover-notes">Anything else? Colours, style, deadline…</label>
					<textarea id="bdz-cover-notes" name="bdz_cover_notes" rows="4" maxlength="5000"><?php echo esc_textarea( $v['notes'] ); ?></textarea>
				</p>

				<p class="bdz-cover__client-error" role="alert" hidden></p>

				<p><button type="submit" class="bdz-cover__submit">Send my cover ✨</button></p>
			</form>
		</div>
		<?php
		echo $this->script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static JS.
		return ob_get_clean();
	}

	/**
	 * The public form deliberately carries no nonce. Nonces for logged-out
	 * visitors are identical for everyone and go stale in page caches, where
	 * they would reject real customers; and there is no session to forge a
	 * request against. Spam is held off by a honeypot, a minimum fill time and
	 * a per-address rate limit instead.
	 */
	public function maybe_handle_submission() {
		// phpcs:disable WordPress.Security.NonceVerification -- see above.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		// A body larger than post_max_size arrives as an empty $_POST.
		if ( empty( $_POST ) && isset( $_GET['bdz_cover'] ) && 'submit' === $_GET['bdz_cover'] ) {
			$this->errors[] = 'That upload was too large. Please send smaller pictures, or fewer at a time.';
			return;
		}

		if ( empty( $_POST['bdz_cover_form'] ) ) {
			return;
		}

		$this->values = array(
			'name'   => sanitize_text_field( wp_unslash( $_POST['bdz_cover_name'] ?? '' ) ),
			'email'  => sanitize_email( wp_unslash( $_POST['bdz_cover_email'] ?? '' ) ),
			'book'   => sanitize_text_field( wp_unslash( $_POST['bdz_cover_book'] ?? '' ) ),
			'author' => sanitize_text_field( wp_unslash( $_POST['bdz_cover_author'] ?? '' ) ),
			'links'  => sanitize_textarea_field( wp_unslash( $_POST['bdz_cover_links'] ?? '' ) ),
			'notes'  => sanitize_textarea_field( wp_unslash( $_POST['bdz_cover_notes'] ?? '' ) ),
		);

		$honeypot = trim( (string) wp_unslash( $_POST['bdz_cover_website'] ?? '' ) );
		$too_fast = ! self::time_ok( (string) wp_unslash( $_POST['bdz_cover_t'] ?? '' ) );
		// phpcs:enable

		if ( '' !== $honeypot || $too_fast ) {
			// Pretend it worked: telling a bot why it failed only trains it.
			$this->redirect_sent();
		}

		if ( '' === $this->values['name'] ) {
			$this->errors[] = 'Please tell us your name.';
		}
		if ( ! is_email( $this->values['email'] ) ) {
			$this->errors[] = 'Please enter a valid email address so we can reply.';
		}
		if ( '' === $this->values['book'] ) {
			$this->errors[] = 'Please tell us the book title.';
		}

		$links = $this->parse_links( $this->values['links'] );
		$files = $this->collect_files();

		if ( ! $links && ! $files && ! $this->errors ) {
			$this->errors[] = 'Please upload a picture of the cover or paste a link to it.';
		}

		if ( $this->errors ) {
			return;
		}

		$rate_key = 'bdz_cover_rl_' . substr( wp_hash( self::client_ip() ), 0, 20 );
		$recent   = (int) get_transient( $rate_key );
		if ( $recent >= self::RATE_LIMIT ) {
			$this->errors[] = 'We have received several requests from you already. Please try again in an hour, or email us directly.';
			return;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'private',
				'post_title'   => $this->values['book'] . ' — ' . $this->values['name'],
				'post_content' => $this->values['notes'],
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			$this->errors[] = 'Sorry, something went wrong saving your request. Please try again.';
			return;
		}

		update_post_meta( $post_id, '_bdz_name', $this->values['name'] );
		update_post_meta( $post_id, '_bdz_email', $this->values['email'] );
		update_post_meta( $post_id, '_bdz_book', $this->values['book'] );
		update_post_meta( $post_id, '_bdz_author', $this->values['author'] );
		update_post_meta( $post_id, '_bdz_links', $links );
		update_post_meta( $post_id, '_bdz_status', 'new' );
		update_post_meta( $post_id, '_bdz_thread', array() );

		$image_ids = $this->store_files( $files, $post_id );
		update_post_meta( $post_id, '_bdz_images', $image_ids );

		if ( ! $image_ids && ! $links ) {
			// Every picture failed to store and there was nothing else to go on.
			wp_delete_post( $post_id, true );
			$this->errors[] = 'Sorry, we could not save your picture. Please try a different file, or paste a link instead.';
			return;
		}

		if ( $image_ids ) {
			set_post_thumbnail( $post_id, $image_ids[0] );
		}

		set_transient( $rate_key, $recent + 1, HOUR_IN_SECONDS );

		$this->notify_owner( $post_id );
		$this->redirect_sent();
	}

	private function redirect_sent() {
		$url = add_query_arg( 'bdz_cover', 'sent', remove_query_arg( 'bdz_cover' ) ) . '#' . self::ANCHOR;
		wp_safe_redirect( $url, 303 );
		exit;
	}

	private function parse_links( $raw ) {
		$links = array();
		foreach ( preg_split( '/\s+/', (string) $raw ) as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}
			if ( ! preg_match( '#^https?://#i', $candidate ) ) {
				$candidate = 'https://' . $candidate;
			}
			$url  = esc_url_raw( $candidate, array( 'http', 'https' ) );
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) || false === strpos( $host, '.' ) ) {
				$this->errors[] = sprintf( '"%s" does not look like a web link.', $candidate );
				continue;
			}
			$links[] = $url;
		}

		$links = array_values( array_unique( $links ) );
		if ( count( $links ) > self::MAX_LINKS ) {
			$this->errors[] = sprintf( 'Please send at most %d links.', self::MAX_LINKS );
		}
		return array_slice( $links, 0, self::MAX_LINKS );
	}

	/**
	 * Validate uploads before anything is created, so a bad file fails the
	 * whole form rather than leaving half a request behind.
	 */
	private function collect_files() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see maybe_handle_submission().
		if ( empty( $_FILES['bdz_cover_files'] ) || ! is_array( $_FILES['bdz_cover_files']['name'] ) ) {
			return array();
		}

		$raw   = $_FILES['bdz_cover_files']; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		$files = array();

		foreach ( array_keys( $raw['name'] ) as $i ) {
			$error = (int) $raw['error'][ $i ];
			if ( UPLOAD_ERR_NO_FILE === $error ) {
				continue;
			}

			$name = sanitize_file_name( (string) $raw['name'][ $i ] );

			if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error || (int) $raw['size'][ $i ] > self::MAX_BYTES ) {
				$this->errors[] = sprintf( '"%s" is too large — the limit is %d MB per picture.', $name, self::MAX_BYTES / 1048576 );
				continue;
			}
			if ( UPLOAD_ERR_OK !== $error || ! is_uploaded_file( $raw['tmp_name'][ $i ] ) ) {
				$this->errors[] = sprintf( '"%s" did not upload completely. Please try again.', $name );
				continue;
			}

			$check = wp_check_filetype_and_ext( $raw['tmp_name'][ $i ], $name, self::IMAGE_MIMES );
			if ( empty( $check['type'] ) || ! in_array( $check['type'], self::IMAGE_MIMES, true ) ) {
				$this->errors[] = sprintf( '"%s" is not a JPG, PNG, GIF or WebP picture.', $name );
				continue;
			}

			$files[] = array(
				'name'     => $check['proper_filename'] ? $check['proper_filename'] : $name,
				'type'     => $check['type'],
				'tmp_name' => $raw['tmp_name'][ $i ],
				'error'    => 0,
				'size'     => (int) $raw['size'][ $i ],
			);
		}

		if ( count( $files ) > self::MAX_FILES ) {
			$this->errors[] = sprintf( 'Please send at most %d pictures.', self::MAX_FILES );
		}
		return array_slice( $files, 0, self::MAX_FILES );
	}

	private function store_files( array $files, $post_id ) {
		if ( ! $files ) {
			return array();
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$ids = array();
		foreach ( $files as $file ) {
			$id = media_handle_sideload(
				$file,
				$post_id,
				'Cover request: ' . $this->values['book']
			);
			if ( ! is_wp_error( $id ) ) {
				$ids[] = (int) $id;
			}
		}
		return $ids;
	}

	private function notify_owner( $post_id ) {
		$to = self::settings()['notify_email'];
		if ( ! is_email( $to ) ) {
			return;
		}

		$v     = $this->values;
		$lines = array(
			'A new cover request has arrived.',
			'',
			'From:   ' . $v['name'] . ' <' . $v['email'] . '>',
			'Book:   ' . $v['book'] . ( $v['author'] ? ' by ' . $v['author'] : '' ),
		);

		$images = self::image_ids( $post_id );
		if ( $images ) {
			$lines[] = '';
			$lines[] = 'Pictures:';
			foreach ( $images as $id ) {
				$lines[] = '  ' . wp_get_attachment_url( $id );
			}
		}

		$links = self::links( $post_id );
		if ( $links ) {
			$lines[] = '';
			$lines[] = 'Links:';
			foreach ( $links as $link ) {
				$lines[] = '  ' . $link;
			}
		}

		if ( '' !== $v['notes'] ) {
			$lines[] = '';
			$lines[] = 'Notes:';
			$lines[] = $v['notes'];
		}

		$lines[] = '';
		$lines[] = 'Open and reply: ' . self::view_url( $post_id );
		$lines[] = '(Replying to this email also goes straight to the customer.)';

		wp_mail(
			$to,
			'New cover request: ' . $v['book'],
			implode( "\n", $lines ),
			array( 'Reply-To: ' . self::header_safe( $v['name'] ) . ' <' . $v['email'] . '>' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Spam guards                                                         */
	/* ------------------------------------------------------------------ */

	private static function signed_time() {
		$t = (string) time();
		return $t . '.' . substr( wp_hash( 'bdz_cover_t' . $t ), 0, 16 );
	}

	private static function time_ok( $value ) {
		$parts = explode( '.', $value, 2 );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return false;
		}
		if ( ! hash_equals( substr( wp_hash( 'bdz_cover_t' . $parts[0] ), 0, 16 ), $parts[1] ) ) {
			return false;
		}
		// No upper bound: a page served from cache carries an old stamp.
		return time() - (int) $parts[0] >= self::MIN_SECONDS;
	}

	private static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	private static function header_safe( $text ) {
		return trim( str_replace( array( "\r", "\n", '<', '>', '"', ',' ), ' ', (string) $text ) );
	}

	/* ------------------------------------------------------------------ */
	/* Front-end assets                                                    */
	/* ------------------------------------------------------------------ */

	private function styles() {
		static $printed = false;
		if ( $printed ) {
			return '';
		}
		$printed = true;

		return <<<'CSS'
<style>
.bdz-cover{--bdz-accent:#b4378f;--bdz-border:rgba(0,0,0,.18);max-width:44rem}
.bdz-cover__form{display:grid;gap:.25rem}
.bdz-cover__row{display:grid;gap:0 1rem;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr))}
.bdz-cover__field{display:flex;flex-direction:column;gap:.35rem;margin:0 0 1rem}
.bdz-cover__field label,.bdz-cover__covers legend{font-weight:600}
.bdz-cover input[type=text],.bdz-cover input[type=email],.bdz-cover textarea{width:100%;box-sizing:border-box;padding:.6rem .7rem;border:1px solid var(--bdz-border);border-radius:6px;font:inherit}
.bdz-cover input:focus,.bdz-cover textarea:focus{outline:2px solid var(--bdz-accent);outline-offset:1px}
.bdz-cover__covers{border:1px dashed var(--bdz-accent);border-radius:10px;padding:1rem 1.1rem .25rem;margin:0 0 1rem}
.bdz-cover__covers legend{padding:0 .4rem}
.bdz-cover__hint{font-size:.875em;opacity:.75;margin:0 0 .75rem}
.bdz-cover__field .bdz-cover__hint{margin:0}
.bdz-cover__req{color:var(--bdz-accent)}
.bdz-cover__previews{display:flex;flex-wrap:wrap;gap:.5rem;margin:0 0 1rem}
.bdz-cover__previews:empty{display:none}
.bdz-cover__previews img{width:84px;height:112px;object-fit:cover;border-radius:4px;border:1px solid var(--bdz-border)}
.bdz-cover__submit{background:var(--bdz-accent);color:#fff;border:0;border-radius:999px;padding:.75rem 1.6rem;font:inherit;font-weight:600;cursor:pointer}
.bdz-cover__submit:hover{filter:brightness(1.1)}
.bdz-cover__submit[disabled]{opacity:.6;cursor:wait}
.bdz-cover__notice{border-radius:8px;padding:.75rem 1rem;margin:0 0 1rem}
.bdz-cover__notice p:last-child,.bdz-cover__notice ul{margin-bottom:0}
.bdz-cover__notice--ok{background:#f3fbf4;border:1px solid #6fbf7a;color:#1d4d24}
.bdz-cover__notice--error,.bdz-cover__client-error{background:#fdf2f2;border:1px solid #e08a8a;color:#7a1f1f}
.bdz-cover__client-error{border-radius:8px;padding:.6rem .9rem}
.bdz-cover__trap{position:absolute!important;left:-9999px;width:1px;height:1px;overflow:hidden}
</style>
CSS;
	}

	private function script() {
		$max_files = (int) self::MAX_FILES;
		$max_bytes = (int) self::MAX_BYTES;

		$js = <<<'JS'
<script>
(function () {
	var root = document.getElementById('bdz-cover-request');
	if (!root) { return; }
	var form = root.querySelector('form');
	if (!form) { return; }
	var files = form.querySelector('#bdz-cover-files');
	var links = form.querySelector('#bdz-cover-links');
	var previews = form.querySelector('.bdz-cover__previews');
	var out = form.querySelector('.bdz-cover__client-error');
	var button = form.querySelector('.bdz-cover__submit');

	function show(msg) {
		out.textContent = msg;
		out.hidden = !msg;
		if (msg) { out.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
	}

	files.addEventListener('change', function () {
		previews.textContent = '';
		Array.prototype.slice.call(files.files, 0, MAX_FILES).forEach(function (f) {
			if (!/^image\//.test(f.type)) { return; }
			var img = document.createElement('img');
			img.alt = f.name;
			img.src = URL.createObjectURL(f);
			img.onload = function () { URL.revokeObjectURL(img.src); };
			previews.appendChild(img);
		});
		show('');
	});

	form.addEventListener('submit', function (e) {
		var problems = [];
		['#bdz-cover-name', '#bdz-cover-book'].forEach(function (sel) {
			var el = form.querySelector(sel);
			if (!el.value.trim()) { problems.push(el.labels[0].textContent.replace('*', '').trim() + ' is required.'); }
		});
		var email = form.querySelector('#bdz-cover-email');
		if (!email.validity.valid || !email.value) { problems.push('Please enter a valid email address.'); }
		var count = files.files.length;
		if (!count && !links.value.trim()) { problems.push('Please upload a picture of the cover or paste a link to it.'); }
		if (count > MAX_FILES) { problems.push('Please choose at most ' + MAX_FILES + ' pictures.'); }
		Array.prototype.forEach.call(files.files, function (f) {
			if (f.size > MAX_BYTES) { problems.push('"' + f.name + '" is larger than ' + Math.round(MAX_BYTES / 1048576) + ' MB.'); }
		});
		if (problems.length) {
			e.preventDefault();
			show(problems.join(' '));
			return;
		}
		button.disabled = true;
		button.textContent = 'Sending…';
	});
})();
</script>
JS;
		return str_replace( array( 'MAX_FILES', 'MAX_BYTES' ), array( (string) $max_files, (string) $max_bytes ), $js );
	}

	/* ------------------------------------------------------------------ */
	/* Admin: list                                                         */
	/* ------------------------------------------------------------------ */

	public static function view_url( $post_id ) {
		return add_query_arg(
			array(
				'page'    => self::VIEW_PAGE,
				'request' => (int) $post_id,
			),
			admin_url( 'admin.php' )
		);
	}

	public function edit_link( $link, $post_id ) {
		return get_post_type( $post_id ) === self::POST_TYPE ? self::view_url( $post_id ) : $link;
	}

	/** The block editor has nothing useful to show for a request. */
	public function redirect_edit_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$action  = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		// Only the editor: trash, untrash and delete also go through post.php.
		if ( 'edit' === $action && $post_id && get_post_type( $post_id ) === self::POST_TYPE ) {
			wp_safe_redirect( self::view_url( $post_id ) );
			exit;
		}
	}

	public function columns( $columns ) {
		return array(
			'cb'         => $columns['cb'],
			'bdz_cover'  => 'Cover',
			'title'      => 'Book',
			'bdz_from'   => 'From',
			'bdz_status' => 'Status',
			'date'       => 'Received',
		);
	}

	public function column( $column, $post_id ) {
		switch ( $column ) {
			case 'bdz_cover':
				$ids = self::image_ids( $post_id );
				if ( $ids ) {
					echo wp_get_attachment_image( $ids[0], array( 48, 64 ), false, array( 'style' => 'width:48px;height:64px;object-fit:cover;border-radius:3px' ) );
				} elseif ( self::links( $post_id ) ) {
					echo '<span class="dashicons dashicons-admin-links" title="Link only"></span>';
				}
				break;

			case 'bdz_from':
				$email = (string) get_post_meta( $post_id, '_bdz_email', true );
				echo esc_html( (string) get_post_meta( $post_id, '_bdz_name', true ) ) . '<br>';
				echo '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
				break;

			case 'bdz_status':
				echo self::status_badge( self::status_of( $post_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				break;
		}
	}

	private static function status_badge( $status ) {
		$colours = array(
			'new'     => '#b4378f',
			'replied' => '#2271b1',
			'closed'  => '#50575e',
		);
		return sprintf(
			'<span style="display:inline-block;padding:2px 8px;border-radius:999px;color:#fff;background:%s;font-size:12px">%s</span>',
			esc_attr( $colours[ $status ] ),
			esc_html( self::STATUSES[ $status ] )
		);
	}

	public function row_actions( $actions, $post ) {
		if ( self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}
		unset( $actions['inline hide-if-no-js'], $actions['view'] );
		if ( isset( $actions['edit'] ) ) {
			$actions['edit'] = '<a href="' . esc_url( self::view_url( $post->ID ) ) . '">Open &amp; reply</a>';
		}
		return $actions;
	}

	public function bulk_actions( $actions ) {
		unset( $actions['edit'] );
		return $actions;
	}

	public function status_filter( $post_type ) {
		if ( self::POST_TYPE !== $post_type ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter only.
		$current = isset( $_GET['bdz_status'] ) ? sanitize_key( $_GET['bdz_status'] ) : '';
		echo '<select name="bdz_status"><option value="">All statuses</option>';
		foreach ( self::STATUSES as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $current, $key, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	public function apply_status_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || self::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter only.
		$status = isset( $_GET['bdz_status'] ) ? sanitize_key( $_GET['bdz_status'] ) : '';
		if ( ! isset( self::STATUSES[ $status ] ) ) {
			return;
		}
		$query->set(
			'meta_query',
			array(
				array(
					'key'   => '_bdz_status',
					'value' => $status,
				),
			)
		);
	}

	/** A count of new requests beside the menu item, like comments have. */
	public function menu_badge() {
		global $menu;

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		$count = count(
			get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'private',
					'posts_per_page' => 100,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => '_bdz_status', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => 'new', // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			)
		);
		if ( ! $count || ! is_array( $menu ) ) {
			return;
		}

		foreach ( $menu as $i => $item ) {
			if ( isset( $item[2] ) && 'edit.php?post_type=' . self::POST_TYPE === $item[2] ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				$menu[ $i ][0] .= sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>', $count );
				break;
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Admin: one request                                                  */
	/* ------------------------------------------------------------------ */

	public function admin_menu() {
		$parent = 'edit.php?post_type=' . self::POST_TYPE;

		// Reachable from each row's "Open & reply"; not a menu item of its own.
		$hook = add_submenu_page( '', 'Cover Request', 'Cover Request', 'edit_posts', self::VIEW_PAGE, array( $this, 'render_view' ) );

		// A page with no parent menu has no title, and admin-header.php warns.
		add_action(
			'load-' . $hook,
			function () {
				global $title;
				$title = 'Cover Request'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
		);

		add_filter(
			'parent_file',
			function ( $file ) use ( $parent ) {
				global $plugin_page;
				return self::VIEW_PAGE === $plugin_page ? $parent : $file;
			}
		);
		add_filter(
			'submenu_file',
			function ( $file ) use ( $parent ) {
				global $plugin_page;
				return self::VIEW_PAGE === $plugin_page ? $parent : $file;
			}
		);

		add_submenu_page( $parent, 'Cover Request Settings', 'Settings', 'manage_options', 'bdz-cover-settings', array( $this, 'render_settings' ) );
	}

	private function request_or_die() {
		// phpcs:ignore WordPress.Security.NonceVerification -- nonce checked by the caller for writes.
		$post_id = isset( $_REQUEST['request'] ) ? absint( $_REQUEST['request'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			wp_die( 'That cover request no longer exists.', '', array( 'back_link' => true ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			wp_die( 'You are not allowed to see cover requests.', '', array( 'response' => 403 ) );
		}
		return $post;
	}

	public function render_view() {
		$post   = $this->request_or_die();
		$id     = $post->ID;
		$name   = (string) get_post_meta( $id, '_bdz_name', true );
		$email  = (string) get_post_meta( $id, '_bdz_email', true );
		$book   = (string) get_post_meta( $id, '_bdz_book', true );
		$author = (string) get_post_meta( $id, '_bdz_author', true );
		$status = self::status_of( $id );
		$thread = self::thread( $id );
		$list   = admin_url( 'edit.php?post_type=' . self::POST_TYPE );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$flash = isset( $_GET['bdz_msg'] ) ? sanitize_key( $_GET['bdz_msg'] ) : '';
		$flash_text = array(
			'sent'      => array( 'success', 'Reply sent to ' . $email . '.' ),
			'noted'     => array( 'success', 'Note saved.' ),
			'status'    => array( 'success', 'Status updated.' ),
			'mailfail'  => array( 'error', 'The email could not be sent. Your message was saved as a note — check the site\'s email setup, or reply from your own inbox.' ),
			'empty'     => array( 'error', 'Write a message first.' ),
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $book ); ?></h1>
			<?php echo self::status_badge( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<a href="<?php echo esc_url( $list ); ?>" class="page-title-action">← All requests</a>
			<hr class="wp-header-end">

			<?php if ( isset( $flash_text[ $flash ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $flash_text[ $flash ][0] ); ?> is-dismissible"><p><?php echo esc_html( $flash_text[ $flash ][1] ); ?></p></div>
			<?php endif; ?>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">

						<div class="postbox">
							<h2 class="hndle">The request</h2>
							<div class="inside">
								<table class="form-table" role="presentation">
									<tr><th>From</th><td><?php echo esc_html( $name ); ?> &lt;<a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a>&gt;</td></tr>
									<tr><th>Book</th><td><?php echo esc_html( $book ); ?><?php echo $author ? ' <span class="description">by ' . esc_html( $author ) . '</span>' : ''; ?></td></tr>
									<tr><th>Received</th><td><?php echo esc_html( get_the_date( 'j F Y, g:i a', $post ) ); ?></td></tr>
									<?php if ( '' !== trim( $post->post_content ) ) : ?>
										<tr><th>Notes</th><td><?php echo wpautop( esc_html( $post->post_content ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
									<?php endif; ?>
									<?php if ( self::links( $id ) ) : ?>
										<tr><th>Links</th><td>
											<?php foreach ( self::links( $id ) as $link ) : ?>
												<div><a href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo esc_html( $link ); ?></a></div>
											<?php endforeach; ?>
										</td></tr>
									<?php endif; ?>
								</table>

								<?php if ( self::image_ids( $id ) ) : ?>
									<div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:8px">
										<?php foreach ( self::image_ids( $id ) as $image_id ) : ?>
											<a href="<?php echo esc_url( wp_get_attachment_url( $image_id ) ); ?>" target="_blank" rel="noopener">
												<?php echo wp_get_attachment_image( $image_id, 'medium', false, array( 'style' => 'max-height:320px;width:auto;border-radius:4px;border:1px solid #dcdcde' ) ); ?>
											</a>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
							</div>
						</div>

						<div class="postbox">
							<h2 class="hndle">Conversation</h2>
							<div class="inside">
								<?php if ( ! $thread ) : ?>
									<p class="description">Nothing yet. Your replies and notes appear here.</p>
								<?php else : ?>
									<?php foreach ( $thread as $entry ) : ?>
										<?php $sent = 'sent' === ( $entry['type'] ?? '' ); ?>
										<div style="border-left:4px solid <?php echo $sent ? '#2271b1' : '#dba617'; ?>;background:#f6f7f7;padding:8px 12px;margin:0 0 12px">
											<p style="margin:0 0 6px">
												<strong><?php echo $sent ? 'Emailed to customer' : 'Note'; ?></strong>
												<span class="description">— <?php echo esc_html( wp_date( 'j M Y, g:i a', (int) ( $entry['time'] ?? 0 ) ) ); ?><?php echo ! empty( $entry['by'] ) ? ' · ' . esc_html( $entry['by'] ) : ''; ?></span>
											</p>
											<?php if ( $sent && ! empty( $entry['subject'] ) ) : ?>
												<p style="margin:0 0 6px"><em><?php echo esc_html( $entry['subject'] ); ?></em></p>
											<?php endif; ?>
											<?php echo wpautop( esc_html( (string) ( $entry['body'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										</div>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>
						</div>

						<div class="postbox">
							<h2 class="hndle">Reply</h2>
							<div class="inside">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="bdz_cover_reply">
									<input type="hidden" name="request" value="<?php echo (int) $id; ?>">
									<?php wp_nonce_field( 'bdz_cover_reply_' . $id ); ?>
									<p>
										<label for="bdz-subject"><strong>Subject</strong></label><br>
										<input id="bdz-subject" type="text" name="subject" class="large-text" value="<?php echo esc_attr( 'Your bedazzled cover: ' . $book ); ?>">
									</p>
									<p>
										<label for="bdz-message"><strong>Message</strong></label><br>
										<textarea id="bdz-message" name="message" rows="8" class="large-text"><?php echo esc_textarea( 'Hi ' . strtok( $name, ' ' ) . ",\n\n" ); ?></textarea>
									</p>
									<p>
										<label><input type="radio" name="mode" value="email" checked> Email it to <?php echo esc_html( $email ); ?></label><br>
										<label><input type="radio" name="mode" value="note"> Just save it as a note (e.g. paste in their reply)</label>
									</p>
									<p class="description">Their replies come to <?php echo esc_html( self::settings()['notify_email'] ); ?>.</p>
									<?php submit_button( 'Send', 'primary', 'submit', false ); ?>
								</form>
							</div>
						</div>
					</div>

					<div id="postbox-container-1" class="postbox-container">
						<div class="postbox">
							<h2 class="hndle">Status</h2>
							<div class="inside">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="bdz_cover_status">
									<input type="hidden" name="request" value="<?php echo (int) $id; ?>">
									<?php wp_nonce_field( 'bdz_cover_status_' . $id ); ?>
									<?php foreach ( self::STATUSES as $key => $label ) : ?>
										<label style="display:block;margin:0 0 6px"><input type="radio" name="status" value="<?php echo esc_attr( $key ); ?>" <?php checked( $status, $key ); ?>> <?php echo esc_html( $label ); ?></label>
									<?php endforeach; ?>
									<?php submit_button( 'Update', 'secondary', 'submit', false ); ?>
								</form>
								<?php if ( current_user_can( 'delete_post', $id ) ) : ?>
									<hr>
									<a class="submitdelete" style="color:#b32d2e" href="<?php echo esc_url( add_query_arg( '_wp_http_referer', rawurlencode( $list ), get_delete_post_link( $id ) ) ); ?>">Move to Trash</a>
									<p class="description">Empty it from the trash to delete the pictures too.</p>
								<?php endif; ?>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function handle_reply() {
		$post = $this->request_or_die();
		check_admin_referer( 'bdz_cover_reply_' . $post->ID );

		$subject = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
		$message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$mode    = 'note' === ( $_POST['mode'] ?? '' ) ? 'note' : 'email';

		$greeting_only = (bool) preg_match( '/^Hi\b[^\n]*,\s*$/', $message );
		if ( '' === trim( $message ) || ( 'email' === $mode && $greeting_only ) ) {
			$this->back( $post->ID, 'empty' );
		}

		$entry = array(
			'type' => 'note',
			'time' => time(),
			'by'   => wp_get_current_user()->display_name,
			'body' => $message,
		);
		$flash = 'noted';

		if ( 'email' === $mode ) {
			if ( '' === $subject ) {
				$subject = 'Your bedazzled cover';
			}
			$to       = (string) get_post_meta( $post->ID, '_bdz_email', true );
			$reply_to = self::settings()['notify_email'];
			$headers  = array();
			if ( is_email( $reply_to ) ) {
				$headers[] = 'Reply-To: ' . self::header_safe( get_bloginfo( 'name' ) ) . ' <' . $reply_to . '>';
			}

			if ( is_email( $to ) && wp_mail( $to, $subject, $message, $headers ) ) {
				$entry['type']    = 'sent';
				$entry['subject'] = $subject;
				$flash            = 'sent';
				if ( 'new' === self::status_of( $post->ID ) ) {
					update_post_meta( $post->ID, '_bdz_status', 'replied' );
				}
			} else {
				$flash = 'mailfail';
			}
		}

		$thread   = self::thread( $post->ID );
		$thread[] = $entry;
		update_post_meta( $post->ID, '_bdz_thread', $thread );

		$this->back( $post->ID, $flash );
	}

	public function handle_status() {
		$post = $this->request_or_die();
		check_admin_referer( 'bdz_cover_status_' . $post->ID );

		$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
		if ( isset( self::STATUSES[ $status ] ) ) {
			update_post_meta( $post->ID, '_bdz_status', $status );
		}
		$this->back( $post->ID, 'status' );
	}

	private function back( $post_id, $flash ) {
		wp_safe_redirect( add_query_arg( 'bdz_msg', $flash, self::view_url( $post_id ) ) );
		exit;
	}

	/** Permanently deleting a request takes its uploaded pictures with it. */
	public function delete_attachments( $post_id ) {
		if ( get_post_type( $post_id ) !== self::POST_TYPE ) {
			return;
		}
		foreach ( self::image_ids( $post_id ) as $image_id ) {
			if ( (int) wp_get_post_parent_id( $image_id ) === (int) $post_id ) {
				wp_delete_attachment( $image_id, true );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Admin: settings                                                     */
	/* ------------------------------------------------------------------ */

	public function register_settings() {
		register_setting(
			'bdz_cover_requests',
			self::SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();
		$email = sanitize_email( $input['notify_email'] ?? '' );
		return array(
			'notify_email' => is_email( $email ) ? $email : get_option( 'admin_email' ),
			'thank_you'    => sanitize_textarea_field( $input['thank_you'] ?? '' ),
		);
	}

	public function render_settings() {
		$s = self::settings();
		?>
		<div class="wrap">
			<h1>Cover Request Settings</h1>
			<p>Put the form on any page with this shortcode: <code>[bdz_cover_request]</code></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'bdz_cover_requests' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bdz-notify">Notify &amp; reply-to email</label></th>
						<td>
							<input id="bdz-notify" type="email" class="regular-text" name="<?php echo esc_attr( self::SETTINGS ); ?>[notify_email]" value="<?php echo esc_attr( $s['notify_email'] ); ?>">
							<p class="description">New requests are emailed here, and customers' replies to your messages come back here.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bdz-thanks">Thank-you message</label></th>
						<td>
							<textarea id="bdz-thanks" class="large-text" rows="3" name="<?php echo esc_attr( self::SETTINGS ); ?>[thank_you]"><?php echo esc_textarea( $s['thank_you'] ); ?></textarea>
							<p class="description">Shown after someone sends the form.</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

BDZ_Cover_Requests::instance()->hooks();

register_activation_hook(
	BDZ_COVER_FILE,
	function () {
		BDZ_Cover_Requests::instance()->register_post_type();
	}
);
