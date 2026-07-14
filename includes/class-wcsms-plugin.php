<?php
/**
 * Main plugin controller.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires up the plugin components.
 */
final class WCSMS_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var WCSMS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the single instance.
	 *
	 * @return WCSMS_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook everything up. Admin classes load only in admin requests to keep
	 * the frontend footprint at zero.
	 */
	private function __construct() {
		// Registered on every request type: Action Scheduler runs queued
		// batches outside wp-admin.
		WCSMS_Batch_Runner::init();

		if ( is_admin() ) {
			WCSMS_Admin::init();
			WCSMS_Settings::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WCSMS_CLI::register();
		}
	}

	/**
	 * Whether WooCommerce Subscriptions is active, meaning the import target
	 * APIs (wcs_create_subscription and friends) are available.
	 *
	 * @return bool
	 */
	public static function is_wcs_active() {
		return function_exists( 'wcs_create_subscription' );
	}
}
