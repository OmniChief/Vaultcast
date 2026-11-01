(function(){
'use strict';
window.VaultcastStalkerVod=function(host,options){
  var h=options.h,active=false,kind='vod',serial=0,timer=null,offset=0,items=[],filters={},controls={},last=null;
  var status,results,next,build,progress,search,sort;
  function valid(token){return active&&host.isConnected&&options.current()&&token===serial;}
  function save(){options.save(kind,Object.assign({},filters));}
  function stop(){active=false;serial++;clearTimeout(timer);}
  function label(text,input){return h('label',{className:'stalker-vod-filter'},[h('span',{text:text}),input]);}
  function change(){offset=0;save();load(false);}
  function select(key,title,choices){var control=h('select',{'aria-label':title,onChange:function(){filters[key]=control.value;change();}});controls[key]=control;setOptions(control,title,choices||[],filters[key]||'');return label(title,control);}
  function setOptions(control,title,choices,value){if(document.activeElement===control)return;control.replaceChildren(h('option',{value:'',text:title==='Minimum rating'?'Any rating':title==='Quality'?'Any quality':'All '+title.toLowerCase()}));var seen={};choices.forEach(function(choice){var id=String(choice.value),name=choice.name||id;if(seen[id])return;seen[id]=true;control.appendChild(h('option',{value:id,text:name,selected:id===value}));});if(value&&!seen[value])control.appendChild(h('option',{value:value,text:value==='unknown'?'Unknown':value,selected:true}));control.value=value;}
  function render(){
    controls={};host.replaceChildren();
    search=h('input',{type:'search',value:filters.q||'',placeholder:kind==='vod'?'Search all indexed movies…':'Search all indexed series…','aria-label':'Search combined catalogue'});
    search.oninput=function(){filters.q=search.value;offset=0;serial++;clearTimeout(timer);save();timer=setTimeout(function(){load(false);},350);};
    sort=h('select',{'aria-label':'Sort catalogue',onChange:function(){filters.sort=sort.value;change();}},[['title','Title A–Z'],['title_desc','Title Z–A'],['newest','Newest year'],['oldest','Oldest year'],['rating','Highest provider rating']].map(function(row){return h('option',{value:row[0],text:row[1],selected:(filters.sort||'title')===row[0]});}));
    var sourceChoices=options.connections.filter(function(c){return c.enabled;}).map(function(c){return {value:c.id,name:c.name};});
    var sidebar=h('aside',{className:'stalker-browse-tools stalker-vod-filters'},[h('h3',{text:'Refine your catalogue'}),select('feed','Connections',sourceChoices),select('genre','Genres'),select('decade','Decades'),select('year','Years'),select('language','Languages'),select('quality','Quality'),select('category','Provider categories'),select('rating','Minimum rating',[{value:'unknown',name:'Unrated'}].concat([5,6,7,8,9].map(function(n){return {value:String(n),name:n+'+ / 10'};}))),h('button',{className:'btn ghost',text:'Clear filters',onClick:function(){filters={};offset=0;save();render();load(false);}}),h('p',{className:'muted stalker-vod-filter-note',text:'Filters use provider metadata and category labels. Unknown keeps titles with missing details discoverable. Ratings are provider ratings on a 0–10 scale.'})]);
    status=h('p',{className:'muted',role:'status'});progress=h('div',{className:'stalker-catalogue-progress'});results=h('div',{className:'stalker-vod-grid'});
    build=h('button',{className:'btn',text:'Manage shared catalogue',hidden:!options.manage,onClick:function(){if(options.manage)options.manage();}});
    next=h('button',{className:'btn',text:'Load more results',hidden:true,onClick:function(){offset=items.length;load(false,true);}});
    var refresh=h('button',{className:'btn ghost',text:'Refresh results',onClick:function(){offset=0;load(false);}});
    host.append(h('div',{className:'stalker-vod-library-heading'},[h('div',{},[h('span',{className:'eyebrow',text:'All enabled Stalker connections'}),h('h2',{text:kind==='vod'?'Movies':'Series'}),h('p',{className:'muted',text:'One catalogue. Choose a connection when a title is available in more than one place.'})]),h('div',{className:'row-actions'},[build,refresh])]),h('div',{className:'stalker-workspace'},[sidebar,h('main',{className:'stalker-content'},[h('form',{className:'stalker-searchbar',onSubmit:function(e){e.preventDefault();filters.q=search.value;change();}},[search,sort,h('button',{className:'btn',type:'submit',text:'Search'})]),status,progress,results,next])]));
  }
  function facets(result){
    var values=result.facets||{},genreSet=new Set(),decades=new Set();(values.genres||[]).forEach(function(row){String(row.value).split('|').filter(Boolean).forEach(function(genre){genreSet.add(genre);});});
    (values.year||[]).forEach(function(row){decades.add(row.value==null?'unknown':String(Math.floor(Number(row.value)/10)*10));});
    var map={genre:Array.from(genreSet).sort().map(function(value){return {value:value};}),decade:Array.from(decades).sort().reverse().map(function(value){return {value:value,name:value==='unknown'?'Unknown':value+'s'};})};
    ['year','language','quality','category'].forEach(function(key){map[key]=(values[key]||[]).map(function(row){return {value:row.value==null?'unknown':String(row.value),name:row.value==null?'Unknown':String(row.value)};});if(key==='year')map[key].reverse();});
    Object.keys(map).forEach(function(key){var titles={genre:'Genres',decade:'Decades',year:'Years',language:'Languages',quality:'Quality',category:'Provider categories'};setOptions(controls[key],titles[key],map[key],filters[key]||'');});
  }
  function draw(result){
    facets(result);results.replaceChildren();
    items.forEach(function(item){
      var sources=item.sources||[{id:item.id,feed:item.feed,connection:item.connection,kind:item.kind,playable:item.playable}],chosen=sources[0];
      var source=h('select',{'aria-label':'Connection for '+item.name,onChange:function(){chosen=sources[Number(source.value)];updateStar();}},sources.map(function(value,index){return h('option',{value:String(index),text:[value.connection,value.language!=='Unknown'?value.language:null,value.category!=='Unknown'?value.category:null].filter(Boolean).join(' · ')});}));
      function stored(){try{return JSON.parse(localStorage.getItem('stalker-favourites-'+options.owner+'-'+chosen.feed)||'[]');}catch(ignore){return [];}}
      var star=h('button',{className:'btn ghost stalker-save',onClick:function(){var list=stored(),existing=list.some(function(row){return row.id===chosen.id&&row.tab===kind;});list=list.filter(function(row){return !(row.id===chosen.id&&row.tab===kind);});if(!existing)list.unshift({id:chosen.id,name:item.name,tab:kind});try{localStorage.setItem('stalker-favourites-'+options.owner+'-'+chosen.feed,JSON.stringify(list.slice(0,100)));}catch(ignore){}updateStar();}});
      function updateStar(){var existing=stored().some(function(row){return row.id===chosen.id&&row.tab===kind;});star.textContent=existing?'★':'☆';star.setAttribute('aria-pressed',String(existing));star.setAttribute('aria-label',(existing?'Remove favourite: ':'Save favourite: ')+item.name);}
      updateStar();
      var open=h('button',{className:'btn',text:item.playable?'View details':kind==='series'?'Browse series':'Explore',onClick:function(){options.open(chosen.feed,Object.assign({},item,chosen),open);}});
      var download=item.playable&&options.download?h('button',{className:'btn ghost',text:'Add to library',onClick:function(){download.disabled=true;options.download(chosen.feed,Object.assign({},item,chosen)).then(function(){download.textContent='Queued';}).catch(function(error){download.disabled=false;status.textContent=error.message;});}}):null;
      results.appendChild(h('article',{className:'stalker-vod-card'},[h('button',{className:'stalker-vod-cover','aria-label':'Open '+item.name,onClick:function(){open.click();}},[h('span',{className:'stalker-vod-placeholder',text:item.name.slice(0,1)}),item.poster?h('img',{src:item.poster,alt:'',loading:'lazy',referrerpolicy:'no-referrer',onError:function(e){e.target.remove();}}):null,h('span',{className:'stalker-vod-badge',text:item.quality!=='Unknown'?item.quality:kind==='series'?'Series':'Movie'})]),h('div',{className:'stalker-vod-copy'},[h('div',{className:'row-head'},[h('h3',{text:item.name,title:item.name}),star]),h('p',{className:'muted',text:[item.year,(item.genres||[]).filter(function(genre){return genre!=='Unknown';}).join(', '),item.rating!=null?item.rating+' / 10':null].filter(Boolean).join(' · ')}),sources.length>1?label('Available connections',source):h('small',{className:'muted',text:chosen.connection}),h('div',{className:'row-actions'},[open,download])])]));
    });
    if(!items.length)results.appendChild(h('p',{className:'muted',text:result.connections.length?'No indexed titles match these filters. Try Unknown, clear a filter or continue loading the catalogue.':'Add an enabled Stalker connection in Settings to browse on-demand titles.'}));
    var scanned=result.connections.reduce(function(n,c){return n+c.scanned;},0),failed=result.connections.filter(function(c){return c.error;}),complete=result.connections.length&&result.connections.every(function(c){return c.done;});
    status.textContent=result.total.toLocaleString()+' matching titles · '+items.length+' shown'+(!complete?' · An administrator can load the shared catalogue in Stalker settings.':'');
    progress.replaceChildren();result.connections.forEach(function(connection){progress.appendChild(h('div',{className:'stalker-scan-line'},[h('strong',{text:connection.name}),h('span',{className:connection.error?'bad':'muted',text:connection.error||connection.scanned.toLocaleString()+(connection.total!=null?' / '+connection.total.toLocaleString():'')+(connection.done?' · Complete':' · Indexed')})]));});
    build.hidden=!options.manage;
    next.hidden=!result.hasMore;next.disabled=false;
  }
  function load(advance,append,refresh){
    if(!active||!host.isConnected||!options.current())return;clearTimeout(timer);var token=++serial;next.disabled=true;
    var params=new URLSearchParams(Object.assign({},filters,{kind:kind,offset:append?items.length:0}));
    if(!append)offset=0;if(refresh)results.replaceChildren();status.className='muted';status.textContent='Reading shared catalogue…';
    options.api('stalker_vod_catalogue',params.toString()).then(function(result){if(!valid(token))return;if(!result||!result.ok)throw new Error(result&&result.error||'Could not load combined catalogue.');last=result;items=append?items.concat(result.items||[]):result.items||[];draw(result);

    }).catch(function(error){if(valid(token)){status.className='bad';status.textContent=error.message;next.disabled=false;build.textContent='Manage shared catalogue';}});
  }
  return {show:function(value){if(active&&kind===value)return;stop();active=true;kind=value;filters=Object.assign({},options.saved(kind)||{});offset=0;items=[];last=null;render();load(false);},stop:stop};
};
})();
