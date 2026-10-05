# HMDG Cookie Consent Mode v2

UK GDPR (PECR) and EU GDPR compliant cookie consent banner for WordPress, built around
Google Consent Mode v2, with booking-conversion tracking.

Maintained by [HMDG](https://hmdg.co.uk) for its client sites. This repository exists so
those sites can fetch updates over the public GitHub API; each release attaches a signed
`hmdg-cookie-consent.zip` that the plugin's built-in updater verifies and installs.

It is not published on wordpress.org and is not supported for general use. Please raise
any issue with HMDG directly.

## Privacy

The plugin sends no visitor, page, or consent data anywhere. Sites managed by HMDG fetch
their own tracking configuration (GTM container ID, GA4 measurement ID, GA4 Measurement
Protocol API secret) from HMDG's configuration service and report back only their own
configuration state. No credentials or IDs are present in this source code.

## Requirements

- WordPress 5.6 or later
- PHP 7.4 or later

## Licence

GPL v2 or later — <https://www.gnu.org/licenses/gpl-2.0.html>

---

## Changelog

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
