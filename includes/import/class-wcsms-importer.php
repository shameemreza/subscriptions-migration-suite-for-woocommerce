<?php
/**
 * Import engine.
 *
 * Creates WooCommerce Subscriptions from normalized records through the WCS
 * CRUD API only: wcs_create_subscription(), WC_Subscription setters,
 * update_dates(), and set_payment_method(). No direct writes to order
 * storage, so HPOS and CPT stores both work.
 *
 * Idempotency: every created subscription is stamped with _wcsms_source and
 * _wcsms_source_id. A record whose stamps already exist is skipped, never
 * duplicated.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imports one normalized record at a time.
 */
class WCSMS_Importer {

	const META_SOURCE    = '_wcsms_source';
	const META_SOURCE_ID = '_wcsms_source_id';
	const META_RUN_ID    = '_wcsms_run_id';

	/**
	 * Import a single record.
	 *
	 * @param array $raw     Raw record (see WCSMS_Record for the shape).
	 * @param array $options {
	 *     Run options.
	 *
	 *     @type bool   $dry_run Validate and resolve without writing. Default true.
	 *     @type string $run_id  Identifier stamped on created subscriptions.
	 * }
	 * @return array {
	 *     Row result.
	 *
	 *     @type string $status          created, skipped, dry_run, or failed.
	 *     @type int    $subscription_id Created or existing subscription id.
	 *     @type string $source_id       Record source id, for report lines.
	 *     @type array  $errors          Error strings.
	 *     @type array  $warnings        Warning strings.
	 * }
	 */
	public function import( $raw, $options = array() ) {
		$options = wp_parse_args(
			$options,
			array(
				'dry_run' => true,
				'run_id'  => '',
			)
		);

		$result = array(
			'status'          => 'failed',
			'subscription_id' => 0,
			'source_id'       => isset( $raw['source_id'] ) ? (string) $raw['source_id'] : '',
			'errors'          => array(),
			'warnings'        => array(),
		);

		$record = WCSMS_Record::normalize( $raw );

		if ( is_wp_error( $record ) ) {
			$result['errors'] = $record->get_error_messages();
			return $result;
		}

		$existing_id = $this->find_existing( $record['source'], $record['source_id'] );
		if ( $existing_id ) {
			$result['status']          = 'skipped';
			$result['subscription_id'] = $existing_id;
			/* translators: %d: subscription id. */
			$result['warnings'][] = sprintf( __( 'Already imported as subscription #%d.', 'subscriptions-migration-suite-for-woocommerce' ), $existing_id );
			return $result;
		}

		$customer_id = $this->resolve_customer( $record, $result );
		$items       = $this->resolve_items( $record, $result );
		$this->check_gateway( $record, $result );

		if ( ! empty( $result['errors'] ) ) {
			return $result;
		}

		if ( $options['dry_run'] ) {
			$result['status'] = 'dry_run';
			return $result;
		}

		wc_transaction_query( 'start' );

		try {
			$subscription = $this->create_subscription( $record, $customer_id, $items, $options, $result );

			wc_transaction_query( 'commit' );

			$result['status']          = 'created';
			$result['subscription_id'] = $subscription->get_id();

			/**
			 * Fires after a subscription is imported.
			 *
			 * @param WC_Subscription $subscription The created subscription.
			 * @param array           $record       The normalized record.
			 */
			do_action( 'wcsms_subscription_imported', $subscription, $record );

			WCSMS_Logger::log( sprintf( 'Imported %s:%s as subscription #%d.', $record['source'], $record['source_id'], $subscription->get_id() ) );
		} catch ( Exception $e ) {
			wc_transaction_query( 'rollback' );
			$result['errors'][] = $e->getMessage();
			WCSMS_Logger::log( sprintf( 'Import failed for %s:%s. %s', $record['source'], $record['source_id'], $e->getMessage() ), 'error' );
		}

		return $result;
	}

	/**
	 * Find a previously imported subscription by its source stamps.
	 *
	 * @param string $source    Source identifier.
	 * @param string $source_id Record id within the source.
	 * @return int Subscription id, or 0 when none exists.
	 */
	private function find_existing( $source, $source_id ) {
		$ids = wc_get_orders(
			array(
				'type'       => 'shop_subscription',
				// Without status "any", wc_get_orders filters by ORDER statuses,
				// which exclude subscription statuses like wc-active, and the
				// lookup silently misses every match.
				'status'     => 'any',
				'limit'      => 1,
				'return'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Idempotency lookup on an indexed pair, bounded to one row.
					array(
						'key'   => self::META_SOURCE,
						'value' => $source,
					),
					array(
						'key'   => self::META_SOURCE_ID,
						'value' => $source_id,
					),
				),
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Resolve the customer to a user id.
	 *
	 * @param array $record Normalized record.
	 * @param array $result Row result, errors appended by reference.
	 * @return int User id, 0 on failure.
	 */
	private function resolve_customer( $record, &$result ) {
		if ( $record['customer_id'] ) {
			$user = get_user_by( 'id', $record['customer_id'] );
			if ( $user ) {
				return $user->ID;
			}
			/* translators: %d: user id from the record. */
			$result['errors'][] = sprintf( __( 'No user with id %d exists on this site.', 'subscriptions-migration-suite-for-woocommerce' ), $record['customer_id'] );
			return 0;
		}

		$user = get_user_by( 'email', $record['customer_email'] );
		if ( $user ) {
			return $user->ID;
		}

		/* translators: %s: customer email from the record. */
		$result['errors'][] = sprintf( __( 'No user with email %s exists on this site.', 'subscriptions-migration-suite-for-woocommerce' ), $record['customer_email'] );
		return 0;
	}

	/**
	 * Resolve line items to products.
	 *
	 * @param array $record Normalized record.
	 * @param array $result Row result, errors appended by reference.
	 * @return array Items with product objects attached.
	 */
	private function resolve_items( $record, &$result ) {
		$resolved = array();

		foreach ( $record['items'] as $index => $item ) {
			$product_id = $item['product_id'];

			if ( ! $product_id && '' !== $item['sku'] ) {
				$product_id = wc_get_product_id_by_sku( $item['sku'] );
			}

			$product = $product_id ? wc_get_product( $product_id ) : false;

			if ( ! $product ) {
				/* translators: 1: item position, 2: product id or SKU from the record. */
				$result['errors'][] = sprintf(
					__( 'Item %1$d: product "%2$s" was not found on this site.', 'subscriptions-migration-suite-for-woocommerce' ),
					$index + 1,
					$item['product_id'] ? $item['product_id'] : $item['sku']
				);
				continue;
			}

			$item['product'] = $product;
			$resolved[]      = $item;
		}

		return $resolved;
	}

	/**
	 * Check gateway availability up front so the fallback to manual renewal
	 * is a reported decision, never a silent one.
	 *
	 * @param array $record Normalized record.
	 * @param array $result Row result, warnings appended by reference.
	 */
	private function check_gateway( $record, &$result ) {
		$method = $record['payment']['method'];

		if ( '' === $method || 'manual' === $method || $record['requires_manual_renewal'] ) {
			return;
		}

		$gateways = WC()->payment_gateways()->get_available_payment_gateways();

		if ( ! isset( $gateways[ $method ] ) ) {
			/* translators: %s: gateway id from the record. */
			$result['warnings'][] = sprintf( __( 'Gateway "%s" is not active on this site; the subscription will be imported with manual renewals.', 'subscriptions-migration-suite-for-woocommerce' ), $method );
		}
	}

	/**
	 * Create the subscription through the WCS CRUD sequence.
	 *
	 * @param array $record      Normalized record.
	 * @param int   $customer_id Resolved user id.
	 * @param array $items       Resolved items.
	 * @param array $options     Run options.
	 * @param array $result      Row result, warnings appended by reference.
	 * @return WC_Subscription
	 * @throws Exception When any step fails; caller rolls back.
	 */
	private function create_subscription( $record, $customer_id, $items, $options, &$result ) {
		$start_date = isset( $record['dates']['start'] ) ? $record['dates']['start'] : gmdate( 'Y-m-d H:i:s' );

		$subscription = wcs_create_subscription(
			array(
				'status'           => 'pending',
				'customer_id'      => $customer_id,
				'start_date'       => $start_date,
				'billing_period'   => $record['billing_period'],
				'billing_interval' => $record['billing_interval'],
				'order_id'         => $record['parent_order_id'],
				'customer_note'    => $record['customer_note'],
				'created_via'      => 'wcsms',
				'currency'         => $record['currency'],
			)
		);

		if ( is_wp_error( $subscription ) ) {
			throw new Exception( esc_html( $subscription->get_error_message() ) );
		}

		if ( ! empty( $record['billing_address'] ) ) {
			$subscription->set_address( $record['billing_address'], 'billing' );
		}
		if ( ! empty( $record['shipping_address'] ) ) {
			$subscription->set_address( $record['shipping_address'], 'shipping' );
		}

		foreach ( $items as $item ) {
			$totals = array();
			if ( null !== $item['subtotal'] ) {
				$totals['subtotal'] = $item['subtotal'];
			}
			if ( null !== $item['total'] ) {
				$totals['total'] = $item['total'];
			}

			$subscription->add_product( $item['product'], $item['quantity'], array( 'totals' => $totals ) );
		}

		foreach ( $record['totals'] as $key => $value ) {
			$setter = 'set_' . $key;
			if ( is_callable( array( $subscription, $setter ) ) ) {
				$subscription->{$setter}( $value );
			}
		}

		$this->apply_payment_method( $subscription, $record, $result );

		// Stamps first, so a crash after save still leaves the row findable.
		$subscription->update_meta_data( self::META_SOURCE, $record['source'] );
		$subscription->update_meta_data( self::META_SOURCE_ID, $record['source_id'] );
		if ( '' !== $options['run_id'] ) {
			$subscription->update_meta_data( self::META_RUN_ID, $options['run_id'] );
		}

		$subscription->save();

		$dates = $record['dates'];
		unset( $dates['start'] );
		if ( ! empty( $dates ) ) {
			$subscription->update_dates( $dates, 'gmt' );
		}

		if ( 'pending' !== $record['status'] ) {
			$this->apply_status( $subscription, $record['status'] );

			// Status transitions recalculate some dates (pending-cancel sets
			// the end date to now when no next payment exists, cancelled
			// stamps the cancellation time). Re-apply the record's dates so
			// the imported state wins, which also reschedules the matching
			// Action Scheduler jobs (end of prepaid term, expiration).
			$post_status_dates = array_intersect_key( $record['dates'], array_flip( array( 'cancelled', 'end' ) ) );
			if ( ! empty( $post_status_dates ) ) {
				$subscription->update_dates( $post_status_dates, 'gmt' );
			}
		}

		foreach ( $record['order_notes'] as $note ) {
			$subscription->add_order_note( $note );
		}

		$subscription->save();

		return $subscription;
	}

	/**
	 * Apply the payment method and its meta, or fall back to manual renewal
	 * with the reason already recorded as a row warning.
	 *
	 * @param WC_Subscription $subscription Subscription being built.
	 * @param array           $record       Normalized record.
	 * @param array           $result       Row result, warnings appended by reference.
	 */
	private function apply_payment_method( $subscription, $record, &$result ) {
		$method = $record['payment']['method'];

		if ( '' === $method || 'manual' === $method || $record['requires_manual_renewal'] ) {
			$subscription->set_requires_manual_renewal( true );
			return;
		}

		$gateways = WC()->payment_gateways()->get_available_payment_gateways();

		if ( ! isset( $gateways[ $method ] ) ) {
			$subscription->set_requires_manual_renewal( true );
			return;
		}

		$payment_meta = array();
		foreach ( array( 'post_meta', 'user_meta' ) as $table ) {
			foreach ( $record['payment'][ $table ] as $key => $value ) {
				$payment_meta[ $table ][ $key ] = array(
					'value' => $value,
					'label' => $key,
				);
			}
		}

		try {
			$subscription->set_payment_method( $gateways[ $method ], $payment_meta );

			if ( '' !== $record['payment']['method_title'] ) {
				$subscription->set_payment_method_title( $record['payment']['method_title'] );
			}
		} catch ( Exception $e ) {
			// Validation hooks (woocommerce_subscription_validate_payment_meta)
			// rejected the meta. Import as manual and say why.
			$subscription->set_requires_manual_renewal( true );
			/* translators: 1: gateway id, 2: validation error message. */
			$result['warnings'][] = sprintf( __( 'Payment meta for "%1$s" was rejected (%2$s); imported with manual renewals.', 'subscriptions-migration-suite-for-woocommerce' ), $method, $e->getMessage() );
		}
	}

	/**
	 * Move the subscription to its target status. The transition gate is
	 * lifted only for this one call: gateway feature flags describe what a
	 * merchant may do in wp-admin, not what an import restoring known state
	 * may do.
	 *
	 * @param WC_Subscription $subscription Subscription being built.
	 * @param string          $status       Target status without prefix.
	 * @throws Exception Propagated from update_status on invalid transitions.
	 */
	private function apply_status( $subscription, $status ) {
		$allow = static function () {
			return true;
		};

		add_filter( 'woocommerce_can_subscription_be_updated_to_' . $status, $allow, 100 );

		try {
			$subscription->update_status( $status );
		} finally {
			remove_filter( 'woocommerce_can_subscription_be_updated_to_' . $status, $allow, 100 );
		}
	}
}
