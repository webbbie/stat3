'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const M = require('../export_model.js');
let checks = 0;
const equal = (actual,expected,label) => { assert.deepEqual(actual,expected,label); checks++; };
equal(Object.keys(M.ranges),['60min','24h','7d','14d','1m','2m','3m'],'all requested ranges in order');
equal(Object.values(M.ranges).map(value=>value.seconds),[3600,86400,604800,1209600,2592000,5184000,7776000],'month filters are capped at 30/60/90 days');
equal(M.timestamp('2026-01-02 03:04:05'),Date.UTC(2026,0,2,3,4,5)/1000,'DATETIME parsing is always UTC');
equal(M.timestamp('0',true),0,'Unix epoch is retained for old parent records');
equal(M.timestamp(null),null,'NULL has no date');
equal(M.timestamp('not a date'),null,'invalid date cannot enter a time range');
equal(M.csvCell('=1+1'),"'=1+1",'CSV formula strings are protected');
equal(M.csvCell('-12.30',true),'-12.30','numeric negatives remain numeric');
equal(M.csvCell('000123'),'000123','leading zeros are retained');
equal(M.csvCell('München; "Test"'),'"München; ""Test"""','CSV delimiters and quotes are escaped');
if (process.argv[2]) {
  const backup = JSON.parse(fs.readFileSync(process.argv[2],'utf8'));
  const before = JSON.stringify(backup);
  const model = M.prepare(backup);
  const all = M.select(model,'3m');
  const summaries = M.summarize(model,all);
  for (const [name,source] of Object.entries(model.sources)) {
    const actual = summaries.sources.get(name);
    equal(actual.rows,source.rows,'SQL row count: '+name);
    equal(actual.metrics,Object.fromEntries(Object.entries(source.metrics)),'SQL metrics: '+name);
    equal(actual.first,M.timestamp(source.first),'SQL first date: '+name);
    equal(actual.last,M.timestamp(source.last),'SQL last date: '+name);
  }
  equal(summaries.eventRows+summaries.relatedRows,backup.meta.summary.total_rows,'all rows counted');
  equal(summaries.firstEvent,M.timestamp(backup.meta.summary.first_event),'first event matches SQL');
  for (const [range,count] of Object.entries({'60min':30,'24h':33,'7d':36,'14d':39,'1m':42,'2m':45,'3m':49})) {
    const selected = M.select(model,range);
    equal(selected.selected.get('custom_export_events').length,count,'boundary rows for '+range);
    equal(selected.to,backup.meta.summary.window.to_unix,'fixed saved end for '+range);
    equal(selected.to-selected.from,M.ranges[range].seconds,'exact duration for '+range);
    for (const name of ['stat4_sessions','stat4_visitors','mind_geo_cache','ppcmate_attributions','pixl_captcha_page_views']) equal(selected.selected.get(name).length,1,'related row retained in '+range+': '+name);
    equal(M.summarize(model,selected).sources.get('pixl_captcha_state').metrics['Wartende Besucher'],250,'current counter kept in '+range);
  }
  const oldNow = Date.now; Date.now = () => Date.UTC(2040,0,1);
  try { equal(M.select(model,'60min').selected.get('custom_export_events').length,30,'offline filters work long after download'); }
  finally { Date.now = oldNow; }
  const csv = [...M.csvChunks(backup)].join('');
  if (process.argv[3]) equal(csv,fs.readFileSync(process.argv[3],'utf8'),'offline CSV equals the authenticated PHP CSV byte for byte');
  equal(JSON.stringify(backup),before,'filtering and CSV leave all original data unchanged');
  for (const table of backup.tables) table.rows = [];
  const empty = M.summarize(M.prepare(backup),M.select(M.prepare(backup),'60min'));
  equal([empty.eventRows,empty.relatedRows,empty.firstEvent],[0,0,null],'empty archive is supported');
}
console.log(`PASS static export model: ${checks} checks; UTC, seven ranges, boundaries, SQL parity, parent records, CSV and saved clock`);
