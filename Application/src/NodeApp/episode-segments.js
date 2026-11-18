'use strict';

const { createHash } = require('crypto');
const { identify, preview, searchShows } = require('./segment-providers');
const { mediaSignature, areaAllowed, areaDenied, itemArea, playbackVisible } = require('./library');
const { authorized, parentalStatus } = require('./profile-policy');
const { documents, enabled, now, parse, publicUser, sha } = require('./account-data');

const empty = () => ({ ok: true, segments: [], source: 'TheIntroDB' });
const numeric = value => (typeof value === 'number' ||
  typeof value === 'string' && /^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i.test(value.trim())) &&
  Number.isFinite(Number(value));

function manualRecord(body, fingerprint) {
  const duration = body.duration ?? 0;
  if (!body.segments || typeof body.segments !== 'object' ||
      Object.values(body.segments).length > 16 || !numeric(duration) || duration < 0 || duration > 21600) {
    return { error: 'Invalid episode timings' };
  }
  const segments = [];
  for (const row of Object.values(body.segments)) {
    if (!['intro', 'outro'].includes(row?.kind) || !numeric(row.start) || !numeric(row.end)) {
      return { error: 'Enter valid start and end times' };
    }
    const start = Number(row.start), end = Number(row.end);
    if (start < 0 || end <= start || end > 21600 || (duration > 0 && end > duration)) {
      return { error: 'End must follow start and be within the episode' };
    }
    segments.push({ kind: row.kind, start, end });
  }
  segments.sort((a, b) => a.start - b.start);
  const lookup = body.lookup && typeof body.lookup === 'object' && Buffer.byteLength(JSON.stringify(body.lookup)) <= 1000
    ? body.lookup : null;
  return { lookup, ok: true, segments, source: 'Admin', duration: Number(duration), fingerprint, updated_at: now() };
}

module.exports = function mountEpisodeSegments(app, prefix, { root, handle, json, unavailable }) {
  const endpoint = prefix + '/items/:id([a-f0-9]{8,64})/segments';
  const operation = handle(async (request, response, pool, _bcrypt, settings, current) => {
    if (!current.user && !current.authenticated && settings.auth?.lan_guest_access !== false) return unavailable(response);
    if (!authorized(response, current)) return;
    const user = current.user;
    const { id } = request.params;
    const [rows] = await pool.execute('SELECT payload FROM vaultcast_items WHERE id=?', [id]);
    const parental = await parentalStatus(root, pool, current, user, now());
    if (!rows.length) {
      const limited = ['library', 'live', 'games'].some(area => !areaAllowed(user, area, parental));
      return response.status(404).json({ ok: false, error: limited ? 'Item not found' : 'Episode unavailable' });
    }
    const item = parse(rows[0].payload);
    if (item?.id !== id) return unavailable(response);
    const area = itemArea(item);
    if (!areaAllowed(user, area, parental)) return areaDenied(response, area);
    if (!await playbackVisible(pool, item, parental)) {
      return response.status(403).json({ ok: false, code: 'parental_blocked', error: 'This title isn’t available on this profile.' });
    }
    if (!areaAllowed(user, 'library', parental)) {
      return response.status(403).json({ ok: false, error: 'Library access required' });
    }

    const series = enabled(item.show);
    const eligible = !['rom', 'audio'].includes(item.media_kind) &&
      (series || ['movies', 'movie', 'disney'].includes(item.type));
    const key = 'episode-segments:' + item.id;
    const fingerprint = sha(JSON.stringify([item.id, item.mtime ?? null, item.size ?? null]));
    const editing = request.method === 'GET' && Object.hasOwn(request.query, 'edit');
    if (request.method === 'POST' || editing) {
      const body = request.body ?? {};
      const docs = await documents(pool, ['permission_roles']);
      if (!publicUser(user, settings, docs, now()).capabilities.timing_edit) {
        return response.status(403).json({ ok: false, error: 'This action requires the timing_edit permission.' });
      }
      if (editing) {
        const showKey = series ? createHash('sha1').update(String(item.section) + '|' + item.show).digest('hex').slice(0, 16) : '';
        const guideKey = 'tvmaze-guide:' + showKey;
        const inputs = await documents(pool, [key, 'match_overrides', ...(series ? [guideKey] : [])]);
        const saved = inputs[key]?.fingerprint === fingerprint ? inputs[key] : null;
        const record = saved ? { ...saved } : { ok: true, segments: [], duration: 0 };
        if (!enabled(record.duration)) {
          const [facts] = await pool.execute('SELECT duration FROM vaultcast_media_facts WHERE item_id=? AND sig=?', [item.id, mediaSignature(item)]);
          record.duration = Number(facts[0]?.duration) || 0;
        }
        const identity = { showKey, pin: inputs.match_overrides?.shows?.[showKey],
          guide: inputs[guideKey], override: inputs.match_overrides?.items?.[item.id] };
        if (!series) {
          const [pins] = await pool.execute(
            "SELECT kind,payload FROM vaultcast_metadata_pins WHERE target_id=? AND kind IN ('movie_poster','movie_synopsis')", [item.id]);
          for (const pin of pins) {
            const data = parse(pin.payload);
            if (data && !enabled(data.cleared)) identity[pin.kind === 'movie_poster' ? 'poster' : 'synopsis'] = data;
          }
        }
        return async () => response.json({ ...record, is_movie: !series, identity: await identify(root, item, identity),
          season: Number.parseInt(item.season ?? 1, 10) || 0, episode: Number.parseInt(item.episode ?? 1, 10) || 0 });
      }
      if (body.action === 'search') {
        const query = typeof body.q === 'string' ? body.q.trim() : '';
        if (Buffer.byteLength(query) < 2 || Buffer.byteLength(query) > 160) {
          return response.status(400).json({ ok: false, error: 'Enter a show name (2–160 characters)' });
        }
        return async () => {
          const result = await searchShows(root, query);
          return result ? response.json(result) :
            response.status(502).json({ ok: false, error: 'Show search is temporarily unavailable' });
        };
      }
      if (body.action === 'lookup') {
        const kind = body.id_type ?? 'tvdb_id';
        const identifier = ['string', 'number'].includes(typeof body.id) ? String(body.id).trim() : '';
        if (!['tvdb_id', 'tmdb_id', 'imdb_id'].includes(kind) ||
            !(kind === 'imdb_id' ? /^tt[0-9]{7,8}$/.test(identifier) : /^[1-9][0-9]{0,7}$/.test(identifier))) {
          return response.status(400).json({ ok: false, error: 'Enter a valid TVDB, TMDB or IMDb show ID' });
        }
        const integer = value => typeof value === 'string' && /^[+-]?(?:0|[1-9]\d*)$/.test(value.trim()) ? Number(value) : value;
        const season = integer(body.season), episode = integer(body.episode);
        const mapping = body.mapping ?? 'official', duration = Number(body.duration);
        if (!Number.isInteger(season) || season < 0 || season > 1000 ||
            !Number.isInteger(episode) || episode < 0 || episode > 10000 ||
            !['official', 'default', 'dvd', 'alternate', 'absolute', 'regional'].includes(mapping) ||
            !numeric(body.duration) || duration <= 0 || duration > 21600) {
          return response.status(400).json({ ok: false, error: 'Enter valid episode numbers and the local episode length' });
        }
        const query = { [kind]: identifier, duration_ms: Math.round(duration * 1000), details: 'true', merge_unknown: 'true' };
        if (series) Object.assign(query, { season, episode, mapping });
        return async () => response.json(await preview(root, item, query, duration));
      }
      if (!eligible) return response.status(400).json({ ok: false, error: 'Movie, TV or anime video required' });
      const record = body.action === 'reset' ? null : manualRecord(body, fingerprint);
      if (record?.error) return response.status(400).json({ ok: false, error: record.error });
      response.locals.mutationStarted = true;
      await pool.execute(
        'INSERT INTO vaultcast_documents (name,payload) VALUES (?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),revision=revision+1',
        [key, JSON.stringify(record)]);
      return response.json(record ?? { ok: true, segments: [] });
    }

    const duration = request.query.duration ?? 0;
    if (typeof duration === 'object') return unavailable(response);
    if (!eligible || user.prefs?.skip_segments === false || !numeric(duration) || duration <= 0 || duration > 21600) {
      return response.json(empty());
    }
    if (series) {
      const season = Number.parseInt(item.season, 10) || 0;
      const episode = Number.parseInt(item.episode, 10) || 0;
      if (season < 0 || season > 1000 || episode < 0 || episode > 10000) return response.json(empty());
    }
    const record = (await documents(pool, [key]))[key];
    if (record?.source === 'Admin' && record.fingerprint === fingerprint) return response.json(record);
    return unavailable(response);
  }, true);

  app.get(endpoint, operation);
  app.post(endpoint, (request, response, next) => {
    if (!request.is('application/json')) return unavailable(response);
    json(request, response, error => error ? unavailable(response) : next());
  }, operation);
};
