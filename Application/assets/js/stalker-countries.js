(function(){
'use strict';
window.VaultcastStalkerCountries=function(host,options){
  var h=options.h,active=false,serial=0,country=(options.saved||{}).country||'';
  var selected=null,feeds=[],folders=[],failures=[],status,body,search='',page=1,channels=[],hasMore=false;
  function current(token){return active&&host.isConnected&&options.current()&&token===serial;}
  function remember(){options.save({country:country,feed:selected&&selected.feed,category:selected&&selected.id});}
  var regions=typeof Intl.DisplayNames==='function'?new Intl.DisplayNames(['en'],{type:'region'}):null;
  function name(code){return code==='ZZ'?'Unlabelled / mixed':regions?regions.of(code):code;}
  function flag(code){
    if(code==='ZZ'||!(/^[A-Z]{2}$/).test(code))return h('span',{'aria-hidden':'true',text:'🌐'});
    var pts=Array.from(code).map(function(ch){return 127397+ch.charCodeAt(0);});
    return h('span',{'aria-hidden':'true',text:String.fromCodePoint.apply(String,pts)});
  }
  function stop(){active=false;serial++;}
  function shell(title){
    host.replaceChildren();
    status=h('p',{className:'muted',role:'status'});
    body=h('div',{className:'stalker-country-results'});
    host.append(h('div',{className:'stalker-country-heading'},[h('h2',{text:title})]),status,body);
  }
  function renderCountries(){
    shell('Live TV · Provider categories');
    var count={},codes={};
    folders.forEach(function(f){codes[f.country]=true;count[f.country]=(count[f.country]||0)+1;});
    var list=Object.keys(codes).sort(function(a,b){return a==='ZZ'?1:b==='ZZ'?-1:name(a).localeCompare(name(b));});
    if(!list.length)status.textContent=failures.length?failures.join(' · '):'No provider categories available.';
    else status.textContent=folders.length+' categories across '+feeds.length+' enabled connections'+(failures.length?' · '+failures.join(' · '):'');
    var grid=h('div',{className:'stalker-country-grid'});
    list.forEach(function(code){grid.appendChild(h('button',{className:'stalker-country-tile',onClick:function(){country=code;selected=null;remember();renderCategories();}},[flag(code),h('strong',{text:name(code)}),h('span',{className:'muted',text:count[code]+' categories'})]));});
    body.append(grid);
  }
  function renderCategories(){
    shell(name(country)+' · Provider categories');
    var back=h('button',{className:'btn ghost',text:'← Countries',onClick:function(){country='';selected=null;remember();renderCountries();}});
    body.append(back);
    var list=folders.filter(function(f){return f.country===country;}).sort(function(a,b){return a.name.localeCompare(b.name)||a.connection.localeCompare(b.connection);});
    status.textContent=list.length+' categories · select a folder to load its channels';
    if(!list.length)body.append(h('p',{className:'muted',text:'No provider categories for this country.'}));
    list.forEach(function(f){body.appendChild(h('button',{className:'stalker-nav-item',text:f.name+' · '+f.connection,onClick:function(){selected=f;page=1;channels=[];search='';remember();loadChannels(false);}}));});
  }
  function drawChannels(){
    shell(selected.name+' · '+selected.connection);
    var back=h('button',{className:'btn ghost',text:'← Categories',onClick:renderCategories});
    var find=h('input',{type:'search',value:search,placeholder:'Filter loaded channels…','aria-label':'Filter channel names'});
    find.oninput=function(){search=find.value;display();};
    var list=h('div',{className:'stalker-country-results'});
    var next=h('button',{className:'btn',text:'Load next provider page',onClick:function(){page++;loadChannels(true);},hidden:!hasMore});
    body.append(back,find,list,next);
    function display(){
      list.replaceChildren();
      var visible=channels.filter(function(c){return c.name.toLowerCase().includes(search.toLowerCase());});
      visible.forEach(function(item){
        var watch=h('button',{className:'btn',text:'Watch',onClick:function(){options.play(item.feed,item,watch);}});
        list.appendChild(h('div',{className:'stalker-channel-row'},[
          h('div',{className:'stalker-country-channel-copy'},[h('strong',{text:item.name}),h('small',{className:'muted',text:item.description})]),watch
        ]));
      });
      if(!visible.length)list.appendChild(h('p',{className:'muted',text:'No channels on the loaded pages match your filter.'}));
      status.textContent=channels.length+' channels loaded from '+selected.connection+' · '+selected.name;
    }
    display();
  }
  function loadChannels(append){
    if(!selected||!active)return;
    var token=++serial,params='feed='+encodeURIComponent(selected.feed)+'&category='+encodeURIComponent(selected.id)+'&page='+page;
    shell('Loading '+selected.name+'…');status.textContent='Requesting provider channel page '+page+'…';
    options.api('stalker_live_channels',params).then(function(result){
      if(!current(token))return;
      if(!result||!result.ok)throw Error(result&&result.error||'Provider channel page failed.');
      channels=append?channels.concat(result.items||[]):result.items||[];
      hasMore=!!result.hasMore;
      drawChannels();
    }).catch(function(error){if(!current(token))return;status.className='bad';status.textContent=error.message;body.append(h('button',{className:'btn',text:'Retry',onClick:function(){loadChannels(append);}}),h('button',{className:'btn ghost',text:'← Categories',onClick:renderCategories}));});
  }
  function discover(){
    shell('Loading provider categories…');
    var token=++serial;folders=[];failures=[];feeds=[];selected=null;
    options.api('stalker','').then(function(result){
      if(!current(token))return;
      feeds=(result.connections||[]).filter(function(f){return f.enabled;});
      if(!feeds.length){renderCountries();return;}
      var pos=0,remaining=feeds.length;
      // Two concurrent requests at most. A slow provider cannot monopolise PHP-FPM.
      function next(){
        if(!current(token)||pos>=feeds.length)return;
        var feed=feeds[pos++];
        options.api('stalker_live_categories','feed='+encodeURIComponent(feed.id)).then(function(data){
          if(!current(token))return;
          (data.categories||[]).forEach(function(c){folders.push({feed:feed.id,connection:feed.name,id:String(c.id),name:c.name,country:c.country||'ZZ'});});
        }).catch(function(error){if(current(token))failures.push(feed.name+': '+error.message);}).then(function(){
          if(!current(token))return;
          remaining--;if(country)renderCategories();else renderCountries();if(remaining>0)next();
        });
      }
      for(var i=0;i<Math.min(2,feeds.length);i++)next();
    }).catch(function(error){if(current(token)){status.className='bad';status.textContent=error.message;}});
  }
  return {show:function(){if(active)return;active=true;discover();},stop:stop};
};
})();
