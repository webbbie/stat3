#!/usr/bin/env node
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const playwright = process.env.PLAYWRIGHT_MODULE || '/Users/christianbayer/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright';
const { chromium } = require(playwright);
const base = process.env.UA_TEST_BASE_URL;
if (!base || !process.env.UA_TEST_SNAPSHOT || !process.env.UA_TEST_COOKIE) throw Error('Run through PIXL_LIVE_TEST_BROWSER=1 php tests/live_test.php');
const clone = value => JSON.parse(JSON.stringify(value));
const original = JSON.parse(fs.readFileSync(process.env.UA_TEST_SNAPSHOT, 'utf8'));
const artifacts = process.env.UA_TEST_ARTIFACTS;
fs.mkdirSync(artifacts, { recursive: true });
let checks = 0;
function verify(condition, message) { assert.ok(condition, message); checks++; }

(async () => {
  const executablePath = process.env.CHROME_PATH || '/Applications/Google Chrome Beta.app/Contents/MacOS/Google Chrome Beta';
  const browser = await chromium.launch({ executablePath, headless: true, args: ['--disable-gpu'] });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1150 }, colorScheme: 'light' });
    const separator = process.env.UA_TEST_COOKIE.indexOf('=');
    await context.addCookies([{ name: process.env.UA_TEST_COOKIE.slice(0, separator), value: process.env.UA_TEST_COOKIE.slice(separator + 1), url: base }]);
    const page = await context.newPage();
    const errors = [], requests = [];
    page.on('pageerror', error => errors.push(error.message));
    let mode = 'ok', payload = clone(original), concurrent = 0, maxConcurrent = 0;
    // Initial real server endpoint is checked before transport fixtures exercise the UI.
    const actual = await context.request.get(`${base}/live.php?data=1`);
    verify(actual.status() === 200 && (await actual.json()).ok, 'real authenticated HTTP API works in browser context');
    await page.route('**/live.php?data=1', async route => {
      requests.push(Date.now()); concurrent++; maxConcurrent = Math.max(maxConcurrent, concurrent);
      try {
        if (mode === 'error') return await route.fulfill({ status: 503, contentType: 'application/json', body: '{"ok":false}' });
        if (mode === 'unauthorized') return await route.fulfill({ status: 401, contentType: 'application/json', body: '{"ok":false}' });
        if (mode === 'malformed') return await route.fulfill({ status: 200, contentType: 'application/json', body: '{bad-json' });
        if (mode === 'slow') await new Promise(resolve => setTimeout(resolve, 6200));
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(payload) });
      } catch (error) { if (!/closed|disposed|intercept|Invalid/.test(error.message)) throw error; }
      finally { concurrent--; }
    });
    await page.goto(`${base}/live.php`, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => document.getElementById('connectionStatus').textContent.includes('alle 5 Sekunden'));
    verify(await page.title() === 'UA · Live', 'page title identifies UA Live');
    verify(await page.getByRole('link', { name: 'UserAgents', exact: true }).getAttribute('href') === 'live.php?view=useragents', 'UserAgents is available beside Live');
    verify(await page.locator('#historyChart polyline').count() === 2, 'two real hourly line series');
    verify(await page.locator('#hourlyRows tr').count() === 24, '24 accessible hourly table rows');
    verify(await page.locator('#latestList li').count() === 5, 'exactly five latest impressions');
    verify(await page.locator('#latestList').innerText().then(text => text.includes('http://example.test:8080/fresh?reload=1')), 'full URLs retain protocol, port and query');
    verify(await page.locator('#campaignRows tr').first().locator('td').count() === 12, 'source, campaign and ten metrics in requested order');
    verify(!(await page.locator('#pushLight').getAttribute('class')).includes('flashing'), 'old notifications do not flash on initial load');
    const geometry = await page.locator('#heartbeatChart path').evaluateAll(paths => paths.map(path => path.getBBox().height));
    verify(geometry.length === 2 && geometry[0] > 40 && geometry[1] > 40, 'heartbeat has both upward and downward pulses');
    await page.evaluate(() => {
      window.uaFlashEvents = 0;
      new MutationObserver(changes => changes.forEach(change => {
        if (change.attributeName === 'class' && document.getElementById('pushLight').classList.contains('flashing')) window.uaFlashEvents++;
      })).observe(document.getElementById('pushLight'), { attributes: true });
    });
    payload.recent_pushes.push({ id: 'new-confirmed-1', timestamp: payload.generated_at - 1 }, { id: 'new-confirmed-2', timestamp: payload.generated_at - 1 });
    payload.totals.pushes_day += 2; payload.totals.pushes_24h += 2;
    payload.latest.unshift({ id: 'new-page', timestamp: payload.generated_at - 1, url: 'https://example.test/new?campaign=full&value=1', complete_url: true }); payload.latest.pop();
    await page.waitForFunction(() => document.getElementById('latestList').textContent.includes('/new?campaign=full'), { timeout: 8000 });
    await page.waitForFunction(() => window.uaFlashEvents >= 2, { timeout: 4000 });
    verify(await page.evaluate(() => window.uaFlashEvents) === 2, 'one red flash for each newly confirmed message');
    const expectedRequests = requests.length + 1;
    await page.waitForFunction(() => !document.getElementById('pushLight').classList.contains('flashing'));
    await page.waitForTimeout(5200);
    verify(requests.length >= expectedRequests && requests[1] - requests[0] >= 4400 && requests[1] - requests[0] < 6000, 'polling runs automatically every five seconds');
    verify(await page.evaluate(() => window.uaFlashEvents) === 2, 'same confirmed sends do not flash again on later polls');
    await page.locator('#campaignSearch').fill('beta');
    verify(await page.locator('#campaignRows tr').count() === 1 && (await page.locator('#campaignRows').innerText()).includes('beta'), 'campaign search filters by source');
    await page.locator('#campaignSearch').fill('no-such-campaign');
    verify((await page.locator('#campaignRows').innerText()).includes('Keine Kampagne'), 'search empty state');
    await page.locator('#campaignSearch').fill('');
    await page.locator('#pauseButton').click();
    const pausedCount = requests.length;
    await page.waitForTimeout(5400);
    verify(requests.length === pausedCount, 'pause stops polling');
    await page.locator('#pauseButton').click();
    await page.waitForTimeout(250);
    verify(requests.length === pausedCount + 1, 'resume refreshes immediately');
    mode = 'error';
    await page.waitForFunction(() => !document.getElementById('errorNotice').hidden, { timeout: 7000 });
    verify((await page.locator('#errorNotice').innerText()).includes('letzte Stand') && await page.locator('#latestList li').count() === 5, 'outage labels stale data and retains last real values');
    mode = 'ok';
    await page.waitForFunction(() => document.getElementById('errorNotice').hidden, { timeout: 7000 });
    verify(true, 'automatic recovery after outage');
    // A hidden tab releases its timer and resumes when visible again.
    await page.evaluate(() => {
      Object.defineProperty(document, 'hidden', { configurable: true, value: true });
      document.dispatchEvent(new Event('visibilitychange'));
    });
    const hiddenCount = requests.length;
    await page.waitForTimeout(5300);
    verify(requests.length === hiddenCount, 'hidden tab stops polling');
    await page.evaluate(() => { delete document.hidden; document.dispatchEvent(new Event('visibilitychange')); });
    await page.waitForTimeout(250);
    verify(requests.length === hiddenCount + 1, 'visible tab resumes immediately');
    // Make the visual fixture look like a full day, without fabricating production data.
    payload = clone(original);
    payload.hourly.forEach((row, i) => { row.users = [4, 3, 2, 1, 1, 3, 8, 12, 17, 22, 19, 16, 25, 32, 28, 38, 29, 22, 26, 34, 25, 20, 14, 17][i]; row.impressions = row.users * 3 + [4, 8, 6, 3, 1][i % 5]; });
    payload.totals.users_24h = 284; payload.totals.impressions_24h = 1512; payload.totals.pages_24h = 38; payload.totals.pushes_24h = 24; payload.totals.pushes_day = 18;
    payload.heartbeat.forEach((row, i) => { row.impressions = [0, 1, 0, 2, 1, 0, 3, 1, 0][i % 9]; row.users = i % 6 === 0 ? 1 : 0; });
    payload.campaigns = ['ppcmate', 'google', 'newsletter', 'instagram'].map((source, index) => ({ ...original.campaigns[0], source, campaign: ['September · Discovery', 'Brand Search', 'Wochenrückblick', 'Stories'][index], users_day: 80 - index * 14, impressions_day: 310 - index * 48, users_hour: 9 - index, impressions_hour: 37 - index * 7, pages_day: 12 - index * 2, pushes_day: 6 - index, users_24h: 110 - index * 19, impressions_24h: 487 - index * 53, pages_24h: 17 - index * 2, pushes_24h: 9 - index }));
    const bad = '<img src=x onerror="window.uaInjection=true">';
    payload.latest[0].url = `https://example.test/full?source=${bad}&very_long_parameter=${'long-value-'.repeat(20)}`;
    payload.campaigns.push({ ...payload.campaigns[0], source: bad, campaign: 'Very long campaign '.repeat(12) });
    await page.waitForFunction(() => document.getElementById('totalUsers').textContent === '284', { timeout: 7000 });
    verify(await page.locator('#latestList img,#campaignRows img').count() === 0 && !await page.evaluate(() => window.uaInjection), 'untrusted campaign and URL text cannot execute HTML');
    // Keep fixture data fixed during responsive screenshots.
    await page.locator('#pauseButton').click();
    for (const width of [320, 375, 390, 430, 720, 844, 1024, 1440]) {
      await page.setViewportSize({ width, height: width < 720 ? 1000 : 1150 });
      await page.waitForTimeout(100);
      const layout = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth, svg: document.getElementById('historyChart').getBoundingClientRect().width, rows: document.querySelectorAll('#latestList li').length }));
      verify(layout.scroll <= layout.width + 1 && layout.svg <= layout.width && layout.rows === 5, `no page overflow or clipped URLs at ${width}px`);
      if (width === 390 || width === 1440) await page.screenshot({ path: path.join(artifacts, `ua-live-${width}-light.png`), fullPage: true });
    }
    await page.locator('#themeToggle').click();
    // A clean sample image for review, separate from long-string/XSS QA screenshots.
    payload.latest[0].url = 'https://example.test/entdecken?utm_source=ppcmate&utm_campaign=september';
    payload.campaigns.pop();
    await page.locator('#pauseButton').click();
    await page.waitForFunction(() => document.querySelectorAll('#campaignRows tr').length === 4);
    await page.setViewportSize({ width: 1440, height: 1120 });
    await page.waitForTimeout(200);
    await page.screenshot({ path: path.join(artifacts, 'ua-live-preview.png'), fullPage: true });
    await page.locator('#pauseButton').click();
    await page.setViewportSize({ width: 390, height: 1000 });
    await page.waitForTimeout(200);
    await page.screenshot({ path: path.join(artifacts, 'ua-live-mobile-top.png') });
    await page.evaluate(() => window.scrollTo(0, document.querySelector('.traffic-panel').offsetTop - 12));
    await page.waitForTimeout(100);
    await page.screenshot({ path: path.join(artifacts, 'ua-live-mobile-traffic.png') });
    verify(await page.evaluate(() => document.documentElement.dataset.theme) === 'dark', 'shared dark theme switches correctly');
    await page.setViewportSize({ width: 1440, height: 1150 });
    await page.waitForTimeout(100);
    await page.screenshot({ path: path.join(artifacts, 'ua-live-1440-dark.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 1000 });
    await page.screenshot({ path: path.join(artifacts, 'ua-live-390-dark.png'), fullPage: true });
    await page.locator('#themeToggle').click();
    // Empty statistics are valid; do not turn them into a connection error.
    payload = clone(original); payload.latest = []; payload.campaigns = []; payload.recent_pushes = [];
    Object.keys(payload.totals).forEach(key => { payload.totals[key] = 0; });
    payload.hourly.forEach(row => { row.users = 0; row.impressions = 0; }); payload.heartbeat.forEach(row => { row.users = 0; row.impressions = 0; row.pushes = 0; });
    await page.locator('#pauseButton').click();
    await page.waitForFunction(() => document.getElementById('totalUsers').textContent === '0');
    verify(await page.locator('#historyEmpty').isVisible() && (await page.locator('#latestList').innerText()).includes('Noch keine'), 'empty database renders explicit empty states');
    await page.screenshot({ path: path.join(artifacts, 'ua-live-empty.png'), fullPage: true });
    mode = 'slow';
    await page.waitForTimeout(11300);
    verify(maxConcurrent === 1, 'slow requests never overlap');
    mode = 'unauthorized';
    await page.waitForFunction(() => document.getElementById('connectionStatus').textContent === 'Anmeldung erforderlich', { timeout: 15000 });
    const expiredCount = requests.length;
    await page.waitForTimeout(5400);
    verify(requests.length === expiredCount && await page.locator('#errorNotice a').getAttribute('href') === 'live.php', 'expired session stops polling and offers shared login');
    verify(errors.length === 0, `no browser JavaScript errors: ${errors.join('; ')}`);
    console.log(`PASS UA browser (${checks} checks; 5s polling, pulse directions, exact flashes, failures, search, 8 widths, dark mode, XSS, empty state)`);
    console.log(`Screenshots: ${artifacts}`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
