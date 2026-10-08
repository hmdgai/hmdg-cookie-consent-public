<?php
/**
 * Regression tests for v2.0.6: consents given before the 2.0.4 wording are kept.
 *
 * Run:  php tests/legacy-consent.php
 *
 * WHAT IT GUARDS
 *   2.0.5 deleted every consent stamped with the bare site Policy Version ('1'). 2.0.6 keeps
 *   them, honours analytics and functional, and withholds marketing. The page must therefore
 *   carry the site's own version next to the effective one, in both the head script and the
 *   banner config, and the head script's legacy branch must never grant an ad signal.
 *
 *   The behaviour itself (cookie kept, signals, banner, modal pre-fill, head script stripped)
 *   is exercised in a real browser against this rendered output before each release; this file
 *   pins the rendered contract so a later edit cannot silently drop it.
 */

error_reporting( E_ALL );
define( 'ABSPATH', sys_get_temp_dir() . '/' );

$GLOBALS['__opts']   = [ 'hmdg_ccm_options' => [ 'gtm_id' => 'GTM-TEST123', 'policy_version' => '' ] ];
$GLOBALS['__inline'] = '';
function is_admin() { return false; }
function wp_doing_cron() { return false; }
function add_action() { return true; }
function add_filter() { return true; }
function register_activation_hook() {}
function register_deactivation_hook() {}
function get_option( $n, $d = false ) { return $GLOBALS['__opts'][ $n ] ?? $d; }
function add_option( $n, $v = '' ) { $GLOBALS['__opts'][ $n ] = $v; return true; }
function update_option( $n, $v = null ) { $GLOBALS['__opts'][ $n ] = $v; return true; }
function wp_parse_args( $a, $d = [] ) { return array_merge( $d, (array) $a ); }
function esc_js( $s ) { return addslashes( (string) $s ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . $p; }
function wp_create_nonce() { return 'nonce'; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_register_style() {}
function wp_enqueue_style() {}
function wp_register_script() {}
function wp_enqueue_script() {}
function wp_add_inline_style() {}
function wp_add_inline_script( $h, $js ) { $GLOBALS['__inline'] .= $js; }

$failures = 0;
function check( $label, $ok ) {
	global $failures;
	if ( $ok ) { echo "PASS  $label\n"; } else { $failures++; echo "FAIL  $label\n"; }
}

require __DIR__ . '/../hmdg-cookie-consent.php';

check( "site version defaults to '1'", HMDG_Cookie_Consent::site_policy_version( '' ) === '1' );
check( 'site version keeps a raised value', HMDG_Cookie_Consent::site_policy_version( '2' ) === '2' );
check( 'effective version is site version plus revision',
	HMDG_Cookie_Consent::effective_policy_version( '' ) === HMDG_Cookie_Consent::site_policy_version( '' ) . '-r' . HMDG_CCM_CONSENT_REVISION );
check( 'consent revision is still 2', HMDG_CCM_CONSENT_REVISION === '2' );

$p = HMDG_Cookie_Consent::instance();
ob_start();
$p->output_consent_defaults();
$head = ob_get_clean();
$p->enqueue_assets();
$js = $GLOBALS['__inline'];

check( 'head script carries the effective version', strpos( $head, "POLICY_VER='1-r2'" ) !== false );
check( 'head script carries the site version', strpos( $head, "SITE_VER='1'" ) !== false );

$cfg = null;
if ( preg_match( '/var hmdgCCM=(\{.*?\});\n/', $js, $m ) ) { $cfg = json_decode( $m[1], true ); }
check( 'banner config carries policyVersion 1-r2', ( $cfg['policyVersion'] ?? null ) === '1-r2' );
check( 'banner config carries sitePolicyVersion 1', ( $cfg['sitePolicyVersion'] ?? null ) === '1' );

// The legacy branch: from the SITE_VER test to its return, no ad signal may appear.
$a = strpos( $head, 'String(c.policyVersion)===SITE_VER' );
$b = $a === false ? false : strpos( $head, 'return;', $a );
$branch = ( $a !== false && $b !== false ) ? substr( $head, $a, $b - $a ) : '';
check( 'head script has a legacy branch', $branch !== '' );
check( 'legacy branch restores analytics', strpos( $branch, 'analytics_storage' ) !== false );
check( 'legacy branch restores functional', strpos( $branch, 'functionality_storage' ) !== false );
check( 'legacy branch grants no ad signal',
	strpos( $branch, 'ad_storage' ) === false && strpos( $branch, 'ad_user_data' ) === false && strpos( $branch, 'ad_personalization' ) === false );
check( 'legacy branch does not delete the cookie', strpos( $branch, 'expires=' ) === false );
check( 'other mismatches are still cleared', strpos( $head, "Policy version changed — clearing consent." ) !== false );

check( 'readCookie withholds marketing from a legacy consent',
	strpos( $js, 'l.legacyMarketing = !!c.marketing;' ) !== false && strpos( $js, 'l.marketing = false;' ) !== false );
check( 'boot asks again only via needsChoice()', strpos( $js, 'if (needsChoice(saved)) { setTimeout(showBanner, 300);' ) !== false );
check( 'no close handler still uses the pre-2.0.6 banner test', strpos( $js, 'if(!readCookie())showBanner()' ) === false );

echo $failures === 0 ? "\nOK — all assertions passed\n" : "\n$failures assertion(s) FAILED\n";
exit( $failures === 0 ? 0 : 1 );
