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
  function loading(body){ body.replaceChildren(h('p', { className:'muted vc-w-loading', text:'Loading…' })); }
  function wErr(body, e){ body.replaceChildren(h('p', { className:'muted vc-w-error', text:(e && (e.error || e.message)) || 'Unavailable right now.' })); }
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
      tick(); var t = setInterval(tick, 1000); return function(){ clearInterval(t); };
    },
    form:function(s){ return textForm([{ key:'style', label:'Style', type:'select', value:s.style || 'digital', options:[['digital', 'Digital'], ['analog', 'Analogue']] }, { key:'seconds', label:'Show seconds', type:'checkbox', value:!!s.seconds }, { key:'date', label:'Show the date', type:'checkbox', value:s.date !== false }, { key:'h12', label:'Clock format', type:'select', value:s.h12 || 'auto', options:[['auto', 'Device default'], ['off', '24-hour'], ['on', '12-hour']] }, { key:'tz', label:'Time zone (optional)', value:s.tz || '', placeholder:'e.g. America/New_York', hint:'Leave empty for this device\u2019s time zone.' }, { key:'label', label:'Label (optional)', value:s.label || '' }]); }
  };

  W.weather = { name:'Weather', desc:'Current conditions and forecast from Open-Meteo.', icon:'🌤️', w:8, h:6,
    render:function(body, s){
      if (!s.loc) { body.appendChild(h('p', { className:'muted', text:'Open the widget settings to choose a location.' })); return; }
      function load(){ weatherData({ lat:s.loc.lat, lon:s.loc.lon, units:s.units }).then(function(j){
        var c = j.current, w = wx(c.code, c.day);
        body.replaceChildren(h('div', { className:'vc-wx' }, [
          h('div', { className:'vc-wx-now' }, [h('span', { className:'vc-wx-icon', text:w.icon, 'aria-hidden':'true' }), h('div', null, [h('strong', { className:'vc-wx-temp', text:Math.round(c.temp) + j.units }), h('span', { className:'muted', text:w.text + ' · feels ' + Math.round(c.feels) + '°' }), h('span', { className:'muted vc-wx-place', text:s.loc.name })])]),
          h('div', { className:'vc-wx-meta muted' }, [h('span', { text:'💧 ' + c.humidity + '%' }), h('span', { text:'💨 ' + Math.round(c.wind) + ' ' + j.wind_units })]),
          h('div', { className:'vc-wx-hours' }, j.hours.slice(0, 8).map(function(x){ return h('div', { className:'vc-wx-h' }, [h('small', { className:'muted', text:x.time.slice(11, 16) }), h('span', { text:wx(x.code).icon }), h('strong', { text:Math.round(x.temp) + '°' }), x.rain ? h('small', { className:'vc-wx-rain', text:x.rain + '%' }) : null]); })),
          h('div', { className:'vc-wx-days' }, j.days.slice(0, 5).map(function(d, i){ return h('div', { className:'vc-wx-d' }, [h('small', { text:i === 0 ? 'Today' : new Date(d.date + 'T12:00').toLocaleDateString([], { weekday:'short' }) }), h('span', { text:wx(d.code).icon }), h('strong', { text:Math.round(d.max) + '°' }), h('small', { className:'muted', text:Math.round(d.min) + '°' })]); })),
          h('small', { className:'vc-w-source muted', text:'Open-Meteo' + (j.stale ? ' · offline, showing last update' : '') })]));
      }).catch(function(e){ wErr(body, e); }); }
      loading(body); load(); var t = setInterval(load, 600000); return function(){ clearInterval(t); };
    },
    form:function(s){
      var loc = s.loc || null, units = select([['c', 'Celsius, km/h'], ['f', 'Fahrenheit, mph']], s.units || 'c');
      return { el:h('div', { className:'vc-form' }, [field('Location', locationPicker(loc, function(p){ loc = p; })), field('Units', units)]), read:function(){ return { loc:loc, units:units.value }; } };
    }
  };

  W.calendar = { name:'Calendar', desc:'Upcoming events from ICS / webcal calendar links.', icon:'📅', w:8, h:7,
    render:function(body, s){
      var urls = String(s.urls || '').split(/\s+/).filter(Boolean);
      if (!urls.length) { body.appendChild(h('p', { className:'muted', text:'Add a calendar link (ICS or webcal) in the widget settings.' })); return; }
      function load(){
        Promise.all(urls.slice(0, 6).map(function(u, i){ return call('/misc/widget/ics?days=' + (s.days || 14) + '&url=' + encodeURIComponent(u)).then(function(j){ return j.events.map(function(e){ e.cal = i; return e; }); }).catch(function(){ return []; }); })).then(function(sets){
          var events = [].concat.apply([], sets).sort(function(a, b){ return a.start - b.start; }).slice(0, 40);
          if (!events.length) { body.replaceChildren(h('p', { className:'muted', text:'Nothing coming up.' })); return; }
          var groups = [], last = '';
          events.forEach(function(e){ var d = new Date(e.start * 1000), key = d.toDateString(); if (key !== last) { last = key; var today = new Date(), tom = new Date(Date.now() + 86400000); groups.push(h('li', { className:'vc-cal-day', text:key === today.toDateString() ? 'Today' : key === tom.toDateString() ? 'Tomorrow' : d.toLocaleDateString([], { weekday:'long', day:'numeric', month:'short' }) })); }
            groups.push(h('li', { className:'vc-cal-ev cal' + (e.cal % 6) }, [h('span', { className:'vc-cal-time', text:e.all_day ? 'All day' : d.toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' }) }), h('span', { className:'vc-cal-title' }, [h('strong', { text:e.title || '(no title)' }), e.location ? h('small', { className:'muted', text:e.location }) : null])])); });
          body.replaceChildren(list(groups, 'vc-cal'));
        });
      }
      loading(body); load(); var t = setInterval(load, 900000); return function(){ clearInterval(t); };
    },
    form:function(s){ return textForm([{ key:'urls', label:'Calendar links (one per line)', type:'textarea', value:s.urls || '', placeholder:'https://…/basic.ics', hint:'Private Google/Outlook/iCloud ICS links work. Public internet addresses only.' }, { key:'days', label:'Days ahead', type:'number', min:1, max:60, value:s.days || 14 }]); }
  };

  // Bin day and other repeating reminders, computed on the device.
  function nextOccurrence(r, from){
    var start = r.start ? new Date(r.start + 'T00:00') : new Date(from.getFullYear(), from.getMonth(), from.getDate());
    var day = new Date(from.getFullYear(), from.getMonth(), from.getDate());
    if (r.repeat === 'once') return start >= day ? start : null;
    for (var i = 0; i < 400; i++) {
      var d = new Date(day.getFullYear(), day.getMonth(), day.getDate() + i);
      if (d < start) continue;
      if (r.repeat === 'monthly') { if (d.getDate() === start.getDate()) return d; continue; }
      if (d.getDay() !== Number(r.weekday != null ? r.weekday : start.getDay())) continue;
      var weeks = Math.max(1, Number(r.every || 1)), diff = Math.round((d - start) / 604800000);
      if (diff % weeks === 0) return d;
    }
    return null;
  }
  W.reminders = { name:'Bin day & reminders', desc:'Bin collections and repeating reminders with a heads-up the evening before.', icon:'🗑️', w:6, h:6,
    render:function(body, s){
      var items = (s.items || []).map(function(r){ return { r:r, next:nextOccurrence(r, new Date()) }; }).filter(function(x){ return x.next; }).sort(function(a, b){ return a.next - b.next; });
      if (!items.length) { body.appendChild(h('p', { className:'muted', text:'Add bin collections or reminders in the widget settings.' })); return; }
      var today = new Date(); today.setHours(0, 0, 0, 0);
      body.appendChild(list(items.map(function(x){
        var days = Math.round((x.next - today) / 86400000), when = days === 0 ? 'Today' : days === 1 ? 'Tomorrow' : days < 7 ? x.next.toLocaleDateString([], { weekday:'long' }) : x.next.toLocaleDateString([], { day:'numeric', month:'short' });
        var urgent = days === 0 || (days === 1 && new Date().getHours() >= 16);
        return h('li', { className:'vc-rem' + (urgent ? ' urgent' : '') + (days <= 1 ? ' soon' : ''), style:x.r.colour ? '--vc-rem:' + x.r.colour : null }, [h('span', { className:'vc-rem-icon', text:x.r.emoji || '🗑️' }), h('span', { className:'vc-rem-title' }, [h('strong', { text:x.r.title || 'Reminder' }), h('small', { className:'muted', text:urgent && days === 1 ? 'Put it out tonight' : (x.r.note || '') })]), h('span', { className:'vc-rem-when', text:when })]);
      }), 'vc-rems'));
      var t = setTimeout(function(){ if (body.isConnected) { body.replaceChildren(); W.reminders.render(body, s); } }, 3600000); return function(){ clearTimeout(t); };
    },
    form:function(s){
      var items = (s.items || []).map(function(x){ return Object.assign({}, x); }), wrap = h('div', { className:'vc-rem-edit' });
      var DAYS = [['1', 'Monday'], ['2', 'Tuesday'], ['3', 'Wednesday'], ['4', 'Thursday'], ['5', 'Friday'], ['6', 'Saturday'], ['0', 'Sunday']];
      function draw(){
        wrap.replaceChildren.apply(wrap, items.map(function(r, i){
          function bind(el, k, num){ el.addEventListener('input', function(){ r[k] = num ? Number(el.value) : el.value; }); el.addEventListener('change', function(){ r[k] = num ? Number(el.value) : el.value; if (k === 'repeat') draw(); }); return el; }
          return h('fieldset', { className:'vc-rem-row' }, [
            bind(input('text', r.emoji || '🗑️', { maxlength:'4', className:'vc-emoji-input', 'aria-label':'Emoji' }), 'emoji'), bind(input('text', r.title || '', { placeholder:'e.g. Recycling', 'aria-label':'Title' }), 'title'),
            bind(select([['weekly', 'Weekly'], ['monthly', 'Monthly'], ['once', 'Once']], r.repeat || 'weekly', { 'aria-label':'Repeat' }), 'repeat'),
            (r.repeat || 'weekly') === 'weekly' ? bind(select(DAYS, String(r.weekday != null ? r.weekday : 1), { 'aria-label':'Day' }), 'weekday', true) : null,
            (r.repeat || 'weekly') === 'weekly' ? bind(select([['1', 'every week'], ['2', 'every 2 weeks'], ['3', 'every 3 weeks'], ['4', 'every 4 weeks']], String(r.every || 1), { 'aria-label':'Interval' }), 'every', true) : null,
            bind(input('date', r.start || '', { 'aria-label':(r.repeat || 'weekly') === 'weekly' ? 'First collection' : 'Date', title:'Start date (sets which week counts for fortnightly collections)' }), 'start'),
            bind(input('color', r.colour || '#59e9ff', { 'aria-label':'Colour' }), 'colour'),
            btn('×', function(){ items.splice(i, 1); draw(); }, 'ghost small', { 'aria-label':'Remove reminder' })]);
        }).concat([btn('Add reminder', function(){ items.push({ emoji:'🗑️', title:'', repeat:'weekly', weekday:1, every:1, colour:'#59e9ff' }); draw(); }, 'ghost')]));
      }
      draw();
      return { el:h('div', null, [h('p', { className:'muted', text:'For fortnightly bins, set the date of a known collection so the right week is used.' }), wrap]), read:function(){ return { items:items.filter(function(x){ return x.title || x.emoji; }) }; } };
    }
  };

  W.recordings = { name:'Recordings', desc:'What is recording now and what is scheduled next.', icon:'⏺️', w:6, h:6,
    render:function(body){
      function load(){ call('/misc/widget/recordings').then(function(j){
        if (j.unavailable) { body.replaceChildren(h('p', { className:'muted', text:'Live TV is turned off on this server.' })); return; }
        var rows = j.recording.map(function(r){ return h('li', { className:'vc-rec live' }, [h('span', { className:'vc-rec-dot', 'aria-label':'Recording' }), h('span', null, [h('strong', { text:r.title }), h('small', { className:'muted', text:r.channel + ' · until ' + new Date(r.end * 1000).toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' }) })])]); })
          .concat(j.upcoming.map(function(r){ var d = new Date(r.start * 1000); return h('li', { className:'vc-rec' }, [h('span', { className:'vc-rec-time', text:d.toLocaleDateString([], { weekday:'short' }) + ' ' + d.toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' }) }), h('span', null, [h('strong', { text:r.title }), h('small', { className:'muted', text:r.channel })])]); }));
        body.replaceChildren(rows.length ? list(rows) : h('p', { className:'muted', text:'No recordings scheduled.' }));
      }).catch(function(e){ wErr(body, e); }); }
      loading(body); load(); var t = setInterval(load, 60000); return function(){ clearInterval(t); };
    }
  };
  W.downloads = { name:'Downloads', desc:'Progress of downloads in the request queue.', icon:'⬇️', w:6, h:6,
    render:function(body){
      function load(){ call('/misc/widget/downloads').then(function(j){
        if (j.unavailable) { body.replaceChildren(h('p', { className:'muted', text:'Downloads are not available for your account.' })); return; }
        body.replaceChildren(j.items.length ? list(j.items.map(function(d){ var pct = Math.max(0, Math.min(100, Number(d.percent || 0))); return h('li', { className:'vc-dl' }, [h('span', { className:'vc-dl-name', text:d.name }), h('span', { className:'vc-progress', role:'progressbar', 'aria-valuenow':String(Math.round(pct)), 'aria-valuemin':'0', 'aria-valuemax':'100' }, [h('i', { style:'width:' + pct + '%' })]), h('small', { className:'muted', text:Math.round(pct) + '% · ' + (d.status || '') })]); })) : h('p', { className:'muted', text:'Nothing downloading.' }));
      }).catch(function(e){ wErr(body, e); }); }
      loading(body); load(); var t = setInterval(load, 10000); return function(){ clearInterval(t); };
    }
  };
  function pick(o, keys){ for (var i = 0; i < keys.length; i++) if (o && o[keys[i]] != null) return o[keys[i]]; return null; }
  function pct(v){ if (v == null) return null; if (typeof v === 'number') return v; if (typeof v === 'object') { var p = pick(v, ['percent', 'used_percent', 'pct']); if (p != null) return Number(p); var u = pick(v, ['used']), t = pick(v, ['total', 'size']); if (u != null && t) return 100 * u / t; } return null; }
  W.health = { name:'NAS health', desc:'CPU, memory, disks, temperature and services.', icon:'🩺', w:6, h:6,
    render:function(body){
      function load(){ call('/misc/widget/health').then(function(j){
        if (j.limited) { body.replaceChildren(h('div', { className:'vc-health-ok' }, [h('span', { className:'vc-rec-dot ok' }), h('strong', { text:'Server online' }), h('small', { className:'muted', text:'Detailed health is visible to administrators.' })])); return; }
        var gauges = [['CPU', pct(pick(j, ['cpu_percent', 'cpu']))], ['Memory', pct(pick(j, ['memory', 'mem']))], ['Media disk', pct(pick(j, ['media_disk', 'disk']))], ['Data disk', pct(pick(j, ['data_disk']))]].filter(function(g){ return g[1] != null && !isNaN(g[1]); });
        var temp = pick(j, ['temperature', 'temp']); if (temp && typeof temp === 'object') temp = pick(temp, ['cpu', 'value', 'max']);
        body.replaceChildren(h('div', { className:'vc-health' }, [
          h('div', { className:'vc-gauges' }, gauges.map(function(g){ var v = Math.round(g[1]); return h('div', { className:'vc-gauge' + (v > 90 ? ' bad' : v > 75 ? ' warn' : ''), style:'--vc-p:' + v }, [h('strong', { text:v + '%' }), h('small', { className:'muted', text:g[0] })]); })),
          h('p', { className:'muted' }, [j.uptime_human ? 'Up ' + j.uptime_human : '', temp ? ' · ' + Math.round(Number(temp)) + '°C' : '', j.load ? ' · load ' + (Array.isArray(j.load) ? j.load.slice(0, 3).map(function(x){ return Number(x).toFixed(2); }).join(' ') : j.load) : '']),
          j.services ? h('div', { className:'vc-services' }, j.services.slice(0, 8).map(function(s){ var ok = s.active === true || s.active === 'active' || s.active === 'running'; return h('span', { className:'vc-chip' + (ok ? ' ok' : ' bad'), text:(ok ? '● ' : '○ ') + s.name }); })) : null]));
      }).catch(function(e){ wErr(body, e); }); }
      loading(body); load(); var t = setInterval(load, 20000); return function(){ clearInterval(t); };
    }
  };
  W.recent = { name:'Recently added', desc:'The newest films and shows in the library.', icon:'🆕', w:12, h:6,
    render:function(body, s){
      call('/misc/widget/recent?n=' + (s.count || 12)).then(function(j){
        if (!j.items.length) { body.replaceChildren(h('p', { className:'muted', text:'Nothing new yet.' })); return; }
        body.replaceChildren(h('div', { className:'vc-poster-row' }, j.items.map(function(it){ return h('button', { type:'button', className:'vc-poster', title:it.title, onClick:function(){ if (it.show_key && A.openShow) A.openShow(it.show_key); else if (A.openMovie) A.openMovie(it.id); } }, [h('img', { src:url(it.poster), alt:'', loading:'lazy' }), h('span', { className:'vc-poster-cap' }, [h('strong', { text:it.title }), h('small', { className:'muted', text:it.subtitle || '' })])]); })));
      }).catch(function(e){ wErr(body, e); });
      loading(body);
    },
    form:function(s){ return textForm([{ key:'count', label:'How many', type:'number', min:4, max:30, value:s.count || 12 }]); }
  };
  W.nowplaying = { name:'Now playing', desc:'What you and your friends are watching (admins see everyone).', icon:'▶️', w:8, h:5,
    render:function(body){
      function load(){ call('/misc/widget/nowplaying').then(function(j){
        body.replaceChildren(j.sessions.length ? list(j.sessions.map(function(x){ return h('li', { className:'vc-np' }, [h('img', { src:url(x.poster), alt:'', loading:'lazy' }), h('span', { className:'vc-np-copy' }, [h('strong', { text:x.title }), h('small', { className:'muted', text:x.user.name + ' · ' + ago(x.updated_at) }), x.percent != null ? h('span', { className:'vc-progress' }, [h('i', { style:'width:' + x.percent + '%' })]) : null])]); })) : h('p', { className:'muted', text:'Nobody is watching right now.' }));
      }).catch(function(e){ wErr(body, e); }); }
      loading(body); load(); var t = setInterval(load, 20000); return function(){ clearInterval(t); };
    }
  };
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
  W.slideshow = { name:'Slideshow', desc:'Library frames and posters, or your own image links, with a slow pan.', icon:'🖼️', w:12, h:8,
    render:function(body, s){
      var stage = h('div', { className:'vc-slides' }); body.appendChild(stage); body.classList.add('vc-flush');
      var t = kenBurns(stage, function(){ return s.source === 'urls' ? Promise.resolve(String(s.urls || '').split(/\s+/).filter(function(u){ return /^https?:\/\//i.test(u); }).map(function(u){ return { url:u, title:'' }; })) : call('/misc/ambient/stills?n=30&kind=' + (s.source || 'mixed')).then(function(j){ return j.stills.map(function(x){ return { url:url(x.url), title:x.title + (x.year ? ' (' + x.year + ')' : '') }; }); }); }, { interval:(s.seconds || 12) * 1000, captions:s.captions !== false });
      return t.stop;
    },
    form:function(s){ return textForm([{ key:'source', label:'Pictures', type:'select', value:s.source || 'mixed', options:[['mixed', 'Library frames and posters'], ['frames', 'Library frames'], ['posters', 'Library posters'], ['urls', 'My image links']] }, { key:'urls', label:'Image links (one per line, used with “My image links”)', type:'textarea', value:s.urls || '' }, { key:'seconds', label:'Seconds per picture', type:'number', min:4, max:300, value:s.seconds || 12 }, { key:'captions', label:'Show titles', type:'checkbox', value:s.captions !== false }]); }
  };
  W.rss = { name:'News feed', desc:'Headlines from any RSS or Atom feed.', icon:'📰', w:8, h:7,
    render:function(body, s){
      if (!s.url) { body.appendChild(h('p', { className:'muted', text:'Add a feed address in the widget settings.' })); return; }
      function load(){ call('/misc/widget/rss?url=' + encodeURIComponent(s.url)).then(function(j){
        body.replaceChildren(h('p', { className:'vc-rss-title', text:j.title || s.url }), list(j.items.slice(0, s.count || 10).map(function(i){ return h('li', { className:'vc-rss-item' }, [i.link ? h('a', { href:i.link, target:'_blank', rel:'noopener noreferrer', text:i.title }) : h('span', { text:i.title }), i.date ? h('small', { className:'muted', text:ago(i.date) }) : null]); })));
      }).catch(function(e){ wErr(body, e); }); }
      loading(body); load(); var t = setInterval(load, 900000); return function(){ clearInterval(t); };
    },
    form:function(s){ return textForm([{ key:'url', label:'Feed address', type:'url', value:s.url || '', placeholder:'https://example.com/feed.xml' }, { key:'count', label:'Headlines', type:'number', min:3, max:25, value:s.count || 10 }]); }
  };
  W.drop = { name:'Drop', desc:'Drop files here to keep them for a while, or send them to your other devices.', icon:'📥', w:6, h:6,
    render:function(body){
      var zone = h('div', { className:'vc-mini-drop', tabindex:'0', role:'button', 'aria-label':'Choose files to upload to Drop' }, [h('strong', { text:'Drop files here' }), h('small', { className:'muted', text:'Kept for 24 hours · sent to your devices' })]), items = h('div');
      body.append(zone, items);
      var picker = h('input', { type:'file', multiple:true, hidden:true }); body.appendChild(picker);
      function refresh(){ call('/drop/state').then(function(j){ items.replaceChildren(list(j.items.filter(function(i){ return i.status === 'available'; }).slice(0, 5).map(function(i){ return h('li', { className:'vc-drop-mini' }, [h('span', { text:kindIcon(i) }), h('a', { href:i.kind === 'link' ? i.body : url('/api/drop/items/' + i.id + '/download'), target:i.kind === 'link' ? '_blank' : null, rel:'noopener', text:i.name }), h('small', { className:'muted', text:'⏳ ' + until(i.expires_at) })]); }))); }).catch(function(){}); }
      function send(files){ Array.prototype.forEach.call(files, function(f){ uploadFile(f, { expires_hours:24, inbox:true }, function(p){ zone.querySelector('small').textContent = p.done ? 'Uploaded ' + f.name : Math.round(100 * p.sent / f.size) + '% · ' + f.name; if (p.done) refresh(); }).catch(err); }); }
      zone.addEventListener('click', function(){ picker.click(); }); zone.addEventListener('keydown', function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); picker.click(); } });
      picker.addEventListener('change', function(){ send(picker.files); picker.value = ''; });
      zone.addEventListener('dragover', function(e){ e.preventDefault(); zone.classList.add('over'); }); zone.addEventListener('dragleave', function(){ zone.classList.remove('over'); });
      zone.addEventListener('drop', function(e){ e.preventDefault(); zone.classList.remove('over'); send(e.dataTransfer.files); });
      refresh(); var t = setInterval(refresh, 60000); return function(){ clearInterval(t); };
    }
  };
  W.leaderboard = { name:'Leaderboard', desc:'Top players or teams this week.', icon:'🏆', w:6, h:7,
    render:function(body, s){
      function load(){ call('/play/leaderboard?board=' + (s.board || 'points') + '&period=' + (s.period || 'week')).then(function(j){
        body.replaceChildren(j.rows.length ? h('ol', { className:'vc-w-list vc-mini-lb' }, j.rows.slice(0, 8).map(function(r, i){ var who = r.team && s.board === 'teams' ? r.team : r.user; return h('li', { className:r.me ? 'me' : '' }, [h('span', { className:'vc-lb-rank', text:String(i + 1) }), h('strong', { text:(r.team && s.board === 'teams' ? r.team.emblem + ' ' : '') + who.name }), h('span', { className:'vc-lb-value', text:j.unit === 'seconds' ? Math.round(r.value / 3600) + ' h' : Number(r.value).toLocaleString() })]); })) : h('p', { className:'muted', text:'No scores yet.' }));
      }).catch(function(e){ wErr(body, e); }); }
      loading(body); load(); var t = setInterval(load, 120000); return function(){ clearInterval(t); };
    },
    form:function(s){ return textForm([{ key:'board', label:'Board', type:'select', value:s.board || 'points', options:[['points', 'Points'], ['teams', 'Teams'], ['blocks', 'Stackfall'], ['snake', 'Neon Snake'], ['merge', '2048 Merge'], ['breakout', 'Brickbreak'], ['quiz', 'Quiz'], ['emu-time', 'Most time played'], ['emu-sessions', 'Most games played']] }, { key:'period', label:'Period', type:'select', value:s.period || 'week', options:[['day', 'Today'], ['week', 'This week'], ['month', 'This month'], ['all', 'All time']] }]); }
  };

  // ------------------------------------------------------------------ Ken Burns slideshow (widget + ambient)
  function kenBurns(stage, source, opts){
    opts = opts || {};
    var layers = [h('div', { className:'vc-kb-layer' }), h('div', { className:'vc-kb-layer' })], cap = h('div', { className:'vc-kb-cap' }), pool = [], i = 0, front = 0, timer = 0, stopped = false;
    layers.forEach(function(l){ stage.appendChild(l); }); if (opts.captions) stage.appendChild(cap);
    var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    function refill(){ return source().then(function(list){ pool = list.slice(); for (var k = pool.length - 1; k > 0; k--) { var r = Math.floor(Math.random() * (k + 1)), t = pool[k]; pool[k] = pool[r]; pool[r] = t; } i = 0; }); }
    function next(){
      if (stopped || !stage.isConnected) return;
      if (i >= pool.length) { refill().then(function(){ if (pool.length) next(); else stage.replaceChildren(h('p', { className:'muted vc-kb-empty', text:'No pictures available yet.' })); }).catch(function(){ timer = setTimeout(next, 30000); }); return; }
      var item = pool[i++], img = new Image();
      img.onload = function(){
        if (stopped) return;
        var back = layers[1 - front];
        back.replaceChildren(img);
        var fx = ['zoom-in-l', 'zoom-in-r', 'zoom-out-l', 'zoom-out-r', 'pan-up', 'pan-down'][Math.floor(Math.random() * 6)];
        img.className = 'vc-kb-img ' + (reduce ? '' : fx); img.style.animationDuration = (opts.interval || 12000) * 1.25 + 'ms';
        back.classList.add('show'); layers[front].classList.remove('show'); front = 1 - front;
        cap.textContent = item.title || '';
        timer = setTimeout(next, opts.interval || 12000);
      };
      img.onerror = function(){ timer = setTimeout(next, 200); };
      img.alt = ''; img.decoding = 'async'; img.src = item.url;
    }
    next();
    return { stop:function(){ stopped = true; clearTimeout(timer); } };
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
      function startMode(){ teardown(); if (mode === 'radio') radio(); else if (mode === 'movie') movie(); else iptv(); }
      startMode();
      return function(){ teardown(); };
    },
    form:function(s){ return textForm([{ key:'mode', label:'Play', type:'select', value:s.mode || 'iptv', options:[['iptv', 'Live TV (IPTV)'], ['radio', 'Internet radio'], ['movie', 'A film from the library']] }, { key:'muted', label:'Start live TV muted', type:'checkbox', value:s.muted !== false }]); },
    merge:true
  };

  // ------------------------------------------------------------------ Board (dashboard grid)
  var COLS = 24;
  var STARTER = [{ type:'clock', x:0, y:0, w:6, h:4 }, { type:'weather', x:6, y:0, w:8, h:6 }, { type:'reminders', x:14, y:0, w:5, h:6 }, { type:'notes', x:19, y:0, w:5, h:6, settings:{ text:'[ ] Welcome to your board\n[ ] Press Edit to move, resize and add widgets' } }, { type:'recent', x:0, y:6, w:14, h:6 }, { type:'nowplaying', x:14, y:6, w:10, h:6 }];
  function overlaps(a, b){ return a !== b && a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h; }
  /** Push anything that collides with the moved widget further down, then float everything up (gridstack-style). */
  function settle(widgets, moved){
    var guard = 0, changed = true;
    while (changed && guard++ < 500) { changed = false; widgets.forEach(function(w){ if (w === moved) return; widgets.forEach(function(o){ if (o !== w && overlaps(w, o) && (o === moved || o.y <= w.y)) { w.y = o.y + o.h; changed = true; } }); }); }
    widgets.slice().sort(function(a, b){ return a.y - b.y || a.x - b.x; }).forEach(function(w){ if (w === moved) return; while (w.y > 0) { w.y--; if (widgets.some(function(o){ return overlaps(w, o); })) { w.y++; break; } } });
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
    function save(now){ clearTimeout(saveT); saveT = setTimeout(function(){ call('/misc/boards', { method:'POST', body:{ boards:data.boards, default:data.default } }).then(function(j){ data.boards.forEach(function(b, i){ if (j.boards[i] && b.id !== j.boards[i].id) b.id = j.boards[i].id; }); }).catch(err); }, now ? 0 : 800); }
    onCleanup(function(){ clearTimeout(saveT); Object.keys(mounted).forEach(function(k){ unmount(k); }); exitKiosk(); });
    var watch = setInterval(function(){ if (!shell.isConnected) { clearInterval(watch); stopAll(); } }, 3000); onCleanup(function(){ clearInterval(watch); });
    function unmount(id){ var m = mounted[id]; if (m && m.cleanup) try { m.cleanup(); } catch (e) {} delete mounted[id]; }
    function headBar(){
      var sel = select(data.boards.map(function(b){ return [b.id, b.name + (b.id === data.default ? ' ★' : '')]; }), cur.id, { 'aria-label':'Board', className:'vc-board-select', onChange:function(){ switchTo(this.value); } });
      head.replaceChildren(
        h('div', { className:'vc-board-title' }, [sel, editing ? null : h('span', { className:'muted', text:cur.widgets.length + ' widgets' })]),
        h('div', { className:'vc-board-tools' }, editing ? [btn('+ Add widget', addWidget, 'primary'), btn('Board settings', boardSettings, 'ghost'), btn('Done', function(){ setEdit(false); }, 'primary')]
          : [btn('Edit', function(){ setEdit(true); }, 'ghost'), btn('New board', newBoard, 'ghost'), btn('Kiosk', function(){ enterKiosk(); }, 'primary', { title:'Full screen, hide the menus and keep the screen awake' })]));
    }
    function switchTo(id){ var b = data.boards.filter(function(x){ return x.id === id; })[0]; if (!b) return; cur = b; A.state.miscSub = id; A.pushNav(); paint(); }
    function setEdit(on){ editing = on; shell.classList.toggle('editing', on); paint(); }
    function newBoard(){
      modal('New board', function(b, close){
        var name = input('text', '', { placeholder:'e.g. Kitchen', maxlength:'40', 'aria-label':'Board name' }), starter = input('checkbox', true);
        b.appendChild(h('form', { className:'vc-form', onSubmit:function(e){ e.preventDefault(); var nb = { id:rid(10), name:name.value.trim() || 'Board', theme:'glass', background:'', widgets:starter.checked ? STARTER.map(function(w){ return Object.assign({ id:rid(8), settings:{} }, JSON.parse(JSON.stringify(w))); }) : [] }; data.boards.push(nb); if (data.boards.length > 12) { data.boards.pop(); A.toast('Up to 12 boards'); return; } save(true); close(); switchTo(nb.id); setEdit(!starter.checked); } }, [field('Name', name), h('label', { className:'vc-check' }, [starter, h('span', { text:'Start with a few useful widgets' })]), h('button', { type:'submit', className:'btn primary', text:'Create board' })]));
        setTimeout(function(){ name.focus(); }, 50);
      });
    }
    function boardSettings(){
      modal('Board settings', function(b, close){
        var name = input('text', cur.name, { maxlength:'40', 'aria-label':'Board name' }), theme = select([['glass', 'Glass (frosted panels)'], ['solid', 'Solid panels'], ['clear', 'Minimal (no panels)']], cur.theme || 'glass'), bg = input('url', /^https?:/.test(cur.background || '') ? cur.background : '', { placeholder:'https://… (optional)' }), bgKind = select([['', 'Theme background'], ['aurora', 'Aurora gradient'], ['image', 'Image link']], /^https?:/.test(cur.background || '') ? 'image' : (cur.background || '')), def = input('checkbox', data.default === cur.id);
        b.appendChild(h('form', { className:'vc-form', onSubmit:function(e){ e.preventDefault(); cur.name = name.value.trim() || 'Board'; cur.theme = theme.value; cur.background = bgKind.value === 'image' ? bg.value.trim() : bgKind.value; if (def.checked) data.default = cur.id; save(true); close(); paint(); } }, [
          field('Name', name), field('Panel style', theme), field('Background', bgKind), field('Background image link', bg), h('label', { className:'vc-check' }, [def, h('span', { text:'Open this board by default' })]),
          h('div', { className:'vc-overlay-actions' }, [h('button', { type:'submit', className:'btn primary', text:'Save' }), btn('Duplicate', function(){ var copy = JSON.parse(JSON.stringify(cur)); copy.id = rid(10); copy.name = cur.name + ' copy'; copy.widgets.forEach(function(w){ w.id = rid(8); }); data.boards.push(copy); save(true); close(); switchTo(copy.id); }, 'ghost'),
            data.boards.length > 1 ? btn('Delete board', function(){ A.confirmDialog('Delete the board “' + cur.name + '”?', { okText:'Delete', danger:true }).then(function(ok){ if (!ok) return; data.boards = data.boards.filter(function(x){ return x !== cur; }); if (data.default === cur.id) data.default = data.boards[0].id; save(true); close(); switchTo(data.boards[0].id); }); }, 'ghost danger') : null])]));
      });
    }
    function addWidget(){
      modal('Add a widget', function(b, close){
        b.appendChild(h('div', { className:'vc-widget-catalogue' }, data.widgets.filter(function(t){ return W[t]; }).map(function(t){ var d = W[t]; return h('button', { type:'button', className:'vc-catalogue-item', onClick:function(){ close(); var spot = freeSpot(cur.widgets, d.w, d.h), w = { id:rid(8), type:t, x:spot.x, y:spot.y, w:d.w, h:d.h, settings:{} }; cur.widgets.push(w); save(); paint(); if (d.form) setTimeout(function(){ widgetSettings(w); }, 60); } }, [h('span', { className:'vc-catalogue-icon', text:d.icon, 'aria-hidden':'true' }), h('strong', { text:d.name }), h('small', { className:'muted', text:d.desc })]); })));
      });
    }
    function widgetSettings(w){
      var d = W[w.type]; if (!d || !d.form) return;
      modal(d.name + ' settings', function(b, close){
        var f = d.form(w.settings || {});
        b.appendChild(h('form', { className:'vc-form', onSubmit:function(e){ e.preventDefault(); w.settings = Object.assign({}, w.settings || {}, f.read()); save(); close(); remount(w); } }, [f.el, h('div', { className:'vc-overlay-actions' }, [h('button', { type:'submit', className:'btn primary', text:'Save' })])]));
      });
    }
    function place(el, w){ el.style.gridColumn = (w.x + 1) + ' / span ' + w.w; el.style.gridRow = (w.y + 1) + ' / span ' + w.h; el.style.setProperty('--vc-rows', String(Math.max(4, w.h))); }
    function metrics(){ var r = grid.getBoundingClientRect(), cs = getComputedStyle(grid), gap = parseFloat(cs.columnGap) || 0, row = parseFloat(cs.gridAutoRows) || 40; return { left:r.left, top:r.top, col:(r.width + gap) / COLS, row:row + (parseFloat(cs.rowGap) || 0) }; }
    function dragify(el, w, handle, mode){
      handle.addEventListener('pointerdown', function(e){
        if (!editing || e.button > 0 || (window.matchMedia && matchMedia('(max-width: 760px)').matches)) return; e.preventDefault(); e.stopPropagation();
        var m = metrics(), sx = e.clientX, sy = e.clientY, ox = w.x, oy = w.y, ow = w.w, oh = w.h, ghost = h('div', { className:'vc-ghost' });
        grid.appendChild(ghost); place(ghost, w); el.classList.add('dragging');
        handle.setPointerCapture(e.pointerId);
        function move(ev){
          var dx = Math.round((ev.clientX - sx) / m.col), dy = Math.round((ev.clientY - sy) / m.row);
          if (mode === 'move') { w.x = Math.max(0, Math.min(COLS - w.w, ox + dx)); w.y = Math.max(0, oy + dy); el.style.transform = 'translate(' + (ev.clientX - sx) + 'px,' + (ev.clientY - sy) + 'px)'; }
          else { w.w = Math.max(2, Math.min(COLS - w.x, ow + dx)); w.h = Math.max(2, Math.min(24, oh + dy)); el.style.width = (el._w0 + ev.clientX - sx) + 'px'; el.style.height = (el._h0 + ev.clientY - sy) + 'px'; }
          place(ghost, w);
        }
        el._w0 = el.offsetWidth; el._h0 = el.offsetHeight;
        function up(){ handle.removeEventListener('pointermove', move); handle.removeEventListener('pointerup', up); handle.removeEventListener('pointercancel', up); ghost.remove(); el.classList.remove('dragging'); el.style.transform = ''; el.style.width = ''; el.style.height = ''; settle(cur.widgets, w); cur.widgets.forEach(function(x){ var n = grid.querySelector('[data-wid="' + x.id + '"]'); if (n) place(n, x); }); save(); }
        handle.addEventListener('pointermove', move); handle.addEventListener('pointerup', up); handle.addEventListener('pointercancel', up);
      });
      // Keyboard: arrows move, shift+arrows resize while the handle has focus.
      handle.addEventListener('keydown', function(e){
        if (!editing) return; var k = { ArrowLeft:[-1, 0], ArrowRight:[1, 0], ArrowUp:[0, -1], ArrowDown:[0, 1] }[e.key]; if (!k) return; e.preventDefault();
        if (e.shiftKey) { w.w = Math.max(2, Math.min(COLS - w.x, w.w + k[0])); w.h = Math.max(2, Math.min(24, w.h + k[1])); } else { w.x = Math.max(0, Math.min(COLS - w.w, w.x + k[0])); w.y = Math.max(0, w.y + k[1]); }
        settle(cur.widgets, w); cur.widgets.forEach(function(x){ var n = grid.querySelector('[data-wid="' + x.id + '"]'); if (n) place(n, x); }); save();
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
      shell.style.backgroundImage = /^https?:\/\//.test(cur.background || '') ? 'url("' + cur.background.replace(/["\\]/g, '') + '")' : '';
      shell.classList.toggle('bg-aurora', cur.background === 'aurora'); shell.classList.toggle('bg-image', /^https?:\/\//.test(cur.background || ''));
      grid.replaceChildren();
      if (!cur.widgets.length) grid.appendChild(h('div', { className:'vc-board-empty' }, [empty('This board is empty', 'Add clocks, weather, live TV, notes and more.', [btn('+ Add widget', function(){ if (!editing) setEdit(true); addWidget(); }, 'primary')])]));
      cur.widgets.slice().sort(function(a, b){ return a.y - b.y || a.x - b.x; }).forEach(function(w){
        var d = W[w.type] || { name:w.type, icon:'?' }, body = h('div', { className:'vc-w-body' });
        var handle = h('div', { className:'vc-w-head', tabindex:editing ? '0' : null, 'aria-label':editing ? 'Move ' + d.name + ' (arrow keys; Shift to resize)' : null }, [h('span', { className:'vc-w-icon', text:d.icon, 'aria-hidden':'true' }), h('span', { className:'vc-w-name', text:(w.settings && w.settings.title) || d.name }),
          editing ? h('span', { className:'vc-w-actions' }, [d.form ? btn('⚙', function(e){ e.stopPropagation(); widgetSettings(w); }, 'ghost vc-icon-btn small', { 'aria-label':'Settings for ' + d.name }) : null, btn('×', function(e){ e.stopPropagation(); A.confirmDialog('Remove this ' + d.name + ' widget?', { okText:'Remove', danger:true }).then(function(ok){ if (!ok) return; cur.widgets = cur.widgets.filter(function(x){ return x !== w; }); save(); paint(); }); }, 'ghost vc-icon-btn small', { 'aria-label':'Remove ' + d.name })]) : (d.form ? btn('⚙', function(){ widgetSettings(w); }, 'ghost vc-icon-btn small vc-w-quickset', { 'aria-label':'Settings for ' + d.name }) : null)]);
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
      document.addEventListener('pointermove', wake); document.addEventListener('pointerdown', wake); document.addEventListener('keydown', key); document.addEventListener('fullscreenchange', fs);
      kiosk = { exit:exit, off:function(){ clearTimeout(t); document.removeEventListener('pointermove', wake); document.removeEventListener('pointerdown', wake); document.removeEventListener('keydown', key); document.removeEventListener('fullscreenchange', fs); } };
      if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen().then(function(){ if (kiosk) kiosk.wasFull = true; }).catch(function(){});
      wake();
    }
    function exitKiosk(){
      if (!kiosk) return; var k = kiosk; kiosk = null; k.off(); k.exit.remove();
      document.body.classList.remove('vc-kiosk', 'vc-cursor-hidden'); wantWake(-1);
      if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(function(){});
    }
    call('/misc/boards').then(function(j){
      data = j;
      if (!data.boards.length) { data.boards = [{ id:rid(10), name:'My board', theme:'glass', background:'', widgets:STARTER.map(function(w){ return Object.assign({ id:rid(8), settings:{} }, JSON.parse(JSON.stringify(w))); }) }]; data.default = data.boards[0].id; save(true); }
      var parts = String(sub || '').split('/');
      cur = data.boards.filter(function(b){ return b.id === parts[0]; })[0] || data.boards.filter(function(b){ return b.id === data.default; })[0] || data.boards[0];
      paint();
      if (parts[1] === 'kiosk') enterKiosk();
    }).catch(function(e){ grid.replaceChildren(empty('Boards unavailable', (e && e.error) || '')); });
  }

  // ------------------------------------------------------------------ Drop: chunked, resumable uploads
  function kindIcon(i){ if (i.kind === 'link') return '🔗'; if (i.kind === 'text') return '📝'; var m = String(i.mime || ''); if (/^image\//.test(m)) return '🖼️'; if (/^video\//.test(m)) return '🎬'; if (/^audio\//.test(m)) return '🎵'; if (/pdf/.test(m)) return '📄'; if (/zip|compressed|tar|7z|rar/.test(m)) return '🗜️'; return '📦'; }
  var STATUS = { available:['Available', 'ok'], expired:['Expired', 'muted'], exhausted:['Download limit reached', 'muted'], uploading:['Uploading', 'warn'], deleted:['Deleted', 'muted'] };
  function fileKey(f){ return 'vaultcast-drop-up:' + [f.name, f.size, f.lastModified || 0].join('|'); }
  /** Upload one file in chunks; resumes a previous attempt of the same file (same name, size and date). */
  function uploadFile(file, opts, onProgress, control){
    control = control || {};
    var key = fileKey(file), saved = store(key), id = null, chunk = 2097152;
    function begin(){
      if (saved && saved.id) return api('/drop/upload/' + saved.id).then(function(j){ if (j && j.ok && !j.complete && j.bytes === file.size) { id = saved.id; chunk = j.chunk || chunk; return j.received; } store(key, null); return fresh(); });
      return fresh();
    }
    function fresh(){ return call('/drop/upload', { method:'POST', body:Object.assign({ name:file.name, size:file.size, mime:file.type || 'application/octet-stream' }, opts) }).then(function(j){ id = j.id; chunk = j.chunk || chunk; store(key, { id:id, at:Date.now() }); return 0; }); }
    function send(offset, tries){
      if (control.cancelled) return Promise.reject({ error:'Cancelled', cancelled:true });
      if (control.paused) return new Promise(function(res){ control.resume = function(){ control.resume = null; res(send(offset, 0)); }; });
      onProgress({ sent:offset, id:id });
      if (offset >= file.size) return Promise.resolve();
      var end = Math.min(file.size, offset + chunk);
      return fetch(A.API + '/drop/upload/' + id + '?offset=' + offset, { method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'application/octet-stream' }, body:file.slice(offset, end) }).then(function(r){
        if (r.status === 413) throw { error:'The web server refused a ' + (chunk / 1048576).toFixed(1) + ' MB upload piece (HTTP 413). Ask the administrator to raise the web server body limit (client_max_body_size / LimitRequestBody) to at least 3 MB.', fatal:true };
        return r.json().catch(function(){ return { ok:false, error:'HTTP ' + r.status }; }).then(function(j){
          if (r.status === 409 && j.received != null) return send(j.received, tries);
          if (!j.ok) { if (r.status >= 500 || r.status === 0) throw { error:j.error || 'Server error', retry:true }; throw { error:j.error || 'Upload failed', fatal:true }; }
          if (j.complete) { store(key, null); onProgress({ sent:file.size, id:id, done:true, item:j.item }); return j; }
          return send(j.received, 0);
        });
      }).catch(function(e){
        if (e && (e.fatal || e.cancelled)) throw e;
        if (tries >= 5) throw { error:(e && e.error) || 'Connection lost. Add the same file again later to resume.' };
        return new Promise(function(res){ setTimeout(res, 1000 * Math.pow(2, tries)); }).then(function(){ return api('/drop/upload/' + id).then(function(j){ return send(j && j.ok ? j.received : offset, tries + 1); }, function(){ return send(offset, tries + 1); }); });
      });
    }
    return begin().then(function(start){ return send(start, 0); }).then(function(r){ return r; });
  }
  function shareDialog(item, refresh, pub){
    modal('Share “' + item.name + '”', function(b){
      var login = input('checkbox', true), pw = input('password', '', { placeholder:'Optional password', autocomplete:'new-password', minlength:'4', maxlength:'72' }), out = h('div', { className:'vc-share-out' }), links = h('div', { className:'vc-share-links' });
      function drawLinks(it){
        var live = (it.links || []).filter(function(l){ return !l.revoked; });
        links.replaceChildren(live.length ? h('h3', { text:'Active links' }) : null);
        live.forEach(function(l){ var full = location.origin + l.url; links.appendChild(h('div', { className:'vc-share-row' }, [h('code', { text:full.replace(/^https?:\/\//, '') }), h('span', { className:'vc-chip', text:(l.require_login ? 'Sign-in' : 'Public') + (l.password ? ' · password' : '') + ' · ' + l.downloads + ' downloads' }), btn('Copy', function(){ copy(full); }, 'ghost small'), btn('Revoke', function(){ call('/drop/links/' + l.token + '/revoke', { method:'POST' }).then(function(){ l.revoked = true; drawLinks(it); refresh(); }).catch(err); }, 'ghost small danger')])); });
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
    function drawUsage(){ var pctUsed = st.quota ? Math.min(100, 100 * st.used / st.quota) : 0; usage.replaceChildren(h('div', { className:'vc-usage-text' }, [h('strong', { text:size(st.used) }), h('span', { className:'muted', text:st.quota ? ' of ' + size(st.quota) + ' in use' : ' in use (no limit)' })]), st.quota ? h('span', { className:'vc-progress' + (pctUsed > 90 ? ' bad' : ''), role:'progressbar', 'aria-valuenow':String(Math.round(pctUsed)), 'aria-valuemin':'0', 'aria-valuemax':'100' }, [h('i', { style:'width:' + pctUsed + '%' })]) : null); }
    function drawItems(){
      var visible = st.items.filter(function(i){ return i.status !== 'deleted'; });
      if (!visible.length) { itemsBox.replaceChildren(h('p', { className:'muted vc-drop-empty', text:'Nothing here yet. Drop a file or write a note above.' })); return; }
      itemsBox.replaceChildren.apply(itemsBox, visible.map(function(i){
        var s = STATUS[i.status] || [i.status, 'muted'], live = i.status === 'available';
        var meta = [i.kind === 'file' ? size(i.bytes) : (i.kind === 'link' ? 'Link' : 'Note'), live ? 'expires in ' + until(i.expires_at) : null, i.max_downloads ? i.downloads + ' / ' + i.max_downloads + ' downloads' : (i.downloads ? i.downloads + ' downloads' : null), i.inbox ? 'sent to my devices' : null, ago(i.created_at)].filter(Boolean).join(' · ');
        var actions = [];
        if (live && i.kind === 'file') actions.push(h('a', { className:'btn primary small', href:url('/api/drop/items/' + i.id + '/download'), download:i.name, text:'Download' }));
        if (live && i.kind === 'file' && /^(image|video|audio)\/|pdf/.test(i.mime)) actions.push(h('a', { className:'btn ghost small', href:url('/api/drop/items/' + i.id + '/download?inline=1'), target:'_blank', rel:'noopener', text:'Open' }));
        if (live && i.kind === 'link') actions.push(h('a', { className:'btn primary small', href:i.body, target:'_blank', rel:'noopener noreferrer', text:'Open link' }));
        if (live && i.kind !== 'file') actions.push(btn('Copy', function(){ copy(i.body); }, 'ghost small'));
        if (live && st.can_upload) actions.push(btn('Share', function(){ shareDialog(i, refresh, st.public_links); }, 'ghost small'));
        if (i.status !== 'uploading') actions.push(btn('Limits', function(){ limitsDialog(i); }, 'ghost small'));
        actions.push(btn('Delete', function(){ A.confirmDialog('Delete “' + i.name + '” now? Share links stop working.', { okText:'Delete', danger:true }).then(function(ok){ if (ok) call('/drop/items/' + i.id, { method:'DELETE' }).then(refresh).catch(err); }); }, 'ghost small danger'));
        return h('article', { className:'vc-drop-item ' + i.status }, [h('span', { className:'vc-drop-icon', text:kindIcon(i), 'aria-hidden':'true' }), h('div', { className:'vc-drop-copy' }, [h('strong', { text:i.name }), h('small', { className:'muted', text:meta }), i.kind === 'text' && live ? h('p', { className:'vc-drop-text', text:i.body.length > 280 ? i.body.slice(0, 280) + '…' : i.body }) : null]), h('span', { className:'vc-chip ' + s[1], text:s[0] }), h('div', { className:'vc-row-actions' }, actions)]);
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
          var control = {}, bar = h('i'), label = h('small', { className:'muted', text:'Starting…' }), pause = btn('Pause', function(){ control.paused = !control.paused; pause.textContent = control.paused ? 'Resume' : 'Pause'; if (!control.paused && control.resume) control.resume(); }, 'ghost small'), cancel = btn('Cancel', function(){ control.cancelled = true; if (control.resume) control.resume(); }, 'ghost small');
          var row = h('div', { className:'vc-upload' }, [h('span', { className:'vc-drop-icon', text:kindIcon({ kind:'file', mime:f.type }) }), h('div', { className:'vc-drop-copy' }, [h('strong', { text:f.name }), h('span', { className:'vc-progress' }, [bar]), label]), h('div', { className:'vc-row-actions' }, [pause, cancel])]);
          queue.appendChild(row);
          var t0 = Date.now(), first = null;
          uploadFile(f, { expires_hours:exp.hours(), max_downloads:dl.value(), inbox:inbox.checked }, function(p){
            if (first == null) first = p.sent;
            var pc = f.size ? 100 * p.sent / f.size : 100; bar.style.width = pc + '%';
            var rate = (p.sent - first) / Math.max(1, (Date.now() - t0) / 1000);
            label.textContent = p.done ? 'Uploaded · ' + size(f.size) : Math.floor(pc) + '% · ' + size(p.sent) + ' of ' + size(f.size) + (rate > 0 ? ' · ' + size(rate) + '/s' : '') + (first > 0 ? ' · resumed' : '');
          }, control).then(function(){ row.classList.add('done'); pause.remove(); cancel.remove(); setTimeout(function(){ row.remove(); }, 4000); refresh(); if (inbox.checked) A.toast('Sent to your devices', { kind:'ok' }); })
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
        var quotaInputs = Object.keys(o.roles).map(function(r){ var i = input('number', s.quotas[r] != null ? s.quotas[r] : '', { min:'0', placeholder:'default', 'data-role':r }); return field((o.roles[r].name || r) + ' quota (MB)', i); });
        var last = o.last_cleanup ? (function(){ try { var j = typeof o.last_cleanup === 'string' ? JSON.parse(o.last_cleanup) : o.last_cleanup; return j; } catch (e) { return null; } })() : null;
        box.replaceChildren(
          h('div', { className:'vc-stat-row' }, [['Stored', size(o.total_bytes)], ['Free on disk', o.free_bytes != null ? size(o.free_bytes) : '—'], ['Reclaimable', size(o.reclaimable.bytes) + ' · ' + o.reclaimable.items + ' items'], ['Upload piece', size(o.limits.chunk)]].map(function(x){ return h('div', { className:'vc-stat' }, [h('strong', { text:x[1] }), h('span', { className:'muted', text:x[0] })]); })),
          h('div', { className:'vc-cleanup' }, [h('div', null, [h('strong', { text:'Cleanup task' }), h('p', { className:'muted', text:'Expired and used-up items are hidden straight away; this task deletes their files. ' + (last && last.finished_at ? 'Last run ' + ago(Date.parse(last.finished_at) / 1000) + '.' : 'Not run yet.') + ' Schedule it with the operating system (cron / Task Scheduler): php src/Console.php drop-cleanup' })]), btn('Run cleanup now', function(){ call('/drop/admin/cleanup', { method:'POST' }).then(function(){ A.toast('Cleanup started. Follow it under Settings → Maintenance.', { kind:'ok' }); setTimeout(load, 4000); }).catch(err); }, 'primary', { disabled:!o.reclaimable.items ? true : null })]),
          h('form', { className:'vc-form', onSubmit:function(e){ e.preventDefault(); var q = {}; box.querySelectorAll('input[data-role]').forEach(function(i){ if (i.value !== '') q[i.getAttribute('data-role')] = Number(i.value); }); call('/drop/admin/settings', { method:'POST', body:{ enabled:en.checked, user_role_upload:ur.checked, public_links:pub.checked, max_file_mb:Number(maxf.value), default_quota_mb:Number(defq.value), quotas:q } }).then(function(){ A.toast('Drop settings saved', { kind:'ok' }); load(); refresh(); }).catch(err); } }, [
            h('label', { className:'vc-check' }, [en, h('span', { text:'Drop is enabled' })]), h('label', { className:'vc-check' }, [ur, h('span', { text:'Standard users may upload (other roles use the Drop permission; per-user overrides live under Users)' })]), h('label', { className:'vc-check' }, [pub, h('span', { text:'Allow public share links (no sign-in)' })]),
            h('div', { className:'vc-field-row' }, [field('Largest file (MB)', maxf), field('Default quota (MB, 0 = none)', defq)].concat(quotaInputs)), h('button', { type:'submit', className:'btn primary', text:'Save settings' })]),
          o.users.length ? h('table', { className:'vc-table' }, [h('thead', null, [h('tr', null, [h('th', { text:'User' }), h('th', { text:'Items' }), h('th', { text:'Stored' })])]), h('tbody', null, o.users.map(function(u){ return h('tr', null, [h('td', { text:u.user.name }), h('td', { text:String(u.items) }), h('td', { text:size(u.bytes) })]); }))]) : null,
          o.items.length ? h('details', { className:'vc-admin-items' }, [h('summary', { text:'All items (' + o.items.length + ')' }), h('div', { className:'vc-drop-items' }, o.items.map(function(i){ var s2 = STATUS[i.status] || [i.status, 'muted']; return h('div', { className:'vc-drop-item ' + i.status }, [h('span', { className:'vc-drop-icon', text:kindIcon(i) }), h('div', { className:'vc-drop-copy' }, [h('strong', { text:i.name }), h('small', { className:'muted', text:i.owner.name + ' · ' + size(i.bytes) + ' · ' + ago(i.created_at) })]), h('span', { className:'vc-chip ' + s2[1], text:s2[0] }), btn('Delete', function(){ A.confirmDialog('Delete “' + i.name + '”?', { okText:'Delete', danger:true }).then(function(ok){ if (ok) call('/drop/admin/items/' + i.id, { method:'DELETE' }).then(load).catch(err); }); }, 'ghost small danger')]); }))]) : null);
      }).catch(function(e){ box.replaceChildren(empty('Overview unavailable', (e && e.error) || '')); }); }
      load();
      return panel('Administration', [box]);
    }
    call('/drop/state').then(function(j){
      st = j; drawUsage(); drawItems();
      body.replaceChildren(st.can_upload ? composer() : panel('Drop', [h('p', { className:'muted', text:'Your account can receive and manage Drop items but cannot upload. Ask an administrator for the Drop permission.' }), usage]), panel('Your items', [itemsBox], btn('Refresh', refresh, 'ghost small')), isAdmin() ? adminPanel() : null);
      tick = setInterval(function(){ if (!body.isConnected) { clearInterval(tick); return; } if (!document.hidden && st) drawItems(); }, 60000); onCleanup(function(){ clearInterval(tick); });
    }).catch(function(e){ body.replaceChildren(empty('Drop unavailable', (e && e.error) || '')); });
  }

  // ------------------------------------------------------------------ Ambient screen
  var MODES = [['stills', 'Film stills', 'Frames from your library with a slow pan and zoom', '🎞️'], ['posters', 'Poster wall', 'Library artwork drifting by', '🖼️'], ['webcams', 'Webcams', 'Live views from the cameras your admin picked', '📷'], ['clock', 'Clock only', 'A calm, dim clock with the weather', '🕰️']];
  function ambientPrefs(){ return Object.assign({ mode:'stills', clock:true, weather:true, radio:'', seconds:14 }, store('vaultcast-ambient') || {}); }
  var amb = null;
  function startAmbient(opts){
    opts = opts || {};
    if (amb) return;
    var p = Object.assign(ambientPrefs(), opts.mode ? { mode:opts.mode } : {}), layer = h('div', { className:'vc-ambient mode-' + p.mode, role:'dialog', 'aria-label':'Ambient screen. Press any key to exit.' }), stage = h('div', { className:'vc-amb-stage' });
    var overlay = h('div', { className:'vc-amb-overlay' }), clock = h('div', { className:'vc-amb-clock' }), wxEl = h('div', { className:'vc-amb-wx' }), hint = h('div', { className:'vc-amb-hint', text:'Press any key or tap to return' });
    layer.append(stage, overlay, hint); if (p.clock !== false || p.mode === 'clock') overlay.appendChild(clock); if (p.weather !== false && p.loc) overlay.appendChild(wxEl);
    document.body.appendChild(layer); document.body.classList.add('vc-ambient-on');
    var stops = [], since = Date.now();
    amb = { layer:layer };
    function tickClock(){ var d = new Date(); clock.replaceChildren(h('strong', { text:clockText(d, {}) }), h('span', { text:dateText(d) })); }
    tickClock(); var ct = setInterval(tickClock, 1000); stops.push(function(){ clearInterval(ct); });
    if (p.weather !== false && p.loc) { var wl = function(){ weatherData(p.loc).then(function(j){ var w = wx(j.current.code, j.current.day); wxEl.replaceChildren(h('span', { className:'vc-amb-wx-icon', text:w.icon }), h('strong', { text:Math.round(j.current.temp) + j.units }), h('small', { text:w.text + ' · ' + p.loc.name })); }).catch(function(){}); }; wl(); var wt = setInterval(wl, 600000); stops.push(function(){ clearInterval(wt); }); }
    if (p.mode === 'stills' || p.mode === 'posters') { var kb = kenBurns(stage, function(){ return call('/misc/ambient/stills?n=40&kind=' + (p.mode === 'stills' ? 'frames' : 'posters')).then(function(j){ return j.stills.map(function(x){ return { url:url(x.url), title:x.title + (x.year ? ' (' + x.year + ')' : '') }; }); }); }, { interval:(p.seconds || 14) * 1000, captions:true }); stops.push(kb.stop); }
    else if (p.mode === 'webcams') {
      call('/misc/ambient').then(function(cfg){
        var cams = cfg.webcams || [], i = 0, timer = 0, refresh = 0, hls = null;
        if (!cams.length) { stage.appendChild(h('p', { className:'vc-kb-empty', text:'No webcams are set up yet. An administrator can add them on the Ambient page.' })); return; }
        function show(){
          clearTimeout(timer); clearInterval(refresh); if (hls) { try { hls.destroy(); } catch (e) {} hls = null; }
          var c = cams[i % cams.length], el;
          if (c.kind === 'embed') el = h('iframe', { src:c.url, className:'vc-cam', allow:'autoplay; fullscreen', referrerpolicy:'no-referrer', sandbox:'allow-scripts allow-same-origin allow-presentation', title:c.name });
          else if (c.kind === 'video') { el = h('video', { className:'vc-cam', muted:true, autoplay:true, playsinline:true }); el.muted = true; playStream(el, c.url, function(){ return !!amb; }).then(function(s){ hls = s; }).catch(function(){}); }
          else { el = h('img', { className:'vc-cam', alt:c.name, src:c.url + (c.url.indexOf('?') >= 0 ? '&' : '?') + '_t=' + Date.now(), referrerpolicy:'no-referrer' }); refresh = setInterval(function(){ el.src = c.url + (c.url.indexOf('?') >= 0 ? '&' : '?') + '_t=' + Date.now(); }, Math.max(5, c.refresh || 30) * 1000); }
          stage.replaceChildren(el, h('div', { className:'vc-kb-cap', text:c.name }));
          i++; if (cams.length > 1) timer = setTimeout(show, Math.max(20, p.seconds * 3) * 1000);
        }
        show(); stops.push(function(){ clearTimeout(timer); clearInterval(refresh); if (hls) try { hls.destroy(); } catch (e) {} });
      }).catch(function(){});
    }
    var audio = null;
    if (p.radio) {
      (A.loadRadio ? A.loadRadio() : Promise.resolve([])).then(function(list){ var st = list.filter(function(x){ return x.id === p.radio; })[0]; if (!st || !amb) return; audio = h('audio'); audio.volume = 0.6; layer.appendChild(audio); playStream(audio, st.url, function(){ return !!amb; }).then(function(s){ if (s) stops.push(function(){ try { s.destroy(); } catch (e) {} }); }).catch(function(){}); overlay.appendChild(h('div', { className:'vc-amb-radio', text:'♪ ' + st.name })); }).catch(function(){});
      stops.push(function(){ if (audio) { audio.pause(); audio.removeAttribute('src'); } });
    }
    wantWake(1);
    if (!opts.idle && layer.requestFullscreen) layer.requestFullscreen().catch(function(){});
    function exit(e){
      if (!amb) return;
      if (e && e.type === 'pointermove' && Date.now() - since < 1200) return;
      if (e && e.type === 'pointermove' && Math.abs(e.movementX || 0) + Math.abs(e.movementY || 0) < 3) return;
      if (e && e.type !== 'pointermove') { e.preventDefault && e.preventDefault(); e.stopPropagation && e.stopPropagation(); }
      ['keydown', 'pointerdown', 'pointermove', 'wheel', 'touchstart'].forEach(function(ev){ window.removeEventListener(ev, exit, true); });
      stops.forEach(function(f){ try { f(); } catch (x) {} });
      wantWake(-1); amb = null; document.body.classList.remove('vc-ambient-on');
      if (document.fullscreenElement === layer && document.exitFullscreen) document.exitFullscreen().catch(function(){});
      layer.classList.add('leaving'); setTimeout(function(){ layer.remove(); }, 350);
    }
    setTimeout(function(){ ['keydown', 'pointerdown', 'pointermove', 'wheel', 'touchstart'].forEach(function(ev){ window.addEventListener(ev, exit, { capture:true, passive:false }); }); }, 400);
    document.addEventListener('fullscreenchange', function fsc(){ if (!document.fullscreenElement && amb && amb.layer === layer && amb.wasFull) exit(); else if (document.fullscreenElement === layer && amb) amb.wasFull = true; if (!amb) document.removeEventListener('fullscreenchange', fsc); });
  }
  function ambient(host){
    host.appendChild(hero('Ambient', 'Turn any screen into a living picture frame. It can also start on its own when nobody has touched the screen for a while.', [btn('Start now', function(){ startAmbient({}); }, 'primary')], 'MISC'));
    var p = ambientPrefs(), body = h('div', { className:'vc-ambient-page' }); host.appendChild(body);
    function save(){ store('vaultcast-ambient', p); }
    var modes = h('div', { className:'vc-mode-grid', role:'radiogroup', 'aria-label':'Ambient mode' }, MODES.map(function(m){ return h('button', { type:'button', role:'radio', 'aria-checked':String(p.mode === m[0]), className:'vc-mode-card' + (p.mode === m[0] ? ' active' : ''), onClick:function(){ p.mode = m[0]; save(); modes.querySelectorAll('.vc-mode-card').forEach(function(c){ c.classList.toggle('active', c === this); c.setAttribute('aria-checked', String(c === this)); }, this); }, onDblclick:function(){ startAmbient({ mode:m[0] }); } }, [h('span', { className:'vc-mode-icon', text:m[3], 'aria-hidden':'true' }), h('strong', { text:m[1] }), h('small', { className:'muted', text:m[2] })]); }));
    var clockC = input('checkbox', p.clock !== false, { onChange:function(){ p.clock = this.checked; save(); } }), wxC = input('checkbox', p.weather !== false, { onChange:function(){ p.weather = this.checked; save(); } });
    var secs = input('number', p.seconds || 14, { min:'5', max:'120', onChange:function(){ p.seconds = Math.max(5, Math.min(120, Number(this.value) || 14)); save(); } });
    var radioSel = select([['', 'No radio']], p.radio || '', { onChange:function(){ p.radio = this.value; save(); } });
    (A.loadRadio ? A.loadRadio() : Promise.resolve([])).then(function(list){ list.forEach(function(st){ radioSel.appendChild(h('option', { value:st.id, text:st.name })); }); radioSel.value = p.radio || ''; }).catch(function(){});
    var own = null; try { own = localStorage.getItem('vaultcast-ambient-idle'); } catch (e) {}
    var idleSel = select([['', 'Use the server default'], ['0', 'Never on this device'], ['2', 'After 2 minutes'], ['5', 'After 5 minutes'], ['10', 'After 10 minutes'], ['15', 'After 15 minutes'], ['30', 'After 30 minutes'], ['60', 'After 1 hour']], own == null ? '' : own, { onChange:function(){ try { if (this.value === '') localStorage.removeItem('vaultcast-ambient-idle'); else localStorage.setItem('vaultcast-ambient-idle', this.value); } catch (e) {} } });
    var serverIdle = h('small', { className:'muted' });
    body.append(panel('Mode', [modes]), panel('Overlay & sound', [h('div', { className:'vc-form' }, [h('label', { className:'vc-check' }, [clockC, h('span', { text:'Show the clock' })]), h('label', { className:'vc-check' }, [wxC, h('span', { text:'Show the weather' })]), field('Weather location', locationPicker(p.loc, function(l){ p.loc = l; save(); })), field('Seconds per picture', secs), field('Radio while ambient runs', radioSel)])]),
      panel('Start automatically', [h('div', { className:'vc-form' }, [field('On this device', idleSel), serverIdle, h('p', { className:'muted', text:'Ambient waits while video or audio is playing. Any key, tap or mouse movement returns you to where you were.' })])]));
    call('/misc/ambient').then(function(cfg){
      serverIdle.textContent = cfg.idle_minutes ? 'Server default: after ' + cfg.idle_minutes + ' minutes.' : 'Server default: off.';
      if (!isAdmin()) return;
      var idle = input('number', cfg.idle_minutes || 0, { min:'0', max:'240' }), radio = select([['', 'None']], cfg.radio || ''), cams = (cfg.webcams || []).map(function(c){ return Object.assign({}, c); }), camBox = h('div', { className:'vc-cam-edit' });
      (A.loadRadio ? A.loadRadio() : Promise.resolve([])).then(function(list){ list.forEach(function(st){ radio.appendChild(h('option', { value:st.id, text:st.name })); }); radio.value = cfg.radio || ''; }).catch(function(){});
      function drawCams(){ camBox.replaceChildren.apply(camBox, cams.map(function(c, i){ function bind(el, k, num){ el.addEventListener('input', function(){ c[k] = num ? Number(el.value) : el.value; }); el.addEventListener('change', function(){ c[k] = num ? Number(el.value) : el.value; }); return el; }
        return h('fieldset', { className:'vc-rem-row' }, [bind(input('text', c.name, { placeholder:'Name', 'aria-label':'Webcam name' }), 'name'), bind(input('url', c.url, { placeholder:'https://…', 'aria-label':'Webcam address' }), 'url'), bind(select([['image', 'Still image (refreshes)'], ['video', 'Video / HLS stream'], ['embed', 'Embedded page']], c.kind || 'image', { 'aria-label':'Type' }), 'kind'), bind(input('number', c.refresh || 30, { min:'5', max:'3600', 'aria-label':'Refresh seconds', title:'Refresh every N seconds (images)' }), 'refresh', true), btn('×', function(){ cams.splice(i, 1); drawCams(); }, 'ghost small', { 'aria-label':'Remove webcam' })]); }).concat([btn('Add webcam', function(){ cams.push({ name:'', url:'', kind:'image', refresh:30 }); drawCams(); }, 'ghost')])); }
      drawCams();
      body.appendChild(panel('Administration', [h('form', { className:'vc-form', onSubmit:function(e){ e.preventDefault(); call('/misc/admin/ambient', { method:'POST', body:{ idle_minutes:Number(idle.value), radio:radio.value, webcams:cams.filter(function(c){ return c.url; }) } }).then(function(){ A.toast('Ambient settings saved', { kind:'ok' }); }).catch(err); } }, [field('Start after N idle minutes for everyone (0 = off)', idle), field('Suggested radio station', radio), h('div', { className:'vc-field' }, [h('span', { text:'Curated webcams' }), h('small', { className:'muted', text:'Public camera images, HLS streams or embeddable pages. Check each source allows this use.' }), camBox]), h('button', { type:'submit', className:'btn primary', text:'Save' })])]));
    }).catch(function(){});
  }

  // ------------------------------------------------------------------ module API
  root.VaultcastMisc = {
    init:function(app){ A = app; h = app.h; api = app.api; },
    render:function(host, tab, sub){
      stopAll(); host.replaceChildren(); host.classList.add('vc-misc');
      if (tab === 'drop') return drop(host, sub);
      if (tab === 'ambient') return ambient(host, sub);
      return board(host, sub);
    },
    startAmbient:startAmbient,
    stop:stopAll,
    upload:uploadFile
  };
})(this);
