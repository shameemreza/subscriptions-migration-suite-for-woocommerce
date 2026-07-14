<?php
/**
 * Subscriptions For WooCommerce (WP Swings) source adapter.
 *
 * Source model, mapped from the plugin's code:
 *
 * - Subscriptions are a WC order type wps_subscriptions whose order status
 *   is always the container wc-wps_renewal; the real lifecycle status lives
 *   in the wps_subscription_status meta.
 * - Dates are Unix timestamps with 0 as the "not set" sentinel, including
 *   the misspelled wps_susbcription_trial_end and wps_susbcription_end keys.
 * - Billing terms use the WCS vocabulary already: wps_sfw_subscription_number
 *   is the interval count and wps_sfw_subscription_interval the period.
 * - The subscribed product usually lives in subscription meta (product_id,
 *   product_qty, line totals), not in order item tables.
 * - Stripe renewals ride the official WC Stripe token store: the customer
 *   and source ids sit on the parent order, so the adapter copies them onto
 *   the new subscription and automatic renewals carry over.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for WP Swings subscription data.
 */
class WCSMS_Source_WPSwings extends WCSMS_Source_Adapter {

	const ORDER_TYPE = 'wps_subscriptions';

	/**
	 * Gateways whose tokens live in the WC Stripe store on the parent order.
	 *
	 * @var string[]
	 */
	const STRIPE_TOKEN_KEYS = array( '_stripe_customer_id', '_stripe_source_id' );

	/**
	 * Resolved store.
	 *
	 * @var string|null
	 */
	private $store = null;

	/**
	 * Adapter id, matching the scanner.
	 *
	 * @return string
	 */
	public function id() {
		return 'wpswings';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'Subscriptions For WooCommerce (WP Swings)';
	}

	/**
	 * Count migratable records.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;

		if ( 'hpos' === $this->store() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = %s", self::ORDER_TYPE ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'trash'", self::ORDER_TYPE ) );
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

		if ( 'hpos' === $this->store() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wc_orders WHERE type = %s ORDER BY id ASC LIMIT %d, %d", self::ORDER_TYPE, $offset, $limit ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'trash' ORDER BY ID ASC LIMIT %d, %d", self::ORDER_TYPE, $offset, $limit ) );
		}

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
		$meta = $this->read_meta( $id );

		if ( empty( $meta ) ) {
			return $this->failure( $id, __( 'Source row could not be read.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$status = isset( $meta['wps_subscription_status'] ) ? (string) $meta['wps_subscription_status'] : '';

		if ( '' === $status ) {
			return $this->failure( $id, __( 'The subscription has no lifecycle status meta.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$period   = isset( $meta['wps_sfw_subscription_interval'] ) ? (string) $meta['wps_sfw_subscription_interval'] : '';
		$interval = isset( $meta['wps_sfw_subscription_number'] ) ? (int) $meta['wps_sfw_subscription_number'] : 0;

		if ( ! in_array( $period, array( 'day', 'week', 'month', 'year' ), true ) || $interval < 1 ) {
			return $this->failure(
				$id,
				sprintf(
					/* translators: 1: period value, 2: interval value from the source. */
					__( 'Billing terms are incomplete (period "%1$s", interval "%2$s").', 'subscriptions-migration-suite-for-woocommerce' ),
					$period,
					$interval
				)
			);
		}

		$dates = array_filter(
			array(
				'start'        => $this->timestamp_to_date( $meta, 'wps_schedule_start' ),
				'trial_end'    => $this->timestamp_to_date( $meta, 'wps_susbcription_trial_end' ),
				'next_payment' => $this->timestamp_to_date( $meta, 'wps_next_payment_date' ),
				'end'          => $this->timestamp_to_date( $meta, 'wps_susbcription_end' ),
				'cancelled'    => $this->timestamp_to_date( $meta, 'wps_subscription_cancelled_date' ),
			)
		);

		if ( in_array( $status, array( 'cancelled', 'expired' ), true ) ) {
			unset( $dates['next_payment'] );
		} else {
			unset( $dates['cancelled'] );
		}

		$payment_method = isset( $meta['_payment_method'] ) ? (string) $meta['_payment_method'] : '';
		$manual         = '' === $payment_method
			|| ( isset( $meta['wps_wsp_payment_type'] ) && 'wps_wsp_manual_method' === $meta['wps_wsp_payment_type'] );

		$parent_order_id = isset( $meta['wps_parent_order'] ) && is_numeric( $meta['wps_parent_order'] ) ? (int) $meta['wps_parent_order'] : 0;
		$parent_order    = $parent_order_id ? wc_get_order( $parent_order_id ) : false;

		$payment = array(
			'method'       => $manual ? '' : $payment_method,
			'method_title' => isset( $meta['_payment_method_title'] ) ? (string) $meta['_payment_method_title'] : '',
		);

		// Stripe tokens live on the parent order in WC Stripe's own keys;
		// copying them to the subscription is what keeps renewals charging.
		if ( ! $manual && $parent_order && 0 === strpos( $payment_method, 'stripe' ) ) {
			foreach ( self::STRIPE_TOKEN_KEYS as $key ) {
				$value = $parent_order->get_meta( $key );
				if ( '' !== (string) $value ) {
					$payment['post_meta'][ $key ] = (string) $value;
				}
			}
		}

		$customer_id = 0;
		if ( isset( $meta['wps_customer_id'] ) && (int) $meta['wps_customer_id'] > 0 ) {
			$customer_id = (int) $meta['wps_customer_id'];
		} elseif ( $parent_order ) {
			$customer_id = $parent_order->get_customer_id();
		}

		$record = array(
			'source'                  => $this->id(),
			'source_id'               => (string) $id,
			'customer_id'             => $customer_id,
			'status'                  => $status,
			'currency'                => isset( $meta['wps_order_currency'] ) && '' !== $meta['wps_order_currency'] ? (string) $meta['wps_order_currency'] : get_woocommerce_currency(),
			'billing_period'          => $period,
			'billing_interval'        => $interval,
			'dates'                   => $dates,
			'requires_manual_renewal' => $manual,
			'payment'                 => $payment,
			'billing_address'         => $parent_order ? $parent_order->get_address( 'billing' ) : array(),
			'shipping_address'        => $parent_order ? $parent_order->get_address( 'shipping' ) : array(),
			'items'                   => $this->build_items( $id, $meta ),
			'totals'                  => array_filter(
				array(
					'total' => isset( $meta['wps_recurring_total'] ) ? (float) $meta['wps_recurring_total'] : 0.0,
				)
			),
			'parent_order_id'         => $parent_order ? $parent_order_id : 0,
			'renewal_order_ids'       => $this->renewal_order_ids( $id ),
			'order_notes'             => array(
				sprintf(
					/* translators: %d: source subscription id. */
					__( 'Migrated from Subscriptions For WooCommerce (WP Swings) subscription #%d.', 'subscriptions-migration-suite-for-woocommerce' ),
					$id
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
	 * Line items: order item tables when present, otherwise the product
	 * reference the source keeps in subscription meta.
	 *
	 * @param int   $id   Source subscription id.
	 * @param array $meta Subscription meta.
	 * @return array<int, array>
	 */
	private function build_items( $id, $meta ) {
		global $wpdb;

		$items = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
		$item_ids = $wpdb->get_col( $wpdb->prepare( "SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d AND order_item_type = 'line_item' ORDER BY order_item_id ASC", $id ) );

		foreach ( $item_ids as $item_id ) {
			$item_meta = array();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE order_item_id = %d", $item_id ), ARRAY_A );
			foreach ( $rows as $row ) {
				$item_meta[ $row['meta_key'] ] = $row['meta_value'];
			}

			$product_id = ! empty( $item_meta['_variation_id'] ) ? (int) $item_meta['_variation_id'] : ( isset( $item_meta['_product_id'] ) ? (int) $item_meta['_product_id'] : 0 );

			if ( 0 === $product_id ) {
				continue;
			}

			$items[] = array(
				'product_id' => $product_id,
				'quantity'   => isset( $item_meta['_qty'] ) ? (int) $item_meta['_qty'] : 1,
				'subtotal'   => isset( $item_meta['_line_subtotal'] ) ? (float) $item_meta['_line_subtotal'] : null,
				'total'      => isset( $item_meta['_line_total'] ) ? (float) $item_meta['_line_total'] : null,
			);
		}

		if ( ! empty( $items ) ) {
			return $items;
		}

		if ( empty( $meta['product_id'] ) ) {
			return array();
		}

		return array(
			array(
				'product_id' => (int) $meta['product_id'],
				'quantity'   => isset( $meta['product_qty'] ) ? max( 1, (int) $meta['product_qty'] ) : 1,
				'subtotal'   => isset( $meta['line_subtotal'] ) ? (float) $meta['line_subtotal'] : null,
				'total'      => isset( $meta['line_total'] ) ? (float) $meta['line_total'] : null,
			),
		);
	}

	/**
	 * Read all meta for a source subscription from its store.
	 *
	 * @param int $id Source subscription id.
	 * @return array<string, string>
	 */
	private function read_meta( $id ) {
		global $wpdb;

		if ( 'hpos' === $this->store() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d", $id ), ARRAY_A );

			$meta = array();
			foreach ( $rows as $row ) {
				$meta[ $row['meta_key'] ] = $row['meta_value'];
			}

			// The gateway id lives in the orders table column on HPOS.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
			$order_row = $wpdb->get_row( $wpdb->prepare( "SELECT payment_method, payment_method_title, currency FROM {$wpdb->prefix}wc_orders WHERE id = %d", $id ), ARRAY_A );

			if ( null === $order_row && empty( $meta ) ) {
				return array();
			}

			if ( $order_row ) {
				if ( empty( $meta['_payment_method'] ) && ! empty( $order_row['payment_method'] ) ) {
					$meta['_payment_method'] = $order_row['payment_method'];
				}
				if ( empty( $meta['_payment_method_title'] ) && ! empty( $order_row['payment_method_title'] ) ) {
					$meta['_payment_method_title'] = $order_row['payment_method_title'];
				}
				if ( empty( $meta['wps_order_currency'] ) && ! empty( $order_row['currency'] ) ) {
					$meta['wps_order_currency'] = $order_row['currency'];
				}
			}

			return $meta;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $id ), ARRAY_A );

		$meta = array();
		foreach ( $rows as $row ) {
			$meta[ $row['meta_key'] ] = $row['meta_value'];
		}

		return $meta;
	}

	/**
	 * Convert a Unix-timestamp meta value to a MySQL UTC datetime, treating
	 * the 0 sentinel and junk as "not set".
	 *
	 * @param array  $meta Subscription meta.
	 * @param string $key  Meta key.
	 * @return string Empty string when not set.
	 */
	private function timestamp_to_date( $meta, $key ) {
		if ( empty( $meta[ $key ] ) || ! is_numeric( $meta[ $key ] ) || (int) $meta[ $key ] <= 0 ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i:s', (int) $meta[ $key ] );
	}

	/**
	 * Renewal orders are plain shop orders stamped with wps_sfw_subscription
	 * pointing back at the source subscription.
	 *
	 * @param int $id Source subscription id.
	 * @return int[]
	 */
	private function renewal_order_ids( $id ) {
		global $wpdb;

		if ( 'hpos' === $this->store() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT o.id FROM {$wpdb->prefix}wc_orders o INNER JOIN {$wpdb->prefix}wc_orders_meta m ON m.order_id = o.id AND m.meta_key = 'wps_sfw_subscription' AND m.meta_value = %s WHERE o.type = 'shop_order' ORDER BY o.id ASC", (string) $id ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'wps_sfw_subscription' AND m.meta_value = %s WHERE p.post_type = 'shop_order' ORDER BY p.ID ASC", (string) $id ) );
		}

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * WP Swings runs its renewal and expiry sweeps as recurring Action
	 * Scheduler jobs.
	 *
	 * @return string[]
	 */
	public function scheduler_hooks() {
		return array(
			'wps_sfw_create_renewal_order_schedule',
			'wps_sfw_expired_renewal_subscription',
		);
	}

	/**
	 * Product conversion maps. WP Swings marks products with the
	 * _wps_sfw_product flag and stores billing terms in wps_sfw_* meta,
	 * already using the WCS period vocabulary.
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
			 WHERE pm.meta_key = '_wps_sfw_product' AND pm.meta_value = 'yes'
			 ORDER BY pm.post_id ASC"
		);

		foreach ( $product_ids as $product_id ) {
			$product_id = (int) $product_id;

			if ( 'yes' === get_post_meta( $product_id, 'wps_sfw_variable_product', true ) ) {
				$maps[] = array(
					'product_id' => $product_id,
					'meta'       => array(),
					'error'      => __( 'Variable subscription products need per-variation conversion, which is not supported yet.', 'subscriptions-migration-suite-for-woocommerce' ),
				);
				continue;
			}

			$period   = (string) get_post_meta( $product_id, 'wps_sfw_subscription_interval', true );
			$interval = max( 1, (int) get_post_meta( $product_id, 'wps_sfw_subscription_number', true ) );

			if ( ! in_array( $period, array( 'day', 'week', 'month', 'year' ), true ) ) {
				$maps[] = array(
					'product_id' => $product_id,
					'meta'       => array(),
					'error'      => sprintf(
						/* translators: %s: period value from the source product. */
						__( 'Unrecognized subscription period "%s" on the source product.', 'subscriptions-migration-suite-for-woocommerce' ),
						$period
					),
				);
				continue;
			}

			$meta = array(
				'_subscription_period'          => $period,
				'_subscription_period_interval' => $interval,
				'_subscription_sign_up_fee'     => (string) get_post_meta( $product_id, 'wps_sfw_subscription_initial_signup_price', true ),
			);

			// WCS length counts billing periods, so the source expiry maps
			// only when its unit matches the billing period.
			$expiry_number   = (int) get_post_meta( $product_id, 'wps_sfw_subscription_expiry_number', true );
			$expiry_interval = (string) get_post_meta( $product_id, 'wps_sfw_subscription_expiry_interval', true );
			if ( $expiry_number > 0 && $expiry_interval === $period ) {
				$meta['_subscription_length'] = $expiry_number;
			}

			$trial_number = (int) get_post_meta( $product_id, 'wps_sfw_subscription_free_trial_number', true );
			if ( $trial_number > 0 ) {
				$trial_interval                     = (string) get_post_meta( $product_id, 'wps_sfw_subscription_free_trial_interval', true );
				$meta['_subscription_trial_length'] = $trial_number;
				$meta['_subscription_trial_period'] = in_array( $trial_interval, array( 'day', 'week', 'month', 'year' ), true ) ? $trial_interval : $period;
			}

			$maps[] = array(
				'product_id' => $product_id,
				'meta'       => array_filter(
					$meta,
					static function ( $value ) {
						return '' !== (string) $value;
					}
				),
				'error'      => null,
			);
		}

		return $maps;
	}

	/**
	 * The store holding the source data.
	 *
	 * @return string hpos or posts.
	 */
	private function store() {
		global $wpdb;

		if ( null !== $this->store ) {
			return $this->store;
		}

		$hpos_count = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wc_orders' ) ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
			$hpos_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = %s", self::ORDER_TYPE ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
		$posts_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'trash'", self::ORDER_TYPE ) );

		$this->store = $hpos_count >= $posts_count ? 'hpos' : 'posts';

		return $this->store;
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
