<?php
/**
 * Etsy -> neutral catalogue item.
 *
 * This is the ONE place in the plugin where raw Etsy field names are allowed to
 * appear. If Etsy's v3 fields drift, fix them here and nowhere else — the same
 * rule the command-line importer follows, so the two cannot diverge.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
