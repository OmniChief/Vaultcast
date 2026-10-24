(function(){
  function compatListen(node,event,fn){if(!node)return;if(node.addEventListener)node.addEventListener(event,fn,false);else if(node.attachEvent)node.attachEvent('on'+event,fn);else node['on'+event]=fn;}
  function compatText(node,value){if(!node)return;while(node.firstChild)node.removeChild(node.firstChild);node.appendChild(document.createTextNode(value));}
  var compatStyle=document.documentElement.style||{};
  if(typeof compatStyle.boxSizing==='undefined'&&typeof compatStyle.MozBoxSizing==='undefined'&&typeof compatStyle.WebkitBoxSizing==='undefined')document.body.className+=' compat-content-box';
  // CSS media queries arrived later than ES3. Keep the same card breakpoints.
  if(!window.matchMedia){
    var compatLayout=function(){var width=document.documentElement.clientWidth||document.body.clientWidth||1024;var size=width<=480?'tiny':width<=760?'small':width<=1100?'medium':'';document.body.className=document.body.className.replace(/ compat-width-[a-z]+/g,'')+(size?' compat-width-'+size:'');};
    compatLayout();compatListen(window,'resize',compatLayout);
  }
  var featureRequests=[],featuresAlive=true,featureTimers=[];
  function compatRequest(path,body,done){
    if(!featuresAlive||!window.XMLHttpRequest||!window.JSON||!window.JSON.parse||!window.JSON.stringify)return false;
    var xhr,finished=false,timer;
    function finish(error,data){if(finished)return;finished=true;clearTimeout(timer);for(var i=featureRequests.length-1;i>=0;i--)if(featureRequests[i]===xhr)featureRequests.splice(i,1);if(featuresAlive)done(error,data);}
    try{xhr=new XMLHttpRequest();xhr.open(body===null?'GET':'POST',(window.__VC_COMPAT_BASE__||'/')+'api'+path,true);if(body!==null)xhr.setRequestHeader('Content-Type','application/json');featureRequests.push(xhr);xhr.onreadystatechange=function(){if(xhr.readyState!==4)return;var data;try{data=window.JSON.parse(xhr.responseText);}catch(parseError){finish('Could not read the response.');return;}finish(xhr.status>=200&&xhr.status<300&&data.ok!==false?null:(data.error||'Request failed.'),data);};timer=setTimeout(function(){finish('Request timed out.');try{xhr.abort();}catch(abortError){}},15000);xhr.send(body===null?null:window.JSON.stringify(body));return true;}catch(requestError){finish('Could not contact the server.');return false;}
  }
  compatListen(window,'pagehide',function(){featuresAlive=false;clearTimeout(chatTimer);for(var i=0;i<featureTimers.length;i++)clearTimeout(featureTimers[i]);for(i=0;i<featureRequests.length;i++)try{featureRequests[i].abort();}catch(abortError){}});
  // Optional progressive enhancement: ordinary forms remain available without JSON/XHR.
  var chatConfig=window.__VC_COMPAT_CHAT__,chatBox=document.getElementById('compat-chat-messages'),chatForm=document.getElementById('compat-chat-compose'),chatStatus=document.getElementById('compat-chat-status'),chatBusy=false,chatTimer=null;
  if(chatConfig&&chatBox&&window.JSON&&window.XMLHttpRequest){
    function chatLink(label,params){var link=document.createElement('a'),query='?compat=on',key;for(key in params)if(params.hasOwnProperty(key))query+='&'+encodeURIComponent(key)+'='+encodeURIComponent(params[key]);link.href=(window.__VC_COMPAT_BASE__||'/')+query;link.className='compat-button';link.appendChild(document.createTextNode(label));return link;}
    function paintChat(data){
      if(!data.unchanged){var nearBottom=chatBox.scrollHeight-chatBox.scrollTop-chatBox.clientHeight<100;compatText(chatBox,'');var rows=data.messages||[];for(var i=0;i<rows.length;i++){var message=rows[i],node=document.createElement('div'),author=document.createElement('p'),text=document.createElement('p');node.className='compat-message'+(message.sender_id===chatConfig.user_id?' mine':'');author.appendChild(chatLink((message.author||{}).name||'User',{view:'profile',id:message.sender_id}));text.className='compat-message-text';text.appendChild(document.createTextNode(message.body||''));node.appendChild(author);node.appendChild(text);if(/^[a-f0-9]{32}$/.test(message.attachment||'')){var art=document.createElement('img');art.className='compat-chat-image';art.alt='Shared image or GIF';art.src=(window.__VC_COMPAT_BASE__||'/')+'api/hub/chat/files/'+message.attachment;node.appendChild(art);}if(message.sender_id===chatConfig.user_id)node.appendChild(chatLink('Edit',{view:'edit-message',id:chatConfig.id,room_view:chatConfig.view,message_id:message.id}));if(message.sender_id===chatConfig.user_id||chatConfig.can_manage)node.appendChild(chatLink('Delete',{view:'delete-message',id:chatConfig.id,room_view:chatConfig.view,message_id:message.id}));chatBox.appendChild(node);}if(nearBottom)chatBox.scrollTop=chatBox.scrollHeight;chatConfig.revision=data.room.revision;if(rows.length)chatConfig.read_id=rows[rows.length-1].id;}
      var people=data.members||[],online=0,typing=[];for(var personIndex=0;personIndex<people.length;personIndex++){if(people[personIndex].online)online++;if(people[personIndex].typing&&people[personIndex].id!==chatConfig.user_id)typing[typing.length]=people[personIndex].name;}compatText(chatStatus,typing.length?typing.join(', ')+' typing…':'Live updates · '+online+' online');
    }
    var chatPulseAt=0;
    function pollChat(){if(!featuresAlive)return;if(document.hidden){chatTimer=setTimeout(pollChat,10000);return;}if(chatBusy){chatTimer=setTimeout(pollChat,3000);return;}chatBusy=true;compatRequest('/hub/chat/'+encodeURIComponent(chatConfig.id)+'?revision='+encodeURIComponent(chatConfig.revision),null,function(error,data){chatBusy=false;if(error)compatText(chatStatus,error);else{paintChat(data);var now=new Date().getTime();if(now-chatPulseAt>20000){chatPulseAt=now;compatRequest('/hub/chat/presence',{},function(){});compatRequest('/hub/chat/'+encodeURIComponent(chatConfig.id),{action:'pulse',read_id:chatConfig.read_id||0},function(){});}}chatTimer=setTimeout(pollChat,error?10000:3000);});}
    if(chatForm)compatListen(chatForm,'submit',function(event){var files=chatForm.getElementsByTagName('input'),textareas=chatForm.getElementsByTagName('textarea'),nonce=null,i;for(i=0;i<files.length;i++){if(files[i].type==='file'&&files[i].value)return;if(files[i].name==='nonce')nonce=files[i];}if(!textareas.length||!nonce)return;event=event||window.event;if(event.preventDefault)event.preventDefault();event.returnValue=false;if(chatForm._busy)return;chatForm._busy=true;var draft=textareas[0].value;compatRequest('/hub/chat/'+encodeURIComponent(chatConfig.id),{action:'send',text:draft,nonce:nonce.value},function(error){chatForm._busy=false;if(error){compatText(chatStatus,error);return;}if(textareas[0].value===draft)textareas[0].value='';var token='';for(var k=0;k<32;k++)token+=Math.floor(Math.random()*16).toString(16);nonce.value=token;chatConfig.revision=-1;});});
    pollChat();
  }
  // Search suggestions are debounced and never replace the normal search form.
  if(window.JSON&&window.XMLHttpRequest){var searchForms=document.getElementsByTagName('form');for(var searchIndex=0;searchIndex<searchForms.length;searchIndex++)if(searchForms[searchIndex].className==='compat-search'){
    (function(form){var fields=form.getElementsByTagName('input'),input=null,view='',i;for(i=0;i<fields.length;i++){if(fields[i].name==='q')input=fields[i];if(fields[i].name==='view')view=fields[i].value;}if(!input||view!=='search')return;var timerIndex=featureTimers.length;featureTimers[timerIndex]=null;var results=document.createElement('div'),timer=null,sequence=0;results.className='compat-suggestions';results.setAttribute('role','status');form.appendChild(results);compatListen(input,'keyup',function(){clearTimeout(timer);var current=++sequence,value=input.value;if(value.length<2){compatText(results,'');return;}timer=setTimeout(function(){compatRequest('/search?suggest=1&area=library&q='+encodeURIComponent(value),null,function(error,data){if(current!==sequence)return;compatText(results,'');if(error)return;var rows=data.catalogue||[];for(var j=0;j<rows.length;j++){if(rows[j].media_kind==='rom')continue;var link=document.createElement('a');link.className='compat-button';link.href=(window.__VC_COMPAT_BASE__||'/')+'?compat=on&view='+(rows[j].show_key?'show':'item')+'&id='+encodeURIComponent(rows[j].show_key||rows[j].id);link.appendChild(document.createTextNode(rows[j].title));results.appendChild(link);}});},350);featureTimers[timerIndex]=timer;});})(searchForms[searchIndex]);
  }}
  var batchContinue=document.getElementById('compat-batch-continue');if(batchContinue)featureTimers[featureTimers.length]=setTimeout(function(){if(featuresAlive)batchContinue.submit();},1200);
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
  if(refreshNode && !document.getElementById('compat-media') && !(chatConfig&&window.JSON&&window.XMLHttpRequest)){
    var fields=document.getElementsByTagName('textarea'),inputs=document.getElementsByTagName('input');
    for(var fieldIndex=0;fieldIndex<fields.length;fieldIndex++){fields[fieldIndex].onkeyup=markDirty;fields[fieldIndex].onchange=markDirty;fields[fieldIndex].oninput=markDirty;}
    for(var inputIndex=0;inputIndex<inputs.length;inputIndex++)if(inputs[inputIndex].type!=='hidden'){inputs[inputIndex].onchange=markDirty;inputs[inputIndex].onkeyup=markDirty;}
    setTimeout(function refreshChat(){for(var draftIndex=0;draftIndex<fields.length;draftIndex++)if(fields[draftIndex].value)dirty=true;for(var fileIndex=0;fileIndex<inputs.length;fileIndex++)if(inputs[fileIndex].type==='file'&&inputs[fileIndex].value)dirty=true;if(dirty){markDirty();return;}if(document.hidden){setTimeout(refreshChat,20000);return;}window.location.href=refreshNode.getAttribute('data-url');},20000);
  }
  // ES3 spatial navigation for keyboards and browser-based TV remotes.
  var remoteField=null,remoteEditing=null,remoteOKDown=false;
  function remoteTag(node){return node&&node.tagName?node.tagName.toLowerCase():'';}
  function remoteStop(event){if(event.preventDefault)event.preventDefault();event.returnValue=false;}
  function remoteUsable(node){
    var tag=remoteTag(node),type=String(node.type||'').toLowerCase(),parent=node,style,rect;
    if(!node.focus||!node.getBoundingClientRect||node.disabled||type==='hidden'||node.getAttribute('aria-disabled')==='true')return false;
    if(node.getAttribute('tabindex')!==null&&node.tabIndex<0)return false;
    if(tag==='a'&&!node.getAttribute('href'))return false;
    while(parent&&parent.nodeType===1){
      style=window.getComputedStyle?window.getComputedStyle(parent,null):parent.currentStyle;
      if(parent.hidden||parent.getAttribute('hidden')!==null||parent.getAttribute('inert')!==null||(remoteTag(parent)==='fieldset'&&parent.disabled)||style&&(style.display==='none'||style.visibility==='hidden'||style.visibility==='collapse'))return false;
      parent=parent.parentNode;
    }
    rect=node.getBoundingClientRect();return rect.right>rect.left&&rect.bottom>rect.top;
  }
  function remoteNative(node){
    var tag=remoteTag(node),type=String(node&&node.type||'').toLowerCase();
    return tag==='textarea'||tag==='select'||tag==='video'||tag==='audio'||node&&node.isContentEditable||tag==='input'&&!/^(button|submit|reset|checkbox|radio|image)$/.test(type);
  }
  function remoteFocus(node){
    node.focus();if(document.activeElement!==node)return false;
    remoteField=remoteNative(node)?node:null;remoteEditing=null;
    var rect=node.getBoundingClientRect(),height=document.documentElement.clientHeight||document.body.clientHeight,width=document.documentElement.clientWidth||document.body.clientWidth;
    if(node.scrollIntoView&&(rect.top<0||rect.bottom>height||rect.left<0||rect.right>width))node.scrollIntoView(rect.top<0);
    return true;
  }
  function remoteNavigation(event){
    event=event||window.event;
    if(event.defaultPrevented||event.returnValue===false||event.altKey||event.ctrlKey||event.metaKey||event.shiftKey||event.isComposing)return;
    var key=event.key,code=event.keyCode||event.which;
    if(key==='ArrowLeft'||key==='Left')code=37;else if(key==='ArrowUp'||key==='Up')code=38;else if(key==='ArrowRight'||key==='Right')code=39;else if(key==='ArrowDown'||key==='Down')code=40;
    else if(key==='Enter'||key==='Accept'||key==='Select'||key==='OK'||code===23)code=13;
    var from=document.activeElement,tag=remoteTag(from),nativeControl=remoteNative(from),ok=code===13;
    if((code===27||key==='Escape')&&remoteEditing===from){remoteEditing=null;remoteField=from;remoteStop(event);return;}
    if(ok){
      if(nativeControl){
        if(remoteField===from){remoteField=null;remoteEditing=from;if(tag==='textarea'||tag==='input'&&String(from.type||'').toLowerCase()!=='file')remoteStop(event);}
        return;
      }
      if(from&&remoteUsable(from)&&typeof from.click==='function'){
        remoteStop(event);
        if(!remoteOKDown&&!event.repeat){remoteOKDown=true;from.click();}
      }
      return;
    }
    if(code<37||code>40)return;
    if(nativeControl&&remoteField!==from)return;
    if(!document.documentElement.getBoundingClientRect)return;
    var tags=['a','button','input','select','textarea','video','audio'],nodes=[],node,rect,i,j;
    // Collect afresh so live suggestions and changing player controls participate.
    for(i=0;i<tags.length;i++){var matches=document.getElementsByTagName(tags[i]);for(j=0;j<matches.length;j++)nodes[nodes.length]=matches[j];}
    var origin=from&&remoteUsable(from)?from.getBoundingClientRect():null,best=null,bestScore=Infinity,visibleBest=null,visibleScore=Infinity;
    var horizontal=code===37||code===39,height=document.documentElement.clientHeight||document.body.clientHeight,width=document.documentElement.clientWidth||document.body.clientWidth;
    for(i=0;i<nodes.length;i++){
      node=nodes[i];if(node===from||!remoteUsable(node))continue;rect=node.getBoundingClientRect();
      if(!origin||tag==='body'||tag==='html'){
        // Start at the top-left visible control, rather than an offscreen skip link.
        var initialScore=rect.top*width+rect.left;
        if(rect.bottom>0&&rect.top<height&&rect.right>0&&rect.left<width&&initialScore<visibleScore){visibleBest=node;visibleScore=initialScore;}
        if(initialScore<bestScore){best=node;bestScore=initialScore;}continue;
      }
      var dx=(rect.left+rect.right-origin.left-origin.right)/2,dy=(rect.top+rect.bottom-origin.top-origin.bottom)/2;
      var forward=code===37?-dx:code===39?dx:code===38?-dy:dy;
      if(forward<=1)continue;
      var gap=horizontal?Math.max(0,rect.top-origin.bottom,origin.top-rect.bottom):Math.max(0,rect.left-origin.right,origin.left-rect.right);
      var side=horizontal?Math.abs(dy):Math.abs(dx),score=forward+gap*4+side*.25;
      if(score<bestScore){best=node;bestScore=score;}
    }
    if(visibleBest)best=visibleBest;
    if(best)remoteFocus(best);
    // Suppress browser scrolling even at the edge of the focusable layout.
    remoteStop(event);
  }
  compatListen(document,'keydown',remoteNavigation);
  compatListen(document,'keyup',function(){remoteOKDown=false;});
  compatListen(window,'blur',function(){remoteOKDown=false;});
  compatListen(document,'mousedown',function(){remoteField=null;remoteEditing=null;});
  var config=window.__VC_COMPAT_PLAYER__,media=document.getElementById('compat-media');
  if(!config||!media){var openPlay=document.getElementById('compat-open-play');if(openPlay)openPlay.submit();return;}
  var message=document.getElementById('compat-player-message'),base=config.base||'/',alive=true,hls=null,roll=null,progressTimer=null,requests=[],resume=Number((config.progress||{}).position)||0,resumed=false,remux=false,loadingHls=false,recovered=false,tuning=false,hlsTimer=null,cinemaTimer=null,cinemaBusy=false,cinemaSending=false,cinemaLastPulse=0,cinemaPending=false,cinemaBuffering=false;
  if(config.episode_skip&&config.item_id&&!config.relay&&!config.cinema){
    (function(){var segments=[],requested=false,current=null,handled={},skip=document.createElement('button'),undo=document.createElement('button'),box=document.createElement('div'),previous=0;
      box.className='compat-skip-segments';box.style.display='none';skip.type=undo.type='button';text(skip,'Skip intro');text(undo,'Undo skip');undo.style.display='none';box.appendChild(skip);box.appendChild(undo);if(message&&message.parentNode)message.parentNode.insertBefore(box,message);
      function length(){var actual=Number(media.duration);return isFinite(actual)&&actual>0?actual:(Number((config.progress||{}).duration)||0);}
      function update(){if(!alive||remux){box.style.display='none';return;}var at=Number(media.currentTime)||0;current=null;for(var i=0;i<segments.length;i++){var part=segments[i];if(at>=part.start&&at<part.end-.25&&part.end<=length()+1){current=part;break;}}box.style.display=current||undo.style.display!=='none'?'block':'none';skip.style.display=current?'inline-block':'none';if(!current)return;text(skip,current.kind==='intro'?'Skip intro':'Skip credits');var id=current.kind+':'+current.start;if(!media.paused&&!handled[id]&&(current.kind==='intro'?config.auto_skip_intro:config.auto_skip_outro))perform();}
      function perform(){if(!current||remux)return;var part=current;handled[part.kind+':'+part.start]=true;previous=Number(media.currentTime)||0;try{media.currentTime=Math.min(part.end,Math.max(0,length()-.05));undo.style.display='inline-block';}catch(seekError){}update();}
      skip.onclick=perform;undo.onclick=function(){try{media.currentTime=previous;}catch(seekError){}undo.style.display='none';update();};
      function loadSegments(){if(requested||remux||!isFinite(length())||length()<=0)return;requested=true;json('/items/'+encodeURIComponent(config.item_id)+'/segments?duration='+Math.round(length()),null,function(error,data){if(error||!data||remux)return;segments=data.segments||[];update();});}listen(media,'playing',loadSegments);listen(media,'durationchange',function(){if(!media.paused)loadSegments();});listen(media,'timeupdate',update);listen(media,'seeked',update);
    }());
  }
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
  function cleanup(){if(!alive)return;clearInterval(progressTimer);clearTimeout(cinemaTimer);clearTimeout(roll);clearTimeout(hlsTimer);if(hls)try{hls.destroy();}catch(e){}var pending=requests;requests=[];for(var i=0;i<pending.length;i++)try{pending[i].abort();}catch(e){}save(true);alive=false;}
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
  if(config.cinema&&window.JSON&&window.XMLHttpRequest){
    var roomConfig=config.cinema,roomStatus=document.getElementById('compat-cinema-status'),peopleNode=document.getElementById('compat-cinema-people'),messagesNode=document.getElementById('compat-cinema-messages'),roomPeople='',roomMessages='',hostReady=false;
    if(!roomConfig.host)try{media.controls=false;}catch(controlError){}
    function roomSend(playback){
      if(!alive)return;if(cinemaSending){if(playback)cinemaPending=true;return;}cinemaSending=true;
      var body=playback&&roomConfig.host?{action:'playback',position:media.currentTime||0,playing:!media.paused&&!media.ended&&!cinemaBuffering}:{};
      json('/hub/rooms/'+roomConfig.id,body,function(error){cinemaSending=false;if(error)text(roomStatus,error);if(cinemaPending){cinemaPending=false;roomSend(true);}});
    }
    function roomPaint(room){
      var members=room.members||[],lines=room.messages||[],signature=window.JSON.stringify(members),i,node;
      if(signature!==roomPeople){roomPeople=signature;text(peopleNode,'');for(i=0;i<members.length;i++){node=document.createElement('span');node.className='compat-person';node.appendChild(document.createTextNode(members[i].name||'Viewer'));peopleNode.appendChild(node);}}
      signature=window.JSON.stringify(lines);if(signature!==roomMessages){roomMessages=signature;text(messagesNode,'');for(i=Math.max(0,lines.length-30);i<lines.length;i++){node=document.createElement('p');node.appendChild(document.createTextNode(((lines[i].user||{}).name||'Viewer')+': '+(lines[i].text||'')));messagesNode.appendChild(node);}}
      text(roomStatus,roomConfig.host?'You control playback.':(room.playing?'Watching with the host.':'The host has paused playback.'));
      if(!roomConfig.host&&media.readyState>=1){var position=Number(room.position)||0;if(room.playing)position+=Math.max(0,Number(room.server_time)-Number(room.updated_at));if(Math.abs((media.currentTime||0)-position)>2.5)try{media.currentTime=position;}catch(seekError){}if(room.playing&&media.paused)play();else if(!room.playing&&!media.paused)try{media.pause();}catch(pauseError){}}
    }
    function roomPoll(){
      if(!alive)return;if(document.hidden){if(roomConfig.host&&new Date().getTime()-cinemaLastPulse>30000){cinemaLastPulse=new Date().getTime();roomSend(false);}cinemaTimer=setTimeout(roomPoll,10000);return;}if(cinemaBusy){cinemaTimer=setTimeout(roomPoll,3000);return;}cinemaBusy=true;
      json('/hub/rooms/'+roomConfig.id,null,function(error,data){cinemaBusy=false;if(error){text(roomStatus,error);cinemaTimer=setTimeout(roomPoll,10000);return;}roomPaint(data.room);var now=new Date().getTime();if(now-cinemaLastPulse>15000){cinemaLastPulse=now;roomSend(false);}cinemaTimer=setTimeout(roomPoll,3000);});
    }
    listen(media,'loadedmetadata',function(){if(hostReady)return;hostReady=true;if(roomConfig.host){try{media.currentTime=Number(roomConfig.position)||0;}catch(initialSeekError){}roomSend(false);}});
    if(roomConfig.host){listen(media,'play',function(){roomSend(true);});listen(media,'pause',function(){roomSend(true);});listen(media,'seeked',function(){roomSend(true);});listen(media,'ended',function(){roomSend(true);});listen(media,'waiting',function(){cinemaBuffering=true;roomSend(true);});listen(media,'playing',function(){cinemaBuffering=false;roomSend(true);});}
    var composeRoom=document.getElementById('compat-cinema-compose');if(composeRoom)listen(composeRoom,'submit',function(event){
      event=event||window.event;var inputs=composeRoom.getElementsByTagName('textarea'),input=inputs[0],value=input?input.value:'';
      if(event.preventDefault)event.preventDefault();event.returnValue=false;
      if(composeRoom._busy)return;composeRoom._busy=true;json('/hub/rooms/'+roomConfig.id,{message:value},function(error){composeRoom._busy=false;if(error)text(roomStatus,error);else if(input&&input.value===value)input.value='';});
    });
    roomPoll();
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
  var nextPreference=document.getElementById('compat-auto-next');
  if(nextPreference)listen(nextPreference,'change',function(){if(config.signed&&window.JSON&&window.XMLHttpRequest)json('/me/prefs',{autoplay_next:nextPreference.checked},function(error){if(error)say('Next-play setting changed for this video, but could not be saved to your account.');});});
  listen(media,'seeked',function(){resumed=true;});listen(media,'pause',save);
  listen(media,'ended',function(){
    save(true);if(config.relay){retune();return;}
    var enabled=document.getElementById('compat-auto-next'),next=document.getElementById('compat-next-play');
    if(enabled&&enabled.checked&&next&&next.form){next.disabled=true;next.form.submit();}
  });
  listen(media,'error',function(){if(config.hls&&!remux){beginHls();return;}say('The browser could not play this format. Try Open compatible stream when available, or use the direct stream link.');});
  if(config.item_id&&config.save_progress&&window.JSON&&window.XMLHttpRequest)progressTimer=setInterval(save,20000);
  if(config.relay&&config.relay.end>new Date().getTime())roll=setTimeout(retune,Math.min(config.relay.end-new Date().getTime()+1000,2147483647));
  if(config.hls){var nativeHls=false;try{nativeHls=!!(media.canPlayType('application/vnd.apple.mpegurl')||media.canPlayType('application/x-mpegURL'));}catch(e){}if(!nativeHls)beginHls();}
  var links=document.getElementsByTagName('a');for(var i=0;i<links.length;i++)if(links[i].getAttribute('data-compat-remux')){
    links[i].onclick=function(event){event=event||window.event;if(!config.remux_url)return true;save();resume=Number(media.currentTime)||resume||0;resumed=resume<5;remux=true;media.src=config.remux_url;play();if(event.preventDefault)event.preventDefault();event.returnValue=false;return false;};
  }
  listen(window,'pagehide',cleanup);listen(window,'unload',cleanup);
})();
