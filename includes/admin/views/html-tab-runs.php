<?php
/**
 * Runs tab.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

$wcsms_runs = array();
foreach ( WCSMS_Run::ids() as $wcsms_run_id ) {
	$wcsms_run = WCSMS_Run::get( $wcsms_run_id );
	if ( null !== $wcsms_run ) {
		$wcsms_runs[] = $wcsms_run;
	}
}
?>
<p>
	<?php esc_html_e( 'Background import runs. Batches process on Action Scheduler; refresh this page to follow progress. An interrupted run can be resumed from its last checkpoint, and rows the earlier attempt already imported are skipped, not duplicated.', 'subscriptions-migration-suite-for-woocommerce' ); ?>
</p>

<?php if ( empty( $wcsms_runs ) ) : ?>
	<div class="notice notice-info inline">
		<p><?php esc_html_e( 'No runs yet. Queue one from the import tab.', 'subscriptions-migration-suite-for-woocommerce' ); ?></p>
	</div>
<?php else : ?>
	<table class="widefat striped" style="max-width: 1100px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Run', 'subscriptions-migration-suite-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Mode', 'subscriptions-migration-suite-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Status', 'subscriptions-migration-suite-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Processed', 'subscriptions-migration-suite-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Created', 'subscriptions-migration-suite-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Skipped', 'subscriptions-migration-suite-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Failed', 'subscriptions-migration-suite-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Updated', 'subscriptions-migration-suite-for-woocommerce' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $wcsms_runs as $wcsms_run ) : ?>
				<tr>
					<td><code><?php echo esc_html( $wcsms_run['id'] ); ?></code></td>
					<td>
						<?php
						echo empty( $wcsms_run['dry_run'] )
							? esc_html__( 'Live', 'subscriptions-migration-suite-for-woocommerce' )
							: esc_html__( 'Dry run', 'subscriptions-migration-suite-for-woocommerce' );
						?>
					</td>
					<td>
						<?php
						$wcsms_status_labels = array(
							'queued'    => __( 'Queued', 'subscriptions-migration-suite-for-woocommerce' ),
							'running'   => __( 'Running', 'subscriptions-migration-suite-for-woocommerce' ),
							'completed' => __( 'Completed', 'subscriptions-migration-suite-for-woocommerce' ),
							'failed'    => __( 'Failed', 'subscriptions-migration-suite-for-woocommerce' ),
						);
						echo esc_html( isset( $wcsms_status_labels[ $wcsms_run['status'] ] ) ? $wcsms_status_labels[ $wcsms_run['status'] ] : $wcsms_run['status'] );
						?>
					</td>
					<td><?php echo esc_html( number_format_i18n( $wcsms_run['line'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( empty( $wcsms_run['dry_run'] ) ? $wcsms_run['tallies']['created'] : $wcsms_run['tallies']['dry_run'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $wcsms_run['tallies']['skipped'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $wcsms_run['tallies']['failed'] ) ); ?></td>
					<td><?php echo esc_html( $wcsms_run['updated_at'] ); ?></td>
					<td>
						<?php if ( ! in_array( $wcsms_run['status'], array( 'completed' ), true ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( WCSMS_Admin::RESUME_ACTION ); ?>
								<input type="hidden" name="action" value="<?php echo esc_attr( WCSMS_Admin::RESUME_ACTION ); ?>" />
								<input type="hidden" name="wcsms_run_id" value="<?php echo esc_attr( $wcsms_run['id'] ); ?>" />
								<button type="submit" class="button"><?php esc_html_e( 'Resume', 'subscriptions-migration-suite-for-woocommerce' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( ! empty( $wcsms_run['errors'] ) ) : ?>
					<tr>
						<td colspan="9">
							<details>
								<summary>
									<?php
									/* translators: %d: number of failed rows shown. */
									echo esc_html( sprintf( __( 'Row errors (%d shown)', 'subscriptions-migration-suite-for-woocommerce' ), count( $wcsms_run['errors'] ) ) );
									?>
								</summary>
								<ul>
									<?php foreach ( $wcsms_run['errors'] as $wcsms_error ) : ?>
										<li>
											<?php
											/* translators: 1: line number, 2: error message. */
											echo esc_html( sprintf( __( 'Line %1$d: %2$s', 'subscriptions-migration-suite-for-woocommerce' ), $wcsms_error['line'], $wcsms_error['message'] ) );
											?>
										</li>
									<?php endforeach; ?>
								</ul>
							</details>
						</td>
					</tr>
				<?php endif; ?>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
