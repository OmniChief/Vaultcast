'use strict';

const { createHash, randomBytes } = require('crypto');
const { open } = require('fs').promises;
const { join, posix } = require('path');
const { lockFile } = require('../Workers/file-lock');
const { authorized } = require('./profile-policy');
const { catalogueShowTitle, episodeGroup } = require('./library');
const { documents, enabled, now, parse } = require('./account-data');
const { q, transaction } = require('./queries');
const { save } = require('./stalker-store');

const jobKey = 'node-library-index';
const readyKey = 'browse-index-ready';
const missingReason = 'The prepared browsing index is missing. Prepare browsing pages; no folder scan is needed.';
const missingReasons = new Set([missingReason, 'The prepared browsing index is missing. Prepare browsing pages; a folder scan is not needed.']);
const columns = ['item_id', 'section_id', 'media_kind', 'type', 'title', 'search_path', 'show_key', 'show_name',
  'season', 'episode', 'year', 'system_name', 'modified_at', 'source_sig'];
const integer = value => value == null || value === '' ? null : parseInt(value, 10) || 0;

function projection(row, types) {
  const item = parse(row.payload);
  if (item.id !== row.id || !item.section) throw new Error('Invalid catalogue item');
  const type = item.type || types.get(item.section) || '';
  const rel = item.rel ?? '';
  const show = ['tv', 'anime'].includes(type) ? catalogueShowTitle(item) : '';
  const key = show ? episodeGroup(item) : '';
  // The stored catalogue payload is also the canonical input to the incremental indexer's signature.
  const signature = createHash('sha256').update(row.payload + '|' + type).digest('hex');
  return [row.id, item.section, item.media_kind ?? '', type, item.title ?? '', rel, key, show,
    integer(item.season), integer(item.episode), integer(item.year) || null,
    (item.system ?? '').trim(), integer(item.mtime) || 0, signature];
}

async function indexItems(conn, catalogue, job) {
  const rows = await q(conn, 'SELECT id,payload FROM vaultcast_items WHERE id>? ORDER BY id LIMIT 100', [job.cursor]);
  if (!rows.length) {
    await q(conn, 'DELETE b FROM vaultcast_browse_items b LEFT JOIN vaultcast_items i ON i.id=b.item_id WHERE i.id IS NULL');
    return { ...job, phase: 'shows', cursor: '' };
  }
  const types = new Map(Object.values(catalogue.sections ?? {}).map(s => [s.id, s.type]));
  const values = rows.map(row => projection(row, types));
  const tuple = '(' + columns.map(() => '?').join(',') + ')';
  await q(conn, 'INSERT INTO vaultcast_browse_items (' + columns.join(',') + ') VALUES ' +
    values.map(() => tuple).join(',') + ' ON DUPLICATE KEY UPDATE ' +
    columns.slice(1).map(name => name + '=VALUES(' + name + ')').join(','), values.flat());
  return { ...job, cursor: rows.at(-1).id, items: job.items + rows.length };
}

function showDirectory(item) {
  const path = String(item.path ?? '').replace(/\\/g, '/');
  const rel = String(item.rel ?? '').replace(/\\/g, '/').replace(/^\/+/, '');
  const folder = rel.split('/')[0];
  if (!path || !folder) return null;
  if (path.endsWith(rel)) return path.slice(0, -rel.length).replace(/\/+$/, '') + '/' + folder;
  for (let dir = posix.dirname(path), depth = 0; depth < 6; depth++, dir = posix.dirname(dir))
    if (posix.basename(dir) === folder) return dir;
  return null;
}

async function indexShows(conn, job) {
  const keys = await q(conn, 'SELECT DISTINCT show_key FROM vaultcast_browse_items WHERE show_key>? ORDER BY show_key LIMIT 25', [job.cursor]);
  if (!keys.length) {
    await q(conn, 'DELETE s FROM vaultcast_show_summaries s LEFT JOIN vaultcast_browse_items b ON b.show_key=s.show_key WHERE b.item_id IS NULL');
    await save(conn, 'show-summaries', now());
    await save(conn, readyKey, true);
    return { ...job, phase: 'complete', cursor: '' };
  }
  const groups = await q(conn, 'SELECT g.*,i.payload AS sample,s.payload AS previous FROM (' +
    'SELECT show_key,section_id,MIN(show_name) AS title,MIN(type) AS type,MIN(item_id) AS sample_id,MIN(year) AS year,' +
    'COUNT(*) AS available,COUNT(DISTINCT season) AS seasons,MAX(modified_at) AS mtime FROM vaultcast_browse_items ' +
    'WHERE show_key IN (' + keys.map(() => '?').join(',') + ') GROUP BY show_key,section_id) g ' +
    'JOIN vaultcast_items i ON i.id=g.sample_id LEFT JOIN vaultcast_show_summaries s ON s.show_key=g.show_key', keys.map(row => row.show_key));
  for (const group of groups) {
    const sample = parse(group.sample);
    const card = parse(group.previous) ?? { id: group.show_key, show_key: group.show_key, kind: 'show',
      episode_count: Number(group.available), season_count: Number(group.seasons), year: group.year, missing_count: null,
      has_poster: true, genres: [], status: null, network: null, _meta_prepared: true };
    Object.assign(card, { title: group.title, section: group.section_id, type: group.type,
      available_count: Number(group.available), mtime: Number(group.mtime),
      show_dir: showDirectory(sample) ?? card.show_dir ?? null,
      local_poster: sample.show_poster ?? sample.poster ?? card.local_poster ?? null });
    await q(conn, 'INSERT INTO vaultcast_show_summaries(show_key,section_id,title,type,source_sig,payload) VALUES(?,?,?,?,?,?) ' +
      'ON DUPLICATE KEY UPDATE section_id=VALUES(section_id),title=VALUES(title),type=VALUES(type),source_sig=VALUES(source_sig),payload=VALUES(payload)',
      [group.show_key, group.section_id, group.title, group.type, randomBytes(16).toString('hex'), JSON.stringify(card)]);
  }
  return { ...job, cursor: keys.at(-1).show_key, shows: job.shows + keys.length };
}

const initialJob = revision => ({ phase: 'items', cursor: '', revision: String(revision), items: 0, shows: 0 });
const pending = job => job && !job.failed && ['items', 'shows'].includes(job.phase);
const status = (ready, job) => ({ ok: true, ready: enabled(ready), required: !enabled(ready),
  phase: enabled(ready) ? 'complete' : job?.failed ? 'failed' : pending(job) ? job.phase : 'needed',
  items: Number(job?.items ?? 0), shows: Number(job?.shows ?? 0) });

// Other catalogue writers use this row lock when replacing the library. Item-level edits also advance its revision.
async function catalogueRow(conn) {
  return (await q(conn, "SELECT revision,payload FROM vaultcast_documents WHERE name='catalogue' FOR UPDATE"))[0];
}

// Keep the existing Background tasks reminder in sync. The shared file is locked by inode on both platforms.
async function syncReminder(root, pool) {
  const path = join(root, 'data/runtime/maintenance-requirements.json');
  let file;
  try { file = await open(path, 'r+'); }
  catch (error) {
    if (error.code !== 'ENOENT') throw error;
    if (enabled((await documents(pool, [readyKey]))[readyKey])) return;
    try { file = await open(path, 'wx+', 0o660); }
    catch (other) { if (other.code !== 'EEXIST') throw other; file = await open(path, 'r+'); }
  }
  try {
    await lockFile(file, 3);
    const original = await file.readFile('utf8');
    const state = { ...JSON.parse(original || '{}') };
    const rows = await q(pool, "SELECT name,revision,IF(name='browse-index-ready',payload,NULL) AS flag FROM vaultcast_documents WHERE name IN ('catalogue','browse_epoch','browse-index-ready')");
    const byName = new Map(rows.map(row => [row.name, row]));
    const previous = state.pending?.views;
    if (enabled(parse(byName.get(readyKey)?.flag))) {
      if (!previous || !missingReasons.has(previous.reason)) return;
      delete state.pending.views;
    } else {
      if (!byName.has('catalogue')) return;
      const revision = byName.get('catalogue').revision + '|' + (byName.get('browse_epoch')?.revision ?? 0);
      if (previous && (!missingReasons.has(previous.reason) || previous.revision === revision)) return;
      state.pending = { ...state.pending, views: { id: 'views', task: 'views', reason: missingReason, revision, at: now(), release: null } };
    }
    const bytes = Buffer.from(JSON.stringify(state));
    let offset = 0;
    while (offset < bytes.length) {
      const { bytesWritten } = await file.write(bytes, offset, bytes.length - offset, offset);
      if (!bytesWritten) throw new Error('Could not persist library maintenance reminder');
      offset += bytesWritten;
    }
    await file.truncate(bytes.length);
    await file.sync();
  } finally { await file.close(); }
}

module.exports = function mountLibraryIndex(app, prefix, { root, db, handle, updating }) {
  let timer, active = false, wakeRequested = false;
  const refreshReminder = pool => syncReminder(root, pool).catch(error =>
    console.warn('Vaultcast library index reminder:', error.code || error.name));
  function schedule(delay) {
    if (active) { wakeRequested = true; return; }
    clearTimeout(timer);
    timer = setTimeout(work, delay);
    timer.unref();
  }
  async function work() {
    timer = null;
    active = true;
    let delay = null, lock, finished = false;
    try {
      if (!db.ready() || await updating()) { delay = 5000; return; }
      const saved = (await db.run(pool => documents(pool, [jobKey])))[jobKey];
      if (!pending(saved)) {
        if (saved?.phase === 'complete') await db.run(refreshReminder);
        return;
      }
      lock = await open(join(root, 'data/maintenance.lock'), 'a');
      try { await lockFile(lock, 0); } catch { delay = 3000; return; }
      await db.run(pool => transaction(pool, async conn => {
        const catalogue = await catalogueRow(conn);
        const docs = await documents(conn, [readyKey, jobKey]);
        let job = docs[jobKey];
        if (!pending(job)) return;
        if (enabled(docs[readyKey]) || !catalogue) {
          await save(conn, jobKey, { ...job, phase: 'complete' });
          finished = true;
          return;
        }
        if (job.revision !== String(catalogue.revision)) job = initialJob(catalogue.revision);
        const next = job.phase === 'items' ? await indexItems(conn, parse(catalogue.payload), job) : await indexShows(conn, job);
        await save(conn, jobKey, next);
        if (pending(next)) delay = 50;
        else finished = true;
      }));
      if (finished) await db.run(refreshReminder);
    } catch (error) {
      console.warn('Vaultcast library index preparation:', error.code || error.name);
      if (!lock || ['ER_LOCK_DEADLOCK', 'ER_LOCK_WAIT_TIMEOUT', 'ECONNRESET', 'ECONNREFUSED'].includes(error.code)) {
        delay = 5000;
      } else {
        try {
          await db.run(pool => transaction(pool, async conn => {
            await catalogueRow(conn);
            const job = (await documents(conn, [jobKey]))[jobKey];
            if (pending(job)) await save(conn, jobKey, { ...job, failed: true });
          }));
        } catch { delay = 5000; }
      }
    } finally {
      if (lock) await lock.close().catch(() => {});
      active = false;
      if (wakeRequested) { wakeRequested = false; schedule(0); }
      else if (delay !== null) schedule(delay);
    }
  }
  const route = prefix + '/admin/library-index';
  const endpoint = handle(async (request, response, pool, _bcrypt, _settings, current) => {
    if (!authorized(response, current)) return;
    if (current.user.role !== 'admin')
      return response.status(403).json({ ok: false, error: 'Administrator access required.' });
    if (request.method !== 'POST') {
      const docs = await documents(pool, [readyKey, jobKey]);
      if (pending(docs[jobKey])) {
        if (!active && !timer) schedule(0);
      } else {
        await refreshReminder(pool);
      }
      return response.json(status(docs[readyKey], docs[jobKey]));
    }
    if (!request.is('application/json')) return response.status(415).json({ ok: false, error: 'Expected a JSON request.' });
    const result = await transaction(pool, async conn => {
      const catalogue = await catalogueRow(conn);
      const docs = await documents(conn, [readyKey, jobKey]);
      if (!catalogue) return { ok: false, error: 'Scan a library folder before preparing the index.' };
      if (enabled(docs[readyKey])) return status(true, null);
      const previous = docs[jobKey];
      const job = previous?.revision === String(catalogue.revision) && ['items','shows'].includes(previous.phase)
        ? { ...previous, failed: false } : initialJob(catalogue.revision);
      await save(conn, jobKey, job);
      return status(false, job);
    });
    if (!result.ok) return response.status(409).json(result);
    if (!result.ready) schedule(0);
    return response.status(result.ready ? 200 : 202).json(result);
  }, true);
  app.get(route, endpoint);
  app.post(route, endpoint);
  schedule(1000);
  return () => active;
};
