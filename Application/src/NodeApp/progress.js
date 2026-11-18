'use strict';

const { mkdir, open } = require('fs').promises;
const { join } = require('path');
const { lockFile } = require('../Workers/file-lock');
const { documents, enabled, now, sha } = require('./account-data');
const { authorized } = require('./profile-policy');
const watchAchievements = require('./watch-achievements');

const previousViews = "GREATEST(COALESCE(JSON_EXTRACT(payload,'$.viewings'),0), " +
  "CASE WHEN COALESCE(JSON_EXTRACT(payload,'$.watched'),0)=1 OR " +
  "(COALESCE(JSON_EXTRACT(payload,'$.duration'),0)>0 AND " +
  "COALESCE(JSON_EXTRACT(payload,'$.position'),0)/NULLIF(JSON_EXTRACT(payload,'$.duration'),0)>0.92) THEN 1 ELSE 0 END)";
const wasArmed = "(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(payload,'$.viewing_armed'))='1' THEN 1 ELSE 0 END)";
const save = 'INSERT INTO vaultcast_progress (user_id,item_id,updated_at,payload) VALUES (?,?,?,?) ' +
  "ON DUPLICATE KEY UPDATE updated_at=VALUES(updated_at),payload=JSON_SET(VALUES(payload),'$.viewings'," +
  "GREATEST(" + previousViews + " + CASE WHEN ?=1 AND (" + wasArmed + "=1 OR " + previousViews + "=0) THEN 1 ELSE 0 END," +
  "COALESCE(JSON_EXTRACT(VALUES(payload),'$.viewings'),0)),'$.viewing_armed'," +
  'CASE WHEN ?=1 THEN 0 WHEN ?=1 THEN 1 ELSE ' + wasArmed + ' END)';

async function refreshHome(root, pool, userId, time) {
  const hash = sha(userId);
  const directory = join(root, 'data/cache/progress');
  const bump = () => pool.execute(
    "INSERT INTO vaultcast_documents (name,payload) VALUES (?,'[]') ON DUPLICATE KEY UPDATE payload=VALUES(payload),revision=revision+1",
    ['progress-user:' + hash]);
  let stamp;
  try {
    await mkdir(directory, { recursive: true, mode: 0o775 });
    stamp = await open(join(directory, hash + '.stamp'), 'a+', 0o664);
  } catch (error) {
    await bump();
    return;
  }
  try {
    // Use the existing stamp and lock so Node and PHP share the same cache throttle.
    if (!await lockFile(stamp, 0).then(() => true, () => false)) return;
    const buffer = Buffer.alloc(32);
    const { bytesRead } = await stamp.read(buffer, 0, buffer.length, 0);
    if (time - (Number(buffer.toString('utf8', 0, bytesRead)) || 0) < 20) return;
    await bump();
    await stamp.truncate(0);
    await stamp.writeFile(String(time));
  } finally {
    await stamp.close();
  }
}

module.exports = function mountProgress(app, prefix, { handle, json, unavailable, root }) {
  app.post(prefix + '/progress', (request, response, next) => {
    if (!request.is('application/json')) return unavailable(response);
    json(request, response, error => error ? unavailable(response) : next());
  }, handle(async (request, response, pool, _bcrypt, settings, current) => {
    if (!current.user && !current.authenticated && settings.auth?.lan_guest_access !== false) return unavailable(response);
    if (!authorized(response, current)) return;
    const { item_id: itemId, position = 0, duration = 0 } = request.body ?? {};
    if (typeof itemId !== 'string' || itemId.length < 8 || itemId.length > 64 || /[^a-f0-9]/.test(itemId)) {
      return response.status(400).json({ ok: false, error: 'item_id required' });
    }
    if (itemId.length === 24) return response.status(204).end();
    if (![position, duration].every(Number.isFinite)) return unavailable(response);
    const fraction = duration > 0 ? Math.max(0, position) / duration : 0;
    const finished = Number(duration > 0 && fraction > 0.92);
    const armed = Number(duration > 0 && fraction < 0.1);
    const time = now();
    response.locals.mutationStarted = true;
    await pool.execute(save, [current.user.id, itemId, time, JSON.stringify({
      position, duration, updated_at: time, viewings: finished, viewing_armed: armed,
    }), finished, finished, armed]);
    await refreshHome(root, pool, current.user.id, time).catch(() => {});
    if (finished && duration > 60) {
      try {
        const { 'achievement-settings': achievements } = await documents(pool, ['achievement-settings']);
        if (!achievements || !Object.hasOwn(achievements, 'enabled') || enabled(achievements.enabled)) {
          const count = await watchAchievements(root, pool, current.user, itemId, duration, time);
          if (count && current.data?.user_id === current.user.id) response.set('X-Vaultcast-Achievement', String(count));
        }
      } catch (error) {
        // Progress is already saved. A later completion tick can retry the achievement transaction.
        console.warn('Vaultcast could not record playback achievements:', error.code ?? error.name);
      }
    }
    return response.status(204).end();
  }, true));
};
