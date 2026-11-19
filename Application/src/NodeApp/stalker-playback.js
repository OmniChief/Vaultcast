'use strict';

const { randomBytes, createHmac } = require('crypto');
const { mkdir } = require('fs').promises;
const { join } = require('path');
const { tmpdir } = require('os');
const { isIP } = require('net');
const { pipeline } = require('stream/promises');
const { createGunzip, createInflate, createBrotliDecompress } = require('zlib');
const { now, parse, sha } = require('./account-data');
const { cached } = require('./profile-policy');
const { liveSnapshot } = require('./stalker-live-browse');
const { q, doc, cache, provider } = require('./stalker-store');
const {
  phpJson,
  configHash,
  itemEntry,
  StalkerProvider,
  mediaUrl,
  publicAddresses,
} = require('./stalker-provider');

const { playlist, resourceUrl } = require('./provider-playlist');

const legacyResourceSecret = randomBytes(32);
const playbackPath = '/netplay/application/live/stalker_stream';
const selections = new Map(),
  cookieWrites = new Map();
const lifetime = 21600;
let transfers = 0;
const problem = (status, message) => Object.assign(new Error(message), { status, expose: true });
const fingerprint = (feed) => sha(phpJson(feed));
const ticketKey = (token) => 'stalker_play_ticket_' + sha(token);
const flag = (value) =>
  /^(?:1|on|yes|true)$/i.test(String(value).trim())
    ? true
    : value != null && /^(?:0|off|no|false|)$/i.test(String(value).trim())
      ? false
      : null;

async function entry(pool, root, feed, id, kind, imported) {
  if (kind !== 'live') return itemEntry(pool, root, feed, id);
  const channels = (imported === undefined ? await liveSnapshot(root, feed) : imported)?.channels;
  let row = channels && Object.hasOwn(channels, id) ? channels[id] : null;
  if (!row) {
    const source = (await doc(pool, 'stalker-catalogues')).sources?.[feed.id + '/live'];
    const generation =
      source?.config === configHash(feed) && source.active ? source.active : fingerprint(feed);
    const rows = await q(
      pool,
      "SELECT payload FROM vaultcast_stalker_titles WHERE fingerprint=? AND kind='live' AND item_id=? ORDER BY playable DESC,parent_id LIMIT 1",
      [generation, id],
    );
    row = rows.length ? parse(rows[0].payload).row : null;
  }
  return row?.cmd
    ? {
        cmd: String(row.cmd),
        use_http_tmp_link: flag(row.use_http_tmp_link),
        use_load_balancing: flag(row.use_load_balancing),
      }
    : null;
}

async function resolve(client, kind, id, item) {
  if (!item?.cmd)
    throw problem(422, 'This entry is unavailable. Refresh the provider catalogue and select it again.');
  if (kind !== 'live') return { url: await client.resolve(id, item), linkMode: 'create_link' };
  const extract = (cmd) => /(?:^|\s)(https?:\/\/[^\s]+)/i.exec(cmd)?.[1];
  const direct = extract(item.cmd);
  if (direct && item.use_http_tmp_link === false && item.use_load_balancing === false) {
    let url;
    try {
      url = mediaUrl(direct);
    } catch {}
    if (url && !/^\/ch\//.test(url.pathname) && !/(?:^|\.)localhost$/i.test(url.hostname)) {
      try {
        await publicAddresses(url);
        return { url: url.href, linkMode: 'static' };
      } catch (error) {
        if (!isIP(url.hostname.replace(/^\[|\]$/g, ''))) throw error;
      }
    }
  }
  const params = { cmd: item.cmd, forced_storage: 'undefined', disable_ad: 0 };
  let link = (await client.call('itv', 'create_link', { ...params, series: '', download: 0 })).cmd;
  if (!link) {
    await client.session(true);
    link = (await client.call('itv', 'create_link', params)).cmd;
  }
  const url = mediaUrl(extract(link));
  await publicAddresses(url);
  return { url: url.href, linkMode: 'create_link' };
}

async function issue(root, feed, id, kind, item, viewer, signal, compatibility = false) {
  const client = await provider(root, feed, signal);
  const { url } = await resolve(client, kind, id, item);
  const ticket = randomBytes(16).toString('hex');
  const state = {
    viewer: String(viewer),
    feed: feed.id,
    fingerprint: fingerprint(feed),
    kind,
    id,
    url,
    created: now(),
    item,
    childKey: randomBytes(32).toString('hex'),
  };
  await cache(root, ticketKey(ticket), state, lifetime);
  const query = new URLSearchParams({ feed: feed.id, channel: id, ticket });
  const hls = kind === 'live'
    ? !compatibility || /\.m3u8$/i.test(new URL(url).pathname)
    : /\.m3u8(?:[?#]|$)/i.test(url);
  if (hls) query.set('format', '.m3u8');
  return { ok: true, source: { kind, url: playbackPath + '?' + query } };
}

async function authorizedTicket(root, feed, id, ticket, viewer) {
  if (typeof ticket !== 'string' || !/^[0-9a-f]{32}$/.test(ticket)) return null;
  const state = await cached(root, ticketKey(ticket));
  if (state?.viewer !== String(viewer) || state.feed !== feed.id || state.id !== id || state.fingerprint !== fingerprint(feed)) return null;
  if (!state.childKey) state.childKey = createHmac('sha256', legacyResourceSecret).update(ticket).digest('hex');
  return state;
}

async function playbackSession(root, feed, state, playback, signal) {
  const key = 'stalker_playback_v3_' + sha(phpJson([state.viewer, feed, state.id, playback]));
  let result = await cached(root, key);
  if (result) return result;
  if (selections.has(key)) return selections.get(key);
  const initialise = async () => {
    const client = await provider(root, feed, signal);
    const url =
      now() - state.created < 30 ? state.url : (await resolve(client, state.kind, state.id, state.item)).url;
    const jar = 'st' + sha(key).slice(0, 32);
    await mkdir(join(tmpdir(), 'iptv_hls_cj'), { recursive: true, mode: 0o700 });
    client.cookiePath = join(tmpdir(), 'iptv_hls_cj', jar + '.jar');
    await client.acceptCookies(mediaUrl(url), {});
    result = { url, jar };
    await cache(root, key, result, lifetime);
    return result;
  };
  const pending = initialise().finally(() => selections.delete(key));
  selections.set(key, pending);
  return pending;
}

async function follow(client, start, options, limit = 8) {
  let url = mediaUrl(start);
  for (let hops = 0; hops <= limit; hops++) {
    const res = await client.response(url.href, options);
    if (res.statusCode < 300 || res.statusCode >= 400 || !res.headers.location) return { res, url, hops };
    res.destroy();
    url = mediaUrl(new URL(res.headers.location, url).href);
  }
  throw problem(502, 'The provider redirected too many times.');
}
async function probe(root, feed, id, item, signal) {
  const client = await provider(root, feed, signal);
  const { url } = await resolve(client, 'live', id, item);
  client.cookiePath = null;
  let method = 'HEAD', code = 0;
  const started = Date.now();
  const deadline = AbortSignal.timeout(8000);
  try {
    let result = await follow(client, url, { method, signal: deadline });
    code = result.res.statusCode;
    result.res.destroy();
    if (code === 405 || code === 501) {
      method = 'GET';
      code = 0;
      result = await follow(client, url, { method, range: 'bytes=0-1023', signal: deadline });
      code = result.res.statusCode;
      try {
        // Some streams ignore ranges and continue indefinitely. Stop after the first data chunk.
        for await (const chunk of result.res) if (chunk.length) break;
      } finally {
        result.res.destroy();
      }
    }
    return { ok: code >= 200 && code < 400, code, ms: Date.now() - started, method, error: null };
  } catch {
    signal.throwIfAborted();
    return { ok: false, code, ms: Date.now() - started, method, error: 'Stream check failed.' };
  }
}

const browserAgent =
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
async function checkMedia(root, feed, id, kind, item, profile, signal) {
  if (!(kind === 'live' ? ['stb', 'http1', 'vlc', 'browser'] : ['stb', 'browser']).includes(profile))
    throw problem(422, 'Unknown playback check profile.');
  const client = await provider(root, feed, signal);
  const { url, linkMode } = await resolve(client, kind, id, item);
  client.cookiePath = null; // Keep diagnostic response cookies in this request only.
  const options = {
    accept: 'video/*, application/vnd.apple.mpegurl, application/x-mpegURL, */*;q=0.8',
    userAgent:
      profile === 'browser' ? browserAgent : profile === 'vlc' ? 'VLC/3.0.21 LibVLC/3.0.21' : undefined,
    referer: ['browser', 'vlc'].includes(profile) ? '' : undefined,
    range: profile === 'browser' ? 'bytes=0-8191' : undefined,
  };
  let result = await follow(client, url, { ...options, signal: AbortSignal.timeout(15000) }, 4);
  if (
    options.range &&
    (result.res.statusCode === 458 ||
      (result.res.statusCode === 400 && /^video\/mp4/i.test(result.res.headers['content-type'])))
  ) {
    result.res.destroy();
    result = await follow(
      client,
      result.url.href,
      { ...options, range: undefined, signal: AbortSignal.timeout(15000) },
      4,
    );
  }
  const { res } = result,
    chunks = [];
  let received = 0,
    failed = false;
  try {
    for await (const chunk of res) {
      const part = chunk.subarray(0, 8192 - received);
      chunks.push(part);
      received += part.length;
      if (received === 8192) break;
    }
  } catch {
    failed = true;
  } finally {
    res.destroy();
  }
  const data = Buffer.concat(chunks),
    type = String(res.headers['content-type'] ?? 'unknown')
      .split(';')[0]
      .toLowerCase();
  const http =
    res.statusCode === 400 && type === 'video/mp4' && data.subarray(0, 64).includes('ftyp')
      ? 200
      : res.statusCode;
  const format = data.toString('utf8').trimStart().startsWith('#EXTM3U')
    ? 'HLS playlist'
    : data[0] === 71 && data[188] === 71
      ? 'MPEG-TS stream'
      : type.startsWith('video/')
        ? 'Video stream'
        : type.includes('html')
          ? 'HTML page'
          : 'Unrecognised response';
  return {
    ok: true,
    http,
    method: 'GET',
    profile,
    linkMode,
    temporaryLink: item.use_http_tmp_link ?? null,
    loadBalancing: item.use_load_balancing ?? null,
    type,
    format,
    redirects: result.hops,
    https: result.url.protocol === 'https:',
    samePortalHost: result.url.hostname === new URL(client.endpoint).hostname,
    cloudflare: /cloudflare/i.test(data.toString()),
    allow: String(res.headers.allow ?? '').replace(/[^A-Z, ]/g, ''),
    received,
    timedOutOrFailed: failed && received < 8192,
  };
}

async function serve(root, feed, state, request, response) {
  const { ticket, resource } = request.query,
    playback = request.query.playback ?? ticket;
  if (typeof playback !== 'string' || !/^[\w-]{1,80}$/.test(playback))
    throw problem(422, 'Invalid playback session.');
  if (transfers >= 24) throw problem(503, 'The media relay is busy.');
  const controller = new AbortController(),
    close = () => controller.abort();
  response.once('close', close);
  transfers++;
  let res, decoder;
  try {
    const target = resource == null ? null : resourceUrl(resource, state.childKey, playback);
    const session = await playbackSession(root, feed, state, playback, controller.signal);
    if (!/^st[0-9a-f]{32}$/.test(session.jar)) throw problem(403, 'Invalid playback session.');
    const jar = join(tmpdir(), 'iptv_hls_cj', session.jar + '.jar');
    const client = new StalkerProvider(root, feed, jar, controller.signal);
    await client.loadCookies();
    // Parallel segments can rotate different cookies. Merge each response into the latest private jar.
    client.mediaCookies = (url, headers) => {
      const previous = cookieWrites.get(jar) ?? Promise.resolve();
      const update = previous
        .catch(() => {})
        .then(async () => {
          client.cookies = [];
          await client.loadCookies();
          await client.acceptCookies(url, headers);
        })
        .finally(() => {
          if (cookieWrites.get(jar) === update) cookieWrites.delete(jar);
        });
      cookieWrites.set(jar, update);
      return update;
    };
    const range = request.headers.range;
    if (range && !/^bytes=(?:\d+-\d*|-\d+)$/.test(range)) throw problem(416, 'Invalid byte range.');
    const options = { method: request.method, range, signal: controller.signal };
    let result = await follow(client, target ?? session.url, options);
    res = result.res;
    const mp4 = () => /^video\/mp4\b/i.test(res.headers['content-type'] ?? '');
    const retry =
      range &&
      ((res.statusCode === 400 && mp4()) ||
        (state.kind !== 'live' &&
          state.id.startsWith('series:') &&
          [456, 458].includes(res.statusCode) &&
          /^bytes=0-/.test(range)));
    if (retry) {
      res.destroy();
      result = await follow(client, target ?? session.url, { ...options, range: undefined });
      res = result.res;
    }
    const maybeMp4 = retry && res.statusCode === 400 && mp4() && request.method !== 'HEAD';
    if ((res.statusCode < 200 || res.statusCode >= 300) && !maybeMp4) {
      response.set({'X-Vaultcast-Error-Origin':'provider','X-Vaultcast-Upstream-Status':String(res.statusCode)});
      throw problem(
        res.statusCode >= 400 && res.statusCode <= 599 ? res.statusCode : 502,
        'Provider refused the media request.',
      );
    }
    const encoding = String(res.headers['content-encoding'] ?? 'identity').toLowerCase();
    const codecs = { gzip: createGunzip, deflate: createInflate, br: createBrotliDecompress };
    if (encoding !== 'identity' && !Object.hasOwn(codecs, encoding))
      throw problem(502, 'Unsupported media encoding.');
    if (request.method !== 'HEAD' && encoding !== 'identity') {
      decoder = codecs[encoding]();
      res.on('error', (err) => decoder.destroy(err));
      res.pipe(decoder);
    }
    const input = decoder ?? res,
      iterator = input[Symbol.asyncIterator](),
      chunks = [];
    let size = 0,
      isList = /mpegurl/i.test(res.headers['content-type'] ?? '') || /\.m3u8$/i.test(result.url.pathname);
    if (request.method !== 'HEAD') {
      while (size < 4096 && (!isList || maybeMp4)) {
        const { value, done } = await iterator.next();
        if (done) break;
        chunks.push(value);
        size += value.length;
        const prefix = Buffer.concat(chunks, size).subarray(0, 4096).toString().trimStart();
        if (maybeMp4 && size < 64) continue;
        if (prefix.startsWith('#EXTM3U')) {
          isList = true;
          break;
        }
        if (prefix && !'#EXTM3U'.startsWith(prefix)) break;
      }
      if (maybeMp4 && !Buffer.concat(chunks, size).subarray(0, 64).includes('ftyp'))
        throw problem(400, 'Provider refused the media request.');
    }
    const type = String(res.headers['content-type'] ?? '').split(';')[0];
    response.set({
      'Cache-Control': 'private, no-store',
      'X-Accel-Buffering': 'no',
      'Referrer-Policy': 'no-referrer',
      'Content-Security-Policy': "default-src 'none'; sandbox",
      'Content-Type': isList
        ? 'application/vnd.apple.mpegurl'
        : /^(?:video|audio)\/[\w.+-]+$/i.test(type) || type === 'text/vtt'
          ? type
          : 'application/octet-stream',
    });
    if (isList) {
      if (request.method === 'HEAD') return response.end();
      for (;;) {
        const { value, done } = await iterator.next();
        if (done) break;
        size += value.length;
        if (size > 5000000) throw problem(502, 'Provider playlist exceeds the size limit.');
        chunks.push(value);
      }
      const params = { feed: feed.id, channel: state.id, ticket, playback };
      return response.end(
        playlist(Buffer.concat(chunks, size).toString(), result.url, params, state.childKey, playback),
      );
    }
    response.status(maybeMp4 ? 200 : res.statusCode);
    for (const key of ['content-range', 'content-length', 'accept-ranges']) {
      if (
        res.headers[key] &&
        !(key === 'content-length' && encoding !== 'identity') &&
        !(key === 'content-range' && maybeMp4)
      )
        response.set(key, res.headers[key]);
    }
    if (request.method === 'HEAD') return response.end();
    async function* media() {
      yield* chunks;
      for (;;) {
        const { value, done } = await iterator.next();
        if (done) return;
        yield value;
      }
    }
    await pipeline(media(), response, { signal: controller.signal });
  } finally {
    decoder?.destroy();
    res?.destroy();
    response.removeListener('close', close);
    transfers--;
  }
}

module.exports = { entry, issue, serve, checkMedia, probe, authorizedTicket };
