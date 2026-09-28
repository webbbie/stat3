"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const root = path.dirname(__dirname);
const flush = () => new Promise(resolve => setImmediate(resolve));

async function gate(options, fixture = {}) {
  const context2d = new Proxy({}, { get: (_, name) => name === "createLinearGradient"
    ? () => ({ addColorStop() {} }) : () => {} });
  const element = () => {
    const classes = new Set();
    return {
      width: 520, height: 250, value: "0", disabled: false, events: {}, removed: false,
      classList: { add(name) { classes.add(name); }, remove(name) { classes.delete(name); }, contains(name) { return classes.has(name); } },
      setAttribute() {}, focus() {}, appendChild() {}, remove() { this.removed = true; }, getContext: () => context2d,
      addEventListener(name, handler) { this.events[name] = handler; }
    };
  };
  const nodes = new Map();
  const links = [];
  const timers = new Map();
  let nextTimer = 0;
  const document = {
    readyState: "complete", baseURI: "https://external.example/page", currentScript: { src: "https://central.example/stats3/captcha.js?v=1.2.0", nonce: "fixture-nonce" },
    documentElement: element(), body: element(), head: { appendChild(link) { links.push(link); if (!fixture.manualStyle) link.onload(); } },
    createElement: element, querySelectorAll: () => [], dispatchEvent() {},
    getElementById(id) {
      if (!nodes.has(id)) nodes.set(id, element());
      return nodes.get(id);
    }
  };
  const window = {
    SwipeGateOptions: { rememberForMinutes: 0, ...options },
    crypto: { getRandomValues(array) { array.fill(0); } },
    getComputedStyle: () => ({ position: fixture.position || "fixed" }),
    setTimeout(callback) { timers.set(++nextTimer, callback); return nextTimer; }, clearTimeout(id) { timers.delete(id); }
  };
  vm.runInNewContext(fs.readFileSync(path.join(root, "captcha.js"), "utf8"), { window, document, URL });
  await flush();
  return { window, document, links, timers, get slider() { return nodes.get("swipe-gate-range"); }, get status() { return nodes.get("swipe-gate-status"); } };
}

async function main() {
  let failures = 0;
  let confirmations = 0;
  const ui = await gate({ onFailure: () => { failures++; }, onConfirm: () => { confirmations++; return true; } });
  ui.slider.events.change(); // Zero does not fit the deterministic target near 52%.
  await flush();
  assert.equal(failures, 1);
  assert.equal(confirmations, 0);
  assert.equal(ui.status.className, "is-error");
  assert.equal(ui.slider.disabled, false);
  ui.slider.value = "52";
  ui.slider.events.change();
  await flush();
  assert.equal(confirmations, 1);
  assert.equal(failures, 1);
  assert.equal(ui.status.className, "is-success");
  ui.slider.events.change();
  await flush();
  assert.equal(confirmations, 1, "a verified gate must not submit again");

  const offline = await gate({ onFailure: () => Promise.reject(new Error("offline")) });
  offline.slider.events.change();
  await flush();
  assert.equal(offline.slider.disabled, false, "reporting errors must still allow another try");
  offline.slider.value = "52";
  offline.slider.events.change();
  assert.equal(offline.status.className, "is-success");

  assert.equal(ui.links[0].href, "https://central.example/stats3/captcha.css", "CSS resolves against the central script, never the external page");
  assert.equal(ui.links[0].rel, "stylesheet");
  assert.equal(ui.links[0].nonce, "fixture-nonce", "a nonce reaches the external stylesheet");

  let suspended = true;
  const deferred = await gate({ shouldStart: () => !suspended });
  assert.equal(deferred.links.length, 0, "a suspended page must not begin loading the gate");
  assert.equal(deferred.document.documentElement.classList.contains("swipe-gate-locked"), false);
  suspended = false;
  deferred.window.SwipeGate.init();
  await flush();
  assert.ok(deferred.slider, "explicit init resumes a deferred gate");
  deferred.window.SwipeGate.init();
  assert.equal(deferred.links.length, 1, "repeated init does not duplicate markup or CSS");

  let allowed = true;
  const slow = await gate({ shouldStart: () => allowed }, { manualStyle: true });
  assert.equal(slow.slider, undefined, "slow CSS never exposes an unstyled puzzle");
  assert.equal(slow.document.documentElement.classList.contains("swipe-gate-locked"), false);
  allowed = false;
  slow.links[0].onload();
  await flush();
  assert.equal(slow.slider, undefined, "CSS completing during pagehide does not initialize the gate");
  allowed = true;
  slow.window.SwipeGate.init();
  await flush();
  assert.ok(slow.slider);
  assert.equal(slow.links.length, 1, "resuming uses the stylesheet that already loaded");

  for (const failure of ["error", "timeout", "blocked-layout"]) {
    let dismissals = 0;
    const blocked = await gate({ onDismiss: () => { dismissals++; } }, { manualStyle: failure !== "blocked-layout", position: failure === "blocked-layout" ? "static" : "fixed" });
    if (failure === "error") blocked.links[0].onerror();
    if (failure === "timeout") [...blocked.timers.values()][0]();
    await flush();
    assert.equal(dismissals, 1, failure + " releases the page exactly once");
    assert.equal(blocked.document.documentElement.classList.contains("swipe-gate-locked"), false);
    blocked.window.SwipeGate.init();
    await flush();
    assert.equal(dismissals, 1, "dismissed gates cannot reappear");
  }
  const manual = await gate({ autoInit: false });
  assert.equal(manual.links.length, 0);
  manual.window.SwipeGate.init();
  await flush();
  assert.ok(manual.slider, "autoInit:false permits explicit initialization");

  // Exercise the actual loader with a resumed ticket, a lost failure report,
  // and final verification. No real analytics endpoint is contacted.
  const source = fs.readFileSync(path.join(root, "count.js"), "utf8");
  const start = source.indexOf("  function startCaptcha() {");
  const end = source.indexOf("\n  (function autoInit()", start);
  const urlStart = source.indexOf("  function pageUrl(value) {");
  const urlEnd = source.indexOf("\n(() => {", urlStart);
  assert.ok(start >= 0 && end > start);
  assert.ok(urlStart >= 0 && urlEnd > urlStart);
  const requests = [];
  const scripts = [];
  const token = "a".repeat(64);
  const window = { addEventListener() {} };
  const sandbox = {
    window, countScript: { nonce: "fixture-nonce" }, location: { href: "https://example.com/page#access_token=synthetic", hostname: "example.com" },
    Utils: { isAllowedHostname: () => true, makeEventId: () => 'test-page-source' },
    CONFIG: { SQL_ENDPOINT: "https://example.com/stats3/pixl_collect.php", SQL_PUBLIC_KEY: "test" },
    document: { getElementById: () => null, createElement: () => ({}), head: { appendChild(script) { scripts.push(script); } } },
    URL, AbortController, setTimeout, clearTimeout, setInterval: () => 1, clearInterval() {},
    fetch: async (url, options) => {
      const body = JSON.parse(options.body);
      requests.push(body);
      if (body.action === "fail") throw new Error("lost report");
      return { ok: true, json: async () => body.action === "check"
        ? { ok: true, required: true, token, failed_attempts: 3 }
        : { ok: true, verified: true } };
    }
  };
  vm.runInNewContext(source.slice(urlStart, urlEnd) + "\n" + source.slice(start, end) + "\nstartCaptcha();", sandbox);
  await flush();
  assert.equal(scripts.length, 1);
  assert.match(scripts[0].src, /captcha\.js(?:\?|$)/);
  assert.equal(scripts[0].nonce, "fixture-nonce");
  window.SwipeGateOptions.onFailure();
  await flush();
  assert.equal(requests[1].action, "fail");
  assert.equal(requests[1].failedAttempts, 1, "a page counts its own attempts independently of parallel tabs");
  assert.equal(requests[1].attemptSource, 'test-page-source');
  assert.equal(requests[1].token, token);
  assert.equal(await window.SwipeGateOptions.onConfirm(), true);
  assert.equal(requests[2].action, "verify");
  assert.equal(requests[2].failedAttempts, 1, "verification recovers a lost failure report");
  assert.equal(requests[2].attemptSource, requests[1].attemptSource);
  window.SwipeGateOptions.onVerified();
  window.SwipeGateOptions.onFailure();
  await flush();
  assert.equal(requests.length, 3, "finished gate must stop failure reporting");
  assert.ok(requests.every(request => request.url === "https://example.com/page"), "all CAPTCHA requests remove URL fragments");
  console.log("PASS Captcha client: puzzle flow, per-page reports, lost report recovery, CSS/nonce, suspension/resume, failures and fragment privacy");
}

main().catch(error => { console.error(error); process.exitCode = 1; });
