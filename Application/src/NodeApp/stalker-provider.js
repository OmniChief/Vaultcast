'use strict';

const { lookup } = require('dns').promises;
const { isIP, BlockList } = require('net');
const { mkdir, readFile, rename, writeFile, unlink } = require('fs').promises;
const { dirname, join } = require('path');
const { randomBytes } = require('crypto');
const { providerResponse } = require('../Workers/media-relay');
const { cached } = require('./profile-policy');
const { sha, now, parse } = require('./account-data');

const ua = 'Mozilla/5.0 (QtEmbedded; U; Linux; C) AppleWebKit/533.3 MAG250 stbapp ver: 4 rev: 1812 Mobile Safari/533.3';
const phpJson = value => JSON.stringify(value).replace(/\//g, '\\/').replace(/[\u0080-\uffff]/g,
  value => '\\u' + value.charCodeAt(0).toString(16).padStart(4, '0'));
const configHash = feed => sha(phpJson(Object.fromEntries(
  ['mac', 'username', 'password', 'serial', 'deviceId', 'deviceId2', 'id', 'url'].map(key => [key, feed[key] ?? '']))));
const blocked = new BlockList();
for (const [address, prefix] of [['0.0.0.0',8],['10.0.0.0',8],['100.64.0.0',10],['127.0.0.0',8],
  ['169.254.0.0',16],['172.16.0.0',12],['192.0.0.0',24],['192.0.2.0',24],['192.168.0.0',16],
  ['198.18.0.0',15],['198.51.100.0',24],['203.0.113.0',24],['224.0.0.0',3]]) blocked.addSubnet(address, prefix, 'ipv4');
// Require globally routed unicast IPv6; reject transition ranges that can embed private IPv4.
const global6 = new BlockList();
global6.addSubnet('2000::', 3, 'ipv6');
for (const [address, prefix] of [['2001::',32],['2001:2::',48],['2001:10::',28],['2001:20::',28],
  ['2001:db8::',32],['2002::',16]]) blocked.addSubnet(address, prefix, 'ipv6');

function mediaUrl(value) {
  if (typeof value !== 'string' || /[\x00-\x20\x7f]/.test(value)) throw new Error('Provider returned an invalid media URL.');
  const url = new URL(value);
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) throw new Error('Provider returned an invalid media URL.');
  return url;
}

async function publicAddresses(url) {
  const host = url.hostname.replace(/^\[|\]$/g, '');
  const ips = isIP(host) ? [{ address: host, family: isIP(host) }] :
    await lookup(host, { all: true, verbatim: true });
  if (!ips.length || ips.some(({ address, family }) => family === 6
    ? !global6.check(address, 'ipv6') || blocked.check(address, 'ipv6')
    : family !== 4 || blocked.check(address, 'ipv4'))) throw new Error('Provider upstream must use a public network address.');
  return ips.map(row => row.address);
}

async function atomicJson(path, value) {
  await mkdir(dirname(path), { recursive: true, mode: 0o700 });
  const temporary = path + '.' + randomBytes(8).toString('hex') + '.tmp';
  try {
    await writeFile(temporary, JSON.stringify(value), { flag: 'wx', mode: 0o600 });
    await rename(temporary, path);
  } finally { await unlink(temporary).catch(() => {}); }
}

async function snapshot(root, key) {
  const hash = sha(key);
  return readFile(join(root, 'data/stalker-catalogue', hash.slice(0, 2), hash + '.json'), 'utf8')
    .then(JSON.parse).catch(() => null);
}

async function itemEntry(pool, root, feed, id) {
  const config = configHash(feed);
  const [accounts] = await pool.execute('SELECT payload FROM vaultcast_documents WHERE name=?', ['stalker-account-' + config]);
  const expiry = accounts.length ? parse(accounts[0].payload)?.expires : null;
  if (expiry && Number(expiry) <= now()) return null;
  if (id.startsWith('series:')) {
    const episode = await snapshot(root, 'episodes:' + config + ':item:' + id);
    const [jobs] = await pool.execute("SELECT generation,status FROM vaultcast_stalker_jobs WHERE feed_id=? AND kind='series' AND config=? AND status NOT IN ('removed','expired','scan_required')", [feed.id, config]);
    const job = jobs[0];
    if (job && episode?.episodeGeneration) {
      if (episode.episodeGeneration === job.generation) return episode;
      if (episode.root != null) {
        const [offers] = await pool.execute("SELECT 1 FROM vaultcast_stalker_offers WHERE feed_id=? AND kind='series' AND item_id=? AND config=? AND available=1" +
          (job.status === 'complete' ? ' AND generation=?' : '') + ' LIMIT 1',
        [feed.id, String(episode.root), config, ...(job.status === 'complete' ? [episode.episodeGeneration] : [])]);
        if (offers.length) return episode;
      }
    }
  }
  const match = /^(vod|series):([^:]+)$/.exec(id);
  if (match) {
    const [rows] = await pool.execute('SELECT playback FROM vaultcast_stalker_offers WHERE feed_id=? AND kind=? AND item_id=? AND config=? AND available=1',
      [feed.id, match[1], match[2], config]);
    if (rows[0]?.playback) return parse(rows[0].playback);
  }
  const direct = await cached(root, 'stalker_direct_v2_' + sha(phpJson([feed, 'item:' + id])));
  if (direct) return direct;
  const [records] = await pool.execute('SELECT payload FROM vaultcast_documents WHERE name=?', ['stalker-catalogues']);
  const saved = records.length ? parse(records[0].payload)?.sources?.[feed.id + '/' + (id.startsWith('series:') ? 'series' : 'vod')] : null;
  const generation = saved?.config === config && saved.active ? saved.active : sha(phpJson(feed));
  const cachedItem = await cached(root, 'stalker_item_v2_' + sha(phpJson([feed, id, generation])));
  if (cachedItem) return cachedItem;
  const legacy = /^(vod|series):([^:]+)(?::(.+))?$/.exec(id);
  if (!legacy) return null;
  const [titles] = await pool.execute('SELECT payload FROM vaultcast_stalker_titles WHERE fingerprint=? AND kind=? AND item_id=? ORDER BY playable DESC,parent_id LIMIT 1',
    [generation, legacy[1], legacy[2]]);
  if (!titles.length) return null;
  const stored = parse(titles[0].payload);
  return { cmd: String(stored.row?.cmd ?? ''), series: legacy[3] ?? String(stored.row?.series_number ?? ''),
    metadata: { ...stored.facets, id, kind: legacy[1], feed: feed.id, connection: feed.name, category: stored.category } };
}

class StalkerProvider {
  constructor(root, feed, cookiePath, signal) {
    this.root = root;
    this.feed = feed;
    this.signal = signal;
    const url = mediaUrl(feed.url);
    if (url.search || url.hash) throw new Error('Enter a portal URL without a query or fragment.');
    const path = url.pathname.replace(/\/$/, '').replace(/\/c$/, '');
    this.endpoint = /\/(?:server\/load|portal)\.php$/.test(path) ? url.origin + path : url.origin + path + '/server/load.php';
    this.cookies = [{ domain: url.hostname.toLowerCase(), sub: false, path: '/', secure: false,
      expires: 0, name: 'mac', value: encodeURIComponent(feed.mac) }];
    this.cookiePath = cookiePath;
    this.token = '';
    this.expires = 0;
  }

  async loadCookies() {
    if (!this.cookiePath) return;
    const text = await readFile(this.cookiePath, 'utf8').catch(() => '');
    for (let line of text.split(/\r?\n/)) {
      if (line.startsWith('#HttpOnly_')) line = line.slice(10);
      else if (line.startsWith('#')) continue;
      const [domain, sub, path, secure, expires, name, value] = line.split('\t');
      if (name && value != null) {
        const host = domain.replace(/^\./, '').toLowerCase();
        this.cookies = this.cookies.filter(cookie => !(cookie.domain === host && cookie.path === path && cookie.name === name));
        this.cookies.push({ domain: host, sub: sub === 'TRUE', path, secure: secure === 'TRUE', expires: Number(expires), name, value });
      }
    }
    const selected = await cached(this.root, 'stalker_endpoint_' + sha(this.feed.url));
    const base = new URL(this.feed.url).pathname.replace(/\/$/, '').replace(/\/c$/, '');
    if (selected?.path === base + '/portal.php') this.endpoint = new URL(this.endpoint).origin + selected.path;
    const session = await cached(this.root, 'stalker_session_' + sha(phpJson(this.feed)));
    if (session?.expires > now() && typeof session.token === 'string' && session.token && !/[\r\n]/.test(session.token)) {
      this.token = session.token;
      this.expires = Number(session.expires);
    }
  }

  cookieHeader(url, mag = false) {
    const host = url.hostname.toLowerCase();
    const cookies = this.cookies.filter(cookie => (!mag || !['mac','stb_lang','timezone'].includes(cookie.name)) && (!cookie.expires || cookie.expires > now()) &&
      (host === cookie.domain || cookie.sub && host.endsWith('.' + cookie.domain)) &&
      (url.pathname === cookie.path || url.pathname.startsWith(cookie.path.endsWith('/') ? cookie.path : cookie.path + '/')) &&
      (!cookie.secure || url.protocol === 'https:')).map(cookie => cookie.name + '=' + cookie.value);
    if (mag) cookies.push('mac=' + encodeURIComponent(this.feed.mac), 'stb_lang=en', 'timezone=Europe/London');
    return cookies.join('; ');
  }

  async acceptCookies(url, headers) {
    for (const header of headers['set-cookie'] ?? []) {
      const [pair, ...attributes] = header.split(';');
      const index = pair.indexOf('=');
      if (index < 1) continue;
      const name = pair.slice(0, index).trim(), value = pair.slice(index + 1).trim();
      if (!/^[!#$%&'*+.^_`|~\w-]+$/.test(name) || /[\x00-\x20\x7f;]/.test(value)) continue;
      const attrs = Object.fromEntries(attributes.map(part => {
        const split = part.indexOf('=');
        return split < 0 ? [part.trim().toLowerCase(), true] : [part.slice(0, split).trim().toLowerCase(), part.slice(split + 1).trim()];
      }));
      const domain = String(attrs.domain ?? url.hostname).replace(/^\./, '').toLowerCase();
      if (domain !== url.hostname && (!domain.includes('.') || !url.hostname.endsWith('.' + domain))) continue;
      const path = typeof attrs.path === 'string' && attrs.path.startsWith('/') ? attrs.path : url.pathname.slice(0, url.pathname.lastIndexOf('/') + 1);
      const expires = typeof attrs['max-age'] === 'string' && /^-?\d+$/.test(attrs['max-age'])
        ? now() + Number(attrs['max-age']) : attrs.expires ? Math.floor(Date.parse(attrs.expires) / 1000) || 0 : 0;
      this.cookies = this.cookies.filter(cookie => !(cookie.domain === domain && cookie.path === path && cookie.name === name));
      this.cookies.push({ domain, sub: Boolean(attrs.domain), path, secure: Boolean(attrs.secure), expires, name, value });
    }
    if (this.cookiePath) {
      const text = '# Netscape HTTP Cookie File\n' + this.cookies.filter(cookie => !cookie.expires || cookie.expires > now()).map(cookie =>
        [cookie.domain, cookie.sub ? 'TRUE' : 'FALSE', cookie.path, cookie.secure ? 'TRUE' : 'FALSE', cookie.expires, cookie.name, cookie.value].join('\t')).join('\n') + '\n';
      const temporary = this.cookiePath + '.' + randomBytes(8).toString('hex') + '.tmp';
      try { await writeFile(temporary, text, { mode: 0o600, flag: 'wx' }); await rename(temporary, this.cookiePath); }
      finally { await unlink(temporary).catch(() => {}); }
    }
  }

  async response(value, { signal, range, ifRange, metadata = false, token = '', attempt = 0 } = {}) {
    signal = this.signal ? signal ? AbortSignal.any([signal, this.signal]) : this.signal : signal;
    signal?.throwIfAborted();
    const url = mediaUrl(value);
    const ips = await publicAddresses(url);
    signal?.throwIfAborted();
    const response = await providerResponse({ ip: ips[attempt % ips.length], purpose: metadata ? 'metadata' : 'download', ua,
      ref: this.endpoint.replace(/\/(?:server\/load|portal)\.php$/, '/c/'),
      cookies: this.cookieHeader(url, metadata), range, ifRange,
      headers: metadata ? { accept: 'application/json', ...(token ? { authorization: 'Bearer ' + token } : {}),
        'x-user-agent': 'Model: MAG250; Link: Ethernet' } : undefined }, url, 'GET', signal);
    try { await this.acceptCookies(url, response.headers); }
    catch (error) { response.destroy(); throw error; }
    return response;
  }

  async request(type, action, params = {}, token = '', endpoint = this.endpoint) {
    const cooldownKey = 'stalker_cooldown_' + sha(phpJson([this.feed.url, this.feed.mac]));
    const cooldown = await cached(this.root, cooldownKey);
    if (Number(cooldown?.until) > now()) throw Object.assign(new Error('Provider rate limited requests (HTTP 429).'), { retryAt: cooldown.until });
    const url = endpoint + '?' + new URLSearchParams({ ...params, type, action, JsHttpRequest: '1-xml' });
    const response = await this.response(url, { metadata: true, token, signal: AbortSignal.timeout(30000) });
    try {
      if (response.statusCode === 429) {
        const raw = response.headers['retry-after'];
        const until = Math.max(now() + 60, /^\d+$/.test(raw ?? '') ? now() + Math.min(31536000, Number(raw)) : Math.floor(Date.parse(raw) / 1000) || 0);
        const hash = sha(cooldownKey);
        await atomicJson(join(this.root, 'data/cache/kv', hash.slice(0, 2), hash + '.json'), { e: until, v: { until } });
        throw Object.assign(new Error('Provider rate limited requests (HTTP 429).'), { retryAt: until });
      }
      if ([401,403].includes(response.statusCode)) throw Object.assign(new Error('Portal rejected authentication. Check the registered device and account.'), { authentication: true });
      if (response.statusCode < 200 || response.statusCode >= 300) throw new Error('Portal ' + type + '/' + action + ' request failed (HTTP ' + response.statusCode + ').');
      const chunks = []; let size = 0;
      for await (const chunk of response) {
        size += chunk.length;
        if (size > 16 * 1024 * 1024) throw new Error('Portal response exceeds the size limit.');
        chunks.push(chunk);
      }
      const data = JSON.parse(Buffer.concat(chunks).toString('utf8'));
      if (action === 'do_auth' && typeof data?.js === 'boolean') return { status: data.js };
      if (data && Object.hasOwn(data, 'js') && (data.js == null || data.js === false || data.js.error)) {
        throw Object.assign(new Error('Portal rejected the session or device.'), { authentication: true });
      }
      if (!data?.js || typeof data.js !== 'object') throw new Error('Portal returned an invalid response.');
      return data.js;
    } finally { response.destroy(); }
  }

  async session(renew = false) {
    if (!renew && this.token && this.expires > now()) return this.token;
    const candidates = [this.endpoint];
    if (!/\.php\/?$/.test(new URL(this.feed.url).pathname)) {
      candidates.push(this.endpoint.replace(/\/(?:server\/load|portal)\.php$/, '/portal.php'),
        this.endpoint.replace(/\/(?:server\/load|portal)\.php$/, '/server/load.php'));
    }
    let last;
    for (const endpoint of new Set(candidates)) {
      try {
        const handshake = await this.request('stb', 'handshake', { token: '' }, '', endpoint);
        if (typeof handshake.token !== 'string' || !handshake.token || /[\r\n]/.test(handshake.token)) throw new Error('Portal handshake returned no session token.');
        this.token = handshake.token; this.endpoint = endpoint; last = null; break;
      } catch (error) { if (error.retryAt) throw error; last = error; }
    }
    if (last) throw last;
    const f = this.feed;
    this.expires = 0;
    const profile = await this.request('stb', 'get_profile', { hd: 1, stb_type: 'MAG250', sn: f.serial ?? '',
      device_id: f.deviceId ?? '', device_id2: f.deviceId2 ?? '', auth_second_step: 1, image_version: 218,
      hw_version: '1.7-BD-00', num_banks: 2, not_valid_token: 0,
      ver: 'ImageDescription: 0.2.18-r14-pub-250; PORTAL version: 5.5.0; API Version: JS API version: 328; STB API version: 134; Player Engine version: 0x58c',
      client_type: 'STB', video_out: 'hdmi' }, this.token);
    if (profile.blocked || profile.error) throw new Error('Portal rejected the registered device profile.');
    if (f.username && !(await this.request('stb', 'do_auth', { login: f.username, password: f.password ?? '' }, this.token)).status) {
      throw new Error('Portal account login was rejected.');
    }
    this.expires = now() + 600;
    return this.token;
  }

  async call(type, action, params = {}) {
    try { return await this.request(type, action, params, await this.session()); }
    catch (error) {
      if (!error.authentication) throw error;
      return this.request(type, action, params, await this.session(true));
    }
  }

  async resolve(id, item) {
    if (!item?.cmd) throw new Error('The selected provider title has no playback command. Refresh the provider page.');
    const params = { cmd: item.cmd, series: String(item.series ?? ''), forced_storage: 'undefined', disable_ad: 0, download: 0 };
    let link = '';
    try { const result = await this.call('vod', 'create_link', params); link = result.cmd ?? result.url ?? ''; }
    catch (error) {
      if (error.retryAt) throw error;
      if (id.startsWith('series:')) {
        try { const result = await this.call('series', 'create_link', params); link = result.cmd ?? result.url ?? ''; }
        catch (other) { if (other.retryAt) throw other; }
      }
      if (!link) link = item.cmd;
      if (!/(?:^|\s)(https?:\/\/[^\s]+)/.test(link)) throw error;
    }
    const match = /(?:^|\s)(https?:\/\/[^\s]+)/.exec(link);
    if (!match) throw new Error('Provider did not return a playable video link.');
    const url = mediaUrl(match[1]);
    await publicAddresses(url);
    return url.href;
  }
}

module.exports = { StalkerProvider, itemEntry, configHash, phpJson, atomicJson, mediaUrl, publicAddresses };
