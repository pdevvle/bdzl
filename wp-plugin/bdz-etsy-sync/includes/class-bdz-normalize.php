<?php
/**
 * Etsy -> neutral catalogue item.
 *
 * This is the ONE place in the plugin where raw Etsy field names are allowed to
 * appear. If Etsy's v3 fields drift, fix them here and nowhere else — the same
 * rule the command-line importer follows, so the two cannot diverge.
 *
 * On variations: the live catalogue turned out to be almost entirely
 * variable — Book crossed with Amount of Gems, or Book crossed with Tools on
 * the spine-only kits, with price varying by property. An Etsy inventory
 * "product" is one combination of property values carrying its own offering;
 * that maps onto a WooCommerce variation, and the distinct property values map
 * onto WooCommerce attributes.
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
	 * Pull the attribute set and the variant list out of an Etsy inventory
	 * payload.
	 *
	 * Only enabled offerings become variants, and an attribute only offers a
	 * value that some enabled variant actually uses — otherwise the storefront
	 * shows a choice that cannot be bought.
	 *
	 * @return array { attributes: [{name, options[]}], variants: [...],
	 *                 property_names: [], offering_count: int,
	 *                 disabled_count: int }
	 */
	public static function variants( $listing_id, $inventory ) {
		$attributes     = array();
		$variants       = array();
		$property_names = array();
		$offering_count = 0;
		$disabled_count = 0;
		$min_price      = null;

		if ( ! is_array( $inventory ) || empty( $inventory['products'] ) ) {
			return compact( 'attributes', 'variants', 'property_names', 'offering_count', 'disabled_count', 'min_price' );
		}

		foreach ( $inventory['products'] as $product ) {
			$values = array();
			foreach ( ( isset( $product['property_values'] ) ? $product['property_values'] : array() ) as $property ) {
				$name  = isset( $property['property_name'] ) ? trim( (string) $property['property_name'] ) : '';
				$value = ( isset( $property['values'][0] ) ) ? trim( (string) $property['values'][0] ) : '';
				if ( '' === $name || '' === $value ) {
					continue;
				}
				$values[ $name ] = $value;
				if ( ! in_array( $name, $property_names, true ) ) {
					$property_names[] = $name;
				}
			}

			$offerings = isset( $product['offerings'] ) ? $product['offerings'] : array();
			if ( $offerings ) {
				$offering_count++;
			}

			foreach ( $offerings as $offering ) {
				$enabled = ! isset( $offering['is_enabled'] ) || $offering['is_enabled'];
				if ( ! $enabled ) {
					$disabled_count++;
					continue;
				}

				list( $price ) = self::money( isset( $offering['price'] ) ? $offering['price'] : null );
				if ( null === $price ) {
					continue;
				}

				if ( null === $min_price || $price < $min_price ) {
					$min_price = $price;
				}

				// A variant describes a choice. An offering with no property
				// values is not one — it is just the listing's own price, and
				// recording it as a variant would model an empty selection.
				if ( ! $values ) {
					break;
				}

				$variants[] = array(
					// Stable and unique: the Etsy inventory product id never
					// changes for a given combination, so re-runs update the
					// same variation rather than churning them.
					'sku'        => sprintf(
						'%d-%d',
						$listing_id,
						isset( $product['product_id'] ) ? (int) $product['product_id'] : count( $variants ) + 1
					),
					'attributes' => $values,
					'price'      => number_format( $price, 2, '.', '' ),
					'quantity'   => isset( $offering['quantity'] ) ? (int) $offering['quantity'] : 0,
				);

				foreach ( $values as $name => $value ) {
					if ( ! isset( $attributes[ $name ] ) ) {
						$attributes[ $name ] = array();
					}
					if ( ! in_array( $value, $attributes[ $name ], true ) ) {
						$attributes[ $name ][] = $value;
					}
				}

				// Etsy carries one offering per inventory product in practice;
				// a second would have no distinct combination to sit on.
				break;
			}
		}

		$attribute_list = array();
		foreach ( $attributes as $name => $options ) {
			if ( $options ) {
				$attribute_list[] = array( 'name' => $name, 'options' => array_values( $options ) );
			}
		}

		return array(
			'attributes'     => $attribute_list,
			'variants'       => $variants,
			'property_names' => $property_names,
			'offering_count' => $offering_count,
			'disabled_count' => $disabled_count,
			'min_price'      => $min_price,
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

		$parsed     = self::variants( $listing_id, $inventory );
		$variants   = $parsed['variants'];
		$attributes = $parsed['attributes'];

		// A listing is variable when there is a real choice to make: more than
		// one buyable combination, described by at least one attribute.
		$is_variable = ( count( $variants ) > 1 && $attributes );

		// Without a listing-level price, fall back to the cheapest buyable
		// offering. For a variable product this only seeds the parent; each
		// variation carries its own price.
		if ( null === $price && null !== $parsed['min_price'] ) {
			$price = $parsed['min_price'];
		}

		$price_on_property    = ( is_array( $inventory ) && ! empty( $inventory['price_on_property'] ) ) ? $inventory['price_on_property'] : array();
		$quantity_on_property = ( is_array( $inventory ) && ! empty( $inventory['quantity_on_property'] ) ) ? $inventory['quantity_on_property'] : array();

		// Review is now reserved for things that cannot be represented
		// faithfully. Having variations is no longer one of them — they are
		// imported as WooCommerce variations.
		$review_reasons = array();
		if ( null === $price && ! $is_variable ) {
			$review_reasons[] = 'no price could be resolved';
		}
		// Not "no variants": a simple listing legitimately has none. The real
		// fault is offerings existing but none of them being buyable.
		if ( $parsed['offering_count'] > 0 && null === $parsed['min_price'] ) {
			$review_reasons[] = 'listing has offerings but none are enabled or priced';
		}
		if ( $is_variable ) {
			// Every variant must name a value for every attribute, or the
			// storefront cannot resolve a selection to a variation.
			foreach ( $variants as $variant ) {
				foreach ( $attributes as $attribute ) {
					if ( ! isset( $variant['attributes'][ $attribute['name'] ] ) ) {
						$review_reasons[] = sprintf(
							'a variation is missing a value for "%s"',
							$attribute['name']
						);
						break 2;
					}
				}
			}
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

		// Etsy carries one weight per listing with its own unit. The store has a
		// single configured unit, so the raw pair travels in the catalogue and
		// the importer converts — the same split the CLI uses.
		$weight = null;
		if ( ! empty( $listing['item_weight'] ) ) {
			$weight = array(
				'value' => $listing['item_weight'],
				'unit'  => isset( $listing['item_weight_unit'] ) ? (string) $listing['item_weight_unit'] : '',
			);
		}

		$dimensions = null;
		if ( ! empty( $listing['item_length'] ) || ! empty( $listing['item_width'] ) || ! empty( $listing['item_height'] ) ) {
			$dimensions = array(
				'length' => isset( $listing['item_length'] ) ? $listing['item_length'] : null,
				'width'  => isset( $listing['item_width'] ) ? $listing['item_width'] : null,
				'height' => isset( $listing['item_height'] ) ? $listing['item_height'] : null,
				'unit'   => isset( $listing['item_dimensions_unit'] ) ? (string) $listing['item_dimensions_unit'] : '',
			);
		}

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
			'sku'             => (string) $listing_id,
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
			'weight'          => $weight,
			'dimensions'      => $dimensions,
			'is_variable'     => $is_variable,
			'attributes'      => $attributes,
			'variants'        => $variants,
			'variation_meta'  => array(
				'offering_count'       => $parsed['offering_count'],
				'disabled_count'       => $parsed['disabled_count'],
				'property_names'       => $parsed['property_names'],
				'price_on_property'    => array_values( $price_on_property ),
				'quantity_on_property' => array_values( $quantity_on_property ),
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

	/**
	 * Etsy reports weight and dimensions in whichever unit the listing used;
	 * WooCommerce has one configured unit per store. Convert, or drop the value
	 * rather than importing a number that means something else.
	 */
	const WEIGHT_TO_GRAMS = array( 'g' => 1.0, 'kg' => 1000.0, 'oz' => 28.349523125, 'lb' => 453.59237 );
	const LENGTH_TO_MM    = array( 'mm' => 1.0, 'cm' => 10.0, 'm' => 1000.0, 'in' => 25.4, 'ft' => 304.8 );

	public static function convert_weight( $value, $from_unit, $to_unit ) {
		if ( ! is_numeric( $value ) || (float) $value <= 0 ) {
			return null;
		}
		$from = strtolower( (string) $from_unit );
		$to   = strtolower( (string) $to_unit );
		if ( ! isset( self::WEIGHT_TO_GRAMS[ $from ], self::WEIGHT_TO_GRAMS[ $to ] ) ) {
			return null;
		}
		$grams = (float) $value * self::WEIGHT_TO_GRAMS[ $from ];
		return round( $grams / self::WEIGHT_TO_GRAMS[ $to ], 3 );
	}

	public static function convert_length( $value, $from_unit, $to_unit ) {
		if ( ! is_numeric( $value ) || (float) $value <= 0 ) {
			return null;
		}
		$from = strtolower( (string) $from_unit );
		$to   = strtolower( (string) $to_unit );
		if ( ! isset( self::LENGTH_TO_MM[ $from ], self::LENGTH_TO_MM[ $to ] ) ) {
			return null;
		}
		$mm = (float) $value * self::LENGTH_TO_MM[ $from ];
		return round( $mm / self::LENGTH_TO_MM[ $to ], 3 );
	}

	/**
	 * Does this variation include the book itself?
	 *
	 * Etsy has no way to make a property affect shipping weight, so the kit and
	 * the kit-plus-book ship as the same weight there. Here the book's weight is
	 * applied as a multiplier on the listing's weight.
	 *
	 * Scoped to the Book property deliberately: the spine-only kits also offer
	 * "With Tools", and tools are not a book. Harley's option values are spelled
	 * inconsistently across listings — "with book", "With Book", "With Books" —
	 * so the test is a case-insensitive "with" at the start of a Book value.
	 */
	public static function variant_includes_book( array $attributes ) {
		foreach ( $attributes as $name => $value ) {
			if ( ! preg_match( '/book/i', (string) $name ) ) {
				continue;
			}
			if ( preg_match( '/^\s*with\b/i', (string) $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Does this title suggest the box holds more than one kit?
	 *
	 * Only used to warn when such a listing has no kit count configured — the
	 * weight itself always comes from the configured counts, never from a guess
	 * about the title. On this shop the heuristic and "top variant price far
	 * above a single kit" picked out exactly the same nine listings.
	 */
	public static function looks_multi_kit( $title ) {
		return (bool) preg_match(
			'/\b(all\s+\w+|all\s*\d+|bundle|full\s+set|set|series|kits)\b/i',
			(string) $title
		);
	}

	/** Stable fingerprint of an image set, so unchanged galleries are skipped. */
	public static function image_signature( array $item ) {
		$urls = array();
		foreach ( ( isset( $item['images'] ) ? $item['images'] : array() ) as $image ) {
			$urls[] = $image['url'];
		}
		return sha1( implode( '|', $urls ) );
	}

	/** Fingerprint of the variant set, so a run can report that it changed. */
	public static function variant_signature( array $item ) {
		$parts = array();
		foreach ( ( isset( $item['variants'] ) ? $item['variants'] : array() ) as $variant ) {
			$values = $variant['attributes'];
			ksort( $values );
			$parts[] = $variant['sku'] . '|' . implode( ',', $values ) . '|' . $variant['price'] . '|' . $variant['quantity'];
		}
		sort( $parts );
		return sha1( implode( ';', $parts ) );
	}
}
