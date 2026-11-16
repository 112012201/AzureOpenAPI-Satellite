<?php
/**
 * IndexNow: tell the participating engines that a URL changed.
 *
 * WHO THIS REACHES -- AND WHO IT DOES NOT
 *
 * IndexNow is a shared protocol: one submission to api.indexnow.org fans out to
 * Bing, Yandex, Seznam, Naver and the other participants. **Google is not one of
 * them, and has said so repeatedly.** Google's own Indexing API is restricted to
 * JobPosting and BroadcastEvent structured data; using it for ordinary pages
 * violates its terms and does not make Google index anything faster. So there is
 * exactly one honest channel to Google: the sitemap, `robots.txt`, and a verified
 * Search Console property. Nothing in this class pretends otherwise.
 *
 * WHAT "ACCEPTED" MEANS
 *
 * A 200 or 202 means the submission was accepted for crawling. It is NOT an
 * assertion that the URL is indexed, ranked, or even visited -- that is the
 * engine's decision and it is not reported back. Anyone reading "submitted" as
 * "indexed" is reading it wrong, so the CLI prints the status and what it means
 * rather than a success banner.
 *
 * THE KEY
 *
 * The key is not a secret. The protocol requires it to be published at
 * `https://<host>/<key>.txt`, where its content must equal the key itself -- a
 * verification file that anyone can read. It is generated per deployment
 * (`bin/indexnow.php --init`) and kept in conf/indexnow.php only so the key file
 * and the submissions cannot disagree.
 */

namespace SatApi\Support;

use SatApi\Config;
use SatApi\Http\Client;

final class IndexNow
{
    /**
     * Where a submission goes.
     *
     * The shared endpoint is the protocol's own front door and fans out to every
     * participant. Bing's direct endpoint is submitted as well: it is the one engine
     * with a public dashboard, it sometimes answers before the shared endpoint does,
     * and the cost of the second request is one HTTP round trip.
     */
    const ENDPOINTS = array(
        'indexnow' => 'https://api.indexnow.org/indexnow',
        'bing'     => 'https://www.bing.com/indexnow',
    );

    /**
     * The verification key, or '' when this deployment has not generated one.
     *
     * IndexNow accepts 8-128 characters of [a-zA-Z0-9-]; a 32-character lowercase hex
     * string satisfies every participant and needs no escaping in a URL or a
     * filename.
     *
     * @return string
     */
    public static function key()
    {
        $file = Config::path('conf/indexnow.php');
        if (!is_file($file)) {
            return '';
        }
        $data = require $file;
        $key = is_array($data) && isset($data['key']) ? (string) $data['key'] : '';
        if ($key === '' || !preg_match('/^[A-Za-z0-9-]{8,128}$/', $key)) {
            return '';
        }

        return $key;
    }

    /**
     * Absolute path of the verification file in the web root.
     *
     * @return string
     */
    public static function keyFile()
    {
        $key = self::key();

        return $key === '' ? '' : Config::path($key . '.txt');
    }

    /**
     * The URL the engines fetch to verify us: it must answer 200 with exactly the key.
     *
     * @return string
     */
    public static function keyLocation()
    {
        $key = self::key();

        return $key === '' ? '' : Seo::site() . '/' . $key . '.txt';
    }

    /**
     * The bare hostname, which is what the payload wants -- not the origin URL.
     *
     * @return string
     */
    public static function host()
    {
        $host = parse_url(Seo::site(), PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    /**
     * The URLs to submit: the sitemap's, and nothing else.
     *
     * Deriving them from sitemap.xml rather than listing them again is the whole
     * point: a second list would drift, and an IndexNow submission of a URL the site
     * does not serve is a 422 at best. tests/run.php asserts the sitemap itself only
     * holds canonical, served addresses.
     *
     * @return array<int,string>
     */
    public static function urls()
    {
        $file = Config::path('sitemap.xml');
        if (!is_file($file)) {
            return array();
        }
        $xml = @simplexml_load_string((string) file_get_contents($file));
        if ($xml === false || !isset($xml->url)) {
            return array();
        }
        $site = Seo::site() . '/';
        $urls = array();
        foreach ($xml->url as $node) {
            $loc = trim((string) $node->loc);
            if ($loc !== '' && strpos($loc, $site) === 0) {
                $urls[] = $loc;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * The JSON body, per the protocol.
     *
     * @param array<int,string> $urls
     * @return array<string,mixed>
     */
    public static function payload(array $urls)
    {
        return array(
            'host'        => self::host(),
            'key'         => self::key(),
            'keyLocation' => self::keyLocation(),
            'urlList'     => array_values($urls),
        );
    }

    /**
     * Submit a set of URLs to every endpoint.
     *
     * @param array<int,string>|null $urls defaults to the sitemap's
     * @return array<string,array{ok:bool,status:int,verdict:string,body:string,error:string}>
     */
    public static function submit($urls = null)
    {
        $urls = $urls === null ? self::urls() : array_values($urls);
        $results = array();

        $json = json_encode(self::payload($urls), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            foreach (self::ENDPOINTS as $name => $endpoint) {
                $results[$name] = array(
                    'ok' => false, 'status' => 0, 'verdict' => 'payload could not be encoded',
                    'body' => '', 'error' => 'json_encode failed',
                );
            }

            return $results;
        }

        foreach (self::ENDPOINTS as $name => $endpoint) {
            // One attempt: a rejected submission is a configuration answer, not a
            // transient fault, and the client already declines to retry 4xx.
            $response = Client::post($endpoint, $json, array('timeout' => 20, 'retries' => 1));
            $results[$name] = array(
                'ok'      => (bool) $response['ok'],
                'status'  => (int) $response['status'],
                'verdict' => self::verdict((int) $response['status'], (bool) $response['ok']),
                'body'    => substr((string) $response['body'], 0, 400),
                'error'   => (string) $response['error'],
            );
        }

        return $results;
    }

    /**
     * What a status code means, in one line. The protocol defines exactly these five.
     *
     * @param int  $status
     * @param bool $ok
     * @return string
     */
    public static function verdict($status, $ok = false)
    {
        switch ((int) $status) {
            case 200:
                return 'accepted: the URL list was received and validated';
            case 202:
                return 'accepted (202): the key is being validated, the list was queued';
            case 400:
                return 'rejected: malformed request (bad JSON or missing field)';
            case 403:
                return 'rejected: the key file is not valid at keyLocation';
            case 422:
                return 'rejected: a URL does not belong to this host, or the key does not match';
            case 429:
                return 'rejected: too many requests (quota), retry later';
            case 0:
                return 'no response: network or TLS failure';
            default:
                return $ok ? 'accepted (status ' . (int) $status . ')' : 'rejected: HTTP ' . (int) $status;
        }
    }
}
