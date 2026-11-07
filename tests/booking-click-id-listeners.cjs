/** v2.0.13: click IDs through the real link listeners. A link decorated while the banner was pending
    must not keep the click ID after the visitor then rejects on the same page (D1). */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const php = fs.readFileSync(path.join(__dirname, '..', 'hmdg-cookie-consent.php'), 'utf8');
const match = php.match(/return \$banner \. <<<'JS'\r?\n([\s\S]*?)\r?\nJS;/);
assert.ok(match, 'shipped inline JavaScript exists');

const handlers = {};
const domHandlers = {};
const destination = 'https://moko-physiotherapy-ltd.uk2.cliniko.com/bookings#service';
let href = destination;
const link = {
  getAttribute(name) { return name === 'href' ? href : null; },
  setAttribute(name, value) { if (name === 'href') href = value; },
  addEventListener(name, fn) { (handlers[name] ||= []).push(fn); },
};
const consentCookie = marketing => 'hmdg_cookie_consent=' + encodeURIComponent(JSON.stringify({
  version: '1.0', policyVersion: '1', analytics: true, marketing,
}));
const document = {
  readyState: 'loading', cookie: '', title: 'Book', referrer: '',
  addEventListener(name, fn) { domHandlers[name] = fn; },
  getElementById() { return null; },
  querySelectorAll(selector) { return selector === 'a[href]' ? [link] : []; },
};
const window = {
  hmdgCCM: { bookingDomains: ['cliniko.com'], decoratorEnabled: true, mpTrackingEnabled: false },
  location: { href: 'https://example.test/?gclid=LANDING', origin: 'https://example.test',
    protocol: 'https:', pathname: '/', search: '?gclid=LANDING', hash: '' },
  google_tag_data: undefined,
};
const context = { window, document, location: { protocol: 'https:' }, URL, URLSearchParams,
  console: { log() {}, warn() {} }, setTimeout() {} };
vm.createContext(context);
vm.runInContext(match[1], context);
domHandlers.DOMContentLoaded();
const fire = name => { for (const fn of handlers[name] || []) fn({}); };
const gclid = () => new URL(href).searchParams.get('gclid');
assert.ok((handlers.touchstart || []).length, 'decorator is attached to the booking link');

// banner pending: forwarded
fire('touchstart');
assert.equal(gclid(), 'LANDING', 'pending: touchstart forwards the URL gclid');
assert.equal(new URL(href).hash, '#service', 'route kept');

// same page, visitor now rejects, then clicks the link
document.cookie = consentCookie(false);
fire('pointerdown'); fire('mousedown'); fire('click');
assert.equal(gclid(), null, 'after Reject All on the same page the earlier click id is removed');
assert.equal(href, destination, 'link returns to its original href');

// visitor later grants Marketing on the same page: forwarded again, once
document.cookie = consentCookie(true);
fire('mousedown'); fire('click');
assert.equal(gclid(), 'LANDING', 'after Accept the click id is forwarded again');
assert.equal(new URL(href).searchParams.getAll('gclid').length, 1, 'exactly one gclid');

// stored gclid after Accept, then withdrawal in the modal (v2.0.8 path, same persistence)
window.location.search = ''; window.location.href = 'https://example.test/';
document.cookie = consentCookie(true) + '; _gcl_aw=GCL.1.STORED';
fire('mousedown');
assert.equal(gclid(), 'STORED', 'consented stored gclid forwarded');
document.cookie = consentCookie(false) + '; _gcl_aw=GCL.1.STORED';
fire('click');
assert.equal(gclid(), null, 'stored gclid removed after Marketing is withdrawn on the same page');

// a site script replaces the href after decoration: the new href becomes the base
document.cookie = '';
window.location.search = '?gclid=AGAIN';
fire('mousedown');
href = 'https://moko-physiotherapy-ltd.uk2.cliniko.com/bookings?practitioner=7#service';
fire('click');
assert.equal(new URL(href).searchParams.get('practitioner'), '7', 'site-changed href respected');
assert.equal(gclid(), 'AGAIN', 'site-changed href decorated');
document.cookie = consentCookie(false);
fire('click');
assert.equal(href, 'https://moko-physiotherapy-ltd.uk2.cliniko.com/bookings?practitioner=7#service',
  'reject restores the site-changed href, not the first one');

console.log('PASS booking click id listeners: pending → reject removes click id; accept re-adds; site href changes respected');
