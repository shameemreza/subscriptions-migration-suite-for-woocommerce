<?php
/**
 * WP-CLI commands.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * The wcsms command namespace.
 */
class WCSMS_CLI {

	/**
	 * Register commands with WP-CLI.
	 */
	public static function register() {
		WP_CLI::add_command( 'wcsms scan', array( __CLASS__, 'scan' ) );
		WP_CLI::add_command( 'wcsms import', array( __CLASS__, 'import' ) );
		WP_CLI::add_command( 'wcsms runs', array( __CLASS__, 'runs' ) );
		WP_CLI::add_command( 'wcsms resume', array( __CLASS__, 'resume' ) );
		WP_CLI::add_command( 'wcsms export', array( __CLASS__, 'export' ) );
		WP_CLI::add_command( 'wcsms migrate', array( __CLASS__, 'migrate' ) );
		WP_CLI::add_command( 'wcsms convert-products', array( __CLASS__, 'convert_products' ) );
		WP_CLI::add_command( 'wcsms cutover', array( __CLASS__, 'cutover' ) );
		WP_CLI::add_command( 'wcsms rollback', array( __CLASS__, 'rollback' ) );
		WP_CLI::add_command( 'wcsms verify', array( __CLASS__, 'verify' ) );
	}

	/**
	 * Verify a migrated source: reconcile counts and check that every
	 * migrated subscription can actually bill.
	 *
	 * Checks that active subscriptions have future next payment dates and,
	 * once released, scheduled renewal actions; that pending cancellations
	 * carry their end dates and prepaid term actions; that automatic
	 * renewals point at gateways active on this site; and that held
	 * subscriptions are counted so a forgotten cutover is visible.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : The source id, as listed by wp wcsms migrate.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcsms verify wpswings
	 *
	 * @param array $args Positional arguments.
	 */
	public static function verify( $args ) {
		if ( ! WCSMS_Plugin::is_wcs_active() ) {
			WP_CLI::error( 'WooCommerce Subscriptions must be active to verify.' );
		}

		$adapter = WCSMS_Sources::get( $args[0] );

		if ( null === $adapter ) {
			WP_CLI::error( sprintf( 'Unknown source "%s". Available: %s', $args[0], implode( ', ', array_keys( WCSMS_Sources::adapters() ) ) ) );
		}

		$report = WCSMS_Verifier::verify( $adapter );

		WP_CLI::log( sprintf( 'Source records remaining: %d', $report['source_total'] ) );
		WP_CLI::log( sprintf( 'Migrated subscriptions: %d', $report['migrated'] ) );

		$statuses = array();
		foreach ( $report['statuses'] as $status => $count ) {
			$statuses[] = $status . ': ' . $count;
		}
		WP_CLI::log( 'By status: ' . ( $statuses ? implode( ', ', $statuses ) : 'none' ) );

		if ( $report['held'] > 0 ) {
			WP_CLI::warning( sprintf( '%d subscriptions are still held from renewals. Complete the cutover: wp wcsms cutover %s --live', $report['held'], $adapter->id() ) );
		}

		if ( empty( $report['issues'] ) ) {
			WP_CLI::success( 'All checks passed.' );
			return;
		}

		foreach ( $report['issues'] as $issue ) {
			WP_CLI::warning( sprintf( '#%d: %s', $issue['subscription_id'], $issue['issue'] ) );
		}

		WP_CLI::error( sprintf( '%d issues found.', count( $report['issues'] ) ) );
	}

	/**
	 * Remove subscriptions a migration or import created.
	 *
	 * Deletes only subscriptions stamped by this plugin, matched by source
	 * or by run id. Source plugin data is never touched. Dry run by
	 * default.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Remove everything imported from this source id.
	 *
	 * [--run=<run_id>]
	 * : Remove everything a specific run created.
	 *
	 * [--live]
	 * : Perform the deletion. Without it, only the count is reported.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcsms rollback --source=yith
	 *     wp wcsms rollback --run=20260714150425 --live
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public static function rollback( $args, $assoc_args ) {
		$source = isset( $assoc_args['source'] ) ? sanitize_key( $assoc_args['source'] ) : '';
		$run_id = isset( $assoc_args['run'] ) ? sanitize_text_field( $assoc_args['run'] ) : '';

		if ( '' === $source && '' === $run_id ) {
			WP_CLI::error( 'Pass --source=<id> or --run=<run_id> to select what to roll back.' );
		}

		$dry_run = ! isset( $assoc_args['live'] );
		$report  = WCSMS_Rollback::run( $source, $run_id, $dry_run );

		if ( $dry_run ) {
			WP_CLI::success( sprintf( '%d subscriptions would be removed. Pass --live to delete them.', $report['found'] ) );
			return;
		}

		WP_CLI::success( sprintf( 'Removed %d of %d subscriptions.', $report['deleted'], $report['found'] ) );
	}

	/**
	 * Convert a source plugin's subscription products into WooCommerce
	 * Subscriptions products.
	 *
	 * Sets the subscription product type and writes the _subscription_*
	 * meta so migrated subscriptions renew with the right product behavior.
	 * Dry run by default. Converted products are stamped and skipped on
	 * re-runs. Variable subscription products are reported and skipped.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : The source id, as listed by wp wcsms migrate.
	 *
	 * [--live]
	 * : Write the conversions.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcsms convert-products flexible_subscriptions
	 *     wp wcsms convert-products wpswings --live
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public static function convert_products( $args, $assoc_args ) {
		if ( ! WCSMS_Plugin::is_wcs_active() ) {
			WP_CLI::error( 'WooCommerce Subscriptions must be active to convert products.' );
		}

		$adapter = WCSMS_Sources::get( $args[0] );

		if ( null === $adapter ) {
			WP_CLI::error( sprintf( 'Unknown source "%s". Available: %s', $args[0], implode( ', ', array_keys( WCSMS_Sources::adapters() ) ) ) );
		}

		$dry_run = ! isset( $assoc_args['live'] );

		if ( $dry_run ) {
			WP_CLI::log( 'Dry run: nothing will be written. Pass --live to convert.' );
		}

		$converter = new WCSMS_Product_Converter();
		$results   = $converter->convert_all( $adapter, $dry_run );

		if ( empty( $results ) ) {
			WP_CLI::success( 'No subscription products found for that source.' );
			return;
		}

		$tally = array();
		foreach ( $results as $row ) {
			$tally[ $row['status'] ] = isset( $tally[ $row['status'] ] ) ? $tally[ $row['status'] ] + 1 : 1;
			$line                    = sprintf( '#%d: %s', $row['product_id'], $row['message'] );
			if ( 'failed' === $row['status'] ) {
				WP_CLI::warning( $line );
			} else {
				WP_CLI::log( $line );
			}
		}

		$parts = array();
		foreach ( $tally as $status => $count ) {
			$parts[] = $status . ': ' . $count;
		}
		WP_CLI::success( 'Done. ' . implode( ', ', $parts ) . '.' );
	}

	/**
	 * Migrate subscriptions from a source plugin into WooCommerce
	 * Subscriptions.
	 *
	 * Reads the source data directly from the database, so the source plugin
	 * can (and should) stay deactivated. Runs as a dry run unless --live is
	 * passed. Already-migrated records are matched by source stamps and
	 * skipped.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : The source id. Currently: flexible_subscriptions.
	 *
	 * [--live]
	 * : Write subscriptions. Without this flag every record is validated and
	 * resolved but nothing is created.
	 *
	 * [--background]
	 * : Queue the migration on Action Scheduler instead of processing now.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcsms migrate flexible_subscriptions
	 *     wp wcsms migrate flexible_subscriptions --live
	 *     wp wcsms migrate flexible_subscriptions --live --background
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public static function migrate( $args, $assoc_args ) {
		$source_id = $args[0];
		$dry_run   = ! isset( $assoc_args['live'] );

		if ( ! WCSMS_Plugin::is_wcs_active() && ! $dry_run ) {
			WP_CLI::error( 'WooCommerce Subscriptions must be active for a live migration.' );
		}

		$adapter = WCSMS_Sources::get( $source_id );

		if ( null === $adapter ) {
			WP_CLI::error( sprintf( 'Unknown source "%s". Available: %s', $source_id, implode( ', ', array_keys( WCSMS_Sources::adapters() ) ) ) );
		}

		if ( $dry_run ) {
			WP_CLI::log( 'Dry run: nothing will be written. Pass --live to migrate.' );
		}

		if ( isset( $assoc_args['background'] ) ) {
			$run = WCSMS_Batch_Runner::start_source( $source_id, $dry_run );

			if ( is_wp_error( $run ) ) {
				WP_CLI::error( $run->get_error_message() );
			}

			WP_CLI::success( sprintf( 'Run %s queued: %d subscriptions. Track it with: wp wcsms runs', $run['id'], $run['total'] ) );
			return;
		}

		$total = $adapter->count();
		WP_CLI::log( sprintf( '%s: %d subscriptions found.', $adapter->label(), $total ) );

		$importer = new WCSMS_Importer();
		$run_id   = 'cli-' . gmdate( 'YmdHis' );
		$offset   = 0;
		$batch    = max( 1, (int) get_option( 'wcsms_batch_size', 20 ) );
		$tally    = array(
			'created' => 0,
			'skipped' => 0,
			'dry_run' => 0,
			'failed'  => 0,
		);

		while ( true ) {
			$rows = $adapter->fetch( $offset, $batch );

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$offset++;

				if ( null === $row['record'] ) {
					$tally['failed']++;
					WP_CLI::warning( sprintf( '#%s failed. %s', $row['source_ref'], $row['error'] ) );
					continue;
				}

				$result = $importer->import(
					$row['record'],
					array(
						'dry_run' => $dry_run,
						'run_id'  => $run_id,
						'hold'    => true,
					)
				);

				$tally[ $result['status'] ]++;

				if ( 'failed' === $result['status'] ) {
					WP_CLI::warning( sprintf( '#%s failed. %s', $row['source_ref'], implode( ' ', $result['errors'] ) ) );
				} elseif ( 'created' === $result['status'] ) {
					$note = $result['warnings'] ? ' ' . implode( ' ', $result['warnings'] ) : '';
					WP_CLI::log( sprintf( '#%s migrated as subscription #%d.%s', $row['source_ref'], $result['subscription_id'], $note ) );
				} elseif ( 'skipped' === $result['status'] ) {
					WP_CLI::log( sprintf( '#%s skipped. %s', $row['source_ref'], implode( ' ', $result['warnings'] ) ) );
				}
			}

			if ( count( $rows ) < $batch ) {
				break;
			}
		}

		WP_CLI::success(
			sprintf(
				'Done. created: %d, skipped: %d, validated: %d, failed: %d (run %s).',
				$tally['created'],
				$tally['skipped'],
				$tally['dry_run'],
				$tally['failed'],
				$run_id
			)
		);

		if ( $tally['created'] > 0 ) {
			WP_CLI::log( sprintf( 'Migrated subscriptions are held from renewals until cutover. When ready: wp wcsms cutover %s --live', $source_id ) );
		}

		if ( $tally['failed'] > 0 ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Confirm cutover for a migrated source: remove the source plugin's
	 * scheduled renewal jobs and release held subscriptions so WooCommerce
	 * Subscriptions takes over billing.
	 *
	 * Dry run by default: reports pending source jobs and held
	 * subscriptions without changing anything.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : The source id, as listed by wp wcsms migrate.
	 *
	 * [--live]
	 * : Perform the cutover.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcsms cutover flexible_subscriptions
	 *     wp wcsms cutover flexible_subscriptions --live
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public static function cutover( $args, $assoc_args ) {
		if ( ! WCSMS_Plugin::is_wcs_active() ) {
			WP_CLI::error( 'WooCommerce Subscriptions must be active for cutover.' );
		}

		$adapter = WCSMS_Sources::get( $args[0] );

		if ( null === $adapter ) {
			WP_CLI::error( sprintf( 'Unknown source "%s". Available: %s', $args[0], implode( ', ', array_keys( WCSMS_Sources::adapters() ) ) ) );
		}

		$dry_run = ! isset( $assoc_args['live'] );
		$report  = WCSMS_Cutover::run( $adapter, $dry_run );

		if ( empty( $report['source_actions'] ) ) {
			WP_CLI::log( 'Source plugin scheduled jobs: none pending.' );
		} else {
			foreach ( $report['source_actions'] as $hook => $count ) {
				WP_CLI::log( sprintf( 'Source job %s: %d pending%s', $hook, $count, $dry_run ? '' : ' (removed)' ) );
			}
		}

		if ( $dry_run ) {
			WP_CLI::log( sprintf( 'Held subscriptions ready to release: %d.', $report['held'] ) );
			WP_CLI::success( 'Dry run only. Pass --live to perform the cutover.' );
			return;
		}

		WP_CLI::success(
			sprintf(
				'Cutover done. Released %d subscriptions, scheduled %d WooCommerce Subscriptions actions.',
				$report['released'],
				$report['scheduled']
			)
		);
	}

	/**
	 * Export WooCommerce Subscriptions to a JSON Lines file.
	 *
	 * The output uses the same record format the importer reads, so the file
	 * can be imported on another site as-is.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to write. The file is overwritten.
	 *
	 * [--status=<statuses>]
	 * : Comma-separated statuses without the wc- prefix (active,on-hold,...).
	 * Default: all statuses.
	 *
	 * [--customer=<id>]
	 * : Limit to one customer id.
	 *
	 * [--gateway=<id>]
	 * : Limit to one payment method id.
	 *
	 * [--date-after=<date>]
	 * : Only subscriptions created on or after this date (Y-m-d).
	 *
	 * [--date-before=<date>]
	 * : Only subscriptions created on or before this date (Y-m-d).
	 *
	 * [--include-tokens]
	 * : Include gateway payment meta (customer and token references) so
	 * automatic renewals can continue on the target site. Off by default
	 * because the values are sensitive.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcsms export subscriptions.jsonl
	 *     wp wcsms export active.jsonl --status=active,pending-cancel --include-tokens
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public static function export( $args, $assoc_args ) {
		if ( ! WCSMS_Plugin::is_wcs_active() ) {
			WP_CLI::error( 'WooCommerce Subscriptions must be active to export.' );
		}

		$file   = $args[0];
		$handle = fopen( $file, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming to a CLI-supplied file.

		if ( false === $handle ) {
			WP_CLI::error( sprintf( 'Cannot write to %s', $file ) );
		}

		$statuses = array();
		if ( ! empty( $assoc_args['status'] ) ) {
			$statuses = array_filter( array_map( 'trim', explode( ',', $assoc_args['status'] ) ) );
		}

		$exporter = new WCSMS_Exporter();
		$stats    = $exporter->export(
			$handle,
			array(
				'statuses'       => $statuses,
				'customer_id'    => isset( $assoc_args['customer'] ) ? (int) $assoc_args['customer'] : 0,
				'gateway'        => isset( $assoc_args['gateway'] ) ? $assoc_args['gateway'] : '',
				'date_after'     => isset( $assoc_args['date-after'] ) ? $assoc_args['date-after'] : '',
				'date_before'    => isset( $assoc_args['date-before'] ) ? $assoc_args['date-before'] : '',
				'include_tokens' => isset( $assoc_args['include-tokens'] ),
			),
			static function ( $total ) {
				WP_CLI::log( sprintf( '%d exported...', $total ) );
			}
		);

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Matching fopen above.

		WP_CLI::success( sprintf( 'Exported %d subscriptions to %s.', $stats['exported'], $file ) );
	}

	/**
	 * List background import runs.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public static function runs( $args, $assoc_args ) {
		$rows = array();

		foreach ( WCSMS_Run::ids() as $id ) {
			$run = WCSMS_Run::get( $id );
			if ( null === $run ) {
				continue;
			}

			$rows[] = array(
				'id'      => $run['id'],
				'type'    => $run['type'],
				'status'  => $run['status'],
				'line'    => $run['line'],
				'created' => $run['tallies']['created'],
				'skipped' => $run['tallies']['skipped'],
				'failed'  => $run['tallies']['failed'],
				'updated' => $run['updated_at'],
			);
		}

		if ( empty( $rows ) ) {
			WP_CLI::log( 'No runs yet.' );
			return;
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'type', 'status', 'line', 'created', 'skipped', 'failed', 'updated' ) );
	}

	/**
	 * Resume an interrupted background run from its last checkpoint.
	 *
	 * Safe to run after a crash or timeout: rows the previous attempt already
	 * imported are matched by their source stamps and skipped.
	 *
	 * ## OPTIONS
	 *
	 * <run_id>
	 * : The run id shown by wp wcsms runs.
	 *
	 * @param array $args Positional arguments.
	 */
	public static function resume( $args ) {
		$result = WCSMS_Batch_Runner::resume( $args[0] );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Run %s re-queued from line %d. Batches run on Action Scheduler.', $result['id'], $result['line'] ) );
	}

	/**
	 * Import subscriptions from a JSON Lines file.
	 *
	 * Each line is one JSON record in the normalized format (see
	 * WCSMS_Record). Runs as a dry run unless --live is passed. Records
	 * already imported (matched by source and source_id) are skipped.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the JSON Lines file.
	 *
	 * [--live]
	 * : Write to the database. Without this flag the run validates and
	 * resolves every record but creates nothing.
	 *
	 * [--limit=<n>]
	 * : Stop after this many records. Foreground runs only.
	 *
	 * [--background]
	 * : Queue the import on Action Scheduler instead of processing it now.
	 * The file must stay in place until the run completes. Track progress
	 * with wp wcsms runs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcsms import subscriptions.jsonl
	 *     wp wcsms import subscriptions.jsonl --live
	 *     wp wcsms import subscriptions.jsonl --live --background
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public static function import( $args, $assoc_args ) {
		if ( ! WCSMS_Plugin::is_wcs_active() ) {
			WP_CLI::error( 'WooCommerce Subscriptions must be active to import.' );
		}

		$file = $args[0];
		if ( ! is_readable( $file ) ) {
			WP_CLI::error( sprintf( 'File not readable: %s', $file ) );
		}

		$dry_run = ! isset( $assoc_args['live'] );
		$limit   = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 0;
		$run_id  = 'cli-' . gmdate( 'YmdHis' );

		if ( $dry_run ) {
			WP_CLI::log( 'Dry run: nothing will be written. Pass --live to import.' );
		}

		if ( isset( $assoc_args['background'] ) ) {
			$run = WCSMS_Batch_Runner::start_jsonl( $file, $dry_run );

			if ( is_wp_error( $run ) ) {
				WP_CLI::error( $run->get_error_message() );
			}

			WP_CLI::success( sprintf( 'Run %s queued on Action Scheduler. Track it with: wp wcsms runs', $run['id'] ) );
			return;
		}

		$importer = new WCSMS_Importer();
		$handle   = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming a local CLI-supplied file line by line.
		$line_no  = 0;
		$tally    = array(
			'created' => 0,
			'skipped' => 0,
			'dry_run' => 0,
			'failed'  => 0,
		);

		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard streaming read.
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$line_no++;
			if ( $limit && $line_no > $limit ) {
				break;
			}

			$raw = json_decode( $line, true );

			if ( null === $raw ) {
				$tally['failed']++;
				WP_CLI::warning( sprintf( 'Line %d: invalid JSON, skipped.', $line_no ) );
				continue;
			}

			$result = $importer->import(
				$raw,
				array(
					'dry_run' => $dry_run,
					'run_id'  => $run_id,
				)
			);

			$tally[ $result['status'] ]++;

			$prefix = sprintf( 'Line %d (%s):', $line_no, $result['source_id'] ? $result['source_id'] : '?' );

			if ( 'failed' === $result['status'] ) {
				WP_CLI::warning( sprintf( '%s failed. %s', $prefix, implode( ' ', $result['errors'] ) ) );
			} elseif ( 'skipped' === $result['status'] ) {
				WP_CLI::log( sprintf( '%s skipped. %s', $prefix, implode( ' ', $result['warnings'] ) ) );
			} else {
				$note = $result['warnings'] ? ' ' . implode( ' ', $result['warnings'] ) : '';
				if ( 'created' === $result['status'] ) {
					WP_CLI::log( sprintf( '%s created subscription #%d.%s', $prefix, $result['subscription_id'], $note ) );
				} else {
					WP_CLI::log( sprintf( '%s ok.%s', $prefix, $note ) );
				}
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Matching fopen above.

		WP_CLI::success(
			sprintf(
				'Done. created: %d, skipped: %d, validated: %d, failed: %d (run %s).',
				$tally['created'],
				$tally['skipped'],
				$tally['dry_run'],
				$tally['failed'],
				$run_id
			)
		);

		if ( $tally['failed'] > 0 ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Scan the site for migratable subscription data.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcsms scan
	 *     wp wcsms scan --format=json
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public static function scan( $args, $assoc_args ) {
		$scanner = new WCSMS_Scanner();
		$results = $scanner->scan();

		$env = $results['environment'];
		WP_CLI::log( sprintf( 'WooCommerce Subscriptions active: %s', $env['wcs_active'] ? 'yes' : 'no' ) );
		WP_CLI::log( sprintf( 'HPOS enabled: %s', $env['hpos_enabled'] ? 'yes' : 'no' ) );

		if ( empty( $results['sources'] ) ) {
			WP_CLI::success( 'No subscription data from supported source plugins was found.' );
			return;
		}

		$rows = array();
		foreach ( $results['sources'] as $source ) {
			$statuses = array();
			foreach ( $source['statuses'] as $status => $count ) {
				$statuses[] = $status . ':' . $count;
			}

			$continuity = array();
			foreach ( $source['continuity'] as $bucket => $count ) {
				$continuity[] = $bucket . ':' . $count;
			}

			$rows[] = array(
				'source'        => $source['label'],
				'plugin_active' => $source['plugin_active'] ? 'yes' : 'no',
				'store'         => $source['store'],
				'total'         => $source['total'],
				'statuses'      => implode( ' ', $statuses ),
				'continuity'    => implode( ' ', $continuity ),
			);
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		WP_CLI\Utils\format_items( $format, $rows, array( 'source', 'plugin_active', 'store', 'total', 'statuses', 'continuity' ) );
	}
}
