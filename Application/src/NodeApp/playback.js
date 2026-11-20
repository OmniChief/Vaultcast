'use strict';

const { randomBytes, randomInt } = require('crypto');
const { stat } = require('fs').promises;
const { extname, isAbsolute } = require('path');
const { documents, enabled, now, parse, sha, publicUser } = require('./account-data');
const { probe, compatibleAudio, libraryFile, libraryCaptionSource } = require('./media-probe');
const mountConversions = require('./library-conversions');
const { activeConversion } = require('./conversion-playback');
const { areaAllowed, areaDenied, itemArea, mediaSignature, playbackVisible } = require('./library');
const { authorized, parentalStatus } = require('./profile-policy');
const { subtitles } = require('./subtitles');

const mime = new Map(Object.entries({
  mp4: 'video/mp4', m4v: 'video/mp4', webm: 'video/webm', ogg: 'video/ogg',
  mkv: 'video/x-matroska', avi: 'video/x-msvideo', mov: 'video/quicktime',
  mp3: 'audio/mpeg', flac: 'audio/flac', aac: 'audio/aac', m4a: 'audio/mp4',
  wav: 'audio/wav', opus: 'audio/opus',
}));
const convertExtensions = new Set(['mkv', 'avi', 'wmv', 'mov', 'ts', 'm2ts', 'mpg', 'mpeg', 'flv', 'divx', 'xvid']);

function mimeFor(extension) {
  return mime.get(extension) ?? 'application/octet-stream';
}

function tokenLifetime(settings) {
  return Math.max(3600, Math.min(86400, Math.trunc(Number(settings.streaming?.token_ttl_seconds ?? 28800)) || 3600));
}

async function isFile(path) {
  try { return (await stat(path)).isFile(); }
  catch (error) {
    if (['ENOENT', 'ENOTDIR'].includes(error.code)) return false;
    throw error;
  }
}

function conversionStatus(queue, id) {
  const jobs = Object.values(queue ?? {}).filter(job => job?.item_id === id);
  const active = jobs.find(job => ['queued', 'running'].includes(job.status));
  const lastResult = jobs.reverse().find(job => enabled(job.dest_deleted) || job.status === 'done');
  return {
    blocked: enabled(lastResult?.dest_deleted),
    active: active ? { status: active.status, progress: Math.max(0, Math.min(100, Number(active.progress) || 0)) } : null,
  };
}

module.exports = function mountPlayback(app, prefix, { root, db, handle, json, unavailable }) {
  app.get(prefix + '/items/:id([a-f0-9]{16})/probe', handle(async (request, response, pool, _bcrypt, settings, current) => {
    if (!authorized(response, current)) return;
    const [rows] = await pool.execute('SELECT payload FROM vaultcast_items WHERE id=?', [request.params.id]);
    if (!rows.length) return response.status(404).json({ ok: false, error: 'Not found' });
    const item = parse(rows[0].payload);
    const parental = await parentalStatus(root, pool, current, current.user, now());
    if (!areaAllowed(current.user, itemArea(item), parental)) return areaDenied(response, itemArea(item));
    if (!await playbackVisible(pool, item, parental)) return response.status(403).json({ ok: false, code: 'parental_blocked', error: 'This title isn’t available on this profile.' });
    const section = Object.values(settings.sections ?? {}).find(entry => entry?.id === item.section);
    return async () => {
      const path = enabled(settings.convert?.prefer_directplay ?? true) && enabled(item.directplay_ready) &&
        item.directplay_path && await isFile(item.directplay_path) ? item.directplay_path : item.path;
      const file = section?.path && path && await isFile(path) ? await libraryFile(path, section.path) : null;
      response.json({ ...await probe(root, file, settings), item_id: item.id, title: item.title ?? '' });
    };
  }, true));
  app.post(prefix + '/items/:id([a-f0-9]{16})/play', (request, response, next) => {
    if (!request.is('application/json')) return unavailable(response);
    json(request, response, error => error ? unavailable(response) : next());
  }, handle(async (request, response, pool, _bcrypt, settings, current) => {
    if (!current.user && settings.auth?.lan_guest_access !== false) return unavailable(response);
    if (!current.authenticated) {
      return response.status(401).json({ ok: false, code: 'login_required', error: 'Sign in to continue.' });
    }
    if (!authorized(response, current)) return;
    const user = current.user;
    const body = request.body;
    if (!body || Object.keys(body).some(key => !['audio', 'external', 'original', 'probe'].includes(key)) ||
        Object.keys(request.query).length ||
        (body.audio != null && typeof body.audio !== 'number' && typeof body.audio !== 'string')) return unavailable(response);

    const { id } = request.params;
    const external = enabled(body.external);
    const [rows] = await pool.execute(
      'SELECT i.payload,f.sig,f.duration,f.video_codec,f.audio_codec,p.payload AS progress FROM vaultcast_items i ' +
      'LEFT JOIN vaultcast_media_facts f ON f.item_id=i.id ' +
      'LEFT JOIN vaultcast_progress p ON p.item_id=i.id AND p.user_id=? AND LENGTH(p.payload)<=1048576 ' +
      'WHERE i.id=?', [user.id, id]);
    const row = rows[0];
    if (!row) return unavailable(response);
    const item = parse(row.payload);
    if (!['video', 'audio'].includes(item.media_kind)) return unavailable(response);
    const parental = await parentalStatus(root, pool, current, user, now());
    const area = itemArea(item);
    if (!areaAllowed(user, area, parental)) return areaDenied(response, area);
    if (!await playbackVisible(pool, item, parental)) {
      return response.status(403).json({ ok: false, code: 'parental_blocked', error: 'This title isn’t available on this profile.' });
    }

    const video = item.media_kind === 'video';
    const remuxAllowed = settings.streaming?.directplay_only === false && settings.streaming.allow_remux !== false;
    const transcodeAllowed = settings.streaming?.directplay_only === false && enabled(settings.transcoding?.enabled);
    const eligible = video && ['movies', 'disney', 'tv', 'anime'].includes(item.type);
    const transcode = eligible && transcodeAllowed;
    const canSave = !external && transcode && !item.directplay_path && !/\.directplay\.[^.]+$/i.test(item.path) &&
      enabled(settings.transcoding?.allow_save ?? true) &&
      publicUser(user, settings, await documents(pool, ['permission_roles']), now()).capabilities.save_copy;
    const facts = row.sig === mediaSignature(item);
    const vc = facts ? String(row.video_codec || '').toLowerCase() : '';
    const ac = facts ? String(row.audio_codec || '').toLowerCase() : '';
    const hint = { mode: !facts ? 'both' : vc === 'h264' || vc.startsWith('avc') ? 'audio' :
      !ac || ac.startsWith('aac') || compatibleAudio(ac) ? 'video' : 'both', video_codec: vc || null, audio_codec: ac || null };
    const section = Object.values(settings.sections ?? {}).find(entry => entry?.id === item.section);
    if (typeof section?.path !== 'string' || !isAbsolute(section.path)) return unavailable(response);

    // Resolve the media and enumerate captions after releasing the database operation lock.
    return async () => {
      if (response.destroyed) return;
      const useCopy = !enabled(body.original) && enabled(settings.convert?.prefer_directplay ?? true) &&
        enabled(item.directplay_ready) && typeof item.directplay_path === 'string' && item.directplay_path &&
        await isFile(item.directplay_path);
      const path = useCopy ? item.directplay_path : item.path;
      if (typeof path !== 'string' || !await isFile(path)) {
        return response.status(404).json({ ok: false, error: 'File missing' });
      }
      if (!await libraryFile(path, section.path)) {
        return response.status(403).json({ ok: false, error: 'The media file is outside its configured library folder.' });
      }
      const requestedCaptionSource = String(item.caption_source ?? item.path ?? path);
      const captionSource = !external && video && requestedCaptionSource !== path
        ? await libraryCaptionSource(requestedCaptionSource, section.path) || path : path;

      const tracks = !external && video ? await subtitles(root, path, captionSource) : new Map();
      const inspected = !external && enabled(body.probe) ? await probe(root, path, settings) : null;
      const active = !external && transcode ? await activeConversion(root, id, user.id, response.locals.base) : null;
      if (response.destroyed) return;
      const token = randomBytes(24).toString('hex');
      const expires = now() + tokenLifetime(settings);
      const payload = { item_id: id, user_id: user.id, path, expires, media_kind: item.media_kind, area: 'library',
        caption_source: external ? path : captionSource };
      const conversion = await db.run(async pool => {
        const { convert_queue } = !external && video && !useCopy ? await documents(pool, ['convert_queue']) : {};
        response.locals.mutationStarted = true;
        await pool.execute('INSERT INTO vaultcast_playback_tokens (token_hash,expires_at,payload) VALUES (?,?,?)',
          [sha(token), expires, JSON.stringify(payload)]);
        if (randomInt(100) === 0) {
          await pool.execute('DELETE FROM vaultcast_playback_tokens WHERE expires_at<? LIMIT 500', [now()]).catch(() => {});
        }
        return conversionStatus(convert_queue, id);
      });

      const url = response.locals.base + 'netplay' + prefix + '/stream/play?t=' + token;
      if (external) return response.json({ ok: true, url: url + '&direct=1' });
      const saved = parse(row.progress);
      const position = Number(saved?.position) || 0;
      const length = Number(saved?.duration) || 0;
      const progress = position >= 5 && !(length > 0 && position > length - 10)
        ? { position, duration: length, updated_at: saved.updated_at ?? null } : null;
      const ext = useCopy ? extname(path).slice(1).toLowerCase() : String(item.ext ?? '');
      const duration = Number(item.duration) || 0;
      const subtitleUpload = user.role === 'admin' || enabled(settings.opensubtitles?.allow_users);
      return response.json({
        ok: true, token, url, media_kind: item.media_kind, ext, title: item.title,
        transcode_allowed: transcode, transcode_auto_blocked: conversion.blocked, transcode_mode: 'manual',
        playback_hint: !canSave && eligible && (remuxAllowed || transcode) ? hint : null, active_conversion: active,
        transcode_save_allowed: canSave, save_copy_default: canSave ? hint : null,
        prepared_copy: Boolean(useCopy), library_conversion: conversion.active,
        progress, resume_position: progress?.position ?? 0,
        emu_core: item.emu_core ?? null, bios_url: null, bios_required: false, bios_missing: false, bios_hint: null, rom_sidecars: [],
        mime: mimeFor(ext), audio_tracks: inspected?.audio_tracks ?? [], audio_count: inspected?.audio_count ?? 0, directplay_audio_select: inspected?.multi_audio ?? false,
        preferred_audio: body.audio == null || body.audio === '' ? null : Math.trunc(Number(body.audio) || 0),
        subtitles: [...tracks.values()].map(({ id, label, language }) => ({ id, label, language,
          url: response.locals.base + 'netplay' + prefix + '/stream/subtitles/' + token + '/' + id + '.vtt' })),
        subtitle_search: video && subtitleUpload && Boolean(String(settings.opensubtitles?.api_key ?? '').trim()),
        subtitle_upload: video && subtitleUpload,
        remux_url: video ? response.locals.base + 'netplay' + prefix + '/stream/remux?t=' + token : null, remux_allowed: remuxAllowed,
        duration: duration > 0 || !video ? (item.duration == null ? null : duration)
          : (row.sig === mediaSignature(item) && Number(row.duration) > 0 ? Number(row.duration) : null),
        video_codec: inspected?.video_codec ?? null, needs_convert_hint: convertExtensions.has(String(ext ?? '').toLowerCase()),
        audio_note: inspected?.multi_audio ? 'Multiple audio tracks available.' : null, file_missing: false, extra: false,
      });
    };
  }, true));
  return mountConversions(app, prefix, { root, db, handle, json });
};

module.exports.libraryFile = libraryFile;
module.exports.mimeFor = mimeFor;
module.exports.tokenLifetime = tokenLifetime;
