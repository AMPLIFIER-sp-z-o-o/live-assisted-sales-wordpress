/**
 * AMPER Live Assisted Sales - browser tracker for WooCommerce storefronts.
 *
 * Port of the AMPER b2c reference tracker: anonymous identity (visitor/session),
 * batched telemetry to the same-origin proxy, GDPR consent banner (EU opt-in,
 * opt-out elsewhere, GPC honoured), select_item on product tiles, scroll-depth
 * milestones and engaged-time pings. Business/money events are emitted by the
 * server - never from here.
 */
(function () {
  "use strict";

  var config = window.amperLasConfig || {};
  var endpoint = String(config.endpoint || "");
  if (!endpoint) return;
  // Server-decided consent regime from the visitor's IP/country: "eu" | "noneu" | "" (unknown).
  var consentRegion = String(config.consentRegion || "");
  var sessionEnded = false;

  var consentTexts = Object.assign(
    {
      aria_label: "Cookie and data-processing consent",
      title: "We value your privacy",
      body: "This store uses cookies and data about your activity to work properly, help you in real time, and recommend products more accurately.",
      accept_all: "Accept all",
      only_necessary: "Only necessary",
      preferences: "Preferences",
      prefs_title: "Privacy preferences",
      prefs_intro: "Choose what you're comfortable with. You can change this at any time.",
      close: "Close",
      necessary_title: "Strictly necessary",
      necessary_state: "Always on",
      necessary_desc: "Required for the store to work and stay secure. Doesn't build a profile or identify you.",
      analytics_title: "Analytics & personalization",
      analytics_desc: "Lets the store understand your visit so it can help you in real time and recommend products more accurately.",
      save: "Save choices",
      cancel: "Cancel",
    },
    config.consentTexts || {}
  );

  // ---- Identity ---------------------------------------------------------
  function uuid() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      return window.crypto.randomUUID();
    }
    // visitor_id is a bearer-ish identifier (it scopes anonymous chat history), so even the
    // fallback must be cryptographically random - never Math.random()/Date.now().
    if (window.crypto && typeof window.crypto.getRandomValues === "function") {
      var bytes = new Uint8Array(16);
      window.crypto.getRandomValues(bytes);
      var hex = "";
      for (var i = 0; i < bytes.length; i++) hex += ("0" + bytes[i].toString(16)).slice(-2);
      return "las-" + hex;
    }
    return "las-" + Date.now() + "-" + Math.random().toString(16).slice(2);
  }

  function storageGet(storage, key) {
    try { return storage.getItem(key); } catch (error) { return ""; }
  }
  function storageSet(storage, key, value) {
    try { storage.setItem(key, value); } catch (error) {}
  }
  function writeCookie(name, value) {
    document.cookie = name + "=" + encodeURIComponent(value) + "; path=/; SameSite=Lax";
  }

  function visitorId() {
    var key = "las_visitor_id";
    var value = storageGet(localStorage, key);
    if (!value) { value = uuid(); storageSet(localStorage, key, value); }
    writeCookie(key, value);
    return value;
  }
  function sessionId() {
    var key = "las_session_id";
    var value = storageGet(sessionStorage, key);
    if (!value) { value = uuid(); storageSet(sessionStorage, key, value); }
    writeCookie(key, value);
    return value;
  }

  function publishWidgetCustomer() {
    var customer = config.customer;
    window.LAS_CUSTOMER = customer && typeof customer === "object" && customer.id ? customer : {};
  }

  // ---- Batched delivery -------------------------------------------------
  // Telemetry is bursty, so events are queued and flushed together to the same-origin
  // proxy. fetch(keepalive)/sendBeacon make the unload flush survive navigation. A failed
  // beacon simply drops the batch - the server is the source of truth for money events.
  var FLUSH_INTERVAL_MS = 5000;
  var MAX_BATCH = 20;
  var queue = [];

  function consentValue() {
    var v = storageGet(localStorage, "las_consent");
    if (v === "true") return true;
    if (v === "false") return false;
    return undefined;
  }

  // Prior-consent gate (GDPR/ePrivacy): in the EU/EEA/UK NOTHING may be collected or sent
  // before the shopper explicitly accepts; elsewhere it is opt-out. GPC is always honoured.
  function trackingAllowed() {
    if (navigator.globalPrivacyControl === true) return false;
    var consent = consentValue();
    if (isEuRegion()) return consent === true;
    return consent !== false;
  }

  function envelope(event) {
    var base = {
      event_id: uuid(),
      visitor_id: visitorId(),
      session_id: sessionId(),
      occurred_at: new Date().toISOString(),
      url: window.location.href,
    };
    var merged = Object.assign(base, event);
    var consent = consentValue();
    if (consent !== undefined) {
      merged.metadata = Object.assign({}, merged.metadata || {}, { consent: consent });
    }
    return merged;
  }

  function postBatch(events, useBeacon) {
    if (!events.length) return;
    var body = JSON.stringify({ events: events });
    try {
      if (useBeacon && navigator.sendBeacon) {
        navigator.sendBeacon(endpoint, new Blob([body], { type: "application/json" }));
        return;
      }
      fetch(endpoint, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: body,
        keepalive: true,
      }).catch(function () {});
    } catch (error) {}
  }

  function flush(useBeacon) {
    if (!queue.length) return;
    var batch = queue.splice(0, MAX_BATCH);
    postBatch(batch, useBeacon);
    if (queue.length) flush(useBeacon);
  }

  function enqueue(event) {
    if (!trackingAllowed()) return; // EU: drop everything until consent is granted
    queue.push(envelope(event));
    if (queue.length >= MAX_BATCH) flush(false);
  }

  // Immediate, on its own request - used for session_start so the agent console sees the
  // arrival without waiting for the flush timer.
  function sendNow(event) {
    if (!trackingAllowed()) return;
    postBatch([envelope(event)], false);
  }

  // ---- Auto-captured signals -------------------------------------------
  function pageContext() {
    return { title: document.title || "", path: window.location.pathname };
  }

  // GA4 select_item: the shopper clicked a product tile in a list. Supports both the
  // generic [data-product-card] contract and native WooCommerce loop tiles (li.product
  // with a post-<id> class). Throttled against double-send from nested targets.
  var lastClickAt = 0;
  var lastSelectId = "";

  function wooTileProduct(target) {
    var tile = target && target.closest ? target.closest("li.product, [data-product-card]") : null;
    if (!tile) return null;
    var productId = "";
    var name = "";
    if (tile.hasAttribute && tile.hasAttribute("data-product-card")) {
      productId = tile.getAttribute("data-product-id") || "";
      name = tile.getAttribute("data-product-name") || "";
    } else {
      var m = (tile.className || "").match(/(?:^|\s)post-(\d+)(?:\s|$)/);
      if (m) productId = m[1];
      if (!productId) {
        var btn = tile.querySelector("[data-product_id]");
        if (btn) productId = btn.getAttribute("data-product_id") || "";
      }
      var titleEl = tile.querySelector(".woocommerce-loop-product__title, h2, h3");
      if (titleEl) name = (titleEl.textContent || "").trim();
    }
    if (!productId) return null;
    return { id: productId, name: name };
  }

  function onClick(evt) {
    // Ignore add-to-cart buttons - the server emits the authoritative add_to_cart event.
    if (evt.target && evt.target.closest && evt.target.closest(".add_to_cart_button, button[name=add-to-cart]")) return;
    var product = wooTileProduct(evt.target);
    if (!product) return;
    var now = Date.now();
    if (now - lastClickAt < 400 && product.id === lastSelectId) return;
    lastClickAt = now;
    lastSelectId = product.id;
    var payload = {
      event_type: "select_item",
      page: pageContext(),
      product: { id: product.id, name: product.name },
    };
    var list = config.listContext;
    if (list && typeof list === "object" && (list.name || list.id)) {
      payload.category = { id: String(list.id || ""), name: String(list.name || ""), slug: String(list.slug || "") };
    }
    enqueue(payload);
  }

  // Scroll depth milestones (25/50/75/90), each fired at most once per page.
  var scrollMilestones = [25, 50, 75, 90];
  var scrollSent = {};
  var maxScrollPct = 0;
  function currentScrollPct() {
    var doc = document.documentElement;
    var scrollable = (doc.scrollHeight || 0) - (doc.clientHeight || 0);
    if (scrollable <= 0) return 100;
    return Math.min(100, Math.round(((window.scrollY || doc.scrollTop || 0) / scrollable) * 100));
  }
  var scrollScheduled = false;
  function onScroll() {
    if (scrollScheduled) return;
    scrollScheduled = true;
    window.requestAnimationFrame(function () {
      scrollScheduled = false;
      var pct = currentScrollPct();
      if (pct > maxScrollPct) maxScrollPct = pct;
      for (var i = 0; i < scrollMilestones.length; i++) {
        var m = scrollMilestones[i];
        if (pct >= m && !scrollSent[m]) {
          scrollSent[m] = true;
          enqueue({ event_type: "scroll_depth", page: pageContext(), metadata: { pct: m } });
        }
      }
    });
  }

  // Engaged-time heartbeat: a page_ping every PING_INTERVAL_MS, but only while the tab is
  // visible AND the user did something in the interval.
  var PING_INTERVAL_MS = 15000;
  var activeSinceLastPing = false;
  var engagedMs = 0;
  function markActive() { activeSinceLastPing = true; }
  function heartbeat() {
    if (document.visibilityState !== "visible" || !activeSinceLastPing) return;
    engagedMs += PING_INTERVAL_MS;
    activeSinceLastPing = false;
    enqueue({
      event_type: "page_ping",
      page: pageContext(),
      metadata: { engaged_ms: engagedMs, max_scroll_pct: maxScrollPct },
    });
  }

  // ---- Lifecycle --------------------------------------------------------
  function sendInitialEvents() {
    visitorId();
    sessionId();
    publishWidgetCustomer();
    var cart = config.initialCart && typeof config.initialCart === "object" ? config.initialCart : undefined;
    sendNow({ event_type: "session_start", page: pageContext(), cart: cart });
  }
  function sendSessionEnd() {
    if (sessionEnded) return;
    sessionEnded = true;
    enqueue({
      event_type: "session_end",
      page: pageContext(),
      metadata: { engaged_time_total: engagedMs, max_scroll_depth: maxScrollPct },
    });
    flush(true);
  }

  publishWidgetCustomer();

  document.addEventListener("click", onClick, true);
  window.addEventListener("scroll", onScroll, { passive: true });
  ["mousemove", "keydown", "scroll", "click", "touchstart"].forEach(function (type) {
    window.addEventListener(type, markActive, { passive: true });
  });
  setInterval(heartbeat, PING_INTERVAL_MS);
  setInterval(function () { flush(false); }, FLUSH_INTERVAL_MS);
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "hidden") flush(true);
  });
  window.addEventListener("pagehide", sendSessionEnd);
  // Back/forward cache: the browser restores the page WITHOUT re-running scripts, so the
  // sessionEnded latch would swallow the next real departure and the shopper's return
  // would go unnoticed until the first heartbeat. A persisted pageshow re-arms the latch
  // and announces the return immediately.
  window.addEventListener("pageshow", function (event) {
    if (!event.persisted) return;
    sessionEnded = false;
    sendNow({ event_type: "session_start", page: pageContext() });
  });

  function persistConsent(value) {
    storageSet(localStorage, "las_consent", value ? "true" : "false");
    writeCookie("las_consent", value ? "true" : "false");
  }
  // Whether this visitor needs a prior-consent banner. The server decides from the
  // IP/country and passes "eu"/"noneu"; only when that is unknown do we fall back to a
  // rough timezone heuristic in the browser.
  function isEuRegion() {
    if (consentRegion === "eu") return true;
    if (consentRegion === "noneu") return false;
    try {
      var tz = (Intl.DateTimeFormat().resolvedOptions().timeZone || "");
      return /^(Europe\/|Atlantic\/(Azores|Madeira|Canary|Reykjavik|Faroe)|Arctic\/Longyearbyen)/.test(tz);
    } catch (e) { return false; }
  }

  // Scoped styles for the consent UI. Every selector is prefixed `lascc-` so it can't
  // collide with the host theme's CSS.
  function injectConsentStyles() {
    if (document.getElementById("las-consent-style")) return;
    var style = document.createElement("style");
    style.id = "las-consent-style";
    style.textContent = [
      ".lascc-card{position:fixed;left:16px;bottom:16px;z-index:2147483000;width:380px;max-width:calc(100vw - 32px);background:#0f172a;color:#e5e7eb;border:1px solid rgba(148,163,184,.18);border-radius:16px;padding:18px;box-shadow:0 18px 48px rgba(2,6,23,.55);font:14px/1.55 system-ui,-apple-system,'Segoe UI',Roboto,sans-serif}",
      ".lascc-card *,.lascc-modal *{box-sizing:border-box}",
      ".lascc-title{margin:0 0 6px;font-size:15px;font-weight:700;color:#f8fafc;letter-spacing:-.01em}",
      ".lascc-body{margin:0 0 14px;color:#cbd5e1}",
      ".lascc-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}",
      // Buttons carry !important on every themable property: WP themes style `button`
      // element selectors (and `button:hover`, specificity 0,1,1) which beat our
      // single-class base rules (0,1,0) exactly on hover - dark theme text on our dark
      // card made labels unreadable. All selectors are lascc-scoped, so this is safe.
      ".lascc-btn{appearance:none;-webkit-appearance:none;border:0!important;border-radius:10px!important;padding:9px 14px!important;margin:0!important;font:inherit!important;font-weight:600!important;cursor:pointer!important;line-height:1!important;min-height:0!important;min-width:0!important;display:inline-flex;align-items:center;gap:7px;text-transform:none!important;text-decoration:none!important;letter-spacing:normal!important;box-shadow:none!important;transition:background .15s,border-color .15s,color .15s,transform .05s}",
      ".lascc-btn:active{transform:translateY(1px)}",
      ".lascc-btn:focus-visible{outline:2px solid #60a5fa!important;outline-offset:2px!important}",
      ".lascc-btn--primary{background:#2563eb!important;color:#fff!important}",
      ".lascc-btn--primary:hover{background:#1d4ed8!important;color:#fff!important}",
      ".lascc-btn--ghost{background:rgba(148,163,184,.12)!important;color:#e5e7eb!important;border:1px solid rgba(148,163,184,.22)!important}",
      ".lascc-btn--ghost:hover{background:rgba(148,163,184,.2)!important;color:#e5e7eb!important;border-color:rgba(148,163,184,.32)!important}",
      ".lascc-btn--link{background:transparent!important;color:#93c5fd!important;padding:9px 8px!important}",
      ".lascc-btn--link:hover{background:transparent!important;color:#bfdbfe!important;text-decoration:underline!important}",
      ".lascc-check-ic{width:15px;height:15px;flex:0 0 auto}",
      ".lascc-overlay{position:fixed;inset:0;z-index:2147483001;background:rgba(2,6,23,.6);display:flex;align-items:center;justify-content:center;padding:16px}",
      ".lascc-modal{width:460px;max-width:100%;max-height:calc(100vh - 32px);overflow:auto;background:#0f172a;color:#e5e7eb;border:1px solid rgba(148,163,184,.18);border-radius:18px;padding:22px;box-shadow:0 24px 64px rgba(2,6,23,.6);font:14px/1.55 system-ui,-apple-system,'Segoe UI',Roboto,sans-serif}",
      ".lascc-modal:focus{outline:none}",
      ".lascc-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:6px}",
      ".lascc-modal-title{margin:0;font-size:18px;font-weight:800;color:#f8fafc;letter-spacing:-.02em}",
      ".lascc-close{appearance:none;-webkit-appearance:none;border:0!important;background:transparent!important;color:#94a3b8!important;font-size:22px!important;line-height:1!important;cursor:pointer!important;width:32px!important;height:32px!important;min-height:0!important;min-width:0!important;padding:0!important;box-shadow:none!important;display:inline-flex;align-items:center;justify-content:center;border-radius:8px!important;flex:0 0 auto;margin:-4px -6px 0 0!important}",
      ".lascc-close:hover{color:#e5e7eb!important;background:rgba(148,163,184,.14)!important}",
      ".lascc-intro{margin:0 0 4px;color:#cbd5e1}",
      ".lascc-row{display:flex;gap:12px;padding:14px 0;margin:0;border-top:1px solid rgba(148,163,184,.14);cursor:pointer}",
      ".lascc-row--locked{cursor:default}",
      ".lascc-row input[type=checkbox]{margin:2px 0 0;width:18px;height:18px;accent-color:#2563eb;cursor:pointer;flex:0 0 auto}",
      ".lascc-row input[disabled]{cursor:default;opacity:.7}",
      ".lascc-row-main{flex:1;min-width:0}",
      ".lascc-row-title{display:flex;align-items:center;gap:8px;font-weight:700;color:#f1f5f9}",
      ".lascc-badge{font-size:11px;font-weight:600;color:#93c5fd;background:rgba(37,99,235,.18);border:1px solid rgba(59,130,246,.3);padding:1px 8px;border-radius:999px}",
      ".lascc-row-desc{margin:4px 0 0;color:#94a3b8;font-size:13px}",
      ".lascc-modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:20px}",
      "@media (max-width:480px){.lascc-card{left:8px;right:8px;bottom:8px;width:auto}}",
    ].join("");
    (document.head || document.documentElement).appendChild(style);
  }

  // Static, no-user-data markup - safe to inject via innerHTML.
  var CHECK_SVG = '<svg class="lascc-check-ic" viewBox="0 0 20 20" aria-hidden="true" fill="none"><path d="M4 10.5l4 4 8-9" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

  function makeConsentButton(label, variant, withCheck) {
    var b = document.createElement("button");
    b.type = "button";
    b.className = "lascc-btn " + variant;
    if (withCheck) {
      var ic = document.createElement("span");
      ic.style.display = "inline-flex";
      ic.innerHTML = CHECK_SVG; // static markup only - never interpolates user data
      b.appendChild(ic);
    }
    b.appendChild(document.createTextNode(label));
    return b;
  }

  // Persist a consent choice and reconcile the tracker with it: dismiss the banner,
  // backfill the suppressed session_start on a NEW grant, drop buffered events on
  // withdrawal. Shared by the banner buttons AND the reopen link.
  function applyConsentChoice(value) {
    var previous = consentValue();
    persistConsent(value);
    var card = document.querySelector(".lascc-card");
    if (card && card.parentNode) card.parentNode.removeChild(card);
    if (value && previous !== true) sendInitialEvents(); // transition into granted -> backfill this visit
    if (!value) queue = []; // withdrawal -> stop sending buffered telemetry
  }

  // Per-purpose preferences modal: strictly-necessary locked on; one Analytics &
  // personalization toggle maps to the single real behavioural bucket.
  function openPrivacyPreferences(opts) {
    injectConsentStyles();
    var initialGranted = !!(opts && opts.initialGranted);
    var opener = document.activeElement;

    var overlay = document.createElement("div");
    overlay.className = "lascc-overlay";
    var modal = document.createElement("div");
    modal.className = "lascc-modal";
    modal.setAttribute("role", "dialog");
    modal.setAttribute("aria-modal", "true");
    modal.setAttribute("aria-label", consentTexts.prefs_title);
    modal.tabIndex = -1;

    var head = document.createElement("div");
    head.className = "lascc-modal-head";
    var heading = document.createElement("h2");
    heading.className = "lascc-modal-title";
    heading.textContent = consentTexts.prefs_title;
    var closeBtn = document.createElement("button");
    closeBtn.type = "button";
    closeBtn.className = "lascc-close";
    closeBtn.setAttribute("aria-label", consentTexts.close);
    closeBtn.innerHTML = "&times;";
    head.appendChild(heading);
    head.appendChild(closeBtn);
    var intro = document.createElement("p");
    intro.className = "lascc-intro";
    intro.textContent = consentTexts.prefs_intro;

    function makeRow(rowTitle, rowDesc, rowOpts) {
      var row = document.createElement("label");
      row.className = "lascc-row" + (rowOpts.locked ? " lascc-row--locked" : "");
      var cb = document.createElement("input");
      cb.type = "checkbox";
      cb.checked = !!rowOpts.checked;
      if (rowOpts.locked) cb.disabled = true;
      var main = document.createElement("div");
      main.className = "lascc-row-main";
      var rt = document.createElement("div");
      rt.className = "lascc-row-title";
      rt.appendChild(document.createTextNode(rowTitle));
      if (rowOpts.badge) {
        var badge = document.createElement("span");
        badge.className = "lascc-badge";
        badge.textContent = rowOpts.badge;
        rt.appendChild(badge);
      }
      var rd = document.createElement("p");
      rd.className = "lascc-row-desc";
      rd.textContent = rowDesc;
      main.appendChild(rt);
      main.appendChild(rd);
      row.appendChild(cb);
      row.appendChild(main);
      return { row: row, input: cb };
    }

    var necessary = makeRow(consentTexts.necessary_title, consentTexts.necessary_desc, { checked: true, locked: true, badge: consentTexts.necessary_state });
    var analytics = makeRow(consentTexts.analytics_title, consentTexts.analytics_desc, { checked: initialGranted, locked: false });

    var modalActions = document.createElement("div");
    modalActions.className = "lascc-modal-actions";
    var cancelBtn = makeConsentButton(consentTexts.cancel, "lascc-btn--ghost", false);
    var saveBtn = makeConsentButton(consentTexts.save, "lascc-btn--primary", false);
    modalActions.appendChild(cancelBtn);
    modalActions.appendChild(saveBtn);

    modal.appendChild(head);
    modal.appendChild(intro);
    modal.appendChild(necessary.row);
    modal.appendChild(analytics.row);
    modal.appendChild(modalActions);
    overlay.appendChild(modal);

    function closeOverlay() {
      document.removeEventListener("keydown", onKey);
      if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
      if (opener && typeof opener.focus === "function" && document.contains(opener)) opener.focus();
    }
    function focusables() { return modal.querySelectorAll("button, input:not([disabled])"); }
    function onKey(e) {
      if (e.key === "Escape") { e.preventDefault(); closeOverlay(); return; }
      if (e.key !== "Tab") return;
      // Minimal focus trap so keyboard users can't tab out of the open dialog.
      var f = focusables();
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
    closeBtn.addEventListener("click", closeOverlay);
    cancelBtn.addEventListener("click", closeOverlay);
    overlay.addEventListener("click", function (e) { if (e.target === overlay) closeOverlay(); });
    saveBtn.addEventListener("click", function () {
      var granted = analytics.input.checked;
      closeOverlay();
      applyConsentChoice(granted);
    });
    document.addEventListener("keydown", onKey);
    (document.body || document.documentElement).appendChild(overlay);
    modal.focus();
  }

  // First-visit banner (EU/EEA/UK only; non-EU/GPC paths persist silently with no card).
  // "Accept all" is the visual primary, "Only necessary" the SAME-layer one-click decline
  // (GDPR requires reject as easy as accept), "Preferences" opens the modal.
  function setupConsentBanner() {
    if (consentValue() !== undefined) return; // already answered
    if (navigator.globalPrivacyControl === true) { persistConsent(false); return; } // honour GPC, no banner
    if (!isEuRegion()) { persistConsent(true); return; } // opt-out market: consent by default, no banner
    injectConsentStyles();

    var card = document.createElement("div");
    card.className = "lascc-card";
    card.setAttribute("role", "dialog");
    card.setAttribute("aria-label", consentTexts.aria_label);
    var title = document.createElement("p");
    title.className = "lascc-title";
    title.textContent = consentTexts.title;
    var body = document.createElement("p");
    body.className = "lascc-body";
    body.textContent = consentTexts.body;
    var actions = document.createElement("div");
    actions.className = "lascc-actions";
    card.appendChild(title);
    card.appendChild(body);
    card.appendChild(actions);

    var acceptAll = makeConsentButton(consentTexts.accept_all, "lascc-btn--primary", true);
    acceptAll.addEventListener("click", function () { applyConsentChoice(true); });
    var onlyNecessary = makeConsentButton(consentTexts.only_necessary, "lascc-btn--ghost", false);
    onlyNecessary.addEventListener("click", function () { applyConsentChoice(false); });
    var prefs = makeConsentButton(consentTexts.preferences, "lascc-btn--link", false);
    prefs.addEventListener("click", function () { openPrivacyPreferences({ initialGranted: consentValue() !== false }); });
    actions.appendChild(acceptAll);
    actions.appendChild(onlyNecessary);
    actions.appendChild(prefs);

    (document.body || document.documentElement).appendChild(card);
  }

  // Reopen path - makes an accidental "Only necessary" recoverable and satisfies the GDPR
  // right to withdraw/change consent. Any store control with [data-las-privacy] (e.g. a
  // footer "Privacy settings" link), or window.LAS_openPrivacySettings(), reopens the modal.
  window.LAS_openPrivacySettings = function () {
    openPrivacyPreferences({ initialGranted: consentValue() !== false });
  };
  document.addEventListener("click", function (e) {
    var trigger = e.target && e.target.closest ? e.target.closest("[data-las-privacy]") : null;
    if (!trigger) return;
    e.preventDefault();
    window.LAS_openPrivacySettings();
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () { sendInitialEvents(); setupConsentBanner(); }, { once: true });
  } else {
    sendInitialEvents();
    setupConsentBanner();
  }
})();
