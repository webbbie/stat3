(() => {
  'use strict';

  const currentScript = document.currentScript;

  const CONFIG = Object.freeze({
    endpoint:
      currentScript?.dataset.endpoint ||
      'https://bayerchristian.de/stats3/ppcmate-postback.php',
    storageKey: 'ppcmate_attribution_v1',
    lifetimeMs: 30 * 24 * 60 * 60 * 1000,
    allowedHosts: new Set([
      'inconsequential.org',
      'www.inconsequential.org'
    ])
  });

  const browserCrypto = globalThis.crypto;

  const text = (value, maximumLength) => {
    const cleaned = String(value ?? '')
      .replace(/[\u0000-\u001f\u007f]/gu, '')
      .trim();

    return cleaned ? cleaned.slice(0, maximumLength) : null;
  };

  const storage = Object.freeze({
    get() {
      try {
        return localStorage.getItem(CONFIG.storageKey);
      } catch {
        return null;
      }
    },

    set(value) {
      try {
        localStorage.setItem(CONFIG.storageKey, value);
      } catch {
        // Tracking still works for the current page when storage is blocked.
      }
    },

    remove() {
      try {
        localStorage.removeItem(CONFIG.storageKey);
      } catch {
        // Nothing else is required.
      }
    }
  });

  const createClientId = () => {
    if (typeof browserCrypto?.randomUUID === 'function') {
      return browserCrypto.randomUUID();
    }

    const bytes = new Uint8Array(24);

    if (typeof browserCrypto?.getRandomValues === 'function') {
      browserCrypto.getRandomValues(bytes);
    } else {
      for (let index = 0; index < bytes.length; index += 1) {
        bytes[index] = Math.floor(Math.random() * 256);
      }
    }

    return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
  };

  const isValidAttribution = attribution =>
    attribution &&
    typeof attribution === 'object' &&
    typeof attribution.clientId === 'string' &&
    attribution.clientId.length >= 16 &&
    typeof attribution.cid === 'string' &&
    attribution.cid.length > 0 &&
    Number.isFinite(attribution.savedAt) &&
    Date.now() - attribution.savedAt <= CONFIG.lifetimeMs;

  const loadAttribution = () => {
    const raw = storage.get();

    if (!raw) {
      return null;
    }

    try {
      const attribution = JSON.parse(raw);

      if (isValidAttribution(attribution)) {
        return attribution;
      }
    } catch {
      // Invalid or outdated data is discarded below.
    }

    storage.remove();
    return null;
  };

  let memoryAttribution = loadAttribution();

  const saveAttribution = attribution => {
    memoryAttribution = attribution;
    storage.set(JSON.stringify(attribution));
    return attribution;
  };

  const readCampaignParameters = () => {
    const parameters = new URLSearchParams(location.search);
    const cid = text(
      parameters.get('cid') || parameters.get('trackingId'),
      512
    );

    if (!cid) {
      return null;
    }

    return {
      clientId: memoryAttribution?.clientId || createClientId(),
      cid,
      cost: text(parameters.get('cost'), 50),
      campaign: text(parameters.get('campaign'), 255),
      zone: text(parameters.get('zone'), 100),
      ssp: text(parameters.get('ssp'), 100),
      geo: text(parameters.get('geo'), 3),
      page: location.href,
      savedAt: Date.now()
    };
  };

  const send = async payload => {
    const body = JSON.stringify(payload);
    let response;

    try {
      response = await fetch(CONFIG.endpoint, {
        method: 'POST',
        mode: 'cors',
        credentials: 'omit',
        cache: 'no-store',
        keepalive: true,
        headers: {
          'Content-Type': 'text/plain;charset=UTF-8'
        },
        body
      });
    } catch (error) {
      if (
        typeof navigator.sendBeacon === 'function' &&
        navigator.sendBeacon(
          CONFIG.endpoint,
          new Blob([body], { type: 'text/plain;charset=UTF-8' })
        )
      ) {
        return { ok: true, queued: true };
      }

      throw error;
    }

    if (!response.ok) {
      throw new Error(`Tracking endpoint returned HTTP ${response.status}.`);
    }

    return await response.json();
  };

  const capture = () => {
    const newAttribution = readCampaignParameters();

    if (newAttribution) {
      saveAttribution(newAttribution);
    }

    const attribution = memoryAttribution;

    if (!isValidAttribution(attribution)) {
      return Promise.resolve({ ok: false, reason: 'no-attribution' });
    }

    return send({
      action: 'capture',
      clientId: attribution.clientId,
      cid: attribution.cid,
      cost: attribution.cost,
      campaign: attribution.campaign,
      zone: attribution.zone,
      ssp: attribution.ssp,
      geo: attribution.geo,
      page: attribution.page || location.href
    });
  };

  let capturePromise = Promise.resolve({ ok: false });

  const conversion = async (value = 0, eventKey = 'default') => {
    const attribution = memoryAttribution || loadAttribution();

    if (!isValidAttribution(attribution)) {
      return { ok: false, sent: false, reason: 'no-attribution' };
    }

    await capturePromise.catch(() => null);

    return send({
      action: 'conversion',
      clientId: attribution.clientId,
      value,
      eventKey: text(eventKey, 200) || 'default',
      page: location.href
    });
  };

  const automaticConversion = event => {
    const element = event.target.closest?.('[data-ppcmate-conversion]');

    if (!element) {
      return;
    }

    const value = element.dataset.ppcmateValue || '0';
    const eventKey =
      element.dataset.ppcmateKey ||
      [
        'click',
        location.pathname,
        element.id || element.getAttribute('href') || element.tagName
      ].join(':');

    void conversion(value, eventKey).catch(() => null);
  };

  if (!CONFIG.allowedHosts.has(location.hostname.toLowerCase())) {
    return;
  }

  window.PpcmateTracker = Object.freeze({
    capture,
    conversion,
    getAttribution: () => memoryAttribution || loadAttribution()
  });

  document.addEventListener('click', automaticConversion, true);
  capturePromise = capture();
  void capturePromise.catch(() => null);
})();
