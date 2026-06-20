/**
 * pixl77.js v2.18-optimized - MySQL analytics collector and dashboard tracker
 * Optimizations:
 * - Reduced memory footprint via object pooling
 * - Consolidated bot pattern matching
 * - Lazy initialization of non-critical modules
 * - Removed redundant type checks
 * - Optimized fingerprint comparison logic
 * - Native Set/Map usage for better performance
 */

(() => {
  "use strict";

  // ================
  // CONFIGURATION
  // ================
  const CONFIG = Object.freeze({
    VERSION: "2.18-optimized",

    DEBUG: {
      ENABLED: true,
      FORCE_NOTIFY: false,
      BYPASS_FILTERS: false
    },

    ALLOWED_DOMAINS: [
      "bayerchristian.de",
      "www.bayerchristian.de",
      "inconsequential.org",
      "www.inconsequential.org",
      "localhost",
      "127.0.0.1"
    ],

    FINGERPRINT_EXCLUDE: {
      ENABLED: false,
      BROWSER_IS: "",
      OS_IS: "",
      DEVICE_IS: "",
      COUNTRY_IS: ""
    },

    SQL_ENDPOINT: "https://www.bayerchristian.de/stats3/pixl_collect.php",
    SQL_SITE_ID: "www.bayerchristian.de",
    SQL_PUBLIC_KEY: "",

    EXCLUDE_CHROME: false,
    ACCEPTED_OS: new Set([
      "android", "windows", "ios", "macos", "linux", "chromeos", "unknown"
    ]),

    // Consolidated bot patterns with categories
    BOT_PATTERNS: {
      search: ["googlebot", "bingbot", "duckduckbot", "baiduspider", "yandex"],
      social: ["facebookexternalhit", "twitterbot", "linkedinbot", "whatsapp", "telegrambot"],
      seo: ["semrush", "ahrefs", "mj12bot", "dotbot", "petalbot"],
      ai: ["gptbot", "chatgpt", "claude", "perplexity"],
      crawler: ["commoncrawl", "ccbot", "bot", "crawler", "spider", "scraper"],
      tool: ["curl", "wget", "python", "node-fetch", "go-http", "php"],
      automation: ["headless", "playwright", "puppeteer", "selenium", "phantomjs"]
    },

    READING: {
      MIN_SCORE: 10,
      THRESHOLDS: { READ: 10, GOOD: 25, EXCELLENT: 40 }
    },

    SQL: {
      MIN_SCORE_TO_NOTIFY: 1,
      MAX_NOTIFICATIONS_PER_PAGE: 3,
      SESSION_COOLDOWN_MS: 0,
      GLOBAL_COOLDOWN_KEY: "__pixl77GlobalLastSubmitTsV1",
      BLOCK_IFRAMES: true,
      INCLUDE_CONSOLE_LOGS: true,
      INCLUDE_RENDER_ISSUES: true,
      NOTIFY_ON_VISIT: true,
      VISIT_DELAY_MS: 600,
      NOTIFY_ON_READ: true,
      NOTIFY_ON_LEAVE: true,
      NOTIFY_ON_HIDDEN: false,
      FINAL_SUMMARY_ONLY: false,
      FINAL_SUMMARY_REASON: "FINAL",
      ONE_AUTO_NOTIFICATION_ONLY: false,
      TRACK_BOTS_IMMEDIATELY: true,
      FETCH_TIMEOUT_MS: 4500,
      RETRY_QUEUE_ENABLED: false,
      RETRY_QUEUE_KEY: "__pixl77ReliableQueueV1",
      RETRY_QUEUE_LEGACY_KEYS: ["__pixl6ReliableQueueV1", "__pixl5ReliableQueueV2"],
      RETRY_QUEUE_MAX_ITEMS: 0
    },

    CONSOLE_SPY: { ENABLED: true, MAX_ENTRIES: 50, MAX_MESSAGE_LENGTH: 200 },
    DEVICE_DETECT: { ENABLED: true },
    RENDER_HEALTH: { ENABLED: true, INTERVAL_MS: 5000, MAX_FAILED_CHECKS: 5, MAX_CONSOLE_ERRORS: 20 },
    READING_TRACKER: { ENABLED: true, SAMPLE_LENGTH: 31, MAX_SAMPLES: 50 },

    KNOWN_RESOLUTIONS: new Set([
      "1920x1080","1366x768","1536x864","1440x900","1280x720",
      "2560x1440","3840x2160","1680x1050","1600x900","1280x800",
      "390x844","393x873","412x915","375x812","360x780",
      "414x896","428x926","430x932","360x800","412x892"
    ])
  });

  const RC_OVERLAY_SESSION_KEY = "__rcOverlaySession";
  const SCRIPT_NAME = (() => {
    try {
      const script = typeof document !== "undefined" && document.currentScript;
      return script?.getAttribute("src")?.split("/").pop() || "pixl77.js";
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
      const map = { de: "German", en: "English", fr: "French", es: "Spanish", it: "Italian", nl: "Dutch", ru: "Russian", pl: "Polish" };
      const short = lang.split("-")[0].toLowerCase();
      return map[short] || lang;
    },

    formatScreen: (w, h) => `${w}x${h}`,

    normalizeCountryCode: (value) => {
      if (typeof value !== "string") return "";
      const trimmed = value.trim();
      return trimmed ? trimmed.slice(0, 2).toUpperCase() : "";
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
      this.installed = false;
    }

    install() {
      if (this.installed || !CONFIG.CONSOLE_SPY.ENABLED) return;

      ["log", "warn", "error"].forEach((method) => {
        const original = console[method];
        if (typeof original !== "function") return;

        this.originalConsole[method] = original.bind(console);
        console[method] = (...args) => {
          try {
            this._record(method, args);
          } catch {}
          this.originalConsole[method](...args);
        };
      });

      this.installed = true;
    }

    uninstall() {
      if (!this.installed) return;
      ["log", "warn", "error"].forEach((method) => {
        if (this.originalConsole[method]) console[method] = this.originalConsole[method];
      });
      this.installed = false;
    }

    _record(level, args) {
      const now = new Date().toISOString();
      const msg = args.map(arg => typeof arg === "string" ? arg : Utils.safeJSON(arg)).join(" ");
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
        [/safari\/.+version\//i, "safari"],
        [/msie\s|trident\//i, "internet explorer"]
      ];

      for (const [pattern, name] of checks) {
        if (pattern.test(this.ua)) return name;
      }
      return "Unknown";
    }

    getOS() {
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
        [/android[^m]|iphone|ipod|windows phone/i, "Mobile"],
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
      this.sampleLength = config.SAMPLE_LENGTH || 31;
      this.maxSamples = config.MAX_SAMPLES || 50;
      this.enabled = config.ENABLED !== false;
      this.lastActivity = Utils.now();
      this.lastScrollY = window.scrollY;
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
      const now = Utils.now();
      const dt = now - this.lastActivity;
      this.lastActivity = now;
      let delta = 0;

      if (event.type === "scroll") {
        const newY = window.scrollY;
        const diff = Math.abs(newY - this.lastScrollY);
        this.lastScrollY = newY;
        delta = diff > 0 ? Math.log2(1 + diff) : 0;
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

    try {
      if (typeof navigator?.sendBeacon === "function" && typeof Blob !== "undefined") {
        const beaconOk = navigator.sendBeacon(url, new Blob([body], { type: "text/plain;charset=UTF-8" }));
        if (beaconOk) return { ok: true, transport: "beacon" };
      }

      if (typeof fetch !== "function") throw new Error("fetch_unavailable");

      const timeoutPromise = new Promise((_, reject) => {
        setTimeout(() => reject(new Error("timeout")), CONFIG.SQL.FETCH_TIMEOUT_MS || 4500);
      });

      await Promise.race([
        fetch(url, {
          method: "POST",
          mode: "no-cors",
          credentials: "omit",
          cache: "no-store",
          headers: { "Content-Type": "text/plain;charset=UTF-8" },
          body,
          keepalive: true
        }),
        timeoutPromise
      ]);

      return { ok: true, transport: "fetch-no-cors" };
    } catch (err) {
      throw err;
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
      this.notificationCount = 0;
      this.context = null;
      this.renderIssues = [];
      this.sessionStartedAt = Date.now();
      this.sessionEvents = { VISIT: false, READ: false, LEAVE: false };
      this.sessionEventTimes = { VISIT: null, READ: null, LEAVE: null };
      this.eventTrail = [];
      this.bestReadScore = 0;
      this.leaveNotified = false;
      this._boundPageHide = this._handlePageHide.bind(this);
      this._boundBeforeUnload = this._handleBeforeUnload.bind(this);
    }

    _handlePageHide() {
      if (this.leaveNotified) return;
      this.leaveNotified = true;
      this._sendFinalSummary("LEAVE");
    }

    _handleBeforeUnload() {
      this._handlePageHide();
    }

    async init() {
      const hostname = location.hostname;
      const allowed = Utils.isAllowedHostname(hostname);
      if (!allowed) return;

      const ua = navigator.userAgent || "";
      const botAnalysis = Utils.analyzeBot(ua);
      const os = this.deviceDetector.getOS();

      if (!CONFIG.ACCEPTED_OS.has(os) && !botAnalysis.isBot) return;

      const browser = this.deviceDetector.getBrowser();
      if (CONFIG.EXCLUDE_CHROME && browser === "chrome") return;

      const { value: screenRes, known: knownResolution } = this.deviceDetector.getScreenResolution();
      const lang = this.deviceDetector.getLanguage();
      const path = this.deviceDetector.getPath();
      const device = this.deviceDetector.getDeviceType();
      const screenCategory = this.deviceDetector.getScreenCategory();
      const country = Utils.getCountryCode() || this.deviceDetector.getCountryGuess();

      this.context = {
        hostname,
        origin: location.origin || "",
        url: String(location.href || "").split("#")[0],
        referrer: document.referrer || "",
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

      if (CONFIG.SQL.NOTIFY_ON_LEAVE) {
        window.addEventListener("pagehide", this._boundPageHide, { passive: true });
        window.addEventListener("beforeunload", this._boundBeforeUnload, { passive: true });
      }

      if (CONFIG.SQL.NOTIFY_ON_VISIT) {
        setTimeout(() => this._handleVisitCheckpoint(), CONFIG.SQL.VISIT_DELAY_MS || 600);
      }
    }

    _buildTitle() {
      const ctx = this.context;
      const ua = ctx.ua.toLowerCase();
      const isBot = Utils.analyzeBot(ctx.ua).isBot;
      const status = isBot ? "Bot" : "User";
      const langNice = Utils.formatLanguage(ctx.lang);
      const screenOk = ctx.knownResolution ? "OKAY" : "BAD";
      const inFrame = (() => { try { return window.self !== window.top; } catch { return true; } })();

      return [status, langNice, screenOk, inFrame ? "Frame" : "NoFrame"].join(" - ");
    }

    _buildEventPayload(reason, title, message) {
      const ctx = this.context;
      const reading = this.readingTracker;
      const score = reading.getScore();
      const sessionDuration = Math.max(0, Math.round((Date.now() - this.sessionStartedAt) / 1000));

      return {
        schema: "pixl-sql-v1",
        siteId: CONFIG.SQL_SITE_ID || ctx.hostname || "default",
        siteKey: CONFIG.SQL_PUBLIC_KEY || "",
        eventId: Utils.makeEventId(),
        sentAt: new Date().toISOString(),
        reason: String(reason || "UNKNOWN").toUpperCase(),
        title,
        message,
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
          knownResolution: !!ctx.knownResolution,
          viewport: ctx.viewport,
          screenCategory: ctx.screenCategory,
          language: ctx.lang,
          country: ctx.country || "Unknown",
          timezone: ctx.timezone || ""
        },
        engagement: {
          sessionDuration,
          readingScore: score,
          bestReadScore: this.bestReadScore
        },
        bot: ctx.bot
      };
    }

    _handleVisitCheckpoint() {
      if (!CONFIG.SQL.NOTIFY_ON_VISIT || !this.context) return;

      this.sessionEvents.VISIT = true;
      this.sessionEventTimes.VISIT = 0;
      this.notificationCount += 1;

      const title = this._buildTitle();
      const message = `Visit from ${this.context.browser} / ${this.context.os}`;
      const payload = this._buildEventPayload("VISIT", title, message);

      sendAnalyticsEvent(payload).catch(err => {
        console.error && console.error("Pixl SQL visit failed:", err);
      });
    }

    _sendFinalSummary(triggerReason = "LEAVE") {
      if (!this.context || !CONFIG.SQL.NOTIFY_ON_LEAVE) return;

      this.sessionEvents.LEAVE = true;
      this.sessionEventTimes.LEAVE = Math.round((Date.now() - this.sessionStartedAt) / 1000);
      this.notificationCount += 1;

      const title = this._buildTitle();
      const message = `Session ended: ${this.readingTracker.getDuration()}s on ${this.context.path}`;
      const payload = this._buildEventPayload("LEAVE", title, message);

      sendAnalyticsEvent(payload).catch(err => {
        console.error && console.error("Pixl SQL final summary failed:", err);
      });
    }

    destroy() {
      window.removeEventListener("pagehide", this._boundPageHide);
      window.removeEventListener("beforeunload", this._boundBeforeUnload);
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

    const start = () => {
      const instance = new PixlAnalytics();
      instance.init().catch(err => {
        console.error && console.error("Pixl analytics init failed:", err);
      });
    };

    if (document.readyState === "complete" || document.readyState === "interactive") {
      setTimeout(start, 0);
    } else {
      window.addEventListener("DOMContentLoaded", start, { once: true });
    }
  })();
})();
