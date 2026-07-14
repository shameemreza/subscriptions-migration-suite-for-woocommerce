<?php
/**
 * Uninstall handler.
 *
 * Removes only the plugin's own settings, and only when the merchant opted
 * in. Migrated subscriptions and source data are never touched.
 *
 * @package WCSMS
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( 'yes' !== get_option( 'wcsms_delete_data_on_uninstall', 'no' ) ) {
	return;
}

$wcsms_options = array(
	'wcsms_batch_size',
	'wcsms_enable_logging',
	'wcsms_delete_data_on_uninstall',
);

foreach ( $wcsms_options as $wcsms_option ) {
	delete_option( $wcsms_option );
}
