(function(){
'use strict';
window.VaultcastStalkerVod=function(host,options){
  var h=options.h,active=false,kind='vod',serial=0,timer=null,letter='#',query='',feeds=[],progress={},titles=[],seen={},loading=false;
  var status,results,next,search,letters,exportButton,groupExportButton,groups,group='',categories=[],category='',groupsLoading=false,titlesStarted=false,categoryStatus=null,prefixGroups=null,showMoreCategories=false,searchAllMode=false,language='',tasks=[];
  function valid(token){return active&&host.isConnected&&options.current()&&token===serial;}
  // Discover provider-defined leading labels from retrieved names, never from a hardcoded provider list.
  // Require an explicit delimiter so ordinary titles (e.g. "A Beautiful Mind") remain intact.
  function prefixParts(item){
    var name=String(item.name||'').replace(/^[\u200e\u200f\u202a-\u202e\u2066-\u2069\ufeff\s]+/g,'').trim(),labels=[];
    for(var n=0;n<8;n++){
      var match=name.match(/^(?:[┃|│¦]\s*([A-Za-z]{2,5})\s*[┃|│¦]\s*|\[\s*([A-Za-z]{2,5})\s*\]\s*[-:|]?\s*|((?:[A-Za-z]{2,5}|[A-Za-z]\s*\+|4K|8K|FULL HD))\s*[-:]\s+)/i);
      if(!match)break;
      var label=(match[1]||match[2]||match[3]).replace(/\s+/g,'').toUpperCase();
      if(!label||!name.slice(match[0].length).trim())break;
      labels.push(label);name=name.slice(match[0].length).trim();
    }
    return {name:name,labels:labels};
  }
  function title(item){return prefixParts(item).name;}
  function groupLabels(item){return item.groupLabels||prefixParts(item).labels;}
  function year(item){
    var explicit=String(item.year||'').trim();
    if(/^\d{4}$/.test(explicit))return explicit;
    var match=title(item).match(/\((19\d{2}|20\d{2})\)\s*$/);
    return match?match[1]:'';
  }
  function key(item){
    var name=title(item).replace(/\s*\((?:19|20)\d{2}\)\s*$/,'').replace(/\s*\[(?:4K|8K|UHD|FHD|HD|SD)\]\s*/gi,' ').toLocaleLowerCase().replace(/[^\p{L}\p{N}]+/gu,' ').trim();
    return kind+'|'+name+'|'+year(item);
  }
  function initial(item){var m=title(item).match(/[A-Za-z0-9]/);return m?m[0].toUpperCase():'#';}
  function fits(item){return !query||title(item).toLocaleLowerCase().includes(query.toLocaleLowerCase());}
  function reset(){
    serial++;progress={};tasks=[];titles=[];seen={};loading=false;group='';titlesStarted=false;
    feeds=options.connections.filter(function(f){return f.enabled;});
    feeds.forEach(function(f){progress[f.id]={page:1,done:false,failed:false};});
    renderResults();
  }
  var exportJob=null;
  function downloadText(lines){
    var blob=new Blob([lines.join('\n')+'\n'],{type:'text/plain;charset=utf-8'});
    var url=URL.createObjectURL(blob),a=document.createElement('a');
    a.href=url;a.download='stalker-vod-raw-names-'+new Date().toISOString().slice(0,10)+'.txt';
    document.body.appendChild(a);a.click();a.remove();
    setTimeout(function(){URL.revokeObjectURL(url);},30000);
  }
  function exportRaw(){
    if(exportJob){exportJob.cancelled=true;return;}
    var job={cancelled:false,lines:['Stalker VOD raw name export','Generated: '+new Date().toISOString(),'Original titles only; no matching or filtering','']};
    exportJob=job;var button=exportButton;button.textContent='Cancel export';
    var kinds=['vod','series'],list=options.connections.filter(function(f){return f.enabled;});
    var feedIndex=0,kindIndex=0,page=1,count=0,seenPages=new Set(),issues=[],limit=1000;
    function finish(){
      if(exportJob!==job)return;
      job.lines.push('','EXPORT STATUS: '+(job.cancelled?'CANCELLED — PARTIAL':issues.length?'INCOMPLETE — see warnings':'Completed where provider indicated last page'));
      job.lines.push('Total records: '+count);
      issues.forEach(function(x){job.lines.push('WARNING: '+x);});
      downloadText(job.lines);exportJob=null;button.textContent='Export raw VOD names (.txt)';
      status.textContent='Raw VOD export '+(job.cancelled?'cancelled (partial text saved)':'downloaded')+' · '+count+' records'+(issues.length?' · '+issues.length+' warnings':'');
    }
    function run(){
      if(exportJob!==job)return;
      if(job.cancelled||feedIndex>=list.length){finish();return;}
      var feed=list[feedIndex],k=kinds[kindIndex];
      if(page===1){job.lines.push('','### '+feed.name+' / '+k);seenPages.clear();}
      status.textContent='Exporting '+feed.name+' '+k+' page '+page+' · '+count+' records (Cancel export to save partial)';
      var params=new URLSearchParams({kind:k,feed:feed.id,page:String(page)});
      options.api('stalker_vod_catalogue',params.toString()).then(function(result){
        if(!result||!result.ok)throw Error(result&&result.error||'API returned an error');
        var items=Array.isArray(result.items)?result.items:[];
        var signature=items.map(function(i){return i.id;}).join('|');
        if(items.length&&seenPages.has(signature)){issues.push(feed.name+' '+k+': repeated provider page '+page);advance();return;}
        if(items.length)seenPages.add(signature);
        items.forEach(function(item){
          if(!item||!item.name)return;
          // Sanitize line separators; preserve original names, IDs and feed identity.
          job.lines.push(String(item.id||'').replace(/[\r\n\t]/g,' ')+'\t'+String(item.name).replace(/[\r\n\t]/g,' '));
          count++;
        });
        if(result.hasMore&&items.length&&page<limit){page++;schedule();}
        else{
          if(result.hasMore&&page>=limit)issues.push(feed.name+' '+k+': page safety limit '+limit+' reached');
          if(result.hasMore&&!items.length)issues.push(feed.name+' '+k+': empty page despite hasMore');
          if(!items.length&&page===1)issues.push(feed.name+' '+k+': no items returned on page 1');
          advance();
        }
      }).catch(function(err){issues.push(feed.name+' '+k+' page '+page+': '+(err.message||err));advance();});
    }
    function advance(){page=1;kindIndex++;if(kindIndex>=kinds.length){kindIndex=0;feedIndex++;}schedule();}
    function schedule(){setTimeout(run,150);}
    if(!list.length){issues.push('No enabled Stalker connections');finish();return;}
    run();
  }
  function exportGroups(){
    if(groupsLoading)return;
    var button=groupExportButton,items=[],warnings=[];
    button.disabled=true;button.textContent='Exporting groups…';
    var enabled=options.connections.filter(function(f){return f.enabled;}),jobs=[];
    enabled.forEach(function(feed){['vod','series'].forEach(function(k){jobs.push({feed:feed,kind:k});});});
    function next(index){
      if(index>=jobs.length){
        var output={generated:new Date().toISOString(),description:'Sanitized provider VOD category fields; host/query values omitted from artwork URLs',connections:items,warnings:warnings};
        var blob=new Blob([JSON.stringify(output,null,2)],{type:'application/json;charset=utf-8'});
        var url=URL.createObjectURL(blob),a=document.createElement('a');
        a.href=url;a.download='stalker-vod-raw-groups-'+new Date().toISOString().slice(0,10)+'.json';
        document.body.appendChild(a);a.click();a.remove();
        setTimeout(function(){URL.revokeObjectURL(url);},30000);
        button.disabled=false;button.textContent='Export raw VOD groups (.json)';
        status.textContent='Group export downloaded: '+items.length+' connection/type responses'+(warnings.length?' · '+warnings.length+' warnings':'');
        return;
      }
      var job=jobs[index];
      status.textContent='Exporting raw '+job.kind+' groups from '+job.feed.name+'…';
      var params=new URLSearchParams({kind:job.kind,feed:job.feed.id,raw_categories_export:'1'});
      options.api('stalker_vod_catalogue',params.toString()).then(function(result){
        if(!result||!result.ok)throw Error(result&&result.error||'Unavailable');
        items.push({connection:job.feed.name,kind:job.kind,categories:result.categories||[]});
      }).catch(function(e){warnings.push(job.feed.name+' '+job.kind+': '+(e.message||e));}).then(function(){next(index+1);});
    }
    next(0);
  }
  function stop(){active=false;serial++;clearTimeout(timer);if(exportJob)exportJob.cancelled=true;}
  function loadCategories(refresh){
    var token=++serial,index=0;
    groupsLoading=true;categories=[];category='';showMoreCategories=false;renderCategories();
    function nextFeed(){
      if(!valid(token))return;
      if(index>=feeds.length){groupsLoading=false;renderCategories();renderResults();return;}
      var feed=feeds[index++];
      categoryStatus.textContent='Loading groups from '+feed.name+'…';
      options.api('stalker_vod_catalogue',new URLSearchParams({kind:kind,feed:feed.id,categories_only:'1',refresh:refresh?'1':''}).toString())
        .then(function(data){
          if(!valid(token))return;
          if(!data||!data.ok)throw Error('Unable to load groups');
          (data.categories||[]).forEach(function(row){
            if(!row||!row.id||String(row.id)==='*')return;
            categories.push({feed:feed.id,feedName:feed.name,id:String(row.id),name:String(row.name||row.id)});
          });
        }).catch(function(){if(valid(token))categoryStatus.textContent='Some provider groups could not be loaded.';})
        .then(nextFeed);
    }
    nextFeed();
  }
  function categoryLanguage(name){
    var n=String(name||'').normalize('NFKC').toUpperCase().replace(/\u00a0/g,' ');
    // Explicit markers take priority over the UK fallback. Never guess an unknown code is UK.
    var m=n.match(/(?:^|\s)[|┃│¦]\s*([A-Z]{2,3})\s*[|┃│¦]/)||n.match(/\[\s*([A-Z]{2,3})\s*\]/);
    var code=m?m[1]:'';
    var known={EN:'UK',US:'UK',UK:'UK',GB:'UK',IR:'Iran',FR:'French',IT:'Italian',DE:'German',ES:'Spanish',NL:'Dutch',PT:'Portuguese',TR:'Turkish',AR:'Arabic',AF:'African'};
    if(code)return known[code]||code;
    if(/\bIRAN\b/.test(n))return 'Iran';
    if(/[\u0600-\u06ff]/.test(n))return 'Arabic';
    if(/\bMULTISUB\b/.test(n))return 'UK';
    if(/\bFRENCH\b/.test(n))return 'French';
    if(/\bITAL(?:Y|IAN)\b/.test(n))return 'Italian';
    if(/\bGERMAN(?:Y)?\b/.test(n))return 'German';
    if(/\bSPANISH\b/.test(n))return 'Spanish';
    if(/\bAFRICAN\b/.test(n))return 'African';
    return 'UK';
  }
  function categoryName(name){
    return String(name||'').normalize('NFKC').replace(/\u00a0/g,' ')
      .replace(/^\s*(?:VOD|MOVIES|SERIES)\s*[-:|]\s*/i,'')
      .replace(/^\s*[|┃│¦]\s*[A-Z]{2,3}\s*[|┃│¦]\s*/i,'')
      .replace(/\s*\[\s*(?:[A-Z]{2,3}|MULTISUB)\s*\]\s*/gi,' ')
      .replace(/\s*[|┃│¦]\s*[A-Z]{2,3}\s*[|┃│¦]\s*/gi,' ')
      .replace(/\s*[-|]\s*$/,'').replace(/\s+/g,' ').trim()||String(name||'');
  }
  function categoryBuckets(){
    var byKey={};
    categories.forEach(function(c){
      var lang=categoryLanguage(c.name),name=categoryName(c.name),key=lang+'|'+name.toLocaleLowerCase();
      if(!byKey[key])byKey[key]={key:key,language:lang,name:name,sources:[]};
      byKey[key].sources.push(c);
    });
    return Object.keys(byKey).map(function(k){return byKey[k];});
  }
  function beginBrowse(key){
    category=key;searchAllMode=false;query='';search.value='';letter='#';
    reset();renderCategories();titlesStarted=true;scan(false);
  }
  function renderCategories(){
    if(!categoryStatus)return;
    categoryStatus.replaceChildren();
    function btn(label,fn,chosen){
      var b=h('button',{className:chosen?'btn':'btn ghost',text:label,onClick:fn});
      b.setAttribute('aria-pressed',String(!!chosen));return b;
    }
    var searchAll=btn('Search all '+(kind==='vod'?'movies':'series'),function(){
      searchAllMode=true;category='';reset();query='';search.value='';letter='#';renderCategories();search.focus();
    },searchAllMode);
    var searchRow=h('div',{className:'row-actions'},[searchAll]);
    searchRow.style.marginBottom='18px';categoryStatus.appendChild(searchRow);
    if(groupsLoading){categoryStatus.appendChild(h('p',{className:'muted',text:'Loading provider folders…'}));return;}
    var buckets=categoryBuckets(),languages=['UK','Iran','French','Italian','Arabic','Multisub','German','Spanish','Dutch','Portuguese','African','Turkish','Other'];
    var available=languages.filter(function(lang){return buckets.some(function(c){return c.language===lang;});});
    buckets.forEach(function(c){if(available.indexOf(c.language)<0)available.push(c.language);});
    var activeLanguage=language||'';
    var wrap=h('div',{className:'row-actions'});
    wrap.style.display='flex';wrap.style.flexWrap='wrap';wrap.style.gap='8px';wrap.style.marginBottom='20px';
    wrap.appendChild(btn('All folders',function(){language='';showMoreCategories=false;renderCategories();},!activeLanguage));
    available.slice(0,5).forEach(function(lang){
      wrap.appendChild(btn(lang,function(){language=lang;showMoreCategories=false;renderCategories();},activeLanguage===lang));
    });
    if(available.length>5)wrap.appendChild(btn(showMoreCategories?'Fewer languages':'More languages',function(){showMoreCategories=!showMoreCategories;renderCategories();},false));
    if(showMoreCategories)available.slice(5).forEach(function(lang){
      wrap.appendChild(btn(lang,function(){language=lang;renderCategories();},activeLanguage===lang));
    });
    categoryStatus.appendChild(wrap);
    var shown=buckets.filter(function(c){return activeLanguage?c.language===activeLanguage:c.language==='UK';});

    shown.sort(function(a,b){return a.name.localeCompare(b.name);});
    var section=h('section');section.style.width='100%';section.style.marginBottom='20px';
    var heading=h('h3',{text:activeLanguage?(activeLanguage+' folders'):'UK folders'});
    heading.style.margin='0 0 12px';section.appendChild(heading);
    var grid=h('div');
    grid.style.display='grid';grid.style.width='100%';grid.style.gridTemplateColumns='repeat(auto-fill,minmax(min(100%,220px),1fr))';grid.style.gap='12px';
    shown.forEach(function(c){
      var card=btn(c.name+' · '+c.sources.length+' '+(c.sources.length===1?'source':'sources'),function(){beginBrowse(c.key);},category===c.key);
      card.className='btn ghost';card.style.whiteSpace='normal';card.style.textAlign='left';card.style.display='flex';card.style.alignItems='center';card.style.justifyContent='space-between';card.style.minHeight='58px';card.style.width='100%';card.style.padding='12px';
      grid.appendChild(card);
    });
    section.appendChild(grid);categoryStatus.appendChild(section);

    if(!shown.length)categoryStatus.appendChild(h('p',{className:'muted',text:'No folders in this language.'}));
  }
  function render(){
    host.replaceChildren();
    search=h('input',{type:'search',value:query,placeholder:'Search all titles or filter loaded names…','aria-label':'Search Stalker VOD'});
    search.oninput=function(){query=search.value;if(!searchAllMode)renderResults();};
    status=h('p',{className:'muted',role:'status'});
    categoryStatus=h('section',{'aria-label':'Available Stalker provider groups'});
    categoryStatus.style.display='block';categoryStatus.style.width='100%';categoryStatus.style.minWidth='0';
    results=h('div',{className:'stalker-vod-grid'});
    next=h('button',{className:'btn',text:'Load more provider pages',onClick:function(){scan(false);}});
    exportButton=h('button',{className:'btn ghost',text:'Export raw VOD names (.txt)',onClick:exportRaw});
    groupExportButton=h('button',{className:'btn ghost',text:'Export raw VOD groups (.json)',onClick:exportGroups});
    host.append(h('div',{className:'stalker-vod-library-heading'},[
      h('div',{},[h('h2',{text:kind==='vod'?'Movies':'Series'}),h('p',{className:'muted',text:'Search across connections or choose a language and collection. Select a folder to browse its titles.'})]),
      h('div',{className:'row-actions'},[h('div',{style:'display:flex;flex-direction:column;gap:8px'},[exportButton,groupExportButton]),h('button',{className:'btn ghost',text:'Refresh',onClick:function(){reset();loadCategories(true);}})])
     ]),categoryStatus,h('form',{className:'stalker-searchbar',onSubmit:function(e){e.preventDefault();if(searchAllMode){reset();query=search.value.trim();titlesStarted=true;scan(false);}else renderResults();}},[search]),status,results,next);
    renderCategories();
  }
  function add(rows,feed){
    (rows||[]).forEach(function(item){
      if(!item||!item.name||!item.id)return;
      var source={id:item.id,feed:feed.id,connection:feed.name,kind:item.kind,playable:item.playable,labels:prefixParts(item).labels,poster:item.poster||'',posterCandidates:Array.isArray(item.posterCandidates)?item.posterCandidates:[]};
      var k=key(item),group=seen[k];
      // Without a year, avoid merging unrelated similarly named releases.
      if(!year(item))k+='|'+feed.id;
      group=seen[k];
      if(!group){group=Object.assign({},item,{sources:[],groupLabels:[]});seen[k]=group;titles.push(group);}
      if(!group.sources.some(function(x){return x.feed===source.feed&&x.id===source.id;}))group.sources.push(source);
      source.labels.forEach(function(label){if(group.groupLabels.indexOf(label)<0)group.groupLabels.push(label);});
    });
  }
  function renderResults(){
    if(!results)return;
    results.replaceChildren();
    var ordered=titles.filter(fits).sort(function(a,b){return title(a).localeCompare(title(b),undefined,{numeric:true});});
    ordered.forEach(function(item){
      var sources=item.sources||[],chosen=sources[0];
      var select=null;
      if(sources.length>1){
        select=h('select',{'aria-label':'Available sources for '+item.name});
        sources.forEach(function(src,i){select.appendChild(h('option',{value:String(i),text:src.connection||('Connection '+(i+1))}));});
      }
      function selected(){return sources[select?Number(select.value):0]||chosen;}
      function open(){var src=selected();options.open(src.feed,Object.assign({},item,src),button);}
      var button=h('button',{className:'btn',text:item.playable?'View details':kind==='series'?'Browse series':'Explore',onClick:open});
      var download=item.playable&&options.download?h('button',{className:'btn ghost',text:'Add to library',onClick:function(){var src=selected();download.disabled=true;options.download(src.feed,Object.assign({},item,src)).then(function(){download.textContent='Queued';}).catch(function(e){download.disabled=false;status.textContent=e.message;});}}):null;
      var star=h('button',{className:'btn ghost stalker-save'});
      function stored(){try{return JSON.parse(localStorage.getItem('stalker-favourites-'+options.owner+'-'+selected().feed)||'[]');}catch(e){return [];}}
      function updateStar(){var src=selected();star.textContent=stored().some(function(x){return x.id===src.id&&x.tab===kind;})?'★':'☆';star.setAttribute('aria-label','Toggle favourite: '+item.name);}
      star.onclick=function(){var src=selected(),list=stored(),exists=list.some(function(x){return x.id===src.id&&x.tab===kind;});list=list.filter(function(x){return !(x.id===src.id&&x.tab===kind);});if(!exists)list.unshift({id:src.id,name:item.name,tab:kind});try{localStorage.setItem('stalker-favourites-'+options.owner+'-'+src.feed,JSON.stringify(list.slice(0,100)));}catch(e){}updateStar();};
      if(select)select.onchange=updateStar;
      updateStar();
      var posterButton=h('button',{className:'stalker-vod-cover','aria-label':'Open '+item.name,onClick:open},[
        h('span',{className:'stalker-vod-placeholder',text:title(item)||item.name||'Untitled'})
      ]);
      if(item.poster){
        var posterImg=h('img',{src:item.poster,alt:'',loading:'lazy',referrerpolicy:'no-referrer'});
        posterImg.onerror=function(){posterImg.remove();};
        posterButton.appendChild(posterImg);
      }
      results.appendChild(h('article',{className:'stalker-vod-card'},[
        posterButton,
        h('div',{className:'stalker-vod-copy'},[
          h('div',{className:'row-head'},[h('h3',{text:title(item),title:item.name}),star]),
          h('p',{className:'muted',text:year(item)}),
          select||h('small',{className:'muted',text:chosen.connection||''}),
          h('div',{className:'row-actions'},[button,download])
        ])
      ]));
    });
    if(!ordered.length)results.appendChild(h('p',{className:'muted',text:titlesStarted?'No matching titles in loaded pages. Load more to continue.':'Select a provider folder or use Search all titles to begin.'}));
    var done=tasks.filter(function(t){return progress[t.key]&&progress[t.key].done;}).length;
    var failed=tasks.filter(function(t){return progress[t.key]&&progress[t.key].failed;}).length;
    status.textContent=ordered.length+' titles in '+(group||'All groups')+' for '+(letter==='#'?'All':letter)+' (loaded so far) · '+done+'/'+tasks.length+' sources finished'+(failed?' · '+failed+' unavailable':'');
    next.hidden=!titlesStarted||!tasks.some(function(t){return progress[t.key]&&!progress[t.key].done;});
    next.disabled=loading;
  }
  function scan(refresh){
    if(loading||!active||!titlesStarted)return;
    if(!tasks.length){
      var bucket=categoryBuckets().find(function(c){return c.key===category;});
      tasks=bucket?bucket.sources.map(function(c){return {key:c.feed+'|'+c.id,feed:feeds.find(function(f){return f.id===c.feed;}),category:c.id};}):
        feeds.map(function(f){return {key:f.id+'|*',feed:f,category:''};});
      tasks=tasks.filter(function(t){return !!t.feed;});
      tasks.forEach(function(t){progress[t.key]={page:1,done:false,failed:false};});
    }
    loading=true;var token=++serial;
    var pending=tasks.filter(function(t){return progress[t.key]&&!progress[t.key].done;});
    var index=0,attempts=0,pagesPerSource=3;
    function step(){
      if(!valid(token))return;
      if(index>=pending.length){loading=false;renderResults();return;}
      var task=pending[index],p=progress[task.key];
      if(attempts>=pagesPerSource||!p||p.done){index++;attempts=0;step();return;}
      attempts++;
      status.textContent='Loading '+task.feed.name+' (page '+p.page+')…';
      var params=new URLSearchParams({kind:kind,feed:task.feed.id,category:task.category,q:searchAllMode?query:'',page:String(p.page),refresh:refresh?'1':''});
      options.api('stalker_vod_catalogue',params.toString()).then(function(result){
        if(!valid(token))return;
        if(!result||!result.ok)throw Error(result&&result.error||'Portal unavailable');
        add(result.items,task.feed);p.page++;p.done=!result.hasMore||p.page>10000;
        if(p.done){index++;attempts=0;}
      }).catch(function(){if(valid(token)){p.done=true;p.failed=true;index++;attempts=0;}})
        .then(function(){if(valid(token)){renderResults();step();}});
    }
    step();
  }
  return {show:function(value){if(active&&kind===value)return;stop();active=true;kind=value;letter='#';query='';render();reset();loadCategories(false);},stop:stop};
};
})();
