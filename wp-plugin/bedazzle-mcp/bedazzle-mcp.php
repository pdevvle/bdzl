<?php
/**
 * Plugin Name: Bedazzle MCP Tools
 * Plugin URI:  https://bedazzlekits.com/
 * Description: Companion plugin for AI Engine that adds WooCommerce, theme, plugin-file, WordPress-update, and URL-download tools to the MCP connector on bedazzlekits.com. Ported from the Priority Print MCP tools, rebranded and re-scoped for this store.
 * Version:     1.0.0
 * Author:      HarleysBooks build
 * License:     GPL v2 or later
 * Requires PHP: 8.0
 * Requires Plugins: ai-engine
 *
 * WHY THIS EXISTS
 *  The stock AI Engine MCP connector can manage posts, products-as-posts, meta,
 *  terms, media, options and blocks, but it cannot read or write files on the
 *  server, has no WooCommerce-aware product/order view, and cannot run updates.
 *  Those gaps block the theme scaffold and the hand-written product page
 *  (brief tasks 4 and 6) and make importer verification (task 5) guesswork.
 *  This plugin closes them by registering extra MCP tools through AI Engine's
 *  own filters (mwai_mcp_tools + mwai_mcp_callback).
 *
 *  The connector cannot install this itself — there is no file-write tool to
 *  bootstrap from. It is placed on the server once, by hand (see readme.txt).
 *  After that, plugin_write_file lets later tool groups be added over the wire.
 *
 * SAFETY MODEL
 *  - Theme writes are restricted to a single theme directory (default
 *    "astra-child", override with the BDZ_MCP_THEME_SLUG constant). The folder
 *    may not exist yet — the first write scaffolds it, which is exactly how the
 *    child theme this build needs gets created.
 *  - Plugin writes/downloads are restricted to wp-content/plugins.
 *  - Path traversal is blocked via realpath() containment checks.
 *  - plugin_download_url enforces https:// only, 12MB max, 60s timeout.
 *  - Each tool declares MCP annotations (readOnlyHint, destructiveHint).
 *  - Update tools wrap WordPress's own Plugin_Upgrader / Theme_Upgrader /
 *    Core_Upgrader.
 *  - Auth is handled by AI Engine's MCP layer (bearer token).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bedazzle_MCP {

	const DEFAULT_THEME_SLUG = 'astra-child';
	const PREFIX             = 'bdz_';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_filters' ) );
	}

	public function register_filters() {
		add_filter( 'mwai_mcp_tools',    array( $this, 'register_tools' ) );
		add_filter( 'mwai_mcp_callback', array( $this, 'handle_call' ), 10, 4 );
	}

	private function theme_slug() : string {
		$slug = defined( 'BDZ_MCP_THEME_SLUG' ) ? BDZ_MCP_THEME_SLUG : self::DEFAULT_THEME_SLUG;
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $slug ) ) {
			throw new Exception( 'Invalid theme slug configured: ' . $slug );
		}
		return $slug;
	}

	private function tools() {
		$theme_slug = defined( 'BDZ_MCP_THEME_SLUG' ) ? BDZ_MCP_THEME_SLUG : self::DEFAULT_THEME_SLUG;

		return array(

			self::PREFIX . 'mcp_ping' => array(
				'name'        => self::PREFIX . 'mcp_ping',
				'description' => 'Health check for the Bedazzle MCP tools. Returns the plugin version, AI Engine version, the active/child theme folders, and the names of every tool this plugin registers. Call this first after install to confirm the wiring works.',
				'inputSchema' => array( 'type' => 'object', 'properties' => (object) array() ),
			),

			self::PREFIX . 'woo_list_products' => array(
				'name'        => self::PREFIX . 'woo_list_products',
				'description' => 'List WooCommerce products. Returns id, name, slug, status, sku, price, stock_status, and category_ids. Use to verify an importer run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'category_slug' => array( 'type' => 'string',  'description' => 'Optional. Filter by product category slug.' ),
						'search'        => array( 'type' => 'string',  'description' => 'Optional. Keyword search across product fields.' ),
						'sku'           => array( 'type' => 'string',  'description' => 'Optional. Exact SKU to look up (e.g. "etsy-1234567890").' ),
						'status'        => array( 'type' => 'string',  'description' => 'Optional. publish | draft | any. Defaults to publish.' ),
						'limit'         => array( 'type' => 'integer', 'description' => 'Optional. Max products to return (1-200, default 50).' ),
					),
				),
			),

			self::PREFIX . 'woo_get_product' => array(
				'name'        => self::PREFIX . 'woo_get_product',
				'description' => 'Get full details for a single WooCommerce product by ID, including sku, prices, stock, images, categories/tags, and meta.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array( 'type' => 'integer', 'description' => 'The WooCommerce product ID.' ),
					),
					'required' => array( 'product_id' ),
				),
			),

			self::PREFIX . 'woo_update_product' => array(
				'name'        => self::PREFIX . 'woo_update_product',
				'description' => 'WRITE OPERATION. Update fields on a WooCommerce product. Always confirm with the user before calling.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id'        => array( 'type' => 'integer', 'description' => 'Product ID to update.' ),
						'name'              => array( 'type' => 'string',  'description' => 'Optional. New product name.' ),
						'description'       => array( 'type' => 'string',  'description' => 'Optional. Full description (HTML allowed, sanitized via wp_kses_post).' ),
						'short_description' => array( 'type' => 'string',  'description' => 'Optional. Short description.' ),
						'status'            => array( 'type' => 'string',  'description' => 'Optional. publish | draft | pending | private.' ),
						'regular_price'     => array( 'type' => 'string',  'description' => 'Optional. Regular price as string (e.g. "35.00").' ),
					),
					'required' => array( 'product_id' ),
				),
			),

			self::PREFIX . 'woo_list_categories' => array(
				'name'        => self::PREFIX . 'woo_list_categories',
				'description' => 'List all WooCommerce product categories with id, name, slug, parent, count, and description.',
				'inputSchema' => array( 'type' => 'object', 'properties' => (object) array() ),
			),

			self::PREFIX . 'woo_get_category' => array(
				'name'        => self::PREFIX . 'woo_get_category',
				'description' => 'Get details for a single WooCommerce product category by slug.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'slug' => array( 'type' => 'string', 'description' => 'The category slug.' ),
					),
					'required' => array( 'slug' ),
				),
			),

			self::PREFIX . 'woo_list_orders' => array(
				'name'        => self::PREFIX . 'woo_list_orders',
				'description' => 'List recent WooCommerce orders. Returns id, status, total, customer name/email, and date.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array( 'type' => 'string',  'description' => 'Optional. processing | on-hold | completed | pending | cancelled | refunded | failed | any. Defaults to processing.' ),
						'limit'  => array( 'type' => 'integer', 'description' => 'Optional. Max orders (1-100, default 25).' ),
					),
				),
			),

			self::PREFIX . 'woo_get_order' => array(
				'name'        => self::PREFIX . 'woo_get_order',
				'description' => 'Get full details for a single WooCommerce order including line items, billing, shipping, totals, and notes.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'order_id' => array( 'type' => 'integer', 'description' => 'The WooCommerce order ID.' ),
					),
					'required' => array( 'order_id' ),
				),
			),

			self::PREFIX . 'woo_update_order_status' => array(
				'name'        => self::PREFIX . 'woo_update_order_status',
				'description' => 'WRITE OPERATION. Change the status of a WooCommerce order.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'order_id' => array( 'type' => 'integer', 'description' => 'Order ID.' ),
						'status'   => array( 'type' => 'string',  'description' => 'New status: processing | on-hold | completed | pending | cancelled | refunded | failed.' ),
						'note'     => array( 'type' => 'string',  'description' => 'Optional. Note to add with the status change.' ),
					),
					'required' => array( 'order_id', 'status' ),
				),
			),

			self::PREFIX . 'woo_add_order_note' => array(
				'name'        => self::PREFIX . 'woo_add_order_note',
				'description' => 'Add a note to a WooCommerce order. Notes can be internal (private) or customer-visible.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'order_id'         => array( 'type' => 'integer', 'description' => 'Order ID.' ),
						'note'             => array( 'type' => 'string',  'description' => 'Note text.' ),
						'is_customer_note' => array( 'type' => 'boolean', 'description' => 'Optional. If true, customer is notified by email.' ),
					),
					'required' => array( 'order_id', 'note' ),
				),
			),

			self::PREFIX . 'theme_list_files' => array(
				'name'        => self::PREFIX . 'theme_list_files',
				'description' => 'List all files in the "' . $theme_slug . '" theme directory. Returns exists:false with an empty list if the theme has not been scaffolded yet.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'subdirectory' => array( 'type' => 'string', 'description' => 'Optional. List files within a subdirectory.' ),
					),
				),
			),

			self::PREFIX . 'theme_read_file' => array(
				'name'        => self::PREFIX . 'theme_read_file',
				'description' => 'Read a file in the "' . $theme_slug . '" theme directory.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'relative_path' => array( 'type' => 'string', 'description' => 'Path relative to the theme root.' ),
					),
					'required' => array( 'relative_path' ),
				),
			),

			self::PREFIX . 'theme_write_file' => array(
				'name'        => self::PREFIX . 'theme_write_file',
				'description' => 'WRITE OPERATION. Write contents to a file in the "' . $theme_slug . '" theme directory, creating the theme folder and any subfolders as needed. Overwrites the whole file — always send complete file contents.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'relative_path' => array( 'type' => 'string', 'description' => 'Path relative to the theme root, e.g. "style.css" or "woocommerce/single-product.php".' ),
						'contents'      => array( 'type' => 'string', 'description' => 'Full file contents to write.' ),
					),
					'required' => array( 'relative_path', 'contents' ),
				),
			),

			self::PREFIX . 'plugin_list_files' => array(
				'name'        => self::PREFIX . 'plugin_list_files',
				'description' => 'List files inside a plugin directory under wp-content/plugins.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'plugin_folder' => array( 'type' => 'string', 'description' => 'Optional. Plugin folder name. Omit to list all top-level plugin directories.' ),
					),
				),
			),

			self::PREFIX . 'plugin_read_file' => array(
				'name'        => self::PREFIX . 'plugin_read_file',
				'description' => 'Read a file from any plugin directory under wp-content/plugins.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'relative_path' => array( 'type' => 'string', 'description' => 'Path relative to wp-content/plugins.' ),
					),
					'required' => array( 'relative_path' ),
				),
			),

			self::PREFIX . 'plugin_write_file' => array(
				'name'        => self::PREFIX . 'plugin_write_file',
				'description' => 'WRITE OPERATION. Write contents to a file in any plugin directory under wp-content/plugins. Creates the file if it does not exist. Use this to add later tool groups to this plugin over the wire.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'relative_path' => array( 'type' => 'string', 'description' => 'Path relative to wp-content/plugins.' ),
						'contents'      => array( 'type' => 'string', 'description' => 'Full file contents to write.' ),
					),
					'required' => array( 'relative_path', 'contents' ),
				),
			),

			self::PREFIX . 'plugin_download_url' => array(
				'name'        => self::PREFIX . 'plugin_download_url',
				'description' => 'WRITE OPERATION. Server-side download a file from an https URL and save it inside wp-content/plugins. Useful for large files that exceed direct-write payload limits. Constraints: https only, 12MB max, 60s timeout. Returns bytes_written.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'url'           => array( 'type' => 'string', 'description' => 'Public https:// URL to fetch from.' ),
						'relative_path' => array( 'type' => 'string', 'description' => 'Target path relative to wp-content/plugins/.' ),
					),
					'required' => array( 'url', 'relative_path' ),
				),
			),

			self::PREFIX . 'wp_check_updates' => array(
				'name'        => self::PREFIX . 'wp_check_updates',
				'description' => 'Refresh and return available core, plugin, and theme updates. Read-only.',
				'inputSchema' => array( 'type' => 'object', 'properties' => (object) array() ),
			),

			self::PREFIX . 'wp_get_plugin_versions' => array(
				'name'        => self::PREFIX . 'wp_get_plugin_versions',
				'description' => 'List all installed plugins with current version, active status, and upgrade-target version.',
				'inputSchema' => array( 'type' => 'object', 'properties' => (object) array() ),
			),

			self::PREFIX . 'wp_update_plugin' => array(
				'name'        => self::PREFIX . 'wp_update_plugin',
				'description' => 'WRITE OPERATION. Update a single plugin via WordPress Plugin_Upgrader.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'plugin' => array( 'type' => 'string', 'description' => 'Plugin file (e.g. "woocommerce/woocommerce.php").' ),
					),
					'required' => array( 'plugin' ),
				),
			),

			self::PREFIX . 'wp_update_theme' => array(
				'name'        => self::PREFIX . 'wp_update_theme',
				'description' => 'WRITE OPERATION. Update a single theme via WordPress Theme_Upgrader.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'stylesheet' => array( 'type' => 'string', 'description' => 'Theme stylesheet folder name.' ),
					),
					'required' => array( 'stylesheet' ),
				),
			),

			self::PREFIX . 'wp_update_core' => array(
				'name'        => self::PREFIX . 'wp_update_core',
				'description' => 'WRITE OPERATION. Update WordPress core via Core_Upgrader.',
				'inputSchema' => array( 'type' => 'object', 'properties' => (object) array() ),
			),
		);
	}

	public function register_tools( $prev ) {
		if ( ! is_array( $prev ) ) {
			$prev = array();
		}
		$tools = $this->tools();

		foreach ( $tools as &$tool ) {
			$tool['category'] = 'Bedazzle';

			$name = $tool['name'];

			$is_readonly = (
				$name === self::PREFIX . 'mcp_ping'          ||
				strpos( $name, self::PREFIX . 'woo_list_'  ) === 0 ||
				strpos( $name, self::PREFIX . 'woo_get_'   ) === 0 ||
				$name === self::PREFIX . 'theme_list_files'  ||
				$name === self::PREFIX . 'theme_read_file'   ||
				$name === self::PREFIX . 'plugin_list_files' ||
				$name === self::PREFIX . 'plugin_read_file'  ||
				$name === self::PREFIX . 'wp_check_updates'  ||
				$name === self::PREFIX . 'wp_get_plugin_versions'
			);

			$is_destructive = (
				$name === self::PREFIX . 'theme_write_file'        ||
				$name === self::PREFIX . 'plugin_write_file'       ||
				$name === self::PREFIX . 'plugin_download_url'     ||
				$name === self::PREFIX . 'woo_update_product'      ||
				$name === self::PREFIX . 'woo_update_order_status' ||
				$name === self::PREFIX . 'wp_update_plugin'        ||
				$name === self::PREFIX . 'wp_update_theme'         ||
				$name === self::PREFIX . 'wp_update_core'
			);

			$tool['annotations'] = array(
				'readOnlyHint'    => $is_readonly,
				'destructiveHint' => $is_destructive,
				'openWorldHint'   => $name === self::PREFIX . 'plugin_download_url',
			);
		}
		unset( $tool );

		return array_merge( $prev, array_values( $tools ) );
	}

	public function handle_call( $prev, string $tool, array $args, ?int $id ) {

		if ( ! empty( $prev ) || ! isset( $this->tools()[ $tool ] ) ) {
			return $prev;
		}

		$response = array( 'jsonrpc' => '2.0', 'id' => $id );

		try {
			switch ( $tool ) {
				case self::PREFIX . 'mcp_ping':                $data = $this->mcp_ping(); break;
				case self::PREFIX . 'woo_list_products':       $data = $this->woo_list_products( $args ); break;
				case self::PREFIX . 'woo_get_product':         $data = $this->woo_get_product( $args ); break;
				case self::PREFIX . 'woo_update_product':      $data = $this->woo_update_product( $args ); break;
				case self::PREFIX . 'woo_list_categories':     $data = $this->woo_list_categories(); break;
				case self::PREFIX . 'woo_get_category':        $data = $this->woo_get_category( $args ); break;
				case self::PREFIX . 'woo_list_orders':         $data = $this->woo_list_orders( $args ); break;
				case self::PREFIX . 'woo_get_order':           $data = $this->woo_get_order( $args ); break;
				case self::PREFIX . 'woo_update_order_status': $data = $this->woo_update_order_status( $args ); break;
				case self::PREFIX . 'woo_add_order_note':      $data = $this->woo_add_order_note( $args ); break;
				case self::PREFIX . 'theme_list_files':        $data = $this->theme_list_files( $args ); break;
				case self::PREFIX . 'theme_read_file':         $data = $this->theme_read_file( $args ); break;
				case self::PREFIX . 'theme_write_file':        $data = $this->theme_write_file( $args ); break;
				case self::PREFIX . 'plugin_list_files':       $data = $this->plugin_list_files( $args ); break;
				case self::PREFIX . 'plugin_read_file':        $data = $this->plugin_read_file( $args ); break;
				case self::PREFIX . 'plugin_write_file':       $data = $this->plugin_write_file( $args ); break;
				case self::PREFIX . 'plugin_download_url':     $data = $this->plugin_download_url( $args ); break;
				case self::PREFIX . 'wp_check_updates':        $data = $this->wp_check_updates(); break;
				case self::PREFIX . 'wp_get_plugin_versions':  $data = $this->wp_get_plugin_versions(); break;
				case self::PREFIX . 'wp_update_plugin':        $data = $this->wp_update_plugin( $args ); break;
				case self::PREFIX . 'wp_update_theme':         $data = $this->wp_update_theme( $args ); break;
				case self::PREFIX . 'wp_update_core':          $data = $this->wp_update_core(); break;
				default:
					return $prev;
			}

			$response['result'] = array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
					),
				),
			);
		} catch ( Exception $e ) {
			$response['error'] = array(
				'code'    => -32603,
				'message' => $e->getMessage(),
			);
		}

		return $response;
	}

	private function mcp_ping() : array {
		$names = array();
		foreach ( $this->tools() as $def ) {
			$names[] = $def['name'];
		}
		return array(
			'plugin'       => 'Bedazzle MCP Tools',
			'version'      => '1.0.0',
			'ai_engine'    => defined( 'MWAI_VERSION' ) ? MWAI_VERSION : 'unknown',
			'woocommerce'  => class_exists( 'WooCommerce' ),
			'theme_slug'   => $this->theme_slug(),
			'active_theme' => array( 'stylesheet' => get_stylesheet(), 'template' => get_template() ),
			'theme_root'   => get_theme_root(),
			'tool_count'   => count( $names ),
			'tools'        => $names,
		);
	}

	private function require_woo() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			throw new Exception( 'WooCommerce is not active.' );
		}
	}

	private function woo_list_products( array $args ) : array {
		$this->require_woo();
		$limit  = isset( $args['limit'] )  ? max( 1, min( 200, (int) $args['limit'] ) ) : 50;
		$status = isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : 'publish';
		$query  = array( 'limit' => $limit, 'status' => $status );
		if ( ! empty( $args['category_slug'] ) ) {
			$query['category'] = array( sanitize_title( $args['category_slug'] ) );
		}
		if ( ! empty( $args['search'] ) ) {
			$query['s'] = sanitize_text_field( $args['search'] );
		}
		if ( ! empty( $args['sku'] ) ) {
			$query['sku'] = sanitize_text_field( $args['sku'] );
		}
		$products = wc_get_products( $query );
		$rows = array();
		foreach ( $products as $product ) {
			$rows[] = array(
				'id'           => $product->get_id(),
				'name'         => $product->get_name(),
				'slug'         => $product->get_slug(),
				'status'       => $product->get_status(),
				'sku'          => $product->get_sku(),
				'price'        => $product->get_price(),
				'stock_status' => $product->get_stock_status(),
				'category_ids' => $product->get_category_ids(),
				'permalink'    => get_permalink( $product->get_id() ),
			);
		}
		return array( 'count' => count( $rows ), 'products' => $rows );
	}

	private function woo_get_product( array $args ) : array {
		$this->require_woo();
		if ( empty( $args['product_id'] ) ) {
			throw new Exception( 'product_id is required.' );
		}
		$product = wc_get_product( (int) $args['product_id'] );
		if ( ! $product ) {
			throw new Exception( 'Product not found.' );
		}
		return array(
			'id'                => $product->get_id(),
			'name'              => $product->get_name(),
			'slug'              => $product->get_slug(),
			'status'            => $product->get_status(),
			'type'              => $product->get_type(),
			'description'       => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'sku'               => $product->get_sku(),
			'regular_price'     => $product->get_regular_price(),
			'sale_price'        => $product->get_sale_price(),
			'price'             => $product->get_price(),
			'stock_status'      => $product->get_stock_status(),
			'stock_quantity'    => $product->get_stock_quantity(),
			'weight'            => $product->get_weight(),
			'dimensions'        => array(
				'length' => $product->get_length(),
				'width'  => $product->get_width(),
				'height' => $product->get_height(),
			),
			'category_ids'      => $product->get_category_ids(),
			'tag_ids'           => $product->get_tag_ids(),
			'image_id'          => $product->get_image_id(),
			'gallery_image_ids' => $product->get_gallery_image_ids(),
			'permalink'         => get_permalink( $product->get_id() ),
			'meta_data'         => $this->flatten_meta( $product->get_meta_data() ),
		);
	}

	private function woo_update_product( array $args ) : array {
		$this->require_woo();
		if ( empty( $args['product_id'] ) ) {
			throw new Exception( 'product_id is required.' );
		}
		$product = wc_get_product( (int) $args['product_id'] );
		if ( ! $product ) {
			throw new Exception( 'Product not found.' );
		}
		$changed = array();
		if ( isset( $args['name'] ) )              { $product->set_name( sanitize_text_field( $args['name'] ) ); $changed[] = 'name'; }
		if ( isset( $args['description'] ) )       { $product->set_description( wp_kses_post( $args['description'] ) ); $changed[] = 'description'; }
		if ( isset( $args['short_description'] ) ) { $product->set_short_description( wp_kses_post( $args['short_description'] ) ); $changed[] = 'short_description'; }
		if ( isset( $args['status'] ) )            { $product->set_status( sanitize_text_field( $args['status'] ) ); $changed[] = 'status'; }
		if ( isset( $args['regular_price'] ) )     { $product->set_regular_price( sanitize_text_field( $args['regular_price'] ) ); $changed[] = 'regular_price'; }
		$product->save();
		return array( 'success' => true, 'product_id' => $product->get_id(), 'fields_updated' => $changed );
	}

	private function woo_list_categories() : array {
		$this->require_woo();
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			throw new Exception( $terms->get_error_message() );
		}
		$rows = array();
		foreach ( $terms as $term ) {
			$rows[] = array( 'id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'parent' => $term->parent, 'count' => $term->count, 'description' => $term->description );
		}
		return array( 'count' => count( $rows ), 'categories' => $rows );
	}

	private function woo_get_category( array $args ) : array {
		$this->require_woo();
		if ( empty( $args['slug'] ) ) {
			throw new Exception( 'slug is required.' );
		}
		$term = get_term_by( 'slug', sanitize_title( $args['slug'] ), 'product_cat' );
		if ( ! $term ) {
			throw new Exception( 'Category not found.' );
		}
		$link = get_term_link( $term );
		return array(
			'id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'parent' => $term->parent,
			'count' => $term->count, 'description' => $term->description,
			'permalink' => is_wp_error( $link ) ? null : $link,
		);
	}

	private function woo_list_orders( array $args ) : array {
		$this->require_woo();
		$limit  = isset( $args['limit'] )  ? max( 1, min( 100, (int) $args['limit'] ) ) : 25;
		$status = isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : 'processing';
		$orders = wc_get_orders( array( 'limit' => $limit, 'status' => $status ) );
		$rows = array();
		foreach ( $orders as $order ) {
			$rows[] = array(
				'id'             => $order->get_id(),
				'status'         => $order->get_status(),
				'total'          => $order->get_total(),
				'currency'       => $order->get_currency(),
				'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'customer_email' => $order->get_billing_email(),
				'date_created'   => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
			);
		}
		return array( 'count' => count( $rows ), 'orders' => $rows );
	}

	private function woo_get_order( array $args ) : array {
		$this->require_woo();
		if ( empty( $args['order_id'] ) ) {
			throw new Exception( 'order_id is required.' );
		}
		$order = wc_get_order( (int) $args['order_id'] );
		if ( ! $order ) {
			throw new Exception( 'Order not found.' );
		}
		$line_items = array();
		foreach ( $order->get_items() as $item ) {
			$line_items[] = array(
				'product_id' => $item->get_product_id(),
				'name'       => $item->get_name(),
				'quantity'   => $item->get_quantity(),
				'subtotal'   => $item->get_subtotal(),
				'total'      => $item->get_total(),
			);
		}
		$notes = array();
		if ( function_exists( 'wc_get_order_notes' ) ) {
			foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
				$notes[] = array(
					'id'            => $note->id,
					'date_created'  => $note->date_created ? $note->date_created->date( 'c' ) : null,
					'content'       => $note->content,
					'customer_note' => (bool) $note->customer_note,
					'added_by'      => $note->added_by,
				);
			}
		}
		return array(
			'id'             => $order->get_id(),
			'status'         => $order->get_status(),
			'total'          => $order->get_total(),
			'currency'       => $order->get_currency(),
			'date_created'   => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
			'billing'        => $order->get_address( 'billing' ),
			'shipping'       => $order->get_address( 'shipping' ),
			'payment_method' => $order->get_payment_method_title(),
			'line_items'     => $line_items,
			'notes'          => $notes,
		);
	}

	private function woo_update_order_status( array $args ) : array {
		$this->require_woo();
		if ( empty( $args['order_id'] ) || empty( $args['status'] ) ) {
			throw new Exception( 'order_id and status are required.' );
		}
		$order = wc_get_order( (int) $args['order_id'] );
		if ( ! $order ) {
			throw new Exception( 'Order not found.' );
		}
		$order->update_status( sanitize_text_field( $args['status'] ), isset( $args['note'] ) ? sanitize_textarea_field( $args['note'] ) : '' );
		return array( 'success' => true, 'order_id' => $order->get_id(), 'status' => $order->get_status() );
	}

	private function woo_add_order_note( array $args ) : array {
		$this->require_woo();
		if ( empty( $args['order_id'] ) || empty( $args['note'] ) ) {
			throw new Exception( 'order_id and note are required.' );
		}
		$order = wc_get_order( (int) $args['order_id'] );
		if ( ! $order ) {
			throw new Exception( 'Order not found.' );
		}
		$is_customer_note = ! empty( $args['is_customer_note'] ) ? 1 : 0;
		$note_id = $order->add_order_note( sanitize_textarea_field( $args['note'] ), $is_customer_note, false );
		return array( 'success' => (bool) $note_id, 'note_id' => $note_id );
	}

	/**
	 * Resolve a caller path against the scoped theme directory. The theme folder
	 * may not exist yet (first write scaffolds it), so containment is checked
	 * against the nearest existing ancestor's realpath plus a lexical check
	 * against the allowed theme directory.
	 */
	private function safe_theme_path( string $relative_path ) : string {
		$real_root = realpath( get_theme_root() );
		if ( ! $real_root ) {
			throw new Exception( 'Theme root not found.' );
		}
		$allowed = $real_root . DIRECTORY_SEPARATOR . $this->theme_slug();

		$relative_path = ltrim( $relative_path, '/\\' );
		if ( '' === $relative_path || strpos( $relative_path, '..' ) !== false || strpos( $relative_path, "\0" ) !== false ) {
			throw new Exception( 'Illegal path.' );
		}

		$full = $allowed . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );

		// Lexical containment within the allowed theme directory.
		if ( strpos( $full, $allowed . DIRECTORY_SEPARATOR ) !== 0 ) {
			throw new Exception( 'Path is outside the allowed theme directory.' );
		}

		// Nearest existing ancestor must physically resolve inside the theme root.
		$probe = $full;
		while ( $probe && ! file_exists( $probe ) ) {
			$parent = dirname( $probe );
			if ( $parent === $probe ) {
				break;
			}
			$probe = $parent;
		}
		$real_probe = realpath( $probe );
		if ( ! $real_probe || strpos( $real_probe, $real_root ) !== 0 ) {
			throw new Exception( 'Path is outside the theme root.' );
		}

		return $full;
	}

	private function theme_list_files( array $args ) : array {
		$real_root = realpath( get_theme_root() );
		if ( ! $real_root ) {
			throw new Exception( 'Theme root not found.' );
		}
		$allowed = $real_root . DIRECTORY_SEPARATOR . $this->theme_slug();

		if ( ! is_dir( $allowed ) ) {
			return array( 'theme' => $this->theme_slug(), 'exists' => false, 'count' => 0, 'files' => array() );
		}

		$start_dir = $allowed;
		if ( ! empty( $args['subdirectory'] ) ) {
			$start_dir = $this->safe_theme_path( $args['subdirectory'] );
			if ( ! is_dir( $start_dir ) ) {
				throw new Exception( 'Subdirectory not found.' );
			}
		}

		$files    = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $start_dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$rel = str_replace( $allowed . DIRECTORY_SEPARATOR, '', $file->getPathname() );
				$files[] = array( 'path' => str_replace( '\\', '/', $rel ), 'size_bytes' => $file->getSize(), 'last_modified' => date( 'c', $file->getMTime() ) );
			}
		}
		return array( 'theme' => $this->theme_slug(), 'exists' => true, 'count' => count( $files ), 'files' => $files );
	}

	private function theme_read_file( array $args ) : array {
		if ( empty( $args['relative_path'] ) ) {
			throw new Exception( 'relative_path is required.' );
		}
		$full_path = $this->safe_theme_path( $args['relative_path'] );
		if ( ! file_exists( $full_path ) ) {
			throw new Exception( 'File not found: ' . $args['relative_path'] );
		}
		$contents = file_get_contents( $full_path );
		if ( $contents === false ) {
			throw new Exception( 'Could not read file.' );
		}
		return array( 'relative_path' => $args['relative_path'], 'size_bytes' => filesize( $full_path ), 'last_modified' => date( 'c', filemtime( $full_path ) ), 'contents' => $contents );
	}

	private function theme_write_file( array $args ) : array {
		if ( empty( $args['relative_path'] ) || ! isset( $args['contents'] ) ) {
			throw new Exception( 'relative_path and contents are required.' );
		}
		$full_path = $this->safe_theme_path( $args['relative_path'] );
		$dir = dirname( $full_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$existed = file_exists( $full_path );
		$bytes = file_put_contents( $full_path, $args['contents'] );
		if ( $bytes === false ) {
			throw new Exception( 'Could not write file.' );
		}
		return array( 'success' => true, 'relative_path' => $args['relative_path'], 'action' => $existed ? 'updated' : 'created', 'bytes_written' => $bytes );
	}

	private function safe_plugin_path( string $relative_path ) : string {
		$plugins_root = realpath( WP_PLUGIN_DIR );
		if ( ! $plugins_root ) {
			throw new Exception( 'Could not resolve plugins directory.' );
		}
		$relative_path = ltrim( $relative_path, '/\\' );
		if ( strpos( $relative_path, '..' ) !== false ) {
			throw new Exception( 'Path traversal not allowed.' );
		}
		$full_path = $plugins_root . DIRECTORY_SEPARATOR . $relative_path;
		$check = file_exists( $full_path ) ? realpath( $full_path ) : realpath( dirname( $full_path ) );
		if ( ! $check || strpos( $check, $plugins_root ) !== 0 ) {
			throw new Exception( 'Path is outside the plugins directory.' );
		}
		return $full_path;
	}

	private function plugin_list_files( array $args ) : array {
		$plugins_root = realpath( WP_PLUGIN_DIR );
		if ( ! $plugins_root ) {
			throw new Exception( 'Could not resolve plugins directory.' );
		}
		if ( empty( $args['plugin_folder'] ) ) {
			$dirs = glob( $plugins_root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR );
			$out = array();
			foreach ( $dirs as $dir ) {
				$out[] = basename( $dir );
			}
			return array( 'plugin_folders' => $out, 'count' => count( $out ) );
		}
		$start_dir = $this->safe_plugin_path( sanitize_text_field( $args['plugin_folder'] ) );
		if ( ! is_dir( $start_dir ) ) {
			throw new Exception( 'Plugin folder not found: ' . $args['plugin_folder'] );
		}
		$files = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $start_dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$rel = str_replace( $plugins_root . DIRECTORY_SEPARATOR, '', $file->getPathname() );
				$files[] = array( 'path' => str_replace( '\\', '/', $rel ), 'size_bytes' => $file->getSize(), 'last_modified' => date( 'c', $file->getMTime() ) );
			}
		}
		return array( 'count' => count( $files ), 'files' => $files );
	}

	private function plugin_read_file( array $args ) : array {
		if ( empty( $args['relative_path'] ) ) {
			throw new Exception( 'relative_path is required.' );
		}
		$full_path = $this->safe_plugin_path( $args['relative_path'] );
		if ( ! file_exists( $full_path ) ) {
			throw new Exception( 'File not found: ' . $args['relative_path'] );
		}
		$contents = file_get_contents( $full_path );
		if ( $contents === false ) {
			throw new Exception( 'Could not read file.' );
		}
		return array( 'relative_path' => $args['relative_path'], 'size_bytes' => filesize( $full_path ), 'last_modified' => date( 'c', filemtime( $full_path ) ), 'contents' => $contents );
	}

	private function plugin_write_file( array $args ) : array {
		if ( empty( $args['relative_path'] ) || ! isset( $args['contents'] ) ) {
			throw new Exception( 'relative_path and contents are required.' );
		}
		$full_path = $this->safe_plugin_path( $args['relative_path'] );
		$dir = dirname( $full_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$bytes = file_put_contents( $full_path, $args['contents'] );
		if ( $bytes === false ) {
			throw new Exception( 'Could not write file.' );
		}
		return array( 'success' => true, 'relative_path' => $args['relative_path'], 'bytes_written' => $bytes );
	}

	/**
	 * Fetch from a public https URL and write into wp-content/plugins.
	 * Caps: 12MB, 60s, https only.
	 */
	private function plugin_download_url( array $args ) : array {
		if ( empty( $args['url'] ) || empty( $args['relative_path'] ) ) {
			throw new Exception( 'url and relative_path are required.' );
		}

		$url = esc_url_raw( $args['url'], array( 'https' ) );
		if ( ! $url || strpos( $url, 'https://' ) !== 0 ) {
			throw new Exception( 'Only https:// URLs are allowed.' );
		}

		$full_path = $this->safe_plugin_path( $args['relative_path'] );

		$response = wp_safe_remote_get( $url, array(
			'timeout'     => 60,
			'redirection' => 3,
			'sslverify'   => true,
		) );

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Fetch failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			throw new Exception( 'Fetch returned HTTP ' . $code );
		}

		$body = wp_remote_retrieve_body( $response );
		$len  = strlen( $body );

		if ( $len === 0 ) {
			throw new Exception( 'Response body is empty.' );
		}
		if ( $len > 12 * 1024 * 1024 ) {
			throw new Exception( 'Response too large (>12MB).' );
		}

		$dir = dirname( $full_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$bytes = file_put_contents( $full_path, $body );
		if ( $bytes === false ) {
			throw new Exception( 'Could not write file. Check filesystem permissions.' );
		}

		return array(
			'success'       => true,
			'relative_path' => $args['relative_path'],
			'source_url'    => $url,
			'bytes_written' => $bytes,
			'http_status'   => $code,
		);
	}

	private function require_upgrader() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-core-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
		require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		if ( ! WP_Filesystem() ) {
			throw new Exception( 'Could not initialize WP_Filesystem.' );
		}
	}

	private function wp_check_updates() : array {
		$this->require_upgrader();
		wp_update_plugins();
		wp_update_themes();
		wp_version_check();
		$plugin_updates = get_site_transient( 'update_plugins' );
		$theme_updates  = get_site_transient( 'update_themes' );
		$all_plugins = get_plugins();
		$plugins = array();
		if ( isset( $plugin_updates->response ) && is_array( $plugin_updates->response ) ) {
			foreach ( $plugin_updates->response as $file => $info ) {
				$plugins[] = array(
					'plugin'    => $file,
					'name'      => isset( $all_plugins[ $file ]['Name'] ) ? $all_plugins[ $file ]['Name'] : $file,
					'current'   => isset( $all_plugins[ $file ]['Version'] ) ? $all_plugins[ $file ]['Version'] : '?',
					'available' => isset( $info->new_version ) ? $info->new_version : '?',
				);
			}
		}
		$themes = array();
		if ( isset( $theme_updates->response ) && is_array( $theme_updates->response ) ) {
			foreach ( $theme_updates->response as $stylesheet => $info ) {
				$theme = wp_get_theme( $stylesheet );
				$themes[] = array(
					'stylesheet' => $stylesheet,
					'name'       => $theme->get( 'Name' ),
					'current'    => $theme->get( 'Version' ),
					'available'  => isset( $info['new_version'] ) ? $info['new_version'] : '?',
				);
			}
		}
		$core = null;
		$core_updates = get_core_updates();
		if ( is_array( $core_updates ) ) {
			foreach ( $core_updates as $u ) {
				if ( isset( $u->response ) && $u->response === 'upgrade' ) {
					global $wp_version;
					$core = array(
						'current'                => $wp_version,
						'available'              => $u->version,
						'php_version_required'   => isset( $u->php_version ) ? $u->php_version : null,
						'mysql_version_required' => isset( $u->mysql_version ) ? $u->mysql_version : null,
					);
					break;
				}
			}
		}
		return array(
			'core_update'    => $core,
			'plugin_updates' => $plugins,
			'theme_updates'  => $themes,
			'plugin_count'   => count( $plugins ),
			'theme_count'    => count( $themes ),
			'core_available' => $core !== null,
		);
	}

	private function wp_get_plugin_versions() : array {
		if ( ! function_exists( 'get_plugins' ) )       require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! function_exists( 'wp_update_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/update.php';
		wp_update_plugins();
		$updates = get_site_transient( 'update_plugins' );
		$update_response = isset( $updates->response ) ? $updates->response : array();
		$all = get_plugins();
		$rows = array();
		foreach ( $all as $file => $data ) {
			$target = null;
			if ( isset( $update_response[ $file ] ) && isset( $update_response[ $file ]->new_version ) ) {
				$target = $update_response[ $file ]->new_version;
			}
			$rows[] = array(
				'plugin'           => $file,
				'name'             => $data['Name'],
				'version'          => $data['Version'],
				'active'           => is_plugin_active( $file ),
				'update_available' => $target,
			);
		}
		return array( 'count' => count( $rows ), 'plugins' => $rows );
	}

	private function wp_update_plugin( array $args ) : array {
		if ( empty( $args['plugin'] ) ) {
			throw new Exception( 'plugin is required.' );
		}
		$this->require_upgrader();
		wp_update_plugins();
		$plugin = sanitize_text_field( $args['plugin'] );
		$all = get_plugins();
		if ( ! isset( $all[ $plugin ] ) ) {
			throw new Exception( 'Plugin not found: ' . $plugin );
		}
		$before = $all[ $plugin ]['Version'];
		$skin = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result = $upgrader->upgrade( $plugin );
		if ( is_wp_error( $result ) ) throw new Exception( 'Upgrade failed: ' . $result->get_error_message() );
		if ( $result === false )      throw new Exception( 'Upgrade returned false.' );
		if ( $result === null )       throw new Exception( 'Upgrade returned null.' );
		wp_clean_plugins_cache();
		$all_after = get_plugins();
		$after = isset( $all_after[ $plugin ]['Version'] ) ? $all_after[ $plugin ]['Version'] : '?';
		return array( 'success' => true, 'plugin' => $plugin, 'before' => $before, 'after' => $after, 'messages' => $skin->get_upgrade_messages() );
	}

	private function wp_update_theme( array $args ) : array {
		if ( empty( $args['stylesheet'] ) ) {
			throw new Exception( 'stylesheet is required.' );
		}
		$this->require_upgrader();
		wp_update_themes();
		$stylesheet = sanitize_text_field( $args['stylesheet'] );
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			throw new Exception( 'Theme not found: ' . $stylesheet );
		}
		$before = $theme->get( 'Version' );
		$skin = new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result = $upgrader->upgrade( $stylesheet );
		if ( is_wp_error( $result ) ) throw new Exception( 'Upgrade failed: ' . $result->get_error_message() );
		if ( $result === false )      throw new Exception( 'Upgrade returned false.' );
		wp_clean_themes_cache();
		$theme_after = wp_get_theme( $stylesheet );
		return array( 'success' => true, 'stylesheet' => $stylesheet, 'before' => $before, 'after' => $theme_after->get( 'Version' ), 'messages' => $skin->get_upgrade_messages() );
	}

	private function wp_update_core() : array {
		$this->require_upgrader();
		wp_version_check();
		$updates = get_core_updates();
		if ( empty( $updates ) || ! is_array( $updates ) ) {
			throw new Exception( 'No core update info available.' );
		}
		$update = false;
		foreach ( $updates as $u ) {
			if ( isset( $u->response ) && $u->response === 'upgrade' ) { $update = $u; break; }
		}
		if ( ! $update ) {
			global $wp_version;
			return array( 'success' => true, 'message' => 'Core is already up to date.', 'version' => $wp_version );
		}
		global $wp_version;
		$before = $wp_version;
		$skin = new Automatic_Upgrader_Skin();
		$upgrader = new Core_Upgrader( $skin );
		$result = $upgrader->upgrade( $update );
		if ( is_wp_error( $result ) ) throw new Exception( 'Core upgrade failed: ' . $result->get_error_message() );
		return array( 'success' => true, 'before' => $before, 'after' => is_string( $result ) ? $result : 'see messages', 'messages' => $skin->get_upgrade_messages() );
	}

	private function flatten_meta( $meta_data ) : array {
		$out = array();
		foreach ( $meta_data as $meta ) {
			$data = $meta->get_data();
			$out[ $data['key'] ] = $data['value'];
		}
		return $out;
	}
}

new Bedazzle_MCP();
