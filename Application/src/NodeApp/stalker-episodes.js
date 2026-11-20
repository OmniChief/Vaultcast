'use strict';

const { now } = require('./account-data');
const { q, bound, page, provider, snapshot, saveSnapshot, episodeKey } = require('./stalker-store');
const { configHash, phpJson } = require('./stalker-provider');
const libraryWriter = require('./stalker-library-write');

const loading = new Map();
const pageKey = (parent, number) => 'page:' + phpJson([parent, number]);

// Only fetch a root already published for this connection, or a child that
// the portal returned under such a root. Old imports used the same page keys.
async function episodeBrowse(pool, root, feed, parent, number, refresh, decode, network, response) {
  const read = (key) => snapshot(root, episodeKey(feed, key));
  const saved = await read(pageKey(parent, number));
  const branch = await read('parent:' + parent);
  const rootId = String(saved?.root ?? branch?.root ?? parent);
  const rows = await q(pool,
    "SELECT item_id,generation,payload FROM vaultcast_stalker_offers WHERE feed_id=? AND kind='series' AND config=? AND available=1 AND item_id IN (?,?) ORDER BY item_id=? DESC",
    [feed.id, configHash(feed), parent, rootId, parent]);
  const show = rows[0];
  const child = show && String(show.item_id) !== parent;
  const retained = (value) => value?.episodeGeneration === show?.generation &&
    String(value?.root) === String(show?.item_id);
  if (!show || (child && !retained(branch) && !retained(saved)))
    return response.status(404).json({ ok: false, error: 'This series is no longer in the selected catalogue.' });
  const metadata = typeof show.payload === 'string' ? JSON.parse(show.payload) : show.payload;
  const send = (result) => response.json({
    ok: true, items: result.items, total: result.total, hasMore: result.hasMore,
    show: metadata, categories: [], page: number, expandedEpisodes: true,
    selectedFeed: feed.id, shared: false, catalogueLoading: false,
  });
  if (!refresh && retained(saved) && (!saved.fetchedAt || saved.fetchedAt > now() - 21600)) return send(saved);
  return network(response, async () => {
    const key = episodeKey(feed, pageKey(parent, number)) + ':' + show.generation;
    let pending = loading.get(key);
    if (!pending) {
      if (loading.size >= 12) throw Object.assign(new Error('Episode requests are busy. Try again shortly.'), { status: 503, expose: true });
      pending = fetchPage(root, feed, parent, number, show, metadata, decode)
        .finally(() => loading.delete(key));
      loading.set(key, pending);
    }
    try { return send(await pending); }
    catch (error) {
      if (retained(saved) && !error.authentication) return send(saved);
      throw error;
    }
  });
}

async function fetchPage(root, feed, parent, number, show, metadata, decode) {
  const client = await provider(root, feed, AbortSignal.timeout(60000));
  const result = await client.ordered('series',
    { sortby: 'name', category: '*', search: '', movie_id: parent }, number, page);
  const { rows, total } = page(result);
  const generation = { episodeGeneration: show.generation, root: String(show.item_id) };
  const write = (key, value) => saveSnapshot(root, episodeKey(feed, key), { ...value, ...generation });
  const library = libraryWriter(decode);
  const items = [];
  for (const row of rows) {
    const id = String(row.id), cmd = typeof row.cmd === 'string' ? row.cmd : '';
    const numbers = Array.isArray(row.series) ? row.series :
      row.series && typeof row.series === 'object' ? Object.values(row.series) : [];
    const seasonText = String(row.season_number ?? row.season ?? '');
    const season = /^\d+$/.test(seasonText) ? Number(seasonText) :
      Number(/(?:season\s*|\bS)(\d+)/i.exec(row.name ?? '')?.[1] ?? NaN);
    const common = { ...library.seriesMetadata(feed, row, metadata), ...library.descriptive(row),
      feed: feed.id, connection: feed.name, showId: String(show.item_id), showName: metadata.name };
    if (Number.isFinite(season)) Object.assign(common, { season, seasonName: season ? 'Season ' + season : 'Specials' });
    const isFolder = !cmd || /^(?:1|true|yes)$/i.test(String(row.is_series));
    if (isFolder && !(numbers.length && cmd)) {
      if (id === parent || id === String(show.item_id)) continue;
      items.push({ ...common, id, kind: 'series', playable: false, hasChildren: true });
      await write('parent:' + id, {});
      continue;
    }
    if (items.length + Math.max(numbers.length, 1) > 5000)
      throw new Error('Provider episode page exceeds the item limit.');
    for (const value of numbers.length ? numbers : [row.series_number ?? '']) {
      client.signal.throwIfAborted();
      if (value == null || typeof value === 'object') continue;
      const episode = String(value);
      const publicId = 'series:' + id + (numbers.length ? ':' + episode : '');
      const episodeTitle = String(row.episode_titles?.[episode] ?? (!numbers.length ? row.name ?? '' : ''));
      const item = { ...common, id: publicId, kind: 'vod', playable: true,
        episode, episodeTitle, seasonId: id,
        name: numbers.length ? metadata.name + ' · ' + (episodeTitle || 'Episode ' + episode) : common.name };
      await write('item:' + publicId, { cmd, series: episode, metadata: item });
      items.push(item);
    }
  }
  // The final page can be shorter. Use the first page size for portals
  // that omit max_page_items.
  const first = number > 1 && !result.max_page_items
    ? await snapshot(root, episodeKey(feed, pageKey(parent, 1))) : null;
  const perPage = bound(result.max_page_items || first?.perPage || rows.length, 1, 100000);
  const value = { ...generation, items, total, perPage, fetchedAt: now(),
    hasMore: rows.length > 0 && (total == null ? rows.length >= perPage : number * perPage < total) };
  await write(pageKey(parent, number), value);
  return value;
}

module.exports = { episodeBrowse };
