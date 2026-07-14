<?php
/**
 * YITH WooCommerce Subscription source adapter.
 *
 * Source model, mapped from the plugin's code:
 *
 * - Subscriptions are a plain CPT ywsbs_subscription with everything in
 *   post meta, and every field may exist under either a bare key (status)
 *   or an underscore-prefixed one (_status). The plugin itself reads both,
 *   so this adapter does too.
 * - Recurrence is price_is_per (interval count) plus price_time_option
 *   (plural unit: days, weeks, months, years).
 * - Dates are Unix timestamps, except cancelled_date, which the plugin
 *   writes in two formats depending on the code path: a Y-m-d H:i:s string
 *   from cancel_subscription() and an integer from update_status().
 * - A cancelled subscription keeps running until end_date (the paid-until
 *   time). Mapping that to WCS cancelled would cut the customer off early,
 *   so cancelled-with-future-end becomes pending-cancel with the end date,
 *   and only subscriptions past their paid time import as cancelled.
 * - The free version stores no card tokens. PayPal Standard profiles are
 *   IPN-driven and not portable; PPCP vault tokens ride the WC token store
 *   and survive only if that gateway stays active.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for YITH subscription data.
 */
class WCSMS_Source_YITH extends WCSMS_Source_Adapter {

	const POST_TYPE = 'ywsbs_subscription';

	/**
	 * Adapter id, matching the scanner.
	 *
	 * @return string
	 */
	public function id() {
		return 'yith';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'YITH WooCommerce Subscription';
	}

	/**
	 * Count migratable records.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'trash'", self::POST_TYPE ) );
	}

	/**
	 * Fetch a page of records.
	 *
	 * @param int $offset Records to skip.
	 * @param int $limit  Page size.
	 * @return array<int, array>
	 */
	public function fetch( $offset, $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'trash' ORDER BY ID ASC LIMIT %d, %d", self::POST_TYPE, $offset, $limit ) );

		$results = array();

		foreach ( $ids as $id ) {
			$results[] = $this->build( (int) $id );
		}

		return $results;
	}

	/**
	 * Build one normalized record.
	 *
	 * @param int $id Source subscription id.
	 * @return array
	 */
	private function build( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $id ), ARRAY_A );

		if ( empty( $rows ) ) {
			return $this->failure( $id, __( 'Source row has no meta.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$meta = array();
		foreach ( $rows as $row ) {
			$meta[ $row['meta_key'] ] = $row['meta_value'];
		}

		$get = static function ( $key, $default_value = '' ) use ( $meta ) {
			if ( isset( $meta[ $key ] ) && '' !== $meta[ $key ] ) {
				return $meta[ $key ];
			}
			if ( isset( $meta[ '_' . $key ] ) && '' !== $meta[ '_' . $key ] ) {
				return $meta[ '_' . $key ];
			}
			return $default_value;
		};

		$source_status = (string) $get( 'status' );

		if ( '' === $source_status ) {
			return $this->failure( $id, __( 'The subscription has no status meta.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$period = $this->singular_period( (string) $get( 'price_time_option' ) );

		if ( '' === $period ) {
			return $this->failure(
				$id,
				sprintf(
					/* translators: %s: period value from the source. */
					__( 'Unrecognized billing period "%s".', 'subscriptions-migration-suite-for-woocommerce' ),
					(string) $get( 'price_time_option' )
				)
			);
		}

		$interval = max( 1, (int) $get( 'price_is_per', 1 ) );

		$dates = array_filter(
			array(
				'start'        => $this->to_datetime( $get( 'start_date' ) ),
				'next_payment' => $this->to_datetime( $get( 'payment_due_date' ) ),
				'end'          => $this->to_datetime( $get( 'end_date' ) ),
				'cancelled'    => $this->to_datetime( $get( 'cancelled_date' ) ),
			)
		);

		if ( empty( $dates['end'] ) ) {
			$expired = $this->to_datetime( $get( 'expired_date' ) );
			if ( '' !== $expired ) {
				$dates['end'] = $expired;
			}
		}

		$status = $this->map_status( $source_status, $dates );

		if ( in_array( $status, array( 'cancelled', 'expired' ), true ) ) {
			unset( $dates['next_payment'] );
		}
		if ( 'pending-cancel' === $status ) {
			unset( $dates['next_payment'] );
			// WCS validates the cancelled date against other dates strictly;
			// the paid-until end date is what matters for the customer.
			unset( $dates['cancelled'] );
		}
		if ( ! in_array( $status, array( 'cancelled' ), true ) ) {
			unset( $dates['cancelled'] );
		}

		$payment_method = (string) $get( 'payment_method' );

		$record = array(
			'source'                  => $this->id(),
			'source_id'               => (string) $id,
			'customer_id'             => (int) $get( 'user_id' ),
			'status'                  => $status,
			'currency'                => '' !== (string) $get( 'order_currency' ) ? (string) $get( 'order_currency' ) : get_woocommerce_currency(),
			'billing_period'          => $period,
			'billing_interval'        => $interval,
			'dates'                   => $dates,
			// The free version stores no reusable card tokens, so imported
			// subscriptions renew manually unless a WCS-compatible gateway
			// recognizes its own meta later.
			'requires_manual_renewal' => true,
			'payment'                 => array(
				'method'       => $payment_method,
				'method_title' => (string) $get( 'payment_method_title' ),
			),
			'billing_address'         => $this->address( $get, 'billing' ),
			'shipping_address'        => $this->address( $get, 'shipping' ),
			'items'                   => $this->items( $get ),
			'totals'                  => array_filter(
				array(
					'total'          => (float) $get( 'order_total', 0 ),
					'shipping_total' => (float) $get( 'order_shipping', 0 ),
					'shipping_tax'   => (float) $get( 'order_shipping_tax', 0 ),
					'discount_total' => (float) $get( 'cart_discount', 0 ),
					'cart_tax'       => (float) $get( 'order_tax', 0 ),
				)
			),
			'parent_order_id'         => $this->parent_order( $get ),
			'order_notes'             => array(
				sprintf(
					/* translators: 1: source subscription id, 2: source status. */
					__( 'Migrated from YITH WooCommerce Subscription #%1$d (source status: %2$s).', 'subscriptions-migration-suite-for-woocommerce' ),
					$id,
					$source_status
				),
			),
		);

		return array(
			'source_ref' => (string) $id,
			'record'     => $record,
			'error'      => null,
		);
	}

	/**
	 * Map a YITH status to a WCS status, honoring paid-until time.
	 *
	 * @param string $source_status YITH status slug.
	 * @param array  $dates         Normalized dates.
	 * @return string
	 */
	private function map_status( $source_status, $dates ) {
		switch ( $source_status ) {
			case 'active':
			case 'trial':
				return 'active';
			case 'pending':
				return 'pending';
			case 'paused':
			case 'suspended':
			case 'overdue':
				return 'on-hold';
			case 'expired':
				return 'expired';
			case 'cancelled':
				// Cancelled but paid until a future end date keeps serving
				// as pending-cancel; past the end date it is truly over.
				if ( ! empty( $dates['end'] ) && strtotime( $dates['end'] ) > time() ) {
					return 'pending-cancel';
				}
				return 'cancelled';
		}

		return 'pending';
	}

	/**
	 * Normalize a source date that may be a Unix timestamp or a datetime
	 * string, the cancelled_date double format included.
	 *
	 * @param mixed $value Raw meta value.
	 * @return string MySQL UTC datetime, or empty when unusable.
	 */
	private function to_datetime( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		if ( is_numeric( $value ) ) {
			return (int) $value > 0 ? gmdate( 'Y-m-d H:i:s', (int) $value ) : '';
		}

		$timestamp = strtotime( (string) $value );

		return false === $timestamp ? '' : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Plural source period to the WCS singular vocabulary.
	 *
	 * @param string $option price_time_option value.
	 * @return string Empty when unrecognized.
	 */
	private function singular_period( $option ) {
		$map = array(
			'days'   => 'day',
			'weeks'  => 'week',
			'months' => 'month',
			'years'  => 'year',
			'day'    => 'day',
			'week'   => 'week',
			'month'  => 'month',
			'year'   => 'year',
		);

		return isset( $map[ $option ] ) ? $map[ $option ] : '';
	}

	/**
	 * Address block from the dual-key meta snapshot.
	 *
	 * @param callable $get    Dual-key meta reader.
	 * @param string   $prefix billing or shipping.
	 * @return array
	 */
	private function address( $get, $prefix ) {
		$fields  = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' );
		$address = array();

		foreach ( $fields as $field ) {
			$value = (string) $get( $prefix . '_' . $field );
			if ( '' !== $value ) {
				$address[ $field ] = $value;
			}
		}

		return $address;
	}

	/**
	 * The single line item YITH keeps on the subscription record.
	 *
	 * @param callable $get Dual-key meta reader.
	 * @return array<int, array>
	 */
	private function items( $get ) {
		$variation_id = (int) $get( 'variation_id' );
		$product_id   = $variation_id ? $variation_id : (int) $get( 'product_id' );

		if ( 0 === $product_id ) {
			return array();
		}

		return array(
			array(
				'product_id' => $product_id,
				'quantity'   => max( 1, (int) $get( 'quantity', 1 ) ),
				'subtotal'   => (float) $get( 'line_subtotal', 0 ),
				'total'      => (float) $get( 'line_total', 0 ),
			),
		);
	}

	/**
	 * Parent order id, only when the order still exists.
	 *
	 * @param callable $get Dual-key meta reader.
	 * @return int
	 */
	private function parent_order( $get ) {
		$order_id = (int) $get( 'order_id' );

		if ( $order_id && wc_get_order( $order_id ) ) {
			return $order_id;
		}

		return 0;
	}

	/**
	 * Product conversion maps for YITH subscription products.
	 *
	 * @return array<int, array>
	 */
	public function product_maps() {
		global $wpdb;

		$maps = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
		$product_ids = $wpdb->get_col(
			"SELECT pm.post_id
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product' AND p.post_status <> 'trash'
			 WHERE pm.meta_key = '_ywsbs_subscription' AND pm.meta_value = 'yes'
			 ORDER BY pm.post_id ASC"
		);

		foreach ( $product_ids as $product_id ) {
			$product_id = (int) $product_id;
			$period     = $this->singular_period( (string) get_post_meta( $product_id, '_ywsbs_price_time_option', true ) );

			if ( '' === $period ) {
				$maps[] = array(
					'product_id' => $product_id,
					'meta'       => array(),
					'error'      => sprintf(
						/* translators: %s: period value from the source product. */
						__( 'Unrecognized subscription period "%s" on the source product.', 'subscriptions-migration-suite-for-woocommerce' ),
						(string) get_post_meta( $product_id, '_ywsbs_price_time_option', true )
					),
				);
				continue;
			}

			$meta = array(
				'_subscription_period'          => $period,
				'_subscription_period_interval' => max( 1, (int) get_post_meta( $product_id, '_ywsbs_price_is_per', true ) ),
			);

			// Max length is counted in the same unit as the billing period,
			// which is exactly how WCS counts _subscription_length.
			$max_length = (int) get_post_meta( $product_id, '_ywsbs_max_length', true );
			if ( 'yes' === get_post_meta( $product_id, '_ywsbs_enable_max_length', true ) && $max_length > 0 ) {
				$meta['_subscription_length'] = $max_length;
			}

			if ( 'yes' === get_post_meta( $product_id, '_ywsbs_enable_limit', true ) ) {
				$limit                       = (string) get_post_meta( $product_id, '_ywsbs_limit', true );
				$meta['_subscription_limit'] = 'one-active' === $limit ? 'active' : 'any';
			}

			$maps[] = array(
				'product_id' => $product_id,
				'meta'       => $meta,
				'error'      => null,
			);
		}

		return $maps;
	}

	/**
	 * YITH renews on WP-Cron, not Action Scheduler. The events are removed
	 * automatically when the plugin is deactivated, and the scanner already
	 * pushes merchants to deactivate the source; nothing to unschedule via
	 * Action Scheduler here.
	 *
	 * @return string[]
	 */
	public function scheduler_hooks() {
		return array();
	}

	/**
	 * Build a failed-row result.
	 *
	 * @param int    $id      Source id.
	 * @param string $message Reason.
	 * @return array
	 */
	private function failure( $id, $message ) {
		return array(
			'source_ref' => (string) $id,
			'record'     => null,
			'error'      => $message,
		);
	}
}
