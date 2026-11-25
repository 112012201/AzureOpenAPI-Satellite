# AzureOpenAPI for Satellite

**This project uses VibeCoding. Please pay attention to its effectiveness**

**One de-duplicated TLE and satellite metadata feed**, merged from CelesTrak, AMSAT and
SatNOGS DB — served as JSON or classic 20-column TLE, described by OpenAPI 3.1, and
cached at the edge.

> 把 CelesTrak、AMSAT 与 SatNOGS 三个上游去重合并成一份 TLE 与卫星元数据源，
> 可输出 JSON 或经典 20 列 TLE，边缘缓存，OpenAPI 3.1 描述。

| | |
|---|---|
| **Version** | `3.3.1+ra` ([SemVer 2.0.0](https://semver.org/)) |
| **API version** | `v1` |
| **Live** | <https://satellite.authskin.xyz> · CDN <https://satellite.db.authskin.xyz> |
| **Docs** | <https://satellite.authskin.xyz/docs> · [OpenAPI 3.1](openapi.yaml) |
| **Runtime** | PHP 7.4 – 8.4 (developed and tested on 8.4), no Composer dependencies |
| **Tests** | `php tests/run.php` — 1138 assertions |
| **License** | MIT (code) · upstream data belongs to its sources |

---

## Why it exists

The three upstreams disagree about spelling, catalogue numbers, units and freshness, and
each one rate-limits differently. A satellite tracker that polls them directly inherits all
of that. This service owns the merge: it fetches, validates (including the TLE modulo-10
checksum), repairs what can be repaired *without inventing data*, records every repair as a
counter, and serves one consistent feed behind a cache that survives an upstream outage.

Design promises that are actually enforced by the test suite:

- **Errors are RFC 9457 `application/problem+json`** — never an HTML page, never a bare 500.
- **Nothing is silently rewritten.** A record that fails its checksum is served with
  `checksum_ok=false`, not "fixed".
- **Conditional requests work**: `ETag` + `304`, so a polling client costs almost nothing.
- **Freshness is stated, not implied**: `X-Service-Version`, cache age and source are on
  every response.

## Features

- **Merged TLE** across CelesTrak (Weather + Amateur), AMSAT and SatNOGS, de-duplicated by
  NORAD catalogue number, with per-source subsets available individually
- **Satellite and transmitter catalogues** (SatNOGS DB), plus **AMSAT live status reports**
- **`/v1/health` and `/v1/status`** — readiness and a human status page
- **Two cache layers** (Redis + files) with stale-while-revalidate, single-flight and
  atomic writes; a manual `warmup` CLI for cron
- **Bilingual UI** — Simplified Chinese, Traditional Chinese and English in one document, so
  a reader without JavaScript still gets the right language
- **Seasonal themes** that switch on a calendar (Christmas / New Year / Spring Festival)
- **A proof-of-work gate** in front of HTML pages, with a fail-open path so a reader is never
  locked out by a broken challenge
- **Encrypted media**: the landing page's video and the seasonal music ship as AES-256-CBC
  ciphertext, decrypted in the browser from a key issued only to a browser that solved the
  challenge. This stops crawlers and hot-linking — it is **not** DRM, and
  `assets/music/README.md` says so explicitly
- **Search-engine plumbing**: canonical URLs, self-referencing `hreflang`, JSON-LD, a sitemap
  that is asserted against the pages it lists, and IndexNow + Bing Webmaster submission

## Quick start

```bash
git clone https://github.com/112012201/AzureOpenAPI-Satellite.git
cd satapi

# Local development: PHP's built-in server, no nginx needed
php -S 127.0.0.1:8080 router.php
# → http://127.0.0.1:8080/  (landing page)
# → http://127.0.0.1:8080/v1/tle?format=json
```

Optional local configuration:

```bash
cp conf/config.example.php conf/config.php   # then edit
```

`conf/config.php`, `conf/media.php`, `conf/indexnow.php` and `conf/bing.php` are
**git-ignored** and per deployment. Everything has a production-sane default, so the quick
start above works with no configuration at all.

Requirements: PHP with `curl`, `mbstring`, `json`, `openssl` (for the media key) and, for
the best experience, Redis (optional — the file cache is used when it is absent).

## API surface

| Endpoint | What it returns |
|---|---|
| `GET /v1/tle` | Merged TLE — `?format=json\|text`, `?source=`, filters, pagination |
| `GET /v1/tle/weather` · `/amateur` · `/amsat` · `/satnogs` | One upstream set, unmerged |
| `GET /v1/satellites` | Satellite catalogue (SatNOGS DB) |
| `GET /v1/transmitters` | Transmitter catalogue (SatNOGS DB) |
| `GET /v1/amsat/status` | AMSAT live status reports |
| `GET /v1/amsat/*` | Read-only AMSAT v1 proxies (`catalog`, `reports`, `summary`, …) |
| `GET /v1/health` · `GET /v1/status` | Readiness / human status page |
| `GET /v1/meta` | Service, version, endpoints, cache and region metadata |
| `GET /docs` | Interactive OpenAPI 3.1 reference |
| `GET /openapi.yaml` | The contract itself |

The legacy URLs (`/tle_merge.php`, `/health.php`, …) still answer, with `Deprecation` and
`Link: rel="successor-version"` headers pointing at the `/v1` equivalent.

## Configuration

Defaults live in `lib/Config.php`; overrides in `conf/config.php`; environment variables
win over both (`SATAPI_*`). The ones that matter:

| Variable | Purpose |
|---|---|
| `SATAPI_SERVICE_VERSION` | Version reported on every response |
| `SATAPI_SEO_SITE` | Canonical host for `canonical`/`og:url`/sitemap |
| `SATAPI_GOOGLE_VERIFICATION` · `SATAPI_BING_VERIFICATION` | Search-console meta tokens |
| `SATAPI_BING_API_KEY` · `SATAPI_BING_SITE` | Bing Webmaster API (prefer `conf/bing.php`) |
| `SATAPI_ADMIN_TOKEN` | Required for `POST /v1/maintenance` |
| `SATAPI_REDIS_*` | Cache backend |
| `SATAPI_THEME_SEASON` | Pin a seasonal theme outside its calendar window |

## Testing

```bash
php tests/run.php          # the whole suite: 1138 assertions
php tests/run.php --verbose
```

The suite is the contract, and it covers things that are otherwise invisible: that a
canonical URL never names another host, that every sitemap entry is a page the app serves,
that an unknown path answers 404 rather than 200, that the media encryption round-trips, and
that every meta description fits a search engine's 25–160 character rule.

## Deployment

Two documents, both maintained with the code:

- **[DEPLOY.md](DEPLOY.md)** — step-by-step deployment, nginx/rewrite rules, verification
  checklist, rollback
- **[OPERATIONS.md](OPERATIONS.md)** — runbook: how to change things safely, what breaks,
  what has broken before and why

Short version: nginx serves static files, everything dynamic goes through `index.php`; the
rewrite file is a strict allowlist (a new dynamic path that is not listed answers 404 — this
has bitten twice).

### Updating a deployment with git

The repository is the source of truth; a deployment is a checkout plus its own untracked
configuration.

```bash
# check what is available without touching the working tree
git fetch origin

# see what would change
git log --oneline HEAD..origin/main

# apply it
git pull --ff-only origin main
```

`--ff-only` is deliberate: it refuses to create a merge commit on a production host, so a
deployment that has been edited in place fails loudly instead of silently diverging.
`git fetch` is the safe half — it updates the local copy of the remote and changes nothing on
disk.

After a pull: re-run `php tests/run.php`, and restart PHP-FPM only if `php.ini` changed (the
code itself is read per request).

## Versioning

[Semantic Versioning 2.0.0](https://semver.org/). Versions are `MAJOR.MINOR.PATCH`, and a
release is tagged `vX.Y.Z`.

The current version is **`3.3.1+ra`**:

- `3.3.0` — features were added and the `/v1` contract did not change. (New endpoints,
  caching, SEO plumbing, media encryption: all additive.)
- `+ra` — **build metadata**, not a pre-release. Under SemVer it is ignored when comparing
  versions, so `3.3.1+ra` and `3.3.0` are the same release; the suffix is kept because this
  deployment has always carried it. Compare with tags, not with the string.

Earlier releases used a suffix that read like a pre-release: `3.2.12-RA`, where `-RA` means
"pre-release of 3.2.12" to every SemVer tool. That is exactly the drift this policy exists to
stop — **anything after `-` is a pre-release, anything after `+` is metadata.**

What bumps what:

| Change | Bump |
|---|---|
| A client that worked yesterday breaks (`/v1` shape, status code, removed field) | MAJOR |
| New endpoint, new parameter, new capability, compatible behaviour change | MINOR |
| Bug fix, copy change, dependency pin, docs | PATCH |

## Repository layout

```
index.php               Front controller — every dynamic request lands here
bootstrap.php           Config, autoloader, error handler
lib/
  Api/                  Router + endpoints (Tle, Catalog, AmsatStatus, System, StatusPage)
  Cache/                Redis + file cache, single-flight locks
  Data/                 Fetch, parse, validate, merge, preprocess
  Http/                 Client (TLS, retries, parallel), Request, Response
  Support/              i18n, Region, Season, Media, Seo, IndexNow, BingWebmaster, Pow…
  Tle/                  TLE parser and merge
partials/               The shared page shell and the landing page
assets/                 Design system, seasonal skins, encrypted media (media is not in git)
conf/                   Configuration, i18n tables, nginx example (secrets are not in git)
bin/                    CLI tools: warmup, media encryption, IndexNow, Bing, diagnostics
tests/                  run.php — the whole suite
docs/                   API reference and generated reference material
```

## Media

`assets/film/` and `assets/music/` are **not in this repository**. The server holds those
files encrypted (`*.mp4.enc`, `*.m4a.enc`, AES-256-CBC, key in `conf/media.php`); publishing
the plaintext working copy would undo the encryption the site ships, and 75 MB of video would
make every clone expensive. `bin/media-encrypt.php` encrypts in place and moves the plaintext
out of the web root; `OPERATIONS.md` documents the whole flow.

## Security

- No credentials are committed. The per-deployment files (`conf/config.php`,
  `conf/media.php`, `conf/indexnow.php`, `conf/bing.php`) are ignored.
- The IndexNow key is **not** a secret — the protocol requires it to be published.
- The media encryption protects against crawlers and hot-linking, **not** against a
  determined visitor: the key necessarily reaches the browser. It is not DRM.
- Found a vulnerability? Open an issue, or use the contact address in the privacy policy.

## License

Code: **MIT** — see [LICENSE](LICENSE).

Data is not ours: TLE and satellite metadata come from CelesTrak, AMSAT and SatNOGS DB and
remain under their own terms. Attribution is rendered on the landing page and carried in the
API's `meta` block.
