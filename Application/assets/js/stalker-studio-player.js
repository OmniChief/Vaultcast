/* Stalker Studio: enhanced controls for the full-interface VOD player.
   This never replaces the underlying media element, playback resolver or compatibility player. */
(function(){
  'use strict';
  if(window.VaultcastCompatibility&&window.VaultcastCompatibility.active)return;
  var current=null,queued=false;
  function el(tag,cls,label){
    var n=document.createElement(tag);if(cls)n.className=cls;
    if(label!==undefined)n.textContent=label;return n;
  }
  function clock(seconds){
    if(!Number.isFinite(seconds)||seconds<0)return '0:00';
    var s=Math.floor(seconds),h=Math.floor(s/3600),m=Math.floor(s%3600/60);
    return (h?h+':'+String(m).padStart(2,'0'):String(m))+':'+String(s%60).padStart(2,'0');
  }
  function attach(video){
    if(video.dataset.stalkerStudioPlayer==='1')return;
    if(video.closest('.player-overlay')||video.closest('.compat-player'))return;
    var root=video.closest('.stalker-vod-detail,.stalker-video-detail,.stalker-vod-player,.stalker-player');
    if(!root)return;
    video.dataset.stalkerStudioPlayer='1';
    var wrap=el('div','stalker-studio-player'),tools=el('div','stalker-studio-player-controls');
    var parent=video.parentNode;if(!parent)return;
    parent.insertBefore(wrap,video);wrap.appendChild(video);wrap.appendChild(tools);
    video.setAttribute('playsinline','');
    video.controls=false;
    var play=el('button','stalker-studio-control','▶ Play');
    var back=el('button','stalker-studio-control','↶ 10s');
    var forward=el('button','stalker-studio-control','10s ↷');
    var time=el('span','stalker-studio-time','0:00 / 0:00');
    var progress=el('input','stalker-studio-progress');progress.type='range';progress.min='0';progress.max='1000';progress.value='0';progress.step='1';progress.setAttribute('aria-label','Seek');
    var sound=el('button','stalker-studio-control stalker-studio-sound','Volume');
    var volume=el('input','stalker-studio-volume');volume.type='range';volume.min='0';volume.max='1';volume.step='0.05';volume.value=String(video.volume);volume.setAttribute('aria-label','Volume');
    var speed=el('select','stalker-studio-speed');speed.setAttribute('aria-label','Playback speed');
    [0.75,1,1.25,1.5,2].forEach(function(value){var option=el('option','',value+'×');option.value=String(value);if(value===1)option.selected=true;speed.appendChild(option);});
    var pip=el('button','stalker-studio-control','Picture in picture');
    var full=el('button','stalker-studio-control','⛶ Fullscreen');
    tools.appendChild(progress);
    var row=el('div','stalker-studio-player-row');
    [play,back,forward,time,sound,volume,speed,pip,full].forEach(function(n){row.appendChild(n);});
    tools.appendChild(row);
    function update(){
      play.textContent=video.paused?'▶ Play':'❚❚ Pause';
      time.textContent=clock(video.currentTime)+' / '+clock(video.duration);
      if(Number.isFinite(video.duration)&&video.duration>0){
        progress.disabled=false;progress.value=String(Math.round(video.currentTime/video.duration*1000));
      }else{progress.disabled=true;progress.value='0';}
      sound.textContent=video.muted||video.volume===0?'Muted':'Volume';
      volume.value=String(video.muted?0:video.volume);
    }
    function toggle(){if(video.paused){var p=video.play();if(p&&p.catch)p.catch(function(){});}else video.pause();}
    play.addEventListener('click',toggle);
    video.addEventListener('click',toggle);
    back.addEventListener('click',function(){if(Number.isFinite(video.duration))video.currentTime=Math.max(0,video.currentTime-10);});
    forward.addEventListener('click',function(){if(Number.isFinite(video.duration))video.currentTime=Math.min(video.duration,video.currentTime+10);});
    progress.addEventListener('input',function(){if(Number.isFinite(video.duration)&&video.duration>0)video.currentTime=Number(progress.value)/1000*video.duration;});
    sound.addEventListener('click',function(){video.muted=!video.muted;update();});
    volume.addEventListener('input',function(){video.muted=false;video.volume=Number(volume.value);});
    speed.addEventListener('change',function(){video.playbackRate=Number(speed.value);});
    if(!document.pictureInPictureEnabled||typeof video.requestPictureInPicture!=='function')pip.hidden=true;
    pip.addEventListener('click',function(){
      var p=document.pictureInPictureElement?document.exitPictureInPicture():video.requestPictureInPicture();
      if(p&&p.catch)p.catch(function(){});
    });
    if(!wrap.requestFullscreen&&!video.webkitEnterFullscreen)full.hidden=true;
    full.addEventListener('click',function(){
      if(document.fullscreenElement){if(document.exitFullscreen)document.exitFullscreen();}
      else if(wrap.requestFullscreen)wrap.requestFullscreen().catch(function(){});
      else if(video.webkitEnterFullscreen)video.webkitEnterFullscreen();
    });
    ['play','pause','timeupdate','durationchange','volumechange','loadedmetadata','ended'].forEach(function(event){video.addEventListener(event,update);});
    wrap.addEventListener('keydown',function(event){
      if(/^(INPUT|SELECT|BUTTON)$/.test(document.activeElement.tagName))return;
      if(event.key===' '||event.key.toLowerCase()==='k'){event.preventDefault();toggle();}
      if(event.key==='ArrowLeft'&&Number.isFinite(video.duration)){event.preventDefault();video.currentTime=Math.max(0,video.currentTime-5);}
      if(event.key==='ArrowRight'&&Number.isFinite(video.duration)){event.preventDefault();video.currentTime=Math.min(video.duration,video.currentTime+5);}
    });
    wrap.tabIndex=0;update();
    current=video;
  }
  function discover(){
    queued=false;
    document.querySelectorAll('.stalker-vod-detail video,.stalker-video-detail video,.stalker-vod-player video,.stalker-player video').forEach(attach);
  }
  function schedule(){
    if(queued)return;queued=true;
    window.requestAnimationFrame?window.requestAnimationFrame(discover):setTimeout(discover,0);
  }
  new MutationObserver(schedule).observe(document.documentElement,{childList:true,subtree:true});
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',schedule);else schedule();
})();
