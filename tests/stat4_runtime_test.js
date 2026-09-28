#!/usr/bin/env node
'use strict';

// Isolated browser VM: all network requests are recorded, never delivered.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
let source = fs.readFileSync(process.env.STAT4_SOURCE || path.join(__dirname, '../stat4/count.js'), 'utf8');
const shared = source.includes('// STAT4 tracker');
if (shared) {
  const start = source.indexOf('(function () {', source.indexOf('// STAT4 tracker'));
  const end = source.indexOf('\n})();', start);
  assert.ok(start >= 0 && end > start, 'Shared STAT4 block found');
  source = source.slice(start, end + 6);
}
const EPOCH = 1809900000000;
let checks = 0;
let sequence = 0;
function check(condition, message) { assert.ok(condition, message); checks++; }
function store(initial = {}, failWrites = false) {
  const values = new Map(Object.entries(initial));
  return { values, getItem: key => values.get(key) ?? null,
    setItem(key, value) { if (failWrites && !key.startsWith('__stat4_storage_test')) throw Error('quota'); values.set(key, String(value)); },
    removeItem(key) { values.delete(key); } };
}
function events(target = {}) {
  const listeners = new Map();
  target.addEventListener = (type, listener) => listeners.set(type, [...(listeners.get(type) || []), listener]);
  target.dispatch = (type, detail = {}) => (listeners.get(type) || []).forEach(listener => listener({ type, ...detail }));
  return target;
}
async function settle() { for (let i = 0; i < 12; i++) await Promise.resolve(); }
function browser(options = {}) {
  let elapsed = 0, nextTimer = 0, focus = true;
  const timers = new Map(), requests = [];
  const local = options.local || store(), session = options.session || store();
  const location = new URL('https://external.example/page?utm_source=test#access_token=SECRET_FRAGMENT');
  const document = events({ hidden: false, readyState: options.loading ? 'loading' : 'complete', title: 'Fixture',
    referrer: 'https://ref.example/?utm_source=ref#SECRET_REF', hasFocus: () => focus,
    currentScript: { src: 'https://central.example/stat4/count.js', dataset: shared ? { stat4Endpoint: options.dataset?.endpoint } : (options.dataset || {}) },
    documentElement: { scrollHeight: 2400, offsetHeight: 2400, clientHeight: 800, clientWidth: 1280, scrollTop: 0 },
    body: { scrollHeight: 2400, offsetHeight: 2400 } });
  class Clock extends Date { constructor(...args) { super(...(args.length ? args : [EPOCH + elapsed])); } static now() { return EPOCH + elapsed; } }
  class TestBlob { constructor(parts) { this.body = parts.join(''); } }
  function record(transport, url, init) {
    const request = { transport, url, init, data: JSON.parse(init.body?.body || init.body), at: elapsed };
    requests.push(request); return request;
  }
  function timer(callback, delay, interval) { const id = ++nextTimer; timers.set(id, { callback, at: elapsed + delay, interval }); return id; }
  const navigator = { userAgent: 'Test Browser', language: 'de-DE', sendBeacon(url, body) {
    const request = record('beacon', url, { body }); request.accepted = options.beacon !== false; return request.accepted;
  } };
  const window = events({ document, navigator, location, localStorage: local, sessionStorage: session,
    screen: { width: 1920, height: 1080 }, innerWidth: 1280, innerHeight: 800, scrollY: 0,
    crypto: { randomUUID: () => `00000000-0000-4000-8000-${String(++sequence).padStart(12, '0')}` },
    setInterval: (callback, delay) => timer(callback, delay, delay),
    setTimeout: (callback, delay) => timer(callback, delay, 0), clearTimeout: id => timers.delete(id),
    fetch: options.fetchMissing ? undefined : (url, init) => {
      const request = record('fetch', url, init);
      return options.fetch ? options.fetch(request) : Promise.resolve({ ok: true });
    } });
  vm.runInNewContext(source, { window, document, navigator, location, Date: Clock, Blob: TestBlob, URL, URLSearchParams,
    Intl, Math, JSON, Promise, Number, Object, AbortController, console, countScript: document.currentScript,
    CONFIG: { STAT4: { ENDPOINT: 'https://central.example/stat4/collect.php', VISITOR_TTL: 86400000, HEARTBEAT_INTERVAL: 30000, TICK_INTERVAL: 5000 } } }, { filename: 'stat4/count.js' });
  return { window, document, requests, local, session,
    async advance(ms) {
      const end = elapsed + ms;
      for (;;) {
        const entry = [...timers].filter(([, value]) => value.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
        if (!entry) break;
        elapsed = entry[1].at;
        if (entry[1].interval) entry[1].at += entry[1].interval; else timers.delete(entry[0]);
        entry[1].callback(); await settle();
      }
      elapsed = end; await settle();
    },
    focus(value) { focus = value; window.dispatch(value ? 'focus' : 'blur'); },
    hidden(value) { document.hidden = value; document.dispatch('visibilitychange'); },
    click(element) { element.nodeType = 1; element.closest = () => element; document.dispatch('click', { target: element }); },
    delivered() { return requests.filter(request => request.transport === 'fetch' || request.accepted); },
    seconds() { const byId = new Map(this.delivered().map(request => [request.data.eventId, request.data])); return [...byId.values()].reduce((sum, data) => sum + data.activeSeconds, 0); }
  };
}

(async () => {
  let b = browser({ loading: true });
  check(b.requests[0].data.type === 'pageview' && b.requests[0].data.level === 0, 'Head script sends one early pageview at level zero');
  check(!JSON.stringify(b.requests[0].data).includes('SECRET'), 'Page and referrer fragments are removed');
  check(b.requests[0].data.url.includes('utm_source=test'), 'Campaign query survives URL sanitization');
  b.document.readyState = 'complete'; b.document.dispatch('DOMContentLoaded');
  for (const tag of ['INPUT', 'SELECT', 'TEXTAREA']) b.click({ tagName: tag, value: 'SECRET_VALUE', textContent: 'SECRET_TEXT' });
  b.click({ tagName: 'INPUT', type: 'password', name: 'login', value: 'SECRET_PASSWORD' });
  b.click({ tagName: 'A', href: 'https://a.example/?utm_source=click#SECRET_LINK' });
  check(b.requests.filter(r => r.data.type === 'click').length === 5, 'Form and link clicks retain a safe static description');
  check(!JSON.stringify(b.requests).includes('SECRET_VALUE') && !JSON.stringify(b.requests).includes('SECRET_TEXT') && !JSON.stringify(b.requests).includes('SECRET_PASSWORD') && !JSON.stringify(b.requests).includes('SECRET_LINK'), 'Click payloads never contain form values or fragments');
  check(b.requests.at(-1).data.target === 'https://a.example/?utm_source=click', 'Link targets preserve campaign query only');

  b = browser(); const documentUrl = b.requests[0].data.url;
  b.window.location.href = 'https://external.example/virtual?utm_source=changed#SECRET';
  await b.advance(30000); b.window.dispatch('pagehide');
  check(b.requests.every(r => r.data.url === documentUrl && r.data.utm.source === 'test'), 'An instance keeps its document URL and campaign after a history route change');

  b = browser({ local: store({}, true), session: store({}, true) });
  const first = b.requests[0].data;
  await b.advance(30000); b.click({ tagName: 'BUTTON', id: 'test' });
  check(b.requests.every(r => r.data.visitorId === first.visitorId && r.data.sessionId === first.sessionId), 'Storage quota failure after probe preserves memory identity');

  b = browser({ beacon: false }); b.click({ tagName: 'BUTTON', id: 'navigate' });
  check(b.requests.filter(r => r.transport === 'fetch' && r.data.type === 'click').every(r => r.init.keepalive === true), 'Navigation clicks use keepalive when Beacon rejects');

  b = browser(); await b.advance(7500); b.window.dispatch('pagehide', { persisted: true });
  check(b.seconds() === 7, 'Pagehide accounts for the fractional current interval');
  const beforeFreeze = b.requests.length; await b.advance(60000);
  check(b.requests.length === beforeFreeze, 'A BFCache-suspended page does not send heartbeats');
  b.window.dispatch('pageshow', { persisted: true }); await b.advance(7500); b.window.dispatch('pagehide');
  check(b.seconds() === 15, 'BFCache resume counts activity and retains fractional remainder without frozen time');

  b = browser();
  for (let i = 0; i < 5; i++) { await b.advance(2500); b.hidden(true); await b.advance(2000); b.hidden(false); }
  b.window.dispatch('pagehide');
  check(b.seconds() === 12, 'Visibility transitions count preceding visible fractions without hidden time');
  b = browser(); await b.advance(2500); b.focus(false); await b.advance(10000); b.focus(true); await b.advance(2500); b.window.dispatch('pagehide');
  check(b.seconds() === 5, 'Focus transitions retain active fractions and exclude unfocused time');

  let failedId = '';
  b = browser({ fetch: request => {
    if (!failedId) { failedId = request.data.eventId; return Promise.resolve({ ok: false }); }
    return Promise.resolve({ ok: true });
  } });
  await b.advance(60000);
  const retries = b.requests.filter(r => r.data.eventId === failedId);
  check(retries.length === 2 && retries.every(r => r.data.activeSeconds === 30), 'HTTP failure retries the immutable 30-second event and UUID');
  check(b.seconds() === 60, 'A failed heartbeat does not discard its active seconds');
  check(b.session.values.get('stat4_retry_queue:https://central.example/stat4/collect.php') === undefined, 'Acknowledged events leave the persistent retry queue');

  failedId = '';
  b = browser({ fetch: request => { if (!failedId) { failedId = request.data.eventId; return Promise.reject(Error('offline')); } return Promise.resolve({ ok: true }); } });
  await b.advance(60000);
  check(b.requests.filter(r => r.data.eventId === failedId).length === 2 && b.seconds() === 60, 'Network rejection also retries without losing or duplicating activity');

  b = browser({ fetch: () => new Promise(() => {}) }); await b.advance(30000);
  const inFlight = b.requests.find(r => r.transport === 'fetch').data.eventId;
  b.window.dispatch('pagehide');
  check(b.requests.some(r => r.transport === 'beacon' && r.data.eventId === inFlight), 'Pagehide recovers an in-flight heartbeat using the same UUID');
  check(b.seconds() === 30, 'Beacon/fetch race is idempotent by event UUID');

  b = browser({ fetch: () => new Promise(() => {}) }); await b.advance(60000);
  const hungId = b.requests.find(r => r.transport === 'fetch').data.eventId;
  check(b.requests.filter(r => r.transport === 'fetch' && r.data.eventId === hungId).length === 2, 'A timed-out fetch becomes retryable with its original UUID');

  b = browser({ beacon: false, fetchMissing: true }); await b.advance(30000);
  const savedSession = b.session, savedLocal = b.local;
  const oldIds = [...new Set(b.requests.map(r => r.data.eventId))];
  b = browser({ session: savedSession, local: savedLocal }); await settle();
  check(oldIds.every(id => b.requests.some(r => r.transport === 'fetch' && r.data.eventId === id)), 'A new page retries queued events from session storage using original IDs');

  b = browser(); const oldIdentity = b.requests[0].data;
  await b.advance(15000);
  b.local.setItem('stat4_visitor', '11111111-1111-4111-8111-111111111111');
  b.local.setItem('stat4_visitor_started', EPOCH + 15000);
  b.window.dispatch('storage', { key: 'stat4_visitor' });
  await b.advance(15000); b.window.dispatch('pagehide');
  const oldSeconds = b.delivered().filter(r => r.data.visitorId === oldIdentity.visitorId).reduce((n, r) => n + r.data.activeSeconds, 0);
  const newSeconds = b.delivered().filter(r => r.data.visitorId !== oldIdentity.visitorId).reduce((n, r) => n + r.data.activeSeconds, 0);
  check(oldSeconds === 15 && newSeconds === 15, 'Cross-tab visitor rotation keeps the preceding activity on the old visitor');
  check(b.requests.filter(r => r.data.visitorId !== oldIdentity.visitorId).every(r => r.data.sessionId !== oldIdentity.sessionId), 'Visitor rotation binds a fresh tab session');
  check(b.requests.some(r => r.data.type === 'pageview' && r.data.visitorId !== oldIdentity.visitorId), 'A rotated visitor receives the current pageview');

  const expires = EPOCH + 2500;
  b = browser({ local: store({ stat4_visitor: '22222222-2222-4222-8222-222222222222', stat4_visitor_started: expires - 86400000 }) });
  await b.advance(5000); b.window.dispatch('pagehide');
  const oldFinal = b.requests.find(r => r.data.type === 'heartbeat' && r.data.visitorId.startsWith('2222'));
  check(oldFinal?.data.activeSeconds === 2 && oldFinal.data.occurredAt === expires - 1, 'TTL boundary freezes old activity and event timestamp before expiration');
  check(b.requests.filter(r => !r.data.visitorId.startsWith('2222')).reduce((n, r) => n + r.data.activeSeconds, 0) === 2, 'Only post-expiry activity is assigned to the new visitor');

  b = browser({ local: store({ stat4_visitor: 'visitor-new', stat4_visitor_started: EPOCH }),
    session: store({ stat4_session: 'session-old', stat4_session_visitor: 'visitor-old' }) });
  check(b.requests[0].data.sessionId !== 'session-old', 'Reload cannot reuse a session bound to a different visitor');
  b = browser({ dataset: { endpoint: 'javascript:alert(1)' } });
  check(b.requests[0].url.startsWith('https://'), 'Invalid endpoint protocols use the configured HTTP fallback');

  b = browser({ beacon: false, fetchMissing: true });
  for (let i = 0; i < 100; i++) b.click({ tagName: 'BUTTON', id: 'queue' });
  check(JSON.parse([...b.session.values].find(([key]) => key.startsWith('stat4_retry_queue:'))[1]).length === 64, 'Persistent retry queue remains bounded under network failure');
  console.log(`STAT4 runtime: ${checks} checks passed`);
})().catch(error => { console.error(error); process.exitCode = 1; });
