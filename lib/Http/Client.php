<?php

namespace SatApi\Http;

use SatApi\Config;
use SatApi\Logger;

/**
 * Upstream HTTP client.
 *
 * Compared with the previous hand-rolled cURL calls it adds:
 *   - TLS verification that is ON by default (the old code disabled it),
 *   - bounded response size, protocol whitelist and redirect cap,
 *   - transient-failure retries with backoff,
 *   - real timings for the /v1/health + /v1/meta diagnostics,
 *   - a parallel (curl_multi) mode used by the merged TLE endpoint.
 */
final class Client
{
    /**
     * @param string $url
     * @param array  $opts
     * @return array{ok:bool,status:int,body:string,error:string,errno:int,ms:int,bytes:int}
     */
    public static function get($url, array $opts = array())
    {
        if (!function_exists('curl_init')) {
            return self::curlMissing();
        }

        $opts = self::normalizeOptions($opts);
        $attempts = $opts['retries'] + 1;
        $result = self::emptyResult();

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $result = self::execute($url, $opts);
            if ($result['ok']) {
                return $result;
            }
            // Do not retry on client errors (4xx) - they will not heal.
            if ($result['status'] >= 400 && $result['status'] < 500) {
                return $result;
            }
            if ($attempt < $attempts) {
                usleep($opts['retry_delay'] * 1000 * $attempt);
                Logger::debug('upstream_retry', array('url' => $url, 'attempt' => $attempt, 'error' => $result['error']));
            }
        }

        return $result;
    }

    /**
     * Fetch several URLs in parallel.
     *
     * @param array<string,string> $urls key => url
     * @param array                $opts
     * @return array<string,array>
     */
    public static function getMany(array $urls, array $opts = array())
    {
        if (!function_exists('curl_multi_init')) {
            $out = array();
            foreach ($urls as $key => $url) {
                $out[$key] = self::curlMissing() + array('url' => $url);
            }

            return $out;
        }

        $opts = self::normalizeOptions($opts);
        $multi = curl_multi_init();
        $handles = array();
        $buffers = array();
        $results = array();

        foreach ($urls as $key => $url) {
            $ch = self::prepare($url, $opts);
            $buffers[$key] = '';
            $maxBytes = $opts['max_bytes'];
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($handle, $chunk) use (&$buffers, $key, $maxBytes) {
                if (strlen($buffers[$key]) + strlen($chunk) > $maxBytes) {
                    return 0; // abort over-sized transfer
                }
                $buffers[$key] .= $chunk;

                return strlen($chunk);
            });
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = array('handle' => $ch, 'url' => $url);
        }

        $running = 0;
        $startedAt = microtime(true);
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                // 1s cap keeps a hung upstream from blocking the whole request.
                if (curl_multi_select($multi, 1.0) === -1) {
                    usleep(1000);
                }
            }
            if ($status !== CURLM_OK) {
                break;
            }
        } while ($running > 0);

        foreach ($handles as $key => $meta) {
            $ch = $meta['handle'];
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $ms = (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
            $body = $buffers[$key];
            $ok = ($errno === 0 && $status === 200 && $body !== '');

            $results[$key] = array(
                'ok'     => $ok,
                'status' => $status,
                'body'   => $ok ? $body : '',
                'error'  => $ok ? '' : ($error !== '' ? $error : 'HTTP ' . $status),
                'errno'  => $errno,
                'ms'     => $ms,
                'bytes'  => strlen($body),
                'url'    => $meta['url'],
            );

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi);

        // One parallel retry round for transient failures.
        if ($opts['retries'] > 0) {
            $retry = array();
            foreach ($results as $key => $result) {
                if (!$result['ok'] && ($result['status'] === 0 || $result['status'] >= 500)) {
                    $retry[$key] = $urls[$key];
                }
            }
            if (!empty($retry)) {
                usleep($opts['retry_delay'] * 1000);
                $second = self::getManyOnce($retry, $opts);
                foreach ($second as $key => $result) {
                    if ($result['ok']) {
                        $results[$key] = $result;
                    }
                }
            }
        }

        Logger::debug('upstream_batch_done', array(
            'total_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'ok'       => count(array_filter($results, static function ($r) {
                return $r['ok'];
            })),
        ));

        return $results;
    }

    /**
     * Single retry round without recursion into the retry logic.
     */
    private static function getManyOnce(array $urls, array $opts)
    {
        $saved = $opts['retries'];
        $opts['retries'] = 0;
        $multi = curl_multi_init();
        $handles = array();
        $buffers = array();

        foreach ($urls as $key => $url) {
            $ch = self::prepare($url, $opts);
            $buffers[$key] = '';
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($handle, $chunk) use (&$buffers, $key) {
                $buffers[$key] .= $chunk;

                return strlen($chunk);
            });
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }

        $running = 0;
        do {
            curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0);

        $results = array();
        foreach ($handles as $key => $ch) {
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $results[$key] = array(
                'ok'     => ($errno === 0 && $status === 200 && $buffers[$key] !== ''),
                'status' => $status,
                'body'   => ($errno === 0 && $status === 200) ? $buffers[$key] : '',
                'error'  => $error !== '' ? $error : 'HTTP ' . $status,
                'errno'  => $errno,
                'ms'     => (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000),
                'bytes'  => strlen($buffers[$key]),
                'url'    => $urls[$key],
            );
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        $opts['retries'] = $saved;

        return $results;
    }

    private static function execute($url, array $opts)
    {
        $ch = self::prepare($url, $opts);
        $body = '';
        $maxBytes = $opts['max_bytes'];
        $aborted = false;
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($handle, $chunk) use (&$body, $maxBytes, &$aborted) {
            if (strlen($body) + strlen($chunk) > $maxBytes) {
                $aborted = true;

                return 0;
            }
            $body .= $chunk;

            return strlen($chunk);
        });

        $started = microtime(true);
        $ok = curl_exec($ch);
        $ms = (int) round((microtime(true) - $started) * 1000);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($aborted) {
            $error = 'response exceeded ' . $maxBytes . ' bytes';
            $errno = 0;
            $status = 0;
        }

        $success = ($ok !== false && $errno === 0 && $status === 200 && $body !== '');

        return array(
            'ok'     => $success,
            'status' => $status,
            'body'   => $success ? $body : '',
            'error'  => $success ? '' : ($error !== '' ? $error : 'HTTP ' . $status),
            'errno'  => $errno,
            'ms'     => $ms,
            'bytes'  => strlen($body),
            'url'    => $url,
        );
    }

    private static function prepare($url, array $opts)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, $opts['timeout']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $opts['connect_timeout']);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, $opts['user_agent']);
        curl_setopt($ch, CURLOPT_ENCODING, ''); // accept and decode gzip/deflate/br
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $opts['verify_tls'] ? 1 : 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $opts['verify_tls'] ? 2 : 0);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        if ($opts['ca_bundle'] !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $opts['ca_bundle']);
        }

        $headers = array();
        if ($opts['accept'] !== '') {
            $headers[] = 'Accept: ' . $opts['accept'];
        }
        foreach ($opts['headers'] as $header) {
            $headers[] = $header;
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        return $ch;
    }

    private static function normalizeOptions(array $opts)
    {
        $defaults = array(
            'timeout'         => (int) Config::get('http.timeout', 20),
            'connect_timeout' => (int) Config::get('http.connect_timeout', 8),
            'user_agent'      => (string) Config::get('http.user_agent', 'AzureOpenAPI-for-Satellite'),
            'verify_tls'      => (bool) Config::get('http.verify_tls', true),
            'ca_bundle'       => (string) Config::get('http.ca_bundle', ''),
            'retries'         => (int) Config::get('http.retries', 2),
            'retry_delay'     => (int) Config::get('http.retry_delay', 250),
            'max_bytes'       => (int) Config::get('http.max_bytes', 33554432),
            'accept'          => '',
            'headers'         => array(),
        );

        return $opts + $defaults;
    }

    private static function emptyResult()
    {
        return array('ok' => false, 'status' => 0, 'body' => '', 'error' => 'not attempted', 'errno' => 0, 'ms' => 0, 'bytes' => 0, 'url' => '');
    }

    /**
     * Uniform failure when the cURL extension is not installed.
     *
     * @return array{ok:bool,status:int,body:string,error:string,errno:int,ms:int,bytes:int,url:string}
     */
    private static function curlMissing()
    {
        Logger::error('curl_extension_missing');

        return array(
            'ok'     => false,
            'status' => 0,
            'body'   => '',
            'error'  => 'the PHP cURL extension is not installed',
            'errno'  => 0,
            'ms'     => 0,
            'bytes'  => 0,
            'url'    => '',
        );
    }

    /**
     * Source definition -> request options.
     */
    public static function optionsForSource(array $source, array $overrides = array())
    {
        return $overrides + array(
            'accept' => isset($source['accept']) ? $source['accept'] : '',
        );
    }
}
