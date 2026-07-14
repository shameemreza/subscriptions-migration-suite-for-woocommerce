<?php
/**
 * Admin page shell with tabs.
 *
 * @package WCSMS
 * @var string     $current_tab  Set by WCSMS_Admin::render_page().
 * @var array|null $scan_results Set by WCSMS_Admin::render_page().
 */

defined( 'ABSPATH' ) || exit;

// Keep in step with WCSMS_Admin::TABS: this array drives the nav links.
$wcsms_tabs = array(
	'scan'   => __( 'Scan', 'subscriptions-migration-suite-for-woocommerce' ),
	'import' => __( 'Import', 'subscriptions-migration-suite-for-woocommerce' ),
	'export' => __( 'Export', 'subscriptions-migration-suite-for-woocommerce' ),
	'runs'   => __( 'Runs', 'subscriptions-migration-suite-for-woocommerce' ),
);
?>
<div class="wrap woocommerce">
	<h1><?php esc_html_e( 'Subscriptions migration', 'subscriptions-migration-suite-for-woocommerce' ); ?></h1>

	<?php if ( ! WCSMS_Plugin::is_wcs_active() ) : ?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'WooCommerce Subscriptions is not active. You can scan and dry run, but live imports need WooCommerce Subscriptions installed and active.', 'subscriptions-migration-suite-for-woocommerce' ); ?></p>
		</div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper woo-nav-tab-wrapper">
		<?php foreach ( $wcsms_tabs as $wcsms_tab_id => $wcsms_tab_label ) : ?>
			<a
				href="<?php echo esc_url( WCSMS_Admin::page_url( $wcsms_tab_id ) ); ?>"
				class="nav-tab <?php echo $current_tab === $wcsms_tab_id ? 'nav-tab-active' : ''; ?>"
			><?php echo esc_html( $wcsms_tab_label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php include WCSMS_PLUGIN_DIR . 'includes/admin/views/html-tab-' . $current_tab . '.php'; ?>
</div>
