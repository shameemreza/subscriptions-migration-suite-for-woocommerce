<?php
/**
 * Product converter.
 *
 * Turns a source plugin's subscription products into real WooCommerce
 * Subscriptions products: the subscription product type plus the
 * _subscription_* meta, so migrated subscriptions renew with the right
 * product behavior and the products keep selling as subscriptions.
 *
 * Variable products convert too: the parent becomes variable-subscription
 * and each variation gets its own _subscription_* meta from the map the
 * adapter built. A variation the adapter could not decode fails that
 * product with a clear message instead of converting half of it.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Applies product conversion maps from source adapters.
 */
class WCSMS_Product_Converter {

	const META_CONVERTED_FROM = '_wcsms_converted_from';

	/**
	 * Convert all products a source adapter maps.
	 *
	 * @param WCSMS_Source_Adapter $adapter Source adapter.
	 * @param bool                 $dry_run List actions without writing.
	 * @return array<int, array{product_id: int, status: string, message: string}>
	 */
	public function convert_all( $adapter, $dry_run = true ) {
		$results = array();

		foreach ( $adapter->product_maps() as $map ) {
			$results[] = $this->convert( $map, $adapter->id(), $dry_run );
		}

		return $results;
	}

	/**
	 * Convert one product.
	 *
	 * @param array  $map       Map entry from the adapter.
	 * @param string $source_id Adapter id, stored as the conversion stamp.
	 * @param bool   $dry_run   List the action without writing.
	 * @return array{product_id: int, status: string, message: string}
	 */
	private function convert( $map, $source_id, $dry_run ) {
		$product_id = (int) $map['product_id'];

		if ( ! empty( $map['error'] ) ) {
			return $this->result( $product_id, 'skipped', $map['error'] );
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return $this->result( $product_id, 'failed', __( 'Product no longer exists.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		if ( '' !== (string) $product->get_meta( self::META_CONVERTED_FROM ) ) {
			return $this->result( $product_id, 'skipped', __( 'Already converted.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		if ( $product->is_type( array( 'subscription', 'variable-subscription' ) ) ) {
			return $this->result( $product_id, 'skipped', __( 'Already a subscription product.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$is_variable = ! empty( $map['variations'] );
		$target_type = $is_variable ? 'variable-subscription' : 'subscription';

		if ( $dry_run ) {
			return $this->result(
				$product_id,
				'dry_run',
				$is_variable
					? sprintf( 'Would convert "%s" to a variable subscription product (%d variations).', $product->get_name(), count( $map['variations'] ) )
					: sprintf( 'Would convert "%s" to a subscription product.', $product->get_name() )
			);
		}

		wp_set_object_terms( $product_id, $target_type, 'product_type' );

		$meta = $map['meta'];

		// WCS reads the recurring price from _subscription_price. Simple
		// products default it to their own price; variable parents derive
		// prices from variations, so no parent default there.
		if ( ! $is_variable && ! isset( $meta['_subscription_price'] ) ) {
			$meta['_subscription_price'] = $product->get_regular_price() ? $product->get_regular_price() : $product->get_price();
		}

		foreach ( $meta as $key => $value ) {
			update_post_meta( $product_id, $key, $value );
		}

		if ( $is_variable ) {
			foreach ( $map['variations'] as $variation ) {
				$variation_id   = (int) $variation['variation_id'];
				$variation_meta = $variation['meta'];

				if ( ! isset( $variation_meta['_subscription_price'] ) ) {
					$variation_price = get_post_meta( $variation_id, '_regular_price', true );
					if ( '' === $variation_price ) {
						$variation_price = get_post_meta( $variation_id, '_price', true );
					}
					if ( '' !== $variation_price ) {
						$variation_meta['_subscription_price'] = $variation_price;
					}
				}

				foreach ( $variation_meta as $key => $value ) {
					update_post_meta( $variation_id, $key, $value );
				}
			}
		}

		update_post_meta( $product_id, self::META_CONVERTED_FROM, $source_id );

		wc_delete_product_transients( $product_id );

		if ( $is_variable && class_exists( 'WC_Product_Variable' ) ) {
			WC_Product_Variable::sync( $product_id );
		}

		WCSMS_Logger::log( sprintf( 'Converted product #%d to a %s product (source %s).', $product_id, $target_type, $source_id ) );

		return $this->result(
			$product_id,
			'converted',
			$is_variable
				? sprintf( 'Converted "%s" (%d variations).', $product->get_name(), count( $map['variations'] ) )
				: sprintf( 'Converted "%s".', $product->get_name() )
		);
	}

	/**
	 * Build a result row.
	 *
	 * @param int    $product_id Product id.
	 * @param string $status     converted, skipped, dry_run, or failed.
	 * @param string $message    Detail.
	 * @return array
	 */
	private function result( $product_id, $status, $message ) {
		return array(
			'product_id' => $product_id,
			'status'     => $status,
			'message'    => $message,
		);
	}
}
