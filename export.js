'use strict';
(() => {
  const root = document.documentElement;
  if (location.protocol === 'file:') root.dataset.offline = 'true';
  const theme = document.getElementById('themeToggle');
  const updateTheme = () => {
    const dark = root.dataset.theme === 'dark';
    theme.textContent = dark ? 'Light Mode' : 'Dark Mode';
    theme.setAttribute('aria-pressed',String(dark));
  };
  theme.addEventListener('click', () => {
    root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem('stats3_theme',root.dataset.theme); } catch (_) {}
    updateTheme();
  });
  updateTheme();
  const status = document.getElementById('filterStatus');
  const dataNode = document.getElementById('stats3Snapshot');
  const M = globalThis.Stats3ExportModel;
  let model;
  try { model = M.prepare(JSON.parse(dataNode.textContent)); }
  catch (_) { status.textContent = 'Die gespeicherten Archivdaten konnten nicht gelesen werden. Bitte die vollständige HTML-Datei erneut herunterladen.'; return; }
  const n = value => value === null ? '–' : Number(value).toLocaleString('de-DE');
  const date = value => value === null ? '–' : new Intl.DateTimeFormat('de-DE',{timeZone:'Europe/Berlin',dateStyle:'medium',timeStyle:'long'}).format(new Date(value*1000));
  const element = (tag,text,className) => { const node = document.createElement(tag); if (text !== undefined) node.textContent = text; if (className) node.className = className; return node; };
  const tableRows = new Map([...document.querySelectorAll('#sourceTable tbody tr')].map(node=>[node.dataset.table,node]));
  const cards = new Map([...document.querySelectorAll('.source-card')].map(node=>[node.dataset.table,node]));
  let range = Object.hasOwn(M.ranges,root.dataset.initialRange) ? root.dataset.initialRange : '3m';
  let sourceName = model.tables.has(root.dataset.initialTable) ? root.dataset.initialTable : '';
  let page = Math.max(1,Number(root.dataset.initialPage) || 1);
  let selection, summary, request = 0;
  const persistView = () => {
    root.dataset.initialRange = range;
    root.dataset.initialTable = sourceName;
    root.dataset.initialPage = String(page);
  };
  function renderRecords() {
    const panel = document.getElementById('datensaetze');
    panel.hidden = !sourceName;
    if (!sourceName) { persistView(); return; }
    const source = model.sources[sourceName];
    const table = model.tables.get(sourceName);
    const rows = selection.selected.get(sourceName) || [];
    const pages = Math.max(1,Math.ceil(rows.length/20));
    page = Math.min(pages,Math.max(1,page));
    document.getElementById('records-title').textContent = source.label + ' · Datensätze';
    document.getElementById('recordsDescription').textContent = n(rows.length) + ' Datensätze · ' + table.columns.length + ' Felder · ' + source.note;
    document.getElementById('pageLabel').textContent = 'Seite ' + n(page) + ' von ' + n(pages) + ' · 20 pro Seite';
    document.getElementById('previousPage').disabled = page === 1;
    document.getElementById('nextPage').disabled = page === pages;
    const fragment = document.createDocumentFragment();
    if (!rows.length) fragment.append(element('p','Keine Datensätze im angezeigten Zeitraum.','empty'));
    const offset = (page-1)*20;
    rows.slice(offset,offset+20).forEach((row,index) => {
      const details = element('details',undefined,'record');
      const title = element('summary','Datensatz ' + n(offset+index+1));
      title.append(element('span',row.event_id ?? row.event_uuid ?? row.id ?? row.session_id ?? row.visitor_hash ?? row.client_id ?? row.ip_hash ?? ''));
      details.append(title);
      const fields = element('dl');
      for (const column of table.columns) {
        const pair = element('div');
        pair.append(element('dt',column.Field));
        const value = row[column.Field];
        const content = element('dd');
        content.append(value === null ? element('em','NULL') : value === '' ? element('em','Leere Zeichenfolge') : element('pre',value));
        pair.append(content); fields.append(pair);
      }
      details.append(fields); fragment.append(details);
    });
    document.getElementById('records').replaceChildren(fragment);
    persistView();
  }
  function renderSelection() {
    selection = M.select(model,range);
    summary = M.summarize(model,selection);
    document.querySelectorAll('[data-range]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.range === range)));
    document.getElementById('filterPeriod').textContent = M.ranges[range].label + ': ' + date(selection.from) + ' bis ' + date(selection.to);
    document.getElementById('eventCount').textContent = n(summary.eventRows);
    document.getElementById('relatedCount').textContent = n(summary.relatedRows);
    document.getElementById('firstEvent').textContent = summary.firstEvent === null ? 'Keine Daten' : new Intl.DateTimeFormat('de-DE',{timeZone:'Europe/Berlin'}).format(new Date(summary.firstEvent*1000));
    for (const [name,values] of summary.sources) {
      const source = model.sources[name];
      if (!source.available) continue;
      tableRows.get(name).querySelector('.selected-count').textContent = n(values.rows);
      const card = cards.get(name);
      card.querySelector('.source-total strong').textContent = n(values.rows);
      const metrics = document.createDocumentFragment();
      for (const [label,value] of Object.entries(values.metrics)) {
        const pair = element('div'); pair.append(element('dt',label),element('dd',n(value))); metrics.append(pair);
      }
      card.querySelector('.source-metrics').replaceChildren(metrics);
      card.querySelector('.dates').textContent = values.first === null ? '' : date(values.first) + ' bis ' + date(values.last) + ' · Zeitfeld: ' + source.date_column;
    }
    renderRecords();
    status.textContent = M.ranges[range].label + ' · ' + n(summary.eventRows) + ' Ereignisse angezeigt · vollständig lokal gefiltert.';
    status.removeAttribute('aria-busy');
  }
  for (const button of document.querySelectorAll('[data-range]')) {
    button.disabled = false;
    button.addEventListener('click', () => {
      const id = ++request;
      range = button.dataset.range; page = 1;
      status.textContent = 'Zeitraum wird lokal ausgewertet …'; status.setAttribute('aria-busy','true');
      setTimeout(() => { if (id === request) renderSelection(); },0);
    });
  }
  document.querySelectorAll('[data-open-table]').forEach(link => link.addEventListener('click', event => {
    event.preventDefault();
    if (!model.tables.has(link.dataset.openTable)) return;
    sourceName = link.dataset.openTable; page = 1; renderRecords();
    location.hash = 'datensaetze';
    document.getElementById('datensaetze').scrollIntoView({block:'start'});
  }));
  document.getElementById('previousPage').addEventListener('click', () => { page--; renderRecords(); });
  document.getElementById('nextPage').addEventListener('click', () => { page++; renderRecords(); });
  const search = document.getElementById('sourceSearch');
  const searchSources = () => {
    const query = search.value.trim().toLocaleLowerCase('de');
    document.querySelectorAll('[data-source]').forEach(node => { node.hidden = !node.dataset.source.toLocaleLowerCase('de').includes(query); });
    document.getElementById('noSources').hidden = [...tableRows.values()].some(row=>!row.hidden);
  };
  search.addEventListener('input',searchSources);
  const filename = extension => 'stats3-backup-' + new Date(model.backup.meta.summary.window.to_unix*1000).toISOString().replace(/[-:]/g,'').replace('T','-').slice(0,15) + '.' + extension;
  const save = (parts,type,name) => {
    const url = URL.createObjectURL(new Blob(parts,{type}));
    const link = element('a'); link.href = url; link.download = name;
    document.body.append(link); link.click(); link.remove();
    setTimeout(()=>URL.revokeObjectURL(url),30000);
  };
  document.getElementById('htmlDownload').addEventListener('click', event => {
    event.preventDefault();
    persistView();
    const copy = root.cloneNode(true);
    copy.dataset.offline = 'true';
    copy.querySelectorAll('.server-link').forEach(node=>node.remove());
    for (const id of ['htmlDownload','backupDownload','csvDownload']) copy.querySelector('#'+id).setAttribute('href','#top');
    copy.querySelector('#sourceSearch').setAttribute('value',search.value);
    save(['<!doctype html>\n',copy.outerHTML],'text/html;charset=utf-8',filename('html'));
  });
  document.getElementById('backupDownload').addEventListener('click', event => {
    event.preventDefault(); save([dataNode.textContent],'application/json;charset=utf-8',filename('json'));
  });
  document.getElementById('csvDownload').addEventListener('click', event => {
    event.preventDefault(); save([...M.csvChunks(model.backup)],'text/csv;charset=utf-8','export.csv');
  });
  renderSelection();
  searchSources();
  root.dataset.archiveReady = 'true';
  if (sourceName && location.hash === '#datensaetze') document.getElementById('datensaetze').scrollIntoView({block:'start'});
})();
