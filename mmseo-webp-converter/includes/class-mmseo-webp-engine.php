<?php
/**
 * Core engine: backup, conversion, reference updating and restore.
 *
 * @package MMSEO_WebP_Converter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Engine class. All heavy lifting happens here in small, resumable batches.
 */
final class MMSEO_WebP_Engine {

	/**
	 * Image MIME types this plugin converts.
	 *
	 * @var string[]
	 */
	const TYPES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/tiff' );

	/**
	 * Singleton instance.
	 *
	 * @var MMSEO_WebP_Engine|null
	 */
	private static $instance = null;

	/**
	 * Cached redirect map for the current request.
	 *
	 * @var array|null
	 */
	private $redirects = null;

	/**
	 * Get the singleton.
	 *
	 * @return MMSEO_WebP_Engine
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Register front-end and upload hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'wp_handle_upload', array( $this, 'convert_new_upload' ) );
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ) );
	}

	// ----- Settings -----

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public function defaults() {
		return array(
			'quality'  => 80,
			'delete'   => 1,
			'auto'     => 0,
			'redirect' => 1,
			'purge'    => 0,
		);
	}

	/**
	 * Current settings merged with defaults.
	 *
	 * @return array
	 */
	public function settings() {
		$saved = get_option( 'mmseo_webp_settings', array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $this->defaults() );
	}

	/**
	 * Validate and store settings.
	 *
	 * @param array $input Raw settings.
	 * @return array Stored settings.
	 */
	public function save_settings( array $input ) {
		$quality  = isset( $input['quality'] ) ? absint( $input['quality'] ) : 80;
		$settings = array(
			'quality'  => max( 30, min( 100, $quality ) ),
			'delete'   => empty( $input['delete'] ) ? 0 : 1,
			'auto'     => empty( $input['auto'] ) ? 0 : 1,
			'redirect' => empty( $input['redirect'] ) ? 0 : 1,
			'purge'    => empty( $input['purge'] ) ? 0 : 1,
		);
		update_option( 'mmseo_webp_settings', $settings, false );
		return $settings;
	}

	/**
	 * Whether the server can write WebP files.
	 *
	 * @return bool
	 */
	public function webp_supported() {
		return wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) || function_exists( 'imagewebp' );
	}

	// ----- Filesystem helpers -----

	/**
	 * Get an initialised WP_Filesystem instance.
	 *
	 * @return WP_Filesystem_Base|false
	 */
	private function fs() {
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		return $wp_filesystem ? $wp_filesystem : false;
	}

	/**
	 * Read a file.
	 *
	 * @param string $path Absolute path.
	 * @return string|false
	 */
	private function read( $path ) {
		$fs = $this->fs();
		if ( ! $fs || ! $fs->exists( $path ) ) {
			return false;
		}
		return $fs->get_contents( $path );
	}

	/**
	 * Read and decode a JSON file.
	 *
	 * @param string $path Absolute path.
	 * @return array
	 */
	private function read_json( $path ) {
		$raw = $this->read( $path );
		if ( false === $raw ) {
			return array();
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Write a file, creating parent folders.
	 *
	 * @param string $path Absolute path.
	 * @param string $data Contents.
	 * @return bool
	 */
	private function write( $path, $data ) {
		$fs = $this->fs();
		if ( ! $fs ) {
			return false;
		}
		wp_mkdir_p( dirname( $path ) );
		return (bool) $fs->put_contents( $path, $data, FS_CHMOD_FILE );
	}

	/**
	 * Encode and write JSON.
	 *
	 * @param string $path Absolute path.
	 * @param array  $data Data.
	 * @return bool
	 */
	private function write_json( $path, array $data ) {
		return $this->write( $path, wp_json_encode( $data ) );
	}

	/**
	 * Copy a file, creating parent folders.
	 *
	 * @param string $src Source.
	 * @param string $dst Destination.
	 * @return bool
	 */
	private function copy_file( $src, $dst ) {
		$fs = $this->fs();
		if ( ! $fs ) {
			return false;
		}
		wp_mkdir_p( dirname( $dst ) );
		return (bool) $fs->copy( $src, $dst, true, FS_CHMOD_FILE );
	}

	/**
	 * Absolute uploads base directory without trailing slash.
	 *
	 * @return string
	 */
	private function upload_base() {
		$u = wp_get_upload_dir();
		return untrailingslashit( wp_normalize_path( $u['basedir'] ) );
	}

	/**
	 * Uploads base URL without trailing slash.
	 *
	 * @return string
	 */
	private function upload_url() {
		$u = wp_get_upload_dir();
		return untrailingslashit( $u['baseurl'] );
	}

	/**
	 * Path relative to the uploads directory, or false if outside it.
	 *
	 * @param string $abs Absolute path.
	 * @return string|false
	 */
	private function rel( $abs ) {
		$abs  = wp_normalize_path( $abs );
		$base = $this->upload_base() . '/';
		if ( 0 !== strpos( $abs, $base ) ) {
			return false;
		}
		return substr( $abs, strlen( $base ) );
	}

	/**
	 * Folder prefix of a relative path ('' or 'a/b/').
	 *
	 * @param string $rel Relative path.
	 * @return string
	 */
	private function dir_prefix( $rel ) {
		$dir = dirname( $rel );
		return ( '.' === $dir || '' === $dir ) ? '' : $dir . '/';
	}

	// ----- Backup storage and runs -----

	/**
	 * Root folder that holds every backup run (unguessable name).
	 *
	 * @return string
	 */
	public function backup_root() {
		$key = get_option( 'mmseo_webp_dirkey' );
		if ( ! $key ) {
			$key = strtolower( wp_generate_password( 12, false, false ) );
			update_option( 'mmseo_webp_dirkey', $key, false );
		}
		return $this->upload_base() . '/mmseo-webp-backup-' . $key;
	}

	/**
	 * Create the backup root and protect it.
	 *
	 * @return bool
	 */
	private function ensure_root() {
		$root = $this->backup_root();
		if ( is_dir( $root ) ) {
			return true;
		}
		if ( ! wp_mkdir_p( $root ) ) {
			return false;
		}
		$this->write( $root . '/index.php', "<?php\n// Silence is golden.\n" );
		$this->write( $root . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		$this->write( $root . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n" );
		return true;
	}

	/**
	 * Validate a run id and return its directory.
	 *
	 * @param string $id Run id.
	 * @return string|false
	 */
	private function run_dir( $id ) {
		if ( ! is_string( $id ) || ! preg_match( '/^run-\d{8}-\d{6}(-\d)?$/', $id ) ) {
			return false;
		}
		$dir = $this->backup_root() . '/' . $id;
		return is_dir( $dir ) ? $dir : false;
	}

	/**
	 * Load a run record.
	 *
	 * @param string $id Run id.
	 * @return array|false
	 */
	public function get_run( $id ) {
		$dir = $this->run_dir( $id );
		if ( ! $dir ) {
			return false;
		}
		$run = $this->read_json( $dir . '/run.json' );
		return $run ? $run : false;
	}

	/**
	 * Persist a run record.
	 *
	 * @param array $run Run record.
	 * @return void
	 */
	private function save_run( array $run ) {
		$dir = $this->run_dir( $run['id'] );
		if ( $dir ) {
			$this->write_json( $dir . '/run.json', $run );
		}
	}

	/**
	 * Human label for a run status.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	private function status_label( $status ) {
		$labels = array(
			'backup'    => __( 'Backup incomplete', 'mmseo-webp-converter' ),
			'backed_up' => __( 'Backed up', 'mmseo-webp-converter' ),
			'converted' => __( 'Converted (links not updated yet)', 'mmseo-webp-converter' ),
			'linked'    => __( 'Converted (old files not removed)', 'mmseo-webp-converter' ),
			'done'      => __( 'Completed', 'mmseo-webp-converter' ),
			'restored'  => __( 'Restored', 'mmseo-webp-converter' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * List all backup runs, newest first.
	 *
	 * @return array[]
	 */
	public function list_runs() {
		$fs   = $this->fs();
		$root = $this->backup_root();
		$out  = array();
		if ( ! $fs || ! is_dir( $root ) ) {
			return $out;
		}
		$entries = $fs->dirlist( $root, false );
		if ( ! is_array( $entries ) ) {
			return $out;
		}
		foreach ( array_keys( $entries ) as $name ) {
			$run = $this->get_run( (string) $name );
			if ( ! $run ) {
				continue;
			}
			$out[] = array(
				'id'        => $run['id'],
				'date'      => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $run['created'] ),
				'status'    => $run['status'],
				'label'     => $this->status_label( $run['status'] ),
				'converted' => (int) $run['stats']['converted'],
				'saved'     => size_format( (int) $run['stats']['saved'] ),
				'restore'   => 'restored' !== $run['status'],
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $b['id'], $a['id'] );
			}
		);
		return $out;
	}

	/**
	 * Delete a backup run from disk.
	 *
	 * @param string $id Run id.
	 * @return true|WP_Error
	 */
	public function delete_run( $id ) {
		$dir = $this->run_dir( $id );
		$fs  = $this->fs();
		if ( ! $dir || ! $fs ) {
			return new WP_Error( 'mmseo_run', __( 'Backup not found.', 'mmseo-webp-converter' ) );
		}
		$fs->delete( $dir, true );
		return true;
	}

	// ----- Database helpers -----

	/**
	 * Count convertible attachments.
	 *
	 * @return int
	 */
	private function count_convertible() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png','image/gif','image/bmp','image/tiff')", $wpdb->posts ) );
	}

	/**
	 * Next batch of convertible attachment IDs after $last.
	 *
	 * @param int $last  Last processed ID.
	 * @param int $limit Batch size.
	 * @return int[]
	 */
	private function ids_after( $last, $limit ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png','image/gif','image/bmp','image/tiff') AND ID > %d ORDER BY ID ASC LIMIT %d", $wpdb->posts, $last, $limit ) );
		return array_map( 'absint', $ids );
	}

	/**
	 * Scan the library and report counts per type.
	 *
	 * @return array
	 */
	public function scan() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT post_mime_type AS t, COUNT(*) AS c FROM %i WHERE post_type = 'attachment' AND post_mime_type LIKE %s GROUP BY post_mime_type ORDER BY c DESC", $wpdb->posts, 'image/%' ) );
		$lines = array();
		$total = 0;
		foreach ( (array) $rows as $row ) {
			$total  += (int) $row->c;
			$lines[] = '  ' . $row->t . ': ' . (int) $row->c;
		}
		$msg = sprintf(
			/* translators: 1: number of images, 2: number of image types */
			__( 'Images in the Media Library: %1$d (%2$d types)', 'mmseo-webp-converter' ),
			$total,
			count( (array) $rows )
		);
		$msg .= "\n" . implode( "\n", $lines );
		$msg .= "\n" . ( $this->webp_supported() ? __( 'Server WebP support: yes', 'mmseo-webp-converter' ) : __( 'Server WebP support: NO', 'mmseo-webp-converter' ) );
		return array(
			'done'   => true,
			'cursor' => array(),
			'msg'    => $msg,
		);
	}

	/**
	 * Relative paths of every file belonging to an attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return string[]
	 */
	private function files_of( $id ) {
		$out  = array();
		$file = get_attached_file( $id );
		if ( ! $file ) {
			return $out;
		}
		$rel = $this->rel( $file );
		if ( false === $rel ) {
			return $out;
		}
		$out[]  = $rel;
		$prefix = $this->dir_prefix( $rel );
		$meta   = wp_get_attachment_metadata( $id );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$out[] = $prefix . $size['file'];
				}
			}
		}
		if ( ! empty( $meta['original_image'] ) ) {
			$out[] = $prefix . $meta['original_image'];
		}
		return array_values( array_unique( $out ) );
	}

	// ----- Phase: init -----

	/**
	 * Start a new run: create the backup folder and record.
	 *
	 * @return array|WP_Error
	 */
	public function init_run() {
		if ( ! $this->webp_supported() ) {
			return new WP_Error( 'mmseo_webp', __( 'This server cannot create WebP images (GD or Imagick with WebP support is required).', 'mmseo-webp-converter' ) );
		}
		if ( ! $this->ensure_root() ) {
			return new WP_Error( 'mmseo_dir', __( 'Could not create the backup folder. Check that the uploads folder is writable.', 'mmseo-webp-converter' ) );
		}
		$settings = $this->settings();
		$id       = 'run-' . gmdate( 'Ymd-His' );
		$suffix   = 0;
		while ( is_dir( $this->backup_root() . '/' . $id ) && $suffix < 9 ) {
			++$suffix;
			$id = 'run-' . gmdate( 'Ymd-His' ) . '-' . $suffix;
		}
		$dir = $this->backup_root() . '/' . $id;
		if ( ! wp_mkdir_p( $dir . '/uploads' ) ) {
			return new WP_Error( 'mmseo_dir', __( 'Could not create the backup folder.', 'mmseo-webp-converter' ) );
		}
		$total = $this->count_convertible();
		$run   = array(
			'id'       => $id,
			'created'  => time(),
			'status'   => 'backup',
			'quality'  => (int) $settings['quality'],
			'delete'   => (int) $settings['delete'],
			'total'    => $total,
			'stats'    => array(
				'converted' => 0,
				'skipped'   => 0,
				'errors'    => 0,
				'saved'     => 0,
				'deleted'   => 0,
			),
			'restored' => 0,
		);
		$this->save_run( $run );
		$this->write_json( $dir . '/map.json', array() );
		$this->write_json( $dir . '/reverse.json', array() );
		return array(
			'done'   => true,
			'cursor' => array(),
			'run'    => $id,
			'total'  => $total,
			'msg'    => sprintf(
				/* translators: 1: backup folder name, 2: number of images */
				__( 'Backup folder: %1$s — images to process: %2$d', 'mmseo-webp-converter' ),
				$id,
				$total
			),
		);
	}

	// ----- Phase: backup files + database -----

	/**
	 * Back up original image files and attachment metadata.
	 *
	 * @param string $run_id Run id.
	 * @param array  $cursor Cursor.
	 * @return array|WP_Error
	 */
	public function backup_files( $run_id, array $cursor ) {
		$run = $this->get_run( $run_id );
		$dir = $this->run_dir( $run_id );
		if ( ! $run || ! $dir ) {
			return new WP_Error( 'mmseo_run', __( 'Backup not found.', 'mmseo-webp-converter' ) );
		}
		$last = isset( $cursor['last'] ) ? absint( $cursor['last'] ) : 0;
		$ids  = $this->ids_after( $last, 25 );
		$snap = array();
		foreach ( $ids as $id ) {
			$last  = $id;
			$files = $this->files_of( $id );
			foreach ( $files as $rel ) {
				$src = $this->upload_base() . '/' . $rel;
				if ( ! file_exists( $src ) ) {
					continue;
				}
				$dst = $dir . '/uploads/' . $rel;
				if ( ! file_exists( $dst ) && ! $this->copy_file( $src, $dst ) ) {
					return new WP_Error( 'mmseo_copy', __( 'Could not copy a file into the backup folder. Nothing has been changed.', 'mmseo-webp-converter' ) );
				}
			}
			$snap[] = array(
				'id'    => $id,
				'file'  => get_post_meta( $id, '_wp_attached_file', true ),
				'mime'  => get_post_mime_type( $id ),
				'guid'  => get_post_field( 'guid', $id ),
				'meta'  => wp_get_attachment_metadata( $id ),
				'files' => $files,
			);
		}
		if ( $snap ) {
			$this->write_json( $dir . '/' . sprintf( 'snap-%010d.json', $last ), $snap );
		}
		$done = empty( $ids );
		return array(
			'done'   => $done,
			'cursor' => array( 'last' => $last ),
			'n'      => count( $ids ),
			'msg'    => $done ? __( 'Image files backed up.', 'mmseo-webp-converter' ) : '',
		);
	}

	/**
	 * Table definitions used for backup and reference replacement.
	 *
	 * @return array[] Each: table name, primary key column.
	 */
	private function tables() {
		global $wpdb;
		return array(
			array( $wpdb->posts, 'ID' ),
			array( $wpdb->postmeta, 'meta_id' ),
			array( $wpdb->options, 'option_id' ),
			array( $wpdb->termmeta, 'meta_id' ),
		);
	}

	/**
	 * Back up the database tables that may hold image references.
	 *
	 * @param string $run_id Run id.
	 * @param array  $cursor Cursor.
	 * @return array|WP_Error
	 */
	public function backup_db( $run_id, array $cursor ) {
		global $wpdb;
		$run = $this->get_run( $run_id );
		$dir = $this->run_dir( $run_id );
		if ( ! $run || ! $dir ) {
			return new WP_Error( 'mmseo_run', __( 'Backup not found.', 'mmseo-webp-converter' ) );
		}
		$tables = $this->tables();
		$t      = isset( $cursor['t'] ) ? absint( $cursor['t'] ) : 0;
		$last   = isset( $cursor['last'] ) ? absint( $cursor['last'] ) : 0;
		if ( $t >= count( $tables ) ) {
			$run['status'] = 'backed_up';
			$this->save_run( $run );
			return array(
				'done'   => true,
				'cursor' => $cursor,
				'msg'    => __( 'Database tables backed up. Backup complete.', 'mmseo-webp-converter' ),
			);
		}
		list( $table, $pk ) = $tables[ $t ];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE %i > %d ORDER BY %i ASC LIMIT 300', $table, $pk, $last, $pk ), ARRAY_A );
		if ( ! $rows ) {
			return array(
				'done'   => false,
				'cursor' => array(
					't'    => $t + 1,
					'last' => 0,
				),
				'msg'    => '',
			);
		}
		$sql = '';
		foreach ( $rows as $row ) {
			$vals = array();
			foreach ( $row as $v ) {
				$vals[] = ( null === $v ) ? 'NULL' : "'" . $wpdb->remove_placeholder_escape( esc_sql( $v ) ) . "'";
			}
			$sql .= 'REPLACE INTO `' . $table . '` VALUES (' . implode( ',', $vals ) . ");\n";
			$last = (int) $row[ $pk ];
		}
		$this->write( $dir . '/' . sprintf( 'db-%d-%010d.sql', $t, $last ), $sql );
		return array(
			'done'   => false,
			'cursor' => array(
				't'    => $t,
				'last' => $last,
			),
			'msg'    => '',
		);
	}

	// ----- Phase: convert -----

	/**
	 * Detect animated GIFs by walking the GIF block structure and counting frames.
	 *
	 * @param string $file Absolute path.
	 * @return bool True when the file holds more than one frame.
	 */
	public function is_animated_gif( $file ) {
		$data = $this->read( $file );
		if ( false === $data || strlen( $data ) < 14 || 'GIF' !== substr( $data, 0, 3 ) ) {
			return false;
		}
		$len    = strlen( $data );
		$pos    = 13;
		$frames = 0;
		if ( ord( $data[10] ) & 0x80 ) {
			$pos += 3 * ( 1 << ( ( ord( $data[10] ) & 0x07 ) + 1 ) );
		}
		while ( $pos < $len ) {
			$block = ord( $data[ $pos ] );
			if ( 0x21 === $block ) {
				$pos = $this->skip_gif_sub_blocks( $data, $pos + 2, $len );
			} elseif ( 0x2C === $block && isset( $data[ $pos + 9 ] ) ) {
				++$frames;
				if ( $frames > 1 ) {
					return true;
				}
				$local = ord( $data[ $pos + 9 ] );
				$pos  += 10;
				if ( $local & 0x80 ) {
					$pos += 3 * ( 1 << ( ( $local & 0x07 ) + 1 ) );
				}
				$pos = $this->skip_gif_sub_blocks( $data, $pos + 1, $len );
			} else {
				break;
			}
		}
		return false;
	}

	/**
	 * Skip a run of GIF data sub-blocks and return the position after the terminator.
	 *
	 * @param string $data GIF bytes.
	 * @param int    $pos  Start position.
	 * @param int    $len  Data length.
	 * @return int
	 */
	private function skip_gif_sub_blocks( $data, $pos, $len ) {
		while ( $pos < $len ) {
			$size = ord( $data[ $pos ] );
			++$pos;
			if ( 0 === $size ) {
				break;
			}
			$pos += $size;
		}
		return $pos;
	}

	/**
	 * Save a WebP copy, using the core editor first and GD as a fallback.
	 *
	 * @param string $src     Source file.
	 * @param string $dst     Destination file.
	 * @param int    $quality 1-100.
	 * @return true|string True on success, otherwise an error message.
	 */
	private function save_webp( $src, $dst, $quality ) {
		$editor = wp_get_image_editor( $src );
		if ( ! is_wp_error( $editor ) ) {
			$editor->set_quality( $quality );
			$result = $editor->save( $dst, 'image/webp' );
			if ( ! is_wp_error( $result ) && file_exists( $dst ) && filesize( $dst ) > 0 ) {
				return true;
			}
			wp_delete_file( $dst );
		}
		return $this->gd_webp( $src, $dst, $quality );
	}

	/**
	 * GD fallback for palette GIF/PNG and BMP files the core editor cannot write as WebP.
	 *
	 * @param string $src     Source file.
	 * @param string $dst     Destination file.
	 * @param int    $quality 1-100.
	 * @return true|string
	 */
	private function gd_webp( $src, $dst, $quality ) {
		if ( ! function_exists( 'imagewebp' ) ) {
			return __( 'The server has no WebP support.', 'mmseo-webp-converter' );
		}
		$info  = wp_getimagesize( $src );
		$mime  = $info ? $info['mime'] : '';
		$image = false;
		if ( 'image/bmp' === $mime && function_exists( 'imagecreatefrombmp' ) ) {
			$image = imagecreatefrombmp( $src );
		}
		if ( ! $image ) {
			$data  = $this->read( $src );
			$image = $data ? imagecreatefromstring( $data ) : false;
		}
		if ( ! $image ) {
			return __( 'This image format cannot be read by the server.', 'mmseo-webp-converter' );
		}
		if ( function_exists( 'imagepalettetotruecolor' ) ) {
			imagepalettetotruecolor( $image );
		}
		imagealphablending( $image, false );
		imagesavealpha( $image, true );
		$ok = imagewebp( $image, $dst, $quality );
		unset( $image );
		return ( $ok && file_exists( $dst ) && filesize( $dst ) > 0 ) ? true : __( 'The WebP file could not be written.', 'mmseo-webp-converter' );
	}

	/**
	 * Convert one attachment.
	 *
	 * @param int   $id      Attachment ID.
	 * @param int   $quality Quality.
	 * @param array $map     Forward map (old rel => new rel), by reference.
	 * @param array $reverse Reverse map (new rel => old rel), by reference.
	 * @param array $created Every file this plugin created (relative paths), by reference.
	 * @return array Result with a status key.
	 */
	private function convert_one( $id, $quality, array &$map, array &$reverse, array &$created ) {
		global $wpdb;
		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return array(
				'status' => 'error',
				'why'    => __( 'file missing', 'mmseo-webp-converter' ),
			);
		}
		$old_rel = $this->rel( $file );
		if ( false === $old_rel ) {
			return array(
				'status' => 'error',
				'why'    => __( 'file is outside the uploads folder', 'mmseo-webp-converter' ),
			);
		}
		$mime = get_post_mime_type( $id );
		if ( 'image/gif' === $mime && $this->is_animated_gif( $file ) ) {
			return array(
				'status' => 'skipped',
				'why'    => __( 'animated GIF', 'mmseo-webp-converter' ),
			);
		}
		$old_meta  = wp_get_attachment_metadata( $id );
		$old_meta  = is_array( $old_meta ) ? $old_meta : array();
		$old_files = $this->files_of( $id );
		$old_size  = filesize( $file );
		$old_info  = wp_getimagesize( $file );
		$dir       = dirname( $file );
		$base      = pathinfo( $file, PATHINFO_FILENAME );
		$new       = $dir . '/' . $base . '.webp';
		if ( file_exists( $new ) ) {
			$new = $dir . '/' . wp_unique_filename( $dir, $base . '.webp' );
		}
		$saved = $this->save_webp( $file, $new, $quality );
		if ( true !== $saved ) {
			wp_delete_file( $new );
			return array(
				'status' => 'error',
				'why'    => $saved,
			);
		}
		$new_info = wp_getimagesize( $new );
		if ( ! $new_info || 'image/webp' !== $new_info['mime'] || ( $old_info && ( $new_info[0] !== $old_info[0] || $new_info[1] !== $old_info[1] ) ) ) {
			wp_delete_file( $new );
			return array(
				'status' => 'error',
				'why'    => __( 'the new file failed verification', 'mmseo-webp-converter' ),
			);
		}
		if ( filesize( $new ) >= $old_size && in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif' ), true ) ) {
			wp_delete_file( $new );
			return array(
				'status' => 'skipped',
				'why'    => __( 'WebP would not be smaller', 'mmseo-webp-converter' ),
			);
		}
		$new_rel = $this->rel( $new );
		update_attached_file( $id, $new );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_mime_type' => 'image/webp',
				'guid'           => $this->upload_url() . '/' . $new_rel,
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );
		$new_meta = wp_generate_attachment_metadata( $id, $new );
		if ( ! is_array( $new_meta ) ) {
			$new_meta = array();
		}
		foreach ( $old_meta as $key => $value ) {
			if ( ! isset( $new_meta[ $key ] ) && 'original_image' !== $key ) {
				$new_meta[ $key ] = $value;
			}
		}
		if ( ! empty( $old_meta['image_meta'] ) ) {
			$new_meta['image_meta'] = $old_meta['image_meta'];
		}
		wp_update_attachment_metadata( $id, $new_meta );

		$old_prefix = $this->dir_prefix( $old_rel );
		$new_prefix = $this->dir_prefix( $new_rel );
		foreach ( $old_files as $old_file ) {
			$map[ $old_file ] = $new_rel;
		}
		$reverse[ $new_rel ] = $old_rel;
		$created[]           = $new_rel;
		if ( ! empty( $new_meta['sizes'] ) ) {
			foreach ( $new_meta['sizes'] as $new_size_data ) {
				if ( ! empty( $new_size_data['file'] ) ) {
					$created[] = $new_prefix . $new_size_data['file'];
				}
			}
		}
		if ( ! empty( $old_meta['sizes'] ) && ! empty( $new_meta['sizes'] ) ) {
			foreach ( $old_meta['sizes'] as $size_name => $old_size_data ) {
				if ( ! empty( $old_size_data['file'] ) && ! empty( $new_meta['sizes'][ $size_name ]['file'] ) ) {
					$new_file             = $new_prefix . $new_meta['sizes'][ $size_name ]['file'];
					$old_file             = $old_prefix . $old_size_data['file'];
					$map[ $old_file ]     = $new_file;
					$reverse[ $new_file ] = $old_file;
				}
			}
		}
		return array(
			'status' => 'converted',
			'saved'  => max( 0, $old_size - filesize( $new ) ),
		);
	}

	/**
	 * Convert a batch of attachments.
	 *
	 * @param string $run_id Run id.
	 * @param array  $cursor Cursor.
	 * @return array|WP_Error
	 */
	public function convert( $run_id, array $cursor ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$run = $this->get_run( $run_id );
		$dir = $this->run_dir( $run_id );
		if ( ! $run || ! $dir ) {
			return new WP_Error( 'mmseo_run', __( 'Backup not found.', 'mmseo-webp-converter' ) );
		}
		if ( ! in_array( $run['status'], array( 'backed_up', 'converted' ), true ) ) {
			return new WP_Error( 'mmseo_order', __( 'The backup must finish before converting.', 'mmseo-webp-converter' ) );
		}
		$last    = isset( $cursor['last'] ) ? absint( $cursor['last'] ) : 0;
		$ids     = $this->ids_after( $last, 5 );
		$map     = $this->read_json( $dir . '/map.json' );
		$reverse = $this->read_json( $dir . '/reverse.json' );
		$created = $this->read_json( $dir . '/created.json' );
		$lines   = array();
		foreach ( $ids as $id ) {
			$last = $id;
			$res  = $this->convert_one( $id, (int) $run['quality'], $map, $reverse, $created );
			if ( 'converted' === $res['status'] ) {
				++$run['stats']['converted'];
				$run['stats']['saved'] += $res['saved'];
			} elseif ( 'error' === $res['status'] ) {
				++$run['stats']['errors'];
				/* translators: 1: attachment ID, 2: reason */
				$lines[] = sprintf( __( '#%1$d not converted (original kept): %2$s', 'mmseo-webp-converter' ), $id, $res['why'] );
			} else {
				++$run['stats']['skipped'];
				/* translators: 1: attachment ID, 2: reason */
				$lines[] = sprintf( __( '#%1$d skipped: %2$s', 'mmseo-webp-converter' ), $id, $res['why'] );
			}
		}
		$this->write_json( $dir . '/map.json', $map );
		$this->write_json( $dir . '/reverse.json', $reverse );
		$this->write_json( $dir . '/created.json', array_values( array_unique( $created ) ) );
		$done = empty( $ids );
		if ( $done ) {
			$run['status'] = 'converted';
			$this->merge_redirects( $map );
			$lines[] = __( 'Conversion finished.', 'mmseo-webp-converter' );
		}
		$this->save_run( $run );
		return array(
			'done'   => $done,
			'cursor' => array( 'last' => $last ),
			'n'      => count( $ids ),
			'msg'    => implode( "\n", $lines ),
		);
	}

	// ----- Phase: replace references (forward and reverse) -----

	/**
	 * URL-encode each segment of a relative path.
	 *
	 * @param string $path Relative path.
	 * @return string
	 */
	private function encode_path( $path ) {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	}

	/**
	 * Build the strtr() pair list from a path map.
	 *
	 * @param array $map From => to relative paths.
	 * @return array
	 */
	private function build_pairs( array $map ) {
		$pairs = array();
		foreach ( $map as $from => $to ) {
			if ( $from === $to ) {
				continue;
			}
			$variants = array(
				array( $from, $to ),
				array( $this->encode_path( $from ), $this->encode_path( $to ) ),
			);
			foreach ( $variants as $v ) {
				$pairs[ '/' . $v[0] ]                              = '/' . $v[1];
				$pairs[ '\\/' . str_replace( '/', '\\/', $v[0] ) ] = '\\/' . str_replace( '/', '\\/', $v[1] );
			}
		}
		return $pairs;
	}

	/**
	 * Recursively replace inside scalars, arrays, objects and nested serialized data.
	 *
	 * @param mixed $value Value.
	 * @param array $pairs strtr pairs.
	 * @return mixed
	 */
	private function deep_replace( $value, array $pairs ) {
		if ( is_string( $value ) ) {
			if ( is_serialized( $value ) ) {
				$inner = maybe_unserialize( $value );
				if ( $inner !== $value ) {
					return maybe_serialize( $this->deep_replace( $inner, $pairs ) );
				}
			}
			return strtr( $value, $pairs );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = $this->deep_replace( $v, $pairs );
			}
			return $value;
		}
		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $k => $v ) {
				$value->$k = $this->deep_replace( $v, $pairs );
			}
			return $value;
		}
		return $value;
	}

	/**
	 * Fetch the next batch of rows for one table (only columns that may hold URLs).
	 *
	 * @param int $t    Table index.
	 * @param int $last Last primary key seen.
	 * @return array Rows, table, primary key, columns.
	 */
	private function fetch_rows( $t, $last ) {
		global $wpdb;
		$rows = array();
		$cols = array();
		switch ( $t ) {
			case 0:
				$table = $wpdb->posts;
				$pk    = 'ID';
				$cols  = array( 'post_content', 'post_excerpt' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ID, post_content, post_excerpt FROM %i WHERE ID > %d ORDER BY ID ASC LIMIT 100', $table, $last ), ARRAY_A );
				break;
			case 1:
				$table = $wpdb->postmeta;
				$pk    = 'meta_id';
				$cols  = array( 'meta_value' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT meta_id, meta_value FROM %i WHERE meta_id > %d AND meta_key NOT IN (%s, %s, %s) ORDER BY meta_id ASC LIMIT 100', $table, $last, '_wp_attachment_metadata', '_wp_attached_file', '_wp_attachment_backup_sizes' ), ARRAY_A );
				break;
			case 2:
				$table = $wpdb->options;
				$pk    = 'option_id';
				$cols  = array( 'option_value' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_id, option_value FROM %i WHERE option_id > %d AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s ORDER BY option_id ASC LIMIT 100', $table, $last, $wpdb->esc_like( 'mmseo_webp_' ) . '%', $wpdb->esc_like( '_transient' ) . '%', $wpdb->esc_like( '_site_transient' ) . '%' ), ARRAY_A );
				break;
			default:
				$table = $wpdb->termmeta;
				$pk    = 'meta_id';
				$cols  = array( 'meta_value' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT meta_id, meta_value FROM %i WHERE meta_id > %d ORDER BY meta_id ASC LIMIT 100', $table, $last ), ARRAY_A );
				break;
		}
		return array( $rows, $table, $pk, $cols );
	}

	/**
	 * Replace old paths with new paths (or the reverse) in the database.
	 *
	 * @param string $run_id    Run id.
	 * @param array  $cursor    Cursor.
	 * @param string $direction 'forward' or 'reverse'.
	 * @return array|WP_Error
	 */
	public function replace( $run_id, array $cursor, $direction = 'forward' ) {
		global $wpdb;
		$run = $this->get_run( $run_id );
		$dir = $this->run_dir( $run_id );
		if ( ! $run || ! $dir ) {
			return new WP_Error( 'mmseo_run', __( 'Backup not found.', 'mmseo-webp-converter' ) );
		}
		if ( 'forward' === $direction && ! in_array( $run['status'], array( 'converted', 'linked' ), true ) ) {
			return new WP_Error( 'mmseo_order', __( 'Conversion must finish before links are updated.', 'mmseo-webp-converter' ) );
		}
		$pairs = $this->build_pairs( $this->read_json( $dir . ( 'forward' === $direction ? '/map.json' : '/reverse.json' ) ) );
		$t     = isset( $cursor['t'] ) ? absint( $cursor['t'] ) : 0;
		$last  = isset( $cursor['last'] ) ? absint( $cursor['last'] ) : 0;
		$count = isset( $cursor['changed'] ) ? absint( $cursor['changed'] ) : 0;
		if ( ! $pairs || $t > 3 ) {
			if ( 'forward' === $direction && 'converted' === $run['status'] ) {
				$run['status'] = 'linked';
				$this->save_run( $run );
			}
			return array(
				'done'   => true,
				'cursor' => $cursor,
				'msg'    => sprintf(
					/* translators: %d: number of database rows changed */
					__( 'Image references updated (%d database rows changed).', 'mmseo-webp-converter' ),
					$count
				),
			);
		}
		list( $rows, $table, $pk, $cols ) = $this->fetch_rows( $t, $last );
		if ( ! $rows ) {
			return array(
				'done'   => false,
				'cursor' => array(
					't'       => $t + 1,
					'last'    => 0,
					'changed' => $count,
				),
				'msg'    => '',
			);
		}
		foreach ( $rows as $row ) {
			$last   = (int) $row[ $pk ];
			$update = array();
			foreach ( $cols as $col ) {
				$value = $row[ $col ];
				if ( null === $value || '' === $value ) {
					continue;
				}
				if ( strtr( $value, $pairs ) === $value && ! is_serialized( $value ) ) {
					continue;
				}
				$new = $this->deep_replace( $value, $pairs );
				if ( $new !== $value ) {
					$update[ $col ] = $new;
				}
			}
			if ( $update ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( $table, $update, array( $pk => $row[ $pk ] ) );
				++$count;
			}
		}
		return array(
			'done'   => false,
			'cursor' => array(
				't'       => $t,
				'last'    => $last,
				'changed' => $count,
			),
			'msg'    => '',
		);
	}

	// ----- Phase: finalize (delete old files) + summary -----

	/**
	 * Delete the old files that have a verified WebP replacement.
	 *
	 * @param string $run_id Run id.
	 * @param array  $cursor Cursor.
	 * @return array|WP_Error
	 */
	public function finalize( $run_id, array $cursor ) {
		$run = $this->get_run( $run_id );
		$dir = $this->run_dir( $run_id );
		if ( ! $run || ! $dir ) {
			return new WP_Error( 'mmseo_run', __( 'Backup not found.', 'mmseo-webp-converter' ) );
		}
		if ( 'linked' !== $run['status'] ) {
			return new WP_Error( 'mmseo_order', __( 'References must be updated before old files are removed.', 'mmseo-webp-converter' ) );
		}
		$map   = $this->read_json( $dir . '/map.json' );
		$keys  = array_keys( $map );
		$off   = isset( $cursor['off'] ) ? absint( $cursor['off'] ) : 0;
		$slice = array_slice( $keys, $off, 200 );
		$base  = $this->upload_base() . '/';
		foreach ( $slice as $old ) {
			$new = $map[ $old ];
			if ( (string) $old === (string) $new ) {
				continue;
			}
			if ( file_exists( $base . $old ) && file_exists( $base . $new ) ) {
				wp_delete_file( $base . $old );
				if ( ! file_exists( $base . $old ) ) {
					++$run['stats']['deleted'];
				}
			}
		}
		$done = empty( $slice );
		if ( $done ) {
			$run['status'] = 'done';
		}
		$this->save_run( $run );
		return array(
			'done'   => $done,
			'cursor' => array( 'off' => $off + 200 ),
			'msg'    => $done ? sprintf(
				/* translators: %d: number of old files deleted */
				__( 'Old files deleted: %d', 'mmseo-webp-converter' ),
				$run['stats']['deleted']
			) : '',
		);
	}

	/**
	 * Final summary and cache clearing.
	 *
	 * @param string $run_id Run id.
	 * @return array|WP_Error
	 */
	public function summary( $run_id ) {
		$run = $this->get_run( $run_id );
		if ( ! $run ) {
			return new WP_Error( 'mmseo_run', __( 'Backup not found.', 'mmseo-webp-converter' ) );
		}
		if ( 'linked' === $run['status'] && empty( $run['delete'] ) ) {
			$run['status'] = 'done';
			$this->save_run( $run );
		}
		$this->clear_caches( $run_id );
		$st = $run['stats'];
		return array(
			'done'   => true,
			'cursor' => array(),
			'msg'    => sprintf(
				/* translators: 1: converted, 2: skipped, 3: errors, 4: space saved, 5: old files deleted */
				__( 'SUMMARY — converted: %1$d, skipped: %2$d, errors: %3$d, space saved: %4$s, old files deleted: %5$d. A backup is kept so you can restore at any time.', 'mmseo-webp-converter' ),
				$st['converted'],
				$st['skipped'],
				$st['errors'],
				size_format( $st['saved'] ),
				$st['deleted']
			),
		);
	}

	/**
	 * Clear object and page-builder caches after a run.
	 *
	 * @param string $run_id Run id.
	 * @return void
	 */
	private function clear_caches( $run_id ) {
		wp_cache_flush();
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		/**
		 * Fires after a conversion or restore run has finished.
		 *
		 * @param string $run_id Run id.
		 */
		do_action( 'mmseo_webp_run_finished', $run_id );
	}

	// ----- Restore -----

	/**
	 * Snapshot part file names for a run, sorted.
	 *
	 * @param string $dir Run directory.
	 * @return string[]
	 */
	private function snapshot_parts( $dir ) {
		$fs    = $this->fs();
		$parts = array();
		$list  = $fs ? $fs->dirlist( $dir, false ) : array();
		foreach ( array_keys( (array) $list ) as $name ) {
			if ( preg_match( '/^snap-\d+\.json$/', (string) $name ) ) {
				$parts[] = (string) $name;
			}
		}
		sort( $parts );
		return $parts;
	}

	/**
	 * Restore step 1: copy the original files back from the backup.
	 *
	 * @param string $run_id Run id.
	 * @param array  $cursor Cursor.
	 * @return array|WP_Error
	 */
	public function restore_files( $run_id, array $cursor ) {
		$run = $this->get_run( $run_id );
		$dir = $this->run_dir( $run_id );
		if ( ! $run || ! $dir ) {
			return new WP_Error( 'mmseo_run', __( 'Backup not found.', 'mmseo-webp-converter' ) );
		}
		if ( 'restored' === $run['status'] ) {
			return new WP_Error( 'mmseo_order', __( 'This backup has already been restored.', 'mmseo-webp-converter' ) );
		}
		$parts = $this->snapshot_parts( $dir );
		$i     = isset( $cursor['i'] ) ? absint( $cursor['i'] ) : 0;
		if ( ! isset( $parts[ $i ] ) ) {
			return array(
				'done'   => true,
				'cursor' => $cursor,
				'msg'    => __( 'Original files restored.', 'mmseo-webp-converter' ),
			);
		}
		$entries = $this->read_json( $dir . '/' . $parts[ $i ] );
		$n       = 0;
		foreach ( $entries as $entry ) {
			if ( ! get_post( (int) $entry['id'] ) ) {
				continue;
			}
			foreach ( (array) $entry['files'] as $rel ) {
				$src = $dir . '/uploads/' . $rel;
				$dst = $this->upload_base() . '/' . $rel;
				if ( ! file_exists( $src ) ) {
					continue;
				}
				if ( file_exists( $dst ) && md5_file( $dst ) === md5_file( $src ) ) {
					continue;
				}
				if ( ! $this->copy_file( $src, $dst ) ) {
					return new WP_Error( 'mmseo_copy', __( 'Could not restore a file. Nothing further was changed.', 'mmseo-webp-converter' ) );
				}
				++$n;
			}
		}
		return array(
			'done'   => false,
			'cursor' => array( 'i' => $i + 1 ),
			'n'      => $n,
			'msg'    => '',
		);
	}

	/**
	 * Restore step 2: put attachment records (path, MIME type, metadata) back.
	 *
	 * @param string $run_id Run id.
	 * @param array  $cursor Cursor.
	 * @return array|WP_Error
	 */
	public function restore_attachments( $run_id, array $cursor ) {
		global $wpdb;
		$run = $this->get_run( $run_id );
		$dir = $this->run_dir( $run_id );
		if ( ! $run || ! $dir ) {
			return new WP_Error( 'mmseo_run', __( 'Backup not found.', 'mmseo-webp-converter' ) );
		}
		$parts = $this->snapshot_parts( $dir );
		$i     = isset( $cursor['i'] ) ? absint( $cursor['i'] ) : 0;
		if ( ! isset( $parts[ $i ] ) ) {
			return array(
				'done'   => true,
				'cursor' => $cursor,
				'msg'    => __( 'Image records restored.', 'mmseo-webp-converter' ),
			);
		}
		foreach ( $this->read_json( $dir . '/' . $parts[ $i ] ) as $entry ) {
			$id = (int) $entry['id'];
			if ( ! get_post( $id ) ) {
				continue;
			}
			update_post_meta( $id, '_wp_attached_file', $entry['file'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_mime_type' => $entry['mime'],
					'guid'           => $entry['guid'],
				),
				array( 'ID' => $id )
			);
			clean_post_cache( $id );
			if ( is_array( $entry['meta'] ) ) {
				wp_update_attachment_metadata( $id, $entry['meta'] );
			}
		}
		return array(
			'done'   => false,
			'cursor' => array( 'i' => $i + 1 ),
			'n'      => 0,
			'msg'    => '',
		);
	}

	/**
	 * Restore step 4: remove the WebP files and close the run.
	 *
	 * @param string $run_id Run id.
	 * @param array  $cursor Cursor.
	 * @return array|WP_Error
	 */
	public function restore_cleanup( $run_id, array $cursor ) {
		$run = $this->get_run( $run_id );
		$dir = $this->run_dir( $run_id );
		if ( ! $run || ! $dir ) {
			return new WP_Error( 'mmseo_run', __( 'Backup not found.', 'mmseo-webp-converter' ) );
		}
		$created = $this->read_json( $dir . '/created.json' );
		$off     = isset( $cursor['off'] ) ? absint( $cursor['off'] ) : 0;
		$slice   = array_slice( $created, $off, 200 );
		$base    = $this->upload_base() . '/';
		foreach ( $slice as $rel ) {
			// Never delete a file that was one of the originals; restore_files put those back.
			if ( ! file_exists( $dir . '/uploads/' . $rel ) ) {
				wp_delete_file( $base . $rel );
			}
		}
		$done = empty( $slice );
		if ( $done ) {
			$run['status']   = 'restored';
			$run['restored'] = time();
			$this->save_run( $run );
			$this->remove_redirects( $this->read_json( $dir . '/map.json' ) );
			$this->clear_caches( $run_id );
		}
		return array(
			'done'   => $done,
			'cursor' => array( 'off' => $off + 200 ),
			'msg'    => $done ? __( 'Restore complete. Your original images and links are back.', 'mmseo-webp-converter' ) : '',
		);
	}

	// ----- Redirects (old URL -> WebP URL) and new uploads -----

	/**
	 * Path of the merged redirect map.
	 *
	 * @return string
	 */
	private function redirect_file() {
		return $this->backup_root() . '/redirects.json';
	}

	/**
	 * Add entries to the redirect map.
	 *
	 * @param array $map Old rel => new rel.
	 * @return void
	 */
	private function merge_redirects( array $map ) {
		$all = $this->read_json( $this->redirect_file() );
		$this->write_json( $this->redirect_file(), array_merge( $all, $map ) );
	}

	/**
	 * Remove entries from the redirect map.
	 *
	 * @param array $map Old rel => new rel to drop.
	 * @return void
	 */
	private function remove_redirects( array $map ) {
		$all = $this->read_json( $this->redirect_file() );
		foreach ( array_keys( $map ) as $key ) {
			unset( $all[ $key ] );
		}
		$this->write_json( $this->redirect_file(), $all );
	}

	/**
	 * 301-redirect a request for a removed image to its WebP replacement.
	 *
	 * @return void
	 */
	public function maybe_redirect() {
		if ( ! is_404() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$settings = $this->settings();
		if ( empty( $settings['redirect'] ) ) {
			return;
		}
		$uri  = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		$base = wp_parse_url( $this->upload_url(), PHP_URL_PATH );
		if ( ! $path || ! $base || 0 !== strpos( $path, $base . '/' ) ) {
			return;
		}
		$rel = rawurldecode( substr( $path, strlen( $base ) + 1 ) );
		if ( null === $this->redirects ) {
			$this->redirects = $this->read_json( $this->redirect_file() );
		}
		if ( isset( $this->redirects[ $rel ] ) && file_exists( $this->upload_base() . '/' . $this->redirects[ $rel ] ) ) {
			wp_safe_redirect( $this->upload_url() . '/' . $this->encode_path( $this->redirects[ $rel ] ), 301 );
			exit;
		}
	}

	/**
	 * Convert brand-new uploads to WebP when the option is enabled.
	 *
	 * @param array $upload Upload data from wp_handle_upload.
	 * @return array
	 */
	public function convert_new_upload( $upload ) {
		$settings = $this->settings();
		if ( empty( $settings['auto'] ) || empty( $upload['file'] ) || empty( $upload['type'] ) ) {
			return $upload;
		}
		if ( ! in_array( $upload['type'], array( 'image/jpeg', 'image/png', 'image/bmp' ), true ) || ! $this->webp_supported() ) {
			return $upload;
		}
		$dir  = dirname( $upload['file'] );
		$base = pathinfo( $upload['file'], PATHINFO_FILENAME );
		$new  = $dir . '/' . $base . '.webp';
		if ( file_exists( $new ) ) {
			$new = $dir . '/' . wp_unique_filename( $dir, $base . '.webp' );
		}
		if ( true !== $this->save_webp( $upload['file'], $new, (int) $settings['quality'] ) ) {
			wp_delete_file( $new );
			return $upload;
		}
		if ( filesize( $new ) >= filesize( $upload['file'] ) ) {
			wp_delete_file( $new );
			return $upload;
		}
		wp_delete_file( $upload['file'] );
		$upload['file'] = $new;
		$upload['url']  = trailingslashit( dirname( $upload['url'] ) ) . basename( $new );
		$upload['type'] = 'image/webp';
		return $upload;
	}
}
