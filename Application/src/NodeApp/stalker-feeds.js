'use strict';

const { enabled } = require('./account-data');
const secretFields = ['mac', 'username', 'password', 'serial', 'deviceId', 'deviceId2'];

// Older PHP documents may encode a sparse or ID-keyed list as an object.
function feedRows(value) {
  if (Array.isArray(value)) return value;
  return Object.entries(value ?? {}).flatMap(([id, feed]) =>
    feed && typeof feed === 'object' ? [{ ...feed, id: feed.id || id }] : []);
}

function savedFeeds(state) {
  if (!state) return [];
  if (state.feeds == null) return [];
  if (typeof state.feeds !== 'object') return null;
  const feeds = [], ids = new Set();
  for (const [key, raw] of Object.entries(state.feeds)) {
    if (raw?.type !== 'stalker') continue;
    const feed = { ...raw, id: raw.id || key, url: String(raw.url ?? '').trim(), mac: String(raw.mac ?? '').trim().toUpperCase() };
    try { feed.name = String(raw.name ?? '').trim() || new URL(feed.url).hostname; } catch { continue; }
    for (const field of secretFields.slice(1)) feed[field] ??= '';

    if (typeof feed.id !== 'string' || !/^[\w.-]+$/.test(feed.id) || ids.has(feed.id)) continue;
    if (!/^https?:\/\//i.test(feed.url) || !/^(?:[A-F0-9]{2}:){5}[A-F0-9]{2}$/.test(feed.mac) ||
        secretFields.slice(1).some(key => typeof feed[key] !== 'string' || Buffer.byteLength(feed[key]) > 512 || /[\r\n\0]/.test(feed[key]))) {
      continue;
    }
    try {
      const url = new URL(feed.url);
      if (url.username || url.password || /[?#\r\n]/.test(feed.url)) continue;
      ids.add(feed.id);
      feeds.push(feed);
    } catch {
      continue;
    }
  }
  // Legacy cache fingerprints use the field order and defaults of iptv_normalize_feed.
  return feeds.map(feed => ({
    type: 'stalker', ...Object.fromEntries(secretFields.map(key => [key,feed[key]])),
    id: feed.id, name: feed.name, url: feed.url,
    enabled: feed.enabled == null || enabled(feed.enabled), preinstalled: enabled(feed.preinstalled),
  }));
}

module.exports = { savedFeeds, feedRows };
