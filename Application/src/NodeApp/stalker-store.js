'use strict';

const { join } = require('path');
const { readFile, mkdir } = require('fs').promises;
const { tmpdir } = require('os');
const { now, sha, parse, documents, enabled, publicUser } = require('./account-data');
const { configHash, phpJson, atomicJson, StalkerProvider } = require('./stalker-provider');

const q = async (pool, sql, args = []) => (await pool.execute(sql, args))[0];
const doc = async (pool, name, fallback = {}) => (await documents(pool, [name]))[name] ?? fallback;
const save = (pool, name, value) =>
  q(
    pool,
    'INSERT INTO vaultcast_documents(name,payload) VALUES(?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),revision=revision+1',
    [name, JSON.stringify(value)],
  );
const pending = (status) => ['queued', 'running', 'partial', 'waiting'].includes(status);
const take = (value, length) =>
  Array.from(String(value ?? ''))
    .slice(0, length)
    .join('');
const snapshotPath = (root, key) => {
  const hash = sha(key);
  return join(root, 'data/stalker-catalogue', hash.slice(0, 2), hash + '.json');
};
const snapshot = (root, key) =>
  readFile(snapshotPath(root, key), 'utf8')
    .then(JSON.parse)
    .catch(() => null);
const saveSnapshot = (root, key, value) => atomicJson(snapshotPath(root, key), value);
const liveKey = (feed, suffix) => 'stalker_live_snapshot_' + sha(phpJson([configHash(feed), suffix]));
const episodeKey = (feed, suffix) => 'episodes:' + configHash(feed) + ':' + suffix;
const cache = (root, key, value, ttl) => {
  const hash = sha(key);
  return atomicJson(join(root, 'data/cache/kv', hash.slice(0, 2), hash + '.json'), {
    e: ttl ? now() + ttl : 0,
    v: value,
  });
};
async function transaction(pool, operation) {
  const connection = await pool.getConnection();
  let usable = true;
  try {
    await connection.beginTransaction();
    const value = await operation(connection);
    await connection.commit();
    return value;
  } catch (error) {
    try {
      await connection.rollback();
    } catch (failure) {
      usable = false;
      connection.destroy();
    }
    throw error;
  } finally {
    if (usable) connection.release();
  }
}
async function locked(pool, job) {
  const rows = await q(
    pool,
    'SELECT generation,status,config FROM vaultcast_stalker_jobs WHERE feed_id=? AND kind=? FOR UPDATE',
    [job.feed_id, job.kind],
  );
  if (rows[0]?.generation !== job.generation || rows[0].config !== job.config || !pending(rows[0].status))
    return false;
  const state = await doc(pool, 'live_iptv');
  const feed = state.feeds?.find(
    (feed) => feed.id === job.feed_id && feed.type === 'stalker' && enabled(feed.enabled),
  );
  return Boolean(feed && configHash(feed) === job.config);
}
async function provider(root, feed, signal) {
  await mkdir(join(tmpdir(), 'vaultcast_stalker'), { recursive: true, mode: 0o700 });
  const client = new StalkerProvider(
    root,
    feed,
    join(tmpdir(), 'vaultcast_stalker', sha(phpJson(feed)) + '.cookies'),
    signal,
  );
  client.shareSession = true;
  await client.loadCookies();
  return client;
}
async function inbox(pool, user, dedupe, title, body) {
  await q(
    pool,
    `INSERT INTO vaultcast_inbox(user_id,type,subtype,dedupe,actor,title,body,action,payload,created_at)
    VALUES (?,'system','stalker',?,'',?,?,?,'[]',?) ON DUPLICATE KEY UPDATE
    deleted=IF(VALUES(created_at)>created_at,0,deleted),read_at=IF(VALUES(created_at)>created_at,0,read_at),
    title=VALUES(title),body=VALUES(body),action=VALUES(action),created_at=GREATEST(created_at,VALUES(created_at))`,
    [
      String(user),
      take(dedupe, 120),
      take(title, 250),
      take(body, 600),
      JSON.stringify({ kind: 'settings', section: 'live-stalker' }),
      now(),
    ],
  );
}
async function notify(pool, feed, expired = false) {
  const settings = await doc(pool, 'settings'),
    roles = await documents(pool, ['permission_roles']);
  for (const row of await q(pool, 'SELECT payload FROM vaultcast_users')) {
    const user = parse(row.payload);
    if (!user?.id || !publicUser(user, settings, roles, now()).capabilities.iptv_manage) continue;
    await inbox(
      pool,
      user.id,
      'stalker-' + (expired ? 'expired' : 'scan-needed') + '-' + configHash(feed),
      expired ? 'Stalker provider expired' : 'Stalker catalogue needs a scan',
      feed.name +
        (expired
          ? ' expired. Scan its replacement once it is available.'
          : ' is ready to scan in Stalker settings.'),
    );
  }
}
async function queue(pool, root, feed, kind, reset = true) {
  const old = (
    await q(pool, 'SELECT * FROM vaultcast_stalker_jobs WHERE feed_id=? AND kind=? FOR UPDATE', [
      feed.id,
      kind,
    ])
  )[0];
  if (
    old?.status === 'complete' &&
    ((kind === 'series' && old.last_hash !== 'episodes_complete') ||
      (kind === 'live' && !(await snapshot(root, liveKey(feed, 'imported-live')))))
  )
    reset = true;
  if (!reset && old?.config === configHash(feed) && !['removed', 'scan_required'].includes(old.status)) {
    if (['paused', 'failed', 'error'].includes(old.status)) {
      await q(
        pool,
        "UPDATE vaultcast_stalker_folders SET status='pending',failures=0,next_run=0,error='' WHERE feed_id=? AND kind=? AND generation=? AND status NOT IN ('complete','skipped')",
        [feed.id, kind, old.generation],
      );
      await q(
        pool,
        "UPDATE vaultcast_stalker_jobs SET status='queued',next_page=1,next_run=?,error='' WHERE feed_id=? AND kind=?",
        [now(), feed.id, kind],
      );
    }
    return;
  }
  await q(
    pool,
    `INSERT INTO vaultcast_stalker_jobs(feed_id,kind,config,generation,status,next_page,processed,next_run)
    VALUES(?,?,?,?,'queued',1,0,?) ON DUPLICATE KEY UPDATE config=VALUES(config),generation=VALUES(generation),
    status='queued',next_page=1,processed=0,last_hash='',error='',next_run=VALUES(next_run)`,
    [feed.id, kind, configHash(feed), require('crypto').randomBytes(16).toString('hex'), now()],
  );
}
async function selected(pool, feed, kind) {
  const entry = (await doc(pool, 'stalker-catalogue-folder-selection')).sources?.[feed + '/' + kind];
  return entry?.mode === 'include'
    ? { mode: 'include', ids: Object.values(entry.ids ?? {}).filter((id) => typeof id === 'string') }
    : { mode: 'all', ids: [] };
}
const folderSelected = (selection, id) =>
  id === '' || selection.mode === 'all' || (id !== '*' && selection.ids.includes(id));
module.exports = {
  q,
  doc,
  save,
  pending,
  take,
  snapshot,
  saveSnapshot,
  liveKey,
  episodeKey,
  cache,
  transaction,
  locked,
  provider,
  inbox,
  notify,
  queue,
  selected,
  folderSelected,
  enabled,
};
