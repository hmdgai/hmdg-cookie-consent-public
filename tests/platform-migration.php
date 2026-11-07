<?php
/**
 * Regression tests for the v2.0.13 booking-platform additions.
 *
 * Run:  php tests/platform-migration.php
 *
 * WHAT IT GUARDS
 *   Sites with saved settings keep their own enabled_platforms list, so a platform added to the
 *   registry would never reach them. migrate_new_platforms() switches each newly added registry
 *   platform on exactly once per site, and never again after an admin turns it off. The record of
 *   offered platforms lives in its own option because saving the settings page rebuilds the blob.
 */

error_reporting( E_ALL );

function is_admin() { return false; }
function wp_doing_cron() { return false; }
function add_action( $hook, $cb, $priority = 10, $args = 1 ) { return true; }
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { return true; }

$GLOBALS['__options'] = [];
$GLOBALS['__writes']  = 0;
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $name ] : $default;
}
function update_option( $name, $value = null, $autoload = null ) {
	$GLOBALS['__options'][ $name ] = $value;
	$GLOBALS['__writes']++;
	return true;
}
function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['__options'] ) ) return false;
	return update_option( $name, $value, $autoload );
}

define( 'ABSPATH', sys_get_temp_dir() . '/' );

$failures = 0;
function check( $label, $ok ) {
	global $failures;
	if ( $ok ) { echo "PASS  $label\n"; } else { $failures++; echo "FAIL  $label\n"; }
}

require __DIR__ . '/../hmdg-cookie-consent.php';

const SETTINGS_OPT = 'hmdg_ccm_options';
const SEEN_OPT     = 'hmdg_ccm_platforms_offered';
const ORIGINAL     = 'cliniko,calendly,acuity,pracsuite,phorest,youcanbook,jane,timely,simplybook';
$added = [ 'practicehub', 'tm3', 'nookal', 'gensolve', 'zanda' ];
$rc    = new ReflectionClass( 'HMDG_Cookie_Consent' );
$reg   = $rc->getConstant( 'PLATFORM_REGISTRY' );
$dm    = $rc->getMethod( 'defaults' );
if ( PHP_VERSION_ID < 80100 ) $dm->setAccessible( true );
$plats = function () { return explode( ',', get_option( SETTINGS_OPT )['enabled_platforms'] ?? '' ); };

/* -- registry ------------------------------------------------------------- */

foreach ( $added as $k ) {
	check( "registry has $k", isset( $reg[ $k ] ) && ! empty( $reg[ $k ]['domains'] ) );
}
check( 'PracticeHub domain', in_array( 'practicehub.io', $reg['practicehub']['domains'] ?? [], true ) );
check( 'TM3 domain', in_array( 'tm3app.com', $reg['tm3']['domains'] ?? [], true ) );
check( 'Nookal domain', in_array( 'nookal.com', $reg['nookal']['domains'] ?? [], true ) );
check( 'Gensolve domain', in_array( 'gensolve.com', $reg['gensolve']['domains'] ?? [], true ) );
check( 'Zanda domains include Power Diary', in_array( 'zandahealth.com', $reg['zanda']['domains'] ?? [], true )
	&& in_array( 'powerdiary.com', $reg['zanda']['domains'] ?? [], true ) );
check( 'Acuity domains include as.me short links', in_array( 'as.me', $reg['acuity']['domains'], true ) );
check( 'Jane domains include janeapp.co.uk', in_array( 'janeapp.co.uk', $reg['jane']['domains'], true ) );
foreach ( $added as $k ) {
	check( "$k has no completion matcher yet (never fires)", ( $reg[ $k ]['postmessage'] ?? null ) === 'false' );
}
$defaults = explode( ',', $dm->invoke( null )['enabled_platforms'] );
check( 'defaults enable the new platforms', ! array_diff( $added, $defaults ) );

/* -- fresh site: no saved settings, defaults apply ------------------------ */

HMDG_Cookie_Consent::migrate_new_platforms();
check( 'fresh site: settings blob not created', ! array_key_exists( SETTINGS_OPT, $GLOBALS['__options'] ) );
check( 'fresh site: every registry platform recorded as offered',
	! array_diff( array_keys( $reg ), (array) get_option( SEEN_OPT, [] ) ) );

/* -- saved site on the original nine: new platforms switched on once ------ */

$GLOBALS['__options'] = [ SETTINGS_OPT => [ 'gtm_id' => 'GTM-TEST', 'enabled_platforms' => ORIGINAL ] ];
HMDG_Cookie_Consent::migrate_new_platforms();
check( 'saved site: new platforms enabled', ! array_diff( $added, $plats() ) );
check( 'saved site: original platforms kept', ! array_diff( explode( ',', ORIGINAL ), $plats() ) );
check( 'saved site: other settings untouched', ( get_option( SETTINGS_OPT )['gtm_id'] ?? '' ) === 'GTM-TEST' );

$w = $GLOBALS['__writes'];
HMDG_Cookie_Consent::migrate_new_platforms();
check( 'second run writes nothing', $GLOBALS['__writes'] === $w );

/* -- admin turns one off, and the settings save rebuilds the blob ---------- */

$GLOBALS['__options'][ SETTINGS_OPT ] = [ 'enabled_platforms' => 'cliniko,jane,tm3' ];
HMDG_Cookie_Consent::migrate_new_platforms();
check( 'admin choice respected: PracticeHub stays off', ! in_array( 'practicehub', $plats(), true ) );
check( 'admin choice respected: list unchanged', get_option( SETTINGS_OPT )['enabled_platforms'] === 'cliniko,jane,tm3' );

/* -- a site that had turned some original platforms off ------------------- */

$GLOBALS['__options'] = [ SETTINGS_OPT => [ 'enabled_platforms' => 'cliniko' ] ];
HMDG_Cookie_Consent::migrate_new_platforms();
check( 'original platforms an admin removed stay removed', ! in_array( 'calendly', $plats(), true ) );
check( 'new platforms still offered once', ! array_diff( $added, $plats() ) );

/* -- saved settings without the key: defaults already include them -------- */

$GLOBALS['__options'] = [ SETTINGS_OPT => [ 'gtm_id' => 'GTM-X' ] ];
HMDG_Cookie_Consent::migrate_new_platforms();
check( 'no stored list: blob not given one', ! isset( get_option( SETTINGS_OPT )['enabled_platforms'] ) );

echo $failures === 0 ? "\nOK — all assertions passed\n" : "\n$failures assertion(s) FAILED\n";
exit( $failures === 0 ? 0 : 1 );
