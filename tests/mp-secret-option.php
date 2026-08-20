<?php
/**
 * Regression tests for the v1.7.0 MP-secret option split.
 *
 * Run:  php tests/mp-secret-option.php
 *
 * WHAT IT GUARDS
 *   The GA4 MP API secret must live in its own NON-AUTOLOADED option, a
 *   pre-1.7.0 value must migrate across exactly once, and the settings blob
 *   must end up holding '' for the legacy key. The autoload flag is the whole
 *   point of the change, so it is asserted, not assumed.
 */

error_reporting( E_ALL );

/* ---------------------------------------------------------- WP shims ---- */

function is_admin() { return false; }
function wp_doing_cron() { return false; }

$GLOBALS['__options']  = [];   // name => value
$GLOBALS['__autoload'] = [];   // name => autoload flag given at creation
$GLOBALS['__writes']   = 0;

function add_action( $hook, $cb, $priority = 10, $args = 1 ) { return true; }
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { return true; }

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['__options'] ) ) return false;
	$GLOBALS['__options'][ $name ]  = $value;
	$GLOBALS['__autoload'][ $name ] = $autoload;
	$GLOBALS['__writes']++;
	return true;
}
function update_option( $name, $value = null, $autoload = null ) {
	if ( ! array_key_exists( $name, $GLOBALS['__options'] ) ) {
		// WP would create it AUTOLOADED here — recorded so a test can catch a
		// code path that lets that happen to the secret.
		$GLOBALS['__autoload'][ $name ] = 'yes-implicit';
	}
	$GLOBALS['__options'][ $name ] = $value;
	$GLOBALS['__writes']++;
	return true;
}

define( 'ABSPATH', sys_get_temp_dir() . '/' );

/* ------------------------------------------------------------ harness --- */

$failures = 0;
function check( $label, $ok ) {
	global $failures;
	if ( $ok ) { echo "PASS  $label\n"; } else { $failures++; echo "FAIL  $label\n"; }
}

require __DIR__ . '/../hmdg-cookie-consent.php';

const SECRET_OPT   = 'hmdg_ccm_ga4_api_secret';
const SETTINGS_OPT = 'hmdg_ccm_options';

/* -- fresh site: nothing to migrate, getter is empty ---------------------- */

HMDG_Cookie_Consent::migrate_mp_secret();
check( 'fresh site: migrate is a no-op',        $GLOBALS['__writes'] === 0 );
check( 'fresh site: getter returns empty',      HMDG_Cookie_Consent::get_mp_secret() === '' );

/* -- setter creates the option NON-autoloaded ----------------------------- */

HMDG_Cookie_Consent::set_mp_secret( 'first-secret-value-abcdef' );
check( 'setter stores the value',
	get_option( SECRET_OPT ) === 'first-secret-value-abcdef' );
check( 'setter creates the option with autoload = no',
	( $GLOBALS['__autoload'][ SECRET_OPT ] ?? '' ) === 'no' );

HMDG_Cookie_Consent::set_mp_secret( 'second-secret-value-ghijkl' );
check( 'setter updates in place',
	get_option( SECRET_OPT ) === 'second-secret-value-ghijkl' );

/* -- migration from a pre-1.7.0 blob -------------------------------------- */

$GLOBALS['__options']  = [];
$GLOBALS['__autoload'] = [];
$GLOBALS['__writes']   = 0;
$GLOBALS['__options'][ SETTINGS_OPT ] = [
	'gtm_id'         => 'GTM-TEST123',
	'ga4_api_secret' => 'legacy-secret-from-1-6',
	'debug_mode'     => 0,
];

HMDG_Cookie_Consent::migrate_mp_secret();

check( 'migration moves the legacy value',
	HMDG_Cookie_Consent::get_mp_secret() === 'legacy-secret-from-1-6' );
check( 'migrated option is NON-autoloaded',
	( $GLOBALS['__autoload'][ SECRET_OPT ] ?? '' ) === 'no' );
$blob = get_option( SETTINGS_OPT );
check( 'settings blob now holds an empty string for the legacy key',
	( $blob['ga4_api_secret'] ?? 'MISSING' ) === '' );
check( 'other settings survive the migration untouched',
	( $blob['gtm_id'] ?? '' ) === 'GTM-TEST123' );

$writes_after_first = $GLOBALS['__writes'];
HMDG_Cookie_Consent::migrate_mp_secret();
check( 'second migrate run writes nothing (idempotent)',
	$GLOBALS['__writes'] === $writes_after_first );

/* -- both present: the new option wins, blob still cleaned ---------------- */

$GLOBALS['__options'][ SETTINGS_OPT ]['ga4_api_secret'] = 'stale-copy-from-a-restore';
HMDG_Cookie_Consent::migrate_mp_secret();
check( 'new option wins over a reappearing legacy copy',
	HMDG_Cookie_Consent::get_mp_secret() === 'legacy-secret-from-1-6' );
check( 'reappearing legacy copy is cleaned out again',
	( get_option( SETTINGS_OPT )['ga4_api_secret'] ?? 'MISSING' ) === '' );

echo $failures === 0 ? "\nOK — all assertions passed\n" : "\n$failures assertion(s) FAILED\n";
exit( $failures === 0 ? 0 : 1 );
