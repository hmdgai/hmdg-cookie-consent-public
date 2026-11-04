/** Only the exact booking host or its subdomains may report completion. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const php = fs.readFileSync(path.join(__dirname, '..', 'hmdg-cookie-consent.php'), 'utf8');
const match = php.match(/return \$banner \. <<<'JS'\r?\n([\s\S]*?)\r?\nJS;/);
assert.ok(match, 'shipped inline JavaScript exists');
const domHandlers = {};
const windowHandlers = {};
const document = {
  readyState: 'loading', cookie: '',
  addEventListener(name, fn) { domHandlers[name] = fn; },
  getElementById() { return null; },
  querySelectorAll() { return []; },
};
const window = {
  hmdgCCM: { iframeTrackingEnabled: true, mpTrackingEnabled: false,
    postMessageMatchers: [{ platform: 'jane', domains: ['janeapp.com'],
      test: "typeof data === 'string' && data === 'booking_complete'" }] },
  location: { origin: 'https://example.test', search: '' },
  addEventListener(name, fn) { windowHandlers[name] = fn; },
};
const context = { window, document, location: { protocol: 'https:' },
  URL, URLSearchParams, setTimeout() {}, console: { log() {}, warn() {} } };
vm.createContext(context);
vm.runInContext(match[1], context);
domHandlers.DOMContentLoaded();
const send = origin => windowHandlers.message({ origin, data: 'booking_complete' });
send('https://janeapp.com.attacker.test');
send('https://otherjaneapp.com');
assert.equal((window.dataLayer || []).length, 0, 'lookalike hosts cannot report a booking');
send('https://booking.janeapp.com');
assert.equal(window.dataLayer.length, 1, 'a genuine subdomain reports a booking');
assert.equal(window.dataLayer[0].event, 'hmdg_booking_completed');
send('https://janeapp.com');
assert.equal(window.dataLayer.length, 1, 'completion stays deduplicated per platform');
console.log('PASS postMessage origin: exact/subdomain allowed, lookalikes blocked');
