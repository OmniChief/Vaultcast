'use strict';

const { readFile } = require('fs').promises;
const { join } = require('path');
const { sha, now, parse, enabled, accessState } = require('./account-data');

const isObject = value => value !== null && typeof value === 'object';
const values = value => isObject(value) ? Object.values(value) : [];
const has = (value, key) => Object.hasOwn(value, key);
const clamp = (value, low, high) => Math.max(low, Math.min(high, Math.trunc(Number(value) || 0)));

async function cached(root, key, time = now()) {
  const name = sha(key);
  const entry = await readFile(join(root, 'data/cache/kv', name.slice(0, 2), name + '.json'), 'utf8')
    .then(JSON.parse).catch(() => null);
  if (entry?.e == null || (Number(entry.e) !== 0 && Number(entry.e) <= time)) return null;
  return entry.v ?? null;
}

const parentalDefaults = { enabled: false, kid: false, ceiling: 5, unrated: 'allow', live: true, play: true, social: true, self_manage: false };
const ratings = [
  ['U', 'U / G · all ages'], ['PG', 'PG · parental guidance'], ['12', '12 and over'],
  ['15', '15 and over'], ['18', '18 and over'],
].map(([label, name], index) => ({ value: index + 1, label, name }));

async function parentalStatus(root, pool, current, user, time) {
  const admin = user.role === 'admin';
  let row;
  if (!admin) {
    const [rows] = await pool.execute("SELECT settings,pin_hash<>'' AS has_pin FROM vaultcast_parental WHERE user_id=?", [user.id]);
    row = rows[0];
  }
  const saved = row ? parse(row.settings) ?? {} : {};
  const settings = { ...parentalDefaults };
  for (const key of ['enabled', 'kid', 'live', 'play', 'social', 'self_manage']) {
    if (has(saved, key)) settings[key] = enabled(saved[key]);
  }
  if (saved.ceiling != null) settings.ceiling = clamp(saved.ceiling, 1, 5);
  if (saved.unrated === 'hide') settings.unrated = 'hide';
  settings.ceiling_label = ratings[settings.ceiling - 1].label;

  let unlocked = 0;
  if (current.browserToken) {
    const entry = await cached(root, 'parental-unlock:' + sha(current.browserToken), time);
    if (entry?.user_id === user.id) unlocked = Math.max(0, Math.trunc(Number(entry.until) || 0) - time);
  }
  return {
    ok: true, admin, settings, restricted: !admin && (settings.enabled || settings.kid) && unlocked === 0,
    kid: !admin && settings.kid, has_pin: Number(row?.has_pin) === 1, unlocked_for: unlocked,
    can_manage: !admin && (!row || settings.self_manage), ratings,
  };
}

function containsMediaCard(value, depth = 0) {
  if (!isObject(value) || depth > 13) return false;
  if (value.id != null && has(value, 'title') &&
      (['item', 'show', 'season'].includes(value.kind) || value.media_kind != null)) return true;
  return values(value).some(child => containsMediaCard(child, depth + 1));
}

function authorized(response, current) {
  if (!current.user) {
    response.status(401).json({ ok: false, error: 'Unauthorized' });
    return false;
  }
  const access = accessState(current.user, now());
  if (access.active) return true;
  response.status(403).json({
    ok: false, code: 'access_denied', access,
    error: access.state === 'expired' ? 'Your access has expired. Ask an administrator to renew it.'
      : 'Your account is waiting for an administrator to grant access.',
  });
  return false;
}

module.exports = { cached, parentalStatus, containsMediaCard, authorized };
