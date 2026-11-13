'use strict';

const { constants } = require('fs');
const { open, stat } = require('fs').promises;
const { extname, isAbsolute } = require('path');
const { pipeline } = require('stream/promises');
const { documents, enabled, now, parse, sha } = require('./account-data');
const { authorized, cached, parentalStatus } = require('./profile-policy');
const { areaAllowed, areaDenied, playbackVisible } = require('./library');
const { libraryFile, mimeFor, tokenLifetime } = require('./playback');
const { subtitles, readSubtitle } = require('./subtitles');


function mediaRange(header, size) {
  const match = header.trim().match(/^bytes=(\d*)-(\d*)$/);
  if (!match || (!match[1] && !match[2]) || !size) return null;
  const start = match[1] ? Number(match[1]) : Math.max(0, size - Number(match[2]));
  const end = match[1] && match[2] ? Math.min(size - 1, Number(match[2])) : size - 1;
  return start <= end ? { start, end } : null;
}

async function authorizePlayback(pool, root, request, response, readSession, token, refresh = true) {
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
  if (!areaAllowed(user, 'library')) {
    areaDenied(response, 'library');
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
    if (!await playbackVisible(pool, item, parental)) {
      response.status(403).json({ ok: false, code: 'parental_blocked', error: 'This title isn’t available on this profile.' });
      return null;
    }
  }

  const { settings = {} } = await documents(pool, ['settings']);
  const section = Object.values(settings.sections ?? {}).find(entry => entry?.id === item.section);
  if (typeof section?.path !== 'string' || !isAbsolute(section.path)) return null;

  const ttl = tokenLifetime(settings);
  if (refresh && Number(rows[0].expires_at) - time < ttl / 2) {
    await pool.execute(
      "UPDATE vaultcast_playback_tokens SET expires_at=?,payload=JSON_SET(payload,'$.expires',?) " +
      'WHERE token_hash=? AND expires_at=? AND expires_at>?',
      [time + ttl, time + ttl, hash, rows[0].expires_at, time]);
  }
  return { file: playback.path, folder: section.path, kind: playback.media_kind, captionSource: playback.caption_source, accel: enabled(settings.streaming?.nginx_accel) };
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
      const resolved = await libraryFile(media.file, media.folder);
      if (!resolved) return fallback();
      const type = mimeFor(extname(resolved).slice(1).toLowerCase());
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
  app.get(prefix + '/stream/subtitles/:token([a-f0-9]{48})/:id([a-f0-9]{64}).vtt', async (request, response) => {
    const { token, id } = request.params;
    const fallback = () => response.status(307).location('/api/stream/subtitles/' + token + '/' + id + '.vtt').end();
    response.set({ 'Cache-Control': 'private, no-store', 'X-Content-Type-Options': 'nosniff', 'Referrer-Policy': 'no-referrer' });
    try {
      if (await updating()) return response.set('Retry-After', '30').sendStatus(503);
      if (!db.ready() || request.headers.origin) return fallback();
      const media = await db.run(pool => authorizePlayback(pool, root, request, response, readSession, token, false));
      if (response.headersSent || response.destroyed) return;
      if (media?.expired || (media && media.kind !== 'video')) {
        return response.status(403).json({ ok: false, error: 'Playback token expired.' });
      }
      if (!media || !await libraryFile(media.file, media.folder)) return fallback();
      if (media.captionSource && media.captionSource !== media.file && !await libraryFile(media.captionSource, media.folder)) {
        return fallback();
      }
      const track = (await subtitles(root, media.file, media.captionSource)).get(id);
      if (!track) return fallback();
      const body = await readSubtitle(track);
      if (body === null) return fallback();
      response.set({ 'Content-Type': 'text/vtt; charset=utf-8', 'Cache-Control': 'private, max-age=300',
        'Content-Length': String(Buffer.byteLength(body)) });
      return response.end(request.method === 'HEAD' ? undefined : body);
    } catch (error) {
      if (!response.headersSent && !response.destroyed) return fallback();
    }
  });
  return () => transfers > 0;
};
