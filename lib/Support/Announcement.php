<?php

namespace SatApi\Support;

use SatApi\Config;
use SatApi\Http\Request;
use SatApi\Logger;

/**
 * The site notice banner.
 *
 * Rendered server-side rather than fetched, so it is visible without JavaScript,
 * announced by screen readers as part of the document, and impossible to miss on a
 * slow connection. Only the dismissal is client-side, because only the browser
 * knows whether this visitor already closed it.
 *
 * Expiry is evaluated HERE, on the server. A client with a wrong clock, or a
 * hostile one, must not be able to keep an expired notice alive.
 */
final class Announcement
{
    /** Notice levels that are allowed to reach the markup. */
    const LEVELS = array('info', 'warning');

    /**
     * The notice to show, or null when there is nothing to show.
     *
     * @return array{id:string,text:string,href:string,href_text:string,level:string,expires_at:string}|null
     */
    public static function current(?Request $request = null)
    {
        if (!(bool) Config::get('announcement.enabled', false)) {
            return null;
        }

        $text = trim((string) Config::get('announcement.text', ''));
        if ($text === '') {
            // Enabled with no copy is a misconfiguration, not an empty banner:
            // rendering an empty bar would look broken, so it is simply omitted.
            return null;
        }

        $expiresAt = trim((string) Config::get('announcement.expires_at', ''));
        if ($expiresAt !== '') {
            $until = strtotime($expiresAt);
            if ($until === false) {
                Logger::warning('announcement_bad_expiry', array('value' => $expiresAt));
            } elseif ($until <= time()) {
                return null;
            }
        }

        $id = trim((string) Config::get('announcement.id', ''));
        if ($id === '') {
            // Without an id every notice would share one dismissal cookie, so a new
            // notice could never be shown to somebody who dismissed an old one.
            // Derive a stable id from the content instead of failing.
            $id = substr(hash('sha256', $text . '|' . $expiresAt), 0, 12);
        }

        $level = strtolower(trim((string) Config::get('announcement.level', 'info')));
        if (!in_array($level, self::LEVELS, true)) {
            $level = 'info';
        }

        return array(
            'id'         => (string) $id,
            'text'       => $text,
            'href'       => trim((string) Config::get('announcement.href', '')),
            'href_text'  => trim((string) Config::get('announcement.href_text', '')),
            'level'      => $level,
            'expires_at' => $expiresAt,
        );
    }

    /**
     * Seconds until the notice expires, or 0 when it does not.
     */
    public static function secondsRemaining(array $announcement)
    {
        if ($announcement['expires_at'] === '') {
            return 0;
        }
        $until = strtotime($announcement['expires_at']);

        return $until === false ? 0 : max(0, $until - time());
    }

    /**
     * Everything the boot script needs about the notice.
     *
     * @return array<string,mixed>
     */
    public static function clientConfig(?array $announcement = null)
    {
        if ($announcement === null) {
            return array('enabled' => false);
        }

        return array(
            'enabled'  => true,
            'id'       => $announcement['id'],
            'level'    => $announcement['level'],
            'ttl'      => self::secondsRemaining($announcement),
            'cookie'   => 'announcement_dismissed',
        );
    }
}
