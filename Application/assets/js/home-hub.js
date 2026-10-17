(function(){
  'use strict';
  var A, data=null, owner=null, loaded=0, pending=null, noticeSeen={}, noticeOwner=null, gameSession=null, cinema=null;
  function h(tag,attrs,kids){var el=A.h(tag,attrs,kids==null?[]:(Array.isArray(kids)?kids:[kids]));if(tag==='textarea' && attrs && attrs.value!=null)el.value=attrs.value;return el;}
  function signed(){return !!(A.state.user && A.state.user.role!=='guest');}
  function request(path,body){return A.api('/hub/'+path,body===undefined?undefined:{method:'POST',body:body}).then(function(j){if(!j || !j.ok){var err=new Error(j && j.error || 'Could not complete request');err.status=j && j._status;throw err;}return j;});}
  function error(e){A.toast(e.message || String(e),{kind:'bad'});}
  function button(text,fn,style){return h('button',{className:'btn '+(style||'ghost'),type:'button',text:text,onKeydown:function(e){if(e.key==='Enter'||e.key===' ')e.stopPropagation();},onClick:function(e){e.stopPropagation();fn(e);}});}
  function empty(text){return h('p',{className:'hub-empty',text:text});}
  function refreshContinue(){var uid=A.state.user && A.state.user.id;A.api('/home').then(function(j){if(j.ok && uid===(A.state.user && A.state.user.id))A.state.home=j;}).catch(function(){});}
  function time(seconds){seconds=Math.floor(seconds||0);var m=Math.floor(seconds/60);return seconds<60?seconds+' sec':m<60?m+' min':Math.floor(m/60)+'h '+m%60+'m';}
  function titleKey(it){return (it.kind==='show'||it.media_kind==='show'||it.show_key?'show:':'item:')+(it.show_key||it.id);}
  function avatar(person){function fallback(){return h('span',{className:'hub-avatar',text:(person.name||'?').slice(0,1).toUpperCase()});}return person.avatar_url?h('img',{className:'hub-avatar',src:person.avatar_url,alt:'',loading:'lazy',onError:function(e){e.currentTarget.replaceWith(fallback());}}):fallback();}
  function refresh(force){
    var uid=A.state.user && A.state.user.id || 'guest';
    if(uid!==owner){data=null;pending=null;loaded=0;noticeSeen={};owner=uid;}
    if(!force && data && Date.now()-loaded<15000)return Promise.resolve(data);
    if(pending)return pending;
    var forOwner=owner;
    pending=request('dashboard').then(function(j){if(String(A.state.user && A.state.user.id || 'guest')!==String(forOwner))throw new Error('Account changed. Reopen Home.');if(owner===forOwner){data=j;loaded=Date.now();}return j;});
    var current=pending;current.then(function(){if(pending===current)pending=null;},function(){if(pending===current)pending=null;});return current;
  }
  function openPath(path){history.pushState({},'',(window.__STREAM_BASE__||'/')+path);A.applyRoute().then(function(){A.render();A.replaceNav();});}
  function go(tab,room){A.state.hubTab=tab||'overview';A.state.cinemaId=room||null;A.navigate('home');}
  function panel(title,kids,more){return h('section',{className:'hub-panel'},[h('div',{className:'hub-panel-head'},[h('h2',{text:title}),more||null]),h('div',{className:'hub-panel-body'},kids)]);}
  function cards(items,limit,shelf){return items.length?h('div',{className:'hub-cards'},items.slice(0,limit||8).map(function(it){var card=A.cardEl(it,it.progress);if(it.watch_status)card.appendChild(h('small',{className:'hub-watch-status',text:it.watch_status}));if(shelf){var remove=button('Remove',function(){remove.disabled=true;request('shelf',{key:it.hub_key||titleKey(it),shelf:shelf,enabled:false}).then(function(){card.remove();data[shelf]=data[shelf].filter(function(row){return row.id!==it.id;});loaded=0;}).catch(function(e){remove.disabled=false;error(e);});});remove.classList.add('hub-shelf-remove');card.appendChild(remove);}return card;})):empty('Nothing here yet. Your next favourite is waiting in the library.');}
  function controls(it){
    var box=h('div',{className:'hub-title-actions'}),key=titleKey(it);
    ['favourites','watchlist'].forEach(function(shelf){
      var active=data && (data[shelf]||[]).some(function(row){return titleKey(row)===key;});
      var b=button((shelf==='favourites'?'♡ Favourite':'+ Watchlist'),function(){
        if(!signed()){A.toast('Sign in to keep your own lists.');return;}
        b.disabled=true;
        request('shelf',{key:key,shelf:shelf,enabled:!active}).then(function(j){key=j.key;active=j.enabled;paint();loaded=0;refresh(true).catch(error);},error).then(function(){b.disabled=false;});
      });
      function paint(){b.textContent=shelf==='favourites'?(active?'♥ Favourited':'♡ Favourite'):(active?'✓ On watchlist':'+ Watchlist');b.setAttribute('aria-pressed',String(!!active));b.classList.toggle('selected',!!active);}
      paint();box.appendChild(b);
    });
    if(it.media_kind==='video'||it.kind==='show')box.appendChild(button('Watch together',function(){chooseVideo(it,createCinema);}));
    if(it.media_kind==='rom')box.appendChild(button('Start lobby · keep my game',function(){A.startLobbyHost(it,true);},'primary'));
    return box;
  }
  function cardAction(card,it){
    if(!A || !it.id || it.media_kind==='audio')return;
    var shelf=it.media_kind==='rom'?'favourites':'watchlist',key=titleKey(it),active=!!(data && (data[shelf]||[]).some(function(row){return titleKey(row)===key;}));
    var b=button(active?'✓':shelf==='favourites'?'♡':'+',function(){
      if(!signed()){A.toast('Sign in to save your own titles.');return;}
      b.disabled=true;request('shelf',{key:key,shelf:shelf,enabled:!active}).then(function(j){active=j.enabled;b.textContent=active?'✓':shelf==='favourites'?'♡':'+';b.setAttribute('aria-pressed',String(active));loaded=0;A.toast(active?'Saved to '+shelf:'Removed from '+shelf);}).catch(error).then(function(){b.disabled=false;});
    });b.classList.add('hub-card-save');b.setAttribute('aria-label','Toggle '+shelf+' for '+it.title);b.setAttribute('aria-pressed',String(active));b.title='Save to '+shelf;
    card.querySelector('.poster').appendChild(b);
  }
  function createCinema(it,friend){
    if(!signed()){A.toast('Sign in to host a cinema.');return;}
    request('rooms',{item_id:it.id}).then(function(j){
      if(friend)request('invite',{kind:'cinema',room_id:j.room.id,user_id:friend.id}).catch(error);
      go('cinema',j.room.id);
    }).catch(error);
  }
  function modal(title,build){
    var previous=document.activeElement;
    var layer=h('div',{className:'confirm-overlay hub-modal'}),body=h('div',{className:'hub-modal-body'});
    function close(){layer.remove();document.removeEventListener('keydown',key);if(previous && previous.isConnected)previous.focus();}
    function key(e){if(e.key==='Escape')close();if(e.key==='Tab'){
      var nodes=layer.querySelectorAll('button:not([disabled]),input:not([disabled]),select:not([disabled]),a[href],textarea:not([disabled])');var first=nodes[0],last=nodes[nodes.length-1];
      if(e.shiftKey && document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey && document.activeElement===last){e.preventDefault();first.focus();}
    }}
    layer.appendChild(h('section',{className:'hub-modal-card',role:'dialog','aria-modal':'true','aria-label':title},[h('div',{className:'hub-panel-head'},[h('h2',{text:title}),button('Close',close)]),body]));
    document.body.appendChild(layer);document.addEventListener('keydown',key);build(body,close);layer.querySelector('button').focus();return layer;
  }
  function picker(kind,done){
    modal(kind==='game'?'Choose a game':'Choose something to watch',function(body,close){
      var list=h('div',{className:'hub-pick-results'}),input=h('input',{type:'search',placeholder:'Search your library…','aria-label':'Search titles'}),seq=0;
      function show(items){list.replaceChildren();items.filter(function(it){return kind==='game'?it.media_kind==='rom':(it.media_kind==='video'||it.kind==='show');}).slice(0,24).forEach(function(it){list.appendChild(button(it.show?it.show+' · '+it.title:it.title,function(){close();if(kind==='game')done(it);else chooseVideo(it,done);}));});if(!list.children.length)list.appendChild(empty('Search for a specific film, episode or game.'));}
      input.addEventListener('input',function(){clearTimeout(input.timer);var mine=++seq;input.timer=setTimeout(function(){A.api('/search?q='+encodeURIComponent(input.value)+'&limit=30').then(function(j){if(mine===seq && list.isConnected)show(j.catalogue||j.items||j.results||[]);}).catch(error);},250);});
      body.appendChild(input);body.appendChild(list);show(((A.state.home||{}).sections||[]).reduce(function(all,s){return all.concat(s.items||[]);},[]));setTimeout(function(){input.focus();},0);
    });
  }
  function chooseVideo(it,done){
    if(it.kind!=='show' && it.media_kind!=='show'){done(it);return;}
    A.api('/shows/'+encodeURIComponent(it.show_key||it.id)).then(function(j){
      if(!j.ok)throw new Error(j.error||'Could not open show');
      modal('Choose an episode',function(body,close){var count=0;(j.seasons||[]).forEach(function(season){(season.episodes||[]).forEach(function(ep){if(!ep.id||ep.available===false)return;count++;body.appendChild(button('S'+ep.season+' E'+ep.episode+' · '+ep.title,function(){close();done(ep);}));});});if(!count)body.appendChild(empty('No playable episodes are available yet.'));});
    }).catch(error);
  }
  function inviteGame(it,friend){A.state._hubInviteFriend=friend;A.startLobbyHost(it,true);}
  function friendCard(person){
    var row=h('div',{className:'hub-person'},[avatar(person),h('div',{},[h('strong',{text:person.name}),h('small',{text:person.relationship==='friend'?'Connected':person.relationship==='incoming'?'Wants to connect':'Request sent'})])]);
    function act(action){request('friend',{user_id:person.id,action:action}).then(function(){return refresh(true);}).then(function(){if(A.state.view==='home')A.render();}).catch(error);}
    if(person.relationship==='incoming')row.appendChild(button('Accept',function(){act('accept');},'primary'));
    if(person.relationship==='friend')row.appendChild(button('Profile',function(){
      modal(person.name,function(body,close){
        body.appendChild(h('div',{className:'hub-profile'},[avatar(person),h('h3',{text:person.name})]));
        request('profile?id='+encodeURIComponent(person.id)).then(function(j){body.appendChild(h('p',{className:'muted',text:j.stats.games+' games played · '+time(j.stats.seconds)+' of play'}));}).catch(error);
        body.appendChild(button('Invite to watch',function(){close();picker('video',function(it){createCinema(it,person);});},'primary'));
        body.appendChild(button('Invite to play',function(){close();picker('game',function(it){inviteGame(it,person);});}));
        body.appendChild(button('Remove friend',function(){A.confirmDialog('Remove '+person.name+' from your friends?').then(function(yes){if(yes)act('remove');});}));
      });
    }));
    if(person.relationship!=='friend')row.appendChild(button(person.relationship==='incoming'?'Decline':'Cancel',function(){act('remove');}));
    return row;
  }
  function friendsPage(){
    var results=h('div'),input=h('input',{type:'search',placeholder:'Find friends by username (2+ characters)','aria-label':'Find friends'});
    var seq=0;input.addEventListener('input',function(){clearTimeout(input.timer);var mine=++seq;input.timer=setTimeout(function(){request('people?q='+encodeURIComponent(input.value)).then(function(j){if(mine!==seq||!results.isConnected)return;results.replaceChildren();j.people.forEach(function(p){var existing=(data.friends||[]).some(function(f){return f.id===p.id;});results.appendChild(h('div',{className:'hub-person'},[avatar(p),h('strong',{text:p.name}),existing?h('small',{text:'Already connected or requested'}):button('Add friend',function(e){var target=e.currentTarget;target.disabled=true;request('friend',{user_id:p.id,action:'request'}).then(function(){target.textContent='Requested';loaded=0;}).catch(function(err){target.disabled=false;error(err);});})]));});}).catch(error);},300);});
    return h('div',{className:'hub-two'},[panel('Your people',data.friends.length?data.friends.map(friendCard):[empty('Make the next film night a shared one. Add a friend to get started.')]),panel('Find your friends',[input,results,h('p',{className:'muted',text:'Requests require acceptance. Only friends can view your play totals or send you invitations.'})])]);
  }
  function notices(){return (data.notifications||[]).slice().reverse().map(function(n){return h('div',{className:'hub-notice'},[avatar(n.from),h('div',{},[h('strong',{text:n.from.name+' invited you'}),h('p',{text:n.title})]),button('Join',function(){openPath(n.path);},'primary'),button('Dismiss',function(e){var row=e.currentTarget.closest('.hub-notice');request('dismiss',{id:n.id}).then(function(){row.remove();loaded=0;}).catch(error);})]);});}
  function overview(){
    return h('div',{className:'hub-two'},[
      panel('Recently watched',data.recent.length?cards(data.recent,6):[empty('Start a film or episode and your next visit starts right here.')],button('History',function(){go('history');})),
      panel('Back in the game',data.games.length?data.games.slice(0,4).map(function(g){return h('div',{className:'hub-game-row'},[h('div',{},[h('strong',{text:g.card.title}),h('small',{text:time(g.seconds)+' played · '+g.sessions+' sessions'})]),button('Play',function(){A.openGame(g.card);},'primary')]);}):[empty('Your recently played games will appear here. Play time counts while the emulator is active.')]),
      panel('Your next watch',cards(data.watchlist,6),button('Watchlist',function(){go('watchlist');})),
      panel('Your circle',data.friends.length?data.friends.slice(0,4).map(friendCard):[empty('Find friends, share a room, make it a film night.')],button('Friends',function(){go('friends');})),
      panel('Coming up',data.upcoming.length?data.upcoming.slice(0,5).map(function(ep){return h('div',{className:'hub-airing'},[h('time',{datetime:ep.date,text:new Date(ep.date+'T12:00:00').toLocaleDateString(undefined,{month:'short',day:'numeric'})}),h('div',{},[h('strong',{text:ep.card.title}),h('small',{text:'S'+ep.season+' E'+ep.episode+' · '+ep.title})]),button('Show',function(){A.openShow(ep.card.show_key);})]);}):[empty('Favourite a series to see its next air dates. Dates come from your prepared episode guides; they do not mean a file is available.')]),
      panel('Your play, in numbers',[h('div',{className:'hub-stats'},[stat(time(data.game_stats.seconds),'Time played'),stat(data.game_stats.games,'Games explored'),stat(data.game_stats.sessions,'Play sessions')]),h('p',{className:'muted',text:'Built from your own play sessions, across your devices.'})],button('Game stats',function(){go('stats');}))
    ]);
  }
  function stat(value,label){return h('div',{},[h('strong',{text:String(value)}),h('small',{text:label})]);}
  function gameStats(){
    var list=h('div'),page=1,seq=0,q=h('input',{type:'search',placeholder:'Filter your games…','aria-label':'Filter game statistics'}),more=button('Load more',function(){page++;load(false);});
    function load(reset){var mine=++seq;if(reset){page=1;list.replaceChildren();}more.disabled=true;request('games?page='+page+'&q='+encodeURIComponent(q.value)).then(function(j){if(mine!==seq || !list.isConnected)return;j.items.forEach(function(g){list.appendChild(h('div',{className:'hub-game-row'},[h('strong',{text:g.card.title}),h('span',{text:time(g.seconds)+' · '+g.sessions+' sessions'}),button('Play',function(){A.openGame(g.card);})]));});if(!list.children.length)list.appendChild(empty('No recorded sessions match this search.'));more.hidden=!j.has_more;more.disabled=false;}).catch(function(e){if(mine===seq){if(!reset)page--;more.disabled=false;error(e);}});}
    q.oninput=function(){clearTimeout(q.timer);q.timer=setTimeout(function(){load(true);},250);};
    var result=panel('Every session counts',[h('div',{className:'hub-stats'},[stat(time(data.game_stats.seconds),'Total play time'),stat(data.game_stats.games,'Games played'),stat(data.game_stats.sessions,'Sessions')]),q,list,more]);setTimeout(function(){load(true);},0);return result;
  }
  function pickTonight(){
    var candidates=(data && data.watchlist.length?data.watchlist:((A.state.home||{}).sections||[]).reduce(function(out,s){return s.type==='roms'?out:out.concat(s.items||[]);},[]));
    if(!candidates.length){A.toast('Add a few titles to your watchlist first.');return;}
    modal('Tonight, sorted',function(body,close){
      var genres={},pool=candidates.slice();pool.forEach(function(it){(it.genres||[]).forEach(function(g){genres[g]=true;});});
      var select=h('select',{'aria-label':'Choose a genre'},[h('option',{value:'',text:'Any mood'})].concat(Object.keys(genres).sort().map(function(g){return h('option',{value:g,text:g});}))),result=h('div',{className:'hub-pick-card'});
      function choose(){var filtered=pool.filter(function(it){return !select.value||(it.genres||[]).indexOf(select.value)>=0;});var it=filtered[Math.floor(Math.random()*filtered.length)];result.replaceChildren();if(it){var card=A.cardEl(it);card.addEventListener('click',function(e){if(!e.target.closest('.hub-card-save'))close();});card.addEventListener('keydown',function(e){if(e.key==='Enter' && !e.target.closest('.hub-card-save'))close();});result.appendChild(card);}else result.appendChild(empty('No matches for this mood.'));}
      body.appendChild(h('p',{className:'muted',text:'A little less scrolling. Pick from your watchlist, or a handful from your library.'}));body.appendChild(select);body.appendChild(result);body.appendChild(button('Another pick',choose,'primary'));select.onchange=choose;choose();
    });
  }
  function releaseCalendar(){
    var types=h('select',{'aria-label':'Release type'},[h('option',{value:'all',text:'TV & anime'}),h('option',{value:'tv',text:'TV'}),h('option',{value:'anime',text:'Anime'})]);
    var query=h('input',{type:'search',placeholder:'Filter favourite shows…','aria-label':'Filter release calendar'}),days=h('select',{'aria-label':'Date range'},[h('option',{value:'30',text:'Next 30 days'}),h('option',{value:'7',text:'Next 7 days'})]),list=h('div');
    function paint(){
      list.replaceChildren();var now=new Date(),today=[now.getFullYear(),String(now.getMonth()+1).padStart(2,'0'),String(now.getDate()).padStart(2,'0')].join('-'),end=new Date(now);end.setDate(end.getDate()+Number(days.value));
      var last=[end.getFullYear(),String(end.getMonth()+1).padStart(2,'0'),String(end.getDate()).padStart(2,'0')].join('-');
      var episodes=data.upcoming.filter(function(ep){return ep.date>=today && ep.date<=last && (types.value==='all'||ep.card.type===types.value) && ep.card.title.toLowerCase().includes(query.value.toLowerCase());});
      if(!episodes.length){list.appendChild(empty('No announced episodes in this range. Favourite TV or anime shows to follow them here. Air dates appear after episode guides have been prepared.'));return;}
      var previous='',group;
      episodes.forEach(function(ep){
        if(ep.date!==previous){previous=ep.date;group=h('section',{className:'hub-calendar-day'},[h('h3',{text:(ep.date===today?'Today · ':'')+new Date(ep.date+'T12:00:00').toLocaleDateString(undefined,{weekday:'long',month:'long',day:'numeric'})})]);list.appendChild(group);}
        var poster=h('img',{src:(window.__STREAM_BASE__||'/')+'api/shows/'+encodeURIComponent(ep.card.show_key)+'/poster?fetch=0&variant=medium',alt:'',loading:'lazy',width:'48',height:'72',onError:function(e){e.currentTarget.hidden=true;}});
        group.appendChild(h('div',{className:'hub-airing'},[poster,h('div',{},[h('strong',{text:ep.card.title}),h('small',{text:'S'+ep.season+' E'+ep.episode+' · '+ep.title})]),button('Open show',function(){A.openShow(ep.card.show_key);})]));
      });
    }
    query.oninput=paint;types.onchange=paint;days.onchange=paint;paint();
    return panel('Release calendar',[h('p',{className:'muted',text:'Upcoming episodes from your favourited TV and anime shows. Air dates use prepared episode guides and do not indicate library availability.'}),h('div',{className:'hub-calendar-filters'},[types,days,query]),list]);
  }
  function view(){
    var root=h('div',{className:'home-hub'}),tab=A.state.hubTab||'overview';
    if(A.state.cinemaId)return A.shell(cinemaView(A.state.cinemaId));
    var name=A.state.user && (A.state.user.display_name||A.state.user.username),hour=new Date().getHours(),resume=((A.state.home||{}).continue_watching||[])[0];
    root.appendChild(h('section',{className:'hub-hero'},[
      h('div',{className:'hub-orbit', 'aria-hidden':'true'},[h('span'),h('span'),h('span')]),
      h('div',{className:'hub-hero-copy'},[h('span',{className:'eyebrow',text:'YOUR SPACE. YOUR STORIES.'}),h('h1',{text:(hour<12?'Good morning':hour<18?'Good afternoon':'Good evening')+(name?', '+name:'. Make yourself at home')+'.'}),h('p',{text:'One more episode. A new high score. A night with your people. It all starts here.'}),
      h('div',{className:'hub-hero-actions'},[resume?button('▶ Continue '+(resume.show||resume.title),function(){A.playItem(resume);},'primary'):null,button('✦ Pick my next watch',pickTonight,resume?'ghost':'primary'),button('Watch together',function(){go('cinema');}),button('Explore the library',function(){A.navigate('library');})])])
    ]));
    var body=h('div',{className:'hub-content'},[empty('Opening your space…')]);root.appendChild(body);
    function paint(){
      if(!root.isConnected)return;body.replaceChildren();
      if(!signed())body.appendChild(h('div',{className:'hub-guest-note'},[h('p',{text:'You can explore and watch on your local network. Sign in for your own lists, friends and play history.'}),button('Sign in',function(){A.navigate('login');},'primary')]));
      if(tab==='overview')body.appendChild(overview());
      else if(tab==='favourites'||tab==='watchlist'){
        var title=tab==='favourites'?'Keep the good ones close':'Your next chapter',q=h('input',{type:'search',placeholder:'Filter this shelf…','aria-label':'Filter shelf'}),items=h('div');var limit=24;
        function shelf(){items.replaceChildren();var filtered=data[tab].filter(function(it){return (it.title||'').toLowerCase().indexOf(q.value.toLowerCase())>=0;});items.appendChild(cards(filtered,limit,tab));if(filtered.length>limit)items.appendChild(button('Show more',function(){limit+=24;shelf();}));}
        q.oninput=function(){limit=24;shelf();};body.appendChild(panel(title,[q,items]));shelf();
      } else if(tab==='friends')body.appendChild(signed()?friendsPage():empty('Sign in to connect with friends.'));
      else if(tab==='calendar')body.appendChild(releaseCalendar());
      else if(tab==='history')body.appendChild(panel('Your recent watches',cards(data.history,40)));
      else if(tab==='stats')body.appendChild(signed()?gameStats():empty('Sign in to keep your game statistics.'));
      else if(tab==='inbox')body.appendChild(panel('Your invitations',data.notifications.length?notices():[empty('All caught up. Your invitations appear here.')]));
      else if(tab==='cinema'){
        var rooms=h('div',{});
        body.appendChild(panel('Film night, together',[h('p',{className:'muted',text:'Public rooms on your server. The host controls playback; everyone gets their own local stream. Headphones recommended.'}),button('Create a cinema',function(){picker('video',createCinema);},'primary'),rooms]));
        request('rooms').then(function(j){if(!rooms.isConnected)return;rooms.replaceChildren();j.rooms.forEach(function(r){rooms.appendChild(h('div',{className:'hub-game-row'},[h('div',{},[h('strong',{text:r.title}),h('small',{text:'Hosted by '+r.host.name})]),button('Join room',function(){go('cinema',r.id);},'primary')]));});if(!j.rooms.length)rooms.appendChild(empty('No rooms yet. Be the one who starts film night.'));}).catch(error);
      }
    }
    setTimeout(function(){refresh().then(paint).catch(function(e){if(body.isConnected)body.replaceChildren(empty(e.message),button('Try again',function(){refresh(true).then(paint).catch(error);}));});},0);
    return A.shell(root);
  }
  function details(it,root){
    var generation=(root._hubDetailGeneration||0)+1;root._hubDetailGeneration=generation;
    setTimeout(function(){
      if(!root.isConnected || root._hubDetailGeneration!==generation)return;
      var key=titleKey(it),existing=root.querySelector('.hub-community');if(existing)existing.remove();
      root.querySelectorAll('.hub-title-actions,.hub-rating').forEach(function(el){el.remove();});
      var actions=controls(it),hero=root.querySelector('.detail-hero');
      if(hero)hero.appendChild(actions);
      var discussion=panel('Around this title',[empty('Opening the conversation…')]);discussion.classList.add('hub-community');root.appendChild(discussion);
      request('title?key='+encodeURIComponent(key)).then(function(j){
        if(!root.isConnected || root._hubDetailGeneration!==generation)return;
        var rating=h('div',{className:'hub-rating',text:j.rating===null?'Be the first to rate this title':'★ '+j.rating+' / 10 · '+j.rating_count+' community ratings'}),synopsis=root.querySelector('.synopsis');
        if(synopsis)synopsis.parentNode.insertBefore(rating,synopsis);else if(hero)hero.appendChild(rating);
        var bs=actions.querySelectorAll('button');if(bs[0]){bs[0].textContent=j.favourite?'♥ Favourited':'♡ Favourite';bs[0].classList.toggle('selected',j.favourite);}if(bs[1]){bs[1].textContent=j.watchlist?'✓ On watchlist':'+ Watchlist';bs[1].classList.toggle('selected',j.watchlist);}
        // Seed the action state as well as the labels before the first click.
        if(!data)data={favourites:[],watchlist:[]};['favourites','watchlist'].forEach(function(s){var yes=s==='favourites'?j.favourite:j.watchlist;data[s]=(data[s]||[]).filter(function(c){return titleKey(c)!==j.card.hub_key;});if(yes)data[s].push(j.card);});
        actions.replaceChildren();var fresh=controls(j.card);while(fresh.firstChild)actions.appendChild(fresh.firstChild);
        root.querySelectorAll('[data-hub-episode]').forEach(function(row){
          var id=row.getAttribute('data-hub-episode'),watched=!!(j.episodes[id] && j.episodes[id].watched);
          if(!id||!signed())return;
          row.querySelectorAll('.hub-watch-toggle').forEach(function(el){el.remove();});
          var b=button(watched?'✓ Watched':'Mark watched',function(){b.disabled=true;request('watched',{item_id:id,watched:!watched}).then(function(){watched=!watched;b.textContent=watched?'✓ Watched':'Mark watched';row.classList.toggle('hub-watched',watched);loaded=0;refreshContinue();}).catch(error).then(function(){b.disabled=false;});});b.classList.add('hub-watch-toggle');row.classList.toggle('hub-watched',watched);row.appendChild(b);
        });
        var content=discussion.querySelector('.hub-panel-body');content.replaceChildren();
        if(j.episodes && Object.keys(j.episodes).length){var eps=Object.keys(j.episodes),seen=eps.filter(function(id){return j.episodes[id].watched;}).length;content.appendChild(h('p',{className:'hub-episode-progress',text:seen+' of '+eps.length+' available episodes watched'}));}
        if(signed() && it.media_kind==='video' && !it.show)content.appendChild(button('Mark watched',function(){request('watched',{item_id:it.id,watched:true}).then(function(){A.toast('Marked watched');loaded=0;refreshContinue();}).catch(error);}));
        if(signed()){
          var mine=j.own_review||j.reviews.find(function(r){return r.user.id===A.state.user.id;}),text=h('textarea',{maxlength:2000,rows:3,placeholder:'Your take. Keep it kind.','aria-label':'Your review',value:mine?mine.text:''}),ratingSelect=h('select',{'aria-label':'Your rating'},[h('option',{value:0,text:'No rating'})].concat(Array.from({length:10},function(_,i){return h('option',{value:i+1,text:'★ '+(i+1)+' / 10',selected:mine && mine.rating===i+1});})));
          content.appendChild(h('div',{className:'hub-review-form'},[text,h('div',{className:'hub-hero-actions'},[ratingSelect,button(mine?'Update review':'Post review',function(e){var target=e.currentTarget;target.disabled=true;request('review',{key:j.card.hub_key,text:text.value,rating:Number(ratingSelect.value)}).then(function(){details(it,root);}).catch(function(err){target.disabled=false;error(err);});},'primary')])]));
        }else content.appendChild(empty('Sign in to rate and join the conversation.'));
        j.reviews.forEach(function(r){
          var comment=h('details',{className:'hub-review'},[h('summary',{},[avatar(r.user),h('strong',{text:r.user.name}),h('span',{text:r.rating?'★ '+r.rating+'/10':'Comment'}),h('small',{text:'Open review · may contain spoilers'})]),h('p',{text:r.text})]);
          if(signed() && (r.user.id===A.state.user.id||A.state.user.role==='admin'))comment.appendChild(button('Delete',function(){request('review',{key:j.card.hub_key,user_id:r.user.id,delete:true}).then(function(){details(it,root);}).catch(error);}));content.appendChild(comment);
        });
        if(!j.reviews.length)content.appendChild(empty('Start the conversation. What made this one memorable?'));
      }).catch(function(e){if(discussion.isConnected)discussion.querySelector('.hub-panel-body').replaceChildren(empty(e.message));});
    },0);
  }
  function cinemaView(id){
    var root=h('div',{className:'hub-cinema'}),player=h('div',{id:'detail-player',className:'detail-player'}),status=h('p',{className:'muted',role:'status',text:'Opening cinema…'}),people=h('div',{className:'hub-room-people'}),messages=h('div',{className:'hub-chat',role:'log','aria-label':'Room conversation'}),info=panel('In the room',[status,people,messages]);
    root.appendChild(h('div',{className:'hub-cinema-heading'},[h('h1',{text:'Film night'}),button('Copy invite link',function(){A.copyText(A.shareUrl('home/cinema/'+id)).then(function(){A.toast('Cinema link copied');}).catch(error);}),button('Leave',function(){go('cinema');})]));root.appendChild(player);root.appendChild(info);
    var text=h('input',{maxlength:400,placeholder:'Say something…','aria-label':'Room message'}),form=h('form',{className:'hub-chat-form'},[text,h('button',{className:'btn',type:'submit',text:'Send'})]);info.appendChild(form);
    var state={id:id,root:root,media:null,host:false,applying:false,room:null,busy:false,lastMember:0,lastMessage:0,ready:false,unlocked:false};cinema=state;
    function send(extra){if(!signed())return Promise.resolve();var media=state.media,payload=Object.assign(state.host && media?{position:media.currentTime||0,playing:!media.paused && !media.ended && !state.buffering}:{},extra||{});state.outbox=(state.outbox||Promise.resolve()).catch(function(){}).then(function(){return request('rooms/'+id,payload);});return state.outbox;}
    form.onsubmit=function(e){e.preventDefault();if(!signed()){A.toast('Sign in to join the room conversation.');return;}var msg=text.value.trim();if(!msg)return;send({message:msg}).then(function(){text.value='';}).catch(error);};
    var unlock=button('Join playback',function(){if(state.media){state.unlocked=true;state.media.play().then(function(){unlock.remove();}).catch(error);}},'primary');player.appendChild(unlock);
    function synchronize(room){
      var media=state.media;if(!media || !state.ready || state.host)return;
      var target=room.position+(room.playing?Math.max(0,room.server_time-room.updated_at):0);
      state.applying=true;
      if(isFinite(media.duration))target=Math.min(target,Math.max(0,media.duration-0.25));
      if(Math.abs((media.currentTime||0)-target)>1.5)media.currentTime=target;
      media.playbackRate=1;
      if(!room.playing && !media.paused)media.pause();
      if(room.playing && media.paused && state.unlocked)media.play().catch(function(){state.unlocked=false;});
      state.applying=false;
    }
    function setup(room){
      state.host=signed() && room.host_id===A.state.user.id;
      root.querySelector('h1').textContent=room.title;
      A.api('/items/'+room.item_id+'/play',{method:'POST',body:{}}).then(function(play){
        if(!root.isConnected || cinema!==state)return;if(!play.ok)throw new Error(play.error||'Cannot open stream');play.autoplay=false;play.hub_cinema_guest=!state.host;A.openPlayer(room.card,play);
        state.media=root.querySelector('video');if(!state.media)throw new Error('This room requires a browser video player.');
        if(!state.host){state.media.controls=false;player.appendChild(unlock);}else unlock.remove();
        state.media.addEventListener('loadedmetadata',function(){state.ready=true;synchronize(state.room);});if(state.media.readyState>=1)state.ready=true;
        ['play','pause','seeked'].forEach(function(event){state.media.addEventListener(event,function(){if(state.host && !state.applying && root.isConnected)send().catch(error);});});
        ['waiting','stalled','seeking','playing'].forEach(function(event){state.media.addEventListener(event,function(){state.buffering=event!=='playing';if(state.host && root.isConnected)send().catch(error);});});
        if(state.host)info.appendChild(button('End cinema for everyone',function(){send({end:true}).then(function(){go('cinema');}).catch(error);}));
      }).catch(error);
    }
    function poll(){
      if(!root.isConnected || cinema!==state)return;
      if(document.hidden){setTimeout(poll,4000);return;}
      var start=Date.now();
      request('rooms/'+id).then(function(j){
        if(!root.isConnected || cinema!==state)return;
        state.room=j.room;state.room.server_time+=(Date.now()-start)/2000;
        status.textContent=(state.host?'You control playback':'Hosted by '+j.room.host.name+' · playback follows the host')+(j.room.playing?' · Playing':' · Paused');
        people.replaceChildren();j.room.members.forEach(function(p){people.appendChild(h('span',{className:'hub-room-person'},[avatar(p),h('span',{text:p.name})]));});
        messages.replaceChildren();j.room.messages.forEach(function(m){messages.appendChild(h('p',{},[h('strong',{text:m.user.name+': '}),h('span',{text:m.text})]));});
        if(!state.started){state.started=true;setup(j.room);}else synchronize(j.room);
        if(signed() && Date.now()-state.lastMember>(state.host?8000:20000)){state.lastMember=Date.now();send().catch(error);}
        setTimeout(poll,j.room.playing?1500:2500);
      }).catch(function(e){if(root.isConnected){status.textContent=e.message;if(e.status!==422)setTimeout(poll,6000);else{if(state.media)state.media.pause();unlock.disabled=true;}}});
    }
    setTimeout(poll,0);return root;
  }
  function gameStarted(item){
    if(!signed())return;
    var emulator=window.EJS_emulator;if(!emulator || emulator._hubStatsStarted)return;emulator._hubStatsStarted=true;
    var token=Array.from(crypto.getRandomValues(new Uint8Array(12))).map(function(v){return v.toString(16).padStart(2,'0');}).join('');
    gameSession={item:item,token:token};
    function tick(){var current=gameSession;if(!current||current.token!==token)return;var emu=window.EJS_emulator;
      if(!emu || emu._streamDisposed){gameSession=null;return;}
      if(!document.hidden)request('game',{item_id:item.id,session:token,active:!emu.paused && !(emu.netplay && (emu.netplay._streamSyncing||emu.netplay._streamAwaitingState||emu.netplay._streamAwaitingGo||emu.netplay.wait))}).then(function(){loaded=0;}).catch(function(){});
      setTimeout(tick,20000);
    }tick();
    function suspend(){if(document.hidden && gameSession && gameSession.token===token)request('game',{item_id:item.id,session:token,active:false,final:true}).catch(function(){});}
    document.addEventListener('visibilitychange',suspend);
    emulator._hubStatsCleanup=function(){
      document.removeEventListener('visibilitychange',suspend);window.removeEventListener('pagehide',emulator._hubStatsCleanup);
      if(!gameSession || gameSession.token!==token)return;gameSession=null;
      var body={item_id:item.id,session:token,active:false,final:true},sent=false;
      try{sent=navigator.sendBeacon((window.__STREAM_BASE__||'/')+'api/hub/game',new Blob([JSON.stringify(body)],{type:'application/json'}));}catch(err){}
      if(!sent)request('game',body).catch(function(){});
    };
    window.addEventListener('pagehide',emulator._hubStatsCleanup);
  }
  function pollInbox(){
    setTimeout(pollInbox,30000);if(!A || !signed() || document.hidden || !A.state.home)return;
    var uid=A.state.user.id;if(noticeOwner!==uid){noticeSeen={};noticeOwner=uid;}request('inbox').then(function(j){if(!signed()||A.state.user.id!==uid)return;if(data&&owner===uid)data.notifications=j.notifications;j.notifications.forEach(function(n){if(noticeSeen[n.id])return;noticeSeen[n.id]=true;A.toast(n.from.name+' invited you to '+n.title+' · Open Home → Inbox',{ms:8000});});var today=new Date().toISOString().slice(0,10);(data && owner===uid && data.upcoming || []).filter(function(ep){return ep.date===today;}).forEach(function(ep){var id=ep.card.id+ep.date+ep.season+ep.episode;if(noticeSeen[id])return;noticeSeen[id]=true;A.toast(ep.card.title+' · S'+ep.season+' E'+ep.episode+' is scheduled to air today',{ms:8000});});}).catch(function(){});
  }
  window.VaultcastHub={init:function(app){A=app;pollInbox();},view:view,details:details,controls:controls,cardAction:cardAction,gameStarted:gameStarted,go:go,
    lobbyOpened:function(room){var friend=A.state._hubInviteFriend;A.state._hubInviteFriend=null;if(friend)request('invite',{kind:'game',room_id:room.id,user_id:friend.id}).then(function(){A.toast('Invitation sent to '+friend.name);}).catch(error);}};
})();
