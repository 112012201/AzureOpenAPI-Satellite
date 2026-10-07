/*
 * Build the raster icons that are not hand-authored in the repository:
 *   apple-touch-icon.png  (180x180, iOS home screen)
 *   icon-192.png          (192x192, web app manifest)
 *
 * Rendering path: the brand mark is drawn as inline SVG in a throwaway HTML
 * page, then rasterised by headless Edge/Chrome. This keeps the repository free
 * of a build toolchain (no sharp / canvas / ImageMagick dependency) while still
 * producing a crisp icon at any size — upscaling the 32x32 PNG would not.
 *
 * Usage:  node bin/build-icons.js
 * Env:    BROWSER=<path to msedge.exe|chrome.exe>  (auto-detected on Windows)
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const root = path.resolve(__dirname, '..');

/* The mark mirrors .brand__mark in index.html: a planet with an orbit ring and
   a satellite, on an opaque brand background so it reads on any wallpaper. */
function page(size, maskable) {
  // Maskable icons must keep their content inside a 40% radius safe zone.
  const scale = maskable ? 0.62 : 0.86;
  const bg = maskable ? '#0891b2' : '#0891b2';
  return `<!doctype html><html><head><meta charset="utf-8"><style>
html,body{margin:0;padding:0;width:${size}px;height:${size}px;background:${bg};overflow:hidden}
svg{display:block;width:${size}px;height:${size}px}
</style></head><body>
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Satellite Data API">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#0891b2"/><stop offset="1" stop-color="#155e75"/>
    </linearGradient>
  </defs>
  <rect width="64" height="64" fill="url(#bg)"/>
  <g transform="translate(32 32) scale(${scale}) translate(-32 -32)">
    <circle cx="32" cy="32" r="11" fill="#ffffff" opacity="0.95"/>
    <circle cx="28" cy="28" r="7" fill="#22d3ee" opacity="0.35"/>
    <g transform="rotate(-25 32 32)">
      <ellipse cx="32" cy="32" rx="24" ry="10" fill="none" stroke="#ffffff" stroke-width="2.6" opacity="0.85"/>
    </g>
    <g transform="rotate(-25 32 32)">
      <circle cx="56" cy="32" r="4" fill="#a78bfa" stroke="#ffffff" stroke-width="1.1"/>
    </g>
    <circle cx="32" cy="32" r="2.6" fill="#7c3aed"/>
  </g>
</svg>
</body></html>`;
}

function findBrowser() {
  if (process.env.BROWSER) return process.env.BROWSER;
  const candidates = [
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    '/usr/bin/chromium-browser',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
  ];
  return candidates.find((c) => fs.existsSync(c)) || null;
}

function render(browser, html, outPath, size) {
  const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'satapi-icon-'));
  const htmlPath = path.join(tmpDir, 'icon.html');
  fs.writeFileSync(htmlPath, html);
  const fileUrl = 'file:///' + htmlPath.replace(/\\/g, '/');
  execFileSync(
    browser,
    [
      '--headless=new',
      '--disable-gpu',
      '--no-sandbox',
      '--hide-scrollbars',
      '--force-device-scale-factor=1',
      `--window-size=${size},${size}`,
      `--screenshot=${outPath}`,
      fileUrl,
    ],
    { stdio: 'ignore' }
  );
  fs.rmSync(tmpDir, { recursive: true, force: true });
  if (!fs.existsSync(outPath)) throw new Error('renderer produced no output for ' + outPath);
}

const browser = findBrowser();
if (!browser) {
  console.error('No Chromium-based browser found. Set BROWSER=<path> and re-run.');
  process.exit(1);
}

const jobs = [
  { file: 'apple-touch-icon.png', size: 180, maskable: false },
  { file: 'icon-192.png', size: 192, maskable: true },
  { file: 'icon-512.png', size: 512, maskable: true },
];

for (const job of jobs) {
  const out = path.join(root, job.file);
  render(browser, page(job.size, job.maskable), out, job.size);
  console.log(`wrote ${job.file} (${fs.statSync(out).size} bytes, ${job.size}x${job.size}${job.maskable ? ', maskable-safe' : ''})`);
}
