# `endfield.hypergryph.com` — loading screen

Source read: `https://endfield.hypergryph.com/`, 2026-10-07.

The component lives in a Next.js chunk; the module is numbered `71272` in
`https://web.hycdn.cn/endfield/official-v4/_next/static/chunks/8858-b4305fa4f5be6c8a.js`.
The site is rebuilt on deploy, so that hash will change — search the chunk for the
`blur(${8 -` expression to find it again.

Transcribed below: the parts that shaped our implementation. Not a complete copy.

## The idea

Progress is not a timer. Every visual is a function of **how many registered tasks have actually
finished**, so the screen advances when work advances and never pretends. That is the one thing we
took wholesale, and it is why `consent.js` registers real tasks (a health round trip, the challenge,
a hand-over step) instead of stepping a bar on an interval.

## The progress model

```js
let [M, Z] = useState(0);   // M: tasks finished   Z: setter
let h = C.length;           // h: total tasks      C: the task list

// Every task runs concurrently; each completion increments the counter.
let A = () => { for (let e of (Z(0), C)) e().finally(() => Z(e => e + 1)); };

// The bar's value is the finished fraction, pushed through a spring rather than set directly.
useEffect(() => { spring.set(M / h * 100) }, [M, h, spring]);
```

## The hand-off

```js
useEffect(() => {
  if (M >= h) {
    onLeaving?.();                 // parent adds its "leaving" class
    leaving(true);
    setTimeout(() => store.setState({ loaded: true }), 1500);   // 1.5s to the route change
    let e = setTimeout(() => onFinished?.(), 2400);             // 2.4s to full teardown
    return () => clearTimeout(e);
  }
  leaving(false);
}, [M]);
```

Three things worth noting, because we reproduce all three:

1. the exit is **two staged timers**, not one — 1500ms then 2400ms
2. the class that starts the exit is added from the *parent*, so the child stays responsible only
   for its own count
3. the backdrop's defocus is continuous across the whole load, not an exit effect:

```jsx
animate={{ filter: `blur(${8 - M / h * 8}px)` }}           // blurred at 0%, sharp at 100%
animate={{ height: `${M / h * 100}%`, width: '100%' }}     // the bar, in landscape
```

## The exit, in CSS

```css
/* The overlay fades long after the fill has crossed, and the fade is delayed rather than eased. */
.leaving        { transition: opacity 1s 1.4s; }

/* The fill: a block that grows from the LEFT edge, on its own delay and its own curve. */
.leaving:after  {
  background: #fffa00;
  transform-origin: left;
  transform: scaleX(0);
  animation: fadeIn .6s cubic-bezier(1, 0, .7, 1) .5s forwards;
}
@keyframes fadeIn { 0% { transform: scaleX(0); } to { transform: scaleX(1); } }
```

`transform-origin: left` + `scaleX(0 → 1)` is the left-to-right wipe we adopted. Note the curve:
`cubic-bezier(1, 0, .7, 1)` starts dead slow and finishes fast — the opposite of an ease-out, and
deliberately so: the block appears to be *gathering* rather than arriving.

## Geometry

```css
/* landscape */
progress     { left: 0; bottom: 17.75rem; width: 82.4%; height: 2.5rem; }
/* portrait  */
progress     { left: 0; top: 0; width: 1.25rem; height: 100%; }
progressBar  { width: 100%; height: 0 -> 100%; }

value        { font-size: 4.375rem; }
symbol       { font-size: 3.25rem; }     /* the % sign, smaller than the number */
core:before  { height: 1.875rem; width: .5rem; border-radius: .25rem; background: #fffa00; }
```

The axis flips with orientation: a **vertical** rail in portrait, a **horizontal** bar in
landscape. Our rail is always vertical, which is why the exit wipe and the rail travel on
deliberately opposite axes — the rail grows downward, the wipe crosses rightward.

## What we did not take

* the `#fffa00` accents and the 1.5s / 2.4s exit timings (ours are 500ms / 400ms)
* the landscape variant — our rail is vertical at every width
* the spring; our roll is a bezier per segment, because the operator specified the four phases
  (startup / sustain / deceleration / rest) rather than a spring's feel
