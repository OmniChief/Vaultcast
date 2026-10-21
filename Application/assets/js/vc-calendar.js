/* Vaultcast Misc → Calendar page, Board calendar + reminders widgets, reminder templates and alerts (loaded on demand). */
(function(root){
  'use strict';
  var A, h, api;
  var TZ = (function(){ try { return Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { return ''; } })();
  function btn(label, onClick, cls, attrs){ return h('button', Object.assign({ type:'button', className:'btn ' + (cls || ''), text:label, onClick:onClick }, attrs || {})); }
  function call(path, opts){ return api(path, opts).then(function(j){ if (!j || !j.ok) throw j || {}; return j; }); }
  function err(e){ A.toast((e && (e.error || e.message)) || 'Something went wrong', { kind:'bad' }); }
  function field(label, el, hint){ return h('label', { className:'vc-field' }, [h('span', { text:label }), el, hint ? h('small', { className:'muted', text:hint }) : null]); }
  function input(type, value, attrs){ var i = h('input', Object.assign({ type:type || 'text' }, attrs || {})); if (type === 'checkbox') i.checked = !!value; else if (value != null) i.value = value; return i; }
  function select(options, value, attrs){ var s = h('select', attrs || {}, options.map(function(o){ return h('option', { value:o[0], text:o[1] }); })); s.value = value == null ? '' : String(value); return s; }
  function modal(title, build){ return window.VaultcastHub.modal(title, build); }
  function empty(t, d, actions){ return A.emptyState ? A.emptyState(t, d, actions) : h('p', { className:'muted', text:t }); }
  function signed(){ var u = A.state.user; return !!(u && u.id && u.role !== 'guest'); }
  function pad(n){ return (n < 10 ? '0' : '') + n; }
  function ymd(d){ return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parseYmd(s){ var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || ''); return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null; }
  function day0(d){ return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }
  function addDays(d, n){ return new Date(d.getFullYear(), d.getMonth(), d.getDate() + n); }
  function monday(d){ return addDays(day0(d), -((d.getDay() + 6) % 7)); }
  function hm(d){ return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
  function tFmt(d){ return d.toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' }); }
  function sec(d){ return Math.floor(d.getTime() / 1000); }
  var DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], DAYS_RRULE = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];
  var COLOURS = ['#59e9ff', '#a7a0ff', '#7ee2a8', '#f5dc7a', '#f2a097', '#ff8fd0', '#ffb36b', '#8fb8ff'];

  // ------------------------------------------------------------------ reminder templates
  function wk(days, every){ return { type:'weekly', every:every || 1, days:days }; }
  var T = function(k, g, emoji, title, sched, extra){ return Object.assign({ key:k, group:g, emoji:emoji, title:title, schedule:sched, time:'09:00', lead:0, colour:'#59e9ff' }, extra || {}); };
  var TEMPLATES = [
    T('bin-general', 'Bins', '🗑️', 'General waste bin', wk([2], 2), { time:'07:00', lead:900, colour:'#9aa5b1', note:'Put it out the night before' }),
    T('bin-recycling', 'Bins', '♻️', 'Recycling bin', wk([2], 2), { time:'07:00', lead:900, colour:'#5aa9e6', note:'Put it out the night before' }),
    T('bin-garden', 'Bins', '🌿', 'Garden waste bin', wk([3], 2), { time:'07:00', lead:900, colour:'#7ee2a8' }),
    T('bin-food', 'Bins', '🍎', 'Food waste caddy', wk([2], 1), { time:'07:00', lead:900, colour:'#c8a165' }),
    T('bin-glass', 'Bins', '🍾', 'Glass recycling', { type:'weekly', every:4, days:[4] }, { time:'07:00', lead:900, colour:'#3fbf9f' }),
    T('rent', 'Money', '🏠', 'Pay rent / mortgage', { type:'monthly', monthly:'day', day:1 }, { lead:2880, colour:'#ffb36b' }),
    T('council-tax', 'Money', '🏛️', 'Council tax', { type:'monthly', monthly:'day', day:1 }, { lead:1440, colour:'#ffb36b' }),
    T('bill-energy', 'Money', '⚡', 'Energy bill', { type:'monthly', monthly:'day', day:15 }, { lead:1440, colour:'#f5dc7a' }),
    T('bill-water', 'Money', '🚰', 'Water bill', { type:'monthly', monthly:'day', day:15 }, { lead:1440, colour:'#5aa9e6' }),
    T('bill-phone', 'Money', '📱', 'Phone bill', { type:'monthly', monthly:'day', day:20 }, { lead:1440, colour:'#a7a0ff' }),
    T('bill-broadband', 'Money', '🌐', 'Broadband bill', { type:'monthly', monthly:'day', day:20 }, { lead:1440, colour:'#8fb8ff' }),
    T('payday', 'Money', '💷', 'Payday', { type:'monthly', monthly:'day', day:28 }, { colour:'#7ee2a8' }),
    T('budget', 'Money', '📊', 'Budget review', { type:'monthly', monthly:'nth', nth:1, weekday:0 }, { time:'18:00', colour:'#7ee2a8' }),
    T('subscriptions', 'Money', '🔁', 'Check subscription renewals', { type:'monthly', monthly:'day', day:25 }, { colour:'#ff8fd0' }),
    T('tv-licence', 'Money', '📺', 'TV licence renewal', { type:'yearly' }, { lead:20160, colour:'#f2a097' }),
    T('medication', 'Health', '💊', 'Take medication', { type:'daily', every:1 }, { time:'08:00', colour:'#ff8fd0' }),
    T('toothbrush', 'Health', '🪥', 'Change toothbrush', { type:'monthly', monthly:'day', day:1, every:3 }, { colour:'#59e9ff' }),
    T('dentist', 'Health', '🦷', 'Dentist check-up', { type:'monthly', monthly:'day', day:1, every:6 }, { lead:10080, colour:'#59e9ff' }),
    T('doctor', 'Health', '🩺', 'Doctor check-up', { type:'yearly' }, { lead:10080, colour:'#f2a097' }),
    T('eye-test', 'Health', '👓', 'Eye test', { type:'yearly', every:2 }, { lead:10080, colour:'#a7a0ff' }),
    T('plants', 'Home', '🪴', 'Water the plants', wk([3, 6]), { time:'18:00', colour:'#7ee2a8' }),
    T('bedding', 'Home', '🛏️', 'Change the bedding', wk([6]), { time:'10:00', colour:'#8fb8ff' }),
    T('fridge', 'Home', '🧊', 'Clean the fridge', { type:'monthly', monthly:'nth', nth:1, weekday:6 }, { time:'10:00', colour:'#59e9ff' }),
    T('hoover', 'Home', '🧹', 'Hoover', wk([6]), { time:'10:00', colour:'#c8a165' }),
    T('laundry', 'Home', '🧺', 'Laundry', wk([1, 4]), { time:'18:00', colour:'#a7a0ff' }),
    T('boiler', 'Home', '🔥', 'Boiler service', { type:'yearly' }, { lead:20160, colour:'#ffb36b' }),
    T('smoke-alarm', 'Home', '🚨', 'Test the smoke alarms', { type:'monthly', monthly:'nth', nth:1, weekday:1 }, { time:'19:00', colour:'#f2a097' }),
    T('gutters', 'Home', '🏚️', 'Clean the gutters', { type:'yearly' }, { colour:'#9aa5b1' }),
    T('freezer', 'Home', '❄️', 'Defrost the freezer', { type:'monthly', monthly:'day', day:1, every:6 }, { colour:'#8fb8ff' }),
    T('home-insurance', 'Home', '🛡️', 'Home insurance renewal', { type:'yearly' }, { lead:30240, colour:'#ffb36b' }),
    T('clocks-forward', 'Home', '⏩', 'Clocks go forward', { type:'monthly', monthly:'nth', nth:-1, weekday:0, every:12 }, { time:'20:00', lead:1440, colour:'#f5dc7a', note:'Last Sunday in March' }),
    T('clocks-back', 'Home', '⏪', 'Clocks go back', { type:'monthly', monthly:'nth', nth:-1, weekday:0, every:12 }, { time:'20:00', lead:1440, colour:'#f5dc7a', note:'Last Sunday in October' }),
    T('feed-pets', 'Pets', '🐾', 'Feed the pets', { type:'daily', every:1 }, { time:'07:30', colour:'#ffb36b' }),
    T('walk-dog', 'Pets', '🐕', 'Walk the dog', { type:'daily', every:1 }, { time:'08:00', colour:'#c8a165' }),
    T('flea', 'Pets', '🐛', 'Flea treatment', { type:'monthly', monthly:'day', day:1 }, { colour:'#7ee2a8' }),
    T('worming', 'Pets', '💉', 'Worming treatment', { type:'monthly', monthly:'day', day:1, every:3 }, { colour:'#7ee2a8' }),
    T('vet', 'Pets', '🏥', 'Vet check-up', { type:'yearly' }, { lead:10080, colour:'#f2a097' }),
    T('mot', 'Car', '🚗', 'Car MOT', { type:'yearly' }, { lead:30240, colour:'#f2a097' }),
    T('car-service', 'Car', '🔧', 'Car service', { type:'yearly' }, { lead:20160, colour:'#ffb36b' }),
    T('car-tax', 'Car', '🧾', 'Car tax', { type:'yearly' }, { lead:20160, colour:'#f5dc7a' }),
    T('car-insurance', 'Car', '🛡️', 'Car insurance renewal', { type:'yearly' }, { lead:30240, colour:'#a7a0ff' }),
    T('tyres', 'Car', '🛞', 'Check tyre pressure', { type:'monthly', monthly:'nth', nth:1, weekday:6 }, { time:'10:00', colour:'#9aa5b1' }),
    T('birthday', 'People', '🎂', 'Birthday', { type:'yearly' }, { lead:10080, colour:'#ff8fd0', time:'' }),
    T('anniversary', 'People', '💍', 'Anniversary', { type:'yearly' }, { lead:10080, colour:'#ff8fd0', time:'' }),
    T('school-run', 'Family', '🎒', 'School run', wk([1, 2, 3, 4, 5]), { time:'08:15', colour:'#8fb8ff' }),
    T('pe-kit', 'Family', '👟', 'PE kit', wk([2]), { time:'07:30', lead:900, colour:'#7ee2a8', note:'Pack it the night before' }),
    T('backup-nas', 'Tech', '💾', 'Back up the NAS', wk([0]), { time:'20:00', colour:'#59e9ff' }),
    T('update-apps', 'Tech', '⬆️', 'Update apps', { type:'monthly', monthly:'nth', nth:1, weekday:6 }, { time:'11:00', colour:'#a7a0ff' })
  ];
  var EMOJI = '🗑️ bin rubbish trash|♻️ recycle|🌿 garden green|🍎 food apple|🍾 glass bottle|🏠 home house rent|🏛️ council tax|⚡ energy electric|🚰 water|📱 phone mobile|🌐 internet broadband|💷 money pay|💳 card bill|📊 budget chart|🔁 repeat subscription|📺 tv licence|💊 pill medication|🪥 toothbrush|🦷 tooth dentist|🩺 doctor|👓 glasses eye|🪴 plant|🛏️ bed|🧊 fridge ice|🧹 broom hoover clean|🧺 laundry basket|🔥 boiler fire|🚨 alarm|❄️ freezer snow|🛡️ insurance shield|⏩ forward|⏪ back|🐾 pet paw|🐕 dog|🐈 cat|🐛 flea bug|💉 vaccine|🏥 hospital vet|🚗 car|🔧 spanner service|🧾 receipt tax|🛞 tyre wheel|🎂 birthday cake|🎁 gift present|💍 ring anniversary|🎒 school bag|👟 shoe pe|💾 backup disk|⬆️ update|🔔 bell reminder|⏰ alarm clock|📅 calendar|📌 pin|⭐ star|❤️ heart love|🎉 party|✈️ travel flight|🚆 train|🚌 bus|🏋️ gym|🧘 yoga|🏃 run|🚴 bike|🍽️ dinner|☕ coffee|🍺 beer|🛒 shopping|📦 parcel delivery|✉️ letter post|📞 call|💻 computer|🖨️ printer|🔑 key|🧴 lotion|💇 haircut|🧽 sponge|🚿 shower|🛁 bath|🌱 seed|🌻 flower|🍼 baby|👶 child|📚 book library|🎵 music|🎮 game|⚽ football|🎾 tennis|🗓️ date|✅ done check'.split('|').map(function(x){ var i = x.indexOf(' '); return [x.slice(0, i), x.slice(i + 1)]; });

  function emojiPicker(current, onPick){
    var q = input('search', '', { placeholder:'Search emoji', 'aria-label':'Search emoji' }), grid = h('div', { className:'vc-emoji-grid', role:'listbox' }), own = input('text', current || '', { maxlength:'8', className:'vc-emoji-input', 'aria-label':'Or type any emoji' });
    function draw(){ var f = q.value.trim().toLowerCase(); grid.replaceChildren.apply(grid, EMOJI.filter(function(e){ return !f || e[1].indexOf(f) >= 0; }).map(function(e){ return h('button', { type:'button', role:'option', className:'vc-emoji' + (e[0] === own.value ? ' on' : ''), text:e[0], title:e[1], 'aria-label':e[1], onClick:function(){ own.value = e[0]; onPick(e[0]); draw(); } }); })); }
    q.addEventListener('input', draw); own.addEventListener('input', function(){ if (own.value.trim()) onPick(own.value.trim()); });
    draw();
    return h('div', { className:'vc-emoji-picker' }, [h('div', { className:'vc-field-row' }, [q, own]), grid]);
  }
  function ordinal(n){ return n === -1 ? 'last' : ['first', 'second', 'third', 'fourth', 'fifth'][n - 1] || n + 'th'; }
  function describe(s){
    if (!s) return '';
    var every = Math.max(1, s.every || 1);
    if (s.type === 'once') return 'Once on ' + (parseYmd(s.start) || new Date()).toLocaleDateString([], { day:'numeric', month:'short', year:'numeric' });
    if (s.type === 'daily') return every === 1 ? 'Every day' : 'Every ' + every + ' days';
    if (s.type === 'weekly') { var d = (s.days || []).map(function(x){ return DOW[x]; }).join(', '); return (every === 1 ? 'Every week' : every === 2 ? 'Every fortnight' : 'Every ' + every + ' weeks') + (d ? ' on ' + d : ''); }
    if (s.type === 'monthly') return (every === 1 ? 'Monthly' : 'Every ' + every + ' months') + (s.monthly === 'nth' ? ' on the ' + ordinal(s.nth) + ' ' + DOW[s.weekday] : ' on day ' + (s.day || 1));
    if (s.type === 'yearly') { var y = parseYmd(s.start); return (every === 1 ? 'Every year' : 'Every ' + every + ' years') + (y ? ' on ' + y.toLocaleDateString([], { day:'numeric', month:'long' }) : ''); }
    return '';
  }
  function scheduleEditor(s){
    s = JSON.parse(JSON.stringify(s || { type:'weekly', every:1, days:[new Date().getDay()] }));
    if (!s.start) s.start = ymd(new Date());
    var box = h('div', { className:'vc-sched' }), summary = h('p', { className:'vc-sched-sum muted', 'aria-live':'polite' });
    function draw(){
      var type = select([['once', 'Once'], ['daily', 'Daily'], ['weekly', 'Weekly'], ['monthly', 'Monthly'], ['yearly', 'Yearly']], s.type, { onChange:function(){ s.type = this.value; if (s.type === 'weekly' && !(s.days || []).length) s.days = [(parseYmd(s.start) || new Date()).getDay()]; if (s.type === 'monthly' && !s.monthly) { s.monthly = 'day'; s.day = (parseYmd(s.start) || new Date()).getDate(); } draw(); } });
      var start = input('date', s.start, { onChange:function(){ s.start = this.value || ymd(new Date()); upd(); } });
      var rows = [h('div', { className:'vc-field-row' }, [field('Repeats', type), field(s.type === 'once' ? 'Date' : s.type === 'yearly' ? 'Date (day and month repeat)' : 'Starting', start)])];
      if (s.type !== 'once') {
        var unit = { daily:'days', weekly:'weeks', monthly:'months', yearly:'years' }[s.type];
        rows.push(field('Every', h('span', { className:'vc-inline' }, [input('number', s.every || 1, { min:'1', max:'52', className:'vc-num', onInput:function(){ s.every = Math.max(1, Math.min(52, Number(this.value) || 1)); upd(); } }), h('span', { text:unit })]), s.type === 'weekly' ? 'Use 2 for fortnightly bins; set Starting to a known collection day.' : null));
      }
      if (s.type === 'weekly') rows.push(h('div', { className:'vc-day-pick', role:'group', 'aria-label':'Days' }, [1, 2, 3, 4, 5, 6, 0].map(function(d){ var on = (s.days || []).indexOf(d) >= 0; return h('button', { type:'button', className:'vc-day' + (on ? ' on' : ''), 'aria-pressed':String(on), text:DOW[d], onClick:function(){ var a = s.days || []; var i = a.indexOf(d); if (i >= 0) { if (a.length > 1) a.splice(i, 1); } else a.push(d); s.days = a; draw(); } }); })));
      if (s.type === 'monthly') {
        var how = select([['day', 'On a day of the month'], ['nth', 'On a weekday (e.g. first Monday)']], s.monthly || 'day', { onChange:function(){ s.monthly = this.value; if (s.monthly === 'nth' && !s.nth) { s.nth = 1; s.weekday = 1; } draw(); } });
        rows.push(field('Monthly', how));
        if ((s.monthly || 'day') === 'day') rows.push(field('Day', input('number', s.day || 1, { min:'1', max:'31', className:'vc-num', onInput:function(){ s.day = Math.max(1, Math.min(31, Number(this.value) || 1)); upd(); } }), 'Short months use their last day.'));
        else rows.push(h('div', { className:'vc-field-row' }, [field('Which', select([['1', 'First'], ['2', 'Second'], ['3', 'Third'], ['4', 'Fourth'], ['-1', 'Last']], String(s.nth || 1), { onChange:function(){ s.nth = Number(this.value); upd(); } })), field('Weekday', select([1, 2, 3, 4, 5, 6, 0].map(function(d){ return [String(d), DOW[d]]; }), String(s.weekday == null ? 1 : s.weekday), { onChange:function(){ s.weekday = Number(this.value); upd(); } }))]));
      }
      box.replaceChildren.apply(box, rows.concat([summary])); upd();
    }
    function upd(){ summary.textContent = describe(s); }
    draw();
    return { el:box, read:function(){ var o = { type:s.type, start:s.start }; if (s.type !== 'once') o.every = s.every || 1; if (s.type === 'weekly') o.days = s.days; if (s.type === 'monthly') { o.monthly = s.monthly || 'day'; if (o.monthly === 'nth') { o.nth = s.nth || 1; o.weekday = s.weekday == null ? 1 : s.weekday; } else o.day = s.day || 1; } return o; } };
  }
  var LEADS = [['0', 'No heads-up'], ['60', '1 hour before'], ['180', '3 hours before'], ['900', 'The evening before (15 h)'], ['1440', '1 day before'], ['2880', '2 days before'], ['10080', '1 week before'], ['20160', '2 weeks before'], ['30240', '3 weeks before']];
  /** Create or edit a reminder. r = existing reminder, or a template to start from. Resolves after saving. */
  function editReminder(r, isNew){
    return new Promise(function(resolve){
      r = Object.assign({ title:'', emoji:'🔔', colour:'#59e9ff', note:'', schedule:null, time:'09:00', lead:0, calendar:false }, r || {});
      if (isNew && r.schedule && !r.schedule.start) { r.schedule = Object.assign({}, r.schedule, { start:ymd(new Date()) }); if (r.schedule.type === 'monthly' && r.schedule.monthly === 'nth' && r.key === 'clocks-forward') r.schedule.start = new Date().getFullYear() + '-03-01'; if (r.key === 'clocks-back') r.schedule.start = new Date().getFullYear() + '-10-01'; }
      modal(isNew ? 'New reminder' : 'Edit reminder', function(b, close){
        var emoji = r.emoji, preview = h('span', { className:'vc-rem-icon big', text:emoji });
        var title = input('text', r.title, { maxlength:'120', placeholder:'e.g. Recycling', required:true }), note = input('text', r.note, { maxlength:'200', placeholder:'Optional note' });
        var colour = input('color', r.colour), sched = scheduleEditor(r.schedule), time = input('time', r.time || ''), lead = select(LEADS, String(r.lead || 0)), cal = input('checkbox', !!r.calendar);
        var pickBox = h('details', { className:'vc-emoji-details' }, [h('summary', { text:'Choose an icon' }), emojiPicker(emoji, function(e){ emoji = e; preview.textContent = e; })]);
        var form = h('form', { className:'vc-form', onSubmit:function(e){
          e.preventDefault();
          var body = { title:title.value, emoji:emoji, colour:colour.value, note:note.value, template:r.key || r.template || '', schedule:sched.read(), time:time.value, lead:Number(lead.value), tz:TZ, calendar:cal.checked };
          call(isNew ? '/misc/reminders' : '/misc/reminders/' + r.id, { method:'POST', body:body }).then(function(j){ close(); A.toast(isNew ? 'Reminder added' : 'Reminder saved', { kind:'ok' }); resolve(j); }).catch(err);
        } }, [h('div', { className:'vc-rem-head' }, [preview, field('Title', title)]), pickBox, sched.el, h('div', { className:'vc-field-row' }, [field('Time (optional)', time, 'Leave empty for all day'), field('Heads-up', lead), field('Colour', colour)]), field('Note', note), h('label', { className:'vc-check' }, [cal, h('span', { text:'Show on my calendar' })]),
          h('div', { className:'vc-form-actions' }, [!isNew ? btn('Delete', function(){ A.confirmDialog('Delete “' + r.title + '”?', { okText:'Delete', danger:true }).then(function(ok){ if (ok) call('/misc/reminders/' + r.id, { method:'DELETE' }).then(function(j){ close(); resolve(j); }).catch(err); }); }, 'ghost danger') : null, h('button', { type:'submit', className:'btn primary', text:isNew ? 'Add reminder' : 'Save' })])]);
        b.appendChild(form); setTimeout(function(){ title.focus(); }, 30);
      });
    });
  }
  function chooseTemplate(){
    return new Promise(function(resolve){
      modal('Add a reminder', function(b, close){
        var q = input('search', '', { placeholder:'Search templates (bins, MOT, birthday…)', 'aria-label':'Search templates' }), list = h('div', { className:'vc-tpl-list' });
        function draw(){
          var f = q.value.trim().toLowerCase(), groups = {};
          TEMPLATES.forEach(function(t){ if (f && (t.title + ' ' + t.group).toLowerCase().indexOf(f) < 0) return; (groups[t.group] = groups[t.group] || []).push(t); });
          var kids = [h('button', { type:'button', className:'vc-tpl custom', onClick:function(){ close(); editReminder({}, true).then(resolve); } }, [h('span', { className:'vc-tpl-icon', text:'✨' }), h('span', null, [h('strong', { text:'Custom reminder' }), h('small', { className:'muted', text:'Your own title, icon and schedule' })])])];
          Object.keys(groups).forEach(function(g){ kids.push(h('h3', { className:'vc-tpl-group', text:g })); groups[g].forEach(function(t){ kids.push(h('button', { type:'button', className:'vc-tpl', onClick:function(){ close(); editReminder(JSON.parse(JSON.stringify(t)), true).then(resolve); } }, [h('span', { className:'vc-tpl-icon', text:t.emoji }), h('span', null, [h('strong', { text:t.title }), h('small', { className:'muted', text:describe(Object.assign({ start:ymd(new Date()) }, t.schedule)) })])])); }); });
          list.replaceChildren.apply(list, kids);
        }
        q.addEventListener('input', draw); draw(); b.append(q, list); setTimeout(function(){ q.focus(); }, 30);
      });
    });
  }
  function whenText(r){
    if (!r.due) return r.active ? '' : 'Finished';
    if (r.state === 'snoozed') return 'Snoozed until ' + tFmt(new Date(r.snooze_until * 1000));
    var d = parseYmd(r.due), today = day0(new Date()), n = Math.round((d - today) / 86400000), t = r.time ? ' · ' + tFmt(new Date(r.due_at * 1000)) : '';
    if (n < 0) return 'Overdue · ' + d.toLocaleDateString([], { weekday:'short', day:'numeric', month:'short' });
    if (n === 0) return 'Today' + t; if (n === 1) return 'Tomorrow' + t;
    if (n < 7) return d.toLocaleDateString([], { weekday:'long' }) + t;
    return d.toLocaleDateString([], { day:'numeric', month:'short' }) + (d.getFullYear() !== today.getFullYear() ? ' ' + d.getFullYear() : '') + t;
  }
  function snoozeMenu(r, done){
    modal('Snooze “' + r.title + '”', function(b, close){
      var tomorrow9 = new Date(); tomorrow9.setDate(tomorrow9.getDate() + 1); tomorrow9.setHours(9, 0, 0, 0);
      var opts = [['15 minutes', 15], ['1 hour', 60], ['3 hours', 180], ['Tomorrow morning', Math.max(15, Math.round((tomorrow9 - Date.now()) / 60000))], ['2 days', 2880], ['1 week', 10080]];
      b.appendChild(h('div', { className:'vc-snooze-list' }, opts.map(function(o){ return btn(o[0], function(){ close(); call('/misc/reminders/' + r.id + '/snooze', { method:'POST', body:{ minutes:o[1] } }).then(done).catch(err); }, 'ghost'); })));
    });
  }
  /** Reminder rows with done / snooze / edit; used by the widget and the Calendar page. */
  function reminderRows(list, refresh, opts){
    opts = opts || {};
    return list.map(function(r){
      var soon = ['overdue', 'today', 'soon', 'tomorrow'].indexOf(r.state) >= 0;
      var sub = r.state === 'tomorrow' && r.lead >= 600 && r.note ? r.note : (r.note || describe(r.schedule));
      return h('li', { className:'vc-rem ' + r.state + (soon ? ' due-soon' : ''), style:'--vc-rem:' + r.colour }, [
        h('span', { className:'vc-rem-icon', text:r.emoji, 'aria-hidden':'true' }),
        h('button', { type:'button', className:'vc-rem-title', title:'Edit', onClick:function(){ editReminder(r, false).then(refresh); } }, [h('strong', { text:r.title }), h('small', { className:'muted', text:sub })]),
        h('span', { className:'vc-rem-when', text:whenText(r) }),
        r.due ? h('span', { className:'vc-rem-actions' }, [
          btn('✓', function(){ var before = r; call('/misc/reminders/' + r.id + '/done', { method:'POST' }).then(function(j){ refresh(j); if (opts.onDone) opts.onDone(before); }).catch(err); }, 'ghost vc-icon-btn small', { 'aria-label':'Done: ' + r.title, title:'Done' }),
          btn('💤', function(){ snoozeMenu(r, refresh); }, 'ghost vc-icon-btn small', { 'aria-label':'Snooze ' + r.title, title:'Snooze' })]) : null]);
    });
  }
  function remindersWidget(body, s, ctx){
    var listEl = h('ul', { className:'vc-w-list vc-rems' }), undoBar = h('div', { className:'vc-undo', hidden:true }), gone = false, undoT = 0, stop = null;
    if (!signed()) { body.appendChild(h('p', { className:'muted', text:'Sign in to keep reminders.' })); return; }
    body.append(undoBar, listEl, h('div', { className:'vc-rem-foot' }, [btn('+ Reminder', function(){ chooseTemplate().then(draw); }, 'ghost small'), btn('All reminders', function(){ A.state.miscTab = 'calendar'; A.state.miscSub = 'reminders'; A.pushNav(); A.render(); }, 'ghost small')]));
    function showUndo(r){ clearTimeout(undoT); undoBar.hidden = false; undoBar.replaceChildren(h('span', { text:'Done: ' + r.title }), btn('Undo', function(){ var prev = r.due ? ymd(addDays(parseYmd(r.due), -1)) : ''; call('/misc/reminders/' + r.id + '/undo', { method:'POST', body:{ through:prev } }).then(draw).catch(err); undoBar.hidden = true; }, 'ghost small')); undoT = setTimeout(function(){ undoBar.hidden = true; }, 8000); }
    function draw(j){
      if (gone) return;
      if (!j || !j.reminders) { call('/misc/reminders').then(draw).catch(function(e){ listEl.replaceChildren(h('li', { className:'muted', text:(e && e.error) || 'Reminders unavailable.' })); }); return; }
      var days = Number(s.days) || 0, horizon = days ? ymd(addDays(new Date(), days)) : null;
      var list = j.reminders.filter(function(r){ return r.due && (!horizon || r.due <= horizon || r.state === 'overdue'); }).slice(0, Math.max(1, Number(s.limit) || 8));
      if (!list.length) { listEl.replaceChildren(h('li', { className:'vc-rem-empty muted', text:j.reminders.length ? 'Nothing due soon.' : 'No reminders yet. Add bins, bills, medication and more.' })); return; }
      listEl.replaceChildren.apply(listEl, reminderRows(list, draw, { onDone:showUndo }));
    }
    // Old Bin day widgets kept their reminders in the widget settings: move them to your account once.
    if (s.items && s.items.length && !s.migrated) call('/misc/reminders/import', { method:'POST', body:{ items:s.items, tz:TZ } }).then(function(j){ if (ctx && ctx.save) ctx.save(Object.assign({}, s, { items:[], migrated:true }), true); draw(j); }).catch(function(){ draw(); });
    else draw();
    var timer = setInterval(function(){ if (!document.hidden) draw(); }, 300000);
    startAlerts();
    return function(){ gone = true; clearInterval(timer); clearTimeout(undoT); if (stop) stop(); };
  }
  function remindersForm(s){
    var limit = input('number', s.limit || 8, { min:'1', max:'30' }), days = select([['0', 'Everything upcoming'], ['1', 'Due today or tomorrow'], ['7', 'The next 7 days'], ['30', 'The next 30 days']], String(s.days || 0));
    return { el:h('div', { className:'vc-form' }, [field('Show up to', limit), field('Which', days), h('p', { className:'muted', text:'Reminders belong to your account, so they show on every board and on the Calendar page. Add them with “+ Reminder” on the widget.' })]), read:function(){ return { limit:Math.max(1, Number(limit.value) || 8), days:Number(days.value) || 0 }; } };
  }

  // ------------------------------------------------------------------ alerts (event and reminder notifications)
  var alertState = { on:false, timers:{}, shown:{} };
  function notify(a){
    if (alertState.shown[a.id]) return; alertState.shown[a.id] = 1;
    try { var seen = JSON.parse(localStorage.getItem('vaultcast-cal-alerts') || '{}'); if (seen[a.id]) return; seen[a.id] = Date.now(); Object.keys(seen).forEach(function(k){ if (Date.now() - seen[k] > 3 * 86400000) delete seen[k]; }); localStorage.setItem('vaultcast-cal-alerts', JSON.stringify(seen)); } catch (e) {}
    A.toast('🔔 ' + a.title + (a.body ? ' · ' + a.body : ''), { ms:8000 });
    if (window.Notification && Notification.permission === 'granted' && document.hidden) try { new Notification(a.title, { body:a.body || '', tag:a.id }); } catch (e) {}
  }
  function startAlerts(){
    if (alertState.on || !signed()) return; alertState.on = true;
    function load(){ if (document.hidden && !(window.Notification && Notification.permission === 'granted')) return; call('/misc/cal/alerts?window=900').then(function(j){ var skew = j.now * 1000 - Date.now(); j.alerts.forEach(function(a){ if (alertState.timers[a.id] || alertState.shown[a.id]) return; var ms = a.at * 1000 - skew - Date.now(); alertState.timers[a.id] = setTimeout(function(){ delete alertState.timers[a.id]; notify(a); }, Math.max(0, ms)); }); }).catch(function(){}); }
    load(); setInterval(load, 600000);
  }

  // ------------------------------------------------------------------ calendars data
  var calCache = null;
  function calendars(fresh){ if (!fresh && calCache) return calCache; calCache = call('/misc/cal/calendars').catch(function(e){ calCache = null; throw e; }); return calCache; }
  function hiddenCals(v){ try { if (v === undefined) return JSON.parse(localStorage.getItem('vaultcast-cal-hidden') || '[]'); localStorage.setItem('vaultcast-cal-hidden', JSON.stringify(v)); } catch (e) { return []; } }
  function events(from, to, ids, rem){ return call('/misc/cal/events?from=' + sec(from) + '&to=' + sec(to) + (ids && ids.length ? '&ids=' + ids.join(',') : '') + (rem ? '&reminders=1' : '')); }
  function evTime(e){ return e.all_day ? 'All day' : tFmt(new Date(e.start * 1000)); }

  // ------------------------------------------------------------------ Board calendar widget
  function calendarWidget(body, s){
    var gone = false, urls = String(s.urls || '').split(/\s+/).filter(Boolean);
    function load(){
      var from = day0(new Date()), to = addDays(from, Math.max(1, Math.min(60, Number(s.days) || 14)));
      var mine = signed() ? events(from, to, s.ids || [], !!s.reminders).then(function(j){ return j.events; }).catch(function(){ return []; }) : Promise.resolve([]);
      var ext = Promise.all(urls.slice(0, 6).map(function(u, i){ return call('/misc/widget/ics?days=' + (s.days || 14) + '&url=' + encodeURIComponent(u)).then(function(j){ return j.events.map(function(e){ e.colour = COLOURS[(i + 3) % COLOURS.length]; return e; }); }).catch(function(){ return []; }); }));
      Promise.all([mine, ext]).then(function(r){
        if (gone) return;
        var now = Date.now() / 1000, list = r[0].concat([].concat.apply([], r[1])).filter(function(e){ return e.end > now || e.start >= now; }).sort(function(a, b){ return a.start - b.start || (b.all_day ? 1 : 0) - (a.all_day ? 1 : 0); }).slice(0, 40);
        if (!list.length) { body.replaceChildren(h('p', { className:'muted', text:'Nothing coming up.' }), openLink()); return; }
        var rows = [], last = '';
        list.forEach(function(e){
          var d = new Date(Math.max(e.start, e.all_day ? 0 : e.start) * 1000), key = e.all_day && e.start < now ? new Date().toDateString() : d.toDateString();
          if (key !== last) { last = key; var dd = new Date(key), t = new Date().toDateString(), tm = addDays(new Date(), 1).toDateString(); rows.push(h('li', { className:'vc-cal-day', text:key === t ? 'Today' : key === tm ? 'Tomorrow' : dd.toLocaleDateString([], { weekday:'long', day:'numeric', month:'short' }) })); }
          rows.push(h('li', { className:'vc-cal-ev', style:'border-left-color:' + (e.colour || 'var(--accent)') }, [h('span', { className:'vc-cal-time', text:evTime(e) }), h('span', { className:'vc-cal-title' }, [h('strong', { text:e.title || '(no title)' }), e.location ? h('small', { className:'muted', text:e.location }) : null])]));
        });
        body.replaceChildren(h('ul', { className:'vc-w-list vc-cal' }, rows), openLink());
      });
    }
    function openLink(){ return signed() ? btn('Open calendar', function(){ A.state.miscTab = 'calendar'; A.state.miscSub = ''; A.pushNav(); A.render(); }, 'ghost small vc-cal-open') : null; }
    body.appendChild(h('p', { className:'muted', text:'Loading…' })); load();
    var t = setInterval(function(){ if (!document.hidden && body.isConnected) load(); }, Math.max(60, Number(s.refresh) || 900) * 1000);
    startAlerts();
    return function(){ gone = true; clearInterval(t); };
  }
  function calendarForm(s){
    var box = h('div', { className:'vc-cal-choose' }, [h('p', { className:'muted', text:'Loading calendars…' })]), checks = [];
    var days = input('number', s.days || 14, { min:'1', max:'60' }), rem = input('checkbox', !!s.reminders), urls = h('textarea', { rows:'3', placeholder:'https://…/basic.ics' }); urls.value = s.urls || '';
    if (signed()) calendars(true).then(function(j){
      checks = j.calendars.map(function(c){ var cb = input('checkbox', !s.ids || !s.ids.length || s.ids.indexOf(c.id) >= 0); return [c.id, cb, h('label', { className:'vc-check' }, [cb, h('span', { className:'vc-cal-swatch', style:'background:' + c.colour }), h('span', { text:c.name + (c.role !== 'owner' ? ' (shared by ' + c.owner.name + ')' : '') })])]; });
      box.replaceChildren.apply(box, checks.length ? checks.map(function(x){ return x[2]; }) : [h('p', { className:'muted', text:'No calendars yet. Create one on the Calendar page.' })]);
    }).catch(function(){ box.replaceChildren(h('p', { className:'muted', text:'Calendars unavailable.' })); });
    else box.replaceChildren(h('p', { className:'muted', text:'Sign in to show your calendars.' }));
    return { el:h('div', { className:'vc-form' }, [field('Calendars to show', box), field('Days ahead', days), h('label', { className:'vc-check' }, [rem, h('span', { text:'Include my reminders' })]), field('Other calendar links (ICS / webcal, one per line)', urls, 'Private Google, Outlook or iCloud ICS links work. Public internet addresses only.')]),
      read:function(){ var on = checks.filter(function(x){ return x[1].checked; }).map(function(x){ return x[0]; }); return { ids:on.length === checks.length ? [] : on, days:Math.max(1, Math.min(60, Number(days.value) || 14)), reminders:rem.checked, urls:urls.value }; } };
  }

  // ------------------------------------------------------------------ event editor
  function editEvent(ev, cals, defaults){
    return new Promise(function(resolve){
      var editable = cals.filter(function(c){ return c.kind === 'local' && (c.role === 'owner' || c.role === 'edit'); });
      if (!editable.length) { A.toast('Create a calendar first', { kind:'bad' }); return; }
      var isNew = !ev || !ev.id; ev = ev || {};
      var start = new Date((ev.series_start || ev.start || defaults.start || sec(new Date())) * 1000), end = new Date((ev.series_end || ev.end || (sec(start) + 3600)) * 1000);
      var allDay = isNew ? !!defaults.allDay : !!ev.all_day;
      if (allDay && !isNew && ev.end_date) end = parseYmd(ev.end_date);
      if (allDay && !isNew) start = parseYmd(ev.start_date) || start;
      var rep = ev.repeat || { freq:'', interval:1, days:[] };
      modal(isNew ? 'New event' : 'Edit event', function(b, close){
        var title = input('text', ev.title || '', { maxlength:'200', placeholder:'Title', required:true }), cal = select(editable.map(function(c){ return [c.id, c.name]; }), ev.calendar_id || defaults.calendar || editable[0].id);
        var ad = input('checkbox', allDay), sd = input('date', ymd(start)), st = input('time', hm(start)), ed = input('date', ymd(end)), et = input('time', hm(end));
        var loc = input('text', ev.location || '', { maxlength:'200', placeholder:'Location' }), notes = h('textarea', { rows:'3', maxlength:'4000', placeholder:'Notes' }); notes.value = ev.notes || '';
        var freq = select([['', 'Does not repeat'], ['DAILY', 'Daily'], ['WEEKLY', 'Weekly'], ['MONTHLY', 'Monthly'], ['YEARLY', 'Yearly']], rep.freq || ''), interval = input('number', rep.interval || 1, { min:'1', max:'99', className:'vc-num' }), until = input('date', rep.until || '');
        var days = rep.days && rep.days.length ? rep.days.slice() : [DAYS_RRULE[start.getDay()]];
        var dayBox = h('div', { className:'vc-day-pick' });
        function drawDays(){ dayBox.replaceChildren.apply(dayBox, [1, 2, 3, 4, 5, 6, 0].map(function(d){ var code = DAYS_RRULE[d], on = days.indexOf(code) >= 0; return h('button', { type:'button', className:'vc-day' + (on ? ' on' : ''), 'aria-pressed':String(on), text:DOW[d], onClick:function(){ var i = days.indexOf(code); if (i >= 0) { if (days.length > 1) days.splice(i, 1); } else days.push(code); drawDays(); } }); })); }
        drawDays();
        var REM = [[0, 'At the start'], [5, '5 min before'], [10, '10 min'], [15, '15 min'], [30, '30 min'], [60, '1 hour'], [120, '2 hours'], [1440, '1 day'], [2880, '2 days'], [10080, '1 week']], remOn = (ev.reminders || (isNew ? [15] : [])).slice();
        var remBox = h('div', { className:'vc-chip-row' }, REM.map(function(r){ var cb = input('checkbox', remOn.indexOf(r[0]) >= 0, { onChange:function(){ var i = remOn.indexOf(r[0]); if (this.checked && i < 0 && remOn.length < 5) remOn.push(r[0]); else if (!this.checked && i >= 0) remOn.splice(i, 1); else if (this.checked) { this.checked = false; A.toast('Up to five reminders'); } } }); return h('label', { className:'vc-check small' }, [cb, h('span', { text:r[1] })]); }));
        var repRow = h('div', { className:'vc-field-row' }, [field('Every', h('span', { className:'vc-inline' }, [interval, h('span', { className:'muted', text:'×' })])), field('Until (optional)', until)]);
        function sync(){ st.hidden = et.hidden = ad.checked; repRow.hidden = !freq.value; dayBox.hidden = freq.value !== 'WEEKLY'; }
        ad.addEventListener('change', sync); freq.addEventListener('change', sync);
        sd.addEventListener('change', function(){ if (ed.value < sd.value) ed.value = sd.value; });
        sync();
        var form = h('form', { className:'vc-form', onSubmit:function(e){
          e.preventDefault();
          var body = { calendar_id:cal.value, title:title.value, location:loc.value, notes:notes.value, all_day:ad.checked, tz:TZ, reminders:remOn,
            repeat:freq.value ? { freq:freq.value, interval:Number(interval.value) || 1, until:until.value || null, days:freq.value === 'WEEKLY' ? days : [] } : null };
          if (ad.checked) { body.start = sd.value; body.end = ed.value || sd.value; }
          else { var s1 = new Date(sd.value + 'T' + (st.value || '09:00')), e1 = new Date((ed.value || sd.value) + 'T' + (et.value || st.value || '10:00')); if (e1 <= s1) e1 = new Date(s1.getTime() + 3600000); body.start = sec(s1); body.end = sec(e1); }
          call(isNew ? '/misc/cal/events' : '/misc/cal/events/' + ev.id, { method:'POST', body:body }).then(function(){ close(); resolve(true); }).catch(err);
        } }, [title, field('Calendar', cal), h('label', { className:'vc-check' }, [ad, h('span', { text:'All day' })]),
          h('div', { className:'vc-field-row' }, [field('Starts', h('span', { className:'vc-inline' }, [sd, st])), field('Ends', h('span', { className:'vc-inline' }, [ed, et]))]),
          field('Repeat', freq), dayBox, repRow, loc, notes, field('Remind me', remBox),
          h('div', { className:'vc-form-actions' }, [!isNew ? btn('Delete', function(){ deleteEvent(ev).then(function(ok){ if (ok) { close(); resolve(true); } }); }, 'ghost danger') : null, h('button', { type:'submit', className:'btn primary', text:isNew ? 'Add event' : 'Save' })])]);
        b.appendChild(form); setTimeout(function(){ title.focus(); }, 30);
      });
    });
  }
  function deleteEvent(ev){
    if (!ev.repeat) return A.confirmDialog('Delete “' + ev.title + '”?', { okText:'Delete', danger:true }).then(function(ok){ if (!ok) return false; return call('/misc/cal/events/' + ev.id, { method:'DELETE' }).then(function(){ return true; }).catch(function(e){ err(e); return false; }); });
    return new Promise(function(resolve){
      modal('Delete a repeating event', function(b, close){
        b.append(h('p', { text:'“' + ev.title + '” repeats. Delete just this one, or every occurrence?' }), h('div', { className:'vc-form-actions' }, [
          btn('Only this one', function(){ close(); call('/misc/cal/events/' + ev.id + '?occurrence=' + ev.start, { method:'DELETE' }).then(function(){ resolve(true); }).catch(function(e){ err(e); resolve(false); }); }, 'ghost'),
          btn('All of them', function(){ close(); call('/misc/cal/events/' + ev.id, { method:'DELETE' }).then(function(){ resolve(true); }).catch(function(e){ err(e); resolve(false); }); }, 'ghost danger')]));
      });
    });
  }
  function showEvent(e, cals, done){
    var c = cals.filter(function(x){ return x.id === e.calendar_id; })[0], d = new Date(e.start * 1000), d2 = new Date(e.end * 1000);
    modal(e.title || 'Event', function(b, close){
      var when = e.all_day ? (e.start_date === e.end_date ? parseYmd(e.start_date).toLocaleDateString([], { weekday:'long', day:'numeric', month:'long' }) : parseYmd(e.start_date).toLocaleDateString([], { day:'numeric', month:'short' }) + ' – ' + parseYmd(e.end_date).toLocaleDateString([], { day:'numeric', month:'short' })) : d.toLocaleDateString([], { weekday:'long', day:'numeric', month:'long' }) + ', ' + tFmt(d) + ' – ' + tFmt(d2);
      b.append(h('div', { className:'vc-ev-detail', style:'--c:' + (e.colour || 'var(--accent)') }, [h('p', { className:'vc-ev-when', text:when }), e.repeat ? h('p', { className:'muted', text:'Repeats ' + String(e.repeat.freq || '').toLowerCase() + (e.repeat.until ? ' until ' + e.repeat.until : '') }) : null, e.location ? h('p', { text:'📍 ' + e.location }) : null, e.notes ? h('p', { className:'vc-ev-notes', text:e.notes }) : null, h('p', { className:'muted', text:(c ? c.name : e.source === 'reminder' ? 'Reminders' : 'Calendar') + (e.source === 'ep' ? ' · new episode' : '') })]),
        h('div', { className:'vc-form-actions' }, [e.editable ? btn('Delete', function(){ deleteEvent(e).then(function(ok){ if (ok) { close(); done(); } }); }, 'ghost danger') : null, e.editable ? btn('Edit', function(){ close(); editEvent(e, cals, {}).then(done); }, 'primary') : null]));
    });
  }

  // ------------------------------------------------------------------ calendar settings (create, edit, share, feed, import/export)
  function editCalendar(c, done){
    var isNew = !c;
    modal(isNew ? 'New calendar' : c.name, function(b, close){
      c = c || { name:'', colour:COLOURS[Math.floor(Math.random() * COLOURS.length)], kind:'local', episodes:'', show_reminders:false };
      var owner = isNew || c.role === 'owner';
      var name = input('text', c.name, { maxlength:'80', placeholder:'e.g. Family', required:true }), colour = input('color', c.colour), kind = select([['local', 'My own calendar'], ['subscribed', 'Subscribe to an ICS / webcal link']], c.kind, { disabled:!isNew });
      var urlIn = input('url', c.url || '', { placeholder:'webcal://… or https://….ics' }), eps = select([['', 'Off'], ['favourites', 'Shows in my favourites and watchlist'], ['library', 'Every show in the library']], c.episodes || ''), rem = input('checkbox', !!c.show_reminders);
      var urlRow = field('Calendar link', urlIn, 'Refreshed every 15 minutes. Private Google, Outlook and iCloud ICS links work.'), epRow = field('New episode releases', eps, 'Adds upcoming air dates (title, SxxEyy and episode name). Updated by the background task after library scans, never on page load.');
      function sync(){ urlRow.hidden = kind.value !== 'subscribed'; epRow.hidden = kind.value !== 'local'; }
      kind.addEventListener('change', sync); sync();
      var form = h('form', { className:'vc-form', onSubmit:function(e){ e.preventDefault(); var body = { name:name.value, colour:colour.value, kind:kind.value, url:urlIn.value, episodes:eps.value, show_reminders:rem.checked }; call(isNew ? '/misc/cal/calendars' : '/misc/cal/calendars/' + c.id, { method:'POST', body:body }).then(function(j){ calCache = Promise.resolve(j); close(); done(j); }).catch(err); } },
        owner ? [h('div', { className:'vc-field-row' }, [field('Name', name), field('Colour', colour)]), field('Type', kind), urlRow, epRow, h('label', { className:'vc-check' }, [rem, h('span', { text:'Show my reminders alongside this calendar' })]), h('div', { className:'vc-form-actions' }, [h('button', { type:'submit', className:'btn primary', text:isNew ? 'Create calendar' : 'Save' })])] : [h('p', { className:'muted', text:'Shared with you by ' + c.owner.name + ' (' + (c.role === 'edit' ? 'can edit' : 'view only') + ').' })]);
      b.appendChild(form);
      if (isNew) { setTimeout(function(){ name.focus(); }, 30); return; }
      // Export / import
      var file = input('file', null, { accept:'.ics,text/calendar', hidden:true, onChange:function(){ var f = this.files[0]; this.value = ''; if (!f) return; if (f.size > 8000000) { A.toast('That file is too large (8 MB at most)', { kind:'bad' }); return; } var r = new FileReader(); r.onload = function(){ call('/misc/cal/calendars/' + c.id + '/import', { method:'POST', body:{ ics:String(r.result || '') } }).then(function(j){ A.toast('Imported ' + j.added + ' new and ' + j.updated + ' updated events', { kind:'ok' }); done(); }).catch(err); }; r.readAsText(f); } });
      if (c.kind === 'local') b.appendChild(h('section', { className:'vc-cal-sec' }, [h('h3', { text:'Import and export' }), h('div', { className:'vc-form-actions start' }, [h('a', { className:'btn ghost small', href:A.API + '/misc/cal/calendars/' + c.id + '/export', download:(c.name || 'calendar') + '.ics', text:'Download .ics' }), c.role !== 'view' ? btn('Import .ics…', function(){ file.click(); }, 'ghost small') : null, file])]));
      if (!owner) return;
      // Feed
      var feedBox = h('div', { className:'vc-feed' });
      function drawFeed(cc){
        if (!cc.feed) { feedBox.replaceChildren(h('p', { className:'muted', text:'Publish a secret link that other calendar apps can subscribe to. Anyone with the link can read this calendar.' }), btn('Create subscription link', function(){ feedAct('create'); }, 'ghost small')); return; }
        var https = location.origin + cc.feed, webcal = https.replace(/^https?:/, 'webcal:');
        feedBox.replaceChildren(field('Subscription link', h('span', { className:'vc-inline' }, [input('text', webcal, { readonly:true, onFocus:function(){ this.select(); } }), btn('Copy', function(){ A.copyText ? A.copyText(webcal) : navigator.clipboard.writeText(webcal); }, 'ghost small')]), 'Also works as ' + https),
          h('div', { className:'vc-form-actions start' }, [h('a', { className:'btn ghost small', href:webcal, text:'Open in calendar app' }), btn('New link', function(){ A.confirmDialog('Make a new link? The current one stops working.', { okText:'New link' }).then(function(ok){ if (ok) feedAct('regenerate'); }); }, 'ghost small'), btn('Turn off', function(){ A.confirmDialog('Stop publishing this calendar? Subscribed apps will stop updating.', { okText:'Turn off', danger:true }).then(function(ok){ if (ok) feedAct('revoke'); }); }, 'ghost small danger')]));
      }
      function feedAct(a){ call('/misc/cal/calendars/' + c.id + '/feed', { method:'POST', body:{ action:a } }).then(function(j){ calCache = Promise.resolve(j); var cc = j.calendars.filter(function(x){ return x.id === c.id; })[0]; if (cc) { c = cc; drawFeed(cc); } }).catch(err); }
      if (c.kind !== 'subscribed') { b.appendChild(h('section', { className:'vc-cal-sec' }, [h('h3', { text:'Subscribe from other apps' }), feedBox])); drawFeed(c); }
      // Sharing
      var shareBox = h('div', { className:'vc-share' });
      function drawShares(cc){
        var who = input('text', '', { placeholder:'Username', 'aria-label':'Username to share with' }), role = select(cc.kind === 'local' ? [['view', 'Can view'], ['edit', 'Can edit']] : [['view', 'Can view']], 'view');
        shareBox.replaceChildren(h('ul', { className:'vc-share-list' }, (cc.shares || []).map(function(s){ return h('li', null, [h('span', { text:s.user.name }), select(cc.kind === 'local' ? [['view', 'Can view'], ['edit', 'Can edit'], ['none', 'Remove']] : [['view', 'Can view'], ['none', 'Remove']], s.role, { 'aria-label':'Access for ' + s.user.name, onChange:function(){ share({ user_id:s.user.id, role:this.value }); } })]); })),
          h('form', { className:'vc-inline', onSubmit:function(e){ e.preventDefault(); if (who.value.trim()) share({ username:who.value.trim(), role:role.value }); } }, [who, role, h('button', { type:'submit', className:'btn ghost small', text:'Share' })]));
      }
      function share(body){ call('/misc/cal/calendars/' + c.id + '/share', { method:'POST', body:body }).then(function(j){ calCache = Promise.resolve(j); var cc = j.calendars.filter(function(x){ return x.id === c.id; })[0]; if (cc) { c = cc; drawShares(cc); } A.toast('Sharing updated', { kind:'ok' }); }).catch(err); }
      b.appendChild(h('section', { className:'vc-cal-sec' }, [h('h3', { text:'Share with people here' }), shareBox])); drawShares(c);
      b.appendChild(h('div', { className:'vc-form-actions start' }, [btn('Delete calendar', function(){ A.confirmDialog('Delete “' + c.name + '” and all its events?', { okText:'Delete', danger:true }).then(function(ok){ if (ok) call('/misc/cal/calendars/' + c.id, { method:'DELETE' }).then(function(j){ calCache = Promise.resolve(j); close(); done(j); }).catch(err); }); }, 'ghost danger small')]));
    });
  }

  // ------------------------------------------------------------------ Calendar page
  function page(host, sub){
    var view = ['month', 'week', 'agenda', 'reminders'].indexOf(sub) >= 0 ? sub : (localStorage.getItem('vaultcast-cal-view') || 'month'), cursor = day0(new Date()), cals = [], gone = false, seq = 0;
    if (!signed()) { host.appendChild(empty('Sign in to use calendars', 'Calendars, reminders and shared events belong to your account.')); return; }
    var title = h('h1', { className:'vc-cal-title-h' }), main = h('div', { className:'vc-cal-main' }), side = h('aside', { className:'vc-cal-side' }), viewSeg = h('div', { className:'vc-seg', role:'group', 'aria-label':'View' });
    var bar = h('header', { className:'vc-cal-bar' }, [h('div', { className:'vc-cal-nav' }, [btn('‹', function(){ move(-1); }, 'ghost vc-icon-btn', { 'aria-label':'Previous' }), btn('Today', function(){ cursor = day0(new Date()); draw(); }, 'ghost small'), btn('›', function(){ move(1); }, 'ghost vc-icon-btn', { 'aria-label':'Next' }), title]), viewSeg, btn('+ Event', function(){ editEvent(null, cals, { start:sec(new Date(cursor.getFullYear(), cursor.getMonth(), cursor.getDate(), new Date().getHours() + 1)) }).then(draw); }, 'primary')]);
    host.append(h('div', { className:'vc-cal-page' }, [bar, h('div', { className:'vc-cal-layout' }, [side, main])]));
    function setView(v){ view = v; try { localStorage.setItem('vaultcast-cal-view', v); } catch (e) {} A.state.miscSub = v; A.pushNav(); draw(); }
    function drawSeg(){ viewSeg.replaceChildren.apply(viewSeg, [['month', 'Month'], ['week', 'Week'], ['agenda', 'Agenda'], ['reminders', 'Reminders']].map(function(o){ return h('button', { type:'button', className:'vc-seg-btn' + (o[0] === view ? ' active' : ''), 'aria-pressed':String(o[0] === view), text:o[1], onClick:function(){ setView(o[0]); } }); })); }
    function move(n){ if (view === 'month') cursor = new Date(cursor.getFullYear(), cursor.getMonth() + n, 1); else if (view === 'week') cursor = addDays(cursor, 7 * n); else cursor = addDays(cursor, 30 * n); draw(); }
    function visibleIds(){ var hid = hiddenCals(); return cals.filter(function(c){ return hid.indexOf(c.id) < 0; }).map(function(c){ return c.id; }); }
    function drawSide(){
      var hid = hiddenCals();
      side.replaceChildren(h('div', { className:'vc-cal-side-head' }, [h('h2', { text:'Calendars' }), btn('+', function(){ editCalendar(null, function(){ loadCals().then(draw); }); }, 'ghost vc-icon-btn small', { 'aria-label':'New calendar' })]),
        h('ul', { className:'vc-cal-list' }, cals.length ? cals.map(function(c){ var on = hid.indexOf(c.id) < 0; return h('li', null, [h('label', { className:'vc-check' }, [input('checkbox', on, { onChange:function(){ var x = hiddenCals(), i = x.indexOf(c.id); if (this.checked && i >= 0) x.splice(i, 1); else if (!this.checked && i < 0) x.push(c.id); hiddenCals(x); draw(); } }), h('span', { className:'vc-cal-swatch', style:'background:' + c.colour }), h('span', { className:'vc-cal-name', text:c.name }), c.kind === 'subscribed' ? h('small', { className:'muted', text:'link' }) : c.episodes ? h('small', { className:'muted', text:'TV' }) : c.role !== 'owner' ? h('small', { className:'muted', text:c.owner.name }) : null]), btn('⋯', function(){ editCalendar(c, function(){ loadCals().then(draw); }); }, 'ghost vc-icon-btn small', { 'aria-label':'Settings for ' + c.name })]); }) : [h('li', { className:'muted', text:'No calendars yet.' })]),
        h('label', { className:'vc-check' }, [input('checkbox', localStorage.getItem('vaultcast-cal-rem') !== 'off', { onChange:function(){ try { localStorage.setItem('vaultcast-cal-rem', this.checked ? 'on' : 'off'); } catch (e) {} draw(); } }), h('span', { text:'Show my reminders' })]),
        window.Notification && Notification.permission === 'default' ? btn('Turn on notifications', function(){ Notification.requestPermission().then(drawSide); }, 'ghost small') : '');
    }
    function loadCals(){ return calendars(true).then(function(j){ cals = j.calendars; if (!cals.length) return call('/misc/cal/calendars', { method:'POST', body:{ name:'My calendar', colour:'#59e9ff' } }).then(function(k){ calCache = Promise.resolve(k); cals = k.calendars; }); }).then(drawSide); }
    function withRem(){ return localStorage.getItem('vaultcast-cal-rem') !== 'off'; }
    function chip(e){ return h('button', { type:'button', className:'vc-cal-chip' + (e.all_day ? ' all-day' : '') + (e.done ? ' done' : ''), style:'--c:' + (e.colour || 'var(--accent)'), title:e.title, onClick:function(ev){ ev.stopPropagation(); open(e); } }, [e.all_day ? null : h('span', { className:'vc-chip-time', text:tFmt(new Date(e.start * 1000)) }), h('span', { text:e.title || '(no title)' })]); }
    function open(e){ if (e.source === 'reminder') { call('/misc/reminders').then(function(j){ var r = j.reminders.filter(function(x){ return x.id === e.reminder_id; })[0]; if (r) editReminder(r, false).then(draw); }); return; } showEvent(e, cals, draw); }
    function byDay(list, from, n){ var m = {}; list.forEach(function(e){ var s = new Date(e.start * 1000), en = new Date(Math.max(e.start + 1, e.end - (e.all_day ? 1 : 0)) * 1000); for (var d = day0(s < from ? from : s); d <= en && d < addDays(from, n); d = addDays(d, 1)) (m[ymd(d)] = m[ymd(d)] || []).push(e); }); return m; }
    function draw(){
      if (gone) return; drawSeg(); var my = ++seq;
      if (view === 'reminders') { title.textContent = 'Reminders'; return drawReminders(); }
      var from, to, n;
      if (view === 'month') { from = monday(new Date(cursor.getFullYear(), cursor.getMonth(), 1)); n = 42; title.textContent = cursor.toLocaleDateString([], { month:'long', year:'numeric' }); }
      else if (view === 'week') { from = monday(cursor); n = 7; var last = addDays(from, 6); title.textContent = from.toLocaleDateString([], { day:'numeric', month:'short' }) + ' – ' + last.toLocaleDateString([], { day:'numeric', month:'short', year:'numeric' }); }
      else { from = day0(cursor); n = 60; title.textContent = 'From ' + from.toLocaleDateString([], { weekday:'short', day:'numeric', month:'long' }); }
      to = addDays(from, n);
      main.classList.add('loading');
      events(from, to, visibleIds(), withRem()).then(function(j){
        if (gone || my !== seq) return; main.classList.remove('loading');
        var ids = visibleIds(), list = j.events.filter(function(e){ return e.calendar_id === 'reminders' || ids.indexOf(e.calendar_id) >= 0; });
        if (j.errors && j.errors.length) A.toast(j.errors[0].error, { kind:'bad', ms:5000 });
        if (view === 'month') drawMonth(from, list); else if (view === 'week') drawWeek(from, list); else drawAgenda(from, list);
      }).catch(function(e){ main.classList.remove('loading'); main.replaceChildren(empty('Calendar unavailable', (e && e.error) || '')); });
    }
    function newOn(d, hour){ var s = new Date(d.getFullYear(), d.getMonth(), d.getDate(), hour == null ? 9 : hour); editEvent(null, cals, { start:sec(s), allDay:hour == null && view === 'month' ? false : false }).then(draw); }
    function drawMonth(from, list){
      var m = byDay(list, from, 42), today = ymd(new Date()), grid = h('div', { className:'vc-month', role:'grid' });
      [1, 2, 3, 4, 5, 6, 0].forEach(function(d){ grid.appendChild(h('div', { className:'vc-month-dow', role:'columnheader', text:DOW[d] })); });
      for (var i = 0; i < 42; i++) (function(d){
        var key = ymd(d), evs = (m[key] || []).sort(function(a, b){ return (b.all_day ? 1 : 0) - (a.all_day ? 1 : 0) || a.start - b.start; }), max = 3;
        grid.appendChild(h('div', { className:'vc-month-cell' + (d.getMonth() !== cursor.getMonth() ? ' other' : '') + (key === today ? ' today' : ''), role:'gridcell', tabindex:'0', 'aria-label':d.toDateString() + (evs.length ? ', ' + evs.length + ' events' : ''), onClick:function(){ newOn(d); }, onKeydown:function(e){ if (e.key === 'Enter') newOn(d); } },
          [h('span', { className:'vc-month-num', text:String(d.getDate()) })].concat(evs.slice(0, max).map(chip), evs.length > max ? [h('button', { type:'button', className:'vc-more', text:'+' + (evs.length - max) + ' more', onClick:function(e){ e.stopPropagation(); cursor = d; setView('agenda'); } })] : [])));
      })(addDays(from, i));
      main.replaceChildren(grid);
    }
    function drawWeek(from, list){
      var m = byDay(list, from, 7), today = ymd(new Date()), wrap = h('div', { className:'vc-week' }), head = h('div', { className:'vc-week-head' }, [h('span')]), allRow = h('div', { className:'vc-week-all' }, [h('span', { className:'muted', text:'all day' })]), body = h('div', { className:'vc-week-body' }), hours = h('div', { className:'vc-week-hours' });
      for (var hr = 0; hr < 24; hr++) hours.appendChild(h('span', { text:pad(hr) + ':00' }));
      body.appendChild(hours);
      for (var i = 0; i < 7; i++) (function(d){
        var key = ymd(d), evs = m[key] || [];
        head.appendChild(h('div', { className:'vc-week-day' + (key === today ? ' today' : '') }, [h('small', { text:DOW[d.getDay()] }), h('strong', { text:String(d.getDate()) })]));
        allRow.appendChild(h('div', { className:'vc-week-allcell' }, evs.filter(function(e){ return e.all_day; }).map(chip)));
        var col = h('div', { className:'vc-week-col' + (key === today ? ' today' : ''), onClick:function(e){ var r = col.getBoundingClientRect(); newOn(d, Math.max(0, Math.min(23, Math.floor((e.clientY - r.top) / r.height * 24)))); } });
        // Timed events: lanes so overlapping events sit side by side.
        var timed = evs.filter(function(e){ return !e.all_day; }).sort(function(a, b){ return a.start - b.start; }), lanes = [], d0 = sec(d), d1 = d0 + 86400;
        timed.forEach(function(e){ var lane = 0; while (lanes[lane] && lanes[lane] > e.start) lane++; lanes[lane] = e.end; e._lane = lane; });
        var nl = Math.max(1, lanes.length);
        timed.forEach(function(e){ var s = Math.max(e.start, d0), en = Math.min(Math.max(e.end, e.start + 900), d1); var el = chip(e); el.classList.add('timed'); el.style.top = ((s - d0) / 864) + '%'; el.style.height = Math.max(2, (en - s) / 864) + '%'; el.style.left = (e._lane / nl * 100) + '%'; el.style.width = (100 / nl) + '%'; col.appendChild(el); });
        if (key === today) { var nowEl = h('div', { className:'vc-now-line' }); nowEl.style.top = ((Date.now() / 1000 - d0) / 864) + '%'; col.appendChild(nowEl); }
        body.appendChild(col);
      })(addDays(from, i));
      wrap.append(head, allRow, body); main.replaceChildren(h('div', { className:'vc-week-scroll' }, [wrap]));
      body.scrollTop = body.scrollHeight * (7 / 24);
    }
    function drawAgenda(from, list){
      var m = byDay(list, from, 60), keys = Object.keys(m).sort(), rows = [];
      keys.forEach(function(k){ var d = parseYmd(k); rows.push(h('li', { className:'vc-cal-day', text:k === ymd(new Date()) ? 'Today' : d.toLocaleDateString([], { weekday:'long', day:'numeric', month:'long' }) })); m[k].sort(function(a, b){ return (b.all_day ? 1 : 0) - (a.all_day ? 1 : 0) || a.start - b.start; }).forEach(function(e){ rows.push(h('li', null, [h('button', { type:'button', className:'vc-cal-ev', style:'border-left-color:' + (e.colour || 'var(--accent)'), onClick:function(){ open(e); } }, [h('span', { className:'vc-cal-time', text:evTime(e) }), h('span', { className:'vc-cal-title' }, [h('strong', { text:e.title || '(no title)' }), e.location ? h('small', { className:'muted', text:e.location }) : null])])])); }); });
      main.replaceChildren(rows.length ? h('ul', { className:'vc-w-list vc-cal vc-agenda' }, rows) : empty('Nothing in the next 60 days', 'Add an event, or turn on more calendars.', [btn('+ Event', function(){ newOn(from); }, 'primary')]));
    }
    function drawReminders(){
      main.replaceChildren(h('p', { className:'muted', text:'Loading…' }));
      function render(j){ if (gone || view !== 'reminders') return; var list = j.reminders;
        main.replaceChildren(h('div', { className:'vc-rem-page' }, [h('div', { className:'vc-form-actions start' }, [btn('+ Reminder', function(){ chooseTemplate().then(render); }, 'primary')]), list.length ? h('ul', { className:'vc-w-list vc-rems' }, reminderRows(list, render)) : empty('No reminders yet', 'Pick a template (bins, bills, MOT, birthdays…) or make your own.', [btn('+ Reminder', function(){ chooseTemplate().then(render); }, 'primary')])])); }
      call('/misc/reminders').then(render).catch(function(e){ main.replaceChildren(empty('Reminders unavailable', (e && e.error) || '')); });
    }
    loadCals().then(draw).catch(function(e){ main.replaceChildren(empty('Calendar unavailable', (e && e.error) || '')); });
    startAlerts();
    return function(){ gone = true; };
  }

  root.VaultcastCalendar = {
    init:function(app){ A = app; h = app.h; api = app.api; },
    page:page, calendarWidget:calendarWidget, calendarForm:calendarForm, remindersWidget:remindersWidget, remindersForm:remindersForm,
    templates:TEMPLATES, editReminder:editReminder, chooseTemplate:chooseTemplate
  };
})(this);
