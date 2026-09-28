/**
 * count.js - Shared Stats3/STAT4 analytics collector and Captcha integration
 * Public settings are loaded from configurator2.php on the central server.
 * Form values and URL fragments are never used as tracking metadata.
 */

(() => {
  "use strict";

  if (window.__pixlCountConfigStarted) return;
  window.__pixlCountConfigStarted = true;
  // Capture the embedding script before the asynchronous configuration request.
  const countScript = document.currentScript;
  let pageHidden = false;
  let configReady = null;
  let started = false;
  const onPageHide = () => { pageHidden = true; };
  const onPageShow = event => {
    if (!event.persisted) return;
    pageHidden = false;
    maybeStart();
  };
  const detach = () => {
    window.removeEventListener("pagehide", onPageHide);
    window.removeEventListener("pageshow", onPageShow);
  };
  const maybeStart = () => {
    if (started || pageHidden || !configReady) return;
    started = true;
    startTrackers(configReady);
  };
  window.addEventListener("pagehide", onPageHide, { passive: true });
  window.addEventListener("pageshow", onPageShow, { passive: true });

  function prepareConfig(data) {
    if (!data || data.ok !== true || data.schemaVersion !== 1 || !data.config
        || typeof data.config !== "object" || Array.isArray(data.config)) {
      throw new Error("invalid_configuration");
    }
    const config = { ...data.config };
    // Validate the public JSON contract; setting values live only in configurator2.php.
    const fields = {
      string: "VERSION SQL_ENDPOINT SQL_SITE_ID SQL_PUBLIC_KEY FINGERPRINT_EXCLUDE.BROWSER_IS FINGERPRINT_EXCLUDE.OS_IS FINGERPRINT_EXCLUDE.DEVICE_IS FINGERPRINT_EXCLUDE.COUNTRY_IS SQL.GLOBAL_COOLDOWN_KEY SQL.FINAL_SUMMARY_REASON SQL.RETRY_QUEUE_KEY USER_SESSION.STORAGE_KEY STAT4.ENDPOINT",
      boolean: "EXCLUDE_CHROME DEBUG.ENABLED DEBUG.FORCE_NOTIFY DEBUG.BYPASS_FILTERS FINGERPRINT_EXCLUDE.ENABLED SQL.BLOCK_IFRAMES SQL.INCLUDE_CONSOLE_LOGS SQL.INCLUDE_RENDER_ISSUES SQL.NOTIFY_ON_VISIT SQL.NOTIFY_ON_READ SQL.NOTIFY_ON_LEAVE SQL.NOTIFY_ON_HIDDEN SQL.FINAL_SUMMARY_ONLY SQL.ONE_AUTO_NOTIFICATION_ONLY SQL.TRACK_BOTS_IMMEDIATELY SQL.RETRY_QUEUE_ENABLED CONSOLE_SPY.ENABLED DEVICE_DETECT.ENABLED RENDER_HEALTH.ENABLED READING_TRACKER.ENABLED",
      number: "READING.MIN_SCORE READING.THRESHOLDS.READ READING.THRESHOLDS.GOOD READING.THRESHOLDS.EXCELLENT SQL.MIN_SCORE_TO_NOTIFY SQL.MAX_NOTIFICATIONS_PER_PAGE SQL.SESSION_COOLDOWN_MS SQL.VISIT_DELAY_MS SQL.READ_DELAY_MS SQL.READ_RECHECK_MS SQL.FETCH_TIMEOUT_MS SQL.RETRY_QUEUE_MAX_ITEMS USER_SESSION.TIMEOUT_MINUTES USER_SESSION.PAGE_RECOUNT_MINUTES CONSOLE_SPY.MAX_ENTRIES CONSOLE_SPY.MAX_MESSAGE_LENGTH RENDER_HEALTH.INTERVAL_MS RENDER_HEALTH.MAX_FAILED_CHECKS RENDER_HEALTH.MAX_CONSOLE_ERRORS READING_TRACKER.SAMPLE_LENGTH READING_TRACKER.MAX_SAMPLES STAT4.VISITOR_TTL STAT4.HEARTBEAT_INTERVAL STAT4.TICK_INTERVAL"
    };
    for (const [type, paths] of Object.entries(fields)) {
      for (const path of paths.split(" ")) {
        const value = path.split(".").reduce((object, key) => object?.[key], config);
        if (typeof value !== type || (type === "number" && (!Number.isFinite(value) || value < 0))) {
          throw new Error("invalid_configuration_field: " + path);
        }
      }
    }
    // Optional alternatives keep older public configurations compatible.
    const fingerprintRules = config.FINGERPRINT_EXCLUDE.RULES;
    if (fingerprintRules !== undefined && !Array.isArray(fingerprintRules)) {
      throw new Error("invalid_configuration_fingerprint_rules");
    }
    for (const rule of [config.FINGERPRINT_EXCLUDE, ...(fingerprintRules || [])]) {
      if (!rule || typeof rule !== "object" || Array.isArray(rule)) {
        throw new Error("invalid_configuration_fingerprint_rule");
      }
      for (const field of ["BROWSER_IS", "OS_IS", "DEVICE_IS", "COUNTRY_IS", "USER_AGENT_CONTAINS"]) {
        if (rule[field] !== undefined && typeof rule[field] !== "string") {
          throw new Error("invalid_configuration_fingerprint_field: " + field);
        }
      }
      if (rule.SCREEN_IS !== undefined && (!Array.isArray(rule.SCREEN_IS)
          || !rule.SCREEN_IS.every(value => typeof value === "string" && /^[1-9]\d*x[1-9]\d*$/.test(value)))) {
        throw new Error("invalid_configuration_fingerprint_screen");
      }
      if (rule.PIXEL_RATIO_IS !== undefined && (typeof rule.PIXEL_RATIO_IS !== "number"
          || !Number.isFinite(rule.PIXEL_RATIO_IS) || rule.PIXEL_RATIO_IS <= 0)) {
        throw new Error("invalid_configuration_fingerprint_pixel_ratio");
      }
    }
    // Delays may be zero (run immediately); recurring timers and sample windows may not.
    for (const path of "SQL.READ_RECHECK_MS SQL.FETCH_TIMEOUT_MS USER_SESSION.TIMEOUT_MINUTES READING_TRACKER.SAMPLE_LENGTH READING_TRACKER.MAX_SAMPLES RENDER_HEALTH.INTERVAL_MS".split(" ")) {
      if (path.split(".").reduce((object, key) => object[key], config) <= 0) {
        throw new Error("invalid_configuration_positive_field: " + path);
      }
    }
    if (config.SQL.RETRY_QUEUE_ENABLED && config.SQL.RETRY_QUEUE_MAX_ITEMS < 1) {
      throw new Error("invalid_configuration_retry_queue_size");
    }
    if (!config.SQL.FINAL_SUMMARY_REASON.trim()
        || config.READING.THRESHOLDS.READ > config.READING.THRESHOLDS.GOOD
        || config.READING.THRESHOLDS.GOOD > config.READING.THRESHOLDS.EXCELLENT) {
      throw new Error("invalid_configuration_summary_or_thresholds");
    }
    const strings = value => Array.isArray(value) && value.every(item => typeof item === "string");
    if (!strings(config.ALLOWED_DOMAINS) || !strings(config.KNOWN_RESOLUTIONS)
        || !strings(config.SQL.RETRY_QUEUE_LEGACY_KEYS)
        || (config.ACCEPTED_OS !== null && !strings(config.ACCEPTED_OS))
        || !config.BOT_PATTERNS || typeof config.BOT_PATTERNS !== "object"
        || Array.isArray(config.BOT_PATTERNS) || !Object.values(config.BOT_PATTERNS).every(strings)
        || config.STAT4.VISITOR_TTL <= 0 || config.STAT4.VISITOR_TTL > 86400000 || config.STAT4.HEARTBEAT_INTERVAL <= 0
        || config.STAT4.TICK_INTERVAL <= 0) {
      throw new Error("invalid_configuration_lists_or_intervals");
    }
    for (const endpoint of [config.SQL_ENDPOINT, config.STAT4.ENDPOINT]) {
      const url = new URL(endpoint);
      if (!["https:", "http:"].includes(url.protocol) || url.username || url.password) {
        throw new Error("invalid_configuration_endpoint");
      }
    }
    config.KNOWN_RESOLUTIONS = new Set(config.KNOWN_RESOLUTIONS);
    if (config.ACCEPTED_OS !== null) config.ACCEPTED_OS = new Set(config.ACCEPTED_OS.map(os => os.toLowerCase()));
    return Object.freeze(config);
  }

  async function loadConfiguration() {
    // The bootstrap address is the only central-server address needed by this JS file.
    const url = new URL(countScript?.dataset?.configUrl || "https://www.bayerchristian.de/stats3/configurator2.php",
      countScript?.src || location.href);
    if (!["https:", "http:"].includes(url.protocol) || url.username || url.password) {
      throw new Error("invalid_configuration_url");
    }
    const controller = typeof AbortController === "function" ? new AbortController() : null;
    let timer;
    try {
      return await Promise.race([
        (async () => {
          const response = await fetch(url.href, {
            method: "GET", mode: "cors", credentials: "omit", cache: "no-store",
            ...(controller ? { signal: controller.signal } : {})
          });
          if (!response.ok) throw new Error("configuration_http_" + response.status);
          return prepareConfig(await response.json());
        })(),
        new Promise((_, reject) => {
          timer = setTimeout(() => {
            if (controller) controller.abort();
            reject(new Error("configuration_timeout"));
          }, 4500);
        })
      ]);
    } finally {
      clearTimeout(timer);
    }
  }

  loadConfiguration().then(config => {
    configReady = config;
    maybeStart();
  }).catch(error => {
    detach();
    if (!started) window.__pixlCountConfigStarted = false;
    if (window.console && typeof window.console.warn === "function") {
      window.console.warn("Stats3 configuration could not be loaded; tracker startup skipped.", error.message);
    }
  });

  function startTrackers(CONFIG) {
  function pageUrl(value) {
    try {
      const url = new URL(value, location.href);
      if (!['https:', 'http:'].includes(url.protocol)) return '';
      url.hash = ''; url.username = ''; url.password = '';
      return url.href;
    } catch { return ''; }
  }
(() => {
  "use strict";

  const RC_OVERLAY_SESSION_KEY = "__rcOverlaySession";

  function readV3UserScore() {
    try {
      if (typeof window.__v3UserScore === "number" && !Number.isNaN(window.__v3UserScore)) {
        return window.__v3UserScore;
      }
      const raw = window.sessionStorage?.getItem(RC_OVERLAY_SESSION_KEY);
      if (!raw) return null;
      const data = JSON.parse(raw);
      if (data && typeof data.score === "number" && !Number.isNaN(data.score)) {
        window.__v3UserScore = data.score;
        return data.score;
      }
    } catch {}
    return null;
  }
  const SCRIPT_NAME = (() => {
    try {
      const script = countScript;
      const src = script?.getAttribute("src");
      if (src) {
        const url = new URL(src, location.href);
        return url.pathname.split("/").pop() || "pixl77.js";
      }
      return "pixl77.js";
    } catch {
      return "pixl77.js";
    }
  })();

  // Flatten bot patterns for faster lookup
  const BOT_PATTERNS_FLAT = new Map();
  Object.entries(CONFIG.BOT_PATTERNS).forEach(([category, patterns]) => {
    patterns.forEach(p => BOT_PATTERNS_FLAT.set(p, category));
  });

  // =======================
  // Utility Helpers
  // =======================
  const Utils = {
    now: () => typeof performance !== "undefined" ? performance.now() : Date.now(),

    clamp: (val, min, max) => Math.max(min, Math.min(max, val)),

    lerp: (a, b, t) => a + (b - a) * t,

    formatLanguage(lang) {
      if (!lang) return "Unknown";
      const short = String(lang).trim().replace(/_/g, "-").split("-")[0].toLowerCase();
      try {
        if (typeof Intl !== "undefined" && typeof Intl.DisplayNames === "function") {
          const name = new Intl.DisplayNames(["en"], { type: "language" }).of(short);
          if (name && name.toLowerCase() !== short) return name;
        }
      } catch {}
      const map = {
        ar: "Arabic", bg: "Bulgarian", cs: "Czech", da: "Danish", de: "German",
        el: "Greek", en: "English", es: "Spanish", et: "Estonian", fi: "Finnish",
        fr: "French", he: "Hebrew", hi: "Hindi", hr: "Croatian", hu: "Hungarian",
        id: "Indonesian", it: "Italian", ja: "Japanese", ko: "Korean", lt: "Lithuanian",
        lv: "Latvian", nl: "Dutch", no: "Norwegian", pl: "Polish", pt: "Portuguese",
        ro: "Romanian", ru: "Russian", sk: "Slovak", sl: "Slovenian", sr: "Serbian",
        sv: "Swedish", th: "Thai", tr: "Turkish", uk: "Ukrainian", ur: "Urdu",
        vi: "Vietnamese", zh: "Chinese"
      };
      return map[short] || short || "Unknown";
    },

    formatCountry(countryCode) {
      const code = Utils.normalizeCountryCode(countryCode);
      if (!code) return "Unknown";
      try {
        if (typeof Intl !== "undefined" && typeof Intl.DisplayNames === "function") {
          const name = new Intl.DisplayNames(["en"], { type: "region" }).of(code);
          if (name && name.toUpperCase() !== code) return name;
        }
      } catch {}
      const map = {
        AT: "Austria", AU: "Australia", BE: "Belgium", BG: "Bulgaria", BR: "Brazil",
        CA: "Canada", CH: "Switzerland", CN: "China", CZ: "Czechia", DE: "Germany",
        DK: "Denmark", EE: "Estonia", ES: "Spain", FI: "Finland", FR: "France",
        GB: "United Kingdom", GR: "Greece", HR: "Croatia", HU: "Hungary", ID: "Indonesia",
        IE: "Ireland", IN: "India", IS: "Iceland", IT: "Italy", JP: "Japan",
        KR: "South Korea", LT: "Lithuania", LU: "Luxembourg", LV: "Latvia", MX: "Mexico",
        MY: "Malaysia", NL: "Netherlands", NO: "Norway", NZ: "New Zealand", PH: "Philippines",
        PK: "Pakistan", PL: "Poland", PT: "Portugal", RO: "Romania", RS: "Serbia",
        RU: "Russia", SE: "Sweden", SG: "Singapore", SI: "Slovenia", SK: "Slovakia",
        TH: "Thailand", TR: "Turkey", TW: "Taiwan", UA: "Ukraine", US: "United States",
        VN: "Vietnam", ZA: "South Africa"
      };
      return map[code] || code;
    },

    formatScreen: (w, h) => `${w}x${h}`,

    normalizeCountryCode: (value) => {
      if (typeof value !== "string") return "";
      const trimmed = value.trim().replace(/_/g, "-");
      if (["germany", "deutschland", "germany (deutschland)"].includes(trimmed.toLowerCase())) return "DE";
      if (/^[A-Za-z]{2}$/.test(trimmed)) return trimmed.toUpperCase();
      const parts = trimmed.split("-");
      for (let index = parts.length - 1; index > 0; index -= 1) {
        if (/^[A-Za-z]{2}$/.test(parts[index])) return parts[index].toUpperCase();
      }
      return "";
    },

    getCountryCode() {
      try {
        if (typeof window !== "undefined" && typeof window.VISITOR_COUNTRY === "string") {
          const direct = Utils.normalizeCountryCode(window.VISITOR_COUNTRY);
          if (direct) return direct;
        }
        const doc = typeof document !== "undefined" ? document.documentElement : null;
        if (doc) {
          const attr = Utils.normalizeCountryCode(doc.getAttribute("data-country"));
          if (attr) return attr;
        }
      } catch {}
      return Utils.parseCountryFromLang((typeof navigator !== "undefined" && navigator.language) || "") || "";
    },

    isAllowedHostname: (hostname) => CONFIG.ALLOWED_DOMAINS.some(d => hostname === d || hostname.endsWith(`.${d}`)),

    isAutomationBrowser: () => {
      try {
        return navigator?.webdriver === true;
      } catch {
        return false;
      }
    },

    analyzeBot(ua) {
      const source = ua || "";
      const lowered = source.toLowerCase();
      const reasons = [];
      let score = 0;
      let category = "";
      let name = "";

      const add = (points, reason, nextCategory, nextName) => {
        score += points;
        if (reason && !reasons.includes(reason)) reasons.push(reason);
        if (!category && nextCategory) category = nextCategory;
        if (!name && nextName) name = nextName;
      };

      if (Utils.isAutomationBrowser()) {
        add(60, "navigator.webdriver", "automation", "webdriver");
      }

      // Check flattened patterns
      for (const [pattern, patternCategory] of BOT_PATTERNS_FLAT) {
        if (lowered.includes(pattern)) {
          add(35, `pattern:${pattern}`, patternCategory, pattern);
        }
      }

      if (!source.trim()) {
        add(25, "missing-user-agent", "unknown", "Unknown UA");
      }

      if (/^(curl|wget|python|java|okhttp|go-http|php|node)/i.test(source)) {
        add(35, "tool-like-prefix", "tool", name || "HTTP tool");
      }

      if (lowered.includes("headless")) {
        add(45, "headless-browser", "automation", name || "Headless browser");
      }

      score = Utils.clamp(score, 0, 100);
      return {
        isBot: score >= 35,
        score,
        category: category || (score >= 35 ? "unknown" : "human"),
        name: name || (score >= 35 ? "Unknown bot" : "Human-like"),
        reasons
      };
    },

    safeJSON: (value) => {
      try {
        return JSON.stringify(value);
      } catch {
        return '"[unserializable]"';
      }
    },

    sanitizeLogMessage(value, maxLength = 200) {
      const source = typeof value === "string" ? value : String(value || "");
      const cleanedUrls = source.replace(/https?:\/\/\S+/gi, (raw) => {
        const normalized = raw.replace(/[),.;]+$/, "");
        try {
          const url = new URL(normalized);
          return `${url.origin}${url.pathname}`;
        } catch {
          return normalized.split("?")[0].split("#")[0];
        }
      });
      const normalized = cleanedUrls.replace(/\s+/g, " ").trim();
      return !normalized ? "" : normalized.length > maxLength ? `${normalized.slice(0, maxLength)}…` : normalized;
    },

    uniqueStrings: (values) => [...new Set((values || []).filter(Boolean))],

    parseCountryFromLang: (lang) => {
      if (!lang) return null;
      const parts = lang.split("-");
      return parts.length > 1 ? parts[1].toUpperCase() : null;
    },

    makeEventId: () => {
      try {
        if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
          return crypto.randomUUID();
        }
      } catch {}
      return [Date.now().toString(36), Math.random().toString(36).slice(2, 10), Math.random().toString(36).slice(2, 10)].join("-");
    },

    safeStorage: (type) => {
      try {
        const store = window[type];
        const key = "__pixl77_storage_test__";
        store.setItem(key, "1");
        store.removeItem(key);
        return store;
      } catch {
        return null;
      }
    },

    getPageSession() {
      const now = Date.now();
      const timeoutMinutes = CONFIG.USER_SESSION.TIMEOUT_MINUTES;
      const recountMinutes = CONFIG.USER_SESSION.PAGE_RECOUNT_MINUTES;
      const timeoutMs = timeoutMinutes * 60 * 1000;
      const recountMs = recountMinutes * 60 * 1000;
      const pageKey = `${String(location.hostname || "").toLowerCase()}${location.pathname || "/"}`;
      const navigationEntry = (() => {
        try {
          return performance.getEntriesByType("navigation")[0] || null;
        } catch {
          return null;
        }
      })();
      const isReload = navigationEntry
        ? navigationEntry.type === "reload"
        : (() => {
            try {
              return performance.navigation?.type === 1;
            } catch {
              return false;
            }
          })();
      const storage = Utils.safeStorage("localStorage") || Utils.safeStorage("sessionStorage");
      let session = null;

      if (storage) {
        try {
          session = JSON.parse(storage.getItem(CONFIG.USER_SESSION.STORAGE_KEY) || "null");
        } catch {
          session = null;
        }
      }

      if (
        !session ||
        typeof session !== "object" ||
        typeof session.id !== "string" ||
        !Number.isFinite(Number(session.lastSeen)) ||
        now - Number(session.lastSeen) >= timeoutMs
      ) {
        session = { id: Utils.makeEventId(), lastSeen: now, pages: {} };
      }

      if (!session.pages || typeof session.pages !== "object") {
        session.pages = {};
      }

      const storedPage = session.pages[pageKey];
      const reusePage = !!(
        storedPage &&
        typeof storedPage.eventId === "string" &&
        Number.isFinite(Number(storedPage.startedAt)) &&
        (isReload || now - Number(storedPage.startedAt) < recountMs)
      );
      const page = reusePage
        ? storedPage
        : { eventId: Utils.makeEventId(), startedAt: now };

      session.lastSeen = now;
      session.pages[pageKey] = page;
      if (storage) {
        try {
          storage.setItem(CONFIG.USER_SESSION.STORAGE_KEY, JSON.stringify(session));
        } catch {}
      }

      return {
        id: session.id,
        eventId: page.eventId,
        pageKey,
        pageRecountMinutes: recountMinutes,
        reused: reusePage,
        reload: isReload,
        touch() {
          if (!storage) return;
          try {
            // Another tab may have added pages or started a newer session.
            const latest = JSON.parse(storage.getItem(CONFIG.USER_SESSION.STORAGE_KEY) || "null");
            if (!latest || latest.id !== session.id) return;
            // A navigation after a long idle period must not revive the expired session.
            if (Date.now() - Number(latest.lastSeen) >= timeoutMs) return;
            latest.lastSeen = Math.max(Number(latest.lastSeen) || 0, Date.now());
            storage.setItem(CONFIG.USER_SESSION.STORAGE_KEY, JSON.stringify(latest));
          } catch {}
        }
      };
    }
  };

  // =======================
  // Console Spy
  // =======================
  class ConsoleSpy {
    constructor(maxEntries = 50, maxLength = 200) {
      this.maxEntries = maxEntries;
      this.maxLength = maxLength;
      this.entries = [];
      this.originalConsole = {};
      this.wrappers = {};
      this.installed = false;
    }

    install() {
      if (this.installed || !CONFIG.CONSOLE_SPY.ENABLED) return;

      ["log", "warn", "error"].forEach((method) => {
        const original = console[method];
        if (typeof original !== "function") return;

        const wrapper = (...args) => {
          try {
            this._record(method, args);
          } catch {}
          original.apply(console, args);
        };
        // Host pages may freeze individual console methods or the whole console.
        try {
          console[method] = wrapper;
          if (console[method] === wrapper) {
            this.originalConsole[method] = original;
            this.wrappers[method] = wrapper;
          }
        } catch {}
      });

      this.installed = true;
    }

    uninstall() {
      if (!this.installed) return;
      ["log", "warn", "error"].forEach((method) => {
        try {
          if (console[method] === this.wrappers[method]) console[method] = this.originalConsole[method];
        } catch {}
      });
      this.installed = false;
    }

    _record(level, args) {
      const now = new Date().toISOString();
      const msg = args.map(arg => {
        if (arg && (arg instanceof Error || Object.prototype.toString.call(arg) === "[object Error]")) {
          return `${arg.name || 'Error'}: ${arg.message || ''}`;
        }
        return typeof arg === "string" ? arg : Utils.safeJSON(arg);
      }).join(" ");
      const trimmed = Utils.sanitizeLogMessage(msg, this.maxLength);

      this.entries.push({ time: now, level, message: trimmed });
      if (this.entries.length > this.maxEntries) this.entries.shift();
    }

    getEntries() { return this.entries.slice(); }
    getErrorCount() { return this.entries.filter(e => e.level === "error").length; }
  }

  // =======================
  // Device & Context Detection
  // =======================
  class DeviceDetector {
    constructor() {
      this.ua = navigator?.userAgent || "";
    }

    getBrowser() {
      if (!CONFIG.DEVICE_DETECT.ENABLED) return "Unknown";
      const ua = this.ua.toLowerCase();
      if (!ua || Utils.analyzeBot(this.ua).isBot) return "Unknown";

      const checks = [
        [/crios\//i, "chrome"],
        [/fxios\//i, "firefox"],
        [/edgios\//i, "edge"],
        [/samsungbrowser\//i, "samsung internet"],
        [/edg\//i, "edge"],
        [/opr\/|opera\//i, "opera"],
        [/firefox\/|fennec\//i, "firefox"],
        [/chrome\/|chromium\//i, "chrome"],
        [/version\/.*safari\//i, "safari"],
        [/msie\s|trident\//i, "internet explorer"]
      ];

      for (const [pattern, name] of checks) {
        if (pattern.test(this.ua)) return name;
      }
      return "Unknown";
    }

    getOS() {
      if (!CONFIG.DEVICE_DETECT.ENABLED) return "Unknown";
      const ua = this.ua.toLowerCase();
      if (!ua || Utils.analyzeBot(this.ua).isBot) return "Unknown";

      const checks = [
        [/android/i, "android"],
        [/iphone|ipad|ipod/i, "ios"],
        [/windows nt/i, "windows"],
        [/cros/i, "chromeos"],
        [/mac os x|macintosh/i, "macos"],
        [/linux|x11/i, "linux"]
      ];

      for (const [pattern, name] of checks) {
        if (pattern.test(this.ua)) return name;
      }
      return "Unknown";
    }

    getDeviceType() {
      const ua = this.ua.toLowerCase();
      const os = this.getOS();
      if (!ua || os === "Unknown") return "Unknown";

      const checks = [
        [/ipad/i, "Tablet"],
        [/tablet|playbook|silk/i, "Tablet"],
        [/android(?!.*mobile)/i, "Tablet"],
        [/android.*mobile|iphone|ipod|windows phone/i, "Mobile"],
        [/mobi/i, "Mobile"]
      ];

      for (const [pattern, name] of checks) {
        if (pattern.test(this.ua)) return name;
      }

      return ["windows", "macos", "linux", "chromeos"].includes(os) ? "Desktop" : "Unknown";
    }

    getScreenCategory() {
      const w = window.innerWidth;
      if (w < 480) return "xs";
      if (w < 768) return "sm";
      if (w < 1024) return "md";
      if (w < 1440) return "lg";
      return "xl";
    }

    getScreenResolution() {
      const width = window.screen.width;
      const height = window.screen.height;
      const res = Utils.formatScreen(width, height);
      return { value: res, known: CONFIG.KNOWN_RESOLUTIONS.has(res) };
    }

    getLanguage() { return navigator.language || "en"; }
    getPath() { return (location.pathname || "/").replace(/^\/+/, ""); }
    getCountryGuess() { return Utils.getCountryCode() || "Unknown"; }
  }

  // =======================
  // Reading Tracker
  // =======================
  class ReadingTracker {
    constructor(config) {
      this.samples = [];
      this.sampleLength = config.SAMPLE_LENGTH;
      this.maxSamples = config.MAX_SAMPLES;
      this.enabled = config.ENABLED !== false;
      this.lastActivity = Utils.now();
      this.lastScrollY = window.scrollY;
      this.lastMouseMove = { x: 0, y: 0 };
      this.hasInteracted = false;
      this.startTime = Date.now();
      this._onActivity = this._onActivity.bind(this);
    }

    install() {
      if (!this.enabled) return;
      ["scroll", "mousemove", "keydown", "touchstart"].forEach(type => {
        window.addEventListener(type, this._onActivity, { passive: true });
      });
    }

    uninstall() {
      ["scroll", "mousemove", "keydown", "touchstart"].forEach(type => {
        window.removeEventListener(type, this._onActivity);
      });
    }

    _onActivity(event) {
      // The required CAPTCHA move must not inflate the page's ReadingScore.
      if (document.documentElement.classList.contains("swipe-gate-locked")) return;
      const now = Utils.now();
      const dt = now - this.lastActivity;
      this.lastActivity = now;
      let delta = 0;

      if (event.type === "scroll") {
        const newY = window.scrollY;
        const diff = Math.abs(newY - this.lastScrollY);
        this.lastScrollY = newY;
        delta = diff > 0 ? Math.log2(1 + diff) : 0;
      } else if (event.type === "mousemove") {
        const x = Number(event.clientX) || 0;
        const y = Number(event.clientY) || 0;
        const dx = x - this.lastMouseMove.x;
        const dy = y - this.lastMouseMove.y;
        const distance = Math.sqrt(dx * dx + dy * dy);
        this.lastMouseMove = { x, y };
        delta = distance > 0 ? Math.log2(1 + distance) : 0;
      } else if (event.type === "keydown") {
        delta = 2;
      } else if (event.type === "touchstart") {
        delta = 3;
      }

      if (dt > 0 && dt < this.sampleLength * 2) {
        delta *= Utils.clamp(this.sampleLength / dt, 0.5, 2.0);
      }

      if (delta > 0) {
        this.hasInteracted = true;
        this._addSample(delta);
      }
    }

    _addSample(delta) {
      this.samples.push(delta);
      if (this.samples.length > this.maxSamples) this.samples.shift();
    }

    getScore() {
      return this.samples.length ? Math.round(this.samples.reduce((a, b) => a + b, 0)) : 0;
    }

    hasEnoughData() { return this.samples.length >= 5 && this.hasInteracted; }
    getDuration() { return Math.round((Date.now() - this.startTime) / 1000); }
  }

  // =======================
  // SQL Analytics Delivery
  // =======================
  async function sendAnalyticsEvent(eventPayload, options = {}) {
    const url = CONFIG.SQL_ENDPOINT;
    const payload = eventPayload && typeof eventPayload === "object" ? eventPayload : { reason: "UNKNOWN", message: String(eventPayload || "") };

    if (!url) throw new Error("sql_endpoint_missing");

    const body = JSON.stringify(payload);

    if ((!options.requireAck || options.unloading) && typeof navigator?.sendBeacon === "function" && typeof Blob !== "undefined") {
      try {
        const beaconOk = navigator.sendBeacon(url, new Blob([body], { type: "text/plain;charset=UTF-8" }));
        if (beaconOk) return { ok: true, transport: "beacon" };
      } catch {} // A rejected beacon must still reach the fetch fallback.
    }

    if (typeof fetch !== "function") throw new Error("fetch_unavailable");

    const controller = typeof AbortController === "function" ? new AbortController() : null;
    let timer;
    try {
      const timeoutPromise = new Promise((_, reject) => {
        timer = setTimeout(() => {
          if (controller) controller.abort();
          reject(new Error("timeout"));
        }, CONFIG.SQL.FETCH_TIMEOUT_MS);
      });

      const response = await Promise.race([
        fetch(url, {
          method: "POST",
          mode: options.requireAck ? "cors" : "no-cors",
          credentials: "omit",
          cache: "no-store",
          headers: { "Content-Type": "text/plain;charset=UTF-8" },
          body,
          keepalive: true,
          ...(controller ? { signal: controller.signal } : {})
        }),
        timeoutPromise
      ]);
      if (response.type !== "opaque" && response.ok === false) throw new Error("sql_http_" + response.status);
      return { ok: true, transport: options.requireAck ? "fetch-cors" : "fetch-no-cors" };
    } finally {
      clearTimeout(timer);
    }
  }

  class DeliveryQueue {
    constructor() {
      this.enabled = CONFIG.SQL.RETRY_QUEUE_ENABLED;
      // Each tab owns its snapshots; a different tab must never overwrite failed deliveries.
      this.store = this.enabled ? Utils.safeStorage("sessionStorage") : null;
      this.items = [];
      this.inFlight = new Set();
      this.limit = Math.min(200, Math.floor(CONFIG.SQL.RETRY_QUEUE_MAX_ITEMS));
      this.key = CONFIG.SQL.RETRY_QUEUE_KEY;
      if (this.enabled && this.store) {
        for (const key of Utils.uniqueStrings([this.key, ...CONFIG.SQL.RETRY_QUEUE_LEGACY_KEYS])) {
          try {
            const entries = JSON.parse(this.store.getItem(key) || "[]");
            if (Array.isArray(entries)) for (const entry of entries) {
              if (entry?.version !== 1 || entry?.endpoint !== CONFIG.SQL_ENDPOINT || entry?.payload?.schema !== "pixl-sql-v1"
                  || entry.payload.siteKey !== CONFIG.SQL_PUBLIC_KEY || !entry.payload.eventId
                  || !entry.payload.sentAt || !entry.payload.page) continue;
              entry.payload.page.url = pageUrl(entry.payload.page.url);
              entry.payload.page.referrer = entry.payload.page.referrer ? pageUrl(entry.payload.page.referrer) : "";
              this.items.push(entry);
            }
            if (key !== this.key) this.store.removeItem(key);
          } catch {}
        }
        this.save();
      }
    }
    id(item) { return [item.payload.eventId, item.payload.sentAt, item.payload.reason].join("|"); }
    save() {
      const seen = new Set();
      this.items = this.items.filter(item => {
        const age = Date.now() - Date.parse(item.payload.sentAt);
        const id = this.id(item);
        if (!Number.isFinite(age) || age < -60000 || age > 86400000 || seen.has(id)) return false;
        seen.add(id); return true;
      }).slice(-this.limit);
      try { this.store?.setItem(this.key, JSON.stringify(this.items)); } catch {}
    }
    async attempt(item, unloading = false) {
      const id = this.id(item);
      if (this.inFlight.has(id)) return;
      this.inFlight.add(id);
      try {
        const result = await sendAnalyticsEvent(item.payload, { requireAck: this.enabled, unloading });
        // A beacon only confirms browser admission. Keep it for a later acknowledged retry.
        if (this.enabled && result.transport !== "beacon") {
          this.items = this.items.filter(entry => this.id(entry) !== id);
          this.save();
        }
      } finally { this.inFlight.delete(id); }
    }
    send(payload, unloading = false) {
      const item = { version: 1, endpoint: CONFIG.SQL_ENDPOINT, payload };
      if (this.enabled) { this.items.push(item); this.save(); }
      return this.attempt(item, unloading);
    }
    retry() {
      if (!this.enabled) return;
      this.save();
      for (const item of this.items.slice()) this.attempt(item).catch(() => {});
    }
  }

  // =======================
  // Pixl Analytics Main
  // =======================
  class PixlAnalytics {
    constructor() {
      this.deviceDetector = new DeviceDetector();
      this.consoleSpy = new ConsoleSpy(CONFIG.CONSOLE_SPY.MAX_ENTRIES, CONFIG.CONSOLE_SPY.MAX_MESSAGE_LENGTH);
      this.readingTracker = new ReadingTracker(CONFIG.READING_TRACKER);
      this.userSession = Utils.getPageSession();
      // Innerhalb von X Minuten verwendet dieselbe Benutzer-Seite immer dieselbe DB-Zeile.
      this.pageEventId = this.userSession.eventId;
      this.notificationCount = 0;
      this.context = null;
      this.renderIssues = [];
      this.sessionStartedAt = Date.now();
      this.sessionEvents = { VISIT: false, READ: false, LEAVE: false };
      this.sessionEventTimes = { VISIT: null, READ: null, LEAVE: null };
      this.eventTrail = [];
      this.bestReadScore = 0;
      this.bestReadDuration = 0;
      this.leaveNotified = false;
      this.readNotificationSent = false;
      this.visitTimeout = null;
      this.readTimeout = null;
      this.readInterval = null;
      this.cachedAt = null;
      this.delivery = null;
      this.cooldownStorage = Utils.safeStorage("localStorage") || Utils.safeStorage("sessionStorage");
      this.lastSubmit = 0;
      this.lastSessionTouch = 0;
      this.renderTimer = null;
      this.failedRenderChecks = 0;
      this.retryTimer = null;
      this.finalSubmitted = false;
      this._boundVisibility = this._handleVisibility.bind(this);
      this._boundOnline = () => this.delivery?.retry();
      this._boundActivity = () => {
        if (document.hidden || this.leaveNotified || Date.now() - this.lastSessionTouch < 15000) return;
        this.lastSessionTouch = Date.now(); this.userSession.touch();
      };
      this._boundPageHide = this._handlePageHide.bind(this);
      this._boundPageShow = this._handlePageShow.bind(this);
    }

    _handlePageHide(event) {
      if (this.leaveNotified) return;
      this.leaveNotified = true;
      this.userSession.touch();
      this.cachedAt = event?.persisted ? Date.now() : null;
      clearTimeout(this.visitTimeout);
      this.visitTimeout = null;
      this._stopReadChecks();
      clearInterval(this.renderTimer);
      clearInterval(this.retryTimer);
      this._sendFinalSummary("LEAVE", true);
    }

    _handlePageShow(event) {
      if (!event.persisted || !this.context || this.cachedAt === null) return;
      const pausedMs = Math.max(0, Date.now() - this.cachedAt);
      this.sessionStartedAt += pausedMs;
      this.readingTracker.startTime += pausedMs;
      this.readingTracker.lastActivity = Utils.now();
      this.cachedAt = null;
      this.leaveNotified = false;
      // Resume the same page record, reserving its final-summary slot again.
      if (this.finalSubmitted && !CONFIG.SQL.ONE_AUTO_NOTIFICATION_ONLY) this.notificationCount = Math.max(0, this.notificationCount - 1);
      this.finalSubmitted = false;
      this.sessionEvents.LEAVE = false;
      this.sessionEventTimes.LEAVE = null;
      this.eventTrail = this.eventTrail.filter(reason => reason !== "LEAVE");
      this.userSession.touch();
      this._startReadChecks(CONFIG.SQL.READ_RECHECK_MS);
      this._startBackgroundChecks();
      this.delivery.retry();
    }

    _handleVisibility() {
      if (this.leaveNotified) return;
      if (document.hidden) {
        this.userSession.touch();
        if (CONFIG.SQL.NOTIFY_ON_HIDDEN) this._sendFinalSummary("HIDDEN", true);
      } else {
        this._boundActivity();
        this.delivery.retry();
      }
    }

    _startBackgroundChecks() {
      clearInterval(this.renderTimer); clearInterval(this.retryTimer);
      if (CONFIG.RENDER_HEALTH.ENABLED && this.failedRenderChecks < CONFIG.RENDER_HEALTH.MAX_FAILED_CHECKS) {
        this._checkRender();
        if (this.failedRenderChecks < CONFIG.RENDER_HEALTH.MAX_FAILED_CHECKS) {
          this.renderTimer = setInterval(() => { if (!document.hidden) this._checkRender(); }, CONFIG.RENDER_HEALTH.INTERVAL_MS);
        }
      }
      if (CONFIG.SQL.RETRY_QUEUE_ENABLED) this.retryTimer = setInterval(() => {
        if (!document.hidden && !this.leaveNotified) this.delivery.retry();
      }, 15000);
    }

    _checkRender() {
      if (document.readyState !== "complete" || this.failedRenderChecks >= CONFIG.RENDER_HEALTH.MAX_FAILED_CHECKS) return;
      const issues = [];
      if (document.readyState !== "loading" && !document.body) issues.push("missing-body");
      for (const img of Array.from(document.images || [])) {
        if (img.complete && (img.currentSrc || img.src) && img.naturalWidth === 0) issues.push("failed-image");
      }
      if (this.consoleSpy.getErrorCount() > CONFIG.RENDER_HEALTH.MAX_CONSOLE_ERRORS) issues.push("console-error-limit");
      this.renderIssues = Utils.uniqueStrings([...this.renderIssues, ...issues]);
      if (issues.length) this.failedRenderChecks += 1;
      if (this.failedRenderChecks >= CONFIG.RENDER_HEALTH.MAX_FAILED_CHECKS) clearInterval(this.renderTimer);
    }

    _canSend(final = false) {
      if (CONFIG.SQL.FINAL_SUMMARY_ONLY && !final) return false;
      if (CONFIG.DEBUG.ENABLED && CONFIG.DEBUG.FORCE_NOTIFY) return true;
      const limit = CONFIG.SQL.ONE_AUTO_NOTIFICATION_ONLY ? Math.min(1, CONFIG.SQL.MAX_NOTIFICATIONS_PER_PAGE)
        : CONFIG.SQL.MAX_NOTIFICATIONS_PER_PAGE;
      if (this.notificationCount >= limit) return false;
      let last = this.lastSubmit;
      try { last = Math.max(last, Number(this.cooldownStorage?.getItem(CONFIG.SQL.GLOBAL_COOLDOWN_KEY)) || 0); } catch {}
      return Date.now() - last >= CONFIG.SQL.SESSION_COOLDOWN_MS;
    }

    _submit(reason, final = false, unloading = false) {
      if (!this._canSend(final)) return false;
      this.notificationCount += 1;
      this.lastSubmit = Date.now();
      try { this.cooldownStorage?.setItem(CONFIG.SQL.GLOBAL_COOLDOWN_KEY, String(this.lastSubmit)); } catch {}
      const payload = this._buildEventPayload(reason);
      if (final) this.finalSubmitted = true;
      if (CONFIG.DEBUG.ENABLED) {
        try { console.info?.("Stats3 event: " + reason); } catch {}
      }
      this.delivery.send(payload, unloading).catch(err => {
        try { console.error?.("Pixl SQL delivery failed:", err); } catch {}
      });
      return true;
    }

    _startReadChecks(delayMs) {
      this._stopReadChecks();
      if (!CONFIG.SQL.NOTIFY_ON_READ || this.readNotificationSent || this.leaveNotified) return;
      this.readTimeout = setTimeout(() => {
        this.readTimeout = null;
        this._handleReadCheckpoint();
        if (!this.readNotificationSent && !this.leaveNotified) {
          this.readInterval = setInterval(
            () => this._handleReadCheckpoint(),
            CONFIG.SQL.READ_RECHECK_MS
          );
        }
      }, delayMs);
    }

    async init() {
      const hostname = location.hostname;
      const allowed = Utils.isAllowedHostname(hostname);
      if (!allowed) return;

      const ua = navigator.userAgent || "";
      const botAnalysis = Utils.analyzeBot(ua);
      const os = this.deviceDetector.getOS();

      if (
        !(CONFIG.DEBUG.ENABLED && CONFIG.DEBUG.BYPASS_FILTERS) && CONFIG.ACCEPTED_OS instanceof Set &&
        CONFIG.ACCEPTED_OS.size > 0 &&
        !CONFIG.ACCEPTED_OS.has(String(os).toLowerCase()) &&
        !botAnalysis.isBot
      ) return;

      const browser = this.deviceDetector.getBrowser();
      if (!(CONFIG.DEBUG.ENABLED && CONFIG.DEBUG.BYPASS_FILTERS) && CONFIG.EXCLUDE_CHROME && browser === "chrome") return;

      const { value: screenRes, known: knownResolution } = this.deviceDetector.getScreenResolution();
      const lang = this.deviceDetector.getLanguage();
      const path = this.deviceDetector.getPath();
      const device = this.deviceDetector.getDeviceType();
      const screenCategory = this.deviceDetector.getScreenCategory();
      const country = Utils.getCountryCode() || this.deviceDetector.getCountryGuess();

      const exclude = CONFIG.FINGERPRINT_EXCLUDE;
      const excluded = exclude.ENABLED && [exclude, ...(exclude.RULES || [])].some(rule => {
        const matchingFields = [[rule.BROWSER_IS || "", browser], [rule.OS_IS || "", os],
          [rule.DEVICE_IS || "", device], [rule.COUNTRY_IS || "", country]]
          .filter(([expected]) => expected.trim() !== "");
        const screens = rule.SCREEN_IS || [];
        const userAgentPart = (rule.USER_AGENT_CONTAINS || "").trim().toLowerCase();
        const hasPixelRatio = rule.PIXEL_RATIO_IS !== undefined;
        // Empty rules must never exclude every visitor.
        return (matchingFields.length > 0 || screens.length > 0 || userAgentPart !== "" || hasPixelRatio)
          && matchingFields.every(([expected, actual]) => expected.trim().toLowerCase() === String(actual).toLowerCase())
          && (screens.length === 0 || screens.includes(screenRes))
          && (userAgentPart === "" || ua.toLowerCase().includes(userAgentPart))
          && (!hasPixelRatio || window.devicePixelRatio === rule.PIXEL_RATIO_IS);
      });
      if (!(CONFIG.DEBUG.ENABLED && CONFIG.DEBUG.BYPASS_FILTERS) && excluded) return;

      this.context = {
        hostname,
        origin: location.origin || "",
        url: pageUrl(location.href),
        referrer: document.referrer ? pageUrl(document.referrer) : "",
        ua,
        browser,
        os,
        device,
        screen: screenRes,
        knownResolution,
        viewport: Utils.formatScreen(window.innerWidth || 0, window.innerHeight || 0),
        lang,
        path,
        screenCategory,
        country,
        bot: botAnalysis,
        timezone: (typeof Intl !== "undefined" && Intl.DateTimeFormat?.().resolvedOptions)
          ? Intl.DateTimeFormat().resolvedOptions().timeZone || ""
          : ""
      };

      this.consoleSpy.install();
      this.readingTracker.install();
      this.delivery = new DeliveryQueue();
      this.delivery.retry();
      this._startBackgroundChecks();
      document.addEventListener("visibilitychange", this._boundVisibility, { passive: true });
      window.addEventListener("online", this._boundOnline);
      for (const type of ["scroll", "mousemove", "keydown", "touchstart", "mousedown"]) {
        window.addEventListener(type, this._boundActivity, { passive: true });
      }

      {
        window.addEventListener("pagehide", this._boundPageHide, { passive: true });
        window.addEventListener("pageshow", this._boundPageShow, { passive: true });
      }

      if (CONFIG.SQL.NOTIFY_ON_VISIT) {
        this.visitTimeout = setTimeout(() => {
          this.visitTimeout = null;
          this._handleVisitCheckpoint();
        }, botAnalysis.isBot && CONFIG.SQL.TRACK_BOTS_IMMEDIATELY ? 0 : CONFIG.SQL.VISIT_DELAY_MS);
      }

      this._startReadChecks(CONFIG.SQL.READ_DELAY_MS);
    }

    _buildEventPayload(reason) {
      const ctx = this.context;
      const reading = this.readingTracker;
      const sessionDuration = Math.max(0, Math.round((Date.now() - this.sessionStartedAt) / 1000));
      const consoleErrors = CONFIG.SQL.INCLUDE_CONSOLE_LOGS
        ? this.consoleSpy.getEntries().filter(entry => entry.level === "error").slice(-3)
        : [];
      const v3Score = readV3UserScore();
      let inFrame = true;
      try { inFrame = window.self !== window.top; } catch {}

      return {
        schema: "pixl-sql-v1",
        siteId: CONFIG.SQL_SITE_ID || ctx.hostname || "default",
        siteKey: CONFIG.SQL_PUBLIC_KEY || "",
        eventId: this.pageEventId,
        session: {
          id: this.userSession.id,
          pageKey: this.userSession.pageKey,
          pageRecountMinutes: this.userSession.pageRecountMinutes,
          reused: this.userSession.reused,
          reload: this.userSession.reload
        },
        sentAt: new Date().toISOString(),
        reason: String(reason || "UNKNOWN").toUpperCase(),
        script: { name: SCRIPT_NAME, version: CONFIG.VERSION },
        page: {
          hostname: ctx.hostname,
          origin: ctx.origin,
          url: ctx.url,
          path: `/${ctx.path || ""}`.replace(/\/{2,}/g, "/"),
          referrer: ctx.referrer
        },
        context: {
          userAgent: ctx.ua,
          browser: ctx.browser,
          os: ctx.os,
          device: ctx.device,
          screen: ctx.screen,
          knownResolution: ctx.knownResolution,
          viewport: ctx.viewport,
          screenCategory: ctx.screenCategory,
          language: ctx.lang,
          country: ctx.country || "Unknown",
          timezone: ctx.timezone || ""
        },
        engagement: {
          sessionDuration,
          readingSamples: reading.samples.slice(),
          bestReadScore: this.bestReadScore,
          bestReadDuration: this.bestReadDuration,
          readingLevel: this.readingTracker.getScore() >= CONFIG.READING.THRESHOLDS.EXCELLENT ? "EXCELLENT"
            : this.readingTracker.getScore() >= CONFIG.READING.THRESHOLDS.GOOD ? "GOOD"
            : this.readingTracker.getScore() >= CONFIG.READING.THRESHOLDS.READ ? "READ" : "LOW",
          v3UserScore: typeof v3Score === "number" && !Number.isNaN(v3Score) ? v3Score : null
        },
        health: {
          renderIssues: CONFIG.SQL.INCLUDE_RENDER_ISSUES ? this.renderIssues.slice() : [],
          consoleErrorCount: this.consoleSpy.getErrorCount(),
          consoleErrors
        },
        flags: {
          inFrame,
          webdriver: Utils.isAutomationBrowser()
        },
        events: {
          reached: { ...this.sessionEvents },
          seconds: { ...this.sessionEventTimes },
          trail: this.eventTrail.slice()
        }
      };
    }

    _handleVisitCheckpoint() {
      if (!CONFIG.SQL.NOTIFY_ON_VISIT || !this.context || this.leaveNotified) return;
      if (!(CONFIG.DEBUG.ENABLED && CONFIG.DEBUG.FORCE_NOTIFY) && this.notificationCount >= CONFIG.SQL.MAX_NOTIFICATIONS_PER_PAGE) return;

      this.sessionEvents.VISIT = true;
      this.sessionEventTimes.VISIT = 0;
      if (!this.eventTrail.includes("VISIT")) this.eventTrail.push("VISIT");
      this._submit("VISIT");
    }

    _handleReadCheckpoint() {
      if (
        !CONFIG.SQL.NOTIFY_ON_READ ||
        !this.context ||
        this.leaveNotified ||
        this.readNotificationSent ||
        (!(CONFIG.DEBUG.ENABLED && CONFIG.DEBUG.FORCE_NOTIFY) && this.notificationCount >= CONFIG.SQL.MAX_NOTIFICATIONS_PER_PAGE)
      ) return;

      const score = this.readingTracker.getScore();
      if (!(CONFIG.DEBUG.ENABLED && CONFIG.DEBUG.FORCE_NOTIFY) && (!this.readingTracker.hasEnoughData()
          || score < Math.max(CONFIG.SQL.MIN_SCORE_TO_NOTIFY, CONFIG.READING.MIN_SCORE, CONFIG.READING.THRESHOLDS.READ))) {
        return;
      }

      const duration = this.readingTracker.getDuration();
      this.sessionEvents.READ = true;
      this.sessionEventTimes.READ = duration;
      this.bestReadScore = Math.max(this.bestReadScore, score);
      this.bestReadDuration = Math.max(this.bestReadDuration, duration);
      if (!this.eventTrail.includes("READ")) this.eventTrail.push("READ");
      if (this._submit("READ") || CONFIG.SQL.FINAL_SUMMARY_ONLY || CONFIG.SQL.ONE_AUTO_NOTIFICATION_ONLY) {
        this.readNotificationSent = true;
        this._stopReadChecks();
      }
    }

    _stopReadChecks() {
      if (this.readTimeout) {
        clearTimeout(this.readTimeout);
        this.readTimeout = null;
      }
      if (this.readInterval) {
        clearInterval(this.readInterval);
        this.readInterval = null;
      }
    }

    _sendFinalSummary(triggerReason = "LEAVE", unloading = false) {
      if (!this.context || (triggerReason === "LEAVE" && !CONFIG.SQL.NOTIFY_ON_LEAVE)) return;
      if (!(CONFIG.DEBUG.ENABLED && CONFIG.DEBUG.FORCE_NOTIFY) && this.notificationCount >= CONFIG.SQL.MAX_NOTIFICATIONS_PER_PAGE) return;

      const terminal = triggerReason === "LEAVE";
      if (terminal) {
        this.sessionEvents.LEAVE = true;
        this.sessionEventTimes.LEAVE = Math.round((Date.now() - this.sessionStartedAt) / 1000);
        if (!this.eventTrail.includes("LEAVE")) this.eventTrail.push("LEAVE");
      }
      this._submit(terminal && CONFIG.SQL.FINAL_SUMMARY_ONLY ? CONFIG.SQL.FINAL_SUMMARY_REASON : triggerReason, terminal, unloading);
    }

    destroy() {
      clearTimeout(this.visitTimeout);
      this.visitTimeout = null;
      this._stopReadChecks();
      clearInterval(this.renderTimer); clearInterval(this.retryTimer);
      document.removeEventListener("visibilitychange", this._boundVisibility);
      window.removeEventListener("online", this._boundOnline);
      for (const type of ["scroll", "mousemove", "keydown", "touchstart", "mousedown"]) window.removeEventListener(type, this._boundActivity);
      window.removeEventListener("pagehide", this._boundPageHide);
      window.removeEventListener("pageshow", this._boundPageShow);
      this.readingTracker.uninstall();
      this.consoleSpy.uninstall();
    }
  }

  // =======================
  // Public API
  // =======================
  function getFingerprint() {
    const detector = new DeviceDetector();
    const { value: screenRes, known: knownResolution } = detector.getScreenResolution();
    return {
      browser: detector.getBrowser(),
      os: detector.getOS(),
      device: detector.getDeviceType(),
      screen: screenRes,
      knownResolution,
      lang: detector.getLanguage(),
      country: Utils.getCountryCode() || detector.getCountryGuess(),
      ua: detector.ua || "Unknown"
    };
  }

  try {
    const publicApi = Object.freeze({
      version: CONFIG.VERSION,
      endpoint: CONFIG.SQL_ENDPOINT,
      fingerprint: getFingerprint
    });

    window.PIXL77 = publicApi;
    window.PIXL6 = publicApi;
  } catch {
    // ignore
  }

  // =======================
  // Auto-Init
  // =======================
  function startCaptcha() {
    if (!Utils.isAllowedHostname(location.hostname) || window.__pixlCaptchaInitialized
        || window.SwipeGate || document.getElementById("swipe-gate")) return;
    window.__pixlCaptchaInitialized = true;
    const endpoint = new URL("captcha.php", CONFIG.SQL_ENDPOINT).href;
    let poll = null;
    let finished = false;
    let confirming = false;
    let failedAttempts = 0;
    let suspended = false;
    let ticket = "";
    let pendingTicket = null;
    let lastStatusMessage = "";
    const attemptSource = Utils.makeEventId();

    const logCaptcha = message => {
      if (message === lastStatusMessage) return;
      lastStatusMessage = message;
      try {
        // Info keeps diagnostics visible without adding them to tracked console errors.
        if (window.console && typeof window.console.info === "function") window.console.info(message);
      } catch {}
    };
    const logStatus = data => {
      const required = data.required === true;
      const status = typeof data.active === "boolean" ? (data.active ? "active" : "inactive")
        : (required ? "active" : "status unknown");
      logCaptcha(`Captcha ${status} — Prüfung für diesen Besucher erforderlich: ${required ? "ja" : "nein"}.`
        + (status === "status unknown" ? " Der Server liefert noch keinen globalen Status." : ""));
    };

    const stop = () => {
      finished = true;
      clearInterval(poll);
    };
    const dismiss = () => {
      stop();
      if (window.SwipeGate) window.SwipeGate.dismiss();
    };
    const request = async (action, token = "") => {
      const controller = typeof AbortController === "function" ? new AbortController() : null;
      let timer;
      try {
        return await Promise.race([
          fetch(endpoint, {
            method: "POST", mode: "cors", credentials: "omit", cache: "no-store",
            headers: { "Content-Type": "text/plain;charset=UTF-8" },
            body: JSON.stringify({ action, token, failedAttempts, attemptSource, url: pageUrl(location.href), siteKey: CONFIG.SQL_PUBLIC_KEY }),
            keepalive: action === "fail",
            ...(controller ? { signal: controller.signal } : {})
          }).then(async response => {
            if (!response.ok) throw new Error("captcha_unavailable");
            const data = await response.json();
            if (!data || data.ok !== true) throw new Error("captcha_unavailable");
            if (action !== "fail") logStatus(data);
            return data;
          }),
          new Promise((_, reject) => {
            timer = setTimeout(() => {
              if (controller) controller.abort();
              reject(new Error("captcha_timeout"));
            }, 4500);
          })
        ]);
      } finally {
        clearTimeout(timer);
      }
    };

    const refreshStatus = () => {
      if (finished || suspended || confirming || !ticket) return;
      request("status", ticket).then(state => {
        if (!finished && !suspended && !confirming && state.required !== true) dismiss();
      }).catch(() => {
        logCaptcha("Captcha status unavailable — Serveranfrage fehlgeschlagen.");
        if (!suspended && !confirming) dismiss();
      });
    };
    const startPolling = () => {
      clearInterval(poll);
      if (!finished && !suspended) poll = setInterval(refreshStatus, 15000);
    };

    const showChallenge = data => {
      if (finished) return;
      if (data.required !== true || !/^[a-f0-9]{64}$/.test(data.token || "")) { stop(); return; }
      if (suspended) { pendingTicket = data; return; }
      pendingTicket = null;
      ticket = data.token;
      const previousOptions = window.SwipeGateOptions || {};
      window.SwipeGateOptions = Object.assign({}, previousOptions, {
        // The central one-use reservation decides; no local pass may skip it.
        rememberForMinutes: 0,
        shouldStart: () => !finished && !suspended,
        onFailure: () => {
          if (finished || suspended) return;
          failedAttempts = Math.min(10000, failedAttempts + 1);
          request("fail", data.token).catch(() => {});
          if (typeof previousOptions.onFailure === "function") previousOptions.onFailure();
        },
        onConfirm: async () => {
          confirming = true;
          try {
            const result = await request("verify", data.token);
            return result.verified === true;
          } finally {
            confirming = false;
          }
        },
        onVerified: () => {
          stop();
          if (typeof previousOptions.onVerified === "function") previousOptions.onVerified();
        },
        onDismiss: stop
      });
      const script = document.createElement("script");
      script.src = new URL("captcha.js?v=1.3.0", endpoint).href;
      if (countScript?.nonce) script.nonce = countScript.nonce;
      script.async = true;
      script.onerror = () => {
        logCaptcha("Captcha active — CAPTCHA-Datei konnte nicht geladen werden.");
        dismiss();
      };
      script.onload = () => {
        if (finished) { dismiss(); return; }
        if (suspended) return;
        window.SwipeGate?.init?.();
        startPolling();
      };
      document.head.appendChild(script);
    };
    request("check").then(showChallenge).catch(() => {
      logCaptcha("Captcha status unavailable — Serveranfrage fehlgeschlagen.");
      stop();
    }); // A disabled/unavailable CAPTCHA must not stop analytics or the page.
    window.addEventListener("pagehide", event => {
      suspended = true;
      clearInterval(poll);
      if (!event.persisted) stop();
    }, { passive: true });
    window.addEventListener("pageshow", event => {
      if (!event.persisted) return;
      suspended = false;
      if (pendingTicket) showChallenge(pendingTicket);
      if (!finished && window.SwipeGate) {
        window.SwipeGate.init?.();
        refreshStatus();
        startPolling();
      }
    }, { passive: true });
  }

  (function autoInit() {
    if (CONFIG.SQL.BLOCK_IFRAMES) {
      try {
        if (window.self !== window.top) return;
      } catch {
        return;
      }
    }

    if (window.__pixl77Initialized) return;
    window.__pixl77Initialized = true;

    let initStarted = false;
    const resumeStart = event => { if (event.persisted) start(); };
    const start = () => {
      if (initStarted || pageHidden || document.readyState === "loading") return;
      initStarted = true;
      window.removeEventListener("pageshow", resumeStart);
      startCaptcha();
      const instance = new PixlAnalytics();
      instance.init().catch(err => {
        console.error && console.error("Pixl analytics init failed:", err);
      });
    };
    window.addEventListener("pageshow", resumeStart, { passive: true });

    if (document.readyState === "complete" || document.readyState === "interactive") {
      setTimeout(start, 0);
    } else {
      window.addEventListener("DOMContentLoaded", start, { once: true });
    }
  })();
})();

// ============================================================
// STAT4 tracker
// Included here so one /stats3/count.js script feeds both Stats3 and STAT4.
// ============================================================
(function () {
  'use strict';

  if (window.__stat4TrackerInitialized) return;
  window.__stat4TrackerInitialized = true;

  var script = countScript;
  var defaultEndpoint = CONFIG.STAT4.ENDPOINT;
  var endpoint = defaultEndpoint;
  try {
    var requestedEndpoint = script && script.dataset.stat4Endpoint
      ? new URL(script.dataset.stat4Endpoint, script.src || location.href)
      : new URL(defaultEndpoint);
    if (/^https?:$/.test(requestedEndpoint.protocol)) endpoint = requestedEndpoint.href;
  } catch (error) {}

  var VISITOR_TTL = CONFIG.STAT4.VISITOR_TTL;
  var HEARTBEAT_INTERVAL = CONFIG.STAT4.HEARTBEAT_INTERVAL;
  var TICK_INTERVAL = CONFIG.STAT4.TICK_INTERVAL;
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

  }
})();
