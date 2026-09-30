<?php
/**
 * Plugin Name:       MMSEO WebP Converter
 * Description:       Back up first, then convert your whole Media Library to compressed WebP and safely update every reference. One-click restore included.
 * Version:           1.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            MMSEO
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mmseo-webp-converter
 * Domain Path:       /languages
 *
 * @package MMSEO_WebP_Converter
 */

defined( 'ABSPATH' ) || exit;

define( 'MMSEO_WEBP_VERSION', '1.1.0' );
define( 'MMSEO_WEBP_FILE', __FILE__ );
define( 'MMSEO_WEBP_URL', plugin_dir_url( __FILE__ ) );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-mmseo-webp-engine.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mmseo-webp-admin.php';

/**
 * Boot the plugin.
 *
 * @return void
 */
function mmseo_webp_boot() {
	$engine = MMSEO_WebP_Engine::instance();
	$engine->hooks();
	if ( is_admin() ) {
		$admin = new MMSEO_WebP_Admin( $engine );
		$admin->hooks();
	}
}
add_action( 'plugins_loaded', 'mmseo_webp_boot' );
