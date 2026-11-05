(function(){
'use strict';
window.VaultcastStalkerVod=function(host,options){
  var genreFilter='',genreSelect=null,languageSelect=null,providerFolderId='',searchMode='title',sortOrder='provider',fullSortRequested=false,minRating='',yearFilter='',scanCursor=0,scanQuery=null;
  var h=options.h,active=false,kind='vod',serial=0,timer=null,letter='#',query='',feeds=[],progress={},titles=[],seen={},seenSourceItems={},loading=false;
  var status,results,next,search,letters,exportButton,groupExportButton,fieldProbeButton,liveProbeButton,epgProbeButton,groups,group='',categories=[],category='',groupsLoading=false,titlesStarted=false,categoryStatus=null,scopeActions=null,prefixGroups=null,showMoreCategories=false,searchAllMode=false,showFolderBrowser=true,language='UK',tasks=[],providerSelections={},preferredTileKeys={},visibleLimit=48;
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
    showMoreCategories=false;renderCategories();if(searchAllMode)rerunScopedSearch();
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
  function foldedSearchText(value,compact){
    var text=String(value||'').normalize('NFKD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase();
    return (compact?text.replace(/[^\p{L}\p{N}]/gu,''):text.replace(/[^\p{L}\p{N}]+/gu,' ')).replace(/\s+/g,' ').trim();
  }
  function providerSearchTerm(value){
    var folded=foldedSearchText(value,false),words=folded.split(/\s+/).filter(Boolean);
    var term=words.sort(function(a,b){return b.length-a.length;})[0]||foldedSearchText(value,true);
    return term.length>24?term.slice(0,5):term;
  }
  function fits(item){
    var genres=itemGenres(item);
    if(genreFilter&&!genres.some(function(value){return value.toLocaleLowerCase()===genreFilter;}))return false;
    if(minRating&&!(Number(item.rating_imdb||item.rating||0)>=Number(minRating)))return false;
    if(yearFilter&&String(year(item))!==yearFilter)return false;
    var searchableTitle=title(item).replace(/\s*[\(\[](?:19|20)\d{2}[\)\]]\s*$/,'').trim();
    var fields=searchMode==='actor'?[item.actors,item.cast]:searchMode==='director'?[item.director]:[searchableTitle];
    var text=fields.filter(Boolean).join(' '),needle=foldedSearchText(query,false);
    if(!needle)return true;
    return foldedSearchText(text,false).includes(needle)||foldedSearchText(text,true).includes(foldedSearchText(query,true));
  }
  function shouldScanAllPages(){return !!(genreFilter||providerFolderId||minRating||yearFilter||fullSortRequested);}
  function applyCatalogueFilterChange(){
    if(!titlesStarted){category='';searchAllMode=true;showFolderBrowser=false;reset();titlesStarted=true;renderCategories();}
    renderResults();
    if(shouldScanAllPages())scan(false);
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
    visibleLimit=48;
    serial++;scanCursor=0;scanQuery=null;progress={};tasks=[];titles=[];seen={};seenSourceItems={};loading=false;group='';titlesStarted=false;
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
        if(!previousCategory&&!searchAllMode&&!titlesStarted&&kind==='vod'){
          var landingFolders=categoryBuckets(),newReleases=landingFolders.filter(function(bucket){return bucket.language==='UK'&&/^new released$/i.test(bucket.name);});
          if(!newReleases.length)newReleases=landingFolders.filter(function(bucket){return bucket.language==='UK'&&/^new release(?:d)?(?:\s|$)/i.test(bucket.name);});
          if(newReleases.length){language='UK';try{localStorage.setItem(languagePreferenceKey,language);}catch(e){}sortOrder='rating';beginBrowse(newReleases[0].key);return;}
        }
        renderCategories();renderResults();
        if(searchAllMode&&titlesStarted&&!tasks.length)scan(false);
        return;
      }
      var feed=feeds[index++];
      categoryStatus.textContent='Preparing catalogue…';
      options.api('stalker_vod_catalogue',new URLSearchParams({kind:kind,feed:feed.id,categories_only:'1',refresh:refresh?'1':''}).toString())
        .then(function(data){
          if(!valid(token))return;
          if(!data||!data.ok)throw Error('Unable to load groups');
          (data.categories||[]).forEach(function(row){
            if(!row||!row.id||String(row.id)==='*')return;
            categories.push({feed:feed.id,feedName:feed.name,id:String(row.id),name:String(row.name||row.id)});
          });
        }).catch(function(){if(valid(token))categoryStatus.textContent='Some catalogue sections could not be loaded.';})
        .then(nextFeed);
    }
    nextFeed();
  }
  function categoryLanguage(name){
    var n=String(name||'').normalize('NFKC').toUpperCase().replace(/\u00a0/g,' ');
    // Explicit locale markers take priority; unmarked folders stay unspecified.
    var m=n.match(/(?:^|\s)[|┃│¦]\s*([A-Z]{2,3})\s*[|┃│¦]/)||n.match(/\[\s*([A-Z]{2,3})\s*\]/);
    var code=m?m[1]:'';
    var known={EN:'UK',US:'UK',UK:'UK',GB:'UK',IR:'Iran',FR:'French',IT:'Italian',DE:'German',ES:'Spanish',NL:'Dutch',PT:'Portuguese',TR:'Turkish',AR:'Arabic',AF:'African'};
    if(code)return known[code]||code;
    var prefix=n.match(/^\s*(PT\s*\/\s*BR|FR|DE|IT|ES|NL|PT|TR|BG|PL|RO|CZ|SK|HU|GR|DK|NO|SE|FI|AL|BR|IL|IN|KU|MT|PK|RU|SO|SV|EN|EX)\s*[-:]/),prefixLanguages={'PT/BR':'Portuguese',FR:'French',DE:'German',IT:'Italian',ES:'Spanish',NL:'Dutch',PT:'Portuguese',TR:'Turkish',BG:'Slavic',PL:'Slavic',RO:'Romanian',CZ:'Slavic',SK:'Slavic',HU:'Hungarian',GR:'Greek',DK:'Scandinavian',NO:'Scandinavian',SE:'Scandinavian',FI:'Finnish',AL:'Albanian',BR:'Portuguese',IL:'Hebrew',IN:'Indian',KU:'Kurdish',MT:'Maltese',PK:'Urdu',RU:'Russian',SO:'Somali',SV:'Swedish',EN:'UK',EX:'Serbian/Croatian/Bosnian'};
    if(prefix)return prefixLanguages[prefix[1].replace(/\s/g,'')];
    if(/\b(?:BANGLA|BENGALI)\b/.test(n))return 'Bengali';
    if(/\bGUJARATI\b/.test(n))return 'Gujarati';
    if(/\bHINDI\b/.test(n))return 'Hindi';
    if(/\bKANNADA\b/.test(n))return 'Kannada';
    if(/\bMALAYALAM\b/.test(n))return 'Malayalam';
    if(/\bMARATHI\b/.test(n))return 'Marathi';
    if(/\b(?:ODIA|ORIYA)\b/.test(n))return 'Odia';
    if(/\bPUNJABI\b/.test(n))return 'Punjabi';
    if(/\bTAMIL\b/.test(n))return 'Tamil';
    if(/\bTELUGU\b/.test(n))return 'Telugu';
    if(/\bURDU\b/.test(n))return 'Urdu';
    if(/\bPAKISTAN(?:I)?\b/.test(n))return 'Urdu';
    if(/\b(?:HEBREW|ISRAELI)\b/.test(n))return 'Hebrew';
    if(/\bKURDISH\b/.test(n))return 'Kurdish';
    if(/\b(?:KOREAN|KOREA)\b/.test(n))return 'Korean';
    if(/\b(?:INDIA|INDIAN|BOLLYWOOD)\b/.test(n))return 'Indian';
    if(/\b(?:SOMALI|SOMALIA)\b/.test(n))return 'Somali';
    if(/\b(?:ALBANIAN|ALBANIA)\b/.test(n))return 'Albanian';
    if(/\b(?:NORDIC|SVENSKA|SWEDISH|NORWEGIAN|DANISH|FINNISH)\b/.test(n))return /\b(?:SVENSKA|SWEDISH)\b/.test(n)?'Swedish':'Scandinavian';
    if(/\b(?:EXYU|EX\s*[- ]?YUGOSLAV(?:IAN)?)\b/.test(n))return 'Serbian/Croatian/Bosnian';
    if(/\b(?:SERBIAN|CROATIAN|BOSNIAN|EXYU)\b/.test(n))return 'Serbian/Croatian/Bosnian';
    if(/\b(?:MULTI\s*\/\s*LINGO|MULTILINGUAL|MULTISUB|MULTI LANGUAGE)\b/.test(n))return 'Multi-language';
    if(/\bASIA(?:N)?\b/.test(n))return 'Asian';
    if(/\b(?:PERSIAN|FARSI|IRANIAN)\b/.test(n))return 'Persian';
    if(/\bIRAN\b/.test(n))return 'Iran';
    if(/\b(?:FRANCE|FRENCH)\b/.test(n))return 'French';
    if(/\b(?:POLAND|POLISH)\b/.test(n))return 'Polish';
    if(/\b(?:TURKEY|TURKISH)\b/.test(n))return 'Turkish';
    if(/\b(?:JAPAN|JAPANESE)\b/.test(n))return 'Japanese';
    if(/\b(?:CHINA|CHINESE)\b/.test(n))return 'Chinese';
    if(/\b(?:THAI|THAILAND)\b/.test(n))return 'Thai';
    if(/\b(?:VIETNAMESE|VIETNAM)\b/.test(n))return 'Vietnamese';
    if(/\b(?:FILIPINO|PHILIPPINES?)\b/.test(n))return 'Filipino';
    if(/\b(?:INDONESIAN|INDONESIA)\b/.test(n))return 'Indonesian';
    if(/\b(?:ITALY|ITALIAN)\b/.test(n))return 'Italian';
    if(/\bGERMAN(?:Y)?\b/.test(n))return 'German';
    if(/\bSPANISH\b/.test(n))return 'Spanish';
    if(/\bDUTCH\b/.test(n))return 'Dutch';
    if(/\bPORTUGUESE\b/.test(n))return 'Portuguese';
    if(/\b(?:SWEDISH|SVENSKA)\b/.test(n))return 'Swedish';
    if(/[\u0600-\u06ff]/.test(n))return 'Arabic';
    if(/[\u0370-\u03ff]/.test(n)||/\bGREEK\b/.test(n))return 'Greek';
    if(/[\u0400-\u04ff]/.test(n)||/\b(?:RUSSIAN|UKRAINIAN|BULGARIAN)\b/.test(n))return 'Slavic';
    if(/\bFRENCH\b/.test(n))return 'French';
    if(/\bITAL(?:Y|IAN)\b/.test(n))return 'Italian';
    if(/\bGERMAN(?:Y)?\b/.test(n))return 'German';
    if(/\bSPANISH\b/.test(n))return 'Spanish';
    if(/\bDUTCH\b/.test(n))return 'Dutch';
    if(/\bPORTUGUESE\b/.test(n))return 'Portuguese';
    if(/\bTURK(?:ISH)?\b/.test(n))return 'Turkish';
    if(/\bAFRICAN\b/.test(n))return 'African';
    if(/\b(?:ACTION|ADULT|ADVENTURE|ANIME|BOXING|CHRISTMAS|COLLECTIONS?|COMEDY|CONCERTS?|CRIME|DOCUMENTAR(?:Y|IES)|DRAMA|FAMILY|FANTASY|HISTORY|HORROR|KIDS?|MANGA|MUSICAL|MUSIC|NEW RELEASES?|ROMANCE|SCIENCE FICTION|SPORTS?|THRILLER|WAR|WESTERN|WORKOUT|MOVIES|FILMS|TOP \d+|IMDB|NEW ADDED|NEW RELEASED)\b/.test(n))return 'UK';
    if(/\bENGLISH\b/.test(n)||/^\s*EN\s*[-:]/.test(n)||/\b(?:BBC|ITV|STV|UTV|CNN|SKY|CHANNEL\s*[45]|MY5|ABC|AMC|BET|BRAVO|CBC|CBS|COMEDY CENTRAL|CW|FOX|FX|HISTORY|MTV|NBC|NICKELODEON|OWN|SHOWTIME|TNT|VH1|SYFY|HALLMARK|LIFETIME|CARTOON NETWORK|WOW TV|ZEUS NETWORK)\b/.test(n)||/\bA\s*&\s*E\+?(?=\s|$)/.test(n))return 'UK';
    return 'Other / unspecified';
  }
  function categoryName(name){
    return String(name||'').normalize('NFKC').replace(/\u00a0/g,' ')
      .replace(/^\s*(?:VOD|MOVIES|SERIES)\s*[-:|]\s*/i,'')
      .replace(/^\s*EN\s*[-:]\s*/i,'')
      .replace(/^\s*[|┃│¦]\s*(?:[A-Z]{2,3}|ASIA|EXYU|PAKISTAN|MULTI\s*\/\s*LINGO)\s*[|┃│¦]\s*/i,'')
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
  function folderSection(name){
    var value=String(name||'').toLocaleLowerCase();
    if(/netflix|amazon|prime video|apple(?: tv)?|disney|hbo|max\b|hulu|paramount|peacock|tubi|discovery\s*\+|britbox|itvx|skyshowtime|espn\s*\+|mubi|crunchyroll|hayu|roku|starz(?:play)?|viaplay|mgm\s*\+/.test(value))return 'Streaming services';
    if(/\b(?:bbc|itv|stv|utv|cnn|hallmark|lifetime|sky|espn|bet\+?|cartoon network|abc(?: network)?|amc(?: network)?|bravo|cbc(?: network)?|cbs(?: network)?|comedy central|cw|fox(?: network)?|fx|mtv|nbc(?: network)?|nickelodeon|own|showtime|tnt(?: network)?|vh1|syfy|wow tv|zeus network)\b/.test(value)||/\ba\s*&\s*e\+?(?=\s|$)/.test(value)||/^\s*history(?: channel)?\s*$/.test(value))return 'TV & networks';
    if(/\btop\s*(?:\d+|movies?|kids?)\b|\bnew\s+(?:release(?:s|d)?|added)\b|\bcollections?\b|\b\d{4}\s*&\s*old\b/.test(value))return 'Collections & popular';
    return 'Genres';
  }
  function folderLogo(name){
    var value=String(name||'').toLocaleLowerCase();
    var logos=[
      [/netflix/,'netflix.svg'],[/amazon|prime video/,'primevideo.png'],[/apple(?: tv)?/,'appletv.svg'],
      [/discovery\s*\+/,'discoveryplus-icon.png'],[/disney/,'disneyplus.svg'],[/espn\s*\+/,'espn.svg'],
      [/hbo|max\b/,'hbomax.svg'],[/hulu/,'hulu.svg'],[/peacock/,'peacock.png'],
      [/paramount/,'paramountplus.svg'],[/tubi/,'tubi.svg'],[/cnn/,'cnn.svg'],[/\bsky\b/,'sky.svg']
    ];
    for(var i=0;i<logos.length;i++)if(logos[i][0].test(value))return 'assets/img/vod/providers/'+logos[i][1];
    return '';
  }
  function folderNetworkMarks(name){
    var value=String(name||'').toLocaleLowerCase(),brands=[
      [/\bbbc\b/,'BBC','bbc'],[/\bitv\b/,'itv','itv'],[/\ba\s*&\s*e\+?(?=\s|$)/,'A&E','ae'],[/\babc(?: network)?\b/,'abc','abc'],
      [/\bamc(?: network)?\b/,'AMC','amc'],[/\bbet\+?\b/,'BET+','bet'],[/\bbravo\b/,'bravo','bravo'],[/\bcbc(?: network)?\b/,'CBC','cbc'],
      [/\bcbs(?: network)?\b/,'CBS','cbs'],[/\bcomedy central\b/,'CC','comedy-central'],[/\bcw\b/,'CW','cw'],[/\bfox(?: network)?\b/,'FOX','fox'],
      [/\bfx\b/,'FX','fx'],[/^\s*history(?: channel)?\s*$/,'HISTORY','history'],[/\bmtv\b/,'MTV','mtv'],[/\bnbc(?: network)?\b/,'NBC','nbc'],
      [/\bnickelodeon\b/,'nick','nickelodeon'],[/\bown\b/,'OWN','own'],[/\bshowtime\b/,'SHOWTIME','showtime'],[/\btnt(?: network)?\b/,'TNT','tnt'],
      [/\bvh1\b/,'VH1','vh1'],[/\bsyfy\b/,'SYFY','syfy'],[/\bwow tv\b/,'WOW','wow'],[/\bzeus network\b/,'ZEUS','zeus'],
      [/\bhallmark\b/,'HALLMARK','hallmark'],[/\blifetime\b/,'LIFETIME','lifetime'],[/\bcartoon network\b/,'CN','cartoon-network'],
      [/\bcrunchyroll\b/,'CR','crunchyroll'],[/\bbritbox\b/,'BRITBOX','britbox'],[/\bitvx\b/,'ITVX','itvx'],[/\bhayu\b/,'hayu','hayu'],
      [/\broku\b/,'ROKU','roku'],[/\bviaplay\b/,'VIAPLAY','viaplay'],[/\bmubi\b/,'MUBI','mubi'],[/\bmgm\s*\+/,'MGM+','mgm'],[/\bstarz(?:play)?\b/,'STARZ','starz']
    ];
    return brands.filter(function(brand){return brand[0].test(value);}).map(function(brand){return {text:brand[1],brand:brand[2]};});
  }
  function folderTile(bucket){
    var sourceCount=new Set(bucket.sources.map(function(source){return String(source.feed);})).size;
    var providerLabel=sourceCount+' '+(sourceCount===1?'provider':'providers'),logo=folderLogo(bucket.name);
    var contents=[h('span',{className:'stalker-vod-folder-title',text:bucket.name}),h('small',{className:'stalker-vod-folder-providers',text:providerLabel})];
    var children=[];
    if(logo){
      var wide=/primevideo|disneyplus|hulu|peacock|espn/.test(logo),brandTone=/disneyplus/.test(logo)?' stalker-vod-folder-logo-disney':/hulu/.test(logo)?' stalker-vod-folder-logo-hulu':'';
      children.push(h('span',{className:'stalker-vod-folder-logo-wrap'+(wide?' stalker-vod-folder-logo-wrap-wide':'')},[h('img',{className:'stalker-vod-folder-logo'+(wide?' stalker-vod-folder-logo-wide':'')+brandTone+(logo==='assets/img/vod/providers/primevideo.png'?' stalker-vod-folder-logo-amazon':''),src:logo,alt:'',loading:'lazy',onError:function(e){var wrap=e.target.parentNode;if(wrap&&wrap.parentNode)wrap.parentNode.removeChild(wrap);}})]));
    }else{
      var networkMarks=folderNetworkMarks(bucket.name);
      if(networkMarks.length)children.push(h('span',{className:'stalker-vod-folder-network-logos','aria-hidden':'true'},networkMarks.map(function(mark){return h('span',{className:'stalker-vod-network-logo stalker-vod-network-logo-'+mark.brand,text:mark.text});})));
    }
    children.push(h('span',{className:'stalker-vod-folder-copy'},contents));
    return h('button',{className:'btn stalker-vod-folder-tile',type:'button','aria-label':'Open '+bucket.name+', available from '+providerLabel,onClick:function(){providerFolderId='';beginBrowse(bucket.key);}},children);
  }
  function rerunScopedSearch(){
    if(!searchAllMode)return;
    var term=search.value.trim();
    if(groupsLoading){query=term;search.value=term;titlesStarted=true;status.textContent='Preparing catalogue…';return;}
    reset();query=term;search.value=term;titlesStarted=true;
    renderResults();
    if(titlesStarted)scan(false);
  }
  function beginBrowse(key){
    category=key;searchAllMode=false;showFolderBrowser=false;query='';search.value='';letter='#';
    reset();renderCategories();titlesStarted=true;scan(false);
  }
  function beginProviderCategory(feedId,categoryId){
    var source=categories.find(function(c){return c.feed===feedId&&c.id===categoryId;});
    var key='provider-folder|'+feedId+'|'+categoryId;
    providerFolderId=feedId;
    providerSelections[key]={name:source?categoryName(source.name):'All titles',sources:[source||{feed:feedId,id:''}]};
    beginBrowse(key);
  }
  function showProviderFolders(feedId){
    serial++;loading=false;clearTimeout(timer);providerFolderId=feedId||'';category='';searchAllMode=false;showFolderBrowser=true;titlesStarted=false;
    query='';if(search)search.value='';tasks=[];reset();renderCategories();renderResults();
  }
  function browseDiscovery(){
    serial++;loading=false;titlesStarted=false;category='';searchAllMode=false;
    query='';search.value='';letter='#';showFolderBrowser=true;providerFolderId='';tasks=[];
    reset();renderCategories();renderResults();
  }
  // Only the user opening a section changes its remembered open state.
  var accordionStates={};
  function renderCategories(){
    if(!categoryStatus||!languageSelect)return;
    categoryStatus.replaceChildren();
    var available=Array.from(new Set(['UK'].concat(categories.map(function(c){return categoryLanguage(c.name);})))),selectedLanguage=language;
    if(selectedLanguage&&available.indexOf(selectedLanguage)<0)available.push(selectedLanguage);
    languageSelect.replaceChildren();
    available.sort(function(a,b){return a==='UK'?-1:b==='UK'?1:a.localeCompare(b);}).forEach(function(lang){languageSelect.appendChild(h('option',{value:lang,text:lang==='UK'?'English':lang,selected:lang===selectedLanguage}));});
    languageSelect.appendChild(h('option',{value:'',text:'All languages',selected:!selectedLanguage}));
    if(!categories.length){categoryStatus.appendChild(h('p',{className:'muted',text:groupsLoading?'Loading provider folders…':feeds.length?'Preparing provider folders…':'No enabled Stalker providers are available.'}));return;}
    if(!showFolderBrowser){
      var activeFeed=feeds.find(function(feed){return feed.id===providerFolderId;});
      categoryStatus.appendChild(h('button',{className:'btn ghost stalker-vod-folder-back',type:'button',text:activeFeed?'← '+activeFeed.name+' folders':'← All folders',onClick:function(){if(activeFeed)showProviderFolders(activeFeed.id);else browseDiscovery();}}));
      return;
    }
    if(providerFolderId){
      var feed=feeds.find(function(candidate){return candidate.id===providerFolderId;});
      var rows=categories.filter(function(c){return c.feed===providerFolderId&&(!language||categoryLanguage(c.name)===language);})
        .slice().sort(function(a,b){return categoryName(a.name).localeCompare(categoryName(b.name),undefined,{sensitivity:'base'});});
      categoryStatus.appendChild(h('div',{className:'stalker-vod-folder-heading'},[h('button',{className:'btn ghost',type:'button',text:'← Providers',onClick:function(){showProviderFolders('');}}),h('h2',{text:feed?feed.name:'Provider folders'})]));
      if(!rows.length)categoryStatus.appendChild(h('p',{className:'muted',text:'No folders match this language.'}));
      else{
        var folderGrid=h('div',{className:'stalker-vod-provider-grid'});
        rows.forEach(function(row){folderGrid.appendChild(h('button',{className:'btn stalker-vod-folder-tile',type:'button',text:categoryName(row.name),onClick:function(){beginProviderCategory(providerFolderId,row.id);}}));});
        categoryStatus.appendChild(folderGrid);
      }
      var allButton=h('button',{className:'btn ghost',type:'button',text:'Browse all titles from this provider',onClick:function(){beginProviderCategory(providerFolderId,'');}});
      categoryStatus.appendChild(allButton);return;
    }
    var providerCount=feeds.length;
    var folders=categoryBuckets().filter(function(bucket){return !language||bucket.language===language;});
    var folderGroups={};
    folders.forEach(function(bucket){(folderGroups[bucket.language]||(folderGroups[bucket.language]=[])).push(bucket);});
    var languages=Object.keys(folderGroups).sort(function(a,b){return a==='UK'?-1:b==='UK'?1:a.localeCompare(b,undefined,{sensitivity:'base'});});
    categoryStatus.appendChild(h('div',{className:'stalker-vod-folder-heading'},[h('h2',{text:'Folders from '+providerCount+' providers'})]));
    if(!folders.length){categoryStatus.appendChild(h('p',{className:'muted',text:'No folders match this language.'}));return;}
    var sections=h('div',{className:'stalker-vod-provider-browser'});
    languages.forEach(function(lang){
      var groupFolders=folderGroups[lang].sort(function(a,b){return a.name.localeCompare(b.name,undefined,{sensitivity:'base'});});
      var section=h('section',{className:'stalker-vod-provider-section'},[h('h2',{text:lang==='UK'?'English':lang})]);
      var featuredNewRelease=kind==='vod'&&lang==='UK'?groupFolders.find(function(bucket){return /^new released$/i.test(bucket.name);})||groupFolders.find(function(bucket){return /^new release(?:d)?(?:\s|$)/i.test(bucket.name);}):null;
      if(featuredNewRelease){
        groupFolders=groupFolders.filter(function(bucket){return bucket!==featuredNewRelease;});
        section.appendChild(h('div',{className:'stalker-vod-featured-folder'},[h('h3',{text:'New Released'}),folderTile(featuredNewRelease)]));
      }
      var sectionOrder=['Streaming services','TV & networks','Collections & popular','Genres'],sectionsByName={};
      groupFolders.forEach(function(bucket){var sectionName=folderSection(bucket.name);(sectionsByName[sectionName]||(sectionsByName[sectionName]=[])).push(bucket);});
      sectionOrder.forEach(function(sectionName){
        var buckets=sectionsByName[sectionName];if(!buckets||!buckets.length)return;
        var detail=h('details',{className:'stalker-vod-folder-category',open:['Streaming services','TV & networks','Genres'].indexOf(sectionName)>=0},[
          h('summary',{text:sectionName+' · '+buckets.length}),h('div',{className:'stalker-vod-provider-grid'})
        ]);
        buckets.forEach(function(bucket){detail.lastElementChild.appendChild(folderTile(bucket));});
        section.appendChild(detail);
      });
      sections.appendChild(section);
    });
    categoryStatus.appendChild(sections);
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
      var languageCategories=categories.filter(function(c){return categoryLanguage(c.name)===fav.language&&enabled.some(function(f){return f.id===c.feed;});});
      function isPreferred(c){return preferred.some(function(p){return p.feed===c.feed&&p.id===c.id;});}
      languageCategories.filter(isPreferred).forEach(function(c){targets.push({feed:enabled.find(function(f){return f.id===c.feed;}),category:c.id});});
      // Search each connection's full catalogue before walking unrelated folders.
      // This catches a favourite that was visible in a merged results list but
      // lives outside its saved folder or beyond that folder's first few pages.
      enabled.forEach(function(feed){targets.push({feed:feed,category:''});});
      languageCategories.filter(function(c){return !isPreferred(c);}).slice(0,24).forEach(function(c){
        targets.push({feed:enabled.find(function(f){return f.id===c.feed;}),category:c.id});
      });
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
      var cachedItem=titles.find(function(item){return normalized(item.name)===job.fav.normalized&&(!job.fav.year||!year(item)||year(item)===job.fav.year);});
      var cachedSources=cachedItem&&Array.isArray(cachedItem.sources)?cachedItem.sources.filter(function(source){
        return source&&options.connections.some(function(feed){return feed.enabled&&String(feed.id)===String(source.feed);});
      }):[];
      if(cachedItem&&cachedSources.length){
        job.sources=cachedSources.slice();
        job.found={feed:cachedSources[0].feed,item:Object.assign({},cachedItem,cachedSources[0],{sources:cachedSources.slice()})};
        job.button.disabled=false;
        usePoster(cachedItem);
        finish();
        return;
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
    search=h('input',{type:'search',value:query,placeholder:'Search '+(searchMode==='title'?(kind==='vod'?'movies':'series'):searchMode+'s')+'…','aria-label':'Search all enabled Stalker providers'});
    search.oninput=function(){query=search.value;renderResults();};
    status=h('p',{className:'muted',role:'status'});
    categoryStatus=h('section');
    results=h('div',{className:'stalker-vod-grid'});
    next=h('button',{className:'btn',text:'Load 48 more',onClick:function(){
      visibleLimit+=48;renderResults();
      if(titles.filter(fits).length<visibleLimit&&tasks.some(function(t){return progress[t.key]&&!progress[t.key].done;}))scan(false);
    }});
    function selector(label,choices,value,change){return h('label',{className:'stalker-vod-filter'},[h('span',{text:label}),h('select',{'aria-label':label,onChange:function(e){change(e.target.value);}},choices.map(function(pair){return h('option',{value:pair[0],text:pair[1],selected:pair[0]===value});}))]);}
    var mode=selector('Search by',[['title',kind==='vod'?'Movies':'Series'],['actor','Actor'],['director','Director']],searchMode,function(value){searchMode=value;search.placeholder='Search '+(value==='title'?(kind==='vod'?'movies':'series'):value+'s')+'…';renderResults();});
    languageSelect=h('select',{'aria-label':'Language',onChange:function(e){chooseLanguage(e.target.value);}});
    genreSelect=h('select',{'aria-label':'Genre',onChange:function(e){genreFilter=e.target.value;applyCatalogueFilterChange();}});
    var sort=selector('Sort by',[['provider','Default order'],['az','A–Z'],['za','Z–A'],['rating','Highest rating'],['newest','Newest release'],['oldest','Oldest release'],['added','Recently added']],sortOrder,function(value){sortOrder=value;fullSortRequested=value!=='provider';applyCatalogueFilterChange();});
    var rating=selector('Minimum rating',[['','Any rating'],['9','9+'],['8','8+'],['7','7+'],['6','6+'],['5','5+']],minRating,function(value){minRating=value;applyCatalogueFilterChange();});
    var years=[['','Any year']];for(var y=new Date().getFullYear()+1;y>=1900;y--)years.push([String(y),String(y)]);
    var release=selector('Release year',years,yearFilter,function(value){yearFilter=value;applyCatalogueFilterChange();});
    function submit(){searchAllMode=true;showFolderBrowser=false;category='';providerFolderId='';renderCategories();rerunScopedSearch();}
    var clearFilters=h('button',{className:'btn ghost',type:'button',text:'Reset filters',onClick:function(){query='';genreFilter='';minRating='';yearFilter='';sortOrder='provider';fullSortRequested=false;searchMode='title';render();submit();}});
    var favourites=h('button',{className:'btn ghost',type:'button',text:'Favourites',onClick:function(){serial++;titlesStarted=false;loading=false;tasks=[];showFolderBrowser=false;renderCategories();showFavourites();}});
    var discover=h('button',{className:'btn ghost',type:'button',text:'Discover',onClick:browseDiscovery});
    host.append(h('form',{className:'stalker-searchbar stalker-vod-search-top',onSubmit:function(e){e.preventDefault();submit();}},[discover,search,mode,h('button',{className:'btn primary',type:'submit',text:'Search'}),favourites]),h('div',{className:'stalker-vod-filters'},[
      h('label',{className:'stalker-vod-filter'},[h('span',{text:'Language'}),languageSelect]),h('label',{className:'stalker-vod-filter'},[h('span',{text:'Genre'}),genreSelect]),sort,rating,release,clearFilters
    ]),categoryStatus,status,results,next);
    renderGenreFilter();renderCategories();
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
      var sourceItemKey=String(feed.id)+'|'+String(item.id),prior=seenSourceItems[sourceItemKey];
      if(prior){['poster','description','actors','cast','director','age','rating_mpaa','genres_str','genre','duration','time','rating_imdb','audio_languages','subtitle_languages'].forEach(function(field){if(!prior[field]&&item[field])prior[field]=item[field];});return;}
      var source={id:item.id,feed:feed.id,connection:feed.name,kind:item.kind,playable:item.playable,labels:prefixParts(item).labels,poster:item.poster||'',posterCandidates:Array.isArray(item.posterCandidates)?item.posterCandidates:[]};
      var part=numberedPart(item);
      var k=part?'numbered|'+feed.id+'|'+part.key:key(item),group=seen[k];
      // Without a year, avoid merging unrelated similarly named releases.
      if(!part&&!year(item))k+='|'+feed.id;
      group=seen[k];
      if(!group){group=Object.assign({},item,{sources:[],groupLabels:[]});if(part){group.name=part.name;group.numberedParts=[];}seen[k]=group;titles.push(group);}
      seenSourceItems[sourceItemKey]=group;
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
    var unfinished=tasks.some(function(t){return progress[t.key]&&!progress[t.key].done;});
    var ordered=titles.filter(fits);
    function numeric(item,field){if(field==='year')return Number(year(item))||0;if(field==='added'){var raw=item.added;return /^\d+$/.test(String(raw||''))?Number(raw)*1000:Date.parse(raw)||0;}return Number(item.rating_imdb||item.rating)||0;}
    if(sortOrder!=='provider')ordered.sort(function(a,b){
      var names=title(a).localeCompare(title(b),undefined,{numeric:true,sensitivity:'base'});
      if(sortOrder==='az'||sortOrder==='za')return sortOrder==='az'?names:-names;
      var field=sortOrder==='rating'?'rating':sortOrder==='added'?'added':'year',av=numeric(a,field),bv=numeric(b,field);
      if(!av||!bv)return av?-1:bv?1:names;
      return (sortOrder==='oldest'?av-bv:bv-av)||names;
    });
    ordered.slice(0,visibleLimit).forEach(function(item){
      var sources=item.sources||[],chosen=sources.find(function(source){return !providerFolderId||String(source.feed)===providerFolderId;})||sources[0];
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
    if(!ordered.length)results.appendChild(h('p',{className:'muted',text:titlesStarted?(unfinished?(loading?'No matches in the pages received yet. Catalogue loading…':'No matches in the pages loaded yet. Load more to continue.'):'No matching titles found.'):'Search to browse titles.'}));
    var done=tasks.filter(function(t){return progress[t.key]&&progress[t.key].done;}).length;
    var failed=tasks.filter(function(t){return progress[t.key]&&progress[t.key].failed;}).length;
    status.textContent=ordered.length+' matching titles in '+(searchAllMode?(language?(language==='UK'?'English':language)+' folders':'All languages'):(group||'Selected folder'))+(unfinished?(loading?' so far · loading catalogue':' so far · more pages available'):'')+(searchMode==='actor'?' · matching available cast':searchMode==='director'?' · matching available directors':'')+' · '+done+'/'+tasks.length+' sources finished'+(failed?' · '+failed+' unavailable':'');
    next.hidden=!titlesStarted||!(ordered.length>visibleLimit||tasks.some(function(t){return progress[t.key]&&!progress[t.key].done;}));
    next.disabled=loading;
  }
  function scan(refresh){
    if(loading||!active||!titlesStarted)return;
    clearTimeout(timer);
    if(searchAllMode&&language&&groupsLoading){status.textContent='Preparing the selected language…';return;}
    var requestQuery=searchAllMode&&searchMode==='title'?providerSearchTerm(query):'';
    if(tasks.length&&scanQuery!==requestQuery){rerunScopedSearch();return;}
    scanQuery=requestQuery;
    if(!tasks.length){
      var bucket=providerSelections[category]||categoryBuckets().find(function(c){return c.key===category;});
      var scopedFeeds=feeds.filter(function(f){return !providerFolderId||String(f.id)===providerFolderId;});
      tasks=bucket?bucket.sources.map(function(c){return {key:c.feed+'|'+c.id,feed:feeds.find(function(f){return f.id===c.feed;}),category:c.id};}):
        searchAllMode&&language?categories.filter(function(c){return categoryLanguage(c.name)===language&&(!providerFolderId||String(c.feed)===providerFolderId);}).map(function(c){return {key:c.feed+'|'+c.id,feed:feeds.find(function(f){return f.id===c.feed;}),category:c.id};}):
        scopedFeeds.map(function(f){return {key:f.id+'|*',feed:f,category:''};});
      // A missing category listing must not silently exclude an enabled provider.
      if(searchAllMode&&language)scopedFeeds.forEach(function(feed){
        if(!categories.some(function(c){return c.feed===feed.id;}))tasks.push({key:feed.id+'|*',feed:feed,category:''});
      });
      tasks=tasks.filter(function(t){return !!t.feed;});
      // Interleave provider folders so the first batch reaches every connection
      // before spending its request budget on one provider's many categories.
      var queues=scopedFeeds.map(function(feed){return tasks.filter(function(t){return t.feed.id===feed.id;});}),interleaved=[];
      while(queues.some(function(queue){return queue.length;}))queues.forEach(function(queue){if(queue.length)interleaved.push(queue.shift());});
      tasks=interleaved;
      tasks.forEach(function(t){progress[t.key]={page:1,done:false,failed:false,pageSignatures:{},seenIds:{}};});
    }
    loading=true;var token=++serial;
    var pending=tasks.slice(scanCursor).concat(tasks.slice(0,scanCursor)).filter(function(t){return progress[t.key]&&!progress[t.key].done;}).slice(0,12);
    var index=0,attempts=0,pagesPerSource=1;
    function step(){
      if(!valid(token))return;
      if(index>=pending.length){
        loading=false;renderResults();
        if((titles.filter(fits).length<visibleLimit||shouldScanAllPages()||query.trim())&&tasks.some(function(t){return progress[t.key]&&!progress[t.key].done;})){
          status.textContent+=' · Checking remaining pages…';
          timer=setTimeout(function(){if(valid(token))scan(false);},500);
        }
        return;
      }
      var task=pending[index],p=progress[task.key];
      if(attempts>=pagesPerSource||!p||p.done){index++;attempts=0;step();return;}
      attempts++;scanCursor=(tasks.indexOf(task)+1)%Math.max(1,tasks.length);
      status.textContent='Loading '+task.feed.name+' (page '+p.page+')…';
      var params=new URLSearchParams({kind:kind,feed:task.feed.id,category:task.category,q:requestQuery,page:String(p.page),refresh:refresh?'1':''});
      options.api('stalker_vod_catalogue',params.toString()).then(function(result){
        if(!valid(token))return;
        if(!result||!result.ok)throw Error(result&&result.error||'Portal unavailable');
        var rows=(result.items||[]),ids=rows.map(function(item){return String(item&&item.id||'');}).filter(Boolean),signature=ids.length+':'+ids.reduce(function(hash,id){for(var n=0;n<id.length;n++)hash=Math.imul(hash^id.charCodeAt(n),16777619)>>>0;return Math.imul(hash^124,16777619)>>>0;},2166136261).toString(36);
        p.pageSignatures=p.pageSignatures||{};p.seenIds=p.seenIds||{};
        if(!ids.length||p.pageSignatures[signature]){p.done=true;return;}
        p.pageSignatures[signature]=true;
        var fresh=rows.filter(function(item){var id=String(item&&item.id||'');if(!id||p.seenIds[id])return false;p.seenIds[id]=true;return true;});
        if(!fresh.length){p.done=true;return;}
        add(fresh,task.feed);p.page++;p.done=!result.hasMore||p.page>10000;
        if(p.done){index++;attempts=0;}
      }).catch(function(){if(valid(token)){p.done=true;p.failed=true;index++;attempts=0;}})
        .then(function(){if(valid(token)){renderResults();step();}});
    }
    step();
  }
  function snapshot(){
    return {kind:kind,language:language,category:category,providerFolderId:providerFolderId,providerSelections:providerSelections,query:query,genreFilter:genreFilter,scanCursor:scanCursor,scanQuery:scanQuery,searchMode:searchMode,sortOrder:sortOrder,fullSortRequested:fullSortRequested,minRating:minRating,yearFilter:yearFilter,searchAllMode:searchAllMode,
      titles:titles.slice(),categories:categories.slice(),progress:JSON.parse(JSON.stringify(progress)),
      tasks:tasks.map(function(t){return {key:t.key,feedId:t.feed&&t.feed.id,category:t.category};}),
      titlesStarted:titlesStarted,scrollY:window.scrollY||document.documentElement.scrollTop||0};
  }
  function restore(saved){
    if(!saved||saved.kind!==kind)return false;
    stop();active=true;serial++;feeds=options.connections.filter(function(f){return f.enabled;});
    if(typeof saved.language==='string')language=saved.language;
    category=saved.category||'';providerFolderId=saved.providerFolderId||saved.providerFilter||'';providerSelections=saved.providerSelections||{};query=saved.query||'';genreFilter=saved.genreFilter||'';scanCursor=saved.scanCursor||0;scanQuery=saved.scanQuery===undefined?(saved.query||''):saved.scanQuery;searchMode=saved.searchMode||'title';sortOrder=saved.sortOrder||'provider';fullSortRequested=!!saved.fullSortRequested;minRating=saved.minRating||'';yearFilter=saved.yearFilter||'';searchAllMode=!!saved.searchAllMode;showFolderBrowser=!(category||searchAllMode);
    titles=(saved.titles||[]).slice();categories=(saved.categories||[]).slice();progress=saved.progress||{};Object.keys(progress).forEach(function(key){var p=progress[key];p.pageSignatures=p.pageSignatures||{};p.seenIds=p.seenIds||{};});
    seenSourceItems={};titles.forEach(function(item){(item.sources||[]).forEach(function(source){seenSourceItems[String(source.feed)+'|'+String(source.id)]=item;});(item.numberedParts||[]).forEach(function(part){seenSourceItems[String(part.feed)+'|'+String(part.id)]=item;});});
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
    stop();active=true;kind=value;letter='#';query='';genreFilter='';providerFolderId='';searchAllMode=false;titlesStarted=false;showFolderBrowser=true;category='';categories=[];providerSelections={};sortOrder=value==='vod'?'rating':'provider';fullSortRequested=false;groupsLoading=true;reset();render();
    // Load the account preference before choosing the default provider.
    var showToken=serial;
    loadAccountSettings().then(function(){
      if(active&&kind===value&&serial===showToken)loadCategories(false);
    });
  },stop:stop,snapshot:snapshot,restore:restore};
};
})();
