'use strict';

const { createHash } = require('crypto');
const { parse } = require('./account-data');
const { transaction, q, save } = require('./stalker-store');
const { liveSnapshot } = require('./stalker-live-browse');
const { entry, probe } = require('./stalker-playback');

const encode = (value) =>
  encodeURIComponent(value).replace(/[!'()*]/g, (c) => '%' + c.charCodeAt(0).toString(16).toUpperCase());
function channelUrl(feed, id) {
  return '/api/live/stalker_stream?feed=' + encode(feed) + '&channel=' + encode(id) + '&format=.m3u8';
}
function selection(value) {
  if (typeof value !== 'string' || !value.startsWith('/api/live/stalker_stream?')) return null;
  const params = new URLSearchParams(value.slice(value.indexOf('?') + 1));
  const feed = params.get('feed'),
    id = params.get('channel');
  return feed && id && value === channelUrl(feed, id) ? { feed, id } : null;
}

async function probeItems(pool, root, feeds, expired, channels) {
  const result = [],
    snapshots = new Map();
  for (const channel of channels) {
    const selected = selection(channel?.url);
    if (!selected) return null;
    const feed = feeds.find((f) => f.id === selected.feed && f.enabled);
    const error = !feed
      ? 'Portal source is unavailable.'
      : expired(feed.id)
        ? 'This provider subscription has expired.'
        : '';
    if (!error && !snapshots.has(feed.id)) snapshots.set(feed.id, await liveSnapshot(root, feed));
    const item = error ? null : await entry(pool, root, feed, selected.id, 'live', snapshots.get(feed.id));
    result.push({
      feed,
      item,
      channel: selected.id,
      url: channel.url,
      id: String(channel.id ?? ''),
      error: error || (!item ? 'Channel is unavailable in the imported catalogue.' : ''),
    });
  }
  return result;
}

async function record(pool, key, result) {
  await transaction(pool, async (db) => {
    await q(
      db,
      "INSERT INTO vaultcast_documents(name,payload) VALUES ('live_iptv_status','{}') ON DUPLICATE KEY UPDATE name=VALUES(name)",
    );
    const [row] = await q(
      db,
      "SELECT payload FROM vaultcast_documents WHERE name='live_iptv_status' FOR UPDATE",
    );
    const state = { version: 1, ...parse(row.payload) };
    state.entries = { ...state.entries, [key]: result };
    if (Object.keys(state.entries).length > 5000) {
      state.entries = Object.fromEntries(
        Object.entries(state.entries)
          .sort((a, b) => String(a[1].checkedAt ?? '').localeCompare(String(b[1].checkedAt ?? '')))
          .slice(-4000),
      );
    }
    await save(db, 'live_iptv_status', state);
  });
}

async function check(root, run, items, signal) {
  const results = [];
  for (const { feed, item, channel, url, id, error } of items) {
    signal.throwIfAborted();
    if (error) {
      results.push({ id, url, ok: false, error, status: 'bad', checkedAt: new Date().toISOString() });
      continue;
    }
    let health;
    try {
      health = await probe(root, feed, channel, item, signal);
    } catch (failure) {
      signal.throwIfAborted();
      results.push({
        id,
        url,
        ok: false,
        error: failure.retryAt ? 'Provider rate limit reached.' : 'Could not resolve the channel.',
        status: 'bad',
        checkedAt: new Date().toISOString(),
      });
      continue;
    }
    const key = createHash('sha1').update(url).digest('hex').slice(0, 24);
    const status = { ...health, url, checkedAt: new Date().toISOString() };
    await run((pool) => record(pool, key, status));
    results.push({ ...status, id, key, finalUrl: url, status: health.ok ? 'ok' : 'bad' });
  }
  return { ok: true, results, probed: results.length };
}

module.exports = { channelUrl, selection, probeItems, check };
