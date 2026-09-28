(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const integer = new Intl.NumberFormat('de-DE');
  const decimal = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  const dates = new Intl.DateTimeFormat('de-DE', { timeZone: 'Europe/Berlin', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
  const num = value => integer.format(value);
  const percent = value => value === null ? '–' : decimal.format(value) + ' %';
  const date = value => dates.format(new Date(value * 1000));
  const name = value => value === '' ? '(ohne Kampagnenname)' : value;
  const node = (tag, text, className) => {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    if (className) element.className = className;
    return element;
  };
  const empty = (body, columns, text) => {
    const row = node('tr'), cell = node('td', text, 'empty-state');
    cell.colSpan = columns; row.append(cell); body.replaceChildren(row);
  };
  const labelCell = (label, text, className) => {
    const cell = node('td', text, className); cell.dataset.label = label; return cell;
  };
  let snapshot = null, selected = null, detail = null, overviewRequest = null, detailRequest = null, stopped = false, expired = false;
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
  const requestedRange = new URL(location.href).searchParams.get('range');
  if (['24h', '1d', '7d', '30d', 'all'].includes(requestedRange)) $('agentRange').value = requestedRange;
  const endpoint = (kind, params) => {
    const url = new URL('live.php', location.href); url.searchParams.set('data', kind);
    for (const [key, value] of Object.entries(params)) url.searchParams.set(key, String(value));
    return url;
  };
  async function get(url, signal) {
    const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal });
    if (response.status === 401) { expired = true; throw new Error('Die Anmeldung ist abgelaufen. Bitte erneut anmelden.'); }
    if (!response.ok) throw new Error('Die UserAgent-Auswertung ist derzeit nicht erreichbar. Bitte erneut aktualisieren.');
    const data = await response.json();
    if (!data || data.ok !== true || !Number.isFinite(data.generated_at)) throw new Error('Die Antwort ist unvollständig. Bitte erneut aktualisieren.');
    return data;
  }
  function showError(id, error, stale = '') {
    const notice = $(id);
    notice.replaceChildren(node('span', (error.name === 'AbortError' ? 'Die Abfrage dauert zu lange. Bitte erneut versuchen.' : error.message) + stale));
    if (expired) {
      const login = node('a', ' Erneut anmelden');
      login.href = 'live.php?view=useragents&range=' + encodeURIComponent($('agentRange').value); notice.append(login);
    }
    notice.hidden = false;
  }
  function campaignButton(row, unknown = false) {
    const button = node('button', name(row.campaign), 'ua-campaign-button'); button.type = 'button';
    button.setAttribute('aria-controls', 'agentDetails');
    button.setAttribute('aria-pressed', String(selected === row.campaign));
    button.addEventListener('click', () => {
      selected = row.campaign; $('agentSearch').value = ''; $('agentUnknownOnly').checked = unknown;
      renderCampaigns(); loadDetails(1);
      $('agentDetails').scrollIntoView({ block: 'start', behavior: 'auto' });
    });
    return button;
  }
  function scoreCell(row) {
    const cell = node('td', undefined, 'ua-score'); cell.append(node('strong', percent(row.score)));
    if (row.score !== null) {
      const bar = node('span', undefined, 'ua-bar'), fill = node('i');
      fill.style.width = Math.max(0, Math.min(100, row.score)) + '%'; bar.setAttribute('aria-hidden', 'true'); bar.append(fill); cell.append(bar);
    }
    cell.append(node('small', row.known_occurrences < 2 ? 'Mindestens 2 nötig' : row.known_occurrences < 20 ? 'Kleine Datenbasis: ' + num(row.known_occurrences) : num(row.known_occurrences) + ' Vorkommen'));
    return cell;
  }
  function renderCampaigns() {
    if (!snapshot) return;
    const query = $('agentCampaignSearch').value.trim().toLocaleLowerCase('de-DE'), sort = $('agentSort').value;
    const rows = snapshot.campaigns.filter(row => [name(row.campaign), ...row.sources.map(source => source.name)].join(' ').toLocaleLowerCase('de-DE').includes(query));
    rows.sort((a, b) => {
      if (sort === 'name') return name(a.campaign).localeCompare(name(b.campaign), 'de');
      if (sort.startsWith('score')) {
        if (a.score === null || b.score === null) return a.score === b.score ? b.occurrences - a.occurrences : a.score === null ? 1 : -1;
        return (sort === 'score_asc' ? a.score - b.score : b.score - a.score) || b.occurrences - a.occurrences;
      }
      return (sort === 'unknown' ? b.either_unknown_percent - a.either_unknown_percent : b.occurrences - a.occurrences) || name(a.campaign).localeCompare(name(b.campaign), 'de');
    });
    const summary = document.createDocumentFragment(), unknowns = document.createDocumentFragment();
    for (const row of rows) {
      const tr = node('tr'); tr.classList.toggle('ua-selected', row.campaign === selected);
      const campaign = node('td'); campaign.append(campaignButton(row));
      campaign.append(node('small', row.sources.map(source => `${source.name || '(direkt)'} · ${num(source.occurrences)}`).join(' / '), 'ua-sources'));
      tr.append(campaign, node('td', num(row.occurrences)), node('td', num(row.users)), node('td', num(row.known_occurrences)), node('td', num(row.distinct_useragents)),
        node('td', percent(row.unique_percent)), node('td', percent(row.dominant_percent)), scoreCell(row), node('td', `${num(row.missing_useragents)} · ${percent(row.missing_useragents_percent)}`));
      summary.append(tr);
      const unknown = node('tr'), title = node('td'); title.append(campaignButton(row, true));
      unknown.append(title, node('td', num(row.occurrences)));
      for (const key of ['browser_unknown', 'os_unknown', 'both_unknown', 'either_unknown']) {
        const cell = node('td', `${num(row[key])} · ${percent(row[key + '_percent'])}`);
        if (row[key] > 0) cell.classList.add('ua-unknown-value'); unknown.append(cell);
      }
      unknowns.append(unknown);
    }
    $('agentCampaignRows').replaceChildren(summary); $('agentUnknownRows').replaceChildren(unknowns);
    if (!rows.length) {
      const message = query ? 'Keine Kampagne passt zur Suche.' : 'Keine Seitenaufrufe im gewählten Zeitraum.';
      empty($('agentCampaignRows'), 9, message); empty($('agentUnknownRows'), 6, message);
    }
    $('agentCampaignCount').textContent = `${num(rows.length)} von ${num(snapshot.campaigns.length)} Kampagnennamen`;
  }
  function renderOverview(data) {
    snapshot = data;
    const total = data.totals;
    $('agentTotalCampaigns').textContent = num(total.campaigns);
    $('agentTotalOccurrences').textContent = num(total.known_occurrences);
    $('agentCoverage').textContent = `${num(total.occurrences)} Aufrufe · ${num(total.missing_useragents)} ohne UserAgent`;
    $('agentTotalDistinct').textContent = num(total.distinct_useragents);
    $('agentTotalUnknown').textContent = num(total.either_unknown);
    $('lastUpdated').textContent = `${data.range.label} · Stand: ${date(data.generated_at)} · Europe/Berlin`;
    if (!data.campaigns.some(row => row.campaign === selected)) {
      selected = data.campaigns[0]?.campaign ?? null; $('agentSearch').value = ''; $('agentUnknownOnly').checked = false;
    }
    $('agentDetails').hidden = selected === null;
    renderCampaigns();
  }
  async function refresh() {
    if (stopped || expired) return;
    overviewRequest?.abort(); detailRequest?.abort();
    const controller = new AbortController(); overviewRequest = controller;
    const timer = setTimeout(() => controller.abort(), 15000);
    $('connectionStatus').textContent = 'Auswertung wird geladen …'; $('connectionDot').classList.remove('connected');
    $('refreshAgents').disabled = true;
    try {
      const data = await get(endpoint('useragents', { range: $('agentRange').value }), controller.signal);
      if (!Array.isArray(data.campaigns) || !data.totals || !data.range || !data.campaigns.every(row => typeof row.campaign === 'string' && Array.isArray(row.sources) && Number.isFinite(row.occurrences) && (row.score === null || Number.isFinite(row.score)))) throw new Error('Die Kampagnenantwort ist unvollständig.');
      if (overviewRequest !== controller || controller.signal.aborted || stopped) return;
      renderOverview(data); $('errorNotice').hidden = true;
      $('connectionStatus').textContent = 'Auswertung aktuell'; $('connectionDot').classList.add('connected');
      const url = new URL(location.href); url.searchParams.set('range', data.range.key); history.replaceState(null, '', url);
      if (selected !== null) loadDetails(1);
    } catch (error) {
      if (overviewRequest !== controller || stopped) return;
      showError('errorNotice', error, snapshot ? ` Angezeigt wird der letzte Stand: ${snapshot.range.label}, ${date(snapshot.generated_at)}.` : '');
      $('connectionStatus').textContent = expired ? 'Anmeldung erforderlich' : 'Aktualisierung fehlgeschlagen';
    } finally {
      clearTimeout(timer);
      if (overviewRequest === controller) { overviewRequest = null; $('refreshAgents').disabled = expired; }
    }
  }
  function renderDetails(data) {
    detail = data;
    const fragment = document.createDocumentFragment();
    for (const row of data.rows) {
      const tr = node('tr'), raw = labelCell('UserAgent');
      raw.append(node('code', row.user_agent || '(UserAgent fehlt)', 'ua-raw'));
      tr.append(raw, labelCell('Vorkommen', num(row.occurrences)), labelCell('Anteil an Kampagne', percent(row.percent)), labelCell('Unique User', num(row.users)));
      for (const [key, label] of [['browsers', 'Browser'], ['operating_systems', 'Betriebssystem']]) {
        const cell = labelCell(label);
        for (const item of row[key]) cell.append(node('span', `${item.name} · ${num(item.occurrences)}`, item.name === 'Unknown' ? 'ua-label ua-unknown-value' : 'ua-label'));
        tr.append(cell);
      }
      tr.append(labelCell('Zuletzt gesehen', date(row.last_seen))); fragment.append(tr);
    }
    $('agentDetailRows').replaceChildren(fragment);
    if (!data.rows.length) empty($('agentDetailRows'), 7, 'Keine UserAgents für diese Auswahl.');
    $('agentDetailSummary').textContent = `${num(data.campaign_occurrences)} Aufrufe in dieser Kampagne · ${num(data.filtered_occurrences)} Aufrufe in der Detailauswahl`;
    const first = data.total_rows ? (data.page - 1) * data.page_size + 1 : 0;
    $('agentDetailCount').textContent = `${num(first)}–${num(Math.min(data.page * data.page_size, data.total_rows))} von ${num(data.total_rows)} UserAgent-Einträgen`;
    $('agentPage').textContent = `Seite ${num(data.page)} / ${num(data.pages)}`;
    $('agentPrev').disabled = data.page <= 1; $('agentNext').disabled = data.page >= data.pages;
  }
  async function loadDetails(page) {
    if (!snapshot || selected === null || stopped || expired) return;
    detailRequest?.abort();
    const controller = new AbortController(); detailRequest = controller; detail = null;
    const timer = setTimeout(() => controller.abort(), 15000);
    $('agentDetails').hidden = false; $('agentDetails').setAttribute('aria-busy', 'true');
    $('agentDetailTitle').textContent = name(selected); $('agentDetailError').hidden = true;
    $('agentDetailSummary').textContent = 'UserAgents werden geladen …'; $('agentDetailCount').textContent = ''; $('agentPage').textContent = '';
    $('agentPrev').disabled = $('agentNext').disabled = true;
    empty($('agentDetailRows'), 7, 'UserAgents werden geladen …');
    try {
      const data = await get(endpoint('useragent_details', { range: snapshot.range.key, at: snapshot.generated_at, campaign: selected, page,
        q: $('agentSearch').value.trim(), unknown: $('agentUnknownOnly').checked ? '1' : '0' }), controller.signal);
      if (!Array.isArray(data.rows) || !Number.isFinite(data.total_rows) || !data.rows.every(row => typeof row.user_agent === 'string' && Array.isArray(row.browsers) && Array.isArray(row.operating_systems))) throw new Error('Die UserAgent-Details sind unvollständig.');
      if (detailRequest !== controller || controller.signal.aborted || stopped) return;
      renderDetails(data);
    } catch (error) {
      if (detailRequest !== controller || stopped) return;
      empty($('agentDetailRows'), 7, 'Details konnten nicht geladen werden.');
      $('agentDetailSummary').textContent = 'Bitte erneut filtern oder aktualisieren.';
      showError('agentDetailError', error);
    } finally {
      clearTimeout(timer);
      if (detailRequest === controller) { detailRequest = null; $('agentDetails').setAttribute('aria-busy', 'false'); }
    }
  }
  $('refreshAgents').addEventListener('click', refresh); $('agentRange').addEventListener('change', refresh);
  $('agentCampaignSearch').addEventListener('input', renderCampaigns); $('agentSort').addEventListener('change', renderCampaigns);
  $('agentDetailFilters').addEventListener('submit', event => { event.preventDefault(); loadDetails(1); });
  $('agentUnknownOnly').addEventListener('change', () => loadDetails(1));
  $('agentPrev').addEventListener('click', () => { if (detail) loadDetails(detail.page - 1); });
  $('agentNext').addEventListener('click', () => { if (detail) loadDetails(detail.page + 1); });
  window.addEventListener('pagehide', () => { stopped = true; overviewRequest?.abort(); detailRequest?.abort(); });
  window.addEventListener('pageshow', event => { if (event.persisted) { stopped = false; refresh(); } });
  refresh();
})();
