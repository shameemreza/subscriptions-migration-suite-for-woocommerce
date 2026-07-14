<?php
/**
 * Streaming subscription exporter.
 *
 * Writes WooCommerce Subscriptions as JSON Lines in the same normalized
 * record shape the importer consumes, so an export is importable as-is:
 * on another site for a move, or re-imported safely thanks to the source
 * stamps. Batched reads keep memory flat regardless of store size.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exports subscriptions to a JSONL stream.
 */
class WCSMS_Exporter {

	const SOURCE = 'wcs-export';

	/**
	 * Export subscriptions matching the filters to an open stream.
	 *
	 * @param resource $handle Writable stream.
	 * @param array    $args {
	 *     Filters and options.
	 *
	 *     @type string[] $statuses       Statuses without the wc- prefix. Empty = all.
	 *     @type int      $customer_id    Limit to one customer.
	 *     @type string   $gateway        Limit to one payment method id.
	 *     @type string   $date_after     Y-m-d, filters on created date.
	 *     @type string   $date_before    Y-m-d, filters on created date.
	 *     @type bool     $include_tokens Include gateway payment meta. Default false.
	 *     @type int      $batch_size     Rows per query. Default from settings.
	 * }
	 * @param callable|null $progress Called with the running total after each batch.
	 * @return array{exported: int}
	 */
	public function export( $handle, $args = array(), $progress = null ) {
		$args = wp_parse_args(
			$args,
			array(
				'statuses'       => array(),
				'customer_id'    => 0,
				'gateway'        => '',
				'date_after'     => '',
				'date_before'    => '',
				'include_tokens' => false,
				'batch_size'     => max( 1, (int) get_option( 'wcsms_batch_size', 20 ) ),
			)
		);

		$exported = 0;
		$page     = 1;

		do {
			$subscriptions = wc_get_orders( $this->build_query( $args, $page ) );

			foreach ( $subscriptions as $subscription ) {
				if ( ! $subscription instanceof WC_Subscription ) {
					continue;
				}

				fwrite( $handle, wp_json_encode( $this->to_record( $subscription, $args['include_tokens'] ) ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming export output.
				$exported++;
			}

			if ( null !== $progress ) {
				call_user_func( $progress, $exported );
			}

			$page++;
		} while ( count( $subscriptions ) === $args['batch_size'] );

		WCSMS_Logger::log( sprintf( 'Exported %d subscriptions.', $exported ) );

		return array( 'exported' => $exported );
	}

	/**
	 * Build the batched wc_get_orders query.
	 *
	 * @param array $args Export args.
	 * @param int   $page Page number.
	 * @return array
	 */
	private function build_query( $args, $page ) {
		$query = array(
			'type'     => 'shop_subscription',
			'status'   => empty( $args['statuses'] )
				? 'any'
				: array_map(
					static function ( $status ) {
						return 'wc-' . str_replace( 'wc-', '', $status );
					},
					$args['statuses']
				),
			'limit'    => $args['batch_size'],
			'paged'    => $page,
			'orderby'  => 'ID',
			'order'    => 'ASC',
		);

		if ( $args['customer_id'] ) {
			$query['customer_id'] = (int) $args['customer_id'];
		}

		if ( '' !== $args['gateway'] ) {
			$query['payment_method'] = $args['gateway'];
		}

		if ( '' !== $args['date_after'] && '' !== $args['date_before'] ) {
			$query['date_created'] = $args['date_after'] . '...' . $args['date_before'];
		} elseif ( '' !== $args['date_after'] ) {
			$query['date_created'] = '>=' . $args['date_after'];
		} elseif ( '' !== $args['date_before'] ) {
			$query['date_created'] = '<=' . $args['date_before'];
		}

		return $query;
	}

	/**
	 * Convert one subscription to the normalized record shape.
	 *
	 * @param WC_Subscription $subscription   Subscription to export.
	 * @param bool            $include_tokens Include gateway payment meta.
	 * @return array
	 */
	public function to_record( $subscription, $include_tokens ) {
		$record = array(
			'source'                  => self::SOURCE,
			'source_id'               => (string) $subscription->get_id(),
			'customer_id'             => $subscription->get_customer_id(),
			'customer_email'          => $subscription->get_billing_email(),
			'status'                  => $subscription->get_status(),
			'currency'                => $subscription->get_currency(),
			'billing_period'          => $subscription->get_billing_period(),
			'billing_interval'        => (int) $subscription->get_billing_interval(),
			'dates'                   => $this->collect_dates( $subscription ),
			'requires_manual_renewal' => $subscription->is_manual(),
			'payment'                 => $this->collect_payment( $subscription, $include_tokens ),
			'billing_address'         => $subscription->get_address( 'billing' ),
			'shipping_address'        => $subscription->get_address( 'shipping' ),
			'items'                   => $this->collect_items( $subscription ),
			'totals'                  => array(
				'total'          => (float) $subscription->get_total(),
				'shipping_total' => (float) $subscription->get_shipping_total(),
				'shipping_tax'   => (float) $subscription->get_shipping_tax(),
				'discount_total' => (float) $subscription->get_discount_total(),
				'discount_tax'   => (float) $subscription->get_discount_tax(),
				'cart_tax'       => (float) $subscription->get_cart_tax(),
			),
			'customer_note'           => $subscription->get_customer_note(),
			'tax_lines'               => $this->collect_tax_lines( $subscription ),
		);

		/**
		 * Filters an exported subscription record before it is written.
		 *
		 * @param array           $record       The normalized record.
		 * @param WC_Subscription $subscription The subscription being exported.
		 */
		return apply_filters( 'wcsms_export_record', $record, $subscription );
	}

	/**
	 * Non-empty schedule dates in UTC.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @return array<string, string>
	 */
	private function collect_dates( $subscription ) {
		$dates = array();

		foreach ( array( 'start', 'trial_end', 'next_payment', 'cancelled', 'end' ) as $date_type ) {
			$value = $subscription->get_date( $date_type, 'gmt' );
			if ( $value ) {
				$dates[ $date_type ] = $value;
			}
		}

		return $dates;
	}

	/**
	 * Payment method and, when requested, the gateway-declared payment meta.
	 *
	 * Uses the woocommerce_subscription_payment_meta filter, the same
	 * mechanism gateways use to declare their recurring token fields, so
	 * every WCS-compatible gateway exports the right keys without this
	 * plugin hardcoding them.
	 *
	 * @param WC_Subscription $subscription   Subscription.
	 * @param bool            $include_tokens Include the meta values.
	 * @return array
	 */
	private function collect_payment( $subscription, $include_tokens ) {
		$payment = array(
			'method'       => $subscription->get_payment_method(),
			'method_title' => $subscription->get_payment_method_title(),
			'post_meta'    => array(),
			'user_meta'    => array(),
		);

		if ( ! $include_tokens || '' === $payment['method'] ) {
			return $payment;
		}

		/** This filter is documented in WooCommerce Subscriptions. */
		$declared = apply_filters( 'woocommerce_subscription_payment_meta', array(), $subscription ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Applying WooCommerce Subscriptions' own filter, not defining a new hook.

		if ( empty( $declared[ $payment['method'] ] ) ) {
			return $payment;
		}

		foreach ( $declared[ $payment['method'] ] as $table => $meta ) {
			$target = in_array( $table, array( 'post_meta', 'postmeta' ), true ) ? 'post_meta' : ( in_array( $table, array( 'user_meta', 'usermeta' ), true ) ? 'user_meta' : '' );

			if ( '' === $target || ! is_array( $meta ) ) {
				continue;
			}

			foreach ( $meta as $key => $data ) {
				if ( isset( $data['value'] ) && '' !== (string) $data['value'] ) {
					$payment[ $target ][ $key ] = (string) $data['value'];
				}
			}
		}

		return $payment;
	}

	/**
	 * Tax lines keyed by rate code, not rate id: rate ids are rows in this
	 * site's tax tables and mean nothing on a target site, while codes
	 * (US-TX-STANDARD-1) can be resolved there.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @return array<int, array>
	 */
	private function collect_tax_lines( $subscription ) {
		$lines = array();

		foreach ( $subscription->get_taxes() as $tax ) {
			$lines[] = array(
				'rate_code'          => $tax->get_rate_code(),
				'label'              => $tax->get_label(),
				'compound'           => $tax->get_compound(),
				'tax_total'          => (float) $tax->get_tax_total(),
				'shipping_tax_total' => (float) $tax->get_shipping_tax_total(),
			);
		}

		return $lines;
	}

	/**
	 * Rate id => rate code map for this subscription's tax lines, used to
	 * key item taxes portably.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @return array<int, string>
	 */
	private function rate_codes( $subscription ) {
		$codes = array();

		foreach ( $subscription->get_taxes() as $tax ) {
			$codes[ (int) $tax->get_rate_id() ] = $tax->get_rate_code();
		}

		return $codes;
	}

	/**
	 * Line items with product references, totals, and taxes keyed by rate code.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @return array<int, array>
	 */
	private function collect_items( $subscription ) {
		$items      = array();
		$rate_codes = $this->rate_codes( $subscription );

		foreach ( $subscription->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			$product    = $item->get_product();

			$taxes     = array();
			$item_data = $item->get_taxes();
			foreach ( array( 'total', 'subtotal' ) as $tax_key ) {
				if ( empty( $item_data[ $tax_key ] ) ) {
					continue;
				}
				foreach ( $item_data[ $tax_key ] as $rate_id => $amount ) {
					if ( '' === (string) $amount || ! isset( $rate_codes[ (int) $rate_id ] ) ) {
						continue;
					}
					$taxes[ $rate_codes[ (int) $rate_id ] ][ $tax_key ] = (float) $amount;
				}
			}

			$items[] = array(
				'product_id'   => $product_id,
				'sku'          => $product ? $product->get_sku() : '',
				'quantity'     => $item->get_quantity(),
				'subtotal'     => (float) $item->get_subtotal(),
				'total'        => (float) $item->get_total(),
				'subtotal_tax' => (float) $item->get_subtotal_tax(),
				'total_tax'    => (float) $item->get_total_tax(),
				'taxes'        => $taxes,
			);
		}

		return $items;
	}
}
