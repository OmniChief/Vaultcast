(function(){
'use strict';
var countries = {
  "AF": "Afghanistan",
  "AX": "Aland Islands",
  "AL": "Albania",
  "DZ": "Algeria",
  "AS": "American Samoa",
  "AD": "Andorra",
  "AO": "Angola",
  "AI": "Anguilla",
  "AQ": "Antarctica",
  "AG": "Antigua and Barbuda",
  "AR": "Argentina",
  "AM": "Armenia",
  "AW": "Aruba",
  "AU": "Australia",
  "AT": "Austria",
  "AZ": "Azerbaijan",
  "BS": "Bahamas",
  "BH": "Bahrain",
  "BD": "Bangladesh",
  "BB": "Barbados",
  "BY": "Belarus",
  "BE": "Belgium",
  "BZ": "Belize",
  "BJ": "Benin",
  "BM": "Bermuda",
  "BT": "Bhutan",
  "BO": "Bolivia",
  "BQ": "Bonaire, Sint Eustatius and Saba",
  "BA": "Bosnia and Herzegovina",
  "BW": "Botswana",
  "BV": "Bouvet Island",
  "BR": "Brazil",
  "IO": "British Indian Ocean Territory",
  "BN": "Brunei Darussalam",
  "BG": "Bulgaria",
  "BF": "Burkina Faso",
  "BI": "Burundi",
  "CV": "Cabo Verde",
  "KH": "Cambodia",
  "CM": "Cameroon",
  "CA": "Canada",
  "KY": "Cayman Islands",
  "CF": "Central African Republic",
  "TD": "Chad",
  "CL": "Chile",
  "CN": "China",
  "CX": "Christmas Island",
  "CC": "Cocos (Keeling) Islands",
  "CO": "Colombia",
  "KM": "Comoros",
  "CK": "Cook Islands",
  "CR": "Costa Rica",
  "HR": "Croatia",
  "CU": "Cuba",
  "CW": "Curaçao",
  "CY": "Cyprus",
  "CZ": "Czech Republic",
  "CI": "Côte d'Ivoire",
  "CD": "Democratic Republic of the Congo",
  "DK": "Denmark",
  "DJ": "Djibouti",
  "DM": "Dominica",
  "DO": "Dominican Republic",
  "EC": "Ecuador",
  "EG": "Egypt",
  "SV": "El Salvador",
  "GQ": "Equatorial Guinea",
  "ER": "Eritrea",
  "EE": "Estonia",
  "SZ": "Eswatini",
  "ET": "Ethiopia",
  "FK": "Falkland Islands",
  "FO": "Faroe Islands",
  "FM": "Federated States of Micronesia",
  "FJ": "Fiji",
  "FI": "Finland",
  "FR": "France",
  "GF": "French Guiana",
  "PF": "French Polynesia",
  "TF": "French Southern Territories",
  "GA": "Gabon",
  "GM": "Gambia",
  "GE": "Georgia",
  "DE": "Germany",
  "GH": "Ghana",
  "GI": "Gibraltar",
  "GR": "Greece",
  "GL": "Greenland",
  "GD": "Grenada",
  "GP": "Guadeloupe",
  "GU": "Guam",
  "GT": "Guatemala",
  "GG": "Guernsey",
  "GN": "Guinea",
  "GW": "Guinea-Bissau",
  "GY": "Guyana",
  "HT": "Haiti",
  "HM": "Heard Island and McDonald Islands",
  "VA": "Holy See",
  "HN": "Honduras",
  "HK": "Hong Kong",
  "HU": "Hungary",
  "IS": "Iceland",
  "IN": "India",
  "ID": "Indonesia",
  "IR": "Iran",
  "IQ": "Iraq",
  "IE": "Ireland",
  "IM": "Isle of Man",
  "IL": "Israel",
  "IT": "Italy",
  "JM": "Jamaica",
  "JP": "Japan",
  "JE": "Jersey",
  "JO": "Jordan",
  "KZ": "Kazakhstan",
  "KE": "Kenya",
  "KI": "Kiribati",
  "KW": "Kuwait",
  "KG": "Kyrgyzstan",
  "LA": "Laos",
  "LV": "Latvia",
  "LB": "Lebanon",
  "LS": "Lesotho",
  "LR": "Liberia",
  "LY": "Libya",
  "LI": "Liechtenstein",
  "LT": "Lithuania",
  "LU": "Luxembourg",
  "MO": "Macau",
  "MG": "Madagascar",
  "MW": "Malawi",
  "MY": "Malaysia",
  "MV": "Maldives",
  "ML": "Mali",
  "MT": "Malta",
  "MH": "Marshall Islands",
  "MQ": "Martinique",
  "MR": "Mauritania",
  "MU": "Mauritius",
  "YT": "Mayotte",
  "MX": "Mexico",
  "MD": "Moldova",
  "MC": "Monaco",
  "MN": "Mongolia",
  "ME": "Montenegro",
  "MS": "Montserrat",
  "MA": "Morocco",
  "MZ": "Mozambique",
  "MM": "Myanmar",
  "NA": "Namibia",
  "NR": "Nauru",
  "NP": "Nepal",
  "NL": "Netherlands",
  "NC": "New Caledonia",
  "NZ": "New Zealand",
  "NI": "Nicaragua",
  "NE": "Niger",
  "NG": "Nigeria",
  "NU": "Niue",
  "NF": "Norfolk Island",
  "KP": "North Korea",
  "MK": "North Macedonia",
  "MP": "Northern Mariana Islands",
  "NO": "Norway",
  "OM": "Oman",
  "PK": "Pakistan",
  "PW": "Palau",
  "PA": "Panama",
  "PG": "Papua New Guinea",
  "PY": "Paraguay",
  "PE": "Peru",
  "PH": "Philippines",
  "PN": "Pitcairn",
  "PL": "Poland",
  "PT": "Portugal",
  "PR": "Puerto Rico",
  "QA": "Qatar",
  "CG": "Republic of the Congo",
  "RO": "Romania",
  "RU": "Russia",
  "RW": "Rwanda",
  "RE": "Réunion",
  "BL": "Saint Barthélemy",
  "SH": "Saint Helena, Ascension and Tristan da Cunha",
  "KN": "Saint Kitts and Nevis",
  "LC": "Saint Lucia",
  "MF": "Saint Martin",
  "PM": "Saint Pierre and Miquelon",
  "VC": "Saint Vincent and the Grenadines",
  "WS": "Samoa",
  "SM": "San Marino",
  "ST": "Sao Tome and Principe",
  "SA": "Saudi Arabia",
  "SN": "Senegal",
  "RS": "Serbia",
  "SC": "Seychelles",
  "SL": "Sierra Leone",
  "SG": "Singapore",
  "SX": "Sint Maarten",
  "SK": "Slovakia",
  "SI": "Slovenia",
  "SB": "Solomon Islands",
  "SO": "Somalia",
  "ZA": "South Africa",
  "GS": "South Georgia and the South Sandwich Islands",
  "KR": "South Korea",
  "SS": "South Sudan",
  "ES": "Spain",
  "LK": "Sri Lanka",
  "PS": "State of Palestine",
  "SD": "Sudan",
  "SR": "Suriname",
  "SJ": "Svalbard and Jan Mayen",
  "SE": "Sweden",
  "CH": "Switzerland",
  "SY": "Syria",
  "TW": "Taiwan",
  "TJ": "Tajikistan",
  "TZ": "Tanzania",
  "TH": "Thailand",
  "TL": "Timor-Leste",
  "TG": "Togo",
  "TK": "Tokelau",
  "TO": "Tonga",
  "TT": "Trinidad and Tobago",
  "TN": "Tunisia",
  "TM": "Turkmenistan",
  "TC": "Turks and Caicos Islands",
  "TV": "Tuvalu",
  "TR": "Türkiye",
  "UG": "Uganda",
  "UA": "Ukraine",
  "AE": "United Arab Emirates",
  "GB": "United Kingdom",
  "UM": "United States Minor Outlying Islands",
  "US": "United States of America",
  "UY": "Uruguay",
  "UZ": "Uzbekistan",
  "VU": "Vanuatu",
  "VE": "Venezuela",
  "VN": "Vietnam",
  "VG": "Virgin Islands (British)",
  "VI": "Virgin Islands (U.S.)",
  "WF": "Wallis and Futuna",
  "EH": "Western Sahara",
  "YE": "Yemen",
  "ZM": "Zambia",
  "ZW": "Zimbabwe"
};
window.VaultcastStalkerCountries=function(host,options){
  var h=options.h,active=false,serial=0,country=(options.saved||{}).country||'GB';
  var genre=(options.saved||{}).genre||'General',view=(options.saved||{}).view||'guide';
  var feeds=[],folders=[],failures=[],channels=[],sourcePages={},pageInventory=[],guideByChannel={},guideLogos={},guideFetched={},guideFetchedAt={};
  var selected=null,loading=false,guideBusy=false,guideRefreshPending=false,guideForceRefresh=false,refreshChannels=false,search='',chosen='',offset=0,body,status,list,hero,summary,more,guideButton;
  var overrides={},favourites=[],onlyFavourites=false,clockTimer=null,cache=new Map();
  var preference='vaultcast-stalker-live-favourites-'+options.owner;
  var guideConnectionAt=Date.now(),guideDb=null,lastGuideCleanup=0;
  // Persist final page results, scoped to this signed-in viewer and provider.
  // Expiry is checked on connection, not by a timer during viewing.
  function browserGuideDb(){
    if(guideDb)return guideDb;
    guideDb=new Promise(function(resolve){
      if(!window.indexedDB){resolve(null);return;}
      var settled=false,request;
      function finish(db){if(settled){if(db)db.close();return;}settled=true;resolve(db);}
      try{request=indexedDB.open('vaultcast-stalker-epg-v1',1);}catch(ignore){finish(null);return;}
      request.onupgradeneeded=function(){if(!request.result.objectStoreNames.contains('pages'))request.result.createObjectStore('pages',{keyPath:'key'});};
      request.onsuccess=function(){var db=request.result;db.onversionchange=function(){db.close();guideDb=null;};finish(db);};
      request.onerror=request.onblocked=function(){finish(null);};
    });return guideDb;
  }
  function guideCacheKey(entry){return JSON.stringify(['guide-v3',String(options.owner),country,entry.feed,entry.category,entry.page,!!entry.providerPage,entry.channelIds||[]]);}
  function cachedGuide(key){return browserGuideDb().then(function(db){return new Promise(function(resolve){
    if(!db){resolve(null);return;}try{var request=db.transaction('pages','readonly').objectStore('pages').get(key);
      request.onsuccess=function(){var row=request.result;resolve(row&&Number.isFinite(row.at)&&row.at<=Date.now()&&Date.now()-row.at<86400000&&row.data?row:null);};
      request.onerror=function(){resolve(null);};
    }catch(ignore){resolve(null);}
  });});}
  function removeCachedGuide(key){return browserGuideDb().then(function(db){return new Promise(function(resolve){
    if(!db){resolve();return;}try{var tx=db.transaction('pages','readwrite');tx.objectStore('pages').delete(key);tx.oncomplete=tx.onerror=tx.onabort=function(){resolve();};}catch(ignore){resolve();}
  });});}
  function cacheGuide(key,data){
    browserGuideDb().then(function(db){if(!db)return;try{
      var tx=db.transaction('pages','readwrite'),store=tx.objectStore('pages');
      tx.onerror=function(){};
      store.put({key:key,at:Date.now(),data:{ok:true,programmes:data.programmes||{},channelLogos:data.channelLogos||{}}});
      // Remove expired records without retaining other users' guide data forever.
      if(Date.now()-lastGuideCleanup<3600000)return;
      lastGuideCleanup=Date.now();
      var cursor=store.openCursor();cursor.onsuccess=function(){var item=cursor.result;if(!item)return;if(lastGuideCleanup-Number(item.value.at)>=86400000)item.delete();item.continue();};
    }catch(ignore){}});
  }
  try{overrides=JSON.parse(localStorage.getItem('vaultcast-stalker-country-overrides-'+options.owner)||'{}');}catch(ignore){}
  try{favourites=JSON.parse(localStorage.getItem(preference)||'[]');if(!Array.isArray(favourites))favourites=[];}catch(ignore){}
  var genreOrder=['General','Entertainment','News','Documentary','Sports','Movies','Kids','Music','Lifestyle','Education','Shopping','Religious','Adult','Other'];
  // Editorial order, not claimed Freeview LCNs. Regions and +1 remain distinct services.
  var ukOrder=['bbc one','bbc two','itv1','stv','utv','channel 4','5','itv1 +1','bbc three','bbc four','bbc alba','s4c','bbc scotland','itv2','itv3','itv4','itvbe','e4','e4 extra','more4','film4','5star','5usa','5action','5select','u&dave','dave','u&drama','drama','u&yesterday','yesterday','u&w','w','u&eden','eden','quest','quest red','really','dmax','tlc','challenge','sky mix','sky arts','legend','legend xtra','true crime','true crime xtra','talking pictures tv','pbs america','together tv'];
  function current(token){return active&&host.isConnected&&options.current()&&token===serial;}
  function folderKey(f){return f.feed+'/'+f.id;}
  function name(code){return code==='ZZ'?'Other countries':countries[code]||code;}
  function genreName(value){return value==='Documentary'?'Documentaries':value;}
  function remember(){options.save({country:country,genre:genre,view:view});}
  function expireGuideFlags(fetched,at,now){Object.keys(fetched).forEach(function(id){if(!at[id]||now-at[id]>=86400000){delete fetched[id];delete at[id];}});}
  function reconnectGuide(){
    if(!active)return;guideConnectionAt=Date.now();guideFetched={};guideFetchedAt={};
    cache.forEach(function(saved){saved.guideFetched={};saved.guideFetchedAt={};});requestGuide();
  }
  function stop(){snapshot();active=false;serial++;loading=false;guideBusy=false;clearInterval(clockTimer);window.removeEventListener('online',reconnectGuide);}
  function plain(value){return Array.isArray(value)?value.filter(function(x){return typeof x==='string';}).join(' · '):typeof value==='string'||typeof value==='number'?String(value):'';}
  function normal(raw){
    var value=String(raw||'').normalize('NFKC').trim();
    for(var i=0;i<5;i++){
      var next=value.replace(/^\s*(?:[|┃│¦]\s*(?:UK|GB|US|USA)\s*[|┃│¦]|\[\s*(?:UK|GB|US|USA)\s*\]|(?:UK|GB|US|USA)\s*[-–—:|])\s*/i,'')
        .replace(/^(?:\[(?:UHD|FHD|HD|SD|HEVC|4K)\]|(?:UHD|FHD|HD|SD|HEVC|4K)\s*[-|:])\s*/i,'')
        .replace(/(?:\s*[-|:]\s*|\s+|\s*\[)(?:UHD|FHD|FULL\s*HD|HD|SD|4K|2160P|1080P|720P|480P|576P|HEVC|H\.?265|H\.?264)\]?\s*$/i,'').trim();
      if(next===value||!next)break;value=next;
    }
    return value.replace(/^BBC\s*1(?=\s|$)/i,'BBC One').replace(/^BBC\s*2(?=\s|$)/i,'BBC Two')
      .replace(/^BBC\s*3(?=\s|$)/i,'BBC Three').replace(/^BBC\s*4(?=\s|$)/i,'BBC Four')
      .replace(/^ITV\s+([1-4])(?=\s|$)/i,'ITV$1').replace(/^CHANNEL\s*4(?=\s|$)/i,'Channel 4')
      .replace(/^4\s*SEVEN(?=\s|$)/i,'4SEVEN')
      .replace(/^(?:CHANNEL\s*5|FIVE)(?=\s|$)/i,'5').replace(/^MORE\s*4(?=\s|$)/i,'More4')
      .replace(/^FILM\s*4(?=\s|$)/i,'Film4').replace(/\s*\+\s*1\b/g,' +1');
  }
  function key(item){return normal(item.name).toLocaleLowerCase();}
  function quality(value){var n=String(value).toUpperCase();return /\b(?:UHD|4K|2160P)\b/.test(n)?['UHD',4]:/\b(?:FHD|FULL\s*HD|1080P)\b/.test(n)?['FHD',3]:/\b(?:HD|720P)\b/.test(n)?['HD',2]:/\b(?:SD|480P|576P)\b/.test(n)?['SD',1]:['Standard',0];}
  function curated(item){return rank(item)<1000;}
  function rank(item){
    if(country!=='GB')return 1000;
    var id=key(item),base=id.replace(/ \+1$/,''),index=ukOrder.indexOf(id),shift=0;
    if(index<0){index=ukOrder.indexOf(base);shift=base!==id ? .25 : 0;}
    if(index<0&&/^(?:bbc one|bbc two|itv1|stv|utv) /.test(base)){
      // Sort regional editions beside their parent; never merge their sources/EPG.
      var parent=base.match(/^(bbc one|bbc two|itv1|stv|utv) /)[1];index=ukOrder.indexOf(parent);shift+=.5;
    }
    return index>=0?index+shift:1000;
  }
  function groupedChannels(){
    var groups=new Map();
    channels.forEach(function(item){
      // Hide provider RAW variants too, including already cached channel pages.
      if(/(?:^|[^a-z0-9])raw(?:$|[^a-z0-9])/i.test(String(item.name||'').normalize('NFKC')))return;
      var id=key(item);
      // Decorative provider headings are not playable television services.
      if(!id||/[#═━☰]{3,}/.test(item.name)||/^[\W_]*(?:UK|GB)?[\W_]*(?:GENERAL|ENTERTAINMENT|CHANNELS)[\W_]*$/i.test(id))return;
      if(!groups.has(id))groups.set(id,Object.assign({},item,{name:normal(item.name),sources:[]}));
      var group=groups.get(id),q=quality(item.name);
      if(!group.sources.some(function(s){return s.feed===item.feed&&String(s.id)===String(item.id);}))group.sources.push(Object.assign({},item,{quality:q[0],qualityRank:q[1]}));
    });
    return Array.from(groups.values()).map(function(group){
      var order=[];group.sources.forEach(function(s){if(order.indexOf(s.feed)<0)order.push(s.feed);});
      group.sources.sort(function(a,b){return b.qualityRank-a.qualityRank||order.indexOf(a.feed)-order.indexOf(b.feed);});
      group.feed=group.sources[0].feed;group.id=group.sources[0].id;return group;
    }).sort(function(a,b){return (genre==='General'||genre==='Entertainment'?rank(a)-rank(b):0)||a.name.localeCompare(b.name,undefined,{numeric:true});});
  }
  function sourcesFor(value){return folders.filter(function(f){return f.country===country&&f.genre===value;});}
  function programmes(item){
    var entries=[],seen=new Set();
    item.sources.forEach(function(s){(guideByChannel[s.feed+'|'+s.id]||[]).forEach(function(p){var id=p.start+'|'+p.end+'|'+p.title;if(Number(p.end)>Number(p.start)&&!seen.has(id)){seen.add(id);entries.push(p);}});});
    return entries.sort(function(a,b){return a.start-b.start;});
  }
  function programmeInfo(item){var time=Date.now()/1000+offset,entries=programmes(item);return {current:entries.find(function(p){return p.start<=time&&p.end>time;}),next:entries.find(function(p){return p.start>time;})};}
  function time(ts){return new Date(Number(ts)*1000).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit',timeZone:'Europe/London'});}
  function image(url,alt,className){if(typeof url!=='string'||!/^data:image\/(?:png|jpeg|webp);base64,/i.test(url))return null;var img=h('img',{src:url,alt:alt||'',className:className||'',loading:'lazy',decoding:'async',referrerpolicy:'no-referrer'});img.onerror=function(){img.remove();};return img;}
  function logo(item){var art=h('span',{className:'stalker-tv-logo','aria-hidden':'true'},[h('span',{text:item.name.slice(0,3)})]);var url=item.sources.map(function(s){return s.logoUrl||guideLogos[s.feed+'|'+s.id];}).filter(Boolean)[0];var img=image(url);if(img){img.onload=function(){art.classList.add('loaded');};art.appendChild(img);}return art;}
  function metadata(p){
    if(!p)return null;var values=[plain(p.categories),p.rating?((p.ratingSystem?p.ratingSystem+' ':'')+p.rating):'',p.episode,plain(p.language),p.subtitles?'Subtitles':''];
    return h('div',{className:'stalker-tv-meta'},values.filter(Boolean).map(function(value){return h('span',{text:value});}));
  }
  function credits(p){if(!p)return '';return [p.cast&&plain(p.cast)?'Cast: '+plain(p.cast):'',p.director&&plain(p.director)?'Director: '+plain(p.director):'',p.presenter&&plain(p.presenter)?'Presented by '+plain(p.presenter):''].filter(Boolean).join(' · ');}
  function playerGuide(item){var events=programmes(item);if(!events.length)return null;return {logo:item.sources.map(function(s){return s.logoUrl||guideLogos[s.feed+'|'+s.id];}).filter(Boolean)[0]||'',programs:events.map(function(p){return Object.assign({},p,{startUnix:p.start*1000,endUnix:p.end*1000,subtitle:p.description||'',kind:'epg'});})};}
  function sequence(items){return items.map(function(item){return {feed:item.feed,id:String(item.id),name:item.name,_guide:playerGuide(item),sources:item.sources.map(function(s){return {feed:s.feed,id:String(s.id),name:s.name,connection:s.connection,kind:'live',quality:s.quality,qualityRank:s.qualityRank,category:s.category,page:s.page};})};});}
  function play(item,button,source){var first=source||item.sources[0],ordered=[first].concat(item.sources.filter(function(s){return s.feed!==first.feed||String(s.id)!==String(first.id);}));options.play(first.feed,Object.assign({},item,{feed:first.feed,id:first.id,sources:ordered,_guide:playerGuide(item),_channelSequence:sequence(visibleChannels())}),button);}
  function favouriteId(item){return country+'|'+key(item);}
  function favourite(item){return favourites.indexOf(favouriteId(item))>=0;}
  function star(item){var b=h('button',{type:'button',className:'btn ghost','aria-pressed':String(favourite(item)),'aria-label':(favourite(item)?'Remove favourite ':'Favourite ')+item.name,text:favourite(item)?'★':'☆'});b.onclick=function(){var id=favouriteId(item);favourites=favourites.filter(function(x){return x!==id;});if(b.getAttribute('aria-pressed')!=='true')favourites.push(id);try{localStorage.setItem(preference,JSON.stringify(favourites.slice(-500)));}catch(ignore){}paint();};return b;}
  function visibleChannels(all){return (all||groupedChannels()).filter(function(item){if(onlyFavourites&&!favourite(item))return false;if(!search)return true;var p=programmeInfo(item).current;return [item.name,p&&p.title,p&&p.description,p&&credits(p)].filter(Boolean).join(' ').toLocaleLowerCase().includes(search.toLocaleLowerCase());});}
  function showFeature(item){
    hero.replaceChildren();if(!item){hero.hidden=true;return;}hero.hidden=false;chosen=key(item);var epg=programmeInfo(item),p=epg.current;
    var watch=h('button',{type:'button',className:'btn primary',text:'▶ Watch live',onClick:function(){play(item,watch);}});
    var details=h('details',{className:'stalker-tv-source-options'},[h('summary',{text:'Quality & connections · '+item.sources.length}),h('div',{className:'stalker-tv-source-list'},item.sources.map(function(s){var b=h('button',{type:'button',className:'btn ghost',text:s.quality+' · '+s.connection,onClick:function(){play(item,b,s);}});return b;}))]);
    var copy=h('div',{className:'stalker-tv-feature-copy'},[h('div',{className:'stalker-tv-kicker'},[logo(item),h('span',{text:item.name+(p?' · '+time(p.start)+'–'+time(p.end):'')})]),h('h2',{text:p?p.title:item.name}),metadata(p),h('p',{text:p&&p.description||'Programme information is not currently available. You can still watch this channel.'}),credits(p)?h('p',{className:'muted stalker-tv-credits',text:credits(p)}):null,h('div',{className:'row-actions'},[watch,star(item)]),details]);
    var art=p&&image(p.poster,p.title,'stalker-tv-feature-poster');hero.append(copy);if(art)hero.append(art);
    var schedule=h('details',{className:'stalker-tv-schedule'},[h('summary',{text:'Programme schedule & information'})]);
    programmes(item).filter(function(entry){return entry.end>Date.now()/1000;}).slice(0,30).forEach(function(entry){schedule.appendChild(h('article',{},[h('strong',{text:time(entry.start)+' · '+entry.title}),metadata(entry),entry.description?h('p',{text:entry.description}):null,credits(entry)?h('small',{text:credits(entry)}):null,h('small',{className:'muted',text:'Guide: '+(entry.source==='freeview'?'Freeview EPG':entry.source==='open-epg'?'Open EPG':'Provider')})]));});
    if(!schedule.querySelector('article'))schedule.appendChild(h('p',{text:'No matching programme listings yet.'}));hero.append(schedule);
  }
  function paint(){
    if(!list||!selected)return;
    var focused=document.activeElement,focusedRow=list.contains(focused)&&focused.closest('[data-channel]');
    var focusKey=focusedRow&&focusedRow.getAttribute('data-channel'),focusIndex=focusedRow?Array.from(focusedRow.querySelectorAll('button')).indexOf(focused):-1;
    var all=groupedChannels(),items=visibleChannels(all);list.replaceChildren();list.className='stalker-tv-list '+(view==='cards'?'stalker-tv-cards':'stalker-tv-guide');
    var previousGroup='';items.forEach(function(item){
      var group=country==='GB'&&genre==='Entertainment'?(curated(item)?'Curated UK channels':'More entertainment'):'';
      if(group&&group!==previousGroup){list.appendChild(h('h3',{className:'stalker-tv-group-title',text:group}));previousGroup=group;}
      var epg=programmeInfo(item),p=epg.current;
      var select=h('button',{type:'button',className:'stalker-tv-programme','aria-pressed':String(chosen===key(item)),onClick:function(){showFeature(item);list.querySelectorAll('.stalker-tv-programme').forEach(function(b){b.setAttribute('aria-pressed',String(b===select));});}},[
        view==='cards'&&p?image(p.poster,p.title,'stalker-tv-card-poster'):null,
        h('span',{className:'stalker-tv-programme-label',text:offset?'Later':'Now'}),h('strong',{text:p?p.title:'Programme information unavailable'}),p?h('small',{className:'muted',text:time(p.start)+'–'+time(p.end)}):null,metadata(p),p&&p.description?h('p',{className:'stalker-tv-synopsis',text:p.description}):null,credits(p)?h('small',{className:'muted stalker-tv-credits',text:credits(p)}):null
      ]);
      if(p&&!offset){var progress=h('div',{className:'stalker-tv-progress','aria-hidden':'true'}),fill=h('span');fill.style.width=Math.max(0,Math.min(100,100*(Date.now()/1000-p.start)/(p.end-p.start)))+'%';progress.appendChild(fill);select.appendChild(progress);}
      var watch=h('button',{type:'button',className:'btn',text:'Watch',onClick:function(){play(item,watch);}});
      list.appendChild(h('article',{className:'stalker-tv-channel','data-channel':key(item)},[h('div',{className:'stalker-tv-channel-name'},[logo(item),h('strong',{text:item.name})]),select,h('div',{className:'stalker-tv-next'},[h('span',{className:'stalker-tv-programme-label',text:'Next'}),h('strong',{text:epg.next?epg.next.title:'Listings unavailable'}),epg.next?h('small',{text:time(epg.next.start)+'–'+time(epg.next.end),className:'muted'}):null]),h('div',{className:'row-actions stalker-tv-watch'},[watch,star(item)])]));
    });
    if(!items.length)list.appendChild(h('p',{className:'muted',text:onlyFavourites?'No favourites in the loaded channels for this genre. Use ☆ to save a channel.':loading?'Loading channels…':'No matching channels in loaded pages.'}));
    summary.textContent=items.length+' channels · '+all.filter(function(item){return !!programmeInfo(item).current;}).length+' with programme listings'+(loading?' · Loading more…':'');
    more.hidden=!selected.sources.some(function(s){return !sourcePages[folderKey(s)]||sourcePages[folderKey(s)].more;});more.disabled=loading;
    guideButton.disabled=guideBusy||guideRefreshPending||!pageInventory.length;guideButton.textContent=guideBusy||guideRefreshPending?'Refreshing EPG…':'Refresh EPG';
    if(!hero.querySelector('details[open]')&&!hero.contains(focused))showFeature(items.find(function(item){return key(item)===chosen;})||items[0]);
    if(focusKey&&focusIndex>=0){var row=Array.from(list.querySelectorAll('[data-channel]')).find(function(el){return el.getAttribute('data-channel')===focusKey;});var target=row&&row.querySelectorAll('button')[focusIndex];if(target)target.focus({preventScroll:true});}
  }
  function shell(){
    host.replaceChildren();host.classList.add('stalker-tv');
    var navigation=h('aside',{className:'stalker-tv-navigation'}),picker=h('select',{'aria-label':'Channel country'});
    var codes=Array.from(new Set(folders.map(function(f){return f.country;})));if(codes.indexOf(country)<0)codes.push(country);
    var priority=['GB','US'];
    codes.sort(function(a,b){
      var x=priority.indexOf(a),y=priority.indexOf(b);
      return (x<0?2:x)-(y<0?2:y)||name(a).localeCompare(name(b));
    }).forEach(function(code){
      var flag=code!=='ZZ'&&/^[A-Z]{2}$/.test(code)?String.fromCodePoint(code.charCodeAt(0)+127397,code.charCodeAt(1)+127397)+' ':'';
      picker.appendChild(h('option',{value:code,selected:code===country,text:flag+name(code)}));
    });
    picker.onchange=function(){country=picker.value;openGenre(genreOrder.find(function(value){return sourcesFor(value).length;})||'General');};
    navigation.append(h('label',{},[h('span',{className:'eyebrow',text:'Your line-up'}),picker]));
    var nav=h('nav',{'aria-label':'Live TV genres'});genreOrder.forEach(function(value){if(!sourcesFor(value).length&&value!==genre)return;nav.appendChild(h('button',{type:'button',className:'btn '+(genre===value?'primary':'ghost'),'aria-pressed':String(genre===value),text:genreName(value),onClick:function(){openGenre(value);}}));});navigation.appendChild(nav);
    var reload=h('button',{type:'button',className:'btn ghost',text:'Refresh channels',onClick:function(){cache.clear();discover(true);}});navigation.appendChild(reload);
    body=h('div',{className:'stalker-tv-content'});status=h('p',{className:'muted',role:'status'});
    var find=h('input',{type:'search',value:search,placeholder:'Find channels, programmes or cast…','aria-label':'Search loaded channels, programmes and cast'});find.oninput=function(){search=find.value;paint();};
    var layouts=h('div',{className:'row-actions'});['guide','cards'].forEach(function(value){layouts.appendChild(h('button',{type:'button',className:'btn '+(view===value?'primary':'ghost'),'aria-pressed':String(view===value),text:value==='guide'?'TV guide':'Browse',onClick:function(){view=value;remember();shell();paint();}}));});
    var fav=h('button',{type:'button',className:'btn ghost',text:onlyFavourites?'★ Favourites':'☆ Favourites','aria-pressed':String(onlyFavourites),onClick:function(){onlyFavourites=!onlyFavourites;fav.setAttribute('aria-pressed',String(onlyFavourites));fav.textContent=onlyFavourites?'★ Favourites':'☆ Favourites';paint();}});layouts.appendChild(fav);
    var when=h('select',{'aria-label':'Programme time'});[[0,'On now'],[3600,'In 1 hour'],[7200,'In 2 hours'],[10800,'In 3 hours']].forEach(function(pair){when.appendChild(h('option',{value:pair[0],selected:offset===pair[0],text:pair[1]}));});when.onchange=function(){offset=Number(when.value);paint();};layouts.appendChild(when);
    hero=h('section',{className:'stalker-tv-feature',hidden:true,'aria-label':'Selected programme'});summary=h('p',{className:'muted',role:'status'});list=h('div');
    more=h('button',{type:'button',className:'btn',text:'Load more channels',onClick:function(){loadChannels();}});
    guideButton=h('button',{type:'button',className:'btn ghost',text:'Refresh EPG',onClick:refreshEpg});
    var sources=h('details',{className:'stalker-tv-provider-settings'},[h('summary',{text:'Provider folders & country corrections'})]);
    sources.ontoggle=function(){
      if(!sources.open||sources.childElementCount>1)return;
      sourcesFor(genre).forEach(function(f){var choose=h('select',{'aria-label':'Country for '+f.name+' on '+f.connection});Object.keys(countries).sort().concat(['ZZ']).forEach(function(code){choose.appendChild(h('option',{value:code,selected:code===f.country,text:name(code)}));});choose.onchange=function(){overrides[folderKey(f)]=choose.value;f.country=choose.value;try{localStorage.setItem('vaultcast-stalker-country-overrides-'+options.owner,JSON.stringify(overrides));}catch(ignore){}cache.clear();selected=null;openGenre(genre);};sources.appendChild(h('label',{},[h('span',{text:f.name+' · '+f.connection}),choose]));});
    };
    body.append(h('div',{className:'stalker-tv-heading'},[h('div',{},[h('span',{className:'eyebrow',text:name(country)+' · Live TV'}),h('h2',{text:genreName(genre)})]),find]),h('div',{className:'stalker-tv-toolbar'},[layouts,guideButton]),status,hero,summary,list,h('div',{className:'row-actions'},[more]),sources);
    host.append(navigation,body);status.textContent=failures.join(' · ');
  }
  function snapshot(){if(selected)cache.set(selected.country+'|'+selected.genre,{channels:channels,sourcePages:sourcePages,pageInventory:pageInventory,guideByChannel:guideByChannel,guideLogos:guideLogos,guideFetched:guideFetched,guideFetchedAt:guideFetchedAt,search:search,chosen:chosen});if(cache.size>8)cache.delete(cache.keys().next().value);}
  function openGenre(value){
    snapshot();serial++;loading=false;guideBusy=false;guideRefreshPending=false;guideForceRefresh=false;genre=value;search='';chosen='';
    var sources=sourcesFor(genre);selected={sources:sources,country:country,genre:genre};
    var saved=cache.get(country+'|'+genre)||{};channels=saved.channels||[];sourcePages=saved.sourcePages||{};pageInventory=saved.pageInventory||[];guideByChannel=saved.guideByChannel||{};guideLogos=saved.guideLogos||{};guideFetched=saved.guideFetched||{};guideFetchedAt=saved.guideFetchedAt||{};search=saved.search||'';chosen=saved.chosen||'';
    remember();shell();paint();if(!sources.length){status.textContent=failures.length?failures.join(' · '):'No Live TV provider folders available for this genre in '+name(country)+'. Choose another genre or country, or refresh the provider list.';return;}
    if(!channels.length)loadChannels();else requestGuide();
  }
  function refreshEpg(){
    if(guideBusy||guideRefreshPending||loading||!pageInventory.length)return;
    guideRefreshPending=true;guideFetched={};guideFetchedAt={};guideForceRefresh=true;
    var clears=[],token=serial;
    pageInventory.forEach(function(entry){
      (entry.channelIds||[]).forEach(function(id){delete guideByChannel[entry.feed+'|'+id];delete guideLogos[entry.feed+'|'+id];});
      clears.push(removeCachedGuide(guideCacheKey(entry)));
    });
    status.textContent='Refreshing guide data in this browser…';paint();
    Promise.all(clears).then(function(){
      if(!current(token))return;
      guideRefreshPending=false;requestGuide();
    });
  }
  function requestGuide(){
    if(!current(serial)||!selected||guideBusy||loading)return;
    var pending=pageInventory.filter(function(p){return !guideFetched[p.feed+'|'+p.category+'|'+p.page];});
    if(!pending.length){guideForceRefresh=false;paint();return;}
    var entry=pending[0];
    var token=serial,id=entry.feed+'|'+entry.category+'|'+entry.page,cacheKey=guideCacheKey(entry),fromBrowser=false,fetchedAt=0,complete=false;guideBusy=true;paint();
    cachedGuide(cacheKey).then(function(cached){
      if(!current(token))return null;
      if(cached&&!guideForceRefresh){fromBrowser=true;fetchedAt=cached.at;return cached.data;}
      return options.api('stalker_live_guide','feed='+encodeURIComponent(entry.feed)+'&category='+encodeURIComponent(entry.category)+'&page='+entry.page+(entry.providerPage?'&provider_page=1':'')+(guideForceRefresh?'&refresh=1':''));
    }).then(function(data){
      if(!current(token))return;if(!data||!data.ok)throw Error(data&&data.error||'Programme guide unavailable');
      // EPG is metadata for this provider page, never a source of channel rows.
      // Apply the same boundary to fresh responses and persisted browser guides.
      (entry.channelIds||[]).forEach(function(id){
        var providerKey=entry.feed+'|'+id;
        if(Object.prototype.hasOwnProperty.call(data.programmes||{},id))guideByChannel[providerKey]=data.programmes[id];
        if(Object.prototype.hasOwnProperty.call(data.channelLogos||{},id))guideLogos[providerKey]=data.channelLogos[id];
      });
      var unfinished=Object.keys(data.openEpgIndexStatus||{}).some(function(region){return region.slice(0,2)===country&&data.openEpgIndexStatus[region]!=='ready';});
      complete=!data.catalogueLoading&&!unfinished&&(data.available||!data.providerEpgUnavailable);
      if(!fromBrowser&&complete)cacheGuide(cacheKey,data);
    }).catch(function(){if(current(token))status.textContent='Saved programme listings could not be loaded.';}).then(function(){
      if(!current(token))return;
      guideFetched[id]=true;guideFetchedAt[id]=complete?(fetchedAt||Date.now()):Date.now()-86400000+60000;
      guideBusy=false;snapshot();requestGuide();
    });
  }
  function loadChannels(){
    if(loading||!selected||!active)return;var token=++serial;loading=true;guideBusy=false;var sources=selected.sources.filter(function(source){return !sourcePages[folderKey(source)]||sourcePages[folderKey(source)].more;}),index=0;
    var existing=new Set(channels.map(function(item){return item.feed+'|'+item.id;}));paint();
    function step(){
      if(!current(token))return;
      if(index>=sources.length){loading=false;paint();snapshot();requestGuide();return;}
      var source=sources[index++],id=folderKey(source),progress=sourcePages[id]||{page:1,more:true};if(!progress.more){step();return;}
      status.textContent='Loading '+source.connection+' · '+source.name;
      options.api('stalker_live_channels','feed='+encodeURIComponent(source.feed)+'&category='+encodeURIComponent(source.id)+'&page='+progress.page+(refreshChannels?'&refresh=1':'')).then(function(result){
        if(!current(token))return;if(!result||!result.ok)throw Error(result&&result.error||'Channel page unavailable');
        var added=0;
        (result.items||[]).forEach(function(item){var k=item.feed+'|'+item.id;if(!existing.has(k)){existing.add(k);channels.push(item);added++;}});
        if((result.items||[]).length)pageInventory.push({feed:source.feed,category:source.id,page:progress.page,providerPage:!!result.providerPage,channelIds:result.items.map(function(item){return String(item.id);})});
        var signature=(result.items||[]).map(function(item){return String(item.id);}).join('|');
        sourcePages[id]={page:progress.page+1,more:!!result.hasMore&&signature!==progress.signature,signature:signature};paint();
      }).catch(function(error){if(current(token))failures.push(source.connection+': '+error.message);}).then(function(){if(!current(token))return;status.textContent=failures.slice(-3).join(' · ');step();});
    }
    step();
  }
  function discover(refresh){
    var token=++serial;loading=false;guideBusy=false;refreshChannels=!!refresh;selected=null;folders=[];failures=[];
    host.replaceChildren(h('p',{className:'muted',role:'status',text:'Loading your television…'}));
    (options.connections&&!refresh?Promise.resolve({connections:options.connections}):options.api('stalker','')).then(function(result){
      if(!current(token))return;feeds=(result.connections||[]).filter(function(f){return f.enabled&&!f.expired;});var index=0;
      function step(){
        if(!current(token))return;if(index>=feeds.length){if(!folders.some(function(f){return f.country===country;}))country=folders.some(function(f){return f.country==='GB';})?'GB':folders[0]?folders[0].country:'GB';if(!sourcesFor(genre).length)genre=genreOrder.find(function(g){return sourcesFor(g).length;})||'General';openGenre(genre);return;}
        var feed=feeds[index++];options.api('stalker_live_categories','feed='+encodeURIComponent(feed.id)+(refresh?'&refresh=1':'')).then(function(data){if(!current(token))return;if(!data||!data.ok)throw Error(data&&data.error||'Categories unavailable');(data.categories||[]).forEach(function(c){var f={feed:feed.id,connection:feed.name,id:String(c.id),name:c.name,country:c.country||'ZZ',genre:c.genre||'Other'};f.country=overrides[folderKey(f)]||f.country;folders.push(f);});}).catch(function(error){if(current(token))failures.push(feed.name+': '+error.message);}).then(step);
      }step();
    }).catch(function(error){if(current(token))host.replaceChildren(h('p',{className:'bad',text:error.message}),h('button',{type:'button',className:'btn',text:'Retry',onClick:function(){discover(refresh);}}));});
  }
  return {show:function(){if(active)return;active=true;guideConnectionAt=Date.now();expireGuideFlags(guideFetched,guideFetchedAt,guideConnectionAt);cache.forEach(function(saved){saved.guideFetched=saved.guideFetched||{};saved.guideFetchedAt=saved.guideFetchedAt||{};expireGuideFlags(saved.guideFetched,saved.guideFetchedAt,guideConnectionAt);});window.addEventListener('online',reconnectGuide);clockTimer=setInterval(function(){if(current(serial)&&selected&&!loading&&!guideBusy){expireGuideFlags(guideFetched,guideFetchedAt,Date.now());requestGuide();}},60000);if(folders.length)openGenre(genre);else discover(false);},stop:stop};
};
})();
