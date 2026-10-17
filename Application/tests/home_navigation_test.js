/* A root-page refresh upgrades chrome without discarding visible SSR cards. */
const fs=require('fs'), vm=require('vm'), assert=require('assert');
const src=fs.readFileSync(__dirname+'/../assets/js/app.js','utf8');
const R=require('../assets/js/routes.js');
const controls=src.slice(src.indexOf('  function accountMenu(){'),src.indexOf('  function subnavEl(mode){'));
const startup=src.slice(src.indexOf('  function enhanceHomeNavigation(){'),src.indexOf('  var initialMe ='));
function fixture(path='/',base='/',hash='') {
  function h(tag,props={},children=[]) {
    const n={tag,props,children:children.filter(Boolean),parent:null,addEventListener(){},
      appendChild(c){this.children.push(c);c.parent=this;},
      replaceChild(next,old){const at=this.children.indexOf(old);assert(at>=0);if(next.parent)next.parent.children.splice(next.parent.children.indexOf(next),1);this.children[at]=next;next.parent=this;old.parent=null;},
      querySelector(selector){const match=n=>selector==='header'?n.tag==='header':selector[0]==='.'?String(n.props.className||'').split(' ').includes(selector.slice(1)):selector[0]==='#'?n.props.id===selector.slice(1):false;for(const c of this.children){if(match(c))return c;const found=c.querySelector(selector);if(found)return found;}return null;}};
    n.children.forEach(c=>c.parent=n);return n;
  }
  const oldHeader=h('header'),oldSections=h('nav',{className:'first-sections'}),cards=h('main',{id:'main'},[h('a',{text:'Visible film'})]);
  const first=h('div',{id:'first-home'},[oldHeader,oldSections,cards]);
  const root=h('div',{id:'app'},[first]);
  const home={ok:true,features:{},sections:[],continue_watching:[]};
  const user={id:'u',username:'Alice'};
  const c={h,R,BASE:base,state:{user,view:'home',searchQuery:''},window:{__STREAM_STARTUP__:{serverRendered:true,home,me:{user},refresh:false}},
    location:{pathname:path,hash},document:{getElementById:id=>id==='first-home'?first:null},
    $:selector=>selector==='#app'?root:null,applyPrefs(){},boots:0,bootApp(){c.boots++;},
    icon:name=>h('svg',{name}),openSettings:section=>{c.settingsOpened=section;},runGlobalSearch:q=>{c.searched=q;},
    replaceNav(){c.replaced=true;},setTimeout(){},loadLiveTeaser(){},
    api(){throw Error('Warm Home must not fetch another API response');},render(){throw Error('SSR cards must not be repainted');},
    shell(content){assert.strictEqual(content,null);return h('div',{},[h('header',{},[c.searchBoxEl('vod'),c.accountMenu()]),h('nav',{className:'subnav'},[h('button',{className:'subnav-btn active',text:'Home'})]),h('main')]);}
  };
  vm.createContext(c);vm.runInContext(controls+startup,c);return {c,first,cards,oldHeader,oldSections};
}
for(const [path,base] of [['/','/'],['/vault/','/vault/'],['/index.php','/']]) {
  const {c,first,cards,oldHeader,oldSections}=fixture(path,base);c.enhanceFirstPage();
  assert.strictEqual(c.boots,0);assert(c.replaced);assert.strictEqual(first.querySelector('#main'),cards);
  assert(!first.children.includes(oldHeader));assert(!first.children.includes(oldSections));
  assert.equal(first.querySelector('.active').props.text,'Home');
  const input=first.querySelector('#q');assert(input.props.className==='search-box');
  input.props.onKeydown({key:'Enter',target:{value:'Alien'}});assert.equal(c.searched,'Alien');
  first.querySelector('.avatar').props.onClick();assert.equal(c.settingsOpened,'account');
}
for(const [path,hash] of [['/library/movies',''],['/','#/home']]) {
  const {c,first,oldHeader}=fixture(path,'/',hash);c.enhanceFirstPage();
  assert.equal(c.boots,1);assert(first.children.includes(oldHeader),'deep and legacy routes follow normal routing');
}
console.log('all passed');
