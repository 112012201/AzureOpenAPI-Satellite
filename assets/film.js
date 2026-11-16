/* ==========================================================================
   film.js — the first-screen video on the landing page.

   WHAT THIS OWNS
   --------------
     1. WHICH ENCODING. The markup carries both URLs and no src, because a video
        element ignores <source media>: the handler is the only thing that can decide
        between 720p and 1080p. A phone gets `sm`; so does anything that asked to save
        data, whatever its width.
     2. STARTING IT. muted + playsinline + loop is the one combination browsers
        autoplay without a gesture. If even that is refused (iOS low-power mode does
        refuse it), the poster stays -- the same frame -- and the first interaction
        retries.
     3. STOPPING IT. The band carries its own pause button (WCAG 2.2.2: anything that
        moves for more than five seconds on its own needs a mechanism to stop it, and
        a looped clip runs forever). The button is IN the band, so it scrolls away with
        it and never joins the fixed corner controls. `prefers-reduced-motion` starts
        the band paused, so the mechanism is the default for whoever asked for it.
     4. NOT BURNING BATTERY FOR NOTHING. Paused once the band has been scrolled past
        and while the tab is hidden, resumed when it comes back: a background video
        still decoding behind the docs page is a phone that gets warm for no reason.
     5. THE FAILURE SIGNAL. If the file cannot be played (missing, blocked, codec
        refused), `data-film-failed` is set and `season:film-failed` is dispatched.
        The theme's canvas effect listens for exactly that and starts drawing instead,
        so the first screen is never an empty box.
     6. THE SLOGAN. The band's one line of copy types itself in 26 languages, one after
        another, with the language's name beside it in the reader's own language --
        see createTyper(). The strings come from the server as JSON, so the rotation is
        translated data, not a second copy of the copy.

   WHY THERE IS NO FADE-IN
   -----------------------
   The <video> carries poster=..., so the element paints the still itself before the
   first frame exists. A fade would spend its first 300ms showing nothing, and this
   band is the largest thing on the page.
   ========================================================================== */
(function (window, document) {
  'use strict';

  var SMALL = '(max-width: 820px)';
  var REDUCED = '(prefers-reduced-motion: reduce)';

  function matches(query) {
    try {
      return !!(window.matchMedia && window.matchMedia(query).matches);
    } catch (e) {
      return false;
    }
  }

  function saveData() {
    var connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    return !!(connection && connection.saveData);
  }

  /*
   * Which encoding, with the IV that decrypts it. The markup carries both ciphertext
   * URLs and no src, because a video element ignores <source media>: this is the only
   * thing that can decide between 720p and 1080p. A phone gets `sm`; so does anything
   * that asked to save data, whatever its width.
   */
  function pick(video) {
    var small = matches(SMALL) || saveData();
    var url = small ? String(video.getAttribute('data-src-sm') || '') : '';
    var iv = small ? String(video.getAttribute('data-iv-sm') || '') : '';
    if (!url) {
      url = String(video.getAttribute('data-src-lg') || '')
        || String(video.getAttribute('data-src-sm') || '');
      iv = String(video.getAttribute('data-iv-lg') || '')
        || String(video.getAttribute('data-iv-sm') || '');
    }
    return { url: url, iv: iv };
  }

  function announceFailure(band) {
    band.setAttribute('data-film-failed', '1');
    var event;
    try {
      event = new CustomEvent('season:film-failed', { bubbles: true });
    } catch (e) {
      event = document.createEvent('Event');
      event.initEvent('season:film-failed', true, true);
    }
    /* The effect script may not have run yet (both are deferred, and either can be
       first), so the attribute above is what it reads when it arrives. */
    document.dispatchEvent(event);
  }

  /*
   * The typewriter.
   *
   * Reads the rotation the server wrote into
   * <script type="application/json" data-film-slogans>, types a phrase out one character
   * at a time, holds it, deletes it and moves to the next language. The caret and the
   * language label follow, and the label is in the READER's language, not the slogan's.
   *
   * Decoration for a screen reader: the slogan is aria-hidden and the accessible copy is
   * the static line beside it, so nothing here is announced. It stops when the visitor
   * pauses the band, when the band is scrolled away and while the tab is hidden -- a loop
   * that keeps repainting text behind a hidden page is a phone that gets warm for nothing.
   *
   * prefers-reduced-motion: no typing at all. The server-rendered sentence IS the finished
   * state and the label is already under it, so there is nothing to take away.
   *
   * The first phrase is already on screen when this runs (the server wrote it into the
   * markup), so it starts by HOLDING rather than by typing: no blank flash on load.
   */
  function createTyper(band) {
    var node = band.querySelector('[data-film-slogan]');
    var label = band.querySelector('[data-film-lang]');
    var data = band.querySelector('[data-film-slogans]');
    if (!node || !data || matches(REDUCED)) {
      return null;
    }
    var list;
    try {
      list = JSON.parse(String(data.textContent || '[]'));
    } catch (e) {
      list = [];
    }
    if (!list || !list.length) {
      return null;
    }

    var TYPE_MS = 64;
    var HOLD_MS = 2300;
    var ERASE_MS = 26;
    var GAP_MS = 420;

    var at = 0;
    var chars = 0;
    var timer = 0;
    var paused = false;
    var phrase = list[0];

    function paint() {
      node.textContent = phrase.text.slice(0, chars);
      node.dir = phrase.dir === 'rtl' ? 'rtl' : 'ltr';
      if (label) {
        label.textContent = chars > 0 ? phrase.label : '';
        label.dir = node.dir;
      }
    }

    function schedule(ms, fn) {
      window.clearTimeout(timer);
      timer = window.setTimeout(fn, ms);
    }

    function step() {
      if (paused) {
        return;
      }
      if (chars < phrase.text.length) {
        chars += 1;
        paint();
        /* A hair of jitter: a perfectly even cadence reads as a loading bar. */
        schedule(TYPE_MS + (chars % 3) * 14, step);
        return;
      }
      schedule(HOLD_MS, erase);
    }

    function erase() {
      if (paused) {
        return;
      }
      if (chars > 0) {
        chars -= 1;
        paint();
        schedule(ERASE_MS, erase);
        return;
      }
      at = (at + 1) % list.length;
      phrase = list[at];
      schedule(GAP_MS, step);
    }

    function setPaused(next) {
      next = !!next;
      if (next === paused) {
        return;
      }
      paused = next;
      if (paused) {
        window.clearTimeout(timer);
      } else {
        schedule(400, step);
      }
    }

    phrase = list[0];
    chars = phrase.text.length;
    paint();
    schedule(HOLD_MS, erase);

    return {
      setPaused: setPaused,
      snapshot: function () {
        return {
          index: at,
          chars: chars,
          length: phrase.text.length,
          label: label ? label.textContent : '',
          dir: node.dir,
          text: phrase.text
        };
      }
    };
  }

  function init() {
    var band = document.querySelector('[data-film]');
    if (!band) {
      return;
    }
    var video = band.querySelector('[data-film-video]');
    if (!video) {
      return;
    }

    var button = band.querySelector('[data-film-toggle]');
    var userPaused = matches(REDUCED);
    var inView = true;
    var typer = createTyper(band);

    /*
     * How tall the band is: the rest of the first screen, whatever is above it.
     *
     * That is not a constant. The announcement bar and the endpoint notice sit above
     * the header and are part of "the first screen", so a fixed
     * `100svh - 58px` pushed the bottom of the band -- and the scroll hint on it --
     * below the fold the moment either strip rendered (measured: 176px of chrome on
     * the live address). So the offset is measured and handed to CSS.
     */
    function fit() {
      var top = band.getBoundingClientRect().top + (window.pageYOffset || 0);
      band.style.setProperty('--film-top', Math.max(0, Math.round(top)) + 'px');
    }

    function label(playing) {
      band.setAttribute('data-film-state', playing ? 'playing' : 'paused');
      if (!button) {
        return;
      }
      /* aria-pressed describes the state the button is IN (paused), and the accessible
         name describes what pressing it DOES -- so the name flips with the state. */
      button.setAttribute('aria-pressed', playing ? 'false' : 'true');
      var text = button.getAttribute(playing ? 'data-label-pause' : 'data-label-play') || '';
      button.setAttribute('aria-label', text);
      button.setAttribute('title', text);
    }

    function start() {
      if (userPaused) {
        return;
      }
      /* Nothing to play until the ciphertext has been decrypted and the element has a
         src; the retry paths below can fire before that. */
      if (!video.getAttribute('src')) {
        return;
      }
      if (typer) {
        typer.setPaused(false);
      }
      /* muted is an attribute AND a property: iOS reads the property. */
      video.muted = true;
      var attempt = video.play();
      if (attempt && attempt.then) {
        attempt.then(function () {
          label(true);
        }).catch(function () {
          /* Refused: the poster is on screen and it is the same frame, so there is
             nothing broken to explain. The pause button stays usable and the first
             gesture retries. */
          band.setAttribute('data-film-state', 'blocked');
        });
      } else {
        label(true);
      }
    }

    function stop() {
      video.pause();
      label(false);
      if (typer) {
        typer.setPaused(true);
      }
    }

    video.addEventListener('error', function () {
      announceFailure(band);
    });

    video.setAttribute('playsinline', '');
    video.preload = 'auto';
    var source = pick(video);
    if (!source.url || !source.iv) {
      announceFailure(band);
      return;
    }
    fit();
    /* Re-measure when the layout above the band can have changed: a resize, a zoom,
       late web fonts, or a visitor closing the announcement bar. */
    window.addEventListener('resize', fit);
    window.addEventListener('orientationchange', fit);
    window.setTimeout(fit, 1200);
    window.setTimeout(fit, 3600);
    if (userPaused) {
      band.setAttribute('data-film-state', 'paused');
    }

    /* The clip is ciphertext on the origin. Decrypt it, then hand the element a blob
       URL -- the poster is what is on screen until then, and it is the same frame. */
    if (!(window.SatApiMedia && window.SatApiMedia.load)) {
      announceFailure(band);
      return;
    }
    window.SatApiMedia.load(source.url, source.iv, 'video/mp4').then(function (blobUrl) {
      video.src = blobUrl;
      if (!userPaused) {
        start();
      }
    }).catch(function () {
      announceFailure(band);
    });

    if (button) {
      button.addEventListener('click', function () {
        if (video.paused) {
          userPaused = false;
          start();
        } else {
          userPaused = true;
          stop();
        }
      });
    }

    /* One retry per gesture, and only while nothing is playing. */
    var retry = function () {
      window.removeEventListener('pointerdown', retry, true);
      window.removeEventListener('keydown', retry, true);
      window.removeEventListener('touchstart', retry, true);
      if (video.paused && !userPaused) {
        start();
      }
    };
    window.addEventListener('pointerdown', retry, true);
    window.addEventListener('keydown', retry, true);
    window.addEventListener('touchstart', retry, true);

    /*
     * Pause what nobody is looking at. threshold 0, not 0.5: the band is taller than
     * the viewport, so "half visible" is not a state it has.
     */
    if (typeof window.IntersectionObserver === 'function') {
      new window.IntersectionObserver(function (entries) {
        for (var i = 0; i < entries.length; i++) {
          inView = entries[i].isIntersecting;
        }
        if (!inView && !video.paused) {
          video.pause();
        } else if (inView && video.paused && !userPaused) {
          start();
        }
        if (typer) {
          typer.setPaused(!inView || userPaused || document.visibilityState === 'hidden');
        }
      }, { threshold: 0 }).observe(band);
    }

    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') {
        if (!video.paused) {
          video.pause();
        }
      } else if (video.paused && inView && !userPaused) {
        start();
      }
      if (typer) {
        typer.setPaused(document.visibilityState === 'hidden' || !inView || userPaused);
      }
    });

    /* Exposed the way theme.js exposes the rotation, so the behaviour can be
       exercised without a video device. */
    window.SatApiFilm = {
      src: function () { return String(video.currentSrc || video.src || ''); },
      small: function () { return matches(SMALL) || saveData(); },
      state: function () { return String(band.getAttribute('data-film-state') || ''); },
      paused: function () { return !!video.paused; },
      slogan: typer ? typer.snapshot : function () { return null; }
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}(window, document));
