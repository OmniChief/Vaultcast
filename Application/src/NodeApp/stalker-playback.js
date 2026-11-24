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
const sessionKey = (feed, state, playback) =>
  'stalker_playback_v3_' + sha(phpJson([state.viewer, feed, state.id, playback]));
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

async function issue(root, feed, id, kind, item, viewer, signal, compatibility = false, sessionHash) {
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
    ...(compatibility && /^[a-f0-9]{64}$/.test(sessionHash ?? '') ? { compatibilitySession: sessionHash } : {}),
  };
  await cache(root, ticketKey(ticket), state, lifetime);
  const query = new URLSearchParams({ feed: feed.id, channel: id, ticket });
  const pathname = new URL(url).pathname;
  const hls = kind === 'live'
    ? !compatibility || /\.m3u8$/i.test(pathname)
    : /\.m3u8$/i.test(pathname);
  if (hls) query.set('format', '.m3u8');
  else if (compatibility && kind === 'live') query.set('format', '.ts');
  else if (compatibility && /\.(?:mp4|m4v|webm|mkv|ts|m2ts|mts)$/i.test(pathname))
    query.set('format', /\.[a-z0-9]+$/i.exec(pathname)[0].toLowerCase());
  const transport = kind !== 'live' && /\.(?:ts|m2ts|mts|mpegts)$/i.test(pathname)
    ? 'mpegts' : undefined;
  return { ok: true, source: { kind, url: playbackPath + '?' + query, ...(transport ? { transport } : {}) } };
}

async function compatibilitySession(root, { ticket, feed, channel }) {
  if (typeof ticket !== 'string' || !/^[a-f0-9]{32}$/.test(ticket) ||
      typeof feed !== 'string' || typeof channel !== 'string') return null;
  const state = await cached(root, ticketKey(ticket));
  if (!state || state.feed !== feed || state.id !== channel ||
      !/^[a-f0-9]{64}$/.test(state.compatibilitySession ?? '') || typeof state.viewer !== 'string') return null;
  return { sessionHash: state.compatibilitySession, viewer: state.viewer };
}

async function authorizedTicket(root, feed, id, ticket, viewer) {
  if (typeof ticket !== 'string' || !/^[0-9a-f]{32}$/.test(ticket)) return null;
  const state = await cached(root, ticketKey(ticket));
  if (state?.viewer !== String(viewer) || state.feed !== feed.id || state.id !== id || state.fingerprint !== fingerprint(feed)) return null;
  if (!state.childKey) state.childKey = createHmac('sha256', legacyResourceSecret).update(ticket).digest('hex');
  return state;
}

async function playbackSession(root, feed, state, playback, signal) {
  const key = sessionKey(feed, state, playback);
  let result = await cached(root, key);
  if (result) return result;
  if (selections.has(key)) return selections.get(key);
  const initialise = async () => {
    const client = await provider(root, feed, signal);
    const url = now() - state.created < 30
      ? state.url : (await resolve(client, state.kind, state.id, state.item)).url;
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
    // A native player's preliminary HEAD must not consume a one-use live
    // provider URL. Validate the ticket first, then answer locally.
    const nativeLive = Boolean(state.compatibilitySession) && state.kind === 'live' && target == null;
    if (nativeLive && request.method === 'HEAD') {
      const path = new URL(state.url).pathname;
      return response.set('Cache-Control','private, no-store')
        .type(/\.m3u8$/i.test(path) ? 'application/vnd.apple.mpegurl' : 'video/mp2t').end();
    }
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
          await client.loadJar();
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
    // Live transport streams are not seekable. Some TVs send a byte-zero
    // range on first open; do not pass that probe to the live provider.
    const upstreamRange = nativeLive && /^bytes=0-/.test(range ?? '') ? undefined : range;
    const options = { method: request.method, range: upstreamRange, ifRange: upstreamRange ? request.headers['if-range'] : undefined, signal: controller.signal };
    let mediaOptions = options;
    let result = await follow(client, target ?? session.url, mediaOptions);
    res = result.res;
    const mp4 = () => /^video\/mp4\b/i.test(res.headers['content-type'] ?? '');
    const retry =
      range &&
      ((res.statusCode === 400 && mp4() && /^bytes=0-/.test(range)) ||
        (state.kind !== 'live' &&
          [456, 458].includes(res.statusCode) &&
          /^bytes=0-/.test(range)));
    if (retry) {
      res.destroy();
      mediaOptions = { ...options, range: undefined, ifRange: undefined };
      result = await follow(client, target ?? session.url, mediaOptions);
      res = result.res;
    }
    // Stalker VOD links can be one-use or expire before the browser opens them.
    // Recover one rejected root request with a fresh device session/create_link;
    // do not re-resolve HLS segments or repeatedly hammer a rejecting portal.
    if ((state.kind !== 'live' || nativeLive) && target == null && [456, 458].includes(res.statusCode) &&
        now() - Number(session.refreshedAt ?? 0) >= 60) {
      const denied = res.statusCode;
      session.refreshedAt = now();
      await cache(root, sessionKey(feed, state, playback), session, lifetime);
      res.destroy();
      try {
        await client.session(true);
        const renewed = (await resolve(client, state.kind, state.id, state.item)).url;
        result = await follow(client, renewed, mediaOptions);
        res = result.res;
        if (res.statusCode >= 200 && res.statusCode < 300) {
          session.url = renewed;
          await cache(root, sessionKey(feed, state, playback), session, lifetime);
        }
      } catch (error) {
        if (controller.signal.aborted) throw error;
        response.set({ 'X-Vaultcast-Error-Origin': 'provider', 'X-Vaultcast-Upstream-Status': String(denied) });
        throw problem(denied, 'The provider rejected the video link and could not renew it.');
      }
    }
    // Some portals label genuine MP4 bytes as HTTP 400 even without a Range header.
    // Accept that response only after verifying the MP4 file signature below.
    const maybeMp4 = res.statusCode === 400 && mp4() && request.method !== 'HEAD';
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
        if (prefix && !'#EXTM3U'.startsWith(prefix) && (size >= 377 || /^(?:video|audio)\//i.test(res.headers['content-type'] ?? ''))) break;
      }
      if (maybeMp4 && (size < 8 || Buffer.concat(chunks, size).toString('ascii', 4, 8) !== 'ftyp'))
        throw problem(400, 'Provider refused the media request.');
    }
    const type = String(res.headers['content-type'] ?? '').split(';')[0];
    const sample = Buffer.concat(chunks, size).subarray(0, 377);
    const isMp4 = sample.length >= 8 && sample.toString('ascii', 4, 8) === 'ftyp';
    const isTs = sample.length >= 377 && sample[0] === 0x47 && sample[188] === 0x47 && sample[376] === 0x47;
    const mediaType = isTs ? 'video/mp2t' : isMp4 && !/^video\//i.test(type) ? 'video/mp4'
      : request.method === 'HEAD' && !/^(?:video|audio)\//i.test(type) && /\.(?:ts|m2ts|mts)$/i.test(result.url.pathname) ? 'video/mp2t' : type;
    response.set({
      'Cache-Control': 'private, no-store',
      'X-Accel-Buffering': 'no',
      'Referrer-Policy': 'no-referrer',
      'Content-Security-Policy': "default-src 'none'; sandbox",
      'Content-Type': isList
        ? 'application/vnd.apple.mpegurl'
        : /^(?:video|audio)\/[\w.+-]+$/i.test(mediaType) || mediaType === 'text/vtt'
          ? mediaType
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
    for (const key of ['content-range', 'content-length', 'accept-ranges', 'etag', 'last-modified']) {
      if (
        res.headers[key] &&
        !(key === 'content-length' && encoding !== 'identity' && request.method !== 'HEAD') &&
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

module.exports = { entry, issue, serve, checkMedia, probe, authorizedTicket, compatibilitySession };
