<?php
/**
 * Sublium (FunnelKit) source adapter.
 *
 * Source model, mapped from the plugin's code:
 *
 * - Subscriptions live in custom tables: sublium_wcs_subscriptions holds
 *   the row (integer status codes 1-11, gateway, gateway_mode, dates,
 *   parent_order_id, items JSON) and sublium_wcs_subscription_meta is an
 *   EAV table with billing terms and address snapshots.
 * - gateway_mode decides everything about payments: 1 is store-managed
 *   (Sublium's own cron charges saved WC payment sources, which can carry
 *   over), 2 is offsite (PayPal hosts the subscription itself). Offsite
 *   rows are refused with a resolution message: nothing written to this
 *   site can preserve billing PayPal runs on its own servers, and
 *   migrating the record while PayPal keeps charging would double bill.
 * - Billing terms sit in the plan model and are denormalized into the
 *   subscription meta; value formats vary, so parsing is tolerant and a
 *   row whose terms cannot be decoded fails with a clear message.
 *
 * Product conversion is not offered for this source: Sublium attaches
 * shared plans to products and taxonomies rather than storing per-product
 * settings, so subscription products need manual setup in WooCommerce
 * Subscriptions.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for Sublium subscription data.
 */
class WCSMS_Source_Sublium extends WCSMS_Source_Adapter {

	/**
	 * Integer status codes to WCS statuses. Completed (8) means the
	 * subscription ran its planned length, which is WCS expired. Overdue,
	 * unpaid, paused, and disputed all pause billing, which is on-hold.
	 *
	 * @var array<int, string>
	 */
	const STATUS_MAP = array(
		1  => 'pending',
		2  => 'active',
		3  => 'active',
		4  => 'on-hold',
		5  => 'on-hold',
		6  => 'on-hold',
		7  => 'on-hold',
		8  => 'expired',
		9  => 'cancelled',
		10 => 'pending-cancel',
		11 => 'on-hold',
	);

	/**
	 * Token meta copied from the parent order for store-managed Stripe
	 * subscriptions: the official WC Stripe keys plus FunnelKit Stripe's
	 * own, so renewals continue whichever of the two gateways stays active.
	 *
	 * @var string[]
	 */
	const TOKEN_KEYS = array( '_stripe_customer_id', '_stripe_source_id', '_fkwcs_source_id', '_fkwcs_payment_mode' );

	/**
	 * Adapter id, matching the scanner.
	 *
	 * @return string
	 */
	public function id() {
		return 'sublium';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'Sublium (FunnelKit)';
	}

	/**
	 * Count migratable records.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;

		if ( ! $this->tables_exist() ) {
			return 0;
		}

		// Table name is the prefix plus a literal.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only migration source scan; identifier is static.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}sublium_wcs_subscriptions`" );
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

		if ( ! $this->tables_exist() ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only migration source scan; identifier is static.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}sublium_wcs_subscriptions` ORDER BY id ASC LIMIT %d, %d", $offset, $limit ), ARRAY_A );

		$results = array();

		foreach ( $rows as $row ) {
			$results[] = $this->build( $row );
		}

		return $results;
	}

	/**
	 * Build one normalized record from a subscriptions-table row.
	 *
	 * @param array $row Table row.
	 * @return array
	 */
	private function build( $row ) {
		$id = (int) $row['id'];

		if ( 2 === (int) $row['gateway_mode'] ) {
			return $this->failure(
				$id,
				__( 'This subscription is billed by the gateway itself (offsite PayPal). Cancel or migrate it inside PayPal first, then re-scan; importing it now would leave PayPal charging alongside WooCommerce Subscriptions.', 'subscriptions-migration-suite-for-woocommerce' )
			);
		}

		$status_code = (int) $row['status'];

		if ( ! isset( self::STATUS_MAP[ $status_code ] ) ) {
			return $this->failure(
				$id,
				sprintf(
					/* translators: %d: numeric status code from the source. */
					__( 'Unknown source status code %d.', 'subscriptions-migration-suite-for-woocommerce' ),
					$status_code
				)
			);
		}

		$status = self::STATUS_MAP[ $status_code ];
		$meta   = $this->read_meta( $id );
		$terms  = $this->billing_terms( $meta );

		if ( null === $terms ) {
			return $this->failure( $id, __( 'Billing terms could not be decoded from the subscription meta.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$dates = array_filter(
			array(
				'start'        => $this->pick_date( $row, 'created_at' ),
				'next_payment' => $this->pick_date( $row, 'next_payment_date' ),
				'end'          => $this->pick_date( $row, 'end_date' ),
			)
		);

		if ( in_array( $status, array( 'cancelled', 'expired' ), true ) ) {
			unset( $dates['next_payment'] );
		}
		if ( 'pending-cancel' === $status && empty( $dates['end'] ) && ! empty( $dates['next_payment'] ) ) {
			$dates['end'] = $dates['next_payment'];
			unset( $dates['next_payment'] );
		}

		$gateway         = (string) $row['gateway'];
		$parent_order_id = (int) $row['parent_order_id'];
		$parent_order    = $parent_order_id ? wc_get_order( $parent_order_id ) : false;
		$manual          = '' === $gateway;

		$payment = array(
			'method'       => $manual ? '' : $gateway,
			'method_title' => '',
		);

		if ( ! $manual && $parent_order && false !== strpos( $gateway, 'stripe' ) ) {
			foreach ( self::TOKEN_KEYS as $key ) {
				$value = $parent_order->get_meta( $key );
				if ( '' !== (string) $value ) {
					$payment['post_meta'][ $key ] = (string) $value;
				}
			}
		}

		$items = $this->items( $row );

		if ( empty( $items ) ) {
			return $this->failure( $id, __( 'No line items could be decoded from the items column.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$record = array(
			'source'                  => $this->id(),
			'source_id'               => (string) $id,
			'customer_id'             => (int) $row['user_id'],
			'status'                  => $status,
			'currency'                => '' !== (string) $row['currency'] ? (string) $row['currency'] : get_woocommerce_currency(),
			'billing_period'          => $terms['period'],
			'billing_interval'        => $terms['interval'],
			'dates'                   => $dates,
			'requires_manual_renewal' => $manual,
			'payment'                 => $payment,
			'billing_address'         => $this->address( $meta, 'billing_details' ),
			'shipping_address'        => $this->address( $meta, 'shipping_details' ),
			'items'                   => $items,
			'totals'                  => $this->totals( $row ),
			'parent_order_id'         => $parent_order ? $parent_order_id : 0,
			'renewal_order_ids'       => $this->renewal_order_ids( $meta, $parent_order_id ),
			'order_notes'             => array(
				sprintf(
					/* translators: 1: source subscription id, 2: numeric source status. */
					__( 'Migrated from Sublium subscription #%1$d (source status code: %2$d).', 'subscriptions-migration-suite-for-woocommerce' ),
					$id,
					$status_code
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
	 * Billing period and interval from the denormalized subscription meta.
	 *
	 * The frequency is the unit and the interval the count, but value
	 * formats vary between plan versions, so both orderings are accepted:
	 * whichever of the two values matches a period word is the unit.
	 *
	 * @param array $meta Subscription meta.
	 * @return array{period: string, interval: int}|null
	 */
	private function billing_terms( $meta ) {
		$period_map = array(
			'day'    => 'day',
			'days'   => 'day',
			'daily'  => 'day',
			'week'   => 'week',
			'weeks'  => 'week',
			'weekly' => 'week',
			'month'   => 'month',
			'months'  => 'month',
			'monthly' => 'month',
			'year'   => 'year',
			'years'  => 'year',
			'yearly' => 'year',
			'annual' => 'year',
		);

		$frequency = isset( $meta['billing_frequency'] ) ? strtolower( trim( (string) $meta['billing_frequency'] ) ) : '';
		$interval  = isset( $meta['billing_interval'] ) ? strtolower( trim( (string) $meta['billing_interval'] ) ) : '';

		$period = '';
		$count  = 0;

		foreach ( array( array( $frequency, $interval ), array( $interval, $frequency ) ) as $pair ) {
			if ( isset( $period_map[ $pair[0] ] ) && ( '' === $pair[1] || is_numeric( $pair[1] ) ) ) {
				$period = $period_map[ $pair[0] ];
				$count  = '' === $pair[1] ? 1 : max( 1, (int) $pair[1] );
				break;
			}
		}

		if ( '' === $period ) {
			return null;
		}

		return array(
			'period'   => $period,
			'interval' => $count,
		);
	}

	/**
	 * Prefer the UTC variant of a date column.
	 *
	 * @param array  $row    Table row.
	 * @param string $column Base column name.
	 * @return string MySQL datetime or empty.
	 */
	private function pick_date( $row, $column ) {
		foreach ( array( $column . '_utc', $column ) as $key ) {
			if ( ! empty( $row[ $key ] ) && '0000-00-00 00:00:00' !== $row[ $key ] ) {
				$timestamp = is_numeric( $row[ $key ] ) ? (int) $row[ $key ] : strtotime( (string) $row[ $key ] );
				if ( $timestamp > 0 ) {
					return gmdate( 'Y-m-d H:i:s', $timestamp );
				}
			}
		}

		return '';
	}

	/**
	 * Line items from the items JSON column.
	 *
	 * @param array $row Table row.
	 * @return array<int, array>
	 */
	private function items( $row ) {
		$decoded = json_decode( (string) $row['items'], true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$items = array();

		foreach ( $decoded as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$product_id = 0;
			foreach ( array( 'variation_id', 'product_id', 'id' ) as $key ) {
				if ( ! empty( $item[ $key ] ) && is_numeric( $item[ $key ] ) ) {
					$product_id = (int) $item[ $key ];
					break;
				}
			}

			if ( 0 === $product_id ) {
				continue;
			}

			$quantity = 1;
			foreach ( array( 'quantity', 'qty' ) as $key ) {
				if ( ! empty( $item[ $key ] ) && is_numeric( $item[ $key ] ) ) {
					$quantity = max( 1, (int) $item[ $key ] );
					break;
				}
			}

			$total = null;
			foreach ( array( 'total', 'line_total', 'price' ) as $key ) {
				if ( isset( $item[ $key ] ) && is_numeric( $item[ $key ] ) ) {
					$total = (float) $item[ $key ];
					break;
				}
			}

			$items[] = array(
				'product_id' => $product_id,
				'quantity'   => $quantity,
				'subtotal'   => $total,
				'total'      => $total,
			);
		}

		return $items;
	}

	/**
	 * Order totals from the totals column, which may be a plain number or
	 * a JSON object with a total key.
	 *
	 * @param array $row Table row.
	 * @return array
	 */
	private function totals( $row ) {
		$raw = (string) $row['totals'];

		if ( is_numeric( $raw ) && (float) $raw > 0 ) {
			return array( 'total' => (float) $raw );
		}

		$decoded = json_decode( $raw, true );

		if ( is_array( $decoded ) && isset( $decoded['total'] ) && is_numeric( $decoded['total'] ) ) {
			return array( 'total' => (float) $decoded['total'] );
		}

		return array();
	}

	/**
	 * Address snapshot from a meta value that may be JSON or serialized.
	 *
	 * @param array  $meta Subscription meta.
	 * @param string $key  billing_details or shipping_details.
	 * @return array
	 */
	private function address( $meta, $key ) {
		if ( empty( $meta[ $key ] ) ) {
			return array();
		}

		$decoded = json_decode( (string) $meta[ $key ], true );

		if ( ! is_array( $decoded ) ) {
			$decoded = maybe_unserialize( $meta[ $key ] );
		}

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$fields  = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' );
		$address = array();

		foreach ( $fields as $field ) {
			if ( ! empty( $decoded[ $field ] ) && is_scalar( $decoded[ $field ] ) ) {
				$address[ $field ] = (string) $decoded[ $field ];
			}
		}

		return $address;
	}

	/**
	 * Renewal orders from the renewal_orders JSON array in the meta table.
	 *
	 * @param array $meta      Subscription meta.
	 * @param int   $parent_id Parent order id, excluded from the list.
	 * @return int[]
	 */
	private function renewal_order_ids( $meta, $parent_id ) {
		if ( empty( $meta['renewal_orders'] ) ) {
			return array();
		}

		$decoded = json_decode( (string) $meta['renewal_orders'], true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$renewals = array();
		foreach ( $decoded as $order_id ) {
			$order_id = (int) $order_id;
			if ( $order_id > 0 && $order_id !== $parent_id ) {
				$renewals[] = $order_id;
			}
		}

		return $renewals;
	}

	/**
	 * All meta rows for a subscription from the EAV table.
	 *
	 * @param int $id Subscription id.
	 * @return array<string, string>
	 */
	private function read_meta( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only migration source scan; identifier is static.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM `{$wpdb->prefix}sublium_wcs_subscription_meta` WHERE subscription_id = %d", $id ), ARRAY_A );

		$meta = array();
		foreach ( (array) $rows as $row ) {
			$meta[ $row['meta_key'] ] = $row['meta_value'];
		}

		return $meta;
	}

	/**
	 * Whether the source tables exist.
	 *
	 * @return bool
	 */
	private function tables_exist() {
		global $wpdb;

		static $exists = null;

		if ( null === $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup.
			$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sublium_wcs_subscriptions' ) )
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup.
				&& (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'sublium_wcs_subscription_meta' ) );
		}

		return $exists;
	}

	/**
	 * Sublium renews on its own WP-Cron events (sublium_wcs_subscription_
	 * scheduler every ten minutes), which stop firing callbacks once the
	 * plugin is deactivated. No Action Scheduler hooks to clear.
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
