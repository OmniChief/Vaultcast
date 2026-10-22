/* Vaultcast media requests: search for films and shows the library doesn't have, ask for them, follow
   their progress, and (for managers) approve, decline or send them to downloads. Loaded on demand.
   All provider and user text is set with textContent (A.h 'text'); nothing here uses innerHTML. */
(function(root){
  'use strict';
  var A = null, h, api;
  var hostEl = null, owner = null, tab = 'discover', status = null;
  var disc = { type:'movie', q:'', results:null, busy:false, error:'', notice:'', seq:0, timer:null };
  var mine = { rows:null, busy:false, error:'' };
  var mgr = { filter:'pending', q:'', rows:null, counts:{}, busy:false, error:'', page:1, more:false, selected:{}, acquire:true, acq:null, admin:false, seq:0, timer:null };

  var STATUS_TONE = { pending:'pending', approved:'approved', downloading:'downloading', available:'available', declined:'declined' };

  function uid(){ return A.state.user && A.state.user.role !== 'guest' ? A.state.user.id : null; }
  function str(v){ return v == null ? '' : String(v); }
  function plural(n, one, many){ return n + ' ' + (n === 1 ? one : (many || one + 's')); }
  function ago(ts){
    ts = Number(ts) || 0; if (!ts) return '';
    var s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
    if (s < 60) return 'just now'; if (s < 3600) return Math.floor(s / 60) + ' min ago'; if (s < 86400) return Math.floor(s / 3600) + ' h ago';
    if (s < 7 * 86400) return Math.floor(s / 86400) + ' d ago';
    return new Date(ts * 1000).toLocaleDateString(undefined, { day:'numeric', month:'short', year: s > 300 * 86400 ? 'numeric' : undefined });
  }
  function dateLabel(ts){ return ts ? new Date(ts * 1000).toLocaleDateString(undefined, { weekday:'short', day:'numeric', month:'short' }) : ''; }
  function err(j, fallback){ return (j && j.error) ? String(j.error) : fallback; }
  function setPath(path){ try { history.pushState({}, '', (A.base || '/') + path); } catch (e) {} }
  function initials(title){ var w = str(title).replace(/[\s\-:.,!?'"()]+/g, ' ').trim().split(' '); return ((w[0] || '?').charAt(0) + (w[1] ? w[1].charAt(0) : '')).toUpperCase(); }

  function posterEl(row, cls){
    var box = h('div', { className:'mreq-poster' + (cls ? ' ' + cls : '') }, [h('span', { className:'mreq-poster-fallback', 'aria-hidden':'true', text:initials(row.title) })]);
    if (row.poster && /^https:\/\//.test(row.poster)) {
      var img = h('img', { alt:'', loading:'lazy', decoding:'async', referrerpolicy:'no-referrer' });
      img.onload = function(){ box.classList.add('has-image'); };
      img.onerror = function(){ img.remove(); };
      img.src = row.poster; box.appendChild(img);
    }
    return box;
  }
  function pill(st, label){ return h('span', { className:'mreq-pill mreq-pill-' + (STATUS_TONE[st] || 'pending'), text:label || st }); }
  function openLibrary(lib){
    if (!lib) return;
    if (lib.type === 'movie' && lib.item_id) A.openMovie(lib.item_id);
    else if (lib.type === 'show' && lib.show_key) A.openShow(lib.show_key);
  }

  /* ---------------------------------------------------------------- status */
  function loadStatus(){
    return api('/requests/status').then(function(j){
      if (!j || !j.ok) throw new Error(err(j, 'Requests could not load'));
      status = j; return j;
    });
  }
  function limitText(){
    var l = status && status.limit; if (!l) return '';
    var left = Math.max(0, l.count - l.used);
    var text = left + ' of ' + l.count + ' requests left (every ' + plural(l.days, 'day') + ')';
    if (!left && l.resets_at) text += ' · more from ' + dateLabel(l.resets_at);
    return text;
  }

  /* ---------------------------------------------------------------- shell */
  function tabs(){
    var list = [['discover', 'Discover'], ['mine', 'My requests']];
    if (status && status.manage) list.push(['manage', 'Manage']);
    return h('div', { className:'mreq-tabs', role:'tablist', 'aria-label':'Requests' }, list.map(function(t){
      var b = h('button', { className:'mreq-tab' + (tab === t[0] ? ' active' : ''), type:'button', role:'tab', 'aria-selected':tab === t[0] ? 'true' : 'false', onClick:function(){ go(t[0]); } }, [h('span', { text:t[1] })]);
      if (t[0] === 'manage' && status && status.pending) b.appendChild(h('span', { className:'mreq-count', text:String(status.pending), 'aria-label':status.pending + ' pending' }));
      return b;
    }));
  }
  function go(t){
    if (t === tab) return;
    tab = t; A.state.requestsTab = t;
    setPath(t === 'manage' ? 'requests/manage' : (t === 'mine' ? 'requests/mine' : 'requests'));
    paint(); loadTab();
  }
  function paint(){
    if (!hostEl || !hostEl.isConnected) return;
    var head = h('header', { className:'mreq-hero' }, [
      h('div', { className:'mreq-hero-copy' }, [
        h('div', { className:'eyebrow', text:'Library' }),
        h('h1', { text:'Requests' }),
        h('p', { className:'muted', text:tab === 'manage' ? 'Approve what people asked for, send it to downloads, or let them know why not.' : 'Can’t find something? Ask for it here and we’ll let you know when it’s ready to watch.' })
      ]),
      tabs()
    ]);
    var body = tab === 'manage' ? manageView() : (tab === 'mine' ? mineView() : discoverView());
    hostEl.replaceChildren(h('section', { className:'mreq-page' }, [head, body]));
  }
  function loadTab(){
    if (tab === 'mine') loadMine();
    else if (tab === 'manage') loadManage();
  }

  /* ---------------------------------------------------------------- discover */
  function discoverView(){
    if (status && !status.create) {
      return h('div', { className:'mreq-empty' }, [h('h3', { text:'Requests aren’t available on your account' }), h('p', { className:'muted', text:status.enabled ? 'Ask an administrator if you’d like to request films and shows.' : 'Requests are turned off on this server.' })]);
    }
    var input = h('input', { type:'search', className:'mreq-search', placeholder:disc.type === 'tv' ? 'Search for a show…' : 'Search for a film…', 'aria-label':'Search for a title to request', value:disc.q, autocomplete:'off', enterkeyhint:'search' });
    input.addEventListener('input', function(){ disc.q = input.value; clearTimeout(disc.timer); disc.timer = setTimeout(function(){ runSearch(); }, 450); });
    input.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); clearTimeout(disc.timer); runSearch(); } });
    var seg = h('div', { className:'mreq-seg', role:'radiogroup', 'aria-label':'Kind of title' }, [['movie', 'Films'], ['tv', 'Shows']].map(function(o){
      return h('button', { type:'button', role:'radio', 'aria-checked':disc.type === o[0] ? 'true' : 'false', className:'mreq-seg-btn' + (disc.type === o[0] ? ' active' : ''), text:o[1], onClick:function(){ if (disc.type === o[0]) return; disc.type = o[0]; disc.results = null; paint(); runSearch(); var el = hostEl.querySelector('.mreq-search'); if (el) el.focus(); } });
    }));
    var bar = h('div', { className:'mreq-searchbar' }, [seg, h('div', { className:'mreq-search-wrap' }, [input])]);
    var meta = [];
    var lt = limitText(); if (lt) meta.push(h('span', { className:'mreq-chip', text:lt }));
    if (status && status.providers) meta.push(h('span', { className:'muted', text:'Results from ' + (disc.type === 'tv' ? status.providers.tv : status.providers.movie) }));
    if (status && status.restricted) meta.push(h('span', { className:'mreq-chip mreq-chip-kid', text:'Only titles within this profile’s age limit are shown. A grown-up approves every request.' }));
    var out = [bar, meta.length ? h('div', { className:'mreq-meta' }, meta) : null];
    if (disc.notice) out.push(h('div', { className:'mreq-empty' }, [h('h3', { text:'Not available here' }), h('p', { className:'muted', text:disc.notice })]));
    else if (disc.error) out.push(h('div', { className:'mreq-empty' }, [h('h3', { text:'Search didn’t work' }), h('p', { className:'muted', text:disc.error }), h('button', { className:'btn', type:'button', text:'Try again', onClick:function(){ runSearch(); } })]));
    else if (disc.busy && !disc.results) out.push(skeleton());
    else if (!disc.results) out.push(h('div', { className:'mreq-empty mreq-empty-soft' }, [h('h3', { text:'What would you like to watch?' }), h('p', { className:'muted', text:'Type a title above. If it’s already in the library you can play it straight away.' })]));
    else if (!disc.results.length) out.push(h('div', { className:'mreq-empty' }, [h('h3', { text:'No matches' }), h('p', { className:'muted', text:status && status.restricted ? 'Nothing matched within this profile’s age limit. Try a different title.' : 'Try a different spelling, or add the year, like “Heat 1995”.' })]));
    else {
      var grid = h('div', { className:'mreq-grid' + (disc.busy ? ' is-busy' : ''), 'aria-busy':disc.busy ? 'true' : 'false' }, disc.results.map(resultCard));
      out.push(grid);
    }
    return h('div', { className:'mreq-discover' }, out);
  }
  function skeleton(){ var g = h('div', { className:'mreq-grid', 'aria-hidden':'true' }); for (var i = 0; i < 6; i++) g.appendChild(h('div', { className:'mreq-card mreq-skeleton' }, [h('div', { className:'mreq-poster' }), h('div', { className:'mreq-card-body' }, [h('span'), h('span'), h('span')])])); return h('div', { role:'status', 'aria-label':'Searching' }, [g]); }
  function runSearch(){
    var q = str(disc.q).trim(), me = uid(), seq = ++disc.seq;
    disc.error = ''; disc.notice = '';
    if (q.length < 2) { disc.results = null; disc.busy = false; paint(); return; }
    disc.busy = true; paint();
    api('/requests/search?type=' + encodeURIComponent(disc.type) + '&q=' + encodeURIComponent(q)).then(function(j){
      if (seq !== disc.seq || me !== uid()) return;
      if (!j || !j.ok) throw new Error(err(j, 'Search failed. Try again in a moment.'));
      disc.results = j.results || []; disc.notice = j.notice || '';
    }).catch(function(e){ if (seq === disc.seq) { disc.error = e.message || 'Search failed.'; disc.results = null; } })
      .then(function(){ if (seq === disc.seq) { disc.busy = false; var had = document.activeElement && document.activeElement.classList.contains('mreq-search'); paint(); if (had) { var el = hostEl && hostEl.querySelector('.mreq-search'); if (el) { el.focus(); try { el.setSelectionRange(el.value.length, el.value.length); } catch (e) {} } } } });
  }
  function bestRequest(r){
    var list = r.requests || [], whole = null, mineReq = null;
    list.forEach(function(x){ if (x.season < 0) whole = x; if (x.mine) mineReq = mineReq || x; });
    return whole || mineReq || list[0] || null;
  }
  function resultCard(r){
    var isTv = r.media_type === 'tv', req = bestRequest(r);
    var badges = [], actions = [];
    var seasonsInLib = (r.library_seasons || []).length;
    if (r.in_library) {
      badges.push(h('span', { className:'mreq-pill mreq-pill-available', text:isTv ? 'In your library · ' + plural(seasonsInLib, 'season') : 'In your library' }));
      actions.push(h('button', { className:'btn primary', type:'button', text:isTv ? 'Open show' : 'Watch now', onClick:function(){ openLibrary(r.library); } }));
      if (isTv) actions.push(h('button', { className:'btn ghost', type:'button', text:'Request more seasons', onClick:function(){ seasonDialog(r); } }));
    } else if (req && req.status !== 'declined') {
      badges.push(pill(req.status, (req.season > 0 ? 'Season ' + req.season + ' · ' : '') + req.status_label));
      if (req.count > (req.mine ? 1 : 0)) badges.push(h('span', { className:'mreq-also', text:req.mine ? 'You and ' + plural(req.count - 1, 'other') : 'Requested by ' + plural(req.count, 'person', 'people') }));
      if (isTv) actions.push(h('button', { className:'btn ghost', type:'button', text:'Choose seasons', onClick:function(){ seasonDialog(r); } }));
      else if (!req.mine) actions.push(h('button', { className:'btn primary', type:'button', text:req.status === 'pending' ? 'Me too' : 'Tell me when it’s ready', title:'Adds you to this request', onClick:function(e){ submit(r, null, '', e.currentTarget); } }));
      else actions.push(h('button', { className:'btn ghost', type:'button', text:'View in My requests', onClick:function(){ go('mine'); } }));
    } else {
      if (req && req.status === 'declined') badges.push(pill('declined', 'Declined before'));
      actions.push(h('button', { className:'btn primary', type:'button', text:isTv ? 'Request…' : 'Request', onClick:function(e){ if (isTv) seasonDialog(r); else movieDialog(r, e.currentTarget); } }));
    }
    var sub = [r.year ? String(r.year) : '', isTv && r.network ? r.network : '', isTv && r.show_status ? r.show_status : ''].filter(Boolean).join(' · ');
    return h('article', { className:'mreq-card' }, [
      posterEl(r),
      h('div', { className:'mreq-card-body' }, [
        h('h3', { className:'mreq-title', text:r.title }),
        h('p', { className:'mreq-sub muted', text:sub || (isTv ? 'Show' : 'Film') }),
        badges.length ? h('div', { className:'mreq-badges' }, badges) : null,
        r.overview ? h('p', { className:'mreq-overview', text:r.overview }) : h('p', { className:'mreq-overview muted', text:'No description yet.' }),
        h('div', { className:'mreq-actions' }, actions)
      ])
    ]);
  }
  function noteField(){ return h('textarea', { className:'mreq-note', maxlength:'300', rows:'2', placeholder:'Anything to add? (optional, e.g. “the 1995 one”)', 'aria-label':'Note for the administrator' }); }
  function movieDialog(r, btn){
    var note = noteField(), send = h('button', { className:'btn primary', type:'button', text:'Send request' });
    var body = h('div', { className:'stack mreq-dialog' }, [h('div', { className:'mreq-dialog-head' }, [posterEl(r, 'small'), h('div', {}, [h('strong', { text:r.title }), h('p', { className:'muted', text:(r.year ? r.year + ' · ' : '') + 'Film' })])]), note, limitText() ? h('p', { className:'muted', text:limitText() }) : null, send]);
    var d = A.dialog('Request a film', body);
    send.onclick = function(){ submit(r, null, note.value, send).then(function(ok){ if (ok) d.close(); }); };
  }
  function seasonDialog(r){
    var body = h('div', { className:'stack mreq-dialog' }, [h('p', { className:'muted', role:'status', text:'Loading seasons…' })]);
    var d = A.dialog('Request ' + r.title, body);
    api('/requests/seasons?source=' + encodeURIComponent(r.source) + '&id=' + encodeURIComponent(r.ext_id)).then(function(j){
      if (!j || !j.ok) throw new Error(err(j, 'Seasons could not load'));
      var seasons = j.seasons || [], boxes = [], inLib = seasons.filter(function(s){ return s.in_library; }).length;
      var wholeOpen = !!j.whole && j.whole.status !== 'declined', libAny = !!(j.show && j.show.in_library);
      var mode = libAny || !seasons.length ? (libAny ? 'some' : 'all') : 'all';
      var allRadio = h('input', { type:'radio', name:'mreq-scope', value:'all', checked:mode === 'all', disabled:libAny });
      var someRadio = h('input', { type:'radio', name:'mreq-scope', value:'some', checked:mode === 'some' });
      var list = h('div', { className:'mreq-season-list' });
      seasons.forEach(function(s){
        var taken = s.in_library || (s.request && s.request.status === 'available');
        var cb = h('input', { type:'checkbox', value:String(s.number), disabled:taken });
        cb._taken = !!taken; boxes.push(cb);
        var info = [s.episodes ? plural(s.episodes, 'episode') : '', s.premiere ? (s.aired ? 'from ' : 'starts ') + new Date(s.premiere + 'T12:00:00').toLocaleDateString(undefined, { month:'short', year:'numeric' }) : 'date to be announced'].filter(Boolean).join(' · ');
        list.appendChild(h('label', { className:'mreq-season' + (taken ? ' is-taken' : '') }, [cb, h('span', { className:'mreq-season-name', text:'Season ' + s.number }), h('small', { className:'muted', text:info }),
          s.in_library ? pill('available', 'In library') : (s.request && s.request.status !== 'declined' ? pill(s.request.status, s.request.status_label) : null)]));
      });
      function sync(){ var some = someRadio.checked; list.classList.toggle('is-disabled', !some); boxes.forEach(function(cb){ cb.disabled = cb._taken || !some; }); }
      allRadio.onchange = sync; someRadio.onchange = sync;
      var note = noteField(), send = h('button', { className:'btn primary', type:'button', text:'Send request' });
      var kids = [h('div', { className:'mreq-dialog-head' }, [posterEl(j.show || r, 'small'), h('div', {}, [h('strong', { text:r.title }), h('p', { className:'muted', text:[r.year ? String(r.year) : '', inLib ? plural(inLib, 'season') + ' already in the library' : ''].filter(Boolean).join(' · ') || 'Show' })])])];
      if (wholeOpen) kids.push(h('p', { className:'mreq-hint', text:'Someone has already asked for the whole show (' + str(j.whole.status_label).toLowerCase() + '). Choosing it adds you to that request.' }));
      kids.push(h('div', { className:'mreq-scope' }, [h('label', { className:'check-label' }, [allRadio, h('span', { text:'Whole show' + (seasons.length ? ' (' + plural(seasons.length, 'season') + ')' : '') })]), h('label', { className:'check-label' }, [someRadio, h('span', { text:'Choose seasons' })])]));
      kids.push(seasons.length ? list : h('p', { className:'muted', text:'TVmaze doesn’t list seasons for this show yet. You can still ask for the whole show.' }));
      kids.push(note); if (limitText()) kids.push(h('p', { className:'muted', text:limitText() })); kids.push(send);
      body.replaceChildren.apply(body, kids);
      sync();
      if (!seasons.length) { someRadio.disabled = true; if (!libAny) allRadio.checked = true; }
      send.onclick = function(){
        var picked = null;
        if (someRadio.checked) { picked = boxes.filter(function(cb){ return cb.checked && !cb.disabled; }).map(function(cb){ return Number(cb.value); }); if (!picked.length) return A.toast('Choose at least one season', { kind:'bad' }); }
        else if (allRadio.disabled) return A.toast('Choose the seasons you’d like', { kind:'bad' });
        submit(r, picked, note.value, send).then(function(ok){ if (ok) d.close(); });
      };
    }).catch(function(e){ body.replaceChildren(h('p', { className:'muted', text:e.message || 'Seasons could not load.' })); });
  }
  function submit(r, seasons, note, btn){
    var me = uid(), label = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Sending…'; }
    return api('/requests', { method:'POST', body:{ media_type:r.media_type, source:r.source, ext_id:r.ext_id, seasons:seasons && seasons.length ? seasons : 'all', note:str(note).trim() } }).then(function(j){
      if (me !== uid()) return false;
      if (!j || !j.ok) throw new Error(err(j, 'The request couldn’t be sent'));
      if (j.status) status = j.status;
      var n = (j.requests || []).length;
      if (!n && (j.already || []).length) A.toast((j.already.join(', ')) + ' is already in the library', { kind:'ok' });
      else A.toast(j.auto_approved ? 'Approved straight away. We’ll let you know when it’s ready.' : 'Request sent. We’ll let you know when there’s news.', { kind:'ok', ms:3600 });
      runSearch(); mine.rows = null;
      return true;
    }).catch(function(e){ A.toast(e.message || 'The request couldn’t be sent', { kind:'bad', ms:4200 }); return false; })
      .then(function(ok){ if (btn && btn.isConnected) { btn.disabled = false; btn.textContent = label; } return ok; });
  }

  /* ---------------------------------------------------------------- my requests */
  function loadMine(){
    var me = uid(); mine.busy = true; mine.error = ''; paint();
    api('/requests/mine').then(function(j){
      if (me !== uid()) return;
      if (!j || !j.ok) throw new Error(err(j, 'Your requests could not load'));
      mine.rows = j.requests || []; if (j.status) status = j.status;
    }).catch(function(e){ mine.error = e.message || 'Your requests could not load'; }).then(function(){ mine.busy = false; if (tab === 'mine') paint(); });
  }
  function progressEl(row){
    if (row.status !== 'downloading') return null;
    var pct = Math.max(0, Math.min(100, Number(row.progress) || 0));
    var bar = h('div', { className:'mreq-bar' + (pct <= 0 ? ' indeterminate' : ''), role:'progressbar', 'aria-valuemin':'0', 'aria-valuemax':'100', 'aria-valuenow':String(Math.round(pct)), 'aria-label':'Download progress' }, [h('span', { style:pct > 0 ? 'width:' + pct + '%' : '' })]);
    return h('div', { className:'mreq-progress' }, [bar, h('small', { className:'muted', text:(pct > 0 ? Math.round(pct) + '% · ' : '') + (row.note || 'Downloading') })]);
  }
  function statusLine(row){
    if (row.status === 'declined') return row.reason ? 'Declined: ' + row.reason : 'Declined. No reason was given.';
    if (row.status === 'available') return 'Ready to watch in the library.';
    if (row.status === 'pending') return 'Waiting for an administrator to have a look.';
    if (row.status === 'approved') return row.note || 'Approved. It’ll be added soon.';
    return '';
  }
  function requestRow(row, manage){
    var meta = [row.scope_label, row.year ? String(row.year) : '', row.rating ? 'Rated ' + row.rating : ''].filter(Boolean).join(' · ');
    var when = manage ? 'Asked ' + ago(row.created_at) : 'You asked ' + ago(row.requested_at);
    var side = [pill(row.status, row.status_label)];
    var also = manage ? '' : (row.also ? 'Also requested by ' + plural(row.also, 'other person', 'other people') : '');
    var actions = [];
    if (!manage) {
      if (row.status === 'available' && row.library) actions.push(h('button', { className:'btn primary', type:'button', text:row.media_type === 'tv' ? 'Open show' : 'Watch now', onClick:function(){ openLibrary(row.library); } }));
      if (row.can_cancel) actions.push(h('button', { className:'btn ghost', type:'button', text:'Cancel request', onClick:function(e){ cancel(row, e.currentTarget); } }));
    }
    var main = [h('div', { className:'mreq-row-top' }, [h('h3', { className:'mreq-title', text:row.title }), h('div', { className:'mreq-row-pills' }, side)]),
      h('p', { className:'mreq-sub muted', text:meta + (meta ? ' · ' : '') + when }),
      statusLine(row) && row.status !== 'downloading' ? h('p', { className:'mreq-status-line mreq-status-' + row.status, text:statusLine(row) }) : null,
      progressEl(row),
      also ? h('p', { className:'mreq-also', text:also }) : null];
    if (manage) main = main.concat(managerBits(row));
    if (actions.length) main.push(h('div', { className:'mreq-actions' }, actions));
    var kids = [];
    if (manage) {
      var cb = h('input', { type:'checkbox', className:'mreq-check', 'aria-label':'Select ' + row.title, checked:!!mgr.selected[row.id] });
      cb.onchange = function(){ if (cb.checked) mgr.selected[row.id] = true; else delete mgr.selected[row.id]; paintBulk(); el.classList.toggle('is-selected', cb.checked); };
      kids.push(cb);
    }
    kids.push(posterEl(row, 'small'));
    kids.push(h('div', { className:'mreq-row-main' }, main));
    var el = h('article', { className:'mreq-row' + (manage && mgr.selected[row.id] ? ' is-selected' : '') }, kids);
    return el;
  }
  function mineView(){
    if (mine.busy && !mine.rows) return h('p', { className:'pending-content muted', role:'status', text:'Loading your requests…' });
    if (mine.error) return h('div', { className:'mreq-empty' }, [h('h3', { text:'Couldn’t load your requests' }), h('p', { className:'muted', text:mine.error }), h('button', { className:'btn', type:'button', text:'Try again', onClick:loadMine })]);
    var rows = mine.rows || [];
    if (!rows.length) return h('div', { className:'mreq-empty' }, [h('h3', { text:'No requests yet' }), h('p', { className:'muted', text:'Search for a film or show you can’t find, and ask for it.' }), status && status.create ? h('button', { className:'btn primary', type:'button', text:'Find something', onClick:function(){ go('discover'); } }) : null]);
    var open = rows.filter(function(r){ return r.status !== 'available' && r.status !== 'declined'; }), done = rows.filter(function(r){ return r.status === 'available' || r.status === 'declined'; });
    var out = [];
    var lt = limitText(); if (lt) out.push(h('div', { className:'mreq-meta' }, [h('span', { className:'mreq-chip', text:lt })]));
    if (open.length) out.push(h('h2', { className:'mreq-h2', text:'In progress' }), h('div', { className:'mreq-list' }, open.map(function(r){ return requestRow(r, false); })));
    if (done.length) out.push(h('h2', { className:'mreq-h2', text:'Finished' }), h('div', { className:'mreq-list' }, done.map(function(r){ return requestRow(r, false); })));
    return h('div', { className:'mreq-mine' }, out);
  }
  function cancel(row, btn){
    A.confirmDialog('Cancel your request for “' + row.title + '”' + (row.media_type === 'tv' ? ' (' + row.scope_label + ')' : '') + '?', { okText:'Cancel request', danger:true }).then(function(ok){
      if (!ok) return;
      if (btn) btn.disabled = true;
      api('/requests/' + encodeURIComponent(row.id) + '/cancel', { method:'POST', body:{} }).then(function(j){
        if (!j || !j.ok) throw new Error(err(j, 'Couldn’t cancel the request'));
        if (j.status) status = j.status;
        A.toast('Request cancelled'); loadMine();
      }).catch(function(e){ if (btn) btn.disabled = false; A.toast(e.message || 'Couldn’t cancel the request', { kind:'bad' }); });
    });
  }

  /* ---------------------------------------------------------------- manage */
  var FILTERS = [['pending', 'Pending'], ['approved', 'Approved'], ['downloading', 'Downloading'], ['available', 'Available'], ['declined', 'Declined'], ['all', 'All']];
  function loadManage(keep){
    var me = uid(), seq = ++mgr.seq;
    mgr.busy = true; mgr.error = ''; if (!keep) { mgr.page = 1; mgr.selected = {}; }
    paint();
    api('/requests/manage?status=' + encodeURIComponent(mgr.filter) + '&q=' + encodeURIComponent(mgr.q) + '&page=' + mgr.page).then(function(j){
      if (seq !== mgr.seq || me !== uid()) return;
      if (!j || !j.ok) throw new Error(err(j, 'Requests could not load'));
      var rows = j.requests || [];
      mgr.rows = keep && mgr.page > 1 && mgr.rows ? mgr.rows.concat(rows) : rows;
      mgr.counts = j.counts || {}; mgr.more = !!j.has_more; mgr.acq = j.acquisition || null; mgr.admin = !!j.admin;
      mgr.acquire = j.acquire_default !== false;
      if (status) status.pending = Number(mgr.counts.pending) || 0;
    }).catch(function(e){ if (seq === mgr.seq) mgr.error = e.message || 'Requests could not load'; })
      .then(function(){ if (seq === mgr.seq) { mgr.busy = false; if (tab === 'manage') paint(); } });
  }
  function selectedIds(){ return Object.keys(mgr.selected).map(Number).filter(function(n){ return n > 0; }); }
  function paintBulk(){
    var bar = hostEl && hostEl.querySelector('.mreq-bulk'); if (!bar) return;
    bar.replaceWith(bulkBar());
  }
  function bulkBar(){
    var ids = selectedIds(), rows = mgr.rows || [];
    var all = h('input', { type:'checkbox', 'aria-label':'Select all shown', checked:rows.length > 0 && ids.length === rows.length });
    all.indeterminate = ids.length > 0 && ids.length < rows.length;
    all.onchange = function(){ mgr.selected = {}; if (all.checked) rows.forEach(function(r){ mgr.selected[r.id] = true; }); paint(); };
    var kids = [h('label', { className:'check-label' }, [all, h('span', { text:ids.length ? plural(ids.length, 'selected', 'selected') : 'Select all' })])];
    if (ids.length) {
      kids.push(h('button', { className:'btn primary', type:'button', text:'Approve', onClick:function(){ approveDialog(ids); } }));
      kids.push(h('button', { className:'btn ghost', type:'button', text:'Decline', onClick:function(){ declineDialog(ids); } }));
      kids.push(h('button', { className:'btn ghost danger-text', type:'button', text:'Delete', onClick:function(){ removeRequests(ids); } }));
    }
    return h('div', { className:'mreq-bulk' + (ids.length ? ' has-selection' : '') }, kids);
  }
  function managerBits(row){
    var out = [];
    var people = row.people || [];
    if (people.length) {
      out.push(h('ul', { className:'mreq-people' }, people.slice(0, 6).map(function(p){
        return h('li', {}, [h('strong', { text:p.name || 'Someone' }), p.kid ? h('span', { className:'mreq-chip mreq-chip-kid', text:'Kids profile' }) : null, h('span', { className:'muted', text:' · ' + ago(p.at) }), p.note ? h('q', { className:'mreq-quote', text:p.note }) : null]);
      }).concat(people.length > 6 ? [h('li', { className:'muted', text:'and ' + plural(people.length - 6, 'more person', 'more people') })] : [])));
    }
    if (row.decided_by && row.status !== 'pending') out.push(h('p', { className:'muted mreq-small', text:(row.status === 'declined' ? 'Declined' : 'Approved') + ' by ' + row.decided_by + (row.decided_at ? ' · ' + ago(row.decided_at) : '') }));
    if (row.overview) out.push(h('details', { className:'mreq-more' }, [h('summary', { text:'About this title' }), h('p', { text:row.overview })]));
    var acts = [];
    var st = row.status;
    if (st === 'pending' || st === 'declined') acts.push(h('button', { className:'btn primary', type:'button', text:'Approve', onClick:function(){ approveDialog([row.id]); } }));
    if (st === 'pending' || st === 'approved' || st === 'downloading') acts.push(h('button', { className:'btn ghost', type:'button', text:'Decline', onClick:function(){ declineDialog([row.id]); } }));
    if ((st === 'approved' || st === 'downloading') && mgr.acq && mgr.acq.ok) acts.push(h('button', { className:'btn ghost', type:'button', text:row.downloads ? 'Search again' : 'Send to downloads', onClick:function(e){ decide([row.id], 'retry', '', null, e.currentTarget); } }));
    if (st !== 'available') acts.push(h('button', { className:'btn ghost', type:'button', text:'Mark available', onClick:function(e){ decide([row.id], 'available', '', null, e.currentTarget); } }));
    if (st === 'available' && row.library) acts.push(h('button', { className:'btn ghost', type:'button', text:'Open', onClick:function(){ openLibrary(row.library); } }));
    if (st === 'declined' || st === 'approved') acts.push(h('button', { className:'btn ghost', type:'button', text:'Back to pending', onClick:function(e){ decide([row.id], 'reopen', '', null, e.currentTarget); } }));
    acts.push(h('button', { className:'btn ghost danger-text', type:'button', text:'Delete', onClick:function(){ removeRequests([row.id]); } }));
    out.push(h('div', { className:'mreq-actions' }, acts));
    return out;
  }
  function manageView(){
    if (status && !status.manage) return h('div', { className:'mreq-empty' }, [h('h3', { text:'Managing requests needs permission' }), h('p', { className:'muted', text:'Ask an administrator for the “Manage media requests” permission.' })]);
    var chips = h('div', { className:'mreq-filters', role:'tablist', 'aria-label':'Filter by status' }, FILTERS.map(function(f){
      var n = f[0] === 'all' ? Object.keys(mgr.counts).reduce(function(a, k){ return a + (Number(mgr.counts[k]) || 0); }, 0) : Number(mgr.counts[f[0]]) || 0;
      return h('button', { type:'button', role:'tab', 'aria-selected':mgr.filter === f[0] ? 'true' : 'false', className:'mreq-filter' + (mgr.filter === f[0] ? ' active' : ''), onClick:function(){ if (mgr.filter === f[0]) return; mgr.filter = f[0]; loadManage(); } }, [h('span', { text:f[1] }), h('span', { className:'mreq-filter-n', text:String(n) })]);
    }));
    var input = h('input', { type:'search', className:'mreq-search small', placeholder:'Filter by title', 'aria-label':'Filter requests by title', value:mgr.q });
    input.addEventListener('input', function(){ mgr.q = input.value; clearTimeout(mgr.timer); mgr.timer = setTimeout(function(){ loadManage(); var el = hostEl && hostEl.querySelector('.mreq-manage .mreq-search'); if (el) { el.focus(); try { el.setSelectionRange(el.value.length, el.value.length); } catch (e) {} } }, 400); });
    var tools = [input];
    if (mgr.admin) tools.push(h('button', { className:'btn ghost', type:'button', text:'Request settings', onClick:settingsDialog }));
    var out = [h('div', { className:'mreq-manage-top' }, [chips, h('div', { className:'mreq-manage-tools' }, tools)])];
    if (mgr.acq && !mgr.acq.ok) out.push(h('p', { className:'mreq-hint', text:'Downloads aren’t set up, so approving just marks a request as approved. ' + (mgr.acq.reason || '') }));
    if (mgr.busy && !mgr.rows) out.push(h('p', { className:'pending-content muted', role:'status', text:'Loading requests…' }));
    else if (mgr.error) out.push(h('div', { className:'mreq-empty' }, [h('h3', { text:'Couldn’t load requests' }), h('p', { className:'muted', text:mgr.error }), h('button', { className:'btn', type:'button', text:'Try again', onClick:function(){ loadManage(); } })]));
    else if (!(mgr.rows || []).length) out.push(h('div', { className:'mreq-empty' }, [h('h3', { text:mgr.q ? 'No requests match that title' : (mgr.filter === 'pending' ? 'All caught up' : 'Nothing here') }), h('p', { className:'muted', text:mgr.filter === 'pending' && !mgr.q ? 'New requests will show up here and in your inbox.' : 'Try another status.' })]));
    else {
      out.push(bulkBar());
      out.push(h('div', { className:'mreq-list' + (mgr.busy ? ' is-busy' : '') }, mgr.rows.map(function(r){ return requestRow(r, true); })));
      if (mgr.more) out.push(h('button', { className:'btn ghost mreq-more-btn', type:'button', text:mgr.busy ? 'Loading…' : 'Show more', disabled:mgr.busy, onClick:function(){ mgr.page++; loadManage(true); } }));
    }
    return h('div', { className:'mreq-manage' }, out);
  }
  function decide(ids, action, reason, acquire, btn){
    var me = uid();
    if (btn) btn.disabled = true;
    var body = { ids:ids, action:action, reason:reason || '' };
    if (acquire !== null && acquire !== undefined) body.acquire = !!acquire;
    return api('/requests/manage/decide', { method:'POST', body:body }).then(function(j){
      if (me !== uid()) return false;
      if (!j || !j.ok) throw new Error(err(j, 'That didn’t work'));
      var words = { approve:'Approved', decline:'Declined', retry:'Sent to downloads', available:'Marked available', reopen:'Moved back to pending', delete:'Deleted' };
      var msg = j.done ? (words[action] || 'Done') + (ids.length > 1 ? ' ' + plural(j.done, 'request') : '') : 'Nothing changed';
      if (j.skipped && j.done) msg += ' (' + j.skipped + ' skipped)';
      if (j.notes && j.notes.length) msg += '. ' + j.notes.slice(0, 2).join(' ');
      A.toast(msg, { kind:j.done ? 'ok' : undefined, ms:j.notes && j.notes.length ? 6000 : 2800 });
      mgr.selected = {}; loadManage();
      return true;
    }).catch(function(e){ A.toast(e.message || 'That didn’t work', { kind:'bad', ms:4200 }); return false; })
      .then(function(ok){ if (btn && btn.isConnected) btn.disabled = false; return ok; });
  }
  function approveDialog(ids){
    var ready = mgr.acq && mgr.acq.ok;
    var cb = h('input', { type:'checkbox', checked:ready && mgr.acquire, disabled:!ready });
    var go2 = h('button', { className:'btn primary', type:'button', text:ids.length > 1 ? 'Approve ' + ids.length : 'Approve' });
    var body = h('div', { className:'stack mreq-dialog' }, [
      h('label', { className:'check-label' }, [cb, h('span', { text:'Search for it and add it to the download queue' })]),
      h('p', { className:'muted', text:ready ? 'Uses your RSS feeds and torrent search, then Transmission if it’s set up. Requesters are told it’s approved.' : 'Downloads aren’t set up, so this only marks the request as approved. ' + ((mgr.acq && mgr.acq.reason) || '') }),
      go2]);
    var d = A.dialog(ids.length > 1 ? 'Approve ' + plural(ids.length, 'request') : 'Approve request', body);
    go2.onclick = function(){ decide(ids, 'approve', '', ready ? cb.checked : false, go2).then(function(ok){ if (ok) d.close(); }); };
  }
  function declineDialog(ids){
    var reason = h('textarea', { className:'mreq-note', maxlength:'400', rows:'3', placeholder:'Reason (optional). The requester will see this.', 'aria-label':'Reason for declining' });
    var presets = h('div', { className:'mreq-presets' }, ['Already available elsewhere', 'Not suitable for this server', 'Couldn’t find a good copy', 'Not out yet'].map(function(t){
      return h('button', { className:'mreq-chip mreq-chip-btn', type:'button', text:t, onClick:function(){ reason.value = t; reason.focus(); } });
    }));
    var go2 = h('button', { className:'btn danger', type:'button', text:ids.length > 1 ? 'Decline ' + ids.length : 'Decline' });
    var d = A.dialog(ids.length > 1 ? 'Decline ' + plural(ids.length, 'request') : 'Decline request', h('div', { className:'stack mreq-dialog' }, [reason, presets, go2]));
    go2.onclick = function(){ decide(ids, 'decline', reason.value, null, go2).then(function(ok){ if (ok) d.close(); }); };
  }
  function removeRequests(ids){
    A.confirmDialog('Delete ' + plural(ids.length, 'request') + '? Requesters won’t be told, and they can ask again.', { okText:'Delete', danger:true }).then(function(ok){ if (ok) decide(ids, 'delete', '', null, null); });
  }

  /* ---------------------------------------------------------------- settings (admins) */
  function settingsDialog(){
    var body = h('div', { className:'stack mreq-dialog mreq-settings' }, [h('p', { className:'muted', role:'status', text:'Loading settings…' })]);
    var d = A.dialog('Request settings', body);
    api('/requests/manage/settings').then(function(j){
      if (!j || !j.ok) throw new Error(err(j, 'Settings could not load'));
      var c = j.config || {};
      function check(on, label, hint){ var cb = h('input', { type:'checkbox', checked:!!on }); return { cb:cb, el:h('label', { className:'check-label mreq-set' }, [cb, h('span', {}, [h('span', { text:label }), hint ? h('small', { className:'muted', text:hint }) : null])]) }; }
      function num(v, min, max, label){ return h('input', { type:'number', min:String(min), max:String(max), value:String(v == null ? min : v), 'aria-label':label, className:'mreq-num' }); }
      var enabled = check(c.enabled !== false, 'Let people request titles', 'Adds Requests under Library for everyone who has the permission.');
      var userRole = check(c.user_role !== false, 'Standard users can request', 'Custom roles use the “Request films and shows” permission in Roles.');
      var direct = check(c.auto_direct !== false, 'Auto-approve people who can already grab downloads', 'Anyone with “Grab downloads directly” skips the queue when downloads are set up.');
      var acquire = check(c.acquire !== false, 'Send approved requests to downloads by default', j.acquisition && j.acquisition.ok ? 'You can still untick this when approving.' : 'Downloads aren’t set up yet, so approvals just mark requests approved.');
      var count = num(c.limit_count, 0, 500, 'Requests allowed'), days = num(c.limit_days || 7, 1, 90, 'Days');
      var roleBoxes = (j.roles || []).map(function(r){ var cb = h('input', { type:'checkbox', value:r.id, checked:(c.auto_roles || []).indexOf(r.id) >= 0 }); return { id:r.id, cb:cb, el:h('label', { className:'check-label' }, [cb, h('span', { text:r.name || r.id })]) }; });
      var limits = c.user_limits || {}, autoUsers = c.auto_users || [];
      var userRows = (j.users || []).map(function(u){
        var auto = h('input', { type:'checkbox', checked:autoUsers.indexOf(u.id) >= 0, 'aria-label':'Auto-approve ' + (u.name || 'user') });
        var lim = h('input', { type:'number', min:'0', max:'500', className:'mreq-num', placeholder:'Default', value:Object.prototype.hasOwnProperty.call(limits, u.id) ? String(limits[u.id]) : '', 'aria-label':'Request limit for ' + (u.name || 'user') });
        return { id:u.id, auto:auto, lim:lim, el:h('tr', {}, [h('td', { text:u.name || 'User' }), h('td', { className:'muted', text:u.role === 'user' ? 'User' : (u.role || '') }), h('td', {}, [auto]), h('td', {}, [lim])]) };
      });
      var save = h('button', { className:'btn primary', type:'button', text:'Save' });
      var kids = [enabled.el, userRole.el, direct.el, acquire.el,
        h('div', { className:'mreq-set-row' }, [h('span', { text:'Each person can make' }), count, h('span', { text:'requests every' }), days, h('span', { text:'days. 0 means no limit.' })]),
        h('h4', { text:'Auto-approve these roles' }), roleBoxes.length ? h('div', { className:'mreq-role-grid' }, roleBoxes.map(function(r){ return r.el; })) : h('p', { className:'muted', text:'No roles yet.' }),
        h('p', { className:'muted mreq-small', text:'Kids profiles always need approval, whatever their role.' }),
        h('h4', { text:'People' })];
      if (userRows.length) kids.push(h('div', { className:'mreq-table-wrap' }, [h('table', { className:'mreq-table' }, [h('thead', {}, [h('tr', {}, [h('th', { text:'Name' }), h('th', { text:'Role' }), h('th', { text:'Auto-approve' }), h('th', { text:'Limit' })])]), h('tbody', {}, userRows.map(function(u){ return u.el; }))])]));
      else kids.push(h('p', { className:'muted', text:'No other accounts yet.' }));
      kids.push(save);
      body.replaceChildren.apply(body, kids);
      save.onclick = function(){
        var lim = {};
        userRows.forEach(function(u){ var v = String(u.lim.value).trim(); if (v !== '' && isFinite(Number(v))) lim[u.id] = Math.max(0, Math.min(500, Math.round(Number(v)))); });
        var payload = { enabled:enabled.cb.checked, user_role:userRole.cb.checked, auto_direct:direct.cb.checked, acquire:acquire.cb.checked,
          limit_count:Math.max(0, Math.round(Number(count.value) || 0)), limit_days:Math.max(1, Math.round(Number(days.value) || 7)),
          auto_roles:roleBoxes.filter(function(r){ return r.cb.checked; }).map(function(r){ return r.id; }),
          auto_users:userRows.filter(function(u){ return u.auto.checked; }).map(function(u){ return u.id; }), user_limits:lim };
        save.disabled = true; save.textContent = 'Saving…';
        api('/requests/manage/settings', { method:'POST', body:payload }).then(function(r){
          if (!r || !r.ok) throw new Error(err(r, 'Settings could not be saved'));
          A.toast('Request settings saved', { kind:'ok' }); d.close();
          return loadStatus().then(function(){ if (A.state.user) A.state.user.requests = { create:status.create, manage:status.manage, enabled:status.enabled }; paint(); loadManage(); });
        }).catch(function(e){ A.toast(e.message || 'Settings could not be saved', { kind:'bad' }); })
          .then(function(){ if (save.isConnected) { save.disabled = false; save.textContent = 'Save'; } });
      };
    }).catch(function(e){ body.replaceChildren(h('p', { className:'muted', text:e.message || 'Settings could not load.' })); });
  }

  /* ---------------------------------------------------------------- module */
  function render(host){
    hostEl = host;
    var me = uid();
    if (owner !== me) { var first = owner === null; owner = me; status = null; if (!first) disc.q = ''; disc.results = null; mine.rows = null; mgr.rows = null; mgr.selected = {}; }
    if (A.state.requestsPrefill) { prefill(A.state.requestsPrefill.type, A.state.requestsPrefill.q); A.state.requestsPrefill = null; }
    var want = A.state.requestsTab || 'discover';
    tab = want === 'manage' || want === 'mine' ? want : 'discover';
    if (!me) { host.replaceChildren(h('div', { className:'mreq-empty' }, [h('h3', { text:'Sign in to request titles' })])); return; }
    if (!status) host.replaceChildren(h('p', { className:'pending-content muted', role:'status', text:'Loading requests…' }));
    else paint();
    loadStatus().then(function(){
      if (me !== uid() || hostEl !== host) return;
      if (tab === 'manage' && !status.manage) tab = 'discover';
      if (A.state.user && A.state.user.requests) { A.state.user.requests.create = status.create; A.state.user.requests.manage = status.manage; }
      paint(); loadTab();
    }).catch(function(e){
      if (hostEl !== host) return;
      host.replaceChildren(h('div', { className:'mreq-empty' }, [h('h3', { text:'Requests couldn’t load' }), h('p', { className:'muted', text:e.message || 'Try again in a moment.' }), h('button', { className:'btn', type:'button', text:'Try again', onClick:function(){ render(host); } })]));
    });
  }
  function init(app){ A = app; h = app.h; api = app.api; }
  function prefill(type, q){ disc.type = type === 'tv' ? 'tv' : 'movie'; disc.q = str(q).slice(0, 120); disc.results = null; disc.pending = true; }

  root.VaultcastRequests = { init:init, render:function(host){ render(host); if (disc.pending && tab === 'discover') { disc.pending = false; runSearch(); } }, prefill:prefill };
})(window);
