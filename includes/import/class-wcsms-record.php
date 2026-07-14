<?php
/**
 * Normalized import record.
 *
 * Every input surface (JSON, CSV, source adapters) produces this shape, and
 * the importer consumes only this shape. One transformation core, thin
 * inputs.
 *
 * Record shape (associative array):
 * - source           (string, required) Input surface or adapter id.
 * - source_id        (string, required) Stable id within the source; drives idempotency.
 * - customer_id      (int) WP user id. Either this or customer_email is required.
 * - customer_email   (string) Used to look up the user when customer_id is absent.
 * - status           (string, required) WCS status without the wc- prefix.
 * - currency         (string) ISO currency code. Defaults to the store currency.
 * - billing_period   (string, required) day, week, month, or year.
 * - billing_interval (int, required) 1 or greater.
 * - dates            (array) start, trial_end, next_payment, cancelled, end,
 *                    last_order_date_created as MySQL Y-m-d H:i:s UTC strings.
 * - requires_manual_renewal (bool) Default false.
 * - payment          (array) method, method_title, post_meta map, user_meta map.
 * - billing_address / shipping_address (array) Standard WC address keys.
 * - items            (array, required) Rows with product_id or sku, quantity,
 *                    subtotal, total, subtotal_tax, total_tax.
 * - totals           (array) total, shipping_total, shipping_tax,
 *                    discount_total, discount_tax, cart_tax.
 * - customer_note    (string).
 * - order_notes      (array of strings) Private notes added after creation.
 * - parent_order_id  (int) Existing order to attach as parent.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validates and normalizes raw record arrays.
 */
class WCSMS_Record {

	/**
	 * Statuses the importer accepts (without the wc- prefix).
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'pending', 'active', 'on-hold', 'cancelled', 'expired', 'pending-cancel', 'switched' );

	/**
	 * Valid billing periods.
	 *
	 * @var string[]
	 */
	const PERIODS = array( 'day', 'week', 'month', 'year' );

	/**
	 * Date keys accepted under dates.
	 *
	 * @var string[]
	 */
	const DATE_KEYS = array( 'start', 'trial_end', 'next_payment', 'cancelled', 'end', 'last_order_date_created' );

	/**
	 * Normalize a raw record. Returns the normalized array or a WP_Error
	 * naming every problem found, so one pass reports all row issues.
	 *
	 * @param mixed $raw Raw record, usually decoded JSON, so any type may arrive.
	 * @return array|WP_Error
	 */
	public static function normalize( $raw ) {
		$errors = new WP_Error();

		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'wcsms_record_invalid', __( 'Record is not an object.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$record = array(
			'source'                  => isset( $raw['source'] ) ? sanitize_key( $raw['source'] ) : '',
			'source_id'               => isset( $raw['source_id'] ) ? sanitize_text_field( (string) $raw['source_id'] ) : '',
			'customer_id'             => isset( $raw['customer_id'] ) ? absint( $raw['customer_id'] ) : 0,
			'customer_email'          => isset( $raw['customer_email'] ) ? sanitize_email( $raw['customer_email'] ) : '',
			'status'                  => isset( $raw['status'] ) ? str_replace( 'wc-', '', sanitize_key( $raw['status'] ) ) : '',
			'currency'                => isset( $raw['currency'] ) ? strtoupper( sanitize_text_field( $raw['currency'] ) ) : get_woocommerce_currency(),
			'billing_period'          => isset( $raw['billing_period'] ) ? sanitize_key( $raw['billing_period'] ) : '',
			'billing_interval'        => isset( $raw['billing_interval'] ) ? absint( $raw['billing_interval'] ) : 0,
			'dates'                   => array(),
			'requires_manual_renewal' => ! empty( $raw['requires_manual_renewal'] ),
			'payment'                 => self::normalize_payment( $raw ),
			'billing_address'         => self::normalize_address( $raw, 'billing_address' ),
			'shipping_address'        => self::normalize_address( $raw, 'shipping_address' ),
			'items'                   => array(),
			'totals'                  => self::normalize_totals( $raw ),
			'customer_note'           => isset( $raw['customer_note'] ) ? sanitize_textarea_field( $raw['customer_note'] ) : '',
			'order_notes'             => array(),
			'parent_order_id'         => isset( $raw['parent_order_id'] ) ? absint( $raw['parent_order_id'] ) : 0,
			'tax_lines'               => self::normalize_tax_lines( $raw ),
		);

		if ( '' === $record['source'] || '' === $record['source_id'] ) {
			$errors->add( 'missing_source', __( 'source and source_id are required for idempotent imports.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		if ( 0 === $record['customer_id'] && '' === $record['customer_email'] ) {
			$errors->add( 'missing_customer', __( 'customer_id or customer_email is required.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		if ( ! in_array( $record['status'], self::STATUSES, true ) ) {
			/* translators: %s: status value from the record. */
			$errors->add( 'bad_status', sprintf( __( 'Unknown subscription status "%s".', 'subscriptions-migration-suite-for-woocommerce' ), $record['status'] ) );
		}

		if ( ! in_array( $record['billing_period'], self::PERIODS, true ) ) {
			/* translators: %s: billing period value from the record. */
			$errors->add( 'bad_period', sprintf( __( 'Billing period must be day, week, month, or year; got "%s".', 'subscriptions-migration-suite-for-woocommerce' ), $record['billing_period'] ) );
		}

		if ( $record['billing_interval'] < 1 ) {
			$errors->add( 'bad_interval', __( 'Billing interval must be 1 or greater.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		if ( isset( $raw['dates'] ) && is_array( $raw['dates'] ) ) {
			foreach ( self::DATE_KEYS as $key ) {
				if ( empty( $raw['dates'][ $key ] ) ) {
					continue;
				}
				$value = self::normalize_date( $raw['dates'][ $key ] );
				if ( null === $value ) {
					/* translators: 1: date field name, 2: rejected value. */
					$errors->add( 'bad_date_' . $key, sprintf( __( 'Date "%1$s" is not a valid Y-m-d H:i:s UTC datetime: "%2$s".', 'subscriptions-migration-suite-for-woocommerce' ), $key, (string) $raw['dates'][ $key ] ) );
				} else {
					$record['dates'][ $key ] = $value;
				}
			}
		}

		if ( isset( $raw['items'] ) && is_array( $raw['items'] ) ) {
			foreach ( $raw['items'] as $index => $item ) {
				$normalized_item = self::normalize_item( $item );
				if ( null === $normalized_item ) {
					/* translators: %d: item position in the record. */
					$errors->add( 'bad_item_' . $index, sprintf( __( 'Item %d needs a product_id or sku and a quantity of 1 or more.', 'subscriptions-migration-suite-for-woocommerce' ), $index + 1 ) );
				} else {
					$record['items'][] = $normalized_item;
				}
			}
		}

		if ( empty( $record['items'] ) && ! $errors->has_errors() ) {
			$errors->add( 'missing_items', __( 'At least one line item is required.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		if ( isset( $raw['order_notes'] ) && is_array( $raw['order_notes'] ) ) {
			foreach ( $raw['order_notes'] as $note ) {
				$note = sanitize_textarea_field( (string) $note );
				if ( '' !== $note ) {
					$record['order_notes'][] = $note;
				}
			}
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return $record;
	}

	/**
	 * Normalize the payment block.
	 *
	 * @param array $raw Raw record.
	 * @return array
	 */
	private static function normalize_payment( $raw ) {
		$payment = array(
			'method'       => '',
			'method_title' => '',
			'post_meta'    => array(),
			'user_meta'    => array(),
		);

		if ( empty( $raw['payment'] ) || ! is_array( $raw['payment'] ) ) {
			return $payment;
		}

		$payment['method']       = isset( $raw['payment']['method'] ) ? sanitize_text_field( $raw['payment']['method'] ) : '';
		$payment['method_title'] = isset( $raw['payment']['method_title'] ) ? sanitize_text_field( $raw['payment']['method_title'] ) : '';

		foreach ( array( 'post_meta', 'user_meta' ) as $table ) {
			if ( empty( $raw['payment'][ $table ] ) || ! is_array( $raw['payment'][ $table ] ) ) {
				continue;
			}
			foreach ( $raw['payment'][ $table ] as $key => $value ) {
				$key = sanitize_text_field( (string) $key );
				if ( '' !== $key && is_scalar( $value ) ) {
					$payment[ $table ][ $key ] = sanitize_text_field( (string) $value );
				}
			}
		}

		return $payment;
	}

	/**
	 * Normalize an address block, keeping only known WC address keys.
	 *
	 * @param array  $raw Raw record.
	 * @param string $key billing_address or shipping_address.
	 * @return array
	 */
	private static function normalize_address( $raw, $key ) {
		$allowed = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' );
		$address = array();

		if ( empty( $raw[ $key ] ) || ! is_array( $raw[ $key ] ) ) {
			return $address;
		}

		foreach ( $allowed as $field ) {
			if ( isset( $raw[ $key ][ $field ] ) && is_scalar( $raw[ $key ][ $field ] ) ) {
				$address[ $field ] = sanitize_text_field( (string) $raw[ $key ][ $field ] );
			}
		}

		return $address;
	}

	/**
	 * Normalize the totals block to floats.
	 *
	 * @param array $raw Raw record.
	 * @return array
	 */
	private static function normalize_totals( $raw ) {
		$keys   = array( 'total', 'shipping_total', 'shipping_tax', 'discount_total', 'discount_tax', 'cart_tax' );
		$totals = array();

		if ( empty( $raw['totals'] ) || ! is_array( $raw['totals'] ) ) {
			return $totals;
		}

		foreach ( $keys as $key ) {
			if ( isset( $raw['totals'][ $key ] ) && is_numeric( $raw['totals'][ $key ] ) ) {
				$totals[ $key ] = (float) $raw['totals'][ $key ];
			}
		}

		return $totals;
	}

	/**
	 * Normalize one line item.
	 *
	 * @param mixed $item Raw item.
	 * @return array|null Null when the item is unusable.
	 */
	private static function normalize_item( $item ) {
		if ( ! is_array( $item ) ) {
			return null;
		}

		$normalized = array(
			'product_id'   => isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0,
			'sku'          => isset( $item['sku'] ) ? sanitize_text_field( $item['sku'] ) : '',
			'quantity'     => isset( $item['quantity'] ) ? max( 1, absint( $item['quantity'] ) ) : 1,
			'subtotal'     => isset( $item['subtotal'] ) && is_numeric( $item['subtotal'] ) ? (float) $item['subtotal'] : null,
			'total'        => isset( $item['total'] ) && is_numeric( $item['total'] ) ? (float) $item['total'] : null,
			'subtotal_tax' => isset( $item['subtotal_tax'] ) && is_numeric( $item['subtotal_tax'] ) ? (float) $item['subtotal_tax'] : 0.0,
			'total_tax'    => isset( $item['total_tax'] ) && is_numeric( $item['total_tax'] ) ? (float) $item['total_tax'] : 0.0,
			'taxes'        => array(),
		);

		if ( isset( $item['taxes'] ) && is_array( $item['taxes'] ) ) {
			foreach ( $item['taxes'] as $rate_code => $amounts ) {
				$rate_code = sanitize_text_field( (string) $rate_code );
				if ( '' === $rate_code || ! is_array( $amounts ) ) {
					continue;
				}
				foreach ( array( 'total', 'subtotal' ) as $tax_key ) {
					if ( isset( $amounts[ $tax_key ] ) && is_numeric( $amounts[ $tax_key ] ) ) {
						$normalized['taxes'][ $rate_code ][ $tax_key ] = (float) $amounts[ $tax_key ];
					}
				}
			}
		}

		if ( 0 === $normalized['product_id'] && '' === $normalized['sku'] ) {
			return null;
		}

		return $normalized;
	}

	/**
	 * Normalize the tax lines block.
	 *
	 * @param array $raw Raw record.
	 * @return array<int, array>
	 */
	private static function normalize_tax_lines( $raw ) {
		$lines = array();

		if ( empty( $raw['tax_lines'] ) || ! is_array( $raw['tax_lines'] ) ) {
			return $lines;
		}

		foreach ( $raw['tax_lines'] as $line ) {
			if ( ! is_array( $line ) || empty( $line['rate_code'] ) ) {
				continue;
			}

			$lines[] = array(
				'rate_code'          => sanitize_text_field( (string) $line['rate_code'] ),
				'label'              => isset( $line['label'] ) ? sanitize_text_field( (string) $line['label'] ) : '',
				'compound'           => ! empty( $line['compound'] ),
				'tax_total'          => isset( $line['tax_total'] ) && is_numeric( $line['tax_total'] ) ? (float) $line['tax_total'] : 0.0,
				'shipping_tax_total' => isset( $line['shipping_tax_total'] ) && is_numeric( $line['shipping_tax_total'] ) ? (float) $line['shipping_tax_total'] : 0.0,
			);
		}

		return $lines;
	}

	/**
	 * Validate a MySQL UTC datetime string.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function normalize_date( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value  = trim( $value );
		$parsed = DateTime::createFromFormat( 'Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );

		if ( false === $parsed || $parsed->format( 'Y-m-d H:i:s' ) !== $value ) {
			return null;
		}

		return $value;
	}
}
