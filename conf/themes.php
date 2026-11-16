<?php

/**
 * Holiday themes: what a themed period looks like, and when it runs.
 *
 * A theme is a STYLESHEET plus an EFFECT plus (optionally) MUSIC plus a GREETING for
 * the loading screen. It changes no markup, no route, no copy and no behaviour: the
 * page is the same page in a different coat, and every theme has a way out that the
 * visitor can find (the badge in the bottom-right corner).
 *
 * WINDOWS ARE IN ASIA/SHANGHAI
 *
 *   `window` is [start, end] and the END IS EXCLUSIVE. "Through 2 January 23:59" is
 *   written as '2027-01-03 00:00' -- that is the same instant and it is impossible to
 *   lose the last minute to a comparison made with 23:59 instead of 23:59:59.
 *
 *   Windows may overlap in principle; the resolver takes the LAST match in this array,
 *   so a later entry wins. The three below do not overlap.
 *
 * MUSIC
 *
 *   `tracks` is the rotation list: the client picks a random one first, then cycles.
 *   `dates` are the windows that REPLACE that list -- used for a single-day set piece
 *   (25 December, 6 February, 9 February). Each entry is [start, end, tracks], again
 *   with an exclusive end.
 *
 *   Files live in assets/music/ and are AAC (.m4a) at 96 kbps: the source material was
 *   Opus (Safari cannot play it) and 25 MB of original audio is not something that
 *   belongs on a cold-start CDN. The mapping from the operator's library is recorded
 *   in assets/music/README.md.
 *
 * FILM
 *
 *   The video that fills the first screen on the landing page, below the header
 *   (the whitehouse.gov shape: nav, then a full-bleed clip, then the page). `sources`
 *   carries the two encodings -- `lg` for a desktop viewport, `sm` for a phone -- and
 *   the client picks one, so a phone never pays for 1080p. `poster` is the still that
 *   paints before a single video byte is read.
 *
 *   The files live in assets/film/ and are H.264, 30 fps, no audio track, 20 seconds,
 *   cut to loop. There is no audio because the theme's music already plays: a video
 *   with its own soundtrack would fight it. No track, no fight.
 *
 *   A missing file is not an error here either: Season::film() returns null for a
 *   theme whose files are absent, the page renders without the band, and the canvas
 *   effect keeps drawing. The site is never a black rectangle.
 *
 * GREETING
 *
 *   The loading screen's tagline becomes the greeting while the theme is on
 *   (`theme.greeting.<name>` in conf/i18n.php), which is why the overlay is translated
 *   with the rest of the site instead of inventing a second string table here.
 *
 * @return array<string,array<string,mixed>>
 */
return array(

    'default' => array(
        'window'  => null,
        'css'     => '',
        'effect'  => '',
        'greeting' => '',
        'music'   => array(),
        /*
         * The plain site gets a film too. It is aerial footage of a village on the
         * water -- Earth seen from above, which is what this service is about. It is
         * not a holiday and it does not announce itself: no cassette, no badge, no
         * words, just the shot and the tagline the loading screen already says.
         */
        'film'    => array(
            'poster'  => 'default-poster.jpg',
            'sources' => array('sm' => 'default-720.mp4', 'lg' => 'default-1080.mp4'),
        ),
    ),

    /*
     * Christmas: evergreen + night sky.
     *
     * 24 December 00:00 through 26 December 23:59. The 25th is its own set piece: for
     * that one day the rotation is replaced by "All I Want for Christmas Is You"; the
     * other two tracks cover the rest of the window.
     */
    'christmas' => array(
        'window'  => array('2026-12-24 00:00', '2026-12-27 00:00'),
        'css'     => 'christmas.css',
        'effect'  => 'christmas.js',
        'greeting' => 'theme.greeting.christmas',
        'film'    => array(
            'poster'  => 'christmas-poster.jpg',
            'sources' => array('sm' => 'christmas-720.mp4', 'lg' => 'christmas-1080.mp4'),
        ),
        'music'   => array(
            /*
             * BOTH tracks, restored. The second one (christmas-last-christmas.m4a) was
             * shipped as a 107-second fragment with no `moov` box -- an encode killed
             * before it finished -- and was removed rather than left to fail half the
             * time. The SOURCE was never lost: `Wham! - Last Christmas (Single
             * Version).opus` was in E:\Users\Administrator\Music\NcmForge all along,
             * and the "no longer on this machine" note that used to be here was wrong
             * because that search only looked at J:. It is re-encoded at 192 kbps with
             * the rest of the set, so the rotation is what the period was specced with.
             */
            'tracks' => array('christmas-last-christmas.m4a', 'christmas-jingle-bell-rock.m4a'),
            'dates'  => array(
                array('2026-12-25 00:00', '2026-12-26 00:00', array('christmas-all-i-want.m4a')),
            ),
        ),
    ),

    /*
     * New Year: the first theme built, and the reference for the other two -- fireworks
     * behind a red ground, except this one is the LIGHTER red (朱红) and the Spring
     * Festival one is the deeper 宫墙红. Two shades of the same idea, so the two periods
     * are distinguishable at a glance.
     *
     * 31 December 00:00 through 2 January 23:59. Two tracks, random first, then rotate.
     */
    'newyear' => array(
        'window'  => array('2026-12-31 00:00', '2027-01-03 00:00'),
        'css'     => 'newyear.css',
        'effect'  => 'newyear.js',
        'greeting' => 'theme.greeting.newyear',
        'film'    => array(
            'poster'  => 'newyear-poster.jpg',
            'sources' => array('sm' => 'newyear-720.mp4', 'lg' => 'newyear-1080.mp4'),
        ),
        'music'   => array(
            'tracks' => array('newyear-yuandan.m4a', 'newyear-joy-to-the-world.m4a'),
            'dates'  => array(),
        ),
    ),

    /*
     * Spring Festival: the New Year theme in the deeper red, with its own music.
     *
     * 4 February 00:00 through 9 February 23:59 (the window ends AT 10 February 00:00).
     * 「花好月圆」covers the window; 6 February is 「春节序曲」only, and 9 February is
     * 「难忘今宵」only. 7-8 February therefore fall back to 花好月圆, which is what
     * "除 2.6 外均为花好月圆" asks for.
     */
    'spring' => array(
        'window'  => array('2027-02-04 00:00', '2027-02-10 00:00'),
        'css'     => 'spring.css',
        'effect'  => 'newyear.js',
        'greeting' => 'theme.greeting.spring',
        'film'    => array(
            'poster'  => 'spring-poster.jpg',
            'sources' => array('sm' => 'spring-720.mp4', 'lg' => 'spring-1080.mp4'),
        ),
        'music'   => array(
            'tracks' => array('spring-hua-hao-yue-yuan.m4a'),
            'dates'  => array(
                array('2027-02-06 00:00', '2027-02-07 00:00', array('spring-chunjie-xuqu.m4a')),
                array('2027-02-09 00:00', '2027-02-10 00:00', array('spring-nanwang-jin-xiao.m4a')),
            ),
        ),
    ),
);
