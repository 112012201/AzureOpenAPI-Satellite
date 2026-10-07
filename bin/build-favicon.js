/*
 * Build a multi-size favicon.ico from the existing PNG favicons.
 *
 * Why PNG-in-ICO: every browser that still requests /favicon.ico (all of them,
 * plus crawlers and RSS readers) supports PNG-compressed ICO entries. Avoiding a
 * BMP encoder keeps this dependency-free — the PNGs are already in the repo.
 *
 * ICONDIR          6 bytes  : reserved(0) type(1) count(n)
 * ICONDIRENTRY    16 bytes  : w h colors reserved planes bpp size offset
 * then the raw PNG payloads.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const inputs = [
  { file: 'favicon-16x16.png', size: 16 },
  { file: 'favicon-32x32.png', size: 32 },
];

const entries = inputs.map((i) => {
  const abs = path.join(root, i.file);
  if (!fs.existsSync(abs)) throw new Error('missing input: ' + abs);
  return { size: i.size, data: fs.readFileSync(abs) };
});

const header = Buffer.alloc(6);
header.writeUInt16LE(0, 0); // reserved
header.writeUInt16LE(1, 2); // type: 1 = icon
header.writeUInt16LE(entries.length, 4);

const dirSize = 16 * entries.length;
let offset = 6 + dirSize;

const dir = Buffer.alloc(dirSize);
entries.forEach((entry, index) => {
  const base = index * 16;
  // 0 means 256 in the ICO format; our sizes are all < 256.
  dir.writeUInt8(entry.size >= 256 ? 0 : entry.size, base + 0); // width
  dir.writeUInt8(entry.size >= 256 ? 0 : entry.size, base + 1); // height
  dir.writeUInt8(0, base + 2);  // palette colour count
  dir.writeUInt8(0, base + 3);  // reserved
  dir.writeUInt16LE(1, base + 4);  // colour planes
  dir.writeUInt16LE(32, base + 6); // bits per pixel
  dir.writeUInt32LE(entry.data.length, base + 8);
  dir.writeUInt32LE(offset, base + 12);
  offset += entry.data.length;
});

const out = Buffer.concat([header, dir, ...entries.map((e) => e.data)]);
const target = path.join(root, 'favicon.ico');
fs.writeFileSync(target, out);

console.log(
  'wrote ' + target + ' (' + out.length + ' bytes, ' +
  entries.map((e) => e.size + 'x' + e.size).join(' + ') + ')'
);
