<?php
/**
 * Logging wrapper around the WooCommerce logger.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Logs to the WooCommerce log with a dedicated source, gated by the
 * plugin's logging setting.
 */
class WCSMS_Logger {

	const SOURCE = 'wcsms';

	/**
	 * Write a log entry.
	 *
	 * @param string $message Message to log.
	 * @param string $level   One of the WC_Log_Levels constants. Default info.
	 * @param array  $context Extra context merged into the log entry.
	 */
	public static function log( $message, $level = 'info', $context = array() ) {
		if ( 'yes' !== get_option( 'wcsms_enable_logging', 'yes' ) ) {
			return;
		}

		$context['source'] = self::SOURCE;
		wc_get_logger()->log( $level, $message, $context );
	}
}
