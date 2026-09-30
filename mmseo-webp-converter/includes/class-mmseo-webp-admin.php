<?php
/**
 * Admin screen and AJAX endpoint.
 *
 * @package MMSEO_WebP_Converter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI class.
 */
final class MMSEO_WebP_Admin {

	/**
	 * Engine instance.
	 *
	 * @var MMSEO_WebP_Engine
	 */
	private $engine;

	/**
	 * Menu page hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Constructor.
	 *
	 * @param MMSEO_WebP_Engine $engine Engine.
	 */
	public function __construct( MMSEO_WebP_Engine $engine ) {
		$this->engine = $engine;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_mmseo_webp', array( $this, 'ajax' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MMSEO_WEBP_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Add the top-level menu entry.
	 *
	 * @return void
	 */
	public function menu() {
		$icon       = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCIgZmlsbD0iYmxhY2siPjxwYXRoIGZpbGwtcnVsZT0iZXZlbm9kZCIgZD0iTTQgMWgxMmEzIDMgMCAwIDEgMyAzdjEyYTMgMyAwIDAgMS0zIDNINGEzIDMgMCAwIDEtMy0zVjRhMyAzIDAgMCAxIDMtM3ptMCAyYTEgMSAwIDAgMC0xIDF2MTJhMSAxIDAgMCAwIDEgMWgxMmExIDEgMCAwIDAgMS0xVjRhMSAxIDAgMCAwLTEtMUg0eiIvPjxwYXRoIGQ9Ik00LjQgNmgxLjhsMS4xIDQuNkw4LjcgNmgxLjZsMS40IDQuNkwxMi44IDZoMS44bC0yLjEgOGgtMS43TDkuNSA5LjYgOC4yIDE0SDYuNXoiLz48L3N2Zz4=';
		$this->hook = add_menu_page(
			__( 'MMSEO WebP Converter', 'mmseo-webp-converter' ),
			__( 'MMSEO WebP', 'mmseo-webp-converter' ),
			'manage_options',
			'mmseo-webp',
			array( $this, 'page' ),
			$icon,
			58
		);
	}

	/**
	 * Add a shortcut link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=mmseo-webp' ) ) . '">' . esc_html__( 'Open converter', 'mmseo-webp-converter' ) . '</a>' );
		return $links;
	}

	/**
	 * Load assets on our screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( $hook !== $this->hook ) {
			return;
		}
		wp_enqueue_style( 'mmseo-webp-admin', MMSEO_WEBP_URL . 'assets/admin.css', array(), MMSEO_WEBP_VERSION );
		wp_enqueue_script( 'mmseo-webp-admin', MMSEO_WEBP_URL . 'assets/admin.js', array(), MMSEO_WEBP_VERSION, true );
		wp_localize_script(
			'mmseo-webp-admin',
			'mmseoWebp',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'mmseo_webp' ),
				'i18n'  => array(
					'confirmGo'      => __( 'A backup will be made first, then all images will be converted to WebP. Continue?', 'mmseo-webp-converter' ),
					'confirmRestore' => __( 'Restore the original images and links from this backup? WebP files created by this run will be removed.', 'mmseo-webp-converter' ),
					'confirmDelete'  => __( 'Delete this backup permanently? You will no longer be able to restore it.', 'mmseo-webp-converter' ),
					'failed'         => __( 'Request failed.', 'mmseo-webp-converter' ),
					'safe'           => __( 'Your original files are still safe in the backup.', 'mmseo-webp-converter' ),
					'stBackupFiles'  => __( 'Step 1/4: backing up image files…', 'mmseo-webp-converter' ),
					'stBackupDb'     => __( 'Step 1/4: backing up database tables…', 'mmseo-webp-converter' ),
					'stConvert'      => __( 'Step 2/4: converting and compressing to WebP…', 'mmseo-webp-converter' ),
					'stReplace'      => __( 'Step 3/4: updating image references…', 'mmseo-webp-converter' ),
					'stDelete'       => __( 'Step 4/4: deleting old files…', 'mmseo-webp-converter' ),
					'stRestoreFiles' => __( 'Restore 1/4: copying original files back…', 'mmseo-webp-converter' ),
					'stRestoreRecs'  => __( 'Restore 2/4: restoring image records…', 'mmseo-webp-converter' ),
					'stRestoreRefs'  => __( 'Restore 3/4: restoring image references…', 'mmseo-webp-converter' ),
					'stRestoreClean' => __( 'Restore 4/4: removing WebP files…', 'mmseo-webp-converter' ),
					'done'           => __( 'Done.', 'mmseo-webp-converter' ),
					'noBackups'      => __( 'No backups yet.', 'mmseo-webp-converter' ),
					'restore'        => __( 'Restore', 'mmseo-webp-converter' ),
					'delete'         => __( 'Delete backup', 'mmseo-webp-converter' ),
					'saved'          => __( 'Settings saved.', 'mmseo-webp-converter' ),
				),
			)
		);
	}

	/**
	 * Render the admin page.
	 *
	 * @return void
	 */
	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = $this->engine->settings();
		?>
		<div class="wrap mmseo-webp">
			<h1 class="mmseo-webp__title">
				<img src="<?php echo esc_url( MMSEO_WEBP_URL . 'assets/icon.svg' ); ?>" alt="" width="36" height="36" />
				<?php esc_html_e( 'MMSEO WebP Converter', 'mmseo-webp-converter' ); ?>
			</h1>
			<?php if ( ! $this->engine->webp_supported() ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'This server cannot create WebP images. GD or Imagick with WebP support is required.', 'mmseo-webp-converter' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Order of work: 1) Backup (image files and database tables), 2) Convert and compress to WebP, 3) Update every reference, 4) Delete the old files. If an image fails, its original is kept. You can restore from any backup below.', 'mmseo-webp-converter' ); ?></p>

			<table class="form-table" role="presentation"><tbody>
				<tr>
					<th scope="row"><label for="mmseo-quality"><?php esc_html_e( 'WebP quality (30–100)', 'mmseo-webp-converter' ); ?></label></th>
					<td><input type="number" id="mmseo-quality" min="30" max="100" value="<?php echo esc_attr( $s['quality'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Delete old files after success', 'mmseo-webp-converter' ); ?></th>
					<td><label><input type="checkbox" id="mmseo-delete" <?php checked( $s['delete'] ); ?> /> <?php esc_html_e( 'Yes (a full backup is always made first)', 'mmseo-webp-converter' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Convert new uploads automatically', 'mmseo-webp-converter' ); ?></th>
					<td><label><input type="checkbox" id="mmseo-auto" <?php checked( $s['auto'] ); ?> /> <?php esc_html_e( 'Yes', 'mmseo-webp-converter' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Redirect old image URLs (301)', 'mmseo-webp-converter' ); ?></th>
					<td><label><input type="checkbox" id="mmseo-redirect" <?php checked( $s['redirect'] ); ?> /> <?php esc_html_e( 'Yes', 'mmseo-webp-converter' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Delete backups when the plugin is deleted', 'mmseo-webp-converter' ); ?></th>
					<td><label><input type="checkbox" id="mmseo-purge" <?php checked( $s['purge'] ); ?> /> <?php esc_html_e( 'Yes (leave unticked to keep your backups)', 'mmseo-webp-converter' ); ?></label></td>
				</tr>
			</tbody></table>

			<p>
				<button type="button" class="button" id="mmseo-scan"><?php esc_html_e( 'Scan only', 'mmseo-webp-converter' ); ?></button>
				<button type="button" class="button" id="mmseo-save"><?php esc_html_e( 'Save settings', 'mmseo-webp-converter' ); ?></button>
				<button type="button" class="button button-primary" id="mmseo-go"><?php esc_html_e( 'Backup, then convert everything', 'mmseo-webp-converter' ); ?></button>
			</p>

			<div class="mmseo-webp__barwrap" role="progressbar" aria-valuemin="0" aria-valuemax="100"><div id="mmseo-bar" class="mmseo-webp__bar"></div></div>
			<p id="mmseo-phase" aria-live="polite"></p>
			<pre id="mmseo-log" class="mmseo-webp__log" aria-live="polite"></pre>

			<h2><?php esc_html_e( 'Backups and restore', 'mmseo-webp-converter' ); ?></h2>
			<table class="widefat striped mmseo-webp__runs" id="mmseo-runs">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Date', 'mmseo-webp-converter' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'mmseo-webp-converter' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Images converted', 'mmseo-webp-converter' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Space saved', 'mmseo-webp-converter' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'mmseo-webp-converter' ); ?></th>
				</tr></thead>
				<tbody></tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Sanitise the cursor sent by the browser (flat map of integers).
	 *
	 * @param string $raw JSON string.
	 * @return int[]
	 */
	private function read_cursor( $raw ) {
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$out = array();
		foreach ( $decoded as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$out[ sanitize_key( $key ) ] = absint( $value );
			}
		}
		return $out;
	}

	/**
	 * AJAX dispatcher.
	 *
	 * @return void
	 */
	public function ajax() {
		check_ajax_referer( 'mmseo_webp', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You are not allowed to do this.', 'mmseo-webp-converter' ), 403 );
		}
		wp_raise_memory_limit( 'admin' );

		$phase  = isset( $_POST['phase'] ) ? sanitize_key( wp_unslash( $_POST['phase'] ) ) : '';
		$run_id = isset( $_POST['run'] ) ? sanitize_text_field( wp_unslash( $_POST['run'] ) ) : '';
		$raw    = isset( $_POST['cursor'] ) ? sanitize_text_field( wp_unslash( $_POST['cursor'] ) ) : '{}';
		$cursor = $this->read_cursor( $raw );

		switch ( $phase ) {
			case 'scan':
				$out = $this->engine->scan();
				break;
			case 'save':
				$out = $this->save_from_request();
				break;
			case 'init':
				$this->save_from_request();
				$out = $this->engine->init_run();
				break;
			case 'backup_files':
				$out = $this->engine->backup_files( $run_id, $cursor );
				break;
			case 'backup_db':
				$out = $this->engine->backup_db( $run_id, $cursor );
				break;
			case 'convert':
				$out = $this->engine->convert( $run_id, $cursor );
				break;
			case 'replace':
				$out = $this->engine->replace( $run_id, $cursor, 'forward' );
				break;
			case 'finalize':
				$out = $this->engine->finalize( $run_id, $cursor );
				break;
			case 'summary':
				$out = $this->engine->summary( $run_id );
				break;
			case 'list':
				$out = array(
					'done' => true,
					'runs' => $this->engine->list_runs(),
				);
				break;
			case 'delete_run':
				$res = $this->engine->delete_run( $run_id );
				$out = is_wp_error( $res ) ? $res : array( 'done' => true );
				break;
			case 'restore_files':
				$out = $this->engine->restore_files( $run_id, $cursor );
				break;
			case 'restore_attachments':
				$out = $this->engine->restore_attachments( $run_id, $cursor );
				break;
			case 'restore_refs':
				$out = $this->engine->replace( $run_id, $cursor, 'reverse' );
				break;
			case 'restore_cleanup':
				$out = $this->engine->restore_cleanup( $run_id, $cursor );
				break;
			default:
				$out = new WP_Error( 'mmseo_phase', __( 'Unknown step.', 'mmseo-webp-converter' ) );
		}

		if ( is_wp_error( $out ) ) {
			wp_send_json_error( $out->get_error_message() );
		}
		wp_send_json_success( $out );
	}

	/**
	 * Save the settings form sent with the request.
	 *
	 * @return array
	 */
	private function save_from_request() {
		// Nonce is verified in ajax() before this method runs.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$input = array(
			'quality'  => isset( $_POST['quality'] ) ? absint( wp_unslash( $_POST['quality'] ) ) : 80,
			'delete'   => isset( $_POST['delete'] ) ? absint( wp_unslash( $_POST['delete'] ) ) : 0,
			'auto'     => isset( $_POST['auto'] ) ? absint( wp_unslash( $_POST['auto'] ) ) : 0,
			'redirect' => isset( $_POST['redirect'] ) ? absint( wp_unslash( $_POST['redirect'] ) ) : 0,
			'purge'    => isset( $_POST['purge'] ) ? absint( wp_unslash( $_POST['purge'] ) ) : 0,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->engine->save_settings( $input );
		return array(
			'done' => true,
			'msg'  => __( 'Settings saved.', 'mmseo-webp-converter' ),
		);
	}
}
