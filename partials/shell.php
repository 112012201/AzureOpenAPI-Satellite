<?php
/**
 * Shared page shell: locale resolution, announcement bar, consent dialog,
 * proof-of-work overlay and the boot script.
 *
 * Included by index.php and the error page. It reads its inputs from these
 * variables, all optional:
 *
 *   $bootPage      string  page key for <body data-page>, default 'index'
 *   $bootTitle     string  <title> already echoed by the caller; unused here
 *   $bootOwnConsent bool   true to render the consent dialog (index/legal only)
 *
 * Design decision: the page renders EVERY locale's text into the document and
 * marks one as active. That way a visitor with JavaScript disabled still reads the
 * correct language (the server picked it from Accept-Language), switching is
 * instant and needs no reload, and crawlers see real content rather than text
 * injected after load. The cost is a few kilobytes of duplicated text, which is
 * cheaper than either a flash of the wrong language or a JS-only page.
 *
 * The proof-of-work overlay is rendered but hidden. It is revealed by the boot
 * script only if the challenge is actually enabled, so a deployment without Cap
 * never shows it -- and a visitor on a text browser is not blocked by markup.
 *
 * @var string|null $bootPage
 * @var bool|null   $bootOwnConsent
 * @var bool|null   $bootStatic  true when the caller writes a static artifact
 *                               (bin/build-error-page.php): time-dependent content
 *                               such as the announcement board is then skipped
 */

if (!defined('SATAPI_BOOTSTRAP')) {
    require dirname(__DIR__) . '/bootstrap.php';
}

// Direct access guard.
//
// nginx already refuses /partials/ (see the `^~ /partials/` rule), but a partial
// that renders internal state must not depend on the web server configuration for
// its safety: a rule can be removed, the file can be reached through a different
// server block, or the site could be moved to a host with no rewrite at all.
//
// The contract is that every caller sets $bootPage before requiring this file.
// Reaching it without that is abnormal, so it answers 404 and stops rather than
// publishing the cookie inventory and the consent state.
if (!defined('SATAPI_BOOTSTRAP') || !isset($bootPage)) {
    if (!headers_sent()) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "Not Found\n";
    exit;
}

use SatApi\Config;
use SatApi\Http\Request;
use SatApi\Support\Announcement;
use SatApi\Support\AnnouncementBoard;
use SatApi\Support\Consent;
use SatApi\Support\Endpoint;
use SatApi\Support\I18n;
use SatApi\Support\Pow;

$bootRequest = Request::capture();
$bootLocale = I18n::resolve($bootRequest);
$bootCode = $bootLocale['code'];
// The BCP 47 tag the document is served as: 'en-US' or 'en-GB' for English, the
// content code itself for Chinese. The boot payload carries the tag (so
// assets/consent.js can put it in <html lang>) and the content code separately.
$bootTag = isset($bootLocale['tag']) ? (string) $bootLocale['tag'] : $bootCode;
$bootT = static function ($key, $fallback = '') use ($bootCode) {
    return I18n::translate($bootCode, $key, $fallback);
};
$bootTAll = static function ($key) {
    $out = array();
    foreach (I18n::locales() as $code) {
        $out[$code] = I18n::translate($code, $key, $key);
    }

    return $out;
};
$bootPage = isset($bootPage) ? (string) $bootPage : 'index';
$bootOwnConsent = isset($bootOwnConsent) ? (bool) $bootOwnConsent : true;

/*
 * The seasonal skin is resolved HERE, once, before any renderer asks: the head, the
 * body class and this partial's own badge all have to agree, and resolving three
 * times would be three chances to disagree.
 */
\SatApi\Support\Season::boot($bootRequest);

$announcement = Announcement::current($bootRequest);
$consentVerdict = Consent::describe($bootRequest);
Consent::setCurrent($consentVerdict);
$consentPayload = Consent::payload($consentVerdict);
/*
 * A legal document is not a challenge: when the caller sets $bootOwnOverlay to
 * false the loading screen is not rendered and the proof of work is not armed,
 * so a reader opening the privacy policy sees the policy instead of a progress
 * bar. Everything else the shell provides (locale, announcement, consent) stays.
 */
$bootOwnOverlay = isset($bootOwnOverlay) ? (bool) $bootOwnOverlay : true;
$powStatus = Pow::status($bootRequest);
if (!$bootOwnOverlay) {
    $powStatus['enabled'] = false;
    $powStatus['state'] = 'disabled';
    $powStatus['site_key'] = '';
}
$cookieStatus = Pow::cookieStatus($bootRequest);
$bootEndpoint = Endpoint::current($bootRequest);

/*
 * The announcement board (the dialog), as opposed to $announcement above (the one
 * line bar driven by configuration).
 *
 * The static error page sets $bootStatic: it is a file generated once by
 * bin/build-error-page.php, so an announcement baked into it would keep showing
 * after its `expires` date -- the same class of mistake as baking a HOSTNAME into
 * it (see the endpoint-note comment further down). A time-dependent artifact has
 * to opt out of time-dependent content.
 */
$bootStatic = !empty($bootStatic);
$bootBoard = $bootStatic ? array() : AnnouncementBoard::active($bootRequest);

/**
 * Emit a translated block: one <span> per locale, all present in the DOM.
 *
 * The server marks the active locale with `is-active` and hides the rest, so the
 * correct language paints on the first frame and JavaScript only ever switches,
 * never fills in.
 */
$bootBlock = static function ($key, $tag = 'span', $attrs = '') use ($bootTAll, $bootCode) {
    $all = $bootTAll($key);
    $out = '';
    foreach ($all as $code => $text) {
        $class = 'i18n' . ($code === $bootCode ? ' is-active' : '');
        $out .= '<' . $tag . ' class="' . $class . '" lang="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8')
            . '" data-locale="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '" '
            . ($code === $bootCode ? '' : 'hidden ') . $attrs . '>'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
            . '</' . $tag . '>';
    }

    return $out;
};
?><!---- shell:locale <?php echo htmlspecialchars($bootCode, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($bootLocale['source'], ENT_QUOTES, 'UTF-8'); ?>) ---->
<?php if ($announcement !== null): ?>
<div class="announce" data-announce data-announce-id="<?php echo htmlspecialchars($announcement['id'], ENT_QUOTES, 'UTF-8'); ?>" data-level="<?php echo htmlspecialchars($announcement['level'], ENT_QUOTES, 'UTF-8'); ?>" role="status">
  <div class="announce__inner">
    <span class="announce__icon" aria-hidden="true"></span>
    <p class="announce__text">
      <?php echo $bootBlock('announce.default'); ?>
      <span class="announce__body"><?php echo htmlspecialchars($announcement['text'], ENT_QUOTES, 'UTF-8'); ?></span>
      <?php if ($announcement['href'] !== ''): ?>
        <a class="announce__link" href="<?php echo htmlspecialchars($announcement['href'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo $bootBlock('announce.learn'); ?></a>
      <?php endif; ?>
    </p>
    <button class="announce__close" type="button" data-announce-close aria-label="<?php echo htmlspecialchars($bootT('announce.dismiss'), ENT_QUOTES, 'UTF-8'); ?>">
      <span aria-hidden="true">&times;</span>
    </button>
  </div>
</div>
<?php endif; ?>
<?php /* --------------------------------------------------------- endpoint notice
       One strip, three addresses, three different ways of letting a visitor down.

       Rendered server-side and on every page, not just the landing page, because
       the notice is a property of the HOSTNAME: a visitor who arrives on a deep
       link (docs, legal, a 404) is on the same address and deserves the same
       warning. Static markup, so it does not depend on JavaScript.

       All three addresses serve the same application and the same routes; what
       differs is how the traffic gets there and therefore how it can fail:

         direct origin (satellite.authskin.xyz)  no edge to absorb a spike, so a
                                                 busy moment is felt as an outage
         EdgeOne       (satellite.api.authskin.xyz) nodes replicate, so a refresh
                                                 can be briefly behind
         SCDN          (satellite.db.authskin.xyz) brand-new path, still being
                                                 characterised

       The words live in conf/i18n.php, written per locale rather than machine
       translated, and the level comes from config: 'warning' when the visitor can
       lose service, 'info' when the risk is only data age. Colouring both the same
       would teach people to ignore the strip.

       The two cache readings are only rendered when the address actually has an
       edge (`cdn_header` is non-empty). On the direct address there is no edge
       verdict to read, so a pair of em dashes would be noise pretending to be
       information. app.js fills them from the headers of a LIVE response -- a cache
       verdict the server cannot know must not be invented here.

       NO HOST HEADER MEANS NO NOTICE. The error page is a STATIC artifact generated
       by a CLI run (bin/build-error-page.php), and a CLI request has no Host. The
       registry then falls back to the default entry, which would bake the EdgeOne
       sentence into a file served to every hostname -- measured: error.html went
       from 7093 to 8944 bytes and claimed "EdgeOne accelerated" even for visitors
       on the direct address. A static page has no address, so it must not claim
       one. */ ?>
<?php if (trim((string) $bootRequest->header('host', '')) !== '' && !empty($bootEndpoint['notice'])): ?>
<div class="enote" data-endpoint-note="<?php echo htmlspecialchars((string) $bootEndpoint['id'], ENT_QUOTES, 'UTF-8'); ?>" data-level="<?php echo htmlspecialchars((string) (isset($bootEndpoint['notice_level']) ? $bootEndpoint['notice_level'] : 'info'), ENT_QUOTES, 'UTF-8'); ?>" role="note">
  <div class="enote__inner">
<?php if (trim((string) $bootEndpoint['badge']) !== ''): ?>
    <span class="enote__badge"><?php echo $bootBlock((string) $bootEndpoint['badge']); ?></span>
<?php endif; ?>
    <p class="enote__text"><?php echo $bootBlock((string) $bootEndpoint['notice']); ?></p>
    <p class="enote__meta">
      <span class="enote__host"><?php echo htmlspecialchars((string) $bootEndpoint['host'], ENT_QUOTES, 'UTF-8'); ?></span>
<?php if (trim((string) $bootEndpoint['cdn_header']) !== ''): ?>
      <span class="enote__cache"><?php echo $bootBlock('cache.edge'); ?>: <b data-edge-cache data-unknown="&mdash;">&mdash;</b></span>
<?php endif; ?>
      <span class="enote__cache"><?php echo $bootBlock('cache.origin'); ?>: <b data-origin-cache data-unknown="&mdash;">&mdash;</b></span>
    </p>
  </div>
</div>
<?php endif; ?>
<?php if ($bootOwnConsent): ?>
<div class="consent" data-consent hidden role="dialog" aria-modal="true" aria-labelledby="consent-title">
  <div class="consent__panel">
    <h2 class="consent__title" id="consent-title"><?php echo $bootBlock('consent.title'); ?></h2>
    <p class="consent__intro"><?php echo $bootBlock('consent.intro'); ?></p>
    <ul class="consent__cats">
      <li class="consent__cat">
        <div class="consent__cat-head">
          <strong><?php echo $bootBlock('consent.cat.essential.title'); ?></strong>
          <span class="chip chip--ok"><?php echo $bootBlock('consent.cat.essential.title'); ?></span>
        </div>
        <p><?php echo $bootBlock('consent.cat.essential.desc'); ?></p>
        <ul class="consent__list">
          <?php foreach ((array) Config::get('cookies.essential', array()) as $name => $why): ?>
            <li><code><?php echo htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8'); ?></code> &mdash; <?php echo htmlspecialchars((string) $why, ENT_QUOTES, 'UTF-8'); ?></li>
          <?php endforeach; ?>
        </ul>
      </li>
      <li class="consent__cat">
        <div class="consent__cat-head">
          <strong><?php echo $bootBlock('consent.cat.preferences.title'); ?></strong>
        </div>
        <p><?php echo $bootBlock('consent.cat.preferences.desc'); ?></p>
        <ul class="consent__list">
          <?php foreach ((array) Config::get('cookies.preferences', array()) as $name => $why): ?>
            <li><code><?php echo htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8'); ?></code> &mdash; <?php echo htmlspecialchars((string) $why, ENT_QUOTES, 'UTF-8'); ?></li>
          <?php endforeach; ?>
        </ul>
      </li>
      <?php if ((array) Config::get('cookies.analytics', array()) !== array()): ?>
      <li class="consent__cat">
        <div class="consent__cat-head">
          <strong><?php echo $bootBlock('consent.cat.analytics.title'); ?></strong>
        </div>
        <p><?php echo $bootBlock('consent.cat.analytics.desc'); ?></p>
        <ul class="consent__list">
          <?php foreach ((array) Config::get('cookies.analytics', array()) as $name => $why): ?>
            <li><code><?php echo htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8'); ?></code> &mdash; <?php echo htmlspecialchars((string) $why, ENT_QUOTES, 'UTF-8'); ?></li>
          <?php endforeach; ?>
        </ul>
      </li>
      <?php endif; ?>
    </ul>
    <p class="consent__links">
      <a href="<?php echo htmlspecialchars((string) Config::get('consent.docs.privacy', '/legal/privacy'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $bootBlock('consent.policy_link'); ?></a>
      <span aria-hidden="true">&middot;</span>
      <a href="<?php echo htmlspecialchars((string) Config::get('consent.docs.terms', '/legal/terms'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $bootBlock('consent.terms_link'); ?></a>
    </p>
    <div class="consent__actions">
      <button class="btn btn--ghost" type="button" data-consent-choice="reject_all"><?php echo $bootBlock('consent.reject_all'); ?></button>
      <button class="btn btn--ghost" type="button" data-consent-choice="essential_only"><?php echo $bootBlock('consent.essential_only'); ?></button>
      <button class="btn btn--primary" type="button" data-consent-choice="accept_all"><?php echo $bootBlock('consent.accept_all'); ?></button>
    </div>
    <p class="consent__note" data-consent-note hidden></p>
  </div>
</div>
<?php endif; ?>
<?php /* ------------------------------------------------------ announcement board
       The dialog the operator publishes by editing content/announcements/*.md.

       Three states, all decided in the BROWSER, because the document is cached and
       shared between visitors (the same reason the cookie dialog reads its own
       decision from document.cookie):

         unread    the panel is shown; the visitor may read/agree, ask again next
                   time ("later", which stores nothing), or refuse -- refusing is
                   only offered by announcements whose file says `refusable: true`
         read      the id is in the announcement_read cookie, so this panel is not
                   shown again until the cookie expires
         refused   the id is in the announcement_declined cookie: the page body is
                   covered by an opaque gate that offers a reload. Reloading clears
                   the refusal and asks again, which is the only way back.

       Every panel is rendered here, in the document, so the board costs one request
       instead of two and works with the same "server renders, script only toggles"
       rule as the rest of the shell. Nothing in this markup is per-visitor: the
       JSON payload carries only the cookie NAMES and the acknowledgement lifetime,
       never a choice.

       The body HTML is compiled (see AnnouncementBoard::markdown) and is already
       HTML-escaped there; raw HTML in a .md file is inert by construction. Adding a
       second escape here would double-encode it. */ ?>
<?php if ($bootBoard !== array()): ?>
<div class="anm" data-anm hidden>
<?php foreach ($bootBoard as $bootAnm): ?>
  <article class="anm__panel" role="dialog" aria-modal="true" aria-labelledby="anm-title-<?php echo htmlspecialchars((string) $bootAnm['id'], ENT_QUOTES, 'UTF-8'); ?>" hidden
           data-anm-item
           data-anm-id="<?php echo htmlspecialchars((string) $bootAnm['id'], ENT_QUOTES, 'UTF-8'); ?>"
           data-anm-lang="<?php echo htmlspecialchars((string) (isset($bootAnm['lang']) ? $bootAnm['lang'] : 'all'), ENT_QUOTES, 'UTF-8'); ?>"
           data-anm-level="<?php echo htmlspecialchars((string) $bootAnm['level'], ENT_QUOTES, 'UTF-8'); ?>"
           data-anm-refusable="<?php echo $bootAnm['refusable'] ? '1' : '0'; ?>"
           data-anm-ack="<?php echo htmlspecialchars((string) $bootAnm['ack'], ENT_QUOTES, 'UTF-8'); ?>">
    <header class="anm__head">
      <p class="anm__chips">
        <span class="anm__chip" data-level="<?php echo htmlspecialchars((string) $bootAnm['level'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo $bootBlock('anm.level.' . $bootAnm['level']); ?></span>
        <span class="anm__eyebrow"><?php echo $bootBlock('anm.eyebrow'); ?></span>
      </p>
      <h2 class="anm__title" id="anm-title-<?php echo htmlspecialchars((string) $bootAnm['id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $bootAnm['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
      <p class="anm__meta">
        <span><span class="anm__meta-key"><?php echo $bootBlock('anm.meta.date'); ?></span> <time datetime="<?php echo htmlspecialchars((string) $bootAnm['date'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $bootAnm['date'], ENT_QUOTES, 'UTF-8'); ?></time></span>
        <span><span class="anm__meta-key"><?php echo $bootBlock('anm.meta.author'); ?></span> <b><?php echo htmlspecialchars((string) $bootAnm['author'], ENT_QUOTES, 'UTF-8'); ?></b></span>
      </p>
    </header>
    <div class="anm__body"><?php echo $bootAnm['html']; ?></div>
    <footer class="anm__actions">
<?php if ($bootAnm['refusable']): ?>
      <span class="anm__decline-wrap">
        <button class="btn btn--ghost anm__decline" type="button" data-anm-decline><?php echo $bootBlock('anm.decline'); ?></button>
        <span class="anm__hint"><?php echo $bootBlock('anm.decline_hint'); ?></span>
      </span>
<?php endif; ?>
      <button class="btn btn--ghost" type="button" data-anm-later><?php echo $bootBlock('anm.later'); ?></button>
      <button class="btn btn--primary" type="button" data-anm-ack><?php echo $bootBlock($bootAnm['ack'] === 'agree' ? 'anm.ack.agree' : 'anm.ack.read'); ?></button>
    </footer>
  </article>
<?php endforeach; ?>
</div>

<?php /* The refusal gate. Opaque by design: the requirement is that a refused
       notice hides the page CONTENT, so this covers the viewport rather than
       floating over it. It is a client-side cover and is documented as such --
       the HTML was already sent, and making the server withhold it would mean
       rendering the page per visitor, which is the cache-poisoning bug this
       project removed twice. */ ?>
<div class="anm-gate" data-anm-gate hidden role="dialog" aria-modal="true" aria-labelledby="anm-gate-title">
  <div class="anm-gate__panel">
    <span class="anm-gate__mark" aria-hidden="true"></span>
    <h2 class="anm-gate__title" id="anm-gate-title"><?php echo $bootBlock('anm.gate.title'); ?></h2>
    <p class="anm-gate__text">
      <span><?php echo $bootBlock('anm.gate.lead'); ?></span><b data-anm-gate-name></b><span><?php echo $bootBlock('anm.gate.tail'); ?></span>
    </p>
    <p class="anm-gate__hint"><?php echo $bootBlock('anm.gate.hint'); ?></p>
    <button class="btn btn--primary" type="button" data-anm-gate-reload><?php echo $bootBlock('anm.gate.reload'); ?></button>
  </div>
</div>
<?php endif; ?>
<?php echo \SatApi\Support\Season::badge(); ?>
<?php echo \SatApi\Support\Season::player(); ?>
<?php if ($bootOwnOverlay): ?>
<div class="boot" data-boot data-pow-enabled="<?php echo $powStatus['enabled'] ? '1' : '0'; ?>" data-pow-endpoint="<?php echo htmlspecialchars((string) $powStatus['endpoint'], ENT_QUOTES, 'UTF-8'); ?>" data-pow-sitekey="<?php echo htmlspecialchars((string) $powStatus['site_key'], ENT_QUOTES, 'UTF-8'); ?>">
  <?php /* Structure mirrors the reference loading screen, which the operator asked to be
           studied and reproduced. Its component renders exactly these parts:

             .bg           a full-bleed layer whose blur is a FUNCTION of progress
                           (blur(8 - done/total*8)px), so the page comes into focus as
                           work completes rather than snapping at the end
             .logo         the mark
             .moreDeco     .deco + .divider + .slogan + .triangles
             .progress     .progressBar (grows) + .progressText
                             .core   .value (the figure) + .symbol (the unit)
                             .deco   the status caption

           The three phases are driven by real task completion, not a timer, so the
           markup carries only what the phases need: a bar, a figure, and a caption. */ ?>
  <div class="boot__bg" data-boot-bg aria-hidden="true"></div>

  <div class="boot__logo" aria-hidden="true">
    <span class="boot__glyph"></span>
  </div>

  <div class="boot__moreDeco" aria-hidden="true">
    <div class="boot__deco"></div>
    <div class="boot__divider"></div>
    <?php /* THE TAGLINE LIVES IN THIS LITERAL, AND THAT IS NOT AN OVERSIGHT TO TIDY UP.
           Config::get('service.tagline', …) is asked for, but the key is set NOWHERE: not in
           lib/Config.php's `service` block, not in the operator's conf/config.php, and there is no
           SATAPI_SERVICE_TAGLINE entry in Config::applyEnvironment()'s explicit map. So this
           fallback is the de-facto source of truth and the only place the live page reads it from.
           If it is ever promoted into lib/Config.php, add the env mapping at the same time AND
           replace this fallback with a neutral default -- leaving a second copy here means a later
           edit to this line renders nothing at all, because Config would win silently. */ ?>
<?php
    /*
     * During a themed period the loading screen says the theme's greeting instead of
     * the service tagline: the overlay is the first thing a visitor sees, and "Merry
     * Christmas!" belongs there rather than over a Christmas-coloured page.
     *
     * The text comes from the same string table as everything else (theme.greeting.*),
     * so it is translated with the rest of the site rather than in here.
     */
    $bootGreeting = \SatApi\Support\Season::greeting();
    ?>
    <p class="boot__slogan"><?php echo htmlspecialchars(
        $bootGreeting !== '' ? $bootGreeting : (string) Config::get('service.tagline', 'The Satellite Keeps Spinning...'),
        ENT_QUOTES,
        'UTF-8'
    ); ?></p>
    <div class="boot__triangles"></div>
  </div>

  <div class="boot__progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
       aria-label="<?php echo htmlspecialchars(I18n::translate($bootCode, 'loading.title', 'Loading'), ENT_QUOTES, 'UTF-8'); ?>"
       data-boot-bar>
    <div class="boot__progressBar" data-boot-fill></div>
    <div class="boot__progressText" data-boot-text>
      <div class="boot__core">
        <span class="boot__value" data-boot-figure>0</span><span class="boot__symbol">%</span>
      </div>
      <div class="boot__caption" data-boot-caption><?php echo $bootBlock('pow.working'); ?></div>
    </div>
  </div>

  <p class="boot__hint boot__hint--sr" data-boot-hint hidden><?php echo $bootBlock('pow.working'); ?></p>
  <p class="boot__error" data-boot-error hidden>
    <span data-boot-fail hidden><?php echo $bootBlock('pow.unavailable'); ?></span>
    <?php echo $bootBlock('pow.failed'); ?>
    <button class="btn btn--sm btn--ghost" type="button" data-boot-retry><?php echo $bootBlock('pow.retry'); ?></button>
    <button class="btn btn--sm btn--ghost" type="button" data-boot-skip><?php echo $bootBlock('pow.skip'); ?></button>
  </p>
</div>
<?php endif; ?>
<script>
/* Boot facts for assets/consent.js. Kept inline and tiny so the document is
   self-describing: the page still renders if this script never runs, because the
   server already picked the locale and already rendered the announcement and the
   consent dialog markup. */
window.__SATAPI_BOOT__ = <?php echo json_encode(array(
    'locale'        => $bootTag,
    'localeCode'    => $bootCode,
    'localeSource'  => $bootLocale['source'],
    'localeZone'    => isset($bootLocale['zone']) ? $bootLocale['zone'] : 'intl',
    'locales'       => I18n::locales(),
    'cookieName'    => 'cookie_consent',
    'cookieVersion' => (int) Config::get('cookies.version', 1),
    'cookieDays'    => (int) Config::get('cookies.lifetime_days', 180),
    'langCookie'    => I18n::COOKIE_NAME,
    'consent'       => $consentPayload,
    'consentRequired' => (bool) Config::get('consent.required', false),
    'pow'           => $powStatus,
    // Strings the scripts WRITE at runtime -- a theme toggle's label, the live status
    // pill, a copy button's flash. They cannot come from the per-locale spans, because
    // assets/app.js replaces the node's text after load; handing them over here keeps
    // the runtime copy in the same table as everything else.
    'theme'         => \SatApi\Support\Season::clientPayload(),
    'ui'            => array(
        'theme.dark'  => I18n::translate($bootCode, 'theme.to_dark', 'Switch to dark theme'),
        'theme.light' => I18n::translate($bootCode, 'theme.to_light', 'Switch to light theme'),
        'status.ok'   => I18n::translate($bootCode, 'status.ok', 'operational'),
        'status.degraded' => I18n::translate($bootCode, 'status.degraded', 'degraded'),
        'status.critical' => I18n::translate($bootCode, 'status.critical', 'outage'),
        'status.unknown'  => I18n::translate($bootCode, 'status.unknown', 'status unknown'),
        'status.title'    => I18n::translate($bootCode, 'status.title', 'Upstream status'),
        'copy.copied'     => I18n::translate($bootCode, 'copy.copied', 'Copied'),
        'copy.failed'     => I18n::translate($bootCode, 'copy.failed', 'Press Ctrl+C'),
    ),
    // Captions the loading screen shows in phase 2. Passed through the boot payload
    // rather than read from the DOM because the component swaps them as each task
    // finishes, and the locale is already resolved here.
    'strings'       => array(
        'boot.preparing' => I18n::translate($bootCode, 'boot.preparing', 'Preparing your session'),
        'boot.verifying' => I18n::translate($bootCode, 'boot.verifying', 'Verifying…'),
        'boot.entering'  => I18n::translate($bootCode, 'boot.entering', 'Entering'),
        'boot.ready'     => I18n::translate($bootCode, 'boot.ready', 'Ready'),
    ),
    /* THE COOKIE STATE IS NOT IN THIS PAYLOAD, AND THAT IS THE FIX FOR A REAL BUG.
       ---------------------------------------------------------------------------
       This document is served `public, s-maxage=600` and sits behind two CDNs. It
       used to embed the visitor's own `cookie_consent` decision here:

           'cookies'          => $cookieStatus,                   // choice + decided
           'hasConsentCookie' => $cookieStatus['decided'],

       Measured 2026-10-28 through EdgeOne, same request, same cookie, two hits:

           api | cookie_consent=accept_all | try1 -> "hasConsentCookie":true   MISS
           api | cookie_consent=accept_all | try2 -> "hasConsentCookie":false  HIT

       and `reject_all` came back as false too. The edge had filled its entry from
       some other visitor and served that variant to this one. This is not cosmetic:
       a first-time visitor served a "decided" variant never sees the dialog at all,
       and a visitor who DID decide is asked again on every page -- the
       "授权状态记不住" report.

       Baking per-visitor state into a shared cache entry cannot be fixed by adding
       `Vary: Cookie`: that was already set, and it is what the edge tiers got wrong.
       The cacheable document must be BYTE-IDENTICAL for every visitor, and the
       decision must be read where it actually lives -- `document.cookie`, in
       assets/consent.js. Only the static, configuration-derived half of the cookie
       inventory stays here, because it is the same for everyone.

       `hasConsentCookie` is kept as an explicit null rather than deleted so a client
       holding a cached copy of an older consent.js cannot read a stale `true`. */
    'cookies'       => array(
        'current_version' => (int) $cookieStatus['current_version'],
        'categories'      => $cookieStatus['categories'],
        'lifetime_days'   => (int) $cookieStatus['lifetime_days'],
    ),
    // Tells any client that reads this payload that the visitor's choice lives in
    // the cookie, not in the document.
    'consentSource' => 'cookie',
    /* The announcement board's client half: cookie NAMES and the acknowledgement
       lifetime are configuration (identical for every visitor, safe to cache).
       Which announcements this visitor has read lives in the cookie and is read by
       assets/announce.js -- never here. */
    'announcements' => array(
        'enabled'        => $bootBoard !== array(),
        'readCookie'     => 'announcement_read',
        'declinedCookie' => 'announcement_declined',
        'days'           => (int) Config::get('announcements.cookie_days', 180),
    ),
    'endpoints'     => array(
        'consentStatus' => '/v1/consent/status',
        'verifyPow'     => '/v1/consent/verify-pow',
    ),
    // Null on purpose -- see the comment above the `cookies` key. The client derives
    // this from `document.cookie`; a server verdict here would reintroduce the
    // cached-foreign-visitor bug.
    'hasConsentCookie' => null,
), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<?php /* announce.js loads BEFORE consent.js on purpose: it must claim the screen
       first (it sets body.has-announce synchronously during DOMContentLoaded), and
       consent.js opens the cookie dialog only when that flag is not set. Otherwise
       a first-time visitor gets two modals on top of each other.
       It is only requested when this address renders a board: the API address has
       none (see the endpoint registry), and a script that can only return early is
       a request nobody needs. */ ?>
<?php if ($bootBoard !== array()): ?>
<script src="/assets/announce.js<?php echo \SatApi\HomePage::assetQueryFor('announce.js'); ?>" defer></script>
<?php endif; ?>
<?php /* The media decryptor: the film and the music are ciphertext, and both players
       need SatApiMedia before they run. Loaded here rather than from each of them so a
       deployed page cannot end up with the video script but not the key. */ ?>
<script src="/assets/media.js<?php echo \SatApi\HomePage::assetQueryFor('media.js'); ?>" defer></script>
<script src="/assets/consent.js<?php echo \SatApi\HomePage::assetQueryFor('consent.js'); ?>" defer></script>
<script src="/assets/app.js<?php echo \SatApi\HomePage::assetQueryFor('app.js'); ?>" defer></script>
<?php echo \SatApi\Support\Season::scripts(); ?>
