/* Vaultcast: cache versioned static resources only. Setup, API and media stay online. */
var CACHE = 'vaultcast-static-' + new URL(self.location.href).searchParams.get('v');
var BASE = new URL(self.registration.scope);
self.addEventListener('install', function(event){ event.waitUntil(self.skipWaiting()); });
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
  if (url.pathname.indexOf(BASE.pathname + 'assets/') !== 0) return;
  if (url.pathname.indexOf('/vendor/') >= 0) return;
  if (!/\.(?:js|css|png|svg|woff2?)$/.test(url.pathname)) return;
  event.respondWith(caches.open(CACHE).then(function(cache){
    return fetch(event.request).then(function(response){
      if (response.ok) cache.put(event.request,response.clone());
      return response;
    }).catch(function(){return cache.match(event.request).then(function(hit){
      return hit || new Response('Resource unavailable offline', {status:503});
    });});
  }));
});
