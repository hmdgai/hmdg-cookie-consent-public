<?php
/**
 * Regression test for the v1.6.1 enrolment fix.
 *
 * Run:  php tests/verify-route-registration.php   (exit 0 = pass, 1 = fail)
 *
 * WHAT IT GUARDS
 *   The enrolment callback is an ordinary FRONT-END REST request:
 *   is_admin() false, wp_doing_cron() false, WP_CLI undefined. In v1.5.0 and
 *   v1.6.0 the /verify route was hooked only from HMDG_Config_Sync::init(),
 *   which the main file calls inside its admin/cron/CLI load gate — so the one
 *   caller /verify exists for was answered 404 and no site could ever enrol.
 *   Fail-soft hid it completely.
 *
 *   This test loads the real plugin files under exactly those front-end
 *   conditions, using WordPress shims, fires rest_api_init, and asserts the
 *   route is registered and behaves. It needs no WordPress install, so it can
 *   run anywhere PHP can — which is what lets it run before every release.
 *
 * NOT SHIPPED. release.yml copies an explicit allowlist into the zip
 * (hmdg-cookie-consent.php, includes/, README.md); tests/ is not in it.
 */

error_reporting( E_ALL );

/* ---------------------------------------------------------- WP shims ---- */

// Front-end conditions: the whole point of the test.
function is_admin() { return false; }
function wp_doing_cron() { return false; }
// WP_CLI deliberately not defined.

$GLOBALS['__actions'] = [];
$GLOBALS['__routes']  = [];
$GLOBALS['__options'] = [];
$GLOBALS['__writes']  = [];

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['__actions'][ $hook ][] = $callback;
	return true;
}

function do_action( $hook ) {
	foreach ( $GLOBALS['__actions'][ $hook ] ?? [] as $cb ) {
		call_user_func( $cb );
	}
}

function register_rest_route( $namespace, $route, $args = [] ) {
	$GLOBALS['__routes'][ $namespace . $route ] = $args;
	return true;
}

function get_option( $name, $default = false ) {
	return $GLOBALS['__options'][ $name ] ?? $default;
}

// /verify must never write. Any option write during the test is recorded and
// fails the read-only assertion below.
function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
	$GLOBALS['__writes'][]           = $name;
	$GLOBALS['__options'][ $name ]   = $value;
	return true;
}
function update_option( $name, $value = null, $autoload = null ) {
	$GLOBALS['__writes'][]           = $name;
	$GLOBALS['__options'][ $name ]   = $value;
	return true;
}

class WP_REST_Response {
	public $data;
	public $status;
	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
}

define( 'ABSPATH', sys_get_temp_dir() . '/' );

/* ------------------------------------------------------------ harness --- */

$failures = 0;
function check( $label, $ok ) {
	global $failures;
	if ( $ok ) {
		echo "PASS  $label\n";
	} else {
		$failures++;
		echo "FAIL  $label\n";
	}
}

/* ------------------------------------------------- load, as a page view -- */

require __DIR__ . '/../hmdg-cookie-consent.php';

// The load gate must still hold: on a front-end request the sync class stays
// unparsed until the REST API actually dispatches. This is the weight
// guarantee the gate exists for, and the fix must not have broken it.
check( 'config-sync class not loaded on a plain page view',
	! class_exists( 'HMDG_Config_Sync', false ) );

check( 'rest_api_init has a listener on a front-end request',
	! empty( $GLOBALS['__actions']['rest_api_init'] ) );

/* -------------------------------------------- dispatch, as the REST API -- */

do_action( 'rest_api_init' );

$route = $GLOBALS['__routes']['hmdg-ccm/v1/verify'] ?? null;

// THE regression assertion. If this fails, enrolment 404s on every site again.
check( '/verify is registered when rest_api_init fires on a front-end request',
	$route !== null );

check( '/verify is GET',
	$route !== null && ( $route['methods'] ?? '' ) === 'GET' );

check( '/verify is public (permission_callback __return_true)',
	$route !== null && ( $route['permission_callback'] ?? '' ) === '__return_true' );

/* --------------------------------------------------- callback behaviour -- */

if ( $route !== null && is_callable( $route['callback'] ?? null ) ) {
	// No key yet: the site has not run its first cron. 503, and NO write —
	// an unauthenticated GET must not be able to create an option.
	$res = call_user_func( $route['callback'] );
	check( 'no key -> 503', $res instanceof WP_REST_Response && $res->status === 503 );
	check( 'no key -> no option written (read-only endpoint)',
		$GLOBALS['__writes'] === [] );

	// With a key: 200 and the fingerprint is sha256 of the key, nothing else.
	$key = str_repeat( 'ab', 32 ); // 64 hex chars, as site_key() generates
	$GLOBALS['__options']['hmdg_ccm_site_key'] = $key;
	$res = call_user_func( $route['callback'] );
	check( 'key present -> 200', $res instanceof WP_REST_Response && $res->status === 200 );
	check( 'serves key_fingerprint = sha256(key)',
		$res instanceof WP_REST_Response
		&& ( $res->data['key_fingerprint'] ?? '' ) === hash( 'sha256', $key ) );
	check( 'serves the fingerprint alone (no other fields)',
		$res instanceof WP_REST_Response
		&& is_array( $res->data ) && array_keys( $res->data ) === [ 'key_fingerprint' ] );
	check( 'still no option written', $GLOBALS['__writes'] === [] );
} else {
	check( '/verify callback is callable', false );
}

echo $failures === 0 ? "\nOK — all assertions passed\n"
                     : "\n$failures assertion(s) FAILED\n";
exit( $failures === 0 ? 0 : 1 );
