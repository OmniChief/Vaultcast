/* Settings update flow: branch preview, deleted pins and explicit apply target. */
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const source = fs.readFileSync(__dirname + '/../assets/js/app.js', 'utf8');
const code = source.slice(source.indexOf('  function renderUpdatesPanel('), source.indexOf('  function renderConvertAdminPanel('));
function el(tag, attrs = {}, children = []) {
  return { tag, attrs, children: children.filter(Boolean), value: attrs.value || '',
    appendChild(child) { this.children.push(child); },
    set textContent(value) { this.children = []; this.text = value; },
    get textContent() { return this.text || ''; } };
}
function find(root, predicate) {
  if (predicate(root)) return root;
  for (const child of root.children || []) { const result = find(child, predicate); if (result) return result; }
}
async function flush() { for (let i = 0; i < 8; i++) await Promise.resolve(); }
(async () => {
  const calls = [];
  let response = { ok: true, current: '1.0.3', current_branch: 'main', selected_branch: 'main', branches: ['main', 'perf/fast'], update_available: false, latest: { branch: 'main', version: '1.0.3' } };
  const context = { h: el, location: { reload() {} }, setTimeout() {}, confirmDialog: () => Promise.resolve(true),
    api(path, opts) { calls.push({ path, opts }); return Promise.resolve(opts ? { ok: true, branch: opts.body.branch, to: '1.0.3', replaced: 0, created: 0, removed: 0 } : response); } };
  vm.createContext(context); vm.runInContext(code, context);
  const host = el('div');
  context.renderUpdatesPanel(host); await flush();
  let selector = find(host, n => n.tag === 'select');
  assert.deepStrictEqual(selector.children.map(n => n.attrs.value), ['main', 'perf/fast']);
  response = { ...response, selected_branch: 'perf/fast', branch_change: true, update_available: true, latest: { branch: 'perf/fast', version: '1.0.3', tree: 'abc', count: 0 } };
  selector.value = 'perf/fast'; selector.onchange(); await flush();
  assert.strictEqual(calls.at(-1).path, '/admin/updates?branch=perf%2Ffast');
  const button = find(host, n => n.attrs?.text === 'Switch to perf/fast');
  assert(button, 'same-version, zero-file branch switch is offered');
  button.attrs.onClick(); await flush();
  assert.strictEqual(calls.at(-1).opts.body.branch, 'perf/fast');
  assert.strictEqual(calls.at(-1).opts.body.tree, 'abc');
  response = { ok: false, current_branch: 'deleted/test', selected_branch: 'deleted/test', branches: ['main'], error: 'Branch deleted' };
  context.renderUpdatesPanel(host); await flush();
  selector = find(host, n => n.tag === 'select');
  assert.deepStrictEqual(selector.children.map(n => n.attrs.value), ['main']);
  assert(find(host, n => n.attrs?.text === 'Installed branch: deleted/test'));
  find(host, n => n.attrs?.text === 'Check selected branch').attrs.onClick(); await flush();
  assert.strictEqual(calls.at(-1).path, '/admin/updates?branch=main');
  assert.strictEqual(calls.filter(c => c.opts).length, 1, 'previewing never applies a switch');
  console.log('all passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
