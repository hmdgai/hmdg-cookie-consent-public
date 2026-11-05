# HMDG WordPress cookie consent v2.0.12 — marketing handoff

> **WP Engine release verification completed, 5 November 2026.**
> [v2.0.12 is a signed prerelease](https://github.com/hmdgai/hmdg-cookie-consent-public/releases/tag/v2.0.12),
> and two signed WP Engine canaries passed their exact-version, cache,
> first-choice, rapid-Save and tested privacy checks. The approved WP Engine
> install ledger now records 275 signed v2.0.12 install results with no skips
> or failures. An independent sweep confirmed all 275 active canonical installs
> have matching v2.0.12 file headers and WordPress versions. All 274 main-cohort
> WP Engine full-cache purges passed; the additional site passed its separate
> purge. Ordinary public-page checks found 96 v2.0.12 markers, 41 pages with
> no plugin marker, and one live domain served by Flywheel at v2.0.6. One of
> the no-marker pages also has a Flywheel server header.
> This release is scoped to WP Engine. GitHub Latest deliberately stays on
> v2.0.6 so Flywheel-managed sites do not receive an automatic update.

At the pause before this release, the provisional WP Engine version ledger
for **274 eligible installs** showed **81 on v2.0.10, 80 on v2.0.9 and 113 on
v2.0.7**. The single v2.0.11 canary was rolled back to v2.0.10 after a
failed rapid-preference test; no v2.0.11 fleet batch ran. These figures are
installation records, not an independent check that every ordinary public
page served the same version.
After the two canaries, the pre-batch checkpoint was **2 on v2.0.12, 79 on
v2.0.10, 80 on v2.0.9 and 113 on v2.0.7**. The 14 bounded batch logs now
record **272/272 signed installs to v2.0.12**, with no skip or failure. With
the two canaries, that is **274/274 original-cohort install results**. A
read-only audit of 25
older unreachable entries found **one newly reachable active installation**
outside the original 274; the other 24 remained unreachable. The expanded
reachable active WP Engine cohort is **275**. That separate late target also
has a signed v2.0.12 install result, independent active header, host-cache
purge and ordinary public-page check. The combined **275 signed install
results** were independently reconciled against all 275 active canonical
plugin folders. The separate check found four expected plugin files at each
install and matching v2.0.12 file-header and WordPress versions throughout.
The main 274-site cache purge passed with zero failures between
05:59:37 and 06:13:27 UTC on 5 November 2026. In the 138 mapped ordinary
public-page checks at 06:13:49–06:14:11 UTC, all pages returned HTTP 200: 96 showed
v2.0.12 without the old fake booking URL, 41 showed no plugin marker, and
one live domain routed to Flywheel and showed its older release and the
old placeholder. The 41 no-marker pages are exactly the same set seen in the
earlier public baseline; one of these pages also has a Flywheel server
header. Their installed WP Engine copies passed the active-version audit,
but the absence of a public marker cannot establish a page's plugin version
or why output is absent. Among mapped pages exposing a marker, the only
older marker was on the separately hosted Flywheel page. The separately
added site is included among the 96 v2.0.12 public markers.

## What changed

v2.0.11 prepared cookie controls to respond on the first genuine click when
WP Rocket delays and later replays JavaScript events. Its replay guard was
intended to prevent a rapid second tap from sending duplicate consent
updates. A live mobile WebKit canary found a remaining error: when a visitor
tapped an Analytics preference and then **Save My Preferences** about 10–12
milliseconds later, the saved Analytics choice was wrong in **four of five**
fresh trials. The canary was rolled back and that prerelease was blocked
from wider deployment.

v2.0.12 records the intended checkbox choice as the visitor taps it, so a
rapid Save can read the choice before WP Rocket's delayed browser task
restores the checkbox. It also handles a repeated tap, label-to-input click
forwarding and keyboard activation. A timer from an old preferences window
cannot change a newly opened window. The fix does not emit extra synthetic
input or change events. The consent policy revision, stored-choice format,
Google Consent Mode mapping, booking tracker, button design and copy are
unchanged.

The plugin does not disable a site's **WP Rocket Delay JavaScript Execution**
setting for other scripts. Normal page caching and Elementor output remain enabled and
unchanged. The consent interaction still relies on WP Rocket's internal
listener/replay behaviour, which is not a documented public API. A later WP
Rocket update may require this path to be reviewed again.

## What this may mean for reporting

In the two tested canaries, first cookie choices and rapid preference Save
stored the visitor's intended choice, and Rocket replay did not create
duplicate consent updates. At the wider fleet level, this may reduce
**missed or duplicate consent updates** that affect later consented measurement.
It does not send marketing tags after Reject, change a visitor's
choice, guarantee an increase in GA4 or advertising reports, or prove that
Google accepted a booking event. The earlier booking nonce, origin, linker
and consent checks remain in the plugin; v2.0.12 does not add a new booking
measurement rule.

A diagnostic test that bypassed all JavaScript delay exposed a Meta request
and `_fbp` cookie **before any choice** on one site. An ordinary-page test
also found Meta activity **after Reject** on that site, while another
Rocket-enabled site's no-choice and Reject baseline was clean. The published
GTM and site-tag owner has not been established for the faulty site. WP
Rocket delay is not a consent rule, and the plugin cannot correct an
incorrectly gated GTM or Meta tag. Check each site's published tags and
cookies separately before calling its tracking compliant.

## Marketing checks after the canaries

1. In fresh mobile sessions, confirm Consent Mode is denied before any
   choice. Record pre-choice network requests and cookies; Google
   consent-aware cookieless pings may still occur while storage is denied.
2. Test first-tap **Accept All**, **Reject All** and **Customise**. In the
   preferences window, tap Analytics and save immediately, then repeat with
   two quick taps and keyboard activation. Check the actual saved choice,
   one consent update and no duplicate reload after WP Rocket replays events.
3. Test no choice, Reject, a custom choice, Accept and later withdrawal.
   Confirm Meta, Google Analytics, Google Ads, OptinMonster and other tags
   obey the relevant choice in GTM Preview/Tag Assistant and the browser
   network and cookie panels. A non-Google tag firing before consent or after
   Reject needs a site-tag fix outside this plugin release.
4. With Analytics consent, test a real booking link and a verified booking
   completion separately. Compare booking records with GA4 DebugView and
   later reports. A clicked link, queued server request or `_gl` value alone
   does not prove Google accepted or attributed a conversion.
5. Record the page, browser, time, choice, GTM version and expected versus
   observed request or event. Compare reports only after accounting for
   traffic, consent rate, campaign changes and attribution windows.

Automated Chromium and WebKit checks do not replace physical iOS Safari
testing. The earlier filled Accept and outlined Reject design remains
subject to a separate consent-choice prominence review.

## Signed release and cache evidence

The [successful release workflow](https://github.com/hmdgai/hmdg-cookie-consent-public/actions/runs/41854865911)
published v2.0.12 from source commit
`35e91842932bb4025a6f370b75e04af9c79cca65`. The 59,233-byte
`hmdg-cookie-consent.zip` has SHA-256
`d99cb41dcba2a7ddfb0b463fbf3c0832752b6143b266e296a658d41a10041b69`.
An independent download check verified its Ed25519 signature, expected
archive contents, both v2.0.12 version fields and absence of the old fake
booking URL. The artifact check alone did not establish an install or live
rapid-Save result; the separate canary evidence is below. GitHub Latest still reports **v2.0.6** at
the time of writing and is intentionally unchanged for this WP Engine-only
stage. Promoting Latest would also expose Flywheel-managed sites to their
automatic updaters, so that belongs to a separate host rollout.

An earlier release check found an updated plugin file header while an
ordinary public page still served the previous version from cache. A
version-gated host purge made the public page serve the installed version.
For v2.0.12, check the active plugin header, purge the host cache and WP
Rocket cache if needed, then inspect the **ordinary public page** after
purge. Keep normal page caching enabled; only the separate booking-nonce
refresh response explicitly sends `private, no-store`.

## Two signed canaries — passed within tested limits

Both canaries installed the **exact signed v2.0.12 ZIP**, independently
reported active v2.0.12 file headers, and passed version-gated WP Engine
object, Varnish, network and Page Speed Boost purges. Ordinary public pages
returned HTTP 200 with the v2.0.12 marker, WP Rocket delay still active,
normal `max-age=600` caching and no old fake booking URL. Neither needed a
separate WP Rocket cache clear.

- **First canary, consent interaction:** rapid Analytics tap→Save without
  waiting for the checkbox restoration timer saved Analytics **on** and
  Marketing **off** in **5/5 WebKit and 5/5 Chromium** trials. WebKit Save
  followed the tap by 20–51 ms; one consent update remained after Rocket
  replay about 2.7 seconds later. Six fresh Accept, Reject and
  Customise→Analytics→Save flows across the two engines saved the matching
  cookie, produced one update/event and did not reopen the UI. No Meta
  request or `_fbp` appeared before choice in six one-second windows. The
  site's existing published tag still fired Meta after Reject or an
  Analytics-only choice; that separate tag issue is **not** a plugin canary
  privacy pass.
- **Second canary, clean privacy baseline:** ordinary cached HTML was still
  on v2.0.12 with WP Rocket active. Fresh WebKit and Chromium no-choice
  sessions kept a denied default for four seconds with no Meta, `_fbp` or
  `_ga`. Reject saved necessary-only in **19 ms and 22 ms** respectively,
  emitted one denied update, and showed none of those marketing requests or
  cookies over the next three seconds. Rapid Analytics tap→Save saved
  Analytics **on** and Marketing **off** in **5/5 WebKit and 5/5 Chromium**
  trials, with one update after Rocket replay. Six additional first-choice
  flows across the engines saved matching cookies and one update/event, with
  reachable controls, no duplicate or reopened UI, and no Meta before
  choice or after the tested choices.

An additional fresh **Firefox** check on the second canary's ordinary cached
page at a 390×667 viewport passed Accept, Reject and
Customise→Analytics→Save. Each saved the expected cookie with one Consent
Mode update and one plugin event; no `_fbp` appeared. First Accept took
34 ms and Reject 26 ms in this automated check. This was a desktop Firefox
engine at mobile width, not a physical mobile browser or iPhone.

These are short controlled browser observations, not a full published-GTM
audit, a physical iPhone test, downstream GA4 acceptance or proof of a
marketing-report improvement. The first canary's separate Meta-after-Reject
tag fault still needs its site owner.

## Deployment result ledger — WP Engine checks complete

| Gate | Current status |
|---|---|
| Signed v2.0.12 source and ZIP | Passed CI and independent artifact verification as above |
| First WP Rocket canary: exact header, purge, ordinary page, first choice, rapid Save, replay | Passed within the tested samples above; separate site-tag fault remains |
| Second WP Rocket privacy canary: exact header, purge, cached page, no choice, Reject and rapid Save | Passed within the tested samples above |
| Signed WP Engine install results | Original cohort: 272/272 bounded batch results, plus two passed canaries, equals 274/274; zero skips or failures. One separate late signed update makes 275 results across the expanded reachable active cohort. |
| Independent active plugin versions | Passed: 275/275 unique reachable installs have one active canonical folder, four expected files, and matching v2.0.12 file-header and WordPress versions. All 11 first-pass anomalies were resolved by targeted read-only checks. |
| Host-cache purge and ordinary public-page checks | Passed: 274/274 unique main-cohort WP Engine full-cache purges at exact v2.0.12, zero failures, plus the additional site's separate purge. In 138 mapped ordinary public-page checks, 138 returned HTTP 200; 96 showed v2.0.12 with no old fake URL, 41 showed no marker (the same set as the earlier baseline, including one Flywheel-served page), and one other Flywheel-served page remained on v2.0.6 with the old placeholder. Among pages exposing a marker, the only older marker was on the separately hosted Flywheel page. A missing public marker cannot establish that page's plugin version or why output is absent. |
| GitHub Latest and Flywheel-managed sites | Latest deliberately remains v2.0.6 during this WP Engine-only release. Flywheel rollout and any Latest promotion are a separate stage. |
| Physical iOS Safari, published GTM corrections, GA4 acceptance and reporting change | Not established by this plugin release |

The client install list, operational commands, canary evidence and exceptions
are kept in the private handoff. This public handoff contains no client site
identifiers.
