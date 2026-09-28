#!/usr/bin/env node
'use strict';

// Test a disposable copy over HTTP. No database, live collector or notifications.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const { spawn } = require('node:child_process');

(async () => {
  const root = path.dirname(__dirname);
  const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'stats3-config-http-'));
  const endpoint = path.join(temporary, 'configurator2.php');
  fs.copyFileSync(path.join(root, 'configurator2.php'), endpoint);
  fs.copyFileSync(path.join(root, 'count.js'), path.join(temporary, 'count.js'));
  fs.copyFileSync(path.join(root, 'captcha.js'), path.join(temporary, 'captcha.js'));
  fs.copyFileSync(path.join(root, 'captcha.css'), path.join(temporary, 'captcha.css'));
  const probe = net.createServer();
  await new Promise((resolve, reject) => { probe.once('error', reject); probe.listen(0, '127.0.0.1', resolve); });
  const port = probe.address().port;
  await new Promise(resolve => probe.close(resolve));
  const server = spawn(process.env.PHP_BIN || 'php', ['-S', `127.0.0.1:${port}`, '-t', temporary], { stdio: ['ignore', 'pipe', 'pipe'] });
  const cleanup = () => { server.kill(); fs.rmSync(temporary, { recursive: true, force: true }); };
  process.once('SIGTERM', () => { cleanup(); process.exit(0); });
  process.once('SIGINT', () => { cleanup(); process.exit(0); });
  try {
    await new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('PHP test server did not start')), 5000);
      server.once('error', error => { clearTimeout(timer); reject(error); });
      server.once('exit', code => { clearTimeout(timer); reject(new Error(`PHP test server exited: ${code}`)); });
      server.stderr.on('data', data => {
        if (String(data).includes('Development Server')) { clearTimeout(timer); resolve(); }
      });
    });
    const base = `http://127.0.0.1:${port}`;
    let checks = 0;
    const verify = (condition, label) => { assert.ok(condition, label); checks++; };
    const get = (origin, method = 'GET') => fetch(`${base}/configurator2.php`, {
      method, headers: origin === undefined ? {} : { Origin: origin }
    });
    const response = await get();
    const data = await response.json();
    verify(response.status === 200 && data.ok === true && data.schemaVersion === 1, 'valid public JSON response');
    verify(response.headers.get('content-type').includes('application/json'), 'JSON MIME type');
    verify(response.headers.get('cache-control') === 'no-store', 'new settings must not be cached');
    verify(response.headers.get('x-content-type-options') === 'nosniff', 'JSON is not executable JavaScript');
    verify(data.config.SQL_ENDPOINT === 'https://www.bayerchristian.de/stats3/pixl_collect.php', 'Stats3 endpoint preserved');
    verify(data.config.STAT4.ENDPOINT === 'https://www.bayerchristian.de/stats3/stat4/collect.php', 'STAT4 endpoint preserved');
    verify(Array.isArray(data.config.KNOWN_RESOLUTIONS), 'Set serialized as JSON array');
    verify(!('db' in data.config) && !('pushover' in data.config) && !('stats_password' in data.config), 'only public client configuration');
    for (const origin of ['https://www.inconsequential.org', 'https://inconsequential.org', 'https://www.bayerchristian.de', 'https://sub.inconsequential.org', 'http://localhost:8080', 'http://127.0.0.1:8000']) {
      const result = await get(origin);
      verify(result.status === 200 && result.headers.get('access-control-allow-origin') === origin, `CORS accepts ${origin}`);
      verify(!(result.headers.has('access-control-allow-credentials')), 'no cross-origin credentials');
    }
    for (const origin of ['https://inconsequential.org.evil.example', 'https://evilinconsequential.org', 'null', 'file://localhost', 'https://user@www.inconsequential.org', 'https://www.inconsequential.org/path']) {
      const result = await get(origin);
      verify(result.status === 403 && !result.headers.has('access-control-allow-origin'), `CORS rejects ${origin}`);
      verify(!(await result.json()).config, 'denied response omits configuration');
    }
    const options = await get('https://www.inconsequential.org', 'OPTIONS');
    verify(options.status === 204 && (await options.text()) === '', 'valid preflight');
    verify(options.headers.get('access-control-allow-methods') === 'GET, OPTIONS', 'read-only endpoint');
    const post = await get('https://www.inconsequential.org', 'POST');
    verify(post.status === 405 && (await post.json()).error === 'GET required', 'POST cannot change settings');
    console.log(`PASS configurator2.php HTTP: ${checks} checks (JSON, defaults, CORS, methods, public settings).`);

    if (process.argv.includes('--serve')) {
      // Same PHP fixture, two different hosts: the browser performs real CORS.
      const central = `http://localhost:${port}`;
      fs.writeFileSync(endpoint, fs.readFileSync(endpoint, 'utf8')
        .replaceAll('https://www.bayerchristian.de/stats3/', central + '/'));
      fs.mkdirSync(path.join(temporary, 'stat4'));
      const mock = `<?php\nheader('Content-Type: application/json');\nheader('Access-Control-Allow-Origin: ${base}');\nheader('Access-Control-Allow-Methods: POST, OPTIONS');\nheader('Access-Control-Allow-Headers: Content-Type');\nif ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }\n$input = json_decode(file_get_contents('php://input'), true);\nfile_put_contents(__DIR__ . '/received.jsonl', json_encode($input) . "\\n", FILE_APPEND);\necho json_encode(['ok' => true]);\n`;
      fs.writeFileSync(path.join(temporary, 'pixl_collect.php'), mock);
      fs.writeFileSync(path.join(temporary, 'stat4', 'collect.php'), mock);
      fs.writeFileSync(path.join(temporary, 'captcha.php'), `<?php\nheader('Content-Type: application/json');\nheader('Access-Control-Allow-Origin: ${base}');\necho json_encode(['ok' => true, 'active' => true, 'required' => true, 'verified' => false, 'token' => str_repeat('a', 64)]);\n`);
      fs.writeFileSync(path.join(temporary, 'fixture.html'), `<!doctype html><html lang="en"><meta charset="utf-8"><title>Stats3 remote configuration test</title><script src="count.js" data-config-url="${central}/configurator2.php" defer></script><h1>Stats3 remote configuration test</h1><p>Local test with mock collectors. No production requests.</p></html>`);
      console.log(`BROWSER_FIXTURE=${base}/fixture.html`);
      console.log(`FIXTURE_DIRECTORY=${temporary}`);
      await new Promise(resolve => server.once('exit', resolve));
    }
  } finally { cleanup(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
