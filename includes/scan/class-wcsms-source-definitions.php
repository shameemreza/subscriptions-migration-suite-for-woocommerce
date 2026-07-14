<?php
/**
 * Definitions of supported migration sources.
 *
 * Each definition describes where a source plugin stores its subscription
 * records and how to read the lifecycle status. The scanner reads these
 * directly from the database so detection works even when the source plugin
 * is inactive (Flexible Subscriptions refuses to run next to WooCommerce
 * Subscriptions, so inactive sources are the normal case).
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registry of source plugin definitions.
 */
class WCSMS_Source_Definitions {

	/**
	 * Get all source definitions.
	 *
	 * Storage types:
	 * - order_type: a WooCommerce order type, may live in HPOS tables or posts.
	 * - post_type:  a plain custom post type in wp_posts.
	 * - table:      a custom database table.
	 *
	 * @return array<string, array>
	 */
	public static function all() {
		return array(
			'flexible_subscriptions' => array(
				'label'         => 'Flexible Subscriptions (WP Desk)',
				'storage'       => 'order_type',
				'object_type'   => 'fsb_subscription',
				'status_source' => 'record_status',
				'plugin_dir'    => 'flexible-subscriptions',
			),
			'wpswings'               => array(
				'label'         => 'Subscriptions For WooCommerce (WP Swings)',
				'storage'       => 'order_type',
				'object_type'   => 'wps_subscriptions',
				'status_source' => 'meta',
				'status_keys'   => array( 'wps_subscription_status' ),
				'plugin_dir'    => 'subscriptions-for-woocommerce',
			),
			'yith'                   => array(
				'label'         => 'YITH WooCommerce Subscription',
				'storage'       => 'post_type',
				'object_type'   => 'ywsbs_subscription',
				'status_source' => 'meta',
				'status_keys'   => array( 'status', '_status' ),
				'plugin_dir'    => 'yith-woocommerce-subscription',
			),
			'wpsubscription'         => array(
				'label'         => 'WPSubscription (ConversWP)',
				'storage'       => 'post_type',
				'object_type'   => 'subscrpt_order',
				'status_source' => 'record_status',
				'plugin_dir'    => 'subscription',
			),
			'sublium'                => array(
				'label'         => 'Sublium (FunnelKit)',
				'storage'       => 'table',
				'table'         => 'sublium_wcs_subscriptions',
				'status_source' => 'column',
				'status_column' => 'status',
				'status_map'    => array(
					1  => 'pending',
					2  => 'trialing',
					3  => 'active',
					4  => 'on-hold',
					5  => 'overdue',
					6  => 'unpaid',
					7  => 'paused',
					8  => 'completed',
					9  => 'cancelled',
					10 => 'pending-cancel',
					11 => 'disputed',
				),
				'plugin_dir'    => 'sublium-subscriptions-for-woocommerce',
			),
		);
	}

	/**
	 * Whether a plugin from the given directory is active.
	 *
	 * Matches on the plugin directory rather than the main file name, since
	 * main file names differ between source plugin versions.
	 *
	 * @param string $plugin_dir Plugin directory name.
	 * @return bool
	 */
	public static function is_plugin_active_by_dir( $plugin_dir ) {
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$network_active = (array) get_site_option( 'active_sitewide_plugins', array() );
			$active         = array_merge( $active, array_keys( $network_active ) );
		}

		foreach ( $active as $plugin_file ) {
			if ( 0 === strpos( $plugin_file, $plugin_dir . '/' ) ) {
				return true;
			}
		}

		return false;
	}
}
