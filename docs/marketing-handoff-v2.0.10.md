# HMDG WordPress cookie consent v2.0.10 — marketing handoff

**Status (4 November 2026, 22:48 Manila time):**
[v2.0.10 is a signed prerelease](https://github.com/hmdgai/hmdg-cookie-consent-public/releases/tag/v2.0.10).
Its PHP 7.4, regression, archive and signature checks passed. The first WP
Engine canary passed its plugin-version, host-cache, ordinary booking-page and
browser checks. The latest recorded tally across **274 eligible WP Engine
installs** is **73 on v2.0.10, 88 on v2.0.9 and 113 on v2.0.7**. One current
batch was still pending at this snapshot. This is a partial rollout, not a
completed fleet or a 274-site cache/public-page verification. GitHub Latest
remains v2.0.6; the v2.0.10 prerelease has not been promoted.

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
On Disc Chiropractic with v2.0.10, the first choice eventually saved in 8/8
fresh tests, but the banner took a median **2.247 seconds** to hide after the
tap, with no immediate feedback. A rapid second tap produced two consent
updates/events in 4/4 fresh WebKit tests. These are small, site-specific
samples; they do not establish a fleet-wide marketing-report effect.

A diagnostic test that bypassed Rocket's delay made the choice respond much
faster, but on Disc it also allowed a Meta request and `_fbp` cookie **before
any choice** in 2/2 fresh checks. The responsible site tag or GTM rule has
not yet been identified. **We will not disable WP Rocket's JavaScript delay
across the fleet** to speed up the banner while that privacy risk is open.
A consent-only first-tap and duplicate-event repair is under investigation;
no such version has been released. Normal page caching stays enabled. Test
each site's first consent action and pre/post-choice tag behaviour in a fresh
mobile session; the site's optimisation settings and published GTM consent
rules need review together.

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

The signed release was built from commit
`4bbfcb48c81800ecf3d4ade19de604ebabd26960` by
[CI run 41845594952](https://github.com/hmdgai/hmdg-cookie-consent-public/actions/runs/41845594952).
The 57,040-byte ZIP has SHA-256
`fa1d6fb03945e1061abbcf556aeb8c7888e0f6872130b12253cef5d67ebab46f`.
An independent check verified its signature, version and archive contents.
The initial WP Engine canary passed an independent plugin header check, an
object/Varnish/network/Page Speed Boost purge, an ordinary public booking
page and mobile Chromium/WebKit consent checks. Its controlled incoming
`_gl` forwarding passed; natural GTM-generated `_gl`, downstream GA4
acceptance, physical iOS Safari and marketing-report uplift were not proven.
The **73/88/113** version tally above remains a 22:48 partial snapshot; the
current batch, exact-version cache purges and wider public-page checks still
need reconciliation. Normal WordPress page caching remains enabled. Consent
choices are read in each visitor's browser; only the separate nonce refresh
response explicitly sends a no-store header. A deployment check must inspect
the active plugin version **and** an ordinary public page after the host
cache is purged.

The private operational handoff contains the client install list, SSH
details, deployment logs and recovery procedure. It is deliberately not
published in this repository.
