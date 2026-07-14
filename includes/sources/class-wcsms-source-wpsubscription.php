<?php
/**
 * WPSubscription (ConversWP) source adapter.
 *
 * Source model, mapped from the plugin's code:
 *
 * - Subscriptions are a CPT subscrpt_order whose lifecycle is the post
 *   status itself: pending, active, on_hold, cancelled, expired, and
 *   pe_cancelled (pending cancellation). Underscored slugs map to the WCS
 *   hyphenated ones.
 * - Billing period and interval live on the parent order's line item in a
 *   serialized _subscrpt_meta array: time is the interval count and type
 *   the period. The subscription meta points at the item.
 * - Dates are Unix timestamps: _subscrpt_start_date and _subscrpt_next_date.
 * - The subscrpt_order_relation table links orders (new, renew,
 *   early-renew), but only exists once the plugin ran its installer; the
 *   adapter falls back to subscription meta when the table is absent.
 * - Stripe billing is store-managed off-session PaymentIntents on WC
 *   Stripe's own keys on the parent order, so auto-renewal carries over
 *   the same way the WP Swings adapter does it.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for WPSubscription data.
 */
class WCSMS_Source_WPSubscription extends WCSMS_Source_Adapter {

	const POST_TYPE = 'subscrpt_order';

	/**
	 * Gateways whose tokens live in the WC Stripe store on the parent order.
	 *
	 * @var string[]
	 */
	const STRIPE_TOKEN_KEYS = array( '_stripe_customer_id', '_stripe_source_id' );

	/**
	 * Adapter id, matching the scanner.
	 *
	 * @return string
	 */
	public function id() {
		return 'wpsubscription';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'WPSubscription (ConversWP)';
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
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_status FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'trash' ORDER BY ID ASC LIMIT %d, %d", self::POST_TYPE, $offset, $limit ), ARRAY_A );

		$results = array();

		foreach ( $rows as $row ) {
			$results[] = $this->build( (int) $row['ID'], (string) $row['post_status'] );
		}

		return $results;
	}

	/**
	 * Build one normalized record.
	 *
	 * @param int    $id          Source subscription id.
	 * @param string $post_status Source post status.
	 * @return array
	 */
	private function build( $id, $post_status ) {
		$status = $this->map_status( $post_status );

		if ( '' === $status ) {
			return $this->failure(
				$id,
				sprintf(
					/* translators: %s: source post status. */
					__( 'Unrecognized source status "%s".', 'subscriptions-migration-suite-for-woocommerce' ),
					$post_status
				)
			);
		}

		$parent_order_id = (int) get_post_meta( $id, '_subscrpt_order_id', true );

		if ( 0 === $parent_order_id ) {
			$parent_order_id = $this->parent_from_relation_table( $id );
		}

		$parent_order = $parent_order_id ? wc_get_order( $parent_order_id ) : false;

		$terms = $this->billing_terms( $id );

		if ( null === $terms ) {
			return $this->failure( $id, __( 'Billing terms could not be read from the parent order item (_subscrpt_meta).', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$dates = array_filter(
			array(
				'start'        => $this->timestamp_to_date( get_post_meta( $id, '_subscrpt_start_date', true ) ),
				'next_payment' => $this->timestamp_to_date( get_post_meta( $id, '_subscrpt_next_date', true ) ),
				'trial_end'    => $this->timestamp_to_date( get_post_meta( $id, '_subscrpt_trial', true ) ),
			)
		);

		if ( 'pending-cancel' === $status ) {
			// Pending cancellation serves until the next renewal date, which
			// becomes the paid-until end date.
			if ( ! empty( $dates['next_payment'] ) ) {
				$dates['end'] = $dates['next_payment'];
			}
			unset( $dates['next_payment'] );
		}

		if ( in_array( $status, array( 'cancelled', 'expired' ), true ) ) {
			unset( $dates['next_payment'] );
		}

		$auto_renew = ! in_array( strtolower( (string) get_post_meta( $id, '_subscrpt_auto_renew', true ) ), array( '', 'no', 'off', '0', 'false' ), true );

		$payment_method = $parent_order ? $parent_order->get_payment_method() : '';
		$manual         = ! $auto_renew || '' === $payment_method;

		$payment = array(
			'method'       => $manual ? '' : $payment_method,
			'method_title' => $parent_order ? $parent_order->get_payment_method_title() : '',
		);

		if ( ! $manual && $parent_order && 0 === strpos( $payment_method, 'stripe' ) ) {
			foreach ( self::STRIPE_TOKEN_KEYS as $key ) {
				$value = $parent_order->get_meta( $key );
				if ( '' !== (string) $value ) {
					$payment['post_meta'][ $key ] = (string) $value;
				}
			}
		}

		$price = (float) get_post_meta( $id, '_subscrpt_price', true );

		$record = array(
			'source'                  => $this->id(),
			'source_id'               => (string) $id,
			'customer_id'             => $parent_order ? $parent_order->get_customer_id() : (int) get_post_field( 'post_author', $id ),
			'status'                  => $status,
			'currency'                => $parent_order ? $parent_order->get_currency() : get_woocommerce_currency(),
			'billing_period'          => $terms['period'],
			'billing_interval'        => $terms['interval'],
			'dates'                   => $dates,
			'requires_manual_renewal' => $manual,
			'payment'                 => $payment,
			'billing_address'         => $parent_order ? $parent_order->get_address( 'billing' ) : array(),
			'shipping_address'        => $parent_order ? $parent_order->get_address( 'shipping' ) : array(),
			'items'                   => $this->items( $id, $price ),
			'totals'                  => $price > 0 ? array( 'total' => $price ) : array(),
			'parent_order_id'         => $parent_order ? $parent_order_id : 0,
			'renewal_order_ids'       => $this->renewal_order_ids( $id, $parent_order_id ),
			'order_notes'             => array(
				sprintf(
					/* translators: 1: source subscription id, 2: source status. */
					__( 'Migrated from WPSubscription #%1$d (source status: %2$s).', 'subscriptions-migration-suite-for-woocommerce' ),
					$id,
					$post_status
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
	 * Map a source post status to a WCS status.
	 *
	 * @param string $post_status Source post status.
	 * @return string Empty when unrecognized.
	 */
	private function map_status( $post_status ) {
		$map = array(
			'pending'      => 'pending',
			'active'       => 'active',
			'on_hold'      => 'on-hold',
			'cancelled'    => 'cancelled',
			'expired'      => 'expired',
			'pe_cancelled' => 'pending-cancel',
		);

		return isset( $map[ $post_status ] ) ? $map[ $post_status ] : '';
	}

	/**
	 * Billing period and interval from the parent order item's
	 * _subscrpt_meta array.
	 *
	 * @param int $id Source subscription id.
	 * @return array{period: string, interval: int}|null
	 */
	private function billing_terms( $id ) {
		$item_id = (int) get_post_meta( $id, '_subscrpt_order_item_id', true );

		if ( 0 === $item_id ) {
			return null;
		}

		$raw = wc_get_order_item_meta( $item_id, '_subscrpt_meta', true );

		if ( ! is_array( $raw ) || empty( $raw['type'] ) ) {
			return null;
		}

		$map = array(
			'day'    => 'day',
			'days'   => 'day',
			'week'   => 'week',
			'weeks'  => 'week',
			'month'  => 'month',
			'months' => 'month',
			'year'   => 'year',
			'years'  => 'year',
		);

		$period = isset( $map[ strtolower( (string) $raw['type'] ) ] ) ? $map[ strtolower( (string) $raw['type'] ) ] : '';

		if ( '' === $period ) {
			return null;
		}

		return array(
			'period'   => $period,
			'interval' => isset( $raw['time'] ) ? max( 1, (int) $raw['time'] ) : 1,
		);
	}

	/**
	 * Convert a Unix-timestamp meta value to a MySQL UTC datetime, treating
	 * empty and junk values as "not set".
	 *
	 * @param mixed $value Raw meta value.
	 * @return string Empty string when not set.
	 */
	private function timestamp_to_date( $value ) {
		if ( empty( $value ) || ! is_numeric( $value ) || (int) $value <= 0 ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i:s', (int) $value );
	}

	/**
	 * Renewal orders from the relation table (renew and early-renew rows),
	 * when the installer created it.
	 *
	 * @param int $id        Source subscription id.
	 * @param int $parent_id Parent order id, excluded from the list.
	 * @return int[]
	 */
	private function renewal_order_ids( $id, $parent_id ) {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'subscrpt_order_relation' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return array();
		}

		// Table name is the prefix plus a literal, escaped with esc_sql;
		// identifiers cannot be parameterized.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only migration source scan; identifier is static and escaped.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM `{$table}` WHERE subscription_id = %d AND type IN ( 'renew', 'early-renew' ) ORDER BY id ASC", $id ) );

		$renewals = array();
		foreach ( (array) $ids as $order_id ) {
			$order_id = (int) $order_id;
			if ( $order_id > 0 && $order_id !== $parent_id ) {
				$renewals[] = $order_id;
			}
		}

		return $renewals;
	}

	/**
	 * Parent order via the relation table, when the installer created it.
	 *
	 * @param int $id Source subscription id.
	 * @return int
	 */
	private function parent_from_relation_table( $id ) {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'subscrpt_order_relation' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return 0;
		}

		// Table name is the prefix plus a literal, escaped with esc_sql;
		// identifiers cannot be parameterized.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only migration source scan; identifier is static and escaped.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM `{$table}` WHERE subscription_id = %d AND type = 'new' ORDER BY id ASC LIMIT 1", $id ) );
	}

	/**
	 * The subscribed product as a line item.
	 *
	 * @param int   $id    Source subscription id.
	 * @param float $price Recurring price.
	 * @return array<int, array>
	 */
	private function items( $id, $price ) {
		$variation_id = (int) get_post_meta( $id, '_subscrpt_variation_id', true );
		$product_id   = $variation_id ? $variation_id : (int) get_post_meta( $id, '_subscrpt_product_id', true );

		if ( 0 === $product_id ) {
			return array();
		}

		return array(
			array(
				'product_id' => $product_id,
				'quantity'   => 1,
				'subtotal'   => $price > 0 ? $price : null,
				'total'      => $price > 0 ? $price : null,
			),
		);
	}

	/**
	 * Product conversion maps for WPSubscription products.
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
			 WHERE pm.meta_key = '_subscrpt_enabled' AND pm.meta_value IN ( 'yes', '1', 'on', 'true' )
			 ORDER BY pm.post_id ASC"
		);

		$period_map = array(
			'day'    => 'day',
			'days'   => 'day',
			'week'   => 'week',
			'weeks'  => 'week',
			'month'  => 'month',
			'months' => 'month',
			'year'   => 'year',
			'years'  => 'year',
		);

		foreach ( $product_ids as $product_id ) {
			$product_id = (int) $product_id;
			$raw_period = strtolower( (string) get_post_meta( $product_id, '_subscrpt_timing_option', true ) );
			$period     = isset( $period_map[ $raw_period ] ) ? $period_map[ $raw_period ] : '';

			if ( '' === $period ) {
				$maps[] = array(
					'product_id' => $product_id,
					'meta'       => array(),
					'error'      => sprintf(
						/* translators: %s: period value from the source product. */
						__( 'Unrecognized subscription period "%s" on the source product.', 'subscriptions-migration-suite-for-woocommerce' ),
						$raw_period
					),
				);
				continue;
			}

			$meta = array(
				'_subscription_period'          => $period,
				'_subscription_period_interval' => max( 1, (int) get_post_meta( $product_id, '_subscrpt_timing_per', true ) ),
				'_subscription_sign_up_fee'     => (string) get_post_meta( $product_id, '_subscrpt_signup_fee', true ),
			);

			$trial = (int) get_post_meta( $product_id, '_subscrpt_trial_timing_per', true );
			if ( $trial > 0 ) {
				$trial_period                       = strtolower( (string) get_post_meta( $product_id, '_subscrpt_trial_timing_option', true ) );
				$meta['_subscription_trial_length'] = $trial;
				$meta['_subscription_trial_period'] = isset( $period_map[ $trial_period ] ) ? $period_map[ $trial_period ] : $period;
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
	 * WPSubscription renews on WP-Cron (subscrpt_hourly_cron), which dies
	 * with plugin deactivation; its occasional grace-period single actions
	 * are subscription-specific and expire on their own without the plugin
	 * to handle them. Nothing to clear via Action Scheduler.
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
