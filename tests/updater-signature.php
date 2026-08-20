<?php
/**
 * Regression tests for the v1.7.0 signed-release verification.
 *
 * Run:  php -d extension=sodium tests/updater-signature.php
 * (CI sets up PHP with sodium; locally the flag enables the bundled DLL.)
 *
 * WHAT IT GUARDS
 *   The updater must refuse to hand WordPress any package that is not ours to
 *   judge, and must refuse OURS unless the release's detached ed25519
 *   signature verifies against the embedded public key. Fail closed on every
 *   path: missing signature asset, unreachable signature, garbage signature,
 *   missing sodium, tampered zip. The cost of failing closed is a loud
 *   WP_Error and no update; the cost of failing open is arbitrary code on
 *   every site running the plugin.
 */

error_reporting( E_ALL );

/* ---------------------------------------------------------- WP shims ---- */

$GLOBALS['__transients'] = [];
$GLOBALS['__sig_body']   = '';
$GLOBALS['__sig_status'] = 200;

function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { return true; }
function add_action( $hook, $cb, $priority = 10, $args = 1 ) { return true; }
function plugin_basename( $file ) { return 'hmdg-cookie-consent/hmdg-cookie-consent.php'; }
function get_transient( $key ) { return $GLOBALS['__transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['__transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['__transients'][ $key ] ); return true; }

class WP_Error {
	public $code; public $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

// download_url() exists, so the updater's require_once of file.php is skipped.
function download_url( $url, $timeout = 300 ) {
	$tmp = tempnam( sys_get_temp_dir(), 'hmdgtest' );
	file_put_contents( $tmp, $GLOBALS['__package_bytes'] );
	return $tmp;
}
function wp_remote_get( $url, $args = [] ) { return [ 'body' => $GLOBALS['__sig_body'], 'code' => $GLOBALS['__sig_status'] ]; }
function wp_remote_retrieve_response_code( $res ) { return is_array( $res ) ? $res['code'] : 0; }
function wp_remote_retrieve_body( $res ) { return is_array( $res ) ? $res['body'] : ''; }

define( 'ABSPATH', sys_get_temp_dir() . '/' );
if ( ! defined( 'HOUR_IN_SECONDS' ) ) define( 'HOUR_IN_SECONDS', 3600 );

/* ------------------------------------------------------------ harness --- */

$failures = 0;
function check( $label, $ok ) {
	global $failures;
	if ( $ok ) { echo "PASS  $label\n"; } else { $failures++; echo "FAIL  $label\n"; }
}

require __DIR__ . '/../includes/class-hmdg-updater.php';

if ( ! function_exists( 'sodium_crypto_sign_seed_keypair' ) ) {
	// verify_bytes must fail CLOSED without sodium — that is itself the test.
	check( 'no sodium -> verify_bytes refuses',
		HMDG_GitHub_Updater::verify_bytes( 'x', base64_encode( str_repeat( 's', 64 ) ),
		                                   base64_encode( str_repeat( 'p', 32 ) ) ) === false );
	echo "\nNOTE: sodium unavailable — crypto and plumbing tests skipped. "
	   . "Run with -d extension=sodium for the full suite; CI always does.\n";
	exit( $failures === 0 ? 0 : 1 );
}

/* ---------------------------------------------- verify_bytes, the core -- */

$seed = random_bytes( 32 );
$kp   = sodium_crypto_sign_seed_keypair( $seed );
$pub  = base64_encode( sodium_crypto_sign_publickey( $kp ) );
$zip  = random_bytes( 4096 ) . 'pretend this is a zip';
$sig  = base64_encode( sodium_crypto_sign_detached( $zip, sodium_crypto_sign_secretkey( $kp ) ) );

check( 'good signature verifies',
	HMDG_GitHub_Updater::verify_bytes( $zip, $sig, $pub ) === true );
check( 'tampered zip refused',
	HMDG_GitHub_Updater::verify_bytes( $zip . 'x', $sig, $pub ) === false );
check( 'wrong key refused',
	HMDG_GitHub_Updater::verify_bytes( $zip, $sig,
		base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_seed_keypair( random_bytes( 32 ) ) ) ) ) === false );
check( 'garbage signature refused',
	HMDG_GitHub_Updater::verify_bytes( $zip, '!!!not-base64!!!', $pub ) === false );
check( 'truncated signature refused',
	HMDG_GitHub_Updater::verify_bytes( $zip, base64_encode( 'short' ), $pub ) === false );

/* -------------------------------------- verify_download, the plumbing --- */
/* The updater instance uses the REAL embedded PUBLIC_KEY, which this test
 * does not hold the seed for — so a "valid" signature from the throwaway key
 * above must be refused by the full path. That asymmetry is used below. */

$u = new HMDG_GitHub_Updater( [
	'slug' => 'hmdg-cookie-consent', 'repo' => 'hmdgai/hmdg-cookie-consent-public',
	'token' => '', 'version' => '1.7.0',
	'plugin_file' => __DIR__ . '/../hmdg-cookie-consent.php',
] );

$cache_key = 'hmdg_updater_' . md5( 'hmdgai/hmdg-cookie-consent-public' );
$GLOBALS['__package_bytes'] = $zip;

// A reply someone else already produced is returned untouched.
check( 'existing reply passes through',
	$u->verify_download( '/somewhere/file.zip', 'https://github.com/hmdgai/hmdg-cookie-consent-public/releases/download/v9/x.zip' ) === '/somewhere/file.zip' );

// A package that is not ours is not intercepted.
check( 'foreign package untouched',
	$u->verify_download( false, 'https://github.com/someone-else/other-plugin/releases/download/v1/x.zip' ) === false );

// Ours, but the release carries no .sig asset: refused, fail closed.
$GLOBALS['__transients'][ $cache_key ] = (object) [
	'tag_name' => 'v9.9.9',
	'assets'   => [ (object) [ 'name' => 'hmdg-cookie-consent.zip',
	                           'browser_download_url' => 'https://github.com/hmdgai/hmdg-cookie-consent-public/releases/download/v9.9.9/hmdg-cookie-consent.zip' ] ],
];
$r = $u->verify_download( false, 'https://github.com/hmdgai/hmdg-cookie-consent-public/releases/download/v9.9.9/hmdg-cookie-consent.zip' );
check( 'unsigned release refused',
	is_wp_error( $r ) && $r->get_error_code() === 'hmdg_unsigned_release' );

// Ours, with a .sig asset, but signed by a key that is NOT the embedded one:
// refused. This is the compromised-channel case, end to end.
// (clear_cache() first: the updater memoises the release per instance, so the
// no-sig release above would otherwise stick for the rest of the run.)
$u->clear_cache();
$GLOBALS['__transients'][ $cache_key ] = (object) [
	'tag_name' => 'v9.9.9',
	'assets'   => [
		(object) [ 'name' => 'hmdg-cookie-consent.zip',
		           'browser_download_url' => 'https://github.com/hmdgai/hmdg-cookie-consent-public/releases/download/v9.9.9/hmdg-cookie-consent.zip' ],
		(object) [ 'name' => 'hmdg-cookie-consent.zip.sig',
		           'browser_download_url' => 'https://github.com/hmdgai/hmdg-cookie-consent-public/releases/download/v9.9.9/hmdg-cookie-consent.zip.sig' ],
	],
];
$GLOBALS['__sig_body'] = $sig; // valid ed25519, wrong key
$r = $u->verify_download( false, 'https://github.com/hmdgai/hmdg-cookie-consent-public/releases/download/v9.9.9/hmdg-cookie-consent.zip' );
check( 'wrong-key signature refused by the full path',
	is_wp_error( $r ) && $r->get_error_code() === 'hmdg_bad_signature' );

// Signature endpoint down: refused, not waved through.
$GLOBALS['__sig_body'] = ''; $GLOBALS['__sig_status'] = 404;
$r = $u->verify_download( false, 'https://github.com/hmdgai/hmdg-cookie-consent-public/releases/download/v9.9.9/hmdg-cookie-consent.zip' );
check( 'unreachable signature refused',
	is_wp_error( $r ) && $r->get_error_code() === 'hmdg_bad_signature' );

echo $failures === 0 ? "\nOK — all assertions passed\n" : "\n$failures assertion(s) FAILED\n";
exit( $failures === 0 ? 0 : 1 );
