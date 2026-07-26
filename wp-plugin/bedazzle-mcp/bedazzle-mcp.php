<?php
/**
 * Plugin Name: Bedazzle MCP Extension
 * Description: Adds site-management tools to the AI Engine MCP connector for bedazzlekits.com. First move: theme file access (list/read/write), which the stock AI Engine MCP does not provide. Registers through AI Engine's mwai_mcp_tools / mwai_mcp_callback filters.
 * Version: 0.1.0
 * Author: HarleysBooks build
 * Requires Plugins: ai-engine
 *
 * Design notes
 * ------------
 * The stock AI Engine MCP exposes posts, meta, terms, media, options and blocks,
 * but no way to read or write files on the server. That single gap blocks the
 * theme scaffold and the hand-written product page (brief tasks 4 and 6). This
 * plugin closes it and nothing else yet — more tool groups (WooCommerce product
 * views, nav menus, shipping zones, Astra settings) are added later as separate
 * files under inc/, one deliberate move at a time.
 *
 * No dependencies beyond WordPress + AI Engine. Plain PHP, no build step.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BDZ_MCP_VERSION', '0.1.0' );
define( 'BDZ_MCP_DIR', plugin_dir_path( __FILE__ ) );

require_once BDZ_MCP_DIR . 'inc/class-bdz-theme-files.php';

/**
 * The registry of tool handlers. Each entry is an object exposing:
 *   ->tools()                       array of AI Engine tool definitions
 *   ->handle( $tool, $args )        mixed result, or null if not ours
 */
function bdz_mcp_handlers() {
	static $handlers = null;
	if ( null === $handlers ) {
		$handlers = array(
			new BDZ_Theme_Files(),
		);
	}
	return $handlers;
}

/**
 * Register our tools with AI Engine's MCP surface.
 *
 * @param array $tools Existing MCP tool definitions.
 * @return array
 */
add_filter( 'mwai_mcp_tools', function ( $tools ) {
	if ( ! is_array( $tools ) ) {
		$tools = array();
	}

	// Self-diagnostic tool: confirms the extension is loaded and lists what it added.
	$tools[] = array(
		'name'        => 'bdz_mcp_ping',
		'description' => 'Bedazzle MCP extension health check. Returns the extension version and the names of every tool this extension registers. Call this first after install to confirm the wiring works.',
		'inputSchema' => array(
			'type'       => 'object',
			'properties' => new stdClass(),
		),
	);

	foreach ( bdz_mcp_handlers() as $handler ) {
		foreach ( $handler->tools() as $tool ) {
			$tools[] = $tool;
		}
	}

	return $tools;
} );

/**
 * Execute our tools.
 *
 * AI Engine applies this filter as:
 *   apply_filters( 'mwai_mcp_callback', $result, $tool, $args, $id )
 * We only touch $result for tools we own; anything else is passed through
 * untouched so other handlers (and AI Engine's built-ins) still work.
 *
 * @param mixed  $result Result so far (null until a handler claims the call).
 * @param string $tool   Tool name being invoked.
 * @param array  $args   Arguments supplied by the caller.
 * @param mixed  $id     Request id (unused here).
 * @return mixed
 */
add_filter( 'mwai_mcp_callback', function ( $result, $tool = '', $args = array(), $id = null ) {
	if ( ! is_string( $tool ) || '' === $tool ) {
		return $result;
	}
	if ( ! is_array( $args ) ) {
		$args = array();
	}

	// Gate every tool this extension adds behind an editor-level capability.
	// AI Engine already authenticates the MCP caller; this is defence in depth.
	$owned = ( 'bdz_mcp_ping' === $tool ) || bdz_mcp_owns_tool( $tool );
	if ( $owned && ! current_user_can( 'edit_themes' ) && ! current_user_can( 'manage_options' ) ) {
		return array( 'error' => 'Insufficient capability for ' . $tool . ' (requires edit_themes/manage_options).' );
	}

	if ( 'bdz_mcp_ping' === $tool ) {
		return bdz_mcp_ping_payload();
	}

	foreach ( bdz_mcp_handlers() as $handler ) {
		$handled = $handler->handle( $tool, $args );
		if ( null !== $handled ) {
			return $handled;
		}
	}

	return $result;
}, 10, 4 );

/**
 * Does any registered handler own this tool name?
 */
function bdz_mcp_owns_tool( $tool ) {
	foreach ( bdz_mcp_handlers() as $handler ) {
		foreach ( $handler->tools() as $def ) {
			if ( isset( $def['name'] ) && $def['name'] === $tool ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Payload for the health-check tool.
 */
function bdz_mcp_ping_payload() {
	$names = array( 'bdz_mcp_ping' );
	foreach ( bdz_mcp_handlers() as $handler ) {
		foreach ( $handler->tools() as $def ) {
			if ( isset( $def['name'] ) ) {
				$names[] = $def['name'];
			}
		}
	}
	return array(
		'extension'      => 'Bedazzle MCP Extension',
		'version'        => BDZ_MCP_VERSION,
		'ai_engine'      => defined( 'MWAI_VERSION' ) ? MWAI_VERSION : 'unknown',
		'active_theme'   => array(
			'stylesheet' => get_stylesheet(), // child (or active) theme folder
			'template'   => get_template(),   // parent theme folder
		),
		'theme_root'     => get_theme_root(),
		'tools'          => $names,
	);
}
