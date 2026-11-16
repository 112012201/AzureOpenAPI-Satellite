<?php

/**
 * The slogan table behind the first-screen typewriter.
 *
 * The band's one line of copy is typed out, deleted, and typed again in another
 * language -- a small claim about what this page is FOR, made without a paragraph.
 * The themed periods type their greeting instead of "Welcome", so a visitor in
 * December sees "Merry Christmas" arrive in Japanese and then Russian.
 *
 * TWO TABLES, ON PURPOSE
 *
 *   `languages`  one row per language: which way it reads, and what to CALL it in
 *                each of this site's three UI locales. The name is the label that
 *                sits next to the slogan ("English-US" on an English page, 英语 on a
 *                Chinese one), so it is written per locale rather than translated at
 *                runtime -- a machine pass would turn 希伯来语 into 希伯来語 and nobody
 *                would notice for a season.
 *
 *   `slogans`    the text itself, keyed by theme and then by language. A theme with a
 *                missing entry simply does not cycle that language: the typewriter
 *                reads the pairs it is given, it does not assume a full grid.
 *
 *                THE FESTIVAL LINES ARE CONGRATULATIONS, NOT NOUNS, and they carry the
 *                mark that makes them one: 圣诞快乐！ / Merry Christmas! -- never the
 *                bare "圣诞" a label would say. Chinese, Japanese and Korean-in-CJK
 *                contexts take the full-width ！; the rest take !. The plain-theme
 *                lines (Welcome, 欢迎) stay unmarked on purpose: an invitation is not
 *                a congratulation, and "Welcome!" would be the same misreading from
 *                the other direction.
 *
 * WHY THE LABEL IS IN THE PAGE'S LANGUAGE, NOT THE SLOGAN'S
 *
 *   The slogan is shown in its own language and script; the label names it in the
 *   reader's. Someone on the English page reading "Добро пожаловать" gets
 *   "Russian-RU" underneath, not "Добро пожаловать" again.
 *
 * WHICH LANGUAGES
 *
 *   The ones with enough speakers to matter, and enough of a written tradition that a
 *   localised copy of the page is plausible: the UN languages, the big European and
 *   East/Southeast Asian ones, and Persian, Hebrew, Czech and Ukrainian. 26 entries --
 *   more than the "at least 20" the brief asked for, with room to drop one without
 *   falling under.
 *
 * @return array{languages:array<string,array<string,string>>,slogans:array<string,array<string,string>>}
 */
return array(

    /*
     * code => dir + the name in each UI locale.
     *
     * `dir` is the direction the SLOGAN reads, not the page's: Arabic, Hebrew and
     * Persian are rtl, everything here is ltr. The typewriter sets it per phrase, so
     * the caret and the label flip with the text instead of sitting on the wrong side.
     */
    'languages' => array(
        'en'      => array('dir' => 'ltr', 'en' => 'English-US',    'zh-Hans' => '英语',       'zh-Hant' => '英語'),
        'zh-Hans' => array('dir' => 'ltr', 'en' => 'Chinese-CN',    'zh-Hans' => '简体中文',   'zh-Hant' => '簡體中文'),
        'zh-Hant' => array('dir' => 'ltr', 'en' => 'Chinese-TW',    'zh-Hans' => '繁体中文',   'zh-Hant' => '繁體中文'),
        'ja'      => array('dir' => 'ltr', 'en' => 'Japanese-JP',   'zh-Hans' => '日语',       'zh-Hant' => '日語'),
        'ko'      => array('dir' => 'ltr', 'en' => 'Korean-KR',     'zh-Hans' => '韩语',       'zh-Hant' => '韓語'),
        'ru'      => array('dir' => 'ltr', 'en' => 'Russian-RU',    'zh-Hans' => '俄语',       'zh-Hant' => '俄語'),
        'es'      => array('dir' => 'ltr', 'en' => 'Spanish-ES',    'zh-Hans' => '西班牙语',   'zh-Hant' => '西班牙語'),
        'fr'      => array('dir' => 'ltr', 'en' => 'French-FR',     'zh-Hans' => '法语',       'zh-Hant' => '法語'),
        'de'      => array('dir' => 'ltr', 'en' => 'German-DE',     'zh-Hans' => '德语',       'zh-Hant' => '德語'),
        'it'      => array('dir' => 'ltr', 'en' => 'Italian-IT',    'zh-Hans' => '意大利语',   'zh-Hant' => '義大利語'),
        'pt'      => array('dir' => 'ltr', 'en' => 'Portuguese-PT', 'zh-Hans' => '葡萄牙语',   'zh-Hant' => '葡萄牙語'),
        'nl'      => array('dir' => 'ltr', 'en' => 'Dutch-NL',      'zh-Hans' => '荷兰语',     'zh-Hant' => '荷蘭語'),
        'pl'      => array('dir' => 'ltr', 'en' => 'Polish-PL',     'zh-Hans' => '波兰语',     'zh-Hant' => '波蘭語'),
        'tr'      => array('dir' => 'ltr', 'en' => 'Turkish-TR',    'zh-Hans' => '土耳其语',   'zh-Hant' => '土耳其語'),
        'ar'      => array('dir' => 'rtl', 'en' => 'Arabic-SA',     'zh-Hans' => '阿拉伯语',   'zh-Hant' => '阿拉伯語'),
        'he'      => array('dir' => 'rtl', 'en' => 'Hebrew-IL',     'zh-Hans' => '希伯来语',   'zh-Hant' => '希伯來語'),
        'fa'      => array('dir' => 'rtl', 'en' => 'Persian-IR',    'zh-Hans' => '波斯语',     'zh-Hant' => '波斯語'),
        'hi'      => array('dir' => 'ltr', 'en' => 'Hindi-IN',      'zh-Hans' => '印地语',     'zh-Hant' => '印地語'),
        'th'      => array('dir' => 'ltr', 'en' => 'Thai-TH',       'zh-Hans' => '泰语',       'zh-Hant' => '泰語'),
        'vi'      => array('dir' => 'ltr', 'en' => 'Vietnamese-VN', 'zh-Hans' => '越南语',     'zh-Hant' => '越南語'),
        'id'      => array('dir' => 'ltr', 'en' => 'Indonesian-ID', 'zh-Hans' => '印尼语',     'zh-Hant' => '印尼語'),
        'ms'      => array('dir' => 'ltr', 'en' => 'Malay-MY',      'zh-Hans' => '马来语',     'zh-Hant' => '馬來語'),
        'sv'      => array('dir' => 'ltr', 'en' => 'Swedish-SE',    'zh-Hans' => '瑞典语',     'zh-Hant' => '瑞典語'),
        'uk'      => array('dir' => 'ltr', 'en' => 'Ukrainian-UA',  'zh-Hans' => '乌克兰语',   'zh-Hant' => '烏克蘭語'),
        'el'      => array('dir' => 'ltr', 'en' => 'Greek-GR',      'zh-Hans' => '希腊语',     'zh-Hant' => '希臘語'),
        'cs'      => array('dir' => 'ltr', 'en' => 'Czech-CZ',      'zh-Hans' => '捷克语',     'zh-Hant' => '捷克語'),
    ),

    /*
     * theme => language code => the line for that theme in that language.
     *
     * The plain site says Welcome (the loading screen keeps its own tagline; this is
     * the band's copy). Themed periods say their greeting.
     */
    'slogans' => array(
        'default' => array(
            'en'      => 'Welcome',
            'zh-Hans' => '欢迎',
            'zh-Hant' => '歡迎',
            'ja'      => 'ようこそ',
            'ko'      => '환영합니다',
            'ru'      => 'Добро пожаловать',
            'es'      => 'Bienvenido',
            'fr'      => 'Bienvenue',
            'de'      => 'Willkommen',
            'it'      => 'Benvenuto',
            'pt'      => 'Bem-vindo',
            'nl'      => 'Welkom',
            'pl'      => 'Witamy',
            'tr'      => 'Hoş geldiniz',
            'ar'      => 'مرحباً',
            'he'      => 'ברוכים הבאים',
            'fa'      => 'خوش آمدید',
            'hi'      => 'स्वागत है',
            'th'      => 'ยินดีต้อนรับ',
            'vi'      => 'Chào mừng',
            'id'      => 'Selamat datang',
            'ms'      => 'Selamat datang',
            'sv'      => 'Välkommen',
            'uk'      => 'Ласкаво просимо',
            'el'      => 'Καλώς ήρθατε',
            'cs'      => 'Vítejte',
        ),
        'christmas' => array(
            'en'      => 'Merry Christmas!',
            'zh-Hans' => '圣诞快乐！',
            'zh-Hant' => '聖誕快樂！',
            'ja'      => 'メリークリスマス！',
            'ko'      => '메리 크리스마스!',
            'ru'      => 'С Рождеством!',
            'es'      => 'Feliz Navidad!',
            'fr'      => 'Joyeux Noël!',
            'de'      => 'Frohe Weihnachten!',
            'it'      => 'Buon Natale!',
            'pt'      => 'Feliz Natal!',
            'nl'      => 'Vrolijk kerstfeest!',
            'pl'      => 'Wesołych Świąt!',
            'tr'      => 'Mutlu Noeller!',
            'ar'      => 'عيد ميلاد سعيد!',
            'he'      => 'חג מולד שמח!',
            'fa'      => 'کریسمس مبارک!',
            'hi'      => 'क्रिसमस की शुभकामनाएँ!',
            'th'      => 'สุขสันต์วันคริสต์มาส!',
            'vi'      => 'Giáng sinh vui vẻ!',
            'id'      => 'Selamat Natal!',
            'ms'      => 'Selamat Hari Krismas!',
            'sv'      => 'God jul!',
            'uk'      => 'Веселого Різдва!',
            'el'      => 'Καλά Χριστούγεννα!',
            'cs'      => 'Veselé Vánoce!',
        ),
        'newyear' => array(
            'en'      => 'Happy New Year!',
            'zh-Hans' => '新年快乐！',
            'zh-Hant' => '新年快樂！',
            'ja'      => 'あけましておめでとう！',
            'ko'      => '새해 복 많이 받으세요!',
            'ru'      => 'С Новым годом!',
            'es'      => 'Feliz Año Nuevo!',
            'fr'      => 'Bonne année!',
            'de'      => 'Frohes neues Jahr!',
            'it'      => 'Buon anno!',
            'pt'      => 'Feliz Ano Novo!',
            'nl'      => 'Gelukkig nieuwjaar!',
            'pl'      => 'Szczęśliwego Nowego Roku!',
            'tr'      => 'Mutlu Yıllar!',
            'ar'      => 'سنة جديدة سعيدة!',
            'he'      => 'שנה טובה!',
            'fa'      => 'سال نو مبارک!',
            'hi'      => 'नववर्ष की शुभकामनाएँ!',
            'th'      => 'สวัสดีปีใหม่!',
            'vi'      => 'Chúc mừng năm mới!',
            'id'      => 'Selamat Tahun Baru!',
            'ms'      => 'Selamat Tahun Baru!',
            'sv'      => 'Gott nytt år!',
            'uk'      => 'З Новим роком!',
            'el'      => 'Καλή Χρονιά!',
            'cs'      => 'Šťastný nový rok!',
        ),
        'spring' => array(
            'en'      => 'Happy Spring Festival!',
            'zh-Hans' => '春节快乐！',
            'zh-Hant' => '春節快樂！',
            'ja'      => '春節おめでとう！',
            'ko'      => '설날 복 많이 받으세요!',
            'ru'      => 'С Праздником весны!',
            'es'      => 'Feliz Año Nuevo Chino!',
            'fr'      => 'Bonne fête du Printemps!',
            'de'      => 'Frohes Frühlingsfest!',
            'it'      => 'Buona Festa di Primavera!',
            'pt'      => 'Feliz Ano Novo Chinês!',
            'nl'      => 'Gelukkig Lentefestival!',
            'pl'      => 'Szczęśliwego Święta Wiosny!',
            'tr'      => 'Mutlu Bahar Bayramı!',
            'ar'      => 'عيد الربيع سعيد!',
            'he'      => 'חג אביב שמח!',
            'fa'      => 'جشن بهار مبارک!',
            'hi'      => 'वसंत महोत्सव की शुभकामनाएँ!',
            'th'      => 'สุขสันต์วันตรุษจีน!',
            'vi'      => 'Chúc mừng Tết Nguyên Đán!',
            'id'      => 'Selamat Imlek!',
            'ms'      => 'Selamat Tahun Baru Cina!',
            'sv'      => 'Glad vårfest!',
            'uk'      => 'Зі Святом весни!',
            'el'      => 'Καλό Φεστιβάλ Άνοιξης!',
            'cs'      => 'Veselé jaro!',
        ),
    ),
);
