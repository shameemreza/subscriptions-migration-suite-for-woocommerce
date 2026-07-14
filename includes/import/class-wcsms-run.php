<?php
/**
 * Run state storage.
 *
 * A run is one import job processed in the background. State lives in a
 * non-autoloaded option per run plus a bounded index, which keeps the
 * footprint small without a custom table. Per-row error detail is capped;
 * the full trail is in the WooCommerce log.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates, loads, and updates run records.
 */
class WCSMS_Run {

	const OPTION_PREFIX = 'wcsms_run_';
	const INDEX_OPTION  = 'wcsms_runs_index';
	const MAX_RUNS      = 50;
	const MAX_ERRORS    = 100;

	/**
	 * Create a run record.
	 *
	 * @param string $type Run type, for example jsonl_import.
	 * @param array  $data Type-specific fields merged into the record.
	 * @return array The stored run.
	 */
	public static function create( $type, $data = array() ) {
		$id = gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 6, false, false ) );

		$run = array_merge(
			array(
				'id'         => $id,
				'type'       => $type,
				'status'     => 'queued',
				'offset'     => 0,
				'line'       => 0,
				'tallies'    => array(
					'created' => 0,
					'skipped' => 0,
					'dry_run' => 0,
					'failed'  => 0,
				),
				'errors'     => array(),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			$data
		);

		update_option( self::OPTION_PREFIX . $id, $run, false );
		self::index_add( $id );

		return $run;
	}

	/**
	 * Load a run.
	 *
	 * @param string $id Run id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$run = get_option( self::OPTION_PREFIX . $id );
		return is_array( $run ) ? $run : null;
	}

	/**
	 * Persist a run, refreshing its updated_at stamp.
	 *
	 * @param array $run Run record.
	 */
	public static function save( $run ) {
		$run['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		update_option( self::OPTION_PREFIX . $run['id'], $run, false );
	}

	/**
	 * Append a row error, bounded so a fully failing file cannot bloat the
	 * option. The count in tallies stays exact either way.
	 *
	 * @param array  $run     Run record, modified in place.
	 * @param int    $line    Line number.
	 * @param string $message Error message.
	 */
	public static function add_error( &$run, $line, $message ) {
		if ( count( $run['errors'] ) < self::MAX_ERRORS ) {
			$run['errors'][] = array(
				'line'    => $line,
				'message' => $message,
			);
		}
	}

	/**
	 * All run ids, newest first.
	 *
	 * @return string[]
	 */
	public static function ids() {
		$index = get_option( self::INDEX_OPTION, array() );
		return is_array( $index ) ? array_reverse( $index ) : array();
	}

	/**
	 * Add a run id to the index, pruning the oldest runs past the cap.
	 *
	 * @param string $id Run id.
	 */
	private static function index_add( $id ) {
		$index   = get_option( self::INDEX_OPTION, array() );
		$index   = is_array( $index ) ? $index : array();
		$index[] = $id;

		while ( count( $index ) > self::MAX_RUNS ) {
			$oldest = array_shift( $index );
			delete_option( self::OPTION_PREFIX . $oldest );
		}

		update_option( self::INDEX_OPTION, $index, false );
	}
}
