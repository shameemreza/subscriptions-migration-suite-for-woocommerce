<?php
/**
 * Uninstall handler.
 *
 * Removes the plugin's own settings, run history, and stored import files,
 * and only when the merchant opted in. Migrated subscriptions and source
 * data are never touched.
 *
 * @package WCSMS
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( 'yes' !== get_option( 'wcsms_delete_data_on_uninstall', 'no' ) ) {
	return;
}

// Run history: every run stored under its own option, plus the index.
$wcsms_run_ids = get_option( 'wcsms_runs_index', array() );
if ( is_array( $wcsms_run_ids ) ) {
	foreach ( $wcsms_run_ids as $wcsms_run_id ) {
		delete_option( 'wcsms_run_' . $wcsms_run_id );
	}
}
delete_option( 'wcsms_runs_index' );

// Settings.
$wcsms_options = array(
	'wcsms_batch_size',
	'wcsms_enable_logging',
	'wcsms_delete_data_on_uninstall',
);

foreach ( $wcsms_options as $wcsms_option ) {
	delete_option( $wcsms_option );
}

// Stored import files and the protected directory.
$wcsms_uploads = wp_upload_dir();
if ( empty( $wcsms_uploads['error'] ) ) {
	$wcsms_dir = trailingslashit( $wcsms_uploads['basedir'] ) . 'wcsms-imports';
	if ( is_dir( $wcsms_dir ) ) {
		$wcsms_files = glob( $wcsms_dir . '/*' );
		if ( is_array( $wcsms_files ) ) {
			array_map( 'wp_delete_file', $wcsms_files );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing the plugin's own empty directory on uninstall.
		rmdir( $wcsms_dir );
	}
}
