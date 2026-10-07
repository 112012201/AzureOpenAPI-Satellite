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
   * Four phases, reproduced from the reference sites the operator named.
   *
   * The structure comes from tongdiaojihua.com's loading module:
   *
   *   overlay      a fixed, full-bleed dark block whose own opacity fades LAST,
   *                on a delay, after the coloured fill has crossed it
   *   fill         an :after block scaled from the left edge on
   *                cubic-bezier(.76, 0, .24, 1) -- slow, fast, slow
   *   rail         a constant 12px, filled by the progress value in pixels
   *   readout      positioned by the SAME value, so it rides the top of the fill
   *
   * And the driving model comes from endfield's component: real task completion, with
   * every visual a function of how much has finished.
   *
   * The progress bar moves in four segments rather than on one curve:
   *
   *   startup      accelerating from rest
   *   sustain      the steady middle
   *   deceleration easing into the target
   *   rest         the final settle, no movement left
   *
   * Each segment gets its own bezier, which is what makes the bar read as mechanical
   * rather than as a single tween. The segment lengths are proportional to how far that
   * segment has to travel, so a phase that only moves a little is not given the same time
   * as one that moves a lot.
   */
  var PHASE_FILL_MS = 760;     // the fill's own duration (md3.css boot-fill)
  var PHASE_FADE_MS = 400;     // the overlay's fade, on a 500ms delay (the .boot--done rule)
  var PHASE_FADE_DELAY_MS = 500;
  var PHASE_CROSSFADE_MS = 400;
  var PHASE_TOTAL_MS = PHASE_FILL_MS + PHASE_FADE_DELAY_MS + PHASE_FADE_MS;
  var BOOT_MIN_MS = 2000;      // minimum dwell: the four-segment roll needs room to be seen

  /*
   * Four segments, each with its own curve. This is the "startup / sustain / deceleration
   * / rest" shape: the bar leaves zero with an accelerating curve, holds a steady pace
   * through the middle, then eases into its target instead of arriving at full speed.
   */
  /*
   * The curves are chosen by their ENDPOINT TANGENTS, not by feel, because that is what
   * decides the speed the bar enters and leaves each segment:
   *
   *   curve            slope at start   slope at end    reads as
   *   ease-in  (0,0,1,1)      0.00          2.00        accelerating from rest
   *   smooth   (.37,0,.63,1)  0.00          0.00        a gentle, even middle
   *   ease-out (0,0,.58,1)    1.70          0.00        decelerating into the target
   *   slow-out (.3,0,.8,.6)   0.00          2.00        a last, shallow settle
   *
   * The first version used (0.22, 1, 0.36, 1) for startup, whose START slope is 4.55 -- it
   * left the blocks at more than four times the average speed and then eased off, which is
   * backwards for a startup phase. Measured as 153 px/s of travel in the first band against
   * 1451 in the second; it is replaced.
   *
   * The shares are proportional to how much of the journey each segment carries, so a
   * segment that only moves a little is not given the same time as one that moves a lot.
   */
  var BOOT_SEGMENTS = [
    // startup / sustain / deceleration / rest -- the four segments the operator named.
    //
    // The curves and the shares are not chosen by eye. A search over candidate curves and
    // share splits kept the combinations that satisfy all four properties at once: the startup
    // begins near rest and accelerates; the sustain segment is the FASTEST of the four; the
    // deceleration ends slower than it starts; the rest segment is the slowest; and nothing
    // anywhere in the journey stalls. Measured profile of the chosen set, in %/s:
    //
    //   segment        mean   first   last   curve
    //   startup          88       8    143   ease-in       (0.42, 0, 1, 1)
    //   sustain         123      81      7   strong in-out (0.60, 0, 0.40, 1)
    //   deceleration     71      45     55   ease-out      (0.00, 0, 0.58, 1)
    //   rest             39      28      5   ease-in-out   (0.42, 0, 0.58, 1)
    //
    // The shares are also the fraction of the journey each segment carries, which is what
    // makes the speed profile follow from the split rather than from a tuned rate. The lowest
    // instantaneous rate across the whole journey is 6.9 %/s, so no segment dwells.
    { key: 'startup',      share: 0.28, curve: [0.42, 0.00, 1.00, 1.00] },
    { key: 'sustain',      share: 0.38, curve: [0.60, 0.00, 0.40, 1.00] },
    { key: 'deceleration', share: 0.22, curve: [0.00, 0.00, 0.58, 1.00] },
    { key: 'rest',         share: 0.12, curve: [0.42, 0.00, 0.58, 1.00] }
  ];

  /**
   * The fraction of the journey covered after `elapsed` ms.
   *
   * The segments are equal in duration -- BOOT_TRAVEL_MS cut into four -- and each carries
   * the fraction of the distance named by its `share`. That split IS the speed profile:
   * startup carries 28% while accelerating, sustain 32% at an even pace, deceleration 26%
   * while easing off, and rest the final 14% very gently.
   *
   * The curves were chosen by their endpoint slopes, because those set the speed the bar
   * enters and leaves a segment: ease-in leaves rest at zero and arrives at 2.0; the smooth
   * middle is near 0 at both ends; ease-out arrives at zero from 1.7. The first version used
   * (0.22, 1, 0.36, 1) for startup, whose start slope is 4.55 -- it left the blocks at over
   * four times the average and then eased off, backwards for a startup.
   */
  function bootTraveled(elapsed) {
    var slice = BOOT_TRAVEL_MS / BOOT_SEGMENTS.length;
    var base = 0;
    for (var i = 0; i < BOOT_SEGMENTS.length; i++) {
      if (elapsed < slice * (i + 1) || i === BOOT_SEGMENTS.length - 1) {
        var local = Math.max(0, Math.min(1, (elapsed - slice * i) / slice));
        return base + BOOT_SEGMENTS[i].share * bezier(BOOT_SEGMENTS[i].curve, local);
      }
      base += BOOT_SEGMENTS[i].share;
    }
    return 1;
  }
  // Total time the roll spends moving after a task reports. Long enough for four segments
  // to be distinguishable, short enough to stay inside the settle budget.
  var BOOT_TRAVEL_MS = 1250;

  var bootTasks = [];          // registered work the screen waits on
  var bootTarget = 0;
  var bootShown = 0;
  var bootRollTimer = null;
  var bootLeaving = false;
  var bootStartedAt = 0;

  /*
   * Every value the display actually paints is recorded on window.__SATAPI_BOOT_ROLL__.
   *
   * This exists because the DOM cannot answer "did it skip a value": a MutationObserver
   * delivers batched records, so several writes inside one frame collapse into one entry
   * showing only the last, which made a correct roll look like it was jumping. The log is
   * the paint order itself, so it is the only honest source for that question.
   */
  var bootPaintLog = [];

  function overlay() {
    return document.querySelector('[data-boot]');
  }

  /** Cubic bezier easing, matching the timing function the browser would apply. */
  function easeAt(segments, progress) {
    var travelled = 0;
    var segment = segments[segments.length - 1];
    for (var i = 0; i < segments.length; i++) {
      if (progress <= travelled + segments[i].share || i === segments.length - 1) {
        segment = segments[i];
        break;
      }
      travelled += segments[i].share;
    }
    var local = Math.max(0, Math.min(1, (progress - travelled) / segment.share));
    return { key: segment.key, eased: bezier(segment.curve, local) };
  }

  /** The same cubic-bezier solve the browser uses, by Newton iteration on x. */
  function bezier(curve, x) {
    var p1x = curve[0], p1y = curve[1], p2x = curve[2], p2y = curve[3];
    var cx = 3 * p1x, bx = 3 * (p2x - p1x) - cx, ax = 1 - cx - bx;
    var cy = 3 * p1y, by = 3 * (p2y - p1y) - cy, ay = 1 - cy - by;
    var sampleX = function (t) { return ((ax * t + bx) * t + cx) * t; };
    var sampleY = function (t) { return ((ay * t + by) * t + cy) * t; };
    var slopeX = function (t) { return (3 * ax * t + 2 * bx) * t + cx; };
    var t = x;
    for (var i = 0; i < 8; i++) {
      var dx = sampleX(t) - x;
      if (Math.abs(dx) < 1e-6) { break; }
      var d = slopeX(t);
      if (Math.abs(d) < 1e-6) { break; }
      t -= dx / d;
    }
    return sampleY(t);
  }

  /**
   * Put one value on screen.
   *
   * The progress is written as a LENGTH in pixels because that is how the reference drives
   * both the rail and the readout (height: var(--progress) and top: var(--progress)), and
   * it means neither element has to know the viewport height.
   *
   * It is written to the ROOT, not to the bar: the backdrop is a sibling of the bar, and a
   * custom property only inherits downward, so writing it to the bar left the backdrop
   * reading the fallback for its whole life and the de-focus never happened.
   */
  function paintProgress(value) {
    var height = window.innerHeight || 800;
    var figure = document.querySelector('[data-boot-figure]');
    if (figure) { figure.textContent = String(Math.floor(value)); }
    document.documentElement.style.setProperty('--boot-progress-px', (value / 100 * height) + 'px');
    document.documentElement.style.setProperty('--boot-progress', String(value / 100));
    if (bootPaintLog.length < 400) { bootPaintLog.push(Math.floor(value)); }
    window.__SATAPI_BOOT_ROLL__ = bootPaintLog;
  }

  /*
   * A handle for driving and inspecting the count from outside.
   *
   * The roll cannot be exercised from an ordinary run -- the tasks finish fast enough that
   * nothing unusual happens -- so the mechanism is driven directly instead: set a target
   * from a known position, let it run, and read how far it travelled. It is also the handle
   * to reach for if the count ever looks wrong in production.
   */
  window.__SATAPI_BOOT_PROGRESS__ = {
    setTarget: function (value) {
      bootTarget = Math.max(0, Math.min(100, value));
      startRoll();
    },
    reset: function (value) {
      bootShown = Math.max(0, Math.min(100, value));
      bootTarget = bootShown;
      paintProgress(bootShown);
    },
    shown: function () { return bootShown; },
    target: function () { return bootTarget; },
    segment: function () { return bootRollSegment; },
    settled: function () { return rollSettled(); }
  };

  /** True once the display has reached its target, so the fill may begin. */
  function rollSettled() {
    return bootTarget === 0 || Math.abs(bootShown - bootTarget) < 0.6;
  }

  var bootRollSegment = 'rest';

  /**
   * One frame of the count.
   *
   * Where the bar is within its own four segments sets both the curve and the rate: the
   * display accelerates out of rest, holds a steady pace, then eases into the target. The
   * phase is decided by how much of the journey is LEFT relative to how far a segment
   * normally carries, which keeps the shape the same whether the gap is 5 points or 60.
   */
  function stepRoll() {
    var now = Date.now();
    var elapsed = now - (stepRoll.started || now);
    stepRoll.last = now;

    if (bootShown < bootTarget) {
      // Position comes from where we are in the JOURNEY, not from a rate.
      //
      // This inversion is the whole point: the four segments are equal in DURATION while
      // carrying different fractions of the DISTANCE, so the speed profile falls out of that
      // split instead of a tuned constant. An earlier version derived a rate from
      // `remaining / frames`, which collapses toward zero as the gap closes and therefore
      // swamped the curve shape it was meant to express -- measured 164 px/s of travel in the
      // startup band against 1051 in the middle, with startup still the slowest segment.
      var next = Math.min(bootTarget, stepRoll.from + (stepRoll.total || 1) * bootTraveled(elapsed));
      // The segment name is for the diagnostic handle only; the movement itself is the line
      // above. Elapsed time picks it, since the segments are equal in duration.
      var slice = BOOT_TRAVEL_MS / BOOT_SEGMENTS.length;
      bootRollSegment = BOOT_SEGMENTS[Math.min(BOOT_SEGMENTS.length - 1, Math.floor(elapsed / slice))].key;
      for (var v = Math.floor(bootShown) + 1; v <= Math.floor(next); v++) {
        paintProgress(v);
      }
      bootShown = next;
    }

    if (rollSettled()) {
      bootShown = bootTarget;
      paintProgress(bootShown);
      bootRollTimer = null;
      bootRollSegment = 'rest';
      return;
    }
    bootRollTimer = window.requestAnimationFrame(stepRoll);
  }

  function startRoll() {
    if (bootRollTimer !== null) { return; }
    /*
     * A new journey begins HERE, and only here.
     *
     * This used to re-stamp `started` on every call, and `setProgress` calls it once per task,
     * so each task completion reset the travel clock to zero. The display then barely moved:
     * with a target of 67 it sat at 0.001, because `elapsed` was always a few milliseconds and
     * the travel fraction it produced was near zero. The origin and the start time belong to
     * the journey, not to each update of its destination.
     */
    stepRoll.started = Date.now();
    stepRoll.from = bootShown;
    stepRoll.total = Math.max(0.001, bootTarget - bootShown);
    bootRollTimer = window.requestAnimationFrame(stepRoll);
  }

  function setProgress(percent) {
    var value = Math.max(0, Math.min(100, Math.round(percent)));
    var bar = document.querySelector('[data-boot-bar]');
    if (bar) { bar.setAttribute('aria-valuenow', String(value)); }
    // The target runs ahead; the display chases it. Both the rail and the numeral follow the
    // display, so they move together.
    var changed = value !== bootTarget;
    bootTarget = value;
    if (bootRollTimer === null) {
      startRoll();
    } else if (changed) {
      /*
       * A new leg, but the clock keeps running.
       *
       * The journey baseline has to be re-stamped whenever the destination moves, or the
       * distance stays whatever it was when the roll first started. Measured as total 0.001 --
       * because that first call happened at progress 0, where there was nothing to travel --
       * and the display then froze at 0.001 while the target climbed to 67.
       *
       * The stopwatch is deliberately NOT reset: elapsed time belongs to the whole roll, so the
       * segment profile keeps advancing instead of restarting at startup on every task.
       */
      stepRoll.from = bootShown;
      stepRoll.total = Math.max(0.001, bootTarget - bootShown);
    }
  }

  function setCaption(text) {
    var caption = document.querySelector('[data-boot-caption]');
    if (!caption || !text) { return; }
    /*
     * Write to the ACTIVE locale span, not to the container. The shell renders one span per
     * locale and CSS picks one, so assigning to the container would flatten all three into
     * view -- measured as "正在验证…正在驗證…Verifying…".
     */
    var active = caption.querySelector('.i18n.is-active');
    if (active) {
      active.textContent = text;
      return;
    }
    caption.textContent = text;
  }

  /**
   * Show the challenge widget.
   *
   * The widget is present and working for the whole of loading, but not shown. It appears in
   * exactly two cases, which are the two that matter to the visitor:
   *
   *   1. the challenge FAILED -- there is something for them to do about it
   *   2. loading FINISHED     -- the page is theirs, and the badge is part of it
   *
   * Anything else means a progress bar with a control underneath it that the visitor is not meant
   * to touch yet. Hiding rather than removing is deliberate: the challenge still has to solve
   * while the screen is up, so the element keeps its box.
   */
  function revealCapHost() {
    var host = document.querySelector('[data-cap-host]');
    if (host) { host.classList.add('is-visible'); }
  }

  function showOverlayError(message) {
    var el = overlay();
    if (el) { el.removeAttribute('hidden'); }
    var box = document.querySelector('[data-boot-error]');
    if (box) {
      if (message) { setCaption(message); }
      box.removeAttribute('hidden');
    }
    // Case 1: the challenge could not be satisfied, so the visitor needs the control.
    revealCapHost();
  }

  /**
   * Register work the screen waits on. Order matters only for the caption: tasks after the
   * first are what the visitor reads while the bar is moving.
   */
  function registerBootTask(label, run) {
    bootTasks.push({ label: label, run: run });
  }

  /**
   * The fill.
   *
   * There is deliberately no pause and no thickening beat between reaching 100% and the fill
   * starting -- that gap is what read as a flicker. The moment the count lands, the block
   * starts crossing.
   *
   * The rail's width is a constant 12px and never changes, so nothing about the bar animates at
   * the end -- there is no width transition that could produce a flash on completion.
   */
  function finishBoot() {
    var el = overlay();
    if (!el || bootLeaving) { return; }
    bootLeaving = true;
    var elapsed = Date.now() - bootStartedAt;
    var wait = Math.max(0, BOOT_MIN_MS - elapsed);
    setProgress(100);
    setCaption(bootText('boot.entering', 'Entering'));

    // Wait for the count to actually arrive before covering the screen.
    //
    // Without this the fill starts while the display is still climbing -- measured 1265ms
    // early, so the block swept over a readout that had not finished. That is the same class
    // of fault as the pause this replaced: the ending has to follow the count, not race it.
    // There is still no dwell once it arrives; this waits on the value, not on a timer.
    window.setTimeout(function () {
      settleRoll(function () {
        // Straight into the fill: no intermediate class, no hold.
        el.classList.add('boot--leaving');
        enterCrossfade();
        window.setTimeout(function () {
          el.classList.add('boot--done');
        }, PHASE_FILL_MS);
        window.setTimeout(function () {
          if (el.parentNode) { el.parentNode.removeChild(el); }
          // Case 2: loading is finished and the page is in place, so the badge belongs on it.
          // Revealed here rather than at `boot--done`, because the overlay is still on screen
          // fading out until this point and the widget sits above it in the stack.
          revealCapHost();
        }, PHASE_FILL_MS + PHASE_FADE_DELAY_MS + PHASE_FADE_MS);
      });
    }, wait);
  }

  /**
   * Call back once the count has settled, or after the safety timeout.
   *
   * The timeout matters: if the roll were ever left in a state it could not leave, the screen
   * would otherwise stay up forever. Two and a half seconds is several times the travel time.
   */
  function settleRoll(done) {
    var started = Date.now();
    function poll() {
      if (rollSettled() || Date.now() - started > 2500) { done(); return; }
      window.requestAnimationFrame(poll);
    }
    window.requestAnimationFrame(poll);
  }

  /**
   * Mark the document so the page cross-fades in.
   *
   * Two classes rather than one so the browser has a style to transition FROM: the starting
   * state has to be in effect before the ending state is added, or there is nothing to
   * interpolate.
   *
   * The two classes land in the same tick, as the reference does it. An earlier attempt spread
   * them across the fill's duration to keep the page hidden behind the block -- which also hid
   * the block, because the overlay is rendered inside <body> and the hidden state applies to
   * `body`. The fill ran invisibly and the screen then cut in with no motion: the tile animation
   * appeared to have been removed.
   */
  function enterCrossfade() {
    var root = document.documentElement;
    root.classList.add('boot-entering');
    // Force the starting style to take effect. Without this the two class changes land in
    // the same style recalculation and the fade is skipped.
    void root.offsetHeight;
    root.classList.add('boot-entered');
  }

  function bootText(key, fallback) {
    var table = (boot && boot.strings) || {};
    return table[key] || fallback;
  }

  /**
   * Run every task concurrently and advance on each completion, as the reference does. A
   * task that rejects still counts: the screen must never stall on a failure, because the
   * page it is covering has already been sent.
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

    // No overlay means no loading screen to hide behind, so nothing will ever call the reveal at
    // the end of it. Without this the widget would stay hidden on a page that has no boot screen.
    if (!overlay()) {
      revealCapHost();
    }

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

  /* ------------------------------------------------------- verify page */

  /**
   * The dedicated verification flow, used on `/verify` only.
   *
   * A page that exists to be verified does not also need a loading screen, a consent dialog or an
   * announcement bar: it needs the challenge, a status line, and a way back. So this path skips
   * the boot sequence entirely and drives the widget directly.
   *
   * On success it navigates to the page the visitor was trying to reach, which the server has
   * already validated as a site-relative path. On failure it says so and stays put rather than
   * bouncing the visitor back to a page that will only send them here again.
   */
  function runVerifyPage() {
    var cfg = window.__SATAPI_VERIFY__;
    if (!cfg) { return false; }

    var status = document.querySelector('[data-verify-status]');
    var retry = document.querySelector('[data-verify-retry]');
    var strings = cfg.strings || {};
    var busy = false;

    // Shown at once on this page: it is the one place the widget IS the content, and there is no
    // loading screen here to hide it behind.
    revealCapHost();

    function say(message) {
      if (!status || !message) { return; }
      var active = status.querySelector('.i18n.is-active');
      if (active) { active.textContent = message; return; }
      status.textContent = message;
    }

    if (!cfg.enabled) {
      // The gate is off, so there is nothing to satisfy: go where they asked.
      say(strings.done || 'Verified. Returning…');
      window.location.replace(cfg.returnTo || '/');
      return true;
    }

    function attempt() {
      if (busy) { return; }
      busy = true;
      say(strings.working || 'Completing the check…');
      loadCapScript(cfg.endpoint)
        .then(function () { return solveWithCap(cfg.endpoint, cfg.siteKey); })
        .then(function (token) { return verifyPow(token); })
        .then(function () {
          say(strings.done || 'Verified. Returning…');
          // A replace, not an assign: the verification page should not sit in the history for
          // the back button to land on and immediately re-verify.
          window.location.replace(cfg.returnTo || '/');
        })
        .catch(function (error) {
          busy = false;
          say((strings.failed || 'The check could not be completed.') + ' (' + (error && error.message ? error.message : 'error') + ')');
        });
    }

    if (retry) { retry.addEventListener('click', attempt); }
    attempt();
    return true;
  }

  function init() {
    // The verify page is a flow step, not a destination: no boot screen, no dialogs.
    if (runVerifyPage()) { return; }
    try { initLanguageSwitch(); } catch (e) { /* a broken switcher must not stop the page */ }
    try { initAnnouncement(); } catch (e) { }
    try { initConsent(); } catch (e) { }
    try { initBoot(); } catch (e) {
      // The screen must never be left standing: reveal the page on any failure. The error is
      // re-thrown rather than swallowed: a bare catch here hid a real fault for a whole
      // debugging pass, because the only visible symptom was the overlay vanishing at once.
      var el = overlay();
      if (el) { el.classList.add('boot--leaving', 'boot--done'); }
      throw e;
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
