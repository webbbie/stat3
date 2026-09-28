'use strict';
// Pure local archive queries. No network, storage mutation or server clock dependency.
(function (root) {
  const ranges = Object.freeze({
    '60min':{label:'60 Minuten',seconds:3600}, '24h':{label:'24 Stunden',seconds:86400},
    '7d':{label:'7 Tage',seconds:7*86400}, '14d':{label:'14 Tage',seconds:14*86400},
    '1m':{label:'1 Monat',seconds:30*86400}, '2m':{label:'2 Monate',seconds:60*86400},
    '3m':{label:'3 Monate',seconds:90*86400}
  });
  const timestamp = (value, unix = false) => {
    if (value === null || value === undefined || value === '') return null;
    const result = unix ? Number(value) : Date.parse(String(value).replace(' ', 'T') + 'Z') / 1000;
    return Number.isFinite(result) ? result : null;
  };
  const unknown = value => ['', 'unknown', 'unbekannt'].includes(String(value ?? '').trim().toLowerCase());
  function prepare(backup) {
    if (backup?.meta?.format !== 'stats3-statistics-backup' || backup.meta.version !== 1 || !Array.isArray(backup.tables)) throw new Error('Unbekanntes Archivformat.');
    const sources = backup.meta.summary.sources;
    const tables = new Map();
    const times = new Map();
    for (const table of backup.tables) {
      tables.set(table.name, table);
      const source = sources[table.name];
      times.set(table.name, table.rows.map(row => source.date_column ? timestamp(row[source.date_column], source.unix) : null));
    }
    return {backup,sources,tables,times};
  }
  function select(model, rangeKey = '3m') {
    if (!Object.hasOwn(ranges, rangeKey)) throw new Error('Unbekannter Zeitraum.');
    const to = model.backup.meta.summary.window.to_unix;
    const from = Math.max(model.backup.meta.summary.window.from_unix, to - ranges[rangeKey].seconds);
    const selected = new Map();
    for (const [name, table] of model.tables) {
      const times = model.times.get(name);
      selected.set(name, model.sources[name].kind === 'snapshot' ? table.rows : table.rows.filter((_, index) => times[index] !== null && times[index] >= from && times[index] <= to));
    }
    const rows = name => selected.get(name) || [];
    const keys = (name, field) => new Set(rows(name).map(row => row[field]).filter(value => value !== null && value !== undefined));
    const related = (name, field, references) => {
      const table = model.tables.get(name);
      if (!table) return;
      const own = new Set(rows(name));
      selected.set(name, table.rows.filter(row => own.has(row) || references.has(row[field])));
    };
    related('stat4_sessions', 'session_id', keys('stat4_events', 'session_id'));
    related('stat4_visitors', 'visitor_hash', new Set([...keys('stat4_sessions','visitor_hash'), ...keys('stat4_notifications','visitor_hash')]));
    related('mind_geo_cache', 'ip_hash', keys('mind_notifications', 'geo_ip_hash'));
    related('ppcmate_attributions', 'client_id', keys('ppcmate_conversions', 'client_id'));
    const captchaVisitors = keys('pixl_captcha_visitors', 'visitor_hash');
    if (model.tables.has('pixl_captcha_page_views')) selected.set('pixl_captcha_page_views', model.tables.get('pixl_captcha_page_views').rows.filter(row => captchaVisitors.has(row.visitor_hash)));
    return {rangeKey,from,to,selected};
  }
  function summarize(model, selection) {
    const sources = new Map();
    let eventRows = 0, relatedRows = 0, firstEvent = null;
    for (const [name, source] of Object.entries(model.sources)) {
      if (!source.available) { sources.set(name,{rows:0,metrics:{},first:null,last:null}); continue; }
      const rows = selection.selected.get(name) || [];
      const columns = new Set(model.tables.get(name).columns.map(column => column.Field));
      const visitors = new Set(), campaigns = new Set();
      let bots = 0, pageviews = 0, clicks = 0, ads = 0, unknownBrowser = 0, unknownOs = 0, sent = 0, first = null, last = null;
      const sums = Object.fromEntries(['waiting_visitors','waiting_page_views','successes','failures','blocked_visitors','last_successes','last_failures'].map(field => [field,null]));
      for (const row of rows) {
        if (row.visitor_hash !== null && row.visitor_hash !== undefined && row.visitor_hash !== '') visitors.add(row.visitor_hash);
        if (row.utm_campaign !== null && row.utm_campaign !== undefined) campaigns.add(row.utm_campaign);
        bots += Number(row.is_bot) === 1 ? 1 : 0;
        pageviews += row.event_type === 'pageview' ? 1 : 0;
        clicks += row.event_type === 'click' ? 1 : 0;
        ads += row.event_type === 'ad' ? 1 : 0;
        unknownBrowser += unknown(row.browser) ? 1 : 0;
        unknownOs += unknown(row.os) ? 1 : 0;
        if (name === 'stat4_notifications') sent += row.status === 'sent' ? 1 : 0;
        if (name === 'ppcmate_conversions') sent += Number(row.sent_at) > 0 ? 1 : 0;
        for (const field of Object.keys(sums)) if (row[field] !== null && row[field] !== undefined) sums[field] = (sums[field] ?? 0) + Number(row[field]);
        const time = source.date_column ? timestamp(row[source.date_column], source.unix) : null;
        if (time !== null) { first = first === null ? time : Math.min(first,time); last = last === null ? time : Math.max(last,time); }
      }
      const metrics = {};
      if (columns.has('visitor_hash')) metrics['Besucher-Hashes'] = visitors.size;
      if (columns.has('is_bot')) metrics['Bot-Datensätze'] = bots;
      if (columns.has('event_type')) {
        metrics.Seitenaufrufe = pageviews;
        if (name === 'stat4_events') metrics.Klicks = clicks;
        if (name === 'impressions') metrics['Banner-Impressionen'] = ads;
      }
      if (columns.has('browser')) metrics['Browser Unknown / leer'] = unknownBrowser;
      if (columns.has('os')) metrics['Betriebssystem Unknown / leer'] = unknownOs;
      if (name === 'stat4_sessions') metrics.Kampagnennamen = campaigns.size;
      if (['stat4_notifications','ppcmate_conversions'].includes(name)) metrics['Erfolgreich gesendet'] = sent;
      for (const [field,label] of Object.entries({waiting_visitors:'Wartende Besucher',waiting_page_views:'Seiten im Wartezyklus',successes:'Aktuelle Erfolge',failures:'Aktuelle Fehler',blocked_visitors:'Aktuell blockiert',last_successes:'Letzte Phase: Erfolge',last_failures:'Letzte Phase: Fehler'})) {
        if (columns.has(field)) metrics[label] = sums[field];
      }
      if (source.kind === 'events') { eventRows += rows.length; if (first !== null) firstEvent = firstEvent === null ? first : Math.min(firstEvent,first); }
      else relatedRows += rows.length;
      sources.set(name,{rows:rows.length,metrics,first,last});
    }
    return {sources,eventRows,relatedRows,firstEvent};
  }
  function csvCell(value, numeric = false) {
    if (value === null || value === undefined) return '';
    let text = String(value);
    if (!(numeric && text.trim() !== '' && Number.isFinite(Number(text))) && /^(?:[\x00-\x20]*[=+@-]|[\t\r\n])/.test(text)) text = "'" + text;
    return /[;"\r\n\t ]/.test(text) ? '"' + text.replace(/"/g,'""') + '"' : text;
  }
  function* csvChunks(backup) {
    const header = ['Statistik','Tabelle'];
    const positions = new Map();
    for (const table of backup.tables) for (const column of table.columns) {
      if (!positions.has(column.Field)) { positions.set(column.Field,header.length); header.push(column.Field); }
    }
    yield '\uFEFF' + header.map(value => csvCell(value)).join(';') + '\r\n';
    let chunk = '';
    for (const table of backup.tables) {
      const columns = table.columns.map(column => ({name:column.Field,position:positions.get(column.Field),numeric:/^(?:tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real|bit)\b/i.test(column.Type)}));
      for (const row of table.rows) {
        const record = Array(header.length).fill('');
        record[0] = csvCell(table.label); record[1] = csvCell(table.name);
        for (const column of columns) record[column.position] = csvCell(row[column.name],column.numeric);
        chunk += record.join(';') + '\r\n';
        if (chunk.length >= 65536) { yield chunk; chunk = ''; }
      }
    }
    if (chunk) yield chunk;
  }
  const api = {ranges,timestamp,prepare,select,summarize,csvCell,csvChunks};
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.Stats3ExportModel = api;
})(globalThis);
