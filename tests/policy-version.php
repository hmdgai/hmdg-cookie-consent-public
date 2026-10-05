<?php
/**
 * Regression tests for the v2.0.5 consent-wording revision.
 *
 * Run:  php tests/policy-version.php
 *
 * WHAT IT GUARDS
 *   Consents stored under the old banner wording carry the bare site Policy
 *   Version ('1'). The effective version must never equal that, or visitors
 *   who agreed to the old text keep their consent and are never shown the
 *   ads-personalisation disclosure. A site's own bump must still re-prompt.
 *   Both places the browser receives the version must use the same value, or
 *   the head script and the banner script would disagree about a consent.
 */

error_reporting( E_ALL );

function is_admin() { return false; }
function wp_doing_cron() { return false; }
function add_action( $hook, $cb, $priority = 10, $args = 1 ) { return true; }
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { return true; }
function get_option( $name, $default = false ) { return $default; }

define( 'ABSPATH', sys_get_temp_dir() . '/' );

$failures = 0;
function check( $label, $ok ) {
	global $failures;
	if ( $ok ) { echo "PASS  $label\n"; } else { $failures++; echo "FAIL  $label\n"; }
}

require __DIR__ . '/../hmdg-cookie-consent.php';

$v = 'HMDG_Cookie_Consent::effective_policy_version';

check( 'revision constant is 2',                    HMDG_CCM_CONSENT_REVISION === '2' );
check( "default site version gives '1-r2'",         $v( '1' ) === '1-r2' );
check( "empty site version is treated as '1'",      $v( '' ) === '1-r2' );
check( 'old-wording consent (policyVersion 1) is stale', $v( '1' ) !== '1' );
check( 'a site bump still changes the version',     $v( '2' ) !== $v( '1' ) );
check( 'a site bump is kept in the value',          $v( '2' ) === '2-r2' );

$src = file_get_contents( __DIR__ . '/../hmdg-cookie-consent.php' );
check( 'head script uses the effective version',
	strpos( $src, "\$pol_ver = esc_js( self::effective_policy_version( \$this->opt('policy_version') ) );" ) !== false );
check( 'banner config uses the effective version',
	strpos( $src, "'policyVersion'           => self::effective_policy_version( \$this->opt('policy_version') )," ) !== false );
check( 'no code path still sends the bare site version',
	strpos( $src, "\$this->opt('policy_version') ?: '1'" ) === false );
check( 'banner script ignores a consent from another policy version',
	strpos( $src, "if (c && c.policyVersion && c.policyVersion !== POLICY_VER) return null;" ) !== false );

echo $failures === 0 ? "\nOK — all assertions passed\n" : "\n$failures assertion(s) FAILED\n";
exit( $failures === 0 ? 0 : 1 );
