(function(){
  'use strict';
  var base=window.__STREAM_BASE__||'/', version=window.__ASSET_VERSION__||'', preference='auto', reasons=[];
  try {preference=localStorage.getItem('vaultcast-browser-mode')||'auto';}catch(e){}
  var match=location.search.match(/(?:^|[?&])compat=(on|off|auto)(?:&|$)/);
  if(match){preference=match[1];try{localStorage.setItem('vaultcast-browser-mode',preference);}catch(e){}}
  function supports(rule,value){try{return !!(window.CSS&&CSS.supports&&CSS.supports(rule,value));}catch(e){return false;}}
  if(!supports('display','grid'))reasons.push('grid layout');
  if(!supports('aspect-ratio','2 / 3')||!supports('width','min(700px, 95vw)')||!supports('font-size','clamp(16px, 2vw, 32px)'))reasons.push('responsive layout');
  if(!supports('--vaultcast-check','1'))reasons.push('CSS variables');
  if(!window.Promise||!Promise.prototype.finally)reasons.push('modern promises');
  if(!window.fetch||!window.URL||!window.Map||!window.Set||!window.AbortController)reasons.push('browser APIs');
  if(!Element.prototype.replaceChildren||!Element.prototype.closest)reasons.push('modern DOM');
  var reduced=preference==='on'||(preference!=='off'&&reasons.length>0);
  window.VaultcastCompatibility={active:reduced,preference:preference,reasons:reasons};
  document.documentElement.className+=(reduced?' compatibility-mode':'');
  function asset(path){return base+'index.php?asset='+path+'&v='+encodeURIComponent(version);}
  function style(path){var link=document.createElement('link');link.rel='stylesheet';link.href=asset(path);document.head.appendChild(link);}
  function failure(){var panel=document.createElement('p'),link=document.createElement('a');panel.style.cssText='padding:20px;background:#111;color:white;font:18px sans-serif';link.href=base+'?compat=on';link.appendChild(document.createTextNode('Open compatibility mode'));panel.appendChild(document.createTextNode('The interface could not load. '));panel.appendChild(link);document.body.appendChild(panel);}
  var scripts=reduced?['compatibility.js']:['routes.js','netplay_sync.js','request-cache.js','chat.js','home-hub.js','home-extras.js','live-search.js','app.js'];
  if(reduced)style('assets/css/compatibility.css');else{style('assets/css/app.css');style('assets/css/home-hub.css');}
  scripts.forEach(function(file){var script=document.createElement('script');script.async=false;script.src=asset('assets/js/'+file);script.onerror=failure;document.head.appendChild(script);});
})();
