# Loading-animation reference material

This folder holds the **reference implementations** the loading screen was built from, kept apart
from the production code on purpose.

The loading screen is not an invented design: it is a reconstruction of how two sites do it, and
the rules in `assets/md3.css` and the timings in `assets/consent.js` exist because of specific
values read out of those sites. Those values used to live only in comments inside the production
files and in `OPERATIONS.md`, which meant a reader of the CSS could not tell which numbers were
ours and which were borrowed.

So: **the borrowed numbers live here.** The production files carry a pointer to this folder and
their own measurements, not a retelling of someone else's implementation.

| File | Source | What it supplied |
|---|---|---|
| [`endfield-hypergryph.com.md`](endfield-hypergryph.com.md) | `https://endfield.hypergryph.com/` | Task-driven progress, the four-phase hand-off, the left-to-right fill block |
| [`tongdiaojihua.com.md`](tongdiaojihua.com.md) | `https://tongdiaojihua.com/` | The constant-width rail, the pixel-length fill, the eased exit, the delayed overlay fade |

## What is ours and what is borrowed

**Borrowed, and kept in this folder:**

* the four-segment speed profile and the `cubic-bezier(.76,0,.24,1)` fill curve (tongdiaojihua)
* the constant width rail and the pixel-length fill that also positions the readout
* the delayed overlay fade (`.4s ease-out .5s`)
* the task-completion model -- every visual driven by real work finishing (endfield)

**Ours, and measured in this repository:**

* the four-segment *share* values (`0.28 / 0.38 / 0.22 / 0.12`), which were searched rather than
  copied -- see the note in `assets/md3.css`
* the 300ms hold at 100% (asked for by the operator, 0.2-0.4s)
* the decision that the page never fades, because the overlay is opaque and lives inside `<body>`
* the abandonment of the page fade, with the luminance measurements that justified it

## A caution about this folder

These are transcriptions of the parts that mattered, read from the sites on **2026-10-07**, not
complete copies. Both are built bundles: the class names are minified and change on every deploy,
so a URL quoted here may 404 later and the line numbers will certainly drift. Treat these files as
a record of *what was observed and why it was adopted*, and re-read the live sites if you need to
build on them again. Nothing in the production code loads or depends on anything in this folder.
