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
    var timer=null,heartbeat=null,busy=false,started=false,session='',stopped=false;
    function listen(node,event,fn){if(node.addEventListener)node.addEventListener(event,fn,false);else if(node.attachEvent)node.attachEvent('on'+event,fn);else node['on'+event]=fn;}
    function text(node,value){while(node.firstChild)node.removeChild(node.firstChild);node.appendChild(document.createTextNode(value));}
    function report(isFinal){if(config.user.role==='guest'||!session)return;A.request('/hub/game',{item_id:config.item.id,session:session,active:!isFinal&&!document.hidden,'final':!!isFinal},function(){});}
    function stop(){
      clearTimeout(timer);clearInterval(heartbeat);busy=false;
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
    function gameStarted(){
      if(nes&&!window.NINTENDO_RUNNING){fail(new Error('The NES engine could not start this ROM.'));return;}
      if(!nes&&!window.SUPERNINTENDO_GAME_BOOTED){fail(new Error('The SNES engine could not start this ROM.'));return;}
      started=true;busy=false;stopped=false;clearTimeout(timer);start.style.display='none';tools.style.display='';
      text(document.getElementById('compat-game-save'),'Download save state');text(document.getElementById('compat-game-load'),'Load save file');text(document.getElementById('compat-game-quit'),'Quit game');
      stage.focus();session='';for(var i=0;i<16;i++)session+=('0'+Math.floor(Math.random()*256).toString(16)).slice(-2);
      report(false);heartbeat=setInterval(function(){report(false);},15000);
      say(spec.label+' is running. Arrows move; Enter starts; Shift selects. '+(nes?'X is A; Z is B. TV remote: red=A, green=B, yellow=Select.':'W/Q are A/B; S/A are X/Y; Z/X are L/R. TV remote: red=B, green=A, yellow=Y, blue=X.'));
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
      if(busy||started)return;busy=true;stopped=false;start.disabled=true;say('Loading the legacy '+spec.label+' engine…');
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
    var controls=document.createElement('div'),buttons=nes?[['Start','Enter'],['Select','ShiftRight'],['A','KeyX'],['B','KeyZ']]:[['Start','Enter'],['Select','ShiftRight'],['A','KeyW'],['B','KeyQ'],['X','KeyS'],['Y','KeyA'],['L','KeyZ'],['R','KeyX']];
    for(var b=0;b<buttons.length;b++)(function(label,code){var button=document.createElement('button');button.type='button';text(button,label);button.onclick=function(){input(code,true);setTimeout(function(){input(code,false);},100);};controls.appendChild(button);})(buttons[b][0],buttons[b][1]);tools.appendChild(controls);
    var codes={13:'Enter',16:'ShiftRight',37:'ArrowLeft',38:'ArrowUp',39:'ArrowRight',40:'ArrowDown',65:'KeyA',81:'KeyQ',83:'KeyS',87:'KeyW',88:'KeyX',90:'KeyZ'};
    codes[403]=nes?'KeyX':'KeyQ';codes[404]=nes?'KeyZ':'KeyW';codes[405]=nes?'ShiftRight':'KeyA';codes[406]=nes?'Enter':'KeyS';
    function legacyKey(event,down){
      event=event||window.event;if(!started)return;
      var target=event.target||event.srcElement,tag=target&&target.tagName;if(tag==='INPUT'||tag==='SELECT'||tag==='TEXTAREA')return;
      var keyCode=event.keyCode||event.which,code=event.code==='ShiftLeft'?'ShiftRight':!event.code||(keyCode>=403&&keyCode<=406)?codes[keyCode]:null;if(code){input(code,down);if(event.preventDefault)event.preventDefault();event.returnValue=false;}
    }
    listen(document,'keydown',function(event){legacyKey(event,true);});listen(document,'keyup',function(event){legacyKey(event,false);});listen(window,'pagehide',stop);
    listen(window,'error',function(event){if(busy)fail(new Error((event.message||'Engine error')+(event.filename?' in '+event.filename:'')));});
    listen(window,'unhandledrejection',function(event){if(busy)fail(new Error(event.reason&&event.reason.message||String(event.reason)));});
    say('Press Start game to try the JavaScript-only '+spec.label+' player.');
  };
})();
