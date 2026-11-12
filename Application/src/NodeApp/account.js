'use strict';

const { createHash, randomBytes } = require('crypto');
const { readFile } = require('fs').promises;
const { join } = require('path');
const { Worker } = require('worker_threads');
const database = require('./database');

const capabilities = [
  'ingest_view', 'ingest_import', 'rss_search', 'downloads_request', 'fix_match',
  'metadata_edit', 'library_remove', 'timing_edit', 'artwork_manage', 'age_ratings',
  'offline.download', 'iptv_manage', 'radio_manage', 'drop.upload', 'pictures.upload',
  'save_copy', 'requests.create', 'requests.manage',
];
const cookieName = 'vaultcast_session';
const sha = value => createHash('sha256').update(value).digest('hex');
const now = () => Math.floor(Date.now() / 1000);
const parse = value => typeof value === 'string' ? JSON.parse(value) : value;
const enabled = value => Array.isArray(value) ? value.length > 0 : Boolean(value && value !== '0');
const storedBoolean = (settings, key, fallback) =>
  settings && Object.hasOwn(settings, key) ? enabled(settings[key]) : fallback;

async function documents(pool, names) {
  const placeholders = names.map(() => '?').join(',');
  const [rows] = await pool.execute('SELECT name,payload FROM vaultcast_documents WHERE name IN (' + placeholders + ')', names);
  return Object.fromEntries(rows.map(row => [row.name, parse(row.payload)]));
}

function browserToken(request) {
  const match = String(request.headers.cookie || '').match(/(?:^|;\s*)vaultcast_session=([a-f0-9]{64})(?=;|$)/);
  return match?.[1] || '';
}

async function session(pool, request) {
  const authorization = String(request.headers.authorization || '').trim();
  const bearer = /^Bearer(?:[ \t]|$)/i.test(authorization);
  const token = bearer ? authorization.match(/^Bearer[ \t]+(vc_[a-f0-9]{64})$/i)?.[1] : browserToken(request);
  if (!token) return { authenticated: false, error: bearer ? 'Invalid API key.' : null };
  const [rows] = await pool.execute(
    'SELECT s.payload,u.id FROM vaultcast_sessions s LEFT JOIN vaultcast_users u ON u.id=s.user_id WHERE s.token_hash=? AND s.expires_at>=?',
    [sha(token), now()]);
  const entry = rows[0];
  if (bearer && (!entry?.id || !enabled(parse(entry.payload)?.api_key))) {
    return { authenticated: false, error: 'API key is invalid or expired.' };
  }
  return { authenticated: Boolean(entry), error: null };
}

function requestAllowed(request, settings) {
  const site = request.headers['sec-fetch-site'];
  if (site === 'cross-site') return false;
  const origin = request.headers.origin;
  if (!origin) return true;
  try {
    const url = new URL(origin);
    if (!['http:', 'https:'].includes(url.protocol) || url.origin !== origin) return false;
    const authority = new URL(url.protocol + '//' + request.headers.host);
    if (authority.origin === url.origin || (site === 'same-origin' && authority.hostname === url.hostname)) return true;
    return settings.public_base_url ? new URL(settings.public_base_url).origin === url.origin : false;
  } catch (error) {
    return false;
  }
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

function verifyPassword(bcrypt, password, hash) {
  return new Promise((resolve, reject) => {
    const worker = new Worker(join(__dirname, 'verify-password.js'), {
      workerData: { bcrypt, password, hash }, resourceLimits: { maxOldGenerationSizeMb: 16 },
    });
    const timer = setTimeout(() => { void worker.terminate(); reject(new Error('Password verification timed out')); }, 8000);
    worker.once('message', match => { clearTimeout(timer); resolve(match === true); });
    worker.once('error', error => { clearTimeout(timer); reject(error); });
    worker.once('exit', () => { clearTimeout(timer); reject(new Error('Password verifier exited')); });
  });
}

module.exports = function mountAccount(app, express, root) {
  const db = database(root);
  const route = '/application';
  const version = readFile(join(root, 'VERSION'), 'utf8').then(text => text.trim()).catch(() => '');
  let passwordChecks = 0;
  let lastPrune = 0;
  const json = express.json({ limit: '16kb' });
  const emptyError = (response, code, error) => response.status(code).json({ ok: false, error });
  function unavailable(response) {
    return emptyError(response.set('X-Vaultcast-App-Unavailable', '1'), 503, 'The Node application backend is unavailable.');
  }
  const handle = operation => async (request, response) => {
    response.set({ 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff' });
    if (!db.ready()) return unavailable(response);
    try {
      const marker = await readFile(join(root, 'data/runtime/maintenance.json'), 'utf8').then(JSON.parse).catch(() => null);
      if (marker && Number(marker.since) > now() - 900) return emptyError(response.set('Retry-After', '30'), 503, 'Vaultcast is updating.');
      await db.run(async (pool, bcrypt) => {
        const settings = (await documents(pool, ['settings'])).settings ?? {};
        if (request.method === 'POST' && !requestAllowed(request, settings)) {
          return emptyError(response, 403, 'Cross-site request rejected.');
        }
        const current = await session(pool, request);
        if (current.error) return emptyError(response, 401, current.error);
        return operation(request, response, pool, bcrypt, settings, current);
      });
    } catch (error) {
      if (response.headersSent || response.destroyed) return;
      if (response.locals.mutationStarted) return emptyError(response, 503, 'The account request could not be completed.');
      return unavailable(response);
    }
  };

  app.get(route + '/status', handle(async (_request, response, _pool, _bcrypt, settings, current) => {
    response.json({
      ok: true, app: settings.app_name ?? 'Vaultcast', version: await version,
      authenticated: current.authenticated, base: '/', public: true,
      drop_anonymous: storedBoolean(settings.drop, 'enabled', true) && enabled(settings.drop?.anonymous),
    });
  }));

  app.post(route + '/login', (request, response, next) => {
    if (!request.is('application/json')) return emptyError(response, 415, 'Expected a JSON request.');
    json(request, response, error => error ? emptyError(response, 400, 'Invalid JSON request.') : next());
  }, handle(async (request, response, pool, bcrypt, settings) => {
    const { username, password } = request.body ?? {};
    if (typeof username !== 'string' || typeof password !== 'string' || Buffer.byteLength(username) > 255 ||
        Buffer.byteLength(password) > 4096 || password.includes('\0')) {
      return emptyError(response, 401, 'Invalid username or password');
    }
    if (passwordChecks >= 2) return emptyError(response, 429, 'Too many sign-in requests. Try again shortly.');
    passwordChecks++;
    let user;
    let verifiedHash;
    try {
      const [rows] = await pool.execute('SELECT payload,password_hash FROM vaultcast_users WHERE username=?', [username.trim()]);
      const hash = rows[0]?.password_hash;
      if (typeof hash !== 'string') return emptyError(response, 401, 'Invalid username or password');
      if (!/^\$2[aby]\$\d{2}\$[./A-Za-z0-9]{53}$/.test(hash)) return unavailable(response);
      if (!await verifyPassword(bcrypt, password, hash)) return emptyError(response, 401, 'Invalid username or password');
      user = parse(rows[0].payload);
      verifiedHash = hash;
    } finally { passwordChecks--; }
    const token = randomBytes(32).toString('hex');
    const time = now();
    const configuredTtl = Number(settings.auth?.session_ttl_seconds ?? 604800);
    const ttl = Number.isSafeInteger(configuredTtl) && Math.abs(configuredTtl) <= 3153600000 ? configuredTtl : 604800;
    const data = {
      user_id: user.id, username: user.username, role: user.role ?? 'user',
      created_at: time, expires_at: time + ttl, ip: request.socket.remoteAddress ?? '0.0.0.0',
    };
    const docs = await documents(pool, ['permission_roles', 'media_requests_config', 'contribution-account-' + sha(String(user.id))]);
    const profile = publicUser(user, settings, docs, time);
    // Reject a credential changed or account deleted between lookup and session creation.
    response.locals.mutationStarted = true;
    const [inserted] = await pool.execute(
      'INSERT INTO vaultcast_sessions (token_hash,user_id,expires_at,payload) SELECT ?,id,?,? FROM vaultcast_users WHERE id=? AND BINARY password_hash=?',
      [sha(token), data.expires_at, JSON.stringify(data), user.id, verifiedHash]);
    if (!inserted.affectedRows) return emptyError(response, 401, 'Invalid username or password');
    if (time - lastPrune >= 300) {
      lastPrune = time;
      await pool.execute('DELETE FROM vaultcast_sessions WHERE expires_at<? LIMIT 1000', [time]).catch(() => {});
    }
    const secure = request.secure || request.headers['x-forwarded-proto'] === 'https' || String(request.headers.origin || '').startsWith('https://');
    response.cookie(cookieName, token, { expires: new Date(data.expires_at * 1000), path: '/', secure, httpOnly: true, sameSite: 'lax' });
    response.json({ ok: true, token, user: profile });
  }));

  app.post(route + '/logout', handle(async (request, response, pool) => {
    const token = browserToken(request);
    if (token) {
      response.locals.mutationStarted = true;
      await pool.execute('DELETE FROM vaultcast_sessions WHERE token_hash=?', [sha(token)]);
    }
    response.clearCookie(cookieName, { path: '/', httpOnly: true, sameSite: 'lax' }).json({ ok: true });
  }));
  return db;
};
