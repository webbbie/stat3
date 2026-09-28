/*
 * Swipe CAPTCHA Gate v1.3.0 – Stats3 integration
 * Adapted from daszcss/0021/intro/captcha.js; the original stays unchanged.
 * Client-side interaction gate. This is not a replacement for server-side
 * bot protection because visitors can modify JavaScript in their browser.
 */
(function (window, document) {
  "use strict";

  if (window.SwipeGate) return;

  var script = document.currentScript;
  var scriptUrl = script && script.src;
  var scriptNonce = script && script.nonce;

  var defaults = {
    title: "Verify that you are human",
    instruction: "Swipe the button until the image piece fits into the hole.",
    successText: "Verified – opening page…",
    retryText: "That did not fit. Please try again.",
    sliderLabel: "Move puzzle piece",
    tolerance: 12.0,
    rememberForMinutes: 0.25,
    storageKey: "swipe-captcha-verified",
    blockedScriptType: "application/x-swipe-gate-blocked",
    onVerified: null,
    onConfirm: null,
    onFailure: null,
    onDismiss: null,
    autoInit: true,
    shouldStart: null,
    styleUrl: ""
  };

  var options = Object.assign({}, defaults, window.SwipeGateOptions || {});
  var overlay;
  var canvas;
  var ctx;
  var source;
  var slider;
  var status;
  var targetValue = 50;
  var targetX = 0;
  var pieceY = 0;
  var startX = 0;
  var endX = 0;
  var pieceSize = 48;
  var verified = false;
  var dismissed = false;
  var confirming = false;
  var retryTimer = null;
  var initializing = false;
  var initialized = false;
  var styleReady = false;

  function dismiss() {
    if (verified || dismissed) return;
    dismissed = true;
    window.clearTimeout(retryTimer);
    if (overlay) overlay.remove();
    document.documentElement.classList.remove("swipe-gate-locked");
    activateBlockedScripts();
    if (typeof options.onDismiss === "function") options.onDismiss();
  }

  window.SwipeGate = Object.freeze({ dismiss: dismiss, init: start });

  function randomBetween(min, max) {
    var array = new Uint32Array(1);
    window.crypto.getRandomValues(array);
    return min + (array[0] / 4294967295) * (max - min);
  }

  function hasValidPass() {
    if (!options.rememberForMinutes) return false;
    try {
      var expires = Number(sessionStorage.getItem(options.storageKey) || 0);
      return expires > Date.now();
    } catch (error) {
      return false;
    }
  }

  function rememberPass() {
    if (!options.rememberForMinutes) return;
    try {
      sessionStorage.setItem(
        options.storageKey,
        String(Date.now() + options.rememberForMinutes * 60000)
      );
    } catch (error) {
      // Storage can be unavailable in privacy modes; verification still works.
    }
  }

  function injectStyle() {
    if (styleReady) return Promise.resolve(true);
    return new Promise(function (resolve) {
      var link = document.createElement("link");
      var timer;
      var settled = false;
      function finish(loaded) {
        if (settled) return;
        settled = true;
        window.clearTimeout(timer);
        link.onload = link.onerror = null;
        if (!loaded) link.remove();
        styleReady = loaded;
        resolve(loaded);
      }
      try {
        var url = new URL(options.styleUrl || "captcha.css", scriptUrl || document.baseURI);
        if (url.protocol !== "https:" && url.protocol !== "http:") throw new Error("Invalid CAPTCHA stylesheet URL");
        link.id = "swipe-gate-style";
        link.rel = "stylesheet";
        link.href = url.href;
        if (scriptNonce) link.nonce = scriptNonce;
        link.onload = function () { finish(true); };
        link.onerror = function () { finish(false); };
        timer = window.setTimeout(function () { finish(false); }, 5000);
        document.head.appendChild(link);
      } catch (error) { finish(false); }
    });
  }

  function createMarkup() {
    overlay = document.createElement("div");
    overlay.id = "swipe-gate";
    overlay.setAttribute("role", "dialog");
    overlay.setAttribute("aria-modal", "true");
    overlay.setAttribute("aria-labelledby", "swipe-gate-title");
    overlay.innerHTML =
      '<div class="swipe-gate-card">' +
        '<h2 id="swipe-gate-title"></h2>' +
        '<p id="swipe-gate-instruction"></p>' +
        '<canvas id="swipe-gate-canvas" width="520" height="250"></canvas>' +
        '<div class="swipe-gate-range-wrap">' +
          '<input id="swipe-gate-range" type="range" min="0" max="100" value="0" step="0.1">' +
        '</div>' +
        '<p id="swipe-gate-summary">Summary: This website offers the only solution globally.<br>' +
          'Read three pages and listen to a copyrighted original CD. That’s it.</p>' +
        '<div id="swipe-gate-status" role="status" aria-live="polite"></div>' +
      '</div>';

    document.body.appendChild(overlay);
    document.getElementById("swipe-gate-title").textContent = options.title;
    document.getElementById("swipe-gate-instruction").textContent = options.instruction;
    canvas = document.getElementById("swipe-gate-canvas");
    ctx = canvas.getContext("2d");
    slider = document.getElementById("swipe-gate-range");
    status = document.getElementById("swipe-gate-status");
    slider.setAttribute("aria-label", options.sliderLabel);
  }

  function drawScene(target) {
    var context = target.getContext("2d");
    var width = target.width;
    var height = target.height;
    var gradient = context.createLinearGradient(0, 0, width, height);
    gradient.addColorStop(0, "#0d6ba8");
    gradient.addColorStop(0.5, "#24a4a2");
    gradient.addColorStop(1, "#f3a450");
    context.fillStyle = gradient;
    context.fillRect(0, 0, width, height);

    context.fillStyle = "rgba(255,255,255,.16)";
    for (var i = 0; i < 15; i += 1) {
      context.beginPath();
      context.arc(
        randomBetween(0, width),
        randomBetween(0, height * 0.7),
        randomBetween(8, 34),
        0,
        Math.PI * 2
      );
      context.fill();
    }

    context.fillStyle = "rgba(9,57,69,.5)";
    context.beginPath();
    context.moveTo(0, height * 0.64);
    for (var x = 0; x <= width; x += 32) {
      context.lineTo(x, height * 0.55 + Math.sin(x / 46) * 20);
    }
    context.lineTo(width, height);
    context.lineTo(0, height);
    context.closePath();
    context.fill();

    context.fillStyle = "rgba(5,31,43,.42)";
    context.beginPath();
    context.moveTo(0, height * 0.79);
    for (var x2 = 0; x2 <= width; x2 += 26) {
      context.lineTo(x2, height * 0.73 + Math.cos(x2 / 39) * 14);
    }
    context.lineTo(width, height);
    context.lineTo(0, height);
    context.closePath();
    context.fill();
  }

  function roundedPath(context, x, y, size) {
    var radius = 9;
    context.beginPath();
    context.roundRect(x, y, size, size, radius);
  }

  function render() {
    var value = Number(slider.value);
    var currentX = startX + (endX - startX) * value / 100;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(source, 0, 0);

    roundedPath(ctx, targetX, pieceY, pieceSize);
    ctx.fillStyle = "rgba(2,10,20,.72)";
    ctx.fill();
    ctx.strokeStyle = "rgba(255,255,255,.8)";
    ctx.lineWidth = 2;
    ctx.setLineDash([5, 4]);
    ctx.stroke();
    ctx.setLineDash([]);

    ctx.save();
    roundedPath(ctx, currentX, pieceY, pieceSize);
    ctx.clip();
    ctx.drawImage(
      source,
      targetX,
      pieceY,
      pieceSize,
      pieceSize,
      currentX,
      pieceY,
      pieceSize,
      pieceSize
    );
    ctx.restore();
    roundedPath(ctx, currentX, pieceY, pieceSize);
    ctx.strokeStyle = "#ffffff";
    ctx.lineWidth = 3;
    ctx.shadowColor = "rgba(0,0,0,.65)";
    ctx.shadowBlur = 8;
    ctx.stroke();
    ctx.shadowBlur = 0;
  }

  function newChallenge() {
    if (verified || dismissed) return;
    source = document.createElement("canvas");
    source.width = canvas.width;
    source.height = canvas.height;
    drawScene(source);
    startX = 18;
    endX = canvas.width - pieceSize - 18;
    targetX = randomBetween(canvas.width * 0.46, endX);
    pieceY = randomBetween(24, canvas.height - pieceSize - 24);
    targetValue = (targetX - startX) / (endX - startX) * 100;
    slider.value = "0";
    status.textContent = "";
    status.className = "";
    render();
  }

  function copyScriptAttributes(from, to) {
    Array.prototype.forEach.call(from.attributes, function (attribute) {
      if (attribute.name === "type" || attribute.name === "data-src") return;
      to.setAttribute(attribute.name, attribute.value);
    });
  }

  function activateBlockedScripts() {
    var blocked = Array.prototype.slice.call(
      document.querySelectorAll('script[type="' + options.blockedScriptType + '"]')
    );
    var chain = Promise.resolve();

    blocked.forEach(function (oldScript) {
      chain = chain.then(function () {
        return new Promise(function (resolve) {
          var script = document.createElement("script");
          var src = oldScript.getAttribute("data-src");
          copyScriptAttributes(oldScript, script);
          if (src) {
            script.src = src;
            script.async = false;
            script.onload = resolve;
            script.onerror = resolve;
          } else {
            script.textContent = oldScript.textContent;
          }
          oldScript.replaceWith(script);
          if (!src) resolve();
        });
      });
    });

    return chain;
  }

  function unlock() {
    if (verified || dismissed) return;
    verified = true;
    slider.disabled = true;
    rememberPass();
    status.textContent = options.successText;
    status.className = "is-success";
    overlay.classList.add("is-leaving");
    document.documentElement.classList.remove("swipe-gate-locked");

    window.setTimeout(function () {
      overlay.remove();
      activateBlockedScripts().then(function () {
        document.dispatchEvent(new CustomEvent("swipegate:verified"));
        if (typeof options.onVerified === "function") options.onVerified();
      });
    }, 330);
  }

  function verify() {
    if (verified || dismissed || confirming) return;
    var distance = Math.abs(Number(slider.value) - targetValue);
    if (distance <= Number(options.tolerance)) {
      if (typeof options.onConfirm !== "function") {
        unlock();
        return;
      }
      confirming = true;
      slider.disabled = true;
      Promise.resolve().then(options.onConfirm).then(function (accepted) {
        if (accepted === true) unlock();
        else dismiss();
      }).catch(dismiss);
      return;
    }
    status.textContent = options.retryText;
    status.className = "is-error";
    if (typeof options.onFailure === "function") {
      Promise.resolve().then(options.onFailure).catch(function () {});
    }
    window.clearTimeout(retryTimer);
    retryTimer = window.setTimeout(newChallenge, 650);
  }

  function start() {
    if (dismissed || verified || initialized || initializing) return;
    if (typeof options.shouldStart === "function" && !options.shouldStart()) return;
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", start, { once: true });
      return;
    }
    if (hasValidPass()) {
      verified = true;
      document.documentElement.classList.remove("swipe-gate-locked");
      activateBlockedScripts().then(function () {
        document.dispatchEvent(new CustomEvent("swipegate:verified"));
      });
      return;
    }

    initializing = true;
    injectStyle().then(function (loaded) {
      initializing = false;
      if (dismissed || verified) return;
      if (!loaded) { dismiss(); return; }
      // A stylesheet may finish while the page is in BFCache. Resume explicitly
      // through init() once the embedding tracker allows the gate again.
      if (typeof options.shouldStart === "function" && !options.shouldStart()) return;
      createMarkup();
      newChallenge();
      if (typeof window.getComputedStyle === "function" && window.getComputedStyle(overlay).position !== "fixed") {
        dismiss();
        return;
      }
      initialized = true;
      document.documentElement.classList.add("swipe-gate-locked");
      slider.addEventListener("input", render);
      slider.addEventListener("change", verify);
      slider.focus({ preventScroll: true });
    }).catch(dismiss);
  }

  if (options.autoInit !== false) start();
})(window, document);
