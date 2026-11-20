'use strict';

const { join } = require('path');
const { writeFile, unlink } = require('fs').promises;
const { randomInt } = require('crypto');
const { now, sha, enabled } = require('../NodeApp/account-data');
const { cached } = require('../NodeApp/profile-policy');
const { configHash, phpJson } = require('../NodeApp/stalker-provider');
const store = require('../NodeApp/stalker-store');
const {
  q,
  doc,
  save,
  pending,
  take,
  snapshot,
  saveSnapshot,
  liveKey,
  episodeKey,
  transaction,
  locked,
  provider,
  notify,
  queue,
  selected,
  folderSelected,
  groups,
  page,
} = store;
const { country, genre } = require('../NodeApp/stalker-taxonomy');
const libraryWriter = require('../NodeApp/stalker-library-write');

const key = (job) => [job.feed_id, job.kind, job.generation];
const scope = (job) => [job.feed_id, job.kind];
// A portal can have multiple MACs and jobs. Only one of them may scan that
// upstream at a time, while unrelated portals can make requests concurrently.
function portalKey(feed) {
  try {
    const url = new URL(feed.url);
    return url.origin + url.pathname.replace(/\/+$/, '').replace(/\/(?:c|portal\.php|server\/load\.php)$/, '');
  } catch (_) {
    return configHash(feed);
  }
}
module.exports = function catalogueWorker(db, root) {
  const library = libraryWriter(db.services().decode);
  const lastJob = new Map();
  // Keep MariaDB writes serialized on small NAS installations. Provider I/O
  // happens outside this queue, so it still runs across all distinct portals.
  let databaseTail = Promise.resolve();
  const database = (operation) => {
    const task = databaseTail.then(() => db.run(operation));
    databaseTail = task.catch(() => {});
    return task;
  };
  let lastMaintenance = 0,
    lastFilters = 0;
  async function commit(job, operation) {
    return database((pool) =>
      transaction(pool, async (connection) => {
        if (!(await locked(connection, job))) return false;
        await operation(connection);
        return true;
      }),
    );
  }
  async function deleteStaged(connection, job, rows) {
    if (rows.length)
      await q(
        connection,
        'DELETE FROM vaultcast_stalker_staging WHERE feed_id=? AND kind=? AND generation=? AND item_id IN (' +
          rows.map(() => '?').join(',') +
          ')',
        [...key(job), ...rows.map((row) => row.item_id)],
      );
  }
  async function step(feed, job, client) {
    let folder;
    try {
      const state = await database(async (pool) => {
        await q(
          pool,
          "INSERT IGNORE INTO vaultcast_stalker_folders(feed_id,kind,generation,folder_id,title) VALUES(?,?,?,'','Folder discovery')",
          key(job),
        );
        const staged = await q(
          pool,
          'SELECT item_id,category,payload FROM vaultcast_stalker_staging WHERE feed_id=? AND kind=? AND generation=? ORDER BY item_id LIMIT 50',
          key(job),
        );
        return {
          staged,
          folder: staged.length
            ? null
            : (
                await q(
                  pool,
                  "SELECT * FROM vaultcast_stalker_folders WHERE feed_id=? AND kind=? AND generation=? AND status NOT IN ('complete','skipped') ORDER BY folder_id='*',folder_id LIMIT 1",
                  key(job),
                )
              )[0],
        };
      });
      if (state.staged.length) {
        await commit(job, async (connection) => {
          await library.publish(connection, feed, job.kind, job.generation, state.staged);
          await deleteStaged(connection, job, state.staged);
          await q(
            connection,
            "UPDATE vaultcast_stalker_jobs SET status='partial',last_hash='publish',error='',next_run=? WHERE feed_id=? AND kind=?",
            [now(), ...scope(job)],
          );
        });
        return;
      }
      folder = state.folder;
      if (folder) {
        if (Number(folder.next_run) > now()) return;
        if (folder.folder_id === '') {
          const discovered = groups(await client.call(job.kind, 'get_categories'));
          await commit(job, async (connection) => {
            const selection = await selected(connection, feed.id, job.kind);
            for (const [id, title] of discovered)
              await q(
                connection,
                'INSERT IGNORE INTO vaultcast_stalker_folders(feed_id,kind,generation,folder_id,title,status) VALUES(?,?,?,?,?,?)',
                [...key(job), id, title, folderSelected(selection, id) ? 'pending' : 'skipped'],
              );
            await q(
              connection,
              "UPDATE vaultcast_stalker_folders SET status='complete',last_success=?,error='',failures=0 WHERE feed_id=? AND kind=? AND generation=? AND folder_id=''",
              [now(), ...key(job)],
            );
            await q(
              connection,
              "UPDATE vaultcast_stalker_jobs SET status='running',last_hash='scan',processed=0,error='',next_run=? WHERE feed_id=? AND kind=?",
              [now(), ...scope(job)],
            );
          });
          return;
        }
        if (Number(folder.next_page) > 10000) throw new Error('Folder page safety limit reached.');
        const result = page(
          await client.ordered(
            job.kind,
            { sortby: 'name', category: folder.folder_id, search: '' },
            Number(folder.next_page),
            page,
          ),
        );
        const rows = result.rows;
        let total = result.total;
        if (
          folder.total != null &&
          total != null &&
          Number(folder.total) !== total &&
          !(!rows.length && total === 0)
        )
          throw new Error('Provider folder total changed during enumeration. Refresh this connection.');
        total =
          !rows.length && folder.total != null
            ? Number(folder.total)
            : (total ?? (folder.total == null ? null : Number(folder.total)));
        const processed = Number(folder.processed) + rows.length;
        if (!rows.length && total != null && processed < total)
          throw new Error('Provider stopped before the advertised folder total.');
        if (!rows.length && processed === 0 && total !== 0)
          throw new Error('Empty folder without an explicit zero total; absence is unverified.');
        await commit(job, async (connection) => {
          let added = 0;
          for (let offset = 0; offset < rows.length; offset += 100) {
            const batch = rows.slice(offset, offset + 100);
            const result = await q(
              connection,
              'INSERT IGNORE INTO vaultcast_stalker_folder_items(feed_id,kind,generation,folder_id,item_id) VALUES ' +
                batch.map(() => '(?,?,?,?,?)').join(','),
              batch.flatMap((row) => [...key(job), folder.folder_id, String(row.id)]),
            );
            if (result.affectedRows !== batch.length)
              throw new Error('Overlapping provider folder pages; completeness is unverified.');
            let staged = [], bytes = 0;
            const flush = async () => {
              if (!staged.length) return;
              const result = await q(
                connection,
                'INSERT IGNORE INTO vaultcast_stalker_staging(feed_id,kind,generation,item_id,category,payload) VALUES ' +
                  staged.map(() => '(?,?,?,?,?,?)').join(','),
                staged.flat(),
              );
              added += result.affectedRows;
              staged = [];
              bytes = 0;
            };
            for (const row of batch) {
              const value = row.category_name ?? row.category_title ?? folder.title,
                category =
                  typeof value === 'object'
                    ? folder.title
                    : take(
                        String(value)
                          .replace(/<[^>]*>/g, '')
                          .trim(),
                        500,
                      );
              const payload = JSON.stringify(row);
              const size = Buffer.byteLength(payload);
              if (staged.length >= 40 || (staged.length && bytes + size > 256 * 1024)) await flush();
              staged.push([...key(job), String(row.id), category, payload]);
              bytes += size;
            }
            await flush();
          }
          await q(
            connection,
            "UPDATE vaultcast_stalker_folders SET status=?,next_page=next_page+1,processed=?,total=?,last_success=?,error='',failures=0,next_run=0 WHERE feed_id=? AND kind=? AND generation=? AND folder_id=?",
            [rows.length ? 'partial' : 'complete', processed, total, now(), ...key(job), folder.folder_id],
          );
          await q(
            connection,
            "UPDATE vaultcast_stalker_jobs SET status='partial',processed=processed+?,next_run=?,error='' WHERE feed_id=? AND kind=?",
            [added, now(), ...scope(job)],
          );
        });
        return;
      }
      if (job.kind === 'series' && (await episodes(feed, job, client))) return;
      let completed = false;
      await commit(job, async (connection) => {
        const staged = await q(
          connection,
          'SELECT item_id,category,payload FROM vaultcast_stalker_staging WHERE feed_id=? AND kind=? AND generation=? ORDER BY item_id LIMIT 50',
          key(job),
        );
        if (staged.length) {
          await library.publish(connection, feed, job.kind, job.generation, staged);
          await deleteStaged(connection, job, staged);
          await q(
            connection,
            "UPDATE vaultcast_stalker_jobs SET status='partial',last_hash='publish',next_page=1,error='',next_run=? WHERE feed_id=? AND kind=?",
            [now(), ...scope(job)],
          );
        } else if (
          (await selected(connection, feed.id, job.kind)).mode === 'include' ||
          (await library.reconcile(connection, job))
        ) {
          await q(
            connection,
            "UPDATE vaultcast_stalker_jobs SET status='complete',last_hash=?,error='',next_run=0 WHERE feed_id=? AND kind=?",
            [job.kind === 'series' ? 'episodes_complete' : 'complete', ...scope(job)],
          );
          completed = true;
        }
      });
      if (completed) await database((pool) => library.filters(pool, root, job.kind));
    } catch (error) {
      if (client.signal?.aborted) return;
      await commit(job, async (connection) => {
        const failures = folder ? Number(folder.failures) + 1 : Math.max(1, Number(job.next_page));
        const retryAt =
            error.retryAt ?? now() + Math.min(3600, 60 * 2 ** Math.min(6, failures - 1)) + randomInt(31),
          failed = !error.retryAt && (error.authentication || failures >= 8);
        let message = error.retryAt
          ? 'HTTP 429; connection requests paused. Checkpoint retained.'
          : error.authentication
            ? 'Authentication rejected; check this connection.'
            : 'Folder/page could not be verified. Checkpoint retained; refresh this connection if provider contents changed.';
        if (error.status >= 400 && error.status <= 599 && !error.retryAt)
          message = 'Provider HTTP ' + error.status + '; checkpoint retained.';
        if (
          /^(?:Invalid provider (?:folder response|folder|page response|item|total)|Unsupported provider folder ID|Repeated IDs in provider page|Folder page safety limit reached|Provider folder total changed during enumeration\. Refresh this connection|Provider stopped before the advertised folder total|Empty folder without an explicit zero total; absence is unverified|Overlapping provider folder pages; completeness is unverified)\.$/.test(
            error.message,
          )
        )
          message = error.message;
        if (folder)
          await q(
            connection,
            'UPDATE vaultcast_stalker_folders SET status=?,error=?,failures=?,next_run=? WHERE feed_id=? AND kind=? AND generation=? AND folder_id=?',
            [
              failed ? 'failed' : 'waiting',
              message,
              error.retryAt ? Number(folder.failures) : failures,
              retryAt,
              ...key(job),
              folder.folder_id,
            ],
          );
        await q(
          connection,
          'UPDATE vaultcast_stalker_jobs SET status=?,error=?,next_run=?,next_page=? WHERE feed_id=? AND kind=?',
          [
            failed ? 'failed' : 'waiting',
            message,
            retryAt,
            error.retryAt ? Number(job.next_page) : failures + 1,
            ...scope(job),
          ],
        );
        if (error.retryAt)
          await q(
            connection,
            "UPDATE vaultcast_stalker_jobs SET next_run=GREATEST(next_run,?) WHERE feed_id=? AND config=? AND status IN ('queued','running','partial','waiting')",
            [retryAt, job.feed_id, job.config],
          );
      });
    }
  }

  async function episodes(feed, job, client) {
    const documentKey = 'stalker-episode-import-' + sha(job.feed_id);
    let cursor = await database((pool) => doc(pool, documentKey));
    if (cursor.generation !== job.generation)
      cursor = {
        generation: job.generation,
        after: '',
        parent: '',
        page: 1,
        root: '',
        children: [],
        visited: [],
      };
    let parent = cursor.parent;
    if (!parent) {
      const rows = await database((pool) =>
        q(
          pool,
          "SELECT item_id FROM vaultcast_stalker_offers WHERE feed_id=? AND kind='series' AND item_id>? AND generation=? AND available=1 ORDER BY item_id LIMIT 1",
          [job.feed_id, cursor.after, job.generation],
        ),
      );
      if (!rows.length) return false;
      parent = rows[0].item_id;
      cursor.root = parent;
      cursor.children = [];
      cursor.visited = [String(parent)];
    }
    if (Number(cursor.page) > 10000) throw new Error('Episode page safety limit reached.');
    const pageKey = episodeKey(feed, 'page:' + phpJson([String(parent), Number(cursor.page)]));
    let result = await snapshot(root, pageKey),
      entries = [];
    if (result?.episodeGeneration !== job.generation) {
      const response = await client.ordered(
        'series',
        { sortby: 'name', category: '*', search: '', movie_id: parent },
        Number(cursor.page),
        page,
      );
      const rows = page(response).rows,
        signature = sha(phpJson(rows.map((row) => row.id)));
      if (rows.length && Number(cursor.page) > 1) {
        const previous = await snapshot(
          root,
          episodeKey(feed, 'page:' + phpJson([String(parent), Number(cursor.page) - 1])),
        );
        if (previous?.episodeGeneration === job.generation && previous.pageSignature === signature)
          throw new Error('Provider repeated an episode page.');
      }
      const items = [];
      const remember = (id, cmd, episode, item) =>
        entries.push([
          episodeKey(feed, 'item:' + id),
          { cmd, series: episode, metadata: item, episodeGeneration: job.generation, root: cursor.root },
        ]);
      for (const row of rows) {
        const id = String(row.id),
          meta = library.seriesMetadata(feed, row),
          cmd = typeof row.cmd === 'object' ? '' : String(row.cmd ?? ''),
          numbers = Array.isArray(row.series) ? row.series : [];
        if (parent && numbers.length && cmd) {
          for (const raw of numbers.slice(0, 200)) {
            if (raw == null || typeof raw === 'object') continue;
            const number = String(raw),
              publicId = 'series:' + id + ':' + number;
            const item = {
              ...meta,
              id: publicId,
              kind: 'vod',
              playable: true,
              feed: feed.id,
              connection: feed.name,
              name: meta.name + ' · Episode ' + number,
              episode: number,
              episodeTitle: '',
              seasonId: id,
            };
            const season = row.season_number ?? row.season;
            if (/^\d+$/.test(String(season ?? ''))) item.season = Number(season);
            item.seasonName = item.season != null ? 'Season ' + item.season : 'Season unknown';
            remember(publicId, cmd, number, item);
            items.push(item);
          }
          continue;
        }
        const playable = Boolean(cmd && !enabled(row.is_series) && !numbers.length && parent),
          publicId = playable ? 'series:' + id : id;
        const item = {
          ...meta,
          id: publicId,
          kind: 'series',
          playable,
          feed: feed.id,
          connection: feed.name,
          hasChildren: !playable && enabled(row.is_series),
          ...library.descriptive(row),
        };
        if (playable) remember(publicId, cmd, String(row.series_number ?? ''), item);
        items.push(item);
      }
      const reportedTotal = Number(response.total_items ?? 0),
        reportedSize = Number(response.max_page_items ?? 0),
        perPage = reportedSize > 0 ? reportedSize : Math.max(1, rows.length);
      const hasMore =
        reportedTotal > 0
          ? Number(cursor.page) * perPage < reportedTotal
          : rows.length > 0 && (reportedSize <= 0 || rows.length >= reportedSize);
      result = {
        items,
        total: reportedTotal > 0 ? reportedTotal : null,
        hasMore,
        episodeGeneration: job.generation,
        pageSignature: signature,
        root: cursor.root,
      };
    }
    for (const item of result.items) {
      const id = String(item.id);
      if (item.hasChildren && !cursor.visited.includes(id)) {
        cursor.visited.push(id);
        cursor.children.push(id);
      }
    }
    if (cursor.visited.length > 1000) throw new Error('Series folder safety limit reached.');
    await commit(job, async (connection) => {
      for (const [key, value] of entries) await saveSnapshot(root, key, value);
      await saveSnapshot(root, pageKey, result);
      if (result.hasMore) {
        cursor.parent = parent;
        cursor.page = Number(cursor.page) + 1;
      } else {
        cursor.parent = cursor.children.shift() ?? '';
        cursor.page = 1;
        if (!cursor.parent) cursor.after = cursor.root;
      }
      await save(connection, documentKey, cursor);
      await q(
        connection,
        "UPDATE vaultcast_stalker_jobs SET status='partial',last_hash='episodes',next_page=1,next_run=?,error='' WHERE feed_id=? AND kind='series'",
        [now(), job.feed_id],
      );
    });
    return true;
  }

  async function live(feed, job, client) {
    try {
      const response = await client.call('itv', 'get_genres'),
        rows = Object.values(response.data ?? response),
        categories = [];
      for (const row of rows) {
        if (
          !row ||
          row.id == null ||
          typeof row.id === 'object' ||
          !String(row.id) ||
          Buffer.byteLength(String(row.id)) > 120
        )
          continue;
        const name = take(row.title ?? row.name ?? row.id, 160);
        categories.push({ id: String(row.id), name, country: country(name), genre: genre(name) });
        if (categories.length >= 1000) break;
      }
      const bulk = await client.call('itv', 'get_all_channels'),
        raw = bulk.data ?? bulk;
      if (
        !raw ||
        typeof raw !== 'object' ||
        Object.keys(raw).length > 50000 ||
        Number(bulk.total_items ?? 0) > Object.keys(raw).length
      )
        throw new Error('Provider returned an incomplete or oversized Live TV list.');
      const members = new Map();
      for (const row of Object.values(raw))
        if (row && row.id != null && typeof row.id !== 'object' && enabled(row.cmd))
          members.set(String(row.id), row);
      if (!members.size) throw new Error('Provider returned no playable Live TV channels.');
      const order = [...members.keys()].sort(
        (a, b) =>
          Number(members.get(a).number ?? 0) - Number(members.get(b).number ?? 0) ||
          (a < b ? -1 : a > b ? 1 : 0),
      );
      const channels = new Proxy(Object.fromEntries(members), { ownKeys: () => order });
      let guide = {};
      try {
        const result = await client.call('itv', 'get_epg_info', { period: 48 });
        if (result && typeof (result.data ?? result) === 'object') guide = result.data ?? result;
      } catch (error) {
        if (error.retryAt || client.signal?.aborted) throw error;
      }
      await commit(job, async (connection) => {
        await saveSnapshot(root, liveKey(feed, 'imported-live'), {
          categories,
          channels,
          guide,
          imported: now(),
        });
        await q(
          connection,
          "UPDATE vaultcast_stalker_jobs SET status='complete',processed=?,error='',next_run=0 WHERE feed_id=? AND kind='live'",
          [members.size, feed.id],
        );
      });
    } catch (error) {
      if (client.signal?.aborted) return;
      await commit(job, async (connection) => {
        if (error.retryAt)
          await q(
            connection,
            "UPDATE vaultcast_stalker_jobs SET status='waiting',next_run=GREATEST(next_run,?),error=? WHERE feed_id=? AND config=? AND status IN ('queued','running','partial','waiting')",
            [
              error.retryAt,
              'Provider rate limited requests. Import will resume after the cooldown.',
              feed.id,
              job.config,
            ],
          );
        else
          await q(
            connection,
            "UPDATE vaultcast_stalker_jobs SET status='failed',error=?,next_run=0 WHERE feed_id=? AND kind='live'",
            ['Live TV could not be imported. Check the provider and retry.', feed.id],
          );
      });
    }
  }

  async function maintenance(_feeds, jobs) {
    await database(async (pool) => {
      for (const job of jobs)
        await transaction(pool, async (connection) => {
          const current = (
            await q(
              connection,
              'SELECT generation,config,status FROM vaultcast_stalker_jobs WHERE feed_id=? AND kind=? FOR UPDATE',
              scope(job),
            )
          )[0];
          if (current?.generation !== job.generation || current.config !== job.config) return;
          const state = await doc(connection, 'live_iptv');
          const feed = state.feeds?.find(
            (feed) => feed.id === job.feed_id && feed.type === 'stalker' && enabled(feed.enabled),
          );
          const account = feed ? await doc(connection, 'stalker-account-' + configHash(feed)) : {};
          if (feed && configHash(feed) !== job.config) {
            await q(
              connection,
              "UPDATE vaultcast_stalker_jobs SET status='scan_required',next_run=0 WHERE feed_id=? AND kind=?",
              scope(job),
            );
          } else if (!feed || (account.expires && Number(account.expires) <= now())) {
            const expired = Boolean(feed);
            if (expired && current.status !== 'expired') await notify(connection, feed, true);
            const records = await q(
              connection,
              'SELECT item_id,catalogue_id FROM vaultcast_stalker_offers WHERE feed_id=? AND kind=? AND available=1 LIMIT 100',
              scope(job),
            );
            const changed = new Set();
            for (const row of records) {
              await q(
                connection,
                'UPDATE vaultcast_stalker_offers SET available=0 WHERE feed_id=? AND kind=? AND item_id=?',
                [...scope(job), row.item_id],
              );
              changed.add(row.catalogue_id);
            }
            for (const id of changed) await library.aggregate(connection, id);
            await q(
              connection,
              'UPDATE vaultcast_stalker_jobs SET status=?,next_run=0 WHERE feed_id=? AND kind=?',
              [expired ? 'expired' : 'removed', ...scope(job)],
            );
          }
          for (const table of [
            'vaultcast_stalker_staging',
            'vaultcast_stalker_folder_items',
            'vaultcast_stalker_folders',
          ]) {
            const removed = !feed;
            await q(
              connection,
              'DELETE FROM ' +
                table +
                ' WHERE feed_id=? AND kind=?' +
                (removed ? '' : ' AND generation<>?') +
                ' LIMIT 100',
              removed ? scope(job) : key(job),
            );
          }
        });
      await library.refreshLocal(pool);
      const expired = await q(
        pool,
        "SELECT c.id FROM vaultcast_stalker_library c WHERE c.available=0 AND c.unavailable_since<=? AND NOT EXISTS(SELECT 1 FROM vaultcast_stalker_offers s JOIN vaultcast_stalker_jobs j ON j.feed_id=s.feed_id AND j.kind=s.kind WHERE s.catalogue_id=c.id AND j.status NOT IN ('complete','removed','expired')) LIMIT 100",
        [now() - 15 * 86400],
      );
      for (const { id } of expired)
        await transaction(pool, async (connection) => {
          const row = (
            await q(
              connection,
              'SELECT available,unavailable_since FROM vaultcast_stalker_library WHERE id=? FOR UPDATE',
              [id],
            )
          )[0];
          if (
            row &&
            !Number(row.available) &&
            row.unavailable_since != null &&
            Number(row.unavailable_since) <= now() - 15 * 86400
          ) {
            for (const table of [
              'vaultcast_stalker_offers',
              'vaultcast_stalker_facets',
              'vaultcast_stalker_aliases',
            ])
              await q(connection, 'DELETE FROM ' + table + ' WHERE catalogue_id=?', [id]);
            await q(connection, 'DELETE FROM vaultcast_stalker_library WHERE id=?', [id]);
          }
        });
    });
  }
  async function tick(signal) {
    const initial = await database(async (pool) => ({
      state: await doc(pool, 'live_iptv'),
      reset: await doc(pool, 'stalker-catalogue-reset', { state: 'idle' }),
      jobs: await q(pool, 'SELECT * FROM vaultcast_stalker_jobs'),
    }));
    const feeds = new Map(
      (initial.state.feeds ?? [])
        .filter((feed) => feed.type === 'stalker' && enabled(feed.enabled))
        .map((feed) => [feed.id, feed]),
    );
    if (initial.reset.state === 'purging') {
      await unlink(join(root, 'data/runtime/stalker-library.active')).catch(() => {});
      const tables = [
          'vaultcast_stalker_staging',
          'vaultcast_stalker_folder_items',
          'vaultcast_stalker_folders',
          'vaultcast_stalker_offers',
          'vaultcast_stalker_facets',
          'vaultcast_stalker_aliases',
          'vaultcast_stalker_library',
        ],
        index = Math.max(0, Math.trunc(Number(initial.reset.table) || 0));
      await database((pool) =>
        transaction(pool, async (connection) => {
          const reset = await doc(connection, 'stalker-catalogue-reset');
          if (reset.state !== 'purging' || Number(reset.table ?? 0) !== index) return;
          if (index < tables.length) {
            if (!(await q(connection, 'DELETE FROM ' + tables[index] + ' LIMIT 500')).affectedRows)
              await save(connection, 'stalker-catalogue-reset', { ...reset, table: index + 1 });
          } else {
            await q(connection, "DELETE FROM vaultcast_stalker_jobs WHERE kind IN ('vod','series')");
            for (const feed of feeds.values())
              for (const kind of ['vod', 'series']) await queue(connection, root, feed, kind, true);
            await save(connection, 'stalker-catalogue-reset', { state: 'complete', finished: now() });
          }
        }),
      );
      return { busy: true };
    }
    if (now() - lastMaintenance >= 60) {
      await maintenance(feeds, initial.jobs);
      lastMaintenance = now();
    }
    const jobs = await database((pool) =>
      q(
        pool,
        "SELECT * FROM vaultcast_stalker_jobs WHERE status IN ('queued','running','partial','waiting') AND next_run<=? ORDER BY next_run,feed_id,kind",
        [now()],
      ),
    );
    const lanes = new Map();
    for (const job of jobs) {
      const feed = feeds.get(job.feed_id);
      if (!feed || configHash(feed) !== job.config) continue;
      const lane = portalKey(feed);
      if (!lanes.has(lane)) lanes.set(lane, []);
      lanes.get(lane).push({ job, feed });
    }
    const selected = [];
    for (const [lane, options] of lanes) {
      const previous = lastJob.get(lane);
      const index = options.findIndex(({ job }) => key(job).join('\0') === previous);
      const choice = options[index < 0 ? 0 : (index + 1) % options.length];
      lastJob.set(lane, key(choice.job).join('\0'));
      selected.push(choice);
    }
    if (!selected.length) {
      await unlink(join(root, 'data/runtime/stalker-library.active')).catch(() => {});
      if (now() - lastFilters > 30) {
        await database(async (pool) => {
          for (const kind of ['vod', 'series'])
            if (!(await cached(root, 'stalker_library_filters_v3_' + kind)))
              await library.filters(pool, root, kind);
        });
        lastFilters = now();
      }
      return { busy: false };
    }
    // Multiple active tuples are understood by the Node status API. Each
    // provider appears at most once, even with several configured MACs.
    const active = selected.map(({ job }) => key(job));
    await writeFile(join(root, 'data/runtime/stalker-library.active'), JSON.stringify(active.length === 1 ? active[0] : active), {
      mode: 0o600,
    });
    await Promise.all(selected.map(async ({ job, feed }) => {
      try {
        await advance(feed, job, signal);
      } catch (error) {
        if (signal?.aborted) return;
        // An unexpected failure in one provider must not stop other providers
        // or busy-loop the same failed job on the next scheduler pass.
        await commit(job, (connection) => q(
          connection,
          "UPDATE vaultcast_stalker_jobs SET status='waiting',next_run=?,error=? WHERE feed_id=? AND kind=?",
          [now() + 60, 'Catalogue operation interrupted; retrying automatically.', ...scope(job)],
        ));
      }
    }));
    return { busy: true };
  }
  async function advance(feed, job, signal) {
    signal?.throwIfAborted();
    const cooldown = await cached(root, 'stalker_cooldown_' + sha(phpJson([feed.url, feed.mac])));
    if (Number(cooldown?.until) > now()) {
      await commit(job, (connection) =>
        q(
          connection,
          "UPDATE vaultcast_stalker_jobs SET next_run=GREATEST(next_run,?),status='waiting',error=? WHERE feed_id=? AND config=? AND status IN ('queued','running','partial','waiting')",
          [
            Number(cooldown.until),
            'Provider rate limited requests. Import will resume after the cooldown.',
            feed.id,
            job.config,
          ],
        ),
      );
      return;
    }
    const client = await provider(root, feed, signal),
      accountKey = 'stalker-account-' + configHash(feed);
    let account = await database((pool) => doc(pool, accountKey, null));
    if (account == null) {
      try {
        account = await client.accountExpiry();
      } catch (error) {
        if (error.retryAt) {
          await commit(job, (connection) =>
            q(
              connection,
              "UPDATE vaultcast_stalker_jobs SET status='waiting',next_run=?,error=? WHERE feed_id=? AND kind=?",
              [error.retryAt, 'Provider rate limited requests. Checkpoint retained.', ...scope(job)],
            ),
          );
          return;
        }
        throw error;
      }
      await database(async (pool) => {
        if ((await doc(pool, accountKey, null)) == null) await save(pool, accountKey, account);
      });
    }
    if (account.expires && Number(account.expires) <= now()) {
      await commit(job, async (connection) => {
        await notify(connection, feed, true);
        await q(
          connection,
          "UPDATE vaultcast_stalker_jobs SET status='expired',next_run=0 WHERE feed_id=? AND config=?",
          [feed.id, job.config],
        );
      });
      return;
    }
    if (job.kind === 'live') await live(feed, job, client);
    else await step(feed, job, client);
  }
  async function control(body) {
    const operation = String(body.operation ?? 'load'),
      kind = String(body.kind ?? 'all'),
      feedId = String(body.feed ?? '');
    const bad = (message) => Object.assign(new Error(message), { userError: true });
    if (!['load', 'refresh', 'pause', 'folders', 'discover', 'reset_rebuild'].includes(operation))
      throw bad('Unknown catalogue operation.');
    if (!['all', 'live', 'vod', 'series'].includes(kind))
      throw bad('Choose Live TV, Movies, Series, or all.');
    const initial = await database(async (pool) => ({
      state: await doc(pool, 'live_iptv'),
      reset: await doc(pool, 'stalker-catalogue-reset', { state: 'idle' }),
    }));
    if (initial.reset.state === 'purging')
      throw bad('Catalogue reset is in progress. Wait for it to finish.');
    let discovered;
    if (operation === 'discover') {
      const feed = initial.state.feeds?.find(
        (feed) => feed.type === 'stalker' && feed.id === feedId && enabled(feed.enabled),
      );
      if (!feed || !['vod', 'series'].includes(kind))
        throw bad('Choose one enabled connection and Movies or Series to discover folders.');
      const jobs = await database((pool) =>
        q(pool, 'SELECT status FROM vaultcast_stalker_jobs WHERE feed_id=? AND kind=?', [feedId, kind]),
      );
      if (pending(jobs[0]?.status))
        throw bad('Pause the current catalogue scan before rediscovering provider folders.');
      const client = await provider(root, feed, AbortSignal.timeout(120000));
      discovered = groups(await client.call(kind, 'get_categories'));
    }
    await database((pool) =>
      transaction(pool, async (connection) => {
        await q(
          connection,
          "SELECT name FROM vaultcast_documents WHERE name IN ('live_iptv','stalker-catalogue-reset','stalker-catalogue-folder-selection') ORDER BY name FOR UPDATE",
        );
        if ((await doc(connection, 'stalker-catalogue-reset')).state === 'purging')
          throw bad('Catalogue reset is in progress. Wait for it to finish.');
        const state = await doc(connection, 'live_iptv'),
          feeds = (state.feeds ?? []).filter((feed) => feed.type === 'stalker' && enabled(feed.enabled));
        if (operation === 'reset_rebuild') {
          if (feedId || kind !== 'all' || body.confirmation !== 'RESET STALKER CATALOGUE')
            throw bad('Confirm a full Stalker Movies and Series reset.');
          await q(
            connection,
            "UPDATE vaultcast_stalker_jobs SET status='paused',next_run=0 WHERE kind IN ('vod','series')",
          );
          await save(connection, 'stalker-catalogue-reset', { state: 'purging', table: 0, started: now() });
          return;
        }
        const chosen = feeds.filter((feed) => !feedId || feed.id === feedId);
        if (feedId && !chosen.length) throw bad('Unknown or disabled Stalker connection.');
        if (['folders', 'discover'].includes(operation)) {
          if (!feedId || !['vod', 'series'].includes(kind))
            throw bad('Choose one connection and Movies or Series.');
          const feed = chosen[0];
          if (operation === 'discover') {
            const old = initial.state.feeds.find((feed) => feed.id === feedId);
            if (configHash(old) !== configHash(feed))
              throw bad('The connection changed. Discover its folders again.');
            const jobs = await q(
              connection,
              'SELECT status FROM vaultcast_stalker_jobs WHERE feed_id=? AND kind=? FOR UPDATE',
              [feedId, kind],
            );
            if (pending(jobs[0]?.status))
              throw bad('Pause the current catalogue scan before rediscovering provider folders.');
            await queue(connection, root, feed, kind, true);
            const job = (
                await q(
                  connection,
                  'SELECT generation FROM vaultcast_stalker_jobs WHERE feed_id=? AND kind=?',
                  [feedId, kind],
                )
              )[0],
              selection = await selected(connection, feedId, kind);
            await q(
              connection,
              "INSERT INTO vaultcast_stalker_folders(feed_id,kind,generation,folder_id,title,status,last_success) VALUES(?,?,?,'','Folder discovery','complete',?)",
              [feedId, kind, job.generation, now()],
            );
            for (const [id, title] of discovered)
              await q(
                connection,
                'INSERT INTO vaultcast_stalker_folders(feed_id,kind,generation,folder_id,title,status,last_success) VALUES(?,?,?,?,?,?,NULL)',
                [
                  feedId,
                  kind,
                  job.generation,
                  id,
                  title,
                  folderSelected(selection, id) ? 'pending' : 'skipped',
                ],
              );
            await q(
              connection,
              "UPDATE vaultcast_stalker_jobs SET status='paused',last_hash='discovered',error='',next_run=? WHERE feed_id=? AND kind=?",
              [now(), feedId, kind],
            );
            return;
          }
          if (
            !['all', 'include'].includes(body.mode) ||
            !Array.isArray(body.folders) ||
            body.folders.length > 5000
          )
            throw bad('Invalid folder selection.');
          const ids = body.mode === 'all' ? [] : [...new Set(body.folders)];
          const previousJob = (
            await q(connection, 'SELECT config,generation,status,last_hash FROM vaultcast_stalker_jobs WHERE feed_id=? AND kind=? FOR UPDATE', [feedId, kind])
          )[0];
          const currentFolders = previousJob ? await q(connection,
            "SELECT folder_id FROM vaultcast_stalker_folders WHERE feed_id=? AND kind=? AND generation=? AND folder_id NOT IN ('','*')",
            [feedId, kind, previousJob.generation]) : [];
          // Prefer the current generation. Older installations may still have
          // a queued generation with no rows after an earlier selection edit.
          const knownFolders = currentFolders.length ? currentFolders : await q(connection,
            "SELECT DISTINCT folder_id FROM vaultcast_stalker_folders WHERE feed_id=? AND kind=? AND folder_id NOT IN ('','*')",
            [feedId, kind]);
          const allowed = new Set(knownFolders.map((row) => row.folder_id));
          if (ids.some((id) => typeof id !== 'string' || !allowed.has(id)))
            throw bad('Unknown provider folder. Reload the catalogue folder list.');
          const saved = await doc(connection, 'stalker-catalogue-folder-selection', { sources: {} });
          if (!saved.sources || Array.isArray(saved.sources)) saved.sources = {};
          const previous = saved.sources[feedId + '/' + kind];
          const previousIds = previous?.mode === 'include'
            ? Object.values(previous.ids ?? {}).filter((id) => typeof id === 'string') : [];
          const unchanged = previous && previousJob && previousJob.last_hash !== 'discovered' &&
            (previous.mode === 'include' ? 'include' : 'all') === body.mode &&
            previousIds.length === ids.length && ids.every((id) => previousIds.includes(id));
          saved.sources[feedId + '/' + kind] = { mode: body.mode, ids };
          await save(connection, 'stalker-catalogue-folder-selection', saved);
          // Saving an unchanged selection must not discard a running scan.
          if (unchanged) return;
          await queue(connection, root, feed, kind, true);
          // The generation change fences in-flight worker pages. Keep the
          // discovered folder names immediately available in the new generation
          // so an edit doesn't make the UI appear to forget its selection or
          // require another provider request before scanning can resume.
          if (previousJob?.config === configHash(feed)) {
            const readFolders = (after) => q(connection,
              "SELECT folder_id,title FROM vaultcast_stalker_folders WHERE feed_id=? AND kind=? AND generation=? AND folder_id>? ORDER BY folder_id LIMIT 100",
              [feedId, kind, previousJob.generation, after]);
            let folders = await readFolders('');
            if (!folders.length) return;
            const next = (
              await q(connection, 'SELECT generation FROM vaultcast_stalker_jobs WHERE feed_id=? AND kind=?', [feedId, kind])
            )[0];
            await q(connection,
              "INSERT INTO vaultcast_stalker_folders(feed_id,kind,generation,folder_id,title,status,last_success) VALUES(?,?,?,'','Folder discovery','complete',?)",
              [feedId, kind, next.generation, now()]);
            const included = new Set(ids);
            // MariaDB 10.3 on the NAS must not read from the table being
            // inserted into in the same INSERT ... SELECT statement. Keep
            // both the reads and the writes bounded for low-memory devices.
            while (folders.length) {
              const values = folders.map((folder) => [
                feedId, kind, next.generation, folder.folder_id, folder.title,
                body.mode === 'all' || (folder.folder_id !== '*' && included.has(folder.folder_id))
                  ? 'pending' : 'skipped',
              ]);
              await q(connection,
                'INSERT INTO vaultcast_stalker_folders(feed_id,kind,generation,folder_id,title,status) VALUES ' +
                  values.map(() => '(?,?,?,?,?,?)').join(','), values.flat());
              const cursor = folders[folders.length - 1].folder_id;
              folders = folders.length < 100 ? [] : await readFolders(cursor);
            }
          }
          return;
        }
        for (const feed of chosen)
          for (const type of kind === 'all' ? ['live', 'vod', 'series'] : [kind]) {
            await q(
              connection,
              'SELECT status FROM vaultcast_stalker_jobs WHERE feed_id=? AND kind=? FOR UPDATE',
              [feed.id, type],
            );
            if (operation === 'pause')
              await q(
                connection,
                "UPDATE vaultcast_stalker_jobs SET status='paused' WHERE feed_id=? AND kind=?",
                [feed.id, type],
              );
            else await queue(connection, root, feed, type, operation === 'refresh');
          }
      }),
    );
    await writeFile(join(root, 'data/runtime/stalker-library.enabled'), '1', { mode: 0o600 });
    return { ok: true };
  }
  return { tick, control };
};
module.exports.groups = groups;
