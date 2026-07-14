<?php
/**
 * Rollback for imported subscriptions.
 *
 * Every subscription this plugin creates is stamped with its source and
 * run id, so a rollback can find and remove exactly what a migration or
 * import created, and nothing else. Source data is never touched: it was
 * only ever read.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds and deletes stamped subscriptions.
 */
class WCSMS_Rollback {

	/**
	 * Subscription ids stamped with a source or a run id.
	 *
	 * @param string $source Source id, empty to skip.
	 * @param string $run_id Run id, empty to skip.
	 * @return int[]
	 */
	public static function find( $source = '', $run_id = '' ) {
		$meta_query = array();

		if ( '' !== $source ) {
			$meta_query[] = array(
				'key'   => WCSMS_Importer::META_SOURCE,
				'value' => $source,
			);
		}

		if ( '' !== $run_id ) {
			$meta_query[] = array(
				'key'   => WCSMS_Importer::META_RUN_ID,
				'value' => $run_id,
			);
		}

		if ( empty( $meta_query ) ) {
			return array();
		}

		return array_map(
			static function ( $order_id ) {
				return is_numeric( $order_id ) ? (int) $order_id : $order_id->get_id();
			},
			wc_get_orders(
				array(
					'type'       => 'shop_subscription',
					'status'     => 'any',
					'limit'      => -1,
					'return'     => 'ids',
					'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded rollback lookup on stamped rows.
				)
			)
		);
	}

	/**
	 * Delete the found subscriptions, unscheduling their actions first so
	 * nothing fires for a subscription that no longer exists.
	 *
	 * @param string $source  Source id, empty to skip.
	 * @param string $run_id  Run id, empty to skip.
	 * @param bool   $dry_run Report without deleting.
	 * @return array{found: int, deleted: int}
	 */
	public static function run( $source = '', $run_id = '', $dry_run = true ) {
		$ids    = self::find( $source, $run_id );
		$report = array(
			'found'   => count( $ids ),
			'deleted' => 0,
		);

		if ( $dry_run ) {
			return $report;
		}

		foreach ( $ids as $id ) {
			$subscription = wcs_get_subscription( $id );

			if ( ! $subscription ) {
				continue;
			}

			$args = array( 'subscription_id' => $id );
			foreach ( WCSMS_Cutover::WCS_HOOKS as $hook ) {
				as_unschedule_all_actions( $hook, $args, WCSMS_Cutover::AS_GROUP );
			}

			// Unlink renewal history first: the orders are real customer
			// orders that stay, but their relation to a subscription that
			// is about to disappear must not.
			$store = WCS_Related_Order_Store::instance();
			foreach ( $store->get_related_order_ids( $subscription, 'renewal' ) as $renewal_order_id ) {
				$renewal_order = wc_get_order( $renewal_order_id );
				if ( $renewal_order instanceof WC_Order ) {
					$store->delete_relation( $renewal_order, $subscription, 'renewal' );
				}
			}

			$subscription->delete( true );
			$report['deleted']++;
		}

		WCSMS_Logger::log( sprintf( 'Rollback removed %d of %d subscriptions (source: %s, run: %s).', $report['deleted'], $report['found'], $source ? $source : '-', $run_id ? $run_id : '-' ) );

		return $report;
	}
}
