/** v2.0.13: Google click IDs in the page URL reach booking links, only while no choice is saved
    or Marketing is granted. Never after Reject All (D1), never overwriting, never malformed. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const FORWARD_AFTER_REJECT = false; // D1, decided 7 Nov 2026

const php = fs.readFileSync(path.join(__dirname, '..', 'hmdg-cookie-consent.php'), 'utf8');
const match = php.match(/return \$banner \. <<<'JS'\r?\n([\s\S]*?)\r?\nJS;/);
assert.ok(match, 'shipped inline booking script exists');
const script = match[1].replace(/\}\)\(\);\s*$/, 'globalThis.bookingUrl = decorateBookingUrl;\n})();');

const window = {
  hmdgCCM: { bookingDomains: ['cliniko.com'] },
  location: { protocol: 'https:', search: '' },
  google_tag_data: undefined,
};
const document = { readyState: 'loading', addEventListener() {}, cookie: '' };
const context = { window, document, location: { protocol: 'https:' }, URL, URLSearchParams, console, setTimeout };
vm.createContext(context);
vm.runInContext(script, context);

const consentCookie = marketing => 'hmdg_cookie_consent=' + encodeURIComponent(JSON.stringify({
  version: '1.0', policyVersion: '1', analytics: true, marketing,
}));
const accepted = consentCookie(true);
const rejected = consentCookie(false);
const search = s => { window.location.search = s; };
const cookie = c => { document.cookie = c; };
const bookingUrl = h => context.bookingUrl(h);
const url = h => new URL(bookingUrl(h));
const eq = (a, b, m) => assert.equal(a, b, m);
const ok = (v, m) => assert.ok(v, m);

const B = 'https://moko-physiotherapy-ltd.uk2.cliniko.com/bookings#service';

// no saved choice
search('?gclid=Cj0KCQ_abc-1'); cookie('');
eq(url(B).searchParams.get('gclid'), 'Cj0KCQ_abc-1', 'URL gclid forwarded with no consent');
eq(url(B).hash, '#service', 'hash route kept');
ok(bookingUrl(B).indexOf('?gclid=') < bookingUrl(B).indexOf('#'), 'query sits before hash');

// iOS ids, nothing else
search('?wbraid=W1&gbraid=G1&gad_source=1&utm_source=google');
eq(url(B).searchParams.get('wbraid'), 'W1', 'URL wbraid forwarded');
eq(url(B).searchParams.get('gbraid'), 'G1', 'URL gbraid forwarded');
ok(!url(B).searchParams.has('gad_source') && !url(B).searchParams.has('utm_source'), 'only click ids forwarded');

// hostile values
for (const v of ['%3Cscript%3E', 'a%20b', 'x'.repeat(513), '']) {
  search('?gclid=' + v);
  ok(!url(B).searchParams.has('gclid'), 'invalid dropped: ' + v.slice(0, 12));
}
// duplicate keys: first occurrence wins, and only if valid
search('?gclid=FIRST&gclid=SECOND'); eq(url(B).searchParams.get('gclid'), 'FIRST', 'first duplicate used');
search('?gclid=%3Cx%3E&gclid=SECOND'); ok(!url(B).searchParams.has('gclid'), 'invalid first duplicate drops gclid');

// precedence and no overwrite
search('?gclid=NEW'); cookie(accepted + '; _gcl_aw=GCL.1.OLD');
eq(url(B).searchParams.get('gclid'), 'NEW', 'URL click wins over stored');
search(''); eq(url(B).searchParams.get('gclid'), 'OLD', 'consented stored gclid still used');
search('?gclid=NEW&wbraid=W2');
eq(url('https://x.cliniko.com/b?gclid=KEEP').searchParams.get('gclid'), 'KEEP', 'existing gclid not overwritten');
eq(url('https://x.cliniko.com/b?wbraid=KEEPW').searchParams.get('wbraid'), 'KEEPW', 'existing wbraid not overwritten');

// idempotent
search('?gclid=ONCE&gbraid=G3'); cookie('');
const once = new URL(bookingUrl(bookingUrl(bookingUrl(B))));
eq(once.searchParams.getAll('gclid').length, 1, 'repeat decoration adds one gclid');
eq(once.searchParams.getAll('gbraid').length, 1, 'repeat decoration adds one gbraid');

// non-booking link untouched
eq(bookingUrl('https://example.com/page'), 'https://example.com/page', 'non-booking link untouched');

// D1: Reject All
search('?gclid=R1&wbraid=RW'); cookie(rejected);
eq(url(B).searchParams.get('gclid'), FORWARD_AFTER_REJECT ? 'R1' : null, 'Reject All behaviour per D1 (gclid)');
eq(url(B).searchParams.get('wbraid'), FORWARD_AFTER_REJECT ? 'RW' : null, 'Reject All behaviour per D1 (wbraid)');

// leftover cookie after reject is still never read (v2.0.8 guarantee)
search(''); cookie(rejected + '; _gcl_aw=GCL.1.LEFT');
ok(!url(B).searchParams.has('gclid'), 'stored gclid not forwarded after reject');

// consent under another policy version counts as no choice: the banner is showing again
search('?gclid=STALE'); cookie('hmdg_cookie_consent=' + encodeURIComponent(JSON.stringify({
  version: '1.0', policyVersion: 'old-policy', analytics: true, marketing: false,
})));
eq(url(B).searchParams.get('gclid'), 'STALE', 'consent under an old policy version is treated as no choice');

// UK Jane: the registry's Jane domains must match janeapp.co.uk booking links
const janeEntry = php.match(/'jane'\s*=>\s*\[[\s\S]*?'domains'\s*=>\s*\[([^\]]*)\]/);
assert.ok(janeEntry, 'Jane registry entry exists');
const janeDomains = [...janeEntry[1].matchAll(/'([^']+)'/g)].map(m => m[1]);
const janeWindow = { hmdgCCM: { bookingDomains: janeDomains }, location: { protocol: 'https:', search: '?gclid=Cj0KCQ_abc-1' }, google_tag_data: undefined };
const janeDocument = { readyState: 'loading', addEventListener() {}, cookie: '' };
const janeContext = { window: janeWindow, document: janeDocument, location: { protocol: 'https:' }, URL, URLSearchParams, console, setTimeout };
vm.createContext(janeContext);
vm.runInContext(script, janeContext);
const janeLink = new URL(janeContext.bookingUrl('https://clinic.janeapp.co.uk/#/staff_member/1'));
eq(janeLink.searchParams.get('gclid'), 'Cj0KCQ_abc-1', 'UK Jane booking link gets the URL gclid');
eq(janeLink.hash, '#/staff_member/1', 'Jane route kept');
eq(janeContext.bookingUrl('https://janeapp.co.uk.attacker.test/'), 'https://janeapp.co.uk.attacker.test/', 'lookalike Jane host untouched');

console.log('PASS booking click id: URL ids forwarded while undecided or granted, validated, never overwritten, not after Reject All');
