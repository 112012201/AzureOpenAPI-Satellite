<?php

/**
 * Legal documents that are not the two long-form ones in lib/Legal.php.
 *
 * Two things live here:
 *
 *   terms-cn / privacy-cn   the mainland-China versions (excluding Hong Kong,
 *                           Macao and Taiwan) of the service terms (2A) and the
 *                           privacy policy (2B). They are separate documents
 *                           rather than a translation of the international ones,
 *                           because the obligations are different: local law
 *                           governs, some accelerated links are not offered, and
 *                           the anti-fraud notice belongs in the document itself.
 *
 *   no-accel                why some accelerated links are not offered (2C). One
 *                           text for every region, because the reason does not
 *                           change with the reader's address -- only whether it
 *                           applies to them does.
 *
 * Shape: doc => locale => title / lede / blocks. A block is one of
 *   array('h' => 'heading')      a section heading
 *   array('p' => 'paragraph')    a paragraph
 *   array('ul' => array(...))    an unordered list
 *   array('note' => 'text')      the highlighted notice block
 *
 * Everything is plain text: the renderer escapes it. No HTML in this file.
 */

$contact = '1422769173@qq.com';
$site = 'https://www.authskin.xyz/';
$effective = '2026-11-12';

return array(

    /* ==================================================================
       2A · 服务协议（中国大陆版本）
       ================================================================== */
    'terms-cn' => array(
        'version' => '2A',
        'updated' => $effective,
        'zh-Hans' => array(
            'title' => '服务协议',
            'lede' => '本协议适用于中国大陆（不含香港特别行政区、澳门特别行政区及台湾地区）的用户。国际版本见 /terms?region=intl。',
            'blocks' => array(
                array('h' => '1. 协议的适用范围'),
                array('p' => '本协议是您与本服务运营者之间关于使用 AzureOpenAPI for Satellite（下称“本服务”）的约定。您访问或使用本服务，即表示您已阅读并同意本协议。若您不同意，请停止使用。'),
                array('p' => '本协议仅适用于中国大陆（不含港澳台）用户。其他国家和地区的用户适用国际版本。'),
                array('h' => '2. 服务内容'),
                array('p' => '本服务是一个只读的卫星轨道数据与卫星元数据接口，将 CelesTrak、AMSAT、SatNOGS 等公开数据源的数据抓取、规范化、合并并缓存后，以 HTTP 接口形式提供。本服务不提供账号注册、不收集用户内容、不提供付费功能。'),
                array('p' => '本服务按“现状”提供。运营者不保证服务不中断、无错误，亦不保证上游数据的准确性、完整性或时效性；上游数据的著作权与使用条件归各数据源所有。'),
                array('h' => '3. 在中国大陆不提供的部分'),
                array('p' => '为遵守中国大陆法律法规及主管部门的要求，本服务在中国大陆（不含港澳台）不展示、不提供部分国际加速链接。您在页面上看到的可用地址即为当前向您提供的全部地址。'),
                array('p' => '上述限制不针对特定用户，也不构成对任何用户的差别待遇；它是对服务提供范围的统一说明。详情见《不加速说明》（/no-acceleration）。'),
                array('h' => '4. 使用规范'),
                array('ul' => array(
                    '不得利用本服务从事违反中国大陆法律法规的活动；',
                    '不得以自动化方式对本服务发起明显超出正常访问量的请求，或绕过限流；',
                    '不得删除、隐藏数据源要求的署名信息，或将数据用于数据源禁止的用途；',
                    '不得将本服务用于侵害他人合法权益的目的。',
                )),
                array('p' => '运营者有权对异常请求采取限流、拒绝服务等措施，以保护服务与其他用户的正常使用。'),
                array('h' => '5. 责任限制'),
                array('p' => '在法律允许的最大范围内，运营者对因使用或无法使用本服务产生的间接损失、利润损失、数据损失不承担责任。若因运营者的故意或重大过失造成您直接损失，运营者依法承担相应责任。'),
                array('h' => '6. 协议的变更与终止'),
                array('p' => '运营者可不时修订本协议。修订后版本在本页发布即生效，页首的版本号随之变更。若您不同意修订内容，请停止使用本服务。'),
                array('p' => '运营者可在提前通知后暂停或终止本服务；因不可抗力、上游停止提供数据或法律要求而暂停的，不视为违约。'),
                array('h' => '7. 法律适用与争议解决'),
                array('p' => '本协议适用中华人民共和国法律（不含港澳台地区法律）。因本协议产生的争议，双方应友好协商解决；协商不成的，提交运营者所在地有管辖权的人民法院诉讼解决。'),
                array('h' => '8. 联系方式'),
                array('p' => '运营者：' . $site . '　联系邮箱：' . $contact . '　生效日期：' . $effective . '。'),
            ),
        ),
        'zh-Hant' => array(
            'title' => '服務條款',
            'lede' => '本條款適用於中國大陸（不含香港特別行政區、澳門特別行政區及台灣地區）的使用者。國際版本見 /terms?region=intl。',
            'blocks' => array(
                array('h' => '1. 條款的適用範圍'),
                array('p' => '本條款是您與本服務營運者之間關於使用 AzureOpenAPI for Satellite（下稱「本服務」）的約定。您存取或使用本服務，即表示您已閱讀並同意本條款。若您不同意，請停止使用。'),
                array('p' => '本條款僅適用於中國大陸（不含港澳台）使用者。其他國家和地區的使用者適用國際版本。'),
                array('h' => '2. 服務內容'),
                array('p' => '本服務是一個唯讀的衛星軌道資料與衛星中繼資料介面，將 CelesTrak、AMSAT、SatNOGS 等公開資料來源的資料抓取、正規化、合併並快取後，以 HTTP 介面形式提供。本服務不提供帳號註冊、不蒐集使用者內容、不提供付費功能。'),
                array('p' => '本服務按「現狀」提供。營運者不保證服務不中斷、無錯誤，亦不保證上游資料的準確性、完整性或時效性；上游資料的著作權與使用條件歸各資料來源所有。'),
                array('h' => '3. 在中國大陸不提供的部分'),
                array('p' => '為遵守中國大陸法律法規及主管部門的要求，本服務在中國大陸（不含港澳台）不展示、不提供部分國際加速連結。您在頁面上看到的可用位址即為目前向您提供的全部位址。'),
                array('p' => '上述限制不針對特定使用者，也不構成對任何使用者的差別待遇；它是對服務提供範圍的統一說明。詳見《不加速說明》（/no-acceleration）。'),
                array('h' => '4. 使用規範'),
                array('ul' => array(
                    '不得利用本服務從事違反中國大陸法律法規的活動；',
                    '不得以自動化方式對本服務發起明顯超出正常存取量的請求，或繞過限流；',
                    '不得刪除、隱藏資料來源要求的署名資訊，或將資料用於資料來源禁止的用途；',
                    '不得將本服務用於侵害他人合法權益的目的。',
                )),
                array('p' => '營運者有權對異常請求採取限流、拒絕服務等措施，以保護服務與其他使用者的正常使用。'),
                array('h' => '5. 責任限制'),
                array('p' => '在法律允許的最大範圍內，營運者對因使用或無法使用本服務產生的間接損失、利潤損失、資料損失不承擔責任。若因營運者的故意或重大過失造成您直接損失，營運者依法承擔相應責任。'),
                array('h' => '6. 條款的變更與終止'),
                array('p' => '營運者可不時修訂本條款。修訂後版本在本頁發布即生效，頁首的版本號隨之變更。若您不同意修訂內容，請停止使用本服務。'),
                array('p' => '營運者可在提前通知後暫停或終止本服務；因不可抗力、上游停止提供資料或法律要求而暫停的，不視為違約。'),
                array('h' => '7. 法律適用與爭議解決'),
                array('p' => '本條款適用中華人民共和國法律（不含港澳台地區法律）。因本條款產生的爭議，雙方應友好協商解決；協商不成的，提交營運者所在地有管轄權的人民法院訴訟解決。'),
                array('h' => '8. 聯絡方式'),
                array('p' => '營運者：' . $site . '　聯絡信箱：' . $contact . '　生效日期：' . $effective . '。'),
            ),
        ),
        'en' => array(
            'title' => 'Terms of Service',
            'lede' => 'These terms apply to users in mainland China, excluding the Hong Kong SAR, the Macao SAR and the Taiwan region. The international version is at /terms?region=intl.',
            'blocks' => array(
                array('h' => '1. Scope'),
                array('p' => 'These terms are the agreement between you and the operator of this service for the use of AzureOpenAPI for Satellite ("the service"). By accessing or using the service you confirm that you have read and accept these terms. If you do not accept them, do not use the service.'),
                array('p' => 'These terms apply to users in mainland China (excluding Hong Kong, Macao and Taiwan). Users elsewhere are covered by the international version.'),
                array('h' => '2. The service'),
                array('p' => 'The service is a read-only HTTP interface for satellite orbital elements and satellite metadata. It fetches public data from CelesTrak, AMSAT and SatNOGS, normalises, merges and caches it, and republishes it over stable endpoints. There are no accounts, no user content and no paid features.'),
                array('p' => 'The service is provided as is. The operator does not warrant uninterrupted or error-free operation, nor the accuracy, completeness or timeliness of upstream data; copyright in that data and the conditions of its use belong to each upstream provider.'),
                array('h' => '3. What is not offered in mainland China'),
                array('p' => 'To comply with the laws and regulations of mainland China and with the requirements of the competent authorities, the service does not display or provide certain international acceleration links to users in mainland China (excluding Hong Kong, Macao and Taiwan). The addresses you can see on the site are the complete set offered to you.'),
                array('p' => 'This limitation is not directed at any particular user and is not differential treatment; it describes the scope of the service uniformly. See the Acceleration Notice (/no-acceleration) for the detail.'),
                array('h' => '4. Acceptable use'),
                array('ul' => array(
                    'Do not use the service for any activity prohibited by the laws of mainland China;',
                    'Do not issue automated requests materially above normal volume, or bypass rate limiting;',
                    'Do not remove or obscure the attribution required by an upstream provider, or use its data for a purpose it prohibits;',
                    'Do not use the service to infringe the lawful rights of others.',
                )),
                array('p' => 'The operator may rate-limit or refuse abnormal traffic to protect the service and other users.'),
                array('h' => '5. Liability'),
                array('p' => 'To the fullest extent permitted by law the operator is not liable for indirect loss, loss of profit or loss of data arising from use of or inability to use the service. Where direct loss results from the operator\'s wilful misconduct or gross negligence, the operator is liable as required by law.'),
                array('h' => '6. Changes and termination'),
                array('p' => 'The operator may revise these terms from time to time. A revision takes effect when published on this page, and the version number at its head changes with it. If you do not accept a revision, stop using the service.'),
                array('p' => 'The operator may suspend or terminate the service with prior notice. Suspension caused by force majeure, an upstream withdrawing its data or a legal requirement is not a breach.'),
                array('h' => '7. Governing law and disputes'),
                array('p' => 'These terms are governed by the laws of the People\'s Republic of China (excluding the laws of Hong Kong, Macao and Taiwan). Disputes are to be resolved by negotiation; failing that, before the competent people\'s court at the operator\'s domicile.'),
                array('h' => '8. Contact'),
                array('p' => 'Operator: ' . $site . ' · Email: ' . $contact . ' · Effective date: ' . $effective . '.'),
            ),
        ),
    ),

    /* ==================================================================
       2B · 隐私政策（中国大陆版本）
       ================================================================== */
    'privacy-cn' => array(
        'version' => '2B',
        'updated' => $effective,
        'zh-Hans' => array(
            'title' => '隐私政策',
            'lede' => '本政策适用于中国大陆（不含香港特别行政区、澳门特别行政区及台湾地区）的用户。国际版本见 /privacy?region=intl。',
            'blocks' => array(
                array('h' => '1. 我们收集哪些信息'),
                array('p' => '本服务不要求注册、不收集姓名、手机号、邮箱或任何账号信息。提供服务时自动处理的信息只有：IP 地址、请求路径与查询参数、User-Agent、请求标识符，以及请求发生的时间。'),
                array('p' => '这些信息用于判断请求来自哪个法域（以便提供对应版本的服务条款与隐私政策）、排查故障、防范滥用与攻击。我们不会将其用于画像、广告或向第三方出售。'),
                array('h' => '2. 保存期限与存放位置'),
                array('p' => '访问日志保留 14 天，随后轮转删除。日志与统计数据存放于本服务所使用的服务器，不因本政策而产生跨境传输。'),
                array('p' => '为判断法域，我们用 IP 地址与本地保存的 APNIC 公开分配表进行比对。该比对在本服务内部完成，不调用任何第三方地理位置接口，也不把 IP 地址发送给第三方。'),
                array('h' => '3. Cookie 与本地存储'),
                array('p' => '本服务使用少量 Cookie：记录您在 Cookie 对话框中的选择、记录您已阅读的公告、以及保存完成验证后的会话。它们在您作出选择后写入，可在浏览器中随时清除。我们未接入第三方统计或广告脚本。'),
                array('h' => '4. 我们不做的事'),
                array('ul' => array(
                    '不出售、不出租、不交换任何个人信息；',
                    '不使用第三方统计、广告或社交插件；',
                    '不读取您的浏览历史、剪贴板或设备中的其他文件；',
                    '不将日志用于与提供服务无关的目的。',
                )),
                array('h' => '5. 您的权利'),
                array('p' => '您可以要求查阅、更正或删除与您有关的日志记录，也可以要求我们停止处理。请通过下方邮箱联系我们，并说明大致的时间与请求路径以便定位。我们将在核实后 15 个工作日内答复。'),
                array('h' => '6. 未成年人'),
                array('p' => '本服务面向具备完全民事行为能力的用户。若您为未成年人，请在监护人指导下使用；我们不会主动收集未成年人的个人信息。'),
                array('h' => '7. 政策更新'),
                array('p' => '本政策修订后在本页发布即生效，页首版本号随之变更。涉及处理目的、处理方式或信息类型的重大变更时，我们会同时在站内公告中说明。'),
                array('h' => '8. 联系方式'),
                array('p' => '运营者：' . $site . '　隐私事务联系邮箱：' . $contact . '　生效日期：' . $effective . '。'),
            ),
        ),
        'zh-Hant' => array(
            'title' => '隱私權政策',
            'lede' => '本政策適用於中國大陸（不含香港特別行政區、澳門特別行政區及台灣地區）的使用者。國際版本見 /privacy?region=intl。',
            'blocks' => array(
                array('h' => '1. 我們蒐集哪些資訊'),
                array('p' => '本服務不要求註冊、不蒐集姓名、手機號碼、電子郵件或任何帳號資訊。提供服務時自動處理的資訊只有：IP 位址、請求路徑與查詢參數、User-Agent、請求識別碼，以及請求發生的時間。'),
                array('p' => '這些資訊用於判斷請求來自哪個法域（以便提供對應版本的服務條款與隱私權政策）、排查故障、防範濫用與攻擊。我們不會將其用於剖析、廣告或向第三方出售。'),
                array('h' => '2. 保存期限與存放位置'),
                array('p' => '存取日誌保留 14 天，隨後輪替刪除。日誌與統計資料存放於本服務所使用的伺服器，不因本政策而產生跨境傳輸。'),
                array('p' => '為判斷法域，我們用 IP 位址與本機保存的 APNIC 公開分配表進行比對。該比對在本服務內部完成，不呼叫任何第三方地理位置介面，也不把 IP 位址傳送給第三方。'),
                array('h' => '3. Cookie 與本機儲存'),
                array('p' => '本服務使用少量 Cookie：記錄您在 Cookie 對話框中的選擇、記錄您已閱讀的公告、以及保存完成驗證後的作業階段。它們在您作出選擇後寫入，可於瀏覽器中隨時清除。我們未接入第三方統計或廣告腳本。'),
                array('h' => '4. 我們不做的事'),
                array('ul' => array(
                    '不出售、不出租、不交換任何個人資訊；',
                    '不使用第三方統計、廣告或社交外掛；',
                    '不讀取您的瀏覽歷史、剪貼簿或裝置中的其他檔案；',
                    '不將日誌用於與提供服務無關的目的。',
                )),
                array('h' => '5. 您的權利'),
                array('p' => '您可以要求查閱、更正或刪除與您有關的日誌紀錄，也可以要求我們停止處理。請透過下方電子郵件聯絡我們，並說明大致時間與請求路徑以便定位。我們將於核實後 15 個工作日內回覆。'),
                array('h' => '6. 未成年人'),
                array('p' => '本服務面向具備完全民事行為能力的使用者。若您為未成年人，請在監護人指導下使用；我們不會主動蒐集未成年人的個人資訊。'),
                array('h' => '7. 政策更新'),
                array('p' => '本政策修訂後於本頁發布即生效，頁首版本號隨之變更。涉及處理目的、處理方式或資訊類型的重大變更時，我們會同時於站內公告中說明。'),
                array('h' => '8. 聯絡方式'),
                array('p' => '營運者：' . $site . '　隱私事務聯絡信箱：' . $contact . '　生效日期：' . $effective . '。'),
            ),
        ),
        'en' => array(
            'title' => 'Privacy Policy',
            'lede' => 'This policy applies to users in mainland China, excluding the Hong Kong SAR, the Macao SAR and the Taiwan region. The international version is at /privacy?region=intl.',
            'blocks' => array(
                array('h' => '1. What we process'),
                array('p' => 'There is no registration and no collection of names, phone numbers, email addresses or account data. Serving a request automatically processes only: the IP address, the request path and query, the User-Agent, a request identifier, and the time of the request.'),
                array('p' => 'That information is used to decide which jurisdiction a request comes from (so the matching terms and privacy policy can be published), to diagnose faults, and to prevent abuse and attacks. It is not used for profiling or advertising, and it is not sold.'),
                array('h' => '2. Retention and location'),
                array('p' => 'Access logs are kept for 14 days and then rotated away. Logs and counters stay on the servers that run the service; this policy does not cause a cross-border transfer of that information.'),
                array('p' => 'The jurisdiction decision compares the IP address with a local copy of the public APNIC allocation table. The comparison happens inside this service: no third-party geolocation API is called and no address is sent to one.'),
                array('h' => '3. Cookies and local storage'),
                array('p' => 'A small number of cookies are used: your choice in the cookie dialog, the announcements you have read, and the session created after a challenge is solved. They are written after you make a choice and can be cleared from the browser at any time. No third-party analytics or advertising script is loaded.'),
                array('h' => '4. What we do not do'),
                array('ul' => array(
                    'Sell, rent or trade any personal information;',
                    'Load third-party analytics, advertising or social plugins;',
                    'Read your browsing history, clipboard or other files on your device;',
                    'Use logs for anything unrelated to operating the service.',
                )),
                array('h' => '5. Your rights'),
                array('p' => 'You may ask to inspect, correct or delete log records that concern you, or ask us to stop processing them. Write to the address below with an approximate time and request path so the record can be located. We answer within 15 working days of verifying the request.'),
                array('h' => '6. Minors'),
                array('p' => 'The service is intended for users with full legal capacity. Minors should use it under the guidance of a guardian; no minor\'s personal information is knowingly collected.'),
                array('h' => '7. Changes'),
                array('p' => 'A revision takes effect when published on this page and the version number at its head changes. A material change to what is processed, why, or which categories are involved is also announced on the site.'),
                array('h' => '8. Contact'),
                array('p' => 'Operator: ' . $site . ' · Privacy contact: ' . $contact . ' · Effective date: ' . $effective . '.'),
            ),
        ),
    ),

    /* ==================================================================
       2C · 不加速说明（两种法域共用一份）
       ================================================================== */
    'no-accel' => array(
        'version' => '2C',
        'updated' => $effective,
        'zh-Hans' => array(
            'title' => '不加速说明',
            'lede' => '本页说明：为什么在中国大陆（不含港澳台）部分加速链接不展示、不提供，以及这对您意味着什么。',
            'blocks' => array(
                array('note' => '亲爱的市民朋友，天津警方反诈劝阻电话“02296110”系专门针对避免您财产被骗受损而设，请您一旦收到来电，立即接听。如遇可疑情况，请拨打“96110”或“02296110”咨询；一旦被骗，请立即拨打“110”报警。'),
                array('h' => '1. 为什么会有这个页面'),
                array('p' => '本服务在境外部署了多条加速线路，用于改善不同地区的访问质量。按照中国大陆法律法规及主管部门的要求，其中部分线路不得向中国大陆（不含港澳台）用户展示或提供。'),
                array('p' => '因此，当您从中国大陆访问时，站点不会列出这些地址：这不是故障，也不是针对您的限制，而是服务提供范围的统一说明。'),
                array('h' => '2. 这对您有什么影响'),
                array('p' => '您能看到的地址就是当前向您提供的全部地址，它们都可以正常使用。已收藏的旧地址若已不在页面上，说明该地址不再向您所在地区提供，请改用页面上列出的地址。'),
                array('p' => '接口本身的路径、参数与响应格式不因地区而改变；数据内容与缓存策略也一致。变化只在“提供哪些入口地址”这一层。'),
                array('h' => '3. 如果您需要国际加速'),
                array('p' => '本服务不向中国大陆用户提供国际加速入口，也不提供任何绕过当地网络管理要求的方案。需要国际加速的用户，应自行确保其使用行为符合所在地法律法规，并在合法合规的前提下选择相应服务。'),
                array('h' => '4. 反诈提示'),
                array('p' => '任何以本服务名义要求您转账、提供验证码、共享屏幕或下载陌生应用的行为，均与本服务无关。本服务不收取任何费用，不会主动联系您索要个人信息。'),
                array('h' => '5. 变更与联系'),
                array('p' => '本说明随服务范围调整而更新，页首版本号随之变更。相关咨询请联系 ' . $contact . '，运营者官网：' . $site . '。生效日期：' . $effective . '。'),
            ),
        ),
        'zh-Hant' => array(
            'title' => '不加速說明',
            'lede' => '本頁說明：為什麼在中國大陸（不含港澳台）部分加速連結不展示、不提供，以及這對您意味著什麼。',
            'blocks' => array(
                array('note' => '親愛的市民朋友，天津警方反詐勸阻電話「02296110」係專門針對避免您財產被騙受損而設，請您一旦收到來電，立即接聽。如遇可疑情況，請撥打「96110」或「02296110」諮詢；一旦被騙，請立即撥打「110」報警。'),
                array('h' => '1. 為什麼會有這個頁面'),
                array('p' => '本服務在境外佈署了多條加速線路，用於改善不同地區的存取品質。依照中國大陸法律法規及主管部門的要求，其中部分線路不得向中國大陸（不含港澳台）使用者展示或提供。'),
                array('p' => '因此，當您從中國大陸存取時，站點不會列出這些位址：這不是故障，也不是針對您的限制，而是服務提供範圍的統一說明。'),
                array('h' => '2. 這對您有什麼影響'),
                array('p' => '您能看到的位址就是目前向您提供的全部位址，它們都可以正常使用。已收藏的舊位址若已不在頁面上，說明該位址不再向您所在地區提供，請改用頁面上列出的位址。'),
                array('p' => '介面本身的路徑、參數與回應格式不因地區而改變；資料內容與快取策略也一致。變化只在「提供哪些入口位址」這一層。'),
                array('h' => '3. 如果您需要國際加速'),
                array('p' => '本服務不向中國大陸使用者提供國際加速入口，也不提供任何繞過當地網路管理要求的方案。需要國際加速的使用者，應自行確保其使用行為符合所在地法律法規，並在合法合規的前提下選擇相應服務。'),
                array('h' => '4. 反詐提示'),
                array('p' => '任何以本服務名義要求您轉帳、提供驗證碼、共享畫面或下載陌生應用程式的行為，均與本服務無關。本服務不收任何費用，不會主動聯絡您索取個人資訊。'),
                array('h' => '5. 變更與聯絡'),
                array('p' => '本說明隨服務範圍調整而更新，頁首版本號隨之變更。相關諮詢請聯絡 ' . $contact . '，營運者官網：' . $site . '。生效日期：' . $effective . '。'),
            ),
        ),
        'en' => array(
            'title' => 'Acceleration Notice',
            'lede' => 'Why certain accelerated links are not shown or offered in mainland China (excluding Hong Kong, Macao and Taiwan), and what that means for you.',
            'blocks' => array(
                array('note' => 'A fraud-prevention notice for readers in Tianjin: the police anti-fraud hotline 02296110 exists to stop you losing money to a scam — answer it when it calls. If something looks suspicious, call 96110 or 02296110; if you have been defrauded, call 110 immediately.'),
                array('h' => '1. Why this page exists'),
                array('p' => 'The service runs several acceleration routes outside mainland China to improve access from different regions. Under the laws and regulations of mainland China and the requirements of the competent authorities, some of those routes may not be displayed or offered to users in mainland China (excluding Hong Kong, Macao and Taiwan).'),
                array('p' => 'So when you visit from mainland China those addresses are not listed. That is not a fault and not a restriction aimed at you: it states the scope of the service uniformly.'),
                array('h' => '2. What it means for you'),
                array('p' => 'The addresses you can see are the complete set offered to you, and they all work. If an old address you bookmarked is no longer listed, it is no longer offered in your region — use one of the addresses on the page instead.'),
                array('p' => 'Paths, parameters and response formats do not change with region, and neither does the data or the caching. What changes is only which entry addresses are offered.'),
                array('h' => '3. If you need international acceleration'),
                array('p' => 'The service does not offer an international acceleration entry point to users in mainland China, and offers no means of circumventing local network-management requirements. Users who need international acceleration are responsible for ensuring their own use complies with the law where they are, and for choosing a service that is lawful for them.'),
                array('h' => '4. Fraud warning'),
                array('p' => 'Nothing this service does involves asking you to transfer money, share a verification code, share your screen or install an unknown app. The service charges nothing and will never contact you asking for personal information.'),
                array('h' => '5. Changes and contact'),
                array('p' => 'This notice is updated when the scope of the service changes, and the version number at its head changes with it. Questions: ' . $contact . ' · Operator site: ' . $site . ' · Effective date: ' . $effective . '.'),
            ),
        ),
    ),
);
