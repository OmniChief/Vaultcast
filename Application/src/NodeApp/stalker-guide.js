'use strict';

const { open, stat } = require('fs').promises;
const { join } = require('path');
const { now, enabled } = require('./account-data');
const { cached } = require('./profile-policy');
const { take, liveKey, cache, snapshotPath, bound } = require('./stalker-store');
const { phpJson } = require('./stalker-provider');
const { channelPage, liveSnapshot } = require('./stalker-live-browse');

const regions = ['GB', 'GB-freeview', 'US'];
const maxIndex = 16 * 1024 * 1024;
const fields = [
  'cast',
  'director',
  'presenter',
  'categories',
  'rating',
  'ratingSystem',
  'episode',
  'language',
  'subtitles',
];
const get = (map, id) => (map && Object.hasOwn(map, id) ? map[id] : undefined);
const values = (obj) => (obj && typeof obj === 'object' ? Object.values(obj) : []);
const clean = (value, decode, limit) =>
  value == null || typeof value === 'object'
    ? ''
    : take(decode(String(value).replace(/<[^>]*>/g, '')).trim(), limit);
const label = (value) =>
  String(value ?? '')
    .trim()
    .toLowerCase();
const validTime = (start, end, time) =>
  end > start && end - start <= 86400 && end >= time - 3600 && start <= time + 172800;

function matchName(name, decode) {
  let text = clean(name, decode, 1000);
  for (let pass = 0; pass < 5; pass++) {
    text = text
      .replace(
        /^\s*(?:[|┃│¦]\s*(?:UK|GB|US|USA)\s*[|┃│¦]|\[\s*(?:UK|GB|US|USA)\s*\]|(?:UK|GB|US|USA)\s*[-:])\s*/iu,
        '',
      )
      .replace(
        /(?:\s+|\s*[-|]\s*|\s*\[)(?:UHD|FHD|FULL\s*HD|HD|SD|4K|2160P|1080P|720P|HEVC|H\.?265|H\.?264)\]?\s*$/iu,
        '',
      );
  }
  return label(text.replace(/\.uk$/iu, '').replace(/\s+/gu, ' '))
    .replace(
      /^bbc\s+(1|2|3|4)(?=\s|$)/u,
      (_match, digit) => 'bbc ' + ['one', 'two', 'three', 'four'][Number(digit) - 1],
    )
    .replace(/^itv\s+([1-4])(?=\s|$)/u, 'itv$1')
    .replace(/^4\s*seven(?=\s|$)/u, '4seven')
    .replace(/^(?:u\s*&\s*)?alibi(?=\s|$)/u, 'alibi')
    .replace(/^(?:channel\s*5|five)(?=\s|$)/u, '5')
    .replace(/^more\s*4(?=\s|$)/u, 'more4')
    .replace(/^film\s*4(?=\s|$)/u, 'film4')
    .replace(/\s*\+\s*1\b/gu, ' +1');
}

async function bytes(file, length, position) {
  const buf = Buffer.alloc(length);
  let offset = 0;
  while (offset < length) {
    const { bytesRead } = await file.read(buf, offset, length - offset, position + offset);
    if (!bytesRead) throw new Error('Incomplete EPG index.');
    offset += bytesRead;
  }
  return buf.toString('utf8');
}

// The PHP index writer appends a JSON directory followed by its 12-byte decimal offset.
// Hold one descriptor so an atomic replacement cannot mix offsets with a newer schedule.
async function lookupReader(path, prefix) {
  const file = await open(path + '.lookup', 'r').catch(() => null);
  if (!file) return null;
  try {
    const size = (await file.stat()).size;
    if (size < 13) throw new Error();
    const footer = await bytes(file, 12, size - 12);
    if (!/^\d{12}$/.test(footer)) throw new Error();
    const start = Number(footer),
      length = size - start - 12;
    if (length < 1 || length > 2 * 1024 * 1024) throw new Error();
    const header = JSON.parse(await bytes(file, length, start));
    const rev = /^\{"schema":2,"updatedAt":(\d+),/.exec(prefix);
    if (
      !rev ||
      Number(rev[1]) !== Number(header.updatedAt) ||
      !header.offsets ||
      !header.names ||
      !header.regional
    )
      throw new Error();
    const offsets = Object.entries(header.offsets).sort((a, b) => a[1] - b[1]),
      ranges = new Map(),
      loaded = new Map();
    for (let i = 0; i < offsets.length; i++) {
      const [id, position] = offsets[i],
        end = offsets[i + 1]?.[1] ?? start;
      if (
        !Number.isSafeInteger(position) ||
        position < 0 ||
        end <= position ||
        end > start ||
        end - position > maxIndex
      )
        throw new Error();
      ranges.set(id, [position, end - position]);
    }
    return {
      ...header,
      has: (id) => ranges.has(id),
      close: () => file.close(),
      async entries(id) {
        if (!ranges.has(id)) return [];
        if (!loaded.has(id)) {
          const [pos, len] = ranges.get(id);
          loaded.set(id, values(JSON.parse(await bytes(file, len, pos))));
        }
        return loaded.get(id);
      },
    };
  } catch {
    await file.close();
    return null;
  }
}

async function readIndex(path, decode) {
  const file = await open(path, 'r').catch(() => null);
  if (!file) return { status: 'missing' };
  try {
    const { size } = await file.stat();
    if (size > maxIndex) return { status: 'oversized' };
    const lookup = await lookupReader(path, await bytes(file, Math.min(80, size), 0));
    if (lookup) return lookup;
    const data = JSON.parse(await bytes(file, size, 0));
    if (!data.programmes || typeof data.programmes !== 'object') return { status: 'invalid' };
    const names = Object.create(null),
      regional = Object.create(null);
    for (const [id, channel] of Object.entries(data.channels ?? {})) {
      if (!Object.hasOwn(data.programmes, id)) continue;
      const name = matchName(channel, decode);
      if (!name) continue;
      (names[name] ??= Object.create(null))[id] = channel;
      if (/^itv1\s+/.test(name)) {
        const root = name.endsWith(' +1') ? 'itv1 +1' : 'itv1';
        if (root !== name) (regional[root] ??= Object.create(null))[id] = channel;
      }
    }
    return {
      updatedAt: data.updatedAt,
      names,
      regional,
      has: (id) => Object.hasOwn(data.programmes, id),
      entries: (id) => values(get(data.programmes, id)),
    };
  } catch {
    return { status: 'invalid' };
  } finally {
    await file.close();
  }
}

async function candidatesFor(index, channel, decode) {
  let source = channel.xmltvId;
  if (index.has(source)) return index.entries(source);
  const key = matchName(channel.name, decode);
  const exact = get(index.names, key),
    regional = exact ? null : get(index.regional, key);
  const candidates = Object.entries(exact ?? regional ?? {});
  if (candidates.length === 1) source = candidates[0][0];
  else if (/\b(?:UHD|4K|FHD|HD)\s*$/iu.test(channel.name)) {
    const hd = candidates.filter(([, name]) => /\b(?:UHD|4K|HD)\s*$/iu.test(name));
    if (hd.length === 1) source = hd[0][0];
  }
  if (source || !regional || candidates.length < 2) return index.entries(source);
  const votes = new Map();
  for (const [id] of candidates) {
    const seen = new Set();
    for (const event of await index.entries(id)) {
      if (!event) continue;
      const key = [event.start, event.end, label(event.title)].join('|');
      if (seen.has(key)) continue;
      seen.add(key);
      const vote = votes.get(key) ?? { event, count: 0 };
      vote.count++;
      votes.set(key, vote);
    }
  }
  return [...votes.values()]
    .filter((vote) => vote.count >= Math.ceil(candidates.length * 0.6))
    .map((vote) => vote.event);
}

function metadata(row, decode) {
  const out = {};
  for (const [field, keys] of Object.entries({
    cast: ['actors', 'cast'],
    director: ['director'],
    presenter: ['presenter'],
    categories: ['categories', 'genre', 'category'],
  })) {
    for (const key of keys) {
      const raw = row[key];
      const entries =
        raw && typeof raw === 'object' ? Object.values(raw) : clean(raw, decode, 1200).split(/\s*[,;|]\s*/);
      const names = entries
        .slice(0, 20)
        .map((e) => clean(e?.name ?? e, decode, 120))
        .filter(Boolean);
      if (names.length) {
        out[field] = [...new Set(names)];
        break;
      }
    }
  }
  for (const [field, keys] of Object.entries({
    rating: ['rating_mpaa', 'age'],
    episode: ['episode_title', 'episode'],
    language: ['language'],
  })) {
    for (const key of keys) {
      const value = clean(row[key], decode, 160);
      if (value) {
        out[field] = value;
        break;
      }
    }
  }
  return out;
}

function portalListings(guide, ids, time, decode) {
  const listings = new Map();
  let count = 0;
  for (const [key, rows] of Object.entries(guide ?? {})) {
    const events = values(rows);
    const channel = ids.has(key)
      ? key
      : String(events.find((row) => row && typeof row === 'object')?.ch_id ?? key);
    if (!ids.has(channel)) continue;
    for (const row of events) {
      if (!row) continue;
      const id = String(row.ch_id ?? channel),
        start = Math.trunc(Number(row.start_timestamp)),
        end = Math.trunc(Number(row.stop_timestamp));
      if (!ids.has(id) || start < 1e9 || !validTime(start, end, time)) continue;
      const title = take(
        String(row.name ?? row.title ?? '')
          .replace(/<[^>]*>/g, '')
          .trim(),
        180,
      );
      if (!title) continue;
      if (!listings.has(id)) listings.set(id, []);
      listings.get(id).push({
        ...metadata(row, decode),
        title,
        description: clean(row.descr ?? row.description, decode, 5000),
        start,
        end,
        source: 'provider',
      });
      if (++count >= 240) return { listings, count };
    }
  }
  return { listings, count };
}

function merge(entries, event, source, time) {
  const start = Math.trunc(Number(event?.start)),
    end = Math.trunc(Number(event?.end));
  if (!validTime(start, end, time)) return false;
  const title = String(event.title ?? '').trim();
  if (!title) return false;
  const existing = entries.find((e) => start < e.end && end > e.start);
  if (existing) {
    if (existing.start === start && label(existing.title) === label(title)) {
      for (const field of ['description', ...fields])
        if (!enabled(existing[field]) && enabled(event[field])) existing[field] = event[field];
    }
    return false;
  }
  entries.push({
    ...Object.fromEntries(fields.filter((f) => Object.hasOwn(event, f)).map((f) => [f, event[f]])),
    title: take(title, 180),
    description: take(event.description, 5000),
    start,
    end,
    source,
  });
  return true;
}

async function guidePage(root, feed, opts, expired, decode) {
  const category = String(opts.category ?? '');
  if (!/^\d{1,12}$/.test(category))
    throw Object.assign(new Error('Select a live TV category.'), { status: 422 });
  const page = bound(opts.page, 1, 10000),
    time = now();
  const loading = { ok: true, programmes: [], available: false, channelCount: 0, catalogueLoading: true };
  if (expired) return loading;
  const key = liveKey(feed, 'imported-live'),
    info = await stat(snapshotPath(root, key)).catch(() => null);
  if (!info) return loading;
  const pathFor = (region) =>
    join(root, 'data/live/epg_cache', 'stalker-open-' + region.toLowerCase() + '.json');
  const revisions = {};
  for (const region of regions)
    revisions[region.toLowerCase()] =
      Math.floor((await stat(pathFor(region)).catch(() => null))?.mtimeMs / 1000) || 0;
  // Keep a separate response key because artwork is stripped before caching the viewer payload.
  const cacheKey = liveKey(
    feed,
    'viewer-guide-v1:' + phpJson([[info.ino, info.mtimeMs, info.size], category, page, revisions]),
  );
  const hit = enabled(opts.refresh) ? null : await cached(root, cacheKey);
  if (hit) return hit;
  const data = await liveSnapshot(root, feed);
  if (!data) return loading;
  const visible = channelPage(data, feed, opts).items,
    ids = new Set(visible.map((item) => item.id));
  if (!ids.size) return { ok: true, programmes: [], available: false, channelCount: 0 };
  const { listings, count } = portalListings(data.guide, ids, time, decode),
    portal = new Set(listings.keys()),
    added = new Set();
  const openEpgIndexStatus = {};
  for (const region of regions) {
    const channels = visible.filter((c) => c.country === region.slice(0, 2));
    if (!channels.length) continue;
    const index = await readIndex(pathFor(region), decode);
    try {
      if (index.status || !(Number(index.updatedAt) > time - 86400)) {
        openEpgIndexStatus[region] = index.status ?? 'stale';
        continue;
      }
      openEpgIndexStatus[region] = 'ready';
      for (const channel of channels) {
        const entries = listings.get(channel.id) ?? [];
        for (const event of await candidatesFor(index, channel, decode)) {
          if (merge(entries, event, region === 'GB-freeview' ? 'freeview' : 'open-epg', time)) {
            added.add(channel.id);
            if (entries.length >= 60) break;
          }
        }
        if (entries.length) listings.set(channel.id, entries);
      }
    } finally {
      await index.close?.();
    }
  }
  const providerEpgUnavailable = !enabled(data.guide);
  if (providerEpgUnavailable && !listings.size)
    throw Object.assign(
      new Error('Provider EPG is unavailable and no matching cached Open EPG listings were found.'),
      { status: 502 },
    );
  for (const channel of visible) {
    const seen = new Set(),
      entries = [];
    for (const event of (listings.get(channel.id) ?? []).sort((a, b) => a.start - b.start)) {
      const signature = [event.start, event.end, event.title].join('|');
      if (seen.has(signature)) continue;
      seen.add(signature);
      entries.push(event);
      if (entries.length === 60) break;
    }
    if (entries.length) listings.set(channel.id, entries);
    const programs = entries.map((event) => ({
      ...event,
      startUnix: event.start * 1000,
      endUnix: event.end * 1000,
      subtitle: event.description,
      kind: 'epg',
    }));
    await cache(
      root,
      liveKey(feed, 'presentation:v3:' + channel.id),
      { name: channel.name, logo: '', programs },
      600,
    );
  }
  const result = {
    ok: true,
    programmes: listings.size ? Object.fromEntries(listings) : [],
    available: listings.size > 0,
    channelCount: ids.size,
    guideChannels: listings.size,
    limited: count >= 240,
    providerEpgUnavailable,
    providerMatchedChannels: portal.size,
    openEpgIndexStatus,
    channelLogos: [],
    openEpgMatchedChannels: added.size,
    openEpgAddedProgrammeChannels: [...added].filter((id) => !portal.has(id)).length,
  };
  await cache(root, cacheKey, result, 180);
  return result;
}
module.exports = { guidePage };
