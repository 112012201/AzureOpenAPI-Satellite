/* ==========================================================================
   AzureOpenAPI for Satellite — shared front-end behaviour
   Plain ES5-compatible vanilla JS. No dependencies, no external requests.
   Everything lives on a single global: window.SatApi
   ========================================================================== */

(function (window, document) {
  'use strict';

  var THEME_KEY = 'satapi-theme';
  var STATUS_INTERVAL_MS = 60000;

  /* ------------------------------------------------------------------ utils */

  function toArray(nodeList) {
    return Array.prototype.slice.call(nodeList || []);
  }

  function all(selector, root) {
    return toArray((root || document).querySelectorAll(selector));
  }

  function attr(el, name) {
    return el && el.getAttribute ? el.getAttribute(name) : null;
  }

  /* One string from the shell's runtime-copy table, with a literal fallback so a
     page that carries no boot payload (the generated 404, for instance) still says
     something rather than printing "undefined". */
  function ui(key, fallback) {
    var boot = window.__SATAPI_BOOT__;
    var table = boot && boot.ui;
    return (table && typeof table[key] === 'string' && table[key]) ? table[key] : fallback;
  }

  /* Element.closest is not available everywhere, so walk up by hand. */
  function closest(el, selector) {
    var node = el;
    while (node && node.nodeType === 1) {
      if (matches(node, selector)) { return node; }
      node = node.parentNode;
    }
    return null;
  }

  function matches(el, selector) {
    var fn = el.matches || el.msMatchesSelector || el.webkitMatchesSelector;
    return fn ? fn.call(el, selector) : false;
  }

  /* Every localStorage touch is guarded: private mode and disabled storage
     both throw, and neither should break the page. */
  function readStore(key) {
    try {
      return window.localStorage ? window.localStorage.getItem(key) : null;
    } catch (err) {
      return null;
    }
  }

  function writeStore(key, value) {
    try {
      if (window.localStorage) { window.localStorage.setItem(key, value); }
    } catch (err) {
      /* storage unavailable — the theme still applies for this page view */
    }
  }

  function setText(el, text) {
    if (!el) { return; }
    el.textContent = text;
  }

  /* ------------------------------------------------------------ theme ---- */

  var theme = (function () {
    var root = document.documentElement;

    function systemTheme() {
      if (window.matchMedia) {
        try {
          return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        } catch (err) { /* fall through */ }
      }
      return 'light';
    }

    function storedTheme() {
      var value = readStore(THEME_KEY);
      return (value === 'light' || value === 'dark') ? value : null;
    }

    function current() {
      var explicit = attr(root, 'data-theme');
      if (explicit === 'light' || explicit === 'dark') { return explicit; }
      return storedTheme() || systemTheme();
    }

    function paint(mode) {
      all('[data-theme-toggle]').forEach(function (button) {
        /* The label is copy, so it comes from the string table the shell handed over
           in __SATAPI_BOOT__.ui rather than from a literal here. */
        var label = mode === 'dark' ? ui('theme.light', 'Switch to light theme') : ui('theme.dark', 'Switch to dark theme');
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
        button.setAttribute('aria-pressed', mode === 'dark' ? 'true' : 'false');
        setText(button.querySelector('[data-theme-label]'), label);
      });
    }

    function apply(mode, persist) {
      if (mode !== 'light' && mode !== 'dark') { return; }
      if (persist) { writeStore(THEME_KEY, mode); }
      root.setAttribute('data-theme', mode);
      try { root.style.colorScheme = mode; } catch (err) { /* older engines */ }
      paint(mode);
    }

    function toggle() {
      apply(current() === 'dark' ? 'light' : 'dark', true);
    }

    function init() {
      var buttons = all('[data-theme-toggle]');
      buttons.forEach(function (button) {
        button.addEventListener('click', toggle);
      });

      /* No explicit choice yet: honour live OS changes. */
      if (window.matchMedia) {
        try {
          var mq = window.matchMedia('(prefers-color-scheme: dark)');
          var onChange = function () {
            if (!storedTheme()) { apply(systemTheme(), false); }
          };
          if (mq.addEventListener) { mq.addEventListener('change', onChange); }
          else if (mq.addListener) { mq.addListener(onChange); }
        } catch (err) { /* ignore */ }
      }

      /* Apply a stored choice even on pages without the inline head bootstrap
         (docs.html), so the theme survives navigation. Without a stored choice
         nothing is set on <html> and the CSS media query keeps governing. */
      var stored = storedTheme();
      if (stored) { apply(stored, false); }
      else { paint(current()); }
    }

    return {
      init: init,
      apply: apply,
      toggle: toggle,
      current: current,
      key: THEME_KEY
    };
  }());

  /* ------------------------------------------------------------- copy ---- */

  var copy = (function () {
    var RESET_MS = 1600;

    function targetFor(button) {
      var id = attr(button, 'data-copy-target') || attr(button, 'data-copy');
      if (id) {
        var byId = document.getElementById(id);
        if (byId) { return byId; }
        /* Fall back to a CSS selector when the value is not an id. */
        try {
          var bySelector = document.querySelector(id);
          if (bySelector) { return bySelector; }
        } catch (err) { /* not a selector */ }
      }
      var scope = closest(button, 'pre') || closest(button, '.code-block') ||
        button.parentNode || document;
      return scope.querySelector('code') || scope.querySelector('pre');
    }

    function textOf(el) {
      if (!el) { return ''; }
      var value = el.innerText != null ? el.innerText : el.textContent;
      return String(value == null ? '' : value).replace(/\s+$/, '');
    }

    function legacyCopy(text) {
      var area = document.createElement('textarea');
      area.value = text;
      area.setAttribute('readonly', 'readonly');
      area.setAttribute('aria-hidden', 'true');
      area.style.position = 'fixed';
      area.style.top = '-1000px';
      area.style.opacity = '0';
      document.body.appendChild(area);
      var ok = false;
      try {
        area.select();
        area.setSelectionRange(0, area.value.length);
        ok = document.execCommand('copy');
      } catch (err) {
        ok = false;
      }
      document.body.removeChild(area);
      return ok;
    }

    /* Callback based on purpose: the async Clipboard API is absent in older
       browsers, and referring to Promise there would throw a ReferenceError. */
    function writeClipboard(text, done) {
      var nav = window.navigator;
      if (nav && nav.clipboard && typeof nav.clipboard.writeText === 'function') {
        try {
          var result = nav.clipboard.writeText(text);
          if (result && typeof result.then === 'function') {
            result.then(function () { done(true); }, function () { done(legacyCopy(text)); });
            return;
          }
          done(true);
          return;
        } catch (err) {
          done(legacyCopy(text));
          return;
        }
      }
      done(legacyCopy(text));
    }

    function flash(button, state) {
      var label = button.querySelector('[data-copy-label]');
      var idle = attr(button, 'data-copy-idle') || (label ? label.textContent : 'Copy');
      button.classList.remove('is-copied', 'is-failed');
      button.classList.add(state === 'failed' ? 'is-failed' : 'is-copied');
      setText(label, state === 'failed' ? ui('copy.failed', 'Press Ctrl+C') : ui('copy.copied', 'Copied'));
      button.setAttribute('data-copy-state', state);
      if (button.__copyTimer) { window.clearTimeout(button.__copyTimer); }
      button.__copyTimer = window.setTimeout(function () {
        button.classList.remove('is-copied', 'is-failed');
        button.removeAttribute('data-copy-state');
        setText(label, idle);
      }, RESET_MS);
    }

    function handle(button) {
      var text = textOf(targetFor(button));
      if (!text) {
        flash(button, 'failed');
        return;
      }
      writeClipboard(text, function (ok) {
        flash(button, ok ? 'copied' : 'failed');
      });
    }

    function init() {
      document.addEventListener('click', function (event) {
        var button = closest(event.target, '[data-copy]');
        if (!button) { return; }
        event.preventDefault();
        handle(button);
      }, false);
    }

    return { init: init, copyText: writeClipboard, textOf: textOf };
  }());

  /* ------------------------------------------------------------- tabs ---- */

  var tabs = (function () {
    function panelsOf(root) {
      return all('[data-panel]', root);
    }

    function buttonsOf(root) {
      return all('[data-tab]', root);
    }

    function activate(root, name, focus) {
      buttonsOf(root).forEach(function (button) {
        var selected = attr(button, 'data-tab') === name;
        button.setAttribute('aria-selected', selected ? 'true' : 'false');
        button.setAttribute('tabindex', selected ? '0' : '-1');
        if (selected) { button.classList.add('is-active'); }
        else { button.classList.remove('is-active'); }
        if (selected && focus) { button.focus(); }
      });
      panelsOf(root).forEach(function (panel) {
        var active = attr(panel, 'data-panel') === name;
        if (active) { panel.removeAttribute('hidden'); }
        else { panel.setAttribute('hidden', 'hidden'); }
      });
    }

    function initRoot(root) {
      var buttons = buttonsOf(root);
      if (!buttons.length) { return; }

      buttons.forEach(function (button, index) {
        if (!attr(button, 'role')) { button.setAttribute('role', 'tab'); }
        if (!attr(button, 'id')) { button.id = 'tab-' + index + '-' + Math.random().toString(36).slice(2, 7); }
        var panelName = attr(button, 'data-tab');
        var panel = all('[data-panel]', root).filter(function (item) {
          return attr(item, 'data-panel') === panelName;
        })[0];
        if (panel) {
          if (!attr(panel, 'role')) { panel.setAttribute('role', 'tabpanel'); }
          panel.setAttribute('aria-labelledby', button.id);
          if (!attr(panel, 'tabindex')) { panel.setAttribute('tabindex', '0'); }
        }
        button.addEventListener('click', function () {
          activate(root, attr(button, 'data-tab'), false);
        });
        button.addEventListener('keydown', function (event) {
          var key = event.key;
          var step = key === 'ArrowRight' || key === 'ArrowDown' ? 1 :
            (key === 'ArrowLeft' || key === 'ArrowUp' ? -1 : 0);
          if (!step) { return; }
          event.preventDefault();
          var next = (buttons.indexOf(button) + step + buttons.length) % buttons.length;
          activate(root, attr(buttons[next], 'data-tab'), true);
        });
      });

      var current = buttons.filter(function (button) {
        return attr(button, 'aria-selected') === 'true';
      })[0] || buttons[0];
      activate(root, attr(current, 'data-tab'), false);
    }

    function init() {
      all('[data-tabs]').forEach(initRoot);
    }

    return { init: init, activate: activate };
  }());

  /* ----------------------------------------------------------- status ---- */

  var status = (function () {
    var timer = null;
    /* Runtime copy: every word here is a translated string from the shell's payload. */
  var STATUS_WORDS = {
    ok: ui('status.ok', 'operational'),
    degraded: ui('status.degraded', 'degraded'),
    critical: ui('status.critical', 'outage'),
    unknown: ui('status.unknown', 'status unknown')
  };

    function levelOf(payload) {
      if (!payload || typeof payload !== 'object') { return 'unknown'; }
      /* `overall` is checked first: /v1/health reports a coarse `status` that
         never says "critical", while `overall` carries the real state. */
      var raw = payload.overall || payload.status || payload.state || payload.health;
      if (raw && typeof raw === 'object') { raw = raw.status || raw.state; }
      var value = String(raw == null ? '' : raw).toLowerCase();
      if (value === 'ok' || value === 'up' || value === 'healthy' || value === 'operational' || value === 'pass') { return 'ok'; }
      if (value === 'degraded' || value === 'warn' || value === 'warning' || value === 'partial' || value === 'maintenance') { return 'degraded'; }
      if (value === 'critical' || value === 'down' || value === 'fail' || value === 'failed' || value === 'error' || value === 'outage') { return 'critical'; }
      return payload.ok === true ? 'ok' : 'unknown';
    }

    function unwrap(payload) {
      if (payload && typeof payload === 'object' && payload.data && typeof payload.data === 'object') {
        /* Merge the envelope with the payload so both shapes resolve. */
        var merged = {};
        var key;
        for (key in payload.data) {
          if (Object.prototype.hasOwnProperty.call(payload.data, key)) { merged[key] = payload.data[key]; }
        }
        for (key in payload) {
          if (Object.prototype.hasOwnProperty.call(payload, key) && key !== 'data' && !(key in merged)) {
            merged[key] = payload[key];
          }
        }
        return merged;
      }
      return payload;
    }

    function formatUptime(value) {
      if (value == null) { return null; }
      if (typeof value === 'string' && !/^\d+(\.\d+)?$/.test(value)) { return value; }
      var seconds = Number(value);
      if (!isFinite(seconds) || seconds < 0) { return String(value); }
      var days = Math.floor(seconds / 86400);
      var hours = Math.floor((seconds % 86400) / 3600);
      var minutes = Math.floor((seconds % 3600) / 60);
      if (days > 0) { return days + 'd ' + hours + 'h'; }
      if (hours > 0) { return hours + 'h ' + minutes + 'm'; }
      return minutes + 'm';
    }

    function formatRedis(value) {
      if (value == null) { return null; }
      if (typeof value === 'object') { value = value.status || value.state || value.ok; }
      if (value === true) { return 'connected'; }
      if (value === false) { return 'unavailable'; }
      return String(value);
    }

    function paintPills(level) {
      all('[data-status-pill]').forEach(function (pill) {
        pill.setAttribute('data-state', level);
        setText(pill.querySelector('.pill__text'), STATUS_WORDS[level] || level);
        pill.setAttribute('title', ui('status.title', 'Upstream status') + ': ' + (STATUS_WORDS[level] || level));
      });
    }

    function paintFields(payload) {
      var checks = (payload.checks && typeof payload.checks === 'object') ? payload.checks : {};
      /* The DOM field names are fixed; the payload may nest or alias them. */
      var rawUptime = payload.uptime != null ? payload.uptime
        : (payload.uptime_seconds != null ? payload.uptime_seconds : checks.uptime);
      var rawRedis = payload.redis != null ? payload.redis : checks.redis;
      var values = {
        overall: payload.overall != null ? payload.overall : (STATUS_WORDS[payload.level] || payload.level),
        version: payload.version != null ? payload.version : payload.service_version,
        uptime: formatUptime(rawUptime),
        redis: formatRedis(rawRedis),
        updated_at: payload.updated_at || payload.generated || payload.timestamp || payload.time
      };
      var key;
      for (key in values) {
        if (!Object.prototype.hasOwnProperty.call(values, key)) { continue; }
        var text = values[key];
        if (text == null || text === '') { text = '—'; }
        all('[data-health-field="' + key + '"]').forEach(function (el) {
          setText(el, String(text));
        });
      }
    }

    function paint(payload) {
      var data = unwrap(payload) || {};
      var level = levelOf(data);
      data.level = level;
      paintPills(level);
      paintFields(data);
    }

    function fail() {
      paintPills('unknown');
      ['overall', 'uptime', 'redis'].forEach(function (key) {
        all('[data-health-field="' + key + '"]').forEach(function (el) { setText(el, '—'); });
      });
      all('[data-health-field="updated_at"]').forEach(function (el) {
        setText(el, 'not reachable');
      });
    }

    function parse(text) {
      try { return JSON.parse(text); } catch (err) { return null; }
    }

    /* A non-2xx status still carries a useful body (/v1/health answers 503 when
       critical), so the body is parsed first and the status only used to fail. */
    function request(done) {
      var url = './v1/health';
      if (window.fetch) {
        window.fetch(url, { headers: { Accept: 'application/json' }, credentials: 'omit' })
          .then(function (response) {
            return response.text().then(function (text) {
              var payload = parse(text);
              if (payload) { done(null, payload); }
              else { done(new Error('HTTP ' + response.status)); }
            });
          })
          .then(null, function (error) { done(error); });
        return;
      }
      if (window.XMLHttpRequest) {
        try {
          var xhr = new window.XMLHttpRequest();
          xhr.open('GET', url, true);
          xhr.setRequestHeader('Accept', 'application/json');
          xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) { return; }
            var payload = parse(xhr.responseText);
            if (payload) { done(null, payload); }
            else { done(new Error('HTTP ' + xhr.status)); }
          };
          xhr.onerror = function () { done(new Error('network error')); };
          xhr.send();
        } catch (err) {
          done(err);
        }
        return;
      }
      done(new Error('no transport'));
    }

    function refresh() {
      request(function (error, payload) {
        if (error || !payload) { fail(); return; }
        paint(payload);
      });
    }

    function start() {
      stop();
      refresh();
      timer = window.setInterval(refresh, STATUS_INTERVAL_MS);
    }

    function stop() {
      if (timer) {
        window.clearInterval(timer);
        timer = null;
      }
    }

    function init() {
      if (!all('[data-status-pill]').length && !all('[data-health-field]').length) { return; }
      start();
      document.addEventListener('visibilitychange', function () {
        if (document.hidden) { stop(); } else { start(); }
      }, false);
      /* Older Safari exposes the hidden state on webkitvisibilitychange. */
      document.addEventListener('webkitvisibilitychange', function () {
        if (document.hidden) { stop(); } else { start(); }
      }, false);
    }

    return { init: init, refresh: refresh, start: start, stop: stop };
  }());

  /* ------------------------------------------------------------- meta ---- */

  var meta = (function () {
    function init() {
      var wanted = all('[data-meta-field]');
      if (!wanted.length || !window.fetch) { return; }
      window.fetch('./v1/meta', { headers: { Accept: 'application/json' }, credentials: 'omit' })
        .then(function (response) {
          return response.text().then(function (text) {
            var parsed = null;
            try { parsed = JSON.parse(text); } catch (err) { parsed = null; }
            if (!parsed) { throw new Error('HTTP ' + response.status); }
            return parsed;
          });
        })
        .then(function (payload) {
          var data = (payload && payload.data && typeof payload.data === 'object') ? payload.data : payload;
          if (!data || typeof data !== 'object') { return; }
          var service = (data.service && typeof data.service === 'object') ? data.service : null;
          var version = data.version || data.service_version ||
            (service ? service.version : null) || data.api_version;
          var sources = data.sources;
          if (sources && typeof sources === 'object' && !(sources instanceof Array)) {
            sources = Object.keys(sources).join(' · ');
          } else if (sources instanceof Array) {
            sources = sources.map(function (item) {
              return (item && typeof item === 'object') ? (item.id || item.name || item.source) : item;
            }).filter(Boolean).join(' · ');
          }
          all('[data-meta-field="version"]').forEach(function (el) {
            if (version) { setText(el, String(version)); }
          });
          all('[data-meta-field="sources"]').forEach(function (el) {
            if (sources) { setText(el, String(sources)); }
          });
        })
        .then(null, function () { /* keep the static fallback */ });
    }

    return { init: init };
  }());

  /* --------------------------------------------------------------- edge --- */

  /**
   * Reports which cache answered a real API call, using the real headers.
   *
   * The server cannot know the CDN's verdict for a response it has not yet handed
   * over -- the edge writes that header on the way out. So it is read here, from a
   * live response, instead of being rendered into the cached HTML: a cache verdict
   * baked into a cacheable page is the exact bug this project already fixed once
   * (see the cookie comment in partials/shell.php).
   *
   * One HEAD request, delayed until the page is idle, and only when the document
   * actually carries the marker. The probe goes to /v1/tle because that is the
   * endpoint whose responses carry the cache headers; /v1/health is `no-store` and
   * would always answer "miss", which would be a truthful but useless reading.
   */
  var edge = (function () {
    var PROBE = '/v1/tle';
    var DELAY_MS = 1200;
    var timer = null;

    /* "MISS from L1:6518" -> "MISS". SCDN appends the node, which is useful in a
       log and noise in a badge. */
    function clean(value) {
      var text = String(value == null ? '' : value).trim();
      if (!text) { return ''; }
      var match = text.match(/^([A-Za-z-]+)/);
      return match ? match[1].toUpperCase() : text;
    }

    function paint(name, value) {
      all('[data-' + name + ']').forEach(function (el) {
        if (value) {
          setText(el, value);
          el.setAttribute('data-known', '1');
        }
      });
    }

    function measure() {
      if (!window.fetch) { return; }
      window.fetch(PROBE, {
        method: 'HEAD',
        cache: 'no-store',
        credentials: 'omit',
        headers: { Accept: 'application/json' }
      }).then(function (response) {
        var headers = response.headers;
        var edgeValue = clean(headers.get('eo-cache-status') || '');
        var raw = clean(headers.get('x-cache-status') || '');

        /* EdgeOne names its verdict; SCDN reuses X-Cache-Status, so on SCDN the
           same header is the EDGE answer and the origin's is on X-Origin-*. */
        if (!edgeValue) { edgeValue = raw; }

        var originValue = clean(headers.get('x-origin-cache-status') || '');
        var age = headers.get('x-origin-cache-age');
        if (originValue && age) {
          originValue += ' · ' + String(age).replace(/[^0-9]/g, '') + 's';
        }
        if (!originValue && /^(HIT|MISS|STALE|EXPIRED)$/.test(raw)) {
          originValue = raw;
        }

        paint('edge-cache', edgeValue);
        paint('origin-cache', originValue);
      }).then(null, function () { /* leave the placeholder: a probe failure is not an error */ });
    }

    function init() {
      if (!all('[data-edge-cache]').length && !all('[data-origin-cache]').length) { return; }
      if (!window.fetch) { return; }
      timer = window.setTimeout(measure, DELAY_MS);
    }

    function stop() {
      if (timer) {
        window.clearTimeout(timer);
        timer = null;
      }
    }

    return { init: init, stop: stop, measure: measure };
  }());

  /* ----------------------------------------------------------- preanswer --- */

  /**
   * Registers the ServiceWorker that pre-answers a direct visit to an API path.
   *
   * Registered on every host that serves this page, because the worker's scope is
   * per-origin: the one installed on satellite.db.authskin.xyz cannot act on
   * satellite.api.authskin.xyz. A registration failure is not an error -- curl and
   * every machine client are unaffected either way, and the site works without it.
   */
  var preanswer = (function () {
    function init() {
      if (!('serviceWorker' in navigator)) { return; }
      if (!window.isSecureContext) { return; }
      try {
        /* /v1/sw, not /sw.js or /sw: this vhost's rewrite is an explicit whitelist
           (only /v1/*, /docs, /privacy, /legal, ... reach index.php), so anything
           outside it is answered statically and a worker published there is a 404.
           /v1/sw arrives at the front controller, and the response's
           Service-Worker-Allowed header widens its scope to "/".
           See lib/ServiceWorker.php. */
        navigator.serviceWorker.register('/v1/sw', { scope: '/' }).then(null, function () {
          /* Ignored on purpose: an unavailable worker degrades to a normal
             navigation, which is the behaviour before this feature existed. */
        });
      } catch (err) {
        /* Older browsers throw synchronously instead of rejecting. */
      }
    }

    return { init: init };
  }());

  /* ---------------------------------------------------- section nav ---- */

  /*
   * Which section the nav should be pointing at.
   *
   * The underline used to be pinned to the FIRST link by a CSS :first-child rule,
   * so scrolling to "接口" still showed "概览" as the current item -- a nav that
   * lies about where the reader is. The markup now carries aria-current="location"
   * (and the stylesheet reads that attribute), so this only has to keep the
   * attribute on the section actually in view.
   *
   * No-JS visitors keep the server's default, which is the first section: the same
   * thing they saw before this existed, and never an underline on nothing.
   */
  var sectionnav = (function () {
    function init() {
      var nav = document.querySelector('.site-header .nav');
      if (!nav) { return; }
      var links = toArray(nav.querySelectorAll('a[href^="#"]'));
      if (links.length < 2) { return; }

      var targets = [];
      for (var i = 0; i < links.length; i++) {
        var id = String(links[i].getAttribute('href') || '').slice(1);
        var section = id ? document.getElementById(id) : null;
        if (section) { targets.push({ link: links[i], section: section }); }
      }
      if (targets.length < 2) { return; }

      var header = document.querySelector('.site-header');
      var raf = window.requestAnimationFrame || function (fn) { return window.setTimeout(fn, 16); };
      var ticking = false;

      function mark() {
        ticking = false;
        var offset = (header ? header.offsetHeight : 0) + 8;
        var line = window.pageYOffset + offset;
        var current = targets[0];
        for (var i = 0; i < targets.length; i++) {
          var top = targets[i].section.getBoundingClientRect().top + window.pageYOffset;
          if (top <= line) { current = targets[i]; }
        }
        /* At the very bottom the last section is on screen before its top reaches the
           line; without this the final item could never become current. */
        var docHeight = document.documentElement.scrollHeight;
        if (window.innerHeight + window.pageYOffset >= docHeight - 2) {
          current = targets[targets.length - 1];
        }
        for (var j = 0; j < targets.length; j++) {
          if (targets[j] === current) {
            targets[j].link.setAttribute('aria-current', 'location');
          } else {
            targets[j].link.removeAttribute('aria-current');
          }
        }
      }

      function schedule() {
        if (ticking) { return; }
        ticking = true;
        raf(mark);
      }

      mark();
      window.addEventListener('scroll', schedule, { passive: true });
      window.addEventListener('resize', schedule);
    }

    return { init: init };
  }());

  /* ------------------------------------------------------------ floats ----
     The Cap pill on every page, and the theme badge and music bar on a themed one, are
     pinned to the bottom corners -- which is exactly where the film band types its slogan
     out. app.css keeps them hidden while <body> carries data-floats="gated" and no
     .floats-shown; this opens that gate.

     It lives here rather than in theme.js because the plain site has no theme: the Cap
     pill is on that page too, and theme.js is never loaded there.

     The condition is the band's own bottom edge, not a pixel count: the band is one screen
     tall and the slogan sits at its bottom, so "the band has scrolled past the corner" is
     the threshold that actually answers the complaint. A page with no band (/docs,
     /legal/*) falls back to a short scroll, because there is nothing to uncover.

     Focus opens the gate too. The controls stay in the tab order while invisible (opacity,
     not visibility), so tabbing onto one must show what has focus. */
  var floats = (function () {
    var CORNER = '.themebar, .seasonbadge, [data-cap-host]';
    /* How much of the bottom of the screen the corner stack occupies: the music bar sits
       16px up -- 92px when the Cap pill shares that corner -- and is about 34px tall, so
       140 is the band's edge clearing the taller placement with room to spare. */
    var RESERVE = 140;

    function init() {
      var body = document.body;
      if (!body || attr(body, 'data-floats') !== 'gated') { return; }

      var film = document.querySelector('[data-film]');
      var held = false;
      var frame = 0;
      var raf = window.requestAnimationFrame || function (fn) { return window.setTimeout(fn, 16); };

      function scrolled() {
        return window.pageYOffset || document.documentElement.scrollTop || 0;
      }

      function wanted() {
        if (held) { return true; }
        var y = scrolled();
        if (y <= 0) { return false; }
        if (film) {
          /* The band is out of the way only once its bottom edge is above the corner. */
          return film.getBoundingClientRect().bottom <= window.innerHeight - RESERVE;
        }
        return y > 24;
      }

      function apply() {
        frame = 0;
        var open = wanted();
        var isOpen = body.classList.contains('floats-shown');
        if (open === isOpen) { return; }
        if (open) { body.classList.add('floats-shown'); }
        else { body.classList.remove('floats-shown'); }
      }

      function schedule() {
        if (frame) { return; }
        frame = raf(apply);
      }

      function corner(node) {
        return closest(node, CORNER);
      }

      window.addEventListener('scroll', schedule, { passive: true });
      window.addEventListener('resize', schedule, { passive: true });
      window.addEventListener('pageshow', schedule);
      document.addEventListener('focusin', function (event) {
        if (!corner(event.target)) { return; }
        held = true;
        schedule();
      });
      document.addEventListener('focusout', function (event) {
        if (!held) { return; }
        var box = corner(event.target);
        if (!box) { return; }
        if (event.relatedTarget && box.contains(event.relatedTarget)) { return; }
        held = false;
        schedule();
      });

      apply();
    }

    return { init: init };
  }());

  /* ------------------------------------------------------------- boot ---- */

  function boot() {
    /* A page that loads this file twice must not end up with two of everything.
       Measured on /docs, which used to include the shell's copy and its own: the
       theme button switched to dark and straight back inside one click (two bound
       handlers, net zero), two 60-second /v1/health pollers ran, and the clipboard
       handler fired twice. The duplicate tag is gone (lib/Docs.php removes it), and
       this guard means a future one cannot hurt either. */
    if (window.__SATAPI_APP_BOOTED__) { return; }
    window.__SATAPI_APP_BOOTED__ = true;

    /* First, before anything that can throw: the gate is CSS-closed from the markup, so a
       later failure must not leave the corner controls stranded off screen. */
    floats.init();
    theme.init();
    copy.init();
    tabs.init();
    status.init();
    meta.init();
    edge.init();
    preanswer.init();
    sectionnav.init();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, false);
  } else {
    boot();
  }

  window.SatApi = {
    theme: theme,
    copy: copy,
    tabs: tabs,
    status: status,
    meta: meta,
    edge: edge,
    preanswer: preanswer,
    sectionnav: sectionnav,
    floats: floats,
    boot: boot,
    version: '1.1.0'
  };
}(window, document));
