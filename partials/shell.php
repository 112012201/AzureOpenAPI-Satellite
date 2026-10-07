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
use SatApi\Support\Consent;
use SatApi\Support\I18n;
use SatApi\Support\Pow;

$bootRequest = Request::capture();
$bootLocale = I18n::resolve($bootRequest);
$bootCode = $bootLocale['code'];
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

$announcement = Announcement::current($bootRequest);
$consentVerdict = Consent::describe($bootRequest);
Consent::setCurrent($consentVerdict);
$consentPayload = Consent::payload($consentVerdict);
$powStatus = Pow::status($bootRequest);
$cookieStatus = Pow::cookieStatus($bootRequest);

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
<div class="boot" data-boot data-pow-enabled="<?php echo $powStatus['enabled'] ? '1' : '0'; ?>" data-pow-endpoint="<?php echo htmlspecialchars((string) $powStatus['endpoint'], ENT_QUOTES, 'UTF-8'); ?>" data-pow-sitekey="<?php echo htmlspecialchars((string) $powStatus['site_key'], ENT_QUOTES, 'UTF-8'); ?>">
  <div class="boot__mark" aria-hidden="true">
    <span class="boot__blocks"><i></i><i></i><i></i><i></i></span>
  </div>
  <p class="boot__title"><?php echo $bootBlock('loading.title'); ?></p>
  <div class="boot__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-boot-bar><span data-boot-fill></span></div>
  <p class="boot__hint" data-boot-hint><?php echo $bootBlock('pow.working'); ?></p>
  <p class="boot__error" data-boot-error hidden>
    <span data-boot-fail hidden><?php echo $bootBlock('pow.unavailable'); ?></span>
    <?php echo $bootBlock('pow.failed'); ?>
    <button class="btn btn--sm btn--ghost" type="button" data-boot-retry><?php echo $bootBlock('pow.retry'); ?></button>
    <button class="btn btn--sm btn--ghost" type="button" data-boot-skip><?php echo $bootBlock('pow.skip'); ?></button>
  </p>
</div>
<script>
/* Boot facts for assets/consent.js. Kept inline and tiny so the document is
   self-describing: the page still renders if this script never runs, because the
   server already picked the locale and already rendered the announcement and the
   consent dialog markup. */
window.__SATAPI_BOOT__ = <?php echo json_encode(array(
    'locale'        => $bootCode,
    'localeSource'  => $bootLocale['source'],
    'locales'       => I18n::locales(),
    'cookieName'    => 'cookie_consent',
    'cookieVersion' => (int) Config::get('cookies.version', 1),
    'cookieDays'    => (int) Config::get('cookies.lifetime_days', 180),
    'langCookie'    => I18n::COOKIE_NAME,
    'consent'       => $consentPayload,
    'consentRequired' => (bool) Config::get('consent.required', false),
    'pow'           => $powStatus,
    'cookies'       => $cookieStatus,
    'endpoints'     => array(
        'consentStatus' => '/v1/consent/status',
        'verifyPow'     => '/v1/consent/verify-pow',
    ),
    'hasConsentCookie' => $cookieStatus['decided'],
), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="/assets/consent.js<?php echo \SatApi\HomePage::assetQueryFor('consent.js'); ?>" defer></script>
<script src="/assets/app.js<?php echo \SatApi\HomePage::assetQueryFor('app.js'); ?>" defer></script>
