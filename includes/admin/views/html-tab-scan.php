<?php
/**
 * Scan tab.
 *
 * @package WCSMS
 * @var array|null $scan_results Set by WCSMS_Admin::render_page().
 */

defined( 'ABSPATH' ) || exit;
?>
<p>
	<?php esc_html_e( 'Scan this site for subscription data from other subscription plugins, then migrate it into WooCommerce Subscriptions.', 'subscriptions-migration-suite-for-woocommerce' ); ?>
	<?php echo wc_help_tip( __( 'The scan is read-only. It looks for subscription records in the database, including data left behind by plugins that are no longer active.', 'subscriptions-migration-suite-for-woocommerce' ) ); ?>
</p>

<form method="post">
	<?php wp_nonce_field( WCSMS_Admin::SCAN_ACTION ); ?>
	<p>
		<button type="submit" name="wcsms_scan" value="1" class="button button-primary">
			<?php esc_html_e( 'Scan for subscription data', 'subscriptions-migration-suite-for-woocommerce' ); ?>
		</button>
	</p>
</form>

<?php if ( null !== $scan_results ) : ?>
	<?php if ( empty( $scan_results['sources'] ) ) : ?>
		<div class="notice notice-info inline">
			<p><?php esc_html_e( 'No subscription data from supported source plugins was found on this site.', 'subscriptions-migration-suite-for-woocommerce' ); ?></p>
		</div>
	<?php else : ?>
		<h2><?php esc_html_e( 'Scan results', 'subscriptions-migration-suite-for-woocommerce' ); ?></h2>
		<table class="widefat striped" style="max-width: 1100px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Source plugin', 'subscriptions-migration-suite-for-woocommerce' ); ?></th>
					<th>
						<?php esc_html_e( 'Plugin status', 'subscriptions-migration-suite-for-woocommerce' ); ?>
						<?php echo wc_help_tip( __( 'Source data can be migrated whether or not the source plugin is active. Inactive is safer: it prevents the source plugin from creating renewals during migration.', 'subscriptions-migration-suite-for-woocommerce' ) ); ?>
					</th>
					<th>
						<?php esc_html_e( 'Storage', 'subscriptions-migration-suite-for-woocommerce' ); ?>
						<?php echo wc_help_tip( __( 'Where the source keeps its records: HPOS order tables, the posts table, or its own custom tables.', 'subscriptions-migration-suite-for-woocommerce' ) ); ?>
					</th>
					<th><?php esc_html_e( 'Subscriptions', 'subscriptions-migration-suite-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'By status', 'subscriptions-migration-suite-for-woocommerce' ); ?></th>
					<th>
						<?php esc_html_e( 'Payment continuity', 'subscriptions-migration-suite-for-woocommerce' ); ?>
						<?php echo wc_help_tip( __( 'Whether automatic renewals survive migration. Carries over: renews without customer action. Conditional: renews if the same gateway stays active. Re-authorization needed: customer must add a payment method. Blocked: billing is hosted at the gateway and must be resolved there first. Manual renewal: no automatic payments in the source either.', 'subscriptions-migration-suite-for-woocommerce' ) ); ?>
					</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $scan_results['sources'] as $source ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $source['label'] ); ?></strong></td>
						<td>
							<?php
							echo $source['plugin_active']
								? esc_html__( 'Active', 'subscriptions-migration-suite-for-woocommerce' )
								: esc_html__( 'Inactive', 'subscriptions-migration-suite-for-woocommerce' );
							?>
						</td>
						<td><?php echo esc_html( $source['store'] ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $source['total'] ) ); ?></td>
						<td>
							<?php
							$parts = array();
							foreach ( $source['statuses'] as $status => $count ) {
								$parts[] = sprintf( '%s: %s', $status, number_format_i18n( $count ) );
							}
							echo esc_html( implode( ', ', $parts ) );
							?>
						</td>
						<td>
							<?php
							$labels = WCSMS_Continuity::labels();
							$parts  = array();
							foreach ( $source['continuity'] as $bucket => $count ) {
								$label   = isset( $labels[ $bucket ] ) ? $labels[ $bucket ] : $bucket;
								$parts[] = sprintf( '%s: %s', $label, number_format_i18n( $count ) );
							}
							echo esc_html( implode( ', ', $parts ) );
							?>
						</td>
						<td>
							<?php if ( null !== WCSMS_Sources::get( $source['id'] ) ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( WCSMS_Admin::MIGRATE_ACTION ); ?>
									<input type="hidden" name="action" value="<?php echo esc_attr( WCSMS_Admin::MIGRATE_ACTION ); ?>" />
									<input type="hidden" name="wcsms_source" value="<?php echo esc_attr( $source['id'] ); ?>" />
									<label style="display: block; margin-bottom: 4px;">
										<input type="checkbox" name="wcsms_live" value="1" />
										<?php esc_html_e( 'Live', 'subscriptions-migration-suite-for-woocommerce' ); ?>
										<?php echo wc_help_tip( __( 'Leave unchecked to queue a dry run first: records are validated and resolved, nothing is written.', 'subscriptions-migration-suite-for-woocommerce' ) ); ?>
									</label>
									<button type="submit" class="button button-primary"><?php esc_html_e( 'Queue migration', 'subscriptions-migration-suite-for-woocommerce' ); ?></button>
								</form>
							<?php else : ?>
								<em><?php esc_html_e( 'Adapter not available yet', 'subscriptions-migration-suite-for-woocommerce' ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
<?php endif; ?>
