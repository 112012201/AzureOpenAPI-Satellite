/* ==========================================================================
   media.js — decrypt the film and the music before the elements see them.

   WHY THIS EXISTS
   ---------------
   The video and the audio are stored on the origin as AES-256-CBC ciphertext
   (`<file>.mp4.enc`, `<file>.m4a.enc`). Nothing serves the plaintext, so a crawler that
   follows /assets/film/*.mp4 gets a 404 and a scraper that guesses the .enc path gets
   bytes it cannot open: the key lives behind /media-key, which only answers a browser
   that solved the challenge (the same signed cap_session cookie).

   HOW IT FITS
   -----------
   `load(url, iv, mime)` returns a promise of an object URL. The caller hands that to a
   <video> or <audio> src. Results are memoised per URL for the life of the page, so the
   music rotation replaying a track costs nothing and the film is decrypted once.

   WHAT IT IS NOT
   --------------
   Not DRM: the key reaches this script by design, so a determined visitor can recover
   the plaintext. It stops the two things that actually happen -- crawler collection and
   hotlinking -- and it keeps the ciphertext cacheable, which is what makes the CDN and
   the DiskCache still worth having.

   FAILURE IS QUIET. A page that cannot decrypt (no Web Crypto, key refused, file
   missing) leaves the film on its poster and the music silent; both players already
   handle "nothing to play" without breaking the page.
   ========================================================================== */
(function (window, document) {
  'use strict';

  var KEY_URL = '/media-key';

  var supported = !!(window.crypto && window.crypto.subtle && window.fetch && window.Promise
    && window.URL && window.URL.createObjectURL && window.TextDecoder);

  var keyPromise = null;
  var blobs = {};

  function bytesFromBase64(b64) {
    var bin = window.atob(String(b64 || ''));
    var out = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) {
      out[i] = bin.charCodeAt(i);
    }
    return out;
  }

  function bytesFromHex(hex) {
    var clean = String(hex || '');
    var out = new Uint8Array(clean.length / 2);
    for (var i = 0; i < out.length; i++) {
      out[i] = parseInt(clean.substr(i * 2, 2), 16);
    }
    return out;
  }

  function requestKey() {
    return window.fetch(KEY_URL, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (res) {
        if (!res.ok) { throw new Error('media-key ' + res.status); }
        return res.json();
      })
      .then(function (body) {
        if (!body || !body.key) { throw new Error('media-key empty'); }
        return window.crypto.subtle.importKey(
          'raw', bytesFromBase64(body.key), { name: 'AES-CBC' }, false, ['decrypt']
        );
      });
  }

  /* The challenge and the film start at the same time, so the first key request can
     lose the race: the cookie is minted when consent.js finishes the proof of work, and
     that is exactly the class it sets. Wait for it, then ask again. */
  function whenVerified() {
    return new Promise(function (resolve) {
      if (document.body && document.body.classList.contains('is-verified')) { resolve(); return; }
      if (!window.MutationObserver || !document.body) { resolve(); return; }
      var observer = new window.MutationObserver(function () {
        if (document.body.classList.contains('is-verified')) {
          observer.disconnect();
          resolve();
        }
      });
      observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });
      /* A page with no challenge never sets the class; do not wait forever. */
      window.setTimeout(function () { observer.disconnect(); resolve(); }, 20000);
    });
  }

  function key() {
    if (!keyPromise) {
      keyPromise = requestKey().catch(function (error) {
        keyPromise = null;
        if (error && error.message === 'media-key 403') {
          return whenVerified().then(requestKey);
        }
        throw error;
      });
    }
    return keyPromise;
  }

  function load(url, iv, mime) {
    var target = String(url || '');
    if (!supported || target === '' || !iv) {
      return Promise.reject(new Error('media unavailable'));
    }
    if (blobs[target]) {
      return blobs[target];
    }

    var promise = key().then(function (cryptoKey) {
      return window.fetch(target, { credentials: 'same-origin' }).then(function (res) {
        if (!res.ok) { throw new Error('media ' + res.status); }
        return res.arrayBuffer().then(function (buffer) {
          return window.crypto.subtle.decrypt({ name: 'AES-CBC', iv: bytesFromHex(iv) }, cryptoKey, buffer);
        });
      }).then(function (plain) {
        return window.URL.createObjectURL(new window.Blob([plain], { type: mime || 'application/octet-stream' }));
      });
    });

    blobs[target] = promise;
    promise.catch(function () { delete blobs[target]; });

    return promise;
  }

  window.SatApiMedia = {
    supported: supported,
    load: load,
    key: key
  };
}(window, document));
