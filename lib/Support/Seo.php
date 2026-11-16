<?php
/**
 * Search-engine metadata for the HTML documents: canonical, hreflang, Open Graph,
 * Twitter cards and JSON-LD.
 *
 * WHY THIS IS ONE CLASS
 *
 * The three documents (landing page, docs, legal) each used to write their own
 * canonical and their own Open Graph block. They drifted: the landing page and the
 * legal pages pointed <link rel="canonical"> at `service.homepage`, which is the
 * OPERATOR'S site (a different site entirely), and the docs page pointed at
 * /docs.html -- a URL that 301s back to /docs. A canonical that names a different
 * page is a request to de-index this one, so both were the most damaging tags on
 * the site and neither showed up in any test.
 *
 * The rule this class enforces: a page's canonical is ITS OWN absolute URL on the
 * host that serves it, and nothing else. `service.homepage` is a link target
 * ("the operator's site"), not a canonical base -- the two are different things
 * that happen to share a value in this deployment, which is exactly why the bug
 * was easy to miss.
 *
 * THE ADDRESS
 *
 * `seo.site` names the host these documents are canonically served from. Left
 * empty it falls back to the `origin` entry of the hostname registry, which is
 * the address with no CDN in front of it -- the one stable name the whole site
 * answers on. It is deliberately NOT the Host header: three hostnames serve this
 * site and a canonical that echoes the request would make each of them its own
 * original.
 */

namespace SatApi\Support;

use SatApi\Config;

final class Seo
{
    /**
     * The robots directive for a page that should be indexed.
     *
     * `max-image-preview:large` is what lets a search result show a large image
     * instead of a thumbnail; without it Google caps the preview at 120x120. The
     * other two lift the snippet limits, which matter here because the landing
     * page's description is written to be read in full.
     */
    const INDEX = 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';

    /**
     * A canonical host, without a trailing slash.
     *
     * @return string `https://satellite.authskin.xyz`, or '' when nothing is configured
     */
    public static function site()
    {
        $candidates = array(
            (string) Config::get('seo.site', ''),
            (string) Config::get('endpoints.registry.origin.url', ''),
            (string) Config::get('service.homepage', ''),
        );
        foreach ($candidates as $candidate) {
            $candidate = rtrim(trim($candidate), '/');
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Absolute URL of a page on the canonical host.
     *
     * The path is normalised rather than trusted: a caller passing 'docs' and one
     * passing '/docs' must produce the same string, or the sitemap and the page
     * would disagree about an address that is identical to a reader.
     *
     * @param string $path '/', '/docs', '/legal/privacy'
     * @param string $lang content locale to pin, '' for the negotiated default
     * @return string
     */
    public static function url($path = '/', $lang = '')
    {
        $path = '/' . ltrim((string) $path, '/');
        $url = self::site() . $path;
        if ((string) $lang !== '') {
            $url .= '?lang=' . rawurlencode((string) $lang);
        }

        return $url;
    }

    /**
     * The hreflang cluster for one page: one URL per language the site publishes,
     * plus x-default.
     *
     * The URLs are the ADDRESS variants the server actually honours (`?lang=`
     * selects a content locale -- see I18n::resolve), not translations of the page
     * under different paths. `en` is declared once: the site has one English text
     * and chooses en-US / en-GB as the html lang from the visitor's own subtag, so
     * declaring both would promise two pages that are the same page.
     *
     * @param string $path
     * @return array<string,string> locale => absolute URL
     */
    public static function languages($path = '/')
    {
        $out = array();
        foreach (I18n::locales() as $code) {
            $out[$code] = self::url($path, $code);
        }
        $out['x-default'] = self::url($path);

        return $out;
    }

    /**
     * The <head> lines for one page.
     *
     * `<title>` is NOT emitted here: every caller already writes one (and the docs
     * page writes it from a translated template), so a second title would be the
     * bug rather than the fix. The title is passed in only so og:title and
     * twitter:title can agree with it.
     *
     * @param array $o {
     *   path        string  canonical path on this host, default '/'
     *   title       string  the page title, for og/twitter
     *   description string  the meta description (also og/twitter)
     *   lang        string  content locale of this representation, default 'zh-Hans'
     *   type        string  og:type, default 'website'
     *   image       string  absolute URL of the preview image
     *   image_type  string  MIME type, default 'image/png'
     *   image_size  array   [width, height]
     *   index       bool    emit the index robots directive (false for noindex)
     *   lang_query  string  locale to pin in the canonical URL ('' when the page
     *                       was negotiated from the request rather than chosen by
     *                       an explicit ?lang= -- a canonical that invented a query
     *                       the visitor never used would be a different address)
     *   nodes       array   JSON-LD nodes to append to the document graph
     * }
     * @return array<int,string>
     */
    public static function head(array $o)
    {
        $path = isset($o['path']) ? (string) $o['path'] : '/';
        $title = isset($o['title']) ? (string) $o['title'] : (string) Config::get('service.name', '');
        $description = isset($o['description']) ? (string) $o['description'] : '';
        $lang = isset($o['lang']) ? (string) $o['lang'] : 'zh-Hans';
        $type = isset($o['type']) ? (string) $o['type'] : 'website';
        $index = isset($o['index']) ? (bool) $o['index'] : true;

        $image = isset($o['image']) && (string) $o['image'] !== ''
            ? (string) $o['image']
            : self::site() . '/icon-512.png';
        $imageType = isset($o['image_type']) ? (string) $o['image_type'] : 'image/png';
        $imageSize = isset($o['image_size']) && is_array($o['image_size'])
            ? array_values($o['image_size'])
            : array(512, 512);
        $wide = count($imageSize) === 2 && (int) $imageSize[0] >= 1200;

        $esc = static function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };

        $canonical = self::url($path, isset($o['lang_query']) ? (string) $o['lang_query'] : '');

        $lines = array();
        $lines[] = '<meta name="robots" content="' . $esc($index ? self::INDEX : 'noindex, follow') . '">';
        /*
         * Verification tokens for the two webmaster consoles. They are emitted only
         * when configured, and on every page, because the console verifies whichever
         * URL the operator typed in -- usually the root, sometimes /docs.
         */
        $google = trim((string) Config::get('seo.google_verification', ''));
        if ($google !== '') {
            $lines[] = '<meta name="google-site-verification" content="' . $esc($google) . '">';
        }
        $bing = trim((string) Config::get('seo.bing_verification', ''));
        if ($bing !== '') {
            $lines[] = '<meta name="msvalidate.01" content="' . $esc($bing) . '">';
        }
        if ($description !== '') {
            $lines[] = '<meta name="description" content="' . $esc($description) . '">';
        }
        $lines[] = '<link rel="canonical" href="' . $esc($canonical) . '">';
        // Self-referencing hreflang on every page, including the default one: a
        // cluster that omits its own members is ignored as incomplete.
        foreach (self::languages($path) as $code => $url) {
            $lines[] = '<link rel="alternate" hreflang="' . $esc($code) . '" href="' . $esc($url) . '">';
        }

        $name = (string) Config::get('service.name', 'AzureOpenAPI for Satellite');
        $lines[] = '<meta property="og:type" content="' . $esc($type) . '">';
        $lines[] = '<meta property="og:site_name" content="' . $esc($name) . '">';
        $lines[] = '<meta property="og:title" content="' . $esc($title) . '">';
        if ($description !== '') {
            $lines[] = '<meta property="og:description" content="' . $esc($description) . '">';
        }
        $lines[] = '<meta property="og:url" content="' . $esc($canonical) . '">';
        $lines[] = '<meta property="og:image" content="' . $esc($image) . '">';
        if (count($imageSize) === 2) {
            $lines[] = '<meta property="og:image:width" content="' . (int) $imageSize[0] . '">';
            $lines[] = '<meta property="og:image:height" content="' . (int) $imageSize[1] . '">';
        }
        if ($imageType !== '') {
            $lines[] = '<meta property="og:image:type" content="' . $esc($imageType) . '">';
        }
        $lines[] = '<meta property="og:image:alt" content="' . $esc($title) . '">';
        $ogLocale = self::ogLocale($lang);
        if ($ogLocale !== '') {
            $lines[] = '<meta property="og:locale" content="' . $esc($ogLocale) . '">';
            foreach (I18n::locales() as $code) {
                $other = self::ogLocale($code);
                if ($other !== '' && $other !== $ogLocale) {
                    $lines[] = '<meta property="og:locale:alternate" content="' . $esc($other) . '">';
                }
            }
        }

        $lines[] = '<meta name="twitter:card" content="' . $esc($wide ? 'summary_large_image' : 'summary') . '">';
        $lines[] = '<meta name="twitter:title" content="' . $esc($title) . '">';
        if ($description !== '') {
            $lines[] = '<meta name="twitter:description" content="' . $esc($description) . '">';
        }
        $lines[] = '<meta name="twitter:image" content="' . $esc($image) . '">';
        $lines[] = '<meta name="twitter:image:alt" content="' . $esc($title) . '">';

        $nodes = isset($o['nodes']) && is_array($o['nodes']) ? $o['nodes'] : array();
        if ($nodes) {
            $lines[] = self::jsonLd($nodes);
        }

        return $lines;
    }

    /**
     * The JSON-LD block. One @graph per document, so the pieces can reference each
     * other by @id instead of repeating the same object three times.
     *
     * @param array $nodes
     * @return string
     */
    public static function jsonLd(array $nodes)
    {
        /*
         * JSON_HEX_TAG is not cosmetic: this block is embedded in an HTML <script>,
         * and a description that ever contained "</script>" would end the element
         * early and spill the rest of the graph into the page as text. Escaping the
         * angle brackets costs nothing and decodes back to the same string.
         */
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG;
        $payload = array('@context' => 'https://schema.org', '@graph' => array_values($nodes));
        $json = json_encode($payload, $flags);
        if ($json === false) {
            return '';
        }

        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /**
     * The two nodes every page can point at: who publishes this, and what the site is.
     *
     * @param string $description
     * @return array<int,array<string,mixed>>
     */
    public static function siteNodes($description = '')
    {
        $site = self::site();
        $name = (string) Config::get('service.name', 'AzureOpenAPI for Satellite');
        $org = array(
            '@type' => 'Organization',
            '@id'   => $site . '/#organization',
            'name'  => $name,
            'url'   => $site . '/',
            'logo'  => array(
                '@type'  => 'ImageObject',
                'url'    => $site . '/icon-512.png',
                'width'  => 512,
                'height' => 512,
            ),
        );
        $email = trim((string) Config::get('service.contact_email', ''));
        if ($email !== '') {
            $org['email'] = $email;
        }
        // sameAs is only worth declaring when it is a real profile elsewhere; an
        // empty list is a claim that there is none, which is not ours to make.
        $sameAs = Config::get('seo.same_as', array());
        if (is_array($sameAs) && $sameAs) {
            $org['sameAs'] = array_values(array_map('strval', $sameAs));
        }

        $website = array(
            '@type'      => 'WebSite',
            '@id'        => $site . '/#website',
            'url'        => $site . '/',
            'name'       => $name,
            'description' => (string) $description,
            'publisher'  => array('@id' => $site . '/#organization'),
            'inLanguage' => array_values(I18n::locales()),
        );

        return array($org, $website);
    }

    /**
     * The service itself, as a machine-readable application: this is an API, and
     * what a search result can say about it is "developer tool, free, documented".
     *
     * @param string $description
     * @return array<string,mixed>
     */
    public static function application($description = '')
    {
        $site = self::site();
        $name = (string) Config::get('service.name', 'AzureOpenAPI for Satellite');

        return array(
            '@type'               => 'SoftwareApplication',
            '@id'                 => $site . '/#application',
            'name'                => $name,
            'applicationCategory' => 'DeveloperApplication',
            'applicationSubCategory' => 'Satellite tracking API',
            'operatingSystem'     => 'Any',
            'url'                 => $site . '/',
            'description'         => (string) $description,
            'isAccessibleForFree' => true,
            'offers'              => array(
                '@type'         => 'Offer',
                'price'         => '0',
                'priceCurrency' => 'USD',
            ),
            'publisher'           => array('@id' => $site . '/#organization'),
        );
    }

    /**
     * A page node for the legal documents and anything else that is a page and not
     * an article. `inLanguage` is the content locale, not the html tag: the tag
     * varies with the visitor, the text does not.
     *
     * @return array<string,mixed>
     */
    public static function webPage($path, $name, $lang, $description = '')
    {
        $url = self::url($path);

        return array(
            '@type'      => 'WebPage',
            '@id'        => $url . '#webpage',
            'url'        => $url,
            'name'       => (string) $name,
            'description' => (string) $description,
            'inLanguage' => (string) $lang,
            'isPartOf'   => array('@id' => self::site() . '/#website'),
        );
    }

    /**
     * BreadcrumbList from [label, path] pairs. The path is the canonical one, so a
     * breadcrumb can never point at a URL that redirects.
     *
     * @param string $path the page the trail ends on, for the list's own @id
     * @param array<int,array{0:string,1:string}> $items
     * @return array<string,mixed>
     */
    public static function breadcrumbs($path, array $items)
    {
        $list = array();
        $position = 1;
        foreach ($items as $item) {
            $label = isset($item[0]) ? (string) $item[0] : '';
            // Not $path: that is the page this list belongs to, and the loop used to
            // overwrite it, so the list's own @id ended up pointing at the last crumb.
            $itemPath = isset($item[1]) ? (string) $item[1] : '/';
            if ($label === '') {
                continue;
            }
            $list[] = array(
                '@type'    => 'ListItem',
                'position' => $position,
                'name'     => $label,
                'item'     => self::url($itemPath),
            );
            $position++;
        }

        return array('@type' => 'BreadcrumbList', '@id' => self::url($path) . '#breadcrumb', 'itemListElement' => $list);
    }

    /**
     * Open Graph locale for a content locale ('zh-Hans' -> 'zh_CN').
     *
     * @param string $code
     * @return string
     */
    public static function ogLocale($code)
    {
        $map = array(
            'zh-Hans' => 'zh_CN',
            'zh-Hant' => 'zh_TW',
            'en'      => 'en_US',
            'en-US'   => 'en_US',
            'en-GB'   => 'en_GB',
        );
        $code = (string) $code;

        return isset($map[$code]) ? $map[$code] : '';
    }
}
