"use strict";

// Exercise the real JSON endpoint and MySQL contract in a disposable copy.
// An explicit Unix-socket DSN with a pixl_captcha_test_* database is mandatory.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const { spawn, execFileSync } = require('node:child_process');

async function main() {
  const dsn = process.env.PIXL_CAPTCHA_TEST_DSN || '';
  if (!dsn) { console.log('SKIP CAPTCHA HTTP/MySQL: set PIXL_CAPTCHA_TEST_DSN to an isolated pixl_captcha_test_* database'); return; }
  const parsed = /^mysql:unix_socket=([^;]+);dbname=(pixl_captcha_test_[A-Za-z0-9_]+)(?:;charset=utf8mb4)?$/.exec(dsn);
  assert.ok(parsed, 'HTTP fixture requires a Unix socket and a disposable pixl_captcha_test_* database');
  const php = process.env.PHP_BIN || 'php';
  const root = path.dirname(__dirname);
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'stats3-captcha-http-'));
  fs.chmodSync(directory, 0o700);
  let server;
  try {
    for (const filename of ['captcha.php', 'pixl_captcha.php', 'pixl_server.php', 'pixl_geoip.php']) {
      fs.copyFileSync(path.join(root, filename), path.join(directory, filename));
    }
    const config = {
      db: { socket: parsed[1], database: parsed[2], user: process.env.PIXL_CAPTCHA_TEST_USER || 'root', password: process.env.PIXL_CAPTCHA_TEST_PASSWORD || '', charset: 'utf8mb4' },
      hash_salt: 'captcha-http-disposable-fixture-salt', allowed_hosts: ['127.0.0.1'], public_key: 'captcha-http-public-fixture',
      captcha: { enabled: true, visitor_interval: 1, page_view_interval: 4, landing_urls: ['/landing', '/second-landing'], success_target: 1, revision: 'http-contract-fixture' }
    };
    fs.writeFileSync(path.join(directory, 'pixl_config.php'), `<?php return json_decode(base64_decode('${Buffer.from(JSON.stringify(config)).toString('base64')}'), true, 512, JSON_THROW_ON_ERROR);\n`, { mode: 0o600 });
    fs.writeFileSync(path.join(directory, 'fixture.php'), `<?php
require __DIR__ . '/pixl_captcha.php';
$pdo = pixl_pdo();
if (!str_starts_with((string)$pdo->query('SELECT DATABASE()')->fetchColumn(), 'pixl_captcha_test_')) throw new RuntimeException('Disposable DB required');
pixl_captcha_ensure_schema($pdo);
if (PHP_SAPI === 'cli') {
  $pdo->exec('DELETE FROM pixl_captcha_page_views');
  $pdo->exec('DELETE FROM pixl_captcha_visitors');
  $pdo->exec('DELETE FROM pixl_captcha_stats');
  $pdo->exec('DELETE FROM pixl_captcha_state');
  pixl_captcha_ensure_schema($pdo);
} else { header('Content-Type: application/json'); echo json_encode(pixl_captcha_summary($pdo, pixl_captcha_settings()), JSON_THROW_ON_ERROR); }
`);
    execFileSync(php, [path.join(directory, 'fixture.php')], { stdio: 'pipe' });
    const probe = net.createServer();
    await new Promise((resolve, reject) => { probe.once('error', reject); probe.listen(0, '127.0.0.1', resolve); });
    const port = probe.address().port;
    await new Promise(resolve => probe.close(resolve));
    server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', directory], { stdio: ['ignore', 'pipe', 'pipe'] });
    await new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('CAPTCHA fixture server startup timeout')), 5000);
      server.once('error', error => { clearTimeout(timer); reject(error); });
      server.once('exit', code => { clearTimeout(timer); reject(new Error('CAPTCHA fixture server exited: ' + code)); });
      server.stderr.on('data', data => { if (String(data).includes('Development Server')) { clearTimeout(timer); resolve(); } });
    });
    const origin = `http://127.0.0.1:${port}`;
    const request = async (body, visitor = 'challenged') => {
      const response = await fetch(`${origin}/captcha.php`, { method: 'POST', headers: {
        Origin: origin, 'Content-Type': 'text/plain;charset=UTF-8', 'User-Agent': `CAPTCHA HTTP fixture ${visitor}`
      }, body: JSON.stringify({ url: `${origin}/landing`, siteKey: config.public_key, ...body }) });
      return { status: response.status, data: await response.json() };
    };
    const summary = async () => (await fetch(`${origin}/fixture.php`)).json();
    assert.equal((await request({ action: 'check' }, 'passing')).data.required, false);
    await request({ action: 'check', url: `${origin}/landing?utm_source=x#fragment` }, 'passing');
    assert.equal((await summary()).waiting_page_views, 1, 'endpoint deduplicates reloads and query/fragment variants');
    await request({ action: 'check', url: `${origin}/article` }, 'passing');
    assert.equal((await summary()).waiting_page_views, 2, 'non-landing pages count toward the page threshold');
    assert.equal((await request({ action: 'check' }, 'passing-two')).data.active, false, 'visitor threshold alone cannot start the phase');
    const deferred = await request({ action: 'check', url: `${origin}/article` });
    assert.equal(deferred.data.active, true, 'fourth visitor/page reaches the page threshold');
    assert.equal(deferred.data.required, false, 'phase activation outside the landing list cannot display a gate');
    assert.equal((await summary()).blocked_visitors, 0);
    const challenge = await request({ action: 'check', attemptSource: 'tab-a' });
    assert.equal(challenge.status, 200);
    assert.equal(challenge.data.required, true);
    const token = challenge.data.token;
    for (const action of ['check', 'status', 'fail', 'verify']) {
      const excluded = await request({ action, token, failedAttempts: 2, url: `${origin}/not-landing` });
      assert.equal(excluded.data.required, false, `${action} on other pages cannot require the CAPTCHA`);
      assert.equal(excluded.data.verified, false);
    }
    assert.equal((await summary()).failures, 0);
    assert.equal((await summary()).successes, 0);
    assert.equal((await request({ action: 'check', url: `${origin}/second-landing` }, 'another-challenge')).data.required, true, 'multiple landing URLs are supported');
    for (const [attemptSource, failedAttempts, expected] of [['tab-a', 1, 1], ['tab-b', 1, 2], ['tab-b', 2, 3], ['tab-a', 1, 3], ['reloaded-page', 1, 4]]) {
      const result = await request({ action: 'fail', token, attemptSource, failedAttempts });
      assert.equal(result.status, 200);
      assert.equal((await summary()).failures, expected, `${attemptSource}:${failedAttempts} must yield ${expected} total failures`);
    }
    assert.equal((await request({ action: 'check', attemptSource: 'reloaded-page' })).data.token, token);
    for (const attemptSource of [null, [], 1, 'invalid source', 'source\n', 'a'.repeat(129)]) {
      assert.equal((await request({ action: 'fail', token, failedAttempts: 7, attemptSource })).status, 422);
    }
    assert.equal((await request({ action: 'fail', token: token + '\n', failedAttempts: 7, attemptSource: 'tab-a' })).status, 422);
    assert.equal((await request({ action: 'fail', token, failedAttempts: 7, attemptSource: 'tab-a', siteKey: 'wrong-key' })).status, 403);
    assert.equal((await summary()).failures, 4, 'invalid requests do not change counters');
    const verified = await request({ action: 'verify', token, failedAttempts: 3, attemptSource: 'reloaded-page' });
    assert.equal(verified.status, 200);
    assert.equal(verified.data.verified, true);
    assert.equal((await summary()).last_failures, 6, 'verify recovers two lost failures from the same page source');
    await request({ action: 'verify', token, failedAttempts: 3, attemptSource: 'reloaded-page' });
    assert.equal((await summary()).last_failures, 6, 'acknowledgement replay is idempotent');
    assert.equal((await summary()).waiting_page_views, 0, 'completion resets the page threshold through the real endpoint');
    assert.equal((await summary()).remaining_page_views, 4);
    console.log('PASS CAPTCHA HTTP/MySQL: combined thresholds, page identity, landing-only checks and acknowledgements, deferred visitors, attemptSource forwarding, reload/retry idempotence, final report recovery, request validation');
  } finally {
    if (server && server.exitCode === null) {
      const exited = new Promise(resolve => server.once('exit', resolve));
      server.kill();
      await exited;
    }
    fs.rmSync(directory, { recursive: true, force: true });
  }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
