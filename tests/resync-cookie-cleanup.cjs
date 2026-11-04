/** Exercise the shipped inline JavaScript's Resync-only cookie cleanup. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const php = fs.readFileSync(path.join(__dirname, '..', 'hmdg-cookie-consent.php'), 'utf8');
const match = php.match(/return \$banner \. <<<'JS'\r?\n([\s\S]*?)\r?\nJS;/);
assert.ok(match, 'inline consent JavaScript exists');
const script = match[1].replace(/\}\)\(\);\s*$/, [
  'globalThis.cleanup = clearResyncUnconsentedCookies;',
  'globalThis.emitGranted = emitResyncGrantedEvents;',
  'globalThis.readConsent = readCookie;',
  // Exercise the real boot path while isolating unrelated page wiring.
  'wireEvents = detectDuplicateScripts = processAllBookingLinks = watchForNewBookingLinks = setupPostMessageListener = checkReturnRedirect = runValidator = showReopen = function(){};',
  'globalThis.bootConsent = boot;',
  '})();',
].join('\n'));

function browser(host) {
  const jar = new Map();
  const writes = [];
  const document = {
    readyState: 'loading',
    addEventListener() {},
    get cookie() {
      return [...jar].map(([name, value]) => `${name}=${value}`).join('; ');
    },
    set cookie(value) {
      writes.push(value);
      const [pair, ...attributes] = value.split(';');
      const equals = pair.indexOf('=');
      const name = pair.slice(0, equals);
      if (attributes.some(attr => /max-age=0|expires=Thu, 01 Jan 1970/i.test(attr))) {
        jar.delete(name);
      } else {
        jar.set(name, pair.slice(equals + 1));
      }
    },
  };
  const context = { window: { hmdgCCM: { policyVersion: '1-r2', sitePolicyVersion: '1' } }, document, location: { hostname: host, protocol: 'https:' }, console, URL, Date, setTimeout() {} };
  vm.createContext(context);
  vm.runInContext(script, context);
  return { jar, writes, cleanup: context.cleanup, emitGranted: context.emitGranted, readConsent: context.readConsent, bootConsent: context.bootConsent, events: () => context.window.dataLayer || [] };
}

const site = browser('resyncphysiotherapy.ie');
for (const name of ['_ga', '_ga_PVK5JM37CB', '_gid', '_gat_gtag_UA', '_fbp', '_fbc', '_gcl_au', '_gac_test', '_omappvp', '_omappvs', 'hmdg_cookie_consent', 'wordpress_logged_in_123']) {
  site.jar.set(name, 'test');
}
site.cleanup({ analytics: false, marketing: true });
for (const name of ['_ga', '_ga_PVK5JM37CB', '_gid', '_gat_gtag_UA']) {
  assert.equal(site.jar.has(name), false, `${name} removed after Analytics withdrawal`);
}
for (const name of ['_fbp', '_gcl_au', '_omappvp']) {
  assert.equal(site.jar.has(name), true, `${name} retained with Marketing consent`);
}
site.cleanup({ analytics: true, marketing: false });
for (const name of ['_fbp', '_fbc', '_gcl_au', '_gac_test', '_omappvp', '_omappvs']) {
  assert.equal(site.jar.has(name), false, `${name} removed after Marketing withdrawal`);
}
for (const name of ['hmdg_cookie_consent', 'wordpress_logged_in_123']) {
  assert.equal(site.jar.has(name), true, `${name} is not a tracking cookie`);
}

const www = browser('www.resyncphysiotherapy.ie');
www.jar.set('_fbp', 'test');
www.cleanup({ analytics: false, marketing: false });
assert.ok(www.writes.some(write => write.includes('domain=resyncphysiotherapy.ie')), 'www also expires root-domain cookies');

const another = browser('example.com');
another.jar.set('_ga', 'test');
another.cleanup({ analytics: false, marketing: false });
assert.equal(another.jar.has('_ga'), true, 'other sites are untouched');

site.emitGranted({ analytics: false, marketing: false });
assert.equal(site.events().length, 0, 'denied choices emit no grant events');
site.emitGranted({ analytics: true, marketing: false });
assert.equal(site.events().length, 1, 'Analytics grant emits one event');
assert.equal(site.events()[0].event, 'hmdg_resync_analytics_granted');
site.emitGranted({ analytics: false, marketing: true });
assert.equal(site.events().length, 2, 'Marketing grant emits one event');
assert.equal(site.events()[1].event, 'hmdg_resync_marketing_granted');
another.emitGranted({ analytics: true, marketing: true });
assert.equal(another.events().length, 0, 'other sites emit no Resync events');

// Pre-2.0.4 consent retains Analytics but withholds Marketing until a new
// choice. Resync's boot cleanup and GTM event must follow those effective
// categories even though the banner is shown again.
const legacy = browser('resyncphysiotherapy.ie');
legacy.jar.set('hmdg_cookie_consent', encodeURIComponent(JSON.stringify({
  version: '1.0', policyVersion: '1', analytics: true, marketing: true,
})));
legacy.jar.set('_ga', 'GA1.1.123.456');
legacy.jar.set('_fbp', 'fb.1.123.456');
const effective = legacy.readConsent();
assert.equal(effective.analytics, true, 'legacy Analytics consent is retained');
assert.equal(effective.marketing, false, 'legacy Marketing consent is withheld');
legacy.bootConsent();
assert.equal(legacy.jar.has('_ga'), true, 'boot retains GA cookie for allowed legacy Analytics');
assert.equal(legacy.jar.has('_fbp'), false, 'boot removes Meta cookie while Marketing is withheld');
assert.equal(legacy.events().length, 1, 'boot emits only the retained Analytics grant');
assert.equal(legacy.events()[0].event, 'hmdg_resync_analytics_granted');

console.log('PASS Resync category cleanup, granted events and host isolation');
