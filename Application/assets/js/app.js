(function(){
  'use strict';
  var BASE = window.__STREAM_BASE__ || '/';
  var API = BASE + 'api';
  var _navSilent = false;
  function streamPlayUrl(url){
    if (!url) return url;
    if (/^https?:\/\//i.test(url)) {
      try {
        var a = document.createElement('a'); a.href = url;
        if (a.host && a.host !== location.host) {
          return location.protocol + '//' + location.host + a.pathname + a.search + a.hash;
        }
      } catch (e0) {}
      return url;
    }
    if (url.charAt(0) === '/') return location.protocol + '//' + location.host + url;
    return url;
  }
  function navState(extra){
    var st = { v: state.view, section: state.section, sectionType: state.sectionType, sectionLabel: state.sectionLabel, showKey: state.showKey, showSlug: state.showSlug, showSeason: state.showSeason, showEpisode: state.showEpisode, movieId: state.movieId, movieSlug: state.movieSlug, collectionId: state.collectionId, settingsSection: state.settingsSection, playTab: state.playTab, sectionSystem: state.sectionSystem || '', sectionSystemSlug: state.sectionSystemSlug || null, gamePath: state.gamePath || null, searchQuery: state.searchQuery, player: !!document.getElementById('player-overlay') };
    st.sectionLetter = state.sectionLetter || '';
    st.collectionsLetter = state.collectionsLetter || '';
    st.hubTab=state.hubTab || 'overview';st.cinemaId=state.cinemaId || null;
    st.roomId = state.gameLobby ? state.gameLobby.session.id : null;
    if (extra) Object.keys(extra).forEach(function(k){ st[k]=extra[k]; });
    return st;
  }
  function pushNav(extra){
    if (_navSilent) return;
    try { history.pushState(navState(extra), '', navUrl(extra)); } catch (e1) {}
  }
  function replaceNav(){
    try { history.replaceState(navState({ player:false }), '', navUrl()); } catch (e2) {}
  }
  /* Keep the address bar on the clean URL of whatever is rendered (new entry per page change). */
  function syncUrl(){
    if (_navSilent) return;
    if (!state._routed && state.view !== 'welcome' && state.view !== 'login') return; // deep link not applied yet
    var want = navUrl();
    if (want === location.pathname + location.search) return;
    try { history.pushState(navState({ player:false }), '', want); } catch (e3) {}
  }

  var state = {
    user: null,
    home: null,
    view: 'home',
    section: null,
    sectionType: null,
    showKey: null,
    movieId: null,
    settings: null,
    adminTab: 'general',
    settingsSection: 'account',
    userTab: 'prefs',
    searchQuery: '',
    searchResult: null,
    searchRss: null,
    searchBusy: false,
    abortControllers: {},
    collectionId: null,
    mm: { section:'', q:'', mode:'auto', page:1, filesPath:'', selected:null },
    sectionPage: 1,
    sectionQ: '',
    sectionLoadingMore: false,
    sectionLetter: '',
    collectionsLetter: '',
    sectionSystem: '',
    playTab: 'games',
    lobby: { sessions: [], loading: false, pollTimer: null, host: null, guest: null },
    sectionFilters: { genre:'', year:'', decade:'', actor:'', status:'', network:'' },
    vodFilterDim: ''
  };


  function audioPrefKey(itemId){ return 'vaultcast-audio:' + itemId; }
  function getAudioPref(itemId){
    try {
      var v = localStorage.getItem(audioPrefKey(itemId));
      if (v != null && v !== '') return parseInt(v, 10);
    } catch(e){}
    return null;
  }
  function setAudioPref(itemId, idx){
    try {
      if (idx == null || idx === '') localStorage.removeItem(audioPrefKey(itemId));
      else localStorage.setItem(audioPrefKey(itemId), String(idx));
    } catch(e){}
  }
  function applyAudioTrack(media, relativeIndex){
    if (!media || relativeIndex == null || isNaN(relativeIndex)) return { ok:false, note:'No track index' };
    try {
      var tracks = media.audioTracks;
      if (tracks && relativeIndex >= 0 && relativeIndex < tracks.length) {
        for (var i=0;i<tracks.length;i++) tracks[i].enabled = (i === relativeIndex);
        return { ok:!!tracks[relativeIndex].enabled, note:'Audio track selected' };
      }
    } catch(e){}
    return { ok:false, note:'Browser DirectPlay cannot switch audio tracks for this file; container default is used.' };
  }
  function playerTrackControls(overlay, media, item, play){
    var bar = h('div', {className:'player-tracks', role:'group', 'aria-label':'Audio and subtitles'});
    var audio = h('select', {id:'player-audio-track', 'aria-label':'Audio track'});
    var captions = h('select', {id:'player-subtitle-track', 'aria-label':'Subtitles'});
    var note = h('span', {className:'player-track-note', role:'status'});
    bar.appendChild(h('label', {'for':'player-audio-track'}, [h('span',{text:'Audio'}), audio]));
    if (media.tagName === 'VIDEO') bar.appendChild(h('label', {'for':'player-subtitle-track'}, [h('span',{text:'Subtitles'}), captions]));
    bar.appendChild(note);
    overlay.appendChild(bar);
    var audioPreference = getAudioPref(item.id), audioApplied = false, subtitlePreference = null, subtitlesApplied = false;
    var subtitleKey = 'vaultcast-subtitles:' + item.id;
    try { subtitlePreference = localStorage.getItem(subtitleKey); } catch (e) {}
    var probeTracks = [], captionTracks = [], probeDuration = 0;
    /* Audio switching without browser audioTracks: restart a server remux (/stream/remux) at the
       current position with the chosen track. remux.offset is the source time where that stream
       starts (the keyframe the server reports), so the real position is offset + media.currentTime.
       While remuxing, native controls are replaced by a bar over the FULL duration (ffprobe /
       library metadata); seeking anywhere restarts the remux at the target with the same track. */
    var remux = { active:false, offset:0, track:null, seeking:false, gen:0, dragging:false };
    function fullDuration(){ return probeDuration || Number(play.duration) || 0; }
    function absTime(){ return (remux.active ? remux.offset : 0) + (media.currentTime || 0); }
    openPlayer._absTime = absTime;
    openPlayer._seekAbsolute = function(seconds){
      if(remux.seeking)return;
      seconds=Math.max(0,Number(seconds)||0);
      var relative=seconds-(remux.active?remux.offset:0);
      if(remux.active && !inBuffered(relative))return switchViaRemux(remux.track,seconds);
      media.currentTime=relative;
    };
    openPlayer._absDuration = function(){ return remux.active ? fullDuration() : (media.duration || 0); };
    function remuxSrc(index, at){
      return streamPlayUrl(play.remux_url) + '&a=' + encodeURIComponent(index) + '&ss=' + Math.max(0, at).toFixed(3);
    }
    function fmtT(t){
      t = Math.max(0, Math.floor(t || 0));
      var hh = Math.floor(t / 3600), mm = Math.floor(t / 60) % 60, ss = t % 60;
      return (hh ? hh + ':' + (mm < 10 ? '0' : '') : '') + mm + ':' + (ss < 10 ? '0' : '') + ss;
    }
    var rbPlay = h('button', { className:'btn ghost sm', type:'button', 'aria-label':'Play or pause', text:'❚❚' });
    var rbRange = h('input', { type:'range', min:'0', max:'0', step:'0.1', value:'0', 'aria-label':'Seek', style:'flex:1;min-width:120px' });
    var rbTime = h('span', { className:'muted', style:'font-variant-numeric:tabular-nums;white-space:nowrap', text:'0:00 / 0:00' });
    var rbFull = h('button', { className:'btn ghost sm', type:'button', 'aria-label':'Full screen', text:'⛶' });
    var remuxBar = h('div', { className:'player-remux-bar', style:'display:none;align-items:center;gap:8px;padding:6px 10px;background:rgba(0,0,0,.6)' }, [rbPlay, rbRange, rbTime, rbFull]);
    overlay.appendChild(remuxBar);
    rbPlay.onclick = function(){ if (media.paused) { var pr = media.play(); if (pr && pr.catch) pr.catch(function(){}); } else media.pause(); };
    rbFull.onclick = function(){
      try { if (document.fullscreenElement) document.exitFullscreen(); else (overlay.requestFullscreen || media.requestFullscreen).call(overlay.requestFullscreen ? overlay : media); } catch (eF) {}
    };
    rbRange.addEventListener('input', function(){ remux.dragging = true; rbTime.textContent = fmtT(+rbRange.value) + ' / ' + fmtT(fullDuration()); });
    rbRange.addEventListener('change', function(){ remux.dragging = false; switchViaRemux(remux.track, +rbRange.value); });
    function paintRemuxBar(){
      if (!remux.active) return;
      var d = fullDuration();
      rbRange.max = String(d || Math.max(absTime(), 1));
      if (!remux.dragging) rbRange.value = String(absTime());
      rbTime.textContent = fmtT(absTime()) + ' / ' + (d ? fmtT(d) : '–:––');
      rbPlay.textContent = media.paused ? '▶' : '❚❚';
    }
    ['timeupdate','play','pause','loadedmetadata'].forEach(function(ev){ media.addEventListener(ev, paintRemuxBar); });
    function setRemuxUi(on){
      remuxBar.style.display = on ? 'flex' : 'none';
      media.controls = !on;
      paintRemuxBar();
    }
    function switchViaRemux(index, at){
      if (!play.remux_url || !play.remux_allowed) return false;
      var target = Math.max(0, at != null ? at : absTime());
      var d = fullDuration();
      if (d && target > d - 0.5) target = Math.max(0, d - 0.5);
      var wasPaused = media.paused && media.currentTime > 0;
      var gen = ++remux.gen;
      remux.seeking = true; remux.track = index;
      var info = streamPlayUrl(play.remux_url) + '&info=1&ss=' + target.toFixed(3);
      fetch(info, { credentials:'same-origin', cache:'no-store' }).then(function(r){ return r.json(); }).catch(function(){ return null; }).then(function(j){
        if (gen !== remux.gen || !overlay.isConnected) return;
        if (j && j.ok === false) { remux.seeking = false; toast(j.error || 'Audio switching is not available.', { kind:'bad' }); return; }
        var start = j && isFinite(j.start) ? Math.min(target, Math.max(0, Number(j.start))) : target;
        if (j && j.duration && !probeDuration) probeDuration = Number(j.duration);
        remux.active = true; remux.offset = start;
        setRemuxUi(true);
        var seekAt = j && isFinite(j.seek) ? Number(j.seek) : start;
        try { media.src = remuxSrc(index, seekAt); media.load(); } catch (eRm) {}
        media.addEventListener('loadedmetadata', function(){
          if (gen !== remux.gen) return;
          if (target - start > 0.25) { try { media.currentTime = target - start; } catch (eS) {} }
          if (!wasPaused) { var pr = media.play(); if (pr && pr.catch) pr.catch(function(){}); }
          setTimeout(function(){ if (gen === remux.gen) remux.seeking = false; }, 400);
          paintRemuxBar();
        }, { once:true });
      });
      return true;
    }
    /* DirectPlay only: admins may turn remuxing on from here; others are told it is disabled. */
    function requestRemux(index, at){
      if (!play.remux_url) return Promise.resolve(false);
      if (play.remux_allowed) return Promise.resolve(switchViaRemux(index, at));
      if (!(state.user && state.user.role === 'admin')) {
        toast('Switching audio tracks is disabled by the administrator (DirectPlay only).', { kind:'bad' });
        return Promise.resolve(false);
      }
      return confirmDialog('This browser cannot switch audio tracks in the file directly. The server can remux the video with the chosen track (video copied, audio converted when needed), but “DirectPlay only” is on in Settings → Streaming. Turn DirectPlay only off and allow remuxing?', { okText:'Allow remuxing', host: overlay }).then(function(ok){
        if (!ok) return false;
        return api('/admin/settings', { method:'POST', body:{ streaming:{ directplay_only:false } } }).then(function(j){
          if (!j || !j.ok) { toast((j && j.error) || 'Could not change the setting', { kind:'bad' }); return false; }
          if (j.settings) state.settings = j.settings;
          play.remux_allowed = true;
          return switchViaRemux(index, at);
        });
      });
    }
    function inBuffered(t){
      try { for (var b = 0; b < media.buffered.length; b++) if (t >= media.buffered.start(b) - 0.5 && t <= media.buffered.end(b) + 0.5) return true; } catch (eB) {}
      return false;
    }
    /* Keyboard / programmatic seeks set media.currentTime (relative to the remux start). */
    media.addEventListener('seeking', function(){
      if (!remux.active || remux.seeking) return;
      var t = media.currentTime || 0;
      if (!inBuffered(t)) switchViaRemux(remux.track, remux.offset + t);
    });
    function languageName(language){
      if (!language || language === 'und') return '';
      try { return new Intl.DisplayNames([navigator.language || 'en'], {type:'language'}).of(language); } catch (e) { return language; }
    }
    function trackLabel(track, index, fallback){
      var label = track.label || track.title || languageName(track.language) || fallback + ' ' + (index + 1);
      var language = languageName(track.language);
      return language && label.toLowerCase() !== language.toLowerCase() ? label + ' (' + language + ')' : label;
    }
    function subtitleId(track, index){ return track.id || [track.language,track.label,index].join(':'); }
    function chooseSubtitle(value){
      captionTracks.forEach(function(track,index){ track.mode = subtitleId(track,index) === value ? 'showing' : 'disabled'; });
    }
    function refresh(){
      if (!overlay.isConnected) return;
      var nativeAudio = media.audioTracks;
      audio.innerHTML = '';
      if (nativeAudio && nativeAudio.length) {
        if (!audioApplied && audioPreference != null && audioPreference < nativeAudio.length) {
          audioApplied = applyAudioTrack(media, audioPreference).ok;
        }
        for (var i=0;i<nativeAudio.length;i++) {
          var track = nativeAudio[i];
          audio.appendChild(h('option', {value:String(i),text:trackLabel(track,i,'Track'),selected:track.enabled}));
        }
        audio.disabled = nativeAudio.length < 2;
      } else {
        var defaultTrack = probeTracks.filter(function(t){return t.default;})[0] || probeTracks[0];
        if (probeTracks.length > 1 && play.remux_url) {
          var current = remux.track != null ? remux.track : (defaultTrack ? defaultTrack.relative : 0);
          probeTracks.forEach(function(t, k){
            var rel = t.relative != null ? t.relative : k;
            audio.appendChild(h('option',{value:String(rel),text:trackLabel(t,k,'Track') + (t.codec ? ' · ' + String(t.codec).toUpperCase() : '') + (t.channels ? ' · ' + t.channels + 'ch' : ''),selected: rel === current}));
          });
          audio.disabled = false;
        } else {
          audio.appendChild(h('option',{value:'',text:defaultTrack ? trackLabel(defaultTrack,0,'Default audio') : 'Default audio'}));
          audio.disabled = true;
        }
      }
      note.textContent = '';
      captionTracks = [];
      for (var j=0;j<media.textTracks.length;j++) {
        var textTrack = media.textTracks[j];
        if (textTrack.kind === 'subtitles' || textTrack.kind === 'captions') captionTracks.push(textTrack);
      }
      if (!subtitlesApplied && subtitlePreference != null) {
        if (subtitlePreference === 'off' || captionTracks.some(function(t,n){return subtitleId(t,n)===subtitlePreference;})) {
          subtitlesApplied = true;
          chooseSubtitle(subtitlePreference);
        }
      }
      var selected = 'off';
      captions.innerHTML = '';
      captions.appendChild(h('option',{value:'off',text:'Off'}));
      captionTracks.forEach(function(track,index){
        var id = subtitleId(track,index);
        if (track.mode === 'showing') selected = id;
        captions.appendChild(h('option',{value:id,text:trackLabel(track,index,'Subtitles')}));
      });
      if (play.subtitle_search) captions.appendChild(h('option',{value:'__opensubtitles',text:'Search OpenSubtitles…'}));
      if (play.subtitle_upload) captions.appendChild(h('option',{value:'__upload',text:'Upload subtitle…'}));
      captions.value = selected;
      captions.disabled = !captionTracks.length && !play.subtitle_search && !play.subtitle_upload;
      captions.title = captionTracks.length ? 'Select subtitles' : (play.subtitle_search ? 'Search OpenSubtitles for subtitles' : 'No subtitles available');
    }
    audio.onchange = function(){
      var index = Number(audio.value);
      var nativeAudio = media.audioTracks;
      if (nativeAudio && nativeAudio.length > 1) {
        var result = applyAudioTrack(media,index);
        if (result.ok) { audioPreference=index; audioApplied=true; setAudioPref(item.id,index); }
        else toast(result.note,{kind:'bad'});
      } else if (play.remux_url) {
        requestRemux(index).then(function(ok){
          if (ok) { audioPreference=index; audioApplied=true; setAudioPref(item.id,index); toast('Switching audio…', { ms:1400 }); }
          refresh();
        });
        return;
      } else toast('Audio switching is not available for this file.',{kind:'bad'});
      refresh();
    };
    captions.onchange = function(){
      if (captions.value === '__opensubtitles' || captions.value === '__upload') {
        var opener = captions.value === '__upload' ? openSubtitleUpload : openSubtitleSearch;
        refresh();
        opener(overlay, play, function(tracks, language, replaced){
          var fresh = addSubtitleTracks(tracks, replaced);
          var pick = fresh.filter(function(t){ return !language || t.language === language; })[0] || fresh[0];
          if (!pick) return;
          var select = function(){
            subtitlePreference = pick.id; subtitlesApplied = true;
            chooseSubtitle(pick.id); refresh();
            try { localStorage.setItem(subtitleKey,pick.id); } catch(e) {}
          };
          var el = media.querySelector('track[id="'+pick.id+'"]');
          if (el && el.track) { el.track.mode = 'showing'; refresh(); select(); } else select();
        });
        return;
      }
      subtitlePreference=captions.value; subtitlesApplied=true;
      chooseSubtitle(subtitlePreference);
      try { localStorage.setItem(subtitleKey,subtitlePreference); } catch(e) {}
      refresh();
    };
    var addedSubtitles = {};
    function addSubtitleTracks(list, replaced){
      var fresh = [];
      (list || []).forEach(function(track){
        if (!track) return;
        var reload = replaced && track.language === replaced && addedSubtitles[track.id] && /uploaded/.test(track.label || '');
        if (reload) { var old = media.querySelector('track[id="'+track.id+'"]'); if (old) old.remove(); }
        else if (addedSubtitles[track.id]) return;
        addedSubtitles[track.id] = true;
        fresh.push(track);
        var src = streamPlayUrl(track.url);
        if (reload) src += (src.indexOf('?') >= 0 ? '&' : '?') + 'v=' + Date.now();
        var element = h('track',{id:track.id,kind:'subtitles',label:track.label,src:src});
        if (track.language) element.srclang=track.language;
        element.addEventListener('load',refresh);
        element.addEventListener('error',function(){
          if (!overlay.isConnected) return;
          element.remove(); refresh(); toast('Could not load '+track.label,{kind:'bad'});
        });
        media.appendChild(element);
      });
      return fresh;
    }
    addSubtitleTracks(play.subtitles);
    ['loadedmetadata','loadeddata','canplay'].forEach(function(event){media.addEventListener(event,refresh);});
    [media.audioTracks,media.textTracks].forEach(function(list){
      if (!list || !list.addEventListener) return;
      ['addtrack','removetrack','change'].forEach(function(event){list.addEventListener(event,refresh);});
    });
    api('/items/'+item.id+'/probe').then(function(probe){
      probeTracks = probe && probe.ok ? (probe.audio_tracks || []) : [];
      probeDuration = probe && probe.duration ? Number(probe.duration) : 0;
      /* Saved choice that the browser cannot apply natively: start the remux at the current position. */
      var nat = media.audioTracks;
      if (!audioApplied && audioPreference != null && !(nat && nat.length > 1) && probeTracks.length > 1 && audioPreference < probeTracks.length) {
        var def = probeTracks.filter(function(t){return t.default;})[0] || probeTracks[0];
        audioApplied = true;
        if (audioPreference !== (def ? def.relative : 0) && play.remux_allowed) {
          var go = function(){ switchViaRemux(audioPreference); };
          if (media.readyState >= 1) go(); else media.addEventListener('loadedmetadata', go, { once:true });
        }
      }
      refresh();
    }).catch(function(){});
    refresh();
  }
  /* Upload an SRT/VTT/ASS/SSA file for the video behind play.token. done(tracks, language, language) */
  function openSubtitleUpload(host, play, done){
    var existing = $('#subtitle-upload-overlay');
    if (existing) existing.remove();
    var overlay = h('div', { id:'subtitle-upload-overlay', className:'confirm-overlay' });
    var card = h('div', { className:'confirm-card', style:'max-width:520px;width:92vw;text-align:left' });
    var file = h('input', { type:'file', accept:'.srt,.vtt,.ass,.ssa,text/vtt,application/x-subrip,text/plain' });
    var codes = ['en','fr','de','es','it','pt','nl','sv','da','no','fi','pl','cs','hu','ro','el','tr','ru','uk','ar','he','hi','ja','ko','zh'];
    var lang = h('select', { 'aria-label':'Subtitle language' }, codes.map(function(c){
      var name = c; try { name = new Intl.DisplayNames([navigator.language || 'en'], { type:'language' }).of(c) + ' (' + c + ')'; } catch (e) {}
      return h('option', { value:c, text:name });
    }));
    var guess = (navigator.language || 'en').slice(0,2).toLowerCase();
    lang.value = codes.indexOf(guess) >= 0 ? guess : 'en';
    file.addEventListener('change', function(){
      var m = file.files[0] && /\.([a-z]{2,3})\.(?:srt|vtt|ass|ssa)$/i.exec(file.files[0].name);
      if (m && codes.indexOf(m[1].toLowerCase()) >= 0) lang.value = m[1].toLowerCase();
    });
    var status = h('div', { className:'muted', role:'status', text:'SRT, WebVTT or ASS/SSA, up to 2 MB. Saved on the server for everyone.' });
    function close(){ overlay.remove(); }
    var send = h('button', { className:'btn primary', text:'Upload' });
    send.onclick = function(){
      var f = file.files && file.files[0];
      if (!f) { status.textContent = 'Choose a subtitle file first.'; return; }
      if (f.size > 2097152) { status.textContent = 'Subtitle files can be at most 2 MB.'; return; }
      send.disabled = true; status.textContent = 'Uploading…';
      fetch(BASE + 'api/stream/subtitles/' + play.token + '/upload?lang=' + encodeURIComponent(lang.value), {
        method:'POST', credentials:'same-origin', cache:'no-store', headers:{ 'Content-Type':'application/octet-stream' }, body:f
      }).then(function(r){ return r.json().catch(function(){ return { ok:false, error:'Upload failed (HTTP ' + r.status + ')' }; }); }).then(function(res){
        send.disabled = false;
        if (!res || !res.ok) { status.textContent = (res && res.error) || 'Upload failed'; return; }
        toast('Subtitles added', { kind:'ok' });
        close();
        done(res.subtitles || [], lang.value, lang.value);
      }).catch(function(){ send.disabled = false; status.textContent = 'Upload failed'; });
    };
    card.appendChild(h('h3', { text:'Upload subtitle' }));
    card.appendChild(h('div', { className:'stack' }, [ file, h('label', {}, [ h('span', { text:'Language' }), lang ]) ]));
    card.appendChild(status);
    card.appendChild(h('div', { className:'row-actions', style:'margin-top:12px' }, [ send, h('button', { className:'btn', text:'Cancel', onClick: close }) ]));
    overlay.appendChild(card);
    overlay.addEventListener('click', function(e){ if (e.target === overlay) close(); });
    (host || document.body).appendChild(overlay);
  }
  /* OpenSubtitles search for the video behind play.token. done(tracks, language) gets the refreshed track list. */
  function openSubtitleSearch(host, play, done){
    var existing = $('#subtitle-search-overlay');
    if (existing) existing.remove();
    var overlay = h('div', { id:'subtitle-search-overlay', className:'confirm-overlay' });
    var card = h('div', { className:'confirm-card', style:'max-width:640px;width:92vw;text-align:left' });
    var lang = h('input', { id:'os_lang', value: (navigator.language || 'en').slice(0,2).toLowerCase(), placeholder:'Languages e.g. en,fr', style:'flex:1' });
    var status = h('div', { className:'muted', role:'status', text:'' });
    var list = h('div', { className:'stack', style:'max-height:50vh;overflow:auto;margin-top:12px' });
    var base = '/stream/subtitles/' + play.token;
    function close(){ overlay.remove(); }
    function search(){
      status.textContent = 'Searching OpenSubtitles…';
      list.innerHTML = '';
      api(base + '/search?lang=' + encodeURIComponent(lang.value || '')).then(function(r){
        if (!r || !r.ok) { status.textContent = (r && r.error) || 'Search failed'; return; }
        var rows = r.results || [];
        status.textContent = rows.length ? rows.length + ' result(s). Downloads count towards the daily OpenSubtitles limit.' : 'No subtitles found.';
        rows.forEach(function(it){
          var btn = h('button', { className:'btn primary', text:'Use' });
          btn.onclick = function(){
            btn.disabled = true; status.textContent = 'Downloading…';
            api(base + '/download', { method:'POST', body:{ file_id: it.file_id, language: it.language } }).then(function(res){
              btn.disabled = false;
              if (!res || !res.ok) { status.textContent = (res && res.error) || 'Download failed'; toast(status.textContent, { kind:'bad' }); return; }
              toast('Subtitles saved' + (res.remaining != null ? ' · ' + res.remaining + ' download(s) left today' : ''), { kind:'ok' });
              close();
              done(res.subtitles || [], it.language);
            }).catch(function(){ btn.disabled = false; status.textContent = 'Download failed'; });
          };
          list.appendChild(h('div', { className:'mm-row' }, [
            h('div', { style:'flex:1;min-width:0' }, [
              h('strong', { text: it.release || 'Subtitle' }),
              h('div', { className:'muted', text: [it.language, it.hash_match ? 'exact file match' : null, it.downloads ? it.downloads + ' downloads' : null,
                it.hearing_impaired ? 'SDH' : null, it.machine_translated ? 'machine translated' : null].filter(Boolean).join(' · ') })
            ]),
            btn
          ]));
        });
      }).catch(function(){ status.textContent = 'Search failed'; });
    }
    lang.addEventListener('keydown', function(e){ if (e.key === 'Enter') search(); });
    card.appendChild(h('h3', { text:'Search OpenSubtitles' }));
    card.appendChild(h('div', { className:'row-actions' }, [ lang, h('button', { className:'btn primary', text:'Search', onClick: search }) ]));
    card.appendChild(status);
    card.appendChild(list);
    card.appendChild(h('div', { className:'row-actions', style:'margin-top:12px' }, [ h('button', { className:'btn', text:'Close', onClick: close }) ]));
    overlay.appendChild(card);
    overlay.addEventListener('click', function(e){ if (e.target === overlay) close(); });
    (host || document.body).appendChild(overlay);
    search();
  }
  function openAlternateFinder(opts){
    opts = opts || {};
    var title = opts.title || '';
    var endpoint = opts.endpoint;
    var body = opts.body || {};
    var existing = $('#alt-overlay');
    if (existing) existing.remove();
    var overlay = h('div', { id:'alt-overlay', className:'confirm-overlay' });
    var card = h('div', { className:'confirm-card', style:'max-width:720px;width:92vw;text-align:left' });
    card.appendChild(h('h3', { text:'Find alternate version' }));
    card.appendChild(h('p', { className:'muted', text:'Uses admin RSS quality/language filters. Download queue remains admin-only.' }));
    card.appendChild(h('div', { className:'row-actions' }, [
      h('input', { id:'alt_q', value: title, style:'flex:1', placeholder:'Search query' }),
      h('input', { id:'alt_quality', placeholder:'Quality e.g. 1080p', style:'width:110px' }),
      h('input', { id:'alt_lang', placeholder:'Lang e.g. English', style:'width:110px' })
    ]));
    var list = h('div', { id:'alt_list', className:'stack', style:'max-height:50vh;overflow:auto;margin-top:12px' });
    var status = h('div', { id:'alt_status', className:'muted', text:'Idle' });
    card.appendChild(status);
    card.appendChild(list);
    card.appendChild(h('div', { className:'row-actions', style:'margin-top:12px' }, [
      h('button', { className:'btn primary', text:'Search', onClick: function(){
        status.textContent = 'Searching…';
        list.innerHTML = '';
        var payload = Object.assign({}, body, {
          query: ($('#alt_q')||{}).value || title,
          quality: ($('#alt_quality')||{}).value || '',
          language: ($('#alt_lang')||{}).value || ''
        });
        api(endpoint, { method:'POST', body: payload }).then(function(r){
          if (!r.ok) { status.textContent = r.reason || r.error || 'Failed'; return; }
          status.textContent = 'Query: '+(r.query_used||'')+' · '+(r.count|| (r.items||[]).length)+' hit(s)';
          (r.items||[]).forEach(function(it){
            list.appendChild(h('div', { className:'mm-row' }, [
              h('div', { style:'flex:1;min-width:0' }, [
                h('strong', { text: it.title || it.name || 'Result' }),
                h('div', { className:'muted', text: [it.size, it.seeds!=null?('seeds '+it.seeds):null, it.quality, it.language].filter(Boolean).join(' · ') })
              ]),
              h('button', { className:'btn primary', text:'Get', disabled:!canManageDownloads(), onClick: function(){
                api('/search/get', { method:'POST', body:{
                  title: it.title || it.name || title,
                  magnet: it.magnet || '',
                  link: it.link || '',
                  enclosure: it.enclosure || '',
                  query: r.query_used || title,
                  kind: body.kind || 'alternate',
                  section_id: body.section_id || '',
                  show: body.show || null
                }}).then(function(res){
                  toast(res.ok ? 'Queued download' : (res.error||'Queue failed'), { kind: res.ok?'ok':'bad' });
                });
              }})
            ]));
          });
          if (!(r.items||[]).length) list.appendChild(h('p', { className:'muted', text:'No RSS results. Check Admin → Acquisition feeds/filters.' }));
        }).catch(function(e){ status.textContent = String(e); });
      }}),
      h('button', { className:'btn ghost', text:'Close', onClick: function(){ overlay.remove(); } })
    ]));
    overlay.appendChild(card);
    document.body.appendChild(overlay);
  }


  function $(sel, el){ return (el||document).querySelector(sel); }
  function $all(sel, el){ return Array.prototype.slice.call((el||document).querySelectorAll(sel)); }
  function h(tag, attrs, kids){
    var el = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function(k){
      if (k === 'className') el.className = attrs[k];
      else if (k === 'text') el.textContent = attrs[k];
      else if (k === 'html') el.innerHTML = attrs[k];
      else if (k.slice(0,2) === 'on' && typeof attrs[k] === 'function') el.addEventListener(k.slice(2).toLowerCase(), attrs[k]);
      else if (attrs[k] !== false && attrs[k] != null) el.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
    });
    (kids||[]).forEach(function(c){ if (c == null) return; el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return el;
  }
  // The shape reserves space on a cold request; real content replaces it before paint on cache hits.
  function pendingContent(attrs){
    attrs = Object.assign({}, attrs || {});
    attrs.className = (attrs.className || '') + ' pending-content';
    attrs.role = 'status';
    return h('p', attrs);
  }
  function toast(msg, opts){
    opts = opts || {};
    var t = $('#toast');
    if (!t) return;
    t.hidden = false; t.textContent = msg;
    t.className = 'toast' + (opts.kind ? (' toast-' + opts.kind) : '');
    clearTimeout(toast._t); toast._t = setTimeout(function(){ t.hidden = true; }, opts.ms || 2800);
  }
  /* admin convert-request inbox notification */
  state.convertPendingCount = 0;
  state._convertNotifTimer = null;
  function updateConvertNotif(count){
    count = Math.max(0, parseInt(count, 10) || 0);
    state.convertPendingCount = count;
    var bar = $('#convert-notif');
    if (!bar) {
      bar = h('button', {
        id: 'convert-notif',
        className: 'convert-notif',
        type: 'button',
        hidden: true,
        onClick: function(){ openSettings('convert'); }
      });
      document.body.appendChild(bar);
    }
    if (count > 0) {
      bar.hidden = false;
      bar.textContent = count + ' TV convert request' + (count === 1 ? '' : 's') + ' - click to review';
      bar.setAttribute('aria-label', bar.textContent);
    } else {
      bar.hidden = true;
      bar.textContent = '';
    }
    $all('.cv-pending-badge').forEach(function(el){
      if (count > 0) {
        el.hidden = false;
        el.textContent = String(count);
      } else {
        el.hidden = true;
        el.textContent = '';
      }
    });
  }
  function refreshConvertNotif(){
    if (!(state.user && state.user.role === 'admin')) {
      updateConvertNotif(0);
      return Promise.resolve(0);
    }
    return api('/admin/convert/pending').then(function(r){
      var n = (r && r.ok) ? (r.count || (r.pending_requests || []).length || 0) : 0;
      updateConvertNotif(n);
      return n;
    }).catch(function(){ return state.convertPendingCount || 0; });
  }
  function startConvertNotifPoll(){
    if (state._convertNotifTimer) return;
    refreshConvertNotif();
    state._convertNotifTimer = setInterval(function(){
      if (!(state.user && state.user.role === 'admin')) {
        updateConvertNotif(0);
        return;
      }
      refreshConvertNotif();
    }, 45000);
  }

  /* Settings → Download sources: checklist of built-in public-domain feeds + custom feeds.
   Stored format is unchanged (list of {url,name,type?}), plus optional enabled/builtin. */
function feedValidateCustom(name, url){
  name = String(name||'').trim(); url = String(url||'').trim();
  if (!name) return 'Give the feed a name.';
  if (!/^https?:\/\//i.test(url)) return 'Feed URL must start with http:// or https://';
  if (/\s/.test(url)) return 'Feed URL must not contain spaces.';
  try { var u = new URL(url.replace(/\{q(uery)?\}/g, 'x')); if (!u.hostname) throw 0; } catch(e){ return 'Feed URL is not a valid web address.'; }
  return '';
}
function feedSourcesEditor(stored){
  var list = (Array.isArray(stored) ? stored : []).map(function(f){ return typeof f === 'string' ? { url:f } : Object.assign({}, f); }).filter(function(f){ return f && f.url; });
  var catalogue = [];
  var wrap = h('div', { className:'stack feed-sources' });
  var listEl = h('div', { className:'stack' });
  var json = h('textarea', { rows:'8', style:'width:100%;font-family:monospace' });
  var adv = h('details', {}, [ h('summary', { text:'Advanced: edit JSON' }), h('p', { className:'muted', text:'Array of {url, name, type?, enabled?}. Changes here replace the list above when you apply them.' }), json,
    h('div', { className:'row-actions' }, [ h('button', { className:'btn', text:'Apply JSON', onClick:function(){
      var v; try { v = JSON.parse(json.value); } catch(e){ return toast('Bad feeds JSON', { kind:'bad' }); }
      if (!Array.isArray(v)) return toast('Feeds JSON must be an array', { kind:'bad' });
      list = v.map(function(f){ return typeof f === 'string' ? { url:f } : f; }).filter(function(f){ return f && f.url; }); paint();
    } }) ]) ]);
  function isOn(f){ return f.enabled !== false; }
  function find(url){ for (var i = 0; i < list.length; i++) if (list[i].url === url) return list[i]; return null; }
  function row(title, desc, url, checked, onToggle, onRemove){
    return h('div', { className:'tx-row' }, [
      h('label', { className:'check-label', style:'flex:1;min-width:0' }, [
        h('input', { type:'checkbox', checked: !!checked, onChange:function(ev){ onToggle(ev.currentTarget.checked); } }),
        h('span', {}, [ h('strong', { text:title }), h('div', { className:'muted', text:desc }), h('div', { className:'muted', style:'font-size:.8em;word-break:break-all', text:url }) ])
      ]),
      onRemove ? h('span', { className:'row-actions' }, [ h('button', { className:'btn ghost danger-outline', text:'Remove', onClick:onRemove }) ]) : null
    ].filter(Boolean));
  }
  function paint(){
    listEl.innerHTML = '';
    var builtinUrls = {};
    catalogue.forEach(function(b){
      builtinUrls[b.url] = 1;
      var cur = find(b.url);
      listEl.appendChild(row(b.name, b.description, b.url, cur && isOn(cur), function(on){
        var c = find(b.url);
        if (c) c.enabled = on; else if (on) list.push({ url:b.url, name:b.name, type:b.type, builtin:b.id, enabled:true });
        json.value = JSON.stringify(list, null, 2);
      }, null));
    });
    var custom = list.filter(function(f){ return !builtinUrls[f.url]; });
    if (custom.length) listEl.appendChild(h('strong', { text:'Custom feeds' }));
    custom.forEach(function(f){
      listEl.appendChild(row(f.name || f.url, f.type ? ('Type: ' + f.type) : 'Custom feed', f.url, isOn(f), function(on){ f.enabled = on; json.value = JSON.stringify(list, null, 2); }, function(){
        confirmDialog('Remove the custom feed “' + (f.name || f.url) + '”?', { okText:'Remove', danger:true }).then(function(ok){ if (!ok) return; list = list.filter(function(x){ return x !== f; }); paint(); });
      }));
    });
    json.value = JSON.stringify(list, null, 2);
  }
  var nameIn = h('input', { type:'text', placeholder:'Name' });
  var urlIn = h('input', { type:'url', placeholder:'https://example.org/rss?q={query}', style:'flex:2' });
  var addRow = h('div', { className:'row-actions' }, [ nameIn, urlIn, h('button', { className:'btn', text:'Add custom feed', onClick:function(){
    var err = feedValidateCustom(nameIn.value, urlIn.value);
    if (!err && find(urlIn.value.trim())) err = 'That feed is already in the list.';
    if (err) return toast(err, { kind:'bad' });
    list.push({ url:urlIn.value.trim(), name:nameIn.value.trim(), enabled:true });
    nameIn.value = ''; urlIn.value = ''; paint();
  } }) ]);
  wrap.appendChild(listEl);
  wrap.appendChild(h('strong', { text:'Add custom feed' }));
  wrap.appendChild(addRow);
  wrap.appendChild(adv);
  paint();
  api('/admin/acquisition/feed-catalogue').then(function(r){ catalogue = (r && r.feeds) || []; paint(); }).catch(function(){});
  return { el: wrap, value: function(){ return list.slice(); } };
}
function confirmDialog(message, opts){
    opts = opts || {};
    return new Promise(function(resolve){
      var existing = $('#confirm-overlay');
      if (existing) existing.remove();
      var overlay = h('div', { id:'confirm-overlay', className:'confirm-overlay' }, [
        h('div', { className:'confirm-card' }, [
          h('p', { text: message }),
          h('div', { className:'row-actions' }, [
            h('button', { className:'btn ghost', text: opts.cancelText || 'Cancel', onClick: function(){ overlay.remove(); resolve(false); } }),
            h('button', { className:'btn ' + (opts.danger ? 'danger' : 'primary'), text: opts.okText || 'Confirm', onClick: function(){ overlay.remove(); resolve(true); } })
          ])
        ])
      ]);
      ((opts.host && opts.host.isConnected) ? opts.host : document.body).appendChild(overlay);
    });
  }
  function emptyState(title, detail, actions){
    return h('div', { className:'empty-state' }, [
      h('div', { className:'empty-icon', text:'∅' }),
      h('h3', { text: title || 'Nothing here' }),
      detail ? h('p', { className:'muted', text: detail }) : null,
      actions ? h('div', { className:'row-actions', style:'justify-content:center' }, actions) : null
    ].filter(Boolean));
  }
  /** Playback progress: never await. Prefer sendBeacon so a busy php-cgi cannot stall the UI. */
  function postProgress(itemId, position, duration){
    var body = JSON.stringify({ item_id: itemId, position: position || 0, duration: duration || 0 });
    try {
      if (typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
        var blob = new Blob([body], { type: 'application/json' });
        if (navigator.sendBeacon(API + '/progress', blob)) return;
      }
    } catch (eB) {}
    try {
      fetch(API + '/progress', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true }).catch(function(){});
    } catch (eF) {}
  }
  var navigationCache = window.createRequestCache();
  function api(path, opts){
    opts = opts || {};
    var method = String(opts.method || 'GET').toUpperCase();
    if (method !== 'GET') {
      if (/^\/hub\/(?:game|rooms|shelf|review|friend|invite|dismiss|saves|lists|chat)(?:\/|$)/.test(path)) return apiRequest(path,opts);
      if (/^\/items\/[a-f0-9]+\/play$/.test(path) || path === '/admin/posters/audit') return apiRequest(path, opts);
      navigationCache.clear();
      return apiRequest(path, opts).then(function(j){ navigationCache.clear(); return j; }, function(error){ navigationCache.clear(); throw error; });
    }
    var cacheable = method === 'GET' && !opts.signal && /^(?:\/home$|\/sections(?:$|\/)|\/shows\/[a-f0-9]+$|\/items\/[a-f0-9]+(?:\?synopsis=[01])?$|\/collections(?:$|\/)|\/resolve\?)/.test(path);
    if (!cacheable) return apiRequest(path, opts);
    var userKey = state.user ? state.user.id : 'guest';
    return navigationCache.read(userKey + ':' + path, function(){ return apiRequest(path, opts); });
  }
  function apiRequest(path, opts){
    opts = opts || {};
    var requestUser=state.user && state.user.id;
    var headers = opts.headers || {};
    if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(opts.body);
    }
    var fetchOpts = Object.assign({ credentials:'same-origin' }, opts, { headers: headers });
    if (opts.signal) fetchOpts.signal = opts.signal;
    return fetch(API + path, fetchOpts)
      .then(function(r){
        return r.json().catch(function(){ return { ok:false, error:'Bad JSON' }; }).then(function(j){
          var sameUser=requestUser===(state.user && state.user.id);
          if ((r.status === 401 || r.status === 403) && sameUser) navigationCache.clear();
          if (r.status === 401 && sameUser) {
            state.user = null;
            if (state.view === 'admin' || state._wantAdmin) {
              state._wantAdmin = true;
              state.view = 'login';
              render();
            } else if (!state._guest && state.view !== 'welcome' && state.view !== 'login') {
              showWelcome();
            }
          }
          if (sameUser && r.status === 403 && j && j.code === 'access_denied' && state.user && state.user.role !== 'admin') {
            state.user.access_state = j.access || { state:'expired', active:false };
            if (state.view !== 'welcome') showWelcome();
          }
          if (!r.ok && !j.error) j.error = 'HTTP ' + r.status;
          j._status = r.status;
          if (j.maintenance_job) {
            toast('Task started. Manage or cancel it in Settings > Background tasks.');
            function waitForTask(){
              return api('/admin/maintenance/' + encodeURIComponent(j.maintenance_job)).then(function(status){
                if (!status.ok || !status.job) return {ok:false,error:'Could not read task status. Check Background tasks.'};
                var job=status.job;
                if(job.status==='complete') return job.result || {ok:true};
                if(job.status==='failed'||job.status==='cancelled') return {ok:false,error:job.error || job.status};
                return new Promise(function(resolve){setTimeout(resolve,1500);}).then(waitForTask);
              });
            }
            return waitForTask();
          }
          return j;
        });
      });
  }
  function fmtSize(n){
    if (!n) return '';
    var u=['B','KB','MB','GB','TB']; var i=0; n=+n;
    while(n>1024 && i<u.length-1){ n/=1024; i++; }
    return n.toFixed(i?1:0)+' '+u[i];
  }

  /* Browser-first posters: Cache API keyed by item id, server only on miss */
  var POSTER_CACHE = 'vaultcast-posters-v2';
  var POSTER_CACHE_MAX_AGE = 60 * 60 * 1000;
  var _posterMem = Object.create(null);
  var posterRevision = '2';
  try {posterRevision = localStorage.getItem('vaultcast-artwork-revision') || '2';} catch (eArtRevision) {}
  function refreshArtwork(){
    posterRevision = String(Date.now());
    try {localStorage.setItem('vaultcast-artwork-revision', posterRevision);} catch (eArtRevision) {}
    _posterMem = Object.create(null); _posterRequests = Object.create(null);
    navigationCache.clear();
    return window.caches ? caches.delete(POSTER_CACHE).catch(function(){}) : Promise.resolve();
  }

  function posterCacheRequest(cacheId, variant){
    var key = String(cacheId || '') + '|' + String(variant || 'full') + '|' + (supportsWebP() ? 'w1' : 'w0') + '|' + posterRevision;
    return new Request(BASE + 'poster-cache/' + encodeURIComponent(key), { credentials:'same-origin' });
  }
  /* WebP feature-detect once — non-supporting browsers get original JPG/PNG */
  var _supportsWebP = null;
  function supportsWebP(){
    if (_supportsWebP != null) return _supportsWebP;
    try {
      _supportsWebP = !!(document.createElement('canvas').toDataURL('image/webp').indexOf('data:image/webp') === 0);
    } catch (eW) { _supportsWebP = false; }
    return _supportsWebP;
  }
  function posterServerUrl(cacheId, kind, fetchRemote, variant){
    var q = fetchRemote ? '?fetch=1' : '?fetch=0';
    variant = variant || 'full';
    q += '&variant=' + encodeURIComponent(variant);
    q += supportsWebP() ? '&webp=1' : '&webp=0';
    q += '&art=' + posterRevision;
    if (kind === 'show') return API + '/shows/' + cacheId + '/poster' + q;
    if (kind === 'season') {
      // cacheId = showKey + '-s' + season
      var m = String(cacheId || '').match(/^([a-f0-9]+)-s(\d+)$/i);
      if (m) return API + '/shows/' + m[1] + '/seasons/' + m[2] + '/poster' + q;
      return API + '/shows/' + cacheId + '/poster' + q;
    }
    return API + '/items/' + cacheId + '/poster' + q;
  }
  function isShowItem(item){
    return !!(item && (item.kind === 'show' || item.media_kind === 'show'));
  }
  /* Poster kind: episode → season art; season miss falls back to show on the API. */
  function posterKindFor(item){
    if (!item) return 'item';
    if (isShowItem(item)) return 'show';
    if (item.show_key && (item.type === 'tv' || item.type === 'anime')) {
      if (item.season != null && item.season !== '' && !isNaN(Number(item.season))) return 'season';
      return 'show';
    }
    return 'item';
  }
  function posterIdFor(item){
    if (!item) return null;
    var kind = posterKindFor(item);
    if (kind === 'show') return item.show_key || item.id;
    if (kind === 'season') {
      var sk = item.show_key || item.id;
      return sk + '-s' + String(Number(item.season));
    }
    return item.id;
  }
  function posterNeedsRemote(item){
    if (!item) return false;
    var t = item.type || '';
    if (t === 'tv' || t === 'anime' || t === 'movies' || t === 'disney' || t === 'roms') return true;
    if (item.media_kind === 'rom') return true;
    if (item.media_kind === 'video' && !item.show) return true;
    return isShowItem(item);
  }
  function setPosterBg(el, url){
    if (!el || !url) return;
    el.style.backgroundImage = 'url("'+String(url).replace(/"/g,'%22')+'")';
    el.style.backgroundSize = 'cover';
    el.style.backgroundPosition = 'center';
    el.style.backgroundRepeat = 'no-repeat';
    el.classList.add('poster-ready');
    el.classList.remove('poster-pending');
    el.classList.remove('poster-failed');
    var ph = el.querySelector('.ph');
    if (ph) ph.style.opacity = '0';
    /* detail/billboard heroes reuse the poster as a blurred cinematic backdrop */
    try {
      if (el.hasAttribute('data-backdrop-src') && el.closest) {
        var host = el.closest('[data-backdrop-host]');
        if (host) { host.style.setProperty('--backdrop', 'url("'+String(url).replace(/"/g,'%22')+'")'); host.classList.add('has-backdrop'); }
      }
    } catch (eBd) {}
  }

  function needsConvertExt(item){
    if (!item) return false;
    if (item.needs_convert) return true;
    var ext = String(item.ext || '').toLowerCase();
    if (!ext) return false;
    return ['mkv','avi','wmv','mov','ts','m2ts','mpg','mpeg','flv','divx','xvid'].indexOf(ext) >= 0;
  }

  var _dlStatus = null;
  var _dlStatusAt = 0;
  function userAreaAllowed(area){
    if (state.user && state.user.role === 'admin') return true;
    var areas = state.user && state.user.areas;
    if (!areas && state.home) areas = state.home.areas;
    return !areas || areas[area] === true;
  }
  function can(permission){return !!(state.user&&(state.user.role==='admin'||state.user.role!=='user'&&state.user.capabilities&&state.user.capabilities[permission]===true));}
  function canSearchRss(){return can('rss_search')&&!!(state.home&&state.home.features&&state.home.features.downloads);}
  function canManageDownloads(){return can('downloads_request')&&!!(state.home&&state.home.features&&state.home.features.downloads);}
  function loadDownloadStatus(force){
    if (!canManageDownloads()) { _dlStatus = null; return Promise.resolve({ok:true,downloading:[]}); }
    var now = Date.now();
    if (!force && _dlStatus && (now - _dlStatusAt) < 8000) return Promise.resolve(_dlStatus);
    return api('/downloads/status').then(function(r){
      _dlStatus = r;
      _dlStatusAt = Date.now();
      return r;
    }).catch(function(){ return _dlStatus || { ok:false, downloading:[] }; });
  }

  function matchDownload(showTitle, season, episode, dl){
    if (!dl) return null;
    var code = epCode(season, episode).toUpperCase();
    var showL = String(showTitle || '').toLowerCase();
    var list = dl.downloading || [];
    for (var i=0;i<list.length;i++){
      var t = list[i];
      var name = String(t.name || '');
      var matchEp = t.season == season && t.episode == episode;
      var nameHas = code && name.toUpperCase().indexOf(code) >= 0;
      if (!matchEp && !nameHas) continue;
      if (showL) {
        var guess = String(t.show_guess || name).toLowerCase();
        var token = showL.split(/\s+/)[0];
        if (token && guess.indexOf(token) < 0 && name.toLowerCase().indexOf(token) < 0) {
          if (!nameHas) continue;
        }
      }
      return { kind:'badge', name: name, percent: t.percent, eta_label: null, status: t.status || 'downloading', queue_id: t.queue_id || null };
    }
    return null;
  }

  function enqueueConvert(item, btn){
    if (!item || !item.id) return toast('Missing item');
    if (!state.user || state.user.role !== 'admin') return toast('Admin login required to convert');
    if (btn) { btn.disabled = true; btn.textContent = '…'; }
    api('/admin/convert', { method:'POST', body:{ item_id: item.id } }).then(function(r){
      if (btn) { btn.disabled = false; btn.textContent = 'Convert'; }
      if (!r.ok) return toast(r.reason || r.error || 'Convert failed');
      toast(r.message || ('Queued convert: ' + (r.entry && r.entry.title || item.title || item.id)));
    }).catch(function(e){
      if (btn) { btn.disabled = false; btn.textContent = 'Convert'; }
      toast(String(e));
    });
  }
  var POSTER_PARALLEL = 2;
  var _posterRequests = Object.create(null);
  var _posterQueue = [];
  var _posterActive = 0;

  function posterInDom(el){
    if (!el || !el.parentNode) return false;
    if (el.isConnected === false) return false;
    return true;
  }
  function posterPriority(el){
    var r = el.getBoundingClientRect();
    var vh = window.innerHeight || 800;
    var vw = window.innerWidth || 1200;
    var cy = r.top + r.height / 2;
    var cx = r.left + r.width / 2;
    var dist = Math.abs(cy - vh / 2) + Math.abs(cx - vw / 3) * 0.35;
    if (r.bottom < 0 || r.top > vh) dist += 2000;
    return dist;
  }
  function markPosterFail(el){
    if (!el) return;
    el.classList.add('poster-failed');
    el.classList.remove('poster-pending');
    el.removeAttribute('data-poster-loaded');
    el.removeAttribute('data-poster-queued');
    var ph = el.querySelector('.ph');
    if (ph) ph.style.opacity = '';
  }
  function fetchPosterServer(url, timeoutMs){
    var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var timer = setTimeout(function(){ if (ctrl) ctrl.abort(); }, timeoutMs || 7000);
    return fetch(url, {
      credentials: 'same-origin',
      cache: 'default',
      signal: ctrl ? ctrl.signal : undefined
    }).then(function(res){
      clearTimeout(timer);
      return res;
    }, function(err){
      clearTimeout(timer);
      throw err;
    });
  }
  var _posterPumpTimer = null;
  function pumpPosterQueue(){
    var deferred = [];
    while (_posterActive < POSTER_PARALLEL && _posterQueue.length) {
      var job = _posterQueue.shift();
      if (!job || !job.el) continue;
      if (job.el.getAttribute('data-poster-loaded') === '1') continue;
      /* Heroes call loadPosterNow before appendChild — do not drop the job or the
         data-poster-queued flag permanently blocks IntersectionObserver retries. */
      if (!posterInDom(job.el)) {
        deferred.push(job);
        continue;
      }
      _posterActive++;
      runPosterJob(job).then(function(){
        _posterActive--;
        pumpPosterQueue();
      }, function(){
        _posterActive--;
        pumpPosterQueue();
      });
    }
    if (deferred.length) {
      for (var di = 0; di < deferred.length; di++) _posterQueue.push(deferred[di]);
      if (!_posterPumpTimer) {
        _posterPumpTimer = setTimeout(function(){
          _posterPumpTimer = null;
          _posterQueue = _posterQueue.filter(function(j){
            if (!j || !j.el) return false;
            if (j.el.getAttribute('data-poster-loaded') === '1') return false;
            j._wait = (j._wait || 0) + 1;
            if (j._wait > 60) { // ~3s of 50ms retries
              try { j.el.removeAttribute('data-poster-queued'); } catch (eW) {}
              return false;
            }
            return true;
          });
          pumpPosterQueue();
        }, 50);
      }
    }
  }
  function runPosterJob(job){
    var el = job.el;
    var itemId = job.itemId;
    var kind = job.kind;
    var variant = job.variant || el.getAttribute('data-poster-variant') || 'full';
    var memKey = String(itemId) + '|' + variant + '|' + (supportsWebP() ? 'w1' : 'w0') + '|' + posterRevision;
    var key = posterCacheRequest(itemId, variant);
    return new Promise(function(resolve){
      var finished = false;
      function done(){ if (finished) return; finished = true; resolve(); }
      function stillWanted(){
        return posterInDom(el) && el.getAttribute('data-poster-id') === String(itemId);
      }
      function paint(b){
        if (finished || !stillWanted() || !b || b.size < 80) return false;
        var u = URL.createObjectURL(b);
        _posterMem[memKey] = u;
        el.setAttribute('data-poster-loaded', '1');
        setPosterBg(el, u);
        done();
        return true;
      }
      if (_posterMem[memKey]) {
        if (stillWanted()) {
          el.setAttribute('data-poster-loaded', '1');
          setPosterBg(el, _posterMem[memKey]);
        }
        done();
        return;
      }
      var startedNet = false;
      function startNet(){
        if (startedNet || finished) return;
        startedNet = true;
        // Remote discovery belongs to the metadata maintenance worker.
        var allowFetch = false;
        var url = posterServerUrl(itemId, kind, !!allowFetch, variant);
        // Share bytes across duplicate tiles; keep the deadline through body download.
        if (!_posterRequests[url]) {
          var ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
          var deadline = setTimeout(function(){ if (ctrl) ctrl.abort(); }, 4000);
          var request = fetch(url, { credentials:'same-origin', cache:'default', signal:ctrl ? ctrl.signal : undefined })
            .then(function(res){
              if (!res.ok) throw new Error('Poster HTTP ' + res.status);
              return res.blob();
            });
          _posterRequests[url] = request.then(function(blob){
            clearTimeout(deadline); delete _posterRequests[url]; return blob;
          }, function(error){
            clearTimeout(deadline); delete _posterRequests[url]; throw error;
          });
        }
        _posterRequests[url].then(function(b){
          if (!paint(b)) { if (!finished) { markPosterFail(el); done(); } return; }
          if (typeof caches !== 'undefined') {
            caches.open(POSTER_CACHE).then(function(c){ return c.put(key, new Response(b, { headers:{ 'X-Vaultcast-Cached-At':String(Date.now()) } })); }).catch(function(){});
          }
        }).catch(function(){
          if (stillWanted()) markPosterFail(el);
          done();
        });
      }
      var cacheTimer = setTimeout(function(){ startNet(); }, 900);
      var cacheP = (typeof caches !== 'undefined')
        ? caches.open(POSTER_CACHE).then(function(c){ return c.match(key); }).catch(function(){ return null; })
        : Promise.resolve(null);
      cacheP.then(function(cached){
        if (finished) return;
        var cachedAt = cached && Number(cached.headers.get('X-Vaultcast-Cached-At'));
        if (cached && cachedAt > 0 && Date.now() - cachedAt >= 0 && Date.now() - cachedAt < POSTER_CACHE_MAX_AGE) {
          clearTimeout(cacheTimer);
          return cached.blob().then(function(b){
            if (paint(b)) return;
            startNet();
          }).catch(function(){ startNet(); });
        }
        clearTimeout(cacheTimer);
        startNet();
      });
    });
  }
  /* clear load flags so a reused hero/tile can swap to a new poster id */
  function resetPosterEl(el){
    if (!el) return;
    try {
      if (_posterObserver) _posterObserver.unobserve(el);
    } catch (eUn) {}
    el.removeAttribute('data-poster-loaded');
    el.removeAttribute('data-poster-queued');
    el.removeAttribute('data-poster-id');
    el.removeAttribute('data-poster-kind');
    el.removeAttribute('data-poster-fetch');
    el.classList.remove('poster-ready', 'poster-failed');
    el.classList.add('poster-pending');
    el.style.backgroundImage = '';
    var ph = el.querySelector('.ph');
    if (ph) ph.style.opacity = '';
  }

  function loadPosterNow(el, itemId, kind){
    if (!itemId || !el) return;
    /* Already painted for this exact id — skip. Different id must reload. */
    if (el.getAttribute('data-poster-loaded') === '1' && el.getAttribute('data-poster-id') === String(itemId)) return;
    if (el.getAttribute('data-poster-loaded') === '1') {
      el.removeAttribute('data-poster-loaded');
      el.removeAttribute('data-poster-queued');
    }
    kind = kind || el.getAttribute('data-poster-kind') || 'item';
    var variant = el.getAttribute('data-poster-variant') || 'full';
    el.setAttribute('data-poster-id', String(itemId));
    el.setAttribute('data-poster-kind', kind);
    var memKey = String(itemId) + '|' + variant + '|' + (supportsWebP() ? 'w1' : 'w0');
    if (_posterMem[memKey]) {
      el.setAttribute('data-poster-loaded', '1');
      setPosterBg(el, _posterMem[memKey]);
      return;
    }
    if (el.getAttribute('data-poster-queued') === '1') return;
    el.setAttribute('data-poster-queued', '1');
    el.classList.add('poster-pending');
    _posterQueue.push({ el: el, itemId: itemId, kind: kind, variant: variant, pri: posterPriority(el) });
    _posterQueue.sort(function(a, b){ return a.pri - b.pri; });
    pumpPosterQueue();
  }

  /* Lazy-load: visible tiles first, at most POSTER_PARALLEL server fetches. */
  var _posterObserver = null;
  function ensurePosterObserver(){
    if (_posterObserver || typeof IntersectionObserver === 'undefined') return _posterObserver;
    _posterObserver = new IntersectionObserver(function(entries){
      var visible = [];
      entries.forEach(function(entry){
        if (!entry.isIntersecting) return;
        visible.push(entry);
      });
      visible.sort(function(a, b){
        return posterPriority(a.target) - posterPriority(b.target);
      });
      visible.forEach(function(entry){
        var el = entry.target;
        var id = el.getAttribute('data-poster-id');
        var k = el.getAttribute('data-poster-kind') || 'item';
        if (id) loadPosterNow(el, id, k);
        _posterObserver.unobserve(el);
      });
    }, { root: null, rootMargin: '180px 0px', threshold: 0.01 });
    return _posterObserver;
  }
  function observePoster(el, itemId, kind){
    if (!el || !itemId) return;
    el.setAttribute('data-poster-id', itemId);
    el.setAttribute('data-poster-kind', kind || 'item');
    if (!el.getAttribute('data-poster-variant')) el.setAttribute('data-poster-variant', 'medium');
    el.classList.add('poster-pending');
    var variant = el.getAttribute('data-poster-variant') || 'medium';
    var memKey = String(itemId) + '|' + variant + '|' + (supportsWebP() ? 'w1' : 'w0');
    if (_posterMem[memKey]) {
      loadPosterNow(el, itemId, kind);
      return;
    }
    var obs = ensurePosterObserver();
    if (obs) {
      obs.observe(el);
      return;
    }
    requestAnimationFrame(function(){
      var r = el.getBoundingClientRect();
      var vh = window.innerHeight || document.documentElement.clientHeight || 800;
      if (r.top < vh + 180 && r.bottom > -80) loadPosterNow(el, itemId, kind);
      else {
        var onScroll = function(){
          var rr = el.getBoundingClientRect();
          var h = window.innerHeight || 800;
          if (rr.top < h + 180 && rr.bottom > -80) {
            loadPosterNow(el, itemId, kind);
            window.removeEventListener('scroll', onScroll, true);
          }
        };
        window.addEventListener('scroll', onScroll, true);
      }
    });
  }

  function cardEl(item, progress){
    var isShow = isShowItem(item);
    var pKind = posterKindFor(item);
    var pid = posterIdFor(item);
    var poster = h('div', { className:'poster poster-pending' }, [
      h('div', { className:'ph', text: (item.show && !isShowItem(item)) ? item.show : (item.title || 'Untitled') })
    ]);
    /* Load cached art, or lazy-fetch on first view (movies/shows/games/CW episodes). */
    if (pid && (item.has_poster || posterNeedsRemote(item))) {
      if (posterNeedsRemote(item)) poster.setAttribute('data-poster-fetch', '1');
      observePoster(poster, pid, pKind);
    }
    if (progress && progress.duration) {
      var pct = Math.min(100, Math.round(100 * progress.position / progress.duration));
      poster.appendChild(h('div', { className:'progress-bar' }, [ h('span', { style:'width:'+pct+'%' }) ]));
    }
    var sub = [];
    if (isShow) {
      if (item.year) sub.push(String(item.year));
      if (item.season_count) sub.push(item.season_count + ' season' + (item.season_count===1?'':'s'));
      if (item.episode_count) sub.push(item.episode_count + ' ep');
    } else {
      if (item.year) sub.push(String(item.year));
      if (item.system) sub.push(String(item.system));
      else if (item.media_kind) sub.push(item.media_kind);
      if (item.ext) sub.push(item.ext);
    }
    function activate(){
      if (isShow) openShow(item.show_key || item.id);
      else if ((item.type === 'movies' || item.type === 'disney' || item.media_kind === 'video') && !item.show) openMovie(item.id);
      else playItem(item);
    }
    var cardTitle = item.title || 'Untitled';
    if (!isShow && item.show) {
      cardTitle = item.show;
      var epBits = [];
      if (item.season != null && item.episode != null) epBits.push(epCode(item.season, item.episode));
      var en = episodeDisplayTitle(item);
      if (en && en !== item.show && en !== epCode(item.season, item.episode)) epBits.push(en);
      if (epBits.length) sub = epBits;
      else if (item.ext) sub.push(String(item.ext));
    }
    if (item.cw_state === 'next' && !(progress && progress.duration)) sub.unshift('Up next');
    if (item.showcase_label) {
      poster.appendChild(h('span', { className: 'card-showcase-badge', text: item.showcase_label }));
      if (sub.indexOf(item.showcase_label) < 0) sub.unshift(item.showcase_label);
    }
    var el = h('div', { className:'card' + (item.showcase_kind ? ' card-showcase' : ''), tabindex:'0', role:'button', onClick: activate, onKeydown: function(e){ if(e.key==='Enter') activate(); } }, [
      poster,
      h('div', { className:'meta' }, [
        h('div', { className:'title', text: cardTitle }),
        h('div', { className:'sub', text: sub.join(item.show && !isShow ? ' · ' : ' - ') })
      ])
    ]);
    window.VaultcastHub.cardAction(el,item);
    window.VaultcastHomeExtras.protectCard(el,item,progress);
    return el;
  }

  function playItem(item, opts){
    opts = opts || {};
    if (!opts.inline && !opts.lobby) {
      if (item.media_kind === 'rom' || item.type === 'roms') { openGame(item); return; }
      if (item.show_key && (state.view !== 'show' || String(state.showKey) !== String(item.show_key))) { state._pendingEpisode = item; openShow(item.show_key, item.season, item.episode); return; }
      if (!item.show && (item.media_kind === 'video' || item.type === 'movies' || item.type === 'disney') && state.view !== 'movie') { openMovie(item.id); return; }
    }
    var pageHost = document.getElementById('detail-player');
    var requestId = ++_playRequestId;
    function showRetry(){
      if (!pageHost || !pageHost.isConnected || requestId !== _playRequestId) return;
      pageHost.appendChild(h('button', {className:'btn', type:'button', text:'Retry player', onClick:function(){
        pageHost.innerHTML = ''; playItem(item, Object.assign({}, opts, {inline:true}));
      }}));
    }
    openPlayer._lobbyOpts = opts.lobby || null;
    // Lazy-fill poster/metadata cache when user opens an item that has no poster yet
    try {
      if (item && item.id && !item.has_poster) {
        var kind = posterKindFor(item);
        var pid = posterIdFor(item);
        if (pid) {
          fetchPosterServer(posterServerUrl(pid, kind, true), 12000).then(function(){}).catch(function(){});
        }
      }
    } catch (ePrefetch) {}
    var body = {};
    var pref = opts.audio != null ? opts.audio : getAudioPref(item.id);
    if (pref != null && !isNaN(pref)) body.audio = pref;
    api('/items/'+item.id+'/play', { method:'POST', body: body }).then(function(j){
      if (requestId !== _playRequestId || (pageHost && !pageHost.isConnected)) return;
      if (!j.ok) {
        showRetry();
        if (needsConvertExt(item) && state.user && state.user.role === 'admin') {
          if (confirm((j.error || 'Play failed') + '\n\nQueue ffmpeg convert to DirectPlay MP4?')) enqueueConvert(item);
        } else {
          toast(j.error || 'Play failed');
        }
        return;
      }
      j.autoplay = opts.autoplay !== false;
      openPlayer(item, j);
    }).catch(function(e){ if (requestId !== _playRequestId) return; showRetry(); toast(String(e)); });
  }
  var _playRequestId = 0;

  function mountDetailPlayer(player, host, focus){
    (host || document.body).appendChild(player);
    if (host && focus) {
      player.setAttribute('tabindex', '-1');
      player.scrollIntoView({block:'start', behavior:'smooth'});
      player.focus({preventScroll:true});
    }
  }

  /* saved resume position (seconds) for an item; 0 = start from beginning */
  function resumePositionFor(item, play){
    var p = (play && play.progress) || (item && item.progress) || null;
    if (!p) return 0;
    var pos = Number(p.position) || 0, dur = Number(p.duration) || 0;
    if (pos < 5) return 0;
    if (dur && pos > dur - 10) return 0;
    return pos;
  }
  function fmtResumeClock(sec){
    sec = Math.max(0, Math.floor(Number(sec) || 0));
    var hh = Math.floor(sec / 3600), mm = Math.floor((sec % 3600) / 60), ss = sec % 60;
    return (hh ? hh + ':' + (mm < 10 ? '0' : '') : '') + mm + ':' + (ss < 10 ? '0' : '') + ss;
  }

  function openPlayer(item, play){
    /* Keep the active lobby when opening its game. */
    closePlayer({ preserveLobby: !!(openPlayer._lobbyOpts) });
    openPlayer._deskPlayStarted = false;
    openPlayer._deskRecoverTries = 0;
    openPlayer._deskRecovering = false;
    var inlineHost = play.media_kind === 'audio' ? null : document.getElementById('detail-player');
    if (play && play.media_kind === 'rom' && item && item.game_path) state.gamePath = item.game_path;
    if (!inlineHost) pushNav({ player:true, itemId: item.id });
    var overlay = h('div', { className:'player-overlay player-simple' + (inlineHost ? ' player-inline' : ''), id:'player-overlay' });
    if (state.gamePath) overlay.appendChild(shareButton(function(){ return routePath(); }, 'Copy link to this game'));
    if (!inlineHost) overlay.appendChild(h('button', {
      className:'player-close', id:'player-back', text:'\u00d7', title:'Back',
      onClick: function(){ try { history.back(); } catch (e) { closePlayer(); } }
    }));

    if (play.media_kind === 'rom') {
      try {
        if (!inlineHost) overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;width:100%;height:100%;z-index:1000;background:#000;margin:0;padding:0;overflow:hidden;display:block;';
      } catch (eRo) {}
      var root = h('div', { id:'emu-root' });
      root.style.cssText = 'position:absolute;top:0;left:0;right:0;bottom:0;width:100%;height:100%;background:#000;z-index:1;';
      overlay.appendChild(root);
      if (openPlayer._lobbyOpts && state.gameLobby) mountLobbyRoomBadge(overlay,state.gameLobby);
      mountDetailPlayer(overlay, inlineHost, true);
      loadEmulator(root, play, item, openPlayer._lobbyOpts || null);
      openPlayer._lobbyOpts = null;
      return;
    }

    var src = streamPlayUrl(play.url);
    var media;
    if (play.media_kind === 'audio') {
      media = h('audio', { id:'player-media', controls:true, autoplay:true, preload:'auto', src: src });
    } else {
      media = h('video', { id:'player-media', controls:true, autoplay:play.autoplay !== false, playsinline:true, preload:'metadata' });
      media.setAttribute('webkit-playsinline', 'true');
      media.setAttribute('playsinline', 'true');
      if (play.mime) {
        try {
          var source = document.createElement('source');
          source.src = src; source.type = play.mime;
          media.appendChild(source);
        } catch (eS) {}
      }
      media.src = src;
    }
    /* resume — seek to saved progress (play.progress from /items/{id}/play, else item.progress) once metadata is ready.
       Progress saves are held until the resume seek lands so the 0:00 start can't overwrite the saved position. */
    var resumeAt = resumePositionFor(item, play);
    var resumeDone = !(resumeAt > 0);
    openPlayer._lastSave = 0;
    function applyResume(){
      if (resumeDone) return;
      var d = 0;
      try { d = media.duration || 0; } catch (eD) {}
      if (!(media.readyState >= 1)) return;
      var t = resumeAt;
      if (d && isFinite(d) && t > d - 10) { resumeDone = true; return; }
      try {
        media.currentTime = t;
        resumeDone = true;
        toast('Resuming at ' + fmtResumeClock(t), { ms: 2200 });
      } catch (eSeek) {}
    }
    if (!resumeDone) {
      media.addEventListener('loadedmetadata', applyResume);
      media.addEventListener('canplay', applyResume);
      media.addEventListener('playing', applyResume);
      setTimeout(function(){ applyResume(); resumeDone = true; }, 8000);
    }
    media.addEventListener('timeupdate', function(){
      if (!resumeDone) return;
      if (!openPlayer._lastSave || Date.now()-openPlayer._lastSave > 5000) {
        openPlayer._lastSave = Date.now();
        postProgress(item.id, openPlayer._absTime ? openPlayer._absTime() : (media.currentTime||0), openPlayer._absDuration ? openPlayer._absDuration() : (media.duration||0));
      }
    });
    media.addEventListener('playing', function(){ openPlayer._deskPlayStarted = true; openPlayer._deskRecoverTries = 0; });
    media.addEventListener('waiting', function(){ if (openPlayer._deskPlayStarted) toast('Buffering…'); });
    var directTried = false;
    media.addEventListener('error', function(){
      /* Failed before playing: retry once with PHP delivery (nginx could not serve the file). */
      var cur = media.currentSrc || media.src || '';
      if (!openPlayer._deskPlayStarted && !directTried && cur.indexOf('/stream/play') >= 0 && cur.indexOf('direct=1') < 0) {
        directTried = true;
        try { media.src = cur + (cur.indexOf('?') >= 0 ? '&' : '?') + 'direct=1'; if (play.autoplay !== false || openPlayer._deskPlayStarted) media.play(); } catch (eD) {}
        return;
      }
      /* soft mid-play recover (token refresh + seek) before convert toast */
      if (openPlayer._deskPlayStarted && !openPlayer._deskRecovering && (openPlayer._deskRecoverTries||0) < 3) {
        openPlayer._deskRecovering = true;
        openPlayer._deskRecoverTries = (openPlayer._deskRecoverTries||0) + 1;
        var t = media.currentTime || 0;
        toast('Reconnecting stream…');
        api('/items/'+item.id+'/play', { method:'POST', body: {} }).then(function(nj){
          var nsrc = (nj && nj.ok && nj.url) ? streamPlayUrl(nj.url) : src;
          if (directTried && nsrc && nsrc.indexOf('direct=1') < 0) nsrc += (nsrc.indexOf('?') >= 0 ? '&' : '?') + 'direct=1';
          try { media.src = nsrc; } catch (e1) {}
          var done = false;
          function resume(){
            if (done) return;
            done = true;
            try { if (t > 1) media.currentTime = t; } catch (e2) {}
            try { media.play(); } catch (e3) {}
            openPlayer._deskRecovering = false;
          }
          media.addEventListener('loadedmetadata', resume);
          setTimeout(resume, 2000);
        }).catch(function(){ openPlayer._deskRecovering = false; });
        return;
      }
      if (needsConvertExt(item) && state.user && state.user.role === 'admin') {
        toast('Playback error \u2014 Convert to DirectPlay MP4 for this device.');
      } else if (needsConvertExt(item)) {
        toast('This device may not decode this file (audio-only). Try another device or ask admin to Convert.');
      } else {
        toast('Playback error');
      }
    });

    overlay.appendChild(media);
    if (media.tagName==='VIDEO' && !play.hub_cinema_guest) {
      var centrePlay=h('button',{className:'detail-centre-play',type:'button','aria-label':'Play video',text:'▶',onClick:function(){media.play().catch(function(err){toast(err.message || 'Could not start video');});}});
      function updateCentrePlay(){centrePlay.hidden=!media.paused || media.ended;}
      media.addEventListener('play',updateCentrePlay);media.addEventListener('pause',updateCentrePlay);media.addEventListener('ended',updateCentrePlay);
      overlay.appendChild(centrePlay);updateCentrePlay();
    }
    var convertBtn = null;
    if (needsConvertExt(item) && state.user && state.user.role === 'admin') {
      convertBtn = h('button', {
        className:'btn player-convert',
        text:'Convert to DirectPlay MP4',
        onClick: function(e){ enqueueConvert(item, e.currentTarget); }
      });
      overlay.appendChild(convertBtn);
    }
    mountDetailPlayer(overlay, inlineHost, play.autoplay !== false);
    try {
      var pr = play.autoplay !== false ? media.play() : null;
      if (pr && pr.catch) pr.catch(function(){});
    } catch(e){}
    /* MKV/etc: if video stays at videoWidth=0 (black picture / audio-only), emphasise Convert */
    if (play.media_kind !== 'audio' && needsConvertExt(item) && media.tagName === 'VIDEO') {
      var blackChecks = 0;
      var blackTimer = setInterval(function(){
        blackChecks++;
        var vw = 0;
        try { vw = media.videoWidth || 0; } catch (eV) {}
        if (vw > 0 || blackChecks > 8 || !overlay.isConnected) {
          clearInterval(blackTimer);
          if (vw > 0 || !overlay.isConnected || !openPlayer._deskPlayStarted) return;
          if (state.user && state.user.role === 'admin') {
            if (convertBtn) {
              convertBtn.textContent = 'Convert — black video / no picture';
              convertBtn.classList.add('danger');
            } else {
              convertBtn = h('button', {
                className:'btn player-convert danger',
                text:'Convert — black video / no picture',
                onClick: function(e){ enqueueConvert(item, e.currentTarget); }
              });
              var ov = document.getElementById('player-overlay');
              if (ov) ov.appendChild(convertBtn);
            }
            toast('Video track not decoding (black picture). Convert to DirectPlay MP4.', { kind:'bad', ms: 6500 });
          } else {
            toast('Audio only / black video — this device may not decode this file. Ask admin to Convert.', { kind:'bad', ms: 6500 });
          }
        }
      }, 400);
    }
    playerTrackControls(overlay, media, item, play);
  }
  var _emuSessionId = 0;
  var _emuLoading = false;
  /** AudioContexts created while an emulator session is alive (AudioContext constructor wrap). */
  var _emuAudioContexts = [];
  var _emuAudioWrapped = false;
  var _OrigAudioContext = null;
  var _OrigWebkitAudioContext = null;

  function wrapEmuAudioContext(){
    if (_emuAudioWrapped) return;
    var Orig = window.AudioContext || window.webkitAudioContext;
    if (!Orig) return;
    _emuAudioWrapped = true;
    _OrigAudioContext = window.AudioContext || null;
    _OrigWebkitAudioContext = window.webkitAudioContext || null;
    function Tracked(opts){
      var ctx = opts !== undefined ? new Orig(opts) : new Orig();
      try { _emuAudioContexts.push(ctx); } catch (e) {}
      return ctx;
    }
    Tracked.prototype = Orig.prototype;
    try { Object.setPrototypeOf(Tracked, Orig); } catch (eP) {}
    try { if (window.AudioContext) window.AudioContext = Tracked; } catch (eA) {}
    try { if (window.webkitAudioContext) window.webkitAudioContext = Tracked; } catch (eW) {}
  }
  function unwrapEmuAudioContext(){
    if (!_emuAudioWrapped) return;
    try { if (_OrigAudioContext) window.AudioContext = _OrigAudioContext; } catch (eA) {}
    try { if (_OrigWebkitAudioContext) window.webkitAudioContext = _OrigWebkitAudioContext; } catch (eW) {}
    _emuAudioWrapped = false;
  }
  function closeEmuAudioContexts(){
    var list = _emuAudioContexts.splice(0, _emuAudioContexts.length);
    for (var i = 0; i < list.length; i++) {
      try {
        var ctx = list[i];
        if (!ctx) continue;
        try { if (typeof ctx.suspend === 'function') ctx.suspend(); } catch (eS) {}
        try { if (typeof ctx.close === 'function' && ctx.state !== 'closed') ctx.close(); } catch (eC) {}
      } catch (e) {}
    }
  }
  function silenceMediaTree(root){
    if (!root || !root.querySelectorAll) return;
    var nodes = root.querySelectorAll('audio, video');
    for (var i = 0; i < nodes.length; i++) {
      try { nodes[i].muted = true; nodes[i].volume = 0; nodes[i].pause(); } catch (e1) {}
      try { nodes[i].removeAttribute('src'); while (nodes[i].firstChild) nodes[i].removeChild(nodes[i].firstChild); nodes[i].load(); } catch (e2) {}
    }
  }

  function destroyEmulator(){
    _emuLoading = false;
    unwrapEmuAudioContext();
    try {
      var emu = window.EJS_emulator;
      if (emu) {
        if (emu._cloudBeforeExit) emu._cloudBeforeExit();
        if (emu._screenControllerCleanup) emu._screenControllerCleanup();
        emu._streamDisposed = true;
        if (emu._hubStatsCleanup) emu._hubStatsCleanup();
        emu.isNetplay = false;
        if (emu.netplay) {
          clearTimeout(emu.netplay._streamSyncWatchdog);
          clearTimeout(emu.netplay._streamRecoveryTimer);
          lobbyCancelStateLoad(emu);
          if (emu.netplay.socket) {
            try { emu.netplay.socket.removeAllListeners(); } catch (eSock) {}
            try { emu.netplay.socket.disconnect(); } catch (eSock2) {}
          }
        }
        /* Pause / mute before tearing the DOM so WebAudio stops scheduling. */
        try { if (typeof emu.pause === 'function') emu.pause(true); } catch (ePause) {}
        try { if (typeof emu.mute === 'function') emu.mute(); } catch (eMute) {}
        try {
          if (emu.gameManager && typeof emu.gameManager.toggleMainLoop === 'function') {
            emu.gameManager.toggleMainLoop(0);
          }
        } catch (e0) {}
        try {
          if (emu.Module) {
            try { if (typeof emu.Module.pauseMainLoop === 'function') emu.Module.pauseMainLoop(); } catch (ePM) {}
            try { if (typeof emu.Module.noExitRuntime !== 'undefined') emu.Module.noExitRuntime = false; } catch (eNE) {}
            /* libretro / SDL2 / WebAudio paths used by EmulatorJS builds */
            var AL = emu.Module.AL;
            if (AL) {
              var ctxs = [];
              if (AL.currentCtx) ctxs.push(AL.currentCtx);
              if (AL.contexts) {
                for (var ck in AL.contexts) if (Object.prototype.hasOwnProperty.call(AL.contexts, ck)) ctxs.push(AL.contexts[ck]);
              }
              for (var ci = 0; ci < ctxs.length; ci++) {
                var ac = ctxs[ci];
                try {
                  var srcs = (ac && ac.sources) || [];
                  for (var si = 0; si < srcs.length; si++) {
                    try {
                      var g = srcs[si] && srcs[si].gain;
                      if (g) { try { g.gain.value = 0; } catch (eG) {} }
                      var ctx = g && g.context;
                      if (ctx && typeof ctx.close === 'function' && ctx.state !== 'closed') ctx.close();
                    } catch (eA) {}
                  }
                } catch (eCtx) {}
                try {
                  var audioCtx = ac && (ac.ctx || ac.audioCtx || ac.context);
                  if (audioCtx && typeof audioCtx.close === 'function' && audioCtx.state !== 'closed') audioCtx.close();
                } catch (eAC) {}
              }
            }
            try {
              var wa = emu.Module.WEBAudio || emu.Module.SDL2 || emu.Module.audioContext;
              if (wa) {
                var maybe = wa.audioContext || wa.ctx || wa.context || (typeof wa.close === 'function' ? wa : null);
                if (maybe && typeof maybe.close === 'function' && maybe.state !== 'closed') maybe.close();
              }
            } catch (eWA) {}
          }
        } catch (e1) {}
        try {
          if (typeof emu.exit === 'function') emu.exit();
          else if (typeof emu.quit === 'function') emu.quit();
          else if (typeof emu.destroy === 'function') emu.destroy();
        } catch (eEx) {}
        try { if (typeof window.EJS_exit === 'function') window.EJS_exit(); } catch (eEJS) {}
        try { if (typeof emu.callEvent === 'function') emu.callEvent('exit'); } catch (e3) {}
        try {
          var L = emu.listeners || [];
          for (var li = 0; li < L.length; li++) {
            try { L[li].elem.removeEventListener(L[li].listener, L[li].cb); } catch (eL) {}
          }
          emu.listeners = [];
        } catch (e2) {}
        try {
          if (emu.canvas && emu.canvas.getContext) {
            var gl = emu.canvas.getContext('webgl') || emu.canvas.getContext('webgl2') || emu.canvas.getContext('experimental-webgl');
            if (gl && gl.getExtension) {
              var lose = gl.getExtension('WEBGL_lose_context');
              if (lose) lose.loseContext();
            }
          }
        } catch (e4) {}
        try {
          var frame = emu.frame || emu.iframe || null;
          if (frame && frame.parentNode) frame.parentNode.removeChild(frame);
        } catch (eF) {}
      }
    } catch (eD) {}
    closeEmuAudioContexts();
    try { window.EJS_emulator = undefined; } catch (e5) {}
    try { delete window.EJS_emulator; } catch (e6) {}
    var gKeys = ['EJS_player','EJS_core','EJS_gameUrl','EJS_biosUrl','EJS_externalFiles','EJS_pathtodata','EJS_startOnLoaded','EJS_gameID','EJS_gameName','EJS_ready','EJS_onGameStart','EJS_Buttons','EJS_netplayServer','EJS_DEBUG_XX','EJS_EXPERIMENTAL_NETPLAY','EJS_forceLegacyCores','EJS_disableLocalStorage','EJS_threads','EJS_netplayICEServers','EJS_defaultOptions'];
    for (var gi = 0; gi < gKeys.length; gi++) {
      try { window[gKeys[gi]] = undefined; } catch (eG) {}
      try { delete window[gKeys[gi]]; } catch (eG2) {}
    }
    try {
      var scripts = document.querySelectorAll('script[data-vaultcast-emu="1"]');
      for (var si2 = 0; si2 < scripts.length; si2++) {
        if (scripts[si2].parentNode) scripts[si2].parentNode.removeChild(scripts[si2]);
      }
    } catch (eS) {}
    try {
      var emuRoot = document.getElementById('emu-root');
      if (emuRoot) {
        silenceMediaTree(emuRoot);
        try { emuRoot.innerHTML = ''; } catch (eER) {}
      }
    } catch (eR) {}
  }

  function closePlayer(opts){
    opts = opts || {};
    if (state.view !== 'game') state.gamePath = null;
    openPlayer._absTime = null; openPlayer._absDuration = null; openPlayer._seekAbsolute = null;
    if (!opts.preserveLobby) {
      try { lobbyEndHostSession(); } catch (eLobbyEnd) {}
    }
    if (_liveCleanup) { try { _liveCleanup(); } catch (eLc) {} _liveCleanup = null; }
    var o = $('#player-overlay');
    try { silenceMediaTree(o); } catch (eSil) {}
    try {
      var media = document.getElementById('player-media');
      if (media) {
        try { media.muted = true; media.volume = 0; media.pause(); } catch (eP) {}
        try {
          media.removeAttribute('src');
          while (media.firstChild) media.removeChild(media.firstChild);
          media.load();
        } catch (eP2) {}
      }
    } catch (eM) {}
    destroyEmulator();
    if (o) {
      try { silenceMediaTree(o); } catch (eSil2) {}
      try { o.innerHTML = ''; } catch (eI) {}
      try { o.remove(); } catch (eR) { if (o.parentNode) o.parentNode.removeChild(o); }
    }
    /* Drop any stray emulator canvases/iframes left outside the overlay. */
    try {
      var stray = document.querySelectorAll('canvas.ejs--canvas, iframe.ejs--iframe, #emu-root');
      for (var si = 0; si < stray.length; si++) {
        try { if (stray[si].parentNode) stray[si].parentNode.removeChild(stray[si]); } catch (eSt) {}
      }
    } catch (eStray) {}
    _emuSessionId++;
  }
  function onAppPopState(ev){
    var st = ev.state;
    if (st && st.v === 'game' && st.roomId) {
      closePlayer(); state.gameLobby = null; _navSilent = true;
      applyRoute().then(function(){render();replaceNav();}).catch(function(){state.view='play';state.playTab='lobby';render();}).then(function(){_navSilent=false;});
      return;
    }
    if (document.getElementById('player-overlay') && !document.querySelector('.player-inline')) {
      closePlayer();
      return;
    }
    if (!st) {
      _navSilent=true;
      applyRoute().then(function(){render();replaceNav();}).catch(function(){state.view='home';state.hubTab='overview';state.cinemaId=null;render();}).then(function(){_navSilent=false;});
      return;
    }
    _navSilent = true;
    state.view = st.v || 'home';
    if (st.section != null) state.section = st.section;
    if (st.sectionType != null) state.sectionType = st.sectionType;
    if (st.sectionLabel != null) state.sectionLabel = st.sectionLabel;
    if (st.showKey != null) state.showKey = st.showKey;
    if (st.movieId != null) state.movieId = st.movieId;
    if (st.collectionId != null) state.collectionId = st.collectionId;
    state.movieSlug = st.movieSlug || null; state.showSlug = st.showSlug || null;
    state.gamePath = st.gamePath || null;
    state.sectionLetter = st.sectionLetter || '';
    state.collectionsLetter = st.collectionsLetter || '';
    state.hubTab=st.hubTab || 'overview';state.cinemaId=st.cinemaId || null;
    state.showSeason = st.showSeason != null ? st.showSeason : null; state.showEpisode = st.showEpisode != null ? st.showEpisode : null;
    if (st.settingsSection != null) state.settingsSection = st.settingsSection;
    if (st.playTab != null) state.playTab = st.playTab;
    if (st.sectionSystem != null) { state.sectionSystem = st.sectionSystem; state.sectionSystemSlug = st.sectionSystemSlug || null; }
    if (st.searchQuery != null) state.searchQuery = st.searchQuery;
    render();
    _navSilent = false;
  }
  window.addEventListener('popstate', onAppPopState);




  /* PS1 needs BIOS — show clear path instead of EmulatorJS settings limbo */
  function showPsxBiosMissing(root, play, item, retryFn){
    root.innerHTML = '';
    try { root.style.cssText = 'position:absolute;top:0;left:0;right:0;bottom:0;width:100%;height:100%;margin:0;padding:24px;background:#07080c;z-index:1;overflow:auto;box-sizing:border-box;'; } catch (eSt) {}
    var box = h('div', { className:'emu-bios-missing' });
    box.style.cssText = 'max-width:560px;margin:10vh auto 0;color:#eee;font:15px/1.45 system-ui,sans-serif;';
    box.appendChild(h('h2', { text:'PlayStation BIOS required', style:'margin:0 0 12px;font-size:1.35rem;' }));
    box.appendChild(h('p', { className:'muted', text:(play && play.bios_hint) || 'Place a legally dumped PS1 BIOS on the server, then retry.', style:'margin:0 0 14px;opacity:.85;' }));
    var path = h('code', { text:'data/bios/psx/scph5501.bin', style:'display:block;padding:10px 12px;background:#12141c;border-radius:8px;word-break:break-all;margin:0 0 10px;' });
    box.appendChild(path);
    box.appendChild(h('p', { text:'USA games (Crash Bandicoot) need scph5501.bin. EU: scph5502.bin · JP: scph5500.bin. Any scph*.bin / PSXONPSP*.bin dropped in that folder is auto-detected.', style:'margin:0 0 16px;opacity:.8;font-size:13px;' }));
    var row = h('div', { style:'display:flex;gap:10px;flex-wrap:wrap;' });
    var retry = h('button', { className:'btn primary', type:'button', text:'Retry (re-check BIOS)' });
    retry.onclick = function(){ if (typeof retryFn === 'function') retryFn(); };
    row.appendChild(retry);
    box.appendChild(row);
    root.appendChild(box);
    _emuLoading = false;
  }


  /* leftover cue sidecars only — PS1 play prefers .bin (no cue required) */
  function buildEmuExternalFiles(play){
    var map = {};
    var list = (play && play.rom_sidecars) || [];
    for (var i = 0; i < list.length; i++) {
      var sc = list[i];
      if (!sc || !sc.name || !sc.url) continue;
      // Absolute FS path (leading /) — matches cue FILE "Game.bin" next to Game.cue at /
      map['/' + String(sc.name).replace(/^\/+/, '')] = streamPlayUrl(sc.url);
    }
    // Client-side fallback: same-basename .bin next to .cue when server omitted sidecars
    if (!Object.keys(map).length && play && play.url && /\.cue(\?|#|$)/i.test(play.url)) {
      try {
        var u = streamPlayUrl(play.url);
        var binUrl = u.replace(/\.cue(\?|#|$)/i, '.bin$1');
        var name = decodeURIComponent((binUrl.split('/').pop() || '').split('?')[0].split('#')[0]);
        if (name) map['/' + name] = binUrl;
      } catch (eSc) {}
    }
    return map;
  }

  function resolveEmuCore(play, item){
    var core = (play && play.emu_core) || (item && item.emu_core) || '';
    var ext = String((item && item.ext) || (play && play.ext) || '').toLowerCase().replace(/^\./, '');
    var blob = [
      (item && item.system) || '',
      (item && item.path) || '',
      (item && item.rel) || '',
      (item && item.title) || '',
      (play && play.title) || '',
      ext
    ].join(' ').toLowerCase();
    /* PlayStation folder label wins over ambiguous .bin/.cue/.img (before Mega Drive heuristic) */
    if (/playstation|\bpsx\b|\bps1\b|(?:^|\/)ps1(?:\/|$)|(?:^|\/)psx(?:\/|$)/.test(blob)) {
      return 'psx';
    }
    if (core === 'psx') return 'psx';
    if (/mega\s*drive|megadrive|genesis|(?:^|\s|\.)smd(?:\s|$)|(?:^|\s|\.)gen(?:\s|$)/.test(blob)
        || ext === 'smd' || ext === 'gen' || ext === 'md') {
      if (/32\s*x|\b32x\b/.test(blob)) return 'sega32x';
      if (/sega\s*cd|mega\s*cd|segacd/.test(blob)) return 'segaCD';
      return 'segaMD';
    }
    return core || 'nes';
  }




  /** structured client lobby/netplay logs → console + optional server journal via /lobby/client-log */
  function lobbyLog(event, data){
    var row = { ts: new Date().toISOString(), event: event, v: APP_VERSION };
    if (data && typeof data === 'object') {
      for (var k in data) {
        if (!Object.prototype.hasOwnProperty.call(data, k)) continue;
        try { row[k] = data[k]; } catch (eC) {}
      }
    }
    try { console.log('[lobby]', JSON.stringify(row)); } catch (eL) { try { console.log('[lobby]', event, data); } catch (e2) {} }
    try {
      if (!window.__STREAM_LOBBY_LOG_Q) window.__STREAM_LOBBY_LOG_Q = [];
      window.__STREAM_LOBBY_LOG_Q.push(row);
      if (window.__STREAM_LOBBY_LOG_Q.length > 200) window.__STREAM_LOBBY_LOG_Q.shift();
      if (!window.__STREAM_LOBBY_LOG_FLUSHING) {
        window.__STREAM_LOBBY_LOG_FLUSHING = true;
        setTimeout(function(){
          window.__STREAM_LOBBY_LOG_FLUSHING = false;
          var batch = (window.__STREAM_LOBBY_LOG_Q || []).splice(0, 40);
          if (!batch.length) return;
          try {
            api('/lobby/client-log', { method:'POST', body: { events: batch } }).catch(function(){});
          } catch (eP) {}
        }, 400);
      }
    } catch (eQ) {}
  }

  function lobbyHostKey(){
    try {
      var k = localStorage.getItem('vaultcast-lobby-host-key');
      if (k && /^[a-f0-9]{32,64}$/i.test(k)) return k;
      k = '';
      var bytes = new Uint8Array(16);
      if (window.crypto && crypto.getRandomValues) crypto.getRandomValues(bytes);
      else for (var i = 0; i < 16; i++) bytes[i] = (Math.random() * 256) | 0;
      for (var j = 0; j < bytes.length; j++) k += ('0' + bytes[j].toString(16)).slice(-2);
      localStorage.setItem('vaultcast-lobby-host-key', k);
      return k;
    } catch (e) { return 'anon' + String(Date.now()); }
  }
  function profileAvatar(profile){
    profile = profile || {};
    var name = String(profile.name || profile.display_name || profile.username || 'Guest');
    var hash = 0; for (var i = 0; i < name.length; i++) hash = ((hash << 5) - hash + name.charCodeAt(i)) | 0;
    var avatar = h('span', {className:'profile-avatar', 'aria-hidden':'true', style:'--avatar-hue:' + Math.abs(hash % 360)}, [h('span', {text:name.trim().split(/\s+/).slice(0,2).map(function(word){return word.charAt(0);}).join('').toUpperCase() || '?'})]);
    var url = profile.avatar_url || (profile.avatar_version && profile.id ? API + '/profiles/' + encodeURIComponent(profile.id) + '/avatar?v=' + encodeURIComponent(profile.avatar_version) : null);
    if (url && ((url.charAt(0) === '/' && url.charAt(1) !== '/') || url.indexOf(location.origin + '/') === 0)) avatar.appendChild(h('img', {src:url, alt:'', loading:'lazy', onError:function(event){event.target.remove();}}));
    return avatar;
  }

  function openMultiplayerGame(item, options, session){
    options.runtime = session.runtime || null;
    var multiplayer = {options:options, session:session};
    try {sessionStorage.setItem('vaultcast-room-' + session.id, JSON.stringify({options:options, item_id:item.id}));} catch (eStore) {}
    state._openingLobby = true;
    try {openGame(item, multiplayer);} finally {state._openingLobby = false;}
  }

  function restoreMultiplayerRoom(item, roomId){
    return api('/lobby/sessions/' + encodeURIComponent(roomId)).then(function(result){
      if (!result.ok) {state.gameLobby = {session:{id:roomId, title:item.title}, preview:true, ended:true};return;}
      var session = result.session, saved = null;
      if (String(session.item_id) !== String(item.id)) {state.gameLobby = {session:{id:roomId, title:item.title}, preview:true, ended:true, error:'This room belongs to another game.'};return;}
      try {saved = JSON.parse(sessionStorage.getItem('vaultcast-room-' + roomId) || 'null');} catch (eRestore) {}
      state.gameLobby = {session:session, preview:true};
      if (!saved || String(saved.item_id) !== String(item.id) || !saved.options) return;
      var options = saved.options;
      options.runtime = session.runtime || null;
      if (options.role === 'host' && options.host_key) return api('/lobby/sessions/' + roomId + '/heartbeat', {method:'POST', body:{host_key:options.host_key}}).then(function(heartbeat){
        if (!heartbeat.ok) return;
        lobbyStartHeartbeat(heartbeat.session, options.host_key); state.gameLobby = {session:heartbeat.session, options:options};
      });
      if (options.role === 'guest' && (session.players || []).some(function(player){return player.slot === options.player_slot;})) {
        state.lobby.guest = {id:roomId, player_slot:options.player_slot}; state.gameLobby = {session:session, options:options};
      }
    });
  }

  function leaveMultiplayerPage(){
    var room = state.gameLobby;
    if (room) try {sessionStorage.removeItem('vaultcast-room-' + room.session.id);} catch (eStore) {}
    closePlayer(); state.gameLobby = null; state.playTab = 'lobby'; navigate('play');
  }

  function lobbyConnection(multiplayer){
    if (multiplayer.ended) return 'Room ended';
    if (multiplayer.error) return 'Connection failed';
    if (multiplayer.preview) return 'Ready to join';
    var emu = window.EJS_emulator, np = emu && emu.netplay;
    if (!np || !np.socket) return 'Starting emulator';
    if (np.extra && np.extra.sessionid !== multiplayer.session.id) return 'Connecting';
    if(np.socket.connected&&np._streamRecoveryPaused)return 'Paused · host can retry sync';
    if (np.socket.connected && emu.isNetplay && (np._streamSyncing || np._streamLoadingState || np._streamAwaitingState || np._streamAwaitingGo)) return 'Synchronising game';
    if (np.socket.connected && emu.isNetplay && np._streamFrameWait && Date.now()-np._streamFrameWait.since>=1000) return np._streamFrameWait.reason;
    if (np.socket.connected && emu.isNetplay && np._streamLastVerifiedFrame) return 'State verified · frame ' + np._streamLastVerifiedFrame;
    if (np.socket.connected && emu.isNetplay) return 'Connected to room';
    if (np.socket.connected) return 'Joining room';
    return 'Disconnected';
  }

  function openLobbyNetplayMenu(){
    var emu = window.EJS_emulator;
    if (!emu || !lobbyBindNetplayUi(emu) || typeof emu.openNetplayMenu !== 'function') {toast('The emulator Netplay menu is not ready yet.');return;}
    emu.openNetplayMenu();
  }

  function mountLobbyRoomBadge(overlay, multiplayer){
    var roomButton = h('button', {className:'lobby-emulator-room', type:'button', onClick:function(event){event.stopPropagation();openLobbyNetplayMenu();}});
    overlay.appendChild(roomButton);
    setTimeout(function paintRoom(){
      if (!overlay.isConnected || state.gameLobby !== multiplayer) return;
      roomButton.textContent = 'Room ' + (multiplayer.session.room_code || '') + ' · P' + multiplayer.options.player_slot + ' · ' + lobbyConnection(multiplayer);
      setTimeout(paintRoom,1000);
    },0);
  }
  function mountLobbyBoard(root, multiplayer){
    var session = multiplayer.session, options = multiplayer.options || {};
    var badge = h('span', {className:'lobby-status-pill', role:'status'});
    var seats = h('div', {className:'lobby-players-board'});
    var note = h('p', {className:'muted lobby-room-note', text:'Players use their normal Player 1 controls for their assigned seat. Connection status does not confirm game synchronisation.'});
    var board = h('section', {className:'lobby-room-panel', 'aria-label':'Multiplayer room'}, [
      h('div', {className:'lobby-room-heading'}, [h('div', {}, [h('div', {className:'eyebrow', text:'MULTIPLAYER LOBBY'}), h('h2', {text:session.room_code ? 'Room ' + session.room_code : 'Multiplayer room'}), h('p', {className:'muted', text:session.title || ''})]), badge]),
      seats, note, h('div', {className:'row-actions'}, [
        multiplayer.preview && !multiplayer.ended ? h('button', {className:'btn primary', text:'Join this room', onClick:function(){joinLobbySession(multiplayer.session);}}) : null,
        !multiplayer.preview && options.role==='host' ? h('button',{className:'btn ghost',text:'Resynchronise game',onClick:function(){var active=window.EJS_emulator,np=active&&active.netplay;if(!np||!np.owner||!np.socket||!np.socket.connected||np._streamSyncing)return;np._streamRecoveries=[];np._streamRecoveryPaused=false;np.sync();}}) : null,
        !multiplayer.preview ? h('button', {className:'btn glass', text:'Open emulator Netplay menu', onClick:openLobbyNetplayMenu}) : null,
        h('button', {className:'btn ghost', text:'Copy room link', onClick:function(){var url=shareUrl(routePath());copyText(url).then(function(){toast('Room link copied');},function(){window.prompt('Copy room link',url);});}}),
        h('button', {className:'btn ghost danger', text:options.role === 'host' ? 'End room and leave' : 'Back to lobby', onClick:leaveMultiplayerPage})
      ])
    ]);
    root.appendChild(board);
    var lastPaint = '', stopped = false, refreshing = false, lastFetch = 0;
    function current(){return board.isConnected && state.gameLobby === multiplayer;}
    function paint(){
      if (!current()) return;
      var status = lobbyConnection(multiplayer); badge.textContent = status; badge.classList.toggle('connected', status === 'Connected to room' || status.indexOf('State verified') === 0);
      if (multiplayer.error) note.textContent = multiplayer.error;
      var emu = window.EJS_emulator, np = emu && emu.netplay, peers = {};
      if (np && emu.isNetplay && np.socket && np.socket.connected) Object.keys(np.players || {}).forEach(function(id){var peer = np.players[id]; if (peer && peer.player_slot) peers[peer.player_slot] = peer;});
      var players = multiplayer.session.players || [];
      var signature = JSON.stringify([players, Object.keys(peers), options.player_slot, multiplayer.ended]);
      if (signature === lastPaint) return; lastPaint = signature; seats.innerHTML = '';
      for (var slot = 1; slot <= (multiplayer.session.max_players || 4); slot++) {
        var player = players.find(function(person){return person.slot === slot;});
        var self = !!player && options.player_slot === slot;
        seats.appendChild(h('article', {className:'lobby-player-seat' + (self ? ' is-you' : '') + (!player ? ' is-open' : '')}, [
          profileAvatar(player || {name:'?'}), h('div', {className:'lobby-player-copy'}, [
            h('span', {className:'lobby-player-slot', text:'Player ' + slot + (slot === 1 ? ' · Host' : '')}),
            h('strong', {text:player ? player.name + (self ? ' · You' : '') : 'Open seat'}),
            h('span', {className:'muted', text:!player ? 'Waiting for a player' : (multiplayer.ended ? 'Room ended' : (peers[slot] ? 'Connected' : 'In lobby · not connected yet'))})
          ])
        ]));
      }
    }
    function tick(){
      if (!current()) {stopped = true;return;}
      paint();
      if (!multiplayer.ended && !refreshing && Date.now() - lastFetch > 3000) {
        refreshing = true;lastFetch = Date.now();
        api('/lobby/sessions/' + encodeURIComponent(session.id)).then(function(result){
          if (!current()) return;
          if (result.ok) {multiplayer.session = result.session;note.textContent = 'Players use their normal Player 1 controls for their assigned seat. Connection status does not confirm game synchronisation.';}
          else if (result._status === 404) {multiplayer.ended = true;note.textContent = 'The host has ended this room. Return to Lobby to start or join another game.';}
          else note.textContent = result.error || 'Could not refresh the players board.';
          paint();
        }).catch(function(){if(current()) note.textContent = 'Connection interrupted. Retrying the players board…';}).then(function(){refreshing = false;});
      }
      if (!stopped) setTimeout(tick, 1000);
    }
    setTimeout(tick, 0);
  }

  function lobbyPlayerName(){
    var u = state.user;
    if (u) return String(u.display_name || u.username || 'Player').slice(0, 32);
    try {
      var n = localStorage.getItem('vaultcast-lobby-name');
      if (n) return String(n).slice(0, 32);
    } catch (e) {}
    return 'Player';
  }
  function lobbyStopPoll(){
    if (state.lobby && state.lobby.pollTimer) {
      try { clearInterval(state.lobby.pollTimer); } catch (e) {}
      state.lobby.pollTimer = null;
    }
  }
  function lobbyEndHostSession(){
    try {
      var b = document.getElementById('lobby-live-badge');
      if (b && b.parentNode) b.parentNode.removeChild(b);
    } catch (eB) {}
    var host = state.lobby && state.lobby.host;
    if (host && host.id) {
      var id = host.id, key = host.host_key;
      state.lobby.host = null;
      if (host._hb) { try { clearInterval(host._hb); } catch (e) {} }
      lobbyLog('end_client', { session_id: id });
      api('/lobby/sessions/' + encodeURIComponent(id), { method:'DELETE', body: { host_key: key } }).catch(function(){});
      return;
    }
    var guest = state.lobby && state.lobby.guest;
    if (guest && guest.id && guest.player_slot) {
      state.lobby.guest = null;
      api('/lobby/sessions/' + encodeURIComponent(guest.id) + '/leave', { method:'POST', body: { player_slot: guest.player_slot } }).catch(function(){});
    }
  }
  function lobbyStartHeartbeat(sess, hostKey){
    if (!sess || !sess.id) return;
    var host = { id: sess.id, host_key: hostKey, item_id: sess.item_id, room_code: sess.room_code, game_id: sess.game_id };
    if (state.lobby.host && state.lobby.host._hb) {
      try { clearInterval(state.lobby.host._hb); } catch (e) {}
    }
    lobbyLog('heartbeat_start', { session_id: sess.id, room_code: sess.room_code, game_id: sess.game_id });
    host._hb = setInterval(function(){
      api('/lobby/sessions/' + encodeURIComponent(host.id) + '/heartbeat', { method:'POST', body: { host_key: hostKey } }).catch(function(e){ lobbyLog('heartbeat_fail', { session_id: host.id, error: String(e && e.message ? e.message : e) }); });
    }, 20000);
    state.lobby.host = host;
  }
  function lobbyNumericGameId(itemId, fallback){
    if (fallback && +fallback > 0) return +fallback | 0;
    var s = String(itemId || '1');
    var h = 0;
    for (var i = 0; i < s.length; i++) h = ((h << 5) - h + s.charCodeAt(i)) | 0;
    h = Math.abs(h) || 1;
    return h;
  }
  function lobbyNetplayServerUrl(){
    var srv = (typeof window.__STREAM_NETPLAY_SERVER__ === 'string' && window.__STREAM_NETPLAY_SERVER__) || '';
    if (!srv) {
      var b = window.__STREAM_BASE__ || '/';
      if (b.slice(-1) !== '/') b += '/';
      srv = b + 'netplay';
    }
    return String(srv).replace(/\/+$/, '');
  }
  function lobbyNetplaySocketPath(){
    var pathPrefix = lobbyNetplayServerUrl().replace(/^https?:\/\/[^/]+/i, '') || '/media/netplay';
    if (pathPrefix.charAt(0) !== '/') pathPrefix = '/' + pathPrefix;
    pathPrefix = pathPrefix.replace(/\/+$/, '');
    return pathPrefix + '/socket.io';
  }
  function installNetplayIoShim(){
    /* re-patch whenever EmulatorJS reloads socket.io.min.js (wipes window.io). */
    function wrap(){
      if (typeof window.io !== 'function') return false;
      if (window.io.__streamPatched) return true;
      var orig = window.io;
      var patched = function(url, opts){
        opts = opts || {};
        var u = String(url == null ? '' : url);
        var match = false;
        var pathPrefix = lobbyNetplaySocketPath().replace(/\/socket\.io$/, '');
        try {
          if (u.indexOf(pathPrefix) !== -1) match = true;
          if (window.__STREAM_NETPLAY_SERVER__ && u.indexOf(String(window.__STREAM_NETPLAY_SERVER__).replace(/\/+$/, '')) === 0) match = true;
          if (/\/netplay\/?$/.test(u) || /\/netplay$/.test(u)) match = true;
          if (u === '' || u === '/' || u === window.location.origin) match = true;
        } catch (eM) {}
        if (match) {
          opts.path = lobbyNetplaySocketPath();
          if (!opts.transports) opts.transports = ['websocket', 'polling'];
          if (opts.upgrade == null) opts.upgrade = true;
          return orig.call(this, window.location.origin, opts);
        }
        return orig.apply(this, arguments);
      };
      try {
        Object.keys(orig).forEach(function(k){ patched[k] = orig[k]; });
      } catch (eK) {}
      patched.__streamPatched = true;
      window.io = patched;
      return true;
    }
    if (!wrap()) {
      if (!window.__STREAM_NETPLAY_IO_SHIM_TIMER__) {
        window.__STREAM_NETPLAY_IO_SHIM_TIMER__ = true;
        var n = 0;
        var t = setInterval(function(){ if (wrap() || ++n > 120) clearInterval(t); }, 100);
      }
    }
    if (!window.__STREAM_NETPLAY_IO_WATCH__) {
      window.__STREAM_NETPLAY_IO_WATCH__ = setInterval(function(){
        try { if (typeof window.io === 'function' && !window.io.__streamPatched) wrap(); } catch (eW) {}
      }, 400);
    }
  }
  function lobbyEnableNetplayGlobals(gameId){

    window.EJS_DEBUG_XX = true;
    window.EJS_EXPERIMENTAL_NETPLAY = true;
    window.EJS_gameID = typeof gameId === 'number' ? gameId : lobbyNumericGameId(gameId);
    var srv = lobbyNetplayServerUrl();
    window.__STREAM_NETPLAY_SERVER__ = srv;
    window.EJS_netplayServer = srv;
    window.EJS_netplayICEServers = window.EJS_netplayICEServers || [
      { urls: 'stun:stun.l.google.com:19302' },
      { urls: 'stun:stun1.l.google.com:19302' }
    ];
    installNetplayIoShim();
  }
  function lobbyPolyfillStartSocketIO(emu){
    if (!emu || !emu.netplay) return;
    /* always install our safe startSocketIO (stock users-updated->sync crashes host mid-frame). */
    if (typeof emu.netplay.startSocketIO === 'function' && emu.netplay.startSocketIO.__streamSafe) return;
    emu.netplay.startSocketIO = function(callback){
      var np = emu.netplay;
      var url = np.url || lobbyNetplayServerUrl();
      if (typeof window.io !== 'function') {
        if (typeof callback === 'function') callback(new Error('io missing'));
        return;
      }
      installNetplayIoShim();
      try {
        if (np.socket && typeof np.socket.disconnect === 'function') np.socket.disconnect();
      } catch (eD) {}
      /* ALWAYS pass Engine.IO path — Socket.IO treats URL pathname as namespace,
         so io('/media/netplay') without path hits /socket.io (404) → xhr poll error. */
      var sockPath = lobbyNetplaySocketPath();
      np.socket = window.io(window.location.origin, {
        path: sockPath,
        // WebSocket first: per-frame packets over HTTP long-polling add a request round trip
        // each and caused the lag; polling stays as the fallback when a proxy blocks Upgrade.
        transports: ['websocket', 'polling'],
        upgrade: true,
        forceNew: true,
        reconnection: false,
        timeout: 16000
      });
      try { lobbyLog('socket_io_init', { url: window.location.origin, path: sockPath, npUrl: url }); } catch (eInit) {}
      var done = false;
      var finish = function(err){
        if (done) return;
        done = true;
        if (typeof callback === 'function') callback(err || null);
      };
      np.socket.on('connect', function(){
        try {
          var tr = (np.socket.io && np.socket.io.engine && np.socket.io.engine.transport && np.socket.io.engine.transport.name) || '?';
          lobbyLog('socket_connect', { transport: tr, sessionid: np.extra && np.extra.sessionid });
        } catch (eC) {}
        finish(null);
      });
      np.socket.on('connect_error', function(err){
        var msg = (err && err.message) ? err.message : String(err || 'connect_error');
        var active = true;
        try { active = np.socket.active !== false; } catch (eA) {}
        try { lobbyLog('socket_connect_error', { error: msg, active: active }); } catch (eL) {}
        /* Engine.IO emits connect_error per failed transport; keep waiting if more remain. */
        if (!active) finish(err || new Error(msg));
      });
      setTimeout(function(){
        if (!done) finish(new Error('Socket.IO connect timeout'));
      }, 18000);
      np.socket.on('users-updated', function(users){
        try {
          var ucount = 0; try { for (var uk in (users||{})) ucount++; } catch (eUc) {}
          lobbyLog('users-updated', { owner: !!np.owner, user_count: ucount, sessionid: np.extra && np.extra.sessionid });
          np.players = users || {};
          try { if (typeof np.updatePlayersTable === 'function') np.updatePlayersTable(); } catch (eT) {}
          /* Defer sync so we are not mid postMainLoop / WASM frame (host crash root cause). */
          if (np.owner && typeof np.sync === 'function') {
            /* Re-establish a shared state when a player joins or leaves. */
            var membership = Object.keys(np.players).sort().join('|');
            var prev = np._streamMembership;
            np._streamMembership = membership;
            if (membership !== prev) {
              lobbyLog('sync_schedule', { user_count: ucount, prev: prev, delay_ms: 120 });
              clearTimeout(np._streamMembershipTimer);
              np._streamMembershipTimer = setTimeout(function(){
                if (emu._streamDisposed || !emu.isNetplay) return;
                np._streamSyncing = false; // Membership changes supersede the old handshake.
                try { np.sync(); } catch (eS) { lobbyLog('sync_throw', { error: String(eS && eS.message ? eS.message : eS) }); console.warn('lobby users-updated sync', eS); }
              }, 120);
            } else {
              lobbyLog('sync_skip', { user_count: ucount, prev: prev, reason: 'unchanged_membership' });
            }
          }
        } catch (eU) { lobbyLog('users-updated_err', { error: String(eU && eU.message ? eU.message : eU) }); console.warn('lobby users-updated', eU); }
      });
      np.socket.on('disconnect', function(){
        clearTimeout(np._streamSyncWatchdog);
        clearTimeout(np._streamMembershipTimer);
        clearTimeout(np._streamRecoveryTimer);
        lobbyCancelStateLoad(emu);
        np._streamSyncing = false;
        try { if (typeof np.roomLeft === 'function') np.roomLeft(); } catch (eL) {}
        emu.isNetplay = false;
        if (!emu._streamDisposed) {
          lobbyEndHostSession();
          try { emu.play(true); } catch (eP) {}
          toast('Multiplayer disconnected. Return to Lobby to host or join again.', { kind:'bad', ms: 6000 });
        }
      });
      np.socket.on('room-closed', function(){ np.socket.disconnect(); });
      np.socket.on('data-message', function(data){
        try { if (typeof np.dataMessage === 'function') np.dataMessage(data); } catch (eM) { console.warn('lobby data-message', eM); }
      });
    };
    emu.netplay.startSocketIO.__streamSafe = true;
  }
  function lobbyEnsureNetplayFns(emu){
    if (!emu) return false;
    try {
      if (!emu.netplay) emu.netplay = {};
      lobbyStubNetplayUi(emu);

      if (typeof emu.netplay.openRoom !== 'function' && typeof emu.defineNetplayFunctions === 'function') {
        emu.defineNetplayFunctions();
      }
    } catch (eDef) { console.warn('lobby defineNetplayFunctions', eDef); }
    installNetplayIoShim();
    lobbyPolyfillStartSocketIO(emu);
    try { lobbyHardenNetplay(emu); } catch (eH) { console.warn('lobbyHardenNetplay', eH); }
    return !!(emu.netplay && typeof emu.netplay.startSocketIO === 'function' && emu.netplay.startSocketIO.__streamSafe);
  }
  /** EmulatorJS min build does not export window.io; DEBUG loader pulls src/socket.io.min.js. Lobby must load it itself. */
  function lobbyEnsureSocketIo(cb){
    if (typeof window.io === 'function') { try { cb(null); } catch (e0) {} return; }
    var src = window.VaultcastDependencies.emulatorData + 'src/socket.io.min.js';
    var existing = document.querySelector('script[data-vaultcast-socketio="1"]');
    if (existing) {
      var n = 0;
      var t = setInterval(function(){
        if (typeof window.io === 'function') { clearInterval(t); try { cb(null); } catch (e1) {} }
        else if (++n > 50) { clearInterval(t); try { cb(new Error('socket.io script present but window.io missing')); } catch (e2) {} }
      }, 100);
      return;
    }
    var s = document.createElement('script');
    s.src = src;
    s.async = true;
    s.setAttribute('data-vaultcast-socketio', '1');
    s.onload = function(){
      if (typeof window.io === 'function') { try { cb(null); } catch (e3) {} }
      else { try { cb(new Error('socket.io loaded but window.io missing')); } catch (e4) {} }
    };
    s.onerror = function(){ try { cb(new Error('Failed to load ' + src)); } catch (e5) {} };
    document.head.appendChild(s);
  }
  function lobbyFakeEl(){
    return {
      style: {},
      innerText: '',
      textContent: '',
      innerHTML: '',
      classList: { add: function(){}, remove: function(){}, contains: function(){ return false; } },
      appendChild: function(){ return null; },
      removeChild: function(){ return null; },
      remove: function(){},
      setAttribute: function(){},
      getAttribute: function(){ return null; },
      addEventListener: function(){},
      removeEventListener: function(){}
    };
  }
  function lobbyBindNetplayUi(emu){
    if (!emu || !emu.netplayMenu || !emu.netplayMenu.querySelectorAll) return false;
    var tables = emu.netplayMenu.querySelectorAll('table.ejs_netplay_table');
    if (tables.length < 2) return false;
    var np = emu.netplay || (emu.netplay = {});
    np.table = tables[0].querySelector('tbody'); np.playerTable = tables[1].querySelector('tbody');
    np.tabs = [tables[0].parentNode, tables[1].parentNode];
    np.roomNameElem = np.tabs[1].querySelector('strong'); np.passwordElem = np.tabs[1].children[1];
    np.createButton = emu.netplayMenu.getElementsByTagName('a')[0];
    if (!np.table || !np.playerTable || !np.roomNameElem || !np.passwordElem || !np.createButton) return false;
    np.name = np.name || lobbyPlayerName();
    // Keep room membership controlled by the Vaultcast lobby, including leaving.
    np.createButton.style.display = 'none';
    if (!emu.netplayMenu.querySelector('[data-vaultcast-leave]')) np.createButton.parentNode.appendChild(h('button', {className:'ejs_button_button', 'data-vaultcast-leave':'1', type:'button', text:'Leave multiplayer', onClick:leaveMultiplayerPage}));
    np.updatePlayersTable = function(){
      if (!np.playerTable || !np.playerTable.isConnected) return;
      np.playerTable.innerHTML = '';
      Object.keys(np.players || {}).map(function(id){return np.players[id];}).sort(function(a,b){return (a.player_slot || 1) - (b.player_slot || 1);}).forEach(function(player, index){
        np.playerTable.appendChild(h('tr', {}, [h('td', {text:'P' + (player.player_slot || index + 1)}), h('td', {text:player.player_name || 'Player'}), h('td', {text:player.userid === np.playerID ? 'You' : ''})]));
      });
    };
    var lobby = state.gameLobby;
    if (lobby) {
      np.roomNameElem.innerText = 'Room ' + (lobby.session.room_code || lobby.session.id);
      np.tabs[0].style.display = 'none';np.tabs[1].style.display = '';np.passwordElem.style.display = 'none';
    }
    np._streamHasRealUi = true;
    return true;
  }

  /** Prefer EmulatorJS's actual menu; stubs only cover unavailable controls. */
  function lobbyStubNetplayUi(emu){
    if (!emu) return;
    if (!emu.netplay) emu.netplay = {};
    lobbyBindNetplayUi(emu);
    var np = emu.netplay;
    if (!np.table) np.table = lobbyFakeEl();
    if (!np.playerTable) np.playerTable = lobbyFakeEl();
    if (!np.passwordElem) np.passwordElem = lobbyFakeEl();
    if (!np.roomNameElem) np.roomNameElem = lobbyFakeEl();
    if (!np.createButton) np.createButton = lobbyFakeEl();
    if (!np.tabs || !np.tabs.length) np.tabs = [lobbyFakeEl(), lobbyFakeEl()];
    if (typeof np.updatePlayersTable !== 'function') {
      np.updatePlayersTable = function(){};
    }
    if (typeof np.reset !== 'function') {
      np.reset = function(){};
    }
    if (typeof np.updateList !== 'object' || !np.updateList) {
      np.updateList = { start: function(){}, stop: function(){} };
    }
    try {
      if (!emu.elements) emu.elements = {};
      if (!emu.elements.bottomBar) emu.elements.bottomBar = {};
      var bb = emu.elements.bottomBar;
      ['cheat','playPause','restart','loadState','saveState','saveSavFiles','loadSavFiles','netplay'].forEach(function(k){
        if (!bb[k]) bb[k] = [lobbyFakeEl(), lobbyFakeEl()];
        if (!bb[k][0]) bb[k][0] = lobbyFakeEl();
        if (!bb[k][1]) bb[k][1] = lobbyFakeEl();
      });
      if (!emu.elements.contextMenu) emu.elements.contextMenu = {};
      if (!emu.elements.contextMenu.save) emu.elements.contextMenu.save = lobbyFakeEl();
      if (!emu.elements.contextMenu.load) emu.elements.contextMenu.load = lobbyFakeEl();
    } catch (eEl) {}
  }
  function lobbySafeRoomJoined(emu, isOwner, roomName, password, sid){
    lobbyStubNetplayUi(emu);
    try {
      if (emu && emu.netplay && typeof emu.netplay.roomJoined === 'function') {
        emu.netplay.roomJoined(!!isOwner, roomName, password || '', sid);
        return;
      }
    } catch (eRJ) {
      console.warn('lobby roomJoined UI', eRJ);
    }
    /* Minimal netplay flags if UI path threw */
    try {
      emu.isNetplay = true;
      if (emu.netplay) emu.netplay.owner = !!isOwner;
    } catch (eF) {}
  }
  /** Decode Socket.IO state payloads (Uint8Array / ArrayBuffer / number[] / Buffer-shaped). */
  function lobbyDecodeStateBytes(raw){
    if (raw == null) return null;
    try {
      if (raw instanceof Uint8Array) return raw;
      if (typeof ArrayBuffer !== 'undefined' && raw instanceof ArrayBuffer) return new Uint8Array(raw);
      if (typeof ArrayBuffer !== 'undefined' && raw.buffer instanceof ArrayBuffer && typeof raw.byteLength === 'number') {
        return new Uint8Array(raw.buffer, raw.byteOffset || 0, raw.byteLength);
      }
      if (Array.isArray(raw)) return new Uint8Array(raw);
      if (raw && raw.type === 'Buffer' && Array.isArray(raw.data)) return new Uint8Array(raw.data);
      if (raw && typeof raw === 'object' && typeof raw.length === 'number') {
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) out[i] = raw[i] & 255;
        return out;
      }
    } catch (eD) { console.warn('lobbyDecodeStateBytes', eD); }
    return null;
  }
  // Frame-gated netplay: host frame packets, binary snapshots on membership
  // changes, and acknowledgements scoped to a specific snapshot.
  function lobbyNetplayTrace(emu, stage){
    if (!emu || emu._streamDisposed) return;
    var np = emu.netplay || {};
    var keys = Object.keys(np.inputsData || {}).map(Number).sort(function(a,b){ return a-b; });
    lobbyLog('netplay_trace', {
      diagnostic_build: 'frame-gate-4', stage: stage,
      session_id: np.extra && np.extra.sessionid,
      owner: !!np.owner, sync_id: np._streamSyncId,
      core: emu.getCore && emu.getCore(), paused: !!emu.paused,
      bridge_installed: !!emu._streamFrameBridgeInstalled,
      bridge_ticks: emu._streamFrameBridgeTicks || 0, pre_ticks: emu._streamPreTicks || 0,
      netplay_ticks: np._streamFrameTicks || 0,
      absolute_frame: lobbyAbsFrameNum(emu), frame_origin: np.init_frame,
      expected_frame: np.currentFrame, sent: np._streamFramesSent || 0,
      received: np._streamFramesReceived || 0,
      first_buffered_frame: keys.length ? keys[0] : null,
      last_buffered_frame: keys.length ? keys[keys.length-1] : null,
      awaiting_state: !!np._streamAwaitingState, awaiting_go: !!np._streamAwaitingGo,
      syncing: !!np._streamSyncing, host_paused: !!np._streamHostPaused,
      socket_connected: !!(np.socket && np.socket.connected)
    });
  }
  function lobbyScheduleSyncTrace(emu){
    var np = emu.netplay;
    var syncId = np._streamSyncId;
    setTimeout(function(){
      if (!emu._streamDisposed && emu.isNetplay && np._streamSyncId === syncId) {
        lobbyNetplayTrace(emu, 'five_seconds_after_snapshot');
      }
    }, 5000);
  }
  function lobbyInstallFrameBridge(emu){
    if (!emu || emu._streamFrameBridgeWrapped || typeof emu.initModule !== 'function') return;
    emu._streamFrameBridgeWrapped = true;
    var initModule = emu.initModule;
    emu.initModule = function(){
      var runtime = window.EJS_Runtime;
      if (typeof runtime !== 'function') return initModule.apply(this, arguments);
      // Bundled 2025 cores copy postMainLoop into an internal callback list
      // inside EJS_Runtime(), before its Promise resolves. Assigning the Module
      // property after game start does not register a callback with those cores.
      window.EJS_Runtime = function(options){
        var previousPre = options.preMainLoop;
        options.preMainLoop = function(){
          emu._streamPreTicks = (emu._streamPreTicks || 0) + 1;
          if (typeof previousPre === 'function' && previousPre() === false) return false;
          return lobbyBeforeFrame(emu);
        };
        var previous = options.postMainLoop;
        var bridge = function(){
          if (emu._streamDisposed) return;
          emu._streamFrameBridgeTicks = (emu._streamFrameBridgeTicks || 0) + 1;
          if (typeof previous === 'function') previous();
          var callback = emu.Module && emu.Module.postMainLoop;
          if (typeof callback === 'function' && callback !== bridge) callback();
        };
        options.postMainLoop = bridge;
        emu._streamFrameBridgeInstalled = true;
        lobbyLog('frame_bridge_installed', { diagnostic_build: 'frame-gate-4' });
        return runtime.apply(this, arguments);
      };
      try {
        return initModule.apply(this, arguments);
      } finally {
        // initModule invokes the factory synchronously; do not retain a global
        // wrapper tied to this emulator when another game is opened later.
        window.EJS_Runtime = runtime;
      }
    };
  }
  function lobbyAbsFrameNum(emu){
    try {
      if (emu && emu.gameManager && typeof emu.gameManager.getFrameNum === 'function') {
        var n = parseInt(emu.gameManager.getFrameNum(), 10);
        if (!isNaN(n) && n >= 0) return n;
      }
    } catch (eF) {}
    try {
      if (emu && emu.netplay && typeof emu.netplay.currentFrame === 'number') return emu.netplay.currentFrame | 0;
    } catch (e2) {}
    return 0;
  }
  function lobbyResetNetplayFrames(emu, reason, absOverride){
    if (!emu || !emu.netplay) return;
    var np = emu.netplay;
    var abs = (typeof absOverride === 'number' && absOverride >= 0) ? (absOverride | 0) : lobbyAbsFrameNum(emu);
    np.init_frame = abs;
    np.currentFrame = 0;
    np.current_frame = 0;
    np.inputsData = {};
    np.inputs = np.inputs || {};
    np.syncing = false;
    np.wait = false;
    np._streamLastHostFrame = -1;
    np._streamFramePermit = null;
    np._streamAckUsers = {};
    np._streamCheckpoints = {};
    np._streamExpectedChecks = {};
    np._streamLastVerifiedFrame = 0;
    np._streamNextCheckFrame=window.VaultcastNetplaySync.CHECK_INTERVAL;
    np._streamFrameWait=null;
    np._streamBuffering=true;np._streamLastAckFrame=0;np._streamRecoveryPaused=false;
    try { lobbyLog('frame_reset', { reason: reason || 'reset', abs_frame: abs, owner: !!np.owner, playerID: np.playerID || null }); } catch (eL) {}
  }
  var LOBBY_STATE_MAX_BYTES=8*1024*1024;
  function lobbyReadState(emu, maximum, read){
    maximum=maximum||LOBBY_STATE_MAX_BYTES;
    // The pinned legacy core owns this buffer and frees/replaces it on the next
    // save_state_info call. Follow the 4.2.1 GameManager contract: read it here,
    // copy only when retaining a snapshot, and never free the borrowed pointer.
    var info = emu.gameManager.functions.saveStateInfo().split('|');
    if (info[2] !== '1') throw new Error('Could not save emulator state: ' + info[0]);
    var length = Number(info[0]), address = Number(info[1]);
    if (!Number.isSafeInteger(address) || address <= 0) throw new Error('Invalid emulator state address');
    var heap=emu.Module.HEAPU8;
    if (!heap || !Number.isSafeInteger(length) || length <= 0 || length > maximum || length > heap.length || address > heap.length-length) {
      throw new Error('Invalid emulator state size (maximum '+Math.round(maximum/1048576)+' MiB)');
    }
    return read(heap.subarray(address,address+length));
  }
  function lobbyCaptureState(emu,maximum){return lobbyReadState(emu,maximum,function(bytes){return new Uint8Array(bytes);});}
  function lobbyStateHash(emu){return lobbyReadState(emu,LOBBY_STATE_MAX_BYTES,window.VaultcastNetplaySync.stateHash);}
  function lobbySyncConfiguration(emu){
    return JSON.stringify({ core: emu.getCore(), emulator: window.VaultcastDependencies.emulatorVersion,
      webgl2: !!(emu.supportsWebgl2 && emu.webgl2Enabled), threads: !!emu.config.threads,
      defaults: emu.defaultCoreOpts || {}, options: emu.gameManager.getCoreOptions() || '' });
  }
  function lobbyCancelStateLoad(emu){
    var np = emu.netplay, load = np && np._streamLoadingState;
    if (!load) return;
    clearTimeout(load.timer);
    if (load.fs.close === load.close) load.fs.close = load.previousClose;
    try { load.fs.unlink(load.path); } catch (eUnlink) {}
    np._streamLoadingState = null;
  }
  function lobbyLoadSnapshot(emu, bytes, hash, held, syncId, ready){
    var np=emu.netplay, fs=emu.gameManager.FS;
    var load={fs:fs,path:'/vaultcast-netplay-'+syncId+'.state',hash:hash,held:held,syncId:syncId,previousClose:fs.close,closed:false,ready:ready};
    load.close=function(stream){var path=stream && stream.path;var result=load.previousClose.apply(this,arguments);if(path===load.path)load.closed=true;return result;};
    np._streamLoadingState=load;fs.close=load.close;
    fs.writeFile(load.path,bytes);load.closed=false;
    emu.gameManager.clearEJSResetTimer();emu.gameManager.functions.loadState(load.path,0);emu.gameManager.functions.toggleMainLoop(1);
    load.timer=setTimeout(function(){if(!emu._streamDisposed && np._streamLoadingState===load)np._streamSyncFailure('The emulator did not restore the host state exactly');},12000);
  }
  function lobbyFinishStateLoad(emu){
    var np = emu.netplay, load = np._streamLoadingState;
    if (!load || !load.closed) return;
    // load_state queues a native file task. Only after its file has closed AND
    // the resulting core state matches may we acknowledge the new epoch.
    var actual = lobbyStateHash(emu);
    if (actual !== load.hash) {
      load.verifyAttempts = (load.verifyAttempts || 0) + 1;
      if (load.verifyAttempts >= 3) {
        lobbyLog('snapshot_mismatch', { expected: load.hash, actual: actual, sync_id: load.syncId });
        throw new Error('The core did not restore the host snapshot exactly');
      }
      return;
    }
    var held = load.held, syncId = load.syncId, ready = load.ready;
    lobbyCancelStateLoad(emu);
    lobbyRestoreControllerState(emu, held);
    lobbyResetNetplayFrames(emu, np.owner ? 'host_snapshot_verified' : 'guest_snapshot_verified');
    if (ready) { ready(); return; }
    if (np._streamNextSnapshot) {
      var next = np._streamNextSnapshot;
      np._streamNextSnapshot = null;
      np.dataMessage(next);
      return;
    }
    lobbyScheduleSyncTrace(emu);
    np.sendMessage({ ready: true, sync_id: syncId, userid: np.playerID });
  }
  function lobbyCheckFrameState(emu){
    var np=emu.netplay,frame=np.currentFrame,expected=np._streamExpectedChecks[frame];
    if(np.owner){if(frame!==(np._streamNextCheckFrame||window.VaultcastNetplaySync.CHECK_INTERVAL))return null;}
    else if(!expected)return null;
    var started=performance.now(),hash=lobbyStateHash(emu),elapsed=performance.now()-started;
    if(np.owner){
      // Slow cores verify less frequently, without changing the agreed frame boundary.
      var interval=elapsed>8?1800:window.VaultcastNetplaySync.CHECK_INTERVAL;
      np._streamNextCheckFrame=frame+interval;
      lobbyLog('state_checkpoint',{frame:frame,ms:Math.round(elapsed),next_frame:np._streamNextCheckFrame});
      return {frame:frame,hash:hash,next_frame:np._streamNextCheckFrame};
    }
    delete np._streamExpectedChecks[frame];
    if(expected!==hash){
      np._streamAwaitingState=true;np._streamLastVerifiedFrame=0;np.setLoading(true);
      lobbyLog('state_mismatch',{frame:frame,expected:expected,actual:hash,sync_id:np._streamSyncId});
      np.sendMessage({sync:true});clearTimeout(np._streamRecoveryTimer);
      np._streamRecoveryTimer=setTimeout(function(){if(!emu._streamDisposed&&np._streamAwaitingState&&!np._streamRecoveryPaused)np._streamSyncFailure('Host did not respond to a synchronisation request');},15000);
    }else np._streamLastVerifiedFrame=frame;
    return null;
  }
  // A bucket labelled F contains inputs used BEFORE host frame F+1 ran.
  // The guest must apply bucket 0 too, before running its first frame.
  function lobbyApplyFrameInputs(emu, bucket){
    var np = emu.netplay;
    np._streamHeldInputs = np._streamHeldInputs || {};
    (bucket || []).forEach(function(value){
      if (!value || !value.connected_input || value._streamApplied) return;
      var input = value.connected_input;
      emu.gameManager.functions.simulateInput(input[0], input[1], input[2]);
      np._streamHeldInputs[input[0] + ':' + input[1]] = input.slice();
      value._streamApplied = true;
    });
  }
  function lobbyRestoreControllerState(emu, inputs){
    var np = emu.netplay;
    // Controller latches can outlive a core savestate load. Replace them on BOTH
    // sides, including buttons held locally before a guest joined the room.
    for (var slot = 0; slot < 4; slot++) {
      for (var index = 0; index < 24; index++) emu.gameManager.functions.simulateInput(slot, index, 0);
    }
    np._streamHeldInputs = {};
    lobbyApplyFrameInputs(emu, inputs.map(function(input){ return { connected_input: input }; }));
  }
  function lobbyFrameWait(np, reason){
    var now = Date.now();
    if (!np._streamFrameWait || np._streamFrameWait.reason !== reason) np._streamFrameWait = { reason:reason, since:now, logged:0 };
    var wait = np._streamFrameWait;
    // Flush a partial ACK batch when buffering: otherwise the host can wait
    // for progress that the guest has made but has not yet acknowledged.
    if (!np.owner && np.currentFrame > (np._streamLastAckFrame || 0)) {
      np.sendMessage({frame_ack:np.currentFrame,sync_id:np._streamSyncId});
      np._streamLastAckFrame = np.currentFrame;
    }
    if (now - wait.since >= 1000 && now - wait.logged >= 5000) {
      wait.logged = now;
      lobbyLog('frame_wait', { reason:reason, frame:np.currentFrame, ms:now-wait.since, acknowledgements:np._streamAckUsers, sync_id:np._streamSyncId });
    }
    return false;
  }
  function lobbyBeforeFrame(emu){
    if (emu._streamDisposed) return false;
    var np = emu.netplay;
    if (!np || !emu.isNetplay) return true;
    np._streamFramePermit = null;
    // The native loader runs in task_queue_check at the end of a loop. Gating
    // every loop here prevents the queued snapshot from EVER being applied.
    if (np._streamLoadingState) return true;
    if (np._streamSyncing || np._streamAwaitingState || np._streamAwaitingGo || np._streamHostPaused || np._streamUserPaused || np._streamRecoveryPaused) return false;
    var frame = np.currentFrame || 0;
    if (np.owner) {
      if (!window.VaultcastNetplaySync.hostMayRun(frame, np.players, np.playerID, np._streamAckUsers)) return lobbyFrameWait(np, 'Waiting for player frames');
      if (!np.inputsData[frame]) np.inputsData[frame] = [];
    }
    var bucket = np.inputsData[frame];
    np.wait = !bucket;
    if (!bucket){if(!np.owner)np._streamBuffering=true;return lobbyFrameWait(np, 'Waiting for network frames');}
    if(!np.owner&&np._streamBuffering){
      for(var ahead=0;ahead<window.VaultcastNetplaySync.BUFFER_FRAMES;ahead++)if(!np.inputsData[frame+ahead])return lobbyFrameWait(np, 'Buffering network frames');
      np._streamBuffering=false;
    }
    np._streamFrameWait = null;
    emu.paused = false;
    // Host and guests apply the same ordered input bucket at the same boundary.
    lobbyApplyFrameInputs(emu, bucket);
    np._streamFramePermit = { frame: frame, absolute: lobbyAbsFrameNum(emu) };
    return true;
  }
  function lobbyAdvanceGuest(emu){
    // The pre-frame hook consumes input without restarting the native loop.
    var np = emu.netplay;
    np.wait = !np.inputsData[np.currentFrame || 0];
  }
  function lobbyEnsurePostMainLoop(emu){
    if (!emu || !emu.netplay || !emu.Module) return;
    var np = emu.netplay;
    emu.Module.postMainLoop = function(){
      np._streamFrameTicks = (np._streamFrameTicks || 0) + 1;
      if (np._streamLoadingState && !emu._streamDisposed && emu.isNetplay) {
        try { lobbyFinishStateLoad(emu); } catch (err) { np._streamSyncFailure(err.message); }
        return;
      }
      if (emu._streamDisposed || !emu.isNetplay || np._streamSyncing || np._streamAwaitingState || np._streamAwaitingGo) return;
      var permit = np._streamFramePermit;
      np._streamFramePermit = null;
      if (!permit) return;
      var advanced = lobbyAbsFrameNum(emu) - permit.absolute;
      if (advanced === 0) return;
      // A post-loop callback cannot reconstruct input boundaries skipped inside
      // the native core. Do not fabricate empty frames and silently diverge.
      if (advanced !== 1 || permit.frame !== np.currentFrame) {
        np._streamSyncFailure('The emulator skipped a multiplayer frame boundary');
        return;
      }
      np.currentFrame = permit.frame + 1;
      var checkpoint;
      try { checkpoint = lobbyCheckFrameState(emu); }
      catch (err) { np._streamSyncFailure(err.message); return; }
      if (np.owner) {
        var last = typeof np._streamLastHostFrame === 'number' ? np._streamLastHostFrame : -1;
        if (np.currentFrame - 1 > last) {
          var batch = window.VaultcastNetplaySync.hostBucketsToSend(np.inputsData, last, np.currentFrame).map(function(value){
            // Application markers are local: guests must apply each input themselves.
            return value.connected_input ? { frame: value.frame, connected_input: value.connected_input.slice() } : { frame: value.frame };
          });
          np._streamLastHostFrame = np.currentFrame - 1;
          np.sendMessage({ 'sync-control': batch, sync_id: np._streamSyncId, checkpoint: checkpoint });
          np._streamFramesSent = (np._streamFramesSent || 0) + (np.currentFrame - 1 - last);
          if (last < 0) lobbyNetplayTrace(emu, 'host_sent_frame_zero');
        }
      } else {
        if(np.currentFrame-(np._streamLastAckFrame||0)>=window.VaultcastNetplaySync.ACK_EVERY){np.sendMessage({frame_ack:np.currentFrame,sync_id:np._streamSyncId});np._streamLastAckFrame=np.currentFrame;}
        lobbyAdvanceGuest(emu);
      }
      var keepFrom = window.VaultcastNetplaySync.pruneBefore(!!np.owner, np.currentFrame, np._streamLastHostFrame);
      Object.keys(np.inputsData || {}).forEach(function(k){
        if (Number(k) < keepFrom) delete np.inputsData[k];
      });
    };
    emu.Module.postMainLoop.__streamSafe = true;
  }
  function lobbyHardenNetplay(emu){
    if (!emu || !emu.netplay) return;
    lobbyStubNetplayUi(emu);
    var np = emu.netplay;
    if (!emu._streamPauseWrapped) {
      emu._streamPauseWrapped = true;
      var normalPause = emu.pause, normalPlay = emu.play;
      function pauseButtons(paused){
        var buttons = emu.elements && emu.elements.bottomBar && emu.elements.bottomBar.playPause;
        if (buttons && buttons.length === 2) {
          buttons[0].style.display = paused ? 'none' : '';
          buttons[1].style.display = paused ? '' : 'none';
        }
      }
      emu.pause = function(internal){
        if (!emu.isNetplay) return normalPause.apply(emu, arguments);
        emu.paused = true;
        if (!internal) { np._streamUserPaused = true; pauseButtons(true); }
      };
      emu.play = function(internal){
        if (!emu.isNetplay) return normalPlay.apply(emu, arguments);
        emu.paused = false;
        if (!internal) { np._streamUserPaused = false; pauseButtons(false); }
      };
    }
    np.getUserIndex = function(user){
      var entry = (np.players || {})[user];
      return entry && entry.player_slot ? entry.player_slot - 1 : -1;
    };
    if (typeof np.getUserCount !== 'function') {
      np.getUserCount = function(){
        var i = 0;
        for (var k in np.players) i++;
        return i;
      };
    }
    if (typeof np.sendMessage !== 'function') {
      np.sendMessage = function(data){
        try {
          if (np.socket && typeof np.socket.emit === 'function') np.socket.emit('data-message', data);
        } catch (eSM) { console.warn('lobby sendMessage', eSM); }
      };
    }
    np.reset = function(absOverride){
      lobbyResetNetplayFrames(emu, 'np.reset', absOverride);
    };
    np.reset.__streamSafe = true;
    if (typeof np.setLoading !== 'function') np.setLoading = function(){};

    function syncFailed(message){
      lobbyCancelStateLoad(emu);
      clearTimeout(np._streamRecoveryTimer);
      if (emu._streamDisposed) return;
      lobbyLog('sync_failed', { error: message });
      if (np.socket) np.socket.disconnect();
      toast('Netplay: ' + message + '. Please rejoin the lobby.', { kind:'bad', ms: 6000 });
    }
    np._streamSyncFailure = syncFailed;
    function releaseHost(){
      var ids = Object.keys(np.players || {});
      if (np._streamLoadingState || !np._streamSyncing || !ids.every(function(id){ return np._streamReadyUsers[id]; })) return;
      clearTimeout(np._streamSyncWatchdog);
      np.sendMessage({ readyready: true, sync_id: np._streamSyncId });
      np._streamSyncing = false;
      flushPendingInputs();
      np.setLoading(false);
      emu.play(true);
      lobbyNetplayTrace(emu, 'host_released');
    }
    // Establish a fresh epoch after membership changes or a verified mismatch.
    np.sync = function(){
      if (emu._streamDisposed || !emu.isNetplay || !np.owner || np._streamSyncing) return;
      if (Object.keys(np.players || {}).some(function(id){ return np.players[id].sync_revision !== 10; })) {
        syncFailed('All players need the latest branch update; refresh each player');
        return;
      }
      if (Object.keys(np.players || {}).some(function(id){ return np.players[id].sync_config !== np.extra.sync_config; })) {
        syncFailed('Players loaded different emulator cores or settings');
        return;
      }
      np._streamSyncing = true;np._streamRecoveryPaused=false;
      var syncId = (np._streamSyncId || 0) + 1;
      np._streamSyncId = syncId;
      np._streamReadyUsers = {};
      clearTimeout(np._streamSyncWatchdog);
      emu.pause(true);
      np.setLoading(true);
      setTimeout(function(){
        if (emu._streamDisposed || !emu.isNetplay || np._streamSyncId !== syncId) return;
        try {
          // Include queued changes and remove controls belonging to departed seats.
          lobbyApplyFrameInputs(emu, np.inputsData[np.currentFrame || 0]);
          var activeSlots = Object.keys(np.players || {}).map(function(id){ return np.getUserIndex(id); });
          var held = Object.keys(np._streamHeldInputs || {}).map(function(key){ return np._streamHeldInputs[key]; })
            .filter(function(input){ return activeSlots.indexOf(input[0]) !== -1 && input[2] !== 0; });
          lobbyRestoreControllerState(emu, held);
          var bytes = lobbyCaptureState(emu);
          if (!(bytes instanceof Uint8Array) || !bytes.length) throw new Error('Could not save host state');
          if (bytes.length > LOBBY_STATE_MAX_BYTES) throw new Error('Host state exceeds the 8 MiB transfer limit');
          var hash = window.VaultcastNetplaySync.stateHash(bytes);
          // Preserve binary encoding: JSON number arrays can exceed the relay's 20 MB limit.
          np.sendMessage({ state: bytes, state_hash: hash, input_state: held, sync_id: syncId });
          np._streamSyncWatchdog = setTimeout(function(){
            if (np._streamSyncing && np._streamSyncId === syncId) syncFailed('State synchronisation timed out');
          }, 15000);
          lobbyResetNetplayFrames(emu,'host_snapshot');
          np._streamReadyUsers[np.playerID] = true;
          lobbyScheduleSyncTrace(emu);
          releaseHost();
        } catch (err) {
          syncFailed(err && err.message ? err.message : String(err));
        }
      }, 100);
    };
    np.sync.__streamSafe = true;

    function flushPendingInputs(){
      // A key may have been held before the snapshot. Reassert physical controls,
      // including releases received while this client was waiting for the host.
      var pending = Object.assign({}, np._streamLocalInputs || {}, np._streamPendingInputs || {});
      np._streamPendingInputs = {};
      Object.keys(pending).forEach(function(index){ np.simulateInput(0, Number(index), pending[index]); });
    }

    np.dataMessage = function(data){
      if (!data || emu._streamDisposed || !emu.isNetplay) return;
      try {
        if (data.state != null) {
          if (np.owner || typeof data.sync_id !== 'number' || data.sync_id <= (np._streamSyncId || 0)) return;
          var bytes = lobbyDecodeStateBytes(data.state);
          if (!bytes || !bytes.length || bytes.length > LOBBY_STATE_MAX_BYTES) throw new Error('Invalid or oversized host state');
          if (typeof data.state_hash !== 'string' || window.VaultcastNetplaySync.stateHash(bytes) !== data.state_hash) {
            throw new Error('Host snapshot checksum is invalid; update and refresh every player');
          }
          clearTimeout(np._streamRecoveryTimer);
          if (np._streamLoadingState) {
            // A second player can join while the first snapshot is loading.
            // Complete the queued native task before installing the newer one.
            np._streamNextSnapshot = data;
            return;
          }
          emu.pause(true);
          np._streamAwaitingGo = true;
          np._streamAwaitingState = false;
          np._streamSyncId = data.sync_id;
          np.setLoading(true);
          if (!Array.isArray(data.input_state) || data.input_state.some(function(input){
            return !Array.isArray(input) || input.length !== 3 ||
              !Number.isInteger(input[0]) || input[0] < 0 || input[0] > 3 ||
              !Number.isInteger(input[1]) || input[1] < 0 || input[1] > 23 || !Number.isFinite(input[2]);
          })) throw new Error('Host controller snapshot is invalid; refresh both players');
          lobbyLoadSnapshot(emu,bytes,data.state_hash,data.input_state,data.sync_id);
          return;
        }
        if(data.recovery_paused&&!np.owner&&data.sync_id===np._streamSyncId){np._streamRecoveryPaused=true;clearTimeout(np._streamRecoveryTimer);np.setLoading(false);emu.pause(true);return;}
        if (data.ready && np.owner) {
          if (!np._streamSyncing || data.sync_id !== np._streamSyncId || !np.players[data.userid]) return;
          np._streamReadyUsers[data.userid] = true;
          releaseHost();
          return;
        }
        if (data.readyready && !np.owner) {
          if (data.sync_id !== np._streamSyncId || !np._streamAwaitingGo) return;
          np._streamAwaitingGo = false;
          flushPendingInputs();
          np.setLoading(false);
          // Remain paused until host bucket 0 is received and applied.
          lobbyAdvanceGuest(emu);
          lobbyNetplayTrace(emu, 'guest_received_go');
          return;
        }
        if (data.frame_ack != null && np.owner) {
          if (data.sync_id === np._streamSyncId && np.players[data.userid] && Number.isInteger(data.frame_ack) && data.frame_ack >= 0 && data.frame_ack <= np.currentFrame) {
            np._streamAckUsers[data.userid] = Math.max(np._streamAckUsers[data.userid] || 0, data.frame_ack);
          }
          return;
        }
        if (data.sync === true && np.owner) {
          if (np._streamSyncing) return;
          var now = Date.now();
          np._streamRecoveries = (np._streamRecoveries || []).filter(function(time){ return now - time < 60000; });
          if (np._streamRecoveries.length >= 3) {
            np._streamRecoveryPaused=true;np._streamSyncing=false;np.setLoading(false);emu.pause(true);
            np.sendMessage({recovery_paused:true,sync_id:np._streamSyncId});
            toast('Repeated state mismatches. The room is paused; the host can retry synchronisation from the players board.',{kind:'bad',ms:10000});
            return;
          }
          np._streamRecoveries.push(now);
          lobbyLog('state_recovery', { attempt: np._streamRecoveries.length, frame: np.currentFrame });
          np.sync(); return;
        }
        if (data['sync-control']) {
          if (!np.owner) np._streamFramesReceived = (np._streamFramesReceived || 0) + 1;
          if (data.sync_id !== np._streamSyncId || np._streamAwaitingState || np._streamAwaitingGo || np._streamSyncing) return;
          var frame = np.currentFrame || 0;
          if (np.owner) {
            data['sync-control'].forEach(function(value){
              if (!value || !value.connected_input) return;
              if (!np.inputsData[frame]) np.inputsData[frame] = [];
              np.inputsData[frame].push({ frame: frame, connected_input: value.connected_input.slice() });
            });
          } else {
            if (data.checkpoint) {
              var check = data.checkpoint;
              if (!Number.isInteger(check.frame) || check.frame <= frame || check.frame!==np._streamNextCheckFrame || !Number.isInteger(check.next_frame) || check.next_frame<check.frame+window.VaultcastNetplaySync.CHECK_INTERVAL || check.next_frame>check.frame+1800 || typeof check.hash !== 'string') {
                throw new Error('Invalid host frame checkpoint');
              }
              np._streamExpectedChecks[check.frame] = check.hash;np._streamNextCheckFrame=check.next_frame;
            }
            data['sync-control'].forEach(function(value){
              if (!value || !Number.isInteger(value.frame) || value.frame < frame) return;
              if (!np.inputsData[value.frame]) np.inputsData[value.frame] = [];
              np.inputsData[value.frame].push(value.connected_input ? { frame: value.frame, connected_input: value.connected_input.slice() } : { frame: value.frame });
            });
            lobbyAdvanceGuest(emu);
          }
          return;
        }
        if (data.restart && !np.owner) {
          emu.pause(true);
          np._streamAwaitingState = true;
          np.sendMessage({ sync: true });
          return;
        }
        if (data.play && !np.owner) { np._streamHostPaused = false; lobbyAdvanceGuest(emu); return; }
        if (data.pause && !np.owner) { np._streamHostPaused = true; emu.pause(true); }
      } catch (err) {
        syncFailed(err && err.message ? err.message : String(err));
      }
    };
    np.dataMessage.__streamSafe = true;
    // Local P1 controls are remapped to the stable lobby slot on every client.
    np.simulateInput = function(player, index, value, resp){
      if (emu._streamDisposed || !emu.isNetplay) return;
      if (player !== 0 && !resp) return;
      // Save/load, rewind and speed shortcuts must never enter a frame bucket.
      if (!Number.isInteger(index) || index < 0 || index > 23 || !Number.isFinite(value)) return;
      np._streamLocalInputs = np._streamLocalInputs || {};
      np._streamLocalInputs[index] = value;
      if (np._streamSyncing || np._streamAwaitingState || np._streamAwaitingGo) {
        np._streamPendingInputs = np._streamPendingInputs || {};
        np._streamPendingInputs[index] = value;
        return;
      }
      var pidx = 0;
      try { pidx = typeof np.getUserIndex === 'function' ? np.getUserIndex(np.playerID) : 0; } catch (eG) { pidx = 0; }
      if (pidx < 0) return;
      var frame = np.currentFrame || 0;
      if (np.owner) {
        if (!np.inputsData[frame]) np.inputsData[frame] = [];
        np.inputsData[frame].push({ frame: frame, connected_input: [pidx, index, value] });
      } else {
        try {
          np.sendMessage({
            sync_id: np._streamSyncId,
            'sync-control': [{
              frame: frame,
              connected_input: [pidx, index, value]
            }]
          });
        } catch (eSend) { console.warn('lobby guest simulateInput send', eSend); }
      }
    };
    np.simulateInput.__streamSafe = true;
    np.simulateInput.__streamLockstep = 'vaultcast-lockstep-4';
    try { lobbyEnsurePostMainLoop(emu); } catch (ePML) { console.warn('lobbyEnsurePostMainLoop', ePML); }

    /* Soft roomJoined: never let UI stubs throw after isNetplay is set. */
    if (typeof np.roomJoined === 'function' && !np.roomJoined.__streamSafe) {
      var origRJ = np.roomJoined.bind(emu);
      np.roomJoined = function(isOwner, roomName, password, roomId){
        lobbyStubNetplayUi(emu);
        try {
          origRJ(!!isOwner, roomName, password || '', roomId);
        } catch (eRJ) {
          console.warn('lobby roomJoined soft-fail', eRJ);
          try {
            emu.isNetplay = true;
            np.owner = !!isOwner;
            np.inputs = np.inputs || {};
            np.inputsData = np.inputsData || {};
          } catch (eF) {}
        }
      };
      np.roomJoined.__streamSafe = true;
    }
  }
  function lobbyGuid(){
    var S4 = function(){ return (((1 + Math.random()) * 0x10000) | 0).toString(16).substring(1); };
    return (S4() + S4() + '-' + S4() + '-' + S4() + '-' + S4() + '-' + S4() + S4() + S4());
  }
  function lobbyAfterGameStart(lobbyOpts){
    if (!lobbyOpts) return;
    var session = _emuSessionId;
    var startedEmu = window.EJS_emulator;
    if (!startedEmu || startedEmu._streamLobbyStarted) return;
    if (!startedEmu._streamFrameBridgeInstalled) {
      lobbyNetplayTrace(startedEmu, 'missing_frame_bridge');
      lobbyEndHostSession();
      toast('Netplay could not initialise frame synchronisation. Close the game and refresh before retrying.', { kind:'bad', ms: 6500 });
      return;
    }
    startedEmu._streamLobbyStarted = true;
    var sid = String(lobbyOpts.session_id || '');
    if (!sid) {
      console.warn('lobbyAfterGameStart: missing session_id');
      try { toast('Netplay: missing lobby session id', { kind:'bad', ms: 4200 }); } catch (eM) {}
      return;
    }
    var roomName = String(lobbyOpts.room_code || lobbyOpts.title || 'Lobby').slice(0, 20);
    var max = Math.max(2, Math.min(4, Math.floor(Number(lobbyOpts.max_players)) || 2));
    var role = lobbyOpts.role === 'host' ? 'host' : (lobbyOpts.role === 'guest' ? 'guest' : '');
    if (!role) return;
    var attempts = 0;
    var maxAttempts = 24;

    function fail(msg){
      if (session !== _emuSessionId || startedEmu._streamDisposed) return;
      if (startedEmu.netplay && startedEmu.netplay.socket) startedEmu.netplay.socket.disconnect();
      lobbyEndHostSession();
      lobbyLog('after_start_fail', { role: role, session_id: sid, error: String(msg) });
      if (state.gameLobby) state.gameLobby.error = String(msg);
      console.warn('lobbyAfterGameStart fail', msg);
      try { toast(msg, { kind:'bad', ms: 5600 }); } catch (eT) {}
    }

    function openFixed(emu){
      var np = emu.netplay;
      np.playerID = lobbyGuid();
      np.players = {};
      np.extra = {
        domain: window.location.host,
        game_id: emu.config && emu.config.gameId,
        room_name: roomName,
        player_name: np.name || lobbyPlayerName(),
        userid: np.playerID,
        sessionid: sid,
        player_slot: 1,
        sync_revision: 10,
        sync_config: lobbySyncConfiguration(emu),
        netplay_protocol: 'vaultcast-lockstep-4'
      };
      np.players[np.playerID] = np.extra;
      np.users = {};
      if (typeof window.io !== 'function') {
        fail('Netplay open failed: Socket.IO client missing');
        return;
      }
      installNetplayIoShim();
      var openDone = false;
      var openTimer = setTimeout(function(){
        if (openDone) return;
        openDone = true;
        fail('Netplay open timed out waiting for Socket.IO');
      }, 20000);
      np.startSocketIO(function(sockErr){
        if (emu._streamDisposed) return;
        if (openDone) return;
        if (sockErr) {
          openDone = true; clearTimeout(openTimer);
          fail('Netplay open failed: ' + (sockErr && sockErr.message ? sockErr.message : String(sockErr)));
          return;
        }
        if (!np.socket) { openDone = true; clearTimeout(openTimer); fail('Netplay open failed: no socket'); return; }
        np.socket.emit('open-room', {
          extra: np.extra,
          maxPlayers: max,
          password: ''
        }, function(error){
          if (emu._streamDisposed) return;
          if (openDone) return;
          openDone = true; clearTimeout(openTimer);
          if (error) {
            console.warn('lobby open-room', error);
            fail('Netplay room open failed: ' + error);
            return;
          }
          lobbyLog('open-room_ok', { session_id: sid, room_name: roomName, max: max });
          try { lobbyHardenNetplay(emu); } catch (eH3) {}
          lobbySafeRoomJoined(emu, true, roomName, '', sid);
          np._streamAwaitingGo=false;
          lobbyResetNetplayFrames(emu, 'room_open');
          try { toast('Netplay room open — lockstep sync active', { ms: 3200 }); } catch (eT2) {}
        });
      });
    }

    function joinFixed(emu){
      var np = emu.netplay;
      np.playerID = lobbyGuid();
      np.players = {};
      np.extra = {
        domain: window.location.host,
        game_id: emu.config && emu.config.gameId,
        room_name: roomName,
        player_name: np.name || lobbyPlayerName(),
        userid: np.playerID,
        sessionid: sid,
        player_slot: lobbyOpts.player_slot,
        sync_revision: 10,
        sync_config: lobbySyncConfiguration(emu),
        netplay_protocol: 'vaultcast-lockstep-4'
      };
      np.players[np.playerID] = np.extra;
      if (typeof window.io !== 'function') {
        fail('Netplay join failed: Socket.IO client missing');
        return;
      }
      installNetplayIoShim();
      var joinDone = false;
      var joinTimer = setTimeout(function(){
        if (joinDone) return;
        joinDone = true;
        fail('Netplay join timed out waiting for Socket.IO');
      }, 20000);
      np.startSocketIO(function(sockErr){
        if (emu._streamDisposed) return;
        if (joinDone) return;
        if (sockErr) {
          joinDone = true; clearTimeout(joinTimer);
          fail('Netplay join failed: ' + (sockErr && sockErr.message ? sockErr.message : String(sockErr)));
          return;
        }
        if (!np.socket) { joinDone = true; clearTimeout(joinTimer); fail('Netplay join failed: no socket'); return; }
        np.socket.emit('join-room', {
          extra: np.extra
        }, function(error, users){
          if (emu._streamDisposed) return;
          if (joinDone) return;
          joinDone = true; clearTimeout(joinTimer);
          if (error) {
            console.warn('lobby join-room', error);
            fail('Netplay join failed: ' + error + ' (is host in-game with netplay open?)');
            return;
          }
          np.players = users || np.players;
          if (Object.keys(np.players || {}).some(function(id){ return np.players[id].sync_revision !== 10; })) {
            fail('All players need the latest branch update; refresh each player');
            return;
          }
          if (Object.keys(np.players || {}).some(function(id){ return np.players[id].sync_config !== np.extra.sync_config; })) {
            fail('Players loaded different emulator cores or settings');
            return;
          }
          lobbyLog('join-room_ok', { session_id: sid, room_name: roomName });
          try { lobbyHardenNetplay(emu); } catch (eH4) {}
          lobbySafeRoomJoined(emu, false, roomName, '', sid);
          np._streamAwaitingState = true;
          emu.pause(true);
          try { toast('Joined netplay room — you are P' + lobbyOpts.player_slot, { ms: 3600 }); } catch (eT2) {}
        });
      });
    }

    function tryConnect(){
      if (session !== _emuSessionId || startedEmu._streamDisposed) return;
      attempts++;
      var emu = window.EJS_emulator;
      if (!emu) {
        if (attempts < maxAttempts) { setTimeout(tryConnect, 400); return; }
        fail('Netplay: emulator not ready');
        return;
      }
      installNetplayIoShim();
      lobbyStubNetplayUi(emu);
      if (!lobbyEnsureNetplayFns(emu)) {
        if (attempts < maxAttempts) { setTimeout(tryConnect, 400); return; }
        fail('Netplay APIs not ready (startSocketIO missing)');
        return;
      }
      try {
        if (!emu.netplay.name) emu.netplay.name = lobbyPlayerName();
      } catch (eN) {}
      /* Ensure url points at our same-origin netplay server */
      try {
        var srv = lobbyNetplayServerUrl();
        emu.netplay.url = srv;
        if (emu.config) emu.config.netplayUrl = srv;
      } catch (eU) {}

      lobbyEnsureSocketIo(function(errIo){
        if (session !== _emuSessionId || emu._streamDisposed) return;
        if (errIo) {
          fail('Netplay: ' + (errIo && errIo.message ? errIo.message : String(errIo)));
          return;
        }
        installNetplayIoShim();
        try { lobbyHardenNetplay(emu); } catch (eH2) { console.warn('lobbyHardenNetplay connect', eH2); }
        try {
          if (role === 'host') openFixed(emu);
          else joinFixed(emu);
        } catch (eRun) {
          console.warn('lobby connect', eRun);
          fail('Netplay connect error: ' + (eRun && eRun.message ? eRun.message : String(eRun)));
        }
      });
    }

    /* Host a bit sooner; guest waits longer so host open-room can land first */
    var delay = role === 'host' ? 700 : 1600;
    setTimeout(function(){
      lobbyEnsureSocketIo(function(ioError){
        if (session !== _emuSessionId || startedEmu._streamDisposed) return;
        if (ioError) { fail('Netplay: '+(ioError.message || String(ioError))); return; }
        installNetplayIoShim();
        tryConnect();
      });
    }, delay);
  }
  function goPlayTab(tab){

    state.playTab = tab === 'lobby' ? 'lobby' : 'games';
    if (state.playTab === 'games') lobbyStopPoll();
    if (state.view !== 'play') navigate('play');
    else render();
  }
  function fetchLobbySessions(){
    state.lobby.loading = true;
    return api('/lobby/sessions').then(function(j){
      state.lobby.loading = false;
      state.lobby.sessions = (j && j.sessions) || [];
      return state.lobby.sessions;
    }).catch(function(e){
      state.lobby.loading = false;
      throw e;
    });
  }
  function paintLobbySessions(){
    var root = document.getElementById('lobby-view');
    if (!root) return;
    var list = root.querySelector('#lobby-session-list');
    if (!list) return;
    var sessions = state.lobby.sessions || [];
    list.innerHTML = '';
    if (!sessions.length) {
      list.appendChild(emptyState(
        'No live sessions',
        'When someone starts a multiplayer game from Lobby, it shows up here for drop-in joins on your LAN or Tailscale network.',
        [ h('button', { className:'btn primary', type:'button', text:'Start multiplayer', onClick: openLobbyStartPicker }) ]
      ));
      return;
    }
    sessions.forEach(function(s){
      list.appendChild(lobbySessionCard(s));
    });
  }
  function lobbyPosterUrl(itemId){
    if (!itemId) return '';
    return API + '/items/' + encodeURIComponent(itemId) + '/poster';
  }
  function lobbySessionCard(s){
    var filled = s.players_filled || 1;
    var max = s.max_players || 2;
    var slots = filled + ' / ' + max;
    var poster = h('div', { className:'lobby-card-poster' });
    if (s.has_poster && s.item_id) {
      var img = h('img', { alt:'', loading:'lazy', src: lobbyPosterUrl(s.item_id) });
      img.onerror = function(){ img.style.display = 'none'; };
      poster.appendChild(img);
    } else {
      poster.appendChild(h('div', { className:'lobby-card-poster-fallback', text:(s.title || '?').slice(0, 1).toUpperCase() }));
    }
    var full = (s.open_slots || 0) <= 0;
    var joinLbl = full ? 'Full' : (s.join_label || ('Join as P' + (filled + 1)));
    var joinBtn = h('button', {
      className: full ? 'btn glass' : 'btn primary', type:'button', text: joinLbl,
      disabled: full,
      onClick: function(){ if (!full) joinLobbySession(s); }
    });
    return h('article', { className:'lobby-card glass-panel' }, [
      poster,
      h('div', { className:'lobby-card-body' }, [
        h('div', { className:'lobby-card-kicker muted', text: (s.system || 'Game') + (s.mp_label ? (' · ' + s.mp_label) : '') }),
        h('h3', { className:'lobby-card-title', text: s.title || 'Session' }),
        h('div', { className:'lobby-card-meta muted', text: 'Host · ' + (s.host_name || 'Anonymous') + ' · ' + slots + ' players' }),
        h('div', { className:'lobby-card-room muted', text: s.room_code ? ('Room ' + s.room_code) : '' }),
        h('div', { className:'lobby-card-actions' }, [ joinBtn ])
      ])
    ]);
  }
  function joinLobbySession(s){
    if (!s || !s.id || !s.item_id) return;
    if (state.lobby.host || state.lobby.guest) {closePlayer();state.gameLobby=null;}
    toast('Joining…', { ms: 1200 });
    api('/lobby/sessions/' + encodeURIComponent(s.id) + '/join', {
      method:'POST', body: { player_name: lobbyPlayerName() }
    }).then(function(j){
      if (!j || !j.ok) { toast((j && j.error) || 'Could not join', { kind:'bad' }); return; }
      var sess = j.session || s;
      var slot = j.player_slot || ((sess.players_filled || 1));
      toast((j.join_label || ('Joined as Player ' + slot)), { ms: 2800 });
      var it = {
        id: sess.item_id,
        title: sess.title,
        system: sess.system,
        emu_core: sess.emu_core,
        game_path: sess.game_path,
        media_kind: 'rom',
        has_poster: !!sess.has_poster
      };
      state.lobby.guest = { id: sess.id, player_slot: slot };
      openMultiplayerGame(it, {
          role: 'guest',
          session_id: sess.id,
          room_code: sess.room_code,
          game_id: sess.game_id,
          max_players: sess.max_players,
          title: sess.title,
          player_slot: slot,
          join_label: j.join_label
      }, sess);
    }).catch(function(e){ toast(String(e), { kind:'bad' }); });
  }
  function lobbyPickerGameCard(it, choose){
    var card = h('button', { className:'lobby-picker-card', type:'button' });
    var thumb = h('div', { className:'lobby-picker-thumb' });
    if (it.has_poster) {
      var im = h('img', { alt:'', loading:'lazy', src:lobbyPosterUrl(it.id) });
      im.onerror = function(){im.remove();};
      thumb.appendChild(im);
    }
    card.appendChild(thumb);
    card.appendChild(h('strong', {text:it.title || 'ROM'}));
    card.appendChild(h('span', {className:'muted',text:(it.system || '') + (it.mp_label ? ' · '+it.mp_label : ' · '+(it.max_players || 4)+'P')}));
    card.onclick = function(){choose(it);};
    return card;
  }
  function openLobbyStartPicker(){
    var existing = document.getElementById('lobby-picker');
    if (existing) existing.remove();
    var overlay = h('div', { id:'lobby-picker', className:'lobby-picker-overlay' });
    var panel = h('div', { className:'lobby-picker glass-panel' });
    panel.appendChild(h('div', { className:'lobby-picker-head' }, [
      h('h2', { text:'Start multiplayer' }),
      h('button', { className:'btn ghost', type:'button', text:'Close', onClick: function(){ overlay.remove(); } })
    ]));
    panel.appendChild(h('p', { className:'muted', text:'Host any game with up to four lobby seats. Guests join as P2, P3 or P4. The game determines which players can control anything; unused players can watch.' }));
    var q = h('input', { className:'search-box', type:'search', placeholder:'Search all games…', autocomplete:'off' });
    var grid = h('div', { className:'lobby-picker-grid', id:'lobby-picker-grid' });
    var favourites = h('section',{className:'lobby-picker-favourites','aria-label':'Favourite games'});
    favourites.hidden = true;
    panel.appendChild(favourites);
    panel.appendChild(h('div', { className:'lobby-picker-search' }, [ q ]));
    panel.appendChild(grid);
    var page = 0;
    var activeQuery = '';
    var requestId = 0;
    var more = h('button', { className:'btn', type:'button', text:'Load more games', onClick:function(){ load(activeQuery, true); } });
    more.hidden = true;
    panel.appendChild(more);
    overlay.appendChild(panel);
    overlay.addEventListener('click', function(e){ if (e.target === overlay) overlay.remove(); });
    document.body.appendChild(overlay);
    if (state.user && state.user.role !== 'guest') {
      var favouriteOwner=state.user.id;
      api('/hub/game-favourites').then(function(result){
        if (!overlay.isConnected || !state.user || state.user.id!==favouriteOwner || !result.ok || !result.items.length) return;
        favourites.hidden=false;
        favourites.appendChild(h('h3',{text:'Favourite games'}));
        var cards=h('div',{className:'lobby-picker-favourites-row'}), offset=0;
        var moreFavourites=h('button',{className:'btn ghost',type:'button',text:'More favourites',onClick:appendFavourites});
        favourites.appendChild(cards);favourites.appendChild(moreFavourites);
        function appendFavourites(){
          var end=Math.min(offset+12,result.items.length);
          for(;offset<end;offset++) cards.appendChild(lobbyPickerGameCard(result.items[offset],function(it){overlay.remove();startLobbyHost(it);}));
          moreFavourites.hidden=offset>=result.items.length;
        }
        appendFavourites();
      }).catch(function(){ /* Search stays available if personal favourites fail. */ });
    }
    function load(qq, append){
      var ticket = ++requestId;
      var nextPage = append ? page + 1 : 1;
      activeQuery = qq || '';
      more.disabled = true;
      if (!append) {
        more.hidden = true;
        grid.innerHTML = '';
        grid.appendChild(pendingContent({ className:'muted', }));
      }
      api('/lobby/multiplayer?per=60&page=' + nextPage + '&q=' + encodeURIComponent(activeQuery)).then(function(j){
        if (ticket !== requestId || !overlay.parentNode) return;
        if (!append) grid.innerHTML = '';
        page = nextPage;
        more.disabled = false;
        more.hidden = !(j && j.has_more);
        var items = (j && j.items) || [];
        if (!items.length) {
          if (!append) grid.appendChild(h('p', { className:'muted', text:'No games matched.' }));
          return;
        }
        items.forEach(function(it){
          grid.appendChild(lobbyPickerGameCard(it,function(selected){overlay.remove();startLobbyHost(selected);}));
        });
      }).catch(function(e){
        if (ticket !== requestId || !overlay.parentNode) return;
        more.disabled = false;
        if (append) toast(String(e), { kind:'bad' });
        else {
          grid.innerHTML = '';
          grid.appendChild(h('p', { className:'muted', text: String(e) }));
        }
      });
    }
    var tmr = null;
    q.addEventListener('input', function(){
      clearTimeout(tmr);
      requestId++;
      more.hidden = true;
      tmr = setTimeout(function(){ load(q.value.trim()); }, 200);
    });
    load('');
    setTimeout(function(){ try { q.focus(); } catch (e) {} }, 50);
  }

  function chooseLobbyStart(item){
    if (document.getElementById('lobby-start-choice')) return;
    var active=window.EJS_emulator, previous=document.activeElement;
    var canKeep=!!(active && active.started && !active._streamDisposed && !active.isNetplay && String(state.movieId)===String(item.id));
    var overlay=h('div',{id:'lobby-start-choice',className:'confirm-overlay'});
    function close(){overlay.remove();document.removeEventListener('keydown',key);if(previous && previous.isConnected)previous.focus();}
    function choose(keep){close();startLobbyHost(item,keep);}
    function key(e){if(e.key==='Escape'){e.preventDefault();close();}}
    var fresh=h('button',{type:'button',className:'btn primary',text:'New game',onClick:function(){choose(false);}});
    overlay.appendChild(h('div',{className:'confirm-card',role:'dialog','aria-modal':'true','aria-label':'Start lobby'},[
      h('h2',{text:'Start lobby'}),
      h('p',{text:canKeep?'Start a new game, or continue from your current game?':'Start a new game. To use your current position, start playing this game first.'}),
      h('div',{className:'row-actions'},[
        fresh,
        h('button',{type:'button',className:'btn',text:'Current game',disabled:!canKeep,onClick:function(){choose(true);}}),
        h('button',{type:'button',className:'btn ghost',text:'Cancel',onClick:close})
      ])
    ]));
    overlay.addEventListener('click',function(e){if(e.target===overlay)close();});
    document.addEventListener('keydown',key);
    (document.fullscreenElement || document.body).appendChild(overlay);
    fresh.focus();
  }
  function lobbyCaptureRuntime(emu){
    var options={}, text=emu.gameManager.getCoreOptions() || '';
    text.split('\n').forEach(function(line){
      var setting=line.split('; ')[0].split('|'), key=setting[0];
      if (!/^[a-zA-Z0-9_-]{1,96}$/.test(key)) return;
      var value=setting.length>1 ? setting[1] : emu.settings && emu.settings[key];
      if (typeof value==='string' && value.length<=256 && Object.keys(options).length<128) options[key]=value;
    });
    var threadSetting=emu.preGetSetting('ejs_threads');
    return {core:emu.getCore(),variant:emu.supportsWebgl2 && emu.webgl2Enabled?'modern':'legacy',
      threaded:typeof window.SharedArrayBuffer==='function' && (threadSetting==='enabled' || threadSetting!=='disabled' && !!emu.config.threads),options:options};
  }
  function promoteCurrentGameToLobby(item, options, session, emu){
    var root=document.getElementById('movie-view'), player=document.getElementById('detail-player'), overlay=document.getElementById('player-overlay');
    if (!root || !player || !overlay || emu!==window.EJS_emulator || emu._streamDisposed || !emu._streamFrameBridgeInstalled) throw new Error('This running game cannot enter multiplayer. Reopen it and retry.');
    // Keep the canvas, WASM memory and audio context alive. Re-rendering the page
    // destroys the current game and forces an unnecessary state round trip.
    options.runtime=session.runtime || null;
    emu.config.netplayUrl=lobbyNetplayServerUrl();
    emu.config.gameId=lobbyNumericGameId(item.id,options.game_id);
    emu.config.system=options.runtime ? options.runtime.core : emu.getCore();
    emu.config.defaultOptions=options.runtime ? Object.assign({},options.runtime.options) : {};
    emu.config.disableLocalStorage=true;
    if(options.runtime)emu.config.threads=options.runtime.threaded;
    emu.netplayEnabled=true;
    if(!lobbyEnsureNetplayFns(emu))throw new Error('Could not initialise multiplayer controls');
    emu.pause(true);
    emu.isNetplay=true;emu.netplay.owner=true;emu.netplay._streamAwaitingGo=true;
    // Pause/play are now frame-gated; leave the native task loop running.
    emu.gameManager.functions.toggleMainLoop(1);
    ['toggleFastForward','toggleSlowMotion','toggleRewind'].forEach(function(name){if(emu.gameManager.functions[name])emu.gameManager.functions[name](0);});
    var bar=emu.elements.bottomBar || {};
    ['restart','loadState','saveState','quickSave','quickLoad','cheat','settings','loadSavFiles'].forEach(function(name){(Array.isArray(bar[name]) ? bar[name] : []).forEach(function(node){if(node && node.style)node.style.display='none';});});
    var multiplayer={options:options,session:session};
    state.gameLobby=multiplayer;
    try{sessionStorage.setItem('vaultcast-room-'+session.id,JSON.stringify({options:options,item_id:item.id}));}catch(eStore){}
    mountLobbyBoard(root,multiplayer);
    var board=root.querySelector('.lobby-room-panel');
    if(board)root.insertBefore(board,player.nextSibling);
    mountLobbyRoomBadge(overlay,multiplayer);
    root.querySelectorAll('button').forEach(function(button){if(button.textContent==='Start lobby'){button.disabled=true;button.textContent='Lobby active';}});
    syncUrl();
    lobbyAfterGameStart(options);
  }
  var _lobbyHostOpening=false;
  function startLobbyHost(item, preserve){
    if (!item || !item.id) return;
    if (typeof preserve !== 'boolean') {chooseLobbyStart(item);return;}
    if(_lobbyHostOpening)return;
    var active=window.EJS_emulator, generation=_emuSessionId, runtime=null;
    if (preserve && (!active || !active.started || active._streamDisposed || active.isNetplay || String(state.movieId)!==String(item.id))) {
      toast('The current game is no longer available. Choose New game instead.',{kind:'bad'});return;
    }
    if(preserve) {
      if(active._cloudRestoring){toast('Wait for the current save to finish loading before opening a lobby.');return;}
      if(!active._streamFrameBridgeInstalled || !document.getElementById('detail-player')){toast('Reopen the game before moving it into multiplayer.',{kind:'bad'});return;}
      try {runtime=lobbyCaptureRuntime(active);}
      catch(err){toast('Could not prepare this game: '+err.message,{kind:'bad'});return;}
    }
    if (state.lobby.host || state.lobby.guest) {closePlayer();state.gameLobby=null;}
    if (!item || !item.id) return;
    var key = lobbyHostKey();
    _lobbyHostOpening=true;
    toast('Opening lobby session…', { ms: 1400 });
    api('/lobby/sessions', {
      method:'POST',
      body: {
        item_id: item.id,
        host_name: lobbyPlayerName(),
        host_key: key,
        runtime: runtime,
        max_players: 4
      }
    }).then(function(j){
      if (!j || !j.ok) { state._hubInviteFriend=null;toast((j && j.error) || 'Could not start session', { kind:'bad' }); return; }
      var sess = j.session;
      if(preserve && (generation!==_emuSessionId || active!==window.EJS_emulator || active._streamDisposed || String(state.movieId)!==String(item.id))) {
        state._hubInviteFriend=null;
        api('/lobby/sessions/'+encodeURIComponent(sess.id),{method:'DELETE',body:{host_key:j.host_key || key}}).catch(function(){});
        toast('The game closed before its lobby opened.',{kind:'bad'});return;
      }
      window.VaultcastHub.lobbyOpened(sess);
      lobbyStartHeartbeat(sess, j.host_key || key);
      toast('Lobby session live — others can join (Room ' + (sess.room_code || '') + ')', { ms: 4200 });
      var options={role:'host',session_id:sess.id,room_code:sess.room_code,game_id:sess.game_id,
        max_players:sess.max_players,title:sess.title,player_slot:1,host_key:j.host_key || key};
      if(preserve) {
        try{promoteCurrentGameToLobby(item,options,sess,active);}
        catch(error){
          lobbyEndHostSession();
          if(active===window.EJS_emulator && !active._streamDisposed){
            active.isNetplay=false;active._streamLobbyStarted=false;
            if(active.netplay){active.netplay._streamAwaitingGo=false;if(active.netplay.socket)active.netplay.socket.disconnect();}
            try{active.play(true);active.gameManager.functions.toggleMainLoop(1);}catch(resumeError){}
          }
          state.gameLobby=null;
          document.querySelectorAll('.lobby-room-panel,.lobby-emulator-room').forEach(function(node){node.remove();});
          var gameRoot=document.getElementById('movie-view');
          if(gameRoot)gameRoot.querySelectorAll('button').forEach(function(button){if(button.textContent==='Lobby active'){button.disabled=false;button.textContent='Start lobby';}});
          replaceNav();
          throw error;
        }
      } else openMultiplayerGame(item,options,sess);
    }).catch(function(e){state._hubInviteFriend=null;toast('Lobby create failed: '+String(e && e.message ? e.message : e),{kind:'bad'});})
      .then(function(){_lobbyHostOpening=false;});
  }

  function viewLobby(){
    lobbyStopPoll();
    var wrap = h('div', { id:'lobby-view', className:'play-view lobby-view' });
    wrap.appendChild(h('div', { className:'lobby-hero' }, [
      h('div', { className:'lobby-hero-text' }, [
        h('h1', { text:'Lobby' }),
        h('p', { className:'muted', text:'Join a live session or host any game from your library. Play together where the game supports it, or watch from an unused player slot.' })
      ]),
      h('div', { className:'lobby-hero-actions' }, [
        h('button', { className:'btn primary', type:'button', text:'Start multiplayer', onClick: openLobbyStartPicker }),
        h('button', { className:'btn glass', type:'button', text:'Refresh', onClick: function(){ fetchLobbySessions().then(paintLobbySessions).catch(function(e){ toast(String(e), { kind:'bad' }); }); } })
      ])
    ]));
    wrap.appendChild(h('div', { id:'lobby-session-list', className:'lobby-session-list' }, [
      pendingContent({ className:'muted', })
    ]));
    wrap.appendChild(h('div', { className:'lobby-footnote muted' }, [
      h('p', { text:'Keep the host game open while others join. Each guest controls their assigned player using their normal Player 1 controls. Everyone needs the same game, emulator core and core settings. If the host leaves, start a new session from Lobby.' })
    ]));
    fetchLobbySessions().then(paintLobbySessions).catch(function(e){
      var list = wrap.querySelector('#lobby-session-list');
      if (list) {
        list.innerHTML = '';
        list.appendChild(emptyState('Lobby unavailable', String(e), [
          h('button', { className:'btn', type:'button', text:'Retry', onClick: function(){ fetchLobbySessions().then(paintLobbySessions); } })
        ]));
      }
    });
    state.lobby.pollTimer = setInterval(function(){
      if (state.playTab !== 'lobby' || state.view !== 'play') { lobbyStopPoll(); return; }
      fetchLobbySessions().then(paintLobbySessions).catch(function(){});
    }, 5000);
    return shell(wrap);
  }


  function mountScreenController(emu){
    if (!emu || emu._screenControllerCleanup || !emu.elements || !emu.elements.parent) return;
    var parent = emu.elements.parent, listeners = [], held = {}, active = {}, releases = {}, downSince = {}, disposed = false;
    var pref = {};
    try { pref = JSON.parse(localStorage.getItem('vaultcast.screenController') || '{}') || {}; } catch (ePref) {}
    var visible = typeof pref.visible === 'boolean' ? pref.visible : !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
    var size = Math.max(32, Math.min(64, Number(pref.size) || 44));
    var opacity = Math.max(30, Math.min(100, Number(pref.opacity) || 80));
    var scheme = typeof emu.getControlScheme === 'function' ? emu.getControlScheme() : emu.getCore(true);
    var analog = ['psx','psp','n64'].indexOf(scheme) !== -1;
    var shell = h('div', {className:'screen-controller', 'aria-label':'On-screen controller'});
    var panel = h('div', {className:'screen-controller-pad', role:'group', 'aria-label':'Player 1 controls'});
    var toolbar = h('div', {className:'screen-controller-toolbar'});
    var toggle = h('button', {type:'button', text:'Controller', 'aria-expanded':String(visible)});
    var settings = h('details', {className:'screen-controller-settings'}, [h('summary', {text:'Adjust'})]);
    function listen(node, event, fn, options){ node.addEventListener(event, fn, options); listeners.push(function(){ node.removeEventListener(event, fn, options); }); }
    function send(index, value){
      if (disposed || emu._streamDisposed || !emu.started || !emu.gameManager) return;
      emu.gameManager.simulateInput(0, index, value);
    }
    function release(key){
      var input = active[key];
      if (!input) return;
      delete active[key];
      held[input.index] = Math.max(0, (held[input.index] || 0) - 1);
      if (!held[input.index]) {
        var index=input.index, remaining=Math.max(0,80-(Date.now()-(downSince[index] || 0)));
        releases[index]=setTimeout(function(){delete releases[index];if(!held[index])send(index,0);},remaining);
      }
      if (!Object.keys(active).some(function(id){ return active[id].button === input.button; })) input.button.classList.remove('pressed');
    }
    function releaseAll(){
      Object.keys(active).forEach(release);
      Object.keys(releases).forEach(function(index){clearTimeout(releases[index]);delete releases[index];send(Number(index),0);});
    }
    function press(key, button, index){
      if (active[key] || !visible || !emu.started || emu._streamDisposed) return;
      active[key] = {index:index, button:button};
      held[index] = (held[index] || 0) + 1;
      if (held[index] === 1) {
        downSince[index]=Date.now();
        if (Object.prototype.hasOwnProperty.call(releases,index)) {clearTimeout(releases[index]);delete releases[index];}
        else send(index, index >= 16 ? 32767 : 1);
      }
      button.classList.add('pressed');
    }
    function button(label, index){
      var node = h('button', {type:'button', text:label, 'aria-label':label, 'data-input':String(index)});
      if (window.PointerEvent) {
        listen(node, 'pointerdown', function(e){
          if (e.pointerType !== 'touch' && e.button !== 0) return;
          e.preventDefault(); e.stopPropagation();
          press('p'+e.pointerId, node, index);
          try { node.setPointerCapture(e.pointerId); } catch (eCapture) {}
        });
        ['pointerup','pointercancel','lostpointercapture'].forEach(function(event){ listen(node,event,function(e){ e.preventDefault(); e.stopPropagation(); release('p'+e.pointerId); }); });
      } else {
        listen(node, 'touchstart', function(e){ e.preventDefault(); e.stopPropagation(); Array.prototype.forEach.call(e.changedTouches,function(t){press('t'+t.identifier,node,index);}); }, {passive:false});
        ['touchend','touchcancel'].forEach(function(event){listen(node,event,function(e){e.preventDefault();e.stopPropagation();Array.prototype.forEach.call(e.changedTouches,function(t){release('t'+t.identifier);});},{passive:false});});
        listen(node, 'mousedown', function(e){if(e.button===0){e.preventDefault();press('mouse',node,index);}});
      }
      listen(node, 'keydown', function(e){if(e.key===' ' || e.key==='Enter'){e.preventDefault();e.stopPropagation();press('k'+index,node,index);}});
      listen(node, 'keyup', function(e){if(e.key===' ' || e.key==='Enter'){e.preventDefault();e.stopPropagation();release('k'+index);}});
      listen(node, 'blur', function(){release('k'+index);});
      // Accessibility/remote activation may deliver a click without pointer events.
      listen(node, 'click', function(e){
        e.preventDefault();e.stopPropagation();
        if (e.detail===0 && !held[index]) {press('click'+index,node,index);release('click'+index);}
      });
      listen(node, 'contextmenu', function(e){e.preventDefault();});
      return node;
    }
    function directions(label, indices){
      return h('div', {className:'screen-controller-directions', role:'group', 'aria-label':label}, [
        h('span',{className:'screen-controller-caption',text:label}),
        button('↑',indices[0]),button('←',indices[2]),button('↓',indices[1]),button('→',indices[3])
      ]);
    }
    var left = h('div',{className:'screen-controller-left'},[directions('D-pad',[4,5,6,7])]);
    if (analog) left.appendChild(directions('Stick',[19,18,17,16]));
    if (scheme === 'psx') left.appendChild(directions('Right stick',[23,22,21,20]));
    if (analog) {
      var directionMode = h('select', {'aria-label':'Directional control'});
      Array.prototype.forEach.call(left.children,function(group,i){
        directionMode.appendChild(h('option',{value:String(i),text:i===0?'D-pad':i===1?'Left stick':'Right stick'}));
        group.hidden = i !== (scheme==='n64' || scheme==='psp' ? 1 : 0);
      });
      directionMode.value = scheme==='n64' || scheme==='psp' ? '1' : '0';
      listen(directionMode,'change',function(){releaseAll();Array.prototype.forEach.call(left.children,function(group,i){group.hidden=i!==Number(directionMode.value);});});
      toolbar.appendChild(directionMode);
    }
    var buttons = [['B',0],['A',8],['Y',1],['X',9],['L',10],['R',11]];
    if (['nes','gb','segaMS','segaGG','ngp','pce'].indexOf(scheme)!==-1) buttons = [['B',0],['A',8]];
    if (scheme === 'ngp') buttons = [['A',0],['B',8]];
    if (scheme === 'pce') buttons = [['II',0],['I',8]];
    if (scheme === 'gba') buttons = [['B',0],['A',8],['L',10],['R',11]];
    if (scheme === 'psx' || scheme === 'psp') buttons = [['×',0],['○',8],['□',1],['△',9],['L',10],['R',11]];
    if (scheme === 'psx') buttons = buttons.concat([['L2',12],['R2',13],['L3',14],['R3',15]]);
    if (scheme === 'n64') buttons = [['A',0],['B',1],['Z',12],['L',10],['R',11],['C ↑',23],['C ↓',22],['C ←',21],['C →',20]];
    if (['segaMD','segaCD','sega32x','sega','segaSaturn'].indexOf(scheme)!==-1) buttons = [['A',1],['B',0],['C',8],['X',10],['Y',9],['Z',11]];
    if (['atari2600','atari7800'].indexOf(scheme)!==-1) buttons = [['Fire',0],['Fire 2',8]];
    var right = h('div',{className:'screen-controller-actions'},buttons.map(function(input){return button(input[0],input[1]);}));
    panel.appendChild(left);
    panel.appendChild(h('div',{className:'screen-controller-center'},[button(scheme==='arcade' || scheme==='mame' ? 'Coin' : 'Select',2),button('Start',3)]));
    panel.appendChild(right);
    function persist(){try{localStorage.setItem('vaultcast.screenController',JSON.stringify({visible:visible,size:size,opacity:opacity}));}catch(eStore){}}
    function paint(){
      panel.hidden = !visible;
      settings.hidden = !visible;
      toggle.setAttribute('aria-expanded',String(visible));
      toggle.textContent = visible ? 'Hide controller' : 'Show controller';
      shell.style.setProperty('--controller-size',size+'px');
      shell.style.setProperty('--controller-requested-size',size+'px');
      panel.style.opacity = String(opacity/100);
    }
    [['Size',32,64,size],['Opacity',30,100,opacity]].forEach(function(option){
      var range = h('input',{type:'range',min:String(option[1]),max:String(option[2]),value:String(option[3]),'aria-label':'Controller '+option[0].toLowerCase()});
      listen(range,'input',function(){if(option[0]==='Size')size=Number(range.value);else opacity=Number(range.value);paint();persist();});
      settings.appendChild(h('label',{},[h('span',{text:option[0]}),range]));
    });
    listen(toggle,'click',function(){releaseAll();visible=!visible;paint();persist();});
    listen(shell,'keydown',function(e){e.stopPropagation();});
    listen(shell,'keyup',function(e){e.stopPropagation();});
    listen(shell,'click',function(e){e.stopPropagation();});
    listen(window,'blur',releaseAll);
    listen(document,'visibilitychange',releaseAll);
    listen(document,'fullscreenchange',releaseAll);
    if (!window.PointerEvent) listen(window,'mouseup',function(){release('mouse');});
    toolbar.appendChild(toggle);toolbar.appendChild(settings);
    shell.appendChild(toolbar);shell.appendChild(panel);parent.appendChild(shell);
    parent.classList.add('has-screen-controller');
    paint();
    emu._screenControllerCleanup = function(){
      releaseAll();disposed=true;
      listeners.forEach(function(remove){remove();});
      parent.classList.remove('has-screen-controller');
      shell.remove();
      emu._screenControllerCleanup=null;
    };
  }

  function loadEmulator(root, play, item, lobbyOpts){
    lobbyOpts = lobbyOpts || null;
    /* Always tear down any prior EmulatorJS session before creating a new one */
    if (_emuLoading || window.EJS_emulator) {
      try { destroyEmulator(); } catch (ePrev) {}
    } else {
      destroyEmulator();
    }
    _emuSessionId++;
    var session = _emuSessionId;
    _emuLoading = true;
    wrapEmuAudioContext();
    root.innerHTML = '';
    root.appendChild(pendingContent({ className:'muted', id:'emu-status', }));
    try {
      root.style.cssText = 'position:absolute;top:0;left:0;right:0;bottom:0;width:100%;height:100%;margin:0;padding:0;background:#000;z-index:1;';
    } catch (eR) {}
    var core = resolveEmuCore(play, item);
    var lobbyRuntime = lobbyOpts && lobbyOpts.runtime;
    /* do not open EmulatorJS settings when PS1 BIOS is missing */
    if (core === 'psx' && !(play && play.bios_url)) {
      showPsxBiosMissing(root, play, item, function(){
        var id = (item && item.id) || (play && play.item_id);
        if (!id) { showPsxBiosMissing(root, play, item, null); return; }
        api('/items/' + encodeURIComponent(id) + '/play', { method:'POST', body: {} }).then(function(j){
          if (j && j.ok) loadEmulator(root, j, item, lobbyOpts);
          else showPsxBiosMissing(root, play, item, null);
        }).catch(function(){ showPsxBiosMissing(root, play, item, null); });
      });
      return;
    }
    var base = (window.__STREAM_BASE__ || '/');
    var dataPath = window.VaultcastDependencies.emulatorData;
    var gameUrl = streamPlayUrl(play.url);
    var gameId = 'game-' + session + '-' + String((item && item.id) || 'rom');

    function fail(msg){
      if (session !== _emuSessionId) return;
      _emuLoading = false;
      if (lobbyOpts) lobbyEndHostSession();
      root.innerHTML = '';
      root.appendChild(h('p', { text: msg || 'Emulator.js failed to load.' }));
      root.appendChild(h('a', { className:'btn primary', href: gameUrl, text:'Download ROM', download:'' }));
    }

    function bootInstance(){
      if (session !== _emuSessionId) return;
      root.innerHTML = '';
      var div = h('div', { id: gameId });
      div.style.cssText = 'width:100%;height:100%;max-width:none;position:absolute;top:0;left:0;right:0;bottom:0;background:#000;';
      root.appendChild(div);
      window.EJS_player = '#' + gameId;
      window.EJS_core = lobbyRuntime ? lobbyRuntime.core : core;
      window.EJS_gameUrl = gameUrl;
      /* PS1 BIOS when present on server (data/bios/psx); else EmulatorJS settings UI */
      try { delete window.EJS_biosUrl; } catch (eBiosDel) { window.EJS_biosUrl = undefined; }
      if (core === 'psx') {
        var bios = (play && play.bios_url) || '';
        if (bios) window.EJS_biosUrl = streamPlayUrl(bios);
      }
      /* sidecars only if play still points at cue (bin launch needs none) */
      var extFiles = buildEmuExternalFiles(play);
      try { delete window.EJS_externalFiles; } catch (eExtDel) { window.EJS_externalFiles = undefined; }
      if (Object.keys(extFiles).length) {
        window.EJS_externalFiles = extFiles;
        try {
          var st = document.getElementById('emu-status');
          if (st) st.textContent = 'Preparing Emulator.js + disc image (large download)…';
        } catch (eSt2) {}
      }
      window.EJS_pathtodata = dataPath;
      // Use the same core variant and initial settings on desktop and mobile.
      // PSP requires WebGL2; other lobbies use the portable WebGL1 core build.
      var cloudCore=window.VaultcastHomeExtras.corePreference(item);
      window.EJS_forceLegacyCores = lobbyRuntime ? lobbyRuntime.variant==='legacy' : lobbyOpts ? core !== 'ppsspp' : !!(cloudCore && cloudCore.variant==='legacy');
      window.EJS_disableLocalStorage = !!lobbyOpts;
      window.EJS_threads = lobbyRuntime ? !!lobbyRuntime.threaded : lobbyOpts ? core === 'ppsspp' : !!(cloudCore && cloudCore.threaded);
      window.EJS_defaultOptions = lobbyRuntime ? Object.assign({},lobbyRuntime.options,{webgl2Enabled:lobbyRuntime.variant==='modern'?'enabled':'disabled',ejs_threads:lobbyRuntime.threaded?'enabled':'disabled'}) : undefined;
      // loader.js is an async IIFE: its script onload is NOT instance-ready.
      // Hold lobby core startup until EJS_ready has installed the frame bridge.
      window.EJS_startOnLoaded = false;
      window.EJS_gameName = (item && item.title) || (play && play.title) || 'ROM';
      if (lobbyOpts) {
        var gid = lobbyNumericGameId((item && item.id) || '', lobbyOpts.game_id);
        lobbyEnableNetplayGlobals(gid);
        /* start socket.io fetch during ROM boot so open/join are not racing a cold load */
        try { lobbyEnsureSocketIo(function(){ installNetplayIoShim(); }); } catch (eIoEarly) {}
        window.EJS_Buttons = { loadState:false, saveState:false, screenshot:true, quickSave:false, quickLoad:false, fullscreen:true, playPause:true, volume:true, mute:true, settings:false, saveSavFiles:true, loadSavFiles:false, exitEmulation:false, netplay:true };
        window.EJS_ready = function(){
          if (session !== _emuSessionId) return;
          var emu = window.EJS_emulator;
          if (!emu || emu._streamDisposed || emu._streamLobbyBootStarted) return;
          lobbyInstallFrameBridge(emu);
          var button = emu.elements && emu.elements.parent && emu.elements.parent.querySelector('.ejs_start_button');
          if (!emu._streamFrameBridgeWrapped || !button || typeof emu.startButtonClicked !== 'function') {
            fail('Could not prepare multiplayer before starting the emulator.');
            return;
          }
          emu._streamLobbyBootStarted = true;
          lobbyLog('lobby_core_start', { diagnostic_build:'frame-gate-4', role:lobbyOpts.role });
          emu.startButtonClicked(button);
        };
        window.EJS_onGameStart = function(){
          if (session !== _emuSessionId) return;
          _emuLoading = false;
          window.VaultcastHub.gameStarted(item);
          try { lobbyAfterGameStart(lobbyOpts); } catch (eLAS) {}
try {
            var slot = lobbyOpts.player_slot || 1;
            var lbl = lobbyOpts.join_label || (lobbyOpts.role === 'host' ? ('Hosting · P' + slot) : ('Player ' + slot));
            toast(lbl + (lobbyOpts.room_code ? (' · Room ' + lobbyOpts.room_code) : ''), { ms: 3200 });
          } catch (eT) {}
        };
      } else {
        window.EJS_gameID = String((item && item.id) || '') + '-' + session;
        try { delete window.EJS_DEBUG_XX; } catch (eD) { window.EJS_DEBUG_XX = undefined; }
        try { delete window.EJS_EXPERIMENTAL_NETPLAY; } catch (eE) { window.EJS_EXPERIMENTAL_NETPLAY = undefined; }
        try { delete window.EJS_netplayServer; } catch (eN) { window.EJS_netplayServer = undefined; }
        window.EJS_Buttons = { loadState:true, saveState:true, screenshot:true, quickSave:true, quickLoad:true, fullscreen:true, playPause:true, volume:true, mute:true, settings:true, saveSavFiles:true, loadSavFiles:true, exitEmulation:false };
        window.EJS_ready = function(){
          if(session!==_emuSessionId)return;
          var emu=window.EJS_emulator;if(!emu || emu._streamDisposed || emu._streamSoloBootStarted)return;
          var button=emu.elements && emu.elements.parent && emu.elements.parent.querySelector('.ejs_start_button');
          if(!button)return;
          lobbyInstallFrameBridge(emu);emu._streamSoloBootStarted=true;
          emu.startButtonClicked(button);
        };
        window.EJS_onGameStart = function(){ if (session === _emuSessionId) { _emuLoading = false; window.VaultcastHub.gameStarted(item); } };
      }

      // loader.js loads its dependencies asynchronously; its script.onload can
      // fire before EJS_emulator exists. Mount from the actual lifecycle events
      // as well as the direct-constructor path.
      function ensureScreenController(){
        if (session !== _emuSessionId) return;
        var emu = window.EJS_emulator;
        if (!emu || emu._streamDisposed) return;
        try { mountScreenController(emu); }
        catch (err) { console.warn('On-screen controller setup failed', err); }
      }
      var readyWithController = window.EJS_ready;
      window.EJS_ready = function(){
        ensureScreenController();
        if (typeof readyWithController === 'function') return readyWithController.apply(this, arguments);
      };
      var startWithController = window.EJS_onGameStart;
      window.EJS_onGameStart = function(){
        ensureScreenController();
        if (typeof startWithController === 'function') return startWithController.apply(this, arguments);
      };

      function wireEjsLifecycle(emu){
        /* loader.js wires these; direct new EmulatorJS() does not — without "start",
           lobbyAfterGameStart never runs (no open-room/join-room, no Joined toast). */
        if (!emu || typeof emu.on !== 'function') return;
        ensureScreenController();
        if (lobbyOpts) lobbyInstallFrameBridge(emu);
        try {
          if (typeof window.EJS_ready === 'function') emu.on('ready', window.EJS_ready);
        } catch (eR) {}
        try {
          if (typeof window.EJS_onGameStart === 'function') emu.on('start', window.EJS_onGameStart);
        } catch (eS) {}
        if (emu.elements && emu.elements.parent && emu.elements.parent.querySelector('.ejs_start_button')) {
          var readyNow = window.EJS_ready;
          setTimeout(function(){if(session===_emuSessionId && !emu._streamDisposed && typeof readyNow==='function')readyNow();},0);
        }
        /* If Start already fired before we attached (softLoad / race), run once now */
        try {
          if (emu.started && typeof window.EJS_onGameStart === 'function') {
            setTimeout(function(){ try { window.EJS_onGameStart(); } catch (eLate) {} }, 50);
          }
        } catch (eSt) {}
      }
      function startFresh(){
        if (session !== _emuSessionId) return;
        try {
          if (typeof window.EmulatorJS === 'function') {
            var config = {
              gameUrl: window.EJS_gameUrl,
              dataPath: dataPath,
              system: window.EJS_core,
              biosUrl: window.EJS_biosUrl || undefined,
              externalFiles: (window.EJS_externalFiles && Object.keys(window.EJS_externalFiles).length) ? window.EJS_externalFiles : undefined,
              startOnLoad: window.EJS_startOnLoaded,
              gameId: window.EJS_gameID,
              gameName: window.EJS_gameName,
              buttonOpts: window.EJS_Buttons,
              defaultOptions: window.EJS_defaultOptions,
              forceLegacyCores: window.EJS_forceLegacyCores,
              disableLocalStorage: window.EJS_disableLocalStorage,
              threads: window.EJS_threads,
              netplayUrl: window.EJS_netplayServer || undefined
            };
            /* Lobby needs numeric gameId for EJS checkSupportedOpts / netplay button */
            if (lobbyOpts && (typeof config.gameId !== 'number' || !(config.gameId > 0))) {
              config.gameId = lobbyNumericGameId((item && item.id) || '', lobbyOpts.game_id);
              window.EJS_gameID = config.gameId;
            }
            window.EJS_emulator = new window.EmulatorJS(window.EJS_player, config);
            wireEjsLifecycle(window.EJS_emulator);
            _emuLoading = false;
            return;
          }
        } catch (eNew) {
          console.warn('EmulatorJS direct start failed', eNew);
        }
        var script = document.createElement('script');
        script.src = dataPath + 'loader.js';
        script.setAttribute('data-vaultcast-emu', '1');
        script.onerror = function(){ fail('Could not load EmulatorJS from the selected player source. Check Playback → Player files in Settings.'); };
        script.onload = function(){
          if (session !== _emuSessionId) return;
          _emuLoading = false;
          /* loader.js also wires start; double-wire is fine (EJS on() appends).
             If loader finished before our globals were set, re-bind. */
          try { wireEjsLifecycle(window.EJS_emulator); } catch (eW) {}
        };
        document.body.appendChild(script);
      }
      startFresh();
    }

    try {
      if (!document.getElementById('vaultcast-emu-css')) {
        var link = document.createElement('link');
        link.id = 'vaultcast-emu-css';
        link.rel = 'stylesheet';
        link.href = dataPath + 'emulator.min.css';
        document.head.appendChild(link);
      }
    } catch (eCss) {}

    /* Check the ROM download before starting EmulatorJS (a failed fetch is a black screen).
       If nginx cannot serve it (folder access, X-Accel), ask the server to stream it through PHP. */
    function probeRom(url){
      if (typeof fetch !== 'function') return Promise.resolve({ ok:true });
      return fetch(url, { credentials:'same-origin', headers:{ Range:'bytes=0-15' }, cache:'no-store' }).then(function(r){
        try { if (r.body && r.body.cancel) r.body.cancel(); } catch (eC) {}
        return { ok: R.romResponseOk(r.status, r.headers.get('Content-Type')), status: r.status, type: r.headers.get('Content-Type') || '' };
      }).catch(function(e){ return { ok:false, status:0, type:'', error:String(e && e.message || e) }; });
    }
    probeRom(gameUrl).then(function(first){
      if (session !== _emuSessionId) return;
      if (first.ok) { bootInstance(); return; }
      var direct = R.withDirect(gameUrl);
      probeRom(direct).then(function(second){
        if (session !== _emuSessionId) return;
        if (second.ok) {
          gameUrl = direct;
          if (play && play.bios_url) play.bios_url = R.withDirect(play.bios_url);
          bootInstance();
          return;
        }
        fail('The game file could not be loaded (' + (second.status ? 'HTTP ' + second.status : (second.error || 'network error')) + (second.type && second.status ? ', ' + second.type.split(';')[0] : '') + '). Check that the games folder is still available to the server, then try again.');
      });
    });
  }

  var APP_VERSION = window.__STREAM_VERSION__;
  var ICONS = {
    home: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10l9-7 9 7v10a1 1 0 0 1-1 1h-5v-8H9v8H4a1 1 0 0 1-1-1z"/></svg>',
    vod: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4.5" width="18" height="12.5" rx="2.6" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M10.2 8.2v5.1l4.4-2.55z" fill="currentColor"/><path d="M8 20h8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    live: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="2.3" fill="currentColor"/><path d="M8.2 8.2a5.4 5.4 0 0 0 0 7.6M15.8 8.2a5.4 5.4 0 0 1 0 7.6M5.3 5.3a9.5 9.5 0 0 0 0 13.4M18.7 5.3a9.5 9.5 0 0 1 0 13.4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    play: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.2 7.5h9.6a4.2 4.2 0 0 1 4.2 4.2v.9a3.9 3.9 0 0 1-6.9 2.5l-.5-.6h-3.2l-.5.6a3.9 3.9 0 0 1-6.9-2.5v-.9a4.2 4.2 0 0 1 4.2-4.2z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M7.6 9.9v3M6.1 11.4h3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="15.6" cy="10.5" r="1.05" fill="currentColor"/><circle cx="17.6" cy="12.6" r="1.05" fill="currentColor"/></svg>',
    search: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="1.9"/><path d="M16 16l4.2 4.2" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>',
    user: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8.6" r="3.8" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M4.8 20a7.2 7.2 0 0 1 14.4 0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    chevL: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 5.5L8 12l6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    chevR: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.5 5.5L16 12l-6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    chevU: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5.5 14.5L12 8l6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    chevD: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5.5 9.5L12 16l6.5-6.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    playFill: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l10.5-6.5z" fill="currentColor"/></svg>',
    pause: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="6.5" y="5.5" width="3.8" height="13" rx="1" fill="currentColor"/><rect x="13.7" y="5.5" width="3.8" height="13" rx="1" fill="currentColor"/></svg>',
    vol: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z" fill="currentColor"/><path d="M15.5 9a4.2 4.2 0 0 1 0 6M18 6.5a7.7 7.7 0 0 1 0 11" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    mute: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z" fill="currentColor"/><path d="M15.5 9.5l5 5M20.5 9.5l-5 5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    full: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 9V4.5H9M15 4.5h4.5V9M19.5 15v4.5H15M9 19.5H4.5V15" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    guide: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="4.5" width="17" height="15" rx="2.4" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M3.5 9.5h17M9 9.5v10" stroke="currentColor" stroke-width="1.8"/></svg>',
    close: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.1" stroke-linecap="round"/></svg>',
    gear: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3.2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 3.5v2.2M12 18.3v2.2M3.5 12h2.2M18.3 12h2.2M6 6l1.6 1.6M16.4 16.4L18 18M6 18l1.6-1.6M16.4 7.6L18 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
    dice: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="3.5" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="8.6" cy="8.6" r="1.3" fill="currentColor"/><circle cx="15.4" cy="15.4" r="1.3" fill="currentColor"/><circle cx="12" cy="12" r="1.3" fill="currentColor"/></svg>',
    info: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 11v5.2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="7.9" r="1.2" fill="currentColor"/></svg>'
  };
  function icon(name, cls){ return h('span', { className:'ico' + (cls ? ' ' + cls : ''), html: ICONS[name] || '' }); }

  function romsSection(){
    var secs = (state.home && state.home.sections) || [];
    for (var i = 0; i < secs.length; i++) if (secs[i].type === 'roms') return secs[i];
    return null;
  }
  function vodSections(){
    return ((state.home && state.home.sections) || []).filter(function(s){ return s.type !== 'roms'; });
  }
  function currentMode(){
    var v = state.view;
    if (v === 'home') return 'home';
    if (v === 'live') return 'live';
    if (v === 'play' || v === 'game') return 'play';
    if (v === 'section' && state.sectionType === 'roms') return 'play';
    if (v === 'admin' || v === 'settings' || v === 'login' || v === 'welcome') return '';
    return 'vod';
  }
  function goMode(mode){
    state.searchQuery = '';
    if (mode === 'live') navigate('live');
    else if (mode === 'play') { state.sectionQ = ''; state.sectionSystem = ''; if (!state.playTab) state.playTab = 'games'; navigate('play'); }
    else if (mode === 'home') { state.hubTab=(state.user && state.user.prefs || {}).home_landing || 'overview';state.cinemaId=null;navigate('home'); }
    else navigate('library');
  }

  /* Clean path URLs (HTML5 history): /movies/<slug>, /tvshows/<slug>/season-<n>/episode-<m>, /live/<id>,
     /games, /library/<section>, /collections, /settings/<section>, /search?q=. See assets/js/routes.js.
     The server answers every non-API path with this app (index.php fallback), so deep links load directly. */
  var R = window.VaultcastRoutes;
  function routePath(extra){
    extra = extra || {};
    if (state.view === 'game' && state.gamePath && !extra.live) return R.build({ view:'play', gamePath: state.gamePath, roomId:state.gameLobby ? state.gameLobby.session.id : null });
    return R.build({ systemSlug: state.view === 'play' && state.sectionSystem ? (state.sectionSystemSlug || null) : null, view: state.view, movieSlug: state.movieSlug, movieId: state.movieId, showSlug: state.showSlug, showKey: state.showKey,
      season: state.showSeason, episode: state.showEpisode, liveCh: extra.live || null, playTab: state.playTab, section: state.section,
      collectionId: state.collectionId, searchQuery: state.searchQuery, settingsSection: state.settingsSection, adminTab: state.adminTab,
      sectionLetter:state.sectionLetter, collectionsLetter:state.collectionsLetter, hubTab:state.hubTab, cinemaId:state.cinemaId });
  }
  function navUrl(extra){ return BASE + routePath(extra); }
  function shareUrl(path){
    return R.absolute(path != null ? path : routePath(), { publicBase: window.__PUBLIC_BASE_URL__ || '', origin: location.protocol + '//' + location.host, base: BASE });
  }
  /* Old "#/vod/..." style links: rewrite to the clean path once, keeping the history entry. */
  function upgradeLegacyHash(){
    var p = R.legacyHashToPath(location.hash);
    if (p === null) return false;
    try { history.replaceState(history.state || {}, '', BASE + p); } catch (e) {}
    return true;
  }
  /* Apply location.pathname to state. Returns a promise (slug routes are resolved by the server). */
  function applyRoute(){
    if(window.VaultcastHomeExtras)window.VaultcastHomeExtras.captureShare();
    upgradeLegacyHash();
    var rel = R.relative(location.pathname, BASE) + (location.search || '');
    var r = R.parse(rel);
    state.hubTab = r.hubTab || ((state.user && state.user.prefs || {}).home_landing) || 'overview'; state.cinemaId = r.cinemaId || null;
    state.sectionLetter = r.sectionLetter || '';
    state.collectionsLetter = r.collectionsLetter || '';
    state.showSeason = null; state.showEpisode = null;
    if (r.view === 'movie' || r.view === 'show') {
      return api('/resolve?path=' + encodeURIComponent('/' + rel.split('?')[0])).then(function(j){
        if (!j || !j.ok) { state.view = 'home'; toast((j && j.error) || 'Page not found', { kind:'bad' }); return; }
        if (j.view === 'movie') { state.view = 'movie'; state.movieId = j.movie_id; state.movieSlug = r.slug; }
        else { state.view = 'show'; state.showKey = j.show_key; state.showSlug = r.slug; state.showSeason = j.season; state.showEpisode = j.episode; state._showEpisodeItem = j.item_id || null; }
      });
    }
    if (r.view === 'live') { state.view = 'live'; if (r.liveCh) state._openLiveCh = r.liveCh; return Promise.resolve(); }
    if (r.view === 'play' || r.view === 'game') {
      state.view = 'play'; state.playTab = r.playTab || 'games';
      var rs = romsSection();
      if (rs) { state.section = rs.id; state.sectionType = 'roms'; state.sectionLabel = rs.label; }
      state.sectionSystem = ''; state.sectionSystemSlug = null;
      if (!r.resolve) return Promise.resolve();
      return api('/resolve?path=' + encodeURIComponent('/' + rel.split('?')[0])).then(function(j){
        if (!j || !j.ok) { toast((j && j.error) || 'Page not found', { kind:'bad' }); return; }
        if (j.view === 'games_system') { state.sectionSystem = j.system || ''; state.sectionSystemSlug = j.system ? j.system_slug : null; return; }
        if (j.view === 'game' && j.item_id) {
          return api('/items/' + encodeURIComponent(j.item_id) + '?synopsis=0').then(function(d){
            var it = d && (d.item || d);
            if (!it || !it.id) { toast('Game not found', { kind:'bad' }); return; }
            if (it.system) { state.sectionSystem = it.system; state.sectionSystemSlug = String(it.game_path || '').split('/')[0] || null; }
            state.gameItem = it; state.movieId = it.id; state.gamePath = it.game_path || null; state.view = 'game';
            state.gameLobby = null;
            if (r.roomId) return restoreMultiplayerRoom(it, r.roomId);
          });
        }
      });
    }
    if (r.view === 'settings') {
      state.view = 'settings';
      state.settingsSection = r.settingsSection || 'account';
      var adminIds = { maintenance:'maintenance', players:'players', features:'features', libraries:'sections', sections:'sections', scan:'scan', library:'library', media:'media', collections:'collections', streaming:'streaming', convert:'convert', queue:'queue', acquisition:'acquisition', system:'system', general:'general', users:'users', advanced:'advanced', ingest:'ingest', matching:'matching', reports:'reports' };
      if (adminIds[state.settingsSection]) state.adminTab = adminIds[state.settingsSection];
      return Promise.resolve();
    }
    if (r.view === 'section') {
      var sec = ((state.home && state.home.sections) || []).find(function(s){ return s.id === r.section; });
      if (sec) { state.section = sec.id; state.sectionType = sec.type; state.sectionLabel = sec.label; state.view = sec.type === 'roms' ? 'play' : 'section'; return Promise.resolve(); }
    }
    if (r.view === 'collections') { state.view = 'collections'; return Promise.resolve(); }
    if (r.view === 'library') { state.view = 'library'; return Promise.resolve(); }
    if (r.view === 'collection') { state.view = 'collection'; state.collectionId = r.collectionId; return Promise.resolve(); }
    if (r.view === 'search') { state.view = 'search'; state.searchQuery = r.searchQuery || ''; return Promise.resolve(); }
    state.view = 'home';
    return Promise.resolve();
  }
  window.addEventListener('hashchange', function(){
    /* a pasted or clicked old-style link */
    if (!upgradeLegacyHash()) return;
    if (state.view === 'welcome' || state.view === 'login') { rememberReturnPath(); return; }
    if (!state.home) return;
    applyRoute().then(function(){ closePlayer(); _navSilent = true; render(); _navSilent = false; });
  });
  /* Share: copies the absolute link of the current page (or a given path). */
  function copyText(text){
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    return new Promise(function(resolve, reject){
      var ta = document.createElement('textarea');
      ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy') ? resolve() : reject(new Error('copy failed')); } catch (e) { reject(e); }
      document.body.removeChild(ta);
    });
  }
  function shareButton(getPath, label){
    return h('button', { className:'btn ghost share-btn', type:'button', title:'Copy link', 'aria-label': label || 'Copy link to this page', onClick: function(e){
      if (e) e.stopPropagation();
      var url = shareUrl(typeof getPath === 'function' ? getPath() : getPath);
      copyText(url).then(function(){ toast('Copied', { kind:'ok', ms:1600 }); }, function(){ window.prompt('Copy this link', url); });
    }}, [ h('span', { 'aria-hidden':'true', text:'🔗' }), h('span', { text:' Share' }) ]);
  }

  function rowEl(title, cards, opts){
    opts = opts || {};
    var sc = h('div', { className:'scroller' }, cards);
    function page(dir){
      try { sc.scrollBy({ left: dir * Math.max(240, sc.clientWidth * 0.85), behavior:'smooth' }); }
      catch (e) { sc.scrollLeft += dir * sc.clientWidth * 0.85; }
    }
    return h('section', { className:'row' + (opts.className ? ' ' + opts.className : '') }, [
      h('div', { className:'row-head' }, [
        h('h2', { text: title, onClick: opts.more || null, className: opts.more ? 'clickable' : '' }),
        opts.meta ? h('span', { className:'meta', text: opts.meta }) : null,
        opts.more ? h('button', { className:'btn ghost row-more', type:'button', onClick: opts.more }, [ h('span', { text: opts.moreText || 'Browse all' }), icon('chevR') ]) : null
      ]),
      h('div', { className:'rail' }, [
        h('button', { className:'rail-arrow prev', type:'button', 'aria-label':'Scroll left', tabindex:'-1', onClick: function(){ page(-1); } }, [ icon('chevL') ]),
        sc,
        h('button', { className:'rail-arrow next', type:'button', 'aria-label':'Scroll right', tabindex:'-1', onClick: function(){ page(1); } }, [ icon('chevR') ])
      ])
    ]);
  }

  function closeMenus(){
    $all('.menu-pop').forEach(function(p){ p.hidden = true; });
    $all('[aria-haspopup="menu"]').forEach(function(b){ b.setAttribute('aria-expanded', 'false'); });
  }
  document.addEventListener('click', function(e){
    if (e.target && e.target.closest && e.target.closest('.menu-wrap')) return;
    closeMenus();
  });

  function accountMenu(){
    var user = state.user;
    return h('button', {
      className: 'icon-btn avatar' + (user ? ' signed-in' : ''), type: 'button',
      title: 'Account & settings', 'aria-label': 'Open account and settings',
      onClick: function(){ openSettings('account'); }
    }, [user ? profileAvatar(user) : icon('user')]);
  }

  function searchBoxEl(mode){
    var ph = mode === 'live' ? 'Filter channels…' : (mode === 'play' ? 'Search games…' : 'Search movies & shows…');
    var val = mode === 'live' ? (live.filter || '') : (mode === 'play' ? (state.sectionQ || '') : (state.searchQuery || ''));
    var input = h('input', {
      className:'search-box', id:'q', type:'search', placeholder: ph, value: val, autocomplete:'off', spellcheck:'false',
      'aria-label': ph,
      onInput: function(e){
        if (mode !== 'live') return;
        live.filter = (e.target.value || '').trim();
        clearTimeout(searchBoxEl._t);
        searchBoxEl._t = setTimeout(paintLive, 140);
      },
      onKeydown: function(e){
        if (e.key === 'Escape') { e.target.blur(); return; }
        if (e.key !== 'Enter') return;
        var v = (e.target.value || '').trim();
        if (mode === 'live') { live.filter = v; paintLive(); return; }
        if (mode === 'play') {
          var rs = romsSection();
          if (!rs) return;
          state.sectionLetter = '';
          if (state.view !== 'play') { state.sectionQ = v; navigate('play'); }
          else loadSection(rs.id, v);
          return;
        }
        state.searchQuery = v;
        if (state.view === 'section' && state.section && !v) loadSection(state.section, '');
        else runGlobalSearch(v);
      }
    });
    var wrap=h('div', { className:'search-wrap' }, [ icon('search'), input, h('kbd', { className:'kbd-hint', text:'/' }) ]);
    if(mode!=='live' && window.VaultcastLiveSearch)window.VaultcastLiveSearch.attach(wrap,input,{api:api,h:h,mode:mode,open:function(it){if(isShowItem(it))openShow(it.show_key||it.id);else if(it.media_kind==='rom')openGame(it);else if(it.show)openShow(it.show_key||it.id,it.season,it.episode);else openMovie(it.id);}});
    return wrap;
  }

  function subnavEl(mode){
    var items = [];
    if (mode === 'home') {
      [['overview','For you'],['watchlist','Watchlist'],['favourites','Favourites'],['calendar','Release calendar'],['arrivals','New episodes'],['lists','My collections'],['friends','Friends'],['chat','Chat'],['cinema','Watch together'],['history','History'],['stats','Game stats'],['inbox','Inbox']].forEach(function(t){items.push({label:t[1],chatKind:t[0]==='inbox'?'mail':t[0]==='chat'?'dm':null,active:(state.hubTab||'overview')===t[0],go:function(){window.VaultcastHub.go(t[0]);}});});
    } else if (mode === 'vod') {
      items.push({ label:'Discover', active: state.view === 'library', go: function(){ navigate('library'); } });
      vodSections().forEach(function(s){
        items.push({ label: s.label, active: (state.view === 'section' && state.section === s.id), go: function(){ navigate('section', s.id); } });
      });
      items.push({ label:'Collections', active: state.view === 'collections' || state.view === 'collection', go: function(){ navigate('collections'); } });
    } else if (mode === 'live') {
      [['On now', 'live-top'], ['Relay', 'live-relay'], ['IPTV', 'live-iptv'], ['Guide', 'live-guide']].forEach(function(p, i){
        items.push({ label: p[0], active: i === 0, go: function(){
          var t = document.getElementById(p[1]);
          if (t) { try { t.scrollIntoView({ behavior:'smooth', block:'start' }); } catch (e) { t.scrollIntoView(); } }
        } });
      });
    } else if (mode === 'play') {
      var rs = romsSection();
      var tab = state.playTab || 'games';
      items.push({ label: rs ? (rs.label.indexOf('Games') >= 0 ? rs.label : 'Games / ROMs') : 'Games / ROMs', active: tab === 'games', go: function(){ state.sectionQ = ''; state.sectionLetter = ''; state.sectionSystem = ''; goPlayTab('games'); } });
      items.push({ label: 'Lobby', active: tab === 'lobby', go: function(){ goPlayTab('lobby'); } });
    } else {
      return null;
    }
    var right = [];
    if (mode === 'live') right.push(h('button', { className:'subnav-link', type:'button', title:'Relay channels, IPTV feeds & favourites', onClick: function(){ openSettings('live-channels'); } }, [ icon('gear'), h('span', { text:'Live settings' }) ]));
    if (mode === 'play' && (state.playTab || 'games') === 'games') right.push(h('button', { className:'subnav-link', type:'button', onClick: surpriseGame }, [ icon('dice'), h('span', { text:'Surprise me' }) ]));
    if (mode === 'play' && (state.playTab || 'games') === 'lobby') right.push(h('button', { className:'subnav-link', type:'button', onClick: openLobbyStartPicker }, [ icon('playFill'), h('span', { text:'Start multiplayer' }) ]));
    return h('nav', { className:'subnav', 'aria-label':'Section' }, [
      h('div', { className:'subnav-inner' }, items.map(function(it){
        return h('button', { className:'subnav-btn' + (it.active ? ' active' : ''), type:'button', 'data-chat-kind':it.chatKind||'', text:it.label+(it.chatKind&&state.chatUnreadOwner===(state.user&&state.user.id)&&state.chatUnread&&state.chatUnread[it.chatKind]?' •':''), onClick: it.go });
      })),
      right.length ? h('div', { className:'subnav-right' }, right) : null
    ]);
  }

  function shell(content){
    var mode = currentMode();
    try { document.body.setAttribute('data-mode', mode || 'none'); } catch (e) {}
    var modes = [
      ['home', 'Home', 'Your lists, friends and next great night'],
      ['vod', 'Library', 'Films, shows and music'],
      ['live', 'Watch Live', 'Live Relay channels, IPTV & guide'],
      ['play', 'Play', 'Retro games in your browser']
    ];
    modes = modes.filter(function(m){
      if (m[0] !== 'home' && !userAreaAllowed({vod:'library', live:'live', play:'games'}[m[0]])) return false;
      if (m[0] === 'live') return !!(state.home && state.home.features && state.home.features.live);
      if (m[0] === 'play') return !!romsSection();
      return true;
    });
    var header = h('header', { className:'topnav xnav' }, [
      h('div', { className:'brand', role:'link', tabindex:'0', title:'Home', onClick: function(){ goMode('home'); }, onKeydown: function(e){ if (e.key === 'Enter') goMode('home'); } }, [
        h('span', { className:'mark', html: ICONS.playFill }),
        h('span', { className:'brand-name', text: (state.home && state.home.app_name) || 'Vaultcast' })
      ]),
      h('nav', { className:'mode-switch', role:'tablist', 'aria-label':'Mode' }, modes.map(function(m){
        return h('button', {
          className:'mode-tab mode-' + m[0] + (mode === m[0] ? ' active' : ''), type:'button', role:'tab',
          'aria-selected': mode === m[0] ? 'true' : 'false', title: m[2],
          onClick: function(){ goMode(m[0]); }
        }, [ icon(m[0]), h('span', { className:'mode-label', text: m[1] }) ]);
      })),
      h('div', { className:'nav-right' }, [ userAreaAllowed('library') || userAreaAllowed('games') ? searchBoxEl(mode) : null, accountMenu() ])
    ]);
    return h('div', { className:'app-shell xshell' }, [
      header,
      subnavEl(mode),
      h('main', { className:'view view-enter', id:'main' }, [ content ])
    ]);
  }

  function cleanTitle(item){
    if (!item) return '';
    if (item.show) return item.show;
    return String(item.title || 'Untitled').replace(/\s+[—-]\s+(?:\d{3,4}p|WEB|BluRay|HDTV).*$/i, '');
  }

  function stopBillboardCycle(){
    if (state._billboardTimer) {
      try { clearInterval(state._billboardTimer); } catch (eBb) {}
      state._billboardTimer = null;
    }
  }

  function billboardPaintFeat(bb, home, feat, eyebrow){
    if (!bb || !feat) return;
    var resume = feat.progress || null;
    var isShow = isShowItem(feat);
    var eyebrowEl = bb.querySelector('.eyebrow');
    var titleEl = bb.querySelector('.billboard-copy h1');
    var metaEl = bb.querySelector('.billboard-meta');
    var progWrap = bb.querySelector('.billboard-progress');
    var actionsEl = bb.querySelector('.billboard-copy .row-actions');
    var poster = bb.querySelector('.billboard-poster');
    if (eyebrowEl) eyebrowEl.textContent = eyebrow;
    if (titleEl) titleEl.textContent = cleanTitle(feat);
    var meta = [];
    if (feat.show && feat.season != null) meta.push(epCode(feat.season, feat.episode) + ' \u00b7 ' + episodeDisplayTitle(feat));
    if (feat.year) meta.push(String(feat.year));
    if (resume && resume.duration) {
      var left = Math.max(0, Math.round((resume.duration - resume.position) / 60));
      meta.push(left + ' min left');
    }
    if (metaEl) {
      if (meta.length) { metaEl.textContent = meta.join(' \u00b7 '); metaEl.hidden = false; }
      else { metaEl.textContent = ''; metaEl.hidden = true; }
    }
    if (progWrap) {
      if (resume && resume.duration) {
        progWrap.hidden = false;
        var span = progWrap.querySelector('span');
        if (span) span.style.width = Math.min(100, Math.round(100 * resume.position / resume.duration)) + '%';
      } else {
        progWrap.hidden = true;
      }
    }
    if (actionsEl) {
      actionsEl.innerHTML = '';
      var playBtn = h('button', { className:'btn primary btn-lg', type:'button', onClick: function(){ playItem(feat); } }, [ icon('playFill'), h('span', { text: resume ? 'Resume' : 'Play' }) ]);
      actionsEl.appendChild(playBtn);
      if (isShow || feat.show_key || feat.type === 'movies' || feat.type === 'disney' || feat.media_kind === 'video') {
        actionsEl.appendChild(h('button', { className:'btn glass btn-lg', type:'button', onClick: function(){
          if (feat.show_key) openShow(feat.show_key); else if (isShow) openShow(feat.id); else openMovie(feat.id);
        } }, [ icon('info'), h('span', { text:'More info' }) ]));
      }
    }
    if (poster) {
      /* reset loaded/queued flags + fade so rotate actually paints the next CW poster */
      resetPosterEl(poster);
      poster.innerHTML = '';
      poster.appendChild(h('div', { className:'ph', text: cleanTitle(feat) }));
      poster.classList.add('poster-swap');
      try { poster.offsetWidth; } catch (eForce) {}
      poster.classList.remove('poster-swap');
      var pid = posterIdFor(feat);
      if (pid) {
        if (posterNeedsRemote(feat)) poster.setAttribute('data-poster-fetch', '1');
        else poster.removeAttribute('data-poster-fetch');
        observePoster(poster, pid, posterKindFor(feat));
        loadPosterNow(poster, pid, posterKindFor(feat));
      }
    }
    try { bb.setAttribute('data-bb-id', String(feat.id || feat.show_key || '')); } catch (eId) {}
  }


  /* Continue Watching — one entry per TV show (API collapses; client guard for stale payloads). */
  function cwOnePerShow(list){
    var out = [], seen = {};
    (list || []).forEach(function(it){
      if (!it) return;
      var g = it.cw_group || (it.show ? (it.show_key || ((it.section || '') + '|' + it.show)) : null);
      if (g) { if (seen[g]) return; seen[g] = true; }
      out.push(it);
    });
    return out;
  }
  function cwEyebrow(it){ return (it && it.cw_state === 'next') ? 'Up next' : 'Continue watching'; }

  function billboardEl(home){
    stopBillboardCycle();
    var feat = null, eyebrow = 'Tonight\u2019s pick', resume = null;
    var cw = cwOnePerShow(home.continue_watching).filter(function(it){ return it.media_kind !== 'rom'; });
    if (cw.length) { feat = cw[0]; eyebrow = cwEyebrow(feat); resume = feat.progress; }
    if (!feat) {
      var pool = [];
      vodSections().forEach(function(s){ (s.items || []).forEach(function(it){ if (it.has_poster) pool.push(it); }); });
      if (!pool.length) vodSections().forEach(function(s){ (s.items || []).forEach(function(it){ pool.push(it); }); });
      if (pool.length) {
        var day = Math.floor(Date.now() / 3600000);
        feat = pool[day % pool.length];
      }
    }
    if (!feat) {
      return h('section', { className:'billboard billboard-empty' }, [
        h('div', { className:'billboard-inner' }, [ h('div', { className:'billboard-copy' }, [
          h('div', { className:'eyebrow', text:'VoD' }),
          h('h1', { text: state.user ? 'Welcome back' : ((home.app_name) || 'Vaultcast') }),
          h('p', { className:'muted', text: (home.item_count || 0) + ' titles in your library' })
        ]) ])
      ]);
    }
    var isShow = isShowItem(feat);
    var poster = h('div', { className:'poster poster-pending billboard-poster', 'data-backdrop-src':'1', 'data-poster-variant':'full' }, [ h('div', { className:'ph', text: cleanTitle(feat) }) ]);
    var meta = [];
    if (feat.show && feat.season != null) meta.push(epCode(feat.season, feat.episode) + ' \u00b7 ' + episodeDisplayTitle(feat));
    if (feat.year) meta.push(String(feat.year));
    if (resume && resume.duration) {
      var left = Math.max(0, Math.round((resume.duration - resume.position) / 60));
      meta.push(left + ' min left');
    }
    var actions = [
      h('button', { className:'btn primary btn-lg', type:'button', onClick: function(){ playItem(feat); } }, [ icon('playFill'), h('span', { text: resume ? 'Resume' : 'Play' }) ])
    ];
    if (isShow || feat.show_key || feat.type === 'movies' || feat.type === 'disney' || feat.media_kind === 'video') {
      actions.push(h('button', { className:'btn glass btn-lg', type:'button', onClick: function(){
        if (feat.show_key) openShow(feat.show_key); else if (isShow) openShow(feat.id); else openMovie(feat.id);
      } }, [ icon('info'), h('span', { text:'More info' }) ]));
    }
    var bb = h('section', { className:'billboard', 'data-backdrop-host':'1' }, [
      h('div', { className:'billboard-bg' }),
      h('div', { className:'billboard-inner' }, [
        h('div', { className:'billboard-copy' }, [
          h('div', { className:'eyebrow', text: eyebrow }),
          h('h1', { text: cleanTitle(feat) }),
          h('p', { className:'billboard-meta', text: meta.length ? meta.join(' \u00b7 ') : '', hidden: meta.length ? null : true }),
          h('div', { className:'billboard-progress', hidden: !(resume && resume.duration) }, [ h('span', { style:'width:' + (resume && resume.duration ? Math.min(100, Math.round(100 * resume.position / resume.duration)) : 0) + '%' }) ]),
          h('div', { className:'row-actions' }, actions),
          h('p', { className:'billboard-foot muted', text: (home.item_count || 0) + ' titles' + (home.scanned_at ? ' \u00b7 library scanned ' + String(home.scanned_at).slice(0, 10) : '') })
        ]),
        poster
      ])
    ]);
    var pid = posterIdFor(feat);
    if (pid) {
      if (posterNeedsRemote(feat)) poster.setAttribute('data-poster-fetch', '1');
      setTimeout(function(){ observePoster(poster, pid, posterKindFor(feat)); }, 0);
    }
    // Rotate Continue Watching titles every five seconds.
    var BILLBOARD_CW_MS = 5000;
    if (cw.length > 1) {
      var idx = 0;
      state._billboardTimer = setInterval(function(){
        if (!bb.isConnected) { stopBillboardCycle(); return; }
        idx = (idx + 1) % cw.length;
        billboardPaintFeat(bb, home, cw[idx], cwEyebrow(cw[idx]));
      }, BILLBOARD_CW_MS);
    }
    return bb;
  }

  function viewHome(){
    var home = state.home || { continue_watching:[], sections:[] };
    var parts = [ billboardEl(home) ];
    var cw = cwOnePerShow(home.continue_watching);
    if (cw.length) parts.push(rowEl('Continue watching', cw.map(function(it){ return cardEl(it, it.progress); }), { meta: String(cw.length) }));
    parts.push(h('div', { id:'live-teaser' }));
    vodSections().forEach(function(sec){
      var items = sec.items || [];
      if (!items.length) {
        parts.push(h('section', { className:'row' }, [
          h('div', { className:'row-head' }, [ h('h2', { text: sec.label }) ]),
          h('div', { style:'padding:0 var(--gutter)' }, [ emptyState('No titles yet', 'Scan this library from Admin when ready.') ])
        ]));
        return;
      }
      parts.push(rowEl(sec.label, items.map(function(it){ return cardEl(it); }), {
        more: function(){ navigate('section', sec.id); }, moreText: 'Browse all ' + (sec.count || '')
      }));
    });
    var rs = romsSection();
    if (rs && (rs.items || []).length) {
      parts.push(rowEl('From Play \u00b7 ' + rs.label, rs.items.map(function(it){ return cardEl(it); }), {
        className:'row-play', more: function(){ goMode('play'); }, moreText:'Open Play'
      }));
    }
    if (!(home.sections || []).length) {
      var isAdmin = state.user && state.user.role === 'admin';
      var welcome = emptyState('Your library starts here', isAdmin ? 'Add a media folder, then scan to bring your collection together.' : 'Your library is being prepared. Sign in to manage it.');
      welcome.appendChild(h('button', {className:'btn primary', text:isAdmin ? 'Add a library' : 'Sign in', onClick:function(){
        if (isAdmin) openSettings('sections'); else { state.view='login'; render(); }
      }}));
      parts.push(welcome);
    }
    setTimeout(loadLiveTeaser, 0);
    return shell(h('div', { className:'vod-home' }, parts));
  }

  function sectionHeroEl(id, text){
    var isPlay = state.view === 'play';
    var kids = [
      h('div', { className:'eyebrow', text: isPlay ? 'Play' : 'VoD' }),
      h('h1', { text: isPlay ? (state.sectionLabel || 'Games') : (state.sectionLabel || id) }),
      h('p', { className:'muted', text: text })
    ];
    if (isPlay) {
      kids.push(h('div', { className:'row-actions' }, [
        h('button', { className:'btn primary', type:'button', onClick: surpriseGame }, [ icon('dice'), h('span', { text:'Surprise me' }) ]),
        state.sectionQ || state.sectionSystem ? h('button', { className:'btn glass', type:'button', text: state.sectionSystem && !state.sectionQ ? 'Clear console' : 'Clear search', onClick: function(){ var rs = romsSection(); state.sectionSystem=''; if (rs) loadSection(rs.id, ''); var b = $('#q'); if (b) b.value = ''; } }) : null
      ]));
    }
    return h('div', { className:'hero section-hero' + (isPlay ? ' play-hero' : '') }, kids);
  }

  function surpriseGame(){
    var rs = romsSection();
    if (!rs) { toast('No games library configured'); return; }
    var n = Math.max(1, rs.count || rs.item_count || 1);
    var page = 1 + Math.floor(Math.random() * n);
    toast('Picking a game\u2026', { ms: 1200 });
    api('/sections/' + encodeURIComponent(rs.id) + '/items?per=1&page=' + page).then(function(j){
      var it = j && (j.items || [])[0];
      if (it) { toast('Starting ' + (it.title || 'game') + (it.system ? ' \u00b7 ' + it.system : ''), { ms: 2200 }); playItem(it); }
      else toast('No game found, try again');
    }).catch(function(e){ toast(String(e), { kind:'bad' }); });
  }

    function viewPlay(){
    if ((state.playTab || 'games') === 'lobby') return viewLobby();
    lobbyStopPoll();
    var rs = romsSection();
    if (!rs) return shell(h('div', { className:'panel' }, [ emptyState('No games library', 'Add a ROMs library under Admin → Libraries, then scan.') ]));
    state.section = rs.id; state.sectionType = 'roms'; state.sectionLabel = rs.label;
    var wrap = h('div', { id:'section-view', className:'play-view' }, [ sectionHeroEl(rs.id, '') ]);
    setTimeout(function(){ loadSection(rs.id, state.sectionQ || ''); }, 0);
    return shell(wrap);
  }


  /* ------------------------------ Watch Live ------------------------------ */
  var live = { guide:null, iptv:null, loadedAt:0, rev:0, loadedRev:-1, busyRev:-1, filter:'', refreshTimer:null, tickTimer:null, busy:null, lastPaint:0 };
  var LIVE_BASE = BASE + 'live/';
  /* Live backend is reached through /media/api/live/<action> (api/live_bridge.php):
     public HTTPS 404s any *.php under /media/live/, LAN :9999 works either way. */
  var LIVE_API = BASE + 'api/live/';
  function liveApi(action, params){
    return fetch(LIVE_API + action + (params ? '?' + String(params).replace(/^&/, '') : ''), { credentials:'same-origin', cache:'no-store' })
      .then(function(r){
        if (!r.ok) throw new Error('Live API HTTP ' + r.status);
        return r.json();
      });
  }
  function liveUrl(u){
    if (!u) return u;
    var m = String(u).match(/(?:^|\/)api\.php\?action=([a-z_]+)&?(.*)$/);
    if (m) return LIVE_API + m[1] + (m[2] ? '?' + m[2] : '');
    if (/^https?:\/\//i.test(u)) return u;
    if (u.charAt(0) === '/') return u;
    return LIVE_BASE + u;
  }
  function liveChannelNum(c){
    var n = c && c.number;
    if (n == null || n === '') return Number.POSITIVE_INFINITY;
    n = +n;
    return (isFinite(n) && n > 0) ? n : Number.POSITIVE_INFINITY;
  }
  function sortLiveChannels(list){
    return (list || []).slice().sort(function(a, b){
      var an = liveChannelNum(a), bn = liveChannelNum(b);
      if (an !== bn) return an - bn;
      var ak = (a && a.kind) || '', bk = (b && b.kind) || '';
      if (ak !== bk) return ak < bk ? -1 : 1;
      var ai = (a && a.id) || '', bi = (b && b.id) || '';
      return ai < bi ? -1 : (ai > bi ? 1 : 0);
    });
  }
  /* Relay live edge - /now returns seekMs/serverNow/mediaOffsetMs; player joins mid-show,
     media without durations are backfilled (ffprobe) instead of becoming 1-second blocks */
  /* Watch Live settings are one native editor: Relay channels (show/season/episode trees, full
     searchable lists), Relay media library (server catalogue import, add/edit/remove), playlists, schedule, IPTV, station */
  /* Native Live/IPTV settings + 24h rolling schedule overlay */
  /* Settings accordion open-group in state (clicks no longer reverted by active-section re-derive) */
  /* Watch Live structured show/episode/season + posters; Settings System + accordion; Collections full width */
  /* TV UI prefers episode_title (NFO/parsed) over file basename */
  /* Watch Live / player zap / guide — channel number ascending */
  function liveChannels(){ return sortLiveChannels((live.guide && live.guide.channels) || []); }
  /* live-cache:start (tests/live_cache_test.js evaluates this block) */
  /* Watch Live keeps the guide + IPTV lineup in memory for 90 s. Every Live/IPTV write (favourite
     added, removed, toggled, reordered, feeds or station saved) bumps live.rev so the next read
     refetches, and tells other open tabs through localStorage so they refresh too. */
  function ensureLiveData(force){
    if (!force && live.guide && live.iptv && live.loadedRev === live.rev && Date.now() - live.loadedAt < 90000) return Promise.resolve();
    if (live.busy && live.busyRev === live.rev) return live.busy;
    var rev = live.rev;
    var req = Promise.all([ liveApi('guide', '&hours=3'), liveApi('iptv') ]).then(function(pair){
      if (live.busy === req) live.busy = null;
      if (rev !== live.rev) return ensureLiveData(true); // a write landed while this read was in flight
      if (pair[0] && pair[0].channels) live.guide = pair[0];
      if (pair[1] && pair[1].ok) live.iptv = pair[1];
      live.loadedAt = Date.now();
      live.loadedRev = rev;
    }, function(e){ if (live.busy === req) live.busy = null; throw e; });
    live.busy = req; live.busyRev = rev;
    return req;
  }
  function invalidateLiveData(fromOtherTab){
    live.rev = (live.rev || 0) + 1;
    live.loadedAt = 0;
    if (!fromOtherTab) { try { localStorage.setItem('vaultcast-live-rev', String(Date.now()) + ':' + Math.random()); } catch (e) {} }
    if (typeof state !== 'undefined' && state.view === 'live' && typeof paintLive === 'function') {
      ensureLiveData(true).then(function(){ if (state.view === 'live') paintLive(); }).catch(function(){});
    }
  }
  function liveApiPost(action, body){
    return fetch(LIVE_API + action, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {})
    }).then(function(r){
      return r.json().then(function(j){
        if (r.ok && j && j.ok !== false && action !== 'iptv_probe') invalidateLiveData(false);
        return { ok: r.ok, status: r.status, body: j };
      });
    });
  }
  /* live-cache:end */
  try { window.addEventListener('storage', function(e){ if (e && e.key === 'vaultcast-live-rev') invalidateLiveData(true); }); } catch (eLs) {}
  function stopLiveTimers(){
    if (live.refreshTimer) { clearInterval(live.refreshTimer); live.refreshTimer = null; }
    if (live.tickTimer) { clearInterval(live.tickTimer); live.tickTimer = null; }
  }
  function fmtClock(ms){
    try { return new Date(ms).toLocaleTimeString([], { hour:'numeric', minute:'2-digit' }); } catch (e) { return ''; }
  }
  function fmtLeft(endMs){
    var m = Math.max(0, Math.round((endMs - Date.now()) / 60000));
    if (m >= 90) return Math.floor(m / 60) + 'h ' + (m % 60) + 'm left';
    return m + 'm left';
  }
  function isFiller(p){
    if (!p) return true;
    return p.kind === 'commercial' || p.kind === 'bumper';
  }

  /* structured now-playing from guide fields or "Show · SxxExx · Episode" title */
  function parseProgMeta(p){
    var empty = { showTitle:'', episodeTitle:'', season:null, episode:null, catalogueId:null, posterId:null };
    if (!p) return empty;
    var showTitle = p.showTitle || '';
    var episodeTitle = p.episodeTitle || '';
    var season = (p.season != null && p.season !== '') ? +p.season : null;
    var episode = (p.episode != null && p.episode !== '') ? +p.episode : null;
    var catalogueId = p.catalogueId || null;
    if (!showTitle || (season == null && !episodeTitle)) {
      var title = String(p.title || '');
      var m = title.match(/^(.+?)\s*[·•]\s*S(\d{1,2})E(\d{1,3})\s*[·•]\s*(.+)$/i);
      if (m) {
        if (!showTitle) showTitle = m[1].trim();
        if (season == null) season = +m[2];
        if (episode == null) episode = +m[3];
        if (!episodeTitle) episodeTitle = m[4].trim();
      } else {
        m = title.match(/^(.+?)\s*[·•]\s*S(\d{1,2})E(\d{1,3})\s*$/i);
        if (m) {
          if (!showTitle) showTitle = m[1].trim();
          if (season == null) season = +m[2];
          if (episode == null) episode = +m[3];
        } else {
          m = title.match(/^(.+?)\s*[·•]\s*(.+)$/);
          if (m) {
            if (!showTitle) showTitle = m[1].trim();
            if (!episodeTitle) episodeTitle = m[2].trim();
          } else if (!showTitle) {
            showTitle = title;
          }
        }
      }
    }
    var posterId = catalogueId || null;
    return { showTitle: showTitle, episodeTitle: episodeTitle, season: season, episode: episode, catalogueId: catalogueId, posterId: posterId };
  }
  function seasonEpisodeLabel(meta){
    if (!meta) return '';
    var parts = [];
    if (meta.season != null && !isNaN(meta.season)) parts.push('Season ' + meta.season);
    if (meta.episode != null && !isNaN(meta.episode)) parts.push('Episode ' + meta.episode);
    return parts.join('  ');
  }
  function livePosterEl(meta, cls){
    var el = h('div', { className: 'lc-poster poster-pending' + (cls ? ' ' + cls : '') });
    if (meta && meta.posterId) {
      observePoster(el, meta.posterId, 'item');
    } else {
      el.classList.remove('poster-pending');
      el.classList.add('lc-poster-empty');
      var initials = ((meta && meta.showTitle) || '?').replace(/[^A-Za-z0-9]/g, '').slice(0, 2).toUpperCase() || '?';
      el.appendChild(h('span', { className:'lc-poster-mono', text: initials }));
    }
    return el;
  }

  function progNowAndNext(ch){
    var now = Date.now(), cur = null, nxt = null;
    var progs = ch.programs || [];
    for (var i = 0; i < progs.length; i++) {
      var p = progs[i];
      if (p.startUnix <= now && p.endUnix > now) cur = p;
      else if (p.startUnix > now && !isFiller(p) && !nxt) nxt = p;
    }
    if (!cur) cur = ch.now || null;
    if (!nxt) nxt = (ch.next && !isFiller(ch.next)) ? ch.next : (ch.next || null);
    if (cur && isFiller(cur) && ch.kind === 'relay') {
      // Show the programme around the break rather than "Peninsula Bank"
      var main = null;
      for (var j = 0; j < progs.length; j++) { if (progs[j].endUnix <= now && !isFiller(progs[j])) main = progs[j]; }
      return { now: cur, nxt: nxt, label: nxt ? ('Break \u00b7 up next ' + nxt.title) : 'Break', main: main };
    }
    return { now: cur, nxt: nxt, label: cur ? cur.title : 'Off air' };
  }


  /* this-channel EPG strip (now + upcoming) for the live player */
  function channelEpgItems(ch, limit){
    limit = limit || 5;
    if (!ch) return [];
    var now = Date.now();
    var progs = (ch.programs && ch.programs.length) ? ch.programs.slice() : [ch.now, ch.next].filter(Boolean);
    progs.sort(function(a, b){ return (a.startUnix || 0) - (b.startUnix || 0); });
    var out = [], seen = {};
    for (var i = 0; i < progs.length; i++) {
      var p = progs[i];
      if (!p || !p.endUnix || p.endUnix <= now) continue;
      if (ch.kind === 'relay' && isFiller(p)) continue;
      if (ch.kind === 'iptv' && p.kind === 'live' && out.length) continue;
      var key = String(p.startUnix) + '|' + String(p.title || '');
      if (seen[key]) continue;
      seen[key] = 1;
      out.push(p);
      if (out.length >= limit) break;
    }
    return out;
  }
  function channelEpgLabel(p, ch){
    if (!p) return '';
    if (ch && ch.kind === 'iptv' && p.kind === 'live') return (ch.name || 'Live') + ' · live';
    var meta = parseProgMeta(p);
    var main = meta.showTitle || p.title || 'Programme';
    if (meta.episodeTitle) return main + ' · ' + meta.episodeTitle;
    var se = seasonEpisodeLabel(meta);
    if (se) return main + ' · ' + se;
    return main;
  }

  function iptvSource(ch){
    var lineup = (live.iptv && live.iptv.lineup) || [];
    for (var i = 0; i < lineup.length; i++) if (lineup[i].id === ch.iptvId) return lineup[i];
    return null;
  }
  function iptvStatus(ch){
    var favs = (live.iptv && live.iptv.favourites) || [];
    for (var i = 0; i < favs.length; i++) if (favs[i].id === ch.iptvId) return favs[i].streamStatus || '';
    return '';
  }
  function chanLogoEl(ch, cls){
    var name = ch.name || ('Channel ' + ch.number);
    var mono = h('span', { className:'chan-mono' + (cls ? ' ' + cls : ''), text: String(name).replace(/[^A-Za-z0-9]/g, '').slice(0, 2).toUpperCase() || String(ch.number) });
    if (!ch.logo) return mono;
    var lsrc = String(ch.logo);
    if (/^https?:\/\//i.test(lsrc) && lsrc.indexOf(location.origin + '/') !== 0) lsrc = LIVE_API + 'logo?u=' + encodeURIComponent(lsrc);
    var img = h('img', { className:'chan-logo' + (cls ? ' ' + cls : ''), src: lsrc, alt:'', loading:'lazy', referrerpolicy:'no-referrer' });
    img.addEventListener('error', function(){ if (img.parentNode) img.parentNode.replaceChild(mono, img); });
    return img;
  }
  function liveCardEl(ch, opts){
    opts = opts || {};
    var nn = progNowAndNext(ch);
    var p = nn.now;
    var isIptv = ch.kind === 'iptv';
    var pct = (p && p.endUnix > p.startUnix) ? Math.max(0, Math.min(100, 100 * (Date.now() - p.startUnix) / (p.endUnix - p.startUnix))) : 0;
    var status = isIptv ? iptvStatus(ch) : '';
    var meta = parseProgMeta(p);
    var fallbackTitle = isIptv && p && p.kind === 'live' ? ('Live · ' + (ch.slogan || 'stream')) : nn.label;
    var showLine = meta.showTitle || (isIptv ? (ch.name || fallbackTitle) : fallbackTitle);
    var epLine = meta.episodeTitle || '';
    var seLine = seasonEpisodeLabel(meta);
    if (isIptv && p && p.kind === 'live') {
      showLine = ch.name || 'Live';
      epLine = ch.slogan || 'Live stream';
      seLine = '';
    }
    var kids = [];
    if (!isIptv) {
      kids.push(livePosterEl(meta, 'lc-poster-main'));
    }
    kids.push(h('div', { className:'lc-body' }, [
      h('div', { className:'lc-top' }, [
        h('span', { className:'lc-num', text: String(ch.number) }),
        isIptv ? chanLogoEl(ch, 'lc-logo') : null,
        h('span', { className:'lc-pill' + (isIptv ? ' iptv' : ''), text: isIptv ? 'IPTV' : 'LIVE' })
      ]),
      h('div', { className:'lc-chan', text: ch.name || ('Channel ' + ch.number) }),
      h('div', { className:'lc-show', text: showLine }),
      epLine ? h('div', { className:'lc-ep', text: epLine }) : null,
      seLine ? h('div', { className:'lc-se', text: seLine }) : null,
      (!isIptv && p) ? h('div', { className:'lc-time', 'data-end': String(p.endUnix) }, [
        h('span', { text: fmtClock(p.startUnix) + ' \u2013 ' + fmtClock(p.endUnix) }),
        h('span', { className:'lc-left', text: fmtLeft(p.endUnix) })
      ]) : h('div', { className:'lc-time' }, [
        h('span', { text: [ch.slogan || 'Live', (iptvSource(ch) || {}).quality, 'HLS'].filter(Boolean).join(' \u00b7 ') }),
        status === 'ok' ? h('span', { className:'lc-ok', text:'\u25cf online' }) : null
      ]),
      h('div', { className:'lc-bar' }, [ h('span', { style:'width:' + (isIptv ? 100 : pct) + '%', 'data-s': p ? String(p.startUnix) : '', 'data-e': p ? String(p.endUnix) : '' }) ]),
      (function(){ if (!nn.nxt || isIptv) return null; var nm = parseProgMeta(nn.nxt); return h('div', { className:'lc-next', text:'Next \u00b7 ' + (nm.showTitle || nn.nxt.title) + (nm.episodeTitle ? (' \u00b7 ' + nm.episodeTitle) : '') + ' \u00b7 ' + fmtClock(nn.nxt.startUnix) }); })()
    ]));
    var card = h('div', { className:'live-card' + (isIptv ? ' is-iptv' : ' is-relay has-poster') + (opts.compact ? ' compact' : ''), tabindex:'0', role:'button',
      'aria-label':'Watch ' + (ch.name || ('channel ' + ch.number)),
      onClick: function(){ openLivePlayer(ch.id); },
      onKeydown: function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openLivePlayer(ch.id); } }
    }, kids);
    return card;
  }

  function loadLiveTeaser(){
    var host = $('#live-teaser');if(!host)return;
    var generation=(host._liveGeneration||0)+1;host._liveGeneration=generation;
    liveApi('guide', '&hours=2&slim=1').then(function(g){
      if (!host.isConnected || host!==$('#live-teaser') || host._liveGeneration!==generation || !g || !g.channels) return;
      host.replaceChildren();if(!g.channels.length)return;
      if (!live.guide) live.guide = g; // slim data is enough for the first paint of cards
      host.appendChild(rowEl('On Watch Live now', sortLiveChannels(g.channels).slice(0, 16).map(function(ch){ return liveCardEl(ch, { compact:true }); }), {
        className:'row-live', more: function(){ goMode('live'); }, moreText:'Open guide'
      }));
    }).catch(function(){});
  }

  function viewLive(){
    var wrap = h('div', { id:'live-view', className:'live-view' }, [
      h('section', { className:'live-hero skeleton', id:'live-top' }, [
        h('div', { className:'live-hero-copy' }, [ h('div', { className:'eyebrow', text:'Watch Live' }), h('h1', { text:'Tuning in\u2026' }), pendingContent({ className:'muted', }) ])
      ])
    ]);
    setTimeout(function(){ loadLive(false); }, 0);
    return shell(wrap);
  }
  function loadLive(force){
    stopLiveTimers();
    ensureLiveData(force).then(paintLive).catch(function(e){
      var root = $('#live-view');
      if (!root) return;
      root.innerHTML = '';
      root.appendChild(emptyState('Watch Live is unavailable', 'Could not reach the Live API (' + String(e && e.message || e) + ').', [
        h('button', { className:'btn', text:'Retry', onClick: function(){ loadLive(true); } })
      ]));
    });
    live.refreshTimer = setInterval(function(){
      if (state.view !== 'live') { stopLiveTimers(); return; }
      if (document.hidden) return;
      ensureLiveData(true).then(function(){ if (state.view === 'live') paintLive(); }).catch(function(){});
    }, 60000);
    live.tickTimer = setInterval(tickLive, 10000);
  }
  function tickLive(){
    if (state.view !== 'live' && !$('#live-teaser')) return;
    var now = Date.now(), stale = false;
    $all('.lc-bar > span[data-s]').forEach(function(sp){
      var s = +sp.getAttribute('data-s'), e = +sp.getAttribute('data-e');
      if (!s || !e) return;
      if (now >= e) stale = true;
      sp.style.width = Math.max(0, Math.min(100, 100 * (now - s) / (e - s))) + '%';
    });
    $all('.lc-time[data-end] .lc-left').forEach(function(el){ el.textContent = fmtLeft(+el.parentNode.getAttribute('data-end')); });
    var gb = $('.g-body');
    if (gb) {
      var s0 = +gb.getAttribute('data-start'), e0 = +gb.getAttribute('data-end');
      gb.style.setProperty('--now', String(Math.max(0, Math.min(1, (now - s0) / (e0 - s0)))));
      if (now > s0 + 45 * 60000) stale = true;
    }
    var ck = $('#live-clock');
    if (ck) ck.textContent = fmtClock(now);
    if (stale && state.view === 'live' && now - live.lastPaint > 20000) {
      ensureLiveData(true).then(function(){ if (state.view === 'live') paintLive(); }).catch(function(){});
    }
  }
  function liveFilterMatch(ch){
    var q = String(live.filter || '').toLowerCase();
    if (!q) return true;
    var nn = progNowAndNext(ch);
    var hay = [ch.name, ch.number, ch.slogan, ch.section, nn.now && nn.now.title, nn.nxt && nn.nxt.title].join(' ').toLowerCase();
    return hay.indexOf(q) >= 0;
  }
  function paintLive(){
    var root = $('#live-view');
    if (!root) return;
    live.lastPaint = Date.now();
    var all = liveChannels();
    var chans = all.filter(liveFilterMatch);
    var relay = chans.filter(function(c){ return c.kind !== 'iptv'; });
    var iptv = chans.filter(function(c){ return c.kind === 'iptv'; });
    root.innerHTML = '';
    root.appendChild(liveHeroEl(relay.length ? relay : chans, all.length));
    if (live.filter) {
      root.appendChild(h('p', { className:'live-filter-note muted' }, [
        (chans.length + ' of ' + all.length + ' channels match \u201c' + live.filter + '\u201d. '),
        h('button', { className:'linkish', type:'button', text:'Clear filter', onClick: function(){ live.filter = ''; var b = $('#q'); if (b) b.value = ''; paintLive(); } })
      ]));
    }
    root.appendChild(h('section', { className:'live-section', id:'live-relay' }, [
      h('div', { className:'row-head' }, [ h('h2', { text:'Relay' }), h('span', { className:'meta', text:'PseudoTV from your library \u00b7 ' + relay.length + ' channel' + (relay.length === 1 ? '' : 's') }) ]),
      relay.length ? h('div', { className:'live-grid' }, relay.map(function(c){ return liveCardEl(c); }))
        : emptyState(live.filter ? 'No Relay channels match' : 'No Relay channels', live.filter ? '' : 'Create channels in Live settings.')
    ]));
    root.appendChild(h('section', { className:'live-section', id:'live-iptv' }, [
      h('div', { className:'row-head' }, [ h('h2', { text:'IPTV' }), h('span', { className:'meta', text:'Favourites from your M3U feeds \u00b7 ' + iptv.length }) ]),
      iptv.length ? h('div', { className:'live-grid' }, iptv.map(function(c){ return liveCardEl(c); }))
        : emptyState(live.filter ? 'No IPTV channels match' : 'No IPTV favourites yet',
            live.filter ? '' : 'Browse your playlist feeds and add favourites in Live settings \u2192 IPTV.',
            live.filter ? null : [ h('button', { className:'btn', type:'button', text:'Open Live settings', onClick: function(){ openSettings('live-channels'); } }) ])
    ]));
    root.appendChild(h('section', { className:'live-section', id:'live-guide' }, [
      h('div', { className:'row-head' }, [
        h('h2', { text:'Guide' }),
        h('span', { className:'meta', text: 'Relay schedule + IPTV XMLTV' + (live.guide && live.guide.epgUpdatedAt ? ' \u00b7 EPG ' + String(live.guide.epgUpdatedAt).slice(0, 16).replace('T', ' ') : '') })
      ]),
      guideEl(chans)
    ]));
    root.appendChild(h('div', { className:'live-foot muted' }, [
      'Relay plays MP4 straight from the server. IPTV is HLS through the same-origin proxy. ',
      h('a', { href: '#/settings/live-channels', text:'Live settings', onClick: function(e){ e.preventDefault(); openSettings('live-channels'); } })
    ]));
    tickLive();
  }
  function liveHeroEl(pool, total){
    var feat = null;
    for (var i = 0; i < pool.length; i++) {
      var nn0 = progNowAndNext(pool[i]);
      if (nn0.now && !isFiller(nn0.now) && (nn0.now.kind === 'movie' || nn0.now.kind === 'episode')) { feat = pool[i]; break; }
    }
    if (!feat) feat = pool[0] || null;
    var now = Date.now();
    var right = h('div', { className:'live-hero-side' }, [
      h('div', { className:'live-clock', id:'live-clock', text: fmtClock(now) }),
      h('div', { className:'muted', text: new Date(now).toLocaleDateString([], { weekday:'long', day:'numeric', month:'long' }) }),
      h('div', { className:'live-count', text: total + ' channel' + (total === 1 ? '' : 's') + ' live' })
    ]);
    if (!feat) {
      return h('section', { className:'live-hero', id:'live-top' }, [
        h('div', { className:'live-hero-copy' }, [ h('div', { className:'eyebrow', text:'Watch Live' }), h('h1', { text:'Nothing on air' }),
          h('p', { className:'muted', text:'Set up Relay channels or add IPTV favourites in Live settings.' }) ]),
        right
      ]);
    }
    var nn = progNowAndNext(feat);
    var p = nn.now;
    var meta = parseProgMeta(p);
    var pct = p ? Math.max(0, Math.min(100, 100 * (now - p.startUnix) / (p.endUnix - p.startUnix))) : 0;
    var isIptv = feat.kind === 'iptv';
    var headline = (isIptv && p && p.kind === 'live') ? (feat.name || 'Live') : (meta.showTitle || nn.label);
    var subline = '';
    if (!isIptv || !(p && p.kind === 'live')) {
      var bits = [];
      if (meta.episodeTitle) bits.push(meta.episodeTitle);
      var se = seasonEpisodeLabel(meta);
      if (se) bits.push(se);
      subline = bits.join(' · ');
    } else {
      subline = feat.slogan || 'Live stream';
    }
    var hero = h('section', { className:'live-hero' + (!isIptv && meta.posterId ? ' has-poster' : ''), id:'live-top' }, [
      (!isIptv && meta.posterId) ? livePosterEl(meta, 'live-hero-poster') : null,
      h('div', { className:'live-hero-copy' }, [
        h('div', { className:'eyebrow' }, [ h('span', { className:'live-dot' }), 'Live now · ' + feat.number + ' ' + (feat.name || '') ]),
        h('h1', { text: headline }),
        subline ? h('p', { className:'live-hero-ep', text: subline }) : null,
        p ? h('p', { className:'muted', text: fmtClock(p.startUnix) + ' \u2013 ' + fmtClock(p.endUnix) + ' \u00b7 ' + fmtLeft(p.endUnix) + (nn.nxt ? ' \u00b7 Next: ' + (parseProgMeta(nn.nxt).showTitle || nn.nxt.title) : '') }) : null,
        h('div', { className:'lc-bar hero-bar' }, [ h('span', { style:'width:' + pct + '%', 'data-s': p ? String(p.startUnix) : '', 'data-e': p ? String(p.endUnix) : '' }) ]),
        h('div', { className:'row-actions' }, [
          h('button', { className:'btn primary btn-lg', type:'button', onClick: function(){ openLivePlayer(feat.id); } }, [ icon('playFill'), h('span', { text:'Watch live' }) ]),
          h('button', { className:'btn glass btn-lg', type:'button', onClick: function(){ var g = $('#live-guide'); if (g) g.scrollIntoView({ behavior:'smooth', block:'start' }); } }, [ icon('guide'), h('span', { text:'Guide' }) ])
        ])
      ]),
      right
    ]);
    return hero;
  }

  function guideEl(chans, opts){
    opts = opts || {};
    var onPick = typeof opts.onPick === 'function' ? opts.onPick : null;
    if (!chans.length) return emptyState('Guide is empty', live.filter ? 'No channels match the filter.' : 'No channels are on air.');
    var now = Date.now();
    var HALF = 30 * 60000;
    var start = Math.floor((now - 10 * 60000) / HALF) * HALF;
    var end = start + 3 * 3600000;
    var span = end - start;
    var ticks = [];
    for (var t = start; t < end; t += HALF) {
      ticks.push(h('span', { className:'g-tick', style:'left:' + (100 * (t - start) / span) + '%', text: fmtClock(t) }));
    }
    var rows = chans.map(function(ch){
      var progs = (ch.programs && ch.programs.length) ? ch.programs : [ch.now, ch.next].filter(Boolean);
      var blocks = [];
      var segs = [], run = null;
      function flushRun(){
        if (!run) return;
        if (run.mains) {
          var best = '', bc = 0;
          Object.keys(run.titles).forEach(function(k){ if (run.titles[k] > bc) { bc = run.titles[k]; best = k; } });
          segs.push({ startUnix: run.s, endUnix: run.e, title: best + (run.mains > 1 ? ' \u00b7 ' + run.mains + ' eps' : ''), kind: 'run' });
        }
        run = null;
      }
      progs.forEach(function(p){
        if (!p || p.endUnix <= start || p.startUnix >= end) return;
        var big = ch.kind === 'iptv' || ((p.endUnix - p.startUnix) >= 180000 && !isFiller(p));
        if (big) { flushRun(); segs.push(p); return; }
        if (!run) run = { s: p.startUnix, e: p.endUnix, titles: {}, mains: 0 };
        run.e = p.endUnix;
        if (!isFiller(p)) {
          run.mains++;
          var base = String(p.title || '').split(/\s*[·•]\s*/)[0].replace(/\s*\d+\s*[\u00d7x]\s*\d+\s*$/, '').replace(/\s*S\d{1,2}E\d{1,3}\s*$/i, '').trim() || p.title;
          run.titles[base] = (run.titles[base] || 0) + 1;
        }
      });
      flushRun();
      segs.forEach(function(p){
        var s = Math.max(p.startUnix, start), e = Math.min(p.endUnix, end);
        if (e - s < 60000) return;
        var on = p.startUnix <= now && p.endUnix > now;
        var title = (ch.kind === 'iptv' && p.kind === 'live') ? (ch.name + ' \u00b7 live') : p.title;
        blocks.push(h('button', {
          className:'g-prog' + (on ? ' on' : '') + (p.endUnix <= now ? ' past' : ''), type:'button',
          style:'left:' + (100 * (s - start) / span) + '%;width:' + (100 * (e - s) / span) + '%',
          title: title + ' \u00b7 ' + fmtClock(p.startUnix) + '\u2013' + fmtClock(p.endUnix),
          onClick: function(){ if (on || p.endUnix > now) { if (onPick) onPick(ch.id); else openLivePlayer(ch.id); } }
        }, [ h('span', { className:'g-title', text: title }), h('span', { className:'g-time', text: fmtClock(p.startUnix) }) ]));
      });
      return h('div', { className:'g-row' }, [
        h('button', { className:'g-chan', type:'button', title:'Watch ' + ch.name, onClick: function(){ if (onPick) onPick(ch.id); else openLivePlayer(ch.id); } }, [
          h('span', { className:'g-num', text: String(ch.number) }),
          chanLogoEl(ch, 'g-logo'),
          h('span', { className:'g-name', text: ch.name || ('Channel ' + ch.number) })
        ]),
        h('div', { className:'g-track' }, blocks)
      ]);
    });
    var body = h('div', { className:'g-body', 'data-start': String(start), 'data-end': String(end), style:'--now:' + ((now - start) / span) }, [
      h('div', { className:'g-head' }, [ h('div', { className:'g-chan g-corner', text:'Today' }), h('div', { className:'g-ticks' }, ticks) ])
    ].concat(rows).concat([ h('div', { className:'g-nowline', 'aria-hidden':'true' }) ]));
    return h('div', { className:'guide-scroll' }, [ body ]);
  }

  /* In-shell live player (Relay + IPTV) - same overlay family as VoD DirectPlay */
  var _liveCleanup = null;
  var _hlsLoading = null;
  function loadHls(){
    if (window.Hls) return Promise.resolve(window.Hls);
    if (_hlsLoading) return _hlsLoading;
    _hlsLoading = new Promise(function(resolve, reject){
      var s = document.createElement('script');
      s.src = window.VaultcastDependencies.hls;
      s.onload = function(){ window.Hls ? resolve(window.Hls) : reject(new Error('hls.js missing')); };
      s.onerror = function(){ _hlsLoading = null; reject(new Error('Could not load hls.js')); };
      document.head.appendChild(s);
    });
    return _hlsLoading;
  }
  function hlsJar(){
    try {
      var k = 'iptv_hls_jar', j = sessionStorage.getItem(k);
      if (!j || !/^[a-zA-Z0-9]{8,40}$/.test(j)) {
        j = (Math.random().toString(36).slice(2) + Date.now().toString(36)).replace(/[^a-zA-Z0-9]/g, '').slice(0, 32);
        sessionStorage.setItem(k, j);
      }
      return j;
    } catch (e) { return 'anon' + String(Date.now()); }
  }
  function consumePendingLiveChannel(){
    var id = state._openLiveCh;
    if (!id) return;
    state._openLiveCh = null;
    try {
      if (/\/live\/[^/]+$/.test(location.pathname)) history.replaceState(history.state || {}, '', BASE + 'live');
    } catch (e) {}
    openLivePlayer(id);
  }
  function openLivePlayer(chId){
    toast('Tuning\u2026', { ms: 1200 });
    ensureLiveData(false).then(function(){ startLivePlayer(chId); }).catch(function(e){ toast('Live unavailable: ' + String(e && e.message || e), { kind:'bad' }); });
  }
  function startLivePlayer(chId){
    closePlayer();
    var list = liveChannels();
    if (!list.length) { toast('No live channels'); return; }
    var idx = 0;
    for (var i = 0; i < list.length; i++) if (list[i].id === chId) { idx = i; break; }
    pushNav({ player:true, live: list[idx].id });

    var lp = { idx: idx, ch: null, block: null, hls: null, token: 0, errors: 0, idleT: null, tickT: null, guideOpen: false, epgOpen: false, keyH: null, mode: 'live', playAt: 0, historyMs: ((live.guide && live.guide.historyDays) || 7) * 86400000, skew: 0 };
    var video = h('video', { id:'player-media', autoplay:true, playsinline:true, preload:'auto' });
    video.setAttribute('webkit-playsinline', 'true');
    var elNum = h('span', { className:'lp-num' }), elName = h('span', { className:'lp-name' }), elPill = h('span', { className:'lc-pill' });
    var elTitle = h('h2', { className:'lp-title', text:'Tuning\u2026' }), elWhen = h('p', { className:'lp-when muted' }), elNext = h('p', { className:'lp-next muted' });
    var elEpg = h('div', { className:'lp-epg', 'aria-label':'This channel schedule' });
    var elFill = h('span'), elProg = h('div', { className:'lp-progress', title:'Seek in programme', role:'slider', 'aria-label':'Seek in programme' }, [ elFill ]), elMsg = h('p', { className:'lp-msg' }), elClock = h('span', { className:'lp-clock' });
    var btnPlay = h('button', { className:'lp-btn', type:'button', title:'Play / pause (Space)', 'aria-label':'Play or pause' }, [ icon('pause') ]);
    var btnMute = h('button', { className:'lp-btn', type:'button', title:'Mute (M)', 'aria-label':'Mute' }, [ icon('vol') ]);
    var vol = h('input', { className:'lp-vol', type:'range', min:'0', max:'1', step:'0.05', value:'1', 'aria-label':'Volume' });
    var btnBack15 = h('button', { className:'lp-btn lp-text', type:'button', title:'Rewind 15 seconds (\u2190)', 'aria-label':'Rewind 15 seconds' }, [ h('span', { text:'\u221215' }) ]);
    var btnFwd30 = h('button', { className:'lp-btn lp-text', type:'button', title:'Forward 30 seconds (\u2192)', 'aria-label':'Forward 30 seconds' }, [ h('span', { text:'+30' }) ]);
    var btnPrevProg = h('button', { className:'lp-btn lp-text', type:'button', title:'Previous programme', 'aria-label':'Previous programme' }, [ h('span', { text:'Prev' }) ]);
    var btnNextProg = h('button', { className:'lp-btn lp-text', type:'button', title:'Next programme', 'aria-label':'Next programme' }, [ h('span', { text:'Next' }) ]);
    var btnGoLive = h('button', { className:'lp-btn lp-text lp-golive', type:'button', title:'Jump to live (L)', 'aria-label':'Jump to live' }, [ h('span', { text:'Live' }) ]);
    var shiftCluster = h('div', { className:'lp-shift', 'aria-label':'Timeshift' }, [ btnBack15, btnFwd30, btnPrevProg, btnNextProg, btnGoLive ]);
    var guideList = h('div', { className:'lp-guide-list' });
    var guide = h('aside', { className:'lp-guide', 'aria-label':'Channels' }, [ h('div', { className:'lp-guide-head' }, [ h('strong', { text:'Channels' }), h('span', { className:'muted', text:'\u2191 \u2193 to zap' }) ]), guideList ]);
    var epgBody = h('div', { className:'lp-epg-full-body' });
    var epgFull = h('div', { className:'lp-epg-full', 'aria-label':'Full EPG', hidden: true }, [
      h('div', { className:'lp-epg-full-head' }, [
        h('div', {}, [ h('strong', { text:'Full EPG' }), h('span', { className:'muted', text:' Relay schedule + IPTV XMLTV · Esc to close' }) ]),
        h('button', { className:'lp-btn lp-text', type:'button', title:'Close full EPG (Esc)', text:'Close', onClick: function(){ toggleFullEpg(false); } })
      ]),
      epgBody
    ]);
    var overlay = h('div', { className:'player-overlay live-player', id:'player-overlay' }, [
      video,
      h('div', { className:'lp-tuning' }, [ h('div', { className:'spinner' }) ]),
      h('div', { className:'lp-chrome' }, [
        h('div', { className:'lp-top' }, [
          h('button', { className:'lp-btn lp-back', type:'button', title:'Back (Esc)', 'aria-label':'Close player', onClick: function(){ try { history.back(); } catch (e) { closePlayer(); } } }, [ icon('close') ]),
          h('div', { className:'lp-chan' }, [ elNum, elName, elPill ]),
          h('div', { className:'lp-spacer' }),
          shareButton(function(){ var c = liveChannels()[lp.idx] || list[lp.idx]; return 'live/' + encodeURIComponent(c ? c.id : ''); }, 'Copy link to this channel'),
          elClock
        ]),
        h('div', { className:'lp-bottom' }, [
          h('div', { className:'eyebrow', text:'Now playing' }),
          elTitle, elWhen,
          elProg,

          elNext,
          elEpg,
          h('div', { className:'lp-controls' }, [
            btnPlay, btnMute, vol,
            h('div', { className:'lp-spacer' }),
            h('button', { className:'lp-btn', type:'button', title:'Previous channel (\u2191)', 'aria-label':'Previous channel', onClick: function(){ zap(-1); } }, [ icon('chevU') ]),
            h('button', { className:'lp-btn', type:'button', title:'Next channel (\u2193)', 'aria-label':'Next channel', onClick: function(){ zap(1); } }, [ icon('chevD') ]),
            h('button', { className:'lp-btn', type:'button', title:'Channel list (G)', 'aria-label':'Channel list', onClick: function(){ toggleGuide(); } }, [ icon('guide') ]),
            h('button', { className:'lp-btn lp-text', type:'button', title:'Full EPG (E)', 'aria-label':'Full EPG', onClick: function(){ toggleFullEpg(); } }, [ icon('guide'), h('span', { text:'EPG' }) ]),
            shiftCluster,
            h('button', { className:'lp-btn', type:'button', title:'Fullscreen (F)', 'aria-label':'Fullscreen', onClick: function(){ toggleFs(); } }, [ icon('full') ])
          ]),
          elMsg
        ])
      ]),
      guide,
      epgFull
    ]);
    document.body.appendChild(overlay);

    function msg(t){ elMsg.textContent = t || ''; }
    function setTuning(on){ overlay.classList.toggle('tuning', !!on); }
    function safePlay(){ try { var pr = video.play(); if (pr && pr.catch) pr.catch(function(){ overlay.classList.add('needs-tap'); }); } catch (e) {} }
    function teardownStream(){
      lp.token++;
      if (lp.hls) { try { lp.hls.destroy(); } catch (e) {} lp.hls = null; }
      try { video.pause(); } catch (e2) {}
      try { video.removeAttribute('src'); video.load(); } catch (e3) {}
      video.onloadedmetadata = null;
      video.oncanplay = null;
    }
    function paintGuideList(){
      guideList.innerHTML = '';
      list.forEach(function(c, i){
        var nn = progNowAndNext(c);
        guideList.appendChild(h('button', { className:'lp-guide-item' + (i === lp.idx ? ' active' : ''), type:'button', onClick: function(){ tune(i); } }, [
          h('span', { className:'g-num', text: String(c.number) }),
          h('span', { className:'lp-gi-text' }, [ h('strong', { text: c.name || ('Channel ' + c.number) }), h('span', { className:'muted', text: (c.kind === 'iptv' && nn.now && nn.now.kind === 'live') ? 'IPTV \u00b7 live' : nn.label }) ])
        ]));
      });
    }
    function toggleGuide(force){
      lp.guideOpen = force != null ? !!force : !lp.guideOpen;
      if (lp.guideOpen && lp.epgOpen) toggleFullEpg(false);
      overlay.classList.toggle('guide-open', lp.guideOpen);
      if (lp.guideOpen) { paintGuideList(); var a = guideList.querySelector('.active'); if (a) try { a.scrollIntoView({ block:'center' }); a.focus(); } catch (e) {} }
    }
    function paintChannelEpg(){
      var c = lp.ch;
      elEpg.innerHTML = '';
      if (!c) return;
      var items = channelEpgItems(c, 4);
      if (!items.length) {
        elEpg.appendChild(h('div', { className:'lp-epg-empty muted', text: c.kind === 'iptv' ? 'No XMLTV listings for this channel' : 'No upcoming programmes on this channel' }));
        return;
      }
      var now = Date.now();
      var rows = items.map(function(p, i){
        var on = p.startUnix <= now && p.endUnix > now;
        var meta = parseProgMeta(p);
        var label = channelEpgLabel(p, c);
        var when = fmtClock(p.startUnix) + '\u2013' + fmtClock(p.endUnix);
        var left = on ? fmtLeft(p.endUnix) : ('from ' + fmtClock(p.startUnix));
        var desc = (p.subtitle && String(p.subtitle).trim() && String(p.subtitle).trim() !== label) ? String(p.subtitle).trim() : '';
        if (desc.length > 110) desc = desc.slice(0, 107) + '\u2026';
        return h('div', { className:'lp-epg-row' + (on ? ' on' : '') }, [
          h('span', { className:'lp-epg-tag', text: on ? 'Now' : (i === 0 ? 'Next' : 'Later') }),
          h('div', { className:'lp-epg-main' }, [
            h('strong', { text: label }),
            desc ? h('span', { className:'lp-epg-desc muted', text: desc }) : null,
            h('span', { className:'lp-epg-when muted', text: when + ' \u00b7 ' + left })
          ])
        ]);
      });
      elEpg.appendChild(h('div', { className:'lp-epg-head' }, [
        h('span', { className:'lp-epg-label', text:'On this channel' })
      ]));
      elEpg.appendChild(h('div', { className:'lp-epg-rows' }, rows));
    }
    function paintFullEpg(){
      epgBody.innerHTML = '';
      var chans = liveChannels();
      var panel = guideEl(chans, { onPick: function(id){
        toggleFullEpg(false);
        var i = 0;
        for (var n = 0; n < list.length; n++) if (list[n].id === id) { i = n; break; }
        tune(i);
      }});
      epgBody.appendChild(panel);
      // scroll active channel row into view
      try {
        var cur = lp.ch && lp.ch.id;
        if (cur) {
          var rows = epgBody.querySelectorAll('.g-row');
          for (var r = 0; r < rows.length; r++) {
            var btn = rows[r].querySelector('.g-chan');
            if (btn && btn.getAttribute('title') && lp.ch && btn.getAttribute('title').indexOf(lp.ch.name) >= 0) {
              rows[r].scrollIntoView({ block:'center' });
              break;
            }
          }
        }
      } catch (e) {}
    }
    function toggleFullEpg(force){
      lp.epgOpen = force != null ? !!force : !lp.epgOpen;
      if (lp.epgOpen && lp.guideOpen) toggleGuide(false);
      overlay.classList.toggle('epg-open', lp.epgOpen);
      if (lp.epgOpen) {
        epgFull.hidden = false;
        paintFullEpg();
        // refresh guide in background so listings stay current
        ensureLiveData(false).then(function(){ if (lp.epgOpen) paintFullEpg(); }).catch(function(){});
      } else {
        epgFull.hidden = true;
        epgBody.innerHTML = '';
      }
      wake();
    }
    function toggleFs(){
      try {
        if (document.fullscreenElement) document.exitFullscreen();
        else if (overlay.requestFullscreen) overlay.requestFullscreen();
      } catch (e) {}
    }
    function paintShiftPill(){
      var c = lp.ch;
      if (!c) return;
      if (c.kind === 'iptv') {
        elPill.textContent = 'IPTV';
        elPill.className = 'lc-pill iptv';
        shiftCluster.style.display = 'none';
        elProg.classList.remove('seekable');
        return;
      }
      shiftCluster.style.display = '';
      elProg.classList.add('seekable');
      var atLive = lp.mode === 'live' || !lp.playAt || (relayNow() - lp.playAt < 2000);
      elPill.textContent = atLive ? 'LIVE' : 'TIMESHIFT';
      elPill.className = 'lc-pill' + (atLive ? '' : ' shift');
      btnGoLive.classList.toggle('on', !atLive);
      btnGoLive.disabled = !!atLive;
    }
    function paintHeader(){
      var c = lp.ch;
      elNum.textContent = String(c.number);
      elName.textContent = c.name || ('Channel ' + c.number);
      paintShiftPill();
    }
    function paintNow(b){
      var c = lp.ch;
      if (c.kind === 'iptv') {
        var nn = progNowAndNext(c);
        var p = nn.now;
        var isPh = !p || p.kind === 'live';
        elTitle.textContent = isPh ? (c.name || 'Live') : p.title;
        elWhen.textContent = isPh ? ((c.slogan || 'Live stream') + ' \u00b7 HLS') : (fmtClock(p.startUnix) + ' \u2013 ' + fmtClock(p.endUnix));
        elNext.textContent = nn.nxt && nn.nxt.kind !== 'live' ? ('Up next \u00b7 ' + nn.nxt.title + (nn.nxt.startUnix ? (' \u00b7 ' + fmtClock(nn.nxt.startUnix)) : '')) : '';
        elFill.style.width = isPh ? '100%' : (Math.max(0, Math.min(100, 100 * (Date.now() - p.startUnix) / (p.endUnix - p.startUnix))) + '%');
        paintChannelEpg();
        return;
      }
      if (!b) return;
      elTitle.textContent = b.title || 'On air';
      elWhen.textContent = fmtClock(b.startUnix) + ' \u2013 ' + fmtClock(b.endUnix) + (b.subtitle ? ' \u00b7 ' + b.subtitle : '');
      var nxt = null, progs = c.programs || [];
      for (var i = 0; i < progs.length; i++) { if (progs[i].startUnix >= b.endUnix - 1000 && !isFiller(progs[i])) { nxt = progs[i]; break; } }
      elNext.textContent = nxt ? ('Up next \u00b7 ' + nxt.title + ' \u00b7 ' + fmtClock(nxt.startUnix)) : '';
      paintChannelEpg();
    }
    /* Relay joins at the live edge. Seek target = server seekMs (position inside the file,
       incl. mediaOffsetMs for programmes split by a rolling slot) + time since the server answered,
       so the server clock decides (TVs with a wrong clock still join mid-show). Re-seeks on canplay
       when the metadata-time seek was ignored; hands over to the next programme when the file is
       shorter than its slot. */
    function relayNow(){ return Date.now() + (lp.skew || 0); }
    function historyClamp(t){
      var now = relayNow(), lo = now - (lp.historyMs || 7 * 86400000);
      if (t < lo) return lo;
      if (t > now) return now;
      return t;
    }
    function currentPlayAt(){
      if (lp.mode === 'live' || !lp.playAt) return relayNow();
      if (lp.block && lp.ch && lp.ch.kind !== 'iptv') {
        var mo = +lp.block.mediaOffsetMs || 0, ct = video.currentTime || 0;
        if (ct) return lp.block.startUnix + Math.max(0, ct * 1000 - mo);
      }
      return lp.playAt;
    }
    function goLive(){
      lp.mode = 'live';
      lp.playAt = 0;
      teardownStream();
      tuneRelay(0);
      paintShiftPill();
      wake();
    }
    function shiftTo(atMs){
      atMs = historyClamp(atMs);
      if (relayNow() - atMs < 1500) { goLive(); return; }
      lp.mode = 'shift';
      lp.playAt = atMs;
      teardownStream();
      tuneRelay(atMs);
      paintShiftPill();
      wake();
    }
    function skipMs(delta){ shiftTo(currentPlayAt() + delta); }
    function skipProg(dir){
      if (!lp.block || (lp.ch && lp.ch.kind === 'iptv')) return;
      if (dir < 0) {
        var into = currentPlayAt() - lp.block.startUnix;
        if (into > 8000) shiftTo(lp.block.startUnix);
        else shiftTo(lp.block.startUnix - 500);
      } else {
        shiftTo(lp.block.endUnix + 20);
      }
    }
    function relayNext(b){
      lp.token++;
      lp.block = null;
      var nextAt = b.endUnix + 20;
      if (lp.mode === 'shift' && nextAt < relayNow() - 1500) tuneRelay(nextAt);
      else { lp.mode = 'live'; lp.playAt = 0; tuneRelay(0); }
    }
    function tuneRelay(at){
      var tok = lp.token;
      var c = lp.ch;
      if (at && at > 0) { lp.mode = 'shift'; lp.playAt = at; }
      else { lp.mode = 'live'; lp.playAt = 0; }
      setTuning(true);
      var asked = Date.now();
      liveApi('now', '&channel=' + encodeURIComponent(c.id) + (at ? '&at=' + Math.round(at) : '')).then(function(b){
        if (tok !== lp.token) return;
        if (!b || !b.url) { setTuning(false); elTitle.textContent = 'Off air'; msg('This channel has nothing scheduled right now.'); return; }
        var got = Date.now(), half = Math.max(0, got - asked) / 2;
        if (b.serverNow) lp.skew = (+b.serverNow + half) - got;
        var mOff = +b.mediaOffsetMs || 0;
        var baseMs = (b.seekMs != null) ? +b.seekMs : (mOff + Math.max(0, (at || relayNow()) - b.startUnix));
        var baseAt = got - half;
        lp.block = b;
        paintNow(b);
        paintShiftPill();
        function target(){ return Math.max(0, (baseMs + (Date.now() - baseAt)) / 1000); }
        var seeks = 0;
        function seekLive(){
          if (tok !== lp.token) return;
          var off = target();
          try {
            var d = video.duration;
            if (d && isFinite(d) && d > 1 && off >= d - 1) { relayNext(b); return; }
            if (off > 1 && Math.abs((video.currentTime || 0) - off) > 2 && seeks < 4) { seeks++; video.currentTime = off; }
          } catch (e) {}
        }
        video.onloadedmetadata = function(){ if (tok !== lp.token) return; seekLive(); if (tok === lp.token) safePlay(); };
        video.oncanplay = function(){ if (tok !== lp.token) return; seekLive(); };
        video.src = streamPlayUrl(liveUrl(b.url));
        safePlay();
      }).catch(function(e){ if (tok === lp.token) { setTuning(false); msg('Tune failed: ' + String(e && e.message || e)); } });
    }
    function tuneIptv(){
      var tok = lp.token;
      var c = lp.ch;
      var src = iptvSource(c);
      paintNow(null);
      if (!src || !src.url) { msg('Channel is not in the IPTV lineup any more.'); return; }
      setTuning(true);
      var url = src.url;
      var isHls = /\.m3u8(\?|$)/i.test(url);
      if (!isHls) { video.src = url; safePlay(); msg('Non-HLS stream: browser DirectPlay attempted.'); return; }
      var purl = LIVE_API + 'iptv_hls?u=' + encodeURIComponent(url) + '&jar=' + encodeURIComponent(hlsJar());
      if (src.userAgent) purl += '&ua=' + encodeURIComponent(src.userAgent);
      if (src.referrer) purl += '&ref=' + encodeURIComponent(src.referrer);
      loadHls().then(function(Hls){
        if (tok !== lp.token) return;
        if (Hls && Hls.isSupported()) {
          var hls = new Hls({ enableWorker: true, lowLatencyMode: false });
          lp.hls = hls;
          var recovered = false;
          hls.on(Hls.Events.MANIFEST_PARSED, function(){ safePlay(); });
          hls.on(Hls.Events.ERROR, function(_e, data){
            if (!data || !data.fatal || tok !== lp.token) return;
            if (data.type === Hls.ErrorTypes.MEDIA_ERROR && !recovered) { recovered = true; try { hls.recoverMediaError(); return; } catch (e) {} }
            setTuning(false);
            msg('Stream error (' + (data.type || '?') + (data.details ? ' / ' + data.details : '') + '). It may be offline, geo-blocked, or its CDN is blocked on this connection.');
          });
          hls.loadSource(purl);
          hls.attachMedia(video);
        } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
          video.src = purl; safePlay();
        } else {
          setTuning(false); msg('This browser cannot play HLS. Try another device or VLC.');
        }
      }).catch(function(){
        if (tok !== lp.token) return;
        if (video.canPlayType('application/vnd.apple.mpegurl')) { video.src = purl; safePlay(); }
        else { setTuning(false); msg('HLS player failed to load.'); }
      });
    }
    function tune(i){
      var n = list.length;
      lp.idx = ((i % n) + n) % n;
      lp.ch = list[lp.idx];
      lp.errors = 0;
      lp.block = null;
      lp.mode = 'live';
      lp.playAt = 0;
      teardownStream();
      msg('');
      overlay.classList.remove('needs-tap');
      paintHeader();
      elTitle.textContent = 'Tuning\u2026'; elWhen.textContent = ''; elNext.textContent = ''; elFill.style.width = '0%';
      elEpg.innerHTML = '';
      if (lp.guideOpen) paintGuideList();
      if (lp.epgOpen) paintFullEpg();
      try { history.replaceState(Object.assign({}, history.state || {}, { live: lp.ch.id }), '', navUrl({ live: lp.ch.id })); } catch (e) {}
      if (lp.ch.kind === 'iptv') tuneIptv(); else tuneRelay(0);
      wake();
    }
    function zap(d){ tune(lp.idx + d); }

    video.addEventListener('playing', function(){ setTuning(false); overlay.classList.remove('needs-tap'); lp.errors = 0; btnPlay.innerHTML = ICONS.pause; });
    video.addEventListener('pause', function(){ btnPlay.innerHTML = ICONS.playFill; });
    video.addEventListener('waiting', function(){ setTuning(true); });
    video.addEventListener('ended', function(){
      if (lp.ch && lp.ch.kind !== 'iptv' && lp.block) relayNext(lp.block);
    });
    video.addEventListener('error', function(){
      if (!lp.ch || !video.getAttribute('src') && !lp.hls) return;
      if (lp.ch.kind !== 'iptv' && lp.block) {
        lp.errors++;
        if (lp.errors > 5) { setTuning(false); msg('Several programmes failed to play on this channel. Try another channel.'); return; }
        var skipTo = lp.block.endUnix + 50;
        lp.token++;
        setTimeout(function(){ tuneRelay(skipTo); }, 600);
        return;
      }
      if (!lp.hls) { setTuning(false); msg('Playback failed. The stream may be offline or unsupported here.'); }
    });
    btnPlay.addEventListener('click', function(){ if (video.paused) safePlay(); else video.pause(); });
    btnBack15.addEventListener('click', function(){ skipMs(-15000); });
    btnFwd30.addEventListener('click', function(){ skipMs(30000); });
    btnPrevProg.addEventListener('click', function(){ skipProg(-1); });
    btnNextProg.addEventListener('click', function(){ skipProg(1); });
    btnGoLive.addEventListener('click', function(){ goLive(); });
    elProg.addEventListener('click', function(e){
      if (!lp.block || !lp.ch || lp.ch.kind === 'iptv') return;
      var rect = elProg.getBoundingClientRect();
      if (!rect.width) return;
      var pct = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
      shiftTo(lp.block.startUnix + pct * lp.block.durationMs);
    });
    video.addEventListener('pause', function(){
      if (!lp.ch || lp.ch.kind === 'iptv') return;
      if (lp.mode === 'live') {
        lp.mode = 'shift';
        lp.playAt = currentPlayAt();
        paintShiftPill();
      }
    });
    btnMute.addEventListener('click', function(){ video.muted = !video.muted; btnMute.innerHTML = video.muted ? ICONS.mute : ICONS.vol; });
    vol.addEventListener('input', function(){ video.volume = +vol.value; video.muted = +vol.value === 0; btnMute.innerHTML = video.muted ? ICONS.mute : ICONS.vol; });
    overlay.addEventListener('click', function(e){
      if (e.target === video || e.target.classList.contains('lp-chrome')) {
        if (overlay.classList.contains('needs-tap') || video.paused) safePlay();
        if (lp.guideOpen) toggleGuide(false);
        if (lp.epgOpen) toggleFullEpg(false);
      }
    });

    function wake(){
      overlay.classList.remove('idle');
      clearTimeout(lp.idleT);
      lp.idleT = setTimeout(function(){ if (!lp.guideOpen && !lp.epgOpen && !video.paused) overlay.classList.add('idle'); }, 3500);
    }
    overlay.addEventListener('mousemove', wake);
    overlay.addEventListener('touchstart', wake, { passive:true });
    lp.keyH = function(e){
      if (!document.getElementById('player-overlay')) return;
      var tag = (e.target && e.target.tagName || '').toLowerCase();
      if (tag === 'input' && e.target.type !== 'range') return;
      var k = e.key;
      if (k === 'ArrowUp' || k === 'PageUp' || k === 'ChannelUp') { e.preventDefault(); if (lp.guideOpen) return; zap(-1); }
      else if (k === 'ArrowDown' || k === 'PageDown' || k === 'ChannelDown') { e.preventDefault(); if (lp.guideOpen) return; zap(1); }
      else if (k === ' ' && tag !== 'button') { e.preventDefault(); if (video.paused) safePlay(); else video.pause(); }
      else if (k === 'ArrowLeft') { e.preventDefault(); if (lp.ch && lp.ch.kind !== 'iptv') skipMs(-15000); }
      else if (k === 'ArrowRight') { e.preventDefault(); if (lp.ch && lp.ch.kind !== 'iptv') skipMs(30000); }
      else if (k === 'l' || k === 'L') { if (lp.ch && lp.ch.kind !== 'iptv') goLive(); }
      else if (k === 'g' || k === 'G') toggleGuide();
      else if (k === 'e' || k === 'E') toggleFullEpg();
      else if (k === 'f' || k === 'F') toggleFs();
      else if (k === 'm' || k === 'M') btnMute.click();
      else if (k === 'Escape') { if (lp.epgOpen) toggleFullEpg(false); else if (lp.guideOpen) toggleGuide(false); else { try { history.back(); } catch (e2) { closePlayer(); } } }
      wake();
    };
    document.addEventListener('keydown', lp.keyH);
    lp.tickT = setInterval(function(){
      elClock.textContent = fmtClock(Date.now());
      var c = lp.ch;
      if (!c) return;
      if (c.kind !== 'iptv' && lp.block) {
        var b = lp.block, mo = +b.mediaOffsetMs || 0, ct = video.currentTime || 0;
        var pos = ct ? (ct * 1000 - mo) : ((lp.mode === 'shift' && lp.playAt) ? (lp.playAt - b.startUnix) : (relayNow() - b.startUnix));
        elFill.style.width = Math.max(0, Math.min(100, 100 * pos / b.durationMs)) + '%';
        if (lp.mode === 'shift' && ct) lp.playAt = b.startUnix + Math.max(0, ct * 1000 - mo);
        // programme cut short by a rolling slot - leave the file at the slot boundary
        if (!video.paused && ct && ct * 1000 >= mo + b.durationMs - 250) relayNext(b);
        // Drifted well past the scheduled block while LIVE (stalled tab etc): resync to live
        else if (lp.mode === 'live' && relayNow() > b.endUnix + 15000 && !video.paused && ct * 1000 < mo + b.durationMs - 30000) { lp.token++; lp.block = null; tuneRelay(0); }
        paintShiftPill();
      } else if (c.kind === 'iptv') {
        paintNow(null);
      }
      // refresh remaining labels on the channel EPG strip about every 15s
      if ((Date.now() / 1000 | 0) % 15 === 0) paintChannelEpg();
    }, 1000);

    _liveCleanup = function(){
      lp.token++;
      clearInterval(lp.tickT); clearTimeout(lp.idleT);
      if (lp.keyH) document.removeEventListener('keydown', lp.keyH);
      if (lp.hls) { try { lp.hls.destroy(); } catch (e) {} lp.hls = null; }
      try { if (document.fullscreenElement) document.exitFullscreen(); } catch (e2) {}
    };
    elClock.textContent = fmtClock(Date.now());
    tune(idx);
  }



  function azLettersList(){
    var letters = ['#'];
    for (var i = 0; i < 26; i++) letters.push(String.fromCharCode(65 + i));
    return letters;
  }

  function titleStartLetter(title){
    var t = String(title || '').replace(/^\s+/, '');
    if (!t) return '#';
    var ch = t.charAt(0).toUpperCase();
    if (ch >= 'A' && ch <= 'Z') return ch;
    return '#';
  }

  function buildAzRail(id, lettersMap, opts){
    opts = opts || {};
    var active = (opts.active != null ? opts.active : (state.sectionLetter || '')).toUpperCase();
    var onPick = opts.onPick || function(letter){ selectSectionLetter(id, letter); };
    var aria = opts.ariaLabel || 'Alphabet index';
    var kids = [
      h('button', {
        type: 'button',
        className: 'az-btn az-all' + (!active ? ' active' : ''),
        title: 'Show all',
        'aria-label': 'Show all',
        text: 'All',
        onClick: function(){ onPick(''); }
      })
    ];
    azLettersList().forEach(function(L){
      var count = lettersMap && typeof lettersMap[L] === 'number' ? lettersMap[L] : null;
      var empty = count === 0;
      kids.push(h('button', {
        type: 'button',
        className: 'az-btn' + (active === L ? ' active' : '') + (empty ? ' empty' : ''),
        title: empty ? (L + ' - none') : ('Jump to ' + L),
        'aria-label': 'Jump to ' + L,
        'aria-disabled': empty ? 'true' : 'false',
        text: L,
        onClick: function(){
          if (empty) return;
          onPick(active === L ? '' : L);
        }
      }));
    });
    return h('nav', {
      className: 'az-rail',
      id: 'az-rail',
      role: 'navigation',
      'aria-label': aria
    }, kids);
  }

  function selectSectionLetter(id, letter){
    state.sectionLetter = letter || '';
    state.sectionQ = '';
    loadSection(id, '', { letter: state.sectionLetter });
    try { window.scrollTo(0, 0); } catch (e) {}
  }

  var azScrollTimer = null;
  function azOnScroll(){
    document.body.classList.add('az-scrolling');
    clearTimeout(azScrollTimer);
    azScrollTimer = setTimeout(function(){ document.body.classList.remove('az-scrolling'); }, 1200);
  }
  function unmountAzRail(){
    var old = document.getElementById('az-rail');
    if (old && old.parentNode) old.parentNode.removeChild(old);
    var tg = document.getElementById('az-toggle');
    if (tg && tg.parentNode) tg.parentNode.removeChild(tg);
    window.removeEventListener('scroll', azOnScroll);
    document.body.classList.remove('az-scrolling', 'az-open');
    var sv = document.getElementById('section-view');
    if (sv) sv.classList.remove('has-az-rail');
    try { document.body.classList.remove('has-az-rail'); } catch (eAz) {}
  }

  function mountAzRail(root, id, lettersMap, opts){
    if (!root) return;
    unmountAzRail();
    var rail = buildAzRail(id, lettersMap, opts);
    if (!rail) return;
    root.classList.add('has-az-rail');
    try { document.body.classList.add('has-az-rail'); } catch (eAz2) {}
    /* Mount on body so position:fixed stays viewport-sticky while library grids scroll
       (ancestors with transform/filter/animation would otherwise trap fixed).
       The rail sits in a reserved left gutter: .has-az-rail pads the content, so it never covers posters. */
    if (!rail.id) rail.id = 'az-rail';
    document.body.appendChild(rail);
    window.addEventListener('scroll', azOnScroll, { passive: true });
    /* Narrow screens: rail is hidden behind a compact toggle and opens as a popover. */
    var active = (opts && opts.active != null ? opts.active : (state.sectionLetter || '')).toUpperCase();
    var toggle = h('button', { id:'az-toggle', type:'button', className:'az-toggle', 'aria-controls':'az-rail', 'aria-expanded':'false',
      'aria-label':'Jump to letter', text: active || 'A–Z', onClick: function(){
        var open = !document.body.classList.contains('az-open');
        document.body.classList.toggle('az-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      }});
    rail.addEventListener('click', function(e){ if (e.target && e.target.classList && e.target.classList.contains('az-btn')) { document.body.classList.remove('az-open'); toggle.setAttribute('aria-expanded', 'false'); } });
    document.body.appendChild(toggle);
  }

  function viewSection(onReady){
    state.sectionLetter = state.sectionLetter || '';
    var wrap = h('div', { id:'section-view' }, [ h('div', { className:'hero' }, [ h('h1', { text: state.sectionLabel || state.section }), pendingContent({ className:'muted', }) ]) ]);
    loadSection(state.section, state.sectionQ || '', { root:wrap, onReady:onReady });
    return shell(wrap);
  }

  function viewShow(onReady){
    var wrap = h('div', { id:'show-view' }, [ h('div', { className:'hero' }, [ h('h1', { text:'TV show' }), pendingContent() ]) ]);
    loadShow(state.showKey, wrap, onReady);
    return shell(wrap);
  }

  function detailMatchButtons(item, showKey){
    function withTools(callback){
      window.__nsEnsureMatching(function(){ if (window.NSMatch) callback(window.NSMatch); });
    }
    var buttons = [h('button', { className:'btn ghost nsm-report-btn', type:'button', text:'⚑ Report', onClick:function(){
      withTools(function(tools){ tools.reportDialog(showKey
        ? {kind:'show', show_key:showKey, label:item.title || ''}
        : {kind:'movie', item_id:item.id, label:(item.title || '') + (item.year ? ' (' + item.year + ')' : '')}); });
    }})];
    if (can('fix_match')) buttons.push(h('button', { className:'btn ghost', type:'button', text:'Fix match', onClick:function(){
      if (!can('fix_match')) return;
      withTools(function(tools){
        if (!can('fix_match')) return;
        if (showKey) { tools.openShowFix(showKey,function(result){openShow(result&&result.show_key||showKey);}); }
        else tools.openMovieFix(item);
      });
    }}));
    if (can('metadata_edit')) buttons.push(h('button', {className:'btn ghost', type:'button', text:'Manual edit', onClick:function(){
      withTools(function(tools){ if (can('metadata_edit')) tools.editDialog(item, showKey); });
    }}));
    return buttons;
  }

  function openShow(showKey, season, episode){
    if (state.showKey !== showKey) state.showSlug = null;
    state.showKey = showKey;
    state.showSeason = season != null ? season : null;
    state.showEpisode = episode != null ? episode : null;
    state.view = 'show';
    render();
  }

  function openMovie(itemId){
    state._pendingEpisode = null;
    if (state.movieId !== itemId) state.movieSlug = null;
    state.movieId = itemId;
    state.view = 'movie';
    render();
  }

  function openGame(item, multiplayer){
    state._pendingEpisode = null;
    state.gameItem = item;
    state.movieId = item.id;
    state.gamePath = item.game_path || null;
    state.gameLobby = multiplayer || null;
    state.view = 'game';
    render();
  }

  var _movieLoadSeq = 0;

  function viewMovie(){
    var wrap = h('div', { id:'movie-view' }, [ h('div', { className:'hero' }, [ h('h1', { text:state.view === 'game' ? 'Game' : 'Movie' }), pendingContent() ]) ]);
    loadMovie(state.movieId);
    return shell(wrap);
  }

  function loadMovie(itemId){
    var seq = ++_movieLoadSeq;
    var isGame = state.view === 'game';
    function stillHere(){
      return seq === _movieLoadSeq && state.view === (isGame ? 'game' : 'movie') && String(state.movieId) === String(itemId);
    }
    function paintError(msg){
      if (!stillHere()) return;
      var root = $('#movie-view');
      if (!root) return;
      root.innerHTML = '';
      root.appendChild(h('div', { className:'hero' }, [
        h('h1', { text: msg || 'Movie not found' }),
        h('button', { className:'btn ghost', text:'← Back', onClick: function(){ state.view='home'; render(); } })
      ]));
    }
    // Instant: MariaDB item + disk-cached synopsis only (never blocks on Wikipedia).
    api('/items/'+encodeURIComponent(itemId)+'?synopsis=1').then(function(j){
      if (!stillHere()) return;
      var root = $('#movie-view');
      if (!root) return;
      if (!j || !j.ok || !j.item) { paintError(isGame ? 'Game not found' : 'Movie not found'); return; }
      var item = j.item;
      if (isGame) { state.gameItem = item; state.gamePath = item.game_path || state.gamePath; replaceNav(); }
      else if (item.slug && stillHere() && state.movieSlug !== item.slug) { state.movieSlug = item.slug; replaceNav(); }
      root.innerHTML = '';
      var poster = h('div', { className:'poster poster-pending movie-hero-poster', 'data-poster-fetch':'1', 'data-backdrop-src':'1', 'data-poster-variant':'full' }, [
        h('div', { className:'ph', text: item.title || 'Movie' })
      ]);
      var actions = [
        h('button', { className:'btn ghost', text:'← Back', onClick: function(){
          if (item.section) navigate('section', item.section);
          else { state.view='home'; render(); }
        }}),
        shareButton(function(){ return isGame ? routePath() : 'movies/' + encodeURIComponent(item.slug || item.id); }, isGame ? 'Copy link to this game' : 'Copy link to this movie'),
        h('button', { className:'btn', text:'Find alternate', onClick: function(){
          openAlternateFinder({
            title: item.title || '',
            endpoint: '/items/'+encodeURIComponent(item.id)+'/alternate',
            body: { kind: 'movie', section_id: item.section || 'movies' }
          });
        }})
      ];
      if (!canSearchRss()) actions = actions.filter(function(button){ return button.textContent !== 'Find alternate'; });
      actions = actions.concat(detailMatchButtons(item, null));
      if (isGame) actions = actions.filter(function(button){ return button.textContent !== 'Find alternate'; });
      if (item.file_exists === false) {
        actions.push(h('span', { className:'bad', text:'File missing on disk' }));
      }
      // Audio metadata was prepared by the facts worker; opening a page never probes media.
      Promise.resolve(item).then(function(pr){
        if (!stillHere() || !pr || !pr.multi_audio) return;
        var box = $('#movie-audio-box');
        if (!box) return;
        box.innerHTML = '';
        box.appendChild(h('label', { text:'Preferred audio track ' }));
        var sel = h('select', { onChange: function(e){ setAudioPref(item.id, parseInt(e.target.value,10)); toast('Audio preference saved'); } });
        var pref = getAudioPref(item.id);
        (pr.audio_tracks||[]).forEach(function(t,i){
          var lab = '#'+(t.relative!=null?t.relative:i)+' '+(t.language||'')+' '+(t.title||t.codec||'');
          var o = h('option', { value:String(t.relative!=null?t.relative:i), text: lab.trim() });
          if (pref!=null && (pref===t.relative || pref===i)) o.setAttribute('selected','');
          sel.appendChild(o);
        });
        box.appendChild(sel);
        box.appendChild(h('span', { className:'muted', text:' Stored locally; applied on DirectPlay when browser allows.' }));
      }).catch(function(){ /* probe is optional */ });
      var meta = [item.system, item.year, item.ext, item.size ? fmtSize(item.size) : null, item.synopsis_source].filter(Boolean);
      var synopsisEl = item.synopsis
        ? h('p', { className:'synopsis', id:'movie-synopsis', text: item.synopsis })
        : h('p', { className:'muted', id:'movie-synopsis', text: 'No synopsis cached yet.' });
      var playerHost = h('div', {id:'detail-player', className:'detail-player'});
      root.appendChild(playerHost);
      var multiplayer = isGame ? state.gameLobby : null;
      if (multiplayer) mountLobbyBoard(root, multiplayer);
      root.appendChild(h('div', { className:'hero detail-hero', 'data-backdrop-host':'1', style:'display:flex;gap:20px;align-items:flex-end;flex-wrap:wrap' }, [
        poster,
        h('div', { style:'flex:1;min-width:200px' }, [
          h('div', { className:'row-actions', style:'margin-bottom:8px' }, actions),
          h('h1', { text: item.title || 'Untitled' }),
          h('p', { className:'muted', text: meta.join(' · ') }),
          synopsisEl,
          h('div', { id:'movie-audio-box', className:'row-actions', style:'margin-top:8px' })
        ])
      ]));
      if (item.id) {
        poster.setAttribute('data-poster-fetch', '1');
        observePoster(poster, item.id, 'item');
        loadPosterNow(poster, item.id, 'item');
      }
      window.VaultcastHub.details(item,root);
      if (!isGame) {
        root.appendChild(h('div', { id:'movie-collections' }));
        mountMovieCollections(item.id);
      }
      if (item.file_exists !== false && !(multiplayer && multiplayer.preview)) playItem(item, {inline:true, autoplay:false, lobby:multiplayer ? multiplayer.options : null});
    }).catch(function(e){
      paintError(String(e && e.message || e || 'Could not load movie'));
    });
  }

  function mountMovieCollections(itemId){
    var host = $('#movie-collections');
    if (!host || !itemId) return;
    api('/collections/for-item/'+encodeURIComponent(itemId)).then(function(r){
      host = $('#movie-collections');
      if (!host) return;
      host.innerHTML = '';
      var cols = (r && r.ok && r.collections) ? r.collections : [];
      if (!cols.length) return;
      cols.forEach(function(col){
        var cards = [];
        (col.parts || []).forEach(function(p){
          if (!p || !p.present || !p.item) return;
          if (p.current || String(p.item_id||'') === String(itemId)) return;
          cards.push(cardEl(p.item));
        });
        if (!cards.length) return;
        host.appendChild(rowEl(col.name || 'More in this collection', cards, {
          className: 'row-collection-siblings',
          meta: String(cards.length),
          more: function(){ state.collectionId = col.id; state.view = 'collection'; render(); },
          moreText: 'Open collection'
        }));
      });
    }).catch(function(){ /* optional enrichment */ });
  }



  /* VoD section Home-style showcase billboard (Discover / Recently Updated) + poster row */
  function sectionShowcaseKind(id){
    var t = state.sectionType;
    if (!t) {
      var sec = (state.home && state.home.sections || []).find(function(s){ return s.id === id; });
      t = sec ? sec.type : null;
    }
    if (t === 'movies' || t === 'disney') return 'discover';
    if (t === 'tv' || t === 'anime') return 'recent';
    return null;
  }

  function sectionShowcaseEyebrow(kind, feat){
    if (kind === 'discover') return 'Discover';
    if (feat && feat.showcase_label) return feat.showcase_label;
    return 'Recently Updated';
  }

  function sectionShowcaseBillboard(list, kind, footText){
    stopBillboardCycle();
    var pool = (list || []).filter(function(it){ return it && it.media_kind !== 'rom'; });
    if (!pool.length) return null;
    var feat = pool[0];
    var eyebrow = sectionShowcaseEyebrow(kind, feat);
    var resume = feat.progress || null;
    var isShow = isShowItem(feat);
    var poster = h('div', { className:'poster poster-pending billboard-poster', 'data-backdrop-src':'1', 'data-poster-variant':'full' }, [ h('div', { className:'ph', text: cleanTitle(feat) }) ]);
    var meta = [];
    if (feat.show && feat.season != null) meta.push(epCode(feat.season, feat.episode) + ' \u00b7 ' + episodeDisplayTitle(feat));
    if (feat.year) meta.push(String(feat.year));
    if (feat.showcase_label && kind === 'recent' && meta.indexOf(feat.showcase_label) < 0) meta.unshift(feat.showcase_label);
    if (resume && resume.duration) {
      var left = Math.max(0, Math.round((resume.duration - resume.position) / 60));
      meta.push(left + ' min left');
    }
    var actions = [
      h('button', { className:'btn primary btn-lg', type:'button', onClick: function(){ playItem(feat); } }, [ icon('playFill'), h('span', { text: resume ? 'Resume' : 'Play' }) ])
    ];
    if (isShow || feat.show_key || feat.type === 'movies' || feat.type === 'disney' || feat.media_kind === 'video') {
      actions.push(h('button', { className:'btn glass btn-lg', type:'button', onClick: function(){
        if (feat.show_key) openShow(feat.show_key); else if (isShow) openShow(feat.id); else openMovie(feat.id);
      } }, [ icon('info'), h('span', { text:'More info' }) ]));
    }
    var bb = h('section', { className:'billboard billboard-section billboard-section-' + kind, 'data-backdrop-host':'1' }, [
      h('div', { className:'billboard-bg' }),
      h('div', { className:'billboard-inner' }, [
        h('div', { className:'billboard-copy' }, [
          h('div', { className:'eyebrow', text: eyebrow }),
          h('h1', { text: cleanTitle(feat) }),
          h('p', { className:'billboard-meta', text: meta.length ? meta.join(' \u00b7 ') : '', hidden: meta.length ? null : true }),
          h('div', { className:'billboard-progress', hidden: !(resume && resume.duration) }, [ h('span', { style:'width:' + (resume && resume.duration ? Math.min(100, Math.round(100 * resume.position / resume.duration)) : 0) + '%' }) ]),
          h('div', { className:'row-actions' }, actions),
          h('p', { className:'billboard-foot muted', text: footText || '' })
        ]),
        poster
      ])
    ]);
    var pid = posterIdFor(feat);
    if (pid) {
      if (posterNeedsRemote(feat)) poster.setAttribute('data-poster-fetch', '1');
      setTimeout(function(){ observePoster(poster, pid, posterKindFor(feat)); }, 0);
    }
    try { bb.setAttribute('data-bb-id', String(feat.id || feat.show_key || '')); } catch (eId) {}
    /* Match Home CW: hard 5s auto-rotate through showcase pool */
    var BILLBOARD_CW_MS = 5000;
    if (pool.length > 1) {
      var idx = 0;
      state._billboardTimer = setInterval(function(){
        if (!bb.isConnected) { stopBillboardCycle(); return; }
        idx = (idx + 1) % pool.length;
        var next = pool[idx];
        billboardPaintFeat(bb, state.home || {}, next, sectionShowcaseEyebrow(kind, next));
        var foot = bb.querySelector('.billboard-foot');
        if (foot && footText) foot.textContent = footText;
      }, BILLBOARD_CW_MS);
    }
    return bb;
  }

  function mountSectionShowcase(root, id, opts){
    if (!root || !id) return;
    opts = opts || {};
    var kind = sectionShowcaseKind(id);
    if (!kind) return;
    stopBillboardCycle();
    var old = root.querySelector('#section-showcase');
    if (old && old.parentNode) old.parentNode.removeChild(old);
    var host = h('div', { id: 'section-showcase', className: 'section-showcase section-showcase-billboard' });
    /* Top of section — Home-parity billboard sits above A–Z grid (rail is body-fixed) */
    root.insertBefore(host, root.firstChild);

    var n = 16;
    var path = kind === 'recent'
      ? '/sections/' + encodeURIComponent(id) + '/recent?n=' + n
      : '/sections/' + encodeURIComponent(id) + '/random?n=' + n;
    var title = kind === 'recent' ? 'Recently Updated' : 'Discover';
    var reqToken = String(Date.now()) + '-' + id + '-' + kind;
    host.setAttribute('data-req', reqToken);
    host.setAttribute('data-kind', kind);
    host.appendChild(h('section', { className: 'billboard billboard-section billboard-section-loading' }, [
      h('div', { className:'billboard-inner' }, [
        h('div', { className:'billboard-copy' }, [
          h('div', { className:'eyebrow', text: title }),
          h('h1', { text: state.sectionLabel || id }),
          pendingContent({ className:'muted', })
        ])
      ])
    ]));

    var footText = opts.footText || '';
    (opts.request || api(path)).then(function(j){
      if (!host.isConnected || host.getAttribute('data-req') !== reqToken) return;
      host.innerHTML = '';
      var list = (j && j.ok && j.items) ? j.items : [];
      if (!list.length) {
        host.appendChild(sectionHeroEl(id, footText || ((opts.asShows ? 'shows' : 'titles'))));
        return;
      }
      if (kind === 'discover') {
        list.forEach(function(it){
          if (!it) return;
          if (!it.showcase_label) {
            it.showcase_kind = 'discover';
            it.showcase_label = 'Discover';
          }
        });
      }
      var bb = sectionShowcaseBillboard(list, kind, footText || (title + ' \u00b7 ' + list.length));
      if (bb) host.appendChild(bb);
      host.appendChild(rowEl(title, list.map(function(it){ return cardEl(it); }), {
        className: 'row-showcase row-showcase-' + kind,
        meta: String(list.length)
      }));
    }).catch(function(){
      if (!host.isConnected || host.getAttribute('data-req') !== reqToken) return;
      host.innerHTML = '';
      host.appendChild(sectionHeroEl(id, footText || 'Library'));
    });
  }

  function sectionIsShowType(id){
    if (state.sectionType === 'tv' || state.sectionType === 'anime') return true;
    var sec = (state.home && state.home.sections || []).find(function(s){ return s.id===id; });
    if (sec && (sec.type === 'tv' || sec.type === 'anime')) return true;
    return false;
  }



  /* Movies/Disney + TV/Anime glass filter row under showcase */
  function sectionIsMovieType(id){
    if (state.sectionType === 'movies' || state.sectionType === 'disney') return true;
    var sec = (state.home && state.home.sections || []).find(function(s){ return s.id===id; });
    if (sec && (sec.type === 'movies' || sec.type === 'disney' || sec.id === 'disney' || sec.id === 'movies')) return true;
    if (id === 'movies' || id === 'disney') return true;
    return false;
  }
  function sectionWantsVodFilters(id){
    return sectionIsMovieType(id) || sectionIsShowType(id);
  }
  function defaultSectionFilters(){
    return { genre:'', year:'', decade:'', actor:'', status:'', network:'' };
  }
  function sectionFiltersActive(){
    var f = state.sectionFilters || defaultSectionFilters();
    return !!(f.genre || f.year || f.decade || f.actor || f.status || f.network);
  }
  function appendSectionFilterParams(path){
    var f = state.sectionFilters || defaultSectionFilters();
    if (f.genre) path += '&genre=' + encodeURIComponent(f.genre);
    if (f.year) path += '&year=' + encodeURIComponent(f.year);
    if (f.decade) path += '&decade=' + encodeURIComponent(f.decade);
    if (f.actor) path += '&actor=' + encodeURIComponent(f.actor);
    if (f.status) path += '&status=' + encodeURIComponent(f.status);
    if (f.network) path += '&network=' + encodeURIComponent(f.network);
    return path;
  }
  function selectVodFilter(id, key, value){
    if (!state.sectionFilters) state.sectionFilters = defaultSectionFilters();
    var cur = state.sectionFilters[key] || '';
    var next = (value && cur === value) ? '' : (value || '');
    state.sectionFilters[key] = next;
    // year vs decade mutually exclusive
    if (key === 'year' && next) state.sectionFilters.decade = '';
    if (key === 'decade' && next) state.sectionFilters.year = '';
    state.sectionLetter = '';
    state.sectionPage = 1;
    loadSection(id, state.sectionQ || '', { filters: true });
  }
  function clearVodFilters(id){
    state.sectionFilters = defaultSectionFilters();
    state.vodFilterDim = '';
    state.sectionPage = 1;
    loadSection(id, state.sectionQ || '', { filters: true });
  }
  function facetEntries(map, limit){
    var entries = [];
    if (!map || typeof map !== 'object') return entries;
    Object.keys(map).forEach(function(k){
      var n = +map[k] || 0;
      if (k && n > 0) entries.push({ name: k, count: n });
    });
    if (limit && entries.length > limit) entries = entries.slice(0, limit);
    return entries;
  }
  function mountVodFilter(root, id, facets){
    var old = root.querySelector('#vod-library-filter');
    if (old) old.remove();
    if (!sectionWantsVodFilters(id)) return;
    facets = facets || state._sectionFacets || {};
    if (!state.sectionFilters) state.sectionFilters = defaultSectionFilters();
    var f = state.sectionFilters;
    var isMovie = sectionIsMovieType(id);
    var dims = isMovie
      ? [
          { key: 'genre', label: 'Genre', map: facets.genres },
          { key: 'year', label: 'Year', map: facets.decades || facets.years, yearMode: true },
          { key: 'actor', label: 'Actor', actor: true }
        ]
      : [
          { key: 'genre', label: 'Genre', map: facets.genres },
          { key: 'year', label: 'Year', map: facets.decades || facets.years, yearMode: true },
          { key: 'status', label: 'Status', map: facets.statuses },
          { key: 'network', label: 'Network', map: facets.networks }
        ];
    // Drop empty dimensions (except actor search + any active value)
    dims = dims.filter(function(d){
      if (d.actor) return true;
      if (f[d.key]) return true;
      if (d.yearMode && (f.year || f.decade)) return true;
      var ents = facetEntries(d.map, 0);
      return ents.length > 0;
    });
    if (!dims.length && !sectionFiltersActive()) return;

    var openDim = state.vodFilterDim || '';
    var headKids = [
      h('div', { className:'vod-filter-label muted', text:'Search by' })
    ];
    dims.forEach(function(d){
      var activeVal = d.actor ? (f.actor || '') : (d.yearMode ? (f.decade || f.year || '') : (f[d.key] || ''));
      var isOpen = openDim === d.key;
      headKids.push(h('button', {
        type:'button',
        className: 'vod-dim-btn' + (isOpen ? ' open' : '') + (activeVal ? ' has-value' : ''),
        title: d.label,
        onClick: function(){
          state.vodFilterDim = isOpen ? '' : d.key;
          mountVodFilter(root, id, state._sectionFacets || facets);
        }
      }, [
        h('span', { text: d.label }),
        activeVal ? h('span', { className:'vod-dim-val', text: '· ' + activeVal }) : null
      ]));
    });
    if (sectionFiltersActive()) {
      headKids.push(h('button', {
        type:'button',
        className:'vod-filter-clear',
        text:'Clear filters',
        onClick: function(){ clearVodFilters(id); }
      }));
    }

    var panelKids = [];
    var dim = dims.find(function(d){ return d.key === openDim; });
    if (dim) {
      if (dim.actor) {
        var actorInput = h('input', {
          type:'search',
          placeholder:'Actor name (searches synopsis cache)…',
          value: f.actor || '',
          'aria-label':'Search by actor',
          onKeydown: function(e){
            if (e.key === 'Enter') {
              e.preventDefault();
              selectVodFilter(id, 'actor', (e.target.value || '').trim());
            }
          }
        });
        panelKids.push(h('div', { className:'vod-actor-form' }, [
          actorInput,
          h('button', {
            type:'button',
            text:'Apply',
            onClick: function(){
              var v = (actorInput.value || '').trim();
              selectVodFilter(id, 'actor', v);
            }
          }),
          f.actor ? h('button', {
            type:'button',
            className:'vod-chip',
            text:'Clear actor',
            onClick: function(){ selectVodFilter(id, 'actor', ''); }
          }) : null
        ]));
      } else if (dim.yearMode) {
        var decadeEntries = facetEntries(facets.decades, 16);
        var yearEntries = facetEntries(facets.years, 24);
        panelKids.push(h('button', {
          type:'button',
          className: 'vod-chip' + (!(f.decade || f.year) ? ' active' : ''),
          text:'All years',
          onClick: function(){
            state.sectionFilters.year = '';
            state.sectionFilters.decade = '';
            state.sectionLetter = '';
            loadSection(id, state.sectionQ || '', { filters: true });
          }
        }));
        decadeEntries.forEach(function(e){
          panelKids.push(h('button', {
            type:'button',
            className: 'vod-chip' + (f.decade === e.name ? ' active' : ''),
            title: e.name + ' (' + e.count + ')',
            text: e.name + ' · ' + e.count,
            onClick: function(){ selectVodFilter(id, 'decade', e.name); }
          }));
        });
        // compact recent years if few decades
        if (decadeEntries.length < 3) {
          yearEntries.forEach(function(e){
            panelKids.push(h('button', {
              type:'button',
              className: 'vod-chip' + (f.year === e.name ? ' active' : ''),
              title: e.name + ' (' + e.count + ')',
              text: e.name + ' · ' + e.count,
              onClick: function(){ selectVodFilter(id, 'year', e.name); }
            }));
          });
        }
      } else {
        var entries = facetEntries(dim.map, 36);
        panelKids.push(h('button', {
          type:'button',
          className: 'vod-chip' + (!f[dim.key] ? ' active' : ''),
          text: 'All',
          onClick: function(){ selectVodFilter(id, dim.key, ''); }
        }));
        entries.forEach(function(e){
          panelKids.push(h('button', {
            type:'button',
            className: 'vod-chip' + (f[dim.key] === e.name ? ' active' : ''),
            title: e.name + ' (' + e.count + ')',
            text: e.name + ' · ' + e.count,
            onClick: function(){ selectVodFilter(id, dim.key, e.name); }
          }));
        });
      }
    }

    var barKids = [ h('div', { className:'vod-filter-head' }, headKids) ];
    if (panelKids.length) {
      barKids.push(h('div', { className:'vod-filter-panel', role:'group', 'aria-label': (dim && dim.label) || 'Filter options' }, panelKids));
    }
    var bar = h('div', {
      id:'vod-library-filter',
      className:'vod-filter glass-panel',
      role:'toolbar',
      'aria-label':'Library filters'
    }, barKids);

    var showcase = root.querySelector('#section-showcase');
    var hero = root.querySelector('.section-hero, .play-hero');
    var consoleBar = root.querySelector('#play-console-filter');
    var anchor = consoleBar || showcase || hero;
    if (anchor && anchor.parentNode === root) {
      if (anchor.nextSibling) root.insertBefore(bar, anchor.nextSibling);
      else root.appendChild(bar);
    } else {
      root.insertBefore(bar, root.firstChild);
    }
  }



  function mountConsoleFilter(root, id, systemsMap){
    var old = root.querySelector('#play-console-filter');
    if (old) old.remove();
    if (state.sectionType !== 'roms' && state.view !== 'play') return;
    var entries = [];
    if (systemsMap && typeof systemsMap === 'object') {
      Object.keys(systemsMap).forEach(function(k){
        var n = +systemsMap[k] || 0;
        if (k) entries.push({ name: k, count: n });
      });
    }
    if (!entries.length && !(state.sectionSystem)) return;
    entries.sort(function(a, b){ return a.name.localeCompare(b.name); });
    var active = state.sectionSystem || '';
    var totalAll = entries.reduce(function(s, e){ return s + e.count; }, 0);
    var chips = [
      h('button', {
        type:'button',
        className: 'console-chip' + (!active ? ' active' : ''),
        text: 'All' + (totalAll ? (' · ' + totalAll) : ''),
        onClick: function(){ selectConsoleSystem(id, ''); }
      })
    ];
    entries.forEach(function(e){
      chips.push(h('button', {
        type:'button',
        className: 'console-chip' + (active === e.name ? ' active' : ''),
        title: e.name + ' (' + e.count + ')',
        text: e.name + ' · ' + e.count,
        onClick: function(){ selectConsoleSystem(id, active === e.name ? '' : e.name); }
      }));
    });
    var bar = h('div', { id:'play-console-filter', className:'console-filter glass-panel', role:'toolbar', 'aria-label':'Filter by console' }, [
      h('div', { className:'console-filter-label muted', text:'Console' }),
      h('div', { className:'console-chip-row' }, chips)
    ]);
    // Insert under showcase if present, else under hero, else at top
    var showcase = root.querySelector('#section-showcase');
    var hero = root.querySelector('.section-hero, .play-hero');
    if (showcase && showcase.parentNode === root) {
      if (showcase.nextSibling) root.insertBefore(bar, showcase.nextSibling);
      else root.appendChild(bar);
    } else if (hero && hero.parentNode === root) {
      if (hero.nextSibling) root.insertBefore(bar, hero.nextSibling);
      else root.appendChild(bar);
    } else {
      root.insertBefore(bar, root.firstChild);
    }
  }
  function selectConsoleSystem(id, system){
    state.sectionSystem = system || '';
    state.sectionSystemSlug = system ? ((state._systemSlugs || {})[system] || null) : null;
    state.sectionLetter = '';
    state.sectionPage = 1;
    loadSection(id, state.sectionQ || '', { system: state.sectionSystem });
  }

  var _sectionLoadSeq = 0;
  function loadSection(id, q, opts){
    opts = opts || {};
    var append = !!opts.append;
    state.section = id;
    if (!append) {
      state.sectionPage = 1;
      state.sectionQ = q || '';
      if (opts.letter != null) state.sectionLetter = opts.letter || '';
      syncUrl();
    }
    var page = append ? (state.sectionPage || 1) : 1;
    var asShows = sectionIsShowType(id);
    var per = asShows ? 48 : 48;
    var letter = (state.sectionLetter || '').toUpperCase();
    if (opts.system != null) state.sectionSystem = opts.system || '';
    var path = asShows
      ? '/sections/'+encodeURIComponent(id)+'/shows?per='+per+'&page='+page+'&q='+encodeURIComponent(state.sectionQ||'')
      : '/sections/'+encodeURIComponent(id)+'/items?per='+per+'&page='+page+'&q='+encodeURIComponent(state.sectionQ||'');
    if (letter) path += '&letter=' + encodeURIComponent(letter);
    if (!asShows && state.sectionSystem) path += '&system=' + encodeURIComponent(state.sectionSystem);
    path = appendSectionFilterParams(path);
    if (append) state.sectionLoadingMore = true;
    var showcaseRequest = !append && !letter && sectionShowcaseKind(id)
      ? api('/sections/' + encodeURIComponent(id) + '/' + (sectionShowcaseKind(id) === 'recent' ? 'recent' : 'random') + '?n=16').catch(function(){ return null; })
      : null;
    var seq = ++_sectionLoadSeq;
    function stillHere(){ return seq === _sectionLoadSeq && state.section === id && (state.view === 'section' || state.view === 'play'); }

    function paintSectionPayload(j){
      state.sectionLoadingMore = false;
      if (j && j.system_slugs) {
        state._systemSlugs = Object.assign(state._systemSlugs || {}, j.system_slugs);
        if (state.sectionSystem && !state.sectionSystemSlug && j.system_slugs[state.sectionSystem]) { state.sectionSystemSlug = j.system_slugs[state.sectionSystem]; replaceNav(); }
        else if (state.view === 'play' && state.sectionSystem) syncUrl();
      }
      var root = (opts.root || $('#section-view'));
      if (!root) return;
      var total = j.total||0;
      var list = asShows ? (j.shows||[]) : (j.items||[]);
      var perGot = j.per || per;
      var curPage = j.page || page;
      var shownBefore = append ? (((curPage-1)*perGot)) : 0;
      var shown = shownBefore + list.length;
      var hasMore = shown < total;
      state.sectionPage = curPage;
      state._sectionHasMore = hasMore;
      state._sectionTotal = total;
      state._sectionAsShows = asShows;

      if (!append) {
        root.innerHTML = '';
        var letter2 = (state.sectionLetter || '').toUpperCase();
        var label = asShows ? (total+' shows') : (total+' titles');
        if (letter2) label = label + ' ' + String.fromCharCode(0x00B7) + ' ' + letter2;
        var heroLabel = label + (list.length && total > list.length ? (' ' + String.fromCharCode(0x00B7) + ' showing '+list.length) : '');
        var showcaseKind = letter ? null : sectionShowcaseKind(id);
        /* billboard replaces the thin section-hero on Movies/Disney/TV/Anime */
        if (!showcaseKind) root.appendChild(sectionHeroEl(id, heroLabel));
        if (j.letters) state._sectionLetters = j.letters;
        mountAzRail(root, id, j.letters || state._sectionLetters || null);
        if (showcaseKind) mountSectionShowcase(root, id, { footText: heroLabel, asShows: asShows, total: total, request: showcaseRequest });
        if (j.systems) state._sectionSystems = j.systems;
        mountConsoleFilter(root, id, j.systems || state._sectionSystems || null);
        if (j.facets) state._sectionFacets = j.facets;
        mountVodFilter(root, id, j.facets || state._sectionFacets || null);
        if (!list.length) {
          var clearBtns = [];
          if (letter2) clearBtns.push(h('button', { className:'btn', text:'Clear letter filter', onClick: function(){ selectSectionLetter(id, ''); } }));
          if (state.sectionQ) clearBtns.push(h('button', { className:'btn', text:'Clear search', onClick: function(){ state.sectionLetter=''; state.sectionSystem=''; state.sectionFilters=defaultSectionFilters(); loadSection(id, ''); var box=$('#q'); if(box) box.value=''; } }));
          if (state.sectionSystem) clearBtns.push(h('button', { className:'btn', text:'Clear console filter', onClick: function(){ selectConsoleSystem(id, ''); } }));
          if (sectionFiltersActive()) clearBtns.push(h('button', { className:'btn', text:'Clear library filters', onClick: function(){ clearVodFilters(id); } }));
          root.appendChild(emptyState(
            (state.sectionQ || letter2 || sectionFiltersActive()) ? 'No matches' : 'This library is empty',
            letter2 ? ('No titles starting with "'+letter2+'".') : (sectionFiltersActive() ? 'Nothing matched the selected filters.' : ((state.sectionQ) ? ('Nothing matched "'+state.sectionQ+'" in the catalogue.') : 'Run Admin -> Scan after adding files.')),
            clearBtns.length ? clearBtns : null
          ));
          return;
        }
        root.appendChild(h('div', { className:'grid section-grid', id:'section-grid' }, list.map(function(it){
          var el = cardEl(it);
          try { el.setAttribute('data-letter', titleStartLetter(it.title)); } catch (eDL) {}
          return el;
        })));
      } else {
        var grid = $('#section-grid');
        if (!grid) return;
        if (j.letters) state._sectionLetters = j.letters;
        if (j.facets) state._sectionFacets = j.facets;
        list.forEach(function(it){
          var el = cardEl(it);
          try { el.setAttribute('data-letter', titleStartLetter(it.title)); } catch (eDL2) {}
          grid.appendChild(el);
        });
        var heroP = root.querySelector('.section-hero p');
        var shownLabel = (asShows ? (total+' shows') : (total+' titles')) + ' ?? showing '+shown;
        if (heroP) heroP.textContent = shownLabel;
        var bbFoot = root.querySelector('#section-showcase .billboard-foot');
        if (bbFoot) bbFoot.textContent = shownLabel;
      }

      var foot = $('#section-pager');
      if (foot) foot.remove();
      if (hasMore || shown > 0) {
        foot = h('div', { className:'section-pager', id:'section-pager' }, [
          h('p', { className:'muted pager-status', text: 'Showing '+shown+' of '+total }),
          hasMore ? h('button', {
            className:'btn primary load-more-btn',
            id:'section-load-more',
            text: state.sectionLoadingMore ? 'Please wait…' : 'Load more',
            onClick: function(){
              if (state.sectionLoadingMore) return;
              state.sectionPage = (state.sectionPage||1) + 1;
              loadSection(id, state.sectionQ, { append:true });
            }
          }) : h('span', { className:'muted', text:'End of catalogue' })
        ]);
        root.appendChild(foot);
      }
      setupSectionInfiniteScroll(id);
    }

    api(path).then(function(j){
      if (!stillHere()) return;
      if (!j || !j.ok) {
        state.sectionLoadingMore = false;
        if (opts.onReady) opts.onReady();
        if (!append) {
          var rootFail = (opts.root || $('#section-view'));
          if (rootFail && !rootFail.querySelector('.section-grid, .empty-state, .billboard-section')) {
            rootFail.innerHTML = '';
            rootFail.appendChild(h('div', { className:'hero' }, [
              h('h1', { text: state.sectionLabel || state.section || 'Library' }),
              h('p', { className:'bad', text: (j && j.error) || 'Could not load this library.' }),
              h('button', { className:'btn', text:'Try again', onClick: function(){ loadSection(id, state.sectionQ || ''); } })
            ]));
          }
        }
        toast((j && j.error) || 'Library load failed', { kind:'bad' });
        return;
      }
      if (opts.onReady) opts.onReady();
      paintSectionPayload(j);
    }).catch(function(e){
      if (!stillHere()) return;
      state.sectionLoadingMore = false;
      if (opts.onReady) opts.onReady();
      if (!append) {
        var root = (opts.root || $('#section-view'));
        if (root && !root.querySelector('.section-grid, .empty-state, .billboard-section')) {
          root.innerHTML = '';
          root.appendChild(h('div', { className:'hero' }, [
            h('h1', { text: state.sectionLabel || state.section || 'Library' }),
            h('p', { className:'bad', text: String(e && e.message || e || 'Could not load this library.') }),
            h('button', { className:'btn', text:'Try again', onClick: function(){ loadSection(id, state.sectionQ || ''); } })
          ]));
        }
      }
      toast(String(e), { kind:'bad' });
    });
  }

  function setupSectionInfiniteScroll(id){
    if (state._sectionScrollBound) return;
    state._sectionScrollBound = true;
    window.addEventListener('scroll', function(){
      if (state.view !== 'section' && state.view !== 'play') return;
      if (!state._sectionHasMore || state.sectionLoadingMore) return;
      var foot = $('#section-pager');
      if (!foot) return;
      var rect = foot.getBoundingClientRect();
      var vh = window.innerHeight || 800;
      if (rect.top < vh + 160) {
        state.sectionPage = (state.sectionPage||1) + 1;
        loadSection(state.section, state.sectionQ, { append:true });
      }
    }, { passive:true });
  }

  function epCode(season, episode){
    function pad(n){ n=String(n==null?'':n); return n.length>=2?n:('0'+n).slice(-2); }
    if (season == null || episode == null) return '';
    return 'S'+pad(season)+'E'+pad(episode);
  }

  function episodeDisplayTitle(ep){
    if (!ep) return 'Episode';
    if (ep.episode_title) {
      var et = String(ep.episode_title).trim();
      if (et && !/\b(?:1080p|720p|WEB-?DL|x265|x264|DSNP)\b/i.test(et)) return et;
    }
    var title = ep.title || 'Episode';
    if (ep.show && title.indexOf(ep.show) === 0) {
      title = title.replace(ep.show, '').replace(/^\s*[—\-–.?·|:]+\s*/, '').trim();
    }
    title = title.replace(/^S\d{1,2}E\d{1,3}\s*[—\-–.?·|:]*\s*/i, '').trim();
    title = title.replace(/\s*\((?:\d{3,4}p|WEB|BluRay|HDTV|UHD|HDR|DSNP|AMZN|NF)[^)]*\)\s*$/i, '').trim();
    title = title.replace(/\b(?:\d{3,4}p|WEB-?DL|WEBRip|BluRay|HDTV|x264|x265|H\.?264|H\.?265|HEVC|DDP?5\.?1|AAC|DSNP|AMZN|MeM\.?GP)\b.*/i, '').trim();
    if (/\./.test(title) && /\b(?:1080p|WEB|BluRay|DSNP)\b/i.test(title)) {
      title = title.replace(/[._]/g, ' ').replace(/\s+/g, ' ').trim();
      title = title.replace(/\b(?:\d{3,4}p|WEB-?DL|WEBRip|BluRay|HDTV|x264|x265|ITA|ENG|DSNP|AMZN)\b.*/i, '').trim();
    }
    title = title.replace(/[._]+/g, ' ').replace(/\s+/g, ' ').trim();
    if (ep.show && title.toLowerCase() === String(ep.show).toLowerCase()) title = '';
    return title || (epCode(ep.season, ep.episode) || 'Episode');
  }

  function fetchEpisode(showKey, season, episode, btn){
    if (btn) { btn.disabled = true; btn.textContent = 'Searching…'; }
    var path = '/shows/'+encodeURIComponent(showKey)+'/episodes/'+season+'/'+episode+'/fetch';
    var key = 'ep:'+showKey+':'+season+':'+episode;
    state.abortControllers[key] = true;
    api(path, { method:'POST', body:{} }).then(function(r){
      delete state.abortControllers[key];
      if (btn) { btn.disabled = false; btn.textContent = 'Get'; }
      if (!r.ok) return toast(r.reason || r.error || 'Fetch failed', { kind:'bad' });
      if (r.skipped) return toast('Already available');
      var msg = r.matched
        ? ('Queued: ' + r.matched)
        : ('Queued search: ' + (r.query || epCode(season, episode)));
      if (r.reason && !r.matched) msg += ' — ' + r.reason;
      toast(msg, { kind:'ok' });
      loadDownloadStatus(true);
    }).catch(function(e){
      delete state.abortControllers[key];
      if (btn) { btn.disabled = false; btn.textContent = 'Get'; }
      toast(String(e), { kind:'bad' });
    });
  }

  function cancelEpisodeFetch(showKey, season, episode, showTitle, btn){
    if (btn) { btn.disabled = true; btn.textContent = 'Cancelling…'; }
    api('/shows/'+encodeURIComponent(showKey)+'/episodes/'+season+'/'+episode+'/cancel', { method:'POST', body:{} }).then(function(r){
      if (btn) { btn.disabled = false; btn.textContent = 'Cancel'; }
      if (!r.ok) return toast(r.error || 'Cancel failed', { kind:'bad' });
      toast('Cancelled '+(r.count||0)+' download'+(r.count===1?'':'s'), { kind:'ok' });
      loadDownloadStatus(true).then(function(){ if (state.view==='show') loadShow(showKey); });
    }).catch(function(e){
      if (btn) { btn.disabled = false; btn.textContent = 'Cancel'; }
      toast(String(e), { kind:'bad' });
    });
  }

  function fetchMissingShow(showKey, season){
    confirmDialog('Queue RSS search for all missing episodes'+(season!=null?(' in season '+season):'')+'?', { okText:'Fetch missing' }).then(function(ok){
      if (!ok) return;
      var body = {};
      if (season != null) body.season = season;
      toast('Queueing…');
      api('/shows/'+encodeURIComponent(showKey)+'/fetch-missing', { method:'POST', body: body }).then(function(r){
        if (!r.ok) return toast(r.reason || r.error || 'Fetch failed', { kind:'bad' });
        toast('Queued '+(r.count||0)+' missing episode'+(r.count===1?'':'s'), { kind:'ok' });
      });
    });
  }

  function mountShowCollections(show, seasons){
    var host = $('#show-collections');
    if (!host || !show) return;
    /* Show pages already list seasons/episodes (the rest of the show). Optionally
       surface a franchise collection rail when an episode item is linked into one. */
    var probeId = show.sample_item_id || show.item_id || null;
    if (!probeId) {
      var ss = seasons || [];
      for (var si = 0; si < ss.length && !probeId; si++) {
        var eps = ss[si].episodes || [];
        for (var ei = 0; ei < eps.length; ei++) {
          if (eps[ei] && eps[ei].id && eps[ei].available !== false && eps[ei].status !== 'missing') {
            probeId = eps[ei].id; break;
          }
        }
      }
    }
    if (!probeId) return;
    api('/collections/for-item/'+encodeURIComponent(probeId)).then(function(r){
      host = $('#show-collections');
      if (!host) return;
      host.innerHTML = '';
      var cols = (r && r.ok && r.collections) ? r.collections : [];
      cols.forEach(function(col){
        var cards = [];
        (col.parts || []).forEach(function(p){
          if (!p || !p.present || !p.item) return;
          if (p.current) return;
          cards.push(cardEl(p.item));
        });
        if (!cards.length) return;
        host.appendChild(rowEl(col.name || 'Related', cards, {
          className: 'row-collection-siblings',
          meta: String(cards.length),
          more: function(){ state.collectionId = col.id; state.view = 'collection'; render(); },
          moreText: 'Open collection'
        }));
      });
    }).catch(function(){});
  }

  var _showLoadSeq = 0;
  function loadShow(showKey, destination, onReady){
    var seq = ++_showLoadSeq;
    function stillHere(){
      return seq === _showLoadSeq && state.view === 'show' && String(state.showKey) === String(showKey);
    }
    api('/shows/'+encodeURIComponent(showKey)).then(function(j){
      if (!stillHere()) return;
      if (onReady) onReady();
      var dl = _dlStatus || {};
      var activeSeason;
      var firstPaint = true;
      var root = (destination || $('#show-view'));
      if (!root) return;
      if (!j || !j.ok || !j.show) {
        root.innerHTML = '';
        root.appendChild(h('div', { className:'hero' }, [
          h('h1', { text:'Show not found' }),
          h('button', { className:'btn ghost', text:'← Back', onClick: function(){ state.view='home'; render(); } })
        ]));
        return;
      }
      var show = j.show;
      var seasons = j.seasons || [];
      if (show.slug && state.view === 'show' && state.showKey === showKey && state.showSlug !== show.slug) { state.showSlug = show.slug; replaceNav(); }
      var showPath = 'tvshows/' + encodeURIComponent(show.slug || show.show_key || show.id);
      var availInfo = j.availability || {};
      var selected = 0;
      for (var i=0;i<seasons.length;i++){
        if (seasons[i].season > 0) { selected = seasons[i].season; break; }
      }
      if (seasons.length && selected === 0) selected = seasons[0].season;
      var wantEpItem = state._showEpisodeItem; state._showEpisodeItem = null;
      if (state.showSeason != null && seasons.some(function(s){ return s.season === state.showSeason; })) selected = state.showSeason;
      else if (wantEpItem) seasons.forEach(function(s){ (s.episodes || []).forEach(function(ep){ if (ep.id === wantEpItem) selected = s.season; }); });
      function paint(sel, fromTab){
        activeSeason = sel;
        if (fromTab) { state.showSeason = sel; state.showEpisode = null; syncUrl(); }
        var playerHost = root.querySelector('#detail-player');
        Array.prototype.slice.call(root.children).forEach(function(child){ if (child !== playerHost) child.remove(); });
        if (!playerHost) { playerHost = h('div', {id:'detail-player', className:'detail-player'}); root.appendChild(playerHost); }
        var heroPoster = h('div', { className:'poster poster-pending show-hero-poster', 'data-poster-fetch':'1', 'data-backdrop-src':'1', 'data-poster-variant':'full' }, [
          h('div', { className:'ph', text: show.title })
        ]);
        // Poster load runs after hero is mounted (see append below)
        var posterId = show.show_key || show.id;
        var missTotal = show.missing_count != null ? show.missing_count : (availInfo.missing || 0);
        var availTotal = show.available_count != null ? show.available_count : (availInfo.available || 0);
        var metaBits = [
          show.year ? String(show.year) : null,
          (show.season_count||0)+' seasons',
          availTotal+' available',
          missTotal ? (missTotal+' missing') : null,
          (show.episode_source ? ('source: '+show.episode_source) : null)
        ].filter(Boolean);
        var heroActions = [
          h('button', { className:'btn ghost', text:'← Back', onClick: function(){
            if (show.section) navigate('section', show.section);
            else { state.view='home'; render(); }
          }}),
          shareButton(showPath, 'Copy link to this show'),
          h('button', { className:'btn', text:'Find alternate', onClick: function(){
            openAlternateFinder({
              title: show.title || '',
              endpoint: '/shows/'+encodeURIComponent(showKey)+'/alternate',
              body: { kind: (show.section==='anime' || show.type==='anime') ? 'anime' : 'tv', section_id: show.section || ((show.type==='anime') ? 'anime' : 'tv'), show: show.title || '' }
            });
          }})
        ];
        if (!canSearchRss()) heroActions = heroActions.filter(function(button){ return button.textContent !== 'Find alternate'; });
        heroActions = heroActions.concat(detailMatchButtons(show, show.show_key || showKey));
        if (missTotal > 0 && canManageDownloads()) {
          heroActions.push(h('button', {
            className:'btn',
            text:'Fetch missing',
            onClick: function(){ fetchMissingShow(show.show_key || show.id, null); }
          }));
        }
        var heroKids = [
          heroPoster,
          h('div', { style:'flex:1;min-width:200px' }, [
            h('div', { className:'row-actions', style:'margin-bottom:8px' }, heroActions),
            h('h1', { text: show.title }),
            h('p', { className:'muted', text: metaBits.join(' · ') }),
            show.synopsis ? h('p', { className:'synopsis', text: show.synopsis }) : null
          ].filter(Boolean))
        ];
        root.appendChild(h('div', { className:'hero detail-hero', 'data-backdrop-host':'1', style:'display:flex;gap:20px;align-items:flex-end;flex-wrap:wrap' }, heroKids));
        window.VaultcastHub.details(show,root);
        // Mount first, then load — otherwise pumpPosterQueue drops the detached job
        if (posterId) {
          heroPoster.setAttribute('data-poster-fetch', '1');
          observePoster(heroPoster, posterId, 'show');
          loadPosterNow(heroPoster, posterId, 'show');
        }

        /* seasons/episodes below are the rest of the show; also surface franchise collections */
        root.appendChild(h('div', { id:'show-collections' }));
        mountShowCollections(show, seasons);

        // Full download queue lives in Admin — public shows only episode badges
        if (state.user && state.user.role === 'admin') {
          var badgeCount = (dl.downloading || []).length;
          root.appendChild(h('div', { className:'tx-panel admin-lite', style:'margin:0 20px 12px' }, [
            h('div', { style:'display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap' }, [
              h('span', { className:'muted', text: badgeCount ? (badgeCount + ' download' + (badgeCount===1?'':'s') + ' in progress') : 'No active downloads for this library' }),
              h('button', { className:'btn ghost', text:'Open download queue', onClick: function(){ state.adminTab='queue'; openAdmin(); } }),
              h('button', { className:'btn ghost', text:'Refresh', onClick: function(){
                loadDownloadStatus(true).then(function(r){ if (!stillHere()) return; dl = r || dl; paint(activeSeason); });
              }})
            ])
          ]));
        }

        root.appendChild(h('div', { className:'row-head detail-siblings-head', style:'padding:18px 20px 6px' }, [
          h('h2', { text: 'More from this show' }),
          h('span', { className:'meta', text: (seasons.length||0) + ' season' + (seasons.length===1?'':'s') })
        ]));
        var tabs = h('div', { className:'tabs', style:'padding:0 20px' }, seasons.map(function(s){
          var label = s.label || ('Season '+s.season);
          if (s.missing_count) label += ' ('+s.missing_count+' missing)';
          return h('button', {
            className: s.season===sel ? 'active' : '',
            text: label,
            onClick: function(){ paint(s.season, true); }
          });
        }).concat([ shareButton(function(){ return showPath + '/season-' + sel; }, 'Copy link to this season') ]));
        root.appendChild(tabs);
        var cur = null;
        for (var si=0;si<seasons.length;si++){ if (seasons[si].season===sel) { cur=seasons[si]; break; } }
        if (!cur) cur = seasons[0];
        var eps = (cur && cur.episodes) || [];
        if (cur && cur.missing_count > 0 && canManageDownloads()) {
          root.appendChild(h('div', { className:'row-actions', style:'padding:0 20px 8px' }, [
            h('button', {
              className:'btn',
              text:'Fetch missing in '+(cur.label || ('Season '+cur.season)),
              onClick: function(){ fetchMissingShow(show.show_key || show.id, cur.season); }
            })
          ]));
        }
        if (!eps.length) {
          root.appendChild(h('p', { className:'muted', style:'padding:12px 20px', text:'No episodes listed for this season.' }));
          return;
        }
        // Season poster for the selected season (fetch on first view)
        var seasonPosterId = (show.show_key || show.id) && cur && cur.season != null
          ? ((show.show_key || show.id) + '-s' + String(cur.season))
          : null;
        if (seasonPosterId && (cur.has_poster || true)) {
          var seasonArt = h('div', {
            className: 'poster poster-pending season-poster',
            style: 'width:120px;height:180px;border-radius:10px;flex-shrink:0;margin:0 20px 12px'
          }, [ h('div', { className:'ph', text: cur.label || ('Season '+cur.season) }) ]);
          seasonArt.setAttribute('data-poster-fetch', '1');
          root.appendChild(h('div', { style:'padding:4px 0 0;display:flex;align-items:flex-end;gap:14px' }, [
            seasonArt,
            h('div', { className:'muted', text: (cur.label || ('Season '+cur.season)) + ' · ' + (cur.available_count||0) + ' available' + (cur.missing_count ? (' · '+cur.missing_count+' missing') : '') })
          ]));
          observePoster(seasonArt, seasonPosterId, 'season');
          loadPosterNow(seasonArt, seasonPosterId, 'season');
        }
        var list = h('div', { className:'stack episode-list', style:'padding:12px 20px 28px' }, eps.map(function(ep){
          var code = epCode(ep.season, ep.episode);
          var title = episodeDisplayTitle(ep);
          var isAvail = ep.available !== false && ep.status !== 'missing' && !!ep.id;
          var dlMatch = canManageDownloads() ? matchDownload(show.title, ep.season, ep.episode, dl) : null;
          var badges = [
            h('span', {
              className: 'badge ' + (isAvail ? 'badge-available' : 'badge-missing'),
              text: isAvail ? 'Available' : 'Missing'
            })
          ];
          if (dlMatch) {
            var dlText = dlMatch.percent != null
              ? ('↓ '+dlMatch.percent+'%'+(dlMatch.eta_label ? ' · '+dlMatch.eta_label : ''))
              : ('↓ '+(dlMatch.status || 'queued'));
            badges.push(h('span', { className:'badge badge-downloading', text: dlText, title: dlMatch.name || '' }));
          }
          if (isAvail && needsConvertExt(ep)) {
            badges.push(h('span', { className:'badge badge-convert', text: String(ep.ext||'').toUpperCase() }));
          }
          var subParts = [];
          if (ep.airdate) subParts.push(ep.airdate);
          if (ep.ext) subParts.push(ep.ext);
          if (ep.size) subParts.push(fmtSize(ep.size));
          var actions = [];
          if (isAvail) {
            actions.push(h('button', {
              className:'btn primary',
              text:'Play',
              onClick: function(e){ e.stopPropagation(); playItem(ep); }
            }));
            if (window.NSMatch) { var nsmRb = window.NSMatch.episodeButton(ep, show); if (nsmRb) actions.push(nsmRb); }
            /* Convert removed from show detail ? use Settings ? Convert */
          } else if (canManageDownloads()) {
            if (dlMatch) {
              actions.push(h('button', {
                className:'btn ghost',
                text:'Cancel',
                onClick: function(e){
                  e.stopPropagation();
                  cancelEpisodeFetch(show.show_key || show.id, ep.season, ep.episode, show.title, e.currentTarget);
                }
              }));
            } else {
              actions.push(h('button', {
                className:'btn',
                text:'Get',
                onClick: function(e){
                  e.stopPropagation();
                  fetchEpisode(show.show_key || show.id, ep.season, ep.episode, e.currentTarget);
                }
              }));
            }
          }
          var epPoster = null;
          if (ep.show_key || show.show_key) {
            var epItem = ep;
            if (!epItem.show_key) epItem = Object.assign({}, ep, { show_key: show.show_key || show.id, type: show.type || ep.type || 'tv' });
            var epKind = posterKindFor(epItem);
            var epPid = posterIdFor(epItem);
            if (epPid) {
              epPoster = h('div', {
                className: 'poster poster-pending',
                style: 'width:48px;height:72px;border-radius:6px;flex-shrink:0'
              }, [ h('div', { className:'ph', text: code || 'Ep' }) ]);
              epPoster.setAttribute('data-poster-fetch', '1');
              observePoster(epPoster, epPid, epKind);
            }
          }
          var epPath = showPath + '/season-' + ep.season + (ep.episode != null && ep.episode !== '' ? '/episode-' + ep.episode : (ep.id ? '/' + encodeURIComponent(ep.id) : ''));
          actions.push(shareButton(epPath, 'Copy link to ' + (code || 'this episode')));
          var isTarget = (state.showEpisode != null && +ep.episode === +state.showEpisode && +ep.season === +sel) || (wantEpItem && ep.id === wantEpItem);
          return h('div', {
            className: 'panel episode-row' + (((state.user||{}).prefs||{}).hide_spoilers?' episode-spoiler-hidden':'') + (isAvail ? '' : ' episode-missing') + (isTarget ? ' episode-target' : ''),
            'data-episode-target': isTarget ? '1' : null,
            'data-hub-episode': isAvail ? ep.id : null,
            'data-episode-code':code,
            style: 'padding:12px 14px;display:flex;justify-content:space-between;gap:12px;align-items:center;' + (isAvail ? 'cursor:pointer;' : ''),
            tabindex: isAvail ? '0' : null,
            role: isAvail ? 'button' : null,
            onClick: isAvail ? function(){ playItem(ep); } : null,
            onKeydown: isAvail ? function(e){ if(e.key==='Enter') playItem(ep); } : null
          }, [
            epPoster,
            h('div', { style:'min-width:0;flex:1' }, [
              h('div', { className:'title', style:'display:flex;gap:8px;align-items:center;flex-wrap:wrap' }, [
                h('span', { text: (code ? code + ' · ' : '') + title })
              ].concat(badges)),
              h('div', { className:'sub muted', text: subParts.join(' · ') })
            ]),
            h('div', { className:'row-actions' }, actions)
          ].filter(Boolean));
        }));
        root.appendChild(list);
        var target = list.querySelector('[data-episode-target]');
        if (target && firstPaint) setTimeout(function(){ if (!stillHere()) return; try { target.scrollIntoView({ block:'center', behavior:'smooth' }); target.focus({ preventScroll:true }); } catch (eT) {} }, 60);
        firstPaint = false;
      }
      paint(selected);
      if (state._pendingEpisode && String(state._pendingEpisode.show_key) === String(showKey)) { var pendingEpisode = state._pendingEpisode; state._pendingEpisode = null; playItem(pendingEpisode, {inline:true}); }
      loadDownloadStatus(false).then(function(fresh){
        if (!stillHere() || !fresh || JSON.stringify(fresh.downloading || []) === JSON.stringify(dl.downloading || [])) return;
        dl = fresh;
        paint(activeSeason);
      });
    }).catch(function(e){
      if (!stillHere()) return;
      if (onReady) onReady();
      var root = (destination || $('#show-view'));
      if (!root) return;
      root.innerHTML = '';
      root.appendChild(h('div', { className:'hero' }, [
        h('h1', { text: String(e && e.message || e || 'Could not load show') }),
        h('button', { className:'btn ghost', text:'← Back', onClick: function(){ state.view='home'; render(); } })
      ]));
    });
  }

  var globalSearchSerial=0;
  function runGlobalSearch(q,page){
    q = (q || '').trim();page=Math.max(1,Number(page)||1);
    var serial=++globalSearchSerial,searchOwner=state.user&&state.user.id;
    state.searchQuery = q;
    state.searchRss = null;
    state.view = 'search';
    if (!q) {
      state.searchBusy = false;
      state.searchResult = { ok:true, catalogue:[], count:0, weak:true, query:'' };
      render();
      return;
    }
    state.searchBusy = true;
    render();
    api('/search?q='+encodeURIComponent(q)+'&limit=40&page='+page).then(function(r){
      if(serial!==globalSearchSerial||state.view!=='search'||searchOwner!==(state.user&&state.user.id))return;
      state.searchBusy = false;
      state.searchResult = r;
      render();
    }).catch(function(e){
      if(serial!==globalSearchSerial||state.view!=='search'||searchOwner!==(state.user&&state.user.id))return;
      state.searchBusy = false;
      state.searchResult = { ok:false, error:String(e), catalogue:[], count:0 };
      render();
    });
  }

  function runRssSearch(q){
    if(!canSearchRss())return;
    q = (q || state.searchQuery || '').trim();
    if (!q) return toast('Enter a search query first');
    state.searchBusy = true;
    render();
    api('/search/rss', { method:'POST', body:{ query:q, kind:'auto' } }).then(function(r){
      state.searchBusy = false;
      state.searchRss = r;
      if (!r.ok) {
        var msg = r.reason || r.error || 'RSS search failed';
        if (r.retry_in) msg += ' (wait ' + r.retry_in + 's)';
        toast(msg, { kind:'bad' });
      } else if (!(r.items||[]).length) {
        toast(r.hint || 'No RSS hits matched filters', { kind:'bad' });
      } else {
        toast((r.count||0)+' RSS hit'+(r.count===1?'':'s'), { kind:'ok' });
      }
      render();
    }).catch(function(e){
      state.searchBusy = false;
      toast(String(e), { kind:'bad' });
      render();
    });
  }

  function getRssHit(item, btn){
    if(!canManageDownloads())return;
    if (btn) { btn.disabled = true; btn.textContent = '…'; }
    api('/search/get', { method:'POST', body:{
      title: item.title || 'RSS get',
      magnet: item.magnet || '',
      link: item.link || '',
      enclosure: item.enclosure || '',
      query: item.search_query || state.searchQuery,
      kind: 'search_get'
    }}).then(function(r){
      if (btn) { btn.disabled = false; btn.textContent = 'Get'; }
      if (!r.ok) return toast(r.error || 'Get failed', { kind:'bad' });
      toast('Queued: '+(r.entry && r.entry.title || item.title), { kind:'ok' });
    }).catch(function(e){
      if (btn) { btn.disabled = false; btn.textContent = 'Get'; }
      toast(String(e), { kind:'bad' });
    });
  }

  function viewSearch(){
    var q = state.searchQuery || '';
    var res = state.searchResult || { catalogue:[], count:0, weak:true };
    var rss = canSearchRss() ? state.searchRss : null;
    var kids = [
      h('div', { className:'hero' }, [
        h('h1', { text: q ? ('Search: '+q) : 'Search' }),
        h('p', { className:'muted', text: state.searchBusy ? 'Searching…' : (
          (res.count||0)+' catalogue hit'+(res.count===1?'':'s')
          + (res.weak && canSearchRss() ? ' · Search download sources' : '')
        ) })
      ])
    ];
    if (state.searchBusy && !(res.catalogue||[]).length) {
      kids.push(pendingContent({ className:'muted', style:'padding:0 20px', }));
    } else if (!(res.catalogue||[]).length) {
      kids.push(emptyState(
        q ? 'No catalogue matches' : 'Type a title to search',
        q ? 'Try a different title or check your library filters.' : 'Press / then type. Catalogue results appear first.',
        canSearchRss() && q && res.rss_fallback_enabled !== false ? [
          h('button', { className:'btn primary', text: state.searchBusy?'Searching RSS…':'Search RSS / downloads', onClick: function(){ runRssSearch(q); } })
        ] : null
      ));
    } else {
      kids.push(h('section', { className:'row' }, [
        h('div', { className:'row-head' }, [
          h('h2', { text:'In your catalogue' }),
          h('span', { className:'meta', text: String(res.total || res.count || 0) })
        ]),
        h('div', { className:'grid' }, (res.catalogue||[]).map(function(it){ return cardEl(it); }))
      ]));
      if((res.page||1)>1||res.has_more)kids.push(h('div',{className:'row-actions',style:'padding:0 var(--gutter) 24px'},[
        h('button',{className:'btn ghost',type:'button',text:'Previous',disabled:!!state.searchBusy||(res.page||1)<=1,onClick:function(){runGlobalSearch(q,(res.page||1)-1);}}),
        h('span',{className:'muted',text:'Page '+(res.page||1)+' · '+(res.total||0)+' matches'}),
        h('button',{className:'btn primary',type:'button',text:'Next',disabled:!!state.searchBusy||!res.has_more,onClick:function(){runGlobalSearch(q,(res.page||1)+1);}})
      ]));
      if (canSearchRss() && (res.weak || res.rss_fallback_enabled)) {
        kids.push(h('div', { className:'panel', style:'margin:8px 20px' }, [
          h('p', { className:'muted', text:'Catalogue results are limited. Optionally search RSS for preferred qualities (admin: min seeds, qualities, languages).' }),
          h('div', { className:'row-actions' }, [
            h('button', {
              className:'btn',
              text: state.searchBusy ? 'Searching…' : 'Search RSS for 720p/1080p',
              disabled: !!state.searchBusy,
              onClick: function(){ runRssSearch(q); }
            })
          ])
        ]));
      }
    }
    if (rss) {
      if (!rss.ok) {
        kids.push(h('div', { className:'panel', style:'margin:8px 20px' }, [
          h('h2', { text:'RSS results' }),
          h('p', { className:'bad', text: (rss.reason || rss.error || 'Failed') + (rss.retry_in ? (' — retry in ' + rss.retry_in + 's') : '') }),
          rss.feeds_searched === 0 ? h('p', { className:'muted', text:'Configure at least one search feed with {query} in Admin → Acquisition.' }) : null
        ].filter(Boolean)));
      } else if (!(rss.items||[]).length) {
        kids.push(emptyState('No RSS hits', rss.hint || 'No torrents matched filters (seeds / quality / language). Check Admin → Acquisition feeds & filters.', [
          h('button', { className:'btn ghost', text:'Retry', onClick: function(){ runRssSearch(q); } })
        ]));
      } else {
        kids.push(h('section', { className:'row' }, [
          h('div', { className:'row-head' }, [
            h('h2', { text:'RSS / download candidates' }),
            h('span', { className:'meta', text: String(rss.count||0) })
          ]),
          h('div', { className:'stack', style:'padding:0 20px 24px' }, (rss.items||[]).map(function(it){
            var meta = [
              it.quality || null,
              it.seeds != null ? (it.seeds+' seeds') : null,
              it.language || null
            ].filter(Boolean).join(' · ');
            return h('div', { className:'panel episode-row', style:'padding:12px 14px;display:flex;justify-content:space-between;gap:12px;align-items:center' }, [
              h('div', { style:'min-width:0;flex:1' }, [
                h('div', { className:'title', text: it.title || 'Untitled' }),
                h('div', { className:'sub muted', text: meta || (it.search_query || '') })
              ]),
              h('div', { className:'row-actions' }, [
                h('button', {
                  className:'btn primary',
                  text:'Get', disabled:!canManageDownloads(),
                  onClick: function(e){ getRssHit(it, e.currentTarget); }
                })
              ])
            ]);
          }))
        ]));
      }
    }
    return shell(h('div', { id:'search-view' }, kids));
  }

  function viewSettings(){
    var u = state.user || {};
    var prefs = u.prefs || {};
    var isAdmin = !!(u && u.role === 'admin');
    var groups = [
      {title:'Your preferences', items:[{id:'account',label:'Account & playback'},{id:'cloud-saves',label:'Cloud saves'}]},
      {title:'Libraries',admin:true,items:[{id:'sections',label:'Folders & media types'},{id:'scan',label:'Scan libraries'},{id:'media',label:'Manage media'},{id:'collections',label:'Collections'},{id:'posters',label:'Artwork'},{id:'library',label:'Find & rename'},{id:'ingest',label:'Import inbox'},{id:'matching',label:'Correct metadata'},{id:'reports',label:'Label reports'}]},
      {title:'Users',admin:true,items:[{id:'users',label:'Accounts & access'}]},
      {title:'Playback',admin:true,items:[{id:'players',label:'Player files'},{id:'streaming',label:'Streaming'},{id:'video-optimise',label:'Video conversion'},{id:'poster-optimise',label:'Artwork optimisation'}]},
      {title:'Integrations',admin:true,items:[{id:'features',label:'Optional features'},{id:'acquisition',label:'Download sources'},{id:'queue',label:'Download queue'},{id:'live-studio',label:'TV Studio'},{id:'live-channels',label:'Live channels'},{id:'live-library',label:'Live media'},{id:'live-playlists',label:'Live playlists'},{id:'live-schedule',label:'Live schedule'},{id:'live-iptv',label:'IPTV feeds'},{id:'live-station',label:'Station outputs'}]},
      {title:'Maintenance',admin:true,items:[{id:'updates',label:'Check for updates'},{id:'maintenance',label:'Background tasks'},{id:'general',label:'Application'},{id:'diagnostics',label:'Diagnostics'},{id:'optimise-system',label:'Storage & caches'},{id:'system',label:'server tools'},{id:'advanced',label:'Advanced configuration'}]}
    ];
    var features = (state.home && state.home.features) || {};
    groups = groups.filter(function(g){ return !g.admin || isAdmin; }).map(function(g){
      g.items = g.items.filter(function(it){
        if (it.id.indexOf('live-') === 0) return !!features.live;
        if (it.id === 'acquisition' || it.id === 'queue') return !!features.downloads;
        if (it.id === 'system') return !!features.server_tools;
        return true;
      });
      return g;
    });
    var flat = [];
    groups.forEach(function(g){ g.items.forEach(function(it){ flat.push(it.id); }); });
    if (state.settingsSection === 'live') state.settingsSection = 'live-channels';
    if (state.settingsSection === 'convert') state.settingsSection = 'video-optimise';
    if (flat.indexOf(state.settingsSection) < 0) state.settingsSection = 'account';
    var sec = state.settingsSection;

    function goSec(id){
      var adminIds = { maintenance:1, players:1, features:1, diagnostics:1, sections:1, scan:1, posters:1, library:1, media:1, collections:1, streaming:1, convert:1, 'video-optimise':1, 'poster-optimise':1, 'optimise-system':1, queue:1, acquisition:1, system:1, general:1, users:1, advanced:1, ingest:1, matching:1, reports:1, live:1, 'live-channels':1, 'live-library':1, 'live-playlists':1, 'live-iptv':1, 'live-schedule':1, 'live-station':1, 'live-studio':1 };
      if (adminIds[id] && !isAdmin) {
        state._wantAdmin = true;
        state._settingsAfterLogin = id;
        state.view = 'login';
        render();
        return;
      }
      state.settingsSection = id;
      if (adminIds[id] && id !== 'live') state.adminTab = id;
      var ownG = ownerGroup(id);
      if (ownG) {
        state.settingsNavGroup = ownG;
        try { localStorage.setItem('vaultcast-settings-group', ownG); } catch (eP) {}
      }
      pushNav();
      render();
    }

    /* accordion open-group lives in state; only derived from the active section
       on first paint (or when goSec changes section) so clicks are not undone by render(). */
    function ownerGroup(id){
      for (var gi = 0; gi < groups.length; gi++) {
        if (groups[gi].items.some(function(it){ return it.id === id; })) return groups[gi].title;
      }
      return '';
    }
    var openGroup;
    /* Section changed outside goSec (deep link, back/forward, post-login redirect): re-open its group once */
    if (state._settingsNavSec !== sec) {
      if (state._settingsNavSec !== undefined || typeof state.settingsNavGroup !== 'string') {
        var ownNow = ownerGroup(sec);
        if (ownNow) state.settingsNavGroup = ownNow;
      }
      state._settingsNavSec = sec;
    }
    if (typeof state.settingsNavGroup === 'string') {
      openGroup = state.settingsNavGroup;
    } else {
      openGroup = ownerGroup(sec);
      if (!openGroup) {
        try { openGroup = localStorage.getItem('vaultcast-settings-group') || ''; } catch (eG) { openGroup = ''; }
      }
      state.settingsNavGroup = openGroup;
    }
    function setOpenGroup(title){
      openGroup = title || '';
      state.settingsNavGroup = openGroup;
      try { localStorage.setItem('vaultcast-settings-group', openGroup); } catch (eS) {}
      render();
    }
    var nav = h('nav', { className: 'settings-nav', 'aria-label': 'Settings' }, groups.map(function(g){
      var isOpen = openGroup === g.title;
      if (g.admin && !isAdmin) {
        return h('div', { className: 'settings-nav-group locked' + (isOpen ? ' open' : '') }, [
          h('button', { className: 'settings-nav-title accordion', type:'button', 'aria-expanded':'false', text: g.title }),
          h('p', { className: 'muted settings-nav-note', text: 'Sign in as admin' })
        ]);
      }
      return h('div', { className: 'settings-nav-group' + (isOpen ? ' open' : '') }, [
        h('button', {
          className: 'settings-nav-title accordion', type:'button',
          'aria-expanded': isOpen ? 'true' : 'false',
          onClick: function(ev){ if (ev && ev.preventDefault) ev.preventDefault(); setOpenGroup(isOpen ? '' : g.title); }
        }, [
          h('span', { text: g.title }),
          h('span', { className:'settings-nav-chevron', text: isOpen ? '▾' : '▸' })
        ]),
        isOpen ? h('div', { className: 'settings-nav-items' }, g.items.map(function(it){
          var kids = [ h('span', { text: it.label }) ];
          if ((it.id === 'ingest' || it.id === 'reports') && window.NSMatch) {
            var nsmN = window.NSMatch.counts[it.id] || 0;
            kids.push(h('span', { className: 'nav-badge nsm-badge-' + it.id, hidden: !(nsmN > 0), text: nsmN > 0 ? String(nsmN) : '' }));
          }
          if (it.id === 'convert') {
            kids.push(h('span', {
              className: 'cv-pending-badge nav-badge',
              hidden: !(state.convertPendingCount > 0),
              text: state.convertPendingCount > 0 ? String(state.convertPendingCount) : ''
            }));
          }
          return h('button', {
            className: 'settings-nav-btn' + (sec === it.id ? ' active' : ''),
            type: 'button',
            onClick: function(){ goSec(it.id); }
          }, kids);
        })) : null
      ]);
    }));

    var bodyKids = [];
    if (sec === 'account') {
      bodyKids = [
        h('h2', { text: 'Account' }),
        h('p', { className: 'muted', text: u.username ? ('Signed in as ' + (u.display_name || u.username)) : 'Browsing as guest — sign in to sync preferences.' }),
        u.username ? h('div', { className: 'stack settings-card' }, [
          h('label', {}, ['Display name', h('input', { id: 'dn', value: u.display_name || '' })]),
          h('div', {className:'profile-avatar-editor'}, [profileAvatar(u), h('label', {}, ['Avatar (JPEG, PNG or WebP, up to 1 MB)', h('input', {id:'profile_avatar', type:'file', accept:'image/jpeg,image/png,image/webp'})])]),
          h('label', {className:'check-label'}, [h('input', {id:'remove_avatar', type:'checkbox'}), 'Remove avatar (use initials)']),
          h('label', {}, ['Theme', h('select', { id: 'theme' }, [
            h('option', { value: 'dark', text: 'Dark', selected: prefs.theme !== 'light' }),
            h('option', { value: 'light', text: 'Light', selected: prefs.theme === 'light' })
          ])]),
          h('label', {}, ['New password (optional)', h('input', { id: 'pw', type: 'password', placeholder: 'Leave blank to keep' })]),
          h('label', { className: 'check-label' }, [ h('input', { id: 'reduce', type: 'checkbox', checked: !!prefs.reduce_motion }), ' Reduce motion' ]),
          h('label', { className: 'check-label' }, [ h('input', { id: 'autoplay', type: 'checkbox', checked: !!prefs.autoplay }), ' Autoplay' ]),
          h('div', { className: 'row-actions' }, [
            h('button', { className: 'btn primary', text: 'Save preferences', onClick: savePrefs }),
            h('button', {className: 'btn', text: 'Sign out', onClick: function(){ api('/logout', {method:'POST'}).then(function(result){
              if (!result.ok) return toast(result.error || 'Could not sign out', {kind:'bad'});
              closePlayer(); state.user = null; state._guest = false; state.home = null;
              return api('/me').then(function(me){
                state._guestBrowsing = !!me.guest_browsing; state._guest = state._guestBrowsing;
                if (state._guest) return loadHome();
                showWelcome();
              });
            }).catch(function(){showWelcome();}); }})
          ])
        ]) : h('div', { className: 'settings-card row-actions' }, [
          h('button', { className: 'btn primary', text: 'Sign in', onClick: function(){ state._wantAdmin = false; state.view = 'login'; render(); } })
        ])
      ];
    } else if (sec === 'cloud-saves') {
      var savesHost=h('div');bodyKids=[h('h2',{text:'Cloud saves'}),savesHost];
      setTimeout(function(){window.VaultcastHomeExtras.savesPanel(savesHost);},0);
    } else if (sec === 'updates' && isAdmin) {
      var updHost = h('div', { className:'stack updates-panel' });
      bodyKids = [
        h('h2', { text:'Check for updates' }),
        h('p', { className:'muted', text:'Updates come from the signed-off GitHub releases of Vaultcast. Each download is verified against the SHA-256 checksums published with the release. Only the application files are replaced: your settings, database, media, caches and data folder are never touched, and the replaced files are backed up first.' }),
        updHost
      ];
      setTimeout(function(){ renderUpdatesPanel(updHost); }, 0);
    } else if (sec === 'maintenance' && isAdmin) {
      var jobsHost = h('div', {className:'stack'});
      function refreshJobs(){
        clearTimeout(jobsHost._poll);
        api('/admin/maintenance').then(function(result){
          jobsHost.textContent = '';
          if (!result.ok) { jobsHost.textContent = result.error || 'Could not load tasks'; return; }
          Object.keys(result.tasks).forEach(function(task){
            jobsHost.appendChild(h('div',{className:'settings-card row-actions'},[
              h('strong',{text:result.tasks[task]}),
              h('button',{className:'btn',text:'Run now',onClick:function(event){ event.currentTarget.disabled=true; api('/admin/maintenance',{method:'POST',body:{task:task}}).then(function(r){if(!r.ok)toast(r.error); refreshJobs();}); }})
            ]));
          });
          result.jobs.forEach(function(job){
            var row=h('div',{className:'settings-card'},[h('strong',{text:result.tasks[job.task] || job.task}),h('p',{text:(job.result && job.result.continuation ? 'Pass complete · continuing' : job.status)+' · '+job.created_at+' · '+job.requested_by}),h('p',{className:'muted',text:job.error || ''})]);
            if (job.progress) row.appendChild(h('p',{className:'muted',text:(job.progress.phase || 'Progress') + ': ' + job.progress.done + '/' + job.progress.total + ' · ' + (job.progress.file || '')}));
            if (job.result && job.task === 'metadata') row.appendChild(h('p',{className:'muted',text:(job.result.stage ? 'Stage: ' + job.result.stage + ' · this pass checked ' : 'Checked ') + job.result.processed + ' artwork entries · ' + job.result.artwork_prepared + ' optimised · ' + (job.result.failed + job.result.artwork_failed) + ' failed' + (job.result.continuation ? ' · remaining work continues automatically in the next task' : '')}));
            if (job.result && job.result.warning) row.appendChild(h('p',{className:'bad',text:job.result.warning}));
            if (job.result && job.result.sidecars) row.appendChild(h('p',{className:'muted',text:'Beside media: ' + job.result.sidecars.written + ' files backed up · ' + job.result.sidecars.unchanged + ' unchanged · ' + job.result.sidecars.preserved + ' existing files preserved · ' + job.result.sidecars.failed + ' failed · ' + job.result.sidecars.skipped + ' unavailable/outside library'}));
            if (job.status==='queued'||job.status==='running') row.appendChild(h('button',{className:'btn',text:'Cancel',onClick:function(){api('/admin/maintenance/cancel',{method:'POST',body:{id:job.id}}).then(function(r){toast(r.message || r.error); refreshJobs();});}}));
            jobsHost.appendChild(row);
          });
          if (result.jobs.some(function(job){return job.status==='queued'||job.status==='running';})) jobsHost._poll=setTimeout(function(){if(jobsHost.isConnected && state.view==='settings' && state.settingsSection==='maintenance')refreshJobs();},2000);
        });
      }
      bodyKids=[h('h2',{text:'Background tasks'}),h('p',{className:'muted',text:'Scans and updates may queue preparation automatically. One task runs at a time. Artwork preparation continues automatically in bounded passes. Cancellation stops at the next file or transaction boundary. Progress refreshes automatically while this page is open.'}),
        h('button',{className:'btn',text:'Refresh status',onClick:refreshJobs}), jobsHost,
        h('p',{className:'muted',text:'Video conversion and artwork batches are started by administrators in their settings pages. Download transfers are controlled in Download queue. External OS schedules must be managed in Task Scheduler or cron.'}),
        h('button',{className:'btn',text:'Video conversion jobs',onClick:function(){openSettings('convert');}}),h('button',{className:'btn',text:'Artwork jobs',onClick:function(){openSettings('poster-optimise');}})];
      refreshJobs();
    } else if (sec === 'features' && isAdmin) {
      var featureOptions = [
        ['live','Live channels & IPTV','TV Studio schedules, IPTV sources (iptv-org is preinstalled) and the programme guide.'],
        ['downloads','Download integrations','Connect download services you already operate.'],
        ['server_tools','Server administration tools','Optional Linux process and service controls. Configure permissions before use.']
      ];
      var featureRows = featureOptions.map(function(option){
        var id = 'feature-' + option[0];
        var on = option[0] === 'live' ? features.live !== false : !!features[option[0]];
        return h('label', { className:'feature-row', 'for':id }, [
          h('span', { className:'feature-text' }, [ h('strong', { text:option[1] }), h('span', { className:'muted', text:option[2] }) ]),
          h('span', { className:'feature-switch' }, [
            h('input', { type:'checkbox', role:'switch', id:id, className:'feature-switch-input', checked:on }),
            h('span', { className:'feature-switch-track', 'aria-hidden':'true' })
          ])
        ]);
      });
      var saveFeatures = h('button',{className:'btn primary',type:'button',text:'Save features',onClick:function(){
        var value = {};
        featureOptions.forEach(function(option){value[option[0]] = document.getElementById('feature-'+option[0]).checked;});
        api('/admin/settings',{method:'POST',body:{features:value}}).then(function(result){
          if (!result.ok) throw new Error(result.error || 'Could not save features');
          if (!state.home) state.home = {};
          state.home.features = value;
          toast('Features updated'); render();
        }).catch(function(error){toast(error.message || String(error));});
      }});
      bodyKids = [
        h('h2',{text:'Optional features'}),
        h('p',{className:'muted',text:'Keep your library light. Enable only the services you use. Media types and folders are managed under Libraries.'}),
        h('div',{className:'settings-card feature-card'}, featureRows.concat([ h('div',{className:'feature-card-foot'},[saveFeatures]) ]))
      ];
    } else if (sec === 'system') {
      bodyKids = [
        h('h2', { text: 'server & processes' }),
        h('p', { className: 'muted', text: 'Resource usage, service restarts, process manager, and reboot. Admin only.' }),
        h('div', { id: 'system-panel', className: 'system-panel' }, [ pendingContent({ className:'muted', }) ])
      ];
      setTimeout(loadSystemPanel, 0);
    } else if (sec === 'players') {
      bodyKids = [
        h('h2', {text:'Player files'}),
        h('p', {className:'muted',text:'Choose whether browsers load EmulatorJS and HLS.js from their CDNs or from this server. Server storage includes all emulator cores. Games and BIOS files stay in your library.'}),
        h('div', {id:'player-assets-panel',className:'settings-card'}, [pendingContent({})])
      ];
      setTimeout(renderPlayerAssets, 0);
    } else if (sec === 'diagnostics') {
      bodyKids = [
        h('h2', { text: 'Diagnostics' }),
        h('p', { className: 'muted', text: 'Run individual diagnostic tests. Nothing runs in the background.' }),
        h('div', { className: 'row-actions' }, [
          h('button', { className: 'btn', text: 'Refresh list', onClick: loadDiagList }),
          h('button', { className: 'btn ghost', text: 'Run all', onClick: runDiag })
        ]),
        h('div', { id: 'diag-out', className: 'settings-card' }, [ pendingContent({ className: 'muted', }) ])
      ];
      setTimeout(loadDiagList, 0);
    } else if (sec === 'ingest' || sec === 'matching' || sec === 'reports') {
      /* Matching group lives in assets/js/matching.js */
      if (!window.NSMatch && window.__nsEnsureMatching) {
        window.__nsEnsureMatching(function(){ if (state.view === 'settings') render(); });
      }
      bodyKids = window.NSMatch ? window.NSMatch.settingsKids(sec) : [ h('h2', { text: 'Matching' }), pendingContent({ className: 'muted', }) ];
    } else if (sec === 'live' || sec.indexOf('live-') === 0) {
      bodyKids = liveNativeSettingsKids(sec);
    } else if (sec === 'poster-optimise') {
      bodyKids = [
        h('h2', { text: 'Poster optimisation' }),
        h('p', { className: 'muted', text: 'Keep the original JPG/PNG and build three WebP sizes: main WebP, medium WebP for library grids and tiny WebP for live search. Detail pages use full WebP; browsers without WebP fall back to the original.' }),
        h('div', { id: 'poster-optimise-panel', className: 'convert-admin po-admin' }, [ pendingContent({ className:'muted', }) ])
      ];
      setTimeout(function(){ renderPosterOptimisePanel(); }, 0);
    } else if (sec === 'optimise-system') {
      bodyKids = [
        h('h2', { text: 'System optimisation' }),
        h('p', { className: 'muted', text: 'Safe, portable cache tools for faster browsing. Manage generated files without changing your media.' }),
        h('div', { id: 'optimise-system-panel', className: 'convert-admin po-admin' }, [ pendingContent({ className:'muted', }) ])
      ];
      setTimeout(function(){ renderOptimiseSystemPanel(); }, 0);
    } else {
      // Admin panels — reuse fillAdmin into #admin-body
      state.adminTab = (sec === 'video-optimise') ? 'convert' : sec;
      bodyKids = [
        h('h2', { text: ({
          sections: 'Libraries', scan: 'Scan', posters: 'Posters', library: 'Find / Rename', media: 'Media Manager',
          collections: 'Collections', streaming: 'Streaming', convert: 'Video optimisation', 'video-optimise': 'Video optimisation',
          'poster-optimise': 'Poster optimisation', 'optimise-system': 'System optimisation',
          queue: 'Download queue',
          acquisition: 'Acquisition', general: 'General', users: 'Users', advanced: 'Advanced'
        })[sec] || sec }),
        h('div', { id: 'admin-body', className: 'tab-panel active settings-admin-body' }, [
          pendingContent({ className: 'muted', })
        ])
      ];
      setTimeout(fillAdmin, 0);
    }

    var panel = h('div', { className: 'panel settings-panel' }, [
      h('div', { className: 'settings-hero' }, [
        h('h1', { text: 'Settings' }),
        h('p', { className: 'muted', text: 'One place for account, VoD libraries, Watch Live, Play, optimise, and downloads. Vaultcast ' + APP_VERSION })
      ]),
      h('div', { className: 'settings-hub' }, [
        nav,
        h('div', { className: 'settings-main', id: 'settings-main' }, bodyKids)
      ])
    ]);
    return shell(panel);
  }

  function savePrefs(){
    var body = {
      display_name: $('#dn').value,
      theme: $('#theme').value,
      password: $('#pw').value || undefined,
      reduce_motion: $('#reduce').checked,
      autoplay: $('#autoplay').checked,
      remove_avatar:!!($('#remove_avatar') && $('#remove_avatar').checked)
    };
    var file = $('#profile_avatar') && $('#profile_avatar').files[0];
    var avatar = Promise.resolve(null);
    if (file && !body.remove_avatar) avatar = new Promise(function(resolve, reject){
      if (file.size > 1048576) {reject(new Error('Choose an avatar no larger than 1 MB'));return;}
      var reader = new FileReader();reader.onerror = function(){reject(new Error('Could not read avatar'));};
      reader.onload = function(){
        var image = new Image();image.onerror = function(){reject(new Error('Choose a valid image'));};
        image.onload = function(){
          if (image.naturalWidth * image.naturalHeight > 4000000) {reject(new Error('Avatar must be no larger than 4 megapixels'));return;}
          try {
            var canvas = document.createElement('canvas');canvas.width = canvas.height = 256;
            var context = canvas.getContext('2d'), size = Math.min(image.naturalWidth,image.naturalHeight);
            context.fillStyle = '#172235';context.fillRect(0,0,256,256);
            context.drawImage(image,(image.naturalWidth-size)/2,(image.naturalHeight-size)/2,size,size,0,0,256,256);
            resolve(canvas.toDataURL('image/jpeg',0.9));
          } catch(error) {reject(new Error('Could not prepare avatar'));}
        };image.src = reader.result;
      };reader.readAsDataURL(file);
    });
    avatar.then(function(data){body.avatar_data = data;return api('/me/prefs', {method:'POST', body:body});}).then(function(j){
      if (!j.ok) return toast(j.error||'Save failed');
      state.user = j.user; toast('Saved'); applyPrefs(); render();
    }).catch(function(error){toast(error.message || 'Could not save preferences', {kind:'bad'});});
  }

  function fmtBytes(n){
    n = +n || 0;
    if (n < 1024) return n + ' B';
    var u = ['KB','MB','GB','TB'];
    var i = -1;
    do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
    return n.toFixed(n >= 10 ? 0 : 1) + ' ' + u[i];
  }
  function loadSystemPanel(){
    var host = $('#system-panel');
    if (!host) return;
    host.innerHTML = '';
    host.appendChild(pendingContent({ className:'muted', }));
    Promise.all([
      api('/admin/system/status'),
      api('/admin/system/processes?limit=60'),
      api('/admin/system/logs?lines=30')
    ]).then(function(rows){
      var st = rows[0] || {};
      var pr = rows[1] || {};
      var lg = rows[2] || {};
      host = $('#system-panel');
      if (!host) return;
      host.innerHTML = '';
      if (!st.ok) {
        host.appendChild(h('p', { className:'bad', text: st.error || 'Could not load system status' }));
        return;
      }
      var mem = st.memory || {};
      var disk = st.disk || {};
      var media = st.media_disk || {};
      var load = st.load || {};
      host.appendChild(h('div', { className:'sys-grid' }, [
        h('div', { className:'sys-card' }, [
          h('div', { className:'sys-label', text:'CPU' }),
          h('div', { className:'sys-value', text: (st.cpu_percent != null ? st.cpu_percent + '%' : '—') }),
          h('div', { className:'muted', text: 'Load ' + [load['1'], load['5'], load['15']].map(function(x){ return x != null ? Number(x).toFixed(2) : '—'; }).join(' / ') })
        ]),
        h('div', { className:'sys-card' }, [
          h('div', { className:'sys-label', text:'Memory' }),
          h('div', { className:'sys-value', text: (mem.percent != null ? mem.percent + '%' : '—') }),
          h('div', { className:'muted', text: fmtBytes(mem.used) + ' / ' + fmtBytes(mem.total) })
        ]),
        h('div', { className:'sys-card' }, [
          h('div', { className:'sys-label', text:'Media storage' }),
          h('div', { className:'sys-value', text: (disk.percent != null ? disk.percent + '%' : '—') }),
          h('div', { className:'muted', text: fmtBytes(disk.free) + ' free of ' + fmtBytes(disk.total) })
        ]),
        h('div', { className:'sys-card' }, [
          h('div', { className:'sys-label', text:'Uptime' }),
          h('div', { className:'sys-value', text: st.uptime_human || '—' }),
          h('div', { className:'muted', text: (st.temperature_c != null ? (st.temperature_c + '°C · ') : '') + (st.hostname || '') })
        ])
      ]));

      host.appendChild(h('div', { className:'settings-card sys-meta' }, [
        h('p', { className:'muted', text: 'Vaultcast ' + (st.version || APP_VERSION) + ' · PHP ' + ((st.php && st.php.version) || '') + (media && media.ok ? (' · media path free ' + fmtBytes(media.free)) : '') }),
        h('div', { className:'sys-ifaces' }, (st.network || []).map(function(n){
          return h('span', { className:'sys-chip', text: (n.iface || '') + ' ' + (n.ipv4 || '') + (n.note ? ' · ' + n.note : '') });
        }))
      ]));

      // Services
      host.appendChild(h('h3', { className:'admin-section-title', text:'Services' }));
      var svcTable = h('table', { className:'table sys-table' }, [
        h('tr', {}, [h('th',{text:'Service'}), h('th',{text:'Status'}), h('th',{text:''})])
      ]);
      (st.services || []).forEach(function(svc){
        svcTable.appendChild(h('tr', {}, [
          h('td', { text: svc.label || svc.id }),
          h('td', { className: svc.running ? 'ok' : 'bad', text: svc.active || '—' }),
          h('td', {}, [
            h('button', {
              className:'btn ghost', type:'button', text:'Restart',
              onClick: function(){
                confirmDialog('Restart ' + (svc.label || svc.id) + '?', { okText:'Restart' }).then(function(ok){
                  if (!ok) return;
                  api('/admin/system/service/restart', { method:'POST', body:{ id: svc.id } }).then(function(r){
                    if (!r.ok) return toast(r.error || 'Restart failed', { kind:'bad' });
                    toast('Restarted ' + (svc.label || svc.id), { kind:'ok' });
                    loadSystemPanel();
                  }).catch(function(e){ toast(String(e), { kind:'bad' }); });
                });
              }
            })
          ])
        ]));
      });
      host.appendChild(svcTable);

      host.appendChild(h('div', { className:'row-actions', style:'margin:14px 0' }, [
        h('button', { className:'btn', type:'button', text:'Refresh', onClick: loadSystemPanel }),
        h('button', { className:'btn ghost', type:'button', text:'Clear PHP / app caches', onClick: function(){
          confirmDialog('Clear OPcache and catalogue cache files?', { okText:'Clear' }).then(function(ok){
            if (!ok) return;
            api('/admin/system/cache-clear', { method:'POST', body:{} }).then(function(r){
              toast(r.ok ? 'Caches cleared' : (r.error || 'Failed'), { kind: r.ok ? 'ok' : 'bad' });
            });
          });
        }}),
        h('button', { className:'btn danger', type:'button', text:'Reboot server…', onClick: function(){
          confirmDialog('Reboot the server now? Active streams and downloads will drop. The UI will be unreachable for a minute or two.', { danger:true, okText:'Reboot' }).then(function(ok){
            if (!ok) return;
            api('/admin/system/reboot', { method:'POST', body:{ confirm:true } }).then(function(r){
              if (!r.ok) return toast(r.error || 'Reboot failed', { kind:'bad' });
              toast(r.message || 'Reboot scheduled', { kind:'ok' });
            }).catch(function(e){ toast(String(e), { kind:'bad' }); });
          });
        }})
      ]));

      // Processes
      host.appendChild(h('h3', { className:'admin-section-title', text:'Processes' }));
      var procTable = h('table', { className:'table sys-table sys-proc' }, [
        h('tr', {}, [h('th',{text:'Name'}), h('th',{text:'PID'}), h('th',{text:'CPU%'}), h('th',{text:'MEM%'}), h('th',{text:'User'}), h('th',{text:''})])
      ]);
      (pr.processes || []).forEach(function(proc){
        procTable.appendChild(h('tr', {}, [
          h('td', { text: proc.name }),
          h('td', { className:'muted', text: String(proc.pid) }),
          h('td', { text: String(proc.cpu) }),
          h('td', { text: String(proc.mem) }),
          h('td', { className:'muted', text: proc.user || '' }),
          h('td', {}, [
            h('button', {
              className:'btn ghost', type:'button', text:'Kill',
              onClick: function(){
                confirmDialog('Send SIGTERM to ' + proc.name + ' (PID ' + proc.pid + ')?', { danger:true, okText:'SIGTERM' }).then(function(ok){
                  if (!ok) return;
                  api('/admin/system/kill', { method:'POST', body:{ pid: proc.pid, force:false } }).then(function(r){
                    if (!r.ok) return toast(r.error || 'Kill failed', { kind:'bad' });
                    toast('Signaled ' + proc.pid, { kind:'ok' });
                    setTimeout(loadSystemPanel, 400);
                  });
                });
              }
            }),
            h('button', {
              className:'btn ghost', type:'button', text:'Force',
              onClick: function(){
                confirmDialog('Force SIGKILL ' + proc.name + ' (PID ' + proc.pid + ')?', { danger:true, okText:'SIGKILL' }).then(function(ok){
                  if (!ok) return;
                  api('/admin/system/kill', { method:'POST', body:{ pid: proc.pid, force:true } }).then(function(r){
                    if (!r.ok) return toast(r.error || 'Kill failed', { kind:'bad' });
                    toast('Killed ' + proc.pid, { kind:'ok' });
                    setTimeout(loadSystemPanel, 400);
                  });
                });
              }
            })
          ])
        ]));
      });
      host.appendChild(procTable);

      // Logs
      if (lg.ok && (lg.logs || []).length) {
        host.appendChild(h('h3', { className:'admin-section-title', text:'Recent logs' }));
        (lg.logs || []).forEach(function(log){
          host.appendChild(h('div', { className:'settings-card' }, [
            h('div', { className:'muted', text: log.path }),
            h('pre', { className:'sys-log', text: log.tail || '(empty)' })
          ]));
        });
      }
    }).catch(function(e){
      host = $('#system-panel');
      if (host) host.textContent = String(e);
    });
  }

  function loadDiagList(){
    api('/diagnostics').then(function(j){
      var out = $('#diag-out'); if (!out) return;
      out.innerHTML = '';
      if (!j.ok) {
        out.appendChild(h('p', { className:'bad', text: j.error || 'Diagnostics unavailable' }));
        return;
      }
      out.appendChild(h('p', { className:'muted', text: 'App '+ (j.version||'') +' · click Run on a test' }));
      var table = h('table', { className:'table', id:'diag-table' }, [
        h('tr', {}, [h('th',{text:'Test'}),h('th',{text:'Group'}),h('th',{text:'Status'}),h('th',{text:''})])
      ]);
      (j.tests||[]).forEach(function(t){
        var statusCell = h('td', { id:'diag-st-'+t.id, className:'muted', text:'—' });
        var detailCell = h('td', { id:'diag-dt-'+t.id, className:'muted', text:'' });
        table.appendChild(h('tr', {}, [
          h('td',{text:t.name}),
          h('td',{ className:'muted', text:t.group||'' }),
          statusCell,
          h('td', {}, [
            h('button', {
              className:'btn',
              text:'Run',
              onClick: function(e){ runDiagOne(t.id, e.currentTarget); }
            })
          ])
        ]));
        // detail on next row for readability
        table.appendChild(h('tr', {}, [
          h('td', { colSpan:'4' }, [ detailCell ])
        ]));
      });
      out.appendChild(table);
    }).catch(function(e){
      var out = $('#diag-out'); if (out) out.textContent = String(e);
    });
  }
  function runDiagOne(id, btn){
    if (btn) { btn.disabled = true; btn.textContent = '…'; }
    api('/diagnostics/'+encodeURIComponent(id), { method:'POST', body:{} }).then(function(j){
      if (btn) { btn.disabled = false; btn.textContent = 'Run'; }
      var t = j.test || {};
      var st = $('#diag-st-'+id); var dt = $('#diag-dt-'+id);
      if (st) { st.textContent = t.ok ? 'OK' : 'FAIL'; st.className = t.ok ? 'ok' : 'bad'; }
      if (dt) dt.textContent = t.detail || (j.error || '');
      if (!j.ok) toast(j.error || 'Test failed', { kind:'bad' });
    }).catch(function(e){
      if (btn) { btn.disabled = false; btn.textContent = 'Run'; }
      toast(String(e), { kind:'bad' });
    });
  }
  function runDiag(){
    api('/diagnostics', { method:'POST', body:{} }).then(function(j){
      var out = $('#diag-out'); if (!out) return;
      out.innerHTML = '';
      out.appendChild(h('p', { text: 'Passed '+j.passed+'/'+j.total + (j.version ? (' · v'+j.version) : '') }));
      var table = h('table', { className:'table' }, [
        h('tr', {}, [h('th',{text:'Test'}),h('th',{text:'Status'}),h('th',{text:'Detail'})])
      ]);
      (j.tests||[]).forEach(function(t){
        table.appendChild(h('tr', {}, [
          h('td',{text:t.name}),
          h('td',{ className: t.ok?'ok':'bad', text: t.ok?'OK':'FAIL' }),
          h('td',{ className:'muted', text: t.detail||'' })
        ]));
      });
      out.appendChild(table);
    });
  }

  function viewAdmin(){
    var groups = [
      { title:'Library', tabs: [['sections','Libraries'],['scan','Scan'],['posters','Posters'],['library','Find / Rename'],['media','Media Manager'],['collections','Collections']] },
      { title:'Playback', tabs: [['streaming','Streaming']] },
      { title:'Optimise', tabs: [['convert','Video optimisation'],['poster-optimise','Poster optimisation'],['optimise-system','System']] },
      { title:'Downloads', tabs: [['queue','Download queue'],['acquisition','Acquisition']] },
      { title:'System', tabs: [['general','General'],['users','Users'],['advanced','Advanced']] }
    ];
    var flat = [];
    groups.forEach(function(g){ g.tabs.forEach(function(t){ flat.push(t); }); });
    if (!flat.some(function(t){ return t[0]===state.adminTab; })) state.adminTab = 'general';
    var panel = h('div', { className:'panel admin-panel' }, [
      h('h1', { text:'Settings' }),
      h('p', { className:'muted admin-lead', text:'Settings are grouped by task. Changes save immediately to the server.' }),
      h('div', { className:'admin-nav' }, groups.map(function(g){
        return h('div', { className:'admin-nav-group' }, [
          h('div', { className:'admin-nav-title', text: g.title }),
          h('div', { className:'tabs admin-tabs' }, g.tabs.map(function(t){
            return h('button', { className: state.adminTab===t[0]?'active':'', text:t[1], onClick: function(){ state.adminTab=t[0]; render(); } });
          }))
        ]);
      })),
      h('div', { id:'admin-body', className:'tab-panel active' }, [ pendingContent({ className:'muted', }) ])
    ]);
    // async fill
    setTimeout(fillAdmin, 0);
    return shell(panel);
  }

  function fillAdmin(){
    api('/admin/settings').then(function(j){
      if (!j.ok) return toast(j.error||'Admin denied');
      state.settings = j.settings;
      var body = $('#admin-body'); if (!body) return;
      body.innerHTML = '';
      var s = j.settings;
      var tab = state.adminTab;
      if (tab === 'general') {
        var auth = s.auth || {};
        var home = s.home || {};
        var pwa = s.pwa || {};
        body.appendChild(h('div', { className:'stack admin-stack' }, [
          h('h3', { className:'admin-section-title', text:'Appearance' }),
          fieldEl('app_name','App display name', s.app_name),
          fieldEl('app_tagline','Subtitle / tagline', s.app_tagline||''),
          fieldEl('theme','Theme (dark or light)', s.theme||'dark'),
          h('h3', { className:'admin-section-title', text:'Links' }),
          fieldEl('public_base_url','Public base URL for shared links (optional, e.g. https://media.example.com/)', s.public_base_url||'', 'url'),
          h('p', { className:'muted', text:'Leave empty to share links using the address you are browsing on (its protocol, host and port).' }),
          h('h3', { className:'admin-section-title', text:'Home screen' }),
          fieldEl('home_random','Random titles per library row', home.random_per_section||10, 'number'),
          fieldEl('home_cw','Continue Watching row size', home.continue_watching_limit||20, 'number'),
          h('h3', { className:'admin-section-title', text:'Sign-in' }),
          h('label', { className:'check-label' }, [ h('input', { id:'auth_req', type:'checkbox', checked:true, disabled:true }), ' External visitors must sign in' ]),
          h('label', { className:'check-label' }, [ h('input', { id:'auth_lan', type:'checkbox', checked:auth.lan_guest_access!==false }), ' Allow local network users to watch without signing in' ]),
          h('p', {className:'muted', text:'LAN guests can browse and play content. Administration still requires an admin login.'}),
          fieldEl('auth_proxies','Trusted reverse proxy IPs / CIDRs (optional, comma separated)', (auth.trusted_proxies || []).join(', ')),
          fieldEl('auth_ttl','Stay signed in for (seconds)', auth.session_ttl_seconds||604800, 'number'),
          h('h3', { className:'admin-section-title', text:'Installable app (PWA)' }),
          h('label', { className:'check-label' }, [ h('input', { id:'pwa_en', type:'checkbox', checked: pwa.enabled!==false }), ' Allow Add to Home Screen' ]),
          fieldEl('pwa_theme','Status bar colour', pwa.theme_color||'#0b0d10'),
          fieldEl('pwa_bg','Splash background colour', pwa.background_color||'#0b0d10'),
          h('div', { className:'row-actions' }, [
            h('button', { className:'btn primary', text:'Save general settings', onClick: function(){
              var pubUrl = $('#public_base_url').value.trim();
              window.__PUBLIC_BASE_URL__ = /^https?:\/\//i.test(pubUrl) ? pubUrl.replace(/\/+$/, '') + '/' : '';
              saveSettings({
                app_name: $('#app_name').value,
                app_tagline: $('#app_tagline').value,
                public_base_url: $('#public_base_url').value.trim(),
                theme: $('#theme').value,
                home: { random_per_section:+$('#home_random').value, continue_watching_limit:+$('#home_cw').value },
                auth: { require_login:true, lan_guest_access:$('#auth_lan').checked, trusted_proxies:$('#auth_proxies').value.split(',').map(function(value){return value.trim();}).filter(Boolean), session_ttl_seconds:+$('#auth_ttl').value },
                pwa: { enabled: $('#pwa_en').checked, theme_color:$('#pwa_theme').value, background_color:$('#pwa_bg').value }
              });
            }})
          ])
        ]));
      } else if (tab === 'media') {
        body.appendChild(renderMediaManager(s));
      } else if (tab === 'sections') {
        body.appendChild(renderSectionsEditor(s));
      } else if (tab === 'library') {
        body.appendChild(renderLibraryManager(s));
      } else if (tab === 'streaming') {
        var st = s.streaming || {};
        body.appendChild(h('div', { className:'stack' }, [
          h('label', {}, [ h('input', { id:'dp_only', type:'checkbox', checked: st.directplay_only!==false }), ' DirectPlay only (no server transcode for playback)' ]),
          h('label', {}, [ h('input', { id:'range_en', type:'checkbox', checked: st.range_enabled!==false }), ' HTTP Range / seeking enabled' ]),
          fieldEl('token_ttl_seconds','Stream token TTL (sec)', st.token_ttl_seconds||14400, 'number'),
          h('div', { className:'row-actions' }, [
            h('button', { className:'btn primary', text:'Save', onClick: function(){
              saveSettings({ streaming: {
                directplay_only: $('#dp_only').checked,
                range_enabled: $('#range_en').checked,
                token_ttl_seconds: +$('#token_ttl_seconds').value
              }});
            }})
          ])
        ]));
        var os = s.opensubtitles || {};
        body.appendChild(h('div', { className:'stack', style:'margin-top:24px' }, [
          h('h3', { text:'OpenSubtitles' }),
          h('p', { className:'muted', text:'Adds “Search OpenSubtitles…” to the player’s subtitle menu. Get an API key from your opensubtitles.com account (API consumers). The username and password are optional and raise the daily download limit. Downloaded subtitles are converted to WebVTT and saved next to the video (or in this server’s data folder when the library is read-only), so every user sees them. Keys are stored in the server settings only.' }),
          h('label', {}, [ h('span', { text:'API key' + (os.api_key_set ? ' (saved; leave blank to keep)' : '') }),
            h('input', { id:'os_api_key', type:'password', autocomplete:'off', placeholder: os.api_key_set ? '••••••••' : 'API key' }) ]),
          fieldEl('os_username','Username (optional)', os.username||'', 'text'),
          h('label', {}, [ h('span', { text:'Password (optional)' + (os.password_set ? ' (saved; leave blank to keep)' : '') }),
            h('input', { id:'os_password', type:'password', autocomplete:'new-password', placeholder: os.password_set ? '••••••••' : '' }) ]),
          fieldEl('os_languages','Default languages (comma separated)', os.languages||'en', 'text'),
          h('label', {}, [ h('input', { id:'os_allow_users', type:'checkbox', checked: !!os.allow_users }), ' Let non-admin users add subtitles: OpenSubtitles downloads (shared daily limit) and file uploads' ]),
          h('div', { className:'row-actions' }, [
            h('button', { className:'btn primary', text:'Save OpenSubtitles', onClick: function(){
              saveSettings({ opensubtitles: {
                api_key: $('#os_api_key').value, password: $('#os_password').value,
                username: $('#os_username').value, languages: $('#os_languages').value,
                allow_users: $('#os_allow_users').checked
              }});
            }}),
            (os.api_key_set || os.password_set) ? h('button', { className:'btn', text:'Remove saved key and password', onClick: function(){
              saveSettings({ opensubtitles: { clear_api_key:true, clear_password:true } });
            }}) : null
          ])
        ]));
      } else if (tab === 'queue') {
        body.appendChild(h('div', { className:'stack' }, [
          h('p', { className:'muted', text:'Admin → Downloads. Remove drops the item from this queue and from Transmission. Delete data also removes that torrent\'s downloaded files (only inside the download folder). Finished torrents are imported automatically about once a minute: MP4 is preferred, the file is moved into Movies / TV / Anime from how it was queued, then the torrent and leftover download data are deleted. Seeding stays off unless you turn it on under Acquisition.' }),
          h('div', { className:'row-actions' }, [
            h('button', { className:'btn primary', text:'Refresh', onClick: function(){ paintAdminQueue(true); } }),
            h('button', { className:'btn', id:'btn_process_completed', text:'Process completed (move + ingest)', onClick: function(){
              var btn = $('#btn_process_completed');
              if (btn) btn.disabled = true;
              toast('Processing completed downloads…');
              api('/admin/downloads/process-completed', { method:'POST', body:{ limit: 8, remove_torrent: true } }).then(function(r){
                if (btn) btn.disabled = false;
                if (!r.ok) return toast(r.error||'Failed', { kind:'bad' });
                toast('Processed '+(r.processed||0)+(r.cancelled?' (cancelled)':''), { kind:'ok' });
                paintAdminQueue(true);
                loadDownloadStatus(true);
              }).catch(function(e){ if (btn) btn.disabled = false; toast(String(e), { kind:'bad' }); });
            }}),
            h('button', { className:'btn ghost', text:'Cancel process', onClick: function(){
              api('/admin/downloads/process-completed/cancel', { method:'POST', body:{} }).then(function(){
                toast('Cancel requested');
              });
            }})
          ]),
          h('div', { id:'admin_queue_panel', className:'tx-panel' }, [ pendingContent({ className:'muted', }) ])
        ]));
        function removeDownloadItem(body, btn){
          var deleting = !!body.delete_local_data;
          var msg = deleting
            ? 'Remove this from the download queue and from Transmission, and delete its downloaded files?'
            : 'Remove this from the download queue and from Transmission? Downloaded files stay on disk.';
          confirmDialog(msg, { danger:true, okText: deleting ? 'Delete data' : 'Remove' }).then(function(ok){
            if (!ok) return;
            if (btn) btn.disabled = true;
            api('/admin/downloads/remove', { method:'POST', body: body }).then(function(res){
              var note = res.ok ? (deleting ? 'Removed and deleted download data' : 'Removed (files kept)') : (res.error || 'Failed');
              if (res.warnings && res.warnings.length) note += ' — ' + res.warnings.join('; ');
              toast(note, { kind: res.ok ? 'ok' : 'bad' });
              paintAdminQueue(true);
              loadDownloadStatus(true);
            }).catch(function(err){
              if (btn) btn.disabled = false;
              toast(String(err), { kind:'bad' });
            });
          });
        }
        function paintAdminQueue(force){
          api('/admin/transmission').then(function(r){
            var el = $('#admin_queue_panel'); if (!el) return;
            var tx = r.transmission || {};
            el.className = 'tx-panel' + (tx.reachable ? '' : ' offline');
            el.innerHTML = '';
            el.appendChild(h('div', { style:'display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center' }, [
              h('strong', { text:'Transmission' }),
              h('span', { className: tx.reachable ? 'ok' : 'bad', text: tx.reachable ? ('Online · '+(tx.torrent_count||0)+' torrents') : ('Offline'+(tx.error?(' — '+tx.error):'')) })
            ]));
            var torrents = tx.torrents || [];
            if (!torrents.length) {
              el.appendChild(h('p', { className:'muted', text: tx.reachable ? 'No torrents.' : 'RPC unreachable.' }));
            }
            torrents.forEach(function(t){
              el.appendChild(h('div', { className:'tx-row' }, [
                h('div', { style:'flex:1;min-width:0' }, [
                  h('div', { text: t.name || '' }),
                  h('div', { className:'muted', text: (t.status||'') + (t.eta_label ? (' · ETA '+t.eta_label) : '') + (t.error ? (' · '+t.error) : '') })
                ]),
                h('div', { className:'tx-pct', text: (t.percent != null ? t.percent+'%' : '') }),
                h('button', {
                  className:'btn ghost danger-outline',
                  text:'Remove',
                  title:'Remove from the queue and Transmission, keep files',
                  onClick: function(ev){ removeDownloadItem({ kind:'transmission', id: t.id, delete_local_data: false }, ev.currentTarget); }
                }),
                h('button', {
                  className:'btn ghost danger-outline',
                  text:'Delete data',
                  title:'Remove from the queue and Transmission and delete downloaded files',
                  onClick: function(ev){ removeDownloadItem({ kind:'transmission', id: t.id, delete_local_data: true }, ev.currentTarget); }
                })
              ]));
            });
            el.appendChild(h('hr'));
            el.appendChild(h('strong', { text:'Local acquire queue' }));
            var lq = r.local_queue || [];
            if (!lq.length) el.appendChild(h('p', { className:'muted', text:'Empty.' }));
            lq.forEach(function(e){
              el.appendChild(h('div', { className:'tx-row' }, [
                h('div', { style:'flex:1;min-width:0' }, [
                  h('div', { text: e.title || e.query || e.id }),
                  h('div', { className:'muted', text: (e.status||'') + (e.show ? (' · '+e.show) : '') + (e.season!=null ? (' '+('S'+e.season+'E'+e.episode)) : '') })
                ]),
                (e.status === 'cancelled' || e.status === 'removed' || e.status === 'done')
                  ? h('span', { className:'muted', text: e.status })
                  : h('span', { className:'row-actions' }, [
                      h('button', {
                        className:'btn ghost danger-outline',
                        text:'Remove',
                        title:'Remove from the queue and Transmission, keep files',
                        onClick: function(ev){ removeDownloadItem({ kind:'local', id: e.id, delete_local_data: false }, ev.currentTarget); }
                      }),
                      h('button', {
                        className:'btn ghost danger-outline',
                        text:'Delete data',
                        title:'Remove from the queue and Transmission and delete downloaded files',
                        onClick: function(ev){ removeDownloadItem({ kind:'local', id: e.id, delete_local_data: true }, ev.currentTarget); }
                      })
                    ])
              ]));
            });
          }).catch(function(e){
            var el = $('#admin_queue_panel');
            if (el) el.textContent = String(e);
          });
        }
        paintAdminQueue(true);
      } else if (tab === 'acquisition') {
        var a = s.acquisition || {};
        var feedsUi = feedSourcesEditor(a.feeds || []);
        setTimeout(function(){
          var info = $('#dl_dir_info'); if (!info) return;
          api('/admin/transmission/download-dir').then(function(r){
            if (!r || !r.ok) return;
            var parts = ['Saving also sets this as Transmission\u2019s download folder.'];
            if (r.transmission != null) parts.push('Transmission currently uses: ' + (r.transmission || '(not set)') + '.');
            if (r.same_volume === false) parts.push('Note: this folder is on a different volume from ' + (r.other_volume || []).join(', ') + ', so imports will copy files (slow, uses double space) instead of moving them. Put downloads on the same drive as the library to make imports instant.');
            info.textContent = parts.join(' ');
            if (r.same_volume === false) info.className = 'warn';
          }).catch(function(){});
        }, 0);
        body.appendChild(h('div', { className:'stack' }, [
          h('label', {}, [ h('input', { id:'acq_en', type:'checkbox', checked: !!a.enabled }), ' Enable RSS acquisition' ]),
          h('label', {}, [ h('input', { id:'rss_fb', type:'checkbox', checked: a.rss_fallback_search!==false }), ' Enable RSS-fallback search (after weak catalogue results)' ]),
          fieldEl('min_seeds','Min seeds to show', a.min_seeds!=null?a.min_seeds:1, 'number'),
          fieldEl('preferred_qualities','Preferred qualities (comma)', (a.preferred_qualities||['1080p','720p']).join(',')),
          fieldEl('preferred_containers','Preferred containers (comma; soft-ranked on RSS search)', (a.preferred_containers||['mp4']).join(',')),
          fieldEl('language_filters','Language filters (comma, empty=any)', (a.language_filters||[]).join(',')),
          fieldEl('min_interval_seconds','Min interval (sec; 0=no wait)', a.min_interval_seconds!=null?a.min_interval_seconds:120, 'number'),
          fieldEl('max_per_hour','Max fetches / hour', a.max_per_hour!=null?a.max_per_hour:20, 'number'),
          fieldEl('jitter_seconds','Jitter (sec; only when min interval > 0)', a.jitter_seconds!=null?a.jitter_seconds:30, 'number'),
          h('label', { className:'check-label' }, [ h('input', { id:'acq_auto_import', type:'checkbox', checked: a.auto_process_completed!==false }), ' Auto-import finished torrents into the library (MP4 preferred; removes torrent and leftover download data)' ]),
          h('h3', { className:'admin-section-title', text:'Transmission (optional download client)' }),
          h('p', { className:'muted', text:'Downloads use Transmission plus PHP cURL; qBittorrent is not supported. The Windows installer and Linux setup install and connect Transmission automatically. On other servers, enter your Transmission RPC URL and credentials below. No download client is needed for your existing library.' }),
          h('a', { href:'https://github.com/transmission/transmission/blob/main/docs/Web-Interface.md', target:'_blank', rel:'noopener', text:'Transmission remote-access instructions' }),
          h('label', { className:'check-label' }, [ h('input', { id:'tr_en', type:'checkbox', checked: !!(a.transmission||{}).enabled }), ' Transmission enabled' ]),
          fieldEl('tr_url','RPC URL', (a.transmission||{}).url||''),
          fieldEl('tr_user','Username', (a.transmission||{}).username||''),
          fieldEl('tr_pass','Password', (a.transmission||{}).password||'', 'password'),
          fieldEl('download_path','Download folder', a.download_path||''),
          folderPicker('download_path'),
          h('p', { id:'dl_dir_info', className:'muted', text:'Saving also sets this as Transmission\u2019s download folder.' }),
          h('p', { className:'muted', text:'For a local client the usual RPC URL is http://127.0.0.1:9091/transmission/rpc. The download folder must be accessible to both Transmission and Vaultcast. The connection check uses the values in this form without saving them.' }),
          h('button', { className:'btn', text:'Check Transmission connection', onClick:function(ev){
            var button=ev.currentTarget; button.disabled=true;
            api('/admin/transmission/check', {method:'POST',body:{url:$('#tr_url').value,username:$('#tr_user').value,password:$('#tr_pass').value}}).then(function(r){
              toast(r.message || r.error || 'Connection failed', {kind:r.ok?'ok':'bad'});
            }).catch(function(e){toast(String(e),{kind:'bad'});}).finally(function(){button.disabled=false;});
          }}),
          h('h3', { className:'admin-section-title', text:'Seeding (upload after download)' }),
          h('p', { className:'muted', text:'Default is no seeding: ratio 0 and idle seed time 0 so Transmission stops when the download finishes. Turn on “Allow seeding” only if you want to share.' }),
          h('label', { className:'check-label' }, [ h('input', { id:'tr_allow_seed', type:'checkbox', checked: !!(a.transmission||{}).allow_seeding }), ' Allow seeding after download completes' ]),
          fieldEl('tr_seed_ratio','Seed until ratio (only if seeding allowed; 0 = unlimited)', (a.transmission||{}).seed_ratio_limit!=null?(a.transmission||{}).seed_ratio_limit:0, 'number'),
          fieldEl('tr_seed_idle','Seed idle minutes (only if seeding allowed; 0 = unlimited)', (a.transmission||{}).seed_idle_minutes!=null?(a.transmission||{}).seed_idle_minutes:0, 'number'),
          h('label', { className:'check-label' }, [ h('input', { id:'tr_seed_existing', type:'checkbox', checked: (a.transmission||{}).apply_to_existing!==false }), ' Also apply policy to existing torrents on save' ]),
          h('h3', { className:'admin-section-title', text:'Stalled downloads' }),
          h('p', { className:'muted', text:'Only torrents Vaultcast queued are checked, each time the download worker runs. A stalled torrent is removed with its data, its hash is blacklisted for that item, and the best-seeded other result is queued instead.' }),
          h('label', { className:'check-label' }, [ h('input', { id:'stall_en', type:'checkbox', checked: (a.stall||{}).enabled!==false }), ' Replace stalled downloads automatically' ]),
          fieldEl('stall_min','Stalled after minutes without progress', (a.stall||{}).minutes!=null?(a.stall||{}).minutes:30, 'number'),
          fieldEl('stall_peer_min','Stalled after minutes with no peers (after metadata)', (a.stall||{}).no_peer_minutes!=null?(a.stall||{}).no_peer_minutes:15, 'number'),
          fieldEl('stall_max','Max attempts per item', (a.stall||{}).max_attempts!=null?(a.stall||{}).max_attempts:3, 'number'),
          h('h3', { className:'admin-section-title', text:'Feeds' }),
          h('p', { className:'muted', text:'Tick the sources to use. Only add feeds you have the right to download from. Search feeds need {query} (or {q}) in the URL. A user search uses one rate-limit slot per click, and nothing polls while idle.' }),
          feedsUi.el,
          h('div', { className:'row-actions' }, [
            h('button', { className:'btn primary', text:'Save', onClick: function(){
              var feedsArr = feedsUi.value(); if (!feedsArr) return;
              function split(v){ return String(v||'').split(',').map(function(x){return x.trim();}).filter(Boolean); }
              saveSettings({ acquisition: {
                enabled: $('#acq_en').checked,
                rss_fallback_search: $('#rss_fb').checked,
                min_seeds: +$('#min_seeds').value,
                preferred_qualities: split($('#preferred_qualities').value),
                preferred_containers: split($('#preferred_containers').value),
                language_filters: split($('#language_filters').value),
                min_interval_seconds: +$('#min_interval_seconds').value,
                max_per_hour: +$('#max_per_hour').value,
                jitter_seconds: +$('#jitter_seconds').value,
                download_path: $('#download_path').value,
                auto_process_completed: $('#acq_auto_import').checked,
                stall: {
                  enabled: $('#stall_en').checked,
                  minutes: Math.max(5, +$('#stall_min').value || 30),
                  no_peer_minutes: Math.max(5, +$('#stall_peer_min').value || 15),
                  max_attempts: Math.max(1, +$('#stall_max').value || 3)
                },
                feeds: feedsArr,
                transmission: {
                  enabled: $('#tr_en').checked,
                  url: $('#tr_url').value,
                  username: $('#tr_user').value,
                  password: $('#tr_pass').value,
                  allow_seeding: $('#tr_allow_seed').checked,
                  seed_ratio_limit: +$('#tr_seed_ratio').value,
                  seed_idle_minutes: +$('#tr_seed_idle').value,
                  apply_to_existing: $('#tr_seed_existing').checked
                }
              }, _apply_transmission_seeding: true });
            }}),
            h('button', { className:'btn', text:'Fetch feeds now', onClick: function(){
              confirmDialog('Fetch configured RSS feeds now? (rate limits still apply)', { okText:'Fetch now' }).then(function(ok){
                if (!ok) return;
                api('/admin/acquisition/fetch',{method:'POST'}).then(function(r){ toast(r.ok?('Found '+(r.count||0)+' items'):(r.reason||r.error||'Failed')); });
              });
            }}),
            h('button', { className:'btn', text:'Open download queue', onClick: function(){ state.adminTab='queue'; render(); } })
          ])
        ]));
      } else if (tab === 'convert' || tab === 'video-optimise') {
        body.appendChild(renderConvertAdminPanel(s));
      } else if (tab === 'poster-optimise') {
        body.appendChild(h('div', { id:'poster-optimise-panel', className:'convert-admin po-admin' }));
        setTimeout(function(){ renderPosterOptimisePanel(); }, 0);
      } else if (tab === 'optimise-system') {
        body.appendChild(h('div', { id:'optimise-system-panel', className:'convert-admin po-admin' }));
        setTimeout(function(){ renderOptimiseSystemPanel(); }, 0);
      } else if (tab === 'collections') {
        body.appendChild(renderCollectionsAdmin(s));
      } else if (tab === 'users') {
        var nuAccess = accessPeriodSelect('pending');
        var nuDate = h('input', { type:'date', className:'ua-date', hidden:true, 'aria-label':'Custom expiry date' });
        nuAccess.addEventListener('change', function(){ nuDate.hidden = nuAccess.value !== 'custom'; });
        body.appendChild(h('div', { className:'stack ua-root' }, [
          h('div', { className:'settings-card stack' }, [
            h('h3', { className:'admin-section-title', text:'Add user' }),
            fieldEl('nu_user','Username',''),
            fieldEl('nu_pass','Password','','password'),
            h('label', {}, [ 'Role', h('select', { id:'nu_role' }, [ h('option', { value:'user', text:'User' }), h('option', { value:'admin', text:'Admin (always has access)' }) ]) ]),
            h('label', {}, [ 'Access', h('div', { className:'ua-controls' }, [ nuAccess, nuDate ]) ]),
            h('div', { className:'row-actions' }, [ h('button', { className:'btn primary', text:'Add user', onClick: function(){
              api('/admin/users',{method:'POST', body:{ username:$('#nu_user').value, password:$('#nu_pass').value, role:$('#nu_role').value, access: nuAccess.value, access_date: nuDate.value || null }}).then(function(r){
                toast(r.ok?'User added':(r.error||'Failed'), { kind: r.ok ? 'ok' : 'bad' }); if (r.ok) { $('#nu_user').value=''; $('#nu_pass').value=''; } loadUsers();
              });
            }}) ])
          ]),
          h('div', {id:'role_list',className:'settings-card stack'}), h('div', { id:'user_list' })
        ]));
        loadUsers();
      } else if (tab === 'scan') {
        var scan = s.scan || {};
        body.appendChild(h('div', { className:'stack admin-stack' }, [
          h('div', { className:'settings-card stack' }, [
            h('h3', { className:'admin-section-title', text:'Library rescan' }),
            h('p', { className:'muted', text:'Rescan library folders into the RAM-resident catalogue. Disk is not touched again until the next rescan, settings change, or library rename. Poster fetching lives under Library → Posters.' }),
            h('div', { className:'row-actions' }, [
              h('button', { className:'btn primary', text:'Rescan now', onClick: function(){
                confirmDialog('Rescan all library folders now? This only runs when you ask.', { okText:'Rescan' }).then(function(ok){
                  if (!ok) return;
                  toast('Scanning…');
                  api('/admin/rescan',{method:'POST'}).then(function(r){
                    if (!r.ok) return toast(r.error||'Scan failed', { kind:'bad' });
                    toast('Scanned '+r.item_count+' items', { kind:'ok' });
                    var meta = $('#scan_meta');
                    if (meta) meta.textContent = 'scanned_at: '+(r.scanned_at || (state.home&&state.home.scanned_at) || 'just now')+' · items '+(r.item_count||'?');
                    return api('/home').then(function(hh){ state.home=hh; });
                  });
                });
              }})
            ]),
            h('pre', { id:'scan_meta', className:'muted', text: 'scanned_at: '+(state.home&&state.home.scanned_at) })
          ]),
          h('div', { className:'settings-card stack' }, [
            h('h3', { className:'admin-section-title', text:'Scan extensions' }),
            h('p', { className:'muted', text:'Which file types the catalogue walker includes, and how deep it descends.' }),
            fieldEl('video_ext','Video extensions (comma)', (scan.video_extensions||[]).join(',')),
            fieldEl('audio_ext','Audio extensions (comma)', (scan.audio_extensions||[]).join(',')),
            fieldEl('rom_ext','ROM extensions (comma)', (scan.rom_extensions||[]).join(',')),
            fieldEl('max_depth','Max scan depth', scan.max_depth||8, 'number'),
            h('button', { className:'btn primary', text:'Save scan settings', onClick: function(){
              function split(v){ return v.split(',').map(function(x){return x.trim();}).filter(Boolean); }
              saveSettings({ scan: {
                video_extensions: split($('#video_ext').value),
                audio_extensions: split($('#audio_ext').value),
                rom_extensions: split($('#rom_ext').value),
                max_depth: +$('#max_depth').value
              }});
            }})
          ])
        ]));
      } else if (tab === 'posters') {
        body.appendChild(renderPostersAdmin(s));
      } else if (tab === 'advanced') {
        var resetPassword=h('input',{type:'password',autocomplete:'current-password','aria-label':'Administrator password'});
        var resetPhrase=h('input',{type:'text',autocomplete:'off',spellcheck:false,placeholder:'RESET VAULTCAST','aria-label':'Reset confirmation phrase'});
        var resetStatus=h('p',{className:'muted',role:'status'});
        var resetButton=h('button',{className:'btn danger',type:'button',text:'Reset Vaultcast',disabled:true,onClick:function(){
          resetButton.disabled=true;resetStatus.textContent='Resetting Vaultcast…';
          api('/admin/factory-reset',{method:'POST',body:{password:resetPassword.value,confirmation:resetPhrase.value}}).then(function(j){
            resetPassword.value='';
            if(!j.ok)throw new Error(j.error||'Reset failed');
            location.assign(j.redirect);
          }).catch(function(e){resetStatus.textContent=e.message;resetButton.disabled=true;});
        }});
        function resetReady(){resetButton.disabled=resetPhrase.value!=='RESET VAULTCAST'||!resetPassword.value;}
        resetPassword.oninput=resetPhrase.oninput=resetReady;
        var dragons=h('details',{className:'settings-card'},[
          h('summary',{text:'Here be dragons'}),
          h('h3',{text:'Start fresh'}),
          h('p',{text:'Permanently erase library records, settings, matches, watch history, favourites, collections, social activity, cloud save records and all user accounts. Your administrator account is recreated with its current username and password. Everyone else is signed out and removed.'}),
          h('p',{text:'Media files, posters and other files on disk are untouched. Cloud saves lose their records and shared links stop working. Database connection details and installed server tools are kept. You will return to library folder settings with no folders configured.'}),
          h('p',{text:'This cannot be undone. Stop playback and background tasks first. Take a database backup if you may need this data again.'}),
          h('label',{},['Your administrator password',resetPassword]),
          h('label',{},['Type RESET VAULTCAST to confirm',resetPhrase]),resetButton,resetStatus
        ]);
        body.appendChild(dragons);
        var lib = s.library || {};
        var diag = s.diagnostics || {};
        var rootsTa = h('textarea', { id:'lib_roots', rows:'4', style:'width:100%;font-family:monospace' });
        rootsTa.value = JSON.stringify(lib.scan_roots||[], null, 2);
        body.appendChild(h('div', { className:'stack' }, [
          h('h3', { text:'Library orphan scan' }),
          h('p', { className:'muted', text:'Extra roots scanned by Find / Rename (in addition to your library folders and download path).' }),
          h('label', {}, ['Extra scan roots JSON (array of paths)', rootsTa]),
          fieldEl('orphan_depth','Orphan max depth', lib.orphan_max_depth||6, 'number'),
          h('label', {}, [ h('input', { id:'diag_en', type:'checkbox', checked: diag.enable_tests!==false }), ' Enable diagnostic tests' ]),
          h('button', { className:'btn primary', text:'Save advanced', onClick: function(){
            var roots; try { roots = JSON.parse(rootsTa.value); } catch(e){ return toast('Bad roots JSON'); }
            saveSettings({
              library: { scan_roots: roots, orphan_max_depth: +$('#orphan_depth').value },
              diagnostics: { enable_tests: $('#diag_en').checked }
            });
          }}),
          h('hr'),
          h('h3', { text:'Raw settings JSON' }),
          h('p', { className:'muted', text:'Full settings dump — edit carefully.' }),
          (function(){
            var ta = h('textarea', { id:'raw_settings', rows:'16', style:'width:100%;font-family:monospace' });
            ta.value = JSON.stringify(s, null, 2);
            return ta;
          })(),
          h('button', { className:'btn', text:'Save raw JSON', onClick: function(){
            try {
              var parsed = JSON.parse($('#raw_settings').value);
              api('/admin/settings', { method:'POST', body: parsed }).then(function(r){
                toast(r.ok?'Raw settings saved':(r.error||'Failed'));
                if (r.settings) state.settings = r.settings;
              });
            } catch(e){ toast('Invalid JSON'); }
          }})
        ]));
      }
    });
  }


  /* dedicated Posters settings — coverage, progress, multi-kind fetch */
  var _posterFetchRun = { active:false, stop:false, totals:{fetched:0, failed:0, batches:0} };
  function posterCovCard(label, row){
    row = row || {};
    var total = +row.total || 0, have = +row.have || 0, missing = +row.missing || 0;
    var pct = total ? Math.round(100 * have / total) : 100;
    return h('div', { className:'poster-cov-card settings-card' }, [
      h('div', { className:'sys-label', text: label }),
      h('div', { className:'sys-value', text: have + ' / ' + total }),
      h('div', { className:'muted', text: missing ? (missing + ' missing · ' + pct + '%') : ('Complete · ' + pct + '%') }),
      h('div', { className:'poster-progress', 'aria-hidden':'true' }, [
        h('i', { style: 'width:' + pct + '%' })
      ])
    ]);
  }
  function paintPosterCoverage(cov){
    var grid = $('#poster_cov_grid');
    var summary = $('#poster_cov_summary');
    var sample = $('#poster_miss_sample');
    if (!cov || !cov.ok) {
      if (summary) summary.textContent = (cov && cov.error) ? cov.error : 'Could not load coverage';
      return;
    }
    var t = cov.totals || {};
    if (summary) {
      summary.textContent = 'Coverage ' + (t.pct != null ? t.pct : '?') + '% · '
        + (t.have||0) + ' with art / ' + (t.total||0) + ' titles · '
        + (t.missing||0) + ' still missing'
        + (cov.omdb_configured ? '' : ' · OMDb key not set (movies use Wikipedia fallback)')
        + ' · cache files ' + (cov.cache_files||0);
    }
    if (grid) {
      grid.innerHTML = '';
      grid.appendChild(posterCovCard('TV / Anime shows', cov.shows));
      grid.appendChild(posterCovCard('Movies / Disney', cov.movies));
      grid.appendChild(posterCovCard('Games / ROMs', cov.games));
    }
    if (sample) {
      var rows = cov.missing_sample || [];
      sample.textContent = rows.length
        ? rows.map(function(r){ return (r.kind||'?') + ': ' + (r.title||''); }).join('\n')
        : 'No missing titles in sample — nice.';
    }
  }
  function refreshPosterCoverage(){
    var summary = $('#poster_cov_summary');
    if (summary) summary.textContent = 'Checking coverage…';
    return api('/admin/posters/status').then(function(r){
      paintPosterCoverage(r);
      return r;
    }).catch(function(e){
      paintPosterCoverage({ ok:false, error:String(e) });
    });
  }
  function setPosterFetchStatus(text, kind){
    var el = $('#poster_fetch_status');
    if (!el) return;
    el.textContent = text || '';
    el.className = 'poster-fetch-status' + (kind ? (' ' + kind) : '');
  }
  function posterKindsFromUi(){
    var kinds = [];
    if ($('#pk_shows') && $('#pk_shows').checked) kinds.push('shows');
    if ($('#pk_movies') && $('#pk_movies').checked) kinds.push('movies');
    if ($('#pk_games') && $('#pk_games').checked) kinds.push('games');
    if (!kinds.length) kinds = ['shows','movies','games'];
    return kinds;
  }
  function runPosterFetchBatch(opts){
    opts = opts || {};
    var limit = opts.limit || 8;
    var kinds = opts.kinds || posterKindsFromUi();
    setPosterFetchStatus('Fetching batch (limit ' + limit + ', ' + kinds.join('+') + ')…', 'busy');
    return api('/admin/posters/fetch', { method:'POST', body:{ limit:limit, kinds:kinds } }).then(function(r){
      if (!r || r._status === 502) {
        setPosterFetchStatus('Batch timed out (502) — try again; batches stay small on purpose', 'bad');
        return r;
      }
      if (!r.ok) {
        setPosterFetchStatus(r.error || 'Fetch failed', 'bad');
        return r;
      }
      _posterFetchRun.totals.fetched += (r.fetched||0);
      _posterFetchRun.totals.failed += (r.failed && r.failed.length) || 0;
      _posterFetchRun.totals.batches += 1;
      if (r.coverage) paintPosterCoverage(r.coverage);
      else refreshPosterCoverage();
      var rem = (r.remaining != null) ? r.remaining : '?';
      var fetchable = (r.remaining_fetchable != null) ? r.remaining_fetchable : rem;
      var msg = 'Batch: fetched ' + (r.fetched||0)
        + ', skipped ' + (r.skipped_existing||0)
        + (r.skipped_recent_fail ? (', defer ' + r.skipped_recent_fail) : '')
        + ', fail ' + ((r.failed && r.failed.length) || 0)
        + ' · missing ~' + rem
        + ' · still trying ~' + fetchable
        + (r.timed_out ? ' · time budget hit' : '')
        + ' · ' + (r.elapsed_ms||0) + 'ms';
      if (r.items && r.items.length) {
        msg += '\nGot: ' + r.items.map(function(it){ return (it.kind||'?') + ' ' + (it.title||''); }).join(', ');
      }
      if (r.failed && r.failed.length) {
        msg += '\nMisses: ' + r.failed.slice(0, 8).join(', ');
      }
      setPosterFetchStatus(msg, (r.fetched ? 'ok' : (r.failed && r.failed.length ? 'bad' : '')));
      return r;
    }).catch(function(e){
      setPosterFetchStatus(String(e), 'bad');
      throw e;
    });
  }
  function stopPosterFetchLoop(){
    _posterFetchRun.stop = true;
    setPosterFetchStatus('Stop requested — finishing current batch…', 'busy');
  }
  function startPosterFetchLoop(){
    if (_posterFetchRun.active) return toast('Poster fetch already running', { kind:'bad' });
    _posterFetchRun.active = true;
    _posterFetchRun.stop = false;
    _posterFetchRun.totals = { fetched:0, failed:0, batches:0 };
    var btnGo = $('#poster_fetch_loop');
    var btnStop = $('#poster_fetch_stop');
    if (btnGo) btnGo.disabled = true;
    if (btnStop) btnStop.disabled = false;
    setPosterFetchStatus('Starting continuous fetch…', 'busy');
    function tick(){
      if (_posterFetchRun.stop) {
        _posterFetchRun.active = false;
        if (btnGo) btnGo.disabled = false;
        if (btnStop) btnStop.disabled = true;
        setPosterFetchStatus(
          'Stopped. Session: ' + _posterFetchRun.totals.fetched + ' fetched across '
          + _posterFetchRun.totals.batches + ' batches'
          + (_posterFetchRun.totals.failed ? (', ' + _posterFetchRun.totals.failed + ' misses') : ''),
          'ok'
        );
        refreshPosterCoverage();
        return;
      }
      runPosterFetchBatch({ limit: 8 }).then(function(r){
        if (!r || !r.ok) {
          _posterFetchRun.active = false;
          if (btnGo) btnGo.disabled = false;
          if (btnStop) btnStop.disabled = true;
          return;
        }
        var remaining = (r.remaining != null) ? +r.remaining : 0;
        var fetchable = (r.remaining_fetchable != null) ? +r.remaining_fetchable : remaining;
        // prefer remaining_fetchable (skips recent hard-fails) so Keep fetching advances
        var more = (r.more != null) ? !!r.more : (fetchable > 0 || !!r.timed_out);
        if (fetchable <= 0 && !r.timed_out) {
          _posterFetchRun.active = false;
          if (btnGo) btnGo.disabled = false;
          if (btnStop) btnStop.disabled = true;
          setPosterFetchStatus(
            'Done. Session: ' + _posterFetchRun.totals.fetched + ' fetched across '
            + _posterFetchRun.totals.batches + ' batches · coverage missing ~' + remaining
            + (r.skipped_recent_fail ? (' · skipped ' + r.skipped_recent_fail + ' recent fails') : ''),
            'ok'
          );
          toast('Poster scan finished — ' + _posterFetchRun.totals.fetched + ' new', { kind:'ok' });
          refreshPosterCoverage();
          return;
        }
        if (!more && (r.fetched||0) === 0 && !r.timed_out) {
          _posterFetchRun.active = false;
          if (btnGo) btnGo.disabled = false;
          if (btnStop) btnStop.disabled = true;
          setPosterFetchStatus(
            'Done. Session: ' + _posterFetchRun.totals.fetched + ' fetched across '
            + _posterFetchRun.totals.batches + ' batches · remaining ~' + remaining,
            'ok'
          );
          toast('Poster scan finished — ' + _posterFetchRun.totals.fetched + ' new', { kind:'ok' });
          refreshPosterCoverage();
          return;
        }
        setTimeout(tick, 350);
      }).catch(function(){
        _posterFetchRun.active = false;
        if (btnGo) btnGo.disabled = false;
        if (btnStop) btnStop.disabled = true;
      });
    }
    tick();
  }
  function renderPostersAdmin(s){
    var posters = (s && s.posters) || {};
    var wrap = h('div', { className:'stack admin-stack posters-admin' });
    wrap.appendChild(h('div', { className:'settings-card stack' }, [
      h('h3', { className:'admin-section-title', text:'Remote posters' }),
      h('p', { className:'muted', text:'Movies/Disney: OMDb (when key set) → Wikipedia. TV/Anime: TVMaze with cleaned-title + search fallback (respects Fix matching pins). Games: libretro Named_Boxarts via GitHub (thumbnails.libretro.com is down) → Wikipedia. Hard misses are deferred 7 days so Keep fetching can walk the whole library. Local poster.jpg / folder.jpg always wins; remote art is cached on the server.' }),
      h('label', { className:'check-label' }, [ h('input', { id:'poster_en', type:'checkbox', checked: posters.enabled!==false }), ' Enable remote posters' ]),
      fieldEl('poster_provider','Provider', posters.provider||'omdb+tvmaze'),
      fieldEl('poster_omdb','OMDb API key', posters.omdb_api_key||''),
      fieldEl('poster_tmdb','TMDB API key (optional: Find collections)', posters.tmdb_api_key||''),
      fieldEl('poster_rate','Rate limit / sec', posters.rate_limit_per_sec||2, 'number'),
      h('p', {className:'muted',text:'Artwork cache: data/posters/ (managed by Vaultcast)'}),
      h('button', { className:'btn primary', text:'Save poster settings', onClick: function(){
        saveSettings({ posters: {
          enabled: $('#poster_en').checked,
          provider: $('#poster_provider').value,
          omdb_api_key: ($('#poster_omdb').value||'').trim(),
          tmdb_api_key: ($('#poster_tmdb').value||'').trim(),
          rate_limit_per_sec: +$('#poster_rate').value
        }});
        setTimeout(refreshPosterCoverage, 400);
      }})
    ]));
    wrap.appendChild(h('div', { className:'settings-card stack' }, [
      h('h3', { className:'admin-section-title', text:'Coverage' }),
      pendingContent({ id:'poster_cov_summary', className:'muted', }),
      h('div', { id:'poster_cov_grid', className:'poster-cov-grid' }),
      h('h4', { text:'Missing sample' }),
      h('pre', { id:'poster_miss_sample', className:'muted poster-miss-sample', text:'…' }),
      h('button', { className:'btn', text:'Refresh coverage', onClick: function(){ refreshPosterCoverage(); } })
    ]));
    wrap.appendChild(h('div', { className:'settings-card stack' }, [
      h('h3', { className:'admin-section-title', text:'Fetch missing' }),
      h('p', { className:'muted', text:'Batches stay small and time-budgeted so the server never 502s. Use “Keep fetching” to walk the whole library; progress and remaining counts update after every batch.' }),
      h('div', { className:'row-actions poster-kind-checks' }, [
        h('label', { className:'check-label' }, [ h('input', { id:'pk_shows', type:'checkbox', checked:true }), ' Shows (TV/Anime)' ]),
        h('label', { className:'check-label' }, [ h('input', { id:'pk_movies', type:'checkbox', checked:true }), ' Movies / Disney' ]),
        h('label', { className:'check-label' }, [ h('input', { id:'pk_games', type:'checkbox', checked:true }), ' Games / ROMs' ])
      ]),
      h('div', { className:'row-actions' }, [
        h('button', { className:'btn primary', text:'Fetch batch (8)', onClick: function(){
          if (_posterFetchRun.active) return toast('Continuous fetch is running — stop it first', { kind:'bad' });
          runPosterFetchBatch({ limit:8 }).then(function(r){
            if (r && r.ok) toast('Fetched '+(r.fetched||0)+' · remaining ~'+(r.remaining!=null?r.remaining:'?'), { kind:'ok' });
          });
        }}),
        h('button', { id:'poster_fetch_loop', className:'btn', text:'Keep fetching until done', onClick: function(){ startPosterFetchLoop(); } }),
        h('button', { id:'poster_fetch_stop', className:'btn ghost', text:'Stop', disabled:true, onClick: function(){ stopPosterFetchLoop(); } })
      ]),
      h('pre', { id:'poster_fetch_status', className:'poster-fetch-status muted', text:'Idle — refresh coverage, then fetch a batch or run until done.' })
    ]));
    setTimeout(refreshPosterCoverage, 0);
    return wrap;
  }

  // Server-side folder picker for library paths: lists drives/subfolders via /admin/folders.
  function folderPicker(inputId){
    var panel = h('div', { className:'folder-picker', hidden:true });
    function load(path){
      panel.innerHTML = '';
      panel.appendChild(pendingContent({ className:'muted', }));
      api('/admin/folders?path='+encodeURIComponent(path||'')).then(function(r){
        panel.innerHTML = '';
        var here = r.path || '';
        panel.appendChild(h('div', { className:'folder-picker-path', text: here || 'Drives and roots' }));
        if (!r.ok) panel.appendChild(h('p', { className:'folder-picker-error', role:'alert', text: r.error || 'Cannot open this folder.' }));
        var list = h('ul', { className:'folder-picker-list' });
        if (r.parent) list.appendChild(h('li', {}, [ h('button', { type:'button', className:'btn ghost', text:'↑ Up', onClick: function(){ load(r.parent); } }) ]));
        if (here && r.parent == null) list.appendChild(h('li', {}, [ h('button', { type:'button', className:'btn ghost', text:'↑ Drives', onClick: function(){ load(''); } }) ]));
        (r.folders||[]).forEach(function(f){
          list.appendChild(h('li', {}, [ h('button', { type:'button', className:'btn ghost', text:f.name, onClick: function(){ load(f.path); } }) ]));
        });
        if (r.ok && here && !(r.folders||[]).length) list.appendChild(h('li', { className:'muted', text:'No subfolders.' }));
        panel.appendChild(list);
        if (r.truncated) panel.appendChild(h('p', { className:'muted', text:'Showing the first 1000 folders.' }));
        panel.appendChild(h('div', { className:'row-actions' }, [
          h('button', { type:'button', className:'btn primary', text:'Use this folder', disabled: !(r.ok && here), onClick: function(){
            var input = $('#'+inputId); if (input) input.value = here; panel.hidden = true;
          }}),
          h('button', { type:'button', className:'btn ghost', text:'Close', onClick: function(){ panel.hidden = true; } })
        ]));
      });
    }
    var button = h('button', { type:'button', className:'btn ghost', text:'Browse server folders…', onClick: function(){
      panel.hidden = !panel.hidden;
      if (!panel.hidden) load((($('#'+inputId)||{}).value||'').trim());
    }});
    return h('div', { className:'stack' }, [ h('div', { className:'row-actions' }, [ button ]), panel ]);
  }

  function renderSectionsEditor(s){
    var wrap = h('div', { className:'stack' });
    wrap.appendChild(h('p', { className:'muted', text:'Configure library folders (Movies, TV, Anime, Disney, ROMs, …). Changes save to settings; run Scan afterwards.' }));
    var list = h('div', { id:'sec_list', className:'stack' });
    function redraw(sections){
      list.innerHTML = '';
      (sections||[]).forEach(function(sec, idx){
        var box = h('div', { className:'stack', style:'border:1px solid var(--border);padding:12px;border-radius:8px' }, [
          h('div', { className:'row-actions' }, [
            h('strong', { text: (sec.label||sec.id||'Section') + ' ('+(sec.id||'')+')' }),
            h('label', {}, [ h('input', { type:'checkbox', checked: !!sec.enabled, onChange: function(e){ sec.enabled = e.target.checked; } }), ' Enabled' ])
          ]),
          fieldEl('sec_label_'+idx, 'Label', sec.label||''),
          fieldEl('sec_path_'+idx, 'Path', sec.path||''),
          folderPicker('sec_path_'+idx),
          h('label', {}, ['Media type', h('select', {id:'sec_type_'+idx}, [['movies','Films'],['tv','TV shows'],['anime','Anime'],['music','Music'],['roms','Games'],['disney','Films (legacy collection)']].map(function(t){return h('option',{value:t[0],text:t[1],selected:sec.type===t[0]});}))]),
          fieldEl('sec_order_'+idx, 'Order', sec.order||0, 'number'),
          fieldEl('sec_excl_'+idx, 'Exclude paths (comma)', (sec.exclude_paths||[]).join(', ')),
          h('button', { className:'btn ghost', text:'Remove', onClick: function(){
            sections.splice(idx,1); redraw(sections);
          }})
        ]);
        list.appendChild(box);
      });
    }
    var sections = JSON.parse(JSON.stringify(s.sections||[]));
    redraw(sections);
    wrap.appendChild(list);
    wrap.appendChild(h('div', { className:'row-actions' }, [
      h('button', { className:'btn', text:'Add section', onClick: function(){
        sections.push({ id:'section_'+Date.now().toString(36), label:'New section', type:'movies', path:'', enabled:true, order: sections.length+1, exclude_paths:[] });
        redraw(sections);
      }}),
      h('button', { className:'btn primary', text:'Save sections', onClick: function(){
        var out = sections.map(function(sec, idx){
          var label = ($('#sec_label_'+idx)||{}).value;
          var p = ($('#sec_path_'+idx)||{}).value;
          var t = ($('#sec_type_'+idx)||{}).value;
          var ord = +(($('#sec_order_'+idx)||{}).value||0);
          var excl = (($('#sec_excl_'+idx)||{}).value||'').split(',').map(function(x){return x.trim();}).filter(Boolean);
          var id = sec.id || ('sec_'+idx);
          if (label) sec.label = label;
          if (p!=null) sec.path = p;
          if (t) sec.type = t;
          sec.order = ord;
          sec.exclude_paths = excl;
          sec.id = id;
          return sec;
        });
        saveSettings({ sections: out });
      }})
    ]));
    var ta = h('textarea', { id:'sections_json', rows:'10', style:'width:100%;font-family:monospace' });
    ta.value = JSON.stringify(s.sections||[], null, 2);
    wrap.appendChild(h('details', {}, [
      h('summary', { text:'Edit as JSON' }),
      ta,
      h('button', { className:'btn', text:'Save JSON', onClick: function(){
        try { saveSettings({ sections: JSON.parse(ta.value) }); }
        catch(e){ toast('Invalid JSON'); }
      }})
    ]));
    return wrap;
  }

  function renderLibraryManager(s){
    var wrap = h('div', { className:'stack' });
    wrap.appendChild(libraryQueryPanel());
    wrap.appendChild(h('h3', { text:'Find misplaced / misnamed media' }));
    wrap.appendChild(h('p', { className:'muted', text:'Scan downloads & Transmission for files not yet in the catalogue. Suggest show/movie match (TVMaze for TV), preview standard rename, then move into library paths. Incremental catalogue update — no full rescan required.' }));
    wrap.appendChild(h('div', { className:'row-actions' }, [
      fieldEl('orphan_limit','Limit', 50, 'number'),
      h('label', {}, [ h('input', { id:'incl_sec', type:'checkbox' }), ' Include section roots' ]),
      h('button', { className:'btn primary', text:'Scan orphans', onClick: function(){ scanOrphans(); } })
    ]));
    wrap.appendChild(h('div', { id:'orphan_status', className:'muted', text:'Idle' }));
    wrap.appendChild(h('div', { id:'orphan_list', className:'stack' }));
    wrap.appendChild(h('div', { id:'orphan_editor', className:'stack', style:'display:none;border:1px solid var(--border);padding:12px;border-radius:8px;margin-top:8px' }));
    return wrap;
  }

  /* Find & rename → query the library: terms (wildcards/fuzzy) → pick items → fix → preview → apply */
  function libraryQueryPanel(){
    var box = h('div', { className:'stack' });
    var q = h('textarea', { rows:'3', placeholder:'One name or term per line, e.g.\nDragon*Kai\nthe matrx', style:'width:100%' });
    var fieldBoxes = {};
    var fieldRow = h('div', { className:'row-actions' }, [['title','Titles'],['file','File names'],['show','Shows'],['episode_title','Episodes']].map(function(f){
      fieldBoxes[f[0]] = h('input', { type:'checkbox', checked:true });
      return h('label', { className:'check-label' }, [ fieldBoxes[f[0]], ' ' + f[1] ]);
    }));
    var status = h('div', { className:'muted' });
    var results = h('div', { className:'stack' });
    var fixHost = h('div', { className:'stack' });
    var found = [], picks = {};
    function search(){
      var fields = Object.keys(fieldBoxes).filter(function(k){ return fieldBoxes[k].checked; });
      status.textContent = 'Searching…'; results.innerHTML = ''; fixHost.innerHTML = '';
      api('/admin/library/query', { method:'POST', body:{ q:q.value, fields:fields } }).then(function(r){
        if (!r.ok) { status.textContent = r.error || 'Search failed'; return; }
        found = r.items || []; picks = {};
        status.textContent = r.total + ' match' + (r.total === 1 ? '' : 'es') + (r.truncated ? ' (showing the first ' + found.length + ')' : '') + ' · ' + r.terms.map(function(t){ return t + ': ' + (r.counts[t] || 0); }).join(' · ');
        paintResults();
      });
    }
    function label(x){
      if (x.type === 'tv' || x.type === 'anime') return (x.show || '') + (x.season != null && x.episode != null ? ' S' + String(x.season).padStart(2,'0') + 'E' + String(x.episode).padStart(2,'0') : '') + (x.episode_title ? ' · ' + x.episode_title : '');
      return (x.title || '') + (x.year ? ' (' + x.year + ')' : '');
    }
    function paintResults(){
      results.innerHTML = '';
      if (!found.length) { results.appendChild(h('p', { className:'muted', text:'Nothing matched. Try a shorter term or * wildcards.' })); return; }
      var all = h('input', { type:'checkbox', title:'Select all', onChange:function(e){ found.forEach(function(x){ picks[x.id] = e.target.checked; }); paintResults(); paintFix(); } });
      results.appendChild(h('table', { className:'live-table nsm-table' }, [
        h('thead', {}, [ h('tr', {}, [ h('th', {}, [all]), h('th', { text:'Item' }), h('th', { text:'File' }), h('th', { text:'Matched' }) ]) ]),
        h('tbody', {}, found.map(function(x){
          return h('tr', {}, [
            h('td', {}, [ h('input', { type:'checkbox', checked: !!picks[x.id], onChange:function(e){ picks[x.id] = e.target.checked; paintFix(); } }) ]),
            h('td', { text: label(x) }), h('td', { className:'nsm-file', text:x.file, title:x.rel }),
            h('td', { className:'muted', text: '“' + x.term + '” in ' + ({title:'title',file:'file name',show:'show',episode_title:'episode'}[x.field] || x.field) + ' · ' + x.score + '%' })
          ]);
        }))
      ]));
      paintFix();
    }
    function paintFix(){
      fixHost.innerHTML = '';
      var ids = found.filter(function(x){ return picks[x.id]; }).map(function(x){ return x.id; });
      if (!ids.length) return;
      var kind = h('select', {}, [ h('option', { value:'title', text:'Set title (movies) / episode title' }), h('option', { value:'show', text:'Rename show (episodes)' }), h('option', { value:'match', text:'Match to… (movie title / TVMaze show id)' }), h('option', { value:'filename', text:'Rename file on disk' }) ]);
      var val = h('input', { type:'text', placeholder:'New name. Tokens: {title} {show} {season} {episode} {episode_title} {year} {name}', style:'flex:2' });
      var year = h('input', { type:'number', placeholder:'Year', className:'narrow' });
      var imdb = h('input', { type:'text', placeholder:'IMDb id (tt…)', className:'narrow' });
      var maze = h('input', { type:'number', placeholder:'TVMaze id', className:'narrow' });
      var prev = h('div', { className:'stack' });
      function body(){ return { ids:ids, fix:{ kind:kind.value, value:val.value, year:year.value, imdb_id:imdb.value, tvmaze_id:maze.value } }; }
      var bPrev = h('button', { className:'btn', text:'Preview', onClick:function(){
        api('/admin/library/query/preview', { method:'POST', body: body() }).then(function(r){
          prev.innerHTML = '';
          if (!r.ok) return toast(r.error || 'Preview failed', { kind:'bad' });
          prev.appendChild(h('table', { className:'live-table nsm-table' }, [
            h('thead', {}, [ h('tr', {}, [ h('th', { text:'Item' }), h('th', { text:'Before' }), h('th', { text:'After' }), h('th', { text:'' }) ]) ]),
            h('tbody', {}, r.rows.map(function(x){
              return h('tr', { className: x.change ? 'nsm-changed' : '' }, [ h('td', { text: x.item ? label(x.item) : x.id }), h('td', { text:x.before || '' }), h('td', { text:x.after || '' }),
                h('td', { className: x.ok ? 'muted' : 'bad', text: !x.ok ? x.error : (x.change ? ((x.sidecars && x.sidecars.length) ? '+ ' + x.sidecars.length + ' sidecar file(s)' : 'will change') : 'no change') }) ]);
            }))
          ]));
          var bApply = h('button', { className:'btn primary', text:'Apply ' + r.changes + ' change' + (r.changes === 1 ? '' : 's'), disabled: !r.changes, onClick:function(){
            var go = kind.value === 'filename' ? confirmDialog('Rename ' + r.changes + ' file(s) on disk? Matching subtitles and artwork are renamed too; watch progress moves with them.', { okText:'Rename files' }) : Promise.resolve(true);
            go.then(function(ok){
              if (!ok) return;
              bApply.disabled = true;
              api('/admin/library/query/apply', { method:'POST', body: body() }).then(function(res){
                if (!res.ok) { bApply.disabled = false; return toast(res.error || 'Failed', { kind:'bad' }); }
                toast('Updated ' + res.changed + (res.failed ? ' · ' + res.failed + ' failed' : ''), { kind: res.failed ? 'bad' : 'ok' });
                search();
              });
            });
          } });
          prev.appendChild(h('div', { className:'row-actions' }, [ bApply ]));
        });
      } });
      function sync(){ var k = kind.value; year.style.display = (k === 'title' || k === 'match') ? '' : 'none'; imdb.style.display = k === 'match' ? '' : 'none'; maze.style.display = k === 'match' ? '' : 'none'; prev.innerHTML = ''; }
      kind.addEventListener('change', sync); sync();
      fixHost.appendChild(h('strong', { text: ids.length + ' selected. Change:' }));
      fixHost.appendChild(h('div', { className:'row-actions' }, [ kind, val, year, imdb, maze, bPrev ]));
      fixHost.appendChild(prev);
    }
    q.addEventListener('keydown', function(e){ if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) search(); });
    box.appendChild(h('h3', { text:'Query the library' }));
    box.appendChild(h('p', { className:'muted', text:'Find items by name across titles, file names, shows and episodes. Use * and ? as wildcards; near-misses are matched too. Pick the items, choose the fix, preview it, then apply. Watch progress is kept.' }));
    box.appendChild(q); box.appendChild(fieldRow);
    box.appendChild(h('div', { className:'row-actions' }, [ h('button', { className:'btn primary', text:'Find', onClick: search }) ]));
    box.appendChild(status); box.appendChild(results); box.appendChild(fixHost);
    box.appendChild(h('hr'));
    return box;
  }

  function scanOrphans(){
    var status = $('#orphan_status');
    var list = $('#orphan_list');
    var editor = $('#orphan_editor');
    if (status) status.textContent = 'Scanning…';
    if (list) list.innerHTML = '';
    if (editor) { editor.style.display='none'; editor.innerHTML=''; }
    var limit = +(($('#orphan_limit')||{}).value || 50);
    var incl = ($('#incl_sec')||{}).checked ? '&include_section_roots=1' : '';
    api('/admin/library/scan-orphans?limit='+limit+incl).then(function(r){
      if (!r.ok) { if(status) status.textContent = r.error||'Scan failed'; return; }
      if (status) status.textContent = 'Found '+(r.count||0)+' orphan(s) under '+(r.roots||[]).join(', ');
      state._orphans = r.orphans || [];
      renderOrphanList();
    });
  }

  function renderOrphanList(){
    var list = $('#orphan_list'); if (!list) return;
    list.innerHTML = '';
    (state._orphans||[]).forEach(function(o, idx){
      var sug = o.suggestion || {};
      var label = (sug.kind==='tv'
        ? ((sug.show||'?')+' S'+String(sug.season||0).padStart(2,'0')+'E'+String(sug.episode||0).padStart(2,'0'))
        : ((sug.title||o.name)+ (sug.year?(' ('+sug.year+')'):'')));
      list.appendChild(h('div', { className:'stack', style:'border:1px solid var(--border);padding:10px;border-radius:8px' }, [
        h('strong', { text: o.name }),
        h('span', { className:'muted', text: o.path }),
        h('span', { text: 'Suggest: '+label+' → '+(sug.section_label||sug.section_id||'?')+' ['+(sug.confidence||'?')+']' }),
        h('div', { className:'row-actions' }, [
          h('button', { className:'btn', text:'Match / edit', onClick: function(){ openOrphanEditor(idx); } }),
          h('button', { className:'btn primary', text:'Quick apply', onClick: function(){ quickApplyOrphan(idx); } })
        ])
      ]));
    });
  }

  function openOrphanEditor(idx){
    var o = (state._orphans||[])[idx]; if (!o) return;
    var editor = $('#orphan_editor'); if (!editor) return;
    editor.style.display = '';
    editor.innerHTML = '';
    editor.appendChild(pendingContent({ className:'muted', }));
    api('/admin/library/suggest', { method:'POST', body:{ path: o.path } }).then(function(r){
      editor.innerHTML = '';
      if (!r.ok) { editor.appendChild(h('p', { text: r.error||'Suggest failed' })); return; }
      var sug = r.suggestion || {};
      var sections = (state.settings&&state.settings.sections)||[];
      var secOpts = sections.map(function(sec){
        return h('option', { value: sec.id, text: (sec.label||sec.id)+' ['+sec.type+']', selected: sec.id===sug.section_id });
      });
      editor.appendChild(h('h3', { text:'Match & rename' }));
      editor.appendChild(h('p', { className:'muted', text: o.path }));
      if (r.tvmaze && r.tvmaze.name) {
        editor.appendChild(h('p', { text: 'TVMaze: '+r.tvmaze.name+(r.tvmaze.premiered?(' ('+r.tvmaze.premiered+')'):'') }));
      }
      editor.appendChild(h('label', {}, ['Kind', h('select', { id:'or_kind' }, [
        h('option', { value:'movie', text:'Movie', selected: sug.kind!=='tv' }),
        h('option', { value:'tv', text:'TV / Anime', selected: sug.kind==='tv' })
      ])]));
      editor.appendChild(h('label', {}, ['Section', h('select', { id:'or_section' }, secOpts)]));
      editor.appendChild(fieldEl('or_title','Title / Show', sug.kind==='tv'?(sug.show||''):(sug.title||'')));
      editor.appendChild(fieldEl('or_year','Year', sug.year||'', 'number'));
      editor.appendChild(fieldEl('or_season','Season', sug.season||'', 'number'));
      editor.appendChild(fieldEl('or_episode','Episode', sug.episode||'', 'number'));
      editor.appendChild(h('pre', { id:'or_preview', className:'muted', text: r.preview ? ('Preview → '+r.preview.dest+(r.preview.conflict?'  [CONFLICT]':'')) : 'No preview' }));
      editor.appendChild(h('div', { className:'row-actions' }, [
        h('button', { className:'btn', text:'Refresh preview', onClick: function(){ previewOrphan(); } }),
        h('button', { className:'btn primary', text:'Apply move + rename', onClick: function(){ applyOrphan(false); } }),
        h('button', { className:'btn ghost', text:'Apply overwrite', onClick: function(){ applyOrphan(true); } })
      ]));
      state._orphanEdit = { path: o.path, idx: idx };
    });
  }

  function orphanPayload(){
    var kind = ($('#or_kind')||{}).value || 'movie';
    return {
      path: state._orphanEdit && state._orphanEdit.path,
      section_id: ($('#or_section')||{}).value,
      kind: kind,
      title: ($('#or_title')||{}).value,
      show: kind==='tv' ? ($('#or_title')||{}).value : undefined,
      year: ($('#or_year')||{}).value,
      season: ($('#or_season')||{}).value,
      episode: ($('#or_episode')||{}).value
    };
  }

  function previewOrphan(){
    var body = orphanPayload();
    api('/admin/library/preview', { method:'POST', body: body }).then(function(r){
      var el = $('#or_preview'); if (!el) return;
      if (!r.ok) { el.textContent = r.error||'Preview failed'; return; }
      el.textContent = 'Preview → '+r.dest+(r.conflict?'  [CONFLICT — dest exists]':'')+(r.same_path?'  [already there]':'');
    });
  }

  function applyOrphan(overwrite){
    var body = orphanPayload();
    body.overwrite = !!overwrite;
    toast('Moving…');
    api('/admin/library/apply', { method:'POST', body: body }).then(function(r){
      if (!r.ok) return toast(r.error||'Apply failed');
      toast(r.moved ? ('Moved → '+(r.rel||r.dest)) : (r.reason||'Updated catalogue'));
      // Remove from local orphan list
      if (state._orphanEdit && state._orphans) {
        state._orphans.splice(state._orphanEdit.idx, 1);
        renderOrphanList();
      }
      var editor = $('#orphan_editor');
      if (editor) { editor.style.display='none'; editor.innerHTML=''; }
    });
  }

  function quickApplyOrphan(idx){
    var o = (state._orphans||[])[idx]; if (!o) return;
    var sug = o.suggestion || {};
    if (!sug.section_id) return toast('No section suggestion');
    toast('Applying…');
    api('/admin/library/apply', { method:'POST', body: {
      path: o.path,
      section_id: sug.section_id,
      kind: sug.kind,
      title: sug.kind==='tv' ? sug.show : sug.title,
      show: sug.show,
      year: sug.year,
      season: sug.season,
      episode: sug.episode
    }}).then(function(r){
      if (!r.ok) return toast(r.error||'Failed');
      toast(r.moved ? ('Moved → '+(r.rel||'')) : (r.reason||'OK'));
      state._orphans.splice(idx, 1);
      renderOrphanList();
    });
  }

  function accessPeriodSelect(selected){
    var opts = [['pending','No access yet (awaiting)'],['1w','1 week'],['1m','1 month'],['3m','3 months'],['1y','1 year'],['custom','Custom date…'],['unlimited','Unlimited']];
    return h('select', { className:'ua-period', 'aria-label':'Access period' }, opts.map(function(o){ return h('option', { value:o[0], text:o[1], selected:o[0] === selected }); }));
  }
  function accessBadge(u){
    var a = u.access_state || {};
    var map = { admin:['Admin · always','accent'], unlimited:['Unlimited','ok'], active:['Active','ok'], expired:['Expired','bad'], pending:['Awaiting access','warn'] };
    var m = map[a.state] || ['Unknown','muted'];
    return h('span', { className:'ua-badge ua-' + (a.state || 'unknown') + ' ' + m[1], text:m[0] });
  }
  function loadUsers(){
    api('/admin/users').then(function(r){
      var el = $('#user_list'); if(!el) return;
      el.innerHTML = '';
      if (!r.ok) { el.appendChild(h('p', { className:'bad', text:r.error || 'Could not load users' })); return; }
      state.userRoles=r.roles||{};state.permissionChoices=r.permission_choices||{};renderRoles();
      var roleSelect=$('#nu_role');if(roleSelect){var selectedRole=roleSelect.value;roleSelect.querySelectorAll('[data-custom-role]').forEach(function(o){o.remove();});Object.keys(state.userRoles).forEach(function(id){roleSelect.appendChild(h('option',{value:id,text:state.userRoles[id].name,'data-custom-role':true}));});roleSelect.value=selectedRole;if(!roleSelect.value)roleSelect.value='user';}
      var table = h('table', { className:'table ua-table' }, [ h('tr', {}, [h('th',{text:'User'}),h('th',{text:'Role'}),h('th',{text:'Access'}),h('th',{text:'Expires'}),h('th',{text:'Change access'}),h('th',{text:''})]) ]);
      (r.users||[]).forEach(function(u){
        var a = u.access_state || {};
        var isAdmin = u.role === 'admin';
        var expires = a.expires_at ? h('div', {}, [ h('div', { text: fmtAccessDate(a.expires_at) }), h('div', { className:'muted', text: fmtRemaining(a.remaining) }) ]) : h('span', { className:'muted', text: isAdmin || a.state === 'unlimited' ? 'Never' : '-' });
        var ctrl;
        if (isAdmin) ctrl = h('span', { className:'muted', text:'Admins are never locked out' });
        else {
          var sel = accessPeriodSelect('1m');
          sel.removeChild(sel.firstChild); // "awaiting" is not a renewal option
          sel.appendChild(h('option', { value:'revoke', text:'Revoke now' }));
          var date = h('input', { type:'date', className:'ua-date', hidden:true, 'aria-label':'Custom expiry date for ' + u.username });
          sel.addEventListener('change', function(){ date.hidden = sel.value !== 'custom'; extend.disabled = !/^(1w|1m|3m|1y)$/.test(sel.value) || a.state !== 'active'; });
          function send(mode){
            if (sel.value === 'revoke') {
              return confirmDialog('Revoke access for ' + u.username + ' now? They will be signed out of the app until you grant access again.', { okText:'Revoke' }).then(function(ok){ if (ok) post(mode); });
            }
            post(mode);
          }
          function post(mode){
            api('/admin/users/' + encodeURIComponent(u.id) + '/access', { method:'POST', body:{ period: sel.value, date: date.value || null, mode: mode } }).then(function(res){
              if (!res.ok) return toast(res.error || 'Failed', { kind:'bad' });
              toast('Access updated for ' + u.username, { kind:'ok' }); loadUsers();
            });
          }
          var set = h('button', { className:'btn', type:'button', text: a.state === 'active' ? 'Set from today' : (a.state === 'unlimited' ? 'Apply' : 'Grant'), onClick: function(){ send('set'); } });
          var extend = h('button', { className:'btn ghost', type:'button', text:'Extend', title:'Add the period to the current expiry date', disabled: a.state !== 'active', onClick: function(){ send('extend'); } });
          ctrl = h('div', { className:'ua-controls' }, [ sel, date, set, extend ]);
        }
        var del = h('button', { className:'btn ghost danger', type:'button', text:'Delete', disabled: !!u.is_self, title: u.is_self ? 'You cannot delete your own account' : 'Delete ' + u.username, onClick: function(){
          confirmDialog('Delete ' + u.username + '? This removes the account, signs it out everywhere and deletes its watch progress. This cannot be undone.', { okText:'Delete user', danger:true }).then(function(ok){
            if (!ok) return;
            api('/admin/users/' + encodeURIComponent(u.id), { method:'DELETE' }).then(function(res){
              if (!res.ok) return toast(res.error || 'Failed', { kind:'bad' });
              toast('Deleted ' + u.username, { kind:'ok' }); loadUsers();
            });
          });
        }});
        var edit = h('button', {className:'btn ghost', type:'button', text:'Edit user', onClick:function(){editUserAreas(u);}});
        table.appendChild(h('tr', {}, [ h('td',{}, [ h('strong', { text:u.username }), u.display_name && u.display_name !== u.username ? h('div', { className:'muted', text:u.display_name }) : null ].filter(Boolean)), h('td',{text:(state.userRoles[u.role]||{}).name||u.role}), h('td',{}, [accessBadge(u)]), h('td',{}, [expires]), h('td',{}, [ctrl]), h('td',{}, [h('div', {className:'row-actions'}, [edit, del])]) ]));
      });
      el.appendChild(h('div', { className:'table-scroll' }, [table]));
    });
  }

  function renderRoles(){
    var host=$('#role_list');if(!host)return;host.innerHTML='';host.appendChild(h('h3',{text:'Roles & permissions'}));host.appendChild(h('p',{className:'muted',text:'Admin has full access. User keeps standard access. Custom roles grant only the permissions selected here; individual user overrides take precedence.'}));
    Object.keys(state.userRoles||{}).forEach(function(id){host.appendChild(h('button',{className:'btn ghost',text:'Edit '+state.userRoles[id].name,onClick:function(){editRole(id);}}));});host.appendChild(h('button',{className:'btn primary',text:'Add role',onClick:function(){editRole(null);}}));
  }
  function permissionDialog(title,body){var overlay=h('div',{className:'confirm-overlay'}),previous=document.activeElement;function close(){overlay.remove();document.removeEventListener('keydown',key);if(previous&&previous.isConnected)previous.focus();}function key(e){if(e.key==='Escape')close();}var card=h('div',{className:'confirm-card',role:'dialog','aria-modal':'true','aria-label':title,style:'max-width:650px;width:94vw;max-height:90vh;overflow:auto;text-align:left'},[h('h3',{text:title}),body,h('button',{className:'btn ghost',text:'Close',onClick:close})]);overlay.appendChild(card);document.body.appendChild(overlay);document.addEventListener('keydown',key);overlay.onclick=function(e){if(e.target===overlay)close();};var focus=card.querySelector('input,select,button');if(focus)focus.focus();return {close:close};}
  function editRole(id){
    var role=(state.userRoles||{})[id]||{name:'',permissions:{}},name=h('input',{value:role.name,maxlength:60,'aria-label':'Role name'}),choices={},status=h('p',{role:'status',className:'muted'}),body=h('div',{className:'stack'},[h('label',{},['Role name',name])]);
    Object.keys(state.permissionChoices).forEach(function(key){var input=h('input',{type:'checkbox',checked:role.permissions[key]===true});choices[key]=input;body.appendChild(h('label',{className:'check-label'},[input,state.permissionChoices[key]]));});
    var save=h('button',{className:'btn primary',text:'Save role'});body.appendChild(status);body.appendChild(save);var dialog=permissionDialog(id?'Edit role':'Add role',body);
    function submit(remove){save.disabled=true;var permissions={};Object.keys(choices).forEach(function(key){permissions[key]=choices[key].checked;});api('/admin/roles',{method:'POST',body:{id:id||'',name:name.value,permissions:permissions,delete:!!remove}}).then(function(r){if(!r.ok)throw new Error(r.error||'Could not save role');dialog.close();loadUsers();toast('Role updated');}).catch(function(e){status.textContent=e.message;save.disabled=false;});}
    save.onclick=function(){submit(false);};if(id)body.appendChild(h('button',{className:'btn danger',text:'Delete role',onClick:function(){confirmDialog('Delete '+role.name+'?',{danger:true}).then(function(ok){if(ok)submit(true);});}}));
  }
  function editUserAreas(user){
    var admin=user.role==='admin',areas=user.areas||{library:true,live:true,games:true},inputs={},overrides={},status=h('p',{className:'muted',role:'status',text:admin?'Admin has full access.':'Choose a role, media areas and optional per-user overrides. Account expiry still applies.'}),role=h('select',{'aria-label':'Role',disabled:admin},admin?[h('option',{value:'admin',text:'Admin'})]:[h('option',{value:'user',text:'User'})]);
    if(!admin)Object.keys(state.userRoles||{}).forEach(function(id){role.appendChild(h('option',{value:id,text:state.userRoles[id].name}));});role.value=user.role;
    var body=h('div',{className:'stack'},[status,h('label',{},['Role',role]),h('h4',{text:'Media areas'})]);
    [['library','Library'],['live','Watch Live'],['games','Games']].forEach(function(entry){var checkbox=h('input',{type:'checkbox',checked:admin||areas[entry[0]]===true,disabled:admin});inputs[entry[0]]=checkbox;body.appendChild(h('label',{className:'check-label'},[checkbox,entry[1]]));});
    body.appendChild(h('h4',{text:'Permission overrides'}));
    Object.keys(state.permissionChoices||{}).forEach(function(key){var select=h('select',{'aria-label':state.permissionChoices[key]},[h('option',{value:'inherit',text:'Use role default'}),h('option',{value:'allow',text:'Allow'}),h('option',{value:'deny',text:'Deny'})]);select.value=user.permissions&&typeof user.permissions[key]==='boolean'?(user.permissions[key]?'allow':'deny'):'inherit';overrides[key]=select;body.appendChild(h('label',{},[state.permissionChoices[key],select]));});
    function defaults(){Object.keys(overrides).forEach(function(key){var select=overrides[key];select.disabled=admin||role.value==='user';var inherited=admin||!!(state.userRoles[role.value]&&state.userRoles[role.value].permissions[key]);select.options[0].textContent='Use role default ('+(inherited?'allowed':'denied')+')';});}role.onchange=defaults;defaults();
    var save=h('button',{className:'btn primary',text:'Save',disabled:admin});body.appendChild(save);var dialog=permissionDialog('Edit user · '+user.username,body);
    save.onclick=function(){var areaValues={},permissions={};Object.keys(inputs).forEach(function(key){areaValues[key]=inputs[key].checked;});Object.keys(overrides).forEach(function(key){if(overrides[key].value!=='inherit')permissions[key]=overrides[key].value==='allow';});save.disabled=true;api('/admin/users/'+encodeURIComponent(user.id)+'/permissions',{method:'POST',body:{role:role.value,areas:areaValues,permissions:permissions}}).then(function(r){if(!r.ok)throw new Error(r.error||'Could not save user');dialog.close();loadUsers();toast('User permissions updated');}).catch(function(e){status.textContent=e.message;save.disabled=false;});};
  }

  function field(id, label, value, type){
    return { id:id, label:label, value:value, type:type||'text' };
  }

  function fmtShortBytes(n){
    n = +n || 0;
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n/1024).toFixed(n>=10240?0:1) + ' KB';
    return (n/1048576).toFixed(n>=10485760?0:1) + ' MB';
  }

  function renderArtworkAudit(host){
    var rows = [], selected = {}, running = false, stopped = false, page = 0;
    var status = h('p', {className:'muted', role:'status', text:'Check library artwork references against files on disk.'});
    var list = h('div', {className:'artwork-audit-list', style:'max-height:520px;overflow:auto'});
    var all = h('input', {type:'checkbox', 'aria-label':'Select all artwork problems'});
    var scan = h('button', {className:'btn', type:'button', text:'Check for missing / broken artwork'});
    var stop = h('button', {className:'btn ghost', type:'button', text:'Stop', disabled:true, onClick:function(){stopped = true;}});
    var repair = h('button', {className:'btn primary', type:'button', text:'Repair selected', disabled:true});
    var typeInputs = {};
    var typePicker = h('fieldset', {className:'row-actions'}, [h('legend', {text:'Check artwork types'})].concat(
      [['movies','Movies'], ['tv','TV'], ['anime','Anime'], ['games','Games']].map(function(entry){
        var checkbox = h('input', {type:'checkbox', checked:true, onChange:function(){
          rows = []; selected = {}; page = 0;
          status.textContent = 'Choose one or more types, then start the check.'; draw();
        }});
        typeInputs[entry[0]] = checkbox;
        return h('label', {}, [checkbox, ' ' + entry[1]]);
      })
    ));
    var panel = h('div', {className:'settings-card'}, [h('h3', {text:'Artwork integrity checker'}), status,
      typePicker, h('div', {className:'row-actions'}, [scan, stop, h('label', {}, [all, ' Select all results']), repair]), list]);
    host.appendChild(panel);
    function current(){ return panel.isConnected; }
    function controls(){
      var count = rows.filter(function(row){return !!selected[row.key];}).length;
      repair.disabled = running || !count; scan.disabled = running || !Object.keys(typeInputs).some(function(type){return typeInputs[type].checked;}); stop.disabled = !running; all.disabled = running;
      Object.keys(typeInputs).forEach(function(type){typeInputs[type].disabled = running;});
      all.checked = rows.length > 0 && count === rows.length; all.indeterminate = count > 0 && count < rows.length;
      repair.textContent = 'Repair selected' + (count ? ' (' + count + ')' : '');
    }
    function openTools(row, edit){
      function saved(result){
        if (!current()) return;
        var oldKey = row.key;
        var key = result.show_key ? 'show_' + result.show_key : oldKey;
        var check = result.removed ? Promise.resolve({ok:true, resolved:true}) : api('/admin/posters/audit/status', {method:'POST', body:{key:key}});
        check.then(function(updated){
          if (!current()) return;
          if (!updated.ok) throw new Error(updated.error || 'Could not refresh artwork result');
          var scroll = list.scrollTop;
          if (updated.resolved) {rows = rows.filter(function(item){return item.key !== oldKey;});delete selected[oldKey];}
          else if (updated.item) {
            var checked = selected[oldKey]; delete selected[oldKey];
            Object.assign(row, updated.item); selected[row.key] = checked;
            row.message = 'Saved. Artwork still needs attention; use Repair or upload a poster.';
          }
          status.textContent = rows.length + ' artwork problems remain'; draw(); list.scrollTop = scroll;
        }).catch(function(error){row.message = 'Saved, but could not recheck: ' + error.message;draw();});
      }
      var showKey = row.show_key;
      api(showKey ? '/shows/' + encodeURIComponent(showKey) : '/items/' + encodeURIComponent(row.id) + '?synopsis=1').then(function(result){
        if (!result.ok) throw new Error(result.error || 'Library entry not found');
        var item = showKey ? result.show : result.item;
        window.__nsEnsureMatching(function(){
          if (!(state.user && state.user.role === 'admin') || !window.NSMatch) return;
          if (edit) window.NSMatch.editDialog(item, showKey, saved);
          else if (showKey) window.NSMatch.openShowFix(showKey, saved);
          else window.NSMatch.openMovieFix(item, saved);
        });
      }).catch(function(error){toast(error.message, {kind:'bad'});});
    }
    function draw(){
      list.innerHTML = '';
      page = Math.max(0, Math.min(page, Math.ceil(rows.length / 100) - 1));
      rows.slice(page * 100, page * 100 + 100).forEach(function(row){
        var checkbox = h('input', {type:'checkbox', checked:!!selected[row.key], disabled:running, 'aria-label':'Select ' + row.title, onChange:function(event){selected[row.key] = event.target.checked; controls();}});
        var actions = [h('button', {className:'btn ghost', type:'button', disabled:running, text:'Repair', onClick:function(){runRepairs([row]);}}),
          h('button', {className:'btn ghost', type:'button', disabled:running, text:'Fix match', onClick:function(){openTools(row, false);}}),
          h('button', {className:'btn ghost', type:'button', disabled:running, text:'Manual edit', onClick:function(){openTools(row, true);}})];
        list.appendChild(h('div', {className:'panel', style:'padding:12px;margin:8px 0'}, [
          h('label', {}, [checkbox, ' ' + (row.title || row.id) + ' · ' + row.kind + (row.season != null ? ' ' + row.season : '')]),
          h('p', {className:row.missing ? 'bad' : 'muted', text:row.problems.join(' · ')}),
          row.message ? h('p', {text:row.message}) : null, h('div', {className:'row-actions'}, actions)
        ]));
      });
      if (rows.length > 100) list.appendChild(h('div', {className:'row-actions'}, [
        h('button', {className:'btn', text:'Previous', disabled:!page, onClick:function(){page--;draw();}}),
        h('span', {text:'Page ' + (page + 1) + ' of ' + Math.ceil(rows.length / 100)}),
        h('button', {className:'btn', text:'Next', disabled:(page + 1) * 100 >= rows.length, onClick:function(){page++;draw();}})
      ]));
      controls();
    }
    all.onchange = function(){rows.forEach(function(row){selected[row.key] = all.checked;});draw();};
    scan.onclick = function(){
      var types = Object.keys(typeInputs).filter(function(type){return typeInputs[type].checked;});
      if (!types.length) {status.textContent = 'Choose at least one artwork type.'; return;}
      running = true; stopped = false; rows = []; selected = {}; page = 0; draw();
      function next(offset, revision){
        if (!current()) return;
        if (stopped) {running = false; status.textContent += ' · stopped';draw();return;}
        api('/admin/posters/audit', {method:'POST', body:{offset:offset, revision:revision, types:types}}).then(function(result){
          if (!current()) return;
          if (!result.ok) throw new Error(result.error || 'Artwork check failed');
          rows = rows.concat(result.items || []);
          status.textContent = 'Checked ' + result.offset + '/' + result.total + ' artwork entries · ' + rows.length + ' problems';
          if (result.more) {controls();setTimeout(function(){next(result.offset, result.revision);}, 50);}
          else {running = false; status.textContent += rows.length ? ' · select entries to repair, match or edit' : ' · no problems found';draw();}
        }).catch(function(error){running = false;status.textContent = error.message;draw();});
      }
      next(0);
    };
    function runRepairs(queue){
      running = true; stopped = false; var completed = 0, resolved = 0; draw();
      function next(){
        if (!current()) return;
        if (stopped || !queue.length) {running = false;status.textContent = 'Repaired ' + resolved + ' of ' + completed + ' checked entries' + (stopped ? ' · stopped' : '');draw();return;}
        var row = queue.shift(); status.textContent = 'Repairing ' + (row.title || row.id) + ' · ' + queue.length + ' remaining';
        api('/admin/posters/audit/repair', {method:'POST', body:{key:row.key}}).then(function(result){
          if (!result.ok) throw new Error(result.error || 'Repair failed');
          if (result.resolved) {resolved++; refreshArtwork(); rows = rows.filter(function(item){return item.key !== row.key;});delete selected[row.key];}
          else {row.problems = result.item ? result.item.problems : row.problems;row.message = result.message;}
        }).catch(function(error){row.message = error.message;}).then(function(){completed++;setTimeout(next, 50);});
      }
      next();
    }
    repair.onclick = function(){runRepairs(rows.filter(function(row){return selected[row.key];}));};
  }

  function renderPosterOptimisePanel(){
    var host = $('#poster-optimise-panel');
    if (!host) return;
    host.innerHTML = '';
    var findState = {
      running: false, cancelled: false, offset: 0, scanned: 0, total: 0,
      needs_total: 0, missing: 0, ready: 0, finished: false, items: [], selected: {}, scopes: { movies:true, shows:true, seasons:true, games:true }, gen: 0
    };
    var poCfg = {
      full_quality: 90, full_max_w: 400, medium_quality: 72, medium_max_w: 220, thumb_quality: 72, thumb_max_w: 40, sizes_version: 3, compression_level: 4, overwrite: false
    };
    /* hydrate from saved settings immediately (summary also returns settings) */
    if (state.settings && state.settings.poster_optimise && typeof state.settings.poster_optimise === 'object') {
      var savedPo=Object.assign({},state.settings.poster_optimise);if((savedPo.sizes_version||0)<3){if(!savedPo.full_max_w||savedPo.full_max_w===1600)savedPo.full_max_w=400;savedPo.medium_max_w=savedPo.thumb_max_w||220;savedPo.medium_quality=savedPo.thumb_quality||72;savedPo.thumb_max_w=40;savedPo.sizes_version=3;}poCfg = Object.assign({}, poCfg, savedPo);
    }
    function collectPoCfg(){
      var fq = $('#po_full_q'), fw = $('#po_full_w'), tq = $('#po_medium_q'), tw = $('#po_medium_w'), cl = $('#po_comp'), ow = $('#po_overwrite');
      return {
        full_quality: fq ? +fq.value : poCfg.full_quality,
        full_max_w: fw ? +fw.value : poCfg.full_max_w,
        medium_quality: tq ? +tq.value : poCfg.medium_quality,
        thumb_quality: 72, sizes_version:3,
        medium_max_w: tw ? +tw.value : poCfg.medium_max_w,
        thumb_max_w: $('#po_tiny_w') ? +$('#po_tiny_w').value : 40,
        compression_level: cl ? +cl.value : poCfg.compression_level,
        overwrite: ow ? !!ow.checked : !!poCfg.overwrite
      };
    }
    function applyPoCfg(cfg){
      if (!cfg) return;
      poCfg = Object.assign(poCfg, cfg);
      var fq = $('#po_full_q'), fw = $('#po_full_w'), tq = $('#po_medium_q'), tw = $('#po_medium_w'), cl = $('#po_comp'), ow = $('#po_overwrite');
      if (fq) { fq.value = String(poCfg.full_quality); var el=$('#po_full_q_val'); if(el) el.textContent=String(poCfg.full_quality); }
      if (fw) { fw.value = String(poCfg.full_max_w); var elw=$('#po_full_w_val'); if(elw) elw.textContent=String(poCfg.full_max_w)+'px'; }
      if (tq) { tq.value = String(poCfg.medium_quality); var el2=$('#po_medium_q_val'); if(el2) el2.textContent=String(poCfg.medium_quality); }
      if (tw) { tw.value = String(poCfg.medium_max_w); var el3=$('#po_medium_w_val'); if(el3) el3.textContent=String(poCfg.medium_max_w)+'px'; }
      var tiny=$('#po_tiny_w');if(tiny){tiny.value=String(poCfg.thumb_max_w||40);$('#po_tiny_w_val').textContent=tiny.value+'px';}
      if (cl) { cl.value = String(poCfg.compression_level); }
      if (ow) ow.checked = !!poCfg.overwrite;
    }
    var queuePoll = { timer: null };

    function scopeParam(){
      var keys = Object.keys(findState.scopes).filter(function(k){ return findState.scopes[k]; });
      return keys.length === 4 ? 'all' : keys.join(',');
    }

    function statusLine(){
      var el = $('#po-find-status');
      if (!el) return;
      el.className = 'cv-find-status' + (findState.running ? ' is-searching' : '');
      el.innerHTML = '';
      if (findState.running) {
        el.appendChild(h('span', { className:'cv-find-pulse' }));
        el.appendChild(h('span', { text: 'Scanning posters… ' + findState.scanned + '/' + (findState.total||'?') + ' · need ' + findState.needs_total }));
      } else if (findState.finished || findState.scanned || findState.total) {
        el.appendChild(h('span', { text: findState.scanned + '/' + findState.total + ' library artwork entries · ' + findState.items.length + ' need WebP · ' + findState.ready + ' ready · ' + findState.missing + ' missing artwork' + (findState.cancelled ? ' (stopped)' : '') }));
      } else {
        el.appendChild(h('span', { className:'muted', text: 'Click Find all to scan for posters missing WebP / thumb variants.' }));
      }
    }

    function paintList(){
      var list = $('#po-glass-list');
      if (!list) return;
      list.innerHTML = '';
      if (!findState.items.length) {
        list.appendChild(h('p', { className:'muted', text: findState.running ? 'Waiting for first hits…' : 'No posters need optimisation (or scan not run yet).' }));
        return;
      }
      findState.items.forEach(function(it){
        var checked = !!findState.selected[it.file];
        var pct = 0;
        var job = (findState._jobs || {})[it.file];
        var rowCls = 'cv-glass-row';
        if (job && job.status === 'running') { rowCls += ' is-converting'; pct = job.progress || 35; }
        if (job && job.status === 'done') { rowCls += ' cv-awaiting'; pct = 100; }
        if (job && job.status === 'failed') rowCls += ' cv-bad';
        var posterStyle = {};
        // use original thumb endpoint for preview
        var preview = API + '/items/' + encodeURIComponent(it.id || '') + '/poster?fetch=0&variant=medium&webp=' + (supportsWebP()?'1':'0');
        if (it.kind === 'show') preview = API + '/shows/' + encodeURIComponent(it.id) + '/poster?fetch=0&variant=medium&webp=' + (supportsWebP()?'1':'0');
        if (it.kind === 'season' && /^([a-f0-9]+)-s(\d+)$/i.test(it.id||'')) {
          var sm = String(it.id).match(/^([a-f0-9]+)-s(\d+)$/i);
          preview = API + '/shows/' + sm[1] + '/seasons/' + sm[2] + '/poster?fetch=0&variant=medium&webp=' + (supportsWebP()?'1':'0');
        }
        var fill = h('div', { className: 'cv-glass-fill' + (job && job.status==='running' ? ' is-pulsing' : ''), style: 'width:' + pct + '%' });
        var row = h('div', { className: rowCls, 'data-file': it.file }, [
          fill,
          h('div', { className:'cv-glass-body' }, [
            h('div', { className:'cv-glass-check' }, [
              h('input', { type:'checkbox', checked: checked, onChange: function(e){
                if (e.target.checked) findState.selected[it.file] = true;
                else delete findState.selected[it.file];
              }})
            ]),
            h('div', { className:'cv-glass-poster', style: 'background-image:url("'+preview.replace(/"/g,'%22')+'")' }),
            h('div', { className:'cv-glass-main' }, [
              h('div', { className:'cv-glass-title', text: it.label || it.file }),
              (job && job.status === 'failed')
                ? h('div', { className:'cv-glass-reason bad', role:'alert', title: job.error || '', text: 'Failed: ' + (job.error || 'unknown error (see server log)') })
                : h('div', { className:'cv-glass-reason', text: it.reason || 'Needs WebP / thumb' }),
              h('div', { className:'cv-glass-sub muted', text: 'orig ' + fmtShortBytes(it.original_bytes)
                + (it.has_webp ? (' · webp ' + fmtShortBytes(it.webp_bytes)) : ' · no webp')
                + (it.has_medium ? (' · medium ' + fmtShortBytes(it.medium_bytes)) : ' · no medium')
                + (it.has_thumb ? (' · thumb ' + fmtShortBytes(it.thumb_bytes)) : ' · no thumb') })
            ]),
            h('div', { className:'cv-glass-side cv-glass-actions' }, [
              (job && (job.status==='queued' || job.status==='running'))
                ? h('button', { className:'btn ghost cv-glass-btn', text: job.status==='running'?'Working…':'Queued', disabled: true })
                : h('button', { className:'btn primary cv-glass-btn', text: (job && (job.status==='done' || job.status==='failed')) || (it.has_webp && it.has_thumb && it.has_medium) ? 'Re-optimise' : 'Optimise', title: ((job && (job.status==='done' || job.status==='failed')) || (it.has_webp && it.has_thumb && it.has_medium)) ? 'Re-encode WebP with current slider settings (overwrites existing)' : 'Create WebP + thumb', onClick: function(){
                    var cfg = collectPoCfg();
                    var reopt = (job && (job.status==='done' || job.status==='failed')) || (it.has_webp && it.has_thumb && it.has_medium);
                    if (reopt) cfg.overwrite = true;
                    runFiles([it.file], cfg);
                  }})
            ])
          ])
        ]);
        list.appendChild(row);
      });
    }

    function applyJobResultToItem(j){
      if (!j || !j.file) return;
      var it = null;
      for (var i = 0; i < findState.items.length; i++) {
        if (findState.items[i].file === j.file) { it = findState.items[i]; break; }
      }
      if (!it) return;
      var res = j.result || {};
      if (j.status === 'done') {
        if (res.webp_bytes != null) it.webp_bytes = +res.webp_bytes || 0;
        if (res.has_medium != null) it.has_medium=!!res.has_medium;
        if (res.medium_bytes != null) it.medium_bytes=+res.medium_bytes||0;
        if (res.thumb_bytes != null) it.thumb_bytes = +res.thumb_bytes || 0;
        if (res.original_bytes != null) it.original_bytes = +res.original_bytes || it.original_bytes || 0;
        it.has_webp = !!(it.webp_bytes > 40 || res.webp_bytes > 40);
        it.has_thumb = !!(it.thumb_bytes > 40 || res.thumb_bytes > 40);
        if (!it.has_webp && (res.made || []).some(function(m){ return String(m).indexOf('webp') === 0; })) it.has_webp = true;
        if (!it.has_thumb && (res.made || []).some(function(m){ return String(m).indexOf('thumb') === 0; })) it.has_thumb = true;
        if (it.has_webp && it.has_thumb && it.has_medium) {
          it.needs = false;
          it.reason = 'ready · webp ' + fmtShortBytes(it.webp_bytes) + ' · thumb ' + fmtShortBytes(it.thumb_bytes);
        } else if (it.has_webp) {
          it.reason = 'missing thumb WebP';
        } else if (it.has_thumb) {
          it.reason = 'missing main WebP';
        }
      }
    }

    function mergeJobs(q){
      findState._jobs = {};
      ((q && q.jobs) || []).forEach(function(j){
        if (j && j.file) {
          findState._jobs[j.file] = j;
          /* refresh list row sizes/status when a job finishes */
          if (j.status === 'done' || j.status === 'failed') applyJobResultToItem(j);
        }
      });
      // Keep done items so Re-optimise stays available when settings change
      paintList();
    }

    var drainBusy = false;
    var drainEnabled = false;

    function pollQueue(){
      if (queuePoll.timer) { clearTimeout(queuePoll.timer); queuePoll.timer = null; }
      api('/admin/posters/optimise/queue').then(function(r){
        if (!r || !r.ok) return;
        mergeJobs(r.queue);
        var sum = r.summary || {};
        var meta = $('#po-summary');
        if (meta) meta.textContent = 'Poster cache: ' + (sum.ready||0) + ' ready · ' + (sum.needs||0) + ' need WebP · ' + (sum.total_originals||0) + ' cached originals. Find all checks the current library, including local artwork.';
        // Queue polling must not overwrite unsaved slider/overwrite changes.
        var jobs = (r.queue && r.queue.jobs) || [];
        var hasQueued = jobs.some(function(j){ return j.status==='queued'; });
        var hasRunning = jobs.some(function(j){ return j.status==='running'; });
        /* drain remaining queued jobs after an explicit Optimise (budget used to stop early) */
        if (drainEnabled && hasQueued && !hasRunning && !drainBusy) {
          drainBusy = true;
          var body = Object.assign({ files: [], budget: 1 }, collectPoCfg());
          api('/admin/posters/optimise', { method:'POST', body: body }).then(function(r2){
            drainBusy = false;
            if (r2 && r2.ok) {
              mergeJobs(r2.queue);
              if (r2.summary) {
                var m2 = $('#po-summary');
                if (m2) m2.textContent = (r2.summary.ready||0) + ' ready · ' + (r2.summary.needs||0) + ' need WebP · ' + (r2.summary.total_originals||0) + ' cached originals';
              }
            }
            queuePoll.timer = setTimeout(pollQueue, 350);
          }).catch(function(){
            drainBusy = false;
            queuePoll.timer = setTimeout(pollQueue, 1500);
          });
          return;
        }
        if (hasQueued || hasRunning) {
          queuePoll.timer = setTimeout(pollQueue, drainEnabled ? 1200 : 4000);
        }
      }).catch(function(){});
    }

    function runFiles(files, cfgOverride){
      if (!files || !files.length) return toast('Nothing selected');
      var cfg = Object.assign({}, collectPoCfg(), cfgOverride || {});
      /* Skip already-complete rows unless overwrite / explicit re-optimise override */
      var filtered = files.filter(function(f){
        if (cfg.overwrite) return true;
        var it = null;
        for (var i = 0; i < findState.items.length; i++) {
          if (findState.items[i].file === f) { it = findState.items[i]; break; }
        }
        if (!it) return true;
        return !(it.has_webp && it.has_thumb && it.has_medium);
      });
      if (!filtered.length) return toast('Nothing to optimise (all have WebP — enable Overwrite or use Re-optimise)', { kind:'bad' });
      drainEnabled = true;
      var body = Object.assign({ files: filtered, budget: 1 }, cfg);
      api('/admin/posters/optimise', { method:'POST', body: body }).then(function(r){
        if (!r.ok) return toast(r.error || 'Optimise failed', { kind:'bad' });
        var applied = (r.results && r.results[0] && r.results[0].settings) || body;
        var made = (r.results && r.results[0] && r.results[0].made) ? r.results[0].made.join('+') : '';
        toast('Queued/ran ' + filtered.length
          + ' · processed ' + (r.processed||0)
          + (r.added ? (' · queued ' + r.added) : '')
          + (made ? (' · ' + made) : '')
          + ' · q' + (applied.full_quality||'?') + '/' + (applied.thumb_quality||'?')
          + ' · w' + (applied.full_max_w||'?') + '/' + (applied.thumb_max_w||'?')
          + (applied.overwrite ? ' · overwrite' : ''), { kind:'ok' });
        mergeJobs(r.queue);
        pollQueue();
      }).catch(function(e){ toast(String(e), { kind:'bad' }); });
    }

    function stopFind(){
      findState.gen++;
      findState.cancelled = true;
      findState.running = false;
      statusLine();
      paintList();
    }

    function findAll(){
      if (findState.running) return;
      var scope = scopeParam();
      var overwrite = collectPoCfg().overwrite;
      if (!scope) return toast('Select at least one artwork type');
      findState.running = true;
      findState.cancelled = false;
      findState.offset = 0;
      findState.items = [];
      findState.selected = {};
      findState.needs_total = 0;
      findState.finished = false; findState.scanned = 0; findState.total = 0; findState.missing = 0; findState.ready = 0;
      findState.gen++;
      var gen = findState.gen;
      statusLine();
      paintList();

      function tick(){
        if (gen !== findState.gen) return;
        if (findState.cancelled) {
          findState.running = false;
          statusLine();
          paintList();
          return;
        }
        api('/admin/posters/optimise/find?offset=' + findState.offset + '&limit=50&scope=' + encodeURIComponent(scope) + '&overwrite=' + (overwrite ? '1' : '0')).then(function(r){
          if (gen !== findState.gen) return;
          if (!r || !r.ok) {
            findState.running = false;
            statusLine();
            toast((r && r.error) || 'Scan failed', { kind:'bad' });
            return;
          }
          findState.scanned = r.scanned || 0;
          findState.total = r.total || 0;
          findState.missing += r.missing_artwork || 0;
          findState.ready += r.ready || 0;
          (r.items || []).forEach(function(it){
            if (!findState.items.some(function(x){ return x.file === it.file; })) findState.items.push(it);
          });
          findState.items.sort(function(a, b){
            var ta = String(a.label || a.file || a.id || '').toLowerCase();
            var tb = String(b.label || b.file || b.id || '').toLowerCase();
            return ta.localeCompare(tb);
          });
          findState.needs_total = findState.items.length;
          statusLine();
          paintList();
          if (r.done || r.next_offset == null) {
            findState.finished = true;
            findState.running = false;
            statusLine();
            return;
          }
          findState.offset = r.next_offset;
          setTimeout(tick, 30);
        }).catch(function(e){
          if (gen !== findState.gen) return;
          findState.running = false;
          statusLine();
          toast(String(e), { kind:'bad' });
        });
      }
      tick();
    }

    host.appendChild(h('div', { className:'cv-section settings-card stack cv-glass-wrap' }, [
      h('div', { className:'cv-glass-head' }, [
        h('h3', { className:'admin-section-title', text:'Batch poster WebP' }),
        h('p', { className:'muted', text:'Library grids load medium WebP; search uses tiny thumbnails; movie/TV detail uses main WebP (quality + max width). Original JPG/PNG always kept as fallback.' }),
        pendingContent({ id:'po-summary', className:'muted', })
      ]),
            h('div', { className:'po-webp-settings cv-section', style:'margin:8px 0 12px' }, [
        h('h4', { className:'admin-section-title', text:'WebP conversion settings', style:'margin:0 0 6px;font-size:15px' }),
        h('p', { className:'muted', text:'Smaller WebP without ugly banding loads grids faster. Main max width caps detail posters; medium max width caps library cards; search thumbnails are generated only when you optimise artwork. Original JPG/PNG stays untouched. Overwrite re-encodes existing WebP on batch.' }),
        h('label', { className:'cv-field cv-slider' }, [
          h('div', { className:'cv-slider-head' }, [ h('span', { className:'cv-field-label', text:'Main WebP quality' }), h('span', { className:'cv-slider-val', id:'po_full_q_val', text:'90' }) ]),
          h('input', { id:'po_full_q', type:'range', min:'40', max:'100', step:'1', value:'90', onInput: function(e){ var el=$('#po_full_q_val'); if(el) el.textContent=e.target.value; } })
        ]),
        h('label', { className:'cv-field cv-slider' }, [
          h('div', { className:'cv-slider-head' }, [ h('span', { className:'cv-field-label', text:'Main max width' }), h('span', { className:'cv-slider-val', id:'po_full_w_val', text:'400px' }) ]),
          h('input', { id:'po_full_w', type:'range', min:'100', max:'700', step:'10', value:'400', onInput: function(e){ var el=$('#po_full_w_val'); if(el) el.textContent=e.target.value+'px'; } })
        ]),
        h('label', { className:'cv-field cv-slider' }, [
          h('div', { className:'cv-slider-head' }, [ h('span', { className:'cv-field-label', text:'Medium WebP quality' }), h('span', { className:'cv-slider-val', id:'po_medium_q_val', text:'72' }) ]),
          h('input', { id:'po_medium_q', type:'range', min:'30', max:'95', step:'1', value:'72', onInput: function(e){ var el=$('#po_medium_q_val'); if(el) el.textContent=e.target.value; } })
        ]),
        h('label', { className:'cv-field cv-slider' }, [
          h('div', { className:'cv-slider-head' }, [ h('span', { className:'cv-field-label', text:'Medium max width' }), h('span', { className:'cv-slider-val', id:'po_medium_w_val', text:'220px' }) ]),
          h('input', { id:'po_medium_w', type:'range', min:'80', max:'360', step:'10', value:'220', onInput: function(e){ var el=$('#po_medium_w_val'); if(el) el.textContent=e.target.value+'px'; } })
        ]),
        h('label', { className:'cv-field cv-slider' }, [
          h('div', { className:'cv-slider-head' }, [h('span', {className:'cv-field-label',text:'Search thumbnail width'}),h('span',{id:'po_tiny_w_val',className:'cv-slider-val',text:'40px'})]),
          h('input',{id:'po_tiny_w',type:'range',min:'16',max:'64',step:'1',value:'40',onInput:function(e){$('#po_tiny_w_val').textContent=e.target.value+'px';}})
        ]),
        h('label', { className:'cv-field' }, [
          h('span', { className:'cv-field-label', text:'Compression level (0=fast, 6=smallest)' }),
          h('select', { id:'po_comp' }, [
            h('option', { value:'0', text:'0 - fastest' }),
            h('option', { value:'2', text:'2' }),
            h('option', { value:'4', text:'4 - default', selected: true }),
            h('option', { value:'6', text:'6 - smallest' })
          ])
        ]),
        h('div', { className:'cv-toggles', style:'margin-top:8px' }, [
          h('label', { className:'check-label' }, [
            h('input', { id:'po_overwrite', type:'checkbox' }),
            h('span', { text:' Overwrite / replace existing WebP on batch convert' })
          ])
        ]),
        h('div', { className:'row-actions', style:'margin-top:10px' }, [
          h('button', { className:'btn primary', text:'Save poster settings', onClick: function(){
            var cfg = collectPoCfg();
            saveSettings({ poster_optimise: cfg });
          }}),
          h('button', { className:'btn ghost', text:'Reset defaults', onClick: function(){
            applyPoCfg({ full_quality:90, full_max_w:400, medium_quality:72, medium_max_w:220, thumb_quality:72, thumb_max_w:40, sizes_version:3, compression_level:4, overwrite:false });
            toast('Form reset - click Save to apply');
          }})
        ])
      ]),
h('div', { className:'cv-find-bar' }, [
        h('button', { className:'btn primary', text:'Find all', onClick: findAll }),
        h('button', { className:'btn ghost', text:'Stop', onClick: stopFind }),
        h('button', { className:'btn ghost', text:'Cancel optimisation', onClick:function(){
          drainEnabled=false;
          api('/admin/posters/optimise/cancel',{method:'POST'}).then(function(r){toast(r.message);pollQueue();}).catch(function(e){toast(String(e),{kind:'bad'});});
        }}),
        h('button', { className:'btn', text:'Optimise selected', onClick: function(){
          var files = Object.keys(findState.selected);
          runFiles(files);
        }}),
        h('button', { className:'btn ghost', text:'Optimise all found', onClick: function(){
          var cfg = collectPoCfg();
          var files = findState.items.filter(function(it){
            if (cfg.overwrite) return true;
            return !(it.has_webp && it.has_thumb && it.has_medium);
          }).map(function(it){ return it.file; });
          runFiles(files, cfg);
        }}),
        h('button', { className:'btn ghost', text:'Refresh queue', onClick: pollQueue })
      ]),
      h('div', { className:'cv-scope-chips', style:'display:flex;flex-wrap:wrap;gap:8px;margin:6px 0 10px' }, [
        ['movies','Movies'],['shows','TV / Anime shows'],['seasons','Seasons'],['games','Games']
      ].map(function(pair){
        var id = pair[0], lab = pair[1];
        return h('label', { className:'check-label', style:'margin:0' }, [
          h('input', { type:'checkbox', checked: !!findState.scopes[id], onChange: function(e){ findState.scopes[id] = !!e.target.checked; } }),
          h('span', { text: lab })
        ]);
      })),
      h('div', { id:'po-find-status', className:'cv-find-status' }),
      h('div', { className:'cv-glass-label', text:'Posters needing WebP' }),
      h('div', { id:'po-glass-list', className:'cv-glass-list' })
    ]));

    renderArtworkAudit(host);
    statusLine();
    applyPoCfg(poCfg);
    api('/admin/posters/optimise/summary').then(function(r){
      var meta = $('#po-summary');
      if (!meta || !r) return;
      meta.textContent = 'Poster cache: ' + (r.ready||0) + ' ready · ' + (r.needs||0) + ' need WebP · ' + (r.total_originals||0) + ' cached originals. Find all checks the current library, including local artwork.'
        + (r.ffmpeg_webp === false ? ' · ffmpeg WebP unavailable; PHP GD is used when available.' : '');
      if (r.settings) {
        applyPoCfg(r.settings);
        if (!state.settings) state.settings = {};
        state.settings.poster_optimise = r.settings;
      }
    });
    /* Also pull admin settings if poster_optimise not yet in state */
    if (!(state.settings && state.settings.poster_optimise)) {
      api('/admin/settings').then(function(j){
        if (j && j.ok && j.settings) {
          state.settings = j.settings;
          if (j.settings.poster_optimise) applyPoCfg(j.settings.poster_optimise);
        }
      }).catch(function(){});
    }
    pollQueue();
  }

  function renderOptimiseSystemPanel(){
    var host = $('#optimise-system-panel');
    if (!host) return;
    host.innerHTML = '';
    host.appendChild(pendingContent({ className:'muted', }));

    function paint(st){
      host.innerHTML = '';
      if (!st || !st.ok) {
        host.appendChild(h('p', { className:'bad', text: (st && st.error) || 'Could not load optimise status' }));
        return;
      }
      function card(title, row, blurb, targets){
        return h('div', { className:'settings-card stack cv-section' }, [
          h('h3', { className:'admin-section-title', text: title }),
          h('p', { className:'muted', text: blurb }),
          h('p', { text: (row.files != null ? (row.files + ' files · ' + fmtShortBytes(row.bytes||0)) : '—') }),
          h('div', { className:'row-actions' }, [
            h('button', { className:'btn primary', text:'Clear', onClick: function(){
              confirmDialog('Clear ' + title + '?', { okText:'Clear' }).then(function(ok){
                if (!ok) return;
                api('/admin/system/cache-clear', { method:'POST', body:{ targets: targets } }).then(function(r){
                  toast(r.ok ? ('Cleared · freed ' + fmtShortBytes(r.bytes_freed||0)) : (r.error||'Failed'), { kind: r.ok?'ok':'bad' });
                  reload();
                });
              });
            }})
          ])
        ]);
      }

      var posters = st.posters || {};
      host.appendChild(h('div', { className:'sys-grid', style:'margin-bottom:16px' }, [
        h('div', { className:'sys-card' }, [
          h('div', { className:'sys-label', text:'Database cache entries' }),
          h('div', { className:'sys-value', text: String((st.database_cache&&st.database_cache.files)||0) }),
          h('div', { className:'muted', text: fmtShortBytes((st.database_cache&&st.database_cache.bytes)||0) })
        ]),
        h('div', { className:'sys-card' }, [
          h('div', { className:'sys-label', text:'Temporary files' }),
          h('div', { className:'sys-value', text: String((st.temporary_files&&st.temporary_files.files)||0) }),
          h('div', { className:'muted', text: fmtShortBytes((st.temporary_files&&st.temporary_files.bytes)||0) })
        ]),
        h('div', { className:'sys-card' }, [
          h('div', { className:'sys-label', text:'Poster originals' }),
          h('div', { className:'sys-value', text: String(posters.originals||0) }),
          h('div', { className:'muted', text: fmtShortBytes(posters.original_bytes||0) })
        ]),
        h('div', { className:'sys-card' }, [
          h('div', { className:'sys-label', text:'WebP / thumbs' }),
          h('div', { className:'sys-value', text: (posters.webp||0) + ' / ' + (posters.thumbs||0) }),
          h('div', { className:'muted', text: fmtShortBytes((posters.webp_bytes||0)+(posters.thumb_bytes||0)) })
        ])
      ]));

      host.appendChild(card('Browse & media caches', st.database_cache||{}, 'Clears cached library views, media analysis and durations. Entries rebuild as needed.', ['shm','probe']));
      host.appendChild(card('PHP OPcache', { files: st.opcache?1:0, bytes:0 }, 'Resets PHP opcode cache so code deploys take effect immediately.', ['opcache']));
      host.appendChild(card('Old convert logs', st.convert_logs||{}, 'Deletes convert_*.log older than 2 hours. Does not touch the convert queue.', ['convert_logs']));
      host.appendChild(card('Temporary process files', st.temporary_files||{}, 'Removes interrupted process output older than one day.', ['tmp']));

      host.appendChild(h('div', { className:'settings-card stack cv-section' }, [
        h('h3', { className:'admin-section-title', text:'Browser & service worker' }),
        h('p', { className:'muted', text:'Force this browser to drop session list caches and refresh the service worker precache. No server files deleted.' }),
        h('div', { className:'row-actions' }, [
          h('button', { className:'btn', text:'Clear session list cache', onClick: function(){
            try {
              Object.keys(sessionStorage).forEach(function(k){
                if (/^(ns-|vaultcast|browse|home)/i.test(k) || /cache/i.test(k)) sessionStorage.removeItem(k);
              });
            } catch (eS) {}
            toast('Session list cache cleared', { kind:'ok' });
          }}),
          h('button', { className:'btn', text:'Refresh service worker', onClick: function(){
            if (!('serviceWorker' in navigator)) return toast('No service worker support');
            navigator.serviceWorker.getRegistrations().then(function(regs){
              return Promise.all(regs.map(function(r){ return r.update(); }));
            }).then(function(){
              toast('Service worker update requested — hard-refresh if assets look stale', { kind:'ok' });
            }).catch(function(e){ toast(String(e), { kind:'bad' }); });
          }}),
          h('button', { className:'btn ghost', text:'Clear poster Cache API', onClick: function(){
            if (typeof caches === 'undefined') return toast('Cache API unavailable');
            caches.delete(POSTER_CACHE).then(function(ok){
              toast(ok ? 'Poster cache deleted' : 'No poster cache present', { kind:'ok' });
              try { _posterMem = Object.create(null); } catch (eM) {}
            });
          }})
        ])
      ]));

      host.appendChild(h('div', { className:'row-actions', style:'margin-top:12px' }, [
        h('button', { className:'btn primary', text:'Clear all app caches', onClick: function(){
          confirmDialog('Clear browse and probe caches, OPcache, old conversion logs and temporary process files?', { okText:'Clear all' }).then(function(ok){
            if (!ok) return;
            api('/admin/system/cache-clear', { method:'POST', body:{ targets:['all'] } }).then(function(r){
              toast(r.ok ? ('Cleared · freed ' + fmtShortBytes(r.bytes_freed||0)) : (r.error||'Failed'), { kind: r.ok?'ok':'bad' });
              reload();
            });
          });
        }}),
        h('button', { className:'btn ghost', text:'Refresh', onClick: reload }),
        h('button', { className:'btn ghost', text:'Open server & processes', onClick: function(){
          state.settingsSection = 'system'; render();
        }})
      ]));
    }

    function reload(){
      api('/admin/system/optimise-status').then(paint).catch(function(e){
        host.innerHTML = '';
        host.appendChild(h('p', { className:'bad', text: String(e) }));
      });
    }
    reload();
  }

  /* ---------------- Settings → Check for updates (admin) ---------------- */
  function renderUpdatesPanel(host, branch, check){
    if (!host.isConnected) return;
    if (!check) {
      host.replaceChildren(h('div',{className:'settings-card stack'},[
        h('p',{text:'Installed version: '+(window.__STREAM_VERSION__ || 'Unknown')}),
        h('p',{className:'muted',text:'Check GitHub for available updates and branches when you are ready.'}),
        h('button',{className:'btn primary',type:'button',text:'Check for updates',onClick:function(){renderUpdatesPanel(host,branch,true);}})
      ]));
      return;
    }
    host.replaceChildren(h('p',{className:'muted',role:'status',text:'Checking GitHub for updates and available branches…'}));
    var requestToken = {};
    host._updateRequest = requestToken;
    function fileList(title, list, cls){
      if (!list || !list.length) return null;
      return h('details', { className:'updates-files' }, [
        h('summary', { text: title + ' (' + list.length + ')' }),
        h('ul', {}, list.map(function(p){ return h('li', { className: cls || '', text: p }); }))
      ]);
    }
    function paint(r){
      if (!host.isConnected || host._updateRequest !== requestToken) return;
      host.textContent = '';
      r = r || {};
      var branches = r.branches || ['main'];
      var selected = r.selected_branch || branch || r.current_branch || 'main';
      var selector = h('select', { 'aria-label':'Update branch' }, branches.map(function(name){
        return h('option', { value:name, text:name });
      }));
      selector.value = branches.indexOf(selected) >= 0 ? selected : 'main';
      selector.onchange = function(){
        host.querySelectorAll('.updates-check-result').forEach(function(el){el.remove();});
        host.appendChild(h('p',{className:'muted updates-check-result',text:'Press Check for updates to check '+selector.value+'.'}));
      };
      var checkSelected = h('button', { className:'btn', type:'button', text:'Check for updates', onClick:function(){ renderUpdatesPanel(host, selector.value, true); } });
      host.appendChild(h('div', { className:'settings-card stack' }, [
        h('p', { text:'Installed branch: ' + (r.current_branch || 'main') }),
        h('label', {}, [ h('span', { text:'Update branch' }), selector ]),
        h('p', { className:'muted', text:'Choose a branch to test, or main to return. The installed branch stays pinned until you apply a switch, even if it is deleted on GitHub. Switching files does not undo database migrations made by a test branch.' }),
        checkSelected
      ]));
      if (r.branches_error) host.appendChild(h('p', { className:'bad', text:r.branches_error }));
      if (!r || !r.ok) {
        host.appendChild(h('div', { className:'settings-card updates-check-result' }, [ h('p', { className:'bad', text:(r && r.error) || 'Could not check for updates.' }),
          h('button', { className:'btn', type:'button', text:'Try again', onClick:function(){ renderUpdatesPanel(host, selector.value, true); } }) ]));
        return;
      }
      var latest = r.latest || {};
      var rows = [
        h('div', { className:'updates-versions' }, [
          h('div', {}, [ h('span', { className:'muted', text:'Installed (' + r.current_branch + ')' }), h('strong', { text:r.current }) ]),
          h('div', {}, [ h('span', { className:'muted', text:'Latest on GitHub (' + (latest.branch || 'main') + ')' }), h('strong', { text: latest.version || '?' }) ])
        ])
      ];
      if (r.note) rows.push(h('p', { className:'muted', text:r.note }));
      if (!r.update_available) rows.push(h('p', { className:'ok', text:'Vaultcast is up to date: every application file matches GitHub.' }));
      else {
        var kb = Math.max(1, Math.round((latest.bytes || 0) / 1024));
        rows.push(h('p', { text: latest.count + ' file' + (latest.count === 1 ? '' : 's') + ' to update (about ' + kb + ' KB to download). Only these files change; settings, the database and media are never touched.' }));
        [ fileList('Changed', latest.changed), fileList('New', latest.added), fileList('Removed upstream', latest.removed, 'bad') ].forEach(function(n){ if (n) rows.push(n); });
      }
      if (latest.url) rows.push(h('a', { href:latest.url, target:'_blank', rel:'noopener', className:'linkish', text:'Recent changes on GitHub' }));
      var status = h('p', { className:'muted', 'aria-live':'polite' });
      var actions = [ h('button', { className:'btn', type:'button', text:'Check again', onClick:function(){ renderUpdatesPanel(host, selector.value, true); } }) ];
      if (r.update_available) {
        var actionLabel = r.branch_change ? 'Switch to ' + latest.branch : 'Update now';
        var btn = h('button', { className:'btn primary', type:'button', text:actionLabel, onClick:function(){
          confirmDialog('Install branch ' + latest.branch + ': ' + latest.count + ' Vaultcast file' + (latest.count === 1 ? '' : 's') + ' (' + r.current + ' \u2192 ' + latest.version + ')? The site shows a short maintenance page while files are replaced. The updater leaves settings and media in place. Branch changes do not reverse database migrations.', { okText:actionLabel }).then(function(ok){
            if (!ok) return;
            btn.disabled = true; selector.disabled = true; checkSelected.disabled = true; status.className = 'muted'; status.textContent = 'Downloading, verifying and installing\u2026 keep this page open.';
            api('/admin/updates/apply', { method:'POST', body:{ tree: latest.tree, branch: latest.branch } }).then(function(res){
              if (res && res.ok) {
                status.className = 'ok';
                status.textContent = 'Installed ' + res.branch + ' · ' + res.to + ' (' + res.replaced + ' replaced, ' + res.created + ' added, ' + res.removed + ' removed). Reloading\u2026';
                setTimeout(function(){ location.reload(); }, 2500);
              } else {
                status.className = 'bad'; status.textContent = (res && res.error) || 'Update failed. Nothing was changed.'; btn.disabled = false; selector.disabled = false; checkSelected.disabled = false;
              }
            }).catch(function(e){ status.className = 'bad'; status.textContent = 'Update request failed: ' + (e && e.message || e) + '. Check again to see the current state.'; btn.disabled = false; selector.disabled = false; checkSelected.disabled = false; });
          });
        } });
        actions.unshift(btn);
      }
      rows.push(h('div', { className:'row-actions' }, actions));
      rows.push(status);
      host.appendChild(h('div', { className:'settings-card stack updates-check-result' }, rows));
    }
    api('/admin/updates' + (branch ? '?branch=' + encodeURIComponent(branch) : '')).then(paint).catch(function(e){ paint({ ok:false, error:String(e && e.message || e) }); });
  }
  function renderConvertAdminPanel(s){
      var c = Object.assign({
        enabled: true, one_job_at_a_time: true, threads: 1, max_threads: 1, quality_mode: 'directplay',
        crf: 18, preset: 'medium',
        audio_bitrate: '320k', nice: 19, ionice_class: 3,
        target_video: 'libx264', target_audio: 'aac', target_container: 'mp4',
        preserve_resolution: true
      }, (s && s.convert) || {});
      var cvMaxThreads = Math.max(1, parseInt(c.max_threads, 10) || 1);
      function cvToggleRow(id, title, hint, on){
        return h('label', { className:'feature-row', 'for':id }, [
          h('span', { className:'feature-text' }, [ h('strong', { text:title }), h('span', { className:'muted', text:hint }) ]),
          h('span', { className:'feature-switch' }, [
            h('input', { type:'checkbox', role:'switch', id:id, className:'feature-switch-input', checked:!!on }),
            h('span', { className:'feature-switch-track', 'aria-hidden':'true' })
          ])
        ]);
      }
      /** Whole number 1..max, or null. */
      function cvThreadsValue(){
        var el = $('#cv_threads'); if (!el) return 1;
        var v = String(el.value).trim();
        if (!/^\d+$/.test(v)) return null;
        var n = parseInt(v, 10);
        return n >= 1 && n <= cvMaxThreads ? n : null;
      }
      var modeState = { mode: String(c.quality_mode || 'directplay') };
      var batchPoll = { timer: null };
      var findState = {
        running: false,
        cancelled: false,
        stopped: false,
        offset: 0,
        scanned: 0,
        total: 0,
        found: 0,
        items: [],
        selected: {},
        expanded: {},
        scopes: { movies: true, tv: true, anime: true },
        gen: 0
      };
      var lastQueue = [];

      var QUALITY_MODES = [
        { id:'original', title:'Original / lossless', blurb:'Remux only (stream-copy). No re-encode \u2014 same quality & ~same size. Tiny server load. May not DirectPlay in every browser.' },
        { id:'directplay', title:'DirectPlay smart', blurb:'Best default. Remux when already H.264+AAC; copy video + AAC audio when needed; encode only if video is not H.264.' },
        { id:'near_lossless', title:'Near-lossless', blurb:'Always encode H.264 CRF 18 \u00b7 medium. Visually transparent; larger than remux. Use when you need DirectPlay from any source.' },
        { id:'balanced', title:'Balanced', blurb:'H.264 CRF 20 \u00b7 medium. Good quality, smaller files, moderate CPU.' },
        { id:'efficient', title:'Efficient', blurb:'H.264 CRF 23 \u00b7 faster. Smaller files, lighter on the server, more compression.' },
        { id:'custom', title:'Custom', blurb:'Use the sliders & dropdowns below exactly as set.' }
      ];

      function statusClass(st){
        st = String(st || '');
        if (st === 'running') return 'cv-running';
        if (st === 'queued') return 'cv-queued';
        if (st === 'done') return 'cv-done';
        if (st === 'failed' || st === 'cancelled') return 'cv-bad';
        return '';
      }

      function selectEl(id, label, value, options){
        var opts = options.map(function(o){
          var v = typeof o === 'string' ? o : o.value;
          var t = typeof o === 'string' ? o : o.label;
          return h('option', { value:v, text:t, selected: String(v) === String(value) });
        });
        return h('label', { className:'cv-field' }, [
          h('span', { className:'cv-field-label', text: label }),
          h('select', { id:id }, opts)
        ]);
      }

      function sliderEl(id, label, value, min, max, step, fmt){
        var v0 = value == null ? min : +value;
        var valLab = h('span', { className:'cv-slider-val', id: id + '_val', text: fmt ? fmt(v0) : String(v0) });
        var input = h('input', {
          id:id, type:'range', min:String(min), max:String(max), step:String(step||1), value:String(v0),
          onInput: function(e){
            var v = e.target.value;
            var el = $('#' + id + '_val');
            if (el) el.textContent = fmt ? fmt(+v) : String(v);
            if (id === 'crf') updateCrfHint(+v);
          }
        });
        return h('label', { className:'cv-field cv-slider' }, [
          h('div', { className:'cv-slider-head' }, [
            h('span', { className:'cv-field-label', text: label }),
            valLab
          ]),
          input
        ]);
      }

      function updateCrfHint(v){
        var hint = $('#cv_crf_hint');
        if (!hint) return;
        if (v <= 0) hint.textContent = 'CRF 0 is true lossless encode \u2014 files balloon massively. Prefer Original remux instead.';
        else if (v < 16) hint.textContent = 'Very high quality / large files. Remux is usually better if you want identical quality.';
        else if (v <= 18) hint.textContent = 'Near-lossless \u2014 visually transparent for most footage.';
        else if (v <= 21) hint.textContent = 'High quality with sensible file size.';
        else if (v <= 24) hint.textContent = 'Good quality, smaller files, lighter server load.';
        else hint.textContent = 'Smaller files \u2014 more visible compression.';
      }

      function syncModeUi(){
        var mode = modeState.mode;
        document.querySelectorAll('.cv-mode-card').forEach(function(el){
          el.classList.toggle('is-active', el.getAttribute('data-mode') === mode);
        });
        var hint = $('#cv_mode_hint');
        var meta = QUALITY_MODES.filter(function(m){ return m.id === mode; })[0];
        if (hint && meta) hint.textContent = meta.blurb;
        var encodeBox = $('#cv_encode_controls');
        var showEncode = (mode === 'near_lossless' || mode === 'balanced' || mode === 'efficient' || mode === 'custom');
        if (encodeBox) encodeBox.hidden = !showEncode;
        var customBox = $('#cv_custom_controls');
        if (customBox) customBox.hidden = mode !== 'custom';
        if (mode === 'near_lossless') {
          if ($('#crf')) { $('#crf').value = '18'; if ($('#crf_val')) $('#crf_val').textContent = '18'; updateCrfHint(18); }
          if ($('#preset')) $('#preset').value = 'medium';
        } else if (mode === 'balanced') {
          if ($('#crf')) { $('#crf').value = '20'; if ($('#crf_val')) $('#crf_val').textContent = '20'; updateCrfHint(20); }
          if ($('#preset')) $('#preset').value = 'medium';
        } else if (mode === 'efficient') {
          if ($('#crf')) { $('#crf').value = '23'; if ($('#crf_val')) $('#crf_val').textContent = '23'; updateCrfHint(23); }
          if ($('#preset')) $('#preset').value = 'faster';
        }
      }

      function stopBatchPoll(){
        if (batchPoll.timer) { clearInterval(batchPoll.timer); batchPoll.timer = null; }
      }
      function ensureBatchPoll(hasActive){
        if (hasActive) {
          if (batchPoll.timer) return;
          batchPoll.timer = setInterval(function(){
            if (!$('#cv_batch_list')) { stopBatchPoll(); return; }
            refreshConvertAdmin({ quiet: true });
          }, 2000);
        } else {
          stopBatchPoll();
        }
      }

      function acceptConvertRequest(req, opts){
        opts = opts || {};
        if (!req || !req.id) return;
        var doStart = function(){
          api('/admin/convert/requests/'+req.id, { method:'POST', body:{ action:'start' } }).then(function(res){
            toast(res.ok ? (res.convert && res.convert.message || 'Accepted - convert queued') : (res.error||'Failed'), { kind: res.ok ? 'ok' : 'bad' });
            refreshConvertAdmin();
          });
        };
        if (opts.skipConfirm) return doStart();
        confirmDialog('Accept convert request for "'+(req.title||req.item_id||'item')+'" and add it to the batch converter?', { okText:'Accept & queue' }).then(function(ok){
          if (ok) doStart();
        });
      }
      function resolveConvertRequest(req, action){
        if (!req || !req.id) return;
        api('/admin/convert/requests/'+req.id, { method:'POST', body:{ action: action } }).then(function(res){
          if (!res.ok) toast(res.error||'Failed', { kind:'bad' });
          else if (action === 'done') toast('Marked done - notification cleared', { kind:'ok' });
          else if (action === 'dismiss') toast('Dismissed - notification cleared', { kind:'ok' });
          else if (action === 'delete') toast('Request removed', { kind:'ok' });
          refreshConvertAdmin();
        });
      }
      function deleteConvertRequest(req, e){
        if (e) { e.preventDefault(); e.stopPropagation(); }
        if (!req || !req.id) return;
        confirmDialog('Remove convert request for "'+(req.title||req.item_id||'item')+'" from the list?', { okText:'Delete', danger:true }).then(function(ok){
          if (ok) resolveConvertRequest(req, 'delete');
        });
      }
      function removeConvertOriginal(job, btn){
        if (!job || !job.id) return;
        var msg = 'Replace original with converted file for "' + (job.title || job.item_id || 'item') + '"?\n\n'
          + 'Delete: ' + (job.src || '?') + '\n\n'
          + 'Keep (same folder): ' + (job.dest || '?') + '\n\n'
          + 'The converted file is checked first (size + duration). The catalogue, watch progress and collection links move to the converted file, and the job is marked done. This cannot be undone.';
        confirmDialog(msg, { okText:'Replace original', danger:true }).then(function(ok){
          if (!ok) return;
          if (btn) { btn.disabled = true; btn.textContent = 'Replacing\u2026'; }
          api('/admin/convert/'+job.id+'/remove-original', { method:'POST', body:{ confirm:true } }).then(function(res){
            if (!res || !res.ok) {
              if (btn) { btn.disabled = false; btn.textContent = 'Replace'; }
              return toast((res && res.error) || 'Remove failed - original kept', { kind:'bad', ms: 6500 });
            }
            toast('Original removed - marked done' + (res.requests_resolved ? (' (' + res.requests_resolved + ' TV request' + (res.requests_resolved === 1 ? '' : 's') + ' closed)') : ''), { kind:'ok' });
            refreshConvertAdmin();
          }).catch(function(){
            if (btn) { btn.disabled = false; btn.textContent = 'Replace'; }
            toast('Remove failed - original kept', { kind:'bad' });
          });
        });
      }
      function resolveConvertJob(job, btn){
        if (!job || !job.id) return;
        if (btn) btn.disabled = true;
        api('/admin/convert/'+job.id+'/resolve', { method:'POST', body:{} }).then(function(res){
          if (btn) btn.disabled = false;
          if (!res || !res.ok) return toast((res && res.error) || 'Failed', { kind:'bad' });
          toast('Marked done - both files kept', { kind:'ok' });
          refreshConvertAdmin();
        });
      }

      function codecLabel(it){
        var v = (it.video_codec || '').toString();
        var a = (it.audio_codec || '').toString();
        if (!v && !a) return (it.ext || '').toString().toUpperCase() || 'codecs?';
        return [v ? v.toUpperCase() : '?', a ? a.toUpperCase() : 'no-audio'].join(' + ');
      }

      function convertReason(it){
        if (it && it.convert_reason) return String(it.convert_reason);
        return codecLabel(it) + ' \u2014 not DirectPlay (need H.264 + AAC)';
      }

      function jobProgressPct(job){
        if (!job) return 0;
        if (job.status === 'done') return 100;
        if (job.status === 'queued') return 0;
        var p = job.progress;
        if (p == null || isNaN(+p)) return job.status === 'running' ? 3 : 0;
        var cap = job.status === 'running' ? 99.5 : 100;
        return Math.max(0, Math.min(cap, +p));
      }

      function jobByItemId(){
        var map = {};
        (lastQueue || []).forEach(function(j){
          if (j && j.item_id) map[j.item_id] = j;
        });
        return map;
      }

      function activeItemIds(){
        var ids = {};
        (lastQueue || []).forEach(function(j){
          if (!j || !j.item_id) return;
          if (j.status === 'queued' || j.status === 'running') ids[j.item_id] = true;
        });
        return ids;
      }

      function makePosterThumb(item, extraClass){
        var pKind = (typeof posterKindFor === 'function') ? posterKindFor(item) : 'item';
        var pid = (typeof posterIdFor === 'function') ? posterIdFor(item) : (item && item.id);
        var poster = h('div', {
          className: 'cv-glass-poster poster poster-pending' + (extraClass ? (' ' + extraClass) : '')
        }, [
          h('div', { className:'ph', text: (item && (item.show || item.title) || '?').toString().slice(0, 1).toUpperCase() })
        ]);
        if (pid && typeof observePoster === 'function') {
          if (item && (item.has_poster || (typeof posterNeedsRemote === 'function' && posterNeedsRemote(item)))) {
            if (typeof posterNeedsRemote === 'function' && posterNeedsRemote(item)) poster.setAttribute('data-poster-fetch', '1');
            setTimeout(function(){ observePoster(poster, pid, pKind); }, 0);
          }
        }
        return poster;
      }

      function enqueueIds(ids, label){
        ids = (ids || []).filter(Boolean);
        if (!ids.length) return toast('Nothing selected');
        api('/admin/convert', { method:'POST', body:{ item_ids: ids } }).then(function(r){
          if (!r.ok) return toast(r.error || 'Enqueue failed', { kind:'bad' });
          toast((label || 'Queued') + ': ' + (r.queued||0) + (r.skipped ? (', skipped ' + r.skipped) : ''), { kind:'ok' });
          ids.forEach(function(id){ delete findState.selected[id]; });
          refreshConvertAdmin();
          paintUnified();
        });
      }

      function cancelJob(job){
        if (!job || !job.id) return;
        api('/admin/convert/'+job.id+'/cancel', { method:'POST' }).then(function(){ refreshConvertAdmin(); });
      }

      function discardConvertJob(job, btn){
        if (!job || !job.id) return;
        if (btn) { btn.disabled = true; }
        api('/admin/convert/'+job.id+'/discard', { method:'POST', body:{ confirm:true } }).then(function(res){
          if (!res || !res.ok) {
            if (btn) btn.disabled = false;
            return toast((res && res.error) || 'Remove failed', { kind:'bad' });
          }
          var cleaned = (res.cleaned && res.cleaned.length) ? (' · cleaned ' + res.cleaned.join(', ')) : '';
          toast('Removed from queue' + cleaned, { kind:'ok' });
          refreshConvertAdmin();
        }).catch(function(){
          if (btn) btn.disabled = false;
          toast('Remove failed', { kind:'bad' });
        });
      }

      function selectedIds(){
        return Object.keys(findState.selected).filter(function(id){ return findState.selected[id]; });
      }

      function groupDiscover(items){
        var shows = {};
        var movies = [];
        var order = [];
        (items || []).forEach(function(it){
          var isEp = !!(it.show && (it.type === 'tv' || it.type === 'anime' || it.section === 'tv' || it.section === 'anime'));
          if (isEp) {
            var key = it.show_key || ('show:' + String(it.show || 'Unknown'));
            if (!shows[key]) {
              shows[key] = {
                key: key,
                show: it.show || 'Unknown show',
                show_key: it.show_key || null,
                section: it.section,
                type: it.type,
                has_poster: !!it.has_poster,
                episodes: []
              };
              order.push({ kind:'show', key: key });
            }
            shows[key].episodes.push(it);
            if (it.has_poster) shows[key].has_poster = true;
          } else {
            order.push({ kind:'movie', item: it });
            movies.push(it);
          }
        });
        Object.keys(shows).forEach(function(k){
          shows[k].episodes.sort(function(a, b){
            var sa = +(a.season || 0), sb = +(b.season || 0);
            if (sa !== sb) return sa - sb;
            return +(a.episode || 0) - +(b.episode || 0);
          });
        });
        order.sort(function(a, b){
          var na = a.kind === 'show' ? ((shows[a.key] && shows[a.key].show) || a.key || '') : ((a.item && (a.item.title || a.item.id)) || '');
          var nb = b.kind === 'show' ? ((shows[b.key] && shows[b.key].show) || b.key || '') : ((b.item && (b.item.title || b.item.id)) || '');
          return String(na).localeCompare(String(nb), undefined, { sensitivity: 'base' });
        });
        return { order: order, shows: shows, movies: movies };
      }

      function selectedScopes(){
        var out = [];
        if (findState.scopes.movies) out.push('movies');
        if (findState.scopes.tv) out.push('tv');
        if (findState.scopes.anime) out.push('anime');
        return out;
      }

      function stopFindAll(){
        if (!findState.running && !findState.cancelled) return;
        findState.cancelled = true;
        findState.running = false;
        findState.stopped = true;
        updateFindIndicator();
        paintUnified();
      }

      function updateFindIndicator(){
        var el = $('#cv_find_status');
        if (!el) return;
        el.classList.toggle('is-searching', !!findState.running);
        var scopeNote = selectedScopes().map(function(s){
          return s === 'movies' ? 'Movies' : (s === 'tv' ? 'TV' : 'Anime');
        }).join(', ') || 'none';
        if (findState.running) {
          var pct = findState.total ? Math.min(100, Math.round(100 * findState.scanned / findState.total)) : 0;
          el.innerHTML = '';
          el.appendChild(h('span', { className:'cv-find-pulse', text:'' }));
          el.appendChild(h('span', {
            text: 'Searching ' + scopeNote + '\u2026 ' + findState.scanned + (findState.total ? (' / ' + findState.total) : '') +
              ' scanned \u00b7 ' + findState.found + ' need convert' + (pct ? (' \u00b7 ' + pct + '%') : '')
          }));
        } else if (findState.stopped && findState.scanned > 0) {
          el.textContent = 'Stopped early \u2014 showing ' + findState.found + ' found so far' +
            (findState.total ? (' (' + findState.scanned + ' / ' + findState.total + ' checked in ' + scopeNote + ')') : '');
        } else if (findState.scanned > 0) {
          el.textContent = 'Scan complete \u2014 ' + findState.found + ' need DirectPlay convert' +
            (findState.total ? (' (' + findState.scanned + ' / ' + findState.total + ' videos in ' + scopeNote + ')') : '');
        } else {
          el.textContent = 'Choose Movies / TV / Anime, then Find all. Scans real codecs (not extension). Games are skipped. TV series stay in accordions.';
        }
        var btn = $('#cv_find_all_btn');
        if (btn) {
          btn.textContent = findState.running ? 'Searching\u2026' : 'Find all';
          btn.disabled = !!findState.running;
        }
        var stop = $('#cv_find_stop_btn');
        if (stop) stop.hidden = !findState.running;
        document.querySelectorAll('.cv-scope-checks input[data-scope]').forEach(function(inp){
          var key = inp.getAttribute('data-scope');
          inp.checked = !!(key && findState.scopes[key]);
          inp.disabled = !!findState.running;
        });
      }

      function previewConvertJob(job){
        if (!job) return;
        var id = job.dest_item_id || null;
        if (!id) {
          toast('Converted file is not in the catalogue yet - wait for ingest to finish', { kind:'bad' });
          return;
        }
        var item = { id: id, title: (job.title || 'Converted preview') + ' (preview)', type: 'movies', has_poster: true };
        try { playItem(item, { audio: null }); }
        catch (e) { toast('Preview failed: ' + e, { kind:'bad' }); }
      }
      function deleteConvertOutput(job, btn){
        if (!job || !job.id) return;
        var msg = 'Delete the converted file for "' + (job.title || job.item_id || 'item') + '"?\n\n'
          + 'Delete: ' + (job.dest || '?') + '\n\n'
          + 'Keep original: ' + (job.src || '?') + '\n\n'
          + 'Use this when Preview shows a bad conversion. The original is untouched.';
        confirmDialog(msg, { okText:'Delete conversion', danger:true }).then(function(ok){
          if (!ok) return;
          if (btn) { btn.disabled = true; btn.textContent = 'Deleting\u2026'; }
          api('/admin/convert/'+job.id+'/delete-converted', { method:'POST', body:{ confirm:true } }).then(function(res){
            if (!res || !res.ok) {
              if (btn) { btn.disabled = false; btn.textContent = 'Delete'; }
              return toast((res && res.error) || 'Delete failed', { kind:'bad', ms: 6500 });
            }
            toast('Conversion deleted - original kept', { kind:'ok' });
            refreshConvertAdmin();
          }).catch(function(){
            if (btn) { btn.disabled = false; btn.textContent = 'Delete'; }
            toast('Delete failed', { kind:'bad' });
          });
        });
      }
      function paintJobRow(job){
        var actions = [];
        var needsAction = job.status === 'done' && !job.resolved;
        var pct = jobProgressPct(job);
        if (job.status === 'queued' || job.status === 'running') {
          actions.push(h('button', { className:'btn ghost cv-glass-btn', text:'Cancel', onClick: function(){ cancelJob(job); }}));
        }
        if (job.status === 'cancelled' || job.status === 'failed') {
          actions.push(h('button', {
            className: 'btn ghost cv-trash cv-glass-btn',
            title: 'Remove from queue and clean partial convert files',
            'aria-label': 'Remove from queue',
            text: '\uD83D\uDDD1',
            onClick: function(e){ discardConvertJob(job, e.currentTarget); }
          }));
        }
        if (needsAction) {
          actions.push(h('button', { className:'btn primary cv-glass-btn', text:'Preview', title:'Watch converted file in overlay', onClick: function(){ previewConvertJob(job); }}));
          actions.push(h('button', { className:'btn danger cv-remove-original cv-glass-btn', text:'Replace', title:'Replace original with converted file', onClick: function(e){
            removeConvertOriginal(job, e.currentTarget);
          }}));
          actions.push(h('button', { className:'btn ghost cv-glass-btn', text:'Delete', title:'Delete bad conversion and keep original', onClick: function(e){
            deleteConvertOutput(job, e.currentTarget);
          }}));
          actions.push(h('button', { className:'btn ghost cv-glass-btn', text:'Keep both', onClick: function(e){
            resolveConvertJob(job, e.currentTarget);
          }}));
        }
        var stateLabel = String(job.status||'').toUpperCase();
        if (job.status === 'running') stateLabel = 'Converting ' + Math.round(pct) + '%';
        if (job.status === 'queued') stateLabel = 'Queued \u2014 waiting';
        if (job.status === 'done') stateLabel = job.resolved ? 'Done' : 'Converted \u2014 choose what to keep';
        var modeNote = job.quality_mode ? String(job.quality_mode) : '';
        if (job.strategy) modeNote = (modeNote ? modeNote + ' / ' : '') + job.strategy;
        var reason = [stateLabel, job.ext ? (String(job.ext).toUpperCase() + ' \u2192 ' + String(job.container || 'mp4').toUpperCase()) : null, modeNote || null, job.message || null].filter(Boolean).join(' \u00b7 ');

        var fakeItem = { id: job.item_id, title: job.title, show: null, type: 'movies', has_poster: true };
        var fill = h('div', {
          className: 'cv-glass-fill' + (job.status === 'running' ? ' is-pulsing' : ''),
          style: 'width:' + (job.status === 'queued' ? '8' : pct) + '%;'
        });
        return h('div', {
          className: 'cv-glass-row cv-job ' + statusClass(job.status) + (needsAction ? ' cv-awaiting' : '') + (job.resolved ? ' cv-resolved' : '') + (job.status === 'running' ? ' is-converting' : ''),
          'data-job': job.id || ''
        }, [
          fill,
          h('div', { className:'cv-glass-body' }, [
            h('span', { className:'cv-glass-check-spacer', 'aria-hidden':'true' }),
            makePosterThumb(fakeItem),
            h('div', { className:'cv-glass-main' }, [
              h('div', { className:'cv-glass-title', text: job.title || job.item_id || 'Job' }),
              h('div', { className:'cv-glass-reason muted', text: reason }),
              h('div', { className:'muted cv-glass-path', text: (job.src || '') + (job.dest ? (' \u2192 ' + job.dest) : '') })
            ]),
            h('div', { className:'cv-glass-side' }, [
              (job.status === 'running' || job.status === 'done')
                ? h('span', { className:'cv-glass-pct', text: Math.round(pct) + '%' })
                : h('span', { className:'cv-glass-pct muted', text: job.status === 'queued' ? 'Queued' : '' }),
              h('div', { className:'row-actions' }, actions)
            ])
          ])
        ]);
      }

      function paintItemRow(it, opts){
        opts = opts || {};
        var jobs = jobByItemId();
        var job = jobs[it.id];
        var active = !!(job && (job.status === 'queued' || job.status === 'running'));
        var pct = active ? jobProgressPct(job) : 0;
        var checked = !!findState.selected[it.id];
        var actions = [];
        if (active) {
          actions.push(h('button', { className:'btn ghost cv-glass-btn', text:'Cancel', onClick: function(){ cancelJob(job); }}));
        } else {
          actions.push(h('button', { className:'btn primary cv-glass-btn', text:'Convert', onClick: function(){
            enqueueIds([it.id], 'Queued');
          }}));
        }
        var fill = h('div', {
          className: 'cv-glass-fill' + (active && job.status === 'running' ? ' is-pulsing' : ''),
          style: 'width:' + (active ? (job.status === 'queued' ? '8' : pct) : '0') + '%;'
        });
        var epLabel = '';
        if (it.season != null && it.episode != null) {
          epLabel = 'S' + String(it.season).padStart(2,'0') + 'E' + String(it.episode).padStart(2,'0') + ' \u00b7 ';
        }
        var title = opts.compactTitle
          ? (epLabel + (it.episode_title || it.title || it.id))
          : (it.title || it.id);
        return h('div', {
          className: 'cv-glass-row' + (active ? (' is-converting ' + statusClass(job.status)) : ' needs') + (opts.nested ? ' is-nested' : ''),
          'data-item': it.id || ''
        }, [
          fill,
          h('div', { className:'cv-glass-body' }, [
            h('label', { className:'cv-glass-check', onClick: function(e){ e.stopPropagation(); } }, [
              h('input', {
                type:'checkbox',
                checked: checked,
                disabled: active,
                onChange: function(e){
                  if (e.target.checked) findState.selected[it.id] = true;
                  else delete findState.selected[it.id];
                  paintUnified();
                }
              })
            ]),
            makePosterThumb(it),
            h('div', { className:'cv-glass-main' }, [
              h('div', { className:'cv-glass-title', text: title }),
              h('div', { className:'cv-glass-reason', text: active
                ? ((job.status === 'running' ? ('Converting ' + Math.round(pct) + '%') : 'Queued') + ' \u00b7 ' + convertReason(it))
                : convertReason(it) })
            ]),
            h('div', { className:'cv-glass-side' }, [
              active && job.status === 'running'
                ? h('span', { className:'cv-glass-pct', text: Math.round(pct) + '%' })
                : h('span', { className:'cv-codec-pill needs', text: codecLabel(it) }),
              h('div', { className:'row-actions' }, actions)
            ])
          ])
        ]);
      }

      function paintShowAccordion(group){
        var key = group.key;
        var open = !!findState.expanded[key];
        var eps = group.episodes || [];
        var activeMap = activeItemIds();
        var activeCount = eps.filter(function(ep){ return activeMap[ep.id]; }).length;
        var selectedCount = eps.filter(function(ep){ return findState.selected[ep.id]; }).length;
        var allSelected = eps.length && selectedCount === eps.length;
        var showItem = {
          id: group.show_key || eps[0] && eps[0].id,
          show_key: group.show_key,
          show: group.show,
          title: group.show,
          type: group.type || 'tv',
          section: group.section || 'tv',
          has_poster: group.has_poster || (eps[0] && eps[0].has_poster),
          season: eps[0] && eps[0].season
        };
        var head = h('div', {
          className: 'cv-glass-accordion-head',
          role: 'button',
          tabIndex: 0,
          onClick: function(){
            findState.expanded[key] = !findState.expanded[key];
            paintUnified();
          },
          onKeyDown: function(e){
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); findState.expanded[key] = !findState.expanded[key]; paintUnified(); }
          }
        }, [
          h('label', { className:'cv-glass-check', onClick: function(e){ e.stopPropagation(); } }, [
            h('input', {
              type:'checkbox',
              checked: allSelected,
              onChange: function(e){
                eps.forEach(function(ep){
                  if (e.target.checked) findState.selected[ep.id] = true;
                  else delete findState.selected[ep.id];
                });
                paintUnified();
              }
            })
          ]),
          makePosterThumb(showItem, 'is-show'),
          h('div', { className:'cv-glass-main' }, [
            h('div', { className:'cv-glass-title', text: group.show }),
            h('div', { className:'cv-glass-reason muted', text:
              eps.length + ' episode' + (eps.length === 1 ? '' : 's') + ' need convert' +
              (activeCount ? (' \u00b7 ' + activeCount + ' converting') : '') +
              (selectedCount && !allSelected ? (' \u00b7 ' + selectedCount + ' selected') : '')
            })
          ]),
          h('div', { className:'cv-glass-side' }, [
            h('span', { className:'cv-glass-chevron' + (open ? ' is-open' : ''), text: open ? '\u25BE' : '\u25B8' }),
            h('button', {
              className:'btn primary cv-glass-btn',
              text: 'Convert all',
              onClick: function(e){
                e.stopPropagation();
                var ids = eps.map(function(ep){ return ep.id; }).filter(function(id){ return !activeMap[id]; });
                if (!ids.length) return toast('All episodes already queued');
                confirmDialog('Queue ' + ids.length + ' episode' + (ids.length===1?'':'s') + ' of "' + group.show + '" for DirectPlay convert?', { okText:'Convert all' }).then(function(ok){
                  if (ok) enqueueIds(ids, 'Queued episodes');
                });
              }
            })
          ])
        ]);
        var body = null;
        if (open) {
          body = h('div', { className:'cv-glass-accordion-body' },
            eps.map(function(ep){ return paintItemRow(ep, { nested: true, compactTitle: true }); })
          );
        }
        return h('div', { className:'cv-glass-accordion' + (open ? ' is-open' : '') }, [head, body].filter(Boolean));
      }

      function paintUnified(){
        var host = $('#cv_batch_list'); if (!host) return;
        host.innerHTML = '';
        var queue = lastQueue || [];
        var active = queue.filter(function(j){ return j.status === 'queued' || j.status === 'running'; });
        var awaiting = queue.filter(function(j){ return j.status === 'done' && !j.resolved; });
        var finished = queue.filter(function(j){ return j.status !== 'queued' && j.status !== 'running' && !(j.status === 'done' && !j.resolved); });
        var orderedJobs = []
          .concat(queue.filter(function(j){ return j.status === 'running'; }))
          .concat(queue.filter(function(j){ return j.status === 'queued'; }))
          .concat(awaiting)
          .concat(finished);

        host.appendChild(h('div', { className:'cv-batch-head cv-glass-head' }, [
          h('strong', { text: active.length ? (active.length + ' converting') : 'Batch converter' }),
          h('span', { className:'muted', text:
            (awaiting.length ? (awaiting.length + ' need action \u00b7 ') : '') +
            (findState.found ? (findState.found + ' need convert \u00b7 ') : '') +
            'Mode: ' + (modeState.mode || 'directplay')
          })
        ]));

        // Controls: scope checkboxes (same style as Poster optimise) + Find all
        function scopeCheck(key, label){
          return h('label', { className:'check-label' }, [
            h('input', {
              type:'checkbox',
              'data-scope': key,
              checked: !!findState.scopes[key],
              disabled: !!findState.running,
              onChange: function(e){
                if (findState.running) { e.target.checked = !!findState.scopes[key]; return; }
                findState.scopes[key] = !!e.target.checked;
                if (!selectedScopes().length) {
                  findState.scopes[key] = true;
                  e.target.checked = true;
                }
                updateFindIndicator();
              }
            }),
            h('span', { text: label })
          ]);
        }
        host.appendChild(h('div', { className:'cv-find-bar' }, [
          h('div', { className:'cv-scope-checks', title:'Restrict Find all (games are never scanned)' }, [
            scopeCheck('movies', 'Movies'),
            scopeCheck('tv', 'TV'),
            scopeCheck('anime', 'Anime')
          ]),
          h('button', { id:'cv_find_all_btn', className:'btn primary', text: findState.running ? 'Searching\u2026' : 'Find all', disabled: !!findState.running, onClick: function(){
            if (!selectedScopes().length) return toast('Select Movies, TV, or Anime first', { kind:'bad' });
            startFindAll(true);
          }}),
          h('button', { id:'cv_find_stop_btn', className:'btn ghost', text:'Stop', hidden: !findState.running, onClick: function(){ stopFindAll(); } }),
          h('button', { className:'btn ghost', text:'Convert selected (' + selectedIds().length + ')', onClick: function(){
            var ids = selectedIds().filter(function(id){ return !activeItemIds()[id]; });
            if (!ids.length) return toast('Select items that need convert first');
            enqueueIds(ids, 'Queued selected');
          }}),
          h('button', { className:'btn ghost', text:'Clear results', onClick: function(){
            if (findState.running) return toast('Stop the scan first');
            findState.items = [];
            findState.found = 0;
            findState.scanned = 0;
            findState.total = 0;
            findState.offset = 0;
            findState.stopped = false;
            findState.selected = {};
            paintUnified();
          }})
        ]));
        host.appendChild(h('div', { id:'cv_find_status', className:'cv-find-status' + (findState.running ? ' is-searching' : '') }));
        updateFindIndicator();

        if (orderedJobs.length) {
          host.appendChild(h('div', { className:'cv-glass-label', text: 'Queue' }));
          orderedJobs.forEach(function(job){ host.appendChild(paintJobRow(job)); });
        }

        var activeIds = activeItemIds();
        var discover = (findState.items || []).filter(function(it){ return it && it.id && !activeIds[it.id]; });
        var grouped = groupDiscover(discover);

        if (!orderedJobs.length && !discover.length && !findState.running && findState.scanned === 0) {
          host.appendChild(h('p', { className:'muted cv-batch-empty', text:'Pick Movies / TV / Anime and press Find all. Results appear live as codecs are checked. Stop anytime \u2014 found items stay in the list.' }));
        } else if (!discover.length && findState.running) {
          host.appendChild(h('p', { className:'muted cv-batch-empty', text:'Scanning\u2026 matches will appear here as they are found.' }));
        } else if (!discover.length && !findState.running === false && findState.scanned > 0 && !orderedJobs.length) {
          host.appendChild(h('p', { className:'muted cv-batch-empty', text: findState.stopped
            ? ('Stopped early with no DirectPlay gaps found yet in the ' + findState.scanned + ' videos checked.')
            : 'Nothing needs convert \u2014 scanned library looks DirectPlay-ready.' }));
        } else if (discover.length) {
          host.appendChild(h('div', { className:'cv-glass-label', text: 'Needs convert' }));
          var list = h('div', { className:'cv-glass-list' });
          grouped.order.forEach(function(entry){
            if (entry.kind === 'show') list.appendChild(paintShowAccordion(grouped.shows[entry.key]));
            else list.appendChild(paintItemRow(entry.item));
          });
          host.appendChild(list);
        }

        ensureBatchPoll(active.length > 0);
      }

      function mergeFindItems(chunk){
        var seen = {};
        findState.items.forEach(function(it){ if (it && it.id) seen[it.id] = true; });
        ((chunk) || []).forEach(function(it){
          if (!it || !it.id || seen[it.id]) return;
          seen[it.id] = true;
          findState.items.push(it);
        });
        findState.items.sort(function(a, b){
          var ta = String(a.show || a.title || a.id || '').toLowerCase();
          var tb = String(b.show || b.title || b.id || '').toLowerCase();
          var c = ta.localeCompare(tb);
          if (c !== 0) return c;
          var sa = +(a.season || 0), sb = +(b.season || 0);
          if (sa !== sb) return sa - sb;
          return +(a.episode || 0) - +(b.episode || 0);
        });
        findState.found = findState.items.length;
      }

      function startFindAll(reset){
        if (findState.running) return;
        var scopes = selectedScopes();
        if (!scopes.length) {
          toast('Select Movies, TV, or Anime first', { kind:'bad' });
          return;
        }
        findState.cancelled = false;
        findState.stopped = false;
        findState.running = true;
        findState.gen += 1;
        var gen = findState.gen;
        if (reset) {
          findState.offset = 0;
          findState.scanned = 0;
          findState.total = 0;
          findState.found = 0;
          findState.items = [];
          findState.selected = {};
        }
        updateFindIndicator();
        paintUnified();

        function step(){
          if (!$('#cv_batch_list')) {
            findState.running = false;
            return;
          }
          if (gen !== findState.gen || findState.cancelled) {
            findState.running = false;
            findState.stopped = true;
            updateFindIndicator();
            paintUnified();
            return;
          }
          var url = '/admin/convert/find-needs?offset=' + encodeURIComponent(findState.offset) +
            '&limit=8&types=' + encodeURIComponent(scopes.join(','));
          api(url).then(function(r){
            if (gen !== findState.gen) {
              findState.running = false;
              return;
            }
            // Always keep whatever this chunk found, even if user just hit Stop
            if (r && r.ok !== false) {
              findState.scanned = r.scanned || findState.scanned;
              findState.total = r.total_video || findState.total;
              findState.offset = r.next_offset != null ? r.next_offset : findState.offset;
              mergeFindItems(r.items);
            }
            if (findState.cancelled) {
              findState.running = false;
              findState.stopped = true;
              updateFindIndicator();
              paintUnified();
              return;
            }
            if (!r || r.ok === false) {
              findState.running = false;
              updateFindIndicator();
              toast((r && r.error) || 'Find all failed', { kind:'bad' });
              paintUnified();
              return;
            }
            // Live list update after every chunk
            paintUnified();
            if (r.done) {
              findState.running = false;
              findState.stopped = false;
              updateFindIndicator();
              paintUnified();
              return;
            }
            setTimeout(step, 20);
          }).catch(function(){
            findState.running = false;
            updateFindIndicator();
            toast('Find all failed', { kind:'bad' });
            paintUnified();
          });
        }
        setTimeout(step, 0);
      }

      function refreshConvertAdmin(opts){
        opts = opts || {};
        api('/admin/convert').then(function(r){
          var pending = r.pending_requests || [];
          updateConvertNotif(pending.length);
          var box = $('#cv_requests'); if (box) {
            box.innerHTML = '';
            var all = r.requests || [];
            if (!pending.length) {
              box.appendChild(h('p', { className:'muted', text:'No pending convert requests from TVs.' }));
            } else {
              box.appendChild(h('p', { className:'muted', text: pending.length + ' pending request(s). Click a request to confirm into the batch converter. Trash removes it from the list.' }));
            }
            (pending.length ? pending : all.slice(-8).reverse()).forEach(function(req){
              var jobNote = req.convert_job_id ? (' \u00b7 job ' + req.convert_job_id) : '';
              var isPending = req.status === 'pending';
              var accepted = !!(req.convert_job_id);
              var actions = h('div', { className:'row-actions cv-req-actions' }, [
                isPending && !accepted
                  ? h('button', { className:'btn primary', text:'Accept & queue', onClick: function(e){
                      e.stopPropagation();
                      acceptConvertRequest(req, { skipConfirm: true });
                    }})
                  : null,
                isPending
                  ? h('button', { className:'btn ghost', text:'Mark done', onClick: function(e){
                      e.stopPropagation();
                      resolveConvertRequest(req, 'done');
                    }})
                  : null,
                isPending
                  ? h('button', { className:'btn ghost', text:'Dismiss', onClick: function(e){
                      e.stopPropagation();
                      resolveConvertRequest(req, 'dismiss');
                    }})
                  : null,
                h('button', {
                  className: 'btn ghost cv-trash',
                  title: 'Delete from request list',
                  'aria-label': 'Delete request',
                  text: '\uD83D\uDDD1',
                  onClick: function(e){ deleteConvertRequest(req, e); }
                })
              ].filter(Boolean));

              box.appendChild(h('div', {
                className: 'cv-card' + (isPending && !accepted ? ' cv-card-pending cv-card-clickable' : ''),
                role: isPending && !accepted ? 'button' : null,
                tabIndex: isPending && !accepted ? 0 : null,
                title: isPending && !accepted ? 'Click to accept into batch converter' : null,
                onClick: function(){
                  if (isPending && !accepted) acceptConvertRequest(req);
                },
                onKeyDown: function(e){
                  if (isPending && !accepted && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); acceptConvertRequest(req); }
                }
              }, [
                h('div', { className:'cv-card-main' }, [
                  h('div', { className:'cv-card-title', text: req.title || req.item_id || 'Request' }),
                  h('div', { className:'muted cv-card-meta', text: [
                    String(req.status || '').toUpperCase(),
                    req.device || null,
                    req.ext ? String(req.ext).toUpperCase() : null,
                    req.reason || null,
                    jobNote || null,
                    req.message || null
                  ].filter(Boolean).join(' \u00b7 ') }),
                  h('div', { className:'muted cv-card-path', text: req.path || '' })
                ]),
                actions
              ]));
            });
          }
          lastQueue = r.queue || [];
          paintUnified();
        }).catch(function(){
          if (!opts.quiet) toast('Could not refresh convert status', { kind:'bad' });
        });
      }

      function collectConvertSettings(){
        return {
          enabled: !!( $('#cv_en') && $('#cv_en').checked ),
          one_job_at_a_time: !!( $('#cv_one') && $('#cv_one').checked ),
          threads: cvThreadsValue() || 1,
          quality_mode: modeState.mode || 'directplay',
          crf: $('#crf') ? +$('#crf').value : 18,
          preset: $('#preset') ? $('#preset').value : 'medium',
          audio_bitrate: $('#audio_bitrate') ? $('#audio_bitrate').value : '320k',
          nice: $('#nice') ? +$('#nice').value : 19,
          ionice_class: $('#ionice_class') ? +$('#ionice_class').value : 3,
          target_video: $('#target_video') ? $('#target_video').value : 'libx264',
          target_audio: $('#target_audio') ? $('#target_audio').value : 'aac',
          target_container: $('#target_container') ? $('#target_container').value : 'mp4',
          preserve_resolution: true
        };
      }

      var modeCards = QUALITY_MODES.map(function(m){
        return h('button', {
          type:'button',
          className: 'cv-mode-card' + (modeState.mode === m.id ? ' is-active' : ''),
          'data-mode': m.id,
          onClick: function(){
            modeState.mode = m.id;
            syncModeUi();
          }
        }, [
          h('strong', { text: m.title }),
          h('span', { className:'muted', text: m.blurb })
        ]);
      });

      var root = h('div', { className:'stack convert-admin' }, [
        h('section', { className:'cv-section settings-card' }, [
          h('h3', { className:'admin-section-title', text:'Quality mode' }),
          h('p', { className:'muted', text:'Pick how converts run. Original remux keeps quality and size; DirectPlay smart avoids unnecessary re-encodes so the server stays responsive.' }),
          h('div', { className:'cv-mode-grid' }, modeCards),
          h('p', { id:'cv_mode_hint', className:'cv-mode-hint muted', text: (QUALITY_MODES.filter(function(m){ return m.id === modeState.mode; })[0] || {}).blurb || '' })
        ]),

        h('section', { className:'cv-section settings-card', id:'cv_encode_controls' }, [
          h('h3', { className:'admin-section-title', text:'Encode settings' }),
          h('p', { className:'muted', text:'Used when a mode re-encodes video. Resolution is always preserved (no scale). Lower CRF = higher quality / larger files. Avoid CRF 0 \u2014 use Original remux for lossless.' }),
          sliderEl('crf', 'CRF quality', c.crf!=null?c.crf:18, 14, 28, 1),
          h('p', { id:'cv_crf_hint', className:'muted cv-hint', text:'' }),
          selectEl('preset', 'x264 speed preset', c.preset||'medium', [
            { value:'ultrafast', label:'ultrafast (lightest CPU)' },
            { value:'superfast', label:'superfast' },
            { value:'veryfast', label:'veryfast' },
            { value:'faster', label:'faster' },
            { value:'fast', label:'fast' },
            { value:'medium', label:'medium (recommended)' },
            { value:'slow', label:'slow (heavier CPU)' },
            { value:'slower', label:'slower' },
            { value:'veryslow', label:'veryslow (avoid on server)' }
          ]),
          selectEl('audio_bitrate', 'Audio bitrate (when re-encoding audio)', c.audio_bitrate||'320k', [
            '128k','160k','192k','256k','320k'
          ]),
          h('div', { id:'cv_custom_controls', className:'cv-custom-grid' }, [
            selectEl('target_video', 'Video codec', c.target_video||'libx264', [
              { value:'libx264', label:'H.264 (libx264)' },
              { value:'copy', label:'Copy (no re-encode)' }
            ]),
            selectEl('target_audio', 'Audio codec', c.target_audio||'aac', [
              { value:'aac', label:'AAC' },
              { value:'copy', label:'Copy (no re-encode)' }
            ])
          ])
        ]),

        h('section', { className:'cv-section settings-card' }, [
          h('h3', { className:'admin-section-title', text:'Output & server load' }),
          h('p', { className:'muted', text:'Output is written beside the original as <name>.directplay.<container>. Jobs run niced so Live/VoD stay responsive.' }),
          selectEl('target_container', 'Container', c.target_container||'mp4', [
            { value:'mp4', label:'MP4 (best DirectPlay)' },
            { value:'m4v', label:'M4V' },
            { value:'mov', label:'MOV' },
            { value:'mkv', label:'MKV' }
          ]),
          h('div', { className:'feature-card cv-feature-card' }, [
            cvToggleRow('cv_en', 'Enable ffmpeg convert queue', 'Queue and run conversions on this server.', !!c.enabled),
            cvToggleRow('cv_one', 'One job at a time', 'Recommended. Runs queued conversions one after another.', c.one_job_at_a_time !== false),
            h('label', { className:'feature-row', 'for':'cv_threads' }, [
              h('span', { className:'feature-text' }, [
                h('strong', { text:'Conversion threads' }),
                h('span', { className:'muted', id:'cv_threads_hint', text:'CPU threads each conversion may use (1\u2013' + cvMaxThreads + ' on this server). 1 keeps Live and VoD responsive; more finishes sooner.' })
              ]),
              h('input', { id:'cv_threads', type:'number', className:'feature-number', min:'1', max:String(cvMaxThreads), step:'1', inputmode:'numeric', value:String(c.threads || 1), 'aria-describedby':'cv_threads_hint' })
            ])
          ]),
          sliderEl('nice', 'CPU nice (higher = kinder to server)', c.nice!=null?c.nice:19, 0, 19, 1),
          selectEl('ionice_class', 'Disk I/O class', c.ionice_class!=null?c.ionice_class:3, [
            { value:'1', label:'1 \u2014 realtime (avoid)' },
            { value:'2', label:'2 \u2014 best-effort' },
            { value:'3', label:'3 \u2014 idle (recommended)' }
          ]),
          h('div', { className:'row-actions' }, [
            h('button', { className:'btn primary', text:'Save convert settings', onClick: function(){
              if (cvThreadsValue() === null) { toast('Conversion threads must be a whole number from 1 to ' + cvMaxThreads + '.', { kind:'bad' }); var te = $('#cv_threads'); if (te) te.focus(); return; }
              saveSettings({ convert: collectConvertSettings() });
            }}),
            h('button', { className:'btn ghost', text:'Reset DirectPlay defaults', onClick: function(){
              modeState.mode = 'directplay';
              if ($('#crf')) { $('#crf').value = '18'; if ($('#crf_val')) $('#crf_val').textContent = '18'; }
              if ($('#preset')) $('#preset').value = 'medium';
              if ($('#audio_bitrate')) $('#audio_bitrate').value = '320k';
              if ($('#nice')) { $('#nice').value = '19'; if ($('#nice_val')) $('#nice_val').textContent = '19'; }
              if ($('#ionice_class')) $('#ionice_class').value = '3';
              if ($('#target_container')) $('#target_container').value = 'mp4';
              if ($('#cv_threads')) $('#cv_threads').value = '1';
              if ($('#target_video')) $('#target_video').value = 'libx264';
              if ($('#target_audio')) $('#target_audio').value = 'aac';
              if ($('#cv_en')) $('#cv_en').checked = true;
              if ($('#cv_one')) $('#cv_one').checked = true;
              syncModeUi();
              toast('Form reset to DirectPlay smart \u2014 click Save to apply');
            }})
          ])
        ]),

        h('section', { className:'cv-section settings-card cv-batch-section' }, [
          h('h3', { className:'admin-section-title', text:'Batch video converter' }),
          h('p', { className:'muted', text:'Glass queue + Find all for DirectPlay gaps (real H.264/AAC). Restrict to Movies / TV / Anime — games are never scanned. Results stream in live; Stop keeps what was found. TV series stay in accordions.' }),
          h('div', { id:'cv_batch_list', className:'cv-batch-list cv-glass-wrap' }, [ pendingContent({ className:'muted', }) ]),
          h('div', { className:'row-actions' }, [
            h('button', { className:'btn', text:'Refresh queue', onClick: function(){ refreshConvertAdmin(); } }),
            h('button', { className:'btn ghost', text:'Clear finished', onClick: function(){
              api('/admin/convert/clear-finished', { method:'POST' }).then(function(r){
                toast(r.ok ? 'Cleared finished jobs' : (r.error||'Failed'), { kind: r.ok?'ok':'bad' });
                refreshConvertAdmin();
              });
            }})
          ])
        ]),

        h('section', { className:'cv-section settings-card' }, [
          h('h3', { className:'admin-section-title', text:'TV convert requests' }),
          h('p', { className:'muted', text:'Inbox for TV DirectPlay failures. Click a pending request to queue it; trash removes it from the list.' }),
          h('div', { id:'cv_requests' })
        ])
      ]);
      setTimeout(function(){
        syncModeUi();
        updateCrfHint(c.crf!=null?+c.crf:18);
        refreshConvertAdmin();
        // Find all is manual — only runs when the user clicks Find all
      }, 0);
      if (typeof MutationObserver !== 'undefined') {
        setTimeout(function(){
          var obs = new MutationObserver(function(){
            if (!$('#cv_batch_list')) {
              findState.cancelled = true;
              findState.running = false;
              stopBatchPoll();
              obs.disconnect();
            }
          });
          if (root.parentNode) obs.observe(root.parentNode, { childList:true, subtree:true });
        }, 50);
      }
      return root;
    }

  function fieldEl(id, label, value, type){
    return h('label', {}, [ label, h('input', { id:id, value: value==null?'':String(value), type: type||'text' }) ]);
  }
  function formBlock(fields, onSave){
    return h('div', { className:'stack' }, fields.map(function(f){
      return fieldEl(f.id, f.label, f.value, f.type);
    }).concat([
      h('button', { className:'btn primary', text:'Save', onClick: function(){
        var data = {};
        fields.forEach(function(f){
          var v = $('#'+f.id).value;
          data[f.id] = f.type==='number' ? +v : v;
        });
        onSave(data);
      }})
    ]));
  }
  function saveSettings(patch){
    var applySeed = !!(patch && patch._apply_transmission_seeding);
    if (patch && patch._apply_transmission_seeding != null) {
      patch = Object.assign({}, patch);
      delete patch._apply_transmission_seeding;
    }
    api('/admin/settings', { method:'POST', body: patch }).then(function(j){
      if (!j.ok) return toast(j.error||'Failed', { kind:'bad' });
      if (j.settings) state.settings = j.settings;
      if (j.warning) toast(j.warning, { kind:'warn', ms:6000 });
      if (applySeed) {
        return api('/admin/transmission/seeding', { method:'POST', body:{} }).then(function(r){
          if (r.ok) {
            var msg = 'Settings saved';
            if (r.policy && !r.policy.allow_seeding) msg += ' · seeding disabled on Transmission';
            else if (r.policy && r.policy.allow_seeding) msg += ' · seeding allowed';
            if (r.torrents_updated) msg += ' · updated '+r.torrents_updated+' torrent(s)';
            toast(msg, { kind:'ok' });
          } else {
            toast('Settings saved, but Transmission seed apply failed: '+(r.reason||r.error||'unknown'), { kind:'bad' });
          }
        });
      }
      toast('Settings saved', { kind:'ok' });
    });
  }


  function abortOp(key){
    if (state.abortControllers[key]) {
      try { state.abortControllers[key].abort(); } catch(e){}
    }
    state.abortControllers[key] = null;
  }
  function apiAbortable(path, opts, key){
    abortOp(key);
    var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    if (ctrl) state.abortControllers[key] = ctrl;
    opts = opts || {};
    if (ctrl) opts.signal = ctrl.signal;
    return api(path, opts).finally(function(){
      if (state.abortControllers[key] === ctrl) state.abortControllers[key] = null;
    });
  }

  function renderMediaManager(s){
    var wrap = h('div', { className:'stack' });
    wrap.appendChild(h('h3', { text:'Media Manager' }));
    wrap.appendChild(h('p', { className:'muted', text:'Browse and edit catalogue items, fix wrong show assignments, search TVMaze/Wikipedia metadata, browse library folders, convert / move / ingest. Nothing runs in the background — only when you click.' }));
    var secOpts = [h('option', { value:'', text:'All sections' })].concat((s.sections||[]).map(function(sec){
      return h('option', { value:sec.id, text:(sec.label||sec.id), selected: (state.mm&&state.mm.section)===sec.id });
    }));
    wrap.appendChild(h('div', { className:'row-actions' }, [
      h('select', { id:'mm_section', onChange: function(){ state.mm.section=$('#mm_section').value; state.mm.page=1; mmLoadCatalogue(); } }, secOpts),
      h('select', { id:'mm_mode' }, [
        h('option', { value:'auto', text:'Auto (shows for TV)', selected: !state.mm || state.mm.mode==='auto' }),
        h('option', { value:'items', text:'Items', selected: state.mm&&state.mm.mode==='items' }),
        h('option', { value:'shows', text:'Shows', selected: state.mm&&state.mm.mode==='shows' }),
        h('option', { value:'items_noposter', text:'Items — no poster', selected: state.mm&&state.mm.mode==='items_noposter' }),
        h('option', { value:'shows_noposter', text:'Shows — no poster', selected: state.mm&&state.mm.mode==='shows_noposter' })
      ]),
      h('input', { id:'mm_q', placeholder:'Filter…', value:(state.mm&&state.mm.q)||'', onKeydown: function(e){ if(e.key==='Enter'){ state.mm.q=$('#mm_q').value; state.mm.page=1; mmLoadCatalogue(); } } }),
      h('button', { className:'btn primary', text:'Browse catalogue', onClick: function(){
        state.mm.section = ($('#mm_section')||{}).value||'';
        state.mm.mode = ($('#mm_mode')||{}).value||'auto';
        state.mm.q = ($('#mm_q')||{}).value||'';
        state.mm.page = 1;
        mmLoadCatalogue();
      }}),
      h('button', { className:'btn ghost', text:'Cancel', onClick: function(){ abortOp('mm_browse'); toast('Cancelled'); } })
    ]));
    wrap.appendChild(h('div', { id:'mm_status', className:'muted', text:'Idle' }));
    wrap.appendChild(h('div', { id:'mm_list', className:'stack' }));
    wrap.appendChild(h('div', { id:'mm_editor', className:'stack mm-editor', style:'display:none' }));
    wrap.appendChild(h('hr'));
    wrap.appendChild(h('h3', { text:'File browser' }));
    wrap.appendChild(h('p', { className:'muted', text:'Library paths: Movies, TvShows, Anime, Roms, Disney. Convert, move/rename into managed structure, or ingest after move.' }));
    wrap.appendChild(h('div', { className:'row-actions' }, [
      h('button', { className:'btn', text:'Open roots', onClick: function(){ mmBrowseFiles(''); } })
    ]));
    wrap.appendChild(h('div', { id:'mm_files_path', className:'muted', text:'' }));
    wrap.appendChild(h('div', { id:'mm_files', className:'stack' }));
    wrap.appendChild(h('hr'));
    wrap.appendChild(h('h3', { text:'Missing media / path diagnose' }));
    wrap.appendChild(h('p', { className:'muted', text:'Compare catalogue entries to library folders (Movies, TvShows, Anime…). Find missing files / orphans. Safe fixes: relink unique match, remove dead entry, ingest orphan. No unsolicited background work.' }));
    wrap.appendChild(h('div', { className:'row-actions' }, [
      h('button', { className:'btn primary', text:'Diagnose missing', onClick: function(){ mmDiagnose(false); } }),
      h('button', { className:'btn', text:'Diagnose + orphans', onClick: function(){ mmDiagnose(true); } }),
      h('button', { className:'btn ghost', text:'Cancel', onClick: function(){ abortOp('mm_diag'); toast('Cancelled'); } })
    ]));
    wrap.appendChild(h('div', { id:'mm_diag_status', className:'muted', text:'Idle' }));
    wrap.appendChild(h('div', { id:'mm_diag', className:'stack' }));
    setTimeout(function(){ mmLoadCatalogue(); }, 0);
    return wrap;
  }

  function mmDiagnose(withOrphans){
    var status = $('#mm_diag_status'); var box = $('#mm_diag');
    if (status) status.textContent = 'Scanning…';
    if (box) box.innerHTML = '';
    var qs = '?limit=2000&orphan_limit=100' + (withOrphans ? '&orphans=1' : '&orphans=0');
    apiAbortable('/admin/media/diagnose'+qs, {}, 'mm_diag').then(function(r){
      if (!r || !r.ok) { if(status) status.textContent = (r&&r.error)||'Failed'; return; }
      if (status) status.textContent = 'Checked '+(r.checked||0)+' · present '+(r.present||0)+' · missing '+(r.missing_count||0)+' · orphans '+(r.orphan_count||0);
      if (!box) return;
      function fixBtn(label, body){
        return h('button', { className:'btn', text: label, onClick: function(){
          confirmDialog('Apply fix: '+label+'?', { okText:'Apply' }).then(function(ok){
            if (!ok) return;
            api('/admin/media/diagnose/fix', { method:'POST', body: body }).then(function(res){
              toast(res.ok ? 'Fixed' : (res.error||'Failed'), { kind: res.ok?'ok':'bad' });
              if (res.ok) mmDiagnose(withOrphans);
            });
          });
        }});
      }
      (r.missing||[]).forEach(function(m){
        var acts = [];
        (m.suggestions||[]).forEach(function(s){
          if (s.action === 'remove_dead') acts.push(fixBtn('Remove dead', { action:'remove_dead', id: m.id }));
          if (s.action === 'relink' && s.safe) acts.push(fixBtn('Relink', { action:'relink', id: m.id, new_path: s.new_path }));
          if (s.action === 'relink_choices') {
            (s.candidates||[]).forEach(function(c,i){
              acts.push(fixBtn('Relink #'+(i+1), { action:'relink', id: m.id, new_path: c }));
            });
          }
        });
        box.appendChild(h('div', { className:'mm-row' }, [
          h('div', { style:'flex:1;min-width:0' }, [
            h('strong', { text: (m.title||'')+' — MISSING' }),
            h('div', { className:'muted', text: m.path||'' })
          ]),
          h('div', { className:'row-actions' }, acts)
        ]));
      });
      (r.orphans||[]).forEach(function(o){
        box.appendChild(h('div', { className:'mm-row' }, [
          h('div', { style:'flex:1;min-width:0' }, [
            h('strong', { text: 'ORPHAN file' }),
            h('div', { className:'muted', text: (o.path||'')+' · '+(o.section||'') })
          ]),
          h('div', { className:'row-actions' }, [
            fixBtn('Ingest', { action:'ingest', path: o.path, section: o.section })
          ])
        ]));
      });
      if (!(r.missing||[]).length && !(r.orphans||[]).length) {
        box.appendChild(h('p', { className:'muted', text:'No mismatches found.' }));
      }
    });
  }

  function mmLoadCatalogue(){
    var status = $('#mm_status'); var list = $('#mm_list');
    if (status) status.textContent = 'Please wait…';
    if (list) list.innerHTML = '';
    var mmMode = (state.mm&&state.mm.mode)||'auto';
    var mmNoPoster = (mmMode==='items_noposter'||mmMode==='shows_noposter') ? '1' : '';
    var qs = '?section='+encodeURIComponent((state.mm&&state.mm.section)||'')
      +'&mode='+encodeURIComponent(mmMode)
      +'&q='+encodeURIComponent((state.mm&&state.mm.q)||'')
      +'&no_poster='+mmNoPoster
      +'&page='+((state.mm&&state.mm.page)||1)+'&per=40';
    apiAbortable('/admin/media/browse'+qs, {}, 'mm_browse').then(function(r){
      if (!r || r.error === 'Bad JSON') return;
      if (r._status === 0 || (r.error && String(r.error).indexOf('abort')>=0)) return;
      if (!r.ok) { if(status) status.textContent = r.error||'Failed'; return; }
      if (status) status.textContent = (r.mode||'')+' · '+(r.total||0)+' result(s) · page '+(r.page||1);
      if (!list) return;
      list.innerHTML = '';
      if (r.mode === 'shows') {
        (r.shows||[]).forEach(function(sh){
          list.appendChild(h('div', { className:'mm-row' }, [
            h('div', { style:'flex:1;min-width:0' }, [
              h('strong', { text: sh.title||'' }),
              h('div', { className:'muted', text: (sh.episode_count||sh.available_count||'?')+' eps · '+(sh.section||'') })
            ]),
            h('div', { className:'row-actions' }, [
              h('button', { className:'btn', text:'Open', onClick: function(){ openShow(sh.show_key); } }),
              h('button', { className:'btn', text:'Reassign', onClick: function(){ mmOpenReassignShow(sh); } }),
              h('button', { className:'btn', text:'Match online', onClick: function(){ mmSearchMeta(sh.title||'', 'tv', function(hit){
                if (!hit) return;
                confirmDialog('Reassign show “'+(sh.title||'')+'” → “'+hit.title+'” and rename on disk?', { okText:'Reassign + rename' }).then(function(ok){
                  if (!ok) return;
                  api('/admin/media/reassign', { method:'POST', body:{ show_key: sh.show_key, show: hit.title, rename:true } }).then(function(res){
                    toast(res.ok ? ('Updated '+(res.updated||0)) : (res.error||'Failed'), { kind: res.ok?'ok':'bad' });
                    mmLoadCatalogue();
                  });
                });
              }); } })
            ])
          ]));
        });
      } else {
        (r.items||[]).forEach(function(it){
          list.appendChild(h('div', { className:'mm-row' }, [
            h('div', { style:'flex:1;min-width:0' }, [
              h('strong', { text: it.title||'' }),
              h('div', { className:'muted', text: (it.show? (it.show+' · ') : '') + (it.year||'') + (it.season!=null?(' · S'+it.season+'E'+it.episode):'') + ' · '+(it.section||'') }),
              h('div', { className:'muted', style:'font-size:.75rem', text: it.rel||it.path||'' })
            ]),
            h('div', { className:'row-actions' }, [
              h('button', { className:'btn', text:'Edit', onClick: function(){ mmOpenItemEditor(it); } }),
              h('button', { className:'btn', text:'Match', onClick: function(){ mmSearchMeta(it.show||it.title||'', (it.type==='tv'||it.type==='anime')?'tv':'movie', function(hit){ if(hit) mmApplyMetaToItem(it, hit); }); } }),
              (it.needs_convert ? h('button', { className:'btn', text:'Convert', onClick: function(){ enqueueConvert(it); } }) : null),
              h('button', { className:'btn ghost', text:'Play', onClick: function(){ playItem(it); } })
            ].filter(Boolean))
          ]));
        });
      }
      var nav = h('div', { className:'row-actions' });
      if ((r.page||1) > 1) nav.appendChild(h('button', { className:'btn', text:'Prev', onClick: function(){ state.mm.page--; mmLoadCatalogue(); } }));
      if (((r.page||1)*(r.per||40)) < (r.total||0)) nav.appendChild(h('button', { className:'btn', text:'Next', onClick: function(){ state.mm.page++; mmLoadCatalogue(); } }));
      list.appendChild(nav);
    }).catch(function(e){ if(status) status.textContent = String(e); });
  }

  function mmOpenItemEditor(it){
    var ed = $('#mm_editor'); if (!ed) return;
    ed.style.display = '';
    ed.innerHTML = '';
    var sections = (state.settings&&state.settings.sections)||[];
    var secOpts = sections.map(function(sec){
      return h('option', { value:sec.id, text:sec.label||sec.id, selected: sec.id===it.section });
    });
    ed.appendChild(h('h3', { text:'Edit item' }));
    ed.appendChild(h('p', { className:'muted', text: it.path||it.rel||it.id }));
    ed.appendChild(fieldEl('mm_title','Title', it.title||''));
    ed.appendChild(fieldEl('mm_show','Show (TV/Anime)', it.show||''));
    ed.appendChild(fieldEl('mm_year','Year', it.year||'', 'number'));
    ed.appendChild(fieldEl('mm_season','Season', it.season||'', 'number'));
    ed.appendChild(fieldEl('mm_episode','Episode', it.episode||'', 'number'));
    ed.appendChild(h('label', {}, ['Section', h('select', { id:'mm_sec' }, secOpts)]));
    ed.appendChild(h('label', {}, [ h('input', { id:'mm_rename', type:'checkbox' }), ' Also rename/move into managed folder structure' ]));
    ed.appendChild(h('div', { className:'row-actions' }, [
      h('button', { className:'btn primary', text:'Save', onClick: function(){
        var body = {
          id: it.id,
          title: ($('#mm_title')||{}).value,
          show: ($('#mm_show')||{}).value,
          year: ($('#mm_year')||{}).value,
          season: ($('#mm_season')||{}).value,
          episode: ($('#mm_episode')||{}).value,
          section_id: ($('#mm_sec')||{}).value,
          rename: !!($('#mm_rename')||{}).checked
        };
        var go = function(){
          api('/admin/media/update', { method:'POST', body: body }).then(function(r){
            toast(r.ok ? (r.moved?'Moved + updated':'Saved') : (r.error||'Failed'), { kind:r.ok?'ok':'bad' });
            if (r.ok) { ed.style.display='none'; mmLoadCatalogue(); }
          });
        };
        if (body.rename) {
          confirmDialog('Move/rename this file on disk into the standard library layout?', { okText:'Move + save' }).then(function(ok){ if(ok) go(); });
        } else go();
      }}),
      h('button', { className:'btn', text:'Search metadata', onClick: function(){
        var kind = (it.type==='tv'||it.type==='anime') ? 'tv' : 'movie';
        mmSearchMeta(($('#mm_show')||{}).value || ($('#mm_title')||{}).value || it.title, kind, function(hit){
          if (!hit) return;
          if ($('#mm_title')) $('#mm_title').value = hit.title||'';
          if (hit.kind==='tv' && $('#mm_show')) $('#mm_show').value = hit.title||'';
          if (hit.year && $('#mm_year')) $('#mm_year').value = hit.year;
          toast('Filled from '+(hit.source||'online'));
        });
      }}),
      h('button', { className:'btn ghost', text:'Close', onClick: function(){ ed.style.display='none'; } })
    ]));
  }

  function mmOpenReassignShow(sh){
    var ed = $('#mm_editor'); if (!ed) return;
    ed.style.display = '';
    ed.innerHTML = '';
    ed.appendChild(h('h3', { text:'Reassign show' }));
    ed.appendChild(h('p', { className:'muted', text: 'Current: '+(sh.title||'') }));
    ed.appendChild(fieldEl('mm_newshow','Correct show name', sh.title||''));
    ed.appendChild(h('label', {}, [ h('input', { id:'mm_re_rename', type:'checkbox', checked:true }), ' Rename folders/files on disk' ]));
    ed.appendChild(h('div', { className:'row-actions' }, [
      h('button', { className:'btn', text:'Search TVMaze', onClick: function(){
        mmSearchMeta(($('#mm_newshow')||{}).value||sh.title, 'tv', function(hit){
          if (hit && $('#mm_newshow')) $('#mm_newshow').value = hit.title;
        });
      }}),
      h('button', { className:'btn primary', text:'Apply', onClick: function(){
        var name = ($('#mm_newshow')||{}).value;
        if (!name) return toast('Name required');
        confirmDialog('Reassign all episodes of “'+(sh.title||'')+'” to “'+name+'”?', { okText:'Reassign' }).then(function(ok){
          if (!ok) return;
          toast('Reassigning…');
          apiAbortable('/admin/media/reassign', { method:'POST', body:{
            show_key: sh.show_key,
            show: name,
            rename: !!($('#mm_re_rename')||{}).checked
          }}, 'mm_reassign').then(function(r){
            toast(r.ok ? ('Updated '+(r.updated||0)+', failed '+(r.failed||0)) : (r.error||'Failed'), { kind:r.ok?'ok':'bad' });
            if (r.ok) { ed.style.display='none'; mmLoadCatalogue(); }
          });
        });
      }}),
      h('button', { className:'btn ghost', text:'Cancel op', onClick: function(){ abortOp('mm_reassign'); toast('Cancelled'); } })
    ]));
  }

  function mmSearchMeta(query, kind, cb){
    var ed = $('#mm_editor');
    if (ed) {
      ed.style.display = '';
      var box = h('div', { id:'mm_meta_hits', className:'stack' }, [ h('p', { className:'muted', text:'Searching '+(kind||'')+'…' }) ]);
      // keep editor content if present; append hits panel
      var existing = $('#mm_meta_hits');
      if (existing) existing.remove();
      ed.appendChild(box);
    }
    apiAbortable('/admin/media/search-metadata', { method:'POST', body:{ query: query, kind: kind||'auto' } }, 'mm_meta').then(function(r){
      var box = $('#mm_meta_hits');
      if (!r.ok) { if(box) box.textContent = r.error||'Search failed'; return; }
      if (!box) {
        // modal-less: toast first hit
        if ((r.results||[])[0]) return cb(r.results[0]);
        return toast('No metadata hits');
      }
      box.innerHTML = '';
      box.appendChild(h('strong', { text: 'Online matches for “'+query+'”' }));
      if (!(r.results||[]).length) box.appendChild(h('p', { className:'muted', text:'No results' }));
      (r.results||[]).forEach(function(hit){
        box.appendChild(h('div', { className:'mm-row' }, [
          h('div', { style:'flex:1' }, [
            h('strong', { text: hit.title+(hit.year?(' ('+hit.year+')'):'') }),
            h('div', { className:'muted', text: (hit.source||'')+' · '+((hit.summary||'').slice(0,140)) })
          ]),
          h('button', { className:'btn primary', text:'Use', onClick: function(){ cb(hit); box.remove(); } })
        ]));
      });
      box.appendChild(h('button', { className:'btn ghost', text:'Close matches', onClick: function(){ box.remove(); } }));
    });
  }

  function mmApplyMetaToItem(it, hit){
    confirmDialog('Apply “'+hit.title+'”'+(hit.year?(' ('+hit.year+')'):'')+' to this item? (metadata only — tick rename in Edit to move on disk)', { okText:'Apply' }).then(function(ok){
      if (!ok) return;
      api('/admin/media/update', { method:'POST', body:{
        id: it.id,
        title: hit.title,
        show: hit.kind==='tv' ? hit.title : (it.show||''),
        year: hit.year,
        poster_url: hit.image || hit.poster_url || '',
        omdb_id: hit.id && String(hit.id).indexOf('tt')===0 ? hit.id : (hit.omdb_id||''),
        plot: hit.summary || '',
        genres: hit.genres || [],
        rename: false
      }}).then(function(r){
        toast(r.ok?'Metadata saved':(r.error||'Failed'), { kind:r.ok?'ok':'bad' });
        if (r.ok) mmLoadCatalogue();
      });
    });
  }

  function mmBrowseFiles(path){
    var list = $('#mm_files'); var pathEl = $('#mm_files_path');
    if (pathEl) pathEl.textContent = path || '(roots)';
    if (list) { list.innerHTML=''; list.appendChild(pendingContent({ className:'muted', })); }
    apiAbortable('/admin/media/files?path='+encodeURIComponent(path||''), {}, 'mm_files').then(function(r){
      if (!list) return;
      list.innerHTML = '';
      if (!r.ok) { list.appendChild(h('p', { className:'bad', text: r.error||'Failed' })); return; }
      if (r.parent != null && r.path) {
        list.appendChild(h('button', { className:'btn ghost', text:'↑ Up', onClick: function(){ mmBrowseFiles(r.parent); } }));
      }
      (r.entries||[]).forEach(function(ent){
        var row = h('div', { className:'mm-row' }, [
          h('div', { style:'flex:1;min-width:0' }, [
            h('strong', { text: (ent.type==='dir'?'📁 ':'')+ent.name }),
            h('div', { className:'muted', text: ent.type + (ent.size!=null?(' · '+fmtSize(ent.size)):'') })
          ]),
          h('div', { className:'row-actions' }, [
            ent.type==='dir' ? h('button', { className:'btn', text:'Open', onClick: function(){ mmBrowseFiles(ent.path); } }) : null,
            (ent.type==='video'||ent.type==='rom') ? h('button', { className:'btn', text:'Ingest…', onClick: function(){ mmIngestPrompt(ent); } }) : null,
            (ent.type==='video') ? h('button', { className:'btn', text:'Match / move', onClick: function(){
              // Reuse orphan editor flow via library suggest
              state._orphans = [{ path: ent.path, name: ent.name, suggestion:{} }];
              state.adminTab = 'library';
              render();
              setTimeout(function(){ openOrphanEditor(0); }, 200);
            } }) : null,
            ent.type!=='dir' ? h('button', { className:'btn danger', text:'Delete', title:'Delete this file from the server', onClick: function(){
              confirmDialog('Are you sure you want to permanently delete “' + ent.name + '”? Its subtitles, .nfo and artwork are removed too, empty folders are tidied up, and the library entry is removed. This cannot be undone.', { okText:'Delete ' + ent.name, danger:true }).then(function(ok){
                if (!ok) return;
                api('/admin/media/files/delete', { method:'POST', body:{ path: ent.path, confirm: ent.name } }).then(function(res){
                  if (!res || !res.ok) return toast((res && res.error) || 'Delete failed', { kind:'bad' });
                  toast('Deleted ' + ent.name + ((res.removed_items||[]).length ? ' · removed from the library' : '') + ((res.removed_dirs||[]).length ? ' · ' + res.removed_dirs.length + ' empty folder(s) removed' : ''), { kind:'ok' });
                  var dirs = res.removed_dirs || [];
                  var up = r.path;
                  if (dirs.length) up = dirs[dirs.length - 1].replace(/[\\/][^\\/]*$/, '');
                  mmBrowseFiles(up);
                });
              });
            } }) : null
          ].filter(Boolean))
        ]);
        list.appendChild(row);
      });
    });
  }

  function mmIngestPrompt(ent){
    var sections = (state.settings&&state.settings.sections)||[];
    var ed = $('#mm_editor'); if (!ed) return;
    ed.style.display = '';
    ed.innerHTML = '';
    ed.appendChild(h('h3', { text:'Ingest file' }));
    ed.appendChild(h('p', { className:'muted', text: ent.path }));
    ed.appendChild(h('label', {}, ['Section', h('select', { id:'mm_ing_sec' }, sections.map(function(sec){
      return h('option', { value:sec.id, text:(sec.label||sec.id)+' — '+sec.path });
    }))]));
    ed.appendChild(h('p', { className:'muted', text:'File must already sit under the section path. Use Match/move first if it is still in downloads.' }));
    ed.appendChild(h('button', { className:'btn primary', text:'Ingest', onClick: function(){
      api('/admin/media/ingest', { method:'POST', body:{ path: ent.path, section_id: ($('#mm_ing_sec')||{}).value } }).then(function(r){
        toast(r.ok?'Ingested':(r.error||'Failed'), { kind:r.ok?'ok':'bad' });
      });
    }}));
  }

  function renderCollectionsAdmin(s){
    var wrap = h('div', { className:'stack' });
    wrap.appendChild(h('h3', { text:'Collections' }));
    wrap.appendChild(h('p', { className:'muted', text:'Pull film series from Wikipedia or TV shows from TVMaze (no API keys). See available vs missing locally, then Get missing via RSS (Admin → Acquisition quality/seeds).' }));
    wrap.appendChild(h('div', { className:'row-actions' }, [
      h('input', { id:'col_online_q', placeholder:'e.g. Aliens collection' }),
      h('button', { className:'btn primary', text:'Search online', onClick: function(){ colSearchOnline(); } }),
      h('button', { className:'btn ghost', text:'Cancel', onClick: function(){ abortOp('col_online'); toast('Cancelled'); } })
    ]));
    wrap.appendChild(h('div', { id:'col_online_hits', className:'stack' }));
    wrap.appendChild(findCollectionsPanel(function(){ colRefreshList(); }));
    wrap.appendChild(h('hr'));
    wrap.appendChild(h('div', { className:'row-actions' }, [
      h('input', { id:'col_name', placeholder:'Manual collection name' }),
      h('button', { className:'btn', text:'Create empty', onClick: function(){
        api('/admin/collections',{method:'POST', body:{ name:$('#col_name').value, parts:[] }}).then(function(){ fillAdmin(); });
      }}),
      /* re-resolve every collection part against the library (normalized title + year) and save item links */
      h('button', { className:'btn', text:'Rematch library', title:'Re-link collection titles to library files (normalized title + year)', onClick: function(){
        toast('Rematching collections…');
        api('/admin/collections/rematch',{method:'POST'}).then(function(r){
          if (!r || !r.ok) return toast((r&&r.error)||'Rematch failed', { kind:'bad' });
          var t = r.totals||{};
          toast('Rematched: '+(t.present||0)+' available · '+(t.missing||0)+' missing · '+(t.changed||0)+' links updated', { kind:'ok' });
          colRefreshList();
        });
      }})
    ]));
    wrap.appendChild(h('div', { id:'col_list', className:'stack' }));
    setTimeout(colRefreshList, 0);
    return wrap;
  }

  function colSearchOnline(){
    var q = ($('#col_online_q')||{}).value||'';
    var hits = $('#col_online_hits'); if (hits) hits.innerHTML = '';
    if (!q) return toast('Enter a query');
    toast('Searching Wikipedia / TVMaze…');
    apiAbortable('/admin/collections/search-online', { method:'POST', body:{ query:q } }, 'col_online').then(function(r){
      if (!hits) return;
      hits.innerHTML = '';
      if (!r.ok) { hits.appendChild(h('p', { className:'bad', text:r.error||'Failed' })); return; }
      if (!(r.results||[]).length) hits.appendChild(h('p', { className:'muted', text:'No results' }));
      (r.results||[]).forEach(function(hit){
        hits.appendChild(h('div', { className:'mm-row' }, [
          h('div', { style:'flex:1' }, [
            h('strong', { text: hit.title }),
            h('div', { className:'muted', text: (hit.source||hit.kind||'')+' · '+((hit.description||'').slice(0,160)) })
          ]),
          h('button', { className:'btn primary', text:'Import', onClick: function(){
            confirmDialog('Import “'+hit.title+'” as a collection?', { okText:'Import' }).then(function(ok){
              if (!ok) return;
              toast('Importing…');
              api('/admin/collections/import-online', { method:'POST', body:{
                kind: hit.kind||hit.source,
                title: hit.title,
                page: hit.title,
                tvmaze_id: hit.tvmaze_id,
                query: q
              }}).then(function(res){
                if (!res.ok) return toast(res.error||'Import failed', { kind:'bad' });
                var miss = (res.status && res.status.missing) ? res.status.missing.length : '?';
                var parts = (res.status && res.status.parts) ? res.status.parts.length : (res.parsed_count||0);
                toast('Imported '+parts+' parts · missing '+miss, { kind:'ok' });
                colRefreshList();
              });
            });
          }})
        ]));
      });
    });
  }

  function colRefreshList(){
    api('/admin/collections').then(function(r){
      var list = $('#col_list'); if(!list) return;
      list.innerHTML = '';
      if (!(r.collections||[]).length) {
        list.appendChild(emptyState('No collections yet', 'Search online for a franchise (e.g. Aliens) or create one manually.'));
        return;
      }
      (r.collections||[]).forEach(function(c){
        var box = h('div', { className:'stack', style:'border:1px solid var(--border);padding:12px;border-radius:8px;margin-top:8px' }, [
          h('strong', { text: c.name }),
          h('span', { className:'muted', text: (c.parts||[]).length+' parts'+(c.source?(' · from '+c.source):'') }),
          h('div', { id:'col_st_'+c.id, className:'muted', text:'' }),
          h('div', { className:'row-actions' }, [
            h('button', { className:'btn', text:'Status', onClick: function(){
              api('/collections/'+c.id).then(function(st){
                var el = $('#col_st_'+c.id);
                if (!el) return;
                el.innerHTML = '';
                (st.parts||[]).forEach(function(p){
                  var via = (p.present && p.item) ? ('  → '+(p.item.title||'')+(p.item.year?(' ('+p.item.year+')'):'')+(p.match?(' ['+p.match+']'):'')) : '';
                  el.appendChild(h('div', {}, [
                    h('span', { className: p.present?'badge badge-available':'badge badge-missing', text: p.present?'Available':'Missing' }),
                    ' '+p.title+(p.year?(' ('+p.year+')'):''),
                    via ? h('span', { className:'muted', text: via }) : null
                  ].filter(Boolean)));
                });
                toast('Missing '+(st.missing||[]).length+' of '+(st.parts||[]).length);
              });
            }}),
            h('button', { className:'btn primary', text:'Get missing', onClick: function(){
              confirmDialog('Queue RSS search/download for missing titles in “'+(c.name||'')+'”? Uses Admin Acquisition quality/seeds.', { okText:'Get missing' }).then(function(ok){
                if (!ok) return;
                api('/admin/collections/'+c.id+'/fetch-missing',{method:'POST'}).then(function(st){
                  toast(st.ok?('Queued '+(st.count||0)):(st.error||'Failed'), { kind:st.ok?'ok':'bad' });
                });
              });
            }}),
            h('button', { className:'btn', text:'Public view', onClick: function(){ state.collectionId=c.id; state.view='collection'; render(); } }),
            h('button', { className:'btn ghost danger-outline', text:'Delete', onClick: function(){
              confirmDialog('Delete collection “'+(c.name||'')+'”?', { danger:true, okText:'Delete' }).then(function(ok){
                if (!ok) return;
                api('/admin/collections/'+c.id,{method:'DELETE'}).then(function(){ fillAdmin(); });
              });
            }})
          ])
        ]);
        list.appendChild(box);
      });
    });
  }

  /* Find collections (admin): resumable job, driven step by step from the page. */
  function findCollectionsPanel(onChange){
    var status = h('div', { className:'muted', role:'status' });
    var bar = h('progress', { max:'100', value:'0', style:'width:100%;display:none' });
    var running = false;
    var btn = h('button', { className:'btn primary', type:'button', text:'Find collections', title:'Look up the collections (film series) your films belong to and create or extend them' });
    var stop = h('button', { className:'btn ghost', type:'button', text:'Stop', style:'display:none' });
    function paint(p){
      if (!p) return;
      var pct = p.total ? Math.round(100 * (p.done || 0) / p.total) : 0;
      bar.style.display = p.status === 'running' ? '' : 'none';
      bar.value = pct;
      var counts = (p.total ? (p.done||0) + '/' + p.total + ' films · ' : '') + (p.found||0) + ' found · ' + (p.created||0) + ' created · ' + (p.updated||0) + ' updated';
      status.textContent = [p.message || '', p.status && p.status !== 'idle' ? counts : '', p.hint || '', p.last_error ? 'Last error: ' + p.last_error : ''].filter(Boolean).join(' — ');
      stop.style.display = running ? '' : 'none';
      btn.disabled = running;
    }
    function loop(){
      if (!running) return;
      api('/admin/collections/discover', { method:'POST', body:{ action:'step' } }).then(function(p){
        if (!p || !p.ok) { running = false; status.textContent = (p && p.error) || 'Find collections failed'; paint(null); btn.disabled = false; return; }
        paint(p);
        if (p.status === 'running' && running) setTimeout(loop, p.wait_ms || (p.busy ? 1500 : 200));
        else { running = false; paint(p); if (onChange) onChange(); }
      }).catch(function(){ running = false; btn.disabled = false; status.textContent = 'Connection lost; press Find collections to resume.'; });
    }
    btn.onclick = function(){
      running = true; btn.disabled = true;
      api('/admin/collections/discover').then(function(cur){
        var resume = cur && (cur.status === 'running' || cur.status === 'cancelled') && cur.done < cur.total;
        return resume ? cur : api('/admin/collections/discover', { method:'POST', body:{ action:'start' } });
      }).then(function(p){ paint(p); if (p && p.ok && p.status === 'running') loop(); else { running = false; paint(p); if (onChange) onChange(); } });
    };
    stop.onclick = function(){ running = false; api('/admin/collections/discover', { method:'POST', body:{ action:'cancel' } }).then(paint); };
    api('/admin/collections/discover').then(function(p){ if (p && p.ok && p.status && p.status !== 'idle') paint(p); }).catch(function(){});
    return h('div', { className:'stack find-collections' }, [ h('div', { className:'row-actions' }, [btn, stop]), bar, status ]);
  }
  function viewCollections(){
    var wrap = h('div', { id:'collections-view', className:'panel panel-collections' }, [
      h('div', { className:'hero' }, [
        h('h1', { text:'Collections' }),
        h('p', { className:'muted', text:'Film series & show groups — tap a poster to expand its titles' })
      ]),
      h('div', { id:'pub_col_list', className:'collections-grid' }, [ pendingContent({ className:'muted', }) ])
    ]);
    api('/collections').then(function(r){
      var list = $('#pub_col_list'); if (!list) return;
      var root = $('#collections-view');
      if (!r.ok || !(r.collections||[]).length) {
        list.innerHTML = '';
        list.className = 'collections-grid';
        list.appendChild(emptyState('No collections', ''));
        return;
      }

      var allCols = (r.collections || []).slice().sort(function(a, b){
        return String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity:'base' });
      });
      var lettersMap = {};
      azLettersList().forEach(function(L){ lettersMap[L] = 0; });
      allCols.forEach(function(c){
        var L = titleStartLetter(c.name);
        if (L >= 'A' && L <= 'Z') lettersMap[L] = (lettersMap[L] || 0) + 1;
      });
      var openId = null;
      function closeExpand(){
        var prev = list.querySelector('.collection-expand.open');
        if (prev) {
          prev.classList.remove('open');
          prev.innerHTML = '';
          prev.hidden = true;
        }
        var prevCard = list.querySelector('.collection-card.is-open');
        if (prevCard) {
          prevCard.classList.remove('is-open');
          try { prevCard.setAttribute('aria-expanded', 'false'); } catch (eA) {}
        }
        openId = null;
      }
      function fillExpand(panel, col){
        panel.innerHTML = '';
        panel.appendChild(pendingContent({ className:'muted', }));
        api('/collections/'+col.id).then(function(st){
          if (!panel.isConnected || openId !== col.id) return;
          panel.innerHTML = '';
          if (!st.ok) {
            panel.appendChild(h('p', { className:'bad', text: st.error || 'Failed to load' }));
            return;
          }
          var parts = st.parts || [];
          var head = h('div', { className:'collection-expand-head' }, [
            h('div', { className:'collection-expand-copy' }, [
              h('h3', { text: (st.collection && st.collection.name) || col.name || 'Collection' }),
              h('p', { className:'muted', text: ((st.collection && st.collection.description) || col.description || '') + (col.source ? (' · ' + col.source) : '') })
            ]),
            h('div', { className:'row-actions' }, [
              h('button', { className:'btn glass', type:'button', text:'Full page', onClick: function(e){
                e.stopPropagation();
                state.collectionId = col.id; state.view = 'collection'; render();
              }}),
              h('button', { className:'btn ghost', type:'button', text:'Close', onClick: function(e){ e.stopPropagation(); closeExpand(); } })
            ])
          ]);
          panel.appendChild(head);
          if (!parts.length) {
            panel.appendChild(h('p', { className:'muted', text:'No parts in this collection.' }));
            return;
          }
          var rail = h('div', { className:'collection-expand-rail' });
          parts.forEach(function(p){
            if (p.present && p.item) {
              rail.appendChild(cardEl(p.item));
            } else {
              rail.appendChild(collectionMissingCard(p, col.id));
            }
          });
          panel.appendChild(rail);
          if (state.user && state.user.role === 'admin' && (st.missing || []).length) {
            panel.appendChild(h('div', { className:'row-actions', style:'margin:12px 0 0' }, [
              h('button', { className:'btn primary', type:'button', text:'Get missing ('+st.missing.length+')', onClick: function(e){
                e.stopPropagation();
                confirmDialog('Queue RSS get for '+st.missing.length+' missing title(s)?', { okText:'Get missing' }).then(function(ok){
                  if (!ok) return;
                  api('/admin/collections/'+col.id+'/fetch-missing',{method:'POST'}).then(function(res){
                    toast(res.ok?('Queued '+(res.count||0)):(res.error||'Failed'), { kind:res.ok?'ok':'bad' });
                  });
                });
              }})
            ]));
          }
        });
      }
      function toggleCard(card, expand, col){
        if (openId === col.id) { closeExpand(); return; }
        closeExpand();
        openId = col.id;
        card.classList.add('is-open');
        try { card.setAttribute('aria-expanded', 'true'); } catch (eA2) {}
        expand.hidden = false;
        expand.classList.add('open');
        fillExpand(expand, col);
        try { expand.scrollIntoView({ behavior:'smooth', block:'nearest' }); } catch (eSc) {}
      }
      function paintGrid(){
        var letter = (state.collectionsLetter || '').toUpperCase();
        var cols = !letter ? allCols : allCols.filter(function(c){ return titleStartLetter(c.name) === letter; });
        list.innerHTML = '';
        list.className = 'collections-grid';
        openId = null;
        if (!cols.length) {
          list.appendChild(emptyState('No collections for ' + letter, 'Pick another letter or tap All on the A–Z rail.'));
        } else {
          cols.forEach(function(c){
            var cover = c.cover_item || null;
            var poster = h('div', { className:'poster poster-pending collection-poster' }, [
              h('div', { className:'ph', text: c.name || 'Collection' })
            ]);
            if (cover) {
              var pKind = posterKindFor(cover);
              var pid = posterIdFor(cover);
              if (pid && (cover.has_poster || posterNeedsRemote(cover))) {
                if (posterNeedsRemote(cover)) poster.setAttribute('data-poster-fetch', '1');
                observePoster(poster, pid, pKind);
              }
            } else {
              poster.classList.remove('poster-pending');
              poster.classList.add('poster-failed');
            }
            var badge = h('div', { className:'collection-badge' }, [
              h('span', { text: String(c.available_count || 0) + '/' + String(c.part_count || 0) })
            ]);
            var expand = h('div', { className:'collection-expand', hidden: true, onClick: function(e){ e.stopPropagation(); } });
            var card = h('div', {
              className:'card collection-card',
              tabindex:'0',
              role:'button',
              'aria-expanded':'false',
              onClick: function(){ toggleCard(card, expand, c); },
              onKeydown: function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleCard(card, expand, c); } }
            }, [
              poster,
              badge,
              h('div', { className:'meta' }, [
                h('div', { className:'title', text: c.name || 'Untitled' }),
                h('div', { className:'sub', text: (c.available_count||0)+' available · '+(c.missing_count||0)+' missing' })
              ])
            ]);
            var cell = h('div', { className:'collection-cell' }, [ card, expand ]);
            list.appendChild(cell);
          });
        }
        if (root) {
          mountAzRail(root, '__collections__', lettersMap, {
            active: state.collectionsLetter || '',
            ariaLabel: 'Collections alphabet index',
            onPick: function(L){
              state.collectionsLetter = L || '';
              syncUrl();
              paintGrid();
              try { window.scrollTo(0, 0); } catch (eZ) {}
            }
          });
        }
      }
      paintGrid();
    });
    return shell(wrap);
  }

  /** Greyed card for a collection member that is not in the library; opens its info page (no Play). */
  function collectionMissingCard(p, colId){
    var poster = p.poster
      ? h('div', { className:'poster' }, [ h('img', { src: API + '/' + p.poster, alt:'', loading:'lazy' }) ])
      : h('div', { className:'poster poster-failed' }, [ h('div', { className:'ph', text: p.title || 'Missing' }) ]);
    function open(e){ if (e) e.stopPropagation(); state.colMember = { id: colId, index: p.index }; state.view = 'colmember'; render(); }
    return h('div', { className:'card card-missing', tabindex:'0', role:'button', title:'Not in your library', onClick: open,
      onKeydown: function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(e); } } }, [
      poster,
      h('div', { className:'meta' }, [
        h('div', { className:'title', text: (p.title || 'Untitled') + (p.year ? (' (' + p.year + ')') : '') }),
        h('div', { className:'sub', text:'Not in library' })
      ])
    ]);
  }

  /** Info page for a collection member that is not owned: stored poster + synopsis, no Play button. */
  function viewCollectionMember(){
    var ref = state.colMember || {};
    var wrap = h('div', { className:'panel', id:'col_member' }, [ pendingContent({ className:'muted' }) ]);
    api('/collections/' + ref.id).then(function(st){
      var root = $('#col_member'); if (!root) return;
      root.innerHTML = '';
      var p = st && st.ok ? (st.parts || []).filter(function(x){ return x.index === ref.index; })[0] : null;
      if (!p) { root.appendChild(h('p', { className:'bad', text:(st && st.error) || 'Not found' })); return; }
      if (p.present && p.item) { if (p.item.show_key) openShow(p.item.show_key); else openMovie(p.item.id); return; }
      var col = st.collection || {};
      root.appendChild(h('button', { className:'btn ghost', text:'← ' + (col.name || 'Collection'), onClick: function(){ state.collectionId = ref.id; state.view = 'collection'; render(); } }));
      root.appendChild(h('div', { className:'movie-detail col-member', style:'display:flex;gap:24px;flex-wrap:wrap;margin-top:12px' }, [
        h('div', { className:'poster', style:'width:220px;flex:0 0 auto;filter:grayscale(.35)' }, [
          p.poster ? h('img', { src: API + '/' + p.poster, alt:'' }) : h('div', { className:'ph', text: p.title || '' })
        ]),
        h('div', { style:'flex:1;min-width:240px' }, [
          h('h1', { text: (p.title || 'Untitled') + (p.year ? (' (' + p.year + ')') : '') }),
          h('p', { className:'muted', text:'Not in your library · part of ' + (col.name || 'this collection') }),
          h('p', { text: p.overview || 'No synopsis stored yet. Refresh the collection in Settings to fetch it.' })
        ])
      ]));
    });
    return shell(wrap);
  }

function viewCollectionDetail(){
    var wrap = h('div', { className:'panel', id:'col_detail' }, [ pendingContent({ className:'muted', }) ]);
    api('/collections/'+state.collectionId).then(function(st){
      var root = $('#col_detail'); if (!root) return;
      root.innerHTML = '';
      if (!st.ok) { root.appendChild(h('p', { className:'bad', text: st.error||'Not found' })); return; }
      var col = st.collection||{};
      root.appendChild(h('div', { className:'hero' }, [
        h('button', { className:'btn ghost', text:'← Collections', onClick: function(){ state.view='collections'; render(); } }),
        h('h1', { text: col.name||'Collection' }),
        h('p', { className:'muted', text: (col.description||'')+(col.source?(' · '+col.source):'') })
      ]));
      var missing = st.missing||[];
      if (state.user && state.user.role==='admin' && missing.length) {
        root.appendChild(h('div', { className:'row-actions', style:'margin:0 0 12px' }, [
          h('button', { className:'btn primary', text:'Get missing ('+missing.length+')', onClick: function(){
            confirmDialog('Queue RSS get for '+missing.length+' missing title(s)?', { okText:'Get missing' }).then(function(ok){
              if (!ok) return;
              api('/admin/collections/'+col.id+'/fetch-missing',{method:'POST'}).then(function(r){
                toast(r.ok?('Queued '+(r.count||0)):(r.error||'Failed'), { kind:r.ok?'ok':'bad' });
              });
            });
          }})
        ]));
      }
      (st.parts||[]).forEach(function(p){
        root.appendChild(h('div', { className:'mm-row' }, [
          h('span', { className: p.present?'badge badge-available':'badge badge-missing', text: p.present?'Available':'Missing' }),
          h('div', { style:'flex:1' }, [
            h('strong', { text: p.title+(p.year?(' ('+p.year+')'):'') }),
            p.item ? h('div', { className:'muted', text: (p.item.title||'')+(p.item.year?(' ('+p.item.year+')'):'')+(p.item.section?(' · '+p.item.section):'') }) : null
          ]),
          p.present && p.item ? h('button', { className:'btn', text:'Open', onClick: function(){
            if (p.item.show_key) openShow(p.item.show_key);
            else openMovie(p.item.id);
          } }) : h('button', { className:'btn ghost', text:'Info', onClick: function(){
            state.colMember = { id: col.id, index: p.index }; state.view = 'colmember'; render();
          } })
        ].filter(Boolean)));
      });
    });
    return shell(wrap);
  }




  var liveSet = {
    station: null,
    iptv: null,
    favView: null,
    browse: { feed: 'all', q: '', group: '', country: '', category: '', facets: null, offset: 0, total: 0, channels: [], loading: false, activeOnly: false, pageSize: 60, source: '' },
    hideInactiveFav: false,
    feedEditId: null,
    channelId: null,
    status: '',
    statusBad: false,
    dirty: false,
    saving: false,
    durPending: 0,
    open: {},
    q: {},
    lib: { kind: 'all' },
    cat: { open: false, q: '', section: '', page: 1, per: 30, hits: [], total: 0, hasMore: false, sections: [], formats: null, availFormats: [], busy: false, error: '', req: 0, timer: null },
    editor: null
  };
  var LIVE_KINDS = ['movie', 'episode', 'commercial', 'bumper'];
  var LIVE_KIND_LABEL = { movie: 'Movie', episode: 'TV', commercial: 'Commercial', bumper: 'Bumper' };
  var LIVE_KIND_PLURAL = { movie: 'Movies', episode: 'TV shows', commercial: 'Commercials', bumper: 'Bumpers' };


  function liveSetMsg(msg, bad){
    liveSet.status = msg || '';
    liveSet.statusBad = !!bad;
    var el = document.getElementById('live-native-status');
    if (el) {
      el.textContent = liveSet.status;
      el.className = 'muted live-native-status' + (liveSet.statusBad ? ' bad' : '');
    } else if (msg) toast(msg);
  }

  function liveSyncDirty(){
    var d = document.getElementById('live-dirty');
    if (d) {
      d.hidden = !liveSet.dirty;
      d.textContent = liveSet.durPending ? ('Unsaved changes \u00b7 reading ' + liveSet.durPending + ' duration' + (liveSet.durPending === 1 ? '' : 's') + '\u2026') : 'Unsaved changes';
    }
    var b = document.getElementById('live-discard');
    if (b) b.disabled = !liveSet.dirty;
  }
  function liveDirty(){ liveSet.dirty = true; liveSyncDirty(); }
  window.addEventListener('beforeunload', function(e){
    if (liveSet.dirty && state.view === 'settings' && String(state.settingsSection || '').indexOf('live') === 0) {
      e.preventDefault(); e.returnValue = ''; return '';
    }
  });

  function liveEnsureChannel(ch){
    if (!ch) return ch;
    if (!ch.libraryIds) ch.libraryIds = {};
    LIVE_KINDS.forEach(function(k){
      if (!Array.isArray(ch.libraryIds[k])) ch.libraryIds[k] = [];
    });
    if (!Array.isArray(ch.playlist)) ch.playlist = [];
    if (ch.commercialsPerBreak == null) ch.commercialsPerBreak = 2;
    if (ch.bumperBefore == null) ch.bumperBefore = true;
    if (ch.commercialEveryMs == null) ch.commercialEveryMs = 25 * 60 * 1000;
    if (ch.fillHours == null) ch.fillHours = 6;
    if (ch.enabled == null) ch.enabled = true;
    if (!ch.seed) ch.seed = ch.id || 'seed';
    if (!ch.rollingSchedule || typeof ch.rollingSchedule !== 'object') {
      ch.rollingSchedule = { enabled: false, slots: [] };
    }
    if (!Array.isArray(ch.rollingSchedule.slots)) ch.rollingSchedule.slots = [];
    return ch;
  }

  function liveMediaById(id){
    var st = liveSet.station;
    if (!st || !st.media) return null;
    if (!liveSet._mIdx || liveSet._mIdxSrc !== st.media || liveSet._mIdxLen !== st.media.length) {
      var idx = {};
      st.media.forEach(function(m){ idx[m.id] = m; });
      liveSet._mIdx = idx; liveSet._mIdxSrc = st.media; liveSet._mIdxLen = st.media.length;
    }
    return liveSet._mIdx[id] || null;
  }
  function liveMediaChanged(){ liveSet._mIdx = null; }

  /* Title cleanup shared by every Relay list (mojibake from old Windows-1252 NFOs, release tags). */
  function livePad2(n){ n = String(n); return n.length < 2 ? '0' + n : n; }
  function liveFixText(s){
    return String(s || '')
      .replace(/\u00d4\u00c7\u00f6/g, '\u2014')
      .replace(/\u00d4\u00c7\u00f4/g, '\u2013')
      .replace(/\u00d4\u00c7\u00a6/g, '\u2026')
      .replace(/(?:\s*[\u2014\u2013\-]\s*)+$/g, '')
      .replace(/\s{2,}/g, ' ')
      .trim();
  }
  function liveEpisodeName(m){
    var title = liveFixText((m && (m.episode_title || m.title)) || '');
    if (!title) return '';
    var series = liveFixText(m && m.series);
    if (series && title.toLowerCase().indexOf(series.toLowerCase()) === 0) {
      title = title.slice(series.length).replace(/^[\s\u2014\u2013\-]+/, '');
    }
    title = title.replace(/^[Ss]\d{1,2}[Ee]\d{1,3}\s*[\u2014\u2013\-|]?\s*/, '');
    title = title.replace(/\s*\((?:\d{3,4}p|WEB|BluRay|HDTV|DSNP|AMZN|NF)[^)]*\)\s*$/i, '');
    title = title.replace(/\b(?:\d{3,4}p|WEB-?DL|WEBRip|BluRay|HDTV|x264|x265|HEVC|DDP?5\.?1|AAC|DSNP|AMZN|MeM\.?GP)\b.*/i, '');
    if (/\./.test(title) && /(?:1080p|WEB|BluRay|DSNP)/i.test(title)) {
      title = title.replace(/[._]/g, ' ').replace(/\s+/g, ' ').trim();
      title = title.replace(/\b(?:\d{3,4}p|WEB-?DL|ITA|ENG|DSNP|AMZN)\b.*/i, '');
    }
    title = liveFixText(title.replace(/[._]+/g, ' ').replace(/\s+/g, ' '));
    if (series && title.toLowerCase() === series.toLowerCase()) return '';
    return title;
  }
  function liveGuideTitle(m){
    if (!m) return '(missing)';
    if (m.kind === 'episode' && (m.series || m.title)) {
      var series = liveFixText(m.series) || liveFixText(m.title) || '(untitled series)';
      var se = (m.season != null && m.season !== '' && m.episode != null && m.episode !== '') ? ('S' + livePad2(m.season) + 'E' + livePad2(m.episode)) : '';
      var name = liveEpisodeName(m);
      return [series, se, name].filter(Boolean).join(' \u00b7 ');
    }
    if (m.kind === 'movie' && m.year) return liveFixText(m.title) + ' (' + m.year + ')';
    return liveFixText(m.title) || m.id || '(untitled)';
  }
  function liveEpLabel(m){
    var se = (m.season != null && m.season !== '' && m.episode != null && m.episode !== '') ? ('S' + livePad2(m.season) + 'E' + livePad2(m.episode)) : (m.episode != null && m.episode !== '' ? ('Episode ' + m.episode) : '');
    var name = liveEpisodeName(m);
    return [se, name].filter(Boolean).join(' \u00b7 ') || liveGuideTitle(m);
  }
  function liveBlob(m){
    return [liveGuideTitle(m), liveFixText(m && m.series), liveFixText(m && m.subtitle), liveFixText(m && m.title), m && m.url].join(' ').toLowerCase();
  }
  function liveScrubMedia(m){
    if (!m) return m;
    if (m.title != null) m.title = liveFixText(m.title);
    if (m.series != null) m.series = liveFixText(m.series);
    if (m.subtitle != null) m.subtitle = liveFixText(m.subtitle);
    if (m.kind === 'episode') m.title = liveEpisodeName(m);
    return m;
  }
  function liveSeriesName(m){ return liveFixText(m && (m.series || m.title)) || '(untitled series)'; }

  function liveFmtDur(ms){
    var s = Math.max(0, Math.round((ms || 0) / 1000));
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    if (h) return h + 'h ' + m + 'm';
    if (m) return m + 'm ' + sec + 's';
    return sec + 's';
  }
  function liveSumMs(list){ var t = 0; list.forEach(function(m){ t += (m && m.durationMs) || 0; }); return t; }
  function livePlural(n, one, many){ return n + ' ' + (n === 1 ? one : (many || one + 's')); }

  function liveUid(prefix){
    return prefix + '-' + Math.random().toString(36).slice(2, 8);
  }

  /* Group episodes: show -> season -> episodes (sorted). */
  function liveEpCmp(a, b){
    var as = a.season == null || a.season === '' ? 9999 : Number(a.season);
    var bs = b.season == null || b.season === '' ? 9999 : Number(b.season);
    if (as !== bs) return as - bs;
    var ae = a.episode == null || a.episode === '' ? 0 : Number(a.episode);
    var be = b.episode == null || b.episode === '' ? 0 : Number(b.episode);
    if (ae !== be) return ae - be;
    return String(a.title || '').localeCompare(String(b.title || ''));
  }
  function liveShowIndex(eps){
    var map = {}, order = [];
    eps.forEach(function(m){
      if (!m) return;
      var name = liveSeriesName(m), key = name.toLowerCase();
      var g = map[key];
      if (!g) { g = map[key] = { key: key, name: name, eps: [] }; order.push(g); }
      g.eps.push(m);
    });
    order.forEach(liveShowFinish);
    order.sort(function(a, b){ return a.name.localeCompare(b.name); });
    return order;
  }
  function liveShowFinish(g){
    g.eps.sort(liveEpCmp);
    var smap = {}, sl = [];
    g.eps.forEach(function(m){
      var sk = (m.season == null || m.season === '') ? 'x' : String(Number(m.season));
      var s = smap[sk];
      if (!s) { s = smap[sk] = { key: sk, season: sk === 'x' ? null : Number(sk), eps: [] }; sl.push(s); }
      s.eps.push(m);
    });
    sl.sort(function(a, b){ if (a.key === 'x') return 1; if (b.key === 'x') return -1; return a.season - b.season; });
    g.seasons = sl;
    return g;
  }
  function liveIds(list){ return list.map(function(m){ return m.id; }); }
  /* Search a show index: a show-name hit keeps the whole show (collapsed); otherwise only matching
     episodes are kept and their show/season open automatically so the hits are visible. */
  function liveFilterShows(shows, q){
    q = String(q || '').trim().toLowerCase();
    if (!q) return shows;
    var out = [];
    shows.forEach(function(g){
      if (g.name.toLowerCase().indexOf(q) >= 0) { out.push(g); return; }
      var hits = g.eps.filter(function(m){ return liveBlob(m).indexOf(q) >= 0; });
      if (!hits.length) return;
      var f = liveShowFinish({ key: g.key, name: g.name, eps: hits, autoOpen: true });
      f.seasons.forEach(function(s){ s.autoOpen = true; });
      out.push(f);
    });
    return out;
  }
  function liveSeasonLabel(s){ return s.season == null ? 'Specials / unnumbered' : ('Season ' + s.season); }

  /* Lazy tree: only expanded shows build their seasons, only expanded seasons build episode rows.
     opts: { stateKey, shows, showLead(g, ctx), showTail(g, ctx), seasonLead(g, s, ctx),
     seasonTail(g, s, ctx), epRow(m, ctx), onChange, empty }. ctx.addSync(fn) registers a refresher;
     ctx.sync() re-runs every refresher of that show (tri-state boxes, counts) then opts.onChange(). */
  function liveTree(opts){
    var wrap = h('div', { className: 'live-tree' });
    if (!opts.shows.length) {
      wrap.appendChild(h('p', { className: 'muted live-tree-empty', text: opts.empty || 'No TV shows.' }));
      return wrap;
    }
    opts.shows.forEach(function(g){ wrap.appendChild(liveTreeShow(opts, g)); });
    return wrap;
  }
  function liveTreeOpen(key, auto){ var v = liveSet.open[key]; return v == null ? !!auto : !!v; }
  function liveTreeHead(open, onToggle, lead, label, tail, what){
    return h('div', { className: 'live-tree-head' }, [
      h('button', { className: 'live-tree-toggle', type: 'button', 'aria-expanded': open ? 'true' : 'false', title: (open ? 'Collapse ' : 'Expand ') + what, onClick: onToggle },
        [ h('span', { className: 'live-tree-caret', 'aria-hidden': 'true' }) ]),
      lead,
      h('button', { className: 'live-tree-name', type: 'button', onClick: onToggle }, label),
      h('div', { className: 'live-tree-tail' }, tail || [])
    ]);
  }
  function liveTreeShow(opts, g){
    var key = opts.stateKey + '::' + g.key;
    var node = h('div', { className: 'live-tree-show' });
    var ctx = { syncs: [], addSync: function(fn){ ctx.syncs.push(fn); fn(); }, sync: function(){ ctx.syncs.forEach(function(fn){ fn(); }); if (opts.onChange) opts.onChange(); } };
    function paint(){
      ctx.syncs = [];
      node.innerHTML = '';
      var open = liveTreeOpen(key, g.autoOpen);
      node.className = 'live-tree-show' + (open ? ' open' : '');
      var toggle = function(){ liveSet.open[key] = !open; paint(); };
      node.appendChild(liveTreeHead(open, toggle, opts.showLead ? opts.showLead(g, ctx) : null, [
        h('strong', { text: g.name }),
        h('span', { className: 'muted', text: ' \u00b7 ' + livePlural(g.eps.length, 'episode') + ' \u00b7 ' + livePlural(g.seasons.length, 'season') })
      ], opts.showTail ? opts.showTail(g, ctx) : null, 'show'));
      if (!open) return;
      node.appendChild(h('div', { className: 'live-tree-body' }, g.seasons.map(function(s){ return liveTreeSeason(opts, g, s, key, ctx); })));
    }
    paint();
    return node;
  }
  function liveTreeSeason(opts, g, s, showKey, ctx){
    var key = showKey + '::s' + s.key;
    var node = h('div', { className: 'live-tree-season' });
    function paint(){
      node.innerHTML = '';
      var open = liveTreeOpen(key, s.autoOpen);
      node.className = 'live-tree-season' + (open ? ' open' : '');
      var toggle = function(){ liveSet.open[key] = !open; paint(); };
      node.appendChild(liveTreeHead(open, toggle, opts.seasonLead ? opts.seasonLead(g, s, ctx) : null, [
        h('span', { text: liveSeasonLabel(s) }),
        h('span', { className: 'muted', text: ' \u00b7 ' + livePlural(s.eps.length, 'episode') + ' \u00b7 ' + liveFmtDur(liveSumMs(s.eps)) })
      ], opts.seasonTail ? opts.seasonTail(g, s, ctx) : null, 'season'));
      if (!open) return;
      node.appendChild(h('div', { className: 'live-tree-eps' }, s.eps.map(function(m){ return opts.epRow(m, ctx); })));
    }
    paint();
    return node;
  }
  /* Tri-state checkbox over a set of media ids. */
  function liveTriBox(ids, isOn, setOn, ctx, title){
    var cb = h('input', { type: 'checkbox', className: 'live-tri', title: title || 'Tick / untick all', 'aria-label': title || 'Tick / untick all' });
    var cnt = h('span', { className: 'live-tree-count' });
    cb.addEventListener('change', function(){ setOn(ids, cb.checked); ctx.sync(); });
    ctx.addSync(function(){
      var n = 0;
      ids.forEach(function(id){ if (isOn(id)) n++; });
      cb.checked = ids.length > 0 && n === ids.length;
      cb.indeterminate = n > 0 && n < ids.length;
      cnt.textContent = n + '/' + ids.length;
    });
    return h('span', { className: 'live-tri-wrap' }, [cb, cnt]);
  }
  /* Filterable flat checkbox list (movies / commercials / bumpers / rotation picks); every item is listed. */
  function liveFlatChecks(items, isOn, setOn, qKey, onChange, emptyText){
    var grid = h('div', { className: 'live-lib-grid' });
    var count = h('span', { className: 'muted' });
    function shown(){
      var q = String(liveSet.q[qKey] || '').trim().toLowerCase();
      return q ? items.filter(function(m){ return liveBlob(m).indexOf(q) >= 0; }) : items;
    }
    function paint(){
      grid.innerHTML = '';
      var list = shown();
      if (!items.length) grid.appendChild(h('p', { className: 'muted', text: emptyText || 'None in the Relay media library yet.' }));
      else if (!list.length) grid.appendChild(h('p', { className: 'muted', text: 'No matches.' }));
      list.forEach(function(m){
        grid.appendChild(h('label', { className: 'check-label live-lib-item' }, [
          h('input', { type: 'checkbox', checked: isOn(m.id), onChange: function(ev){ setOn([m.id], ev.target.checked); if (onChange) onChange(); } }),
          h('span', { text: liveGuideTitle(m) + (m.durationMs ? ' \u00b7 ' + liveFmtDur(m.durationMs) : '') })
        ]));
      });
      count.textContent = (list.length === items.length) ? livePlural(items.length, 'item') : (list.length + ' of ' + items.length + ' match');
    }
    var bar = h('div', { className: 'live-filter-bar' }, [
      liveSearchInput(qKey, 'Filter by title\u2026', paint),
      h('button', { className: 'btn ghost sm', type: 'button', text: 'Tick all listed', onClick: function(){ setOn(liveIds(shown()), true); paint(); if (onChange) onChange(); } }),
      h('button', { className: 'btn ghost sm', type: 'button', text: 'Untick all listed', onClick: function(){ setOn(liveIds(shown()), false); paint(); if (onChange) onChange(); } }),
      count
    ]);
    paint();
    return h('div', { className: 'live-flat' }, [bar, grid]);
  }
  /* Debounced search box: repaints only its target, so typing keeps focus. */
  function liveSearchInput(qKey, label, onRun){
    var t = null;
    return h('input', { type: 'search', className: 'live-filter', value: liveSet.q[qKey] || '', placeholder: label, 'aria-label': label,
      onInput: function(e){ liveSet.q[qKey] = e.target.value; clearTimeout(t); t = setTimeout(onRun, 160); } });
  }
  function liveSetHas(arr, id){ return arr.indexOf(id) >= 0; }
  function liveSetApply(holder, field, ids, on){
    var arr = holder[field];
    if (on) {
      var have = {}; arr.forEach(function(x){ have[x] = true; });
      ids.forEach(function(id){ if (!have[id]) { arr.push(id); have[id] = true; } });
    } else {
      var drop = {}; ids.forEach(function(id){ drop[id] = true; });
      holder[field] = arr.filter(function(x){ return !drop[x]; });
    }
  }

  function liveLoadStation(force){
    if (liveSet.station && !force) return Promise.resolve(liveSet.station);
    return liveApi('station').then(function(st){
      if (!st || !st.channels) throw new Error((st && st.error) || 'Invalid station');
      if (!Array.isArray(st.media)) st.media = [];
      st.channels.forEach(liveEnsureChannel);
      liveSet.station = st;
      liveMediaChanged();
      liveSet.dirty = false;
      if (!liveSet.channelId || !st.channels.some(function(c){ return c.id === liveSet.channelId; })) liveSet.channelId = st.channels[0] ? st.channels[0].id : null;
      return st;
    });
  }

  function liveSaveStation(){
    if (!liveSet.station || liveSet.saving) return;
    if (liveSet.durPending) { liveSetMsg('Still reading ' + livePlural(liveSet.durPending, 'episode duration') + ' from the server \u2014 save again in a moment.', true); return; }
    if (liveSet.editor) { liveSetMsg('Finish or cancel the media editor first (Save media / Cancel).', true); return; }
    var st = liveSet.station;
    st.media.forEach(liveScrubMedia);
    st.channels.forEach(liveEnsureChannel);
    liveSet.saving = true;
    liveSetMsg('Saving station\u2026');
    document.querySelectorAll('[data-live-save]').forEach(function(b){ b.disabled = true; });
    return liveApiPost('station', st).then(function(res){
      if (!res.ok || !res.body || !res.body.channels) {
        liveSetMsg((res.body && res.body.error) || 'Save failed', true);
        return;
      }
      liveSet.station = res.body;
      if (!Array.isArray(liveSet.station.media)) liveSet.station.media = [];
      liveSet.station.channels.forEach(liveEnsureChannel);
      liveMediaChanged();
      liveSet.dirty = false;
      liveSetMsg('Saved. Lineups rebuilt.');
      fillLiveNativePanel(state.settingsSection);
    }).catch(function(e){ liveSetMsg(String(e.message || e), true); })
      .then(function(){
        liveSet.saving = false;
        document.querySelectorAll('[data-live-save]').forEach(function(b){ b.disabled = false; });
        liveSyncDirty();
      });
  }

  function liveLoadIptv(force){
    if (liveSet.iptv && !force) return Promise.resolve(liveSet.iptv);
    return liveApi('iptv').then(function(body){
      if (!body) throw new Error('Empty IPTV response');
      if (body.error && !body.iptv) throw new Error(body.error);
      liveSet.iptv = body.iptv || { channels: [], feeds: body.feeds || [], epgUrls: [], numberStart: 100, activeFeedId: 'all' };
      if (!liveSet.iptv.feeds) liveSet.iptv.feeds = body.feeds || [];
      if (!liveSet.iptv.activeFeedId) liveSet.iptv.activeFeedId = body.activeFeedId || 'all';
      if (!Array.isArray(liveSet.iptv.channels)) liveSet.iptv.channels = [];
      liveSet.favView = body.favourites || null;
      if (!liveSet.browse.feed) liveSet.browse.feed = liveSet.iptv.activeFeedId || 'all';
      return liveSet.iptv;
    });
  }

  function liveIptvDot(st){
    st = st || 'unknown';
    var title = st === 'ok' ? 'Stream OK (last Check)' : (st === 'bad' ? 'Stream failed (last Check)' : 'Not checked yet \u2014 use Check');
    return h('span', { className: 'iptv-dot iptv-dot-' + st, title: title, 'aria-label': title });
  }

  function liveSectionMeta(sec){
    var map = {
      'live-studio': { title: 'TV Studio', blurb: 'Weekly calendar per channel, filler that plays between slots, ad breaks, numbering, logos and access, XMLTV / M3U outputs, recordings and stream status.' },
      'live-channels': { title: 'Relay channels', blurb: 'Number, name and slogan each Relay channel, put it on or off air, and tick the movies, TV shows (whole show, season or single episodes), commercials and bumpers it may air.' },
      'live-library': { title: 'Relay media library', blurb: 'Everything Relay can schedule. Add movies or whole shows from the server catalogue, add files by URL, edit titles / season / episode / duration, or remove items.' },
      'live-playlists': { title: 'Playlists & programming', blurb: 'Set programs air first, in order; under the fill window Relay fills at random from the channel library. Commercial breaks, bumpers and the random seed live here too.' },
      'live-schedule': { title: 'Rolling schedule', blurb: '24-hour wall-clock slots: rotate a movie nightly, or advance one TV episode per day. Deterministic from the calendar day.' },
      'live-iptv': { title: 'IPTV feeds', blurb: 'M3U playlist feeds, browse and search channels, favourites lineup, on-demand stream Check, inactive filter, and XMLTV EPG.' },
      'live-station': { title: 'Station & outputs', blurb: 'Station identity, media base URL, timeshift history, guide lookahead, external-player stream URLs, and JSON backup / merge import.' }
    };
    return map[sec] || map['live-channels'];
  }

  function liveNativeSettingsKids(sec){
    if (sec === 'live') sec = 'live-channels';
    var meta = liveSectionMeta(sec);
    var related = [
      ['live-studio', 'TV Studio'],
      ['live-channels', 'Relay'],
      ['live-library', 'Media library'],
      ['live-playlists', 'Playlists'],
      ['live-schedule', 'Schedule'],
      ['live-iptv', 'IPTV'],
      ['live-station', 'Station']
    ];
    var stationSection = sec !== 'live-iptv' && sec !== 'live-studio';
    setTimeout(function(){ fillLiveNativePanel(sec); }, 0);
    return [
      h('div', { className: 'settings-live-head' }, [
        h('div', {}, [
          h('h2', { text: meta.title }),
          h('p', { className: 'muted', text: meta.blurb })
        ]),
        stationSection ? h('div', { className: 'row-actions live-save-bar' }, [
          h('span', { id: 'live-dirty', className: 'live-dirty', hidden: !liveSet.dirty, text: 'Unsaved changes' }),
          h('button', { id: 'live-discard', className: 'btn ghost', type: 'button', text: 'Discard changes', disabled: !liveSet.dirty, onClick: function(){
            if (!confirm('Discard all unsaved Relay changes and reload the station from the server?')) return;
            liveSet.dirty = false; liveSet.editor = null; liveSet.durPending = 0;
            liveSet.station = null;
            liveSetMsg('Changes discarded.');
            fillLiveNativePanel(sec);
          }}),
          h('button', { className: 'btn primary', type: 'button', 'data-live-save': '1', text: 'Save station', onClick: liveSaveStation })
        ]) : null
      ]),
      h('div', { className: 'settings-live-subnav', role: 'tablist', 'aria-label': 'Watch Live sections' }, related.map(function(pair){
        return h('button', {
          type: 'button',
          className: 'settings-live-subnav-btn' + (sec === pair[0] ? ' active' : ''),
          onClick: function(){ openSettings(pair[0]); }
        }, [ document.createTextNode(pair[1]) ]);
      })),
      h('p', { id: 'live-native-status', className: 'muted live-native-status' + (liveSet.statusBad ? ' bad' : ''), text: liveSet.status || '' }),
      h('div', { id: 'live-native-panel', className: 'live-native-panel' }, [
        pendingContent({ className: 'muted', })
      ])
    ];
  }

  function fillLiveNativePanel(sec){
    var host = document.getElementById('live-native-panel');
    if (!host) return;
    if (sec === 'live') sec = 'live-channels';
    host.innerHTML = '';
    host.appendChild(pendingContent({ className: 'muted', }));
    /* unsaved Relay edits survive moving between sections; otherwise always reload from the server */
    var p;
    if (sec === 'live-studio') { paintTvStudio(host); return; }
    if (sec === 'live-iptv') p = liveLoadIptv(true).then(function(){ paintLiveIptv(host); });
    else p = liveLoadStation(!liveSet.dirty).then(function(){
      liveSyncDirty();
      if (sec === 'live-schedule') paintLiveSchedule(host);
      else if (sec === 'live-playlists') paintLivePlaylists(host);
      else if (sec === 'live-station') paintLiveStation(host);
      else if (sec === 'live-library') paintLiveLibrary(host);
      else paintLiveChannels(host);
    });
    p.catch(function(e){
      host.innerHTML = '';
      host.appendChild(h('p', { className: 'bad', text: String(e.message || e) }));
    });
  }

  /* ---------------- TV Studio (weekly calendar, filler, adverts, outputs) ---------------- */
  var tvs = { data:null, studio:null, tab:'schedule', channelId:'', dirty:false, sel:null, preview:null, health:null };
  var TVS_DAYS = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
  var TVS_PX_PER_MIN = 0.75; /* 18 px per 24 min -> 1080 px per day */
  var TVS_SNAP = 15;
  function tvsApi(action, opts){
    return api('/live/' + action + (opts && opts.query ? opts.query : ''), opts || {}).then(function(j){
      if (opts && opts.method && opts.method !== 'GET' && j && j.ok !== false) invalidateLiveData(false);
      return j;
    });
  }
  function tvsLoad(){
    return tvsApi('tv_studio').then(function(j){
      if (!j || !j.ok) throw new Error((j && j.error) || 'Could not load TV Studio');
      tvs.data = j; tvs.studio = JSON.parse(JSON.stringify(j.studio)); tvs.dirty = false;
      if (!tvs.channelId || !j.channels.some(function(c){ return c.id === tvs.channelId; })) tvs.channelId = j.channels.length ? j.channels[0].id : '';
    });
  }
  function tvsUid(p){ return p + '-' + Math.random().toString(36).slice(2, 10); }
  function tvsChan(id){
    id = id || tvs.channelId;
    if (!tvs.studio.channels[id] || Array.isArray(tvs.studio.channels[id])) {
      tvs.studio.channels[id] = { mode:'overlay', fillerListId:'', logo:'', group:'', access:{ visibility:'everyone', users:[] }, timeshift:true,
        ads:{ enabled:false, poolId:'', everyMinutes:15, breakSeconds:120, preRoll:false, kinds:['movie','episode'] }, slots:[] };
    }
    return tvs.studio.channels[id];
  }
  function tvsMedia(id){ var m = (tvs.data.media || []); for (var i = 0; i < m.length; i++) if (m[i].id === id) return m[i]; return null; }
  function tvsFmtMin(m){ m = ((m % 1440) + 1440) % 1440; return ('0' + Math.floor(m / 60)).slice(-2) + ':' + ('0' + (m % 60)).slice(-2); }
  function tvsFmtDur(ms){ var m = Math.round(ms / 60000); return m >= 60 ? (Math.floor(m / 60) + 'h ' + (m % 60) + 'm') : (m + 'm'); }
  function tvsMark(){ tvs.dirty = true; var d = $('#tvs-dirty'); if (d) d.hidden = false; var b = $('#tvs-save'); if (b) b.disabled = false; }
  function tvsSave(){
    var btn = $('#tvs-save'); if (btn) btn.disabled = true;
    return tvsApi('tv_studio', { method:'POST', body:{ studio: tvs.studio } }).then(function(j){
      if (!j || !j.ok) throw new Error((j && j.error) || 'Save failed');
      tvs.data = j; tvs.studio = JSON.parse(JSON.stringify(j.studio)); tvs.dirty = false;
      toast('TV Studio saved', { kind:'ok' }); tvsPaint();
    }).catch(function(e){ toast(String(e.message || e), { kind:'bad' }); if (btn) btn.disabled = false; });
  }
  function paintTvStudio(host){
    host.innerHTML = '';
    host.appendChild(pendingContent({ className:'muted', }));
    (tvs.data && tvs.dirty ? Promise.resolve() : tvsLoad()).then(function(){ tvsPaint(); }).catch(function(e){
      host.innerHTML = ''; host.appendChild(h('p', { className:'bad', text:String(e.message || e) }));
    });
  }
  function tvsPaint(){
    var host = document.getElementById('live-native-panel');
    if (!host || !tvs.data) return;
    host.innerHTML = '';
    // Scope root for TV Studio styles (.tvs-root ...), so they never reach the header or other panels.
    host = host.appendChild(h('div', { className:'tvs-root' }));
    var tabs = [['schedule','Schedule'],['filler','Filler'],['adverts','Adverts'],['channels','Channels'],['epg','EPG & outputs'],['dvr','Recordings'],['health','Status']];
    var chans = tvs.data.channels.slice().sort(function(a, b){ return a.number - b.number; });
    host.appendChild(h('div', { className:'tvs-bar' }, [
      h('div', { className:'tvs-tabs', role:'tablist' }, tabs.map(function(t){
        return h('button', { type:'button', role:'tab', className:'tvs-tab' + (tvs.tab === t[0] ? ' active' : ''), 'aria-selected': tvs.tab === t[0] ? 'true' : 'false', text:t[1], onClick:function(){ tvs.tab = t[0]; tvsPaint(); } });
      })),
      h('div', { className:'row-actions' }, [
        h('span', { id:'tvs-dirty', className:'live-dirty', hidden:!tvs.dirty, text:'Unsaved changes' }),
        h('button', { className:'btn ghost', type:'button', text:'Discard', onClick:function(){ tvs.data = null; paintTvStudio(host); } }),
        h('button', { id:'tvs-save', className:'btn primary', type:'button', text:'Save studio', disabled:!tvs.dirty, onClick:tvsSave })
      ])
    ]));
    var warn = (tvs.data.warnings || []);
    if (warn.length) host.appendChild(h('div', { className:'tvs-warnings' }, warn.slice(0, 6).map(function(w){ return h('p', { text:'\u26a0 ' + w.message }); })));
    if (!chans.length && tvs.tab !== 'health' && tvs.tab !== 'epg') {
      host.appendChild(h('div', { className:'settings-card' }, [ h('p', { text:'Create a Relay channel first, then schedule it here.' }),
        h('button', { className:'btn', type:'button', text:'Open Relay channels', onClick:function(){ openSettings('live-channels'); } }) ]));
      return;
    }
    var picker = function(){
      return h('label', { className:'tvs-chan-pick' }, [ h('span', { className:'muted', text:'Channel' }),
        (function(){
          var s = h('select', { onChange:function(e){ tvs.channelId = e.target.value; tvs.sel = null; tvsPaint(); } }, chans.map(function(c){
            return h('option', { value:c.id, selected:c.id === tvs.channelId, text:c.number + ' \u00b7 ' + c.name });
          }));
          return s;
        })() ]);
    };
    var body = h('div', { className:'tvs-body' });
    host.appendChild(body);
    ({ schedule:tvsPaintSchedule, filler:tvsPaintFiller, adverts:tvsPaintAdverts, channels:tvsPaintChannels, epg:tvsPaintEpg, dvr:tvsPaintDvr, health:tvsPaintHealth })[tvs.tab](body, picker);
  }

  /* ---- weekly calendar ---- */
  function tvsPaintSchedule(body, picker){
    var ch = tvsChan();
    var top = h('div', { className:'tvs-row' }, [ picker(),
      h('label', { className:'tvs-chan-pick' }, [ h('span', { className:'muted', text:'Outside slots' }),
        h('select', { onChange:function(e){ ch.mode = e.target.value; tvsMark(); tvsPaint(); } }, [
          h('option', { value:'overlay', selected:ch.mode === 'overlay', text:'Keep the Relay rotation' }),
          h('option', { value:'calendar', selected:ch.mode === 'calendar', text:'Play the filler list' }) ]) ]),
      ch.mode === 'calendar' ? tvsListSelect('Filler list', tvs.studio.fillerLists, ch.fillerListId, function(v){ ch.fillerListId = v; tvsMark(); }) : null,
      h('p', { className:'muted tvs-hint', text:'Drag on an empty area to add a slot. Drag a slot to move it, drag its bottom edge to resize. Times snap to ' + TVS_SNAP + ' minutes.' })
    ]);
    body.appendChild(top);
    var wrap = h('div', { className:'tvs-cal-wrap' });
    var cal = h('div', { className:'tvs-cal' });
    var hours = h('div', { className:'tvs-hours' }, [ h('div', { className:'tvs-dayhead' }) ]);
    var hcol = h('div', { className:'tvs-hcol', style:'height:' + (1440 * TVS_PX_PER_MIN) + 'px' });
    for (var hr = 0; hr < 24; hr++) hcol.appendChild(h('span', { style:'top:' + (hr * 60 * TVS_PX_PER_MIN) + 'px', text:('0' + hr).slice(-2) + ':00' }));
    hours.appendChild(hcol); cal.appendChild(hours);
    var nowD = new Date(); var today = (nowD.getDay() + 6) % 7; var nowMin = nowD.getHours() * 60 + nowD.getMinutes();
    TVS_DAYS.forEach(function(dn, d){
      var col = h('div', { className:'tvs-day' + (d === today ? ' today' : '') }, [ h('div', { className:'tvs-dayhead', text:dn }) ]);
      var lane = h('div', { className:'tvs-lane', 'data-day':String(d), style:'height:' + (1440 * TVS_PX_PER_MIN) + 'px' });
      if (d === today) lane.appendChild(h('div', { className:'tvs-now', style:'top:' + (nowMin * TVS_PX_PER_MIN) + 'px' }));
      lane.addEventListener('pointerdown', function(e){ if (e.target === lane) tvsCreateDrag(e, lane, d); });
      col.appendChild(lane); cal.appendChild(col);
    });
    ch.slots.forEach(function(s){
      s.days.forEach(function(d){
        var lane = cal.querySelector('.tvs-lane[data-day="' + d + '"]');
        var segs = [[s.startMin, Math.min(1440, s.startMin + s.durationMin), d]];
        if (s.startMin + s.durationMin > 1440) segs.push([0, s.startMin + s.durationMin - 1440, (d + 1) % 7]);
        segs.forEach(function(sg, i){
          var ln = i ? cal.querySelector('.tvs-lane[data-day="' + sg[2] + '"]') : lane;
          ln.appendChild(tvsSlotEl(s, sg[0], sg[1], d, i > 0));
        });
      });
    });
    wrap.appendChild(cal);
    var layout = h('div', { className:'tvs-sched' }, [ wrap, tvsSlotEditor() ]);
    body.appendChild(layout);
    setTimeout(function(){ wrap.scrollTop = Math.max(0, (nowMin - 120) * TVS_PX_PER_MIN); }, 0);
    tvsPreview(body);
  }
  function tvsSlotLabel(s){
    if (s.title) return s.title;
    if (s.contentType === 'series') return s.series || 'Series';
    if (s.contentType === 'filler') { var l = tvsFind(tvs.studio.fillerLists, s.fillerListId); return l ? l.name : 'Filler'; }
    var m = s.mediaIds.length ? tvsMedia(s.mediaIds[0]) : null;
    return m ? m.title + (s.mediaIds.length > 1 ? ' +' + (s.mediaIds.length - 1) : '') : 'Empty slot';
  }
  function tvsFind(list, id){ for (var i = 0; i < (list || []).length; i++) if (list[i].id === id) return list[i]; return null; }
  function tvsSlotEl(s, a, b, day, cont){
    var el = h('div', { className:'tvs-slot' + (tvs.sel === s.id ? ' selected' : '') + ' t-' + s.contentType, tabindex:'0',
      style:'top:' + (a * TVS_PX_PER_MIN) + 'px;height:' + Math.max(14, (b - a) * TVS_PX_PER_MIN - 2) + 'px' + (s.color ? ';--slot:' + s.color : '') }, [
      h('strong', { text:tvsSlotLabel(s) }),
      h('span', { text:tvsFmtMin(s.startMin) + '\u2013' + tvsFmtMin(s.startMin + s.durationMin) + (s.days.length > 1 ? ' \u00b7 ' + s.days.length + ' days' : '') }),
      cont ? null : h('div', { className:'tvs-resize', title:'Drag to change length' })
    ]);
    el.addEventListener('click', function(e){ e.stopPropagation(); tvs.sel = s.id; tvsPaint(); });
    el.addEventListener('keydown', function(e){
      if (e.key === 'Delete' || e.key === 'Backspace') { tvsDeleteSlot(s.id); e.preventDefault(); }
      if (e.key === 'ArrowUp' || e.key === 'ArrowDown') { s.startMin = Math.max(0, Math.min(1439, s.startMin + (e.key === 'ArrowUp' ? -TVS_SNAP : TVS_SNAP))); tvsMark(); tvsPaint(); e.preventDefault(); }
    });
    if (!cont) el.addEventListener('pointerdown', function(e){ tvsMoveDrag(e, el, s, day, e.target.classList.contains('tvs-resize')); });
    return el;
  }
  function tvsMinAt(lane, y){ var r = lane.getBoundingClientRect(); return Math.max(0, Math.min(1440, Math.round((y - r.top) / TVS_PX_PER_MIN / TVS_SNAP) * TVS_SNAP)); }
  function tvsDayAt(x){
    var lanes = document.querySelectorAll('.tvs-lane');
    for (var i = 0; i < lanes.length; i++) { var r = lanes[i].getBoundingClientRect(); if (x >= r.left && x < r.right) return Number(lanes[i].getAttribute('data-day')); }
    return null;
  }
  function tvsCreateDrag(e, lane, day){
    e.preventDefault();
    var a = tvsMinAt(lane, e.clientY);
    var ghost = h('div', { className:'tvs-slot ghost', style:'top:' + (a * TVS_PX_PER_MIN) + 'px;height:' + (TVS_SNAP * TVS_PX_PER_MIN) + 'px' });
    lane.appendChild(ghost);
    var b = a + 60;
    function mv(ev){ b = Math.max(a + TVS_SNAP, tvsMinAt(lane, ev.clientY)); ghost.style.height = ((b - a) * TVS_PX_PER_MIN) + 'px'; }
    function up(){
      document.removeEventListener('pointermove', mv); document.removeEventListener('pointerup', up);
      var s = { id:tvsUid('slot'), title:'', days:[day], startMin:Math.min(a, 1439), durationMin:Math.max(TVS_SNAP, b - a), contentType:'list', mediaIds:[], series:'', fillerListId:'', order:'sequential', ads:true, color:'', validFrom:'', validTo:'' };
      tvsChan().slots.push(s); tvs.sel = s.id; tvsMark(); tvsPaint();
    }
    document.addEventListener('pointermove', mv); document.addEventListener('pointerup', up);
  }
  function tvsMoveDrag(e, el, s, day, resize){
    e.preventDefault(); e.stopPropagation();
    var y0 = e.clientY, start0 = s.startMin, dur0 = s.durationMin, moved = false, newDay = day;
    function mv(ev){
      var dm = Math.round((ev.clientY - y0) / TVS_PX_PER_MIN / TVS_SNAP) * TVS_SNAP;
      if (Math.abs(ev.clientY - y0) > 3) moved = true;
      if (resize) { s.durationMin = Math.max(TVS_SNAP, Math.min(1440, dur0 + dm)); el.style.height = (s.durationMin * TVS_PX_PER_MIN - 2) + 'px'; }
      else {
        s.startMin = Math.max(0, Math.min(1440 - TVS_SNAP, start0 + dm)); el.style.top = (s.startMin * TVS_PX_PER_MIN) + 'px';
        var d = tvsDayAt(ev.clientX); if (d !== null) { newDay = d; if (d !== day) moved = true; }
      }
    }
    function up(){
      document.removeEventListener('pointermove', mv); document.removeEventListener('pointerup', up);
      if (!resize && newDay !== day && s.days.indexOf(newDay) < 0) { s.days = s.days.map(function(x){ return x === day ? newDay : x; }).sort(); }
      tvs.sel = s.id; if (moved) tvsMark(); tvsPaint();
    }
    document.addEventListener('pointermove', mv); document.addEventListener('pointerup', up);
  }
  function tvsDeleteSlot(id){ var ch = tvsChan(); ch.slots = ch.slots.filter(function(x){ return x.id !== id; }); tvs.sel = null; tvsMark(); tvsPaint(); }
  function tvsListSelect(label, lists, val, onChange){
    return h('label', { className:'tvs-chan-pick' }, [ h('span', { className:'muted', text:label }),
      h('select', { onChange:function(e){ onChange(e.target.value); } }, [ h('option', { value:'', text:'\u2014 none \u2014' }) ].concat((lists || []).map(function(l){
        return h('option', { value:l.id, selected:l.id === val, text:l.name + ' (' + l.mediaIds.length + ')' });
      }))) ]);
  }
  function tvsMediaPicker(selected, onChange, kinds){
    var q = '';
    var box = h('div', { className:'tvs-picker' });
    var chosen = h('ol', { className:'tvs-chosen' });
    var results = h('div', { className:'tvs-results' });
    function paint(){
      chosen.innerHTML = '';
      selected.forEach(function(id, i){
        var m = tvsMedia(id);
        chosen.appendChild(h('li', {}, [ h('span', { text:(m ? m.title : id) + (m ? ' \u00b7 ' + tvsFmtDur(m.durationMs) : '') }),
          h('button', { className:'icon-btn', type:'button', title:'Move up', text:'\u2191', disabled:i === 0, onClick:function(){ selected.splice(i - 1, 0, selected.splice(i, 1)[0]); onChange(selected); paint(); } }),
          h('button', { className:'icon-btn', type:'button', title:'Remove', text:'\u00d7', onClick:function(){ selected.splice(i, 1); onChange(selected); paint(); } }) ]));
      });
      if (!selected.length) chosen.appendChild(h('li', { className:'muted', text:'Nothing chosen yet' }));
      results.innerHTML = '';
      var ql = q.toLowerCase();
      (tvs.data.media || []).filter(function(m){ return (!kinds || kinds.indexOf(m.kind) >= 0) && selected.indexOf(m.id) < 0 && (!ql || (m.title + ' ' + m.series).toLowerCase().indexOf(ql) >= 0); }).slice(0, 40).forEach(function(m){
        results.appendChild(h('button', { type:'button', className:'tvs-result' + (m.durationMs ? '' : ' no-dur'), title:m.durationMs ? '' : 'Unknown duration \u2014 run the duration task in Live media', onClick:function(){ selected.push(m.id); onChange(selected); paint(); } }, [
          h('span', { className:'lc-pill', text:m.kind }), document.createTextNode(' ' + m.title), h('span', { className:'muted', text:m.durationMs ? ' ' + tvsFmtDur(m.durationMs) : ' ?' }) ]));
      });
    }
    box.appendChild(h('input', { type:'search', placeholder:'Search Live media\u2026', onInput:function(e){ q = e.target.value; paint(); } }));
    box.appendChild(results); box.appendChild(chosen); paint();
    return box;
  }
  function tvsSlotEditor(){
    var ch = tvsChan();
    var s = tvsFind(ch.slots, tvs.sel);
    var pane = h('aside', { className:'tvs-editor settings-card' });
    if (!s) {
      pane.appendChild(h('h3', { text:'Slots' }));
      pane.appendChild(h('p', { className:'muted', text:ch.slots.length ? 'Select a slot to edit it.' : 'No slots yet. Drag across the calendar to create one.' }));
      ch.slots.forEach(function(x){ pane.appendChild(h('button', { type:'button', className:'tvs-slot-row', onClick:function(){ tvs.sel = x.id; tvsPaint(); } }, [
        h('strong', { text:tvsSlotLabel(x) }), h('span', { className:'muted', text:' ' + x.days.map(function(d){ return TVS_DAYS[d]; }).join(', ') + ' ' + tvsFmtMin(x.startMin) }) ])); });
      return pane;
    }
    function set(k, v, repaint){ s[k] = v; tvsMark(); if (repaint) tvsPaint(); }
    pane.appendChild(h('div', { className:'tvs-row' }, [ h('h3', { text:'Edit slot' }), h('button', { className:'btn ghost', type:'button', text:'Close', onClick:function(){ tvs.sel = null; tvsPaint(); } }) ]));
    pane.appendChild(h('label', {}, [ 'Title', h('input', { type:'text', value:s.title, placeholder:'Shown in the guide (optional)', onChange:function(e){ set('title', e.target.value, true); } }) ]));
    pane.appendChild(h('div', { className:'tvs-days' }, TVS_DAYS.map(function(dn, d){
      return h('button', { type:'button', className:'tvs-daybtn' + (s.days.indexOf(d) >= 0 ? ' on' : ''), text:dn, onClick:function(){
        var i = s.days.indexOf(d); if (i >= 0) { if (s.days.length > 1) s.days.splice(i, 1); } else s.days.push(d); s.days.sort(); set('days', s.days, true);
      } });
    }).concat([ h('button', { type:'button', className:'linkish', text:'Every day', onClick:function(){ set('days', [0,1,2,3,4,5,6], true); } }),
      h('button', { type:'button', className:'linkish', text:'Weekdays', onClick:function(){ set('days', [0,1,2,3,4], true); } }) ])));
    pane.appendChild(h('div', { className:'tvs-row' }, [
      h('label', {}, [ 'Start', h('input', { type:'time', step:'300', value:tvsFmtMin(s.startMin), onChange:function(e){ var p = e.target.value.split(':'); set('startMin', Number(p[0]) * 60 + Number(p[1]), true); } }) ]),
      h('label', {}, [ 'Length (min)', h('input', { type:'number', min:'5', max:'1440', step:'5', value:String(s.durationMin), onChange:function(e){ set('durationMin', Math.max(5, Math.min(1440, Number(e.target.value) || 60)), true); } }) ])
    ]));
    pane.appendChild(h('label', {}, [ 'Content', h('select', { onChange:function(e){ set('contentType', e.target.value, true); } }, [
      ['list','Chosen programmes'],['media','Single programme'],['series','Series (next episode each airing)'],['filler','Filler list'] ].map(function(o){ return h('option', { value:o[0], selected:s.contentType === o[0], text:o[1] }); })) ]));
    if (s.contentType === 'series') {
      var series = {}; (tvs.data.media || []).forEach(function(m){ if (m.kind === 'episode' && m.series) series[m.series] = (series[m.series] || 0) + 1; });
      pane.appendChild(h('label', {}, [ 'Series', h('select', { onChange:function(e){ set('series', e.target.value, true); } }, [ h('option', { value:'', text:'Choose a show' }) ].concat(Object.keys(series).sort().map(function(n){
        return h('option', { value:n, selected:n === s.series, text:n + ' (' + series[n] + ' episodes)' }); }))) ]));
    } else if (s.contentType === 'filler') {
      pane.appendChild(tvsListSelect('Filler list', tvs.studio.fillerLists, s.fillerListId, function(v){ set('fillerListId', v, true); }));
    } else {
      if (s.contentType === 'list') pane.appendChild(h('label', {}, [ 'Order', h('select', { onChange:function(e){ set('order', e.target.value); } }, [
        h('option', { value:'sequential', selected:s.order === 'sequential', text:'In order' }), h('option', { value:'shuffle', selected:s.order === 'shuffle', text:'Shuffle each airing' }) ]) ]));
      pane.appendChild(tvsMediaPicker(s.mediaIds, function(ids){ if (s.contentType === 'media' && ids.length > 1) ids.splice(0, ids.length - 1); set('mediaIds', ids); }, ['movie','episode','bumper','commercial','other']));
    }
    pane.appendChild(h('label', { className:'tvs-check' }, [ h('input', { type:'checkbox', checked:s.ads, onChange:function(e){ set('ads', e.target.checked); } }), ' Allow ad breaks in this slot' ]));
    pane.appendChild(h('div', { className:'tvs-row' }, [
      h('label', {}, [ 'From', h('input', { type:'date', value:s.validFrom, onChange:function(e){ set('validFrom', e.target.value); } }) ]),
      h('label', {}, [ 'Until', h('input', { type:'date', value:s.validTo, onChange:function(e){ set('validTo', e.target.value); } }) ]),
      h('label', {}, [ 'Colour', h('input', { type:'color', value:s.color || '#6c5ce7', onChange:function(e){ set('color', e.target.value, true); } }) ])
    ]));
    pane.appendChild(h('div', { className:'row-actions' }, [
      h('button', { className:'btn ghost', type:'button', text:'Duplicate', onClick:function(){ var c = JSON.parse(JSON.stringify(s)); c.id = tvsUid('slot'); c.startMin = Math.min(1439, s.startMin + s.durationMin); ch.slots.push(c); tvs.sel = c.id; tvsMark(); tvsPaint(); } }),
      h('button', { className:'btn danger', type:'button', text:'Delete slot', onClick:function(){ tvsDeleteSlot(s.id); } })
    ]));
    return pane;
  }
  function tvsPreview(body){
    var card = h('div', { className:'settings-card tvs-preview' }, [ h('h3', { text:'Next 12 hours (saved schedule)' }), pendingContent({ className:'muted', }) ]);
    body.appendChild(card);
    tvsApi('tv_preview', { query:'?channel=' + encodeURIComponent(tvs.channelId) + '&hours=12' }).then(function(j){
      card.lastChild.remove();
      var list = h('ol', { className:'tvs-timeline' });
      (j.blocks || []).slice(0, 80).forEach(function(b){
        list.appendChild(h('li', { className:(b.advert ? 'ad' : (b.filler ? 'filler' : (b.slotId ? 'slot' : 'cycle'))) }, [
          h('time', { text:new Date(b.startUnix).toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' }) }),
          h('span', { text:b.title + (b.mediaOffsetMs ? ' (cont.)' : '') }),
          h('em', { className:'muted', text:b.advert ? 'advert' : (b.filler ? 'filler' : (b.slotTitle || (b.slotId ? 'slot' : 'rotation'))) }) ]));
      });
      if (!list.children.length) list.appendChild(h('li', { className:'muted', text:'Off air \u2014 nothing scheduled and no filler.' }));
      card.appendChild(list);
      if (tvs.dirty) card.appendChild(h('p', { className:'muted', text:'Save to update this preview.' }));
    }).catch(function(e){ card.lastChild.textContent = String(e.message || e); });
  }

  /* ---- filler & adverts ---- */
  function tvsPaintLists(body, key, title, blurb, kinds){
    var lists = tvs.studio[key];
    body.appendChild(h('div', { className:'tvs-row' }, [ h('p', { className:'muted', text:blurb }),
      h('button', { className:'btn', type:'button', text:'New ' + title.toLowerCase(), onClick:function(){ lists.push({ id:tvsUid(key === 'adPools' ? 'ads' : 'fill'), name:title + ' ' + (lists.length + 1), mediaIds:[], order:'shuffle' }); tvsMark(); tvsPaint(); } }) ]));
    if (!lists.length) body.appendChild(h('div', { className:'settings-card' }, [ h('p', { className:'muted', text:'No ' + title.toLowerCase() + 's yet.' }) ]));
    lists.forEach(function(l, i){
      var total = l.mediaIds.reduce(function(a, id){ var m = tvsMedia(id); return a + (m ? m.durationMs : 0); }, 0);
      body.appendChild(h('div', { className:'settings-card tvs-list' }, [
        h('div', { className:'tvs-row' }, [
          h('input', { type:'text', value:l.name, 'aria-label':'Name', onChange:function(e){ l.name = e.target.value; tvsMark(); } }),
          h('select', { onChange:function(e){ l.order = e.target.value; tvsMark(); } }, [ h('option', { value:'shuffle', selected:l.order === 'shuffle', text:'Shuffle' }), h('option', { value:'sequential', selected:l.order === 'sequential', text:'In order' }) ]),
          h('span', { className:'muted', text:l.mediaIds.length + ' items \u00b7 ' + tvsFmtDur(total) }),
          h('button', { className:'btn danger', type:'button', text:'Delete', onClick:function(){ if (!confirm('Delete ' + l.name + '?')) return; lists.splice(i, 1); tvsMark(); tvsPaint(); } }) ]),
        tvsMediaPicker(l.mediaIds, function(ids){ l.mediaIds = ids; tvsMark(); }, kinds)
      ]));
    });
  }
  function tvsPaintFiller(body){ tvsPaintLists(body, 'fillerLists', 'Filler list', 'Filler plays whenever no slot is on air (channels set to "Play the filler list"), or inside a slot that uses one.', null); }
  function tvsPaintAdverts(body, picker){
    var ch = tvsChan(); var a = ch.ads;
    body.appendChild(h('div', { className:'settings-card' }, [ h('h3', { text:'Ad rules' }), picker(),
      h('label', { className:'tvs-check' }, [ h('input', { type:'checkbox', checked:a.enabled, onChange:function(e){ a.enabled = e.target.checked; tvsMark(); } }), ' Insert ad breaks on this channel' ]),
      h('div', { className:'tvs-row' }, [
        tvsListSelect('Ad pool', tvs.studio.adPools, a.poolId, function(v){ a.poolId = v; tvsMark(); }),
        h('label', {}, [ 'Break every (min)', h('input', { type:'number', min:'5', max:'120', value:String(a.everyMinutes), onChange:function(e){ a.everyMinutes = Number(e.target.value) || 15; tvsMark(); } }) ]),
        h('label', {}, [ 'Break length (s)', h('input', { type:'number', min:'10', max:'600', step:'5', value:String(a.breakSeconds), onChange:function(e){ a.breakSeconds = Number(e.target.value) || 120; tvsMark(); } }) ]) ]),
      h('label', { className:'tvs-check' }, [ h('input', { type:'checkbox', checked:a.preRoll, onChange:function(e){ a.preRoll = e.target.checked; tvsMark(); } }), ' Pre-roll break before each programme' ]),
      h('div', { className:'tvs-days' }, [ h('span', { className:'muted', text:'Breaks in: ' }) ].concat([['movie','Movies'],['episode','Episodes'],['other','Other']].map(function(k){
        return h('button', { type:'button', className:'tvs-daybtn' + (a.kinds.indexOf(k[0]) >= 0 ? ' on' : ''), text:k[1], onClick:function(){ var i = a.kinds.indexOf(k[0]); if (i >= 0) a.kinds.splice(i, 1); else a.kinds.push(k[0]); tvsMark(); tvsPaint(); } });
      }))),
      h('p', { className:'muted', text:'Breaks are never placed in the last two minutes of a programme. Slots can opt out individually. The programme resumes where it left off after each break.' })
    ]));
    tvsPaintLists(body, 'adPools', 'Ad pool', 'Ad pools are lists of commercials (or promos) that breaks draw from in order or shuffled.', ['commercial','bumper','other']);
  }

  /* ---- channels: numbering, logos, groups, access, timeshift ---- */
  function tvsPaintChannels(body){
    var edits = {};
    var users = tvs.data.users || [];
    var rows = tvs.data.channels.slice().sort(function(a, b){ return a.number - b.number; }).map(function(c){
      var cfg = tvsChan(c.id);
      edits[c.id] = { id:c.id, number:c.number, name:c.name, enabled:c.enabled };
      var e = edits[c.id];
      return h('tr', {}, [
        h('td', {}, [ h('input', { type:'number', min:'1', max:'9999', className:'tvs-num', value:String(c.number), onChange:function(ev){ e.number = Number(ev.target.value); } }) ]),
        h('td', {}, [ h('div', { className:'tvs-logo' }, [ cfg.logo ? h('img', { src:cfg.logo, alt:'' }) : h('span', { text:(c.name || '?').slice(0, 2) }) ]) ]),
        h('td', {}, [ h('input', { type:'text', value:c.name, onChange:function(ev){ e.name = ev.target.value; } }) ]),
        h('td', {}, [ h('input', { type:'url', value:cfg.logo, placeholder:'https://\u2026/logo.png', onChange:function(ev){ cfg.logo = ev.target.value.trim(); tvsMark(); } }) ]),
        h('td', {}, [ h('input', { type:'text', value:cfg.group, placeholder:'Relay', onChange:function(ev){ cfg.group = ev.target.value; tvsMark(); } }) ]),
        h('td', {}, [ h('select', { onChange:function(ev){ cfg.access.visibility = ev.target.value; tvsMark(); tvsPaint(); } }, [['everyone','Everyone'],['signed-in','Signed-in users'],['users','Chosen users'],['admins','Admins only']].map(function(o){ return h('option', { value:o[0], selected:cfg.access.visibility === o[0], text:o[1] }); })),
          cfg.access.visibility === 'users' ? h('select', { multiple:true, size:'3', onChange:function(ev){ cfg.access.users = Array.prototype.filter.call(ev.target.options, function(o){ return o.selected; }).map(function(o){ return o.value; }); tvsMark(); } }, users.map(function(u){ return h('option', { value:u.username, selected:cfg.access.users.indexOf(u.username) >= 0, text:u.username }); })) : null ]),
        h('td', {}, [ h('input', { type:'checkbox', checked:cfg.timeshift, title:'Allow rewinding into the past (history window set in Station)', onChange:function(ev){ cfg.timeshift = ev.target.checked; tvsMark(); } }) ]),
        h('td', {}, [ h('input', { type:'checkbox', checked:c.enabled, title:'On air', onChange:function(ev){ e.enabled = ev.target.checked; } }) ])
      ]);
    });
    body.appendChild(h('div', { className:'settings-card' }, [
      h('div', { className:'tvs-row' }, [ h('h3', { text:'Channels & numbering' }), h('button', { className:'btn', type:'button', text:'Apply numbers & names', onClick:function(){
        tvsApi('tv_channels', { method:'POST', body:{ channels:Object.keys(edits).map(function(k){ return edits[k]; }) } }).then(function(j){
          if (!j.ok) throw new Error(j.error || 'Failed'); var keep = tvs.studio; tvs.data = j; tvs.studio = keep; toast('Channels updated', { kind:'ok' }); tvsPaint();
        }).catch(function(err){ toast(String(err.message || err), { kind:'bad' }); });
      } }) ]),
      h('p', { className:'muted', text:'Numbers, names and on-air apply immediately with the button. Logos, groups, access and timeshift are saved with "Save studio". IPTV channel numbers are edited under IPTV feeds.' }),
      h('div', { className:'tvs-table-wrap' }, [ h('table', { className:'tvs-table' }, [
        h('thead', {}, [ h('tr', {}, ['No.','','Name','Logo URL','Group','Who can watch','Timeshift','On air'].map(function(t){ return h('th', { text:t }); })) ]),
        h('tbody', {}, rows) ]) ])
    ]));
  }

  /* ---- EPG / outputs / sources ---- */
  function tvsPaintEpg(body){
    var origin = location.origin + BASE;
    var e = tvs.studio.epg;
    var copy = function(url){ return h('div', { className:'tvs-url' }, [ h('code', { text:url }), h('button', { className:'btn ghost', type:'button', text:'Copy', onClick:function(){ try { navigator.clipboard.writeText(url); toast('Copied'); } catch (x) {} } }) ]); };
    body.appendChild(h('div', { className:'settings-card' }, [ h('h3', { text:'Outputs for external players' }),
      h('p', { className:'muted', text:'Point Kodi, TiviMate, Jellyfin or Plex (via xTeVe/Threadfin) at these. The lineup M3U includes every on-air Relay channel and your IPTV favourites, with numbers, groups and logos.' }),
      h('label', {}, [ 'Lineup M3U' ]), copy(origin + 'api/live/playlist'),
      h('label', {}, [ 'XMLTV guide' ]), copy(origin + 'api/live/xmltv'),
      h('div', { className:'tvs-row' }, [
        h('label', {}, [ 'Guide days', h('input', { type:'number', min:'1', max:'7', value:String(e.exportDays), onChange:function(ev){ e.exportDays = Number(ev.target.value) || 2; tvsMark(); } }) ]),
        h('label', { className:'tvs-check' }, [ h('input', { type:'checkbox', checked:e.includeIptv, onChange:function(ev){ e.includeIptv = ev.target.checked; tvsMark(); } }), ' Include IPTV programmes from imported XMLTV' ]),
        h('label', {}, [ 'Schedule time zone', h('select', { onChange:function(ev){ tvs.studio.timezone = ev.target.value; tvsMark(); } }, (tvs.data.timezones || []).map(function(z){ return h('option', { value:z, selected:z === tvs.studio.timezone, text:z }); })) ]) ]),
      h('a', { className:'btn', href:origin + 'api/live/xmltv?dl=1', text:'Download XMLTV' })
    ]));
    body.appendChild(h('div', { className:'settings-card' }, [ h('h3', { text:'Sources & EPG import' }),
      h('p', { className:'muted', text:(tvs.data.feeds || []).length + ' M3U source(s) and ' + (tvs.data.epgUrls || []).length + ' XMLTV import URL(s) are configured. Sources, stream lists and EPG URLs are managed in IPTV feeds.' }),
      h('ul', { className:'tvs-sources' }, (tvs.data.feeds || []).map(function(f){ return h('li', {}, [ h('strong', { text:f.name || f.id }), h('span', { className:'muted', text:' ' + (f.enabled === false ? 'disabled' : 'enabled') }) ]); })),
      h('div', { className:'row-actions' }, [ h('button', { className:'btn', type:'button', text:'Open IPTV feeds', onClick:function(){ openSettings('live-iptv'); } }),
        h('button', { className:'btn ghost', type:'button', text:'Refresh EPG now', onClick:function(){ api('/live/epg_refresh', { method:'POST' }).then(function(j){ toast(j && j.ok !== false ? 'EPG refreshed' : (j.error || 'EPG refresh failed')); }); } }) ])
    ]));
  }

  /* ---- recordings ---- */
  function tvsPaintDvr(body, picker){
    var recs = tvs.studio.recordings;
    var title = h('input', { type:'text', placeholder:'Title' });
    var start = h('input', { type:'datetime-local' });
    var len = h('input', { type:'number', min:'5', value:'60' });
    body.appendChild(h('div', { className:'settings-card' }, [ h('h3', { text:'Recordings' }),
      h('p', { className:'muted', text:'Relay channels are scheduled from your own library, so a "recording" is a bookmark of what aired that viewers can replay within the timeshift window. Capturing IPTV streams to disk needs the recorder worker planned for the next phase; entries are kept as scheduled until then.' }),
      h('div', { className:'tvs-row' }, [ picker(), title, start, len, h('button', { className:'btn', type:'button', text:'Schedule', onClick:function(){
        var s = new Date(start.value).getTime(); if (!s) { toast('Pick a start time'); return; }
        recs.push({ id:tvsUid('rec'), channelId:tvs.channelId, title:title.value || 'Recording', startUnix:s, endUnix:s + (Number(len.value) || 60) * 60000, status:'scheduled', createdAt:Date.now() }); tvsMark(); tvsPaint();
      } }) ]),
      h('table', { className:'tvs-table' }, [ h('tbody', {}, recs.slice().sort(function(a, b){ return b.startUnix - a.startUnix; }).map(function(r){
        var c = tvs.data.channels.filter(function(x){ return x.id === r.channelId; })[0];
        return h('tr', {}, [ h('td', { text:new Date(r.startUnix).toLocaleString() }), h('td', { text:tvsFmtDur(r.endUnix - r.startUnix) }), h('td', { text:c ? c.number + ' ' + c.name : r.channelId }), h('td', { text:r.title }), h('td', {}, [ h('span', { className:'lc-pill', text:r.status }) ]),
          h('td', {}, [ h('button', { className:'btn ghost', type:'button', text:'Remove', onClick:function(){ tvs.studio.recordings = recs.filter(function(x){ return x !== r; }); tvsMark(); tvsPaint(); } }) ]) ]);
      })) ])
    ]));
  }

  /* ---- status ---- */
  function tvsPaintHealth(body){
    var card = h('div', { className:'settings-card' }, [ h('h3', { text:'Stream status' }), pendingContent({ className:'muted', }) ]);
    body.appendChild(card);
    tvsApi('tv_health').then(function(j){
      card.lastChild.remove();
      var grid = h('div', { className:'tvs-health' });
      (j.relay || []).forEach(function(c){
        grid.appendChild(h('div', { className:'tvs-hcard ' + c.state + (c.enabled ? '' : ' off') }, [
          h('div', { className:'tvs-row' }, [ h('strong', { text:c.number + ' \u00b7 ' + c.name }), liveIptvDot(c.state === 'ok' ? 'ok' : (c.state === 'off-air' ? 'bad' : 'unknown')) ]),
          h('p', { text:c.onAir ? ((c.onAir.advert ? 'Advert: ' : c.onAir.filler ? 'Filler: ' : 'Now: ') + c.onAir.title) : 'Off air' }),
          h('p', { className:'muted', text:c.scheduledNext6h + ' items in the next 6 h' + (c.enabled ? '' : ' \u00b7 disabled') }) ]));
      });
      (j.iptv || []).forEach(function(c){
        grid.appendChild(h('div', { className:'tvs-hcard iptv' + (c.enabled ? '' : ' off') }, [
          h('div', { className:'tvs-row' }, [ h('strong', { text:(c.number || '\u2014') + ' \u00b7 ' + c.name }), liveIptvDot(c.status) ]),
          h('p', { className:'muted', text:'IPTV \u00b7 ' + (c.checkedAt ? 'checked ' + new Date(typeof c.checkedAt === 'number' ? c.checkedAt : Date.parse(c.checkedAt)).toLocaleString() : 'not checked') }) ]));
      });
      card.appendChild(grid);
      card.appendChild(h('p', { className:'muted', text:'EPG updated: ' + (j.epg.updatedAt || 'never') + ((j.epg.errors || []).length ? ' \u00b7 ' + j.epg.errors.length + ' error(s)' : '') }));
      card.appendChild(h('button', { className:'btn ghost', type:'button', text:'Check IPTV streams in IPTV feeds', onClick:function(){ openSettings('live-iptv'); } }));
    }).catch(function(e){ card.lastChild.textContent = String(e.message || e); });
  }

  function liveMediaOfKind(kind){
    return (liveSet.station.media || []).filter(function(m){ return m.kind === kind; });
  }

  /* ---------------- Relay channels ---------------- */
  function paintLiveChannels(host){
    var st = liveSet.station;
    host.innerHTML = '';
    var list = (st.channels || []).slice().sort(function(a,b){ return (Number(a.number)||0)-(Number(b.number)||0); });
    var byKind = {};
    LIVE_KINDS.forEach(function(k){ byKind[k] = liveMediaOfKind(k).sort(function(a, b){ return liveGuideTitle(a).localeCompare(liveGuideTitle(b)); }); });
    var showIdx = liveShowIndex(byKind.episode);

    host.appendChild(h('div', { className: 'settings-card' }, [
      h('div', { className: 'row-actions', style: 'justify-content:space-between;flex-wrap:wrap' }, [
        h('h3', { text: 'Channels (' + list.length + ')', style: 'margin:0' }),
        h('div', { className: 'row-actions' }, [
          h('button', { className: 'btn', type: 'button', text: 'Add channel', onClick: function(){
            var used = {};
            st.channels.forEach(function(c){ used[Number(c.number)] = true; });
            var n = 1; while (used[n]) n++;
            var id = liveUid('ch');
            st.channels.push(liveEnsureChannel({
              id: id, number: n, name: 'Channel ' + n, slogan: '', enabled: true,
              libraryIds: { movie: [], episode: [], commercial: [], bumper: [] },
              playlist: [], fillHours: 6, commercialEveryMs: 25*60*1000, commercialsPerBreak: 2,
              bumperBefore: true, seed: liveUid('seed'), rollingSchedule: { enabled: false, slots: [] }
            }));
            liveSet.channelId = id;
            liveDirty();
            paintLiveChannels(host);
            liveSetMsg('Channel ' + n + ' added \u2014 name it, tick its library, then Save station.');
          }})
        ])
      ]),
      h('p', { className: 'muted', text: 'Relay library: ' + livePlural(byKind.movie.length, 'movie') + ' \u00b7 ' + livePlural(showIdx.length, 'show') + ' (' + livePlural(byKind.episode.length, 'episode') + ') \u00b7 ' + livePlural(byKind.commercial.length, 'commercial') + ' \u00b7 ' + livePlural(byKind.bumper.length, 'bumper') + '. Add or edit items under Media library. Save station rebuilds the PseudoTV loop.' })
    ]));

    list.forEach(function(ch){
      liveEnsureChannel(ch);
      var isOn = function(kind){ return function(id){ return liveSetHas(ch.libraryIds[kind], id); }; };
      var setOn = function(kind){ return function(ids, on){ liveSetApply(ch.libraryIds, kind, ids, on); liveDirty(); }; };
      var summaries = {};
      function sumText(kind){
        var have = {}, n = 0;
        byKind[kind].forEach(function(m){ have[m.id] = true; });
        ch.libraryIds[kind].forEach(function(id){ if (have[id]) n++; });
        if (kind === 'episode') {
          var shows = 0;
          showIdx.forEach(function(g){ if (g.eps.some(function(m){ return liveSetHas(ch.libraryIds.episode, m.id); })) shows++; });
          return LIVE_KIND_PLURAL.episode + ' \u2014 ' + shows + '/' + showIdx.length + ' shows \u00b7 ' + n + '/' + byKind.episode.length + ' episodes';
        }
        return LIVE_KIND_PLURAL[kind] + ' \u2014 ' + n + '/' + byKind[kind].length;
      }
      function refreshSum(kind){ if (summaries[kind]) summaries[kind].textContent = sumText(kind); }

      function section(kind){
        var openKey = 'chacc:' + ch.id + ':' + kind;
        var sumEl = h('span', { text: sumText(kind) });
        summaries[kind] = sumEl;
        var det = h('details', { className: 'live-acc' }, [ h('summary', {}, [sumEl]) ]);
        var built = false;
        function build(){
          if (built) return; built = true;
          if (kind === 'episode') {
            var treeHost = h('div', { className: 'live-tree-host' });
            var qKey = 'chtv:' + ch.id;
            var listed = function(){ var ids = []; liveFilterShows(showIdx, liveSet.q[qKey]).forEach(function(g){ ids = ids.concat(liveIds(g.eps)); }); return ids; };
            var paintTree = function(){
              treeHost.innerHTML = '';
              treeHost.appendChild(liveTree({
                stateKey: 'ch:' + ch.id,
                shows: liveFilterShows(showIdx, liveSet.q[qKey]),
                empty: byKind.episode.length ? 'No shows or episodes match.' : 'No TV in the Relay media library yet \u2014 add shows under Media library.',
                onChange: function(){ refreshSum('episode'); },
                showLead: function(g, ctx){ return liveTriBox(liveIds(g.eps), isOn('episode'), setOn('episode'), ctx, 'Tick / untick every listed episode of ' + g.name); },
                seasonLead: function(g, s, ctx){ return liveTriBox(liveIds(s.eps), isOn('episode'), setOn('episode'), ctx, 'Tick / untick ' + g.name + ' ' + liveSeasonLabel(s)); },
                epRow: function(m, ctx){
                  var cb = h('input', { type: 'checkbox', onChange: function(ev){ setOn('episode')([m.id], ev.target.checked); ctx.sync(); } });
                  ctx.addSync(function(){ cb.checked = isOn('episode')(m.id); });
                  return h('label', { className: 'check-label live-tree-ep' }, [cb, h('span', { className: 'grow', text: liveEpLabel(m) }), h('span', { className: 'muted', text: liveFmtDur(m.durationMs) })]);
                }
              }));
            };
            det.appendChild(h('div', { className: 'live-acc-body' }, [
              h('div', { className: 'live-filter-bar' }, [
                liveSearchInput(qKey, 'Search shows or episodes\u2026', paintTree),
                h('button', { className: 'btn ghost sm', type: 'button', text: 'Tick all listed', onClick: function(){ setOn('episode')(listed(), true); paintTree(); refreshSum('episode'); } }),
                h('button', { className: 'btn ghost sm', type: 'button', text: 'Untick all listed', onClick: function(){ setOn('episode')(listed(), false); paintTree(); refreshSum('episode'); } })
              ]),
              h('p', { className: 'muted live-hint', text: 'Tick a show or season to take all of its episodes; expand it to pick single episodes.' }),
              treeHost
            ]));
            paintTree();
          } else {
            det.appendChild(h('div', { className: 'live-acc-body' }, [
              liveFlatChecks(byKind[kind], isOn(kind), setOn(kind), 'ch' + kind + ':' + ch.id, function(){ refreshSum(kind); },
                'No ' + LIVE_KIND_PLURAL[kind].toLowerCase() + ' in the Relay media library yet \u2014 add them under Media library.')
            ]));
          }
        }
        det.addEventListener('toggle', function(){ liveSet.open[openKey] = det.open; if (det.open) build(); });
        if (liveSet.open[openKey]) { det.open = true; build(); }
        return det;
      }

      var card = h('div', { className: 'settings-card live-ch-card' + (ch.enabled === false ? ' is-off' : '') });
      card.appendChild(h('div', { className: 'live-ch-head' }, [
        h('label', {}, ['#', h('input', { type: 'number', min: 1, className: 'narrow', value: ch.number, onChange: function(e){ var n = Number(e.target.value); if (n > 0) { ch.number = n; liveDirty(); } else e.target.value = ch.number; } })]),
        h('label', { className: 'grow' }, ['Name', h('input', { value: ch.name || '', onInput: function(e){ ch.name = e.target.value; liveDirty(); } })]),
        h('label', { className: 'grow' }, ['Slogan', h('input', { value: ch.slogan || '', onInput: function(e){ ch.slogan = e.target.value; liveDirty(); } })]),
        h('label', { className: 'check-label live-onair' }, [ h('input', { type: 'checkbox', checked: ch.enabled !== false, onChange: function(e){ ch.enabled = e.target.checked; liveDirty(); card.classList.toggle('is-off', !ch.enabled); } }), ' On air' ]),
        h('button', { className: 'btn ghost', type: 'button', text: 'Remove', onClick: function(){
          if (st.channels.length < 2) { liveSetMsg('Keep at least one Relay channel.', true); return; }
          if (!confirm('Remove channel ' + (ch.number || '') + ' ' + (ch.name || ch.id) + '? (Takes effect when you Save station.)')) return;
          st.channels = st.channels.filter(function(c){ return c.id !== ch.id; });
          if (liveSet.channelId === ch.id) liveSet.channelId = st.channels[0].id;
          liveDirty();
          paintLiveChannels(host);
        }})
      ]));
      LIVE_KINDS.forEach(function(k){ card.appendChild(section(k)); });
      host.appendChild(card);
    });
  }

  function liveCurrentChannel(){
    var st = liveSet.station;
    if (!st) return null;
    for (var i = 0; i < st.channels.length; i++) if (st.channels[i].id === liveSet.channelId) return st.channels[i];
    return st.channels[0] || null;
  }

  function liveChannelSelect(onChange){
    var st = liveSet.station;
    var opts = (st.channels || []).slice().sort(function(a,b){ return (Number(a.number)||0)-(Number(b.number)||0); }).map(function(c){
      return h('option', { value: c.id, text: (c.number || '?') + ' \u00b7 ' + (c.name || c.id) + (c.enabled === false ? ' (off air)' : ''), selected: c.id === liveSet.channelId });
    });
    return h('select', { 'aria-label': 'Channel', onChange: function(e){ liveSet.channelId = e.target.value; if (onChange) onChange(); } }, opts);
  }

  /* ---------------- Relay media library ---------------- */
  function liveResolveMediaUrl(url){
    if (!url) return '';
    url = String(url);
    if (/^https?:\/\//i.test(url) || url.indexOf('blob:') === 0) return url;
    if (/^api\.php\?action=/.test(url)) return liveUrl(url);
    if (url.charAt(0) === '/') return url;
    var base = String((liveSet.station && liveSet.station.mediaBaseUrl) || '').replace(/\/$/, '');
    return base ? (base + '/' + url) : liveUrl(url);
  }
  function liveEmptyItem(kind){
    return {
      id: liveUid(kind === 'episode' ? 'ep' : kind === 'movie' ? 'mov' : kind === 'commercial' ? 'ad' : 'bmp'),
      kind: kind || 'movie', title: '', subtitle: '', series: '',
      season: kind === 'episode' ? 1 : null, episode: kind === 'episode' ? 1 : null,
      year: null, durationMs: 0, url: '', notes: ''
    };
  }
  function liveStripMedia(ids){
    var drop = {}; ids.forEach(function(id){ drop[id] = true; });
    liveSet.station.channels.forEach(function(ch){
      liveEnsureChannel(ch);
      LIVE_KINDS.forEach(function(k){ ch.libraryIds[k] = ch.libraryIds[k].filter(function(x){ return !drop[x]; }); });
      ch.playlist = ch.playlist.filter(function(x){ return !drop[x]; });
      (ch.rollingSchedule.slots || []).forEach(function(sl){ if (Array.isArray(sl.mediaIds)) sl.mediaIds = sl.mediaIds.filter(function(x){ return !drop[x]; }); });
    });
  }
  function liveRemoveMedia(ids){
    var drop = {}; ids.forEach(function(id){ drop[id] = true; });
    liveSet.station.media = liveSet.station.media.filter(function(m){ return !drop[m.id]; });
    liveStripMedia(ids);
    if (liveSet.editor && drop[liveSet.editor.item.id]) liveSet.editor = null;
    liveMediaChanged();
    liveDirty();
    liveSetMsg(livePlural(ids.length, 'item') + ' removed from the Relay library. Save station to keep.');
  }
  function liveAttachToAll(item){
    liveSet.station.channels.forEach(function(ch){
      liveEnsureChannel(ch);
      if (ch.libraryIds[item.kind].indexOf(item.id) < 0) ch.libraryIds[item.kind].push(item.id);
    });
  }
  function liveMoveKind(item, prevKind){
    if (prevKind === item.kind) return;
    liveSet.station.channels.forEach(function(ch){
      liveEnsureChannel(ch);
      LIVE_KINDS.forEach(function(k){ ch.libraryIds[k] = ch.libraryIds[k].filter(function(x){ return x !== item.id; }); });
      if (ch.libraryIds[item.kind].indexOf(item.id) < 0) ch.libraryIds[item.kind].push(item.id);
      if (item.kind !== 'movie' && item.kind !== 'episode') ch.playlist = ch.playlist.filter(function(x){ return x !== item.id; });
    });
  }
  function liveMediaByCatalogue(cid){
    var media = liveSet.station.media;
    for (var i = 0; i < media.length; i++) if (media[i].catalogueId === cid) return media[i];
    return null;
  }
  /* per-user saved media-format filter for Add from server library */
  function liveCatFormatsPref(){
    var p = (state.user && state.user.prefs) || {};
    return Array.isArray(p.live_cat_formats) ? p.live_cat_formats.slice() : [];
  }
  function liveCatSaveFormats(list){
    if (state.user) { state.user.prefs = state.user.prefs || {}; state.user.prefs.live_cat_formats = list.slice(); }
    api('/me/prefs', { method:'POST', body:{ live_cat_formats: list } }).catch(function(){});
  }
  function liveCatalogueToMedia(item){
    var tv = item.type === 'tv' || item.type === 'anime' || (item.show && item.season != null && item.season !== '');
    var entry = {
      id: liveUid(tv ? 'ep' : 'mov'),
      kind: tv ? 'episode' : 'movie',
      title: (tv ? (item.episode_title || item.title) : item.title) || 'Untitled',
      subtitle: '',
      series: tv ? (item.show || '') : '',
      season: tv && item.season != null && item.season !== '' ? Number(item.season) : null,
      episode: tv && item.episode != null && item.episode !== '' ? Number(item.episode) : null,
      year: item.year == null || item.year === '' ? null : Number(item.year),
      durationMs: item.durationMs || 0,
      url: item.url || (BASE + 'api/live/file?id=' + encodeURIComponent(item.id)),
      notes: 'Vaultcast' + (item.section ? ' \u00b7 ' + item.section : '') + (item.rel ? ' \u00b7 ' + item.rel : ''),
      catalogueId: item.id
    };
    if (tv && item.episode_title) entry.episode_title = item.episode_title;
    return liveScrubMedia(entry);
  }
  function liveCatalogueLabel(x){
    if (x.group === 'show') {
      return (x.show || x.title || 'Untitled show') + ' \u00b7 ' + livePlural(x.episode_count || 0, 'episode') + (x.season_count ? ' \u00b7 ' + livePlural(x.season_count, 'season') : '');
    }
    if (x.show) {
      var se = (x.season != null && x.season !== '' && x.episode != null && x.episode !== '') ? ('S' + livePad2(x.season) + 'E' + livePad2(x.episode)) : '';
      var ep = liveFixText(x.episode_title || '');
      return [liveFixText(x.show), se, ep && ep !== x.show ? ep : ''].filter(Boolean).join(' \u00b7 ');
    }
    return (x.title || 'Untitled') + (x.year ? ' (' + x.year + ')' : '');
  }
  function liveFillDurations(cids, onTick){
    var queue = cids.slice(), active = 0;
    liveSet.durPending += queue.length;
    liveSyncDirty();
    function next(){
      while (active < 3 && queue.length) {
        active++;
        (function(cid){
          liveApi('catalogue', 'id=' + encodeURIComponent(cid)).then(function(body){
            if (body && body.ok && body.item && body.item.durationMs > 0 && liveSet.station) {
              liveSet.station.media.forEach(function(m){ if (m.catalogueId === cid) m.durationMs = body.item.durationMs; });
            }
          }).catch(function(){}).then(function(){
            active--; liveSet.durPending = Math.max(0, liveSet.durPending - 1);
            liveSyncDirty();
            if (onTick) onTick(liveSet.durPending === 0);
            next();
          });
        })(queue.shift());
      }
    }
    next();
  }

  function paintLiveLibrary(host){
    var st = liveSet.station;
    var cat = liveSet.cat;
    host.innerHTML = '';
    var listHost = h('div', { className: 'live-lib-list' });
    var catHost = h('div');
    var edHost = h('div');
    var countEl = h('p', { className: 'muted' });
    var catResults = null;

    function counts(){
      var c = { movie: 0, episode: 0, commercial: 0, bumper: 0 };
      st.media.forEach(function(m){ if (c[m.kind] != null) c[m.kind]++; });
      countEl.textContent = livePlural(st.media.length, 'item') + ': ' + livePlural(c.movie, 'movie') + ' \u00b7 ' + livePlural(c.episode, 'episode') + ' \u00b7 ' + livePlural(c.commercial, 'commercial') + ' \u00b7 ' + livePlural(c.bumper, 'bumper');
    }

    host.appendChild(h('div', { className: 'settings-card' }, [
      h('div', { className: 'row-actions', style: 'justify-content:space-between;flex-wrap:wrap' }, [
        h('h3', { text: 'Media library', style: 'margin:0' }),
        h('div', { className: 'row-actions' }, [
          h('button', { className: 'btn', type: 'button', text: 'Add from server library', onClick: function(){
            cat.open = true; cat.page = 1; paintCat(); loadCat();
            setTimeout(function(){ var q = document.getElementById('live-cat-q'); if (q) q.focus(); }, 0);
          }}),
          h('button', { className: 'btn ghost', type: 'button', text: 'Add media by URL', onClick: function(){
            liveSet.editor = { isNew: true, prevKind: 'movie', item: liveEmptyItem('movie') }; paintEditor();
          }})
        ])
      ]),
      countEl,
      h('p', { className: 'muted', text: 'Items added from the server library start off every channel \u2014 tick them under Relay. Items added by URL are attached to every channel. Changes apply when you Save station.' })
    ]));
    host.appendChild(catHost);
    host.appendChild(edHost);

    /* --- server catalogue search --- */
    function loadCat(){
      var req = ++cat.req;
      cat.busy = true; cat.error = '';
      paintCatResults();
      if (cat.formats === null) cat.formats = liveCatFormatsPref();
      liveApi('catalogue', 'page=' + cat.page + '&per=' + cat.per + '&q=' + encodeURIComponent(cat.q) + '&section=' + encodeURIComponent(cat.section) + '&formats=' + encodeURIComponent(cat.formats.join(','))).then(function(body){
        if (req !== cat.req) return;
        cat.busy = false;
        if (!body || !body.ok) { cat.error = (body && body.error) || 'Catalogue search failed'; cat.hits = []; cat.total = 0; cat.hasMore = false; paintCatResults(); return; }
        cat.hits = body.items || [];
        cat.total = body.total || 0;
        cat.hasMore = !!body.has_more;
        if (body.per) cat.per = body.per;
        var hadSections = cat.sections.length;
        if (body.sections && body.sections.length) cat.sections = body.sections;
        var prevFormats = cat.availFormats.map(function(f){ return f.ext; }).join(',');
        cat.availFormats = body.formats || [];
        var formatsChanged = prevFormats !== cat.availFormats.map(function(f){ return f.ext; }).join(',');
        if ((!hadSections && cat.sections.length) || formatsChanged) paintCat(); else paintCatResults();
      }).catch(function(e){
        if (req !== cat.req) return;
        cat.busy = false; cat.error = String(e.message || e); paintCatResults();
      });
    }
    function paintCat(){
      catHost.innerHTML = '';
      catResults = null;
      if (!cat.open) return;
      catResults = h('div', { className: 'live-cat-results' });
      catHost.appendChild(h('div', { className: 'settings-card live-cat' }, [
        h('div', { className: 'row-actions', style: 'justify-content:space-between;flex-wrap:wrap' }, [
          h('h3', { text: 'Add from server library', style: 'margin:0' }),
          h('button', { className: 'btn ghost', type: 'button', text: 'Close', onClick: function(){ cat.open = false; paintCat(); } })
        ]),
        h('p', { className: 'muted', text: 'Search the Vaultcast video catalogue. Movies add one title; TV and anime are listed by show \u2014 Add show imports every episode (durations are read from the server).' }),
        h('div', { className: 'live-filter-bar' }, [
          h('input', { id: 'live-cat-q', type: 'search', className: 'live-filter', value: cat.q, placeholder: 'Search movies and shows\u2026', 'aria-label': 'Search catalogue',
            onInput: function(e){ cat.q = e.target.value; cat.page = 1; clearTimeout(cat.timer); cat.timer = setTimeout(loadCat, 250); } }),
          h('select', { 'aria-label': 'Library section', onChange: function(e){ cat.section = e.target.value; cat.page = 1; loadCat(); } },
            [h('option', { value: '', text: 'All video', selected: !cat.section })].concat(cat.sections.map(function(s){
              return h('option', { value: s.id, text: s.label || s.id, selected: cat.section === s.id });
            }))),
          liveCatFormatChips()
        ]),
        catResults
      ]));
      paintCatResults();
    }
    /* media-format filter: chips for the extensions actually present; combines with section + search */
    function liveCatFormatChips(){
      var sel = cat.formats || [];
      var avail = cat.availFormats.slice();
      sel.forEach(function(x){ if (!avail.some(function(f){ return f.ext === x; })) avail.push({ ext: x, count: 0 }); });
      if (!avail.length) return null;
      function set(list){
        cat.formats = list.slice().sort(); cat.page = 1;
        liveCatSaveFormats(cat.formats);
        paintCat(); loadCat();
      }
      return h('div', { className: 'live-format-chips', role: 'group', 'aria-label': 'Media format' }, [
        h('button', { type: 'button', className: 'sys-chip nsm-chip' + (!sel.length ? ' nsm-accent' : ''), 'aria-pressed': String(!sel.length), text: 'All formats', onClick: function(){ set([]); } })
      ].concat(avail.map(function(f){
        var on = sel.indexOf(f.ext) >= 0;
        return h('button', { type: 'button', className: 'sys-chip nsm-chip' + (on ? ' nsm-accent' : ''), 'aria-pressed': String(on), title: f.count + ' file(s)',
          text: f.ext.toUpperCase() + (f.count ? ' ' + f.count : ''),
          onClick: function(){ set(on ? sel.filter(function(x){ return x !== f.ext; }) : sel.concat([f.ext])); } });
      })));
    }
    function paintCatResults(){
      if (!catResults) return;
      catResults.innerHTML = '';
      if (cat.busy) { catResults.appendChild(h('p', { className: 'muted', text: 'Searching catalogue\u2026' })); return; }
      if (cat.error) catResults.appendChild(h('p', { className: 'bad', text: cat.error }));
      if (!cat.hits.length && !cat.error) catResults.appendChild(h('p', { className: 'muted', text: cat.q ? 'No catalogue matches.' : 'No video in the catalogue yet.' }));
      cat.hits.forEach(function(x){
        var meta = (x.section || '') + (x.type ? ' \u00b7 ' + x.type : '');
        var inLib = false;
        if (x.group === 'show') {
          var showKey = String(x.show || x.title || '').toLowerCase();
          var have = st.media.filter(function(m){ return m.kind === 'episode' && liveSeriesName(m).toLowerCase() === showKey; }).length;
          if (have) meta += ' \u00b7 ' + have + ' already in Relay';
          inLib = have > 0 && have >= (x.episode_count || 0);
        } else {
          if (x.ext) meta += ' \u00b7 ' + x.ext;
          if (x.needs_convert) meta += ' \u00b7 may not DirectPlay';
          if (x.rel) meta += ' \u00b7 ' + x.rel;
          inLib = !!liveMediaByCatalogue(x.id);
        }
        catResults.appendChild(h('div', { className: 'live-plist-row' }, [
          h('div', { className: 'grow' }, [ h('strong', { text: liveCatalogueLabel(x) }), h('div', { className: 'muted', text: meta }) ]),
          inLib ? h('span', { className: 'muted', text: 'In library' }) :
          h('button', { className: 'btn', type: 'button', text: x.group === 'show' ? 'Add show' : 'Add', onClick: function(e){
            e.currentTarget.disabled = true;
            if (x.group === 'show') addShow(x.show || x.title || ''); else addOne(x.id);
          }})
        ]));
      });
      if (cat.total) {
        var from = (cat.page - 1) * cat.per + 1;
        var to = Math.min(cat.total, (cat.page - 1) * cat.per + cat.hits.length);
        catResults.appendChild(h('div', { className: 'row-actions', style: 'margin-top:10px' }, [
          h('span', { className: 'muted', text: from + '\u2013' + to + ' of ' + cat.total }),
          h('button', { className: 'btn ghost', type: 'button', text: 'Prev', disabled: cat.page <= 1, onClick: function(){ if (cat.page > 1) { cat.page--; loadCat(); } } }),
          h('button', { className: 'btn ghost', type: 'button', text: 'Next', disabled: !cat.hasMore, onClick: function(){ if (cat.hasMore) { cat.page++; loadCat(); } } })
        ]));
      }
    }
    function addOne(cid){
      var have = liveMediaByCatalogue(cid);
      if (have) { liveSetMsg('Already in the library: ' + liveGuideTitle(have)); return; }
      liveSetMsg('Reading catalogue item\u2026');
      liveApi('catalogue', 'id=' + encodeURIComponent(cid)).then(function(body){
        if (!body || !body.ok || !body.item) { liveSetMsg((body && body.error) || 'Could not read that catalogue item', true); paintCatResults(); return; }
        var entry = liveCatalogueToMedia(body.item);
        if (!(entry.durationMs > 0) || !entry.url) {
          liveSet.editor = { isNew: true, prevKind: entry.kind, item: entry, fromCatalogue: true };
          liveSetMsg('Duration unknown \u2014 set it (or Read from file), then Save media.', true);
          paintEditor(); paintCatResults();
          return;
        }
        st.media.push(entry);
        liveMediaChanged(); liveDirty();
        liveSetMsg(liveGuideTitle(entry) + ' added (off every channel). Tick it under Relay, then Save station.');
        counts(); paintList(); paintCatResults();
      }).catch(function(e){ liveSetMsg(String(e.message || e), true); paintCatResults(); });
    }
    function addShow(showName){
      if (!showName) return;
      liveSetMsg('Importing ' + showName + '\u2026');
      liveApi('catalogue', 'show=' + encodeURIComponent(showName) + '&formats=' + encodeURIComponent((cat.formats || []).join(','))).then(function(body){
        if (!body || !body.ok || !body.items || !body.items.length) { liveSetMsg((body && body.error) || 'Could not read that show', true); paintCatResults(); return; }
        var added = 0, skipped = 0, need = [];
        body.items.forEach(function(x){
          if (liveMediaByCatalogue(x.id)) { skipped++; return; }
          var entry = liveCatalogueToMedia(x);
          if (!(entry.durationMs > 0)) { entry.durationMs = 0; need.push(x.id); }
          st.media.push(entry);
          added++;
        });
        liveMediaChanged();
        if (added) liveDirty();
        liveSetMsg((added ? (showName + ': ' + livePlural(added, 'episode') + ' added (off every channel)') : 'Nothing new') + (skipped ? ('; ' + skipped + ' already in library') : '') + '. Tick the show under Relay, then Save station.');
        counts(); paintList(); paintCatResults();
        if (need.length) {
          var last = 0;
          liveFillDurations(need, function(done){
            var now = Date.now();
            if (done || now - last > 1000) { last = now; if (document.body.contains(listHost)) paintList(); }
            if (done) liveSetMsg(showName + ': all durations read. Save station to keep.');
          });
        }
      }).catch(function(e){ liveSetMsg(String(e.message || e), true); paintCatResults(); });
    }

    /* --- media editor (add by URL / edit) --- */
    function readEditor(){
      var ed = liveSet.editor; if (!ed) return;
      var item = ed.item;
      function v(id){ var n = document.getElementById(id); return n ? n.value : null; }
      var k = v('le-kind'); if (k) item.kind = k;
      if (v('le-url') != null) item.url = v('le-url').trim();
      if (v('le-title') != null) item.title = v('le-title');
      if (v('le-sub') != null) item.subtitle = v('le-sub');
      if (v('le-notes') != null) item.notes = v('le-notes');
      var y = v('le-year'); if (y != null) item.year = y === '' ? null : Number(y);
      var d = v('le-dur'); if (d != null) item.durationMs = d === '' ? 0 : Math.round(Number(d) * 1000);
      if (item.kind === 'episode') {
        if (v('le-series') != null) item.series = v('le-series');
        var s = v('le-season'), e = v('le-ep');
        if (s != null) item.season = s === '' ? null : Number(s);
        if (e != null) item.episode = e === '' ? null : Number(e);
        if (v('le-title') != null) item.episode_title = item.title;
      } else {
        item.series = ''; item.season = null; item.episode = null;
        delete item.episode_title;
      }
    }
    function paintEditor(){
      edHost.innerHTML = '';
      var ed = liveSet.editor;
      if (!ed) return;
      var item = ed.item;
      function fld(label, ctl){ return h('label', {}, [label, ctl]); }
      var durIn = h('input', { id: 'le-dur', type: 'number', min: 1, value: item.durationMs ? Math.round(item.durationMs / 1000) : '' });
      edHost.appendChild(h('div', { className: 'settings-card stack live-editor' }, [
        h('h3', { text: ed.isNew ? 'Add media' : 'Edit media' }),
        h('div', { className: 'live-editor-grid' }, [
          fld('Kind', h('select', { id: 'le-kind', onChange: function(){ readEditor(); paintEditor(); } }, LIVE_KINDS.map(function(k){
            return h('option', { value: k, text: LIVE_KIND_LABEL[k], selected: k === item.kind });
          }))),
          fld('File URL or path', h('input', { id: 'le-url', value: item.url || '', placeholder: 'media/film.mp4 or https://media.example/movies/film.mp4' })),
          fld(item.kind === 'episode' ? 'Episode title' : 'Title (TV guide)', h('input', { id: 'le-title', value: item.kind === 'episode' ? liveEpisodeName(item) : liveFixText(item.title) })),
          fld('Subtitle', h('input', { id: 'le-sub', value: liveFixText(item.subtitle) })),
          item.kind === 'episode' ? fld('Series', h('input', { id: 'le-series', value: liveFixText(item.series) })) : null,
          item.kind === 'episode' ? fld('Season', h('input', { id: 'le-season', type: 'number', min: 0, value: item.season == null ? '' : item.season })) : null,
          item.kind === 'episode' ? fld('Episode', h('input', { id: 'le-ep', type: 'number', min: 0, value: item.episode == null ? '' : item.episode })) : null,
          fld('Year', h('input', { id: 'le-year', type: 'number', value: item.year == null ? '' : item.year })),
          fld('Duration (seconds)', h('div', { className: 'row-actions' }, [ durIn,
            h('button', { className: 'btn ghost', type: 'button', text: 'Read from file', onClick: function(){
              readEditor();
              var url = liveResolveMediaUrl(item.url);
              if (!url) { liveSetMsg('Enter a file URL first.', true); return; }
              liveSetMsg('Reading duration\u2026');
              var vid = document.createElement('video');
              vid.preload = 'metadata';
              vid.muted = true;
              vid.onloadedmetadata = function(){
                if (!isFinite(vid.duration) || vid.duration <= 0) { liveSetMsg('Could not read duration.', true); return; }
                item.durationMs = Math.round(vid.duration * 1000);
                durIn.value = Math.round(vid.duration);
                liveSetMsg('Duration ' + liveFmtDur(item.durationMs));
                vid.removeAttribute('src'); try { vid.load(); } catch (eL) {}
              };
              vid.onerror = function(){ liveSetMsg('Could not read duration from ' + url, true); };
              vid.src = url;
            }})
          ])),
          fld('Notes', h('textarea', { id: 'le-notes', rows: 2, text: item.notes || '' }))
        ]),
        h('div', { className: 'row-actions' }, [
          h('button', { className: 'btn primary', type: 'button', text: 'Save media', onClick: function(){
            readEditor();
            liveScrubMedia(item);
            var titleOk = (item.title && String(item.title).trim()) || (item.kind === 'episode' && item.series && String(item.series).trim());
            if (!titleOk || !item.url || !String(item.url).trim() || !(item.durationMs > 0)) {
              liveSetMsg(item.kind === 'episode' ? 'Need a series (or title), URL, and duration.' : 'Need a title, URL, and duration.', true);
              return;
            }
            if (ed.isNew) {
              st.media.push(item);
              if (!ed.fromCatalogue) liveAttachToAll(item);
            } else {
              st.media = st.media.map(function(m){ return m.id === item.id ? item : m; });
              liveMoveKind(item, ed.prevKind);
            }
            liveMediaChanged();
            liveSet.editor = null;
            liveDirty();
            liveSetMsg('Media ' + (ed.isNew ? 'added' : 'updated') + ': ' + liveGuideTitle(item) + '. Save station to rebuild lineups.');
            paintEditor(); counts(); paintList(); paintCatResults();
          }}),
          h('button', { className: 'btn ghost', type: 'button', text: 'Cancel', onClick: function(){ liveSet.editor = null; paintEditor(); } })
        ])
      ]));
      setTimeout(function(){ try { edHost.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (eS) {} }, 0);
    }
    function edit(m){
      var copy = {}; Object.keys(m).forEach(function(k){ copy[k] = m[k]; });
      liveSet.editor = { isNew: false, prevKind: m.kind, item: liveScrubMedia(copy) };
      paintEditor();
    }

    /* --- library list: flat items + show -> season -> episode tree --- */
    var kindSel = h('select', { 'aria-label': 'Kind', onChange: function(e){ liveSet.lib.kind = e.target.value; paintList(); } },
      [h('option', { value: 'all', text: 'All kinds', selected: liveSet.lib.kind === 'all' })].concat(LIVE_KINDS.map(function(k){
        return h('option', { value: k, text: LIVE_KIND_PLURAL[k], selected: liveSet.lib.kind === k });
      })));
    host.appendChild(h('div', { className: 'settings-card' }, [
      h('div', { className: 'live-filter-bar' }, [ liveSearchInput('lib', 'Search titles, shows, episodes, files\u2026', function(){ paintList(); }), kindSel ]),
      listHost
    ]));

    function itemRow(m, isEp){
      return h('div', { className: 'live-plist-row' + (isEp ? ' live-tree-ep' : '') }, [
        h('div', { className: 'grow' }, [
          h('strong', { text: isEp ? liveEpLabel(m) : liveGuideTitle(m) }),
          h('div', { className: 'muted file', text: [LIVE_KIND_LABEL[m.kind] || m.kind, m.durationMs ? liveFmtDur(m.durationMs) : 'no duration', m.subtitle ? liveFixText(m.subtitle) : '', m.url || ''].filter(Boolean).join(' \u00b7 ') })
        ]),
        h('button', { className: 'btn ghost sm', type: 'button', text: 'Edit', onClick: function(){ edit(m); } }),
        h('button', { className: 'btn ghost sm', type: 'button', text: 'Remove', onClick: function(){
          if (!confirm('Remove ' + liveGuideTitle(m) + ' from the Relay library (and every channel / playlist)?')) return;
          liveRemoveMedia([m.id]); counts(); paintList();
        }})
      ]);
    }
    function paintList(){
      listHost.innerHTML = '';
      var q = String(liveSet.q.lib || '').trim().toLowerCase();
      var kind = liveSet.lib.kind;
      var flat = st.media.filter(function(m){ return m.kind !== 'episode' && (kind === 'all' || m.kind === kind) && (!q || liveBlob(m).indexOf(q) >= 0); })
        .sort(function(a, b){ return liveGuideTitle(a).localeCompare(liveGuideTitle(b)); });
      var shows = (kind === 'all' || kind === 'episode') ? liveFilterShows(liveShowIndex(st.media.filter(function(m){ return m.kind === 'episode'; })), q) : [];
      if (!flat.length && !shows.length) {
        listHost.appendChild(h('p', { className: 'muted', text: st.media.length ? 'Nothing matches.' : 'The Relay library is empty \u2014 Add from server library.' }));
        return;
      }
      if (flat.length) {
        listHost.appendChild(h('h4', { className: 'live-sub', text: (kind === 'all' ? 'Movies, commercials & bumpers' : LIVE_KIND_PLURAL[kind]) + ' (' + flat.length + ')' }));
        listHost.appendChild(h('div', { className: 'live-plist' }, flat.map(function(m){ return itemRow(m, false); })));
      }
      if (shows.length) {
        listHost.appendChild(h('h4', { className: 'live-sub', text: 'TV shows (' + shows.length + ')' }));
        listHost.appendChild(liveTree({
          stateKey: 'lib',
          shows: shows,
          showTail: function(g){ return [
            h('span', { className: 'muted', text: liveFmtDur(liveSumMs(g.eps)) }),
            h('button', { className: 'btn ghost sm', type: 'button', text: 'Remove show', onClick: function(){
              if (!confirm('Remove ' + livePlural(g.eps.length, 'listed episode') + ' of ' + g.name + ' from the Relay library (and every channel / playlist)?')) return;
              liveRemoveMedia(liveIds(g.eps)); counts(); paintList();
            }})
          ]; },
          seasonTail: function(g, s){ return [
            h('button', { className: 'btn ghost sm', type: 'button', text: 'Remove season', onClick: function(){
              if (!confirm('Remove ' + g.name + ' ' + liveSeasonLabel(s) + ' (' + livePlural(s.eps.length, 'episode') + ') from the Relay library?')) return;
              liveRemoveMedia(liveIds(s.eps)); counts(); paintList();
            }})
          ]; },
          epRow: function(m){ return itemRow(m, true); }
        }));
      }
    }
    counts();
    paintCat();
    paintEditor();
    paintList();
  }

  /* ---------------- Playlists & programming ---------------- */
  function paintLivePlaylists(host){
    var ch = liveCurrentChannel();
    host.innerHTML = '';
    if (!ch) {
      host.appendChild(h('p', { className: 'muted', text: 'Add a Relay channel first.' }));
      return;
    }
    liveEnsureChannel(ch);
    var meterCard = h('div', { className: 'settings-card' });
    var setCard = h('div', { className: 'settings-card live-plist-card' });
    var libHost = h('div');

    function paintMeter(){
      var setMs = 0;
      ch.playlist.forEach(function(id){ var m = liveMediaById(id); if (m && (m.kind === 'movie' || m.kind === 'episode')) setMs += m.durationMs || 0; });
      var need = (ch.fillHours || 6) * 3600 * 1000;
      var pct = Math.min(100, need ? (setMs / need) * 100 : 0);
      var ok = setMs >= need;
      meterCard.innerHTML = '';
      meterCard.appendChild(h('div', { className: 'row-actions', style: 'justify-content:space-between;flex-wrap:wrap' }, [
        h('h3', { text: 'Channel', style: 'margin:0' }),
        h('div', { className: 'row-actions' }, [ liveChannelSelect(function(){ paintLivePlaylists(host); }) ])
      ]));
      meterCard.appendChild(h('div', { className: 'live-meter' + (ok ? ' ok' : '') }, [ h('span', { style: 'width:' + pct + '%' }) ]));
      meterCard.appendChild(h('p', { className: 'muted', text: liveFmtDur(setMs) + ' set / ' + (ch.fillHours || 6) + 'h fill window \u2014 ' +
        (ok ? 'at or over the fill window: this list loops with commercials.' : 'under the fill window: remaining airtime is generated at random from the channel library.') }));
    }
    function paintSet(){
      setCard.innerHTML = '';
      setCard.appendChild(h('div', { className: 'row-actions', style: 'justify-content:space-between;flex-wrap:wrap' }, [
        h('h3', { text: 'Set playlist (' + ch.playlist.length + ')', style: 'margin:0' }),
        ch.playlist.length ? h('button', { className: 'btn ghost sm', type: 'button', text: 'Clear playlist', onClick: function(){
          if (!confirm('Clear all ' + ch.playlist.length + ' set programs on this channel?')) return;
          ch.playlist = []; liveDirty(); paintSet(); paintMeter();
        }}) : null
      ]));
      if (!ch.playlist.length) { setCard.appendChild(h('p', { className: 'muted', text: 'Empty \u2014 this channel is fully generated from its library.' })); return; }
      setCard.appendChild(h('div', { className: 'live-plist live-plist-scroll' }, ch.playlist.map(function(id, index){
        var m = liveMediaById(id);
        function mv(to){
          if (to < 0 || to >= ch.playlist.length) return;
          var x = ch.playlist.splice(index, 1)[0]; ch.playlist.splice(to, 0, x);
          liveDirty(); paintSet();
        }
        return h('div', { className: 'live-plist-row' }, [
          h('span', { className: 'muted live-plist-n', text: String(index + 1) }),
          h('div', { className: 'grow' }, [
            h('strong', { text: m ? liveGuideTitle(m) : (id + ' (missing from library)') }),
            m ? h('div', { className: 'muted', text: liveFmtDur(m.durationMs) }) : null
          ]),
          h('button', { className: 'btn ghost sm', type: 'button', text: 'Top', title: 'Move to top', disabled: index === 0, onClick: function(){ mv(0); } }),
          h('button', { className: 'btn ghost sm', type: 'button', text: 'Up', disabled: index === 0, onClick: function(){ mv(index - 1); } }),
          h('button', { className: 'btn ghost sm', type: 'button', text: 'Down', disabled: index === ch.playlist.length - 1, onClick: function(){ mv(index + 1); } }),
          h('button', { className: 'btn ghost sm', type: 'button', text: 'Remove', onClick: function(){ ch.playlist.splice(index, 1); liveDirty(); paintSet(); paintMeter(); } })
        ]);
      })));
    }
    function add(ids){
      if (!ids.length) return;
      ids.forEach(function(id){ ch.playlist.push(id); });
      liveDirty(); paintSet(); paintMeter();
      liveSetMsg(livePlural(ids.length, 'program') + ' added to the set playlist of ' + (ch.name || ch.id) + '.');
    }

    var movies = ch.libraryIds.movie.map(liveMediaById).filter(function(m){ return m && m.kind === 'movie'; })
      .sort(function(a, b){ return liveGuideTitle(a).localeCompare(liveGuideTitle(b)); });
    var shows = liveShowIndex(ch.libraryIds.episode.map(liveMediaById).filter(function(m){ return m && m.kind === 'episode'; }));
    var qKey = 'pl:' + ch.id;
    function paintLib(){
      libHost.innerHTML = '';
      var q = String(liveSet.q[qKey] || '').trim().toLowerCase();
      var mvs = q ? movies.filter(function(m){ return liveBlob(m).indexOf(q) >= 0; }) : movies;
      var sh = liveFilterShows(shows, q);
      if (!movies.length && !shows.length) { libHost.appendChild(h('p', { className: 'muted', text: 'No movies or TV ticked on this channel \u2014 tick them under Relay.' })); return; }
      if (movies.length) {
        libHost.appendChild(h('h4', { className: 'live-sub', text: 'Movies (' + mvs.length + (mvs.length !== movies.length ? ' of ' + movies.length : '') + ')' }));
        libHost.appendChild(mvs.length ? h('div', { className: 'live-plist' }, mvs.map(function(m){
          return h('div', { className: 'live-plist-row' }, [
            h('div', { className: 'grow' }, [ h('strong', { text: liveGuideTitle(m) }), h('div', { className: 'muted', text: liveFmtDur(m.durationMs) }) ]),
            h('button', { className: 'btn ghost sm', type: 'button', text: 'Add', onClick: function(){ add([m.id]); } })
          ]);
        })) : h('p', { className: 'muted', text: 'No movie matches.' }));
      }
      if (shows.length) {
        libHost.appendChild(h('h4', { className: 'live-sub', text: 'TV (' + sh.length + (sh.length !== shows.length ? ' of ' + shows.length : '') + ' shows)' }));
        libHost.appendChild(liveTree({
          stateKey: 'pl:' + ch.id,
          shows: sh,
          empty: 'No show or episode matches.',
          showTail: function(g){ return [ h('button', { className: 'btn ghost sm', type: 'button', text: 'Add all', title: 'Append every listed episode of ' + g.name + ' in order', onClick: function(){ add(liveIds(g.eps)); } }) ]; },
          seasonTail: function(g, s){ return [ h('button', { className: 'btn ghost sm', type: 'button', text: 'Add season', onClick: function(){ add(liveIds(s.eps)); } }) ]; },
          epRow: function(m){
            return h('div', { className: 'live-plist-row live-tree-ep' }, [
              h('div', { className: 'grow' }, [ h('span', { text: liveEpLabel(m) }), h('span', { className: 'muted', text: ' \u00b7 ' + liveFmtDur(m.durationMs) }) ]),
              h('button', { className: 'btn ghost sm', type: 'button', text: 'Add', onClick: function(){ add([m.id]); } })
            ]);
          }
        }));
      }
    }

    host.appendChild(meterCard);
    host.appendChild(h('div', { className: 'live-split' }, [
      h('div', { className: 'settings-card' }, [
        h('h3', { text: 'Channel library \u2192 set playlist' }),
        h('div', { className: 'live-filter-bar' }, [ liveSearchInput(qKey, 'Search this channel\u2019s movies, shows, episodes\u2026', paintLib) ]),
        libHost
      ]),
      setCard
    ]));
    paintMeter(); paintSet(); paintLib();

    host.appendChild(h('div', { className: 'settings-card stack' }, [
      h('h3', { text: 'Programming rules' }),
      h('label', {}, ['Fill if set content is under (hours)', h('input', { type: 'number', min: 1, max: 24, value: ch.fillHours, onChange: function(e){ ch.fillHours = Math.max(1, Math.min(24, Number(e.target.value) || 6)); liveDirty(); paintMeter(); } })]),
      h('label', {}, ['Commercial break every (minutes)', h('input', { type: 'number', min: 0, value: Math.round((ch.commercialEveryMs||0)/60000), onChange: function(e){ ch.commercialEveryMs = Math.max(0, Number(e.target.value) || 0) * 60000; liveDirty(); } })]),
      h('label', {}, ['Commercials per break', h('input', { type: 'number', min: 1, max: 8, value: ch.commercialsPerBreak, onChange: function(e){ ch.commercialsPerBreak = Math.max(1, Math.min(8, Number(e.target.value) || 2)); liveDirty(); } })]),
      h('label', { className: 'check-label' }, [ h('input', { type: 'checkbox', checked: !!ch.bumperBefore, onChange: function(e){ ch.bumperBefore = e.target.checked; liveDirty(); } }), ' Play a bumper before each program' ]),
      h('label', {}, ['Random seed', h('input', { value: ch.seed || '', onInput: function(e){ ch.seed = e.target.value; liveDirty(); } })]),
      h('p', { className: 'muted', text: 'The same seed always builds the same fill. Changing seed, playlist or libraries rebuilds the loop on save.' })
    ]));
  }

  /* ---------------- Rolling schedule ---------------- */
  /* the server matches slot.series against media.series (trimmed, case-insensitive) */
  function liveSeriesValue(g){ return String((g.eps[0] && g.eps[0].series) || '').trim() || g.name; }
  function liveSameSeries(a, b){ return String(a || '').trim().toLowerCase() === String(b || '').trim().toLowerCase(); }
  function paintLiveSchedule(host){
    var ch = liveCurrentChannel();
    host.innerHTML = '';
    if (!ch) {
      host.appendChild(h('p', { className: 'muted', text: 'Add a Relay channel first.' }));
      return;
    }
    liveEnsureChannel(ch);
    var rs = ch.rollingSchedule;

    host.appendChild(h('div', { className: 'settings-card' }, [
      h('div', { className: 'row-actions', style: 'justify-content:space-between;flex-wrap:wrap' }, [
        h('h3', { text: '24-hour rolling schedule', style: 'margin:0' }),
        h('div', { className: 'row-actions' }, [ liveChannelSelect(function(){ paintLiveSchedule(host); }) ])
      ]),
      h('p', { className: 'muted', text: 'Wall-clock slots overlay the PseudoTV loop in the Watch Live guide. Rotation is deterministic from the calendar day (optional start date) \u2014 no NFO rewrites, no daily cursor files.' }),
      h('label', { className: 'check-label' }, [
        h('input', { type: 'checkbox', checked: !!rs.enabled, onChange: function(e){ rs.enabled = e.target.checked; liveDirty(); paintLiveSchedule(host); } }),
        ' Enable rolling schedule on this channel'
      ])
    ]));

    if (!rs.enabled) {
      host.appendChild(h('p', { className: 'muted', text: 'Enable to add daily slots (e.g. 20:00 movie rotate, or next episode of a show).' }));
      return;
    }

    var allMovies = (liveSet.station.media || []).filter(function(m){ return m.kind === 'movie'; });
    var chMovies = allMovies.filter(function(m){ return ch.libraryIds.movie.indexOf(m.id) >= 0; });
    var movies = (chMovies.length ? chMovies : allMovies).slice().sort(function(a, b){ return liveGuideTitle(a).localeCompare(liveGuideTitle(b)); });
    var shows = liveShowIndex((liveSet.station.media || []).filter(function(m){ return m.kind === 'episode' && ch.libraryIds.episode.indexOf(m.id) >= 0; }));

    rs.slots.forEach(function(slot, si){
      if (!slot.id) slot.id = liveUid('rs');
      if (!Array.isArray(slot.mediaIds)) slot.mediaIds = [];
      var type = slot.type || 'movie_rotate';
      host.appendChild(h('div', { className: 'settings-card stack live-roll-slot' }, [
        h('div', { className: 'row-actions', style: 'justify-content:space-between' }, [
          h('h3', { text: 'Slot ' + (si + 1) + ' \u00b7 ' + livePad2(slot.hour != null ? slot.hour : 20) + ':' + livePad2(slot.minute != null ? slot.minute : 0), style: 'margin:0' }),
          h('button', { className: 'btn ghost', type: 'button', text: 'Remove slot', onClick: function(){
            rs.slots.splice(si, 1); liveDirty(); paintLiveSchedule(host);
          }})
        ]),
        h('div', { className: 'live-roll-time' }, [
          h('label', {}, ['Hour', h('input', { type: 'number', min: 0, max: 23, value: slot.hour != null ? slot.hour : 20, onChange: function(e){ slot.hour = Math.max(0, Math.min(23, Number(e.target.value)||0)); liveDirty(); } })]),
          h('label', {}, ['Minute', h('input', { type: 'number', min: 0, max: 59, value: slot.minute != null ? slot.minute : 0, onChange: function(e){ slot.minute = Math.max(0, Math.min(59, Number(e.target.value)||0)); liveDirty(); } })]),
          h('label', {}, ['Type', h('select', { onChange: function(e){ slot.type = e.target.value; liveDirty(); paintLiveSchedule(host); } }, [
            h('option', { value: 'movie_rotate', text: 'Movie rotate (1 per day)', selected: type === 'movie_rotate' }),
            h('option', { value: 'episode_daily', text: 'Next TV episode (+1 / day)', selected: type === 'episode_daily' })
          ])]),
          h('label', {}, ['Start date (optional)', h('input', { type: 'date', value: slot.startDate || '', onChange: function(e){ slot.startDate = e.target.value; liveDirty(); } })])
        ]),
        (type === 'episode_daily') ? h('div', {}, [
          h('label', {}, ['Series', h('select', { onChange: function(e){ slot.series = e.target.value; liveDirty(); } },
            [h('option', { value: '', text: '\u2014 choose series \u2014', selected: !slot.series })].concat(shows.map(function(g){
              return h('option', { value: liveSeriesValue(g), text: g.name + ' (' + livePlural(g.eps.length, 'episode') + ')', selected: liveSameSeries(slot.series, liveSeriesValue(g)) });
            })).concat(slot.series && !shows.some(function(g){ return liveSameSeries(slot.series, liveSeriesValue(g)); }) ? [h('option', { value: slot.series, text: slot.series + ' (not ticked on this channel)', selected: true })] : [])
          )]),
          shows.length ? null : h('p', { className: 'muted', text: 'Tick a show on this channel under Relay to schedule its episodes.' })
        ]) : h('div', {}, [
          h('p', { className: 'muted', text: (chMovies.length ? 'Movies from this channel\u2019s library.' : 'This channel has no movies ticked \u2014 listing the whole Relay library.') + ' Ticked titles rotate one per calendar day.' }),
          liveFlatChecks(movies, function(id){ return slot.mediaIds.indexOf(id) >= 0; }, function(ids, on){ liveSetApply(slot, 'mediaIds', ids, on); liveDirty(); }, 'rs:' + slot.id, null, 'No movies in the Relay media library yet.')
        ]),
        h('p', { className: 'muted', text: 'Every day at this time Relay airs the next movie in the selection, or the next episode of the chosen show.' })
      ]));
    });

    host.appendChild(h('div', { className: 'row-actions' }, [
      h('button', { className: 'btn', type: 'button', text: 'Add slot', onClick: function(){
        rs.slots.push({ id: liveUid('rs'), hour: 20, minute: 0, type: 'movie_rotate', mediaIds: [], series: '', startDate: '' });
        liveDirty(); paintLiveSchedule(host);
      }})
    ]));
  }

  /* ---------------- Station, outputs, backup ---------------- */
  function liveMergeStation(incoming){
    var st = liveSet.station;
    var mediaMap = {}, mediaOrder = [];
    st.media.forEach(function(m){ if (!mediaMap[m.id]) mediaOrder.push(m.id); mediaMap[m.id] = m; });
    incoming.media.forEach(function(m){ if (!m || !m.id) return; if (!mediaMap[m.id]) mediaOrder.push(m.id); mediaMap[m.id] = m; });
    var chMap = {}, chOrder = [];
    st.channels.forEach(function(c){ if (!chMap[c.id]) chOrder.push(c.id); chMap[c.id] = c; });
    incoming.channels.forEach(function(c){ if (!c || !c.id) return; liveEnsureChannel(c); if (!chMap[c.id]) chOrder.push(c.id); chMap[c.id] = c; });
    st.media = mediaOrder.map(function(id){ return mediaMap[id]; });
    st.channels = chOrder.map(function(id){ return chMap[id]; });
    ['name', 'tagline', 'mediaBaseUrl', 'historyDays', 'lookaheadHours'].forEach(function(k){
      if (incoming[k] != null) st[k] = incoming[k];
    });
    if (!liveCurrentChannel() && st.channels[0]) liveSet.channelId = st.channels[0].id;
    liveMediaChanged();
  }

  function paintLiveStation(host){
    var st = liveSet.station;
    host.innerHTML = '';
    host.appendChild(h('div', { className: 'settings-card stack' }, [
      h('h3', { text: 'Station' }),
      h('label', {}, ['Station name', h('input', { value: st.name || '', onInput: function(e){ st.name = e.target.value; liveDirty(); } })]),
      h('label', {}, ['Tagline', h('input', { value: st.tagline || '', onInput: function(e){ st.tagline = e.target.value; liveDirty(); } })]),
      h('label', {}, ['Media base URL', h('input', { value: st.mediaBaseUrl || '', placeholder: 'https://media.example/media', onInput: function(e){ st.mediaBaseUrl = e.target.value; liveDirty(); } })]),
      h('label', {}, ['Timeshift history (days)', h('input', { type: 'number', min: 1, max: 14, value: st.historyDays != null ? st.historyDays : 7, onChange: function(e){ st.historyDays = Math.max(1, Math.min(14, Number(e.target.value) || 7)); liveDirty(); } })]),
      h('label', {}, ['Guide lookahead (hours)', h('input', { type: 'number', min: 2, max: 24, value: st.lookaheadHours != null ? st.lookaheadHours : 12, onChange: function(e){ st.lookaheadHours = Math.max(2, Math.min(24, Number(e.target.value) || 12)); liveDirty(); } })]),
      h('p', { className: 'muted', text: 'DirectPlay reads MP4s as-is. History is how far a pause can rewind and still land on the program that was airing. Relative media paths resolve against the media base URL.' }),
      h('div', { className: 'row-actions' }, [ h('button', { className: 'btn primary', type: 'button', 'data-live-save': '1', text: 'Save station', onClick: liveSaveStation }) ])
    ]));

    /* external-player URL goes through /media/api/live/m3u (no ".php") so it works on the public HTTPS hostname too */
    var origin = (location.origin || '') + String(BASE || '/media/').replace(/\/?$/, '/') + 'api/live/';
    host.appendChild(h('div', { className: 'settings-card' }, [
      h('h3', { text: 'Player outputs' }),
      h('p', { className: 'muted', text: 'Watch in an external player: Copy stream URL, then paste it into your player \u2014 ' + PLAYER_PASTE_HINT + '. Starts at the live edge and keeps playing the schedule. Works on any device or network; no plug-ins or custom protocols. To watch in the browser instead, use Watch Live.' }),
      liveOutputRows(origin, st.channels || [])
    ]));

    host.appendChild(h('div', { className: 'settings-card' }, [
      h('h3', { text: 'Backup' }),
      h('p', { className: 'muted', text: 'Export the station as JSON. Import merges channels and media by id \u2014 existing items update, new ones are added, nothing is bulk-deleted. Review, then Save station.' }),
      h('p', { className: 'muted', text: livePlural((st.channels||[]).length, 'channel') + ' \u00b7 ' + livePlural((st.media||[]).length, 'media item') }),
      h('div', { className: 'row-actions' }, [
        h('button', { className: 'btn ghost', type: 'button', text: 'Export JSON', onClick: function(){
          var blob = new Blob([JSON.stringify(st, null, 2)], { type: 'application/json' });
          var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'relay-station.json';
          document.body.appendChild(a); a.click(); setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); }, 1000);
        }}),
        h('label', { className: 'btn ghost' }, [
          document.createTextNode('Import JSON (merge)'),
          h('input', { type: 'file', accept: 'application/json,.json', hidden: true, onChange: function(e){
            var file = e.target.files && e.target.files[0]; if (!file) return;
            var reader = new FileReader();
            reader.onload = function(){
              try {
                var parsed = JSON.parse(String(reader.result));
                if (!parsed || !Array.isArray(parsed.channels) || !Array.isArray(parsed.media)) throw new Error('Not a Relay station file');
                liveMergeStation(parsed);
                liveDirty();
                liveSetMsg('Imported ' + livePlural(parsed.channels.length, 'channel') + ' / ' + livePlural(parsed.media.length, 'media item') + ' (merged by id) \u2014 review, then Save station.');
                paintLiveStation(host);
              } catch (err) { liveSetMsg(err.message || 'Import failed', true); }
            };
            reader.readAsText(file);
          }})
        ])
      ])
    ]));
  }

  /* clipboard copy that also works on plain-http origins (navigator.clipboard needs a secure context) */
  function copyText(text){
    return new Promise(function(resolve, reject){
      function legacy(){
        try {
          var ta = document.createElement('textarea');
          ta.value = text; ta.setAttribute('readonly', '');
          ta.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;';
          document.body.appendChild(ta);
          ta.focus(); ta.select();
          try { ta.setSelectionRange(0, text.length); } catch (eR) {}
          var ok = false;
          try { ok = document.execCommand('copy'); } catch (eC) {}
          document.body.removeChild(ta);
          ok ? resolve() : reject(new Error('copy failed'));
        } catch (eL) { reject(eL); }
      }
      if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext !== false) {
        navigator.clipboard.writeText(text).then(resolve, legacy);
      } else legacy();
    });
  }
  /* Player outputs = Copy stream URL (primary). Browsers cannot launch a desktop player without a
     per-machine registered protocol or companion app, so we don't pretend: no vlc:// / intent:// / registry,
     and no navigating a tab to the .m3u (browsers just render it as text). Primary copies the network URL
     for VLC (Media -> Open Network Stream), mpv, PotPlayer, IINA, Infuse, Kodi... On touch devices with the
     Web Share API a Share button hands the URL to an app's share target. Quiet secondary: Download .m3u
     (vlc.php?dl=1 -> Content-Disposition: attachment). Works for any user / browser / location. */
  var PLAYER_PASTE_HINT = 'VLC: Media \u2192 Open Network Stream (Ctrl+N) \u00b7 PotPlayer: Ctrl+U \u00b7 mpv / IINA / Kodi: open URL';
  function canWebShare(){
    try {
      if (!navigator.share) return false;
      var coarse = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
      return coarse || /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent || '');
    } catch (eS) { return false; }
  }
  function playlistDownloadUrl(url){ return url + (url.indexOf('?') >= 0 ? '&' : '?') + 'dl=1'; }
  function copyStreamUrl(url, btn){
    return copyText(url).then(function(){
      liveSetMsg('Stream URL copied: ' + url + ' \u2014 paste it into your player. ' + PLAYER_PASTE_HINT);
      toast('Stream URL copied \u2014 in VLC: Media \u2192 Open Network Stream, paste, Play', { kind:'ok', ms: 4500 });
      if (btn) {
        var label = btn.getAttribute('data-label') || btn.textContent;
        btn.setAttribute('data-label', label);
        btn.textContent = 'Copied \u2713';
        clearTimeout(btn._t); btn._t = setTimeout(function(){ btn.textContent = label; }, 1800);
      }
    }, function(){
      liveSetMsg('Your browser blocked copying \u2014 select the URL box and copy it manually: ' + url, true);
      toast('Copy blocked by browser \u2014 select the URL box and copy it', { kind:'bad', ms: 4500 });
    });
  }
  function shareStreamUrl(url, title){
    if (!navigator.share) return copyStreamUrl(url);
    return navigator.share({ title: title || 'Live channel', url: url }).then(function(){
      toast('Sent \u2014 pick VLC or your player in the share sheet', { kind:'ok', ms: 3000 });
    }, function(err){
      if (err && err.name === 'AbortError') return;
      copyStreamUrl(url);
    });
  }
  /* Player output rows use the channel-number id (?channel=ch-1); the server resolves collisions. */
  function liveOutputRows(origin, channels){
    var box = h('div', { className: 'stack' });
    function paint(slugOf){
      box.innerHTML = '';
      channels.slice().sort(function(a,b){ return (Number(a.number)||0)-(Number(b.number)||0); }).forEach(function(ch){
        var slug = slugOf(ch);
        var label = ((ch.number || '') + ' ' + (ch.name || ch.id)).trim();
        box.appendChild(playerOutputRow(origin + 'm3u?channel=' + encodeURIComponent(slug), label, 'channel-' + (ch.number || ch.id)));
      });
    }
    paint(function(ch){ return ch.number != null && ch.number !== '' ? 'ch-' + Number(ch.number) : ch.id; });
    liveApi('channels').then(function(r){
      var map = {};
      ((r && r.channels) || []).forEach(function(c){ if (c.kind === 'relay') map[c.id] = c.slug; });
      paint(function(ch){ return map[ch.id] || ch.id; });
    }).catch(function(){});
    return box;
  }
  function playerOutputRow(url, label, fileBase){
    var copyBtn = h('button', { className: 'btn primary player-copy', type: 'button', text: 'Copy stream URL',
      title: 'Copy the network stream URL \u2014 paste into VLC (Media \u2192 Open Network Stream) or any player',
      onClick: function(e){ copyStreamUrl(url, e.currentTarget); } });
    var urlBox = h('input', { className: 'player-url', type: 'text', readonly: true, value: url, spellcheck: 'false',
      'aria-label': 'Network stream URL for ' + label,
      onFocus: function(e){ try { e.target.select(); } catch (eF) {} },
      onClick: function(e){ try { e.target.select(); } catch (eF) {} } });
    var actions = [copyBtn];
    if (canWebShare()) actions.push(h('button', { className: 'btn ghost player-share', type: 'button', text: 'Share to app\u2026',
      title: 'Send the stream URL to VLC or another player via the share sheet',
      onClick: function(){ shareStreamUrl(url, label); } }));
    actions.push(h('a', { className: 'player-dl muted', href: playlistDownloadUrl(url), download: (fileBase || 'channel') + '.m3u',
      title: 'Save a small .m3u playlist file (optional) \u2014 open it with your media player',
      text: 'Download .m3u',
      onClick: function(){ toast('Saved playlist \u2014 open the download with your media player', { kind:'ok', ms: 3500 }); } }));
    return h('div', { className: 'live-plist-row player-row' }, [
      h('div', { className: 'grow' }, [ h('strong', { text: label }), urlBox ]),
      h('div', { className: 'row-actions player-actions' }, actions)
    ]);
  }
  /* programmatic entry kept for stale callers: never navigates; copies the URL instead */
  function openInPlayer(url){ return copyStreamUrl(url); }
  function openInVlc(url){ return copyStreamUrl(url); }
  /* ---------------- IPTV ---------------- */
  function paintLiveIptv(host){
    var iptv = liveSet.iptv || { channels: [], feeds: [], epgUrls: [], numberStart: 100, activeFeedId: 'all' };
    var feeds = iptv.feeds || [];
    var favs = liveSet.favView || (iptv.channels || []).map(function(ch, i){
      return Object.assign({}, ch, {
        number: (iptv.numberStart || 100) + i,
        enabled: ch.enabled !== false,
        streamStatus: ch.streamStatus || 'unknown'
      });
    });
    if (liveSet.hideInactiveFav) favs = favs.filter(function(ch){ return ch.streamStatus !== 'bad'; });
    var activeFeed = iptv.activeFeedId || 'all';
    var editFeed = liveSet.feedEditId ? feeds.filter(function(f){ return f.id === liveSet.feedEditId; })[0] : null;

    host.innerHTML = '';

    // Feeds card
    host.appendChild(h('div', { className: 'settings-card' }, [
      h('h3', { text: 'Playlist feeds (' + feeds.length + ')' }),
      h('p', { className: 'muted', text: 'Each feed is a name + URL to an .m3u / .m3u8 index. Browse uses the active feed (or All, which merges every enabled feed, de-duplicated by stream URL). Playlists cache ~24h on the server \u2014 Reload playlist forces a redownload. HLS plays through the same-origin proxy.' }),
      feeds.length ? h('div', { className: 'live-table-wrap' }, [
        h('table', { className: 'live-table' }, [
          h('thead', {}, [h('tr', {}, ['Name','URL','On',''].map(function(t){ return h('th', { text: t }); }))]),
          h('tbody', {}, feeds.map(function(f){
            return h('tr', {}, [
              h('td', {}, [h('strong', { text: f.name || f.id }), f.preinstalled ? h('span', { className: 'lc-pill iptv', text: 'Preinstalled', style: 'margin-left:6px' }) : null, h('div', { className: 'muted file', text: f.id })]),
              h('td', { className: 'file', text: f.url || '' }),
              h('td', {}, [h('label', { className: 'check-label' }, [
                h('input', { type: 'checkbox', checked: f.enabled !== false, onChange: function(ev){
                  var on = ev.target.checked;
                  persistIptvFeeds(feeds.map(function(x){ return x.id === f.id ? Object.assign({}, x, { enabled: on }) : Object.assign({}, x); }), null, (on ? 'Enabled ' : 'Disabled ') + (f.name || f.id));
                }}), ' On'
              ])]),
              h('td', { className: 'row-actions' }, [
                h('button', { className: 'btn ghost', type: 'button', text: 'Edit', onClick: function(){ liveSet.feedEditId = f.id; paintLiveIptv(host); }}),
                h('button', { className: 'btn ghost', type: 'button', text: 'Remove', onClick: function(){
                  if (!confirm('Remove feed ' + (f.name||f.id) + '?' + (f.preinstalled ? ' It will not be added back automatically; you can re-add it any time with its URL.' : ''))) return;
                  var extra = null;
                  if ((iptv.activeFeedId || '') === f.id) extra = { activeFeedId: 'all' };
                  if (liveSet.browse.feed === f.id) liveSet.browse.feed = 'all';
                  persistIptvFeeds(feeds.filter(function(x){ return x.id !== f.id; }), extra);
                }})
              ])
            ]);
          }))
        ])
      ]) : h('p', { className: 'muted', text: 'No feeds configured.' }),
      h('div', { className: 'stack', style: 'margin-top:12px' }, [
        h('label', {}, ['Feed name', h('input', { id: 'iptvFeedName', value: editFeed ? (editFeed.name||'') : '', placeholder: 'e.g. iptv-org' })]),
        h('label', {}, ['Playlist URL', h('input', { id: 'iptvFeedUrl', value: editFeed ? (editFeed.url||'') : '', placeholder: 'https://example.com/playlist.m3u' })]),
        editFeed ? h('p', { className: 'muted', text: 'Editing feed id ' + editFeed.id }) : null,
        h('div', { className: 'row-actions', style: 'flex-wrap:wrap' }, [
          h('button', { className: 'btn', type: 'button', text: editFeed ? 'Update feed' : 'Add feed', onClick: function(){
            var name = (($('#iptvFeedName')||{}).value || '').trim();
            var url = (($('#iptvFeedUrl')||{}).value || '').trim();
            if (!url) { liveSetMsg('Playlist URL is required.', true); return; }
            if (!/^https?:\/\//i.test(url)) { liveSetMsg('Playlist URL must start with http:// or https://', true); return; }
            if (!name) name = 'Feed';
            var next = feeds.slice();
            if (liveSet.feedEditId) {
              if (!next.some(function(f){ return f.id === liveSet.feedEditId; })) { liveSetMsg('Feed not found.', true); return; }
              next = next.map(function(f){ return f.id === liveSet.feedEditId ? Object.assign({}, f, { name: name, url: url }) : f; });
            } else {
              var id = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || liveUid('feed');
              var base = id, n = 2;
              while (next.some(function(f){ return f.id === id; })) { id = base + '-' + n; n++; }
              next.push({ id: id, name: name, url: url, enabled: true });
            }
            liveSet.feedEditId = null;
            persistIptvFeeds(next, null);
          }}),
          editFeed ? h('button', { className: 'btn ghost', type: 'button', text: 'Cancel edit', onClick: function(){ liveSet.feedEditId = null; paintLiveIptv(host); }}) : null,
          h('label', {}, ['Active browse feed', h('select', { id: 'iptvActiveFeed' },
            [h('option', { value: 'all', text: 'All feeds', selected: activeFeed === 'all' })].concat(feeds.map(function(f){
              return h('option', { value: f.id, text: f.name || f.id, selected: activeFeed === f.id });
            }))
          )]),
          h('button', { className: 'btn ghost', type: 'button', text: 'Save active feed', onClick: function(){
            var aid = ($('#iptvActiveFeed')||{}).value || 'all';
            liveApiPost('iptv_save', { activeFeedId: aid }).then(function(res){
              if (!res.ok || !res.body || !res.body.ok) { liveSetMsg((res.body && res.body.error) || 'Save failed', true); return; }
              liveSet.iptv = res.body.iptv;
              liveSet.browse.feed = aid;
              liveSetMsg('Active feed set to ' + aid);
              paintLiveIptv(host);
            });
          }})
        ])
      ])
    ]));

    // Favourites
    host.appendChild(h('div', { className: 'settings-card' }, [
      h('h3', { text: 'Your favourites (' + (iptv.channels || []).length + ')' }),
      h('div', { className: 'row-actions', style: 'flex-wrap:wrap;margin-bottom:10px' }, [
        h('label', { className: 'check-label' }, [
          h('input', { type: 'checkbox', checked: liveSet.hideInactiveFav, onChange: function(e){ liveSet.hideInactiveFav = e.target.checked; paintLiveIptv(host); }}),
          ' Hide inactive (cached red)'
        ]),
        h('button', { className: 'btn ghost', type: 'button', text: 'Check selected', onClick: function(){
          var items = [];
          host.querySelectorAll('.iptv-fav-sel:checked').forEach(function(cb){
            items.push({ id: cb.getAttribute('data-id'), url: cb.getAttribute('data-url') || '' });
          });
          probeIptvItems(items, function(){ paintLiveIptv(host); });
        }})
      ]),
      !(iptv.channels || []).length ? h('p', { className: 'muted', text: 'Empty — browse below and click Add.' }) :
      h('div', { className: 'live-table-wrap' }, [
        h('table', { className: 'live-table' }, [
          h('thead', {}, [h('tr', {}, ['', 'Status', '#', 'Channel', 'Group', 'On', ''].map(function(t){ return h('th', { text: t }); }))]),
          h('tbody', {}, favs.map(function(ch){
            return h('tr', {}, [
              h('td', {}, [h('input', { type: 'checkbox', className: 'iptv-fav-sel', 'data-id': ch.id, 'data-url': ch.url || '' })]),
              h('td', {}, [liveIptvDot(ch.streamStatus || 'unknown')]),
              h('td', { text: String(ch.number != null ? ch.number : '') }),
              h('td', {}, [h('strong', { text: ch.name || ch.id }), h('div', { className: 'muted file', text: ch.id + (ch.tvgId ? (' · ' + ch.tvgId) : '') })]),
              h('td', { text: ch.group || '' }),
              h('td', {}, [h('label', { className: 'check-label' }, [
                h('input', { type: 'checkbox', checked: ch.enabled !== false, onChange: function(ev){
                  liveApiPost('iptv_toggle', { id: ch.id, enabled: ev.target.checked }).then(function(res){
                    if (!res.ok || !res.body || !res.body.ok) { liveSetMsg((res.body && res.body.error) || 'Toggle failed', true); ev.target.checked = !ev.target.checked; return; }
                    liveSet.iptv = null; liveSet.favView = null;
                    liveLoadIptv(true).then(function(){ paintLiveIptv(host); });
                  });
                }}), ' On'
              ])]),
              h('td', { className: 'row-actions' }, [
                h('button', { className: 'btn ghost sm', type: 'button', text: 'Up', title: 'Move up the lineup (renumbers)', disabled: favIndex(ch.id) <= 0, onClick: function(){ moveFav(ch.id, -1); } }),
                h('button', { className: 'btn ghost sm', type: 'button', text: 'Down', title: 'Move down the lineup (renumbers)', disabled: favIndex(ch.id) < 0 || favIndex(ch.id) >= (iptv.channels || []).length - 1, onClick: function(){ moveFav(ch.id, 1); } }),
                h('button', { className: 'btn ghost', type: 'button', text: 'Check', onClick: function(){
                  probeIptvItems([{ id: ch.id, url: ch.url || '' }], function(){ paintLiveIptv(host); });
                }}),
                h('button', { className: 'btn ghost', type: 'button', text: 'Remove', onClick: function(){
                  liveApiPost('iptv_remove', { id: ch.id }).then(function(res){
                    if (!res.ok || !res.body || !res.body.ok) { liveSetMsg((res.body && res.body.error) || 'Remove failed', true); return; }
                    liveSet.iptv = null; liveSet.favView = null;
                    liveSetMsg('Removed ' + ch.id);
                    liveLoadIptv(true).then(function(){ paintLiveIptv(host); });
                  });
                }})
              ])
            ]);
          }))
        ])
      ]),
      h('label', { style: 'margin-top:12px;display:block' }, ['Lineup numbers start at', h('input', { id: 'iptvNumStart', type: 'number', className: 'narrow', min: 1, value: iptv.numberStart || 100 })])
    ]));

    // EPG
    host.appendChild(h('div', { className: 'settings-card stack' }, [
      h('h3', { text: 'EPG (XMLTV)' }),
      h('p', { className: 'muted', text: 'Unified guide merges Relay schedules with IPTV programmes. One XMLTV URL per line, matched by tvg-id / channel id. With no XMLTV, IPTV channels show a generic Live block in the guide.' }),
      h('label', {}, ['XMLTV URLs', h('textarea', { id: 'iptvEpgUrls', rows: 4, text: (iptv.epgUrls || []).join('\n') })]),
      h('div', { className: 'row-actions' }, [
        h('button', { className: 'btn primary', type: 'button', text: 'Save IPTV settings', onClick: function(){
          var urls = (($('#iptvEpgUrls')||{}).value || '').split(/\r?\n/).map(function(s){ return s.trim(); }).filter(Boolean);
          liveApiPost('iptv_save', {
            epgUrls: urls,
            numberStart: Number((($('#iptvNumStart')||{}).value) || 100)
          }).then(function(res){
            if (!res.ok || !res.body || !res.body.ok) { liveSetMsg((res.body && res.body.error) || 'Save failed', true); return; }
            liveSet.iptv = res.body.iptv;
            liveSetMsg('IPTV settings saved.');
            paintLiveIptv(host);
          });
        }}),
        h('button', { className: 'btn ghost', type: 'button', text: 'Refresh EPG now', onClick: function(){
          liveSetMsg('Fetching EPG…');
          liveApi('epg_refresh', '&force=1').then(function(body){
            if (!body || !body.ok) { liveSetMsg((body && body.error) || 'EPG refresh failed', true); return; }
            liveSetMsg('EPG refreshed · ' + (body.channelsWithEpg || 0) + ' favourites matched · ' + ((body.errors || []).length) + ' URL errors');
          }).catch(function(e){ liveSetMsg(String(e.message||e), true); });
        }})
      ])
    ]));

    // Browse
    var br = liveSet.browse;
    var browseCard = h('div', { className: 'settings-card' }, [
      h('h3', { text: 'Browse channels' }),
      h('div', { className: 'row-actions', style: 'flex-wrap:wrap;margin-bottom:10px' }, [
        h('label', {}, ['Feed', h('select', { id: 'iptvBrowseFeed', onChange: function(){ runIptvBrowse(false, false); } },
          [h('option', { value: 'all', text: 'All feeds', selected: br.feed === 'all' })].concat(feeds.map(function(f){
            return h('option', { value: f.id, text: f.name || f.id, selected: br.feed === f.id });
          }))
        )]),
        h('label', {}, ['Filter', h('input', { id: 'iptvQ', value: br.q || '', placeholder: 'e.g. ITV', onKeydown: function(e){ if (e.key === 'Enter') { e.preventDefault(); runIptvBrowse(false, false); } } })]),
        h('label', {}, ['Country', h('select', { id: 'iptvCountry', onChange: function(){ runIptvBrowse(false, false); } }, iptvFacetOptions('countries', br.country, 'All countries'))]),
        h('label', {}, ['Category', h('select', { id: 'iptvCategory', onChange: function(){ runIptvBrowse(false, false); } }, iptvFacetOptions('categories', br.category, 'All categories'))]),
        h('label', { className: 'check-label' }, [
          h('input', { type: 'checkbox', id: 'iptvActiveOnly', checked: !!br.activeOnly, onChange: function(){ runIptvBrowse(false, false); }}),
          ' Hide inactive'
        ]),
        h('button', { className: 'btn', type: 'button', text: 'Refresh list', onClick: function(){ runIptvBrowse(false, false); }}),
        h('button', { className: 'btn ghost', type: 'button', text: 'Reload playlist', onClick: function(){ runIptvBrowse(false, true); }}),
        h('button', { className: 'btn ghost', type: 'button', text: 'Check selected', onClick: function(){
          var items = [];
          host.querySelectorAll('.iptv-br-sel:checked').forEach(function(cb){
            var ch = br.channels[Number(cb.getAttribute('data-idx'))];
            if (ch) items.push({ id: ch.id, url: ch.url });
          });
          probeIptvItems(items, function(){ paintIptvBrowseResults(); });
        }})
      ]),
      h('p', { className: 'muted', text: 'Status dots stay grey until you Check (max 25 at a time). Hide inactive only drops channels previously probed red. All feeds merges every enabled feed, de-duplicated by stream URL.' }),
      h('div', { id: 'iptvResults', className: 'live-table-wrap' }, [pendingContent({ className: 'muted', })]),
      h('div', { className: 'row-actions', style: 'margin-top:8px' }, [
        h('button', { className: 'btn ghost', type: 'button', id: 'iptvMore', text: 'Load more', disabled: true, onClick: function(){ runIptvBrowse(true, false); }}),
        h('span', { className: 'muted', id: 'iptvCount', text: '' })
      ])
    ]);
    host.appendChild(browseCard);

    function favIndex(id){
      var list = iptv.channels || [];
      for (var i = 0; i < list.length; i++) if (list[i].id === id) return i;
      return -1;
    }
    function moveFav(id, dir){
      var list = (iptv.channels || []).slice();
      var i = favIndex(id), j = i + dir;
      if (i < 0 || j < 0 || j >= list.length) return;
      var t = list[i]; list[i] = list[j]; list[j] = t;
      liveApiPost('iptv_save', { channels: list }).then(function(res){
        if (!res.ok || !res.body || !res.body.ok) { liveSetMsg((res.body && res.body.error) || 'Reorder failed', true); return; }
        liveSet.iptv = null; liveSet.favView = null;
        liveSetMsg('Lineup order saved.');
        liveLoadIptv(true).then(function(){ paintLiveIptv(host); });
      }).catch(function(e){ liveSetMsg(String(e.message || e), true); });
    }
    function persistIptvFeeds(nextFeeds, extra, okMsg){
      var body = { feeds: nextFeeds };
      if (extra) Object.keys(extra).forEach(function(k){ body[k] = extra[k]; });
      liveApiPost('iptv_save', body).then(function(res){
        if (!res.ok || !res.body || !res.body.ok) { liveSetMsg((res.body && res.body.error) || 'Feed save failed', true); paintLiveIptv(host); return; }
        liveSet.iptv = res.body.iptv;
        liveSet.feedEditId = null;
        liveSetMsg(okMsg || 'Feeds saved.');
        paintLiveIptv(host);
      }).catch(function(e){ liveSetMsg(String(e.message || e), true); });
    }

    function applyProbeResults(results){
      results = results || [];
      var map = {};
      results.forEach(function(r){ if (r.url) map[r.url] = r; if (r.id) map['id:' + r.id] = r; });
      if (liveSet.favView) {
        liveSet.favView.forEach(function(ch){
          var r = map['id:' + ch.id] || map[ch.url];
          if (r) { ch.streamStatus = r.ok ? 'ok' : 'bad'; ch.streamCheckedAt = r.checkedAt || null; }
        });
      }
      (liveSet.browse.channels || []).forEach(function(ch){
        var r = map[ch.url] || map['id:' + ch.id];
        if (r) { ch.streamStatus = r.ok ? 'ok' : 'bad'; ch.streamCheckedAt = r.checkedAt || null; }
      });
    }

    function probeIptvItems(items, done){
      if (!items.length) { liveSetMsg('Select one or more channels to Check (max 25).', true); return; }
      if (items.length > 25) items = items.slice(0, 25);
      liveSetMsg('Probing ' + items.length + ' stream' + (items.length === 1 ? '' : 's') + '…');
      liveApiPost('iptv_probe', { channels: items }).then(function(res){
        if (!res.ok || !res.body || !res.body.ok) { liveSetMsg((res.body && res.body.error) || 'Probe failed', true); return; }
        applyProbeResults(res.body.results || []);
        var okN = (res.body.results || []).filter(function(r){ return r.ok; }).length;
        var badN = (res.body.results || []).length - okN;
        liveSetMsg('Probe done · ' + okN + ' green · ' + badN + ' red');
        if (done) done();
      }).catch(function(e){ liveSetMsg(String(e.message||e), true); });
    }

    function paintIptvBrowseResults(){
      var box = document.getElementById('iptvResults');
      var more = document.getElementById('iptvMore');
      var count = document.getElementById('iptvCount');
      if (!box) return;
      box.innerHTML = '';
      if (br.loading) { box.appendChild(pendingContent({ className: 'muted', })); return; }
      if (!(br.channels || []).length) {
        box.appendChild(h('p', { className: 'muted', text: 'No channels in this feed (or all filtered).' }));
      } else {
        box.appendChild(h('table', { className: 'live-table' }, [
          h('thead', {}, [h('tr', {}, ['', 'Status', 'Channel', 'Group', 'Feed', 'Q', ''].map(function(t){ return h('th', { text: t }); }))]),
          h('tbody', {}, br.channels.map(function(ch, idx){
            return h('tr', {}, [
              h('td', {}, [h('input', { type: 'checkbox', className: 'iptv-br-sel', 'data-idx': String(idx) })]),
              h('td', {}, [liveIptvDot(ch.streamStatus || 'unknown')]),
              h('td', {}, [h('strong', { text: ch.name || '' }), h('div', { className: 'muted file', text: ch.tvgId || ch.id || '' })]),
              h('td', { text: ch.group || '' }),
              h('td', { text: ch.sourceFeedName || ch.sourceFeedId || '' }),
              h('td', { text: ch.quality || '' }),
              h('td', { className: 'row-actions' }, [
                h('button', { className: 'btn ghost', type: 'button', text: 'Check', onClick: function(){
                  probeIptvItems([{ id: ch.id, url: ch.url }], function(){ paintIptvBrowseResults(); });
                }}),
                ch.inLineup ? h('span', { className: 'muted', text: 'Added' }) : h('button', { className: 'btn', type: 'button', text: 'Add', onClick: function(){
                  liveApiPost('iptv_add', ch).then(function(res){
                    if (!res.ok || !res.body || !res.body.ok) { liveSetMsg((res.body && res.body.error) || 'Add failed', true); return; }
                    liveSet.iptv = null; liveSet.favView = null;
                    ch.inLineup = true; // browse rows are cached client-side; show "Added" without a re-search
                    liveSetMsg('Added ' + ch.name);
                    liveLoadIptv(true).then(function(){ paintLiveIptv(host); });
                  });
                }})
              ])
            ]);
          }))
        ]));
      }
      var srcShort = br.source || '';
      if (srcShort.indexOf('https://') === 0 && srcShort.length > 60) srcShort = srcShort.slice(0, 57) + '…';
      if (count) count.textContent = (br.total || 0) + ' in list · showing ' + (br.channels || []).length + (srcShort ? ' · ' + srcShort : '');
      if (more) more.disabled = !br.channels || br.channels.length >= (br.total || 0);
    }

    function iptvFacetOptions(kind, current, allLabel){
      var list = (br.facets && br.facets[kind]) || [];
      var names = null;
      try { names = kind === 'countries' && typeof Intl !== 'undefined' && Intl.DisplayNames ? new Intl.DisplayNames([navigator.language || 'en'], { type:'region' }) : null; } catch (eN) {}
      var opts = [h('option', { value: '', text: allLabel, selected: !current })];
      if (current && !list.some(function(f){ return f.key === current; })) list = [{ key: current, count: 0 }].concat(list);
      list.forEach(function(f){
        var label = f.key === '?' ? 'Unknown' : f.key;
        if (names && f.key.length === 2) { try { label = names.of(f.key) || f.key; } catch (eD) {} }
        opts.push(h('option', { value: f.key, selected: f.key === current, text: label + (f.count ? ' (' + f.count + ')' : '') }));
      });
      return opts;
    }
    function iptvRefillFacets(){
      [['iptvCountry', 'countries', br.country, 'All countries'], ['iptvCategory', 'categories', br.category, 'All categories']].forEach(function(x){
        var sel = document.getElementById(x[0]); if (!sel) return;
        sel.innerHTML = ''; iptvFacetOptions(x[1], x[2], x[3]).forEach(function(o){ sel.appendChild(o); });
      });
    }
    function runIptvBrowse(append, forceRefresh){
      br.feed = (($('#iptvBrowseFeed')||{}).value) || 'all';
      br.q = (($('#iptvQ')||{}).value || '').trim();
      br.group = '';
      var cSel = $('#iptvCountry'), gSel = $('#iptvCategory');
      if (cSel) br.country = cSel.value || '';
      if (gSel) br.category = gSel.value || '';
      br.activeOnly = !!(document.getElementById('iptvActiveOnly') && document.getElementById('iptvActiveOnly').checked);
      if (!append) { br.offset = 0; br.channels = []; }
      br.loading = true;
      paintIptvBrowseResults();
      var q = 'feed=' + encodeURIComponent(br.feed) + '&q=' + encodeURIComponent(br.q) + '&group=' + encodeURIComponent(br.group) + '&country=' + encodeURIComponent(br.country || '') + '&category=' + encodeURIComponent(br.category || '') + '&limit=' + br.pageSize + '&offset=' + br.offset;
      if (br.activeOnly) q += '&activeOnly=1';
      if (forceRefresh) q += '&refresh=1';
      liveApi('iptv_browse', q).then(function(body){
        br.loading = false;
        if (!body || !body.ok) { liveSetMsg((body && body.error) || 'Browse failed', true); paintIptvBrowseResults(); return; }
        br.total = body.total || 0;
        br.source = body.source || '';
        br.channels = append ? br.channels.concat(body.channels || []) : (body.channels || []);
        if (!append && body.facets) { br.facets = body.facets; iptvRefillFacets(); }
        if (body.feedErrors && Object.keys(body.feedErrors).length) liveSetMsg('Some sources could not be loaded: ' + Object.keys(body.feedErrors).map(function(k){ return k + ' (' + body.feedErrors[k] + ')'; }).join(', '), true);
        br.offset = br.channels.length;
        if ((!iptv.epgUrls || !iptv.epgUrls.length) && body.playlistEpgUrls && body.playlistEpgUrls.length) {
          var ta = document.getElementById('iptvEpgUrls');
          if (ta && !ta.value.trim()) {
            ta.value = body.playlistEpgUrls.join('\n');
            liveSetMsg('Playlist suggested EPG URL(s) — Save IPTV settings to keep them.');
          }
        }
        if (forceRefresh) liveSetMsg('Playlist redownloaded · ' + (br.total || 0) + ' channels');
        paintIptvBrowseResults();
      }).catch(function(e){ br.loading = false; liveSetMsg(String(e.message||e), true); paintIptvBrowseResults(); });
    }

    runIptvBrowse(false, false);
  }
  function openSettings(section){
    state.settingsSection = section || 'account';
    var adminIds = { maintenance:'maintenance', players:'players', features:'features', libraries:'sections', sections:'sections', scan:'scan', library:'library', media:'media', collections:'collections', streaming:'streaming', convert:'convert', queue:'queue', acquisition:'acquisition', system:'system', general:'general', users:'users', advanced:'advanced', ingest:'ingest', matching:'matching', reports:'reports' };
    if (adminIds[state.settingsSection]) state.adminTab = adminIds[state.settingsSection];
    var needsAdmin = !!adminIds[state.settingsSection] || state.settingsSection === 'live' || (state.settingsSection && state.settingsSection.indexOf('live-') === 0);
    if (needsAdmin) {
      state._wantAdmin = true;
      state._settingsAfterLogin = state.settingsSection;
      if (!(state.user && state.user.role === 'admin')) {
        state.view = 'login';
        render();
        return;
      }
    }
    state.view = 'settings';
    pushNav();
    render();
    /* pull matching.js when Settings opens (deferred from boot). */
    if (window.__nsEnsureMatching) {
      window.__nsEnsureMatching(function(){
        try { if (window.NSMatch && window.NSMatch.refreshCounts) window.NSMatch.refreshCounts(); } catch (e) {}
        if (state.view === 'settings') render();
      });
    }
  }
  function openAdmin(tab){
    openSettings(tab || state.adminTab || 'general');
  }

  /* Signed-out landing page. The deep link the visitor asked for is kept in
     state._returnHash and restored after sign-in. */
  /* The deep link a signed-out (or gated) visitor asked for, restored after sign-in. */
  function rememberReturnPath(){
    upgradeLegacyHash();
    var rel = R.relative(location.pathname, BASE);
    if (rel !== '' && rel !== 'welcome' && rel !== 'login') state._returnHash = BASE + rel + (location.search || '');
  }
  function showWelcome(){
    rememberReturnPath();
    state.view = 'welcome';
    _navSilent = true;
    try { history.replaceState({}, '', BASE + 'welcome'); } catch (e) {}
    render();
    _navSilent = false;
  }
  function restoreReturnHash(){
    var target = state._returnHash || BASE;
    state._returnHash = null;
    try { history.replaceState({}, '', target); } catch (e) { location.href = target; }
  }
  /* Time-limited access: a signed-in user without active access stays on the welcome page. */
  function accessGated(){
    var a = state.user && state.user.access_state;
    return !!(state.user && state.user.role !== 'admin' && a && a.active === false);
  }
  function fmtAccessDate(ts){
    if (!ts) return '';
    try { return new Date(ts * 1000).toLocaleDateString(undefined, { year:'numeric', month:'short', day:'numeric' }); } catch (e) { return ''; }
  }
  function fmtRemaining(sec){
    if (sec == null) return '';
    if (sec <= 0) return 'expired';
    var d = Math.floor(sec / 86400), hr = Math.floor((sec % 86400) / 3600);
    if (d >= 60) return Math.round(d / 30) + ' months left';
    if (d >= 1) return d + (d === 1 ? ' day' : ' days') + ' left';
    if (hr >= 1) return hr + (hr === 1 ? ' hour' : ' hours') + ' left';
    return 'under an hour left';
  }
  function welcomeGateCard(){
    var a = state.user.access_state || {};
    var expired = a.state === 'expired';
    var name = state.user.display_name || state.user.username;
    var status = h('p', { className:'muted welcome-gate-status', role:'status' });
    return h('div', { className:'welcome-gate ' + (expired ? 'is-expired' : 'is-pending') }, [
      h('div', { className:'welcome-gate-icon', 'aria-hidden':'true', text: expired ? '⏳' : '🔑' }),
      h('h2', { text: expired ? 'Your access has expired' : 'Awaiting access' }),
      h('p', { text: expired
        ? 'Hi ' + name + ', your access ended' + (a.expires_at ? ' on ' + fmtAccessDate(a.expires_at) : '') + '. Ask your administrator to renew it, then check again.'
        : 'Hi ' + name + ', your account is set up. An administrator needs to grant access before you can start watching.' }),
      h('div', { className:'welcome-actions' }, [
        h('button', { className:'btn primary', text:'Check again', onClick: function(){
          status.textContent = 'Checking…';
          api('/me').then(function(j){
            if (j.ok && j.user) {state.user = j.user;document.dispatchEvent(new Event('vaultcast-account-ready'));}
            if (!j.ok || !j.user) { state.user = null; showWelcome(); return; }
            if (accessGated()) { status.textContent = 'Still waiting. Try again once your administrator has granted access.'; return; }
            restoreReturnHash(); bootApp();
          });
        }}),
        h('button', { className:'btn ghost', text:'Sign out', onClick: function(){ api('/logout', { method:'POST' }).then(function(){ state.user = null; showWelcome(); }); } })
      ]),
      status
    ]);
  }
  function goSignIn(){ state._wantAdmin = false; state.view = 'login'; render(); setTimeout(function(){ var u = $('#lu'); if (u) u.focus(); }, 0); }
  function viewWelcome(){
    var site = window.__SITE_NAME__ || 'Vaultcast';
    function feature(icon, title, text){
      return h('div', { className:'welcome-feature' }, [
        h('div', { className:'welcome-feature-icon', text:icon, 'aria-hidden':'true' }),
        h('h3', { text:title }), h('p', { text:text })
      ]);
    }
    var gated = accessGated();
    var actions = gated ? [ welcomeGateCard() ] : [ h('button', { className:'btn primary welcome-cta', text:'Sign in', onClick: goSignIn }) ];
    if (!gated && state._guestBrowsing) actions.push(h('button', { className:'btn ghost', text:'Browse as guest', onClick: function(){ state._guest = true; restoreReturnHash(); bootApp(); } }));
    return h('div', { className:'welcome' }, [
      h('header', { className:'welcome-top' }, [
        h('div', { className:'welcome-brand' }, [ h('img', { src:'assets/img/mark.svg', alt:'', width:28, height:28 }), h('strong', { text:site }) ]),
        gated ? h('span', { className:'muted', text: 'Signed in as ' + (state.user.username || '') }) : h('button', { className:'btn', text:'Sign in', onClick: goSignIn })
      ]),
      h('main', { className:'welcome-main' }, [
        h('section', { className:'welcome-hero' }, [
          h('p', { className:'welcome-eyebrow', text:'Your private media server' }),
          h('h1', { text:'Your films, shows, live TV and games. One place.' }),
          h('p', { className:'welcome-lede', text: site + ' streams the library on your own server to any browser, TV or phone. No subscriptions or ads, and nothing leaves your house.' }),
          h('div', { className:'welcome-actions' }, actions)
        ]),
        h('section', { className:'welcome-features', 'aria-label':'What you get' }, [
          feature('🎬', 'Films & series', 'Artwork, collections and resume points for every title, played directly with no re-encoding where possible.'),
          feature('📺', 'Live TV', 'Scheduled channels built from your library, plus IPTV with a full programme guide.'),
          feature('🕹️', 'Retro games', 'Play your ROM collection in the browser, and join friends in the online lobby.'),
          feature('📱', 'Every screen', 'Use it in your browser or install it as an app on your phone.')
        ])
      ]),
      h('footer', { className:'welcome-foot', text: site + ' · Private server. Ask your administrator for an account.' })
    ]);
  }

  function viewLogin(){
    return h('div', { className:'login-wrap' }, [
      h('div', { className:'login-card stack' }, [
        h('div', { className:'brand' }, [ h('span', { className:'mark', text:'▶', style:'display:inline-flex;width:28px;height:28px;border-radius:8px;background:var(--accent);align-items:center;justify-content:center;margin-right:8px' }), h('strong',{text:window.__SITE_NAME__ || 'Vaultcast'}) ]),
        h('h1', { text:'Sign in' }),
        h('p', { text:'DirectPlay catalogue on your server' }),
        h('label', {}, ['Username', h('input', { id:'lu', autocomplete:'username' })]),
        h('label', {}, ['Password', h('input', { id:'lp', type:'password', autocomplete:'current-password', onKeydown: function(e){ if(e.key==='Enter') doLogin(); } })]),
        h('button', { id:'login-submit', className:'btn primary', text:'Sign in', onClick: doLogin }),
        h('button', { className:'btn ghost', text: state.user || state._guest ? 'Back to catalogue' : 'Back', onClick: function(){ state._wantAdmin=false; if (state.user || state._guest) { state.view='home'; render(); } else showWelcome(); } }),
        h('p', { id:'login-err', className:'bad' })
      ])
    ]);
  }
  function doLogin(){
    if (state._loginBusy) return;
    state._loginBusy = true;
    var button = $('#login-submit');
    var error = $('#login-err');
    if (error) error.textContent = '';
    if (button) { button.disabled = true; button.textContent = 'Signing in…'; }
    api('/login', { method:'POST', body:{ username: $('#lu').value, password: $('#lp').value } }).then(function(j){
      if (!j.ok) { $('#login-err').textContent = j.error || 'Login failed'; return; }
      state.user = j.user;document.dispatchEvent(new Event('vaultcast-account-ready'));
      if (button) button.textContent = 'Opening your library…';
      if (state._wantAdmin) {
        if (state.user.role !== 'admin') {
          $('#login-err').textContent = 'Admin account required';
          return;
        }
        state._wantAdmin = false;
        state.view = 'settings';
        state.settingsSection = state._settingsAfterLogin || state.settingsSection || 'general';
        state._settingsAfterLogin = null;
        if (!state.home) return bootApp();
        pushNav();
        render();
        return;
      }
      restoreReturnHash();
      return bootApp();
    }).catch(function(){
      if (error && error.isConnected) error.textContent = 'Could not sign in. Check your connection and try again.';
    }).then(function(){
      state._loginBusy = false;
      if (button && button.isConnected) { button.disabled = false; button.textContent = 'Sign in'; }
    });
  }

  function navigate(view, sectionId){
    /* Closing Play / leaving the page while a game is open must stop audio. */
    if (document.getElementById('player-overlay')) {
      try { closePlayer(); } catch (eNavClose) {}
    }
    if (view === 'play') { var rsP = romsSection(); if (rsP) sectionId = rsP.id; }
    if (view === 'section' && sectionId) {
      var secR = (state.home && state.home.sections || []).find(function(s){ return s.id===sectionId; });
      if (secR && secR.type === 'roms') view = 'play';
    }
    var modeChange = view !== state.view || (sectionId && sectionId !== state.section);
    if (view !== 'section' || (sectionId && sectionId !== state.section)) {
      state.sectionLetter = '';
      state._sectionLetters = null;
      state.sectionFilters = { genre:'', year:'', decade:'', actor:'', status:'', network:'' };
      state.vodFilterDim = '';
      state._sectionFacets = null;
    }
    if (view !== 'collections') state.collectionsLetter = '';
    state.view = view;
    if (sectionId) {
      state.section = sectionId;
      var sec = (state.home && state.home.sections || []).find(function(s){ return s.id===sectionId; });
      state.sectionLabel = sec ? sec.label : sectionId;
      state.sectionType = sec ? sec.type : null;
    }
    pushNav();
    render();
    if (modeChange) { try { window.scrollTo(0, 0); } catch (eS) {} }
  }

  function applyPrefs(){
    var p = (state.user && state.user.prefs) || {};
    document.body.classList.toggle('reduce-motion', !!p.reduce_motion);
    if(state.user && state._homePrefUser!==state.user.id){state._homePrefUser=state.user.id;if(state.view==='home' && R.relative(location.pathname,BASE)==='' && p.home_landing)state.hubTab=p.home_landing;}
  }

  function render(){ renderView(); syncUrl(); }
  function renderView(){
    var root = $('#app');
    if (state.home && state.user && state.user.role !== 'admin') {
      var requiredArea = /^(?:show|movie|collections|collection|colmember)$/.test(state.view) ? 'library'
        : (/^(?:game|play)$/.test(state.view) ? 'games' : (state.view === 'live' ? 'live' : (state.view === 'section' ? (state.sectionType === 'roms' ? 'games' : 'library') : null)));
      if (requiredArea && !userAreaAllowed(requiredArea)) {state.view = 'settings';state.settingsSection = 'account';}
      if (state.view === 'search' && !userAreaAllowed('library') && !userAreaAllowed('games')) {state.view = 'settings';state.settingsSection = 'account';}
      if (state.view === 'library' && !userAreaAllowed('library')) {
        if (userAreaAllowed('live') && state.home.features && state.home.features.live) state.view = 'live';
        else if (userAreaAllowed('games') && romsSection()) state.view = 'play';
        else {state.view = 'settings';state.settingsSection = 'account';}
      }
    }
    _playRequestId++;
    if (document.querySelector('.player-inline')) closePlayer({preserveLobby:!!state._openingLobby});
    if (state.view !== 'game') state.gameLobby = null;
    if (state.view !== 'game') state.gamePath = null;
    if ((state.view === 'section' || state.view === 'show') && root.firstChild) {
      var nextView;
      var published = false;
      var publish = function(){
        if (published) return;
        published = true;
        stopBillboardCycle();
        unmountAzRail();
        applyPrefs();
        document.body.classList.remove('view-live');
        stopLiveTimers();
        closeMenus();
        // Keep navigation usable in browsers without Element.replaceChildren.
        while (root.firstChild) root.removeChild(root.firstChild);
        root.appendChild(nextView);
      };
      nextView = state.view === 'section' ? viewSection(publish) : viewShow(publish);
      return;
    }
    stopBillboardCycle();
    unmountAzRail();
    root.innerHTML = '';
    applyPrefs();
    document.body.classList.remove('view-live');
    if (state.view !== 'live') stopLiveTimers();
    closeMenus();
    if (state.view === 'welcome') { document.body.setAttribute('data-mode', 'none'); root.appendChild(viewWelcome()); return; }
    if (state.view === 'login') { document.body.setAttribute('data-mode', 'none'); root.appendChild(viewLogin()); return; }
    if (state.view === 'admin') {
      // fold Admin into unified Settings
      state.view = 'settings';
      if (!state.settingsSection || state.settingsSection === 'account') state.settingsSection = state.adminTab || 'general';
    }
    if (state.view === 'settings') {
      var adminOnly = { maintenance:1, players:1, features:1, diagnostics:1, sections:1, libraries:1, scan:1, library:1, media:1, collections:1, streaming:1, convert:1, queue:1, acquisition:1, system:1, general:1, users:1, advanced:1, ingest:1, matching:1, reports:1, live:1, 'live-channels':1, 'live-library':1, 'live-playlists':1, 'live-iptv':1, 'live-schedule':1, 'live-station':1, 'live-studio':1 };
      if (adminOnly[state.settingsSection] && !(state.user && state.user.role === 'admin')) {
        state._wantAdmin = true;
        state._settingsAfterLogin = state.settingsSection;
        root.appendChild(viewLogin());
        return;
      }
      root.appendChild(viewSettings());
      return;
    }
    if (state.view === 'home') root.appendChild(window.VaultcastHub.view());
    else if (state.view === 'library') root.appendChild(viewHome());
    else if (state.view === 'section') root.appendChild(viewSection());
    else if (state.view === 'show') root.appendChild(viewShow());
    else if (state.view === 'movie' || state.view === 'game') root.appendChild(viewMovie());
    else if (state.view === 'search') root.appendChild(viewSearch());
    else if (state.view === 'collections') root.appendChild(viewCollections());
    else if (state.view === 'collection') root.appendChild(viewCollectionDetail());
    else if (state.view === 'colmember') root.appendChild(viewCollectionMember());
    else if (state.view === 'live') { root.appendChild(viewLive()); setTimeout(consumePendingLiveChannel, 0); }
    else if (state.view === 'play') root.appendChild(viewPlay());
    else root.appendChild(viewHome());
  }

  function bootApp(){
    return meP.then(function(me){
      if (me && me.ok && !me.user) state._guestBrowsing = !!me.guest_browsing;
      if (me && me.guest_browsing && !state.user) state._guest = true;
      if (!state.user && !state._guest) { showWelcome(); return; }
      if (accessGated()) { showWelcome(); return; }
      return loadHome();
    });
  }
  function loadHome(){
    var startup = window.__STREAM_STARTUP__;
    var sameUser = startup && startup.me && String((startup.me.user || {}).id || '') === String((state.user || {}).id || '');
    var initialHome = sameUser && startup.home;
    if (window.__STREAM_STARTUP__) window.__STREAM_STARTUP__.home = null;
    var homeRequest = initialHome ? Promise.resolve(initialHome) : api('/home');
    return homeRequest.then(function(j){
      if (!j.ok) {
        if (j._status === 401 || j._status === 403) return;
        throw new Error(j.error || 'Home failed');
      }
      state.home = j;
      return applyRoute().then(function(){
        state._routed = true;
        if (state.view === 'search' && state.searchQuery) { runGlobalSearch(state.searchQuery); replaceNav(); return; }
        _navSilent = true; render(); _navSilent = false;
        replaceNav();
        /* Deep link to a game: list page underneath (so Back/close returns to it), then start the game. */
        if (state._openGameItem) { var g = state._openGameItem; state._openGameItem = null; playItem(g); }
      });
    }).catch(function(){
      var root = $('#app');
      root.innerHTML = '';
      root.appendChild(shell(h('div', { className:'empty-state', role:'alert' }, [
        h('h2', { text:'Could not open your library' }),
        h('p', { text:'Check your connection and try again.' }),
        h('button', { className:'btn primary', text:'Try again', onClick:loadHome })
      ])));
    });
  }

  function renderPlayerAssets(){
    var panel = document.getElementById('player-assets-panel');
    if (!panel) return;
    var active = true, busy = false;
    function current(){ return active && document.getElementById('player-assets-panel') === panel; }
    function request(body){ return api('/admin/player-assets',{method:'POST',body:body}).then(function(result){
      if (!result.ok) throw new Error(result.error || 'Player file operation failed');
      return result;
    }); }
    function show(info){
      if (!current()) return;
      panel.innerHTML = '';
      panel.appendChild(h('p',{text:'Currently serving from: '+(info.source === 'local' ? 'this server' : 'CDN')}));
      var status = h('p',{role:'status',text:info.total ? info.done+' / '+info.total+' files downloaded ('+fmtSize(info.bytes)+').' : 'No server copy downloaded.'});
      panel.appendChild(status);
      panel.appendChild(h('p',{className:'muted',text:'The full download includes all cores and may be large. Keep this page open; an interrupted download can be resumed.'}));
      function source(value){
        request({action:'source',source:value}).then(function(){location.reload();}).catch(function(error){status.textContent=error.message;});
      }
      var download = h('button',{className:'btn primary',text:info.complete?'Download again':(info.done?'Resume download':'Download to server'),disabled:!info.can_download,onClick:function(){
        if (busy) return;
        busy=true; download.disabled=true; cdn.disabled=true; local.disabled=true;
        function next(){
          if (!current()) return;
          status.textContent='Downloading '+(info.done+1)+' / '+(info.total || '…')+' files…';
          request({action:'download'}).then(function(result){
            info=result;
            if (result.complete) { busy=false; active=false; location.reload(); return; }
            next();
          }).catch(function(error){
            busy=false; download.disabled=false; cdn.disabled=false; local.disabled=!info.complete;
            download.textContent='Resume download'; status.textContent=error.message;
          });
        }
        if (info.complete) request({action:'reset'}).then(function(result){info=result;next();}).catch(function(error){busy=false;download.disabled=false;cdn.disabled=false;local.disabled=false;status.textContent=error.message;});
        else next();
      }});
      var cdn=h('button',{className:'btn',text:'Use CDN',onClick:function(){source('cdn');}});
      var local=h('button',{className:'btn',text:'Use server copy',disabled:!info.complete,onClick:function(){source('local');}});
      panel.appendChild(h('div',{className:'row-actions'},[download,cdn,local]));
      if (!info.can_download) panel.appendChild(h('p',{text:'Enable PHP cURL on the server to download player files.'}));
    }
    api('/admin/player-assets').then(show).catch(function(error){if(current())panel.textContent=error.message;});
  }
  function registerSW(){
    if (!('serviceWorker' in navigator)) return;
    navigator.serviceWorker.register(BASE + 'sw.js?v=' + encodeURIComponent(window.__ASSET_VERSION__ || APP_VERSION), { scope: BASE }).then(function(reg){ try { reg.update(); } catch (e) {} }).catch(function(){});
  }

  // Keyboard: / focuses search (when not typing in an input)
  document.addEventListener('keydown', function(e){
    if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey) {
      var tag = (e.target && e.target.tagName || '').toLowerCase();
      if (tag === 'input' || tag === 'textarea' || tag === 'select' || (e.target && e.target.isContentEditable)) return;
      e.preventDefault();
      var box = $('#q');
      if (box) { box.focus(); box.select && box.select(); }
    }
  });

  /* helpers for assets/js/matching.js (Ingest inbox / Fix matching / Label reports) */
  window.NSApp = { h: h, api: api, toast: toast, confirmDialog: confirmDialog, render: render, state: state, can:can,
    openSettings: openSettings, openShow: openShow, openMovie: openMovie, openGame:openGame, navigate:navigate, refreshArtwork:refreshArtwork, fmtSize: fmtSize, version: APP_VERSION,
    shell:shell,cardEl:cardEl,applyRoute:applyRoute,replaceNav:replaceNav,openPlayer:openPlayer,playItem:playItem,copyText:copyText,shareUrl:shareUrl,startLobbyHost:startLobbyHost,captureGameState:lobbyCaptureState,quitGame:function(){closePlayer();} };
  window.VaultcastHub.init(window.NSApp);
  window.VaultcastHomeExtras.init(window.NSApp);
  // Load matching tools when Settings or a detail-page action needs them.
  var matchingCallbacks = [];
  window.__nsEnsureMatching = function(cb){
    if (window.NSMatch) { if (cb) cb(); return; }
    if (cb) matchingCallbacks.push(cb);
    if (window.__nsMatchingLoading) return;
    window.__nsMatchingLoading = true;
    var sm = document.createElement('script');
    sm.src = BASE + 'index.php?asset=assets/js/matching.js&v=' + (window.__ASSET_VERSION__ || APP_VERSION);
    sm.defer = true;
    sm.onload = function(){
      window.__nsMatchingLoading = false;
      var callbacks = matchingCallbacks; matchingCallbacks = [];
      callbacks.forEach(function(callback){ callback(); });
    };
    sm.onerror = function(){
      window.__nsMatchingLoading = false; matchingCallbacks = [];
      sm.remove(); toast('Could not load matching tools. Please try again.', {kind:'bad'});
    };
    document.head.appendChild(sm);
  };

  // Initial HTML images also need bounded scheduling; native lazy loading can
  // enqueue whole horizontal rails and consume all HTTP/1.1 connections.
  function enhanceFirstPosters(){
    var first = document.getElementById('first-home');
    if (!first) return;
    var queue = [], active = 0, observer;
    function pump(){
      while (active < 2 && queue.length) {
        var img = queue.shift();
        if (img.isConnected === false) continue;
        var url = img.getAttribute('data-first-src');
        if (!url) continue;
        img.removeAttribute('data-first-src'); active++;
        (function(image){
          var finished = false;
          function done(){ if (finished) return; finished = true; active--; pump(); }
          image.addEventListener('load', done, {once:true});
          image.addEventListener('error', done, {once:true});
          image.src = url;
        })(img);
      }
    }
    var images = first.querySelectorAll('img[data-first-src]');
    if (typeof IntersectionObserver === 'undefined') {
      Array.prototype.forEach.call(images, function(img){ queue.push(img); }); pump(); return;
    }
    observer = new IntersectionObserver(function(entries){
      entries.forEach(function(entry){
        if (!entry.isIntersecting) return;
        observer.unobserve(entry.target); queue.push(entry.target);
      });
      pump();
    }, {root:null, rootMargin:'80px 0px', threshold:0.01});
    Array.prototype.forEach.call(images, function(img){ observer.observe(img); });
  }

  // Reuse the same interactive navigation as in-app transitions, retaining the
  // first-response cards and avoiding another Home request or full repaint.
  function enhanceHomeNavigation(){
    var first = document.getElementById('first-home');
    if (!first) return;
    var enhanced = shell(null);
    var header = first.querySelector('header');
    var sections = first.querySelector('.first-sections');
    if (header) first.replaceChild(enhanced.querySelector('header'), header);
    if (sections) first.replaceChild(enhanced.querySelector('.subnav'), sections);
  }

  // Enhance actual first-response content without clearing it or waiting for /home.
  function enhanceFirstPage(){
    var initial = window.__STREAM_STARTUP__;
    if (!initial || !initial.serverRendered) return bootApp();
    if (!initial.home) {
      state.view = state.user ? 'welcome' : 'login';
      var form = document.getElementById('first-login');
      if (form) form.addEventListener('submit', function(){
        var button = form.querySelector('button[type="submit"]');
        if (button) { button.disabled = true; button.textContent = 'Signing in…'; }
      });
      return;
    }
    state.home = initial.home;
    state._guest = !state.user && !!initial.me.guest_browsing;
    applyPrefs();
    var root = $('#app');
    root.addEventListener('click', function(event){
      var link = event.target.closest && event.target.closest('a[data-first-nav]');
      if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      var playId = link.getAttribute('data-first-play');
      if (playId) {
        var items = (state.home.continue_watching || []).slice();
        (state.home.sections || []).forEach(function(section){ items = items.concat(section.items || []); });
        var item = items.find(function(candidate){ return String(candidate.id) === playId; });
        if (item) { playItem(item); return; }
      }
      history.pushState({}, '', link.href);
      applyRoute().then(function(){
        state._routed = true;
        if (state.view === 'search' && state.searchQuery) runGlobalSearch(state.searchQuery);
        else render();
        replaceNav();
        if (state._openGameItem) { var game = state._openGameItem; state._openGameItem = null; playItem(game); }
      }).catch(function(){ location.href = link.href; });
    });
    if (R.relative(location.pathname, BASE) !== '' || /^#\//.test(location.hash)) {
      return bootApp(); // Detail routes keep visible HTML underneath while their own data arrives.
    }
    state.view = 'home';
    state._routed = true;
    render();
    replaceNav();
    initial.home = null;
    setTimeout(loadLiveTeaser, 0);
    if (initial.refresh) {
      api('/home').then(function(home){
        if (!home.ok || String((state.user || {}).id || '') !== String((initial.me.user || {}).id || '') || accessGated()) return;
        state.home = home;
        if (state.view === 'home' && !document.getElementById('player-overlay')) render();
      }).catch(function(){ /* The server-rendered catalogue remains usable offline. */ });
    }
  }
  var initialMe = window.__STREAM_STARTUP__ && window.__STREAM_STARTUP__.me;
  var meP = (initialMe ? Promise.resolve(initialMe) : api('/me')).then(function(j){
    if (j.ok && j.user) {state.user = j.user;document.dispatchEvent(new Event('vaultcast-account-ready'));}
    if (j.ok && typeof j.pending_convert_count === 'number') updateConvertNotif(j.pending_convert_count);
    return j;
  }).catch(function(){ return null; });
  meP.then(enhanceFirstPage);
  meP.then(function(){ startConvertNotifPoll(); });
  registerSW();
})();
