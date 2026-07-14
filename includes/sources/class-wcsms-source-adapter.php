<?php
/**
 * Source adapter base.
 *
 * An adapter reads a source plugin's subscription data straight from the
 * database and produces normalized records for the importer. Adapters never
 * call source plugin code: the source is usually inactive during migration
 * (Flexible Subscriptions refuses to run next to WooCommerce Subscriptions),
 * and raw reads keep that a feature, not a problem.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contract for migration source adapters.
 */
abstract class WCSMS_Source_Adapter {

	/**
	 * Adapter id, matching the scanner's source id.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Human label.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Total migratable records.
	 *
	 * @return int
	 */
	abstract public function count();

	/**
	 * Fetch a page of records in stable order.
	 *
	 * @param int $offset Records to skip.
	 * @param int $limit  Page size.
	 * @return array<int, array{source_ref: string, record: array|null, error: string|null}>
	 *         One entry per source row. When a row cannot be mapped, record
	 *         is null and error says why; the runner reports it as a failed
	 *         row instead of dropping it silently.
	 */
	abstract public function fetch( $offset, $limit );

	/**
	 * Product conversion maps: which products the source marked as
	 * subscription products, and the WCS product meta each should get.
	 *
	 * @return array<int, array{product_id: int, meta: array<string, string>, error: string|null}>
	 *         Entries with an error are reported and skipped, never
	 *         converted on a guess.
	 */
	public function product_maps() {
		return array();
	}

	/**
	 * Action Scheduler hooks the source plugin uses for its own renewal
	 * engine. Cutover unschedules them so only WooCommerce Subscriptions
	 * bills after migration.
	 *
	 * @return string[]
	 */
	public function scheduler_hooks() {
		return array();
	}
}

/**
 * Registry of available adapters.
 */
class WCSMS_Sources {

	/**
	 * Adapter id => class name.
	 *
	 * @return array<string, string>
	 */
	public static function adapters() {
		return array(
			'flexible_subscriptions' => 'WCSMS_Source_Flexible_Subscriptions',
			'wpswings'               => 'WCSMS_Source_WPSwings',
			'yith'                   => 'WCSMS_Source_YITH',
		);
	}

	/**
	 * Instantiate an adapter.
	 *
	 * @param string $id Adapter id.
	 * @return WCSMS_Source_Adapter|null
	 */
	public static function get( $id ) {
		$adapters = self::adapters();
		return isset( $adapters[ $id ] ) ? new $adapters[ $id ]() : null;
	}
}
