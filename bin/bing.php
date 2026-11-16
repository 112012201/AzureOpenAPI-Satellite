<?php
/**
 * Bing Webmaster Tools: URL and sitemap submission.
 *
 *   php bin/bing.php --status          who the key is, what it can touch, quota left
 *   php bin/bing.php --dry-run         print what would be sent, send nothing
 *   php bin/bing.php --submit          SubmitUrlbatch with every URL in sitemap.xml
 *   php bin/bing.php --sitemap         SubmitFeed, then read the feed list back
 *   php bin/bing.php --set-key         read a NEW key from stdin and store it (0600)
 *
 * The key is never printed. `--set-key` exists so a rotation does not have to travel
 * through a chat message again: pipe it in, or edit conf/bing.php on the server.
 *
 * This reaches Bing only. Google has no equivalent API for ordinary pages, so its
 * lane is sitemap.xml + robots.txt and time -- nothing here pretends otherwise.
 */

use SatApi\Config;
use SatApi\Support\BingWebmaster;
use SatApi\Support\IndexNow;
use SatApi\Support\Seo;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/../bootstrap.php';

$mode = 'status';
foreach (array_slice($argv, 1) as $arg) {
    if (in_array($arg, array('--status', '--submit', '--sitemap', '--dry-run', '--set-key'), true)) {
        $mode = substr($arg, 2);
    } else {
        fwrite(STDERR, "unknown option: $arg\n");
        exit(2);
    }
}

/* --------------------------------------------------------------- --set-key */

if ($mode === 'set-key') {
    $key = trim((string) stream_get_contents(STDIN));
    if ($key === '' || !preg_match('/^[A-Za-z0-9]{16,64}$/', $key)) {
        fwrite(STDERR, "expected a 16-64 character alphanumeric key on stdin\n");
        exit(1);
    }
    $site = BingWebmaster::siteUrl();
    $conf = Config::path('conf/bing.php');
    $body = "<?php\n"
        . "/**\n"
        . " * Bing Webmaster API credential. Written by `php bin/bing.php --set-key`.\n"
        . " *\n"
        . " * 0600 and not in git: this one IS a secret (unlike the IndexNow key, which the\n"
        . " * protocol requires to be published). Rotating it means running --set-key again\n"
        . " * or editing this file on the server -- never pasting it into a chat.\n"
        . " */\n\n"
        . "return array(\n"
        . "    'key'  => '" . $key . "',\n"
        . "    'site' => '" . $site . "',\n"
        . ");\n";
    if (@file_put_contents($conf, $body) === false) {
        fwrite(STDERR, "cannot write $conf\n");
        exit(1);
    }
    @chmod($conf, 0600);
    echo "stored for site " . $site . " (key " . BingWebmaster::mask($key) . ")\n";
    exit(0);
}

/* ----------------------------------------------------------------- status */

echo "key         : " . BingWebmaster::mask() . "\n";
echo "site        : " . BingWebmaster::siteUrl() . "  (the verified property submissions go to)\n";
echo "canonical   : " . Seo::site() . "  (where the pages actually live)\n";

$sites = BingWebmaster::sites();
if ($sites) {
    echo "sites       : " . count($sites) . " under this key\n";
    foreach ($sites as $site) {
        echo "  - " . $site['url'] . ($site['verified'] ? '  verified' : '  NOT verified') . "\n";
    }
} else {
    echo "sites       : could not be read (check the key)\n";
}

$quota = BingWebmaster::quota();
if ($quota !== null) {
    echo "quota       : " . $quota['daily'] . " URLs/day, " . $quota['monthly'] . " URLs/month remaining\n";
}

$feeds = BingWebmaster::feeds();
echo "feeds       : " . count($feeds) . " sitemap(s) known to Bing\n";
foreach ($feeds as $feed) {
    echo "  - " . $feed['url'] . '  [' . ($feed['status'] === '' ? 'no status' : $feed['status']) . "]\n";
}

$urls = IndexNow::urls();
echo "sitemap     : " . count($urls) . " URL(s) in sitemap.xml\n";
foreach ($urls as $url) {
    echo "  - $url\n";
}

if ($mode === 'status') {
    exit(0);
}

/* ---------------------------------------------------------------- dry-run */

if ($mode === 'dry-run') {
    echo "\nSubmitUrlbatch body for " . BingWebmaster::siteUrl() . ":\n";
    echo json_encode(array('siteUrl' => BingWebmaster::siteUrl(), 'urlList' => $urls), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    echo "\nSubmitFeed body:\n";
    echo json_encode(array('siteUrl' => BingWebmaster::siteUrl(), 'feedUrl' => Seo::site() . '/sitemap.xml'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

/* ----------------------------------------------------------------- submit */

if ($mode === 'submit') {
    if (!$urls) {
        fwrite(STDERR, "nothing to submit: sitemap.xml lists no URL\n");
        exit(1);
    }
    $result = BingWebmaster::submitUrls($urls);
    printf(
        "\nSubmitUrlbatch: HTTP %d %s\n",
        $result['status'],
        $result['ok'] ? 'accepted -- the URL(s) are queued for crawling' : 'REJECTED'
    );
    if (!$result['ok']) {
        echo "  " . $result['error'] . "\n";
        echo "  A 400 usually means the URL is not on a verified property (see --status);\n";
        echo "  a 401/403 means the key itself was refused.\n";
        exit(1);
    }
    echo "  This is a crawl queue, not an index: Bing decides what to crawl and when.\n";
    exit(0);
}

/* ---------------------------------------------------------------- sitemap */

$result = BingWebmaster::submitSitemap();
printf("\nSubmitFeed: HTTP %d %s\n", $result['status'], $result['ok'] ? 'accepted' : 'REJECTED');
if (!$result['ok']) {
    echo "  " . $result['error'] . "\n";
    exit(1);
}

// Read it back: the only proof that the submission landed is Bing listing it.
$feeds = BingWebmaster::feeds();
echo "feeds now   : " . count($feeds) . "\n";
foreach ($feeds as $feed) {
    echo "  - " . $feed['url'] . '  [' . ($feed['status'] === '' ? 'no status' : $feed['status']) . "]\n";
}
exit(0);
