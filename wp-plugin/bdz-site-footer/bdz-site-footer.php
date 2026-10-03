<?php
/**
 * Plugin Name: Bedazzle Site Footer
 * Description: Replaces Astra's "Powered by Astra WordPress Theme" credit in the footer bar with the shop's own footer line.
 * Version:     1.0.0
 * Author:      Bedazzle Book Kits
 *
 * @package bedazzle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Astra's legacy footer bar (is-header-footer-builder = false) reads its credit
 * through astra_get_option(), which applies an "astra_get_option_{$key}" filter
 * before returning. Filtering there replaces the credit without writing to the
 * astra-settings option — so every other customizer setting is left alone, and
 * an Astra update cannot overwrite this the way a theme file edit would.
 *
 * Astra substitutes [current_year] and [site_title] itself, then runs the
 * result through wp_kses_post(), which keeps these links and their attributes.
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
