'use strict';

const { areaAllowed, areaDenied, itemArea, playbackVisible } = require('./library');
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
    if (request.method === 'POST') {
      const body = request.body ?? {};
      if (['search', 'lookup'].includes(body.action)) return unavailable(response);
      const docs = await documents(pool, ['permission_roles']);
      if (!publicUser(user, settings, docs, now()).capabilities.timing_edit) {
        return response.status(403).json({ ok: false, error: 'This action requires the timing_edit permission.' });
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

    if (Object.hasOwn(request.query, 'edit')) return unavailable(response);
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
