/* ES3 key and TV-remote bindings shared by the legacy players. */
(function(){
  window.VaultcastLegacyControls=function(options){
    var actions=options.actions.concat([['settings','Controller settings']]),system=options.system,stage=options.stage,root=document.getElementById('compat-controller-config');
    var bindings={},lookup={},pressed={},counts={},timers={},rows={},settingsHeld={},open=false,waiting=null,pulseId=0,storageAvailable=true;
    var storageKey='vaultcast-legacy-controls:v1:'+String(options.userId||'public')+':'+system;
    function own(object,key){return Object.prototype.hasOwnProperty.call(object,key);}
    function listen(node,event,fn,capture){if(node.addEventListener)node.addEventListener(event,fn,!!capture);else if(node.attachEvent)node.attachEvent('on'+event,fn);else node['on'+event]=fn;}
    function text(node,value){while(node.firstChild)node.removeChild(node.firstChild);node.appendChild(document.createTextNode(value));}
    function element(tag,value){var node=document.createElement(tag);if(value!==undefined)text(node,value);return node;}
    function button(label,fn){var node=element('button',label);node.type='button';node.onclick=fn;return node;}
    function consume(event){if(event.preventDefault)event.preventDefault();event.returnValue=false;if(event.stopImmediatePropagation)event.stopImmediatePropagation();if(event.stopPropagation)event.stopPropagation();event.cancelBubble=true;}
    function actionName(action){for(var i=0;i<actions.length;i++)if(actions[i][0]===action)return actions[i][1];return action;}
    function defaults(){
      var base={up:[38,50,98],down:[40,56,104],left:[37,52,100],right:[39,54,102],start:[13,417],select:[16],settings:[48,96]};
      if(system==='genesis'){base.a=[65,53,101,403];base.b=[83,404];base.c=[68,405];base.x=[81];base.y=[87];base.z=[69];base.mode=[90];base.start.push(406);}
      else if(system==='nes'){base.a=[88,403];base.b=[90,404];base.select.push(405);base.start.push(406);}
      else {base.a=[87,404];base.b=[81,403];base.x=[83,406];base.y=[65,405];base.l=[90];base.r=[88];}
      var result={},i,j,action,keys;
      for(i=0;i<actions.length;i++){action=actions[i][0];keys=base[action]||[];result[action]=[];for(j=0;j<keys.length;j++)result[action].push('key:'+keys[j]);}
      return result;
    }
    var keyNames={13:'Enter / OK',16:'Shift',19:'Pause',27:'Escape',32:'Space',37:'Left arrow',38:'Up arrow',39:'Right arrow',40:'Down arrow',403:'Red',404:'Green',405:'Yellow',406:'Blue',412:'Rewind',415:'Play',417:'Fast-forward',10252:'Play / Pause'};
    function keyName(token){
      if(token.slice(0,4)!=='key:')return token.slice(token.indexOf(':')+1);
      var number=Number(token.slice(4));if(keyNames[number])return keyNames[number];if(number>=48&&number<=57)return String.fromCharCode(number);if(number>=65&&number<=90)return String.fromCharCode(number);if(number>=96&&number<=105)return 'Numpad '+(number-96);return 'Key '+number;
    }
    function keyToken(event){
      var number=Number(event.keyCode||event.which)||0,code=event.code||'',key=event.key||'';
      if(/^Numpad[0-9]$/.test(code))return 'key:'+(96+Number(code.slice(6)));
      if(number>0)return 'key:'+number;
      var named={Enter:13,Accept:13,Select:13,OK:13,Space:32,ArrowLeft:37,ArrowUp:38,ArrowRight:39,ArrowDown:40,ShiftLeft:16,ShiftRight:16,Escape:27,MediaFastForward:417,FastForward:417,MediaRewind:412,MediaPlay:415,MediaPlayPause:10252};
      if(own(named,code)||own(named,key))return 'key:'+(named[code]||named[key]);
      if(/^Key[A-Z]$/.test(code))return 'key:'+code.charCodeAt(3);
      if(/^Digit[0-9]$/.test(code))return 'key:'+code.charCodeAt(5);
      return code?'code:'+code:key?'name:'+key:null;
    }
    function rebuild(){lookup={};for(var i=0;i<actions.length;i++){var action=actions[i][0],keys=bindings[action]||[];for(var j=0;j<keys.length;j++)lookup[keys[j]]=action;}}
    function read(){
      bindings=defaults();
      try{
        var stored=window.localStorage.getItem(storageKey);if(!stored)return;
        var data=window.JSON.parse(stored),clean={},seen={},i,j,action,list,token;
        for(i=0;i<actions.length;i++){
          action=actions[i][0];list=data[action];if(Object.prototype.toString.call(list)!=='[object Array]')return;clean[action]=[];
          for(j=0;j<list.length&&j<32;j++){token=list[j];if(typeof token==='string'&&/^(?:key:[0-9]{1,10}|(?:code|name):[^\x00-\x1f]{1,80})$/.test(token)&&!own(seen,token)){seen[token]=true;clean[action].push(token);}}
        }
        bindings=clean;
      }catch(ignore){storageAvailable=false;}
    }
    function persist(){try{window.localStorage.setItem(storageKey,window.JSON.stringify(bindings));storageAvailable=true;}catch(ignore){storageAvailable=false;}}
    function hold(token,action){if(own(pressed,token))return;pressed[token]=action;counts[action]=(counts[action]||0)+1;if(counts[action]===1)options.send(action,true);}
    function release(token){
      if(own(timers,token)){clearTimeout(timers[token]);delete timers[token];}
      if(!own(pressed,token))return;var action=pressed[token];delete pressed[token];counts[action]=Math.max(0,(counts[action]||0)-1);if(!counts[action])options.send(action,false);
    }
    function releaseAll(){for(var token in pressed)if(own(pressed,token))release(token);for(token in timers)if(own(timers,token))clearTimeout(timers[token]);pressed={};counts={};timers={};settingsHeld={};}
    read();rebuild();
    if(!root){root=element('div');root.id='compat-controller-config';stage.parentNode.insertBefore(root,stage);}
    var toggle=button('Controller settings',function(){setOpen(!open);});toggle.id='compat-controller-toggle';toggle.setAttribute('aria-expanded','false');
    var panel=element('div');panel.id='compat-controller-panel';panel.className='compat-panel compat-controller-panel';panel.style.display='none';
    panel.appendChild(element('h3','Controller settings · '+options.label));
    panel.appendChild(element('p','Add a key, number or TV-remote button to any action. A key can belong to one action. The game pauses while this panel is open. Changes are saved for this console on this device. Number 0 opens or closes this panel by default.'));
    var status=element('p','Choose Add key, then press the button you want. Escape cancels capture.');status.id='compat-controller-status';status.setAttribute('role','status');
    var cancel=button('Cancel key capture',function(){waiting=null;draw();text(status,'Key capture cancelled.');});cancel.style.display='none';
    function draw(){
      for(var i=0;i<actions.length;i++){
        var action=actions[i][0],labels=[],keys=bindings[action]||[];for(var j=0;j<keys.length;j++)labels.push(keyName(keys[j]));
        text(rows[action].keys,labels.length?labels.join(', '):'Not assigned');text(rows[action].add,waiting===action?'Press a button…':'Add key');
      }
      cancel.style.display=waiting?'':'none';
    }
    for(var a=0;a<actions.length;a++)(function(action,label){
      var row=element('div');row.className='compat-controller-row';row.setAttribute('data-control-action',action);
      var name=element('strong',label),keys=element('span'),add=button('Add key',function(){releaseAll();waiting=action;draw();text(status,'Press a key or remote button for '+label+'. Escape cancels.');}),clear=button('Clear',function(){releaseAll();waiting=null;bindings[action]=[];rebuild();persist();draw();text(status,label+' cleared.'+(storageAvailable?'':' Storage is unavailable; changes last until you leave this page.'));});
      keys.className='compat-controller-keys';add.setAttribute('aria-label','Add key for '+label);clear.setAttribute('aria-label','Clear keys for '+label);
      row.appendChild(name);row.appendChild(keys);row.appendChild(add);row.appendChild(clear);panel.appendChild(row);rows[action]={keys:keys,add:add};
    })(actions[a][0],actions[a][1]);
    panel.appendChild(status);panel.appendChild(cancel);
    panel.appendChild(button('Reset controls',function(){releaseAll();waiting=null;bindings=defaults();rebuild();persist();draw();text(status,'Default controls restored.'+(storageAvailable?'':' Storage is unavailable; changes last until you leave this page.'));}));
    panel.appendChild(button('Return to game',function(){setOpen(false);}));root.appendChild(toggle);root.appendChild(panel);draw();
    function setOpen(value){
      if(open===value)return;releaseAll();waiting=null;open=value;panel.style.display=value?'':'none';toggle.setAttribute('aria-expanded',value?'true':'false');
      text(toggle,value?'Close controller settings':'Controller settings');draw();if(options.isRunning())options.pause(value);if(value)rows[actions[0][0]].add.focus();else if(options.isRunning())stage.focus();else toggle.focus();
    }
    function editing(event){var node=event.target||event.srcElement,tag=node&&String(node.tagName).toLowerCase();return tag==='input'||tag==='select'||tag==='textarea'||tag==='button'||tag==='a'||node&&node.isContentEditable;}
    function keydown(event){
      event=event||window.event;var token=keyToken(event);if(!token)return;
      if(waiting){
        consume(event);if(token==='key:27'){waiting=null;draw();text(status,'Key capture cancelled.');return;}
        if(event.ctrlKey||event.altKey||event.metaKey){text(status,'Use a single key or remote button without Ctrl or Alt.');return;}
        releaseAll();var assigned=waiting,previous=lookup[token];
        for(var i=0;i<actions.length;i++){var action=actions[i][0],list=bindings[action];for(var j=list.length-1;j>=0;j--)if(list[j]===token)list.splice(j,1);}
        bindings[assigned].push(token);waiting=null;rebuild();persist();draw();
        text(status,keyName(token)+' assigned to '+actionName(assigned)+(previous&&previous!==assigned?' (removed from '+actionName(previous)+')':'')+'.'+(storageAvailable?' Saved on this device.':' Storage is unavailable; changes last until you leave this page.'));return;
      }
      var node=event.target||event.srcElement,tag=node&&String(node.tagName).toLowerCase();
      if(lookup[token]==='settings'&&tag!=='input'&&tag!=='select'&&tag!=='textarea'&&!(node&&node.isContentEditable)&&!event.ctrlKey&&!event.altKey&&!event.metaKey){
        consume(event);if(!own(settingsHeld,token)){setOpen(!open);settingsHeld[token]=true;}return;
      }
      if(open||!options.isRunning()||editing(event)||event.ctrlKey||event.altKey||event.metaKey)return;
      var action=lookup[token];if(!action)return;consume(event);hold(token,action);
      // Media keys are often delivered without keyup by TV browsers.
      if(token==='key:417'||token==='key:412'||token==='key:415'||token==='key:10252'){
        if(own(timers,token))clearTimeout(timers[token]);timers[token]=setTimeout(function(){release(token);},150);
      }
    }
    function keyup(event){event=event||window.event;var token=keyToken(event);if(token&&own(settingsHeld,token)){delete settingsHeld[token];consume(event);}else if(token&&own(pressed,token)){release(token);consume(event);}else if(waiting)consume(event);}
    listen(document,'keydown',keydown,true);listen(document,'keyup',keyup,true);listen(window,'blur',releaseAll);listen(window,'focus',function(){if(open)setTimeout(function(){if(open&&options.isRunning())options.pause(true);},0);});listen(window,'pagehide',releaseAll);
    return {isOpen:function(){return open;},close:function(){setOpen(false);},release:releaseAll,ready:function(){releaseAll();if(open)options.pause(true);},pulse:function(action){if(!options.isRunning()||open)return;var token='pulse:'+(++pulseId);hold(token,action);timers[token]=setTimeout(function(){release(token);},100);}};
  };
})();
