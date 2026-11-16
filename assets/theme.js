/* ==========================================================================
   theme.js — the chrome every holiday theme shares.

   Three jobs, all of them the same whichever theme is running:

     1. the badge's way out (bottom-right)
     2. the music controls (bottom-left): play / pause / replay, rotating tracks
     3. damage control for a cached copy -- if this visitor already refused THIS theme,
        the theme is stripped from the page instead of being shown again

   The corner controls' scroll gate is NOT here: it has to work on the plain site too,
   where this file is never loaded, so it lives in assets/app.js.

   WHY THE MUSIC LIVES HERE AND NOT IN AN EFFECT FILE
   --------------------------------------------------
   Both the New Year and the Christmas themes play music, and the Spring Festival one
   does too. An effect file (newyear.js, christmas.js) owns a canvas and nothing else;
   the moment a second effect needed a player, a copy of it would have appeared and the
   two would have drifted.

   AUTOPLAY
   --------
   Browsers refuse audible playback without a gesture. So: try once on load; if the
   promise rejects, show the hint and wait for the first pointer/key/touch anywhere on
   the page. The buttons always work, which is the part that matters -- a visitor who
   does not want music must be able to stop it.
   ========================================================================== */
(function (window, document) {
  'use strict';

  var boot = window.__SATAPI_BOOT__ || {};
  var info = boot.theme || {};
  var COOKIE = info.cookie || 'satapi-season';
  var NAME = String(info.name || 'default');
  var TRACKS = Object.prototype.toString.call(info.tracks) === '[object Array]' ? info.tracks : [];
  var MEDIA = info.media && typeof info.media === 'object' ? info.media : {};
  var STRINGS = info.strings || {};

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

  /* --------------------------------------------------------------- leave */

  function initExit() {
    var button = document.querySelector('[data-season-exit]');
    if (!button) { return; }

    button.addEventListener('click', function () {
      /*
       * Two ways to leave, and they are not interchangeable:
       *
       *   ?season=...   the preview is pinned by the URL, so reloading would show the
       *                 same theme again. The parameter is dropped instead.
       *   a window      the theme came from the calendar, so the cookie records that
       *                 THIS theme was refused. It is not "no themes ever": the next
       *                 holiday still asks.
       */
      var search = String(window.location.search || '');
      if (search.indexOf('season=') !== -1) {
        var url = new URL(window.location.href);
        url.searchParams.delete('season');
        window.location.replace(url.toString());
        return;
      }
      writeCookie(COOKIE, NAME === 'default' ? 'default' : NAME, 400 * 86400);
      window.location.reload();
    });
  }

  /* A visitor who refused this theme can still be served it from a shared cache -- the
     cache cannot key on a cookie. Correct the paint rather than reload: the class and
     the canvas are the whole theme, and the controls go with them. */
  function honourOptOut() {
    if (readCookie(COOKIE) !== NAME || NAME === 'default') { return false; }
    var classes = String(document.body.className || '').split(/\s+/);
    var kept = [];
    for (var i = 0; i < classes.length; i++) {
      if (classes[i] && classes[i] !== NAME) { kept.push(classes[i]); }
    }
    document.body.className = kept.join(' ');
    document.body.setAttribute('data-season', 'default');
    var nodes = document.querySelectorAll('canvas.fx, [data-season-badge], [data-theme-music], [data-film]');
    for (var j = 0; j < nodes.length; j++) {
      if (nodes[j].parentNode) { nodes[j].parentNode.removeChild(nodes[j]); }
    }
    return true;
  }

  /* ---------------------------------------------------------------- music */

  function initMusic() {
    if (TRACKS.length === 0) { return; }
    var bar = document.querySelector('[data-theme-music]');
    if (!bar) { return; }
    var toggle = bar.querySelector('[data-music-toggle]');
    var replay = bar.querySelector('[data-music-replay]');
    var hint = bar.querySelector('[data-music-hint]');
    if (!toggle) { return; }

    var audio = document.createElement('audio');
    audio.preload = 'none';
    audio.volume = 0.55;
    audio.setAttribute('playsinline', '');
    document.body.appendChild(audio);

    /* A random first track, then the list in order, wrapping. "Two tracks, random then
       rotate" is what the operator asked for on the New Year theme. */
    var index = Math.floor(Math.random() * TRACKS.length);
    var userPaused = false;
    var started = false;
    var pending = null;

    /*
     * The tracks are ciphertext (see assets/media.js and lib/Support/Media.php). Loading
     * one is a fetch + decrypt + blob URL, so it is asynchronous: `pending` is the
     * promise the next start() waits on. The IV travels in the boot payload, keyed by the
     * URL, because the rotation only passes URLs around.
     */
    function load(at) {
      index = ((at % TRACKS.length) + TRACKS.length) % TRACKS.length;
      var url = String(TRACKS[index] || '');
      var meta = MEDIA[url] || {};
      pending = (window.SatApiMedia && window.SatApiMedia.load)
        ? window.SatApiMedia.load(url, meta.iv, meta.mime || 'audio/mp4')
        : Promise.reject(new Error('no media api'));
      pending.catch(function () { /* start() reports it; keep the console clean */ });
      return pending;
    }

    function label(playing) {
      toggle.textContent = playing ? (STRINGS.pause || 'Pause') : (STRINGS.play || 'Play');
      toggle.setAttribute('aria-pressed', playing ? 'true' : 'false');
      bar.setAttribute('data-state', playing ? 'playing' : 'idle');
    }

    function say(text) {
      if (!hint) { return; }
      hint.textContent = text || '';
      hint.hidden = !text;
    }

    function play() {
      var attempt = audio.play();
      if (attempt && attempt.then) {
        attempt.then(function () {
          started = true;
          label(true);
          say('');
        }).catch(function () {
          /* Blocked: the answer is the button, and a hint that says so. */
          bar.setAttribute('data-state', 'blocked');
          say(STRINGS.hint || '');
        });
      } else {
        started = true;
        label(true);
      }
    }

    function start() {
      userPaused = false;
      if (!pending) { load(index); }
      if (!pending) { return; }
      pending.then(function (blobUrl) {
        if (userPaused) { return; }
        if (audio.src !== blobUrl) { audio.src = blobUrl; }
        play();
      }).catch(function () {
        /* Decryption failed: no key, no file, or no Web Crypto. Silent then, and the
           button still offers a retry (a later click re-runs load()). */
        pending = null;
        bar.setAttribute('data-state', 'error');
        say('');
        label(false);
      });
    }

    function pause() {
      userPaused = true;
      audio.pause();
      label(false);
      say('');
    }

    toggle.addEventListener('click', function () {
      if (audio.paused) { start(); } else { pause(); }
    });

    if (replay) {
      replay.addEventListener('click', function () {
        try { audio.currentTime = 0; } catch (e) { /* not loaded yet */ }
        start();
      });
    }

    /* One rotation step. With a single track this is a loop; that is intended -- the
       Spring Festival theme has one song per period. */
    audio.addEventListener('ended', function () {
      load(index + 1);
      if (!userPaused) { start(); } else { label(false); }
    });

    /*
     * A file that cannot be decoded must not own the player. It used to: `error` set a
     * state and stopped, so one bad track silenced the whole period (there WAS one --
     * a Christmas file truncated mid-encode, measured at 107 seconds with no moov box).
     * Now a failure advances to the next track, and gives up only after every track in
     * the rotation has failed once, which is a deployment with no audio at all.
     */
    var failures = 0;
    audio.addEventListener('error', function () {
      failures += 1;
      if (failures < TRACKS.length) {
        load(index + 1);
        if (!userPaused) { start(); }
        return;
      }
      bar.setAttribute('data-state', 'error');
      say('');
      label(false);
    });

    load(index);

    /* First attempt, then the first gesture. `once` so a page full of clicks is not a
       page full of play() calls. */
    start();
    var resume = function () {
      if (!userPaused && audio.paused) { start(); }
      window.removeEventListener('pointerdown', resume, true);
      window.removeEventListener('keydown', resume, true);
      window.removeEventListener('touchstart', resume, true);
    };
    window.addEventListener('pointerdown', resume, true);
    window.addEventListener('keydown', resume, true);
    window.addEventListener('touchstart', resume, true);

    /* Exposed for the self-test: the rotation is the part worth exercising without a
       real audio device. */
    window.SatApiThemeMusic = {
      tracks: TRACKS,
      index: function () { return index; },
      next: function () { load(index + 1); return index; }
    };
  }

  function init() {
    initExit();
    if (honourOptOut()) { return; }
    initMusic();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}(window, document));
