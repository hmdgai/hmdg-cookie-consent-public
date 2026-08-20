<?php
/**
 * HMDG Cookie Consent - central config sync
 *
 * File: includes/class-hmdg-config-sync.php
 *
 * Pulls gtm_id, ga4_measurement_id and ga4_api_secret from the configuration
 * service, so these values are managed centrally instead of typed per site.
 * Design history and rationale live in HMDG's internal documentation.
 *
 * ENROLMENT
 *   1. On first run this plugin generates a random KEY.
 *   2. It serves sha256(KEY) at /wp-json/hmdg-ccm/v1/verify, unauthenticated.
 *   3. It POSTs { site_url, key } to the configuration service, which fetches
 *      this site's /verify and checks the fingerprint against the posted key.
 *   4. 201 means enrolled. The key becomes this site's credential.
 *
 *   The /verify route MUST be registered on ordinary front-end requests: the
 *   enrolment callback is one (v1.6.1).
 *
 * TWO RULES THAT MUST NOT BE RELAXED
 *
 *   FAIL SOFT. Config is applied only on a 200 carrying non-null values. A
 *   404, a 5xx, a timeout, a DNS failure or a 200 full of nulls leaves the
 *   existing configuration exactly as it was. The service being down, slow or
 *   mid-deploy can never break tracking on a site. The site depends on the
 *   LAST successful call, never on this one.
 *
 *   NEVER BLANK A WORKING VALUE. A null from the server means "leave it
 *   alone", not "clear it". Removing tracking from a site is an explicit
 *   act, not a side effect of somebody emptying a field.
 *
 * @package HMDG_Cookie_Consent
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class HMDG_Config_Sync {

	/* ---------------------------------------------------------------- options */
	const OPT_KEY      = 'hmdg_ccm_site_key';      // per-site credential
	const OPT_STATE    = 'hmdg_ccm_sync_state';    // status, timings, last error
	const OPT_SETTINGS = 'hmdg_ccm_options';       // the plugin's existing option

	const CRON_HOOK = 'hmdg_ccm_config_sync';
	const TIMEOUT   = 12;

	/** Only these three keys are ever written by sync. Nothing else is touched. */
	const SYNCED_FIELDS = [ 'gtm_id', 'ga4_measurement_id', 'ga4_api_secret' ];

	/**
	 * Sentinel meaning "nobody has set the production hostname yet". While
	 * DEFAULT_BASE equals it, sync makes no HTTP calls and shows no admin
	 * notice. release.yml refuses to build a release in that state.
	 */
	const UNSET_BASE = 'https://bigbrother.invalid/api/v1';

	/**
	 * The production configuration service. A single site can be pointed
	 * elsewhere with HMDG_CONFIG_ENDPOINT in wp-config.php.
	 */
	const DEFAULT_BASE = 'https://big-brother-eight.vercel.app/api/v1';

	/**
	 * Statuses meaning "already enrolled, go straight to the config pull".
	 *
	 * key_rejected is deliberately in this list. It is what stops a revoked key
	 * from being silently replaced: without it, run() falls through to
	 * register() and the site re-enrols itself with a fresh key, which is
	 * exactly the self-healing that sync() warns against. Revocation is usually
	 * somebody's decision, and a decision that undoes itself is not one.
	 */
	const ENROLLED_STATES = [ 'enrolled', 'ok', 'no_config_held', 'unreachable',
	                          'no_config', 'bad_response', 'rejected_value',
	                          'throttled', 'key_rejected' ];

	/** Statuses a weekly retry cannot fix. Backed off to monthly. */
	const NEEDS_HUMAN = [ 'pending_review', 'domain_mismatch', 'key_rejected' ];

	public static function init(): void {
		// rest_api_init is deliberately NOT hooked here. /verify must be served
		// on ordinary front-end REST requests -- the enrolment callback is one,
		// and is neither admin nor cron nor CLI -- while init() only runs
		// inside the main file's admin/cron/CLI load gate. The route is
		// registered unconditionally from the main plugin file (v1.6.1);
		// hooking it here as well would only mask that registration and
		// invite the 404 regression back.
		add_action( self::CRON_HOOK, [ __CLASS__, 'run' ] );
		add_action( 'init',          [ __CLASS__, 'maybe_schedule' ] );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notice' ] );
		add_action( 'admin_post_hmdg_ccm_sync_now', [ __CLASS__, 'handle_sync_now' ] );
	}

	private static function base(): string {
		if ( defined( 'HMDG_CONFIG_ENDPOINT' ) && HMDG_CONFIG_ENDPOINT ) {
			return rtrim( HMDG_CONFIG_ENDPOINT, '/' );
		}
		return self::DEFAULT_BASE;
	}

	/** False on a build whose production hostname was never filled in. */
	private static function endpoint_configured(): bool {
		return self::base() !== self::UNSET_BASE;
	}

	/* ==================================================================
	   Scheduling

	   Jittered across a full week on purpose. Without the offset ~340 sites
	   wake within the same minute and arrive at one Postgres role together.
	================================================================== */
	public static function maybe_schedule(): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_event( time() + wp_rand( 300, WEEK_IN_SECONDS ), 'weekly', self::CRON_HOOK );
	}

	/** Call from the plugin's activation hook so a new site enrols promptly. */
	public static function activate(): void {
		self::maybe_schedule();
		wp_schedule_single_event( time() + 60, self::CRON_HOOK );
	}

	/** Call from the plugin's deactivation hook, or the cron is orphaned. */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/* ==================================================================
	   Credentials
	================================================================== */
	private static function site_key(): string {
		$key = (string) get_option( self::OPT_KEY, '' );
		if ( $key !== '' ) return $key;

		$key = self::random_hex();
		if ( $key === '' ) return '';

		add_option( self::OPT_KEY, $key, '', 'no' );
		return $key;
	}

	/**
	 * What /verify serves: sha256 of this site's key.
	 *
	 * Derived, never stored. It survives a restore from backup for the same
	 * reason the key does, and it needs no expiry because knowing it grants
	 * nothing on its own.
	 *
	 * READ ONLY, deliberately: it reads the option rather than calling
	 * site_key(), which would create one. /verify is unauthenticated, and an
	 * unauthenticated GET must not be able to write an option -- see the note
	 * above register_routes(). A site that has not run its first cron yet has
	 * no key, and /verify correctly answers 503 until it does.
	 */
	private static function key_fingerprint(): string {
		$key = (string) get_option( self::OPT_KEY, '' );
		return $key === '' ? '' : hash( 'sha256', $key );
	}

	private static function random_hex(): string {
		try {
			// 32 bytes -> 64 hex chars. The server validates that exact length
			// on the key, so do not shorten it. wp_generate_password is not
			// cryptographically framed for this.
			return bin2hex( random_bytes( 32 ) );
		} catch ( \Exception $e ) {
			self::set_state( [ 'status' => 'error', 'error' => 'no CSPRNG available' ] );
			return '';
		}
	}

	/* ==================================================================
	   REST: the verification endpoint the enrolment callback reads

	   Public by necessity and harmless: it returns a hash of a value this site
	   generated itself. Reading it grants nothing.

	   Deliberately NOT behind rest_security_gate(): the caller arrives with no
	   nonce, no same-origin Referer and no session. The response is a
	   fixed-size constant with no side effects.

	   "Performs no write" is load-bearing: key_fingerprint() reads the option
	   directly instead of calling site_key(), which would let an
	   unauthenticated GET create an option. Before the first cron run there is
	   nothing to serve, and 503 is the honest answer.

	   REGISTERED FROM THE MAIN PLUGIN FILE, OUTSIDE ITS LOAD GATE (v1.6.1) —
	   the enrolment callback is a plain front-end REST request, which that
	   gate excludes. Keep it that way.
	================================================================== */
	public static function register_routes(): void {
		register_rest_route( 'hmdg-ccm/v1', '/verify', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function () {
				$fp = self::key_fingerprint();
				if ( $fp === '' ) {
					return new WP_REST_Response( [ 'error' => 'unavailable' ], 503 );
				}
				return new WP_REST_Response( [
					'key_fingerprint' => $fp,
				], 200 );
			},
		] );
	}

	/* ==================================================================
	   Cron entry point
	================================================================== */
	public static function run(): void {
		// A build with no production hostname does nothing at all, rather than
		// failing silently against a host that does not exist.
		if ( ! self::endpoint_configured() ) {
			return;
		}

		$state  = self::state();
		$status = (string) ( $state['status'] ?? '' );

		// Back off on outcomes a retry cannot fix. A wrong domain record needs
		// a human; retrying weekly achieves nothing and adds noise to the
		// central enrolment queue.
		if ( in_array( $status, self::NEEDS_HUMAN, true ) ) {
			$last = (int) ( $state['last_attempt'] ?? 0 );
			if ( $last > 0 && ( time() - $last ) < 30 * DAY_IN_SECONDS ) {
				return;
			}
		}

		if ( ! self::is_enrolled() && ! self::register() ) {
			return;
		}

		self::sync();
	}

	private static function is_enrolled(): bool {
		if ( (string) get_option( self::OPT_KEY, '' ) === '' ) {
			return false;
		}
		return in_array( (string) ( self::state()['status'] ?? '' ),
		                 self::ENROLLED_STATES, true );
	}

	/* ==================================================================
	   Enrolment
	================================================================== */
	private static function register(): bool {
		$key = self::site_key();
		if ( $key === '' ) {
			return false;
		}

		$res = wp_remote_post( self::base() . '/register', [
			'timeout' => self::TIMEOUT,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'site_url'       => home_url(),
				'key'            => $key,
				'plugin_version' => defined( 'HMDG_CCM_VERSION' ) ? HMDG_CCM_VERSION : 'unknown',
				'observed'       => self::observed(),
			] ),
		] );

		if ( is_wp_error( $res ) ) {
			self::set_state( [ 'status' => 'register_failed', 'error' => $res->get_error_message() ] );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$msg  = is_array( $body ) ? (string) ( $body['error'] ?? '' ) : '';

		if ( $code === 201 ) {
			self::set_state( [ 'status' => 'enrolled', 'error' => '' ] );
			return true;
		}

		// 409: the domain matched no account, or more than one. A human fixes
		// that centrally. Not an error on this side.
		if ( $code === 409 ) {
			self::set_state( [ 'status' => 'pending_review', 'error' => $msg ] );
			return false;
		}

		// 403 complaining about a redirect: this site's recorded domain now
		// serves somewhere else. 17 accounts were in that state on 18 Aug.
		// Refusing is correct; enrolling would bind this site to another
		// client's configuration.
		if ( $code === 403 && stripos( $msg, 'different domain' ) !== false ) {
			self::set_state( [ 'status' => 'domain_mismatch', 'error' => $msg ] );
			return false;
		}

		self::set_state( [ 'status' => 'register_failed',
		                   'error'  => $msg !== '' ? $msg : "HTTP $code" ] );
		return false;
	}

	/**
	 * What this site is running right now. Stored centrally as observations,
	 * never overwriting a human-entered value.
	 *
	 * tag_output_active is the field that matters, and it is why this is not
	 * simply a dump of the options: output_google_tag() suppresses our tag
	 * entirely when another Google-tag plugin is active, and on those sites a
	 * stored gtm_id is not a firing container. Reporting the option alone
	 * would record agreement on precisely the sites where no tag fires.
	 */
	private static function observed(): array {
		$opts = (array) get_option( self::OPT_SETTINGS, [] );
		$gtm  = (string) ( $opts['gtm_id'] ?? '' );
		$gtag = (string) ( $opts['gtag_id'] ?? '' );

		$map = [];
		if ( class_exists( 'HMDG_Cookie_Consent' )
		     && method_exists( 'HMDG_Cookie_Consent', 'conflicting_tag_plugin_map' ) ) {
			$map = (array) HMDG_Cookie_Consent::instance()->conflicting_tag_plugin_map();
		}

		return [
			'gtm_id'                     => $gtm,
			'gtag_id'                    => $gtag,
			'ga4_measurement_id'         => (string) ( $opts['ga4_measurement_id'] ?? '' ),
			// v1.7.0: the secret moved to its own option; the blob's copy is
			// '' after migration, so reading it here would report every
			// migrated site as unconfigured. The array read stays as the
			// fallback for the impossible-in-practice case of this class
			// running without the main one.
			'mp_configured'              => class_exists( 'HMDG_Cookie_Consent' )
				? HMDG_Cookie_Consent::get_mp_secret() !== ''
				: ! empty( $opts['ga4_api_secret'] ),
			'tag_output_active'          => ( $gtm !== '' || $gtag !== '' ) && empty( $map ),
			// Names for humans, ids for counting. array_values/array_keys of one
			// map, so the two lists cannot drift apart or fall out of order.
			'conflicting_tag_plugins'    => array_values( $map ),
			'conflicting_tag_plugin_ids' => array_keys( $map ),
		];
	}

	/* ==================================================================
	   The weekly pull
	================================================================== */
	private static function sync(): void {
		$key = (string) get_option( self::OPT_KEY, '' );
		if ( $key === '' ) return;

		$url  = self::base() . '/site-config';
		$args = [
			'timeout' => self::TIMEOUT,
			'headers' => [
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( [
				'observed'       => self::observed(),
				'plugin_version' => defined( 'HMDG_CCM_VERSION' ) ? HMDG_CCM_VERSION : 'unknown',
			] ),
		];

		$reported = true;
		$res      = wp_remote_post( $url, $args );

		/*
		 * 405 means this site is newer than the server -- the route exports GET
		 * but not POST, which happens if a deploy is rolled back. Retry as a GET
		 * and take the configuration anyway: losing the report costs a week,
		 * losing the config costs the site's tracking. No new status: recorded
		 * in state, not surfaced as an admin notice.
		 */
		if ( ! is_wp_error( $res )
		     && (int) wp_remote_retrieve_response_code( $res ) === 405 ) {
			$reported = false;
			$res      = wp_remote_get( $url, [
				'timeout' => self::TIMEOUT,
				'headers' => [ 'Authorization' => 'Bearer ' . $key ],
			] );
		}

		/* ---- every path below leaves the existing config untouched ---- */

		if ( is_wp_error( $res ) ) {
			self::set_state( [ 'status' => 'unreachable', 'error' => $res->get_error_message() ] );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );

		if ( $code === 429 ) {
			// Called too soon. Harmless: the next scheduled run is a week away.
			self::set_state( [ 'status' => 'throttled', 'error' => '' ] );
			return;
		}

		if ( $code === 401 ) {
			// Key revoked or unknown. Do NOT self-heal by re-registering: a
			// revoked key usually means somebody revoked it deliberately.
			// key_rejected being in ENROLLED_STATES is what enforces that.
			//
			// 401 ONLY, and 403 deliberately NOT. Every key-related refusal on
			// this endpoint is a 401; a 403 arriving here came from something
			// in between (a WAF, a proxy), not from the server. Reading that
			// as revocation would put the site into a 30-day backoff over a
			// request the server never saw. It falls through to no_config
			// below instead, which retries next week and says nothing.
			self::set_state( [ 'status' => 'key_rejected', 'error' => "HTTP $code" ] );
			return;
		}

		if ( $code !== 200 ) {
			self::set_state( [ 'status' => 'no_config', 'error' => "HTTP $code" ] );
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) ) {
			self::set_state( [ 'status' => 'bad_response', 'error' => '' ] );
			return;
		}

		self::apply( $body, $reported );
	}

	/* ==================================================================
	   Apply, conservatively
	================================================================== */
	private static function apply( array $incoming, bool $reported = true ): void {
		$opts    = (array) get_option( self::OPT_SETTINGS, [] );
		$before  = $opts;
		$changed = [];
		$errors  = [];
		$held    = false;

		foreach ( self::SYNCED_FIELDS as $field ) {
			$val = isset( $incoming[ $field ] ) ? trim( (string) $incoming[ $field ] ) : '';

			// Null or empty never clears a local value. This is the fail-soft
			// contract, not a convenience.
			if ( $val === '' ) continue;

			$held = true;

			// Shape check. The server guards these too, but a typo must not
			// repoint a site's tracking and this is the last place to stop
			// it. Case-sensitive: Google issues these uppercase.
			if ( ! self::valid( $field, $val ) ) {
				$errors[] = $field;
				continue;
			}

			// v1.7.0: the MP secret lives in its own non-autoloaded option,
			// never in the settings blob this loop writes. Same fail-soft
			// semantics, different destination — and never logged by value.
			if ( $field === 'ga4_api_secret' ) {
				if ( HMDG_Cookie_Consent::get_mp_secret() !== $val ) {
					HMDG_Cookie_Consent::set_mp_secret( $val );
					$changed[] = 'ga4_api_secret';
				}
				continue;
			}

			if ( (string) ( $opts[ $field ] ?? '' ) !== $val ) {
				$opts[ $field ] = $val;
				$changed[] = "$field=$val";
			}
		}

		if ( $opts !== $before ) {
			update_option( self::OPT_SETTINGS, $opts );
		}

		// A 200 carrying nothing is a successful call about a site the server
		// holds no configuration for. Reporting that as "synced" would put a
		// green notice on a site with empty tracking fields, which is the exact
		// silent failure this class exists to remove.
		if ( $errors ) {
			$status = 'rejected_value';
		} elseif ( ! $held ) {
			$status = 'no_config_held';
		} else {
			$status = 'ok';
		}

		self::set_state( [
			'status'   => $status,
			'error'    => $errors ? 'malformed: ' . implode( ',', $errors ) : '',
			'changed'  => $changed,
			'reported' => $reported,
		] );
	}

	private static function valid( string $field, string $val ): bool {
		switch ( $field ) {
			case 'gtm_id':
				return (bool) preg_match( '/^GTM-[A-Z0-9]{4,}$/', $val );
			case 'ga4_measurement_id':
				return (bool) preg_match( '/^G-[A-Z0-9]{8,}$/', $val );
			case 'ga4_api_secret':
				return strlen( $val ) >= 20 && strlen( $val ) <= 64;
		}
		return false;
	}

	/* ==================================================================
	   Sync now, so an account manager can force a pull during a client call
	   rather than waiting up to a week
	================================================================== */
	public static function handle_sync_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'hmdg_ccm_sync_now' );

		// Clear the monthly backoff, since a human asking is new information.
		// Safe even on key_rejected: that status is in ENROLLED_STATES, so the
		// forced run repeats the config pull and cannot re-register the site.
		$state = self::state();
		unset( $state['last_attempt'] );
		update_option( self::OPT_STATE, $state, 'no' );

		self::run();

		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	public static function sync_now_url(): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=hmdg_ccm_sync_now' ),
			'hmdg_ccm_sync_now'
		);
	}

	/* ==================================================================
	   State and admin surface
	================================================================== */
	private static function state(): array {
		return (array) get_option( self::OPT_STATE, [] );
	}

	private static function set_state( array $patch ): void {
		$state = array_merge( self::state(), $patch );
		$state['last_attempt'] = time();

		// changed[] describes one apply() call. Merging it forward would carry
		// a stale list into every later unreachable/throttled state.
		if ( ! array_key_exists( 'changed', $patch ) ) {
			unset( $state['changed'] );
		}

		// `reported` describes one apply() call too, and needs the same
		// treatment for the same reason. Every early return above -- unreachable,
		// throttled, key_rejected, no_config, bad_response -- reported nothing,
		// so carrying last week's value forward would sit a stale `reported`
		// beside a fresh `last_attempt` and read as if this attempt had reported.
		if ( ! array_key_exists( 'reported', $patch ) ) {
			unset( $state['reported'] );
		}

		if ( ( $patch['status'] ?? '' ) === 'ok' ) {
			$state['last_success'] = time();
		}
		update_option( self::OPT_STATE, $state, 'no' );
	}

	public static function admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) return;

		// Say nothing on a build with no production hostname. These are client
		// sites; our unfinished plumbing is not theirs to read about.
		if ( ! self::endpoint_configured() ) return;

		$screen = get_current_screen();
		if ( ! $screen || strpos( (string) $screen->id, 'hmdg' ) === false ) return;

		$state  = self::state();
		$status = (string) ( $state['status'] ?? 'never_run' );
		$sync   = esc_url( self::sync_now_url() );

		if ( $status === 'ok' ) {
			$when = ! empty( $state['last_success'] )
				? human_time_diff( (int) $state['last_success'] ) . ' ago'
				: 'just now';
			printf(
				'<div class="notice notice-success is-dismissible"><p>Tracking configuration synced %s. <a href="%s">Sync now</a></p></div>',
				esc_html( $when ), $sync
			);
			return;
		}

		$messages = [
			'no_config_held'  => 'This site is enrolled, but no tracking configuration is held for it centrally, so nothing has been applied. Any settings below are local. Ask the PPC team to add this site&rsquo;s IDs.',
			'pending_review'  => 'This site could not be matched to a single account centrally. The tracking settings below are whatever is stored locally. Ask the PPC team to check the domain record.',
			'domain_mismatch' => 'This site&rsquo;s recorded domain now points somewhere else, so it has not been enrolled. Tracking settings below are local only, and the record needs correcting centrally.',
			'key_rejected'    => 'This site&rsquo;s sync key was rejected. Local settings are still active, but configuration changes will not reach this site until a key is reissued.',
			'rejected_value'  => 'A value received centrally was malformed and has been ignored. Local settings are unchanged.',
		];

		$msg = $messages[ $status ] ?? sprintf(
			'Tracking configuration has not synced (%s). Existing settings remain active.',
			esc_html( $status )
		);

		printf( '<div class="notice notice-warning"><p>%s <a href="%s">Retry now</a></p></div>',
		        wp_kses_post( $msg ), $sync );
	}
}
