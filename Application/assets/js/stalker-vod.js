(function(){
'use strict';
window.VaultcastStalkerVod=function(host,options){
  var h=options.h,active=false,kind='vod',serial=0,timer=null,letter='#',query='',feeds=[],progress={},titles=[],seen={},loading=false;
  var status,results,next,search,letters,exportButton;
  function valid(token){return active&&host.isConnected&&options.current()&&token===serial;}
  // Provider names often begin with quality tags (e.g. "4K - Borderlands").
  // These are not part of the title and must not decide its A-Z letter.
  function title(item){
    var name=String(item.name||'').trim();
    // Repeat because a provider can stack country, language and quality tags.
    for(var n=0;n<8;n++){
      var cleaned=name
        .replace(/^\s*[┃|]\s*[A-Z]{2,3}\s*[┃|]\s*/i,'')
        .replace(/^\s*\[\s*[A-Z]{2,3}\s*\]\s*[-:|]?\s*/i,'')
        .replace(/^\s*(?:(?:4K|8K|UHD|FHD|FULL HD|HD|SD)\s*[-|:]\s*|\[\s*(?:4K|8K|UHD|FHD|HD|SD)\s*\]\s*)/i,'')
        .trim();
      if(cleaned===name)break;
      name=cleaned;
    }
    return name;
  }
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
  function fits(item){return (!query||title(item).toLocaleLowerCase().includes(query.toLocaleLowerCase()))&&(letter==='#'||initial(item)===letter);}
  function reset(){
    serial++;progress={};titles=[];seen={};loading=false;
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
  function stop(){active=false;serial++;clearTimeout(timer);if(exportJob)exportJob.cancelled=true;}
  function render(){
    host.replaceChildren();
    search=h('input',{type:'search',value:query,placeholder:'Search loaded provider titles…','aria-label':'Search Stalker VOD'});
    search.oninput=function(){query=search.value;renderResults();};
    status=h('p',{className:'muted',role:'status'});
    results=h('div',{className:'stalker-vod-grid'});
    next=h('button',{className:'btn',text:'Load more provider pages',onClick:function(){scan(false);}});
    exportButton=h('button',{className:'btn ghost',text:'Export raw VOD names (.txt)',onClick:exportRaw});
    letters=h('div',{className:'row-actions',role:'group','aria-label':'Browse titles by first letter'});
    '#ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('').forEach(function(ch){
      var b=h('button',{className:'btn ghost',text:ch==='#'?'All':ch,'aria-label':ch==='#'?'All provider titles (diagnostic view)':'Titles starting with '+ch,onClick:function(){if(letter===ch)return;letter=ch;renderLetters();renderResults();}});
      b.dataset.letter=ch;letters.appendChild(b);
    });
    host.append(h('div',{className:'stalker-vod-library-heading'},[
      h('div',{},[h('h2',{text:kind==='vod'?'Movies':'Series'}),h('p',{className:'muted',text:'Browse the titles loaded from portal pages. All and A–Z reuse the same results; Load more fetches another small batch.'})]),
      h('div',{className:'row-actions'},[exportButton,h('button',{className:'btn ghost',text:'Refresh',onClick:function(){reset();scan(true);}})])
    ]),letters,h('form',{className:'stalker-searchbar',onSubmit:function(e){e.preventDefault();renderResults();}},[search]),status,results,next);
    renderLetters();
  }
  function renderLetters(){
    Array.prototype.forEach.call(letters.children,function(b){
      var chosen=b.dataset.letter===letter;
      b.className=chosen?'btn':'btn ghost';
      b.setAttribute('aria-pressed',String(chosen));
    });
  }
  function add(rows,feed){
    (rows||[]).forEach(function(item){
      if(!item||!item.name||!item.id)return;
      var source={id:item.id,feed:feed.id,connection:feed.name,kind:item.kind,playable:item.playable};
      var k=key(item),group=seen[k];
      // Without a year, avoid merging unrelated similarly named releases.
      if(!year(item))k+='|'+feed.id;
      group=seen[k];
      if(!group){group=Object.assign({},item,{sources:[]});seen[k]=group;titles.push(group);}
      if(!group.sources.some(function(x){return x.feed===source.feed&&x.id===source.id;}))group.sources.push(source);
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
      results.appendChild(h('article',{className:'stalker-vod-card'},[
        h('button',{className:'stalker-vod-cover','aria-label':'Open '+item.name,onClick:open},[
          h('span',{className:'stalker-vod-placeholder',text:item.name.slice(0,1)}),
          item.poster?h('img',{src:item.poster,alt:'',loading:'lazy',referrerpolicy:'no-referrer',onError:function(e){e.target.remove();}}):null]),
        h('div',{className:'stalker-vod-copy'},[
          h('div',{className:'row-head'},[h('h3',{text:title(item),title:item.name}),star]),
          h('p',{className:'muted',text:year(item)}),
          select||h('small',{className:'muted',text:chosen.connection||''}),
          h('div',{className:'row-actions'},[button,download])
        ])
      ]));
    });
    if(!ordered.length)results.appendChild(h('p',{className:'muted',text:'No provider titles in the pages retrieved. Check connection and pagination.'}));
    var done=feeds.filter(function(f){return progress[f.id]&&progress[f.id].done;}).length;
    var failed=feeds.filter(function(f){return progress[f.id]&&progress[f.id].failed;}).length;
    status.textContent=ordered.length+' titles for '+(letter==='#'?'All':letter)+' (loaded so far) · '+done+'/'+feeds.length+' connections finished'+(failed?' · '+failed+' unavailable':'');
    next.hidden=!feeds.some(function(f){return progress[f.id]&&!progress[f.id].done;});
    next.disabled=loading;
  }
  function scan(refresh){
    if(loading||!active)return;
    loading=true;var token=++serial;
    var pending=feeds.filter(function(f){return progress[f.id]&&!progress[f.id].done;});
    // Fetch a small bounded batch per provider; a single page often has no titles
    // starting with the selected letter, even when the portal contains many.
    var pagesPerFeed=3;
    var index=0,attempts=0;
    function step(){
      if(!valid(token))return;
      if(index>=pending.length){loading=false;renderResults();return;}
      var feed=pending[index],p=progress[feed.id];
      if(attempts>=pagesPerFeed){index++;attempts=0;step();return;}
      attempts++;
      if(!p||p.done){step();return;}
      status.textContent='Loading '+feed.name+' (page '+p.page+')…';
      // Never use provider single-letter search; sort and filter loaded pages locally.
      var params=new URLSearchParams({kind:kind,feed:feed.id,page:String(p.page),refresh:refresh?'1':''});
      options.api('stalker_vod_catalogue',params.toString()).then(function(result){
        if(!valid(token))return;
        if(!result||!result.ok)throw Error(result&&result.error||'Portal unavailable');
        add(result.items,feed);p.page++;
        p.done=!result.hasMore||p.page>10000;
        if(p.done){index++;attempts=0;}
      }).catch(function(){if(valid(token)){p.done=true;p.failed=true;index++;attempts=0;}}).then(function(){if(valid(token)){renderResults();step();}});
    }
    step();
  }
  return {show:function(value){if(active&&kind===value)return;stop();active=true;kind=value;letter='#';query='';render();reset();scan(false);},stop:stop};
};
})();
