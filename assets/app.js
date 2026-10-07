/* ==========================================================================
   Satellite Data API — shared front-end behaviour
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
        var label = mode === 'dark' ? 'Switch to light theme' : 'Switch to dark theme';
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
      setText(label, state === 'failed' ? 'Press Ctrl+C' : 'Copied');
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
    var STATUS_WORDS = { ok: 'operational', degraded: 'degraded', critical: 'outage', unknown: 'status unknown' };

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
        pill.setAttribute('title', 'Upstream status: ' + (STATUS_WORDS[level] || level));
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

  /* ------------------------------------------------------------- boot ---- */

  function boot() {
    theme.init();
    copy.init();
    tabs.init();
    status.init();
    meta.init();
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
    boot: boot,
    version: '1.0.0'
  };
}(window, document));
