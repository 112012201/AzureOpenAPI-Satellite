<?php

namespace SatApi\Support;

use SatApi\Config;
use SatApi\Http\Request;
use SatApi\Logger;

/**
 * Privacy policy + terms acknowledgement.
 *
 * The contract is stateless on purpose:
 *
 *     ?consent=privacy,terms&consent_version=1
 *     X-Consent: privacy,terms;version=1
 *
 * A signed token would be harder to forge, but it would also make every API
 * response uncacheable at the CDN (the token would have to differ per client) and
 * would force a round trip before the first useful request. The trade-off is that
 * the acknowledgement is a client *statement*, not proof. That is stated in the
 * documentation rather than implied, and the upgrade path (a signed token) is
 * noted so the decision can be revisited.
 *
 * Enforcement is off by default. Turning it on would break every existing
 * integration the moment a legal notice is published, which is not a decision
 * code should make on an operator's behalf: `consent.required` is the switch and
 * `SATAPI_CONSENT_REQUIRED=1` flips it without a deploy.
 */
final class Consent
{
    public const STATE_OK = 'ok';
    public const STATE_MISSING = 'missing';
    public const STATE_INVALID = 'invalid';
    public const STATE_STALE = 'stale';

    /**
     * Describe the acknowledgement carried by a request.
     *
     * Never throws and never writes: the Router decides what to do with the
     * verdict, which keeps the "read the request" and "answer the request" halves
     * separately testable.
     *
     * @return array{
     *   accepted:bool,
     *   state:string,
     *   provided:string[],
     *   missing:string[],
     *   version:int,
     *   client_version:int|null,
     *   error:string,
     *   required:bool
     * }
     */
    public static function describe(Request $request)
    {
        $required = (bool) Config::get('consent.required', false);
        $currentVersion = (int) Config::get('consent.version', 1);
        $items = array_values(array_map('strval', (array) Config::get('consent.items', array('privacy', 'terms'))));

        $raw = self::rawValue($request);

        if ($raw['error'] !== '') {
            return array(
                'accepted'       => false,
                'state'          => self::STATE_INVALID,
                'provided'       => array(),
                'missing'        => $items,
                'version'        => $currentVersion,
                'client_version' => null,
                'error'          => $raw['error'],
                'required'       => $required,
            );
        }

        $provided = self::parseItems($raw['items'], $items);
        $clientVersion = $raw['version'];

        if ($provided === array()) {
            return array(
                'accepted'       => false,
                'state'          => self::STATE_MISSING,
                'provided'       => array(),
                'missing'        => $items,
                'version'        => $currentVersion,
                'client_version' => $clientVersion,
                'error'          => '',
                'required'       => $required,
            );
        }

        // A version mismatch means the documents changed since the client agreed,
        // so the acknowledgement no longer covers what is published.
        if ($clientVersion !== null && $clientVersion !== $currentVersion) {
            return array(
                'accepted'       => false,
                'state'          => self::STATE_STALE,
                'provided'       => $provided,
                'missing'        => array_values(array_diff($items, $provided)),
                'version'        => $currentVersion,
                'client_version' => $clientVersion,
                'error'          => '',
                'required'       => $required,
            );
        }

        $missing = array_values(array_diff($items, $provided));

        return array(
            'accepted'       => $missing === array(),
            'state'          => $missing === array() ? self::STATE_OK : self::STATE_MISSING,
            'provided'       => $provided,
            'missing'        => $missing,
            'version'        => $currentVersion,
            'client_version' => $clientVersion,
            'error'          => '',
            'required'       => $required,
        );
    }

    /**
     * The verdict for the request currently being served.
     *
     * Held statically so every endpoint can attach `meta.consent` without each of
     * them re-parsing the request: the Router evaluates once, before dispatch, and
     * the renderer reads the same verdict. Null before the Router runs (CLI, tests
     * that call a handler directly), in which case no consent block is emitted.
     *
     * @var array|null
     */
    private static $current = null;

    public static function setCurrent(array $verdict)
    {
        self::$current = $verdict;
    }

    public static function current()
    {
        return self::$current;
    }

    public static function reset()
    {
        self::$current = null;
    }

    /**
     * The public `consent` object attached to responses when the client has not
     * acknowledged both documents.
     *
     * @param array $verdict from describe()
     * @return array<string,mixed>
     */
    public static function payload(array $verdict)
    {
        $docs = (array) Config::get('consent.docs', array());
        $urls = array();
        foreach ((array) Config::get('consent.items', array()) as $item) {
            $urls[(string) $item] = isset($docs[$item]) ? (string) $docs[$item] : '';
        }

        $payload = array(
            'required'  => (array) Config::get('consent.items', array()),
            'accepted'  => (bool) $verdict['accepted'],
            'state'     => (string) $verdict['state'],
            'version'   => (int) $verdict['version'],
            'urls'      => $urls,
            'hint'      => 'Include ?consent='
                . implode(',', (array) Config::get('consent.items', array()))
                . '&consent_version=' . (int) $verdict['version']
                . ' to acknowledge both documents.',
        );

        if (!empty($verdict['missing'])) {
            $payload['missing'] = array_values($verdict['missing']);
        }
        if (!empty($verdict['provided'])) {
            $payload['provided'] = array_values($verdict['provided']);
        }
        if ($verdict['client_version'] !== null && $verdict['client_version'] !== $verdict['version']) {
            $payload['client_version'] = (int) $verdict['client_version'];
        }
        if ($verdict['error'] !== '') {
            $payload['error'] = (string) $verdict['error'];
        }

        return $payload;
    }

    /**
     * Response header advertising the requirement. Absent when the client has
     * acknowledged, so a healthy integration carries no extra noise.
     */
    public static function headerValue(array $verdict)
    {
        if ($verdict['accepted']) {
            return '';
        }

        return implode(',', (array) Config::get('consent.items', array()))
            . ';version=' . (int) $verdict['version']
            . ';state=' . (string) $verdict['state'];
    }

    /**
     * Is this path exempt from the gate?
     *
     * The exemptions are what make the notice reachable: a client cannot be asked
     * to acknowledge a document it is forbidden to read, and an operator must
     * still be able to see whether the service is alive.
     */
    public static function isExemptPath($path)
    {
        // Compare without a trailing slash so "/v1/health/" behaves like "/v1/health".
        $normalised = rtrim((string) $path, '/');
        if ($normalised === '') {
            $normalised = '/';
        }
        foreach ((array) Config::get('consent.exempt_paths', array()) as $exempt) {
            $exempt = rtrim((string) $exempt, '/');
            if ($exempt !== '' && $normalised === $exempt) {
                return true;
            }
        }

        return false;
    }

    /**
     * The 451 payload for an unacknowledged request in enforcing mode.
     *
     * @return array<string,mixed>
     */
    public static function problemExtra(array $verdict)
    {
        return array('consent' => self::payload($verdict));
    }

    /**
     * Read the raw acknowledgement from either the query string or the header.
     *
     * @return array{items:string, version:int|null, error:string}
     */
    private static function rawValue(Request $request)
    {
        $max = max(16, (int) Config::get('consent.max_param_bytes', 512));

        $items = (string) $request->param('consent', '');
        $versionRaw = $request->param('consent_version', null);

        $header = trim((string) $request->header('x-consent', ''));
        if ($header !== '') {
            // X-Consent: privacy,terms;version=1
            $parts = explode(';', $header);
            $headerItems = trim((string) array_shift($parts));
            if ($headerItems !== '') {
                $items = $headerItems;
            }
            foreach ($parts as $part) {
                $part = trim($part);
                if (stripos($part, 'version=') === 0) {
                    $versionRaw = substr($part, strlen('version='));
                }
            }
        }

        if (strlen($items) > $max) {
            return array('items' => '', 'version' => null, 'error' => 'consent parameter exceeds ' . $max . ' bytes');
        }

        // Anything outside the allowed alphabet is rejected rather than sanitised:
        // the value ends up in a log line and in the public consent object.
        if ($items !== '' && !preg_match('/^[A-Za-z0-9_,. -]*$/', $items)) {
            return array('items' => '', 'version' => null, 'error' => 'consent parameter contains unsupported characters');
        }

        $version = null;
        if ($versionRaw !== null && $versionRaw !== '') {
            if (!preg_match('/^[0-9]{1,6}$/', (string) $versionRaw)) {
                return array('items' => $items, 'version' => null, 'error' => 'consent_version must be a positive integer');
            }
            $version = (int) $versionRaw;
        }

        return array('items' => $items, 'version' => $version, 'error' => '');
    }

    /**
     * Normalise the comma separated list, keeping only recognised items and
     * recording anything unrecognised so the client can be told.
     *
     * @param string   $raw
     * @param string[] $known
     * @return string[]
     */
    private static function parseItems($raw, array $known)
    {
        $out = array();
        $unknown = array();
        foreach (explode(',', (string) $raw) as $piece) {
            $piece = strtolower(trim($piece));
            if ($piece === '') {
                continue;
            }
            // Accept the common spellings of the same document.
            $aliases = array(
                'privacy-policy' => 'privacy',
                'privacy_policy' => 'privacy',
                'privacypolicy'  => 'privacy',
                'tos'            => 'terms',
                'terms-of-service' => 'terms',
                'terms_of_service' => 'terms',
                'termsofservice' => 'terms',
                'service'        => 'terms',
            );
            if (isset($aliases[$piece])) {
                $piece = $aliases[$piece];
            }
            if (in_array($piece, $known, true)) {
                $out[$piece] = true;
            } else {
                $unknown[$piece] = true;
            }
        }

        if ($unknown !== array()) {
            Logger::info('consent_unknown_items', array('items' => array_keys($unknown)));
        }

        return array_keys($out);
    }
}
