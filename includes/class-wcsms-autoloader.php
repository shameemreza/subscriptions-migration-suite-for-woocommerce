<?php
/**
 * Class-map autoloader.
 *
 * A static class map is the fastest option: no directory scanning and no
 * string transformation on every lookup. New classes must be added here.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Autoloader for WCSMS classes.
 */
class WCSMS_Autoloader {

	/**
	 * Class map: class name => path relative to the includes directory.
	 *
	 * @var array<string, string>
	 */
	private static $class_map = array(
		'WCSMS_Plugin'             => 'class-wcsms-plugin.php',
		'WCSMS_Logger'             => 'class-wcsms-logger.php',
		'WCSMS_Admin'              => 'admin/class-wcsms-admin.php',
		'WCSMS_Settings'           => 'admin/class-wcsms-settings.php',
		'WCSMS_Scanner'            => 'scan/class-wcsms-scanner.php',
		'WCSMS_Continuity'         => 'scan/class-wcsms-continuity.php',
		'WCSMS_Source_Definitions' => 'scan/class-wcsms-source-definitions.php',
		'WCSMS_Record'             => 'import/class-wcsms-record.php',
		'WCSMS_Importer'           => 'import/class-wcsms-importer.php',
		'WCSMS_Run'                => 'import/class-wcsms-run.php',
		'WCSMS_Exporter'           => 'export/class-wcsms-exporter.php',
		'WCSMS_Source_Adapter'     => 'sources/class-wcsms-source-adapter.php',
		'WCSMS_Sources'            => 'sources/class-wcsms-source-adapter.php',
		'WCSMS_Source_Flexible_Subscriptions' => 'sources/class-wcsms-source-flexible-subscriptions.php',
		'WCSMS_Source_WPSwings'    => 'sources/class-wcsms-source-wpswings.php',
		'WCSMS_Source_YITH'        => 'sources/class-wcsms-source-yith.php',
		'WCSMS_Source_WPSubscription' => 'sources/class-wcsms-source-wpsubscription.php',
		'WCSMS_Source_Sublium'     => 'sources/class-wcsms-source-sublium.php',
		'WCSMS_Batch_Runner'       => 'import/class-wcsms-batch-runner.php',
		'WCSMS_Product_Converter'  => 'import/class-wcsms-product-converter.php',
		'WCSMS_Cutover'            => 'import/class-wcsms-cutover.php',
		'WCSMS_Rollback'           => 'import/class-wcsms-rollback.php',
		'WCSMS_CLI'                => 'cli/class-wcsms-cli.php',
	);

	/**
	 * Register the autoloader.
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Load a mapped class.
	 *
	 * @param string $class_name Requested class name.
	 */
	public static function autoload( $class_name ) {
		if ( isset( self::$class_map[ $class_name ] ) ) {
			require WCSMS_PLUGIN_DIR . 'includes/' . self::$class_map[ $class_name ];
		}
	}
}
