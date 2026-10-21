/* Vaultcast: cache versioned static resources only. Setup, API and media stay online.
   With no connection at all, page loads get a small offline page that plays titles saved on this device
   (Downloads keep their videos in IndexedDB, not in this cache). */
var CACHE = 'vaultcast-static-' + new URL(self.location.href).searchParams.get('v');
var BASE = new URL(self.registration.scope);
var VERSION = new URL(self.location.href).searchParams.get('v') || '';
var OFFLINE_ASSETS = ['assets/css/app.css', 'assets/js/vc-offline.js'].map(function(path){ return BASE.pathname + 'index.php?asset=' + path + '&v=' + encodeURIComponent(VERSION); });
self.addEventListener('install', function(event){
  // The offline page's two files; a failure here must never block the update.
  event.waitUntil(caches.open(CACHE).then(function(cache){ return Promise.all(OFFLINE_ASSETS.map(function(u){ return cache.add(u).catch(function(){}); })); }).then(function(){ return self.skipWaiting(); }));
});
function offlinePage(){
  var esc = function(s){ return String(s).replace(/[&<>"]/g, function(c){ return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;' }[c]; }); };
  var html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<base href="' + esc(BASE.pathname) + '"><title>Vaultcast · Offline</title><link rel="stylesheet" href="' + esc(OFFLINE_ASSETS[0]) + '"></head>'
    + '<body class="vc-offline-page"><main id="app"><p style="padding:24px">Loading your downloads…</p></main>'
    + '<script src="' + esc(OFFLINE_ASSETS[1]) + '"></script><script>window.VaultcastOffline&&VaultcastOffline.standalone(document.getElementById("app"),' + JSON.stringify(BASE.pathname) + ');</script></body></html>';
  return new Response(html, { status:200, headers:{ 'Content-Type':'text/html; charset=utf-8', 'Cache-Control':'no-store' } });
}
self.addEventListener('activate', function(event){
  event.waitUntil(caches.keys().then(function(keys){
    return Promise.all(keys.filter(function(key){
      return (key.indexOf('vaultcast-static-') === 0) && key !== CACHE;
    }).map(function(key){return caches.delete(key);}));
  }).then(function(){return self.clients.claim();}));
});
self.addEventListener('fetch', function(event){
  var url = new URL(event.request.url);
  if (event.request.method !== 'GET' || url.origin !== BASE.origin) return;
  // Page loads: always the network (pages are personal and never cached); the offline page only when it fails.
  if (event.request.mode === 'navigate' && url.pathname.indexOf(BASE.pathname) === 0 && url.pathname.indexOf(BASE.pathname + 'api/') !== 0) {
    event.respondWith(fetch(event.request).catch(offlinePage));
    return;
  }
  var appAsset = url.pathname === BASE.pathname + 'index.php'
    && /^assets\/(?:js\/[A-Za-z0-9_-]+\.js|css\/[A-Za-z0-9_-]+\.css)$/.test(url.searchParams.get('asset') || '');
  if (!appAsset) {
    if (url.pathname.indexOf(BASE.pathname + 'assets/') !== 0) return;
    if (url.pathname.indexOf('/vendor/') >= 0) return;
    if (!/\.(?:js|css|png|svg|woff2?)$/.test(url.pathname)) return;
  }
  event.respondWith(caches.open(CACHE).then(function(cache){
    // Content-versioned assets are immutable for this app version.
    if (url.searchParams.has('v')) return cache.match(event.request).then(function(hit){
      if (hit) return hit;
      return fetch(event.request).then(function(response){
        if (response.ok) cache.put(event.request,response.clone());
        return response;
      });
    });
    return fetch(event.request).then(function(response){
      if (response.ok) cache.put(event.request,response.clone());
      return response;
    }).catch(function(){return cache.match(event.request).then(function(hit){
      return hit || new Response('Resource unavailable offline', {status:503});
    });});
  }));
});
