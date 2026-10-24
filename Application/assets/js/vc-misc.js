/* Vaultcast Misc: Board dashboards, Drop file sharing and the Ambient screen (loaded on demand). */
(function(root){
  'use strict';
  var A, h, api;
  function signed(){ return !!(A.state.user && A.state.user.id && A.state.user.role !== 'guest'); }
  function me(){ return A.state.user || {}; }
  function isAdmin(){ return me().role === 'admin'; }
  function btn(label, onClick, cls, attrs){ return h('button', Object.assign({ type:'button', className:'btn ' + (cls || ''), text:label, onClick:onClick }, attrs || {})); }
  function empty(t, d, actions){ return A.emptyState ? A.emptyState(t, d, actions) : h('p', { className:'muted', text:t }); }
  function err(e){ A.toast((e && (e.error || e.message)) || 'Something went wrong', { kind:'bad' }); }
  function call(path, opts){ return api(path, opts).then(function(j){ if (!j || !j.ok) throw j || {}; return j; }); }
  function url(u){ return A.apiUrl ? A.apiUrl(u) : u; }
  function size(n){ n = Number(n || 0); if (A.fmtSize) return A.fmtSize(n); var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0; while (n >= 1024 && i < 4) { n /= 1024; i++; } return (i ? n.toFixed(1) : n) + ' ' + u[i]; }
  function ago(t){ var s = Math.max(1, Math.round(Date.now() / 1000 - t)); if (s < 60) return 'just now'; if (s < 3600) return Math.round(s / 60) + ' min ago'; if (s < 86400) return Math.round(s / 3600) + ' h ago'; return Math.round(s / 86400) + ' d ago'; }
  function until(t){ var s = Math.round(t - Date.now() / 1000); if (s <= 0) return 'now'; if (s < 3600) return Math.max(1, Math.round(s / 60)) + ' min'; if (s < 86400) return Math.round(s / 3600) + ' h'; return Math.round(s / 86400) + ' d'; }
  /** Weather values can be missing (null); show a dash instead of a misleading 0. */
  function deg(v){ return typeof v === 'number' && isFinite(v) ? String(Math.round(v)) : '–'; }
  function store(k, v){ try { if (v === undefined) return JSON.parse(localStorage.getItem(k) || 'null'); if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, JSON.stringify(v)); } catch (e) { return null; } }
  function rid(n){ var s = ''; var a = 'abcdefghijklmnopqrstuvwxyz0123456789'; for (var i = 0; i < (n || 8); i++) s += a[Math.floor(Math.random() * a.length)]; return s; }
  function hero(title, sub, actions, kicker){ return h('header', { className:'vc-hero' }, [h('div', { className:'vc-hero-copy' }, [kicker ? h('span', { className:'eyebrow', text:kicker }) : null, h('h1', { text:title }), sub ? h('p', { className:'muted', text:sub }) : null]), actions ? h('div', { className:'vc-hero-actions' }, actions) : null]); }
  function panel(title, kids, more, cls){ return h('section', { className:'hub-panel vc-panel ' + (cls || '') }, [h('div', { className:'hub-panel-head' }, [h('h2', { text:title }), more || null]), h('div', { className:'hub-panel-body' }, kids)]); }
  function seg(options, value, onChange, label){
    var wrap = h('div', { className:'vc-seg', role:'group', 'aria-label':label || '' });
    options.forEach(function(o){ wrap.appendChild(h('button', { type:'button', className:'vc-seg-btn' + (String(o[0]) === String(value) ? ' active' : ''), 'aria-pressed':String(String(o[0]) === String(value)), text:o[1], onClick:function(){ wrap.querySelectorAll('.vc-seg-btn').forEach(function(b){ b.classList.remove('active'); b.setAttribute('aria-pressed', 'false'); }); this.classList.add('active'); this.setAttribute('aria-pressed', 'true'); onChange(o[0]); } })); });
    return wrap;
  }
  function field(label, input, hint){ return h('label', { className:'vc-field' }, [h('span', { text:label }), input, hint ? h('small', { className:'muted', text:hint }) : null]); }
  function input(type, value, attrs){ var i = h('input', Object.assign({ type:type || 'text' }, attrs || {})); if (type === 'checkbox') i.checked = !!value; else if (value != null) i.value = value; return i; }
  function select(options, value, attrs){ var s = h('select', attrs || {}, options.map(function(o){ return h('option', { value:o[0], text:o[1] }); })); s.value = value == null ? '' : String(value); return s; }
  function modal(title, build){ return window.VaultcastHub.modal(title, build); }
  function copy(text){ if (A.copyText) return A.copyText(text); if (navigator.clipboard) navigator.clipboard.writeText(text).then(function(){ A.toast('Copied', { kind:'ok' }); }); }
  var WAKE = null;
  function wakeLock(on){
    if (!('wakeLock' in navigator)) return;
    if (on) { if (WAKE) return; navigator.wakeLock.request('screen').then(function(l){ WAKE = l; l.addEventListener('release', function(){ WAKE = null; }); }).catch(function(){}); }
    else if (WAKE) { WAKE.release().catch(function(){}); WAKE = null; }
  }
  var wakeWanted = 0;
  document.addEventListener('visibilitychange', function(){ if (!document.hidden && wakeWanted > 0) wakeLock(true); });
  function wantWake(delta){ wakeWanted = Math.max(0, wakeWanted + delta); wakeLock(wakeWanted > 0); }
  var cleanups = [];
  function onCleanup(fn){ cleanups.push(fn); }
  function stopAll(){ var c = cleanups; cleanups = []; c.forEach(function(fn){ try { fn(); } catch (e) {} }); }
  /**
   * Widget refresh loop: the first refresh is staggered so a board's widgets do not all hit the server together,
   * nothing runs while the tab is hidden, and a refresh that came due while hidden runs once on return.
   * s.refresh (seconds) overrides the widget's default interval. Returns the stop function widgets hand back.
   */
  function poll(fn, ms, s){
    var every = s && Number(s.refresh) > 0 ? Math.max(5, Number(s.refresh)) * 1000 : ms, timer = 0, due = false, stopped = false;
    function run(){ if (stopped) return; if (document.hidden) { due = true; return; } due = false; try { fn(); } catch (e) {} timer = setTimeout(run, every); }
    function vis(){ if (!document.hidden && due && !stopped) { clearTimeout(timer); run(); } }
    document.addEventListener('visibilitychange', vis);
    timer = setTimeout(run, every + (every >= 5000 ? Math.floor(Math.random() * Math.min(every * 0.25, 30000)) : 0));
    return function(){ stopped = true; clearTimeout(timer); document.removeEventListener('visibilitychange', vis); };
  }
  function every(ms, fn, host){ var t = setInterval(function(){ if (host && !host.isConnected) { clearInterval(t); return; } if (!document.hidden) fn(); }, ms); onCleanup(function(){ clearInterval(t); }); return t; }

  // ------------------------------------------------------------------ weather helpers (Open-Meteo WMO codes)
  var WMO = { 0:['Clear', '☀️', '🌙'], 1:['Mainly clear', '🌤️', '🌙'], 2:['Partly cloudy', '⛅', '☁️'], 3:['Overcast', '☁️', '☁️'], 45:['Fog', '🌫️'], 48:['Rime fog', '🌫️'], 51:['Light drizzle', '🌦️'], 53:['Drizzle', '🌦️'], 55:['Heavy drizzle', '🌧️'], 56:['Freezing drizzle', '🌧️'], 57:['Freezing drizzle', '🌧️'], 61:['Light rain', '🌦️'], 63:['Rain', '🌧️'], 65:['Heavy rain', '🌧️'], 66:['Freezing rain', '🌧️'], 67:['Freezing rain', '🌧️'], 71:['Light snow', '🌨️'], 73:['Snow', '🌨️'], 75:['Heavy snow', '❄️'], 77:['Snow grains', '🌨️'], 80:['Showers', '🌦️'], 81:['Showers', '🌧️'], 82:['Violent showers', '⛈️'], 85:['Snow showers', '🌨️'], 86:['Snow showers', '❄️'], 95:['Thunderstorm', '⛈️'], 96:['Thunderstorm, hail', '⛈️'], 99:['Thunderstorm, hail', '⛈️'] };
  function wx(code, day){ var w = WMO[code] || ['—', '🌡️']; return { text:w[0], icon:day === false && w[2] ? w[2] : w[1] }; }
  function weatherData(loc){ return call('/misc/widget/weather?lat=' + loc.lat + '&lon=' + loc.lon + '&units=' + (loc.units || 'c')); }
  function locationPicker(current, onPick){
    var q = input('search', '', { placeholder:'Search a town or city', 'aria-label':'Location search' }), list = h('div', { className:'vc-place-list' }), t = 0;
    q.addEventListener('input', function(){ clearTimeout(t); t = setTimeout(function(){ if (q.value.trim().length < 2) { list.replaceChildren(); return; } call('/misc/widget/geocode?q=' + encodeURIComponent(q.value.trim())).then(function(j){ list.replaceChildren.apply(list, j.places.length ? j.places.map(function(p){ return h('button', { type:'button', className:'vc-place', onClick:function(){ onPick({ name:p.name, region:p.region, lat:p.lat, lon:p.lon }); list.replaceChildren(h('p', { className:'muted', text:'✓ ' + p.name + (p.region ? ', ' + p.region : '') })); } }, [h('strong', { text:p.name }), h('small', { className:'muted', text:p.region })]); }) : [h('p', { className:'muted', text:'No places found.' })]); }).catch(err); }, 350); });
    return h('div', { className:'vc-location' }, [current && current.name ? h('p', { className:'muted', text:'Current: ' + current.name + (current.region ? ', ' + current.region : '') }) : null, q, list]);
  }
  function clockText(d, opts){ opts = opts || {}; try { return d.toLocaleTimeString([], { hour:'2-digit', minute:'2-digit', second:opts.seconds ? '2-digit' : undefined, hour12:opts.h12 ? true : (opts.h12 === false ? false : undefined), timeZone:opts.tz || undefined }); } catch (e) { return d.toLocaleTimeString(); } }
  function dateText(d, tz){ try { return d.toLocaleDateString([], { weekday:'long', day:'numeric', month:'long', timeZone:tz || undefined }); } catch (e) { return d.toDateString(); } }

  // ------------------------------------------------------------------ widgets
  // Each widget: { name, desc, icon, w, h, render(body, settings, ctx) -> optional cleanup, form(settings) -> { el, read() } }
  var W = {};
  function list(rows, cls){ return h('ul', { className:'vc-w-list ' + (cls || '') }, rows); }
  /** Refresh-safe update shared with the app (A.swap): patches in place, keyed by data-key, no remounts. */
  function swap(){ return A.swap.apply(null, arguments); }
  function loading(body){ body.replaceChildren(h('p', { className:'muted vc-w-loading', text:'Loading…' })); }
  /** A failed background refresh keeps the last good content (no blink to an error and back); only a first load shows the error. */
  function wErr(body, e, force){
    var msg = (e && (e.error || e.message)) || 'Unavailable right now.';
    if (!force && body.firstChild && !body.querySelector('.vc-w-loading, .vc-w-error')) { body.title = 'Last refresh failed: ' + msg; return; }
    swap(body, h('p', { className:'muted vc-w-error', text:msg }));
  }
  function textForm(fields){
    var els = {};
    var el = h('div', { className:'vc-form' }, fields.map(function(f){
      var i;
      if (f.type === 'select') i = select(f.options, f.value);
      else if (f.type === 'textarea') { i = h('textarea', { rows:String(f.rows || 4), placeholder:f.placeholder || '' }); i.value = f.value || ''; }
      else i = input(f.type || 'text', f.value, { placeholder:f.placeholder || '', min:f.min, max:f.max });
      els[f.key] = i;
      return f.type === 'checkbox' ? h('label', { className:'vc-check' }, [i, h('span', { text:f.label })]) : field(f.label, i, f.hint);
    }));
    return { el:el, read:function(){ var o = {}; Object.keys(els).forEach(function(k){ var i = els[k]; o[k] = i.type === 'checkbox' ? i.checked : i.type === 'number' ? Number(i.value) : i.value; }); return o; } };
  }

  W.clock = { name:'Clock', desc:'Digital or analogue clock with the date.', icon:'🕒', w:6, h:4,
    render:function(body, s){
      var face = h('div', { className:'vc-clock ' + (s.style === 'analog' ? 'analog' : 'digital') }); body.appendChild(face);
      var hands = null;
      if (s.style === 'analog') { face.innerHTML = '<svg viewBox="0 0 100 100" aria-hidden="true"><circle cx="50" cy="50" r="47" class="rim"/>' + Array.apply(null, Array(12)).map(function(_, i){ var a = i * Math.PI / 6; return '<line x1="' + (50 + 40 * Math.sin(a)) + '" y1="' + (50 - 40 * Math.cos(a)) + '" x2="' + (50 + 44 * Math.sin(a)) + '" y2="' + (50 - 44 * Math.cos(a)) + '" class="tick"/>'; }).join('') + '<line x1="50" y1="50" x2="50" y2="26" class="hh"/><line x1="50" y1="50" x2="50" y2="14" class="mh"/><line x1="50" y1="56" x2="50" y2="10" class="sh"/><circle cx="50" cy="50" r="2.5" class="pin"/></svg><div class="vc-clock-date"></div>'; hands = face.querySelectorAll('.hh,.mh,.sh'); }
      else face.innerHTML = '<div class="vc-clock-time"></div><div class="vc-clock-date"></div>';
      function tick(){
        var d = new Date();
        if (hands) {
          var parts = { h:d.getHours(), m:d.getMinutes(), s:d.getSeconds() };
          if (s.tz) try { var f = new Intl.DateTimeFormat('en-GB', { timeZone:s.tz, hour:'numeric', minute:'numeric', second:'numeric', hour12:false }).formatToParts(d); f.forEach(function(p){ if (p.type === 'hour') parts.h = +p.value; if (p.type === 'minute') parts.m = +p.value; if (p.type === 'second') parts.s = +p.value; }); } catch (e) {}
          hands[0].setAttribute('transform', 'rotate(' + ((parts.h % 12) * 30 + parts.m / 2) + ' 50 50)'); hands[1].setAttribute('transform', 'rotate(' + (parts.m * 6 + parts.s / 10) + ' 50 50)'); hands[2].setAttribute('transform', 'rotate(' + parts.s * 6 + ' 50 50)'); hands[2].style.display = s.seconds === false ? 'none' : '';
        } else face.querySelector('.vc-clock-time').textContent = clockText(d, { seconds:s.seconds, h12:s.h12 === 'on' ? true : s.h12 === 'off' ? false : null, tz:s.tz });
        face.querySelector('.vc-clock-date').textContent = s.date === false ? '' : (s.label ? s.label + ' · ' : '') + dateText(d, s.tz);
      }
      tick(); return poll(tick, 1000);
    },
    form:function(s){ return textForm([{ key:'style', label:'Style', type:'select', value:s.style || 'digital', options:[['digital', 'Digital'], ['analog', 'Analogue']] }, { key:'seconds', label:'Show seconds', type:'checkbox', value:!!s.seconds }, { key:'date', label:'Show the date', type:'checkbox', value:s.date !== false }, { key:'h12', label:'Clock format', type:'select', value:s.h12 || 'auto', options:[['auto', 'Device default'], ['off', '24-hour'], ['on', '12-hour']] }, { key:'tz', label:'Time zone (optional)', value:s.tz || '', placeholder:'e.g. America/New_York', hint:'Leave empty for this device\u2019s time zone.' }, { key:'label', label:'Label (optional)', value:s.label || '' }]); }
  };

  W.weather = { name:'Weather', desc:'Current conditions and a forecast for any place.', icon:'🌤️', w:8, h:6,
    render:function(body, s){
      if (!s.loc) { body.appendChild(h('p', { className:'muted', text:'Open the widget settings to choose a location.' })); return; }
      function load(){ weatherData({ lat:s.loc.lat, lon:s.loc.lon, units:s.units }).then(function(j){
        var c = j.current, w = wx(c.code, c.day);
        swap(body, h('div', { className:'vc-wx' }, [
          h('div', { className:'vc-wx-now' }, [h('span', { className:'vc-wx-icon', text:w.icon, 'aria-hidden':'true' }), h('div', null, [h('strong', { className:'vc-wx-temp', text:deg(c.temp) + j.units }), h('span', { className:'muted', text:w.text + ' · feels ' + deg(c.feels) + '°' }), h('span', { className:'muted vc-wx-place', text:s.loc.name })])]),
          h('div', { className:'vc-wx-meta muted' }, [h('span', { text:'💧 ' + deg(c.humidity) + '%' }), h('span', { text:'💨 ' + deg(c.wind) + ' ' + j.wind_units })]),
          h('div', { className:'vc-wx-hours' }, j.hours.slice(0, 8).map(function(x){ return h('div', { className:'vc-wx-h' }, [h('small', { className:'muted', text:x.time.slice(11, 16) }), h('span', { text:wx(x.code).icon }), h('strong', { text:deg(x.temp) + '°' }), x.rain ? h('small', { className:'vc-wx-rain', text:x.rain + '%' }) : null]); })),
          h('div', { className:'vc-wx-days' }, j.days.slice(0, 5).map(function(d, i){ return h('div', { className:'vc-wx-d' }, [h('small', { text:i === 0 ? 'Today' : new Date(d.date + 'T12:00').toLocaleDateString([], { weekday:'short' }) }), h('span', { text:wx(d.code).icon }), h('strong', { text:deg(d.max) + '°' }), h('small', { className:'muted', text:deg(d.min) + '°' })]); })),
          j.stale ? h('small', { className:'vc-w-source muted', text:'Offline, showing the last update' }) : null]));
      }).catch(function(e){ wErr(body, e); }); }
      loading(body); load(); return poll(load, 600000, s);
    },
    form:function(s){
      var loc = s.loc || null, units = select([['c', 'Celsius, km/h'], ['f', 'Fahrenheit, mph']], s.units || 'c');
      return { el:h('div', { className:'vc-form' }, [field('Location', locationPicker(loc, function(p){ loc = p; })), field('Units', units)]), read:function(){ return { loc:loc, units:units.value }; } };
    }
  };

  // Calendar and Reminders widgets live in vc-calendar.js (loaded with the first one on a board).
  function calMod(){ return A.loadModule('vc-calendar.js', 'VaultcastCalendar'); }
  function lazyWidget(method){
    return function(body, s, ctx){
      var stop = null, gone = false; loading(body);
      calMod().then(function(m){ if (gone || !body.isConnected) return; body.replaceChildren(); stop = m[method](body, s, ctx); }).catch(function(e){ wErr(body, e); });
      return function(){ gone = true; if (typeof stop === 'function') stop(); };
    };
  }
  function lazyForm(method){
    return function(s){
      var holder = h('div', { className:'vc-form' }, [h('p', { className:'muted', text:'Loading…' })]), inner = null;
      calMod().then(function(m){ inner = m[method](s); holder.replaceChildren(inner.el); }).catch(function(e){ holder.replaceChildren(h('p', { className:'muted', text:(e && e.message) || 'Unavailable.' })); });
      return { el:holder, read:function(){ return inner ? inner.read() : s; } };
    };
  }
  W.calendar = { name:'Calendar', desc:'Upcoming events from your calendars, shared calendars, episode releases and ICS links.', icon:'📅', w:8, h:7, render:lazyWidget('calendarWidget'), form:lazyForm('calendarForm') };
  W.reminders = { name:'Reminders', desc:'Bins, bills, medication, car, birthdays and anything that repeats, with done and snooze.', icon:'🔔', w:6, h:6, render:lazyWidget('remindersWidget'), form:lazyForm('remindersForm') };

  W.notes = { name:'Notes', desc:'A sticky note. Lines starting with [ ] become checkboxes.', icon:'📝', w:6, h:6,
    render:function(body, s, ctx){
      var ta = h('textarea', { className:'vc-note', placeholder:'Write something…', 'aria-label':'Note' }); ta.value = s.text || '';
      var view = h('div', { className:'vc-note-view', tabindex:'0', title:'Click to edit' }), t = 0;
      function draw(){
        view.replaceChildren.apply(view, String(ta.value || '').split('\n').map(function(line, i){
          var m = /^\s*\[( |x|X)\]\s?(.*)$/.exec(line);
          if (m) return h('label', { className:'vc-note-check' + (m[1] !== ' ' ? ' done' : '') }, [input('checkbox', m[1] !== ' ', { onChange:function(){ var lines = ta.value.split('\n'); lines[i] = lines[i].replace(/\[( |x|X)\]/, this.checked ? '[x]' : '[ ]'); ta.value = lines.join('\n'); persist(); draw(); } }), h('span', { text:m[2] })]);
          return h('p', { text:line || '\u00a0' });
        }));
      }
      function persist(){ clearTimeout(t); t = setTimeout(function(){ s.text = ta.value; ctx.save(Object.assign({}, s), true); }, 700); }
      ta.addEventListener('input', persist); ta.addEventListener('blur', function(){ draw(); ta.hidden = true; view.hidden = false; });
      view.addEventListener('click', function(e){ if (e.target.tagName === 'INPUT') return; view.hidden = true; ta.hidden = false; ta.focus(); });
      view.addEventListener('keydown', function(e){ if (e.key === 'Enter') { view.hidden = true; ta.hidden = false; ta.focus(); } });
      body.classList.add('vc-note-body'); if (s.colour) body.style.setProperty('--vc-note', s.colour);
      ta.hidden = !!s.text; view.hidden = !s.text; draw();
      body.append(view, ta);
      return function(){ clearTimeout(t); };
    },
    form:function(s){ return textForm([{ key:'colour', label:'Tint', type:'color', value:s.colour || '#f5dc7a' }]); }
  };
  W.rss = { name:'News feed', desc:'Headlines from any RSS or Atom feed.', icon:'📰', w:8, h:7,
    render:function(body, s){
      if (!s.url) { body.appendChild(h('p', { className:'muted', text:'Add a feed address in the widget settings.' })); return; }
      function load(){ call('/misc/widget/rss?url=' + encodeURIComponent(s.url)).then(function(j){
        swap(body, h('p', { className:'vc-rss-title', text:j.title || s.url }), list(j.items.slice(0, s.count || 10).map(function(i){ return h('li', { className:'vc-rss-item', 'data-key':String(i.link || i.title || '') }, [i.link ? h('a', { href:i.link, target:'_blank', rel:'noopener noreferrer', text:i.title }) : h('span', { text:i.title }), i.date ? h('small', { className:'muted', text:ago(i.date) }) : null]); })));
      }).catch(function(e){ wErr(body, e); }); }
      loading(body); load(); return poll(load, 900000, s);
    },
    form:function(s){ return textForm([{ key:'url', label:'Feed address', type:'url', value:s.url || '', placeholder:'https://example.com/feed.xml' }, { key:'count', label:'Headlines', type:'number', min:3, max:25, value:s.count || 10 }]); }
  };
  W.drop = { name:'Drop', desc:'Drop files here to keep them for a while, or send them to your other devices.', icon:'📥', w:6, h:6,
    render:function(body, s){
      var zone = h('div', { className:'vc-mini-drop', tabindex:'0', role:'button', 'aria-label':'Choose files to upload to Drop' }, [h('strong', { text:'Drop files here' }), h('small', { className:'muted', text:'Kept for 24 hours · sent to your devices' })]), items = h('div');
      body.append(zone, items);
      var picker = h('input', { type:'file', multiple:true, hidden:true }); body.appendChild(picker);
      function refresh(){ call('/drop/state').then(function(j){ swap(items, list(j.items.filter(function(i){ return i.status === 'available'; }).slice(0, 5).map(function(i){ return h('li', { className:'vc-drop-mini', 'data-key':String(i.id) }, [h('span', { text:kindIcon(i) }), h('a', { href:i.kind === 'link' ? i.body : url('/api/drop/items/' + i.id + '/download'), target:i.kind === 'link' ? '_blank' : null, rel:'noopener', text:i.name }), h('small', { className:'muted', text:'⏳ ' + until(i.expires_at) })]); }))); }).catch(function(){}); }
      function send(files){ Array.prototype.forEach.call(files, function(f){ uploadFile(f, { expires_hours:24, inbox:true }, function(p){ zone.querySelector('small').textContent = p.done ? 'Uploaded ' + f.name : Math.round(100 * p.sent / f.size) + '% · ' + f.name; if (p.done) refresh(); }).catch(err); }); }
      zone.addEventListener('click', function(){ picker.click(); }); zone.addEventListener('keydown', function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); picker.click(); } });
      picker.addEventListener('change', function(){ send(picker.files); picker.value = ''; });
      zone.addEventListener('dragover', function(e){ e.preventDefault(); zone.classList.add('over'); }); zone.addEventListener('dragleave', function(){ zone.classList.remove('over'); });
      zone.addEventListener('drop', function(e){ e.preventDefault(); zone.classList.remove('over'); send(e.dataTransfer.files); });
      refresh(); return poll(refresh, 60000, s);
    }
  };
  W.recordings = { name:'Recordings', desc:'What is recording now and what is scheduled next.', icon:'⏺️', w:6, h:6,
    render:function(body, s){
      function load(){ call('/misc/widget/recordings').then(function(j){
        if (j.unavailable) { swap(body, h('p', { className:'muted', text:'Live TV is turned off on this server.' })); return; }
        var rows = j.recording.map(function(r){ return h('li', { className:'vc-rec live', 'data-key':'r' + (r.id || (r.title + r.start)) }, [h('span', { className:'vc-rec-dot', 'aria-label':'Recording' }), h('span', null, [h('strong', { text:r.title }), h('small', { className:'muted', text:r.channel + ' · until ' + new Date(r.end * 1000).toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' }) })])]); })
          .concat(j.upcoming.map(function(r){ var d = new Date(r.start * 1000); return h('li', { className:'vc-rec', 'data-key':'u' + (r.id || (r.title + r.start)) }, [h('span', { className:'vc-rec-time', text:d.toLocaleDateString([], { weekday:'short' }) + ' ' + d.toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' }) }), h('span', null, [h('strong', { text:r.title }), h('small', { className:'muted', text:r.channel })])]); }));
        swap(body, rows.length ? list(rows) : h('p', { className:'muted', text:'No recordings scheduled.' }));
      }).catch(function(e){ wErr(body, e); }); }
      loading(body); load(); return poll(load, 60000, s);
    }
  };
  var DL_STATUS = { downloading:'', queued:'Queued', queued_local:'Queued', download_wait:'Waiting', check_wait:'Waiting to check', checking:'Checking', stalled:'Stalled', stopped:'Paused', error:'Error' };
  function dlPercent(d){
    var p = d.percent == null || d.percent === '' ? NaN : Number(d.percent);
    if (isFinite(p) && p > 0 && p <= 1 && d.size == null) p = p * 100;
    if ((!isFinite(p) || p <= 0) && Number(d.size) > 0 && d.left != null && isFinite(Number(d.left))) p = (Number(d.size) - Number(d.left)) * 100 / Number(d.size);
    return isFinite(p) ? Math.max(0, Math.min(100, p)) : null;
  }
  function dlEta(sec){ sec = Math.round(Number(sec)); if (!isFinite(sec) || sec <= 0) return ''; if (sec < 60) return sec + 's left'; if (sec < 3600) return Math.floor(sec / 60) + 'm left'; if (sec < 86400) { var m = Math.floor((sec % 3600) / 60); return Math.floor(sec / 3600) + 'h' + (m ? ' ' + m + 'm' : '') + ' left'; } return Math.floor(sec / 86400) + 'd left'; }
  function dlRow(d){
    var pct = dlPercent(d), st = String(d.status || ''), label = DL_STATUS.hasOwnProperty(st) ? DL_STATUS[st] : st.replace(/_/g, ' ');
    var rate = Number(d.rate) > 0 ? size(d.rate) + '/s' : '';
    var meta = [pct != null && st !== 'queued' ? Math.floor(pct) + '%' : '', label, rate, dlEta(d.eta)].filter(function(x){ return x && x !== 'null' && x !== 'undefined'; }).join(' · ');
    var fill = h('i'); fill.style.width = (pct || 0) + '%';
    var bar = h('span', { className:'vc-progress' + (st === 'error' ? ' bad' : ''), role:'progressbar', 'aria-valuemin':'0', 'aria-valuemax':'100', 'aria-valuenow':pct != null ? String(Math.round(pct)) : null, 'aria-label':'Download progress' }, [fill]);
    var name = String(d.name || d.title || '').trim() || 'Download';
    return h('li', { className:'vc-dl', 'data-key':String(d.id || name), title:name }, [h('span', { className:'vc-dl-name', text:name }), bar, meta ? h('small', { className:'muted', text:meta }) : null]);
  }
  W.downloads = { name:'Downloads', desc:'Progress of downloads in the request queue.', icon:'⬇️', w:6, h:6,
    render:function(body, s){
      function load(){ call('/misc/widget/downloads').then(function(j){
        if (j.unavailable) { swap(body, h('p', { className:'muted', text:'Downloads are not available for your account.' })); return; }
        var items = Array.isArray(j.items) ? j.items.filter(function(d){ return d && typeof d === 'object'; }) : [];
        swap(body, items.length ? list(items.map(dlRow)) : h('p', { className:'muted', text:'Nothing downloading right now.' }));
      }).catch(function(e){ wErr(body, e); }); }
      loading(body); load(); return poll(load, 10000, s);
    }
  };
  function pick(o, keys){ for (var i = 0; i < keys.length; i++) if (o && o[keys[i]] != null) return o[keys[i]]; return null; }
  function pct(v){ if (v == null) return null; if (typeof v === 'number') return v; if (typeof v === 'object') { var p = pick(v, ['percent', 'used_percent', 'pct']); if (p != null) return Number(p); var u = pick(v, ['used']), t = pick(v, ['total', 'size']); if (u != null && t) return 100 * u / t; } return null; }
  W.health = { name:'NAS health', desc:'CPU, memory, disks, temperature and services.', icon:'🩺', w:6, h:6,
    render:function(body, s){
      /** Load average arrives as [1,5,15], {"1":..,"5":..,"15":..} or a plain number; nulls (no /proc on Windows) are skipped. */
      function loadText(v){ var a = Array.isArray(v) ? v : (v && typeof v === 'object') ? [v['1'], v['5'], v['15']] : [v]; a = a.slice(0, 3).filter(function(x){ return x !== null && x !== undefined && x !== '' && isFinite(Number(x)); }).map(function(x){ return Number(x).toFixed(2); }); return a.join(' '); }
      function load(){ call('/misc/widget/health').then(function(j){
        if (j.limited) { swap(body, h('div', { className:'vc-health-ok' }, [h('span', { className:'vc-rec-dot ok' }), h('strong', { text:'Server online' }), h('small', { className:'muted', text:'Detailed health is visible to administrators.' })])); return; }
        var gauges = [['CPU', pct(pick(j, ['cpu_percent', 'cpu']))], ['Memory', pct(pick(j, ['memory', 'mem']))], ['Media disk', pct(pick(j, ['media_disk', 'disk']))], ['Data disk', pct(pick(j, ['data_disk']))]].filter(function(g){ return g[1] != null && !isNaN(g[1]); });
        var temp = pick(j, ['temperature', 'temp']); if (temp && typeof temp === 'object') temp = pick(temp, ['cpu', 'value', 'max']);
        swap(body, h('div', { className:'vc-health' }, [
          h('div', { className:'vc-gauges' }, gauges.map(function(g){ var v = Math.round(g[1]); return h('div', { className:'vc-gauge' + (v > 90 ? ' bad' : v > 75 ? ' warn' : ''), style:'--vc-p:' + v }, [h('strong', { text:v + '%' }), h('small', { className:'muted', text:g[0] })]); })),
          h('p', { className:'muted' }, [j.uptime_human ? 'Up ' + j.uptime_human : '', temp ? ' · ' + Math.round(Number(temp)) + '°C' : '', loadText(j.load) ? ' · load ' + loadText(j.load) : '']),
          j.services ? h('div', { className:'vc-services' }, j.services.slice(0, 8).map(function(s){ var ok = s.active === true || s.active === 'active' || s.active === 'running'; return h('span', { className:'vc-chip' + (ok ? ' ok' : ' bad'), text:(ok ? '● ' : '○ ') + s.name }); })) : null]));
      }).catch(function(e){ wErr(body, e); }); }
      loading(body); load(); return poll(load, 20000, s);
    }
  };
  W.recent = { name:'Recently added', desc:'The newest films and shows in the library.', icon:'🆕', w:12, h:6,
    render:function(body, s){
      function load(){ call('/misc/widget/recent?n=' + (s.count || 12) + (s.source ? '&source=' + encodeURIComponent(s.source) : '')).then(function(j){
        if (!j.items.length) { swap(body, h('p', { className:'muted', text: j.missing ? 'That smart collection is no longer available. Edit this widget to pick another.' : (s.source ? 'Nothing matches this smart collection right now.' : 'Nothing new yet.') })); return; }
        swap(body, h('div', { className:'vc-poster-row' }, j.items.map(function(it){ return h('button', { type:'button', className:'vc-poster', 'data-key':String(it.id || it.show_key || it.title), title:it.title, onClick:function(){ if (it.show_key && A.openShow) A.openShow(it.show_key); else if (A.openMovie) A.openMovie(it.id); } }, [h('img', { src:url(it.poster), alt:'', loading:'lazy' }), h('span', { className:'vc-poster-cap' }, [h('strong', { text:it.title }), h('small', { className:'muted', text:it.subtitle || '' })])]); })));
      }).catch(function(e){ wErr(body, e); }); }
      loading(body); load(); return poll(load, 600000, s);
    },
    form:function(s){
      var f = textForm([{ key:'source', label:'Show', type:'select', value:s.source || '', options:[['', 'Recently added (whole library)']].concat(s.source ? [[s.source, 'Saved smart collection']] : []) }, { key:'count', label:'How many', type:'number', min:4, max:30, value:s.count || 12 }]);
      var pick = f.el.querySelector('select');
      // Smart collections can feed this widget instead of the newest titles.
      api('/smart-collections').then(function(j){ if (!pick || !j || !j.ok) return; var keep = pick.value; Array.from(pick.options).slice(1).forEach(function(o){ o.remove(); }); (j.collections || []).forEach(function(c){ pick.appendChild(h('option', { value:'smart:' + c.id, text:'Smart: ' + c.name })); }); pick.value = keep; if (pick.selectedIndex < 0) pick.selectedIndex = 0; }).catch(function(){});
      return f;
    }
  };
  W.nowplaying = { name:'Now playing', desc:'What you and your friends are watching (admins see everyone).', icon:'▶️', w:8, h:5,
    render:function(body, s){
      function load(){ call('/misc/widget/nowplaying').then(function(j){
        swap(body, j.sessions.length ? list(j.sessions.map(function(x){ return h('li', { className:'vc-np', 'data-key':String(x.id || x.session || ((x.user && x.user.name) + '|' + x.title)) }, [h('img', { src:url(x.poster), alt:'', loading:'lazy' }), h('span', { className:'vc-np-copy' }, [h('strong', { text:x.title }), h('small', { className:'muted', text:x.user.name + ' · ' + ago(x.updated_at) }), x.percent != null ? h('span', { className:'vc-progress' }, [h('i', { style:'width:' + x.percent + '%' })]) : null])]); })) : h('p', { className:'muted', text:'Nobody is watching right now.' }));
      }).catch(function(e){ wErr(body, e); }); }
      loading(body); load(); return poll(load, 20000, s);
    }
  };
  W.leaderboard = { name:'Leaderboard', desc:'Top players or teams this week.', icon:'🏆', w:6, h:7,
    render:function(body, s){
      function load(){ call('/play/leaderboard?board=' + (s.board || 'points') + '&period=' + (s.period || 'week')).then(function(j){
        swap(body, j.rows.length ? h('ol', { className:'vc-w-list vc-mini-lb' }, j.rows.slice(0, 8).map(function(r, i){ var who = r.team && s.board === 'teams' ? r.team : r.user; return h('li', { className:r.me ? 'me' : '', 'data-key':'lb' + ((who && (who.id || who.name)) || i) }, [h('span', { className:'vc-lb-rank', text:String(i + 1) }), h('strong', { text:(r.team && s.board === 'teams' ? r.team.emblem + ' ' : '') + who.name }), h('span', { className:'vc-lb-value', text:j.unit === 'seconds' ? Math.round(r.value / 3600) + ' h' : Number(r.value).toLocaleString() })]); })) : h('p', { className:'muted', text:'No scores yet.' }));
      }).catch(function(e){ wErr(body, e); }); }
      loading(body); load(); return poll(load, 120000, s);
    },
    form:function(s){ return textForm([{ key:'board', label:'Board', type:'select', value:s.board || 'points', options:[['points', 'Points'], ['teams', 'Teams'], ['blocks', 'Stackfall'], ['snake', 'Neon Snake'], ['merge', '2048 Merge'], ['breakout', 'Brickbreak'], ['quiz', 'Quiz'], ['emu-time', 'Most time played'], ['emu-sessions', 'Most games played']] }, { key:'period', label:'Period', type:'select', value:s.period || 'week', options:[['day', 'Today'], ['week', 'This week'], ['month', 'This month'], ['all', 'All time']] }]); }
  };

  // ------------------------------------------------------------------ webcams (Board widget + Ambient)
  /** Strict YouTube video id from watch, youtu.be, live, embed and shorts links; anything else gives ''. */
  function youtubeId(u){
    var x; try { x = new URL(String(u || '').trim()); } catch (e) { return ''; }
    if (x.protocol !== 'https:' && x.protocol !== 'http:') return '';
    var host = x.hostname.toLowerCase().replace(/^(www|m|music)\./, ''), path = x.pathname, id = '';
    if (host === 'youtu.be') id = (/^\/([A-Za-z0-9_-]{11})\/?$/.exec(path) || [])[1] || '';
    else if (host === 'youtube.com' || host === 'youtube-nocookie.com') {
      if (path === '/watch' || path === '/watch/') id = x.searchParams.get('v') || '';
      else id = (/^\/(?:live|embed|shorts|v)\/([A-Za-z0-9_-]{11})\/?$/.exec(path) || [])[1] || '';
    }
    return /^[A-Za-z0-9_-]{11}$/.test(id) ? id : '';
  }
  function safeHttpUrl(u){ u = String(u || '').trim(); return /^https?:\/\/[^\s"'<>\\]+$/i.test(u) ? u : ''; }
  /** Which player a webcam needs: an explicit choice wins, otherwise it is worked out from the address. */
  function camKind(c){
    var u = String(c.url || ''), k = c.kind || 'auto';
    if (youtubeId(u)) return 'youtube';
    if (k !== 'auto' && k !== 'youtube') return k;
    if (/\.(m3u8|mp4|webm)(?:[?#]|$)/i.test(u)) return 'video';
    if (/mjpe?g|\/video\.cgi|faststream|\/stream(?:[?\/#]|$)|action=stream/i.test(u)) return 'mjpeg';
    return 'image';
  }
  function camLabel(c){
    var n = String(c.name || '').trim(); if (n) return n;
    if (youtubeId(c.url)) return 'YouTube live';
    try { return new URL(c.url).hostname.replace(/^www\./, ''); } catch (e) { return 'Webcam'; }
  }
  /** Build the element for one webcam. Returns { el, stop }. Images refresh; MJPEG and video stream; YouTube embeds muted. */
  function camView(c, alive){
    var kind = camKind(c), url = safeHttpUrl(c.url), el, t = 0, hls = null;
    if (kind === 'youtube') {
      el = h('iframe', { className:'vc-cam vc-cam-yt', src:'https://www.youtube-nocookie.com/embed/' + youtubeId(c.url) + '?autoplay=1&mute=1&playsinline=1&rel=0&modestbranding=1', title:camLabel(c),
        allow:'autoplay; encrypted-media; picture-in-picture', allowfullscreen:true, referrerpolicy:'strict-origin-when-cross-origin', loading:'lazy' });
    } else if (!url) el = h('p', { className:'vc-kb-empty', text:'This webcam address is not valid.' });
    else if (kind === 'embed') el = h('iframe', { src:url, className:'vc-cam', allow:'autoplay; fullscreen', referrerpolicy:'no-referrer', sandbox:'allow-scripts allow-same-origin allow-presentation', title:camLabel(c), loading:'lazy' });
    else if (kind === 'video') { el = h('video', { className:'vc-cam', muted:true, autoplay:true, playsinline:true }); el.muted = true; playStream(el, url, alive).then(function(s){ hls = s; }).catch(function(){}); }
    else if (kind === 'mjpeg') el = h('img', { className:'vc-cam', alt:camLabel(c), src:url, referrerpolicy:'no-referrer' });
    else {
      var bust = function(){ return url + (url.indexOf('?') >= 0 ? '&' : '?') + '_t=' + Date.now(); };
      el = h('img', { className:'vc-cam', alt:camLabel(c), src:bust(), referrerpolicy:'no-referrer' });
      // Fetch the next snapshot off-screen and swap only once it has decoded, so the picture never blanks
      // (or shows a broken-image icon) between refreshes. A failed fetch keeps the last good frame.
      var busy = false;
      t = setInterval(function(){
        if (document.hidden || !el.isConnected || busy) return;
        var pre = new Image(), u = bust(); busy = true; pre.referrerPolicy = 'no-referrer';
        pre.onload = function(){ busy = false; if (!el.isConnected) return; var go = function(){ if (el.isConnected) el.src = u; }; if (pre.decode) pre.decode().then(go, go); else go(); };
        pre.onerror = function(){ busy = false; };
        pre.src = u;
      }, Math.max(5, Number(c.refresh) || 30) * 1000);
    }
    return { el:el, stop:function(){ clearInterval(t); if (hls) { try { hls.destroy(); } catch (e) {} hls = null; } if (el.tagName === 'VIDEO') { el.pause(); el.removeAttribute('src'); } if (kind === 'mjpeg' && el.tagName === 'IMG') el.removeAttribute('src'); if (el.tagName === 'IFRAME') el.src = 'about:blank'; } };
  }
  var CAM_KINDS = [['auto', 'Detect from the address'], ['youtube', 'YouTube live'], ['image', 'Still image (refreshes)'], ['mjpeg', 'MJPEG stream'], ['video', 'Video / HLS stream'], ['embed', 'Embedded page']];
  /** Editable list of { name, url, kind, refresh } used by the widget settings and the Ambient admin panel. */
  function camEditor(cams){
    var box = h('div', { className:'vc-cam-edit' });
    function draw(){
      box.replaceChildren.apply(box, cams.map(function(c, i){
        function bind(el, k, num){ ['input', 'change'].forEach(function(ev){ el.addEventListener(ev, function(){ c[k] = num ? Number(el.value) : el.value; if (k === 'url' || k === 'kind') note.textContent = hint(); }); }); return el; }
        function hint(){ if (!c.url) return ''; if (!safeHttpUrl(c.url)) return 'Use an http(s) address.'; var k = camKind(c); return k === 'youtube' ? 'YouTube video ' + youtubeId(c.url) + ' (plays muted)' : (c.kind || 'auto') === 'auto' ? 'Detected: ' + (CAM_KINDS.filter(function(x){ return x[0] === k; })[0] || ['', k])[1] : ''; }
        var note = h('small', { className:'muted vc-cam-note', text:'' }); note.textContent = hint();
        return h('fieldset', { className:'vc-rem-row vc-cam-row' }, [bind(input('text', c.name || '', { placeholder:'Name (shown as the label)', 'aria-label':'Webcam name', maxlength:'60' }), 'name'), bind(input('url', c.url || '', { placeholder:'https://… or a YouTube link', 'aria-label':'Webcam address' }), 'url'), bind(select(CAM_KINDS, c.kind || 'auto', { 'aria-label':'Type' }), 'kind'), bind(input('number', c.refresh || 30, { min:'5', max:'3600', 'aria-label':'Refresh seconds', title:'Refresh every N seconds (still images)' }), 'refresh', true), btn('×', function(){ cams.splice(i, 1); draw(); }, 'ghost small', { 'aria-label':'Remove webcam' }), note]);
      }).concat([btn('Add webcam', function(){ cams.push({ name:'', url:'', kind:'auto', refresh:30 }); draw(); }, 'ghost')]));
    }
    draw(); return box;
  }
  var camsCache = null;
  /** The user's saved webcams (server-side, per user), shared by the Webcams and Media widgets and Ambient. */
  function myCams(fresh){ if (!fresh && camsCache) return camsCache; camsCache = call('/misc/prefs').then(function(j){ return j.webcams || []; }).catch(function(e){ camsCache = null; throw e; }); return camsCache; }
  /** Webcams an admin made available for Ambient (site registry); cached for a minute. */
  var ambCams = null, ambCamsAt = 0;
  function ambientCams(fresh){ if (fresh || !ambCams || Date.now() - ambCamsAt > 60000) { ambCamsAt = Date.now(); ambCams = call('/misc/ambient').then(function(c){ return c.webcams || []; }).catch(function(){ ambCams = null; return []; }); } return ambCams; }
  function saveCams(list){ camsCache = call('/misc/prefs', { method:'POST', body:{ webcams:list } }).then(function(j){ return j.webcams || []; }); return camsCache; }
  /**
   * Show webcams one at a time with a crossfade and a small name label. Only one player is alive: the
   * previous image/stream/iframe is torn down as soon as the fade to the next one finishes.
   * opts: { seconds, pauseOnHover, fade, start (index), controls }
   */
  function camCycle(stage, cams, opts){
    opts = opts || {};
    var i = opts.start || 0, cur = null, timer = 0, stopped = false, hovering = false, fadeMs = Math.max(0, (opts.fade == null ? 0.8 : opts.fade) * 1000);
    var label = h('div', { className:'vc-cam-label' }), wrap = h('div', { className:'vc-cam-cycle' });
    stage.replaceChildren(wrap, label); if (opts.label === false) label.hidden = true;
    if (opts.controls !== false && cams.length > 1) stage.appendChild(btn('›', function(){ clearTimeout(timer); step(); }, 'ghost small vc-cam-next', { 'aria-label':'Next webcam' }));
    function alive(){ return !stopped && stage.isConnected; }
    function arm(){ clearTimeout(timer); if (cams.length > 1 && !stopped) timer = setTimeout(function(){ if (hovering || document.hidden) { arm(); return; } step(); }, Math.max(10, Math.min(600, Number(opts.seconds) || 30)) * 1000); }
    function step(){
      if (!alive()) return;
      var c = cams[i % cams.length]; i++;
      var v = camView(c, alive), layer = h('div', { className:'vc-cam-layer' }, [v.el]), old = cur, shown = false;
      wrap.appendChild(layer);
      function reveal(){ if (shown || stopped) return; shown = true; layer.classList.add('show'); label.textContent = camLabel(c); if (old) { setTimeout(function(){ old.v.stop(); old.layer.remove(); }, fadeMs + 50); } }
      if (v.el.tagName === 'IMG') { v.el.addEventListener('load', reveal, { once:true }); v.el.addEventListener('error', reveal, { once:true }); }
      else if (v.el.tagName === 'VIDEO') v.el.addEventListener('playing', reveal, { once:true });
      else if (v.el.tagName === 'IFRAME') v.el.addEventListener('load', reveal, { once:true });
      setTimeout(reveal, 4000); if (!old) reveal();
      cur = { v:v, layer:layer };
      arm();
    }
    if (opts.pauseOnHover) { stage.addEventListener('pointerenter', function(){ hovering = true; stage.classList.add('paused'); }); stage.addEventListener('pointerleave', function(){ hovering = false; stage.classList.remove('paused'); }); }
    stage.style.setProperty('--vc-cam-fade', fadeMs + 'ms');
    if (cams.length) step(); else stage.replaceChildren(h('p', { className:'vc-kb-empty', text:'No webcams chosen yet.' }));
    return { stop:function(){ stopped = true; clearTimeout(timer); if (cur) { cur.v.stop(); } wrap.replaceChildren(); } };
  }
  /** Checklist of saved webcams (empty selection = all of them). */
  function camChooser(cams, chosen, single){
    var box = h('div', { className:'vc-cam-choose' });
    if (!cams.length) { box.appendChild(h('p', { className:'muted', text:'No saved webcams yet. Add them in a Webcams widget’s settings.' })); return { el:box, read:function(){ return single ? '' : []; } }; }
    if (single) { var sel = select(cams.map(function(c){ return [c.id, camLabel(c)]; }), chosen || cams[0].id); box.appendChild(sel); return { el:box, read:function(){ return sel.value; } }; }
    var checks = cams.map(function(c){ var cb = input('checkbox', !chosen || !chosen.length || chosen.indexOf(c.id) >= 0); box.appendChild(h('label', { className:'vc-check' }, [cb, h('span', { text:camLabel(c) })])); return [c.id, cb]; });
    return { el:box, read:function(){ var on = checks.filter(function(x){ return x[1].checked; }).map(function(x){ return x[0]; }); return on.length === checks.length ? [] : on; } };
  }
  function pickCams(all, ids){ return ids && ids.length ? all.filter(function(c){ return ids.indexOf(c.id) >= 0; }) : all; }
  W.webcams = { name:'Webcams', desc:'Live webcams: YouTube live streams, camera snapshots, MJPEG or HLS.', icon:'📷', w:8, h:6,
    render:function(body, s){
      body.classList.add('vc-flush');
      var stage = h('div', { className:'vc-cam-stage' }), cyc = null, views = [], gone = false; body.appendChild(stage);
      (s.cams && s.cams.length && !s.ids ? Promise.resolve(s.cams) : myCams().then(function(all){ return pickCams(all, s.ids); })).then(function(cams){
        if (gone) return;
        cams = cams.filter(function(c){ return c && (youtubeId(c.url) || safeHttpUrl(c.url)); });
        if (!cams.length) { body.classList.remove('vc-flush'); stage.replaceChildren(h('p', { className:'muted', text:'Add webcams (YouTube live links work) in the widget settings.' })); return; }
        if (s.layout === 'grid' && cams.length > 1) {
          stage.classList.add('grid', 'n' + Math.min(6, cams.length));
          views = cams.slice(0, 6).map(function(c){ var v = camView(c, function(){ return !gone && stage.isConnected; }); stage.appendChild(h('figure', { className:'vc-cam-cell' }, [v.el, h('figcaption', { className:'vc-cam-label', text:camLabel(c) })])); return v; });
          return;
        }
        cyc = camCycle(stage, cams, { seconds:s.seconds || 60, pauseOnHover:!!s.hoverPause, fade:s.fade == null ? 0.8 : s.fade });
      }).catch(function(e){ if (!gone) wErr(body, e, true); });
      return function(){ gone = true; if (cyc) cyc.stop(); views.forEach(function(v){ v.stop(); }); };
    },
    form:function(s){
      var holder = h('div', { className:'vc-form' }, [h('p', { className:'muted', text:'Loading your webcams…' })]), cams = null, chooser = null;
      var layout = select([['rotate', 'One at a time'], ['grid', 'Grid (up to 6)']], s.layout || 'rotate'), secs = input('number', s.seconds || 60, { min:'10', max:'600' }), hover = input('checkbox', !!s.hoverPause);
      myCams(true).catch(function(){ return []; }).then(function(saved){
        cams = saved.map(function(c){ return Object.assign({}, c); });
        // Older widgets kept their own list: offer it as the start of the saved list.
        if (!cams.length && s.cams && s.cams.length) cams = s.cams.map(function(c){ return { name:c.name || '', url:c.url, kind:c.kind || 'auto', refresh:c.refresh || 30 }; });
        chooser = camChooser(saved, s.ids, false);
        holder.replaceChildren(field('Your webcams', camEditor(cams), 'Saved to your account and shared with the Media widget. YouTube watch, youtu.be, live, embed and shorts links play muted with the privacy-enhanced player.'), field('Show on this widget', chooser.el, 'New webcams appear here after saving.'), field('Layout', layout), field('Seconds per webcam (10–600)', secs), h('label', { className:'vc-check' }, [hover, h('span', { text:'Pause while the pointer is over it' })]));
      });
      return { el:holder, read:function(){
        var out = { layout:layout.value, seconds:Math.max(10, Math.min(600, Number(secs.value) || 60)), hoverPause:hover.checked, ids:chooser ? chooser.read() : (s.ids || []) };
        if (cams) { var clean = cams.filter(function(c){ return c.url; }).map(function(c){ return { id:c.id, name:String(c.name || '').trim().slice(0, 60), url:String(c.url).trim(), kind:c.kind || 'auto', refresh:Number(c.refresh) || 30 }; }); saveCams(clean).catch(err); out.cams = null; }
        return out;
      } };
    }
  };

  // ------------------------------------------------------------------ your pictures (Ambient + Picture frame)
  var picCache = null, picAt = 0;
  function myPictures(fresh){
    if (!fresh && picCache && Date.now() - picAt < 60000) return picCache;
    picAt = Date.now(); picCache = call('/misc/pictures').catch(function(e){ picCache = null; throw e; });
    return picCache;
  }
  function picUrl(p){ return url(p.url); }
  /** Upload several pictures at once with one progress bar; resolves with the server's answer. */
  function uploadPictures(files, onProgress){
    return new Promise(function(resolve, reject){
      var fd = new FormData(); Array.prototype.forEach.call(files, function(f){ fd.append('files[]', f, f.name); });
      var x = new XMLHttpRequest(); x.open('POST', A.API + '/misc/pictures'); x.withCredentials = true; x.responseType = 'json';
      x.upload.onprogress = function(e){ if (e.lengthComputable && onProgress) onProgress(e.loaded / e.total); };
      x.onload = function(){ var j = x.response || {}; if (x.status >= 200 && x.status < 300 && j.ok) resolve(j); else reject(j.error ? j : { error:x.status === 413 ? 'Those pictures are larger than the server accepts in one go. Try fewer at a time.' : 'Upload failed.' }); };
      x.onerror = function(){ reject({ error:'Upload failed: the connection dropped.' }); };
      x.send(fd);
    });
  }
  /** Manager panel: upload (button or drag and drop), grid with select / delete. */
  function picturesManager(onChange){
    var box = h('div', { className:'vc-pics' }), grid = h('div', { className:'vc-pics-grid', role:'list' }), info = h('small', { className:'muted' }), bar = h('div', { className:'vc-pics-bar' }), prog = h('progress', { max:'1', value:'0', hidden:true, className:'vc-pics-prog' }), picked = {};
    var file = h('input', { type:'file', multiple:true, accept:'image/jpeg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif', hidden:true, onChange:function(){ if (this.files.length) send(this.files); this.value = ''; } });
    var addBtn = btn('Upload pictures', function(){ file.click(); }, 'primary small'), delBtn = btn('Delete selected', function(){ var ids = Object.keys(picked); if (!ids.length) return; A.confirmDialog('Delete ' + ids.length + ' picture' + (ids.length === 1 ? '' : 's') + '?', { okText:'Delete', danger:true }).then(function(ok){ if (!ok) return; call('/misc/pictures/delete', { method:'POST', body:{ ids:ids } }).then(function(j){ picked = {}; draw(j); picCache = Promise.resolve(j); picAt = Date.now(); if (onChange) onChange(j); }).catch(err); }); }, 'ghost small danger');
    function send(files){
      var list = Array.prototype.filter.call(files, function(f){ return /^image\/(jpeg|png|webp|gif)$/.test(f.type) || /\.(jpe?g|png|webp|gif)$/i.test(f.name); });
      if (!list.length) { A.toast('Choose JPG, PNG, WebP or GIF pictures', { kind:'bad' }); return; }
      prog.hidden = false; prog.value = 0; addBtn.disabled = true;
      uploadPictures(list, function(p){ prog.value = p; }).then(function(j){
        draw(j); picCache = Promise.resolve(j); picAt = Date.now(); if (onChange) onChange(j);
        if (j.added && j.added.length) A.toast(j.added.length + ' picture' + (j.added.length === 1 ? '' : 's') + ' added', { kind:'ok' });
        if (j.failed && j.failed.length) A.toast(j.failed[0].error + (j.failed.length > 1 ? ' (+' + (j.failed.length - 1) + ' more)' : ''), { kind:'bad', ms:6000 });
      }).catch(err).then(function(){ prog.hidden = true; addBtn.disabled = false; });
    }
    function draw(j){
      addBtn.hidden = !j.can_upload;
      info.textContent = j.pictures.length + ' picture' + (j.pictures.length === 1 ? '' : 's') + ' · ' + size(j.bytes) + ' · up to ' + j.limits.file_mb + ' MB each (JPG, PNG, WebP, GIF)' + (j.can_upload ? '' : ' · your account cannot upload');
      delBtn.hidden = !Object.keys(picked).length;
      grid.replaceChildren.apply(grid, j.pictures.length ? j.pictures.map(function(p){
        var cb = input('checkbox', !!picked[p.id], { 'aria-label':'Select ' + p.name, onChange:function(){ if (this.checked) picked[p.id] = 1; else delete picked[p.id]; tile.classList.toggle('picked', this.checked); delBtn.hidden = !Object.keys(picked).length; } });
        var tile = h('div', { className:'vc-pic' + (picked[p.id] ? ' picked' : ''), role:'listitem', title:p.name + ' · ' + p.width + '×' + p.height + ' · ' + size(p.bytes) }, [h('img', { src:picUrl(p), alt:p.name, loading:'lazy', decoding:'async' }), h('label', { className:'vc-pic-pick' }, [cb]),
          btn('×', function(){ A.confirmDialog('Delete “' + p.name + '”?', { okText:'Delete', danger:true }).then(function(ok){ if (ok) call('/misc/pictures/' + p.id, { method:'DELETE' }).then(function(r){ delete picked[p.id]; draw(r); picCache = Promise.resolve(r); picAt = Date.now(); if (onChange) onChange(r); }).catch(err); }); }, 'ghost small vc-pic-del', { 'aria-label':'Delete ' + p.name })]);
        return tile;
      }) : [h('p', { className:'muted vc-pics-empty', text:'No pictures yet. Upload some, or drop them here.' })]);
    }
    ['dragenter', 'dragover'].forEach(function(ev){ box.addEventListener(ev, function(e){ e.preventDefault(); box.classList.add('drag'); }); });
    ['dragleave', 'drop'].forEach(function(ev){ box.addEventListener(ev, function(e){ e.preventDefault(); box.classList.remove('drag'); if (ev === 'drop' && e.dataTransfer && e.dataTransfer.files.length) send(e.dataTransfer.files); }); });
    bar.append(addBtn, delBtn, info, file);
    box.append(bar, prog, grid);
    myPictures(true).then(draw).catch(function(e){ grid.replaceChildren(h('p', { className:'muted', text:(e && e.error) || 'Pictures unavailable.' })); });
    return box;
  }
  function shuffle(a){ for (var k = a.length - 1; k > 0; k--) { var r = Math.floor(Math.random() * (k + 1)), t = a[k]; a[k] = a[r]; a[r] = t; } return a; }
  /**
   * Shared picture renderer for the Slideshow and Picture frame widgets: two layers with a crossfade
   * (or a cut, or a slow Ken Burns pan), cover/contain fit, shuffle, optional captions. loader() resolves
   * to [{ url, title }]; it is asked again after each full pass so new pictures show up.
   */
  /**
   * Crossfade without a dark dip: the incoming layer fades in on top while the outgoing one stays fully
   * opaque underneath; the old layer is only hidden once the new one is solid.
   */
  function fadeOver(back, front, ms){
    var t = (back._vcFade = (back._vcFade || 0) + 1);
    back.style.zIndex = '2'; front.style.zIndex = '1';
    back.classList.add('show');
    setTimeout(function(){ if (back._vcFade === t && back.classList.contains('show')) { front.style.transition = 'none'; front.classList.remove('show'); void front.offsetWidth; front.style.transition = ''; } }, (ms || 0) + 60);
  }
  function pictureStage(body, s, loader, opts){
    opts = opts || {};
    body.classList.add('vc-flush');
    var trans = s.transition || opts.transition || 'fade', reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    var stage = h('div', { className:'vc-frame fit-' + (s.fit === 'contain' ? 'contain' : 'cover') + (trans === 'none' ? ' no-fade' : '') }), layers = [h('div', { className:'vc-frame-layer' }), h('div', { className:'vc-frame-layer' })], cap = h('div', { className:'vc-kb-cap' });
    var front = 0, list = [], i = 0, stop = null, gone = false, every = Math.max(4, Number(s.seconds) || 20) * 1000;
    var fadeMs = trans === 'none' ? 0 : Math.max(0.3, Math.min(6, Number(s.fade) || 1.5)) * 1000;
    stage.style.setProperty('--vc-frame-fade', fadeMs / 1000 + 's');
    layers.forEach(function(l){ stage.appendChild(l); }); if (s.captions) stage.appendChild(cap); body.appendChild(stage);
    function showPic(p, tries){
      var img = new Image(); img.decoding = 'async'; img.alt = p.title || ''; img.className = 'vc-frame-img';
      img.onload = function(){
        if (gone || !stage.isConnected) return;
        var back = layers[1 - front];
        if (trans === 'kenburns' && !reduce) { img.className += ' vc-kb-img ' + ['zoom-in-l', 'zoom-in-r', 'zoom-out-l', 'zoom-out-r', 'pan-up', 'pan-down'][Math.floor(Math.random() * 6)]; img.style.animationDuration = every * 1.25 + 'ms'; }
        back.replaceChildren(img);
        if (s.fit === 'contain') back.style.setProperty('--vc-frame-bg', 'url("' + img.src.replace(/["\\()\s]/g, '') + '")');
        fadeOver(back, layers[front], fadeMs); front = 1 - front; cap.textContent = p.title || '';
      };
      img.onerror = function(){ if ((tries || 0) < 3 && list.length > 1) next((tries || 0) + 1); };
      img.src = p.url;
    }
    function load(){ return loader().then(function(l){ list = (l || []).filter(function(x){ return x && x.url; }); if (s.shuffle !== false && !opts.single) shuffle(list); i = 0; return list; }); }
    function next(tries){
      if (gone) return;
      if (i >= list.length) { load().then(function(){ if (list.length) showPic(list[i++], tries); }).catch(function(){}); return; }
      showPic(list[i++], tries);
    }
    load().then(function(){
      if (gone || !stage.isConnected) return;
      if (!list.length) { stage.replaceChildren(h('p', { className:'vc-kb-empty', text:opts.empty || 'No pictures yet.' })); return; }
      if (opts.single) { showPic(opts.single(list) || list[0]); return; }
      next(); if (list.length > 1 || opts.reload) stop = poll(next, every);
    }).catch(function(e){ if (!gone) wErr(body, e, true); });
    return function(){ gone = true; if (stop) stop(); };
  }
  function libraryPictures(s, n){
    var art = s.art || (['frames', 'posters', 'mixed'].indexOf(s.source) >= 0 ? s.source : 'posters');
    return call('/misc/ambient/stills?n=' + (n || 30) + '&kind=' + encodeURIComponent(art) + '&type=' + encodeURIComponent(s.library || 'both') + '&order=' + (s.order === 'recent' ? 'recent' : 'random') + '&variant=medium')
      .then(function(j){ return j.stills.map(function(x){ return { url:url(x.url), title:x.title + (x.year ? ' (' + x.year + ')' : '') }; }); });
  }
  function ownPictures(){ return myPictures().then(function(j){ return j.pictures.map(function(p){ return { url:picUrl(p), title:'', id:p.id }; }); }); }
  function linkPictures(s){ return String(s.urls || '').split(/\s+/).map(safeImageUrl).filter(Boolean).map(function(u){ return { url:u, title:'' }; }); }
  /** Common settings rows: interval, fit, shuffle, transition. */
  function pictureOptions(s, kb){
    var secs = input('number', s.seconds || (kb ? 12 : 20), { min:'4', max:'3600' }), fit = select([['cover', 'Fill the frame (crop)'], ['contain', 'Show the whole picture']], s.fit || 'cover'), shuf = input('checkbox', s.shuffle !== false);
    var trans = select([['fade', 'Crossfade'], ['kenburns', 'Crossfade with a slow pan'], ['none', 'Cut']], s.transition || (kb ? 'kenburns' : 'fade')), fade = select([['0.6', 'Quick'], ['1.5', 'Gentle'], ['3', 'Slow']], String(s.fade || 1.5));
    return { els:[field('Seconds per picture', secs), field('Fit', fit), h('label', { className:'vc-check' }, [shuf, h('span', { text:'Shuffle' })]), field('Transition', trans), field('Crossfade speed', fade)],
      read:function(){ return { seconds:Math.max(4, Number(secs.value) || 20), fit:fit.value, shuffle:shuf.checked, transition:trans.value, fade:Number(fade.value) || 1.5 }; } };
  }
  W.slideshow = { name:'Slideshow', desc:'Library posters and backdrops, your own pictures, or both, with a crossfade.', icon:'🎞️', w:12, h:8,
    render:function(body, s){
      var src = s.source === 'mine' || s.source === 'both' || s.source === 'urls' ? s.source : 'library';
      return pictureStage(body, Object.assign({ captions:s.captions !== false && src !== 'mine', transition:'kenburns' }, s), function(){
        if (src === 'urls') return Promise.resolve(linkPictures(s));
        var parts = [];
        if (src !== 'mine') parts.push(libraryPictures(s, src === 'both' ? 20 : 30).catch(function(){ return []; }));
        if (src !== 'library') parts.push(ownPictures().catch(function(){ return []; }));
        return Promise.all(parts).then(function(sets){ return [].concat.apply([], sets); });
      }, { empty:src === 'mine' ? 'No pictures yet. Upload some in this widget’s settings.' : 'No pictures available yet.', reload:src !== 'mine' });
    },
    form:function(s){
      var legacy = ['frames', 'posters', 'mixed'].indexOf(s.source) >= 0;
      var source = select([['library', 'Library artwork'], ['mine', 'My pictures'], ['both', 'Both mixed'], ['urls', 'Image links']], legacy || !s.source ? 'library' : s.source);
      var art = select([['posters', 'Posters'], ['frames', 'Backdrops and film stills'], ['mixed', 'Posters and stills']], s.art || (legacy ? s.source : 'posters'));
      var lib = select([['both', 'Films and TV'], ['movies', 'Films only'], ['tv', 'TV only']], s.library || 'both'), order = select([['random', 'Random'], ['recent', 'Recently added']], s.order || 'random');
      var urls = h('textarea', { rows:'3', placeholder:'https://…/photo.jpg' }); urls.value = s.urls || '';
      var caps = input('checkbox', s.captions !== false), o = pictureOptions(s, true);
      var libBox = h('div', { className:'vc-form' }, [field('Artwork', art), field('From', lib), field('Order', order), h('label', { className:'vc-check' }, [caps, h('span', { text:'Show titles' })])]);
      var mineBox = h('details', { className:'vc-frame-manage', open:s.source === 'mine' }, [h('summary', { text:'Your pictures (upload or delete)' }), picturesManager()]);
      var urlBox = field('Image links (one per line)', urls);
      function sync(){ var v = source.value; libBox.hidden = v === 'mine' || v === 'urls'; mineBox.hidden = v === 'library' || v === 'urls'; urlBox.hidden = v !== 'urls'; }
      source.addEventListener('change', sync); sync();
      return { el:h('div', { className:'vc-form' }, [field('Source', source), libBox, mineBox, urlBox].concat(o.els)),
        read:function(){ return Object.assign({ source:source.value, art:art.value, library:lib.value, order:order.value, urls:urls.value, captions:caps.checked }, o.read()); } };
    }
  };
  W.frame = { name:'Picture frame', desc:'Your own pictures: one favourite or a slideshow with a crossfade.', icon:'🖼️', w:8, h:8,
    render:function(body, s){
      return pictureStage(body, s, ownPictures, { empty:'No pictures yet. Add some on the Ambient page or in this widget’s settings.', single:s.mode === 'single' ? function(l){ return l.filter(function(p){ return p.id === s.picture; })[0]; } : null });
    },
    form:function(s){
      var mode = select([['slideshow', 'Slideshow'], ['single', 'A single picture']], s.mode || 'slideshow'), pick = select([['', 'The newest picture']], s.picture || ''), o = pictureOptions(s, false);
      function fill(j){ var v = pick.value || s.picture || ''; pick.replaceChildren.apply(pick, [h('option', { value:'', text:'The newest picture' })].concat(j.pictures.map(function(p){ return h('option', { value:p.id, text:p.name }); }))); pick.value = v; if (pick.value !== v) pick.value = ''; }
      myPictures().then(fill).catch(function(){});
      var el = h('div', { className:'vc-form' }, [field('Show', mode), field('Picture (single)', pick)].concat(o.els, [h('details', { className:'vc-frame-manage', open:true }, [h('summary', { text:'Your pictures (upload or delete)' }), picturesManager(fill)])]));
      return { el:el, read:function(){ return Object.assign({ mode:mode.value, picture:pick.value }, o.read()); } };
    }
  };

  // ------------------------------------------------------------------ Ken Burns slideshow (widget + ambient)
  function kenBurns(stage, source, opts){
    opts = opts || {};
    var layers = [h('div', { className:'vc-kb-layer' }), h('div', { className:'vc-kb-layer' })], cap = h('div', { className:'vc-kb-cap' }), pool = [], i = 0, front = 0, timer = 0, stopped = false;
    layers.forEach(function(l){ stage.appendChild(l); }); if (opts.captions) stage.appendChild(cap);
    var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    function refill(){ return source().then(function(list){ pool = list.slice(); for (var k = pool.length - 1; k > 0; k--) { var r = Math.floor(Math.random() * (k + 1)), t = pool[k]; pool[k] = pool[r]; pool[r] = t; } i = 0; }); }
    var waiting = false;
    function onVis(){ if (!document.hidden && waiting && !stopped) { waiting = false; next(); } }
    document.addEventListener('visibilitychange', onVis);
    function next(){
      if (stopped || !stage.isConnected) { document.removeEventListener('visibilitychange', onVis); return; }
      // A hidden tab neither decodes pictures nor keeps a timer chain going; it resumes when shown again.
      if (document.hidden) { waiting = true; return; }
      if (i >= pool.length) { refill().then(function(){ if (pool.length) next(); else stage.replaceChildren(h('p', { className:'muted vc-kb-empty', text:'No pictures available yet.' })); }).catch(function(){ timer = setTimeout(next, 30000); }); return; }
      var item = pool[i++], img = new Image();
      img.onload = function(){
        if (stopped) return;
        var back = layers[1 - front];
        back.replaceChildren(img);
        var fx = ['zoom-in-l', 'zoom-in-r', 'zoom-out-l', 'zoom-out-r', 'pan-up', 'pan-down'][Math.floor(Math.random() * 6)];
        img.className = 'vc-kb-img ' + (reduce ? '' : fx); img.style.animationDuration = (opts.interval || 12000) * 1.25 + 'ms';
        fadeOver(back, layers[front], parseFloat(getComputedStyle(back).transitionDuration) * 1000 || 1600); front = 1 - front;
        cap.textContent = item.title || '';
        timer = setTimeout(next, opts.interval || 12000);
      };
      img.onerror = function(){ timer = setTimeout(next, 200); };
      img.alt = ''; img.decoding = 'async'; img.src = item.url;
    }
    next();
    return { stop:function(){ stopped = true; clearTimeout(timer); document.removeEventListener('visibilitychange', onVis); } };
  }

  // ------------------------------------------------------------------ media widget: IPTV, internet radio or a library film
  function iptvChannels(){
    return A.ensureLiveData ? A.ensureLiveData().then(function(){ var l = A.liveData() || {}, iptv = l.iptv || {}; var favs = (iptv.favourites || []).map(function(f){ return f.id; }); var lineup = (iptv.lineup || []).slice(); lineup.sort(function(a, b){ return (favs.indexOf(b.id) >= 0) - (favs.indexOf(a.id) >= 0); }); return lineup; }) : Promise.resolve([]);
  }
  function playStream(video, src, cur){
    if (/\.m3u8(?:[?#]|$)/i.test(src) && !video.canPlayType('application/vnd.apple.mpegurl')) return A.loadHls().then(function(Hls){ if (!cur()) return null; var s = new Hls({ enableWorker:true }); s.loadSource(src); s.attachMedia(video); s.on(Hls.Events.MANIFEST_PARSED, function(){ video.play().catch(function(){}); }); return s; });
    video.src = src; video.play().catch(function(){}); return Promise.resolve(null);
  }
  W.media = { name:'Media', desc:'Live TV, internet radio or a film from the library, right on the board.', icon:'📺', w:12, h:8,
    render:function(body, s, ctx){
      body.classList.add('vc-flush', 'vc-media');
      var mode = s.mode || 'iptv', token = 0, hls = null, cleanup = [];
      function cur(t){ return function(){ return t === token && body.isConnected; }; }
      function teardown(){ token++; if (hls) { try { hls.destroy(); } catch (e) {} hls = null; } cleanup.forEach(function(f){ f(); }); cleanup = []; body.querySelectorAll('video,audio').forEach(function(v){ try { v.pause(); v.removeAttribute('src'); v.load(); } catch (e) {} }); }
      function bar(kids){ return h('div', { className:'vc-media-bar' }, kids); }
      function iptv(){
        var t = ++token, video = h('video', { playsinline:true, muted:true, autoplay:true, className:'vc-media-video' }), note = h('p', { className:'vc-media-note muted' });
        video.muted = s.muted !== false;
        var mute = btn(video.muted ? '🔇' : '🔊', function(){ video.muted = !video.muted; mute.textContent = video.muted ? '🔇' : '🔊'; mute.setAttribute('aria-label', video.muted ? 'Unmute' : 'Mute'); ctx.save(Object.assign({}, s, { muted:video.muted }), true); }, 'ghost vc-icon-btn', { 'aria-label':video.muted ? 'Unmute' : 'Mute' });
        var name = h('strong', { text:'Live TV' });
        body.replaceChildren(video, note, bar([name, mute, btn('Channel', function(){ choose(); }, 'ghost small'), btn('⛶', function(){ if (video.requestFullscreen) video.requestFullscreen().catch(function(){}); }, 'ghost vc-icon-btn', { 'aria-label':'Full screen' })]));
        function start(ch){
          if (!ch) { note.textContent = 'Choose a channel.'; return; }
          name.textContent = ch.name || 'Live TV'; note.textContent = '';
          A.attachIptvStream(video, ch, { current:cur(t), play:function(){ video.play().catch(function(){}); }, error:function(m){ note.textContent = m; }, stream:function(st){ hls = st; }, note:function(){}, jar:A.hlsJar ? A.hlsJar() : '' });
        }
        function choose(){ iptvChannels().then(function(chs){ modal('Choose a channel', function(b, close){ var q = input('search', '', { placeholder:'Filter channels', 'aria-label':'Filter channels' }), l = h('div', { className:'vc-pick-list' }); function draw(){ var f = q.value.toLowerCase(); l.replaceChildren.apply(l, chs.filter(function(c){ return !f || String(c.name || '').toLowerCase().indexOf(f) >= 0; }).slice(0, 200).map(function(c){ return h('button', { type:'button', className:'vc-pick', onClick:function(){ close(); ctx.save(Object.assign({}, s, { channel:c.id }), true); teardown(); s.channel = c.id; iptv(); } }, [c.logo ? h('img', { src:c.logo, alt:'', loading:'lazy' }) : null, h('span', { text:c.name })]); })); } q.addEventListener('input', draw); draw(); b.append(q, l); if (!chs.length) l.appendChild(h('p', { className:'muted', text:'No IPTV channels are set up.' })); }); }).catch(err); }
        iptvChannels().then(function(chs){ if (!cur(t)()) return; start(chs.filter(function(c){ return c.id === s.channel; })[0] || null); if (!s.channel) choose(); }).catch(function(){ note.textContent = 'Live TV is unavailable.'; });
      }
      function pseudotv(){
        var t=++token, sequence=0, timer=null, channel=null;
        var video=h('video',{playsinline:true,controls:true,muted:true,autoplay:true,className:'vc-media-video'}),name=h('strong',{text:'PseudoTV'}),note=h('p',{className:'vc-media-note muted','aria-live':'polite'});
        video.muted=s.muted!==false;
        body.replaceChildren(video,note,bar([name,btn('Channel',choose,'ghost small'),btn('Retune',tune,'ghost small')]));
        function later(ms){clearTimeout(timer);timer=setTimeout(tune,Math.min(Math.max(1000,ms),2147483647));}
        function tune(){
          if(!cur(t)()||!channel)return;
          var mine=++sequence,asked=Date.now();clearTimeout(timer);
          A.liveApi('now','channel='+encodeURIComponent(channel.id)).then(function(b){
            if(!cur(t)()||mine!==sequence)return;
            if(!b||!b.url){note.textContent='This channel is off air.';video.pause();video.removeAttribute('src');video.load();later(30000);return;}
            note.textContent=b.title||'Live';
            var got=Date.now(),seek=Math.max(0,Number(b.seekMs!=null?b.seekMs:(Number(b.mediaOffsetMs)||0)+Math.max(0,Number(b.serverNow||got)-Number(b.startUnix||got))))/1000;
            video.onloadedmetadata=function(){if(!cur(t)()||mine!==sequence)return;try{video.currentTime=Math.min(seek+(Date.now()-got+(got-asked)/2)/1000,isFinite(video.duration)?Math.max(0,video.duration-.1):Infinity);}catch(ignore){}};
            if(hls){hls.destroy();hls=null;}
            var url=String(b.url),m=url.match(/(?:^|\/)api\.php\?action=([a-z_]+)&?(.*)$/);
            if(m)url=A.base+'api/live/'+m[1]+(m[2]?'?'+m[2]:'');
            else if(!/^(https?:\/\/|\/)/i.test(url))url=A.base+'live/'+url;
            if(A.streamPlayUrl)url=A.streamPlayUrl(url);
            playStream(video,url,function(){return cur(t)()&&mine===sequence;}).then(function(stream){if(!cur(t)()||mine!==sequence){if(stream)stream.destroy();return;}hls=stream;}).catch(function(e){if(cur(t)()&&mine===sequence){note.textContent=e.message||'Could not play this channel.';later(30000);}});
            if(Number(b.endUnix)>0)later(Number(b.endUnix)-Number(b.serverNow||got)-(got-asked)/2+250);
          }).catch(function(){if(cur(t)()&&mine===sequence){note.textContent='PseudoTV is unavailable. Retrying…';later(30000);}});
        }
        function channels(){return A.ensureLiveData(false).then(function(){return ((A.liveData().guide||{}).channels||[]).filter(function(ch){return ch.kind==='relay';});});}
        function choose(){
          channels().then(function(list){if(!cur(t)())return;modal('Choose a PseudoTV channel',function(b,close){
            if(!list.length){b.appendChild(h('p',{className:'muted',text:'No PseudoTV channels are available. Set them up in PseudoTV Studio.'}));return;}
            list.forEach(function(ch){b.appendChild(btn((ch.number!=null?ch.number+' · ':'')+ch.name,function(){if(!cur(t)()){close();return;}close();s.channel=ch.id;ctx.save(Object.assign({},s,{mode:'pseudotv',channel:ch.id}),true);channel=ch;name.textContent=ch.name;tune();},'ghost'));});
          });}).catch(function(){if(cur(t)())note.textContent='Could not load PseudoTV channels.';});
        }
        if((A.userAreaAllowed&&!A.userAreaAllowed('live'))||!(A.state.home&&A.state.home.features&&A.state.home.features.live)){note.textContent='Live access is required for PseudoTV.';return;}
        video.addEventListener('ended',tune);video.addEventListener('error',function(){if(cur(t)())later(5000);});
        cleanup.push(function(){sequence++;clearTimeout(timer);video.onloadedmetadata=null;video.removeEventListener('ended',tune);});
        channels().then(function(list){if(!cur(t)())return;channel=list.filter(function(ch){return ch.id===s.channel;})[0]||null;if(channel){name.textContent=channel.name;tune();}else note.textContent=s.channel?'The saved channel is unavailable. Choose another channel.':'Choose a PseudoTV channel to watch.';}).catch(function(){if(cur(t)())note.textContent='Could not load PseudoTV channels.';});
      }
      function radio(){
        var t = ++token, audio = h('audio', { preload:'none' }), title = h('strong', { text:'Radio' }), now = h('p', { className:'vc-radio-now', 'aria-live':'polite' }), art = h('div', { className:'vc-radio-art', 'aria-hidden':'true' }, [h('span'), h('span'), h('span'), h('span'), h('span')]);
        var play = btn('▶', function(){ if (audio.paused) begin(); else audio.pause(); }, 'primary vc-icon-btn', { 'aria-label':'Play' });
        audio.addEventListener('play', function(){ play.textContent = 'Ⅱ'; play.setAttribute('aria-label', 'Pause'); art.classList.add('on'); }); audio.addEventListener('pause', function(){ play.textContent = '▶'; play.setAttribute('aria-label', 'Play'); art.classList.remove('on'); });
        audio.addEventListener('error', function(){ now.textContent = 'This station could not be played.'; });
        var vol = input('range', s.volume != null ? s.volume : 0.8, { min:'0', max:'1', step:'0.05', 'aria-label':'Volume' }); audio.volume = Number(vol.value); vol.addEventListener('input', function(){ audio.volume = Number(vol.value); });
        body.replaceChildren(h('div', { className:'vc-radio' }, [art, h('div', { className:'vc-radio-copy' }, [title, now])]), bar([play, vol, btn('Station', function(){ choose(); }, 'ghost small')]));
        var station = null, stream = null;
        function begin(){ if (!station) { choose(); return; } if (A.stopRadio) A.stopRadio(); if (audio.src || stream) { audio.play().catch(function(){}); return; } playStream(audio, station.url, cur(t)).then(function(st){ stream = st; hls = st; }).catch(function(e){ now.textContent = e.message || 'Could not play.'; }); }
        function meta(){ if (!station || audio.paused) return; call('/misc/widget/radio-now?' + (station.id ? 'id=' + encodeURIComponent(station.id) : 'url=' + encodeURIComponent(station.url))).then(function(j){ if (cur(t)()) now.textContent = j.title ? '♪ ' + j.title : (station.tags || 'Live radio'); }).catch(function(){}); }
        var mt = setInterval(meta, 30000); cleanup.push(function(){ clearInterval(mt); });
        audio.addEventListener('playing', meta);
        function set(st){ station = st; title.textContent = st ? st.name : 'Radio'; now.textContent = st ? (st.tags || 'Live radio') : 'Choose a station.'; }
        function choose(){
          (A.loadRadio ? A.loadRadio() : Promise.resolve([])).catch(function(){ return []; }).then(function(stations){
            modal('Choose a station', function(b, close){
              var l = h('div', { className:'vc-pick-list' }, stations.map(function(st){ return h('button', { type:'button', className:'vc-pick', onClick:function(){ close(); ctx.save(Object.assign({}, s, { station:st.id, stream_url:'', stream_name:'' }), true); s.station = st.id; teardown(); radio(); } }, [st.logo ? h('img', { src:st.logo, alt:'', loading:'lazy' }) : null, h('span', { text:st.name })]); }));
              var u = input('url', s.stream_url || '', { placeholder:'https://stream.example.com/live.mp3', 'aria-label':'Stream address' }), n = input('text', s.stream_name || '', { placeholder:'Name', 'aria-label':'Station name' });
              b.append(stations.length ? l : h('p', { className:'muted', text:'No shared stations yet. Add one by address below, or ask an admin to add stations under Live → Radio.' }), h('form', { className:'vc-form', onSubmit:function(e){ e.preventDefault(); if (!/^https?:\/\//i.test(u.value)) { A.toast('Use an http(s) stream address'); return; } close(); ctx.save(Object.assign({}, s, { station:'', stream_url:u.value, stream_name:n.value }), true); s.station = ''; s.stream_url = u.value; s.stream_name = n.value; teardown(); radio(); } }, [h('h3', { text:'Custom stream' }), n, u, h('button', { type:'submit', className:'btn primary', text:'Use this stream' })]));
            });
          });
        }
        if (s.stream_url) set({ id:'', name:s.stream_name || 'Internet radio', url:s.stream_url });
        else (A.loadRadio ? A.loadRadio() : Promise.resolve([])).then(function(list){ if (!cur(t)()) return; set(list.filter(function(x){ return x.id === s.station; })[0] || null); }).catch(function(){ set(null); });
        cleanup.push(function(){ audio.pause(); });
      }
      function movie(){
        var t = ++token, video = h('video', { playsinline:true, controls:true, className:'vc-media-video', preload:'metadata' }), title = h('strong', { text:'Film' }), overlay = h('div', { className:'vc-media-over', hidden:true });
        var item = null, lastSave = 0, countdown = 0;
        body.replaceChildren(video, overlay, bar([title, btn('Change film', function(){ browse(); }, 'ghost small'), btn('⛶', function(){ if (video.requestFullscreen) video.requestFullscreen().catch(function(){}); }, 'ghost vc-icon-btn', { 'aria-label':'Full screen' })]));
        function progress(force){ if (!item || !video.duration) return; var now = Date.now(); if (!force && now - lastSave < 15000) return; lastSave = now; try { fetch(A.API + '/progress', { method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'application/json' }, body:JSON.stringify({ item_id:item.id, position:video.currentTime, duration:video.duration }), keepalive:true }).catch(function(){}); } catch (e) {} }
        video.addEventListener('timeupdate', function(){ progress(false); }); video.addEventListener('pause', function(){ progress(true); });
        video.addEventListener('ended', function(){ progress(true); finished(); });
        function load(it, fromStart){
          item = it; title.textContent = it.title + (it.year ? ' (' + it.year + ')' : ''); overlay.hidden = true; clearInterval(countdown);
          api('/items/' + encodeURIComponent(it.id) + '/play', { method:'POST', body:{} }).then(function(j){
            if (!cur(t)()) return; if (!j.ok) { overlay.hidden = false; overlay.replaceChildren(h('p', { text:j.error || 'This film cannot play here.' }), btn('Choose another', browse, 'primary')); return; }
            video.src = A.streamPlayUrl(j.url);
            var pos = j.progress && Number(j.progress.position || j.progress.t || 0);
            video.addEventListener('loadedmetadata', function once(){ video.removeEventListener('loadedmetadata', once); if (!fromStart && pos > 30 && video.duration && pos < video.duration - 90) video.currentTime = pos; video.play().catch(function(){}); });
            ctx.save(Object.assign({}, s, { item:{ id:it.id, title:it.title, year:it.year, poster:it.poster } }), true); s.item = it;
          }).catch(err);
        }
        function finished(){
          overlay.hidden = false; overlay.replaceChildren(h('p', { className:'muted', text:'Finding what\u2019s next…' }));
          call('/misc/movies/next', { method:'POST', body:{ after:item ? item.id : '', source:s.next || '', queue:(s.queue || []).map(function(q){ return q.id; }) } }).then(function(j){
            if (!cur(t)()) return;
            var nx = j.item, auto = !!s.autoplay && nx;
            var label = h('span', { text:nx ? (auto ? 'Up next in 10 s' : 'Up next') : 'That was the last one.' });
            overlay.replaceChildren(h('div', { className:'vc-media-next' }, [
              nx ? h('img', { src:url(nx.poster), alt:'', className:'vc-next-poster' }) : null,
              h('div', { className:'vc-next-copy' }, [h('p', { className:'eyebrow' }, [label]), nx ? h('h3', { text:nx.title + (nx.year ? ' (' + nx.year + ')' : '') }) : null,
                h('div', { className:'vc-overlay-actions' }, [nx ? btn('Play next', function(){ advanceQueue(nx); load(nx, true); }, 'primary') : null, btn('Replay', function(){ load(item, true); }, 'ghost'), btn('Browse library', browse, 'ghost'), btn('Auto-play: ' + (s.autoplay ? 'on' : 'off'), function(){ s.autoplay = !s.autoplay; ctx.save(Object.assign({}, s), true); finished(); }, 'ghost'), btn('Next from…', sourcePicker, 'ghost')])])]));
            if (auto) { var n = 10; clearInterval(countdown); countdown = setInterval(function(){ if (!cur(t)() || overlay.hidden) { clearInterval(countdown); return; } n--; label.textContent = 'Up next in ' + n + ' s'; if (n <= 0) { clearInterval(countdown); advanceQueue(nx); load(nx, true); } }, 1000); }
          }).catch(function(e){ overlay.replaceChildren(h('p', { text:(e && e.error) || 'Could not pick the next film.' }), btn('Browse library', browse, 'primary'), btn('Replay', function(){ load(item, true); }, 'ghost')); });
        }
        function advanceQueue(nx){ if (s.next === 'queue' && s.queue) { s.queue = s.queue.filter(function(q){ return q.id !== nx.id; }); ctx.save(Object.assign({}, s), true); } }
        function sourcePicker(){
          call('/misc/movies/sources').then(function(src){ modal('Auto-play next from', function(b, close){
            var opts = [['', 'Something unwatched'], ['queue', 'My board queue (' + (s.queue || []).length + ')']].concat(src.collections.map(function(c){ return ['collection:' + c.id, 'Collection: ' + c.name]; })).concat(src.genres.slice(0, 40).map(function(g){ return ['genre:' + g.name, 'Genre: ' + g.name]; }));
            var sel = select(opts, s.next || ''), auto = input('checkbox', !!s.autoplay);
            b.appendChild(h('form', { className:'vc-form', onSubmit:function(e){ e.preventDefault(); s.next = sel.value; s.autoplay = auto.checked; ctx.save(Object.assign({}, s), true); close(); if (!overlay.hidden) finished(); } }, [field('Source', sel), h('label', { className:'vc-check' }, [auto, h('span', { text:'Start the next film automatically (10-second countdown)' })]), h('button', { type:'submit', className:'btn primary', text:'Save' })]));
          }); }).catch(err);
        }
        function browse(){
          modal('Choose a film', function(b, close){
            var q = input('search', '', { placeholder:'Search the library', 'aria-label':'Search films' }), grid = h('div', { className:'vc-film-grid' }), tm = 0;
            var queueBox = h('div', { className:'vc-queue' });
            function drawQueue(){ var qs = s.queue || []; queueBox.replaceChildren(h('p', { className:'eyebrow', text:'Queue (' + qs.length + ')' }), qs.length ? h('div', { className:'vc-chip-row' }, qs.map(function(x, i){ return h('span', { className:'vc-chip' }, [x.title, h('button', { type:'button', className:'vc-chip-x', text:'×', 'aria-label':'Remove ' + x.title, onClick:function(){ s.queue.splice(i, 1); ctx.save(Object.assign({}, s), true); drawQueue(); } })]); })) : h('small', { className:'muted', text:'Add films with “+ Queue” to play them in order.' })); }
            function search(){ call('/misc/movies?n=36&q=' + encodeURIComponent(q.value.trim())).then(function(j){ grid.replaceChildren.apply(grid, j.items.length ? j.items.map(function(it){ return h('div', { className:'vc-film' }, [h('button', { type:'button', className:'vc-film-play', onClick:function(){ close(); load(it, false); }, title:'Play ' + it.title }, [h('img', { src:url(it.poster), alt:'', loading:'lazy' }), h('span', { text:it.title + (it.year ? ' (' + it.year + ')' : '') })]), btn('+ Queue', function(){ s.queue = (s.queue || []).concat([{ id:it.id, title:it.title }]).slice(-50); s.next = 'queue'; ctx.save(Object.assign({}, s), true); drawQueue(); A.toast('Added to the queue'); }, 'ghost small')]); }) : [h('p', { className:'muted', text:'No films found.' })]); }).catch(function(e){ grid.replaceChildren(h('p', { className:'muted', text:(e && e.error) || 'Library unavailable.' })); }); }
            q.addEventListener('input', function(){ clearTimeout(tm); tm = setTimeout(search, 300); });
            drawQueue(); b.append(q, queueBox, grid, h('div', { className:'vc-overlay-actions' }, [btn('Auto-play settings', function(){ close(); sourcePicker(); }, 'ghost')])); search(); setTimeout(function(){ q.focus(); }, 50);
          });
        }
        cleanup.push(function(){ progress(true); clearInterval(countdown); });
        if (s.item && s.item.id) load(s.item, false); else { overlay.hidden = false; overlay.replaceChildren(h('p', { className:'muted', text:'Pick a film to play on this board.' }), btn('Browse library', browse, 'primary')); }
      }
      function webcams(){
        var t = ++token, stage = h('div', { className:'vc-cam-stage vc-media-cams' });
        body.replaceChildren(stage);
        myCams().then(function(all){
          if (!cur(t)()) return;
          var list = s.camMode === 'single' ? all.filter(function(c){ return c.id === s.cam; }).slice(0, 1) : pickCams(all, s.camIds);
          if (!list.length && all.length) list = all.slice(0, 1);
          if (!list.length) { stage.replaceChildren(h('p', { className:'vc-kb-empty', text:'No saved webcams yet. Add some in a Webcams widget, then choose them in this widget’s settings.' })); return; }
          var cyc = camCycle(stage, list, { seconds:s.camSeconds || 30, pauseOnHover:!!s.camHoverPause, fade:0.8 });
          cleanup.push(cyc.stop);
        }).catch(function(e){ stage.replaceChildren(h('p', { className:'vc-kb-empty', text:(e && e.error) || 'Webcams unavailable.' })); });
      }
      function startMode(){ teardown(); if (mode === 'radio') radio(); else if (mode === 'movie') movie(); else if (mode === 'webcams') webcams(); else if(mode==='pseudotv')pseudotv(); else iptv(); }
      startMode();
      return function(){ teardown(); };
    },
    form:function(s){
      var base = textForm([{ key:'mode', label:'Play', type:'select', value:s.mode || 'iptv', options:[['pseudotv','PseudoTV channel'],['iptv', 'Live TV (IPTV)'], ['radio', 'Internet radio'], ['movie', 'A film from the library'], ['webcams', 'Webcams']] }, { key:'muted', label:'Start live TV muted', type:'checkbox', value:s.muted !== false }]);
      var modeSel = base.el.querySelector('select'), camBox = h('div', { className:'vc-form' }, [h('p', { className:'muted', text:'Loading your webcams…' })]), single = null, multi = null;
      var camMode = select([['cycle', 'Cycle through webcams'], ['single', 'A single webcam']], s.camMode || 'cycle'), secs = input('number', s.camSeconds || 30, { min:'10', max:'600' }), hover = input('checkbox', !!s.camHoverPause);
      myCams(true).catch(function(){ return []; }).then(function(all){
        single = camChooser(all, s.cam, true); multi = camChooser(all, s.camIds, false);
        var one = field('Webcam', single.el), many = field('Webcams in the cycle', multi.el), sec = field('Seconds per webcam (10 s to 10 min)', secs), hv = h('label', { className:'vc-check' }, [hover, h('span', { text:'Pause while the pointer is over it' })]);
        function sync(){ var c = camMode.value === 'cycle'; one.hidden = c; many.hidden = !c; sec.hidden = !c; hv.hidden = !c; }
        camMode.addEventListener('change', sync); sync();
        camBox.replaceChildren(field('Show', camMode), one, many, sec, hv, h('small', { className:'muted', text:'Webcams come from your saved list (edit it in any Webcams widget). One player runs at a time.' }));
      });
      function sync(){ camBox.hidden = modeSel.value !== 'webcams'; }
      modeSel.addEventListener('change', sync); sync();
      return { el:h('div', { className:'vc-form' }, [base.el, camBox]), read:function(){ var o = base.read(); o.camMode = camMode.value; o.camSeconds = Math.max(10, Math.min(600, Number(secs.value) || 30)); o.camHoverPause = hover.checked; if (single) o.cam = single.read(); if (multi) o.camIds = multi.read(); return o; } };
    },
    merge:true
  };

  W.pseudotv = {name:'PseudoTV',desc:'Watch one of your scheduled PseudoTV channels on this board.',icon:'📺',w:12,h:8,
    render:function(body,s,ctx){return W.media.render(body,Object.assign({},s,{mode:'pseudotv'}),ctx);},
    form:function(s){return textForm([{key:'muted',label:'Start muted',type:'checkbox',value:s.muted!==false}]);},merge:true};

  // ------------------------------------------------------------------ Board (dashboard grid)
  var COLS = 24;
  /* Saved boards may use older type keys or settings shapes; map them to the current ones when a board loads.
     The normalised form is written back on the next save. */
  var WIDGET_ALIASES = { recently_added:'recent', recentlyadded:'recent', 'recently-added':'recent', now_playing:'nowplaying', 'now-playing':'nowplaying',
    bins:'reminders', binday:'reminders', bin_day:'reminders', 'bin-day':'reminders', picture_frame:'frame', pictureframe:'frame', webcam:'webcams',
    nas_health:'health', nashealth:'health', news:'rss', note:'notes', slides:'slideshow', recording:'recordings', download:'downloads' };
  function widgetType(t, aliases){ var k = String(t || '').trim().toLowerCase(); if (W[k]) return k; k = (aliases && aliases[k]) || WIDGET_ALIASES[k] || ''; return W[k] ? k : ''; }
  function normaliseWidget(w, aliases){
    if (!w || typeof w !== 'object') return w;
    var type = widgetType(w.type, aliases); if (type) w.type = type;
    var s = w.settings = w.settings && typeof w.settings === 'object' && !Array.isArray(w.settings) ? w.settings : {};
    if (w.type === 'recent' && s.count != null) s.count = Math.max(4, Math.min(30, Number(s.count) || 12));
    if (w.type === 'slideshow' && ['frames', 'posters', 'mixed'].indexOf(s.source) >= 0) { s.art = s.art || s.source; s.source = 'library'; }
    return w;
  }
  function normaliseBoards(d){ (d.boards || []).forEach(function(b){ b.widgets = (Array.isArray(b.widgets) ? b.widgets : []).map(function(w){ return normaliseWidget(w, d.aliases); }); }); return d; }
  var STARTER = [{ type:'clock', x:0, y:0, w:6, h:4 }, { type:'weather', x:6, y:0, w:8, h:6 }, { type:'reminders', x:14, y:0, w:5, h:6 }, { type:'notes', x:19, y:0, w:5, h:6, settings:{ text:'[ ] Welcome to your board\n[ ] Press Edit to move, resize and add widgets' } }, { type:'recent', x:0, y:6, w:14, h:6 }, { type:'nowplaying', x:14, y:6, w:10, h:6 }];
  // Ready-made layouts for "New board".
  var TEMPLATES = {
    starter:['Starter', STARTER],
    kitchen:['Kitchen', [{ type:'clock', x:0, y:0, w:6, h:4 }, { type:'weather', x:6, y:0, w:10, h:6 }, { type:'reminders', x:16, y:0, w:8, h:6 }, { type:'calendar', x:0, y:4, w:6, h:8 }, { type:'notes', x:6, y:6, w:10, h:6, settings:{ text:'[ ] Shopping list' } }, { type:'media', x:16, y:6, w:8, h:6 }]],
    lounge:['Living-room TV', [{ type:'clock', x:0, y:0, w:6, h:4 }, { type:'weather', x:0, y:4, w:6, h:6 }, { type:'recent', x:6, y:0, w:18, h:6 }, { type:'nowplaying', x:6, y:6, w:9, h:5 }, { type:'recordings', x:15, y:6, w:9, h:5 }]],
    server:['Server room', [{ type:'health', x:0, y:0, w:8, h:7 }, { type:'downloads', x:8, y:0, w:8, h:7 }, { type:'recordings', x:16, y:0, w:8, h:7 }, { type:'nowplaying', x:0, y:7, w:12, h:5 }, { type:'clock', x:12, y:7, w:6, h:5 }, { type:'drop', x:18, y:7, w:6, h:5 }]],
    games:['Game night', [{ type:'leaderboard', x:0, y:0, w:8, h:8 }, { type:'leaderboard', x:8, y:0, w:8, h:8, settings:{ board:'teams' } }, { type:'clock', x:16, y:0, w:8, h:4 }, { type:'notes', x:16, y:4, w:8, h:4, settings:{ text:'Tonight:\n[ ] Quiz\n[ ] Chess rematch' } }]]
  };
  function fromTemplate(list){ return list.map(function(w){ return Object.assign({ id:rid(8), settings:{} }, JSON.parse(JSON.stringify(w))); }); }
  // Widgets that refresh on their own get a "Refresh every" setting even when they have no other options.
  var POLLED = ['weather', 'calendar', 'reminders', 'recordings', 'downloads', 'health', 'recent', 'nowplaying', 'rss', 'drop', 'leaderboard'];
  function hasSettings(type){ return !!((W[type] && W[type].form) || POLLED.indexOf(type) >= 0); }
  function overlaps(a, b){ return a !== b && a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h; }
  /**
   * Free placement: widgets keep their exact x/y/w/h and gaps stay gaps. After a move or resize, only widgets
   * that the moved one now overlaps are pushed down, by the minimum needed (to just below it), and that cascades
   * only to widgets those pushed ones then overlap. Nothing is ever pulled up to fill space.
   */
  function settle(widgets, moved){
    clampWidget(moved);
    var queue = [moved], guard = 0;
    while (queue.length && guard++ < 2000) {
      var a = queue.shift();
      widgets.forEach(function(o){ if (o === moved || o === a || !overlaps(a, o)) return; o.y = a.y + a.h; queue.push(o); });
    }
  }
  function clampWidget(w){
    w.w = Math.max(1, Math.min(COLS, Math.round(Number(w.w)) || 4)); w.h = Math.max(1, Math.min(24, Math.round(Number(w.h)) || 3));
    w.x = Math.max(0, Math.min(COLS - w.w, Math.round(Number(w.x)) || 0)); w.y = Math.max(0, Math.round(Number(w.y)) || 0);
  }
  /**
   * Load-time check only: keeps saved positions as they are. Widgets saved without a position (very old layouts)
   * get one from their visual order, and any saved overlap is resolved by pushing down. Returns true when
   * something changed so the caller saves once.
   */
  function fixLayout(widgets){
    var changed = false, placed = [];
    widgets.forEach(function(w){
      var before = [w.x, w.y, w.w, w.h].join(), missing = !isFinite(parseFloat(w.x)) || !isFinite(parseFloat(w.y));
      clampWidget(w);
      if (missing) { var sp = freeSpot(placed, w.w, w.h); w.x = sp.x; w.y = sp.y; }
      if ([w.x, w.y, w.w, w.h].join() !== before) changed = true;
      placed.push(w);
    });
    placed.slice().sort(function(a, b){ return a.y - b.y || a.x - b.x; }).forEach(function(w, i, arr){
      for (var k = 0; k < i; k++) if (overlaps(arr[k], w)) { w.y = arr[k].y + arr[k].h; changed = true; k = -1; }
    });
    return changed;
  }
  function freeSpot(widgets, w, hgt){
    for (var y = 0; y < 400; y++) for (var x = 0; x + w <= COLS; x++) { var c = { x:x, y:y, w:w, h:hgt }; if (!widgets.some(function(o){ return overlaps(c, o); })) return c; }
    return { x:0, y:widgets.reduce(function(m, o){ return Math.max(m, o.y + o.h); }, 0), w:w, h:hgt };
  }
  function board(host, sub){
    if (!signed()) { host.appendChild(empty('Sign in to build boards', 'Boards are saved to your account.')); return; }
    var data = null, cur = null, editing = false, saveT = 0, mounted = {}, grid = h('div', { className:'vc-board-grid', role:'list' }), head = h('div', { className:'vc-board-head' }), shell = h('div', { className:'vc-board' }, [head, grid]);
    host.appendChild(shell);
    grid.appendChild(A.pendingContent({ text:'Loading your boards…' }));
    function save(now){ clearTimeout(saveT); saveT = setTimeout(function(){ call('/misc/boards', { method:'POST', body:{ boards:data.boards, default:data.default, rotate:data.rotate || 0 } }).then(function(j){ data.boards.forEach(function(b, i){ if (j.boards[i] && b.id !== j.boards[i].id) b.id = j.boards[i].id; }); }).catch(err); }, now ? 0 : 800); }
    onCleanup(function(){ clearTimeout(saveT); Object.keys(mounted).forEach(function(k){ unmount(k); }); exitKiosk(); });
    var watch = setInterval(function(){ if (!shell.isConnected) { clearInterval(watch); stopAll(); } }, 3000); onCleanup(function(){ clearInterval(watch); });
    function unmount(id){ var m = mounted[id]; if (m && m.cleanup) try { m.cleanup(); } catch (e) {} delete mounted[id]; }
    function headBar(){
      var sel = select(data.boards.map(function(b){ return [b.id, b.name + (b.id === data.default ? ' ★' : '')]; }), cur.id, { 'aria-label':'Board', className:'vc-board-select', onChange:function(){ switchTo(this.value); } });
      head.replaceChildren(
        h('div', { className:'vc-board-title' }, [sel, editing ? null : h('span', { className:'muted', text:cur.widgets.length + ' widgets' })]),
        h('div', { className:'vc-board-tools' }, editing ? [btn('+ Add widget', addWidget, 'primary'), btn('Board settings', boardSettings, 'ghost'), btn('Done', function(){ setEdit(false); }, 'primary')]
          : [cur.locked ? btn('🔒 Locked', function(){ A.confirmDialog('Unlock the layout of “' + cur.name + '” so it can be edited?', { okText:'Unlock' }).then(function(ok){ if (!ok) return; cur.locked = false; save(true); setEdit(true); }); }, 'ghost', { title:'This layout is locked. Click to unlock it.' }) : btn('Edit', function(){ setEdit(true); }, 'ghost'), btn('New board', newBoard, 'ghost'), btn('Kiosk', function(){ enterKiosk(); }, 'primary', { title:'Full screen, hide the menus and keep the screen awake' })]));
    }
    function switchTo(id, quiet){ var b = data.boards.filter(function(x){ return x.id === id; })[0]; if (!b) return; cur = b; A.state.miscSub = id; if (!quiet) A.pushNav(); paint(); }
    function setEdit(on){ editing = on; shell.classList.toggle('editing', on); paint(); }
    function newBoard(){
      modal('New board', function(b, close){
        var name = input('text', '', { placeholder:'e.g. Kitchen', maxlength:'40', 'aria-label':'Board name' });
        var tpl = select([['', 'Empty board']].concat(Object.keys(TEMPLATES).map(function(k){ return [k, TEMPLATES[k][0]]; })), 'starter', { 'aria-label':'Template' });
        var file = h('input', { type:'file', accept:'application/json,.json', hidden:true });
        function add(nb){ if (data.boards.length >= 12) { A.toast('Up to 12 boards'); return false; } data.boards.push(nb); save(true); close(); switchTo(nb.id); return true; }
        file.addEventListener('change', function(){
          var f = file.files && file.files[0]; if (!f) return;
          if (f.size > 400000) { A.toast('That file is too large for a board layout.', { kind:'bad' }); return; }
          f.text().then(function(txt){
            var j = JSON.parse(txt), src = j && (j.board || j);
            if (!src || !Array.isArray(src.widgets)) throw new Error('Not a board layout');
            var widgets = src.widgets.map(function(w){ return normaliseWidget(w, data.aliases); }).filter(function(w){ return w && W[w.type]; }).slice(0, 40).map(function(w){ return { id:rid(8), type:w.type, x:+w.x || 0, y:+w.y || 0, w:+w.w || 4, h:+w.h || 3, settings:(w.settings && typeof w.settings === 'object') ? w.settings : {} }; });
            add({ id:rid(10), name:String(name.value.trim() || src.name || 'Imported board').slice(0, 40), theme:src.theme || 'glass', background:typeof src.background === 'string' ? src.background : '', color:typeof src.color === 'string' ? src.color : '', locked:false, widgets:widgets });
            A.toast('Board imported', { kind:'ok' });
          }).catch(function(){ A.toast('That file is not a Vaultcast board layout.', { kind:'bad' }); });
        });
        b.appendChild(h('form', { className:'vc-form', onSubmit:function(e){ e.preventDefault(); var t = TEMPLATES[tpl.value]; add({ id:rid(10), name:name.value.trim() || (t ? t[0] : 'Board'), theme:'glass', background:'', color:'', locked:false, widgets:t ? fromTemplate(t[1]) : [] }); if (!t) setEdit(true); } }, [field('Name', name), field('Start from', tpl),
          h('div', { className:'vc-overlay-actions' }, [h('button', { type:'submit', className:'btn primary', text:'Create board' }), btn('Import layout (JSON)…', function(){ file.click(); }, 'ghost'), file])]));
        setTimeout(function(){ name.focus(); }, 50);
      });
    }
    function boardSettings(){
      modal('Board settings', function(b, close){
        var isImg = /^(https?:\/\/|\/)/.test(cur.background || '');
        var name = input('text', cur.name, { maxlength:'40', 'aria-label':'Board name' }), theme = select([['glass', 'Glass (frosted panels)'], ['solid', 'Solid panels'], ['clear', 'Minimal (no panels)']], cur.theme || 'glass'), bg = input('url', isImg ? cur.background : '', { placeholder:'https://… (optional)' }), colour = input('color', cur.color || '#10162a', { 'aria-label':'Background colour' });
        var bgKind = select([['', 'Theme background'], ['aurora', 'Aurora gradient'], ['colour', 'Plain colour'], ['image', 'Image link']], isImg ? 'image' : (cur.color ? 'colour' : (cur.background || ''))), def = input('checkbox', data.default === cur.id), lock = input('checkbox', !!cur.locked);
        var rotate = select([['0', 'Off'], ['30', 'Every 30 seconds'], ['60', 'Every minute'], ['120', 'Every 2 minutes'], ['300', 'Every 5 minutes'], ['900', 'Every 15 minutes']], String(data.rotate || 0), { 'aria-label':'Rotate boards' });
        function syncBg(){ bg.closest('.vc-field').hidden = bgKind.value !== 'image'; colour.closest('.vc-field').hidden = bgKind.value !== 'colour'; }
        bgKind.addEventListener('change', syncBg);
        function exportLayout(){
          var out = { vaultcast_board:1, board:{ name:cur.name, theme:cur.theme, background:cur.background || '', color:cur.color || '', widgets:cur.widgets.map(function(w){ return { type:w.type, x:w.x, y:w.y, w:w.w, h:w.h, settings:w.settings || {} }; }) } };
          var a = h('a', { href:URL.createObjectURL(new Blob([JSON.stringify(out, null, 2)], { type:'application/json' })), download:(cur.name || 'board').replace(/[^\w.-]+/g, '-') + '.board.json' });
          document.body.appendChild(a); a.click(); setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); }, 1000);
        }
        b.appendChild(h('form', { className:'vc-form', onSubmit:function(e){ e.preventDefault(); cur.name = name.value.trim() || 'Board'; cur.theme = theme.value; cur.background = bgKind.value === 'image' ? bg.value.trim() : (bgKind.value === 'aurora' ? 'aurora' : ''); cur.color = bgKind.value === 'colour' ? colour.value : ''; cur.locked = lock.checked; data.rotate = Number(rotate.value) || 0; if (def.checked) data.default = cur.id; save(true); close(); if (cur.locked) setEdit(false); else paint(); startRotation(); } }, [
          field('Name', name), field('Panel style', theme), field('Background', bgKind), field('Background image link', bg), field('Background colour', colour),
          h('label', { className:'vc-check' }, [def, h('span', { text:'Open this board by default' })]), h('label', { className:'vc-check' }, [lock, h('span', { text:'Lock the layout (prevents accidental edits on shared screens)' })]),
          field('Rotate between boards', rotate, 'Cycles through all your boards while this page is visible — handy for kiosk screens.'),
          h('div', { className:'vc-overlay-actions' }, [h('button', { type:'submit', className:'btn primary', text:'Save' }), btn('Duplicate', function(){ var copy = JSON.parse(JSON.stringify(cur)); copy.id = rid(10); copy.name = cur.name + ' copy'; copy.locked = false; copy.widgets.forEach(function(w){ w.id = rid(8); }); if (data.boards.length >= 12) { A.toast('Up to 12 boards'); return; } data.boards.push(copy); save(true); close(); switchTo(copy.id); }, 'ghost'),
            btn('Export layout', exportLayout, 'ghost'),
            data.boards.length > 1 ? btn('Delete board', function(){ A.confirmDialog('Delete the board “' + cur.name + '”?', { okText:'Delete', danger:true }).then(function(ok){ if (!ok) return; data.boards = data.boards.filter(function(x){ return x !== cur; }); if (data.default === cur.id) data.default = data.boards[0].id; save(true); close(); switchTo(data.boards[0].id); }); }, 'ghost danger') : null])]));
        syncBg();
      });
    }
    var rotStop = null;
    function startRotation(){
      if (rotStop) { rotStop(); rotStop = null; }
      if (!data.rotate || data.boards.length < 2) return;
      rotStop = poll(function(){ if (editing || !shell.isConnected) return; var i = data.boards.indexOf(cur); switchTo(data.boards[(i + 1) % data.boards.length].id, true); }, data.rotate * 1000);
    }
    onCleanup(function(){ if (rotStop) rotStop(); });
    function addWidget(){
      modal('Add a widget', function(b, close){
        b.appendChild(h('div', { className:'vc-widget-catalogue' }, data.widgets.filter(function(t){ return W[t]; }).map(function(t){ var d = W[t]; return h('button', { type:'button', className:'vc-catalogue-item', onClick:function(){ close(); var spot = freeSpot(cur.widgets, d.w, d.h), w = { id:rid(8), type:t, x:spot.x, y:spot.y, w:d.w, h:d.h, settings:{} }; cur.widgets.push(w); save(); paint(); if (d.form) setTimeout(function(){ widgetSettings(w); }, 60); } }, [h('span', { className:'vc-catalogue-icon', text:d.icon, 'aria-hidden':'true' }), h('strong', { text:d.name }), h('small', { className:'muted', text:d.desc })]); })));
      });
    }
    function widgetSettings(w){
      var d = W[w.type]; if (!d || !hasSettings(w.type)) return;
      modal(d.name + ' settings', function(b, close){
        var f = d.form ? d.form(w.settings || {}) : { el:h('div'), read:function(){ return {}; } };
        var polled = POLLED.indexOf(w.type) >= 0, cur0 = (w.settings && w.settings.refresh) || '';
        var refresh = select([['', 'Automatic'], ['15', 'Every 15 seconds'], ['30', 'Every 30 seconds'], ['60', 'Every minute'], ['300', 'Every 5 minutes'], ['900', 'Every 15 minutes'], ['3600', 'Every hour']], String(cur0), { 'aria-label':'Refresh interval' });
        b.appendChild(h('form', { className:'vc-form', onSubmit:function(e){ e.preventDefault(); var next = Object.assign({}, w.settings || {}, f.read()); if (polled) { if (refresh.value) next.refresh = Number(refresh.value); else delete next.refresh; } w.settings = next; save(); close(); remount(w); } }, [f.el, polled ? field('Refresh', refresh, 'Widgets pause while this tab is hidden and catch up when you come back.') : null, h('div', { className:'vc-overlay-actions' }, [h('button', { type:'submit', className:'btn primary', text:'Save' })])]));
      });
    }
    function place(el, w){ el.style.gridColumn = (w.x + 1) + ' / span ' + w.w; el.style.gridRow = (w.y + 1) + ' / span ' + w.h; el.style.setProperty('--vc-rows', String(Math.max(4, w.h))); }
    function placeAll(){ cur.widgets.forEach(function(x){ var n = grid.querySelector('[data-wid="' + x.id + '"]'); if (n) place(n, x); }); }
    function metrics(){ var r = grid.getBoundingClientRect(), cs = getComputedStyle(grid), gap = parseFloat(cs.columnGap) || 0, row = parseFloat(cs.gridAutoRows) || 40; return { left:r.left, top:r.top, col:(r.width + gap) / COLS, row:row + (parseFloat(cs.rowGap) || 0) }; }
    // The single-column phone layout is only a view: it is never dragged and never saved over desktop positions.
    function narrow(){ return !!(window.matchMedia && matchMedia('(max-width: 760px)').matches); }
    var CHROME_CONTROLS = 'button, a, input, select, textarea, label, [role="button"], .vc-w-actions, .vc-w-resize, [data-no-drag]';
    function dragify(el, w, handle, mode){
      handle.addEventListener('pointerdown', function(e){
        if (!editing || e.button > 0 || narrow()) return;
        // Controls in the widget chrome (remove, settings, any input) must get their own click, never start a drag.
        if (mode === 'move' && e.target && e.target.closest && e.target.closest(CHROME_CONTROLS)) return;
        e.preventDefault(); e.stopPropagation();
        var m = metrics(), sx = e.clientX, sy = e.clientY, ox = w.x, oy = w.y, ow = w.w, oh = w.h, ghost = h('div', { className:'vc-ghost' });
        grid.appendChild(ghost); place(ghost, w); el.classList.add('dragging');
        handle.setPointerCapture(e.pointerId);
        function move(ev){
          var dx = Math.round((ev.clientX - sx) / m.col), dy = Math.round((ev.clientY - sy) / m.row);
          if (mode === 'move') { w.x = Math.max(0, Math.min(COLS - w.w, ox + dx)); w.y = Math.max(0, oy + dy); el.style.transform = 'translate(' + (ev.clientX - sx) + 'px,' + (ev.clientY - sy) + 'px)'; }
          else { w.w = Math.max(2, Math.min(COLS - w.x, ow + dx)); w.h = Math.max(2, Math.min(24, oh + dy)); w.x = ox; w.y = oy; el.style.width = (el._w0 + ev.clientX - sx) + 'px'; el.style.height = (el._h0 + ev.clientY - sy) + 'px'; }
          place(ghost, w);
        }
        el._w0 = el.offsetWidth; el._h0 = el.offsetHeight;
        function up(){ handle.removeEventListener('pointermove', move); handle.removeEventListener('pointerup', up); handle.removeEventListener('pointercancel', up); ghost.remove(); el.classList.remove('dragging'); el.style.transform = ''; el.style.width = ''; el.style.height = ''; if (w.x === ox && w.y === oy && w.w === ow && w.h === oh) return; settle(cur.widgets, w); placeAll(); save(); }
        handle.addEventListener('pointermove', move); handle.addEventListener('pointerup', up); handle.addEventListener('pointercancel', up);
      });
      // Keyboard: arrows move, shift+arrows resize while the handle has focus.
      handle.addEventListener('keydown', function(e){
        if (!editing || narrow()) return; var k = { ArrowLeft:[-1, 0], ArrowRight:[1, 0], ArrowUp:[0, -1], ArrowDown:[0, 1] }[e.key]; if (!k) return; e.preventDefault();
        if (e.shiftKey) { w.w = Math.max(2, Math.min(COLS - w.x, w.w + k[0])); w.h = Math.max(2, Math.min(24, w.h + k[1])); } else { w.x = Math.max(0, Math.min(COLS - w.w, w.x + k[0])); w.y = Math.max(0, w.y + k[1]); }
        settle(cur.widgets, w); placeAll(); save();
      });
    }
    function remount(w){ var el = grid.querySelector('[data-wid="' + w.id + '"]'); if (!el) return paint(); unmount(w.id); var body = el.querySelector('.vc-w-body'); body.replaceChildren(); body.className = 'vc-w-body'; body.removeAttribute('style'); mountBody(w, body); }
    function mountBody(w, body){
      var d = W[w.type]; if (!d) { body.appendChild(h('p', { className:'muted', text:'This widget is not available.' })); return; }
      var ctx = { save:function(settings, quiet){ w.settings = settings; save(); if (!quiet) remount(w); }, editing:editing };
      try { mounted[w.id] = { cleanup:d.render(body, w.settings || {}, ctx) }; } catch (e) { body.appendChild(h('p', { className:'muted', text:'This widget hit a problem.' })); }
    }
    function paint(){
      Object.keys(mounted).forEach(unmount);
      headBar();
      shell.setAttribute('data-theme', cur.theme || 'glass');
      // Same rule as the server: plain http(s) or same-site paths only, never quotes, parentheses or escapes inside url().
      var img = /^(https?:\/\/|\/(?!\/))[^\s"'()<>\\]+$/i.test(cur.background || '');
      shell.style.backgroundImage = img ? 'url("' + cur.background + '")' : '';
      shell.style.backgroundColor = /^#[0-9a-f]{3}([0-9a-f]{3})?$/i.test(cur.color || '') ? cur.color : '';
      shell.classList.toggle('bg-aurora', cur.background === 'aurora'); shell.classList.toggle('bg-image', img); shell.classList.toggle('bg-colour', !!shell.style.backgroundColor); shell.classList.toggle('locked', !!cur.locked);
      grid.replaceChildren();
      if (!cur.widgets.length) grid.appendChild(h('div', { className:'vc-board-empty' }, [empty('This board is empty', 'Add clocks, weather, live TV, notes and more.', [btn('+ Add widget', function(){ if (!editing) setEdit(true); addWidget(); }, 'primary')])]));
      cur.widgets.slice().sort(function(a, b){ return a.y - b.y || a.x - b.x; }).forEach(function(w){
        var d = W[w.type] || { name:w.type, icon:'?' }, body = h('div', { className:'vc-w-body' });
        var handle = h('div', { className:'vc-w-head', tabindex:editing ? '0' : null, 'aria-label':editing ? 'Move ' + d.name + ' (arrow keys; Shift to resize)' : null }, [h('span', { className:'vc-w-icon', text:d.icon, 'aria-hidden':'true' }), h('span', { className:'vc-w-name', text:(w.settings && w.settings.title) || d.name }),
          editing ? h('span', { className:'vc-w-actions', 'data-no-drag':'', onPointerdown:function(e){ e.stopPropagation(); }, onMousedown:function(e){ e.stopPropagation(); }, onTouchstart:function(e){ e.stopPropagation(); } }, [hasSettings(w.type) ? btn('⚙', function(e){ e.stopPropagation(); widgetSettings(w); }, 'ghost vc-icon-btn small', { 'aria-label':'Settings for ' + d.name }) : null, btn('×', function(e){ e.stopPropagation(); A.confirmDialog('Remove this ' + d.name + ' widget?', { okText:'Remove', danger:true }).then(function(ok){ if (!ok) return; cur.widgets = cur.widgets.filter(function(x){ return x !== w; }); save(); paint(); }); }, 'ghost vc-icon-btn small', { 'aria-label':'Remove ' + d.name })]) : (hasSettings(w.type) && !cur.locked ? btn('⚙', function(){ widgetSettings(w); }, 'ghost vc-icon-btn small vc-w-quickset', { 'aria-label':'Settings for ' + d.name }) : null)]);
        var el = h('article', { className:'vc-widget w-' + w.type, 'data-wid':w.id, role:'listitem', 'aria-label':d.name }, [handle, body]);
        if (editing) { var rz = h('span', { className:'vc-w-resize', 'aria-hidden':'true' }); el.appendChild(rz); dragify(el, w, handle, 'move'); dragify(el, w, rz, 'resize'); }
        place(el, w); grid.appendChild(el); mountBody(w, body);
      });
    }
    // Kiosk: full screen, chrome hidden, cursor hides when idle, screen stays awake.
    var kiosk = null;
    function enterKiosk(){
      if (kiosk) return; if (editing) setEdit(false);
      document.body.classList.add('vc-kiosk'); wantWake(1);
      var exit = h('button', { type:'button', className:'vc-kiosk-exit', text:'Exit kiosk', onClick:exitKiosk }); document.body.appendChild(exit);
      var t = 0; function wake(){ document.body.classList.remove('vc-cursor-hidden'); exit.classList.add('show'); clearTimeout(t); t = setTimeout(function(){ document.body.classList.add('vc-cursor-hidden'); exit.classList.remove('show'); }, 3000); }
      function key(e){ if (e.key === 'Escape' && !document.fullscreenElement) exitKiosk(); }
      function fs(){ if (!document.fullscreenElement && kiosk && kiosk.wasFull) exitKiosk(); }
      // Track the real visible height (TV and mobile browsers differ on 100vh); the board's minimum height follows it.
      var root = document.documentElement, vv = window.visualViewport, fitT = 0;
      function fit(){ cancelAnimationFrame(fitT); fitT = requestAnimationFrame(function(){ var hgt = Math.round((vv && vv.height) || window.innerHeight || root.clientHeight); if (hgt > 0) { root.style.setProperty('--vc-kiosk-h', hgt + 'px'); document.body.classList.add('vc-kiosk-h'); } }); }
      root.classList.add('vc-kiosk-root'); fit();
      window.addEventListener('resize', fit); window.addEventListener('orientationchange', fit); if (vv) vv.addEventListener('resize', fit);
      document.addEventListener('pointermove', wake); document.addEventListener('pointerdown', wake); document.addEventListener('keydown', key); document.addEventListener('fullscreenchange', fs); document.addEventListener('fullscreenchange', fit);
      kiosk = { exit:exit, off:function(){ clearTimeout(t); cancelAnimationFrame(fitT); window.removeEventListener('resize', fit); window.removeEventListener('orientationchange', fit); if (vv) vv.removeEventListener('resize', fit);
        document.removeEventListener('pointermove', wake); document.removeEventListener('pointerdown', wake); document.removeEventListener('keydown', key); document.removeEventListener('fullscreenchange', fs); document.removeEventListener('fullscreenchange', fit);
        root.classList.remove('vc-kiosk-root'); root.style.removeProperty('--vc-kiosk-h'); document.body.classList.remove('vc-kiosk-h'); } };
      if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen().then(function(){ if (kiosk) kiosk.wasFull = true; }).catch(function(){});
      wake();
    }
    function exitKiosk(){
      if (!kiosk) return; var k = kiosk; kiosk = null; k.off(); k.exit.remove();
      document.body.classList.remove('vc-kiosk', 'vc-cursor-hidden'); wantWake(-1);
      if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(function(){});
    }
    call('/misc/boards').then(function(j){
      data = normaliseBoards(j);
      var fixed = false; data.boards.forEach(function(b){ if (fixLayout(b.widgets)) fixed = true; }); if (fixed) save(true);
      if (!data.boards.length) { data.boards = [{ id:rid(10), name:'My board', theme:'glass', background:'', widgets:STARTER.map(function(w){ return Object.assign({ id:rid(8), settings:{} }, JSON.parse(JSON.stringify(w))); }) }]; data.default = data.boards[0].id; save(true); }
      var parts = String(sub || '').split('/');
      cur = data.boards.filter(function(b){ return b.id === parts[0]; })[0] || data.boards.filter(function(b){ return b.id === data.default; })[0] || data.boards[0];
      paint(); startRotation();
      if (parts[1] === 'kiosk') enterKiosk();
    }).catch(function(e){ grid.replaceChildren(empty('Boards unavailable', (e && e.error) || '')); });
  }

  // ------------------------------------------------------------------ Drop: chunked, resumable uploads
  function kindIcon(i){ if (i.kind === 'link') return '🔗'; if (i.kind === 'text') return '📝'; var m = String(i.mime || ''); if (/^image\//.test(m)) return '🖼️'; if (/^video\//.test(m)) return '🎬'; if (/^audio\//.test(m)) return '🎵'; if (/pdf/.test(m)) return '📄'; if (/zip|compressed|tar|7z|rar/.test(m)) return '🗜️'; return '📦'; }
  var STATUS = { available:['Available', 'ok'], expired:['Expired', 'muted'], exhausted:['Download limit reached', 'muted'], uploading:['Uploading', 'warn'], deleted:['Deleted', 'muted'] };
  function fileKey(f){ return 'vaultcast-drop-up:' + [f.name, f.size, f.lastModified || 0].join('|'); }
  function eta(secs){ secs = Math.max(0, Math.round(secs)); if (secs < 60) return secs + ' s'; if (secs < 3600) return Math.floor(secs / 60) + ' min ' + (secs % 60) + ' s'; return Math.floor(secs / 3600) + ' h ' + Math.floor(secs % 3600 / 60) + ' min'; }
  /**
   * Upload one file in chunks (XHR, so progress moves within a chunk too). A previous attempt of the same file
   * (same name, size and date) resumes from what the server already has, including after a page refresh.
   * control: { paused, cancelled, abort() } — cancelling deletes the partial upload on the server.
   * onProgress({ sent, id, done, item, rate, eta })
   */
  function uploadFile(file, opts, onProgress, control){
    control = control || {};
    var key = fileKey(file), saved = store(key), id = null, chunk = 8388608, started = Date.now(), base = 0, xhr = null;
    control.abort = function(){ control.cancelled = true; if (xhr) xhr.abort(); if (control.resume) control.resume(); };
    function begin(){
      if (saved && saved.id) return api('/drop/upload/' + saved.id).then(function(j){ if (j && j.ok && !j.complete && !j.purged && j.bytes === file.size) { id = saved.id; chunk = j.chunk || chunk; return j.received; } store(key, null); return fresh(); });
      return fresh();
    }
    function fresh(){ return call('/drop/upload', { method:'POST', body:Object.assign({ name:file.name, size:file.size, mime:file.type || 'application/octet-stream' }, opts) }).then(function(j){ id = j.id; chunk = j.chunk || chunk; store(key, { id:id, at:Date.now() }); return 0; }); }
    function report(sent, extra){ var secs = (Date.now() - started) / 1000, rate = secs > 0.5 ? (sent - base) / secs : 0; onProgress(Object.assign({ sent:sent, id:id, rate:rate, eta:rate > 0 ? (file.size - sent) / rate : null }, extra || {})); }
    function piece(offset){
      return new Promise(function(resolve, reject){
        var end = Math.min(file.size, offset + chunk), x = new XMLHttpRequest(); xhr = x;
        x.open('POST', A.API + '/drop/upload/' + id + '?offset=' + offset); x.withCredentials = true; x.setRequestHeader('Content-Type', 'application/octet-stream');
        x.upload.onprogress = function(ev){ report(offset + ev.loaded); };
        x.onload = function(){ var j = {}; try { j = JSON.parse(x.responseText); } catch (e) { j = { ok:false, error:'HTTP ' + x.status }; } j.status = x.status; resolve(j); };
        x.onerror = function(){ reject({ error:'Connection lost', retry:true }); };
        x.onabort = function(){ reject({ error:'Cancelled', cancelled:true }); };
        x.send(file.slice(offset, end));
      });
    }
    function waitIfPaused(){ return control.paused ? new Promise(function(res){ control.resume = function(){ control.resume = null; started = Date.now(); res(); }; }) : Promise.resolve(); }
    // Iterative loop (no promise recursion), so a 1 GB file does not build a long promise chain.
    function run(offset){
      var tries = 0;
      base = offset; started = Date.now(); report(offset);
      return new Promise(function(resolve, reject){
        function step(){
          if (control.cancelled) return reject({ error:'Cancelled', cancelled:true });
          waitIfPaused().then(function(){
            if (control.cancelled) return reject({ error:'Cancelled', cancelled:true });
            if (offset >= file.size) { store(key, null); return resolve({}); }
            return piece(offset).then(function(j){
              if (j.status === 413) return reject({ error:'The web server refused a ' + (chunk / 1048576).toFixed(1) + ' MB upload piece (HTTP 413). Ask the administrator to raise the web server body limit (client_max_body_size / LimitRequestBody) to at least ' + Math.ceil(chunk / 1048576 + 1) + ' MB.', fatal:true });
              if (j.status === 409 && j.received != null) { offset = j.received; return step(); }
              if (!j.ok) { if (j.status >= 500 || j.status === 0 || j.status === 429) throw { error:j.error || 'Server busy', retry:true }; return reject({ error:j.error || 'Upload failed', fatal:true }); }
              tries = 0; offset = j.received;
              if (j.complete) { store(key, null); report(file.size, { done:true, item:j.item }); return resolve(j); }
              report(offset); step();
            }).catch(function(e){
              if (e && (e.cancelled || control.cancelled)) return reject({ error:'Cancelled', cancelled:true });
              if (++tries > 6) return reject({ error:'Connection lost. Add the same file again later to resume.' });
              setTimeout(function(){ api('/drop/upload/' + id).then(function(r){ if (r && r.ok) offset = r.received; step(); }, step); }, Math.min(30000, 1000 * Math.pow(2, tries)));
            });
          });
        }
        step();
      });
    }
    return begin().then(run).catch(function(e){
      if (e && e.cancelled && id) { store(key, null); api('/drop/items/' + id, { method:'DELETE' }); }
      throw e;
    });
  }
  /** Run uploads two at a time so a batch of files does not saturate the link or the server. */
  var upQueue = [], upRunning = 0;
  function queueUpload(task){ return new Promise(function(resolve, reject){ upQueue.push(function(){ return task().then(resolve, reject); }); pumpUploads(); }); }
  function pumpUploads(){ while (upRunning < 2 && upQueue.length) { upRunning++; upQueue.shift()().catch(function(){}).then(function(){ upRunning--; pumpUploads(); }); } }
  function preview(item){
    var src = url('/api/drop/items/' + item.id + '/download?inline=1');
    if (item.preview === 'pdf') { window.open(src, '_blank', 'noopener'); return; }
    modal(item.name, function(b){
      var box = h('div', { className:'vc-drop-preview' });
      if (item.preview === 'image') box.appendChild(h('img', { src:src, alt:item.name }));
      else if (item.preview === 'video') box.appendChild(h('video', { src:src, controls:true, playsinline:true, preload:'metadata' }));
      else if (item.preview === 'audio') box.appendChild(h('audio', { src:src, controls:true, preload:'metadata' }));
      else if (item.preview === 'text') { var pre = h('pre', { className:'vc-drop-text-preview', text:'Loading…' }); box.appendChild(pre); fetch(src, { credentials:'same-origin' }).then(function(r){ return r.text(); }).then(function(t){ pre.textContent = t.length > 200000 ? t.slice(0, 200000) + '\n…' : t; }).catch(function(){ pre.textContent = 'Could not load the preview.'; }); }
      b.append(box, h('div', { className:'vc-overlay-actions' }, [h('a', { className:'btn primary', href:url('/api/drop/items/' + item.id + '/download'), download:item.name, text:'Download' })]));
    });
  }
  function shareDialog(item, refresh, pub){
    modal('Share “' + item.name + '”', function(b){
      var login = input('checkbox', true), pw = input('password', '', { placeholder:'Optional password', autocomplete:'new-password', minlength:'4', maxlength:'72' }), out = h('div', { className:'vc-share-out' }), links = h('div', { className:'vc-share-links' });
      function drawLinks(it){
        var live = (it.links || []).filter(function(l){ return !l.revoked; });
        links.replaceChildren(live.length ? h('h3', { text:'Active links' }) : '');
        live.forEach(function(l){
        var full = location.origin + l.url, extra = h('div', { className:'vc-share-extra' });
        var expired = l.expires_at && l.expires_at * 1000 <= Date.now();
        var chip = (l.require_login ? 'Sign-in' : 'Public') + (l.password ? ' · password' : '') + ' · ' + l.downloads + ' download' + (l.downloads === 1 ? '' : 's') + (l.expires_at ? (expired ? ' · link expired' : ' · link ends in ' + until(l.expires_at)) : '');
        links.appendChild(h('div', { className:'vc-share-row' }, [h('code', { text:full.replace(/^https?:\/\//, '') }), h('span', { className:'vc-chip' + (expired ? ' muted' : ''), text:chip }),
          btn('Copy', function(){ copy(full); }, 'ghost small'), btn('QR', function(){ if (extra.firstChild) extra.replaceChildren(); else extra.replaceChildren(qr(full)); }, 'ghost small', { 'aria-label':'Show a QR code for this link' }),
          navigator.share ? btn('Share…', function(){ navigator.share({ title:item.name, url:full }).catch(function(){}); }, 'ghost small') : null,
          h('select', { className:'small', 'aria-label':'Link lifetime', onChange:function(){ var v = this.value; if (v === '') return; call('/drop/links/' + l.token + '/extend', { method:'POST', body:{ hours:Number(v) } }).then(function(j){ drawLinks(j.item); refresh(); A.toast('Link updated', { kind:'ok' }); }).catch(err); } }, [h('option', { value:'', text:'Link lifetime…' }), h('option', { value:'0', text:'Same as the item' }), h('option', { value:'1', text:'1 hour from now' }), h('option', { value:'24', text:'24 hours from now' }), h('option', { value:'168', text:'7 days from now' })]),
          btn('Revoke', function(){ call('/drop/links/' + l.token + '/revoke', { method:'POST' }).then(function(){ l.revoked = true; drawLinks(it); refresh(); }).catch(err); }, 'ghost small danger'), extra]));
      });
      }
      b.append(h('p', { className:'muted', text:'Links follow the item\u2019s own limits: they stop working when it expires or its download limit is reached (' + (item.max_downloads ? item.max_downloads - item.downloads + ' downloads left' : 'unlimited downloads') + ', expires in ' + until(item.expires_at) + ').' }),
        h('form', { className:'vc-form', onSubmit:function(e){ e.preventDefault(); call('/drop/items/' + item.id + '/links', { method:'POST', body:{ require_login:login.checked, password:pw.value } }).then(function(j){
          var full = location.origin + j.url; out.replaceChildren(h('div', { className:'vc-share-new' }, [qr(full), h('div', null, [h('input', { type:'text', readonly:true, value:full, 'aria-label':'Share link', onFocus:function(){ this.select(); } }), h('div', { className:'vc-overlay-actions' }, [btn('Copy link', function(){ copy(full); }, 'primary'), navigator.share ? btn('Share…', function(){ navigator.share({ title:item.name, url:full }).catch(function(){}); }, 'ghost') : null])])]));
          pw.value = ''; drawLinks(j.item); refresh();
        }).catch(err); } }, [h('label', { className:'vc-check' }, [login, h('span', { text:'Require a sign-in to this server' + (pub ? '' : ' (public links are turned off)') })]), field('Password', pw), h('button', { type:'submit', className:'btn primary', text:'Create link' })]), out, links);
      if (!pub) login.disabled = true;
      drawLinks(item);
    });
  }
  function qr(text){ var box = h('div', { className:'vc-qr-box' }); A.loadModule('vc-qr.js', 'VaultcastQR').then(function(Q){ box.appendChild(Q.svg(text, { label:'QR code for the share link' })); }).catch(function(){}); return box; }
  function expiryPicker(presets, maxDays, initialHours){
    var hours = initialHours || 24, custom = input('number', '', { min:'1', max:String(maxDays), step:'1', placeholder:'days', 'aria-label':'Custom number of days', className:'vc-days' });
    var chips = seg(Object.keys(presets).map(function(k){ return [k, presets[k]]; }).concat([['custom', 'Custom']]), String(hours), function(v){ if (v === 'custom') { custom.hidden = false; custom.focus(); } else { custom.hidden = true; hours = Number(v); } }, 'Keep for');
    custom.hidden = true;
    return { el:h('div', { className:'vc-expiry' }, [chips, custom]), hours:function(){ if (!custom.hidden) { var d = Math.max(1, Math.min(maxDays, Number(custom.value) || 1)); return d * 24; } return hours; } };
  }
  function downloadsPicker(initial){
    var val = initial || 0, custom = input('number', '', { min:'1', max:'100000', placeholder:'count', 'aria-label':'Maximum downloads', className:'vc-days' }); custom.hidden = true;
    var chips = seg([['0', 'Unlimited'], ['1', '1'], ['5', '5'], ['10', '10'], ['custom', 'Custom']], String(val), function(v){ if (v === 'custom') { custom.hidden = false; custom.focus(); } else { custom.hidden = true; val = Number(v); } }, 'Download limit');
    return { el:h('div', { className:'vc-expiry' }, [chips, custom]), value:function(){ return custom.hidden ? val : Math.max(1, Number(custom.value) || 1); } };
  }
  function drop(host){
    if (!signed()) { host.appendChild(empty('Sign in to use Drop', '')); return; }
    host.appendChild(hero('Drop', 'Temporary file and note sharing. Items expire on their own; share them by link or send them to your other devices.', null, 'MISC'));
    var body = h('div', { className:'vc-drop' }, [A.pendingContent({ text:'Loading…' })]); host.appendChild(body);
    var st = null, queue = h('div', { className:'vc-upload-queue', 'aria-live':'polite' }), itemsBox = h('div', { className:'vc-drop-items' }), usage = h('div', { className:'vc-usage' }), tick = 0;
    function refresh(){ return call('/drop/state').then(function(j){ st = j; drawUsage(); drawItems(); }).catch(err); }
    function drawUsage(){ var pctUsed = st.quota ? Math.min(100, 100 * st.used / st.quota) : 0; usage.replaceChildren(h('div', { className:'vc-usage-text' }, [h('strong', { text:size(st.used) }), h('span', { className:'muted', text:st.quota ? ' of ' + size(st.quota) + ' in use' : ' in use (no limit)' })]), st.quota ? h('span', { className:'vc-progress' + (pctUsed > 90 ? ' bad' : ''), role:'progressbar', 'aria-valuenow':String(Math.round(pctUsed)), 'aria-valuemin':'0', 'aria-valuemax':'100' }, [h('i', { style:'width:' + pctUsed + '%' })]) : ''); }
    var selected = {}, zipBar = h('div', { className:'vc-zip-bar', hidden:true });
    function drawZipBar(){
      var ids = Object.keys(selected).filter(function(id){ return st.items.some(function(i){ return i.id === id && i.status === 'available'; }); });
      zipBar.hidden = ids.length < 1;
      var total = st.items.filter(function(i){ return selected[i.id]; }).reduce(function(n, i){ return n + i.bytes; }, 0);
      zipBar.replaceChildren(h('span', { text:ids.length + ' selected · ' + size(total) }), h('a', { className:'btn primary small', href:url('/api/drop/zip?ids=' + ids.join(',')), text:'Download as ZIP' }), btn('Clear', function(){ selected = {}; drawItems(); }, 'ghost small'));
    }
    function drawItems(){
      drawZipBar();
      var visible = st.items.filter(function(i){ return i.status !== 'deleted'; });
      if (!visible.length) { itemsBox.replaceChildren(h('p', { className:'muted vc-drop-empty', text:'Nothing here yet. Drop a file or write a note above.' })); return; }
      itemsBox.replaceChildren.apply(itemsBox, visible.map(function(i){
        var s = STATUS[i.status] || [i.status, 'muted'], live = i.status === 'available';
        var meta = [i.kind === 'file' ? size(i.bytes) : (i.kind === 'link' ? 'Link' : 'Note'), live ? 'expires in ' + until(i.expires_at) : null, i.max_downloads ? i.downloads + ' / ' + i.max_downloads + ' downloads' : (i.downloads ? i.downloads + ' downloads' : null), i.inbox ? 'sent to my devices' : null, ago(i.created_at)].filter(Boolean).join(' · ');
        var actions = [];
        if (live && i.kind === 'file') actions.push(h('a', { className:'btn primary small', href:url('/api/drop/items/' + i.id + '/download'), download:i.name, text:'Download' }));
        if (live && i.kind === 'file' && i.preview) actions.push(btn('Preview', function(){ preview(i); }, 'ghost small'));
        if (live && i.kind === 'link') actions.push(h('a', { className:'btn primary small', href:i.body, target:'_blank', rel:'noopener noreferrer', text:'Open link' }));
        if (live && i.kind !== 'file') actions.push(btn('Copy', function(){ copy(i.body); }, 'ghost small'));
        if (live && st.can_upload) actions.push(btn('Share', function(){ shareDialog(i, refresh, st.public_links); }, 'ghost small'));
        if (i.status !== 'uploading') actions.push(btn('Limits', function(){ limitsDialog(i); }, 'ghost small'));
        actions.push(btn('Delete', function(){ A.confirmDialog('Delete “' + i.name + '” now? Share links stop working.', { okText:'Delete', danger:true }).then(function(ok){ if (ok) call('/drop/items/' + i.id, { method:'DELETE' }).then(refresh).catch(err); }); }, 'ghost small danger'));
        var pickBox = live && i.kind === 'file' ? input('checkbox', !!selected[i.id], { 'aria-label':'Select ' + i.name + ' for a ZIP download', className:'vc-drop-pick', onChange:function(){ if (this.checked) selected[i.id] = true; else delete selected[i.id]; drawZipBar(); } }) : h('span', { className:'vc-drop-pick' });
        return h('article', { className:'vc-drop-item ' + i.status }, [pickBox, h('span', { className:'vc-drop-icon', text:kindIcon(i), 'aria-hidden':'true' }), h('div', { className:'vc-drop-copy' }, [h('strong', { text:i.name }), h('small', { className:'muted', text:meta }), i.kind === 'text' && live ? h('p', { className:'vc-drop-text', text:i.body.length > 280 ? i.body.slice(0, 280) + '…' : i.body }) : null]), h('span', { className:'vc-chip ' + s[1], text:s[0] }), h('div', { className:'vc-row-actions' }, actions)]);
      }));
    }
    function limitsDialog(i){
      modal('Availability', function(b, close){
        var exp = expiryPicker(st.presets, st.max_days, 24), dl = downloadsPicker(i.max_downloads), name = input('text', i.name, { maxlength:'200', 'aria-label':'Name' }), touched = false;
        exp.el.addEventListener('click', function(){ touched = true; });
        b.appendChild(h('form', { className:'vc-form', onSubmit:function(e){ e.preventDefault(); var payload = { name:name.value, max_downloads:dl.value() }; if (touched) payload.expires_hours = exp.hours(); call('/drop/items/' + i.id, { method:'POST', body:payload }).then(function(){ close(); refresh(); }).catch(err); } }, [
          h('p', { className:'muted', text:i.status === 'available' ? 'Expires in ' + until(i.expires_at) + '. Pick a new period to restart the clock from now (up to ' + st.max_days + ' days).' : 'This item is no longer available. Extend it or raise the download limit to bring it back until the cleanup task removes the file.' }),
          field('Name', name), h('div', { className:'vc-field' }, [h('span', { text:'Keep for (from now)' }), exp.el]), h('div', { className:'vc-field' }, [h('span', { text:'Download limit' }), dl.el]),
          h('div', { className:'vc-overlay-actions' }, [h('button', { type:'submit', className:'btn primary', text:'Save' }), i.status === 'available' ? btn('Expire now', function(){ call('/drop/items/' + i.id, { method:'POST', body:{ expire_now:true } }).then(function(){ close(); refresh(); }).catch(err); }, 'ghost danger') : null])]));
      });
    }
    function composer(){
      var exp = expiryPicker(st.presets, st.max_days, 24), dl = downloadsPicker(0), inbox = input('checkbox', false), mode = 'file';
      var picker = h('input', { type:'file', multiple:true, hidden:true });
      var zone = h('div', { className:'vc-dropzone', tabindex:'0', role:'button', 'aria-label':'Choose files to upload' }, [h('span', { className:'vc-dropzone-icon', text:'⇪', 'aria-hidden':'true' }), h('strong', { text:'Drop files here' }), h('span', { className:'muted', text:'or click to choose · paste works too · up to ' + size(st.limits.max_file) + ' per file' })]);
      var text = h('textarea', { rows:'4', placeholder:'Paste a note or a link…', 'aria-label':'Note or link' }), tname = input('text', '', { placeholder:'Title (optional)', maxlength:'120', 'aria-label':'Title' });
      var textForm = h('form', { className:'vc-form', hidden:true, onSubmit:function(e){ e.preventDefault(); var v = text.value.trim(); if (!v) return; call('/drop/text', { method:'POST', body:{ kind:/^https?:\/\/\S+$/i.test(v) ? 'link' : 'text', body:v, name:tname.value, expires_hours:exp.hours(), max_downloads:dl.value(), inbox:inbox.checked } }).then(function(){ text.value = ''; tname.value = ''; A.toast(inbox.checked ? 'Sent to your devices' : 'Saved', { kind:'ok' }); refresh(); }).catch(err); } }, [tname, text, h('button', { type:'submit', className:'btn primary', text:'Save note or link' })]);
      var tabs = seg([['file', 'Files'], ['text', 'Note or link']], 'file', function(v){ mode = v; zone.hidden = v !== 'file'; textForm.hidden = v !== 'text'; }, 'What to drop');
      function add(files){
        Array.prototype.forEach.call(files || [], function(f){
          var control = {}, bar = h('i'), label = h('small', { className:'muted', text:'Starting…' }), pause = btn('Pause', function(){ control.paused = !control.paused; pause.textContent = control.paused ? 'Resume' : 'Pause'; if (!control.paused && control.resume) control.resume(); }, 'ghost small'), cancel = btn('Cancel', function(){ if (control.abort) control.abort(); else control.cancelled = true; if (control.resume) control.resume(); }, 'ghost small');
          var row = h('div', { className:'vc-upload' }, [h('span', { className:'vc-drop-icon', text:kindIcon({ kind:'file', mime:f.type }) }), h('div', { className:'vc-drop-copy' }, [h('strong', { text:f.name }), h('span', { className:'vc-progress', role:'progressbar', 'aria-valuemin':'0', 'aria-valuemax':'100', 'aria-valuenow':'0', 'aria-label':'Upload progress for ' + f.name }, [bar]), label]), h('div', { className:'vc-row-actions' }, [pause, cancel])]);
          queue.appendChild(row);
          var first = null, opts = { expires_hours:exp.hours(), max_downloads:dl.value(), inbox:inbox.checked };
          if (f.size > st.limits.max_file) { row.classList.add('failed'); label.textContent = 'Too large: up to ' + size(st.limits.max_file) + ' per file.'; pause.remove(); cancel.textContent = 'Dismiss'; cancel.onclick = function(){ row.remove(); }; return; }
          label.textContent = 'Waiting…';
          queueUpload(function(){ if (control.cancelled) return Promise.reject({ cancelled:true }); return uploadFile(f, opts, function(p){
            if (first == null) first = p.sent;
            var pc = f.size ? 100 * p.sent / f.size : 100; bar.style.width = pc + '%'; bar.parentNode.setAttribute('aria-valuenow', String(Math.floor(pc)));
            label.textContent = p.done ? 'Uploaded · ' + size(f.size) : Math.floor(pc) + '% · ' + size(p.sent) + ' of ' + size(f.size) + (p.rate > 0 ? ' · ' + size(p.rate) + '/s' : '') + (p.eta != null && p.rate > 0 ? ' · ' + eta(p.eta) + ' left' : '') + (first > 0 ? ' · resumed' : '');
          }, control); }).then(function(){ row.classList.add('done'); pause.remove(); cancel.remove(); setTimeout(function(){ row.remove(); }, 4000); refresh(); if (inbox.checked) A.toast('Sent to your devices', { kind:'ok' }); })
            .catch(function(e){ row.classList.add('failed'); label.textContent = (e && e.error) || 'Upload failed'; pause.remove(); cancel.textContent = 'Dismiss'; cancel.onclick = function(){ row.remove(); }; if (e && e.cancelled) row.remove(); refresh(); });
        });
      }
      zone.addEventListener('click', function(){ picker.click(); }); zone.addEventListener('keydown', function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); picker.click(); } });
      picker.addEventListener('change', function(){ add(picker.files); picker.value = ''; });
      ['dragenter', 'dragover'].forEach(function(ev){ zone.addEventListener(ev, function(e){ e.preventDefault(); zone.classList.add('over'); }); });
      ['dragleave', 'drop'].forEach(function(ev){ zone.addEventListener(ev, function(){ zone.classList.remove('over'); }); });
      zone.addEventListener('drop', function(e){ e.preventDefault(); add(e.dataTransfer.files); });
      function paste(e){ if (!body.isConnected) { document.removeEventListener('paste', paste); return; } if (/^(INPUT|TEXTAREA)$/.test(e.target.tagName)) return; var files = e.clipboardData && e.clipboardData.files; if (files && files.length) { e.preventDefault(); add(files); } }
      document.addEventListener('paste', paste); onCleanup(function(){ document.removeEventListener('paste', paste); });
      return panel('New drop', [tabs, zone, picker, textForm,
        h('div', { className:'vc-drop-options' }, [h('div', { className:'vc-field' }, [h('span', { text:'Keep for' }), exp.el]), h('div', { className:'vc-field' }, [h('span', { text:'Download limit' }), dl.el]), h('label', { className:'vc-check' }, [inbox, h('span', { text:'Send to my devices (shows in your notifications everywhere you are signed in)' })])]),
        queue], usage);
    }
    function adminPanel(){
      var box = h('div', { className:'vc-drop-admin' }, [A.pendingContent({ text:'Loading overview…' })]);
      function load(){ call('/drop/admin').then(function(o){
        var s = o.settings;
        var en = input('checkbox', s.enabled), ur = input('checkbox', s.user_role_upload), pub = input('checkbox', s.public_links), maxf = input('number', s.max_file_mb, { min:'1' }), defq = input('number', s.default_quota_mb, { min:'0' });
        var anon = input('checkbox', s.anonymous), anonFile = input('number', s.anon_max_file_mb, { min:'1' }), anonTotal = input('number', s.anon_quota_mb, { min:'1' }), anonDays = input('number', s.anon_max_days, { min:'1', max:'30' }), accel = input('checkbox', s.nginx_downloads), confirmed = false;
        // Turning anonymous uploads on needs an explicit confirmation; the server refuses it without one.
        anon.addEventListener('change', function(){
          if (!anon.checked || s.anonymous) { anonBox.hidden = !anon.checked; return; }
          anon.checked = false;
          A.confirmDialog('Allow anonymous Drop? Anyone who can reach this server will be able to upload files without signing in. They could fill the disk or use your server to host files. Only turn this on if you understand and accept that risk.', { okText:'Allow anonymous uploads', danger:true }).then(function(ok){ if (!ok) return; confirmed = true; anon.checked = true; anonBox.hidden = false; });
        });
        var anonBox = h('div', { className:'vc-field-row', hidden:!s.anonymous }, [field('Anonymous: largest file (MB)', anonFile), field('Anonymous: total storage (MB)', anonTotal), field('Anonymous: longest expiry (days)', anonDays, 'Uploads default to 24 hours.')]);
        var anonSection = h('div', { className:'vc-anon-admin' }, [
          h('label', { className:'vc-check' }, [anon, h('span', { text:'Allow anonymous Drop' })]),
          h('p', { className:'vc-warn-danger', role:'note' }, [h('strong', { text:'This is not recommended. ' }), 'Anyone who can reach this server can upload without an account, fill the disk or use it to host files. Uploads are limited per network address, kept apart from each other and from the rest of the site, and admins can delete them below.']),
          anonBox, s.anonymous ? h('p', { className:'muted' }, ['Anonymous page: ', h('a', { href:o.anon_url, target:'_blank', rel:'noopener', text:location.origin + o.anon_url }), ' · in use: ' + size(o.anon_used || 0) + ' of ' + size(s.anon_quota_mb * 1048576)]) : null]);
        var quotaInputs = Object.keys(o.roles).map(function(r){ var i = input('number', s.quotas[r] != null ? s.quotas[r] : '', { min:'0', placeholder:'default', 'data-role':r }); return field((o.roles[r].name || r) + ' quota (MB)', i); });
        var last = o.last_cleanup ? (function(){ try { var j = typeof o.last_cleanup === 'string' ? JSON.parse(o.last_cleanup) : o.last_cleanup; return j; } catch (e) { return null; } })() : null;
        box.replaceChildren(
          h('div', { className:'vc-stat-row' }, [['Stored', size(o.total_bytes)], ['Free on disk', o.free_bytes != null ? size(o.free_bytes) : '—'], ['Reclaimable', size(o.reclaimable.bytes) + ' · ' + o.reclaimable.items + ' items'], ['Upload piece', size(o.limits.chunk)]].map(function(x){ return h('div', { className:'vc-stat' }, [h('strong', { text:x[1] }), h('span', { className:'muted', text:x[0] })]); })),
          h('div', { className:'vc-cleanup' }, [h('div', null, [h('strong', { text:'Cleanup task' }), h('p', { className:'muted', text:'Expired and used-up items are hidden straight away; this task deletes their files. ' + (last && last.finished_at ? 'Last run ' + ago(Date.parse(last.finished_at) / 1000) + '.' : 'Not run yet.') + ' Schedule it with the operating system (cron / Task Scheduler): php src/Console.php drop-cleanup' })]), btn('Run cleanup now', function(){ call('/drop/admin/cleanup', { method:'POST' }).then(function(){ A.toast('Cleanup started. Follow it under Settings → Maintenance.', { kind:'ok' }); setTimeout(load, 4000); }).catch(err); }, 'primary', { disabled:!o.reclaimable.items ? true : null })]),
          h('form', { className:'vc-form', onSubmit:function(e){ e.preventDefault(); var q = {}; box.querySelectorAll('input[data-role]').forEach(function(i){ if (i.value !== '') q[i.getAttribute('data-role')] = Number(i.value); }); call('/drop/admin/settings', { method:'POST', body:{ enabled:en.checked, user_role_upload:ur.checked, public_links:pub.checked, max_file_mb:Number(maxf.value), default_quota_mb:Number(defq.value), quotas:q, anonymous:anon.checked, confirm_anonymous:confirmed, anon_max_file_mb:Number(anonFile.value), anon_quota_mb:Number(anonTotal.value), anon_max_days:Number(anonDays.value), nginx_downloads:accel.checked } }).then(function(j){ A.toast('Drop settings saved', { kind:'ok' }); (j.notes || []).forEach(function(n){ A.toast(n); }); load(); refresh(); }).catch(err); } }, [
            h('label', { className:'vc-check' }, [en, h('span', { text:'Drop is enabled' })]), h('label', { className:'vc-check' }, [ur, h('span', { text:'Standard users may upload (other roles use the Drop permission; per-user overrides live under Users)' })]), h('label', { className:'vc-check' }, [pub, h('span', { text:'Allow public share links (no sign-in)' })]),
            h('div', { className:'vc-field-row' }, [field('Largest file (MB)', maxf, 'Default 1024 MB. A role quota below this caps that role\u2019s largest file.'), field('Default quota (MB, 0 = none)', defq)].concat(quotaInputs)),
            (o.notes || []).length ? h('ul', { className:'muted vc-notes' }, o.notes.map(function(n){ return h('li', { text:n }); })) : null,
            h('label', { className:'vc-check' }, [accel, h('span', { text:'Let nginx serve Drop downloads (needs nginx accel on, and nginx able to read the Drop folder)' })]),
            anonSection, h('button', { type:'submit', className:'btn primary', text:'Save settings' })]),
          o.users.length ? h('table', { className:'vc-table' }, [h('thead', null, [h('tr', null, [h('th', { text:'User' }), h('th', { text:'Items' }), h('th', { text:'Stored' })])]), h('tbody', null, o.users.map(function(u){ return h('tr', null, [h('td', { text:u.user.name }), h('td', { text:String(u.items) }), h('td', { text:size(u.bytes) })]); }))]) : '',
          o.items.length ? h('details', { className:'vc-admin-items' }, [h('summary', { text:'All items (' + o.items.length + ')' }), h('div', { className:'vc-drop-items' }, o.items.map(function(i){ var s2 = STATUS[i.status] || [i.status, 'muted']; return h('div', { className:'vc-drop-item ' + i.status }, [h('span', { className:'vc-drop-icon', text:kindIcon(i) }), h('div', { className:'vc-drop-copy' }, [h('strong', { text:i.name }), h('small', { className:'muted', text:i.owner.name + (i.anonymous && i.origin ? ' (' + i.origin + ')' : '') + ' · ' + size(i.bytes) + ' · ' + i.downloads + ' download' + (i.downloads === 1 ? '' : 's') + ' · ' + ago(i.created_at) })]), h('span', { className:'vc-chip ' + s2[1], text:s2[0] }), btn('Delete', function(){ A.confirmDialog('Delete “' + i.name + '”?', { okText:'Delete', danger:true }).then(function(ok){ if (ok) call('/drop/admin/items/' + i.id, { method:'DELETE' }).then(load).catch(err); }); }, 'ghost small danger')]); }))]) : '');
      }).catch(function(e){ box.replaceChildren(empty('Overview unavailable', (e && e.error) || '')); }); }
      load();
      return panel('Administration', [box]);
    }
    call('/drop/state').then(function(j){
      st = j; drawUsage(); drawItems();
      body.replaceChildren(st.can_upload ? composer() : panel('Drop', [h('p', { className:'muted', text:'Your account can receive and manage Drop items but cannot upload. Ask an administrator for the Drop permission.' }), usage]), panel('Your items', [zipBar, itemsBox], btn('Refresh', refresh, 'ghost small')), isAdmin() ? adminPanel() : '');
      tick = setInterval(function(){ if (!body.isConnected) { clearInterval(tick); return; } if (!document.hidden && st) drawItems(); }, 60000); onCleanup(function(){ clearInterval(tick); });
    }).catch(function(e){ body.replaceChildren(empty('Drop unavailable', (e && e.error) || '')); });
  }

  // ------------------------------------------------------------------ Ambient screen
  // Ambient is built from independent layers: a background, a clock, toggleable overlays and screen care
  // (dim, a scrim behind text, burn-in drift, night mode). The old fixed modes are now one-click presets.
  var FX_SCENES = [['aurora', 'Aurora', '🌌'], ['stars', 'Starfield', '✨'], ['waves', 'Waves', '🌊'], ['bokeh', 'Bokeh lights', '🔆'], ['rain', 'Rain on glass', '🌧️'], ['lava', 'Lava lamp', '🫧'], ['gradient', 'Colour flow', '🎨']];
  var GRADIENTS = { dusk:'linear-gradient(160deg,#141e30,#243b55)', ocean:'linear-gradient(160deg,#0f2027,#203a43,#2c5364)', ember:'linear-gradient(160deg,#200122,#6f0000)', forest:'linear-gradient(160deg,#0b1d13,#1e3d2b)', midnight:'radial-gradient(80% 80% at 50% 40%,#1b2338,#000)', slate:'linear-gradient(160deg,#232526,#414345)' };
  var PRESETS = [
    ['stills', 'Film stills', 'Frames from your library with a slow pan and zoom', '🎞️', { bg:'library', library:'frames' }],
    ['posters', 'Poster wall', 'Library artwork drifting by', '🖼️', { bg:'library', library:'posters' }],
    ['mixed', 'Library mix', 'Film stills and posters together', '🎬', { bg:'library', library:'mixed' }],
    ['links', 'Your pictures', 'Pictures you upload, plus any picture links', '🌄', { bg:'pictures' }],
    ['webcams', 'Webcams', 'Live views from the webcams your administrator made available', '📷', { bg:'webcam' }],
    ['clock', 'Clock only', 'A calm, dim clock with the weather', '🕰️', { bg:'gradient', gradient:'midnight', clock:{ show:true, style:'minimal', size:'xl', pos:'center' } }]
  ].concat(FX_SCENES.map(function(f){ return ['fx-' + f[0], f[1], 'Animated scene behind the clock', f[2], { bg:'fx', fx:f[0], clock:{ show:true, style:'minimal', size:'l', pos:'center' } }]; }));
  var QUOTES = [['The best way out is always through.', 'Robert Frost'], ['Simplicity is the ultimate sophistication.', 'Leonardo da Vinci'], ['Well done is better than well said.', 'Benjamin Franklin'], ['What we think, we become.', 'Buddha'], ['Stay hungry, stay foolish.', 'Stewart Brand'], ['Not all those who wander are lost.', 'J. R. R. Tolkien'], ['The journey of a thousand miles begins with one step.', 'Lao Tzu'], ['Happiness depends upon ourselves.', 'Aristotle'], ['Turn your wounds into wisdom.', 'Oprah Winfrey'], ['Do what you can, with what you have, where you are.', 'Theodore Roosevelt'], ['Little by little, one travels far.', 'J. R. R. Tolkien'], ['Act as if what you do makes a difference. It does.', 'William James'], ['The only way to do great work is to love what you do.', 'Steve Jobs'], ['Dwell on the beauty of life.', 'Marcus Aurelius'], ['Be curious, not judgemental.', 'Walt Whitman'], ['Wherever you go, go with all your heart.', 'Confucius'], ['Everything you can imagine is real.', 'Pablo Picasso'], ['Nothing is impossible; the word itself says I’m possible.', 'Audrey Hepburn'], ['Keep your face always toward the sunshine.', 'Walt Whitman'], ['The secret of getting ahead is getting started.', 'Mark Twain'], ['Quality is not an act, it is a habit.', 'Aristotle'], ['Tomorrow is a new day with no mistakes in it yet.', 'L. M. Montgomery'], ['It always seems impossible until it’s done.', 'Nelson Mandela'], ['Life is really simple, but we insist on making it complicated.', 'Confucius'], ['Whatever you are, be a good one.', 'Abraham Lincoln'], ['Make each day your masterpiece.', 'John Wooden'], ['Light tomorrow with today.', 'Elizabeth Barrett Browning'], ['The best is yet to come.', 'Robert Browning'], ['Kindness is a language the deaf can hear and the blind can see.', 'Mark Twain'], ['Slow and steady wins the race.', 'Aesop']];
  function minutesOf(t){ var m = /^(\d{1,2}):(\d{2})$/.exec(t || ''); return m ? (Number(m[1]) % 24) * 60 + Math.min(59, Number(m[2])) : null; }
  /** True inside a from/until window; windows may cross midnight (22:00 → 07:00). */
  function inWindow(from, to, d){ var a = minutesOf(from), b = minutesOf(to); if (a == null || b == null || a === b) return false; var n = d.getHours() * 60 + d.getMinutes(); return a < b ? (n >= a && n < b) : (n >= a || n < b); }
  function safeImageUrl(u){ u = String(u || '').trim(); return /^https?:\/\/[^\s"'()\\]+$/i.test(u) ? u : ''; }
  function ambientDefaults(){ return { bg:'library', library:'frames', fx:'aurora', gradient:'dusk', solid:'#05070d', webcam:'', seconds:14, fade:1.6, links:[], radio:'', loc:null,
    clock:{ show:true, style:'digital', size:'l', pos:'bl', h12:'auto', seconds:false, date:true }, overlays:{ weather:true, event:false, reminders:false, nowplaying:false, quote:false },
    dim:0, scrim:true, shift:true, night:{ on:false, from:'22:00', to:'07:00', level:60, warm:true } }; }
  /** Fill gaps and convert the old single-mode settings into layers. */
  function normaliseAmbient(p){
    var d = ambientDefaults(); p = p && typeof p === 'object' ? JSON.parse(JSON.stringify(p)) : {};
    if (p.mode && !p.bg) {
      var pre = PRESETS.filter(function(x){ return x[0] === p.mode; })[0]; if (pre) Object.assign(p, JSON.parse(JSON.stringify(pre[4])));
      p.clock = Object.assign({}, p.clock || {}, { show:p.clock !== false }); p.overlays = { weather:p.weather !== false };
      if (p.dim === true) p.night = { on:true, from:p.dimFrom || '22:00', to:p.dimTo || '07:00', level:p.dimLevel || 60, warm:false };
      if (typeof p.dim !== 'number') p.dim = 0; delete p.mode; delete p.weather; delete p.dimFrom; delete p.dimTo; delete p.dimLevel;
    }
    var out = Object.assign(d, p);
    out.clock = Object.assign(ambientDefaults().clock, p.clock && typeof p.clock === 'object' ? p.clock : {});
    out.overlays = Object.assign(ambientDefaults().overlays, p.overlays || {});
    out.night = Object.assign(ambientDefaults().night, p.night && typeof p.night === 'object' ? p.night : {});
    out.dim = Math.max(0, Math.min(80, Number(out.dim) || 0)); out.links = (out.links || []).map(safeImageUrl).filter(Boolean).slice(0, 200);
    return out;
  }
  function ambientPrefs(){ return normaliseAmbient(store('vaultcast-ambient')); }
  var ambSaveT = 0;
  function saveAmbientPrefs(p){ store('vaultcast-ambient', p); clearTimeout(ambSaveT); ambSaveT = setTimeout(function(){ if (signed()) call('/misc/prefs', { method:'POST', body:{ ambient:p } }).catch(function(){}); }, 800); }
  /** Server copy wins (per user, follows you between screens); the local copy just makes the first paint instant. */
  function loadAmbientPrefs(){ return signed() ? call('/misc/prefs').then(function(j){ if (j.ambient) { var p = normaliseAmbient(j.ambient); store('vaultcast-ambient', p); return p; } return ambientPrefs(); }).catch(function(){ return ambientPrefs(); }) : Promise.resolve(ambientPrefs()); }

  function analogFace(){
    var NS = 'http://www.w3.org/2000/svg', svg = document.createElementNS(NS, 'svg'); svg.setAttribute('viewBox', '0 0 100 100'); svg.setAttribute('class', 'vc-amb-analog'); svg.setAttribute('aria-hidden', 'true');
    function el(n, a){ var e = document.createElementNS(NS, n); Object.keys(a).forEach(function(k){ e.setAttribute(k, a[k]); }); svg.appendChild(e); return e; }
    el('circle', { cx:50, cy:50, r:47, 'class':'rim' }); for (var k = 0; k < 12; k++) { var a = k * Math.PI / 6; el('line', { x1:50 + 40 * Math.sin(a), y1:50 - 40 * Math.cos(a), x2:50 + 45 * Math.sin(a), y2:50 - 45 * Math.cos(a), 'class':'tick' }); }
    var hh = el('line', { x1:50, y1:50, x2:50, y2:27, 'class':'hh' }), mh = el('line', { x1:50, y1:50, x2:50, y2:14, 'class':'mh' }), sh = el('line', { x1:50, y1:56, x2:50, y2:10, 'class':'sh' }); el('circle', { cx:50, cy:50, r:2.5, 'class':'pin' });
    return { svg:svg, set:function(d, secs){ hh.setAttribute('transform', 'rotate(' + ((d.getHours() % 12) * 30 + d.getMinutes() / 2) + ' 50 50)'); mh.setAttribute('transform', 'rotate(' + (d.getMinutes() * 6 + d.getSeconds() / 10) + ' 50 50)'); sh.style.display = secs ? '' : 'none'; sh.setAttribute('transform', 'rotate(' + d.getSeconds() * 6 + ' 50 50)'); } };
  }
  /**
   * Render Ambient into host (full screen or the preview box). Returns { stop }.
   * opts.preview keeps it light: one muted webcam player at most, no radio, fewer requests.
   */
  function buildAmbient(host, p, opts){
    opts = opts || {};
    var stops = [], alive = true, stage = h('div', { className:'vc-amb-stage' }), dimEl = h('div', { className:'vc-amb-dim', 'aria-hidden':'true' }), warmEl = h('div', { className:'vc-amb-warm', 'aria-hidden':'true' });
    var ov = h('div', { className:'vc-amb-overlay pos-' + (p.clock.pos || 'bl') + (p.scrim ? ' scrim' : '') }), clock = h('div', { className:'vc-amb-clock style-' + p.clock.style + ' size-' + p.clock.size }), info = h('div', { className:'vc-amb-info' });
    host.classList.add('vc-amb-host'); host.replaceChildren(stage, dimEl, warmEl, ov);
    function isAlive(){ return alive && host.isConnected; }
    // Unit for type sizes: 1% of the host width, so the preview is a true miniature of the screen.
    function unit(){ host.style.setProperty('--amb-u', Math.max(2, host.getBoundingClientRect().width / 100) + 'px'); }
    unit(); var ro = window.ResizeObserver ? new ResizeObserver(unit) : null; if (ro) { ro.observe(host); stops.push(function(){ ro.disconnect(); }); } else { window.addEventListener('resize', unit); stops.push(function(){ window.removeEventListener('resize', unit); }); }
    // ---- background
    var bgKind = p.bg, fade = Math.max(0.2, Math.min(8, Number(p.fade) || 1.6)); host.style.setProperty('--vc-kb-fade', fade + 's');
    if (bgKind === 'solid') stage.style.background = /^#[0-9a-f]{6}$/i.test(p.solid || '') ? p.solid : '#000';
    else if (bgKind === 'gradient') stage.style.background = GRADIENTS[p.gradient] || GRADIENTS.dusk;
    else if (bgKind === 'fx') A.loadModule('vc-fx.js', 'VaultcastFx').then(function(F){ if (!isAlive()) return; var f = F.start(stage, p.fx || 'aurora', { maxDpr:opts.preview ? 1 : 1.25, fps:opts.preview ? 20 : 30 }); stops.push(f.stop); }).catch(function(){ stage.style.background = GRADIENTS.dusk; });
    else if (bgKind === 'pictures') { var own = (p.links || []).map(function(u){ return { url:u, title:'' }; }); var kp = kenBurns(stage, function(){ return ownPictures().catch(function(){ return []; }).then(function(m){ return m.concat(own); }); }, { interval:(p.seconds || 14) * 1000 }); stops.push(kp.stop); }
    else if (bgKind === 'library') { var kl = kenBurns(stage, function(){ return call('/misc/ambient/stills?n=40&kind=' + (p.library === 'posters' || p.library === 'mixed' ? p.library : 'frames') + (opts.preview ? '&variant=medium' : '')).then(function(j){ return j.stills.map(function(x){ return { url:url(x.url), title:x.title + (x.year ? ' (' + x.year + ')' : '') }; }); }); }, { interval:(p.seconds || 14) * 1000, captions:!opts.preview }); stops.push(kl.stop); }
    else if (bgKind === 'webcam') {
      // Only webcams an admin made available for Ambient (the server sends nothing else), under their admin-set names.
      ambientCams().then(function(all){
        if (!isAlive()) return;
        all = all.filter(function(c){ return youtubeId(c.url) || safeHttpUrl(c.url); });
        var chosen = p.webcam ? all.filter(function(c){ return c.id === p.webcam; }) : all;
        if (!chosen.length) chosen = all;
        if (!chosen.length) { stage.style.background = GRADIENTS.slate; stage.appendChild(h('p', { className:'vc-kb-empty', text:'No webcams available yet.' })); return; }
        // The preview runs the same renderer, scaled into the box: muted, one player, torn down with the preview.
        stage.classList.add('vc-cam-stage');
        var cyc = camCycle(stage, opts.preview ? chosen.slice(0, 1) : chosen, { seconds:Math.max(30, (p.seconds || 14) * 3), fade:1, controls:false, label:!opts.preview }); stops.push(cyc.stop);
      });
    } else if (bgKind === 'nowplaying') {
      var npBg = h('div', { className:'vc-amb-np-bg' }), npArt = h('img', { className:'vc-amb-np-art', alt:'' }); stage.append(npBg, npArt); stage.style.background = '#000';
      var np = function(){ call('/misc/widget/nowplaying').then(function(j){ if (!isAlive()) return; var s0 = (j.sessions || [])[0]; if (!s0) { npArt.hidden = true; npBg.style.backgroundImage = ''; stage.style.background = GRADIENTS.midnight; return; } var u = url(String(s0.poster).replace('variant=thumb', 'variant=medium')); npArt.hidden = false; npArt.src = u; npBg.style.backgroundImage = 'url("' + u.replace(/["\\()\s]/g, '') + '")'; }).catch(function(){}); };
      np(); stops.push(poll(np, 30000));
    }
    // ---- clock
    if (p.clock.show) {
      var face = p.clock.style === 'analog' ? analogFace() : null, timeEl = h('strong'), dateEl = h('span');
      if (face) clock.appendChild(face.svg); else clock.appendChild(timeEl); if (p.clock.date) clock.appendChild(dateEl);
      ov.appendChild(clock);
      var tick = function(){ var d = new Date(); if (face) face.set(d, p.clock.seconds); else timeEl.textContent = clockText(d, { seconds:p.clock.seconds, h12:p.clock.h12 === 'on' ? true : p.clock.h12 === 'off' ? false : null }); if (p.clock.date) dateEl.textContent = dateText(d); night(d); };
      tick(); var ct = setInterval(tick, 1000); stops.push(function(){ clearInterval(ct); });
    } else { var nt = setInterval(function(){ night(new Date()); }, 30000); stops.push(function(){ clearInterval(nt); }); }
    ov.appendChild(info);
    // ---- overlays
    var o = p.overlays;
    function block(cls){ var b = h('div', { className:'vc-amb-block ' + cls }); info.appendChild(b); return b; }
    if (o.weather && p.loc) { var wxEl = block('vc-amb-wx'); var wl = function(){ weatherData(p.loc).then(function(j){ var w = wx(j.current.code, j.current.day); swap(wxEl, h('span', { className:'vc-amb-wx-icon', text:w.icon }), h('strong', { text:deg(j.current.temp) + j.units }), h('small', { text:w.text + ' · ' + p.loc.name })); }).catch(function(){}); }; wl(); stops.push(poll(wl, 600000)); }
    if (o.event && signed()) { var evEl = block('vc-amb-event'); var el = function(){ var now = Math.floor(Date.now() / 1000); call('/misc/cal/events?from=' + now + '&to=' + (now + 14 * 86400)).then(function(j){ var e = (j.events || []).filter(function(x){ return x.source !== 'reminder' && (x.all_day ? x.end > now : x.start >= now - 600); })[0]; evEl.hidden = !e; if (!e) return; var d = new Date(e.start * 1000), today = new Date().toDateString() === d.toDateString(); swap(evEl, h('span', { className:'vc-amb-dot', style:'--c:' + (e.colour || '#59e9ff') }), h('span', null, [h('small', { text:(today ? 'Today' : d.toLocaleDateString([], { weekday:'short', day:'numeric', month:'short' })) + (e.all_day ? '' : ' · ' + d.toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' })) }), h('strong', { text:e.title })])); }).catch(function(){ evEl.hidden = true; }); }; el(); stops.push(poll(el, 300000)); }
    if (o.reminders && signed()) { var rmEl = block('vc-amb-rems'); var rl = function(){ call('/misc/reminders').then(function(j){ var list = (j.reminders || []).filter(function(r){ return r.due && r.state !== 'later' && r.state !== 'snoozed'; }).slice(0, 3); rmEl.hidden = !list.length; rmEl.replaceChildren.apply(rmEl, list.map(function(r){ return h('div', { className:'vc-amb-rem ' + r.state }, [h('span', { text:r.emoji }), h('span', { text:r.title }), h('small', { text:r.state === 'overdue' ? 'Overdue' : r.state === 'today' ? 'Today' : r.state === 'tomorrow' ? 'Tomorrow' : 'Soon' })]); })); }).catch(function(){ rmEl.hidden = true; }); }; rl(); stops.push(poll(rl, 300000)); }
    if (o.nowplaying) { var npEl = block('vc-amb-np'); var nl = function(){ call('/misc/widget/nowplaying').then(function(j){ var s0 = (j.sessions || [])[0]; npEl.hidden = !s0; if (s0) swap(npEl, h('small', { text:'▶ ' + (s0.user && s0.user.name ? s0.user.name + ' is watching' : 'Now playing') }), h('strong', { text:s0.title })); }).catch(function(){ npEl.hidden = true; }); }; nl(); stops.push(poll(nl, 60000)); }
    if (o.quote) { var day = Math.floor((Date.now() - new Date().getTimezoneOffset() * 60000) / 86400000), q = QUOTES[day % QUOTES.length]; block('vc-amb-quote').append(h('q', { text:q[0] }), h('small', { text:'— ' + q[1] })); }
    // ---- screen care
    dimEl.style.opacity = String(p.dim / 100);
    function night(d){ var on = p.night.on && inWindow(p.night.from, p.night.to, d); dimEl.style.opacity = String(Math.min(0.92, (p.dim + (on ? Number(p.night.level) || 60 : 0)) / 100)); warmEl.style.opacity = on && p.night.warm ? '1' : '0'; }
    night(new Date());
    if (p.shift && !opts.preview) { var sh = function(){ ov.style.transform = 'translate(' + Math.round((Math.random() * 2 - 1) * 1.2 * 10) / 10 + '%,' + Math.round((Math.random() * 2 - 1) * 1.2 * 10) / 10 + '%)'; }; var st = setInterval(function(){ if (!document.hidden) sh(); }, 90000); stops.push(function(){ clearInterval(st); }); }
    return { stop:function(){ alive = false; stops.forEach(function(f){ try { f(); } catch (e) {} }); stops = []; } };
  }

  var amb = null;
  function startAmbient(opts){
    opts = opts || {};
    if (amb) return;
    var p = ambientPrefs(); if (opts.preset) { var pre = PRESETS.filter(function(x){ return x[0] === opts.preset; })[0]; if (pre) p = normaliseAmbient(Object.assign(p, JSON.parse(JSON.stringify(pre[4])))); }
    var layer = h('div', { className:'vc-ambient', role:'dialog', 'aria-label':'Ambient screen. Press Escape or use the Exit button to return.' }), view = h('div', { className:'vc-amb-view' });
    var hint = h('div', { className:'vc-amb-hint', text:'Move the mouse or tap for controls · Esc to exit' });
    var exitBtn = h('button', { type:'button', className:'vc-amb-exit', 'aria-label':'Exit ambient' }, [h('span', { text:'✕', 'aria-hidden':'true' }), h('span', { text:'Exit' })]);
    var controls = h('div', { className:'vc-amb-controls', role:'toolbar', 'aria-label':'Ambient controls' }, [exitBtn]);
    layer.append(view, hint, controls);
    document.body.appendChild(layer); document.body.classList.add('vc-ambient-on');
    var stops = [], since = Date.now();
    amb = { layer:layer };
    var built = buildAmbient(view, p, {}); stops.push(built.stop);
    // Pick up the server copy (another screen may have changed it) without a visible restart unless it differs.
    loadAmbientPrefs().then(function(fresh){ if (amb && amb.layer === layer && !opts.preset && JSON.stringify(fresh) !== JSON.stringify(p)) { built.stop(); p = fresh; built = buildAmbient(view, p, {}); stops.push(built.stop); } });
    var audio = null;
    if (p.radio) {
      (A.loadRadio ? A.loadRadio() : Promise.resolve([])).then(function(list){ var st = list.filter(function(x){ return x.id === p.radio; })[0]; if (!st || !amb) return; audio = h('audio'); audio.volume = 0.6; layer.appendChild(audio); playStream(audio, st.url, function(){ return !!amb; }).then(function(s){ if (s) stops.push(function(){ try { s.destroy(); } catch (e) {} }); }).catch(function(){}); controls.insertBefore(h('span', { className:'vc-amb-mode', text:'♪ ' + st.name }), exitBtn); }).catch(function(){});
      stops.push(function(){ if (audio) { audio.pause(); audio.removeAttribute('src'); } });
    }
    wantWake(1);
    // Achievements: starting Ambient, then a beacon a minute while it stays on screen.
    var achUser = A.state.user;
    if (achUser && achUser.role !== 'guest' && achUser.achievements && achUser.achievements.enabled) {
      var achTick = function(start){ A.api('/achievements/tick', { method:'POST', body:{ kind:'ambient', start:!!start } }).catch(function(){}); };
      achTick(true);
      var achT = setInterval(function(){ if (amb && amb.layer === layer && !document.hidden) achTick(false); }, 60000);
      stops.push(function(){ clearInterval(achT); });
    }
    if (!opts.idle && layer.requestFullscreen) layer.requestFullscreen().catch(function(){});
    // Exit only on purpose: Escape (or a remote's Back key), the Exit button, or leaving full screen.
    // Mouse movement, taps and other keys just show the controls and the cursor for a few seconds.
    var hideT = 0;
    function hideControls(){ if (controls.contains(document.activeElement)) { hideT = setTimeout(hideControls, 3000); return; } layer.classList.remove('show-controls'); }
    function reveal(){ layer.classList.add('show-controls'); clearTimeout(hideT); hideT = setTimeout(hideControls, 3000); }
    function onKey(e){
      if (!amb) return;
      if (e.key === 'Escape' || e.key === 'Esc' || e.key === 'BrowserBack' || e.key === 'GoBack') { e.preventDefault(); e.stopPropagation(); exit(); return; }
      reveal();
      if (e.key === 'Tab') { e.preventDefault(); exitBtn.focus(); return; }
      if (controls.contains(document.activeElement) && (e.key === 'Enter' || e.key === ' ')) return;
      e.preventDefault(); e.stopPropagation();
    }
    function onPointer(e){ if (!amb) return; if (e.type === 'pointermove' && Date.now() - since < 800) return; reveal(); if (e.type !== 'pointermove' && !controls.contains(e.target)) e.preventDefault(); }
    function onClick(e){ if (!controls.contains(e.target)) { e.preventDefault(); e.stopPropagation(); } }
    function exit(){
      if (!amb) return;
      window.removeEventListener('keydown', onKey, true);
      ['pointermove', 'pointerdown'].forEach(function(ev){ layer.removeEventListener(ev, onPointer); });
      clearTimeout(hideT);
      stops.forEach(function(f){ try { f(); } catch (x) {} });
      wantWake(-1); amb = null; document.body.classList.remove('vc-ambient-on');
      if (document.fullscreenElement === layer && document.exitFullscreen) document.exitFullscreen().catch(function(){});
      layer.classList.add('leaving'); setTimeout(function(){ layer.remove(); }, 350);
    }
    exitBtn.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); exit(); });
    window.addEventListener('keydown', onKey, true);
    ['pointermove', 'pointerdown'].forEach(function(ev){ layer.addEventListener(ev, onPointer); });
    layer.addEventListener('click', onClick);
    amb.exit = exit;
    document.addEventListener('fullscreenchange', function fsc(){ if (!document.fullscreenElement && amb && amb.layer === layer && amb.wasFull) exit(); else if (document.fullscreenElement === layer && amb) amb.wasFull = true; if (!amb) document.removeEventListener('fullscreenchange', fsc); });
  }

  function ambient(host){
    host.appendChild(hero('Ambient', 'Turn any screen into a living picture frame. Mix a background, a clock and overlays; it can also start on its own when nobody has touched the screen for a while.', [btn('Start now', function(){ startAmbient({}); }, 'primary')], 'MISC'));
    var p = ambientPrefs(), body = h('div', { className:'vc-ambient-page' }), previewBox = h('div', { className:'vc-amb-preview', 'aria-label':'Live preview' }), preview = null, pt = 0, forms = h('div', { className:'vc-amb-options' });
    host.appendChild(body);
    function refreshPreview(){ clearTimeout(pt); pt = setTimeout(function(){ if (preview) preview.stop(); if (previewBox.isConnected) preview = buildAmbient(previewBox, p, { preview:true }); }, 250); }
    function changed(){ saveAmbientPrefs(p); refreshPreview(); drawPresets(); }
    onCleanup(function(){ clearTimeout(pt); if (preview) preview.stop(); });
    var presets = h('div', { className:'vc-mode-grid vc-amb-presets', role:'list', 'aria-label':'Presets' });
    function matches(pre){ var x = pre[4]; return Object.keys(x).every(function(k){ return typeof x[k] === 'object' ? Object.keys(x[k]).every(function(kk){ return p[k] && p[k][kk] === x[k][kk]; }) : p[k] === x[k]; }); }
    function drawPresets(){ presets.replaceChildren.apply(presets, PRESETS.map(function(m){ var on = matches(m); return h('button', { type:'button', role:'listitem', 'aria-pressed':String(on), className:'vc-mode-card' + (on ? ' active' : ''), title:'Double-click to start', onClick:function(){ var x = JSON.parse(JSON.stringify(m[4])); Object.keys(x).forEach(function(k){ p[k] = typeof x[k] === 'object' ? Object.assign({}, p[k], x[k]) : x[k]; }); changed(); drawForms(); }, onDblclick:function(){ startAmbient({ preset:m[0] }); } }, [h('span', { className:'vc-mode-icon', text:m[3], 'aria-hidden':'true' }), h('strong', { text:m[1] }), h('small', { className:'muted', text:m[2] })]); })); }
    function bind(el, get, set){ el.addEventListener(el.type === 'range' ? 'input' : 'change', function(){ set(el.type === 'checkbox' ? el.checked : el.value); changed(); }); return el; }
    function check(label, obj, key){ return h('label', { className:'vc-check' }, [bind(input('checkbox', !!obj[key]), null, function(v){ obj[key] = v; }), h('span', { text:label })]); }
    function drawForms(){
      // Background
      var bg = bind(select([['library', 'Library frames and posters'], ['pictures', 'Your pictures'], ['fx', 'Animated scene'], ['gradient', 'Gradient'], ['solid', 'Solid colour'], ['webcam', 'A webcam'], ['nowplaying', 'Now-playing artwork']], p.bg), null, function(v){ p.bg = v; drawForms(); });
      var lib = bind(select([['frames', 'Film stills'], ['posters', 'Posters'], ['mixed', 'Both']], p.library), null, function(v){ p.library = v; });
      var fx = bind(select(FX_SCENES.map(function(f){ return [f[0], f[2] + ' ' + f[1]]; }), p.fx), null, function(v){ p.fx = v; });
      var grad = bind(select(Object.keys(GRADIENTS).map(function(k){ return [k, k.charAt(0).toUpperCase() + k.slice(1)]; }), p.gradient), null, function(v){ p.gradient = v; });
      var solid = bind(input('color', p.solid), null, function(v){ p.solid = v; });
      var cam = select([['', 'Cycle through all']], p.webcam); bind(cam, null, function(v){ p.webcam = v; });
      var camNote = h('small', { className:'muted', text:'Loading webcams…' });
      ambientCams(true).then(function(list){
        list.forEach(function(c){ cam.appendChild(h('option', { value:c.id, text:camLabel(c) })); });
        if (p.webcam && !list.some(function(c){ return c.id === p.webcam; })) { p.webcam = ''; saveAmbientPrefs(p); }
        cam.value = p.webcam || ''; cam.hidden = !list.length;
        camNote.textContent = list.length ? (list.length === 1 ? 'One webcam is available.' : list.length + ' webcams are available.') + ' Only one webcam player runs at a time.' : 'No webcams available yet. An administrator can make webcams available for Ambient.';
      });
      var secs = bind(input('number', p.seconds, { min:'5', max:'120' }), null, function(v){ p.seconds = Math.max(5, Math.min(120, Number(v) || 14)); });
      var fadeSel = bind(select([['0.6', 'Quick (0.6 s)'], ['1.6', 'Gentle (1.6 s)'], ['3', 'Slow (3 s)'], ['6', 'Very slow (6 s)']], String(p.fade)), null, function(v){ p.fade = Number(v); });
      var bgRows = [field('Background', bg)];
      if (p.bg === 'library') bgRows.push(field('Artwork', lib));
      if (p.bg === 'fx') bgRows.push(field('Scene', fx, 'Lightweight canvas animation; pauses when hidden and stays still with reduced motion.'));
      if (p.bg === 'gradient') bgRows.push(field('Gradient', grad));
      if (p.bg === 'solid') bgRows.push(field('Colour', solid));
      if (p.bg === 'webcam') bgRows.push(h('div', { className:'vc-field' }, [h('span', { text:'Webcam' }), cam, camNote]));
      if (p.bg === 'library' || p.bg === 'pictures') bgRows.push(field('Seconds per picture', secs), field('Transition speed', fadeSel));
      // Clock
      var c = p.clock;
      var style = bind(select([['digital', 'Digital'], ['minimal', 'Minimal (thin)'], ['analog', 'Analogue']], c.style), null, function(v){ c.style = v; });
      var sizeSel = bind(select([['s', 'Small'], ['m', 'Medium'], ['l', 'Large'], ['xl', 'Huge']], c.size), null, function(v){ c.size = v; });
      var pos = bind(select([['center', 'Centre'], ['bl', 'Bottom left'], ['bc', 'Bottom centre'], ['br', 'Bottom right'], ['tl', 'Top left'], ['tr', 'Top right']], c.pos), null, function(v){ c.pos = v; });
      var h12 = bind(select([['auto', 'Device default'], ['off', '24-hour'], ['on', '12-hour']], c.h12), null, function(v){ c.h12 = v; });
      // Screen care
      var n = p.night, dim = bind(input('range', p.dim, { min:'0', max:'80', step:'5', 'aria-label':'Dim level' }), null, function(v){ p.dim = Number(v); });
      var nFrom = bind(input('time', n.from), null, function(v){ n.from = v; }), nTo = bind(input('time', n.to), null, function(v){ n.to = v; }), nLvl = bind(input('range', n.level, { min:'10', max:'90', step:'5', 'aria-label':'How dark at night' }), null, function(v){ n.level = Number(v); });
      var radioSel = select([['', 'No radio']], p.radio || ''); bind(radioSel, null, function(v){ p.radio = v; });
      (A.loadRadio ? A.loadRadio() : Promise.resolve([])).then(function(list){ list.forEach(function(st){ radioSel.appendChild(h('option', { value:st.id, text:st.name })); }); radioSel.value = p.radio || ''; }).catch(function(){});
      forms.replaceChildren(
        panel('Background', [h('div', { className:'vc-form' }, bgRows)]),
        panel('Clock', [h('div', { className:'vc-form' }, [check('Show the clock', c, 'show'), h('div', { className:'vc-field-row' }, [field('Style', style), field('Size', sizeSel), field('Position', pos)]), h('div', { className:'vc-field-row' }, [field('Format', h12)]), check('Seconds', c, 'seconds'), check('Date', c, 'date')])]),
        panel('Overlays', [h('div', { className:'vc-form' }, [check('Weather', p.overlays, 'weather'), field('Weather location', locationPicker(p.loc, function(l){ p.loc = l; changed(); })), check('Next calendar event', p.overlays, 'event'), check('Upcoming reminders', p.overlays, 'reminders'), check('Now playing', p.overlays, 'nowplaying'), check('Quote of the day', p.overlays, 'quote')])]),
        panel('Screen care', [h('div', { className:'vc-form' }, [field('Dim', dim), check('Darken and blur behind text', p, 'scrim'), check('Drift slowly to protect against burn-in', p, 'shift'), check('Night mode: dimmer and warmer at night', n, 'on'), h('div', { className:'vc-field-row' }, [field('From', nFrom), field('Until', nTo), field('Night darkness', nLvl)]), check('Warmer colours at night', n, 'warm')])]),
        panel('Sound', [h('div', { className:'vc-form' }, [field('Radio while ambient runs', radioSel)])]));
    }
    var own = null; try { own = localStorage.getItem('vaultcast-ambient-idle'); } catch (e) {}
    var idleSel = select([['', 'Use the server default'], ['0', 'Never on this device'], ['2', 'After 2 minutes'], ['5', 'After 5 minutes'], ['10', 'After 10 minutes'], ['15', 'After 15 minutes'], ['30', 'After 30 minutes'], ['60', 'After 1 hour']], own == null ? '' : own, { onChange:function(){ try { if (this.value === '') localStorage.removeItem('vaultcast-ambient-idle'); else localStorage.setItem('vaultcast-ambient-idle', this.value); } catch (e) {} } });
    var serverIdle = h('small', { className:'muted' });
    var linksTa = h('textarea', { rows:4, placeholder:'https://example.local/photos/1.jpg', 'aria-label':'Picture addresses, one per line', onChange:function(){ p.links = this.value.split(/\s+/).map(safeImageUrl).filter(Boolean).slice(0, 200); this.value = p.links.join('\n'); changed(); } }); linksTa.value = (p.links || []).join('\n');
    body.append(h('div', { className:'vc-amb-top' }, [h('div', { className:'vc-amb-preview-wrap' }, [previewBox, h('div', { className:'vc-amb-preview-bar' }, [h('small', { className:'muted', text:'Live preview' }), btn('Start now', function(){ startAmbient({}); }, 'primary small')])]), panel('Presets', [h('p', { className:'muted', text:'One click sets the layers below; double-click starts it.' }), presets])]),
      forms,
      panel('Your pictures', [h('p', { className:'muted', text:'Used by the “Your pictures” background, the Slideshow and the Picture frame. Only you can see them.' }), picturesManager(function(){ if (p.bg === 'pictures') refreshPreview(); }), h('details', { className:'vc-form vc-links-more' }, [h('summary', { text:'Picture links' }), field('Picture addresses (one per line, shown after your uploads)', linksTa, 'Pictures load straight from your browser; the server never fetches them.')])]),
      panel('Start automatically', [h('div', { className:'vc-form' }, [field('On this device', idleSel), serverIdle, h('p', { className:'muted', text:'Ambient waits while video or audio is playing. Moving the mouse or tapping shows an Exit button; Esc also returns you to where you were.' })])]));
    drawPresets(); drawForms(); refreshPreview();
    loadAmbientPrefs().then(function(fresh){ if (!body.isConnected) return; p = fresh; linksTa.value = (p.links || []).join('\n'); drawPresets(); drawForms(); refreshPreview(); });
    call('/misc/ambient').then(function(cfg){
      serverIdle.textContent = cfg.idle_minutes ? 'Server default: after ' + cfg.idle_minutes + ' minutes.' : 'Server default: off.';
      if (!isAdmin()) return;
      var idle = input('number', cfg.idle_minutes || 0, { min:'0', max:'240' }), radio = select([['', 'None']], cfg.radio || ''), cams = (cfg.webcams || []).map(function(c){ return Object.assign({}, c); });
      (A.loadRadio ? A.loadRadio() : Promise.resolve([])).then(function(list){ list.forEach(function(st){ radio.appendChild(h('option', { value:st.id, text:st.name })); }); radio.value = cfg.radio || ''; }).catch(function(){});
      body.appendChild(panel('Administration', [h('form', { className:'vc-form', onSubmit:function(e){ e.preventDefault(); call('/misc/admin/ambient', { method:'POST', body:{ idle_minutes:Number(idle.value), radio:radio.value } }).then(function(){ A.toast('Ambient settings saved', { kind:'ok' }); }).catch(err); } }, [field('Start after N idle minutes for everyone (0 = off)', idle), field('Suggested radio station', radio), h('div', { className:'vc-field' }, [h('span', { text:'Webcams' }), h('small', { className:'muted', text:'Webcams people save on their boards are collected in Administration → Settings → Ambient & webcams. Choose there which ones Ambient offers.' }), h('a', { className:'btn ghost small', href:'/settings/ambient', onClick:function(e){ if (A.openSettings) { e.preventDefault(); A.openSettings('ambient'); } } , text:'Manage webcams' })]), h('button', { type:'submit', className:'btn primary', text:'Save' })])]));
    }).catch(function(){});
  }

  // ------------------------------------------------------------------ Administration → Settings → Ambient & webcams
  var KIND_LABEL = { youtube:'YouTube', image:'Image', mjpeg:'MJPEG', video:'HLS / video', embed:'Page', auto:'Auto' };
  function adminWebcams(host){
    var list = h('div', { className:'vc-wa-list', role:'list' }), count = h('p', { className:'muted vc-wa-count' });
    var name = input('text', '', { placeholder:'Name (optional)', 'aria-label':'New webcam name', maxlength:'120' }), addr = input('url', '', { placeholder:'https://… (YouTube, image, MJPEG or HLS)', 'aria-label':'New webcam address', required:true });
    var add = h('form', { className:'vc-wa-add', onSubmit:function(e){ e.preventDefault(); var u = addr.value.trim(); if (!u) return;
      call('/misc/admin/webcams', { method:'POST', body:{ url:u, name:name.value.trim() } }).then(function(j){ addr.value = ''; name.value = ''; draw(j.webcams); A.toast('Webcam added', { kind:'ok' }); }).catch(err); } },
      [name, addr, h('button', { type:'submit', className:'btn primary', text:'Add webcam' })]);
    host.replaceChildren(h('fieldset', { className:'settings-fieldset vc-wa' }, [h('legend', { text:'Webcams' }), count, list, h('h3', { className:'vc-wa-sub', text:'Add a webcam' }), add]));
    function kindOf(c){ return c.kind && c.kind !== 'auto' ? c.kind : (youtubeId(c.url) ? 'youtube' : camKind(c)); }
    function row(c){
      var nm = input('text', c.name || '', { placeholder:camLabel({ url:c.url }), 'aria-label':'Name for ' + c.url, maxlength:'120' }), last = c.name || '';
      function saveName(){ var v = nm.value.trim(); if (v === last) return; call('/misc/admin/webcams/' + c.id, { method:'POST', body:{ name:v } }).then(function(){ last = v; A.toast('Name saved', { kind:'ok' }); }).catch(function(e){ nm.value = last; err(e); }); }
      nm.addEventListener('change', saveName); nm.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); nm.blur(); } });
      var on = input('checkbox', !!c.enabled_ambient, { 'aria-label':'Available in Ambient: ' + (c.name || c.url) });
      on.addEventListener('change', function(){ var want = on.checked; on.disabled = true;
        call('/misc/admin/webcams/' + c.id, { method:'POST', body:{ enabled_ambient:want } }).then(function(j){ on.disabled = false; c.enabled_ambient = j.webcam.enabled_ambient; on.checked = c.enabled_ambient; el.classList.toggle('on', c.enabled_ambient); ambCams = null; })
          .catch(function(e){ on.disabled = false; on.checked = !want; err(e); }); });
      var k = kindOf(c), short = String(c.url).replace(/^https?:\/\//i, '');
      var el = h('div', { className:'vc-wa-row' + (c.enabled_ambient ? ' on' : ''), role:'listitem' }, [
        h('div', { className:'vc-wa-main' }, [nm, h('div', { className:'vc-wa-meta' }, [h('span', { className:'vc-chip vc-wa-kind', text:KIND_LABEL[k] || 'Auto' }), h('a', { className:'vc-wa-url', href:safeHttpUrl(c.url) || '#', target:'_blank', rel:'noopener noreferrer', title:c.url, text:short }), h('small', { className:'muted', text:'Added by ' + (c.added_by_name || 'someone') + (c.created_at ? ' · ' + new Date(c.created_at * 1000).toLocaleDateString() : '') })])]),
        h('label', { className:'vc-check vc-wa-toggle' }, [on, h('span', { text:'Available in Ambient' })]),
        btn('Remove', function(){ A.confirmDialog('Remove “' + (c.name || short) + '” from the list? It reappears if someone saves it on a board again.', { okText:'Remove', danger:true }).then(function(ok){ if (ok) call('/misc/admin/webcams/' + c.id, { method:'DELETE' }).then(function(j){ ambCams = null; draw(j.webcams); }).catch(err); }); }, 'ghost small danger')]);
      return el;
    }
    function draw(cams){
      cams = cams || []; var n = cams.filter(function(c){ return c.enabled_ambient; }).length;
      count.textContent = cams.length ? cams.length + (cams.length === 1 ? ' webcam' : ' webcams') + ' · ' + n + ' available in Ambient' : '';
      list.replaceChildren.apply(list, cams.length ? cams.map(row) : [h('p', { className:'muted', text:'No webcams yet. They appear here when someone saves one on a board, or add one below.' })]);
    }
    call('/misc/admin/webcams').then(function(j){ draw(j.webcams); }).catch(function(e){ list.replaceChildren(h('p', { className:'muted', text:(e && e.error) || 'Webcams unavailable.' })); });
  }

  // ------------------------------------------------------------------ module API
  root.VaultcastMisc = {
    init:function(app){ A = app; h = app.h; api = app.api; },
    render:function(host, tab, sub){
      stopAll(); host.replaceChildren(); host.classList.add('vc-misc');
      if (tab === 'drop') return drop(host, sub);
      if (tab === 'ambient') return ambient(host, sub);
      if (tab === 'calendar') { var lz = h('div', { className:'vc-lazy' }); host.appendChild(lz); return A.loadModule('vc-calendar.js', 'VaultcastCalendar').then(function(m){ if (!lz.isConnected) return; lz.className = ''; var stop = m.page(lz, sub); if (typeof stop === 'function') onCleanup(stop); }).catch(function(e){ lz.replaceChildren(empty('Calendar unavailable', (e && e.message) || '')); }); }
      return board(host, sub);
    },
    startAmbient:startAmbient,
    adminWebcams:adminWebcams,
    stop:stopAll,
    upload:uploadFile
  };
})(this);
