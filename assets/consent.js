/* ==========================================================================
   Boot, consent and proof-of-work for the document shell.

   Plain ES5-compatible JavaScript, no dependencies, no external requests. The
   server has already rendered the locale choice, the announcement banner and the
   consent dialog markup, so this file only *changes* what is on screen:

     - it hides the overlay once the page is interactive (the overlay exists to
       avoid a flash of the wrong language and to host the challenge, not to gate
       content that the server already sent);
     - it remembers an announcement dismissal;
     - it stores the cookie choice;
     - it runs the Cap challenge when `pow.enabled` is true.

   Nothing here is required for the page to be readable: with scripts blocked the
   document still shows the correct language, the notice, the dialog controls and
   every policy link. The challenge is the one thing that genuinely needs script,
   which is why failing open is the deployment default.
   ========================================================================== */
(function (window, document) {
  'use strict';

  var boot = window.__SATAPI_BOOT__ || {};
  var docEl = document.documentElement;

  /* ------------------------------------------------------------- cookies */

  function readCookie(name) {
    var parts = String(document.cookie || '').split(';');
    for (var i = 0; i < parts.length; i++) {
      var piece = parts[i].replace(/^\s+/, '');
      if (piece.indexOf(name + '=') === 0) {
        return decodeURIComponent(piece.slice(name.length + 1));
      }
    }
    return '';
  }

  function writeCookie(name, value, maxAgeSeconds) {
    var attrs = '; Path=/; SameSite=Lax';
    if (maxAgeSeconds) { attrs += '; Max-Age=' + maxAgeSeconds; }
    if (window.location.protocol === 'https:') { attrs += '; Secure'; }
    document.cookie = name + '=' + encodeURIComponent(value) + attrs;
  }

  /* ------------------------------------------------------------- locales */

  function applyLocale(code) {
    if (!code) { return; }
    docEl.setAttribute('lang', code);
    if (document.body) { document.body.setAttribute('data-locale', code); }

    var blocks = document.querySelectorAll('[data-locale]');
    for (var i = 0; i < blocks.length; i++) {
      var el = blocks[i];
      var match = el.getAttribute('data-locale') === code;
      if (match) {
        el.removeAttribute('hidden');
        el.classList.add('is-active');
      } else {
        el.setAttribute('hidden', 'hidden');
        el.classList.remove('is-active');
      }
    }

    var buttons = document.querySelectorAll('[data-lang-choice]');
    for (var j = 0; j < buttons.length; j++) {
      var active = buttons[j].getAttribute('data-lang-choice') === code;
      buttons[j].setAttribute('aria-pressed', active ? 'true' : 'false');
      if (active) { buttons[j].classList.add('is-active'); } else { buttons[j].classList.remove('is-active'); }
    }
  }

  function initLanguageSwitch() {
    applyLocale(boot.locale);

    var buttons = document.querySelectorAll('[data-lang-choice]');
    for (var i = 0; i < buttons.length; i++) {
      buttons[i].addEventListener('click', function (event) {
        var code = event.currentTarget.getAttribute('data-lang-choice');
        applyLocale(code);
        // The language cookie is a preference: only stored when the visitor has not
        // restricted optional cookies. Without it the switch lasts for this page.
        if (boot.cookies && boot.cookies.choice === 'reject_all') { return; }
        if (boot.cookies && boot.cookies.choice === 'essential_only') { return; }
        writeCookie(boot.langCookie || 'satapi-lang', code, (boot.cookieDays || 180) * 86400);
      });
    }
  }

  /* ------------------------------------------------------- announcement */

  function initAnnouncement() {
    var bar = document.querySelector('[data-announce]');
    if (!bar) { return; }

    var id = bar.getAttribute('data-announce-id') || '';
    if (id && readCookie('announcement_dismissed') === id) {
      // Already dismissed: remove it rather than hide it, so it takes no space and
      // leaves nothing for a screen reader to announce.
      bar.parentNode.removeChild(bar);
      return;
    }

    var close = bar.querySelector('[data-announce-close]');
    if (!close) { return; }
    close.addEventListener('click', function () {
      if (id) {
        // Expire with the notice itself when one is configured, so the cookie does
        // not outlive the thing it refers to.
        writeCookie('announcement_dismissed', id, 30 * 86400);
      }
      bar.parentNode.removeChild(bar);
    });
  }

  /* ------------------------------------------------------------ consent */

  function initConsent() {
    var dialog = document.querySelector('[data-consent]');
    if (!dialog) { return; }

    var note = dialog.querySelector('[data-consent-note]');
    var choice = (boot.cookies && boot.cookies.choice) || '';
    var currentVersion = (boot.cookies && boot.cookies.current_version) || boot.cookieVersion || 1;
    var recordedVersion = (boot.cookies && boot.cookies.version) || 0;

    // Ask again when the notice itself changed, because the previous answer covered
    // a different set of cookies.
    var needsDecision = !choice || (recordedVersion && recordedVersion !== currentVersion);

    if (needsDecision) {
      dialog.removeAttribute('hidden');
      if (document.body) { document.body.classList.add('has-dialog'); }
    }

    function decide(kind) {
      writeCookie(boot.cookieName || 'cookie_consent', kind + ';v=' + currentVersion, (boot.cookieDays || 180) * 86400);
      dialog.setAttribute('hidden', 'hidden');
      if (document.body) { document.body.classList.remove('has-dialog'); }

      if (kind !== 'accept_all') {
        // Remove optional cookies that may already exist, so a downgrade actually
        // downgrades instead of leaving the old state behind.
        writeCookie('satapi-theme', '', 0);
      }
      if (kind === 'reject_all' && note) {
        note.removeAttribute('hidden');
        note.setAttribute('data-state', 'rejected');
      } else if (kind === 'essential_only' && note) {
        note.removeAttribute('hidden');
        note.setAttribute('data-state', 'essential');
      }
      showSettingsButton();
    }

    var choices = dialog.querySelectorAll('[data-consent-choice]');
    for (var i = 0; i < choices.length; i++) {
      choices[i].addEventListener('click', function (event) {
        decide(event.currentTarget.getAttribute('data-consent-choice'));
      });
    }

    function showSettingsButton() {
      var host = document.querySelector('[data-consent-settings]');
      if (!host) { return; }
      host.removeAttribute('hidden');
      if (host.getAttribute('data-bound') === '1') { return; }
      host.setAttribute('data-bound', '1');
      host.addEventListener('click', function (event) {
        event.preventDefault();
        dialog.removeAttribute('hidden');
        if (document.body) { document.body.classList.add('has-dialog'); }
      });
    }

    if (!needsDecision) { showSettingsButton(); }
  }

  /* ------------------------------------------------------------- overlay */
  /*
   * Three phases, reproduced from the reference loading component.
   *
   * The component drives everything from one number: how many of its tasks have
   * finished, over how many there are. It runs every task at once, increments a counter
   * in each one's `finally`, and every visual is a function of done/total -- the bar's
   * length, the figure, the caption's position, and the backdrop's blur. When the count
   * reaches the total it marks itself leaving, waits a fixed 1500ms with the screen
   * filled, and only then considers the app loaded (handing over at 2400ms).
   *
   * That is what this reproduces:
   *
   *   phase 1  pre-response   progress 0, backdrop blurred by the full 8px
   *   phase 2  linear loading the bar grows as tasks finish, backdrop de-focuses
   *   phase 3  fill and enter the band fills the screen and HOLDS for 1500ms
   *
   * The hold is the part that was missing before. An earlier version removed the
   * overlay as soon as the wipe finished, so the transition was a flicker instead of an
   * entrance. These three constants are the reference's own numbers.
   */
  var PHASE3_BAND_MS = 620;    // the wipe that fills the screen (md3.css `boot-fadeIn`)
  var PHASE3_HOLD_MS = 1500;   // filled and still, before anything is revealed
  var PHASE3_TOTAL_MS = 2400;  // band + hold + fade; matches the reference's handover
  var BOOT_MIN_MS = 1400;      // minimum dwell so phases 1 and 2 are actually seen

  var bootTasks = [];
  var bootDone = 0;
  var bootStartedAt = 0;
  var bootLeaving = false;

  function overlay() {
    return document.querySelector('[data-boot]');
  }

  function setProgress(percent) {
    var value = Math.max(0, Math.min(100, Math.round(percent)));
    var bar = document.querySelector('[data-boot-bar]');
    var fill = document.querySelector('[data-boot-fill]');
    var figure = document.querySelector('[data-boot-figure]');
    if (bar) {
      bar.setAttribute('aria-valuenow', String(value));
    }
    if (fill) {
      /*
       * One custom property drives the bar, the figure's position AND the backdrop's
       * blur. md3.css turns it into scaleY() and into blur(8px - p*8px).
       *
       * It is set on the ROOT, not on the bar. A custom property inherits down the tree,
       * and the backdrop (`.boot__bg`) is a SIBLING of the bar, not a descendant -- so
       * writing it to the bar left the backdrop reading the fallback 0 for its whole
       * life and the de-focus never happened. Measured: blur stayed at a flat 8px at
       * 33% and at 67% progress, which is how this was found.
       */
      document.documentElement.style.setProperty('--boot-progress', String(value / 100));
    }
    if (figure) {
      figure.textContent = String(value);
    }
  }

  function setCaption(text) {
    var caption = document.querySelector('[data-boot-caption]');
    if (!caption || !text) { return; }
    /*
     * Write to the ACTIVE locale span, not to the container.
     *
     * The caption is rendered by the shell with one span per locale, exactly like every
     * other translated string, so the language switcher works without JavaScript having
     * to know the strings. Assigning to the container flattened all three into view at
     * once -- measured as "正在验证…正在驗證…Verifying…". Updating only the visible span
     * keeps that contract and still swaps the caption as tasks finish.
     */
    var active = caption.querySelector('.i18n.is-active');
    if (active) {
      active.textContent = text;
      return;
    }
    caption.textContent = text;
  }

  function setHintText(text) {
    var hint = document.querySelector('[data-boot-hint]');
    if (hint && text) { hint.textContent = text; }
  }

  function showOverlayError(message) {
    var el = overlay();
    if (el) { el.removeAttribute('hidden'); }
    var box = document.querySelector('[data-boot-error]');
    if (box) {
      if (message) { setCaption(message); }
      box.removeAttribute('hidden');
    }
  }

  /**
   * Register work that the screen waits on. Order matters only for the caption: the
   * tasks after the first are what the visitor sees while the bar is moving.
   */
  function registerBootTask(label, run) {
    bootTasks.push({ label: label, run: run });
  }

  /**
   * Phase 3. Marks the screen as leaving (which starts the band), holds, then fades and
   * removes the overlay.
   */
  function finishBoot() {
    var el = overlay();
    if (!el || bootLeaving) { return; }
    bootLeaving = true;
    var elapsed = Date.now() - bootStartedAt;
    var wait = Math.max(0, BOOT_MIN_MS - elapsed);
    setProgress(100);
    setCaption(bootText('boot.entering', 'Entering'));

    window.setTimeout(function () {
      el.classList.add('boot--leaving');
      // The fade begins only after the band has arrived AND held.
      window.setTimeout(function () {
        el.classList.add('boot--done');
      }, PHASE3_BAND_MS + PHASE3_HOLD_MS);
      window.setTimeout(function () {
        if (el.parentNode) { el.parentNode.removeChild(el); }
      }, PHASE3_TOTAL_MS);
    }, wait);
  }

  function bootText(key, fallback) {
    var table = (boot && boot.strings) || {};
    return table[key] || fallback;
  }

  /**
   * Run every task concurrently and advance on each completion, exactly as the
   * reference does. A task that rejects still counts: the screen must never stall on a
   * failure, because the page it is covering has already been sent.
   */
  function runBootTasks() {
    bootStartedAt = Date.now();
    var total = bootTasks.length;
    if (total === 0) {
      finishBoot();
      return;
    }
    setProgress(0);
    setCaption(bootText('boot.preparing', 'Preparing your session'));

    var finished = 0;
    function advance(task) {
      finished += 1;
      setProgress(finished / total * 100);
      if (finished >= total) {
        finishBoot();
      } else if (task && task.label) {
        setCaption(task.label);
      }
    }

    bootTasks.forEach(function (task, index) {
      var next = bootTasks[index + 1];
      Promise.resolve()
        .then(function () { return task.run(); })
        .catch(function () { /* counted anyway, see above */ })
        .then(function () { advance(next); });
    });
  }

  /* ------------------------------------------------------ proof of work */

  /* The widget resolves its own challenge and solves it; we only load it, wait for
     `solve`, and hand the token to our own endpoint to mint a session cookie.
     Asset overrides (window.CAP_CUSTOM_*) are set inline in the document head by
     lib/Support/CapBootstrap.php, BEFORE this file runs, so the widget never reaches
     for a third-party CDN. */
  function loadCapScript(endpoint) {
    return new Promise(function (resolve, reject) {
      var existing = document.querySelector('script[data-cap]');
      if (existing) {
        if (existing.getAttribute('data-loaded') === '1') { resolve(); return; }
        existing.addEventListener('load', function () { resolve(); });
        existing.addEventListener('error', function () { reject(new Error('widget failed to load')); });
        return;
      }
      var script = document.createElement('script');
      script.src = endpoint + '/widget.js';
      script.async = true;
      script.setAttribute('data-cap', '1');
      script.addEventListener('load', function () {
        script.setAttribute('data-loaded', '1');
        resolve();
      });
      script.addEventListener('error', function () { reject(new Error('widget failed to load')); });
      document.head.appendChild(script);
    });
  }

  function solveWithCap(endpoint, siteKey) {
    return new Promise(function (resolve, reject) {
      var host = document.querySelector('[data-cap-host]');
      if (!host) {
        host = document.createElement('div');
        host.setAttribute('data-cap-host', '1');
        var bar = document.querySelector('.boot__bar');
        if (bar && bar.parentNode) { bar.parentNode.insertBefore(host, bar.nextSibling); }
        else if (document.body) { document.body.appendChild(host); }
      }

      // The widget reports its result by writing into an input named by
      // data-cap-hidden-field-name. It renders that input itself, but it needs the
      // attribute to know the name, and the value to read afterwards -- relying only on
      // a `solve` event produced no challenge request at all in testing, so the input is
      // the contract we drive.
      var fieldName = 'cap-token';
      var widget = document.createElement('cap-widget');
      // The site key is a PATH PREFIX, not a body field. Cap's challenge endpoint is
      // POST /{siteKey}/challenge -- posting to /challenge without it answers 500
      // ("Internal server error"), which the widget surfaces as a network_error and the
      // visitor sees as a challenge that never finishes. Measured: /cap/{siteKey}/challenge
      // -> 200 with a real token, /cap/challenge -> 500. So the endpoint has to carry the
      // key even though the attribute also exists.
      var base = endpoint + '/';
      if (siteKey) { base = endpoint + '/' + encodeURIComponent(siteKey) + '/'; }
      widget.setAttribute('data-cap-api-endpoint', base);
      widget.setAttribute('data-cap-hidden-field-name', fieldName);
      if (siteKey) { widget.setAttribute('data-cap-site-key', siteKey); }
      host.appendChild(widget);

      var settled = false;
      var deadline = Date.now() + 45000;
      var errors = [];
      widget.addEventListener('error', function (e) {
        errors.push((e && e.detail) ? String(e.detail) : 'widget error');
      });

      // Start the challenge explicitly.
      //
      // A widget that is created with document.createElement and appended does not begin
      // on its own -- measured over several runs: it fetched its WASM and then sat idle,
      // never posting to /challenge, until solve() was called. Calling it once the element
      // is connected is what makes the flow start. The declared method exists on the
      // element (solve, reset, tokenValue), so this is the documented entry point rather
      // than a private hook.
      function begin(attempt) {
        if (settled || !widget.solve) { return; }
        try {
          widget.solve();
        } catch (e) {
          // A solve() that throws because the element is not ready yet is retried below;
          // one that throws twice is reported by the polling timeout.
          if (attempt < 6) {
            window.setTimeout(function () { begin(attempt + 1); }, 300);
          }
        }
      }
      // One tick after insertion, so the custom element is upgraded and connected.
      window.setTimeout(function () { begin(0); }, 60);

      function token() {
        // The input may live inside the widget's own DOM (light or shadow).
        var found = null;
        function look(root) {
          if (!root || found) { return; }
          var input = root.querySelector && root.querySelector('input[name="' + fieldName + '"]');
          if (input && input.value) { found = input.value; return; }
          var all = root.querySelectorAll ? root.querySelectorAll('*') : [];
          for (var i = 0; i < all.length; i++) {
            if (all[i].shadowRoot) { look(all[i].shadowRoot); if (found) { return; } }
          }
        }
        look(document);
        if (found) { return found; }
        found = null;
        look(widget);
        if (found) { return found; }
        if (widget.shadowRoot) { look(widget.shadowRoot); }
        return found;
      }

      (function poll() {
        if (settled) { return; }
        var t = token();
        if (t) {
          settled = true;
          resolve(t);
          return;
        }
        if (Date.now() > deadline) {
          settled = true;
          reject(new Error(errors.length ? errors.join('; ') : 'challenge timed out'));
          return;
        }
        window.setTimeout(poll, 400);
      })();

      // Some widget versions do emit `solve`; accept it as a shortcut so a working
      // event does not have to wait for the poll.
      widget.addEventListener('solve', function (event) {
        if (settled) { return; }
        var detail = event && event.detail;
        var t = typeof detail === 'string' ? detail
          : (detail && (detail.token || (detail[0] && detail[0].token))) || '';
        if (t) { settled = true; resolve(t); }
      });
    });
  }

  function tokenToBase64Url(token) {
    // Cap emits a JSON string. The self-test also calls this directly, where the value
    // may already be a plain string.
    var json = typeof token === 'string' ? token : JSON.stringify(token);
    var bytes = new TextEncoder().encode(json);
    var binary = '';
    for (var i = 0; i < bytes.length; i++) { binary += String.fromCharCode(bytes[i]); }
    return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function verifyPow(token) {
    return new Promise(function (resolve, reject) {
      var url = (boot.endpoints && boot.endpoints.verifyPow) || '/v1/consent/verify-pow';
      var request = new XMLHttpRequest();
      request.open('POST', url, true);
      request.setRequestHeader('Content-Type', 'application/json');
      request.setRequestHeader('Accept', 'application/json');
      request.timeout = 12000;
      request.onreadystatechange = function () {
        if (request.readyState !== 4) { return; }
        if (request.status >= 200 && request.status < 300) { resolve(request.responseText); }
        else { reject(new Error('verify-pow HTTP ' + request.status)); }
      };
      request.ontimeout = function () { reject(new Error('verify-pow timeout')); };
      request.onerror = function () { reject(new Error('verify-pow network error')); };
      request.send(JSON.stringify({ token: tokenToBase64Url(token) }));
    });
  }

  /**
   * The challenge, as one task among several rather than as the whole screen.
   *
   * It resolves either way. Cap being unreachable degrades the protection; it must not
   * hold the screen open, because the content behind it has already been sent.
   */
  function powTask() {
    var pow = boot.pow || {};
    if (!pow.enabled) {
      return function () { return Promise.resolve(); };
    }

    return function () {
      return loadCapScript(pow.endpoint)
        .then(function () { return solveWithCap(pow.endpoint, pow.site_key); })
        .then(function (token) { return verifyPow(token); })
        .then(function () {
          if (document.body) { document.body.classList.add('is-verified'); }
        })
        .catch(function (error) {
          var message = (document.querySelector('[data-boot-fail]') || {}).textContent || 'unavailable';
          showOverlayError(message + ' (' + (error && error.message ? error.message : 'error') + ')');
        });
    };
  }

  /* ---------------------------------------------------------------- init */

  function initBoot() {
    var pow = boot.pow || {};

    // A short, real round trip so the rail reports something that actually happened
    // rather than stepping on a timer. It is deliberately the cheapest endpoint.
    registerBootTask(bootText('boot.preparing', 'Preparing your session'), function () {
      if (!window.fetch) { return Promise.resolve(); }
      return fetch('/v1/health/live', { headers: { Accept: 'application/json' } })
        .then(function () { return null; })
        .catch(function () { return null; });
    });

    // The session check, which is the work that genuinely gates the page.
    registerBootTask(
      bootText('boot.verifying', 'Verifying…'),
      powTask()
    );

    // Phase 3 gets its own task so the count reaches the total on the handover step,
    // the way the reference's last task does.
    registerBootTask(bootText('boot.entering', 'Entering'), function () {
      if (!pow.enabled) { setCaption(bootText('boot.ready', 'Ready')); }
      return Promise.resolve();
    });

    runBootTasks();
  }

  function init() {
    try { initLanguageSwitch(); } catch (e) { /* a broken switcher must not stop the page */ }
    try { initAnnouncement(); } catch (e) { }
    try { initConsent(); } catch (e) { }
    try { initBoot(); } catch (e) {
      // The screen must never be left standing: reveal the page on any failure.
      var el = overlay();
      if (el) { el.classList.add('boot--leaving', 'boot--done'); }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Expose the tiny pieces the self-test drives directly.
  window.SatApiConsent = {
    applyLocale: applyLocale,
    tokenToBase64Url: tokenToBase64Url,
    readCookie: readCookie,
    writeCookie: writeCookie
  };
}(window, document));
