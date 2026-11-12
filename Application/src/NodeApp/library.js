'use strict';

const { parse, now, enabled, documents } = require('./account-data');
const { authorized, cached, parentalStatus } = require('./profile-policy');

const list = value => value && typeof value === 'object' ? Object.values(value) : [];
const int = value => Number.parseInt(value, 10) || 0;
const lower = value => String(value ?? '').replace(/[A-Z]/g, letter => letter.toLowerCase());
const queryText = value => typeof value === 'string' ? value.trim() : '';
const alphabet = () => Object.fromEntries(Array.from('#ABCDEFGHIJKLMNOPQRSTUVWXYZ', letter => [letter, 0]));
const initial = title => {
  const letter = String(title ?? '').trim()[0]?.toUpperCase();
  return /^[A-Z]$/.test(letter ?? '') ? letter : '#';
};

const ratingAliases = [
  'G|U|UC|TV-Y|TV-Y7|TV-Y7-FV|TV-G|ALL|AL|L|TP',
  'PG|TV-PG|GP|M/PG',
  'PG-13|12|12A|UA|U/A',
  'TV-14|AA|15|M|MA15+|MA 15+',
  'R|NC-17|A|X|18|R18|R18+|TV-MA|R-RATED',
];
const ratingBuckets = new Map(ratingAliases.flatMap((group, index) => group.split('|').map(value => [value, index + 1])));

function ratingOf(row) {
  if (row.source === 'manual') return Number(row.bucket);
  const raw = String(row.raw ?? '').trim().toUpperCase()
    .replace(/^(?:RATED|CERTIFICATE|CERT|FSK|BBFC|MPAA|ACB|USK)\s*:?\s*/, '').replace(/[–—_]/g, '-').trim();
  if (ratingBuckets.has(raw)) return ratingBuckets.get(raw);
  const match = raw.match(/\d{1,2}/);
  if (!match) return 0;
  const age = Number(match[0]);
  return age <= 6 ? 1 : age <= 10 ? 2 : age <= 12 ? 3 : age <= 15 ? 4 : 5;
}

async function visibleCards(pool, cards, parental) {
  if (!parental.restricted) return cards;
  const targets = cards.map(card => card.kind === 'show' ? 'show:' + card.show_key
    : card.media_kind === 'video' ? 'item:' + card.id : null);
  const wanted = [...new Set(targets.filter(Boolean))];
  if (!wanted.length) return cards;
  const [rows] = await pool.execute('SELECT target,bucket,raw,source FROM vaultcast_content_ratings WHERE bucket>0 AND target IN (' +
    wanted.map(() => '?').join(',') + ')', wanted);
  const ratings = new Map(rows.map(row => [row.target, ratingOf(row)]));
  return cards.filter((_card, index) => {
    if (!targets[index]) return true;
    const bucket = ratings.get(targets[index]) || 0;
    return bucket === 0 ? parental.settings.unrated !== 'hide' : bucket <= parental.settings.ceiling;
  });
}

function areaAllowed(user, area, parental) {
  if (user.role === 'admin') return true;
  if (parental.restricted && ((area === 'live' && !parental.settings.live) ||
      (area === 'games' && !parental.settings.play))) return false;
  return !user.areas || typeof user.areas !== 'object' || enabled(user.areas[area]);
}

function itemArea(section) {
  return section.type === 'roms' ? 'games' : 'library';
}

function areaDenied(response, area) {
  return response.status(403).json({ ok: false, code: 'area_denied', area,
    error: 'Your account does not have access to ' + (area === 'games' ? 'Games' : 'Library') + '.' });
}

function fixText(value) {
  return String(value ?? '').replace(/ÔÇö/g, '—').replace(/ÔÇô/g, '–').replace(/ÔÇª/g, '…')
    .replace(/(?:\s*[—–-]\s*)+$/, '').replace(/\s{2,}/g, ' ').trim();
}

function systemSlug(system, core) {
  const name = String(system ?? '').normalize('NFKD').replace(/\p{M}/gu, '')
    .replace(/&/g, ' and ').replace(/\+/g, ' plus ').replace(/['’"]/g, '')
    .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80).replace(/-+$/, '');
  if (name && !['roms', 'rom', 'games', 'game', 'lobby'].includes(name)) return name;
  const cores = { segaMD: 'genesis', nes: 'nes', snes: 'snes', n64: 'n64', gb: 'gameboy', gba: 'gba',
    nds: 'nds', psx: 'playstation', segaGG: 'gamegear', sms: 'mastersystem', sega32x: 'sega-32x',
    segaCD: 'sega-cd', pce: 'pc-engine', '3ds': '3ds' };
  return Object.hasOwn(cores, core) ? cores[core] : 'games';
}

function reverseSlugs(registry, kind) {
  const result = new Map();
  for (const [slug, id] of Object.entries(registry?.[kind] ?? {})) {
    if (!result.has(String(id))) result.set(String(id), slug);
  }
  return result;
}

function itemCard(row, item, settings, movieSlugs, gameSlugs) {
  const video = item.media_kind === 'video';
  const rom = item.media_kind === 'rom';
  const facts = row.sig === int(item.mtime) + '|' + int(item.size);
  const preferred = enabled(item.directplay_path) && enabled(item.directplay_ready) && enabled(settings.convert?.prefer_directplay ?? true);
  const ext = lower(item.ext);
  return {
    id: item.id, section: item.section, type: item.type, title: fixText(item.title), episode_title: null,
    year: item.year ?? null, season: item.season ?? null, episode: item.episode ?? null,
    show: item.show ? fixText(item.show) : item.show ?? null, show_key: null, show_slug: null,
    slug: ['movies', 'disney'].includes(item.type) || (video && !item.show) ? movieSlugs.get(String(item.id)) ?? item.id : null,
    game_path: rom ? gameSlugs.get(String(item.id)) ?? (systemSlug(item.system, item.emu_core) + '/' + item.id) : null,
    ext: item.ext, size: item.size, duration: video && facts ? Number(row.duration) || 0 : 0,
    mtime: item.mtime, media_kind: item.media_kind, emu_core: item.emu_core ?? null, system: item.system ?? null,
    has_poster: true, kind: 'item',
    needs_convert: video && !preferred && (facts ? enabled(row.needs_convert)
      : Boolean(ext && !['mp4', 'm4v', 'webm', 'mp3', 'aac', 'flac'].includes(ext))),
  };
}

function showCard(row, slugs) {
  const key = row.show_key ?? row.id;
  return {
    id: key, show_key: key, kind: 'show', section: row.section, type: row.type,
    title: row.title, year: row.year ?? null, slug: row.slug ?? slugs.get(String(key)) ?? key,
    episode_count: row.episode_count ?? 0, available_count: row.available_count ?? null,
    missing_count: row.missing_count ?? null, season_count: row.season_count ?? 0,
    has_poster: enabled(row.has_poster), media_kind: 'show',
    genres: list(row.genres).map(String), status: row.status ?? null, network: row.network ?? null,
  };
}

function count(map, value) {
  const key = String(value ?? '').trim();
  if (key) map.set(key, (map.get(key) || 0) + 1);
}

function showFacets(shows) {
  const years = new Map(), decades = new Map(), genres = new Map(), statuses = new Map(), networks = new Map();
  for (const show of shows) {
    const year = int(show.year);
    if (year >= 1900 && year <= 2100) {
      count(years, year);
      count(decades, (Math.floor(year / 10) * 10) + 's');
    }
    for (const genre of list(show.genres)) count(genres, genre);
    count(statuses, show.status);
    count(networks, show.network);
  }
  const order = (map, compare) => map.size ? Object.fromEntries([...map].sort(compare)) : [];
  const numeric = ([left], [right]) => int(right) - int(left);
  const popularity = ([, left], [, right]) => right - left;
  return { years: order(years, numeric), decades: order(decades, numeric), genres: order(genres, popularity),
    statuses: order(statuses, popularity), networks: order(networks, popularity) };
}

function filtersFor(query, names) {
  return Object.fromEntries(names.map(name => [name, queryText(query[name]) || null]));
}

function showMatches(show, filters, search) {
  const year = int(show.year);
  const decade = int(String(filters.decade ?? '').replace(/\D/g, ''));
  return (!filters.year || year === int(filters.year)) &&
    (!decade || (year >= decade && year <= decade + 9)) &&
    (!filters.genre || list(show.genres).some(genre => lower(genre) === lower(filters.genre))) &&
    (!filters.status || lower(show.status) === lower(filters.status)) &&
    (!filters.network || lower(show.network) === lower(filters.network)) &&
    (!search || lower(show.title).includes(search));
}

async function movieFacets(root, pool, section) {
  const [rows] = await pool.execute("SELECT name,revision FROM vaultcast_documents WHERE name IN ('catalogue','browse_epoch','settings')");
  const revisions = Object.fromEntries(rows.map(row => [row.name, row.revision]));
  const revision = 'v4|' + ['catalogue', 'browse_epoch', 'settings'].map(name => revisions[name] ?? 0).join('|');
  const prepared = await cached(root, 'browse-current|facets_movies|' + section);
  if (prepared?.revision === revision && prepared.payload?.facets) {
    return { facets: prepared.payload.facets, facets_pending: false };
  }
  const previous = await cached(root, 'movie-facets-last|' + section);
  return {
    facets: previous?.facets ?? { years: [], decades: [], genres: [] },
    facets_pending: true,
  };
}

async function indexedItems(pool, section, query, page, per, letter) {
  const system = queryText(query.system);
  const search = queryText(query.q);
  let where = 'i.section_id=?';
  const params = [section];
  if (system) { where += ' AND i.system_name=?'; params.push(system); }
  if (search) {
    const needle = '%' + search.replace(/[!%_]/g, char => '!' + char) + '%';
    where += " AND (i.title LIKE ? ESCAPE '!' OR i.show_title LIKE ? ESCAPE '!' OR i.system_name LIKE ? ESCAPE '!' OR JSON_UNQUOTE(JSON_EXTRACT(i.payload,'$.rel')) LIKE ? ESCAPE '!')";
    params.push(needle, needle, needle, needle);
  }
  const [systemRows] = await pool.execute("SELECT system_name,COUNT(*) AS n FROM vaultcast_items WHERE section_id=? AND system_name<>'' GROUP BY system_name ORDER BY system_name", [section]);
  const [letterRows] = await pool.execute('SELECT i.initial,COUNT(*) AS n FROM vaultcast_items i WHERE ' + where + ' GROUP BY i.initial', params);
  const letters = alphabet();
  for (const row of letterRows) letters[row.initial] = Number(row.n);
  const selected = /^[A-Z#]$/.test(letter);
  const total = selected ? letters[letter] || 0 : Object.values(letters).reduce((sum, n) => sum + n, 0);
  if (selected) { where += ' AND i.initial=?'; params.push(letter); }
  const offset = (page - 1) * per;
  const [rows] = offset >= total ? [[]] : await pool.execute(
    'SELECT i.payload,f.sig,f.duration,f.needs_convert FROM (SELECT i.id,i.title FROM vaultcast_items i WHERE ' +
    where + ' ORDER BY i.title,i.id LIMIT ' + per + ' OFFSET ' + offset +
    ') p JOIN vaultcast_items i ON i.id=p.id LEFT JOIN vaultcast_media_facts f ON f.item_id=i.id ORDER BY p.title,p.id', params);
  return { rows, total, letters, system: system || null,
    systems: systemRows.length ? Object.fromEntries(systemRows.map(row => [row.system_name, Number(row.n)])) : [] };
}

module.exports = function mountLibrary(app, prefix, { root, handle, unavailable }) {
  const browse = operation => handle(async (request, response, pool, _bcrypt, settings, current) => {
    if (!current.user && settings.auth?.lan_guest_access !== false) return unavailable(response);
    if (!current.user && !current.authenticated) {
      return response.status(401).json({ ok: false, error: 'Sign in to continue.', code: 'login_required' });
    }
    if (!authorized(response, current)) return;
    const docs = await documents(pool, ['catalogue']);
    const parental = await parentalStatus(root, pool, current, current.user, now());
    return operation(request, response, pool, settings, current.user, docs.catalogue ?? {}, parental);
  }, true);

  app.get(prefix + '/sections', browse(async (_request, response, _pool, _settings, user, catalogue, parental) => {
    const sections = list(catalogue.sections).filter(section => section && areaAllowed(user, itemArea(section), parental))
      .map(({ path, ...section }) => section);
    response.json({ ok: true, sections, scanned_at: catalogue.scanned_at ?? null });
  }));

  app.get(prefix + '/resolve', browse(async (request, response, pool, _settings, user, _catalogue, parental) => {
    const raw = queryText(request.query.path);
    let path = raw.split(/[?#]/, 1)[0];
    if (/^(?:https?:)?\/\//i.test(path)) {
      try { path = new URL(path, 'http://localhost').pathname; } catch (error) { path = ''; }
    }
    const parts = path.split('/').filter(Boolean).map(segment => {
      try { return decodeURIComponent(segment); } catch (error) { return segment; }
    });
    const [head, slug, tail, episode] = parts;
    const kind = { movies: 'movie', tvshows: 'show', games: 'game' }[head];
    if (!['movie', 'show', 'game'].includes(kind) || !slug || (kind === 'game' && slug === 'lobby')) {
      return response.json({ ok: false, error: 'Not a slug route', view: 'unknown' });
    }
    const area = kind === 'game' ? 'games' : 'library';
    if (!areaAllowed(user, area, parental)) return areaDenied(response, area);
    if (kind === 'game' && !tail) {
      const system = lower(slug);
      let label = '';
      if (system !== 'games') {
        const [rows] = await pool.execute("SELECT system_name,JSON_UNQUOTE(JSON_EXTRACT(payload,'$.emu_core')) AS core FROM vaultcast_items WHERE media_kind='rom' GROUP BY system_name,core");
        const match = rows.find(row => systemSlug(row.system_name, row.core) === system);
        if (!match) return unavailable(response);
        label = match.system_name;
      }
      return response.json({ ok: true, view: 'games_system', system: label, system_slug: system });
    }
    const registry = (await documents(pool, ['slugs'])).slugs;
    const key = lower(kind === 'game' ? slug + '/' + tail : slug);
    const map = registry?.[kind] ?? {};
    let id = Object.hasOwn(map, key) ? map[key] : null;
    const rawId = kind === 'game' ? lower(tail) : key;
    if (!id && /^[a-f0-9]{8,64}$/.test(rawId)) {
      if (kind === 'show') id = rawId;
      else {
        const [rows] = await pool.execute('SELECT id FROM vaultcast_items WHERE id=?', [rawId]);
        id = rows[0]?.id;
      }
    }
    if (!id) return response.json({ ok: false, error: kind[0].toUpperCase() + kind.slice(1) + ' not found', view: 'notfound' });
    if (kind === 'movie') return response.json({ ok: true, view: 'movie', movie_id: id });
    if (kind === 'game') return response.json({ ok: true, view: 'game', item_id: id });
    const season = tail?.match(/^season-(\d{1,3})$/);
    const number = season && episode?.match(/^episode-(\d{1,4})(?:-|$)/);
    const item = season && !number && /^[a-f0-9]{8,64}$/.test(episode ?? '') ? episode : null;
    return response.json({ ok: true, view: 'show', show_key: id,
      season: season ? Number(season[1]) : null, episode: number ? Number(number[1]) : null, item_id: item });
  }));

  app.get(prefix + '/sections/:section([A-Za-z0-9_-]+)/:mode(items|shows)', browse(async (request, response, pool, settings, user, catalogue, parental) => {
    const { section: id, mode } = request.params;
    const section = list(catalogue.sections).find(entry => entry?.id === id);
    const limited = ['library', 'live', 'games'].some(area => !areaAllowed(user, area, parental));
    const configured = list(settings.sections).find(entry => entry?.id === id);
    if (limited && !configured) return response.status(404).json({ ok: false, error: 'Section not found' });
    if (!section || (configured && itemArea(configured) !== itemArea(section))) return unavailable(response);
    const area = itemArea(section);
    if (!areaAllowed(user, area, parental)) return areaDenied(response, area);
    const query = request.query;
    const per = Math.max(1, Math.min(100, query.per === undefined ? 48 : int(query.per)));
    const page = Math.max(1, Math.min(Math.floor(Number.MAX_SAFE_INTEGER / per), int(query.page)));
    const letter = queryText(query.letter).toUpperCase();
    const envelope = { ok: true, page, per, letter: letter || null, cached: false };

    if (mode === 'items') {
      const movie = ['movies', 'disney'].includes(section.type);
      const filters = filtersFor(query, ['genre', 'year', 'decade', 'actor']);
      if ((!movie && !['roms', 'music'].includes(section.type)) || (movie && Object.values(filters).some(Boolean))) return unavailable(response);
      const { rows, ...result } = await indexedItems(pool, id, query, page, per, letter);
      const items = rows.map(row => parse(row.payload));
      if (items.some(item => !item || ['tv', 'anime'].includes(item.type) || (item.show && Object.hasOwn(item, 'season')))) return unavailable(response);
      const slugs = items.length ? (await documents(pool, ['slugs'])).slugs : null;
      const movieSlugs = reverseSlugs(slugs, 'movie'), gameSlugs = reverseSlugs(slugs, 'game');
      const cards = rows.map((row, index) => itemCard(row, items[index], settings, movieSlugs, gameSlugs));
      const facets = movie ? await movieFacets(root, pool, id) : { facets: {} };
      return response.json({ ...envelope, ...result, ...facets, has_more: page * per < result.total,
        filters: { genre: null, year: null, decade: null, actor: null },
        items: await visibleCards(pool, cards, parental) });
    }

    if (!['tv', 'anime'].includes(section.type)) return unavailable(response);
    const docs = await documents(pool, ['browse-index-ready', 'slugs']);
    if (!enabled(docs['browse-index-ready'])) return unavailable(response);
    const [rows] = await pool.execute('SELECT payload FROM vaultcast_show_summaries WHERE section_id=? ORDER BY title,show_key LIMIT 10001', [id]);
    const shows = rows.map(row => parse(row.payload));
    if (shows.length > 10000 || shows.some(show => !show || !show._meta_prepared)) return unavailable(response);
    const filters = filtersFor(query, ['genre', 'year', 'decade', 'status', 'network']);
    const letters = alphabet();
    const search = lower(queryText(query.q));
    const matches = shows.filter(show => showMatches(show, filters, search));
    for (const show of matches) letters[initial(show.title)]++;
    const picked = letter ? matches.filter(show => initial(show.title) === letter) : matches;
    const slugs = reverseSlugs(docs.slugs, 'show');
    const cards = picked.slice((page - 1) * per, page * per).map(show => showCard(show, slugs));
    response.json({
      ...envelope, total: picked.length, has_more: page * per < picked.length, letters,
      facets: showFacets(shows), filters, shows: await visibleCards(pool, cards, parental),
    });
  }));
};
