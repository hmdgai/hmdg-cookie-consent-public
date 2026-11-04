# HMDG WordPress cookie consent v2.0.10 — marketing handoff

**Status (4 November 2026):** v2.0.10 is under release and site QA. This
page will be updated with the signed release, WP Engine deployment and cache
verification results before it is described as complete.

## What changed

The preferences window now presents **Accept All**, **Save My Preferences**,
then **Reject All** in visual, keyboard and screen-reader order. Accept All is
the only filled button; Save and Reject have transparent backgrounds and dark
outlines. The banner buttons, consent categories, stored choices, policy
revision and Google Consent Mode signals are unchanged. The preferences
window remains scrollable on short mobile screens.

The plugin also marks its consent defaults and control script with WP
Rocket's documented `nowprocket` exclusion, so these critical scripts are
kept out of JavaScript delay. That marker does not change WP Rocket's global
first-interaction handler; the live first-tap check below remains essential.

The release carries forward the v2.0.8 and v2.0.9 measurement improvements:

- A cached booking page can refresh an expired booking nonce and retry once
  after an invalid nonce, without disabling normal page caching.
- A server-side booking event requires a current Analytics choice. A leftover
  `_ga` cookie after rejection does not authorise it.
- Mouse, pen, keyboard and assistive activation can record a booking-link
  click without creating a duplicate after pointer activation.
- The Google linker decorates the actual booking destination. The plugin
  forwards a generated `_gl` once, without the old fake Cliniko URL. Google
  configuration and the booking destination must also support cross-domain
  attribution.
- The booking endpoint checks the site's public home origin. A stored Google
  Ads click ID is not forwarded after Marketing consent is withdrawn.
  Booking completion messages require an exact trusted booking host or one
  of its subdomains.

These changes can reduce **missed consented booking events** caused by stale
nonces, origin mismatch or unsupported link activation. They do not turn a
booking-link click into a confirmed booking, override a visitor's rejection,
or guarantee an increase in GA4 or ad-platform reports.

## What remains site-specific

Each site's published GTM container, GA4 and Google Ads configuration, Meta
tags, booking platform and reporting rules must be checked separately. A
plugin release cannot fix a tag that fires on All Pages without the correct
consent trigger, nor prove that GA4 accepted or attributed an event. Marketing
should compare pre/post reports with consent acceptance rate, traffic mix,
campaign changes, GTM version and attribution windows.

WP Rocket's **Delay JavaScript Execution** can affect Google tracking and
first-interaction controls even when the consent script itself is excluded.
The plugin does not need WP Rocket or Elementor caching to run, and normal
page caching stays enabled. On a site using JavaScript delay, test the first
tap of every consent action in a fresh mobile session and check that Google
and non-Google tags respect the visitor's choice. Review the site's
optimisation settings and GTM consent rules together before changing them.

## Verification steps for marketing

Use a fresh browser profile and GTM Preview/Tag Assistant on each
representative WordPress site:

1. Before any choice, confirm Analytics and advertising storage are denied.
   Identify any GA4, Google Ads and Meta requests or cookies sent before a
   choice.
2. Test the **first tap** of Accept All, Reject All and Customise on a mobile
   browser. Each must respond immediately; a second tap must not be needed.
3. Reject All and then a custom choice: confirm only allowed tag categories
   start and the corresponding consent update reaches GTM. Withdraw consent
   and check that later non-essential requests stop.
4. With Analytics consent, activate a real booking link by mouse, keyboard
   and touch. Inspect `_gl` when Google generates it, then compare GA4
   DebugView with booking-platform records. Check completed bookings
   separately from outbound clicks.
5. Record the site, page, time, browser, choice, GTM version and expected
   versus observed tag or event for any discrepancy.

## Release and cache evidence

The release identity, signed ZIP hash, canary results, WP Engine install
count and host-cache checks will be added when verified. Normal WordPress
page caching remains enabled. Consent choices are read in each visitor's
browser; only the nonce refresh response is uncached. A deployment check
must inspect the active plugin version **and** an ordinary public page after
the host cache is purged.

The private operational handoff contains the client install list, SSH
details, deployment logs and recovery procedure. It is deliberately not
published in this repository.
