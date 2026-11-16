<?php
/**
 * Landing page body.
 *
 * Moved verbatim out of the former index.html so the page can be rendered by PHP:
 * the shell (locale resolution, announcement bar, consent dialog, proof-of-work
 * overlay) has to run on the server, and a static file cannot do that. The markup
 * below is unchanged apart from extensionless links.
 *
 * Available here (set by index.php):
 *   $homeLocale   array  resolved locale
 *   $homeT        callable(key) -> string in the active locale
 *   $homeBlock    callable(key) -> escaped HTML, one <span> per locale
 *   $homeUrl      string canonical base URL
 *   $homeVersion  string service version
 *
 * Every human-visible string comes from conf/i18n-home.php -- $homeBlock keeps the
 * three locales in the document, $homeT supplies attribute values.
 *
 * @var callable $homeT
 * @var callable $homeBlock
 */
?>
<a class="skip-link" href="#main"><?php echo $homeBlock('home.skip'); ?></a>

<!-- ======================================================================
     Header
     ====================================================================== -->
<header class="site-header">
  <div class="wrap site-header__inner">
    <a class="brand" href="#overview">
      <svg class="brand__mark" viewBox="0 0 64 64" width="34" height="34" aria-hidden="true" focusable="false">
        <circle class="logo__planet" cx="32" cy="32" r="10"></circle>
        <g class="logo__ring">
          <ellipse cx="32" cy="32" rx="25" ry="12" fill="none" stroke-width="2.2" transform="rotate(-24 32 32)"></ellipse>
          <circle class="logo__sat" cx="55" cy="21" r="3.4"></circle>
        </g>
      </svg>
      <span class="brand__name">AzureOpenAPI for Satellite</span>
    </a>

    <nav class="nav" aria-label="<?php echo htmlspecialchars($homeT('home.nav.aria'), ENT_QUOTES, 'UTF-8'); ?>">
      <a href="#overview" aria-current="location"><?php echo $homeBlock('home.nav.overview'); ?></a>
      <a href="#endpoints"><?php echo $homeBlock('home.nav.endpoints'); ?></a>
      <a href="#quickstart"><?php echo $homeBlock('home.nav.quickstart'); ?></a>
      <a href="#status"><?php echo $homeBlock('home.nav.status'); ?></a>
      <a href="/docs"><?php echo $homeBlock('home.nav.docs'); ?></a>
    </nav>

    <div class="header__actions">
      <?php echo $homePicker; ?>
      <button class="icon-btn" type="button" data-theme-toggle aria-label="<?php echo htmlspecialchars($homeT('home.theme.toggle'), ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($homeT('home.theme.toggle'), ENT_QUOTES, 'UTF-8'); ?>" aria-pressed="false">
        <svg data-theme-icon="light" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false">
          <circle cx="12" cy="12" r="4.1"></circle>
          <path d="M12 2.4v2.2M12 19.4v2.2M2.4 12h2.2M19.4 12h2.2M5.2 5.2l1.6 1.6M17.2 17.2l1.6 1.6M5.2 18.8l1.6-1.6M17.2 6.8l1.6-1.6"></path>
        </svg>
        <svg data-theme-icon="dark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
          <path d="M20.2 14.6A8.6 8.6 0 0 1 9.4 3.8a8.6 8.6 0 1 0 10.8 10.8Z"></path>
        </svg>
        <span class="sr-only" data-theme-label><?php echo $homeBlock('home.theme.toggle'); ?></span>
      </button>
      <a class="btn btn--sm btn--primary" href="/docs"><?php echo $homeBlock('home.header.docs'); ?></a>
    </div>
  </div>
</header>

<?php /* ======================================================================
     First-screen film
     ======================================================================
     The shape is whitehouse.gov's: the header stays where it is, a full-bleed
     clip takes the rest of the first screen, and the page proper begins below
     it. Nothing is fixed or overlaid -- the band sits IN the document flow, so
     the hero, the endpoint tables and the footer are simply further down, and a
     visitor who does not scroll sees the header and the film.

     Three decisions worth knowing:

       1. NO SRC IN THE MARKUP. film.js picks the encoding (720p on a phone,
          1080p on a desktop) and assigns it. A video element ignores
          <source media>, so the choice cannot be declarative; and without
          JavaScript the poster is what shows -- a still of the same frame,
          rather than a hole in the page.

       2. MUTED, LOOPING, AND NO AUDIO TRACK IN THE FILE. Background video may
          only autoplay muted, and the theme's music is already playing from the
          bottom-left control, so a clip with its own soundtrack would fight it.

       3. THE LEAD LINE IS THE ONLY COPY ON IT, AND IT DOES NOT SIT STILL: the band
          types "Welcome" (or the theme's greeting) in one language, deletes it and
          types the next, with the language's name beside it in the reader's own
          language. The string table is conf/slogans.php.
     ====================================================================== */ ?>
<?php if (is_array($homeFilm)): ?>
<section class="film" data-film>
  <video class="film__video" data-film-video
         muted loop playsinline preload="none"
         poster="<?php echo htmlspecialchars((string) $homeFilm['poster'], ENT_QUOTES, 'UTF-8'); ?>"
<?php /* One variant is enough for the band to exist: Season::film() returns only the
         files that are on disk, and film.js falls back to the other one. Emitting an
         empty data-src for the missing side would be a URL the handler then has to
         guess about. */ ?>
<?php if (isset($homeFilm['sources']['sm']['url'])): ?>
         data-src-sm="<?php echo htmlspecialchars((string) $homeFilm['sources']['sm']['url'], ENT_QUOTES, 'UTF-8'); ?>"
         data-iv-sm="<?php echo htmlspecialchars((string) $homeFilm['sources']['sm']['iv'], ENT_QUOTES, 'UTF-8'); ?>"
<?php endif; ?>
<?php if (isset($homeFilm['sources']['lg']['url'])): ?>
         data-src-lg="<?php echo htmlspecialchars((string) $homeFilm['sources']['lg']['url'], ENT_QUOTES, 'UTF-8'); ?>"
         data-iv-lg="<?php echo htmlspecialchars((string) $homeFilm['sources']['lg']['iv'], ENT_QUOTES, 'UTF-8'); ?>"
<?php endif; ?>         disablepictureinpicture disableremoteplayback
         aria-hidden="true" tabindex="-1"></video>
  <span class="film__veil" aria-hidden="true"></span>
  <?php /* The clip loops forever, so it needs a mechanism to stop it (WCAG 2.2.2).
           It lives IN the band rather than in a fixed corner: it belongs to this
           picture, and it should scroll away with it. film.js swaps the icon, the
           name and aria-pressed; both labels are in the document so the swap costs
           nothing and the string is translated with the rest of the page. */ ?>
  <button class="film__toggle" type="button" data-film-toggle aria-pressed="false"
          data-label-pause="<?php echo htmlspecialchars($homeT('home.film.pause'), ENT_QUOTES, 'UTF-8'); ?>"
          data-label-play="<?php echo htmlspecialchars($homeT('home.film.play'), ENT_QUOTES, 'UTF-8'); ?>">
    <svg data-film-icon="pause" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true" focusable="false">
      <path d="M9.5 5v14M14.5 5v14"></path>
    </svg>
    <svg data-film-icon="play" viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true" focusable="false">
      <path d="M8.5 5.2 19 12 8.5 18.8Z"></path>
    </svg>
  </button>
  <div class="film__inner">
    <?php /* THE BAND'S ONE LINE, AND THE REASON IT IS THREE ELEMENTS.
             [data-film-slogan] is typed letter by letter and is aria-hidden: a phrase
             that rewrites itself every few seconds is noise to a screen reader, so the
             accessible copy is the static, page-language line in .sr-only. The label
             names whichever language is on screen, in the READER's language -- see
             conf/slogans.php. Without JavaScript all three stay as the server wrote
             them: one slogan, one label, no animation. */ ?>
    <p class="film__lead">
      <span class="film__slogan" data-film-slogan aria-hidden="true"><?php echo htmlspecialchars((string) $homeFilm['lead'], ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="film__lang" data-film-lang aria-hidden="true"><?php
          $homeFilmLabel = isset($homeFilm['phrases'][0]['label']) ? (string) $homeFilm['phrases'][0]['label'] : '';
          echo htmlspecialchars($homeFilmLabel, ENT_QUOTES, 'UTF-8');
      ?></span>
      <span class="sr-only" data-film-static><?php echo htmlspecialchars((string) $homeFilm['lead'], ENT_QUOTES, 'UTF-8'); ?></span>
    </p>
  </div>
  <?php /* The rotation, as data. JSON in a script the browser never executes keeps the
           strings out of the markup's reading order and out of the DOM tree the typewriter
           would otherwise have to rebuild; JSON_HEX_TAG/AMP/APOS/QUOT stop a stray
           "</script>" or quote in a translation from closing the element early. */ ?>
  <script type="application/json" data-film-slogans><?php
      echo json_encode(
          isset($homeFilm['phrases']) ? $homeFilm['phrases'] : array(),
          JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      );
  ?></script>
  <a class="film__scroll" href="#overview">
    <span class="film__scrollText"><?php echo $homeBlock('home.film.scroll'); ?></span>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
      <path d="M12 4v15M5.5 12.5 12 19l6.5-6.5"></path>
    </svg>
  </a>
</section>
<?php endif; ?>

<main id="main">

  <!-- ====================================================================
       Hero / Overview
       ==================================================================== -->
  <section class="hero" id="overview" aria-labelledby="overview-title">
    <div class="wrap">
      <div class="hero__grid">
        <div>
          <span class="eyebrow"><?php echo $homeBlock('home.hero.eyebrow'); ?></span>
          <h1 id="overview-title"><?php echo $homeBlock('home.hero.title'); ?></h1>
          <p class="lede">
            <?php echo $homeBlock('home.hero.lede'); ?>
          </p>

          <p class="hero__cta">
            <span class="pill" data-status-pill data-state="unknown" role="status">
              <span class="pill__dot" aria-hidden="true"></span>
              <span class="pill__text"><?php echo $homeBlock('home.status.unknown'); ?></span>
            </span>
          </p>

          <div class="hero__cta">
            <a class="btn btn--primary" href="/docs">
              <?php echo $homeBlock('home.hero.cta'); ?>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                <path d="M5 12h13M12.5 5.5 19 12l-6.5 6.5"></path>
              </svg>
            </a>
            <a class="btn btn--ghost" href="./openapi.yaml">
              <?php echo $homeBlock('home.openapi.spec'); ?>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                <path d="M14 4h6v6M20 4l-9 9M18 14.5V19a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h4.5"></path>
              </svg>
            </a>
          </div>

          <p class="hero__note">
            <?php echo $homeBlock('home.hero.note'); ?> <code>GET <?php echo $homeBaseEsc; ?>/v1/tle</code>
          </p>
        </div>

        <aside class="hero__panel" aria-labelledby="hero-status-title">
          <h2 id="hero-status-title"><?php echo $homeBlock('home.health.title'); ?> /v1/health</h2>
          <dl class="kv">
            <dt><?php echo $homeBlock('home.health.field.overall'); ?></dt>
            <dd data-health-field="overall">—</dd>
            <dt><?php echo $homeBlock('home.health.field.version'); ?></dt>
            <dd data-health-field="version">—</dd>
            <dt><?php echo $homeBlock('home.health.field.uptime'); ?></dt>
            <dd data-health-field="uptime">—</dd>
            <dt><?php echo $homeBlock('home.health.field.redis'); ?></dt>
            <dd data-health-field="redis">—</dd>
            <dt><?php echo $homeBlock('home.health.field.updated'); ?></dt>
            <dd data-health-field="updated_at">—</dd>
          </dl>
          <p class="hero__note mt-2">
            <?php echo $homeBlock('home.health.note'); ?> <code>/v1/health</code> <?php echo $homeBlock('home.health.note.end'); ?>
          </p>
        </aside>
      </div>

      <ul class="stats">
        <li>
          <span class="stats__value">1&nbsp;700+</span>
          <span class="stats__label"><?php echo $homeBlock('home.stats.satellites'); ?></span>
        </li>
        <li>
          <span class="stats__value">4</span>
          <span class="stats__label"><?php echo $homeBlock('home.stats.sources'); ?></span>
        </li>
        <li>
          <!-- Was "3 h". The CelesTrak refresh cadence and the group TTL were both
               raised to 6 h to stop the IP from being rate-limited, so the published
               number had become wrong. -->
          <span class="stats__value">6&nbsp;h</span>
          <span class="stats__label"><?php echo $homeBlock('home.stats.refresh'); ?></span>
        </li>
        <li>
          <!-- Was "99.9% cache hit", which nothing measured. Replaced with a number
               that is read from the same live endpoint as the rest of this panel. -->
          <span class="stats__value" data-health-field="uptime">&#8212;</span>
          <span class="stats__label"><?php echo $homeBlock('home.stats.uptime'); ?></span>
        </li>
      </ul>
      <p class="stats__hint">
        <?php echo $homeBlock('home.stats.hint'); ?> <code>meta.count</code> <?php echo $homeBlock('home.stats.hint.and'); ?> <a href="/v1/status">/v1/status</a><?php echo $homeBlock('home.stats.hint.end'); ?>
      </p>
    </div>
  </section>

  <!-- ====================================================================
       Features
       ==================================================================== -->
  <section class="section" id="features" aria-labelledby="features-title">
    <div class="wrap">
      <div class="section__head">
        <span class="eyebrow"><?php echo $homeBlock('home.features.eyebrow'); ?></span>
        <h2 id="features-title"><?php echo $homeBlock('home.features.title'); ?></h2>
        <p>
          <?php echo $homeBlock('home.features.intro'); ?>
        </p>
      </div>

      <div class="grid grid--3">
        <article class="card">
          <span class="card__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false">
              <path d="M12 3 3 7.5l9 4.5 9-4.5L12 3Z"></path>
              <path d="M3 12l9 4.5 9-4.5"></path>
              <path d="M3 16.5 12 21l9-4.5"></path>
            </svg>
          </span>
          <h3><?php echo $homeBlock('home.features.card1.title'); ?></h3>
          <p>
            <?php echo $homeBlock('home.features.card1.body'); ?> <code>meta.sources</code><?php echo $homeBlock('home.features.card1.end'); ?>
          </p>
          <p class="card__meta">GET /v1/tle</p>
        </article>

        <article class="card">
          <span class="card__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false">
              <rect x="3" y="3.5" width="18" height="7" rx="2"></rect>
              <rect x="3" y="13.5" width="18" height="7" rx="2"></rect>
              <path d="M7 7h.01M7 17h.01"></path>
            </svg>
          </span>
          <h3><?php echo $homeBlock('home.features.card2.title'); ?></h3>
          <p>
            <?php echo $homeBlock('home.features.card2.body'); ?> <code>/v1/tle/weather</code>, <code>/v1/tle/amateur</code>,
            <code>/v1/tle/amsat</code> <?php echo $homeBlock('home.features.card2.and'); ?> <code>/v1/tle/satnogs</code> <?php echo $homeBlock('home.features.card2.end'); ?>
          </p>
          <p class="card__meta">GET /v1/tle/{source}</p>
        </article>

        <article class="card">
          <span class="card__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false">
              <path d="M9.5 4H8a2 2 0 0 0-2 2v2.4A2.6 2.6 0 0 1 3.4 11 2.6 2.6 0 0 1 6 13.6V16a2 2 0 0 0 2 2h1.5"></path>
              <path d="M14.5 4H16a2 2 0 0 1 2 2v2.4A2.6 2.6 0 0 0 20.6 11 2.6 2.6 0 0 0 18 13.6V16a2 2 0 0 1-2 2h-1.5"></path>
            </svg>
          </span>
          <h3><?php echo $homeBlock('home.features.card3.title'); ?></h3>
          <p>
            <?php echo $homeBlock('home.features.card3.body'); ?> <code>Accept: text/plain</code> <?php echo $homeBlock('home.features.card3.or'); ?>
            <code>?format=tle</code> <?php echo $homeBlock('home.features.card3.end'); ?>
          </p>
          <p class="card__meta">Accept: application/json | text/plain</p>
        </article>

        <article class="card">
          <span class="card__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false">
              <path d="M13 2.5 4.8 13.2H11l-1 8.3 8.2-10.7H12l1-8.3Z"></path>
            </svg>
          </span>
          <h3><?php echo $homeBlock('home.features.card4.title'); ?></h3>
          <p>
            <?php echo $homeBlock('home.features.card4.body'); ?> <code>ETag</code><?php echo $homeBlock('home.features.card4.plural'); ?> <code>Cache-Control</code> <?php echo $homeBlock('home.features.card4.with'); ?>
            <code>stale-while-revalidate</code><?php echo $homeBlock('home.features.card4.and'); ?> <code>304</code> <?php echo $homeBlock('home.features.card4.end'); ?>
          </p>
          <p class="card__meta">ETag · If-None-Match · SWR</p>
        </article>

        <article class="card">
          <span class="card__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false">
              <path d="M8.5 6h12M8.5 12h12M8.5 18h12"></path>
              <path d="M3.8 6h.01M3.8 12h.01M3.8 18h.01"></path>
            </svg>
          </span>
          <h3><?php echo $homeBlock('home.features.card5.title'); ?></h3>
          <p>
            <?php echo $homeBlock('home.features.card5.body'); ?> <code>limit</code>/<code>offset</code> <?php echo $homeBlock('home.features.card5.pagination'); ?>
            <code>fields=</code><?php echo $homeBlock('home.features.card5.filters'); ?> <code>norad</code>,
            <code>mode</code> <?php echo $homeBlock('home.features.card5.and'); ?> <code>status</code><?php echo $homeBlock('home.features.card5.end'); ?>
          </p>
          <p class="card__meta">GET /v1/satellites · /v1/transmitters</p>
        </article>

        <article class="card">
          <span class="card__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false">
              <path d="M12 3l7.2 3v5.6c0 4.2-3 7.7-7.2 9.6-4.2-1.9-7.2-5.4-7.2-9.6V6L12 3Z"></path>
              <path d="M9 12.1l2.2 2.2 4.3-4.6"></path>
            </svg>
          </span>
          <h3><?php echo $homeBlock('home.features.card6.title'); ?></h3>
          <p>
            <?php echo $homeBlock('home.features.card6.body'); ?> <code>application/problem+json</code> <?php echo $homeBlock('home.features.card6.media'); ?>
            <code>type</code> <?php echo $homeBlock('home.features.card6.end'); ?>
          </p>
          <p class="card__meta">problem+json · OpenAPI 3.1</p>
        </article>
      </div>

      <div class="btn-row">
        <a class="btn btn--ghost" href="./openapi.yaml"><?php echo $homeBlock('home.openapi.spec'); ?></a>
        <a class="btn btn--ghost" href="/docs"><?php echo $homeBlock('home.features.guides'); ?></a>
      </div>
    </div>
  </section>

  <!-- ====================================================================
       Endpoints
       ==================================================================== -->
  <section class="section section--tight" id="endpoints" aria-labelledby="endpoints-title">
    <div class="wrap">
      <div class="section__head">
        <span class="eyebrow"><?php echo $homeBlock('home.endpoints.eyebrow'); ?></span>
        <h2 id="endpoints-title"><?php echo $homeBlock('home.endpoints.title'); ?></h2>
        <p>
          <?php echo $homeBlock('home.endpoints.intro'); ?> <code>GET</code><?php echo $homeBlock('home.endpoints.intro.end'); ?>
        </p>
      </div>

      <div class="table-wrap" role="region" aria-labelledby="endpoints-title" tabindex="0">
        <table class="data">
          <caption><?php echo $homeBlock('home.endpoints.caption'); ?></caption>
          <thead>
            <tr>
              <th scope="col"><?php echo $homeBlock('home.endpoints.th.method'); ?></th>
              <th scope="col"><?php echo $homeBlock('home.endpoints.th.path'); ?></th>
              <th scope="col"><?php echo $homeBlock('home.endpoints.th.description'); ?></th>
              <th scope="col"><?php echo $homeBlock('home.endpoints.th.ttl'); ?></th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><span class="badge badge--get">GET</span></td>
              <td><span class="path">/v1/tle</span></td>
              <td><?php echo $homeBlock('home.endpoints.row.tle.desc'); ?></td>
              <td><span class="ttl"><?php echo $homeBlock('home.endpoints.row.tle.ttl'); ?></span></td>
            </tr>
            <tr>
              <td><span class="badge badge--get">GET</span></td>
              <td><span class="path">/v1/tle/{source}</span></td>
              <td><?php echo $homeBlock('home.endpoints.row.source.desc'); ?> <code>weather</code>, <code>amateur</code>, <code>amsat</code>, <code>satnogs</code> <?php echo $homeBlock('home.endpoints.row.source.or'); ?> <code>merged</code><?php echo $homeBlock('home.endpoints.row.source.end'); ?></td>
              <td><span class="ttl"><?php echo $homeBlock('home.endpoints.row.source.ttl'); ?></span></td>
            </tr>
            <tr>
              <td><span class="badge badge--get">GET</span></td>
              <td><span class="path">/v1/satellites</span></td>
              <td><?php echo $homeBlock('home.endpoints.row.satellites.desc'); ?></td>
              <td><span class="ttl"><?php echo $homeBlock('home.endpoints.row.satellites.ttl'); ?></span></td>
            </tr>
            <tr>
              <td><span class="badge badge--get">GET</span></td>
              <td><span class="path">/v1/transmitters</span></td>
              <td><?php echo $homeBlock('home.endpoints.row.transmitters.desc'); ?></td>
              <td><span class="ttl"><?php echo $homeBlock('home.endpoints.row.transmitters.ttl'); ?></span></td>
            </tr>
            <tr>
              <td><span class="badge badge--get">GET</span></td>
              <td><span class="path">/v1/amsat/status</span></td>
              <td><?php echo $homeBlock('home.endpoints.row.amsat_status.desc'); ?></td>
              <td><span class="ttl"><?php echo $homeBlock('home.endpoints.row.amsat_status.ttl'); ?></span></td>
            </tr>
            <tr>
              <td><span class="badge badge--get">GET</span></td>
              <td><span class="path">/v1/amsat/*</span></td>
              <td>
                <?php echo $homeBlock('home.endpoints.row.amsat_api.desc'); ?>
                <code>catalog</code>, <code>reports</code>, <code>summary</code>, <code>statuses</code>,
                <code>health</code>, <code>satellites</code>, <code>sat-info</code><?php echo $homeBlock('home.endpoints.row.amsat_api.end'); ?>
                <?php echo $homeBlock('home.endpoints.row.amsat_api.note'); ?>
              </td>
              <td><span class="ttl"><?php echo $homeBlock('home.endpoints.row.amsat_api.ttl'); ?></span></td>
            </tr>
            <tr>
              <td><span class="badge badge--get">GET</span></td>
              <td><span class="path">/v1/health</span></td>
              <td><?php echo $homeBlock('home.endpoints.row.health.desc'); ?></td>
              <td><span class="ttl"><?php echo $homeBlock('home.endpoints.row.health.ttl'); ?></span></td>
            </tr>
            <tr>
              <td><span class="badge badge--get">GET</span></td>
              <td><span class="path">/v1/status</span></td>
              <td><?php echo $homeBlock('home.endpoints.row.status.desc'); ?></td>
              <td><span class="ttl"><?php echo $homeBlock('home.endpoints.row.status.ttl'); ?></span></td>
            </tr>
            <tr>
              <td><span class="badge badge--get">GET</span></td>
              <td><span class="path">/v1/meta</span></td>
              <td><?php echo $homeBlock('home.endpoints.row.meta.desc'); ?></td>
              <td><span class="ttl"><?php echo $homeBlock('home.endpoints.row.meta.ttl'); ?></span></td>
            </tr>
            <tr>
              <td><span class="badge badge--get">GET</span></td>
              <td><span class="path">/v1/maintenance</span></td>
              <td><?php echo $homeBlock('home.endpoints.row.maintenance.desc'); ?></td>
              <td><span class="ttl"><?php echo $homeBlock('home.endpoints.row.maintenance.ttl'); ?></span></td>
            </tr>
          </tbody>
        </table>
      </div>

      <p class="hint">
        <span class="badge badge--deprecated"><?php echo $homeBlock('home.endpoints.deprecated.badge'); ?></span>
        <?php echo $homeBlock('home.endpoints.deprecated.body'); ?> <code>/tle</code>, <code>/tle/{source}</code> <?php echo $homeBlock('home.endpoints.deprecated.and'); ?> <code>/health</code>
        <?php echo $homeBlock('home.endpoints.deprecated.answer'); ?> <code>Deprecation</code> <?php echo $homeBlock('home.endpoints.deprecated.and'); ?> <code>Sunset</code>
        <?php echo $homeBlock('home.endpoints.deprecated.header'); ?> <code>/v1</code> <?php echo $homeBlock('home.endpoints.deprecated.paths'); ?>
      </p>
    </div>
  </section>

  <!-- ====================================================================
       Quickstart
       ==================================================================== -->
  <section class="section section--tight" id="quickstart" aria-labelledby="quickstart-title">
    <div class="wrap">
      <div class="section__head">
        <span class="eyebrow"><?php echo $homeBlock('home.quickstart.eyebrow'); ?></span>
        <h2 id="quickstart-title"><?php echo $homeBlock('home.quickstart.title'); ?></h2>
        <p>
          <?php echo $homeBlock('home.quickstart.intro'); ?>
          <code><?php echo $homeBaseEsc; ?></code> <?php echo $homeBlock('home.quickstart.intro.end'); ?>
        </p>
      </div>

      <div class="tabs" data-tabs>
        <div class="tablist" role="tablist" aria-label="<?php echo htmlspecialchars($homeT('home.quickstart.tabs'), ENT_QUOTES, 'UTF-8'); ?>">
          <button class="tab" type="button" role="tab" id="tab-curl" data-tab="curl" aria-selected="true" aria-controls="panel-curl">cURL</button>
          <button class="tab" type="button" role="tab" id="tab-javascript" data-tab="javascript" aria-selected="false" aria-controls="panel-javascript">JavaScript</button>
          <button class="tab" type="button" role="tab" id="tab-python" data-tab="python" aria-selected="false" aria-controls="panel-python">Python</button>
          <button class="tab" type="button" role="tab" id="tab-php" data-tab="php" aria-selected="false" aria-controls="panel-php">PHP</button>
        </div>

        <div class="tabs__panels">
          <!-- cURL -->
          <div class="tabpanel" id="panel-curl" data-panel="curl" role="tabpanel" aria-labelledby="tab-curl" tabindex="0">
            <div class="code-block">
              <div class="code-block__bar">
                <span class="code-block__title"><?php echo $homeBlock('home.quickstart.caption.curl'); ?></span>
                <button class="copy-btn" type="button" data-copy="code-curl" data-copy-idle="<?php echo htmlspecialchars($homeT('home.copy.label'), ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($homeT('home.quickstart.copy.curl'), ENT_QUOTES, 'UTF-8'); ?>">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <rect x="9" y="9" width="11" height="11" rx="2"></rect>
                    <path d="M15 5.5A1.5 1.5 0 0 0 13.5 4H6a2 2 0 0 0-2 2v7.5A1.5 1.5 0 0 0 5.5 15"></path>
                  </svg>
                  <span data-copy-label><?php echo $homeBlock('home.copy.label'); ?></span>
                </button>
              </div>
<pre><code id="code-curl"># 1. Merged feed — every upstream source, de-duplicated
curl -sS -H 'Accept: application/json' \
  '<?php echo $homeBaseEsc; ?>/v1/tle' | jq '.meta.count, .data[0].name'

# 2. One satellite, JSON envelope (ISS, NORAD 25544)
curl -sS '<?php echo $homeBaseEsc; ?>/v1/tle/weather?norad=25544&amp;format=json'

# 3. Same satellite as classic 20-column TLE lines
curl -sS -H 'Accept: text/plain' \
  '<?php echo $homeBaseEsc; ?>/v1/tle/weather?norad=25544&amp;format=tle'

# 4. Health probe, the same one the status pill uses
curl -sS '<?php echo $homeBaseEsc; ?>/v1/health'</code></pre>
            </div>
          </div>

          <!-- JavaScript -->
          <div class="tabpanel" id="panel-javascript" data-panel="javascript" role="tabpanel" aria-labelledby="tab-javascript" tabindex="0" hidden>
            <div class="code-block">
              <div class="code-block__bar">
                <span class="code-block__title"><?php echo $homeBlock('home.quickstart.caption.js'); ?></span>
                <button class="copy-btn" type="button" data-copy="code-js" data-copy-idle="<?php echo htmlspecialchars($homeT('home.copy.label'), ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($homeT('home.quickstart.copy.js'), ENT_QUOTES, 'UTF-8'); ?>">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <rect x="9" y="9" width="11" height="11" rx="2"></rect>
                    <path d="M15 5.5A1.5 1.5 0 0 0 13.5 4H6a2 2 0 0 0-2 2v7.5A1.5 1.5 0 0 0 5.5 15"></path>
                  </svg>
                  <span data-copy-label><?php echo $homeBlock('home.copy.label'); ?></span>
                </button>
              </div>
<pre><code id="code-js">var BASE = '<?php echo $homeBaseEsc; ?>';

// Merged feed, then one satellite from a single upstream.
fetch(BASE + '/v1/tle', { headers: { Accept: 'application/json' } })
  .then(function (res) {
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return res.json();
  })
  .then(function (envelope) {
    console.log(envelope.meta.count + ' satellites merged');
    return fetch(BASE + '/v1/tle/weather?norad=25544&amp;format=json');
  })
  .then(function (res) { return res.json(); })
  .then(function (envelope) {
    var iss = envelope.data[0];
    console.log(iss.name, iss.epoch);
    console.log(iss.line1);
    console.log(iss.line2);
  })
  .catch(function (err) { console.error('request failed:', err.message); });</code></pre>
            </div>
          </div>

          <!-- Python -->
          <div class="tabpanel" id="panel-python" data-panel="python" role="tabpanel" aria-labelledby="tab-python" tabindex="0" hidden>
            <div class="code-block">
              <div class="code-block__bar">
                <span class="code-block__title">python · requests</span>
                <button class="copy-btn" type="button" data-copy="code-python" data-copy-idle="<?php echo htmlspecialchars($homeT('home.copy.label'), ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($homeT('home.quickstart.copy.python'), ENT_QUOTES, 'UTF-8'); ?>">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <rect x="9" y="9" width="11" height="11" rx="2"></rect>
                    <path d="M15 5.5A1.5 1.5 0 0 0 13.5 4H6a2 2 0 0 0-2 2v7.5A1.5 1.5 0 0 0 5.5 15"></path>
                  </svg>
                  <span data-copy-label><?php echo $homeBlock('home.copy.label'); ?></span>
                </button>
              </div>
<pre><code id="code-python">import requests

BASE = "<?php echo $homeBaseEsc; ?>"

# Merged feed — everything, de-duplicated.
merged = requests.get(BASE + "/v1/tle", timeout=10).json()
print(merged["meta"]["count"], "satellites merged from", merged["meta"]["sources"])

# One satellite, JSON envelope.
iss = requests.get(
    BASE + "/v1/tle/weather",
    params={"norad": 25544, "format": "json"},
    timeout=10,
).json()

sat = iss["data"][0]
print(sat["name"], sat["norad_id"], sat["epoch"])
print(sat["line1"])
print(sat["line2"])</code></pre>
            </div>
          </div>

          <!-- PHP -->
          <div class="tabpanel" id="panel-php" data-panel="php" role="tabpanel" aria-labelledby="tab-php" tabindex="0" hidden>
            <div class="code-block">
              <div class="code-block__bar">
                <span class="code-block__title">php · curl</span>
                <button class="copy-btn" type="button" data-copy="code-php" data-copy-idle="<?php echo htmlspecialchars($homeT('home.copy.label'), ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($homeT('home.quickstart.copy.php'), ENT_QUOTES, 'UTF-8'); ?>">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <rect x="9" y="9" width="11" height="11" rx="2"></rect>
                    <path d="M15 5.5A1.5 1.5 0 0 0 13.5 4H6a2 2 0 0 0-2 2v7.5A1.5 1.5 0 0 0 5.5 15"></path>
                  </svg>
                  <span data-copy-label><?php echo $homeBlock('home.copy.label'); ?></span>
                </button>
              </div>
<pre><code id="code-php">&lt;?php
$base = '<?php echo $homeBaseEsc; ?>';

// Merged feed.
$ch = curl_init($base . '/v1/tle');
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER =&gt; true,
    CURLOPT_HTTPHEADER     =&gt; array('Accept: application/json'),
    CURLOPT_TIMEOUT        =&gt; 10,
));
$merged = json_decode(curl_exec($ch), true);
curl_close($ch);

printf("%d satellites merged\n", $merged['meta']['count']);

// One satellite: ISS, NORAD 25544.
$ch = curl_init($base . '/v1/tle/weather?norad=25544&amp;format=json');
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER =&gt; true,
    CURLOPT_HTTPHEADER     =&gt; array('Accept: application/json'),
    CURLOPT_TIMEOUT        =&gt; 10,
));
$payload = json_decode(curl_exec($ch), true);
curl_close($ch);

$sat = $payload['data'][0];
echo $sat['name'], ' ', $sat['norad_id'], PHP_EOL;
echo $sat['line1'], PHP_EOL, $sat['line2'], PHP_EOL;</code></pre>
            </div>
          </div>
        </div>
      </div>

      <!-- Response example -->
      <div class="mt-3" id="response">
        <h3><?php echo $homeBlock('home.response.title'); ?></h3>
        <p class="lede">
          <?php echo $homeBlock('home.response.lede'); ?> <code>/v1/*</code> <?php echo $homeBlock('home.response.shape'); ?> <code>meta</code> <?php echo $homeBlock('home.response.meta'); ?> <code>data</code> <?php echo $homeBlock('home.response.data'); ?>
        </p>

        <div class="code-block">
          <div class="code-block__bar">
            <span class="code-block__title">200 OK · application/json · GET /v1/tle/weather?norad=25544&amp;format=json</span>
            <button class="copy-btn" type="button" data-copy="code-response" data-copy-idle="<?php echo htmlspecialchars($homeT('home.copy.label'), ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($homeT('home.quickstart.copy.response'), ENT_QUOTES, 'UTF-8'); ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                <rect x="9" y="9" width="11" height="11" rx="2"></rect>
                <path d="M15 5.5A1.5 1.5 0 0 0 13.5 4H6a2 2 0 0 0-2 2v7.5A1.5 1.5 0 0 0 5.5 15"></path>
              </svg>
              <span data-copy-label><?php echo $homeBlock('home.copy.label'); ?></span>
            </button>
          </div>
<pre><code id="code-response">{
  "meta": {
    "service": "satellite-data-api",
    "version": "v<?php echo $homeVersionEsc; ?>",
    "api_version": "v1",
    "generated": "2026-09-15T21:20:04Z",
    "request_id": "0f2c1b7a-5d3e-4f1a-9c88-1b2f4e6a7d10",
    "count": 1,
    "cache": {
      "hit": true,
      "key": "tle:weather:25544:json",
      "ttl": 10800,
      "age": 412
    }
  },
  "data": [
    {
      "norad_id": 25544,
      "name": "ISS (ZARYA)",
      "epoch": "2026-09-15T21:14:23Z",
      "source": "celestrak:weather",
      "line1": "1 25544U 98067A   26258.88499338  .00006292  00000-0  12172-3 0  9990",
      "line2": "2 25544  51.6310 211.2092 0004923 144.3133 215.8185 15.49128922585812"
    }
  ]
}</code></pre>
        </div>

        <p class="hint">
          <?php echo $homeBlock('home.response.error'); ?> <code>data</code> <?php echo $homeBlock('home.response.error.with'); ?>
          <code>{"type":"<?php echo $homeBaseEsc; ?>/problems/norad-not-found","title":"Satellite not found","status":404,"detail":"...","instance":"/v1/tle/weather","request_id":"..."}</code><?php echo $homeBlock('home.response.error.end'); ?>
        </p>
      </div>
    </div>
  </section>

  <!-- ====================================================================
       Status
       ==================================================================== -->
  <section class="section section--tight" id="status" aria-labelledby="status-title">
    <div class="wrap">
      <div class="section__head">
        <span class="eyebrow"><?php echo $homeBlock('home.status.eyebrow'); ?></span>
        <h2 id="status-title"><?php echo $homeBlock('home.status.title'); ?></h2>
        <p>
          <?php echo $homeBlock('home.status.intro'); ?>
          <a href="/v1/health">/v1/health</a> <?php echo $homeBlock('home.status.intro.end'); ?>
        </p>
      </div>

      <div class="grid grid--3">
        <article class="card">
          <h3><?php echo $homeBlock('home.status.overall'); ?></h3>
          <p class="hero__cta">
            <span class="pill" data-status-pill data-state="unknown">
              <span class="pill__dot" aria-hidden="true"></span>
              <span class="pill__text"><?php echo $homeBlock('home.status.unknown'); ?></span>
            </span>
          </p>
          <p class="card__meta">GET /v1/health</p>
        </article>

        <article class="card">
          <h3><?php echo $homeBlock('home.status.runtime'); ?></h3>
          <dl class="kv">
            <dt><?php echo $homeBlock('home.health.field.version'); ?></dt>
            <dd data-health-field="version">—</dd>
            <dt><?php echo $homeBlock('home.health.field.uptime'); ?></dt>
            <dd data-health-field="uptime">—</dd>
            <dt><?php echo $homeBlock('home.health.field.redis'); ?></dt>
            <dd data-health-field="redis">—</dd>
            <dt><?php echo $homeBlock('home.health.field.updated'); ?></dt>
            <dd data-health-field="updated_at">—</dd>
          </dl>
        </article>

        <article class="card">
          <h3><?php echo $homeBlock('home.status.freshness'); ?></h3>
          <p>
            <?php echo $homeBlock('home.status.freshness.body'); ?> <a href="/v1/status">/v1/status</a> <?php echo $homeBlock('home.status.freshness.and'); ?> <a href="/v1/maintenance">/v1/maintenance</a><?php echo $homeBlock('home.status.freshness.end'); ?>
          </p>
          <p class="card__meta">GET /v1/status · GET /v1/maintenance</p>
        </article>
      </div>
    </div>
  </section>

  <!-- ====================================================================
       Sources & attribution
       ==================================================================== -->
  <section class="section section--tight" id="sources" aria-labelledby="sources-title">
    <div class="wrap">
      <div class="section__head">
        <span class="eyebrow"><?php echo $homeBlock('home.sources.eyebrow'); ?></span>
        <h2 id="sources-title"><?php echo $homeBlock('home.sources.title'); ?></h2>
        <p>
          <?php echo $homeBlock('home.sources.intro'); ?>
        </p>
      </div>

      <div class="attrib">
        <article class="card">
          <p class="badge-row"><span class="badge badge--info"><?php echo $homeBlock('home.sources.celestrak.badge'); ?></span></p>
          <h3>CelesTrak</h3>
          <p>
            <?php echo $homeBlock('home.sources.celestrak.body'); ?> <code>/v1/tle/weather</code> <?php echo $homeBlock('home.sources.celestrak.and'); ?> <code>/v1/tle/amateur</code><?php echo $homeBlock('home.sources.celestrak.end'); ?>
          </p>
          <p><a href="https://celestrak.org" rel="noopener noreferrer">https://celestrak.org</a></p>
          <p class="attrib__tag"><?php echo $homeBlock('home.sources.celestrak.tag'); ?></p>
        </article>

        <article class="card">
          <p class="badge-row"><span class="badge badge--info"><?php echo $homeBlock('home.sources.amsat.badge'); ?></span></p>
          <h3>AMSAT</h3>
          <p>
            <?php echo $homeBlock('home.sources.amsat.body'); ?> <strong>AMSAT Satellite Status API
            (v1)</strong><?php echo $homeBlock('home.sources.amsat.after'); ?> <code>/v1/amsat/status</code>
            <?php echo $homeBlock('home.sources.amsat.set'); ?> <code>/v1/amsat/*</code>
            (<code>catalog</code>, <code>reports</code>, <code>summary</code>, <code>statuses</code>,
            <code>health</code> <?php echo $homeBlock('home.sources.amsat.tail'); ?>
          </p>
          <p><a href="https://www.amsat.org" rel="noopener noreferrer">https://www.amsat.org</a></p>
          <ul class="attrib__links">
            <li>
              <a href="https://status.amsat.org/api/v1/" rel="noopener noreferrer">status.amsat.org/api/v1/</a>
              <span><?php echo $homeBlock('home.sources.amsat.link1'); ?></span>
            </li>
            <li>
              <a href="https://status.amsat.org/api/v1/docs.php" rel="noopener noreferrer">/api/v1/docs.php</a>
              <span><?php echo $homeBlock('home.sources.amsat.link2'); ?></span>
            </li>
            <li>
              <a href="https://status.amsat.org/api/v1/openapi.php" rel="noopener noreferrer">/api/v1/openapi.php</a>
              <span><?php echo $homeBlock('home.sources.amsat.link3'); ?></span>
            </li>
            <li>
              <a href="https://status.amsat.org/api/v1/overview.php" rel="noopener noreferrer">/api/v1/overview.php</a>
              <span><?php echo $homeBlock('home.sources.amsat.link4'); ?></span>
            </li>
            <li>
              <a href="https://status.amsat.org/api/v1/health.php" rel="noopener noreferrer">/api/v1/health.php</a>
              <span><?php echo $homeBlock('home.sources.amsat.link5'); ?></span>
            </li>
            <li>
              <a href="https://github.com/AMSAT-NA/satellite-status/blob/main/CHANGELOG" rel="noopener noreferrer">AMSAT-NA/satellite-status CHANGELOG</a>
              <span><?php echo $homeBlock('home.sources.amsat.link6'); ?></span>
            </li>
          </ul>
          <p class="attrib__tag"><?php echo $homeBlock('home.sources.amsat.tag'); ?></p>
        </article>

        <article class="card">
          <p class="badge-row"><span class="badge badge--info"><?php echo $homeBlock('home.sources.satnogs.badge'); ?></span></p>
          <h3>SatNOGS DB</h3>
          <p>
            <?php echo $homeBlock('home.sources.satnogs.body'); ?>
            <code>/v1/satellites</code> <?php echo $homeBlock('home.sources.satnogs.and'); ?> <code>/v1/transmitters</code><?php echo $homeBlock('home.sources.satnogs.end'); ?>
          </p>
          <p><a href="https://db.satnogs.org" rel="noopener noreferrer">https://db.satnogs.org</a></p>
          <p class="attrib__tag"><?php echo $homeBlock('home.sources.satnogs.tag'); ?></p>
        </article>
      </div>

      <div class="callout callout--info mt-2">
        <div>
          <strong><?php echo $homeBlock('home.sources.licence.heading'); ?></strong> <?php echo $homeBlock('home.sources.licence.body'); ?>
        </div>
      </div>
    </div>
  </section>
</main>

<!-- ======================================================================
     Footer
     ====================================================================== -->
<footer class="site-footer">
  <div class="wrap">
    <div class="footer__grid">
      <div>
        <p class="footer__title">AzureOpenAPI for Satellite</p>
        <p class="muted mb-0">
          <?php echo $homeBlock('home.footer.intro'); ?>
        </p>
      </div>

      <div>
        <p class="footer__title"><?php echo $homeBlock('home.footer.docs'); ?></p>
        <ul class="footer__links">
          <li><a href="/docs"><?php echo $homeBlock('home.footer.api_docs'); ?></a></li>
          <li><a href="/openapi.yaml"><?php echo $homeBlock('home.openapi.spec'); ?></a></li>
          <li><a href="/v1/status"><?php echo $homeBlock('home.footer.service_status'); ?></a></li>
          <li><a href="/v1/meta"><?php echo $homeBlock('home.footer.service_meta'); ?></a></li>
        </ul>
      </div>

      <div>
        <p class="footer__title"><?php echo $homeBlock('home.footer.policies'); ?></p>
        <ul class="footer__links">
          <li><a href="/robots.txt">robots.txt</a></li>
          <li><a href="/.well-known/security.txt">security.txt</a></li>
          <li><a href="/v1/health"><?php echo $homeBlock('home.footer.health'); ?></a></li>
        </ul>
      </div>
    </div>

    <?php /* --------------------------------------------- mainland-only notice
           Rendered on the SERVER, and only when Region resolved this request to
           mainland China (excluding Hong Kong, Macao and Taiwan). The page is then
           served no-store, because a document that depends on the client's address
           must never become the cached answer for somebody else -- the same class of
           mistake as caching a per-visitor cookie decision (see HomePage::render).

           The link points at /no-accel, a permanent redirect to the written notice,
           so the short address printed here survives a future rename. */ ?>
    <?php if (!empty($homeMainland)): ?>
    <div class="cnfoot" role="note">
      <p class="cnfoot__fraud"><?php echo $homeBlock('footer.region.fraud'); ?></p>
      <p class="cnfoot__policy"><?php echo $homeBlock('footer.region.policy_before'); ?><a href="/no-accel"><?php echo $homeBlock('footer.region.policy_link'); ?></a><?php echo $homeBlock('footer.region.after'); ?></p>
    </div>
    <?php endif; ?>

    <div class="footer__bottom">
      <p><?php echo $homeBlock('home.footer.data'); ?></p>
      <p>
        <?php echo $homeBlock('home.footer.sources'); ?> <span class="version" data-meta-field="sources">CelesTrak · AMSAT · SatNOGS</span>
        · <?php echo $homeBlock('home.footer.service'); ?> <span class="version" data-meta-field="version">v<?php echo $homeVersionEsc; ?></span>
        <span class="sr-only"><?php echo $homeBlock('home.footer.fallback'); ?></span>
      </p>
    </div>
  </div>
</footer>
