"use strict";

// Real Configurator saves and count.js -> CAPTCHA -> MySQL checks in a disposable
// copy. No production configuration is loaded or overwritten by this fixture.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const crypto = require('node:crypto');
const { spawn, execFileSync } = require('node:child_process');

async function main() {
  const dsn = process.env.PIXL_CAPTCHA_TEST_DSN || '';
  if (!dsn) { console.log('SKIP Configurator/CAPTCHA browser: an isolated PIXL_CAPTCHA_TEST_DSN is required'); return; }
  const parsed = /^mysql:unix_socket=([^;]+);dbname=(pixl_captcha_test_[A-Za-z0-9_]+)(?:;charset=utf8mb4)?$/.exec(dsn);
  assert.ok(parsed, 'only a disposable Unix-socket database is accepted');
  const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
  const php = process.env.PHP_BIN || 'php';
  const root = path.dirname(__dirname);
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'stats3-configurator-captcha-'));
  fs.chmodSync(directory, 0o700);
  let server, browser;
  let checks = 0;
  const check = (condition, label) => { assert.ok(condition, label); checks++; };
  try {
    for (const filename of ['configurator.php', 'pixl_server.php', 'pixl_geoip.php', 'pixl_captcha.php', 'captcha.php', 'captcha.js', 'captcha.css', 'count.js', 'pixl_schema.sql', 'stats.php', 'stats-mobile.css']) {
      fs.copyFileSync(path.join(root, filename), path.join(directory, filename));
    }
    fs.mkdirSync(path.join(directory, 'sessions'));
    const probe = net.createServer();
    await new Promise((resolve, reject) => { probe.once('error', reject); probe.listen(0, '127.0.0.1', resolve); });
    const port = probe.address().port;
    await new Promise(resolve => probe.close(resolve));
    const origin = `http://127.0.0.1:${port}`;
    const configPath = path.join(directory, 'pixl_config.php');
    const writeConfig = config => fs.writeFileSync(configPath, `<?php return json_decode(base64_decode('${Buffer.from(JSON.stringify(config)).toString('base64')}'), true, 512, JSON_THROW_ON_ERROR);\n`, { mode: 0o600 });
    const readConfig = () => JSON.parse(execFileSync(php, ['-r', 'echo json_encode(require $argv[1], JSON_THROW_ON_ERROR);', configPath], { encoding: 'utf8' }));
    const config = JSON.parse(execFileSync(php, ['-r', 'echo json_encode(require $argv[1], JSON_THROW_ON_ERROR);', path.join(root, 'pixl_config.example.php')], { encoding: 'utf8' }));
    config.db = { socket: parsed[1], host: 'localhost', database: parsed[2], user: process.env.PIXL_CAPTCHA_TEST_USER || 'root', password: 'configurator-fixture-only', charset: 'utf8mb4', timeout: 5 };
    config.stats_password = 'configurator-fixture-password';
    config.hash_salt = 'configurator-fixture-salt-long-enough';
    config.stats_cookie_name = 'pixl_stats_login';
    config.allowed_hosts = ['127.0.0.1'];
    config.geoip = { enabled: false };
    config.pushover = { enabled: false };
    config.captcha = { enabled: true, visitor_interval: 1, success_target: 1 };
    writeConfig(config);
    const publicConfig = fs.readFileSync(path.join(root, 'configurator2.php'), 'utf8');
    const overrides = `$config['SQL_ENDPOINT'] = '${origin}/pixl_collect.php';\n$config['SQL_PUBLIC_KEY'] = '';\n$config['STAT4']['ENDPOINT'] = '${origin}/stat4/collect.php';\n`;
    check(publicConfig.includes("header('Content-Type:"), 'public fixture override point exists');
    fs.writeFileSync(path.join(directory, 'configurator2.php'), publicConfig.replace("header('Content-Type:", overrides + "header('Content-Type:"));
    fs.writeFileSync(path.join(directory, 'fixture.php'), `<?php
require __DIR__ . '/pixl_captcha.php';
$pdo = pixl_pdo();
if (!str_starts_with((string)$pdo->query('SELECT DATABASE()')->fetchColumn(), 'pixl_captcha_test_')) throw new RuntimeException('Disposable DB required');
pixl_captcha_ensure_schema($pdo);
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--expire-phase') {
  $started = time() - pixl_captcha_settings()['max_duration_hours'] * 3600;
  $pdo->prepare('UPDATE pixl_captcha_state SET phase_started_at=? WHERE id=1')->execute([$started]);
} elseif (PHP_SAPI === 'cli') {
  foreach (['pixl_captcha_page_views', 'pixl_captcha_visitors', 'pixl_captcha_stats', 'pixl_captcha_state'] as $table) $pdo->exec('DELETE FROM ' . $table);
  pixl_captcha_ensure_schema($pdo);
} else { header('Content-Type: application/json'); echo json_encode(pixl_captcha_summary($pdo, pixl_captcha_settings()), JSON_THROW_ON_ERROR); }
`);
    fs.writeFileSync(path.join(directory, 'router.php'), `<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (in_array($path, ['/pixl_collect.php', '/stat4/collect.php'], true)) { header('Content-Type: application/json'); echo '{"ok":true}'; return; }
if (in_array($path, ['/landing.html', '/other.html', '/article.html', '/second'], true)) {
  header('Content-Type: text/html; charset=utf-8');
  echo '<!doctype html><html><head><meta charset="utf-8"><title>Captcha landing fixture</title></head><body><h1>Landing fixture</h1><p>Local disposable test page.</p><script src="/count.js" data-config-url="/configurator2.php"></script></body></html>'; return;
}
if (in_array($path, ['/pixl_stats.php', '/stat/checkthis.php', '/stat/dashboardx2.html'], true)) { echo '<!doctype html><title>Unrelated report placeholder</title>'; return; }
return false;
`);
    server = spawn(php, ['-d', 'opcache.enable=0', '-d', `session.save_path=${directory}/sessions`, '-S', `127.0.0.1:${port}`, path.join(directory, 'router.php')], { cwd: directory, stdio: ['ignore', 'pipe', 'pipe'] });
    await new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('Fixture startup timeout')), 5000);
      server.once('error', error => { clearTimeout(timer); reject(error); });
      server.stderr.on('data', data => { if (String(data).includes('Development Server')) { clearTimeout(timer); resolve(); } });
    });
    const edge = '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge';
    const executablePath = process.env.BROWSER_EXECUTABLE || (fs.existsSync(edge) ? edge : undefined);
    browser = await chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}), args: ['--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1, EXCLUDE localhost'] });
    const context = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
    await context.addCookies([{ name: config.stats_cookie_name, value: crypto.createHmac('sha256', config.hash_salt).update('pixl-stats|' + config.stats_password).digest('hex'), url: origin }]);
    const page = await context.newPage();
    const pageErrors = [];
    page.on('pageerror', error => pageErrors.push(error.message));
    await page.goto(origin + '/configurator.php');
    const section = page.locator('section[aria-labelledby="captcha-title"]');
    check(await page.getByLabel('Besucher Seiten Ansichten', { exact: true }).inputValue() === '0', 'new threshold defaults to zero');
    check(await page.getByLabel('Captcha/Landing Urls', { exact: true }).inputValue() === '', 'new landing list defaults to empty');
    const duration = page.getByLabel('Maximale Captcha-Dauer (Stunden)', { exact: true });
    check(await duration.inputValue() === '4', 'existing configuration without an hours setting defaults to four hours');
    await duration.fill('2');
    await page.getByLabel('Besucher Seiten Ansichten', { exact: true }).fill('4');
    await page.getByLabel('Captcha/Landing Urls', { exact: true }).fill(`${origin}/landing.html?utm_source=test\n/second\n${origin}/landing.html#part\n`);
    const submit = async () => { await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.getByRole('button', { name: 'Konfiguration speichern' }).click()]); };
    await submit();
    check(page.url().endsWith('saved=1'), 'valid settings save successfully');
    const saved = readConfig();
    check(saved.captcha.page_view_interval === 4 && saved.captcha.landing_urls.join('\n') === `${origin}/landing.html\n/second`, 'saved settings normalize URLs and remove duplicates');
    check(saved.captcha.revision.length === 32, 'changed settings receive a fresh revision');
    check(saved.captcha.max_duration_hours === 2 && await duration.inputValue() === '2', 'hours value is saved and restored after reloading');
    check(saved.db.password === config.db.password && saved.hash_salt === config.hash_salt && saved.stats_password === config.stats_password, 'saving preserves existing fixture secrets');
    await submit();
    check(readConfig().captcha.revision === saved.captcha.revision, 'unchanged form does not restart the cycle');
    await duration.fill('3');
    await submit();
    const durationOnly = readConfig();
    check(durationOnly.captcha.max_duration_hours === 3 && durationOnly.captcha.revision !== saved.captcha.revision, 'changing only the hours limit starts a fresh configuration cycle');
    Object.assign(saved, durationOnly);
    await submit();
    check(readConfig().captcha.revision === saved.captcha.revision, 'unchanged hours do not restart the cycle');
    for (const value of ['0', '-1', '1.5', '8761', '']) {
      const before = fs.readFileSync(configPath, 'utf8');
      await page.evaluate(value => { document.querySelector('.config-form').noValidate = true; document.querySelector('#captcha_max_duration_hours').value = value; }, value);
      await submit();
      check((await page.textContent('body')).includes('Die Captcha-Stundenbegrenzung muss eine ganze Zahl'), 'server rejects invalid hours ' + JSON.stringify(value));
      check(fs.readFileSync(configPath, 'utf8') === before, 'invalid hours cannot modify the configuration');
      await page.goto(origin + '/configurator.php');
    }
    for (const value of ['-1', '1.5', '1000001']) {
      const before = fs.readFileSync(configPath, 'utf8');
      await page.evaluate(value => { document.querySelector('.config-form').noValidate = true; document.querySelector('#captcha_page_view_interval').value = value; }, value);
      await submit();
      check((await page.textContent('body')).includes('Besucher Seiten Ansichten muss eine ganze Zahl'), 'server rejects invalid page threshold ' + value);
      check(fs.readFileSync(configPath, 'utf8') === before, 'invalid threshold cannot modify configuration');
      await page.goto(origin + '/configurator.php');
    }
    const beforeInvalidUrl = fs.readFileSync(configPath, 'utf8');
    await page.getByLabel('Captcha/Landing Urls', { exact: true }).fill('javascript:alert(1)\n<img src=x onerror=alert(1)>');
    await submit();
    check((await page.textContent('body')).includes('Ungueltige Captcha/Landing URL'), 'invalid URL has a visible validation error');
    check(fs.readFileSync(configPath, 'utf8') === beforeInvalidUrl && await section.locator('img').count() === 0, 'invalid URL is escaped and cannot overwrite settings');
    await page.goto(origin + '/configurator.php');
    const visitorBox = await page.locator('#captcha_visitor_interval').boundingBox();
    const pageBox = await page.locator('#captcha_page_view_interval').boundingBox();
    check(visitorBox.y === pageBox.y && visitorBox.height === pageBox.height, 'desktop threshold inputs remain aligned at equal heights');
    const artifacts = process.env.TEST_ARTIFACT_DIR;
    if (artifacts) { fs.mkdirSync(artifacts, { recursive: true }); await section.screenshot({ path: path.join(artifacts, 'captcha-configurator-desktop.png') }); }
    await page.setViewportSize({ width: 390, height: 844 });
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'mobile Configurator has no horizontal overflow');
    if (artifacts) await section.screenshot({ path: path.join(artifacts, 'captcha-configurator-mobile.png') });
    check(pageErrors.length === 0, 'Configurator renders without JavaScript errors');

    // Switch only the fixture password to the test server's actual credential.
    saved.db.password = process.env.PIXL_CAPTCHA_TEST_PASSWORD || '';
    writeConfig(saved);
    execFileSync(php, [path.join(directory, 'fixture.php')], { stdio: 'pipe' });
    const seed = async (url, visitor) => (await fetch(origin + '/captcha.php', { method: 'POST', headers: { Origin: origin, 'User-Agent': visitor, 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'check', url: origin + url }) })).json();
    await seed('/landing.html', 'seed-a');
    await seed('/article.html', 'seed-a');
    check(!(await seed('/landing.html', 'seed-b')).active, 'real endpoint waits while only three of four pages have been seen');
    await page.goto(origin + '/stats.php');
    check((await page.locator('#captcha').textContent()).includes('3 / 4'), 'dashboard visibly shows the page threshold progress');
    check((await page.locator('#captcha').textContent()).includes('Seiten-Schwellwert ist noch offen'), 'dashboard explains why the time estimate is unavailable');
    if (artifacts) await page.locator('#captcha').screenshot({ path: path.join(artifacts, 'captcha-dashboard.png'), style: '.topbar { visibility: hidden; }' });
    const visitorContext = await browser.newContext({ userAgent: 'Mozilla/5.0 CaptchaLandingFixture', viewport: { width: 1024, height: 768 } });
    const visitor = await visitorContext.newPage();
    const navigate = async url => {
      const response = visitor.waitForResponse(r => r.url().endsWith('/captcha.php') && r.request().postDataJSON().action === 'check');
      await visitor.goto(origin + url);
      return (await response).json();
    };
    const deferred = await navigate('/other.html');
    check(deferred.active && !deferred.required && await visitor.locator('#swipe-gate').count() === 0, 'unchanged count.js displays no CAPTCHA outside the landing list');
    const ticket = await navigate('/landing.html?utm_source=browser');
    await visitor.locator('#swipe-gate').waitFor({ state: 'visible' });
    check(ticket.required, 'same new browser receives the CAPTCHA after navigating to its allowed landing URL');
    const excluded = await navigate('/other.html');
    check(!excluded.required && await visitor.locator('#swipe-gate').count() === 0, 'issued ticket does not display on a later excluded page');
    const resumed = await navigate('/second');
    await visitor.locator('#swipe-gate').waitFor({ state: 'visible' });
    check(resumed.token === ticket.token, 'second allowed landing reuses the same CAPTCHA ticket');
    execFileSync(php, [path.join(directory, 'fixture.php'), '--expire-phase'], { stdio: 'pipe' });
    const timeoutStatus = visitor.waitForResponse(r => r.url().endsWith('/captcha.php') && r.request().postDataJSON().action === 'status', { timeout: 25000 });
    const timeoutData = await (await timeoutStatus).json();
    await visitor.locator('#swipe-gate').waitFor({ state: 'detached', timeout: 5000 });
    check(timeoutData.ok && !timeoutData.required && !timeoutData.active && !(await visitor.locator('html').getAttribute('class') || '').includes('swipe-gate-locked'), 'existing count.js polling automatically releases the gate after the configured hours');
    const afterTimeout = await (await fetch(origin + '/fixture.php')).json();
    check(afterTimeout.waiting_page_views === 0 && afterTimeout.remaining_page_views === 4 && afterTimeout.remaining_visitors === 2 && afterTimeout.last_blocked_visitors === 1, 'expired phase resets both counters and retains its unresolved visitor result');
    console.log(`PASS Configurator/CAPTCHA browser (${checks} checks): hours saving and validation, desktop/mobile layout, dashboard progress, real count.js/HTTP/MySQL landing flow and automatic timed release`);
  } finally {
    if (browser) await browser.close();
    if (server && server.exitCode === null) { const exited = new Promise(resolve => server.once('exit', resolve)); server.kill(); await exited; }
    fs.rmSync(directory, { recursive: true, force: true });
  }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
