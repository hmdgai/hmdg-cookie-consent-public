/** Exercise the shipped booking-link listeners with pointer and keyboard events. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const php = fs.readFileSync(path.join(__dirname, '..', 'hmdg-cookie-consent.php'), 'utf8');
const match = php.match(/return \$banner \. <<<'JS'\r?\n([\s\S]*?)\r?\nJS;/);
assert.ok(match, 'shipped inline JavaScript exists');

const handlers = {};
const domHandlers = {};
const destination = 'https://app.glofox.com/portal/#/branch/example/classes-week-view';
let href = destination;
const link = {
  getAttribute(name) { return name === 'href' ? href : null; },
  setAttribute(name, value) { if (name === 'href') href = value; },
  addEventListener(name, fn) { (handlers[name] ||= []).push(fn); },
};
const posts = [];
const consent = encodeURIComponent(JSON.stringify({
  version: '1.0', policyVersion: '1', analytics: true,
}));
const cookie = '_ga=GA1.1.123456789.987654321; _ga_TEST=GS2.1.s1234567890$o1; '
  + 'hmdg_cookie_consent=' + consent;
const document = {
  readyState: 'loading', cookie, title: 'Book a class', referrer: '',
  addEventListener(name, fn) { domHandlers[name] = fn; },
  getElementById() { return null; },
  querySelectorAll(selector) { return selector === 'a[href]' ? [link] : []; },
};
const window = {
  hmdgCCM: {
    bookingDomains: ['app.glofox.com'], decoratorEnabled: true,
    mpTrackingEnabled: true, mpBookNowEndpoint: '/book-now', mpNonce: 'nonce',
    ga4MeasurementId: 'G-TEST',
  },
  location: { href: 'https://example.test/book/', origin: 'https://example.test',
    protocol: 'https:', pathname: '/book/', search: '', hash: '' },
  google_tag_data: { gl: { decorateUrl(url) {
    const decorated = new URL(url);
    decorated.searchParams.set('_gl', 'generated*token');
    return decorated.toString();
  } } },
};
const context = {
  window, document, location: { protocol: 'https:' }, URL, URLSearchParams,
  console: { log() {}, warn() {} }, setTimeout() {},
  fetch(endpoint, options) {
    posts.push({ endpoint, payload: JSON.parse(options.body) });
    return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
  },
};
vm.createContext(context);
vm.runInContext(match[1], context);
domHandlers.DOMContentLoaded();

function fire(name, detail) {
  for (const fn of handlers[name] || []) fn({ detail });
}

fire('click', 0);
assert.equal(posts.length, 1, 'keyboard or assistive click sends one booking event');
assert.equal(new URL(href).searchParams.get('_gl'), 'generated*token', 'keyboard link gets _gl');

href = destination;
fire('pointerdown', 1);
fire('mousedown', 1);
fire('click', 1);
assert.equal(posts.length, 2, 'pointer plus click sends one additional booking event');
assert.equal(new URL(href).searchParams.get('_gl'), 'generated*token', 'pointer link gets _gl');

href = destination;
fire('click', 1);
assert.equal(posts.length, 3, 'click without pointerdown works in older browsers');
assert.equal(new URL(href).searchParams.get('_gl'), 'generated*token', 'fallback click gets _gl');

assert.ok(posts.every(post => post.endpoint === '/book-now'), 'all events use the booking endpoint');
document.cookie = '_ga=GA1.1.123456789.987654321; hmdg_cookie_consent='
  + encodeURIComponent(JSON.stringify({ version: '1.0', policyVersion: '1', analytics: false }));
fire('click', 0);
assert.equal(posts.length, 3, 'a leftover GA cookie cannot send after Reject All');
console.log('PASS booking activation: keyboard, pointer deduplication, click fallback, _gl');
