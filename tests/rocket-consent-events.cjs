/** Consent controls must act on the first click and ignore Rocket's replay. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const php = fs.readFileSync(path.join(__dirname, '..', 'hmdg-cookie-consent.php'), 'utf8');
const match = php.match(/return \$banner \. <<<'JS'\r?\n([\s\S]*?)\r?\nJS;/);
assert.ok(match, 'shipped consent JavaScript exists');
assert.ok(!php.includes('pre_get_rocket_option_delay_js'), 'release keeps WP Rocket Delay JS enabled');

function makePage(rocketOn, reloadConsent = false) {
  const queued = [];
  const savedEvents = [];
  const userEvents = new Set(['click', 'keydown', 'keyup', 'input', 'touchstart', 'touchend']);
  let rocketReady = !rocketOn;
  let reloads = 0;
  let replayGuardTimer = null;
  const nextTask = [];
  const jar = new Map();
  const nodes = new Map();

  class FakeEvent {
    constructor(type, init = {}) {
      this.type = type;
      this.bubbles = init.bubbles !== false;
      this.target = init.target || null;
      this.isTrusted = init.isTrusted === true;
      this.key = init.key || '';
      this.shiftKey = !!init.shiftKey;
      this.detail = init.detail || 0;
      this.defaultPrevented = false;
    }
    preventDefault() { this.defaultPrevented = true; }
    stopPropagation() { this.propagationStopped = true; }
  }

  class FakeNode {
    constructor(id, parent = null, tagName = 'DIV') {
      this.id = id;
      this.parent = parent;
      this.tagName = tagName;
      this.listeners = {};
      this.attributes = new Map();
      this.style = {};
      this.checked = false;
      const classes = new Set();
      this.classList = {
        add(value) { classes.add(value); },
        remove(value) { classes.delete(value); },
        contains(value) { return classes.has(value); },
      };
      nodes.set(id, this);
    }
    addEventListener(type, fn, options) {
      if (rocketOn && !rocketReady && userEvents.has(type) && !(options && options.isRocket)) {
        queued.push({target: this, type, fn});
      } else {
        (this.listeners[type] ||= []).push(fn);
      }
    }
    dispatchEvent(event) {
      if (!event.target) event.target = this;
      event.currentTarget = this;
      for (const fn of this.listeners[event.type] || []) fn.call(this, event);
      if (event.bubbles && this.parent && !event.propagationStopped) this.parent.dispatchEvent(event);
      return !event.defaultPrevented;
    }
    getAttribute(name) { return this.attributes.get(name) ?? null; }
    setAttribute(name, value) { this.attributes.set(name, String(value)); }
    hasAttribute(name) { return this.attributes.has(name); }
    removeAttribute(name) { this.attributes.delete(name); }
    focus() { doc.activeElement = this; }
    blur() { if (doc.activeElement === this) doc.activeElement = null; }
    contains(node) { for (let n = node; n; n = n.parent) if (n === this) return true; return false; }
    querySelector(selector) {
      return selector === 'input[type="checkbox"]' ? this.input || null : null;
    }
    querySelectorAll() { return []; }
  }

  const win = new FakeNode('window');
  const doc = new FakeNode('document', win);
  doc.readyState = 'complete';
  doc.title = 'Consent test';
  doc.referrer = '';
  doc.body = new FakeNode('body', doc);
  doc.activeElement = null;
  Object.defineProperty(doc, 'cookie', {
    get() { return [...jar].map(([key, value]) => `${key}=${value}`).join('; '); },
    set(raw) {
      const first = raw.split(';', 1)[0];
      const i = first.indexOf('=');
      jar.set(first.slice(0, i), first.slice(i + 1));
    },
  });
  doc.getElementById = id => nodes.get(id) || null;
  doc.querySelectorAll = selector => {
    if (selector === '#hmdg-modal-overlay .hmdg-toggle') return labels;
    if (selector === '.hmdg-cat-toggle') return [category];
    return [];
  };
  doc.querySelector = selector => selector === '.hmdg-modal' ? modal : null;

  const banner = new FakeNode('hmdg-banner', doc);
  const accept = new FakeNode('hmdg-btn-accept', banner, 'BUTTON');
  const reject = new FakeNode('hmdg-btn-reject', banner, 'BUTTON');
  const customise = new FakeNode('hmdg-btn-customise', banner, 'BUTTON');
  const reopen = new FakeNode('hmdg-reopen', doc, 'BUTTON');
  const overlay = new FakeNode('hmdg-modal-overlay', doc);
  const modal = new FakeNode('modal', overlay);
  const close = new FakeNode('hmdg-modal-close', modal, 'BUTTON');
  const modalAccept = new FakeNode('hmdg-modal-accept', modal, 'BUTTON');
  const modalReject = new FakeNode('hmdg-modal-reject', modal, 'BUTTON');
  const save = new FakeNode('hmdg-modal-save', modal, 'BUTTON');
  const showMore = new FakeNode('hmdg-showmore-btn', modal, 'BUTTON');
  const moreContent = new FakeNode('hmdg-showmore', modal);
  moreContent.setAttribute('hidden', '');
  const category = new FakeNode('category', modal, 'BUTTON');
  category.setAttribute('aria-controls', 'hmdg-desc-analytics');
  category.setAttribute('aria-expanded', 'false');
  const categoryDescription = new FakeNode('hmdg-desc-analytics', modal);
  categoryDescription.setAttribute('hidden', '');
  const labels = [];
  const inputs = {};
  for (const name of ['functional', 'analytics', 'performance', 'marketing']) {
    const label = new FakeNode(`label-${name}`, modal, 'LABEL');
    const input = new FakeNode(`hmdg-chk-${name}`, label, 'INPUT');
    const span = new FakeNode(`span-${name}`, label, 'SPAN');
    label.input = input;
    label.span = span;
    labels.push(label);
    inputs[name] = input;
  }
  modal.querySelectorAll = () => [close, category, showMore, modalAccept, save, modalReject];

  const location = {
    protocol: 'https:', hostname: 'resyncphysiotherapy.ie', search: '',
    href: 'https://resyncphysiotherapy.ie/',
    reload() { reloads++; },
  };
  win.location = location;
  win.hmdgCCM = {policyVersion: '1-r2', sitePolicyVersion: '1', reloadConsent};
  win.dataLayer = [];
  win.addEventListener('click', event => {
    if (rocketOn && !rocketReady) {
      savedEvents.push({type: event.type, target: event.target, detail: event.detail});
      event.preventDefault();
      event.stopPropagation();
    }
  }, {isRocket: true});
  win.addEventListener('keydown', event => {
    if (rocketOn && !rocketReady) {
      savedEvents.push({type: event.type, target: event.target, key: event.key,
        shiftKey: event.shiftKey});
      event.stopPropagation();
    }
  }, {isRocket: true});

  const context = {
    window: win, document: doc, location, URL, URLSearchParams, Event: FakeEvent,
    console: {log() {}, warn() {}},
    setTimeout(fn, delay) {
      if (delay === 0) nextTask.push(fn); // after bubbling and default cancellation
      else if (delay === 1500) replayGuardTimer = fn;
      else fn();
    },
  };
  vm.createContext(context);
  vm.runInContext(match[1], context);

  async function click(target, isTrusted = true, detail = 1) {
    const old = target.tagName === 'INPUT' ? target.checked : null;
    if (old !== null) target.checked = !old; // native checkbox pre-activation
    const event = new FakeEvent('click', {target, isTrusted, detail});
    target.dispatchEvent(event);
    if (old !== null && event.defaultPrevented) target.checked = old;
    if (target.tagName === 'SPAN' && !event.defaultPrevented) {
      await click(target.parent.input, isTrusted, detail); // native label forwarding
    }
    for (const fn of nextTask.splice(0)) fn();
    await Promise.resolve();
    return event;
  }
  function key(target, name, shiftKey = false, isTrusted = true) {
    const event = new FakeEvent('keydown', {target, key: name, shiftKey, isTrusted});
    target.dispatchEvent(event);
    return event;
  }
  async function replay() {
    if (!rocketOn) return;
    win.dispatchEvent(new FakeEvent('rocket-allScriptsLoaded', {target: win, bubbles: false}));
    rocketReady = true;
    for (const item of queued) (item.target.listeners[item.type] ||= []).push(item.fn);
    const originals = savedEvents.splice(0);
    for (const item of originals) {
      if (item.type === 'click') await click(item.target, false, item.detail);
      else key(item.target, item.key, item.shiftKey, false);
    }
    if (replayGuardTimer) { replayGuardTimer(); replayGuardTimer = null; }
  }
  function consent() {
    const raw = jar.get('hmdg_cookie_consent');
    return raw ? JSON.parse(decodeURIComponent(raw)) : null;
  }
  function updates() {
    return win.dataLayer.filter(item => item[0] === 'consent' && item[1] === 'update');
  }
  function grantEvents(name) {
    return win.dataLayer.filter(item => item.event === name);
  }
  return {click, key, replay, consent, updates, grantEvents, savedEvents, queued,
    get reloads() { return reloads; },
    doc, banner, accept, reject, customise, reopen, overlay, close, modalAccept,
    modalReject, save, showMore, moreContent, category, categoryDescription,
    inputs, labels};
}

(async () => {
  {
    const p = makePage(true, true);
    assert.ok(p.banner.classList.contains('hmdg-open'));
    await p.click(p.accept);
    assert.equal(p.consent().marketing, true, 'first Accept saves immediately');
    assert.equal(p.updates().length, 1);
    assert.equal(p.grantEvents('hmdg_resync_analytics_granted').length, 1);
    assert.equal(p.grantEvents('hmdg_resync_marketing_granted').length, 1);
    assert.equal(p.reloads, 1, 'reload runs once');
    assert.equal(p.savedEvents.length, 1, 'Rocket still receives the first click');
    await p.replay();
    assert.equal(p.updates().length, 1, 'replayed Accept cannot duplicate consent');
    assert.equal(p.reloads, 1, 'replay cannot duplicate reload');
    await p.click(p.reopen);
    assert.ok(p.overlay.classList.contains('hmdg-open'), 'later preference change opens');
    await p.click(p.modalReject);
    assert.equal(p.consent().marketing, false, 'later intentional Reject works');
    assert.equal(p.updates().length, 2, 'later decision emits one new update');
  }
  {
    const p = makePage(true, true);
    await p.click(p.reject);
    assert.equal(p.consent().analytics, false, 'first Reject saves immediately');
    await p.replay();
    assert.equal(p.updates().length, 1);
    assert.equal(p.reloads, 1);
  }
  {
    const p = makePage(true);
    await p.click(p.customise);
    assert.ok(p.overlay.classList.contains('hmdg-open'), 'Customize opens immediately');
    await p.click(p.labels[1].span);
    assert.equal(p.inputs.analytics.checked, true, 'Rocket-cancelled label click retains choice');
    await p.click(p.inputs.performance);
    assert.equal(p.inputs.performance.checked, true, 'Rocket-cancelled direct input click retains choice');
    await p.click(p.category);
    await p.click(p.showMore);
    assert.equal(p.category.getAttribute('aria-expanded'), 'true');
    assert.equal(p.showMore.getAttribute('aria-expanded'), 'true');
    await p.click(p.save);
    assert.equal(p.consent().analytics, true, 'rapid Save reads Analytics choice');
    assert.equal(p.consent().performance, true, 'rapid Save reads direct input choice');
    assert.equal(p.consent().marketing, false);
    assert.equal(p.updates().length, 1);
    await p.replay();
    assert.equal(p.updates().length, 1, 'modal replay cannot duplicate Save');
    assert.equal(p.inputs.analytics.checked, true, 'checkbox replay does not reverse choice');
    assert.equal(p.inputs.performance.checked, true, 'direct input replay does not reverse choice');
    assert.equal(p.category.getAttribute('aria-expanded'), 'true', 'category replay ignored');
    assert.equal(p.showMore.getAttribute('aria-expanded'), 'true', 'Show more replay ignored');
  }
  {
    const p = makePage(true);
    await p.click(p.customise);
    p.doc.activeElement = p.modalReject;
    const tab = p.key(p.modalReject, 'Tab');
    assert.equal(tab.defaultPrevented, true, 'Tab wraps at the modal end');
    assert.equal(p.doc.activeElement, p.close, 'Tab focuses the first modal control');
    const shiftTab = p.key(p.close, 'Tab', true);
    assert.equal(shiftTab.defaultPrevented, true, 'Shift-Tab wraps at the modal start');
    assert.equal(p.doc.activeElement, p.modalReject, 'Shift-Tab focuses the last modal control');
    await p.click(p.close);
    assert.ok(!p.overlay.classList.contains('hmdg-open'));
    await p.replay();
    assert.ok(!p.overlay.classList.contains('hmdg-open'), 'rapid close stays closed after replay');
    await p.click(p.customise);
    assert.ok(p.overlay.classList.contains('hmdg-open'), 'later trusted Customize still opens');
    p.key(p.doc, 'Escape');
    assert.ok(!p.overlay.classList.contains('hmdg-open'), 'Escape closes immediately');
  }
  {
    const p = makePage(true);
    p.key(p.accept, 'Enter');
    await p.click(p.accept, true, 0);
    assert.equal(p.updates().length, 1, 'Enter activation uses one native click');
    await p.replay();
    assert.equal(p.updates().length, 1);
  }
  {
    const p = makePage(true);
    p.key(p.reject, ' ');
    await p.click(p.reject, true, 0);
    assert.equal(p.updates().length, 1, 'Space activation uses one native click');
    await p.replay();
    assert.equal(p.updates().length, 1);
  }
  {
    const p = makePage(true);
    await p.click(p.accept, false, 1); // Rocket's iOS touch helper synthesises a click.
    assert.equal(p.updates().length, 1, 'synthetic first touch click acts immediately');
    await p.replay();
    assert.equal(p.updates().length, 1, 'synthetic touch replay is ignored');
  }
  {
    const p = makePage(true);
    await p.click(p.customise);
    await p.click(p.modalAccept);
    assert.equal(p.consent().marketing, true, 'modal Accept acts immediately');
    await p.replay();
    assert.equal(p.updates().length, 1, 'modal Accept replay cannot duplicate');
  }
  {
    const p = makePage(false);
    await p.click(p.customise);
    await p.click(p.labels[1].span);
    assert.equal(p.inputs.analytics.checked, true, 'normal browser keeps native label activation');
    await p.click(p.save);
    assert.equal(p.consent().analytics, true);
    assert.equal(p.updates().length, 1);
  }
  console.log('PASS Rocket consent events: first click, rapid modal choice, replay, keyboard, normal browser');
})().catch(error => { console.error(error); process.exitCode = 1; });
