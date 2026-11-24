(function(){
  var config=window.__VC_COMPAT_OFFLINE__,host=document.getElementById('compat-offline-host'),status=document.getElementById('compat-offline-status');
  if(!config||!host||!status)return;
  function say(value){while(status.firstChild)status.removeChild(status.firstChild);status.appendChild(document.createTextNode(value));}
  if(!window.VaultcastOffline||!window.Promise||!window.fetch||!window.indexedDB||!window.Blob||!window.URL||!URL.createObjectURL||!window.crypto||!crypto.getRandomValues||!window.AbortController||!window.Element||!Element.prototype.replaceChildren||!Element.prototype.remove||!Object.assign||!Array.from||!window.IDBObjectStore||!IDBObjectStore.prototype.getAll){
    say('This browser does not support the local storage and media APIs required for offline videos. Online playback is still available.');return;
  }
  var offline=window.VaultcastOffline;
  offline.init({base:config.base,state:{user:config.user},toast:say});
  if(config.item){
    var button=document.createElement('button');button.type='button';button.appendChild(document.createTextNode('Download '+config.item.title));status.parentNode.insertBefore(button,host);button.onclick=function(){offline.download(config.item,button);};
  }
  offline.render(host);
  if(navigator.serviceWorker&&navigator.serviceWorker.getRegistration){
    navigator.serviceWorker.getRegistration(config.base).then(function(reg){
      if(reg){say('Downloads are stored in this browser. Keep this page open while downloading.');return;}
      return navigator.serviceWorker.register(config.base+'sw.js?v='+encodeURIComponent(config.version)+(config.native?'&assets=node':''),{scope:config.base}).then(function(){say('Downloads are stored in this browser. Keep this page open while downloading.');});
    })['catch'](function(){say('Offline storage is available, but this browser could not register the offline page.');});
  }else say('Downloads are stored in this browser. Opening Vaultcast with no server connection requires service worker support and a secure origin (normally HTTPS).');
})();