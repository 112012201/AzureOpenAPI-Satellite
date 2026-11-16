<?php
/**
 * Bing Webmaster Tools API: URL submission and sitemap submission.
 *
 * WHY THIS EXISTS IN ADDITION TO IndexNow
 *
 * IndexNow and this API are two different signals to the same engine. IndexNow is
 * keyless and answers "accepted / not accepted" with no detail; this API is bound to
 * a verified property and answers with a per-request result, a live quota, and the
 * list of sites the key may actually touch. When both are available, sending both is
 * the honest thing to do -- one is cheap, the other is accountable.
 *
 * What it does NOT reach: Google. Bing's API speaks for Bing. Google has no
 * equivalent for ordinary pages (its Indexing API is JobPosting/BroadcastEvent only),
 * so Google's lane is the sitemap, robots.txt and time.
 *
 * THE KEY
 *
 * `conf/bing.php` (mode 0600, not in git). This module NEVER prints it: the key
 * travels only in the request URL, `mask()` is what any diagnostic shows, and a
 * failure is reported by method name rather than by dumping the request.
 *
 * THE SITE
 *
 * Submissions are made against a property the key has verified -- here
 * `https://authskin.xyz/`, the apex. A URL is only worth submitting if it lives on
 * that property; whether the apex property covers its subdomains is Bing's decision,
 * and the API answers it with an error rather than a guess (measured 2026-11-14:
 * https://satellite.authskin.xyz/docs is accepted under the apex site).
 */

namespace SatApi\Support;

use SatApi\Config;
use SatApi\Http\Client;

final class BingWebmaster
{
    /** The JSON service root. Every method is a sibling under it. */
    const BASE = 'https://ssl.bing.com/webmaster/api.svc/json/';

    /**
     * The credential, or '' when this deployment has none.
     *
     * @return string
     */
    public static function key()
    {
        $file = Config::path('conf/bing.php');
        if (is_file($file)) {
            $data = require $file;
            $key = is_array($data) && isset($data['key']) ? trim((string) $data['key']) : '';
            if ($key !== '') {
                return $key;
            }
        }

        return trim((string) Config::get('seo.bing_api_key', ''));
    }

    /**
     * The verified property submissions are made against, normalised to a trailing
     * slash because Bing keys its sites by that exact string.
     *
     * @return string
     */
    public static function siteUrl()
    {
        $file = Config::path('conf/bing.php');
        $site = '';
        if (is_file($file)) {
            $data = require $file;
            $site = is_array($data) && isset($data['site']) ? trim((string) $data['site']) : '';
        }
        if ($site === '') {
            $site = trim((string) Config::get('seo.bing_site', ''));
        }
        if ($site === '') {
            // The apex, not Seo::site(): the property that is actually verified is
            // what the API accepts, and the two are different hosts here.
            $site = 'https://authskin.xyz';
        }

        return rtrim($site, '/') . '/';
    }

    /**
     * What a diagnostic may show of a key. Never the key itself.
     *
     * @param string $key
     * @return string
     */
    public static function mask($key = null)
    {
        $key = $key === null ? self::key() : (string) $key;
        if ($key === '') {
            return '(none)';
        }

        return substr($key, 0, 4) . str_repeat('*', max(0, strlen($key) - 8)) . substr($key, -4);
    }

    /**
     * One API call.
     *
     * The key is appended as a query parameter (the API's only authentication), which
     * is exactly why the error path reports the METHOD and never the request.
     *
     * @param string     $method  e.g. 'GetUserSites'
     * @param array      $query   extra query parameters
     * @param array|null $body    JSON body for a POST
     * @return array{ok:bool,status:int,data:mixed,error:string}
     */
    public static function call($method, array $query = array(), $body = null)
    {
        $key = self::key();
        if ($key === '') {
            return array('ok' => false, 'status' => 0, 'data' => null, 'error' => 'no Bing API key configured');
        }

        $query['apikey'] = $key;
        $url = self::BASE . $method . '?' . http_build_query($query);

        $response = $body === null
            ? Client::get($url, array('timeout' => 25, 'retries' => 1))
            : Client::post($url, json_encode($body, JSON_UNESCAPED_SLASHES), array('timeout' => 25, 'retries' => 1));

        $status = (int) $response['status'];
        $decoded = json_decode((string) $response['body'], true);
        $ok = $status === 200 && is_array($decoded);

        return array(
            'ok'     => $ok,
            'status' => $status,
            'data'   => $ok ? (isset($decoded['d']) ? $decoded['d'] : null) : null,
            'error'  => $ok ? '' : self::explain($status, $response['body'], $method),
        );
    }

    /**
     * Turn the API's error envelope into a sentence, without echoing the request.
     *
     * @param int    $status
     * @param string $body
     * @param string $method
     * @return string
     */
    private static function explain($status, $body, $method)
    {
        $decoded = json_decode((string) $body, true);
        if (is_array($decoded) && isset($decoded['Message'])) {
            $code = isset($decoded['ErrorCode']) ? (int) $decoded['ErrorCode'] : 0;

            return $method . ': HTTP ' . (int) $status . ' (code ' . $code . ') ' . trim((string) $decoded['Message']);
        }

        return $method . ': HTTP ' . (int) $status . ' with a non-JSON body';
    }

    /**
     * The sites this key may act on. The first thing to check when a submission is
     * refused: the URL has to live on one of these.
     *
     * @return array<int,array{url:string,verified:bool}>
     */
    public static function sites()
    {
        $result = self::call('GetUserSites');
        if (!$result['ok'] || !is_array($result['data'])) {
            return array();
        }
        $out = array();
        foreach ($result['data'] as $site) {
            $out[] = array(
                'url'      => isset($site['Url']) ? (string) $site['Url'] : '',
                'verified' => !empty($site['IsVerified']),
            );
        }

        return $out;
    }

    /**
     * Remaining URL-submission quota for a property.
     *
     * @return array{daily:int,monthly:int}|null
     */
    public static function quota($siteUrl = null)
    {
        $result = self::call('GetUrlSubmissionQuota', array('siteUrl' => $siteUrl === null ? self::siteUrl() : $siteUrl));
        if (!$result['ok'] || !is_array($result['data'])) {
            return null;
        }

        return array(
            'daily'   => isset($result['data']['DailyQuota']) ? (int) $result['data']['DailyQuota'] : 0,
            'monthly' => isset($result['data']['MonthlyQuota']) ? (int) $result['data']['MonthlyQuota'] : 0,
        );
    }

    /**
     * Submit up to 10,000 URLs in one call.
     *
     * @param array<int,string> $urls
     * @return array{ok:bool,status:int,error:string}
     */
    public static function submitUrls(array $urls, $siteUrl = null)
    {
        $result = self::call(
            'SubmitUrlbatch',
            array(),
            array(
                'siteUrl' => $siteUrl === null ? self::siteUrl() : $siteUrl,
                'urlList' => array_values($urls),
            )
        );

        return array('ok' => $result['ok'], 'status' => $result['status'], 'error' => $result['error']);
    }

    /**
     * Submit (or refresh) a sitemap for a property.
     *
     * @return array{ok:bool,status:int,error:string}
     */
    public static function submitSitemap($feedUrl = null, $siteUrl = null)
    {
        $result = self::call(
            'SubmitFeed',
            array(),
            array(
                'siteUrl' => $siteUrl === null ? self::siteUrl() : $siteUrl,
                'feedUrl' => $feedUrl === null ? Seo::site() . '/sitemap.xml' : (string) $feedUrl,
            )
        );

        return array('ok' => $result['ok'], 'status' => $result['status'], 'error' => $result['error']);
    }

    /**
     * The sitemaps Bing already knows about for a property. Read-only, and the only
     * way to confirm that a SubmitFeed actually landed.
     *
     * @return array<int,array{url:string,status:string}>
     */
    public static function feeds($siteUrl = null)
    {
        $result = self::call('GetFeeds', array('siteUrl' => $siteUrl === null ? self::siteUrl() : $siteUrl));
        if (!$result['ok'] || !is_array($result['data'])) {
            return array();
        }
        $out = array();
        foreach ($result['data'] as $feed) {
            $out[] = array(
                'url'    => isset($feed['Url']) ? (string) $feed['Url'] : '',
                'status' => isset($feed['Status']) ? (string) $feed['Status'] : '',
            );
        }

        return $out;
    }
}
