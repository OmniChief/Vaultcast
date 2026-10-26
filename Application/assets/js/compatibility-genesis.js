/* ES3 host for the separately downloaded, pinned JavaScript-only PicoDrive engine. */
(function(){
  window.VaultcastCompatibilityGenesis=function(config,A,load,say){
    var start=document.getElementById('compat-game-start'),stage=document.getElementById('compat-emulator-stage'),tools=document.getElementById('compat-game-tools'),timer=null,busy=false,started=false,session='',heartbeat=null,romName='game.md',controller=null,configPaused=false,wasPaused=false,wasAudioRunning=false;
    var actions=[['up','Up'],['down','Down'],['left','Left'],['right','Right'],['start','Start'],['mode','Mode'],['a','A'],['b','B'],['c','C'],['x','X'],['y','Y'],['z','Z']],player={};
    for(var actionIndex=0;actionIndex<actions.length;actionIndex++)player[actions[actionIndex][0]]='Vaultcast_'+actions[actionIndex][0];
    function listen(node,event,fn){if(node.addEventListener)node.addEventListener(event,fn,false);else if(node.attachEvent)node.attachEvent('on'+event,fn);else node['on'+event]=fn;}
    function fail(error){clearTimeout(timer);busy=false;start.disabled=false;say('Legacy Genesis: '+(error.message||String(error)));}
    function report(isFinal){if(config.user.role==='guest'||!session)return;A.request('/hub/game',{item_id:config.item.id,session:session,active:!isFinal&&!document.hidden&&!configPaused,'final':!!isFinal},function(){});}
    function pauseForSettings(paused){
      if(!started)return;var audio=window.GENESIS_AUDIO_CONTEXT;
      if(paused){if(!configPaused){wasPaused=!!window.GENESIS_GAME_PAUSED;wasAudioRunning=!!(audio&&audio.state==='running');}configPaused=true;window.GENESIS_GAME_PAUSED=true;}
      else {if(!configPaused)return;configPaused=false;window.GENESIS_GAME_PAUSED=wasPaused;if(!wasPaused){window.GENESIS_LAST_FRAME_TIME=window.performance.now();window.GENESIS_FRAME_ACCUMULATOR=0;if(!window.GENESIS_ANIM_FRAME_ID&&window.runFrameGenesis)window.runFrameGenesis(window.performance.now());}}
      window.GENESIS_INPUT_STATE_1=0;window.GENESIS_INPUT_STATE_2=0;
      try{var pending=audio&&(paused?audio.suspend&&audio.suspend():wasAudioRunning&&audio.resume&&audio.resume());if(pending&&pending.then)pending.then(function(){},function(){});}catch(ignore){}
      report(false);
    }
    controller=window.VaultcastLegacyControls({system:'genesis',label:'Genesis',userId:config.user.id,stage:stage,actions:actions,isRunning:function(){return started;},send:function(action,down){if(started&&window.updateInputGenesis)window.updateInputGenesis('Vaultcast_'+action,down);},pause:pauseForSettings});
    function stop(){if(controller)controller.release();started=false;window.GENESIS_GAME_PAUSED=true;window.GENESIS_INPUT_STATE_1=0;window.GENESIS_INPUT_STATE_2=0;clearInterval(heartbeat);clearTimeout(timer);report(true);if(window.GENESIS_AUDIO_CONTEXT&&window.GENESIS_AUDIO_CONTEXT.suspend)window.GENESIS_AUDIO_CONTEXT.suspend();}
    function boot(buffer){
      if(typeof window.embedGenesis!=='function'){fail(new Error('The Genesis engine could not be executed.'));return;}
      try{
        while(stage.firstChild)stage.removeChild(stage.firstChild);
        var target=document.createElement('div');target.id='compat-genesis';target.style.width='100%';target.style.height='100%';stage.appendChild(target);
        window.embedGenesis({container:'compat-genesis',name:romName,rom:buffer,soundEnabled:true,showMobileControls:false,player1:player,player2:{up:'KeyI',down:'KeyK',left:'KeyJ',right:'KeyL',start:'KeyH',mode:'KeyV',a:'KeyB',b:'KeyN',c:'KeyM',x:'KeyU',y:'KeyO',z:'KeyP'},backText:'Back to game',cbStarted:function(){
          started=true;busy=false;clearTimeout(timer);start.style.display='none';tools.style.display='';document.getElementById('compat-game-save').innerHTML='Download save state';document.getElementById('compat-game-load').innerHTML='Load save file';document.getElementById('compat-game-quit').innerHTML='Quit game';controller.ready();if(!controller.isOpen())stage.focus();session='';for(var i=0;i<16;i++)session+=('0'+Math.floor(Math.random()*256).toString(16)).slice(-2);report(false);heartbeat=setInterval(function(){report(false);},15000);say('Genesis is running. Controller settings lets you map keys and TV buttons. Numbers 2/4/6/8 move; 5 is A.');
        }});
      }catch(error){fail(error);}
    }
    function rom(url){
      var xhr=new XMLHttpRequest();xhr.open('GET',url,true);
      try{xhr.responseType='arraybuffer';}catch(ignore){}
      var binary=xhr.responseType==='arraybuffer';if(!binary&&xhr.overrideMimeType)xhr.overrideMimeType('text/plain; charset=x-user-defined');
      xhr.onreadystatechange=function(){if(xhr.readyState!==4)return;if(xhr.status<200||xhr.status>=300){fail(new Error('The game download failed ('+xhr.status+').'));return;}
        try{var buffer=xhr.response;if(!binary){var text=xhr.responseText,bytes=new Uint8Array(text.length);for(var i=0;i<text.length;i++)bytes[i]=text.charCodeAt(i)&255;buffer=bytes.buffer;}if(!buffer||!buffer.byteLength)throw new Error('The game download was empty.');if(buffer.byteLength>33554432)throw new Error('Use an uncompressed Genesis ROM no larger than 32 MiB.');say('Starting legacy Genesis…');boot(buffer);}catch(error){fail(error);}
      };xhr.onerror=function(){fail(new Error('The game download failed.'));};xhr.send(null);
    }
    start.style.display='';start.onclick=function(){
      if(busy||started)return;controller.close();busy=true;start.disabled=true;say('Loading the legacy Genesis engine…');timer=setTimeout(function(){if(!started)fail(new Error('Startup timed out. Reload the page to retry.'));},120000);
      A.request('/items/'+encodeURIComponent(config.item.id)+'/play',{},function(error,play){if(error){fail(error);return;}if(!/^(bin|md|gen|smd|32x)$/i.test(play.ext||'')){fail(new Error('Use an uncompressed .bin, .md, .gen, .smd or .32x ROM.'));return;}
        romName=config.item.title.replace(/[^a-zA-Z0-9_.-]+/g,'_')+'.'+play.ext;
        load(config.legacyEngine,function(loadError){if(loadError){fail(loadError);return;}function ready(){if(!busy)return;if(window.picoRuntimeReady&&typeof window.embedGenesis==='function'){rom(play.url);return;}setTimeout(ready,50);}ready();});
      });
    };
    document.getElementById('compat-game-save').onclick=function(){if(started&&window.downloadStateGenesis)window.downloadStateGenesis();};
    document.getElementById('compat-game-load').onclick=function(){if(started&&window.uploadStateGenesis)window.uploadStateGenesis();};
    var controls=document.createElement('div'),buttons=[['Start','start'],['A','a'],['B','b'],['C','c'],['X','x'],['Y','y'],['Z','z']];
    for(var b=0;b<buttons.length;b++)(function(label,key){var button=document.createElement('button');button.type='button';button.appendChild(document.createTextNode(label));button.onclick=function(){controller.pulse(key);};controls.appendChild(button);})(buttons[b][0],buttons[b][1]);tools.appendChild(controls);
    document.getElementById('compat-game-quit').onclick=function(){stop();location.href=config.base+'?compat=on&view=game&id='+encodeURIComponent(config.item.id);};
    listen(window,'pagehide',stop);listen(window,'error',function(event){if(busy)fail(new Error((event.message||'Engine error')+(event.filename?' in '+event.filename:'')));});
    say('Press Start game to try the JavaScript-only Genesis player.');
  };
})();
