(function(){
'use strict';
window.VaultcastStalkerVod=function(host,options){
  var h=options.h,active=false,kind='vod',serial=0,timer=null,letter='#',query='',feeds=[],progress={},titles=[],seen={},loading=false;
  var status,results,next,search,letters,exportButton,groupExportButton,groups,group='',categories=[],category='',groupsLoading=false,titlesStarted=false,categoryStatus=null,scopeActions=null,prefixGroups=null,showMoreCategories=false,searchAllMode=false,language='',tasks=[],providerSelections={};
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
  var groupExportJob=null,groupExportUrl=null,groupDownloadLink=null;
  function exportGroups(){
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
  function stop(){active=false;serial++;clearTimeout(timer);if(exportJob)exportJob.cancelled=true;}
  function loadCategories(refresh){
    var token=++serial,index=0;
    groupsLoading=true;categories=[];category='';showMoreCategories=false;renderCategories();
    function nextFeed(){
      if(!valid(token))return;
      if(index>=feeds.length){groupsLoading=false;renderCategories();renderResults();if(searchAllMode&&titlesStarted&&!tasks.length)scan(false);return;}
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
          onClick:function(){if(language===value)return;language=value;showMoreCategories=false;renderCategories();rerunScopedSearch();}});
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
    providerSelections={};
    var shown=buckets.filter(function(c){return !activeLanguage||c.language===activeLanguage;});

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
    var section=h('section');section.style.width='100%';section.style.marginBottom='20px';
    var heading=h('h3',{text:activeLanguage?(languageLabel(activeLanguage)+' folders'):'All languages'});
    heading.style.margin='0 0 12px';section.appendChild(heading);
    sections.forEach(function(part){
      if(!part.items.length)return;
      part.items.sort(function(a,b){return a.name.localeCompare(b.name);});
      var accordion=h('details',{className:'stalker-folder-section',open:part.label==='Genres'});
      accordion.appendChild(h('summary',{className:'stalker-folder-section-heading',text:part.label+' ('+part.items.length+')'}));
      var grid=h('div');
      grid.style.display='grid';grid.style.width='100%';grid.style.gridTemplateColumns='repeat(auto-fill,minmax(min(100%,220px),1fr))';grid.style.gap='12px';
      function folderButton(c){
        var card=btn(c.name+' · '+c.sources.length+' '+(c.sources.length===1?'source':'sources'),function(){beginBrowse(c.key);},category===c.key);
        card.className='btn ghost';card.style.whiteSpace='normal';card.style.textAlign='left';card.style.minHeight='58px';card.style.width='100%';card.style.padding='12px';
        if(part.label==='Genres'||part.label==='Collections & special folders'){
          card.className+=' stalker-folder-art-card';
          card.prepend(h('span',{className:'stalker-folder-art',text:artworkFor(c.name,part.label),'aria-hidden':'true'}));
        }
        return card;
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
          grid.appendChild(h('button',{type:'button',className:'stalker-provider-group stalker-provider-action',onClick:function(){beginBrowse(key);}},[
            h('span',{className:'stalker-provider-summary'},[
              art,h('strong',{text:def?def[1]:'Other networks'}),
              h('small',{className:'muted',text:entry.items.length+' folders · '+all.length+' sources'})
            ])
          ]));
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
    return {name:name,year:/^(?:19|20)\d{2}$/.test(release)?release:'',normalized:normalized,tab:item.tab||kind};
  }
  function favouriteId(item){
    var fav=favouriteIdentity(item);
    return fav.tab+'|'+fav.normalized+'|'+fav.year;
  }
  function favouriteEntries(){
    var saved=null;
    try{
      var raw=localStorage.getItem(favouriteKey());
      if(raw!==null){saved=JSON.parse(raw);if(!Array.isArray(saved))saved=[];}
    }catch(e){saved=[];}
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
      return true;
    }catch(e){
      status.textContent='Could not save favourites in browser storage. Check browser storage permissions or available space.';
      return false;
    }
  }
  function findFavourite(favourite,button){
    if(button.disabled)return;
    button.disabled=true;var original=button.textContent;button.textContent='Searching…';
    var activeFeeds=options.connections.filter(function(f){return f.enabled;}),index=0,match=null;
    function normalized(name){return favouriteIdentity({name:name,tab:kind}).normalized;}
    function finish(){
      button.disabled=false;button.textContent=original;
      if(match){options.open(match.feed,Object.assign({},match.item,{feed:match.feed}),button);}
      else status.textContent='Favourite "'+favourite.name+'" was not found on the enabled connections. It remains saved.';
    }
    function next(){
      if(!active||!host.isConnected)return;
      if(match||index>=activeFeeds.length){finish();return;}
      var feed=activeFeeds[index++],page=1;
      function searchPage(){
        if(!active||!host.isConnected)return;
        var params=new URLSearchParams({kind:kind,feed:feed.id,q:favourite.name,page:String(page)});
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
    var favs=favouriteEntries().filter(function(item){return item.tab===kind;});
    results.replaceChildren();
    next.hidden=true;
    status.textContent=favs.length?favs.length+' saved favourites. Select one to find it on the current connections.':'No saved favourites for '+(kind==='vod'?'movies':'series')+'.';
    favs.forEach(function(fav){
      var button=h('button',{className:'btn ghost',text:fav.name+(fav.year?' ('+fav.year+')':''),onClick:function(){findFavourite(fav,button);}});
      button.style.whiteSpace='normal';button.style.textAlign='left';
      results.appendChild(button);
    });
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
      h('div',{},[h('h2',{text:kind==='vod'?'Movies':'Series'}),h('p',{className:'muted',text:'Search all languages or select a language to limit searches to its provider folders. Select a folder to browse its titles.'})]),
      h('div',{className:'row-actions'},[h('div',{style:'display:flex;flex-direction:column;gap:8px'},[exportButton,groupExportButton]),h('button',{className:'btn ghost',text:'Refresh',onClick:function(){reset();loadCategories(true);}})])
     ]),h('form',{className:'stalker-searchbar',onSubmit:function(e){e.preventDefault();if(searchAllMode){rerunScopedSearch();}else renderResults();}},[search]),categoryStatus,status,results,next);
    var searchAllButton=h('button',{className:'btn',type:'button',text:'Search all '+(kind==='vod'?'movies':'series'),onClick:function(){
      var term=search.value.trim();
      searchAllMode=true;category='';query=term;search.value=term;letter='#';
      renderCategories();rerunScopedSearch();
    }});
    var favouritesButton=h('button',{className:'btn ghost',type:'button',text:'Favourites',onClick:function(){
      serial++;titlesStarted=false;loading=false;searchAllMode=false;category='';tasks=[];renderCategories();showFavourites();
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
      if(part&&!group.numberedParts.some(function(p){return p.feed===feed.id&&p.id===item.id;}))group.numberedParts.push({number:part.number,name:item.name,id:item.id,feed:feed.id,connection:feed.name,item:item});
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
      function open(){var src=selected();options.open(src.feed,Object.assign({},item,src,{sources:sources}),button);}
      var button=h('button',{className:'btn',text:item.playable?'View details':kind==='series'?'Browse series':'Explore',onClick:open});
      var download=item.playable&&options.download?h('button',{className:'btn ghost',text:'Add to library',onClick:function(){var src=selected();download.disabled=true;options.download(src.feed,Object.assign({},item,src)).then(function(){download.textContent='Queued';}).catch(function(e){download.disabled=false;status.textContent=e.message;});}}):null;
      var star=h('button',{className:'btn ghost stalker-save'});
      function updateStar(){
        var active=favouriteEntries().some(function(x){return favouriteId(x)===favouriteId(Object.assign({},item,{tab:kind}));});
        star.textContent=active?'★':'☆';
        star.setAttribute('aria-label',(active?'Remove favourite: ':'Save favourite: ')+title(item));
        star.setAttribute('aria-pressed',String(active));
      }
      star.onclick=function(){
        var fav=favouriteIdentity(Object.assign({},item,{tab:kind})),id=favouriteId(fav);
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
        posterImg.onerror=function(){posterImg.remove();};
        posterButton.appendChild(posterImg);
      }
      results.appendChild(h('article',{className:'stalker-vod-card'},[
        posterButton,
        h('div',{className:'stalker-vod-copy'},[
          h('h3',{text:title(item),title:item.name}),
          h('div',{className:'row-head'},[h('span',{className:'muted',text:year(item)||' '}),star]),
          h('div',{className:'row-actions'},[button,download])
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
    return {kind:kind,language:language,category:category,query:query,searchAllMode:searchAllMode,
      titles:titles.slice(),categories:categories.slice(),progress:JSON.parse(JSON.stringify(progress)),
      tasks:tasks.map(function(t){return {key:t.key,feedId:t.feed&&t.feed.id,category:t.category};}),
      titlesStarted:titlesStarted,scrollY:window.scrollY||document.documentElement.scrollTop||0};
  }
  function restore(saved){
    if(!saved||saved.kind!==kind)return false;
    stop();active=true;serial++;feeds=options.connections.filter(function(f){return f.enabled;});
    language=saved.language||'';category=saved.category||'';query=saved.query||'';searchAllMode=!!saved.searchAllMode;
    titles=(saved.titles||[]).slice();categories=(saved.categories||[]).slice();progress=saved.progress||{};
    tasks=(saved.tasks||[]).map(function(t){return {key:t.key,feed:feeds.find(function(f){return f.id===t.feedId;}),category:t.category};}).filter(function(t){return !!t.feed;});
    titlesStarted=!!saved.titlesStarted;seen={};loading=false;groupsLoading=false;
    render();search.value=query;renderCategories();renderResults();
    var target=Number(saved.scrollY)||0;
    requestAnimationFrame(function(){requestAnimationFrame(function(){if(active&&host.isConnected)window.scrollTo(0,target);});});
    return true;
  }
  return {show:function(value){if(active&&kind===value)return;stop();active=true;kind=value;letter='#';query='';render();reset();loadCategories(false);},stop:stop,snapshot:snapshot,restore:restore};
};
})();
