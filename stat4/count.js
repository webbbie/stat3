(function () {
  'use strict';

  if (window.__stat4TrackerInitialized) return;
  window.__stat4TrackerInitialized = true;

  var script = document.currentScript;
  var defaultEndpoint = 'https://www.bayerchristian.de/stats3/stat4/collect.php';
  var endpoint = defaultEndpoint;
  try {
    var requestedEndpoint = script && script.dataset.endpoint
      ? new URL(script.dataset.endpoint, script.src || location.href)
      : script && script.src ? new URL('collect.php', script.src) : new URL(defaultEndpoint);
    if (/^https?:$/.test(requestedEndpoint.protocol)) endpoint = requestedEndpoint.href;
  } catch (error) {}

  var VISITOR_TTL = 24 * 60 * 60 * 1000;
  var HEARTBEAT_INTERVAL = 30000;
  var TICK_INTERVAL = 5000;
  var QUEUE_LIMIT = 64;
  var QUEUE_TTL = 15 * 60 * 1000;
  var storage = safeStorage('localStorage');
  var sessionStorage = safeStorage('sessionStorage');
  var queueKey = 'stat4_retry_queue:' + endpoint;
  var pending = [];
  var visitorId = '';
  var visitorStarted = 0;
  var sessionId = '';
  var activeSeconds = 0;
  var lastTick = Date.now();
  var maxLevel = 0;
  var lastSentLevel = -1;
  var stopped = false;
  // One tracker instance represents this document, even if its address changes with history.pushState.
  var pageUrl = safeUrl(location.href);
  var pageReferrer = safeUrl(document.referrer);
  var pageSearch = location.search;
  var pageActive = !document.hidden && (!document.hasFocus || document.hasFocus());

  function safeStorage(name) {
    var result = { store: null, failed: false, memory: Object.create(null) };
    try {
      result.store = window[name];
      var key = '__stat4_storage_test__';
      result.store.setItem(key, '1');
      result.store.removeItem(key);
    } catch (error) { result.failed = true; }
    return result;
  }

  function getValue(store, key) {
    if (!store.failed) {
      try {
        var value = store.store.getItem(key) || '';
        store.memory[key] = value;
        return value;
      } catch (error) { store.failed = true; }
    }
    return store.memory[key] || '';
  }

  function setValue(store, key, value) {
    value = String(value);
    store.memory[key] = value;
    if (!store.failed) {
      try { store.store.setItem(key, value); }
      catch (error) { store.failed = true; }
    }
  }

  function removeValue(store, key) {
    delete store.memory[key];
    if (!store.failed) {
      try { store.store.removeItem(key); }
      catch (error) { store.failed = true; }
    }
  }

  function uuid() {
    try {
      if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
    } catch (error) {}
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (character) {
      var random = Math.random() * 16 | 0;
      return (character === 'x' ? random : (random & 3 | 8)).toString(16);
    });
  }

  function safeUrl(value) {
    try {
      var url = new URL(String(value || ''), location.href);
      if (!/^https?:$/.test(url.protocol)) return '';
      url.hash = '';
      url.username = '';
      url.password = '';
      return value ? url.href : '';
    } catch (error) { return ''; }
  }

  function currentUtm() {
    var params;
    try { params = new URLSearchParams(pageSearch); }
    catch (error) { params = { get: function () { return ''; } }; }
    return {
      source: params.get('utm_source') || '', medium: params.get('utm_medium') || '',
      campaign: params.get('utm_campaign') || '', term: params.get('utm_term') || '', content: params.get('utm_content') || ''
    };
  }

  function payload(type, now) {
    var root = document.documentElement;
    var timezone = '';
    try { timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (error) {}
    return {
      eventId: uuid(), type: type, visitorId: visitorId, visitorStarted: visitorStarted, sessionId: sessionId,
      // Delivery can be retried after a visitor window ends. Keep the original event time and identity.
      occurredAt: Math.max(visitorStarted, Math.min(now || Date.now(), visitorStarted + VISITOR_TTL - 1)),
      url: pageUrl, title: document.title || '', referrer: pageReferrer,
      screen: (window.screen ? window.screen.width : 0) + 'x' + (window.screen ? window.screen.height : 0),
      inner: (window.innerWidth || root.clientWidth || 0) + 'x' + (window.innerHeight || root.clientHeight || 0),
      language: navigator.language || '', timezone: timezone, userAgent: navigator.userAgent || '',
      level: maxLevel, activeSeconds: 0, utm: currentUtm()
    };
  }

  function persistPending() {
    if (pending.length) setValue(sessionStorage, queueKey, JSON.stringify(pending.map(function (item) {
      return { body: item.body, createdAt: item.createdAt, attempts: item.attempts, nextAttempt: item.nextAttempt };
    })));
    else removeValue(sessionStorage, queueKey);
  }

  function prunePending() {
    var now = Date.now();
    pending = pending.filter(function (item) { return now - item.createdAt <= QUEUE_TTL; });
  }

  function acknowledge(item) {
    var index = pending.indexOf(item);
    if (index !== -1) pending.splice(index, 1);
    persistPending();
  }

  function retryLater(item) {
    item.inFlight = false;
    item.nextAttempt = Date.now() + Math.min(60000, 1000 * Math.pow(2, Math.min(item.attempts, 6)));
    persistPending();
  }

  function deliver(item, preferBeacon) {
    // Beacon is also allowed for an in-flight fetch during pagehide; the UUID makes retries idempotent.
    if (preferBeacon && typeof navigator.sendBeacon === 'function') {
      try {
        if (navigator.sendBeacon(endpoint, new Blob([item.body], { type: 'text/plain;charset=UTF-8' }))) {
          acknowledge(item);
          return;
        }
      } catch (error) {}
    }
    if (item.inFlight || typeof window.fetch !== 'function') return;
    item.inFlight = true;
    item.attempts += 1;
    var attempt = item.attempts;
    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    var timeout = window.setTimeout(function () {
      if (item.inFlight && item.attempts === attempt) {
        retryLater(item);
        if (controller) controller.abort();
      }
    }, 10000);
    try {
      window.fetch(endpoint, {
        method: 'POST', headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, body: item.body,
        mode: 'cors', credentials: 'omit', keepalive: !!preferBeacon,
        signal: controller ? controller.signal : undefined
      }).then(function (response) {
        window.clearTimeout(timeout);
        if (!response.ok) throw new Error('STAT4 collector rejected the event');
        acknowledge(item);
      }).catch(function () {
        window.clearTimeout(timeout);
        if (item.attempts === attempt) retryLater(item);
      });
    } catch (error) {
      window.clearTimeout(timeout);
      retryLater(item);
    }
  }

  function drain(preferBeacon) {
    prunePending();
    pending.slice().forEach(function (item) {
      if (preferBeacon || (!item.inFlight && item.nextAttempt <= Date.now())) deliver(item, preferBeacon);
    });
  }

  function send(data, preferBeacon) {
    prunePending();
    if (pending.length >= QUEUE_LIMIT) return false;
    var item = { body: JSON.stringify(data), createdAt: Date.now(), attempts: 0, nextAttempt: 0, inFlight: false };
    pending.push(item);
    persistPending();
    deliver(item, preferBeacon);
    return true;
  }

  function snapshotActivity(type, preferBeacon, force, now) {
    if (!force && Math.floor(activeSeconds) === 0 && maxLevel === lastSentLevel) return false;
    var sent = false;
    do {
      var data = payload(type, now);
      // The collector accepts at most 300 seconds per event. Keep excess for the next snapshot.
      data.activeSeconds = Math.min(300, Math.floor(activeSeconds));
      if (!send(data, preferBeacon)) return sent;
      activeSeconds = Math.max(0, activeSeconds - data.activeSeconds);
      lastSentLevel = maxLevel;
      sent = true;
      type = 'heartbeat';
    } while (activeSeconds >= 1);
    return sent;
  }

  function ensureIdentity(now) {
    var storedId = getValue(storage, 'stat4_visitor');
    var storedStarted = Number(getValue(storage, 'stat4_visitor_started'));
    var validStarted = Number.isFinite(storedStarted) && storedStarted > 0 && storedStarted <= now + 300000;
    if (!storedId || (validStarted && now - storedStarted >= VISITOR_TTL)) {
      storedId = uuid();
      storedStarted = now;
    } else if (!validStarted) storedStarted = now;
    var changed = !!visitorId && visitorId !== storedId;
    if (changed) {
      snapshotActivity('heartbeat', false, false, now);
      // Fractions and a full failed queue must never be assigned to a different visitor.
      activeSeconds = 0;
      lastSentLevel = -1;
    }
    var sessionVisitor = getValue(sessionStorage, 'stat4_session_visitor');
    if (changed || (sessionVisitor && sessionVisitor !== storedId)) removeValue(sessionStorage, 'stat4_session');
    visitorId = storedId;
    visitorStarted = storedStarted;
    sessionId = getValue(sessionStorage, 'stat4_session') || uuid();
    if (getValue(storage, 'stat4_visitor') !== visitorId) setValue(storage, 'stat4_visitor', visitorId);
    if (Number(getValue(storage, 'stat4_visitor_started')) !== visitorStarted) setValue(storage, 'stat4_visitor_started', visitorStarted);
    setValue(sessionStorage, 'stat4_session', sessionId);
    setValue(sessionStorage, 'stat4_session_visitor', visitorId);
    if (changed) send(payload('pageview', now), true);
  }

  function tick() {
    var now = Date.now();
    if (!stopped) {
      var start = Math.max(lastTick, now - TICK_INTERVAL);
      var expiry = visitorStarted + VISITOR_TTL;
      if (pageActive) activeSeconds += Math.max(0, Math.min(now, expiry) - start) / 1000;
      ensureIdentity(now);
      if (pageActive && expiry <= now) activeSeconds += Math.max(0, now - Math.max(start, expiry)) / 1000;
    }
    lastTick = now;
  }

  function updateActivityState() {
    tick();
    pageActive = !document.hidden && (!document.hasFocus || document.hasFocus());
  }

  function updateScrollLevel() {
    if (document.readyState === 'loading') return;
    var root = document.documentElement;
    var body = document.body;
    var pageHeight = Math.max(root.scrollHeight, root.offsetHeight, root.clientHeight,
      body ? body.scrollHeight : 0, body ? body.offsetHeight : 0);
    var viewport = window.innerHeight || root.clientHeight || 0;
    var scrollable = Math.max(0, pageHeight - viewport);
    var position = window.scrollY || root.scrollTop || 0;
    var level = scrollable > 0 ? Math.round(position / scrollable * 100) : 100;
    maxLevel = Math.max(maxLevel, Math.max(0, Math.min(100, level)));
  }

  function flush(type, preferBeacon, force) {
    if (stopped) return false;
    tick();
    updateScrollLevel();
    drain(preferBeacon);
    return snapshotActivity(type, preferBeacon, force, Date.now());
  }

  function targetDescription(target) {
    var element = target && target.nodeType === 1 ? target : target && target.parentElement;
    if (!element || typeof element.closest !== 'function') return '';
    element = element.closest('a,button,input,select,textarea,[role="button"]');
    if (!element) return '';
    var tag = String(element.tagName || element.nodeName || '').toLowerCase();
    // Form controls can contain credentials and free-form personal data. Never inspect their content.
    if (tag === 'input' || tag === 'select' || tag === 'textarea') {
      if (String(element.type || '').toLowerCase() === 'password') return 'input:password';
      return String(element.name || element.id || tag).trim().slice(0, 2048);
    }
    return String(element.href ? safeUrl(element.href) : (element.name || element.id || element.textContent || ''))
      .trim().slice(0, 2048);
  }

  try {
    var saved = JSON.parse(getValue(sessionStorage, queueKey) || '[]');
    if (Array.isArray(saved)) saved.slice(-QUEUE_LIMIT).forEach(function (item) {
      if (!item || typeof item.body !== 'string' || item.body.length > 65536 || !Number.isFinite(item.createdAt)) return;
      if (Date.now() - item.createdAt < 0 || Date.now() - item.createdAt > QUEUE_TTL) return;
      var data = JSON.parse(item.body);
      if (!data || typeof data.eventId !== 'string' || !Number.isFinite(data.occurredAt)) return;
      pending.push({ body: item.body, createdAt: item.createdAt, attempts: Math.max(0, Number(item.attempts) || 0), nextAttempt: 0, inFlight: false });
    });
  } catch (error) {}
  ensureIdentity(Date.now());
  updateScrollLevel();
  document.addEventListener('DOMContentLoaded', updateScrollLevel, { once: true });
  window.addEventListener('scroll', updateScrollLevel, { passive: true });
  window.addEventListener('resize', updateScrollLevel, { passive: true });
  window.addEventListener('focus', updateActivityState, { passive: true });
  window.addEventListener('blur', updateActivityState, { passive: true });
  window.addEventListener('storage', function (event) {
    if (event.key === null || event.key === 'stat4_visitor' || event.key === 'stat4_visitor_started') tick();
  });
  window.addEventListener('online', function () { if (!stopped) drain(false); });
  document.addEventListener('click', function (event) {
    if (stopped) return;
    var target = targetDescription(event.target);
    if (!target) return;
    tick();
    var data = payload('click');
    data.target = target;
    send(data, true);
  }, true);
  document.addEventListener('visibilitychange', function () {
    updateActivityState();
    if (document.hidden) flush('heartbeat', true, false);
  });
  window.addEventListener('pagehide', function () {
    if (stopped) return;
    flush('leave', true, true);
    stopped = true;
    pageActive = false;
  }, { passive: true });
  window.addEventListener('pageshow', function (event) {
    if (!event.persisted) return;
    stopped = false;
    lastTick = Date.now();
    pageActive = !document.hidden && (!document.hasFocus || document.hasFocus());
    ensureIdentity(lastTick);
    updateScrollLevel();
    drain(false);
  }, { passive: true });
  window.setInterval(tick, TICK_INTERVAL);
  window.setInterval(function () { if (!stopped) flush('heartbeat', false, false); }, HEARTBEAT_INTERVAL);
  drain(false);
  send(payload('pageview'), true);
})();
