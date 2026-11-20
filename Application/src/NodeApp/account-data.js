'use strict';

const { createHash } = require('crypto');

const capabilities = [
  'ingest_view', 'ingest_import', 'rss_search', 'downloads_request', 'fix_match',
  'metadata_edit', 'library_remove', 'timing_edit', 'artwork_manage', 'age_ratings',
  'offline.download', 'iptv_manage', 'radio_manage', 'drop.upload', 'pictures.upload',
  'save_copy', 'requests.create', 'requests.manage',
];
const sha = value => createHash('sha256').update(value).digest('hex');
const now = () => Math.floor(Date.now() / 1000);
const parse = value => typeof value === 'string' ? JSON.parse(value) : value;
const enabled = value => value && typeof value === 'object' ? Object.keys(value).length > 0 : Boolean(value && value !== '0');
const storedBoolean = (settings, key, fallback) =>
  settings && Object.hasOwn(settings, key) ? enabled(settings[key]) : fallback;

async function documents(pool, names) {
  const placeholders = names.map(() => '?').join(',');
  const [rows] = await pool.execute('SELECT name,payload FROM vaultcast_documents WHERE name IN (' + placeholders + ')', names);
  return Object.fromEntries(rows.map(row => [row.name, parse(row.payload)]));
}

function accessState(user, time) {
  const common = { expires_at: null, remaining: null };
  if (user.role === 'admin') return { ...common, state: 'admin', active: true };
  const access = user.access;
  if (!access || typeof access !== 'object') return { ...common, state: 'unlimited', active: true, migrated: true };
  if (access.mode === 'unlimited') return { ...common, state: 'unlimited', active: true };
  if (access.mode === 'until' && access.until != null) {
    const expires = Math.trunc(Number(access.until) || 0);
    return { expires_at: expires, remaining: Math.max(0, expires - time), state: expires > time ? 'active' : 'expired', active: expires > time };
  }
  return { ...common, state: 'pending', active: false };
}

function publicUser(user, settings, docs, time) {
  const role = user.role ?? 'user';
  const defaults = {
    'drop.upload': enabled(settings.drop?.user_role_upload),
    'pictures.upload': storedBoolean(settings.pictures, 'user_role_upload', true),
    'offline.download': storedBoolean(settings.offline, 'user_role', true),
    'requests.create': storedBoolean(docs.media_requests_config, 'user_role', true),
  };
  const roles = docs.permission_roles ?? { curator: { permissions: {} } };
  const allowed = key => {
    if (role === 'admin') return true;
    if (role === 'user') {
      if (!Object.hasOwn(defaults, key)) return false;
      const override = user.permissions?.[key];
      return typeof override === 'boolean' ? override : defaults[key];
    }
    if (!Object.hasOwn(roles, role)) return false;
    if (key === 'ingest_view' && allowed('ingest_import')) return true;
    return (user.permissions?.[key] ?? roles[role]?.permissions?.[key]) === true;
  };
  const { password_hash, ...profile } = user;
  return {
    ...profile,
    access_state: accessState(user, time),
    capabilities: Object.fromEntries(capabilities.map(key => [key, allowed(key)])),
    contribution_points: {
      balance: 0, earned: 0, spent: 0, admin_added: 0, images_generated: 0, videos_converted: 0,
      ...docs['contribution-account-' + sha(String(user.id))],
    },
  };
}

module.exports = { sha, now, parse, enabled, storedBoolean, documents, accessState, publicUser };
