<?php
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
