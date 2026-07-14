<?php
/**
 * Export tab.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

$wcsms_statuses = function_exists( 'wcs_get_subscription_statuses' ) ? wcs_get_subscription_statuses() : array();
$wcsms_gateways = WC()->payment_gateways()->get_available_payment_gateways();
?>
<p>
	<?php esc_html_e( 'Download subscriptions as a JSON Lines file. The file uses the same format the import tab reads, so it can be imported on another site as-is.', 'subscriptions-migration-suite-for-woocommerce' ); ?>
</p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( WCSMS_Admin::EXPORT_ACTION ); ?>
	<input type="hidden" name="action" value="<?php echo esc_attr( WCSMS_Admin::EXPORT_ACTION ); ?>" />

	<table class="form-table">
	<tbody>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Statuses', 'subscriptions-migration-suite-for-woocommerce' ); ?></th>
			<td class="forminp forminp-checkbox">
				<fieldset>
					<legend class="screen-reader-text"><span><?php esc_html_e( 'Statuses', 'subscriptions-migration-suite-for-woocommerce' ); ?></span></legend>
					<?php foreach ( $wcsms_statuses as $wcsms_status_key => $wcsms_status_label ) : ?>
						<label style="margin-right: 16px; display: inline-block;">
							<input type="checkbox" name="wcsms_statuses[]" value="<?php echo esc_attr( str_replace( 'wc-', '', $wcsms_status_key ) ); ?>" />
							<?php echo esc_html( $wcsms_status_label ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<p class="description"><?php esc_html_e( 'Leave all unchecked to export every status.', 'subscriptions-migration-suite-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="wcsms_customer"><?php esc_html_e( 'Customer', 'subscriptions-migration-suite-for-woocommerce' ); ?></label>
			</th>
			<td class="forminp">
				<select
					class="wc-customer-search"
					id="wcsms_customer"
					name="wcsms_customer"
					data-placeholder="<?php esc_attr_e( 'All customers', 'subscriptions-migration-suite-for-woocommerce' ); ?>"
					data-allow_clear="true"
					style="width: 300px;"
				></select>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="wcsms_gateway"><?php esc_html_e( 'Payment method', 'subscriptions-migration-suite-for-woocommerce' ); ?></label>
			</th>
			<td class="forminp">
				<select name="wcsms_gateway" id="wcsms_gateway" class="wc-enhanced-select" style="width: 300px;">
					<option value=""><?php esc_html_e( 'Any payment method', 'subscriptions-migration-suite-for-woocommerce' ); ?></option>
					<?php foreach ( $wcsms_gateways as $wcsms_gateway_id => $wcsms_gateway ) : ?>
						<option value="<?php echo esc_attr( $wcsms_gateway_id ); ?>"><?php echo esc_html( $wcsms_gateway->get_title() ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="wcsms_date_after"><?php esc_html_e( 'Created between', 'subscriptions-migration-suite-for-woocommerce' ); ?></label>
			</th>
			<td class="forminp">
				<input type="date" name="wcsms_date_after" id="wcsms_date_after" />
				&nbsp;<?php esc_html_e( 'and', 'subscriptions-migration-suite-for-woocommerce' ); ?>&nbsp;
				<input type="date" name="wcsms_date_before" id="wcsms_date_before" />
				<p class="description"><?php esc_html_e( 'Leave empty for all dates.', 'subscriptions-migration-suite-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Payment tokens', 'subscriptions-migration-suite-for-woocommerce' ); ?></th>
			<td class="forminp forminp-checkbox">
				<fieldset>
					<legend class="screen-reader-text"><span><?php esc_html_e( 'Payment tokens', 'subscriptions-migration-suite-for-woocommerce' ); ?></span></legend>
					<label for="wcsms_include_tokens">
						<input type="checkbox" name="wcsms_include_tokens" id="wcsms_include_tokens" value="1" />
						<?php esc_html_e( 'Include payment meta in the export', 'subscriptions-migration-suite-for-woocommerce' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Includes each gateway\'s recurring payment references (customer and token ids) so automatic renewals can continue on the target site. Only enable this when the file will be handled securely: the values are sensitive.', 'subscriptions-migration-suite-for-woocommerce' ); ?></p>
				</fieldset>
			</td>
		</tr>
	</tbody>
	</table>

	<p class="submit">
		<button type="submit" class="button button-primary">
			<?php esc_html_e( 'Download export', 'subscriptions-migration-suite-for-woocommerce' ); ?>
		</button>
	</p>
</form>
