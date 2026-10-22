/* Vaultcast unified inbox: messages, invitations, friend requests, library imports, convert requests and
   maintenance in one friendly list. Visiting it clears the account dot; read/unread is kept per item.
   Messages open the threaded conversation (it stays a messenger). Loaded on demand. */
(function(root){
  'use strict';
  var A = null, h, api;
  var filter = 'all', selected = {}, selecting = false, items = [], groups = {}, unread = {}, loading = false, hasMore = false, owner = null, admin = false;
  var running = {}; /* task -> {job, text, state} for maintenance items started here */

  var ICONS = {
    all: 'M4 13h4l2 3h4l2-3h4M5 5h14l1 8v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-5z',
    message: 'M4 5h16v11H8l-4 4z',
    invite: 'M3 8l9 6 9-6M4 6h16v12H4z',
    friend: 'M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm-6 9a6 6 0 0 1 12 0M17 8v6M14 11h6',
    library: 'M4 4h4v16H4zM10 4h4v16h-4zM16 5l3.5 1 -3 14 -3.5-1z',
    request: 'M12 3v12m0 0l-4-4m4 4l4-4M5 19h14',
    system: 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8zm0-5v3m0 12v3M3 12h3m12 0h3M5.6 5.6l2.1 2.1m8.6 8.6l2.1 2.1M5.6 18.4l2.1-2.1m8.6-8.6l2.1-2.1',
    drop: 'M12 3v12m0 0l-4-4m4 4l4-4M4 17v3h16v-3',
    achievement: 'M8 21h8m-4-4v4M7 4h10v5a5 5 0 0 1-10 0zM7 6H4a3 3 0 0 0 3 4m10-4h3a3 3 0 0 1-3 4',
    maintenance: 'M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.4-.6-.6-2.4z'
  };
  var FILTERS = [['all', 'All'], ['message', 'Messages'], ['invite', 'Invites'], ['library', 'Library'], ['request', 'Requests'], ['achievement', 'Achievements'], ['system', 'System']];

  function svg(name, cls){
    var ns = 'http://www.w3.org/2000/svg', s = document.createElementNS(ns, 'svg'), p = document.createElementNS(ns, 'path');
    s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('aria-hidden', 'true'); s.setAttribute('class', cls || 'inbox-svg');
    p.setAttribute('d', ICONS[name] || ICONS.all); s.appendChild(p); return s;
  }
  function uid(){ return A.state.user && A.state.user.role !== 'guest' ? A.state.user.id : null; }
  function ago(ts){
    var s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
    if (s < 60) return 'Just now'; if (s < 3600) return Math.floor(s / 60) + ' min ago'; if (s < 86400) return Math.floor(s / 3600) + ' h ago';
    if (s < 7 * 86400) return Math.floor(s / 86400) + ' d ago';
    return new Date(ts * 1000).toLocaleDateString(undefined, { day:'numeric', month:'short', year: s > 300 * 86400 ? 'numeric' : undefined });
  }
  function iconFor(it){ return it.subtype === 'friend' ? 'friend' : it.subtype === 'maintenance' ? 'maintenance' : it.subtype === 'drop' ? 'drop' : it.type; }
  function kindLabel(it){
    return { dm:'Direct message', mail:'Private message', friend:'Friend request', cinema:'Watch together', game:'Game invite', play:'Play', maintenance:'Maintenance', convert:'Convert request', media:'Media request', media_update:'Your request', ingest:'Ready to import', drop:'Drop' }[it.subtype] || (it.type === 'achievement' ? 'Achievement' : '') || (groups[it.type] || '');
  }

  /* ---------- the dot ---------- */
  function setDot(isNew){ A.state.inboxOwner = uid(); A.state.inboxNew = !!isNew; document.dispatchEvent(new Event('vaultcast-notices-changed')); }
  function markSeen(){ setDot(false); api('/inbox/seen', { method:'POST', body:{} }).catch(function(){}); }

  /* ---------- actions ---------- */
  function openPath(path){ history.pushState({}, '', (A.base || '/') + path); A.applyRoute().then(function(){ A.render(); A.replaceNav(); }); }
  function markRead(ids, read){
    ids.forEach(function(id){ var it = items.find(function(x){ return x.id === id; }); if (it) it.read = read; });
    return api('/inbox/read', { method:'POST', body:{ ids:ids, read:read } }).catch(function(){});
  }
  function openItem(it){
    if (!it.read) markRead([it.id], true);
    var a = it.action || {};
    if (a.kind === 'chat' && window.VaultcastChat && window.VaultcastChat.open) return window.VaultcastChat.open(a.room, a.mode === 'mail' ? 'mail' : 'chat');
    if (a.kind === 'settings') return A.openSettings(a.section);
    if (a.kind === 'path' && a.path) return openPath(a.path);
    if (a.kind === 'maintenance') return A.openSettings('maintenance');
  }
  function primaryLabel(it){
    var a = it.action || {};
    if (a.kind === 'chat') return 'Open conversation';
    if (it.subtype === 'friend') return 'View request';
    if (it.subtype === 'cinema' || it.subtype === 'game') return 'Join';
    if (it.subtype === 'convert' || it.subtype === 'media') return 'Review';
    if (it.subtype === 'media_update') return 'View request';
    if (it.subtype === 'ingest') return 'Open import inbox';
    return 'Open';
  }
  function remove(ids){
    if (!ids.length) return Promise.resolve();
    return api('/inbox/delete', { method:'POST', body:{ ids:ids } }).then(function(j){
      if (!j || !j.ok) throw new Error((j && j.error) || 'Could not delete');
      items = items.filter(function(x){ return ids.indexOf(x.id) < 0; }); ids.forEach(function(id){ delete selected[id]; });
      A.toast(ids.length === 1 ? 'Removed from your inbox' : ids.length + ' items removed from your inbox');
    });
  }

  /* Maintenance: run the exact task, then follow its progress until it finishes. */
  function runTask(it, paint){
    var task = (it.payload && it.payload.task) || (it.action && it.action.task);
    if (!task || running[task] && (running[task].state === 'running')) return;
    running[task] = { state:'running', text:'Starting…', pct:null };
    paint();
    api('/admin/maintenance', { method:'POST', body:{ task:task } }).then(function(j){
      var job = j && j.job; if (!job || !job.id) throw new Error((j && j.error) || 'The task could not start.');
      if (job.status === 'failed') throw new Error(job.error || 'The task could not start.');
      running[task].job = job.id; follow(task, paint);
    }).catch(function(e){ running[task] = { state:'failed', text:e.message || 'The task could not start.' }; paint(); });
  }
  function follow(task, paint){
    var r = running[task]; if (!r || !r.job) return;
    api('/admin/maintenance/' + r.job).then(function(j){
      var job = j && j.job; if (!job) throw new Error('The task status is unavailable.');
      var p = job.progress || {};
      if (job.status === 'complete') { running[task] = { state:'done', text:'Done. Everything is up to date.' }; paint(); setTimeout(function(){ load(true); }, 1200); return; }
      if (job.status === 'failed' || job.status === 'cancelled') { running[task] = { state:'failed', text:job.status === 'cancelled' ? 'Cancelled.' : (job.error || 'The task failed. See Settings → Maintenance for details.') }; paint(); return; }
      r.text = job.status === 'queued' ? 'Queued…' : (p.phase ? p.phase + ' · ' : 'Running · ') + (p.total ? (p.done || 0) + ' of ' + p.total : 'working');
      r.pct = p.total ? Math.min(100, Math.round(100 * (p.done || 0) / p.total)) : null;
      paint(); setTimeout(function(){ if (running[task] === r) follow(task, paint); }, 2000);
    }).catch(function(e){ running[task] = { state:'failed', text:e.message || 'Lost track of the task.' }; paint(); });
  }

  /* ---------- rendering ---------- */
  var hostEl = null;
  function avatarFor(it){
    var a = it.actor;
    if (a && a.avatar) return h('img', { className:'inbox-avatar', src:a.avatar, alt:'', loading:'lazy' });
    if (a && a.name) return h('span', { className:'inbox-avatar inbox-initial', text:a.name.trim().charAt(0).toUpperCase() || '?' });
    var w = h('span', { className:'inbox-avatar inbox-type-icon inbox-tone-' + it.type }); w.appendChild(svg(iconFor(it))); return w;
  }
  function itemEl(it, repaint){
    var task = it.subtype === 'maintenance' && ((it.payload && it.payload.task) || (it.action && it.action.task));
    var run = task ? running[task] : null;
    var check = h('input', { type:'checkbox', className:'inbox-check', 'aria-label':'Select ' + it.title, checked:!!selected[it.id], onChange:function(e){ if (e.target.checked) selected[it.id] = true; else delete selected[it.id]; repaint(); } });
    var actions = [];
    if (task && admin) {
      actions.push(h('button', { className:'btn primary', type:'button', disabled:!!(run && run.state === 'running'), text:run && run.state === 'running' ? 'Running…' : (run && run.state === 'failed' ? 'Try again' : 'Run task'), onClick:function(e){ e.stopPropagation(); runTask(it, repaint); } }));
      actions.push(h('button', { className:'btn ghost', type:'button', text:'Details', onClick:function(e){ e.stopPropagation(); A.openSettings('maintenance'); } }));
    } else actions.push(h('button', { className:'btn primary', type:'button', text:primaryLabel(it), onClick:function(e){ e.stopPropagation(); openItem(it); } }));
    actions.push(h('button', { className:'btn ghost inbox-mark', type:'button', text:it.read ? 'Mark unread' : 'Mark read', onClick:function(e){ e.stopPropagation(); markRead([it.id], !it.read).then(repaint); repaint(); } }));
    actions.push(h('button', { className:'icon-btn inbox-del', type:'button', 'aria-label':'Delete', title:'Delete from my inbox', text:'×', onClick:function(e){ e.stopPropagation(); remove([it.id]).then(repaint).catch(function(err){ A.toast(err.message, { kind:'bad' }); }); } }));
    var meta = h('div', { className:'inbox-meta' }, [h('span', { className:'inbox-kind inbox-tone-' + it.type, text:kindLabel(it) }), h('span', { className:'muted', text:ago(it.created_at) }), it.new ? h('span', { className:'inbox-new-pill', text:'New' }) : null]);
    var progress = run ? h('div', { className:'inbox-progress inbox-progress-' + run.state, role:'status' }, [
      run.state === 'running' ? h('div', { className:'inbox-bar' + (run.pct == null ? ' indeterminate' : '') }, [h('span', { style:run.pct == null ? null : 'width:' + run.pct + '%' })]) : null,
      h('small', { text:run.text })]) : null;
    var row = h('article', { className:'inbox-item' + (it.read ? ' is-read' : ' is-unread') + (selected[it.id] ? ' is-selected' : ''), tabindex:'0', 'data-type':it.type,
      onClick:function(e){ if (e.target.closest('button,input,a')) return; if (selecting) { if (selected[it.id]) delete selected[it.id]; else selected[it.id] = true; repaint(); return; } if (!task) openItem(it); },
      onKeydown:function(e){ if (e.key === 'Enter' && e.target === e.currentTarget && !task) openItem(it); } }, [
      selecting ? check : null,
      avatarFor(it),
      h('div', { className:'inbox-main' }, [meta, h('strong', { className:'inbox-title', text:it.title }), it.body ? h('p', { className:'inbox-body', text:it.body }) : null, progress, h('div', { className:'inbox-actions' }, actions)]),
      it.read ? null : h('span', { className:'inbox-unread-dot', 'aria-label':'Unread' })
    ]);
    return row;
  }
  function emptyEl(){
    var copy = { all:['You’re all caught up', 'Messages, invitations and anything that needs you will land here.'], message:['No messages', 'When a friend writes to you, the conversation shows up here.'], invite:['No invitations', 'Game, chess, watch-together and friend requests appear here.'], library:['Nothing to import', 'Finished downloads ready for your library show up here.'], request:['No requests', 'Convert requests from your viewers appear here.'], system:['Nothing needs attention', 'Maintenance and device notices appear here.'] }[filter] || ['Empty', ''];
    var icon = h('div', { className:'inbox-empty-art' }); icon.appendChild(svg(filter === 'all' ? 'all' : filter, 'inbox-svg big'));
    return h('div', { className:'inbox-empty' }, [icon, h('h3', { text:copy[0] }), h('p', { className:'muted', text:copy[1] })]);
  }
  function paint(){
    if (!hostEl || !hostEl.isConnected) return;
    var visible = items.filter(function(it){ return filter === 'all' || it.type === filter; });
    var selCount = Object.keys(selected).length;
    var chips = h('div', { className:'inbox-filters', role:'tablist', 'aria-label':'Filter inbox' }, FILTERS.filter(function(f){ return f[0] === 'all' || groups[f[0]]; }).map(function(f){
      var n = f[0] === 'all' ? Object.keys(unread).reduce(function(s, k){ return s + (unread[k] || 0); }, 0) : (unread[f[0]] || 0);
      var b = h('button', { type:'button', role:'tab', className:'inbox-chip' + (filter === f[0] ? ' active' : ''), 'aria-selected':filter === f[0] ? 'true' : 'false', onClick:function(){ filter = f[0]; selected = {}; load(false); } }, [h('span', { text:f[1] }), n ? h('span', { className:'inbox-chip-count', text:String(n) }) : null]);
      b.insertBefore(svg(f[0]), b.firstChild); return b;
    }));
    var tools = h('div', { className:'inbox-tools' }, selecting ? [
      h('label', { className:'inbox-all' }, [h('input', { type:'checkbox', checked:visible.length > 0 && visible.every(function(it){ return selected[it.id]; }), onChange:function(e){ selected = {}; if (e.target.checked) visible.forEach(function(it){ selected[it.id] = true; }); paint(); } }), h('span', { text:selCount ? selCount + ' selected' : 'Select all' })]),
      h('button', { className:'btn ghost', type:'button', disabled:!selCount, text:'Mark read', onClick:function(){ markRead(Object.keys(selected).map(Number), true).then(paint); paint(); } }),
      h('button', { className:'btn danger', type:'button', disabled:!selCount, text:'Delete' + (selCount ? ' (' + selCount + ')' : ''), onClick:function(){
        var ids = Object.keys(selected).map(Number);
        (A.confirmDialog ? A.confirmDialog('Delete ' + ids.length + (ids.length === 1 ? ' item' : ' items') + ' from your inbox? Conversations themselves are kept.', { okText:'Delete', danger:true }) : Promise.resolve(confirm('Delete these items?'))).then(function(ok){ if (ok) remove(ids).then(function(){ selecting = false; paint(); }).catch(function(e){ A.toast(e.message, { kind:'bad' }); }); });
      } }),
      h('button', { className:'btn ghost', type:'button', text:'Done', onClick:function(){ selecting = false; selected = {}; paint(); } })
    ] : [
      h('button', { className:'btn ghost', type:'button', text:'Select', disabled:!visible.length, onClick:function(){ selecting = true; paint(); } }),
      h('button', { className:'btn ghost', type:'button', text:'Mark all read', disabled:!visible.some(function(it){ return !it.read; }), onClick:function(){ markRead(visible.filter(function(it){ return !it.read; }).map(function(it){ return it.id; }), true).then(function(){ load(false); }); } }),
      h('button', { className:'btn ghost', type:'button', text:'New message', onClick:function(){ if (window.VaultcastChat) window.VaultcastChat.open('', 'mail'); } })
    ]);
    var list = h('div', { className:'inbox-list', 'aria-live':'polite' }, loading && !items.length ? [h('p', { className:'muted inbox-loading', text:'Loading your inbox…' })] : (visible.length ? visible.map(function(it){ return itemEl(it, paint); }) : [emptyEl()]));
    if (hasMore && visible.length) list.appendChild(h('button', { className:'btn ghost inbox-more', type:'button', text:'Show older', onClick:function(){ more(); } }));
    var head = h('header', { className:'inbox-head' }, [h('div', {}, [h('h1', { text:'Inbox' }), h('p', { className:'muted', text:'Messages, invitations and everything that needs you, in one place.' })])]);
    hostEl.replaceChildren(h('section', { className:'inbox-page' }, [head, chips, tools, list]));
  }
  function load(force){
    var me = uid(); if (!me) return;
    loading = true; paint();
    api('/inbox?type=' + encodeURIComponent(filter)).then(function(j){
      if (uid() !== me) return;
      if (!j || !j.ok) throw new Error((j && j.error) || 'Could not load your inbox');
      items = j.items || []; groups = j.groups || {}; unread = j.unread || {}; hasMore = !!j.has_more; admin = !!j.admin;
      if (filter !== 'all' && !groups[filter]) filter = 'all';
    }).catch(function(e){ A.toast(e.message || 'Could not load your inbox', { kind:'bad' }); }).then(function(){ loading = false; paint(); if (force) markSeen(); });
  }
  function more(){
    var last = items[items.length - 1]; if (!last) return;
    api('/inbox?type=' + encodeURIComponent(filter) + '&before=' + last.created_at + '&before_id=' + last.id).then(function(j){ if (j && j.ok) { var have = {}; items.forEach(function(x){ have[x.id] = 1; }); items = items.concat((j.items || []).filter(function(x){ return !have[x.id]; })); hasMore = !!j.has_more; paint(); } }).catch(function(){});
  }

  function render(host){
    hostEl = host;
    if (!uid()) { host.replaceChildren(A.emptyState('Sign in to see your inbox', 'Your messages and invitations are waiting.', [h('button', { className:'btn primary', type:'button', text:'Sign in', onClick:function(){ A.navigate('login'); } })])); return; }
    if (owner !== uid()) { owner = uid(); items = []; selected = {}; selecting = false; running = {}; filter = 'all'; }
    if (A.state.inboxPane === 'mail' && window.VaultcastChat) {
      var back = h('button', { className:'btn ghost inbox-back', type:'button', text:'← Back to inbox', onClick:function(){ A.state.inboxPane = null; history.pushState({}, '', (A.base || '/') + 'inbox'); A.render(); } });
      host.replaceChildren(h('section', { className:'inbox-page inbox-thread' }, [back, window.VaultcastChat.view('mail')]));
      return;
    }
    paint(); load(true);
  }

  root.VaultcastInbox = { init:function(app){ A = app; h = app.h; api = app.api; }, render:render, markSeen:markSeen };
})(window);
