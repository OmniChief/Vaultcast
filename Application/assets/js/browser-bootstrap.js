(function(){
  function start(){
    if(!document.body){setTimeout(start,20);return;}
    var base=window.__STREAM_BASE__||'/',version=window.__ASSET_VERSION__||'',preference='auto',reasons=[];
    try{preference=localStorage.getItem('vaultcast-browser-mode')||'auto';}catch(e){}
    var match=location.search.match(/(?:^|[?&])compat=(on|off|auto)(?:&|$)/);
    if(match){preference=match[1];try{localStorage.setItem('vaultcast-browser-mode',preference);}catch(e){}}
    function supports(rule,value){try{return !!(window.CSS&&window.CSS.supports&&window.CSS.supports(rule,value));}catch(e){return false;}}
    if(!supports('display','grid')||!supports('--vaultcast-check','1'))reasons.push('layout');
    if(!supports('aspect-ratio','2 / 3')||!supports('width','min(700px, 95vw)')||!supports('font-size','clamp(16px, 2vw, 32px)'))reasons.push('responsive layout');
    if(!window.Promise||!window.Promise.prototype['finally'])reasons.push('promises');
    if(!window.fetch||!window.URL||!window.Map||!window.Set||!window.AbortController)reasons.push('browser APIs');
    if(!window.Element||!window.Element.prototype||!window.Element.prototype.replaceChildren||!window.Element.prototype.closest)reasons.push('DOM');
    if(!Array.prototype.forEach||!Array.prototype.map||!Array.prototype.filter||!Array.prototype.indexOf||!String.prototype.trim||!Date.now||!window.JSON||!window.XMLHttpRequest||!document.addEventListener)reasons.push('JavaScript APIs');
    var reduced=preference==='on'||(preference!=='off'&&reasons.length>0);
    window.VaultcastCompatibility={active:reduced,preference:preference,reasons:reasons};
    if(reduced){location.replace(base+'?compat=on&detected=1');return;}
    function asset(path){return base+'index.php?asset='+path+'&v='+encodeURIComponent(version);}
    function style(path){var link=document.createElement('link');link.rel='stylesheet';link.href=asset(path);document.getElementsByTagName('head')[0].appendChild(link);}
    function failure(){var panel=document.createElement('p'),link=document.createElement('a');panel.style.cssText='padding:20px;background:#111;color:white;font:18px sans-serif';link.href=base+'?compat=on';link.appendChild(document.createTextNode('Open compatibility mode'));panel.appendChild(document.createTextNode('The interface could not load. '));panel.appendChild(link);document.body.appendChild(panel);}
    style('assets/css/app.css');style('assets/css/home-hub.css');
    var files=['routes.js','netplay_sync.js','request-cache.js','chat.js','home-hub.js','home-extras.js','live-search.js','theme-play.js','app.js'];
    for(var i=0;i<files.length;i++){var script=document.createElement('script');script.async=false;script.src=asset('assets/js/'+files[i]);script.onerror=failure;document.getElementsByTagName('head')[0].appendChild(script);}
  }
  start();
})();
