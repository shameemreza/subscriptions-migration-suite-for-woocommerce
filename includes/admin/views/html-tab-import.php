<?php
/**
 * Import tab.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;
?>
<p>
	<?php esc_html_e( 'Upload a JSON Lines file of subscription records, one record per line. The import runs in the background, and every record is matched by its source id, so re-importing a file never creates duplicates. Records reference customers by id or email and products by id or SKU; both must already exist on this site.', 'subscriptions-migration-suite-for-woocommerce' ); ?>
</p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
	<?php wp_nonce_field( WCSMS_Admin::UPLOAD_ACTION ); ?>
	<input type="hidden" name="action" value="<?php echo esc_attr( WCSMS_Admin::UPLOAD_ACTION ); ?>" />

	<table class="form-table">
		<tbody>
			<tr valign="top">
				<th scope="row" class="titledesc">
					<label for="wcsms_file"><?php esc_html_e( 'Import file', 'subscriptions-migration-suite-for-woocommerce' ); ?></label>
				</th>
				<td class="forminp">
					<input type="file" name="wcsms_file" id="wcsms_file" accept=".jsonl,.json" required />
					<p class="description"><?php esc_html_e( 'Accepted formats: .jsonl and .json (one record per line). The file is stored in a protected directory and removed when the run completes.', 'subscriptions-migration-suite-for-woocommerce' ); ?></p>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row" class="titledesc"><?php esc_html_e( 'Mode', 'subscriptions-migration-suite-for-woocommerce' ); ?></th>
				<td class="forminp forminp-checkbox">
					<fieldset>
						<legend class="screen-reader-text"><span><?php esc_html_e( 'Mode', 'subscriptions-migration-suite-for-woocommerce' ); ?></span></legend>
						<label for="wcsms_live">
							<input type="checkbox" name="wcsms_live" id="wcsms_live" value="1" />
							<?php esc_html_e( 'Live import (writes subscriptions)', 'subscriptions-migration-suite-for-woocommerce' ); ?>
						</label>
						<?php echo wc_help_tip( __( 'Leave unchecked to dry run first: every record is validated and resolved, nothing is written, and problems show per line on the runs tab.', 'subscriptions-migration-suite-for-woocommerce' ) ); ?>
					</fieldset>
				</td>
			</tr>
		</tbody>
	</table>

	<p class="submit">
		<button type="submit" class="button button-primary">
			<?php esc_html_e( 'Queue import', 'subscriptions-migration-suite-for-woocommerce' ); ?>
		</button>
	</p>
</form>
