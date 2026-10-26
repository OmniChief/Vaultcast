/* ES3 entry point. Emulator launch is always offered; Play enhancements are optional. */
(function(){
  'use strict';
  var config=window.__VC_COMPAT_PLAY__;if(!config)return;
  var message=document.getElementById('compat-play-message'),enhance=document.getElementById('compat-play-enhance'),start=document.getElementById('compat-game-start');
  function say(text){if(message){while(message.firstChild)message.removeChild(message.firstChild);message.appendChild(document.createTextNode(text));}}
  function capable(){return !!(window.Promise&&Promise.prototype['finally']&&window.fetch&&window.URLSearchParams&&window.Map&&window.Set&&window.Uint8Array&&window.Element&&Element.prototype.replaceChildren&&Element.prototype.remove&&window.requestAnimationFrame&&window.getComputedStyle&&window.addEventListener);}
  function load(src,done){var script=document.createElement('script');script.src=src;script.onload=function(){done(null);};script.onerror=function(){done(new Error('Could not load '+src+'. Check your connection and player asset settings.'));};document.body.appendChild(script);}
  if(config.mode==='play'&&!capable()){say('The simple Play view is available. Interactive games need a newer browser.');return;}
  function listen(node,event,fn){if(node.addEventListener)node.addEventListener(event,fn,false);else if(node.attachEvent)node.attachEvent('on'+event,fn);else node['on'+event]=fn;}
  function remove(node){if(node&&node.parentNode)node.parentNode.removeChild(node);}
  function clear(node){while(node.firstChild)node.removeChild(node.firstChild);}
  function text(node,value){clear(node);node.appendChild(document.createTextNode(String(value)));}
  function h(tag,attrs,kids){
    var el=document.createElement(tag),k,v,i;attrs=attrs||{};
    for(k in attrs)if(Object.prototype.hasOwnProperty.call(attrs,k)){
      v=attrs[k];if(v===null||v===undefined||v===false)continue;
      if(k==='text')text(el,v);else if(k==='html')el.innerHTML=v;else if(k==='className')el.className=v;
      else if(/^on[A-Z]/.test(k))listen(el,k.slice(2).toLowerCase(),v);
      else if(k==='value'||k==='checked'||k==='disabled'||k==='readOnly')el[k]=v;else el.setAttribute(k,v===true?'':String(v));
    }
    kids=Object.prototype.toString.call(kids)==='[object Array]'?kids:kids==null?[]:[kids];
    for(i=0;i<kids.length;i++){k=kids[i];if(k!==null&&k!==undefined&&k!==false)el.appendChild(k.nodeType?k:document.createTextNode(String(k)));}
    return el;
  }
  function api(path,opts){opts=opts||{};var args={method:opts.method||'GET',credentials:'same-origin',headers:{'Content-Type':'application/json'}};if(opts.body!==undefined)args.body=JSON.stringify(opts.body);return fetch(config.base+'api'+path,args).then(function(r){return r.json().then(function(j){if(!r.ok||j.ok===false)throw new Error(j.error||'Request failed.');return j;});});}
  function request(path,body,done){var xhr;try{xhr=new XMLHttpRequest();xhr.open('POST',config.base+'api'+path,true);xhr.setRequestHeader('Content-Type','application/json');xhr.onreadystatechange=function(){if(xhr.readyState!==4)return;var data;try{data=JSON.parse(xhr.responseText);}catch(error){done(new Error('Could not read the game response.'));return;}if(xhr.status<200||xhr.status>=300||data.ok===false){done(new Error(data.error||'Could not prepare the game.'));return;}done(null,data);};xhr.onerror=function(){done(new Error('Could not contact the game server.'));};xhr.send(JSON.stringify(body));}catch(error){done(error);}}
  function module(file,name){return new Promise(function(resolve,reject){if(window[name]){resolve(window[name]);return;}load(config.assets&&config.assets[file]||config.base+'index.php?asset=assets/js/'+file,function(err){if(err||!window[name])reject(err||new Error('Game module unavailable.'));else resolve(window[name]);});});}
  function modal(title,build){var previous=document.activeElement,box=h('div',{className:'compat-modal',role:'dialog','aria-modal':'true','aria-label':title}),body=h('div',{className:'compat-modal-body'}),closeButton=h('button',{type:'button',text:'Close'});function close(){remove(box);if(previous&&previous.isConnected)previous.focus();}closeButton.onclick=close;box.appendChild(h('h2',{text:title}));box.appendChild(closeButton);box.appendChild(body);document.body.appendChild(box);listen(box,'keydown',function(e){if(e.key==='Escape'){e.stopPropagation();close();}if(e.key==='Tab'){var nodes=box.querySelectorAll('button,input,select,textarea,a[href],[tabindex="0"]'),first=nodes[0],last=nodes[nodes.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}});build(body,close);closeButton.focus();return box;}
  listen(window,'pageshow',function(event){if(event.persisted)location.reload();});
  window.VaultcastHub={modal:modal};window.__STREAM_BASE__=config.base;
  var app={h:h,api:api,request:request,BASE:config.base,state:{user:config.user,playTab:config.tab,playSub:config.sub||''},toast:function(text){say(text);},confirmDialog:function(text){return Promise.resolve(window.confirm(text));},pendingContent:function(o){return h('p',{text:o&&o.text||'Loading…',role:'status'});},emptyState:function(t,d,actions){return h('div',{},[h('h3',{text:t}),h('p',{text:d||''})].concat(actions||[]));},loadModule:module,apiUrl:function(url){return url.indexOf('/api/')===0?config.base+url.slice(1):url;},navigate:function(view){location.href=config.base+'?compat=on&view='+encodeURIComponent(view);},copyText:function(text){if(navigator.clipboard)return navigator.clipboard.writeText(text);window.prompt('Copy this link',text);return Promise.resolve();},shareUrl:function(path){return location.origin+config.base+path;}};
  if(config.mode==='emulator'||config.mode==='genesis-legacy'||config.mode==='nes-legacy'||config.mode==='snes-legacy'){
    say('Press Start game to try the emulator on this device.');
    // The emulator has a separate page and lifetime, so leaving it releases all game resources.
    var emuScript=config.emulatorScript;
    function hostReady(err){
      if(err){say(err.message);return;}
      try{var host=config.mode==='genesis-legacy'?window.VaultcastCompatibilityGenesis:config.mode==='nes-legacy'||config.mode==='snes-legacy'?window.VaultcastCompatibilityNintendo:window.VaultcastCompatibilityEmulator;if(typeof host!=='function')throw new Error('The emulator host could not be executed by this browser.');host(config,app,load,say);}catch(error){say('Emulator startup failed: '+(error.message||String(error)));}
    }
    function controllerReady(error){if(error){say(error.message);return;}if(config.legacyController)load(config.legacyController,function(controllerError){if(controllerError){say(controllerError.message);return;}load(emuScript,hostReady);});else load(emuScript,hostReady);}
    if(config.legacyRuntime)load(config.legacyRuntime,controllerReady);else controllerReady(null);
    return;
  }
  if(!enhance)return;enhance.style.display='';
  var interactive=document.getElementById('compat-play-interactive'),baseline=document.getElementById('compat-play-baseline');
  app.pushNav=function(){var url=config.base+'?compat=on&view=play&tab='+encodeURIComponent(app.state.playTab)+'&sub='+encodeURIComponent(app.state.playSub||'');history.replaceState(null,'',url);};
  app.render=function(){if(app.state.playTab==='achievements'){location.href=config.base+'?compat=on&view=play&tab=achievements';return;}window.VaultcastPlay.render(interactive,app.state.playTab,app.state.playSub);};
  app.goPlayTab=function(tab){app.state.playTab=tab;app.state.playSub='';app.pushNav();app.render();};
  var active=false;
  enhance.onclick=function(){if(active){window.VaultcastPlay.stop();if(app.state.playTab!==config.tab||(app.state.playSub||'')!==(config.sub||'')){location.href=config.base+'?compat=on&view=play&tab='+encodeURIComponent(app.state.playTab)+'&sub='+encodeURIComponent(app.state.playSub||'');return;}clear(interactive);baseline.style.display='';text(enhance,'Open interactive view');active=false;return;}enhance.disabled=true;say('Loading interactive Play…');var css=h('link',{rel:'stylesheet',href:config.base+'index.php?asset=assets/css/vc-extra.css'});document.head.appendChild(css);module('vc-play.js','VaultcastPlay').then(function(P){P.init(app);app.render();baseline.style.display='none';active=true;text(enhance,'Return to simple view');say('Interactive view ready.');})['catch'](function(e){say(e.message);baseline.style.display='';})['finally'](function(){enhance.disabled=false;});};
  listen(window,'pagehide',function(){if(window.VaultcastPlay)window.VaultcastPlay.stop();});
})();
