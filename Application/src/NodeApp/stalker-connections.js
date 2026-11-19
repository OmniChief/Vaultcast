'use strict';

const { randomBytes } = require('crypto');
const { enabled, now, parse } = require('./account-data');
const { configHash, mediaUrl } = require('./stalker-provider');
const { q, save, notify, transaction } = require('./stalker-store');

const invalid = (message) => Object.assign(new Error(message), { userError: true });
const privateFields = ['mac', 'username', 'password', 'serial', 'deviceId', 'deviceId2'];

function connection(id, input, old) {
  const url = String(input.url ?? '').trim();
  let portal;
  try {
    portal = mediaUrl(url);
  } catch {
    throw invalid('Enter an HTTP or HTTPS portal URL without embedded credentials.');
  }
  if (!/^https?:\/\//i.test(url) || /[?#\\]/.test(url))
    throw invalid('Enter a portal URL without a query or fragment.');
  const result = { type: 'stalker' };
  for (const key of privateFields) {
    const value = input[key];
    if (value != null && typeof value !== 'string') throw invalid('Invalid portal device or account field.');
    result[key] =
      (value == null || value === '') && !enabled(input.replaceCredentials)
        ? (old?.[key] ?? '')
        : (value ?? '');
    if (Buffer.byteLength(result[key]) > 512 || /[\r\n\0]/.test(result[key]))
      throw invalid('Invalid portal device or account field.');
  }
  result.mac = result.mac.trim().toUpperCase();
  if (!/^(?:[A-F0-9]{2}:){5}[A-F0-9]{2}$/.test(result.mac))
    throw invalid('Enter the MAC address registered with your provider.');
  return {
    ...result,
    id,
    name: String(input.name ?? '').trim() || portal.hostname,
    url,
    enabled: input.enabled == null || input.enabled === true,
    preinstalled: enabled(old?.preinstalled),
  };
}

// Use the same initial lineup as iptv_load, including the one-time public source.
function initialise(state) {
  const defaultFeed = {
    id: 'iptv-org',
    name: 'iptv-org (free public channels)',
    url: 'https://iptv-org.github.io/iptv/index.m3u',
    enabled: true,
    preinstalled: true,
  };
  state.feeds ??= [];
  if (!Array.isArray(state.feeds)) throw invalid('The saved IPTV connections could not be read.');
  state.seededDefaults = { ...state.seededDefaults };
  if (!enabled(state.seededDefaults[defaultFeed.id])) {
    state.seededDefaults[defaultFeed.id] = new Date().toISOString();
    if (
      !state.feeds.some(
        (feed) =>
          feed.id === defaultFeed.id ||
          String(feed.url ?? '')
            .toLowerCase()
            .replace(/\/+$/, '') === defaultFeed.url,
      )
    )
      state.feeds.push(defaultFeed);
  }
  state.activeFeedId ||= state.feeds[0]?.id ?? 'all';
  state.channels ??= [];
  state.epgUrls ??= [];
  state.numberStart ??= 100;
  state.defaultCountry ??= 'uk';
  state.version = Math.max(Number(state.version) || 0, 2);
}

async function manage(pool, body) {
  const operation = body.operation ?? 'save',
    id = body.id || 'stalker-' + randomBytes(6).toString('hex');
  if (!['save', 'remove'].includes(operation)) throw invalid('Unknown connection operation.');
  if (typeof id !== 'string' || !/^[a-zA-Z0-9_.-]+$/.test(id)) throw invalid('Invalid connection ID.');
  return transaction(pool, async (db) => {
    await q(db, "INSERT INTO vaultcast_documents(name,payload) VALUES ('live_iptv','{}') ON DUPLICATE KEY UPDATE name=VALUES(name)");
    const [row] = await q(db, "SELECT payload FROM vaultcast_documents WHERE name='live_iptv' FOR UPDATE");
    const state = { ...parse(row.payload) };
    initialise(state);
    const index = state.feeds.findIndex((feed) => feed.id === id),
      old = state.feeds[index];
    if (old && old.type !== 'stalker') throw invalid('This is not a Stalker connection.');
    const next = operation === 'save' ? connection(id, body.connection ?? {}, old) : null;
    if (next && index < 0) state.feeds.push(next);
    else if (next) state.feeds[index] = next;
    else if (old) state.feeds.splice(index, 1);
    if (!next && state.activeFeedId === id) state.activeFeedId = 'all';
    state.updatedAt = new Date().toISOString();
    await save(db, 'live_iptv', state);
    if (old && !next?.enabled)
      await q(db, "UPDATE vaultcast_stalker_jobs SET status='removed',next_run=? WHERE feed_id=?", [
        now(),
        id,
      ]);
    else if (next?.enabled && (!old || !enabled(old.enabled) || configHash(next) !== configHash(old))) {
      await q(db, "UPDATE vaultcast_stalker_jobs SET status='scan_required',next_run=0 WHERE feed_id=?", [
        id,
      ]);
      await notify(db, next);
    }
    return { ok: true };
  });
}

async function check(client) {
  await client.session(true);
  let reported = 0,
    sample = 0;
  function inspect(result, bulk) {
    const data = result.data ?? (bulk ? result : null);
    if (!data || typeof data !== 'object') throw new Error('The provider returned an invalid channel page.');
    sample = Object.keys(data).length;
    const total = Number(result.total_items);
    if (Number.isFinite(total)) reported = Math.max(reported, Math.trunc(total));
  }
  for (const p of [0, 1]) {
    try {
      inspect(
        await client.call('itv', 'get_ordered_list', { sortby: 'number', genre: '*', search: '', fav: 0, p }),
        false,
      );
    } catch (error) {
      if (error.retryAt || client.signal?.aborted) throw error;
    }
    if (sample) break;
  }
  if (!sample) inspect(await client.call('itv', 'get_all_channels'), true);
  const expiry = await client.accountExpiry();
  return {
    ok: true,
    expiryDate: expiry.date,
    expiryTime: expiry.time,
    expiryTimeZone: expiry.timeZone,
    expiryStatus: expiry.status,
    expirySources: expiry.sources,
    channelCount: reported || sample || null,
    sampleCount: sample,
    countEstimated: !reported,
    endpoint: client.endpoint,
  };
}
module.exports = { manage, check, invalid };
