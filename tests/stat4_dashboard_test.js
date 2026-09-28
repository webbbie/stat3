#!/usr/bin/env node
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const requests = [], nodes = new Map();
const location = new URL('https://central.example/stat4/?range=1day');
function node() {
  const listeners = new Map();
  return { innerHTML: '', value: '', className: '', dataset: {}, classList: { toggle() {} },
    addEventListener(type, callback) { listeners.set(type, callback); },
    fire(type) { listeners.get(type)?.call(this); },
    set textContent(value) { this.innerHTML = String(value).replace(/</g, '&lt;').replace(/>/g, '&gt;'); } };
}
const buttons = ['1day', '24h', '60min'].map(range => ({ ...node(), dataset: { range } }));
function $(selector) {
  if (selector.endsWith('Chart')) return null;
  if (!nodes.has(selector)) nodes.set(selector, node());
  return nodes.get(selector);
}
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../stat4/dashboard.js'), 'utf8'), {
  window: {}, document: { querySelector: $, querySelectorAll: () => buttons, createElement: node },
  location, history: { replaceState(state, title, url) { location.href = String(url); } },
  URL, URLSearchParams, Intl, Date, Number, String, Object, Math, Promise,
  fetch(url) { return new Promise((resolve, reject) => requests.push({ url, resolve, reject })); },
  addEventListener() {}, setInterval() {}, setTimeout() {}, clearTimeout() {}
});
function response(range, count) {
  return { ok: true, json: async () => ({ ok: true, range, generated_at: '2026-09-11T10:00:00Z',
    metrics: { visitors: count }, trend: [], breakdowns: {}, details: {}, paths: [], user_agents: [],
    session_summary: {}, levels: [], campaigns: [], live: {}, per_minute: [], charts: {}, forecast: {} }) };
}
async function settle() { for (let i = 0; i < 15; i++) await Promise.resolve(); }
(async () => {
  assert.equal(requests.length, 1);
  buttons[1].fire('click');
  assert.equal(requests.length, 2, 'Changing range starts its request while the previous range is still loading');
  assert.equal(location.search, '?range=24h');
  requests[1].resolve(response('24h', 240)); await settle();
  const wanted = $('#primaryMetrics').innerHTML;
  assert.match(wanted, /240/);
  requests[0].resolve(response('1day', 10)); await settle();
  assert.equal($('#primaryMetrics').innerHTML, wanted, 'An older response cannot overwrite the requested range metrics');
  assert.equal(location.search, '?range=24h', 'An older response cannot revert the URL selection');

  buttons[0].fire('click'); buttons[2].fire('click');
  requests[2].reject(Error('Old request failed')); await settle();
  assert.equal($('#status').className, 'status', 'An obsolete request error does not replace current loading state');
  $('#refresh').fire('click');
  assert.equal(requests.length, 4, 'Refresh coalesces with the current range request');
  requests[3].resolve(response('60min', 60)); await settle();
  assert.match($('#primaryMetrics').innerHTML, /60/);
  assert.equal(location.search, '?range=60min');
  $('#refresh').fire('click');
  assert.equal(requests.length, 5, 'Current range can refresh after its request completes');
  requests[4].reject(Error('Current failure')); await settle();
  assert.equal($('#status').className, 'status error', 'Current request errors stay visible');
  console.log('STAT4 dashboard: 12 checks passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
