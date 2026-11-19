'use strict';

const { randomBytes } = require('crypto');
const { q, take, cache, doc, save, transaction } = require('./stalker-store');
const { sha, now, parse, enabled } = require('./account-data');
const { configHash, StalkerProvider } = require('./stalker-provider');

module.exports = function libraryWriter(decode) {
  const text = (value) =>
    value == null || typeof value === 'object' ? '' : decode(String(value).replace(/<[^>]*>/g, '')).trim();
  const displayTitle = (value) =>
    text(value)
      .replace(
        /^(?:(?:4k|uhd|fhd|hd|sd|2160p|1080p|720p|en|eng|uk|us|fr|de|es|it|nl|ru|pt|ar|hi|tr|gr|el|nf|netflix|amz|amazon|hbo|max)\s*[-|:]\s*)+/iu,
        '',
      )
      .trim();
  const title = (value) =>
    displayTitle(value)
      .toLowerCase()
      .replace(/\s*[\[(](?:(?:19|20)\d{2}|4k|uhd|fhd|hd|sd|1080p|720p|2160p)[\])]\s*/giu, ' ')
      .replace(/\s+(?:4k|uhd|fhd|1080p|720p|2160p)$/iu, '')
      .replace(/\s+(?:19|20)\d{2}$/u, '')
      .replace(/[^\p{L}\p{N}]+/gu, ' ')
      .trim();
  function ids(row) {
    let imdb = text(row.imdb_id ?? row.imdb ?? ''),
      tmdb = text(row.tmdb_id ?? row.tmdb ?? '');
    if (/^\d{1,12}$/.test(imdb) && Number(imdb) > 0) imdb = 'tt' + imdb.padStart(7, '0');
    return [
      /^(?:https?:\/\/(?:www\.)?imdb\.com\/title\/)?(tt\d{5,12})\/?$/i.exec(imdb)?.[1].toLowerCase() ?? '',
      /^[1-9]\d{0,11}$/.test(tmdb) ? tmdb : '',
    ];
  }
  const unique = (values) => [...new Set(values)];
  function names(value) {
    const values = Array.isArray(value)
      ? value
      : value && typeof value === 'object'
        ? Object.values(value)
        : text(value).split(/[,;|/]+/);
    return unique(
      values
        .map((value) => text(value && typeof value === 'object' ? (value.name ?? value.title ?? '') : value))
        .filter((value) => value && !Number.isFinite(Number(value)))
        .map((value) => take(value, 80)),
    );
  }
  const languageMap = {};
  for (const [name, codes] of Object.entries({
    English: 'en eng uk us english',
    French: 'fr fra fre french',
    German: 'de deu ger german',
    Spanish: 'es spa spanish',
    Italian: 'it ita italian',
    Portuguese: 'pt por portuguese',
    Dutch: 'nl nld dut dutch',
    Greek: 'el gr gre greek',
    Russian: 'ru rus russian',
    Arabic: 'ar ara arabic',
    Hindi: 'hi hin hindi',
    Turkish: 'tr tur turkish',
    Japanese: 'ja jp jpn japanese',
    Korean: 'ko kor korean',
    Chinese: 'zh chi zho chinese',
  }))
    for (const code of codes.split(' ')) languageMap[code] = name;
  function languages(value, knownOnly = false) {
    return unique(
      names(value).flatMap((name) => {
        let key = name
          .replace(/\s*\[[^\]]*\]\s*/g, '')
          .trim()
          .toLowerCase();
        key = /^([a-z]{2,3})[-_][a-z]{2}$/i.exec(key)?.[1] ?? key;
        return languageMap[key]
          ? [languageMap[key]]
          : !knownOnly && !['unknown', 'multi', 'multilingual', 'n/a', 'none', 'null'].includes(key)
            ? [name]
            : [];
      }),
    );
  }
  function seriesMetadata(feed, row, parent = {}) {
    const locations = [],
      seen = new Set(),
      sections = [row, row.info ?? {}, row.movie_info ?? {}];
    for (const section of sections)
      for (const field of [
        'screenshot_uri',
        'cover',
        'poster',
        'movie_image',
        'pic',
        'image',
        'icon',
        'logo',
        'cover_big',
        'cover_url',
        'poster_url',
        'image_url',
        'screenshot',
        'thumbnail',
        'thumb',
      ]) {
        const value = section[field];
        if (value == null || typeof value === 'object' || !String(value).trim() || locations.length >= 8)
          continue;
        try {
          const url = new URL(String(value).trim(), new StalkerProvider('', feed).endpoint);
          if (
            !['http:', 'https:'].includes(url.protocol) ||
            url.username ||
            url.password ||
            url.href.length > 2048 ||
            /[\r\n]/.test(String(value)) ||
            seen.has(url.href) ||
            /^https?:\/\/image\.tmdb\.org\/t\/p\/[^/?#]+\/?(?:[?#].*)?$/i.test(url.href)
          )
            continue;
          seen.add(url.href);
          locations.push({ field, url: url.href });
        } catch (error) {}
      }
    let description = '';
    for (const section of sections) {
      for (const field of ['description', 'descr', 'plot', 'summary', 'overview']) {
        const value = text(section[field]);
        if (value && !/^(?:n\/?a|none|null|unknown|no description)$/i.test(value)) {
          description = value;
          break;
        }
      }
      if (description) break;
    }
    const original = String(row.name ?? row.o_name ?? row.id ?? 'Provider show');
    const display = original
      .replace(/(?<=[\p{L}\p{N}])\.(?=[\p{L}\p{N}])/gu, ' ')
      .replace(/\s+/gu, ' ')
      .trim();
    let year = '';
    for (const field of ['year', 'movie_year', 'release_date', 'premiered']) {
      year = /^((?:19|20)\d{2})(?:$|[-/])/.exec(String(row[field] ?? ''))?.[1] ?? '';
      if (year) break;
    }
    if (!year) year = /(?:^|[\s.(])((?:19|20)\d{2})(?:[\s.)]|$)/.exec(original)?.[1] ?? '';
    return {
      name: display || original,
      poster: locations[0]?.url || parent.poster || '',
      posterCandidates: locations,
      description: take(description || parent.description || '', 5000),
      year: year || parent.year || '',
    };
  }
  function descriptive(row) {
    const fields = [
      'o_name',
      'genres_str',
      'genre',
      'genres',
      'director',
      'actors',
      'cast',
      'country',
      'language',
      'original_language',
      'rating_imdb',
      'rating_mpaa',
      'rating_kinopoisk',
      'age',
      'added',
      'imdb_id',
      'tmdb_id',
      'kinopoisk_id',
      'movie_year',
      'release_date',
      'premiered',
      'duration',
      'category_id',
      'category',
      'genre_id',
      'genre_id_1',
      'genre_id_2',
      'genre_id_3',
      'genre_id_4',
      'cat_genre_id_1',
      'cat_genre_id_2',
      'cat_genre_id_3',
      'cat_genre_id_4',
      'rating',
      'time',
      'hd',
      'high_quality',
      'low_quality',
      'season',
      'season_number',
      'series_number',
      'episodes_count',
      'seasons_count',
      'year_end',
      'audio_languages',
      'subtitle_languages',
    ];
    const out = {};
    for (const section of [row, row.info ?? {}, row.movie_info ?? {}, row.video_info ?? {}])
      for (const field of fields) {
        if (Object.hasOwn(out, field) || !Object.hasOwn(section, field)) continue;
        let value = section[field];
        if (value == null) continue;
        if (
          typeof value === 'object' &&
          ['actors', 'cast', 'director', 'genres', 'audio_languages', 'subtitle_languages'].includes(field)
        ) {
          value = unique(
            Object.values(value)
              .slice(0, 20)
              .map((entry) =>
                take(text(entry && typeof entry === 'object' ? (entry.name ?? '') : entry), 120),
              )
              .filter(Boolean),
          ).join(', ');
        }
        if (typeof value === 'object') continue;
        value = text(value);
        if (
          !value ||
          /^(?:n\/?a|none|null|unknown|undefined|not available)$/i.test(value) ||
          /(?:https?:\/\/|[a-z0-9+.-]+:\/\/|(?:token|auth|password|cookie|session|mac)[=:])/i.test(value)
        )
          continue;
        if (/^(?:genre_id(?:_[1-4])?|cat_genre_id_[1-4])$/.test(field) && value === '0') continue;
        out[field] = take(
          value.replace(/[\x00-\x1f\x7f]/g, ' '),
          ['actors', 'cast', 'director'].includes(field) ? 3000 : 300,
        );
      }
    return out;
  }
  function metadata(feed, input, kind, category) {
    let row = input;
    for (const section of ['info', 'movie_info', 'video_info'])
      if (row[section] && typeof row[section] === 'object') row = { ...row[section], ...row };
    const m = { ...seriesMetadata(feed, row), ...descriptive(row) };
    [m.imdb_id, m.tmdb_id] = ids(row);
    if (!m.year) m.year = /[\[(]((?:19|20)\d{2})[\])]/.exec(m.name)?.[1] ?? '';
    m.year = /^(19|20)\d{2}$/.test(String(m.year)) ? Number(m.year) : null;
    m.languages = languages(row.audio_languages ?? row.audio_language ?? row.language ?? '');
    m.languageEvidence = m.languages.length ? 'provider audio metadata' : 'unknown';
    const audience = category
      .replace(/\b(?:with\s+)?(?:english|en|eng)\s+(?:subtitles?|subs?)\b/gi, '')
      .replace(/^\s*(?:4k|uhd|fhd|hd|sd)\s*[-|:]?\s*/i, '');
    m.audienceLanguages = unique(
      [
        ...audience.matchAll(
          /(?:^|[|:\[(])\s*([A-Za-z]{2,3})\s*(?=[-|:\])]|$)|\b(English|French|German|Spanish|Italian|Portuguese|Dutch|Greek|Russian|Arabic|Hindi|Turkish|Japanese|Korean|Chinese)\b/gi,
        ),
      ].flatMap((match) => languages(match[1] || match[2], true)),
    );
    m.subtitleLanguages = languages(row.subtitle_languages ?? '');
    m.genres = names(row.genres ?? row.genres_str ?? row.genre_name ?? row.genre ?? '');
    if (!m.genres.length)
      for (const genre of [
        'Action',
        'Adventure',
        'Animation',
        'Comedy',
        'Crime',
        'Documentary',
        'Drama',
        'Family',
        'Fantasy',
        'History',
        'Horror',
        'Music',
        'Mystery',
        'Romance',
        'Science Fiction',
        'Thriller',
        'War',
        'Western',
        'Sport',
      ])
        if (new RegExp('\\b' + genre + '\\b', 'i').test(category)) m.genres.push(genre);
    m.quality = text(row.quality ?? row.resolution ?? '');
    m.quality =
      /\b(2160p|4k|uhd|1080p|fhd|720p|hd|sd)\b/i
        .exec(m.quality + ' ' + m.name + ' ' + category)?.[1]
        .toUpperCase() ?? m.quality;
    m.providerTitle = text(row.name ?? row.o_name ?? m.name);
    m.name = displayTitle(m.name);
    m.category = category;
    m.kind = kind;
    m.playable = kind === 'vod' && enabled(row.cmd) && !enabled(row.is_series) && !enabled(row.series);
    m.id = m.playable ? kind + ':' + row.id : String(row.id);
    m.feed = feed.id;
    m.connection = feed.name;
    return m;
  }
  const compatible = (a, b) => ['imdb_id', 'tmdb_id'].every((key) => !a[key] || !b[key] || a[key] === b[key]);
  async function identity(pool, m, kind, previous) {
    let candidates = new Map();
    for (const field of ['imdb_id', 'tmdb_id']) {
      if (!m[field]) continue;
      for (const row of await q(
        pool,
        'SELECT * FROM vaultcast_stalker_library WHERE kind=? AND ' + field + '=? LIMIT 101',
        [kind, m[field]],
      ))
        if (compatible(m, row)) candidates.set(row.id, row);
      if (candidates.size) break;
    }
    const normal = title(m.o_name ?? m.name),
      key = sha(normal),
      displayKey = sha(title(m.name));
    if (!candidates.size && m.year && normal)
      for (const row of await q(
        pool,
        'SELECT c.* FROM vaultcast_stalker_aliases a JOIN vaultcast_stalker_library c ON c.id=a.catalogue_id WHERE c.kind=? AND a.title_key IN (?,?) AND a.year=? LIMIT 101',
        [kind, key, displayKey, m.year],
      ))
        if (compatible(m, row)) candidates.set(row.id, row);
    if (candidates.size > 100) candidates = new Map();
    if (previous) {
      const row = (
        await q(pool, 'SELECT * FROM vaultcast_stalker_library WHERE id=?', [previous.catalogue_id])
      )[0];
      if (row) candidates.set(row.id, row);
    }
    candidates = new Map([...candidates].filter(([, row]) => compatible(m, row)));
    const evidence = { ...m };
    let consistent = true;
    for (const row of candidates.values()) {
      if (!compatible(evidence, row)) {
        consistent = false;
        break;
      }
      for (const field of ['imdb_id', 'tmdb_id']) if (!evidence[field]) evidence[field] = row[field];
    }
    if (candidates.size && consistent) {
      const id =
        previous && candidates.has(previous.catalogue_id)
          ? previous.catalogue_id
          : [...candidates.keys()].sort()[0];
      for (const other of candidates.keys())
        if (other !== id) {
          await q(pool, 'UPDATE vaultcast_stalker_offers SET catalogue_id=? WHERE catalogue_id=?', [
            id,
            other,
          ]);
          await q(
            pool,
            'INSERT IGNORE INTO vaultcast_stalker_aliases(catalogue_id,title_key,year) SELECT ?,title_key,year FROM vaultcast_stalker_aliases WHERE catalogue_id=?',
            [id, other],
          );
          for (const table of ['vaultcast_stalker_facets', 'vaultcast_stalker_aliases'])
            await q(pool, 'DELETE FROM ' + table + ' WHERE catalogue_id=?', [other]);
          await q(pool, 'DELETE FROM vaultcast_stalker_library WHERE id=?', [other]);
        }
      await q(
        pool,
        "UPDATE vaultcast_stalker_library SET imdb_id=IF(imdb_id='',?,imdb_id),tmdb_id=IF(tmdb_id='',?,tmdb_id) WHERE id=?",
        [evidence.imdb_id, evidence.tmdb_id, id],
      );
      return id;
    }
    if (previous) return previous.catalogue_id;
    const id = randomBytes(16).toString('hex');
    await q(
      pool,
      'INSERT INTO vaultcast_stalker_library(id,kind,title,title_key,year,imdb_id,tmdb_id,payload,unavailable_since) VALUES(?,?,?,?,?,?,?,?,?)',
      [id, kind, take(m.name, 500), key, m.year, m.imdb_id, m.tmdb_id, JSON.stringify(m), now()],
    );
    return id;
  }
  async function aggregate(pool, id) {
    const rows = await q(
      pool,
      'SELECT available,payload FROM vaultcast_stalker_offers WHERE catalogue_id=? ORDER BY available DESC,last_seen DESC,feed_id,item_id',
      [id],
    );
    let available = false,
      m = null;
    const sets = { languages: [], audienceLanguages: [], subtitleLanguages: [], genres: [] };
    for (const row of rows) {
      const item = parse(row.payload);
      m ??= { ...item };
      for (const field of [
        'description',
        'poster',
        'director',
        'actors',
        'year',
        'imdb_id',
        'tmdb_id',
        'rating_imdb',
      ])
        if (!enabled(m[field]) && enabled(item[field])) m[field] = item[field];
      if (Number(row.available)) {
        available = true;
        for (const field of Object.keys(sets)) sets[field].push(...(item[field] ?? []));
      }
    }
    const previousRows = await q(pool, 'SELECT payload FROM vaultcast_stalker_library WHERE id=?', [id]),
      previous = previousRows.length ? parse(previousRows[0].payload) : {};
    if (m)
      for (const field of ['description', 'poster', 'director', 'actors', 'year', 'rating_imdb'])
        if (!enabled(m[field]) && enabled(previous[field])) m[field] = previous[field];
    if (!available && m) sets.genres = m.genres ?? [];
    if (m) {
      for (const field of Object.keys(sets)) m[field] = unique(sets[field]);
      await q(
        pool,
        'UPDATE vaultcast_stalker_library SET title=?,year=COALESCE(?,year),payload=?,available=?,unavailable_since=IF(?=1,NULL,COALESCE(unavailable_since,?)) WHERE id=?',
        [
          take(m.name, 500),
          m.year ?? null,
          JSON.stringify(m),
          available ? 1 : 0,
          available ? 1 : 0,
          now(),
          id,
        ],
      );
    } else
      await q(
        pool,
        'UPDATE vaultcast_stalker_library SET available=0,unavailable_since=COALESCE(unavailable_since,?) WHERE id=?',
        [now(), id],
      );
    const desired = new Map();
    for (const [field, values] of Object.entries(sets))
      for (const value of unique(values)) desired.set(field + '\0' + value, [field, value]);
    const existing = await q(pool, 'SELECT facet,value FROM vaultcast_stalker_facets WHERE catalogue_id=?', [
      id,
    ]);
    if (
      existing.length === desired.size &&
      existing.every((row) => desired.has(row.facet + '\0' + row.value))
    )
      return;
    await q(pool, 'DELETE FROM vaultcast_stalker_facets WHERE catalogue_id=?', [id]);
    for (const [field, value] of desired.values())
      await q(pool, 'INSERT IGNORE INTO vaultcast_stalker_facets(catalogue_id,facet,value) VALUES(?,?,?)', [
        id,
        field,
        value,
      ]);
  }
  async function publish(pool, feed, kind, generation, rows) {
    const changed = new Set();
    for (const stored of rows) {
      const row = parse(stored.payload),
        m = metadata(feed, row, kind, stored.category);
      const old = (
        await q(
          pool,
          'SELECT s.catalogue_id,s.available,s.payload,s.playback,c.imdb_id,c.tmdb_id,c.title_key,c.year FROM vaultcast_stalker_offers s JOIN vaultcast_stalker_library c ON c.id=s.catalogue_id WHERE s.feed_id=? AND s.kind=? AND s.item_id=?',
          [feed.id, kind, String(row.id)],
        )
      )[0];
      const key = sha(title(m.o_name ?? m.name)),
        oldMetadata = old ? parse(old.payload) : {};
      const same =
        old &&
        compatible(m, old) &&
        (sha(title(oldMetadata.o_name ?? oldMetadata.name)) === key ||
          (m.imdb_id && m.imdb_id === old.imdb_id) ||
          (m.tmdb_id && m.tmdb_id === old.tmdb_id)) &&
        (!m.year || !old.year || Number(old.year) === m.year);
      const privateItem = {
        cmd: row.cmd == null || typeof row.cmd === 'object' ? '' : String(row.cmd),
        series: String(row.series_number ?? ''),
        metadata: m,
      };
      const payload = JSON.stringify(m),
        playback = JSON.stringify(privateItem);
      if (
        same &&
        JSON.stringify(parse(old.payload)) === payload &&
        JSON.stringify(parse(old.playback)) === playback
      ) {
        await q(
          pool,
          'UPDATE vaultcast_stalker_offers SET config=?,generation=?,available=1,last_seen=? WHERE feed_id=? AND kind=? AND item_id=?',
          [configHash(feed), generation, now(), feed.id, kind, String(row.id)],
        );
        if (!Number(old.available)) changed.add(old.catalogue_id);
        continue;
      }
      const id = await identity(pool, m, kind, same ? old : null);
      if (m.year)
        for (const alias of unique([key, sha(title(m.name))]))
          await q(
            pool,
            'INSERT IGNORE INTO vaultcast_stalker_aliases(catalogue_id,title_key,year) VALUES(?,?,?)',
            [id, alias, m.year],
          );
      await q(
        pool,
        `INSERT INTO vaultcast_stalker_offers(feed_id,kind,item_id,catalogue_id,config,generation,available,last_seen,payload,playback) VALUES(?,?,?,?,?,?,1,?,?,?)
        ON DUPLICATE KEY UPDATE catalogue_id=VALUES(catalogue_id),config=VALUES(config),generation=VALUES(generation),available=1,last_seen=VALUES(last_seen),payload=VALUES(payload),playback=VALUES(playback)`,
        [feed.id, kind, String(row.id), id, configHash(feed), generation, now(), payload, playback],
      );
      if (old && old.catalogue_id !== id) changed.add(old.catalogue_id);
      changed.add(id);
    }
    for (const id of changed) await aggregate(pool, id);
  }
  async function reconcile(pool, job) {
    const rows = await q(
      pool,
      'SELECT item_id,catalogue_id FROM vaultcast_stalker_offers WHERE feed_id=? AND kind=? AND generation<>? LIMIT 100',
      [job.feed_id, job.kind, job.generation],
    );
    const changed = new Set();
    for (const row of rows) {
      await q(
        pool,
        'UPDATE vaultcast_stalker_offers SET available=0,generation=? WHERE feed_id=? AND kind=? AND item_id=?',
        [job.generation, job.feed_id, job.kind, row.item_id],
      );
      changed.add(row.catalogue_id);
    }
    for (const id of changed) await aggregate(pool, id);
    return !rows.length;
  }
  async function filters(pool, root, kind) {
    const facets = {};
    for (const row of await q(
      pool,
      'SELECT f.facet,f.value,COUNT(*) AS count FROM vaultcast_stalker_facets f JOIN vaultcast_stalker_library c ON c.id=f.catalogue_id WHERE c.kind=? GROUP BY f.facet,f.value ORDER BY f.value',
      [kind],
    ))
      (facets[row.facet] ??= []).push({ value: row.value, count: Number(row.count) });
    const years = (
      await q(
        pool,
        'SELECT DISTINCT year FROM vaultcast_stalker_library WHERE kind=? AND year IS NOT NULL ORDER BY year DESC',
        [kind],
      )
    ).map((row) => row.year);
    await cache(root, 'stalker_library_filters_v3_' + kind, { facets, years }, 0);
  }
  async function refreshLocal(pool) {
    const key = 'stalker-library-local-backfill-v1',
      cursor = await doc(pool, key, { id: '', complete: false });
    if (cursor.complete) return;
    await transaction(pool, async (connection) => {
      const rows = await q(
        connection,
        'SELECT id,payload FROM vaultcast_items WHERE id>? ORDER BY id LIMIT 200 FOR UPDATE',
        [cursor.id],
      );
      for (const row of rows) {
        const item = parse(row.payload);
        if (!item?.id) continue;
        if (item.media_kind !== 'video') {
          await q(connection, 'DELETE FROM vaultcast_stalker_local WHERE item_id=?', [item.id]);
          continue;
        }
        const show = text(item.show ?? item.show_title ?? ''),
          kind = show ? 'series' : 'vod',
          [imdb, tmdb] = ids(item),
          rawYear = Number(item.year),
          year = rawYear >= 1900 && rawYear <= 2099 ? Math.trunc(rawYear) : null;
        await q(
          connection,
          `INSERT INTO vaultcast_stalker_local(item_id,kind,title_key,year,imdb_id,tmdb_id) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE kind=VALUES(kind),title_key=VALUES(title_key),year=VALUES(year),imdb_id=VALUES(imdb_id),tmdb_id=VALUES(tmdb_id)`,
          [item.id, kind, sha(title(show || item.title || '')), year, imdb, tmdb],
        );
      }
      const complete = rows.length < 200;
      if (complete)
        await q(
          connection,
          'DELETE l FROM vaultcast_stalker_local l LEFT JOIN vaultcast_items i ON i.id=l.item_id WHERE i.id IS NULL',
        );
      await save(connection, key, { id: complete ? '' : rows.at(-1).id, complete });
    });
  }
  return {
    text,
    title,
    seriesMetadata,
    descriptive,
    metadata,
    aggregate,
    publish,
    reconcile,
    filters,
    refreshLocal,
  };
};
