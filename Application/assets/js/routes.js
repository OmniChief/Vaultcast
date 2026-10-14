/* Vaultcast clean URLs: pure helpers shared by app.js and the node tests.
   Paths are relative to the install base (window.__STREAM_BASE__), e.g. "movies/runner-2026". */
(function(root){
  'use strict';
  function enc(s){ return encodeURIComponent(String(s)); }
  function dec(s){ try { return decodeURIComponent(s); } catch (e) { return s; } }

  /** App state -> path (no leading slash). */
  function build(st){
    st = st || {};
    var v = st.view;
    if (v === 'movie' && (st.movieSlug || st.movieId)) return 'movies/' + enc(st.movieSlug || st.movieId);
    if (v === 'show' && (st.showSlug || st.showKey)) {
      var p = 'tvshows/' + enc(st.showSlug || st.showKey);
      if (st.season != null && st.season !== '') {
        p += '/season-' + parseInt(st.season, 10);
        if (st.episode != null && st.episode !== '') p += '/episode-' + parseInt(st.episode, 10);
        else if (st.episodeItemId) p += '/' + enc(st.episodeItemId);
      }
      return p;
    }
    if (v === 'live') return st.liveCh ? 'live/' + enc(st.liveCh) : 'live';
    if (v === 'play') {
      if (st.playTab === 'lobby') return 'games/lobby';
      if (st.gamePath) return 'games/' + String(st.gamePath).split('/').map(enc).join('/');
      if (st.systemSlug) return 'games/' + enc(st.systemSlug);
      return 'games';
    }
    if (v === 'section' && st.section) return 'library/' + enc(st.section);
    if (v === 'collection' && st.collectionId) return 'collections/' + enc(st.collectionId);
    if (v === 'collections') return 'collections';
    if (v === 'search') return st.searchQuery ? 'search?q=' + enc(st.searchQuery) : 'search';
    if (v === 'settings' || v === 'admin') {
      var sec = v === 'admin' ? (st.adminTab || 'general') : st.settingsSection;
      return sec && sec !== 'account' ? 'settings/' + enc(sec) : 'settings';
    }
    if (v === 'welcome') return 'welcome';
    if (v === 'login') return 'login';
    return '';
  }

  /** path (no base, no leading slash, may include ?query) -> route description. */
  function parse(path){
    path = String(path || '');
    var q = '';
    var qi = path.indexOf('?');
    if (qi >= 0) { q = path.slice(qi + 1); path = path.slice(0, qi); }
    var parts = path.split('/').filter(function(p){ return p !== ''; }).map(dec);
    var head = parts[0] || '';
    var params = {};
    q.split('&').forEach(function(kv){ if (!kv) return; var i = kv.indexOf('='); params[dec(i < 0 ? kv : kv.slice(0, i))] = dec(i < 0 ? '' : kv.slice(i + 1).replace(/\+/g, ' ')); });
    if (head === '') return { view:'home' };
    if (head === 'movies' && parts[1]) return { view:'movie', slug:parts[1], resolve:true };
    if (head === 'movies') return { view:'home' };
    if (head === 'tvshows' && parts[1]) return { view:'show', slug:parts[1], resolve:true };
    if (head === 'tvshows') return { view:'home' };
    if (head === 'live') return { view:'live', liveCh: parts[1] || null };
    if (head === 'games') {
      if (parts[1] === 'lobby' || !parts[1]) return { view:'play', playTab: parts[1] === 'lobby' ? 'lobby' : 'games' };
      if (parts[2]) return { view:'game', system: parts[1], slug: parts[2], resolve:true };
      return { view:'play', playTab:'games', systemSlug: parts[1], resolve:true };
    }
    if (head === 'library' && parts[1]) return { view:'section', section:parts[1] };
    if (head === 'collections') return parts[1] ? { view:'collection', collectionId:parts[1] } : { view:'collections' };
    if (head === 'search') return { view:'search', searchQuery: params.q || '' };
    if (head === 'settings') return { view:'settings', settingsSection: parts[1] || 'account' };
    if (head === 'welcome') return { view:'welcome' };
    if (head === 'login') return { view:'login' };
    return { view:'notfound', path: path };
  }

  /** Old "#/..." links -> new path, or null when the hash is not a legacy route. */
  function legacyHashToPath(hash){
    hash = String(hash || '');
    if (!/^#\//.test(hash)) return null;
    var parts = hash.replace(/^#\/?/, '').split('/').filter(function(p){ return p !== ''; }).map(dec);
    var head = parts[0] || '';
    if (head === '' || head === 'vod') {
      if (!parts[1]) return '';
      if (parts[1] === 'collections') return 'collections';
      return 'library/' + enc(parts[1]);
    }
    if (head === 'live') return parts[1] ? 'live/' + enc(parts[1]) : 'live';
    if (head === 'play') return parts[1] === 'lobby' ? 'games/lobby' : 'games';
    if (head === 'settings' || head === 'admin') return parts[1] ? 'settings/' + enc(parts[1]) : (head === 'admin' ? 'settings/general' : 'settings');
    if (head === 'welcome' || head === 'login') return head;
    return '';
  }

  /** Strip the install base from a location pathname. */
  function relative(pathname, base){
    base = base || '/';
    pathname = String(pathname || '/');
    if (pathname.indexOf(base) === 0) pathname = pathname.slice(base.length);
    else if (pathname + '/' === base) pathname = '';
    return pathname.replace(/^\/+/, '').replace(/^index\.php\/?/, '');
  }

  /** Absolute share link: optional public base URL, else the page's own protocol, host and port. */
  function absolute(path, opts){
    opts = opts || {};
    var pub = String(opts.publicBase || '').trim();
    var prefix;
    if (/^https?:\/\//i.test(pub)) prefix = pub.replace(/\/+$/, '') + '/';
    else prefix = String(opts.origin || '').replace(/\/+$/, '') + (opts.base || '/');
    return prefix + String(path || '').replace(/^\/+/, '');
  }

  /** Same URL asking the server to stream through PHP instead of nginx (X-Accel). */
  function withDirect(url){
    url = String(url || '');
    if (/[?&]direct=1(?:&|#|$)/.test(url)) return url;
    var hash = '', hi = url.indexOf('#');
    if (hi >= 0) { hash = url.slice(hi); url = url.slice(0, hi); }
    return url + (url.indexOf('?') >= 0 ? '&' : '?') + 'direct=1' + hash;
  }

  /** Did a ROM request return the ROM (not an error or an HTML/JSON page)? */
  function romResponseOk(status, contentType){
    if (status !== 200 && status !== 206) return false;
    var t = String(contentType || '').toLowerCase();
    return !(t.indexOf('text/html') === 0 || t.indexOf('application/json') === 0);
  }

  var api = { withDirect: withDirect, romResponseOk: romResponseOk, build: build, parse: parse, legacyHashToPath: legacyHashToPath, relative: relative, absolute: absolute };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.VaultcastRoutes = api;
})(this);
