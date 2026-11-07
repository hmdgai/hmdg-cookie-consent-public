<?php
/** Resync-only disclosure and footer-link regression checks. */
error_reporting( E_ALL );
define( 'ABSPATH', sys_get_temp_dir() . '/' );

$GLOBALS['test_host'] = 'resyncphysiotherapy.ie';
function is_admin() { return false; }
function wp_doing_cron() { return false; }
function add_action( $hook, $cb, $priority = 10, $args = 1 ) { return true; }
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { return true; }
function register_activation_hook() {}
function register_deactivation_hook() {}
function get_option( $name, $default = false ) { return $default; }
function update_option( $name, $value = null, $autoload = null ) { return true; }
function wp_parse_args( $a, $defaults = [] ) { return array_merge( $defaults, (array) $a ); }
function home_url( $path = '' ) { return 'https://' . $GLOBALS['test_host'] . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function esc_url( $value ) { return $value; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }

require __DIR__ . '/../hmdg-cookie-consent.php';

$failures = 0;
function check( $label, $ok ) {
    global $failures;
    if ( $ok ) echo "PASS  $label\n";
    else { $failures++; echo "FAIL  $label\n"; }
}

$plugin = HMDG_Cookie_Consent::instance();
$menu = '<li><a href="https://resyncphysiotherapy.ie/privacy-policy/">Privacy Policy</a></li>';
$out = $plugin->append_resync_footer_links( $menu, (object) [] );
check( 'Resync footer has Cookie Policy link', strpos( $out, 'href="/cookie-policy/">Cookie Policy' ) !== false );
check( 'Resync footer has Cookie settings link', strpos( $out, 'hmdg-cookie-settings-link' ) !== false );
check( 'footer links are idempotent', $plugin->append_resync_footer_links( $out, (object) [] ) === $out );
check( 'other menus are untouched', $plugin->append_resync_footer_links( '<li>Home</li>', (object) [] ) === '<li>Home</li>' );
$policy_already_present = $menu . '<li><a href="/cookie-policy/">Cookie Policy</a></li>';
$with_settings = $plugin->append_resync_footer_links( $policy_already_present, (object) [] );
check( 'existing Cookie Policy link is not duplicated', substr_count( $with_settings, '>Cookie Policy</a>' ) === 1 );
check( 'settings link is added when policy link already exists', strpos( $with_settings, 'hmdg-cookie-settings-link' ) !== false );
$GLOBALS['test_host'] = 'example.test';
check( 'other sites are untouched', $plugin->append_resync_footer_links( $menu, (object) [] ) === $menu );

$method = new ReflectionMethod( HMDG_Cookie_Consent::class, 'get_banner_html' );
$method->setAccessible( true );
$GLOBALS['test_host'] = 'resyncphysiotherapy.ie';
$html = $method->invoke( $plugin );
foreach ( [ '_fbp', '_fbc', '_omappvp', '_omappvs' ] as $cookie ) {
    check( $cookie . ' is disclosed in preferences', strpos( $html, '<code>' . $cookie . '</code>' ) !== false );
}
check( 'Meta cookies have advertising heading', strpos( $html, '>Meta advertising</th>' ) !== false );
check( 'OptinMonster cookies have marketing heading', strpos( $html, '>Marketing pop-ups</th>' ) !== false );
$GLOBALS['test_host'] = 'example.test';
$other_html = $method->invoke( $plugin );
foreach ( [ '_fbp', '_fbc', '_omappvp', '_omappvs' ] as $cookie ) {
    check( $cookie . ' is not disclosed on another site', strpos( $other_html, '<code>' . $cookie . '</code>' ) === false );
}
check( 'consent revision is not raised by table edits', HMDG_CCM_CONSENT_REVISION === '2' );

echo $failures === 0 ? "\nOK — all assertions passed\n" : "\n$failures assertion(s) FAILED\n";
exit( $failures === 0 ? 0 : 1 );
