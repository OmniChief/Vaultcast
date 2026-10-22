/* Vaultcast parental controls: kids profiles, PIN unlock, self settings and the admin editor (loaded on demand).
   Everything here is presentation only; the server enforces every limit. */
(function(root){
  'use strict';
  var A, h, api;
  var LEVELS = [[1,'U / G · everyone'],[2,'PG · parental guidance'],[3,'12 · age 12 and up'],[4,'15 · age 15 and up'],[5,'18 · adults (no limit)']];
  function text(v, fallback){ return v == null || v === '' ? (fallback || '') : String(v); }
  function el(tag, attrs, kids){ return h(tag, attrs || {}, kids || []); }
  function check(label, on, hint){ var i = el('input', { type:'checkbox' }); i.checked = !!on; return { input:i, node:el('label', { className:'check-label parental-check' }, [i, el('span', {}, [el('strong', { text:label }), hint ? el('small', { className:'muted', text:' ' + hint }) : null])]) }; }
  function choose(label, options, value){ var s = el('select', { 'aria-label':label }, options.map(function(o){ return el('option', { value:String(o[0]), text:o[1] }); })); s.value = String(value); return { input:s, node:el('label', { className:'parental-field' }, [el('span', { text:label }), s]) }; }
  function pinInput(label, placeholder){ var i = el('input', { type:'password', inputmode:'numeric', pattern:'[0-9]*', maxlength:6, autocomplete:'off', placeholder:placeholder || '4–6 digits', 'aria-label':label }); return { input:i, node:el('label', { className:'parental-field' }, [el('span', { text:label }), i]) }; }
  function friendly(j, fallback){ return (j && (j.error || j.message)) || fallback || 'Something went wrong. Please try again.'; }
  function dialog(title, body){ return A.dialog ? A.dialog(title, body) : null; }
  function status(){ return (A.state.user && A.state.user.parental) || null; }

  /* Shared form for both the admin editor and self settings. */
  function form(settings){
    var s = settings || {};
    var parts = {
      enabled: check('Turn on parental controls', s.enabled, 'Hide titles above the age limit and the areas switched off below.'),
      kid: check('Kids profile', s.kid, 'A simpler, friendlier Home with bigger pictures and no settings. Leaving it needs the PIN.'),
      ceiling: choose('Highest age rating allowed', LEVELS, s.ceiling || 5),
      unrated: choose('Titles without an age rating', [['allow','Show them'],['hide','Hide them (safest)']], s.unrated || 'allow'),
      live: check('Live TV', s.live !== false, 'Channels, IPTV and radio.'),
      play: check('Play & Arcade', s.play !== false, 'Games and the arcade.'),
      social: check('Chat, messages & friends', s.social !== false, 'Chat rooms, direct messages, friends and invites.')
    };
    var areas = el('fieldset', { className:'parental-areas' }, [el('legend', { text:'Allowed areas' }), parts.live.node, parts.play.node, parts.social.node]);
    parts.node = el('div', { className:'parental-form stack' }, [parts.enabled.node, parts.kid.node, el('div', { className:'parental-grid' }, [parts.ceiling.node, parts.unrated.node]), areas]);
    function sync(){ if (parts.kid.input.checked) parts.enabled.input.checked = true; }
    parts.kid.input.addEventListener('change', sync);
    parts.read = function(){ return { enabled: parts.enabled.input.checked || parts.kid.input.checked, kid: parts.kid.input.checked, ceiling: Number(parts.ceiling.input.value) || 5, unrated: parts.unrated.input.value, live: parts.live.input.checked, play: parts.play.input.checked, social: parts.social.input.checked }; };
    return parts;
  }

  function summary(s){
    if (!s || (!s.enabled && !s.kid)) return 'Off';
    var bits = [s.kid ? 'Kids profile' : 'On', 'up to ' + text(s.ceiling_label, 'any rating')];
    var off = []; if (s.live === false) off.push('Live'); if (s.play === false) off.push('Play'); if (s.social === false) off.push('chat');
    if (off.length) bits.push('no ' + off.join(', '));
    return bits.join(' · ');
  }

  /* Admin: Settings → Accounts → Edit user → Parental controls. */
  function adminEditor(user, done){
    var current = user.parental || {};
    var f = form(current);
    var self = check('Let this person change these settings with the PIN', current.self_manage, '');
    var pin = pinInput(current.has_pin ? 'New PIN (leave empty to keep the current one)' : 'PIN for leaving kid mode and changing settings');
    var clear = check('Remove the PIN', false, current.kid ? 'Not possible while the kids profile is on.' : '');
    var note = el('p', { className:'muted', role:'status', text: current.has_pin ? 'A PIN is set.' : 'No PIN yet. Kids profiles need one.' });
    var save = el('button', { className:'btn primary', type:'button', text:'Save parental controls' });
    var body = el('div', { className:'stack parental-admin' }, [el('p', { className:'muted', text:'Limits apply on every device this person signs in on. Age ratings come from the files\u2019 NFO, OMDb and TMDB; you can correct one on a title\u2019s page.' }), f.node, self.node, pin.node, current.has_pin ? clear.node : null, note, save]);
    var d = dialog('Parental controls · ' + text(user.display_name || user.username, 'User'), body);
    save.onclick = function(){
      save.disabled = true; note.textContent = 'Saving…';
      var settings = f.read(); settings.self_manage = self.input.checked;
      api('/admin/users/' + encodeURIComponent(user.id) + '/parental', { method:'POST', body:{ settings:settings, pin:pin.input.value.trim(), clear_pin:clear.input.checked } }).then(function(j){
        save.disabled = false;
        if (!j || !j.ok) { note.textContent = friendly(j, 'Could not save.'); return; }
        A.toast('Parental controls saved', { kind:'ok' }); if (d) d.close(); if (done) done(j.parental);
      }, function(){ save.disabled = false; note.textContent = 'Could not reach the server. Please try again.'; });
    };
  }

  /* The signed-in person's own page: Settings → Parental controls. */
  function selfPanel(host){
    host.replaceChildren(el('p', { className:'muted', text:'Loading…' }));
    api('/me/parental').then(function(st){
      if (!host.isConnected) return;
      if (!st || !st.ok) { host.replaceChildren(el('p', { className:'muted', text:friendly(st, 'Parental controls could not be loaded.') })); return; }
      A.state.user.parental = st;
      var s = st.settings || {};
      var head = el('div', { className:'settings-card parental-status' }, [el('h3', { text:'Right now' }), el('p', { text: summary(s) }),
        st.unlocked_for > 0 ? el('p', { className:'muted', text:'Unlocked on this device for about ' + Math.max(1, Math.round(st.unlocked_for / 60)) + ' more minutes.' }) : null,
        st.unlocked_for > 0 ? el('button', { className:'btn', type:'button', text: s.kid ? 'Back to kid mode' : 'Lock again', onClick:lock }) : null]);
      if (!st.can_manage) { host.replaceChildren(head, el('div', { className:'settings-card' }, [el('h3', { text:'Managed by your administrator' }), el('p', { className:'muted', text:'Ask the person who runs this server to change these settings.' })])); return; }
      var f = form(s);
      var cur = pinInput('Current PIN'), next = pinInput(st.has_pin ? 'New PIN (optional)' : 'Choose a PIN');
      var note = el('p', { className:'muted', role:'status', text: st.has_pin ? 'Enter the current PIN to save changes.' : 'Choose a 4–6 digit PIN. You\u2019ll need it to leave kid mode or change these settings.' });
      var save = el('button', { className:'btn primary', type:'button', text:'Save' });
      save.onclick = function(){
        save.disabled = true; note.textContent = 'Saving…';
        api('/me/parental', { method:'POST', body:{ settings:f.read(), current_pin:cur.input.value.trim(), new_pin:next.input.value.trim() } }).then(function(j){
          save.disabled = false;
          if (!j || !j.ok) { note.textContent = friendly(j, 'Could not save.'); return; }
          A.toast('Parental controls saved', { kind:'ok' }); setTimeout(function(){ location.reload(); }, 400);
        }, function(){ save.disabled = false; note.textContent = 'Could not reach the server. Please try again.'; });
      };
      host.replaceChildren(head, el('div', { className:'settings-card stack' }, [el('h3', { text:'Limits for this profile' }), f.node, st.has_pin ? cur.node : null, next.node, note, el('div', { className:'row-actions' }, [save])]));
    }, function(){ host.replaceChildren(el('p', { className:'muted', text:'Parental controls could not be loaded. Check your connection and try again.' })); });
  }

  function unlockPrompt(then){
    var pin = pinInput('PIN', 'Enter PIN');
    var note = el('p', { className:'muted', role:'status', text:'Enter the grown-up PIN to unlock everything on this device for an hour.' });
    var go = el('button', { className:'btn primary', type:'submit', text:'Unlock' });
    var body = el('form', { className:'stack parental-unlock' }, [note, pin.node, go]);
    var d = dialog('Grown-up mode', body);
    body.addEventListener('submit', function(e){
      e.preventDefault(); go.disabled = true;
      api('/me/parental/unlock', { method:'POST', body:{ pin:pin.input.value.trim() } }).then(function(j){
        go.disabled = false;
        if (!j || !j.ok) { note.textContent = friendly(j, 'That PIN didn\u2019t work.'); pin.input.value = ''; pin.input.focus(); return; }
        A.state.user.parental = j; if (d) d.close();
        if (then) { apply(); then(); } else location.reload();
      }, function(){ go.disabled = false; note.textContent = 'Could not reach the server. Please try again.'; });
    });
    setTimeout(function(){ pin.input.focus(); }, 30);
  }
  function lock(){ api('/me/parental/lock', { method:'POST' }).then(function(){ location.reload(); }, function(){ location.reload(); }); }

  /* Body classes and the small floating switch. */
  function apply(){
    var st = status(), b = document.body; if (!b) return;
    var kidLocked = !!(st && st.kid && !(st.unlocked_for > 0));
    var unlocked = !!(st && (st.kid || (st.settings && st.settings.enabled)) && st.unlocked_for > 0);
    b.classList.toggle('kid-mode', kidLocked);
    b.classList.toggle('parental-unlocked', unlocked);
    var pill = document.getElementById('parental-switch');
    if (!kidLocked && !unlocked) { if (pill) pill.remove(); return; }
    if (!pill) { pill = el('button', { id:'parental-switch', type:'button', className:'parental-switch' }); b.appendChild(pill); }
    pill.textContent = kidLocked ? '\uD83D\uDD12 Grown-ups' : (st.kid ? '\uD83E\uDDF8 Back to kid mode' : '\uD83D\uDD12 Lock parental controls');
    pill.title = kidLocked ? 'Enter the PIN to leave kid mode' : 'Turn the limits back on for this device';
    pill.onclick = kidLocked ? function(){ unlockPrompt(null); } : lock;
  }

  /* Admins and age_ratings holders: correct a title's age rating on its page. */
  function ratingControl(target){
    var s = el('select', { 'aria-label':'Age rating' }, [el('option', { value:'0', text:'Automatic / unknown' })].concat(LEVELS.map(function(l){ return el('option', { value:String(l[0]), text:l[1].split(' · ')[0] }); })));
    var info = el('small', { className:'muted', text:'' });
    var wrap = el('label', { className:'parental-rating-control' }, [el('span', { text:'Age rating' }), s, info]);
    api('/admin/ratings?target=' + encodeURIComponent(target)).then(function(j){
      if (!j || !j.ok) { wrap.remove(); return; }
      var r = j.rating || {}; s.value = r.source === 'manual' ? String(r.bucket || 0) : '0';
      info.textContent = r.bucket ? (r.source === 'manual' ? 'Set by hand' : 'Found: ' + text(r.raw, text(r.label)) + (r.source ? ' (' + r.source + ')' : '')) : 'No rating found yet';
    }, function(){ wrap.remove(); });
    s.addEventListener('change', function(){
      api('/admin/ratings', { method:'POST', body:{ target:target, bucket:Number(s.value) } }).then(function(j){
        if (!j || !j.ok) return A.toast(friendly(j, 'Could not save the rating.'), { kind:'bad' });
        info.textContent = Number(s.value) ? 'Set by hand' : 'Automatic'; A.toast('Age rating saved', { kind:'ok' });
      });
    });
    return wrap;
  }

  root.VaultcastParental = { init:function(app){ A = app; h = app.h; api = app.api; }, apply:apply, unlockPrompt:unlockPrompt, lock:lock, selfPanel:selfPanel, adminEditor:adminEditor, ratingControl:ratingControl, summary:summary };
})(window);
