/* ==========================================================================
   Announcement board — the dialog, its three answers, and the refusal gate.

   Plain ES5-compatible JavaScript, no dependencies, no network. The server has
   already rendered every panel into the document (see partials/shell.php), so this
   file only decides WHICH one is on screen and records what the visitor said.

   WHY THE DECISION IS HERE AND NOT ON THE SERVER

   The document is served `public, s-maxage=600` through two CDNs. A page that
   rendered "this visitor has read announcement X" would be cached under a URL that
   another visitor shares, and the bug that produces is already in this project's
   history twice over (see the note above `cookies` in partials/shell.php):

     read   the cookie `announcement_read` holds the ids that were acknowledged
     reset  "later" stores NOTHING, which is exactly what "ask me again next time"
            means; a value would turn it into a silent dismissal
     gate   the cookie `announcement_declined` holds an id the visitor refused; the
            page content is covered until they agree, and the gate's button clears
            the refusal and reloads, which is the only way back

   Losing one of those cookies costs a repeated dialog, never a broken page: every
   state falls back to "ask".

   The one thing that is deliberately missing is a hard block. The HTML is already
   on the wire, so the gate is a cover; withholding the content server-side would
   mean rendering the page per visitor, i.e. giving up the CDN to enforce a notice.
   That trade-off was made by the operator and is recorded in the handoff notes.
   ========================================================================== */
(function (window, document) {
  'use strict';

  var boot = window.__SATAPI_BOOT__ || {};
  var cfg = boot.announcements || {};
  var READ_COOKIE = cfg.readCookie || 'announcement_read';
  var DECLINED_COOKIE = cfg.declinedCookie || 'announcement_declined';
  var DAYS = parseInt(cfg.days, 10) || 180;
  var MAX_IDS = 40; // cookie hygiene: 40 ids of 64 chars still fit in 4 KB

  /* ------------------------------------------------------------- cookies */

  function readCookie(name) {
    var parts = String(document.cookie || '').split(';');
    for (var i = 0; i < parts.length; i++) {
      var piece = parts[i].replace(/^\s+/, '');
      if (piece.indexOf(name + '=') === 0) {
        return decodeURIComponent(piece.slice(name.length + 1));
      }
    }
    return '';
  }

  function writeCookie(name, value, maxAgeSeconds) {
    var attrs = '; Path=/; SameSite=Lax';
    if (maxAgeSeconds) { attrs += '; Max-Age=' + maxAgeSeconds; }
    if (window.location.protocol === 'https:') { attrs += '; Secure'; }
    document.cookie = name + '=' + encodeURIComponent(value) + attrs;
  }

  /** A comma separated id list, trimmed and de-duplicated. */
  function idList(raw) {
    var out = [];
    var bits = String(raw || '').split(',');
    for (var i = 0; i < bits.length; i++) {
      var id = String(bits[i]).replace(/^\s+|\s+$/g, '');
      if (id && out.indexOf(id) === -1) { out.push(id); }
    }
    return out;
  }

  function addId(cookieName, id) {
    var ids = idList(readCookie(cookieName));
    if (ids.indexOf(id) === -1) { ids.push(id); }
    // Keep the NEWEST ids: an old entry silently falling out of a full cookie only
    // means that notice may be asked again, which is the harmless direction.
    while (ids.length > MAX_IDS) { ids.shift(); }
    writeCookie(cookieName, ids.join(','), DAYS * 86400);
  }

  function removeId(cookieName, id) {
    var ids = idList(readCookie(cookieName));
    var kept = [];
    for (var i = 0; i < ids.length; i++) {
      if (ids[i] !== id) { kept.push(ids[i]); }
    }
    // Writing an empty cookie when there was nothing to remove would put a stray
    // `announcement_declined=` in every visitor's jar for no reason (measured on the
    // first real run: the read path left one behind).
    if (kept.length === ids.length) { return; }
    writeCookie(cookieName, kept.join(','), DAYS * 86400);
  }

  /* --------------------------------------------------------------- state */

  var panels = [];
  var current = null;

  /* Which language this reader is on.
     The document carries every locale's text and assets/consent.js keeps <html lang>
     and <body data-locale> in step, so this reads the language the page is actually
     showing rather than guessing from the browser. */
  function readingLanguage() {
    var body = document.body ? document.body.getAttribute('data-locale') : '';
    if (body) { return body; }
    var tag = String(document.documentElement.getAttribute('lang') || '').toLowerCase();
    if (tag.indexOf('zh') === 0) {
      return /hant|tw|hk|mo/.test(tag) ? 'zh-Hant' : 'zh-Hans';
    }
    return 'en';
  }

  /* How well one version of a notice fits this reader:
       0  the exact language
       1  language-neutral (no `lang` in its front matter)
       2  the other Chinese script -- readable, just not preferred
       3  a language this reader probably cannot read; used only as a last resort */
  function langRank(itemLang, reading) {
    var lang = String(itemLang || 'all');
    if (lang === 'all' || lang === '') { return 1; }
    if (lang === reading) { return 0; }
    if (lang.indexOf('zh') === 0 && reading.indexOf('zh') === 0) { return 2; }
    return 3;
  }

  /* One notice per id, in the reader's language.
     A notice is published once per language with the SAME id -- the id is the
     dismissal key -- so every version is in the document and exactly one of them is
     queued here. Leaving the others in the DOM is fine: they stay hidden and the
     shared id means acknowledging one acknowledges the notice. */
  function collect() {
    var nodes = document.querySelectorAll('[data-anm-item]');
    var reading = readingLanguage();
    var best = {};
    var order = [];
    for (var i = 0; i < nodes.length; i++) {
      var node = nodes[i];
      var id = node.getAttribute('data-anm-id') || '';
      var rank = langRank(node.getAttribute('data-anm-lang'), reading);
      if (!best.hasOwnProperty(id)) {
        best[id] = { node: node, rank: rank };
        order.push(id);
      } else if (rank < best[id].rank) {
        best[id] = { node: node, rank: rank };
      }
    }
    for (var j = 0; j < order.length; j++) {
      panels.push(best[order[j]].node);
    }
  }

  function levelOf(panel) {
    return panel.getAttribute('data-anm-level') || 'normal';
  }

  function isRefusable(panel) {
    return panel.getAttribute('data-anm-refusable') === '1';
  }

  function overlay() {
    return document.querySelector('[data-anm]');
  }

  function gate() {
    return document.querySelector('[data-anm-gate]');
  }

  /** Lock the page behind the dialog: one scroll bar, one thing to look at. */
  function lock() {
    if (document.body) { document.body.classList.add('has-announce'); }
  }

  function unlock() {
    if (document.body) { document.body.classList.remove('has-announce'); }
  }

  /** Tell the consent dialog it may now take the screen (see consent.js). */
  function announceClosed() {
    try {
      document.dispatchEvent(new window.Event('satapi:announce-closed'));
    } catch (e) {
      // Very old browsers without the Event constructor: consent.js simply asks
      // again on the next page, which is why this is not allowed to throw.
    }
  }

  /* --------------------------------------------------------------- render */

  function showPanel(panel) {
    var host = overlay();
    if (!host) { return; }
    var all = host.querySelectorAll('[data-anm-item]');
    for (var i = 0; i < all.length; i++) {
      if (all[i] === panel) {
        all[i].removeAttribute('hidden');
      } else {
        all[i].setAttribute('hidden', 'hidden');
      }
    }
    host.removeAttribute('hidden');
    current = panel;
    lock();

    // Focus the affirmative control: the dialog is asking a question, and the
    // keyboard path should land on the answer rather than on the page behind it.
    var primary = panel.querySelector('[data-anm-ack]');
    if (primary && primary.focus) {
      try { primary.focus(); } catch (e) { }
    }
  }

  function hideOverlay() {
    var host = overlay();
    var all = host ? host.querySelectorAll('[data-anm-item]') : [];
    for (var i = 0; i < all.length; i++) { all[i].setAttribute('hidden', 'hidden'); }
    if (host) { host.setAttribute('hidden', 'hidden'); }
    current = null;
  }

  function showGate(panel) {
    var host = gate();
    var id = panel ? panel.getAttribute('data-anm-id') : '';
    if (!host || !id) { return; }
    var name = host.querySelector('[data-anm-gate-name]');
    var title = panel.querySelector('.anm__title');
    if (name) { name.textContent = title ? title.textContent : id; }
    host.setAttribute('data-anm-gate-id', id);
    host.removeAttribute('hidden');
    if (document.body) { document.body.classList.add('has-announce', 'anm-locked'); }
    var button = host.querySelector('[data-anm-gate-reload]');
    if (button && button.focus) {
      try { button.focus(); } catch (e) { }
    }
  }

  /* -------------------------------------------------------------- actions */

  /**
   * The queue: one dialog at a time.
   *
   * Reading three notices stacked on top of each other is not a thing, so after
   * "read/agree" the next unread panel is shown, and after "later" nothing is --
   * "later" defers the whole board, not one item of it.
   */
  function nextUnread() {
    var read = idList(readCookie(READ_COOKIE));
    for (var i = 0; i < panels.length; i++) {
      var id = panels[i].getAttribute('data-anm-id');
      if (read.indexOf(id) === -1) {
        showPanel(panels[i]);
        return true;
      }
    }
    return false;
  }

  function acknowledge() {
    if (!current) { return; }
    addId(READ_COOKIE, current.getAttribute('data-anm-id'));
    // Agreeing also cancels a previous refusal of the same notice, so a visitor who
    // reloaded from the gate cannot end up both read and declined.
    removeId(DECLINED_COOKIE, current.getAttribute('data-anm-id'));
    hideOverlay();
    if (!nextUnread()) {
      unlock();
      announceClosed();
    }
  }

  function later() {
    hideOverlay();
    unlock();
    announceClosed();
  }

  function decline() {
    if (!current) { return; }
    var panel = current;
    addId(DECLINED_COOKIE, panel.getAttribute('data-anm-id'));
    hideOverlay();
    showGate(panel);
  }

  function reloadWithoutRefusal() {
    var host = gate();
    var id = host ? host.getAttribute('data-anm-gate-id') : '';
    if (id) { removeId(DECLINED_COOKIE, id); }
    window.location.reload();
  }

  /* ----------------------------------------------------------------- init */

  function bind() {
    var host = overlay();
    if (host) {
      var ack = host.querySelectorAll('[data-anm-ack]');
      for (var i = 0; i < ack.length; i++) {
        ack[i].addEventListener('click', acknowledge);
      }
      var defer = host.querySelectorAll('[data-anm-later]');
      for (var j = 0; j < defer.length; j++) {
        defer[j].addEventListener('click', later);
      }
      var refuse = host.querySelectorAll('[data-anm-decline]');
      for (var k = 0; k < refuse.length; k++) {
        refuse[k].addEventListener('click', decline);
      }
    }

    var host2 = gate();
    if (host2) {
      var reload = host2.querySelector('[data-anm-gate-reload]');
      if (reload) { reload.addEventListener('click', reloadWithoutRefusal); }
    }

    // Escape is "later": the dialog is not a trap, and a visitor who does not want
    // to decide yet must be able to get out of it without answering.
    document.addEventListener('keydown', function (event) {
      if (!current) { return; }
      if (event.key === 'Escape' || event.keyCode === 27) {
        event.preventDefault();
        later();
      }
    });
  }

  function init() {
    if (!cfg.enabled) { return; }
    collect();
    if (panels.length === 0) { return; }
    bind();

    // A refusal outranks an unread notice: it was made on purpose, later, and by
    // somebody who already knows what the notice says.
    var declined = idList(readCookie(DECLINED_COOKIE));
    for (var i = 0; i < panels.length; i++) {
      var id = panels[i].getAttribute('data-anm-id');
      if (isRefusable(panels[i]) && declined.indexOf(id) !== -1) {
        showGate(panels[i]);
        return;
      }
    }

    if (!nextUnread()) {
      // Nothing to ask. Consent.js is waiting for `has-announce` to clear, so this
      // is also where the cookie dialog is released when the board is empty.
      announceClosed();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Exposed for the self-test: idList and the gate are the two pieces worth
  // exercising without a browser.
  window.SatApiAnnounce = {
    idList: idList,
    readCookie: readCookie,
    writeCookie: writeCookie
  };
}(window, document));
