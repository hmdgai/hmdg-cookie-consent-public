# HMDG Cookie Consent Mode v2

Cookie consent banner for WordPress, designed for UK PECR and EU GDPR, built around
Google Consent Mode v2, with booking-conversion tracking.

Maintained by [HMDG](https://hmdg.co.uk) for its client sites. This repository exists so
those sites can fetch updates over the public GitHub API; each release attaches a signed
`hmdg-cookie-consent.zip` that the plugin's built-in updater verifies and installs.

It is not published on wordpress.org and is not supported for general use. Please raise
any issue with HMDG directly.

## Privacy

On configured sites the plugin loads the site's Google tag or GTM container and sends
consent choices to Google Consent Mode. When booking tracking is enabled and the visitor
has granted analytics consent, the browser sends booking event details to the site's
WordPress REST endpoint, which forwards them to that site's GA4 property. GTM may also
load other tags configured by the site. Sites managed by HMDG fetch their tracking IDs
and server-side GA4 API secret from HMDG's configuration service. No site credentials
or IDs are committed to this source code.

## Requirements

- WordPress 5.6 or later
- PHP 7.4 or later

## Licence

GPL v2 or later — <https://www.gnu.org/licenses/gpl-2.0.html>

## Marketing handoff

The [v2.0.12 marketing handoff](docs/marketing-handoff-v2.0.12.md) explains the
WP Rocket consent interaction and rapid preference Save changes, release and deployment evidence,
verification steps, and the limits of what the plugin can establish without
GTM/GA4 access. The [v2.0.10 handoff](docs/marketing-handoff-v2.0.10.md)
remains available as the previous release record.

---

## Changelog

### 2.0.12 — rapid preference Save on WP Rocket pages

- Records a preference-checkbox choice when the visitor taps it, so Save My
  Preferences uses the intended value even if WebKit delivers Save before a
  cancelled checkbox click has been restored by the browser task timer. This
  follows a live v2.0.11 canary where rapid Customise → Analytics → Save
  stored Analytics off in four of five fresh mobile WebKit runs.
- Handles a repeated checkbox tap before the timer, normal label-to-input
  forwarding and keyboard activation. Timers from a closed preferences window
  cannot change a newly opened window. No synthetic input/change events are
  emitted, and the stored consent policy revision is unchanged.
- WP Rocket Delay JavaScript Execution and page caching stay enabled; this
  does not change GTM, Meta or other site tags. Verify the ordinary cached
  page on a signed canary before fleet deployment, especially rapid Save,
  replay deduplication and pre-choice requests. The v2.0.11 compatibility
  path still relies on WP Rocket's undocumented event hooks.

### 2.0.11 — WP Rocket consent interaction

- Registers this plugin's consent controls ahead of WP Rocket's delayed event
  replay, so Accept, Reject, Customise and preference actions can respond to
  the first genuine click. A replay guard prevents duplicate choices, consent
  updates and reloads; rapid checkbox choices are retained before Save.
- WP Rocket Delay JavaScript Execution, normal page caching, Elementor output,
  other page scripts, stored consent and Consent Mode mapping are unchanged.
  This does not correct an incorrectly gated GTM, Meta or site tag or guarantee
  higher marketing report totals.
- This compatibility path uses WP Rocket's internal `isRocket` listener option
  and `rocket-allScriptsLoaded` event, which are not a public API. Test the
  ordinary cached page on a Rocket-enabled canary, including first tap,
  rapid repeat, mobile keyboard/touch, pre-choice requests and cookie state,
  before wider deployment. If a future Rocket version changes its replay
  contract, this fix needs review. An earlier unguarded `isRocket` trial fired
  twice on replay; this version checks the open consent surface and ignores
  replayed controls before another consent update can be sent.

### 2.0.10 — Preferences modal actions

- Stacks the preferences-modal actions at every viewport width in matching visual,
  DOM and keyboard order: Accept All, Save My Preferences, then Reject All.
- Accept All is the only filled modal action. Save My Preferences and Reject All
  have transparent backgrounds and dark outlined borders, including on hover
  and focus. The existing banner actions are unchanged.
- Each action retains at least a 44px target, and the short-screen modal remains
  scrollable. Consent choices, policy revision, GTM loading and booking tracking
  are unchanged.
- Adds WP Rocket's documented `nowprocket` marker to the consent defaults and
  control script so those scripts are excluded from JavaScript delay.
  This does not disable page caching or WP Rocket's site-wide JavaScript delay;
  sites using that delay must verify first-tap consent behaviour separately.

### 2.0.9 — Integrate Resync consent behaviour

- Includes the v2.0.8 booking measurement, consent-check and banner-button changes.
- On `resyncphysiotherapy.ie` only, the footer menus include Cookie Policy and
  Cookie settings links. Cookie settings opens the existing preferences window.
  The preferences table discloses Meta and OptinMonster cookies used on that site.
- On Resync only, accessible first-party analytics and marketing cookies are
  expired after their category is rejected or withdrawn, and on later visits
  without valid consent. Consent and WordPress login cookies are preserved.
- On Resync only, Analytics-granted and Marketing-granted data-layer events are
  sent after a choice and on later visits with valid saved consent. Denied
  categories send no grant event. GTM still needs the matching published
  triggers and consent rules; this plugin update does not edit the container.
- The Resync code is host-gated. Other sites retain their existing cookie
  disclosures, footer menus and consent events.

### 2.0.8 — Booking measurement reliability

- Refreshes an expired booking nonce from a same-site endpoint when a cached page is
  older than the nonce, with one retry if the booking request receives an invalid-nonce
  response. Normal page caching stays enabled.
- Validates booking REST requests against the public WordPress home URL, so a
  separate WordPress installation URL does not reject legitimate visitors.
- Covers keyboard, assistive and pen activation of booking links, while avoiding a
  second event after a pointer click.
- Asks Google's linker to decorate the actual booking destination and forwards
  `_gl` with one URL encoding pass. This avoids losing domain-scoped linker
  values or breaking Glofox hash routes. Protocol-relative booking links are
  handled too. Cross-domain attribution still requires the destination to be
  configured in Google and to accept its linker parameter.
- Requires a current analytics consent choice before sending a server-side booking
  event, even if a GA cookie remains after a visitor rejects cookies.
- Stops forwarding a stored Google Ads click ID to a booking link after the
  visitor rejects marketing cookies.
- Accepts iframe booking-completion messages only from an exact trusted
  booking host or one of its subdomains, preventing lookalike hosts from
  creating false completion events.
- Clarifies the admin guidance: an event queued for GA4 is not confirmed
  delivery, and a booking click is not a completed booking.
- The banner now uses a filled dark Accept All button and dark outlined Reject
  All button; Customise remains outlined. At 768px and below the buttons stack
  Accept All, Customise, Reject All in matching visual and keyboard order.
  Preferences-modal decision buttons retain their equal filled treatment.
- Google Consent Mode defaults, disclosure text and GTM loading are unchanged.

### 2.0.7 — Remove a booking-link scanner false positive

- The `_gl` helper now uses an invalid example destination instead of a
  Cliniko-looking placeholder. No request is made to that destination. The
  consent interface, booking-domain list and URL-decoration flow are unchanged.

### 2.0.6 — Earlier Consents Are Kept

- Consents given under the banner wording before 2.0.4 are no longer discarded. Their
  analytics and functional choices are honoured.
- Visitors who had allowed marketing are asked again under the current wording. Until they
  choose, ads personalisation, ad user data and advertising storage stay denied.
- Raising a site's Policy Version still asks every visitor again. Consents given under 2.0.4
  or later are unchanged.

### 2.0.5 — Visitors Are Asked Again Under the New Wording

- Consents given before the 2.0.4 banner wording are no longer honoured: every visitor sees
  the banner once more and chooses under the text that discloses ads personalisation and
  sharing with Google.
- Stored consents now carry the plugin's banner-wording revision alongside the site's own
  Policy Version, so a future wording change re-prompts visitors without editing each site.
  Raising the Policy Version in settings still re-prompts as before.
- Google signals, booking tracking and every other setting are unchanged.

### 2.0.4 — Clearer Advertising Disclosure

- The banner now states that cookies and personal data are used for ads personalisation
  and measurement, and that they are shared with partners including Google, as Google's
  EU User Consent Policy requires on the first layer of a consent banner.
- Consent choices, stored records, Google signals and booking tracking are unchanged.

### 2.0.3 — Automatic Updates No Longer Remove the Plugin

- Fixed: an automatic update deleted the plugin instead of replacing it. The updater
  compared the install folder WordPress reported, which ends in a slash, with one that did
  not, treated every update as a misnamed folder, and removed the copy it had just
  installed.
- If an update cannot be moved into place, the updater now reports an error, so WordPress
  restores the previous version from its backup instead of discarding it.
- Sites running an earlier version must be updated by a direct install, not by automatic
  update: the update step runs the version already installed.
- Consent choices, stored records, Google signals and booking tracking are unchanged.

### 2.0.2 — Mobile Consent Visibility

- The consent surface, heading, body text, links and controls use a fixed high-contrast
  palette rather than inheriting Elementor colours from each site.
- Reject All and Accept All use the same filled dark treatment in the banner and preferences
  modal. Customise and Save My Preferences remain visually distinct outline controls.
- The banner and preferences modal use dynamic viewport limits and touch scrolling on
  short screens. Bottom padding respects device safe areas.
- The floating preferences icon stays hidden while the banner is open, even when a site's
  button styles set their own display value.
- Consent choices, stored records, Google signals and booking tracking are unchanged.

### 2.0.1 — Pre-release Installs Verify Correctly

- Fixed: installing a pre-release directly by its download URL was refused as "unsigned".
  The updater looked the signature up in the latest promoted release's metadata, which
  does not contain a pre-release's files; it now takes the signature published beside the
  exact file being installed. Verification remains strict — an actually unsigned build is
  still refused.
- No consent-banner design, settings field, or frontend style changes.

### 2.0.0 — Fleet Release

- Version number moves to 2.0.0 so every site, including those running older plugin
  lineages with higher version numbers, receives updates correctly from here on. No
  functional change is implied by the jump.
- Fixed a misleading status: a rate-limited configuration call arriving moments after a
  successful one no longer replaces the "synced" status with a warning. The success and
  its timestamp are kept; the extra attempt is simply noted.
- No consent-banner design, settings field, or frontend style changes.

### 1.7.2 — Clean Start

- No functional changes. Repository history consolidated; code and behaviour are identical
  to 1.7.1.

### 1.7.1 — Housekeeping

- No functional changes. Internal development documentation moved out of this repository;
  code and behaviour are identical to 1.7.0 apart from comments and this README.

### 1.7.0 — Signed Releases

- Every release is now cryptographically signed, and the updater verifies the signature
  before installing. A release that does not verify is refused: the running version stays
  untouched and the failure is logged.
- The GA4 Measurement Protocol API secret moved to its own non-autoloaded option. An
  existing value migrates automatically on first load; nothing needs re-entering.
- No consent-banner design, settings field, or frontend style changes.

### 1.6.1 — Enrolment Fix

- Fixed: the `/wp-json/hmdg-ccm/v1/verify` route was not served on ordinary front-end
  requests, so enrolment with the configuration service could not complete. It remains
  read-only and serves a single per-site hash — no settings, credentials, or visitor data.
- Corrected three plugin identifiers in conflict detection that could not match a real
  install.
- No consent-banner design, settings field, or frontend style changes.

### 1.6.0 — Weekly Reporting

- Each site now reports its own configuration state on every weekly configuration call,
  not only once at enrolment, and includes the WordPress plugin identifier beside the name
  of any known Google-tag plugin that is suppressing this plugin's tag output. No visitor,
  page, or consent data is sent.
- Removed the legacy verify nonce; `/verify` serves the key fingerprint alone.

### 1.5.0 — Central Tracking Configuration

- The GTM container ID, GA4 measurement ID, and Measurement Protocol API secret can now be
  supplied by HMDG's configuration service on a weekly schedule instead of being typed per
  site. A failed or empty fetch changes nothing, and an empty value never clears a working
  one. All other settings remain per-site.
- New read-only REST route `/wp-json/hmdg-ccm/v1/verify`, used once during enrolment to
  prove the site controls its domain. It exposes no settings, credentials, or visitor data.
- Fixed the activation hook never running; added a deactivation hook so scheduled work is
  cleaned up.

### 1.4.0 — Unattended Auto-Updates

- Updates now install themselves in the background instead of waiting for someone to click
  Update in wp-admin.
- Failed release checks are cached for an hour to respect GitHub API rate limits.
- The per-update notification email is suppressed for this plugin only.

### 1.3.1 — Paid Attribution Recovery

- Removed an unnecessary measurement hold and optional ad-click redaction from
  denied-consent cookieless pings. All Consent Mode v2 defaults remain denied until the
  visitor chooses.

### 1.3.0 — Production QA Hardening

- Performance: removed a header that prevented page caching; removed Bootstrap from the
  front end; consent UI assets are self-contained.
- Security: REST endpoints require a Content-Type header, validate message origins per
  platform, and the updater token is scoped to this repository only.
- Fixes: geo attribution, engaged-session counting, PHP 7.4 compatibility, and an
  Elementor layout issue caused by link decoration.

### 1.2.0 — Universal Booking Tracker

- Booking-conversion tracking across nine booking platforms via iframe postMessage,
  external link clicks, and return-redirect detection, with hardened REST endpoints.
