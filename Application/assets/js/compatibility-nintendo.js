/* ES3 hosts for the separately downloaded JavaScript-only FCEUmm and Snes9x engines. */
(function(){
  window.VaultcastCompatibilityNintendo=function(config,A,load,say){
    var nes=config.legacySystem==='nes';
    var player={up:'ArrowUp',down:'ArrowDown',left:'ArrowLeft',right:'ArrowRight',start:'Enter',select:'ShiftRight'};
    var other={up:'KeyI',down:'KeyK',left:'KeyJ',right:'KeyL',start:'KeyH',select:'KeyV'};
    if(nes){player.a='KeyX';player.b='KeyZ';other.a='KeyU';other.b='KeyO';}
    else {player.a='KeyW';player.b='KeyQ';player.x='KeyS';player.y='KeyA';player.l='KeyZ';player.r='KeyX';other.a='KeyU';other.b='KeyH';other.x='KeyO';other.y='KeyN';other.l='KeyP';other.r='KeyM';other.start='KeyB';}
    var spec=nes?{label:'NES',embed:'embedNintendo',save:'downloadStateNintendo',restore:'uploadStateNintendo',extensions:/^nes$/i}:{label:'SNES',embed:'embedSuperNintendo',save:'downloadStateSuperNintendo',restore:'uploadStateSuperNintendo',extensions:/^(sfc|smc)$/i};
    var start=document.getElementById('compat-game-start'),stage=document.getElementById('compat-emulator-stage'),tools=document.getElementById('compat-game-tools');
    var timer=null,heartbeat=null,busy=false,started=false,session='',stopped=false,controller=null,configPaused=false,wasPaused=false,wasAudioRunning=false;
    function listen(node,event,fn){if(node.addEventListener)node.addEventListener(event,fn,false);else if(node.attachEvent)node.attachEvent('on'+event,fn);else node['on'+event]=fn;}
    function text(node,value){while(node.firstChild)node.removeChild(node.firstChild);node.appendChild(document.createTextNode(value));}
    function report(isFinal){if(config.user.role==='guest'||!session)return;A.request('/hub/game',{item_id:config.item.id,session:session,active:!isFinal&&!document.hidden&&!configPaused,'final':!!isFinal},function(){});}
    function stop(){
      if(controller)controller.release();started=false;clearTimeout(timer);clearInterval(heartbeat);busy=false;
      if(nes){window.NINTENDO_RUNNING=false;window.NINTENDO_PAUSED=true;window.NINTENDO_KEYSTATE1={};window.NINTENDO_KEYSTATE2={};if(window.stopLoopNintendo)window.stopLoopNintendo();}
      else {window.SUPERNINTENDO_GAME_RUNNING=false;window.SUPERNINTENDO_KEY_INPUT_1=0;window.SUPERNINTENDO_KEY_INPUT_2=0;}
      if(!stopped){stopped=true;report(true);}
      var audio=nes?window.NINTENDO_AUDIO_CONTEXT:window.SUPERNINTENDO_AUDIO_CONTEXT;
      try{if(audio&&audio.suspend){var pending=audio.suspend();if(pending&&pending.then)pending.then(function(){},function(){});}}catch(ignore){}
    }
    function fail(error){stop();start.disabled=false;say('Legacy '+spec.label+': '+(error.message||String(error)));}
    function input(code,down){
      if(!started)return;
      if(nes){var map=window.NINTENDO_KEYMAP1||{},key=map[code];if(key!==undefined)window.NINTENDO_KEYSTATE1[key]=down;}
      else if(window.updateInputSuperNintendo)window.updateInputSuperNintendo(code,down);
    }
    var actions=[['up','Up'],['down','Down'],['left','Left'],['right','Right'],['start','Start'],['select','Select'],['a','A'],['b','B']];
    if(!nes)actions=actions.concat([['x','X'],['y','Y'],['l','L'],['r','R']]);
    for(var actionIndex=0;actionIndex<actions.length;actionIndex++)player[actions[actionIndex][0]]='Vaultcast_'+actions[actionIndex][0];
    function pauseForSettings(paused){
      if(!started)return;var audio=nes?window.NINTENDO_AUDIO_CONTEXT:window.SUPERNINTENDO_AUDIO_CONTEXT;
      if(paused){
        if(!configPaused){wasPaused=nes?!!window.NINTENDO_PAUSED:!window.SUPERNINTENDO_GAME_RUNNING;wasAudioRunning=!!(audio&&audio.state==='running');}
        configPaused=true;
        if(nes){window.NINTENDO_PAUSED=true;if(window.stopLoopNintendo)window.stopLoopNintendo();}else window.SUPERNINTENDO_GAME_RUNNING=false;
      }else {
        if(!configPaused)return;configPaused=false;
        if(nes){window.NINTENDO_PAUSED=wasPaused;if(!wasPaused&&window.startLoopNintendo)window.startLoopNintendo(window.NINTENDO_TARGET_FPS);}
        else if(!wasPaused){window.SUPERNINTENDO_GAME_RUNNING=true;window.SUPERNINTENDO_LAST_FRAME_TIME=0;if(!window.SUPERNINTENDO_LOOP_ACTIVE&&window.requestFrameSuperNintendo){window.SUPERNINTENDO_LOOP_ACTIVE=true;window.requestAnimationFrame(window.requestFrameSuperNintendo);}}
      }
      if(nes){window.NINTENDO_KEYSTATE1={};window.NINTENDO_KEYSTATE2={};}else {window.SUPERNINTENDO_KEY_INPUT_1=0;window.SUPERNINTENDO_KEY_INPUT_2=0;}
      try{var pending=audio&&(paused?audio.suspend&&audio.suspend():wasAudioRunning&&audio.resume&&audio.resume());if(pending&&pending.then)pending.then(function(){},function(){});}catch(ignore){}
      report(false);
    }
    controller=window.VaultcastLegacyControls({system:nes?'nes':'snes',label:spec.label,userId:config.user.id,stage:stage,actions:actions,isRunning:function(){return started;},send:function(action,down){input('Vaultcast_'+action,down);},pause:pauseForSettings});
    function gameStarted(){
      if(nes&&!window.NINTENDO_RUNNING){fail(new Error('The NES engine could not start this ROM.'));return;}
      if(!nes&&!window.SUPERNINTENDO_GAME_BOOTED){fail(new Error('The SNES engine could not start this ROM.'));return;}
      started=true;busy=false;stopped=false;clearTimeout(timer);start.style.display='none';tools.style.display='';
      text(document.getElementById('compat-game-save'),'Download save state');text(document.getElementById('compat-game-load'),'Load save file');text(document.getElementById('compat-game-quit'),'Quit game');
      controller.ready();if(!controller.isOpen())stage.focus();session='';for(var i=0;i<16;i++)session+=('0'+Math.floor(Math.random()*256).toString(16)).slice(-2);
      report(false);heartbeat=setInterval(function(){report(false);},15000);
      say(spec.label+' is running. Use Controller settings to map keys, numbers and TV-remote buttons.');
    }
    function boot(buffer,name){
      try{
        if(typeof window[spec.embed]!=='function')throw new Error('The '+spec.label+' engine could not be executed.');
        while(stage.firstChild)stage.removeChild(stage.firstChild);
        var target=document.createElement('div');target.id='compat-nintendo';target.style.width='100%';target.style.height='100%';stage.appendChild(target);
        window[spec.embed]({container:'compat-nintendo',name:name,rom:buffer,soundEnabled:true,showMobileControls:false,player1:player,player2:other,backText:'Back to game',cbStarted:gameStarted});
      }catch(error){fail(error);}
    }
    function downloadROM(play){
      var xhr=new XMLHttpRequest();xhr.open('GET',play.url,true);
      try{xhr.responseType='arraybuffer';xhr.timeout=45000;}catch(ignore){}
      var binary=xhr.responseType==='arraybuffer';if(!binary&&xhr.overrideMimeType)xhr.overrideMimeType('text/plain; charset=x-user-defined');
      xhr.onreadystatechange=function(){
        if(xhr.readyState!==4||!busy)return;
        if(xhr.status<200||xhr.status>=300){fail(new Error('The game download failed ('+xhr.status+').'));return;}
        try{
          var buffer=xhr.response;
          if(!binary){var source=xhr.responseText,bytes=new Uint8Array(source.length);for(var i=0;i<source.length;i++)bytes[i]=source.charCodeAt(i)&255;buffer=bytes.buffer;}
          if(!buffer||!buffer.byteLength)throw new Error('The game download was empty.');
          if(buffer.byteLength>33554432)throw new Error('Use an uncompressed ROM no larger than 32 MiB.');
          say('Starting legacy '+spec.label+'…');
          boot(buffer,config.item.title.replace(/[^a-zA-Z0-9_.-]+/g,'_')+'.'+play.ext);
        }catch(error){fail(error);}
      };
      xhr.onerror=function(){if(busy)fail(new Error('The game download failed.'));};xhr.ontimeout=function(){if(busy)fail(new Error('The game download timed out.'));};xhr.send(null);
    }
    start.style.display='';start.onclick=function(){
      if(busy||started)return;controller.close();busy=true;stopped=false;start.disabled=true;say('Loading the legacy '+spec.label+' engine…');
      timer=setTimeout(function(){if(!started)fail(new Error('Startup timed out. Reload this page to retry.'));},120000);
      A.request('/items/'+encodeURIComponent(config.item.id)+'/play',{},function(error,play){
        if(!busy)return;if(error){fail(error);return;}
        if(!spec.extensions.test(play.ext||'')){fail(new Error(nes?'Use an uncompressed .nes ROM.':'Use an uncompressed .sfc or .smc ROM.'));return;}
        load(config.legacyEngine,function(loadError){if(!busy)return;if(loadError){fail(loadError);return;}downloadROM(play);});
      });
    };
    document.getElementById('compat-game-save').onclick=function(){if(started&&window[spec.save])window[spec.save]();};
    document.getElementById('compat-game-load').onclick=function(){if(started&&window[spec.restore])window[spec.restore]();};
    document.getElementById('compat-game-quit').onclick=function(){stop();location.href=config.base+'?compat=on&view=game&id='+encodeURIComponent(config.item.id);};
    var controls=document.createElement('div'),buttons=nes?[['Start','start'],['Select','select'],['A','a'],['B','b']]:[['Start','start'],['Select','select'],['A','a'],['B','b'],['X','x'],['Y','y'],['L','l'],['R','r']];
    for(var b=0;b<buttons.length;b++)(function(label,code){var button=document.createElement('button');button.type='button';text(button,label);button.onclick=function(){controller.pulse(code);};controls.appendChild(button);})(buttons[b][0],buttons[b][1]);tools.appendChild(controls);
    listen(window,'pagehide',stop);
    listen(window,'error',function(event){if(busy)fail(new Error((event.message||'Engine error')+(event.filename?' in '+event.filename:'')));});
    listen(window,'unhandledrejection',function(event){if(busy)fail(new Error(event.reason&&event.reason.message||String(event.reason)));});
    say('Press Start game to try the JavaScript-only '+spec.label+' player.');
  };
})();
