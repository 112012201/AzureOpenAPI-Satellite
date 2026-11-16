# Theme music

Eight tracks, three periods. Every file here is **AAC 192 kbps in an `.m4a` container**,
encoded from the original lossless-ish sources — not from each other.

**The files in this directory are ciphertext.** Each `*.m4a` is stored as `*.m4a.enc`
(AES-256-CBC) and the browser decrypts it after fetching the key from `/media-key`; the
table below still names the plaintext, because that is what was measured before
encryption. `php bin/media-encrypt.php` produces the `.enc` files and moves the plaintext
out of the web root — see the "视频与音乐加密" section in OPERATIONS.md.

## Measured loudness

"Normalised" is only worth anything if it is measured afterwards, so these are the
`ffmpeg -i FILE -af ebur128=peak=true -f null -` readings of the shipped files:

| file | length | integrated | true peak |
| --- | --- | --- | --- |
| `christmas-all-i-want.m4a` | 241.6 s | −12.9 LUFS | −4.8 dBFS |
| `christmas-jingle-bell-rock.m4a` | 131.0 s | −12.8 LUFS | −0.2 dBFS |
| `christmas-last-christmas.m4a` | 269.0 s | −13.1 LUFS | −0.4 dBFS |
| `newyear-yuandan.m4a` | 250.7 s | −12.7 LUFS | −2.3 dBFS |
| `newyear-joy-to-the-world.m4a` | 206.4 s | −13.1 LUFS | −1.0 dBFS |
| `spring-hua-hao-yue-yuan.m4a` | 151.2 s | −12.8 LUFS | +1.1 dBFS |
| `spring-nanwang-jin-xiao.m4a` | 232.3 s | −12.7 LUFS | −1.1 dBFS |
| `spring-chunjie-xuqu.m4a` | 302.6 s | −13.3 LUFS | −0.5 dBFS |

That is a **0.6 LU spread** across the whole rotation (it was 1.7 LU at 96 kbps): a
listener should not reach for the volume when the track changes. The peak column is
the decoded true peak — one file sits slightly over full scale because a dense master
plus the AAC round trip overshoots, which is a property of the material, not a mistake.

## The chain, and the two that did not work

```bash
# 96 kbps -> 192 kbps is not a re-compression: every track is decoded from its OWN
# source (opus, or the AVS3 file for the two Chinese recordings) and encoded once.
ffmpeg -i "<source>" -vn \
  -af "aresample=48000,volume=<gain>dB,alimiter=limit=0.891:level=disabled:attack=5:release=50" \
  -c:a aac -b:a 192k -ar 48000 -movflags +faststart <name>.m4a
```

`volume=<gain>` is `-13 LUFS − (measured integrated loudness of the source)`.

Two earlier attempts, both wrong, both measured:

1. **`loudnorm` with `measured_*` + `linear=true`.** It does not land. When the linear
   gain would break its true-peak ceiling it falls back to the dynamic mode, and that
   mode overshot by 3–6 LU: a source measuring −18.5 came out at **−10.0**.
2. **A fixed gain capped at the source's own true peak.** Linear, but the peaky tracks
   ended up quiet: 难忘今宵 came out **−15.6 LUFS**, 2.6 LU below its neighbours, which
   is an audible jump in the rotation.

Gain to the target *and then* `alimiter` at −1 dBFS is what works: the same track now
measures −12.7 LUFS with a −1.1 dB true peak.

## The mapping

| file | source | used by |
| --- | --- | --- |
| `christmas-last-christmas.m4a` | `Wham! - Last Christmas (Single Version).opus` | Christmas rotation |
| `christmas-jingle-bell-rock.m4a` | `Bobby Helms - Jingle Bell Rock.opus` | Christmas rotation |
| `christmas-all-i-want.m4a` | `Mariah Carey - All I Want for Christmas Is You.opus` | Christmas, 25 Dec only |
| `newyear-yuandan.m4a` | `HoworD郭森,DancingLi0n舞狮 - 元旦（Original Mix）.opus` | New Year rotation |
| `newyear-joy-to-the-world.m4a` | `女子十二乐坊 - Joy To The World.m4a` (AVS3 7.1.4) | New Year rotation |
| `spring-hua-hao-yue-yuan.m4a` | `民乐 - 花好月圆.opus` | Spring Festival, default |
| `spring-chunjie-xuqu.m4a` | `中国爱乐乐团 - 李焕之 春节序曲.m4a` (AVS3 7.1.4) | Spring Festival, 6 Feb |
| `spring-nanwang-jin-xiao.m4a` | `李谷一 - 难忘今宵.opus` | Spring Festival, 9 Feb |

The opus originals live in `E:\Users\Administrator\Music\NcmForge\` (a working copy of
the same six sits in `D:\tmp\theme-src\`). **Note the non-breaking space** in the 元旦
filename: `Original\u00A0Mix` — a plain space does not match it, which is how one of
them went missing from a batch run.

## The two AVS3 recordings

`newyear-joy-to-the-world.m4a` and `spring-chunjie-xuqu.m4a` come from MP4 files whose
sample entry is **`av3a`**: AVS3 audio, 44100 Hz, **7.1.4**, twelve channels. A normal
ffmpeg build has no decoder for it (`no decoder found for: none`), and no browser plays
it. The copy at `E:\ffmpeg\` does — it is an *HDR Vivid encoder* build carrying
`libarcdav3a` (`ffmpeg -decoders | grep avs` lists `av3a`).

The 7.1.4 → stereo downmix happens **first, inside the filter chain**
(`aformat=channel_layouts=stereo`). Downmixing after the filter sums channels above
full scale: the first attempt measured −11 LUFS and clipped.

## Licence note

These are commercial recordings. They are served from this site only while the relevant
theme runs, and the operator is responsible for having the right to play them.
