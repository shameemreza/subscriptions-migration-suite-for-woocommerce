<?php
/**
 * Read-only scanner for migration sources.
 *
 * Counts subscription records per source and breaks them down by status.
 * All queries are SELECTs against the source plugins' own storage. Direct
 * queries are unavoidable here: the source schemas are foreign to
 * WooCommerce CRUD, and the source plugins are usually inactive.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scans the database for migratable subscription data.
 */
class WCSMS_Scanner {

	/**
	 * Run a full scan across all known sources.
	 *
	 * @return array{
	 *     environment: array,
	 *     sources: array<int, array>
	 * }
	 */
	public function scan() {
		$results = array();

		foreach ( WCSMS_Source_Definitions::all() as $source_id => $definition ) {
			$result = $this->scan_source( $source_id, $definition );

			if ( null !== $result ) {
				$results[] = $result;
			}
		}

		WCSMS_Logger::log( sprintf( 'Scan finished. Sources with data: %d.', count( $results ) ) );

		return array(
			'environment' => $this->environment(),
			'sources'     => $results,
		);
	}

	/**
	 * Environment facts that decide whether a migration can start.
	 *
	 * @return array
	 */
	private function environment() {
		$hpos_enabled = false;

		if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			$hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		return array(
			'wcs_active'   => WCSMS_Plugin::is_wcs_active(),
			'hpos_enabled' => $hpos_enabled,
		);
	}

	/**
	 * Scan one source. Returns null when no data exists for it.
	 *
	 * @param string $source_id  Source identifier.
	 * @param array  $definition Source definition.
	 * @return array|null
	 */
	private function scan_source( $source_id, $definition ) {
		switch ( $definition['storage'] ) {
			case 'order_type':
				$counts = $this->count_order_type( $definition );
				break;
			case 'post_type':
				$counts = $this->count_post_type( $definition );
				break;
			case 'table':
				$counts = $this->count_table( $definition );
				break;
			default:
				return null;
		}

		if ( null === $counts || 0 === $counts['total'] ) {
			return null;
		}

		return array(
			'id'            => $source_id,
			'label'         => $definition['label'],
			'plugin_active' => WCSMS_Source_Definitions::is_plugin_active_by_dir( $definition['plugin_dir'] ),
			'store'         => $counts['store'],
			'total'         => $counts['total'],
			'statuses'      => $counts['statuses'],
		);
	}

	/**
	 * Count records for a WooCommerce order-type source.
	 *
	 * HPOS-aware sources write to whichever store is authoritative, but a
	 * site may hold legacy rows in posts from before an HPOS switch, so both
	 * stores are checked and the larger count wins.
	 *
	 * @param array $definition Source definition.
	 * @return array|null
	 */
	private function count_order_type( $definition ) {
		$type = $definition['object_type'];

		$hpos  = $this->count_orders_table( $type, $definition );
		$posts = $this->count_posts_store( $type, $definition );

		if ( null === $hpos && null === $posts ) {
			return null;
		}

		if ( null !== $hpos && ( null === $posts || $hpos['total'] >= $posts['total'] ) ) {
			return $hpos;
		}

		return $posts;
	}

	/**
	 * Count order-type records in the HPOS orders table.
	 *
	 * @param string $type       Order type.
	 * @param array  $definition Source definition.
	 * @return array|null Null when the orders table does not exist.
	 */
	private function count_orders_table( $type, $definition ) {
		global $wpdb;

		$orders_table = $wpdb->prefix . 'wc_orders';
		$meta_table   = $wpdb->prefix . 'wc_orders_meta';

		if ( ! $this->table_exists( $orders_table ) ) {
			return null;
		}

		if ( 'meta' === $definition['status_source'] ) {
			$status_key = $definition['status_keys'][0];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only scan of a foreign schema; see file docblock.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT COALESCE(m.meta_value, 'unknown') AS status, COUNT(*) AS total
					 FROM {$wpdb->prefix}wc_orders o
					 LEFT JOIN {$wpdb->prefix}wc_orders_meta m ON m.order_id = o.id AND m.meta_key = %s
					 WHERE o.type = %s
					 GROUP BY m.meta_value",
					$status_key,
					$type
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only scan of a foreign schema; see file docblock.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT o.status AS status, COUNT(*) AS total
					 FROM {$wpdb->prefix}wc_orders o
					 WHERE o.type = %s
					 GROUP BY o.status",
					$type
				),
				ARRAY_A
			);
		}

		return $this->build_counts( $rows, 'hpos' );
	}

	/**
	 * Count order-type or post-type records in the posts store.
	 *
	 * @param string $type       Post type.
	 * @param array  $definition Source definition.
	 * @return array|null
	 */
	private function count_posts_store( $type, $definition ) {
		global $wpdb;

		if ( 'meta' === $definition['status_source'] ) {
			$keys        = $definition['status_keys'];
			$primary_key = $keys[0];
			$fallback    = isset( $keys[1] ) ? $keys[1] : $keys[0];

			// Two LEFT JOINs cover sources (YITH) that store status under
			// either a bare key or an underscore-prefixed key.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only scan of a foreign schema; see file docblock.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT COALESCE(pm1.meta_value, pm2.meta_value, 'unknown') AS status, COUNT(*) AS total
					 FROM {$wpdb->posts} p
					 LEFT JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = p.ID AND pm1.meta_key = %s
					 LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = %s
					 WHERE p.post_type = %s AND p.post_status <> 'trash'
					 GROUP BY COALESCE(pm1.meta_value, pm2.meta_value)",
					$primary_key,
					$fallback,
					$type
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only scan of a foreign schema; see file docblock.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.post_status AS status, COUNT(*) AS total
					 FROM {$wpdb->posts} p
					 WHERE p.post_type = %s AND p.post_status <> 'trash'
					 GROUP BY p.post_status",
					$type
				),
				ARRAY_A
			);
		}

		return $this->build_counts( $rows, 'posts' );
	}

	/**
	 * Count records for a post-type source.
	 *
	 * @param array $definition Source definition.
	 * @return array|null
	 */
	private function count_post_type( $definition ) {
		return $this->count_posts_store( $definition['object_type'], $definition );
	}

	/**
	 * Count records for a custom-table source.
	 *
	 * @param array $definition Source definition.
	 * @return array|null
	 */
	private function count_table( $definition ) {
		global $wpdb;

		$table = $wpdb->prefix . $definition['table'];

		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		$column = $definition['status_column'];

		// Table and column names come from the static definitions above, never from user input.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only scan of a foreign schema; identifiers are static.
		$rows = $wpdb->get_results( "SELECT `{$column}` AS status, COUNT(*) AS total FROM `{$table}` GROUP BY `{$column}`", ARRAY_A );

		if ( ! empty( $definition['status_map'] ) && is_array( $rows ) ) {
			foreach ( $rows as &$row ) {
				$code          = (int) $row['status'];
				$row['status'] = isset( $definition['status_map'][ $code ] ) ? $definition['status_map'][ $code ] : 'unknown-' . $code;
			}
			unset( $row );
		}

		return $this->build_counts( $rows, 'table' );
	}

	/**
	 * Turn GROUP BY rows into a normalized counts array.
	 *
	 * @param array|null $rows  Rows with status and total keys.
	 * @param string     $store Store label.
	 * @return array
	 */
	private function build_counts( $rows, $store ) {
		$statuses = array();
		$total    = 0;

		foreach ( (array) $rows as $row ) {
			$status              = (string) $row['status'];
			$count               = (int) $row['total'];
			$statuses[ $status ] = $count;
			$total              += $count;
		}

		arsort( $statuses );

		return array(
			'store'    => $store,
			'total'    => $total,
			'statuses' => $statuses,
		);
	}

	/**
	 * Whether a database table exists.
	 *
	 * @param string $table Full table name including prefix.
	 * @return bool
	 */
	private function table_exists( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
