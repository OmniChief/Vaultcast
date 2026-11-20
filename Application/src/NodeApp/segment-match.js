'use strict';

const { createHash } = require('crypto');
const { documents, enabled, parse, sha, now } = require('./account-data');
const { q, transaction } = require('./queries');
const { identify, preview } = require('./segment-providers');

const empty = () => ({ ok: true, segments: [], source: 'TheIntroDB' });
const fingerprint = item => sha(JSON.stringify([item.id, item.mtime ?? null, item.size ?? null]));
const eligible = item => !['rom','audio'].includes(item.media_kind) &&
  (enabled(item.show) || ['movies','movie','disney'].includes(item.type));

async function context(pool, item) {
  const key = 'episode-segments:' + item.id;
  const showKey = enabled(item.show) ? createHash('sha1').update(String(item.section) + '|' + item.show).digest('hex').slice(0, 16) : '';
  const guideKey = 'tvmaze-guide:' + showKey;
  const inputs = await documents(pool, ['match_overrides', ...(showKey ? [guideKey] : [])]);
  const identity = { showKey, guide: inputs[guideKey], pin: inputs.match_overrides?.shows?.[showKey],
    override: inputs.match_overrides?.items?.[item.id] };
  if (!showKey) {
    const pins = await q(pool, "SELECT kind,payload FROM vaultcast_metadata_pins WHERE target_id=? AND kind IN ('movie_poster','movie_synopsis')", [item.id]);
    for (const pin of pins) {
      const data = parse(pin.payload);
      if (data && !enabled(data.cleared)) identity[pin.kind === 'movie_poster' ? 'poster' : 'synopsis'] = data;
    }
  }
  const [row] = await q(pool, 'SELECT revision,payload FROM vaultcast_documents WHERE name=?', [key]);
  const record = row && parse(row.payload);
  return { identity, key, revision: row?.revision, saved: record?.fingerprint === fingerprint(item) ? record : null };
}

async function match(root, db, item, input, duration) {
  const { saved, key, revision, identity } = input;
  if (saved?.source === 'Admin') return saved;
  if (!eligible(item) || !Number.isFinite(duration) || duration <= 0 || duration > 21600) return empty();
  const series = Boolean(identity.showKey);
  const pin = identity.pin?.provider === 'none' ? 0 : Number(identity.pin?.tvmaze_id) > 0 ? Number(identity.pin.tvmaze_id) : null;
  const season = parseInt(item.season, 10) || 0, episode = parseInt(item.episode, 10) || 0;
  if (series && (pin === 0 || season < 0 || season > 1000 || episode < 0 || episode > 10000)) return empty();
  if (series && saved && (saved.match_pin ?? null) === pin) return saved;
  const { externals } = await identify(root, item, identity);
  const params = { duration_ms: Math.round(duration * 1000), details: 'true', merge_unknown: 'true' };
  const query = series ? { season, episode, mapping: 'official', ...params } : {};
  if (series && Number.isSafeInteger(Number(externals.thetvdb)) && Number(externals.thetvdb) > 0) query.tvdb_id = Number(externals.thetvdb);
  else if (/^tt\d{7,10}$/.test(externals.imdb ?? '')) query.imdb_id = externals.imdb;
  else return empty();
  if (!series) {
    if (saved && saved.lookup?.imdb_id === query.imdb_id) return saved;
    Object.assign(query, params);
  }
  const result = await preview(root, item, query, duration, false);
  if (!result.segments.length) return result;
  const record = { ...result, fingerprint: fingerprint(item), match_pin: series ? pin : null, updated_at: now() };
  // A slow provider response must not replace a manual edit or reset made during the lookup.
  return db.run(pool => transaction(pool, async conn => {
    const [source] = await q(conn, 'SELECT payload FROM vaultcast_items WHERE id=? LOCK IN SHARE MODE', [item.id]);
    if (!source || fingerprint(parse(source.payload)) !== record.fingerprint) return empty();
    const payload = JSON.stringify(record);
    const write = revision === undefined
      ? await q(conn, 'INSERT INTO vaultcast_documents(name,payload) VALUES(?,?) ON DUPLICATE KEY UPDATE name=name', [key,payload])
      : await q(conn, 'UPDATE vaultcast_documents SET payload=?,revision=revision+1 WHERE name=? AND revision=?', [payload,key,revision]);
    if (revision !== undefined && write.affectedRows === 1) return record;
    const current = (await documents(conn,[key]))[key];
    return current?.fingerprint === record.fingerprint ? current : empty();
  }));
}

module.exports = { context, match, eligible, fingerprint };
