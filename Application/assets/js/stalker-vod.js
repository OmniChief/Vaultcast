(function(){
'use strict';
window.VaultcastStalkerVod=function(host,options){
  var h=options.h,active=false,kind='vod',serial=0,timer=null,page=1,items=[],filters={},last=null;
  var status,results,next,search,source,category;
  function valid(token){return active&&host.isConnected&&options.current()&&token===serial;}
  function save(){options.save(kind,Object.assign({},filters));}
  function stop(){active=false;serial++;clearTimeout(timer);}
  function change(){page=1;items=[];save();load(false);}
  function selectOptions(el,rows,value,placeholder){
    el.replaceChildren(h('option',{value:'',text:placeholder}));
    rows.forEach(function(row){el.appendChild(h('option',{value:String(row.value),text:row.name||String(row.value)}));});
    el.value=value||'';
  }
  function render(){
    host.replaceChildren();
    search=h('input',{type:'search',value:filters.q||'',placeholder:kind==='vod'?'Search provider movies…':'Search provider series…','aria-label':'Search provider catalogue'});
    search.oninput=function(){filters.q=search.value;page=1;items=[];save();serial++;clearTimeout(timer);timer=setTimeout(function(){load(false);},350);};
    var feeds=options.connections.filter(function(c){return c.enabled;});
    if(!filters.feed||!feeds.some(function(c){return c.id===filters.feed;}))filters.feed=feeds.length?feeds[0].id:'';
    source=h('select',{'aria-label':'Provider connection',onChange:function(){filters.feed=source.value;filters.category='';change();}});
    selectOptions(source,feeds.map(function(c){return {value:c.id,name:c.name};}),filters.feed,'Choose connection');
    category=h('select',{'aria-label':'Provider category',onChange:function(){filters.category=category.value;change();}});
    selectOptions(category,[],filters.category,'All provider categories');
    status=h('p',{className:'muted',role:'status'});
    results=h('div',{className:'stalker-vod-grid'});
    next=h('button',{className:'btn',text:'Load next provider page',hidden:true,onClick:function(){page++;load(true);}});
    var refresh=h('button',{className:'btn ghost',text:'Refresh current page',onClick:function(){page=1;items=[];load(false,true);}});
    host.append(h('div',{className:'stalker-vod-library-heading'},[
      h('div',{},[h('span',{className:'eyebrow',text:'Provider catalogue · on demand'}),h('h2',{text:kind==='vod'?'Movies':'Series'}),h('p',{className:'muted',text:'Only the selected provider page is requested. No full database import or artwork downloads.'})]),
      h('div',{className:'row-actions'},[refresh])
    ]),h('div',{className:'stalker-workspace'},[
      h('aside',{className:'stalker-browse-tools stalker-vod-filters'},[
        h('label',{className:'stalker-vod-filter'},[h('span',{text:'Connection'}),source]),
        h('label',{className:'stalker-vod-filter'},[h('span',{text:'Provider categories'}),category]),
        h('p',{className:'muted stalker-vod-filter-note',text:'Showing the provider\'s own categories and pages. Additional global filters require optional indexing and do not block browsing.'})
      ]),
      h('main',{className:'stalker-content'},[
        h('form',{className:'stalker-searchbar',onSubmit:function(e){e.preventDefault();filters.q=search.value;change();}},[search,h('button',{className:'btn',type:'submit',text:'Search'})]),
        status,results,next
      ])
    ]));
  }
  function draw(result){
    var cats=(result.facets&&result.facets.category)||[];
    if(document.activeElement!==category)selectOptions(category,cats,filters.category,'All provider categories');
    results.replaceChildren();
    items.forEach(function(item){
      var chosen=item.sources&&item.sources[0]||{id:item.id,feed:item.feed,connection:item.connection,kind:item.kind,playable:item.playable};
      function stored(){try{return JSON.parse(localStorage.getItem('stalker-favourites-'+options.owner+'-'+chosen.feed)||'[]');}catch(ignore){return [];}}
      var star=h('button',{className:'btn ghost stalker-save'});
      function updateStar(){var existing=stored().some(function(row){return row.id===chosen.id&&row.tab===kind;});star.textContent=existing?'★':'☆';star.setAttribute('aria-pressed',String(existing));star.setAttribute('aria-label',(existing?'Remove favourite: ':'Save favourite: ')+item.name);}
      star.onclick=function(){var list=stored(),existing=list.some(function(row){return row.id===chosen.id&&row.tab===kind;});list=list.filter(function(row){return !(row.id===chosen.id&&row.tab===kind);});if(!existing)list.unshift({id:chosen.id,name:item.name,tab:kind});try{localStorage.setItem('stalker-favourites-'+options.owner+'-'+chosen.feed,JSON.stringify(list.slice(0,100)));}catch(ignore){}updateStar();};
      updateStar();
      var open=h('button',{className:'btn',text:item.playable?'View details':kind==='series'?'Browse series':'Explore',onClick:function(){options.open(chosen.feed,Object.assign({},item,chosen),open);}});
      var download=item.playable&&options.download?h('button',{className:'btn ghost',text:'Add to library',onClick:function(){download.disabled=true;options.download(chosen.feed,Object.assign({},item,chosen)).then(function(){download.textContent='Queued';}).catch(function(error){download.disabled=false;status.textContent=error.message;});}}):null;
      results.appendChild(h('article',{className:'stalker-vod-card'},[
        h('button',{className:'stalker-vod-cover','aria-label':'Open '+item.name,onClick:function(){open.click();}},[
          h('span',{className:'stalker-vod-placeholder',text:item.name.slice(0,1)}),
          item.poster?h('img',{src:item.poster,alt:'',loading:'lazy',referrerpolicy:'no-referrer',onError:function(e){e.target.remove();}}):null
        ]),
        h('div',{className:'stalker-vod-copy'},[h('div',{className:'row-head'},[h('h3',{text:item.name,title:item.name}),star]),
          h('p',{className:'muted',text:[item.year||''].filter(Boolean).join(' · ')}),
          h('small',{className:'muted',text:chosen.connection||''}),
          h('div',{className:'row-actions'},[open,download])
        ])
      ]));
    });
    if(!items.length)results.appendChild(h('p',{className:'muted',text:'No titles returned for this provider page or category.'}));
    status.textContent=items.length+' titles shown · provider page '+(result.page||page);
    next.hidden=!result.hasMore;next.disabled=false;
  }
  function load(append,refresh){
    if(!active||!host.isConnected||!options.current())return;
    clearTimeout(timer);var token=++serial;next.disabled=true;
    var params=new URLSearchParams({kind:kind,feed:filters.feed||'',category:filters.category||'',q:filters.q||'',page:String(page),refresh:refresh?'1':''});
    status.className='muted';status.textContent='Loading provider page…';
    options.api('stalker_vod_catalogue',params.toString()).then(function(result){
      if(!valid(token))return;
      if(!result||!result.ok)throw new Error(result&&result.error||'Could not load provider page.');
      last=result;items=append?items.concat(result.items||[]):result.items||[];draw(result);
    }).catch(function(error){if(valid(token)){status.className='bad';status.textContent=error.message;next.disabled=false;}});
  }
  return {show:function(value){if(active&&kind===value)return;stop();active=true;kind=value;filters=Object.assign({},options.saved(kind)||{});page=1;items=[];last=null;render();load(false);},stop:stop};
};
})();
