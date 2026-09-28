#!/usr/bin/env node
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || '/Users/christianbayer/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const base = process.env.UA_TEST_BASE_URL, cookie = process.env.UA_TEST_COOKIE, artifacts = process.env.UA_TEST_ARTIFACTS;
if (!base || !cookie || !artifacts) throw Error('Run through PIXL_USERAGENTS_TEST_BROWSER=1 php tests/useragents_test.php');
fs.mkdirSync(artifacts, { recursive: true });
let checks = 0;
const verify = (ok, label) => { assert.ok(ok, label); checks++; };
(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge', headless: true,
    args: ['--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1, EXCLUDE localhost'] });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' });
    const separator = cookie.indexOf('=');
    await context.addCookies([{ name: cookie.slice(0, separator), value: cookie.slice(separator + 1), url: base }]);
    const page = await context.newPage(); const errors = [], requests = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => { if (request.url().includes('data=')) requests.push(request.url()); });
    await page.goto(base + '/live.php?view=useragents', { waitUntil: 'networkidle' });
    await page.waitForFunction(() => document.getElementById('connectionStatus').textContent === 'Auswertung aktuell');
    verify(await page.title() === 'UA · UserAgents' && await page.locator('.areas a[aria-current="page"]').innerText() === 'UserAgents', 'UserAgents area is active');
    verify(await page.locator('script[src="live.js"]').count() === 0 && !requests.some(url => new URL(url).searchParams.get('data') === '1'), 'report does not start the Live polling loop');
    const select = async (campaign, unknown = false) => {
      await page.locator(unknown ? '#agentUnknownRows' : '#agentCampaignRows').getByRole('button', { name: campaign, exact: true }).click();
      await page.waitForFunction(() => document.getElementById('agentDetails').getAttribute('aria-busy') === 'false');
      verify(await page.locator('#agentDetailTitle').innerText() === campaign, 'campaign detail selection: ' + campaign);
    };
    const dominant = page.locator('#agentCampaignRows tr').filter({ has: page.getByRole('button', { name: 'Dominant', exact: true }) });
    verify((await dominant.innerText()).includes('19,1 %') && (await dominant.innerText()).includes('90,0 %'), 'weighted score and dominant share render correctly');
    verify((await dominant.locator('.ua-sources').innerText()).includes('alpha') && (await dominant.locator('.ua-sources').innerText()).includes('beta'), 'merged campaign lists both sources');
    await select('Dominant');
    verify(await page.locator('#agentDetailRows tr').count() === 11 && await page.locator('#agentDetailRows tr').first().locator('td').nth(1).innerText() === '90', 'full UserAgent frequencies come from the real API');
    const unknownRow = page.locator('#agentUnknownRows tr').filter({ has: page.getByRole('button', { name: 'Dominant', exact: true }) });
    verify((await unknownRow.innerText()).includes('20 · 20,0 %') && (await unknownRow.innerText()).includes('15 · 15,0 %') && (await unknownRow.innerText()).includes('10 · 10,0 %') && (await unknownRow.innerText()).includes('25 · 25,0 %'), 'Unknown overview displays separate counts, percentages, intersection and union');
    await select('Dominant', true);
    verify(await page.locator('#agentUnknownOnly').isChecked() && await page.locator('#agentDetailRows tr').count() === 1 && (await page.locator('#agentDetailSummary').innerText()).includes('25 Aufrufe'), 'Unknown shortcut filters matching pageviews');
    await page.locator('#agentCampaignSearch').fill('beta');
    verify(await page.locator('#agentCampaignRows tr').count() === 1 && await page.locator('#agentUnknownRows tr').count() === 1, 'campaign search also finds sources and filters both overviews');
    await page.locator('#agentCampaignSearch').fill('not-a-campaign');
    verify((await page.locator('#agentCampaignRows').innerText()).includes('Keine Kampagne'), 'campaign search empty state');
    await page.locator('#agentCampaignSearch').fill('');
    await page.locator('#agentSort').selectOption('score_desc');
    verify((await page.locator('#agentCampaignRows tr').first().locator('.ua-score').innerText()).includes('100,0 %'), 'highest score sort');
    verify((await page.locator('#agentCampaignRows tr').last().locator('.ua-score').innerText()).includes('Mindestens 2'), 'missing scores sort last');
    await page.locator('#agentSort').selectOption('score_asc');
    verify((await page.locator('#agentCampaignRows tr').first().locator('.ua-score').innerText()).startsWith('0,0 %'), 'lowest score sort');
    await select('Unique');
    const first = await page.locator('#agentDetailRows code').allTextContents();
    verify(first.length === 50 && !await page.locator('#agentNext').isDisabled(), 'first 50 agents and pagination');
    await page.locator('#agentNext').click();
    await page.waitForFunction(() => document.getElementById('agentPage').textContent === 'Seite 2 / 2');
    const second = await page.locator('#agentDetailRows code').allTextContents();
    verify(second.length === 50 && new Set([...first, ...second]).size === 100, 'second page covers remaining agents without duplicates');
    await page.locator('#agentSearch').fill('Unique Agent/9'); await page.locator('#agentDetailFilters button').click();
    await page.waitForFunction(() => document.getElementById('agentDetailRows').querySelectorAll('code').length === 11);
    verify((await page.locator('#agentDetailCount').innerText()).includes('11 von 11'), 'UserAgent search resets pagination');
    await select('Balanced', true);
    verify((await page.locator('#agentDetailRows').innerText()).includes('Keine UserAgents'), 'empty Unknown filter');
    await select('Missing');
    verify(await page.locator('#agentDetailRows code').innerText() === '(UserAgent fehlt)', 'missing raw UA is labeled separately');
    await page.locator('#agentRange').selectOption('7d');
    await page.waitForFunction(() => new URL(location.href).searchParams.get('range') === '7d' && document.getElementById('connectionStatus').textContent === 'Auswertung aktuell');
    verify(requests.some(url => url.includes('data=useragents') && url.includes('range=7d')), 'range selection refreshes API and bookmark URL');
    const hostile = '<img src=x onerror="window.uaInjected=true">';
    await select(hostile);
    verify(await page.locator('#agentCampaignRows img,#agentDetailRows script,#agentDetailRows img').count() === 0 && !await page.evaluate(() => window.uaInjected), 'campaign and raw UserAgent HTML remains inert text');
    for (const width of [320, 375, 390, 430, 720, 844, 1024, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      const layout = await page.evaluate(() => ({ viewport: innerWidth, scroll: document.documentElement.scrollWidth, detail: document.getElementById('agentDetails').getBoundingClientRect().width }));
      verify(layout.scroll <= layout.viewport + 1 && layout.detail <= layout.viewport, `no document overflow at ${width}px with long raw UA`);
      if (width === 390) {
        await page.locator('#agentDetails').scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(artifacts, 'useragents-mobile-details.png') });
      }
    }
    await select('Dominant'); await page.locator('#agentSort').selectOption('occurrences');
    await page.evaluate(() => scrollTo(0,0));
    await page.screenshot({ path: path.join(artifacts, 'useragents-desktop.png'), fullPage: true });
    await page.locator('#themeToggle').click();
    verify(await page.evaluate(() => document.documentElement.dataset.theme) === 'dark', 'shared dark theme');
    await page.setViewportSize({ width: 390, height: 1000 }); await page.evaluate(() => scrollTo(0,0));
    await page.screenshot({ path: path.join(artifacts, 'useragents-mobile.png') });
    const overviewRoute = '**/live.php?data=useragents&*';
    await page.route(overviewRoute, route => route.fulfill({ status: 503, body: '{}' }));
    await page.locator('#refreshAgents').click(); await page.locator('#errorNotice').waitFor({ state: 'visible' });
    verify((await page.locator('#errorNotice').innerText()).includes('letzte Stand') && await page.locator('#agentCampaignRows tr').count() > 1, 'outage retains and labels last overview');
    await page.unroute(overviewRoute); await page.locator('#refreshAgents').click();
    await page.waitForFunction(() => document.getElementById('errorNotice').hidden && document.getElementById('connectionStatus').textContent === 'Auswertung aktuell');
    verify(true, 'manual refresh recovers after outage');
    const detailRoute = '**/live.php?data=useragent_details&*';
    await page.route(detailRoute, route => route.fulfill({ status: 503, body: '{}' }));
    await select('Repeated');
    verify(await page.locator('#agentDetailError').isVisible() && (await page.locator('#agentDetailRows').innerText()).includes('nicht geladen'), 'detail failure does not show stale rows from another campaign');
    await page.unroute(detailRoute); await page.locator('#agentDetailFilters button').click();
    await page.waitForFunction(() => document.getElementById('agentDetailRows').querySelectorAll('code').length === 1);
    verify(await page.locator('#agentDetailError').isHidden(), 'detail recovery');
    await page.route(detailRoute, async route => {
      if (new URL(route.request().url()).searchParams.get('campaign') === 'Unique') {
        const response = await route.fetch(); await new Promise(resolve => setTimeout(resolve, 500));
        try { await route.fulfill({ response }); } catch (error) { if (!/closed|disposed|intercept|Invalid|aborted/.test(error.message)) throw error; }
      } else await route.continue();
    });
    await page.locator('#agentCampaignRows').getByRole('button', { name: 'Unique', exact: true }).click();
    await select('Dominant'); await page.waitForTimeout(700);
    verify(await page.locator('#agentDetailTitle').innerText() === 'Dominant' && (await page.locator('#agentDetailRows').innerText()).includes('Repeated Agent/1.0'), 'late response cannot overwrite a newer campaign selection');
    await page.unroute(detailRoute);
    await page.route(overviewRoute, route => route.fulfill({ status: 401, body: '{"ok":false}' }));
    await page.locator('#refreshAgents').click();
    await page.waitForFunction(() => document.getElementById('connectionStatus').textContent === 'Anmeldung erforderlich');
    verify((await page.locator('#errorNotice a').getAttribute('href')).includes('view=useragents') && await page.locator('#refreshAgents').isDisabled(), 'expired authentication offers UserAgents login and disables refresh');
    verify(errors.length === 0, 'no browser JavaScript errors: ' + errors.join('; '));
    console.log(`PASS UserAgents browser: ${checks} checks; real API, details, scores, Unknown, search, pagination, races, auth, 8 widths, XSS and dark mode`);
    console.log('Screenshots: ' + artifacts);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
