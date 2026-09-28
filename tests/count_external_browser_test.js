#!/usr/bin/env node
'use strict';

// Real cross-origin browser/HTTP integration. The source count.js is served unchanged.
// Only a disposable public PHP configuration copy is adjusted; all collectors are mocks.
// No Playwright request interception: CORS and transports are handled by the browser.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');
const defaultPlaywright = '/Users/christianbayer/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright';
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || (fs.existsSync(defaultPlaywright) ? defaultPlaywright : 'playwright'));
const root = path.dirname(__dirname);
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'stats3-external-browser-'));
const logFile = path.join(temp, 'received.jsonl');
const listen = server => new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
const close = server => new Promise(resolve => { server.closeAllConnections?.(); server.close(resolve); });
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
let checks = 0;
function verify(condition, label) { assert.ok(condition, label); checks++; }
function records(name) {
  if (!fs.existsSync(logFile)) return [];
  return fs.readFileSync(logFile, 'utf8').trim().split('\n').filter(Boolean).map(line => JSON.parse(line)).filter(record => record.case === name);
}
async function waitForRecord(name, predicate, timeout = 5000) {
  const end = Date.now() + timeout;
  while (Date.now() < end) { const found = records(name).find(predicate); if (found) return found; await delay(25); }
  throw Error(`Timed out waiting for ${name} collector event; records=${JSON.stringify(records(name))}`);
}

(async () => {
  let browser, central, external, third;
  try {
    const probe = net.createServer(); await listen(probe); const port = probe.address().port; await close(probe);
    const centralOrigin = `http://localhost:${port}`;
    const rewrite = `
// Disposable browser fixture overrides. Never copied back into the project configuration.
$fixtureCase = (string) ($_GET['case'] ?? 'head');
if (!preg_match('/^[a-z0-9-]+$/', $fixtureCase)) { http_response_code(400); exit; }
$config['SQL_ENDPOINT'] = '${centralOrigin}/case/' . $fixtureCase . '/pixl_collect.php';
$config['STAT4']['ENDPOINT'] = '${centralOrigin}/case/' . $fixtureCase . '/stat4/collect.php';
$config['STAT4']['HEARTBEAT_INTERVAL'] = 1000;
$config['STAT4']['TICK_INTERVAL'] = 250;
$config['SQL']['VISIT_DELAY_MS'] = 40;
$config['SQL']['READ_DELAY_MS'] = 120;
$config['SQL']['READ_RECHECK_MS'] = 100;
$config['DEBUG']['FORCE_NOTIFY'] = true;
if ($fixtureCase === 'final-only') { $config['SQL']['FINAL_SUMMARY_ONLY'] = true; $config['SQL']['FINAL_SUMMARY_REASON'] = 'CUSTOM-END'; }
if ($fixtureCase === 'cors-denied') { $config['ALLOWED_DOMAINS'] = ['denied.invalid']; }
`;
    const publicConfig = fs.readFileSync(path.join(root, 'configurator2.php'), 'utf8');
    verify(publicConfig.includes("header('Content-Type:"), 'public PHP configuration override insertion found');
    fs.writeFileSync(path.join(temp, 'configurator2.php'), publicConfig.replace("header('Content-Type:", rewrite + "\nheader('Content-Type:"));
    for (const filename of ['captcha.js', 'captcha.css']) fs.copyFileSync(path.join(root, filename), path.join(temp, filename));
    fs.writeFileSync(path.join(temp, 'router.php'), `<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$allow = preg_match('~^http://(?:localhost|127\\.0\\.0\\.1):[0-9]+$~', $origin);
if ($path === '/configurator2.php') {
    $case = (string) ($_GET['case'] ?? '');
    if (in_array($case, ['config-503', 'config-malformed', 'config-timeout'], true)) {
        if ($allow) header('Access-Control-Allow-Origin: ' . $origin);
        header('Content-Type: application/json');
        if ($case === 'config-timeout') { sleep(6); echo '{}'; exit; }
        if ($case === 'config-503') http_response_code(503);
        echo $case === 'config-malformed' ? '{broken-json' : '{}'; exit;
    }
    require __DIR__ . '/configurator2.php'; exit;
}
if (preg_match('~^/case/([a-z0-9-]+)/(captcha\\.(?:js|css))$~', $path, $asset)) {
    header('Content-Type: ' . (str_ends_with($asset[2], '.js') ? 'text/javascript' : 'text/css'));
    readfile(__DIR__ . '/' . $asset[2]); exit;
}
if (preg_match('~^/case/([a-z0-9-]+)/(pixl_collect\\.php|stat4/collect\\.php|captcha\\.php)$~', $path, $match)) {
    if ($allow) header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: POST, OPTIONS'); header('Access-Control-Allow-Headers: Content-Type');
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    $status = 200;
    if ($match[1] === 'stat4-retry' && $match[2] === 'stat4/collect.php' && ($input['type'] ?? '') === 'heartbeat' && ($input['activeSeconds'] ?? 0) > 0 && !is_file(__DIR__ . '/failed-once')) {
        file_put_contents(__DIR__ . '/failed-once', '1'); $status = 503;
    }
    file_put_contents(__DIR__ . '/received.jsonl', json_encode(['case'=>$match[1], 'receiver'=>$match[2], 'method'=>$_SERVER['REQUEST_METHOD'], 'origin'=>$origin, 'contentType'=>$_SERVER['CONTENT_TYPE'] ?? '', 'status'=>$status, 'data'=>$input]) . "\\n", FILE_APPEND);
    http_response_code($status);
    if ($match[2] === 'captcha.php') {
        $verified = ($input['action'] ?? '') === 'verify';
        echo json_encode(['ok'=>true, 'active'=>true, 'required'=>$match[1] === 'captcha-csp' && !$verified, 'verified'=>$verified, 'token'=>str_repeat('a',64)]); exit;
    }
    echo json_encode(['ok'=>$status === 200]); exit;
}
http_response_code(404); echo 'Fixture route not found';
`);
    central = spawn(process.env.PHP_BIN || 'php', ['-S', `127.0.0.1:${port}`, path.join(temp, 'router.php')], { cwd: temp, stdio: ['ignore', 'pipe', 'pipe'] });
    let serverErrors = '';
    await new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(Error('PHP fixture startup timeout: ' + serverErrors)), 5000);
      central.once('error', error => { clearTimeout(timer); reject(error); });
      central.once('exit', code => { clearTimeout(timer); reject(Error('PHP fixture exited ' + code)); });
      central.stderr.on('data', data => { serverErrors += String(data); if (String(data).includes('Development Server')) { clearTimeout(timer); resolve(); } });
    });
    const countSource = fs.readFileSync(path.join(root, 'count.js'));
    third = http.createServer((req, res) => { res.setHeader('Content-Type', 'text/javascript'); res.end(countSource); });
    await listen(third);
    const thirdOrigin = `http://127.0.0.1:${third.address().port}`;
    external = http.createServer((req, res) => {
      const url = new URL(req.url, 'http://fixture.test');
      if (url.pathname === '/count.js') { res.setHeader('Content-Type', 'text/javascript'); res.end(countSource); return; }
      if (url.pathname === '/blank') { res.setHeader('Content-Type', 'text/html'); res.end('<!doctype html><title>Finished fixture</title>'); return; }
      const name = url.searchParams.get('case') || 'head';
      if (!/^[a-z0-9-]+$/.test(name)) { res.writeHead(400); res.end(); return; }
      const csp = name === 'captcha-csp';
      if (csp) res.setHeader('Content-Security-Policy', `default-src 'none'; script-src 'nonce-fixture-nonce'; style-src 'nonce-fixture-nonce'; connect-src ${centralOrigin}; img-src data:`);
      res.setHeader('Content-Type', 'text/html;charset=utf-8');
      const src = name === 'third-origin' ? thirdOrigin + '/count.js' : '/count.js';
      const attrs = `nonce="fixture-nonce" src="${src}" data-config-url="${centralOrigin}/configurator2.php?case=${name}"`;
      const setup = `<script nonce="fixture-nonce">${name === 'frozen-console' ? 'Object.freeze(console);' : ''}${name === 'stat4-retry' ? "Object.defineProperty(navigator,'sendBeacon',{value:function(){return false}});" : ''}${csp ? 'crypto.getRandomValues=function(array){array.fill(0);return array};' : ''}</script>`;
      const tag = `<script ${attrs}${name === 'async' ? ' async' : name === 'defer' || name === 'duplicate' ? ' defer' : ''}></script>`;
      const body = `<body><h1>External tracker fixture ${name}</h1><p>Disposable receiver, no visitor database.</p><input id="password" type="password" value="SYNTHETIC_PASSWORD"><textarea id="notes">SYNTHETIC_TEXTAREA</textarea><a id="link" href="https://unreachable.invalid/path?utm_source=target#SYNTHETIC_LINK_TOKEN">Test link</a><main>${'<p>Fixture content for a scrollable article.</p>'.repeat(160)}</main>`;
      const dynamic = `<script nonce="fixture-nonce">var tracker=document.createElement('script');tracker.src='/count.js';tracker.dataset.configUrl='${centralOrigin}/configurator2.php?case=${name}';tracker.nonce='fixture-nonce';document.head.appendChild(tracker);</script>`;
      const head = '<!doctype html><html><head><meta charset="utf-8"><title>Count external fixture</title>' + setup;
      if (name === 'head') {
        // Hold the body so the unchanged tracker can observe a genuine parser-loading document.
        res.write(head + tag);
        setTimeout(() => res.end('</head>' + body + '</body></html>'), 300);
      } else res.end(head + (!['end', 'dynamic'].includes(name) ? tag + (name === 'duplicate' ? tag : '') : '') + '</head>' + body + (name === 'end' ? tag : name === 'dynamic' ? dynamic : '') + '</body></html>');
    });
    await listen(external);
    const externalOrigin = `http://127.0.0.1:${external.address().port}`;
    const edge = '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge';
    const executablePath = process.env.BROWSER_EXECUTABLE || (fs.existsSync(edge) ? edge : undefined);
    browser = await chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}),
      args: ['--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1, EXCLUDE localhost'] });
    async function fixture(name, contextOptions = {}) {
      const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', ...contextOptions });
      if (name.startsWith('fingerprint-')) {
        // These cases emulate human devices; automation is otherwise intentionally classified as a bot.
        await context.addInitScript(() => Object.defineProperty(navigator, 'webdriver', { get: () => false }));
      }
      const page = await context.newPage();
      const errors = [], diagnostics = [], remote = [];
      page.on('pageerror', error => errors.push(error.message));
      page.on('console', message => diagnostics.push(message.text()));
      page.on('request', request => { if (!['localhost', '127.0.0.1'].includes(new URL(request.url()).hostname)) remote.push(request.url()); });
      await page.goto(`${externalOrigin}/?case=${name}#SYNTHETIC_FRAGMENT_TOKEN`, { waitUntil: 'load' });
      return { context, page, errors, diagnostics, remote };
    }
    async function finish(name, fixture, expectStats = true) {
      await fixture.page.goto(externalOrigin + '/blank');
      if (expectStats) await waitForRecord(name, record => record.receiver === 'pixl_collect.php' && record.data.events?.reached?.LEAVE === true);
      verify(fixture.errors.length === 0, `${name}: no uncaught browser errors: ${fixture.errors.join('; ')}`);
      verify(fixture.remote.length === 0, `${name}: every network request stayed on loopback`);
      await fixture.context.close();
    }
    for (const name of ['head', 'async', 'defer', 'end', 'dynamic', 'third-origin', 'duplicate', 'frozen-console']) {
      const f = await fixture(name);
      const first = await waitForRecord(name, record => record.receiver === 'stat4/collect.php' && record.data.type === 'pageview');
      await waitForRecord(name, record => record.receiver === 'pixl_collect.php' && record.data.reason === 'VISIT');
      if (name === 'head') verify(first.data.level === 0, 'head: initial pageview has zero scroll before body parsing');
      verify(records(name).filter(r => r.receiver === 'stat4/collect.php' && r.data.type === 'pageview').length === 1, `${name}: one initial STAT4 pageview`);
      verify(first.origin === externalOrigin, `${name}: browser supplied actual external Origin`);
      await finish(name, f);
      console.log(`PASS external include: ${name}`);
    }

    const iphone = { userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1', isMobile: true, hasTouch: true, deviceScaleFactor: 3 };
    for (const [name, contextOptions, excluded] of [
      ['fingerprint-mac-de', { locale: 'de-DE', screen: { width: 2560, height: 1440 } }, true],
      ['fingerprint-mac-fr', { locale: 'fr-FR', screen: { width: 2560, height: 1440 } }, false],
      ['fingerprint-mac-other-screen', { locale: 'de-DE', screen: { width: 1920, height: 1080 } }, false],
      ['fingerprint-iphone-de', { ...iphone, locale: 'de-DE', viewport: { width: 390, height: 844 } }, true],
      ['fingerprint-iphone-landscape', { ...iphone, locale: 'de-DE', viewport: { width: 844, height: 390 } }, true],
      ['fingerprint-iphone-fr', { ...iphone, locale: 'fr-FR', viewport: { width: 390, height: 844 } }, false]
    ]) {
      const f = await fixture(name, contextOptions);
      await waitForRecord(name, r => r.receiver === 'stat4/collect.php' && r.data.type === 'pageview');
      await waitForRecord(name, r => r.receiver === 'captcha.php');
      if (!excluded) await waitForRecord(name, r => r.receiver === 'pixl_collect.php' && r.data.reason === 'VISIT');
      await delay(250);
      verify(records(name).some(r => r.receiver === 'pixl_collect.php') === !excluded, `${name}: configured Stats3 exclusion`);
      await finish(name, f, !excluded);
      verify(records(name).some(r => r.receiver === 'pixl_collect.php') === !excluded, `${name}: exclusion also holds on navigation`);
      console.log(`PASS external fingerprint: ${name}`);
    }

    {
      const name = 'privacy', f = await fixture(name);
      await waitForRecord(name, r => r.receiver === 'stat4/collect.php');
      await f.page.locator('#password').click(); await f.page.locator('#notes').click();
      await f.page.locator('#link').evaluate(element => { element.addEventListener('click', event => event.preventDefault()); element.click(); });
      await waitForRecord(name, r => r.receiver === 'stat4/collect.php' && r.data.type === 'click' && r.data.target?.includes('utm_source=target'));
      await finish(name, f);
      const serialized = JSON.stringify(records(name));
      for (const secret of ['SYNTHETIC_PASSWORD', 'SYNTHETIC_TEXTAREA', 'SYNTHETIC_LINK_TOKEN', 'SYNTHETIC_FRAGMENT_TOKEN']) verify(!serialized.includes(secret), `privacy: ${secret} not transmitted`);
      verify(records(name).some(r => r.receiver === 'stat4/collect.php' && r.data.target === 'input:password'), 'privacy: password click uses generic metadata');
      console.log('PASS external privacy: password, textarea, link and page fragments across all receivers');
    }
    {
      const name = 'final-only', f = await fixture(name);
      await waitForRecord(name, r => r.receiver === 'stat4/collect.php'); await delay(400);
      verify(!records(name).some(r => r.receiver === 'pixl_collect.php'), 'final-only: no intermediate Stats3 submission');
      await finish(name, f);
      const summaries = records(name).filter(r => r.receiver === 'pixl_collect.php');
      verify(summaries.length === 1 && summaries[0].data.reason === 'CUSTOM-END', 'final-only: exactly one custom summary on actual navigation');
      console.log('PASS external final-only custom summary');
    }
    {
      const name = 'stat4-retry', f = await fixture(name);
      const failed = await waitForRecord(name, r => r.receiver === 'stat4/collect.php' && r.status === 503);
      const retried = await waitForRecord(name, r => r.receiver === 'stat4/collect.php' && r.status === 200 && r.data.eventId === failed.data.eventId, 6500);
      verify(failed.data.activeSeconds > 0 && failed.data.activeSeconds === retried.data.activeSeconds, 'STAT4 retry keeps original activity seconds');
      verify(JSON.stringify(failed.data) === JSON.stringify(retried.data), 'STAT4 retries same immutable payload after actual HTTP503');
      await finish(name, f);
      console.log('PASS external STAT4 HTTP503 retry: immutable UUID/payload and retained seconds');
    }
    {
      const name = 'captcha-csp', f = await fixture(name);
      await f.page.waitForSelector('#swipe-gate-range');
      verify(await f.page.locator('#swipe-gate').evaluate(element => getComputedStyle(element).position) === 'fixed', 'CSP: CAPTCHA remains a fixed overlay');
      verify(await f.page.locator('script[src*="captcha.js"]').evaluate(element => element.nonce) === 'fixture-nonce', 'CSP: count include nonce reaches dynamic CAPTCHA script');
      verify(await f.page.locator('#swipe-gate-style').evaluate(element => element.nonce) === 'fixture-nonce', 'CSP: script nonce reaches CAPTCHA stylesheet');
      await f.page.locator('#swipe-gate-range').evaluate(element => { element.value = '0'; element.dispatchEvent(new Event('change')); });
      const failure = await waitForRecord(name, r => r.receiver === 'captcha.php' && r.data.action === 'fail');
      await f.page.locator('#swipe-gate-range').evaluate(element => { element.value = '52'; element.dispatchEvent(new Event('change')); });
      const verified = await waitForRecord(name, r => r.receiver === 'captcha.php' && r.data.action === 'verify');
      await f.page.waitForSelector('#swipe-gate', { state: 'detached' });
      verify(failure.data.failedAttempts === 1 && verified.data.failedAttempts === 1 && failure.data.attemptSource === verified.data.attemptSource, 'CAPTCHA failure and verify retain source and cumulative attempt count');
      verify(!JSON.stringify(records(name).filter(r => r.receiver === 'captcha.php')).includes('SYNTHETIC_FRAGMENT_TOKEN'), 'CAPTCHA never transmits page fragments');
      verify(!f.diagnostics.some(message => /Refused|violates.*directive|Content Security Policy/i.test(message)), 'CSP: no script/style policy violation');
      await finish(name, f);
      console.log('PASS integrated CAPTCHA: real puzzle failure/verification and nonce-only CSP');
    }
    for (const name of ['cors-denied', 'config-503', 'config-malformed', 'config-timeout']) {
      const f = await fixture(name);
      await f.page.waitForFunction(() => window.__pixlCountConfigStarted === false, null, { timeout: 6000 });
      verify(records(name).length === 0, `${name}: failed configuration starts no collector or CAPTCHA`);
      verify(f.diagnostics.some(message => message.includes('configuration could not be loaded')), `${name}: startup failure is diagnosed`);
      if (name === 'cors-denied') verify(f.diagnostics.some(message => /CORS|Access-Control-Allow-Origin/i.test(message)), 'CORS denial is enforced by native browser networking');
      await finish(name, f, false);
      console.log(`PASS external configuration failure: ${name}`);
    }
    console.log(`PASS complete external browser fixture: ${checks} assertions; unchanged count.js; three loopback origins; no production data`);
  } finally {
    if (browser) await browser.close();
    if (central) { central.kill(); await new Promise(resolve => central.exitCode !== null ? resolve() : central.once('exit', resolve)); }
    await Promise.all([external, third].filter(Boolean).map(close));
    fs.rmSync(temp, { recursive: true, force: true });
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
