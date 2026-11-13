'use strict';

const { createHash, randomInt } = require('crypto');
const { readFile } = require('fs').promises;
const { join } = require('path');
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
  const targets = cards.map(card => {
    if (card.kind === 'show') return ['show:' + card.show_key];
    if (card.media_kind !== 'video') return [];
    const item = 'item:' + card.id;
    return card.show_key ? ['show:' + card.show_key, item] : [item];
  });
  const wanted = [...new Set(targets.flat())];
  if (!wanted.length) return cards;
  const [rows] = await pool.execute('SELECT target,bucket,raw,source FROM vaultcast_content_ratings WHERE bucket>0 AND target IN (' +
    wanted.map(() => '?').join(',') + ')', wanted);
  const ratings = new Map(rows.map(row => [row.target, ratingOf(row)]));
  return cards.filter((_card, index) => {
    if (!targets[index].length) return true;
    const bucket = targets[index].map(target => ratings.get(target)).find(Boolean) || 0;
    return bucket === 0 ? parental.settings.unrated !== 'hide' : bucket <= parental.settings.ceiling;
  });
}

async function playbackVisible(pool, item, parental) {
  if (!parental.restricted || item.media_kind !== 'video') return true;
  let showKey = typeof item.show_key === 'string' && enabled(item.show_key) ? item.show_key : '';
  if (!showKey && ['tv', 'anime'].includes(item.type) && String(item.show ?? '') !== '' && item.section != null) {
    showKey = createHash('sha1').update(String(item.section) + '|' + item.show).digest('hex').slice(0, 16);
  }
  return (await visibleCards(pool, [{ id: item.id, media_kind: item.media_kind, show_key: showKey }], parental)).length > 0;
}

function areaAllowed(user, area, parental) {
  if (user.role === 'admin') return true;
  if (parental?.restricted && ((area === 'live' && !parental.settings.live) ||
      (area === 'games' && !parental.settings.play))) return false;
  return !user.areas || typeof user.areas !== 'object' || enabled(user.areas[area]);
}

function itemArea(section) {
  return section.type === 'roms' || section.media_kind === 'rom' ? 'games' : 'library';
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

function mediaSignature(item) {
  return int(item.mtime) + '|' + int(item.size);
}

function isEpisode(item) {
  return ['tv', 'anime'].includes(item.type) || (item.show && Object.hasOwn(item, 'season'));
}

function itemCard(row, item, settings, movieSlugs, gameSlugs) {
  const video = item.media_kind === 'video';
  const rom = item.media_kind === 'rom';
  const facts = row.sig === mediaSignature(item);
  const preferred = enabled(item.directplay_path) && enabled(item.directplay_ready) && enabled(settings.convert?.prefer_directplay ?? true);
  const ext = lower(item.ext);
  const savedEpisodeTitle = ['tv', 'anime'].includes(item.type) ? fixText(item.episode_title) : '';
  const episodeTitle = savedEpisodeTitle &&
    !/\b(?:1080p|720p|WEB-?DL|x264|x265|DSNP|MeM\.?GP)\b|\.(?:1080p|WEB|BluRay)/i.test(savedEpisodeTitle)
    ? savedEpisodeTitle : null;
  return {
    id: item.id, section: item.section, type: item.type, title: fixText(item.title), episode_title: episodeTitle,
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

async function showcaseItems(pool, section, ids, settings, registry) {
  if (!ids.length) return [];
  const [rows] = await pool.execute(
    'SELECT i.id,i.payload,f.sig,f.duration,f.needs_convert FROM vaultcast_items i LEFT JOIN vaultcast_media_facts f ON f.item_id=i.id WHERE i.section_id=? AND i.id IN (' +
    ids.map(() => '?').join(',') + ')', [section, ...ids]);
  const movies = reverseSlugs(registry, 'movie');
  const games = reverseSlugs(registry, 'game');
  const byId = new Map(rows.map(row => [row.id, itemCard(row, parse(row.payload), settings, movies, games)]));
  return ids.map(id => byId.get(id));
}

function sample(ids, size) {
  for (let index = 0; index < Math.min(ids.length, size); index++) {
    const other = randomInt(index, ids.length);
    [ids[index], ids[other]] = [ids[other], ids[index]];
  }
  return ids.slice(0, size);
}

async function recentCards(pool, section, count, settings, registry) {
  // Group on the existing browse index. Resolve full episode payloads only for the selected cards.
  const [groups] = await pool.execute(
    "SELECT show_key,MIN(NULLIF(modified_at,0)) AS oldest,MAX(modified_at) AS newest " +
    "FROM vaultcast_browse_items WHERE section_id=? AND show_key<>'' GROUP BY show_key HAVING MAX(modified_at)>0 LIMIT 10001", [section]);
  if (groups.length > 10000) return null;
  const time = now();
  const newShows = [], episodes = [];
  for (const group of groups) {
    const oldest = Number(group.oldest ?? group.newest);
    const newest = Number(group.newest);
    if (oldest > time - 60 * 86400 && newest - oldest < 21 * 86400) newShows.push({ ...group, sort: oldest, show: true });
    else episodes.push({ ...group, sort: newest, show: false });
  }
  const newestFirst = (a, b) => b.sort - a.sort || a.show_key.localeCompare(b.show_key);
  newShows.sort(newestFirst);
  episodes.sort(newestFirst);
  const showCap = Math.max(4, Math.ceil(count * 0.4));
  const picked = [...newShows.slice(0, showCap), ...episodes, ...newShows.slice(showCap)]
    .slice(0, count).sort(newestFirst);
  const showKeys = picked.filter(group => group.show).map(group => group.show_key);
  const episodeGroups = picked.filter(group => !group.show);
  const slugs = reverseSlugs(registry, 'show');
  const shows = new Map(), latest = new Map();
  if (showKeys.length) {
    const [rows] = await pool.execute('SELECT show_key,payload FROM vaultcast_show_summaries WHERE section_id=? AND show_key IN (' +
      showKeys.map(() => '?').join(',') + ')', [section, ...showKeys]);
    for (const row of rows) {
      const show = parse(row.payload);
      if (!show?._meta_prepared) return null;
      shows.set(row.show_key, showCard(show, slugs));
    }
  }
  if (episodeGroups.length) {
    const [rows] = await pool.execute(
      'SELECT show_key,item_id FROM (SELECT show_key,item_id,ROW_NUMBER() OVER (PARTITION BY show_key ORDER BY modified_at DESC,title DESC,item_id DESC) AS position ' +
      'FROM vaultcast_browse_items WHERE section_id=? AND show_key IN (' + episodeGroups.map(() => '?').join(',') + ')) ranked WHERE position=1',
      [section, ...episodeGroups.map(group => group.show_key)]);
    const cards = await showcaseItems(pool, section, rows.map(row => row.item_id), settings, registry);
    for (const [index, row] of rows.entries()) latest.set(row.show_key, cards[index]);
  }
  const cards = [];
  for (const group of picked) {
    const card = group.show ? shows.get(group.show_key) : latest.get(group.show_key);
    if (!card) return null;
    if (!group.show) {
      card.show_key = group.show_key;
      card.show_slug = slugs.get(group.show_key) ?? group.show_key;
      card.slug = null;
    }
    cards.push({ ...card, mtime: Number(group.newest),
      showcase_kind: group.show ? 'new_show' : 'new_episode', showcase_label: group.show ? 'New show' : 'New episode' });
  }
  return cards;
}

function episodeLabel(show, season, episode, name) {
  const code = 'S' + String(season).padStart(2, '0') + 'E' + String(episode).padStart(2, '0');
  return fixText([show, code, name].filter(Boolean).join(' — '));
}

async function showDetail(root, pool, summary, settings, parental) {
  const key = summary.show_key;
  const guideKey = 'tvmaze-guide:' + key;
  const docs = await documents(pool, ['browse-index-ready', 'slugs', guideKey]);
  if (!enabled(docs['browse-index-ready'])) return null;

  const [rows] = await pool.execute(
    'SELECT i.payload,f.sig,f.duration,f.needs_convert FROM vaultcast_browse_items b ' +
    'JOIN vaultcast_items i ON i.id=b.item_id LEFT JOIN vaultcast_media_facts f ON f.item_id=i.id ' +
    'WHERE b.show_key=? AND b.section_id=? AND i.section_id=b.section_id ORDER BY i.title,i.id LIMIT 5001',
    [key, summary.section]);
  if (rows.length > 5000) return null;
  const showSlugs = reverseSlugs(docs.slugs, 'show');
  const emptySlugs = new Map();
  const available = new Map(), unnumbered = [], localMax = new Map();
  const episodeKey = (season, episode) => season + ':' + episode;
  for (const row of rows) {
    const item = parse(row.payload);
    const name = item.show || String(item.rel ?? '').replace(/\\/g, '/').split('/')[0] || item.title || '';
    if (name !== summary.title) continue;
    const card = itemCard(row, item, settings, emptySlugs, emptySlugs);
    Object.assign(card, { show_key: key, show_slug: showSlugs.get(key) ?? key, available: true, status: 'available' });
    if (card.episode_title && item.season != null && item.episode != null && item.season !== '' && item.episode !== '') {
      card.title = episodeLabel(fixText(item.show), int(item.season), int(item.episode),
        lower(card.episode_title) === lower(fixText(item.show)) ? null : card.episode_title);
    }
    if (item.season == null || item.episode == null) {
      card.season = int(item.season);
      unnumbered.push(card);
      continue;
    }
    const season = int(item.season), episode = int(item.episode);
    const id = episodeKey(season, episode);
    if (!available.has(id)) available.set(id, card);
    if (season > 0) localMax.set(season, Math.max(episode, localMax.get(season) ?? 0));
  }

  const guide = docs[guideKey];
  const guideEpisodes = list(guide?.episodes);
  if (guideEpisodes.length > 10000) return null;
  const expected = new Map();
  let source = guideEpisodes.length ? 'tvmaze' : 'local_gaps';
  for (const entry of guideEpisodes) {
    if (entry?.number == null) continue;
    const season = int(entry.season), episode = int(entry.number);
    expected.set(episodeKey(season, episode), { season, episode, name: entry.name ?? null, airdate: entry.airdate ?? null });
  }
  for (const [season, max] of localMax) {
    if (max > 10000) return null;
    for (let episode = 1; episode <= max; episode++) {
      const id = episodeKey(season, episode);
      if (!expected.has(id)) {
        if (expected.size >= 10000) return null;
        expected.set(id, { season, episode, name: null, airdate: null });
        if (source === 'tvmaze') source = 'tvmaze+local_gaps';
      }
    }
  }
  for (const [id, card] of available) {
    if (!expected.has(id)) expected.set(id, { season: int(card.season), episode: int(card.episode), name: null, airdate: null });
  }

  const seasons = new Map();
  const add = card => {
    if (!seasons.has(card.season)) seasons.set(card.season, []);
    seasons.get(card.season).push(card);
  };
  for (const [id, entry] of expected) {
    const card = available.get(id);
    if (card) {
      if (enabled(entry.name)) card.episode_title = entry.name;
      if (enabled(entry.airdate)) card.airdate = entry.airdate;
      card.season = entry.season;
      add(card);
      continue;
    }
    add({
      id: null, show_key: key, section: summary.section, type: summary.type,
      title: episodeLabel(summary.title, entry.season, entry.episode, entry.name), episode_title: entry.name,
      year: summary.year ?? null, season: entry.season, episode: entry.episode, show: summary.title,
      ext: null, size: null, mtime: null, media_kind: 'video', has_poster: false,
      kind: 'episode', available: false, status: 'missing', airdate: entry.airdate,
    });
  }
  for (const card of unnumbered) add(card);

  const visible = new Set(await visibleCards(pool, [...available.values(), ...unnumbered], parental));
  const seasonList = [...seasons].sort(([a], [b]) => a - b).map(([season, episodes]) => {
    episodes.sort((a, b) => int(a.episode) - int(b.episode) ||
      Buffer.compare(Buffer.from(a.title), Buffer.from(b.title)));
    const count = episodes.filter(card => card.available).length;
    return { season, label: season === 0 ? 'Specials' : 'Season ' + season, episode_count: episodes.length,
      available_count: count, missing_count: episodes.length - count, has_poster: true,
      episodes: episodes.filter(card => !card.available || visible.has(card)) };
  });
  const availableCount = available.size + unnumbered.length;
  const missingCount = expected.size - available.size;
  const total = availableCount + missingCount;
  const [pins] = await pool.execute("SELECT payload FROM vaultcast_metadata_pins WHERE kind='show_meta' AND target_id=?", [key]);
  let pin = pins.length ? parse(pins[0].payload) : null;
  if (!pins.length) {
    const legacy = await readFile(join(root, 'data/synopsis', 'show_' + key + '.meta.json'), 'utf8').then(JSON.parse).catch(() => null);
    if (enabled(legacy?.pinned)) pin = legacy;
  }
  let synopsis = null;
  if (pin && !enabled(pin.cleared)) {
    if (enabled(pin.manual)) synopsis = String(pin.summary ?? '');
    else if (typeof pin.summary === 'string' && pin.summary) synopsis = pin.summary;
  }
  if (synopsis === null) {
    const cached = await readFile(join(root, 'data/tvmaze', 'show_' + key + '_episodes.json'), 'utf8').then(JSON.parse).catch(() => null);
    synopsis = typeof cached?.summary === 'string' && cached.summary ? cached.summary : guideEpisodes.length ? guide.summary ?? null : null;
  }
  const regularCount = seasonList.filter(season => season.season > 0).length;
  return {
    ok: true,
    show: { show_key: key, id: key, kind: 'show', section: summary.section, type: summary.type, title: summary.title,
      year: summary.year ?? null, slug: showSlugs.get(key) ?? key, episode_count: total,
      available_count: availableCount, missing_count: missingCount, season_count: regularCount || seasonList.length,
      has_poster: true, tvmaze_id: guideEpisodes.length ? guide.id ?? null : null,
      synopsis, genres: guideEpisodes.length ? guide.genres ?? [] : [], episode_source: source },
    seasons: seasonList,
    availability: { available: availableCount, missing: missingCount, total, source },
  };
}

async function cachedSynopsis(root, pool, item) {
  const empty = { summary: null, source: null, pending: false };
  const game = item.media_kind === 'rom';
  const film = ['movies', 'disney'].includes(item.type) || (item.media_kind === 'video' && !item.show);
  if (!game && !film) return empty;

  const [rows] = await pool.execute("SELECT payload FROM vaultcast_metadata_pins WHERE kind='movie_synopsis' AND target_id=?", [item.id]);
  const pin = rows.length ? parse(rows[0].payload) : null;
  if (pin && !enabled(pin.cleared) && typeof pin.summary === 'string' &&
      (game || pin.summary || enabled(pin.manual))) {
    return { summary: pin.summary, source: pin.provider ?? (game ? pin.source : null) ?? 'pin', pending: false };
  }

  const filename = 'movie_' + String(item.id).replace(/[^a-f0-9]/g, '') + '.json';
  const stored = await readFile(join(root, 'data/synopsis', filename), 'utf8')
    .then(JSON.parse).catch(error => {
      if (error.code === 'ENOENT' || error instanceof SyntaxError) return null;
      throw error;
    });
  if (!rows.length && enabled(stored?.pinned) && typeof stored.summary === 'string' && stored.summary) {
    return { summary: stored.summary, source: stored.provider ?? (game ? stored.source : null) ?? 'pin', pending: false };
  }
  if (game) {
    // Match the stored game synopsis format used by the metadata worker.
    if (stored?.game_lookup_version !== 2) return { ...empty, pending: true };
    const summary = String(stored.summary ?? '') || null;
    return { summary, source: stored.source ?? null, pending: !summary && int(stored.retry_at) <= now() };
  }
  if (!stored || !Object.hasOwn(stored, 'summary')) return { ...empty, pending: true };
  const summary = typeof stored.summary === 'string' ? stored.summary || null : null;
  return { summary, source: enabled(summary) ? String(stored.source ?? '') : null, pending: false };
}

module.exports = function mountLibrary(app, prefix, { root, handle, unavailable }) {
  const browse = (operation, includeCatalogue = true) => handle(async (request, response, pool, _bcrypt, settings, current) => {
    if (!current.user && settings.auth?.lan_guest_access !== false) return unavailable(response);
    if (!current.user && !current.authenticated) {
      return response.status(401).json({ ok: false, error: 'Sign in to continue.', code: 'login_required' });
    }
    if (!authorized(response, current)) return;
    const docs = includeCatalogue ? await documents(pool, ['catalogue']) : {};
    const parental = await parentalStatus(root, pool, current, current.user, now());
    return operation(request, response, pool, settings, current.user, docs.catalogue ?? {}, parental);
  }, true);

  app.get(prefix + '/shows/:id([a-f0-9]+)', browse(async (request, response, pool, settings, user, _catalogue, parental) => {
    if (!areaAllowed(user, 'library', parental)) return areaDenied(response, 'library');
    const { id } = request.params;
    if (!(await visibleCards(pool, [{ kind: 'show', show_key: id }], parental)).length) {
      return response.status(403).json({ ok: false, code: 'parental_blocked', error: 'This title isn’t available on this profile.' });
    }
    const [rows] = await pool.execute('SELECT payload FROM vaultcast_show_summaries WHERE show_key=?', [id]);
    const summary = rows.length ? parse(rows[0].payload) : null;
    if (!summary || summary.show_key !== id || !summary._meta_prepared || !enabled(settings.posters?.enabled ?? true)) {
      return unavailable(response);
    }
    const result = await showDetail(root, pool, summary, settings, parental);
    return result ? response.json(result) : unavailable(response);
  }, false));

  app.get(prefix + '/items/:id([a-f0-9]+)', browse(async (request, response, pool, settings, user, _catalogue, parental) => {
    // Explicit media probes and TV episode formatting still use the legacy handlers.
    if (enabled(request.query.probe) || enabled(request.query.audio)) return unavailable(response);
    const { id } = request.params;
    const [rows] = await pool.execute(
      'SELECT i.payload,f.sig,f.present,f.duration,f.needs_convert FROM vaultcast_items i ' +
      'LEFT JOIN vaultcast_media_facts f ON f.item_id=i.id WHERE i.id=?', [id]);
    if (!rows.length) {
      if (/^[a-f0-9]{24}$/.test(id)) return unavailable(response);
      const limited = ['library', 'live', 'games'].some(area => !areaAllowed(user, area, parental));
      return response.status(404).json({ ok: false, error: limited ? 'Item not found' : 'Not found' });
    }
    const row = rows[0];
    const item = parse(row.payload);
    const area = itemArea(item);
    if (!areaAllowed(user, area, parental)) return areaDenied(response, area);
    if (isEpisode(item)) return unavailable(response);

    if (!(await visibleCards(pool, [{ id: item.id, media_kind: item.media_kind }], parental)).length) {
      return response.status(403).json({ ok: false, code: 'parental_blocked', error: 'This title isn’t available on this profile.' });
    }

    const audioKey = 'media-audio:' + id;
    const docs = await documents(pool, ['slugs', audioKey]);
    const card = itemCard(row, item, settings, reverseSlugs(docs.slugs, 'movie'), reverseSlugs(docs.slugs, 'game'));
    const synopsis = request.query.synopsis === '0'
      ? { summary: null, source: null, pending: false }
      : await cachedSynopsis(root, pool, item);
    const signature = mediaSignature(item);
    const audio = docs[audioKey]?.sig === signature ? docs[audioKey].probe : null;
    response.json({ ok: true, item: {
      ...card,
      synopsis: synopsis.summary,
      synopsis_source: synopsis.source,
      synopsis_pending: synopsis.pending,
      file_exists: enabled(item.path) && (row.sig !== signature || enabled(row.present)),
      path: item.path ?? '',
      audio_tracks: audio?.audio_tracks ?? [],
      audio_count: audio?.audio_count ?? 0,
      multi_audio: enabled(audio?.multi_audio),
    } });
  }, false));

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

  app.get(prefix + '/sections/:section([A-Za-z0-9_-]+)/:mode(items|shows|random|recent)', browse(async (request, response, pool, settings, user, catalogue, parental) => {
    const { section: id, mode } = request.params;
    const section = list(catalogue.sections).find(entry => entry?.id === id);
    const limited = ['library', 'live', 'games'].some(area => !areaAllowed(user, area, parental));
    const configured = list(settings.sections).find(entry => entry?.id === id);
    if (limited && !configured) return response.status(404).json({ ok: false, error: 'Section not found' });
    if (!section || (configured && itemArea(configured) !== itemArea(section))) return unavailable(response);
    const area = itemArea(section);
    if (!areaAllowed(user, area, parental)) return areaDenied(response, area);
    const query = request.query;
    if (mode === 'random' || mode === 'recent') {
      const count = Math.max(1, Math.min(40, query.n === undefined ? 16 : int(query.n)));
      const buildCount = Math.max(count, 16);
      const series = ['tv', 'anime'].includes(section.type);
      const docs = await documents(pool, ['browse-index-ready', 'slugs']);
      if (series && !enabled(docs['browse-index-ready'])) return unavailable(response);
      let cards;
      if (mode === 'recent') {
        if (!series) return unavailable(response);
        cards = await recentCards(pool, id, buildCount, settings, docs.slugs);
      } else if (series) {
        const [rows] = await pool.execute('SELECT show_key FROM vaultcast_show_summaries WHERE section_id=? LIMIT 10001', [id]);
        if (rows.length > 10000) return unavailable(response);
        const ids = sample(rows.map(row => row.show_key), buildCount);
        if (!ids.length) cards = [];
        else {
          const [picked] = await pool.execute('SELECT show_key,payload FROM vaultcast_show_summaries WHERE section_id=? AND show_key IN (' +
            ids.map(() => '?').join(',') + ')', [id, ...ids]);
          const byKey = new Map(picked.map(row => [row.show_key, parse(row.payload)]));
          if (ids.some(key => !byKey.get(key)?._meta_prepared)) return unavailable(response);
          const slugs = reverseSlugs(docs.slugs, 'show');
          cards = ids.map(key => showCard(byKey.get(key), slugs));
        }
      } else {
        if (!['movies', 'disney', 'roms', 'music'].includes(section.type)) return unavailable(response);
        const [revisions] = await pool.execute("SELECT revision FROM vaultcast_documents WHERE name='catalogue'");
        const existing = await cached(root, 'section-sample-v1|' + id);
        let ids;
        if (Array.isArray(existing?.ids) && Number(existing.revision) === Number(revisions[0]?.revision ?? 0)) {
          ids = existing.ids;
        } else {
          const [rows] = await pool.execute('SELECT id FROM vaultcast_items WHERE section_id=? LIMIT 100001', [id]);
          ids = rows.map(row => row.id);
        }
        if (ids.length > 100000) return unavailable(response);
        cards = await showcaseItems(pool, id, sample(ids, buildCount), settings, docs.slugs);
      }
      if (!cards || cards.some(card => !card)) return unavailable(response);
      const kind = mode === 'recent' ? 'recent' : series ? 'shows' : 'items';
      return response.json({ ok: true, kind, cached: false, items: await visibleCards(pool, cards.slice(0, count), parental) });
    }
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
      if (items.some(item => !item || isEpisode(item))) return unavailable(response);
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

Object.assign(module.exports, { areaAllowed, areaDenied, itemArea, mediaSignature, playbackVisible });
