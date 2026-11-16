/* =============================================================================
   AzureOpenAPI for Satellite -- ServiceWorker pre-answer.
   =============================================================================

   WHAT THIS IS
   ------------
   A "pre-answer" for headed browsers: when someone opens an API path directly in
   the address bar (https://…/v1/tle), there is nothing on screen until the upstream
   data has been resolved, which can be seconds on a cold cache. This worker answers
   that navigation IMMEDIATELY with a loading screen, hands the real request to the
   network, and swaps in the data when it arrives.

   WHAT IT DELIBERATELY DOES NOT DO
   --------------------------------
   It never touches a request whose `mode` is not "navigate". That single check is
   the whole safety story:

     - curl, wget, CI jobs, monitors, CDN probes, and every fetch()/XHR from a page
       bypass this worker entirely -- their bytes, headers and timing are unchanged;
     - the landing page's own API calls are unaffected;
     - a ServiceWorker can therefore never make the API slower or alter a response
       for a machine client.

   It also never caches an API response. This is a presentation layer, not a cache:
   every load fetches live, so it cannot serve stale data or hide a 5xx.

   WHY THE LOADING PAGE FETCHES THE DATA ITSELF
   --------------------------------------------
   `respondWith` commits the response at the moment it returns, and a navigation
   response cannot be replaced afterwards. So the worker returns a tiny document and
   that document performs the fetch -- a plain `fetch()` (mode "same-origin"), which
   the worker passes straight through because it is not a navigation. No loop, no
   streaming hacks.

   The same document is the only place a browser can read the CDN's cache headers
   for the call it just made, which is why the cache mode is reported there: on a
   same-origin fetch nothing is hidden by CORS, so `EO-Cache-Status` (EdgeOne) and
   `X-Cache-Status` (SCDN) are both visible. SCDN reuses the header name this
   application already emits, so the ORIGIN verdict is read from
   `X-Origin-Cache-Status` -- see lib/Api/Endpoints/Base.php for the measurement.

   AN HTML RESPONSE IS A PAGE, NOT DATA
   ------------------------------------
   Several API paths answer a browser with a real HTML document rather than data:
   /v1/health renders the status dashboard, /v1/status always does, and a site in
   maintenance answers with the maintenance page. Dumping those into the payload
   <pre> showed a visitor the page's SOURCE -- every tag and stylesheet href as text
   -- and then called it "the data".

   So the loading screen now decides from the RESPONSE, not from a path list that
   would drift the moment a new HTML-capable route is added: if the fetched
   Content-Type is HTML, the document is replaced with it (document.open/write), and
   the visitor gets the page exactly as the server designed it -- same styles, same
   scripts, same URL. Anything else keeps the pre-answer panel, which is what that
   panel is for.

   The Accept header asks for HTML first for the same reason: a route that can answer
   either representation must be asked as a browser would ask, or the worker would
   keep receiving JSON from a path whose whole point is the dashboard.
   ========================================================================== */

'use strict';

/* Bump when the loading screen or the interception rules change. The browser
   re-fetches this file on navigation, but an installed worker keeps running until
   the new one activates; skipWaiting() below makes that immediate. */
const VERSION = 'satapi-preanswer-2';

/* Paths whose navigation is pre-answered. Explicit, not a heuristic: a worker that
   guesses can swallow a page it has no business rendering. */
function isApiPath(pathname) {
  /* The worker's own script must never be pre-answered: opening /v1/sw in a tab
     should show the JavaScript, not a loading screen that tries to JSON-parse it.
     (`/v1/sw` sits under /v1/, so the prefix test below would otherwise catch it.) */
  if (pathname === '/v1/sw') { return false; }
  if (pathname === '/v1' || pathname.indexOf('/v1/') === 0) { return true; }
  if (pathname === '/tle' || pathname.indexOf('/tle/') === 0) { return true; }
  if (pathname.indexOf('/satnogs/') === 0) { return true; }
  if (pathname.indexOf('/amsat/') === 0) { return true; }
  if (pathname === '/health' || pathname === '/status') { return true; }
  // Historical .php shims: /tle_weather.php, /health.php, ...
  if (/^\/[A-Za-z0-9_]+\.php$/.test(pathname)) { return true; }
  return false;
}

const TEXT = {
  'zh-Hans': {
    loading: '数据正在载入，请等待数据传输完成…',
    hint: '正在向源站请求最新数据',
    error: '数据加载失败',
    retry: '重试',
    back: '返回总览',
    edge: '边缘缓存',
    origin: '源站缓存',
    endpoint: '地址',
    elapsed: '耗时',
    status: '状态码',
    unknown: '未提供'
  },
  'zh-Hant': {
    loading: '資料正在載入，請等待資料傳輸完成…',
    hint: '正在向源站請求最新資料',
    error: '資料載入失敗',
    retry: '重試',
    back: '返回總覽',
    edge: '邊緣快取',
    origin: '源站快取',
    endpoint: '位址',
    elapsed: '耗時',
    status: '狀態碼',
    unknown: '未提供'
  },
  'en': {
    loading: 'Loading data, please wait for the transfer to complete…',
    hint: 'Requesting the latest data from the origin',
    error: 'The data could not be loaded',
    retry: 'Retry',
    back: 'Back to overview',
    edge: 'Edge cache',
    origin: 'Origin cache',
    endpoint: 'Address',
    elapsed: 'Elapsed',
    status: 'Status',
    unknown: 'not reported'
  }
};

function loaderPage(origin, pathAndQuery) {
  const lang = (navigator.language || 'en').toLowerCase();
  const code = lang.indexOf('zh-hant') === 0 || lang.indexOf('zh-tw') === 0 || lang.indexOf('zh-hk') === 0
    ? 'zh-Hant'
    : (lang.indexOf('zh') === 0 ? 'zh-Hans' : 'en');
  const t = TEXT[code];

  /* The payload is embedded as JSON, not as HTML, so the page can render it as text
     without any risk of the API's own output being interpreted as markup. */
  /* `path` is carried separately from `url` so the tab title can read "HTTP 200 ·
     /v1/tle" instead of the full origin. */
  const boot = JSON.stringify({ t: t, url: origin + pathAndQuery, path: pathAndQuery, code: code });

  return `<!DOCTYPE html>
<html lang="${code}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>${t.loading}</title>
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  html, body { height: 100%; margin: 0; }
  body {
    background: #0b0f14;
    color: #e6edf3;
    font: 15px/1.6 -apple-system, "Segoe UI", "Noto Sans SC", "Noto Sans TC", system-ui, sans-serif;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    padding: 24px; gap: 18px;
  }
  .wrap { width: 100%; max-width: 780px; }
  .head { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; }
  .dot {
    width: 10px; height: 10px; border-radius: 50%;
    background: #58a6ff; flex: 0 0 auto;
    animation: pulse 1.1s ease-in-out infinite;
  }
  @keyframes pulse { 0%,100% { opacity: .25; transform: scale(.85); } 50% { opacity: 1; transform: scale(1); } }
  .title { font-size: 17px; font-weight: 600; margin: 0; }
  .hint { color: #8b949e; font-size: 13px; margin: 2px 0 0; }
  .bar { height: 3px; border-radius: 3px; background: #161b22; overflow: hidden; margin: 4px 0 0; }
  .bar > i {
    display: block; height: 100%; width: 40%;
    background: linear-gradient(90deg, transparent, #58a6ff, transparent);
    animation: slide 1.25s linear infinite;
  }
  @keyframes slide { from { transform: translateX(-100%); } to { transform: translateX(350%); } }
  .meta { margin-top: 16px; color: #8b949e; font-size: 12.5px; display: grid; gap: 4px; }
  .meta b { color: #c9d1d9; font-weight: 600; }
  pre {
    margin: 16px 0 0; padding: 16px; max-height: 62vh; overflow: auto;
    background: #11161d; border: 1px solid #21262d; border-radius: 10px;
    font-size: 12.5px; line-height: 1.55; white-space: pre-wrap; word-break: break-word;
    font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
  }
  .actions { margin-top: 16px; display: flex; gap: 10px; flex-wrap: wrap; }
  button, a.btn {
    font: inherit; font-size: 13.5px; padding: 8px 14px; border-radius: 8px;
    border: 1px solid #30363d; background: #21262d; color: #e6edf3;
    cursor: pointer; text-decoration: none;
  }
  button:hover, a.btn:hover { background: #30363d; }
  .err { color: #f85149; }
  [hidden] { display: none !important; }
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <span class="dot" aria-hidden="true"></span>
    <div>
      <p class="title" id="title">${t.loading}</p>
      <p class="hint" id="hint">${t.hint}</p>
    </div>
  </div>
  <div class="bar" id="bar"><i></i></div>

  <div class="meta" id="meta" hidden>
    <div><b id="m-endpoint"></b></div>
    <div id="m-edge"></div>
    <div id="m-origin"></div>
    <div id="m-status"></div>
  </div>

  <pre id="payload" hidden></pre>

  <div class="actions" id="actions" hidden>
    <button type="button" id="retry">${t.retry}</button>
    <a class="btn" href="/">${t.back}</a>
  </div>
</div>
<script>
(function () {
  var boot = ${boot};
  var t = boot.t;
  var el = function (id) { return document.getElementById(id); };
  var started = Date.now();
  var attempts = 0;

  /* An HTML answer replaces this document outright.
     document.open() resets the parser, so the written markup becomes the page: its
     own styles and scripts run, and relative URLs resolve against the same address
     the visitor typed (the document URL does not change). The URL bar keeps showing
     the API path, which is correct -- that is the resource they asked for. Wrapped
     in try/catch because a browser that refuses a programmatic document write must
     still show the payload rather than an empty screen. */
  function adoptDocument(html) {
    try {
      document.open();
      document.write(html);
      document.close();
      return true;
    } catch (e) {
      return false;
    }
  }

  function report(response, text, error) {
    el('bar').hidden = true;

    if (error) {
      el('title').textContent = t.error;
      el('title').className = 'title err';
      el('hint').textContent = String(error);
      el('actions').hidden = false;
      /* The tab keeps the title it was created with until someone changes it. It
         said "loading…" and would have kept saying it after the request failed, so
         the tab is updated in every branch, not just the happy one. */
      document.title = t.error + ' · ' + boot.path;
      return;
    }

    el('title').textContent = 'HTTP ' + response.status;
    el('hint').textContent = '';
    el('actions').hidden = false;

    /* Same reason as above: with three of these open the browser showed three tabs
       all titled "loading…", which is indistinguishable from three stuck requests. */
    document.title = 'HTTP ' + response.status + ' · ' + boot.path;

    /* Headers are only readable because this is a same-origin fetch. SCDN overwrites
       X-Cache-Status with its own verdict, so the origin's answer is read from
       X-Origin-Cache-Status; when that header is absent (an older cached response)
       the raw X-Cache-Status is still shown, labelled as what it is. */
    var edge = response.headers.get('eo-cache-status') || response.headers.get('x-cache-status') || t.unknown;
    var origin = response.headers.get('x-origin-cache-status') || t.unknown;
    var originAge = response.headers.get('x-origin-cache-age');

    /* The media type is shown because it is the answer to "did I get TLE text or
       JSON?" -- a question the URL alone no longer answers now that ?json=true
       changes the result without changing the path. */
    var type = response.headers.get('content-type') || '';
    var media = type.split(';')[0].trim() || t.unknown;

    /* The page the server wrote, not a text rendering of its source. Checked before
       any of the panel's own labels are touched: after a successful swap none of them
       exist any more. */
    if (type.indexOf('html') !== -1 && text.replace(/\s+/g, '') !== '') {
      if (adoptDocument(text)) { return; }
    }

    el('m-endpoint').textContent = t.endpoint + ': ' + boot.url;
    el('m-edge').textContent = t.edge + ': ' + edge;
    el('m-origin').textContent = t.origin + ': ' + origin + (originAge ? ' (age ' + originAge + 's)' : '');
    el('m-status').textContent = t.status + ': ' + response.status + ' · ' + media
      + ' · ' + t.elapsed + ': ' + (Date.now() - started) + 'ms';
    el('meta').hidden = false;

    var body = text;
    if (type.indexOf('json') !== -1) {
      try { body = JSON.stringify(JSON.parse(text), null, 2); } catch (e) { /* keep raw */ }
    }
    el('payload').textContent = body;
    el('payload').hidden = false;
  }

  function load() {
    attempts++;
    el('bar').hidden = false;
    el('title').textContent = t.loading;
    el('title').className = 'title';
    el('hint').textContent = t.hint;
    el('meta').hidden = true;
    el('payload').hidden = true;
    el('actions').hidden = true;
    document.title = t.loading;

    /* Not a navigation, so the ServiceWorker passes it straight through to the
       network. cache: 'no-store' makes sure a browser cache cannot answer with an
       older copy and make the reported edge status a lie.

       TEXT IS ASKED FOR FIRST, and that is the point of this header. It used to ask
       for application/json first, which made every TLE path render JSON in the
       browser even though the default representation is standard TLE text -- the
       Accept header was silently outvoting the default. JSON is now requested by the
       caller, with ?json=true, and an explicit query parameter outranks Accept (see
       Request::format()), so the switch still works with this header in place. */
    fetch(boot.url, {
      cache: 'no-store',
      credentials: 'same-origin',
      /* HTML first: /v1/health and /v1/status answer a browser with a dashboard, and
         asking for text/plain would be the worker choosing the wrong representation
         for a path whose whole purpose is that page. text/plain;q=0.9 keeps TLE paths
         returning TLE text -- the server's format() never answers HTML for them (the
         TLE routes ignore Accept entirely: their format is ?json=). */
      headers: { 'accept': 'text/html, application/xhtml+xml, text/plain;q=0.9, */*;q=0.8' }
    }).then(function (response) {
      return response.text().then(function (text) { report(response, text, null); });
    }).catch(function (err) {
      report(null, '', err && err.message ? err.message : 'network error');
    });
  }

  el('retry').addEventListener('click', load);
  load();
})();
</script>
</body>
</html>`;
}

self.addEventListener('install', function (event) {
  // Take over immediately: a worker waiting for every tab to close would never
  // activate on a site people keep open.
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', function (event) {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function (event) {
  const request = event.request;

  // THE safety check. Anything that is not a page navigation is left alone.
  if (request.mode !== 'navigate') { return; }
  if (request.method !== 'GET') { return; }

  let url;
  try { url = new URL(request.url); } catch (e) { return; }

  if (url.origin !== self.location.origin) { return; }
  if (!isApiPath(url.pathname)) { return; }

  event.respondWith(new Response(loaderPage(url.origin, url.pathname + url.search), {
    status: 200,
    headers: {
      'Content-Type': 'text/html; charset=utf-8',
      // Never let a CDN or the browser cache the loading screen: it must not
      // outlive the worker that produced it.
      'Cache-Control': 'no-store',
      'X-Satapi-Preanswer': VERSION
    }
  }));
});
