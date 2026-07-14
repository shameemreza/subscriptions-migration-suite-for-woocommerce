<?php
/**
 * Admin screen under the WooCommerce menu.
 *
 * @package WCSMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin page, its tabs, and the form handlers.
 */
class WCSMS_Admin {

	const PAGE_SLUG     = 'wcsms';
	const SCAN_ACTION   = 'wcsms_run_scan';
	const UPLOAD_ACTION = 'wcsms_upload_import';
	const RESUME_ACTION = 'wcsms_resume_run';
	const EXPORT_ACTION = 'wcsms_export_download';

	/**
	 * Tabs shown on the page.
	 *
	 * @var string[]
	 */
	const TABS = array( 'scan', 'import', 'export', 'runs' );

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 60 );
		add_action( 'admin_post_' . self::UPLOAD_ACTION, array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_' . self::RESUME_ACTION, array( __CLASS__, 'handle_resume' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/**
	 * Enqueue WooCommerce admin assets on our page, for the enhanced
	 * customer search select on the export tab.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue( $hook_suffix ) {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
	}

	/**
	 * Add the submenu page under WooCommerce.
	 */
	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Subscriptions migration', 'subscriptions-migration-suite-for-woocommerce' ),
			__( 'Subscriptions migration', 'subscriptions-migration-suite-for-woocommerce' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * The active tab, validated against the whitelist.
	 *
	 * @return string
	 */
	public static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'scan'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab selection only.
		return in_array( $tab, self::TABS, true ) ? $tab : 'scan';
	}

	/**
	 * URL of the page, optionally for a specific tab.
	 *
	 * @param string $tab Tab id.
	 * @return string
	 */
	public static function page_url( $tab = '' ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		return $tab ? add_query_arg( 'tab', $tab, $url ) : $url;
	}

	/**
	 * Render the admin page. The scan tab runs its read-only scan inline;
	 * import and resume post to admin-post.php handlers.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$current_tab  = self::current_tab();
		$scan_results = null;

		if ( 'scan' === $current_tab && isset( $_POST['wcsms_scan'] ) ) {
			check_admin_referer( self::SCAN_ACTION );
			$scanner      = new WCSMS_Scanner();
			$scan_results = $scanner->scan();
		}

		include WCSMS_PLUGIN_DIR . 'includes/admin/views/html-admin-page.php';
	}

	/**
	 * Handle the import file upload: store it in the protected directory,
	 * validate the first record parses, and queue a background run.
	 */
	public static function handle_upload() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to import subscriptions.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		check_admin_referer( self::UPLOAD_ACTION );

		if ( empty( $_FILES['wcsms_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['wcsms_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Path used only via is_uploaded_file/move_uploaded_file.
			self::redirect_with_notice( 'import', 'error', __( 'No file was uploaded.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$name = isset( $_FILES['wcsms_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['wcsms_file']['name'] ) ) : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, array( 'jsonl', 'json' ), true ) ) {
			self::redirect_with_notice( 'import', 'error', __( 'Only .jsonl and .json files are accepted.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$dir = self::upload_dir();
		if ( is_wp_error( $dir ) ) {
			self::redirect_with_notice( 'import', 'error', $dir->get_error_message() );
		}

		$target = trailingslashit( $dir ) . 'import-' . gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 8, false, false ) ) . '.jsonl';

		if ( ! move_uploaded_file( $_FILES['wcsms_file']['tmp_name'], $target ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_move_uploaded_file -- Standard upload move into a generated path.
			self::redirect_with_notice( 'import', 'error', __( 'The uploaded file could not be stored.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		if ( ! self::first_record_parses( $target ) ) {
			wp_delete_file( $target );
			self::redirect_with_notice( 'import', 'error', __( 'The file does not look like JSON Lines: the first record did not parse.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$dry_run = empty( $_POST['wcsms_live'] );
		$run     = WCSMS_Batch_Runner::start_jsonl( $target, $dry_run, array( 'own_file' => true ) );

		if ( is_wp_error( $run ) ) {
			wp_delete_file( $target );
			self::redirect_with_notice( 'import', 'error', $run->get_error_message() );
		}

		self::redirect_with_notice(
			'runs',
			'success',
			$dry_run
				? __( 'Dry run queued. Nothing will be written; results appear below as batches finish.', 'subscriptions-migration-suite-for-woocommerce' )
				: __( 'Import queued. Batches process in the background; refresh to follow progress.', 'subscriptions-migration-suite-for-woocommerce' )
		);
	}

	/**
	 * Handle a resume request from the runs tab.
	 */
	public static function handle_resume() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage runs.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		check_admin_referer( self::RESUME_ACTION );

		$run_id = isset( $_POST['wcsms_run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['wcsms_run_id'] ) ) : '';
		$result = WCSMS_Batch_Runner::resume( $run_id );

		if ( is_wp_error( $result ) ) {
			self::redirect_with_notice( 'runs', 'error', $result->get_error_message() );
		}

		self::redirect_with_notice( 'runs', 'success', __( 'Run re-queued from its last checkpoint.', 'subscriptions-migration-suite-for-woocommerce' ) );
	}

	/**
	 * Stream a filtered export as a JSONL download. The exporter writes
	 * batch by batch, so memory stays flat regardless of store size.
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to export subscriptions.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		check_admin_referer( self::EXPORT_ACTION );

		if ( ! WCSMS_Plugin::is_wcs_active() ) {
			self::redirect_with_notice( 'export', 'error', __( 'WooCommerce Subscriptions must be active to export.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$statuses = array();
		if ( ! empty( $_POST['wcsms_statuses'] ) && is_array( $_POST['wcsms_statuses'] ) ) {
			$statuses = array_map( 'sanitize_key', wp_unslash( $_POST['wcsms_statuses'] ) );
		}

		$args = array(
			'statuses'       => $statuses,
			'customer_id'    => isset( $_POST['wcsms_customer'] ) ? absint( $_POST['wcsms_customer'] ) : 0,
			'gateway'        => isset( $_POST['wcsms_gateway'] ) ? sanitize_text_field( wp_unslash( $_POST['wcsms_gateway'] ) ) : '',
			'date_after'     => isset( $_POST['wcsms_date_after'] ) ? sanitize_text_field( wp_unslash( $_POST['wcsms_date_after'] ) ) : '',
			'date_before'    => isset( $_POST['wcsms_date_before'] ) ? sanitize_text_field( wp_unslash( $_POST['wcsms_date_before'] ) ) : '',
			'include_tokens' => ! empty( $_POST['wcsms_include_tokens'] ),
		);

		$filename = 'subscriptions-export-' . gmdate( 'Ymd-His' ) . '.jsonl';

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$handle   = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming the download body.
		$exporter = new WCSMS_Exporter();
		$exporter->export( $handle, $args );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Matching fopen above.

		exit;
	}

	/**
	 * Show the notice carried on the redirect back to the page.
	 */
	public static function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Displaying a message set by our own nonce-checked handlers; no state changes.
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] || empty( $_GET['wcsms_notice'] ) ) {
			return;
		}

		$type    = isset( $_GET['wcsms_notice'] ) && 'error' === $_GET['wcsms_notice'] ? 'error' : 'success';
		$message = isset( $_GET['wcsms_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['wcsms_message'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * The protected directory imports are stored in. Files can hold customer
	 * data and payment references, so the directory blocks direct access on
	 * Apache and uses unguessable file names as the second layer (covers
	 * nginx, which ignores .htaccess).
	 *
	 * @return string|WP_Error Directory path.
	 */
	private static function upload_dir() {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'wcsms_uploads', $uploads['error'] );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'wcsms-imports';

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'wcsms_uploads', __( 'The import directory could not be created.', 'subscriptions-migration-suite-for-woocommerce' ) );
		}

		$files = array(
			'.htaccess'  => 'Deny from all',
			'index.html' => '',
		);

		foreach ( $files as $file => $content ) {
			$path = trailingslashit( $dir ) . $file;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing two tiny protection files once.
			}
		}

		return $dir;
	}

	/**
	 * Whether the first non-empty line of the file is valid JSON.
	 *
	 * @param string $file File path.
	 * @return bool
	 */
	private static function first_record_parses( $file ) {
		$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading one line for validation.

		if ( false === $handle ) {
			return false;
		}

		$valid = false;

		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard streaming read.
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$valid = is_array( json_decode( $line, true ) );
			break;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Matching fopen above.

		return $valid;
	}

	/**
	 * Redirect back to the page with a notice and stop.
	 *
	 * @param string $tab     Target tab.
	 * @param string $type    success or error.
	 * @param string $message Notice text.
	 */
	private static function redirect_with_notice( $tab, $type, $message ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'wcsms_notice'  => $type,
					'wcsms_message' => rawurlencode( $message ),
				),
				self::page_url( $tab )
			)
		);
		exit;
	}
}
