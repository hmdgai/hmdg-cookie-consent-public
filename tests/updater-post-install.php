<?php
/**
 * Regression tests for the updater's upgrader_post_install hook.
 *
 * Run:  php tests/updater-post-install.php
 *
 * WHAT IT GUARDS
 *   post_install() exists to rename a release that unpacked under the wrong
 *   folder name into the plugin's own folder. WordPress core passes
 *   $result['destination'] WITH a trailing slash
 *   (wp-admin/includes/class-wp-upgrader.php, install_package()). Up to
 *   v2.0.2 the hook compared that path to one WITHOUT the slash, concluded the
 *   folder was wrong on every update, deleted the folder WordPress had just
 *   installed, failed to move the (now missing) source, and still reported
 *   success -- so WordPress discarded its backup instead of restoring it.
 *   Every automatic update removed the plugin from the site.
 *
 *   These cases run against a real temporary directory, so they assert what
 *   is actually left on disk, not what a mock was told.
 */

error_reporting( E_ALL );

/* ---------------------------------------------------------- WP shims ---- */

$GLOBALS['__activated'] = [];

function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { return true; }
function add_action( $hook, $cb, $priority = 10, $args = 1 ) { return true; }
function plugin_basename( $file ) { return 'hmdg-cookie-consent/hmdg-cookie-consent.php'; }
function get_transient( $key ) { return false; }
function set_transient( $key, $value, $ttl = 0 ) { return true; }
function delete_transient( $key ) { return true; }
function activate_plugin( $plugin ) { $GLOBALS['__activated'][] = $plugin; return null; }
function trailingslashit( $s ) { return untrailingslashit( $s ) . '/'; }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }

class WP_Error {
	public $code; public $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

/**
 * The subset of WP_Filesystem_Direct the hook uses, on the real disk and with
 * the same semantics: move() refuses an existing destination unless told to
 * overwrite, and returns false on failure rather than throwing.
 */
class Test_Filesystem {
	public $fail_moves = false;
	public function exists( $path ) { return file_exists( $path ); }
	public function delete( $path, $recursive = false ) {
		$path = untrailingslashit( $path );
		if ( ! file_exists( $path ) ) return false;
		if ( is_file( $path ) ) return unlink( $path );
		foreach ( array_diff( scandir( $path ), [ '.', '..' ] ) as $f ) {
			$this->delete( $path . '/' . $f, true );
		}
		return rmdir( $path );
	}
	public function move( $source, $destination, $overwrite = false ) {
		if ( $this->fail_moves ) return false;
		$source = untrailingslashit( $source ); $destination = untrailingslashit( $destination );
		if ( ! $overwrite && file_exists( $destination ) ) return false;
		return @rename( $source, $destination );
	}
}

$root = sys_get_temp_dir() . '/hmdg-post-install-' . bin2hex( random_bytes( 4 ) );
define( 'WP_PLUGIN_DIR', $root . '/plugins' );
define( 'ABSPATH', $root . '/' );

/* ------------------------------------------------------------ harness --- */

$failures = 0;
function check( $label, $ok ) {
	global $failures;
	if ( $ok ) { echo "PASS  $label\n"; } else { $failures++; echo "FAIL  $label\n"; }
}

/** A plugin folder holding one marker file, so "it survived" means "its files did". */
function make_plugin_dir( $dir, $marker ) {
	@mkdir( $dir, 0777, true );
	file_put_contents( $dir . '/hmdg-cookie-consent.php', $marker );
}
function marker_in( $dir ) {
	$f = untrailingslashit( $dir ) . '/hmdg-cookie-consent.php';
	return is_file( $f ) ? file_get_contents( $f ) : null;
}
function reset_plugins() {
	global $wp_filesystem;
	$wp_filesystem->delete( WP_PLUGIN_DIR, true );
	mkdir( WP_PLUGIN_DIR, 0777, true );
	$GLOBALS['__activated'] = [];
	$wp_filesystem->fail_moves = false;
}

require __DIR__ . '/../includes/class-hmdg-updater.php';

$GLOBALS['wp_filesystem'] = new Test_Filesystem();
$u = new HMDG_GitHub_Updater( [
	'slug'        => 'hmdg-cookie-consent',
	'repo'        => 'example/repo',
	'version'     => '2.0.2',
	'plugin_file' => '/x/hmdg-cookie-consent/hmdg-cookie-consent.php',
] );

$ours     = [ 'plugin' => 'hmdg-cookie-consent/hmdg-cookie-consent.php', 'type' => 'plugin', 'action' => 'update' ];
$expected = WP_PLUGIN_DIR . '/hmdg-cookie-consent';

/* ---- 1. THE BUG: installed in the right folder, destination has a slash -- */

reset_plugins();
make_plugin_dir( $expected, 'NEW' );
$r = $u->post_install( true, $ours, [ 'destination' => $expected . '/', 'destination_name' => 'hmdg-cookie-consent' ] );
check( 'right folder + trailing slash: the new copy is still on disk', marker_in( $expected ) === 'NEW' );
check( 'right folder + trailing slash: no error returned', ! is_wp_error( $r ) );
check( 'right folder + trailing slash: plugin re-activated', $GLOBALS['__activated'] === [ 'hmdg-cookie-consent/hmdg-cookie-consent.php' ] );

/* ---- 2. The same without the slash -------------------------------------- */

reset_plugins();
make_plugin_dir( $expected, 'NEW' );
$r = $u->post_install( true, $ours, [ 'destination' => $expected, 'destination_name' => 'hmdg-cookie-consent' ] );
check( 'right folder, no slash: the new copy is still on disk', marker_in( $expected ) === 'NEW' );
check( 'right folder, no slash: no error returned', ! is_wp_error( $r ) );

/* ---- 3. Genuinely wrong folder name: move it into place ------------------ */

reset_plugins();
$wrong = WP_PLUGIN_DIR . '/hmdg-cookie-consent-2.0.3';
make_plugin_dir( $wrong, 'NEW' );
$r = $u->post_install( true, $ours, [ 'destination' => $wrong . '/', 'destination_name' => 'hmdg-cookie-consent-2.0.3' ] );
check( 'wrong folder: new copy moved into the plugin folder', marker_in( $expected ) === 'NEW' );
check( 'wrong folder: the misnamed folder is gone', ! file_exists( $wrong ) );
check( 'wrong folder: result points at the plugin folder',
	is_array( $r ) && untrailingslashit( $r['destination'] ) === $expected && $r['destination_name'] === 'hmdg-cookie-consent' );

/* ---- 4. Wrong folder while an old copy still sits in the right one -------- */

reset_plugins();
make_plugin_dir( $expected, 'OLD' );
make_plugin_dir( $wrong, 'NEW' );
$r = $u->post_install( true, $ours, [ 'destination' => $wrong . '/', 'destination_name' => 'hmdg-cookie-consent-2.0.3' ] );
check( 'wrong folder over an old copy: new copy replaces it', marker_in( $expected ) === 'NEW' );
check( 'wrong folder over an old copy: no error returned', ! is_wp_error( $r ) );

/* ---- 5. A failed move is reported, so WordPress restores its backup ------- */

reset_plugins();
make_plugin_dir( $wrong, 'NEW' );
$wp_filesystem->fail_moves = true;
$r = $u->post_install( true, $ours, [ 'destination' => $wrong . '/', 'destination_name' => 'hmdg-cookie-consent-2.0.3' ] );
check( 'failed move: returns a WP_Error', is_wp_error( $r ) );
check( 'failed move: does not activate a plugin that is not in place', $GLOBALS['__activated'] === [] );

/* ---- 6. Somebody else's plugin is none of our business -------------------- */

reset_plugins();
make_plugin_dir( WP_PLUGIN_DIR . '/other', 'OTHER' );
$theirs = [ 'destination' => WP_PLUGIN_DIR . '/other/', 'destination_name' => 'other' ];
$r = $u->post_install( true, [ 'plugin' => 'other/other.php', 'type' => 'plugin', 'action' => 'update' ], $theirs );
check( 'other plugin: result passed through untouched', $r === $theirs );
check( 'other plugin: its folder untouched', marker_in( WP_PLUGIN_DIR . '/other' ) === 'OTHER' );
check( 'other plugin: nothing activated', $GLOBALS['__activated'] === [] );

/* ---- 7. Fresh installs carry no "plugin" key and are left alone ----------- */

reset_plugins();
make_plugin_dir( $expected, 'NEW' );
$fresh = [ 'destination' => $expected . '/', 'destination_name' => 'hmdg-cookie-consent' ];
$r = $u->post_install( true, [ 'type' => 'plugin', 'action' => 'install' ], $fresh );
check( 'fresh install: result passed through untouched', $r === $fresh );
check( 'fresh install: files untouched', marker_in( $expected ) === 'NEW' );

$wp_filesystem->delete( $root, true );

echo "\n" . ( $failures === 0 ? 'OK — all assertions passed' : "FAILED — $failures assertion(s)" ) . "\n";
exit( $failures === 0 ? 0 : 1 );
