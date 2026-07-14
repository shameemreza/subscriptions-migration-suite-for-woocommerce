<?php
/**
 * Settings section inside WooCommerce settings.
 *
 * Lives under WooCommerce > Settings > Advanced, following the WooCommerce
 * settings API so fields, tooltips, and saving all behave natively.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the migration suite section to the Advanced settings tab.
 */
class WCSMS_Settings {

	const SECTION_ID = 'wcsms';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_filter( 'woocommerce_get_sections_advanced', array( __CLASS__, 'add_section' ) );
		add_filter( 'woocommerce_get_settings_advanced', array( __CLASS__, 'add_settings' ), 10, 2 );
	}

	/**
	 * Register the section label.
	 *
	 * @param array $sections Existing Advanced tab sections.
	 * @return array
	 */
	public static function add_section( $sections ) {
		$sections[ self::SECTION_ID ] = __( 'Subscriptions migration', 'subscriptions-migration-suite-for-woocommerce' );
		return $sections;
	}

	/**
	 * Register the settings fields.
	 *
	 * @param array  $settings        Current settings.
	 * @param string $current_section Current section id.
	 * @return array
	 */
	public static function add_settings( $settings, $current_section ) {
		if ( self::SECTION_ID !== $current_section ) {
			return $settings;
		}

		return array(
			array(
				'title' => __( 'Subscriptions migration', 'subscriptions-migration-suite-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Defaults for migration, export, and import runs.', 'subscriptions-migration-suite-for-woocommerce' ),
				'id'    => 'wcsms_settings_title',
			),
			array(
				'title'             => __( 'Batch size', 'subscriptions-migration-suite-for-woocommerce' ),
				'desc_tip'          => __( 'How many subscriptions each background batch processes. Lower this on shared hosting; raise it on strong servers.', 'subscriptions-migration-suite-for-woocommerce' ),
				'id'                => 'wcsms_batch_size',
				'type'              => 'number',
				'default'           => '20',
				'custom_attributes' => array(
					'min'  => '1',
					'max'  => '500',
					'step' => '1',
				),
			),
			array(
				'title'    => __( 'Enable logging', 'subscriptions-migration-suite-for-woocommerce' ),
				'desc'     => __( 'Write migration activity to the WooCommerce log', 'subscriptions-migration-suite-for-woocommerce' ),
				'desc_tip' => __( 'Logs appear under WooCommerce > Status > Logs with the source "wcsms".', 'subscriptions-migration-suite-for-woocommerce' ),
				'id'       => 'wcsms_enable_logging',
				'type'     => 'checkbox',
				'default'  => 'yes',
			),
			array(
				'title'    => __( 'Remove data on uninstall', 'subscriptions-migration-suite-for-woocommerce' ),
				'desc'     => __( 'Delete this plugin\'s settings and run history when the plugin is deleted', 'subscriptions-migration-suite-for-woocommerce' ),
				'desc_tip' => __( 'Migrated subscriptions are never deleted. This only removes the plugin\'s own settings and reports.', 'subscriptions-migration-suite-for-woocommerce' ),
				'id'       => 'wcsms_delete_data_on_uninstall',
				'type'     => 'checkbox',
				'default'  => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'wcsms_settings_title',
			),
		);
	}
}
