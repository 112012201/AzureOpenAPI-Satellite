/* ==========================================================================
   christmas.js — the night sky and the treeline.

   EFFECT ONLY (see assets/theme.js for the badge and the music controls). What it
   draws, back to front:

     the sky      a static gradient, painted once per resize into an offscreen layer
     the stars    ~150 points, each with its own twinkle phase
     the treeline  conifer silhouettes along the bottom, with warm lights on them

   The sky and the trees never change between frames, so they are painted ONCE into an
   offscreen canvas and blitted each frame. Only the stars twinkle. That keeps the loop
   to one drawImage plus ~180 tiny fills, which is what makes it safe to leave running
   behind a text page.

   Reduced motion: no canvas at all. The CSS gradients in christmas.css still give the
   page a night sky, so the theme survives without the animation.
   ========================================================================== */
(function (window, document) {
  'use strict';

  var STAR_COUNT = 150;
  var TREE_COUNT = 7;
  var LIGHT_COLOURS = ['#d8b45a', '#c0483c', '#f4dfa4', '#e8825a'];

  function init() {
    if (document.querySelector('canvas.fx')) { return; }
    /* The film owns the first screen when one is there -- see the note in newyear.js.
       This canvas is the fallback for a clip that could not play. */
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
    var running = false;
    var raf = window.requestAnimationFrame || function (fn) { return window.setTimeout(fn, 16); };

    var sky = document.createElement('canvas');
    var skyCtx = sky.getContext('2d');
    var stars = [];
    var trees = [];
    var lights = [];

    /* A deterministic-ish scatter: Math.random is fine here (nobody compares two page
       loads), but the trees' positions are quantised so the treeline has rhythm
       instead of clumping. */
    function seed() {
      stars = [];
      for (var i = 0; i < STAR_COUNT; i++) {
        stars.push({
          x: Math.random() * width,
          y: Math.random() * height * 0.62,
          r: Math.random() < 0.82 ? 0.8 : 1.6,
          phase: Math.random() * Math.PI * 2,
          speed: 0.6 + Math.random() * 1.4
        });
      }

      trees = [];
      lights = [];
      var baseY = height;
      for (var t = 0; t < TREE_COUNT; t++) {
        var slot = (t + 0.5) / TREE_COUNT;
        var x = width * slot + (Math.random() - 0.5) * (width / TREE_COUNT) * 0.35;
        /* A treeline, not a forest: at 0.20-0.36 of the viewport the trees crowded the
           content they sit behind. 0.13-0.22 keeps them as a horizon. */
        var tall = height * (0.13 + Math.random() * 0.09);
        var wide = tall * (0.30 + Math.random() * 0.08);
        trees.push({ x: x, base: baseY - height * 0.02, height: tall, width: wide });
        var count = 5 + Math.floor(Math.random() * 5);
        for (var k = 0; k < count; k++) {
          var along = 0.15 + Math.random() * 0.8;
          lights.push({
            tree: t,
            along: along,
            side: (Math.random() - 0.5) * 0.86,
            colour: LIGHT_COLOURS[Math.floor(Math.random() * LIGHT_COLOURS.length)],
            phase: Math.random() * Math.PI * 2,
            speed: 0.5 + Math.random() * 1.1,
            r: 1.2 + Math.random() * 1.1
          });
        }
      }
    }

    function paintSky() {
      sky.width = canvas.width;
      sky.height = canvas.height;
      skyCtx.setTransform(dpr, 0, 0, dpr, 0, 0);

      var gradient = skyCtx.createLinearGradient(0, 0, 0, height);
      gradient.addColorStop(0, '#051019');
      gradient.addColorStop(0.45, '#0a1f2c');
      gradient.addColorStop(0.78, '#0d2a2a');
      gradient.addColorStop(1, '#0b1c18');
      skyCtx.fillStyle = gradient;
      skyCtx.fillRect(0, 0, width, height);

      /* Trees: layered triangles, darkest at the back. A conifer is a stack of three,
         each narrower than the one below -- that silhouette is what makes it read as a
         Christmas tree rather than as a generic triangle. */
      for (var i = 0; i < trees.length; i++) {
        var tree = trees[i];
        var tiers = 3;
        for (var tier = 0; tier < tiers; tier++) {
          var shrink = 1 - tier * 0.26;
          var w = tree.width * shrink;
          var h = tree.height * 0.52;
          var apexY = tree.base - tree.height + tier * (tree.height * 0.24);
          skyCtx.beginPath();
          skyCtx.moveTo(tree.x, apexY);
          skyCtx.lineTo(tree.x - w / 2, apexY + h);
          skyCtx.lineTo(tree.x + w / 2, apexY + h);
          skyCtx.closePath();
          skyCtx.fillStyle = tier === 2 ? '#123f30' : (tier === 1 ? '#0f3628' : '#0c2c21');
          skyCtx.fill();
        }
        /* A trunk stub, and the snow line along the bottom of the last tier. */
        skyCtx.fillStyle = '#0a1a14';
        skyCtx.fillRect(tree.x - tree.width * 0.045, tree.base - tree.height * 0.06, tree.width * 0.09, tree.height * 0.06);
      }

      /* Ground line: a dark band so the trees are standing on something. */
      skyCtx.fillStyle = '#08130f';
      skyCtx.fillRect(0, height * 0.985, width, height * 0.015);
    }

    function resize() {
      width = window.innerWidth;
      height = window.innerHeight;
      canvas.width = Math.round(width * dpr);
      canvas.height = Math.round(height * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      seed();
      paintSky();
      paint(performance.now ? performance.now() : Date.now());
    }

    function paint(now) {
      var t = (typeof now === 'number' ? now : Date.now()) / 1000;
      ctx.clearRect(0, 0, width, height);
      ctx.drawImage(sky, 0, 0, width, height);

      /* Stars: a slow sine on opacity, each with its own phase so the sky breathes
         instead of blinking in unison. */
      for (var i = 0; i < stars.length; i++) {
        var s = stars[i];
        var twinkle = 0.45 + 0.55 * (0.5 + 0.5 * Math.sin(t * s.speed + s.phase));
        ctx.globalAlpha = twinkle;
        ctx.fillStyle = '#f4f8f4';
        ctx.fillRect(s.x, s.y, s.r, s.r);
      }

      /* The lights on the trees: warm, small, slower than the stars. */
      ctx.globalCompositeOperation = 'lighter';
      for (var k = 0; k < lights.length; k++) {
        var l = lights[k];
        var tree = trees[l.tree];
        if (!tree) { continue; }
        var y = tree.base - tree.height * (1 - l.along);
        var spread = tree.width * 0.5 * Math.max(0.12, l.along);
        var x = tree.x + l.side * spread;
        var glow = 0.5 + 0.5 * Math.sin(t * l.speed + l.phase);
        ctx.globalAlpha = 0.35 + 0.65 * glow;
        ctx.fillStyle = l.colour;
        ctx.beginPath();
        ctx.arc(x, y, l.r * (0.8 + 0.5 * glow), 0, Math.PI * 2);
        ctx.fill();
      }
      ctx.globalAlpha = 1;
      ctx.globalCompositeOperation = 'source-over';
    }

    function frame(now) {
      if (!running) { return; }
      paint(now);
      raf(frame);
    }

    /* Resizing clears the canvas, so repaint at once rather than waiting for a frame
       the browser may never deliver. */
    function onResize() {
      resize();
      paint();
    }

    function start() {
      if (running) { return; }
      running = true;
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
