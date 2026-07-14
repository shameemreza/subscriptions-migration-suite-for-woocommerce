<?php
/**
 * Background batch runner on Action Scheduler.
 *
 * Each batch is one scheduled action that processes up to the configured
 * batch size, checkpoints the file byte offset in the run record, and
 * enqueues the next batch. A crash or timeout loses at most one batch of
 * progress, and re-processing those rows is safe because the importer skips
 * anything already stamped.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schedules and processes import batches.
 */
class WCSMS_Batch_Runner {

	const HOOK  = 'wcsms_process_batch';
	const GROUP = 'wcsms';

	/**
	 * Hook registration. Must run on every request type: Action Scheduler
	 * executes queued actions outside wp-admin.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Start a background JSON Lines import.
	 *
	 * @param string $file    Absolute path to the JSONL file. Must remain in
	 *                        place until the run completes.
	 * @param bool   $dry_run Validate without writing.
	 * @param array  $extra   Extra run fields, for example own_file => true
	 *                        when the file was uploaded into the plugin's own
	 *                        directory and should be deleted on completion.
	 * @return array|WP_Error The created run, or an error.
	 */
	public static function start_jsonl( $file, $dry_run, $extra = array() ) {
		$path = realpath( $file );

		if ( false === $path || ! is_readable( $path ) ) {
			return new WP_Error( 'wcsms_file_unreadable', __( 'The import file does not exist or is not readable.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$run = WCSMS_Run::create(
			'jsonl_import',
			array_merge(
				$extra,
				array(
					'file'    => $path,
					'dry_run' => (bool) $dry_run,
				)
			)
		);

		self::enqueue( $run['id'] );

		WCSMS_Logger::log( sprintf( 'Run %s queued for %s (%s).', $run['id'], $path, $dry_run ? 'dry run' : 'live' ) );

		return $run;
	}

	/**
	 * Start a background migration from a source adapter.
	 *
	 * @param string $source_id Adapter id from WCSMS_Sources.
	 * @param bool   $dry_run   Validate without writing.
	 * @return array|WP_Error The created run, or an error.
	 */
	public static function start_source( $source_id, $dry_run ) {
		$adapter = WCSMS_Sources::get( $source_id );

		if ( null === $adapter ) {
			return new WP_Error( 'wcsms_unknown_source', __( 'No adapter exists for that source.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$total = $adapter->count();

		if ( 0 === $total ) {
			return new WP_Error( 'wcsms_source_empty', __( 'No migratable subscriptions were found for that source.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$run = WCSMS_Run::create(
			'source_import',
			array(
				'source'  => $source_id,
				'total'   => $total,
				'dry_run' => (bool) $dry_run,
			)
		);

		self::enqueue( $run['id'] );

		WCSMS_Logger::log( sprintf( 'Run %s queued for source %s: %d subscriptions (%s).', $run['id'], $source_id, $total, $dry_run ? 'dry run' : 'live' ) );

		return $run;
	}

	/**
	 * Re-queue an interrupted run from its last checkpoint.
	 *
	 * @param string $run_id Run id.
	 * @return array|WP_Error The run, or an error.
	 */
	public static function resume( $run_id ) {
		$run = WCSMS_Run::get( $run_id );

		if ( null === $run ) {
			return new WP_Error( 'wcsms_run_missing', __( 'No run with that id exists.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		if ( 'completed' === $run['status'] ) {
			return new WP_Error( 'wcsms_run_done', __( 'That run already completed.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		if ( as_has_scheduled_action( self::HOOK, array( 'run_id' => $run_id ), self::GROUP ) ) {
			return new WP_Error( 'wcsms_run_scheduled', __( 'That run already has a batch scheduled.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$run['status'] = 'queued';
		WCSMS_Run::save( $run );
		self::enqueue( $run_id );

		WCSMS_Logger::log( sprintf( 'Run %s resumed from line %d.', $run_id, $run['line'] ) );

		return $run;
	}

	/**
	 * Process one batch. Runs inside an Action Scheduler action.
	 *
	 * @param string $run_id Run id.
	 */
	public static function handle( $run_id ) {
		$run = WCSMS_Run::get( $run_id );

		if ( null === $run || ! in_array( $run['status'], array( 'queued', 'running' ), true ) ) {
			return;
		}

		if ( ! WCSMS_Plugin::is_wcs_active() && empty( $run['dry_run'] ) ) {
			$run['status'] = 'failed';
			WCSMS_Run::add_error( $run, $run['line'], __( 'WooCommerce Subscriptions is not active.', 'subscriptions-migration-suite-for-woocommerce' ) );
			WCSMS_Run::save( $run );
			return;
		}

		if ( 'source_import' === $run['type'] ) {
			self::handle_source_batch( $run );
			return;
		}

		$handle = is_readable( $run['file'] ) ? fopen( $run['file'], 'r' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming the run's own checkpointed file.

		if ( false === $handle ) {
			$run['status'] = 'failed';
			WCSMS_Run::add_error( $run, $run['line'], __( 'The import file is no longer readable.', 'subscriptions-migration-suite-for-woocommerce' ) );
			WCSMS_Run::save( $run );
			WCSMS_Logger::log( sprintf( 'Run %s failed: file missing.', $run_id ), 'error' );
			return;
		}

		$run['status'] = 'running';

		fseek( $handle, (int) $run['offset'] );

		$importer   = new WCSMS_Importer();
		$batch_size = max( 1, (int) get_option( 'wcsms_batch_size', 20 ) );
		$processed  = 0;
		$done       = false;

		while ( $processed < $batch_size ) {
			$line = fgets( $handle );

			if ( false === $line ) {
				$done = true;
				break;
			}

			$run['offset'] = ftell( $handle );

			if ( '' === trim( $line ) ) {
				continue;
			}

			$run['line']++;
			$processed++;

			$raw = json_decode( trim( $line ), true );

			if ( null === $raw ) {
				$run['tallies']['failed']++;
				WCSMS_Run::add_error( $run, $run['line'], __( 'Invalid JSON.', 'subscriptions-migration-suite-for-woocommerce' ) );
				continue;
			}

			$result = $importer->import(
				$raw,
				array(
					'dry_run' => ! empty( $run['dry_run'] ),
					'run_id'  => $run['id'],
				)
			);

			$run['tallies'][ $result['status'] ]++;

			if ( 'failed' === $result['status'] ) {
				WCSMS_Run::add_error( $run, $run['line'], implode( ' ', $result['errors'] ) );
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Matching fopen above.

		if ( $done ) {
			$run['status'] = 'completed';
			WCSMS_Run::save( $run );

			if ( ! empty( $run['own_file'] ) ) {
				wp_delete_file( $run['file'] );
			}
			WCSMS_Logger::log(
				sprintf(
					'Run %s completed. created: %d, skipped: %d, validated: %d, failed: %d.',
					$run_id,
					$run['tallies']['created'],
					$run['tallies']['skipped'],
					$run['tallies']['dry_run'],
					$run['tallies']['failed']
				)
			);

			/**
			 * Fires when a background import run completes.
			 *
			 * @param array $run The finished run record.
			 */
			do_action( 'wcsms_run_completed', $run );
			return;
		}

		WCSMS_Run::save( $run );
		self::enqueue( $run_id );
	}

	/**
	 * Process one batch of a source-adapter migration. The checkpoint is the
	 * count of records processed, which is also the fetch offset: adapters
	 * return rows in stable id order.
	 *
	 * @param array $run Run record.
	 */
	private static function handle_source_batch( $run ) {
		$adapter = WCSMS_Sources::get( $run['source'] );

		if ( null === $adapter ) {
			$run['status'] = 'failed';
			WCSMS_Run::add_error( $run, $run['line'], __( 'The source adapter is no longer available.', 'subscriptions-migration-suite-for-woocommerce' ) );
			WCSMS_Run::save( $run );
			return;
		}

		$run['status'] = 'running';

		$batch_size = max( 1, (int) get_option( 'wcsms_batch_size', 20 ) );
		$rows       = $adapter->fetch( (int) $run['line'], $batch_size );
		$importer   = new WCSMS_Importer();

		foreach ( $rows as $row ) {
			$run['line']++;

			if ( null === $row['record'] ) {
				$run['tallies']['failed']++;
				WCSMS_Run::add_error( $run, $run['line'], sprintf( '%s: %s', $row['source_ref'], $row['error'] ) );
				continue;
			}

			$result = $importer->import(
				$row['record'],
				array(
					'dry_run' => ! empty( $run['dry_run'] ),
					'run_id'  => $run['id'],
				)
			);

			$run['tallies'][ $result['status'] ]++;

			if ( 'failed' === $result['status'] ) {
				WCSMS_Run::add_error( $run, $run['line'], sprintf( '%s: %s', $row['source_ref'], implode( ' ', $result['errors'] ) ) );
			}
		}

		if ( count( $rows ) < $batch_size ) {
			$run['status'] = 'completed';
			WCSMS_Run::save( $run );
			WCSMS_Logger::log(
				sprintf(
					'Run %s completed. created: %d, skipped: %d, validated: %d, failed: %d.',
					$run['id'],
					$run['tallies']['created'],
					$run['tallies']['skipped'],
					$run['tallies']['dry_run'],
					$run['tallies']['failed']
				)
			);

			/** This action is documented earlier in this file. */
			do_action( 'wcsms_run_completed', $run );
			return;
		}

		WCSMS_Run::save( $run );
		self::enqueue( $run['id'] );
	}

	/**
	 * Queue the next batch action.
	 *
	 * @param string $run_id Run id.
	 */
	private static function enqueue( $run_id ) {
		as_enqueue_async_action( self::HOOK, array( 'run_id' => $run_id ), self::GROUP );
	}
}
