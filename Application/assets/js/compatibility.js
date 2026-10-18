(function(){
  // Decorative artwork must not replace the usable title when a cache file is missing.
  var images=document.getElementsByTagName('img'),art;
  for(var imageIndex=0;imageIndex<images.length;imageIndex++){
    art=images[imageIndex];
    if(art.getAttribute('data-compat-poster')){
      art.onerror=function(){this.style.display='none';};
      if(art.complete&&typeof art.naturalWidth==='number'&&art.naturalWidth===0)art.style.display='none';
    }
  }
  // Optional refresh never runs on player pages and pauses for unfinished forms.
  var refreshNode=document.getElementById('compat-refresh'),dirty=false;
  function markDirty(){dirty=true;if(refreshNode){while(refreshNode.firstChild)refreshNode.removeChild(refreshNode.firstChild);refreshNode.appendChild(document.createTextNode('Auto-refresh paused while you write. Use Refresh when ready.'));}}
  if(refreshNode && !document.getElementById('compat-media')){
    var fields=document.getElementsByTagName('textarea'),inputs=document.getElementsByTagName('input');
    for(var fieldIndex=0;fieldIndex<fields.length;fieldIndex++){fields[fieldIndex].onkeyup=markDirty;fields[fieldIndex].onchange=markDirty;fields[fieldIndex].oninput=markDirty;}
    for(var inputIndex=0;inputIndex<inputs.length;inputIndex++)if(inputs[inputIndex].type!=='hidden'){inputs[inputIndex].onchange=markDirty;inputs[inputIndex].onkeyup=markDirty;}
    setTimeout(function refreshChat(){for(var draftIndex=0;draftIndex<fields.length;draftIndex++)if(fields[draftIndex].value)dirty=true;for(var fileIndex=0;fileIndex<inputs.length;fileIndex++)if(inputs[fileIndex].type==='file'&&inputs[fileIndex].value)dirty=true;if(dirty){markDirty();return;}if(document.hidden){setTimeout(refreshChat,20000);return;}window.location.href=refreshNode.getAttribute('data-url');},20000);
  }
  function remoteNavigation(event){
    event=event||window.event;var code=event.keyCode||event.which;if(code<37||code>40)return;
    var from=document.activeElement,tag=from&&from.tagName?from.tagName.toLowerCase():'';
    if(tag==='input'||tag==='select'||tag==='option'||tag==='textarea'||tag==='video'||tag==='audio'||from&&from.isContentEditable)return;
    if(!document.documentElement.getBoundingClientRect)return;
    var anchors=document.getElementsByTagName('a'),buttons=document.getElementsByTagName('button'),nodes=[],node,rect,i;
    for(i=0;i<anchors.length;i++)nodes[nodes.length]=anchors[i];for(i=0;i<buttons.length;i++)nodes[nodes.length]=buttons[i];
    var origin=from&&from.getBoundingClientRect?from.getBoundingClientRect():null,best=null,bestScore=Infinity;
    for(i=0;i<nodes.length;i++){
      node=nodes[i];if(node===from||node.disabled||!node.getBoundingClientRect)continue;rect=node.getBoundingClientRect();
      if(rect.right<=rect.left||rect.bottom<=rect.top||rect.right<0||rect.left<0)continue;
      if(!origin||tag==='body'||tag==='html'){best=node;break;}
      var dx=(rect.left+rect.right-origin.left-origin.right)/2,dy=(rect.top+rect.bottom-origin.top-origin.bottom)/2;
      var forward=code===37?-dx:code===39?dx:code===38?-dy:dy,side=code===37||code===39?Math.abs(dy):Math.abs(dx);
      if(forward<=1)continue;var score=forward+side*3;if(score<bestScore){best=node;bestScore=score;}
    }
    if(best){best.focus();if(event.preventDefault)event.preventDefault();event.returnValue=false;}
  }
  if(document.addEventListener)document.addEventListener('keydown',remoteNavigation,false);else if(document.attachEvent)document.attachEvent('onkeydown',remoteNavigation);
  var config=window.__VC_COMPAT_PLAYER__,media=document.getElementById('compat-media');
  if(!config||!media)return;
  var message=document.getElementById('compat-player-message'),base=config.base||'/',alive=true,hls=null,roll=null,progressTimer=null,requests=[],resume=Number((config.progress||{}).position)||0,resumed=false,remux=false,loadingHls=false,recovered=false,tuning=false,hlsTimer=null;
  var started=new Date().getTime();
  function text(node,value){if(!node)return;while(node.firstChild)node.removeChild(node.firstChild);node.appendChild(document.createTextNode(value));}
  function say(value){text(message,value);}
  function listen(node,event,fn){if(node.addEventListener)node.addEventListener(event,fn,false);else if(node.attachEvent)node.attachEvent('on'+event,fn);else node['on'+event]=fn;}
  function json(path,body,done){
    if(!window.XMLHttpRequest||!window.JSON||!window.JSON.parse||(body!==null&&!window.JSON.stringify)){done('This browser cannot refresh the stream automatically. Use Retune or the channel playlist.');return;}
    var xhr,finished=false,timer;
    function finish(error,data){if(finished)return;finished=true;clearTimeout(timer);for(var i=requests.length-1;i>=0;i--)if(requests[i]===xhr)requests.splice(i,1);if(alive)done(error,data);}
    try{xhr=new XMLHttpRequest();xhr.open(body===null?'GET':'POST',base+'api'+path,true);if(body!==null)xhr.setRequestHeader('Content-Type','application/json');requests.push(xhr);
      xhr.onreadystatechange=function(){if(xhr.readyState!==4)return;var data={};if(xhr.status===204){finish(null,data);return;}try{data=window.JSON.parse(xhr.responseText);}catch(e){finish('The server returned an unreadable response.');return;}finish(xhr.status>=200&&xhr.status<300&&data.ok!==false?null:(data.error||'The stream request failed.'),data);};
      timer=setTimeout(function(){finish('The stream request timed out.');try{xhr.abort();}catch(e){}},20000);xhr.send(body===null?null:window.JSON.stringify(body));
    }catch(e){finish('This browser could not contact the server.');}
  }
  function play(){try{var promise=media.play();if(promise&&promise['catch'])promise['catch'](function(){if(alive)say('Press Play in the player to begin.');});}catch(e){say('Use the direct stream link if this browser has no inline player.');}}
  function save(final){if(config.item_id&&config.save_progress&&resumed&&isFinite(media.duration)&&media.duration>0){var body={item_id:config.item_id,position:media.currentTime||0,duration:media.duration};if(final===true&&window.JSON&&window.JSON.stringify&&navigator.sendBeacon){try{if(navigator.sendBeacon(base+'api/progress',window.JSON.stringify(body)))return;}catch(e){}}json('/progress',body,function(){});}}
  function liveURL(url){var at=String(url||'').indexOf('api.php?action=');if(at>=0){var tail=url.slice(at+15),split=tail.indexOf('&');return base+'api/live/'+(split<0?tail:tail.slice(0,split)+'?'+tail.slice(split+1));}if(url&&url.charAt(0)!=='/'&&url.indexOf('http://')!==0&&url.indexOf('https://')!==0)return base+'live/'+url;return url;}
  function retune(){
    if(!alive||!config.relay||tuning)return;tuning=true;clearTimeout(roll);var asked=new Date().getTime();
    json('/live/now?channel='+encodeURIComponent(config.relay.id),null,function(error,data){tuning=false;
      if(error||!data||!data.url){say(error||'This channel is off air.');roll=setTimeout(retune,30000);return;}
      config.url=liveURL(data.url);config.relay.seek=(Number(data.seekMs)||0)/1000;started=asked;media.src=config.url;play();say(data.title||'Live');
      var remaining=Number(data.endUnix)-Number(data.serverNow||new Date().getTime());if(remaining>0)roll=setTimeout(retune,Math.min(remaining+1000,2147483647));
    });
  }
  function hlsError(){say('This browser cannot play this HLS stream. Use Open stream directly in a compatible media player.');}
  function beginHls(){
    if(!alive||loadingHls||hls)return;
    if(!window.MediaSource&&!window.ManagedMediaSource){hlsError();return;}
    if(!window.Promise||!window.JSON){hlsError();return;}loadingHls=true;
    function ready(){
      if(!alive||hls)return;clearTimeout(hlsTimer);loadingHls=false;
      try{if(!window.Hls||!window.Hls.isSupported()){hlsError();return;}hls=new window.Hls({enableWorker:false,lowLatencyMode:false,maxBufferLength:15,maxMaxBufferLength:30});
        hls.on(window.Hls.Events.MANIFEST_PARSED,play);hls.on(window.Hls.Events.ERROR,function(event,data){if(!alive||!data||!data.fatal)return;if(data.type===window.Hls.ErrorTypes.MEDIA_ERROR&&!recovered){recovered=true;hls.recoverMediaError();return;}hlsError();});hls.loadSource(config.url);hls.attachMedia(media);
      }catch(e){hlsError();}
    }
    if(window.Hls){ready();return;}var script=document.createElement('script');script.src=config.hls_url;script.onload=ready;script.onreadystatechange=function(){if(script.readyState==='loaded'||script.readyState==='complete'){script.onreadystatechange=null;if(loadingHls)ready();}};script.onerror=function(){clearTimeout(hlsTimer);loadingHls=false;hlsError();};hlsTimer=setTimeout(function(){if(alive&&loadingHls)hlsError();},20000);document.getElementsByTagName('head')[0].appendChild(script);
  }
  function cleanup(){if(!alive)return;clearInterval(progressTimer);clearTimeout(roll);clearTimeout(hlsTimer);if(hls)try{hls.destroy();}catch(e){}var pending=requests;requests=[];for(var i=0;i<pending.length;i++)try{pending[i].abort();}catch(e){}save(true);alive=false;}
  if(typeof media.play!=='function'||typeof media.canPlayType!=='function'){say('This browser has no usable inline player. Open the stream directly.');return;}
  var centrePlay=document.getElementById('compat-centre-play'),centreLabel=document.getElementById('compat-centre-label');
  function updateCentrePlay(){
    if(!centrePlay)return;
    centrePlay.style.display=(media.paused||media.ended)?'block':'none';
    var caption=media.ended?'Replay':(media.currentTime>0?'Resume':'Play');
    text(centreLabel,caption);centrePlay.setAttribute('aria-label',caption+' video');
  }
  if(centrePlay){
    listen(centrePlay,'click',function(){play();});
    var centreEvents=['play','playing','pause','ended','loadedmetadata','emptied'];
    for(var centreIndex=0;centreIndex<centreEvents.length;centreIndex++)listen(media,centreEvents[centreIndex],updateCentrePlay);
    updateCentrePlay();
  }
  var duration=Number((config.progress||{}).duration)||0;if(duration&&resume>duration-10)resume=0;resumed=resume<5;
  var subtitleSelect=document.getElementById('compat-subtitles'),subtitleControls=document.getElementById('compat-subtitle-controls');
  if(subtitleSelect&&media.textTracks&&subtitleControls){
    subtitleControls.style.display='block';
    listen(subtitleSelect,'change',function(){try{for(var trackIndex=0;trackIndex<media.textTracks.length;trackIndex++)media.textTracks[trackIndex].mode=trackIndex===Number(subtitleSelect.value)?'showing':'disabled';}catch(trackError){say('Use the native player controls to choose subtitles.');}});
  }
  listen(media,'loadedmetadata',function(){
    if(!alive)return;
    if(config.relay){try{media.currentTime=(Number(config.relay.seek)||0)+(new Date().getTime()-started)/1000;}catch(e){}}
    else if(!resumed&&resume>=5){try{media.currentTime=resume;}catch(e){resumed=true;say('Resume is unavailable; playback starts from the beginning.');}}
    if(config.autoplay)play();
  });
  listen(media,'seeked',function(){resumed=true;});listen(media,'pause',save);
  listen(media,'ended',function(){save();if(config.relay)retune();});
  listen(media,'error',function(){if(config.hls&&!remux){beginHls();return;}say('The browser could not play this format. Try Open compatible stream when available, or use the direct stream link.');});
  if(config.item_id&&config.save_progress&&window.JSON&&window.XMLHttpRequest)progressTimer=setInterval(save,20000);
  if(config.relay&&config.relay.end>new Date().getTime())roll=setTimeout(retune,Math.min(config.relay.end-new Date().getTime()+1000,2147483647));
  if(config.hls){var nativeHls=false;try{nativeHls=!!(media.canPlayType('application/vnd.apple.mpegurl')||media.canPlayType('application/x-mpegURL'));}catch(e){}if(!nativeHls)beginHls();}
  var links=document.getElementsByTagName('a');for(var i=0;i<links.length;i++)if(links[i].getAttribute('data-compat-remux')){
    links[i].onclick=function(event){event=event||window.event;if(!config.remux_url)return true;save();resume=Number(media.currentTime)||resume||0;resumed=resume<5;remux=true;media.src=config.remux_url;play();if(event.preventDefault)event.preventDefault();event.returnValue=false;return false;};
  }
  listen(window,'pagehide',cleanup);listen(window,'unload',cleanup);
})();
