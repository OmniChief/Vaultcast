/* Vaultcast smart collections: rule builder with live preview, the Collections page shelf and Home rows (loaded on demand). */
(function(root){
  'use strict';
  var A, h, api, meta = null;
  var OPS = {
    kind:[['is','is']], genre:[['is','is'],['is_not','is not']], person:[['contains','includes'],['is_not','does not include']],
    year:[['is','is'],['gte','from'],['lte','up to'],['between','between']], decade:[['is','is'],['is_not','is not']],
    rating:[['lte','up to'],['gte','at least'],['is','is']], runtime:[['lte','up to'],['gte','at least'],['between','between']],
    added:[['is','within']], watch:[['is','is']], library:[['is','is'],['is_not','is not']], community:[['gte','at least'],['lte','up to']],
    resolution:[['gte','at least'],['is','exactly'],['lte','up to']], codec:[['is','is'],['is_not','is not']]
  };
  var ICONS = { kind:'🎬', genre:'🎭', person:'🧑‍🎤', year:'📅', decade:'🕰️', rating:'🛡️', runtime:'⏱️', added:'🆕', watch:'👀', library:'📚', community:'⭐', resolution:'🖥️', codec:'🎞️' };
  function el(t, a, k){ return h(t, a || {}, k || []); }
  function sel(options, value, label){ var s = el('select', { 'aria-label':label || '' }, options.map(function(o){ return el('option', { value:String(o[0]), text:o[1] }); })); if (value != null) s.value = String(value); if (s.selectedIndex < 0 && s.options.length) s.selectedIndex = 0; return s; }
  function inp(type, value, attrs){ var i = el('input', Object.assign({ type:type }, attrs || {})); i.value = value == null ? '' : String(value); return i; }
  function friendly(j, f){ return (j && (j.error || j.message)) || f || 'Something went wrong. Please try again.'; }
  function loadMeta(){ return meta ? Promise.resolve(meta) : api('/smart-collections').then(function(j){ if (j && j.ok) meta = j; return j; }); }

  function valueInput(field, op, value){
    var c = (meta && meta.choices) || {};
    var v = Array.isArray(value) ? value : [value == null ? '' : value, ''];
    if (field === 'kind') return [sel([['movies','Films'],['shows','TV shows']], v[0])];
    if (field === 'watch') return [sel([['unwatched','Not watched yet'],['in_progress','In progress'],['watched','Watched']], v[0])];
    if (field === 'rating') return [sel([[1,'U / G'],[2,'PG'],[3,'12'],[4,'15'],[5,'18'],[0,'Not rated']], v[0] === '' ? 2 : v[0])];
    if (field === 'resolution') return [sel([[480,'SD (480p)'],[720,'HD (720p)'],[1080,'Full HD (1080p)'],[2160,'4K (2160p)']], v[0] === '' ? 1080 : v[0])];
    if (field === 'library') return [sel((c.libraries || []).map(function(l){ return [l.value, l.label]; }), v[0])];
    if (field === 'codec') return [sel((c.codecs || ['h264','hevc']).map(function(x){ return [x, x.toUpperCase()]; }), v[0])];
    if (field === 'genre') { var list = el('datalist', { id:'smart-genres' }, (c.genres || []).map(function(g){ return el('option', { value:g }); })); var gi = inp('text', v[0], { list:'smart-genres', placeholder:'e.g. comedy' }); gi._extra = list; return [gi]; }
    if (field === 'person') return [inp('text', v[0], { placeholder:'e.g. a favourite actor' })];
    if (field === 'decade') return [sel([1950,1960,1970,1980,1990,2000,2010,2020].map(function(d){ return [d, d + 's']; }), v[0] || 2000)];
    var ph = field === 'added' ? 'days' : field === 'runtime' ? 'minutes' : field === 'community' ? '0–10' : 'year';
    var a = inp('number', v[0], { placeholder:ph, min:0 });
    if (op === 'between') return [a, inp('number', v[1], { placeholder:ph, min:0 })];
    return [a];
  }

  function ruleRow(rule, onChange, onRemove){
    var fields = Object.keys((meta && meta.fields) || {}).map(function(k){ return [k, (ICONS[k] ? ICONS[k] + ' ' : '') + meta.fields[k]]; });
    var row = el('div', { className:'smart-rule' }), fieldSel = sel(fields, rule.field, 'Rule field'), opSel, vals = [];
    function paint(keepValue){
      var f = fieldSel.value, ops = OPS[f] || [['is','is']];
      var prevOp = opSel ? opSel.value : rule.op;
      opSel = sel(ops, prevOp, 'Comparison'); if (!ops.some(function(o){ return o[0] === prevOp; })) opSel.selectedIndex = 0;
      vals = valueInput(f, opSel.value, keepValue ? rule.value : '');
      opSel.onchange = function(){ rule.op = opSel.value; var cur = vals.map(function(v){ return v.value; }); rule.value = cur; paint(true); onChange(); };
      var kids = [fieldSel, opSel].concat(vals); vals.forEach(function(v){ if (v._extra) kids.push(v._extra); v.addEventListener('input', sync); v.addEventListener('change', sync); });
      kids.push(el('button', { type:'button', className:'btn ghost smart-rule-remove', 'aria-label':'Remove rule', text:'✕', onClick:function(){ row.remove(); onRemove(); } }));
      row.replaceChildren.apply(row, kids); sync(true);
    }
    function sync(silent){ rule.field = fieldSel.value; rule.op = opSel.value; rule.value = vals.length > 1 ? vals.map(function(v){ return v.value; }) : (vals[0] ? vals[0].value : ''); if (silent !== true) onChange(); }
    fieldSel.onchange = function(){ rule.value = ''; paint(false); onChange(); };
    paint(true); row._rule = rule; return row;
  }

  function editor(col, onSaved){
    loadMeta().then(function(){
      col = col || {}; var r = col.rules || { match:'all', rules:[{ field:'added', op:'is', value:'30' }], sort:'added_desc', limit:50 };
      var rules = (r.rules || []).map(function(x){ return Object.assign({}, x); });
      var name = inp('text', col.name || '', { maxlength:80, placeholder:'e.g. Short comedies I haven\u2019t seen' });
      var desc = inp('text', col.description || '', { maxlength:200, placeholder:'Optional' });
      var match = sel([['all','all of these rules'],['any','any of these rules']], r.match || 'all', 'Match');
      var sort = sel(Object.keys(meta.sorts || {}).map(function(k){ return [k, meta.sorts[k]]; }), r.sort || 'added_desc', 'Sort');
      var limit = inp('number', r.limit || 50, { min:1, max:200 });
      var pin = el('input', { type:'checkbox' }); pin.checked = !!col.pin_home;
      var isAdmin = A.state.user && A.state.user.role === 'admin';
      var shared = el('input', { type:'checkbox' }); shared.checked = !!col.shared;
      var list = el('div', { className:'smart-rules' });
      var count = el('strong', { className:'smart-count', text:'…' }), sample = el('div', { className:'smart-preview-rail' });
      var note = el('p', { className:'muted', role:'status', text:'' });
      var timer = null, seq = 0;
      function current(){ return { match:match.value, rules:Array.from(list.children).map(function(n){ return n._rule; }), sort:sort.value, limit:Number(limit.value) || 50 }; }
      function preview(){
        clearTimeout(timer); timer = setTimeout(function(){
          var mine = ++seq; count.textContent = '…';
          api('/smart-collections/preview', { method:'POST', body:{ rules:current() } }).then(function(j){
            if (mine !== seq) return;
            if (!j || !j.ok) { count.textContent = '—'; return; }
            count.textContent = j.count === 1 ? '1 title' : (j.count || 0) + ' titles';
            sample.replaceChildren.apply(sample, (j.items || []).slice(0, 8).map(function(it){ return A.cardEl ? A.cardEl(it) : el('span', { text:it.title || '' }); }));
            if (!(j.items || []).length) sample.replaceChildren(el('p', { className:'muted', text:'Nothing matches yet. Try loosening a rule.' }));
          }, function(){ if (mine === seq) count.textContent = '—'; });
        }, 350);
      }
      function add(rule){ list.appendChild(ruleRow(rule, preview, preview)); }
      rules.forEach(add);
      [match, sort, limit].forEach(function(x){ x.addEventListener('change', preview); x.addEventListener('input', preview); });
      var save = el('button', { type:'button', className:'btn primary', text: col.id ? 'Save changes' : 'Create smart collection' });
      var body = el('div', { className:'stack smart-editor' }, [
        el('label', { className:'vc-field' }, [el('span', { text:'Name' }), name]),
        el('label', { className:'vc-field' }, [el('span', { text:'Description' }), desc]),
        el('div', { className:'smart-match' }, [el('span', { text:'Include titles that match ' }), match]),
        list,
        el('button', { type:'button', className:'btn ghost', text:'＋ Add rule', onClick:function(){ add({ field:'genre', op:'is', value:'' }); preview(); } }),
        el('div', { className:'smart-grid' }, [el('label', { className:'vc-field' }, [el('span', { text:'Order' }), sort]), el('label', { className:'vc-field' }, [el('span', { text:'Show at most' }), limit])]),
        el('label', { className:'check-label' }, [pin, ' Pin to Home']),
        isAdmin ? el('label', { className:'check-label' }, [shared, ' Share with everyone (admin)']) : null,
        el('div', { className:'smart-preview' }, [el('div', { className:'smart-preview-head' }, [el('span', { className:'muted', text:'Live preview' }), count]), sample]),
        note, el('div', { className:'row-actions' }, [save, col.id && col.can_edit ? el('button', { type:'button', className:'btn ghost danger', text:'Delete', onClick:function(){
          A.confirmDialog('Delete the smart collection \u201c' + (col.name || '') + '\u201d? The titles themselves stay in your library.', { okText:'Delete', danger:true }).then(function(ok){
            if (!ok) return; api('/smart-collections/' + col.id, { method:'DELETE' }).then(function(j){ if (!j || !j.ok) return A.toast(friendly(j), { kind:'bad' }); meta = null; if (d) d.close(); A.toast('Smart collection deleted', { kind:'ok' }); if (onSaved) onSaved(); });
          });
        } }) : null])
      ]);
      var d = A.dialog(col.id ? 'Edit smart collection' : 'New smart collection', body);
      save.onclick = function(){
        save.disabled = true; note.textContent = 'Saving…';
        api('/smart-collections', { method:'POST', body:{ id:col.id || '', name:name.value, description:desc.value, rules:current(), pin_home:pin.checked, shared:shared.checked } }).then(function(j){
          save.disabled = false;
          if (!j || !j.ok) { note.textContent = friendly(j, 'Could not save.'); return; }
          meta = null; if (d) d.close(); A.toast('Smart collection saved', { kind:'ok' }); if (onSaved) onSaved(j.collection);
        }, function(){ save.disabled = false; note.textContent = 'Could not reach the server. Please try again.'; });
      };
      preview();
    }, function(){ A.toast('Smart collections could not be loaded.', { kind:'bad' }); });
  }

  function open(col, host){
    host.replaceChildren(el('p', { className:'muted', text:'Loading…' })); host.hidden = false;
    api('/smart-collections/' + col.id).then(function(j){
      if (!j || !j.ok) { host.replaceChildren(el('p', { className:'muted', text:friendly(j, 'This smart collection could not be loaded.') })); return; }
      var head = el('div', { className:'collection-expand-head' }, [el('div', { className:'collection-expand-copy' }, [el('h3', {}, [el('span', { className:'smart-badge', text:'Smart' }), ' ' + j.collection.name]), el('p', { className:'muted', text:(j.collection.description ? j.collection.description + ' · ' : '') + (j.count === 1 ? '1 title' : j.count + ' titles') + ' · updates automatically' })]),
        el('div', { className:'row-actions' }, [j.collection.can_edit ? el('button', { type:'button', className:'btn glass', text:'Edit rules', onClick:function(){ editor(j.collection, function(){ section(host.parentNode._smartHost || host.parentNode); }); } }) : null,
          el('button', { type:'button', className:'btn ghost', text:'Close', onClick:function(){ host.hidden = true; host.replaceChildren(); } })])]);
      var grid = el('div', { className:'grid smart-grid-items' }, (j.items || []).map(function(it){ return A.cardEl(it); }));
      host.replaceChildren(head, (j.items || []).length ? grid : el('p', { className:'muted', text:'Nothing matches these rules right now. New titles appear here as soon as they fit.' }));
    });
  }

  /* Collections page: smart collections shelf with a "New" tile. */
  function section(host){
    host._smartHost = host;
    host.replaceChildren(el('p', { className:'muted', text:'Loading smart collections…' }));
    meta = null;
    loadMeta().then(function(j){
      if (!host.isConnected) return;
      var cols = (j && j.collections) || [];
      var expand = el('div', { className:'collection-expand smart-expand', hidden:true });
      var tiles = cols.map(function(c){
        var covers = (c.cover_items || []).slice(0, 3).map(function(it){ var src = it.kind === 'show' ? '/api/shows/' + encodeURIComponent(it.show_key || it.id) + '/poster?fetch=0&variant=thumb' : '/api/items/' + encodeURIComponent(it.id) + '/poster?fetch=0&variant=thumb'; return el('img', { src:A.apiUrl ? A.apiUrl(src) : src, alt:'', loading:'lazy' }); });
        return el('button', { type:'button', className:'smart-tile', onClick:function(){ open(c, expand); } }, [
          el('div', { className:'smart-tile-art' }, covers.length ? covers : [el('span', { className:'smart-tile-empty', text:'✨' })]),
          el('div', { className:'smart-tile-copy' }, [el('span', { className:'smart-badge', text: c.shared ? 'Smart · shared' : 'Smart' }), el('strong', { text:c.name }), el('small', { className:'muted', text:(c.count === 1 ? '1 title' : (c.count || 0) + ' titles') + (c.pin_home ? ' · on Home' : '') })])
        ]);
      });
      tiles.push(el('button', { type:'button', className:'smart-tile smart-tile-new', onClick:function(){ editor(null, function(){ section(host); }); } }, [el('div', { className:'smart-tile-art' }, [el('span', { className:'smart-tile-empty', text:'＋' })]), el('div', { className:'smart-tile-copy' }, [el('strong', { text:'New smart collection' }), el('small', { className:'muted', text:'Pick titles by genre, year, rating, runtime and more' })])]));
      var want = A.state._openSmart; A.state._openSmart = null;
      if (want) cols.forEach(function(c){ if (c.id === want) setTimeout(function(){ open(c, expand); try { expand.scrollIntoView({ behavior:'smooth', block:'start' }); } catch (e) {} }, 0); });
      host.replaceChildren(el('div', { className:'row-head smart-head' }, [el('h2', { text:'Smart collections' }), el('span', { className:'meta', text:'Fill themselves from rules and stay up to date' })]), el('div', { className:'smart-tiles' }, tiles), expand);
    }, function(){ host.replaceChildren(el('p', { className:'muted', text:'Smart collections could not be loaded.' })); });
  }

  /* Home: one row per pinned smart collection. */
  function homeRows(host){
    api('/smart-collections/home').then(function(j){
      if (!host.isConnected || !j || !j.ok) return;
      host.replaceChildren.apply(host, (j.rows || []).map(function(r){
        return A.rowEl(r.name, r.items.map(function(it){ return A.cardEl(it); }), { className:'row-smart', meta:'Smart · ' + r.count, more:function(){ A.state._openSmart = r.id; A.navigate('collections'); }, moreText:'See all' });
      }));
    }).catch(function(){});
  }

  root.VaultcastSmart = { init:function(app){ A = app; h = app.h; api = app.api; }, section:section, homeRows:homeRows, editor:editor, open:open };
})(window);
