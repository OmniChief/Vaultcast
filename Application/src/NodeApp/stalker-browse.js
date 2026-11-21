'use strict';

const { enabled, parse, sha } = require('./account-data');
const { cached } = require('./profile-policy');
const { q, take, provider, groups, bound } = require('./stalker-store');
const { configHash, phpJson, itemEntry } = require('./stalker-provider');
const libraryWriter = require('./stalker-library-write');

const like = (value) => '%' + value.replace(/[!%_]/g, '!$&') + '%';

async function localMatches(pool, items, kind, title) {
  const selects = [],
    args = [];
  for (const item of items) {
    const imdb = String(item.imdb_id ?? ''),
      tmdb = String(item.tmdb_id ?? ''),
      year = Number(item.year) || 0;
    if (!imdb && !tmdb && !year) continue;
    selects.push(
      'SELECT ? AS id, CONVERT(? USING ascii) AS imdb_id, CONVERT(? USING ascii) AS tmdb_id, ' +
        'CONVERT(? USING ascii) AS title_key, CAST(? AS SIGNED) AS year',
    );
    args.push(item.catalogueId, imdb, tmdb, year ? sha(title(item.o_name ?? item.name)) : '', year);
  }
  if (!selects.length) return new Set();
  const alternatives = [
    "p.imdb_id<>'' AND l.imdb_id=p.imdb_id",
    "p.tmdb_id<>'' AND l.tmdb_id=p.tmdb_id",
    'p.year<>0 AND l.title_key=p.title_key AND l.year=p.year',
  ].map(
    (match) =>
      'EXISTS (SELECT 1 FROM vaultcast_stalker_local l JOIN vaultcast_items i ON i.id=l.item_id WHERE l.kind=? AND ' +
      match +
      " AND (p.imdb_id='' OR l.imdb_id='' OR l.imdb_id=p.imdb_id) AND (p.tmdb_id='' OR l.tmdb_id='' OR l.tmdb_id=p.tmdb_id))",
  );
  const rows = await q(
    pool,
    'SELECT p.id FROM (' + selects.join(' UNION ALL ') + ') p WHERE ' + alternatives.join(' OR '),
    [...args, kind, kind, kind],
  );
  return new Set(rows.map((row) => row.id));
}

async function indexed(pool, root, feeds, opts, libraryAllowed, decode) {
  const kind = opts.kind === 'series' ? 'series' : 'vod';
  const library = libraryWriter(decode);
  const where = ['c.kind=?'],
    args = [kind],
    joinArgs = [];
  const term = take(library.text(opts.q), 160);
  if (term) {
    where.push("c.title LIKE ? ESCAPE '!'");
    args.push(like(term));
  }
  let join = '';
  for (const [param, facet] of Object.entries({
    language: 'languages',
    audience: 'audienceLanguages',
    genre: 'genres',
  })) {
    if (!enabled(opts[param])) continue;
    const value = take(library.text(opts[param]), 80);
    if (!join) {
      join = ' JOIN vaultcast_stalker_facets f ON f.catalogue_id=c.id AND f.facet=? AND f.value=?';
      joinArgs.push(facet, value);
    } else {
      where.push(
        'EXISTS (SELECT 1 FROM vaultcast_stalker_facets f WHERE f.catalogue_id=c.id AND f.facet=? AND f.value=?)',
      );
      args.push(facet, value);
    }
  }
  if (/^[A-Z]$/.test(opts.letter ?? '')) {
    where.push('c.title LIKE ?');
    args.push(opts.letter + '%');
  }
  if (/^(19|20)\d{2}$/.test(opts.year ?? '')) {
    where.push('c.year=?');
    args.push(Number(opts.year));
  }
  if (['available', 'unavailable'].includes(opts.availability)) {
    where.push('c.available=?');
    args.push(Number(opts.availability === 'available'));
  }
  const order =
    (opts.sort === 'newest' ? 'c.year DESC,' : opts.sort === 'oldest' ? 'c.year IS NULL,c.year ASC,' : '') +
    'c.title ASC,c.id';
  const page =
    'SELECT c.id FROM vaultcast_stalker_library c' +
    join +
    ' WHERE ' +
    where.join(' AND ') +
    ' ORDER BY ' +
    order +
    ' LIMIT 49 OFFSET ' +
    bound(opts.offset, 0, 1000000);
  const rows = await q(
    pool,
    'SELECT c.id,c.available,c.unavailable_since,c.payload FROM (' +
      page +
      ') page_ids JOIN vaultcast_stalker_library c ON c.id=page_ids.id ORDER BY ' +
      order,
    [...joinArgs, ...args],
  );
  const hasMore = rows.length > 48;
  if (hasMore) rows.pop();
  const offers = new Map();
  if (rows.length) {
    const ids = rows.map((row) => row.id);
    const sources = await q(
      pool,
      'SELECT catalogue_id,feed_id,config,payload FROM vaultcast_stalker_offers WHERE available=1 AND catalogue_id IN (' +
        ids.map(() => '?').join(',') +
        ') ORDER BY feed_id,item_id',
      ids,
    );
    const active = new Map(feeds.map((feed) => [feed.id, { config: configHash(feed), name: feed.name }]));
    for (const row of sources) {
      const feed = active.get(row.feed_id);
      if (!feed || feed.config !== row.config) continue;
      if (!offers.has(row.catalogue_id)) offers.set(row.catalogue_id, []);
      offers.get(row.catalogue_id).push({ ...parse(row.payload), connection: feed.name });
    }
  }
  const items = rows.map((row) => {
    const sources = offers.get(row.id) ?? [],
      available = enabled(row.available);
    return {
      ...parse(row.payload),
      catalogueId: row.id,
      sources,
      available,
      verificationPending: !sources.length && (available || row.unavailable_since == null),
      unavailableSince: row.unavailable_since,
    };
  });
  const local = libraryAllowed ? await localMatches(pool, items, kind, library.title) : new Set();
  for (const item of items) item.local = local.has(item.catalogueId);
  const importing = await q(pool,
    "SELECT 1 FROM vaultcast_stalker_jobs WHERE kind=? AND status IN ('queued','running','partial') LIMIT 1", [kind]);
  const filters = await cached(root, 'stalker_library_filters_v3_' + kind);
  return { ok: true, items, hasMore, indexing: importing.length > 0, facets: filters?.facets ?? [], years: filters?.years ?? [] };
}

async function providerPage(pool, feed, kind, category, term, page) {
  const args = [feed.id, kind, configHash(feed)];
  let where = 's.feed_id=? AND s.kind=? AND s.config=? AND s.available=1';
  if (category && category !== '*') {
    where +=
      ' AND EXISTS (SELECT 1 FROM vaultcast_stalker_folder_items f WHERE f.feed_id=s.feed_id AND f.kind=s.kind AND f.generation=s.generation AND f.item_id=s.item_id AND f.folder_id=?)';
    args.push(category);
  }
  if (term) {
    where += " AND c.title LIKE ? ESCAPE '!'";
    args.push(like(term));
  }
  const rows = await q(
    pool,
    'SELECT s.payload FROM vaultcast_stalker_offers s JOIN vaultcast_stalker_library c ON c.id=s.catalogue_id WHERE ' +
      where +
      ' ORDER BY c.title,s.item_id LIMIT 61 OFFSET ' +
      (page - 1) * 60,
    args,
  );
  return {
    items: rows.slice(0, 60).map((row) => parse(row.payload)),
    total: null,
    hasMore: rows.length > 60,
  };
}

async function direct(pool, root, feed, kind, opts) {
  const parent = take(opts.parent, 120);
  const page = bound(opts.page ?? Math.floor(bound(opts.offset, 0, 600000) / 60) + 1, 1, 10000);
  const categories = parent
    ? []
    : await q(
        pool,
        "SELECT f.folder_id AS id,f.title AS name FROM vaultcast_stalker_folders f JOIN vaultcast_stalker_jobs j ON j.feed_id=f.feed_id AND j.kind=f.kind AND j.generation=f.generation WHERE j.feed_id=? AND j.kind=? AND j.config=? AND f.folder_id<>'' AND f.status NOT IN ('skipped','failed') ORDER BY f.title",
        [feed.id, kind, configHash(feed)],
      );
  if (enabled(opts.categories_only))
    return {
      ok: true,
      items: [],
      total: null,
      hasMore: false,
      categories,
      selectedFeed: feed.id,
      shared: false,
    };
  let result;
  if (!parent)
    result = await providerPage(
      pool,
      feed,
      kind,
      take(opts.category, 120),
      take(String(opts.q ?? '').trim(), 160),
      page,
    );
  else result = { items: [], total: 0, hasMore: false };
  for (const item of result.items) {
    item.sources = [
      Object.fromEntries(
        ['id', 'feed', 'connection', 'kind', 'playable']
          .filter((key) => Object.hasOwn(item, key))
          .map((key) => [key, item[key]]),
      ),
    ];
  }
  return {
    ...result,
    ok: true,
    facets: { category: categories.map((row) => ({ value: row.id, name: row.name })) },
    categories,
    page,
    expandedEpisodes: false,
    catalogueLoading: result.catalogueLoading ?? false,
    connections: [
      { name: feed.name, scanned: result.items.length, total: result.total, done: true, error: '' },
    ],
    selectedFeed: feed.id,
    shared: false,
    indexing: false,
  };
}
async function detail(pool, root, feed, id, decode) {
  const { seriesMetadata } = libraryWriter(decode);
  function publicItem(stored, kind) {
    const row = stored.row,
      playable = kind === 'vod' && enabled(row.cmd) && !enabled(row.is_series) && !enabled(row.series);
    return {
      ...seriesMetadata(feed, row),
      ...stored.facets,
      id: (playable ? kind + ':' : '') + row.id,
      kind,
      playable,
      showId: kind === 'series' ? String(row.id) : null,
      feed: feed.id,
      connection: feed.name,
      category: stored.category,
    };
  }
  const entry = await itemEntry(pool, root, feed, id, async (stored, kind, episode, parent, generation) => {
    if (!episode) return publicItem(stored, kind);
    let show = await cached(root, 'stalker_show_v1_' + sha(phpJson([feed, parent, generation])));
    if (!show && parent) {
      const rows = await q(
        pool,
        "SELECT payload FROM vaultcast_stalker_titles WHERE fingerprint=? AND kind='series' AND item_id=? ORDER BY playable DESC,parent_id LIMIT 1",
        [generation, parent],
      );
      if (rows.length) show = publicItem(parse(rows[0].payload), 'series');
    }
    const row = stored.row,
      meta = seriesMetadata(feed, row, show ?? {});
    let season = [row.season_number, row.season].find(
      (v) => v != null && String(v).trim() !== '' && Number.isFinite(Number(v)),
    );
    season ??=
      /(?:season|сезон|^s)\s*(\d+)/iu.exec(meta.name)?.[1] ??
      /\bS(\d{1,2})E\d+\b/i.exec(episode)?.[1] ??
      /\b(?:season|saison|staffel)\s*(\d+)\b/iu.exec(row.name ?? '')?.[1];
    season = season == null ? null : Math.trunc(Number(season));
    const seasonName = season == null ? 'Season unknown' : season === 0 ? 'Specials' : 'Season ' + season;
    const episodeTitle = String(row.episode_titles?.[episode] ?? '').trim(),
      showName = show?.name ?? meta.name;
    return {
      ...meta,
      id,
      name: showName + ' · ' + seasonName + ' · ' + (episodeTitle || 'Episode ' + episode),
      showName,
      kind: 'vod',
      playable: true,
      season,
      seasonName,
      seasonId: String(row.id),
      episode,
      episodeTitle,
      showId: show?.showId ?? show?.id ?? '',
    };
  });
  return entry ? (entry.metadata ?? { id, name: 'Provider video', kind: 'vod', playable: true }) : null;
}

async function categoryExport(root, feed, kind) {
  const client = await provider(root, feed, AbortSignal.timeout(90000));
  const response = await client.call(kind, 'get_categories');
  groups(response);
  const art = [
    'logo',
    'icon',
    'image',
    'poster',
    'cover',
    'pic',
    'screenshot_uri',
    'movie_image',
    'thumbnail',
    'thumb',
    'image_url',
    'poster_url',
    'cover_url',
  ];
  const categories = [];
  for (const row of Object.values(response.data ?? response)) {
    const category = {};
    for (const key of ['id', 'title', 'name', 'description', ...art]) {
      if (row[key] == null || typeof row[key] === 'object') continue;
      let value = take(row[key], 1000);
      if (
        /(?:^|[/?&=])(?:token|auth|key|pass|password|session|mac|cookie|signature|access_token)[=/&]/i.test(
          value,
        )
      )
        value = '[redacted]';
      if (art.includes(key)) {
        try {
          const url = new URL(value.startsWith('//') ? 'https:' + value : value);
          value = url.host ? url.pathname + ' [host/query omitted]' : value.split('?')[0];
        } catch {
          value = value.split('?')[0];
        }
      }
      category[key] = value;
    }
    if (Object.keys(category).length) categories.push(category);
  }
  return { ok: true, kind, selectedFeed: feed.id, categories, count: categories.length };
}
module.exports = { indexed, direct, categoryExport, detail };
