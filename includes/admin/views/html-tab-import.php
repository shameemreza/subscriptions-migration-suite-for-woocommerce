<?php
/**
 * Import tab.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;
?>
<p>
	<?php esc_html_e( 'Upload a JSON Lines file of subscription records. The import runs in the background and every record is matched by its source id, so re-importing a file never creates duplicates.', 'subscriptions-migration-suite-for-woocommerce' ); ?>
	<?php echo wc_help_tip( __( 'One JSON record per line. Records reference customers by id or email and products by id or SKU; both must already exist on this site.', 'subscriptions-migration-suite-for-woocommerce' ) ); ?>
</p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
	<?php wp_nonce_field( WCSMS_Admin::UPLOAD_ACTION ); ?>
	<input type="hidden" name="action" value="<?php echo esc_attr( WCSMS_Admin::UPLOAD_ACTION ); ?>" />

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">
				<label for="wcsms_file"><?php esc_html_e( 'Import file', 'subscriptions-migration-suite-for-woocommerce' ); ?></label>
			</th>
			<td>
				<input type="file" name="wcsms_file" id="wcsms_file" accept=".jsonl,.json" required />
				<p class="description"><?php esc_html_e( 'Accepted formats: .jsonl and .json (one record per line). The file is stored in a protected directory and removed when the run completes.', 'subscriptions-migration-suite-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Mode', 'subscriptions-migration-suite-for-woocommerce' ); ?>
				<?php echo wc_help_tip( __( 'Leave unchecked to dry run first: every record is validated and resolved, nothing is written, and problems show per line on the runs tab.', 'subscriptions-migration-suite-for-woocommerce' ) ); ?>
			</th>
			<td>
				<label for="wcsms_live">
					<input type="checkbox" name="wcsms_live" id="wcsms_live" value="1" />
					<?php esc_html_e( 'Live import (writes subscriptions)', 'subscriptions-migration-suite-for-woocommerce' ); ?>
				</label>
			</td>
		</tr>
	</table>

	<p class="submit">
		<button type="submit" class="button button-primary">
			<?php esc_html_e( 'Queue import', 'subscriptions-migration-suite-for-woocommerce' ); ?>
		</button>
	</p>
</form>
