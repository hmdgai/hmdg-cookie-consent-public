<?php
/** A cached page can refresh its booking nonce without weakening the POST gate. */
error_reporting( E_ALL );
define( 'ABSPATH', sys_get_temp_dir() . '/' );

$GLOBALS['options'] = [
    'hmdg_ccm_options' => [
        'gtm_id' => 'GTM-TEST123', 'ga4_measurement_id' => 'G-TEST123',
        'mp_tracking_enabled' => 1,
    ],
    'hmdg_ccm_ga4_api_secret' => 'test-secret',
];
$GLOBALS['routes'] = [];
function is_admin() { return false; }
function wp_doing_cron() { return false; }
function add_action() { return true; }
function add_filter() { return true; }
function register_activation_hook() {}
function register_deactivation_hook() {}
function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
function update_option( $name, $value = null, $autoload = null ) { $GLOBALS['options'][ $name ] = $value; return true; }
function wp_parse_args( $args, $defaults = [] ) { return array_merge( $defaults, (array) $args ); }
function register_rest_route( $namespace, $route, $args ) {
    $GLOBALS['routes'][ $namespace . $route ] = $args;
}
function get_site_url() { return 'https://internal-wp.test'; }
function home_url() { return 'https://example.test'; }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_create_nonce( $action ) { return $action === 'hmdg_ccm_mp' ? 'fresh-token' : ''; }
function wp_unslash( $value ) { return $value; }
function wp_verify_nonce( $value, $action ) { return $action === 'hmdg_ccm_mp' && $value === 'fresh-token'; }
function get_transient() { return false; }
function set_transient() { return true; }
function sanitize_text_field( $value ) { return (string) $value; }
function sanitize_key( $value ) { return strtolower( (string) $value ); }
function esc_url_raw( $value ) { return (string) $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_remote_post( $url, $args ) { $GLOBALS['outbound'][] = [ $url, $args ]; }

class WP_REST_Request {
    private $headers;
    private $body;
    public function __construct( $headers, $body = [] ) {
        $this->headers = $headers; $this->body = $body;
    }
    public function get_header( $name ) { return $this->headers[ $name ] ?? ''; }
    public function get_body() { return json_encode( $this->body ); }
    public function get_json_params() { return $this->body; }
}
class WP_REST_Response {
    public $data;
    public $status;
    public $headers;
    public function __construct( $data, $status = 200, $headers = [] ) {
        $this->data = $data; $this->status = $status; $this->headers = $headers;
    }
}

require __DIR__ . '/../hmdg-cookie-consent.php';
$plugin = HMDG_Cookie_Consent::instance();
$plugin->register_rest_endpoints();
$nonce_route = $GLOBALS['routes']['hmdg-ccm/v1/nonce'] ?? null;
if ( ! $nonce_route || $nonce_route['methods'] !== 'GET' ) {
    fwrite( STDERR, "FAIL nonce route registration\n" ); exit( 1 );
}

$allowed = $plugin->handle_mp_nonce( new WP_REST_Request( [ 'Referer' => 'https://example.test/book/' ] ) );
$external = $plugin->handle_mp_nonce( new WP_REST_Request( [ 'Origin' => 'https://evil.test' ] ) );
$internal = $plugin->handle_mp_nonce( new WP_REST_Request( [ 'Origin' => 'https://internal-wp.test' ] ) );
$missing = $plugin->handle_mp_nonce( new WP_REST_Request( [] ) );
if ( $allowed->status !== 200 || $allowed->data['nonce'] !== 'fresh-token'
    || strpos( $allowed->headers['Cache-Control'] ?? '', 'no-store' ) === false
    || $external->status !== 403 || $internal->status !== 403 || $missing->status !== 403 ) {
    fwrite( STDERR, "FAIL nonce origin and cache controls\n" ); exit( 1 );
}
if ( $GLOBALS['routes']['hmdg-ccm/v1/book-now']['methods'] !== 'POST'
    || $GLOBALS['routes']['hmdg-ccm/v1/booking-complete']['methods'] !== 'POST' ) {
    fwrite( STDERR, "FAIL booking POST routes changed\n" ); exit( 1 );
}
$method = new ReflectionMethod( HMDG_Cookie_Consent::class, 'has_analytics_consent' );
$method->setAccessible( true );
$_COOKIE[ HMDG_CCM_COOKIE ] = json_encode( [
    'version' => '1.0', 'policyVersion' => '1-r2', 'analytics' => true,
] );
$current = $method->invoke( $plugin );
$_COOKIE[ HMDG_CCM_COOKIE ] = rawurlencode( json_encode( [
    'version' => '1.0', 'policyVersion' => '1-r2', 'analytics' => true,
] ) );
$encoded_current = $method->invoke( $plugin );
$_COOKIE[ HMDG_CCM_COOKIE ] = json_encode( [
    'version' => '1.0', 'policyVersion' => '1', 'analytics' => true,
] );
$legacy = $method->invoke( $plugin );
$_COOKIE[ HMDG_CCM_COOKIE ] = json_encode( [
    'version' => '1.0', 'policyVersion' => '1-r2', 'analytics' => false,
] );
$rejected = $method->invoke( $plugin );
$_COOKIE[ HMDG_CCM_COOKIE ] = json_encode( [
    'version' => '1.0', 'policyVersion' => 'stale', 'analytics' => true,
] );
$stale = $method->invoke( $plugin );
unset( $_COOKIE[ HMDG_CCM_COOKIE ] );
$missing = $method->invoke( $plugin );
if ( ! $current || ! $encoded_current || ! $legacy || $rejected || $stale || $missing ) {
    fwrite( STDERR, "FAIL analytics consent gate\n" ); exit( 1 );
}
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$request = new WP_REST_Request( [
    'Referer' => 'https://example.test/book/',
    'X-HMDG-Nonce' => 'fresh-token',
    'Content-Type' => 'application/json',
], [ 'client_id' => '123456789.987654321', 'booking_url' => 'https://app.glofox.com/book/' ] );
$_COOKIE[ HMDG_CCM_COOKIE ] = json_encode( [
    'version' => '1.0', 'policyVersion' => '1-r2', 'analytics' => false,
] );
$blocked = $plugin->handle_book_now( $request );
if ( $blocked->status !== 403 || ! empty( $GLOBALS['outbound'] ) ) {
    fwrite( STDERR, "FAIL rejected visitor reached MP\n" ); exit( 1 );
}
$_COOKIE[ HMDG_CCM_COOKIE ] = json_encode( [
    'version' => '1.0', 'policyVersion' => '1-r2', 'analytics' => true,
] );
$queued = $plugin->handle_book_now( $request );
if ( $queued->status !== 200 || empty( $queued->data['queued'] )
    || count( $GLOBALS['outbound'] ?? [] ) !== 1 ) {
    fwrite( STDERR, "FAIL consented visitor did not queue one MP event\n" ); exit( 1 );
}
echo "PASS booking nonce and consent: public home origin, no-store, current/legacy consent\n";
