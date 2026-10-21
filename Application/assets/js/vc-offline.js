/* Vaultcast offline downloads: saves browser-ready copies into IndexedDB on this device, plays them with no
   network, and syncs watch progress back later. Works inside the app and as the offline page the service
   worker shows when the server can't be reached. Loaded on demand. */
(function(root){
  'use strict';
  var A = null, h, api, BASE = '';
  var DB_NAME = 'vaultcast-offline', CHUNK = 8 * 1024 * 1024, db = null;
  var active = null, queue = [], listeners = [];

  /* ---------- tiny helpers (standalone mode has no app helpers) ---------- */
  function mk(tag, attrs, kids){
    var el = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function(k){ var v = attrs[k];
      if (k === 'className') el.className = v; else if (k === 'text') el.textContent = v;
      else if (k.slice(0, 2) === 'on' && typeof v === 'function') el.addEventListener(k.slice(2).toLowerCase(), v);
      else if (v !== false && v != null) el.setAttribute(k, v === true ? '' : v); });
    (kids || []).forEach(function(c){ if (c != null) el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return el;
  }
  function el(t, a, k){ return (h || mk)(t, a || {}, k || []); }
  function toast(msg, kind){ if (A && A.toast) A.toast(msg, { kind:kind || '' }); else { var t = document.getElementById('vc-off-toast'); if (t) { t.textContent = msg; t.hidden = false; setTimeout(function(){ t.hidden = true; }, 4000); } } }
  function apiPath(p){ return BASE + 'api' + p; }
  function post(p, body){ return fetch(apiPath(p), { method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'application/json' }, body:JSON.stringify(body || {}) }).then(function(r){ return r.json().catch(function(){ return { ok:false, error:'The server sent an unexpected reply.' }; }); }); }
  function bytes(n){ n = +n || 0; if (n < 1048576) return Math.max(0, Math.round(n / 1024)) + ' KB'; if (n < 1073741824) return (n / 1048576).toFixed(n < 10485760 ? 1 : 0) + ' MB'; return (n / 1073741824).toFixed(2) + ' GB'; }
  function deviceId(){ var k = 'vc-device-id', v = ''; try { v = localStorage.getItem(k) || ''; } catch (e) {} if (!/^[a-f0-9]{32}$/.test(v)) { var a = new Uint8Array(16); crypto.getRandomValues(a); v = Array.prototype.map.call(a, function(b){ return ('0' + b.toString(16)).slice(-2); }).join(''); try { localStorage.setItem(k, v); } catch (e) {} } return v; }
  function userId(){ var u = A && A.state && A.state.user; if (u && u.id) { try { localStorage.setItem('vc-offline-user', String(u.id)); } catch (e) {} return String(u.id); } try { return localStorage.getItem('vc-offline-user') || ''; } catch (e) { return ''; } }
  function supported(){ return !!(root.indexedDB && root.fetch && root.Blob && root.URL && URL.createObjectURL); }
  function emit(){ listeners = listeners.filter(function(fn){ try { return fn() !== false; } catch (e) { return false; } }); }

  /* ---------- IndexedDB ---------- */
  function open(){
    if (db) return Promise.resolve(db);
    return new Promise(function(res, rej){
      var r = indexedDB.open(DB_NAME, 1);
      r.onupgradeneeded = function(){ var d = r.result;
        if (!d.objectStoreNames.contains('titles')) d.createObjectStore('titles', { keyPath:'key' });
        if (!d.objectStoreNames.contains('chunks')) d.createObjectStore('chunks');
        if (!d.objectStoreNames.contains('progress')) d.createObjectStore('progress', { keyPath:'key' }); };
      r.onsuccess = function(){ db = r.result; res(db); };
      r.onerror = function(){ rej(r.error || new Error('Storage is not available in this browser.')); };
    });
  }
  function tx(store, mode, fn){ return open().then(function(d){ return new Promise(function(res, rej){ var t = d.transaction(store, mode), out; t.oncomplete = function(){ res(out); }; t.onerror = t.onabort = function(){ rej(t.error || new Error('Storage error')); }; out = fn(t.objectStore(store)); }); }); }
  function req(r){ return new Promise(function(res, rej){ r.onsuccess = function(){ res(r.result); }; r.onerror = function(){ rej(r.error); }; }); }
  function getTitle(key){ return open().then(function(d){ return req(d.transaction('titles').objectStore('titles').get(key)); }); }
  function putTitle(t){ return tx('titles', 'readwrite', function(s){ s.put(t); }); }
  function allTitles(){ var uid = userId(); return open().then(function(d){ return req(d.transaction('titles').objectStore('titles').getAll()); }).then(function(list){ return (list || []).filter(function(t){ return t.user_id === uid; }).sort(function(a, b){ return (b.added_at || 0) - (a.added_at || 0); }); }); }
  function putChunk(key, i, blob){ return tx('chunks', 'readwrite', function(s){ s.put(blob, key + ':' + i); }); }
  function chunks(t){ return open().then(function(d){ var s = d.transaction('chunks').objectStore('chunks'), out = []; return Promise.all(Array.from({ length:t.chunks || 0 }, function(_, i){ return req(s.get(t.key + ':' + i)).then(function(b){ out[i] = b; }); })).then(function(){ return out; }); }); }
  function removeTitle(t){ return tx('chunks', 'readwrite', function(s){ for (var i = 0; i < (t.chunks || 0) + 2; i++) s.delete(t.key + ':' + i); }).then(function(){ return tx('titles', 'readwrite', function(s){ s.delete(t.key); }); }); }
  function keyFor(itemId){ return userId() + ':' + itemId; }

  /* ---------- downloads ---------- */
  function report(){ if (!navigator.onLine) return Promise.resolve(); return allTitles().then(function(list){ return post('/offline/inventory', { device:deviceId(), items:list.filter(function(t){ return t.status === 'done' || t.status === 'downloading' || t.status === 'paused'; }).map(function(t){ return t.item_id; }) }); }).catch(function(){}); }

  function ticket(itemId){ return post('/offline/prepare', { item_id:itemId, device:deviceId() }); }

  function download(item, button){
    if (!supported()) { toast('This browser can\u2019t store videos for offline viewing.', 'bad'); return; }
    var id = String(item.id || item.item_id || '');
    if (button) { button.disabled = true; button.textContent = 'Preparing…'; }
    getTitle(keyFor(id)).then(function(existing){
      if (existing && existing.status === 'done') { toast('Already on this device. Find it in Downloads.', 'ok'); if (button) { button.disabled = false; button.textContent = '✓ On this device'; } return; }
      return ticket(id).then(function(j){
        if (!j || !j.ok) { toast((j && j.error) || 'This title can\u2019t be downloaded right now.', 'bad'); if (button) { button.disabled = false; button.textContent = '⬇ Download'; } return; }
        return estimate().then(function(est){
          if (est && est.quota && est.quota - est.usage < j.size * 1.05) { toast('Not enough space on this device for ' + bytes(j.size) + '. Free some space and try again.', 'bad'); if (button) { button.disabled = false; button.textContent = '⬇ Download'; } return; }
          if (navigator.storage && navigator.storage.persist) navigator.storage.persist().catch(function(){});
          var t = existing || { key:keyFor(id), item_id:id, user_id:userId(), received:0, chunks:0, added_at:Date.now() };
          Object.assign(t, { title:j.title || item.title || 'Title', show:j.show || '', season:j.season, episode:j.episode, year:j.year, duration:j.duration || 0, mime:j.mime || 'video/mp4', size:j.size || 0, url:j.url, status:'queued', error:'', resume:t.resume || j.resume || 0 });
          return putTitle(t).then(function(){ return saveExtras(t, j); }).then(function(){ enqueue(t.key); toast('Downloading to this device. See progress in Downloads.', 'ok'); if (button) { button.disabled = false; button.textContent = '⬇ Downloading…'; } });
        });
      });
    }).catch(function(e){ toast((e && e.message) || 'Could not start the download.', 'bad'); if (button) { button.disabled = false; button.textContent = '⬇ Download'; } });
  }

  /* Poster and subtitles are small: fetched once and kept with the title. */
  function saveExtras(t, j){
    var subs = (j.subtitles || []).slice(0, 4);
    return Promise.all([fetch(j.poster, { credentials:'same-origin' }).then(function(r){ return r.ok ? r.blob() : null; }).catch(function(){ return null; })]
      .concat(subs.map(function(s){ return fetch(s.url, { credentials:'same-origin' }).then(function(r){ return r.ok ? r.text() : ''; }).then(function(text){ return text ? { label:s.label || 'Subtitles', lang:s.lang || '', text:text } : null; }).catch(function(){ return null; }); })))
      .then(function(out){ t.poster = out[0] || null; t.subtitles = out.slice(1).filter(Boolean); return putTitle(t); });
  }

  function enqueue(key){ if (queue.indexOf(key) < 0 && (!active || active.key !== key)) queue.push(key); pump(); emit(); }
  function pump(){
    if (active || !queue.length) return;
    var key = queue.shift();
    getTitle(key).then(function(t){ if (!t || t.status === 'done' || t.status === 'paused') { pump(); return; } run(t); });
  }
  function run(t){
    var ctrl = root.AbortController ? new AbortController() : null;
    active = { key:t.key, ctrl:ctrl, title:t };
    t.status = 'downloading'; putTitle(t); emit();
    var retried = false;
    function finish(status, error){ t.status = status; t.error = error || ''; return putTitle(t).then(function(){ active = null; emit(); report(); pump(); }); }
    function next(){
      if (t.size && t.received >= t.size) return finish('done').then(function(){ toast('\u201c' + t.title + '\u201d is ready to watch offline.', 'ok'); });
      var end = t.size ? Math.min(t.size, t.received + CHUNK) - 1 : t.received + CHUNK - 1;
      return fetch(t.url, { credentials:'same-origin', headers:{ Range:'bytes=' + t.received + '-' + end }, signal:ctrl ? ctrl.signal : undefined }).then(function(r){
        if ((r.status === 401 || r.status === 403 || r.status === 404 || r.status === 410) && !retried) {
          // Playback tickets expire; ask for a fresh one once and carry on where we stopped.
          retried = true; return ticket(t.item_id).then(function(j){ if (!j || !j.ok) throw new Error((j && j.error) || 'The server refused the download.'); t.url = j.url; return putTitle(t).then(next); });
        }
        if (r.status === 200 && t.received > 0) throw new Error('The server can\u2019t resume this download. Delete it and start again.');
        if (!r.ok) throw new Error('Download stopped (server said ' + r.status + ').');
        if (r.status === 200) return whole(r);
        retried = false;
        return r.blob().then(function(b){ return putChunk(t.key, t.chunks, b).then(function(){ t.chunks++; t.received += b.size; if (!t.size) { var m = /\/(\d+)$/.exec(r.headers.get('Content-Range') || ''); if (m) t.size = +m[1]; } return putTitle(t); }).then(function(){ emit(); return b.size ? next() : finish('done'); }); });
      });
    }
    /* No range support: stream the body and store it in pieces as it arrives (pausing restarts). */
    function whole(r){
      if (!r.body || !r.body.getReader) return r.blob().then(function(b){ return putChunk(t.key, 0, b).then(function(){ t.chunks = 1; t.received = t.size = b.size; return finish('done'); }); });
      var reader = r.body.getReader(), parts = [], size = 0;
      function flush(){ if (!size) return Promise.resolve(); var b = new Blob(parts, { type:t.mime }); parts = []; size = 0; return putChunk(t.key, t.chunks, b).then(function(){ t.chunks++; t.received += b.size; return putTitle(t); }).then(emit); }
      function pull(){ return reader.read().then(function(x){ if (x.done) return flush().then(function(){ t.size = t.received; return finish('done'); }); parts.push(x.value); size += x.value.byteLength; return (size >= CHUNK ? flush() : Promise.resolve()).then(pull); }); }
      return pull();
    }
    next().catch(function(e){
      if (e && e.name === 'AbortError') return;
      finish('error', (e && e.message) || 'Download failed.');
    });
  }
  function pause(key){ if (active && active.key === key && active.ctrl) { active.ctrl.abort(); var t = active.title; active = null; getTitle(key).then(function(cur){ cur.status = 'paused'; return putTitle(cur); }).then(function(){ emit(); pump(); }); } else getTitle(key).then(function(t){ if (t) { t.status = 'paused'; queue = queue.filter(function(k){ return k !== key; }); putTitle(t).then(emit); } }); }
  function resume(key){ getTitle(key).then(function(t){ if (!t) return; if (t.received > 0 && t.status === 'error' && /resume/.test(t.error)) { t.received = 0; t.chunks = 0; } t.status = 'queued'; t.error = ''; putTitle(t).then(function(){ enqueue(key); }); }); }
  function remove(t){ if (active && active.key === t.key && active.ctrl) { active.ctrl.abort(); active = null; } queue = queue.filter(function(k){ return k !== t.key; }); return removeTitle(t).then(function(){ emit(); report(); pump(); }); }
  function estimate(){ return navigator.storage && navigator.storage.estimate ? navigator.storage.estimate().catch(function(){ return null; }) : Promise.resolve(null); }

  /* ---------- offline progress ---------- */
  function saveProgress(t, position){
    t.resume = position; putTitle(t);
    var entry = { key:t.key, item_id:t.item_id, user_id:t.user_id, position:position, duration:t.duration || 0, at:Math.floor(Date.now() / 1000) };
    return tx('progress', 'readwrite', function(s){ s.put(entry); }).then(sync);
  }
  var syncing = false;
  function sync(){
    if (syncing || !navigator.onLine) return Promise.resolve();
    syncing = true; var uid = userId();
    return open().then(function(d){ return req(d.transaction('progress').objectStore('progress').getAll()); }).then(function(list){
      list = (list || []).filter(function(p){ return p.user_id === uid; });
      if (!list.length) return;
      return post('/offline/progress', { entries:list.map(function(p){ return { item_id:p.item_id, position:p.position, duration:p.duration, at:p.at }; }) }).then(function(j){
        if (j && j.ok) return tx('progress', 'readwrite', function(s){ list.forEach(function(p){ s.delete(p.key); }); });
      });
    }).catch(function(){}).then(function(){ syncing = false; });
  }
  root.addEventListener('online', function(){ sync(); report(); });

  /* ---------- player ---------- */
  function play(t){
    chunks(t).then(function(parts){
      if (parts.some(function(p){ return !p; })) { toast('Part of this download is missing. Delete it and download again.', 'bad'); return; }
      var src = URL.createObjectURL(new Blob(parts, { type:t.mime || 'video/mp4' })), urls = [src];
      var video = el('video', { controls:true, autoplay:true, playsinline:true, className:'vc-off-video' });
      video.src = src;
      (t.subtitles || []).forEach(function(s, i){ var u = URL.createObjectURL(new Blob([s.text], { type:'text/vtt' })); urls.push(u); var tr = el('track', { kind:'subtitles', label:s.label, srclang:s.lang || 'und', src:u }); if (i === 0) tr.setAttribute('default', ''); video.appendChild(tr); });
      var last = 0;
      video.addEventListener('loadedmetadata', function(){ if (t.resume > 5 && t.resume < (video.duration || 0) - 15) video.currentTime = t.resume; });
      video.addEventListener('timeupdate', function(){ if (Math.abs(video.currentTime - last) >= 10) { last = video.currentTime; saveProgress(t, video.currentTime); } });
      var overlay = el('div', { className:'vc-off-player', role:'dialog', 'aria-modal':'true', 'aria-label':'Playing ' + t.title });
      function close(){ saveProgress(t, video.currentTime || 0); video.pause(); video.removeAttribute('src'); video.load(); urls.forEach(function(u){ URL.revokeObjectURL(u); }); overlay.remove(); document.removeEventListener('keydown', key); }
      function key(e){ if (e.key === 'Escape') close(); }
      overlay.appendChild(el('div', { className:'vc-off-player-bar' }, [el('strong', { text:label(t) }), el('button', { type:'button', className:'btn ghost', text:'Close', onClick:close })]));
      overlay.appendChild(video);
      document.body.appendChild(overlay); document.addEventListener('keydown', key);
    }).catch(function(){ toast('This download could not be opened.', 'bad'); });
  }
  function label(t){ var ep = t.show ? t.show + (t.season != null && t.episode != null ? ' · S' + ('0' + t.season).slice(-2) + 'E' + ('0' + t.episode).slice(-2) : '') : ''; return ep ? ep + ' · ' + t.title : t.title + (t.year ? ' (' + t.year + ')' : ''); }

  /* ---------- Downloads page ---------- */
  function render(host){
    var list = el('div', { className:'vc-off-list' }), storage = el('p', { className:'muted vc-off-storage', text:'Checking storage…' });
    var note = el('details', { className:'vc-off-note' }, [el('summary', { text:'How offline downloads work' }), el('p', { className:'muted', text:'Titles are saved inside this browser on this device only, as the same browser-ready copy the player uses. They play with no connection, and what you watch syncs back next time you\u2019re online. Clearing site data, private browsing or low storage can remove them. iPhone and iPad (Safari) keep less space and may clear downloads you haven\u2019t opened for a few weeks, so add Vaultcast to the Home Screen and keep big films for when you need them.' })]);
    var head = el('div', { className:'vc-off-head' }, [el('div', {}, [el('span', { className:'eyebrow', text: navigator.onLine ? 'ON THIS DEVICE' : 'OFFLINE' }), el('h1', { text:'Downloads' }), el('p', { className:'muted', text:'Watch anywhere, even with no connection.' })]), storage]);
    host.replaceChildren(el('div', { className:'vc-off' }, [head, list, note]));
    if (!supported()) { list.replaceChildren(empty('This browser can\u2019t keep downloads', 'Try a recent Chrome, Edge, Firefox or Safari.')); storage.textContent = ''; return; }
    function paintStorage(){ estimate().then(function(e){ storage.textContent = e && e.quota ? 'Using ' + bytes(e.usage) + ' of about ' + bytes(e.quota) + ' this browser allows' : ''; }); }
    function paint(){
      if (!host.isConnected) return false;
      allTitles().then(function(titles){
        paintStorage();
        if (!titles.length) { list.replaceChildren(empty('Nothing downloaded yet', navigator.onLine ? 'Open a film or episode and choose \u201c\u2b07 Download\u201d to keep it on this device.' : 'Connect to the internet to download titles.')); return; }
        list.replaceChildren.apply(list, titles.map(row));
      });
    }
    function row(t){
      var pct = t.size ? Math.min(100, Math.round(100 * (t.received || 0) / t.size)) : 0;
      var poster = t.poster ? el('img', { src:URL.createObjectURL(t.poster), alt:'', className:'vc-off-poster' }) : el('div', { className:'vc-off-poster vc-off-poster-empty', text:'🎬' });
      if (t.poster) poster.addEventListener('load', function(){ URL.revokeObjectURL(poster.src); });
      var state = t.status === 'done' ? 'Ready · ' + bytes(t.size) : t.status === 'downloading' ? 'Downloading · ' + pct + '% of ' + bytes(t.size) : t.status === 'paused' ? 'Paused · ' + pct + '%' : t.status === 'queued' ? 'Waiting…' : (t.error || 'Stopped');
      var btns = [];
      if (t.status === 'done') btns.push(el('button', { type:'button', className:'btn primary', text:'▶ Play', onClick:function(){ play(t); } }));
      else if (t.status === 'downloading' || t.status === 'queued') btns.push(el('button', { type:'button', className:'btn', text:'Pause', onClick:function(){ pause(t.key); } }));
      else btns.push(el('button', { type:'button', className:'btn', text:'Resume', disabled:!navigator.onLine, onClick:function(){ resume(t.key); } }));
      btns.push(el('button', { type:'button', className:'btn ghost danger', text:'Delete', onClick:function(){ if (confirm('Delete \u201c' + t.title + '\u201d from this device?')) remove(t).then(paint); } }));
      return el('div', { className:'vc-off-row' + (t.status === 'error' ? ' bad' : '') }, [poster, el('div', { className:'vc-off-copy' }, [el('strong', { text:label(t) }), el('small', { className:'muted', text:state }), t.status !== 'done' ? el('div', { className:'vc-off-bar' }, [el('span', { style:'width:' + pct + '%' })]) : null]), el('div', { className:'row-actions' }, btns)]);
    }
    listeners.push(paint); paint(); report(); sync();
  }
  function empty(t, d){ return A && A.emptyState ? A.emptyState(t, d) : el('div', { className:'vc-off-empty' }, [el('h3', { text:t }), el('p', { className:'muted', text:d })]); }

  /* The page the service worker shows when the server can't be reached. */
  function standalone(host, base){
    BASE = base || BASE; h = null;
    host.replaceChildren(el('div', { className:'vc-off-shell' }, [el('div', { className:'vc-off-banner', text:'You\u2019re offline. Your downloads are below; the rest of Vaultcast comes back when you reconnect.' }), el('div', { id:'vc-off-host' }), el('p', { id:'vc-off-toast', className:'vc-off-toast', hidden:true }),
      el('button', { type:'button', className:'btn', text:'Try again', onClick:function(){ location.reload(); } })]));
    render(document.getElementById('vc-off-host'));
    root.addEventListener('online', function(){ setTimeout(function(){ location.reload(); }, 800); });
  }

  // Resume interrupted downloads when the app opens.
  function restart(){ allTitles().then(function(list){ list.forEach(function(t){ if (t.status === 'downloading' || t.status === 'queued') enqueue(t.key); }); }).catch(function(){}); }

  root.VaultcastOffline = { init:function(app){ A = app; h = app.h; api = app.api; BASE = (app.base || (document.querySelector('base') && document.querySelector('base').getAttribute('href')) || '/'); if (navigator.onLine) { restart(); sync(); } },
    render:render, download:download, standalone:standalone, supported:supported, onDevice:function(id){ return getTitle(keyFor(id)).then(function(t){ return t ? t.status : ''; }).catch(function(){ return ''; }); } };
})(window);
