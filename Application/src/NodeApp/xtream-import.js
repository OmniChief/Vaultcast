'use strict';

const { randomBytes } = require('crypto');
const { sha } = require('./account-data');
const { cached } = require('./profile-policy');
const { cache, doc, save } = require('./stalker-store');
const { mediaUrl, phpJson } = require('./stalker-provider');

const settingsKey = 'xtream-import-settings';
const fail = (message, status = 422) => Object.assign(new Error(message), { status, expose: true });
const labels = new Map(Object.entries({
  portal: 'url', server: 'url', serverurl: 'url', url: 'url', host: 'url',
  username: 'username', user: 'username', password: 'password', pass: 'password',
  created: 'created', createdat: 'created', exp: 'expiry', expiry: 'expiry', expires: 'expiry',
  expiration: 'expiry', expirydate: 'expiry', conn: 'connections', connections: 'connections',
  activeconnections: 'connections', maxconn: 'maximum', maxconnections: 'maximum', port: 'port', status: 'status',
}));
const label = text => labels.get(text.normalize('NFKC').replace(/[^a-z0-9]/gi, '').toLowerCase());
const id = bytes => randomBytes(bytes).toString('hex');
const status = settings => ({ ok: true, configured: Boolean(settings.url), width: settings.width ?? '1270' });
const attr = (node, key) => node.attrs.find(entry => entry.name === key)?.value ?? '';

function* walk(root) {
  const pending = [root];
  while (pending.length) {
    const node = pending.pop();
    if (['script', 'style', 'noscript'].includes(node.tagName)) continue;
    yield node;
    for (let i = (node.childNodes?.length ?? 0) - 1; i >= 0; i--) pending.push(node.childNodes[i]);
  }
}

function connection(fields, position) {
  const details = {};
  for (const key of ['created', 'expiry', 'connections', 'maximum', 'port', 'status'])
    details[key] = Array.from(fields[key] ?? '').slice(0, 160).join('');
  const result = { id: id(12), name: 'Provider connection ' + position, url: '', details, valid: false, issue: '' };
  try {
    const address = fields.url.trim().replace(/\/+$/, '').replace(/\/player_api\.php$/i, '');
    if (address.includes('*')) throw fail('Server address is masked.');
    let url;
    try { url = mediaUrl(address); } catch { throw fail('Enter an HTTP or HTTPS portal URL without embedded credentials.'); }
    if (url.search || url.hash) throw fail('Enter a portal URL without a query or fragment.');
    const port = fields.port?.trim();
    if (port) {
      if (!/^\d+$/.test(port) || Number(port) < 1 || Number(port) > 65535) throw fail('Invalid port.');
      // URL normalizes an explicitly specified default port away.
      const explicit = address.match(/^https?:\/\/(?:\[[^\]]+\]|[^/:?#]+):(\d+)(?:\/|$)/i);
      if (explicit && Number(explicit[1]) !== Number(port)) throw fail('Server address and port field disagree.');
      url.port = port;
    }
    result.url = url.href.replace(/\/$/, '');
    for (const key of ['username', 'password']) {
      const value = (fields[key] ?? '').trim();
      if (!value || /^[*•●]+$/u.test(value) || Buffer.byteLength(value) > 512 || /[\r\n\0]/.test(value))
        throw fail('Username or password is missing, masked or invalid.');
      result[key] = value;
    }
    result.valid = true;
  } catch (error) {
    if (!error.expose) throw error;
    result.issue = error.message;
  }
  return result;
}

function entriesFromPage(html, parser, width) {
  const entries = [], seen = new Set();
  let tables = 0, examined = 0;
  function record(fields) {
    if (!fields?.url) return;
    const key = JSON.stringify(Object.entries(fields).sort(([a], [b]) => a.localeCompare(b)));
    if (seen.has(key)) return;
    seen.add(key);
    entries.push(connection(fields, entries.length + 1));
  }
  for (const table of walk(parser.parse(html))) {
    if (table.tagName !== 'table' || (width && attr(table, 'width').trim() !== width)) continue;
    tables++;
    const columns = [];
    let headers;
    const rows = table.childNodes.flatMap(node => ['thead', 'tbody', 'tfoot'].includes(node.tagName) ? node.childNodes : [node]);
    for (const row of rows) {
      if (row.tagName !== 'tr') continue;
      const cells = [];
      for (const cell of row.childNodes) {
        if (cell.tagName !== 'th' && cell.tagName !== 'td') continue;
        if (++examined > 20000) throw fail('The provider table is too large. Select a narrower table.');
        const span = Math.max(1, parseInt(attr(cell, 'colspan'), 10) || 1);
        if (span > 20 || Number(attr(cell, 'rowspan')) > 1)
          throw fail('The table has ambiguous merged cells. Enter these connections manually.');
        let text = '';
        for (const node of walk(cell)) {
          if (node.nodeName === '#text') text += node.value;
          else if (node.tagName === 'br') text += '\n';
          if (text.length > 4096) throw fail('A provider table cell is too large.');
        }
        text = text.replace(/\u00a0/g, ' ').trim();
        if (Buffer.byteLength(text) > 4096) throw fail('A provider table cell is too large.');
        cells.push(text, ...Array(span - 1).fill(''));
      }
      const possible = cells.map(label);
      if (['url', 'username', 'password'].every(key => possible.includes(key))) { headers = possible; continue; }
      if (headers) {
        const fields = {};
        cells.forEach((value, i) => { if (headers[i]) fields[headers[i]] = value; });
        record(fields);
        continue;
      }
      cells.forEach((text, i) => {
        for (const line of text.split(/\r?\n/)) {
          const pair = line.trim().match(/^([^:：]+)[:：]\s*(.*)$/u);
          const key = pair && label(pair[1]);
          if (!key) continue;
          if (key === 'url' && columns[i]?.url) { record(columns[i]); columns[i] = {}; }
          const fields = columns[i] ??= {};
          const value = pair[2].trim();
          if (Object.hasOwn(fields, key) && fields[key] !== value)
            throw fail('Conflicting fields in a provider column. Enter that connection manually.');
          fields[key] = value;
        }
      });
    }
    columns.forEach(record);
  }
  if (!tables) throw fail('No matching table found. Check the table width, or leave it blank to search all tables.');
  return entries;
}

function identity(entry) {
  try { return JSON.stringify([mediaUrl(entry.url).href.replace(/\/+$/, ''), entry.username]); }
  catch { return null; }
}

async function providerImport({ root, pool, body, viewer, parser, readPage, checkAccount }) {
  const settings = await doc(pool, settingsKey);
  if (!body) return status(settings);
  const operation = body.operation;
  if (operation === 'configure') {
    let next = {};
    if (!body.clear) {
      const url = String(body.url ?? '').trim() || settings.url;
      const width = String(body.width ?? '1270').trim();
      try {
        if (!url || url.length > 8000 || mediaUrl(url).hash) throw new Error();
      } catch { throw fail('Enter a valid HTTP(S) provider page URL.'); }
      if (width && !/^\d{1,5}$/.test(width)) throw fail('Enter a numeric table width, or leave blank to search all tables.');
      next = { url, width };
    }
    await save(pool, settingsKey, next);
    return status(next);
  }
  const fingerprint = sha(phpJson(settings));
  if (operation === 'discover') {
    if (!settings.url) throw fail('Save a provider page URL first.');
    const entries = entriesFromPage(await readPage(settings.url), parser, settings.width ?? '1270');
    const token = id(24);
    await cache(root, 'xtream-import:' + token, { viewer, settings: fingerprint, entries }, 1800);
    const saved = new Set(Object.values((await doc(pool, 'xtream-connections')).connections ?? {}).map(identity));
    return { ok: true, token, entries: entries.map(({ username, password, ...entry }) => ({
      ...entry, added: entry.valid && saved.has(identity({ url: entry.url, username })),
      credentials: entry.valid ? 'Stored privately' : 'Incomplete',
    })) };
  }
  if (!['check', 'add'].includes(operation)) throw fail('Unknown provider import operation.');
  const preview = typeof body.token === 'string' && /^[a-f0-9]{48}$/.test(body.token) && await cached(root, 'xtream-import:' + body.token);
  if (!preview || preview.viewer !== viewer || preview.settings !== fingerprint)
    throw fail('Discovery expired or the source changed. Fetch the table again.');
  const entry = preview.entries.find(candidate => candidate.id === body.id);
  if (!entry?.valid) throw fail('Select a complete, unmasked connection.');
  if (operation === 'check') return { ok: true, account: await checkAccount(entry) };
  const stored = await doc(pool, 'xtream-connections');
  const feeds = Object.values(stored.connections ?? {});
  if (feeds.some(feed => identity(feed) === identity(entry))) return { ok: true, added: false, message: 'This server and username are already saved.' };
  const name = String(body.name ?? entry.name).trim();
  if (!name || Buffer.byteLength(name) > 160) throw fail('Enter a connection name (up to 160 characters).');
  const feed = { id: 'xtream-' + id(6), name, url: entry.url, username: entry.username, password: entry.password, enabled: true };
  await save(pool, 'xtream-connections', { ...stored, connections: { ...stored.connections, [feed.id]: feed } });
  return { ok: true, added: true, message: 'Connection added.' };
}

module.exports = providerImport;
