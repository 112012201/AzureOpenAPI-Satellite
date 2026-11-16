# First-screen film

The landing page opens with a full-bleed clip below the header, then the page proper —
the whitehouse.gov shape. These are the files that band plays.

| theme | when | files |
| --- | --- | --- |
| plain (`default`) | always | `default-1080.mp4` · `default-720.mp4` · `default-poster.jpg` |
| Christmas | 24–26 Dec | `christmas-1080.mp4` · `christmas-720.mp4` · `christmas-poster.jpg` |
| New Year | 31 Dec – 2 Jan | `newyear-1080.mp4` · `newyear-720.mp4` · `newyear-poster.jpg` |
| Spring Festival | 4–9 Feb | `spring-1080.mp4` · `spring-720.mp4` · `spring-poster.jpg` |

## What these are

**H.264, 30 fps, 20 seconds, no audio track, `+faststart`, 1920×1080 and 1280×720.**

- **No audio** is deliberate. The theme already plays music from the bottom-left
  control; a clip with its own soundtrack would fight it. The source files were stripped
  with `-an`.
- **20 seconds, cut to loop.** The loop point is a cut, not a cross-fade: the material is
  a montage anyway (the Christmas source is a series of 8–12 second close-ups), so a
  hard cut is what the footage already does everywhere else.
- **Two encodings.** A phone takes the 720p file — `assets/film.js` decides, because a
  video element ignores `<source media>`. Nothing reaches a phone that a desktop does
  not already have, except 3–4 MB of pixels it cannot show.
- **A poster each.** It is the same frame as the first video frame, and the `<video>`
  paints it before a single byte of video is decoded, so the band is never an empty box
  (and never a flash of black).

## The sources and where the clips start

| file | source | starts at |
| --- | --- | --- |
| `default-*` | `H:\Video\2026\时间丶ing\单视频\NASA卫星拍摄视频  4k.mp4` | 100 s, **24 s at 1.2×** |
| `christmas-*` | `H:\Video\2026\8K宝库\单视频\圣诞节8K超高清.mp4` | 596 s |
| `newyear-*` | `H:\Video\2025\VenenoSix24\…\【4K】1小时烟花秀-元宵节快乐.mp4` | 2090 s |
| `spring-*` | `H:\Video\2026\8KHDR视频宝库\单视频\绚丽烟花 4K画质测试…mp4` | 88 s |

The start times are shot boundaries: every source is a montage, and a clip that spans a
cut would carry somebody else's cut into what is meant to look like one take. The three
holiday clips are still montages — their material is a series of bursts and ornaments —
but the plain-site one is a single 20-second glide, because a castle and a beach swapping
places behind the headline is not a mood, it is a mistake.

## How they were made

The three theme clips are plain H.264 encodes:

```bash
ffmpeg -ss <start> -t 20 -i "<source>" -an \
  -vf "fps=30,scale=1920:1080:flags=lanczos" \
  -c:v libx264 -crf 28 -preset slow -profile:v high -level 4.0 -pix_fmt yuv420p \
  -g 60 -keyint_min 30 -sc_threshold 0 -movflags +faststart  <theme>-1080.mp4
```

`default-*` is the ISS downlink footage (`NASA卫星拍摄视频 4k.mp4`, 283 s, 1920×1080,
29.97 fps, plain SDR BT.709 — no tone mapping needed). The window is 24 s from 100 s,
played at **1.2×** to fill the 20-second loop; everything after ~235 s of that file is
end credits and the NASA logo, so the window stops well short of it:

```bash
ffmpeg -ss 100 -t 24 -i "<nasa source>" -an \
  -vf "scale=1920:1080:flags=lanczos,setpts=PTS/1.2,fps=30,format=yuv420p" \
  -c:v libx264 -crf 26 -maxrate 3.0M -bufsize 6.0M -preset slow \
  -profile:v high -level 4.0 -pix_fmt yuv420p -movflags +faststart  default-1080.mp4
```

`setpts=PTS/1.2` is the FASTER direction — dividing timestamps shortens the clip;
multiplying gives slow motion (the first attempt at the previous clip used `PTS*2` for
that reason, and `PTS/2` when it wanted speed). The reel is a dissolve montage of "Earth
from orbit", so the window is picked for its arc: a night pass with the ISS solar arrays
in frame, a daylight city, then an atoll.

The plain-site clip before this one was a tropical-coast aerial at half speed, from an
HDR test reel. Same idea — one continuous shot of Earth from above — but it needed HDR10
tone mapping and a bitrate ceiling, and that reel cut every few seconds, so the only way
to get twenty seconds of one shot was to slow it down. This source needed neither.

## Budget

| | 1080p | 720p | poster |
| --- | --- | --- | --- |
| plain | 7.0 MB | 3.0 MB | 94 KB |
| Christmas | 5.7 MB | 2.6 MB | 85 KB |
| New Year | 3.5 MB | 1.6 MB | 49 KB |
| Spring Festival | 6.5 MB | 2.7 MB | 60 KB |

`tests/run.php` fails above **12 MB per file**: the band is decoration, and a decoration
that costs more than that is a bad trade on a metered connection. The 720p column is what
a phone actually downloads.

## Replacing one

Re-encode with the same name and this directory's name in `conf/themes.php` needs no
change — `Season::film()` only reads the file's mtime for the cache-busting `?v=`, so a
new file replaces the old one in every browser that sees it. Keep the names, keep the
20-second length, and keep the poster a frame from the new clip.
