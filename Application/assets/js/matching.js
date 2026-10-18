
(function(){
  'use strict';
  var A = window.NSApp;
  if (!A) return;
  var h = A.h, api = A.api, toast = A.toast;
  var M = { counts: { ingest: 0, reports: 0 }, ui: {} };
  window.NSMatch = M;

  function isAdmin(){ var s = A.state; return !!(s && s.user && s.user.role === 'admin'); }
  function $(sel, el){ return (el || document).querySelector(sel); }
  function clear(el){ while (el && el.firstChild) el.removeChild(el.firstChild); return el; }
  function pad2(n){ n = parseInt(n, 10); return isNaN(n) ? '' : (n < 10 ? '0' + n : String(n)); }
  function code(s, e){
    if (s == null || s === '') return '';
    if (e == null || e === '') return (+s === 0 ? 'Specials' : 'Season ' + s);
    return 'S' + pad2(s) + 'E' + pad2(e);
  }
  function fmtSize(n){ return A.fmtSize ? A.fmtSize(n) : String(n || ''); }
  function chip(text, kind, title){ return h('span', { className: 'sys-chip nsm-chip' + (kind ? ' nsm-' + kind : ''), text: text, title: title || null }); }
  function num(v){ if (v === '' || v == null) return null; var n = parseInt(v, 10); return isNaN(n) ? null : n; }
  function busy(btn, on, label){
    if (!btn) return;
    if (on) { btn._t = btn.textContent; btn.disabled = true; btn.textContent = label || 'Working…'; }
    else { btn.disabled = false; if (btn._t) btn.textContent = btn._t; }
  }
  function input(attrs){ return h('input', attrs); }
  function modal(title, bodyKids, opts){
    opts = opts || {};
    var old = $('#nsm-modal'); if (old) old.remove();
    var card = h('div', { className: 'confirm-card nsm-modal-card' + (opts.wide ? ' wide' : '') }, [
      h('div', { className: 'nsm-modal-head' }, [
        h('h3', { text: title }),
        h('button', { className: 'btn ghost nsm-x', type: 'button', text: '✕', 'aria-label': 'Close', onClick: function(){ close(); } })
      ])
    ].concat(bodyKids));
    var ov = h('div', { id: 'nsm-modal', className: 'confirm-overlay nsm-overlay', onClick: function(e){ if (e.target === ov) close(); } }, [card]);
    function close(){ ov.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e){ if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', onKey);
    document.body.appendChild(ov);
    return { close: close, card: card };
  }

  /* ------------------------------------------------------------ badges */
  function paintBadges(){
    [['ingest', M.counts.ingest], ['reports', M.counts.reports]].forEach(function(p){
      Array.prototype.forEach.call(document.querySelectorAll('.nsm-badge-' + p[0]), function(el){
        var on = p[1] > 0;
        el.hidden = !on;
        el.textContent = on ? String(p[1]) : '';
        if (on) el.removeAttribute('hidden'); else el.setAttribute('hidden', '');
      });
    });
    var n = (M.counts.ingest || 0) + (M.counts.reports || 0);
    var bar = $('#nsm-notif');
    if (!bar) {
      bar = h('button', { id: 'nsm-notif', className: 'convert-notif nsm-notif', type: 'button', hidden: true, onClick: function(){
        A.openSettings(M.counts.ingest > 0 ? 'ingest' : 'reports');
      }});
      document.body.appendChild(bar);
    }
    var bits = [];
    if (M.counts.ingest > 0) bits.push(M.counts.ingest + ' download' + (M.counts.ingest === 1 ? '' : 's') + ' to ingest');
    if (M.counts.reports > 0) bits.push(M.counts.reports + ' label report' + (M.counts.reports === 1 ? '' : 's'));
    var onSettings = A.state.view === 'settings' && (A.state.settingsSection === 'ingest' || A.state.settingsSection === 'reports');
    bar.hidden = !(n > 0 && isAdmin()) || onSettings;
    bar.textContent = bits.join(' · ') + ' - review';
  }
  M.refreshCounts = function(){
    if (!isAdmin()) { M.counts = { ingest: 0, reports: 0 }; paintBadges(); return; }
    api('/admin/match/counts').then(function(j){
      if (j && j.ok) { M.counts.ingest = j.ingest || 0; M.counts.reports = j.reports || 0; M.mode = j.ingest_mode; }
      paintBadges();
    }).catch(function(){});
  };
  M.paintBadges = paintBadges;
  setTimeout(M.refreshCounts, 1500);
  setInterval(function(){ if (!document.hidden) M.refreshCounts(); }, 60000);

  /* ------------------------------------------------------- settings entry */
  M.settingsKids = function(sec){
    var host = h('div', { className: 'nsm-panel', id: 'nsm-panel-' + sec }, [ h('p', { className: 'muted pending-content', role: 'status' }) ]);
    var kids;
    if (sec === 'ingest') {
      kids = [ h('h2', { text: 'Ingest inbox' }),
        h('p', { className: 'muted', text: 'Finished downloads wait here. Say whether each one is a TV show, movie or anime, pick the online match (TVMaze / OMDb / Wikipedia), check the episode mapping, then import into the right library folder.' }), host ];
      setTimeout(function(){ renderIngest(host); }, 0);
    } else if (sec === 'matching') {
      kids = [ h('h2', { text: 'Fix matching' }),
        h('p', { className: 'muted', text: 'Find shows whose episodes are unnumbered or untitled, auto-match them against TVMaze by file name / title, pin the right online show, or edit season, episode and title by hand. Edits are kept when the library is rescanned.' }), host ];
      setTimeout(function(){ renderMatching(host); }, 0);
    } else {
      kids = [ h('h2', { text: 'Label reports' }),
        h('p', { className: 'muted', text: 'Viewers flag wrong episode, movie or anime labels with the ⚑ Report button. Reassign the metadata here, then the report is resolved.' }), host ];
      setTimeout(function(){ renderReports(host); }, 0);
    }
    return kids;
  };

  /* ================================================================ INGEST */
  var KIND_LABEL = { tv: 'TV show', movie: 'Movie', anime: 'Anime' };
  function renderIngest(host){
    clear(host).appendChild(h('p', { className: 'muted', text: 'Checking Transmission…' }));
    var showAll = !!M.ui.ingestAll;
    api('/admin/ingest' + (showAll ? '?all=1' : '')).then(function(j){
      clear(host);
      if (!j || !j.ok) { host.appendChild(h('p', { className: 'bad', text: (j && j.error) || 'Failed to load inbox' })); return; }
      M.counts.ingest = j.pending || 0; paintBadges();
      var modeSel = h('select', { onChange: function(e){
        api('/admin/ingest/mode', { method: 'POST', body: { mode: e.target.value } }).then(function(r){ toast(r.ok ? 'Ingest mode saved' : (r.error || 'Failed'), { kind: r.ok ? 'ok' : 'bad' }); });
      }}, [
        h('option', { value: 'ask_unlinked', text: 'Ask me, except exact episodes requested from a show page', selected: j.mode === 'ask_unlinked' }),
        h('option', { value: 'ask_all', text: 'Ask me for every finished download', selected: j.mode === 'ask_all' }),
        h('option', { value: 'auto', text: 'Import automatically (previous behaviour)', selected: j.mode === 'auto' })
      ]);
      host.appendChild(h('div', { className: 'settings-card nsm-toolbar' }, [
        h('label', { className: 'nsm-field grow' }, [ h('span', { text: 'When a download finishes' }), modeSel ]),
        h('label', { className: 'check-label' }, [ h('input', { type: 'checkbox', checked: showAll, onChange: function(e){ M.ui.ingestAll = e.target.checked; renderIngest(host); } }), ' Show done (imported / dismissed)' ]),
        h('button', { className: 'btn', type: 'button', text: 'Refresh', onClick: function(){ renderIngest(host); } })
      ]));
      if (j.transmission === false) host.appendChild(h('p', { className: 'bad', text: 'Transmission is not reachable, so finished downloads cannot be listed.' }));
      if (!j.items || !j.items.length) {
        host.appendChild(h('div', { className: 'empty-state' }, [ h('div', { className: 'empty-icon', text: '✓' }), h('h3', { text: 'Nothing waiting' }), h('p', { text: 'Finished downloads that need a decision show up here.' }) ]));
        return;
      }
      j.items.forEach(function(it){ host.appendChild(ingestCard(it, j.sections || {}, host)); });
    });
  }

  function ingestCard(it, sections, listHost){
    var body = h('div', { className: 'nsm-wizard', hidden: true });
    var statusChip = it.status === 'dismissed' ? chip('Dismissed', 'muted') : (it.status === 'partial' ? chip('Partly imported', 'warn') : chip('New', 'accent'));
    var q = it.queue;
    var card = h('div', { className: 'cv-card nsm-ingest-card' }, [
      h('div', { className: 'nsm-grow' }, [
        h('div', { className: 'cv-card-title', text: it.name }),
        h('div', { className: 'cv-card-meta muted' }, [
          statusChip, chip(it.files.length + ' video' + (it.files.length === 1 ? '' : 's')), chip(fmtSize(it.size)),
          chip('Looks like: ' + KIND_LABEL[it.guess_kind], 'accent'),
          q && q.query ? chip('Requested as "' + q.query + '"', '', 'Download queue entry') : null
        ].filter(Boolean)),
        body
      ]),
      h('div', { className: 'row-actions' }, [
        h('button', { className: 'btn primary', type: 'button', text: 'Review & import', onClick: function(e){
          body.hidden = !body.hidden;
          e.currentTarget.textContent = body.hidden ? 'Review & import' : 'Close';
          if (!body.hidden && !body.firstChild) wizard(body, it, sections, listHost);
        }}),
        it.status === 'dismissed'
          ? h('button', { className: 'btn ghost', type: 'button', text: 'Restore', onClick: function(){ setIngestStatus(it.hash, 'awaiting', listHost); } })
          : h('button', { className: 'btn ghost', type: 'button', text: 'Dismiss', title: 'Hide from the inbox and leave the files in the download folder', onClick: function(){ setIngestStatus(it.hash, 'dismissed', listHost); } })
      ])
    ]);
    return card;
  }
  function setIngestStatus(hash, st, listHost){
    api('/admin/ingest/status', { method: 'POST', body: { hash: hash, status: st } }).then(function(r){
      if (!r.ok) return toast(r.error || 'Failed', { kind: 'bad' });
      renderIngest(listHost); M.refreshCounts();
    });
  }

  function wizard(body, it, sections, listHost){
    var W = { kind: it.guess_kind, match: null, plan: null, section: null };
    var step1 = h('div', { className: 'nsm-step' });
    var step2 = h('div', { className: 'nsm-step' });
    var step3 = h('div', { className: 'nsm-step' });
    body.appendChild(step1); body.appendChild(step2); body.appendChild(step3);

    function paintKind(){
      clear(step1);
      step1.appendChild(h('div', { className: 'nsm-step-title', text: '1 · What is it?' }));
      step1.appendChild(h('div', { className: 'settings-choice-grid nsm-kind-grid' }, ['tv', 'movie', 'anime'].map(function(k){
        return h('button', { type: 'button', className: 'settings-choice' + (W.kind === k ? ' active' : ''), onClick: function(){
          if (W.kind === k) return;
          W.kind = k; W.match = null; W.plan = null; paintKind(); paintSearch(true); clear(step3);
        }}, [ h('strong', { text: KIND_LABEL[k] }), h('span', { className: 'muted', text: k === 'movie' ? 'OMDb / Wikipedia · Title (Year)/' : (k === 'anime' ? 'TVMaze · Anime library' : 'TVMaze · Show/Season NN/') }) ]);
      })));
    }
    function paintSearch(auto){
      clear(step2);
      var qIn = input({ type: 'search', value: W.kind === it.guess_kind || !W.lastQ ? (W.lastQ || it.guess_query || '') : W.lastQ, placeholder: W.kind === 'movie' ? 'Movie title (year optional)' : 'Show title' });
      var results = h('div', { className: 'nsm-results' });
      function run(){
        var q = qIn.value.trim(); if (!q) return;
        W.lastQ = q;
        clear(results).appendChild(h('p', { className: 'muted', text: 'Searching…' }));
        api('/admin/ingest/search', { method: 'POST', body: { kind: W.kind, q: q } }).then(function(r){
          clear(results);
          var rows = (r && r.results) || [];
          if (!rows.length) results.appendChild(h('p', { className: 'muted', text: (r && r.error) || 'No online match found. Try another spelling, or use the typed title.' }));
          rows.slice(0, 10).forEach(function(m){ results.appendChild(resultRow(m)); });
          results.appendChild(h('button', { className: 'btn ghost', type: 'button', text: 'Use "' + q + '" without an online match', onClick: function(){
            pick({ source: 'manual', id: null, title: q.replace(/\s*\((\d{4})\)\s*$/, ''), year: (q.match(/\((\d{4})\)\s*$/) || [])[1] || it.guess_year || null });
          }}));
        });
      }
      function resultRow(m){
        var meta = [m.year, m.network || m.type, m.language, (m.genres || []).slice(0, 3).join(', ')].filter(Boolean).join(' · ');
        var sel = W.match && String(W.match.id) === String(m.id) && W.match.source === m.source;
        return h('button', { type: 'button', className: 'nsm-result' + (sel ? ' active' : ''), onClick: function(){ pick(m); } }, [
          m.image ? h('img', { src: m.image, alt: '', loading: 'lazy' }) : h('div', { className: 'nsm-noimg', text: (m.title || '?').charAt(0) }),
          h('div', { className: 'nsm-grow' }, [
            h('strong', { text: (m.title || '') + (m.year ? ' (' + m.year + ')' : '') }),
            h('div', { className: 'muted', text: meta }),
            m.looks_anime && W.kind === 'tv' ? chip('Looks like anime', 'warn') : null,
            m.summary ? h('div', { className: 'muted nsm-summary', text: String(m.summary).slice(0, 180) }) : null
          ]),
          h('span', { className: 'sys-chip', text: m.source })
        ]);
      }
      function pick(m){
        W.match = m;
        if (m.looks_anime && W.kind === 'tv' && (sections.anime || []).some(function(s){ return s.type === 'anime'; })) {
          toast('TVMaze lists this as animation from Japan - switched to Anime (change it in step 1 if wrong)');
          W.kind = 'anime'; paintKind();
        }
        Array.prototype.forEach.call(results.querySelectorAll('.nsm-result'), function(b){ b.classList.remove('active'); });
        loadPlan();
      }
      step2.appendChild(h('div', { className: 'nsm-step-title', text: '2 · Match online' }));
      step2.appendChild(h('div', { className: 'cv-search-row' }, [ qIn, h('button', { className: 'btn', type: 'button', text: 'Search', onClick: run }) ]));
      qIn.addEventListener('keydown', function(e){ if (e.key === 'Enter') run(); });
      step2.appendChild(results);
      if (auto) run();
    }
    function loadPlan(extra){
      clear(step3).appendChild(h('p', { className: 'muted', text: 'Building import plan…' }));
      var b = { hash: it.hash, kind: W.kind, match: W.match, section_id: W.section || '' };
      if (extra) Object.keys(extra).forEach(function(k){ b[k] = extra[k]; });
      api('/admin/ingest/plan', { method: 'POST', body: b }).then(function(p){
        if (!p || !p.ok) { clear(step3).appendChild(h('p', { className: 'bad', text: (p && p.error) || 'Plan failed' })); return; }
        W.plan = p; W.section = p.section_id; paintPlan();
      });
    }
    function paintPlan(){
      var p = W.plan;
      clear(step3);
      var series = W.kind !== 'movie';
      step3.appendChild(h('div', { className: 'nsm-step-title', text: '3 · Check and import' }));
      var secSel = h('select', { onChange: function(e){ W.section = e.target.value; loadPlan(); } }, (p.sections || []).map(function(s){
        return h('option', { value: s.id, text: s.label + ' (' + s.path + ')', selected: s.id === p.section_id });
      }));
      var showIn = series ? input({ value: p.show_folder || '', title: 'Folder / show name in the library' }) : null;
      var seasonIn = series ? input({ type: 'number', min: '0', className: 'narrow', value: p.season_hint != null ? p.season_hint : '', placeholder: 'auto' }) : null;
      var keep = h('input', { type: 'checkbox' });
      var head = [ h('label', { className: 'nsm-field' }, [ h('span', { text: 'Library' }), secSel ]) ];
      if (series) {
        head.push(h('label', { className: 'nsm-field grow' }, [ h('span', { text: p.existing_folder ? 'Show folder (exists - files are added to it)' : 'Show folder (new)' }), showIn ]));
        head.push(h('label', { className: 'nsm-field' }, [ h('span', { text: 'Season for unnumbered' }), seasonIn ]));
        head.push(h('button', { className: 'btn ghost', type: 'button', text: 'Re-plan', onClick: function(){ loadPlan({ season_hint: seasonIn.value }); } }));
      }
      step3.appendChild(h('div', { className: 'nsm-toolbar' }, head));
      if (series && p.guide) step3.appendChild(h('p', { className: 'muted', text: 'TVMaze: ' + p.guide.name + ' · ' + (p.guide.episodes || []).length + ' episodes listed' }));
      var rows = [];
      var tbl = h('table', { className: 'live-table nsm-table' }, [
        h('thead', {}, [ h('tr', {}, series
          ? [ h('th', { text: '' }), h('th', { text: 'File → destination' }), h('th', { text: 'S' }), h('th', { text: 'E' }), h('th', { text: 'Episode title' }), h('th', { text: 'How' }) ]
          : [ h('th', { text: '' }), h('th', { text: 'File → destination' }), h('th', { text: 'Title' }), h('th', { text: 'Year' }) ]) ]),
        h('tbody', {}, p.files.map(function(f){
          var r = { f: f, on: h('input', { type: 'checkbox', checked: true }) };
          rows.push(r);
          var dest = h('div', { className: 'muted nsm-dest', text: '→ ' + (f.rel_dest || (f.ready ? '' : 'needs season + episode')) });
          if (f.conflict) dest.appendChild(chip('exists', 'bad'));
          var fileCell = h('td', { className: 'nsm-filecell', title: f.path }, [ h('div', { className: 'nsm-file', text: f.name }), dest ]);
          if (series) {
            r.s = input({ type: 'number', min: '0', className: 'narrow', value: f.season != null ? f.season : '' });
            r.e = input({ type: 'number', min: '0', className: 'narrow', value: f.episode != null ? f.episode : '' });
            r.t = input({ value: f.episode_title || '', placeholder: 'title (optional)', list: 'nsm-ingest-guide' });
            r.t.addEventListener('change', function(){ fillFromGuide(r); });
            return h('tr', { className: f.ready ? '' : 'nsm-needs' }, [ h('td', {}, [r.on]), fileCell, h('td', {}, [r.s]), h('td', {}, [r.e]), h('td', {}, [r.t]),
              h('td', {}, [ chip(f.method + (f.confidence ? ' ' + f.confidence + '%' : ''), f.method === 'needs_input' || f.method === 'duplicate' ? 'bad' : (f.confidence >= 85 ? 'ok' : 'warn')) ]) ]);
          }
          r.t = input({ value: f.title || '' });
          r.y = input({ type: 'number', className: 'narrow', value: f.year || '' });
          return h('tr', {}, [ h('td', {}, [r.on]), fileCell, h('td', {}, [r.t]), h('td', {}, [r.y]) ]);
        }))
      ]);
      if (series && p.guide) {
        var dl = h('datalist', { id: 'nsm-ingest-guide' }, (p.guide.episodes || []).filter(function(e){ return e.number != null; }).map(function(e){
          return h('option', { value: code(e.season, e.number) + ' ' + (e.name || '') });
        }));
        var oldDl = $('#nsm-ingest-guide'); if (oldDl) oldDl.remove();
        step3.appendChild(dl);
      }
      function fillFromGuide(r){
        var m = String(r.t.value).match(/^S(\d{1,2})E(\d{1,3})\s+(.*)$/);
        if (m) { r.s.value = parseInt(m[1], 10); r.e.value = parseInt(m[2], 10); r.t.value = m[3]; }
      }
      step3.appendChild(h('div', { className: 'nsm-table-wrap' }, [tbl]));
      var go = h('button', { className: 'btn primary', type: 'button', text: 'Import ' + p.files.length + ' file' + (p.files.length === 1 ? '' : 's') });
      go.addEventListener('click', function(){
        var files = rows.map(function(r){
          var o = { path: r.f.path, skip: !r.on.checked };
          if (series) { o.season = num(r.s.value); o.episode = num(r.e.value); o.episode_title = r.t.value.trim(); }
          else { o.title = r.t.value.trim(); o.year = num(r.y.value); }
          return o;
        });
        var todo = files.filter(function(f){ return !f.skip; });
        if (!todo.length) return toast('Nothing selected', { kind: 'bad' });
        if (series && todo.some(function(f){ return f.season == null || f.episode == null; })) return toast('Every selected file needs a season and episode', { kind: 'bad' });
        busy(go, true, 'Importing…');
        function waitJob(id){
          return new Promise(function(resolve){
            var fails = 0;
            (function poll(){
              api('/admin/ingest/progress?id=' + encodeURIComponent(id)).then(function(p){
                fails = 0;
                var pr = p.progress || {};
                if (p.status === 'queued') go.textContent = 'Waiting for another task…';
                else if (pr.total) go.textContent = 'Importing ' + Math.min(pr.done || 0, pr.total) + ' of ' + pr.total + ' files…';
                if (p.status === 'complete' || p.status === 'failed' || p.status === 'cancelled') {
                  resolve(p.result || { ok: false, error: p.error || 'Import failed', errors: [{ path: '', error: p.error || 'Import failed' }] });
                } else setTimeout(poll, 1500);
              }).catch(function(){ if (++fails > 20) resolve({ ok: false, errors: [{ path: '', error: 'Lost contact with the server' }] }); else setTimeout(poll, 3000); });
            })();
          });
        }
        api('/admin/ingest/commit', { method: 'POST', body: {
          hash: it.hash, kind: W.kind, section_id: W.section, show: series ? showIn.value.trim() : '', files: files,
          tvmaze_id: W.match && W.match.source === 'tvmaze' ? W.match.id : null, match: W.match, keep_torrent: keep.checked
        }}).then(function(r){ return r && r.background && r.job ? waitJob(r.job) : r; }).then(function(r){
          busy(go, false);
          if (r.imported) toast('Imported ' + r.imported + ' file' + (r.imported === 1 ? '' : 's') + (r.finish && r.finish.message ? ' - ' + r.finish.message : ''), { kind: r.ok ? 'ok' : 'warn', ms: 5000 });
          if (!r.ok) {
            var errs = (r.errors || []).map(function(e){ return (e.path || '').split('/').pop() + ': ' + e.error; }).join('\n');
            A.confirmDialog('Some files were not imported:\n' + errs, { okText: 'OK' });
          }
          if (r.imported) { renderIngest(listHost); M.refreshCounts(); }
        });
      });
      step3.appendChild(h('div', { className: 'row-actions nsm-commit' }, [
        h('label', { className: 'check-label' }, [ keep, ' Keep the torrent seeding (do not delete download data)' ]), go
      ]));
    }
    paintKind(); paintSearch(true);
  }

  /* ============================================================== MATCHING */
  function renderMatching(host){
    if (M.ui.openShow) { var k = M.ui.openShow; M.ui.openShow = null; return renderShowEditor(host, k); }
    clear(host);
    var qIn = input({ type: 'search', placeholder: 'Filter shows', value: M.ui.matchQ || '' });
    var filt = h('select', {}, [ h('option', { value: 'problems', text: 'Shows with problems', selected: M.ui.matchFilter !== 'all' }), h('option', { value: 'all', text: 'All TV & anime shows', selected: M.ui.matchFilter === 'all' }) ]);
    var list = h('div', { className: 'nsm-show-list' });
    function load(){
      M.ui.matchQ = qIn.value; M.ui.matchFilter = filt.value;
      clear(list).appendChild(h('p', { className: 'muted pending-content', role: 'status' }));
      api('/admin/match/shows?filter=' + encodeURIComponent(filt.value) + '&q=' + encodeURIComponent(qIn.value)).then(function(j){
        clear(list);
        if (!j.ok) return list.appendChild(h('p', { className: 'bad', text: j.error || 'Failed' }));
        if (!j.shows.length) return list.appendChild(h('p', { className: 'muted', text: 'No shows need attention.' }));
        list.appendChild(h('table', { className: 'live-table nsm-table' }, [
          h('thead', {}, [ h('tr', {}, [ h('th', { text: 'Show' }), h('th', { text: 'Library' }), h('th', { text: 'Files' }), h('th', { text: 'No episode no.' }), h('th', { text: 'No title' }), h('th', { text: 'Online guide' }), h('th', { text: '' }) ]) ]),
          h('tbody', {}, j.shows.map(function(s){
            var g = { pinned: ['Pinned', 'ok'], none: ['Local only', 'muted'], auto: ['TVMaze (auto)', ''], miss: ['Not found', 'bad'], unknown: ['Not fetched', 'muted'] }[s.guide] || [s.guide, ''];
            return h('tr', {}, [
              h('td', {}, [ h('strong', { text: s.title }) ]), h('td', { text: s.section }), h('td', { text: String(s.files) }),
              h('td', {}, [ s.unnumbered ? chip(String(s.unnumbered), 'bad') : chip('0', 'ok') ]),
              h('td', {}, [ s.untitled ? chip(String(s.untitled), 'warn') : chip('0', 'ok') ]),
              h('td', {}, [ chip(g[0], g[1]), s.overrides ? chip(s.overrides + ' fixed', 'accent') : null ].filter(Boolean)),
              h('td', {}, [ h('button', { className: 'btn', type: 'button', text: 'Fix', onClick: function(){ renderShowEditor(host, s.show_key); } }) ])
            ]);
          }))
        ]));
      });
    }
    qIn.addEventListener('keydown', function(e){ if (e.key === 'Enter') load(); });
    filt.addEventListener('change', load);
    var rp = h('div', { className: 'settings-card nsm-reparse' });
    function paintReparse(res){
      clear(rp);
      rp.appendChild(h('div', { className: 'nsm-grow' }, [
        h('strong', { text: 'Re-read episode numbers from file and folder names' }),
        h('p', { className: 'muted', text: 'Runs the improved name parser over the whole TV/anime catalogue (Season folders, "01 - Title", "Show - 05", "S01 E02", event folders). Nothing is moved on disk. Manual fixes are kept.' }),
        res ? h('p', { className: res.ok ? 'ok' : 'bad', text: res.ok ? ((res.dry ? 'Would update ' : 'Updated ') + res.changed + ' items.') : (res.error || 'Failed') }) : null,
        res && res.sample && res.sample.length && res.dry ? h('details', { className: 'live-acc' }, [ h('summary', { text: 'Preview (first ' + res.sample.length + ')' }),
          h('div', { className: 'nsm-sample' }, res.sample.map(function(x){
            return h('div', { className: 'muted', text: x.rel + ' → ' + Object.keys(x.changes).map(function(k){ return k + ': ' + x.changes[k][0] + ' → ' + x.changes[k][1]; }).join('; ') });
          })) ]) : null
      ].filter(Boolean)));
      var bPrev = h('button', { className: 'btn', type: 'button', text: 'Preview' });
      var bApply = h('button', { className: 'btn primary', type: 'button', text: 'Apply', disabled: !(res && res.dry && res.changed) });
      bPrev.addEventListener('click', function(){ busy(bPrev, true, 'Checking…'); api('/admin/match/reparse', { method: 'POST', body: { apply: false } }).then(function(r){ paintReparse(r); }); });
      bApply.addEventListener('click', function(){ busy(bApply, true, 'Applying…'); api('/admin/match/reparse', { method: 'POST', body: { apply: true } }).then(function(r){ paintReparse(r); load(); }); });
      rp.appendChild(h('div', { className: 'row-actions' }, [bPrev, bApply]));
    }
    paintReparse(null);
    host.appendChild(rp);
    host.appendChild(h('div', { className: 'cv-search-row' }, [ qIn, filt, h('button', { className: 'btn', type: 'button', text: 'Search', onClick: load }) ]));
    host.appendChild(list);
    load();
  }

  function renderShowEditor(host, showKey, context){
    clear(host).appendChild(h('p', { className: 'muted pending-content', role: 'status' }));
    api('/admin/match/show/' + showKey).then(function(j){
      clear(host);
      if (!j.ok) { host.appendChild(h('p', { className: 'bad', text: j.error || 'Show not found' })); host.appendChild(h('button', { className: 'btn', text: context ? 'Close' : '← All shows', onClick: function(){ if (context) context.close(); else renderMatching(host); } })); return; }
      var pin = j.pin, guide = j.guide;
      var guideText = pin && pin.provider === 'none' ? 'Local only (no online match) - unnumbered files become Specials in folder order'
        : (guide ? ('TVMaze: ' + guide.name + (guide.premiered ? ' (' + String(guide.premiered).slice(0, 4) + ')' : '') + ' · #' + guide.id + (pin ? ' · pinned' : ' · auto') + ' · ' + (guide.episodes || []).length + ' episodes') : 'No TVMaze match');
      host.appendChild(h('div', { className: 'nsm-editor-head' }, [
        h('button', { className: 'btn ghost', type: 'button', text: context ? 'Close' : '← All shows', onClick: function(){ if (context) context.close(); else renderMatching(host); } }),
        h('div', { className: 'nsm-grow' }, [ h('h3', { text: j.show }), h('div', { className: 'muted', text: j.section + ' · ' + j.items.length + ' files · ' + guideText }) ]),
        context ? null : h('button', { className: 'btn ghost', type: 'button', text: 'Open show page', onClick: function(){ A.openShow(showKey); } })
      ]));
      // provider controls
      var pq = input({ type: 'search', value: (guide && guide.name) || j.show, placeholder: 'Title, year, TVMaze URL or IMDb ID' });
      var pres = h('div', { className: 'nsm-results' });
      var renameIn = input({ value: '', placeholder: 'Rename show (optional)' });
      var ptabs = h('div', { className: 'nsm-src-tabs', role: 'tablist' }), psource = 'all';
      function searchProv(){
        clear(pres).appendChild(h('p', { className: 'muted', text: 'Searching…' }));
        api('/admin/match/search', { method: 'POST', body: { kind: j.type === 'anime' ? 'anime' : 'tv', q: pq.value, source: psource } }).then(function(r){
          clear(pres);
          sourceTabs(ptabs, r.sources, psource, function(id){ psource = id; searchProv(); });
          if (r.unavailable) { pres.appendChild(h('p', { className: 'muted', text: r.unavailable })); return; }
          (r.results || []).slice(0, 10).forEach(function(m){
            var isMaze = m.source === 'tvmaze';
            var meta = [m.matched_via, m.network || m.type, m.language, (m.genres || []).join(', '), isMaze ? 'Use #' + m.id : (m.source === 'omdb' ? 'synopsis + poster, episodes via IMDb id' : 'synopsis + poster only')].filter(Boolean).join(' · ');
            pres.appendChild(h('button', { type: 'button', className: 'nsm-result' + (isMaze && guide && +guide.id === +m.id ? ' active' : ''), onClick: function(){
              var match = { source: m.source, id: m.id, title: m.title, year: m.year || null, summary: m.summary || '', image: m.image || null, page_title: m.page_title || null, url: m.url || null };
              doPin(isMaze ? { provider: 'tvmaze', tvmaze_id: m.id, name: m.title, rename_to: renameIn.value.trim(), match: match }
                           : { provider: 'tvmaze', name: m.title, rename_to: renameIn.value.trim(), match: match });
            }}, fixResultBody(m, meta)));
          });
          if (!(r.results || []).length) pres.appendChild(h('p', { className: 'muted', text: 'No results from this source.' }));
        });
      }
      function doPin(body){
        api('/admin/match/show/' + showKey + '/pin', { method: 'POST', body: body }).then(function(r){
          if (!r.ok) return toast(r.error || 'Failed', { kind: 'bad' });
          toast(r.meta_only ? 'Synopsis and poster pinned (episode guide unchanged)' : 'Saved - now run Auto-match', { kind: 'ok' });
          if (r.warning) toast(r.warning, {kind:'warn'});
          if (context) { A.refreshArtwork().then(function(){context.saved(r);}); return; }
          renderShowEditor(host, r.show_key || showKey);
        });
      }
      host.appendChild(h('details', { className: 'live-acc nsm-prov' }, [
        h('summary', { text: 'Online match: ' + guideText }),
        h('div', { className: 'nsm-prov-body' }, [
          h('div', { className: 'cv-search-row' }, [ pq, h('button', { className: 'btn', type: 'button', text: 'Search', onClick: searchProv }), renameIn ]),
          ptabs,
          pres,
          h('div', { className: 'row-actions' }, [
            h('button', { className: 'btn ghost', type: 'button', text: 'No online match (local specials)', onClick: function(){ doPin({ provider: 'none', rename_to: renameIn.value.trim() }); } }),
            pin ? h('button', { className: 'btn ghost', type: 'button', text: 'Clear pin (auto search by name)', onClick: function(){ doPin({ provider: 'clear' }); } }) : null,
            h('button', { className: 'btn ghost', type: 'button', text: 'Rename only', onClick: function(){
              if (!renameIn.value.trim()) return toast('Type the new name first');
              doPin({ provider: pin ? (pin.provider === 'none' ? 'none' : 'tvmaze') : 'clear', tvmaze_id: pin && pin.tvmaze_id, name: pin && pin.name, rename_to: renameIn.value.trim() });
            }})
          ].filter(Boolean))
        ])
      ]));
      pq.addEventListener('keydown', function(e){ if (e.key === 'Enter') searchProv(); });
      host.appendChild(numberingPanel(showKey, function(){ renderShowEditor(host, showKey, context); }));

      // guide datalist
      var dlId = 'nsm-guide-' + showKey;
      if (guide && guide.episodes) {
        host.appendChild(h('datalist', { id: dlId }, guide.episodes.filter(function(e){ return e.number != null; }).map(function(e){
          return h('option', { value: code(e.season, e.number) + ' ' + (e.name || '') });
        })));
      }
      var tableHost = h('div', { className: 'nsm-table-wrap' });
      var actions = h('div', { className: 'row-actions nsm-sticky-actions' });
      host.appendChild(actions);
      host.appendChild(tableHost);
      paintItems(j.items, null);

      function paintItems(items, proposal){
        clear(tableHost); clear(actions);
        var propBy = {};
        if (proposal) proposal.rows.forEach(function(r){ propBy[r.id] = r; });
        var rows = [];
        var all = h('input', { type: 'checkbox', title: 'Select all', onChange: function(e){ rows.forEach(function(r){ r.sel.checked = e.target.checked; }); } });
        var tbl = h('table', { className: 'live-table nsm-table' }, [
          h('thead', {}, [ h('tr', {}, [ h('th', {}, [all]), h('th', { text: 'File' }), h('th', { text: 'S' }), h('th', { text: 'E' }), h('th', { text: 'Episode title' }), h('th', { text: proposal ? 'Auto-match' : 'Source' }) ]) ]),
          h('tbody', {}, items.map(function(it){
            var pr = propBy[it.id];
            var r = { it: it, pr: pr, sel: h('input', { type: 'checkbox', checked: !!(pr && pr.changed) }) };
            var src = pr ? pr.proposal : it;
            r.s = input({ type: 'number', min: '0', className: 'narrow', value: src.season != null ? src.season : '' });
            r.e = input({ type: 'number', min: '0', className: 'narrow', value: src.episode != null ? src.episode : '' });
            r.t = input({ value: src.episode_title || '', list: guide ? dlId : null, placeholder: guide ? 'type or pick from TVMaze' : 'title' });
            r.t.addEventListener('change', function(){
              var m = String(r.t.value).match(/^S(\d{1,2})E(\d{1,3})\s+(.*)$/);
              if (m) { r.s.value = parseInt(m[1], 10); r.e.value = parseInt(m[2], 10); r.t.value = m[3]; }
              r.sel.checked = true;
            });
            [r.s, r.e].forEach(function(x){ x.addEventListener('input', function(){ r.sel.checked = true; }); });
            rows.push(r);
            var info;
            if (pr) {
              var p = pr.proposal;
              var was = code(pr.current.season, pr.current.episode) || 'unnumbered';
              info = h('td', {}, [ chip(p.method + (p.confidence ? ' ' + p.confidence + '%' : ''), p.method === 'local' ? 'muted' : (p.confidence >= 85 ? 'ok' : 'warn'), p.note || ''),
                pr.changed ? h('div', { className: 'muted nsm-was', text: 'was ' + was + (pr.current.episode_title ? ' · ' + pr.current.episode_title : '') }) : chip('no change', 'muted'),
                pr.override_source === 'manual' ? chip('manual kept', 'accent') : null ].filter(Boolean));
            } else {
              info = h('td', {}, [ it.override ? chip(it.override.source || 'fixed', 'accent', 'Saved ' + (it.override.at || '') + ' by ' + (it.override.by || '')) : chip('file name', 'muted') ]);
            }
            return h('tr', { className: pr && pr.changed ? 'nsm-changed' : '' }, [ h('td', {}, [r.sel]), h('td', { className: 'nsm-file', text: it.rel.split('/').slice(1).join('/'), title: it.rel }), h('td', {}, [r.s]), h('td', {}, [r.e]), h('td', {}, [r.t]), info ]);
          }))
        ]);
        tableHost.appendChild(tbl);
        var bAuto = h('button', { className: 'btn primary', type: 'button', text: proposal ? 'Re-run auto-match' : 'Auto-match' });
        bAuto.addEventListener('click', function(){
          busy(bAuto, true, 'Matching…');
          api('/admin/match/show/' + showKey + '/propose', { method: 'POST', body: {} }).then(function(pr){
            busy(bAuto, false);
            if (!pr.ok) return toast(pr.error || 'Failed', { kind: 'bad' });
            var changed = pr.rows.filter(function(r){ return r.changed; }).length;
            toast(pr.matched_online + ' of ' + pr.total + ' matched online · ' + changed + ' would change', { ms: 4500 });
            paintItems(items, pr);
          });
        });
        var bSave = h('button', { className: 'btn' + (proposal ? ' primary' : ''), type: 'button', text: proposal ? 'Apply selected' : 'Save selected' });
        bSave.addEventListener('click', function(){
          var sel = rows.filter(function(r){ return r.sel.checked; });
          if (!sel.length) return toast('Select rows first');
          var payload = sel.map(function(r){ return { id: r.it.id, season: num(r.s.value), episode: num(r.e.value), episode_title: r.t.value.trim() || null }; });
          busy(bSave, true, 'Saving…');
          api('/admin/match/items', { method: 'POST', body: { rows: payload } }).then(function(res){
            busy(bSave, false);
            if (!res.ok) return toast(res.error || 'Failed', { kind: 'bad' });
            toast('Saved ' + res.changed + ' item' + (res.changed === 1 ? '' : 's'), { kind: 'ok' });
            if (res.warning) toast(res.warning, {kind:'warn'});
            renderShowEditor(host, showKey, context);
          });
        });
        actions.appendChild(bAuto);
        actions.appendChild(bSave);
        if (proposal) {
          var bAll = h('button', { className: 'btn', type: 'button', text: 'Apply all auto changes' });
          bAll.addEventListener('click', function(){
            busy(bAll, true, 'Applying…');
            api('/admin/match/show/' + showKey + '/apply', { method: 'POST', body: {} }).then(function(res){
              busy(bAll, false);
              if (!res.ok) return toast(res.error || 'Failed', { kind: 'bad' });
              toast('Updated ' + res.changed + ' episodes' + (res.skipped ? ' (' + res.skipped + ' manual kept)' : ''), { kind: 'ok' });
              renderShowEditor(host, showKey, context);
            });
          });
          actions.appendChild(bAll);
        }
        var bReset = h('button', { className: 'btn ghost', type: 'button', text: 'Reset selected to file name' });
        bReset.addEventListener('click', function(){
          var ids = rows.filter(function(r){ return r.sel.checked; }).map(function(r){ return r.it.id; });
          if (!ids.length) return toast('Select rows first');
          A.confirmDialog('Remove saved fixes for ' + ids.length + ' file(s) and go back to what the file names say?', { okText: 'Reset', danger: true }).then(function(ok){
            if (!ok) return;
            api('/admin/match/items/clear', { method: 'POST', body: { ids: ids } }).then(function(){ renderShowEditor(host, showKey, context); });
          });
        });
        actions.appendChild(bReset);
      }
    });
  }

  /* Episode numbering: Season/Episode vs Absolute (overall number, typical for anime). */
  function numberingPanel(showKey, done){
    var body = h('div', { className: 'nsm-prov-body' });
    var sum = h('summary', { text: 'Episode numbering' });
    var det = h('details', { className: 'live-acc nsm-prov nsm-numbering' }, [ sum, body ]);
    var mode = null, data = null, rows = [];
    var modeSel = h('select', { title: 'How episodes are numbered in the file names' }, [
      h('option', { value: 'auto', text: 'Auto-detect' }),
      h('option', { value: 'season', text: 'Season / Episode (S01E05)' }),
      h('option', { value: 'absolute', text: 'Absolute (overall episode number)' })
    ]);
    var info = h('p', { className: 'muted' });
    var tableHost = h('div', { className: 'nsm-table-wrap' });
    var bApply = h('button', { className: 'btn primary', type: 'button', text: 'Apply' });
    body.appendChild(h('div', { className: 'row-actions' }, [ h('label', {}, [ 'Numbering ', modeSel ]), bApply ]));
    body.appendChild(info);
    body.appendChild(tableHost);
    function mapped(n){
      var m = data && data.map ? data.map[n] : null;
      if (n == null || isNaN(n)) return null;
      if (m) return { s: m[0], e: m[1], t: m[2] || '' };
      if (data && Object.keys(data.map || {}).length === 0) return { s: 1, e: n, t: '' };
      return null;
    }
    function load(m){
      clear(tableHost).appendChild(h('p', { className: 'muted', text: 'Reading file names…' }));
      api('/admin/match/show/' + showKey + '/numbering/preview', { method: 'POST', body: m ? { mode: m } : {} }).then(function(r){
        if (!r.ok) { clear(tableHost); return toast(r.error || 'Failed', { kind: 'bad' }); }
        data = r; mode = r.mode; modeSel.value = r.mode;
        var dt = r.detected || {};
        sum.textContent = 'Episode numbering: ' + (r.effective === 'absolute' ? 'Absolute' : 'Season / Episode') + (r.mode === 'auto' ? ' (auto)' : '');
        info.textContent = (r.mode === 'auto' ? 'Detected ' + (dt.mode === 'absolute' ? 'absolute numbering' : 'season/episode numbering') + (dt.reasons && dt.reasons.length ? ' (' + dt.reasons.join(', ') + ')' : '') + '. ' : '')
          + (r.effective === 'absolute' ? 'Mapping: ' + (r.source === 'none' ? 'no episode guide, so files are numbered as season 1' : r.source) + '. Check the numbers, edit any that are wrong, then Apply. Watch progress stays with each file.'
             : 'Season/Episode uses the SxxEyy in the file names. Apply restores files changed by an earlier absolute pass.');
        paint();
      });
    }
    function paint(){
      clear(tableHost); rows = [];
      if (data.effective !== 'absolute') return;
      var all = h('input', { type: 'checkbox', checked: true, onChange: function(e){ rows.forEach(function(r){ r.sel.checked = e.target.checked; }); } });
      tableHost.appendChild(h('table', { className: 'live-table nsm-table' }, [
        h('thead', {}, [ h('tr', {}, [ h('th', {}, [all]), h('th', { text: 'File' }), h('th', { text: 'Detected #' }), h('th', { text: 'Maps to' }), h('th', { text: 'Title' }), h('th', { text: 'Now' }) ]) ]),
        h('tbody', {}, data.rows.map(function(x){
          var r = { x: x, sel: h('input', { type: 'checkbox', checked: !!x.changed }) };
          r.n = input({ type: 'number', min: '1', className: 'narrow', value: x.absolute != null ? x.absolute : '', title: x.method ? 'Found by: ' + x.method : 'No number found; type one' });
          var codeTd = h('td'), titleTd = h('td');
          function upd(){
            var m = mapped(num(r.n.value));
            clear(codeTd).appendChild(m ? document.createTextNode(code(m.s, m.e)) : chip(x.note || 'no match', 'warn'));
            titleTd.textContent = m ? m.t : '';
          }
          r.n.addEventListener('input', function(){ r.sel.checked = true; upd(); });
          upd(); rows.push(r);
          return h('tr', { className: x.changed ? 'nsm-changed' : '' }, [ h('td', {}, [r.sel]), h('td', { className: 'nsm-file', text: x.file, title: x.rel }), h('td', {}, [r.n]), codeTd, titleTd,
            h('td', { className: 'muted', text: (code(x.current.season, x.current.episode) || 'unnumbered') + (x.current.absolute ? ' (#' + x.current.absolute + ')' : '') }) ]);
        }))
      ]));
    }
    modeSel.addEventListener('change', function(){ load(modeSel.value); });
    bApply.addEventListener('click', function(){
      var body = { mode: modeSel.value };
      if (data && data.effective === 'absolute') {
        body.rows = rows.filter(function(r){ return r.sel.checked; }).map(function(r){ return { id: r.x.id, absolute: num(r.n.value) }; });
        if (!body.rows.length) return toast('Select files first');
      }
      busy(bApply, true, 'Applying…');
      api('/admin/match/show/' + showKey + '/numbering', { method: 'POST', body: body }).then(function(res){
        busy(bApply, false);
        if (!res.ok) return toast(res.error || 'Failed', { kind: 'bad' });
        toast(res.effective === 'absolute' ? ('Numbering saved · ' + res.changed + ' episode' + (res.changed === 1 ? '' : 's') + ' updated') : ('Season/Episode numbering saved' + (res.restored ? ' · ' + res.restored + ' restored' : '')), { kind: 'ok' });
        done();
      });
    });
    det.addEventListener('toggle', function(){ if (det.open && !data) load(null); });
    // Fetch the mode up front so the summary shows it, and open the panel when absolute applies.
    api('/admin/match/show/' + showKey + '/numbering/preview', { method: 'POST', body: {} }).then(function(r){
      if (!r || !r.ok || data) return;
      data = r; mode = r.mode; modeSel.value = r.mode;
      sum.textContent = 'Episode numbering: ' + (r.effective === 'absolute' ? 'Absolute' : 'Season / Episode') + (r.mode === 'auto' ? ' (auto)' : '');
      if (r.effective === 'absolute') { det.open = true; load(r.mode); }
    }).catch(function(){});
    return det;
  }

  /* =============================================================== REPORTS */
  function renderReports(host){
    var st = M.ui.repStatus || 'open';
    clear(host);
    host.appendChild(h('div', { className: 'tabs' }, ['open', 'resolved', 'dismissed', 'all'].map(function(s){
      return h('button', { type: 'button', className: s === st ? 'active' : '', text: s.charAt(0).toUpperCase() + s.slice(1), onClick: function(){ M.ui.repStatus = s; renderReports(host); } });
    })));
    var list = h('div', {}, [ h('p', { className: 'muted pending-content', role: 'status' }) ]);
    host.appendChild(list);
    api('/admin/reports?status=' + st).then(function(j){
      clear(list);
      if (!j.ok) return list.appendChild(h('p', { className: 'bad', text: j.error || 'Failed' }));
      M.counts.reports = j.open || 0; paintBadges();
      if (!j.reports.length) return list.appendChild(h('div', { className: 'empty-state' }, [ h('div', { className: 'empty-icon', text: '⚑' }), h('h3', { text: 'No ' + (st === 'all' ? '' : st + ' ') + 'reports' }), h('p', { text: 'Viewers can flag a wrong label from any movie page, show page or episode row.' }) ]));
      j.reports.forEach(function(r){ list.appendChild(reportCard(r, host)); });
    });
  }
  function labelOf(x, kind){
    if (!x) return '(item no longer in the library)';
    if (kind === 'movie') return (x.title || '') + (x.year ? ' (' + x.year + ')' : '');
    if (kind === 'show') return x.title || '';
    return (x.show ? x.show + ' · ' : '') + (code(x.season, x.episode) || 'unnumbered') + (x.episode_title ? ' · ' + x.episode_title : '');
  }
  function reportCard(r, listHost){
    var fix = h('div', { className: 'nsm-fix', hidden: true });
    var notes = [r.note].concat((r.notes || []).map(function(n){ return n.by + ': ' + n.note; })).filter(Boolean);
    var sug = r.suggested ? Object.keys(r.suggested).filter(function(k){ return r.suggested[k] !== '' && r.suggested[k] != null; }).map(function(k){ return k + ' ' + r.suggested[k]; }).join(', ') : '';
    var cls = 'cv-card nsm-report' + (r.status === 'open' ? ' cv-card-pending' : '');
    return h('div', { className: cls }, [
      h('div', { className: 'nsm-grow' }, [
        h('div', { className: 'cv-card-title' }, [ chip(r.kind, 'accent'), ' ', labelOf(r.current || r.snapshot, r.kind) ]),
        h('div', { className: 'cv-card-meta muted', text: 'Reported by ' + r.reporter + (r.count > 1 ? ' (+' + (r.count - 1) + ' more)' : '') + ' · ' + String(r.created_at || '').replace('T', ' ').slice(0, 16) + (r.status !== 'open' ? ' · ' + r.status + (r.resolved_by ? ' by ' + r.resolved_by : '') : '') }),
        r.snapshot && r.current && labelOf(r.snapshot, r.kind) !== labelOf(r.current, r.kind) ? h('div', { className: 'cv-card-meta muted', text: 'When reported: ' + labelOf(r.snapshot, r.kind) }) : null,
        notes.length ? h('div', { className: 'nsm-note', text: '“' + notes.join('” · “') + '”' }) : null,
        sug ? h('div', { className: 'cv-card-meta', text: 'Suggested: ' + sug }) : null,
        (r.current || r.snapshot || {}).rel ? h('div', { className: 'cv-card-path muted', text: (r.current || r.snapshot).rel }) : null,
        r.resolution ? h('div', { className: 'cv-card-meta ok', text: r.resolution }) : null,
        fix
      ].filter(Boolean)),
      h('div', { className: 'row-actions' }, [
        r.status === 'open' ? h('button', { className: 'btn primary', type: 'button', text: 'Reassign', onClick: function(){ fix.hidden = !fix.hidden; if (!fix.hidden && !fix.firstChild) fixForm(fix, r, listHost); } }) : null,
        r.status === 'open' ? h('button', { className: 'btn ghost', type: 'button', text: 'Dismiss', onClick: function(){ setReport(r.id, { status: 'dismissed' }, listHost); } })
          : h('button', { className: 'btn ghost', type: 'button', text: 'Reopen', onClick: function(){ setReport(r.id, { status: 'open' }, listHost); } })
      ].filter(Boolean))
    ]);
  }
  function setReport(id, body, listHost){
    return api('/admin/reports/' + id, { method: 'POST', body: body }).then(function(r){
      if (!r.ok) { toast(r.error || 'Failed', { kind: 'bad' }); return r; }
      if (listHost) renderReports(listHost);
      M.refreshCounts();
      return r;
    });
  }
  function fixForm(host, r, listHost){
    var cur = r.current || r.snapshot || {};
    if (r.kind === 'show') {
      host.appendChild(h('div', { className: 'row-actions' }, [ h('button', { className: 'btn', type: 'button', text: 'Open in Fix matching', onClick: function(){
        M.ui.openShow = r.show_key; M.ui.resolveReport = r.id; A.openSettings('matching');
      }}) ]));
      return;
    }
    if (r.kind === 'movie' || cur.type === 'movies' || cur.type === 'movie') {
      return movieFixForm(host, r.item_id, cur, function(label){ setReport(r.id, { status: 'resolved', resolution: 'Now: ' + label }, listHost); });
    }
    host.appendChild(h('p', { className: 'muted pending-content', role: 'status' }));
    if (!r.show_key) { clear(host).appendChild(h('p', { className: 'bad', text: 'Episode has no show key' })); return; }
    api('/admin/match/show/' + r.show_key).then(function(j){
      clear(host);
      if (!j.ok) { host.appendChild(h('p', { className: 'bad', text: j.error || 'Show not found' })); return; }
      episodeFixForm(host, r.item_id, cur, j, function(label){ setReport(r.id, { status: 'resolved', resolution: 'Now: ' + label }, listHost); });
    });
  }
  function episodeFixForm(host, itemId, cur, ed, done){
    var sug = {};
    var s = input({ type: 'number', min: '0', className: 'narrow', value: cur.season != null ? cur.season : '' });
    var e = input({ type: 'number', min: '0', className: 'narrow', value: cur.episode != null ? cur.episode : '' });
    var dlId = 'nsm-fix-dl-' + itemId;
    var t = input({ value: cur.episode_title || '', list: dlId, placeholder: 'type or pick from TVMaze' });
    var showSel = h('select', {}, [ h('option', { value: '', text: '(same show) ' + (ed.show || '') }) ].concat((ed.shows_in_section || []).filter(function(n){ return n !== ed.show; }).map(function(n){ return h('option', { value: n, text: n }); })));
    t.addEventListener('change', function(){ var m = String(t.value).match(/^S(\d{1,2})E(\d{1,3})\s+(.*)$/); if (m) { s.value = parseInt(m[1], 10); e.value = parseInt(m[2], 10); t.value = m[3]; } });
    var dl = h('datalist', { id: dlId }, ((ed.guide && ed.guide.episodes) || []).filter(function(x){ return x.number != null; }).map(function(x){ return h('option', { value: code(x.season, x.number) + ' ' + (x.name || '') }); }));
    var save = h('button', { className: 'btn primary', type: 'button', text: 'Save & resolve' });
    save.addEventListener('click', function(){
      var row = { id: itemId, season: num(s.value), episode: num(e.value), episode_title: t.value.trim() || null };
      if (showSel.value) row.show = showSel.value;
      busy(save, true, 'Saving…');
      api('/admin/match/items', { method: 'POST', body: { rows: [row] } }).then(function(res){
        busy(save, false);
        if (!res.ok || !res.changed) return toast(res.error || 'Nothing saved', { kind: 'bad' });
        toast('Label updated', { kind: 'ok' });
        done((row.show || ed.show) + ' · ' + (code(row.season, row.episode) || 'unnumbered') + (row.episode_title ? ' · ' + row.episode_title : ''));
      });
    });
    host.appendChild(dl);
    host.appendChild(h('div', { className: 'nsm-toolbar' }, [
      h('label', { className: 'nsm-field' }, [ h('span', { text: 'Season' }), s ]),
      h('label', { className: 'nsm-field' }, [ h('span', { text: 'Episode' }), e ]),
      h('label', { className: 'nsm-field grow' }, [ h('span', { text: 'Title' + (ed.guide ? ' (TVMaze: ' + ed.guide.name + ')' : '') }), t ]),
      h('label', { className: 'nsm-field' }, [ h('span', { text: 'Show' }), showSel ])
    ]));
    host.appendChild(h('div', { className: 'row-actions' }, [ save,
      h('button', { className: 'btn ghost', type: 'button', text: 'Whole show…', onClick: function(){ M.ui.openShow = ed.show_key; A.openSettings('matching'); } }) ]));
  }
  /* Fix Match source tabs: All + each backend source; keyless ones are shown disabled with a hint. */
  function sourceTabs(host, sources, current, onPick){
    clear(host);
    var list = [{ id: 'all', label: 'All', available: true }].concat(sources || []);
    list.forEach(function(src){
      var b = h('button', { type: 'button', className: 'btn ghost nsm-src-tab' + (current === src.id ? ' active' : '') + (src.available ? '' : ' unavailable'),
        text: src.label + (src.available ? '' : ' (needs key)'), title: src.hint || '', 'aria-pressed': current === src.id ? 'true' : 'false' });
      b.addEventListener('click', function(){
        if (!src.available) return toast(src.hint || (src.label + ' is not available'));
        onPick(src.id);
      });
      host.appendChild(b);
    });
    return host;
  }
  function fixResultBody(m, extra){
    return [ m.image ? h('img', { src: m.image, alt: '', loading: 'lazy' }) : h('div', { className: 'nsm-noimg', text: (m.title || '?').charAt(0) }),
      h('div', { className: 'nsm-grow' }, [ h('strong', { text: (m.title || '') + (m.year ? ' (' + m.year + ')' : '') }),
        extra ? h('div', { className: 'muted', text: extra }) : null,
        m.summary ? h('div', { className: 'muted nsm-summary', text: String(m.summary).slice(0, 180) }) : h('div', { className: 'muted nsm-summary', text: 'No synopsis from this source' }) ].filter(Boolean)),
      h('span', { className: 'sys-chip', text: m.source_label || m.source }) ];
  }
  function movieFixForm(host, itemId, cur, done){
    var q = input({ type: 'search', value: (cur.title || '') + (cur.year ? ' ' + cur.year : '') });
    var res = h('div', { className: 'nsm-results' });
    var t = input({ value: cur.title || '' }), y = input({ type: 'number', className: 'narrow', value: cur.year || '' });
    var chosen = null;
    t.addEventListener('input', function(){ if (chosen && t.value.trim() !== String(chosen.title || '')) { chosen = null; Array.prototype.forEach.call(res.querySelectorAll('.nsm-result'), function(x){ x.classList.remove('active'); }); } });
    var tabs = h('div', { className: 'nsm-src-tabs', role: 'tablist' }), source = 'all';
    function run(){
      clear(res).appendChild(h('p', { className: 'muted', text: 'Searching…' }));
      api('/admin/match/search', { method: 'POST', body: { kind: cur.type === 'roms' ? 'game' : 'movie', q: q.value, source: source } }).then(function(r){
        clear(res);
        sourceTabs(tabs, r.sources, source, function(id){ source = id; run(); });
        if (r.unavailable) { res.appendChild(h('p', { className: 'muted', text: r.unavailable })); return; }
        (r.results || []).slice(0, 10).forEach(function(m){
          var b = h('button', { type: 'button', className: 'nsm-result' + (chosen && chosen.source === m.source && String(chosen.id) === String(m.id) ? ' active' : ''), onClick: function(){
            chosen = m; t.value = m.title || ''; y.value = m.year || '';
            Array.prototype.forEach.call(res.querySelectorAll('.nsm-result'), function(x){ x.classList.remove('active'); }); b.classList.add('active');
          }}, fixResultBody(m, (m.genres || []).slice(0, 3).join(', ')));
          res.appendChild(b);
        });
        if (!(r.results || []).length) res.appendChild(h('p', { className: 'muted', text: 'No results from this source. Try another source, or type the title and year below.' }));
      });
    }
    q.addEventListener('keydown', function(e){ if (e.key === 'Enter') run(); });
    var save = h('button', { className: 'btn primary', type: 'button', text: 'Save' });
    save.addEventListener('click', function(){
      if (!t.value.trim()) return toast('Title required', { kind: 'bad' });
      busy(save, true, 'Saving…');
      api('/admin/match/movie/' + itemId, { method: 'POST', body: { title: t.value.trim(), year: num(y.value), imdb_id: chosen && chosen.source === 'omdb' ? chosen.id : null, image: chosen && chosen.image ? chosen.image : null, match: chosen ? { source: chosen.source, id: chosen.id, title: chosen.title, year: chosen.year || null, summary: chosen.summary || '', url: chosen.url || null, image: chosen.image || null, page_title: chosen.page_title || null } : null } }).then(function(r){
        busy(save, false);
        if (!r.ok) return toast(r.error || 'Failed', { kind: 'bad' });
        if (r.warning) toast(r.warning, {kind:'warn'});
        toast(cur.type === 'roms' ? 'Game match updated' : 'Movie label updated', { kind: 'ok' });
        A.refreshArtwork().then(function(){done(t.value.trim() + (y.value ? ' (' + y.value + ')' : ''));});
      });
    });
    host.appendChild(h('div', { className: 'cv-search-row' }, [ q, h('button', { className: 'btn', type: 'button', text: 'Search', onClick: run }) ]));
    host.appendChild(tabs);
    host.appendChild(res);
    host.appendChild(h('div', { className: 'nsm-toolbar' }, [ h('label', { className: 'nsm-field grow' }, [ h('span', { text: 'Title' }), t ]), h('label', { className: 'nsm-field' }, [ h('span', { text: 'Year' }), y ]), save ]));
    run();
  }

  /* ======================================================= REPORT DIALOG */
  function reportDialog(target){
    var note = h('textarea', { rows: '3', placeholder: 'What is wrong? e.g. "this is actually S02E05 - Bad Dog"', maxlength: '500' });
    var kids = [ h('p', { className: 'muted', text: 'Currently labelled: ' + target.label }) ];
    var s, e, t, y;
    if (target.kind === 'episode') {
      s = input({ type: 'number', min: '0', className: 'narrow', placeholder: 'S' }); e = input({ type: 'number', min: '0', className: 'narrow', placeholder: 'E' }); t = input({ placeholder: 'correct episode title (optional)' });
      kids.push(h('div', { className: 'nsm-toolbar' }, [ h('label', { className: 'nsm-field' }, [ h('span', { text: 'Season' }), s ]), h('label', { className: 'nsm-field' }, [ h('span', { text: 'Episode' }), e ]), h('label', { className: 'nsm-field grow' }, [ h('span', { text: 'Title' }), t ]) ]));
    } else if (target.kind === 'movie') {
      t = input({ placeholder: 'correct title (optional)' }); y = input({ type: 'number', className: 'narrow', placeholder: 'year' });
      kids.push(h('div', { className: 'nsm-toolbar' }, [ h('label', { className: 'nsm-field grow' }, [ h('span', { text: 'Correct title' }), t ]), h('label', { className: 'nsm-field' }, [ h('span', { text: 'Year' }), y ]) ]));
    }
    kids.push(h('label', { className: 'nsm-field' }, [ h('span', { text: 'Note' }), note ]));
    var send = h('button', { className: 'btn primary', type: 'button', text: 'Send report' });
    kids.push(h('div', { className: 'row-actions' }, [ send ]));
    var m = modal(target.kind === 'show' ? 'Report wrong show match' : 'Report wrong label', kids);
    send.addEventListener('click', function(){
      var sug = {};
      if (s && s.value !== '') sug.season = num(s.value);
      if (e && e.value !== '') sug.episode = num(e.value);
      if (t && t.value.trim()) sug.title = t.value.trim();
      if (y && y.value !== '') sug.year = num(y.value);
      busy(send, true, 'Sending…');
      api('/reports/label', { method: 'POST', body: { item_id: target.item_id || '', show_key: target.show_key || '', kind: target.kind, note: note.value.trim(), suggested: sug } }).then(function(r){
        busy(send, false);
        if (!r.ok) return toast(r.error || 'Could not send report', { kind: 'bad' });
        m.close();
        toast(r.deduped ? 'Thanks - already reported, added your note' : 'Thanks - an admin will fix the label', { kind: 'ok' });
        M.refreshCounts();
      });
    });
  }
  M.reportDialog = reportDialog;

  /* =================================================== DETAIL PAGE HOOKS */
  M.movieButtons = function(item){
    var out = [ h('button', { className: 'btn ghost nsm-report-btn', type: 'button', text: '⚑ Report', title: 'Report a wrong title / year / poster', onClick: function(){
      reportDialog({ kind: 'movie', item_id: item.id, label: (item.title || '') + (item.year ? ' (' + item.year + ')' : '') });
    }}) ];
    if (isAdmin()) out.push(h('button', { className: 'btn ghost', type: 'button', text: 'Fix match', onClick: function(){ M.openMovieFix(item); }}));
    return out;
  };
  M.openShowFix = function(showKey, onSaved){
    if (!A.can('fix_match')) return;
    var body = h('div', {}), dialog = modal('Fix show match', [body], {wide:true});
    renderShowEditor(body, showKey, {close:function(){dialog.close();}, saved:function(result){dialog.close(); if (onSaved) onSaved(result);}});
  };
  M.openMovieFix = function(item, onSaved){
    if (!A.can('fix_match')) return;
    var body = h('div', {});
    var isGame = item.media_kind === 'rom' || item.type === 'roms';
    var m = modal(isGame ? 'Fix game match' : 'Fix movie match', [body], { wide: true });
    movieFixForm(body, item.id, { title: item.title, year: item.year, type: isGame ? 'roms' : 'movies' }, function(){ m.close(); if (onSaved) {onSaved({id:item.id});return;} if (isGame) A.openGame(item); else A.openMovie(item.id); });
  };
  M.editDialog = function(item, showKey, onSaved){
    if (!A.can('metadata_edit')) return;
    var title = input({value:item.title || ''}), year = input({type:'number', value:item.year || ''});
    var synopsis = h('textarea', {rows:'8', maxlength:'20000'}); synopsis.value = item.synopsis || '';
    var upload = input({type:'file', accept:'image/jpeg,image/png,image/webp'});
    var status = h('p', {className:'muted', role:'status'});
    var save = h('button', {className:'btn primary', type:'button', text:'Save changes'});
    var remove = h('button', {className:'btn danger', type:'button', text:'Remove from library'});
    var m = modal('Manual edit', [
      h('label', {className:'nsm-field'}, [h('span', {text:'Title'}), title]),
      h('label', {className:'nsm-field'}, [h('span', {text:'Year'}), year]),
      h('label', {className:'nsm-field'}, [h('span', {text:'Synopsis'}), synopsis]),
      h('label', {className:'nsm-field'}, [h('span', {text:'Upload poster (JPEG, PNG or WebP, up to 8 MB)'}), upload]),
      status, h('div', {className:'row-actions'}, isAdmin()?[save,remove]:[save])
    ], {wide:true});
    function refresh(r){ A.refreshArtwork().then(function(){m.close(); if (onSaved) {onSaved(r);return;} if (showKey) A.openShow(r.show_key || showKey); else if (item.media_kind === 'rom' || item.type === 'roms') A.openGame(item); else A.openMovie(item.id);}); }
    function posterData(){
      var file = upload.files && upload.files[0];
      if (!file) return Promise.resolve(null);
      if (file.size > 8 * 1024 * 1024) return Promise.reject(new Error('Poster must be no larger than 8 MB'));
      return new Promise(function(resolve, reject){ var reader = new FileReader(); reader.onload = function(){resolve(reader.result);}; reader.onerror = function(){reject(new Error('Could not read poster'));}; reader.readAsDataURL(file); });
    }
    save.onclick = function(){
      if (!title.value.trim()) { status.textContent = 'Title required'; return; }
      save.disabled = remove.disabled = true; status.textContent = 'Saving…';
      posterData().then(function(data){ return api('/admin/media/edit', {method:'POST', body:{id:showKey ? null : item.id, show_key:showKey || null, title:title.value.trim(), year:year.value || null, synopsis:synopsis.value, poster_data:data}}); }).then(function(r){
        if (!r.ok) throw new Error(r.error || 'Could not save');
        toast(r.warning || 'Saved', {kind:r.warning ? 'warn' : 'ok'}); refresh(r);
      }).catch(function(error){ status.textContent = error.message; save.disabled = remove.disabled = false; });
    };
    remove.onclick = function(){
      A.confirmDialog('Remove “' + (item.title || '') + '”' + (showKey ? ' and every episode' : '') + ' from the library? Files remain on disk and rescans will keep these entries excluded.', {okText:'Remove from library', danger:true}).then(function(ok){
        if (!ok) return;
        save.disabled = remove.disabled = true;
        return api('/admin/media/edit', {method:'POST', body:{id:showKey ? null : item.id, show_key:showKey || null, remove:true, confirm:'REMOVE'}}).then(function(r){
          if (!r.ok) throw new Error(r.error || 'Could not remove');
          m.close(); if (onSaved) {onSaved({removed:true});return;} A.navigate(item.media_kind === 'rom' ? 'play' : 'home');
        }).catch(function(error){status.textContent = error.message; save.disabled = remove.disabled = false;});
      });
    };
  };
  M.showButtons = function(show, showKey){
    var out = [ h('button', { className: 'btn ghost nsm-report-btn', type: 'button', text: '⚑ Report', title: 'Report a wrong show match or wrong episode labels', onClick: function(){
      reportDialog({ kind: 'show', show_key: showKey, label: show.title || '' });
    }}) ];
    if (isAdmin()) out.push(h('button', { className: 'btn ghost', type: 'button', text: 'Fix matching', onClick: function(){ M.ui.openShow = showKey; A.openSettings('matching'); } }));
    return out;
  };
  M.episodeButton = function(ep, show){
    if (!ep || !ep.id) return null;
    var label = (show && show.title ? show.title + ' · ' : '') + (code(ep.season, ep.episode) || 'unnumbered') + (ep.episode_title ? ' · ' + ep.episode_title : '');
    return h('button', { className: 'btn ghost nsm-ep-report', type: 'button', text: '⚑', title: 'Report wrong episode label', 'aria-label': 'Report wrong episode label', onClick: function(e){
      e.stopPropagation();
      reportDialog({ kind: 'episode', item_id: ep.id, label: label });
    }});
  };

  // app.js may have rendered Settings before this file loaded
  if (A.state.view === 'settings' && /^(ingest|matching|reports)$/.test(A.state.settingsSection || '')) A.render();
  paintBadges();
})();
