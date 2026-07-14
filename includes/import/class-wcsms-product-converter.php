<?php
/**
 * Product converter.
 *
 * Turns a source plugin's subscription products into real WooCommerce
 * Subscriptions products: the subscription product type plus the
 * _subscription_* meta, so migrated subscriptions renew with the right
 * product behavior and the products keep selling as subscriptions.
 *
 * Simple products only for now: variable subscription conversion needs
 * per-variation meta and is reported as skipped, not guessed.
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

		if ( $dry_run ) {
			return $this->result( $product_id, 'dry_run', sprintf( 'Would convert "%s" to a subscription product.', $product->get_name() ) );
		}

		wp_set_object_terms( $product_id, 'subscription', 'product_type' );

		$meta = $map['meta'];

		// WCS reads the recurring price from _subscription_price; default it
		// to the product's own price when the source kept it there.
		if ( ! isset( $meta['_subscription_price'] ) ) {
			$meta['_subscription_price'] = $product->get_regular_price() ? $product->get_regular_price() : $product->get_price();
		}

		foreach ( $meta as $key => $value ) {
			update_post_meta( $product_id, $key, $value );
		}

		update_post_meta( $product_id, self::META_CONVERTED_FROM, $source_id );

		wc_delete_product_transients( $product_id );

		WCSMS_Logger::log( sprintf( 'Converted product #%d to a subscription product (source %s).', $product_id, $source_id ) );

		return $this->result( $product_id, 'converted', sprintf( 'Converted "%s".', $product->get_name() ) );
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
