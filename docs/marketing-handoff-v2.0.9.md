# HMDG WordPress cookie consent v2.0.9 — marketing handoff

**Status (4 November 2026):** [v2.0.9 signed prerelease](https://github.com/hmdgai/hmdg-cookie-consent-public/releases/tag/v2.0.9)
passed PHP 7.4, regression, archive and signature checks. Ben's Pilates
passed the first WP Engine canary. The remaining WP Engine rollout is under
way; GitHub Latest promotion is pending. This section will be updated with
final deployment results.

## Purpose and scope

This is the WordPress plugin in `hmdgai/hmdg-cookie-consent-public`, not the
Astro consent implementation. This handoff covers the shared measurement,
booking-link and banner changes released in the v2.0.9 build.

The plugin controls visitor consent, Google Consent Mode v2 signals, eligible
booking-link attribution and consented booking event delivery. Each site's GTM,
GA4, Google Ads and Meta configuration remains separate. A plugin update cannot
change a published GTM container or guarantee that reported conversions rise.

## Changes carried forward from v2.0.8

- A cached booking page can refresh its expired REST nonce without disabling
  normal page caching. The booking request retries once for an invalid nonce.
- The booking endpoint checks the site's public WordPress home origin and
  requires a current analytics choice before sending a server-side GA4 booking
  event. A leftover GA cookie does not count as consent.
- Keyboard, assistive technology and pen booking-link activation are handled;
  pointer clicks do not create duplicate events.
- Google's linker is asked to decorate the actual booking destination, and
  `_gl` is forwarded with one encoding pass. The fake `test.cliniko.com`
  placeholder is absent. Cross-domain attribution also depends on the
  destination being configured in Google and accepting the parameter.
- A stored Google Ads click ID is not forwarded after marketing consent is
  withdrawn. Booking completion messages require an exact trusted booking
  host or one of its subdomains.
- The banner uses a filled dark Accept All button, outlined Customise and
  outlined Reject All. At 768 CSS pixels and below, the order is Accept All,
  Customise, Reject All in both visual and keyboard order. The preferences
  modal retains equal filled Accept All and Reject All controls.

These changes improve the chance of recording *consented* booking activity
without treating an outbound click as a completed booking. They do not
authorise non-essential storage or consented booking events before consent.
GTM firing rules, platform configuration, browser blocking and GA4 processing
can still affect reports.

## Verification for marketing

For a representative WordPress site, use a fresh browser profile and GTM
Preview/Tag Assistant, then check all of the following:

1. Before any choice, analytics and advertising storage remain denied.
   Identify every GA4, Google Ads and Meta tag that fires. A
   plugin update does not itself pause tags configured to fire on All Pages.
2. Reject All: no non-essential tag should write a first-party cookie or send
   its normal measurement request. The preferences link must remain available.
3. Accept All and custom category choices: verify that only the allowed tag
   categories start, and that GTM sees the corresponding consent update.
4. Withdraw consent: confirm later non-essential requests stop. Existing
   third-party cookies cannot be deleted by this site. Use a fresh browser
   profile for the next baseline.
5. With analytics consent, click a real booking link using mouse, keyboard and
   mobile touch. Inspect the destination URL for a correctly encoded `_gl`
   when Google generates one, and compare the site's GA4 DebugView and booking
   platform records. An outbound booking click and a completed booking are
   different events.
6. Compare pre/post release counts with consent acceptance rate, browser mix,
   campaign traffic, GTM version, Ads/Meta attribution windows and GA4
   processing delay. Record site, page, date/time, browser, consent choice and
   expected versus observed tag/event for each discrepancy.

## Release identity and current deployment

The release tag points to source commit `6c20b55bd9a9b379141351ccb6948c600abd34c0`.
The signed ZIP is 56,678 bytes with SHA-256
`d542f2c32a44919188ea507e745dfe6fb2a94769e8a7ac51531dcf336c2b7c59`.
An independent check verified the Ed25519 signature, plugin version, archive
contents and absence of the old Cliniko placeholder.

The Ben's Pilates WP Engine install reports v2.0.9 and its host caches were
purged. Its ordinary public booking page served HTTP 200 with the v2.0.9
marker, three Glofox anchors and no old placeholder in an independent browser
check. Controlled and incoming `_gl` forwarding passed. On mobile WebKit,
all three banner buttons and the preferences Reject control were reachable.
A separate command-line request received a Cloudflare 403, so automated
responses can vary. Natural GTM-generated `_gl`, physical iOS Safari and
GA4 processing were not established. These facts do not establish any change
in GA4 or ad-platform reporting.

## Caching and deployment

Normal WordPress page caching stays enabled. Consent choices are read in the
visitor's browser; the nonce refresh response is uncached. Deployment must
install the exact signed release ZIP, verify the active plugin version, purge
host caches and inspect an ordinary public page. GitHub Latest controls
automatic updates, including sites outside WP Engine, so it must follow
canary and fleet checks.

The private operational handoff contains site inventory, install logs and
rollback steps. It is intentionally not published here because site install
names are SSH usernames. This page will record the final rollout outcome.
