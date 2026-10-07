# `tongdiaojihua.com` — loading screen

Source read: `https://tongdiaojihua.com/`, 2026-10-07, from two built assets:

* `/assets/loading-Ds8GNncC.js` — a Pinia store
* `/assets/index-CnsoXQYN.css` — the styles

Both are hashed build outputs, so those names change on deploy. Transcribed below: the parts that
shaped our implementation. Not a complete copy.

## The idea

A task counter with a **minimum visible time**: progress is driven by completed tasks, but the
screen will not go away until it has been up for at least one second. The point is that a load
which finishes instantly should not flash — the visitor gets a stable thing to look at rather than
a strobe.

```js
X = 1e3;   // the minimum display time, 1000ms

l = async () => {
  let t = Math.max(0, e.value - 1);              // one task fewer is now outstanding
  if (t === 0) {                                  // that was the last one
    let e = r.value == null ? X : Date.now() - r.value,
        t = Math.max(0, X - e);                   // how much of the second is left
    t > 0 && await new Promise(e => setTimeout(e, t));
    o(100, { allowDecrease: !1 });                 // snap to 100, never backwards
    r.value = null;
  }
  e.value = t;
};

createTaskProgress(e, t = 100) -> { step, completeTask, completeAll }
```

Two details we took:

* `allowDecrease: false` — the display may only go up. A late task resolving out of order cannot
  pull the bar backwards.
* the minimum is enforced by **waiting**, not by slowing the bar down. The bar arrives at its true
  value immediately; the screen is what lingers.

## The rail: constant width, pixel-length fill

This is the part that fixed our bar's proportions.

```css
.progress-bar-container {
  width: 12px;              /* CONSTANT. It does not thicken at the end. */
  height: 100%;
}
.progress-fill {
  width: 100%;
  height: var(--progress);  /* a PIXEL length, not a percentage */
  background-color: var(--c-primary);
  transition: height .2s ease-out;
}
.progress-info {
  /* The readout is positioned by the same value that fills the rail, so the number rides the
     top of the fill without a second source of truth. */
  left: 36px;
  top: max(10vh, min(var(--progress), calc(100vh - 150px)));
  transition: top .2s ease-out;
}
.progress-number {
  color: var(--c-primary);
  letter-spacing: -1px;
  font-size: 56px;
  font-weight: 900;
  line-height: 1;
}
```

Three consequences we adopted directly:

1. **The rail never changes width.** Ours was 6px doubling to 12px at 100%, and that doubling was a
   separate beat that read as a flicker. It is now a constant 12px, with no width transition at all.
2. **The fill is a length, not a ratio.** `height: var(--progress)` with a px value, written to the
   root element, which is what lets the readout be positioned by the same number.
3. **The readout is clamped** into a band (`10vh` above, `100vh - 150px` below) so it cannot run off
   the top or bottom on a short viewport.

## The exit: a left-to-right wipe, then a delayed fade

```css
.app-loading-overlay {
  color: #fff;
  z-index: 9999;
  opacity: 1;
  --c-text-muted: #6b6b6b;
  --c-divider: #ffffff26;
  background-color: #151515;
  width: 100vw;
  height: 100vh;
  transition: opacity .4s ease-out .5s;   /* the fade, DELAYED by half a second */
  position: fixed;
  inset: 0;
  overflow: hidden;
}

/* The wipe. A full-bleed block, scaled from the left edge. */
.app-loading-overlay:before {
  content: "";
  background-color: var(--c-primary);
  z-index: 10000;                          /* above the overlay's own content */
  transform-origin: 0 0;                   /* the LEFT edge */
  transition: transform .5s cubic-bezier(.76, 0, .24, 1);
  position: absolute;
  inset: 0;
  transform: scaleX(0);
}
.app-loading-overlay.--exiting:before { transform: scaleX(1); }
```

`cubic-bezier(.76, 0, .24, 1)` is the curve we use for our own fill. It is an ease-in-out with an
unusually flat start and finish: slow at the left edge, fast through the middle, slow again at the
right. The measured quadrant split is `0.428 / 0.572 / 0.000 / 0.000` — i.e. the first half of the
block covers a little less ground than the second, which is what "left slow, right fast" means.

## What we did not take

* the `#151515` and `--c-primary` palette — ours comes from the MD3 role tokens
* the 1000ms minimum display; ours is `BOOT_MIN_MS = 2000`, because our four-segment roll needs
  longer to be legible than a single eased fill does
* the `translate`-free approach to the readout: ours is positioned by the fill length exactly as
  theirs is, but clamped with our own spacing tokens
