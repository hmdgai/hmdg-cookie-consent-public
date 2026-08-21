<?php
/**
 * Plugin Name:  HMDG Cookie Consent Mode v2
 * Plugin URI:   https://hmdg.co.uk
 * Description:  UK GDPR (PECR) & EU GDPR compliant cookie consent banner with Google Consent
 *               Mode v2 and booking-conversion tracking. Maintained by HMDG for its client
 *               sites; the changelog is shown with each update.
 * Version:      2.0.0
 * Author:       HMDG
 * Author URI:   https://hmdg.co.uk
 * License:      GPL v2 or later
 * Text Domain:  hmdg-cookie-consent
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ==========================================================================
   CONSTANTS
========================================================================== */
define( 'HMDG_CCM_VERSION', '2.0.0' );
define( 'HMDG_CCM_FILE',    __FILE__ );
define( 'HMDG_CCM_COOKIE',  'hmdg_cookie_consent' );
define( 'HMDG_CCM_EXPIRY',  180 );

/* ==========================================================================
   PLUGIN CLASS
========================================================================== */
final class HMDG_Cookie_Consent {

    private static ?self $instance = null;
    private array $opts = [];

    /*
     * v1.2.0 — PLATFORM REGISTRY
     * Each platform entry defines:
     *   label        — human label shown in admin
     *   domains      — default domains to match for link-based tracking
     *   postmessage  — JS expression that returns true when the iframe message
     *                  means "booking completed". Evaluated as a JS function body
     *                  receiving (data) — the raw e.data value.
     *   msg_type     — 'string' | 'object' (how postMessage data arrives)
     */
    private const PLATFORM_REGISTRY = [
        'cliniko' => [
            'label'       => 'Cliniko',
            'domains'     => ['cliniko.com'],
            'postmessage' => "typeof data==='string'&&data.indexOf('cliniko-bookings-page:confirmed')>-1",
            'msg_type'    => 'string',
        ],
        'calendly' => [
            'label'       => 'Calendly',
            'domains'     => ['calendly.com'],
            'postmessage' => "data&&typeof data==='object'&&data.event==='calendly.event_scheduled'",
            'msg_type'    => 'object',
        ],
        'acuity' => [
            'label'       => 'Acuity Scheduling',
            'domains'     => ['acuityscheduling.com', 'squarespace.com'],
            'postmessage' => "typeof data==='string'&&data.indexOf('bookedAppointment')>-1",
            'msg_type'    => 'string',
        ],
        'pracsuite' => [
            'label'       => 'PracSuite',
            'domains'     => ['pracsuite.com'],
            'postmessage' => "data&&typeof data==='object'&&data.event==='bookingConfirmed'",
            'msg_type'    => 'object',
        ],
        'phorest' => [
            'label'       => 'Phorest',
            'domains'     => ['phorest.com'],
            'postmessage' => "typeof data==='string'&&data.indexOf('booking_confirmed')>-1",
            'msg_type'    => 'string',
        ],
        'youcanbook' => [
            'label'       => 'YouCanBook.me',
            'domains'     => ['youcanbook.me'],
            // YCBM supports redirect back; also sends postMessage
            'postmessage' => "data&&typeof data==='object'&&data.status==='confirmed'",
            'msg_type'    => 'object',
        ],
        'jane' => [
            'label'       => 'Jane App',
            'domains'     => ['jane.app', 'janeapp.com'],
            'postmessage' => "data&&typeof data==='object'&&data.event==='janeBookingCompleted'",
            'msg_type'    => 'object',
        ],
        'timely' => [
            'label'       => 'Timely',
            'domains'     => ['gettimely.com'],
            'postmessage' => "data&&typeof data==='object'&&data.event==='booking_complete'",
            'msg_type'    => 'object',
        ],
        'simplybook' => [
            'label'       => 'SimplyBook.me',
            'domains'     => ['simplybook.me', 'simplybook.it'],
            'postmessage' => "data&&typeof data==='object'&&(data.event==='booking_complete'||data.status==='complete')",
            'msg_type'    => 'object',
        ],
    ];

    /* ── Rate limiting ──────────────────────────────────────────────────────
     * Max MP requests per IP per window.
     * Window = 60 seconds. Limit = 20 requests (generous for real usage,
     * blocks flood attacks — a real user clicks Book Now once or twice).
     */
    private const RATE_LIMIT_MAX    = 20;
    private const RATE_LIMIT_WINDOW = 60; // seconds
    private const RATE_LIMIT_PREFIX = 'hmdg_rl_';

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->opts = wp_parse_args( (array) get_option( 'hmdg_ccm_options', [] ), self::defaults() );

        /*
         * v1.7.0: the MP API secret lives in its own NON-AUTOLOADED option, not
         * in the settings blob that loads on every page view. migrate() moves a
         * pre-1.7.0 value across exactly once; the overlay below keeps every
         * existing reader — opt('ga4_api_secret'), $this->opts[...] — working
         * unchanged, so the split is invisible to the rest of this file.
         */
        self::migrate_mp_secret();
        $this->opts['ga4_api_secret'] = self::get_mp_secret();

        $this->init();
    }

    /* ==========================================================================
       DEFAULTS
    ========================================================================== */
    private static function defaults(): array {
        return [
            'gtm_id'                     => '',
            'gtag_id'                    => '',
            'ga4_measurement_id'         => '',
            'ga4_api_secret'             => '',
            'privacy_policy_url'         => '/privacy-policy/',
            'cookie_policy_url'          => '/cookie-policy/',
            'terms_url'                  => '/terms-conditions/',
            'google_safety_url'          => 'https://business.safety.google/privacy/',
            'policy_version'             => '1',
            'reload_on_consent'          => 0,
            'debug_mode'                 => 0,
            // v1.2.0: universal booking settings
            'booking_domains'            => 'cliniko.com',          // comma-separated external link domains
            'booking_decorator_enabled'  => 1,                      // decorate external booking links
            'mp_tracking_enabled'        => 1,                      // server-side MP on book_now_click
            'iframe_tracking_enabled'    => 1,                      // listen for postMessage completion
            'redirect_tracking_enabled'  => 0,                      // catch return-redirect ?booking_confirmed
            'redirect_param'             => 'booking_confirmed',    // query param to watch for
            'enabled_platforms'          => 'cliniko,calendly,acuity,pracsuite,phorest,youcanbook,jane,timely,simplybook',
            // Legacy Cliniko-specific kept for back-compat
            'cliniko_domains'            => 'cliniko.com',
            'cliniko_decorator_enabled'  => 1,
            'ads_conversion_id'          => '',
            'ads_conversion_label'       => '',
        ];
    }

    /* ==========================================================================
       MP API SECRET (v1.7.0)

       Its own option, autoload 'no'. The settings blob is autoloaded on every
       page view; a server-side credential does not belong in memory on
       requests that will never use it. The constructor overlay keeps the old
       array shape alive for every existing reader.

       WRITERS: exactly three. sanitize_options() (a human on the settings
       page), HMDG_Config_Sync::apply() (the configuration service), and
       migrate_mp_secret() (once, moving a pre-1.7.0 value). Anything else
       writing the settings blob cannot touch the secret, which is the point.

       ROLLBACK NOTE: a site rolled back to <= 1.6.x reads the secret from the
       settings blob, where migration left ''. MP sends stop (the empty check
       already gates them) until the next weekly sync re-applies the value —
       degraded, not broken, and self-healing for centrally managed sites.
    ========================================================================== */

    private const OPT_MP_SECRET = 'hmdg_ccm_ga4_api_secret';

    public static function get_mp_secret(): string {
        return (string) get_option( self::OPT_MP_SECRET, '' );
    }

    public static function set_mp_secret( string $value ): void {
        if ( false === get_option( self::OPT_MP_SECRET ) ) {
            // add_option is the only call that can set autoload; update_option
            // on a missing option would create it autoloaded.
            add_option( self::OPT_MP_SECRET, $value, '', 'no' );
        } else {
            update_option( self::OPT_MP_SECRET, $value );
        }
    }

    /** Move a pre-1.7.0 secret out of the settings blob. Runs on every load,
     *  does work at most once: after the move the blob holds '' and the
     *  condition never fires again. */
    public static function migrate_mp_secret(): void {
        $settings = (array) get_option( 'hmdg_ccm_options', [] );
        $legacy   = (string) ( $settings['ga4_api_secret'] ?? '' );
        if ( $legacy === '' ) return;

        // The new option wins if both exist (it is the newer write path).
        if ( self::get_mp_secret() === '' ) {
            self::set_mp_secret( $legacy );
        }
        $settings['ga4_api_secret'] = '';
        update_option( 'hmdg_ccm_options', $settings );
    }

    /* ==========================================================================
       INIT
    ========================================================================== */
    private function init(): void {
        /*
         * v1.5.0: register_activation_hook() used to be called from here. It never
         * fired. init() runs from the constructor, which runs on plugins_loaded --
         * and on the activation request plugins_loaded has ALREADY fired by the time
         * activate_plugin() includes this file, so the hook was registered after
         * do_action( 'activate_...' ) had already run, or never at all. on_activate()
         * was therefore dead code and default options were never seeded on activation
         * (harmless only because defaults() is merged in the constructor anyway).
         * Both activation and deactivation hooks now live at file scope at the bottom
         * of this file, which is the only place they work.
         */
        add_action( 'admin_menu',            [ $this, 'register_admin_menu' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        if ( ! is_admin() ) {
            add_action( 'wp_head',            [ $this, 'output_preconnects' ],      1 );
            add_action( 'wp_head',            [ $this, 'output_consent_defaults' ], 2 );
            add_action( 'wp_head',            [ $this, 'output_google_tag' ],       3 );
            add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ]             );
            add_action( 'wp_footer',          [ $this, 'output_footer' ],           99 );
        }

        add_action( 'rest_api_init', [ $this, 'register_rest_endpoints' ] );
        add_action( 'admin_notices', [ $this, 'admin_conflict_notice' ] );
        $this->register_cache_exclusions();
        add_filter( 'script_loader_tag', [ $this, 'add_script_attributes' ], 10, 2 );
    }

    public function on_activate(): void {
        if ( false === get_option( 'hmdg_ccm_options' ) ) {
            add_option( 'hmdg_ccm_options', self::defaults() );
        }
    }

    /* ==========================================================================
       SECURITY HELPERS
    ========================================================================== */

    /**
     * Get a safe client IP, handling proxies.
     * Never trusts X-Forwarded-For from unknown sources unless behind a known proxy.
     */
    private function get_client_ip(): string {
        // Cloudflare
        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            return sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
        }
        // Standard reverse proxy (WP Engine, Kinsta, etc.)
        if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
            return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
        }
        return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
    }

    /**
     * Per-IP rate limiter using WordPress transients.
     * Returns true if the request should be blocked.
     */
    private function is_rate_limited(): bool {
        $ip  = $this->get_client_ip();
        $key = self::RATE_LIMIT_PREFIX . md5( $ip );

        $data = get_transient( $key );

        if ( false === $data ) {
            set_transient( $key, [ 'count' => 1, 'start' => time() ], self::RATE_LIMIT_WINDOW );
            return false;
        }

        $data['count']++;

        if ( $data['count'] > self::RATE_LIMIT_MAX ) {
            // Refresh transient window to keep blocking until activity stops
            set_transient( $key, $data, self::RATE_LIMIT_WINDOW );
            return true;
        }

        set_transient( $key, $data, self::RATE_LIMIT_WINDOW );
        return false;
    }

    /**
     * Validate the request origin against the site's own domain.
     * Blocks requests from external origins — only our own pages should hit this endpoint.
     */
    private function is_valid_origin( WP_REST_Request $request ): bool {
        $origin  = $request->get_header( 'Origin' );
        $referer = $request->get_header( 'Referer' );
        $site    = trailingslashit( get_site_url() );

        // v1.3.0: reject requests with no origin/referer — these are not from our pages.
        // Debug mode no longer bypasses this (prevents accidental exposure in production).
        if ( empty( $origin ) && empty( $referer ) ) {
            return false;
        }

        $check = ! empty( $origin ) ? $origin : $referer;

        // Normalise — strip path from referer for comparison
        $parsed = wp_parse_url( $check );
        if ( empty( $parsed['host'] ) ) return false;

        $site_host    = wp_parse_url( $site, PHP_URL_HOST );
        $request_host = $parsed['host'];

        // Allow exact match or www/non-www variants
        return $request_host === $site_host
            || $request_host === 'www.' . $site_host
            || 'www.' . $request_host === $site_host;
    }

    /* ==========================================================================
       REST ENDPOINTS — v1.2.0 (hardened)
       Two endpoints:
         POST /wp-json/hmdg-ccm/v1/book-now        — fires book_now_click (link click)
         POST /wp-json/hmdg-ccm/v1/booking-complete — fires booking_completed (iframe postMessage)
    ========================================================================== */
    public function register_rest_endpoints(): void {
        $shared_args = [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
        ];

        register_rest_route( 'hmdg-ccm/v1', '/book-now', array_merge( $shared_args, [
            'callback' => [ $this, 'handle_book_now' ],
        ]));

        register_rest_route( 'hmdg-ccm/v1', '/booking-complete', array_merge( $shared_args, [
            'callback' => [ $this, 'handle_booking_complete' ],
        ]));
    }

    /**
     * Shared security gate for both REST endpoints.
     * Returns WP_REST_Response on failure, null on pass.
     */
    private function rest_security_gate( WP_REST_Request $request ): ?WP_REST_Response {

        // 1. Rate limiting
        if ( $this->is_rate_limited() ) {
            return new WP_REST_Response(
                [ 'error' => 'Too many requests' ],
                429,
                [ 'Retry-After' => (string) self::RATE_LIMIT_WINDOW ]
            );
        }

        // 2. Nonce verification
        $nonce = $request->get_header( 'X-HMDG-Nonce' );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'hmdg_ccm_mp' ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid nonce' ], 403 );
        }

        // 3. Origin / Referer validation
        if ( ! $this->is_valid_origin( $request ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid origin' ], 403 );
        }

        // 4. Content-Type guard — must be present and must be JSON
        $ct = $request->get_header( 'Content-Type' );
        if ( empty( $ct ) || strpos( $ct, 'application/json' ) === false ) {
            return new WP_REST_Response( [ 'error' => 'Invalid content type' ], 415 );
        }

        // 5. Payload size guard — reject anything over 8KB (real payloads are ~500 bytes)
        $body_raw = $request->get_body();
        if ( strlen( $body_raw ) > 8192 ) {
            return new WP_REST_Response( [ 'error' => 'Payload too large' ], 413 );
        }

        return null; // all checks passed
    }

    /**
     * Fires book_now_click — triggered when user clicks an external booking link.
     */
    public function handle_book_now( WP_REST_Request $request ): WP_REST_Response {
        $gate = $this->rest_security_gate( $request );
        if ( $gate ) return $gate;
        return $this->fire_mp_event( $request, 'book_now_click' );
    }

    /**
     * Fires booking_completed — triggered when the iframe postMessage fires.
     * This is the new v1.2.0 endpoint that achieves true completion tracking.
     */
    public function handle_booking_complete( WP_REST_Request $request ): WP_REST_Response {
        $gate = $this->rest_security_gate( $request );
        if ( $gate ) return $gate;
        return $this->fire_mp_event( $request, 'booking_completed' );
    }

    /**
     * Shared Measurement Protocol firing logic.
     * Used by both handle_book_now and handle_booking_complete.
     */
    private function fire_mp_event( WP_REST_Request $request, string $event_name ): WP_REST_Response {
        $measurement_id = $this->opt( 'ga4_measurement_id' );
        $api_secret     = $this->opt( 'ga4_api_secret' );
        $mp_enabled     = ! empty( $this->opts['mp_tracking_enabled'] );

        if ( ! $mp_enabled || empty( $measurement_id ) || empty( $api_secret ) ) {
            return new WP_REST_Response( [ 'error' => 'MP not configured' ], 400 );
        }

        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid JSON' ], 400 );
        }

        // Sanitise all incoming fields
        $client_id    = sanitize_text_field( $body['client_id']    ?? '' );
        $session_id   = sanitize_text_field( $body['session_id']   ?? '' );
        $booking_url  = esc_url_raw(         $body['booking_url']  ?? '' );
        $page_url     = esc_url_raw(         $body['page_url']     ?? '' );
        $page_title   = sanitize_text_field( $body['page_title']   ?? '' );
        $page_referrer= esc_url_raw(         $body['page_referrer']?? '' );
        $gclid        = sanitize_text_field( $body['gclid']        ?? '' );
        $platform     = sanitize_key(        $body['platform']     ?? 'unknown' );
        $event_id     = sanitize_text_field( $body['event_id']     ?? '' );

        // client_id is mandatory — GA4 cannot stitch the session without it
        if ( empty( $client_id ) ) {
            return new WP_REST_Response( [ 'error' => 'Missing client_id' ], 400 );
        }

        // Validate client_id format — must look like digits.digits (e.g. 123456789.987654321)
        if ( ! preg_match( '/^\d+\.\d+$/', $client_id ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid client_id format' ], 400 );
        }

        // Build event params
        $event_params = [
            'engagement_time_msec' => 100,
            'page_location'        => $page_url,
            'page_title'           => $page_title,
            'booking_platform'     => $platform,
        ];

        if ( ! empty( $booking_url ) )   $event_params['booking_url']    = $booking_url;
        if ( ! empty( $page_referrer ) ) $event_params['page_referrer']  = $page_referrer;
        if ( ! empty( $session_id ) )    $event_params['session_id']     = $session_id;
        if ( ! empty( $gclid ) )         $event_params['gclid']          = $gclid;
        if ( ! empty( $event_id ) )      $event_params['event_id']       = $event_id;

        // v1.3.0: IP override for geo attribution — use the real visitor IP from the
        // HTTP request (not from JS payload, which can't access the client IP).
        // The REST call comes from the visitor's browser, so $_SERVER has the correct IP.
        $visitor_ip = $this->get_client_ip();

        // v1.3.0: timestamp_micros at root level per GA4 MP spec (not inside event params).
        // Places event ~100ms before now to ensure correct session ordering.
        $mp_payload = [
            'client_id'       => $client_id,
            'timestamp_micros' => (string) ( ( time() * 1000000 ) - 100000 ),
            'events'          => [[ 'name' => $event_name, 'params' => $event_params ]],
        ];

        if ( ! empty( $visitor_ip ) && filter_var( $visitor_ip, FILTER_VALIDATE_IP ) ) {
            $mp_payload['ip_override'] = $visitor_ip;
        }

        $url = add_query_arg([
            'measurement_id' => $measurement_id,
            'api_secret'     => $api_secret,
        ], 'https://www.google-analytics.com/mp/collect' );

        // v1.3.0: non-blocking fire-and-forget. wp_remote_post returns immediately,
        // so we cannot verify Google received the event. Response indicates "queued".
        wp_remote_post( $url, [
            'timeout'     => 5,
            'headers'     => [ 'Content-Type' => 'application/json' ],
            'body'        => wp_json_encode( $mp_payload ),
            'blocking'    => false,
        ]);

        return new WP_REST_Response([
            'success'  => true,
            'event'    => $event_name,
            'platform' => $platform,
            'queued'   => true,
            'debug'    => $this->opt( 'debug_mode' ) === '1' ? $mp_payload : null,
        ], 200 );
    }

    /* ==========================================================================
       CONFLICT DETECTION
    ========================================================================== */
    /*
     * Active tag plugins, as [ plugin basename => display name ].
     *
     * The map, not two lists: names are for humans, basenames are the stable
     * identifiers reported centrally. 'MonsterInsights' is a strict prefix of
     * 'MonsterInsights Pro' and they are different plugins, so any like-match
     * on the names conflates them.
     *
     * A wrong basename here is a silent false negative: the site reports "no
     * conflict" while another plugin suppresses our tag. When adding an
     * entry, read the main file's name out of the wordpress.org SVN listing
     * (https://plugins.svn.wordpress.org/<slug>/trunk/) rather than guessing
     * it from the slug — three of the original nine entries were guesses that
     * could never match a real install (fixed in v1.6.1).
     *
     * Callers wanting names use array_values(); implode(), count() and
     * array_map() already operate on values, so they need no change.
     */
    private function detect_conflicting_plugins(): array {
        if ( ! function_exists( 'is_plugin_active' ) ) include_once ABSPATH . 'wp-admin/includes/plugin.php';
        $conflicts = [];
        $names = [
            'google-site-kit/google-site-kit.php'                                               => 'Google Site Kit',
            'google-analytics-for-wordpress/googleanalytics.php'                                => 'MonsterInsights',
            'google-analytics-premium/googleanalytics-premium.php'                              => 'MonsterInsights Pro',
            'ga-google-analytics/ga-google-analytics.php'                                       => 'GA Google Analytics',
            'wp-analytify/wp-analytify.php'                                                     => 'Analytify',
            'wp-google-tag-manager/wp-google-tag-manager.php'                                   => 'WP Google Tag Manager',
            'duracelltomi-google-tag-manager/duracelltomi-google-tag-manager-for-wordpress.php' => 'GTM4WP (DuracellTomi)',
            'header-footer-code-manager/99robots-header-footer-code-manager.php'                => 'Header Footer Code Manager',
        ];
        foreach ( $names as $file => $name ) {
            if ( is_plugin_active( $file ) ) $conflicts[ $file ] = $name;
        }
        return $conflicts;
    }

    private function has_conflicting_tag_plugin(): bool {
        return count( $this->detect_conflicting_plugins() ) > 0;
    }

    /*
     * v1.5.0: public so HMDG_Config_Sync can report whether our tag actually fires,
     * not merely whether a GTM ID is stored. On a site running Site Kit or GTM4WP
     * the two are different things.
     *
     * v1.6.0: returns the map, so the caller can report stable ids beside the names.
     */
    public function conflicting_tag_plugin_map(): array {
        return $this->detect_conflicting_plugins();
    }

    public function admin_conflict_notice(): void {
        if ( ! $this->is_configured() ) return;
        $screen = get_current_screen();
        if ( ! $screen ) return;
        $conflicts = $this->detect_conflicting_plugins();
        if ( empty( $conflicts ) ) return;
        $list = implode( ', ', array_map( fn($n) => '<strong>' . esc_html($n) . '</strong>', $conflicts ) );
        echo '<div class="notice notice-warning is-dismissible"><p><strong>⚠️ HMDG Cookie Consent:</strong> Detected existing Google tag plugin(s): ' . $list . '. HMDG has <strong>skipped injecting its own Google tag</strong>. Consent Mode defaults and signals are still active.</p></div>';
    }

    /* ==========================================================================
       ADMIN ASSETS
    ========================================================================== */
    public function enqueue_admin_assets( string $hook ): void {
        if ( $hook !== 'toplevel_page_hmdg-cookie-consent' ) return;
        wp_enqueue_style(  'hmdg-admin-bootstrap-css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css', [], '5.3.3' );
        wp_enqueue_script( 'hmdg-admin-bootstrap-js',  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js', [], '5.3.3', true );
        wp_add_inline_style( 'hmdg-admin-bootstrap-css', $this->get_admin_css() );
    }

    private function get_admin_css(): string { return <<<'ADMINCSS'
#hmdg-admin-wrap{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;}
#hmdg-admin-wrap *,#hmdg-admin-wrap *::before,#hmdg-admin-wrap *::after{box-sizing:border-box;}
#wpcontent h1,#wpcontent h2,#wpcontent h3{font-family:inherit;}
#hmdg-admin-wrap .hmdg-badge{font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;}
#hmdg-admin-wrap .card{border:1px solid #dee2e6;border-radius:10px;width:100%;}
#hmdg-admin-wrap .card-header{background:#f8f9fa;border-bottom:1px solid #dee2e6;border-radius:10px 10px 0 0!important;padding:14px 20px;}
#hmdg-admin-wrap .card-header h2{font-size:15px;font-weight:700;margin:0;color:#1a1a2e;}
#hmdg-admin-wrap .card-body{padding:20px;}
#hmdg-admin-wrap label.form-label{font-weight:600;font-size:13px;color:#374151;margin-bottom:4px;}
#hmdg-admin-wrap .form-control,.form-check-input{font-size:13px;}
#hmdg-admin-wrap .form-text{font-size:12px;color:#6b7280;margin-top:4px;}
#hmdg-admin-wrap .info-card{border-radius:10px;padding:16px 18px;margin-bottom:14px;border:1px solid transparent;}
#hmdg-admin-wrap .info-card h3{font-size:13px;font-weight:700;margin:0 0 8px;}
#hmdg-admin-wrap .info-card ul{margin:0;padding-left:18px;font-size:12.5px;line-height:2;}
#hmdg-admin-wrap .info-card ol{margin:0;padding-left:18px;font-size:12.5px;line-height:2.2;}
#hmdg-admin-wrap .info-card p{font-size:12.5px;margin:0 0 6px;}
#hmdg-admin-wrap .info-card code{font-size:11px;padding:1px 5px;border-radius:3px;}
#hmdg-admin-wrap .hmdg-dot{width:8px;height:8px;border-radius:50%;display:inline-block;}
#hmdg-admin-wrap .btn-hmdg-save{background:#1a56db;border-color:#1a56db;color:#fff;font-weight:700;padding:10px 28px;font-size:14px;border-radius:8px;}
#hmdg-admin-wrap .btn-hmdg-save:hover{background:#1648c0;border-color:#1648c0;color:#fff;}
#hmdg-admin-wrap .field-row{padding:14px 0;border-bottom:1px solid #f3f4f6;}
#hmdg-admin-wrap .field-row:last-child{border-bottom:none;}
#hmdg-admin-wrap .field-row:first-child{padding-top:0;}
#hmdg-admin-wrap .hmdg-alert{border-left:4px solid #1a56db;background:#eff6ff;border-radius:0 8px 8px 0;padding:12px 16px;font-size:13px;color:#1e3a8a;margin-bottom:0;}
#hmdg-admin-wrap .hmdg-alert-success{border-left:4px solid #16a34a;background:#f0fdf4;border-radius:0 8px 8px 0;padding:12px 16px;font-size:13px;color:#166534;}
#hmdg-admin-wrap .hmdg-alert-purple{border-left:4px solid #7c3aed;background:#f5f3ff;border-radius:0 8px 8px 0;padding:12px 16px;font-size:13px;color:#4c1d95;}
#hmdg-admin-wrap .console-preview{background:#1e1e2e;border-radius:8px;padding:14px 16px;font-family:'Courier New',monospace;font-size:11.5px;line-height:1.9;}
#hmdg-admin-wrap .console-preview .c-green{color:#a6e3a1;}
#hmdg-admin-wrap .console-preview .c-yellow{color:#f9e2af;}
#hmdg-admin-wrap .console-preview .c-red{color:#f38ba8;}
#hmdg-admin-wrap .console-preview .c-grey{color:#6c7086;}
#hmdg-admin-wrap .hmdg-status-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid rgba(0,0,0,.06);font-size:12.5px;}
#hmdg-admin-wrap .hmdg-status-row:last-child{border-bottom:none;}
#hmdg-admin-wrap .hmdg-status-label{color:#374151;font-weight:600;}
#hmdg-admin-wrap .hmdg-status-value{font-weight:700;}
#hmdg-admin-wrap .hmdg-status-ok{color:#16a34a;}
#hmdg-admin-wrap .hmdg-status-warn{color:#d97706;}
#hmdg-admin-wrap .hmdg-status-off{color:#6b7280;}
#hmdg-admin-wrap .platform-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;margin-top:8px;}
#hmdg-admin-wrap .platform-pill{border:1px solid #e5e7eb;border-radius:8px;padding:8px 12px;display:flex;align-items:center;gap:8px;font-size:12.5px;}
#hmdg-admin-wrap .platform-pill input[type=checkbox]{width:16px;height:16px;cursor:pointer;}
#hmdg-admin-wrap .hmdg-layout{display:flex;gap:24px;align-items:flex-start;width:100%;}
#hmdg-admin-wrap .hmdg-main{flex:1 1 0;min-width:0;}
#hmdg-admin-wrap .hmdg-sidebar{width:320px;flex-shrink:0;}
@media(max-width:1200px){#hmdg-admin-wrap .hmdg-sidebar{width:280px;}}
@media(max-width:960px){#hmdg-admin-wrap .hmdg-layout{flex-direction:column;}#hmdg-admin-wrap .hmdg-sidebar{width:100%;}}
ADMINCSS;
    }

    private function is_configured(): bool {
        return ! empty( $this->opts['gtm_id'] ) || ! empty( $this->opts['gtag_id'] );
    }

    private function is_mp_configured(): bool {
        return ! empty( $this->opts['ga4_measurement_id'] )
            && ! empty( $this->opts['ga4_api_secret'] )
            && ! empty( $this->opts['mp_tracking_enabled'] );
    }

    private function opt( string $key ): string {
        return (string) ( $this->opts[ $key ] ?? '' );
    }

    /* Build a merged list of all booking domains from enabled platforms + custom booking_domains field */
    private function get_all_booking_domains(): array {
        $enabled = array_filter( array_map( 'trim', explode( ',', $this->opt('enabled_platforms') ) ) );
        $domains = [];
        foreach ( $enabled as $key ) {
            if ( isset( self::PLATFORM_REGISTRY[ $key ] ) ) {
                foreach ( self::PLATFORM_REGISTRY[ $key ]['domains'] as $d ) {
                    $domains[] = $d;
                }
            }
        }
        // Also include any custom domains from the booking_domains field
        $custom = array_filter( array_map( 'trim', explode( ',', $this->opt('booking_domains') ) ) );
        $domains = array_unique( array_merge( $domains, $custom ) );
        return array_values( $domains );
    }

    /* Build postMessage matchers for enabled platforms.
       v1.3.0: includes domains for JS-side origin validation. */
    private function get_postmessage_matchers(): array {
        $enabled  = array_filter( array_map( 'trim', explode( ',', $this->opt('enabled_platforms') ) ) );
        $matchers = [];
        foreach ( $enabled as $key ) {
            if ( isset( self::PLATFORM_REGISTRY[ $key ] ) ) {
                $matchers[] = [
                    'platform' => $key,
                    'test'     => self::PLATFORM_REGISTRY[ $key ]['postmessage'],
                    'domains'  => self::PLATFORM_REGISTRY[ $key ]['domains'],
                ];
            }
        }
        return $matchers;
    }

    /* ==========================================================================
       CACHE EXCLUSIONS — v1.2.0: conditional no-store header
    ========================================================================== */
    private function register_cache_exclusions(): void {
        add_filter( 'rocket_exclude_js',                  [ $this, 'excl_js' ] );
        add_filter( 'rocket_delay_js_exclusions',         [ $this, 'excl_js' ] );
        add_filter( 'rocket_exclude_defer_js',            [ $this, 'excl_js' ] );
        add_filter( 'rocket_exclude_inline_js',           [ $this, 'excl_inline' ] );
        add_filter( 'rocket_minify_excluded_external_js', [ $this, 'excl_external' ] );
        add_filter( 'w3tc_minify_js_do_tag_minification', [ $this, 'w3tc_skip' ], 10, 3 );
        add_filter( 'litespeed_optimize_js_excludes',     [ $this, 'excl_js' ] );
        add_filter( 'litespeed_optm_js_defer_exc',        [ $this, 'excl_js' ] );
        add_filter( 'autoptimize_filter_js_exclude',      [ $this, 'autoptimize_excl' ] );

        // v1.3.0: no-store and Vary: Cookie both removed. The consent UI reads cookies
        // client-side — the HTML output is identical regardless of consent state, so
        // full-page caching (browser, CDN, WP Rocket, LiteSpeed) is completely safe.
        // Nonces embedded in the page are valid 12-24h, well within cache TTLs.
    }
    public function excl_js( $list ): array       { return array_merge( (array)$list, ['hmdg-ccm-js'] ); }
    public function excl_inline( $list ): array   { return array_merge( (array)$list, ['hmdg-consent-defaults','hmdg-ccm-config'] ); }
    public function excl_external( $list ): array { return (array) $list; }
    public function w3tc_skip( bool $do, $file, $type ): bool { return strpos( (string) $file, 'hmdg' ) !== false ? false : $do; }
    public function autoptimize_excl( string $str ): string   { return $str . ', hmdg-ccm-js'; }
    public function add_script_attributes( string $tag, string $handle ): string {
        if ( $handle === 'hmdg-ccm-js' ) {
            $tag = str_replace('<script ','<script data-cfasync="false" data-no-optimize="1" data-rocket-exclude="true" data-pagespeed-no-defer ', $tag);
        }
        return $tag;
    }

    /* ==========================================================================
       wp_head — PRECONNECTS
    ========================================================================== */
    public function output_preconnects(): void {
        if ( ! $this->is_configured() ) return;
        echo "\n<!-- HMDG CCM: resource hints -->\n";
        echo '<link rel="preconnect" href="https://www.googletagmanager.com" crossorigin>' . "\n";
        echo '<link rel="dns-prefetch" href="//www.googletagmanager.com">' . "\n";
    }

    /* ==========================================================================
       wp_head — CONSENT DEFAULTS
    ========================================================================== */
    public function output_consent_defaults(): void {
        if ( ! $this->is_configured() ) return;
        $cookie  = HMDG_CCM_COOKIE;
        $debug   = $this->opt('debug_mode') === '1' ? 'true' : 'false';
        $pol_ver = esc_js( $this->opt('policy_version') ?: '1' );
        ?>
<!-- HMDG CCM v<?php echo esc_html( HMDG_CCM_VERSION ); ?> | Step 1/3: Consent defaults -->
<script id="hmdg-consent-defaults" data-cfasync="false" data-no-optimize="1" data-rocket-exclude="true" data-pagespeed-no-defer>
(function(){
  'use strict';
  window.dataLayer=window.dataLayer||[];
  window.gtag=window.gtag||function(){window.dataLayer.push(arguments);};
  var DEBUG=<?php echo $debug; ?>, POLICY_VER='<?php echo $pol_ver; ?>';
  function log(){if(DEBUG){var a=['[HMDG CCM]'].concat(Array.prototype.slice.call(arguments));console.log.apply(console,a);}}
  // v1.3.1: saved consent is restored synchronously below. Keep the denied-state
  // measurement immediate so short visits still produce consent-aware cookieless pings.
  window.gtag('consent','default',{
    analytics_storage:'denied',ad_storage:'denied',ad_user_data:'denied',
    ad_personalization:'denied',functionality_storage:'denied',
    personalization_storage:'denied',security_storage:'granted'
  });
  log('✅ Consent defaults DENIED | Advanced CM | immediate cookieless measurement');
  (function(){
    try{
      var raw=(document.cookie.match('(?:^|; )<?php echo esc_js($cookie); ?>=([^;]*)')||[])[1];
      if(!raw){log('ℹ No saved consent — banner will show.');return;}
      var c=JSON.parse(decodeURIComponent(raw));
      if(!c||!c.version)return;
      if(c.policyVersion&&c.policyVersion!==POLICY_VER){
        log('⚠ Policy version changed — clearing consent.');
        document.cookie='<?php echo esc_js($cookie); ?>=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/;';
        return;
      }
      window.gtag('consent','update',{
        analytics_storage:      c.analytics ?'granted':'denied',
        ad_storage:             c.marketing ?'granted':'denied',
        ad_user_data:           c.marketing ?'granted':'denied',
        ad_personalization:     c.marketing ?'granted':'denied',
        functionality_storage:  c.functional?'granted':'denied',
        personalization_storage:c.functional?'granted':'denied'
      });
      log('✅ Consent restored:',c);
    }catch(e){log('⚠ Could not restore consent:',e.message);}
  })();
})();
</script>
        <?php
    }

    /* ==========================================================================
       wp_head — GOOGLE TAG
    ========================================================================== */
    public function output_google_tag(): void {
        if ( ! $this->is_configured() ) return;
        if ( $this->has_conflicting_tag_plugin() ) {
            $conflicts = $this->detect_conflicting_plugins();
            echo "\n<!-- HMDG CCM v" . esc_html( HMDG_CCM_VERSION ) . ": tag injection SKIPPED — " . esc_html(implode(', ',$conflicts)) . " -->\n";
            return;
        }
        $gtm_id  = $this->opt('gtm_id');
        $gtag_id = $this->opt('gtag_id');
        $ads_id  = $this->opt('ads_conversion_id');
        if ( ! empty($gtm_id) ) : ?>
<!-- HMDG CCM v<?php echo esc_html( HMDG_CCM_VERSION ); ?> Step 2/3: GTM -->
<script data-cfasync="false" data-no-optimize="1" data-rocket-exclude="true">
(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});
var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';
j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;
f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','<?php echo esc_js($gtm_id); ?>');
</script>
        <?php elseif ( ! empty($gtag_id) ) : ?>
<!-- HMDG CCM v<?php echo esc_html( HMDG_CCM_VERSION ); ?> Step 2/3: gtag.js direct -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr($gtag_id); ?>" data-cfasync="false" data-no-optimize="1" data-rocket-exclude="true"></script>
<script data-cfasync="false" data-no-optimize="1" data-rocket-exclude="true">
window.dataLayer=window.dataLayer||[];
window.gtag=window.gtag||function(){window.dataLayer.push(arguments);};
window.gtag('js',new Date());
window.gtag('config','<?php echo esc_js($gtag_id); ?>',{anonymize_ip:true});
<?php if(!empty($ads_id)):?>window.gtag('config','<?php echo esc_js($ads_id); ?>');<?php endif;?>
</script>
        <?php endif;
    }

    /* ==========================================================================
       ENQUEUE ASSETS
    ========================================================================== */
    public function enqueue_assets(): void {
        if ( ! $this->is_configured() ) return;

        // v1.3.0: Bootstrap removed from frontend — consent UI CSS is fully self-contained.
        // This saves ~56KB+ (gzipped) per page load across every client site.
        // Bootstrap is still loaded on the admin settings page only.
        wp_register_style( 'hmdg-ccm-css', false );
        wp_enqueue_style( 'hmdg-ccm-css' );
        wp_add_inline_style( 'hmdg-ccm-css', $this->get_css() );
        wp_register_script( 'hmdg-ccm-js', false, [], false, true );
        wp_enqueue_script( 'hmdg-ccm-js' );

        $config = [
            'version'                 => HMDG_CCM_VERSION,
            'cookieName'              => HMDG_CCM_COOKIE,
            'cookieExpiry'            => HMDG_CCM_EXPIRY,
            'debug'                   => (bool) $this->opt('debug_mode'),
            'gtagId'                  => $this->opt('gtag_id'),
            'gtmId'                   => $this->opt('gtm_id'),
            'policyVersion'           => $this->opt('policy_version') ?: '1',
            'reloadConsent'           => (bool) $this->opt('reload_on_consent'),
            'ga4MeasurementId'        => $this->opt('ga4_measurement_id'),
            // v1.2.0: universal booking config
            'mpTrackingEnabled'       => $this->is_mp_configured(),
            'mpBookNowEndpoint'       => rest_url( 'hmdg-ccm/v1/book-now' ),
            'mpCompleteEndpoint'      => rest_url( 'hmdg-ccm/v1/booking-complete' ),
            'mpNonce'                 => wp_create_nonce( 'hmdg_ccm_mp' ),
            'bookingDomains'          => $this->get_all_booking_domains(),
            'decoratorEnabled'        => (bool) ($this->opts['booking_decorator_enabled'] ?? 1),
            'iframeTrackingEnabled'   => (bool) ($this->opts['iframe_tracking_enabled'] ?? 1),
            'redirectTrackingEnabled' => (bool) ($this->opts['redirect_tracking_enabled'] ?? 0),
            'redirectParam'           => $this->opt('redirect_param') ?: 'booking_confirmed',
            'postMessageMatchers'     => $this->get_postmessage_matchers(),
            // Legacy back-compat keys so any client-side code relying on old names still works
            'clinikoDecoratorEnabled' => (bool) ($this->opts['booking_decorator_enabled'] ?? 1),
            'clinikoDomains'          => $this->get_all_booking_domains(),
        ];

        wp_add_inline_script(
            'hmdg-ccm-js',
            'var hmdgCCM=' . wp_json_encode( $config ) . ';' . "\n" . $this->get_js(),
            'after'
        );
    }

    /* ==========================================================================
       wp_footer
    ========================================================================== */
    public function output_footer(): void {
        if ( ! $this->is_configured() ) return;
        $gtm_id = $this->opt('gtm_id');
        if ( ! empty($gtm_id) && ! $this->has_conflicting_tag_plugin() ) {
            printf( "\n<!-- GTM noscript -->\n<noscript><iframe src=\"https://www.googletagmanager.com/ns.html?id=%s\" height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe></noscript>\n\n", esc_attr($gtm_id) );
        }
        echo $this->get_banner_html();
    }

    /* ==========================================================================
       BANNER HTML — unchanged from v1.1.1
    ========================================================================== */
    private function get_banner_html(): string {
        $pp  = esc_url( $this->opts['privacy_policy_url']  ?? '/privacy-policy/' );
        $cp  = esc_url( $this->opts['cookie_policy_url']   ?? '/cookie-policy/' );
        $tc  = esc_url( $this->opts['terms_url']           ?? '/terms-conditions/' );
        $gs  = esc_url( $this->opts['google_safety_url']   ?? 'https://business.safety.google/privacy/' );
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M21.598 11.064a1.006 1.006 0 0 0-.854-.172A2.938 2.938 0 0 1 20 11c-1.654 0-3-1.346-3.003-2.937.005-.034.016-.136.017-.17a1 1 0 0 0-1.736-.806A2.967 2.967 0 0 1 13 8c-1.654 0-3-1.346-3-3 0-.085.007-.17.02-.254a1 1 0 0 0-1.562-.888C5.152 5.6 3 8.639 3 12c0 4.971 4.029 9 9 9s9-4.029 9-9c0-.085-.003-.17-.007-.254a1 1 0 0 0-.395-.682z"/><circle cx="9" cy="14" r="1.1"/><circle cx="12" cy="17" r="1.1"/><circle cx="7" cy="17" r="1.1"/><circle cx="15" cy="13" r="1.1"/></svg>';
        ob_start(); ?>
<!-- HMDG Cookie Consent Mode v2 — v<?php echo esc_html( HMDG_CCM_VERSION ); ?> -->
<div id="hmdg-ccm" class="hmdg-ccm">
    <div id="hmdg-banner" class="hmdg-banner" role="dialog" aria-modal="true" aria-label="Cookie consent" aria-live="polite" tabindex="-1">
        <div class="hmdg-banner-inner">
            <div class="hmdg-banner-content"><div class="hmdg-banner-text">
                <div class="hmdg-banner-title" role="heading" aria-level="2">We value your privacy</div>
                <p class="hmdg-banner-body">We use cookies to personalise content and analyse traffic. You can choose which cookies to allow.
                    &nbsp;<a href="<?php echo $pp; ?>" class="hmdg-link" target="_blank" rel="noopener">Privacy Policy</a>
                    &nbsp;&bull;&nbsp;<a href="<?php echo $cp; ?>" class="hmdg-link" target="_blank" rel="noopener">Cookie Policy</a>
                    &nbsp;&bull;&nbsp;<a href="<?php echo $tc; ?>" class="hmdg-link" target="_blank" rel="noopener">Terms &amp; Conditions</a>
                    &nbsp;&bull;&nbsp;<a href="<?php echo $gs; ?>" class="hmdg-link" target="_blank" rel="noopener noreferrer">Google Privacy</a>
                </p>
            </div></div>
            <div class="hmdg-banner-buttons" role="group" aria-label="Cookie consent options">
                <button type="button" id="hmdg-btn-customise" class="hmdg-btn hmdg-btn-outline">Customise</button>
                <button type="button" id="hmdg-btn-reject"    class="hmdg-btn hmdg-btn-equal">Reject All</button>
                <button type="button" id="hmdg-btn-accept"    class="hmdg-btn hmdg-btn-equal">Accept All</button>
            </div>
        </div>
    </div>
    <button type="button" id="hmdg-reopen" class="hmdg-reopen" aria-label="Manage your cookie preferences" title="Cookie Preferences"><?php echo $svg; ?></button>
    <div id="hmdg-modal-overlay" class="hmdg-overlay" role="dialog" aria-modal="true" aria-labelledby="hmdg-modal-title">
        <div class="hmdg-modal" role="document">
            <div class="hmdg-modal-header">
                <div class="hmdg-modal-title" id="hmdg-modal-title" role="heading" aria-level="2">Customise Consent Preferences</div>
                <button type="button" id="hmdg-modal-close" class="hmdg-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="hmdg-modal-body">
                <p class="hmdg-intro">We use cookies to help you navigate efficiently and perform certain functions. You can choose which categories to allow below. Cookies categorised as <strong>Necessary</strong> are always active.
                    <button type="button" id="hmdg-showmore-btn" class="hmdg-link-btn" aria-expanded="false" aria-controls="hmdg-showmore">Show more</button>
                </p>
                <div id="hmdg-showmore" hidden><p class="hmdg-intro">We also use third-party cookies for analytics and advertising. These are only stored with your consent. You can update or withdraw consent at any time via the cookie icon at the bottom-left. This site operates under <strong>UK GDPR</strong>, the <strong>Data Protection Act 2018</strong>, and the <strong>Privacy and Electronic Communications Regulations (PECR)</strong>. <a href="<?php echo $cp; ?>" class="hmdg-link" target="_blank">Cookie Policy</a> &nbsp;&bull;&nbsp; <a href="<?php echo $pp; ?>" class="hmdg-link" target="_blank">Privacy Policy</a></p></div>
                <div class="hmdg-cat"><div class="hmdg-cat-row"><button type="button" class="hmdg-cat-toggle" aria-expanded="false" aria-controls="hmdg-desc-necessary"><span class="hmdg-arrow" aria-hidden="true">&#x203A;</span><span class="hmdg-cat-name">Necessary</span></button><span class="hmdg-always-on">Always Active</span></div>
                <div id="hmdg-desc-necessary" class="hmdg-cat-desc" hidden><p>Necessary cookies are required for core site features including security, network management, and consent storage.</p>
                <table class="hmdg-cookie-table"><thead><tr><th>Cookie</th><th>Duration</th><th>Description</th></tr></thead><tbody>
                <tr><td><code>hmdg_cookie_consent</code></td><td>180 days</td><td>Stores your cookie consent preferences.</td></tr>
                <tr><td><code>PHPSESSID</code></td><td>Session</td><td>WordPress session identifier.</td></tr>
                <tr><td><code>wordpress_logged_in_*</code></td><td>Session</td><td>WordPress login authentication.</td></tr>
                </tbody></table></div></div>
                <div class="hmdg-cat"><div class="hmdg-cat-row"><button type="button" class="hmdg-cat-toggle" aria-expanded="false" aria-controls="hmdg-desc-functional"><span class="hmdg-arrow" aria-hidden="true">&#x203A;</span><span class="hmdg-cat-name">Functional</span></button><label class="hmdg-toggle" aria-label="Enable functional cookies"><input type="checkbox" id="hmdg-chk-functional" name="functional"><span class="hmdg-toggle-track"><span class="hmdg-toggle-thumb"></span></span></label></div>
                <div id="hmdg-desc-functional" class="hmdg-cat-desc" hidden><p>Functional cookies enable enhanced features such as live chat and remembering preferences.</p>
                <table class="hmdg-cookie-table"><thead><tr><th>Cookie</th><th>Duration</th><th>Description</th></tr></thead><tbody><tr><td><code>wp-settings-*</code></td><td>1 year</td><td>Stores user interface preferences.</td></tr></tbody></table></div></div>
                <div class="hmdg-cat"><div class="hmdg-cat-row"><button type="button" class="hmdg-cat-toggle" aria-expanded="false" aria-controls="hmdg-desc-analytics"><span class="hmdg-arrow" aria-hidden="true">&#x203A;</span><span class="hmdg-cat-name">Analytics</span></button><label class="hmdg-toggle" aria-label="Enable analytics cookies"><input type="checkbox" id="hmdg-chk-analytics" name="analytics"><span class="hmdg-toggle-track"><span class="hmdg-toggle-thumb"></span></span></label></div>
                <div id="hmdg-desc-analytics" class="hmdg-cat-desc" hidden><p>Analytics cookies help us understand visitor behaviour. Controls <code>analytics_storage</code> for Google Analytics 4.</p>
                <table class="hmdg-cookie-table"><thead><tr><th>Cookie</th><th>Duration</th><th>Description</th></tr></thead><tbody>
                <tr><td><code>_ga</code></td><td>2 years</td><td>Google Analytics — distinguishes users.</td></tr>
                <tr><td><code>_ga_*</code></td><td>2 years</td><td>Google Analytics 4 — session persistence.</td></tr>
                <tr><td><code>_gid</code></td><td>24 hours</td><td>Google Analytics — distinguishes users.</td></tr>
                <tr><td><code>_gat</code></td><td>1 minute</td><td>Google Analytics — throttles request rate.</td></tr>
                </tbody></table></div></div>
                <div class="hmdg-cat"><div class="hmdg-cat-row"><button type="button" class="hmdg-cat-toggle" aria-expanded="false" aria-controls="hmdg-desc-performance"><span class="hmdg-arrow" aria-hidden="true">&#x203A;</span><span class="hmdg-cat-name">Performance</span></button><label class="hmdg-toggle" aria-label="Enable performance cookies"><input type="checkbox" id="hmdg-chk-performance" name="performance"><span class="hmdg-toggle-track"><span class="hmdg-toggle-thumb"></span></span></label></div>
                <div id="hmdg-desc-performance" class="hmdg-cat-desc" hidden><p>Performance cookies collect aggregate, anonymous data about site performance.</p>
                <table class="hmdg-cookie-table"><thead><tr><th>Cookie</th><th>Duration</th><th>Description</th></tr></thead><tbody><tr><td><code>_gat_gtag_*</code></td><td>1 minute</td><td>Google Tag Manager — rate throttle.</td></tr></tbody></table></div></div>
                <div class="hmdg-cat"><div class="hmdg-cat-row"><button type="button" class="hmdg-cat-toggle" aria-expanded="false" aria-controls="hmdg-desc-marketing"><span class="hmdg-arrow" aria-hidden="true">&#x203A;</span><span class="hmdg-cat-name">Marketing / Advertising</span></button><label class="hmdg-toggle" aria-label="Enable marketing cookies"><input type="checkbox" id="hmdg-chk-marketing" name="marketing"><span class="hmdg-toggle-track"><span class="hmdg-toggle-thumb"></span></span></label></div>
                <div id="hmdg-desc-marketing" class="hmdg-cat-desc" hidden><p>Marketing cookies track visits to deliver relevant ads. Controls <code>ad_storage</code>, <code>ad_user_data</code>, and <code>ad_personalization</code>.</p>
                <table class="hmdg-cookie-table"><thead><tr><th>Cookie</th><th>Duration</th><th>Description</th></tr></thead><tbody>
                <tr><td><code>_gcl_au</code></td><td>90 days</td><td>Google Ads — conversion tracking.</td></tr>
                <tr><td><code>_gcl_aw</code></td><td>90 days</td><td>Google Ads — click attribution.</td></tr>
                <tr><td><code>_gac_*</code></td><td>90 days</td><td>Google Ads — campaign information.</td></tr>
                <tr><td><code>IDE</code></td><td>1 year</td><td>Google DoubleClick — ad targeting.</td></tr>
                </tbody></table></div></div>
            </div>
            <div class="hmdg-modal-footer">
                <div class="hmdg-modal-actions" role="group">
                    <button type="button" id="hmdg-modal-reject" class="hmdg-btn hmdg-btn-equal">Reject All</button>
                    <button type="button" id="hmdg-modal-save"   class="hmdg-btn hmdg-btn-outline">Save My Preferences</button>
                    <button type="button" id="hmdg-modal-accept" class="hmdg-btn hmdg-btn-equal">Accept All</button>
                </div>
                <p class="hmdg-powered">Powered by <strong>HMDG</strong> &nbsp;&bull;&nbsp; <a href="<?php echo $cp; ?>" class="hmdg-link" target="_blank" rel="noopener" style="font-size:11px;">Cookie Policy</a></p>
            </div>
        </div>
    </div>
</div>
<!-- End HMDG Cookie Consent Mode v2 — v<?php echo esc_html( HMDG_CCM_VERSION ); ?> -->
<?php
        return ob_get_clean();
    }

    /* ==========================================================================
       CSS — unchanged from v1.1.1
    ========================================================================== */
    private function get_css(): string { return <<<'CSS'
.hmdg-ccm{--hmdg-bg:var(--white,#ffffff);--hmdg-heading:var(--heading_color,#111111);--hmdg-text:var(--body_color,#444444);--hmdg-primary:var(--primary_color,#0d6efd);--hmdg-border:rgba(0,0,0,.10);--hmdg-radius:8px;--hmdg-font:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;--hmdg-z:999990;--hmdg-shadow:0 -2px 28px rgba(0,0,0,.12);}
.hmdg-ccm *,.hmdg-ccm *::before,.hmdg-ccm *::after{box-sizing:border-box !important;}
.hmdg-banner{display:none;position:fixed !important;bottom:0 !important;left:0 !important;right:0 !important;z-index:var(--hmdg-z) !important;background:var(--hmdg-bg) !important;border-top:1px solid var(--hmdg-border) !important;box-shadow:var(--hmdg-shadow) !important;padding:14px 24px !important;}
.hmdg-banner.hmdg-open{display:block !important;animation:hmdgSlideUp .3s ease !important;}
@keyframes hmdgSlideUp{from{transform:translateY(100%);opacity:0}to{transform:translateY(0);opacity:1}}
.hmdg-banner-inner{max-width:1400px !important;margin:0 auto !important;display:flex !important;align-items:center !important;gap:16px !important;flex-wrap:wrap !important;}
.hmdg-banner-content{display:flex !important;align-items:center !important;flex:1 !important;min-width:220px !important;}
.hmdg-banner-title{font-family:var(--hmdg-font) !important;font-size:12.5px !important;font-weight:700 !important;color:var(--hmdg-heading) !important;margin:0 0 2px !important;padding:0 !important;line-height:1.3 !important;border:none !important;display:block !important;}
.hmdg-banner-body{font-family:var(--hmdg-font) !important;font-size:11.5px !important;color:var(--hmdg-text) !important;margin:0 !important;padding:0 !important;line-height:1.55 !important;}
.hmdg-banner-buttons{display:flex !important;gap:6px !important;flex-shrink:0 !important;flex-wrap:wrap !important;align-items:center !important;}
.hmdg-ccm .hmdg-btn{display:inline-flex !important;align-items:center !important;justify-content:center !important;padding:6px 13px !important;font-family:var(--hmdg-font) !important;font-size:12px !important;font-weight:600 !important;border-radius:var(--hmdg-radius) !important;cursor:pointer !important;transition:all .2s !important;white-space:nowrap !important;line-height:1.4 !important;text-decoration:none !important;border:2px solid transparent !important;-webkit-appearance:none !important;}
.hmdg-ccm .hmdg-btn-equal{background:transparent !important;border-color:var(--hmdg-primary) !important;color:var(--hmdg-primary) !important;}
.hmdg-ccm .hmdg-btn-equal:hover,.hmdg-ccm .hmdg-btn-equal:focus{background:var(--hmdg-primary) !important;color:#fff !important;}
.hmdg-ccm .hmdg-btn-outline{background:transparent !important;border-color:var(--hmdg-border) !important;color:var(--hmdg-text) !important;}
.hmdg-ccm .hmdg-btn-outline:hover,.hmdg-ccm .hmdg-btn-outline:focus{background:rgba(0,0,0,.05) !important;}
.hmdg-ccm .hmdg-link{color:var(--hmdg-primary) !important;text-decoration:underline !important;}
.hmdg-ccm .hmdg-link-btn{background:none !important;border:none !important;padding:0 !important;color:var(--hmdg-primary) !important;font-size:12.5px !important;font-family:var(--hmdg-font) !important;cursor:pointer !important;text-decoration:underline !important;}
.hmdg-reopen{display:none;position:fixed !important;bottom:16px !important;left:16px !important;z-index:var(--hmdg-z) !important;width:34px !important;height:34px !important;border-radius:50% !important;background:var(--hmdg-primary) !important;color:#fff !important;border:none !important;cursor:pointer !important;padding:0 !important;align-items:center !important;justify-content:center !important;box-shadow:0 2px 10px rgba(0,0,0,.22) !important;transition:transform .2s,box-shadow .2s !important;}
.hmdg-reopen.hmdg-open{display:flex !important;}
.hmdg-reopen:hover,.hmdg-reopen:focus{transform:scale(1.1) !important;box-shadow:0 4px 16px rgba(0,0,0,.3) !important;}
.hmdg-reopen svg{width:18px !important;height:18px !important;}
.hmdg-overlay{display:none;position:fixed !important;inset:0 !important;z-index:calc(var(--hmdg-z) + 10) !important;background:rgba(0,0,0,.55) !important;align-items:center !important;justify-content:center !important;padding:16px !important;}
.hmdg-overlay.hmdg-open{display:flex !important;animation:hmdgFadeIn .2s ease !important;}
@keyframes hmdgFadeIn{from{opacity:0}to{opacity:1}}
.hmdg-modal{background:var(--hmdg-bg) !important;border-radius:12px !important;width:100% !important;max-width:640px !important;max-height:88vh !important;display:flex !important;flex-direction:column !important;box-shadow:0 24px 64px rgba(0,0,0,.28) !important;overflow:hidden !important;}
.hmdg-overlay.hmdg-open .hmdg-modal{animation:hmdgPopIn .25s ease !important;}
@keyframes hmdgPopIn{from{transform:translateY(16px);opacity:0}to{transform:translateY(0);opacity:1}}
.hmdg-modal-header{padding:20px 24px 14px !important;border-bottom:1px solid var(--hmdg-border) !important;display:flex !important;align-items:center !important;justify-content:space-between !important;flex-shrink:0 !important;}
.hmdg-modal-title{font-family:var(--hmdg-font) !important;font-size:18px !important;font-weight:800 !important;color:var(--hmdg-heading) !important;margin:0 !important;padding:0 !important;border:none !important;display:block !important;line-height:1.3 !important;}
.hmdg-modal-close{background:none !important;border:none !important;font-size:26px !important;line-height:1 !important;cursor:pointer !important;padding:4px 8px !important;border-radius:4px !important;color:var(--hmdg-text) !important;display:flex !important;align-items:center !important;transition:background .2s !important;}
.hmdg-modal-close:hover{background:rgba(0,0,0,.07) !important;}
.hmdg-modal-body{padding:18px 24px !important;overflow-y:auto !important;flex:1 !important;-webkit-overflow-scrolling:touch !important;}
.hmdg-ccm .hmdg-intro{font-family:var(--hmdg-font) !important;font-size:13px !important;color:var(--hmdg-text) !important;margin:0 0 10px !important;line-height:1.65 !important;}
.hmdg-cat{border-bottom:1px solid var(--hmdg-border) !important;padding:10px 0 !important;}
.hmdg-cat:last-child{border-bottom:none !important;}
.hmdg-cat-row{display:flex !important;align-items:center !important;justify-content:space-between !important;gap:8px !important;}
.hmdg-cat-toggle{background:none !important;border:none !important;cursor:pointer !important;padding:4px 0 !important;display:flex !important;align-items:center !important;gap:8px !important;flex:1 !important;text-align:left !important;}
.hmdg-cat-name{font-family:var(--hmdg-font) !important;font-size:14px !important;font-weight:700 !important;color:var(--hmdg-heading) !important;}
.hmdg-arrow{display:inline-block !important;font-size:20px !important;line-height:1 !important;width:14px !important;color:var(--hmdg-primary) !important;transition:transform .2s !important;flex-shrink:0 !important;}
.hmdg-cat-toggle[aria-expanded="true"] .hmdg-arrow{transform:rotate(90deg) !important;}
.hmdg-cat-desc{padding:8px 4px 4px 22px !important;}
.hmdg-cat-desc p{font-family:var(--hmdg-font) !important;font-size:12.5px !important;color:var(--hmdg-text) !important;margin:0 0 8px !important;line-height:1.65 !important;}
.hmdg-cat-desc code{background:rgba(0,0,0,.07) !important;padding:1px 5px !important;border-radius:3px !important;font-size:11.5px !important;}
.hmdg-always-on{font-size:12px !important;font-weight:700 !important;color:#198754 !important;white-space:nowrap !important;flex-shrink:0 !important;}
.hmdg-cookie-table{width:100% !important;border-collapse:collapse !important;font-family:var(--hmdg-font) !important;font-size:11.5px !important;margin-top:8px !important;margin-bottom:4px !important;}
.hmdg-cookie-table th,.hmdg-cookie-table td{text-align:left !important;padding:5px 8px !important;border:1px solid var(--hmdg-border) !important;color:var(--hmdg-text) !important;line-height:1.5 !important;}
.hmdg-cookie-table th{background:rgba(0,0,0,.04) !important;font-weight:700 !important;font-size:11px !important;color:var(--hmdg-heading) !important;}
.hmdg-cookie-table tr:nth-child(even) td{background:rgba(0,0,0,.02) !important;}
.hmdg-cookie-table code{background:rgba(0,0,0,.07) !important;padding:1px 4px !important;border-radius:3px !important;font-size:10.5px !important;}
.hmdg-toggle{position:relative !important;display:inline-block !important;width:46px !important;height:26px !important;flex-shrink:0 !important;margin:0 !important;cursor:pointer !important;}
.hmdg-toggle input{position:absolute !important;opacity:0 !important;width:0 !important;height:0 !important;}
.hmdg-toggle-track{position:absolute !important;inset:0 !important;background:#bbb !important;border-radius:26px !important;transition:.25s !important;}
.hmdg-toggle-thumb{position:absolute !important;width:20px !important;height:20px !important;left:3px !important;bottom:3px !important;background:#fff !important;border-radius:50% !important;transition:.25s !important;box-shadow:0 1px 4px rgba(0,0,0,.2) !important;display:block !important;}
.hmdg-toggle input:checked~.hmdg-toggle-track{background:var(--hmdg-primary) !important;}
.hmdg-toggle input:checked~.hmdg-toggle-track .hmdg-toggle-thumb{transform:translateX(20px) !important;}
.hmdg-toggle input:focus-visible~.hmdg-toggle-track{outline:3px solid var(--hmdg-primary) !important;outline-offset:2px !important;}
.hmdg-modal-footer{padding:14px 24px !important;border-top:1px solid var(--hmdg-border) !important;flex-shrink:0 !important;}
.hmdg-modal-actions{display:flex !important;gap:8px !important;margin-bottom:10px !important;flex-wrap:wrap !important;}
.hmdg-modal-actions .hmdg-btn{flex:1 !important;min-width:90px !important;}
.hmdg-powered{text-align:right !important;font-size:11px !important;color:var(--hmdg-text) !important;opacity:.6 !important;margin:0 !important;font-family:var(--hmdg-font) !important;}
.hmdg-powered strong{color:var(--hmdg-primary) !important;}
@media(max-width:768px){.hmdg-banner-inner{flex-direction:column !important;align-items:flex-start !important;}.hmdg-banner-buttons{width:100% !important;justify-content:flex-end !important;}.hmdg-modal{max-height:92vh !important;}.hmdg-modal-actions{flex-direction:column !important;}.hmdg-modal-actions .hmdg-btn{width:100% !important;}.hmdg-cookie-table{font-size:10.5px !important;}.hmdg-cookie-table th,.hmdg-cookie-table td{padding:4px 6px !important;}}
@media(max-width:480px){.hmdg-banner{padding:12px 16px !important;}.hmdg-ccm .hmdg-btn{width:100% !important;}.hmdg-banner-buttons{justify-content:stretch !important;}}
CSS;
    }

    /* ==========================================================================
       JAVASCRIPT — v1.2.0
       NEW in v1.2.0:
       ─ Universal booking tracker: works for Cliniko, Calendly, Acuity, PracSuite,
         Phorest, YouCanBook.me, Jane App, Timely, SimplyBook.me, and any custom domain.
       ─ postMessage listener: catches iframe booking completion events from ALL
         supported platforms and fires booking_completed to GA4 via server-side MP.
       ─ Return-redirect detection: if the booking platform redirects back to the
         WordPress site with ?booking_confirmed in the URL, fire booking_completed.
       ─ pointerdown trigger instead of click for MP firing (fires earlier, more
         reliable before navigation begins).
       ─ page_referrer + event_id added to all MP payloads.
       ─ visitor_ip sent in payload for GA4 geo attribution.
       ─ Deduplication guard: postMessage completion only fires once per page load.
    ========================================================================== */
    private function get_js(): string {
        // Nowdoc below allows no PHP interpolation, so the version banner is built
        // separately and prepended rather than hardcoded, per the existing pattern
        // of passing dynamic values into this script via window.hmdgCCM.
        $banner = '/* HMDG Cookie Consent Mode v2 — v' . esc_js( HMDG_CCM_VERSION ) . ' | Universal Booking Tracker */' . "\n";
        return $banner . <<<'JS'
(function () {
  'use strict';
  if (window._hmdgCCMBooted) return;
  window._hmdgCCMBooted = true;

  var cfg        = window.hmdgCCM   || {};
  var CNAME      = cfg.cookieName   || 'hmdg_cookie_consent';
  var EXPIRY     = cfg.cookieExpiry || 180;
  var DEBUG      = !!cfg.debug;
  var POLICY_VER = cfg.policyVersion || '1';
  var VERSION    = cfg.version       || '';
  var RELOAD     = !!cfg.reloadConsent;
  var GA4_ID     = cfg.ga4MeasurementId || '';

  /* v1.2.0: universal booking config */
  var DECORATOR_ON    = cfg.decoratorEnabled !== false;
  var MP_ON           = !!cfg.mpTrackingEnabled;
  var MP_BOOK_NOW     = cfg.mpBookNowEndpoint   || '';
  var MP_COMPLETE     = cfg.mpCompleteEndpoint  || '';
  var MP_NONCE        = cfg.mpNonce             || '';
  var BOOKING_DOMAINS = cfg.bookingDomains      || [];
  var IFRAME_ON       = !!cfg.iframeTrackingEnabled;
  var REDIRECT_ON     = !!cfg.redirectTrackingEnabled;
  var REDIRECT_PARAM  = cfg.redirectParam       || 'booking_confirmed';
  var PM_MATCHERS     = cfg.postMessageMatchers  || []; // [{platform, test}]

  /* Deduplication: only fire booking_completed once per page load per platform */
  var _completedPlatforms = {};

  function log()  { if(!DEBUG) return; console.log.apply(console, ['[HMDG CCM]'].concat(Array.prototype.slice.call(arguments))); }
  function warn() { console.warn.apply(console, ['[HMDG CCM]'].concat(Array.prototype.slice.call(arguments))); }

  /* ─── Cookie helpers ──────────────────────────────────────────────────── */
  function writeCookie(data) {
    var d = new Date(); d.setDate(d.getDate() + EXPIRY);
    var sec = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = CNAME + '=' + encodeURIComponent(JSON.stringify(data))
      + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax' + sec;
  }
  function readCookie() {
    var esc = CNAME.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var m = document.cookie.match('(?:^|; )' + esc + '=([^;]*)');
    if (!m) return null;
    try { return JSON.parse(decodeURIComponent(m[1])); } catch(e) { return null; }
  }
  function readRawCookie(name) {
    var esc = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var m = document.cookie.match('(?:^|; )' + esc + '=([^;]*)');
    return m ? decodeURIComponent(m[1]) : null;
  }

  /* ─── GA4 cookie parsers ──────────────────────────────────────────────── */
  function getGA4ClientId() {
    var raw = readRawCookie('_ga');
    if (!raw) return null;
    var p = raw.split('.');
    if (p.length >= 4) return p[2] + '.' + p[3];
    return null;
  }

  function getGA4SessionId() {
    if (!GA4_ID) return null;
    var suffix = GA4_ID.replace(/^G-/i, '');
    var raw    = readRawCookie('_ga_' + suffix);
    if (!raw) return null;
    try {
      /* New format May 2025+: GS2.1.sSSSSSSSSS$o... */
      if (raw.indexOf('GS2') === 0) {
        var stripped = raw.replace(/^GS2\.1\./, '');
        var segs = stripped.split('$');
        /* Defensive: find segment starting with 's' followed by digits */
        for (var i = 0; i < segs.length; i++) {
          if (/^s\d+$/.test(segs[i])) return segs[i].slice(1);
        }
      }
      /* Legacy: GS1.1.SSSSSSSSSS.N... */
      var parts = raw.split('.');
      if (parts.length >= 3 && /^\d+$/.test(parts[2])) return parts[2];
    } catch(e) {}
    return null;
  }

  function getStoredGclid() {
    var raw = readRawCookie('_gcl_aw');
    if (!raw) return null;
    var p = raw.split('.');
    return p.length >= 3 ? p.slice(2).join('.') : null;
  }

  /* ─── gtag helpers ────────────────────────────────────────────────────── */
  function ensureGtag() {
    window.dataLayer = window.dataLayer || [];
    if (typeof window.gtag !== 'function') window.gtag = function(){ window.dataLayer.push(arguments); };
  }

  function updateConsent(c) {
    ensureGtag();
    var signals = {
      analytics_storage:       c.analytics  ? 'granted' : 'denied',
      ad_storage:              c.marketing  ? 'granted' : 'denied',
      ad_user_data:            c.marketing  ? 'granted' : 'denied',
      ad_personalization:      c.marketing  ? 'granted' : 'denied',
      functionality_storage:   c.functional ? 'granted' : 'denied',
      personalization_storage: c.functional ? 'granted' : 'denied'
    };
    window.gtag('consent', 'update', signals);
    (window.dataLayer = window.dataLayer || []).push({
      event: 'hmdg_consent_update',
      hmdg_consent: {
        necessary: true, functional: !!c.functional, analytics: !!c.analytics,
        performance: !!c.performance, marketing: !!c.marketing,
        policyVersion: POLICY_VER, consentedAt: c.consentedAt || new Date().toISOString()
      },
      consent_analytics_storage:  signals.analytics_storage,
      consent_ad_storage:         signals.ad_storage,
      consent_ad_user_data:       signals.ad_user_data,
      consent_ad_personalization: signals.ad_personalization
    });
    log('📡 Consent signals updated:', signals);
  }

  function buildConsent(o) {
    return Object.assign({
      version: '1.0', policyVersion: POLICY_VER,
      consentedAt: new Date().toISOString(),
      necessary: true, functional: false, analytics: false,
      performance: false, marketing: false
    }, o || {});
  }

  /* ─── Element helpers ─────────────────────────────────────────────────── */
  function el(id)        { return document.getElementById(id); }
  function isChecked(id) { var e = el(id); return e ? e.checked : false; }
  function setCheck(id,v){ var e = el(id); if(e) e.checked = !!v; }

  /* ─── Banner / Modal ──────────────────────────────────────────────────── */
  function showBanner() { var b=el('hmdg-banner'); if(!b)return; b.classList.add('hmdg-open'); var r=el('hmdg-reopen'); if(r)r.classList.remove('hmdg-open'); setTimeout(function(){b.focus();},50); }
  function hideBanner() { var b=el('hmdg-banner'); if(b) b.classList.remove('hmdg-open'); }
  function showReopen() { var r=el('hmdg-reopen'); if(r) r.classList.add('hmdg-open'); }
  function openModal() {
    var saved = readCookie();
    if (saved) { setCheck('hmdg-chk-functional',saved.functional); setCheck('hmdg-chk-analytics',saved.analytics); setCheck('hmdg-chk-performance',saved.performance); setCheck('hmdg-chk-marketing',saved.marketing); }
    else { ['functional','analytics','performance','marketing'].forEach(function(k){ setCheck('hmdg-chk-'+k, false); }); }
    var o = el('hmdg-modal-overlay'); if(!o) return;
    o.classList.add('hmdg-open'); document.body.style.overflow = 'hidden';
    setTimeout(function(){ var c=el('hmdg-modal-close'); if(c)c.focus(); }, 60);
  }
  function closeModal() {
    var o = el('hmdg-modal-overlay'); if(!o) return;
    var active = document.activeElement; if(active && o.contains(active)) active.blur();
    o.classList.remove('hmdg-open'); document.body.style.overflow = '';
  }
  function maybeReload() { if (RELOAD) setTimeout(function(){ window.location.reload(); }, 150); }

  /* ─── Consent actions ─────────────────────────────────────────────────── */
  function doAcceptAll() {
    var c = buildConsent({functional:true,analytics:true,performance:true,marketing:true});
    writeCookie(c); updateConsent(c); hideBanner(); closeModal(); showReopen();
    console.log('[HMDG CCM] ✅ All cookies accepted. Policy v'+POLICY_VER+' | '+c.consentedAt);
    maybeReload();
  }
  function doRejectAll() {
    var c = buildConsent();
    writeCookie(c); updateConsent(c); hideBanner(); closeModal(); showReopen();
    console.log('[HMDG CCM] ✅ Only necessary cookies active. Policy v'+POLICY_VER+' | '+c.consentedAt);
    maybeReload();
  }
  function doSavePreferences() {
    var c = buildConsent({functional:isChecked('hmdg-chk-functional'),analytics:isChecked('hmdg-chk-analytics'),performance:isChecked('hmdg-chk-performance'),marketing:isChecked('hmdg-chk-marketing')});
    writeCookie(c); updateConsent(c); hideBanner(); closeModal(); showReopen();
    console.log('[HMDG CCM] ✅ Preferences saved. Policy v'+POLICY_VER+' | '+c.consentedAt);
    maybeReload();
  }

  /* ─── Booking domain helpers ──────────────────────────────────────────── */
  function isBookingUrl(href) {
    if (!href || !BOOKING_DOMAINS.length) return false;
    try {
      var h = (href.indexOf('//') === -1) ? new URL('https://' + href) : new URL(href);
      return BOOKING_DOMAINS.some(function(d) {
        return h.hostname === d || h.hostname.endsWith('.' + d);
      });
    } catch(e) { return false; }
  }

  function getLiveGl() {
    try {
      var gtd = window.google_tag_data;
      if (gtd && gtd.gl && typeof gtd.gl.decorateUrl === 'function') {
        var t = gtd.gl.decorateUrl('https://test.cliniko.com/');
        var m = t.match(/[?&]_gl=([^&#]*)/);
        if (m) return m[1];
      }
      var pm = window.location.search.match(/[?&]_gl=([^&#]*)/);
      if (pm) return pm[1];
    } catch(e) {}
    return null;
  }

  function decorateBookingUrl(href) {
    try {
      var url = new URL(href);
      var gl  = getLiveGl();
      var gc  = getStoredGclid();
      if (gl) { url.searchParams.set('_gl', gl); log('🔗 _gl decorated'); }
      if (gc && !url.searchParams.has('gclid')) { url.searchParams.set('gclid', gc); log('🔗 gclid decorated'); }
      return url.toString();
    } catch(e) { return href; }
  }

  /* ─── Generate a lightweight unique event_id ──────────────────────────── */
  function genEventId() {
    return 'hmdg_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
  }

  /* ═══════════════════════════════════════════════════════════════════════
     CORE MP FIRING — used by both book_now_click and booking_completed
  ═══════════════════════════════════════════════════════════════════════ */
  function fireMPEvent(endpoint, bookingUrl, platform, eventOverrides) {
    if (!MP_ON || !endpoint || !MP_NONCE) {
      log('ℹ MP tracking not configured — skipping.');
      return;
    }

    var clientId  = getGA4ClientId();
    var sessionId = getGA4SessionId();
    var gclid     = getStoredGclid();

    if (!clientId) {
      log('⚠ MP: No _ga cookie — user may not have accepted analytics consent.');
      return;
    }

    var payload = Object.assign({
      client_id:    clientId,
      session_id:   sessionId  || '',
      booking_url:  bookingUrl || '',
      page_url:     window.location.href,
      page_title:   document.title,
      page_referrer:document.referrer || '',
      gclid:        gclid      || '',
      platform:     platform   || 'unknown',
      event_id:     genEventId()
    }, eventOverrides || {});

    log('📡 Firing MP [' + (endpoint === MP_BOOK_NOW ? 'book_now_click' : 'booking_completed') + '] platform=' + platform + ' client_id=' + clientId);

    if (typeof fetch === 'function') {
      fetch(endpoint, {
        method:    'POST',
        keepalive: true,
        headers: { 'Content-Type': 'application/json', 'X-HMDG-Nonce': MP_NONCE },
        body: JSON.stringify(payload)
      }).then(function(r){ return r.json(); }).then(function(d){
        if (d.success) log('✅ MP fired successfully. event=' + d.event + ' platform=' + d.platform);
        else warn('⚠ MP error:', d.error || 'unknown');
      }).catch(function(e){ warn('⚠ MP fetch failed:', e.message); });
    } else {
      /* XHR fallback for ancient browsers */
      var xhr = new XMLHttpRequest();
      xhr.open('POST', endpoint, true);
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.setRequestHeader('X-HMDG-Nonce', MP_NONCE);
      xhr.send(JSON.stringify(payload));
      log('📡 MP fired via XHR');
    }
  }

  /* ─── book_now_click (external link click) ────────────────────────────── */
  function fireBookNowClick(bookingUrl, platform) {
    fireMPEvent(MP_BOOK_NOW, bookingUrl, platform || 'unknown');
  }

  /* ─── booking_completed (iframe postMessage or redirect) ─────────────── */
  function fireBookingCompleted(platform, bookingUrl) {
    /* Deduplicate: only fire once per platform per page load */
    if (_completedPlatforms[platform]) {
      log('ℹ booking_completed already fired for', platform, '— skipping duplicate.');
      return;
    }
    _completedPlatforms[platform] = true;

    /* Also push to GTM dataLayer so GTM tags can fire as well */
    (window.dataLayer = window.dataLayer || []).push({
      event:           'hmdg_booking_completed',
      booking_platform: platform,
      booking_url:     bookingUrl || ''
    });
    log('📤 dataLayer: hmdg_booking_completed platform=' + platform);

    fireMPEvent(MP_COMPLETE, bookingUrl || '', platform);
  }

  /* ═══════════════════════════════════════════════════════════════════════
     v1.2.0: UNIVERSAL postMessage LISTENER
     Listens for booking completion messages from all supported platforms.
     Each platform has a 'test' expression that's evaluated safely.
  ═══════════════════════════════════════════════════════════════════════ */
  function setupPostMessageListener() {
    if (!IFRAME_ON || !PM_MATCHERS.length) return;

    window.addEventListener('message', function(e) {
      /* Ignore messages from the same origin (our own JS) */
      if (e.origin === window.location.origin) return;

      var data = e.data;

      /* Safety: ignore extremely large messages (>10KB) */
      try {
        var raw = typeof data === 'string' ? data : JSON.stringify(data);
        if (raw.length > 10240) return;
      } catch(ex) { return; }

      for (var i = 0; i < PM_MATCHERS.length; i++) {
        var matcher  = PM_MATCHERS[i];
        var platform = matcher.platform;
        var testExpr = matcher.test;
        var domains  = matcher.domains || [];

        /* v1.3.0: validate message origin against platform's known domains */
        var originHost = '';
        try { originHost = new URL(e.origin).hostname; } catch(oe) { continue; }
        var originValid = domains.length === 0; /* skip check if no domains defined */
        for (var d = 0; d < domains.length; d++) {
          if (originHost === domains[d] || originHost.indexOf('.' + domains[d]) > -1) {
            originValid = true; break;
          }
        }
        if (!originValid) continue;

        try {
          /* Evaluate the test expression with data in scope */
          var testFn = new Function('data', 'return (' + testExpr + ');');
          if (testFn(data)) {
            log('🎉 postMessage booking completion detected! platform=' + platform + ' origin=' + e.origin);
            fireBookingCompleted(platform, '');
            break; // one completion event per message
          }
        } catch(ex) {
          /* Silently ignore test evaluation errors */
        }
      }
    }, false);

    log('👂 postMessage listener active for', PM_MATCHERS.length, 'platform(s)');
  }

  /* ═══════════════════════════════════════════════════════════════════════
     v1.2.0: RETURN-REDIRECT DETECTOR
     Fires booking_completed if the booking platform redirected back to this
     WordPress page with ?booking_confirmed (or custom param) in the URL.
  ═══════════════════════════════════════════════════════════════════════ */
  function checkReturnRedirect() {
    if (!REDIRECT_ON) return;
    try {
      var params  = new URLSearchParams(window.location.search);
      var referer = document.referrer || '';
      if (!params.has(REDIRECT_PARAM)) return;

      /* Determine which platform redirected us by checking the referrer */
      var platform = 'unknown';
      if (referer) {
        for (var i = 0; i < BOOKING_DOMAINS.length; i++) {
          if (referer.indexOf(BOOKING_DOMAINS[i]) > -1) {
            platform = BOOKING_DOMAINS[i].split('.')[0]; // e.g. 'calendly'
            break;
          }
        }
      }

      log('🎉 Return redirect booking completion detected! platform=' + platform + ' referer=' + referer);
      fireBookingCompleted(platform, referer);

      /* Clean the URL so a page refresh doesn't re-fire */
      params.delete(REDIRECT_PARAM);
      var newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '') + window.location.hash;
      window.history.replaceState({}, document.title, newUrl);
    } catch(e) {}
  }

  /* ─── Booking link processor ──────────────────────────────────────────── */
  function getPlatformForUrl(href) {
    if (!href) return 'unknown';
    try {
      var h = new URL(href.indexOf('//') === -1 ? 'https://' + href : href);
      for (var i = 0; i < BOOKING_DOMAINS.length; i++) {
        var d = BOOKING_DOMAINS[i];
        if (h.hostname === d || h.hostname.endsWith('.' + d)) {
          return d.split('.')[0]; // rough platform name
        }
      }
    } catch(e) {}
    return 'unknown';
  }

  function attachDecoratorToLink(a) {
    if (a._hmdgDecorated) return;
    a._hmdgDecorated = true;
    function decorate() {
      var href = a.getAttribute('href');
      if (href && isBookingUrl(href)) a.setAttribute('href', decorateBookingUrl(href));
    }
    a.addEventListener('mousedown', decorate, {passive: true});
    a.addEventListener('touchstart', decorate, {passive: true});
  }

  function attachMPToLink(a) {
    if (a._hmdgMP) return;
    a._hmdgMP = true;
    /* v1.2.0: use pointerdown — fires earlier than click, before navigation begins */
    a.addEventListener('pointerdown', function() {
      var href = a.getAttribute('href');
      if (href && isBookingUrl(href)) {
        var platform = getPlatformForUrl(href);
        fireBookNowClick(href, platform);
      }
    });
  }

  function processBookingLink(a) {
    var href = a.getAttribute('href');
    if (!isBookingUrl(href)) return;
    if (DECORATOR_ON) attachDecoratorToLink(a);
    if (MP_ON)        attachMPToLink(a);
  }

  function processAllBookingLinks() {
    var count = 0;
    document.querySelectorAll('a[href]').forEach(function(a) {
      if (isBookingUrl(a.getAttribute('href'))) { processBookingLink(a); count++; }
    });
    if (count > 0) log('🔗 Booking links processed: ' + count);
  }

  function watchForNewBookingLinks() {
    if (!window.MutationObserver) return;
    var obs = new MutationObserver(function(mutations) {
      mutations.forEach(function(m) {
        m.addedNodes.forEach(function(node) {
          if (node.nodeType !== 1) return;
          if (node.tagName === 'A') processBookingLink(node);
          (node.querySelectorAll ? node.querySelectorAll('a[href]') : []).forEach(processBookingLink);
        });
      });
    });
    obs.observe(document.body, {childList: true, subtree: true});
  }

  /* ─── Duplicate script detection ─────────────────────────────────────── */
  function detectDuplicateScripts() {
    var patterns = [
      {sel:'script[src*="googletagmanager.com/gtm.js"]',  name:'GTM'},
      {sel:'script[src*="googletagmanager.com/gtag/js"]', name:'gtag.js'}
    ];
    var found = {}, dups = [];
    patterns.forEach(function(p) {
      var s = document.querySelectorAll(p.sel);
      if (s.length > 0) { found[p.name] = s.length; if (s.length > 1) dups.push(p.name + ' (×'+s.length+')'); }
    });
    if (dups.length > 0) warn('⚠ Multiple Google tags:', dups.join(', '));
    if (Object.keys(found).length > 0) log('🔍 Google tags on page:', found);
  }

  /* ─── Validator (debug only) ──────────────────────────────────────────── */
  function runValidator() {
    if (!DEBUG) return;
    var checks = [];
    var dl = window.dataLayer || [];
    checks.push({label:'Consent defaults set',       ok:dl.some(function(e){return e&&e[0]==='consent'&&e[1]==='default';})});
    checks.push({label:'gtag available',             ok:typeof window.gtag==='function'});
    checks.push({label:'Google tag detected',        ok:!!document.querySelector('script[src*="googletagmanager.com/gtm.js"],script[src*="googletagmanager.com/gtag/js"]')});
    checks.push({label:'Consent cookie found',       ok:!!readCookie()});
    checks.push({label:'_ga client_id readable',     ok:!!getGA4ClientId()});
    checks.push({label:'_ga_XXXX session_id readable',ok:!!getGA4SessionId()});
    checks.push({label:'Server-side MP configured',  ok:MP_ON&&!!MP_BOOK_NOW});
    checks.push({label:'iframe postMessage active',  ok:IFRAME_ON&&PM_MATCHERS.length>0});
    console.group('[HMDG CCM] Validator (v' + VERSION + ')');
    checks.forEach(function(c){ console[c.ok?'log':'warn']('  '+(c.ok?'✓':'⚠')+' '+c.label); });
    console.log('  —— '+checks.filter(function(c){return c.ok;}).length+'/'+checks.length+' passed');
    console.groupEnd();
  }

  /* ─── Wire events ─────────────────────────────────────────────────────── */
  function wireEvents() {
    var ba=el('hmdg-btn-accept');    if(ba) ba.addEventListener('click',doAcceptAll);
    var br=el('hmdg-btn-reject');    if(br) br.addEventListener('click',doRejectAll);
    var bc=el('hmdg-btn-customise'); if(bc) bc.addEventListener('click',openModal);
    var reopen=el('hmdg-reopen');
    if(reopen) reopen.addEventListener('click',function(){if(readCookie()){openModal();}else{showBanner();}});
    var mc=el('hmdg-modal-close');
    if(mc) mc.addEventListener('click',function(){closeModal();if(!readCookie())showBanner();});
    var mr=el('hmdg-modal-reject'); if(mr) mr.addEventListener('click',doRejectAll);
    var ms=el('hmdg-modal-save');   if(ms) ms.addEventListener('click',doSavePreferences);
    var ma=el('hmdg-modal-accept'); if(ma) ma.addEventListener('click',doAcceptAll);
    var ov=el('hmdg-modal-overlay');
    if(ov) ov.addEventListener('click',function(e){if(e.target===ov){closeModal();if(!readCookie())showBanner();}});
    document.querySelectorAll('.hmdg-cat-toggle').forEach(function(btn){
      btn.addEventListener('click',function(){
        var exp  = this.getAttribute('aria-expanded')==='true';
        var desc = document.getElementById(this.getAttribute('aria-controls'));
        this.setAttribute('aria-expanded',String(!exp));
        if(desc){if(exp)desc.setAttribute('hidden','');else desc.removeAttribute('hidden');}
      });
    });
    var sm=el('hmdg-showmore-btn');
    if(sm) sm.addEventListener('click',function(){
      var c=el('hmdg-showmore'); if(!c) return;
      var hidden=c.hasAttribute('hidden');
      if(hidden){c.removeAttribute('hidden');this.textContent='Show less';this.setAttribute('aria-expanded','true');}
      else{c.setAttribute('hidden','');this.textContent='Show more';this.setAttribute('aria-expanded','false');}
    });
    document.addEventListener('keydown',function(e){
      var o=el('hmdg-modal-overlay');
      if(!o||!o.classList.contains('hmdg-open')) return;
      if(e.key==='Escape'){closeModal();if(!readCookie())showBanner();return;}
      if(e.key!=='Tab') return;
      var modal=document.querySelector('.hmdg-modal'); if(!modal) return;
      var focusable=Array.prototype.slice.call(modal.querySelectorAll('button:not([disabled]),[href],input:not([disabled]),[tabindex]:not([tabindex="-1"])'));
      if(!focusable.length) return;
      var first=focusable[0], last=focusable[focusable.length-1];
      if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}
      else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}
    });
  }

  /* ─── Boot ────────────────────────────────────────────────────────────── */
  function boot() {
    wireEvents();
    detectDuplicateScripts();
    processAllBookingLinks();
    watchForNewBookingLinks();
    setupPostMessageListener();
    checkReturnRedirect();
    var saved = readCookie();
    if (!saved || !saved.version) { setTimeout(showBanner, 300); log('ℹ No consent — showing banner.'); }
    else { showReopen(); log('✅ Consent on record.', saved); }
    runValidator();
    log('🚀 HMDG CCM v' + VERSION + ' | Universal Booking Tracker | ' + BOOKING_DOMAINS.length + ' domain(s) | ' + PM_MATCHERS.length + ' platform(s)');
  }

  document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded', boot)
    : boot();
})();
JS;
    }

    /* ==========================================================================
       ADMIN — settings registration + sanitisation
    ========================================================================== */
    public function register_admin_menu(): void {
        $icon = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#a7aaad"><path d="M21.598 11.064a1.006 1.006 0 0 0-.854-.172A2.938 2.938 0 0 1 20 11c-1.654 0-3-1.346-3.003-2.937.005-.034.016-.136.017-.17a1 1 0 0 0-1.736-.806A2.967 2.967 0 0 1 13 8c-1.654 0-3-1.346-3-3 0-.085.007-.17.02-.254a1 1 0 0 0-1.562-.888C5.152 5.6 3 8.639 3 12c0 4.971 4.029 9 9 9s9-4.029 9-9c0-.085-.003-.17-.007-.254a1 1 0 0 0-.395-.682z"/></svg>');
        add_menu_page( 'HMDG Cookie Consent Mode v2', 'Cookie Consent', 'manage_options', 'hmdg-cookie-consent', [ $this, 'render_admin_page' ], $icon, 80 );
    }

    public function register_settings(): void {
        register_setting( 'hmdg_ccm_group', 'hmdg_ccm_options', [ 'sanitize_callback' => [ $this, 'sanitize_options' ] ] );
    }

    public function sanitize_options( $raw ): array {
        $raw = (array) $raw;

        /* Sanitise enabled platforms — only allow known platform keys */
        $known    = array_keys( self::PLATFORM_REGISTRY );
        $raw_plat = array_filter( array_map( 'sanitize_key', explode( ',', $raw['enabled_platforms'] ?? '' ) ) );
        $safe_plat = array_intersect( $raw_plat, $known );

        /*
         * v1.7.0: the MP secret is diverted to its own non-autoloaded option
         * and the settings blob stores '' in its place. The form field still
         * shows the value (the constructor overlays it back), so a human
         * re-saving the page round-trips it here unchanged.
         *
         * GUARDED to genuine settings-form submissions, and the guard is
         * load-bearing: register_setting() attaches this callback to EVERY
         * update_option('hmdg_ccm_options', ...) made while admin_init has
         * run — including HMDG_Config_Sync::apply()'s programmatic write
         * during an admin "Sync now", whose array holds '' for this key.
         * Undiverted, that write would wipe the secret the sync had just
         * stored. option_page is only ever posted by the options.php form.
         */
        if ( is_admin()
             && isset( $_POST['option_page'] )
             && $_POST['option_page'] === 'hmdg_ccm_group' ) {
            self::set_mp_secret( sanitize_text_field( $raw['ga4_api_secret'] ?? '' ) );
        }

        return [
            'gtm_id'                    => sanitize_text_field( $raw['gtm_id']                ?? '' ),
            'gtag_id'                   => sanitize_text_field( $raw['gtag_id']               ?? '' ),
            'ga4_measurement_id'        => sanitize_text_field( $raw['ga4_measurement_id']    ?? '' ),
            'ga4_api_secret'            => '',
            'privacy_policy_url'        => sanitize_text_field( $raw['privacy_policy_url']    ?? '/privacy-policy/' ),
            'cookie_policy_url'         => sanitize_text_field( $raw['cookie_policy_url']     ?? '/cookie-policy/' ),
            'terms_url'                 => sanitize_text_field( $raw['terms_url']             ?? '/terms-conditions/' ),
            'google_safety_url'         => esc_url_raw(         $raw['google_safety_url']     ?? 'https://business.safety.google/privacy/' ),
            'policy_version'            => sanitize_text_field( $raw['policy_version']        ?? '1' ),
            'reload_on_consent'         => ! empty( $raw['reload_on_consent'] ) ? 1 : 0,
            'debug_mode'                => ! empty( $raw['debug_mode'] ) ? 1 : 0,
            'ads_conversion_id'         => sanitize_text_field( $raw['ads_conversion_id']     ?? '' ),
            'ads_conversion_label'      => sanitize_text_field( $raw['ads_conversion_label']  ?? '' ),
            // v1.2.0
            'booking_domains'           => sanitize_text_field( $raw['booking_domains']       ?? 'cliniko.com' ),
            'booking_decorator_enabled' => ! empty( $raw['booking_decorator_enabled'] ) ? 1 : 0,
            'mp_tracking_enabled'       => ! empty( $raw['mp_tracking_enabled'] ) ? 1 : 0,
            'iframe_tracking_enabled'   => ! empty( $raw['iframe_tracking_enabled'] ) ? 1 : 0,
            'redirect_tracking_enabled' => ! empty( $raw['redirect_tracking_enabled'] ) ? 1 : 0,
            'redirect_param'            => sanitize_key( $raw['redirect_param'] ?? 'booking_confirmed' ),
            'enabled_platforms'         => implode( ',', $safe_plat ),
            // Legacy back-compat
            'cliniko_domains'           => sanitize_text_field( $raw['booking_domains']       ?? 'cliniko.com' ),
            'cliniko_decorator_enabled' => ! empty( $raw['booking_decorator_enabled'] ) ? 1 : 0,
        ];
    }

    /* ==========================================================================
       ADMIN PAGE RENDER
    ========================================================================== */
    public function render_admin_page(): void {
        if ( ! current_user_can('manage_options') ) return;
        $o           = $this->opts;
        $saved       = ! empty( $_GET['settings-updated'] );
        $configured  = $this->is_configured();
        $mp_ok       = $this->is_mp_configured();
        $enabled_plat= array_filter( array_map( 'trim', explode( ',', $o['enabled_platforms'] ?? '' ) ) );
        ?>
        <div class="wrap p-0" id="hmdg-admin-wrap">
            <!-- HEADER -->
            <div class="d-flex align-items-center gap-3 px-4 py-3 mb-0" style="background:linear-gradient(135deg,#1a56db,#1e40af);border-radius:0 0 12px 12px;margin-top:-8px;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#fff" width="36" height="36" style="flex-shrink:0;"><path d="M21.598 11.064a1.006 1.006 0 0 0-.854-.172A2.938 2.938 0 0 1 20 11c-1.654 0-3-1.346-3.003-2.937.005-.034.016-.136.017-.17a1 1 0 0 0-1.736-.806A2.967 2.967 0 0 1 13 8c-1.654 0-3-1.346-3-3 0-.085.007-.17.02-.254a1 1 0 0 0-1.562-.888C5.152 5.6 3 8.639 3 12c0 4.971 4.029 9 9 9s9-4.029 9-9c0-.085-.003-.17-.007-.254a1 1 0 0 0-.395-.682z"/><circle cx="9" cy="14" r="1.1"/><circle cx="12" cy="17" r="1.1"/><circle cx="7" cy="17" r="1.1"/><circle cx="15" cy="13" r="1.1"/></svg>
                <div>
                    <h1 class="text-white mb-0" style="font-size:20px;font-weight:800;line-height:1.2;">
                        HMDG Cookie Consent Mode v2
                        <span class="hmdg-badge ms-2 text-white" style="background:rgba(255,255,255,.2);font-size:11px;vertical-align:middle;">v<?php echo HMDG_CCM_VERSION; ?></span>
                    </h1>
                    <p class="text-white mb-0 mt-1" style="font-size:12px;opacity:.8;">UK GDPR &amp; PECR &nbsp;·&nbsp; Google Consent Mode v2 Advanced &nbsp;·&nbsp; Universal Booking Tracker &nbsp;·&nbsp; Server-Side MP &nbsp;·&nbsp; ICO Compliant</p>
                </div>
                <div class="ms-auto">
                    <?php if ($configured && $mp_ok): ?>
                    <span class="badge rounded-pill" style="background:rgba(34,197,94,.25);color:#bbf7d0;border:1px solid rgba(134,239,172,.4);font-size:11px;padding:5px 12px;"><span class="hmdg-dot me-1" style="background:#4ade80;"></span> Active + MP</span>
                    <?php elseif ($configured): ?>
                    <span class="badge rounded-pill" style="background:rgba(234,179,8,.2);color:#fef08a;border:1px solid rgba(253,224,71,.3);font-size:11px;padding:5px 12px;"><span class="hmdg-dot me-1" style="background:#facc15;"></span> Active</span>
                    <?php else: ?>
                    <span class="badge rounded-pill" style="background:rgba(234,179,8,.2);color:#fef08a;border:1px solid rgba(253,224,71,.3);font-size:11px;padding:5px 12px;"><span class="hmdg-dot me-1" style="background:#facc15;"></span> Not Configured</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="p-4" style="max-width:1100px;">
                <?php if ($saved): ?>
                <div class="alert alert-success d-flex align-items-center gap-2 mb-4" role="alert" style="border-radius:8px;">
                    <svg width="18" height="18" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    <div><strong>Settings saved.</strong> Clear all caches after saving.</div>
                </div>
                <?php endif; ?>

                <form method="post" action="options.php">
                <?php settings_fields('hmdg_ccm_group'); ?>
                <div class="row g-4">
                    <div class="col-lg-8">

                        <!-- Google Tag IDs -->
                        <div class="card mb-4">
                            <div class="card-header d-flex align-items-center gap-2"><span style="font-size:16px;">🎯</span><h2>Google Tag IDs</h2></div>
                            <div class="card-body">
                                <div class="hmdg-alert mb-4"><strong>Preferred:</strong> Enter your <strong>GTM ID</strong>. <strong>Alternative:</strong> Google tag ID for direct gtag.js mode. <em>Do not fill both.</em></div>
                                <div class="field-row"><div class="row align-items-start">
                                    <div class="col-sm-4"><label class="form-label" for="gtm_id">GTM ID <span class="badge ms-1" style="background:#dcfce7;color:#166534;font-size:10px;">Recommended</span></label></div>
                                    <div class="col-sm-8"><input type="text" id="gtm_id" name="hmdg_ccm_options[gtm_id]" value="<?php echo esc_attr($o['gtm_id']); ?>" placeholder="GTM-XXXXXXX" class="form-control form-control-sm"></div>
                                </div></div>
                                <div class="field-row"><div class="row align-items-start">
                                    <div class="col-sm-4"><label class="form-label" for="gtag_id">Google tag ID</label></div>
                                    <div class="col-sm-8"><input type="text" id="gtag_id" name="hmdg_ccm_options[gtag_id]" value="<?php echo esc_attr($o['gtag_id']); ?>" placeholder="G-XXXXXXXXXX" class="form-control form-control-sm"><div class="form-text">Only if not using GTM.</div></div>
                                </div></div>
                                <div class="field-row"><div class="row align-items-start">
                                    <div class="col-sm-4"><label class="form-label" for="ads_conversion_id">Ads Conversion ID</label></div>
                                    <div class="col-sm-8"><input type="text" id="ads_conversion_id" name="hmdg_ccm_options[ads_conversion_id]" value="<?php echo esc_attr($o['ads_conversion_id'] ?? ''); ?>" placeholder="AW-XXXXXXXXX" class="form-control form-control-sm"></div>
                                </div></div>
                            </div>
                        </div>

                        <!-- Server-Side MP -->
                        <div class="card mb-4" style="border-color:#c4b5fd;">
                            <div class="card-header d-flex align-items-center gap-2" style="background:#f5f3ff;"><span style="font-size:16px;">⚡</span><h2>Server-Side Conversion Tracking <span class="badge ms-1" style="background:#ede9fe;color:#5b21b6;font-size:10px;">GA4 Measurement Protocol</span></h2></div>
                            <div class="card-body">
                                <div class="hmdg-alert-purple mb-3">Fires <code>book_now_click</code> and <code>booking_completed</code> events directly from the WordPress server to Google Analytics, so bookings are still recorded when browser-side tracking is blocked. Analytics consent is still required — these events need the <code>_ga</code> cookie, which only exists once the visitor has accepted analytics.</div>
                                <div class="field-row"><div class="row align-items-start">
                                    <div class="col-sm-4"><label class="form-label" for="mp_tracking_enabled">Enable Server-Side Tracking</label></div>
                                    <div class="col-sm-8"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="mp_tracking_enabled" name="hmdg_ccm_options[mp_tracking_enabled]" value="1" role="switch" <?php checked(1, $o['mp_tracking_enabled'] ?? 1); ?> style="width:40px;height:22px;cursor:pointer;"></div></div>
                                </div></div>
                                <div class="field-row"><div class="row align-items-start">
                                    <div class="col-sm-4"><label class="form-label" for="ga4_measurement_id">GA4 Measurement ID <span style="color:#dc2626;">*</span></label></div>
                                    <div class="col-sm-8"><input type="text" id="ga4_measurement_id" name="hmdg_ccm_options[ga4_measurement_id]" value="<?php echo esc_attr($o['ga4_measurement_id']); ?>" placeholder="G-XXXXXXXXXX" class="form-control form-control-sm"><div class="form-text">GA4 → Admin → Data Streams → Measurement ID</div></div>
                                </div></div>
                                <div class="field-row"><div class="row align-items-start">
                                    <div class="col-sm-4"><label class="form-label" for="ga4_api_secret">MP API Secret <span style="color:#dc2626;">*</span></label></div>
                                    <div class="col-sm-8"><input type="password" id="ga4_api_secret" name="hmdg_ccm_options[ga4_api_secret]" value="<?php echo esc_attr($o['ga4_api_secret']); ?>" placeholder="••••••••••••••••••••" class="form-control form-control-sm" autocomplete="new-password"><div class="form-text">GA4 → Admin → Data Streams → Measurement Protocol API secrets → Create</div></div>
                                </div></div>
                            </div>
                        </div>

                        <!-- Universal Booking Tracker -->
                        <div class="card mb-4" style="border-color:#a5f3fc;">
                            <div class="card-header d-flex align-items-center gap-2" style="background:#f0fdff;"><span style="font-size:16px;">🌐</span><h2>Universal Booking Tracker <span class="badge ms-1" style="background:#cffafe;color:#0e7490;font-size:10px;">v1.2.0 — New</span></h2></div>
                            <div class="card-body">
                                <div class="hmdg-alert-success mb-3">Works with any booking platform — Cliniko, Calendly, Acuity, PracSuite, and more. Fires <code>book_now_click</code> on external link clicks and <code>booking_completed</code> when an iframe booking confirms.</div>

                                <!-- Enabled platforms -->
                                <div class="field-row">
                                    <label class="form-label d-block mb-2">Enabled Platforms <span class="badge" style="background:#f1f5f9;color:#374151;font-size:10px;border:1px solid #e2e8f0;">select all that apply</span></label>
                                    <div class="platform-grid">
                                    <?php foreach ( self::PLATFORM_REGISTRY as $key => $plat ) :
                                        $checked = in_array( $key, $enabled_plat, true ) ? 'checked' : '';
                                    ?>
                                    <label class="platform-pill">
                                        <input type="checkbox" name="hmdg_ccm_options[enabled_platforms_<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($key); ?>" <?php echo $checked; ?> onchange="hmdgUpdatePlatforms()">
                                        <span><?php echo esc_html($plat['label']); ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" id="hmdg_enabled_platforms" name="hmdg_ccm_options[enabled_platforms]" value="<?php echo esc_attr( implode(',', $enabled_plat) ); ?>">
                                    <div class="form-text mt-2">Selected platforms' domains are automatically added to the booking domain list.</div>
                                </div>
                                <script>
                                function hmdgUpdatePlatforms(){
                                  var selected=[];
                                  document.querySelectorAll('[name^="hmdg_ccm_options[enabled_platforms_"]').forEach(function(cb){if(cb.checked)selected.push(cb.value);});
                                  document.getElementById('hmdg_enabled_platforms').value=selected.join(',');
                                }
                                </script>

                                <!-- Custom booking domains -->
                                <div class="field-row"><div class="row align-items-start">
                                    <div class="col-sm-4"><label class="form-label" for="booking_domains">Additional Domains</label></div>
                                    <div class="col-sm-8">
                                        <input type="text" id="booking_domains" name="hmdg_ccm_options[booking_domains]" value="<?php echo esc_attr($o['booking_domains'] ?? 'cliniko.com'); ?>" class="form-control form-control-sm" placeholder="myplatform.com, anotherbooking.co.uk">
                                        <div class="form-text">Add any custom booking domains not in the list above. Comma-separated.</div>
                                    </div>
                                </div></div>

                                <!-- Toggles -->
                                <div class="field-row"><div class="row align-items-start">
                                    <div class="col-sm-4"><label class="form-label">Features</label></div>
                                    <div class="col-sm-8">
                                        <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="booking_decorator_enabled" name="hmdg_ccm_options[booking_decorator_enabled]" value="1" role="switch" <?php checked(1, $o['booking_decorator_enabled'] ?? 1); ?> style="width:40px;height:22px;cursor:pointer;"><label class="form-check-label ms-2 form-label mb-0" for="booking_decorator_enabled">Append <code>_gl</code> + <code>gclid</code> to booking links at click time</label></div>
                                        <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="iframe_tracking_enabled" name="hmdg_ccm_options[iframe_tracking_enabled]" value="1" role="switch" <?php checked(1, $o['iframe_tracking_enabled'] ?? 1); ?> style="width:40px;height:22px;cursor:pointer;"><label class="form-check-label ms-2 form-label mb-0" for="iframe_tracking_enabled">Listen for iframe postMessage booking completion (fires <code>booking_completed</code>)</label></div>
                                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="redirect_tracking_enabled" name="hmdg_ccm_options[redirect_tracking_enabled]" value="1" role="switch" <?php checked(1, $o['redirect_tracking_enabled'] ?? 0); ?> style="width:40px;height:22px;cursor:pointer;"><label class="form-check-label ms-2 form-label mb-0" for="redirect_tracking_enabled">Detect return-redirect booking completion via URL parameter</label></div>
                                    </div>
                                </div></div>

                                <div class="field-row"><div class="row align-items-start">
                                    <div class="col-sm-4"><label class="form-label" for="redirect_param">Redirect Param</label></div>
                                    <div class="col-sm-8"><input type="text" id="redirect_param" name="hmdg_ccm_options[redirect_param]" value="<?php echo esc_attr($o['redirect_param'] ?? 'booking_confirmed'); ?>" class="form-control form-control-sm" style="max-width:220px;"><div class="form-text">URL parameter to watch for on return redirects. e.g. <code>?booking_confirmed=1</code></div></div>
                                </div></div>
                            </div>
                        </div>

                        <!-- Policy URLs -->
                        <div class="card mb-4">
                            <div class="card-header d-flex align-items-center gap-2"><span style="font-size:16px;">🔗</span><h2>Policy URLs</h2></div>
                            <div class="card-body">
                                <div class="field-row"><div class="row align-items-start"><div class="col-sm-4"><label class="form-label" for="privacy_policy_url">Privacy Policy URL</label></div><div class="col-sm-8"><input type="text" id="privacy_policy_url" name="hmdg_ccm_options[privacy_policy_url]" value="<?php echo esc_attr($o['privacy_policy_url']); ?>" placeholder="/privacy-policy/" class="form-control form-control-sm"></div></div></div>
                                <div class="field-row"><div class="row align-items-start"><div class="col-sm-4"><label class="form-label" for="cookie_policy_url">Cookie Policy URL</label></div><div class="col-sm-8"><input type="text" id="cookie_policy_url" name="hmdg_ccm_options[cookie_policy_url]" value="<?php echo esc_attr($o['cookie_policy_url']); ?>" placeholder="/cookie-policy/" class="form-control form-control-sm"></div></div></div>
                                <div class="field-row"><div class="row align-items-start"><div class="col-sm-4"><label class="form-label" for="terms_url">Terms &amp; Conditions URL</label></div><div class="col-sm-8"><input type="text" id="terms_url" name="hmdg_ccm_options[terms_url]" value="<?php echo esc_attr($o['terms_url']); ?>" placeholder="/terms-conditions/" class="form-control form-control-sm"></div></div></div>
                            </div>
                        </div>

                        <!-- Compliance -->
                        <div class="card mb-4">
                            <div class="card-header d-flex align-items-center gap-2"><span style="font-size:16px;">⚖️</span><h2>Compliance Settings</h2></div>
                            <div class="card-body">
                                <div class="field-row"><div class="row align-items-start">
                                    <div class="col-sm-4"><label class="form-label" for="policy_version">Policy Version</label></div>
                                    <div class="col-sm-8"><input type="text" id="policy_version" name="hmdg_ccm_options[policy_version]" value="<?php echo esc_attr($o['policy_version'] ?: '1'); ?>" placeholder="1" class="form-control form-control-sm" style="max-width:100px;"><div class="form-text"><strong>Increment</strong> when policies change to force re-consent.</div></div>
                                </div></div>
                                <div class="field-row"><div class="row align-items-start">
                                    <div class="col-sm-4"><label class="form-label" for="reload_on_consent">Reload After Consent</label></div>
                                    <div class="col-sm-8"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="reload_on_consent" name="hmdg_ccm_options[reload_on_consent]" value="1" role="switch" <?php checked(1, $o['reload_on_consent'] ?? 0); ?> style="width:40px;height:22px;cursor:pointer;"></div></div>
                                </div></div>
                            </div>
                        </div>

                        <!-- Debug -->
                        <div class="card mb-4">
                            <div class="card-header d-flex align-items-center gap-2"><span style="font-size:16px;">🐞</span><h2>Debug Mode</h2></div>
                            <div class="card-body">
                                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="debug_mode" name="hmdg_ccm_options[debug_mode]" value="1" role="switch" <?php checked(1, $o['debug_mode']); ?> style="width:40px;height:22px;cursor:pointer;"><label class="form-check-label ms-2 form-label mb-0" for="debug_mode">Enable verbose console logging + Validator</label></div>
                                <div class="form-text mt-2"><strong class="text-danger">Disable on live sites.</strong></div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-hmdg-save">💾 Save Settings</button>
                    </div>

                    <!-- SIDEBAR -->
                    <div class="col-lg-4">
                        <div style="position:sticky;top:32px;">
                            <div class="info-card mb-3" style="background:#f5f3ff;border-color:#c4b5fd;">
                                <h3 style="color:#5b21b6;">🌐 v1.2.0 — How booking tracking works</h3>
                                <ol style="color:#4c1d95;padding-left:18px;font-size:12px;line-height:2.2;">
                                    <li><strong>External link click:</strong> fires <code>book_now_click</code> via MP immediately on <code>pointerdown</code></li>
                                    <li><strong>Iframe embed:</strong> listens for platform-specific <code>postMessage</code> → fires <code>booking_completed</code> via MP</li>
                                    <li><strong>Return redirect:</strong> detects <code>?booking_confirmed</code> param on page load → fires <code>booking_completed</code></li>
                                    <li>All events carry full <code>client_id</code>, <code>session_id</code>, <code>gclid</code>, and <code>page_referrer</code></li>
                                    <li><code>booking_completed</code> also pushed to GTM <code>dataLayer</code> as <code>hmdg_booking_completed</code></li>
                                </ol>
                            </div>
                            <div class="info-card mb-3" style="background:#fef2f2;border-color:#fecaca;">
                                <h3 style="color:#991b1b;">🔒 Security Hardening (v1.3.0)</h3>
                                <ul style="color:#7f1d1d;">
                                    <li>Per-IP rate limiting: max <?php echo self::RATE_LIMIT_MAX; ?> requests / <?php echo self::RATE_LIMIT_WINDOW; ?>s</li>
                                    <li>Origin/Referer validation — only same-site requests accepted</li>
                                    <li>Nonce verification (12-hour validity)</li>
                                    <li>Payload size guard (max 8KB)</li>
                                    <li>Content-Type guard (must be JSON)</li>
                                    <li><code>client_id</code> format validation (regex)</li>
                                    <li>Non-blocking <code>wp_remote_post</code> — browser not kept waiting</li>
                                    <li>Full-page caching safe (client-side consent)</li>
                                </ul>
                            </div>
                            <div class="info-card mb-3" style="background:#f0fdf4;border-color:#86efac;">
                                <h3 style="color:#166534;">✅ Supported Platforms</h3>
                                <ul style="color:#166534;">
                                    <?php foreach ( self::PLATFORM_REGISTRY as $k => $p ) : ?>
                                    <li><?php echo esc_html($p['label']); ?> <span style="opacity:.6;font-size:11px;">(<?php echo esc_html(implode(', ', $p['domains'])); ?>)</span></li>
                                    <?php endforeach; ?>
                                    <li>+ any custom domain you add</li>
                                </ul>
                            </div>
                            <div class="info-card" style="background:#eff6ff;border-color:#bfdbfe;">
                                <h3 style="color:#1e40af;">🔍 Verify in DevTools</h3>
                                <div class="console-preview">
                                    <div class="c-green">🌐 Universal Booking Tracker</div>
                                    <div class="c-green">👂 postMessage listener active (9 platforms)</div>
                                    <div class="c-yellow">📡 Firing MP [book_now_click]</div>
                                    <div class="c-green">✅ MP fired. event=book_now_click</div>
                                    <div class="c-grey">── on iframe confirm ──</div>
                                    <div class="c-yellow">🎉 postMessage: platform=cliniko</div>
                                    <div class="c-yellow">📡 Firing MP [booking_completed]</div>
                                    <div class="c-green">✅ MP fired. event=booking_completed</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                </form>
            </div>
        </div>
        <?php
    }
}

add_action( 'plugins_loaded', [ 'HMDG_Cookie_Consent', 'instance' ], 1 );

/* ==========================================================================
   GITHUB AUTO-UPDATER

   Releases live in this public repository, which is why no GitHub token is
   needed on sites.

   v1.4.0: the load gate is no longer `is_admin()`. WordPress checks for and
   installs plugin updates from wp-cron, where `is_admin()` is FALSE — the old
   gate meant unattended updates could never happen. Do not put `is_admin()`
   back.

   A token may still be set in wp-config.php — define('HMDG_GITHUB_TOKEN',
   '...') — which raises the GitHub API rate limit. Optional, normally unset.
========================================================================== */
if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    require_once __DIR__ . '/includes/class-hmdg-updater.php';
    new HMDG_GitHub_Updater([
        'slug'        => 'hmdg-cookie-consent',
        'repo'        => 'hmdgai/hmdg-cookie-consent-public',
        'token'       => defined( 'HMDG_GITHUB_TOKEN' ) ? HMDG_GITHUB_TOKEN : '',
        'version'     => HMDG_CCM_VERSION,
        'plugin_file' => HMDG_CCM_FILE,
    ]);
}

/* ==========================================================================
   CENTRAL CONFIG SYNC  (v1.5.0)

   Pulls gtm_id, ga4_measurement_id and ga4_api_secret from the configuration
   service on a weekly jittered cron. See includes/class-hmdg-config-sync.php
   for the two rules that must not be relaxed.

   THE /verify ROUTE IS REGISTERED OUTSIDE THE GATE, AND MUST STAY THERE
   (v1.6.1). The enrolment callback is an ordinary front-end REST request:
   is_admin() false, wp_doing_cron() false, WP_CLI undefined — inside the gate
   it answers 404 to the only caller it exists for and no site can enrol. The
   closure lazy-loads the class, so an ordinary page view still parses nothing
   here: rest_api_init fires only when the REST API dispatches.

   Everything else keeps the same load gate as the updater, for the same
   reason: the weekly pull runs under wp-cron, where is_admin() is false. Do
   not narrow it to is_admin().

   The activation and deactivation hooks below are registered at FILE SCOPE,
   not from inside a plugins_loaded callback: on the activation request
   plugins_loaded has already fired before activate_plugin() includes this
   file, so a hook registered any later never runs. They sit inside this gate
   because activation and deactivation only ever happen in wp-admin or WP-CLI.

   Without the deactivation hook the weekly cron is orphaned: it keeps firing
   against a class that no longer loads.
========================================================================== */
add_action( 'rest_api_init', function () {
    require_once __DIR__ . '/includes/class-hmdg-config-sync.php';
    HMDG_Config_Sync::register_routes();
} );

if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    require_once __DIR__ . '/includes/class-hmdg-config-sync.php';
    HMDG_Config_Sync::init();

    register_activation_hook( HMDG_CCM_FILE, function () {
        HMDG_Cookie_Consent::instance()->on_activate();
        HMDG_Config_Sync::activate();
    } );

    register_deactivation_hook( HMDG_CCM_FILE, [ 'HMDG_Config_Sync', 'deactivate' ] );
}
