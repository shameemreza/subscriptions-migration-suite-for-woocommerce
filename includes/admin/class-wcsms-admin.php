<?php
/**
 * Admin screen under the WooCommerce menu.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin page and handles the scan action.
 */
class WCSMS_Admin {

	const PAGE_SLUG   = 'wcsms';
	const SCAN_ACTION = 'wcsms_run_scan';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 60 );
	}

	/**
	 * Add the submenu page under WooCommerce.
	 */
	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Subscriptions migration', 'subscriptions-migration-suite-for-woocommerce' ),
			__( 'Subscriptions migration', 'subscriptions-migration-suite-for-woocommerce' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the admin page. Runs a scan when the form was submitted with a
	 * valid nonce; the scan itself is read-only.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$scan_results = null;

		if ( isset( $_POST['wcsms_scan'] ) ) {
			check_admin_referer( self::SCAN_ACTION );
			$scanner      = new WCSMS_Scanner();
			$scan_results = $scanner->scan();
		}

		include WCSMS_PLUGIN_DIR . 'includes/admin/views/html-admin-page.php';
	}
}
