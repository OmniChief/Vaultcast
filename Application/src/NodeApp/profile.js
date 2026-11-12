'use strict';

const { readFile } = require('fs').promises;
const { join } = require('path');
const { sha, now, parse, enabled, storedBoolean, documents, accessState, publicUser } = require('./account-data');

const isObject = value => value !== null && typeof value === 'object';
const values = value => isObject(value) ? Object.values(value) : [];
const has = (value, key) => Object.hasOwn(value, key);
const trim = value => value.replace(/^[ \t\n\r\0\v]+|[ \t\n\r\0\v]+$/g, '');
const chars = (value, max) => Array.from(value).slice(0, max).join('');
const text = value => typeof value === 'string' || typeof value === 'number' ? String(value) : value === true ? '1' : '';
const clamp = (value, low, high) => Math.max(low, Math.min(high, Math.trunc(Number(value) || 0)));

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
    const name = sha('parental-unlock:' + sha(current.browserToken));
    const entry = await readFile(join(root, 'data/cache/kv', name.slice(0, 2), name + '.json'), 'utf8')
      .then(JSON.parse).catch(() => null);
    if (entry?.e != null && (Number(entry.e) === 0 || Number(entry.e) > time) && entry.v?.user_id === user.id) {
      unlocked = Math.max(0, Math.trunc(Number(entry.v.until) || 0) - time);
    }
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

async function updateUser(pool, id, apply) {
  const conn = await pool.getConnection();
  let usable = true;
  try {
    await conn.beginTransaction();
    const [rows] = await conn.execute('SELECT payload,password_hash FROM vaultcast_users WHERE id=? FOR UPDATE', [id]);
    if (!rows[0]) {
      await conn.rollback();
      return null;
    }
    const user = parse(rows[0].payload);
    if (!accessState(user, now()).active) {
      await conn.rollback();
      return null;
    }
    const updated = apply(user);
    await conn.execute('UPDATE vaultcast_users SET payload=?,password_hash=? WHERE id=?',
      [JSON.stringify(updated), updated.password_hash ?? rows[0].password_hash, id]);
    await conn.execute("INSERT INTO vaultcast_documents (name,payload) VALUES ('users','[]') ON DUPLICATE KEY UPDATE payload=VALUES(payload),revision=revision+1");
    await conn.commit();
    return updated;
  } catch (error) {
    try { await conn.rollback(); }
    catch (rollbackError) { usable = false; conn.destroy(); }
    throw error;
  } finally {
    if (usable) conn.release();
  }
}

function preferencePatch(body) {
  const patch = {};
  for (const key of ['theme', 'autoplay', 'default_quality', 'language']) {
    if (has(body, key)) patch[key] = body[key];
  }
  if (has(body, 'motion_mode')) {
    patch.motion_mode = body.motion_mode;
    patch.reduce_motion = body.motion_mode === 'reduced';
  } else if (has(body, 'reduce_motion')) {
    patch.reduce_motion = [true, 1, '1', 'true'].includes(body.reduce_motion);
    patch.motion_mode = patch.reduce_motion ? 'reduced' : 'full';
  }
  for (const key of ['autoplay_on_open', 'autoplay_next', 'mini_player', 'skip_segments',
    'auto_skip_intro', 'auto_skip_outro', 'hide_spoilers', 'home_compact']) {
    if (has(body, key)) patch[key] = enabled(body[key]);
  }
  const panels = ['recent', 'games', 'watchlist', 'friends', 'calendar', 'stats'];
  if (isObject(body.home_panels)) patch.home_panels = [...new Set(values(body.home_panels).filter(value => panels.includes(value)))];
  const landings = ['overview', 'watchlist', 'favourites', 'calendar', 'friends', 'cinema', 'history', 'stats', 'inbox', 'arrivals', 'lists'];
  if (landings.includes(body.home_landing)) patch.home_landing = body.home_landing;
  if (has(body, 'live_cat_formats')) {
    const formats = typeof body.live_cat_formats === 'string' ? body.live_cat_formats.split(',') : values(body.live_cat_formats);
    const cleaned = formats.map(value => trim(text(value)).replace(/^\.+/, '').toLowerCase())
      .filter(value => /^[a-z0-9]{1,8}$/.test(value));
    patch.live_cat_formats = [...new Set(cleaned)].slice(0, 30).sort();
  }
  return patch;
}

function favouritePatch(body) {
  const patch = {};
  if (!has(body, 'favourites') && !has(body, 'defaults')) throw new Error('No favourite settings supplied.');
  if (has(body, 'favourites')) {
    if (!isObject(body.favourites) || values(body.favourites).length > 200) throw new Error('Invalid Stalker favourites.');
    const unique = new Set();
    patch.stalker_vod_favourites = [];
    for (const item of values(body.favourites)) {
      if (!item || !['vod', 'series'].includes(item.tab)) throw new Error('Invalid Stalker favourite.');
      const name = trim(text(item.name));
      if (!name || Array.from(name).length > 200) throw new Error('Invalid favourite title.');
      const year = chars(text(item.year), 4);
      const key = item.tab + '|' + name.toLowerCase() + '|' + year;
      if (unique.has(key)) continue;
      unique.add(key);
      patch.stalker_vod_favourites.push({
        name, tab: item.tab, year, language: chars(text(item.language), 50), folder: chars(text(item.folder), 200),
      });
    }
  }
  if (has(body, 'defaults')) {
    if (!isObject(body.defaults)) throw new Error('Invalid preferred providers.');
    patch.stalker_vod_defaults = {};
    for (const kind of ['vod', 'series']) {
      const entry = body.defaults[kind];
      if (entry == null) { patch.stalker_vod_defaults[kind] = null; continue; }
      const keys = ['id', 'section', 'language', 'type'];
      if (keys.some(key => typeof entry[key] !== 'string' || !entry[key] || Array.from(entry[key]).length > 160) ||
          !['provider', 'folder'].includes(entry.type)) throw new Error('Invalid default provider.');
      patch.stalker_vod_defaults[kind] = Object.fromEntries(keys.map(key => [key, entry[key]]));
    }
  }
  return patch;
}

module.exports = function mountProfiles(app, prefix, { root, handle, unavailable, passwordWork, json }) {
  const read = operation => handle(operation, true);
  const write = (path, operation) => app.post(prefix + path, (request, response, next) => {
    response.set('Cache-Control', 'no-store');
    if (!request.is('application/json')) return response.status(415).json({ ok: false, error: 'Expected a JSON request.' });
    json(request, response, error => error ? response.status(400).json({ ok: false, error: 'Invalid JSON request.' }) : next());
  }, read(operation));
  const profileDocs = user => ['permission_roles', 'media_requests_config', 'contribution-account-' + sha(String(user.id))];

  app.get(prefix + '/me', read(async (_request, response, pool, _bcrypt, settings, current) => {
    const user = current.user;
    if (!user) {
      // The legacy web route has the verified peer address for LAN guest access.
      if (settings.auth?.lan_guest_access !== false) return unavailable(response);
      return response.json({ ok: true, user: null, authenticated: false, guest_browsing: false, pending_convert_count: 0 });
    }
    const time = now();
    const admin = user.role === 'admin';
    const offlineDoc = 'offline-devices:' + sha(String(user.id));
    const names = [...profileDocs(user), offlineDoc, 'achievement-settings'];
    if (admin) names.push('convert_requests');
    const docs = await documents(pool, names);
    const profile = publicUser({ ...user, _session: current.data }, settings, docs, time);
    profile.parental = await parentalStatus(root, pool, current, user, time);
    if (profile.parental.restricted && containsMediaCard(profile)) return unavailable(response);
    const library = admin || !isObject(user.areas) || enabled(user.areas.library);
    const signedIn = Boolean(user.id) && user.role !== 'guest';
    const offline = enabled(settings.features?.offline);
    profile.offline = {
      ok: true, enabled: offline, allowed: signedIn && offline && library && profile.capabilities['offline.download'],
      max_items: clamp(settings.offline?.max_items, 0, 500),
      held: values(docs[offlineDoc]).reduce((total, row) => total + values(row?.items).length, 0),
    };
    const requests = storedBoolean(docs.media_requests_config, 'enabled', true);
    profile.requests = {
      enabled: requests,
      create: signedIn && Boolean(user.role) && requests && library && profile.capabilities['requests.create'],
      manage: admin || (!profile.parental.restricted && library && profile.capabilities['requests.manage']),
    };
    profile.achievements = {
      enabled: signedIn && Boolean(user.role) && user.id !== 'public' && storedBoolean(docs['achievement-settings'], 'enabled', true),
    };
    response.json({
      ok: true, user: profile, authenticated: true,
      pending_convert_count: admin ? values(docs.convert_requests).filter(row => row?.status === 'pending').length : 0,
    });
  }));

  write('/me/prefs', async (request, response, pool, bcrypt, settings, current) => {
    if (!authorized(response, current)) return;
    const body = request.body ?? {};
    if (has(body, 'motion_mode') && !['system', 'full', 'reduced'].includes(body.motion_mode)) {
      return response.status(422).json({ ok: false, error: 'Choose a valid motion setting.' });
    }
    if (enabled(body.avatar_data) || enabled(body.remove_avatar)) return unavailable(response);
    if (!/^[a-f0-9]{16,64}$/.test(current.user.id)) return response.status(422).json({ ok: false, error: 'Invalid profile' });
    const patch = preferencePatch(body);
    const docs = await documents(pool, profileDocs(current.user));
    const parental = await parentalStatus(root, pool, current, current.user, now());
    if (parental.restricted && (containsMediaCard(current.user) || containsMediaCard(patch) || containsMediaCard(docs))) {
      return unavailable(response);
    }
    let passwordHash;
    if (enabled(body.password)) {
      if (enabled(current.data?.api_key)) return response.status(403).json({ ok: false, error: 'Sign in through your browser to change your password.' });
      if (typeof body.password !== 'string' || Buffer.byteLength(body.password) < 12 ||
          Buffer.byteLength(body.password) > 72 || body.password.includes('\0')) {
        return response.status(400).json({ ok: false, error: 'Use a password between 12 and 72 bytes.' });
      }
      passwordHash = await passwordWork(bcrypt, body.password);
    }
    response.locals.mutationStarted = true;
    const saved = await updateUser(pool, current.user.id, user => ({
      ...user, avatar_version: user.avatar_version ?? null, prefs: { ...user.prefs, ...patch },
      ...(enabled(body.display_name) && typeof body.display_name === 'string' ? { display_name: trim(body.display_name) } : {}),
      ...(passwordHash ? { password_hash: passwordHash } : {}),
    }));
    if (!saved) return response.status(401).json({ ok: false, error: 'Unauthorized' });
    const profile = publicUser(saved, settings, docs, now());
    delete profile.access_state; // The legacy preferences response leaves access state to /me.
    response.json({ ok: true, user: profile });
  });

  app.get(prefix + '/me/stalker-vod-favourites', read(async (_request, response, pool, _bcrypt, _settings, current) => {
    if (!authorized(response, current)) return;
    const prefs = current.user.prefs ?? {};
    const result = { ok: true, favourites: prefs.stalker_vod_favourites ?? null, defaults: prefs.stalker_vod_defaults ?? null };
    if (containsMediaCard(result) && (await parentalStatus(root, pool, current, current.user, now())).restricted) return unavailable(response);
    response.json(result);
  }));

  write('/me/stalker-vod-favourites', async (request, response, pool, _bcrypt, _settings, current) => {
    if (!authorized(response, current)) return;
    let patch;
    try { patch = favouritePatch(request.body ?? {}); }
    catch (error) { return response.status(422).json({ ok: false, error: error.message }); }
    response.locals.mutationStarted = true;
    const saved = await updateUser(pool, current.user.id, user => ({ ...user, prefs: { ...user.prefs, ...patch } }));
    if (!saved) return response.status(401).json({ ok: false, error: 'Unauthorized' });
    response.json({ ok: true });
  });
};
