'use strict';

const { randomBytes, createHmac } = require('crypto');
const { mkdir, open } = require('fs').promises;
const { join } = require('path');
const { pipeline } = require('stream/promises');
const { createGunzip, createInflate, createBrotliDecompress } = require('zlib');
const { lockFile } = require('../Workers/file-lock');
const { providerResponse, xtreamCurrent, closeXtreamUpstream } = require('../Workers/media-relay');
const { now, sha, enabled, documents, publicUser } = require('./account-data');
const { cached, authorized, parentalStatus } = require('./profile-policy');
const { areaAllowed } = require('./library');
const { cache, doc, save } = require('./stalker-store');
const { mediaUrl, publicAddresses, providerBody, phpJson } = require('./stalker-provider');
const ProviderCookies = require('./provider-cookies');
const { playlist, resourceUrl } = require('./provider-playlist');
const providerImport = require('./xtream-import');

const streamPath = '/netplay/application/live/xtream_stream';
const fail = (status, message) => Object.assign(new Error(message), { status, expose: true });
const objects = value => Object.values(value ?? {}).filter(row => row && typeof row === 'object');

function accountPath(root, feed) {
  const url = mediaUrl(feed.url);
  const account = sha(phpJson([url.hostname.toLowerCase(), Number(url.port || (url.protocol === 'https:' ? 443 : 80)), feed.username]));
  return join(root, 'data/runtime/xtream', account);
}

async function changeSession(base, change) {
  // Serialize updates on the account session inode shared with the connection manager.
  let file;
  try {
    file = await open(base + '.session', 'r+');
  } catch (error) {
    if (error.code !== 'ENOENT') throw error;
    try { file = await open(base + '.session', 'wx+', 0o600); }
    catch (other) { if (other.code !== 'EEXIST') throw other; file = await open(base + '.session', 'r+'); }
  }
  try {
    await lockFile(file);
    const state = JSON.parse((await file.readFile('utf8')) || '{}');
    const next = await change(state);
    if (next) {
      const data = Buffer.from(JSON.stringify(next));
      let offset = 0;
      while (offset < data.length) {
        const { bytesWritten } = await file.write(data, offset, data.length - offset, offset);
        if (!bytesWritten) throw new Error('Could not persist playback session.');
        offset += bytesWritten;
      }
      await file.truncate(data.length);
    }
  } finally { await file.close(); }
}

async function upstream(address, { signal, jar, range }) {
  let url = mediaUrl(address);
  for (let redirects = 0; redirects < 6; redirects++) {
    const [ip] = await publicAddresses(url);
    signal.throwIfAborted();
    const res = await providerResponse({ ip, ua: 'Vaultcast', purpose: jar ? 'media' : 'metadata',
      xtream: true, cookies: jar?.cookieHeader(url), range }, url, 'GET', signal);
    try {
      if (jar && res.headers['set-cookie']) await jar.acceptCookies(url, res.headers);
      if (!(res.statusCode >= 300 && res.statusCode < 400 && res.headers.location)) return { res, url };
      url = mediaUrl(new URL(res.headers.location, url).href);
    } catch (error) {
      await closeXtreamUpstream(res);
      throw error;
    }
    await closeXtreamUpstream(res);
  }
  throw fail(502, 'The provider redirected too many times.');
}

async function api(root, feed, action, params, refresh, signal) {
  const key = 'xtream-api:' + sha(phpJson([feed, action, Object.keys(params).length ? params : []]));
  if (action && !refresh) {
    const hit = await cached(root, key);
    if (hit && typeof hit === 'object') return hit;
  }
  const query = new URLSearchParams({ username: feed.username, password: feed.password, ...(action && { action }), ...params });
  const { res } = await upstream(feed.url.replace(/\/$/, '') + '/player_api.php?' + query, { signal });
  try {
    if (res.statusCode !== 200) throw fail(502, 'Provider API returned HTTP ' + res.statusCode + '.');
    const data = JSON.parse((await providerBody(res, 32 * 1024 * 1024)).toString('utf8'));
    if (!data || typeof data !== 'object') throw fail(502, 'Provider returned an invalid catalogue.');
    if (data.user_info && Number(data.user_info.auth) !== 1) throw fail(502, 'Provider rejected the saved account.');
    if (action) await cache(root, key, data, 300).catch(() => {});
    return data;
  } finally { await closeXtreamUpstream(res); }
}

async function checkAccount(root, feed, signal, includeCreated = false) {
  const { user_info: account } = await api(root, feed, '', {}, true, signal);
  if (Number(account?.auth) !== 1) throw fail(502, 'Provider rejected the account.');
  const fields = ['status', 'exp_date', 'active_cons', 'max_connections'];
  if (includeCreated) fields.push('created_at');
  return Object.fromEntries(fields.filter(key => Object.hasOwn(account, key)).map(key => [key, account[key]]));
}

async function withConfigurationLock(root, signal, operation) {
  const dir = join(root, 'data/runtime/xtream');
  await mkdir(dir, { recursive: true, mode: 0o700 });
  const lock = await open(join(dir, 'configuration.lock'), 'a', 0o600);
  try {
    try { await lockFile(lock, 3, signal); }
    catch { throw fail(409, 'Connection settings are being saved. Please try again.'); }
    return await operation();
  } finally { await lock.close(); }
}

async function manage(root, pool, input, signal) {
  const { operation = 'save', feed: id = '' } = input;
  return withConfigurationLock(root, signal, async () => {
    const stored = await doc(pool, 'xtream-connections');
    const feeds = new Map(objects(stored.connections).map(feed => [feed.id, feed]));
    const previous = feeds.get(id);
    if (id && !previous) throw fail(404, 'Connection no longer exists.');
    if (operation === 'check') {
      if (!previous) throw fail(422, 'Save the connection first.');
      return { ok: true, account: await checkAccount(root, previous, signal) };
    }
    if (operation === 'remove' && previous) feeds.delete(id);
    else if (operation === 'save') {
      const value = input.connection ?? {};
      const name = String(value.name ?? '').trim();
      if (!name || Buffer.byteLength(name) > 160) throw fail(422, 'Enter a connection name of up to 160 characters.');
      let url;
      try {
        url = mediaUrl(String(value.url ?? '').trim().replace(/\/+$/, '').replace(/\/player_api\.php$/i, ''));
        if (url.search || url.hash) throw new Error();
        await publicAddresses(url);
      } catch { throw fail(422, 'Enter a public HTTP or HTTPS provider address.'); }
      const feed = { id: id || 'xtream-' + randomBytes(6).toString('hex'), name, url: url.href.replace(/\/$/, ''), enabled: enabled(value.enabled) };
      for (const key of ['username','password']) {
        const text = value[key] || previous?.[key];
        if (typeof text !== 'string' || !text || Buffer.byteLength(text) > 512 || /[\r\n\0]/.test(text))
          throw fail(422, 'Enter a valid username and password.');
        feed[key] = text;
      }
      feeds.set(feed.id, feed);
    } else throw fail(422, 'Unknown connection operation.');
    if (previous) await changeSession(accountPath(root, previous), state => ({ ...state, expires: 0 }));
    await save(pool, 'xtream-connections', { connections: Object.fromEntries(feeds) });
    return { ok: true };
  });
}

async function importPage(url, signal) {
  let res;
  try {
    ({ res } = await upstream(url, { signal }));
    if (res.statusCode !== 200) throw fail(502, 'Provider page returned HTTP ' + res.statusCode + '.');
    return (await providerBody(res, 2 * 1024 * 1024)).toString('utf8');
  } catch (error) {
    if (error.expose) throw error;
    throw fail(502, 'Could not fetch the provider page. Check its URL and whether it requires a browser login.');
  } finally { await closeXtreamUpstream(res); }
}

function item(row, kind) {
  return { id: String(row.stream_id ?? row.series_id ?? row.id ?? ''), name: String(row.name ?? row.title ?? 'Untitled'),
    kind, category: String(row.category_id ?? ''), season: Number(row.season) || 0, episode: Number(row.episode_num) || 0 };
}

async function catalogue(root, feed, input, action, viewer, signal, base) {
  const kind = input.kind ?? 'live';
  if (!['live', 'series', 'vod'].includes(kind)) throw fail(422, 'Invalid library type.');
  const fetchApi = (method, params = {}) => api(root, feed, method, params, enabled(input.refresh), signal);
  if (action === 'xtream_guide') {
    if (!/^\d+$/.test(input.id)) throw fail(422, 'Invalid channel.');
    const data = await fetchApi('get_short_epg', { stream_id: input.id, limit: 8 });
    const decode = (value, fallback) => /^[A-Za-z0-9+/]*={0,2}$/.test(value ?? '') ?
      Buffer.from(value ?? '', 'base64').toString('utf8') : fallback;
    return { ok: true, programmes: objects(data.epg_listings).map(row => ({ title: decode(row.title, String(row.title ?? '')),
      description: decode(row.description, ''), start: String(row.start ?? ''), end: String(row.end ?? '') })) };
  }
  if (action === 'xtream_categories') {
    const rows = await fetchApi('get_' + kind + '_categories');
    return { ok: true, categories: objects(rows).map(row => ({ id: String(row.category_id ?? ''), name: String(row.category_name ?? 'Other') })) };
  }
  let rows;
  if (input.series) {
    if (kind !== 'series' || !/^\d+$/.test(input.series)) throw fail(422, 'Invalid series.');
    rows = objects((await fetchApi('get_series_info', { series_id: input.series })).episodes).flatMap(objects);
  } else {
    rows = objects(await fetchApi(kind === 'series' ? 'get_series' : 'get_' + kind + '_streams',
      input.category ? { category_id: input.category } : {}));
  }
  if (action === 'xtream_browse') {
    const page = Math.max(1, parseInt(input.page, 10) || 1);
    const search = String(input.q ?? '').trim().toLowerCase();
    const items = [], start = (page - 1) * 60;
    let total = 0;
    for (const row of rows) {
      const entry = item(row, kind);
      if (search && !entry.name.toLowerCase().includes(search)) continue;
      if (total >= start && items.length < 60) items.push(entry);
      total++;
    }
    return { ok: true, items, page, total };
  }
  if (kind === 'series' && !input.series) throw fail(422, 'Select an episode.');
  const row = rows.find(row => item(row, kind).id === String(input.id));
  if (!row) throw fail(422, 'Title no longer available. Refresh the library.');
  const selected = item(row, kind);
  const ext = kind === 'live' ? 'm3u8' : String(row.container_extension ?? 'mp4');
  if (!/^\d+$/.test(selected.id) || !/^[a-z0-9]{1,8}$/i.test(ext)) throw fail(502, 'Provider returned an invalid stream ID or format.');
  const { client } = input, sequence = Number(input.sequence);
  if (typeof client !== 'string' || !/^[\w-]{16,80}$/.test(client) || !Number.isSafeInteger(sequence) || sequence < 1) throw fail(422, 'Reload Xtream Codes before playing.');
  const url = feed.url.replace(/\/$/, '') + '/' + (kind === 'vod' ? 'movie' : kind) + '/' +
    [feed.username, feed.password, selected.id + '.' + ext].map(encodeURIComponent).join('/');
  const session = randomBytes(24).toString('hex');
  const ticket = createHmac('sha256', session).update(url).digest('hex');
  await mkdir(join(root, 'data/runtime/xtream'), { recursive: true, mode: 0o700 });
  await changeSession(accountPath(root, feed), async state => {
    if (state.expires > now() && (state.viewer !== viewer || state.client !== client)) throw fail(409, 'This provider account is already playing in another tab or device. Stop that player first.');
    if (state.client === client && state.sequence >= sequence) throw fail(409, 'A newer playback selection has replaced this request.');
    signal.throwIfAborted();
    await cache(root, 'xtream-play:' + ticket, { feed: feed.id, viewer, fingerprint: sha(phpJson(feed)), url, session }, 21600);
    return { id: session, viewer, client, sequence, expires: now() + 90 };
  });
  return { ok: true, item: selected, source: { kind, url: base.slice(0, -1) + streamPath + '?ticket=' + ticket + '&format=.' + ext } };
}

async function stream(root, base, ticket, state, request, response, controller) {
  const { session } = state, signal = controller.signal;
  const target = request.query.resource == null ? state.url : resourceUrl(request.query.resource, sha(session), ticket);
  const current = () => xtreamCurrent(base, session);
  const stopped = () => fail(409, 'Playback was stopped or replaced.');
  if (!await current()) throw stopped();
  if (request.method === 'HEAD') return response.type(/\.m3u8$/i.test(mediaUrl(target).pathname) ? 'application/vnd.apple.mpegurl' : 'application/octet-stream').end();
  const range = request.headers.range;
  if (range && !/^bytes=(?:\d+-\d*|-\d+)$/.test(range)) throw fail(416, 'Invalid byte range.');
  const lock = await open(base + '.transfer', 'a', 0o600);
  let res, timer, decoder;
  try {
    await lockFile(lock, 12, signal).catch(() => { throw fail(409, 'Previous provider request is still closing.'); });
    if (!await current()) throw stopped();
    timer = setInterval(() => { current().then(ok => { if (!ok) controller.abort(stopped()); }, () => controller.abort(stopped())); }, 500);
    const jar = new ProviderCookies(base + '.cookies');
    await jar.loadJar();
    const resolvedKey = 'xtream-resolved:' + sha(session + target);
    const resolved = await cached(root, resolvedKey);
    const result = await upstream(resolved?.url || target, { signal, jar, range });
    res = result.res;
    if (res.statusCode === 416) {
      if (res.headers['content-range']) response.set('Content-Range', res.headers['content-range']);
      return response.status(416).end();
    }
    if (![200, 206].includes(res.statusCode)) throw fail(502, 'Provider refused the stream (HTTP ' + res.statusCode + ').');
    const type = String(res.headers['content-type'] ?? 'application/octet-stream').split(';')[0];
    if (/html|json/i.test(type)) throw fail(502, 'The provider returned an error page instead of media.');
    if (result.url.href !== target && result.url.href !== resolved?.url)
      await cache(root, resolvedKey, { url: result.url.href }, 21600);
    const encoding = String(res.headers['content-encoding'] ?? 'identity').toLowerCase();
    const decoders = { gzip: createGunzip, deflate: createInflate, br: createBrotliDecompress };
    if (encoding !== 'identity') {
      if (!Object.hasOwn(decoders, encoding)) throw fail(502, 'Unsupported provider encoding.');
      decoder = decoders[encoding]();
      res.on('error', error => decoder.destroy(error));
      res.pipe(decoder);
    }
    const reader = (decoder ?? res)[Symbol.asyncIterator]();
    const chunks = [];
    let size = 0, isList = /mpegurl/i.test(type) || /\.m3u8$/i.test(result.url.pathname);
    while (!isList && size < 1024) {
      const { value, done } = await reader.next();
      if (done) break;
      chunks.push(value);
      size += value.length;
      const prefix = Buffer.concat(chunks, size).subarray(0, 1024).toString().trimStart();
      if (prefix.startsWith('#EXTM3U')) isList = true;
      else if (prefix && !'#EXTM3U'.startsWith(prefix)) break;
    }
    response.set('X-Accel-Buffering', 'no');
    if (isList) {
      for (;;) {
        const { value, done } = await reader.next();
        if (done) break;
        size += value.length;
        if (size > 5_000_000) throw fail(502, 'Provider playlist exceeds the size limit.');
        chunks.push(value);
      }
      return response.type('application/vnd.apple.mpegurl').send(playlist(Buffer.concat(chunks, size).toString(), result.url,
        { ticket }, sha(session), ticket));
    }
    response.status(res.statusCode).type(/^(?:video|audio)\//i.test(type) ? type : 'application/octet-stream');
    for (const header of ['content-length', 'accept-ranges', 'content-range']) {
      if (res.headers[header] && !decoder) response.set(header, res.headers[header]);
    }
    async function* bytes() {
      yield* chunks;
      for (;;) {
        const { value, done } = await reader.next();
        if (done) return;
        yield value;
      }
    }
    await pipeline(bytes(), response, { signal });
  } finally {
    clearInterval(timer);
    decoder?.destroy();
    try { await closeXtreamUpstream(res); } finally { await lock.close(); }
  }
}

module.exports = function mountXtream(app, prefix, { root, handle, json, services }) {
  let active = 0;
  const operation = handle(async (request, response, pool, _bcrypt, settings, current) => {
    if (enabled(settings.features_explicit?.live) && !enabled(settings.features?.live)) return response.status(404).json({ ok: false, error: 'Live channels are disabled in Settings → Integrations.' });
    if (!authorized(response, current)) return;
    const user = current.user, viewer = String(user.id);
    const parental = await parentalStatus(root, pool, current, user, now());
    if (!areaAllowed(user, 'live', parental)) return response.status(403).json({ ok: false, code: 'area_denied', area: 'live', error: 'Your account does not have access to Live.' });
    if (parental.restricted) return response.status(403).json({ ok: false, error: 'Provider catalogues are unavailable for restricted profiles.' });
    const action = request.params.action;
    if (request.method === 'HEAD' && action !== 'xtream_stream') return response.sendStatus(405);
    const adminAction = ['xtream_manage', 'xtream_import', 'xtream_import_status'].includes(action);
    const docs = await documents(pool, adminAction || action === 'xtream_connections' ? ['xtream-connections', 'permission_roles'] : ['xtream-connections']);
    const feeds = objects(docs['xtream-connections']?.connections);
    if (adminAction) {
      if (!publicUser(user, settings, docs, now()).capabilities.iptv_manage)
        return response.status(403).json({ ok: false, error: 'Managing providers requires the iptv_manage permission.' });
      active++;
      try {
        const signal = AbortSignal.timeout(15000);
        if (action === 'xtream_manage') return response.json(await manage(root, pool, request.body ?? {}, signal));
        const body = action === 'xtream_import_status' ? null : request.body ?? {};
        const run = () => providerImport({ root, pool, body, viewer, parser: services().html,
          readPage: url => importPage(url, signal),
          checkAccount: entry => checkAccount(root, entry, signal, true),
        });
        return response.json(await (['configure', 'add'].includes(body?.operation) ? withConfigurationLock(root, signal, run) : run()));
      } catch (error) {
        return response.status(error.expose ? error.status : 502).json({ ok: false, error: error.expose ? error.message : 'Could not update the provider connection.' });
      } finally { active--; }
    }
    if (action === 'xtream_connections') {
      const manage = publicUser(user, settings, docs, now()).capabilities.iptv_manage;
      return response.json({ ok: true, connections: feeds.filter(f => enabled(f.enabled) || manage)
        .map(({ id, name, url, enabled }) => ({ id, name, url, enabled })) });
    }
    const input = request.method === 'POST' ? request.body ?? {} : request.query;
    const ticketAction = ['xtream_heartbeat', 'xtream_stop', 'xtream_stream'].includes(action);
    const ticket = input.ticket;
    const state = ticketAction && typeof ticket === 'string' && /^[a-f0-9]{64}$/.test(ticket) ? await cached(root, 'xtream-play:' + ticket) : null;
    const feed = feeds.find(f => f.id === (ticketAction ? state?.feed : input.feed) && enabled(f.enabled));
    if (ticketAction && (!feed || state?.viewer !== viewer || !/^[a-f0-9]{48}$/.test(state?.session) ||
        state.fingerprint !== sha(phpJson(feed)))) return response.status(403).json({ ok: false, error: 'Playback expired. Select the title again.' });
    if (!feed) return response.status(422).json({ ok: false, error: 'Select an enabled Xtream Codes connection.' });
    return async () => {
      if (response.destroyed) return;
      const controller = new AbortController(), close = () => controller.abort();
      response.once('close', close);
      active++;
      try {
        if (!ticketAction) return response.json(await catalogue(root, feed, input, action, viewer,
          AbortSignal.any([controller.signal, AbortSignal.timeout(30000)]), response.locals.base));
        const base = accountPath(root, feed);
        if (action === 'xtream_stream') {
          try { return await stream(root, base, ticket, state, request, response, controller); }
          catch (error) {
            if (!controller.signal.aborted && ![403, 409, 416].includes(error.status))
              await changeSession(base, saved => saved.id === state.session ? { ...saved, expires: 0 } : null);
            throw error;
          }
        }
        await changeSession(base, saved => {
          if (action === 'xtream_stop') return saved.id === state.session ? { ...saved, expires: 0 } : null;
          if (saved.id !== state.session || saved.expires <= now()) throw fail(409, 'Playback was stopped or replaced.');
          return { ...saved, expires: now() + 90 };
        });
        return response.json({ ok: true });
      } catch (error) {
        if (response.destroyed) return;
        if (response.headersSent) return response.destroy();
        return response.status(error.expose ? error.status : 502).json({ ok: false, error: error.expose ? error.message : 'Could not complete the Xtream Codes provider request.' });
      } finally { active--; response.removeListener('close', close); }
    };
  }, true);
  app.get(prefix + '/live/:action(xtream_connections|xtream_categories|xtream_browse|xtream_stream|xtream_guide|xtream_import_status)', operation);
  app.post(prefix + '/live/:action(xtream_manage|xtream_play|xtream_stop|xtream_heartbeat|xtream_import)', json, operation);
  return () => active > 0;
};
