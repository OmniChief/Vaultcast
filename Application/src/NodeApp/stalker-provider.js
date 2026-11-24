'use strict';

const { lookup } = require('dns').promises;
const { isIP, BlockList } = require('net');
const { mkdir, readFile, rename, writeFile, unlink } = require('fs').promises;
const { dirname, join } = require('path');
const { randomBytes } = require('crypto');
const { createGunzip, createInflate, createBrotliDecompress } = require('zlib');
const { providerResponse } = require('../Workers/media-relay');
const { cached } = require('./profile-policy');
const { sha, now, parse, enabled } = require('./account-data');

const ua =
  'Mozilla/5.0 (QtEmbedded; U; Linux; C) AppleWebKit/533.3 MAG250 stbapp ver: 4 rev: 1812 Mobile Safari/533.3';
const phpJson = (value) =>
  JSON.stringify(value)
    .replace(/\//g, '\\/')
    .replace(/[\u0080-\uffff]/g, (value) => '\\u' + value.charCodeAt(0).toString(16).padStart(4, '0'));
async function providerBody(response, limit) {
  const encoding = String(response.headers['content-encoding'] ?? 'identity')
    .trim()
    .toLowerCase();
  const decoders = { gzip: createGunzip, deflate: createInflate, br: createBrotliDecompress };
  const factory = Object.hasOwn(decoders, encoding) ? decoders[encoding] : null;
  if (!factory && encoding !== 'identity' && encoding !== '')
    throw new Error('Provider returned an unsupported response encoding.');
  const decoder = factory?.(),
    source = decoder ? response.pipe(decoder) : response;
  const forward = (error) => decoder?.destroy(error);
  if (decoder) response.on('error', forward);
  try {
    const chunks = [];
    let size = 0;
    for await (const chunk of source) {
      size += chunk.length;
      if (size > limit) throw new Error('Provider response exceeds the size limit.');
      chunks.push(chunk);
    }
    return Buffer.concat(chunks);
  } finally {
    if (decoder) {
      response.removeListener('error', forward);
      decoder.destroy();
    }
  }
}
function portalEndpoint(value) {
  const url = mediaUrl(value);
  if (url.search || url.hash) throw new Error('Enter a portal URL without a query or fragment.');
  const path = url.pathname.replace(/\/+$/, '').replace(/\/c$/, '');
  return url.origin + (/\/(?:server\/load|portal)\.php$/.test(path) ? path : path + '/server/load.php');
}
const configHash = (feed) =>
  sha(
    phpJson(
      Object.fromEntries(
        ['mac', 'username', 'password', 'serial', 'deviceId', 'deviceId2', 'id', 'url'].map((key) => [
          key,
          feed[key] ?? '',
        ]),
      ),
    ),
  );
const blocked = new BlockList();
for (const [address, prefix] of [
  ['0.0.0.0', 8],
  ['10.0.0.0', 8],
  ['100.64.0.0', 10],
  ['127.0.0.0', 8],
  ['169.254.0.0', 16],
  ['172.16.0.0', 12],
  ['192.0.0.0', 24],
  ['192.0.2.0', 24],
  ['192.168.0.0', 16],
  ['198.18.0.0', 15],
  ['198.51.100.0', 24],
  ['203.0.113.0', 24],
  ['224.0.0.0', 3],
])
  blocked.addSubnet(address, prefix, 'ipv4');
// Require globally routed unicast IPv6; reject transition ranges that can embed private IPv4.
const global6 = new BlockList();
global6.addSubnet('2000::', 3, 'ipv6');
for (const [address, prefix] of [
  ['2001::', 32],
  ['2001:2::', 48],
  ['2001:10::', 28],
  ['2001:20::', 28],
  ['2001:db8::', 32],
  ['2002::', 16],
])
  blocked.addSubnet(address, prefix, 'ipv6');

function mediaUrl(value) {
  if (typeof value !== 'string' || /[\x00-\x20\x7f]/.test(value))
    throw new Error('Provider returned an invalid media URL.');
  const url = new URL(value);
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password)
    throw new Error('Provider returned an invalid media URL.');
  return url;
}

async function publicAddresses(url) {
  const host = url.hostname.replace(/^\[|\]$/g, '');
  const ips = isIP(host)
    ? [{ address: host, family: isIP(host) }]
    : await lookup(host, { all: true, verbatim: true });
  if (
    !ips.length ||
    ips.some(({ address, family }) =>
      family === 6
        ? !global6.check(address, 'ipv6') || blocked.check(address, 'ipv6')
        : family !== 4 || blocked.check(address, 'ipv4'),
    )
  )
    throw new Error('Provider upstream must use a public network address.');
  return ips.map((row) => row.address);
}

async function atomicJson(path, value, publish) {
  await mkdir(dirname(path), { recursive: true, mode: 0o700 });
  const temporary = path + '.' + randomBytes(8).toString('hex') + '.tmp';
  try {
    await writeFile(temporary, JSON.stringify(value), { flag: 'wx', mode: 0o600 });
    if (publish) await publish(() => rename(temporary, path));
    else await rename(temporary, path);
  } finally {
    await unlink(temporary).catch(() => {});
  }
}

async function snapshot(root, key) {
  const hash = sha(key);
  return readFile(join(root, 'data/stalker-catalogue', hash.slice(0, 2), hash + '.json'), 'utf8')
    .then(JSON.parse)
    .catch(() => null);
}

async function usableEpisode(pool, feed, config, episode) {
  if (episode?.episodeGeneration == null || episode.root == null) return false;
  const [offers] = await pool.execute(
    "SELECT 1 FROM vaultcast_stalker_offers WHERE feed_id=? AND kind='series' AND item_id=? AND config=? AND generation=? AND available=1 LIMIT 1",
    [feed.id, String(episode.root), config, episode.episodeGeneration],
  );
  return offers.length > 0;
}

async function directItem(pool, root, feed, id) {
  if (id.startsWith('series:')) {
    const config = configHash(feed);
    const episode = await snapshot(root, 'episodes:' + config + ':item:' + id);
    if (await usableEpisode(pool, feed, config, episode)) return episode;
  }
  return cached(root, 'stalker_direct_v2_' + sha(phpJson([feed, 'item:' + id])));
}

async function itemEntry(pool, root, feed, id, legacyMetadata) {
  const config = configHash(feed);
  const [accounts] = await pool.execute('SELECT payload FROM vaultcast_documents WHERE name=?', [
    'stalker-account-' + config,
  ]);
  const expiry = accounts.length ? parse(accounts[0].payload)?.expires : null;
  if (enabled(expiry) && Number(expiry) <= now()) return null;
  const series = id.startsWith('series:');
  if (series) {
    const item = await directItem(pool, root, feed, id);
    if (item) return item;
  }
  const match = /^(vod|series):([^:]+)$/.exec(id);
  if (match) {
    const [rows] = await pool.execute(
      'SELECT playback FROM vaultcast_stalker_offers WHERE feed_id=? AND kind=? AND item_id=? AND config=? AND available=1',
      [feed.id, match[1], match[2], config],
    );
    if (rows[0]?.playback) return parse(rows[0].playback);
  }
  if (!series) {
    const item = await directItem(pool, root, feed, id);
    if (item) return item;
  }
  const [records] = await pool.execute('SELECT payload FROM vaultcast_documents WHERE name=?', [
    'stalker-catalogues',
  ]);
  const saved = records.length
    ? parse(records[0].payload)?.sources?.[feed.id + '/' + (id.startsWith('series:') ? 'series' : 'vod')]
    : null;
  const generation = saved?.config === config && saved.active ? saved.active : sha(phpJson(feed));
  const cachedItem = await cached(root, 'stalker_item_v2_' + sha(phpJson([feed, id, generation])));
  if (cachedItem) return cachedItem;
  const legacy = /^(vod|series):([^:]+)(?::(.+))?$/.exec(id);
  const kind = legacy?.[1] ?? 'vod';
  const itemId = legacy?.[2] ?? id;
  const [titles] = await pool.execute(
    'SELECT parent_id,payload FROM vaultcast_stalker_titles WHERE fingerprint=? AND kind=? AND item_id=? ORDER BY playable DESC,parent_id LIMIT 1',
    [generation, kind, itemId],
  );
  if (!titles.length) return null;
  const stored = parse(titles[0].payload);
  return {
    cmd: String(stored.row?.cmd ?? ''),
    series: legacy?.[3] ?? String(stored.row?.series_number ?? ''),
    metadata: legacyMetadata
      ? await legacyMetadata(stored, kind, legacy?.[3], titles[0].parent_id, generation)
      : {
          ...stored.facets,
          id,
          kind,
          feed: feed.id,
          connection: feed.name,
          category: stored.category,
        },
  };
}

const ProviderCookies = require('./provider-cookies');

const pendingSessions = new Map();

class StalkerProvider extends ProviderCookies {
  constructor(root, feed, cookiePath, signal) {
    super(cookiePath);
    this.root = root;
    this.feed = feed;
    this.signal = signal;
    const url = mediaUrl(feed.url);
    this.endpoint = portalEndpoint(feed.url);
    this.cookies = [
      {
        domain: url.hostname.toLowerCase(),
        sub: false,
        path: '/',
        secure: false,
        expires: 0,
        name: 'mac',
        value: encodeURIComponent(feed.mac),
      },
    ];
    this.token = '';
    this.expires = 0;
  }

  async loadCookies() {
    if (!this.cookiePath) return;
    await this.loadJar();
    const selected = await cached(this.root, 'stalker_endpoint_' + sha(this.feed.url));
    const base = new URL(this.feed.url).pathname.replace(/\/$/, '').replace(/\/c$/, '');
    if ([base + '/portal.php', base + '/server/load.php'].includes(selected?.path))
      this.endpoint = new URL(this.endpoint).origin + selected.path;
    const session = await cached(this.root, 'stalker_session_' + sha(phpJson(this.feed)));
    if (
      session?.expires > now() &&
      typeof session.token === 'string' &&
      session.token &&
      !/[\r\n]/.test(session.token)
    ) {
      this.token = session.token;
      this.expires = Number(session.expires);
    }
  }

  async response(
    value,
    {
      signal,
      range,
      ifRange,
      metadata = false,
      token = '',
      attempt = 0,
      method = 'GET',
      userAgent = ua,
      referer,
      accept,
      withoutCookies = false,
    } = {},
  ) {
    signal = this.signal ? (signal ? AbortSignal.any([signal, this.signal]) : this.signal) : signal;
    signal?.throwIfAborted();
    const url = mediaUrl(value);
    const ips = await publicAddresses(url);
    signal?.throwIfAborted();
    const response = await providerResponse(
      {
        ip: ips[attempt % ips.length],
        purpose: metadata ? 'metadata' : this.mediaCookies ? 'media' : 'download',
        jar: this.mediaCookies ? this.cookiePath : undefined,
        ua: userAgent,
        accept,
        ref: referer ?? this.endpoint.replace(/\/(?:server\/load|portal)\.php$/, '/c/'),
        cookies: withoutCookies ? '' : this.cookieHeader(url, metadata),
        range,
        ifRange,
        headers: metadata
          ? {
              accept: 'application/json',
              ...(token ? { authorization: 'Bearer ' + token } : {}),
              'x-user-agent': 'Model: MAG250; Link: Ethernet',
            }
          : undefined,
      },
      url,
      method,
      signal,
    );
    try {
      if (!withoutCookies) {
        if (this.mediaCookies) {
          if (response.headers['set-cookie']?.length) await this.mediaCookies(url, response.headers);
        } else if (response.headers['set-cookie']?.length) await this.acceptCookies(url, response.headers);
      }
    } catch (error) {
      response.destroy();
      throw error;
    }
    return response;
  }

  async request(type, action, params = {}, token = '', endpoint = this.endpoint) {
    const cooldownKey = 'stalker_cooldown_' + sha(phpJson([this.feed.url, this.feed.mac]));
    // A list-page throttle belongs to its catalogue folder. Authentication
    // and other portal-wide throttles still apply to every request.
    const scope = action === 'get_ordered_list' && (params.category ?? params.genre) != null
      ? 'stalker_folder_cooldown_' + sha(phpJson([configHash(this.feed), type,
          params.category ?? params.genre, params.movie_id ?? '']))
      : null;
    const global = await cached(this.root, cooldownKey);
    const cooldown = Number(global?.until) > now() || !scope ? global : await cached(this.root, scope);
    if (Number(cooldown?.until) > now())
      throw Object.assign(new Error('Provider rate limited requests (HTTP 429).'), {
        retryAt: cooldown.until, folderLimited: Boolean(scope && cooldown !== global),
      });
    const url = endpoint + '?' + new URLSearchParams({ ...params, type, action, JsHttpRequest: '1-xml' });
    // Reverse proxies sometimes redirect the metadata endpoint. Never forward
    // the MAC, session token, or account parameters to another origin.
    const signal = AbortSignal.timeout(30000);
    let response = await this.response(url, { metadata: true, token, signal });
    let currentUrl = new URL(url);
    for (let hops = 0; [301,302,307,308].includes(response.statusCode) && response.headers.location && hops < 2; hops++) {
      const next = new URL(response.headers.location, currentUrl);
      if (next.origin !== currentUrl.origin || next.username || next.password) break;
      if (!next.search) next.search = currentUrl.search;
      response.destroy();
      currentUrl = next;
      response = await this.response(next.href, { metadata: true, token, signal });
    }
    try {
      if (response.statusCode === 429) {
        const raw = response.headers['retry-after'];
        const until = Math.max(
          now() + 60,
          /^\d+$/.test(raw ?? '')
            ? now() + Math.min(31536000, Number(raw))
            : Math.floor(Date.parse(raw) / 1000) || 0,
        );
        const hash = sha(scope || cooldownKey);
        await atomicJson(join(this.root, 'data/cache/kv', hash.slice(0, 2), hash + '.json'), {
          e: until,
          v: { until },
        });
        throw Object.assign(new Error('Provider rate limited requests (HTTP 429).'), { retryAt: until, folderLimited: Boolean(scope) });
      }
      if ([401, 403].includes(response.statusCode))
        throw Object.assign(
          new Error('Portal rejected authentication. Check the registered device and account.'),
          { authentication: true },
        );
      if (response.statusCode < 200 || response.statusCode >= 300)
        throw Object.assign(
          new Error('Portal ' + type + '/' + action + ' request failed (HTTP ' + response.statusCode + ').'),
          { status: response.statusCode },
        );
      const data = JSON.parse((await providerBody(response, 16 * 1024 * 1024)).toString('utf8').replace(/^\uFEFF/, ''));
      if (action === 'do_auth' && typeof data?.js === 'boolean') return { status: data.js };
      if (
        data &&
        Object.hasOwn(data, 'js') &&
        (data.js == null || data.js === false || enabled(data.js.error))
      ) {
        throw Object.assign(new Error('Portal rejected the session or device.'), {
          authentication: true, emptyReply: data.js == null || data.js === false,
        });
      }
      // Some portals return the create_link command as a string instead of
      // the usual object. Accept it only for this action, not catalogue data.
      if (action === 'create_link' && typeof data?.js === 'string' && data.js.trim())
        return { cmd: data.js };
      if (!data?.js || typeof data.js !== 'object') throw new Error('Portal returned an invalid response.');
      return data.js;
    } finally {
      response.destroy();
    }
  }

  async session(renew = false) {
    if (!renew && this.token && this.expires > now()) return this.token;
    if (!this.shareSession || !this.cookiePath) return this.handshake();
    const key = this.root + ':' + this.cookiePath;
    const running = pendingSessions.get(key);
    if (running) {
      try { await running; } catch (error) {
        this.signal?.throwIfAborted();
        if (error.name !== 'AbortError' && error.name !== 'TimeoutError') throw error;
        return this.session(renew);
      }
      this.signal?.throwIfAborted();
      await this.loadCookies();
      if (this.token && this.expires > now()) return this.token;
    }
    const pending = this.handshake().finally(() => pendingSessions.delete(key));
    pendingSessions.set(key, pending);
    return pending;
  }

  async handshake() {
    const candidates = [this.endpoint];
    if (!/\.php\/?$/.test(new URL(this.feed.url).pathname)) {
      candidates.push(
        this.endpoint.replace(/\/(?:server\/load|portal)\.php$/, '/portal.php'),
        this.endpoint.replace(/\/(?:server\/load|portal)\.php$/, '/server/load.php'),
      );
    }
    let last;
    for (const endpoint of new Set(candidates)) {
      try {
        const handshake = await this.request('stb', 'handshake', { token: '' }, '', endpoint);
        if (typeof handshake.token !== 'string' || !handshake.token || /[\r\n]/.test(handshake.token))
          throw new Error('Portal handshake returned no session token.');
        this.token = handshake.token;
        this.endpoint = endpoint;
        last = null;
        break;
      } catch (error) {
        if (error.retryAt) throw error;
        last = error;
      }
    }
    if (last) throw last;
    const f = this.feed;
    this.expires = 0;
    const profile = await this.request(
      'stb',
      'get_profile',
      {
        hd: 1,
        stb_type: 'MAG250',
        sn: f.serial ?? '',
        device_id: f.deviceId ?? '',
        device_id2: f.deviceId2 ?? '',
        auth_second_step: 1,
        image_version: 218,
        hw_version: '1.7-BD-00',
        num_banks: 2,
        not_valid_token: 0,
        ver: 'ImageDescription: 0.2.18-r14-pub-250; PORTAL version: 5.5.0; API Version: JS API version: 328; STB API version: 134; Player Engine version: 0x58c',
        client_type: 'STB',
        video_out: 'hdmi',
      },
      this.token,
    );
    if (enabled(profile.blocked) || enabled(profile.error))
      throw Object.assign(new Error('Portal rejected the registered device profile.'), {
        authentication: true,
      });
    if (
      f.username &&
      !enabled(
        (await this.request('stb', 'do_auth', { login: f.username, password: f.password ?? '' }, this.token))
          .status,
      )
    ) {
      throw new Error('Portal account login was rejected.');
    }
    this.expires = now() + 600;
    if (this.shareSession) {
      for (const [key, value, ttl] of [
        ['stalker_endpoint_' + sha(f.url), { path: new URL(this.endpoint).pathname }, 86400],
        ['stalker_session_' + sha(phpJson(f)), { token: this.token, expires: this.expires }, 600],
      ]) {
        const hash = sha(key);
        await atomicJson(join(this.root, 'data/cache/kv', hash.slice(0, 2), hash + '.json'), {
          e: now() + ttl,
          v: value,
        });
      }
    }
    return this.token;
  }

  async call(type, action, params = {}) {
    const token = await this.session();
    try {
      return await this.request(type, action, params, token);
    } catch (error) {
      if (!error.authentication) throw error;
      if (this.shareSession) await this.loadCookies();
      return this.request(type, action, params, await this.session(this.token === token));
    }
  }

  async resolve(id, item) {
    if (!item?.cmd)
      throw new Error('The selected provider title has no playback command. Refresh the provider page.');
    const params = {
      cmd: item.cmd,
      series: String(item.series ?? ''),
      forced_storage: 'undefined',
      disable_ad: 0,
      download: 0,
    };
    const extract = value => typeof value === 'string' && /(?:^|[\s"'])(https?:\/\/[^\s"'<>]+)/i.exec(value);
    let match;
    for (const type of id.startsWith('series:') ? ['vod', 'series'] : ['vod']) {
      try {
        const result = await this.call(type, 'create_link', params);
        match = extract(result?.cmd) || extract(result?.url);
        if (match) break;
      } catch (error) {
        if (error.authentication || error.retryAt || this.signal?.aborted) throw error;
      }
    }
    match ||= extract(item.cmd);
    if (!match) throw new Error('Provider did not return a playable video link.');
    const url = mediaUrl(match[1]);
    await publicAddresses(url);
    return url.href;
  }

  async ordered(type, params, page, validate) {
    const key = 'stalker_paging_v2_' + sha(phpJson([this.feed, type, params]));
    const mode = await cached(this.root, key);
    if (mode) return this.call(type, 'get_ordered_list', { ...params, p: page - 1 + Number(mode.offset) });
    let zero, first = [];
    try {
      zero = await this.call(type, 'get_ordered_list', { ...params, p: 0 });
      validate(zero);
      first = Object.values(zero.data);
    } catch (error) {
      // Some one-based portals reject page zero outright.
      if ((error.authentication && !error.emptyReply) || error.retryAt || this.signal?.aborted ||
          (error.status && ![400, 404, 416, 422].includes(error.status))) throw error;
      if (!zero && !error.status && !error.emptyReply) throw error;
    }
    // A complete nonempty page zero is already sufficient for this request.
    // Don't probe beyond the end just to learn an offset we won't use.
    if (page === 1 && first.length && Number(zero.total_items) === first.length) return zero;
    const one = await this.call(type, 'get_ordered_list', { ...params, p: 1 });
    validate(one);
    const second = Object.values(one.data);
    const offset = !first.length || (first.length === second.length &&
      first.every((row, index) => String(row.id) === String(second[index].id))) ? 1 : 0;
    if (first.length || second.length) {
      const hash = sha(key);
      await atomicJson(join(this.root, 'data/cache/kv', hash.slice(0, 2), hash + '.json'), {
        e: now() + 86400,
        v: { offset },
      });
    }
    return page === 1
      ? offset
        ? one
        : zero
      : page === 2 && !offset ? one : this.call(type, 'get_ordered_list', { ...params, p: page - 1 + offset });
  }

  async accountExpiry() {
    const responses = [],
      sources = [];
    for (const [type, action, params] of [
      ['account_info', 'get_main_info', {}],
      [
        'stb',
        'get_profile',
        {
          hd: 1,
          stb_type: 'MAG250',
          sn: this.feed.serial ?? '',
          device_id: this.feed.deviceId ?? '',
          device_id2: this.feed.deviceId2 ?? '',
          auth_second_step: 1,
          image_version: 218,
          hw_version: '1.7-BD-00',
          num_banks: 2,
          not_valid_token: 0,
          ver: 'ImageDescription: 0.2.18-r14-pub-250; PORTAL version: 5.5.0; API Version: JS API version: 328; STB API version: 134; Player Engine version: 0x58c',
          client_type: 'STB',
          video_out: 'hdmi',
        },
      ],
    ]) {
      try {
        responses.push(await this.call(type, action, params));
        sources.push(type + '/' + action);
      } catch (error) {
        if (error.retryAt || this.signal?.aborted) throw error;
      }
    }
    const fields = new Set([
      'expire_billing_date',
      'end_date',
      'expire_date',
      'expiration_date',
      'expires_at',
      'subscription_end',
      'expiration',
      'expiry_date',
      'expiry',
      'exp_date',
      'tariff_expired_date',
      'tariff_instead_expired',
      'phone',
    ]);
    for (const response of responses) {
      const pending = [[response, 0]];
      while (pending.length) {
        const [data, depth] = pending.pop();
        for (const [key, value] of Object.entries(data ?? {})) {
          if (fields.has(key.toLowerCase()) && value != null && typeof value !== 'object') {
            const raw = String(value).trim();
            if (
              !raw ||
              ['0', '0000-00-00', '0000-00-00 00:00:00', 'unlimited', 'null', 'false'].includes(
                raw.toLowerCase(),
              ) ||
              (key.toLowerCase() === 'phone' && !/[./-]|[a-z]{3,}|\s\d{4}\b/i.test(raw))
            )
              continue;
            const numeric = /^\d+$/.test(raw);
            // MAG billing dates often use day.month.year, which Date.parse can interpret as month first.
            const european = /^(\d{1,2})\.(\d{1,2})\.(\d{4})(.*)$/.exec(raw);
            const normal = european
              ? european[3] +
                '-' +
                european[2].padStart(2, '0') +
                '-' +
                european[1].padStart(2, '0') +
                european[4]
              : raw;
            const dateOnly = /^\d{4}-\d\d-\d\d$/.test(normal);
            const timestamp = numeric
              ? Number(raw) / (Number(raw) > 1e12 ? 1000 : 1)
              : Date.parse(dateOnly ? normal + 'T00:00:00' : normal) / 1000;
            if (timestamp >= 946684800 && timestamp <= 4102444800) {
              const clock = /(?:T|\s)(\d{1,2}):(\d{2})/.exec(raw);
              const utc = numeric || /(?:Z|[+-]\d{2}:?\d{2})$/i.test(raw);
              const instant = new Date(Math.floor(timestamp) * 1000);
              const iso = instant.toISOString();
              const date = utc
                ? iso.slice(0, 10)
                : [
                    instant.getFullYear(),
                    String(instant.getMonth() + 1).padStart(2, '0'),
                    String(instant.getDate()).padStart(2, '0'),
                  ].join('-');
              const time =
                numeric || clock
                  ? utc
                    ? iso.slice(11, 16)
                    : clock[1].padStart(2, '0') + ':' + clock[2]
                  : null;
              return {
                date,
                time,
                timeZone: time ? (utc ? 'UTC' : 'provider time') : null,
                expires: time
                  ? Math.floor(timestamp)
                  : Math.floor(
                      new Date(instant.getFullYear(), instant.getMonth(), instant.getDate() + 1).getTime() /
                        1000,
                    ),
                status: 'found',
                sources,
              };
            }
          }
          if (depth < 3 && value && typeof value === 'object') pending.push([value, depth + 1]);
        }
      }
    }
    return {
      expires: null,
      date: null,
      time: null,
      timeZone: null,
      status: sources.length ? 'not_exposed' : 'account_requests_failed',
      sources,
    };
  }
}

module.exports = {
  StalkerProvider,
  portalEndpoint,
  itemEntry,
  directItem,
  usableEpisode,
  configHash,
  phpJson,
  atomicJson,
  mediaUrl,
  publicAddresses,
  providerBody,
};
