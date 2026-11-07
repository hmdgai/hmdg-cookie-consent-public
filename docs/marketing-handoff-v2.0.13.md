# HMDG WordPress cookie consent v2.0.13 — marketing handoff

> **Status, 7 November 2026: built and tested, not yet released.** The source is on branch
> `fix/booking-click-id-forwarding`. The signed prerelease, canaries and fleet rollout follow the
> plan in the ops repo (`docs/superpowers/plans/2026-11-07-booking-click-id-forwarding.md`).
> Release waits on the privacy sign-off (Mark). This header is updated as each step completes.

## Why this release exists

On 6 November the tracking team found that ad-click bookings from visitors who don't accept the
banner were not reaching Google. The cause is a gap in the plugin's Book Now link decorator:

- The decorator added the Google click ID to Cliniko and Jane links only from Google's ad cookie
  (`_gcl_aw`). That cookie exists only after the visitor accepts Marketing.
- The click ID was also sitting in the page address (`?gclid=…`), put there by Google Ads and kept
  there between pages by Google's own passthrough in GTM. The decorator ignored it.
- Google's passthrough works only within the clinic's own site. On Moko, with the Cliniko host
  listed as a cross-domain linker domain and passthrough on, Google added nothing to the Cliniko
  link while consent was denied.

Separately, the Jane booking domains did not include `janeapp.co.uk`, where UK Jane clinics book.
16 fleet homepages link to it.

## What changes

1. **Booking links carry the click ID from the page address.** At click time, `gclid`, `gbraid` and
   `wbraid` are copied from the current page's address onto the booking link.
   - Only while the visitor has not answered the banner, or has granted Marketing.
   - Not after Reject All or Marketing off (decision D1, 7 November 2026).
   - Only to registered booking hosts. Existing parameters on the link are never overwritten.
   - Malformed values are dropped. Nothing else (`gad_source`, `utm_*`) is forwarded.
2. **UK Jane is recognised.** `janeapp.co.uk` links are now decorated, counted as Book Now clicks and
   accepted for iframe completion messages.
3. **More booking systems.** PracticeHub, TM3, Nookal, Gensolve, Zanda (Power Diary) and Acuity
   `as.me` links are decorated and counted as Book Now clicks. A 7 November probe found their booking
   pages running the clinic's own Google tag. No completion event fires for them yet. Book Now counts
   on these sites will step up after the update: that is newly counted clicks, not growth.

## What does not change

- Consent Mode defaults stay denied. The consent revision stays at 2, so nobody sees the banner again.
- No new cookie or storage. The plugin still does not set `url_passthrough`, and does not decorate
  internal links, so page caching is unaffected.
- The admin toggle **Append Google click IDs (gclid, gbraid, wbraid) and _gl to booking links at
  click time** remains the per-site off switch.

## What it can and cannot recover

- **Cliniko:** Cliniko loads no Google tag until the patient clicks "Consent to cookies". Once they do,
  a click ID in the booking link is stored and the conversion is attributed. Patients who decline
  Cliniko's prompt as well as ours are not recoverable from our side.
- **Jane UK:** once the patient presses "Allow all", Jane stores the click ID and Google Ads hits carry
  it. Before consent, Jane's denied, cookieless Google ping also carries the click ID from the landing
  address. How Jane's server-side Ads upload sources the click ID is still undocumented.
- **PracticeHub, Gensolve, Nookal, Zanda, Acuity:** the booking pages tested store the click ID as
  soon as they load; most ask for no cookie consent. **TM3** stores it after its own "Accept all".
  Where a clinic has not put its Google tag on the booking system, nothing is recovered.
- **Not a fix for:** the nine accounts at zero (each has its own cause), Cliniko's own consent gate,
  or Select&Book (its redirect drops the click ID), Semble, Rushcliff, TeamUp, Fresha and Momence
  (no clinic Google tag on their booking pages).
- Measure at +7 and +14 days before promising a number to clients.

## How to verify on a site

1. Open a page with `?gclid=TESTVALUE` in a fresh private window and ignore the banner.
2. Press and hold on a Book Now link (or hover and right-click → copy link). The link should contain
   `gclid=TESTVALUE`.
3. Reject All, reload with the same address: the link should **not** contain it.
4. Accept All: the link contains the click ID, and `_gl` as before.

The canary tool `tools/canary/click-id-probe.mjs` in the ops repo runs these checks automatically.
