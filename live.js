(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const numbers = new Intl.NumberFormat('de-DE');
  const dates = new Intl.DateTimeFormat('de-DE', { timeZone: 'Europe/Berlin', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  const times = new Intl.DateTimeFormat('de-DE', { timeZone: 'Europe/Berlin', hour: '2-digit', minute: '2-digit', second: '2-digit' });
  const hours = new Intl.DateTimeFormat('de-DE', { timeZone: 'Europe/Berlin', hour: '2-digit', minute: '2-digit' });
  const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
  const metricKeys = ['users_day', 'impressions_day', 'users_hour', 'impressions_hour', 'pages_day', 'pushes_day', 'users_24h', 'impressions_24h', 'pages_24h', 'pushes_24h'];
  let snapshot = null, interval = null, request = null, paused = false, stopped = false, expired = false;
  let knownPushes = new Set(), knownPages = new Set(), flashQueue = 0, flashTimer = null;
  const number = value => numbers.format(value || 0);
  const date = (value, format = dates) => format.format(new Date(value * 1000));
  const node = (tag, text, className) => {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    if (className) element.className = className;
    return element;
  };
  function svgNode(tag, attributes = {}, text) {
    const element = document.createElementNS('http://www.w3.org/2000/svg', tag);
    Object.entries(attributes).forEach(([key, value]) => element.setAttribute(key, String(value)));
    if (text !== undefined) element.textContent = text;
    return element;
  }
  function status(text, connected = false) {
    $('connectionStatus').textContent = text;
    $('connectionDot').classList.toggle('connected', connected);
  }
  function theme() {
    const dark = document.documentElement.dataset.theme === 'dark';
    $('themeToggle').textContent = dark ? 'Light Mode' : 'Dark Mode';
    $('themeToggle').setAttribute('aria-pressed', String(dark));
  }
  $('themeToggle').addEventListener('click', () => {
    document.documentElement.dataset.theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem('stats3_theme', document.documentElement.dataset.theme); } catch (_) {}
    theme();
  });
  theme();

  function history() {
    if (!snapshot) return;
    const svg = $('historyChart'), width = Math.max(260, svg.clientWidth), height = svg.clientHeight;
    const left = 42, right = width - 13, top = 17, bottom = height - 36;
    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
    const max = Math.max(1, ...snapshot.hourly.map(row => Math.max(row.users, row.impressions)));
    const magnitude = 10 ** Math.floor(Math.log10(max));
    const step = Math.max(1, Math.ceil(max / magnitude / 4) * magnitude);
    const ceiling = Math.ceil(max / step) * step;
    const y = value => bottom - value / ceiling * (bottom - top);
    const x = index => left + index / 23 * (right - left);
    const content = document.createDocumentFragment();
    content.append(svgNode('title', {}, 'Unique User und Impressionen pro 60 Minuten in den letzten 24 Stunden'));
    for (let value = 0; value <= ceiling; value += step) {
      content.append(svgNode('line', { x1: left, x2: right, y1: y(value), y2: y(value), class: 'chart-grid' }));
      content.append(svgNode('text', { x: left - 8, y: y(value) + 3, 'text-anchor': 'end', class: 'chart-axis' }, number(value)));
    }
    const labelEvery = width < 500 ? 6 : 3;
    snapshot.hourly.forEach((row, i) => {
      if (i % labelEvery === 0 || i === 23) content.append(svgNode('text', { x: x(i), y: bottom + 23, 'text-anchor': i === 23 ? 'end' : 'middle', class: 'chart-axis' }, date(row.start, hours)));
    });
    for (const key of ['impressions', 'users']) {
      const points = snapshot.hourly.map((row, i) => `${x(i)},${y(row[key])}`).join(' ');
      if (key === 'impressions') content.append(svgNode('polygon', { points: `${left},${bottom} ${points} ${right},${bottom}`, fill: 'var(--impressions)', opacity: '.055' }));
      content.append(svgNode('polyline', { points, class: `chart-line chart-${key}` }));
      snapshot.hourly.forEach((row, i) => {
        const dot = svgNode('circle', { cx: x(i), cy: y(row[key]), r: width < 500 ? 2 : 2.8, fill: `var(--${key})`, tabindex: '0' });
        const label = `${date(row.start)} – ${date(row.end, hours)}: ${number(row.users)} Unique User, ${number(row.impressions)} Impressionen`;
        dot.setAttribute('aria-label', label);
        dot.append(svgNode('title', {}, label));
        content.append(dot);
      });
    }
    svg.replaceChildren(content);
    svg.setAttribute('aria-label', `Letzte 24 Stunden: ${number(snapshot.totals.users_24h)} Unique User, ${number(snapshot.totals.impressions_24h)} Impressionen. Stundenwerte stehen in der Tabelle.`);
    $('historyEmpty').hidden = snapshot.totals.impressions_24h !== 0;
    $('historyRange').textContent = `${date(snapshot.windows['24h'])} – ${date(snapshot.generated_at)} · 24 × 60 Minuten`;
  }

  function heartbeat() {
    if (!snapshot) return;
    const svg = $('heartbeatChart'), width = Math.max(260, svg.clientWidth), height = svg.clientHeight;
    const left = 7, right = width - 7, baseline = height * .49;
    const end = snapshot.generated_at, start = end - 120;
    const x = stamp => left + (stamp - start) / 120 * (right - left);
    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
    const content = document.createDocumentFragment();
    content.append(svgNode('title', {}, 'Seitenaufrufe schlagen nach oben aus; der erste Seitenaufruf eines neuen Users nach unten.'));
    for (let time = Math.ceil(start / 10) * 10; time <= end; time += 10) content.append(svgNode('line', { x1: x(time), x2: x(time), y1: 10, y2: height - 12, class: 'chart-grid' }));
    for (const lineY of [baseline - 42, baseline, baseline + 42]) content.append(svgNode('line', { x1: left, x2: right, y1: lineY, y2: lineY, class: lineY === baseline ? 'heartbeat-base' : 'chart-grid' }));
    for (const key of ['impressions', 'users']) {
      let d = `M${left},${baseline}`;
      snapshot.heartbeat.forEach(row => {
        const count = row[key];
        if (!count) return;
        const binStart = Math.max(start, row.start), binEnd = Math.min(end, row.start + 5);
        if (binEnd <= binStart) return;
        // At very high rates overlapping pulses share pixels; counts remain exact in the labels.
        const visibleCount = Math.min(count, 1000);
        const spacing = (x(binEnd) - x(binStart)) / visibleCount;
        const pulseWidth = Math.min(8, spacing * .84);
        for (let i = 0; i < visibleCount; i++) {
          const middle = x(binStart) + spacing * (i + .5);
          const peak = baseline + (key === 'users' ? 43 : -48);
          d += ` L${middle - pulseWidth / 2},${baseline} L${middle},${peak} L${middle + pulseWidth / 2},${baseline}`;
        }
      });
      d += ` L${right},${baseline}`;
      content.append(svgNode('path', { d, class: `heartbeat-pulse chart-${key}` }));
    }
    snapshot.heartbeat.forEach(row => {
      const binStart = Math.max(start, row.start), binEnd = Math.min(end, row.start + 5);
      if (binEnd <= binStart) return;
      const region = svgNode('rect', { x: x(binStart), y: 5, width: Math.max(0, x(binEnd) - x(binStart)), height: height - 10, fill: 'transparent' });
      region.append(svgNode('title', {}, `${date(row.start, times)}: ${number(row.users)} neue User, ${number(row.impressions)} Impressionen, ${number(row.pushes)} Pushover`));
      content.append(region);
    });
    content.append(svgNode('circle', { cx: right, cy: baseline, r: 3, fill: 'var(--users)' }));
    svg.replaceChildren(content);
    const recent = snapshot.heartbeat.filter(row => row.start >= end - 5);
    const users = recent.reduce((sum, row) => sum + row.users, 0), impressions = recent.reduce((sum, row) => sum + row.impressions, 0);
    $('pulseCounts').textContent = `Aktuelles 5-Sekunden-Intervall: ${number(users)} neue User · ${number(impressions)} Impressionen`;
    svg.setAttribute('aria-label', `Live-Traffic der letzten zwei Minuten. Aktuelles Intervall: ${number(users)} neue User nach unten, ${number(impressions)} Impressionen nach oben.`);
  }

  function flashNext() {
    if (!flashQueue || reducedMotion.matches || paused || document.hidden || stopped) { flashQueue = 0; return; }
    flashQueue--;
    $('pushLight').classList.add('flashing');
    flashTimer = setTimeout(() => {
      $('pushLight').classList.remove('flashing');
      flashTimer = setTimeout(() => { flashTimer = null; flashNext(); }, 300);
    }, 400);
  }
  function pushes(data, first) {
    const fresh = first ? [] : data.recent_pushes.filter(message => !knownPushes.has(message.id));
    knownPushes = new Set(data.recent_pushes.map(message => message.id));
    $('pushToday').textContent = number(data.totals.pushes_day);
    $('pushLight').setAttribute('aria-label', `Pushover: ${number(data.totals.pushes_day)} bestätigte Nachrichten heute. Versandprotokoll öffnen.`);
    if (fresh.length) {
      $('pushStatus').textContent = `${number(fresh.length)} neue Pushover-Nachricht${fresh.length === 1 ? '' : 'en'} bestätigt · ${date(Math.max(...fresh.map(message => message.timestamp)), times)}`;
      flashQueue += fresh.length;
      if (!flashTimer) flashNext();
    }
  }
  $('pushLight').addEventListener('click', () => { location.href = 'mind/index.php'; });

  function latest(data, first) {
    const fragment = document.createDocumentFragment();
    data.latest.forEach(row => {
      const item = node('li');
      if (!first && !knownPages.has(row.id)) item.classList.add('new-impression');
      const time = node('time', date(row.timestamp, times));
      time.dateTime = new Date(row.timestamp * 1000).toISOString();
      time.title = date(row.timestamp);
      // URLs are text, so tracked content cannot inject HTML or execute a URL scheme.
      item.append(time, node('span', row.url, `full-url${row.complete_url ? '' : ' legacy-url'}`));
      fragment.append(item);
    });
    if (!data.latest.length) fragment.append(node('li', 'Noch keine Seitenaufrufe gespeichert.', 'empty-state'));
    $('latestList').replaceChildren(fragment);
    knownPages = new Set(data.latest.map(row => row.id));
  }

  function campaigns() {
    if (!snapshot) return;
    const query = $('campaignSearch').value.trim().toLocaleLowerCase('de-DE');
    const labelSource = row => row.source || '(direkt)';
    const labelCampaign = row => row.campaign || '(ohne Kampagne)';
    const rows = snapshot.campaigns.filter(row => `${labelSource(row)} ${labelCampaign(row)}`.toLocaleLowerCase('de-DE').includes(query));
    const fragment = document.createDocumentFragment();
    rows.forEach(row => {
      const tr = node('tr');
      tr.append(node('td', labelSource(row)), node('td', labelCampaign(row)));
      metricKeys.forEach(key => tr.append(node('td', number(row[key]))));
      fragment.append(tr);
    });
    if (!rows.length) {
      const tr = node('tr'), td = node('td', query ? 'Keine Kampagne passt zur Suche.' : 'Noch keine Kampagnendaten im Zeitraum.', 'empty-state');
      td.colSpan = 12; tr.append(td); fragment.append(tr);
    }
    $('campaignRows').replaceChildren(fragment);
    $('campaignCount').textContent = `${number(rows.length)} von ${number(snapshot.campaigns.length)} Quelle/Kampagne-Kombinationen`;
  }
  $('campaignSearch').addEventListener('input', campaigns);

  function render(data) {
    const first = snapshot === null;
    pushes(data, first); latest(data, first); snapshot = data;
    for (const [id, key] of [['totalUsers', 'users_24h'], ['totalImpressions', 'impressions_24h'], ['totalPages', 'pages_24h'], ['totalPushes', 'pushes_24h']]) $(id).textContent = number(data.totals[key]);
    $('warningNotice').textContent = data.warnings.join(' ');
    $('warningNotice').hidden = !data.warnings.length;
    const hourly = document.createDocumentFragment();
    data.hourly.forEach(row => {
      const tr = node('tr');
      tr.append(node('td', `${date(row.start)} – ${date(row.end, hours)}`), node('td', number(row.users)), node('td', number(row.impressions)));
      hourly.append(tr);
    });
    $('hourlyRows').replaceChildren(hourly);
    history(); heartbeat(); campaigns();
    $('lastUpdated').textContent = `Letzte Aktualisierung: ${date(data.generated_at, times)} · Europe/Berlin`;
  }

  async function refresh() {
    if (request || paused || stopped || expired || document.hidden) return;
    const controller = new AbortController(); request = controller;
    const timeout = setTimeout(() => controller.abort(), 8000);
    try {
      const endpoint = new URL('live.php', location.href); endpoint.searchParams.set('data', '1');
      const response = await fetch(endpoint, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: controller.signal });
      if (response.status === 401) { expired = true; throw new Error('Die Anmeldung ist abgelaufen. Bitte erneut anmelden.'); }
      if (!response.ok) throw new Error('Live-Daten sind derzeit nicht erreichbar. Erneuter Versuch alle 5 Sekunden.');
      const data = await response.json();
      if (!data.ok || !Array.isArray(data.hourly) || data.hourly.length !== 24 || !Array.isArray(data.heartbeat) || !Array.isArray(data.latest) || !Array.isArray(data.campaigns) || !Array.isArray(data.recent_pushes) || !Array.isArray(data.warnings) || !data.totals || !data.windows || !Number.isFinite(data.generated_at)) throw new Error('Die Live-Antwort ist unvollständig. Erneuter Versuch alle 5 Sekunden.');
      if (paused || stopped || document.hidden) return;
      render(data);
      $('errorNotice').hidden = true;
      status('Live · alle 5 Sekunden', true);
    } catch (error) {
      if (paused || stopped || document.hidden) return;
      const notice = $('errorNotice');
      notice.replaceChildren(node('span', error.name === 'AbortError' ? 'Die Abfrage dauert zu lange. Erneuter Versuch alle 5 Sekunden.' : error.message));
      if (snapshot) notice.append(node('span', ` Angezeigt wird der letzte Stand von ${date(snapshot.generated_at, times)}.`));
      if (expired) { const login = node('a', 'Erneut anmelden'); login.href = 'live.php'; notice.append(login); clearInterval(interval); }
      notice.hidden = false;
      status(expired ? 'Anmeldung erforderlich' : 'Verbindung unterbrochen');
    } finally {
      clearTimeout(timeout);
      if (request === controller) request = null;
    }
  }
  function suspend() {
    clearInterval(interval); interval = null;
    if (request) request.abort();
    clearTimeout(flashTimer); flashTimer = null; flashQueue = 0;
    $('pushLight').classList.remove('flashing');
  }
  function start() {
    if (paused || stopped || expired || document.hidden) return;
    clearInterval(interval); interval = setInterval(refresh, 5000);
    refresh();
  }
  $('pauseButton').addEventListener('click', () => {
    paused = !paused;
    $('pauseButton').textContent = paused ? 'Fortsetzen' : 'Pausieren';
    $('pauseButton').setAttribute('aria-pressed', String(paused));
    if (paused) { suspend(); status('Live-Aktualisierung pausiert'); } else start();
  });
  document.addEventListener('visibilitychange', () => { if (document.hidden) { suspend(); status('Im Hintergrund pausiert'); } else start(); });
  window.addEventListener('pagehide', () => { stopped = true; suspend(); });
  window.addEventListener('pageshow', () => { stopped = false; start(); });
  if (typeof ResizeObserver === 'function') new ResizeObserver(() => { history(); heartbeat(); }).observe($('historyChart'));
  else window.addEventListener('resize', () => { history(); heartbeat(); });
  start();
})();
