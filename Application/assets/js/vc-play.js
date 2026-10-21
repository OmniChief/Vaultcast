/* Vaultcast Play: Arcade, Quiz nights, Teams & friends and Leaderboards (loaded on demand). */
(function(root){
  'use strict';
  var A, h, api;
  var GAMES = {
    blocks:{ name:'Stackfall', blurb:'Stack, spin and clear lines as the pace climbs.', icon:'<svg viewBox="0 0 48 48"><rect x="6" y="26" width="10" height="10" rx="2"/><rect x="16" y="26" width="10" height="10" rx="2"/><rect x="16" y="16" width="10" height="10" rx="2"/><rect x="26" y="26" width="10" height="10" rx="2" opacity=".55"/><rect x="26" y="36" width="10" height="6" rx="2" opacity=".35"/><rect x="36" y="6" width="6" height="14" rx="2" opacity=".75"/></svg>', hue:280 },
    snake:{ name:'Neon Snake', blurb:'Eat, grow and do not bite your own tail.', icon:'<svg viewBox="0 0 48 48"><path d="M8 36h14a6 6 0 0 0 0-12h-6a6 6 0 0 1 0-12h18" fill="none" stroke-width="6" stroke-linecap="round"/><circle cx="40" cy="12" r="4"/></svg>', hue:150 },
    merge:{ name:'2048 Merge', blurb:'Slide matching tiles together and chase 2048.', icon:'<svg viewBox="0 0 48 48"><rect x="6" y="6" width="16" height="16" rx="4"/><rect x="26" y="6" width="16" height="16" rx="4" opacity=".55"/><rect x="6" y="26" width="16" height="16" rx="4" opacity=".55"/><rect x="26" y="26" width="16" height="16" rx="4"/></svg>', hue:30 },
    breakout:{ name:'Brickbreak', blurb:'Keep the ball alive and smash every brick.', icon:'<svg viewBox="0 0 48 48"><rect x="6" y="8" width="10" height="6" rx="2"/><rect x="19" y="8" width="10" height="6" rx="2" opacity=".6"/><rect x="32" y="8" width="10" height="6" rx="2"/><rect x="12" y="17" width="10" height="6" rx="2" opacity=".6"/><rect x="26" y="17" width="10" height="6" rx="2"/><circle cx="26" cy="31" r="3"/><rect x="14" y="38" width="20" height="4" rx="2"/></svg>', hue:200 },
    word:{ name:'Daily Five', blurb:'One five-letter word a day. Six guesses.', icon:'<svg viewBox="0 0 48 48"><rect x="4" y="16" width="12" height="16" rx="3"/><rect x="18" y="16" width="12" height="16" rx="3" opacity=".55"/><rect x="32" y="16" width="12" height="16" rx="3" opacity=".3"/></svg>', hue:110 },
    chess:{ name:'Chess', blurb:'Challenge a friend by correspondence or play the computer.', icon:'<svg viewBox="0 0 48 48"><path d="M18 40h12l-2-12c4-2 6-6 6-10a10 10 0 0 0-20 0c0 4 2 8 6 10z"/><rect x="13" y="40" width="22" height="4" rx="2"/></svg>', hue:45 }
  };
  var RUN = ['blocks', 'snake', 'merge', 'breakout'];
  var HOWTO = {
    blocks:['Pieces fall into the well. Fill a whole row to clear it; clearing several rows at once scores much more.', 'Move with ← → (or A D), rotate with ↑ or X, soft drop with ↓ and hard drop with Space. C or Shift holds a piece for later.', 'Every ten lines the level and the speed go up. The game ends when a new piece has no room.'],
    snake:['Steer the snake to the red food. Every bite makes you longer and a little faster.', 'Use the arrow keys, WASD, a swipe or the D-pad. Gold bonus food appears now and then and is worth five times as much, but it fades.', 'Hitting a wall or your own tail ends the game.'],
    merge:['Slide every tile in one direction with the arrow keys, WASD or a swipe.', 'Two tiles with the same number merge into one and add to your score. A new tile appears after every move.', 'Reach 2048 and keep going. The game ends when no move is possible. Leaving mid-game saves it so you can resume.'],
    breakout:['Move the paddle with ← →, the mouse or your finger and press Space or tap to launch the ball.', 'Break every brick to reach the next level. Darker bricks need two hits. Some bricks drop a wide paddle or an extra ball.', 'You have three lives; a ball that falls past the paddle costs one.']
  };
  function signed(){ return !!(A.state.user && A.state.user.id && A.state.user.role !== 'guest'); }
  function me(){ return A.state.user || {}; }
  function fmt(n){ return Number(n || 0).toLocaleString(); }
  function ago(t){ var s = Math.max(1, Math.round(Date.now() / 1000 - t)); if (s < 60) return 'just now'; if (s < 3600) return Math.round(s / 60) + ' min ago'; if (s < 86400) return Math.round(s / 3600) + ' h ago'; return Math.round(s / 86400) + ' d ago'; }
  function dur(sec){ sec = Math.round(sec || 0); var hh = Math.floor(sec / 3600), mm = Math.floor(sec % 3600 / 60); return hh ? hh + ' h ' + mm + ' min' : mm ? mm + ' min' : sec + ' s'; }
  function svgIcon(markup, cls){ var s = h('span', { className:'vc-ico ' + (cls || ''), html:markup }); return s; }
  function btn(label, onClick, cls, attrs){ return h('button', Object.assign({ type:'button', className:'btn ' + (cls || ''), text:label, onClick:onClick }, attrs || {})); }
  function go(tab, sub){ A.state.playTab = tab; A.state.playSub = sub || ''; A.pushNav(); A.render(); }
  function avatar(p, size){
    p = p || {}; var initial = String(p.name || '?').trim().charAt(0).toUpperCase() || '?';
    return p.avatar_url ? h('img', { className:'vc-avatar', src:p.avatar_url, alt:'', width:size || 32, height:size || 32, loading:'lazy' }) : h('span', { className:'vc-avatar vc-avatar-letter', text:initial, 'aria-hidden':'true' });
  }
  function hero(title, sub, actions, kicker){
    return h('header', { className:'vc-hero' }, [h('div', { className:'vc-hero-copy' }, [kicker ? h('span', { className:'eyebrow', text:kicker }) : null, h('h1', { text:title }), sub ? h('p', { className:'muted', text:sub }) : null]), actions ? h('div', { className:'vc-hero-actions' }, actions) : null]);
  }
  function panel(title, kids, more, cls){ return h('section', { className:'hub-panel vc-panel ' + (cls || '') }, [h('div', { className:'hub-panel-head' }, [h('h2', { text:title }), more || null]), h('div', { className:'hub-panel-body' }, kids)]); }
  function empty(t, d, actions){ return A.emptyState ? A.emptyState(t, d, actions) : h('p', { className:'muted', text:t }); }
  function err(e){ A.toast((e && (e.error || e.message)) || 'Something went wrong', { kind:'bad' }); }
  function call(path, opts){ return api(path, opts).then(function(j){ if (!j || !j.ok) throw j || {}; return j; }); }
  function loadGames(){ return A.loadModule('vc-games.js', 'VaultcastGames'); }
  function loadQR(){ return A.loadModule('vc-qr.js', 'VaultcastQR'); }
  function qrBox(text, label){
    var box = h('div', { className:'vc-qr-box', 'aria-label':label || 'QR code' });
    loadQR().then(function(Q){ try { box.appendChild(Q.svg(text, { label:label })); } catch (e) { box.textContent = text; } });
    return box;
  }
  function seg(options, value, onChange, label){
    var wrap = h('div', { className:'vc-seg', role:'group', 'aria-label':label || '' });
    options.forEach(function(o){ wrap.appendChild(h('button', { type:'button', className:'vc-seg-btn' + (o[0] === value ? ' active' : ''), 'aria-pressed':String(o[0] === value), text:o[1], onClick:function(){ onChange(o[0]); } })); });
    return wrap;
  }
  function stopAll(){ if (current && current.destroy) current.destroy(); current = null; if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; } }
  var current = null, pollTimer = null;

  // ------------------------------------------------------------------ friends picker (challenge / chess)
  function pickFriend(title, onPick){
    window.VaultcastHub.modal(title, function(body, close){
      body.appendChild(A.pendingContent({ text:'Loading friends…' }));
      call('/play/friends').then(function(j){
        body.replaceChildren();
        var friends = (j.friends || []).filter(function(f){ return f.state === 'friend'; });
        if (!friends.length) { body.appendChild(empty('No friends yet', 'Add friends under Play → Teams & friends, then challenge them here.', [btn('Find friends', function(){ close(); go('social', 'friends'); }, 'primary')])); return; }
        var list = h('div', { className:'vc-people-list' });
        friends.forEach(function(f){ list.appendChild(h('button', { type:'button', className:'vc-person-row', onClick:function(){ close(); onPick(f); } }, [avatar(f), h('span', { className:'vc-person-name' }, [h('strong', { text:f.name }), h('small', { className:'muted', text:f.team ? f.team.emblem + ' ' + f.team.name : '@' + f.username })])])); });
        body.appendChild(list);
      }).catch(function(e){ body.replaceChildren(empty('Friends unavailable', (e && e.error) || '')); });
    });
  }
  function challenge(game){
    if (!signed()) { A.toast('Sign in to challenge friends'); return; }
    pickFriend('Challenge a friend to ' + GAMES[game].name, function(f){
      call('/play/challenge', { method:'POST', body:{ user_id:f.id, game:game } }).then(function(){ A.toast('Challenge sent to ' + f.name, { kind:'ok' }); }).catch(err);
    });
  }

  // ------------------------------------------------------------------ Arcade hub
  function arcade(host, sub){
    var parts = String(sub || '').split('/');
    if (RUN.indexOf(parts[0]) >= 0) return runGame(host, parts[0]);
    if (parts[0] === 'word') return dailyFive(host);
    if (parts[0] === 'chess') return chessView(host, parts[1] || '');
    host.appendChild(hero('Arcade', 'Original pick-up-and-play games. Personal bests and points count towards your team and the leaderboards.', [btn('Leaderboards', function(){ go('leaderboards'); }, 'ghost'), btn('Quiz night', function(){ go('quiz'); }, 'primary')], 'PLAY'));
    var daily = h('div', { className:'vc-daily-strip' });
    host.appendChild(daily);
    var grid = h('div', { className:'vc-game-grid' });
    Object.keys(GAMES).forEach(function(id){
      var g = GAMES[id], best = h('span', { className:'vc-best', text:signed() ? 'Best —' : 'Play as guest' });
      var card = h('article', { className:'vc-game-card', style:'--vc-hue:' + g.hue, tabindex:'0', role:'button', 'aria-label':'Play ' + g.name,
        onClick:function(e){ if (e.target.closest('.vc-card-actions')) return; go('arcade', id); }, onKeydown:function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go('arcade', id); } } }, [
        h('div', { className:'vc-game-art' }, [svgIcon(g.icon, 'vc-game-icon')]),
        h('div', { className:'vc-game-copy' }, [h('h3', { text:g.name }), h('p', { className:'muted', text:g.blurb }), best]),
        h('div', { className:'vc-card-actions' }, [btn(id === 'chess' ? 'Open' : 'Play', function(){ go('arcade', id); }, 'primary'), id !== 'chess' ? btn('Ranks', function(){ go('leaderboards', id); }, 'ghost') : null, RUN.indexOf(id) >= 0 || id === 'word' ? btn('Challenge', function(){ challenge(id); }, 'ghost') : null])
      ]);
      card._best = best; grid.appendChild(card);
    });
    host.appendChild(grid);
    if (signed()) {
      call('/play/arcade/summary').then(function(j){ Array.prototype.forEach.call(grid.children, function(card, i){ var id = Object.keys(GAMES)[i]; if (j.bests[id] != null) card._best.textContent = j.bests[id] ? 'Your best ' + fmt(j.bests[id]) : 'No score yet'; else if (id === 'chess') card._best.textContent = 'Async with friends · vs computer'; }); }).catch(function(){});
      call('/play/word').then(function(w){
        daily.replaceChildren(h('div', { className:'vc-daily-card' }, [h('strong', { text:'Daily Five #' + w.number }), h('span', { className:'muted', text:w.done ? (w.won ? 'Solved in ' + w.guesses.length + ' · streak ' + w.streak : 'Better luck tomorrow') : (w.guesses.length ? w.guesses.length + ' of 6 guesses used' : 'Today\u2019s word is waiting') }), btn(w.done ? 'Share result' : 'Play', function(){ go('arcade', 'word'); }, w.done ? 'ghost' : 'primary')]),
          h('div', { className:'vc-daily-card' }, [h('strong', { text:'Daily quiz' }), h('span', { className:'muted', text:'Ten questions, new every day' }), btn('Play', function(){ go('quiz', 'daily'); }, 'primary')]));
      }).catch(function(){});
    } else daily.appendChild(h('p', { className:'muted', text:'Sign in to keep personal bests, earn points and join the daily puzzles.' }));
  }

  // ------------------------------------------------------------------ game runner (keyboard, touch, gamepad, pause, sound)
  var KEYMAP = { ArrowLeft:'left', ArrowRight:'right', ArrowUp:'up', ArrowDown:'down', a:'left', d:'right', w:'up', s:'down', A:'left', D:'right', W:'up', S:'down', ' ':'drop', Enter:'action', c:'hold', C:'hold', Shift:'hold', z:'rotateL', Z:'rotateL', x:'rotate', X:'rotate' };
  function soundOn(){ try { return localStorage.getItem('vaultcast-arcade-sound') !== 'off'; } catch (e) { return true; } }
  function runGame(host, id){
    var g = GAMES[id], scoreEl = h('strong', { className:'vc-hud-score', text:'0' }), bestEl = h('span', { className:'vc-hud-best muted', text:'' }), statusEl = h('span', { className:'vc-hud-status', 'aria-live':'polite' });
    var stageEl = h('div', { className:'vc-game-stage', tabindex:'0', 'aria-label':g.name + ' game area' }), overlay = h('div', { className:'vc-game-overlay', hidden:true });
    var soundBtn = btn(soundOn() ? '🔊' : '🔇', function(){ try { localStorage.setItem('vaultcast-arcade-sound', soundOn() ? 'off' : 'on'); } catch (e) {} soundBtn.textContent = soundOn() ? '🔊' : '🔇'; soundBtn.setAttribute('aria-label', soundOn() ? 'Mute sound' : 'Turn sound on'); }, 'ghost vc-icon-btn', { 'aria-label':soundOn() ? 'Mute sound' : 'Turn sound on' });
    var pauseBtn = btn('Pause', function(){ toggle(); }, 'ghost');
    var helpBtn = btn('?', function(){ if (engine && !paused && !ended) setPaused(true); showHowTo(function(){ if (engine && !ended) setPaused(false); else hideOverlay(); }); }, 'ghost vc-icon-btn', { 'aria-label':'How to play' });
    var wrap = h('div', { className:'vc-game-wrap' }, [
      h('div', { className:'vc-game-bar' }, [btn('← Arcade', function(){ go('arcade'); }, 'ghost'), h('div', { className:'vc-game-title' }, [h('h1', { text:g.name }), statusEl]), h('div', { className:'vc-hud' }, [h('span', { className:'muted', text:'Score' }), scoreEl, bestEl]),
        h('div', { className:'vc-game-tools' }, [pauseBtn, helpBtn, soundBtn, btn('⛶', function(){ var el = wrap; if (document.fullscreenElement) document.exitFullscreen(); else if (el.requestFullscreen) el.requestFullscreen().catch(function(){}); }, 'ghost vc-icon-btn', { 'aria-label':'Full screen' })])]),
      h('div', { className:'vc-game-body' }, [stageEl, overlay]),
      h('div', { className:'vc-touch-pad' }),
      h('p', { className:'vc-game-help muted' })
    ]);
    host.appendChild(wrap);
    var engine = null, run = null, startedAt = 0, activeMs = 0, lastResume = 0, paused = false, ended = false, best = 0, held = {}, repeatTimers = {}, gpPrev = {}, gpRaf = 0, pbShown = false, lastScore = 0;
    var resumeKey = 'vaultcast-arcade-resume:' + (me().id || 'guest') + ':' + id;
    function score(n){ lastScore = n; scoreEl.textContent = fmt(n); if (n > best && best > 0) { scoreEl.classList.add('vc-pb'); if (!pbShown) { pbShown = true; A.toast('🎉 New personal best in ' + g.name + '!', { kind:'ok' }); } } }
    function playedMs(){ return activeMs + (paused || ended || !lastResume ? 0 : Date.now() - lastResume); }
    /** Games that can snapshot (2048) are saved when you leave or pause, and offered again for six hours. */
    function saveSnap(){ if (!engine || ended || !engine.snapshot) return; var snap = engine.snapshot(); if (snap && lastScore > 0) store(resumeKey, { run:run, best:best, ms:playedMs(), state:snap, at:Date.now() }); }
    function showHowTo(done){
      showOverlay([h('h2', { text:'How to play ' + g.name }), h('ul', { className:'vc-howto' }, (HOWTO[id] || []).map(function(t){ return h('li', { text:t }); })), h('p', { className:'muted', text:'P or Esc pauses. Games pause by themselves when you switch tabs or windows. A gamepad works too.' }), btn('Got it', function(){ try { localStorage.setItem('vaultcast-arcade-howto:' + id, '1'); } catch (e) {} done(); }, 'primary')]);
    }
    function setPaused(p, reason){
      if (ended || !engine) return; if (p === paused) return; paused = p;
      if (p) { activeMs += Date.now() - lastResume; engine.pause(); saveSnap(); pauseBtn.textContent = 'Resume'; showOverlay([h('h2', { text:'Paused' }), reason ? h('p', { className:'muted', text:reason }) : null, btn('Resume', function(){ setPaused(false); }, 'primary'), btn('Restart', function(){ start(); }, 'ghost'), btn('Quit', function(){ go('arcade'); }, 'ghost')]); }
      else { lastResume = Date.now(); engine.resume(); pauseBtn.textContent = 'Pause'; hideOverlay(); stageEl.focus(); }
    }
    function toggle(){ setPaused(!paused); }
    function showOverlay(kids){ overlay.replaceChildren(h('div', { className:'vc-overlay-card' }, kids)); overlay.hidden = false; }
    function hideOverlay(){ overlay.hidden = true; overlay.replaceChildren(); }
    function start(resume){
      if (!resume) store(resumeKey, null);
      hideOverlay(); ended = false; paused = false; pbShown = false; lastScore = 0; pauseBtn.textContent = 'Pause'; scoreEl.textContent = '0'; scoreEl.classList.remove('vc-pb'); statusEl.textContent = '';
      if (engine) { engine.destroy(); engine = null; } stageEl.replaceChildren();
      run = null;
      var begin = function(){
        loadGames().then(function(G){
          if (!wrap.isConnected) return;
          engine = G[id](stageEl, { sound:soundOn, score:score, status:function(t){ statusEl.textContent = t; }, over:finish });
          current = { destroy:cleanup };
          startedAt = Date.now(); lastResume = startedAt; activeMs = 0;
          if (resume && engine.restore && engine.restore(resume.state)) { activeMs = Math.max(0, resume.ms || 0); statusEl.textContent = 'Resumed'; }
          wrap.querySelector('.vc-game-help').textContent = engine.help || '';
          var pad = wrap.querySelector('.vc-touch-pad'); pad.replaceChildren();
          (engine.controls || []).forEach(function(c){
            var b = h('button', { type:'button', className:'vc-pad-btn', text:c[1], 'aria-label':c[0] });
            b.addEventListener('pointerdown', function(e){ e.preventDefault(); press(c[0], true); b.setPointerCapture && b.setPointerCapture(e.pointerId); });
            ['pointerup','pointercancel','pointerleave'].forEach(function(ev){ b.addEventListener(ev, function(){ press(c[0], false); }); });
            pad.appendChild(b);
          });
          stageEl.focus();
        }).catch(err);
      };
      // A resumed game keeps its original run, so the server's clock covers the whole game (runs last six hours).
      if (resume && resume.run && signed()) { run = resume.run; best = resume.best || 0; bestEl.textContent = best ? 'Best ' + fmt(best) : ''; begin(); }
      else if (signed()) call('/play/arcade/start', { method:'POST', body:{ game:id } }).then(function(j){ run = j.run; best = j.best || 0; bestEl.textContent = best ? 'Best ' + fmt(best) : ''; begin(); }).catch(function(e){ err(e); begin(); });
      else { bestEl.textContent = 'Guest: scores are not saved'; begin(); }
    }
    function finish(res){
      if (ended) return; ended = true; activeMs += Date.now() - lastResume; store(resumeKey, null);
      var card = [h('h2', { text:'Game over' }), h('div', { className:'vc-final-score', text:fmt(res.score) }), h('p', { className:'muted vc-result-line', text:signed() ? 'Saving score…' : 'Sign in to save scores and earn points.' }),
        h('div', { className:'vc-overlay-actions' }, [btn('Play again', function(){ start(); }, 'primary'), btn('Leaderboard', function(){ go('leaderboards', id); }, 'ghost'), signed() ? btn('Challenge a friend', function(){ challenge(id); }, 'ghost') : null])];
      showOverlay(card);
      var line = overlay.querySelector('.vc-result-line');
      if (!signed() || !run) return;
      call('/play/arcade/score', { method:'POST', body:{ run:run, score:res.score, duration_ms:activeMs, meta:res.meta || '' } }).then(function(j){
        best = j.best; bestEl.textContent = 'Best ' + fmt(j.best);
        line.textContent = (j.personal_best ? '🎉 New personal best! ' : '') + (j.points ? '+' + j.points + ' points' : 'No points this time') + (j.rank ? ' · rank #' + j.rank : '');
        if (j.personal_best) line.classList.add('vc-pb');
      }).catch(function(e){ line.textContent = (e && e.error) || 'Score not saved.'; });
    }
    function press(action, down){
      if (!engine) return;
      if (action === 'pause') { if (down) toggle(); return; }
      if (paused || ended) return;
      engine.input(action, down);
      if ((engine.repeat || []).indexOf(action) >= 0) {
        clearTimeout(repeatTimers[action]); clearInterval(repeatTimers[action + 'i']);
        if (down) repeatTimers[action] = setTimeout(function(){ repeatTimers[action + 'i'] = setInterval(function(){ if (!paused && !ended && engine) engine.input(action, true); }, 50); }, 170);
      }
    }
    function onKey(e){
      if (!wrap.isConnected) return cleanup();
      if (e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) return;
      if (e.key === 'p' || e.key === 'P' || e.key === 'Escape') { if (e.type === 'keydown') { e.preventDefault(); if (!ended) toggle(); } return; }
      var a = KEYMAP[e.key]; if (!a) return;
      e.preventDefault();
      if (e.type === 'keydown') { if (held[e.key]) return; held[e.key] = true; press(a, true); } else { held[e.key] = false; press(a, false); }
    }
    // swipe
    var sx = 0, sy = 0, sw = false;
    stageEl.addEventListener('touchstart', function(e){ if (!engine || !engine.swipe) return; var t = e.touches[0]; sx = t.clientX; sy = t.clientY; sw = true; }, { passive:true });
    stageEl.addEventListener('touchmove', function(e){ if (engine && engine.swipe) e.preventDefault(); }, { passive:false });
    stageEl.addEventListener('touchend', function(e){ if (!sw || !engine) return; sw = false; var t = e.changedTouches[0], dx = t.clientX - sx, dy = t.clientY - sy; if (Math.max(Math.abs(dx), Math.abs(dy)) < 24) return; var a = Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 'right' : 'left') : (dy > 0 ? 'down' : 'up'); press(a, true); press(a, false); });
    // gamepad
    var GP = { 12:'up', 13:'down', 14:'left', 15:'right', 0:'rotate', 1:'drop', 2:'hold', 3:'action', 9:'pause' };
    function poll(){
      if (!wrap.isConnected) { cleanup(); return; }
      gpRaf = requestAnimationFrame(poll);
      var pads = navigator.getGamepads ? navigator.getGamepads() : []; var p = pads && (pads[0] || pads[1]); if (!p) return;
      var now = {}; Object.keys(GP).forEach(function(i){ if (p.buttons[i] && p.buttons[i].pressed) now[GP[i]] = true; });
      if (p.axes && p.axes.length >= 2) { if (p.axes[0] < -.5) now.left = true; if (p.axes[0] > .5) now.right = true; if (p.axes[1] < -.5) now.up = true; if (p.axes[1] > .5) now.down = true; }
      ['up','down','left','right','rotate','drop','hold','action','pause'].forEach(function(a){ if (!!now[a] !== !!gpPrev[a]) press(a, !!now[a]); });
      gpPrev = now;
    }
    function onHide(){ if (document.hidden) { setPaused(true, 'Paused while you were away.'); saveSnap(); } }
    function onBlur(){ setPaused(true, 'Paused while the window was in the background.'); }
    function cleanup(){
      saveSnap();
      document.removeEventListener('keydown', onKey); document.removeEventListener('keyup', onKey); document.removeEventListener('visibilitychange', onHide); window.removeEventListener('blur', onBlur);
      cancelAnimationFrame(gpRaf); Object.keys(repeatTimers).forEach(function(k){ clearTimeout(repeatTimers[k]); clearInterval(repeatTimers[k]); });
      if (engine) { engine.destroy(); engine = null; }
    }
    document.addEventListener('keydown', onKey); document.addEventListener('keyup', onKey); document.addEventListener('visibilitychange', onHide); window.addEventListener('blur', onBlur);
    poll();
    var saved = store(resumeKey), seen = false; try { seen = !!localStorage.getItem('vaultcast-arcade-howto:' + id); } catch (e) {}
    if (saved && saved.state && Date.now() - saved.at < 6 * 3600000 - 120000) showOverlay([h('h2', { text:'Resume your game?' }), h('p', { className:'muted', text:'Score ' + fmt(saved.state.score || 0) + ' · saved ' + ago(Math.floor(saved.at / 1000)) }), h('div', { className:'vc-overlay-actions' }, [btn('Resume', function(){ start(saved); }, 'primary'), btn('New game', function(){ start(); }, 'ghost')])]);
    else if (!seen && HOWTO[id]) showHowTo(function(){ start(); });
    else start();
  }

  // ------------------------------------------------------------------ Daily Five
  function dailyFive(host){
    host.appendChild(h('div', { className:'vc-game-bar' }, [btn('← Arcade', function(){ go('arcade'); }, 'ghost'), h('div', { className:'vc-game-title' }, [h('h1', { text:'Daily Five' }), h('span', { className:'muted', text:'Guess the five-letter word in six tries.' })]), btn('Ranks', function(){ go('leaderboards', 'word'); }, 'ghost')]));
    if (!signed()) { host.appendChild(empty('Sign in to play', 'The Daily Five keeps your streak, so it needs an account.')); return; }
    var box = h('div', { className:'vc-word' }, [A.pendingContent({ text:'Loading today\u2019s puzzle…' })]); host.appendChild(box);
    var state = null, typed = '', busy = false, keyEls = {};
    function load(){ call('/play/word').then(function(j){ state = j; draw(); }).catch(function(e){ box.replaceChildren(empty('Puzzle unavailable', (e && e.error) || '')); }); }
    function letterMarks(){ var m = {}; (state.guesses || []).forEach(function(g){ for (var i = 0; i < 5; i++) { var c = g.word[i], k = g.marks[i], rank = { g:3, y:2, x:1 }; if (!m[c] || rank[k] > rank[m[c]]) m[c] = k; } }); return m; }
    function draw(){
      var grid = h('div', { className:'vc-word-grid', role:'grid', 'aria-label':'Guesses' });
      for (var r = 0; r < 6; r++) {
        var g = state.guesses[r], row = h('div', { className:'vc-word-row', role:'row' });
        for (var c = 0; c < 5; c++) {
          var ch = g ? g.word[c] : (r === state.guesses.length && !state.done ? (typed[c] || '') : '');
          var mark = g ? g.marks[c] : '';
          row.appendChild(h('div', { className:'vc-word-tile' + (mark ? ' m-' + mark : '') + (ch && !mark ? ' typed' : ''), role:'gridcell', style:g ? 'animation-delay:' + (c * 90) + 'ms' : null, 'aria-label':ch ? ch.toUpperCase() + (mark ? ' ' + ({ g:'correct', y:'wrong place', x:'not in word' })[mark] : '') : 'empty', text:ch.toUpperCase() }));
        }
        grid.appendChild(row);
      }
      var marks = letterMarks(), kb = h('div', { className:'vc-word-keys' }); keyEls = {};
      ['qwertyuiop', 'asdfghjkl', '+zxcvbnm-'].forEach(function(line){
        var row = h('div', { className:'vc-word-krow' });
        line.split('').forEach(function(k){
          var label = k === '+' ? 'Enter' : k === '-' ? '⌫' : k.toUpperCase();
          var b = h('button', { type:'button', className:'vc-word-key' + (k === '+' || k === '-' ? ' wide' : '') + (marks[k] ? ' m-' + marks[k] : ''), text:label, 'aria-label':k === '-' ? 'Delete' : label, onClick:function(){ key(k === '+' ? 'Enter' : k === '-' ? 'Backspace' : k); } });
          keyEls[k] = b; row.appendChild(b);
        });
        kb.appendChild(row);
      });
      var info = h('div', { className:'vc-word-info' });
      if (state.done) {
        info.appendChild(h('p', { className:'vc-word-result', text:state.won ? 'Solved in ' + state.guesses.length + '/6 · streak ' + state.streak : 'The word was ' + String(state.answer || '').toUpperCase() }));
        info.appendChild(btn('Share result', share, 'primary'));
        info.appendChild(h('p', { className:'muted', text:'A new word arrives at midnight.' }));
      } else info.appendChild(h('p', { className:'muted', text:'Streak ' + state.streak + ' · ' + (6 - state.guesses.length) + ' guesses left' }));
      box.replaceChildren(grid, info, state.done ? null : kb);
    }
    function share(){
      var lines = state.guesses.map(function(g){ return g.marks.split('').map(function(m){ return m === 'g' ? '🟩' : m === 'y' ? '🟨' : '⬛'; }).join(''); });
      var text = 'Vaultcast Daily Five #' + state.number + ' ' + (state.won ? state.guesses.length : 'X') + '/6\n\n' + lines.join('\n');
      if (navigator.share) navigator.share({ text:text }).catch(function(){});
      else if (navigator.clipboard) navigator.clipboard.writeText(text).then(function(){ A.toast('Result copied', { kind:'ok' }); }, function(){ A.toast(text); });
      else A.toast(text);
    }
    function key(k){
      if (!state || state.done || busy) return;
      if (k === 'Enter') {
        if (typed.length !== 5) { shake(); A.toast('Five letters please'); return; }
        busy = true;
        call('/play/word', { method:'POST', body:{ guess:typed } }).then(function(){ typed = ''; busy = false; load(); }).catch(function(e){ busy = false; shake(); err(e); });
        return;
      }
      if (k === 'Backspace') typed = typed.slice(0, -1);
      else if (/^[a-z]$/i.test(k) && typed.length < 5) typed += k.toLowerCase();
      draw();
    }
    function shake(){ var row = box.querySelectorAll('.vc-word-row')[state.guesses.length]; if (row) { row.classList.remove('shake'); void row.offsetWidth; row.classList.add('shake'); } }
    function onKey(e){ if (!box.isConnected) { document.removeEventListener('keydown', onKey); return; } if (e.ctrlKey || e.metaKey || e.altKey || /^(INPUT|TEXTAREA)$/.test(e.target.tagName)) return; if (e.key === 'Enter' || e.key === 'Backspace' || /^[a-z]$/i.test(e.key)) { e.preventDefault(); key(e.key); } }
    document.addEventListener('keydown', onKey);
    current = { destroy:function(){ document.removeEventListener('keydown', onKey); } };
    load();
  }

  // ------------------------------------------------------------------ Chess
  var GLYPH = { K:'♔', Q:'♕', R:'♖', B:'♗', N:'♘', P:'♙', k:'♚', q:'♛', r:'♜', b:'♝', n:'♞', p:'♟' };
  function chessBoard(C, opts){
    var el = h('div', { className:'vc-chess-board', role:'grid', 'aria-label':'Chess board' }), sel = -1, st = null, legal = [], last = '', flip = false, enabled = false;
    function sqName(i){ return C.name(i); }
    function draw(){
      el.replaceChildren();
      var targets = sel >= 0 ? legal.filter(function(m){ return m.slice(0, 2) === sqName(sel); }).map(function(m){ return C.sq(m.slice(2, 4)); }) : [];
      var checkSq = C.inCheck(st, st.turn) ? st.b.indexOf(st.turn === 'w' ? 'K' : 'k') : -1;
      for (var row = 0; row < 8; row++) for (var col = 0; col < 8; col++) {
        var rank = flip ? row : 7 - row, file = flip ? 7 - col : col, i = rank * 8 + file, p = st.b[i];
        var cls = 'vc-sq ' + ((rank + file) % 2 ? 'light' : 'dark') + (i === sel ? ' sel' : '') + (targets.indexOf(i) >= 0 ? (p !== '.' ? ' cap' : ' dot') : '') + (last && (C.sq(last.slice(0, 2)) === i || C.sq(last.slice(2, 4)) === i) ? ' last' : '') + (i === checkSq ? ' check' : '');
        var cell = h('button', { type:'button', className:cls, 'data-sq':String(i), 'aria-label':sqName(i) + (p !== '.' ? ' ' + (p === p.toUpperCase() ? 'white ' : 'black ') + ({ k:'king', q:'queen', r:'rook', b:'bishop', n:'knight', p:'pawn' })[p.toLowerCase()] : ''), onClick:(function(i){ return function(){ tap(i); }; })(i) }, [
          p !== '.' ? h('span', { className:'vc-piece ' + (p === p.toUpperCase() ? 'w' : 'b'), text:GLYPH[p.toLowerCase()] }) : null,
          col === 0 ? h('span', { className:'vc-coord r', text:String(rank + 1) }) : null, row === 7 ? h('span', { className:'vc-coord f', text:'abcdefgh'[file] }) : null]);
        el.appendChild(cell);
      }
    }
    function tap(i){
      if (!enabled) return;
      var mine = st.b[i] !== '.' && (st.b[i] === st.b[i].toUpperCase() ? 'w' : 'b') === st.turn;
      if (sel >= 0) {
        var from = sqName(sel), to = sqName(i), moves = legal.filter(function(m){ return m.slice(0, 4) === from + to; });
        if (moves.length === 1) { sel = -1; opts.onMove(moves[0]); return; }
        if (moves.length > 1) { promote(moves); return; }
      }
      sel = mine ? (sel === i ? -1 : i) : -1; draw();
    }
    function promote(moves){
      window.VaultcastHub.modal('Promote pawn', function(body, close){
        var row = h('div', { className:'vc-promo' });
        ['q','r','b','n'].forEach(function(p){ var m = moves.filter(function(x){ return x[4] === p; })[0]; if (m) row.appendChild(h('button', { type:'button', className:'vc-promo-btn', text:GLYPH[st.turn === 'w' ? p.toUpperCase() : p], 'aria-label':({ q:'Queen', r:'Rook', b:'Bishop', n:'Knight' })[p], onClick:function(){ close(); sel = -1; opts.onMove(m); } })); });
        body.appendChild(row);
      });
    }
    return { el:el, set:function(fen, moves, lastMove, flipped, on){ st = C.parse(fen); legal = moves || []; last = lastMove || ''; flip = !!flipped; enabled = !!on; sel = -1; draw(); } };
  }
  function chessView(host, id){
    host.appendChild(h('div', { className:'vc-game-bar' }, [btn(id ? '← Chess' : '← Arcade', function(){ go('arcade', id ? 'chess' : ''); }, 'ghost'), h('div', { className:'vc-game-title' }, [h('h1', { text:id === 'ai' ? 'Chess vs computer' : 'Chess' }), h('span', { className:'muted', text:id === 'ai' ? 'Local game, runs in your browser.' : 'Correspondence games with friends: move whenever suits you.' })])]));
    var body = h('div', { className:'vc-chess' }); host.appendChild(body);
    loadGames().then(function(G){
      if (!body.isConnected) return;
      if (id === 'ai') return chessAi(body, G.chess);
      if (!signed()) { body.appendChild(empty('Sign in to play friends', 'You can still play the computer.', [btn('Play the computer', function(){ go('arcade', 'chess/ai'); }, 'primary')])); return; }
      if (id) return chessGame(body, G.chess, id);
      chessList(body);
    }).catch(err);
  }
  function chessList(body){
    body.appendChild(h('div', { className:'vc-chess-actions' }, [btn('Challenge a friend', function(){
      pickFriend('Play chess with…', function(f){
        window.VaultcastHub.modal('New game with ' + f.name, function(b, close){
          b.appendChild(h('p', { className:'muted', text:'Choose your colour. Your friend gets a notification.' }));
          b.appendChild(h('div', { className:'vc-overlay-actions' }, [['White', false], ['Black', true]].map(function(c){ return btn('Play ' + c[0], function(){ close(); call('/play/chess', { method:'POST', body:{ opponent:f.id, play_black:c[1] } }).then(function(j){ go('arcade', 'chess/' + j.game.id); }).catch(err); }, c[1] ? 'ghost' : 'primary'); })));
        });
      });
    }, 'primary'), btn('Play the computer', function(){ go('arcade', 'chess/ai'); }, 'ghost')]));
    var list = h('div', { className:'vc-chess-list' }, [A.pendingContent({ text:'Loading games…' })]); body.appendChild(list);
    call('/play/chess').then(function(j){
      list.replaceChildren();
      if (!j.games.length) { list.appendChild(empty('No games yet', 'Challenge a friend, then take turns whenever you like.')); return; }
      j.games.forEach(function(g){
        var opp = g.players[g.colour === 'w' ? g.black : g.white] || {};
        var status = g.status !== 'active' ? (g.winner === '' ? 'Draw · ' + g.reason : (g.winner === me().id ? 'Won · ' : 'Lost · ') + g.reason) : (g.your_turn ? 'Your move' : 'Waiting for ' + opp.name);
        list.appendChild(h('button', { type:'button', className:'vc-chess-row' + (g.your_turn ? ' your-turn' : ''), onClick:function(){ go('arcade', 'chess/' + g.id); } }, [avatar(opp), h('span', { className:'vc-person-name' }, [h('strong', { text:'vs ' + opp.name }), h('small', { className:'muted', text:'You play ' + (g.colour === 'w' ? 'white' : 'black') + ' · ' + g.moves.length + ' moves · ' + ago(g.updated_at) })]), h('span', { className:'vc-chip' + (g.your_turn ? ' accent' : ''), text:status })]));
      });
    }).catch(function(e){ list.replaceChildren(empty('Games unavailable', (e && e.error) || '')); });
  }
  function moveList(moves){ var out = h('ol', { className:'vc-move-list' }); for (var i = 0; i < moves.length; i += 2) out.appendChild(h('li', { text:moves[i] + (moves[i + 1] ? '  ' + moves[i + 1] : '') })); setTimeout(function(){ out.scrollTop = out.scrollHeight; }); return out; }
  function chessGame(body, C, id){
    var board = chessBoard(C, { onMove:function(m){ send('move', { move:m }); } }), side = h('aside', { className:'vc-chess-side' }), game = null, sending = false;
    body.appendChild(h('div', { className:'vc-chess-layout' }, [h('div', { className:'vc-chess-board-wrap' }, [board.el]), side]));
    function show(g){
      game = g; var opp = g.players[g.colour === 'w' ? g.black : g.white] || {}, mine = g.players[me().id] || me();
      board.set(g.fen, g.legal, g.moves[g.moves.length - 1], g.colour === 'b', g.your_turn && !sending);
      var status = g.status !== 'active' ? (g.winner === '' ? 'Drawn by ' + g.reason : (g.winner === me().id ? 'You won by ' : 'You lost by ') + g.reason) : g.your_turn ? (g.check ? 'Check! Your move' : 'Your move') : 'Waiting for ' + opp.name + '…';
      side.replaceChildren(
        h('div', { className:'vc-chess-player' }, [avatar(opp), h('strong', { text:opp.name }), h('span', { className:'muted', text:g.colour === 'w' ? 'Black' : 'White' })]),
        h('p', { className:'vc-chess-status' + (g.your_turn ? ' your-turn' : ''), 'aria-live':'polite', text:status }),
        g.draw_offer && g.draw_offer !== me().id && g.status === 'active' ? h('p', { className:'vc-chip accent', text:opp.name + ' offers a draw' }) : null,
        moveList(g.moves),
        h('div', { className:'vc-chess-player' }, [avatar(mine), h('strong', { text:'You' }), h('span', { className:'muted', text:g.colour === 'w' ? 'White' : 'Black' })]),
        g.status === 'active' ? h('div', { className:'vc-overlay-actions' }, [btn(g.draw_offer && g.draw_offer !== me().id ? 'Accept draw' : g.draw_offer === me().id ? 'Draw offered' : 'Offer draw', function(){ send('draw', {}); }, 'ghost', { disabled:g.draw_offer === me().id }), btn('Resign', function(){ A.confirmDialog ? A.confirmDialog('Resign this game?', { okText:'Resign', danger:true }).then(function(ok){ if (ok) send('resign', {}); }) : send('resign', {}); }, 'ghost danger')]) : btn('Back to games', function(){ go('arcade', 'chess'); }, 'primary'));
      if (g.status === 'active' && !g.your_turn) schedule();
    }
    function schedule(){ clearTimeout(pollTimer); pollTimer = setTimeout(function(){ if (!body.isConnected) return; if (document.hidden) return schedule(); call('/play/chess/' + id).then(function(j){ if (!body.isConnected) return; if (j.game.updated_at !== game.updated_at || j.game.your_turn !== game.your_turn) show(j.game); else schedule(); }).catch(schedule); }, 5000); }
    function send(action, payload){
      if (sending) return; sending = true;
      call('/play/chess/' + id + '/' + action, { method:'POST', body:payload }).then(function(j){ sending = false; show(j.game); }).catch(function(e){ sending = false; err(e); if (game) show(game); });
    }
    call('/play/chess/' + id).then(function(j){ show(j.game); }).catch(function(e){ body.replaceChildren(empty('Game not found', (e && e.error) || '')); });
  }
  function chessAi(body, C){
    var pref = {}; try { pref = JSON.parse(localStorage.getItem('vaultcast-chess-ai') || '{}'); } catch (e) {}
    var level = pref.level || 2, colour = pref.colour || 'w', s, history, moves, thinking = false, over = false, reported = false;
    var board = chessBoard(C, { onMove:function(m){ play(m); } }), side = h('aside', { className:'vc-chess-side' });
    body.appendChild(h('div', { className:'vc-chess-layout' }, [h('div', { className:'vc-chess-board-wrap' }, [board.el]), side]));
    function key(){ return C.fen(s).split(' ').slice(0, 4).join(' '); }
    function reset(){ s = C.parse(C.START); history = [key()]; moves = []; over = false; reported = false; draw(); if (colour === 'b') ai(); }
    function result(){ var o = C.outcome(s, history); if (!o) return ''; over = true; return o; }
    function draw(){
      var o = over ? C.outcome(s, history) : '', text;
      if (o === 'checkmate') text = s.turn === colour ? 'Checkmate. The computer wins.' : 'Checkmate! You win.';
      else if (o) text = 'Draw (' + o + ').';
      else text = thinking ? 'Computer is thinking…' : (C.inCheck(s, s.turn) ? 'Check! ' : '') + (s.turn === colour ? 'Your move' : '');
      board.set(C.fen(s), !over && !thinking && s.turn === colour ? C.legal(s).map(C.uci) : [], moves[moves.length - 1], colour === 'b', !over && !thinking && s.turn === colour);
      side.replaceChildren(
        h('label', { className:'vc-field' }, [h('span', { text:'Difficulty' }), seg([[1, 'Casual'], [2, 'Club'], [3, 'Strong']], level, function(v){ level = v; save(); draw(); }, 'Difficulty')]),
        h('label', { className:'vc-field' }, [h('span', { text:'Your colour' }), seg([['w', 'White'], ['b', 'Black']], colour, function(v){ colour = v; save(); reset(); }, 'Colour')]),
        h('p', { className:'vc-chess-status your-turn', 'aria-live':'polite', text:text }),
        moveList(moves),
        h('div', { className:'vc-overlay-actions' }, [btn('New game', function(){ reset(); }, over ? 'primary' : 'ghost'), !over && moves.length >= 2 && !thinking ? btn('Undo', function(){ var back = s.turn === colour ? 2 : 1; s = C.parse(C.START); moves = moves.slice(0, moves.length - back); history = [key()]; moves.forEach(function(m){ s = apply(m); history.push(key()); }); draw(); }, 'ghost') : null]));
      if (over && !reported) report(o);
    }
    function apply(u){ var m = C.legal(s).filter(function(x){ return C.uci(x) === u; })[0]; return C.apply(s, m); }
    function save(){ try { localStorage.setItem('vaultcast-chess-ai', JSON.stringify({ level:level, colour:colour })); } catch (e) {} }
    function play(u){ if (over) return; s = apply(u); moves.push(u); history.push(key()); result(); draw(); if (!over) ai(); }
    function ai(){
      thinking = true; draw();
      setTimeout(function(){ if (!body.isConnected) return; var m = C.bestMove(s, level); thinking = false; if (!m) { result(); draw(); return; } s = C.apply(s, m); moves.push(C.uci(m)); history.push(key()); result(); draw(); }, 250);
    }
    function report(o){
      reported = true; if (!signed()) return;
      var res = o === 'checkmate' ? (s.turn === colour ? 'loss' : 'win') : 'draw';
      if (res === 'loss') return;
      call('/play/chess/ai', { method:'POST', body:{ result:res, level:level, moves:Math.ceil(moves.length / 2) } }).then(function(j){ if (j.points) A.toast('+' + j.points + ' points', { kind:'ok' }); }).catch(function(){});
    }
    reset();
  }

  // ------------------------------------------------------------------ Quiz
  var SHAPES = ['▲', '◆', '●', '■'];
  function store(k, v){ try { if (v === undefined) return JSON.parse(localStorage.getItem(k) || 'null'); if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, JSON.stringify(v)); } catch (e) { return null; } }
  function img(src){ return A.apiUrl ? A.apiUrl(src) : src; }
  /** Quiz sounds: short synthesized tones, muted with one switch that is remembered on this device. */
  var qAudio = null;
  function quizSoundOn(){ try { return localStorage.getItem('vaultcast-quiz-sound') !== 'off'; } catch (e) { return true; } }
  function tone(freq, dur, type, vol, delay){
    if (!quizSoundOn() || document.hidden) return;
    try {
      qAudio = qAudio || new (window.AudioContext || window.webkitAudioContext)(); if (qAudio.state === 'suspended') qAudio.resume();
      var o = qAudio.createOscillator(), g = qAudio.createGain(), t = qAudio.currentTime + (delay || 0);
      o.type = type || 'sine'; o.frequency.setValueAtTime(freq, t); g.gain.setValueAtTime(vol || 0.06, t); g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
      o.connect(g); g.connect(qAudio.destination); o.start(t); o.stop(t + dur + 0.02);
    } catch (e) {}
  }
  var SOUNDS = { question:function(){ tone(523, .12); tone(784, .18, 'sine', .06, .12); }, tick:function(){ tone(880, .05, 'square', .025); }, reveal:function(){ tone(659, .15, 'triangle'); tone(988, .3, 'triangle', .06, .15); }, right:function(){ tone(784, .1, 'triangle'); tone(1175, .2, 'triangle', .06, .1); }, wrong:function(){ tone(220, .25, 'sawtooth', .04); }, join:function(){ tone(1046, .07, 'sine', .04); }, final:function(){ [523, 659, 784, 1046].forEach(function(f, i){ tone(f, .25, 'triangle', .06, i * .14); }); } };
  function muteBtn(){ var b = btn(quizSoundOn() ? '🔊' : '🔇', function(){ try { localStorage.setItem('vaultcast-quiz-sound', quizSoundOn() ? 'off' : 'on'); } catch (e) {} b.textContent = quizSoundOn() ? '🔊' : '🔇'; b.setAttribute('aria-label', quizSoundOn() ? 'Mute quiz sounds' : 'Turn quiz sounds on'); }, 'ghost vc-icon-btn', { 'aria-label':quizSoundOn() ? 'Mute quiz sounds' : 'Turn quiz sounds on' }); return b; }
  function playerChip(p, extra){ return h('span', { className:'vc-player-chip pop' }, [avatar(p, 24), h('span', { text:p.name }), p.streak >= 3 ? h('span', { className:'vc-streak', title:p.streak + ' right in a row', text:'🔥' + p.streak }) : null].concat(extra || [])); }
  function quiz(host, sub){
    var parts = String(sub || '').split('/');
    if (parts[0] === 'host' && /^[A-Za-z]{4}$/.test(parts[1] || '')) return quizHost(host, parts[1].toUpperCase());
    if (parts[0] === 'daily') return quizDaily(host);
    if (parts[0] === 'practice') return quizPractice(host);
    if (parts[0] === 'new') return quizSetup(host);
    if (/^[A-Za-z]{4}$/.test(parts[0] || '')) return quizPlayer(host, parts[0].toUpperCase());
    host.appendChild(hero('Quiz night', 'Run a quiz on the big screen while everyone answers on their phones. Questions come from your own library, a built-in bank and optionally Open Trivia DB.', [btn('Leaderboard', function(){ go('leaderboards', 'quiz'); }, 'ghost')], 'PLAY'));
    var code = h('input', { className:'vc-code-input', maxlength:'4', autocomplete:'off', autocapitalize:'characters', spellcheck:'false', placeholder:'ABCD', 'aria-label':'Room code', onInput:function(){ code.value = code.value.toUpperCase().replace(/[^A-Z]/g, ''); } });
    var grid = h('div', { className:'vc-quiz-hub' }, [
      h('article', { className:'vc-quiz-card host' }, [h('span', { className:'vc-quiz-emoji', text:'📺' }), h('h2', { text:'Host on this screen' }), h('p', { className:'muted', text:'Pick categories, rounds and a timer. Players scan the QR code to join.' }), signed() ? btn('Set up a quiz', function(){ go('quiz', 'new'); }, 'primary') : h('p', { className:'muted', text:'Sign in to host.' })]),
      h('form', { className:'vc-quiz-card join', onSubmit:function(e){ e.preventDefault(); if (code.value.length === 4) go('quiz', code.value); else code.focus(); } }, [h('span', { className:'vc-quiz-emoji', text:'📱' }), h('h2', { text:'Join a quiz' }), h('p', { className:'muted', text:'Enter the four-letter code shown on the host screen.' }), h('div', { className:'vc-join-row' }, [code, h('button', { type:'submit', className:'btn primary', text:'Join' })])]),
      h('article', { className:'vc-quiz-card' }, [h('span', { className:'vc-quiz-emoji', text:'📅' }), h('h2', { text:'Daily quiz' }), h('p', { className:'muted', text:'Ten questions, the same for everyone today.' }), signed() ? btn('Play today\u2019s quiz', function(){ go('quiz', 'daily'); }, 'primary') : h('p', { className:'muted', text:'Sign in to play.' })]),
      h('article', { className:'vc-quiz-card' }, [h('span', { className:'vc-quiz-emoji', text:'🎯' }), h('h2', { text:'Practice' }), h('p', { className:'muted', text:'Warm up on any category. No points, no pressure.' }), btn('Practise', function(){ go('quiz', 'practice'); }, 'ghost')])
    ]);
    host.appendChild(grid);
    var mine = []; try { mine = Object.keys(localStorage); } catch (e) {} mine = mine.filter(function(k){ return k.indexOf('vaultcast-quiz-host:') === 0; });
    if (mine.length) host.appendChild(panel('Your open rooms', [h('div', { className:'vc-chip-row' }, mine.map(function(k){ var c = k.slice(20); return btn('Room ' + c, function(){ go('quiz', 'host/' + c); }, 'ghost'); }))]));
  }
  function quizSetup(host){
    host.appendChild(h('div', { className:'vc-game-bar' }, [btn('← Quiz', function(){ go('quiz'); }, 'ghost'), h('div', { className:'vc-game-title' }, [h('h1', { text:'Set up a quiz' })])]));
    if (!signed()) { host.appendChild(empty('Sign in to host', '')); return; }
    var box = h('div', { className:'vc-quiz-setup' }, [A.pendingContent({ text:'Loading categories…' })]); host.appendChild(box);
    call('/play/quiz/options').then(function(o){ settingsForm(box, o, store('vaultcast-quiz-settings') || {}, 'Create room', function(s){
      store('vaultcast-quiz-settings', s);
      return call('/play/quiz/rooms', { method:'POST', body:{ settings:s } }).then(function(j){ store('vaultcast-quiz-host:' + j.code, j.host_token); go('quiz', 'host/' + j.code); });
    }); }).catch(function(e){ box.replaceChildren(empty('Quiz unavailable', (e && e.error) || '')); });
  }
  function settingsForm(box, o, s, label, submit){
    s = Object.assign({ title:'Quiz night', mode:'solo', teams:2, rounds:3, per_round:5, timer:20, auto:true, categories:['general', 'lib-frame', 'science'] }, s);
    var chosen = s.categories.slice();
    function num(name, min, max, v, hint){ return h('label', { className:'vc-field' }, [h('span', { text:name }), h('input', { type:'number', min:String(min), max:String(max), value:String(v), 'data-k':hint, inputmode:'numeric' })]); }
    var cats = h('div', { className:'vc-cat-groups' });
    var groups = {}; o.categories.forEach(function(c){ (groups[c.group] = groups[c.group] || []).push(c); });
    Object.keys(groups).forEach(function(g){ cats.appendChild(h('fieldset', { className:'vc-cat-group' }, [h('legend', { text:g })].concat(groups[g].map(function(c){ var on = chosen.indexOf(c.id) >= 0; return h('button', { type:'button', className:'vc-cat' + (on ? ' active' : ''), 'aria-pressed':String(on), text:c.name, onClick:function(e){ var i = chosen.indexOf(c.id); if (i >= 0) chosen.splice(i, 1); else if (chosen.length < 8) chosen.push(c.id); else A.toast('Up to 8 categories'); e.currentTarget.classList.toggle('active', chosen.indexOf(c.id) >= 0); e.currentTarget.setAttribute('aria-pressed', String(chosen.indexOf(c.id) >= 0)); } }); })))); });
    var mode = s.mode, modeSeg = h('div'), teamsField = num('Teams', 2, 4, s.teams, 'teams');
    function drawMode(){ modeSeg.replaceChildren(seg([['solo', 'Everyone for themselves'], ['teams', 'Teams']], mode, function(v){ mode = v; drawMode(); }, 'Mode')); teamsField.hidden = mode !== 'teams'; }
    drawMode();
    var title = h('input', { type:'text', maxlength:'60', value:s.title, 'aria-label':'Quiz title' }), auto = h('input', { type:'checkbox', checked:s.auto ? true : null });
    var go2 = h('button', { type:'submit', className:'btn primary', text:label });
    var form = h('form', { className:'vc-form', onSubmit:function(e){
      e.preventDefault(); if (!chosen.length) { A.toast('Choose at least one category'); return; }
      var out = { title:title.value, mode:mode, categories:chosen, auto:auto.checked };
      form.querySelectorAll('input[data-k]').forEach(function(i){ out[i.getAttribute('data-k')] = +i.value; });
      go2.disabled = true; Promise.resolve(submit(out)).catch(err).then(function(){ go2.disabled = false; });
    } }, [
      h('label', { className:'vc-field' }, [h('span', { text:'Title' }), title]),
      h('div', { className:'vc-field' }, [h('span', { text:'Mode' }), modeSeg]),
      h('div', { className:'vc-field-row' }, [teamsField, num('Rounds', 1, 8, s.rounds, 'rounds'), num('Questions per round', 2, 15, s.per_round, 'per_round'), num('Seconds per question', 8, 90, s.timer, 'timer')]),
      h('div', { className:'vc-field' }, [h('span', { text:'Categories (one per round, in order; they repeat if there are more rounds)' }), cats]),
      h('label', { className:'vc-check' }, [auto, h('span', { text:'Advance automatically after each reveal' })]),
      h('div', { className:'vc-overlay-actions' }, [go2])
    ]);
    box.replaceChildren(form);
  }
  function timerRing(endsAt, total, offset){
    var el = h('div', { className:'vc-timer', role:'timer' }), raf = 0;
    el.innerHTML = '<svg viewBox="0 0 44 44" aria-hidden="true"><circle cx="22" cy="22" r="19" class="bg"/><circle cx="22" cy="22" r="19" class="fg"/></svg><span></span>';
    var fg = el.querySelector('.fg'), label = el.querySelector('span'), C2 = 2 * Math.PI * 19;
    fg.style.strokeDasharray = C2;
    (function tick(){ if (!el.isConnected && raf) return; var left = Math.max(0, endsAt - (Date.now() / 1000 + offset)); label.textContent = Math.ceil(left); fg.style.strokeDashoffset = String(C2 * (1 - left / total)); el.classList.toggle('low', left <= 5); if (left > 0) raf = requestAnimationFrame(tick); })();
    return el;
  }
  function choiceButtons(q, opts){
    return h('div', { className:'vc-choices' + (opts.big ? ' big' : '') }, q.choices.map(function(c, i){
      var cls = 'vc-choice c' + i + (q.answer != null ? (i === q.answer ? ' right' : ' wrong') : '') + (opts.chosen === i ? ' chosen' : '');
      var total = q.stats ? q.stats.reduce(function(a, b){ return a + b; }, 0) : 0, n = q.stats ? q.stats[i] || 0 : 0;
      var stat = q.stats ? h('span', { className:'vc-choice-stat', 'aria-label':n + ' of ' + total + ' chose this' }, [h('span', { className:'vc-choice-stat-bar', style:'width:' + (total ? Math.round(100 * n / total) : 0) + '%' }), h('span', { className:'vc-choice-stat-n', text:String(n) })]) : null;
      return h(opts.onPick ? 'button' : 'div', { type:opts.onPick ? 'button' : null, className:cls, disabled:opts.onPick && (opts.locked || q.answer != null) ? true : null, onClick:opts.onPick ? function(){ opts.onPick(i); } : null }, [h('span', { className:'vc-shape', text:SHAPES[i], 'aria-hidden':'true' }), h('span', { className:'vc-choice-text', text:c }), stat]);
    }));
  }
  function scoreboard(players, teams, mode, animate){
    var list = h('ol', { className:'vc-scoreboard' }), max = Math.max(1, players[0] ? players[0].score : 1);
    var rows = (mode === 'teams' && teams.length ? teams.map(function(t){ return { name:t.name + ' (' + t.members + ')', score:t.score, previous:t.score, colour:t.colour }; }) : players.filter(function(p){ return !p.left; })).slice(0, 10);
    max = Math.max(1, rows.reduce(function(m, r){ return Math.max(m, r.score); }, 1));
    rows.forEach(function(p, i){
      var bar = h('span', { className:'vc-sb-bar', style:'width:' + (animate ? (100 * (p.previous || 0) / max) : (100 * p.score / max)) + '%' + (p.colour ? ';background:' + p.colour : '') });
      var num = h('strong', { className:'vc-sb-score', text:fmt(animate ? p.previous || 0 : p.score) });
      list.appendChild(h('li', { className:'vc-sb-row', style:'animation-delay:' + (i * 70) + 'ms' }, [h('span', { className:'vc-sb-rank', text:String(i + 1) }), h('span', { className:'vc-sb-name', text:p.name }), h('span', { className:'vc-sb-track' }, [bar]), num]));
      if (animate) setTimeout(function(){ bar.style.width = (100 * p.score / max) + '%'; countUp(num, p.previous || 0, p.score, 900); }, 250 + i * 70);
    });
    return list;
  }
  function countUp(el, from, to, ms){ var t0 = performance.now(); (function f(t){ var k = Math.min(1, (t - t0) / ms); el.textContent = fmt(Math.round(from + (to - from) * (1 - Math.pow(1 - k, 3)))); if (k < 1) requestAnimationFrame(f); })(t0); }
  function podium(players, teams, mode){
    var rows = mode === 'teams' && teams.length ? teams.map(function(t){ return { name:t.name, score:t.score, colour:t.colour }; }) : players.filter(function(p){ return !p.left; });
    var order = [1, 0, 2], el = h('div', { className:'vc-podium' });
    order.forEach(function(i){ var p = rows[i]; if (!p) return; el.appendChild(h('div', { className:'vc-podium-step p' + (i + 1), style:p.colour ? '--vc-team:' + p.colour : null }, [h('span', { className:'vc-podium-medal', text:['🥇', '🥈', '🥉'][i] }), h('strong', { className:'vc-podium-name', text:p.name }), h('span', { className:'vc-podium-score', text:fmt(p.score) }), h('div', { className:'vc-podium-block', text:String(i + 1) })])); });
    return el;
  }
  function joinUrl(code){ var base = A.BASE || '/'; return location.origin + base + 'play/quiz/' + code; }
  function quizHost(host, code){
    var token = store('vaultcast-quiz-host:' + code);
    var screen = h('div', { className:'vc-quiz-screen' }), bar = h('div', { className:'vc-quiz-hostbar' });
    host.appendChild(h('div', { className:'vc-quiz-host' }, [screen, bar]));
    var st = null, v = 0, offset = 0, lastKey = '', busy = false, options = null, seen = {}, tickTimer = 0;
    function act(action, extra){ if (busy) return Promise.resolve(); busy = true; return call('/play/quiz/rooms/' + code + '/host', { method:'POST', body:Object.assign({ action:action, host_token:token }, extra || {}) }).then(function(j){ busy = false; apply(j); }).catch(function(e){ busy = false; err(e); }); }
    function poll(){
      clearTimeout(pollTimer);
      if (!screen.isConnected) return;
      api('/play/quiz/rooms/' + code + '?v=' + v + '&host_token=' + encodeURIComponent(token || '')).then(function(j){
        if (!screen.isConnected) return;
        if (!j.ok) { screen.replaceChildren(empty('Room closed', j.error || 'This quiz room is no longer open.', [btn('Back to Quiz', function(){ store('vaultcast-quiz-host:' + code, null); go('quiz'); }, 'primary')])); bar.replaceChildren(); return; }
        host.classList.remove('vc-offline');
        if (!j.same) apply(j); else offset = j.now - Date.now() / 1000;
        pollTimer = setTimeout(poll, document.hidden ? 4000 : 1000);
      }).catch(function(){ host.classList.add('vc-offline'); pollTimer = setTimeout(poll, 3000); });
    }
    function apply(j){
      st = j; v = j.version; offset = j.now - Date.now() / 1000;
      if (!j.is_host) { if (token) { store('vaultcast-quiz-host:' + code, null); token = null; } screen.replaceChildren(empty('Not the host screen', 'This room is controlled from another screen.', [btn('Join as a player', function(){ go('quiz', code); }, 'primary')])); bar.replaceChildren(); return; }
      if (j.prefetch) { var im = new Image(); im.src = img(j.prefetch); }
      var key = j.status + ':' + j.index;
      if (key !== lastKey) cue(j);
      if (j.status === 'lobby') { var fresh = j.players.some(function(p){ return !p.left && !seen[p.id]; }); j.players.forEach(function(p){ seen[p.id] = true; }); if (fresh && lastKey) SOUNDS.join(); }
      if (key !== lastKey || j.status === 'lobby' || j.status === 'question') { draw(key !== lastKey); lastKey = key; }
    }
    /** Sounds on the big screen: a sting for each question, ticks for the last five seconds and a chime at the reveal. */
    function cue(j){
      clearTimeout(tickTimer);
      if (j.status === 'question' && j.question) { SOUNDS.question(); (function tick(){ if (!screen.isConnected || st.status !== 'question') return; var left = j.question.ends_at - (Date.now() / 1000 + offset); if (left <= 0) return; if (left <= 5.05) SOUNDS.tick(); tickTimer = setTimeout(tick, left > 6 ? (left - 5) * 1000 : 1000); })(); }
      else if (j.status === 'reveal') SOUNDS.reveal();
      else if (j.status === 'final') SOUNDS.final();
    }
    /** Players at any point in the game: remove someone, or hand the room to a signed-in player. */
    function managePlayers(){
      window.VaultcastHub.modal('Players', function(body, close){
        var live = st.players.filter(function(p){ return !p.left; });
        if (!live.length) { body.appendChild(empty('No players yet', '')); return; }
        body.appendChild(h('div', { className:'vc-people-list' }, live.map(function(p){
          return h('div', { className:'vc-person-row' }, [avatar(p), h('span', { className:'vc-person-name' }, [h('strong', { text:p.name }), h('small', { className:'muted', text:fmt(p.score) + ' pts' + (p.signed ? '' : ' · guest') })]),
            h('div', { className:'vc-row-actions' }, [p.signed ? btn('Make host', function(){ A.confirmDialog('Hand this room to ' + p.name + '? They control it from their own device and this screen stops being the host.', { okText:'Make host' }).then(function(ok){ if (!ok) return; close(); act('transfer', { player:p.id }).then(function(){ store('vaultcast-quiz-host:' + code, null); }); }); }, 'ghost small') : null,
              btn('Remove', function(){ A.confirmDialog('Remove ' + p.name + ' from the quiz? They cannot rejoin this room.', { okText:'Remove', danger:true }).then(function(ok){ if (ok) { close(); act('kick', { player:p.id }); } }); }, 'ghost small danger')])]);
        })));
      });
    }
    function controls(){
      var s = st.status, kids = [h('span', { className:'vc-quiz-code-small', text:'Room ' + code })];
      if (s === 'lobby') kids.push(btn('Settings', editSettings, 'ghost'), btn('Start quiz', function(){ act('start'); }, 'primary', { disabled:!st.players.length }));
      else if (s === 'question') kids.push(btn('Reveal answer', function(){ act('reveal'); }, 'primary'));
      else if (s === 'intro' || s === 'reveal' || s === 'scores') kids.push(btn(st.settings.auto ? 'Next now' : 'Next', function(){ act('next'); }, 'primary'));
      else if (s === 'final') kids.push(btn('Play again', function(){ act('restart'); }, 'primary'), btn('Back to lobby', function(){ act('lobby'); }, 'ghost'));
      if (s !== 'lobby' && s !== 'final') kids.push(btn('End quiz', function(){ A.confirmDialog('End the quiz now and show the final scores?', { okText:'End quiz' }).then(function(ok){ if (ok) act('end'); }); }, 'ghost'));
      if (s !== 'closed') kids.push(btn('Players', managePlayers, 'ghost'));
      kids.push(muteBtn());
      kids.push(btn('⛶', function(){ var el = host.querySelector('.vc-quiz-host'); if (document.fullscreenElement) document.exitFullscreen(); else if (el.requestFullscreen) el.requestFullscreen().catch(function(){}); }, 'ghost vc-icon-btn', { 'aria-label':'Full screen' }));
      kids.push(btn('Close room', function(){ A.confirmDialog('Close this room? Players will be disconnected.', { okText:'Close room', danger:true }).then(function(ok){ if (ok) act('close').then(function(){ store('vaultcast-quiz-host:' + code, null); go('quiz'); }); }); }, 'ghost danger'));
      bar.replaceChildren.apply(bar, kids);
    }
    function editSettings(){
      window.VaultcastHub.modal('Quiz settings', function(body, close){
        (options ? Promise.resolve(options) : call('/play/quiz/options')).then(function(o){ options = o; settingsForm(body, o, st.settings, 'Save', function(s){ store('vaultcast-quiz-settings', s); return act('settings', { settings:s }).then(close); }); }).catch(err);
      });
    }
    function draw(changed){
      controls();
      var s = st.status, q = st.question;
      if (s === 'lobby') {
        var people = h('div', { className:'vc-lobby-players' });
        if (st.settings.mode === 'teams') {
          st.team_names.slice(0, st.settings.teams).forEach(function(t, ti){
            people.appendChild(h('div', { className:'vc-lobby-team', style:'--vc-team:' + t[1] }, [h('h3', { text:t[0] })].concat(st.players.filter(function(p){ return p.team === ti && !p.left; }).map(function(p){ return h('span', { className:'vc-player-chip' }, [avatar(p, 24), h('button', { type:'button', className:'vc-chip-move', title:'Move to the next team', 'aria-label':'Move ' + p.name + ' to the next team', text:p.name, onClick:function(){ act('team', { player:p.id, team:(ti + 1) % st.settings.teams }); } }), h('button', { type:'button', className:'vc-chip-x', 'aria-label':'Remove ' + p.name, text:'×', onClick:function(){ act('kick', { player:p.id }); } })]); }))));
          });
        } else st.players.filter(function(p){ return !p.left; }).forEach(function(p){ people.appendChild(playerChip(p, [h('button', { type:'button', className:'vc-chip-x', 'aria-label':'Remove ' + p.name, text:'×', onClick:function(){ act('kick', { player:p.id }); } })])); });
        screen.replaceChildren(h('div', { className:'vc-lobby' }, [
          h('div', { className:'vc-lobby-join' }, [h('p', { className:'eyebrow', text:'Join on your phone' }), h('div', { className:'vc-room-code', text:code }), h('p', { className:'muted vc-join-url', text:joinUrl(code).replace(/^https?:\/\//, '') }), qrBox(joinUrl(code), 'Scan to join room ' + code)]),
          h('div', { className:'vc-lobby-side' }, [h('h1', { text:st.settings.title }), h('p', { className:'muted', text:st.settings.rounds + ' rounds × ' + st.settings.per_round + ' questions · ' + st.settings.timer + ' s each · ' + (st.settings.mode === 'teams' ? st.settings.teams + ' teams' : 'solo') }),
            h('h2', { text:st.players.filter(function(p){ return !p.left; }).length + ' players' }), people.childNodes.length ? people : h('p', { className:'muted vc-pulse', text:'Waiting for players…' })])]));
      } else if (s === 'intro') {
        screen.replaceChildren(h('div', { className:'vc-quiz-intro' }, [h('p', { className:'eyebrow', text:'Get ready' }), h('h1', { text:st.settings.title }), h('ol', { className:'vc-round-list' }, st.rounds.map(function(r, i){ return h('li', { style:'animation-delay:' + (i * 120) + 'ms', text:'Round ' + (i + 1) + ': ' + r.name }); }))]));
      } else if (s === 'question' || s === 'reveal') {
        if (!changed && s === 'question') { var ans = screen.querySelector('.vc-answered'); if (ans) ans.textContent = q.answered + ' / ' + st.players.filter(function(p){ return !p.left; }).length + ' answered'; return; }
        var media = q.image ? h('img', { className:'vc-quiz-image', src:img(q.image), alt:'Film frame' }) : q.clue ? h('blockquote', { className:'vc-quiz-clue', text:q.clue }) : null;
        screen.replaceChildren(h('div', { className:'vc-quiz-q ' + s }, [
          h('div', { className:'vc-quiz-meta' }, [h('span', { className:'vc-chip', text:'Round ' + (q.round + 1) + ' · ' + q.round_name }), h('span', { className:'muted', text:'Question ' + (q.n + 1) + ' of ' + st.total }), s === 'question' ? timerRing(q.ends_at, q.timer, offset) : null]),
          h('h1', { className:'vc-quiz-question', text:q.question }), media,
          choiceButtons(q, { big:true }),
          s === 'question' ? h('p', { className:'vc-answered muted', text:q.answered + ' / ' + st.players.filter(function(p){ return !p.left; }).length + ' answered' }) : h('div', { className:'vc-reveal-board' }, [h('h2', { text:'Leaders' }), scoreboard(st.players.slice(0, 5), st.teams, st.settings.mode, true)])
        ]));
      } else if (s === 'scores') {
        screen.replaceChildren(h('div', { className:'vc-quiz-scores' }, [h('p', { className:'eyebrow', text:'End of round ' + (q ? q.round + 1 : '') }), h('h1', { text:'Scoreboard' }), scoreboard(st.players, st.teams, st.settings.mode, true)]));
      } else if (s === 'final') {
        screen.replaceChildren(h('div', { className:'vc-quiz-final' }, [h('p', { className:'eyebrow', text:'Final results' }), h('h1', { text:st.settings.title }), podium(st.players, st.teams, st.settings.mode), scoreboard(st.players, st.teams, st.settings.mode, false)]));
        confetti(screen);
      } else screen.replaceChildren(empty('Room closed', ''));
    }
    // A signed-in host (including one the room was handed to) is recognised by account, so no token is needed.
    if (!token && !signed()) { screen.appendChild(empty('Not the host screen', 'Open this room from the device that created it, or join as a player.', [btn('Join as a player', function(){ go('quiz', code); }, 'primary')])); return; }
    screen.appendChild(A.pendingContent({ text:'Opening room…' }));
    poll();
  }
  function confetti(host){
    if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var layer = h('div', { className:'vc-confetti', 'aria-hidden':'true' });
    for (var i = 0; i < 80; i++) layer.appendChild(h('i', { style:'left:' + Math.random() * 100 + '%;animation-delay:' + Math.random() * 1.5 + 's;animation-duration:' + (2.5 + Math.random() * 2) + 's;--vc-c:' + Math.floor(Math.random() * 360) }));
    host.appendChild(layer); setTimeout(function(){ layer.remove(); }, 6000);
  }
  function quizPlayer(host, code){
    var box = h('div', { className:'vc-quiz-phone' }); host.appendChild(box);
    var cred = store('vaultcast-quiz-player:' + code), v = 0, st = null, lastKey = '', sending = false, offset = 0, fails = 0;
    var banner = h('p', { className:'vc-offline-banner', role:'status', hidden:true, text:'Connection lost. Reconnecting…' });
    function offline(on){ banner.hidden = !on; if (on && !banner.isConnected) box.prepend(banner); }
    // Coming back to the tab (or the network) reconnects at once instead of waiting for the next slow poll.
    function wake(){ if (!box.isConnected) { document.removeEventListener('visibilitychange', wake); window.removeEventListener('online', wake); return; } if (!document.hidden && cred && st) poll(); }
    document.addEventListener('visibilitychange', wake); window.addEventListener('online', wake);
    function q(){ return cred ? '&player=' + encodeURIComponent(cred.player) + '&player_token=' + encodeURIComponent(cred.token) : ''; }
    function joinForm(msg){
      var name = h('input', { type:'text', maxlength:'24', placeholder:'Your name', 'aria-label':'Your name', value:signed() ? (me().display_name || me().username || '') : '', disabled:signed() ? true : null });
      box.replaceChildren(h('form', { className:'vc-join-card', onSubmit:function(e){ e.preventDefault(); join(name.value); } }, [h('p', { className:'eyebrow', text:'Room ' + code }), h('h1', { text:'Join the quiz' }), msg ? h('p', { className:'muted', text:msg }) : null, name, h('button', { type:'submit', className:'btn primary', text:'Join' })]));
      if (!signed()) name.focus();
    }
    function join(name, team){
      call('/play/quiz/rooms/' + code + '/join', { method:'POST', body:Object.assign({ name:name }, team != null ? { team:team } : {}, cred ? { player:cred.player, player_token:cred.token } : {}) }).then(function(j){ cred = { player:j.player, token:j.player_token }; store('vaultcast-quiz-player:' + code, cred); apply(j); poll(); }).catch(function(e){ if (!st) joinForm((e && e.error) || ''); else err(e); });
    }
    function poll(){
      clearTimeout(pollTimer); if (!box.isConnected) return;
      api('/play/quiz/rooms/' + code + '?v=' + v + q()).then(function(j){
        if (!box.isConnected) return;
        if (!j.ok) { box.replaceChildren(empty('Room not open', j.error || '', [btn('Back', function(){ go('quiz'); }, 'primary')])); return; }
        fails = 0; offline(false);
        if (!j.same) apply(j); else offset = j.now - Date.now() / 1000;
        pollTimer = setTimeout(poll, document.hidden ? 4000 : 1000);
      }).catch(function(){ fails++; offline(true); pollTimer = setTimeout(poll, Math.min(10000, 1500 * fails)); });
    }
    function apply(j){
      st = j; v = j.version; offset = j.now - Date.now() / 1000;
      if (!j.me) { if (j.status === 'closed') box.replaceChildren(empty('This quiz has finished', '')); else joinForm(); return; }
      if (j.me.kicked) { box.replaceChildren(empty('The host removed you from this quiz', 'You can still watch the big screen.', [btn('Back to Quiz', function(){ store('vaultcast-quiz-player:' + code, null); go('quiz'); }, 'primary')])); return; }
      if (j.me.left) { box.replaceChildren(empty('You left this room', '', [btn('Rejoin', function(){ join(j.me.name); }, 'primary')])); return; }
      var key = j.status + ':' + j.index + ':' + (j.me.answer ? 1 : 0) + ':' + j.me.team + ':' + (j.is_host ? 1 : 0);
      if (key === lastKey) return;
      var changedStatus = lastKey.split(':').slice(0, 2).join(':') !== j.status + ':' + j.index; lastKey = key;
      var s = j.status, qq = j.question, team = j.me.team != null && j.settings.mode === 'teams' ? j.team_names[j.me.team] : null;
      var head = h('div', { className:'vc-phone-head', style:team ? '--vc-team:' + team[1] : null }, [h('strong', { text:j.me.name }), team ? h('span', { className:'vc-chip', text:team[0] }) : null, j.me.streak >= 2 ? h('span', { className:'vc-streak', text:'🔥 ' + j.me.streak + ' in a row' }) : null, h('span', { className:'muted', text:fmt(j.me.score) + ' pts' }), muteBtn()]);
      var hostLink = j.is_host ? h('p', { className:'vc-host-note' }, ['You are now the host. ', btn('Open host controls', function(){ go('quiz', 'host/' + code); }, 'primary small')]) : null;
      var main;
      if (s === 'lobby') main = h('div', { className:'vc-phone-wait' }, [h('h1', { text:'You\u2019re in!' }), h('p', { className:'muted vc-pulse', text:'Waiting for ' + j.host_name + ' to start…' }),
        h('div', { className:'vc-lobby-mini' }, j.players.filter(function(p){ return !p.left; }).slice(0, 24).map(function(p){ return h('span', { className:'vc-lobby-face', title:p.name }, [avatar(p, 28)]); })),
        j.settings.mode === 'teams' ? h('div', { className:'vc-field' }, [h('span', { text:'Pick your team' }), h('div', { className:'vc-team-pick' }, j.team_names.slice(0, j.settings.teams).map(function(t, i){ return h('button', { type:'button', className:'vc-team-btn' + (j.me.team === i ? ' active' : ''), style:'--vc-team:' + t[1], text:t[0], onClick:function(){ join(j.me.name, i); } }); }))]) : null]);
      else if (s === 'intro') main = h('div', { className:'vc-phone-wait' }, [h('h1', { text:'Get ready…' }), h('p', { className:'muted', text:j.rounds.length + ' rounds coming up' })]);
      else if (s === 'question') main = j.me.answer ? h('div', { className:'vc-phone-wait' }, [h('h1', { text:'Locked in' }), h('span', { className:'vc-shape-big c' + j.me.answer.c, text:SHAPES[j.me.answer.c] }), h('p', { className:'muted', text:'Waiting for everyone else…' })])
        : h('div', { className:'vc-phone-q' }, [h('div', { className:'vc-quiz-meta' }, [h('span', { className:'muted', text:'Q' + (qq.n + 1) + ' / ' + j.total }), timerRing(qq.ends_at, qq.timer, offset)]), h('p', { className:'vc-phone-question', text:qq.question }), choiceButtons(qq, { onPick:answer, locked:sending })]);
      else if (s === 'reveal') { var a = j.me.answer || {}; main = h('div', { className:'vc-phone-result ' + (a.ok ? 'ok' : 'bad') }, [h('h1', { text:a.ok ? 'Correct!' : (a.c === -1 || a.c == null ? 'Too slow' : 'Not quite') }), a.ok ? h('p', { className:'vc-phone-points', text:'+' + fmt(a.pts) }) : h('p', { className:'muted', text:'Answer: ' + qq.choices[qq.answer] }), a.ok && j.me.streak >= 2 ? h('p', { className:'vc-streak', text:'🔥 ' + j.me.streak + ' in a row' }) : null, h('p', { className:'muted', text:'You are #' + j.me.rank }), qq.stats ? choiceButtons(qq, { chosen:a.c }) : null]); }
      else if (s === 'scores') main = h('div', { className:'vc-phone-wait' }, [h('h1', { text:'#' + j.me.rank }), h('p', { className:'muted', text:fmt(j.me.score) + ' points so far' })]);
      else if (s === 'final') main = h('div', { className:'vc-phone-wait' }, [h('p', { className:'eyebrow', text:'Final' }), h('h1', { text:j.me.rank === 1 ? '🏆 You won!' : 'You finished #' + j.me.rank }), h('p', { className:'muted', text:fmt(j.me.score) + ' points' }), signed() ? btn('Leaderboard', function(){ go('leaderboards', 'quiz'); }, 'ghost') : null]);
      else main = empty('This quiz has finished', '');
      box.replaceChildren.apply(box, [head, hostLink, main].filter(Boolean)); if (!banner.hidden) box.prepend(banner);
      if (changedStatus) { if (s === 'reveal') ((j.me.answer || {}).ok ? SOUNDS.right : SOUNDS.wrong)(); else if (s === 'final' && j.me.rank === 1) SOUNDS.final(); }
      if (s === 'reveal' && navigator.vibrate) try { navigator.vibrate((j.me.answer || {}).ok ? 60 : [40, 60, 40]); } catch (e) {}
    }
    function answer(i){
      if (sending) return; sending = true;
      call('/play/quiz/rooms/' + code + '/answer', { method:'POST', body:{ choice:i, player:cred.player, player_token:cred.token } }).then(function(j){ sending = false; apply(j); }).catch(function(e){ sending = false; err(e); lastKey = ''; apply(st); });
    }
    box.appendChild(A.pendingContent({ text:'Connecting…' }));
    if (cred) poll(); else api('/play/quiz/rooms/' + code + '?v=0').then(function(j){ if (!j.ok) box.replaceChildren(empty('Room not found', j.error || 'Check the code on the host screen.', [btn('Back', function(){ go('quiz'); }, 'primary')])); else if (signed()) join(''); else joinForm(); });
  }
  function soloRun(box, questions, opts){
    var i = opts.start || 0, right = opts.correct || 0, timer = 0;
    function show(){
      clearTimeout(timer);
      if (i >= questions.length) { opts.done(right); return; }
      var q = questions[i], locked = false, t0 = Date.now();
      var media = q.image ? h('img', { className:'vc-quiz-image', src:img(q.image), alt:'Film frame' }) : q.clue ? h('blockquote', { className:'vc-quiz-clue', text:q.clue }) : null;
      var choices = choiceButtons({ choices:q.choices }, { onPick:pick, big:true }), ring = timerRing(Date.now() / 1000 + 25, 25, 0);
      box.replaceChildren(h('div', { className:'vc-quiz-q' }, [h('div', { className:'vc-quiz-meta' }, [h('span', { className:'vc-chip', text:q.category || 'Quiz' }), h('span', { className:'muted', text:(i + 1) + ' / ' + questions.length + ' · ' + right + ' correct' }), ring]), h('h1', { className:'vc-quiz-question', text:q.question }), media, choices]));
      timer = setTimeout(function(){ pick(-1); }, 25000);
      function pick(c){
        if (locked) return; locked = true; clearTimeout(timer);
        Promise.resolve(opts.answer(q, c, Date.now() - t0)).then(function(ans){
          if (ans === c) right++;
          var rendered = choiceButtons({ choices:q.choices, answer:ans }, { chosen:c, big:true }); choices.replaceWith(rendered); ring.remove();
          i++; timer = setTimeout(show, ans === c ? 1300 : 2400);
        }).catch(function(e){ err(e); locked = false; });
      }
    }
    show();
    current = { destroy:function(){ clearTimeout(timer); } };
  }
  function quizDaily(host){
    host.appendChild(h('div', { className:'vc-game-bar' }, [btn('← Quiz', function(){ go('quiz'); }, 'ghost'), h('div', { className:'vc-game-title' }, [h('h1', { text:'Daily quiz' }), h('span', { className:'muted', text:'Ten questions, one go each, same for everyone today.' })])]));
    if (!signed()) { host.appendChild(empty('Sign in to play', '')); return; }
    var box = h('div', { className:'vc-quiz-solo' }, [A.pendingContent({ text:'Loading…' })]); host.appendChild(box);
    function result(j){
      // Count from the questions themselves so a missing or non-numeric total can never render as "false/10".
      var qs = j.questions || [], answered = qs.filter(function(q){ return typeof q.chosen === 'number'; }), right = answered.filter(function(q){ return q.chosen === q.answer; }).length;
      var total = Number(j.total) || qs.length, score = typeof j.correct === 'number' ? j.correct : right;
      if (!answered.length) { box.replaceChildren(empty('Not played yet', 'Today\u2019s quiz is waiting.', [btn('Back to Quiz', function(){ go('quiz'); }, 'primary')])); return; }
      var squares = qs.map(function(q){ return typeof q.chosen !== 'number' ? '⬜' : q.chosen === q.answer ? '🟩' : '🟥'; }).join('');
      var text = 'Vaultcast daily quiz ' + j.date + ': ' + score + '/' + total + '\n' + squares;
      box.replaceChildren(h('div', { className:'vc-solo-result' }, [h('p', { className:'eyebrow', text:'Today\u2019s score' }), h('div', { className:'vc-final-score', text:score + '/' + total }), h('p', { className:'vc-emoji-row', text:squares }),
        h('div', { className:'vc-overlay-actions' }, [btn('Share', function(){ if (navigator.share) navigator.share({ text:text }).catch(function(){}); else if (navigator.clipboard) navigator.clipboard.writeText(text).then(function(){ A.toast('Copied', { kind:'ok' }); }); }, 'primary'), btn('Leaderboard', function(){ go('leaderboards', 'quiz'); }, 'ghost')]),
        h('ol', { className:'vc-review' }, answered.map(function(q){ return h('li', { className:q.chosen === q.answer ? 'ok' : 'bad' }, [h('strong', { text:q.question }), h('span', { className:'muted', text:' ' + q.choices[q.answer] + (q.chosen !== q.answer ? ' (you: ' + (q.chosen >= 0 ? q.choices[q.chosen] : 'no answer') + ')' : '') })]); }))]));
    }
    call('/play/quiz/daily').then(function(j){
      if (j.done) return result(j);
      var last = j;
      soloRun(box, j.questions, { start:Number(j.answered) || 0, correct:Number(j.correct) || 0, answer:function(q, c){ return call('/play/quiz/daily/answer', { method:'POST', body:{ n:q.n, choice:c } }).then(function(r){ last = r; return r.answer; }); }, done:function(){ result(last); } });
    }).catch(function(e){ box.replaceChildren(empty('Daily quiz unavailable', (e && e.error) || '')); });
  }
  function quizPractice(host){
    host.appendChild(h('div', { className:'vc-game-bar' }, [btn('← Quiz', function(){ go('quiz'); }, 'ghost'), h('div', { className:'vc-game-title' }, [h('h1', { text:'Practice' }), h('span', { className:'muted', text:'No points: just for fun.' })])]));
    var box = h('div', { className:'vc-quiz-solo' }, [A.pendingContent({ text:'Loading categories…' })]); host.appendChild(box);
    call('/play/quiz/options').then(function(o){
      box.replaceChildren(h('div', { className:'vc-cat-groups' }, o.categories.filter(function(c){ return c.id !== 'lib-frame'; }).map(function(c){ return h('button', { type:'button', className:'vc-cat', text:c.name, onClick:function(){
        box.replaceChildren(A.pendingContent({ text:'Shuffling questions…' }));
        call('/play/quiz/practice?category=' + encodeURIComponent(c.id) + '&count=10').then(function(j){ soloRun(box, j.questions, { answer:function(q){ return q.answer; }, done:function(n){ box.replaceChildren(h('div', { className:'vc-solo-result' }, [h('div', { className:'vc-final-score', text:n + '/' + j.questions.length }), h('div', { className:'vc-overlay-actions' }, [btn('Another round', function(){ go('quiz', 'practice'); }, 'primary'), btn('Back to Quiz', function(){ go('quiz'); }, 'ghost')])])); } }); }).catch(err);
      } }); })));
    }).catch(function(e){ box.replaceChildren(empty('Practice unavailable', (e && e.error) || '')); });
  }

  // ------------------------------------------------------------------ Teams & friends
  function social(host, sub){
    var parts = String(sub || '').split('/'), tab = parts[0] || 'overview';
    host.appendChild(hero('Teams & friends', 'Build a crew, challenge friends and collect badges. Every point you earn also counts for your team.', null, 'PLAY'));
    if (!signed()) { host.appendChild(empty('Sign in to join teams', 'Teams, friends and profiles need an account.')); return; }
    if (tab === 'join' && /^[a-f0-9]{20}$/.test(parts[1] || '')) return joinTeamLink(host, parts[1]);
    var tabs = [['overview', 'Overview'], ['teams', 'Teams'], ['friends', 'Friends'], ['profile', 'My profile'], ['privacy', 'Privacy']];
    if (me().role === 'admin') tabs.push(['admin', 'Moderation']);
    var badges = {};
    host.appendChild(h('nav', { className:'vc-tabs', 'aria-label':'Social sections' }, tabs.map(function(t){ badges[t[0]] = h('span', { className:'vc-tab-badge', hidden:true }); return h('button', { type:'button', className:'vc-tab' + (t[0] === tab ? ' active' : ''), 'aria-current':t[0] === tab ? 'page' : null, onClick:function(){ go('social', t[0] === 'overview' ? '' : t[0]); } }, [t[1], badges[t[0]]]); })));
    var body = h('div', { className:'vc-social' }); host.appendChild(body);
    // Badges for things waiting on you: friend requests and team invitations (one small request each, only when not on that tab).
    function badge(k, n, label){ if (!badges[k] || !n) return; badges[k].hidden = false; badges[k].textContent = String(n); badges[k].setAttribute('aria-label', n + ' ' + label); }
    if (tab !== 'friends') call('/play/friends').then(function(j){ badge('friends', j.friends.filter(function(f){ return f.state === 'incoming'; }).length, 'waiting requests'); }).catch(function(){});
    if (tab !== 'teams') call('/play/teams').then(function(j){ badge('teams', j.team ? (j.team.requests || []).length : j.invites.length, j.team ? 'join requests' : 'invitations'); }).catch(function(){});
    if (tab === 'privacy') return privacyView(body);
    if (tab === 'teams') return teamsView(body);
    if (tab === 'friends') return friendsView(body);
    if (tab === 'profile') return profileView(body, parts[1] || '');
    if (tab === 'admin' && me().role === 'admin') return adminView(body);
    overview(body);
  }
  function teamBadge(t, big){ return h('span', { className:'vc-team-badge' + (big ? ' big' : ''), style:'--vc-team:' + (t.colour || 'var(--accent)'), 'aria-hidden':'true', text:t.emblem || '★' }); }
  function activityList(rows){
    if (!rows.length) return h('p', { className:'muted', text:'Nothing yet. Play a game to get things going.' });
    return h('ul', { className:'vc-activity' }, rows.map(function(r){ return h('li', null, [avatar(r.user), h('span', { className:'vc-activity-text' }, [h('strong', { text:r.user.name }), ' ' + r.label]), r.points ? h('span', { className:'vc-chip accent', text:'+' + r.points }) : null, h('time', { className:'muted', text:ago(r.at) })]); }));
  }
  function overview(body){
    var grid = h('div', { className:'vc-social-grid' }, [A.pendingContent({ text:'Loading…' })]); body.appendChild(grid);
    Promise.all([call('/play/teams'), call('/play/friends'), call('/play/activity'), call('/play/profile')]).then(function(r){
      var t = r[0], f = r[1], a = r[2], p = r[3];
      var teamCard = t.team ? h('div', { className:'vc-team-hero', style:'--vc-team:' + t.team.colour }, [teamBadge(t.team, true), h('div', null, [h('h3', { text:t.team.name }), h('p', { className:'muted', text:t.team.motto || t.team.members.length + ' members' }), h('strong', { text:fmt(t.team.points) + ' team points' })]), btn('Open', function(){ go('social', 'teams'); }, 'ghost')])
        : h('div', null, [h('p', { className:'muted', text:t.invites.length ? 'You have ' + t.invites.length + ' team invitation(s).' : 'You are not in a team yet.' }), btn(t.invites.length ? 'View invitations' : 'Create or join a team', function(){ go('social', 'teams'); }, 'primary')]);
      var stats = h('div', { className:'vc-stat-row' }, [['Points', p.stats.points], ['This week', p.stats.points_week], ['Rank', p.stats.rank ? '#' + p.stats.rank : '—'], ['Badges', p.badges.filter(function(b){ return b.earned; }).length + '/' + p.badges.length]].map(function(s){ return h('div', { className:'vc-stat' }, [h('strong', { text:typeof s[1] === 'number' ? fmt(s[1]) : s[1] }), h('span', { className:'muted', text:s[0] })]); }));
      var friends = f.friends.filter(function(x){ return x.state === 'friend'; }).slice(0, 8), incoming = f.friends.filter(function(x){ return x.state === 'incoming'; });
      grid.replaceChildren(
        panel('You', [h('div', { className:'vc-me' }, [avatar(p.person, 56), h('div', null, [h('h3', { text:p.person.name }), h('p', { className:'muted', text:p.team ? p.team.emblem + ' ' + p.team.name + (p.team.role === 'captain' ? ' · captain' : '') : 'No team' })])]), stats], btn('Profile', function(){ go('social', 'profile'); }, 'ghost')),
        panel('Your team', [teamCard], null),
        panel('Friends', [incoming.length ? h('p', { className:'vc-chip accent', text:incoming.length + ' friend request(s) waiting' }) : null, friends.length ? h('div', { className:'vc-people-list' }, friends.map(personRow)) : h('p', { className:'muted', text:'No friends yet.' })], btn('Manage', function(){ go('social', 'friends'); }, 'ghost')),
        panel('Activity', [activityList(a.activity)], null, 'wide'));
    }).catch(function(e){ grid.replaceChildren(empty('Social unavailable', (e && e.error) || '')); });
  }
  function personRow(f, extra){
    return h('div', { className:'vc-person-row' }, [avatar(f), h('button', { type:'button', className:'vc-person-name linkish', onClick:function(){ go('social', 'profile/' + f.id); } }, [h('strong', { text:f.name }), h('small', { className:'muted', text:(f.team ? f.team.emblem + ' ' + f.team.name + ' · ' : '') + (f.points_week != null ? fmt(f.points_week) + ' pts this week' : '@' + (f.username || '')) })]), extra || null]);
  }
  function teamsView(body){
    var box = h('div', { className:'vc-teams' }, [A.pendingContent({ text:'Loading teams…' })]); body.appendChild(box);
    function act(payload){ return call('/play/teams', { method:'POST', body:payload }).then(draw).catch(err); }
    function editor(t, colours, emblems, onSave){
      var name = h('input', { type:'text', maxlength:'32', value:t.name || '', placeholder:'Team name', 'aria-label':'Team name', required:true }), motto = h('input', { type:'text', maxlength:'80', value:t.motto || '', placeholder:'Motto (optional)', 'aria-label':'Motto' });
      var colour = t.colour || colours[0], emblem = t.emblem || emblems[0], preview = h('span');
      function pv(){ preview.replaceChildren(teamBadge({ colour:colour, emblem:emblem }, true)); }
      var cs = h('div', { className:'vc-swatches', role:'radiogroup', 'aria-label':'Colour' }, colours.map(function(c){ return h('button', { type:'button', className:'vc-swatch' + (c === colour ? ' active' : ''), style:'--vc-team:' + c, 'aria-label':c, onClick:function(e){ colour = c; cs.querySelectorAll('.vc-swatch').forEach(function(x){ x.classList.remove('active'); }); e.currentTarget.classList.add('active'); pv(); } }); }));
      var es = h('div', { className:'vc-emblems', role:'radiogroup', 'aria-label':'Emblem' }, emblems.map(function(c){ return h('button', { type:'button', className:'vc-emblem' + (c === emblem ? ' active' : ''), text:c, onClick:function(e){ emblem = c; es.querySelectorAll('.vc-emblem').forEach(function(x){ x.classList.remove('active'); }); e.currentTarget.classList.add('active'); pv(); } }); }));
      pv();
      return h('form', { className:'vc-form', onSubmit:function(e){ e.preventDefault(); onSave({ name:name.value, motto:motto.value, colour:colour, emblem:emblem }); } }, [h('div', { className:'vc-team-edit-head' }, [preview, h('div', { className:'vc-grow' }, [name, motto])]), h('div', { className:'vc-field' }, [h('span', { text:'Colour' }), cs]), h('div', { className:'vc-field' }, [h('span', { text:'Emblem' }), es]), h('button', { type:'submit', className:'btn primary', text:t.id ? 'Save' : 'Create team' })]);
    }
    function invitePicker(){
      pickFriend('Invite a friend', function(f){ act({ action:'invite', user_id:f.id }).then(function(){ A.toast('Invitation sent', { kind:'ok' }); }); });
    }
    function draw(j){
      var kids = [];
      if (j.team) {
        var t = j.team, captain = t.members.some(function(m){ return m.id === me().id && m.role === 'captain'; });
        kids.push(h('div', { className:'vc-team-hero big', style:'--vc-team:' + t.colour }, [teamBadge(t, true), h('div', { className:'vc-grow' }, [h('h2', { text:t.name }), h('p', { className:'muted', text:t.motto || 'No motto yet' }), h('strong', { text:fmt(t.points) + ' points' })]),
          h('div', { className:'vc-overlay-actions' }, [captain ? btn('Invite', invitePicker, 'primary') : null, captain ? btn('Edit', function(){ window.VaultcastHub.modal('Edit team', function(b, close){ b.appendChild(editor(t, j.colours, j.emblems, function(v){ act(Object.assign({ action:'edit' }, v)).then(close); })); }); }, 'ghost') : null, btn('Ranks', function(){ go('leaderboards', 'teams'); }, 'ghost'), btn('Leave', function(){ A.confirmDialog('Leave ' + t.name + '?', { okText:'Leave team', danger:true }).then(function(ok){ if (ok) act({ action:'leave' }); }); }, 'ghost danger')])]));
        kids.push(panel('Members', [h('div', { className:'vc-people-list' }, t.members.map(function(m){
          return personRow(m, h('span', { className:'vc-row-actions' }, [m.role === 'captain' ? h('span', { className:'vc-chip accent', text:'Captain' }) : null,
            captain && m.id !== me().id ? btn(m.role === 'captain' ? 'Make member' : 'Make captain', function(){ act({ action:m.role === 'captain' ? 'demote' : 'promote', user_id:m.id }); }, 'ghost small') : null,
            captain && m.id !== me().id ? btn('Remove', function(){ A.confirmDialog('Remove ' + m.name + ' from the team?', { okText:'Remove', danger:true }).then(function(ok){ if (ok) act({ action:'remove', user_id:m.id }); }); }, 'ghost small danger') : null]));
        }))]));
        var wallText = h('textarea', { rows:'2', maxlength:'280', placeholder:'Say something to your team', 'aria-label':'Message to your team' });
        kids.push(panel('Team wall', [h('form', { className:'vc-wall-form', onSubmit:function(e){ e.preventDefault(); var v = wallText.value.trim(); if (!v) return; act({ action:'post', text:v }); } }, [wallText, h('button', { type:'submit', className:'btn primary small', text:'Post' })]),
          (t.wall || []).length ? h('ul', { className:'vc-wall' }, t.wall.slice().reverse().map(function(w){ return h('li', null, [avatar(w.user), h('div', { className:'vc-grow' }, [h('strong', { text:w.user.name }), h('p', { className:'vc-wall-text', text:w.text }), h('time', { className:'muted', text:ago(w.at) })]), w.can_delete ? btn('×', function(){ act({ action:'unpost', post_id:w.id }); }, 'ghost small vc-icon-btn', { 'aria-label':'Delete this post' }) : null]); })) : h('p', { className:'muted', text:'No posts yet.' })]));
        if (captain) {
          var linkUrl = t.link ? location.origin + (A.BASE || '/') + 'play/social/join/' + t.link : '';
          kids.push(panel('Invite link', t.link ? [h('p', { className:'muted', text:'Anyone signed in on this server who opens this link joins the team straight away. Replace it to stop the old one working.' }), h('div', { className:'vc-share-row' }, [h('code', { text:linkUrl.replace(/^https?:\/\//, '') }), btn('Copy', function(){ (navigator.clipboard ? navigator.clipboard.writeText(linkUrl) : Promise.reject()).then(function(){ A.toast('Copied', { kind:'ok' }); }).catch(function(){ window.prompt('Copy this link', linkUrl); }); }, 'ghost small'), btn('Replace', function(){ act({ action:'link' }); }, 'ghost small'), btn('Turn off', function(){ act({ action:'unlink' }); }, 'ghost small danger')]), qrBox(linkUrl, 'QR code for the team invite link')]
            : [h('p', { className:'muted', text:'Create a link people can open to join without an invitation.' }), btn('Create invite link', function(){ act({ action:'link' }); }, 'primary')]));
        }
        if (captain && (t.requests || []).length) kids.push(panel('Join requests', [h('div', { className:'vc-people-list' }, t.requests.map(function(p){ return personRow(p, h('span', { className:'vc-row-actions' }, [btn('Approve', function(){ act({ action:'approve', user_id:p.id }); }, 'primary small'), btn('Reject', function(){ act({ action:'reject', user_id:p.id }); }, 'ghost small')])); }))]));
        if ((t.invites || []).length) kids.push(panel('Pending invitations', [h('div', { className:'vc-people-list' }, t.invites.map(function(p){ return personRow(p, captain ? btn('Cancel', function(){ act({ action:'cancel-invite', user_id:p.id }); }, 'ghost small') : null); }))]));
      } else {
        if (j.invites.length) kids.push(panel('Invitations', [h('div', { className:'vc-people-list' }, j.invites.map(function(t){ return h('div', { className:'vc-person-row' }, [teamBadge(t), h('span', { className:'vc-person-name' }, [h('strong', { text:t.name }), h('small', { className:'muted', text:(t.invited_by ? 'from ' + t.invited_by.name + ' · ' : '') + t.members.length + ' members' })]), h('span', { className:'vc-row-actions' }, [btn('Accept', function(){ act({ action:'accept', team_id:t.id }); }, 'primary small'), btn('Decline', function(){ act({ action:'decline', team_id:t.id }); }, 'ghost small')])]); }))]));
        kids.push(panel('Start a team', [editor({}, j.colours, j.emblems, function(v){ act(Object.assign({ action:'create' }, v)); })]));
      }
      kids.push(panel('All teams', [j.teams.length ? h('div', { className:'vc-team-grid' }, j.teams.map(function(t, i){
        return h('article', { className:'vc-team-card', style:'--vc-team:' + t.colour }, [h('span', { className:'vc-team-rank', text:'#' + (i + 1) }), teamBadge(t), h('h3', { text:t.name }), h('p', { className:'muted', text:t.members.length + ' members · ' + fmt(t.points) + ' pts' }), t.motto ? h('p', { className:'vc-motto', text:'“' + t.motto + '”' }) : null,
          !j.team && !t.requested ? btn('Ask to join', function(){ act({ action:'request', team_id:t.id }).then(function(){ A.toast('Request sent'); }); }, 'ghost small') : t.requested ? h('span', { className:'vc-chip', text:'Requested' }) : null]);
      })) : h('p', { className:'muted', text:'No teams yet. Be the first!' })]));
      box.replaceChildren.apply(box, kids);
    }
    call('/play/teams').then(draw).catch(function(e){ box.replaceChildren(empty('Teams unavailable', (e && e.error) || '')); });
  }
  function friendsView(body){
    var q = h('input', { type:'search', placeholder:'Find people by username', 'aria-label':'Find people' }), results = h('div', { className:'vc-people-list' }), list = h('div', { className:'vc-friends' }, [A.pendingContent({ text:'Loading friends…' })]), t = 0;
    body.appendChild(panel('Add friends', [q, results]));
    body.appendChild(list);
    function act(action, id){ return call('/play/friends', { method:'POST', body:{ action:action, user_id:id } }).then(draw).catch(err); }
    q.addEventListener('input', function(){ clearTimeout(t); t = setTimeout(function(){
      if (q.value.trim().length < 2) { results.replaceChildren(); return; }
      call('/play/people?q=' + encodeURIComponent(q.value.trim())).then(function(j){ results.replaceChildren.apply(results, j.people.length ? j.people.map(function(p){ return personRow(p, btn('Add friend', function(e){ e.currentTarget.disabled = true; act('request', p.id).then(function(){ A.toast('Request sent to ' + p.name, { kind:'ok' }); }); }, 'primary small')); }) : [h('p', { className:'muted', text:'Nobody found.' })]); }).catch(function(){});
    }, 250); });
    function draw(j){
      var groups = { incoming:[], friend:[], outgoing:[] }; j.friends.forEach(function(f){ (groups[f.state] = groups[f.state] || []).push(f); });
      var kids = [];
      if (groups.incoming.length) kids.push(panel('Requests', [h('div', { className:'vc-people-list' }, groups.incoming.map(function(f){ return personRow(f, h('span', { className:'vc-row-actions' }, [btn('Accept', function(){ act('accept', f.id); }, 'primary small'), btn('Decline', function(){ act('decline', f.id); }, 'ghost small')])); }))]));
      kids.push(panel('Friends', [groups.friend.length ? h('div', { className:'vc-people-list' }, groups.friend.map(function(f){ return personRow(f, h('span', { className:'vc-row-actions' }, [btn('Challenge', function(){ window.VaultcastHub.modal('Challenge ' + f.name, function(b, close){ b.appendChild(h('div', { className:'vc-chip-row' }, ['blocks', 'snake', 'merge', 'breakout', 'word'].map(function(g){ return btn(GAMES[g].name, function(){ close(); call('/play/challenge', { method:'POST', body:{ user_id:f.id, game:g } }).then(function(){ A.toast('Challenge sent', { kind:'ok' }); }).catch(err); }, 'ghost'); }).concat([btn('Chess', function(){ close(); call('/play/chess', { method:'POST', body:{ opponent:f.id } }).then(function(j){ go('arcade', 'chess/' + j.game.id); }).catch(err); }, 'ghost')]))); }); }, 'ghost small'), btn('Remove', function(){ A.confirmDialog('Remove ' + f.name + ' from your friends?', { okText:'Remove', danger:true }).then(function(ok){ if (ok) act('remove', f.id); }); }, 'ghost small danger')])); })) : h('p', { className:'muted', text:'Search above to add friends.' })]));
      if (groups.outgoing.length) kids.push(panel('Sent requests', [h('div', { className:'vc-people-list' }, groups.outgoing.map(function(f){ return personRow(f, btn('Cancel', function(){ act('remove', f.id); }, 'ghost small')); }))]));
      list.replaceChildren.apply(list, kids);
    }
    call('/play/friends').then(draw).catch(function(e){ list.replaceChildren(empty('Friends unavailable', (e && e.error) || '')); });
  }
  function profileView(body, id){
    var box = h('div', { className:'vc-profile' }, [A.pendingContent({ text:'Loading profile…' })]); body.appendChild(box);
    call('/play/profile' + (id ? '?id=' + encodeURIComponent(id) : '')).then(function(p){
      var s = p.stats;
      var blockBtn = !p.me ? btn('Block', function(){ A.confirmDialog('Block ' + p.person.name + '? You stop being friends, they cannot challenge you, invite you or see your profile, and you will not get notifications from them.', { okText:'Block', danger:true }).then(function(ok){ if (ok) call('/play/privacy', { method:'POST', body:{ action:'block', user_id:p.person.id } }).then(function(){ A.toast(p.person.name + ' is blocked'); go('social', 'privacy'); }).catch(err); }); }, 'ghost danger') : null;
      if (p.private) { box.replaceChildren(h('div', { className:'vc-profile-head' }, [avatar(p.person, 72), h('div', { className:'vc-grow' }, [h('h2', { text:p.person.name }), h('p', { className:'muted', text:'This profile is private.' })]), !p.friend ? btn('Add friend', function(e){ e.currentTarget.disabled = true; call('/play/friends', { method:'POST', body:{ action:'request', user_id:p.person.id } }).then(function(){ A.toast('Request sent', { kind:'ok' }); }).catch(err); }, 'primary') : null, blockBtn])); return; }
      box.replaceChildren(
        h('div', { className:'vc-profile-head' }, [avatar(p.person, 72), h('div', { className:'vc-grow' }, [h('h2', { text:p.person.name }), h('p', { className:'muted', text:(p.team ? p.team.emblem + ' ' + p.team.name + (p.team.role === 'captain' ? ' · captain' : '') + ' · ' : '') + fmt(s.points) + ' points' + (s.rank ? ' · #' + s.rank + ' overall' : '') })]),
          !p.me && !p.friend ? btn('Add friend', function(e){ e.currentTarget.disabled = true; call('/play/friends', { method:'POST', body:{ action:'request', user_id:p.person.id } }).then(function(){ A.toast('Request sent', { kind:'ok' }); }).catch(err); }, 'primary') : null, blockBtn, p.me ? btn('Privacy', function(){ go('social', 'privacy'); }, 'ghost') : null]),
        h('div', { className:'vc-stat-row' }, [['Games played', s.games_played], ['Points this week', s.points_week], ['Quiz wins', s.quiz_wins], ['Chess wins', s.chess_wins], ['Daily Five streak', s.word_streak], ['Friends', s.friends]].map(function(x){ return h('div', { className:'vc-stat' }, [h('strong', { text:fmt(x[1]) }), h('span', { className:'muted', text:x[0] })]); })),
        panel('Achievements', [h('div', { className:'vc-badges' }, p.badges.map(function(b){ return h('div', { className:'vc-badge' + (b.earned ? ' earned' : ''), title:b.detail }, [h('span', { className:'vc-badge-icon', text:b.icon, 'aria-hidden':'true' }), h('strong', { text:b.name }), h('small', { className:'muted', text:b.detail }), h('span', { className:'sr-only', text:b.earned ? 'Earned' : 'Not earned yet' })]); }))]),
        panel('Personal bests', [Object.keys(p.bests).length ? h('div', { className:'vc-bests' }, Object.keys(p.bests).map(function(g){ var b = p.bests[g]; return h('button', { type:'button', className:'vc-best-row', onClick:function(){ go('leaderboards', g); } }, [h('strong', { text:b.name }), h('span', { text:g === 'chess' ? 'Played ' + b.plays : fmt(b.best) }), h('span', { className:'muted', text:b.plays + ' plays' + (b.rank ? ' · #' + b.rank : '') })]); })) : h('p', { className:'muted', text:'No games yet.' })]),
        panel('Recent activity', [activityList(p.activity)]));
    }).catch(function(e){ box.replaceChildren(empty('Profile unavailable', (e && e.error) || '')); });
  }
  function privacyView(body){
    var box = h('div', { className:'vc-privacy' }, [A.pendingContent({ text:'Loading…' })]); body.appendChild(box);
    function save(payload){ return call('/play/privacy', { method:'POST', body:payload }).then(function(){ A.toast('Saved', { kind:'ok' }); return call('/play/privacy').then(draw); }).catch(err); }
    function draw(j){
      var hide = h('input', { type:'checkbox', checked:j.hide ? true : null, onChange:function(){ save({ action:'save', hide:this.checked }); } });
      var vis = seg([['everyone', 'Everyone'], ['friends', 'Friends only'], ['private', 'Only me']], j.profile, function(v){ save({ action:'save', profile:v }); }, 'Who can see my profile');
      box.replaceChildren(
        panel('Leaderboards', [h('label', { className:'vc-check' }, [hide, h('span', { text:'Hide me from leaderboards' })]), h('p', { className:'muted', text:'Your scores still count for you and your team; your name just does not appear on the public boards.' })]),
        panel('Profile', [h('div', { className:'vc-field' }, [h('span', { text:'Who can see my profile, stats and activity' }), vis])]),
        panel('Blocked players', [j.blocked.length ? h('div', { className:'vc-people-list' }, j.blocked.map(function(p){ return h('div', { className:'vc-person-row' }, [avatar(p), h('span', { className:'vc-person-name' }, [h('strong', { text:p.name })]), btn('Unblock', function(){ save({ action:'unblock', user_id:p.id }); }, 'ghost small')]); })) : h('p', { className:'muted', text:'You have not blocked anyone. Block someone from their profile.' })]));
    }
    call('/play/privacy').then(draw).catch(function(e){ box.replaceChildren(empty('Privacy settings unavailable', (e && e.error) || '')); });
  }
  function joinTeamLink(host, token){
    var box = h('div', { className:'vc-social' }); host.appendChild(box);
    if (!signed()) { box.appendChild(empty('Sign in to join this team', 'Team invite links work for accounts on this server.')); return; }
    box.appendChild(panel('Join a team', [h('p', { className:'muted', text:'You were sent an invite link to a team.' }), h('div', { className:'vc-overlay-actions' }, [btn('Join the team', function(e){ e.currentTarget.disabled = true; call('/play/teams', { method:'POST', body:{ action:'join-link', token:token } }).then(function(){ A.toast('Welcome to the team!', { kind:'ok' }); go('social', 'teams'); }).catch(function(x){ err(x); go('social', 'teams'); }); }, 'primary'), btn('Not now', function(){ go('social'); }, 'ghost')])]));
  }
  function adminView(body){
    var box = h('div', { className:'vc-admin' }, [A.pendingContent({ text:'Loading…' })]); body.appendChild(box);
    Promise.all([call('/play/admin/settings'), call('/play/teams')]).then(function(r){
      var cfg = r[0].play || {}, teams = r[1].teams;
      var words = h('textarea', { rows:'3', placeholder:'Comma-separated words that team names may not contain', 'aria-label':'Blocked words' }); words.value = cfg.banned_words || '';
      var otdb = h('input', { type:'checkbox', checked:cfg.opentdb !== false ? true : null });
      var resetSel = h('select', { 'aria-label':'Leaderboard to reset' }, [['', 'All leaderboards and team points'], ['blocks', 'Stackfall'], ['snake', 'Neon Snake'], ['merge', '2048 Merge'], ['breakout', 'Brickbreak'], ['word', 'Daily Five'], ['quiz', 'Quiz'], ['chess', 'Chess'], ['emu-time', 'Most time played (games)'], ['emu-sessions', 'Most games played']].map(function(o){ return h('option', { value:o[0], text:o[1] }); }));
      box.replaceChildren(
        panel('Settings', [h('form', { className:'vc-form', onSubmit:function(e){ e.preventDefault(); call('/play/admin/settings', { method:'POST', body:{ banned_words:words.value, opentdb:otdb.checked } }).then(function(){ A.toast('Saved', { kind:'ok' }); }).catch(err); } }, [h('label', { className:'vc-field' }, [h('span', { text:'Blocked words in team names' }), words]), h('label', { className:'vc-check' }, [otdb, h('span', { text:'Offer Open Trivia DB categories (needs internet access)' })]), h('button', { type:'submit', className:'btn primary', text:'Save' })])]),
        panel('Reset leaderboards', [h('p', { className:'muted', text:'Resetting hides older scores from the boards. Personal history and badges are kept.' }), h('div', { className:'vc-join-row' }, [resetSel, btn('Reset', function(){ A.confirmDialog('Reset “' + resetSel.options[resetSel.selectedIndex].text + '”?', { okText:'Reset', danger:true }).then(function(ok){ if (ok) call('/play/admin/reset', { method:'POST', body:{ game:resetSel.value } }).then(function(){ A.toast('Leaderboard reset', { kind:'ok' }); }).catch(err); }); }, 'danger')])]),
        panel('Teams', [teams.length ? h('div', { className:'vc-people-list' }, teams.map(function(t){
          var name = h('input', { type:'text', value:t.name, maxlength:'32', 'aria-label':'Team name' });
          return h('div', { className:'vc-person-row' }, [teamBadge(t), name, h('span', { className:'vc-row-actions' }, [btn('Rename', function(){ call('/play/admin/team', { method:'POST', body:{ team_id:t.id, name:name.value } }).then(function(){ A.toast('Renamed', { kind:'ok' }); }).catch(err); }, 'ghost small'), btn('Delete', function(){ A.confirmDialog('Delete team ' + t.name + '?', { okText:'Delete', danger:true }).then(function(ok){ if (ok) call('/play/admin/team', { method:'POST', body:{ team_id:t.id, delete:true } }).then(function(){ go('social', 'admin'); }).catch(err); }); }, 'ghost small danger')])]);
        })) : h('p', { className:'muted', text:'No teams.' })]));
    }).catch(function(e){ box.replaceChildren(empty('Moderation unavailable', (e && e.error) || '')); });
  }

  // ------------------------------------------------------------------ Leaderboards
  var BOARDS = [['points', 'Points', 'Arcade'], ['teams', 'Teams', 'Arcade'], ['blocks', 'Stackfall', 'Arcade'], ['snake', 'Neon Snake', 'Arcade'], ['merge', '2048 Merge', 'Arcade'], ['breakout', 'Brickbreak', 'Arcade'], ['word', 'Daily Five', 'Arcade'], ['chess', 'Chess wins', 'Arcade'], ['quiz', 'Quiz', 'Quiz'], ['emu-sessions', 'Most games played', 'Games'], ['emu-time', 'Most time played', 'Games']];
  function leaderboards(host, sub){
    var board = BOARDS.some(function(b){ return b[0] === sub; }) ? sub : 'points', pref = store('vaultcast-lb') || {}, period = pref.period || 'week', scope = pref.scope || 'all';
    host.appendChild(hero('Leaderboards', 'Arcade, quiz and game-library rankings. Filter by period, friends or your team.', [btn('Arcade', function(){ go('arcade'); }, 'ghost'), btn('Quiz', function(){ go('quiz'); }, 'ghost')], 'GAMES'));
    var picker = h('nav', { className:'vc-board-picker', 'aria-label':'Leaderboard' }), filters = h('div', { className:'vc-board-filters' }), table = h('div', { className:'vc-board-table' });
    host.appendChild(h('div', { className:'vc-boards' }, [picker, h('div', { className:'vc-board-main' }, [filters, table])]));
    var groups = {}; BOARDS.forEach(function(b){ (groups[b[2]] = groups[b[2]] || []).push(b); });
    Object.keys(groups).forEach(function(g){ picker.appendChild(h('div', { className:'vc-board-group' }, [h('span', { className:'eyebrow', text:g })].concat(groups[g].map(function(b){ return h('button', { type:'button', className:'vc-board-btn' + (b[0] === board ? ' active' : ''), 'aria-current':b[0] === board ? 'true' : null, text:b[1], onClick:function(){ A.state.playSub = b[0]; A.pushNav(); board = b[0]; picker.querySelectorAll('.vc-board-btn').forEach(function(x){ x.classList.toggle('active', x === this); x.removeAttribute('aria-current'); }, this); this.setAttribute('aria-current', 'true'); load(); } }); })))); });
    function drawFilters(){
      filters.replaceChildren(seg([['day', 'Today'], ['week', 'Week'], ['month', 'Month'], ['all', 'All time']], period, function(v){ period = v; save(); drawFilters(); load(); }, 'Period'),
        board === 'teams' ? null : seg([['all', 'Everyone'], ['friends', 'Friends'], ['team', 'My team']], scope, function(v){ if (!signed() && v !== 'all') { A.toast('Sign in to filter by friends or team'); return; } scope = v; save(); drawFilters(); load(); }, 'Who'));
    }
    function save(){ store('vaultcast-lb', { period:period, scope:scope }); }
    function value(r, j){ if (j.unit === 'seconds') return dur(r.value); if (board === 'chess') return fmt(r.value) + (r.value === 1 ? ' win' : ' wins'); if (j.unit === 'sessions') return fmt(r.value) + ' sessions'; return fmt(r.value) + (board === 'points' || board === 'teams' ? ' pts' : ''); }
    var seq = 0;
    function load(){
      var mine = ++seq; drawFilters();
      table.replaceChildren(A.pendingContent({ text:'Loading…' }));
      call('/play/leaderboard?board=' + board + '&period=' + period + '&scope=' + (board === 'teams' ? 'all' : scope)).then(function(j){
        if (mine !== seq) return;
        var note = (board === 'emu-time' || board === 'emu-sessions') && period !== 'all' ? h('p', { className:'muted vc-note', text:'Daily totals are collected from this update onwards; All time includes your full play history.' }) : null;
        if (!j.rows.length) { table.replaceChildren(note || '', empty('No entries yet', j.note || 'Be the first on this board.')); return; }
        var top = j.rows.slice(0, 3), rest = j.rows.slice(3);
        var podiumEl = h('div', { className:'vc-lb-podium' }, [1, 0, 2].map(function(i){ var r = top[i]; if (!r) return null; var who = r.team || r.user; return h('div', { className:'vc-lb-top p' + (i + 1) + (r.me ? ' me' : ''), style:r.team && board === 'teams' ? '--vc-team:' + r.team.colour : null }, [h('span', { className:'vc-podium-medal', text:['🥇', '🥈', '🥉'][i] }), board === 'teams' ? teamBadge(r.team, true) : avatar(r.user, 56), h('strong', { text:who.name }), h('span', { text:value(r, j) })]); }));
        var list = h('ol', { className:'vc-lb-list', start:'4' }, rest.map(function(r, i){ return h('li', { className:'vc-lb-row' + (r.me ? ' me' : '') }, [h('span', { className:'vc-lb-rank', text:String(i + 4) }), board === 'teams' ? teamBadge(r.team) : avatar(r.user), h('span', { className:'vc-lb-name' }, [h('strong', { text:board === 'teams' ? r.team.name : r.user.name }), r.team && board !== 'teams' ? h('small', { className:'muted', text:r.team.emblem + ' ' + r.team.name }) : board === 'teams' ? h('small', { className:'muted', text:r.players + ' players scoring' }) : null]), h('span', { className:'vc-lb-value', text:value(r, j) })]); }));
        table.replaceChildren(note || '', podiumEl, rest.length ? list : '');
        if (board !== 'teams') podiumEl.querySelectorAll('.vc-lb-top').forEach(function(el, k){ var r = top[[1, 0, 2][k]]; if (r && r.user && r.user.id) { el.tabIndex = 0; el.setAttribute('role', 'link'); el.addEventListener('click', function(){ if (signed()) go('social', 'profile/' + r.user.id); }); } });
      }).catch(function(e){ if (mine === seq) table.replaceChildren(empty('Leaderboard unavailable', (e && e.error) || '')); });
    }
    load();
  }

  // ------------------------------------------------------------------ module API
  root.VaultcastPlay = {
    init:function(app){ A = app; h = app.h; api = app.api; },
    games:GAMES,
    render:function(host, tab, sub){
      stopAll();
      host.replaceChildren(); host.classList.add('vc-play');
      if (tab === 'quiz') return quiz(host, sub);
      if (tab === 'social') return social(host, sub);
      if (tab === 'leaderboards') return leaderboards(host, sub);
      return arcade(host, sub);
    },
    stop:stopAll
  };
})(this);
