(function(){
  'use strict';
  var sequence=0;
  window.VaultcastLiveSearch={attach:function(wrap,input,A){
    var controller,timer,version=0,active=-1,rows=[],cache=new Map(),id='live-search-'+(++sequence);
    var list=A.h('div',{id:id,className:'live-search-results',role:'listbox','aria-label':'Search suggestions'});list.hidden=true;wrap.appendChild(list);
    input.setAttribute('role','combobox');input.setAttribute('aria-autocomplete','list');input.setAttribute('aria-controls',id);input.setAttribute('aria-expanded','false');
    function close(){version++;if(controller)controller.abort();clearTimeout(timer);list.hidden=true;active=-1;input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');}
    function choose(row){close();A.open(row);}
    function paint(items,q){
      list.replaceChildren();active=-1;rows=[];input.removeAttribute('aria-activedescendant');
      items.slice(0,5).forEach(function(it,i){
        var row=A.h('div',{id:id+'-'+i,role:'option','aria-selected':'false',className:'live-search-result'});
        var kind=(it.kind==='show'||it.media_kind==='show'||it.show_key)?'shows':'items';
        var image=A.h('img',{alt:'',width:'40',height:'60',loading:'lazy'}),size=0,sizes=['thumb','medium','full'];
        function src(){return (window.__STREAM_BASE__||'/')+'api/'+kind+'/'+encodeURIComponent(it.show_key||it.id)+'/poster?fetch=0&variant='+sizes[size];}
        image.src=src();image.onerror=function(){if(++size<sizes.length)image.src=src();else image.hidden=true;};
        row.appendChild(image);row.appendChild(A.h('span',{},[A.h('strong',{text:it.title||it.show||'Untitled'}),A.h('small',{text:[it.year,it.system||it.type||it.media_kind].filter(Boolean).join(' · ')})]));
        row.addEventListener('pointerdown',function(e){e.preventDefault();});row.addEventListener('click',function(){choose(it);});list.appendChild(row);rows.push({el:row,item:it});
      });
      if(!items.length)list.appendChild(A.h('p',{className:'live-search-empty',text:'No matching titles'}));
      var more=A.h('button',{type:'button',className:'live-search-more',text:'Search all results for “'+q+'”'});
      more.addEventListener('pointerdown',function(e){e.preventDefault();});more.addEventListener('click',function(){close();input.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',bubbles:true}));});list.appendChild(more);
      list.hidden=false;input.setAttribute('aria-expanded','true');
    }
    input.addEventListener('input',function(){
      clearTimeout(timer);if(controller)controller.abort();var q=input.value.trim(),mine=++version;list.hidden=true;input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');active=-1;
      if(!q)return;
      timer=setTimeout(function(){
        if(cache.has(q)){paint(cache.get(q),q);return;}
        controller=typeof AbortController!=='undefined'?new AbortController():null;
        A.api('/search?q='+encodeURIComponent(q)+'&limit=5&suggest=1'+(A.mode==='play'?'&area=games':'&area=library'),controller?{signal:controller.signal}:{}).then(function(r){
          if(mine!==version||!input.isConnected||document.activeElement!==input)return;
          if(!r||!r.ok)return;var items=r.catalogue||[];cache.set(q,items);if(cache.size>30)cache.delete(cache.keys().next().value);paint(items,q);
        }).catch(function(){if(mine===version)close();});
      },180);
    });
    input.addEventListener('keydown',function(e){
      if(e.key==='Escape'){close();return;}
      if(list.hidden)return;
      if(e.key==='ArrowDown'||e.key==='ArrowUp'){
        e.preventDefault();e.stopImmediatePropagation();if(!rows.length)return;
        active=(active+(e.key==='ArrowDown'?1:-1)+rows.length)%rows.length;
        rows.forEach(function(row,i){row.el.setAttribute('aria-selected',String(i===active));});input.setAttribute('aria-activedescendant',rows[active].el.id);rows[active].el.scrollIntoView({block:'nearest'});
      }else if(e.key==='Enter'&&active>=0){e.preventDefault();e.stopImmediatePropagation();choose(rows[active].item);}else if(e.key==='Enter')close();
    },true);
    wrap.addEventListener('focusout',function(e){if(!wrap.contains(e.relatedTarget))close();});
  }};
})();
