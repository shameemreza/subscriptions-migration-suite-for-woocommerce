<?php
/**
 * Plugin Name: Subscriptions Migration Suite for WooCommerce
 * Plugin URI: https://github.com/shameemreza/subscriptions-migration-suite-for-woocommerce
 * Description: Export, import, and migrate subscriptions into WooCommerce Subscriptions from third-party subscription plugins.
 * Version: 0.1.0
 * Author: Shameem Reza
 * Author URI: https://shameemreza.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: subscriptions-migration-suite-for-woocommerce
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 10.9
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

define( 'WCSMS_VERSION', '0.1.0' );
define( 'WCSMS_PLUGIN_FILE', __FILE__ );
define( 'WCSMS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCSMS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Declare compatibility with HPOS and block checkout before WooCommerce boots.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Boot the plugin once all plugins are loaded, so WooCommerce is available.
 */
function wcsms_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wcsms_woocommerce_missing_notice' );
		return;
	}

	require_once WCSMS_PLUGIN_DIR . 'includes/class-wcsms-autoloader.php';
	WCSMS_Autoloader::register();
	WCSMS_Plugin::instance();
}
add_action( 'plugins_loaded', 'wcsms_init' );

/**
 * Admin notice shown when WooCommerce is not active.
 */
function wcsms_woocommerce_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Subscriptions Migration Suite for WooCommerce requires WooCommerce to be installed and active.', 'subscriptions-migration-suite-for-woocommerce' )
	);
}
