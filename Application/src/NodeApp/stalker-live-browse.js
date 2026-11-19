'use strict';

const { enabled, sha, parse } = require('./account-data');
const { take, snapshot, liveKey, q, doc, bound } = require('./stalker-store');
const { country, countries, genre } = require('./stalker-taxonomy');
const { cached } = require('./profile-policy');
const { configHash, phpJson } = require('./stalker-provider');

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
const loadingSnapshots = new Map();
function liveSnapshot(root, feed) {
  const key = liveKey(feed, 'imported-live'),
    path = root + '/' + key;
  if (!loadingSnapshots.has(path)) {
    loadingSnapshots.set(
      path,
      snapshot(root, key).finally(() => loadingSnapshots.delete(path)),
    );
  }
  return loadingSnapshots.get(path);
}

function channels(data) {
  // JSON.parse enumerates numeric keys by ID. Restore the imported provider channel order.
  return Object.values(data.channels).sort(
    (a, b) =>
      (parseInt(a.number, 10) || 0) - (parseInt(b.number, 10) || 0) ||
      Buffer.compare(Buffer.from(String(a.id)), Buffer.from(String(b.id))),
  );
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

function channelPage(data, feed, opts) {
  const category = take(opts.category, 120);
  if (!category) throw error('Choose a provider category.');
  if (!data) return { ok: true, items: [], total: 0, hasMore: false, catalogueLoading: true };
  const selected = data.categories.find((row) => row.id === category);
  if (!selected) throw error('Unknown provider category.');
  const rows = channels(data).filter((row) => category === '*' || String(row.tv_genre_id ?? '') === category);
  const page = bound(opts.page, 1, 10000),
    items = [];
  for (const row of rows.slice((page - 1) * 60, page * 60)) {
    if (!validChannel(row)) continue;
    const id = String(row.id),
      name = take(row.name ?? id, 300);
    if (/[#═━☰]{3,}/u.test(name)) continue;
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
  return { ok: true, items, page, total: rows.length, hasMore: page * 60 < rows.length };
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
    for (const row of channels(data)) {
      if (!validChannel(row)) continue;
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
    const events = [];
    if (saved) {
      if (saved.__unavailable) guideError = 'The provider programme guide is currently unavailable.';
      const stableId = 'stalker-' + sha(feed.id + ':' + id).slice(0, 20);
      for (const event of saved[stableId] ?? []) events.push(event);
    } else {
      const guide = (await imported())?.guide ?? {};
      for (const [channel, rows] of Object.entries(guide)) {
        for (const row of Object.values(rows ?? {})) {
          if (!row || String(row.ch_id ?? channel) !== id) continue;
          const toMillis = (v) =>
            v != null && String(v).trim() && Number.isFinite(Number(v))
              ? Math.trunc(Number(v)) * 1000
              : Date.parse(String(v));
          const startUnix = toMillis(row.start_timestamp ?? row.time),
            endUnix = toMillis(row.stop_timestamp ?? row.time_to);
          if (!Number.isFinite(startUnix) || !Number.isFinite(endUnix) || !startUnix || endUnix <= startUnix)
            continue;
          events.push({
            startUnix,
            endUnix,
            title: String(row.name ?? row.title ?? ''),
            subtitle: String(row.descr ?? ''),
            kind: 'epg',
          });
        }
      }
    }
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
module.exports = { channelPage, channelDetail, byCountry, liveSnapshot };
