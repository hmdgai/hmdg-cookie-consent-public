<?php
/**
 * HMDG GitHub Plugin Updater
 *
 * Checks a private GitHub repository for new releases and integrates
 * with the native WordPress plugin update system. No external plugins required.
 *
 * v1.2.0 security fix: GitHub Personal Access Token is now ONLY used in
 * Authorization headers — it is never appended as a URL query parameter,
 * so it cannot appear in access logs or hosting control panels.
 *
 * Download flow:
 *   1. Get release metadata via GET /repos/{owner}/{repo}/releases/latest
 *      → Authorization: Bearer TOKEN header
 *   2. Find the .zip release asset, get its numeric asset ID
 *   3. Download via GET /repos/{owner}/{repo}/releases/assets/{id}
 *      → Authorization: Bearer TOKEN header
 *      → Accept: application/octet-stream header
 *   This is the correct GitHub API pattern per official GitHub docs.
 *
 * @package HMDG
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class HMDG_GitHub_Updater {

    /** Sentinel stored in the release transient after a failed lookup. See cache_failure(). */
    private const FAIL_MARKER = 'hmdg_release_check_failed';

    /**
     * v1.7.0: the release signing public key (ed25519, base64, 32 bytes).
     *
     * Every release asset is signed by the build workflow, and
     * verify_download() refuses to install a package whose signature does not
     * check out against THIS key.
     *
     * FAIL CLOSED, deliberately. A release with no .sig asset, an unreadable
     * signature, or a mismatch does not install — the site keeps its current
     * version and WordPress logs the WP_Error. Sites never break; they just
     * stop updating until a properly signed release exists.
     *
     * ROTATION is a two-step — ship the NEW key in a release signed with the
     * OLD key first. Procedure in HMDG's internal documentation; a one-step
     * rotation strands every site that has not yet updated.
     */
    private const PUBLIC_KEY = 'u1QgseOTNlQ2D4Fwdu0wT4fPzr1mIzYlyplLC29Q66s=';

    private string $slug;
    private string $repo;
    private string $token;
    private string $version;
    private string $plugin_file;
    private string $basename;
    private ?object $github_data = null;
    private string $cache_key;

    public function __construct( array $args ) {
        $this->slug        = $args['slug']        ?? '';
        $this->repo        = $args['repo']        ?? '';
        $this->token       = $args['token']       ?? '';
        $this->version     = $args['version']     ?? '0.0.0';
        $this->plugin_file = $args['plugin_file'] ?? '';
        $this->basename    = plugin_basename( $this->plugin_file );
        $this->cache_key   = 'hmdg_updater_' . md5( $this->repo );

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
        add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 20, 3 );
        add_filter( 'upgrader_post_install',                 [ $this, 'post_install' ], 10, 3 );
        add_action( 'upgrader_process_complete',             [ $this, 'clear_cache' ], 10, 0 );

        // v1.7.0: take over the package download for OUR packages only, so the
        // zip's signature is verified before WordPress unpacks anything.
        add_filter( 'upgrader_pre_download', [ $this, 'verify_download' ], 10, 4 );

        // v1.4.0: opt this plugin in to WordPress background auto-updates. Without this
        // WordPress only LISTS the update in wp-admin and waits for someone to click it.
        add_filter( 'auto_update_plugin',            [ $this, 'enable_auto_update' ], 10, 2 );
        add_filter( 'auto_plugin_update_send_email', [ $this, 'suppress_update_email' ], 10, 2 );

        // v1.2.0: inject Authorization header for any GitHub download request
        // This replaces the old approach of appending ?access_token=TOKEN to the URL
        if ( ! empty( $this->token ) ) {
            add_filter( 'http_request_args', [ $this, 'inject_auth_header' ], 10, 2 );
        }
    }

    /* ==========================================================================
       SECURITY: Authorization header injection
       This filter fires on every wp_remote_* call and adds our token only
       when the request targets api.github.com or a GitHub release asset URL.
       Token is NEVER placed in a URL query parameter.
    ========================================================================== */
    public function inject_auth_header( array $args, string $url ): array {
        // v1.3.0: scope token injection to this specific repo only.
        // Prevents token leaking if another plugin requests a different GitHub repo.
        $repo_pattern = $this->repo; // e.g. "owner/repo"
        $is_our_api   = strpos( $url, 'api.github.com' ) !== false
                      && strpos( $url, $repo_pattern ) !== false;
        $is_our_asset = strpos( $url, 'github.com' ) !== false
                      && strpos( $url, $repo_pattern ) !== false
                      && strpos( $url, '/releases/' ) !== false;

        if ( $is_our_api || $is_our_asset ) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->token;

            // For asset downloads, set Accept: application/octet-stream
            // so GitHub streams the binary rather than returning JSON metadata
            if ( $is_our_asset && strpos( $url, '/assets/' ) !== false ) {
                $args['headers']['Accept'] = 'application/octet-stream';
            } else {
                $args['headers']['Accept'] = 'application/vnd.github.v3+json';
            }

            $args['headers']['User-Agent']         = 'HMDG-Plugin-Updater/1.4.0';
            $args['headers']['X-GitHub-Api-Version'] = '2022-11-28';

            // Ensure redirects are followed — GitHub asset downloads use 302 redirects
            // to CDN. WordPress's HTTP API follows redirects by default (redirection: 5).
            $args['redirection'] = 5;
        }

        return $args;
    }

    /* ==========================================================================
       GITHUB API
    ========================================================================== */
    private function get_github_release(): ?object {
        if ( $this->github_data !== null ) return $this->github_data;

        $cached = get_transient( $this->cache_key );
        if ( $cached !== false ) {
            // v1.4.0: self::FAIL_MARKER means a recent check failed. Return early rather
            // than retrying - see cache_failure() for why this matters on this fleet.
            if ( $cached === self::FAIL_MARKER ) return null;
            $this->github_data = $cached;
            return $this->github_data;
        }

        $url = sprintf( 'https://api.github.com/repos/%s/releases/latest', $this->repo );

        // Headers are injected by inject_auth_header filter — we don't put the
        // token in the URL here or anywhere else.
        $response = wp_remote_get( $url, [
            'timeout'    => 15,
            'user-agent' => 'HMDG-Plugin-Updater/1.4.0',
        ]);

        if ( is_wp_error( $response ) ) {
            $this->cache_failure();
            return null;
        }
        if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
            $this->cache_failure();
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );
        if ( empty( $body ) || empty( $body->tag_name ) ) {
            $this->cache_failure();
            return null;
        }

        $this->github_data = $body;
        set_transient( $this->cache_key, $body, 12 * HOUR_IN_SECONDS );

        return $this->github_data;
    }

    /**
     * Get the download URL for the release ZIP asset.
     *
     * v1.2.0: Returns the api.github.com asset URL (not browser_download_url).
     * The API URL + Authorization header is the correct authenticated download path.
     * We never use ?access_token= query parameters.
     *
     * URL format: https://api.github.com/repos/{owner}/{repo}/releases/assets/{id}
     * With header: Authorization: Bearer TOKEN + Accept: application/octet-stream
     */
    private function get_download_url(): string {
        $release = $this->get_github_release();
        if ( ! $release ) return '';

        $has_token = ! empty( $this->token );

        // Prefer a named .zip release asset (uploaded as part of the release)
        if ( ! empty( $release->assets ) && is_array( $release->assets ) ) {
            foreach ( $release->assets as $asset ) {
                if ( empty( $asset->name ) || substr( $asset->name, -4 ) !== '.zip' ) continue;

                // v1.4.0: which URL is correct depends entirely on whether we have a token.
                //
                // The api.github.com asset URL only streams the binary when the request
                // carries `Accept: application/octet-stream` - and that header is added by
                // inject_auth_header(), which is ONLY registered when a token is set. With
                // no token the API URL returns JSON metadata instead, WordPress writes that
                // JSON to disk as the "zip", and the update fails on an unreadable archive.
                //
                // Releases come from a public repo and sites set no token, so the
                // untokened path is the normal one. Do not collapse these two branches.
                if ( ! $has_token ) {
                    if ( ! empty( $asset->browser_download_url ) ) return $asset->browser_download_url;
                } else {
                    if ( ! empty( $asset->url ) ) return $asset->url;
                }
            }
        }

        // Fall back to zipball (auto-generated source ZIP) if no named asset exists
        // zipball_url is also an api.github.com URL and works with Authorization header
        return $release->zipball_url ?? '';
    }

    private function get_remote_version(): string {
        $release = $this->get_github_release();
        if ( ! $release ) return '0.0.0';
        return ltrim( $release->tag_name, 'vV' );
    }

    /**
     * v1.7.0: the detached signature asset for the release zip, published by the
     * build workflow as "<zip name>.sig" (base64 text of a 64-byte ed25519
     * signature over the zip's exact bytes). Empty when the release carries no
     * signature — which verify_download() treats as a refusal, not a pass.
     */
    private function get_signature_url(): string {
        $release = $this->get_github_release();
        if ( ! $release || empty( $release->assets ) || ! is_array( $release->assets ) ) return '';

        $has_token = ! empty( $this->token );
        foreach ( $release->assets as $asset ) {
            if ( empty( $asset->name ) || substr( $asset->name, -8 ) !== '.zip.sig' ) continue;
            if ( ! $has_token ) {
                if ( ! empty( $asset->browser_download_url ) ) return $asset->browser_download_url;
            } else {
                if ( ! empty( $asset->url ) ) return $asset->url;
            }
        }
        return '';
    }

    /** Only intercept downloads that are ours: this repo's releases. Everything
     *  else is returned untouched so other plugins' updates are unaffected. */
    private function is_our_package( string $package ): bool {
        return strpos( $package, 'github.com' ) !== false
            && strpos( $package, $this->repo ) !== false;
    }

    /**
     * The pure check, separated from the WordPress plumbing so the release
     * workflow and the test harness can exercise it byte-for-byte.
     *
     * sodium_crypto_sign_verify_detached ships as the sodium extension on
     * PHP >= 7.2 and as WordPress's bundled sodium_compat polyfill everywhere
     * else, so on any WP >= 5.6 site (this plugin's floor) it exists.
     */
    public static function verify_bytes( string $zip_bytes, string $sig_b64, string $pub_b64 ): bool {
        if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) return false;
        $sig = base64_decode( trim( $sig_b64 ), true );
        $pub = base64_decode( $pub_b64, true );
        if ( $sig === false || $pub === false ) return false;
        if ( strlen( $sig ) !== 64 || strlen( $pub ) !== 32 ) return false;
        try {
            return sodium_crypto_sign_verify_detached( $sig, $zip_bytes, $pub );
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /**
     * upgrader_pre_download: download our package ourselves, verify its
     * signature, and hand WordPress the verified local file — or a WP_Error,
     * which aborts the install and leaves the running version untouched.
     *
     * Every failure path is CLOSED: missing signature asset, unreachable
     * signature, undecodable signature, missing sodium, mismatch. This
     * updater never installs an unverified build.
     */
    public function verify_download( $reply, $package = '', $upgrader = null, $hook_extra = [] ) {
        if ( false !== $reply ) return $reply;                 // someone else already handled it
        if ( ! is_string( $package ) || $package === '' ) return $reply;
        if ( ! $this->is_our_package( $package ) ) return $reply;

        if ( ! function_exists( 'download_url' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $sig_url = $this->get_signature_url();
        if ( $sig_url === '' ) {
            return new WP_Error( 'hmdg_unsigned_release',
                'HMDG Cookie Consent: this release carries no signature asset. '
                . 'Refusing to install an unsigned build.' );
        }

        $file = download_url( $package, 300 );
        if ( is_wp_error( $file ) ) return $file;

        $sig_res = wp_remote_get( $sig_url, [ 'timeout' => 30 ] );
        $sig_b64 = ( ! is_wp_error( $sig_res )
                     && wp_remote_retrieve_response_code( $sig_res ) === 200 )
                   ? (string) wp_remote_retrieve_body( $sig_res ) : '';

        $zip_bytes = (string) file_get_contents( $file );

        if ( $sig_b64 === '' || $zip_bytes === ''
             || ! self::verify_bytes( $zip_bytes, $sig_b64, self::PUBLIC_KEY ) ) {
            @unlink( $file );
            return new WP_Error( 'hmdg_bad_signature',
                'HMDG Cookie Consent: release signature verification FAILED. '
                . 'The package was not installed. If this is not a transient '
                . 'network fault, treat it as a possible compromise of the '
                . 'release channel and investigate before retrying.' );
        }

        return $file;                                          // verified; WP unpacks this
    }

    /* ==========================================================================
       WORDPRESS UPDATE HOOKS
    ========================================================================== */
    public function check_update( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;

        $remote_version = $this->get_remote_version();

        if ( version_compare( $remote_version, $this->version, '>' ) ) {
            $download_url = $this->get_download_url();

            if ( ! empty( $download_url ) ) {
                $transient->response[ $this->basename ] = (object) [
                    'slug'         => dirname( $this->basename ),
                    'plugin'       => $this->basename,
                    'new_version'  => $remote_version,
                    'url'          => 'https://github.com/' . $this->repo,
                    'package'      => $download_url,
                    'icons'        => [],
                    'banners'      => [],
                    'tested'       => '',
                    'requires_php' => '7.4',
                    'requires'     => '5.6',
                ];
            }
        }

        return $transient;
    }

    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) return $result;
        if ( dirname( $this->basename ) !== ( $args->slug ?? '' ) ) return $result;

        $release = $this->get_github_release();
        if ( ! $release ) return $result;

        return (object) [
            'name'          => 'HMDG Cookie Consent Mode v2',
            'slug'          => dirname( $this->basename ),
            'version'       => $this->get_remote_version(),
            'author'        => '<a href="https://hmdg.co.uk">HMDG</a>',
            'homepage'      => 'https://hmdg.co.uk',
            'requires'      => '5.6',
            'requires_php'  => '7.4',
            'tested'        => get_bloginfo( 'version' ),
            'downloaded'    => 0,
            'last_updated'  => $release->published_at ?? '',
            'sections'      => [
                'description' => 'UK GDPR (PECR) &amp; EU GDPR compliant cookie consent banner with Google Consent Mode v2 and Universal Booking Tracker.',
                'changelog'   => nl2br( esc_html( $release->body ?? 'No changelog provided.' ) ),
            ],
            'download_link' => $this->get_download_url(),
        ];
    }

    public function post_install( $response, $hook_extra, $result ) {
        if ( ! isset( $hook_extra['plugin'] ) ) return $result;
        if ( $hook_extra['plugin'] !== $this->basename ) return $result;

        global $wp_filesystem;

        $expected_dir  = WP_PLUGIN_DIR . '/' . dirname( $this->basename );
        $installed_dir = $result['destination'];

        if ( $installed_dir !== $expected_dir ) {
            if ( $wp_filesystem->exists( $expected_dir ) ) {
                $wp_filesystem->delete( $expected_dir, true );
            }
            $wp_filesystem->move( $installed_dir, $expected_dir );
            $result['destination']        = $expected_dir;
            $result['destination_name']   = dirname( $this->basename );
            $result['remote_destination'] = $expected_dir;
        }

        activate_plugin( $this->basename );
        $this->clear_cache();

        return $result;
    }

    /**
     * v1.4.0: cache a failed release check so a broken or rate-limited GitHub
     * lookup is not repeated on every request that refreshes the update transient.
     *
     * This matters more here than it looks. Releases are fetched unauthenticated,
     * GitHub's unauthenticated API limit is 60 requests/hour per IP, and many
     * hosted sites share outbound IPs — so sites retrying a failing check from
     * one egress IP can hold themselves in a rate-limited state indefinitely.
     * One hour of backoff bounds that.
     */
    private function cache_failure(): void {
        set_transient( $this->cache_key, self::FAIL_MARKER, HOUR_IN_SECONDS );
    }

    /**
     * v1.4.0: tell WordPress to install this plugin's updates unattended.
     *
     * Scoped to this plugin only - the incoming $update value is returned untouched
     * for everything else, so we never change auto-update behaviour for other plugins.
     */
    public function enable_auto_update( $update, $item ) {
        $plugin = '';
        if ( is_object( $item ) && isset( $item->plugin ) ) {
            $plugin = $item->plugin;
        } elseif ( is_array( $item ) && isset( $item['plugin'] ) ) {
            $plugin = $item['plugin'];
        }

        return ( $plugin === $this->basename ) ? true : $update;
    }

    /**
     * v1.4.0: suppress the "plugin was automatically updated" email for this plugin.
     *
     * The admin_email is often the site owner's own address, and they did not
     * ask for mail about an agency-managed plugin on every release.
     *
     * Suppressed ONLY when every plugin in the batch is ours. If WordPress updated
     * something else in the same run the email goes out as normal - otherwise we would
     * be hiding another plugin's update failure from whoever runs the site.
     */
    public function suppress_update_email( $enabled, $update_results = [] ) {
        if ( empty( $update_results ) || ! is_array( $update_results ) ) return $enabled;

        foreach ( $update_results as $result ) {
            $plugin = '';
            if ( is_object( $result ) && isset( $result->item ) && isset( $result->item->plugin ) ) {
                $plugin = $result->item->plugin;
            }
            if ( $plugin !== $this->basename ) return $enabled;
        }

        return false;
    }

    public function clear_cache(): void {
        delete_transient( $this->cache_key );
        $this->github_data = null;
    }
}
