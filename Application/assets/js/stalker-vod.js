(function(){
'use strict';
window.VaultcastStalkerVod=function(host,options){
  var genreFilter='',genreSelect=null;
  var h=options.h,active=false,kind='vod',serial=0,timer=null,letter='#',query='',feeds=[],progress={},titles=[],seen={},loading=false;
  var status,results,next,search,letters,exportButton,groupExportButton,fieldProbeButton,liveProbeButton,epgProbeButton,groups,group='',categories=[],category='',groupsLoading=false,titlesStarted=false,categoryStatus=null,scopeActions=null,prefixGroups=null,showMoreCategories=false,searchAllMode=false,showFolderBrowser=true,language='UK',tasks=[],providerSelections={},preferredTileKeys={};
  // English is the initial scope; remember explicit selections per user/browser.
  var languagePreferenceKey='stalker-vod-language-'+options.owner;
  try{var savedLanguage=localStorage.getItem(languagePreferenceKey);if(savedLanguage!==null)language=savedLanguage;}catch(e){}
  // Signed-in devices share preferences; browser storage remains an offline
  // fallback and a one-time source for migrating existing local favourites.
  var accountFavourites=null,accountDefaults=null;
  function accountUrl(){return (window.NSApp&&window.NSApp.API?window.NSApp.API:(window.__STREAM_BASE__||'/')+'api')+'/me/stalker-vod-favourites';}
  function accountRequest(body){
    return fetch(accountUrl(),{method:body===null?'GET':'POST',credentials:'same-origin',
      headers:body===null?{}:{'Content-Type':'application/json'},
      body:body===null?undefined:JSON.stringify(body)
    }).then(function(response){if(!response.ok)throw Error('Account sync unavailable');return response.json();})
      .then(function(data){if(!data||!data.ok)throw Error('Account sync unavailable');return data;});
  }
  function localDefault(tab){
    try{return JSON.parse(localStorage.getItem('stalker-vod-preferred-provider-v1-'+options.owner+'-'+tab)||'null');}
    catch(e){return null;}
  }
  function saveAccountSettings(body){
    accountRequest(body).catch(function(){if(status)status.textContent='Saved on this device, but account sync failed. Try again when connected.';});
  }
  function loadAccountSettings(){
    return accountRequest(null).then(function(data){
      var migration={};
      if(Array.isArray(data.favourites)){
        accountFavourites=data.favourites;
        try{localStorage.setItem(favouriteKey(),JSON.stringify(data.favourites));}catch(e){}
      }else{
        accountFavourites=favouriteEntries();
        if(accountFavourites.length)migration.favourites=accountFavourites;
      }
      if(data.defaults&&typeof data.defaults==='object'){
        accountDefaults={vod:data.defaults.vod||null,series:data.defaults.series||null};
        ['vod','series'].forEach(function(tab){
          try{
            if(accountDefaults[tab])localStorage.setItem('stalker-vod-preferred-provider-v1-'+options.owner+'-'+tab,JSON.stringify(accountDefaults[tab]));
            else localStorage.removeItem('stalker-vod-preferred-provider-v1-'+options.owner+'-'+tab);
          }catch(e){}
        });
      }else{
        accountDefaults={vod:localDefault('vod'),series:localDefault('series')};
        if(accountDefaults.vod||accountDefaults.series)migration.defaults=accountDefaults;
      }
      if(Object.keys(migration).length)saveAccountSettings(migration);
    }).catch(function(){accountFavourites=null;accountDefaults=null;});
  }
  function chooseLanguage(value){
    if(language===value)return;
    language=value;
    try{localStorage.setItem(languagePreferenceKey,value);}catch(e){}
    showMoreCategories=false;renderCategories();rerunScopedSearch();
  }
  // One preferred tile per tab and viewer: genres, providers, collections and Kids.
  // Save a stable name/brand, never a temporary provider folder ID.
  function providerFavouriteKey(){return 'stalker-vod-preferred-provider-v1-'+options.owner+'-'+kind;}
  function preferredProvider(){
    if(accountDefaults)return accountDefaults[kind]||null;
    try{
      var value=JSON.parse(localStorage.getItem(providerFavouriteKey())||'null');
      return value&&typeof value.id==='string'&&typeof value.section==='string'&&typeof value.language==='string'?value:null;
    }catch(e){return null;}
  }
  function togglePreferredProvider(entry){
    var previous=preferredProvider(),same=previous&&(previous.type||'provider')===(entry.type||'provider')&&previous.id===entry.id&&previous.section===entry.section&&previous.language===entry.language;
    try{
      if(same)localStorage.removeItem(providerFavouriteKey());
      else localStorage.setItem(providerFavouriteKey(),JSON.stringify(entry));
    }catch(e){status.textContent='This browser could not save the preferred provider.';return;}
    if(accountDefaults){
      accountDefaults[kind]=same?null:entry;
      saveAccountSettings({defaults:accountDefaults});
    }
    renderCategories();
  }
  function openPreferredProvider(){
    var fav=preferredProvider();
    if(!fav)return;
    // Build the chosen language's provider groups before looking up the saved
    // brand. The previous language may have no matching visible group.
    if(language!==fav.language){
      language=fav.language;
      try{localStorage.setItem(languagePreferenceKey,language);}catch(e){}
      renderCategories();
    }
    var selected=preferredTileKeys[(fav.type||'provider')+'|'+fav.language+'|'+fav.section+'|'+fav.id];
    if(!selected)return; // Folder/brand removed; leave the full browser visible.
    beginBrowse(selected);
  }
  function favouriteTile(openButton,identity,label,selectionKey){
    var id=(identity.type||'provider')+'|'+identity.language+'|'+identity.section+'|'+identity.id;
    preferredTileKeys[id]=selectionKey;
    var fav=preferredProvider(),selected=!!fav&&(fav.type||'provider')===(identity.type||'provider')
      &&fav.id===identity.id&&fav.section===identity.section&&fav.language===identity.language;
    var tile=h('div',{className:'stalker-provider-favourite-tile'});
    var star=h('button',{type:'button',
      className:'stalker-provider-favourite-star'+(selected?' is-favourite':''),
      text:selected?'★':'☆',
      title:(selected?'Remove default ':'Set default ')+label+' for '+(kind==='vod'?'Movies':'Series'),
      'aria-label':(selected?'Remove default ':'Set default ')+label+' for '+(kind==='vod'?'Movies':'Series'),
      'aria-pressed':String(selected),
      onClick:function(){togglePreferredProvider(identity);}
    });
    tile.append(openButton,star);
    return tile;
  }
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
  function itemGenres(item){
    var raw=item.genres_str||item.genres||item.genre||'';
    var values=Array.isArray(raw)?raw:String(raw).split(/[,;|/]+/);
    return Array.from(new Set(values.map(function(value){return typeof value==='string'?value.trim():'';}).filter(function(value){return value&&!/^\d+$/.test(value);})));
  }
  function fits(item){
    var genres=itemGenres(item);
    if(genreFilter&&!genres.some(function(value){return value.toLocaleLowerCase()===genreFilter;}))return false;
    var text=[title(item),item.actors,item.cast,item.director,genres.join(' ')].filter(Boolean).join(' ').toLocaleLowerCase();
    return !query||text.includes(query.toLocaleLowerCase());
  }
  function renderGenreFilter(){
    if(!genreSelect)return;
    var values=new Map();titles.forEach(function(item){itemGenres(item).forEach(function(value){values.set(value.toLocaleLowerCase(),value);});});
    genreSelect.replaceChildren(h('option',{value:'',text:'All genres',selected:!genreFilter}));
    if(genreFilter&&!values.has(genreFilter))values.set(genreFilter,genreFilter);
    Array.from(values).sort(function(a,b){return a[1].localeCompare(b[1]);}).forEach(function(pair){genreSelect.appendChild(h('option',{value:pair[0],text:pair[1],selected:genreFilter===pair[0]}));});
    genreSelect.disabled=!values.size;
  }
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
  // The diagnostic export stays browser-driven and never requests playable URLs.
  function exportRaw(){
    if(exportJob){exportJob.cancelled=true;return;}
    var answer=window.prompt('Metadata export: enter 250 for a representative sample or ALL to browse every provider catalogue page.','250');
    if(answer===null)return;
    var maxRecords=/^all$/i.test(answer.trim())?Infinity:Number(answer.trim());
    if(!Number.isInteger(maxRecords)&&maxRecords!==Infinity||maxRecords<1||maxRecords>1000000){status.textContent='Enter a positive record limit or ALL.';return;}
    var job={cancelled:false,lines:['Stalker VOD comprehensive metadata export','Generated: '+new Date().toISOString(),'Scope: '+(maxRecords===Infinity?'ALL':maxRecords)+' records','Source: Stalker catalogue pages and category metadata; no external metadata matching','Sensitive URLs, credentials, commands and tokens are excluded','Each RECORD line is a JSON object; missing metadata is null or omitted','']};
    exportJob=job;
    var button=exportButton;button.textContent='Save partial detailed export';
    var kinds=['vod','series'],list=options.connections.filter(function(f){return f.enabled;});
    var count=0,issues=[],fieldsFound={},categoriesMap={},tasks=[],taskIndex=0,seenPages={},stats={},seenRecords={};
    function plain(value,max){
      if(typeof value!=='string'&&typeof value!=='number'&&typeof value!=='boolean')return null;
      return String(value).replace(/[\u0000-\u001f\u007f]/g,' ').slice(0,max||1000);
    }
    function safeArtwork(value){
      if(!value||typeof value!=='string')return null;
      // Only retain pathname, never portal hostname, query, userinfo or embedded credentials.
      try{
        var parsed=new URL(value,window.location.origin);
        if(!/^https?:$/.test(parsed.protocol))return null;
        return parsed.pathname.replace(/[\u0000-\u001f\u007f]/g,'').slice(0,400);
      }catch(e){return null;}
    }
    function record(item,feed,k,groupMap){
      var parsed=prefixParts(item),y=year(item);
      var allowed=['description','o_name','genre','genres','genres_str','genre_id','genre_id_1','genre_id_2','genre_id_3','genre_id_4','cat_genre_id_1','cat_genre_id_2','cat_genre_id_3','cat_genre_id_4','country','language','original_language','director','actors','cast','duration','time','rating','rating_imdb','rating_mpaa','rating_kinopoisk','age','added','hd','high_quality','low_quality','imdb_id','tmdb_id','kinopoisk_id','movie_year','release_date','premiered','season','season_number','series_number','episodes_count','seasons_count','year_end','audio_languages','subtitle_languages','episode','episodeTitle','seasonId','seasonName','category','category_id','categoryId'];
      var obj={connection:plain(feed.name,160),type:k,portal_id:plain(item.id,120),
        raw_title:plain(item.name,600),clean_title:plain(parsed.name,600),prefixes:parsed.labels,
        year:y||null,playable:!!item.playable};
      allowed.forEach(function(field){if(item[field]!==undefined&&item[field]!==null){var value=plain(item[field],field==='description'?3000:400);if(value!==null)obj[field]=value;fieldsFound[field]=true;}});
      var groupId=String(item.category_id||item.categoryId||item.category||'');
      obj.portal_category_id=groupId||null;
      if(groupId&&groupMap[groupId])obj.portal_category_name=groupMap[groupId];
      var art=safeArtwork(item.poster);
      if(art)obj.poster_path=art;
      if(Array.isArray(item.posterCandidates))obj.artwork_fields=item.posterCandidates.slice(0,8).map(function(x){return plain(x&&x.field,60);}).filter(Boolean);
      return obj;
    }
    function finish(){
      if(exportJob!==job)return;
      job.lines.push('','EXPORT STATUS: '+(job.cancelled?'CANCELLED - PARTIAL':count>=maxRecords?'SAMPLE LIMIT REACHED':issues.length?'INCOMPLETE - see warnings':'Completed where provider indicated last page'));
      job.lines.push('Total records: '+count);
      job.lines.push('Additional item fields seen: '+(Object.keys(fieldsFound).join(', ')||'none exposed by catalogue endpoint'));
      job.lines.push('Field counts: '+JSON.stringify(stats));
      job.lines.push('Tasks visited: '+taskIndex+'/'+tasks.length);
      job.lines.push('Distinct per-connection item records: '+Object.keys(seenRecords).length);
      issues.forEach(function(x){job.lines.push('WARNING: '+x);});
      var name='stalker-vod-detailed-'+new Date().toISOString().slice(0,10)+'.txt';
      var blob=new Blob([job.lines.join('\n')+'\n'],{type:'text/plain;charset=utf-8'});
      var url=URL.createObjectURL(blob),a=document.createElement('a');
      a.href=url;a.download=name;document.body.appendChild(a);a.click();a.remove();
      setTimeout(function(){URL.revokeObjectURL(url);},30000);
      exportJob=null;button.textContent='Export comprehensive VOD metadata (.txt)';
      status.textContent='Detailed VOD export saved: '+count+' records'+(issues.length?' · '+issues.length+' warnings':'')+'.';
    }
    function recordStats(obj){
      ['year','description','poster_path','portal_category_id','portal_category_name','language','imdb_id','tmdb_id','genres_str','genre_id_1','actors','age','added','rating_imdb','time','hd'].forEach(function(field){
        if(obj[field]!==undefined&&obj[field]!==null&&obj[field]!=='')stats[field]=(stats[field]||0)+1;
      });
    }
    // Explore each connection and both media types. Category IDs yield far more
    // varied samples than page one of the provider's alphabetical 'all' list.
    function buildTasks(){
      list.forEach(function(feed){kinds.forEach(function(k){
        var key=feed.id+'|'+k,map=categoriesMap[key]||{};
        var groups=Object.keys(map).filter(function(id){return id!=='*';});
        if(maxRecords!==Infinity){
          // Distribute the sample across group positions, not just top categories.
          var chosen=[],slots=Math.min(8,groups.length);
          for(var n=0;n<slots;n++){var index=Math.floor(n*groups.length/slots);if(chosen.indexOf(groups[index])<0)chosen.push(groups[index]);}
          groups=chosen;
        }
        if(maxRecords===Infinity)groups=['']; // One All traversal per feed/type prevents rereading titles in multiple categories.
        if(!groups.length)groups=[''];
        groups.forEach(function(category){tasks.push({feed:feed,kind:k,key:key,category:category,page:1});});
      });});
      // Interleave connections and kinds by category rounds, avoiding an entire
      // sample being consumed by a single connection.
      var sorted=[],max=0;
      list.forEach(function(feed){kinds.forEach(function(k){max=Math.max(max,tasks.filter(function(t){return t.feed.id===feed.id&&t.kind===k;}).length);});});
      for(var pos=0;pos<max;pos++)list.forEach(function(feed){kinds.forEach(function(k){
        var matches=tasks.filter(function(t){return t.feed.id===feed.id&&t.kind===k;});
        if(matches[pos])sorted.push(matches[pos]);
      });});
      tasks=sorted;
    }
    function schedule(){setTimeout(run,250);}
    function run(){
      if(exportJob!==job)return;
      if(job.cancelled||count>=maxRecords||taskIndex>=tasks.length){finish();return;}
      var task=tasks[taskIndex],feed=task.feed,k=task.kind,key=task.key;
      var catLabel=(categoriesMap[key]||{})[task.category]||task.category||'All';
      status.textContent='Detailed export: '+feed.name+' '+k+' / '+catLabel+' · '+count+' records (save partial at any time)';
      var params=new URLSearchParams({kind:k,feed:feed.id,page:String(task.page)});
      if(task.category)params.set('category',task.category);
      Promise.resolve(options.api('stalker_vod_catalogue',params.toString())).then(function(result){
        if(job.cancelled){finish();return;}
        if(!result||!result.ok)throw Error(result&&result.error||'Catalogue request failed');
        var items=Array.isArray(result.items)?result.items:[];
        var signature=items.map(function(i){return i.id;}).join('|'),signatureKey=key+'|'+task.category;
        if(items.length&&seenPages[signatureKey]===signature){issues.push(feed.name+' '+k+' '+catLabel+': repeated page');taskIndex++;schedule();return;}
        seenPages[signatureKey]=signature;
        job.lines.push('### '+plain(feed.name,160)+' / '+k+' / '+plain(catLabel,200)+' / page '+task.page);
        items.forEach(function(item){
          if(!item||!item.name||count>=maxRecords)return;
          var uniqueKey=feed.id+'|'+k+'|'+String(item.id);
          if(seenRecords[uniqueKey])return;
          seenRecords[uniqueKey]=true;
          var obj=record(item,feed,k,categoriesMap[key]||{});
          if(!obj.portal_category_id&&task.category)obj.portal_category_id=task.category;
          if(!obj.portal_category_name&&task.category)obj.portal_category_name=catLabel;
          recordStats(obj);job.lines.push('RECORD\t'+JSON.stringify(obj));count++;

        });
        // Small demos take one page per sampled category; full scans continue
        // each category until the portal reports its final page.
        if(maxRecords===Infinity&&result.hasMore&&items.length&&task.page<1000){task.page++;schedule();}
        else{if(maxRecords===Infinity&&result.hasMore&&task.page>=1000)issues.push(feed.name+' '+k+' '+catLabel+': page safety cap reached');taskIndex++;schedule();}
      }).catch(function(err){issues.push(feed.name+' '+k+' '+catLabel+': '+(err.message||err));taskIndex++;schedule();});
    }
    function loadCategories(index){
      if(job.cancelled){finish();return;}
      var n=list.length*kinds.length;
      if(index>=n){buildTasks();run();return;}
      var feed=list[Math.floor(index/kinds.length)],k=kinds[index%kinds.length],key=feed.id+'|'+k;
      status.textContent='Checking category metadata '+(index+1)+'/'+n+' · '+feed.name+' '+k;
      Promise.resolve(options.api('stalker_vod_catalogue',new URLSearchParams({kind:k,feed:feed.id,raw_categories_export:'1'}).toString()))
        .then(function(res){
          var map={};
          if(res&&res.ok&&Array.isArray(res.categories)&&res.categories.length){
            var safeRows=res.categories.map(function(row){
              var safe={};['id','name','title','description'].forEach(function(f){if(row&&row[f]!=null)safe[f]=plain(row[f],600);});
              if(row&&row.id!=null)map[String(row.id)]=plain(row.title||row.name||row.id,300);
              return safe;
            });
            job.lines.push('CATEGORIES\t'+JSON.stringify({connection:plain(feed.name,160),type:k,items:safeRows}));
          }else issues.push(feed.name+' '+k+': category details unavailable');
          categoriesMap[key]=map;
        }).catch(function(err){categoriesMap[key]={};issues.push(feed.name+' '+k+' categories: '+(err.message||err));})
        .then(function(){setTimeout(function(){loadCategories(index+1);},250);});
    }
    if(!list.length){issues.push('No enabled Stalker connections');finish();return;}
    loadCategories(0);
  }
  // A small admin-only inspection of one original catalogue page, not a scan.
  function exportCatalogueFields(){
    if(!options.isAdmin||!fieldProbeButton||fieldProbeButton.disabled)return;
    var list=options.connections.filter(function(f){return f.enabled;});
    if(!list.length){status.textContent='No enabled Stalker connections.';return;}
    var choices=list.map(function(f,i){return (i+1)+'. '+f.name;}).join('\n');
    var selection=window.prompt('Choose the Stalker connection to inspect:\n'+choices,'1');
    if(selection===null)return;
    var idx=Number(selection)-1;
    if(!Number.isInteger(idx)||idx<0||idx>=list.length){status.textContent='Invalid connection choice.';return;}
    var feed=list[idx],params=new URLSearchParams({kind:kind,feed:feed.id});
    // The currently selected category narrows the one-page sample, if numeric.
    if(/^\d{1,12}$/.test(String(category)))params.set('category',category);
    fieldProbeButton.disabled=true;
    status.textContent='Inspecting one raw catalogue page from '+feed.name+' ('+kind+')…';
    Promise.resolve(options.api('stalker_vod_fields',params.toString())).then(function(result){
      if(!result||!result.ok)throw Error(result&&result.error||'Catalogue field inspection failed');
      var output={generated:new Date().toISOString(),source:'One Stalker get_ordered_list page',
        complete:true,connection:result.connection,type:result.kind,category:result.category,
        rows_inspected:result.rows_inspected,fields:result.fields,
        note:'Sanitised field inventory; transport details, credentials, playback commands and URLs omitted.'};
      var blob=new Blob([JSON.stringify(output,null,2)+'\n'],{type:'application/json;charset=utf-8'});
      var url=URL.createObjectURL(blob),a=document.createElement('a');
      a.href=url;a.download='stalker-vod-provider-fields-'+kind+'-'+new Date().toISOString().slice(0,10)+'.json';
      document.body.appendChild(a);a.click();a.remove();
      setTimeout(function(){URL.revokeObjectURL(url);},30000);
      status.textContent='Raw provider field inventory saved: '+result.rows_inspected+' items, '+Object.keys(result.fields||{}).length+' field names.';
    }).catch(function(err){status.textContent='Provider field inspection failed: '+(err.message||err);})
      .then(function(){fieldProbeButton.disabled=false;});
  }
  // Admin-only snapshot of one channel page, deliberately separate from VOD scanning.
  function exportLiveFields(){
    if(!options.isAdmin||!liveProbeButton||liveProbeButton.disabled)return;
    var list=options.connections.filter(function(f){return f.enabled;});
    if(!list.length){status.textContent='No enabled Stalker connections.';return;}
    var choice=window.prompt('Choose a Stalker live TV connection:\n'+list.map(function(f,i){return (i+1)+'. '+f.name;}).join('\n'),'1');
    if(choice===null)return;
    var index=Number(choice)-1;
    if(!Number.isInteger(index)||index<0||index>=list.length){status.textContent='Choose a valid connection number.';return;}
    var feed=list[index],params=new URLSearchParams({feed:feed.id});
    var categoryId=window.prompt('Optional Live TV category ID (leave blank to inspect the first category):','');
    if(categoryId===null)return;
    categoryId=categoryId.trim();
    if(categoryId&&!/^\d{1,12}$/.test(categoryId)){status.textContent='Enter a numeric category ID or leave blank.';return;}
    if(categoryId)params.set('category',categoryId);
    var pageAnswer=window.prompt('Provider channel page to inspect (1–20). One page only.','1');
    if(pageAnswer===null)return;
    if(!/^(?:[1-9]|1[0-9]|20)$/.test(pageAnswer.trim())){status.textContent='Choose a page from 1 to 20.';return;}
    params.set('page',pageAnswer.trim());
    liveProbeButton.disabled=true;
    status.textContent='Inspecting one live TV channel page on '+feed.name+'…';
    Promise.resolve(options.api('stalker_live_fields',params.toString())).then(function(result){
      if(!result||!result.ok)throw Error(result&&result.error||'Live TV inspection failed');
      var output={generated:new Date().toISOString(),source:'Stalker Live TV get_ordered_list page',
        connection:result.connection,category:result.category,category_id:result.category_id,
        page:result.page,rows_inspected:result.rows_inspected,provider_total:result.provider_total,
        fields:result.fields,channel_samples:result.channel_samples,separator_samples:result.separator_samples,
        note:'One safe channel page: field distributions and up to 15 samples; commands, URLs and credentials omitted.'};
      var blob=new Blob([JSON.stringify(output,null,2)+'\n'],{type:'application/json;charset=utf-8'});
      var url=URL.createObjectURL(blob),a=document.createElement('a');
      a.href=url;a.download='stalker-live-tv-fields-'+new Date().toISOString().slice(0,10)+'.json';
      document.body.appendChild(a);a.click();a.remove();
      setTimeout(function(){URL.revokeObjectURL(url);},30000);
      status.textContent='Live TV field inventory saved: '+result.rows_inspected+' channels in '+result.category+'.';
    }).catch(function(err){status.textContent='Live TV field inspection failed: '+(err.message||err);})
      .then(function(){liveProbeButton.disabled=false;});
  }
  function exportEpgFields(){
    if(!options.isAdmin||!epgProbeButton||epgProbeButton.disabled)return;
    var list=options.connections.filter(function(f){return f.enabled;});
    if(!list.length){status.textContent='No enabled Stalker connections.';return;}
    var answer=window.prompt('Choose a Stalker connection for a short EPG diagnostic:\n'+list.map(function(f,i){return (i+1)+'. '+f.name;}).join('\n'),'1');
    if(answer===null)return;
    var index=Number(answer)-1;
    if(!Number.isInteger(index)||index<0||index>=list.length){status.textContent='Invalid connection choice.';return;}
    var feed=list[index],params=new URLSearchParams({feed:feed.id,period:'1'});
    epgProbeButton.disabled=true;
    status.textContent='Checking a short EPG response on '+feed.name+'…';
    Promise.resolve(options.api('stalker_epg_fields',params.toString())).then(function(result){
      if(!result||!result.ok)throw Error(result&&result.error||'EPG diagnostic failed');
      var output={generated:new Date().toISOString(),source:'Stalker get_epg_info short sample',
        connection:result.connection,period:result.period,channels_seen:result.channels_seen,
        channels_with_events:result.channels_with_events,events_inspected:result.events_inspected,
        truncated:result.truncated,earliest_start_utc:result.earliest_start_utc,
        latest_end_utc:result.latest_end_utc,fields:result.fields,programme_samples:result.programme_samples,
        note:result.note};
      var blob=new Blob([JSON.stringify(output,null,2)+'\n'],{type:'application/json;charset=utf-8'});
      var url=URL.createObjectURL(blob),a=document.createElement('a');
      a.href=url;a.download='stalker-live-epg-fields-'+new Date().toISOString().slice(0,10)+'.json';
      document.body.appendChild(a);a.click();a.remove();
      setTimeout(function(){URL.revokeObjectURL(url);},30000);
      status.textContent='EPG diagnostic saved: '+result.events_inspected+' programme entries across '+result.channels_with_events+' channels.';
    }).catch(function(err){status.textContent='EPG diagnostic failed: '+(err.message||err);})
      .then(function(){epgProbeButton.disabled=false;});
  }
  var groupExportJob=null,groupExportUrl=null,groupDownloadLink=null;
  function exportGroups(){
    if(!options.isAdmin)return;
    if(groupExportJob){groupExportJob.cancelled=true;groupExportJob.advance();return;}
    var button=groupExportButton,items=[],warnings=[],job={cancelled:false,advance:function(){}};
    groupExportJob=job;button.textContent='Save partial export';
    if(groupDownloadLink){groupDownloadLink.remove();groupDownloadLink=null;}
    if(groupExportUrl){URL.revokeObjectURL(groupExportUrl);groupExportUrl=null;}
    var enabled=options.connections.filter(function(f){return f.enabled;}),jobs=[];
    enabled.forEach(function(feed){['vod','series'].forEach(function(k){jobs.push({feed:feed,kind:k});});});
    function finish(){
      if(groupExportJob!==job)return;
      groupExportJob=null;
      var output={generated:new Date().toISOString(),complete:!job.cancelled&&!warnings.length,
        description:'Sanitized VOD category fields; poster URL hosts and queries omitted',
        checked:items.length,expected:jobs.length,connections:items,warnings:warnings};
      var blob=new Blob([JSON.stringify(output,null,2)],{type:'application/json;charset=utf-8'});
      if(groupExportUrl)URL.revokeObjectURL(groupExportUrl);
      groupExportUrl=URL.createObjectURL(blob);
      if(groupDownloadLink)groupDownloadLink.remove();
      groupDownloadLink=document.createElement('a');
      groupDownloadLink.href=groupExportUrl;
      groupDownloadLink.download='stalker-vod-raw-groups-'+new Date().toISOString().slice(0,10)+'.json';
      groupDownloadLink.textContent='Download exported groups JSON';
      groupDownloadLink.className='btn';
      groupDownloadLink.style.display='inline-block';
      groupDownloadLink.style.marginTop='8px';
      button.insertAdjacentElement('afterend',groupDownloadLink);
      button.textContent='Export raw VOD groups (.json)';
      status.textContent='Group export ready: '+items.length+'/'+jobs.length+' responses. Click Download exported groups JSON.'+(warnings.length?' '+warnings.length+' warnings.':'');
    }
    function next(index){
      if(groupExportJob!==job)return;
      if(job.cancelled||index>=jobs.length){finish();return;}
      var current=jobs[index],finished=false,timer;
      function advance(result,error){
        if(finished)return;
        finished=true;clearTimeout(timer);
        if(result&&result.ok)items.push({connection:current.feed.name,kind:current.kind,categories:result.categories||[]});
        else if(error)warnings.push(current.feed.name+' '+current.kind+': '+error);
        next(index+1);
      }
      job.advance=function(){advance(null,'Skipped by user');};
      status.textContent='Exporting groups '+(index+1)+'/'+jobs.length+': '+current.feed.name+' '+current.kind+' (Save partial export to stop)';
      // A provider request may never settle. A timeout must still produce a downloadable file.
      timer=setTimeout(function(){advance(null,'Timed out after 12 seconds');},12000);
      var params=new URLSearchParams({kind:current.kind,feed:current.feed.id,raw_categories_export:'1'});
      try{
        Promise.resolve(options.api('stalker_vod_catalogue',params.toString())).then(function(result){
          if(!result||!result.ok)advance(null,result&&result.error||'Provider request failed');
          else advance(result,null);
        },function(error){advance(null,String(error&&error.message||error));});
      }catch(error){advance(null,String(error&&error.message||error));}
    }
    next(0);
  }
  function stop(){active=false;serial++;favouriteLookupToken++;clearTimeout(timer);if(exportJob)exportJob.cancelled=true;}
  function loadCategories(refresh){
    var token=++serial,index=0;
    // Refresh provider discovery without throwing the viewer out of the selected folder.
    var previousCategory=category;
    groupsLoading=true;categories=[];showMoreCategories=false;renderCategories();
    function nextFeed(){
      if(!valid(token))return;
      if(index>=feeds.length){
        groupsLoading=false;
        // A provider may remove or rename a folder. Reconcile the active source list,
        // while keeping a merged folder selected when at least one source survives.
        if(previousCategory&&!searchAllMode){
          var availableKeys=categoryBuckets().map(function(c){return c.key;});
          var oldSelection=providerSelections[previousCategory];
          if(availableKeys.indexOf(previousCategory)<0&&oldSelection){
            var stillPresent=oldSelection.sources.filter(function(source){
              return categories.some(function(c){return c.feed===source.feed&&c.id===source.id;});
            });
            if(!stillPresent.length){category='';titlesStarted=false;tasks=[];titles=[];seen={};}
            else providerSelections[previousCategory]={name:oldSelection.name,sources:stillPresent};
          }
        }
        renderCategories();renderResults();
        if(!refresh&&!searchAllMode&&!titlesStarted)openPreferredProvider();
        if(searchAllMode&&titlesStarted&&!tasks.length)scan(false);
        return;
      }
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
  function rerunScopedSearch(){
    if(!searchAllMode)return;
    var term=search.value.trim();
    if(groupsLoading){query=term;search.value=term;titlesStarted=!!term;status.textContent='Loading provider folders…';return;}
    reset();query=term;search.value=term;titlesStarted=!!term;
    renderResults();
    if(titlesStarted)scan(false);
  }
  function beginBrowse(key){
    category=key;searchAllMode=false;showFolderBrowser=false;query='';search.value='';letter='#';
    reset();renderCategories();titlesStarted=true;scan(false);
  }
  function browseDiscovery(){
    serial++;loading=false;titlesStarted=false;category='';searchAllMode=false;
    query='';search.value='';letter='#';showFolderBrowser=true;tasks=[];
    reset();renderCategories();renderResults();
  }
  // Only the user opening a section changes its remembered open state.
  var accordionStates={};
  function renderCategories(){
    if(!categoryStatus)return;
    categoryStatus.replaceChildren();
    function btn(label,fn,chosen){
      var b=h('button',{className:chosen?'btn':'btn ghost',text:label,onClick:fn});
      b.setAttribute('aria-pressed',String(!!chosen));return b;
    }
    var buckets=categoryBuckets(),languages=['UK','African','Arabic','Dutch','French','German','Iran','Italian','Portuguese','Spanish','Turkish','Other'];
    var available=languages.filter(function(lang){return buckets.some(function(c){return c.language===lang;});});
    buckets.forEach(function(c){if(available.indexOf(c.language)<0)available.push(c.language);});
    available.sort(function(a,b){if(a==='UK')return -1;if(b==='UK')return 1;return a.localeCompare(b);});
    function languageLabel(lang){return lang==='UK'?'English':lang;}
    var activeLanguage=language||'';
    if(scopeActions){
      scopeActions.replaceChildren();
      function scopeButton(label,value){
        var selected=activeLanguage===value;
        var button=h('button',{type:'button',className:'btn ghost stalker-scope-toggle',text:label,
          onClick:function(){chooseLanguage(value);}});
        button.setAttribute('aria-pressed',String(selected));
        button.style.background=selected?'var(--accent,#196b96)':'var(--surface,#172438)';
        button.style.color=selected?'#fff':'var(--text-muted,#b6c3d2)';
        button.style.borderColor=selected?'var(--accent,#5cbdf5)':'var(--border,#3a4e66)';
        button.style.boxShadow=selected?'0 0 0 1px var(--accent,#5cbdf5), 0 0 12px rgba(80,180,245,.35)':'none';
        scopeActions.appendChild(button);
      }
      // Show every language supplied by the current provider folders.
      available.forEach(function(lang){scopeButton(languageLabel(lang),lang);});
      // The unrestricted search is deliberately last.
      scopeButton('All languages','');
    }
    if(groupsLoading){categoryStatus.appendChild(h('p',{className:'muted',text:'Loading provider folders…'}));return;}
    providerSelections={};preferredTileKeys={};
    var shown=buckets.filter(function(c){return !activeLanguage||c.language===activeLanguage;});
    // Selection names are derived from live folder data below. The browser is
    // still built when collapsed so scanning retains all of its source IDs.
    var viewBar=h('nav',{className:'stalker-vod-viewbar','aria-label':'Stalker browse location'});
    var viewTitle=h('strong',{text:searchAllMode?'Search across '+(activeLanguage?languageLabel(activeLanguage):'all languages'):
      category?'Selected provider folders':'Browse '+(kind==='vod'?'movies':'series')});
    viewBar.appendChild(viewTitle);
    if(category||searchAllMode){
      var discovery=h('button',{type:'button',className:'btn ghost',text:'All folders',onClick:browseDiscovery});
      viewBar.appendChild(discovery);
      if(category){
        viewBar.appendChild(h('button',{type:'button',className:'btn ghost',
          text:showFolderBrowser?'Hide folders':'Change folder',
          'aria-expanded':String(showFolderBrowser),
          onClick:function(){showFolderBrowser=!showFolderBrowser;renderCategories();}}));
      }
    }
    categoryStatus.appendChild(viewBar);

    // Order by purpose within each language: genre, service/company, then collections.
    // Classification affects display only; provider IDs and merged source lists are unchanged.
    // Fixed, auditable brand lists based on actual provider folders.
    // SRS / SERIES prefixes are provider category labels, not separate brands.
    var streaming=[
      ['amazon','Amazon Prime Video',/^AMAZON|^PRIME(?:\s*VIDEO)?\b/,'primevideo.com'],
      ['apple','Apple TV+',/^APPLE(?:\+|\s*TV\b|\b)/,'tv.apple.com'],
      ['crunchyroll','Crunchyroll',/^CRUNCHYROLL\b/,'crunchyroll.com'],
      ['discovery','Discovery+',/^DISCOVERY(?:\+|\b)/,'discoveryplus.com'],
      ['disney','Disney+',/^DISNEY(?:\+|\b)/,'disneyplus.com'],
      ['hbo','HBO Max',/^(?:HBOMAX|HBO(?:\s*MAX)?|MAX)\b/,'hbomax.com'],
      ['hulu','Hulu',/^HULU\b/,'hulu.com'],
      ['mgm','MGM+',/^MGM(?:\+|\b)/,'mgmplus.com'],
      ['netflix','Netflix',/^NETFLIX\b/,'netflix.com'],
      ['paramount','Paramount+',/^PARAMOUNT(?:\+|\b)/,'paramountplus.com'],
      ['peacock','Peacock',/^PEACOCK\b/,'peacocktv.com'],
      ['star z','Starzplay',/^(?:STARZPLAY|STARZ)\b/,'starz.com'],
      ['tubi','Tubi',/^TUBI\b/,'tubitv.com']
    ];
    var networks=[
      ['ae','A&E',/^A&E(?:\s|$)/,'aetv.com'],['abc','ABC',/^ABC(?:\s*NETWORK)?\b/,'abc.com'],
      ['amc','AMC',/^AMC(?:\s*NETWORK)?\b/,'amc.com'],['bbc','BBC',/^BBC\b/,'bbc.co.uk'],
      ['bet','BET+',/^BET(?:\+|\b)/,'bet.com'],['bravo','Bravo',/^BRAVO\b/,'bravotv.com'],
      ['cbc','CBC',/^CBC(?:\s*NETWORK)?\b/,'cbc.ca'],['cbs','CBS',/^CBS(?:\s*NETWORK)?\b/,'cbs.com'],
      ['comedycentral','Comedy Central',/^COMEDY\s*CENTRAL\b/,'cc.com'],
      ['cw','CW',/^CW\b/,'cwtv.com'],['fox','Fox',/^FOX(?:\s*NETWORK)?\b/,'fox.com'],
      ['fx','FX',/^FX\b/,'fxnow.fxnetworks.com'],['hallmark','Hallmark',/^HALLMARK\b/,'hallmarkchannel.com'],
      ['hayu','Hayu',/^HAYU\b/,'hayu.com'],['lifetime','Lifetime',/^LIFETIME\b/,'mylifetime.com'],
      ['mtv','MTV',/^MTV\b/,'mtv.com'],['nbc','NBC',/^NBC(?:\s*NETWORK)?\b/,'nbc.com'],
      ['nickelodeon','Nickelodeon',/^NICKELODEON\b/,'nick.com'],['own','OWN',/^OWN\b/,'oprah.com'],
      ['roku','Roku',/^ROKU\b/,'therokuchannel.roku.com'],['showtime','Showtime',/^SHOWTIME\b/,'showtime.com'],
      ['sky','Sky',/^SKY(?:\s*SHOWTIME)?\b/,'sky.com'],['syfy','Syfy',/^SYFY\b/,'syfy.com'],
      ['tnt','TNT',/^TNT(?:\s*NETWORK)?\b/,'tntdrama.com'],['vh1','VH1',/^VH1\b/,'vh1.com'],
      ['viaplay','Viaplay',/^VIAPLAY\b/,'viaplay.com'],['wow','WOW TV',/^WOW\s*TV\b/,'wowtv.de'],
      ['zeus','Zeus Network',/^ZEUS(?:\s*NETWORK)?\b/,'thezeusnetwork.com']
    ];
    function cleanFolderName(name){return String(name||'').normalize('NFKC').toUpperCase().trim().replace(/^(?:SRS|SERIES)\s*[-:|]\s*/,'');}
    function brandFor(name,defs){var n=cleanFolderName(name);return defs.find(function(x){return x[2].test(n);})||null;}
    function isKids(name){return /\bKIDS?\b/i.test(cleanFolderName(name));}
    function folderType(name){
      var n=cleanFolderName(name);
      if(brandFor(n,streaming))return 'Streaming services';
      if(brandFor(n,networks))return 'TV networks & channels';
      if(isKids(n))return 'Genres';
      if(/^(?:ANIME|ANIMATION|ACTION|ADVENTURE|COMEDY|DRAMA|ROMANCE|CRIME|THRILLER|MYSTERY|HORROR|FANTASY|SCIENCE[\s-]*FICTION|DOCUMENTARY|FAMILY|HISTORY|WAR|WESTERN|MUSIC|SPORT|CHRISTMAS)\b/.test(n))return 'Genres';
      return 'Collections & special folders';
    }
    function artworkFor(name,sectionName){
      var n=cleanFolderName(name);
      var rules=sectionName==='Genres'?[
        [/KIDS?/,'🧸'],[/ANIME/,'🎨'],[/ACTION/,'⚡'],[/ADVENTURE/,'🧭'],
        [/COMEDY/,'🎭'],[/DOCUMENTARY/,'🎬'],[/HISTORY/,'🏛'],[/SPORT/,'🏆'],
        [/HORROR/,'👻'],[/SCIENCE/,'🚀'],[/CHRISTMAS/,'🎄']
      ]:[
        [/NEW ADDED/,'✚'],[/REALITY/,'🎬'],[/TURKISH/,'🌍'],
        [/SUB/,'▤'],[/4K/,'◈'],[/TOP/,'🏆']
      ];
      var matched=rules.find(function(x){return x[0].test(n);});return matched?matched[1]:'▦';
    }
    var sections=[
      {label:'Genres',items:[]},
      {label:'Streaming services',items:[]},
      {label:'TV networks & channels',items:[]},
      {label:'Collections & special folders',items:[]}
    ];
    shown.forEach(function(c){sections.find(function(g){return g.label===folderType(c.name);}).items.push(c);});
    // A single Kids entry searches only category names containing Kid or Kids.
    // Branded Kids folders remain accessible under their brand too.
    var kids=shown.filter(function(c){return isKids(c.name);});
    if(kids.length){
      sections[0].items=sections[0].items.filter(function(c){return !isKids(c.name);});
      sections[0].items.push({name:'Kids',key:'kids|'+(activeLanguage||'all'),sources:kids.reduce(function(all,c){return all.concat(c.sources);},[]),aggregate:true});
    }
    var section=h('section',{className:'stalker-vod-discovery'});section.style.width='100%';section.style.marginBottom='20px';
    var heading=h('h3',{text:activeLanguage?(languageLabel(activeLanguage)+' · Discover'):'Discover · All languages'});
    heading.style.margin='0 0 12px';section.appendChild(heading);
    sections.forEach(function(part){
      if(!part.items.length)return;
      part.items.sort(function(a,b){return a.name.localeCompare(b.name);});
      // Studio navigation: the four discovery sections are visible immediately.
      // Provider lists remain current because every render rebuilds them from live categories.
      var accordion=h('section',{className:'stalker-folder-section stalker-studio-section'});
      var sectionHeading=h('div',{className:'stalker-folder-section-heading'},[
        h('strong',{text:part.label}),
        h('small',{className:'muted',text:part.items.length+' groups'})
      ]);
      sectionHeading.style.display='flex';sectionHeading.style.alignItems='center';
      sectionHeading.style.justifyContent='space-between';sectionHeading.style.padding='12px 14px';
      accordion.appendChild(sectionHeading);
      var grid=h('div');
      grid.style.display='grid';grid.style.width='100%';grid.style.gridTemplateColumns='repeat(auto-fill,minmax(min(100%,220px),1fr))';grid.style.gap='12px';
      function folderButton(c){
        var card=btn(c.name+' · '+c.sources.length+' '+(c.sources.length===1?'source':'sources'),function(){beginBrowse(c.key);},category===c.key);
        card.className='btn ghost';card.style.whiteSpace='normal';card.style.textAlign='left';card.style.minHeight='58px';card.style.width='100%';card.style.padding='12px';
        if(part.label==='Genres'||part.label==='Collections & special folders'){
          card.className+=' stalker-folder-art-card';
          card.prepend(h('span',{className:'stalker-folder-art',text:artworkFor(c.name,part.label),'aria-hidden':'true'}));
        }
        // Preserve the aggregate Kids selection and ordinary provider folders.
        providerSelections[c.key]={name:c.name,sources:c.sources};
        var identity={type:'folder',id:c.name.toLocaleLowerCase(),section:part.label,language:activeLanguage||'UK'};
        return favouriteTile(card,identity,c.name,c.key);
      }
      if(part.label==='Streaming services'||part.label==='TV networks & channels'){
        var defs=part.label==='Streaming services'?streaming:networks,grouped={};
        part.items.forEach(function(c){
          var def=brandFor(c.name,defs),key=def?def[0]:'other';
          if(!grouped[key])grouped[key]={def:def,items:[]};
          grouped[key].items.push(c);
        });
        Object.keys(grouped).sort(function(a,b){
          if(a==='other')return 1;if(b==='other')return -1;
          return grouped[a].def[1].localeCompare(grouped[b].def[1]);
        }).forEach(function(id){
          var entry=grouped[id],def=entry.def,all=entry.items.reduce(function(out,c){return out.concat(c.sources);},[]);
          var key='provider|'+(activeLanguage||'UK')+'|'+part.label+'|'+id;
          providerSelections[key]={name:def?def[1]:'Other',sources:all};
          var art=h('span',{className:'stalker-provider-artwork'});
          if(def){
            // Favicons are actual brand assets; fallback to readable names if unreachable.
            var logo=h('img',{src:'https://www.google.com/s2/favicons?domain='+encodeURIComponent(def[3])+'&sz=128',alt:def[1]+' logo',loading:'lazy',referrerpolicy:'no-referrer'});
            logo.onload=function(){art.classList.add('has-logo');};
            logo.onerror=function(){logo.remove();art.classList.remove('has-logo');};
            art.appendChild(logo);
          }
          art.appendChild(h('span',{className:'stalker-provider-monogram',text:def?def[1]:'Other networks'}));
          var identity={type:'provider',id:id,section:part.label,language:activeLanguage||'UK'};
          var openProvider=h('button',{type:'button',className:'stalker-provider-group stalker-provider-action',onClick:function(){beginBrowse(key);}},[
            h('span',{className:'stalker-provider-summary'},[
              art,h('strong',{text:def?def[1]:'Other networks'}),
              h('small',{className:'muted',text:entry.items.length+' folders · '+all.length+' sources'})
            ])
          ]);
          grid.appendChild(favouriteTile(openProvider,identity,def?def[1]:'Other networks',key));
        });
      }else{
        part.items.forEach(function(c){
          if(c.aggregate)providerSelections[c.key]={name:c.name,sources:c.sources};
          grid.appendChild(folderButton(c));
        });
      }
      accordion.appendChild(grid);
      section.appendChild(accordion);
    });
    if(category){
      var selectedProvider=providerSelections[category];
      viewTitle.textContent=selectedProvider?(selectedProvider.name+' · '+selectedProvider.sources.length+' sources'):'Selected provider folders';
    }
    // Collapse the large discovery artwork grid once a folder or full search
    // is active. No data is lost and switching folders remains instantaneous.
    section.hidden=!!(category||searchAllMode)&&!showFolderBrowser;
    categoryStatus.appendChild(section);

    if(!shown.length)categoryStatus.appendChild(h('p',{className:'muted',text:'No folders in this language.'}));
  }
  // Store stable title/year identities, never temporary provider IDs.
  function favouriteKey(){return 'stalker-vod-favourites-v2-'+options.owner;}
  function favouriteName(item){
    return title({name:String(item.name||'')}).replace(/\s*\((?:19|20)\d{2}\)\s*$/,'').trim();
  }
  function favouriteIdentity(item){
    var name=favouriteName(item),release=String(item.year||year(item)||'').trim();
    var normalized=name.toLocaleLowerCase().replace(/[^\p{L}\p{N}]+/gu,' ').trim();
    return {name:name,year:/^(?:19|20)\d{2}$/.test(release)?release:'',normalized:normalized,tab:item.tab||kind,
      language:typeof item.language==='string'?item.language:'',folder:typeof item.folder==='string'?item.folder:''};
  }
  function favouriteId(item){
    var fav=favouriteIdentity(item);
    return fav.tab+'|'+fav.normalized+'|'+fav.year;
  }
  // A saved language identifies provider folders, not the viewer's current language.
  // Older favourites without a language continue using the original all-provider lookup.
  function favouriteTargets(fav){
    var enabled=options.connections.filter(function(f){return f.enabled;}),targets=[];
    if(fav.language&&categories.length){
      var bucket=categoryBuckets().find(function(c){return c.key===fav.folder;});
      var preferred=bucket?bucket.sources:((providerSelections[fav.folder]||{}).sources||[]);
      categories.filter(function(c){return categoryLanguage(c.name)===fav.language&&enabled.some(function(f){return f.id===c.feed;});})
        .sort(function(a,b){
          function priority(c){return preferred.some(function(p){return p.feed===c.feed&&p.id===c.id;})?0:1;}
          return priority(a)-priority(b);
        })
        // Bound lookups on providers with hundreds of language folders.
        .slice(0,24)
        .forEach(function(c){targets.push({feed:enabled.find(function(f){return f.id===c.feed;}),category:c.id});});
    }
    // If categories are not available, preserve the existing name search behaviour.
    if(!targets.length)enabled.forEach(function(feed){targets.push({feed:feed,category:''});});
    return targets;
  }
  var favouriteLookupToken=0;
  function favouriteEntries(){
    // Once the account responds it is authoritative: never let stale local
    // storage on another device silently replace the shared favourites.
    var saved=accountFavourites;
    if(saved===null){
      try{
        var raw=localStorage.getItem(favouriteKey());
        if(raw!==null){saved=JSON.parse(raw);if(!Array.isArray(saved))saved=[];}
      }catch(e){saved=[];}
    }
    if(saved===null){
      // Import per-connection favourites created by older Vaultcast versions.
      saved=[];
      options.connections.forEach(function(feed){
        try{
          var old=JSON.parse(localStorage.getItem('stalker-favourites-'+options.owner+'-'+feed.id)||'[]');
          if(Array.isArray(old))saved=saved.concat(old);
        }catch(e){}
      });
    }
    var seenFav={},entries=[];
    saved.forEach(function(item){
      if(!item||!item.name||!['vod','series'].includes(item.tab))return;
      var fav=favouriteIdentity(item),id=favouriteId(fav);
      if(!fav.normalized||seenFav[id])return;
      seenFav[id]=true;entries.push(fav);
    });
    return entries.sort(function(a,b){return a.name.localeCompare(b.name)||a.year.localeCompare(b.year);});
  }
  function saveFavourites(list){
    try{
      localStorage.setItem(favouriteKey(),JSON.stringify(list.slice(0,200)));
      if(accountFavourites!==null){
        accountFavourites=list.slice(0,200);
        saveAccountSettings({favourites:accountFavourites});
      }
      return true;
    }catch(e){
      status.textContent='Could not save favourites in browser storage. Check browser storage permissions or available space.';
      return false;
    }
  }
  function findFavourite(favourite,button){
    if(button.disabled)return;
    button.disabled=true;var original=button.textContent;button.textContent='Searching…';
    var targets=favouriteTargets(favourite),index=0,match=null;
    function normalized(name){return favouriteIdentity({name:name,tab:kind}).normalized;}
    function finish(){
      button.disabled=false;button.textContent=original;
      if(match){options.open(match.feed,Object.assign({},match.item,{feed:match.feed}),button);}
      else status.textContent='Favourite "'+favourite.name+'" was not found on the enabled connections. It remains saved.';
    }
    function next(){
      if(!active||!host.isConnected)return;
      if(match||index>=targets.length){finish();return;}
      var target=targets[index++],feed=target.feed,page=1;
      function searchPage(){
        if(!active||!host.isConnected)return;
        var params=new URLSearchParams({kind:kind,feed:feed.id,category:target.category,q:favourite.name,page:String(page)});
        options.api('stalker_vod_catalogue',params.toString()).then(function(data){
          var candidates=data&&Array.isArray(data.items)?data.items:[];
          var found=candidates.find(function(item){return item&&normalized(item.name)===favourite.normalized&&(!favourite.year||!year(item)||year(item)===favourite.year);});
          if(found){match={item:found,feed:feed.id};finish();return;}
          if(data&&data.hasMore&&candidates.length&&page<3){page++;searchPage();}
          else next();
        }).catch(function(){next();});
      }
      status.textContent='Finding '+favourite.name+' on '+feed.name+'…';
      searchPage();
    }
    next();
  }
  function showFavourites(){
    var token=++favouriteLookupToken,favs=favouriteEntries().filter(function(item){return item.tab===kind;});
    results.replaceChildren();
    next.hidden=true;
    status.textContent=favs.length?'Checking saved favourites against current providers…':'No saved favourites for '+(kind==='vod'?'movies':'series')+'.';
    function current(){return token===favouriteLookupToken&&active&&host.isConnected&&options.current();}
    function normalized(name){return favouriteIdentity({name:name,tab:kind}).normalized;}
    var jobs=[];
    favs.forEach(function(fav){
      var titleText=fav.name+(fav.year?' ('+fav.year+')':'');
      var languageNote=fav.language?(fav.language==='UK'?'English':fav.language):'All languages (older favourite)';
      var cover=h('div',{className:'stalker-vod-cover'},[
        h('span',{className:'stalker-vod-placeholder',text:fav.name})
      ]);
      var message=h('small',{className:'muted',text:'Looking up provider artwork…'});
      var button=h('button',{type:'button',className:'btn ghost',text:titleText,onClick:function(){
        if(job.found){options.open(job.found.feed,Object.assign({},job.found.item,{feed:job.found.feed,sources:job.sources.slice()}),button);}
        else findFavourite(fav,button);
      }});
      button.style.whiteSpace='normal';button.style.textAlign='left';button.style.width='100%';
      var remove=h('button',{type:'button',className:'btn ghost stalker-save',text:'★',
        'aria-label':'Remove '+titleText+' from favourites',title:'Remove from favourites','aria-pressed':'true',
        onClick:function(){
          var id=favouriteId(fav),remaining=favouriteEntries().filter(function(item){return favouriteId(item)!==id;});
          if(saveFavourites(remaining))showFavourites();
        }});
      remove.style.alignSelf='flex-start';remove.style.fontSize='1.2rem';
      var card=h('article',{className:'stalker-vod-card stalker-favourite-entry'},[
        h('div',{className:'stalker-vod-poster-frame'},[cover,remove]),h('div',{className:'stalker-vod-copy'},[button,h('small',{className:'muted',text:'Saved language: '+languageNote}),message])
      ]);
      results.appendChild(card);
      var job={fav:fav,cover:cover,message:message,found:null,sources:[],ready:false};
      button.disabled=true;
      job.button=button;
      jobs.push(job);
    });
    // Two outstanding lookups at most; each provider search is limited to three pages.
    // This uses the existing direct provider endpoint, not a catalogue worker or database.
    var index=0,complete=0,unverified=0;
    function searchJob(job){
      var targets=favouriteTargets(job.fav),feedIndex=0,failed=false,posterFound=false;
      function finish(){
        if(!current())return;
        complete++;
        job.ready=true;
        job.button.disabled=!job.found;
        if(!job.found){
          if(failed){unverified++;job.message.textContent='Could not verify availability (provider error).';}
          else job.message.textContent='Not found in the provider pages checked.';
        }else if(!posterFound)job.message.textContent='Poster unavailable from current providers · '+job.sources.length+' connection(s) found.';
        else job.message.textContent=job.sources.length+' connection(s) available.';
        status.textContent='Checked '+complete+' of '+jobs.length+' favourites'+(unverified?' · '+unverified+' could not be verified':'');
        schedule();
      }
      function usePoster(item){
        if(!current())return false;
        var poster=String(item.poster||'').trim();
        if(!/^https?:\/\//i.test(poster))return false;
        var image=h('img',{src:poster,alt:job.fav.name+' poster',loading:'lazy',referrerpolicy:'no-referrer'});
        image.onerror=function(){
          if(!current())return;
          image.remove();
          job.message.textContent='Poster unavailable from current providers.';
        };
        job.cover.replaceChildren(image);
        job.message.textContent='Checking other connections…';
        return true;
      }
      function nextFeed(){
        if(!current())return;
        if(feedIndex>=targets.length){finish();return;}
        var target=targets[feedIndex++],feed=target.feed,page=1;
        function nextPage(){
          if(!current())return;
          var params=new URLSearchParams({kind:kind,feed:feed.id,category:target.category,q:job.fav.name,page:String(page)});
          options.api('stalker_vod_catalogue',params.toString()).then(function(data){
            if(!current())return;
            if(!data||!data.ok){failed=true;nextFeed();return;}
            var candidates=Array.isArray(data.items)?data.items:[];
            candidates.forEach(function(item){
              if(!item||normalized(item.name)!==job.fav.normalized||(job.fav.year&&year(item)&&year(item)!==job.fav.year))return;
              var source={id:item.id,feed:feed.id,connection:feed.name,kind:item.kind,playable:item.playable,
                poster:item.poster||''};
              if(!job.sources.some(function(existing){return String(existing.feed)===String(source.feed)&&String(existing.id)===String(source.id);}))job.sources.push(source);
              if(!job.found)job.found={feed:feed.id,item:item};
              if(!posterFound&&usePoster(item))posterFound=true;
            });
            if(data.hasMore&&candidates.length&&page<3){page++;nextPage();}
            else nextFeed();
          }).catch(function(){if(current()){failed=true;nextFeed();}});
        }
        nextPage();
      }
      nextFeed();
    }
    var running=0;
    function schedule(){
      if(!current())return;
      running--;
      while(running<2&&index<jobs.length){
        var job=jobs[index++];
        running++;
        searchJob(job);
      }
    }
    // Start the first two searches; subsequent work advances only as each completes.
    running=1;
    schedule();
  }
  function render(){
    host.replaceChildren();
    search=h('input',{type:'search',value:query,placeholder:'Search titles, actors, genres or filter loaded results…','aria-label':'Search Stalker catalogue'});
    search.oninput=function(){query=search.value;if(!searchAllMode)renderResults();};
    status=h('p',{className:'muted',role:'status'});
    categoryStatus=h('section',{'aria-label':'Available Stalker provider groups'});
    categoryStatus.style.display='block';categoryStatus.style.width='100%';categoryStatus.style.minWidth='0';
    results=h('div',{className:'stalker-vod-grid'});
    next=h('button',{className:'btn',text:'Load more provider pages',onClick:function(){scan(false);}});
    genreSelect=h('select',{'aria-label':'Filter loaded titles by genre'});
    genreSelect.onchange=function(){genreFilter=genreSelect.value;renderResults();};
    var refresh=h('button',{className:'btn ghost',type:'button',text:'Refresh',onClick:function(){reset();loadCategories(true);}});
    host.append(h('form',{className:'stalker-searchbar stalker-vod-search-top',onSubmit:function(e){e.preventDefault();if(searchAllMode){rerunScopedSearch();}else renderResults();}},[search,genreSelect,refresh]),categoryStatus,status,results,next);
    renderGenreFilter();
    var searchAllButton=h('button',{className:'btn',type:'button',text:'Search all '+(kind==='vod'?'movies':'series'),onClick:function(){
      var term=search.value.trim();
      searchAllMode=true;showFolderBrowser=false;category='';query=term;search.value=term;letter='#';
      renderCategories();rerunScopedSearch();
    }});
    var favouritesButton=h('button',{className:'btn ghost',type:'button',text:'Favourites',onClick:function(){
      serial++;titlesStarted=false;loading=false;searchAllMode=false;showFolderBrowser=true;category='';tasks=[];renderCategories();showFavourites();genreSelect.disabled=true;
    }});
    scopeActions=h('div',{className:'row-actions'});
    scopeActions.style.display='flex';scopeActions.style.flexWrap='wrap';scopeActions.style.gap='8px';
    var searchActions=h('div',{className:'row-actions'},[searchAllButton,favouritesButton,scopeActions]);
    searchActions.style.display='flex';searchActions.style.flexWrap='wrap';searchActions.style.alignItems='center';searchActions.style.gap='8px';
    searchActions.style.marginTop='8px';
    search.parentElement.insertAdjacentElement('afterend',searchActions);
    renderCategories();
  }
  function numberedPart(item){
    // Provider pseudo-series such as "024 - Noreen mohammad sadiq..." are
    // independent playable rows in Movies or Series, not native series folders.
    if(!item.playable)return null;
    var name=title(item),m=name.match(/^\s*(\d{1,4})\s*[-–—.)_]\s*(.+?)\s*$/u);
    if(!m||!m[2]||m[2].length<5)return null;
    var base=m[2].replace(/\s+/g,' ').trim(),poster=String(item.poster||'');
    // Require the same actual poster (not the common empty or TMDB base URL).
    if(!poster||/\/t\/p\/[^/]+\/?(?:\?.*)?$/.test(poster))return null;
    // For numbered collections, each part often has a different subtitle.
    // Match a substantial shared creator/series prefix as well as the exact poster.
    // Keep groups scoped to the provider connection to avoid unrelated matches.
    var words=base.split(/\s+/),shared=words.length>=4?words.slice(0,3).join(' '):base;
    if(shared.length<12)return null;
    return {number:Number(m[1]),name:shared,key:shared.toLocaleLowerCase()+'|'+poster};
  }
  function add(rows,feed){
    (rows||[]).forEach(function(item){
      if(!item||!item.name||!item.id)return;
      var source={id:item.id,feed:feed.id,connection:feed.name,kind:item.kind,playable:item.playable,labels:prefixParts(item).labels,poster:item.poster||'',posterCandidates:Array.isArray(item.posterCandidates)?item.posterCandidates:[]};
      var part=numberedPart(item);
      var k=part?'numbered|'+feed.id+'|'+part.key:key(item),group=seen[k];
      // Without a year, avoid merging unrelated similarly named releases.
      if(!part&&!year(item))k+='|'+feed.id;
      group=seen[k];
      if(!group){group=Object.assign({},item,{sources:[],groupLabels:[]});if(part){group.name=part.name;group.numberedParts=[];}seen[k]=group;titles.push(group);}
      // Enrich an already matched title without changing its identity or position.
      ['poster','description','actors','cast','director','age','rating_mpaa','genres_str','genre','duration','time','rating_imdb','audio_languages','subtitle_languages'].forEach(function(field){if(!group[field]&&item[field])group[field]=item[field];});
      if(part&&!group.numberedParts.some(function(p){return p.feed===feed.id&&p.id===item.id;}))group.numberedParts.push({number:part.number,name:item.name,id:item.id,feed:feed.id,connection:feed.name,item:item});
      if(!group.sources.some(function(x){return x.feed===source.feed&&x.id===source.id;}))group.sources.push(source);
      source.labels.forEach(function(label){if(group.groupLabels.indexOf(label)<0)group.groupLabels.push(label);});
    });
  }
  function renderResults(){
    if(!results)return;
    renderGenreFilter();
    favouriteLookupToken++;
    results.replaceChildren();
    // Append-only discovery order: the portal returns independent pages and
    // providers do not guarantee a globally sorted catalogue. Sorting after
    // each page moved existing posters into earlier/later slots on Load more.
    // Existing duplicate titles retain their first position as sources merge.
    var ordered=titles.filter(fits);
    ordered.forEach(function(item){
      var sources=item.sources||[],chosen=sources[0];
      if(item.numberedParts&&item.numberedParts.length>1){
        var parts=item.numberedParts.slice().sort(function(a,b){return a.number-b.number||a.name.localeCompare(b.name);});
        var details=h('details',{className:'stalker-numbered-group'});
        details.appendChild(h('summary',{text:parts.length+' parts · '+item.name}));
        parts.forEach(function(part){
          var label=String(part.number).padStart(3,'0')+' · '+title(part.item);
          details.appendChild(h('button',{className:'btn ghost',text:label,onClick:function(){
            options.open(part.feed,Object.assign({},part.item,{sources:[{id:part.id,feed:part.feed,connection:part.connection,kind:part.item.kind,playable:true}]}));
          }}));
        });
        results.appendChild(h('article',{className:'stalker-vod-card'},[
          h('div',{className:'stalker-vod-cover'},[h('span',{className:'stalker-vod-placeholder',text:item.name}),item.poster?h('img',{src:item.poster,alt:'',loading:'lazy',referrerpolicy:'no-referrer',onError:function(e){e.target.remove();}}):null]),
          h('div',{className:'stalker-vod-copy'},[details])
        ]));
        return;
      }
      function selected(){return chosen;}
      function open(){var src=selected();options.open(src.feed,Object.assign({},item,src,{sources:sources,poster:src.poster||item.poster}),posterButton);}
      var star=h('button',{className:'btn ghost stalker-save'});
      function updateStar(){
        var active=favouriteEntries().some(function(x){return favouriteId(x)===favouriteId(Object.assign({},item,{tab:kind}));});
        star.textContent=active?'★':'☆';
        star.setAttribute('aria-label',(active?'Remove favourite: ':'Save favourite: ')+title(item));
        star.setAttribute('aria-pressed',String(active));
      }
      star.onclick=function(){
        var fav=favouriteIdentity(Object.assign({},item,{tab:kind,language:language,folder:category})),id=favouriteId(fav);
        if(!fav.normalized){status.textContent='Cannot save a favourite without a title.';return;}
        var list=favouriteEntries(),exists=list.some(function(x){return favouriteId(x)===id;});
        list=list.filter(function(x){return favouriteId(x)!==id;});
        if(!exists)list.unshift(fav);
        if(saveFavourites(list)){updateStar();status.textContent=exists?'Removed from favourites.':'Saved '+fav.name+(fav.year?' ('+fav.year+')':'')+' to favourites.';}
      };
      updateStar();
      var posterButton=h('button',{className:'stalker-vod-cover','aria-label':'Open '+item.name,onClick:open},[
        h('span',{className:'stalker-vod-placeholder',text:title(item)||item.name||'Untitled'})
      ]);
      if(item.poster){
        var posterImg=h('img',{src:item.poster,alt:'',loading:'lazy',referrerpolicy:'no-referrer'});
        var alternatives=Array.from(new Set((item.posterCandidates||[]).map(function(candidate){return candidate.url;}).concat(sources.map(function(source){return source.poster;})))).filter(function(url){return typeof url==='string'&&/^https?:\/\//i.test(url)&&url!==item.poster;}).slice(0,8);
        posterImg.onerror=function(){if(alternatives.length)posterImg.src=alternatives.shift();else posterImg.remove();};
        posterButton.appendChild(posterImg);
      }
      var cardTitle=title(item),cardYear=year(item);
      if(cardYear&&cardTitle.endsWith(' ('+cardYear+')'))cardTitle=cardTitle.slice(0,-(String(cardYear).length+3));
      var posterFrame=h('div',{className:'stalker-vod-poster-frame'},[posterButton,star]);
      if(item.rating_imdb&&Number(item.rating_imdb)>0)posterFrame.appendChild(h('span',{
        className:'stalker-vod-poster-rating','aria-label':'Rating '+Number(item.rating_imdb).toFixed(1),
        text:'★ '+Number(item.rating_imdb).toFixed(1)
      }));
      results.appendChild(h('article',{className:'stalker-vod-card'},[
        posterFrame,
        h('div',{className:'stalker-vod-copy'},[
          h('h3',{text:cardTitle,title:item.name}),
          h('span',{className:'muted',text:cardYear||''})
        ])
      ]));
    });
    if(!ordered.length)results.appendChild(h('p',{className:'muted',text:titlesStarted?'No matching titles in loaded pages. Load more to continue.':'Select a provider folder or use Search all titles to begin.'}));
    var done=tasks.filter(function(t){return progress[t.key]&&progress[t.key].done;}).length;
    var failed=tasks.filter(function(t){return progress[t.key]&&progress[t.key].failed;}).length;
    status.textContent=ordered.length+' titles in '+(searchAllMode?(language?(language==='UK'?'English':language)+' folders':'All languages'):(group||'Selected folder'))+' (loaded so far) · '+done+'/'+tasks.length+' sources finished'+(failed?' · '+failed+' unavailable':'');
    next.hidden=!titlesStarted||!tasks.some(function(t){return progress[t.key]&&!progress[t.key].done;});
    next.disabled=loading;
  }
  function scan(refresh){
    if(loading||!active||!titlesStarted)return;
    if(searchAllMode&&language&&groupsLoading){status.textContent='Loading provider folders before searching '+language+'…';return;}
    if(!tasks.length){
      var bucket=providerSelections[category]||categoryBuckets().find(function(c){return c.key===category;});
      tasks=bucket?bucket.sources.map(function(c){return {key:c.feed+'|'+c.id,feed:feeds.find(function(f){return f.id===c.feed;}),category:c.id};}):
        searchAllMode&&language?categories.filter(function(c){return categoryLanguage(c.name)===language;}).map(function(c){return {key:c.feed+'|'+c.id,feed:feeds.find(function(f){return f.id===c.feed;}),category:c.id};}):
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
  function snapshot(){
    return {kind:kind,language:language,category:category,query:query,genreFilter:genreFilter,searchAllMode:searchAllMode,
      titles:titles.slice(),categories:categories.slice(),progress:JSON.parse(JSON.stringify(progress)),
      tasks:tasks.map(function(t){return {key:t.key,feedId:t.feed&&t.feed.id,category:t.category};}),
      titlesStarted:titlesStarted,scrollY:window.scrollY||document.documentElement.scrollTop||0};
  }
  function restore(saved){
    if(!saved||saved.kind!==kind)return false;
    stop();active=true;serial++;feeds=options.connections.filter(function(f){return f.enabled;});
    if(typeof saved.language==='string')language=saved.language;
    category=saved.category||'';query=saved.query||'';genreFilter=saved.genreFilter||'';searchAllMode=!!saved.searchAllMode;showFolderBrowser=!(category||searchAllMode);
    titles=(saved.titles||[]).slice();categories=(saved.categories||[]).slice();progress=saved.progress||{};
    tasks=(saved.tasks||[]).map(function(t){return {key:t.key,feed:feeds.find(function(f){return f.id===t.feedId;}),category:t.category};}).filter(function(t){return !!t.feed;});
    titlesStarted=!!saved.titlesStarted;seen={};loading=false;groupsLoading=false;
    render();search.value=query;renderCategories();renderResults();
    var target=Number(saved.scrollY)||0;
    requestAnimationFrame(function(){requestAnimationFrame(function(){if(active&&host.isConnected)window.scrollTo(0,target);});});
    return true;
  }
  function showDiagnostics(){
    if(!options.isAdmin)return;
    active=true;status=h('p',{className:'muted',role:'status'});
    var type=h('select',{'aria-label':'Catalogue to inspect'},[h('option',{value:'vod',text:'Movies'}),h('option',{value:'series',text:'Series'})]);
    type.onchange=function(){kind=type.value;fieldProbeButton.textContent=kind==='series'?'Inspect series JSON fields (.json)':'Inspect movie JSON fields (.json)';};
    exportButton=h('button',{className:'btn ghost',type:'button',text:'Export detailed VOD metadata (.txt)',onClick:exportRaw});
    groupExportButton=h('button',{className:'btn ghost',type:'button',text:'Export raw VOD groups (.json)',onClick:exportGroups});
    fieldProbeButton=h('button',{className:'btn ghost',type:'button',text:'Inspect movie JSON fields (.json)',onClick:exportCatalogueFields});
    liveProbeButton=h('button',{className:'btn ghost',type:'button',text:'Inspect live TV channel fields (.json)',onClick:exportLiveFields});
    epgProbeButton=h('button',{className:'btn ghost',type:'button',text:'Inspect live TV EPG fields (.json)',onClick:exportEpgFields});
    host.replaceChildren(type,h('div',{className:'row-actions stalker-diagnostic-actions'},[exportButton,groupExportButton,fieldProbeButton,liveProbeButton,epgProbeButton]),status);
  }
  return {showDiagnostics:showDiagnostics,show:function(value){
    if(active&&kind===value)return;
    stop();active=true;kind=value;letter='#';query='';genreFilter='';render();reset();
    // Load the account preference before choosing the default provider.
    var showToken=serial;
    loadAccountSettings().then(function(){
      if(active&&kind===value&&serial===showToken)loadCategories(false);
    });
  },stop:stop,snapshot:snapshot,restore:restore};
};
})();
