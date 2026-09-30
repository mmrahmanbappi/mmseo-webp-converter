<?php
/**
 * Uninstall routine.
 *
 * Removes plugin settings. Backups are only deleted when the user enabled
 * "Delete backups when the plugin is deleted".
 *
 * @package MMSEO_WebP_Converter
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$mmseo_webp_settings = get_option( 'mmseo_webp_settings', array() );
$mmseo_webp_purge    = is_array( $mmseo_webp_settings ) && ! empty( $mmseo_webp_settings['purge'] );

if ( $mmseo_webp_purge ) {
	$mmseo_webp_key = get_option( 'mmseo_webp_dirkey' );
	if ( $mmseo_webp_key && preg_match( '/^[a-z0-9]{12}$/', $mmseo_webp_key ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;
		$mmseo_webp_upload = wp_get_upload_dir();
		$mmseo_webp_dir    = trailingslashit( wp_normalize_path( $mmseo_webp_upload['basedir'] ) ) . 'mmseo-webp-backup-' . $mmseo_webp_key;
		if ( $wp_filesystem && is_dir( $mmseo_webp_dir ) ) {
			$wp_filesystem->delete( $mmseo_webp_dir, true );
		}
	}
	delete_option( 'mmseo_webp_dirkey' );
}

delete_option( 'mmseo_webp_settings' );
