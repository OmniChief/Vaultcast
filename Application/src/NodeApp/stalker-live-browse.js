'use strict';

const { stat } = require('fs').promises;
const { enabled, sha, parse } = require('./account-data');
const { take, snapshot, snapshotPath, liveKey, q, doc, bound, cache, provider, page: providerRows } = require('./stalker-store');
const { country, countries, genre } = require('./stalker-taxonomy');
const { cached } = require('./profile-policy');
const { configHash, phpJson } = require('./stalker-provider');

const { stableId, lineupGuide } = require('./stalker-lineup-guide');

const groups = [
  'General',
  'Entertainment',
  'Sports',
  'News',
  'Documentary',
  'Movies',
  'Kids',
  'Music',
  'Lifestyle',
  'Education',
  'Shopping',
  'Religious',
  'Adult',
  'Other',
];
const flag = (value) => ['1', 'true', 'yes', 'on'].includes(String(value).trim().toLowerCase());
const error = (message) => Object.assign(new Error(message), { status: 422 });
// Keep a small, byte-bounded cache for repeated channel, guide and player
// reads. Atomic snapshot replacements change the file identity immediately.
const loadingSnapshots = new Map();
const snapshots = new Map();
const indexes = new WeakMap();
let retainedBytes = 0;
async function liveSnapshot(root, feed) {
  const key = liveKey(feed, 'imported-live'), path = snapshotPath(root, key);
  const info = await stat(path).catch(() => null);
  const stamp = info && [info.ino, info.size, info.mtimeMs].join(':');
  const old = snapshots.get(path);
  if (old) {
    snapshots.delete(path);
    if (old.stamp === stamp) {
      snapshots.set(path, old);
      return old.data;
    }
    retainedBytes -= old.size;
  }
  if (!info) return null;
  const loading = loadingSnapshots.get(path);
  if (loading?.stamp === stamp) return loading.promise;
  const entry = { stamp };
  entry.promise = snapshot(root, key).then(data => {
    if (data && loadingSnapshots.get(path) === entry && info.size <= 16 * 1024 * 1024) {
      while (snapshots.size && (snapshots.size >= 4 || retainedBytes + info.size > 16 * 1024 * 1024)) {
        const [first, evicted] = snapshots.entries().next().value;
        retainedBytes -= evicted.size;
        snapshots.delete(first);
      }
      snapshots.set(path, { stamp, data, size: info.size });
      retainedBytes += info.size;
    }
    return data;
  }).finally(() => {
    if (loadingSnapshots.get(path) === entry) loadingSnapshots.delete(path);
  });
  loadingSnapshots.set(path, entry);
  return entry.promise;
}

function channelIndex(data) {
  let index = indexes.get(data);
  if (index) return index;
  const all = Object.values(data.channels).filter(playable).sort((a, b) =>
    (parseInt(a.number, 10) || 0) - (parseInt(b.number, 10) || 0) ||
    (String(a.id) < String(b.id) ? -1 : String(a.id) > String(b.id) ? 1 : 0));
  const folders = new Map();
  for (const row of all) {
    const id = String(row.tv_genre_id ?? '');
    if (!folders.has(id)) folders.set(id, []);
    folders.get(id).push(row);
  }
  index = { all, folders };
  indexes.set(data, index);
  return index;
}
function validChannel(row) {
  return (
    row?.id != null &&
    typeof row.id !== 'object' &&
    String(row.id) !== '' &&
    Buffer.byteLength(String(row.id)) <= 120 &&
    enabled(row.cmd)
  );
}

function playable(row) {
  return validChannel(row) && !/[#═━☰]{3,}/u.test(take(row.name ?? row.id, 300));
}

function present(rows, feed, selected, category, page, total, hasMore) {
  const items = [];
  for (const row of rows) {
    if (!playable(row)) continue;
    const id = String(row.id),
      name = take(row.name ?? id, 300);
    let xmltvId = String(row.xmltv_id ?? '').trim();
    if (Buffer.byteLength(xmltvId) > 160 || /[\/\\?&=\x00-\x1f]|https?:/i.test(xmltvId)) xmltvId = '';
    const number = /^\+?[1-9]\d*$/.test(String(row.number ?? '').trim()) ? Number(row.number) : 0;
    const code = country(name);
    items.push({
      id,
      name,
      description: selected.name,
      feed: feed.id,
      connection: feed.name,
      kind: 'live',
      category,
      page,
      country: code === 'ZZ' ? selected.country : code,
      number: number >= 1 && number <= 1000000 ? number : null,
      xmltvId,
      epgAvailable: Boolean(xmltvId),
      archive: flag(row.archive) && flag(row.enable_tv_archive),
      archiveHours: bound(row.tv_archive_duration, 0, 720),
      pvr: flag(row.allow_pvr),
      timeshift: flag(row.allow_local_timeshift),
      logoAvailable: false,
      logoUrl: '',
    });
  }
  return { ok: true, items, page, total, hasMore };
}

function channelPage(data, feed, opts) {
  const category = take(opts.category, 120);
  if (!category) throw error('Choose a provider category.');
  if (!data) return { ok: true, items: [], total: 0, hasMore: false, catalogueLoading: true };
  const selected = data.categories.find((row) => row.id === category);
  if (!selected) throw error('Unknown provider category.');
  const index = channelIndex(data);
  const rows = category === '*' ? index.all : index.folders.get(category) ?? [];
  const page = bound(opts.page, 1, 10000);
  return present(rows.slice((page - 1) * 60, page * 60), feed, selected, category, page,
    rows.length, page * 60 < rows.length);
}

// A full get_all_channels response is not supported by every Stalker portal. The
// importer may therefore have no snapshot even though individual pages work.
// Use short-lived, feed-scoped provider pages without exposing stream commands.
async function liveCategories(root, feed, signal, refresh = false) {
  const key = liveKey(feed, 'live-categories:v1');
  const saved = refresh ? null : await cached(root, key);
  if (Array.isArray(saved)) return saved;
  const client = await provider(root, feed, signal);
  const response = await client.call('itv', 'get_genres');
  if (!response || typeof response !== 'object' || enabled(response.error) ||
      response.success === false || response.status === false)
    throw new Error('Invalid Live TV categories.');
  const rows = response.data ?? response;
  if (!rows || typeof rows !== 'object') throw new Error('Invalid Live TV categories.');
  const categories = [];
  const seen = new Set();
  for (const row of Object.values(rows)) {
    if (!row || row.id == null || typeof row.id === 'object') continue;
    const id = String(row.id);
    if (!id || Buffer.byteLength(id) > 120 || /[\x00-\x1f\x7f]/.test(id) || seen.has(id)) continue;
    seen.add(id);
    const name = take(String(row.title ?? row.name ?? id).replace(/<[^>]*>/g, '').trim(), 160);
    categories.push({ id, name, country: country(name), genre: genre(name) });
    if (categories.length >= 1000) break;
  }
  if (!categories.length) categories.push({ id: '*', name: 'All channels', country: 'ZZ', genre: 'General' });
  await cache(root, key, categories, 600);
  return categories;
}

async function liveProviderPage(root, feed, opts, signal) {
  const category = take(opts.category, 120),
    number = bound(opts.page, 1, 10000);
  if (!category) throw error('Choose a provider category.');
  const categories = await liveCategories(root, feed, signal, enabled(opts.refresh));
  const selected = categories.find((row) => row.id === category);
  if (!selected) throw error('Unknown provider category.');
  const key = liveKey(feed, 'live-page:v1:' + category + ':' + number),
    sizeKey = liveKey(feed, 'live-page-size:v1:' + category);
  let saved = enabled(opts.refresh) ? null : await cached(root, key);
  if (!saved) {
    const client = await provider(root, feed, signal);
    const response = await client.ordered(
      'itv', { sortby: 'number', genre: category, search: '', fav: 0 }, number, providerRows,
    );
    const result = providerRows(response);
    saved = { rows: result.rows, total: result.total };
    await cache(root, key, saved, 600);
    if (number === 1 && result.rows.length) await cache(root, sizeKey, result.rows.length, 600);
  }
  const size = number === 1 ? saved.rows.length : await cached(root, sizeKey);
  const hasMore = saved.rows.length > 0 &&
    (saved.total == null || !size || number * size < saved.total);
  return { rows: saved.rows, selected, category, page: number, total: saved.total, hasMore };
}

async function channelPageFromProvider(root, feed, opts, signal) {
  const data = await liveProviderPage(root, feed, opts, signal);
  return present(data.rows, feed, data.selected, data.category, data.page,
    data.total, data.hasMore);
}

async function livePageEntry(root, feed, opts, id, signal) {
  const data = await liveProviderPage(root, feed, opts, signal);
  const row = data.rows.find((item) => playable(item) && String(item.id) === id);
  const linkFlag = (value) => /^(?:1|on|yes|true)$/i.test(String(value ?? '').trim())
    ? true : /^(?:0|off|no|false|)$/i.test(String(value ?? '').trim()) ? false : null;
  return row?.cmd ? {
    cmd: String(row.cmd),
    use_http_tmp_link: linkFlag(row.use_http_tmp_link),
    use_load_balancing: linkFlag(row.use_load_balancing),
  } : null;
}

async function byCountry(root, feeds, expired, opts) {
  const wanted = String(opts.country ?? '').toUpperCase();
  if (wanted && wanted !== 'ZZ' && !countries.has(wanted)) throw error('Choose a country.');
  const query = take(String(opts.q ?? '').trim(), 160).toLowerCase();
  const offset = bound(opts.offset, 0, 1000000);
  const counts = Object.fromEntries(groups.map((group) => [group, 0]));
  const connections = [],
    items = [];
  let total = 0;
  for (const feed of feeds) {
    const data = expired(feed.id) ? null : await liveSnapshot(root, feed);
    if (!data) {
      connections.push({
        name: feed.name,
        scanned: 0,
        total: null,
        done: false,
        error: 'No saved Live TV catalogue.',
      });
      continue;
    }
    let scanned = 0;
    const categories = new Map(data.categories.map((row) => [row.id, row.name]));
    for (const row of channelIndex(data).all) {
      scanned++;
      if (scanned % 512 === 0) await new Promise((resolve) => setImmediate(resolve));
      const id = String(row.id),
        name = take(row.name ?? id, 300);
      const description = take(row.group_title ?? categories.get(String(row.tv_genre_id ?? '')), 180);
      if (query && !(name + ' ' + description).toLowerCase().includes(query)) continue;
      let code = country(name);
      if (code === 'ZZ') code = country(description);
      if (wanted && code !== wanted) continue;
      const group = genre(name, description);
      counts[group]++;
      if (opts.group && group !== opts.group) continue;
      if (total >= offset && items.length < 120)
        items.push({
          id,
          name,
          description,
          kind: 'live',
          country: code,
          group,
          feed: feed.id,
          connection: feed.name,
        });
      total++;
    }
    connections.push({ name: feed.name, scanned, total: scanned, done: true, error: null });
  }
  return {
    ok: true,
    items,
    groups: counts,
    total,
    hasMore: offset + items.length < total,
    indexing: false,
    connections,
    shared: false,
  };
}
async function channelDetail(pool, root, feed, opts, expired) {
  const id = String(opts.id ?? '');
  const presentation = await cached(root, liveKey(feed, 'presentation:v3:' + id));
  const sources = (await doc(pool, 'stalker-catalogues')).sources;
  const source = sources?.[feed.id + '/live'];
  const generation =
    source?.config === configHash(feed) && source.active ? source.active : sha(phpJson(feed));
  let item = await cached(root, 'stalker_channel_v2_' + sha(phpJson([feed, id, generation])));
  if (!item) {
    const scan = await cached(root, 'stalker_live_search_v2_' + sha(phpJson(feed)));
    item = scan?.rows && Object.hasOwn(scan.rows, id) ? scan.rows[id] : null;
  }
  let data;
  const imported = async () =>
    data === undefined ? (data = expired ? null : await liveSnapshot(root, feed)) : data;
  if (!item) {
    const channels = (await imported())?.channels;
    const row = channels && Object.hasOwn(channels, id) ? channels[id] : null;
    if (row) item = { name: row.name ?? id };
    else {
      const rows = await q(
        pool,
        "SELECT payload FROM vaultcast_stalker_titles WHERE fingerprint=? AND kind='live' AND item_id=? ORDER BY playable DESC,parent_id LIMIT 1",
        [generation, id],
      );
      const facets = rows[0] ? parse(rows[0].payload).facets : null;
      if (facets) item = { name: facets.name, description: facets.providerGroup };
    }
  }
  const includeGuide = opts.guide !== '0';
  const programs = includeGuide
    ? (presentation?.programs ?? []).map((event) =>
        Object.hasOwn(event, 'poster') ? { ...event, poster: '' } : { ...event },
      )
    : [];
  let guideError = '';
  if (includeGuide && (!programs.length || enabled(opts.refresh))) {
    const key = 'stalker_channel_epg_' + sha(phpJson(feed));
    const saved = enabled(opts.refresh) ? null : await cached(root, key);
    const keyId = stableId(feed.id, id);
    if (saved?.__unavailable) guideError = 'The provider programme guide is currently unavailable.';
    const source = saved ?? lineupGuide((await imported())?.guide, feed.id, new Set([keyId]));
    const events = source[keyId] ?? [];
    for (const event of events) {
      if (!programs.some((known) => event.startUnix < known.endUnix && event.endUnix > known.startUnix))
        programs.push(Object.hasOwn(event, 'poster') ? { ...event, poster: '' } : event);
    }
  }
  const cutoff = Date.now() - 3600000;
  return {
    ok: true,
    channel: {
      id: 'stalker:' + feed.id + ':' + id,
      provider: 'stalker',
      kind: 'iptv',
      number: /^\d+$/.test(id) ? Number(id) : 0,
      name: presentation?.name ?? (item?.name || 'Channel ' + id),
      slogan: item?.description || feed.name,
      logo: '',
      programs: programs.filter((event) => event.endUnix > cutoff).sort((a, b) => a.startUnix - b.startUnix),
    },
    guideError,
  };
}
module.exports = { channelPage, channelPageFromProvider, liveCategories, livePageEntry, channelDetail, byCountry, liveSnapshot };
