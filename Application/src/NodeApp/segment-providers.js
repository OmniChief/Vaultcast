'use strict';

const { randomBytes } = require('crypto');
const { mkdir, readFile, rename, unlink, writeFile } = require('fs').promises;
const { join } = require('path');
const { cached } = require('./profile-policy');
const { enabled, now, sha } = require('./account-data');

async function cacheResult(root, key, value, ttl) {
  const hash = sha(key);
  const directory = join(root, 'data/cache/kv', hash.slice(0, 2));
  const target = join(directory, hash + '.json');
  const temporary = target + '.' + randomBytes(8).toString('hex') + '.tmp';
  try {
    await mkdir(directory, { recursive: true, mode: 0o775 });
    await writeFile(temporary, JSON.stringify({ e: now() + ttl, v: value }), { flag: 'wx', mode: 0o664 });
    await rename(temporary, target);
  } catch (error) {
    await unlink(temporary).catch(() => {});
  }
}

async function providerJson(url) {
  let status = 0;
  try {
    const response = await fetch(url, {
      redirect: 'error', signal: AbortSignal.timeout(4000),
      headers: { 'User-Agent': 'Vaultcast/1.1.0', Accept: 'application/json' },
    });
    status = response.status;
    if (status !== 200) {
      await response.body?.cancel();
      return [status, null];
    }
    const reader = response.body.getReader();
    const chunks = [];
    let bytes = 0;
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      bytes += value.byteLength;
      if (bytes > 1048576) {
        await reader.cancel();
        return [status, null];
      }
      chunks.push(value);
    }
    return [status, JSON.parse(Buffer.concat(chunks, bytes).toString('utf8'))];
  } catch (error) {
    return [status, null];
  }
}

async function searchShows(root, query) {
  const key = 'segment-show-search:' + sha(query.replace(/[A-Z]/g, letter => letter.toLowerCase()));
  const previous = await cached(root, key);
  if (previous) return previous;
  const [status, data] = await providerJson('https://api.tvmaze.com/search/shows?q=' + encodeURIComponent(query));
  if (status !== 200 || !Array.isArray(data)) return null;
  const results = data.slice(0, 15).flatMap(row => {
    const show = row?.show;
    const external = show?.externals;
    if (!external?.thetvdb && !external?.imdb) return [];
    return [{ name: show.name ?? '', year: String(show.premiered ?? '').slice(0, 4),
      tvdb_id: external.thetvdb ?? null, imdb_id: external.imdb ?? null }];
  });
  const result = { ok: true, results };
  await cacheResult(root, key, result, 600);
  return result;
}

const imdb = value => typeof value === 'string' && /^tt[0-9]{7,10}$/.test(value) && !/\s/.test(value);
const number = value => typeof value === 'number' && Number.isFinite(value) ||
  typeof value === 'string' && /^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i.test(value.trim()) && Number.isFinite(Number(value));
const numericField = value => number(value) ? Number(value) : null;
const readJson = path => readFile(path, 'utf8').then(JSON.parse).catch(() => null);

async function identify(root, item, identity) {
  if (identity.showKey) {
    const guide = enabled(identity.guide?.episodes) ? identity.guide :
      await readJson(join(root, 'data/tvmaze', 'show_' + identity.showKey + '_episodes.json'));
    const id = identity.pin?.provider === 'none' ? 0 : Number(identity.pin?.tvmaze_id) > 0
      ? Math.trunc(Number(identity.pin.tvmaze_id)) : Number(guide?.id) || 0;
    let externals = id > 0 && id !== Number(guide?.id) ? {} : guide?.externals ?? {};
    if (!enabled(externals) && Number.isSafeInteger(id) && id > 0) {
      const key = 'skip-identity:' + id;
      let result = await cached(root, key);
      if (!result) {
        const [status, data] = await providerJson('https://api.tvmaze.com/shows/' + id);
        result = { externals: status === 200 ? data?.externals ?? {} : {} };
        await cacheResult(root, key, result, status === 200 ? 604800 : 300);
      }
      externals = result.externals ?? {};
    }
    return { tvmaze_id: id, externals };
  }

  const { override, poster, synopsis } = identity;
  const explicit = [item.imdb_id, override?.imdb_id, poster?.provider_id, synopsis?.provider_id].find(imdb);
  if (explicit) return { externals: { imdb: explicit } };
  const stored = await readJson(join(root, 'data/synopsis', 'movie_' + item.id + '.json'));
  const source = String(synopsis?.provider ?? stored?.source ?? '');
  if (source.startsWith('omdb:') && imdb(source.slice(5))) return { externals: { imdb: source.slice(5) } };
  let page = String(poster?.page_title ?? synopsis?.page_title ?? '');
  const wiki = 'https://en.wikipedia.org/wiki/';
  if (!page && source.startsWith(wiki)) {
    try { page = decodeURIComponent(source.slice(wiki.length)); } catch (error) { page = source.slice(wiki.length); }
  }
  if (!page || page.length > 1000 || page === '.' || page === '..') return { externals: {} };
  const key = 'segment-movie-id:' + sha(page);
  const previous = await cached(root, key);
  if (previous) return previous;
  const [status, summary] = await providerJson('https://en.wikipedia.org/api/rest_v1/page/summary/' + encodeURIComponent(page));
  let id;
  if (status === 200 && typeof summary?.wikibase_item === 'string' && /^Q\d{1,30}$/.test(summary.wikibase_item)) {
    const [, data] = await providerJson('https://www.wikidata.org/w/api.php?action=wbgetclaims&format=json&property=P345&entity=' + summary.wikibase_item);
    id = (Array.isArray(data?.claims?.P345) ? data.claims.P345 : [])
      .map(claim => claim?.mainsnak?.datavalue?.value).find(imdb);
  }
  const result = { externals: id ? { imdb: id } : {} };
  await cacheResult(root, key, result, id ? 604800 : 3600);
  return result;
}

async function preview(root, item, query, duration) {
  const out = { ok: true, segments: [], source: 'TheIntroDB', duration, lookup: query };
  const key = 'episode-segments-v5:' + sha(JSON.stringify([query, item.mtime ?? null, item.size ?? null]));
  const series = enabled(item.show);
  const [status, data] = await providerJson('https://api.theintrodb.org/v4/media?' + new URLSearchParams(query));
  let ttl = 86400;
  if (status !== 200 || !data || typeof data !== 'object') {
    out.http_status = status;
    out.message = status === 404 ? 'No timestamps found for this show / episode.' :
      status === 429 ? 'TheIntroDB rate limit reached; try again later.' : 'TheIntroDB lookup failed (HTTP ' + status + ').';
    if (status !== 404) ttl = 300;
  } else if (data.type !== (series ? 'tv' : 'movie') ||
      (series && (data.season != null && Number(data.season) !== query.season ||
      data.episode != null && Number(data.episode) !== query.episode))) {
    out.message = 'The provider returned a different media item or episode.';
  } else {
    const group = numericField(data.group_duration_ms);
    out.provider_duration = group === null ? null : group / 1000;
    out.tvdb_id = data.tvdb_id ?? null;
    if (group > 0 && Math.abs(group / 1000 - duration) > Math.max(15, duration * 0.02)) {
      out.message = 'Provider runtime differs from this file; timings were not applied. Check the show, episode order and release.';
    } else {
      for (const [field, kind] of series ? [['intro', 'intro'], ['credits', 'outro']] : [['credits', 'outro']]) {
        for (const row of (Array.isArray(data[field]) ? data[field] : []).slice(0, 8)) {
          const { start_ms: rawStart, end_ms: rawEnd } = row ?? {};
          if ((rawStart == null && kind === 'outro') || (rawEnd == null && kind === 'intro') ||
              (rawStart != null && !number(rawStart)) || (rawEnd != null && !number(rawEnd))) continue;
          const start = rawStart == null ? 0 : Number(rawStart) / 1000;
          const end = rawEnd == null ? duration : Number(rawEnd) / 1000;
          if (start < 0 || end <= start || end > duration + 1 || end - start > (kind === 'intro' ? 600 : 1800)) continue;
          out.segments.push({ kind, start, end: Math.min(duration, end), confidence: numericField(row.confidence),
            submission_count: row.submission_count == null || !number(row.submission_count) ? null : Math.trunc(Number(row.submission_count)) });
        }
      }
      out.segments.sort((a, b) => a.start - b.start);
      if (!out.segments.length) out.message = 'No usable intro or credits timestamps for this episode / release.';
      else {
        ttl = 604800;
        if (!group) out.message = 'Timestamps found, but provider runtime is unknown. Verify these timings against your file before saving.';
      }
    }
  }
  await cacheResult(root, key, out, ttl);
  return out;
}

module.exports = { identify, preview, searchShows };
