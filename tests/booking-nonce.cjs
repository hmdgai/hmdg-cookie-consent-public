/** Cached-page nonce refresh and a single retry after an expired nonce. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const php = fs.readFileSync(path.join(__dirname, '..', 'hmdg-cookie-consent.php'), 'utf8');
const match = php.match(/return \$banner \. <<<'JS'\r?\n([\s\S]*?)\r?\nJS;/);
assert.ok(match, 'shipped inline JavaScript exists');

async function scenario({ issuedAt, firstPost, expectedGets, expectedPosts }) {
  const handlers = {};
  const events = {};
  const calls = [];
  const link = {
    getAttribute(name) { return name === 'href' ? 'https://app.glofox.com/book/' : null; },
    addEventListener(name, fn) { (handlers[name] ||= []).push(fn); },
  };
  const document = {
    readyState: 'loading', title: 'Book', referrer: '',
    cookie: '_ga=GA1.1.123456789.987654321; hmdg_cookie_consent='
      + encodeURIComponent(JSON.stringify({ version: '1.0', policyVersion: '1', analytics: true })),
    addEventListener(name, fn) { events[name] = fn; },
    getElementById() { return null; },
    querySelectorAll(selector) { return selector === 'a[href]' ? [link] : []; },
  };
  const window = {
    hmdgCCM: { bookingDomains: ['app.glofox.com'], decoratorEnabled: false,
      mpTrackingEnabled: true, mpBookNowEndpoint: '/book-now', mpNonce: 'old',
      mpNonceEndpoint: '/nonce', mpNonceIssuedAt: issuedAt },
    location: { href: 'https://example.test/book/', origin: 'https://example.test',
      pathname: '/book/', search: '', hash: '' },
  };
  let postCount = 0;
  const context = { window, document, location: { protocol: 'https:' },
    URL, URLSearchParams, console: { log() {}, warn() {} }, setTimeout() {},
    fetch(url, options) {
      calls.push({ url, options });
      if (options.method !== 'POST') {
        return Promise.resolve({ ok: true, status: 200,
          json: () => Promise.resolve({ nonce: 'fresh' }) });
      }
      postCount++;
      const response = postCount === 1 ? firstPost : { status: 200, data: { success: true } };
      return Promise.resolve({ status: response.status,
        json: () => Promise.resolve(response.data) });
    },
  };
  vm.createContext(context);
  vm.runInContext(match[1], context);
  events.DOMContentLoaded();
  await new Promise(setImmediate);
  for (const fn of handlers.click || []) fn({ detail: 0 });
  await new Promise(setImmediate);
  assert.equal(calls.filter(c => c.options.method !== 'POST').length, expectedGets);
  assert.equal(calls.filter(c => c.options.method === 'POST').length, expectedPosts);
  return calls;
}

(async () => {
  const stale = await scenario({ issuedAt: 1,
    firstPost: { status: 200, data: { success: true } }, expectedGets: 1, expectedPosts: 1 });
  assert.equal(stale.find(c => c.options.method === 'POST').options.headers['X-HMDG-Nonce'],
    'fresh', 'cached HTML refreshes the nonce before a booking click');
  assert.equal(stale[0].options.cache, 'no-store', 'nonce request bypasses cached responses');

  const fresh = await scenario({ issuedAt: Math.floor(Date.now() / 1000),
    firstPost: { status: 200, data: { success: true } },
    expectedGets: 0, expectedPosts: 1 });
  assert.equal(fresh[0].options.headers['X-HMDG-Nonce'], 'old',
    'a fresh page makes no extra nonce request');

  const expired = await scenario({ issuedAt: Math.floor(Date.now() / 1000),
    firstPost: { status: 403, data: { error: 'Invalid nonce' } },
    expectedGets: 1, expectedPosts: 2 });
  assert.equal(expired.filter(c => c.options.method === 'POST')[1].options.headers['X-HMDG-Nonce'],
    'fresh', 'expired nonce retries once with a refreshed value');

  await scenario({ issuedAt: Math.floor(Date.now() / 1000),
    firstPost: { status: 403, data: { error: 'Invalid origin' } },
    expectedGets: 0, expectedPosts: 1 });
  console.log('PASS booking nonce: proactive refresh, expired retry, origin rejection');
})().catch(error => { console.error(error); process.exitCode = 1; });
