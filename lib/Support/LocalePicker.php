<?php

namespace SatApi\Support;

/**
 * The language selector, rendered once for every page that offers one.
 *
 * Three rules decide what it contains, and none of them belong at the call site:
 *
 *   1. the ZONE decides the choices. A mainland visitor is offered Simplified
 *      Chinese and English; everyone else is offered all three. That comes from
 *      I18n::switcher(), so a page cannot accidentally offer a language its zone
 *      does not serve.
 *   2. the labels are ENDONYMS -- 简体中文, 繁體中文, English (US). A language
 *      picker written only in English is a picker only English readers can use.
 *   3. the markup is a button group with `data-lang-choice`, which is the single
 *      contract assets/consent.js binds to. Adding a picker to a new page means
 *      calling this, not writing another copy of the loop.
 *
 * The buttons are inert until consent.js runs, and the server has already marked
 * the right one active, so a visitor without JavaScript still sees the language
 * the address and browser asked for.
 */
final class LocalePicker
{
    /**
     * @param array<string,mixed> $locale a value from I18n::resolve()
     * @param string              $class  base class; buttons get "{$class}__btn"
     * @param string              $attrs  extra attributes for the container
     * @return string '' when there is nothing to choose between
     */
    public static function render(array $locale, $class = 'lang', $attrs = '')
    {
        $entries = I18n::switcher($locale);
        if (count($entries) < 2) {
            return '';
        }
        $esc = static function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };
        $class = trim((string) $class) === '' ? 'lang' : trim((string) $class);
        // The container may carry more than one class ("lang lang--header"); the
        // BUTTON class is derived from the first one only. Using the whole string
        // produced "lang lang--header__btn", which matched no rule and left the
        // controls as bare text in the app bar -- the exact bug this comment exists to
        // stop coming back.
        $names = preg_split('/\s+/', $class);
        if (!is_array($names) || $names === array() || (string) $names[0] === '') {
            $names = array('lang');
        }
        $base = (string) $names[0];
        $buttonClass = $base . '__btn';
        $code = isset($locale['code']) ? (string) $locale['code'] : 'en';
        $active = isset($locale['tag']) ? (string) $locale['tag'] : $code;

        $out = '<div class="' . $esc($class) . '" role="group"'
            . ' aria-label="' . $esc(I18n::translate($code, 'nav.language', 'Language')) . '"'
            . ($attrs === '' ? '' : ' ' . $attrs) . '>';
        foreach ($entries as $entry) {
            $isActive = $entry['code'] === $active;
            $out .= '<button class="' . $esc($buttonClass) . ($isActive ? ' is-active' : '') . '" type="button"'
                . ' data-lang-choice="' . $esc($entry['code']) . '"'
                . ' aria-pressed="' . ($isActive ? 'true' : 'false') . '"'
                // The name of a language is written IN that language, so the
                // button declares it rather than inheriting the page's.
                . ' lang="' . $esc($entry['html_lang']) . '"'
                . ' hreflang="' . $esc($entry['html_lang']) . '">'
                . $esc($entry['name'])
                . '</button>';
        }

        return $out . '</div>';
    }
}
