/* Vaultcast trailers and extras on film and show pages: a "Play trailer" button in the hero, an Extras
   row (local Trailers / Featurettes / Deleted Scenes and so on) and, when a title has no local trailer,
   the TMDB trailer embedded from youtube-nocookie in a modal. Optional muted trailer behind the hero.
   Loaded on demand. Provider and file text is only ever set with textContent (A.h 'text'). */
(function(root){
  'use strict';
  var A = null, h, api;
  var online = {}; // 'item:<id>' -> Promise of { video }
  var GLYPH = { trailer:'▶', featurette:'★', behindthescenes:'🎬', deleted:'✂', interview:'🎙', scene:'🎞', short:'⏱', other:'＋' };

  function str(v){ return v == null ? '' : String(v); }
  function err(j, fallback){ return j && j.error ? String(j.error) : fallback; }
  function reduced(){ return document.documentElement.getAttribute('data-motion') === 'reduced' || document.body.classList.contains('reduce-motion'); }
  function clock(sec){
    sec = Math.max(0, Math.round(Number(sec) || 0)); if (!sec) return '';
    var hh = Math.floor(sec / 3600), mm = Math.floor((sec % 3600) / 60), ss = sec % 60;
    return (hh ? hh + ':' + (mm < 10 ? '0' : '') : '') + mm + ':' + (ss < 10 ? '0' : '') + ss;
  }
  function endpoint(opts, what){ return '/' + (opts.kind === 'show' ? 'shows' : 'items') + '/' + encodeURIComponent(opts.id) + '/' + what; }
  function ytId(v){ var k = v && str(v.key); return /^[A-Za-z0-9_-]{11}$/.test(k) ? k : ''; }

  /* ---------------------------------------------------------------- local extras */
  function pauseInline(){ var m = document.querySelector('#detail-player video'); if (m && !m.paused) { try { m.pause(); } catch (e) {} } }
  /** Play a local extra in the full-screen player; the film's own inline player comes back (paused) afterwards. */
  function playExtra(x, opts){
    if (!x || !/^[a-f0-9]{24}$/.test(str(x.id))) return;
    stopHeroBackgrounds();
    var host = document.getElementById('detail-player');
    var hadInline = !!(host && host.querySelector('.player-overlay'));
    api('/items/' + x.id + '/play', { method:'POST', body:{} }).then(function(j){
      if (!j || !j.ok) { A.toast(err(j, 'This extra could not be played.'), { kind:'bad' }); return; }
      j.autoplay = true; j.from_start = true;
      A.openPlayer({ id:x.id, title:str(x.title) || str(x.type_label) || 'Extra', ext:str(x.ext), media_kind:'video', _extra:true }, j);
      var ov = document.getElementById('player-overlay');
      if (!ov || !hadInline || !opts || typeof opts.restoreInline !== 'function') return;
      var t = setInterval(function(){
        if (ov.isConnected) return;
        clearInterval(t);
        if (host.isConnected && !host.querySelector('.player-overlay') && !document.getElementById('player-overlay')) opts.restoreInline();
      }, 600);
    }).catch(function(){ A.toast('This extra could not be played.', { kind:'bad' }); });
  }

  function card(x, opts){
    var label = str(x.type_label) || 'Extra', dur = clock(x.duration);
    var thumb = h('div', { className:'vcx-thumb' }, [h('span', { className:'vcx-thumb-glyph', 'aria-hidden':'true', text:GLYPH[x.extra_type] || '▶' })]);
    if (x.thumb && /^\//.test(str(x.thumb))) {
      var img = h('img', { alt:'', loading:'lazy', decoding:'async' });
      img.onload = function(){ thumb.classList.add('has-image'); };
      img.onerror = function(){ img.remove(); };
      img.src = str(x.thumb); thumb.appendChild(img);
    }
    if (dur) thumb.appendChild(h('span', { className:'vcx-duration', text:dur }));
    thumb.appendChild(h('span', { className:'vcx-play', 'aria-hidden':'true', text:'▶' }));
    return h('button', { type:'button', className:'vcx-card', 'aria-label':'Play ' + label.toLowerCase() + ': ' + (str(x.title) || label) + (dur ? ', ' + dur : ''), onClick:function(){ playExtra(x, opts); } }, [
      thumb,
      h('span', { className:'vcx-type', text:label + (x.season != null ? ' · ' + (Number(x.season) === 0 ? 'Specials' : 'Season ' + x.season) : '') }),
      h('strong', { className:'vcx-title', text:str(x.title) || label })
    ]);
  }

  function row(host, list, heading, opts){
    host.replaceChildren(
      h('div', { className:'row-head' }, [h('h2', { text:heading }), h('span', { className:'meta', text:list.length + (list.length === 1 ? ' video' : ' videos') })]),
      h('div', { className:'vcx-strip', role:'list' }, list.map(function(x){ var c = card(x, opts); c.setAttribute('role', 'listitem'); return c; }))
    );
  }

  /* ---------------------------------------------------------------- online trailer modal */
  function lookup(opts){
    var key = opts.kind + ':' + opts.id;
    if (!online[key]) online[key] = api(endpoint(opts, 'trailer')).then(function(j){
      if (!j || !j.ok) { delete online[key]; throw new Error(err(j, 'Could not look up a trailer.')); }
      return j;
    }, function(e){ delete online[key]; throw e; });
    return online[key];
  }

  function trailerModal(opts){
    stopHeroBackgrounds(); pauseInline();
    var previous = document.activeElement, closed = false;
    var frameBox = h('div', { className:'vcx-frame' }, [A.pendingContent({ text:'Finding the trailer…' })]);
    var caption = h('p', { className:'muted vcx-caption' });
    var closeBtn = h('button', { type:'button', className:'icon-btn vcx-close', 'aria-label':'Close trailer', text:'×' });
    var dialog = h('div', { className:'vcx-dialog', role:'dialog', 'aria-modal':'true', 'aria-label':'Trailer: ' + (str(opts.title) || 'this title'), tabindex:'-1' }, [
      h('div', { className:'vcx-dialog-head' }, [h('strong', { text:(str(opts.title) || 'Trailer') }), closeBtn]),
      frameBox, caption
    ]);
    var overlay = h('div', { className:'vcx-overlay' + (reduced() ? '' : ' vcx-animate') }, [dialog]);
    function close(){
      if (closed) return; closed = true;
      document.removeEventListener('keydown', keys, true);
      overlay.remove(); // removing the iframe stops playback
      if (previous && previous.isConnected) previous.focus();
    }
    function keys(e){
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
      if (e.key === 'Tab') { var f = Array.prototype.slice.call(dialog.querySelectorAll('button,a[href],iframe')); if (!f.length) return; var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); } else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); } }
    }
    closeBtn.onclick = close;
    overlay.addEventListener('click', function(e){ if (e.target === overlay) close(); });
    document.addEventListener('keydown', keys, true);
    document.body.appendChild(overlay);
    closeBtn.focus();
    lookup(opts).then(function(j){
      if (closed) return;
      var id = ytId(j.video);
      if (!id) {
        frameBox.replaceChildren(h('div', { className:'vcx-none' }, [h('h3', { text:'No trailer found' }), h('p', { className:'muted', text:j.disabled ? 'Online trailers are turned off on this server.' : 'There isn’t a trailer for this title yet.' })]));
        if (opts.button) { opts.button.disabled = true; opts.button.textContent = 'No trailer'; }
        return;
      }
      frameBox.replaceChildren(h('iframe', { src:'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&playsinline=1&rel=0&modestbranding=1', title:'Trailer: ' + (str(opts.title) || 'this title'),
        allow:'autoplay; encrypted-media; picture-in-picture; fullscreen', allowfullscreen:true, referrerpolicy:'strict-origin-when-cross-origin' }));
      var bits = [str(j.video.name), j.video.official ? 'Official' : '', 'via TMDB · YouTube'].filter(Boolean);
      caption.textContent = bits.join(' · ');
    }).catch(function(e){
      if (closed) return;
      frameBox.replaceChildren(h('div', { className:'vcx-none' }, [h('h3', { text:'Couldn’t load the trailer' }), h('p', { className:'muted', text:(e && e.message) || 'Please try again in a moment.' }),
        h('button', { type:'button', className:'btn', text:'Try again', onClick:function(){ close(); trailerModal(opts); } })]));
    });
  }

  /* ---------------------------------------------------------------- muted hero trailer */
  var heroStops = [];
  function stopHeroBackgrounds(){ var s = heroStops; heroStops = []; s.forEach(function(fn){ try { fn(); } catch (e) {} }); }
  function heroBackground(opts, view){
    var hero = opts.hero; if (!hero || !view.hero_autoplay || reduced()) return;
    var playerHost = document.getElementById('detail-player');
    function busy(){ return !!(playerHost && playerHost.querySelector('video,iframe,#emu-root')) || !!document.querySelector('.player-overlay:not(.player-inline)'); }
    if (busy()) return;
    var layer = h('div', { className:'vcx-hero-bg', 'aria-hidden':'true' }), done = false, observer = null;
    function stop(){ if (done) return; done = true; if (observer) observer.disconnect(); layer.remove(); hero.classList.remove('vcx-hero-has-bg'); }
    heroStops.push(stop);
    if (playerHost && window.MutationObserver) { observer = new MutationObserver(function(){ if (busy() || !hero.isConnected) stop(); }); observer.observe(playerHost, { childList:true, subtree:true }); }
    function attach(el){ if (done || !hero.isConnected || busy()) { stop(); return; } layer.appendChild(el); hero.classList.add('vcx-hero-has-bg'); hero.insertBefore(layer, hero.firstChild); }
    if (view.local_trailer) {
      api('/items/' + view.local_trailer.id + '/play', { method:'POST', body:{} }).then(function(j){
        if (!j || !j.ok || !j.url || done) return;
        var v = h('video', { muted:true, autoplay:true, loop:true, playsinline:true, preload:'auto', tabindex:'-1' }); v.muted = true; v.setAttribute('playsinline', '');
        v.addEventListener('error', stop);
        v.src = A.streamPlayUrl(j.url); attach(v);
        var p = v.play(); if (p && p.catch) p.catch(stop);
      }).catch(function(){});
    } else if (view.online) {
      lookup(opts).then(function(j){
        var id = ytId(j.video); if (!id || done) return;
        attach(h('iframe', { src:'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&mute=1&controls=0&loop=1&playlist=' + id + '&playsinline=1&rel=0&modestbranding=1&disablekb=1', title:'Trailer preview',
          tabindex:'-1', allow:'autoplay; encrypted-media', referrerpolicy:'strict-origin-when-cross-origin', loading:'lazy' }));
      }).catch(function(){});
    }
  }

  /* ---------------------------------------------------------------- page mount */
  /**
   * opts: { kind:'item'|'show', id, title, season (shows: the open season), hero, actions (row-actions element), root }
   * Adds the Play trailer button and an Extras row under the hero. Safe to call on every repaint.
   */
  function mount(opts){
    if (!opts || !opts.id || !opts.root) return;
    stopHeroBackgrounds();
    var old = opts.root.querySelector('.vcx-row'); if (old) old.remove();
    var oldBtn = opts.actions && opts.actions.querySelector('[data-vcx-trailer]'); if (oldBtn) oldBtn.remove();
    var host = h('section', { className:'row vcx-row', 'aria-label':'Trailers and extras' }, [A.pendingContent({ text:'Looking for trailers and extras…', className:'vcx-loading' })]);
    var anchor = opts.hero && opts.hero.parentNode === opts.root ? opts.hero.nextSibling : null;
    opts.root.insertBefore(host, anchor);
    function load(){
      api(endpoint(opts, 'extras')).then(function(view){
        if (!host.isConnected) return;
        if (!view || !view.ok) throw new Error(err(view, 'Could not load extras.'));
        var all = Array.isArray(view.extras) ? view.extras : [];
        var list = opts.kind === 'show' ? all.filter(function(x){ return x.season == null || opts.season == null || Number(x.season) === Number(opts.season); }) : all;
        if (view.local_trailer || view.online) addTrailerButton(opts, view);
        if (!list.length) { host.remove(); }
        else row(host, list, opts.kind === 'show' && opts.season != null && list.some(function(x){ return x.season != null; }) ? 'Extras · ' + (Number(opts.season) === 0 ? 'Specials' : 'Season ' + opts.season) : 'Extras', opts);
        heroBackground(opts, view);
      }).catch(function(e){
        if (!host.isConnected) return;
        host.replaceChildren(h('div', { className:'vcx-error' }, [h('span', { className:'muted', text:(e && e.message) || 'Could not load extras.' }), h('button', { type:'button', className:'btn ghost sm', text:'Retry', onClick:function(){ host.replaceChildren(A.pendingContent({ text:'Looking for trailers and extras…', className:'vcx-loading' })); load(); } })]));
      });
    }
    load();
  }

  function addTrailerButton(opts, view){
    if (!opts.actions || !opts.actions.isConnected || opts.actions.querySelector('[data-vcx-trailer]')) return;
    var btn = h('button', { type:'button', className:'btn primary vcx-trailer-btn', 'data-vcx-trailer':'1', text:'▶ Play trailer' });
    btn.onclick = function(){
      if (view.local_trailer) playExtra(view.local_trailer, opts);
      else trailerModal({ kind:opts.kind, id:opts.id, title:opts.title, button:btn });
    };
    var back = opts.actions.firstChild;
    opts.actions.insertBefore(btn, back ? back.nextSibling : null);
  }

  /* ---------------------------------------------------------------- admin settings */
  function adminPanel(host){
    host.replaceChildren(A.pendingContent({ text:'Loading trailer settings…' }));
    api('/admin/extras/settings').then(function(j){
      if (!j || !j.ok) throw new Error(err(j, 'Could not load trailer settings.'));
      var st = j.settings || {}, counts = j.counts || {};
      var onlineBox = h('input', { type:'checkbox', checked:st.online !== false });
      var heroBox = h('input', { type:'checkbox', checked:!!st.hero_autoplay });
      var status = h('p', { className:'muted', role:'status' });
      var save = h('button', { type:'button', className:'btn primary', text:'Save' });
      var probe = h('button', { type:'button', className:'btn', text:'Probe extras now' });
      save.onclick = function(){
        save.disabled = true; status.textContent = 'Saving…';
        api('/admin/extras/settings', { method:'POST', body:{ online:onlineBox.checked, hero_autoplay:heroBox.checked } }).then(function(r){
          if (!r || !r.ok) throw new Error(err(r, 'Could not save.'));
          status.textContent = 'Saved.'; A.toast('Trailer settings saved', { kind:'ok' });
        }).catch(function(e){ status.textContent = (e && e.message) || 'Could not save.'; }).then(function(){ save.disabled = false; });
      };
      probe.onclick = function(){
        probe.disabled = true;
        api('/admin/extras/settings', { method:'POST', body:{ action:'probe' } }).then(function(r){
          if (!r || !r.ok) throw new Error(err(r, 'Could not start the task.'));
          A.toast('Probing extras in the Task centre', { kind:'ok' });
        }).catch(function(e){ A.toast((e && e.message) || 'Could not start the task.', { kind:'bad' }); }).then(function(){ probe.disabled = false; });
      };
      var notes = [];
      if (!j.tmdb) notes.push('Online trailers need the TMDB key in Library artwork settings. Without it, only local trailers play.');
      else if (!j.remote) notes.push('Online metadata lookups are switched off, so online trailers are not fetched.');
      host.replaceChildren.apply(host, [
        h('p', { className:'muted', text:'Local extras are found during a library scan: folders called Trailers, Featurettes, Behind The Scenes, Deleted Scenes, Interviews, Scenes, Shorts, Extras or Other inside a film or show folder, or files ending in -trailer, -featurette, -behindthescenes, -deleted, -interview, -scene, -short or -other next to the film.' }),
        h('p', { text:(Number(counts.extras) || 0) + ' extras indexed' + (Number(counts.waiting) ? ' · ' + counts.waiting + ' waiting for a running time and thumbnail' : '') + '.' }),
        h('label', { className:'check-label' }, [onlineBox, ' Online trailers from TMDB when a title has no local trailer (played from youtube-nocookie.com)']),
        h('label', { className:'check-label' }, [heroBox, ' Play a muted trailer behind the title header (off by default; never with reduced motion)']),
        notes.length ? h('p', { className:'muted', text:notes.join(' ') }) : null,
        h('div', { className:'row-actions' }, [save, probe]), status
      ].filter(Boolean));
    }).catch(function(e){
      host.replaceChildren(h('div', { className:'vcx-error' }, [h('span', { className:'muted', text:(e && e.message) || 'Could not load trailer settings.' }), h('button', { type:'button', className:'btn ghost sm', text:'Retry', onClick:function(){ adminPanel(host); } })]));
    });
  }

  root.VaultcastExtras = {
    init:function(app){ A = app; h = app.h; api = app.api; },
    mount:mount, adminPanel:adminPanel, stop:stopHeroBackgrounds
  };
})(window);
