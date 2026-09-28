(function(){
  'use strict';
  var $=function(s){return document.querySelector(s);};
  var nf=new Intl.NumberFormat('de-DE',{maximumFractionDigits:2});
  var esc=function(v){var d=document.createElement('div');d.textContent=v==null?'':String(v);return d.innerHTML;};
  var number=function(v,suffix){return nf.format(Number(v)||0)+(suffix||'');};
  var colors=['#61e4b5','#55a7ff','#a98bff','#ffcc66','#ff748c','#63d9ed','#a6e36b','#f18bd4'];

  function metric(label,value,note){return '<article class="metric"><span>'+esc(label)+'</span><strong>'+esc(value)+'</strong>'+(note?'<small>'+esc(note)+'</small>':'')+'</article>';}
  function emptyRow(cols){return '<tr><td colspan="'+cols+'" class="empty">Noch keine Daten</td></tr>';}
  function rows(target,data,cells,cols){$(target).innerHTML=data.length?data.map(function(r){return '<tr>'+cells(r).map(function(c){return '<td>'+c+'</td>';}).join('')+'</tr>';}).join(''):emptyRow(cols);}
  function metricSet(target,items){$(target).innerHTML=items.map(function(x){return metric(x[0],x[1],x[2]);}).join('');}

  function fitCanvas(canvas){var dpr=Math.min(devicePixelRatio||1,2),rect=canvas.getBoundingClientRect();canvas.width=Math.max(300,rect.width*dpr);canvas.height=Math.max(180,rect.height*dpr);var ctx=canvas.getContext('2d');ctx.setTransform(dpr,0,0,dpr,0,0);return {ctx:ctx,w:rect.width,h:rect.height};}
  function lineChart(id,data){var canvas=$('#'+id);if(!canvas)return;var f=fitCanvas(canvas),ctx=f.ctx,w=f.w,h=f.h,p={l:38,r:12,t:18,b:30},vals=data.map(function(x){return Number(x.visitors||x.value)||0;}),max=Math.max.apply(null,vals.concat([1]));ctx.clearRect(0,0,w,h);ctx.strokeStyle='rgba(255,255,255,.08)';ctx.fillStyle='#8794a6';ctx.font='11px system-ui';for(var i=0;i<4;i++){var y=p.t+(h-p.t-p.b)*i/3;ctx.beginPath();ctx.moveTo(p.l,y);ctx.lineTo(w-p.r,y);ctx.stroke();ctx.fillText(Math.round(max*(1-i/3)),4,y+4);}if(!data.length){ctx.fillText('Noch keine Daten',p.l,h/2);return;}var pts=data.map(function(x,i){return{x:p.l+(w-p.l-p.r)*(data.length===1?.5:i/(data.length-1)),y:p.t+(h-p.t-p.b)*(1-vals[i]/max)};});var grad=ctx.createLinearGradient(0,p.t,0,h-p.b);grad.addColorStop(0,'rgba(97,228,181,.36)');grad.addColorStop(1,'rgba(97,228,181,0)');ctx.beginPath();ctx.moveTo(pts[0].x,h-p.b);pts.forEach(function(a){ctx.lineTo(a.x,a.y);});ctx.lineTo(pts[pts.length-1].x,h-p.b);ctx.closePath();ctx.fillStyle=grad;ctx.fill();ctx.beginPath();pts.forEach(function(a,i){if(i===0)ctx.moveTo(a.x,a.y);else ctx.lineTo(a.x,a.y);});ctx.strokeStyle='#61e4b5';ctx.lineWidth=2.5;ctx.stroke();var step=Math.max(1,Math.ceil(data.length/6));ctx.fillStyle='#8794a6';data.forEach(function(x,i){if(i%step===0||i===data.length-1){var label=String(x.label).slice(-5);ctx.fillText(label,Math.max(p.l-4,pts[i].x-15),h-9);}});}
  function bars(id,data){var canvas=$('#'+id);if(!canvas)return;var f=fitCanvas(canvas),ctx=f.ctx,w=f.w,h=f.h;if(!data.length){ctx.fillStyle='#8794a6';ctx.font='12px system-ui';ctx.fillText('Noch keine Daten',12,30);return;}var max=Math.max.apply(null,data.map(function(x){return Number(x.value||x.visitors)||0;}).concat([1])),pad=10,row=(h-pad*2)/Math.min(data.length,8);ctx.font='11px system-ui';data.slice(0,8).forEach(function(x,i){var val=Number(x.value||x.visitors)||0,y=pad+i*row,bw=(w-120)*val/max;ctx.fillStyle='rgba(255,255,255,.07)';ctx.fillRect(104,y+5,w-116,row-10);ctx.fillStyle=colors[i%colors.length];ctx.fillRect(104,y+5,bw,row-10);ctx.fillStyle='#dce5ee';var label=String(x.label||'Unbekannt');if(label.length>15)label=label.slice(0,14)+'…';ctx.fillText(label,2,y+row/2+4);ctx.textAlign='right';ctx.fillText(nf.format(val),w-4,y+row/2+4);ctx.textAlign='left';});}

  function render(d){
    var m=d.metrics;
    metricSet('#primaryMetrics',[
      ['Unique Besucher',number(m.visitors)],['Unique Bots',number(m.bots)],['Bounce Rate',number(m.bounce_rate,'%')],['Unique Seiten / Besucher',number(m.pages_per_visitor)],['Besucher mit 4+ Seiten',number(m.visitors4)]
    ]);
    lineChart('trendChart',d.trend);
    var labels={browser:'Browser',os:'OS',device:'Device',country:'Land'};
    $('#breakdowns').innerHTML=Object.keys(labels).map(function(k){var items=d.breakdowns[k]||[];return '<article><h3>'+labels[k]+'</h3>'+(items.length?items.slice(0,5).map(function(x){return '<div class="rank"><span>'+esc(x.label)+'</span><b>'+number(x.value)+'</b></div>';}).join(''):'<p class="empty">Keine Daten</p>')+'</article>';}).join('');
    var detailLabels={screen:'Screen Size',inner:'Innere Screen Size',language:'Sprache',country:'Land',browser_version:'Browser mit Version',os_version:'OS mit Version'};
    $('#details').innerHTML=Object.keys(detailLabels).map(function(k){var a=d.details[k]||[];return '<article><h3>'+detailLabels[k]+'</h3>'+(a.length?a.slice(0,6).map(function(x){return '<div class="rank"><span>'+esc(x.label)+'</span><b>'+number(x.value)+'</b></div>';}).join(''):'<p class="empty">Keine Daten</p>')+'</article>';}).join('');
    rows('#paths',d.paths,function(r){return ['<span class="path">'+esc(r.label)+'</span>','<b>'+number(r.pageviews)+'</b>'];},2);
    $('#userAgents').innerHTML=d.user_agents.length?d.user_agents.map(function(r){return '<li><span>'+esc(r.label||'Unbekannt')+'</span><b>'+number(r.value)+'</b></li>';}).join(''):'<li class="empty">Noch keine Daten</li>';
    var s=d.session_summary;
    metricSet('#sessionMetrics', [['Unique Besucher',number(s.visitors)],['Eine Seite',number(s.single_page_visitors)],['Mehrere Seiten',number(s.multipage_visitors)],['Klickende Besucher',number(s.clicking_visitors)],['Besucher mit 4+ Seiten',number(s.visitors4)],['Ø unique Seiten',number(s.avg_unique_pages)],['Max Level',number(s.max_level,'%')]]);
    rows('#levels',d.levels,function(r){return ['<b>'+esc(r.label)+'</b>',number(r.visitors),number(r.avg_seconds,' s')];},3);
    rows('#campaigns',d.campaigns,function(r){return ['<b>'+esc(r.campaign)+'</b>',esc(r.source),esc(r.medium),number(r.visitors),number(r.clicking_visitors)];},5);
    var l=d.live;
    metricSet('#liveMetrics',[['Unique Besucher',number(l.users)],['Mit Seitenaufruf',number(l.pageview_users)],['Klickende Besucher',number(l.click_users)],['Aktive Besucher',number(l.active_users)],['Bots',number(l.bot_users)],['Ø Besucher / aktive Minute',number(l.avg_users_per_active_minute)],['Ø Minuten bis nächster Besucher',number(l.avg_minutes_to_next_visitor,' min')]]);
    lineChart('minuteChart',d.per_minute);lineChart('dailyChart',d.charts.daily);bars('referrerChart',d.charts.referrer);bars('browserChart',d.charts.browser);bars('osChart',d.charts.os);bars('languageChart',d.charts.language);bars('screenChart',d.charts.screen);
    var f=d.forecast||{};
    metricSet('#forecastMetrics',[
      ['Forecast Benutzer pro Stunde',number(f.per_hour),'Basis: '+number(f.current_hour_users)+' Unique in der laufenden Stunde'],
      ['Forecast Benutzer pro 24 Stunden',number(f.per_24h),'Basis: '+number(f.rolling_hour_users)+' Unique in den letzten 60 Minuten'],
      ['Forecast Benutzer Heute',number(f.today),'Bisher heute: '+number(f.today_users)+' Unique']
    ]);
  }
  var loading=false,loadingRange='',requestVersion=0,currentRange='1day';
  function setRange(range){
    currentRange=range;
    document.querySelectorAll('.range-button').forEach(function(button){button.classList.toggle('active',button.dataset.range===range);});
    var days=$('#daysRange');days.value=/^last[1-7]d$/.test(range)?range:'';
    var url=new URL(location.href);url.searchParams.set('range',range);history.replaceState(null,'',url);
  }
  function load(){
    var range=currentRange;
    if(loading&&loadingRange===range)return;
    loading=true;loadingRange=range;
    var version=++requestVersion;
    $('#status').innerHTML='<i></i> Wird geladen';$('#status').className='status';
    fetch('api.php?range='+encodeURIComponent(range),{cache:'no-store'}).then(function(r){
      return r.json().then(function(j){if(!r.ok||!j.ok)throw new Error(j.error||'Fehler');return j;});
    }).then(function(d){
      if(version!==requestVersion||range!==currentRange)return;
      setRange(d.range);render(d);
      $('#status').innerHTML='<i></i> Live · '+new Date(d.generated_at).toLocaleTimeString('de-DE',{hour:'2-digit',minute:'2-digit'});
    }).catch(function(e){
      if(version!==requestVersion||range!==currentRange)return;
      $('#status').className='status error';$('#status').innerHTML='<i></i> '+esc(e.message);
    }).finally(function(){if(version===requestVersion)loading=false;});
  }
  document.querySelectorAll('.range-button').forEach(function(button){button.addEventListener('click',function(){setRange(button.dataset.range);load();});});
  $('#daysRange').addEventListener('change',function(){if(this.value){setRange(this.value);load();}});
  $('#refresh').addEventListener('click',load);addEventListener('resize',function(){clearTimeout(window._stat4Resize);window._stat4Resize=setTimeout(load,180);});
  var requested=new URLSearchParams(location.search).get('range');if(/^(60min|24h|1day|last[1-7]d)$/.test(requested||''))setRange(requested);else setRange('1day');load();setInterval(load,60000);
})();
