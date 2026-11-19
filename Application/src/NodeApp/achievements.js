'use strict';

const definitions = require('./achievement-definitions.json');
const ensureHistory = require('./achievement-history');
const { award, invalidateRarity, tiers, withUser } = require('./watch-achievements');
const { q } = require('./queries');
const { cached, cacheResult, parentalStatus, authorized } = require('./profile-policy');
const { documents, enabled, now, storedBoolean } = require('./account-data');

const categories = { watching: 'Watching', live: 'Live TV', play: 'Play', social: 'Social', library: 'Library', fun: 'Board & Ambient' };
const fail = (response, error) => response.status(422).json({ ok: false, error });
const signedIn = user => Boolean(user.id && user.id !== 'public' && user.role && user.role !== 'guest');

async function achievementsEnabled(pool) {
  const docs = await documents(pool, ['achievement-settings']);
  return storedBoolean(docs['achievement-settings'], 'enabled', true);
}

function knownIds(input, limit) {
  const ids = input && typeof input === 'object' ? Object.values(input) : [input];
  return [...new Set(ids.slice(0, limit).filter(id => typeof id === 'string' && Object.hasOwn(definitions, id)))];
}

async function rarity(root, pool) {
  const key = 'achievements-rarity';
  const hit = await cached(root, key);
  if (hit?.people && hit.pct) return hit;
  const [users] = await q(pool, "SELECT COUNT(*) AS n FROM vaultcast_users WHERE role<>'guest'");
  const people = Math.max(1, Number(users.n));
  const counts = await q(pool, 'SELECT ach_id,COUNT(*) AS n FROM vaultcast_achievements GROUP BY ach_id');
  const pct = Object.fromEntries(counts.map(row => [row.ach_id, Math.min(100, Math.round(1000 * row.n / people) / 10)]));
  const value = { people, pct };
  await cacheResult(root, key, value, 600);
  return value;
}

function formatProgress(counter, value) {
  if (counter === 'watch_seconds' || counter === 'live_seconds') {
    const digits = value < 36000 ? 1 : 0;
    return (value / 3600).toLocaleString('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits }) + ' h';
  }
  if (counter === 'relay_seconds' || counter === 'ambient_seconds') return Math.floor(value / 60).toLocaleString('en-US') + ' min';
  return value.toLocaleString('en-US');
}

async function view(root, pool, current) {
  const user = current.user;
  await ensureHistory(root, pool, user.id);
  const counters = Object.fromEntries((await q(pool,
    'SELECT counter,value FROM vaultcast_achievement_counters WHERE user_id=?', [user.id])).map(row => [row.counter, Number(row.value)]));
  const earned = new Map((await q(pool,
    'SELECT ach_id,earned_at,showcase FROM vaultcast_achievements WHERE user_id=?', [user.id])).map(row => [row.ach_id, row]));
  counters.earned = earned.size;
  const stats = await rarity(root, pool);
  const byTier = Object.fromEntries(Object.keys(tiers).map(tier => [tier, 0]));
  const achievements = Object.entries(definitions).map(([id, [category, tier, icon, name, description, counter, goal, secret]]) => {
    const entry = earned.get(id);
    const hidden = secret && !entry;
    const value = Math.min(counters[counter] ?? 0, goal);
    if (entry) byTier[tier]++;
    return { id, category, category_label: categories[category], tier, tier_label: tiers[tier],
      icon: hidden ? '❔' : icon, name: hidden ? 'Secret achievement' : name,
      description: hidden ? 'Keep exploring to discover this one.' : description, secret, hidden,
      earned: Boolean(entry), earned_at: Number(entry?.earned_at ?? 0), showcase: Number(entry?.showcase ?? 0),
      progress: entry ? 1 : hidden || goal <= 1 ? 0 : Math.round(1000 * value / goal) / 1000,
      progress_text: entry || hidden || goal <= 1 ? '' : formatProgress(counter, value) + ' of ' + formatProgress(counter, goal),
      rarity: Number(stats.pct[id] ?? 0) };
  });
  const showcase = achievements.filter(a => a.showcase > 0).sort((a, b) => a.showcase - b.showcase).slice(0, 3).map(a => a.id);
  const { settings } = await parentalStatus(root, pool, current, user, now());
  return { ok: true, enabled: true, achievements, categories, tiers,
    summary: { earned: earned.size, total: achievements.length, tiers: byTier, people: stats.people },
    showcase, social: !(settings.enabled || settings.kid) || settings.social, showcase_max: 3 };
}

async function tick(connection, userId, kind, start) {
  const time = now();
  let changed = false;
  if (kind === 'ambient') {
    const [ambient] = await q(connection,
      "SELECT value FROM vaultcast_achievement_counters WHERE user_id=? AND counter='ambient' FOR UPDATE", [userId]);
    if (!(Number(ambient?.value) >= 1)) {
      await q(connection, "INSERT INTO vaultcast_achievement_counters (user_id,counter,value,updated_at) VALUES (?,'ambient',1,?) " +
        'ON DUPLICATE KEY UPDATE value=GREATEST(value,1),updated_at=VALUES(updated_at)', [userId, time]);
      changed = true;
    }
  }
  if (!start) {
    const clock = kind === 'ambient' ? 'tick_ambient' : 'tick_tv';
    const [last] = await q(connection,
      'SELECT value FROM vaultcast_achievement_counters WHERE user_id=? AND counter=? FOR UPDATE', [userId, clock]);
    if (time - Number(last?.value ?? 0) >= 50) {
      await q(connection, 'INSERT INTO vaultcast_achievement_counters (user_id,counter,value,updated_at) VALUES (?,?,?,?) ' +
        'ON DUPLICATE KEY UPDATE value=VALUES(value),updated_at=VALUES(updated_at)', [userId, clock, time, time]);
      const counters = kind === 'relay' ? ['relay_seconds', 'live_seconds'] : [kind + '_seconds'];
      for (const counter of counters) await q(connection,
        'INSERT INTO vaultcast_achievement_counters (user_id,counter,value,updated_at) VALUES (?,?,60,?) ' +
        'ON DUPLICATE KEY UPDATE value=value+60,updated_at=VALUES(updated_at)', [userId, counter, time]);
      changed = true;
    }
  }
  return changed ? award(connection, userId, time) : 0;
}

module.exports = function mountAchievements(app, prefix, { root, handle, json }) {
  const path = prefix + '/achievements';
  const authenticated = operation => handle((request, response, pool, _bcrypt, _settings, current) => {
    if (authorized(response, current)) return operation(request, response, pool, current);
  }, true);
  const write = (suffix, operation) => app.post(path + suffix, (request, response, next) => {
    if (!request.is('application/json')) return response.status(415).json({ ok: false, error: 'Expected a JSON request.' });
    json(request, response, error => error ? response.status(400).json({ ok: false, error: 'Invalid JSON request.' }) : next());
  }, authenticated(operation));

  app.get(path, authenticated(async (_request, response, pool, current) => {
    if (!signedIn(current.user)) return fail(response, 'Sign in to see your achievements.');
    if (!await achievementsEnabled(pool)) return response.json({ ok: true, enabled: false, achievements: [], categories: {}, summary: null });
    return response.json(await view(root, pool, current));
  }));
  app.get(path + '/pending', authenticated(async (_request, response, pool, { user }) => {
    if (!signedIn(user) || !await achievementsEnabled(pool)) return response.json({ ok: true, items: [] });
    const rows = await q(pool, 'SELECT ach_id,earned_at FROM vaultcast_achievements WHERE user_id=? AND toasted=0 ORDER BY earned_at,ach_id LIMIT 10', [user.id]);
    const items = rows.filter(row => Object.hasOwn(definitions, row.ach_id)).map(row => {
      const [, tier, icon, name, description] = definitions[row.ach_id];
      return { id: row.ach_id, name, description, icon, tier, tier_label: tiers[tier], earned_at: Number(row.earned_at) };
    });
    return response.json({ ok: true, items });
  }));
  write('/toasted', async (request, response, pool, { user }) => {
    if (!signedIn(user)) return fail(response, 'Sign in first.');
    const ids = knownIds(request.body?.ids, 20);
    if (ids.length) {
      response.locals.mutationStarted = true;
      await q(pool, 'UPDATE vaultcast_achievements SET toasted=1 WHERE user_id=? AND ach_id IN (' + ids.map(() => '?').join(',') + ')', [user.id, ...ids]);
    }
    return response.json({ ok: true });
  });
  write('/showcase', async (request, response, pool, { user }) => {
    if (!signedIn(user)) return fail(response, 'Sign in to choose your badges.');
    if (!await achievementsEnabled(pool)) return fail(response, 'Achievements are turned off on this server.');
    const ids = knownIds(request.body?.ids, 10);
    const error = await withUser(pool, user.id, async connection => {
      const earned = await q(connection, 'SELECT ach_id FROM vaultcast_achievements WHERE user_id=? FOR UPDATE', [user.id]);
      if (ids.some(id => !earned.some(row => row.ach_id === id))) return 'You can only show achievements you have earned.';
      if (ids.length > 3) return 'Choose up to three achievements.';
      response.locals.mutationStarted = true;
      await q(connection, 'UPDATE vaultcast_achievements SET showcase=0 WHERE user_id=? AND showcase<>0', [user.id]);
      for (const [index, id] of ids.entries()) await q(connection,
        'UPDATE vaultcast_achievements SET showcase=? WHERE user_id=? AND ach_id=?', [index + 1, user.id, id]);
    });
    return error ? fail(response, error) : response.json({ ok: true, showcase: ids });
  });
  write('/tick', async (request, response, pool, current) => {
    const user = current.user;
    if (!signedIn(user)) return fail(response, 'Sign in to earn achievements.');
    if (!await achievementsEnabled(pool)) return response.json({ ok: true, enabled: false });
    const { kind, start } = request.body ?? {};
    if (!['live', 'relay', 'ambient'].includes(kind)) return fail(response, 'Unknown activity.');
    response.locals.mutationStarted = true;
    const count = await withUser(pool, user.id, connection => tick(connection, user.id, kind, enabled(start)));
    if (count) {
      await invalidateRarity(root);
      if (current.data?.user_id === user.id) response.set('X-Vaultcast-Achievement', String(count));
    }
    return response.json({ ok: true, enabled: true });
  });
};
