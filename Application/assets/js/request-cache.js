/* Small, session-memory cache for read-only navigation responses. No persistent user data. */
(function(root){
  'use strict';
  function createRequestCache(now){
    now = now || Date.now;
    var entries = new Map(), pending = new Map(), epoch = 0;
    function clear(){ entries.clear(); pending.clear(); epoch++; }
    function read(key, fetcher){
      var hit = entries.get(key);
      if (hit && hit.until > now()) return Promise.resolve(JSON.parse(hit.json));
      if (pending.has(key)) return pending.get(key).then(function(j){ return JSON.parse(JSON.stringify(j)); });
      var generation = epoch;
      var request = Promise.resolve().then(fetcher).then(function(j){
        if (generation === epoch && j && j.ok && !j.error) {
          entries.delete(key);
          entries.set(key, {json:JSON.stringify(j), until:now() + 15000});
          while (entries.size > 40) entries.delete(entries.keys().next().value);
        }
        return j;
      });
      pending.set(key, request);
      function finish(){ if (pending.get(key) === request) pending.delete(key); }
      request.then(finish, finish);
      return request.then(function(j){ return JSON.parse(JSON.stringify(j)); });
    }
    return {read:read, clear:clear};
  }
  if (typeof module === 'object' && module.exports) module.exports = createRequestCache;
  else root.createRequestCache = createRequestCache;
})(typeof window === 'object' ? window : this);
