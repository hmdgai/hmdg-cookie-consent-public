/** Guard the booking URL path that reads and forwards Google's _gl value. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const php = fs.readFileSync(path.join(__dirname, '..', 'hmdg-cookie-consent.php'), 'utf8');
assert.ok(!php.includes('test.cliniko.com'), 'scanner placeholder is absent');
const match = php.match(/return \$banner \. <<<'JS'\r?\n([\s\S]*?)\r?\nJS;/);
assert.ok(match, 'shipped inline booking script exists');
const script = match[1].replace(/\}\)\(\);\s*$/, 'globalThis.bookingUrl = decorateBookingUrl;\n})();');

const destination = 'https://app.glofox.com/portal/#/branch/example/classes-week-view?header=classes';
const calls = [];
const window = {
  hmdgCCM: { bookingDomains: ['app.glofox.com'] },
  location: { protocol: 'https:', search: '' },
  google_tag_data: { gl: { decorateUrl(url) {
    calls.push(url);
    if (new URL(url).hostname !== 'app.glofox.com') return url;
    const decorated = new URL(url);
    decorated.searchParams.set('_gl', 'generated*token');
    return decorated.toString();
  } } },
};
const document = { readyState: 'loading', addEventListener() {}, cookie: '' };
const context = { window, document, location: { protocol: 'https:' }, URL, URLSearchParams, console, setTimeout };
vm.createContext(context);
vm.runInContext(script, context);

const decorated = new URL(context.bookingUrl(destination));
assert.equal(calls.length, 1, 'Google linker is called once');
assert.equal(calls[0], destination, 'linker receives the actual Glofox destination');
assert.equal(decorated.searchParams.get('_gl'), 'generated*token', 'generated linker value reaches Glofox URL once encoded');
assert.ok(!decorated.href.includes('%252A'), 'linker value is not encoded twice');
assert.equal(decorated.hash, new URL(destination).hash, 'Glofox route survives URL decoration');

window.google_tag_data = undefined;
window.location.search = '?_gl=incoming%2Atoken';
assert.equal(new URL(context.bookingUrl(destination)).searchParams.get('_gl'), 'incoming*token', 'incoming linker fallback reaches Glofox URL once encoded');
window.location.search = '';
assert.equal(new URL(context.bookingUrl(destination)).searchParams.has('_gl'), false, 'no synthetic linker value is added');

const accepted = encodeURIComponent(JSON.stringify({
  version: '1.0', policyVersion: '1', analytics: true, marketing: true,
}));
document.cookie = 'hmdg_cookie_consent=' + accepted + '; _gcl_aw=GCL.123.clickid';
assert.equal(new URL(context.bookingUrl(destination)).searchParams.get('gclid'),
  'clickid', 'stored ad click ID reaches booking link with marketing consent');
document.cookie = 'hmdg_cookie_consent=' + encodeURIComponent(JSON.stringify({
  version: '1.0', policyVersion: '1', analytics: true, marketing: false,
})) + '; _gcl_aw=GCL.123.clickid';
assert.equal(new URL(context.bookingUrl(destination)).searchParams.has('gclid'), false,
  'leftover ad click cookie is not forwarded after marketing rejection');

window.location.search = '?_gl=incoming%2Atoken';
const protocolRelative = context.bookingUrl('//app.glofox.com/portal/#/branch/example');
assert.equal(new URL(protocolRelative).searchParams.get('_gl'), 'incoming*token', 'protocol-relative booking link is decorated');

console.log('PASS booking linker: actual domain, _gl encoding, Glofox route, consented gclid');
