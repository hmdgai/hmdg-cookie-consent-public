<?php
/** Guard the consent actions' DOM and keyboard order across future UI changes. */
error_reporting( E_ALL );
define( 'ABSPATH', sys_get_temp_dir() . '/' );

function is_admin() { return false; }
function wp_doing_cron() { return false; }
function add_action( $hook, $cb, $priority = 10, $args = 1 ) { return true; }
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { return true; }
function register_activation_hook() {}
function register_deactivation_hook() {}
function get_option( $name, $default = false ) { return $default; }
function wp_parse_args( $a, $defaults = [] ) { return array_merge( $defaults, (array) $a ); }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function esc_url( $value ) { return $value; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }

require __DIR__ . '/../hmdg-cookie-consent.php';

$failures = 0;
function check( $label, $ok ) {
    global $failures;
    echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
    if ( ! $ok ) $failures++;
}

$method = new ReflectionMethod( HMDG_Cookie_Consent::class, 'get_banner_html' );
$method->setAccessible( true );
$html = $method->invoke( HMDG_Cookie_Consent::instance() );
$doc = new DOMDocument();
libxml_use_internal_errors( true );
$doc->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' );
libxml_clear_errors();
$xpath = new DOMXPath( $doc );

function action_ids( $xpath, $container ) {
    $ids = [];
    foreach ( $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $container . ' ")]/button' ) as $button ) {
        $ids[] = $button->getAttribute( 'id' );
    }
    return $ids;
}

check( 'modal action DOM and tab order is Accept, Save, Reject',
    action_ids( $xpath, 'hmdg-modal-actions' ) === [ 'hmdg-modal-accept', 'hmdg-modal-save', 'hmdg-modal-reject' ] );
check( 'banner action DOM and tab order is unchanged',
    action_ids( $xpath, 'hmdg-banner-buttons' ) === [ 'hmdg-btn-accept', 'hmdg-btn-customise', 'hmdg-btn-reject' ] );

$accept = $xpath->query( '//*[@id="hmdg-modal-accept"]' )->item( 0 );
$save = $xpath->query( '//*[@id="hmdg-modal-save"]' )->item( 0 );
$reject = $xpath->query( '//*[@id="hmdg-modal-reject"]' )->item( 0 );
check( 'only modal Accept uses the filled primary treatment',
    $accept && strpos( ' ' . $accept->getAttribute( 'class' ) . ' ', ' hmdg-btn-primary ' ) !== false
    && $save && strpos( ' ' . $save->getAttribute( 'class' ) . ' ', ' hmdg-btn-primary ' ) === false
    && $reject && strpos( ' ' . $reject->getAttribute( 'class' ) . ' ', ' hmdg-btn-primary ' ) === false );
check( 'modal Save and Reject use the dark outline treatment',
    $save && $reject
    && strpos( ' ' . $save->getAttribute( 'class' ) . ' ', ' hmdg-btn-decision-outline ' ) !== false
    && strpos( ' ' . $reject->getAttribute( 'class' ) . ' ', ' hmdg-btn-decision-outline ' ) !== false );
check( 'all modal actions remain native buttons',
    $accept && $save && $reject
    && $accept->tagName === 'button' && $save->tagName === 'button' && $reject->tagName === 'button' );
check( 'presentation change does not force renewed consent', HMDG_CCM_CONSENT_REVISION === '2' );

echo $failures === 0 ? "\nOK — all assertions passed\n" : "\n$failures assertion(s) FAILED\n";
exit( $failures === 0 ? 0 : 1 );
