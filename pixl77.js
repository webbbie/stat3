/**
 * pixl77.js v2.16 - MySQL analytics collector and dashboard tracker
 * - Fingerprint-Schranke (Browser/OS/Country) statt IP-Exclude
 * - Alle externen Abfrage-Services (z.B. ipify) entfernt
 * - Sendet strukturierte Statistikdaten an den eigenen PHP/MySQL-Collector
 */

(() => {
  "use strict";

  // ================
  // CONFIGURATION
  // ================
  const CONFIG = Object.freeze({
    VERSION: "2.16-pixl77-mysql",

    // Debug-Modus
    DEBUG: {
      ENABLED: true,
      FORCE_NOTIFY: false,
      BYPASS_FILTERS: false
    },

    // Domain- & Fingerprint-Filtering
    ALLOWED_DOMAINS: [
      "bayerchristian.de",
      "www.bayerchristian.de",
      "inconsequential.org",
      "www.inconsequential.org",
      "localhost",
      "127.0.0.1"
    ],

    /**
     * Fingerprint-Exclude:
     * Wenn ENABLED = true und alle gesetzten Felder matchen,
     * wird pixl77.js normalerweise nicht gestartet. DEBUG.BYPASS_FILTERS kann das deaktivieren.
     *
     * BROWSER_IS: "chrome" | "safari" | "firefox" | "edge" | ...
     *   -> Vergleich ist case-insensitive und als substring
     *
     * OS_IS: "windows" | "macos" | "android" | "ios" | "linux" | ...
     *
     * COUNTRY_IS: z.B. "DE", "AT", "US"
     *   -> wird aus window.VISITOR_COUNTRY, <html data-country="DE">
     *      oder aus navigator.language ("de-DE") abgeleitet
     */
    FINGERPRINT_EXCLUDE: {
      ENABLED: false,
      BROWSER_IS: "",
      OS_IS: "",
      DEVICE_IS: "",
      COUNTRY_IS: ""
    },

    // SQL/PHP Collector auf dem eigenen Webspace.
    // pixl_collect.php speichert die Events in MySQL; pixl_stats.php zeigt sie an.
    SQL_ENDPOINT: "https://www.bayerchristian.de/stats3/pixl_collect.php",
    SQL_SITE_ID: "www.bayerchristian.de",
    SQL_PUBLIC_KEY: "",

    // Browser & Bot Filtering
    EXCLUDE_CHROME: false,
    ACCEPTED_OS: ["android", "windows", "ios", "macos", "linux"],
    BOT_PATTERNS: [
      "bot",
      "crawler",
      "spider",
      "scraper",
      "slurp",
      "wget",
      "curl",
      "python",
      "node-fetch",
      "go-http",
      "libwww",
      "httpclient",
      "scrapy",
      "phpcrawl",
      "nutch",
      "semrush",
      "ahrefs",
      "yandex",
      "googlebot",
      "bingbot",
      "duckduckbot",
      "baiduspider",
      "facebookexternalhit",
      "twitterbot",
      "linkedinbot",
      "whatsapp",
      "telegrambot",
      "applebot",
      "mj12bot",
      "dotbot",
      "petalbot",
      "bytespider",
      "gptbot",
      "chatgpt",
      "claude",
      "perplexity",
      "commoncrawl",
      "ccbot"
    ],

    // Reading Score & Delivery Thresholds
    READING: {
      MIN_SCORE: 10,
      THRESHOLDS: {
        READ: 10,
        GOOD: 25,
        EXCELLENT: 40
      }
    },

    // SQL Delivery Policy
    SQL: {
      MIN_SCORE_TO_NOTIFY: 1,

      // Maximal ein automatischer FINAL-Submit innerhalb einer Tab-Session.
      MAX_NOTIFICATIONS_PER_SESSION: 1,

      // Zusätzlich domainweit innerhalb des Browsers sperren.
      // Wirkt über Reloads, erneut geöffnete Tabs und parallele Tabs hinweg.
      SESSION_COOLDOWN_MS: 10 * 60 * 1000,
      GLOBAL_COOLDOWN_KEY: "__pixl77GlobalLastSubmitTsV1",

      // Tracker in eingebetteten iframes nicht erneut starten.
      BLOCK_IFRAMES: true,

      INCLUDE_CONSOLE_LOGS: true,
      INCLUDE_RENDER_ISSUES: true,

      // VISIT und READ werden nur als Meilensteine erfasst.
      // Gespeichert wird ausschließlich die finale Zusammenfassung beim Verlassen.
      NOTIFY_ON_VISIT: true,
      VISIT_DELAY_MS: 600,
      NOTIFY_ON_READ: true,
      NOTIFY_ON_LEAVE: true,

      // Ein bloßer Tab-Wechsel gilt nicht als Verlassen der Seite.
      NOTIFY_ON_HIDDEN: false,

      FINAL_SUMMARY_ONLY: true,
      FINAL_SUMMARY_REASON: "FINAL",
      ONE_AUTO_NOTIFICATION_ONLY: true,
      TRACK_BOTS_IMMEDIATELY: true,
      FETCH_TIMEOUT_MS: 4500,

      // Browser-Retry deaktiviert: verhindert erneute Zustellung alter Events
      // nach einem Reload, wenn ein Request beim Verlassen unsicher war.
      RETRY_QUEUE_ENABLED: false,
      RETRY_QUEUE_KEY: "__pixl77ReliableQueueV1",
      RETRY_QUEUE_LEGACY_KEYS: [
        "__pixl6ReliableQueueV1",
        "__pixl5ReliableQueueV2",
        "__pixl5OriginalReliableQueueV1"
      ],
      RETRY_QUEUE_MAX_ITEMS: 0
    },

    // Console Spy Configuration
    CONSOLE_SPY: {
      ENABLED: true,
      MAX_ENTRIES: 50,
      MAX_MESSAGE_LENGTH: 200
    },

    // Device & Screen Detection
    DEVICE_DETECT: {
      ENABLED: true
    },

    // Render Health Check
    RENDER_HEALTH: {
      ENABLED: true,
      INTERVAL_MS: 5000,
      MAX_FAILED_CHECKS: 5,
      MAX_CONSOLE_ERRORS: 20
    },

    // Reading Tracker Config
    READING_TRACKER: {
      ENABLED: true,
      SAMPLE_LENGTH: 31,
      MAX_SAMPLES: 50
    },

    // Known screen resolutions
    KNOWN_RESOLUTIONS: new Set([
      "1920x1080","1366x768","1536x864","1440x900","1280x720",
      "2560x1440","3840x2160","1680x1050","1600x900","1280x800",
      "390x844","393x873","412x915","375x812","360x780",
      "414x896","428x926","430x932","360x800","412x892"
    ])
  });

  const RC_OVERLAY_SESSION_KEY = "__rcOverlaySession";
  const SESSION_NOTIFICATION_COUNT_KEY = "__pixl77SubmitCount";
  const SESSION_LAST_NOTIFICATION_KEY = "__pixl77LastSubmitTs";
  const SCRIPT_NAME = (() => {
    try {
      const script = typeof document !== "undefined" && document.currentScript;
      const src = script && script.getAttribute("src");
      if (src) {
        const url = new URL(src, location.href);
        return url.pathname.split("/").pop() || "pixl77.js";
      }
    } catch {
      // ignore
    }
    return "pixl77.js";
  })();

  function readV3UserScore() {
    let score = null;
    try {
      if (typeof window === "undefined") {
        return null;
      }

      if (
        typeof window.__v3UserScore === "number" &&
        !Number.isNaN(window.__v3UserScore)
      ) {
        return window.__v3UserScore;
      }

      if (typeof window.sessionStorage !== "undefined") {
        const raw = window.sessionStorage.getItem(RC_OVERLAY_SESSION_KEY);
        if (raw) {
          try {
            const data = JSON.parse(raw);
            if (
              data &&
              typeof data.score === "number" &&
              !Number.isNaN(data.score)
            ) {
              score = data.score;
              window.__v3UserScore = score;
              return score;
            }
          } catch {
            // ignore parse errors
          }
        }
      }
    } catch {
      // ignore any unexpected errors
    }
    return score;
  }

  // =======================
  // Utility Helpers
  // =======================
  const Utils = {
    now() {
      return (typeof performance !== "undefined" && performance.now)
        ? performance.now()
        : Date.now();
    },

    clamp(value, min, max) {
      return Math.min(max, Math.max(min, value));
    },

    lerp(a, b, t) {
      return a + (b - a) * t;
    },

    formatLanguage(lang) {
      if (!lang) return "Unknown";
      const map = {
        de: "German",
        en: "English",
        fr: "French",
        es: "Spanish",
        it: "Italian",
        nl: "Dutch",
        ru: "Russian",
        pl: "Polish"
      };
      const short = lang.split("-")[0].toLowerCase();
      return map[short] || lang;
    },

    formatScreen(width, height) {
      return `${width}x${height}`;
    },

    normalizeCountryCode(value) {
      if (typeof value !== "string") return "";
      const trimmed = value.trim();
      if (!trimmed) return "";
      return trimmed.slice(0, 2).toUpperCase();
    },

    getCountryCode() {
      try {
        if (
          typeof window !== "undefined" &&
          typeof window.VISITOR_COUNTRY === "string"
        ) {
          const direct = Utils.normalizeCountryCode(window.VISITOR_COUNTRY);
          if (direct) return direct;
        }

        const doc = typeof document !== "undefined"
          ? document.documentElement
          : null;
        if (doc) {
          const attr = Utils.normalizeCountryCode(doc.getAttribute("data-country"));
          if (attr) return attr;
        }
      } catch {
        // ignore
      }

      return Utils.parseCountryFromLang(
        (typeof navigator !== "undefined" && navigator.language) || ""
      ) || "";
    },

    isAllowedHostname(hostname) {
      if (!hostname) return false;
      return CONFIG.ALLOWED_DOMAINS.some((domain) => (
        hostname === domain || hostname.endsWith(`.${domain}`)
      ));
    },

    isAutomationBrowser() {
      try {
        if (typeof navigator !== "undefined" && navigator.webdriver === true) {
          return true;
        }
      } catch {
        // ignore
      }
      return false;
    },

    isLikelyBot(ua) {
      return Utils.analyzeBot(ua).isBot;
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
        if (reason && !reasons.includes(reason)) {
          reasons.push(reason);
        }
        if (!category && nextCategory) {
          category = nextCategory;
        }
        if (!name && nextName) {
          name = nextName;
        }
      };

      const extraPatterns = [
        "headless",
        "headlesschrome",
        "playwright",
        "puppeteer",
        "phantomjs",
        "selenium",
        "automation"
      ];

      if (Utils.isAutomationBrowser()) {
        add(60, "navigator.webdriver", "automation", "webdriver");
      }

      const namedPatterns = [
        ["googlebot", "search", "Googlebot"],
        ["bingbot", "search", "Bingbot"],
        ["duckduckbot", "search", "DuckDuckBot"],
        ["baiduspider", "search", "Baiduspider"],
        ["yandex", "search", "Yandex"],
        ["applebot", "search", "Applebot"],
        ["facebookexternalhit", "social", "Facebook"],
        ["twitterbot", "social", "Twitterbot"],
        ["linkedinbot", "social", "LinkedInBot"],
        ["whatsapp", "social", "WhatsApp"],
        ["telegrambot", "social", "TelegramBot"],
        ["semrush", "seo", "Semrush"],
        ["ahrefs", "seo", "Ahrefs"],
        ["mj12bot", "seo", "MJ12bot"],
        ["dotbot", "seo", "DotBot"],
        ["petalbot", "seo", "PetalBot"],
        ["bytespider", "ai", "ByteSpider"],
        ["gptbot", "ai", "GPTBot"],
        ["chatgpt", "ai", "ChatGPT"],
        ["claude", "ai", "Claude"],
        ["perplexity", "ai", "Perplexity"],
        ["commoncrawl", "crawler", "Common Crawl"],
        ["ccbot", "crawler", "CCBot"],
        ["headlesschrome", "automation", "HeadlessChrome"],
        ["playwright", "automation", "Playwright"],
        ["puppeteer", "automation", "Puppeteer"],
        ["selenium", "automation", "Selenium"],
        ["phantomjs", "automation", "PhantomJS"],
        ["curl", "tool", "curl"],
        ["wget", "tool", "wget"],
        ["python", "tool", "Python"],
        ["node-fetch", "tool", "node-fetch"],
        ["go-http", "tool", "Go HTTP client"],
        ["php", "tool", "PHP client"]
      ];

      namedPatterns.forEach(([needle, nextCategory, nextName]) => {
        if (lowered.includes(needle)) {
          add(35, `ua:${needle}`, nextCategory, nextName);
        }
      });

      [...CONFIG.BOT_PATTERNS, ...extraPatterns].forEach((pattern) => {
        if (pattern && lowered.includes(pattern)) {
          add(20, `pattern:${pattern}`, category || "crawler", name || pattern);
        }
      });

      if (!source.trim()) {
        add(25, "missing-user-agent", "unknown", "Unknown UA");
      }

      if (/^(curl|wget|python|java|okhttp|go-http-client|php|node)/i.test(source)) {
        add(35, "tool-like-user-agent-prefix", "tool", name || "HTTP tool");
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

    safeJSON(value) {
      try {
        return JSON.stringify(value);
      } catch {
        return `"[unserializable]"`;
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
      if (!normalized) return "";
      return normalized.length > maxLength
        ? `${normalized.slice(0, maxLength)}…`
        : normalized;
    },

    uniqueStrings(values) {
      return [...new Set((values || []).filter(Boolean))];
    },

    parseCountryFromLang(lang) {
      if (!lang) return null;
      const parts = lang.split("-");
      if (parts.length > 1) {
        return parts[1].toUpperCase();
      }
      return null;
    },

    makeEventId() {
      try {
        if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
          return crypto.randomUUID();
        }
      } catch {
        // ignore
      }
      return [
        Date.now().toString(36),
        Math.random().toString(36).slice(2, 10),
        Math.random().toString(36).slice(2, 10)
      ].join("-");
    },

    safeStorage(type) {
      try {
        if (typeof window === "undefined") return null;
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
        if (typeof console[method] !== "function") return;

        this.originalConsole[method] = console[method].bind(console);
        console[method] = (...args) => {
          try {
            this._record(method, args);
          } catch {
            // ignore
          }
          this.originalConsole[method](...args);
        };
      });

      this.installed = true;
    }

    uninstall() {
      if (!this.installed) return;
      ["log", "warn", "error"].forEach((method) => {
        if (this.originalConsole[method]) {
          console[method] = this.originalConsole[method];
        }
      });
      this.installed = false;
    }

    _record(level, args) {
      const now = new Date().toISOString();
      const msg = args
        .map((arg) => {
          if (typeof arg === "string") return arg;
          return Utils.safeJSON(arg);
        })
        .join(" ");
      const trimmed = Utils.sanitizeLogMessage(msg, this.maxLength);

      this.entries.push({ time: now, level, message: trimmed });
      if (this.entries.length > this.maxEntries) {
        this.entries.shift();
      }
    }

    getEntries() {
      return this.entries.slice();
    }

    getErrorCount() {
      return this.entries.filter((e) => e.level === "error").length;
    }
  }

  // =======================
  // Dialog & Error Interceptor
  // =======================
  class DialogInterceptor {
    constructor() {
      this.originalAlert = window.alert;
      this.originalConfirm = window.confirm;
      this.originalPrompt = window.prompt;
      this.errorEvents = [];
      this.installed = false;
    }

    install() {
      if (this.installed) return;

      window.alert = (msg) => {
        this._record("alert", msg);
        return this.originalAlert(msg);
      };

      window.confirm = (msg) => {
        this._record("confirm", msg);
        return this.originalConfirm(msg);
      };

      window.prompt = (msg, def) => {
        this._record("prompt", msg);
        return this.originalPrompt(msg, def);
      };

      window.addEventListener("error", (event) => {
        this._record("error", event.message || "Unknown error");
      });

      window.addEventListener("unhandledrejection", (event) => {
        this._record(
          "unhandledrejection",
          event.reason ? String(event.reason) : "Unknown rejection"
        );
      });

      this.installed = true;
    }

    uninstall() {
      if (!this.installed) return;
      window.alert = this.originalAlert;
      window.confirm = this.originalConfirm;
      window.prompt = this.originalPrompt;
      this.installed = false;
    }

    _record(type, message) {
      const now = new Date().toISOString();
      const cleaned = Utils.sanitizeLogMessage(message, 240) || "Unknown";
      this.errorEvents.push({ time: now, type, message: cleaned });
      if (this.errorEvents.length > 100) {
        this.errorEvents.shift();
      }
    }

    getErrorEvents() {
      return this.errorEvents.slice();
    }
  }

  // =======================
  // Device & Context Detection
  // =======================
  class DeviceDetector {
    constructor() {
      this.ua = (navigator && navigator.userAgent) || "";
    }

    getBrowser() {
      const uaRaw = this.ua || "";
      const ua = uaRaw.toLowerCase();
      if (!ua || Utils.isLikelyBot(ua)) return "Unknown";

      // iOS-Browser zuerst prüfen, weil iOS-User-Agents fast immer Safari enthalten.
      if (/crios\//i.test(uaRaw)) return "chrome";
      if (/fxios\//i.test(uaRaw)) return "firefox";
      if (/edgios\//i.test(uaRaw)) return "edge";
      if (/opios\//i.test(uaRaw)) return "opera";

      if (/samsungbrowser\//i.test(uaRaw)) return "samsung internet";
      if (/edg\//i.test(uaRaw)) return "edge";
      if (/opr\//i.test(uaRaw) || /opera\//i.test(uaRaw)) return "opera";
      if (/firefox\//i.test(uaRaw) || /fennec\//i.test(uaRaw)) return "firefox";

      if ((/; wv\)/i.test(uaRaw) || /version\/\d+\.\d+.*chrome\//i.test(uaRaw)) && /android/i.test(uaRaw)) {
        return "android webview";
      }

      if (/chrome\//i.test(uaRaw) || /chromium\//i.test(uaRaw)) return "chrome";

      if (/safari\//i.test(uaRaw) && /version\//i.test(uaRaw) && !/chrome|chromium|crios|fxios|edg|edgios|opr|opios|samsungbrowser/i.test(uaRaw)) {
        return "safari";
      }

      if (/msie\s|trident\//i.test(uaRaw)) return "internet explorer";
      return "Unknown";
    }

    getOS() {
      const uaRaw = this.ua || "";
      const ua = uaRaw.toLowerCase();
      const platform = (navigator && navigator.platform) || "";
      const maxTouchPoints = Number((navigator && navigator.maxTouchPoints) || 0);

      if (!ua || Utils.isLikelyBot(ua)) return "Unknown";
      if (/android/i.test(uaRaw)) return "android";
      if (/iphone|ipad|ipod/i.test(uaRaw)) return "ios";

      // iPadOS Desktop Mode: meldet oft Macintosh + Touch.
      if (/macintosh/i.test(uaRaw) && /mac/i.test(platform) && maxTouchPoints > 1) {
        return "ios";
      }

      if (/windows nt/i.test(uaRaw)) return "windows";
      if (/cros/i.test(uaRaw)) return "chromeos";
      if (/mac os x|macintosh/i.test(uaRaw)) return "macos";
      if (/linux|x11/i.test(uaRaw)) return "linux";
      return "Unknown";
    }

    getDeviceType() {
      const uaRaw = this.ua || "";
      const ua = uaRaw.toLowerCase();
      const platform = (navigator && navigator.platform) || "";
      const maxTouchPoints = Number((navigator && navigator.maxTouchPoints) || 0);
      const os = this.getOS();

      if (!ua || Utils.isLikelyBot(ua) || os === "Unknown") return "Unknown";
      if (/ipad/i.test(uaRaw)) return "Tablet";
      if (/macintosh/i.test(uaRaw) && /mac/i.test(platform) && maxTouchPoints > 1) return "Tablet";
      if (/tablet|playbook|silk/i.test(uaRaw)) return "Tablet";
      if (/android/i.test(uaRaw) && !/mobile/i.test(uaRaw)) return "Tablet";
      if (/mobi|iphone|ipod|android.*mobile|windows phone/i.test(uaRaw)) return "Mobile";

      if (["windows", "macos", "linux", "chromeos"].includes(os)) return "Desktop";

      const width = Number((window && window.screen && window.screen.width) || window.innerWidth || 0);
      const height = Number((window && window.screen && window.screen.height) || window.innerHeight || 0);
      const shortestSide = Math.min(width, height);
      if (shortestSide > 0 && shortestSide <= 480 && maxTouchPoints > 0) return "Mobile";
      if (shortestSide > 480 && shortestSide <= 1024 && maxTouchPoints > 0) return "Tablet";
      return "Unknown";
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
      return {
        value: res,
        known: CONFIG.KNOWN_RESOLUTIONS.has(res)
      };
    }

    getLanguage() {
      return navigator.language || navigator.userLanguage || "en";
    }

    getPath() {
      return (location.pathname || "/").replace(/^\/+/, "");
    }

    getCountryGuess() {
      return Utils.getCountryCode() || "Unknown";
    }
  }

  // =======================
  // Fingerprint Helpers
  // =======================

  function isFingerprintExcluded() {
    if (CONFIG.DEBUG && CONFIG.DEBUG.BYPASS_FILTERS) return false;

    const rules = Array.isArray(CONFIG.FINGERPRINT_EXCLUDES)
      ? CONFIG.FINGERPRINT_EXCLUDES
      : [CONFIG.FINGERPRINT_EXCLUDE].filter(Boolean);

    if (!rules.length) return false;

    const detector = new DeviceDetector();
    const browser = detector.getBrowser();
    const os = detector.getOS();
    const country = Utils.getCountryCode();

    const debug = CONFIG.DEBUG && CONFIG.DEBUG.ENABLED;
    if (debug) {
      console.log("[pixl77] Fingerprint:", { browser, os, country });
    }

    return rules.some((cfg) => {
      if (!cfg || !cfg.ENABLED) return false;

      let browserMatch = true;
      let osMatch = true;
      let countryMatch = true;

      if (cfg.BROWSER_IS) {
        browserMatch = browser.toLowerCase().includes(String(cfg.BROWSER_IS).toLowerCase());
      }

      if (cfg.OS_IS) {
        osMatch = os.toLowerCase().includes(String(cfg.OS_IS).toLowerCase());
      }

      if (cfg.COUNTRY_IS) {
        countryMatch = country.toLowerCase() === String(cfg.COUNTRY_IS).toLowerCase();
      }

      const exclude = browserMatch && osMatch && countryMatch;

      if (exclude && debug) {
        console.log("[pixl77] Fingerprint-Exclude active - pixl77.js stops here.");
      }

      return exclude;
    });
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
      this.lastMouseMove = { x: 0, y: 0, t: this.lastActivity };
      this.keyPressCount = 0;
      this.hasInteracted = false;
      this.startTime = Date.now();
      this._onActivity = this._onActivity.bind(this);
    }

    install() {
      if (!this.enabled) return;
      window.addEventListener("scroll", this._onActivity, { passive: true });
      window.addEventListener("mousemove", this._onActivity, { passive: true });
      window.addEventListener("keydown", this._onActivity, { passive: true });
      window.addEventListener("touchstart", this._onActivity, {
        passive: true
      });
    }

    uninstall() {
      window.removeEventListener("scroll", this._onActivity);
      window.removeEventListener("mousemove", this._onActivity);
      window.removeEventListener("keydown", this._onActivity);
      window.removeEventListener("touchstart", this._onActivity);
    }

    _onActivity(event) {
      const now = Utils.now();
      const dt = now - this.lastActivity;
      this.lastActivity = now;

      let delta = 0;

      switch (event.type) {
        case "scroll": {
          const newY = window.scrollY;
          const diff = Math.abs(newY - this.lastScrollY);
          this.lastScrollY = newY;
          delta += diff > 0 ? Math.log2(1 + diff) : 0;
          break;
        }
        case "mousemove": {
          const dx = event.clientX - this.lastMouseMove.x;
          const dy = event.clientY - this.lastMouseMove.y;
          const dist = Math.sqrt(dx * dx + dy * dy);
          this.lastMouseMove = { x: event.clientX, y: event.clientY, t: now };
          delta += dist > 0 ? Math.log2(1 + dist) : 0;
          break;
        }
        case "keydown": {
          this.keyPressCount++;
          delta += 2;
          break;
        }
        case "touchstart": {
          delta += 3;
          break;
        }
        default:
          break;
      }

      if (dt > 0 && dt < this.sampleLength * 2) {
        const factor = this.sampleLength / dt;
        delta *= Utils.clamp(factor, 0.5, 2.0);
      }

      if (delta > 0) {
        this.hasInteracted = true;
        this._addSample(delta);
      }
    }

    _addSample(delta) {
      this.samples.push(delta);
      if (this.samples.length > this.maxSamples) {
        this.samples.shift();
      }
    }

    getScore() {
      if (!this.samples.length) return 0;
      const sum = this.samples.reduce((a, b) => a + b, 0);
      return Math.round(sum);
    }

    getScoreTrail() {
      if (!this.samples.length) return "";
      const recent = this.samples.slice(-5);
      return recent.map((v) => v.toFixed(1)).join(",");
    }

    hasEnoughData() {
      return this.samples.length >= 5 && this.hasInteracted;
    }

    getDuration() {
      return Math.round((Date.now() - this.startTime) / 1000);
    }
  }

  // =======================
  // Render Health Checker
  // =======================
  class RenderHealthChecker {
    constructor(config, consoleSpy, dialogInterceptor) {
      this.enabled = config.ENABLED !== false;
      this.intervalMs = config.INTERVAL_MS || 5000;
      this.maxChecks = config.MAX_FAILED_CHECKS || 5;
      this.maxConsoleErrors = config.MAX_CONSOLE_ERRORS || 20;

      this.consoleSpy = consoleSpy;
      this.dialogInterceptor = dialogInterceptor;

      this.checkInterval = null;
      this.failedChecks = 0;
      this.renderIssues = [];
    }

    start() {
      if (!this.enabled || this.checkInterval) return;
      this.checkInterval = setInterval(
        () => this._check(),
        this.intervalMs
      );
    }

    stop() {
      if (this.checkInterval) {
        clearInterval(this.checkInterval);
        this.checkInterval = null;
      }
    }

    _check() {
      if (document.readyState !== "complete") return;

      const bodyOK = !!document.body;
      const contentOK =
        document.body &&
        (document.body.textContent || "").trim().length > 10;

      if (!bodyOK || !contentOK) {
        this.renderIssues.push("EMPTY_BODY");
        this.failedChecks++;
      }

      const errorCount = this.consoleSpy.getErrorCount();
      if (errorCount > this.maxConsoleErrors) {
        this.renderIssues.push("MANY_CONSOLE_ERRORS");
        this.failedChecks++;
      }

      const dialogErrors = this.dialogInterceptor.getErrorEvents();
      if (dialogErrors.length > 0) {
        this.renderIssues.push("DIALOG_ERRORS");
      }

      if (this.failedChecks >= this.maxChecks) {
        this.stop();
      }
    }

    getIssues() {
      return Utils.uniqueStrings(this.renderIssues);
    }
  }

  // =======================
  // SQL Analytics Delivery
  // =======================
  function timeoutPromise(ms) {
    return new Promise((_, reject) => {
      setTimeout(() => reject(new Error("timeout")), ms);
    });
  }

  function isRetryQueueEnabled() {
    const maxItems = Number(CONFIG.SQL.RETRY_QUEUE_MAX_ITEMS) || 0;
    return CONFIG.SQL.RETRY_QUEUE_ENABLED === true && maxItems > 0;
  }

  function clearRetryQueues() {
    const storage = Utils.safeStorage("localStorage");
    if (!storage) return false;

    try {
      const keys = [
        CONFIG.SQL.RETRY_QUEUE_KEY,
        ...(Array.isArray(CONFIG.SQL.RETRY_QUEUE_LEGACY_KEYS)
          ? CONFIG.SQL.RETRY_QUEUE_LEGACY_KEYS
          : [])
      ];

      for (const key of keys) {
        if (typeof key === "string" && key) {
          storage.removeItem(key);
        }
      }

      return true;
    } catch {
      return false;
    }
  }

  function readRetryQueue() {
    if (!isRetryQueueEnabled()) return [];

    const storage = Utils.safeStorage("localStorage");
    if (!storage) return [];
    try {
      const raw = storage.getItem(CONFIG.SQL.RETRY_QUEUE_KEY);
      const parsed = raw ? JSON.parse(raw) : [];
      return Array.isArray(parsed) ? parsed : [];
    } catch {
      return [];
    }
  }

  function writeRetryQueue(items) {
    const storage = Utils.safeStorage("localStorage");
    if (!storage) return false;

    try {
      if (!isRetryQueueEnabled()) {
        storage.removeItem(CONFIG.SQL.RETRY_QUEUE_KEY);
        return true;
      }

      const maxItems = Math.max(
        1,
        Number(CONFIG.SQL.RETRY_QUEUE_MAX_ITEMS) || 1
      );

      storage.setItem(
        CONFIG.SQL.RETRY_QUEUE_KEY,
        JSON.stringify(items.slice(-maxItems))
      );
      return true;
    } catch {
      return false;
    }
  }

  function enqueueRetry(payload, reason) {
    if (!isRetryQueueEnabled()) return false;

    const queue = readRetryQueue();
    queue.push({ payload, reason: reason || "delivery_failed", createdAt: Date.now() });
    return writeRetryQueue(queue);
  }

  async function flushRetryQueue() {
    if (!isRetryQueueEnabled()) {
      clearRetryQueues();
      return;
    }

    const queue = readRetryQueue();
    if (!queue.length) return;
    writeRetryQueue([]);
    for (const item of queue) {
      if (!item || !item.payload) continue;
      try {
        await sendAnalyticsEvent(item.payload, { allowQueue: false });
      } catch {
        // Do not endlessly requeue old items during flush.
      }
    }
  }

  async function sendAnalyticsEvent(eventPayload, options = {}) {
    const allowQueue = options.allowQueue !== false;
    const url = CONFIG.SQL_ENDPOINT;
    const payload = eventPayload && typeof eventPayload === "object"
      ? eventPayload
      : { reason: "UNKNOWN", message: String(eventPayload || "") };
    const reason = String(payload.reason || "").toUpperCase();

    if (!url) {
      throw new Error("sql_endpoint_missing");
    }

    const body = JSON.stringify(payload);

    try {
      if (
        (reason === "LEAVE" || reason === "FINAL") &&
        typeof navigator !== "undefined" &&
        typeof navigator.sendBeacon === "function" &&
        typeof Blob !== "undefined"
      ) {
        const beaconOk = navigator.sendBeacon(
          url,
          new Blob([body], {
            type: "text/plain;charset=UTF-8"
          })
        );
        if (beaconOk) {
          return { ok: true, transport: "beacon" };
        }
      }

      if (typeof fetch !== "function") {
        throw new Error("fetch_unavailable");
      }

      const request = fetch(url, {
        method: "POST",
        mode: "no-cors",
        credentials: "omit",
        cache: "no-store",
        headers: {
          "Content-Type": "text/plain;charset=UTF-8"
        },
        body,
        keepalive: true
      });

      await Promise.race([
        request,
        timeoutPromise(CONFIG.SQL.FETCH_TIMEOUT_MS || 4500)
      ]);

      // no-cors returns an opaque response. If the browser accepted the request,
      // treat it as sent; exact server confirmation is visible in pixl_stats.php.
      return { ok: true, transport: "fetch-no-cors" };
    } catch (err) {
      if (allowQueue) {
        enqueueRetry(payload, err && err.message ? err.message : "send_failed");
      }
      throw err;
    }
  }

  // =======================
  // Pixl Analytics
  // =======================
  class PixlAnalytics {
    constructor() {
      this.deviceDetector = new DeviceDetector();
      this.consoleSpy = new ConsoleSpy(
        CONFIG.CONSOLE_SPY.MAX_ENTRIES,
        CONFIG.CONSOLE_SPY.MAX_MESSAGE_LENGTH
      );
      this.dialogInterceptor = new DialogInterceptor();
      this.readingTracker = new ReadingTracker(CONFIG.READING_TRACKER);
      this.renderChecker = new RenderHealthChecker(
        CONFIG.RENDER_HEALTH,
        this.consoleSpy,
        this.dialogInterceptor
      );
      this.notificationCount = this._readNumberFromSession(
        SESSION_NOTIFICATION_COUNT_KEY
      );
      this.lastNotificationTs = this._readNumberFromSession(
        SESSION_LAST_NOTIFICATION_KEY
      );
      this.context = null;
      this.renderIssues = [];
      this.readTimeout = null;
      this.readInterval = null;
      this.leaveNotified = false;
      this.botVisitSent = false;
      this.finalNotificationSent = false;
      this.sessionStartedAt = Date.now();
      this.sessionEvents = { VISIT: false, READ: false, LEAVE: false };
      this.sessionEventTimes = { VISIT: null, READ: null, LEAVE: null };
      this.eventTrail = [];
      this.bestReadScore = 0;
      this.bestReadDuration = 0;
      this.autoNotificationSent = this.notificationCount > 0;
      this._boundPageHide = this._handlePageHide.bind(this);
      this._boundVisibilityChange = this._handleVisibilityChange.bind(this);
      this._boundBeforeUnload = this._handleBeforeUnload.bind(this);
    }

    _readNumberFromSession(key) {
      try {
        const raw = window.sessionStorage.getItem(key);
        const value = Number(raw);
        return Number.isFinite(value) ? value : 0;
      } catch {
        return 0;
      }
    }

    _writeNumberToSession(key, value) {
      try {
        window.sessionStorage.setItem(key, String(value));
      } catch {
        // ignore
      }
    }

    _readNumberFromLocal(key) {
      try {
        const storage = Utils.safeStorage("localStorage");
        if (!storage || !key) return 0;
        const raw = storage.getItem(key);
        const value = Number(raw);
        return Number.isFinite(value) ? value : 0;
      } catch {
        return 0;
      }
    }

    _writeNumberToLocal(key, value) {
      try {
        const storage = Utils.safeStorage("localStorage");
        if (!storage || !key) return;
        storage.setItem(key, String(value));
      } catch {
        // ignore
      }
    }

    _getLatestNotificationTs() {
      const localTs = this._readNumberFromLocal(
        CONFIG.SQL.GLOBAL_COOLDOWN_KEY
      );
      return Math.max(this.lastNotificationTs || 0, localTs || 0);
    }

    _isNotificationCooldownActive() {
      const cooldownMs = Number(CONFIG.SQL.SESSION_COOLDOWN_MS) || 0;
      if (cooldownMs <= 0) return false;

      const latestTs = this._getLatestNotificationTs();
      this.lastNotificationTs = latestTs;

      return latestTs > 0 && Date.now() - latestTs < cooldownMs;
    }

    _notifyAndMarkSession() {
      this.notificationCount += 1;
      this.lastNotificationTs = Date.now();
      this._writeNumberToSession(
        SESSION_NOTIFICATION_COUNT_KEY,
        this.notificationCount
      );
      this._writeNumberToSession(
        SESSION_LAST_NOTIFICATION_KEY,
        this.lastNotificationTs
      );
      this._writeNumberToLocal(
        CONFIG.SQL.GLOBAL_COOLDOWN_KEY,
        this.lastNotificationTs
      );
    }

    _recordMilestone(reason) {
      const name = String(reason || "Unknown").toUpperCase();
      if (!this.sessionEvents) {
        this.sessionEvents = { VISIT: false, READ: false, LEAVE: false };
      }
      if (!this.sessionEventTimes) {
        this.sessionEventTimes = { VISIT: null, READ: null, LEAVE: null };
      }

      const markReached = (eventName) => {
        if (!Object.prototype.hasOwnProperty.call(this.sessionEvents, eventName)) {
          return;
        }
        this.sessionEvents[eventName] = true;
        if (this.sessionEventTimes[eventName] === null) {
          this.sessionEventTimes[eventName] = Math.max(
            0,
            Math.round((Date.now() - this.sessionStartedAt) / 1000)
          );
        }
      };

      if (name === "READ") {
        const score = this.readingTracker.getScore();
        const duration = this.readingTracker.getDuration();
        if (score > this.bestReadScore) this.bestReadScore = score;
        if (duration > this.bestReadDuration) this.bestReadDuration = duration;

        // READ zählt als erreicht, sobald der READ-Checkpoint wirklich existiert
        // und seit Seitenstart mehr als 0 Sekunden vergangen sind.
        // Dadurch hat READ in der finalen Anzeige Priorität vor LEAVE,
        // auch wenn der ReadingScore noch klein oder 0 ist.
        if (duration > 0) {
          markReached("READ");
        }
      } else if (Object.prototype.hasOwnProperty.call(this.sessionEvents, name)) {
        markReached(name);
      }

      if (!this.eventTrail.includes(name)) {
        this.eventTrail.push(name);
      }
    }

    _handleVisitCheckpoint() {
      if (!CONFIG.SQL.NOTIFY_ON_VISIT) return;
      this._recordMilestone("VISIT");
    }

    _handleReadCheckpoint() {
      if (!CONFIG.SQL.NOTIFY_ON_READ) return;
      this._recordMilestone("READ");
    }

    _handlePageHide() {
      if (this.leaveNotified) return;
      this.leaveNotified = true;
      this._sendFinalSummary("LEAVE");
    }

    _handleVisibilityChange() {
      if (document.visibilityState === "hidden") {
        this._handlePageHide();
      }
    }

    _handleBeforeUnload() {
      this._handlePageHide();
    }

    async init() {
      const debugBypass = CONFIG.DEBUG && CONFIG.DEBUG.BYPASS_FILTERS;
      const debugEnabled = CONFIG.DEBUG && CONFIG.DEBUG.ENABLED;

      const hostname = location.hostname;
      const allowed = Utils.isAllowedHostname(hostname);
      if (!allowed && !(debugEnabled && debugBypass)) return;

      const ua = navigator.userAgent || "";
      const botAnalysis = Utils.analyzeBot(ua);

      const os = this.deviceDetector.getOS();
      if (!debugBypass && !botAnalysis.isBot && !CONFIG.ACCEPTED_OS.includes(os)) return;

      const browser = this.deviceDetector.getBrowser();
      if (!debugBypass && CONFIG.EXCLUDE_CHROME && browser === "chrome") return;

      const { value: screenRes, known: knownResolution } =
        this.deviceDetector.getScreenResolution();
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
        timezone: (
          typeof Intl !== "undefined" &&
          Intl.DateTimeFormat &&
          Intl.DateTimeFormat().resolvedOptions
        )
          ? Intl.DateTimeFormat().resolvedOptions().timeZone || ""
          : ""
      };

      this.consoleSpy.install();
      this.dialogInterceptor.install();
      this.readingTracker.install();
      this.renderChecker.start();

      if (isRetryQueueEnabled()) {
        setTimeout(() => {
          flushRetryQueue().catch((err) => {
            console.warn && console.warn("Pixl SQL retry queue failed:", err);
          });
        }, 1400);
      } else {
        clearRetryQueues();
      }

      if (CONFIG.SQL.NOTIFY_ON_VISIT) {
        setTimeout(() => {
          this._handleVisitCheckpoint();
        }, CONFIG.SQL.VISIT_DELAY_MS || 600);
      }

      if (CONFIG.SQL.TRACK_BOTS_IMMEDIATELY && botAnalysis.isBot) {
        setTimeout(() => {
          this._sendBotVisit();
        }, CONFIG.SQL.VISIT_DELAY_MS || 600);
      }

      if (CONFIG.SQL.NOTIFY_ON_LEAVE) {
        window.addEventListener("pagehide", this._boundPageHide, { passive: true });
        window.addEventListener("beforeunload", this._boundBeforeUnload, {
          passive: true
        });

        if (CONFIG.SQL.NOTIFY_ON_HIDDEN) {
          document.addEventListener("visibilitychange", this._boundVisibilityChange, {
            passive: true
          });
        }
      }

      if (CONFIG.SQL.NOTIFY_ON_READ) {
        this.readTimeout = setTimeout(() => {
          this._handleReadCheckpoint();
        }, 15000);

        this.readInterval = setInterval(() => {
          this._handleReadCheckpoint();
        }, 30000);
      }
    }

    _buildTitle() {
      const ctx = this.context;
      const parts = [];

      // --- 1) BOT / BOT2 / USER / VISIT ---
      const ua = ctx.ua.toLowerCase();

      let status = "Visit";

      // Bot-Erkennung
      const isBot = Utils.isLikelyBot(ua);
      // Datacenter-Erkennung
      const isDataCenter =
        ua.includes("headless") ||
        ua.includes("crawler") ||
        ua.includes("spider") ||
        ua.includes("phantom") ||
        ua.includes("node") ||
        navigator.webdriver === true;

      if (isBot) {
        status = "Bot";
      } else if (isDataCenter) {
        status = "Bot2";
      } else if (ctx.browser === "chrome" || ctx.os === "windows") {
        status = "User";
      }

      parts.push(status);

      // --- 2) Sprache (wie gehabt) ---
      const langNice = Utils.formatLanguage(ctx.lang);
      parts.push(langNice);

      // --- 3) OKAY / BAD für Screen Resolution ---
      parts.push(ctx.knownResolution ? "OKAY" : "BAD");

      // --- 4) Frame / NoFrame Test ---
      const inFrame = (() => {
        try {
          return window.self !== window.top;
        } catch {
          return true;
        }
      })();

      parts.push(inFrame ? "Frame" : "NoFrame");

      return parts.join(" - ");
    }

    _getFinalReadingLabel() {
      const eventState = this.sessionEvents || { VISIT: false, READ: false, LEAVE: false };
      const eventTimes = this.sessionEventTimes || { VISIT: null, READ: null, LEAVE: null };
      const readSeconds = eventTimes.READ;

      // Anzeige-Regel:
      // - Sobald READ existiert und über 0 Sekunden liegt, zeige READ.
      // - Dadurch gewinnt READ vor einem späteren LEAVE.
      // - Sonst, wenn LEAVE eingetroffen ist, zeige LEAVE.
      // - Sonst, wenn nur VISIT eingetroffen ist, zeige VISIT.
      // - Wenn nichts sicher ist, bleibe konservativ bei Unknown.
      if (eventState.READ && Number.isFinite(readSeconds) && readSeconds > 0) return "READ";
      if (eventState.LEAVE) return "LEAVE";
      if (eventState.VISIT) return "VISIT";
      return "Unknown";
    }

    _formatEventSeconds(eventName) {
      const eventState = this.sessionEvents || { VISIT: false, READ: false, LEAVE: false };
      const eventTimes = this.sessionEventTimes || { VISIT: null, READ: null, LEAVE: null };
      const name = String(eventName || "").toUpperCase();
      if (!eventState[name] || eventTimes[name] === null || !Number.isFinite(eventTimes[name])) {
        return "Unknown";
      }
      return String(eventTimes[name]) + "s";
    }

    _getEventSecondsNumber(eventName) {
      const eventState = this.sessionEvents || { VISIT: false, READ: false, LEAVE: false };
      const eventTimes = this.sessionEventTimes || { VISIT: null, READ: null, LEAVE: null };
      const name = String(eventName || "").toUpperCase();
      if (!eventState[name] || eventTimes[name] === null || !Number.isFinite(eventTimes[name])) {
        return null;
      }
      return eventTimes[name];
    }

    _buildMessage(reason) {
      const ctx = this.context;
      const reading = this.readingTracker;
      const score = reading.getScore();
      const scoreTrail = this.readingTracker.getScoreTrail();
      const render = this.renderIssues.length
        ? this.renderIssues.join("|")
        : "OK";

      const langNice = Utils.formatLanguage(ctx.lang);

      const v3Score = readV3UserScore();
      const sessionDuration = Math.max(0, Math.round((Date.now() - this.sessionStartedAt) / 1000));
      const readingLabel = this._getFinalReadingLabel();
      const readingSeconds = this._formatEventSeconds(readingLabel);
      const readingDisplay = readingLabel === "Unknown" || readingSeconds === "Unknown"
        ? readingLabel
        : `${readingLabel} (${readingSeconds})`;

      const lines = [
        `Screen: ${ctx.screen}${ctx.knownResolution ? "" : " (?)"}`,
        `Lang: ${ctx.lang} (${langNice})`,
        `Country: ${ctx.country || "Unknown"}`,
        `Browser: ${ctx.browser}`,
        `OS: ${ctx.os}`,
        `Device: ${ctx.device}/${ctx.screenCategory}`,
        `Path: /${ctx.path}`,
        `SessionDuration: ${sessionDuration}s`,
        `Reading: ${readingDisplay}`,
        `ReadingScore: ${score}${
          scoreTrail ? ` [${scoreTrail}]` : ""
        }`
      ];

      if (typeof v3Score === "number" && !Number.isNaN(v3Score)) {
        lines.push(`v3UserScore: ${v3Score.toFixed(2)}`);
      }

      if (CONFIG.SQL.INCLUDE_RENDER_ISSUES) {
        lines.push(`Render: ${render}`);
      }

      if (CONFIG.SQL.INCLUDE_CONSOLE_LOGS) {
        const errors = this.consoleSpy
          .getEntries()
          .filter((e) => e.level === "error")
          .slice(-3);
        if (errors.length) {
          lines.push(
            "",
            "Console Errors (last 3):",
            ...errors.map(
              (e) => `- [${e.time}] ${Utils.sanitizeLogMessage(e.message, 160)}`
            )
          );
        }
      }

      const dialogEvents = this.dialogInterceptor
        .getErrorEvents()
        .slice(-3);
      if (dialogEvents.length) {
        lines.push(
          "",
          "Dialog / JS Errors:",
          ...dialogEvents.map(
            (e) => `- [${e.time}] ${e.type}: ${Utils.sanitizeLogMessage(e.message, 160)}`
          )
        );
      }

      return lines.join("\n");
    }

    _buildEventPayload(reason, title, message) {
      const ctx = this.context;
      const reading = this.readingTracker;
      const score = reading.getScore();
      const scoreTrail = reading.getScoreTrail();
      const v3Score = readV3UserScore();
      const sessionDuration = Math.max(
        0,
        Math.round((Date.now() - this.sessionStartedAt) / 1000)
      );
      const readingLabel = this._getFinalReadingLabel();
      const readingSeconds = this._getEventSecondsNumber(readingLabel);
      const renderStatus = this.renderIssues.length
        ? this.renderIssues.join("|")
        : "OK";
      const consoleErrors = this.consoleSpy
        .getEntries()
        .filter((entry) => entry.level === "error");
      const dialogEvents = this.dialogInterceptor.getErrorEvents();

      const inFrame = (() => {
        try {
          return window.self !== window.top;
        } catch {
          return true;
        }
      })();

      return {
        schema: "pixl-sql-v1",
        siteId: CONFIG.SQL_SITE_ID || ctx.hostname || "default",
        siteKey: CONFIG.SQL_PUBLIC_KEY || "",
        eventId: Utils.makeEventId(),
        sentAt: new Date().toISOString(),
        reason: String(reason || "UNKNOWN").toUpperCase(),
        title,
        message,
        script: {
          name: SCRIPT_NAME,
          version: CONFIG.VERSION
        },
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
          readingLabel,
          readingSeconds,
          readingScore: score,
          scoreTrail,
          bestReadScore: this.bestReadScore,
          bestReadDuration: this.bestReadDuration,
          v3UserScore: typeof v3Score === "number" && !Number.isNaN(v3Score)
            ? Number(v3Score.toFixed(2))
            : null
        },
        health: {
          renderStatus,
          renderIssues: this.renderIssues.slice(),
          consoleErrorCount: consoleErrors.length,
          consoleErrors: consoleErrors.slice(-3),
          dialogErrorCount: dialogEvents.length,
          dialogEvents: dialogEvents.slice(-3)
        },
        bot: ctx.bot || Utils.analyzeBot(ctx.ua),
        events: {
          reached: { ...this.sessionEvents },
          seconds: { ...this.sessionEventTimes },
          trail: this.eventTrail.slice()
        },
        flags: {
          inFrame,
          webdriver: !!(navigator && navigator.webdriver === true)
        }
      };
    }

    _collectRenderIssues() {
      const issues = [];
      const renderIssues = this.renderChecker.getIssues();
      if (renderIssues.length) {
        issues.push(...renderIssues);
      }

      const consoleEntries = this.consoleSpy.getEntries();
      const errorCount = consoleEntries.filter(
        (e) => e.level === "error"
      ).length;
      if (errorCount > CONFIG.RENDER_HEALTH.MAX_CONSOLE_ERRORS) {
        issues.push("MANY_CONSOLE_ERRORS_OVERALL");
      }

      this.renderIssues = Utils.uniqueStrings(issues);
    }

    _sendBotVisit() {
      if (this.botVisitSent || !this.context || !this.context.bot || !this.context.bot.isBot) {
        return;
      }

      if (this.notificationCount >= CONFIG.SQL.MAX_NOTIFICATIONS_PER_SESSION) {
        return;
      }

      this.botVisitSent = true;
      this._recordMilestone("VISIT");
      this._collectRenderIssues();
      this._notifyAndMarkSession();
      this.autoNotificationSent = true;

      const title = this._buildTitle();
      const message = this._buildMessage("BOT");
      const payload = this._buildEventPayload("BOT", title, message);

      sendAnalyticsEvent(payload).catch((err) => {
        console.error && console.error("Pixl SQL bot visit failed:", err);
      });
    }

    _shouldSendFinalSummary() {
      if (!this.context) return false;
      if (this.finalNotificationSent) return false;

      const debugEnabled = CONFIG.DEBUG && CONFIG.DEBUG.ENABLED;
      const debugForce = debugEnabled && CONFIG.DEBUG.FORCE_NOTIFY;

      // FORCE_NOTIFY darf Session-Limits umgehen, aber nicht doppelte FINALs erzeugen.
      if (debugForce) {
        return true;
      }

      if (this.notificationCount >= CONFIG.SQL.MAX_NOTIFICATIONS_PER_SESSION) {
        return false;
      }

      if (this._isNotificationCooldownActive()) {
        return false;
      }

      return !!CONFIG.SQL.NOTIFY_ON_LEAVE;
    }

    _sendFinalSummary(triggerReason = "LEAVE") {
      if (!CONFIG.SQL.FINAL_SUMMARY_ONLY) {
        this._maybeNotify(triggerReason);
        return;
      }

      this._recordMilestone("LEAVE");

      if (!this._shouldSendFinalSummary()) return;

      this._collectRenderIssues();
      this.finalNotificationSent = true;
      this.autoNotificationSent = true;
      this._notifyAndMarkSession();

      const title = this._buildTitle();
      const message = this._buildMessage(CONFIG.SQL.FINAL_SUMMARY_REASON || "FINAL");
      const payload = this._buildEventPayload(
        CONFIG.SQL.FINAL_SUMMARY_REASON || "FINAL",
        title,
        message
      );

      sendAnalyticsEvent(payload).catch((err) => {
        console.error && console.error("Pixl SQL final summary failed:", err);
      });
    }

    _shouldNotify(reason) {
      if (!this.context) return false;

      const isManual = reason === "TEST" || String(reason || "").startsWith("MANUAL");
      const isAuto = !isManual;
      const debugEnabled = CONFIG.DEBUG && CONFIG.DEBUG.ENABLED;
      const debugForce = debugEnabled && CONFIG.DEBUG.FORCE_NOTIFY;

      // DEBUG.FORCE_NOTIFY soll bewusst alle Session-Limits umgehen.
      // Dadurch bleiben VISIT, READ und LEAVE im Debug testbar, auch wenn
      // MAX_NOTIFICATIONS_PER_SESSION = 1 gesetzt ist.
      if (debugForce) {
        return true;
      }

      if (
        isAuto &&
        CONFIG.SQL.ONE_AUTO_NOTIFICATION_ONLY &&
        this.notificationCount >= 1
      ) {
        return false;
      }

      if (this.notificationCount >= CONFIG.SQL.MAX_NOTIFICATIONS_PER_SESSION) {
        return false;
      }

      if (this._isNotificationCooldownActive()) {
        return false;
      }

      const score = this.readingTracker.getScore();
      const hasData = this.readingTracker.hasEnoughData();

      if (reason === "VISIT") {
        return !!CONFIG.SQL.NOTIFY_ON_VISIT;
      }

      if (reason === "READ") {
        if (!CONFIG.SQL.NOTIFY_ON_READ) return false;
        if (!hasData) return false;
        if (score < CONFIG.SQL.MIN_SCORE_TO_NOTIFY) return false;
        return true;
      }

      if (reason === "LEAVE") {
        if (!CONFIG.SQL.NOTIFY_ON_LEAVE) return false;
        if (score >= CONFIG.SQL.MIN_SCORE_TO_NOTIFY) {
          return true;
        }
      }

      return false;
    }

    _maybeNotify(reason) {
      const isManual = reason === "TEST" || String(reason || "").startsWith("MANUAL");
      if (CONFIG.SQL.FINAL_SUMMARY_ONLY && !isManual) {
        this._recordMilestone(reason);
        return;
      }

      if (!this._shouldNotify(reason)) return;

      this._collectRenderIssues();
      this._notifyAndMarkSession();
      if (reason !== "TEST" && !String(reason || "").startsWith("MANUAL")) {
        this.autoNotificationSent = true;
      }

      const title = this._buildTitle();
      const message = this._buildMessage(reason);
      const payload = this._buildEventPayload(reason, title, message);

      sendAnalyticsEvent(payload).catch((err) => {
        console.error && console.error("Pixl SQL send failed:", err);
      });
    }

    destroy() {
      if (this.readTimeout) {
        clearTimeout(this.readTimeout);
        this.readTimeout = null;
      }
      if (this.readInterval) {
        clearInterval(this.readInterval);
        this.readInterval = null;
      }
      window.removeEventListener("pagehide", this._boundPageHide);
      document.removeEventListener("visibilitychange", this._boundVisibilityChange);
      window.removeEventListener("beforeunload", this._boundBeforeUnload);
      this.readingTracker.uninstall();
      this.renderChecker.stop();
      this.consoleSpy.uninstall();
      this.dialogInterceptor.uninstall();
    }
  }


  // =======================
  // Public Debug API
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
      fingerprint: getFingerprint,
      notify(eventName = "TEST") {
        const detector = new DeviceDetector();
        const fp = getFingerprint();
        const reason = String(eventName || "TEST").toUpperCase();
        const title = `pixl77 MySQL: ${reason}`;
        const message = [
          `${reason}: ${fp.browser} / ${fp.os} / ${fp.device}`,
          `Lang: ${fp.lang}`,
          `Country: ${fp.country}`,
          `Screen: ${fp.screen}${fp.knownResolution ? "" : " (?)"}`,
          `Path: /${detector.getPath()}`,
          `Time: ${new Date().toISOString()}`
        ].join("\n");

        return sendAnalyticsEvent({
          schema: "pixl-sql-v1",
          siteId: CONFIG.SQL_SITE_ID || location.hostname || "default",
          siteKey: CONFIG.SQL_PUBLIC_KEY || "",
          eventId: Utils.makeEventId(),
          sentAt: new Date().toISOString(),
          reason,
          title,
          message,
          script: {
            name: SCRIPT_NAME,
            version: CONFIG.VERSION
          },
          page: {
            hostname: location.hostname || "",
            origin: location.origin || "",
            url: String(location.href || "").split("#")[0],
            path: `/${detector.getPath() || ""}`.replace(/\/{2,}/g, "/"),
            referrer: document.referrer || ""
          },
          context: {
            userAgent: fp.ua,
            browser: fp.browser,
            os: fp.os,
            device: fp.device,
            screen: fp.screen,
            knownResolution: !!fp.knownResolution,
            viewport: Utils.formatScreen(window.innerWidth || 0, window.innerHeight || 0),
            screenCategory: detector.getScreenCategory(),
            language: fp.lang,
            country: fp.country,
            timezone: (
              typeof Intl !== "undefined" &&
              Intl.DateTimeFormat &&
              Intl.DateTimeFormat().resolvedOptions
            )
              ? Intl.DateTimeFormat().resolvedOptions().timeZone || ""
              : ""
          },
          engagement: {
            sessionDuration: 0,
            readingLabel: "MANUAL",
            readingSeconds: null,
            readingScore: 0,
            scoreTrail: "",
            bestReadScore: 0,
            bestReadDuration: 0,
            v3UserScore: readV3UserScore()
          },
          health: {
            renderStatus: "MANUAL",
            renderIssues: [],
            consoleErrorCount: 0,
            consoleErrors: [],
            dialogErrorCount: 0,
            dialogEvents: []
          },
          bot: Utils.analyzeBot(fp.ua),
          events: {
            reached: {},
            seconds: {},
            trail: [reason]
          },
          flags: {
            inFrame: (() => {
              try {
                return window.self !== window.top;
              } catch {
                return true;
              }
            })(),
            webdriver: !!(navigator && navigator.webdriver === true)
          }
        });
      }
    });

    window.PIXL6 = publicApi;
    window.PIXL77 = publicApi;
    window.PIXL5 = publicApi;
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

    if (window.__pixl77Initialized || window.__pixl6Initialized || window.__pixl5Initialized) return;
    window.__pixl77Initialized = true;
    window.__pixl6Initialized = true;
    window.__pixl5Initialized = true;

    // Fingerprint-Schranke:
    // Wenn Browser/OS/Country matchen -> pixl77 läuft normalerweise nicht.
    if (isFingerprintExcluded()) {
      return;
    }

    const instance = new PixlAnalytics();
    const start = () => {
      instance.init().catch((err) => {
        console.error && console.error("Pixl analytics init failed:", err);
      });
    };
    if (
      document.readyState === "complete" ||
      document.readyState === "interactive"
    ) {
      setTimeout(start, 0);
    } else {
      window.addEventListener("DOMContentLoaded", start, { once: true });
    }
  })();
})();
