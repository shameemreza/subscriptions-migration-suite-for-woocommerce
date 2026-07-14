<?php
/**
 * Flexible Subscriptions (WP Desk) source adapter.
 *
 * Reads fsb_subscription orders from whichever store holds them (HPOS
 * tables or posts) and maps them to normalized records:
 *
 * - Status slugs are already WCS slugs; the wc- prefix is stripped.
 * - _billing_frequency is one ISO-8601 duration (P1M, P2W) and splits into
 *   billing period and interval. Composite durations (P1M15D) have no WCS
 *   representation and fail the row with a clear message.
 * - _current_period_end_utc is the next payment date; other _*_date_utc
 *   keys map one to one. All are already Y-m-d H:i:s UTC.
 * - Line items live in the standard WooCommerce order item tables in both
 *   storage modes.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for Flexible Subscriptions data.
 */
class WCSMS_Source_Flexible_Subscriptions extends WCSMS_Source_Adapter {

	const ORDER_TYPE = 'fsb_subscription';

	/**
	 * Subscription meta keys read from the source.
	 *
	 * @var string[]
	 */
	const META_KEYS = array(
		'_billing_frequency',
		'_start_date_utc',
		'_trial_end_date_utc',
		'_current_period_end_utc',
		'_end_date_utc',
		'_cancelled_date_utc',
		'_requires_manual_renewal',
	);

	/**
	 * Which store holds the data, resolved once.
	 *
	 * @var string|null hpos or posts.
	 */
	private $store = null;

	/**
	 * Adapter id.
	 *
	 * @return string
	 */
	public function id() {
		return 'flexible_subscriptions';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'Flexible Subscriptions (WP Desk)';
	}

	/**
	 * Count migratable records in the resolved store.
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
	 * Fetch a page of source rows as normalized records.
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
	 * Build one normalized record from a source subscription id.
	 *
	 * @param int $id Source subscription id.
	 * @return array{source_ref: string, record: array|null, error: string|null}
	 */
	private function build( $id ) {
		$row = 'hpos' === $this->store() ? $this->read_hpos( $id ) : $this->read_posts( $id );

		if ( null === $row ) {
			return $this->failure( $id, __( 'Source row could not be read.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$status = str_replace( 'wc-', '', $row['status'] );

		if ( in_array( $status, array( 'trash', 'auto-draft', 'draft' ), true ) ) {
			return $this->failure( $id, __( 'Source subscription is trashed or a draft.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$frequency = $this->split_frequency( $row['meta']['_billing_frequency'] ?? '' );

		if ( null === $frequency ) {
			return $this->failure(
				$id,
				sprintf(
					/* translators: %s: the ISO-8601 duration from the source. */
					__( 'Billing frequency "%s" cannot be represented as a single WooCommerce Subscriptions period and interval.', 'subscriptions-migration-suite-for-woocommerce' ),
					isset( $row['meta']['_billing_frequency'] ) ? $row['meta']['_billing_frequency'] : ''
				)
			);
		}

		$dates = array();
		$map   = array(
			'_start_date_utc'          => 'start',
			'_trial_end_date_utc'      => 'trial_end',
			'_current_period_end_utc'  => 'next_payment',
			'_end_date_utc'            => 'end',
			'_cancelled_date_utc'      => 'cancelled',
		);
		foreach ( $map as $source_key => $target_key ) {
			if ( ! empty( $row['meta'][ $source_key ] ) ) {
				$dates[ $target_key ] = $row['meta'][ $source_key ];
			}
		}

		// Ended subscriptions keep no future payment; WCS rejects a next
		// payment date on them anyway.
		if ( in_array( $status, array( 'cancelled', 'expired', 'switched', 'pending-cancel' ), true ) ) {
			unset( $dates['next_payment'] );
		}

		$manual = isset( $row['meta']['_requires_manual_renewal'] )
			&& in_array( strtolower( (string) $row['meta']['_requires_manual_renewal'] ), array( 'true', '1', 'yes' ), true );

		$parent_order_id = 0;
		if ( $row['parent_order_id'] && wc_get_order( $row['parent_order_id'] ) ) {
			$parent_order_id = (int) $row['parent_order_id'];
		}

		$record = array(
			'source'                  => $this->id(),
			'source_id'               => (string) $id,
			'customer_id'             => $row['customer_id'],
			'status'                  => $status,
			'currency'                => $row['currency'],
			'billing_period'          => $frequency['period'],
			'billing_interval'        => $frequency['interval'],
			'dates'                   => $dates,
			'requires_manual_renewal' => $manual,
			'payment'                 => array(
				'method'       => $row['payment_method'],
				'method_title' => $row['payment_method_title'],
			),
			'billing_address'         => $row['billing_address'],
			'shipping_address'        => $row['shipping_address'],
			'items'                   => $this->read_items( $id ),
			'totals'                  => $row['totals'],
			'parent_order_id'         => $parent_order_id,
			'order_notes'             => array(
				sprintf(
					/* translators: %d: source subscription id. */
					__( 'Migrated from Flexible Subscriptions subscription #%d.', 'subscriptions-migration-suite-for-woocommerce' ),
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
	 * Split an ISO-8601 duration into a WCS period and interval.
	 *
	 * @param string $duration Duration string, for example P1M or P2W.
	 * @return array{period: string, interval: int}|null Null when composite or invalid.
	 */
	private function split_frequency( $duration ) {
		if ( '' === $duration ) {
			return null;
		}

		try {
			$interval = new DateInterval( $duration );
		} catch ( Exception $e ) {
			return null;
		}

		$units = array_filter(
			array(
				'year'  => $interval->y,
				'month' => $interval->m,
				'day'   => $interval->d,
			)
		);

		if ( 1 !== count( $units ) || $interval->h || $interval->i || $interval->s ) {
			return null;
		}

		$period = key( $units );
		$length = (int) current( $units );

		// DateInterval turns P2W into 14 days; whole weeks read better as
		// weeks and match how the source stores them.
		if ( 'day' === $period && 0 === $length % 7 ) {
			return array(
				'period'   => 'week',
				'interval' => $length / 7,
			);
		}

		return array(
			'period'   => $period,
			'interval' => $length,
		);
	}

	/**
	 * Read the subscription row from the HPOS tables.
	 *
	 * @param int $id Subscription id.
	 * @return array|null
	 */
	private function read_hpos( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT status, currency, customer_id, payment_method, payment_method_title, total_amount, parent_order_id FROM {$wpdb->prefix}wc_orders WHERE id = %d", $id ), ARRAY_A );

		if ( null === $order ) {
			return null;
		}

		$meta = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
		$meta_rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d", $id ), ARRAY_A );
		foreach ( $meta_rows as $meta_row ) {
			if ( in_array( $meta_row['meta_key'], self::META_KEYS, true ) ) {
				$meta[ $meta_row['meta_key'] ] = $meta_row['meta_value'];
			}
		}

		$addresses = array(
			'billing'  => array(),
			'shipping' => array(),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
		$address_rows = $wpdb->get_results( $wpdb->prepare( "SELECT address_type, first_name, last_name, company, address_1, address_2, city, state, postcode, country, email, phone FROM {$wpdb->prefix}wc_order_addresses WHERE order_id = %d", $id ), ARRAY_A );
		foreach ( $address_rows as $address_row ) {
			$type = $address_row['address_type'];
			unset( $address_row['address_type'] );
			if ( isset( $addresses[ $type ] ) ) {
				// Address columns are nullable; keep only real values.
				$addresses[ $type ] = array_filter(
					$address_row,
					static function ( $value ) {
						return null !== $value && '' !== $value;
					}
				);
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
		$operational = $wpdb->get_row( $wpdb->prepare( "SELECT discount_total_amount, shipping_total_amount, shipping_tax_amount FROM {$wpdb->prefix}wc_order_operational_data WHERE order_id = %d", $id ), ARRAY_A );

		return array(
			'status'               => (string) $order['status'],
			'currency'             => (string) $order['currency'],
			'customer_id'          => (int) $order['customer_id'],
			'payment_method'       => (string) $order['payment_method'],
			'payment_method_title' => (string) $order['payment_method_title'],
			'parent_order_id'      => (int) $order['parent_order_id'],
			'billing_address'      => $addresses['billing'],
			'shipping_address'     => $addresses['shipping'],
			'totals'               => array_filter(
				array(
					'total'          => (float) $order['total_amount'],
					'discount_total' => $operational ? (float) $operational['discount_total_amount'] : 0.0,
					'shipping_total' => $operational ? (float) $operational['shipping_total_amount'] : 0.0,
					'shipping_tax'   => $operational ? (float) $operational['shipping_tax_amount'] : 0.0,
				)
			),
			'meta'                 => $meta,
		);
	}

	/**
	 * Read the subscription row from the posts store.
	 *
	 * @param int $id Subscription id.
	 * @return array|null
	 */
	private function read_posts( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
		$post = $wpdb->get_row( $wpdb->prepare( "SELECT post_status, post_parent FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );

		if ( null === $post ) {
			return null;
		}

		$meta = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
		$meta_rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $id ), ARRAY_A );
		foreach ( $meta_rows as $meta_row ) {
			$meta[ $meta_row['meta_key'] ] = $meta_row['meta_value'];
		}

		$address = function ( $prefix ) use ( $meta ) {
			$fields = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' );
			$out    = array();
			foreach ( $fields as $field ) {
				if ( ! empty( $meta[ '_' . $prefix . '_' . $field ] ) ) {
					$out[ $field ] = $meta[ '_' . $prefix . '_' . $field ];
				}
			}
			return $out;
		};

		return array(
			'status'               => (string) $post['post_status'],
			'currency'             => isset( $meta['_order_currency'] ) ? $meta['_order_currency'] : get_woocommerce_currency(),
			'customer_id'          => isset( $meta['_customer_user'] ) ? (int) $meta['_customer_user'] : 0,
			'payment_method'       => isset( $meta['_payment_method'] ) ? $meta['_payment_method'] : '',
			'payment_method_title' => isset( $meta['_payment_method_title'] ) ? $meta['_payment_method_title'] : '',
			'parent_order_id'      => (int) $post['post_parent'],
			'billing_address'      => $address( 'billing' ),
			'shipping_address'     => $address( 'shipping' ),
			'totals'               => array_filter(
				array(
					'total'          => isset( $meta['_order_total'] ) ? (float) $meta['_order_total'] : 0.0,
					'discount_total' => isset( $meta['_cart_discount'] ) ? (float) $meta['_cart_discount'] : 0.0,
					'shipping_total' => isset( $meta['_order_shipping'] ) ? (float) $meta['_order_shipping'] : 0.0,
					'shipping_tax'   => isset( $meta['_order_shipping_tax'] ) ? (float) $meta['_order_shipping_tax'] : 0.0,
				)
			),
			'meta'                 => array_intersect_key( $meta, array_flip( self::META_KEYS ) ),
		);
	}

	/**
	 * Read line items from the standard WooCommerce order item tables, which
	 * both storage modes use.
	 *
	 * @param int $id Subscription id.
	 * @return array<int, array>
	 */
	private function read_items( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
		$item_ids = $wpdb->get_col( $wpdb->prepare( "SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d AND order_item_type = 'line_item' ORDER BY order_item_id ASC", $id ) );

		$items = array();

		foreach ( $item_ids as $item_id ) {
			$meta = array();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
			$meta_rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE order_item_id = %d", $item_id ), ARRAY_A );
			foreach ( $meta_rows as $meta_row ) {
				$meta[ $meta_row['meta_key'] ] = $meta_row['meta_value'];
			}

			$product_id = ! empty( $meta['_variation_id'] ) ? (int) $meta['_variation_id'] : ( isset( $meta['_product_id'] ) ? (int) $meta['_product_id'] : 0 );

			if ( 0 === $product_id ) {
				continue;
			}

			$items[] = array(
				'product_id' => $product_id,
				'quantity'   => isset( $meta['_qty'] ) ? (int) $meta['_qty'] : 1,
				'subtotal'   => isset( $meta['_line_subtotal'] ) ? (float) $meta['_line_subtotal'] : null,
				'total'      => isset( $meta['_line_total'] ) ? (float) $meta['_line_total'] : null,
			);
		}

		return $items;
	}

	/**
	 * Product conversion maps. Flexible Subscriptions products are the WCS
	 * schema with an _fsb_ prefix; the period is a single letter (M) and
	 * the recurring price lives in the native _price.
	 *
	 * @return array<int, array>
	 */
	public function product_maps() {
		global $wpdb;

		$maps = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration source scan.
		$rows = $wpdb->get_results(
			"SELECT tr.object_id, t.slug
			 FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
			 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			 WHERE t.slug IN ( 'fsb-subscription', 'fsb-variable-subscription' )
			 ORDER BY tr.object_id ASC",
			ARRAY_A
		);

		$letter_periods = array(
			'D' => 'day',
			'W' => 'week',
			'M' => 'month',
			'Y' => 'year',
		);

		foreach ( $rows as $row ) {
			$product_id = (int) $row['object_id'];

			if ( 'fsb-variable-subscription' === $row['slug'] ) {
				$maps[] = array(
					'product_id' => $product_id,
					'meta'       => array(),
					'error'      => __( 'Variable subscription products need per-variation conversion, which is not supported yet.', 'subscriptions-migration-suite-for-woocommerce' ),
				);
				continue;
			}

			$period_letter = strtoupper( (string) get_post_meta( $product_id, '_fsb_subscription_period', true ) );
			$period        = isset( $letter_periods[ $period_letter ] ) ? $letter_periods[ $period_letter ] : '';

			if ( '' === $period ) {
				$maps[] = array(
					'product_id' => $product_id,
					'meta'       => array(),
					'error'      => sprintf(
						/* translators: %s: period value from the source product. */
						__( 'Unrecognized subscription period "%s" on the source product.', 'subscriptions-migration-suite-for-woocommerce' ),
						$period_letter
					),
				);
				continue;
			}

			$meta = array(
				'_subscription_period'          => $period,
				'_subscription_period_interval' => max( 1, (int) get_post_meta( $product_id, '_fsb_subscription_interval', true ) ),
				'_subscription_length'          => (int) get_post_meta( $product_id, '_fsb_subscription_length', true ),
				'_subscription_sign_up_fee'     => (string) get_post_meta( $product_id, '_fsb_subscription_sign_up_fee', true ),
				'_subscription_one_time_shipping' => 'yes' === get_post_meta( $product_id, '_fsb_subscription_one_time_shipping', true ) ? 'yes' : 'no',
				'_subscription_limit'           => (string) get_post_meta( $product_id, '_fsb_subscription_limit', true ),
			);

			$trial_length = (int) get_post_meta( $product_id, '_fsb_subscription_trial_length', true );
			if ( $trial_length > 0 ) {
				$trial_letter                       = strtoupper( (string) get_post_meta( $product_id, '_fsb_subscription_trial_period', true ) );
				$meta['_subscription_trial_length'] = $trial_length;
				$meta['_subscription_trial_period'] = isset( $letter_periods[ $trial_letter ] ) ? $letter_periods[ $trial_letter ] : $period;
			}

			$maps[] = array(
				'product_id' => $product_id,
				'meta'       => array_filter( $meta, 'strlen' ),
				'error'      => null,
			);
		}

		return $maps;
	}

	/**
	 * The store holding the source data. When both stores have rows (an HPOS
	 * switch mid-history), the one with more rows wins.
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
