/* Vaultcast clean URLs: pure helpers shared by app.js and the node tests.
   Paths are relative to the install base (window.__STREAM_BASE__), e.g. "movies/runner-2026". */
(function(root){
  'use strict';
  function enc(s){ return encodeURIComponent(String(s)); }
  function dec(s){ try { return decodeURIComponent(s); } catch (e) { return s; } }
  function letter(value){ value = String(value || '').toUpperCase(); return /^[A-Z#]$/.test(value) ? value : ''; }
  function withLetter(path, value){ value = letter(value); return path + (value ? '?letter=' + enc(value) : ''); }

  /** App state -> path (no leading slash). */
  function build(st){
    st = st || {};
    var v = st.view;
    if (v === 'library') return 'library';
    if (v === 'home') return st.cinemaId ? 'home/cinema/' + enc(st.cinemaId) : (st.hubTab && st.hubTab !== 'overview' ? 'home/' + enc(st.hubTab) : '');
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
    if (v === 'live') return st.liveCh ? (String(st.liveCh).indexOf('radio:')===0?'live/radio/'+enc(String(st.liveCh).slice(6)):'live/' + enc(st.liveCh)) : (st.liveTab && st.liveTab!=='now' ? 'live/'+enc(st.liveTab) : 'live');
    if (v === 'play') {
      if (st.playTab === 'lobby') return 'games/lobby';
      if (st.playTab === 'leaderboards') return 'games/leaderboards' + (st.playSub ? '/' + enc(st.playSub) : '');
      if (st.playTab === 'arcade' || st.playTab === 'quiz' || st.playTab === 'social' || st.playTab === 'achievements') return 'play/' + st.playTab + (st.playSub ? '/' + String(st.playSub).split('/').map(enc).join('/') : '');
      if (st.gamePath) return 'games/' + String(st.gamePath).split('/').map(enc).join('/') + (/^[a-f0-9]{16}$/.test(st.roomId || '') ? '?room=' + enc(st.roomId) : '');
      if (st.systemSlug) return withLetter('games/' + enc(st.systemSlug), st.sectionLetter);
      return withLetter('games', st.sectionLetter);
    }
    if (v === 'section' && st.section) return withLetter('library/' + enc(st.section), st.sectionLetter);
    if (v === 'collection' && st.collectionId) return 'collections/' + enc(st.collectionId);
    if (v === 'collections') return withLetter('collections', st.collectionsLetter);
    if (v === 'search') return st.searchQuery ? 'search?q=' + enc(st.searchQuery) : 'search';
    if (v === 'settings' || v === 'admin') {
      var sec = v === 'admin' ? (st.adminTab || 'general') : st.settingsSection;
      return sec && sec !== 'account' ? 'settings/' + enc(sec) : 'settings';
    }
    if (v === 'misc') return 'misc' + (st.miscTab ? '/' + enc(st.miscTab) + (st.miscSub ? '/' + enc(st.miscSub) : '') : '');
    if (v === 'ai') return 'ai' + (st.aiTab && st.aiTab !== 'images' ? '/' + enc(st.aiTab) : '');
    if (v === 'offline') return 'offline';
    if (v === 'requests') return st.requestsTab === 'manage' ? 'requests/manage' : (st.requestsTab === 'mine' ? 'requests/mine' : 'requests');
    if (v === 'inbox') return st.inboxPane === 'mail' ? 'inbox/private' : 'inbox';
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
    /* Unified inbox; the old Home → Inbox and Settings → Inbox addresses land here too. */
    if (head === 'inbox') return { view:'inbox', inboxPane: parts[1] === 'private' ? 'mail' : null };
    if ((head === 'home' || head === 'settings') && parts[1] === 'inbox') return { view:'inbox' };
    /* Media requests (Library → Requests): discover, my requests, and the managers' queue. */
    if (head === 'requests') return (!parts[1] || parts[1] === 'mine' || parts[1] === 'manage') && !parts[2] ? { view:'requests', requestsTab: parts[1] || 'discover' } : { view:'notfound', path:path };
    if (head === 'home') {
      var tab = parts[1] || 'overview';
      if (['overview','favourites','watchlist','calendar','friends','history','stats','inbox','cinema','arrivals','lists','chat'].indexOf(tab) < 0) return {view:'notfound'};
      return {view:'home',hubTab:tab,cinemaId:tab === 'cinema' && /^[a-f0-9]{16}$/.test(parts[2] || '') ? parts[2] : null};
    }
    if (head === 'library' && !parts[1]) return {view:'library'};
    if (head === 'movies' && parts[1]) return { view:'movie', slug:parts[1], resolve:true };
    if (head === 'movies') return { view:'home' };
    if (head === 'tvshows' && parts[1]) return { view:'show', slug:parts[1], resolve:true };
    if (head === 'tvshows') return { view:'home' };
    if (head === 'live') return ['now','relay','iptv','radio','guide'].indexOf(parts[1])>=0 ? {view:'live',liveTab:parts[1]==='guide'?'now':parts[1],liveCh:parts[1]==='radio'&&parts[2]?'radio:'+parts[2]:null} : {view:'live',liveTab:'now',liveCh:parts[1]||null};
    if (head === 'play') {
      var pt = parts[1] || 'arcade';
      if (['arcade','quiz','social','achievements'].indexOf(pt) < 0) return { view:'notfound', path:path };
      return { view:'play', playTab:pt, playSub:parts.slice(2, 4).join('/') };
    }
    if (head === 'offline' && !parts[1]) return { view:'offline' };
    if (head === 'misc') {
      var mt = parts[1] || '';
      if (mt && ['board','calendar','drop','ambient'].indexOf(mt) < 0) return { view:'notfound', path:path };
      return { view:'misc', miscTab:mt || 'board', miscSub:parts[2] || '' };
    }
    if (head === 'ai') return !parts[1] || parts[1] === 'images' ? { view:'ai', aiTab:'images' } : { view:'notfound', path:path };
    if (head === 'games' && parts[1] === 'leaderboards' && !parts[3]) return { view:'play', playTab:'leaderboards', playSub:parts[2] || '' };
    if (head === 'games') {
      if (parts[1] === 'lobby' || !parts[1]) return { view:'play', playTab: parts[1] === 'lobby' ? 'lobby' : 'games', sectionLetter:parts[1] === 'lobby' ? '' : letter(params.letter) };
      if (parts[2]) return { view:'game', system: parts[1], slug: parts[2], resolve:true, roomId:/^[a-f0-9]{16}$/.test(params.room || '') ? params.room : null };
      return { view:'play', playTab:'games', systemSlug: parts[1], resolve:true, sectionLetter:letter(params.letter) };
    }
    if (head === 'library' && parts[1]) return { view:'section', section:parts[1], sectionLetter:letter(params.letter) };
    if (head === 'collections') return parts[1] ? { view:'collection', collectionId:parts[1] } : { view:'collections', collectionsLetter:letter(params.letter) };
    if (head === 'search') return { view:'search', searchQuery: params.q || '' };
    if (head === 'settings') return { view:'settings', settingsSection: parts[1] || 'settings-home' };
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
