<?php

namespace SatApi;

/**
 * Central configuration.
 *
 * Resolution order (later wins):
 *   1. built-in defaults (below)
 *   2. conf/config.php  (optional, not web reachable)
 *   3. environment variables (SATAPI_*, plus a few legacy names)
 *
 * Values are addressed with dot paths: Config::get('cache.redis.host').
 */
final class Config
{
    /** @var array<string,mixed> */
    private static $values = array();

    private static $root = '';
    private static $loaded = false;

    /**
     * Initialise the configuration. Safe to call more than once.
     */
    public static function init($root)
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        self::$root = rtrim((string) $root, "/\\");

        self::$values = array(
            'root' => self::$root,

            'service' => array(
                'name'        => 'AzureOpenAPI for Satellite',
                'slug'        => 'azureopenapi-for-satellite',
                'version'     => '3.3.1+ra',
                'api_version' => 'v1',
                'description' => 'Unified TLE and satellite metadata proxy (CelesTrak, AMSAT, SatNOGS).',
                // The official site of the operator, not the API hostname. It is where
                // the legal documents send a reader who wants the operator's own site.
                // It is NOT the canonical base for these pages: canonical, og:url and
                // the sitemap come from `seo.site` below. The two used to share this
                // one value, which is how the landing page ended up telling search
                // engines that its canonical copy lived on a different site.
                'homepage'    => 'https://www.authskin.xyz',
                'docs'        => '/docs.html',
                'license'     => 'Data: public sources. API code: MIT.',
                'contact'     => 'https://www.authskin.xyz/',
                // The address the privacy policy and the terms publish. Kept in one
                // place so a change cannot leave one document pointing at an old box.
                'contact_email' => '1422769173@qq.com',
                'build'       => 'local',
            ),

            // ------------------------------------------------------------------
            // Search-engine identity.
            //
            // `site` is the address these documents are canonically served from. It
            // must be the host that answers HTTP itself, not a CDN alias: three
            // hostnames serve this site (origin, EdgeOne, SCDN) and a canonical that
            // named an edge would make every cache its own original.
            //
            // Left empty it resolves to the `origin` entry of the hostname registry,
            // which is exactly that host. Setting it explicitly is for a move to a
            // different domain, where changing one string here must be enough for the
            // canonical, the Open Graph tags and the sitemap to follow.
            // ------------------------------------------------------------------
            'seo' => array(
                'site'    => '',
                // Profiles of the operator or the project elsewhere. Only a real one
                // belongs here: an empty list is fine, an invented list is a claim.
                'same_as' => array(),
                // Search Console / Webmaster Tools verification tokens.
                //
                // These are not secrets: each one is published in the page's own
                // <head> so the crawler can read it back, which is the entire proof.
                // They live in configuration so verifying the site is a one-line
                // change rather than a template edit, and so the DNS method and the
                // HTML-file method remain alternatives rather than the only paths.
                'google_verification' => '',
                'bing_verification'   => '',
                // Bing Webmaster Tools API credential. This one IS a secret, so it
                // belongs in conf/bing.php (mode 0600, not in git) rather than here;
                // the entries exist so a container can inject them instead.
                'bing_api_key' => '',
                // The verified property the API acts on. NOT seo.site: the verified
                // property is the apex (https://authskin.xyz/), while the pages live
                // on the satellite subdomain, and Bing keys sites by exact URL.
                'bing_site'    => '',
            ),

            // ------------------------------------------------------------------
            // Public hostname registry.
            //
            // More than one hostname serves this API, each behind a different
            // CDN, and the service has to be able to say WHICH one answered and
            // whether that answer came off an edge or from this process.
            //
            // `cdn` names the vendor, and `cdn_header` is the response header
            // that vendor writes its own cache verdict into. They are not
            // interchangeable:
            //
            //   EdgeOne  -> EO-Cache-Status     (its own namespace, no collision)
            //   SCDN     -> X-Cache-Status      (COLLIDES with the origin header
            //                                    this app emits in
            //                                    Api\Endpoints\Base::cacheHeaders)
            //
            // That collision is why the origin's verdict also travels as
            // X-Origin-Cache-Status: `X-Cache-Status` cannot be trusted as an
            // origin signal once a CDN is in front, because the edge overwrites
            // it on the way out. Measured 2026-10-28 on satellite.db:
            //   X-Cache-Status: MISS from L1:6518   (SCDN)
            //   X-Cache-Age: 5541                   (origin, 5541s)
            // i.e. four headers describing one cache and two of them disagreeing.
            //
            // `status` is published so a client can tell a battle-tested address
            // from one that is still being shaken out. 'new' is not decoration:
            // it also drives the in-page notice and the meta.endpoint block.
            //
            // THE THREE ADDRESSES AND THEIR HONEST TRADE-OFFS
            //
            // Each route fails in its own way, and a visitor cannot be expected to
            // know which. So every entry carries `notice` (an i18n key, so the text
            // is written per locale instead of machine-translated) plus a short
            // `badge`, and the page renders them as a strip at the top. The three
            // do not share one sentence because they are not the same risk:
            //
            //   origin    direct to the main proxy, with no edge in front of it. That
            //             is its VALUE, not its fallback role: nothing is cached by a
            //             third party, so what a client reads is what this process
            //             computed this second -- the address for anyone who needs
            //             current data. The cost is that a busy moment is felt
            //             directly, because no edge absorbs the spike.
            //   primary   EdgeOne, a normal acceleration path; nodes replicate, so a
            //             stale copy can be served after a refresh
            //   scdn      the newer path (2026-10-28); out of testing since 2026-11-03
            //             and now the regularly-accelerated address
            //
            // `notice_level` is 'warning' when the visitor can lose service and
            // 'info' when the risk is only data age -- colouring both the same would
            // teach people to ignore the strip.
            //
            // `announcements` decides whether the announcement board (the dialog
            // built from content/announcements/*.md) is rendered on a page for this
            // address. It is true everywhere: the operator's "not on the API address"
            // meant API RESPONSES, which is structural -- the board only exists in
            // HTML documents, and /v1/* answers JSON. The flag stays as a hook for a
            // future hostname that should not carry the board at all.
            // ------------------------------------------------------------------
            'endpoints' => array(
                // Moved from `primary` to `scdn` on 2026-11-03, with the public
                // announcement: the EdgeOne address enters a long maintenance window
                // on 2026-11-06, so answering an unrecognised Host with it would point
                // clients at an address that is about to be down. `primary` stays in
                // the registry -- it is not retired, and /v1/meta must still describe
                // it correctly while it is up.
                'default' => 'scdn',

                'registry' => array(
                    'primary' => array(
                        'host'          => 'satellite.api.authskin.xyz',
                        'url'           => 'https://satellite.api.authskin.xyz',
                        'cdn'           => 'edgeone',
                        'cdn_header'    => 'eo-cache-status',
                        'status'        => 'stable',
                        'testing'       => false,
                        'badge'         => 'endpoint.badge.edgeone',
                        'notice'        => 'endpoint.notice.edgeone',
                        'notice_level'  => 'info',
                        'announcements' => true,
                    ),

                    // Added 2026-10-28. CNAME to an SCDN ("安全加速") endpoint; the
                    // origin is the same application, so every route, header and
                    // limit is identical by construction -- only the edge differs.
                    //
                    // 2026-11-03: testing finished, so `status`/`testing` are no
                    // longer 'new'/true. That flag is published in /v1/meta and says
                    // "this address is under test"; leaving it on would now be a lie,
                    // and the badge (which is what a visitor reads) changes with it.
                    'scdn' => array(
                        'host'          => 'satellite.db.authskin.xyz',
                        'url'           => 'https://satellite.db.authskin.xyz',
                        'cdn'           => 'scdn',
                        'cdn_header'    => 'x-cache-status',
                        'status'        => 'stable',
                        'testing'       => false,
                        // Shown in /v1/meta and on the landing page. Kept short: it
                        // is a label, not documentation.
                        'since'         => '2026-10-28',
                        'stable_since'  => '2026-11-03',
                        'badge'         => 'endpoint.badge.regular',
                        'notice'        => 'endpoint.notice.scdn',
                        'notice_level'  => 'info',
                        'announcements' => true,
                    ),

                    // The third name in the vhost's server_name list. It used to fall
                    // through to `primary`, so a visitor on the un-mediated address was
                    // told they were on EdgeOne. It has no edge at all: `cdn` is 'none'
                    // and `cdn_header` is empty, and the template uses exactly that to
                    // decide whether there is an edge verdict worth displaying.
                    'origin' => array(
                        'host'          => 'satellite.authskin.xyz',
                        'url'           => 'https://satellite.authskin.xyz',
                        'cdn'           => 'none',
                        'cdn_header'    => '',
                        'status'        => 'stable',
                        'testing'       => false,
                        'badge'         => 'endpoint.badge.direct',
                        'notice'        => 'endpoint.notice.direct',
                        'notice_level'  => 'warning',
                        'announcements' => true,
                    ),
                ),
            ),

            // ------------------------------------------------------------------
            // Information preprocessing.
            //
            // Upstream feeds are not merely concatenated: they are cleaned and
            // completed before the request path ever sees them, and the cleaned
            // result is stored as a snapshot so a request does not redo the work.
            //
            // Every repair is COUNTED rather than quietly applied, because a
            // cleaning pass that silently changes data is indistinguishable from
            // a source that is lying. The counters are published in
            // /v1/status and in the reload report.
            //
            // The rules themselves live in lib/Data/Prepared.php, one method per
            // rule, so they are readable without reading the pipeline.
            // ------------------------------------------------------------------
            'preprocess' => array(
                'enabled' => true,
                // Cache key and lifetime of the cleaned snapshot. The snapshot is
                // derived data, so its TTL only needs to outlive the slowest
                // upstream source; it is invalidated by a reload regardless.
                'key' => 'prepared:tle',
                'ttl' => 43200,

                // Verify the modulo-10 checksum on line 1 and line 2 and record the
                // TRUE verdict.
                //
                // Deliberately does NOT rewrite a failing line to make it pass.
                // Recomputing the trailing digit turns "this data is corrupt" into
                // "this data is fine", which is the one thing a preprocessing step
                // must never do: the checksum exists to detect corruption, not to be
                // regenerated over it. A record that fails is still served (it is
                // upstream data, not ours to invent), with checksum_ok=false and the
                // expected digit published, which is what lets a client decide.
                'verify_checksum' => true,

                // Fill a missing NORAD catalogue number from the satellite name,
                // using the configured norad_fallback map. Exact (normalised) match
                // only: a fuzzy match would map "AO-7" onto "AO-70" the moment the
                // catalogue grows that name, and a wrong catalogue number is worse
                // than a missing one.
                'complete_norad' => true,

                // Reject a line 2 catalogue number that disagrees with line 1.
                // A mismatched pair is two different satellites welded together.
                'reject_norad_mismatch' => true,

                // Strip the decorations upstreams add to names ("0 ISS (ZARYA)"),
                // collapse whitespace, and upper-case nothing (names are proper
                // nouns; "Amsat" must not become "AMSAT").
                'normalise_names' => true,

                // Hard cap on records kept. Guards the memory ceiling: the four TLE
                // sets merge to roughly 12k records today, and an upstream returning
                // a pathological payload must not OOM the worker.
                'max_records' => 60000,
            ),

            // ------------------------------------------------------------------
            // Hot reload: re-pull every source and OVERWRITE the cached copy.
            //
            // Distinct from the scheduled warmup in two ways that matter:
            //   - it ignores TTL, so a reload 30 seconds after the last fetch still
            //     hits the network instead of being a no-op;
            //   - it covers every dataset, not just the four TLE sets.
            //
            // Reachable from the CLI (`php index.php reload`) and over HTTP
            // (POST /v1/admin/reload), both gated below.
            // ------------------------------------------------------------------
            'reload' => array(
                'enabled' => true,
                // Wall-clock ceiling for one full reload. The upstream timeout is
                // 20s per source and there are seven, so a generous ceiling still
                // fails fast when an upstream black-holes the connection.
                'time_limit' => 180,
                // A reload rewrites seven cache keys and the prepared snapshot.
                // Serialised on purpose: two overlapping reloads would double the
                // upstream load for an identical result, which is how a service
                // gets blocked by its own upstream.
                'lock_timeout_ms' => 5000,
            ),

            'cache' => array(
                'dir'   => self::$root . '/data/cache',
                'redis' => array(
                    'enabled' => true,
                    'host'    => '127.0.0.1',
                    'port'    => 6379,
                    'db'      => 8,
                    'timeout' => 1.0,
                    'prefix'  => 'satapi:',
                ),
                // Hard ceiling for a single cached payload (bytes).
                'max_payload' => 33554432,
            ),

            'http' => array(
                'user_agent'  => 'AzureOpenAPI-for-Satellite/3.3.1+ra (+https://satellite.api.authskin.xyz)',
                'verify_tls'  => true,
                'ca_bundle'   => '',
                'retries'     => 2,
                'retry_delay' => 250, // milliseconds
                'max_bytes'   => 33554432,
                'timeout'     => 20,
                'connect_timeout' => 8,
                // Who emits the four security headers on dynamic responses.
                // The application owns them because it is the only source that
                // covers `php -S` and Apache as well as nginx, and because a
                // server-level nginx group is applied twice to fastcgi responses
                // (measured: panel vhost copy removed => count 2 -> 1).
                //   'app'   (default) - this process sends all four.
                //   'nginx'           - this process sends none. Verified to
                //                       leave dynamic responses with zero
                //                       security headers under the current
                //                       nginx config, so change it only together
                //                       with conf/bt-panel-rewrite.conf.
                //   'both'            - v2.2 defect, duplicates. Rollback only.
                'security_headers_owner' => 'app',
            ),

            'cors' => array(
                'enabled'        => true,
                'allow_origin'   => '*',
                'allow_methods'  => 'GET, HEAD, OPTIONS',
                'allow_headers'  => 'Content-Type, Accept, If-None-Match, If-Modified-Since, X-Requested-With, Authorization',
                // Every header a browser script is expected to read must be listed
                // here, because a cross-origin response hides all but the CORS
                // safelisted ones. EO-Cache-Status is on the list for a concrete
                // reason: the landing page reports which edge served the API call,
                // and without it fetch() cannot see the header at all and silently
                // falls back to the origin verdict -- which is exactly the
                // "cache mode shows the wrong thing" bug this replaces.
                'expose_headers' => 'ETag, Age, X-Request-Id, X-Cache-Status, X-Cache-Age, X-Cache-TTL, X-Cache-Backend, X-Origin-Cache-Status, X-Origin-Cache-Age, X-Origin-Cache-TTL, X-Origin-Cache-Backend, EO-Cache-Status, X-CDN, X-Edge-Cache-Status, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After, Deprecation, Sunset, Link',
                'max_age'        => 86400,
            ),

            'ratelimit' => array(
                'enabled'     => false,
                'per_minute'  => 120,
                'burst'       => 40,
                'dir'         => self::$root . '/data/ratelimit',
                'trust_proxy' => false,
            ),

            'compression' => array(
                'enabled'     => true,
                'min_bytes'   => 1024,
                'level'       => 6,
            ),

            'maintenance' => array(
                // Directory holding the `.maintenance_<endpoint>` flag files.
                'dir' => self::$root . '/data',
            ),

            'metrics' => array(
                // Telemetry sink selection: 'file' | 'redis' | 'both'.
                // See lib/Metrics/Driver.php for the driver contract.
                'driver' => 'both',
                'file'   => self::$root . '/data/metrics.json',
                'redis'  => array(
                    'enabled' => true,
                    // A dedicated logical database keeps counter writes off the
                    // cache database, so a metrics burst cannot evict datasets.
                    'db'      => 9,
                    'prefix'  => 'satapi:m:',
                    'timeout' => 1.0,
                ),
                'mysql'  => array(
                    // Historical rollups. Disabled until credentials are given
                    // via SATAPI_MYSQL_* or conf/config.php; when disabled the
                    // metrics endpoints degrade instead of failing.
                    'enabled'        => false,
                    'host'           => '127.0.0.1',
                    'port'           => 3306,
                    'database'       => 'satapi',
                    'user'           => '',
                    'password'       => '',
                    'charset'        => 'utf8mb4',
                    'retention_days' => 90,
                ),
                // Redis minute buckets expire after this many seconds (48h).
                'redis_retention' => 172800,
            ),

            // ------------------------------------------------------------------
            // AMSAT Satellite Status API (v1) proxy.
            //
            // AMSAT's own documented JSON endpoints, relayed byte for byte by
            // Api\Endpoints\AmsatApi. `ttl` is not a performance knob: AMSAT asks
            // clients not to poll more often than every 5 minutes, so 300 is the floor
            // that keeps this service inside their guidelines however many of our own
            // clients ask for the same document.
            // ------------------------------------------------------------------
            'amsat_api' => array(
                'base'    => 'https://status.amsat.org/api/v1/',
                'ttl'     => 300,
                'timeout' => 8,
            ),

            // ------------------------------------------------------------------
            // Jurisdiction: which version of the legal documents a visitor gets.
            //
            // `ranges` is the compiled APNIC table (bin/build-cn-ranges.php). When it
            // is missing every request resolves to the international documents, which
            // is the safe direction: the mainland version carries local obligations and
            // must not be shown to someone outside that jurisdiction by accident.
            // ------------------------------------------------------------------
            'region' => array(
                // Mainland allocations (CC=CN in the APNIC registry). Hong Kong,
                // Macao and Taiwan are separate allocations and are therefore NOT
                // in this file -- which is what makes "in the CN table" mean
                // exactly "mainland", the exclusion the policy requires.
                'ranges' => 'data/cn-ranges.php',
                // Hong Kong + Macao + Taiwan allocations. They read the
                // international documents, but in Traditional Chinese: the
                // LANGUAGE follows the address, the JURISDICTION does not.
                'hmt_ranges' => 'data/hmt-ranges.php',
            ),

            // ------------------------------------------------------------------
            // Seasonal skin.
            //
            // `default` is the site as designed. `newyear` swaps in the red-and-gold
            // theme (assets/newyear.css + the fireworks canvas); nothing about the
            // markup, the routes or the text changes, and a visitor can leave the
            // skin for themselves with the badge it shows.
            //
            // ?season=newyear previews it without touching configuration, and
            // ?season=default turns it off for one visitor. A skin chosen that way
            // makes the response no-store, because it is then per-visitor.
            // ------------------------------------------------------------------
            'theme' => array(
                'season' => 'default',
            ),

            'admin' => array(
                // Empty token => maintenance writes are disabled (read-only mode).
                'token' => '',
                // Accept the token as `?token=` as well as in a header.
                //
                // OFF by default, and that is a security decision rather than a
                // preference: a query string is written to the access log, kept in
                // shell history, and travels as a Referer if the response ever links
                // out. An admin token in an access log IS an admin credential.
                // Turn it on only for a host whose logs nobody else can read.
                'allow_query_token' => false,
            ),

            // ------------------------------------------------------------------
            // Site announcement banner.
            //
            // Rendered server-side into the HTML so it is visible without
            // JavaScript and is announced by screen readers. `id` is the identity
            // of the notice: changing it makes a previously dismissed banner
            // reappear (the dismissal cookie stores the id, not a boolean).
            // `expires_at` is evaluated on the SERVER: a client with a wrong
            // clock must not be able to keep an expired notice alive.
            // ------------------------------------------------------------------
            'announcement' => array(
                'enabled'    => false,
                'id'         => '',
                'text'       => '',
                'href'       => '',
                'href_text'  => '',
                'level'      => 'info',   // info | warning
                'expires_at' => '',       // '' = never; otherwise ISO-8601 UTC
            ),

            // ------------------------------------------------------------------
            // The announcement board (the dialog, not the bar above).
            //
            // Source of truth is one Markdown file per announcement in
            // `content/announcements/`, front matter carrying title/date/author/
            // level/refusable/expires. `php index.php announcements` validates them
            // and rewrites `compiled` in one atomic step; the request path only ever
            // reads the compiled artifact, never the Markdown.
            //
            // `max_shown` caps how many dialogs are embedded in a page. Every live
            // announcement is still in the artifact -- the cap is about page weight,
            // not about policy. Ordering is by severity and then by date, so the cap
            // only ever drops the least urgent ones.
            //
            // `cookie_days` is how long an acknowledgement is remembered. It is
            // deliberately shorter than the cookie lifetime for consent: a notice is
            // news, and a visitor who read one six months ago has forgotten it.
            // ------------------------------------------------------------------
            'announcements' => array(
                'enabled'        => true,
                'source'         => 'content/announcements',
                'compiled'       => 'data/announcements.php',
                'max_shown'      => 3,
                'cookie_days'    => 180,
                'default_author' => 'Announcement system',
            ),

            // ------------------------------------------------------------------
            // Consent (privacy policy + terms of service acknowledgement).
            //
            // The contract is stateless so it survives CDN caching:
            //   ?consent=privacy,terms&consent_version=1
            //   X-Consent: privacy,terms;version=1
            //
            // `required` also gates enforcement. It is false by default so an
            // existing integration is never broken by a new legal notice; set
            // SATAPI_CONSENT_REQUIRED=1 to answer 451 without an acknowledgement.
            // Bumping `version` invalidates every previous acknowledgement, which
            // is what a material change to either document requires.
            // ------------------------------------------------------------------
            'consent' => array(
                'required' => false,
                'version'  => 1,
                'items'    => array('privacy', 'terms'),
                'docs'     => array(
                    'privacy' => '/legal/privacy.html',
                    'terms'   => '/legal/terms.html',
                ),
                // Paths that never require an acknowledgement: the notice itself,
                // the machine-readable contract, the probes an operator needs, and
                // the very document a client is being asked to read.
                'exempt_paths' => array(
                    '/v1/health', '/v1/health/live', '/v1/health/ready',
                    '/v1/status', '/v1/meta', '/v1/consent/status',
                    '/v1/consent/verify-pow',
                    // An operator tool authenticated by admin.token. Demanding a
                    // consent parameter as well would only make an outage harder
                    // to fix: the person reloading the cache is the one who
                    // already accepted the terms by holding the token.
                    '/v1/admin/reload',
                    '/docs.html', '/openapi.yaml',
                    '/legal/privacy.html', '/legal/terms.html', '/legal/index.html',
                ),
                // Longest accepted value for ?consent=, in bytes. Guards logs.
                'max_param_bytes' => 512,
            ),

            // ------------------------------------------------------------------
            // Proof of work (Cap) in front of the HTML entry points.
            //
            // Only the browser entry points are challenged: an API client cannot
            // solve a proof of work, and challenging /v1/* would also make every
            // response uncacheable at the CDN.
            //
            // `fail_open` is deliberate. A third-party challenge service going
            // down must degrade the protection, never lock the whole site, so an
            // unreachable or slow Cap instance results in a pass plus a logged
            // warning and an X-PoW-Status header the page can surface.
            // ------------------------------------------------------------------
            'pow' => array(
                'enabled'      => false,
                'provider'     => 'cap',
                // Public base URL of the Cap instance as the BROWSER sees it;
                // it is also what the widget calls, so it must not be loopback.
                'endpoint'     => '/cap',
                'site_key'     => '',
                // Prefer SATAPI_POW_SECRET_KEY so the secret stays out of the repo.
                'secret_key'   => '',
                'timeout_ms'   => 5000,
                'fail_open'    => true,
                'session_ttl'  => 43200,   // cap_session cookie lifetime, seconds
                'exempt_paths' => array(
                    '/v1/health', '/v1/health/live', '/v1/health/ready',
                    '/v1/status', '/v1/meta', '/v1/consent/status',
                    '/v1/consent/verify-pow',
                    '/v1/admin/reload',
                    '/legal/privacy.html', '/legal/terms.html', '/legal/index.html',
                ),
            ),

            // ------------------------------------------------------------------
            // Cookie and local storage inventory exposed to the consent dialog
            // AND published in the privacy policy.
            //
            // `essential` entries are written even when the visitor rejects
            // everything: they are what makes the visitor's own choice and the
            // proof-of-work session possible. That is stated plainly in the dialog
            // and in the policy rather than being quietly assumed. The strings here
            // are the single source of truth for both, so the policy cannot drift
            // from the code.
            // ------------------------------------------------------------------
            'cookies' => array(
                'version' => 1,
                'essential' => array(
                    'cookie_consent'         => 'Records the choice you made in the cookie dialog, so you are not asked again on every page.',
                    'announcement_dismissed' => 'Records the identifier of the service notice you closed, so it stays closed.',
                    'announcement_read'      => 'Records the identifiers of the announcements you acknowledged, so they are not shown again.',
                    'announcement_declined'  => 'Records the announcement you refused. While it is live the page content stays hidden, and the choice is cleared when you agree or reload to be asked again.',
                    'cap_session'            => 'Holds the proof-of-work session for this browser. Set only after a challenge is solved, and expires after 12 hours.',
                ),
                'preferences' => array(
                    'satapi-theme' => 'Remembers whether you chose the light or the dark theme.',
                    'satapi-lang'  => 'Remembers the display language you selected, so the next page is already in it.',
                ),
                // No analytics cookie is set today. The category exists so the
                // dialog, the policy and the code agree on the same three names,
                // and so enabling analytics later is a configuration change rather
                // than a silent new cookie.
                'analytics' => array(),
                'lifetime_days' => 180,
            ),

            'legacy' => array(
                // Emit Deprecation/Sunset headers on the pre-v1 routes.
                'warn'  => true,
                'sunset' => '',
            ),

            'logs' => array(
                'dir'   => self::$root . '/logs',
                'file'  => 'app.log',
                'level' => 'warning',
            ),

            // Upstream data sources, shared by every endpoint.
            'sources' => array(
                'weather' => array(
                    'label'   => 'CelesTrak Weather',
                    'url'     => 'https://celestrak.org/NORAD/elements/gp.php?GROUP=weather&FORMAT=tle',
                    'ttl'     => 10800,
                    'parser'  => 'tle-text',
                    'accept'  => 'text/plain',
                    'attribution' => 'CelesTrak (Dr. T.S. Kelso)',
                ),
                'amateur' => array(
                    'label'   => 'CelesTrak Amateur',
                    'url'     => 'https://celestrak.org/NORAD/elements/gp.php?GROUP=amateur&FORMAT=tle',
                    'ttl'     => 10800,
                    'parser'  => 'tle-text',
                    'accept'  => 'text/plain',
                    'attribution' => 'CelesTrak (Dr. T.S. Kelso)',
                ),
                'amsat' => array(
                    'label'   => 'AMSAT Daily TLE',
                    'url'     => 'https://www.amsat.org/tle/dailytle.txt',
                    'ttl'     => 43200,
                    'parser'  => 'tle-text',
                    'accept'  => 'text/plain',
                    'attribution' => 'AMSAT',
                ),
                'satnogs' => array(
                    'label'   => 'SatNOGS DB TLE',
                    'url'     => 'https://db.satnogs.org/api/tle/?format=json',
                    'ttl'     => 10800,
                    'parser'  => 'satnogs-json',
                    'accept'  => 'application/json',
                    'attribution' => 'SatNOGS DB (libre.space)',
                ),
                'satnogs_satellites' => array(
                    'label'   => 'SatNOGS Satellites',
                    'url'     => 'https://db.satnogs.org/api/satellites/',
                    'ttl'     => 21600,
                    'parser'  => 'json',
                    'accept'  => 'application/json',
                    'attribution' => 'SatNOGS DB (libre.space)',
                ),
                'satnogs_transmitters' => array(
                    'label'   => 'SatNOGS Transmitters',
                    'url'     => 'https://db.satnogs.org/api/transmitters/',
                    'ttl'     => 21600,
                    'parser'  => 'json',
                    'accept'  => 'application/json',
                    'attribution' => 'SatNOGS DB (libre.space)',
                ),
                'amsat_status' => array(
                    'label'   => 'AMSAT Status Reports',
                    'url'     => 'https://www.amsat.org/status/',
                    'ttl'     => 3600,
                    'parser'  => 'amsat-status-html',
                    'accept'  => 'text/html',
                    'attribution' => 'AMSAT',
                ),
            ),

            // Static NORAD fallback map for AMSAT status parsing.
            // Verified against CelesTrak where possible (see OPTIMIZATION-REPORT.md).
            'norad_fallback' => array(
                'AO-7'   => '07530',
                'AO-73'  => '39444',
                'AO-85'  => '40967',
                'AO-91'  => '43017',
                'AO-92'  => '43137',
                'AO-95'  => '43770',
                'AO-109' => '46325',
                'FO-29'  => '24278',
                'SO-50'  => '27607',
                'EO-88'  => '42017',
                'PO-101' => '43678',
                'CAS-4A' => '43855',
                'CAS-4B' => '43856',
                'CO-128' => '45785',
                'ISS'    => '25544',
                'UO-11'  => '14781',
                'AO-27'  => '22825',
                'AO-51'  => '28375',
                'JO-97'  => '43803',
                'RS-44'  => '44909',
            ),
        );

        self::loadFile();
        self::applyEnvironment();
    }

    private static function loadFile()
    {
        $file = self::$root . '/conf/config.php';
        if (!is_file($file)) {
            return;
        }
        /** @noinspection PhpIncludeInspection */
        $overrides = require $file;
        if (is_array($overrides)) {
            self::$values = self::merge(self::$values, $overrides);
        }
    }

    private static function applyEnvironment()
    {
        $map = array(
            'SATAPI_SERVICE_VERSION'      => 'service.version',
            'SATAPI_SERVICE_HOMEPAGE'     => 'service.homepage',
            // The canonical host for canonical/og:url/sitemap. Separate from
            // service.homepage on purpose: one is where the operator lives, the
            // other is where THIS site answers.
            'SATAPI_SEO_SITE'             => 'seo.site',
            'SATAPI_GOOGLE_VERIFICATION'  => 'seo.google_verification',
            'SATAPI_BING_VERIFICATION'    => 'seo.bing_verification',
            'SATAPI_BING_API_KEY'         => 'seo.bing_api_key',
            'SATAPI_BING_SITE'            => 'seo.bing_site',
            'SATAPI_SERVICE_BUILD'        => 'service.build',
            'SATAPI_THEME_SEASON'         => 'theme.season',
            'SATAPI_REDIS_ENABLED'        => 'cache.redis.enabled',
            'SATAPI_REDIS_HOST'           => 'cache.redis.host',
            'SATAPI_REDIS_PORT'           => 'cache.redis.port',
            'SATAPI_REDIS_DB'             => 'cache.redis.db',
            'SATAPI_REDIS_PREFIX'         => 'cache.redis.prefix',
            'SATAPI_CACHE_DIR'            => 'cache.dir',
            'SATAPI_CORS_ORIGIN'          => 'cors.allow_origin',
            'SATAPI_RATELIMIT_ENABLED'    => 'ratelimit.enabled',
            'SATAPI_RATELIMIT_PER_MINUTE' => 'ratelimit.per_minute',
            'SATAPI_ADMIN_TOKEN'          => 'admin.token',
            'SATAPI_TRUST_PROXY'          => 'ratelimit.trust_proxy',
            'SATAPI_LOG_LEVEL'            => 'logs.level',
            'SATAPI_LOG_DIR'              => 'logs.dir',
            'SATAPI_VERIFY_TLS'           => 'http.verify_tls',
            'SATAPI_CA_BUNDLE'            => 'http.ca_bundle',
            'SATAPI_SECURITY_HEADERS_OWNER' => 'http.security_headers_owner',
            'SATAPI_METRICS_DRIVER'       => 'metrics.driver',
            'SATAPI_METRICS_FILE'         => 'metrics.file',
            'SATAPI_METRICS_REDIS_ENABLED' => 'metrics.redis.enabled',
            'SATAPI_METRICS_REDIS_DB'     => 'metrics.redis.db',
            'SATAPI_METRICS_REDIS_PREFIX' => 'metrics.redis.prefix',
            'SATAPI_METRICS_REDIS_RETENTION' => 'metrics.redis_retention',
            'SATAPI_MYSQL_ENABLED'        => 'metrics.mysql.enabled',
            'SATAPI_MYSQL_HOST'           => 'metrics.mysql.host',
            'SATAPI_MYSQL_PORT'           => 'metrics.mysql.port',
            'SATAPI_MYSQL_DATABASE'       => 'metrics.mysql.database',
            'SATAPI_MYSQL_USER'           => 'metrics.mysql.user',
            'SATAPI_MYSQL_PASSWORD'       => 'metrics.mysql.password',
            'SATAPI_MYSQL_RETENTION_DAYS' => 'metrics.mysql.retention_days',
            'SATAPI_ANNOUNCEMENT_ENABLED' => 'announcement.enabled',
            'SATAPI_ANNOUNCEMENT_ID'      => 'announcement.id',
            'SATAPI_ANNOUNCEMENT_TEXT'    => 'announcement.text',
            'SATAPI_ANNOUNCEMENT_HREF'    => 'announcement.href',
            'SATAPI_ANNOUNCEMENT_HREF_TEXT' => 'announcement.href_text',
            // ISO-8601. Evaluated on the server, so a client clock cannot revive an
            // expired notice.
            'SATAPI_ANNOUNCEMENT_EXPIRES' => 'announcement.expires_at',
            // 'info' or 'warning'; anything else collapses to 'info'.
            'SATAPI_ANNOUNCEMENT_LEVEL'   => 'announcement.level',

            // SpaceTrack credentials.
            //
            // These are read from conf/config.php by default (a file nginx denies and
            // git ignores). The environment wins, so a credential can be rotated
            // without editing a file, and `SATAPI_SPACETRACK_PASSWORD=-` clears
            // whatever the file set. Keeping both paths working matters: the config
            // file is the documented primary, and an environment that cannot override
            // it would make a leaked credential impossible to revoke without a deploy.
            'SATAPI_SPACETRACK_ENABLED'   => 'sources.spacetrack.enabled',
            'SATAPI_SPACETRACK_IDENTITY'  => 'sources.spacetrack.identity',
            'SATAPI_SPACETRACK_PASSWORD'  => 'sources.spacetrack.password',
            'SATAPI_SPACETRACK_TIMEOUT'   => 'sources.spacetrack.timeout',
            'SATAPI_SPACETRACK_CHUNK'     => 'sources.spacetrack.chunk_size',
            'SATAPI_CONSENT_REQUIRED'     => 'consent.required',
            'SATAPI_CONSENT_VERSION'      => 'consent.version',
            'SATAPI_PREPROCESS_ENABLED'   => 'preprocess.enabled',
            'SATAPI_PREPROCESS_TTL'       => 'preprocess.ttl',
            'SATAPI_RELOAD_ENABLED'       => 'reload.enabled',
            'SATAPI_RELOAD_TIME_LIMIT'    => 'reload.time_limit',
            'SATAPI_POW_ENABLED'          => 'pow.enabled',
            'SATAPI_POW_ENDPOINT'         => 'pow.endpoint',
            'SATAPI_POW_SITE_KEY'         => 'pow.site_key',
            'SATAPI_POW_SECRET_KEY'       => 'pow.secret_key',
            'SATAPI_POW_FAIL_OPEN'        => 'pow.fail_open',
        );

        foreach ($map as $env => $path) {
            $value = getenv($env);
            if ($value === false) {
                continue;
            }
            $coerced = self::coerceEnvValue($value);
            if ($coerced === null) {
                // Empty means "not set" -- the usual and safest reading, because an
                // unset variable and an empty one are indistinguishable in most
                // shells and a blank value silently wiping a credential would be a
                // nasty surprise.
                continue;
            }
            self::set($path, $coerced);
        }
    }

    /**
     * Turn one raw environment value into a configuration value.
     *
     * Returns null to mean "leave the configured value alone". Extracted from the
     * loop so the rules are directly testable: the loop itself runs during bootstrap,
     * which means a test cannot change the environment and re-observe the result
     * without re-running bootstrap.
     *
     * The rules, in order:
     *   `-`                -> '' deliberately EMPTY. An empty environment variable
     *                         reads as "not set", so an operator needs an explicit
     *                         way to clear a value that a config file set -- without
     *                         it a configured announcement id could never be blanked,
     *                         and a leaked credential could not be revoked from the
     *                         environment.
     *   ''                 -> null (not set)
     *   0/false/no/off     -> false
     *   1/true/yes/on      -> true
     *   numeric            -> int or float
     *   anything else      -> the string, unchanged
     *
     * @param string $value
     * @return mixed|null
     */
    public static function coerceEnvValue($value)
    {
        $value = (string) $value;
        if ($value === '-') {
            return '';
        }
        if ($value === '') {
            return null;
        }
        if (in_array($value, array('0', 'false', 'no', 'off'), true)) {
            return false;
        }
        if (in_array($value, array('1', 'true', 'yes', 'on'), true)) {
            return true;
        }
        if (is_numeric($value)) {
            return $value + 0;
        }

        return $value;
    }

    private static function merge(array $base, array $overrides)
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Read a configuration value using a dot path.
     *
     * @param string $path
     * @param mixed  $default
     * @return mixed
     */
    public static function get($path, $default = null)
    {
        $node = self::$values;
        foreach (explode('.', (string) $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * Override a configuration value at runtime (tests / CLI flags).
     *
     * @param string $path
     * @param mixed  $value
     */
    public static function set($path, $value)
    {
        $segments = explode('.', (string) $path);
        $node = &self::$values;
        foreach ($segments as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = array();
            }
            $node = &$node[$segment];
        }
        $node = $value;
        unset($node);
    }

    public static function root()
    {
        return self::$root;
    }

    public static function path($relative)
    {
        return self::$root . '/' . ltrim((string) $relative, '/');
    }

    /**
     * The ids that are genuinely data sources: a URL to fetch, a cache TTL and a
     * parser.
     *
     * This list exists because the `sources` configuration block also carries
     * provider *credentials* (`sources.spacetrack.identity/password`). Without a
     * filter, `sources()` returned that block as if it were a dataset, which put a
     * credential-carrying array into `/v1/meta` and produced "Undefined array key
     * url/ttl/parser" warnings on every request for it.
     *
     * Keeping the credential out of public output by *listing what is public* is the
     * safer direction than by blacklisting what is not: a new credential block added
     * later cannot leak by being forgotten.
     */
    const DATASETS = array(
        'weather',
        'amateur',
        'amsat',
        'satnogs',
        'satnogs_satellites',
        'satnogs_transmitters',
        'amsat_status',
    );

    /**
     * Every configured public hostname, keyed by short id.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function endpoints()
    {
        $registry = self::get('endpoints.registry', array());

        return is_array($registry) ? $registry : array();
    }

    /**
     * One entry from the hostname registry, or null.
     *
     * @return array<string,mixed>|null
     */
    public static function endpoint($id)
    {
        $registry = self::endpoints();

        return isset($registry[$id]) && is_array($registry[$id]) ? $registry[$id] : null;
    }

    public static function defaultEndpointId()
    {
        $id = (string) self::get('endpoints.default', 'primary');

        return self::endpoint($id) !== null ? $id : 'primary';
    }

    /**
     * Resolve a request's Host header to a registry id.
     *
     * Deliberately tolerant: a Host can arrive with a port, in mixed case, or
     * with a trailing dot (FQDN form), and an unknown host -- localhost, a
     * staging name, an IP -- must map to the default entry rather than to
     * nothing. Returning null for "unknown" would make every caller handle a
     * second failure mode for no benefit: the fallback is the primary address,
     * which is what an unrecognised host is almost certain to be.
     *
     * @param string $host
     * @return string registry id
     */
    public static function endpointIdForHost($host)
    {
        $host = strtolower(trim((string) $host));
        // Strip the port (including IPv6 bracket form) and a trailing root dot.
        $host = preg_replace('/:\d+$/', '', $host);
        $host = trim((string) $host, '[]');
        $host = rtrim($host, '.');

        if ($host !== '') {
            foreach (self::endpoints() as $id => $entry) {
                $known = strtolower((string) (isset($entry['host']) ? $entry['host'] : ''));
                if ($known !== '' && $known === $host) {
                    return (string) $id;
                }
            }
        }

        return self::defaultEndpointId();
    }

    /**
     * Only the configured data sources, never credential or policy blocks.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function sources()
    {
        $configured = (array) self::get('sources', array());
        $out = array();
        foreach (self::DATASETS as $id) {
            if (isset($configured[$id]) && is_array($configured[$id])) {
                $out[$id] = $configured[$id];
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function source($key)
    {
        $sources = self::sources();

        return isset($sources[$key]) ? $sources[$key] : null;
    }
}
