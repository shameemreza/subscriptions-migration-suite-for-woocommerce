<?php
/**
 * Payment continuity classification.
 *
 * Sorts every source subscription into a bucket that answers the one
 * question that decides a migration: will this subscription keep charging
 * automatically after the move to WooCommerce Subscriptions?
 *
 * Buckets:
 * - carries_over:  token lives in a store WooCommerce Subscriptions gateways
 *                  already read (WC Stripe customer/source keys).
 * - conditional:   token lives in the WC payment tokens vault and survives
 *                  only if the same gateway stays active after migration.
 * - reauth_needed: legacy profiles or gateway-specific tokens with no WCS
 *                  consumer; imported as manual renewal until the customer
 *                  re-authorizes.
 * - blocked:       billing is hosted at the gateway (offsite subscriptions);
 *                  must be resolved at the gateway before migration.
 * - manual:        no automatic payments in the source either.
 * - unknown:       gateway not recognized; needs a human look.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classifies source subscriptions by payment continuity.
 */
class WCSMS_Continuity {

	/**
	 * Bucket labels for display.
	 *
	 * @return array<string, string>
	 */
	public static function labels() {
		return array(
			'carries_over'  => __( 'Carries over', 'subscriptions-migration-suite-for-woocommerce' ),
			'conditional'   => __( 'Conditional', 'subscriptions-migration-suite-for-woocommerce' ),
			'reauth_needed' => __( 'Re-authorization needed', 'subscriptions-migration-suite-for-woocommerce' ),
			'blocked'       => __( 'Blocked', 'subscriptions-migration-suite-for-woocommerce' ),
			'manual'        => __( 'Manual renewal', 'subscriptions-migration-suite-for-woocommerce' ),
			'unknown'       => __( 'Needs review', 'subscriptions-migration-suite-for-woocommerce' ),
		);
	}

	/**
	 * Classify one gateway row for a source.
	 *
	 * @param string $source_id Source identifier from WCSMS_Source_Definitions.
	 * @param string $gateway   Gateway id recorded on the subscription, may be empty.
	 * @param string $flag      Source-specific flag value (manual marker, auto-renew flag).
	 * @param int    $mode      Source-specific mode (Sublium gateway_mode), 0 when unused.
	 * @return string Bucket key.
	 */
	public static function classify( $source_id, $gateway, $flag = '', $mode = 0 ) {
		$gateway = strtolower( (string) $gateway );

		switch ( $source_id ) {
			case 'wpswings':
				return self::classify_wpswings( $gateway, $flag );
			case 'flexible_subscriptions':
				return self::classify_flexible( $gateway, $flag );
			case 'yith':
				return self::classify_yith( $gateway );
			case 'wpsubscription':
				return self::classify_wpsubscription( $gateway, $flag );
			case 'sublium':
				return self::classify_sublium( $gateway, $mode );
		}

		return 'unknown';
	}

	/**
	 * WP Swings rides the official WC Stripe token store for Stripe, so
	 * those carry over. Its PayPal, Payfast, Cybersource, and Amazon Pay
	 * tokens are plugin-specific and need re-authorization.
	 *
	 * @param string $gateway Gateway id.
	 * @param string $flag    wps_wsp_payment_type value.
	 * @return string
	 */
	private static function classify_wpswings( $gateway, $flag ) {
		if ( 'wps_wsp_manual_method' === $flag || '' === $gateway ) {
			return 'manual';
		}
		if ( 0 === strpos( $gateway, 'stripe' ) ) {
			return 'carries_over';
		}
		if ( false !== strpos( $gateway, 'paypal' ) || false !== strpos( $gateway, 'ppec' ) ) {
			return 'reauth_needed';
		}
		if ( in_array( $gateway, array( 'payfast', 'woocybs', 'amazon_payments_advanced' ), true ) ) {
			return 'reauth_needed';
		}
		return 'unknown';
	}

	/**
	 * Flexible Subscriptions stores no tokens itself; continuity depends on
	 * each gateway's own storage, so auto-renewing subscriptions are
	 * conditional on the same gateway staying active.
	 *
	 * @param string $gateway Gateway id.
	 * @param string $flag    _requires_manual_renewal value.
	 * @return string
	 */
	private static function classify_flexible( $gateway, $flag ) {
		if ( 'true' === $flag || '' === $gateway ) {
			return 'manual';
		}
		return 'conditional';
	}

	/**
	 * YITH free stores no card tokens. Its PayPal Standard profiles are
	 * IPN-driven and not portable; PPCP vault tokens live in the WC vault.
	 *
	 * @param string $gateway Gateway id.
	 * @return string
	 */
	private static function classify_yith( $gateway ) {
		if ( '' === $gateway ) {
			return 'manual';
		}
		if ( 'paypal' === $gateway ) {
			return 'reauth_needed';
		}
		if ( 0 === strpos( $gateway, 'ppcp' ) ) {
			return 'conditional';
		}
		return 'unknown';
	}

	/**
	 * WPSubscription charges Stripe off-session on WC Stripe's own keys, so
	 * Stripe carries over. Its PayPal integration uses plugin-owned tables.
	 *
	 * @param string $gateway Gateway id of the parent order.
	 * @param string $flag    _subscrpt_auto_renew value.
	 * @return string
	 */
	private static function classify_wpsubscription( $gateway, $flag ) {
		$auto_renew = ! in_array( strtolower( (string) $flag ), array( '', 'no', 'off', '0', 'false' ), true );

		if ( ! $auto_renew || '' === $gateway ) {
			return 'manual';
		}
		if ( 0 === strpos( $gateway, 'stripe' ) ) {
			return 'carries_over';
		}
		if ( false !== strpos( $gateway, 'paypal' ) ) {
			return 'reauth_needed';
		}
		return 'unknown';
	}

	/**
	 * Sublium: gateway_mode 2 means the gateway hosts the billing (offsite
	 * PayPal subscriptions), which cannot be re-homed and blocks migration
	 * until resolved. Mode 1 Stripe uses WC payment sources and carries
	 * over; mode 1 PayPal vault and Square tokens are plugin-specific.
	 *
	 * @param string $gateway Gateway id.
	 * @param int    $mode    gateway_mode column value.
	 * @return string
	 */
	private static function classify_sublium( $gateway, $mode ) {
		if ( 2 === $mode ) {
			return 'blocked';
		}
		if ( '' === $gateway ) {
			return 'manual';
		}
		if ( false !== strpos( $gateway, 'stripe' ) ) {
			return 'carries_over';
		}
		if ( false !== strpos( $gateway, 'paypal' ) ) {
			return 'reauth_needed';
		}
		if ( false !== strpos( $gateway, 'square' ) ) {
			return 'reauth_needed';
		}
		return 'unknown';
	}

	/**
	 * Aggregate classified rows into bucket totals.
	 *
	 * @param string $source_id Source identifier.
	 * @param array  $rows      Rows with gateway, flag, mode, and total keys.
	 * @return array<string, int> Bucket => count, empty buckets omitted.
	 */
	public static function summarize( $source_id, $rows ) {
		$buckets = array();

		foreach ( $rows as $row ) {
			$bucket = self::classify(
				$source_id,
				isset( $row['gateway'] ) ? $row['gateway'] : '',
				isset( $row['flag'] ) ? (string) $row['flag'] : '',
				isset( $row['mode'] ) ? (int) $row['mode'] : 0
			);

			if ( ! isset( $buckets[ $bucket ] ) ) {
				$buckets[ $bucket ] = 0;
			}
			$buckets[ $bucket ] += (int) $row['total'];
		}

		arsort( $buckets );

		return $buckets;
	}
}
