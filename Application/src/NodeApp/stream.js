'use strict';

const { constants } = require('fs');
const { open, realpath, stat } = require('fs').promises;
const { createHash } = require('crypto');
const { extname, isAbsolute, relative, sep } = require('path');
const { pipeline } = require('stream/promises');
const { documents, enabled, now, parse, sha } = require('./account-data');
const { authorized, cached, parentalStatus } = require('./profile-policy');
const { visibleCards } = require('./library');

const mime = new Map(Object.entries({
  mp4: 'video/mp4', m4v: 'video/mp4', webm: 'video/webm', ogg: 'video/ogg',
  mkv: 'video/x-matroska', avi: 'video/x-msvideo', mov: 'video/quicktime',
  mp3: 'audio/mpeg', flac: 'audio/flac', aac: 'audio/aac', m4a: 'audio/mp4',
  wav: 'audio/wav', opus: 'audio/opus',
}));

function mediaRange(header, size) {
  const match = header.trim().match(/^bytes=(\d*)-(\d*)$/);
  if (!match || (!match[1] && !match[2]) || !size) return null;
  const start = match[1] ? Number(match[1]) : Math.max(0, size - Number(match[2]));
  const end = match[1] && match[2] ? Math.min(size - 1, Number(match[2])) : size - 1;
  return start <= end ? { start, end } : null;
}

async function authorizePlayback(pool, root, request, response, readSession, token) {
  let session;
  if (/^Bearer(?:[ \t]|$)/i.test(String(request.headers.authorization ?? '').trim())) {
    session = await readSession(pool, request, true);
    if (session.error) {
      response.status(401).json({ ok: false, error: session.error });
      return null;
    }
  }
  const time = now();
  const hash = sha(token);
  const [rows] = await pool.execute(
    'SELECT expires_at,payload FROM vaultcast_playback_tokens WHERE token_hash=? AND expires_at>?',
    [hash, time]);
  if (!rows.length) return { expired: true };
  const playback = parse(rows[0].payload);
  const ownerId = playback.user_id;
  if (typeof ownerId !== 'string' || ['', 'public', 'live'].includes(ownerId) || playback.area !== 'library' ||
      !['video', 'audio'].includes(playback.media_kind) || typeof playback.item_id !== 'string' || !/^[a-f0-9]{16}$/.test(playback.item_id)) {
    return null;
  }

  const [owners] = await pool.execute('SELECT payload FROM vaultcast_users WHERE id=?', [ownerId]);
  if (!owners.length) {
    response.status(403).json({ ok: false, error: 'This account no longer exists.', code: 'access_denied' });
    return null;
  }
  const user = parse(owners[0].payload);
  if (!authorized(response, { user })) return null;
  if (user.role !== 'admin' && user.areas && typeof user.areas === 'object' && !enabled(user.areas.library)) {
    response.status(403).json({ ok: false, code: 'area_denied', area: 'library', error: 'Your account does not have access to Library.' });
    return null;
  }

  const [items] = await pool.execute('SELECT payload FROM vaultcast_items WHERE id=?', [playback.item_id]);
  if (!items.length) return null;
  const item = parse(items[0].payload);
  if (item.media_kind !== playback.media_kind || ![item.path, item.directplay_path].includes(playback.path) ||
      typeof playback.path !== 'string' || !isAbsolute(playback.path)) return null;
  if (user.role !== 'admin' && item.media_kind === 'video') {
    const parental = await parentalStatus(root, pool, {}, user, time);
    if (parental.restricted && request.headers.cookie) {
      session ??= await readSession(pool, request, true);
      if (session.data?.user_id === ownerId && session.browserToken) {
        // Only the token owner’s authenticated device can use its temporary unlock.
        const unlock = await cached(root, 'parental-unlock:' + sha(session.browserToken), time);
        if (unlock?.user_id === ownerId && Number(unlock.until) > time) parental.restricted = false;
      }
    }
    const showKey = typeof item.show_key === 'string' && item.show_key && item.show_key !== '0' ? item.show_key :
      (['tv', 'anime'].includes(item.type) && String(item.show ?? '') && item.section != null
      ? createHash('sha1').update(String(item.section ?? '') + '|' + item.show).digest('hex').slice(0, 16) : null);
    if (!(await visibleCards(pool, [{ id: item.id, media_kind: item.media_kind, show_key: showKey }], parental)).length) {
      response.status(403).json({ ok: false, code: 'parental_blocked', error: 'This title isn’t available on this profile.' });
      return null;
    }
  }

  const { settings = {} } = await documents(pool, ['settings']);
  const section = Object.values(settings.sections ?? {}).find(entry => entry?.id === item.section);
  if (typeof section?.path !== 'string' || !isAbsolute(section.path)) return null;

  const ttl = Math.max(3600, Math.min(86400, Math.trunc(Number(settings.streaming?.token_ttl_seconds ?? 28800)) || 3600));
  if (Number(rows[0].expires_at) - time < ttl / 2) {
    await pool.execute(
      "UPDATE vaultcast_playback_tokens SET expires_at=?,payload=JSON_SET(payload,'$.expires',?) " +
      'WHERE token_hash=? AND expires_at=? AND expires_at>?',
      [time + ttl, time + ttl, hash, rows[0].expires_at, time]);
  }
  return { file: playback.path, folder: section.path, accel: enabled(settings.streaming?.nginx_accel) };
}

module.exports = function mountStream(app, prefix, { db, root, readSession, updating }) {
  let transfers = 0;
  app.get(prefix + '/stream/play', async (request, response) => {
    const token = request.query.t;
    response.set({
      'Cache-Control': 'private, no-store',
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Expose-Headers': 'Content-Type, Content-Length, Content-Range, Accept-Ranges',
      'Referrer-Policy': 'no-referrer',
    });
    if (typeof token !== 'string' || token.length !== 48 || !/^[a-f0-9]{48}$/.test(token)) return response.status(403).type('text').send('Forbidden');
    const fallback = () => response.status(307)
      .location('/api/stream/play?t=' + token + (enabled(request.query.direct) ? '&direct=1' : '')).end();
    let file;
    try {
      if (await updating()) return response.set('Retry-After', '30').sendStatus(503);
      if (!db.ready() || request.headers.origin) return fallback();
      const media = await db.run(pool => authorizePlayback(pool, root, request, response, readSession, token));
      if (response.headersSent || response.destroyed) return;
      if (media?.expired) return response.status(403).type('text').send('Expired');
      if (!media) return fallback();
      const [resolved, folder] = await Promise.all([realpath(media.file), realpath(media.folder)]);
      const child = relative(folder, resolved);
      if (!child || child === '..' || child.startsWith('..' + sep) || isAbsolute(child)) return fallback();
      const type = mime.get(extname(resolved).slice(1).toLowerCase()) ?? 'application/octet-stream';
      const head = request.method === 'HEAD';
      if (!head && media.accel && !enabled(request.query.direct) && process.platform === 'linux') {
        if (!(await stat(resolved)).isFile()) return fallback();
        const uri = resolved.split('/').map(encodeURIComponent).join('/');
        return response.set({ 'Content-Type': type, 'Accept-Ranges': 'bytes', 'X-Accel-Redirect': '/_vaultcast_media' + uri }).end();
      }

      file = await open(resolved, constants.O_RDONLY | (constants.O_NONBLOCK ?? 0) | (constants.O_NOFOLLOW ?? 0));
      const info = await file.stat();
      if (!info.isFile() || !Number.isSafeInteger(info.size)) return fallback();
      const size = info.size;
      response.set({ 'Content-Type': type, 'Accept-Ranges': 'bytes' });
      if (head || !request.headers.range) response.set('Content-Length', String(size));
      if (head) return response.end();
      let range;
      if (request.headers.range) {
        range = mediaRange(request.headers.range, size);
        if (!range) return response.status(416).set('Content-Range', 'bytes */' + size).end();
        const { start, end } = range;
        response.status(206).set({
          'Content-Range': 'bytes ' + start + '-' + end + '/' + size,
          'Content-Length': String(end - start + 1),
        });
      }
      if (!size) return response.end();
      if (!response.destroyed) {
        response.set('X-Accel-Buffering', 'no');
        transfers++;
        try {
          await pipeline(file.createReadStream({ ...range, autoClose: false }), response);
        } finally {
          transfers--;
        }
      }
    } catch (error) {
      if (response.destroyed) return;
      if (response.headersSent) response.destroy();
      else return fallback();
    } finally {
      if (file) await file.close().catch(() => {});
    }
  });
  return () => transfers > 0;
};
