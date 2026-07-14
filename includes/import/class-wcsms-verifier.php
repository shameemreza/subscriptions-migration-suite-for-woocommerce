<?php
/**
 * Post-migration verification.
 *
 * Reconciles what a migration produced against what the source holds and
 * what WooCommerce Subscriptions needs to bill correctly. The checks are
 * the ones that decide whether renewals actually happen:
 *
 * - Every source record is accounted for: migrated, or explainable.
 * - Active subscriptions carry a future next payment date.
 * - Released subscriptions have their scheduled actions in place; held
 *   ones are counted so a forgotten cutover is loud.
 * - Subscriptions expecting automatic renewals use a gateway that is
 *   actually active on this site.
 * - Pending-cancel subscriptions have the end date their prepaid term
 *   depends on.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verifies migrated subscriptions for a source.
 */
class WCSMS_Verifier {

	const MAX_ISSUES = 100;

	/**
	 * Run all checks for a source.
	 *
	 * @param WCSMS_Source_Adapter $adapter Source adapter.
	 * @return array{
	 *     source_total: int,
	 *     migrated: int,
	 *     held: int,
	 *     statuses: array<string, int>,
	 *     issues: array<int, array{subscription_id: int, issue: string}>
	 * }
	 */
	public static function verify( $adapter ) {
		$report = array(
			'source_total' => $adapter->count(),
			'migrated'     => 0,
			'held'         => 0,
			'statuses'     => array(),
			'issues'       => array(),
		);

		$ids = WCSMS_Rollback::find( $adapter->id() );

		$report['migrated'] = count( $ids );

		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		$now      = time();

		foreach ( $ids as $id ) {
			$subscription = wcs_get_subscription( $id );

			if ( ! $subscription ) {
				self::add_issue( $report, $id, __( 'Stamped id no longer loads as a subscription.', 'subscriptions-migration-suite-for-woocommerce' ) );
				continue;
			}

			$status = $subscription->get_status();

			if ( ! isset( $report['statuses'][ $status ] ) ) {
				$report['statuses'][ $status ] = 0;
			}
			$report['statuses'][ $status ]++;

			$held = 'yes' === $subscription->get_meta( WCSMS_Cutover::META_HELD );
			if ( $held ) {
				$report['held']++;
			}

			if ( $subscription->has_status( 'active' ) ) {
				$next = $subscription->get_time( 'next_payment' );

				if ( $next <= 0 && ! $subscription->get_time( 'end' ) ) {
					self::add_issue( $report, $id, __( 'Active with no next payment date and no end date; it will never renew or expire.', 'subscriptions-migration-suite-for-woocommerce' ) );
				} elseif ( $next > 0 && $next <= $now ) {
					self::add_issue( $report, $id, __( 'Next payment date is in the past.', 'subscriptions-migration-suite-for-woocommerce' ) );
				}

				if ( ! $held && $next > $now ) {
					$scheduled = as_next_scheduled_action(
						'woocommerce_scheduled_subscription_payment',
						array( 'subscription_id' => $id ),
						WCSMS_Cutover::AS_GROUP
					);
					if ( false === $scheduled ) {
						self::add_issue( $report, $id, __( 'Released and active but no renewal action is scheduled.', 'subscriptions-migration-suite-for-woocommerce' ) );
					}
				}
			}

			if ( $subscription->has_status( 'pending-cancel' ) ) {
				$end = $subscription->get_time( 'end' );

				if ( $end <= 0 ) {
					self::add_issue( $report, $id, __( 'Pending cancellation with no end date; the prepaid term cannot finish.', 'subscriptions-migration-suite-for-woocommerce' ) );
				} elseif ( ! $held && $end > $now ) {
					$scheduled = as_next_scheduled_action(
						'woocommerce_scheduled_subscription_end_of_prepaid_term',
						array( 'subscription_id' => $id ),
						WCSMS_Cutover::AS_GROUP
					);
					if ( false === $scheduled ) {
						self::add_issue( $report, $id, __( 'Released and pending cancellation but no end of prepaid term action is scheduled.', 'subscriptions-migration-suite-for-woocommerce' ) );
					}
				}
			}

			if ( ! $subscription->is_manual() && $subscription->has_status( array( 'active', 'on-hold', 'pending' ) ) ) {
				$method = $subscription->get_payment_method();
				if ( '' !== $method && ! isset( $gateways[ $method ] ) ) {
					self::add_issue(
						$report,
						$id,
						sprintf(
							/* translators: %s: gateway id. */
							__( 'Expects automatic renewals via "%s", which is not active on this site.', 'subscriptions-migration-suite-for-woocommerce' ),
							$method
						)
					);
				}
			}
		}

		WCSMS_Logger::log(
			sprintf(
				'Verify %s: %d source records, %d migrated, %d held, %d issues.',
				$adapter->id(),
				$report['source_total'],
				$report['migrated'],
				$report['held'],
				count( $report['issues'] )
			)
		);

		return $report;
	}

	/**
	 * Record an issue, bounded.
	 *
	 * @param array  $report          Report, modified in place.
	 * @param int    $subscription_id Subscription id.
	 * @param string $issue           Description.
	 */
	private static function add_issue( &$report, $subscription_id, $issue ) {
		if ( count( $report['issues'] ) < self::MAX_ISSUES ) {
			$report['issues'][] = array(
				'subscription_id' => $subscription_id,
				'issue'           => $issue,
			);
		}
	}
}
