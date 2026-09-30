<?php
// MMSEO WebP Converter - QA harness v2. Usage: php qa2.php <stage>
error_reporting( E_ALL & ~E_DEPRECATED );
$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['SERVER_NAME'] = 'localhost'; $_SERVER['REQUEST_URI'] = '/wptest/'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require 'F:/laragon/www/wptest/wp-load.php';
foreach ( array( 'plugin', 'image', 'file', 'media', 'user' ) as $f ) { require_once ABSPATH . "wp-admin/includes/$f.php"; }
$stage = isset( $argv[1] ) ? $argv[1] : 'all';
$BASEF = 'F:/laragon/qa2-base.json';
$FAILS = 0;
function t( $ok, $msg ) { global $FAILS; if ( ! $ok ) { $FAILS++; } echo ( $ok ? 'PASS' : 'FAIL' ) . ' - ' . $msg . "\n"; }
function eng() { return MMSEO_WebP_Engine::instance(); }
function upl() { return wp_normalize_path( wp_get_upload_dir()['basedir'] ); }
function rrmdir( $d ) { if ( ! is_dir( $d ) ) { return; } foreach ( scandir( $d ) as $f ) { if ( '.' === $f || '..' === $f ) { continue; } $p = $d . '/' . $f; is_dir( $p ) ? rrmdir( $p ) : unlink( $p ); } rmdir( $d ); }

/* ---------- image makers ---------- */
function gfx( $w, $h, $alpha = false ) {
	$im = imagecreatetruecolor( $w, $h );
	if ( $alpha ) { imagealphablending( $im, false ); imagesavealpha( $im, true ); imagefill( $im, 0, 0, imagecolorallocatealpha( $im, 0, 0, 0, 127 ) ); imagealphablending( $im, true ); }
	mt_srand( 42 );
	for ( $y = 0; $y < $h; $y += 2 ) { imageline( $im, 0, $y, $w, $y, imagecolorallocate( $im, (int) ( $y * 255 / $h ), 120, 255 - (int) ( $y * 255 / $h ) ) ); }
	for ( $i = 0; $i < 40; $i++ ) { imagefilledellipse( $im, mt_rand( 0, $w ), mt_rand( 0, $h ), mt_rand( 20, 200 ), mt_rand( 20, 200 ), imagecolorallocatealpha( $im, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ), $alpha ? 40 : 0 ) ); }
	return $im;
}
function anim_gif( $netscape ) {
	$h = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff";
	if ( $netscape ) { $h .= "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00"; }
	$f = "\x21\xF9\x04\x00\x0A\x00\x00\x00\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00";
	return $h . $f . $f . "\x3B";
}
function make( $name, $w, $h, $alpha = false ) {
	$p = sys_get_temp_dir() . '/' . $name; $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
	if ( 'gif' === $ext && 0 === strpos( $name, 'qa-anim' ) ) { file_put_contents( $p, anim_gif( false !== strpos( $name, 'net' ) ) ); return $p; }
	$im = gfx( $w, $h, $alpha );
	if ( 'jpg' === $ext || 'jpeg' === $ext ) { imagejpeg( $im, $p, 95 ); } elseif ( 'png' === $ext ) { imagepng( $im, $p, 1 ); } elseif ( 'gif' === $ext ) { imagetruecolortopalette( $im, false, 255 ); imagegif( $im, $p ); } elseif ( 'bmp' === $ext ) { imagebmp( $im, $p, false ); } elseif ( 'webp' === $ext ) { imagewebp( $im, $p, 80 ); }
	return $p;
}
function sideload( $path, $name ) { $id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $path ), 0 ); if ( is_wp_error( $id ) ) { echo 'sideload error ' . $name . ': ' . $id->get_error_message() . "\n"; return 0; } return $id; }

/* ---------- snapshot / compare ---------- */
function tree() {
	$base = upl(); $out = array();
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) { if ( ! $f->isFile() ) { continue; } $rel = substr( wp_normalize_path( $f->getPathname() ), strlen( $base ) + 1 ); if ( 0 === strpos( $rel, 'mmseo-webp-backup-' ) ) { continue; } $out[ $rel ] = md5_file( $f->getPathname() ); }
	ksort( $out ); return $out;
}
function snap( $B ) {
	global $wpdb; wp_cache_flush(); $s = array( 'tree' => tree(), 'att' => array(), 'posts' => array(), 'meta' => array(), 'opt' => null );
	foreach ( $B['ids'] as $id ) { $p = get_post( $id ); if ( ! $p ) { continue; } $s['att'][ $id ] = array( 'file' => get_post_meta( $id, '_wp_attached_file', true ), 'mime' => $p->post_mime_type, 'guid' => $p->guid, 'meta' => $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_wp_attachment_metadata'", $id ) ) ); }
	foreach ( $B['posts'] as $pid ) { $p = get_post( $pid ); $s['posts'][ $pid ] = array( $p->post_content, $p->post_excerpt ); foreach ( array( '_elementor_data', '_qa_serialized_meta' ) as $k ) { $s['meta'][ $pid ][ $k ] = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s", $pid, $k ) ); } }
	$s['opt'] = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", 'qa_serialized_opt' ) );
	return $s;
}
function diffs( $a, $b, $skip_ids = array() ) {
	$d = array();
	foreach ( $skip_ids as $id ) { unset( $a['att'][ $id ], $b['att'][ $id ] ); }
	foreach ( array( 'tree', 'att', 'posts', 'meta' ) as $k ) { foreach ( array_unique( array_merge( array_keys( $a[ $k ] ), array_keys( $b[ $k ] ) ) ) as $key ) { if ( ( $a[ $k ][ $key ] ?? null ) !== ( $b[ $k ][ $key ] ?? null ) ) { $d[] = "$k/$key"; } } }
	if ( $a['opt'] !== $b['opt'] ) { $d[] = 'opt'; }
	return $d;
}

/* ---------- auth + HTTP ajax ---------- */
function make_auth( $uid ) {
	$exp = time() + 7200; $tok = WP_Session_Tokens::get_instance( $uid )->create( $exp );
	$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $uid, $exp, 'logged_in', $tok );
	wp_set_current_user( $uid ); $nonce = wp_create_nonce( 'mmseo_webp' );
	return array( 'cookie' => AUTH_COOKIE . '=' . rawurlencode( wp_generate_auth_cookie( $uid, $exp, 'auth', $tok ) ) . '; ' . LOGGED_IN_COOKIE . '=' . rawurlencode( $_COOKIE[ LOGGED_IN_COOKIE ] ), 'nonce' => $nonce, 'tok' => $tok, 'uid' => $uid );
}
function ajax( $phase, $run, $cursor, $extra, $auth, $nonce_override = null ) {
	$body = array_merge( array( 'action' => 'mmseo_webp', 'phase' => $phase, 'run' => $run, 'cursor' => wp_json_encode( (object) $cursor ) ), (array) $extra );
	if ( null !== $nonce_override ) { if ( '' !== $nonce_override ) { $body['nonce'] = $nonce_override; } } elseif ( $auth ) { $body['nonce'] = $auth['nonce']; }
	$hdr = $auth ? array( 'Cookie' => $auth['cookie'] ) : array();
	$r = wp_remote_post( admin_url( 'admin-ajax.php' ), array( 'timeout' => 120, 'body' => $body, 'headers' => $hdr ) );
	if ( is_wp_error( $r ) ) { return array( 0, null, $r->get_error_message() ); }
	return array( wp_remote_retrieve_response_code( $r ), json_decode( wp_remote_retrieve_body( $r ), true ), wp_remote_retrieve_body( $r ) );
}
function drive( $phase, $run, $auth, &$msgs = null ) {
	$cur = array(); $n = 0;
	for ( $i = 0; $i < 600; $i++ ) {
		list( $code, $j, $raw ) = ajax( $phase, $run, $cur, array(), $auth );
		if ( 200 !== $code || empty( $j['success'] ) ) { echo "  drive($phase) failed: HTTP $code " . substr( $raw, 0, 200 ) . "\n"; return false; }
		$d = $j['data']; $n += $d['n'] ?? 0; $cur = $d['cursor'] ?? array(); if ( ! empty( $d['msg'] ) ) { $msgs[] = $d['msg']; }
		if ( ! empty( $d['done'] ) ) { return $n + 1; }
	}
	return false;
}
function pipeline( $auth, $delete = 1 ) {
	list( $c, $j ) = ajax( 'init', '', array(), array( 'quality' => 80, 'delete' => $delete, 'auto' => 0, 'redirect' => 1, 'purge' => 0 ), $auth );
	if ( empty( $j['success'] ) ) { echo "  init failed\n"; return false; }
	$run = $j['data']['run']; $m = array();
	foreach ( array( 'backup_files', 'backup_db', 'convert', 'replace' ) as $ph ) { if ( false === drive( $ph, $run, $auth, $m ) ) { return false; } }
	if ( $delete && false === drive( 'finalize', $run, $auth, $m ) ) { return false; }
	ajax( 'summary', $run, array(), array(), $auth );
	wp_cache_flush();
	return $run;
}
function restore_http( $run, $auth ) {
	foreach ( array( 'restore_files', 'restore_attachments', 'restore_refs', 'restore_cleanup' ) as $ph ) { if ( false === drive( $ph, $run, $auth ) ) { return false; } }
	wp_cache_flush();
	return true;
}
function B() { global $BASEF; return json_decode( file_get_contents( $BASEF ), true ); }

/* =================== STAGES =================== */
if ( 'reset' === $stage ) {
	foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) { wp_delete_attachment( $id, true ); }
	foreach ( get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) { wp_delete_post( $id, true ); }
	foreach ( array( 'qa_serialized_opt', 'lpc_test_serialized', 'lpc_webp_run', 'lpc_webp_settings', 'mmseo_webp_settings', 'mmseo_webp_dirkey' ) as $o ) { delete_option( $o ); }
	foreach ( glob( upl() . '/mmseo-webp-backup-*' ) ?: array() as $d ) { rrmdir( $d ); }
	foreach ( glob( WP_CONTENT_DIR . '/lpc-webp-backup*' ) ?: array() as $d ) { rrmdir( $d ); }
	foreach ( glob( upl() . '/*/*/*' ) ?: array() as $f ) { if ( is_file( $f ) ) { wp_delete_file( $f ); } }
	@unlink( $BASEF );
	echo "reset done\n"; exit;
}

if ( 'setup' === $stage ) {
	echo "== SETUP ==\n";
	$r = activate_plugin( 'mmseo-webp-converter/mmseo-webp-converter.php' );
	t( ! is_wp_error( $r ) && is_plugin_active( 'mmseo-webp-converter/mmseo-webp-converter.php' ), 'plugin active' . ( is_wp_error( $r ) ? ': ' . $r->get_error_message() : '' ) );
	t( class_exists( 'MMSEO_WebP_Engine' ) && class_exists( 'MMSEO_WebP_Admin' ) || class_exists( 'MMSEO_WebP_Engine' ), 'engine class loaded' );
	eng()->save_settings( array( 'quality' => 80, 'delete' => 1, 'auto' => 0, 'redirect' => 1, 'purge' => 0 ) );
	$src = array(
		'qa-photo-a.jpg' => array( 1600, 1200, 0 ), 'qa-photo-b.jpeg' => array( 1200, 800, 0 ), 'qa-big.jpg' => array( 3200, 2400, 0 ), 'qa-logo.png' => array( 800, 600, 1 ),
		'qa-shot.png' => array( 1000, 700, 0 ), 'qa-static.gif' => array( 400, 300, 0 ), 'qa-already.webp' => array( 500, 400, 0 ), 'qa-tiny.png' => array( 20, 20, 0 ),
		'qa-old.bmp' => array( 600, 400, 0 ), 'QA-UPPER.JPG' => array( 900, 600, 0 ), 'qa.v2.final.jpg' => array( 800, 500, 0 ), 'qa-anim-net.gif' => array( 1, 1, 0 ), 'qa-anim-plain.gif' => array( 1, 1, 0 ),
	);
	$ids = array();
	foreach ( $src as $n => $d ) { $ids[ $n ] = sideload( make( $n, $d[0], $d[1], (bool) $d[2] ), $n ); t( $ids[ $n ] > 0, "uploaded $n (#{$ids[$n]} " . get_post_mime_type( $ids[ $n ] ) . ')' ); }
	$u = function ( $n ) use ( $ids ) { return wp_get_attachment_url( $ids[ $n ] ); };
	$sz = function ( $n, $s ) use ( $ids ) { $i = wp_get_attachment_image_src( $ids[ $n ], $s ); return $i ? $i[0] : ''; };
	$p1 = wp_insert_post( array( 'post_title' => 'QA post 1', 'post_status' => 'publish', 'post_excerpt' => 'Excerpt with ' . $u( 'qa-logo.png' ), 'post_content' =>
		'<p>A <img src="' . $u( 'qa-photo-a.jpg' ) . '" srcset="' . $sz( 'qa-photo-a.jpg', 'medium' ) . ' 300w, ' . $u( 'qa-photo-a.jpg' ) . ' 1600w" class="wp-image-' . $ids['qa-photo-a.jpg'] . '"></p>'
		. '<!-- wp:image {"id":' . $ids['qa-logo.png'] . '} --><figure class="wp-block-image"><img src="' . $u( 'qa-logo.png' ) . '" class="wp-image-' . $ids['qa-logo.png'] . '"/></figure><!-- /wp:image -->'
		. '<p>Decoy: https://example.com/img/qa-photo-a.jpg</p><p><img src="' . $sz( 'qa-shot.png', 'thumbnail' ) . '"></p><p><img src="' . $u( 'QA-UPPER.JPG' ) . '"> <img src="' . $u( 'qa.v2.final.jpg' ) . '"> <img src="' . $u( 'qa-anim-net.gif' ) . '"></p>' ) );
	$p2 = wp_insert_post( array( 'post_title' => 'QA post 2 featured', 'post_status' => 'publish', 'post_content' => 'Featured image test. Bmp: ' . $u( 'qa-old.bmp' ) ) );
	set_post_thumbnail( $p2, $ids['qa-photo-b.jpeg'] );
	update_post_meta( $p1, '_elementor_data', wp_slash( wp_json_encode( array( array( 'id' => 'abc', 'settings' => array( 'image' => array( 'url' => $u( 'qa-big.jpg' ), 'id' => $ids['qa-big.jpg'] ), 'bg' => $u( 'qa-static.gif' ) ) ) ) ) ) );
	update_post_meta( $p1, '_qa_serialized_meta', array( 'image' => $u( 'qa-shot.png' ), 'list' => array( $u( 'qa-old.bmp' ), 5 ), 'nested' => serialize( array( 'deep' => $u( 'qa-photo-b.jpeg' ) ) ) ) );
	update_option( 'qa_serialized_opt', array( 'a' => $u( 'qa-photo-a.jpg' ), 'nested' => array( 'img' => $u( 'qa-shot.png' ), 'n' => 5 ), 'obj' => (object) array( 'u' => $u( 'qa-logo.png' ) ) ), false );
	$B = array( 'ids' => $ids, 'posts' => array( $p1, $p2 ), 'p1' => $p1, 'p2' => $p2, 'urls' => array_map( $u, array_combine( array_keys( $ids ), array_keys( $ids ) ) ) );
	$B['snap'] = snap( $B ); file_put_contents( $BASEF, wp_json_encode( $B ) );
	t( count( $B['snap']['tree'] ) > 30, 'baseline snapshot: ' . count( $B['snap']['tree'] ) . ' files hashed' );
	echo 'webp supported: ' . ( eng()->webp_supported() ? 'yes' : 'NO' ) . "\n";
}

if ( 'unit' === $stage ) {
	echo "== UNIT ==\n";
	$e = eng(); $rm = new ReflectionMethod( 'MMSEO_WebP_Engine', 'build_pairs' ); $rm->setAccessible( true ); $rd = new ReflectionMethod( 'MMSEO_WebP_Engine', 'deep_replace' ); $rd->setAccessible( true );
	t( false === $e->is_animated_gif( make( 'qa-static.gif', 100, 80 ) ), 'static GIF detected as static' );
	t( true === $e->is_animated_gif( make( 'qa-anim-net.gif', 1, 1 ) ), 'animated GIF (with loop header) detected' );
	t( true === $e->is_animated_gif( make( 'qa-anim-plain.gif', 1, 1 ) ), 'animated GIF (WITHOUT loop header) detected' );
	t( false === $e->is_animated_gif( __FILE__ ), 'non-GIF file not flagged' );
	$pairs = $rm->invoke( $e, array( '2026/09/my pic é.jpg' => '2026/09/my pic é.webp', '2026/09/a.jpg' => '2026/09/a.webp', '2026/09/a-300x200.jpg' => '2026/09/a-300x200.webp' ) );
	$s = 'x /2026/09/my pic é.jpg y /2026/09/my%20pic%20%C3%A9.jpg z \\/2026\\/09\\/a.jpg w /2026/09/a-300x200.jpg v /other/a.jpg';
	$out = $rd->invoke( $e, $s, $pairs );
	t( false !== strpos( $out, '/2026/09/my pic é.webp' ) && false !== strpos( $out, '/2026/09/my%20pic%20%C3%A9.webp' ), 'plain and URL-encoded paths both replaced' );
	t( false !== strpos( $out, '\\/2026\\/09\\/a.webp' ) && false !== strpos( $out, '/2026/09/a-300x200.webp' ), 'JSON-escaped and size-specific paths replaced' );
	t( false !== strpos( $out, 'v /other/a.jpg' ), 'path in a different folder untouched' );
	$ser = serialize( array( 'k' => '/2026/09/a.jpg', 'n' => array( 1, 2 ), 'in' => serialize( array( 'z' => '/2026/09/a.jpg' ) ), 'o' => (object) array( 'p' => '/2026/09/a.jpg' ) ) );
	$res = unserialize( $rd->invoke( $e, $ser, $pairs ) );
	t( is_array( $res ) && '/2026/09/a.webp' === $res['k'] && 2 === count( $res['n'] ) && '/2026/09/a.webp' === unserialize( $res['in'] )['z'] && '/2026/09/a.webp' === $res['o']->p, 'serialized data (nested serialized + object) stays valid, lengths recomputed' );
}

if ( 'cycle1' === $stage ) {
	echo "== CYCLE 1: full conversion via real AJAX, then strict restore ==\n";
	$B = B(); $auth = make_auth( 1 ); $ids = $B['ids'];
	$t0 = microtime( true ); $run = pipeline( $auth ); t( false !== $run, 'pipeline finished via AJAX in ' . round( microtime( true ) - $t0, 1 ) . 's (' . $run . ')' );
	$r = eng()->get_run( $run ); t( 'done' === $r['status'], 'run status = done' ); echo '  stats: ' . wp_json_encode( $r['stats'] ) . "\n";
	t( 0 === $r['stats']['errors'], 'no conversion errors' ); t( $r['stats']['converted'] >= 9 && $r['stats']['skipped'] >= 2, 'converted ' . $r['stats']['converted'] . ', skipped ' . $r['stats']['skipped'] . ' (2 animated GIFs expected skipped)' );
	foreach ( $ids as $n => $id ) {
		$mime = get_post_mime_type( $id ); $f = get_attached_file( $id ); $was = $B['snap']['att'][ $id ]['mime'];
		if ( 'image/webp' === $was ) { t( file_exists( $f ), "$n already WebP untouched" ); continue; }
		if ( 'qa-static.gif' === $n ) { $kept = 'image/gif' === $mime || 'image/webp' === $mime; t( $kept && file_exists( $f ), "$n handled safely (" . $mime . ': ' . ( 'image/gif' === $mime ? 'kept as GIF because WebP would be larger' : 'converted' ) . ')' ); continue; }
		if ( false !== strpos( $n, 'anim' ) ) { t( 'image/gif' === $mime && file_exists( $f ), "$n animated GIF left as GIF" ); continue; }
		$m = wp_get_attachment_metadata( $id ); $ok = 'image/webp' === $mime && preg_match( '/\.webp$/', $f ) && file_exists( $f ) && 'image/webp' === wp_getimagesize( $f )['mime'];
		foreach ( (array) ( $m['sizes'] ?? array() ) as $s ) { $ok = $ok && preg_match( '/\.webp$/', $s['file'] ) && file_exists( dirname( $f ) . '/' . $s['file'] ); }
		t( $ok, "$n -> " . basename( $f ) . ' (' . size_format( filesize( $f ) ) . ', ' . count( (array) ( $m['sizes'] ?? array() ) ) . ' sizes)' );
		$orig = $B['snap']['att'][ $id ]['file']; t( ! file_exists( upl() . '/' . $orig ), "$n old file deleted" );
	}
	$before = $B['snap']['tree']; $after = tree(); $left_old = 0; foreach ( $after as $rel => $h ) { if ( preg_match( '/\.(jpe?g|png|bmp)$/i', $rel ) ) { $left_old++; } } t( 0 === $left_old, 'no leftover JPG/PNG/BMP files in uploads' );
	$p1 = get_post( $B['p1'] )->post_content;
	t( false === strpos( $p1, 'uploads/2026/09/qa-photo-a.jpg' ) && false !== strpos( $p1, 'uploads/2026/09/qa-photo-a.webp' ) && false !== strpos( $p1, 'qa-shot-150x150.webp' ), 'post 1: full-size and thumbnail-size URLs updated' );
	t( false !== strpos( $p1, 'wp-image-' . $ids['qa-logo.png'] ) && false !== strpos( $p1, '"id":' . $ids['qa-logo.png'] ), 'post 1: attachment IDs unchanged' );
	t( false !== strpos( $p1, 'https://example.com/img/qa-photo-a.jpg' ), 'post 1: external decoy untouched' );
	t( false !== strpos( $p1, 'QA-UPPER.webp' ) && 1 === preg_match( '#qa\.v2\.final_?\.webp#', $p1 ), 'post 1: UPPERCASE ext and dotted filename converted' );
	t( false !== strpos( $p1, 'qa-anim-net.gif' ), 'post 1: animated GIF reference left alone' );
	t( false !== strpos( get_post( $B['p1'] )->post_excerpt, 'qa-logo.webp' ), 'post 1: excerpt updated' );
	t( (int) get_post_thumbnail_id( $B['p2'] ) === $ids['qa-photo-b.jpeg'] && preg_match( '/\.webp$/', (string) get_the_post_thumbnail_url( $B['p2'], 'full' ) ), 'featured image intact and WebP' );
	$el = get_post_meta( $B['p1'], '_elementor_data', true ); $j = json_decode( $el, true );
	t( null !== $j && false !== strpos( $el, 'qa-big' ) && false === strpos( $el, '.jpg' ) && ( $j[0]['settings']['image']['id'] ?? 0 ) == $ids['qa-big.jpg'], 'Elementor JSON valid, URL updated, id intact' );
	$sm = get_post_meta( $B['p1'], '_qa_serialized_meta', true ); t( is_array( $sm ) && preg_match( '/qa-shot\.webp$/', $sm['image'] ) && 5 === $sm['list'][1] && preg_match( '/qa-photo-b\.webp$/', unserialize( $sm['nested'] )['deep'] ), 'serialized postmeta (incl. nested serialized) intact + updated' );
	$so = get_option( 'qa_serialized_opt' ); t( is_array( $so ) && preg_match( '/qa-photo-a\.webp$/', $so['a'] ) && is_object( $so['obj'] ) && preg_match( '/qa-logo\.webp$/', $so['obj']->u ), 'serialized option (array + object) intact + updated' );
	stream_context_set_default( array( 'http' => array( 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 20 ) ) );
	$newurl = wp_get_attachment_url( $ids['qa-photo-a.jpg'] ); $h = get_headers( $newurl, 1 ); $ct = is_array( $h['Content-Type'] ) ? end( $h['Content-Type'] ) : $h['Content-Type'];
	t( false !== strpos( $h[0], '200' ) && false !== stripos( $ct, 'webp' ), 'new URL serves 200 image/webp' );
	$h2 = get_headers( $B['urls']['qa-photo-a.jpg'], 1 ); $loc = is_array( $h2['Location'] ?? '' ) ? end( $h2['Location'] ) : ( $h2['Location'] ?? '' );
	t( false !== strpos( $h2[0], '301' ) && preg_match( '/qa-photo-a\.webp$/', $loc ), 'old JPG URL -> 301 -> WebP' );
	$h3 = get_headers( $B['urls']['QA-UPPER.JPG'], 1 ); t( false !== strpos( $h3[0], '301' ), 'old UPPERCASE .JPG URL also 301s' );
	$h4 = get_headers( wp_get_upload_dir()['baseurl'] . '/2099/01/nope.jpg', 1 ); t( false !== strpos( $h4[0], '404' ), 'never-existing old URL stays 404' );
	$pl = get_permalink( $B['p1'] ); $hh = get_headers( $pl, 1 ); t( false !== strpos( $hh[0], '200' ), 'front-end post page loads 200' );
	$pg = file_get_contents( $pl ); t( false !== strpos( $pg, 'qa-photo-a.webp' ) && 0 === preg_match_all( '#uploads/2026/09/qa-photo-a\.jpg#', $pg ), 'rendered page serves WebP and no longer mentions the old JPG' );
	list( $c, $lj ) = ajax( 'list', '', array(), array(), $auth ); t( 1 === count( $lj['data']['runs'] ?? array() ) && 'done' === $lj['data']['runs'][0]['status'], 'backup list shows the run' );
	echo "-- restore --\n"; $t0 = microtime( true ); t( restore_http( $run, $auth ), 'restore finished via AJAX in ' . round( microtime( true ) - $t0, 1 ) . 's' );
	$d = diffs( $B['snap'], snap( $B ) ); t( empty( $d ), 'STRICT: every file (md5), attachment record, metadata, post, meta and option identical to before' . ( $d ? ' - diffs: ' . implode( ', ', array_slice( $d, 0, 8 ) ) : '' ) );
	t( 'restored' === eng()->get_run( $run )['status'], 'run marked restored' );
	$hr = get_headers( $newurl, 1 ); t( false !== strpos( $hr[0], '404' ) || false !== strpos( $hr[0], '301' ), 'WebP URL after restore: ' . $hr[0] );
	list( $c, $j ) = ajax( 'restore_files', $run, array(), array(), $auth ); t( empty( $j['success'] ), 'restoring twice is refused' );
	foreach ( array( 'mmseo_webp_settings' ) as $o ) { t( false !== get_option( $o ), 'settings option kept' ); }
}

if ( 'cycle2' === $stage ) {
	echo "== CYCLE 2: convert again, then delete an attachment + edit a post, then restore ==\n";
	$B = B(); $auth = make_auth( 1 ); $ids = $B['ids'];
	$run = pipeline( $auth ); t( false !== $run, 're-conversion after a restore works (' . $run . ')' ); t( 'done' === eng()->get_run( $run )['status'], 'status done' );
	wp_delete_attachment( $ids['qa-tiny.png'], true ); t( ! get_post( $ids['qa-tiny.png'] ), 'attachment deleted while converted' );
	$webp_b = wp_get_attachment_url( $ids['qa-photo-b.jpeg'] ); $p2 = get_post( $B['p2'] );
	wp_update_post( array( 'ID' => $B['p2'], 'post_content' => $p2->post_content . ' NEW <img src="' . $webp_b . '">' ) );
	t( restore_http( $run, $auth ), 'restore finished' );
	$now = snap( $B ); $d = diffs( $B['snap'], $now, array( $ids['qa-tiny.png'] ) );
	$d = array_values( array_filter( $d, function ( $x ) use ( $B ) { return ! ( 'posts/' . $B['p2'] === $x ) && ! preg_match( '#^tree/\d{4}/\d{2}/qa-tiny#', $x ); } ) );
	t( empty( $d ), 'everything else identical to original' . ( $d ? ' - diffs: ' . implode( ', ', array_slice( $d, 0, 8 ) ) : '' ) );
	$p2c = get_post( $B['p2'] )->post_content; t( false !== strpos( $p2c, 'NEW <img src="' . $B['urls']['qa-photo-b.jpeg'] . '">' ), 'post edited AFTER conversion was reverted to the original URL without losing the edit' );
	t( ! get_post( $ids['qa-tiny.png'] ) && empty( preg_grep( '#qa-tiny#', array_keys( tree() ) ) ), 'deleted attachment was NOT resurrected and left no files behind' );
	$B['ids'] = array_diff_key( $B['ids'], array( 'qa-tiny.png' => 1 ) ); $B['snap'] = snap( $B ); file_put_contents( $GLOBALS['BASEF'], wp_json_encode( $B ) );
}

if ( 'cycle3' === $stage ) {
	echo "== CYCLE 3: interrupted run (stopped halfway) then restore ==\n";
	$B = B(); $auth = make_auth( 1 ); $m = array();
	list( $c, $j ) = ajax( 'init', '', array(), array( 'quality' => 80, 'delete' => 1, 'auto' => 0, 'redirect' => 1, 'purge' => 0 ), $auth ); $run = $j['data']['run'];
	drive( 'backup_files', $run, $auth ); drive( 'backup_db', $run, $auth );
	list( $c, $j ) = ajax( 'convert', $run, array(), array(), $auth ); t( ! empty( $j['success'] ) && empty( $j['data']['done'] ), 'converted the first batch only, then "crashed"' );
	$mid = 0; foreach ( $B['ids'] as $id ) { if ( 'image/webp' === get_post_mime_type( $id ) && 'image/webp' !== $B['snap']['att'][ $id ]['mime'] ) { $mid++; } } t( $mid >= 1, "$mid attachments were converted mid-run" );
	list( $c, $j ) = ajax( 'replace', $run, array(), array(), $auth ); t( empty( $j['success'] ), 'cannot jump to "update links" before conversion finished (order enforced)' );
	list( $c, $j ) = ajax( 'finalize', $run, array(), array(), $auth ); t( empty( $j['success'] ), 'cannot delete old files before links are updated (order enforced)' );
	t( restore_http( $run, $auth ), 'restore from the half-finished run' );
	$d = diffs( $B['snap'], snap( $B ) ); t( empty( $d ), 'STRICT: fully identical to original after restoring an interrupted run' . ( $d ? ' - ' . implode( ', ', array_slice( $d, 0, 8 ) ) : '' ) );
}

if ( 'security' === $stage ) {
	echo "== SECURITY ==\n";
	$B = B(); $admin = make_auth( 1 );
	$subid = wp_insert_user( array( 'user_login' => 'qa_sub', 'user_pass' => wp_generate_password(), 'user_email' => 'qa_sub@example.com', 'role' => 'subscriber' ) );
	$sub = make_auth( $subid ); $admin = make_auth( 1 );
	list( $c, $j, $raw ) = ajax( 'scan', '', array(), array(), null, '' ); t( empty( $j['success'] ), "anonymous request rejected (HTTP $c)" );
	list( $c, $j, $raw ) = ajax( 'scan', '', array(), array(), $admin, '' ); t( 403 === $c && empty( $j['success'] ), "admin WITHOUT nonce rejected (HTTP $c)" );
	list( $c, $j, $raw ) = ajax( 'scan', '', array(), array(), $admin, 'deadbeef12' ); t( 403 === $c && empty( $j['success'] ), "admin with FORGED nonce rejected (HTTP $c)" );
	list( $c, $j, $raw ) = ajax( 'scan', '', array(), array(), $sub ); t( empty( $j['success'] ), "subscriber with valid nonce rejected (HTTP $c)" );
	foreach ( array( 'init', 'convert', 'restore_files', 'delete_run' ) as $ph ) { list( $c, $j ) = ajax( $ph, 'run-20260101-000000', array(), array(), $sub ); t( empty( $j['success'] ), "subscriber cannot call $ph" ); }
	list( $c, $j ) = ajax( 'scan', '', array(), array(), $admin ); t( ! empty( $j['success'] ), 'administrator can scan' );
	list( $c, $j ) = ajax( 'bogus', '', array(), array(), $admin ); t( empty( $j['success'] ), 'unknown phase rejected' );
	foreach ( array( '../../wp-config', '..\\..\\wp-config', 'run-20260101-000000/../../x', 'run-99999999-999999', '' ) as $bad ) { list( $c, $j ) = ajax( 'backup_files', $bad, array(), array(), $admin ); t( empty( $j['success'] ), 'bad run id refused: ' . var_export( $bad, true ) ); list( $c, $j ) = ajax( 'delete_run', $bad, array(), array(), $admin ); t( empty( $j['success'] ), 'delete refused for bad id ' . var_export( $bad, true ) ); }
	$gl = wp_remote_get( admin_url( 'admin-ajax.php?action=mmseo_webp&phase=scan' ), array( 'headers' => array( 'Cookie' => $admin['cookie'] ) ) ); $jb = json_decode( wp_remote_retrieve_body( $gl ), true ); t( empty( $jb['success'] ), 'GET request (no nonce) rejected' );
	list( $c, $j ) = ajax( 'init', '', array(), array( 'quality' => '999; DROP TABLE', 'delete' => 'x', 'auto' => '1', 'redirect' => '<script>', 'purge' => 0 ), $admin ); $run = $j['data']['run'] ?? ''; $s = eng()->settings();
	t( 100 === $s['quality'] && 0 === $s['delete'] && 1 === $s['auto'], 'malicious settings input sanitised (quality clamped to ' . $s['quality'] . ')' );
	list( $c, $j ) = ajax( 'convert', $run, array(), array(), $admin ); t( empty( $j['success'] ), 'convert refused before backup finished' );
	list( $c, $j ) = ajax( 'backup_files', $run, array( 'last' => '1; DROP', 'x' => array( 1 ) ), array(), $admin ); t( ! empty( $j['success'] ), 'garbage cursor values are neutralised, not executed' );
	$root = eng()->backup_root(); $url = wp_get_upload_dir()['baseurl'] . '/' . basename( $root );
	foreach ( array( '/' . $run . '/run.json', '/redirects.json', '/' . $run . '/uploads/2026/09/qa-photo-a.jpg', '/' ) as $p ) { $r = wp_remote_get( $url . $p, array( 'timeout' => 15 ) ); $code = wp_remote_retrieve_response_code( $r ); t( in_array( $code, array( 403, 404 ), true ), "backup folder not web-readable: $p (HTTP $code)" ); }
	t( file_exists( $root . '/.htaccess' ) && file_exists( $root . '/index.php' ), 'backup folder has .htaccess + index.php guards' );
	t( 1 === preg_match( '/mmseo-webp-backup-[a-z0-9]{12}$/', $root ), 'backup folder name is unguessable: ' . basename( $root ) );
	eng()->delete_run( $run ); eng()->save_settings( array( 'quality' => 80, 'delete' => 1, 'auto' => 0, 'redirect' => 1, 'purge' => 0 ) );
	wp_delete_user( $subid ); $n = 0; foreach ( array( $admin['tok'] ) as $tk ) { WP_Session_Tokens::get_instance( 1 )->destroy( $tk ); }
	t( ! get_user_by( 'login', 'qa_sub' ), 'test user removed' );
}

if ( 'stress' === $stage ) {
	echo "== STRESS: 40 extra images through the whole cycle ==\n";
	$B = B(); $extra = array();
	for ( $i = 1; $i <= 40; $i++ ) { $n = sprintf( 'qa-bulk-%02d.%s', $i, 0 === $i % 3 ? 'png' : 'jpg' ); $extra[ $n ] = sideload( make( $n, 640, 420, 0 === $i % 3 ), $n ); }
	t( 40 === count( array_filter( $extra ) ), '40 images uploaded' );
	$B2 = $B; $B2['ids'] = array_merge( $B['ids'], $extra ); $before = snap( $B2 ); $auth = make_auth( 1 );
	$mem0 = memory_get_peak_usage( true ); $t0 = microtime( true ); $run = pipeline( $auth ); $dt = microtime( true ) - $t0;
	t( false !== $run, 'whole pipeline over ' . count( $B2['ids'] ) . ' attachments OK in ' . round( $dt, 1 ) . 's' );
	$r = eng()->get_run( $run ); echo '  stats: ' . wp_json_encode( $r['stats'] ) . "\n"; t( 0 === $r['stats']['errors'], 'zero errors' ); t( $r['stats']['converted'] >= 48, 'converted ' . $r['stats']['converted'] );
	$w = 0; foreach ( $extra as $n => $id ) { if ( 'image/webp' === get_post_mime_type( $id ) ) { $w++; } } t( 40 === $w, "all 40 bulk images are WebP ($w)" );
	t( restore_http( $run, $auth ), 'restore OK' ); $d = diffs( $before, snap( $B2 ) ); t( empty( $d ), 'STRICT identical after restoring 50+ images' . ( $d ? ' - ' . implode( ', ', array_slice( $d, 0, 8 ) ) : '' ) );
	foreach ( $extra as $id ) { wp_delete_attachment( $id, true ); }
	list( $c, $j ) = ajax( 'list', '', array(), array(), $auth ); foreach ( $j['data']['runs'] as $rr ) { eng()->delete_run( $rr['id'] ); } t( 0 === count( eng()->list_runs() ), 'backups deleted via plugin API; list is empty' );
}

if ( 'extras' === $stage ) {
	echo "== EXTRAS: auto-convert, redirect switch, uninstall ==\n";
	$B = B(); $auth = make_auth( 1 );
	eng()->save_settings( array( 'quality' => 80, 'delete' => 1, 'auto' => 1, 'redirect' => 1, 'purge' => 0 ) );
	$id = sideload( make( 'qa-new-upload.jpg', 1400, 900 ), 'qa-new-upload.jpg' ); $f = get_attached_file( $id ); $m = wp_get_attachment_metadata( $id );
	$ok = $id && preg_match( '/\.webp$/', $f ) && 'image/webp' === get_post_mime_type( $id ) && ! empty( $m['sizes'] ); foreach ( (array) ( $m['sizes'] ?? array() ) as $s ) { $ok = $ok && preg_match( '/\.webp$/', $s['file'] ); }
	t( $ok, 'new JPG upload auto-converted (with WebP thumbnails)' ); t( ! file_exists( dirname( $f ) . '/qa-new-upload.jpg' ), 'no JPG left behind' );
	$idp = sideload( make( 'qa-new-logo.png', 500, 400, true ), 'qa-new-logo.png' ); t( 'image/webp' === get_post_mime_type( $idp ), 'new transparent PNG auto-converted' );
	$ida = sideload( make( 'qa-anim-new.gif', 1, 1 ), 'qa-anim-new.gif' ); t( 'image/gif' === get_post_mime_type( $ida ), 'new animated GIF upload left alone' );
	eng()->save_settings( array( 'quality' => 80, 'delete' => 1, 'auto' => 0, 'redirect' => 1, 'purge' => 0 ) );
	$id2 = sideload( make( 'qa-auto-off.jpg', 800, 500 ), 'qa-auto-off.jpg' ); t( 'image/jpeg' === get_post_mime_type( $id2 ), 'with auto-convert OFF (default) uploads are untouched' );
	foreach ( array( $id, $idp, $ida, $id2 ) as $x ) { wp_delete_attachment( $x, true ); }
	// redirect switch
	$run = pipeline( $auth ); $old = $B['urls']['qa-photo-a.jpg']; stream_context_set_default( array( 'http' => array( 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 20 ) ) );
	eng()->save_settings( array( 'quality' => 80, 'delete' => 1, 'auto' => 0, 'redirect' => 0, 'purge' => 0 ) ); $h = get_headers( $old, 1 ); t( false !== strpos( $h[0], '404' ), 'redirect option OFF -> old URL is 404' );
	eng()->save_settings( array( 'quality' => 80, 'delete' => 1, 'auto' => 0, 'redirect' => 1, 'purge' => 0 ) ); $h = get_headers( $old, 1 ); t( false !== strpos( $h[0], '301' ), 'redirect option ON -> old URL is 301' );
	t( restore_http( $run, $auth ), 'restore after redirect tests' ); $d = diffs( $B['snap'], snap( $B ) ); t( empty( $d ), 'identical to original again' . ( $d ? ' - ' . implode( ', ', array_slice( $d, 0, 6 ) ) : '' ) );
	$h = get_headers( $old, 1 ); t( false !== strpos( $h[0], '200' ), 'after restore the ORIGINAL url serves the original file (HTTP 200)' );
	// uninstall behaviour
	$root = eng()->backup_root(); t( is_dir( $root ), 'backup root exists before uninstall test' );
	define( 'WP_UNINSTALL_PLUGIN', 'mmseo-webp-converter/mmseo-webp-converter.php' );
	$plugin_dir = WP_PLUGIN_DIR . '/mmseo-webp-converter'; $key = get_option( 'mmseo_webp_dirkey' );
	eng()->save_settings( array( 'quality' => 80, 'delete' => 1, 'auto' => 0, 'redirect' => 1, 'purge' => 0 ) ); include $plugin_dir . '/uninstall.php';
	t( false === get_option( 'mmseo_webp_settings' ) && is_dir( $root ) && $key === get_option( 'mmseo_webp_dirkey' ), 'uninstall (purge OFF): settings removed, backups KEPT' );
	eng()->save_settings( array( 'quality' => 80, 'delete' => 1, 'auto' => 0, 'redirect' => 1, 'purge' => 1 ) ); include $plugin_dir . '/uninstall.php';
	t( false === get_option( 'mmseo_webp_settings' ) && ! is_dir( $root ) && false === get_option( 'mmseo_webp_dirkey' ), 'uninstall (purge ON): settings AND backups removed' );
}

if ( 'dbg' === $stage ) {
	$B = B(); $auth = make_auth( 1 ); $run = pipeline( $auth );
	echo "POST1 after convert:\n" . get_post( $B['p1'] )->post_content . "\n\nPOST2:\n" . get_post( $B['p2'] )->post_content . "\n";
	foreach ( $B['ids'] as $n => $id ) { echo "$n #$id " . get_post_meta( $id, '_wp_attached_file', true ) . "\n"; }
	restore_http( $run, $auth ); $now = snap( $B );
	foreach ( $B['snap']['att'] as $id => $a ) { $b = $now['att'][ $id ]; foreach ( array( 'file', 'mime', 'guid', 'meta' ) as $k ) { if ( $a[ $k ] !== $b[ $k ] ) { echo "ATT $id field $k differs\n  BEFORE: " . substr( $a[ $k ], 0, 900 ) . "\n  AFTER : " . substr( $b[ $k ], 0, 900 ) . "\n"; } } }
	foreach ( $B['snap']['posts'] as $pid => $a ) { if ( $a !== $now['posts'][ $pid ] ) { echo "POST $pid differs\n  BEFORE: " . $a[0] . "\n  AFTER : " . $now['posts'][ $pid ][0] . "\n"; } }
	eng()->delete_run( $run );
}

if ( 'dbg2' === $stage ) {
	$B = B(); $auth = make_auth( 1 ); $run = pipeline( $auth ); echo "run=$run status=" . eng()->get_run( $run )['status'] . "\n";
	foreach ( array( 'restore_files', 'restore_attachments', 'restore_refs', 'restore_cleanup' ) as $ph ) {
		$cur = array();
		for ( $i = 0; $i < 30; $i++ ) { list( $c, $j, $raw ) = ajax( $ph, $run, $cur, array(), $auth ); echo "$ph #$i HTTP $c " . substr( $raw, 0, 260 ) . "\n"; if ( empty( $j['success'] ) || ! empty( $j['data']['done'] ) ) { break; } $cur = $j['data']['cursor']; }
	}
	echo "status now: " . eng()->get_run( $run )['status'] . "\n";
}

if ( 'dbg3' === $stage ) {
	$B = B(); $auth = make_auth( 1 ); $id = $B['ids']['qa-old.bmp'];
	echo "BASELINE meta: " . $B['snap']['att'][ $id ]['meta'] . "\n";
	foreach ( $B['snap']['tree'] as $k => $h ) { if ( false !== strpos( $k, 'qa-old' ) ) { echo "  base $k $h\n"; } }
	$run = pipeline( $auth ); echo "AFTER CONVERT:\n"; foreach ( tree() as $k => $h ) { if ( false !== strpos( $k, 'qa-old' ) ) { echo "  $k $h\n"; } }
	$root = eng()->backup_root(); echo "BACKUP:\n"; foreach ( glob( $root . '/' . $run . '/uploads/2026/09/qa-old*' ) as $f ) { echo '  ' . basename( $f ) . ' ' . md5_file( $f ) . "\n"; }
	echo "snap file: " . substr( file_get_contents( glob( $root . '/' . $run . '/snap-*.json' )[0] ), 0, 0 ) . "\n";
	restore_http( $run, $auth ); echo "AFTER RESTORE:\n"; foreach ( tree() as $k => $h ) { if ( false !== strpos( $k, 'qa-old' ) ) { echo "  $k $h\n"; } }
	eng()->delete_run( $run );
}

if ( 'demo' === $stage ) {
	activate_plugin( 'mmseo-webp-converter/mmseo-webp-converter.php' );
	eng()->save_settings( array( 'quality' => 80, 'delete' => 1, 'auto' => 0, 'redirect' => 1, 'purge' => 0 ) );
	$B = B(); $auth = make_auth( 1 );
	$r1 = pipeline( $auth ); restore_http( $r1, $auth ); sleep( 2 ); $r2 = pipeline( $auth );
	echo "demo runs: $r1 (restored), $r2 (done)\n";
}
echo "\nTOTAL FAILS: $FAILS\n";
