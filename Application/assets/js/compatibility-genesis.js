/* ES3 host for the separately downloaded, pinned JavaScript-only PicoDrive engine. */
(function(){
  if(typeof window.globalThis==='undefined')window.globalThis=window;
  // Small ES3 shims for the downloaded engine; no browser capability gate.
  if(!window.Promise){
    var LegacyPromise=function(executor){
      var self=this;self.state=0;self.value=null;self.queue=[];
      function settle(state,value){if(self.state)return;if(state===1&&value===self){settle(2,new TypeError('Promise cycle'));return;}
        if(state===1&&value&&(typeof value==='object'||typeof value==='function')){var then;try{then=value.then;}catch(error){settle(2,error);return;}if(typeof then==='function'){var done=false;try{then.call(value,function(v){if(!done){done=true;settle(1,v);}},function(e){if(!done){done=true;settle(2,e);}});}catch(error2){if(!done){done=true;settle(2,error2);}}return;}}
        self.state=state;self.value=value;flush();
      }
      function flush(){if(!self.state)return;setTimeout(function(){var rows=self.queue;self.queue=[];for(var i=0;i<rows.length;i++)rows[i]();},0);}
      self.then=function(success,failure){return new LegacyPromise(function(resolve,reject){self.queue.push(function(){var fn=self.state===1?success:failure;if(typeof fn!=='function'){(self.state===1?resolve:reject)(self.value);return;}try{resolve(fn(self.value));}catch(error){reject(error);}});flush();});};
      self['catch']=function(fn){return self.then(null,fn);};
      var called=false;try{executor(function(value){if(!called){called=true;settle(1,value);}},function(error){if(!called){called=true;settle(2,error);}});}catch(error){if(!called){called=true;settle(2,error);}}
    };
    LegacyPromise.resolve=function(value){return new LegacyPromise(function(resolve){resolve(value);});};
    LegacyPromise.reject=function(error){return new LegacyPromise(function(resolve,reject){reject(error);});};
    window.Promise=LegacyPromise;
  }
  if(!Object.assign)Object.assign=function(target){if(target==null)throw new TypeError('Object.assign target');target=Object(target);for(var i=1;i<arguments.length;i++){var source=arguments[i];if(source!=null)for(var key in source)if(Object.prototype.hasOwnProperty.call(source,key))target[key]=source[key];}return target;};
  if(!Array.from)Array.from=function(source){var result=[];for(var i=0;i<source.length;i++)result.push(source[i]);return result;};
  if(!Array.prototype.includes)Array.prototype.includes=function(value,from){var length=this.length>>>0,i=Number(from)||0;if(i<0)i=Math.max(0,length+i);for(;i<length;i++)if(this[i]===value||(this[i]!==this[i]&&value!==value))return true;return false;};
  if(!Array.prototype.fill)Array.prototype.fill=function(value,start,end){var length=this.length>>>0,i=Number(start)||0,until=end===undefined?length:Number(end)||0;i=i<0?Math.max(0,length+i):Math.min(length,i);until=until<0?Math.max(0,length+until):Math.min(length,until);for(;i<until;i++)this[i]=value;return this;};
  if(!String.prototype.startsWith)String.prototype.startsWith=function(value,start){start=Math.max(0,Number(start)||0);return this.substr(start,String(value).length)===String(value);};
  if(!String.prototype.endsWith)String.prototype.endsWith=function(value,end){value=String(value);end=end===undefined?this.length:Math.min(this.length,Math.max(0,Number(end)||0));return end>=value.length&&this.substring(end-value.length,end)===value;};
  if(!String.prototype.includes)String.prototype.includes=function(value,start){return this.indexOf(String(value),Math.max(0,Number(start)||0))>=0;};
  if(!Math.imul)Math.imul=function(a,b){var ah=(a>>>16)&65535,al=a&65535,bh=(b>>>16)&65535,bl=b&65535;return (al*bl+((ah*bl+al*bh)<<16))|0;};
  if(!Math.fround)Math.fround=function(n){var value=new Float32Array(1);value[0]=n;return value[0];};
  if(!Math.clz32)Math.clz32=function(n){n=n>>>0;if(!n)return 32;var count=0;while(!(n&2147483648)){n=n<<1;count++;}return count;};
  if(!window.performance)window.performance={};if(!window.performance.now)window.performance.now=function(){return new Date().getTime();};
  if(!window.requestAnimationFrame)window.requestAnimationFrame=function(fn){return setTimeout(function(){fn(window.performance.now());},16);};
  window.VaultcastCompatibilityGenesis=function(config,A,load,say){
    var start=document.getElementById('compat-game-start'),stage=document.getElementById('compat-emulator-stage'),tools=document.getElementById('compat-game-tools'),timer=null,busy=false,started=false,session='',heartbeat=null,romName='game.md';
    function listen(node,event,fn){if(node.addEventListener)node.addEventListener(event,fn,false);else if(node.attachEvent)node.attachEvent('on'+event,fn);else node['on'+event]=fn;}
    function fail(error){clearTimeout(timer);busy=false;start.disabled=false;say('Legacy Genesis: '+(error.message||String(error)));}
    function report(isFinal){if(config.user.role==='guest'||!session)return;A.request('/hub/game',{item_id:config.item.id,session:session,active:!isFinal&&!document.hidden,'final':!!isFinal},function(){});}
    function stop(){window.GENESIS_GAME_PAUSED=true;window.GENESIS_INPUT_STATE_1=0;window.GENESIS_INPUT_STATE_2=0;clearInterval(heartbeat);clearTimeout(timer);report(true);if(window.GENESIS_AUDIO_CONTEXT&&window.GENESIS_AUDIO_CONTEXT.suspend)window.GENESIS_AUDIO_CONTEXT.suspend();}
    function boot(buffer){
      if(typeof window.embedGenesis!=='function'){fail(new Error('The Genesis engine could not be executed.'));return;}
      try{
        while(stage.firstChild)stage.removeChild(stage.firstChild);
        var target=document.createElement('div');target.id='compat-genesis';target.style.width='100%';target.style.height='100%';stage.appendChild(target);
        window.embedGenesis({container:'compat-genesis',name:romName,rom:buffer,soundEnabled:true,showMobileControls:false,player1:{up:'ArrowUp',down:'ArrowDown',left:'ArrowLeft',right:'ArrowRight',start:'Enter',mode:'KeyZ',a:'KeyA',b:'KeyS',c:'KeyD',x:'KeyQ',y:'KeyW',z:'KeyE'},player2:{up:'KeyI',down:'KeyK',left:'KeyJ',right:'KeyL',start:'KeyH',mode:'KeyV',a:'KeyB',b:'KeyN',c:'KeyM',x:'KeyU',y:'KeyO',z:'KeyP'},backText:'Back to game',cbStarted:function(){
          started=true;busy=false;clearTimeout(timer);start.style.display='none';tools.style.display='';document.getElementById('compat-game-save').innerHTML='Download save state';document.getElementById('compat-game-load').innerHTML='Load save file';document.getElementById('compat-game-quit').innerHTML='Quit game';stage.focus();session='';for(var i=0;i<16;i++)session+=('0'+Math.floor(Math.random()*256).toString(16)).slice(-2);report(false);heartbeat=setInterval(function(){report(false);},15000);say('Genesis is running. Arrows move; Enter starts. On a TV remote, red is A, green is B and yellow is C.');
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
      if(busy||started)return;busy=true;start.disabled=true;say('Loading the legacy Genesis engine…');timer=setTimeout(function(){if(!started)fail(new Error('Startup timed out. Reload the page to retry.'));},120000);
      A.request('/items/'+encodeURIComponent(config.item.id)+'/play',{},function(error,play){if(error){fail(error);return;}if(!/^(bin|md|gen|smd|32x)$/i.test(play.ext||'')){fail(new Error('Use an uncompressed .bin, .md, .gen, .smd or .32x ROM.'));return;}
        romName=config.item.title.replace(/[^a-zA-Z0-9_.-]+/g,'_')+'.'+play.ext;
        load(config.legacyEngine,function(loadError){if(loadError){fail(loadError);return;}function ready(){if(!busy)return;if(window.picoRuntimeReady&&typeof window.embedGenesis==='function'){rom(play.url);return;}setTimeout(ready,50);}ready();});
      });
    };
    document.getElementById('compat-game-save').onclick=function(){if(started&&window.downloadStateGenesis)window.downloadStateGenesis();};
    document.getElementById('compat-game-load').onclick=function(){if(started&&window.uploadStateGenesis)window.uploadStateGenesis();};
    var controls=document.createElement('div'),buttons=[['Start','Enter'],['A','KeyA'],['B','KeyS'],['C','KeyD'],['X','KeyQ'],['Y','KeyW'],['Z','KeyE']];
    for(var b=0;b<buttons.length;b++)(function(label,key){var button=document.createElement('button');button.type='button';button.appendChild(document.createTextNode(label));button.onclick=function(){if(!started||!window.updateInputGenesis)return;window.updateInputGenesis(key,true);setTimeout(function(){window.updateInputGenesis(key,false);},100);};controls.appendChild(button);})(buttons[b][0],buttons[b][1]);tools.appendChild(controls);
    document.getElementById('compat-game-quit').onclick=function(){stop();location.href=config.base+'?compat=on&view=game&id='+encodeURIComponent(config.item.id);};
    // Older TV keyboards report keyCode rather than KeyboardEvent.code.
    var codes={13:'Enter',37:'ArrowLeft',38:'ArrowUp',39:'ArrowRight',40:'ArrowDown',65:'KeyA',83:'KeyS',68:'KeyD',81:'KeyQ',87:'KeyW',69:'KeyE',90:'KeyZ',403:'KeyA',404:'KeyS',405:'KeyD',406:'Enter'};
    function input(event,down){event=event||window.event;if(!started||event.code||!window.updateInputGenesis)return;var target=event.target||event.srcElement,tag=target&&target.tagName;if(tag==='INPUT'||tag==='SELECT'||tag==='TEXTAREA')return;var key=codes[event.keyCode||event.which];if(key){window.updateInputGenesis(key,down);if(event.preventDefault)event.preventDefault();event.returnValue=false;}}
    listen(document,'keydown',function(event){input(event,true);});listen(document,'keyup',function(event){input(event,false);});listen(window,'pagehide',stop);listen(window,'error',function(event){if(busy)fail(new Error((event.message||'Engine error')+(event.filename?' in '+event.filename:'')));});
    say('Press Start game to try the JavaScript-only Genesis player.');
  };
})();
