/* ==========================================================================
   newyear.js — the fireworks. Used by the New Year and Spring Festival themes.

   EFFECT ONLY. It owns a canvas and nothing else: the exit badge, the music controls
   and the opt-out cookie live in assets/theme.js, because they are the same for every
   theme and a copy of them in each effect is how two themes start disagreeing.

   The canvas is created here rather than in the markup: a theme that is off must not
   ship an empty <canvas> in every page's HTML, and an effect that fails to load should
   leave no trace at all.

   WHAT IT DELIBERATELY IS NOT
   ---------------------------
   Not a library, not a network request: a canvas and a requestAnimationFrame loop,
   about a hundred lines of ES5. If this file fails, the page simply has a plain
   background -- the right thing to lose.

   MOTION AND COST
   ---------------
   - `prefers-reduced-motion: reduce`: no canvas is created at all.
   - The loop stops when the tab is hidden and resumes when it is back.
   - At most MAX_PARTICLES particles exist; a burst is skipped rather than queued.
   ========================================================================== */
(function (window, document) {
  'use strict';

  var MAX_PARTICLES = 320;
  var BURST_MIN_MS = 1100;
  var BURST_MAX_MS = 2100;

  /* Warm palette: gold, amber, ember, and a white-hot core. */
  var COLOURS = ['#f2c14e', '#ffd97a', '#ff9d5c', '#ff7a63', '#fff3d6'];

  function init() {
    if (document.querySelector('canvas.fx')) { return; }
    /*
     * A film owns the first screen when one is there. Fireworks in the clip AND
     * fireworks on the canvas over it is two of the same thing, so the canvas stands
     * down -- unless the clip reports that it cannot play (assets/film.js dispatches
     * `season:film-failed`), in which case this is the fallback that keeps the page
     * from opening on an empty box. The attribute covers the other order: the clip may
     * have failed before this script ran.
     */
    var film = document.querySelector('[data-film]');
    if (film && !film.hasAttribute('data-film-failed')) {
      document.addEventListener('season:film-failed', init, { once: true });
      return;
    }
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduced) { return; }

    var canvas = document.createElement('canvas');
    canvas.className = 'fx';
    canvas.setAttribute('aria-hidden', 'true');
    document.body.appendChild(canvas);
    if (!canvas.getContext) { return; }

    var ctx = canvas.getContext('2d');
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    var width = 0;
    var height = 0;
    var particles = [];
    var nextBurst = 0;
    /* FALSE, not true. `start()` refuses to run while `running` is set, so initialising
       this to true made the first call a no-op: the canvas was sized and then left
       empty until the tab was hidden and shown again. Measured, not reasoned about. */
    var running = false;
    var raf = window.requestAnimationFrame || function (fn) { return window.setTimeout(fn, 16); };

    function resize() {
      width = window.innerWidth;
      height = window.innerHeight;
      canvas.width = Math.round(width * dpr);
      canvas.height = Math.round(height * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    function burst() {
      /* Higher in the sky than the fold: the fireworks are behind text, so a burst
         that starts at the reader's line height reads as a smudge. */
      var x = width * (0.16 + Math.random() * 0.68);
      var y = height * (0.08 + Math.random() * 0.42);
      var count = 46 + Math.round(Math.random() * 26);
      var speed = 1.9 + Math.random() * 2.1;
      var colour = COLOURS[Math.floor(Math.random() * COLOURS.length)];
      for (var i = 0; i < count; i++) {
        if (particles.length >= MAX_PARTICLES) { break; }
        var angle = (Math.PI * 2 * i) / count + Math.random() * 0.18;
        var power = speed * (0.55 + Math.random() * 0.75);
        particles.push({
          x: x,
          y: y,
          vx: Math.cos(angle) * power,
          vy: Math.sin(angle) * power,
          life: 1,
          decay: 0.008 + Math.random() * 0.012,
          colour: colour,
          size: 1 + Math.random() * 1.4
        });
      }
    }

    function step() {
      for (var i = particles.length - 1; i >= 0; i--) {
        var p = particles[i];
        p.x += p.vx;
        p.y += p.vy;
        p.vy += 0.045;          /* gravity */
        p.vx *= 0.985;
        p.vy *= 0.985;
        p.life -= p.decay;
        if (p.life <= 0) {
          particles.splice(i, 1);
          continue;
        }
        ctx.globalAlpha = Math.max(0, p.life) * 0.9;
        ctx.fillStyle = p.colour;
        ctx.fillRect(p.x, p.y, p.size, p.size);
      }
      ctx.globalAlpha = 1;
    }

    /*
     * One painted frame, without scheduling the next.
     *
     * Split out because `resize()` re-sizes the canvas, and sizing a canvas CLEARS it:
     * a window resize would otherwise blank the fireworks until the next animation
     * frame, which in a throttled or background tab is "until the reader comes back".
     */
    function paint(now) {
      /* A translucent wipe instead of a full clear: the trail is the whole look, and
         it costs one fillRect. Tuned so a particle fades over ~1s at 60fps. The wash
         is dark red so the canvas never reads as a grey veil over the red ground. */
      ctx.globalCompositeOperation = 'source-over';
      ctx.fillStyle = 'rgba(36, 6, 10, 0.22)';
      ctx.fillRect(0, 0, width, height);
      ctx.globalCompositeOperation = 'lighter';

      var when = typeof now === 'number' ? now : Date.now();
      if (when >= nextBurst) {
        burst();
        nextBurst = when + BURST_MIN_MS + Math.random() * (BURST_MAX_MS - BURST_MIN_MS);
      }
      step();
      ctx.globalCompositeOperation = 'source-over';
    }

    function frame(now) {
      if (!running) { return; }
      paint(now);
      raf(frame);
    }

    /* Resizing clears the canvas, so repaint at once rather than waiting for a frame
       the browser may never deliver (a background tab, a throttled compositor). */
    function onResize() {
      resize();
      paint();
    }

    /*
     * Start mid-flight.
     *
     * The first frame is drawn synchronously rather than left to the first rAF
     * callback: a browser throttles rAF until it can paint, so a page opened in a
     * background tab would show an empty rectangle until focus returned. Three bursts
     * at three ages make that first frame read as "fireworks going on" instead of as
     * one dot cluster that has not moved yet.
     */
    function start() {
      if (running) { return; }
      running = true;
      nextBurst = 0;
      ctx.globalCompositeOperation = 'lighter';
      burst();
      for (var a = 0; a < 12; a++) { step(); }
      burst();
      for (var b = 0; b < 18; b++) { step(); }
      burst();
      for (var c = 0; c < 5; c++) { step(); }
      ctx.globalCompositeOperation = 'source-over';
      nextBurst = Date.now() + BURST_MIN_MS;
      raf(frame);
    }

    function stop() { running = false; }

    resize();
    window.addEventListener('resize', onResize);
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { stop(); } else { start(); }
    });
    start();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}(window, document));
