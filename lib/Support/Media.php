<?php

namespace SatApi\Support;

/**
 * The encrypted media: where the ciphertext is, and which key and IV open it.
 *
 * The film and the music are stored as AES-256-CBC ciphertext next to the original name
 * (`default-1080.mp4.enc`). Nothing serves the plaintext: the browser fetches the
 * ciphertext, asks /media-key for the key (a request that only succeeds for a visitor
 * who has solved the challenge), and decrypts it with Web Crypto before handing the
 * bytes to the video or audio element.
 *
 * WHY THIS AND NOT HLS
 * --------------------
 * HLS + AES-128 is the industry shape, but it needs a segmented copy of every file
 * (about 74 MB to re-upload here) and hls.js on every browser that is not Safari. This
 * deployment already ships progressive MP4/AAC and its players are built around
 * `<video src>` / `<audio src>`, so encryption at rest plus a page-side decrypt keeps
 * the players working, keeps the ciphertext cacheable (it is useless without the key),
 * and needs no new dependency.
 *
 * WHAT IT IS NOT
 * --------------
 * Not DRM. The key reaches the browser by design, so a determined visitor can recover
 * the plaintext. What it stops is the thing that actually happens: a crawler or a
 * scraper following `/assets/film/*.mp4` and keeping the bytes, and a hotlink from
 * another site. The ciphertext is useless without a solved challenge.
 *
 * The manifest (key + IVs) lives in conf/media.php, which is git-ignored: it is a
 * deployment secret, not source.
 */
final class Media
{
    /** OpenSSL cipher name; the browser uses the matching Web Crypto algorithm. */
    const CIPHER = 'aes-256-cbc';

    /** @var array{key:string,files:array<string,array{iv:string,mime:string}>}|null */
    private static $manifest = null;

    /**
     * The parsed manifest. Missing or unreadable means "no media": every caller treats
     * that as absent, so a deployment that forgot the file loses the film and the music
     * rather than throwing on every page render.
     *
     * @return array{key:string,files:array<string,array{iv:string,mime:string}>}
     */
    public static function manifest()
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $path = dirname(__DIR__, 2) . '/conf/media.php';
        $loaded = is_file($path) ? require $path : array();
        if (!is_array($loaded)) {
            $loaded = array();
        }

        $files = array();
        foreach (isset($loaded['files']) && is_array($loaded['files']) ? $loaded['files'] : array() as $name => $row) {
            if (!is_array($row)) {
                continue;
            }
            $iv = isset($row['iv']) ? (string) $row['iv'] : '';
            if (!preg_match('/^[0-9a-f]{32}$/', $iv)) {
                continue;
            }
            $files[(string) $name] = array(
                'iv'   => $iv,
                'mime' => isset($row['mime']) ? (string) $row['mime'] : 'application/octet-stream',
            );
        }

        self::$manifest = array(
            'key'   => isset($loaded['key']) ? (string) $loaded['key'] : '',
            'files' => $files,
        );

        return self::$manifest;
    }

    /**
     * Which IV and content type belong to a file, or null when it is not in the
     * manifest (an unencrypted or undecided file must not be served as if it were).
     *
     * @return array{iv:string,mime:string}|null
     */
    public static function entry($name)
    {
        $files = self::manifest()['files'];
        $name = (string) $name;

        return isset($files[$name]) ? $files[$name] : null;
    }

    /** The 32-byte key as raw bytes, or '' when the deployment has no manifest. */
    public static function keyBytes()
    {
        $hex = self::manifest()['key'];
        if (!preg_match('/^[0-9a-f]{64}$/', $hex)) {
            return '';
        }

        return (string) hex2bin($hex);
    }

    /**
     * Absolute path of the ciphertext for a file under the web root, given its
     * plaintext-relative name ("assets/film/default-720.mp4").
     */
    public static function cipherPath($relative)
    {
        return dirname(__DIR__, 2) . '/' . ltrim((string) $relative, '/') . '.enc';
    }

    /**
     * Is the ciphertext for this file on disk AND described by the manifest? Both halves
     * are needed: a file without an IV cannot be decrypted, and an IV without a file is
     * a 404 the player would sit on.
     */
    public static function available($relative, $name)
    {
        return self::entry($name) !== null && is_file(self::cipherPath($relative));
    }
}
