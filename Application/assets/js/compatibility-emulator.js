/* Standalone EmulatorJS host. Uses the same server saves and pending-save queue as the full UI. */
(function(){
  'use strict';
  window.VaultcastCompatibilityEmulator=function(config,A,load,say){
    var item=config.item,stage=document.getElementById('compat-emulator-stage'),start=document.getElementById('compat-game-start'),tools=document.getElementById('compat-game-tools'),saves=document.getElementById('compat-game-saves'),generation=0,heartbeat=null,timeout=null,session='',leaving=false,loading=false;
    window.VaultcastDependencies=config.dependencies;
    function each(rows,fn){for(var i=0;i<rows.length;i++)fn(rows[i],i);}
    function listen(node,event,fn){if(node.addEventListener)node.addEventListener(event,fn,false);else if(node.attachEvent)node.attachEvent('on'+event,fn);else node['on'+event]=fn;}
    function integer(n){return typeof n==='number'&&isFinite(n)&&Math.floor(n)===n&&Math.abs(n)<=9007199254740991;}

    function fail(error){loading=false;clearTimeout(timeout);start.disabled=false;start.style.display='';say(error.message||String(error));}
    function report(isFinal){if(config.user.role==='guest'||!session||!window.Promise||!window.fetch)return null;return A.api('/hub/game',{method:'POST',body:{item_id:item.id,session:session,active:!isFinal&&!document.hidden,'final':!!isFinal}})['catch'](function(){});}
    function dispose(){
      clearInterval(heartbeat);clearTimeout(timeout);var emu=window.EJS_emulator;if(!emu)return;
      if(emu._cloudStateLoad)emu._cloudStateLoad.done(new Error('Game closed before the save loaded.'));
      emu._streamDisposed=true;
      // Remove beforeunload too: firing exit twice would unmount the save filesystem twice.
      each(emu.listeners||[],function(listener){try{listener.elem.removeEventListener(listener.listener,listener.cb);}catch(ignore){}});emu.listeners=[];
      try{if(typeof emu.pause==='function')emu.pause(true);if(typeof emu.mute==='function')emu.mute();}catch(ignore2){}
      try{if(emu.gameManager)emu.gameManager.toggleMainLoop(0);if(emu.Module&&emu.Module.pauseMainLoop)emu.Module.pauseMainLoop();}catch(ignore3){}
      function close(context){if(context&&typeof context.close==='function'&&context.state!=='closed')context.close()['catch'](function(){});}
      try{var al=emu.Module&&emu.Module.AL,contexts=[],key;if(al)for(key in al.contexts)if(Object.prototype.hasOwnProperty.call(al.contexts,key))contexts.push(al.contexts[key]);if(al&&al.currentCtx)contexts.push(al.currentCtx);each(contexts,function(ctx){close(ctx.ctx||ctx.audioCtx||ctx.context);each(ctx.sources||[],function(source){if(source&&source.gain)close(source.gain.context);});});close(emu.audioContext);}catch(ignore4){}
      try{if(typeof emu.callEvent==='function')emu.callEvent('exit');}catch(ignore5){}
      window.EJS_emulator=undefined;while(stage.firstChild)stage.removeChild(stage.firstChild);tools.style.display='none';
    }
    function hash(bytes){var h=2166136261;for(var i=0;i<bytes.length;i++){h^=bytes[i];h+=(h<<1)+(h<<4)+(h<<7)+(h<<8)+(h<<24);}return bytes.length+':'+(h>>>0);}
    function readState(emu,maximum,read){
      var info=emu.gameManager.functions.saveStateInfo().split('|');if(info[2]!=='1')throw new Error('Could not save emulator state: '+info[0]);
      var length=Number(info[0]),address=Number(info[1]),heap=emu.Module.HEAPU8;
      if(!heap||!integer(address)||address<=0||!integer(length)||length<=0||length>maximum||length>heap.length||address>heap.length-length)throw new Error('Invalid save state size or address (maximum 32 MiB).');
      return read(heap.subarray(address,address+length));
    }
    function observe(emu,message){var state=emu._cloudStateLoad;if(!state||message.indexOf(state.path)<0)return;if(/failed|error|not supported|invalid/i.test(message))state.nativeError='The emulator rejected this save state. Check the game, core and version.';else if(/\[State\].*Loading state.*[0-9]+.*bytes/i.test(message))state.nativeApplied=true;}
    function finishLoad(emu){var state=emu._cloudStateLoad;if(!state)return;if(state.nativeError){state.done(new Error(state.nativeError));return;}if(!state.closed)return;if(state.nativeApplied){state.done();return;}if((state.attempts||0)>=3)return;state.attempts=(state.attempts||0)+1;try{if(readState(emu,33554432,hash)===state.hash)state.done();}catch(e){state.done(e);}}
    function bridge(emu){
      if(emu._compatBridge)return;emu._compatBridge=true;var init=emu.initModule;
      emu.initModule=function(){var runtime=window.EJS_Runtime;if(typeof runtime!=='function')return init.apply(this,arguments);
        window.EJS_Runtime=function(options){each(['print','printErr'],function(key){var prev=options[key];options[key]=function(message){observe(emu,String(message));if(typeof prev==='function')prev.apply(this,arguments);};});var previous=options.postMainLoop;options.postMainLoop=function(){if(emu._streamDisposed)return;if(typeof previous==='function')previous();finishLoad(emu);};var result=runtime.apply(this,arguments);function verbose(module){if(module&&typeof module.callMain==='function'){var call=module.callMain;module.callMain=function(args){args=(args||[]).slice();var verboseFlag=false;for(var i=0;i<args.length;i++)if(args[i]==='-v')verboseFlag=true;if(!verboseFlag)args.unshift('-v');return call.call(this,args);};}return module;}return result&&typeof result.then==='function'?result.then(verbose):verbose(result);};
        try{return init.apply(this,arguments);}finally{window.EJS_Runtime=runtime;}
      };
    }
    A.captureGameState=function(emu,maximum){return readState(emu,maximum,function(bytes){return new Uint8Array(bytes);});};
    A.loadGameState=function(emu,bytes){return new Promise(function(resolve,reject){
      if(emu!==window.EJS_emulator||emu._streamDisposed||!emu.started||emu._cloudStateLoad){reject(new Error('Game is not ready to load a save state.'));return;}
      var fs=emu.gameManager.FS,state={path:'/vaultcast-cloud.state',previousClose:fs.close,closed:false,hash:hash(bytes)};
      state.done=function(error){if(emu._cloudStateLoad!==state)return;clearTimeout(state.timer);if(fs.close===state.close)fs.close=state.previousClose;try{fs.unlink(state.path);}catch(ignore){}emu._cloudStateLoad=null;if(error){reject(error);return;}if(typeof emu.play==='function')emu.play(true);emu.gameManager.functions.toggleMainLoop(1);resolve();};
      state.close=function(stream){var path=stream&&stream.path,result=state.previousClose.apply(this,arguments);if(path===state.path||path===state.path.slice(1))state.closed=true;return result;};
      try{fs.writeFile(state.path,bytes);emu._cloudStateLoad=state;fs.close=state.close;emu.gameManager.clearEJSResetTimer();emu.gameManager.functions.loadState(state.path,0);emu.gameManager.functions.toggleMainLoop(1);state.timer=setTimeout(function(){state.done(new Error('The emulator did not acknowledge this save state.'));},12000);}catch(e){emu._cloudStateLoad=state;state.done(e);}
    });};
    function boot(play,preference){
      var core=play.emu_core;if(!core)throw new Error('No emulator core is available for this game.');
      if(play.bios_missing)throw new Error(play.bios_hint||'This game needs a BIOS. Add it to the server and retry.');
      dispose();var mine=++generation;stage.appendChild(A.h('div',{id:'compat-emulator',style:'width:100%;height:100%'}));
      window.EJS_player='#compat-emulator';window.EJS_core=preference?preference.core:core;window.EJS_gameUrl=play.url;window.EJS_biosUrl=play.bios_url||undefined;window.EJS_pathtodata=config.dependencies.emulatorData;window.EJS_gameName=item.title;window.EJS_gameID=String(config.user.id)+':'+String(item.id);window.EJS_startOnLoaded=false;window.EJS_forceLegacyCores=preference?preference.variant==='legacy':core!=='ppsspp'&&core!=='psp';window.EJS_threads=core==='ppsspp'||core==='psp'||!!(preference&&preference.threaded);window.EJS_disableLocalStorage=false;
      window.EJS_defaultOptions={webgl2Enabled:window.EJS_forceLegacyCores?'disabled':'enabled',ejs_threads:window.EJS_threads?'enabled':'disabled'};
      var external={};each(play.rom_sidecars||[],function(sc){external['/'+sc.name.replace(/^\/+/, '')]=sc.url;});window.EJS_externalFiles=external;
      window.EJS_Buttons={contextMenuButton:false,loadState:true,saveState:true,screenshot:true,quickSave:false,quickLoad:false,fullscreen:true,playPause:true,volume:true,mute:true,settings:true,saveSavFiles:true,loadSavFiles:true,exitEmulation:false};
      window.EJS_ready=function(){if(mine!==generation)return;var emu=window.EJS_emulator;if(!emu||emu._compatReady)return;emu._compatReady=true;bridge(emu);var button=emu.elements&&emu.elements.parent&&emu.elements.parent.querySelector('.ejs_start_button');if(button)emu.startButtonClicked(button);};
      window.EJS_onGameStart=function(){if(mine!==generation)return;var emu=window.EJS_emulator;if(!emu||emu._compatStarted)return;emu._compatStarted=true;loading=false;clearTimeout(timeout);start.style.display='none';tools.style.display='';document.getElementById('compat-game-save').disabled=config.user.role==='guest'||!window.VaultcastHomeExtras;document.getElementById('compat-game-load').disabled=config.user.role==='guest'||!window.VaultcastHomeExtras;document.getElementById('compat-game-quit').textContent=config.user.role==='guest'||!window.VaultcastHomeExtras?'Quit game':'Save & quit';say('Game ready. Use the emulator controls for keyboard, touch or gamepad settings.');stage.focus();if(window.VaultcastHomeExtras)window.VaultcastHomeExtras.gameStarted(item);session='';for(var i=0;i<16;i++)session+=('0'+Math.floor(Math.random()*256).toString(16)).slice(-2);report(false);heartbeat=setInterval(function(){report(false);},15000);};
      timeout=setTimeout(function(){if(mine!==generation||window.EJS_emulator&&window.EJS_emulator.started)return;say('The emulator is taking longer than expected. Check the game file and player assets, or reload this page to retry.');},120000);
      // The upstream loader starts with an async function. Load the pinned engine
      // directly so browsers do not have to parse that loader before trying it.
      var dataPath=config.dependencies.emulatorData;
      var style=document.createElement('link');style.rel='stylesheet';style.href=dataPath+'emulator.min.css';document.head.appendChild(style);
      say('Loading emulator.min.js…');
      load(dataPath+'emulator.min.js',function(error){
        if(mine!==generation||!loading)return;
        if(error){fail(error);return;}
        if(typeof window.EmulatorJS!=='function'){fail(new Error('emulator.min.js loaded but could not run. Check the browser console for its syntax error.'));return;}
        try{
          var emu=new window.EmulatorJS(window.EJS_player,{gameUrl:window.EJS_gameUrl,dataPath:dataPath,system:window.EJS_core,biosUrl:window.EJS_biosUrl,gameName:window.EJS_gameName,gameId:window.EJS_gameID,startOnLoad:false,externalFiles:window.EJS_externalFiles,forceLegacyCores:window.EJS_forceLegacyCores,threads:window.EJS_threads,disableLocalStorage:window.EJS_disableLocalStorage,defaultOptions:window.EJS_defaultOptions,buttonOpts:window.EJS_Buttons});
          window.EJS_emulator=emu;emu.on('ready',window.EJS_ready);emu.on('start',window.EJS_onGameStart);
          say('Starting emulator core…');
          if(emu.elements&&emu.elements.parent&&emu.elements.parent.querySelector('.ejs_start_button'))window.EJS_ready();
        }catch(startError){fail(new Error('Emulator initialization failed: '+(startError.message||String(startError))));}
      });
    }
    function launch(){
      if(loading){say('Wait for this game to finish starting.');return;}
      loading=true;start.disabled=true;say('Preparing game…');
      var pref=window.VaultcastHomeExtras?window.VaultcastHomeExtras.corePreference(item):null;
      A.request('/items/'+encodeURIComponent(item.id)+'/play',{},function(error,play){if(error){fail(error);return;}try{boot(play,pref);}catch(bootError){fail(bootError);}});
    }
    A.openGame=function(){report(true);launch();};
    function action(loadSlot){var emu=window.EJS_emulator;return window.VaultcastHomeExtras.quickSlotAction(emu,loadSlot)['catch'](fail);}
    document.getElementById('compat-game-save').onclick=function(){action(false);};document.getElementById('compat-game-load').onclick=function(){action(true);};
    document.getElementById('compat-game-quit').onclick=function(){if(leaving)return;leaving=true;if(!window.VaultcastHomeExtras||!window.Promise){report(true);dispose();location.href=config.base+'?compat=on&view=game&id='+encodeURIComponent(item.id);return;}var emu=window.EJS_emulator,pending;try{pending=emu&&emu._cloudBeforeExit?emu._cloudBeforeExit():Promise.resolve();}catch(e){leaving=false;fail(e);return;}say('Finishing your save…');Promise.resolve(pending).then(function(result){if(result===false)throw new Error('Could not capture your exit save. Save manually or download a state before leaving.');return report(true);}).then(function(){dispose();location.href=config.base+'?compat=on&view=game&id='+encodeURIComponent(item.id);})['catch'](function(e){leaving=false;fail(e);});};
    listen(window,'pagehide',function(){clearInterval(heartbeat);var emu=window.EJS_emulator;if(emu&&emu._cloudBeforeExit)emu._cloudBeforeExit();if(config.user.role!=='guest'&&session&&navigator.sendBeacon)navigator.sendBeacon(config.base+'api/hub/game',new Blob([JSON.stringify({item_id:item.id,session:session,active:false,'final':true})],{type:'application/json'}));dispose();});
    listen(document,'visibilitychange',function(){report(false);});
    listen(window,'error',function(event){if(loading&&event.message)fail(new Error('Emulator startup failed'+(event.filename?' in '+event.filename+(event.lineno?':'+event.lineno:''):'')+': '+event.message));});
    listen(window,'unhandledrejection',function(event){if(loading)fail(new Error('Emulator startup failed: '+(event.reason&&event.reason.message||String(event.reason))));});
    // Cloud saves enhance the session; they never gate an emulator launch.
    start.style.display='';start.onclick=launch;say('Press Start game to try the emulator on this device.');
    if(window.Promise&&Promise.prototype['finally']&&window.fetch&&window.URLSearchParams&&window.Element&&Element.prototype.replaceChildren&&Element.prototype.remove){
      load(config.extras,function(error){if(error)return;try{if(!window.VaultcastHomeExtras)return;window.VaultcastHomeExtras.init(A);document.dispatchEvent(new Event('vaultcast-account-ready'));window.VaultcastHomeExtras.savesPanel(saves,item);var emu=window.EJS_emulator;if(emu&&emu.started){window.VaultcastHomeExtras.gameStarted(item);document.getElementById('compat-game-save').disabled=config.user.role==='guest';document.getElementById('compat-game-load').disabled=config.user.role==='guest';document.getElementById('compat-game-quit').textContent=config.user.role==='guest'?'Quit game':'Save & quit';}}catch(ignore){}});
    }
  };
})();
