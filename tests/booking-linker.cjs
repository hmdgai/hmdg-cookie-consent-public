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
  location: { search: '' },
  google_tag_data: { gl: { decorateUrl(url) {
    calls.push(url);
    return url + '?_gl=generated%2Atoken';
  } } },
};
const document = { readyState: 'loading', addEventListener() {}, cookie: '' };
const context = { window, document, location: { protocol: 'https:' }, URL, URLSearchParams, console, setTimeout };
vm.createContext(context);
vm.runInContext(script, context);

const decorated = new URL(context.bookingUrl(destination));
assert.equal(calls.length, 1, 'Google linker is called once');
assert.equal(calls[0], 'https://example.invalid/', 'linker receives an invalid example destination');
assert.equal(decorated.searchParams.get('_gl'), 'generated%2Atoken', 'generated linker value reaches Glofox URL');
assert.equal(decorated.hash, new URL(destination).hash, 'Glofox route survives URL decoration');

window.google_tag_data = undefined;
window.location.search = '?_gl=incoming%2Atoken';
assert.equal(new URL(context.bookingUrl(destination)).searchParams.get('_gl'), 'incoming%2Atoken', 'incoming linker fallback reaches Glofox URL');
window.location.search = '';
assert.equal(new URL(context.bookingUrl(destination)).searchParams.has('_gl'), false, 'no synthetic linker value is added');

console.log('PASS booking linker: invalid placeholder, generated and incoming _gl, Glofox route');
