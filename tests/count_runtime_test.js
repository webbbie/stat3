#!/usr/bin/env node
'use strict';

// Run the complete, unmodified collector in isolated browser-like VM contexts.
// Every transport and external script is intercepted; this suite never uses a server.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { execFileSync } = require('node:child_process');

const sourcePath = process.env.COUNT_JS_SOURCE || path.join(__dirname, '..', 'count.js');
const source = fs.readFileSync(sourcePath, 'utf8');
const publicConfiguration = JSON.parse(execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, '..', 'configurator2.php')], { encoding: 'utf8' }));
const EPOCH = 1809900000000;
const TOKEN = 'a'.repeat(64);
const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const SAFARI = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1';
let sequence = 0;

function storage(initial = {}) {
  const values = new Map(Object.entries(initial));
  return {
    getItem: key => values.has(key) ? values.get(key) : null,
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: key => values.delete(key),
    values
  };
}

function eventTarget(target = {}) {
  const listeners = new Map();
  target.addEventListener = (type, listener, options = {}) => {
    const entries = listeners.get(type) || [];
    if (!entries.some(entry => entry.listener === listener)) entries.push({ listener, once: !!options.once });
    listeners.set(type, entries);
  };
  target.removeEventListener = (type, listener) => {
    listeners.set(type, (listeners.get(type) || []).filter(entry => entry.listener !== listener));
  };
  target.dispatchEvent = event => {
    for (const entry of [...(listeners.get(event.type) || [])]) {
      if (entry.once) target.removeEventListener(event.type, entry.listener);
      entry.listener.call(target, event);
    }
    return true;
  };
  return target;
}

function browser(options = {}) {
  let elapsed = 0;
  let wallOffset = 0;
  let timerId = 0;
  const timers = new Map();
  const requests = [];
  const errors = [];
  const scripts = [];
  const logs = [];
  const classes = new Set();
  const startTime = options.now ?? EPOCH;
  const local = options.localStorage || storage();
  const session = options.sessionStorage || storage();
  const root = {
    scrollHeight: 2400, offsetHeight: 2400, clientHeight: 800, clientWidth: 1280, scrollTop: 0,
    getAttribute: name => name === 'data-country' ? (options.country || null) : null,
    classList: { contains: name => classes.has(name), add: name => classes.add(name), remove: name => classes.delete(name) }
  };
  const script = {
    src: options.scriptSrc || 'https://www.bayerchristian.de/stats3/count.js',
    nonce: options.nonce || '',
    dataset: options.scriptDataset || {},
    getAttribute(name) { return name === 'src' ? this.src : null; }
  };
  const document = eventTarget({
    readyState: options.readyState || 'complete', hidden: false, visibilityState: 'visible',
    documentElement: root, body: options.bodyMissing ? null : { scrollHeight: 2400, offsetHeight: 2400 },
    currentScript: script, title: 'Collector runtime test', referrer: 'https://example.net/referrer',
    hasFocus: () => options.focus !== false,
    images: options.images || [],
    getElementById: id => id === 'swipe-gate' && options.existingGateElement ? {} : null,
    createElement: tag => ({ tagName: tag.toUpperCase() }),
    head: { appendChild: child => { scripts.push(child); return child; } }
  });
  const location = new URL(options.url || 'https://www.bayerchristian.de/article.html?utm_source=test&utm_campaign=runtime#section');
  class FakeDate extends Date {
    constructor(...args) { super(...(args.length ? args : [startTime + elapsed + wallOffset])); }
    static now() { return startTime + elapsed + wallOffset; }
  }
  class FakeBlob {
    constructor(parts, settings) { this.body = parts.join(''); this.type = settings?.type || ''; }
    async text() { return this.body; }
  }
  const schedule = (callback, delay, repeat, args) => {
    const id = ++timerId;
    timers.set(id, { callback, time: elapsed + Math.max(0, Number(delay) || 0), repeat, args });
    return id;
  };
  const parseRequest = (transport, url, init = {}) => {
    const body = init.body instanceof FakeBlob ? init.body.body : init.body;
    const record = { transport, url: String(url), init, at: elapsed, data: body ? JSON.parse(body) : null };
    requests.push(record);
    return record;
  };
  const navigator = { userAgent: options.ua ?? CHROME, language: options.language ?? 'de-DE', webdriver: !!options.webdriver };
  if (options.beacon !== 'missing') navigator.sendBeacon = (url, blob) => {
    const record = parseRequest('beacon', url, { body: blob });
    if (options.beacon === 'throw') { record.accepted = false; throw new Error('sendBeacon blocked'); }
    record.accepted = options.beacon !== false;
    return record.accepted;
  };
  const window = eventTarget({
    document, navigator, location, Date: FakeDate, Blob: options.blobMissing ? undefined : FakeBlob,
    URL, URLSearchParams, Intl, AbortController, Promise, Math, JSON,
    screen: options.screen || { width: 1920, height: 1080 }, devicePixelRatio: options.pixelRatio ?? 1,
    innerWidth: 1280, innerHeight: 800, scrollY: 0,
    crypto: options.cryptoMissing ? undefined : { randomUUID: () => `00000000-0000-4000-8000-${String(++sequence).padStart(12, '0')}` },
    performance: { now: () => elapsed, getEntriesByType: () => [{ type: options.navigation || 'navigate' }] },
    console: Object.fromEntries(['log', 'warn', 'error', 'info', 'debug'].map(level => [level, (...args) => logs.push({ level, args })])),
    setTimeout: (callback, delay, ...args) => schedule(callback, delay, 0, args),
    clearTimeout: id => timers.delete(id),
    setInterval: (callback, delay, ...args) => schedule(callback, delay, Math.max(1, Number(delay) || 0), args),
    clearInterval: id => timers.delete(id)
  });
  window.window = window;
  window.self = window;
  if (options.crossOriginFrame) Object.defineProperty(window, 'top', { get() { throw new Error('SecurityError'); } });
  else window.top = options.iframe ? {} : window;
  for (const [name, value] of [['localStorage', local], ['sessionStorage', session]]) {
    if (options.storageBlocked) Object.defineProperty(window, name, { get() { throw new Error('SecurityError'); } });
    else window[name] = value;
  }
  if (options.swipeGate) window.SwipeGate = options.swipeGate;
  if (options.swipeOptions) window.SwipeGateOptions = options.swipeOptions;
  if (options.frozenConsole) Object.freeze(window.console);
  if (options.fetch !== 'missing') window.fetch = (url, init) => {
    const record = parseRequest('fetch', url, init);
    if (init?.method === 'GET') {
      const response = options.configRespond?.(record);
      if (response !== undefined) return response;
      return Promise.resolve({ ok: true, status: 200, json: async () => JSON.parse(JSON.stringify(options.config || publicConfiguration)) });
    }
    if (options.fetch === 'throw') throw new Error('fetch blocked');
    if (options.fetch === 'reject') return Promise.reject(new Error('offline'));
    if (options.fetch === 'pending') return new Promise(() => {});
    const result = options.respond?.(record);
    if (result !== undefined) return result;
    return Promise.resolve({ ok: true, status: 200, json: async () => ({ ok: true, required: false }) });
  };
  const context = vm.createContext(window);
  const settle = async () => { for (let i = 0; i < 20; i += 1) await Promise.resolve(); };
  const evaluate = () => {
    document.currentScript = script;
    try { vm.runInContext(source, context, { filename: sourcePath, timeout: 1000 }); }
    catch (error) { errors.push(error); }
    document.currentScript = null;
  };
  const advance = async duration => {
    const target = elapsed + duration;
    await settle();
    let count = 0;
    while (true) {
      const next = [...timers].filter(([, timer]) => timer.time <= target).sort((a, b) => a[1].time - b[1].time || a[0] - b[0])[0];
      if (!next) break;
      assert.ok(++count < 20000, 'timer runaway');
      const [id, timer] = next;
      elapsed = timer.time;
      if (timer.repeat) timer.time += timer.repeat;
      else timers.delete(id);
      try { timer.callback(...timer.args); } catch (error) { errors.push(error); }
      await settle();
    }
    elapsed = target;
    await settle();
  };
  const dispatch = async (target, type, data = {}) => {
    try { target.dispatchEvent({ type, ...data }); } catch (error) { errors.push(error); }
    await settle();
  };
  const stats3 = reason => requests.filter(request => /\/pixl_collect\.php$/.test(request.url) && (!reason || request.data.reason === reason));
  const stat4 = type => requests.filter(request => /\/stat4\/collect\.php$/.test(request.url) && (!type || request.data.type === type));
  const captcha = action => requests.filter(request => /\/captcha\.php$/.test(request.url) && (!action || request.data.action === action));
  const noErrors = () => assert.deepEqual(errors.map(error => error.stack), [], 'uncaught browser runtime exceptions');
  evaluate();
  return { window, document, local, session, requests, errors, scripts, logs, timers, classes, evaluate, advance, settle,
    configuration: () => requests.filter(request => request.init.method === 'GET'),
    stats3, stat4, captcha, noErrors,
    jumpWallClock: duration => { wallOffset += duration; },
    event: (type, data) => dispatch(window, type, data),
    documentEvent: (type, data) => dispatch(document, type, data),
    async ready() { document.readyState = 'interactive'; await dispatch(document, 'DOMContentLoaded'); await dispatch(window, 'DOMContentLoaded'); await advance(0); },
    async visibility(hidden) { document.hidden = hidden; document.visibilityState = hidden ? 'hidden' : 'visible'; await dispatch(document, 'visibilitychange'); },
    async activity(count = 5) { for (let i = 0; i < count; i += 1) { await advance(70); await dispatch(window, 'keydown', { key: 'ArrowDown' }); } },
    async loadCaptcha() { assert.equal(scripts.length, 1); scripts[0].onload(); await settle(); }
  };
}

const cases = [];
const test = (name, run) => cases.push({ name, run });
const captchaResponse = data => Promise.resolve({ ok: true, status: 200, json: async () => data });
const requireCaptcha = () => captchaResponse({ ok: true, required: true, token: TOKEN, failed_attempts: 0 });

test('normal start sends one Stats3 VISIT, STAT4 pageview and CAPTCHA check', async () => {
  const b = browser();
  await b.advance(600);
  b.noErrors();
  assert.equal(b.stats3('VISIT').length, 1);
  assert.equal(b.stat4('pageview').length, 1);
  assert.equal(b.captcha('check').length, 1);
  const visit = b.stats3('VISIT')[0].data;
  assert.equal(visit.page.url, 'https://www.bayerchristian.de/article.html?utm_source=test&utm_campaign=runtime');
  assert.equal(visit.context.browser, 'chrome');
  assert.equal(visit.context.os, 'macos');
  assert.equal(visit.session.reused, false);
  assert.equal(visit.engagement.sessionDuration, 1);
  assert.equal(b.stat4('pageview')[0].data.utm.source, 'test');
  assert.equal(b.window.PIXL77, b.window.PIXL6);
});

test('duplicate script inclusion does not duplicate either tracker or CAPTCHA', async () => {
  const b = browser(); b.evaluate(); await b.advance(600); b.noErrors();
  assert.equal(b.stats3('VISIT').length, 1);
  assert.equal(b.stat4('pageview').length, 1);
  assert.equal(b.captcha('check').length, 1);
});

test('head script waits for DOMContentLoaded and tolerates missing body', async () => {
  const b = browser({ readyState: 'loading', bodyMissing: true });
  await b.advance(1000);
  assert.equal(b.stats3().length, 0);
  await b.ready(); await b.advance(600); b.noErrors();
  assert.equal(b.stats3('VISIT').length, 1);
  assert.equal(b.stat4('pageview').length, 1);
});

test('head script does not report full scroll depth before page content exists', async () => {
  const b = browser({ readyState: 'loading', bodyMissing: true });
  b.document.documentElement.scrollHeight = 800;
  b.document.documentElement.offsetHeight = 800;
  await b.advance(0);
  assert.equal(b.stat4('pageview').length, 1, 'initial pageview is sent immediately');
  assert.equal(b.stat4('pageview')[0].data.level, 0, 'unbuilt document is not a fully scrolled page');
  b.document.body = { scrollHeight: 2400, offsetHeight: 2400 };
  b.document.documentElement.scrollHeight = 2400;
  b.document.documentElement.offsetHeight = 2400;
  await b.ready(); b.noErrors();
  assert.equal(b.stat4('pageview')[0].data.level, 0);
});

test('blocked storage getters retain usable identities without runtime errors', async () => {
  const b = browser({ storageBlocked: true }); await b.advance(600); await b.event('pagehide'); b.noErrors();
  assert.ok(b.stats3('VISIT')[0].data.eventId);
  assert.equal(b.stat4('pageview')[0].data.visitorId, b.stat4('leave')[0].data.visitorId);
  assert.equal(b.stat4('pageview')[0].data.sessionId, b.stat4('leave')[0].data.sessionId);
});

test('storage quota failure and malformed session JSON do not stop collectors', async () => {
  const broken = { getItem: () => '{bad json', setItem() { throw new Error('QuotaExceededError'); }, removeItem() {} };
  const b = browser({ localStorage: broken, sessionStorage: storage({ __pixl77UserSessionV1: '{bad' }) });
  await b.advance(600); b.noErrors(); assert.equal(b.stats3('VISIT').length, 1); assert.equal(b.stat4('pageview').length, 1);
});

test('UUID fallback works when browser crypto is unavailable', async () => {
  const b = browser({ cryptoMissing: true }); await b.advance(600); b.noErrors();
  assert.ok(b.stats3('VISIT')[0].data.eventId); assert.match(b.stat4('pageview')[0].data.visitorId, /^[a-f0-9-]{36}$/);
});

test('same-page navigation within ten minutes reuses the Stats3 database event', async () => {
  const first = browser(); await first.advance(600);
  const next = browser({ localStorage: first.local, sessionStorage: first.session, now: EPOCH + 60000 }); await next.advance(600);
  assert.equal(first.stats3()[0].data.eventId, next.stats3()[0].data.eventId);
  assert.equal(next.stats3()[0].data.session.reused, true);
  assert.equal(first.stat4()[0].data.visitorId, next.stat4()[0].data.visitorId);
});

test('reload reuses page event beyond ten minutes while navigation recounts', async () => {
  const first = browser(); await first.advance(600);
  const reload = browser({ localStorage: first.local, sessionStorage: first.session, now: EPOCH + 11 * 60000, navigation: 'reload' });
  await reload.advance(600);
  assert.equal(first.stats3()[0].data.eventId, reload.stats3()[0].data.eventId);
  assert.equal(reload.stats3()[0].data.session.reload, true);
  const next = browser({ localStorage: first.local, sessionStorage: first.session, now: EPOCH + 12 * 60000 }); await next.advance(600);
  assert.notEqual(first.stats3()[0].data.eventId, next.stats3()[0].data.eventId);
  assert.equal(first.stats3()[0].data.session.id, next.stats3()[0].data.session.id);
});

test('new path creates a page event and thirty-minute inactivity creates a session', async () => {
  const first = browser(); await first.advance(600);
  const next = browser({ localStorage: first.local, sessionStorage: first.session, now: EPOCH + 31 * 60000, url: 'https://www.bayerchristian.de/other.html' });
  await next.advance(600);
  assert.notEqual(first.stats3()[0].data.eventId, next.stats3()[0].data.eventId);
  assert.notEqual(first.stats3()[0].data.session.id, next.stats3()[0].data.session.id);
});

test('STAT4 visitor expires after 24 hours and renews the tab session', async () => {
  const first = browser(); await first.advance(0);
  const next = browser({ localStorage: first.local, sessionStorage: first.session, now: EPOCH + 86400000 }); await next.advance(0);
  assert.notEqual(first.stat4()[0].data.visitorId, next.stat4()[0].data.visitorId);
  assert.notEqual(first.stat4()[0].data.sessionId, next.stat4()[0].data.sessionId);
});

test('STAT4 tab session renews when another tab rotates the shared visitor', async () => {
  const first = browser(); await first.advance(0);
  const oldSession = first.stat4('pageview')[0].data.sessionId;
  const next = browser({ localStorage: first.local, now: EPOCH + 86400000 }); await next.advance(0);
  first.jumpWallClock(86400000); await first.event('pagehide');
  assert.equal(first.stat4('leave')[0].data.visitorId, next.stat4('pageview')[0].data.visitorId);
  assert.notEqual(first.stat4('leave')[0].data.sessionId, oldSession, 'session belongs to the renewed visitor');
});

test('Stats3 emits READ only after interaction and emits LEAVE once', async () => {
  const b = browser(); await b.advance(15000); assert.equal(b.stats3('READ').length, 0);
  await b.activity(); await b.advance(5000);
  assert.equal(b.stats3('READ').length, 1);
  await b.event('beforeunload'); await b.event('pagehide'); await b.advance(30000); b.noErrors();
  assert.equal(b.stats3('LEAVE').length, 1); assert.equal(b.stats3().length, 3);
  assert.equal(b.stats3('LEAVE')[0].data.events.trail.join(','), 'VISIT,READ,LEAVE');
});

test('CAPTCHA gestures do not inflate Stats3 reading samples', async () => {
  const b = browser(); await b.advance(0); b.classes.add('swipe-gate-locked');
  await b.activity(10); await b.advance(15000); await b.event('pagehide');
  assert.equal(b.stats3('READ').length, 0); assert.equal(b.stats3('LEAVE')[0].data.engagement.readingSamples.length, 0);
});

test('early pagehide cancels the delayed Stats3 VISIT', async () => {
  const b = browser(); await b.advance(100); await b.event('pagehide'); await b.advance(1000); b.noErrors();
  assert.equal(b.stats3('LEAVE').length, 1);
  assert.equal(b.stats3('VISIT').length, 0, 'VISIT must not arrive after LEAVE for an already hidden page');
});

test('cancelled navigation after beforeunload leaves reading checkpoints operational', async () => {
  const b = browser(); await b.advance(600); await b.event('beforeunload');
  await b.activity(); await b.advance(15000); b.noErrors();
  assert.equal(b.stats3('READ').length, 1, 'beforeunload alone must not stop a still-active page');
});

test('BFCache restore resumes STAT4 heartbeat and allows another leave', async () => {
  const b = browser(); await b.advance(1000); await b.event('pagehide', { persisted: true });
  await b.advance(30000); const before = b.stat4('heartbeat').length;
  await b.event('pageshow', { persisted: true }); await b.advance(30000);
  assert.ok(b.stat4('heartbeat').length > before, 'restored STAT4 collector must resume heartbeats');
  await b.event('pagehide', { persisted: false }); assert.equal(b.stat4('leave').length, 2);
});

test('BFCache restore resumes Stats3 read checkpoint before any READ was sent', async () => {
  const b = browser(); await b.advance(1000); await b.event('pagehide', { persisted: true });
  await b.event('pageshow', { persisted: true }); await b.activity(); await b.advance(20000); b.noErrors();
  assert.equal(b.stats3('READ').length, 1, 'restored Stats3 collector must evaluate reading activity');
});

test('STAT4 hidden intervals contribute no active time and click target is bounded', async () => {
  const b = browser(); await b.advance(5000); await b.visibility(true); const hidden = b.stat4('heartbeat').at(-1);
  assert.equal(hidden.data.activeSeconds, 5);
  await b.advance(60000); await b.visibility(false); await b.advance(5000);
  const target = { nodeType: 1, closest: () => ({ href: 'https://example.org/' + 'x'.repeat(3000) }) };
  await b.documentEvent('click', { target });
  assert.equal(b.stat4('click').at(-1).data.target.length, 2048); b.noErrors();
  await b.event('pagehide'); assert.equal(b.stat4('leave')[0].data.activeSeconds, 5);
});

test('STAT4 accounts for visible time before a visibility change between ticks', async () => {
  const b = browser(); await b.advance(4900); await b.visibility(true); b.noErrors();
  const seconds = b.stat4('heartbeat').at(-1).data.activeSeconds;
  assert.ok(seconds >= 4 && seconds <= 5, `expected roughly 4.9 visible seconds, received ${seconds}`);
});

test('STAT4 link clicks use a navigation-safe transport', async () => {
  const b = browser(); await b.advance(0);
  await b.documentEvent('click', { target: { nodeType: 1, closest: () => ({ href: 'https://example.org/next' }) } });
  const click = b.stat4('click').at(-1);
  assert.ok((click.transport === 'beacon' && click.accepted) || click.init.keepalive, 'click must survive immediate link navigation');
});

test('STAT4 scroll level stays within zero to one hundred', async () => {
  const b = browser(); b.window.scrollY = 99999; await b.event('scroll'); await b.event('pagehide');
  assert.equal(b.stat4('leave')[0].data.level, 100);
});

test('STAT4 custom endpoint resolves against the script URL', async () => {
  const b = browser({ scriptDataset: { stat4Endpoint: './stat4/custom.php' } }); await b.advance(0); b.noErrors();
  assert.ok(b.requests.some(request => request.url === 'https://www.bayerchristian.de/stats3/stat4/custom.php' && request.data.type === 'pageview'));
});

for (const beacon of [false, 'missing']) test(`transport falls back to fetch when sendBeacon is ${beacon}`, async () => {
  const b = browser({ beacon }); await b.advance(600); b.noErrors();
  assert.equal(b.stats3('VISIT').filter(request => request.transport === 'fetch').length, 1);
  assert.equal(b.stat4('pageview').filter(request => request.transport === 'fetch').length, 1);
});

test('Stats3 falls back to fetch when sendBeacon throws', async () => {
  const b = browser({ beacon: 'throw' }); await b.advance(600); b.noErrors();
  assert.equal(b.stats3('VISIT').filter(request => request.transport === 'fetch').length, 1);
});

for (const fetch of ['reject', 'throw', 'missing', 'pending']) test(`failed or unavailable transport remains contained (${fetch})`, async () => {
  const b = browser({ beacon: false, fetch }); await b.advance(20000); await b.event('pagehide'); b.noErrors();
  assert.equal(b.scripts.length, 0);
  assert.ok(b.logs.some(log => log.level === (fetch === 'missing' ? 'warn' : 'error')), 'configuration/transport failure should be logged');
});

test('missing Blob falls back to fetch for both trackers', async () => {
  const b = browser({ blobMissing: true }); await b.advance(600); b.noErrors();
  assert.equal(b.stats3()[0].transport, 'fetch'); assert.equal(b.stat4()[0].transport, 'fetch');
});

for (const frame of [{ iframe: true }, { crossOriginFrame: true }]) test(`Stats3 and CAPTCHA skip ${frame.iframe ? 'iframe' : 'inaccessible top window'}`, async () => {
  const b = browser(frame); await b.advance(20000); b.noErrors();
  assert.equal(b.stats3().length, 0); assert.equal(b.captcha().length, 0);
});

test('Stats3 and CAPTCHA reject lookalike unapproved hostnames', async () => {
  const b = browser({ url: 'https://bayerchristian.de.evil.example/page' }); await b.advance(20000); b.noErrors();
  assert.equal(b.stats3().length, 0); assert.equal(b.captcha().length, 0);
});

for (const options of [{ ua: 'Googlebot/2.1 (+http://www.google.com/bot.html)' }, { webdriver: true }, { ua: '' }]) test(`bot or unusual client remains a valid analytics payload (${JSON.stringify(options)})`, async () => {
  const b = browser(options); await b.advance(600); b.noErrors();
  assert.equal(b.stats3('VISIT').length, 1); assert.equal(b.stats3()[0].data.context.browser, options.ua === '' ? 'Unknown' : 'Unknown');
  if (options.webdriver) assert.equal(b.stats3()[0].data.flags.webdriver, true);
});

test('Safari fingerprint recognizes the real Version/Safari UA ordering', async () => {
  const b = browser({ ua: SAFARI }); await b.advance(600);
  assert.equal(b.window.PIXL77.fingerprint().browser, 'safari');
  assert.equal(b.stats3()[0].data.context.browser, 'safari');
});

test('Android tablet without Mobile marker is classified as a tablet', async () => {
  const b = browser({ ua: 'Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36' });
  await b.advance(600); b.noErrors(); assert.equal(b.window.PIXL77.fingerprint().device, 'Tablet');
});

for (const data of [{ ok: true, required: false }, { ok: true, required: true, token: 'invalid' }, { ok: false }, null]) test(`CAPTCHA does not load an invalid or unnecessary challenge (${JSON.stringify(data)})`, async () => {
  const b = browser({ respond: request => request.data?.action === 'check' ? captchaResponse(data) : undefined });
  await b.advance(600); b.noErrors(); assert.equal(b.scripts.length, 0); assert.equal(b.stats3('VISIT').length, 1);
});

test('CAPTCHA HTTP and JSON failures leave analytics operational', async () => {
  for (const response of [{ ok: false, status: 503 }, { ok: true, json: async () => { throw new Error('Invalid JSON'); } }]) {
    const b = browser({ respond: request => request.data?.action === 'check' ? Promise.resolve(response) : undefined });
    await b.advance(600); b.noErrors(); assert.equal(b.scripts.length, 0); assert.equal(b.stats3('VISIT').length, 1);
    assert.ok(b.logs.some(log => log.level === 'info' && log.args[0] === 'Captcha status unavailable — Serveranfrage fehlgeschlagen.'));
  }
});

for (const [data, expected] of [
  [{ ok: true, active: true, required: false }, 'Captcha active — Prüfung für diesen Besucher erforderlich: nein.'],
  [{ ok: true, active: true, required: true, token: TOKEN }, 'Captcha active — Prüfung für diesen Besucher erforderlich: ja.'],
  [{ ok: true, active: false, required: false }, 'Captcha inactive — Prüfung für diesen Besucher erforderlich: nein.'],
  [{ ok: true, required: false }, 'Captcha status unknown — Prüfung für diesen Besucher erforderlich: nein. Der Server liefert noch keinen globalen Status.'],
  [{ ok: true, required: true, token: TOKEN }, 'Captcha active — Prüfung für diesen Besucher erforderlich: ja.']
]) test(`CAPTCHA console distinguishes global state and visitor requirement (${JSON.stringify(data)})`, async () => {
  const b = browser({ respond: request => request.data?.action === 'check' ? captchaResponse(data) : undefined });
  await b.advance(600); b.noErrors();
  const messages = b.logs.filter(log => log.level === 'info' && String(log.args[0]).startsWith('Captcha '));
  assert.deepEqual(messages.map(log => log.args), [[expected]]);
  assert.ok(!JSON.stringify(messages).includes(TOKEN), 'console must not contain the challenge token');
  assert.equal(b.scripts.length, data.required ? 1 : 0);
});

test('CAPTCHA console reports state changes without repeating polling messages', async () => {
  let active = true;
  let dismissed = 0;
  const b = browser({ respond: request => ['check', 'status'].includes(request.data?.action)
    ? captchaResponse({ ok: true, active, required: active, token: TOKEN }) : undefined });
  await b.advance(0);
  b.window.SwipeGate = { dismiss: () => dismissed++ };
  await b.loadCaptcha();
  await b.advance(30000);
  assert.equal(b.logs.filter(log => log.level === 'info' && String(log.args[0]).startsWith('Captcha ')).length, 1);
  active = false;
  await b.advance(15000); b.noErrors();
  assert.deepEqual(b.logs.filter(log => log.level === 'info' && String(log.args[0]).startsWith('Captcha ')).map(log => log.args[0]), [
    'Captcha active — Prüfung für diesen Besucher erforderlich: ja.',
    'Captcha inactive — Prüfung für diesen Besucher erforderlich: nein.'
  ]);
  assert.equal(dismissed, 1);
});

test('CAPTCHA check timeout aborts its fetch without interrupting analytics', async () => {
  const b = browser({ respond: request => request.data?.action === 'check' ? new Promise(() => {}) : undefined });
  await b.advance(4501); b.noErrors(); assert.equal(b.scripts.length, 0);
  assert.equal(b.captcha('check')[0].init.signal.aborted, true); assert.equal(b.stats3('VISIT').length, 1);
});

test('existing CAPTCHA is not initialized a second time', async () => {
  const b = browser({ existingGateElement: true }); await b.advance(600); b.noErrors();
  assert.equal(b.captcha().length, 0); assert.equal(b.scripts.length, 0); assert.equal(b.stats3('VISIT').length, 1);
});

test('CAPTCHA failure count is cumulative per page source and verified challenge stops polling', async () => {
  let failures = 0; let verified = 0;
  const b = browser({
    swipeOptions: { onFailure: () => failures++, onVerified: () => verified++ },
    respond: request => {
      if (request.data?.action === 'check') return captchaResponse({ ok: true, required: true, token: TOKEN, failed_attempts: 2 });
      if (request.data?.action === 'verify') return captchaResponse({ ok: true, verified: true });
      if (request.data?.action === 'status') return requireCaptcha();
    }
  });
  await b.advance(0); await b.loadCaptcha();
  assert.equal(b.window.SwipeGateOptions.rememberForMinutes, 0);
  b.window.SwipeGateOptions.onFailure(); await b.settle(); b.window.SwipeGateOptions.onFailure(); await b.settle();
  assert.equal(failures, 2); assert.deepEqual(b.captcha('fail').map(request => request.data.failedAttempts), [1, 2]);
  const attemptSource = b.captcha('fail')[0].data.attemptSource;
  assert.ok(typeof attemptSource === 'string' && attemptSource.length >= 16);
  assert.equal(b.captcha('fail')[1].data.attemptSource, attemptSource);
  assert.ok(b.captcha('fail').every(request => request.init.keepalive === true));
  assert.equal(await b.window.SwipeGateOptions.onConfirm(), true);
  assert.equal(b.captcha('verify')[0].data.failedAttempts, 2);
  assert.equal(b.captcha('verify')[0].data.attemptSource, attemptSource);
  b.window.SwipeGateOptions.onVerified(); await b.advance(60000); b.noErrors();
  assert.equal(verified, 1); assert.equal(b.captcha('status').length, 0);
});

test('CAPTCHA rejected confirmation remains rejected and pending confirmation pauses polling', async () => {
  let resolveVerify;
  const b = browser({ respond: request => {
    if (request.data?.action === 'check') return requireCaptcha();
    if (request.data?.action === 'verify') return new Promise(resolve => { resolveVerify = resolve; });
    if (request.data?.action === 'status') return requireCaptcha();
  } });
  await b.advance(0); await b.loadCaptcha(); await b.advance(13000);
  const confirming = b.window.SwipeGateOptions.onConfirm(); await b.advance(2001);
  assert.equal(b.captcha('status').length, 0);
  resolveVerify({ ok: true, json: async () => ({ ok: true, verified: false }) });
  assert.equal(await confirming, false); b.noErrors();
});

test('CAPTCHA script load error dismisses the challenge', async () => {
  let dismissed = 0;
  const b = browser({ respond: request => request.data?.action === 'check' ? requireCaptcha() : undefined });
  await b.advance(0); b.window.SwipeGate = { dismiss: () => dismissed++ };
  b.scripts[0].onerror(); await b.advance(30000); b.noErrors();
  assert.equal(dismissed, 1); assert.equal(b.captcha('status').length, 0);
  assert.ok(b.logs.some(log => log.level === 'info' && log.args[0] === 'Captcha active — CAPTCHA-Datei konnte nicht geladen werden.'));
});

test('CAPTCHA reservation removal or status network error dismisses the gate', async () => {
  for (const rejected of [false, true]) {
    let dismissed = 0;
    const b = browser({ respond: request => {
      if (request.data?.action === 'check') return requireCaptcha();
      if (request.data?.action === 'status') return rejected ? Promise.reject(new Error('offline')) : captchaResponse({ ok: true, required: false });
    } });
    await b.advance(0); b.window.SwipeGate = { dismiss: () => dismissed++ }; await b.loadCaptcha();
    await b.advance(15000); b.noErrors(); assert.equal(dismissed, 1);
    await b.advance(30000); assert.equal(b.captcha('status').length, 1);
  }
});

test('CAPTCHA response arriving after pagehide cannot inject a challenge', async () => {
  let resolveCheck;
  const b = browser({ respond: request => request.data?.action === 'check' ? new Promise(resolve => { resolveCheck = resolve; }) : undefined });
  await b.advance(0); await b.event('pagehide'); resolveCheck({ ok: true, json: async () => ({ ok: true, required: true, token: TOKEN }) });
  await b.settle(); b.noErrors(); assert.equal(b.scripts.length, 0);
});

test('BFCache restore resumes status checks for an open CAPTCHA', async () => {
  const b = browser({ respond: request => ['check', 'status'].includes(request.data?.action) ? requireCaptcha() : undefined });
  await b.advance(0); b.window.SwipeGate = { dismiss() {} }; await b.loadCaptcha();
  await b.event('pagehide', { persisted: true }); await b.event('pageshow', { persisted: true }); await b.advance(16000); b.noErrors();
  assert.ok(b.captcha('status').length > 0, 'open challenge must continue observing its reservation after BFCache restore');
});

test('remote configuration loads once from the central PHP endpoint without cookies or caching', async () => {
  const b = browser({ scriptSrc: 'https://www.inconsequential.org/files/src/count.js', url: 'https://www.inconsequential.org/files/gate.html' });
  b.evaluate();
  await b.advance(600); b.noErrors();
  assert.equal(b.configuration().length, 1);
  const request = b.configuration()[0];
  assert.equal(request.url, 'https://www.bayerchristian.de/stats3/configurator2.php');
  assert.equal(request.init.credentials, 'omit');
  assert.equal(request.init.mode, 'cors');
  assert.equal(request.init.cache, 'no-store');
  assert.equal(b.stats3('VISIT')[0].data.script.name, 'count.js');
  assert.equal(b.stat4('pageview').length, 1);
});

test('remote configuration controls both endpoints, Site ID, CAPTCHA and STAT4 timing', async () => {
  const config = JSON.parse(JSON.stringify(publicConfiguration));
  config.config.SQL_ENDPOINT = 'https://collector.example/central/pixl_collect.php';
  config.config.SQL_SITE_ID = 'remote-setting';
  config.config.STAT4.ENDPOINT = 'https://collector.example/central/stat4/collect.php';
  config.config.STAT4.HEARTBEAT_INTERVAL = 1000;
  config.config.STAT4.TICK_INTERVAL = 1000;
  const b = browser({ config }); await b.advance(1000); b.noErrors();
  assert.equal(b.stats3('VISIT')[0].url, config.config.SQL_ENDPOINT);
  assert.equal(b.stats3('VISIT')[0].data.siteId, 'remote-setting');
  assert.equal(b.captcha('check')[0].url, 'https://collector.example/central/captcha.php');
  assert.equal(b.stat4('pageview')[0].url, config.config.STAT4.ENDPOINT);
  assert.equal(b.stat4('heartbeat').length, 1);
});

test('remote configuration preserves script attributes across asynchronous startup', async () => {
  let resolveConfig;
  const b = browser({ scriptSrc: 'https://static.example/files/src/count.js',
    scriptDataset: { configUrl: './settings.php', stat4Endpoint: './custom.php' },
    configRespond: () => new Promise(resolve => { resolveConfig = resolve; }) });
  await b.advance(1000);
  assert.equal(b.stats3().length + b.stat4().length + b.captcha().length, 0);
  assert.equal(b.configuration()[0].url, 'https://static.example/files/src/settings.php');
  resolveConfig(captchaResponse(publicConfiguration));
  await b.advance(600); b.noErrors();
  assert.equal(b.stats3('VISIT')[0].data.script.name, 'count.js');
  assert.ok(b.requests.some(request => request.url === 'https://static.example/files/src/custom.php'));
});

test('remote configuration restores ACCEPTED_OS arrays to the existing Set contract', async () => {
  const config = JSON.parse(JSON.stringify(publicConfiguration));
  config.config.ACCEPTED_OS = ['WINDOWS'];
  const b = browser({ config }); await b.advance(600); b.noErrors();
  assert.equal(b.stats3().length, 0, 'macOS is excluded by the remotely configured OS list');
  assert.equal(b.stat4('pageview').length, 1);
});

for (const [name, change] of [
  ['missing body', () => null],
  ['schema version', data => { data.schemaVersion = 99; return data; }],
  ['missing SQL', data => { delete data.config.SQL; return data; }],
  ['string boolean', data => { data.config.SQL.NOTIFY_ON_VISIT = 'false'; return data; }],
  ['invalid bot patterns', data => { data.config.BOT_PATTERNS.search = 'bot'; return data; }],
  ['invalid resolutions', data => { data.config.KNOWN_RESOLUTIONS = {}; return data; }],
  ['invalid OS list', data => { data.config.ACCEPTED_OS = 'macos'; return data; }],
  ['invalid endpoint', data => { data.config.SQL_ENDPOINT = 'javascript:alert(1)'; return data; }],
  ['zero heartbeat', data => { data.config.STAT4.HEARTBEAT_INTERVAL = 0; return data; }]
]) test(`remote configuration rejects ${name} before starting either tracker`, async () => {
  const data = change(JSON.parse(JSON.stringify(publicConfiguration)));
  const b = browser({ configRespond: () => captchaResponse(data) });
  await b.advance(10000); b.noErrors();
  assert.equal(b.stats3().length + b.stat4().length + b.captcha().length, 0);
  assert.equal(b.scripts.length, 0);
  assert.equal(b.timers.size, 0);
  assert.ok(b.logs.some(log => log.level === 'warn'));
});

for (const [name, respond] of [
  ['HTTP error', () => Promise.resolve({ ok: false, status: 503 })],
  ['CORS or network rejection', () => Promise.reject(new Error('Failed to fetch'))],
  ['synchronous transport error', () => { throw new Error('fetch blocked'); }],
  ['invalid JSON', () => Promise.resolve({ ok: true, json: async () => { throw new SyntaxError('bad JSON'); } })]
]) test(`remote configuration contains ${name} without starting tracking`, async () => {
  const b = browser({ configRespond: respond }); await b.advance(10000); b.noErrors();
  assert.equal(b.stats3().length + b.stat4().length + b.captcha().length, 0);
  assert.equal(b.timers.size, 0);
  assert.ok(b.logs.some(log => log.level === 'warn'));
});

test('remote configuration timeout aborts the request and ignores a late answer', async () => {
  let resolveConfig;
  const b = browser({ configRespond: () => new Promise(resolve => { resolveConfig = resolve; }) });
  await b.advance(4501);
  assert.equal(b.configuration()[0].init.signal.aborted, true);
  resolveConfig(captchaResponse(publicConfiguration));
  await b.advance(1000); b.noErrors();
  assert.equal(b.stats3().length + b.stat4().length + b.captcha().length, 0);
  assert.equal(b.timers.size, 0);
});

test('remote configuration arriving after pagehide waits for BFCache restore', async () => {
  let resolveConfig;
  const b = browser({ configRespond: () => new Promise(resolve => { resolveConfig = resolve; }) });
  await b.event('pagehide', { persisted: true });
  resolveConfig(captchaResponse(publicConfiguration));
  await b.advance(1000);
  assert.equal(b.stats3().length + b.stat4().length + b.captcha().length, 0);
  await b.event('pageshow', { persisted: true });
  await b.advance(600); b.noErrors();
  assert.equal(b.stats3('VISIT').length, 1);
  assert.equal(b.stat4('pageview').length, 1);
  assert.equal(b.configuration().length, 1);
});

test('remote configuration can retry after failure when the script is explicitly included again', async () => {
  let attempts = 0;
  const b = browser({ configRespond: () => ++attempts === 1 ? Promise.reject(new Error('offline')) : captchaResponse(publicConfiguration) });
  await b.advance(0); b.evaluate(); await b.advance(600); b.noErrors();
  assert.equal(b.configuration().length, 2);
  assert.equal(b.stats3('VISIT').length, 1);
  assert.equal(b.stat4('pageview').length, 1);
});

function configured(values) {
  const data = JSON.parse(JSON.stringify(publicConfiguration));
  for (const [key, value] of Object.entries(values)) {
    const parts = key.split('.'); const field = parts.pop();
    parts.reduce((object, part) => object[part], data.config)[field] = value;
  }
  return data;
}

test('frozen console leaves both counters operational', async () => {
  const b = browser({ frozenConsole: true }); await b.advance(600); await b.event('pagehide'); b.noErrors();
  assert.equal(b.stats3('VISIT').length, 1); assert.equal(b.stats3('LEAVE').length, 1);
  assert.equal(b.stat4('pageview').length, 1);
});

test('Error objects retain their message and sanitize URLs', async () => {
  const b = browser(); await b.advance(600);
  b.window.console.error(new Error('SYNTHETIC_ERROR https://example.net/path?token=PRIVATE#SECRET'));
  await b.event('pagehide');
  const log = b.stats3('LEAVE')[0].data.health.consoleErrors[0].message;
  assert.match(log, /Error: SYNTHETIC_ERROR https:\/\/example.net\/path/);
  assert.ok(!/PRIVATE|SECRET/.test(log));
});

test('all tracking URLs omit fragments and preserve campaign parameters', async () => {
  const b = browser({ url: 'https://www.inconsequential.org/page?utm_source=test#access_token=SYNTHETIC_SECRET' });
  await b.advance(600); b.noErrors();
  for (const request of b.requests) assert.ok(!JSON.stringify(request.data).includes('SYNTHETIC_SECRET'));
  assert.match(b.stat4('pageview')[0].data.url, /utm_source=test$/);
});

test('zero visit and read delays run immediately', async () => {
  const b = browser({ config: configured({ 'SQL.VISIT_DELAY_MS': 0, 'SQL.READ_DELAY_MS': 0, 'DEBUG.FORCE_NOTIFY': true }) });
  await b.advance(0); b.noErrors(); assert.equal(b.stats3('VISIT').length, 1); assert.equal(b.stats3('READ').length, 1);
});

for (const field of ['SQL.READ_RECHECK_MS', 'SQL.FETCH_TIMEOUT_MS', 'READING_TRACKER.SAMPLE_LENGTH', 'READING_TRACKER.MAX_SAMPLES', 'USER_SESSION.TIMEOUT_MINUTES', 'RENDER_HEALTH.INTERVAL_MS']) {
  test(`zero recurring/sample window is rejected: ${field}`, async () => {
    const b = browser({ config: configured({ [field]: 0 }) }); await b.advance(20000); b.noErrors();
    assert.equal(b.stats3().length + b.stat4().length, 0); assert.ok(b.logs.some(log => log.level === 'warn'));
  });
}

test('final summary mode sends only the configured terminal reason', async () => {
  const b = browser({ config: configured({ 'SQL.FINAL_SUMMARY_ONLY': true, 'SQL.FINAL_SUMMARY_REASON': 'COMPLETE' }) });
  await b.advance(600); await b.activity(); await b.advance(15000); assert.equal(b.stats3().length, 0);
  await b.event('pagehide'); b.noErrors(); assert.equal(b.stats3().length, 1);
  const final = b.stats3()[0].data;
  assert.equal(final.reason, 'COMPLETE'); assert.equal(final.events.reached.LEAVE, true); assert.equal(final.events.reached.READ, true);
});

test('one automatic event limit is preserved through BFCache restores', async () => {
  const b = browser({ config: configured({ 'SQL.ONE_AUTO_NOTIFICATION_ONLY': true }) });
  await b.advance(600); await b.activity(); await b.advance(15000);
  await b.event('pagehide', { persisted: true }); await b.event('pageshow', { persisted: true }); await b.event('pagehide');
  b.noErrors(); assert.equal(b.stats3().length, 1);
});

test('fingerprint exclusion matches all configured fields; debug bypass is explicit', async () => {
  const fields = { 'FINGERPRINT_EXCLUDE.ENABLED': true, 'FINGERPRINT_EXCLUDE.BROWSER_IS': 'CHROME',
    'FINGERPRINT_EXCLUDE.OS_IS': 'macos', 'FINGERPRINT_EXCLUDE.DEVICE_IS': 'Desktop', 'FINGERPRINT_EXCLUDE.COUNTRY_IS': 'DE' };
  const b = browser({ config: configured(fields) }); await b.advance(600); b.noErrors(); assert.equal(b.stats3().length, 0);
  const other = browser({ config: configured({ ...fields, 'FINGERPRINT_EXCLUDE.COUNTRY_IS': 'FR' }) }); await other.advance(600); assert.equal(other.stats3().length, 1);
  const bypass = browser({ config: configured({ ...fields, 'DEBUG.BYPASS_FILTERS': true }) }); await bypass.advance(600); assert.equal(bypass.stats3().length, 1);
});

for (const country of ['DE', 'de', 'Germany', 'Deutschland', 'Germany (Deutschland)']) {
  test(`default fingerprint excludes German Macintosh 2560x1440: ${country}`, async () => {
    const b = browser({ country, language: 'en-US', screen: { width: 2560, height: 1440 } });
    await b.advance(16000); await b.event('pagehide'); b.noErrors();
    assert.equal(b.stats3().length, 0, 'excluded visitors send no Stats3 phase');
    assert.equal(b.stat4('pageview').length, 1, 'existing independent STAT4 behavior is preserved');
    assert.equal(b.captcha('check').length, 1, 'existing independent CAPTCHA behavior is preserved');
  });
}

for (const screen of [{ width: 390, height: 844 }, { width: 844, height: 390 }]) {
  test(`default fingerprint excludes the German iPhone profile in ${screen.width}x${screen.height}`, async () => {
    const b = browser({ ua: IPHONE, screen, pixelRatio: 3, country: 'DE' });
    await b.advance(600); await b.event('pagehide'); b.noErrors();
    assert.equal(b.stats3().length, 0);
  });
}

for (const [label, options] of [
  ['Macintosh in France', { country: 'FR', screen: { width: 2560, height: 1440 } }],
  ['Macintosh with no German country hint', { language: 'en-US', screen: { width: 2560, height: 1440 } }],
  ['Macintosh with another resolution', { screen: { width: 1920, height: 1080 } }],
  ['Windows at the same resolution', { ua: CHROME.replace('Macintosh; Intel Mac OS X 10_15_7', 'Windows NT 10.0; Win64; x64'), screen: { width: 2560, height: 1440 } }],
  ['iPhone in Austria', { ua: IPHONE, country: 'AT', screen: { width: 390, height: 844 }, pixelRatio: 3 }],
  ['iPhone with another resolution', { ua: IPHONE, screen: { width: 393, height: 852 }, pixelRatio: 3 }],
  ['iPhone with another pixel ratio', { ua: IPHONE, screen: { width: 390, height: 844 }, pixelRatio: 2 }],
  ['iPad with the same screen values', { ua: IPHONE.replaceAll('iPhone', 'iPad'), screen: { width: 390, height: 844 }, pixelRatio: 3 }],
  ['Android with the same screen values', { ua: 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36', screen: { width: 390, height: 844 }, pixelRatio: 3 }]
]) {
  test(`default fingerprint keeps ${label}`, async () => {
    const b = browser(options); await b.advance(600); b.noErrors();
    assert.equal(b.stats3('VISIT').length, 1);
  });
}

test('fingerprint master switch and explicit debug bypass disable alternative exclusions', async () => {
  for (const fields of [{ 'FINGERPRINT_EXCLUDE.ENABLED': false }, { 'DEBUG.BYPASS_FILTERS': true }]) {
    const b = browser({ screen: { width: 2560, height: 1440 }, config: configured(fields) });
    await b.advance(600); b.noErrors(); assert.equal(b.stats3('VISIT').length, 1);
  }
  const b = browser({ screen: { width: 2560, height: 1440 }, config: configured({ 'DEBUG.ENABLED': false, 'DEBUG.BYPASS_FILTERS': true }) });
  await b.advance(600); b.noErrors(); assert.equal(b.stats3().length, 0, 'bypass requires debug mode');
});

test('empty fingerprint alternatives never exclude everyone; legacy configuration remains supported', async () => {
  for (const rules of [undefined, [], [{}, { COUNTRY_IS: ' ' }, { SCREEN_IS: [] }]]) {
    const b = browser({ config: configured({ 'FINGERPRINT_EXCLUDE.RULES': rules }) });
    await b.advance(600); b.noErrors(); assert.equal(b.stats3('VISIT').length, 1);
  }
  const b = browser({ config: configured({ 'FINGERPRINT_EXCLUDE.RULES': undefined, 'FINGERPRINT_EXCLUDE.COUNTRY_IS': 'DE' }) });
  await b.advance(600); b.noErrors(); assert.equal(b.stats3().length, 0);
});

for (const [label, rules] of [
  ['not an array', {}], ['null rule', [null]], ['array rule', [[]]],
  ['invalid country type', [{ COUNTRY_IS: 42 }]], ['invalid user agent type', [{ USER_AGENT_CONTAINS: true }]],
  ['screen not an array', [{ SCREEN_IS: '390x844' }]], ['invalid screen', [{ SCREEN_IS: ['0x844'] }]],
  ['invalid pixel ratio type', [{ PIXEL_RATIO_IS: '3' }]], ['invalid pixel ratio value', [{ PIXEL_RATIO_IS: 0 }]]
]) {
  test(`malformed fingerprint configuration is rejected: ${label}`, async () => {
    const b = browser({ config: configured({ 'FINGERPRINT_EXCLUDE.RULES': rules }) });
    await b.advance(600); b.noErrors();
    assert.equal(b.stats3().length + b.stat4().length + b.captcha().length, 0);
    assert.ok(b.logs.some(log => log.level === 'warn'));
  });
}

test('hidden notification is separate from the eventual LEAVE', async () => {
  const b = browser({ config: configured({ 'SQL.NOTIFY_ON_HIDDEN': true }) }); await b.advance(600); await b.visibility(true);
  assert.equal(b.stats3('HIDDEN').length, 1); assert.equal(b.stats3('HIDDEN')[0].data.events.reached.LEAVE, false);
  await b.visibility(false); await b.event('pagehide'); b.noErrors(); assert.equal(b.stats3('LEAVE').length, 1);
});

test('session cooldown suppresses close events and expires across pages', async () => {
  const config = configured({ 'SQL.SESSION_COOLDOWN_MS': 5000, 'SQL.GLOBAL_COOLDOWN_KEY': 'custom-cooldown' });
  const b = browser({ config }); await b.advance(600); await b.event('pagehide'); assert.equal(b.stats3().length, 1);
  assert.ok(b.local.getItem('custom-cooldown'));
  const next = browser({ config, localStorage: b.local, now: EPOCH + 6000 }); await next.advance(600); assert.equal(next.stats3('VISIT').length, 1);
});

test('retry queue persists rejected requests and retries immutable snapshots online', async () => {
  let offline = true;
  const config = configured({ 'SQL.RETRY_QUEUE_ENABLED': true, 'SQL.RETRY_QUEUE_MAX_ITEMS': 10, 'SQL.RETRY_QUEUE_KEY': 'test-queue' });
  const respond = request => request.url.endsWith('/pixl_collect.php') && offline ? Promise.reject(new Error('offline')) : undefined;
  const b = browser({ config, beacon: false, respond }); await b.advance(600); b.noErrors();
  const original = b.stats3('VISIT')[0].data;
  assert.equal(JSON.parse(b.session.getItem('test-queue')).length, 1);
  offline = false; await b.event('online');
  assert.equal(b.stats3('VISIT').length, 2); assert.deepEqual(b.stats3('VISIT')[1].data, original);
  assert.equal(b.stats3('VISIT')[1].init.mode, 'cors'); assert.equal(JSON.parse(b.session.getItem('test-queue')).length, 0);
});

test('retry queue recovers on a new document and respects its item bound', async () => {
  const config = configured({ 'SQL.RETRY_QUEUE_ENABLED': true, 'SQL.RETRY_QUEUE_MAX_ITEMS': 1, 'SQL.RETRY_QUEUE_KEY': 'reload-queue' });
  const b = browser({ config, beacon: false, respond: request => request.url.endsWith('/pixl_collect.php') ? Promise.reject(new Error('offline')) : undefined });
  await b.advance(600); await b.event('pagehide'); const queued = JSON.parse(b.session.getItem('reload-queue'));
  assert.equal(queued.length, 1); assert.equal(queued[0].payload.reason, 'LEAVE');
  const next = browser({ config, sessionStorage: b.session, localStorage: b.local, now: EPOCH + 1000 }); await next.advance(0);
  assert.equal(next.stats3('LEAVE').length, 1); assert.deepEqual(next.stats3('LEAVE')[0].data, queued[0].payload);
});

test('retry enabled with zero item capacity is rejected instead of silently losing retries', async () => {
  const b = browser({ config: configured({ 'SQL.RETRY_QUEUE_ENABLED': true, 'SQL.RETRY_QUEUE_MAX_ITEMS': 0 }) });
  await b.advance(600); assert.equal(b.stats3().length + b.stat4().length, 0);
});

test('device detection and known-resolution configuration affect outgoing context', async () => {
  const b = browser({ config: configured({ 'DEVICE_DETECT.ENABLED': false, 'KNOWN_RESOLUTIONS': [] }) });
  await b.advance(600); const ctx = b.stats3()[0].data.context;
  assert.equal(ctx.browser, 'Unknown'); assert.equal(ctx.os, 'Unknown'); assert.equal(ctx.device, 'Unknown'); assert.equal(ctx.knownResolution, false);
});

test('render checks detect failed images and honor their inclusion and enabled switches', async () => {
  for (const [enabled, include] of [[true, true], [true, false], [false, true]]) {
    const b = browser({ images: [{ complete: true, src: 'https://example.net/missing.png', naturalWidth: 0 }],
      config: configured({ 'RENDER_HEALTH.ENABLED': enabled, 'SQL.INCLUDE_RENDER_ISSUES': include }) });
    await b.advance(600); assert.equal(b.stats3()[0].data.health.renderIssues.includes('failed-image'), enabled && include);
  }
});

test('render console limit and failed-check limit govern health reporting', async () => {
  const b = browser({ config: configured({ 'RENDER_HEALTH.INTERVAL_MS': 100, 'RENDER_HEALTH.MAX_CONSOLE_ERRORS': 0, 'RENDER_HEALTH.MAX_FAILED_CHECKS': 1 }) });
  await b.advance(0); b.window.console.error('synthetic'); await b.advance(600);
  assert.deepEqual(b.stats3()[0].data.health.renderIssues, ['console-error-limit']);
});

test('reading minimum gates READ and configured thresholds label activity', async () => {
  const b = browser({ config: configured({ 'READING.MIN_SCORE': 1000 }) }); await b.advance(600); await b.activity(); await b.advance(15000);
  assert.equal(b.stats3('READ').length, 0);
  const c = browser({ config: configured({ 'READING.THRESHOLDS.READ': 1, 'READING.THRESHOLDS.GOOD': 2, 'READING.THRESHOLDS.EXCELLENT': 3 }) });
  await c.advance(600); await c.activity(); await c.event('pagehide'); assert.equal(c.stats3('LEAVE')[0].data.engagement.readingLevel, 'EXCELLENT');
});

test('bot immediate tracking and debug output can be disabled centrally', async () => {
  const b = browser({ ua: 'Googlebot/2.1', config: configured({ 'SQL.TRACK_BOTS_IMMEDIATELY': true }) }); await b.advance(0); assert.equal(b.stats3('VISIT').length, 1);
  const c = browser({ ua: 'Googlebot/2.1', config: configured({ 'SQL.TRACK_BOTS_IMMEDIATELY': false, 'DEBUG.ENABLED': false }) });
  await c.advance(0); assert.equal(c.stats3().length, 0); await c.advance(600); assert.equal(c.stats3('VISIT').length, 1);
  assert.ok(!c.logs.some(log => String(log.args[0]).startsWith('Stats3 event:')));
});

test('activity renews the shared session without discarding another tab page', async () => {
  const b = browser(); await b.advance(600);
  const initial = b.stats3()[0].data.session.id;
  const saved = JSON.parse(b.local.getItem('__pixl77UserSessionV1')); saved.pages.other = { eventId: 'other', startedAt: EPOCH };
  b.local.setItem('__pixl77UserSessionV1', JSON.stringify(saved));
  for (let minute = 0; minute < 31; minute++) { await b.advance(60000); await b.event('keydown'); }
  assert.ok(JSON.parse(b.local.getItem('__pixl77UserSessionV1')).pages.other);
  const next = browser({ localStorage: b.local, now: EPOCH + 31 * 60000 + 1000, url: 'https://www.bayerchristian.de/next' });
  await next.advance(600); assert.equal(next.stats3()[0].data.session.id, initial);
});

test('late CAPTCHA check is deferred through BFCache and nonce reaches the dynamic script', async () => {
  let resolveCheck;
  const b = browser({ nonce: 'nonce-test', respond: request => request.data?.action === 'check' ? new Promise(resolve => { resolveCheck = resolve; }) : undefined });
  await b.advance(0); await b.event('pagehide', { persisted: true }); resolveCheck(await requireCaptcha()); await b.settle();
  assert.equal(b.scripts.length, 0); await b.event('pageshow', { persisted: true }); b.noErrors();
  assert.equal(b.scripts.length, 1); assert.equal(b.scripts[0].nonce, 'nonce-test');
  assert.equal(b.window.SwipeGateOptions.shouldStart(), true);
  await b.event('pagehide', { persisted: true }); assert.equal(b.window.SwipeGateOptions.shouldStart(), false);
  let resumed = 0; b.window.SwipeGate = { init: () => resumed++, dismiss() {} }; await b.loadCaptcha(); assert.equal(resumed, 0);
  await b.event('pageshow', { persisted: true }); assert.equal(resumed, 1);
});

test('READ delayed by cooldown is retried when the cooldown expires', async () => {
  const b = browser({ config: configured({ 'SQL.SESSION_COOLDOWN_MS': 20000, 'SQL.READ_RECHECK_MS': 1000 }) });
  await b.advance(600); await b.activity(); await b.advance(14500); assert.equal(b.stats3('READ').length, 0);
  await b.advance(6000); b.noErrors(); assert.equal(b.stats3('READ').length, 1);
});

test('two offline tabs retain separate retry queues through reload', async () => {
  const config = configured({ 'SQL.RETRY_QUEUE_ENABLED': true, 'SQL.RETRY_QUEUE_MAX_ITEMS': 10, 'SQL.RETRY_QUEUE_KEY': 'tab-queue' });
  const local = storage();
  const respond = request => request.url.endsWith('/pixl_collect.php') ? Promise.reject(new Error('offline')) : undefined;
  const a = browser({ config, localStorage: local, respond }); const b = browser({ config, localStorage: local, respond, url: 'https://www.bayerchristian.de/other' });
  await a.advance(600); await b.advance(600); await a.event('pagehide'); await b.event('pagehide');
  assert.equal(JSON.parse(a.session.getItem('tab-queue')).length, 2); assert.equal(JSON.parse(b.session.getItem('tab-queue')).length, 2);
  const reload = browser({ config, localStorage: local, sessionStorage: a.session, now: EPOCH + 1000 }); await reload.advance(0);
  assert.equal(reload.stats3().length, 2); assert.equal(JSON.parse(b.session.getItem('tab-queue')).length, 2);
});

test('known queue envelopes migrate from legacy keys; unversioned payloads are not replayed', async () => {
  const config = configured({ 'SQL.RETRY_QUEUE_ENABLED': true, 'SQL.RETRY_QUEUE_MAX_ITEMS': 10, 'SQL.RETRY_QUEUE_KEY': 'current-queue', 'SQL.RETRY_QUEUE_LEGACY_KEYS': ['legacy-queue'] });
  const b = browser({ config, respond: request => request.url.endsWith('/pixl_collect.php') ? Promise.reject(new Error('offline')) : undefined });
  await b.advance(600); const entries = JSON.parse(b.session.getItem('current-queue'));
  b.session.removeItem('current-queue'); b.session.setItem('legacy-queue', JSON.stringify([...entries, { endpoint: entries[0].endpoint, payload: { ...entries[0].payload, message: 'OLD_PRIVATE_DATA' } }]));
  const next = browser({ config, sessionStorage: b.session, now: EPOCH + 1000 }); await next.advance(0);
  assert.equal(next.stats3().length, 1); assert.ok(!JSON.stringify(next.stats3()).includes('OLD_PRIVATE_DATA')); assert.equal(next.session.getItem('legacy-queue'), null);
});

test('debug force requires debug enabled and explicitly overrides count/cooldown limits', async () => {
  const fields = { 'SQL.MAX_NOTIFICATIONS_PER_PAGE': 0, 'SQL.SESSION_COOLDOWN_MS': 100000, 'DEBUG.FORCE_NOTIFY': true };
  const a = browser({ config: configured({ ...fields, 'DEBUG.ENABLED': false }) }); await a.advance(16000); await a.event('pagehide'); assert.equal(a.stats3().length, 0);
  const b = browser({ config: configured(fields) }); await b.advance(16000); await b.event('pagehide'); assert.deepEqual(b.stats3().map(r => r.data.reason), ['VISIT', 'READ', 'LEAVE']);
});

test('pagehide cannot revive a session after thirty idle minutes', async () => {
  const b = browser(); await b.advance(600); const first = b.stats3()[0].data.session.id;
  await b.advance(31 * 60000); await b.event('pagehide');
  const next = browser({ localStorage: b.local, now: EPOCH + 31 * 60000 + 1000 }); await next.advance(600);
  assert.notEqual(next.stats3()[0].data.session.id, first);
});

test('DOM-delayed Stats3 start waits until a hidden document is restored', async () => {
  const b = browser({ readyState: 'loading' }); await b.advance(0); await b.event('pagehide', { persisted: true }); await b.ready(); await b.advance(600);
  assert.equal(b.stats3().length + b.captcha().length, 0);
  await b.event('pageshow', { persisted: true }); await b.advance(600); b.noErrors(); assert.equal(b.stats3('VISIT').length, 1);
});

(async () => {
  let failed = 0;
  let unhandled = [];
  const captureRejection = error => { unhandled.push(error); };
  process.on('unhandledRejection', captureRejection);
  for (const { name, run } of cases) {
    unhandled = [];
    try {
      await run();
      await new Promise(resolve => setImmediate(resolve));
      assert.equal(unhandled.length, 0, `unhandled rejection: ${unhandled.map(error => error.stack).join('\n')}`);
      console.log(`PASS ${name}`);
    } catch (error) {
      await new Promise(resolve => setImmediate(resolve));
      failed++; console.error(`FAIL ${name}\n  ${error.stack}`);
    }
  }
  process.removeListener('unhandledRejection', captureRejection);
  console.log(`\n${cases.length - failed}/${cases.length} complete-count.js runtime scenarios passed; ${failed} failed. No network requests were sent.`);
  process.exitCode = failed ? 1 : 0;
})().catch(error => { console.error(error); process.exitCode = 1; });
