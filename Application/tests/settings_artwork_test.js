const fs = require('fs'), vm = require('vm'), assert = require('assert');
const source = fs.readFileSync(__dirname + '/../assets/js/app.js', 'utf8');
const code = source.slice(source.indexOf('  function renderPosterOptimisePanel(){'), source.indexOf('  function renderOptimiseSystemPanel(){'));
const ids = {}, nodes = [], requests = [], timers = [], messages = [];
function h(tag, props = {}, children = []) {
  const node = Object.assign({tag, children: [], appendChild(child) { this.children.push(child); }}, props);
  Object.defineProperty(node, 'innerHTML', {set() { node.children = []; }});
  Object.defineProperty(node, 'textContent', {get() { return node.text || node.children.map(x => x.textContent || '').join(''); }, set(v) { node.text = v; }});
  if (props.id) ids['#' + props.id] = node;
  node.children = children; nodes.push(node); return node;
}
h('div', {id:'poster-optimise-panel'});
const context = {h, $:key => ids[key], state:{settings:{poster_optimise:{}}}, pendingContent:props => h('div',props),
  toast:text => messages.push(text), fmtShortBytes: x => String(x), saveSettings() {}, clearTimeout() {},
  setTimeout:fn => { timers.push(fn); return timers.length; },
  api:(url, options) => {
    if (!url.includes('/find?')) return Promise.resolve({ok:true, settings:{thumb_quality:72}, summary:{settings:{thumb_quality:72}}, queue:{jobs:[]}});
    return new Promise((resolve,reject) => requests.push({url,resolve,reject}));
  }
};
vm.createContext(context); vm.runInContext(code,context); context.renderPosterOptimisePanel();
const button = text => nodes.find(n => n.tag === 'button' && n.text === text);
const flush = async () => { for(let i=0;i<8;i++) await Promise.resolve(); };
(async () => {
  await flush();
  ids['#po_thumb_q'].value='85';
  button('Refresh queue').onClick(); await flush();
  assert.equal(ids['#po_thumb_q'].value,'85','queue refresh preserves unsaved controls');
  button('Find all').onClick(); assert.equal(requests.length,1);
  button('Stop').onClick(); button('Find all').onClick();
  requests[0].resolve({ok:true,total:99,scanned:50,items:[],done:true}); await flush();
  assert(!ids['#po-find-status'].textContent.includes('99'),'stopped response cannot mutate replacement scan');
  requests[1].resolve({ok:true,total:3,scanned:3,items:[],ready:2,missing_artwork:1,done:true}); await flush();
  assert(ids['#po-find-status'].textContent.includes('3/3 library artwork entries'));
  assert(ids['#po-find-status'].textContent.includes('2 ready · 1 missing artwork'));
  // All four scope chips off must not trigger an accidental full-library scan.
  const scopeChips=nodes.filter(n=>n.tag==='input' && n.type==='checkbox' && n.onChange);
  scopeChips.forEach(n=>n.onChange({target:{checked:false}}));
  const count=requests.length;button('Find all').onClick();assert.equal(requests.length,count);
  assert(messages.includes('Select at least one artwork type'));
  console.log('all passed');
})().catch(e=>{console.error(e);process.exitCode=1;});
