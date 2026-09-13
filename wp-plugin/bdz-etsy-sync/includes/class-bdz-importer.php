<?php
/**
 * Neutral catalogue item -> WooCommerce product.
 *
 * The safety rules here exist because this runs unattended:
 *
 *   - Products are matched by SKU (etsy-<listing_id>), so a re-run updates in
 *     place instead of creating a second copy.
 *   - Status is set on create only, so a product the owner unpublished stays
 *     unpublished.
 *   - Images are only re-imported when the Etsy image set actually changed.
 *   - A product this plugin did not create is never converted between simple
 *     and variable; it is skipped.
 *   - No product is ever deleted.
 *
 * Variations are the one thing that does get deleted, and only ever the
 * plugin's own: a variation whose Etsy offering has gone would otherwise sit in
 * the storefront as a buyable combination that no longer exists. Deletion is
 * scoped to children whose SKU carries this listing's own prefix.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		$sku         = $item['sku'];
		$title       = $item['title'];
		$is_variable = ! empty( $item['is_variable'] );
		$target_type = $is_variable ? 'variable' : 'simple';

		if ( ! $is_variable && '' === $item['price'] ) {
			return $this->outcome( 'failed', sprintf( '%s has no price — skipped. %s', $sku, $title ) );
		}
		if ( $is_variable && empty( $item['variants'] ) ) {
			return $this->outcome( 'failed', sprintf( '%s is variable but has no buyable variations — skipped. %s', $sku, $title ) );
		}

		$product_id = wc_get_product_id_by_sku( $sku );
		$existing   = $product_id ? wc_get_product( $product_id ) : null;

		if ( $existing && $existing->get_type() !== $target_type ) {
			// Converting a product this plugin created is legitimate — Etsy is
			// the source of truth and a listing can gain or lose its options.
			// Converting someone else's product is not.
			$is_ours = '' !== (string) get_post_meta( $product_id, self::META_LISTING_ID, true );
			if ( ! $is_ours ) {
				return $this->outcome(
					'skipped',
					sprintf(
						'%s is a "%s" product in the store (#%d) and this plugin did not create it — importing would convert it to %s and orphan its variations. Skipped.',
						$sku,
						$existing->get_type(),
						$product_id,
						$target_type
					)
				);
			}
		}

		if ( $this->dry_run ) {
			$shape = $is_variable
				? sprintf( 'variable, %d variation(s)', count( $item['variants'] ) )
				: 'simple';
			return $this->outcome(
				$existing ? 'updated' : 'created',
				sprintf(
					'%s would %s — %s [%s]',
					$sku,
					$existing ? 'update #' . $product_id : 'be created',
					$title,
					$shape
				)
			);
		}

		$is_new = ! $existing;

		// A type change has to go through a fresh object: WooCommerce decides
		// behaviour from the class, not from a settable field.
		if ( $existing && $existing->get_type() !== $target_type ) {
			$product = $is_variable ? new WC_Product_Variable( $product_id ) : new WC_Product_Simple( $product_id );
		} elseif ( $existing ) {
			$product = $existing;
		} else {
			$product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
		}

		$product->set_name( $title );
		$product->set_sku( $sku );
		$product->set_description( BDZ_Etsy_Normalize::text_to_html( $item['description'] ) );
		$product->set_short_description(
			BDZ_Etsy_Normalize::text_to_html( BDZ_Etsy_Normalize::first_paragraph( $item['description'] ) )
		);
		$product->set_catalog_visibility( 'visible' );

		if ( $is_variable ) {
			// Price and stock live on the variations; the parent must not carry
			// its own or WooCommerce shows a price that belongs to nothing.
			$product->set_regular_price( '' );
			$product->set_manage_stock( false );
			$product->set_attributes( $this->build_attributes( $item['attributes'] ) );
		} else {
			$product->set_regular_price( $item['price'] );
			$product->set_manage_stock( true );
			$product->set_stock_quantity( max( 0, (int) $item['quantity'] - $this->stock_buffer ) );
			$product->set_backorders( 'no' );
			$product->set_attributes( array() );
		}

		if ( $is_new ) {
			$product->set_status( $this->new_status );
		}

		$product_id = $product->save();
		if ( ! $product_id ) {
			return $this->outcome( 'failed', sprintf( '%s could not be saved.', $sku ) );
		}

		$variation_note = '';
		if ( $is_variable ) {
			$counts         = $this->sync_variations( $product_id, $item );
			$variation_note = sprintf(
				' [%d variation(s): +%d ~%d -%d]',
				count( $item['variants'] ),
				$counts['created'],
				$counts['updated'],
				$counts['removed']
			);
		} else {
			// Dropping back to simple: clear out any variations left behind.
			$this->remove_all_variations( $product_id, $item['sku'] );
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

		if ( $is_variable ) {
			// Recalculates the parent's displayed price range from the
			// variations that now exist.
			WC_Product_Variable::sync( $product_id );
		}
		wc_delete_product_transients( $product_id );

		return $this->outcome(
			$is_new ? 'created' : 'updated',
			sprintf( '%s %s #%d — %s%s', $sku, $is_new ? 'created' : 'updated', $product_id, $title, $variation_note )
		);
	}

	private function outcome( $action, $message ) {
		return array( 'action' => $action, 'message' => $message );
	}

	/**
	 * Custom product-level attributes rather than global taxonomies: these
	 * values are Etsy's, they differ per listing, and registering dozens of
	 * global attribute taxonomies for them would clutter the store for no gain.
	 */
	private function build_attributes( array $attributes ) {
		$out      = array();
		$position = 0;

		foreach ( $attributes as $attribute ) {
			if ( empty( $attribute['name'] ) || empty( $attribute['options'] ) ) {
				continue;
			}
			$object = new WC_Product_Attribute();
			$object->set_id( 0 );
			$object->set_name( $attribute['name'] );
			$object->set_options( $attribute['options'] );
			$object->set_position( $position++ );
			$object->set_visible( true );
			$object->set_variation( true );
			$out[] = $object;
		}

		return $out;
	}

	/**
	 * Bring the product's variations in line with the Etsy offerings.
	 *
	 * @return array { created, updated, removed }
	 */
	private function sync_variations( $product_id, array $item ) {
		$counts = array( 'created' => 0, 'updated' => 0, 'removed' => 0 );
		$prefix = $item['sku'] . '-';

		$existing = array();
		$parent   = wc_get_product( $product_id );
		foreach ( $parent->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child ) {
				$existing[ $child->get_sku() ] = $child;
			}
		}

		$seen = array();

		foreach ( $item['variants'] as $variant ) {
			$variation = isset( $existing[ $variant['sku'] ] )
				? $existing[ $variant['sku'] ]
				: new WC_Product_Variation();

			$is_new = ! $variation->get_id();

			$attributes = array();
			foreach ( $variant['attributes'] as $name => $value ) {
				// Custom attributes key on the sanitised attribute name and
				// store the option text verbatim.
				$attributes[ sanitize_title( $name ) ] = $value;
			}

			$variation->set_parent_id( $product_id );
			$variation->set_sku( $variant['sku'] );
			$variation->set_attributes( $attributes );
			$variation->set_regular_price( $variant['price'] );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( max( 0, (int) $variant['quantity'] - $this->stock_buffer ) );
			$variation->set_backorders( 'no' );
			if ( $is_new ) {
				$variation->set_status( 'publish' );
			}
			$variation->save();

			$seen[ $variant['sku'] ] = true;
			$counts[ $is_new ? 'created' : 'updated' ]++;
		}

		// A combination Etsy no longer offers must not remain buyable. Only
		// this listing's own variations are touched.
		foreach ( $existing as $sku => $variation ) {
			if ( isset( $seen[ $sku ] ) ) {
				continue;
			}
			if ( 0 !== strpos( (string) $sku, $prefix ) ) {
				continue;
			}
			$variation->delete( true );
			$counts['removed']++;
		}

		return $counts;
	}

	private function remove_all_variations( $product_id, $sku ) {
		$parent = wc_get_product( $product_id );
		if ( ! $parent || ! method_exists( $parent, 'get_children' ) ) {
			return;
		}
		$prefix = $sku . '-';
		foreach ( $parent->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child && 0 === strpos( (string) $child->get_sku(), $prefix ) ) {
				$child->delete( true );
			}
		}
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
	 * signature check matters: without it every run re-downloads the gallery.
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
