"use strict";

// Optional real-browser check: PLAYWRIGHT_MODULE and BROWSER_EXECUTABLE may point
// to an existing installation. Both fixture origins stay on loopback; no database
// or analytics receiver is used.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const http = require("node:http");
const path = require("node:path");
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || "playwright");
const root = path.dirname(__dirname);
const listen = server => new Promise(resolve => server.listen(0, "127.0.0.1", resolve));
const close = server => new Promise(resolve => server.close(resolve));

async function main() {
  const central = http.createServer((req, res) => {
    const filename = req.url.split("?")[0].slice(1);
    if (!['captcha.js', 'captcha.css'].includes(filename)) { res.writeHead(404); res.end(); return; }
    res.setHeader("Content-Type", filename.endsWith(".js") ? "text/javascript" : "text/css");
    res.end(fs.readFileSync(path.join(root, filename)));
  });
  await listen(central);
  const centralOrigin = `http://127.0.0.1:${central.address().port}`;
  const external = http.createServer((req, res) => {
    const mode = new URL(req.url, 'http://fixture.test').searchParams.get('mode');
    const stylePolicy = mode === 'nonce' ? "'nonce-captcha-fixture'" : mode === 'blocked' ? "'self'" : centralOrigin;
    res.setHeader("Content-Type", "text/html;charset=utf-8");
    res.setHeader("Content-Security-Policy", `default-src 'none'; script-src 'nonce-captcha-fixture'; style-src ${stylePolicy}`);
    res.end(`<!doctype html><html><head><meta charset="utf-8"><title>CAPTCHA CSP fixture</title></head><body>
      <h1>External host fixture</h1>
      <script nonce="captcha-fixture">window.dismissals=0;window.verified=0;
      window.SwipeGateOptions={rememberForMinutes:0,onDismiss:function(){window.dismissals++},onVerified:function(){window.verified++}};
      crypto.getRandomValues=function(array){array.fill(0);return array};</script>
      <script nonce="captcha-fixture" src="${centralOrigin}/captcha.js"></script>
      </body></html>`);
  });
  await listen(external);
  const externalOrigin = `http://127.0.0.1:${external.address().port}`;
  let browser;
  try {
    browser = await chromium.launch({ headless: true, ...(process.env.BROWSER_EXECUTABLE ? { executablePath: process.env.BROWSER_EXECUTABLE } : {}) });
    for (const mode of ['allowed-host', 'nonce', 'blocked']) {
      const page = await browser.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.goto(`${externalOrigin}/?mode=${mode}`);
      if (mode === 'blocked') {
        await page.waitForFunction(() => window.dismissals === 1);
        assert.equal(await page.locator('#swipe-gate').count(), 0, 'a denied stylesheet does not expose a broken overlay');
        assert.equal(await page.evaluate(() => document.documentElement.classList.contains('swipe-gate-locked')), false);
      } else {
        await page.waitForSelector('#swipe-gate-range');
        assert.equal(await page.locator('#swipe-gate').evaluate(el => getComputedStyle(el).position), 'fixed');
        assert.equal(await page.locator('#swipe-gate-style').evaluate(el => el.nonce), 'captcha-fixture');
        assert.equal(await page.locator('#swipe-gate-style').getAttribute('href'), `${centralOrigin}/captcha.css`);
        await page.locator('#swipe-gate-range').evaluate(el => { el.value = '52'; el.dispatchEvent(new Event('change')); });
        await page.waitForFunction(() => window.verified === 1);
        assert.equal(await page.locator('#swipe-gate').count(), 0);
        assert.equal(await page.evaluate(() => document.documentElement.classList.contains('swipe-gate-locked')), false);
      }
      assert.deepEqual(errors, [], `${mode}: no uncaught browser error`);
      await page.close();
    }
    console.log('PASS CAPTCHA browser CSP: external stylesheet host allowance, nonce-only styles, blocked stylesheet releases page; two loopback origins');
  } finally {
    if (browser) await browser.close();
    await Promise.all([close(central), close(external)]);
  }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
