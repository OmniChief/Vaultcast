'use strict';

const { now, sha } = require('../NodeApp/account-data');
const { q, page, liveKey, saveSnapshot } = require('../NodeApp/stalker-store');
const { parseLiveCategories } = require('../NodeApp/stalker-live-browse');
const { country, genre } = require('../NodeApp/stalker-taxonomy');

const maxChannels = 50000;
const maxEntries = 100000;
const maxBytes = 32 * 1024 * 1024;
const scope = job => [job.feed_id, 'live', job.generation];
const invalid = message => Object.assign(new Error(message), { catalogue: true });

function channel(row) {
  if (!row || row.id == null || typeof row.id === 'object' ||
      !String(row.id) || Buffer.byteLength(String(row.id)) > 120 ||
      typeof row.cmd !== 'string' || !row.cmd.trim()) return null;
  const item = { id: String(row.id), cmd: row.cmd };
  for (const field of ['name', 'number', 'tv_genre_id', 'group_title', 'xmltv_id',
    'archive', 'enable_tv_archive', 'tv_archive_duration', 'allow_pvr', 'allow_local_timeshift',
    'use_http_tmp_link', 'use_load_balancing']) {
    if (row[field] != null && typeof row[field] !== 'object') item[field] = row[field];
  }
  return item;
}

async function bulkChannels(client) {
  let response;
  try {
    response = await client.call('itv', 'get_all_channels');
  } catch (error) {
    if (error.retryAt || client.signal?.aborted) throw error;
    // Some portals return js:null for an unimplemented bulk action even after
    // a successful login. The ordered-list request still authenticates normally.
    if (error.emptyReply) return null;
    if (error.authentication) throw error;
    if ([400,404,405,413,422,501].includes(error.status) || error instanceof SyntaxError ||
        /invalid response|exceeds the size limit/.test(error.message)) return null;
    throw error;
  }
  const raw = response.data ?? response;
  if (typeof raw !== 'object' || !raw) return null;
  const rows = Object.values(raw);
  if (rows.length > maxChannels) throw invalid('Live TV exceeds the channel limit.');
  if (Object.hasOwn(response, 'total_items') && Number(response.total_items) > rows.length) return null;
  const channels = new Map();
  for (const row of rows) {
    const item = channel(row);
    if (item) {
      if (channels.has(item.id)) return null;
      channels.set(item.id, item);
    }
  }
  return channels.size ? channels : null;
}

module.exports = function liveImporter(root, database, commit) {
  async function publish(feed, job, client, categories, channels) {
    if (!channels.size) throw invalid('The provider returned no playable Live TV channels.');
    let guide = {};
    try {
      const result = await client.call('itv', 'get_epg_info', { period: 48 });
      if (typeof (result.data ?? result) === 'object') guide = result.data ?? result;
    } catch (error) {
      if (client.signal?.aborted) throw error;
    }
    client.signal?.throwIfAborted();
    await saveSnapshot(root, liveKey(feed, 'imported-live'), {
      categories, channels: Object.fromEntries(channels), guide, imported: now(),
    }, install => commit(job, async connection => {
      client.signal?.throwIfAborted();
      await install();
      await q(connection,
        "UPDATE vaultcast_stalker_jobs SET status='complete',last_hash='complete',processed=?,next_run=0,error='' WHERE feed_id=? AND kind=? AND generation=?",
        [channels.size, ...scope(job)]);
    }));
  }

  async function discover(feed, job, client) {
    const categories = parseLiveCategories(await client.call('itv', 'get_genres'), true);
    const channels = await bulkChannels(client);
    if (channels) return publish(feed, job, client, categories, channels);
    const hasNamed = categories.some(row => row.id !== '*');
    await commit(job, async connection => {
      for (let i = 0; i < categories.length; i += 100) {
        const batch = categories.slice(i, i + 100);
        await q(connection,
          'INSERT INTO vaultcast_stalker_folders(feed_id,kind,generation,folder_id,title,status) VALUES ' +
            batch.map(() => '(?,?,?,?,?,?)').join(','),
          batch.flatMap(row => [...scope(job), row.id, row.name,
            row.id === '*' && hasNamed ? 'complete' : 'pending']));
      }
      await q(connection,
        "UPDATE vaultcast_stalker_jobs SET last_hash='live_pages',status='partial',next_page=1,next_run=0,error='' WHERE feed_id=? AND kind=? AND generation=?",
        scope(job));
    });
  }

  async function importPage(job, folder, client) {
    const number = Number(folder.next_page);
    if (number > 10000) throw invalid('Live TV folder exceeds the page limit.');
    const response = await client.ordered('itv',
      { sortby: 'number', genre: folder.folder_id, search: '', fav: 0 }, number, page);
    const { rows, total } = page(response);
    const processed = Number(folder.processed) + rows.length;
    const advertised = total || (folder.total == null ? null : Number(folder.total));
    const pageSize = Number(response.max_page_items);
    if (advertised != null && (processed > advertised || (!rows.length && processed < advertised)))
      throw invalid('The portal returned an incomplete Live TV folder.');
    if (Number(job.processed) + rows.length > maxEntries)
      throw invalid('Live TV exceeds the folder membership limit.');
    const done = !rows.length || processed === advertised ||
      (advertised == null && Number.isSafeInteger(pageSize) && pageSize > 0 && rows.length < pageSize);
    await commit(job, async connection => {
      // Record headings as well as channels so repeating a page can never mark
      // a partial lineup complete. Folder membership is part of the unique key.
      let batch = [], bytes = 0;
      async function flush() {
        if (!batch.length) return;
        const result = await q(connection,
          'INSERT IGNORE INTO vaultcast_stalker_staging(feed_id,kind,generation,item_id,category,payload) VALUES ' +
            batch.map(() => '(?,?,?,?,?,?)').join(','), batch.flat());
        if (result.affectedRows !== batch.length) throw invalid('The portal repeated a Live TV page.');
        batch = [];
        bytes = 0;
      }
      for (const row of rows) {
        const payload = JSON.stringify(channel(row));
        const size = Buffer.byteLength(payload);
        if (size > 256 * 1024) throw invalid('The provider returned an oversized channel record.');
        if (batch.length >= 100 || bytes + size > 256 * 1024) await flush();
        batch.push([...scope(job), sha(JSON.stringify([folder.folder_id, String(row.id)])), folder.folder_id, payload]);
        bytes += size;
      }
      await flush();
      await q(connection,
        "UPDATE vaultcast_stalker_folders SET status=?,processed=?,total=?,next_page=next_page+1,last_success=?,failures=0,next_run=0,error='' WHERE feed_id=? AND kind=? AND generation=? AND folder_id=?",
        [done ? 'complete' : 'partial', processed, advertised, now(), ...scope(job), folder.folder_id]);
      await q(connection,
        "UPDATE vaultcast_stalker_jobs SET status='partial',processed=processed+?,next_run=0,next_page=1,error='' WHERE feed_id=? AND kind=? AND generation=?",
        [rows.length, ...scope(job)]);
    });
  }

  async function finish(feed, job, client) {
    const folders = await database(pool => q(pool,
      'SELECT folder_id,title FROM vaultcast_stalker_folders WHERE feed_id=? AND kind=? AND generation=? ORDER BY folder_id',
      scope(job)));
    const categories = folders.map(({ folder_id: id, title: name }) => ({ id, name, country: country(name), genre: genre(name) }));
    const channels = new Map();
    let after = '', bytes = 0;
    for (;;) {
      client.signal?.throwIfAborted();
      const rows = await database(pool => q(pool,
        'SELECT item_id,category,payload FROM vaultcast_stalker_staging WHERE feed_id=? AND kind=? AND generation=? AND item_id>? ORDER BY item_id LIMIT 200',
        [...scope(job), after]));
      for (const row of rows) {
        bytes += Buffer.byteLength(row.payload);
        if (bytes > maxBytes) throw invalid('Live TV snapshot exceeds the size limit.');
        const item = JSON.parse(row.payload);
        if (!item) continue;
        if (!channels.has(item.id)) {
          item.tv_genre_id ??= row.category;
          item.importFolders = [];
          channels.set(item.id, item);
        }
        channels.get(item.id).importFolders.push(row.category);
        if (channels.size > maxChannels) throw invalid('Live TV exceeds the channel limit.');
      }
      if (rows.length < 200) break;
      after = rows[rows.length - 1].item_id;
    }
    return publish(feed, job, client, categories, channels);
  }

  return async function live(feed, job, client) {
    let folder;
    try {
      if (job.last_hash !== 'live_pages') return await discover(feed, job, client);
      [folder] = await database(pool => q(pool,
        "SELECT * FROM vaultcast_stalker_folders WHERE feed_id=? AND kind=? AND generation=? AND status<>'complete' ORDER BY (status='failed'),next_run,folder_id LIMIT 1",
        scope(job)));
      if (!folder) return await finish(feed, job, client);
      if (folder.status === 'failed' || Number(folder.next_run) > now()) {
        await commit(job, connection => q(connection,
          'UPDATE vaultcast_stalker_jobs SET status=?,next_run=?,error=? WHERE feed_id=? AND kind=? AND generation=?',
          [folder.status === 'failed' ? 'failed' : 'waiting', folder.next_run, folder.error, ...scope(job)]));
        return;
      }
      await importPage(job, folder, client);
    } catch (error) {
      if (client.signal?.aborted) return;
      const failures = folder ? Number(folder.failures) + 1 : Math.max(1, Number(job.next_page));
      const failed = error.authentication || error.catalogue || (!error.retryAt && failures >= 8);
      const retryAt = error.retryAt ?? now() + Math.min(3600, 60 * 2 ** Math.min(6, failures - 1));
      const message = error.catalogue ? error.message : error.authentication ? 'The portal rejected this connection.' :
        error.retryAt ? 'Provider rate limit reached. Live TV import will resume after the cooldown.' :
        'Could not read the Live TV lineup. The previous lineup is still available.';
      await commit(job, async connection => {
        const scoped = folder && !error.authentication && (!error.retryAt || error.folderLimited);
        if (scoped) await q(connection,
          'UPDATE vaultcast_stalker_folders SET status=?,failures=?,next_run=?,error=? WHERE feed_id=? AND kind=? AND generation=? AND folder_id=?',
          [failed ? 'failed' : 'waiting', error.retryAt ? folder.failures : failures, retryAt, message, ...scope(job), folder.folder_id]);
        await q(connection,
          'UPDATE vaultcast_stalker_jobs SET status=?,next_page=?,next_run=?,error=? WHERE feed_id=? AND kind=? AND generation=?',
          [scoped ? 'partial' : failed ? 'failed' : 'waiting', failures + 1, scoped ? 0 : retryAt, message, ...scope(job)]);
      });
    }
  };
};
