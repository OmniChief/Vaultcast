/* Vaultcast achievements: the Play → Achievements page (progress, earned dates, rarity, category filter,
   profile showcase), the celebratory toast when something is unlocked, and the admin settings panel.
   Loaded on demand. All text goes through A.h 'text' (textContent); nothing is built from HTML strings. */
(function(root){
  'use strict';
  var A = null, h, api;
  var page = null;          // { host, data, filter, editing, picks }
  var celebrating = false;

  function str(v){ return v == null ? '' : String(v); }
  function num(v){ v = Number(v); return isFinite(v) ? v : 0; }
  function err(j, fallback){ return j && j.error ? String(j.error) : (j && j.message ? String(j.message) : fallback); }
  function ok(j){ if (!j || !j.ok) throw j || {}; return j; }
  function tierClass(t){ return 'vca-tier-' + (['bronze','silver','gold','platinum'].indexOf(t) >= 0 ? t : 'bronze'); }
  function reduced(){
    return document.documentElement.getAttribute('data-motion') === 'reduced' || document.body.classList.contains('reduce-motion') ||
      !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }
  function day(ts){ ts = num(ts); if (!ts) return ''; try { return new Date(ts * 1000).toLocaleDateString(undefined, { day:'numeric', month:'short', year:'numeric' }); } catch (e) { return ''; } }
  function pct(v){ v = num(v); return v <= 0 ? '' : (v < 1 ? '<1%' : (v >= 99.5 ? '100%' : Math.round(v) + '%')); }
  function empty(t, d, actions){ return A.emptyState ? A.emptyState(t, d, actions) : h('p', { className:'muted', text:t }); }
  function button(label, fn, cls, attrs){ return h('button', Object.assign({ type:'button', className:'btn ' + (cls || 'ghost'), text:label, onClick:fn }, attrs || {})); }

  /* ---------------------------------------------------------------- page */
  function render(host){
    page = { host:host, data:null, filter:page && page.host === host ? page.filter : 'all', editing:false, picks:[] };
    host.classList.add('vca-page');
    host.replaceChildren(A.pendingContent ? A.pendingContent({ text:'Loading achievements…' }) : h('p', { className:'muted', text:'Loading achievements…' }));
    var mine = page;
    api('/achievements').then(ok).then(function(j){
      if (page !== mine || !host.isConnected) return;
      mine.data = j; paint();
    }).catch(function(j){
      if (page !== mine || !host.isConnected) return;
      host.replaceChildren(empty('Achievements could not load', err(j, 'Check the connection and try again.'), [button('Retry', function(){ render(host); }, 'primary')]));
    });
  }
  function paint(){
    var p = page; if (!p || !p.data || !p.host.isConnected) return;
    var d = p.data, host = p.host;
    if (!d.enabled) { host.replaceChildren(empty('Achievements are turned off', 'An administrator has switched achievements off on this server.')); return; }
    var list = Array.isArray(d.achievements) ? d.achievements : [];
    var cats = d.categories || {}, sum = d.summary || {}, tiers = sum.tiers || {};
    var head = h('header', { className:'vca-head' }, [
      h('div', { className:'vca-head-text' }, [
        h('h1', { text:'Achievements' }),
        h('p', { className:'muted', text:num(sum.earned) + ' of ' + num(sum.total || list.length) + ' unlocked' })
      ]),
      h('div', { className:'vca-tier-summary', 'aria-label':'Earned by tier' }, ['bronze','silver','gold','platinum'].map(function(t){
        return h('span', { className:'vca-tier-count ' + tierClass(t) }, [h('span', { className:'vca-dot', 'aria-hidden':'true' }), h('strong', { text:String(num(tiers[t])) }), h('span', { className:'muted', text:' ' + str((d.tiers || {})[t] || t) })]);
      })),
      h('div', { className:'vca-overall', role:'progressbar', 'aria-valuemin':'0', 'aria-valuemax':String(num(sum.total) || 1), 'aria-valuenow':String(num(sum.earned)), 'aria-label':'Overall progress' }, [
        h('span', { style:'width:' + Math.round(100 * num(sum.earned) / Math.max(1, num(sum.total))) + '%' })
      ])
    ]);
    host.replaceChildren(head, showcaseBox(d, list), filters(cats, list), grid(list));
  }
  function showcaseBox(d, list){
    var p = page, byId = {}; list.forEach(function(a){ byId[a.id] = a; });
    var chosen = (p.editing ? p.picks : (Array.isArray(d.showcase) ? d.showcase : [])).filter(function(id){ return byId[id]; });
    var max = num(d.showcase_max) || 3;
    var earned = list.filter(function(a){ return a.earned; }).length;
    var row = h('div', { className:'vca-showcase-row' }, chosen.length ? chosen.map(function(id){ var a = byId[id];
      return h('span', { className:'vca-chip ' + tierClass(a.tier), title:str(a.description) }, [h('span', { className:'vca-chip-icon', 'aria-hidden':'true', text:str(a.icon) }), h('span', { text:str(a.name) })]);
    }) : [h('span', { className:'muted', text:p.editing ? 'Tap earned achievements below to add them (up to ' + max + ').' : (earned ? 'No badges chosen yet.' : 'Earn an achievement to show it on your profile.') })]);
    var actions;
    if (p.editing) actions = [
      button('Save showcase', function(e){ var b = e.currentTarget; b.disabled = true;
        api('/achievements/showcase', { method:'POST', body:{ ids:p.picks } }).then(ok).then(function(j){ d.showcase = Array.isArray(j.showcase) ? j.showcase : []; p.editing = false; A.toast('Showcase saved', { kind:'ok' }); paint(); })
          .catch(function(j){ b.disabled = false; A.toast(err(j, 'The showcase could not be saved.'), { kind:'bad' }); });
      }, 'primary'),
      button('Cancel', function(){ p.editing = false; paint(); })
    ];
    else actions = earned ? [button(chosen.length ? 'Change showcase' : 'Choose badges', function(){ p.editing = true; p.picks = (Array.isArray(d.showcase) ? d.showcase : []).slice(0, max); paint(); })] : [];
    return h('section', { className:'vca-showcase-panel' + (p.editing ? ' editing' : '') }, [
      h('div', { className:'vca-showcase-head' }, [h('h2', { text:'Profile showcase' }), h('div', { className:'vca-actions' }, actions)]),
      row,
      d.social === false ? h('p', { className:'muted small', text:'Your profile is not shown to other people, so your showcase stays private.' }) : h('p', { className:'muted small', text:'Shown on your profile to people who can see it (your Play privacy settings apply).' })
    ]);
  }
  function filters(cats, list){
    var p = page;
    var opts = [['all', 'All'], ['earned', 'Earned'], ['progress', 'In progress']].concat(Object.keys(cats).map(function(k){ return [k, str(cats[k])]; }));
    return h('div', { className:'vca-filters', role:'toolbar', 'aria-label':'Filter achievements' }, opts.map(function(o){
      var n = list.filter(function(a){ return match(a, o[0]); }).length;
      return h('button', { type:'button', className:'vca-filter' + (p.filter === o[0] ? ' active' : ''), 'aria-pressed':p.filter === o[0] ? 'true' : 'false', onClick:function(){ p.filter = o[0]; paint(); } }, [h('span', { text:o[1] }), h('span', { className:'vca-filter-n', text:String(n) })]);
    }));
  }
  function match(a, f){
    if (f === 'all') return true;
    if (f === 'earned') return !!a.earned;
    if (f === 'progress') return !a.earned && !a.hidden && num(a.progress) > 0;
    return a.category === f;
  }
  function grid(list){
    var p = page, shown = list.filter(function(a){ return match(a, p.filter); });
    if (!shown.length) return empty(p.filter === 'earned' ? 'Nothing unlocked here yet' : 'Nothing to show', p.filter === 'earned' ? 'Watch, play and join in: achievements unlock as you go.' : 'Try another filter.');
    return h('div', { className:'vca-grid' }, shown.map(card));
  }
  function card(a){
    var p = page, picked = p.editing && p.picks.indexOf(a.id) >= 0;
    var bits = [
      h('div', { className:'vca-icon', 'aria-hidden':'true', text:str(a.icon) || '🏅' }),
      h('div', { className:'vca-body' }, [
        h('div', { className:'vca-title-row' }, [h('h3', { text:str(a.name) }), h('span', { className:'vca-tier ' + tierClass(a.tier), text:str(a.tier_label) })]),
        h('p', { className:'vca-desc', text:str(a.description) }),
        a.earned ? h('p', { className:'vca-earned', text:'Unlocked ' + day(a.earned_at) }) :
          (num(a.progress) > 0 && str(a.progress_text) ? h('div', { className:'vca-progress' }, [
            h('div', { className:'vca-bar', role:'progressbar', 'aria-valuemin':'0', 'aria-valuemax':'100', 'aria-valuenow':String(Math.round(num(a.progress) * 100)), 'aria-label':str(a.name) + ' progress' }, [h('span', { style:'width:' + Math.max(2, Math.round(num(a.progress) * 100)) + '%' })]),
            h('span', { className:'muted small', text:str(a.progress_text) })
          ]) : h('p', { className:'muted small', text:a.hidden ? 'Secret' : 'Not started yet' })),
        h('p', { className:'vca-meta muted small', text:[str(a.category_label), pct(a.rarity) ? pct(a.rarity) + ' of people have this' : 'Nobody has this yet'].filter(Boolean).join(' · ') })
      ])
    ];
    var cls = 'vca-card ' + tierClass(a.tier) + (a.earned ? ' earned' : '') + (a.hidden ? ' secret' : '') + (picked ? ' picked' : '');
    if (p.editing && a.earned) {
      return h('button', { type:'button', className:cls + ' pickable', 'aria-pressed':picked ? 'true' : 'false', onClick:function(){
        var i = p.picks.indexOf(a.id), max = num(p.data.showcase_max) || 3;
        if (i >= 0) p.picks.splice(i, 1); else if (p.picks.length < max) p.picks.push(a.id); else { A.toast('Choose up to ' + max + ' badges', { kind:'bad' }); return; }
        paint();
      } }, bits);
    }
    return h('article', { className:cls }, bits);
  }

  /* ---------------------------------------------------------------- celebration */
  function stack(){
    var s = document.getElementById('vca-toasts');
    if (!s) { s = h('div', { id:'vca-toasts', className:'vca-toasts', role:'status', 'aria-live':'polite' }); document.body.appendChild(s); }
    return s;
  }
  function showToast(a, delay){
    setTimeout(function(){
      var still = reduced();
      var el = h('button', { type:'button', className:'vca-toast ' + tierClass(a.tier) + (still ? ' still' : ''), onClick:function(){ dismiss(); if (A.goPlayTab) A.goPlayTab('achievements'); } }, [
        still ? null : h('span', { className:'vca-sparkles', 'aria-hidden':'true' }, [h('i'), h('i'), h('i'), h('i'), h('i'), h('i')]),
        h('span', { className:'vca-toast-icon', 'aria-hidden':'true', text:str(a.icon) || '🏅' }),
        h('span', { className:'vca-toast-text' }, [
          h('small', { text:'Achievement unlocked · ' + str(a.tier_label || 'Bronze') }),
          h('strong', { text:str(a.name) }),
          h('span', { className:'muted', text:str(a.description) })
        ])
      ]);
      var timer = setTimeout(dismiss, 7000);
      function dismiss(){ clearTimeout(timer); el.classList.add('leaving'); setTimeout(function(){ if (el.parentNode) el.parentNode.removeChild(el); }, still ? 0 : 250); }
      stack().appendChild(el);
    }, delay);
  }
  /** Show anything earned but not yet celebrated, once, then tell the server. */
  function celebrate(){
    if (celebrating || !A.state.user || A.state.user.role === 'guest') return;
    celebrating = true;
    var owner = A.state.user.id;
    api('/achievements/pending').then(ok).then(function(j){
      if (!A.state.user || A.state.user.id !== owner) return;
      var items = Array.isArray(j.items) ? j.items : [];
      if (!items.length) return;
      var ids = items.map(function(a){ return a.id; });
      items.slice(0, 4).forEach(function(a, i){ showToast(a, i * 900); });
      if (items.length > 4) setTimeout(function(){ A.toast('…and ' + (items.length - 4) + ' more. See Play → Achievements.'); }, 4 * 900);
      // Refresh the page if it is open, so the new unlocks show straight away.
      if (page && page.host && page.host.isConnected && !page.editing) render(page.host);
      return api('/achievements/toasted', { method:'POST', body:{ ids:ids } });
    }).catch(function(){}).then(function(){ celebrating = false; });
  }

  /* ---------------------------------------------------------------- admin */
  function adminPanel(host){
    host.replaceChildren(A.pendingContent ? A.pendingContent({ text:'Loading achievement settings…' }) : h('p', { className:'muted', text:'Loading…' }));
    api('/admin/achievements/settings').then(ok).then(function(j){ if (host.isConnected) paintAdmin(host, j); })
      .catch(function(j){ host.replaceChildren(empty('Settings could not load', err(j, 'Check the connection and try again.'), [button('Retry', function(){ adminPanel(host); }, 'primary')])); });
  }
  function paintAdmin(host, j){
    var st = j.settings || {}, msg = h('p', { className:'muted small', role:'status' });
    var toggle = h('input', { type:'checkbox', checked:!!st.enabled, onChange:function(){
      toggle.disabled = true;
      api('/admin/achievements/settings', { method:'POST', body:{ enabled:toggle.checked } }).then(ok).then(function(r){
        if (A.state.user && A.state.user.achievements) A.state.user.achievements.enabled = !!(r.settings && r.settings.enabled);
        A.toast(toggle.checked ? 'Achievements are on' : 'Achievements are off', { kind:'ok' }); paintAdmin(host, r);
      }).catch(function(r){ toggle.checked = !toggle.checked; toggle.disabled = false; A.toast(err(r, 'Could not save.'), { kind:'bad' }); });
    } });
    var run = button('Count past activity now', function(){
      run.disabled = true; msg.textContent = 'Starting…';
      api('/admin/achievements/settings', { method:'POST', body:{ action:'backfill' } }).then(function(r){
        var job = r && r.job;
        if (!job || job.status === 'failed') throw r || {};
        msg.textContent = 'Started in the Task centre. People get their past achievements quietly, with one Inbox note each.';
      }).catch(function(r){ run.disabled = false; msg.textContent = err(r, 'The task could not start. Another task may be running; try again shortly.'); });
    }, 'ghost', { disabled:!st.enabled });
    host.replaceChildren(
      h('p', { className:'muted', text:'Achievements and badges reward watching, Live TV, games, quizzes, friends and requests. Everything is counted on this server from activity Vaultcast already records; nothing is sent anywhere.' }),
      h('label', { className:'check-label' }, [toggle, ' Achievements and badges']),
      h('p', { className:'muted small', text:'When off, the Achievements page, toasts, Inbox items and profile showcases are hidden and no progress is recorded. Earned achievements are kept.' }),
      h('div', { className:'vca-admin-stats' }, [
        h('span', { text:num(j.total) + ' achievements available' }),
        h('span', { text:num(j.earned) + ' earned by ' + num(j.people) + (num(j.people) === 1 ? ' person' : ' people') })
      ]),
      h('div', { className:'vca-actions' }, [run]),
      h('p', { className:'muted small', text:'Past films, episodes, games, friends, reviews and requests count once this has run. Each person also catches up automatically the first time they open Achievements.' }),
      msg
    );
  }

  root.VaultcastAchievements = {
    init:function(app){ A = app; h = app.h; api = app.api; },
    render:render, celebrate:celebrate, adminPanel:adminPanel
  };
})(window);
