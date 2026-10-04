<?php
/**
 * Plugin Name: Bedazzle Site Tweaks
 * Description: Small Astra option overrides applied by filter: the footer bar credit, and the order of the single-product summary.
 * Version:     1.1.0
 * Author:      Bedazzle Book Kits
 *
 * @package bedazzle
 *
 * Installed as wp-content/plugins/bdz-site-footer.php. The filename predates
 * the second filter; it is kept because the MCP tooling here can write plugin
 * files but not delete them, so renaming would strand the old file.
 *
 * Astra reads its settings through astra_get_option(), which ends in
 *
 *     apply_filters( "astra_get_option_{$option}", $value, $option, $default );
 *
 * (inc/core/common-functions.php:662 in 4.13.11). Filtering there changes one
 * value and touches nothing else. The alternative — writing the astra-settings
 * option — replaces one serialised array holding every Astra and Astra Pro
 * setting, and Astra falls back to its defaults for any key missing from what
 * is written, so a single slip silently resets the site's customizer settings.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Footer bar credit, replacing "Powered by Astra WordPress Theme".
 *
 * Astra substitutes [current_year] and [site_title] itself, then runs the
 * result through wp_kses_post(), which keeps these links and their attributes.
 *
 * Applies to the legacy footer bar only. The site has
 * is-header-footer-builder = false; if that is ever turned on, Astra renders
 * the footer through the builder and reads footer-copyright-editor instead.
 *
 * @return string Footer credit markup.
 */
function bdz_site_footer_credit() {
	$links = array(
		'<a href="' . esc_url( home_url( '/shop/' ) ) . '">Shop</a>',
		'<a href="' . esc_url( home_url( '/about/' ) ) . '">About</a>',
		'<a href="' . esc_url( home_url( '/contact/' ) ) . '">Contact</a>',
		'<a href="https://www.instagram.com/harleysbooks" target="_blank" rel="noopener">Instagram</a>',
		'<a href="https://www.tiktok.com/@harleysbooktok" target="_blank" rel="noopener">TikTok</a>',
	);

	return '&copy; [current_year] [site_title] &middot; ' . implode( ' &middot; ', $links );
}
add_filter( 'astra_get_option_footer-sml-section-1-credit', 'bdz_site_footer_credit' );

/**
 * Order of the blocks in the single-product summary.
 *
 * The stored value ends "...short_desc, add_cart, price", which puts the
 * price block *below* the add-to-cart button. Every product here is a
 * variable product, so the form already prints the selected variation's
 * price — leaving a second, lower price under the button. Moving price up
 * ahead of the description gives the usual read: what it is, what it costs,
 * what you get, then buy.
 *
 * Delete this filter to go back to the stored order.
 *
 * @return string[] Summary block order.
 */
function bdz_single_product_structure() {
	return array( 'meta', 'title', 'ratings', 'price', 'short_desc', 'add_cart' );
}
add_filter( 'astra_get_option_single-product-structure', 'bdz_single_product_structure' );
