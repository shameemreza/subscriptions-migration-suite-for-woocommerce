<?php
/**
 * Cutover guard against double billing.
 *
 * Source plugins keep their own renewal engines, and WooCommerce
 * Subscriptions schedules its own actions the moment dates are set. If both
 * run during a migration window, customers get charged twice. The guard
 * works in two halves:
 *
 * 1. During a live source migration, every imported subscription is held:
 *    its WCS scheduled actions are removed and it is stamped _wcsms_held.
 * 2. Cutover removes the source plugin's scheduled jobs, releases every
 *    held subscription by scheduling its WCS actions from its own dates,
 *    and reports what is live so the merchant can confirm exactly one
 *    billing engine remains.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Holds, releases, and verifies scheduling around cutover.
 */
class WCSMS_Cutover {

	const META_HELD = '_wcsms_held';
	const AS_GROUP  = 'wc_subscription_scheduled_event';

	/**
	 * The WCS scheduled-action hooks keyed by subscription id args.
	 *
	 * @var string[]
	 */
	const WCS_HOOKS = array(
		'woocommerce_scheduled_subscription_trial_end',
		'woocommerce_scheduled_subscription_payment',
		'woocommerce_scheduled_subscription_expiration',
		'woocommerce_scheduled_subscription_end_of_prepaid_term',
	);

	/**
	 * Hold one subscription: remove its WCS scheduled actions and stamp it.
	 *
	 * @param WC_Subscription $subscription Freshly imported subscription.
	 */
	public static function hold( $subscription ) {
		$args = array( 'subscription_id' => $subscription->get_id() );

		foreach ( self::WCS_HOOKS as $hook ) {
			as_unschedule_all_actions( $hook, $args, self::AS_GROUP );
		}

		$subscription->update_meta_data( self::META_HELD, 'yes' );
		$subscription->save();
	}

	/**
	 * Held subscription ids for a source.
	 *
	 * @param string $source_id Source adapter id.
	 * @return int[]
	 */
	public static function held_ids( $source_id ) {
		return array_map(
			static function ( $order_id ) {
				// With return "ids" the values are numeric; tolerate a full
				// object in case the query args are ever changed.
				return is_numeric( $order_id ) ? (int) $order_id : $order_id->get_id();
			},
			wc_get_orders(
				array(
					'type'       => 'shop_subscription',
					'status'     => 'any',
					'limit'      => -1,
					'return'     => 'ids',
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded cutover lookup on stamped rows.
						array(
							'key'   => WCSMS_Importer::META_SOURCE,
							'value' => $source_id,
						),
						array(
							'key'   => self::META_HELD,
							'value' => 'yes',
						),
					),
				)
			)
		);
	}

	/**
	 * Pending source-plugin scheduled actions, per hook.
	 *
	 * @param WCSMS_Source_Adapter $adapter Source adapter.
	 * @return array<string, int> hook => pending count.
	 */
	public static function pending_source_actions( $adapter ) {
		$pending = array();

		foreach ( $adapter->scheduler_hooks() as $hook ) {
			$actions = as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'status'   => ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 1000,
				),
				'ids'
			);

			if ( ! empty( $actions ) ) {
				$pending[ $hook ] = count( $actions );
			}
		}

		return $pending;
	}

	/**
	 * Run the cutover for a source.
	 *
	 * @param WCSMS_Source_Adapter $adapter Source adapter.
	 * @param bool                 $dry_run Report without changing anything.
	 * @return array{
	 *     source_actions: array<string, int>,
	 *     held: int,
	 *     released: int,
	 *     scheduled: int
	 * }
	 */
	public static function run( $adapter, $dry_run = true ) {
		$report = array(
			'source_actions' => self::pending_source_actions( $adapter ),
			'held'           => 0,
			'released'       => 0,
			'scheduled'      => 0,
		);

		$held_ids       = self::held_ids( $adapter->id() );
		$report['held'] = count( $held_ids );

		if ( $dry_run ) {
			return $report;
		}

		foreach ( array_keys( $report['source_actions'] ) as $hook ) {
			as_unschedule_all_actions( $hook );
		}

		foreach ( $held_ids as $subscription_id ) {
			$subscription = wcs_get_subscription( $subscription_id );

			if ( ! $subscription ) {
				continue;
			}

			$report['scheduled'] += self::schedule_wcs_actions( $subscription );

			$subscription->delete_meta_data( self::META_HELD );
			$subscription->save();
			$report['released']++;
		}

		WCSMS_Logger::log(
			sprintf(
				'Cutover for %s: removed %d source hooks, released %d subscriptions, scheduled %d actions.',
				$adapter->id(),
				count( $report['source_actions'] ),
				$report['released'],
				$report['scheduled']
			)
		);

		return $report;
	}

	/**
	 * Schedule the WCS actions a subscription's dates call for, mirroring
	 * the WCS scheduler's date-to-hook map.
	 *
	 * @param WC_Subscription $subscription Subscription to release.
	 * @return int Actions scheduled.
	 */
	public static function schedule_wcs_actions( $subscription ) {
		$args      = array( 'subscription_id' => $subscription->get_id() );
		$scheduled = 0;
		$now       = time();

		$map = array(
			'trial_end'    => 'woocommerce_scheduled_subscription_trial_end',
			'next_payment' => 'woocommerce_scheduled_subscription_payment',
		);

		// The end date means expiration on a live subscription, and end of
		// the prepaid term on one that is cancelled or pending cancellation.
		$map['end'] = $subscription->has_status( array( 'cancelled', 'pending-cancel' ) )
			? 'woocommerce_scheduled_subscription_end_of_prepaid_term'
			: 'woocommerce_scheduled_subscription_expiration';

		foreach ( $map as $date_type => $hook ) {
			$timestamp = $subscription->get_time( $date_type );

			if ( $timestamp <= $now ) {
				continue;
			}

			if ( 'next_payment' === $date_type && ! $subscription->has_status( 'active' ) ) {
				continue;
			}

			if ( false === as_next_scheduled_action( $hook, $args, self::AS_GROUP ) ) {
				as_schedule_single_action( $timestamp, $hook, $args, self::AS_GROUP );
				$scheduled++;
			}
		}

		return $scheduled;
	}
}
