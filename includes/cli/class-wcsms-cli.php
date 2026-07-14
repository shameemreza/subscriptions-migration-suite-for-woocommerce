<?php
/**
 * WP-CLI commands.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * The wcsms command namespace.
 */
class WCSMS_CLI {

	/**
	 * Register commands with WP-CLI.
	 */
	public static function register() {
		WP_CLI::add_command( 'wcsms scan', array( __CLASS__, 'scan' ) );
	}

	/**
	 * Scan the site for migratable subscription data.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcsms scan
	 *     wp wcsms scan --format=json
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public static function scan( $args, $assoc_args ) {
		$scanner = new WCSMS_Scanner();
		$results = $scanner->scan();

		$env = $results['environment'];
		WP_CLI::log( sprintf( 'WooCommerce Subscriptions active: %s', $env['wcs_active'] ? 'yes' : 'no' ) );
		WP_CLI::log( sprintf( 'HPOS enabled: %s', $env['hpos_enabled'] ? 'yes' : 'no' ) );

		if ( empty( $results['sources'] ) ) {
			WP_CLI::success( 'No subscription data from supported source plugins was found.' );
			return;
		}

		$rows = array();
		foreach ( $results['sources'] as $source ) {
			$statuses = array();
			foreach ( $source['statuses'] as $status => $count ) {
				$statuses[] = $status . ':' . $count;
			}

			$continuity = array();
			foreach ( $source['continuity'] as $bucket => $count ) {
				$continuity[] = $bucket . ':' . $count;
			}

			$rows[] = array(
				'source'        => $source['label'],
				'plugin_active' => $source['plugin_active'] ? 'yes' : 'no',
				'store'         => $source['store'],
				'total'         => $source['total'],
				'statuses'      => implode( ' ', $statuses ),
				'continuity'    => implode( ' ', $continuity ),
			);
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		WP_CLI\Utils\format_items( $format, $rows, array( 'source', 'plugin_active', 'store', 'total', 'statuses', 'continuity' ) );
	}
}
