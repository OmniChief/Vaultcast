'use strict';

const fs = require('fs').promises;
const { constants } = require('fs');
const { randomBytes } = require('crypto');
const { join, parse: parsePath, dirname, basename } = require('path');
const { availableParallelism } = require('os');
const { run } = require('../Workers/transcode-runner');
const { lockFile } = require('../Workers/file-lock');
const { q, transaction } = require('./queries');
const { documents, enabled, now, parse, publicUser, sha } = require('./account-data');
const { authorized, parentalStatus } = require('./profile-policy');
const { areaAllowed, areaDenied, playbackVisible } = require('./library');
const { libraryFile, probe } = require('./media-probe');
const { atomicJson } = require('./stalker-provider');
const { save: put } = require('./stalker-store');
const { directory, jobId, readJob, status } = require('./conversion-playback');

const busy = new Set();
const working = job => ['running', 'queued', 'saving'].includes(job.status);
const problem = (status, message) => Object.assign(new Error(message), { status });
const exists = path => fs.stat(path).then(() => true, error => { if (error.code === 'ENOENT') return false; throw error; });
const destFor = file => join(dirname(file), parsePath(file).name.replace(/(?:\.directplay)+$/i, '') + '.directplay.mp4');
const stamp = info => ({ size: info.size, mtime: Math.floor(info.mtimeMs / 1000) });
const same = (a, b) => a.size === b.size && a.mtime === b.mtime;
const normal = path => path.replace(/\\/g, '/');

function config(settings) {
  const s = settings.transcoding ?? {};
  const integer = (key, fallback, min, max) => Math.min(max, Math.max(min, Number.isSafeInteger(s[key]) ? s[key] : fallback));
  return { threads: integer('threads', 1, 1, availableParallelism()), max_sessions: integer('max_sessions', 1, 1, 4),
    crf: integer('crf', 20, 14, 28), preset: s.preset || 'veryfast', audio_bitrate: s.audio_bitrate || '192k',
    audio_channels: s.audio_channels ?? '2', max_height: integer('max_height', 0, 0, 2160),
    max_bitrate: integer('max_bitrate', 0, 0, 40000), segment_seconds: integer('segment_seconds', 4, 2, 6),
    startup_seconds: integer('startup_seconds', 90, 10, 120), idle_seconds: integer('idle_seconds', 120, 30, 600),
    cache_hours: integer('cache_hours', 6, 1, 72), cache_gb: integer('cache_gb', 10, 1, 100) };
}

function encodeArgs(job, dir) {
  const c = job.config, threads = String(c.threads);
  const args = ['-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-threads', threads, '-filter_threads', threads,
    '-filter_complex_threads', threads, '-protocol_whitelist', 'file,pipe'];
  if (job.offset) args.push('-ss', job.offset.toFixed(3));
  args.push('-i', job.src, '-map', '0:v:0', '-map', '0:a:' + job.audio + '?');
  if (job.save_mode === 'audio') args.push('-c:v', 'copy');
  else {
    args.push('-c:v', 'libx264', '-threads:v', threads, '-preset', c.preset, '-crf', String(c.crf), '-pix_fmt', 'yuv420p',
      '-vf', c.max_height ? 'scale=-2:trunc(min(ih\\,' + c.max_height + ')/2)*2' : 'scale=trunc(iw/2)*2:trunc(ih/2)*2',
      '-force_key_frames', 'expr:gte(t,n_forced*' + c.segment_seconds + ')');
    if (c.max_bitrate) args.push('-maxrate', c.max_bitrate + 'k', '-bufsize', (2 * c.max_bitrate) + 'k');
  }
  if (job.save_mode === 'video') args.push('-c:a', 'copy');
  else {
    args.push('-c:a', 'aac', '-b:a', c.audio_bitrate, '-threads:a', threads);
    if (c.audio_channels === '2') args.push('-ac', '2');
  }
  return args.concat(['-sn', '-dn', '-f', 'hls', '-hls_time', String(c.segment_seconds), '-hls_playlist_type', 'event',
    '-hls_flags', 'independent_segments+temp_file', '-hls_segment_filename', join(dir, 'segment%06d.ts'),
    '-progress', 'pipe:1', '-nostats', join(dir, 'index.m3u8')]);
}

async function adopt(root, db, job, dest, profile) {
  const file = await fs.open(join(root, 'data/convert_queue.lock'), 'a');
  try {
    await lockFile(file, 30);
    await db.run(pool => transaction(pool, async conn => {
      const rows = await q(conn, 'SELECT payload FROM vaultcast_items WHERE id=? FOR UPDATE', [job.item_id]);
      const item = rows.length && parse(rows[0].payload);
      if (!item || normal(item.path) !== normal(job.original)) throw new Error('The original library entry changed.');
      const select = async (name, fallback) => {
        const rows = await q(conn, 'SELECT payload FROM vaultcast_documents WHERE name=? FOR UPDATE', [name]);
        return rows.length ? parse(rows[0].payload) : fallback;
      };
      const path = normal(dest);
      const profiles = await select('directplay_profiles', {});
      await put(conn, 'directplay_profiles', { ...profiles, [path]: profile });
      item.directplay_path = path;
      item.directplay_ready = profile.ready;
      item.directplay_versions = [path, ...Object.values(item.directplay_versions ?? []).filter(p => p !== path)];
      await q(conn, 'UPDATE vaultcast_items SET payload=? WHERE id=?', [JSON.stringify(item), item.id]);
      const queue = Object.values(await select('convert_queue', []));
      const date = new Date().toISOString(), id = job.id.slice(0, 16);
      if (!queue.some(row => row.id === id)) {
        queue.push({ id, item_id: item.id, dest_item_id: item.id, title: item.title, src: item.path, dest: path,
          section: item.section, status: 'done', progress: 100, created_at: new Date(job.created_at * 1000).toISOString(),
          finished_at: date, message: 'Watch & save copy complete. Original retained.', container: 'mp4',
          origin: 'watch_save', save_mode: job.save_mode, source_stat: job.source_stat });
        await put(conn, 'convert_queue', queue);
      }
      const requests = await select('convert_requests', []);
      let changed = false;
      for (const request of Object.values(requests)) {
        if (request?.status !== 'pending' || request.item_id !== item.id) continue;
        request.message = 'Converted - resolve in convert queue (remove original or keep both)';
        request.updated_at = date;
        changed = true;
      }
      if (changed) await put(conn, 'convert_requests', requests);
      await q(conn, "UPDATE vaultcast_documents SET revision=revision+1 WHERE name='catalogue'");
      await put(conn, 'browse_epoch', now());
    }));
  } finally { await file.close(); }
}

async function publishCopy(part, dest, recovering) {
  try { await fs.link(part, dest); }
  catch (error) {
    if (error.code === 'EEXIST' && recovering) {
      const [a, b] = await Promise.all([fs.stat(part, { bigint: true }), fs.stat(dest, { bigint: true })]);
      if (a.ino && a.ino === b.ino && a.dev === b.dev) return;
    }
    if (!['ENOTSUP','EOPNOTSUPP','EPERM','ENOSYS'].includes(error.code)) throw error;
    await fs.copyFile(part, dest, constants.COPYFILE_EXCL);
  }
}

async function perform(root, db, settings, job, lock) {
  const dir = directory(root, job.id);
  const persist = () => { job.updated_at = now(); return atomicJson(join(dir, 'session.json'), job); };
  const ffmpeg = settings.tools?.ffmpeg || 'ffmpeg';
  const execute = args => run({ job, arguments: [ffmpeg, ...args] });
  let part;
  try {
    const dest = destFor(job.src);
    if (!same(stamp(await fs.stat(job.src)), job.source_stat)) throw new Error('The source file changed.');
    if (job.save) part = join(dirname(dest), '.' + basename(dest) + '.' + job.id + '.part');
    if (job.save && job.verified_copy) {
      if (!job.published) {
        await publishCopy(part, dest, true);
        job.published = true;
        await persist();
      }
      await adopt(root, db, job, dest, job.verified_copy);
      job.status = 'done';
      return;
    }
    const encoded = job.save && job.status === 'saving' && await exists(join(dir, 'index.m3u8'));
    job.status = encoded ? 'saving' : 'running';
    await persist();
    const source = await probe(root, job.src, settings);
    if (!source.duration || (source.audio_count && job.audio >= source.audio_count)) throw new Error('Could not read the selected media or audio track.');
    job.duration = source.duration;
    if (!encoded) await execute(encodeArgs(job, dir));
    if (!await exists(join(dir, 'index.m3u8'))) throw new Error('No playable fragments were produced.');
    if (job.save) {
      job.status = 'saving';
      if (encoded) await fs.unlink(part).catch(error => { if (error.code !== 'ENOENT') throw error; });
      job.output_part = part;
      await persist();
      const args = ['-hide_banner', '-loglevel', 'error', '-nostdin', '-n', '-protocol_whitelist', 'file,crypto,pipe',
        '-i', join(dir, 'index.m3u8'), '-map', '0:v:0', '-map', '0:a?', '-c', 'copy'];
      if (job.save_mode === 'audio' && ['hevc', 'h265'].includes(source.video_codec)) args.push('-tag:v', 'hvc1');
      await execute(args.concat(['-movflags', '+faststart', '-f', 'mp4', '-progress', 'pipe:1', '-nostats', part]));
      const copy = await probe(root, part, settings);
      if (!copy.duration || Math.abs(copy.duration - source.duration) > Math.max(10, source.duration * .03))
        throw new Error('The completed copy did not pass the duration check.');
      if (!same(stamp(await fs.stat(job.src)), job.source_stat)) throw new Error('The original changed during conversion.');
      const profile = { ...stamp(await fs.stat(part)), ready: copy.video_codec === 'h264' &&
        ['yuv420p', 'yuvj420p'].includes(copy.pixel_format) && copy.audio_tracks.every(track => track.codec === 'aac'),
        source: job.original, source_size: job.source_stat.size, source_mtime: job.source_stat.mtime };
      // Publish only a fully verified file. An existing destination always wins.
      job.verified_copy = profile;
      await persist();
      await publishCopy(part, dest, false);
      job.published = true;
      await persist();
      await adopt(root, db, job, dest, profile);
    }
    job.status = 'done';
  } catch (error) {
    job.status = await exists(join(dir, 'cancel')) ? 'cancelled' : 'failed';
    job.error = error.message;
  } finally {
    if (part) await fs.unlink(part).catch(() => {});
    delete job.output_part;
    try { await persist(); } finally { busy.delete(job.id); await lock.close(); }
  }
}

async function expire(root, job) {
  const dir = directory(root, job.id), age = now() - Number(job.updated_at);
  if (age < Math.max(300, Number(job.config?.cache_hours || 6) * 3600)) return;
  const access = await fs.stat(join(dir, 'access')).catch(() => null);
  if (access && Date.now() - access.mtimeMs < 300000) return;
  const lock = await fs.open(join(dir, 'worker.lock'), 'a');
  try {
    if (!await lockFile(lock, 0).then(() => true, () => false)) return;
    for (const name of await fs.readdir(dir)) {
      if (/^(?:session\.json(?:\.tmp)?|ffmpeg\.log|segment\d{6,}\.ts(?:\.tmp)?|index\.m3u8(?:\.tmp)?|access|progress|cancel)$/.test(name))
        await fs.unlink(join(dir, name)).catch(() => {});
    }
  } finally { await lock.close(); }
  await fs.unlink(join(dir, 'worker.lock')).catch(() => {});
  await fs.rmdir(dir).catch(() => {});
}

async function start(root, db, settings, item, src, owner, body) {
  const base = join(root, 'data/transcode');
  await fs.mkdir(base, { recursive: true, mode: 0o700 });
  const queue = await fs.open(join(base, 'queue.lock'), 'a');
  try {
    await lockFile(queue, 3).catch(() => { throw problem(409, 'Playback queue busy. Try again shortly.'); });
    const c = config(settings), save = body.save === true;
    const audio = Number(body.audio ?? 0), offset = save ? 0 : Math.min(604800, Math.max(0, Number(body.start ?? 0)));
    const mode = save ? body.save_mode ?? 'both' : 'both';
    if (!Number.isInteger(audio) || audio < 0 || audio > 99 || !Number.isFinite(offset) ||
        !['both','audio','video'].includes(mode)) throw problem(422, 'Invalid conversion settings.');
    if (body.replace) {
      const prev = await readJob(root, body.replace);
      if (!prev || prev.item_id !== item.id || prev.owner !== owner || prev.token !== body.replace_token || prev.save)
        throw problem(403, 'This playback worker cannot be replaced.');
      const dir = directory(root, prev.id);
      await fs.writeFile(join(dir, 'cancel'), '');
      const lock = await fs.open(join(dir, 'worker.lock'), 'a');
      try { await lockFile(lock, 8).catch(() => { throw problem(409, 'The previous worker is still stopping.'); }); }
      finally { await lock.close(); }
    }
    let count = 0;
    for (const id of (await fs.readdir(base)).filter(jobId)) {
      const job = await readJob(root, id);
      if (!job) continue;
      if (!working(job) || now() - job.updated_at >= 150) { await expire(root, job); continue; }
      if (job.owner === owner && job.item_id === item.id && job.request_token === body.t && job.save === save &&
          job.audio === audio && job.save_mode === mode && Math.abs(job.offset - offset) < .1 &&
          !await exists(join(directory(root, id), 'cancel'))) return status(root, job, db.base());
      count++;
    }
    if (count >= c.max_sessions) throw problem(409, 'All transcoding slots are in use.');
    const info = await fs.stat(src);
    if (!info.isFile()) throw problem(404, 'Source file missing.');
    if (save) {
      if (/\.directplay\.[^.]+$/i.test(src) || await exists(destFor(src))) throw problem(409, 'A prepared copy already exists.');
      await fs.access(dirname(src), constants.W_OK);
    }
    const id = randomBytes(16).toString('hex'), dir = directory(root, id);
    await fs.mkdir(dir, { mode: 0o700 });
    const job = { id, engine: 'node', item_id: item.id, title: item.title, section: item.section, src, original: item.path,
      token: body.t, owner, request_token: body.t, save, save_mode: mode, offset, audio, config: c,
      status: 'queued', created_at: now(), updated_at: now(), source_stat: stamp(info), error: null };
    const lock = await fs.open(join(dir, 'worker.lock'), 'a');
    try {
      await lockFile(lock, 0);
      await fs.writeFile(join(dir, 'access'), '');
      await atomicJson(join(dir, 'session.json'), job);
    } catch (error) { await lock.close(); throw error; }
    busy.add(id);
    void perform(root, db, settings, job, lock).catch(error => console.error('Could not persist conversion', id, error.code));
    return status(root, job, db.base());
  } finally { await queue.close(); }
}

module.exports = function mountConversions(app, prefix, { root, db, handle, json }) {
  app.post(prefix + '/items/:id([a-f0-9]{16})/transcode', json, handle(async (request, response, pool, _bcrypt, settings, current) => {
    if (!authorized(response, current)) return;
    const user = current.user, body = request.body ?? {}, id = request.params.id;
    if (settings.streaming?.directplay_only !== false || !enabled(settings.transcoding?.enabled))
      return response.status(403).json({ ok: false, error: 'Playback transcoding is disabled.' });
    if (typeof body.t !== 'string' || !/^[a-f0-9]{48}$/.test(body.t) || (body.save != null && typeof body.save !== 'boolean'))
      return response.status(422).json({ ok: false, error: 'Invalid playback request.' });
    const [row] = await q(pool, 'SELECT i.payload,t.payload AS token FROM vaultcast_items i JOIN vaultcast_playback_tokens t ' +
      'ON t.token_hash=? AND t.expires_at>? WHERE i.id=?', [sha(body.t), now(), id]);
    const item = row && parse(row.payload), token = row && parse(row.token);
    if (!item || item.media_kind !== 'video' || !['tv','anime','movies','disney'].includes(item.type) ||
        token.item_id !== id || token.user_id !== user.id || token.area !== 'library' || ![item.path, item.directplay_path].includes(token.path))
      return response.status(403).json({ ok: false, error: 'Select a local library movie or episode.' });
    const parental = await parentalStatus(root, pool, current, user, now());
    if (!areaAllowed(user, 'library', parental)) return areaDenied(response, 'library');
    if (!await playbackVisible(pool, item, parental)) return response.status(403).json({ ok: false, error: 'This title is unavailable on this profile.' });
    if (body.save && (!enabled(settings.transcoding.allow_save ?? true) ||
        !publicUser(user, settings, await documents(pool, ['permission_roles']), now()).capabilities.save_copy))
      return response.status(403).json({ ok: false, error: 'Watch & save copy is disabled for this account.' });
    const folder = Object.values(settings.sections ?? {}).find(section => section?.id === item.section)?.path;
    return async () => {
      try {
        const src = folder && await libraryFile(body.save ? item.path : token.path, folder);
        if (!src) throw problem(404, 'File outside the library.');
        response.json(await start(root, db, settings, item, src, user.id, body));
      } catch (error) {
        response.status(error.status ?? 503).json({ ok: false, error: error.status ? error.message : 'Could not start the playback worker.' });
      }
    };
  }, true));
  // A managed update waits for active encoders. After an unexpected restart,
  // only restart saved-copy jobs created by this engine and still authorized.
  let recovering = false;
  const timer = setInterval(async () => {
    if (recovering || !db.ready()) return;
    recovering = true;
    try {
      const base = join(root, 'data/transcode');
      const ids = await fs.readdir(base).catch(error => { if (error.code === 'ENOENT') return []; throw error; });
      let pending = false;
      for (const id of ids.filter(jobId)) {
        const dir = directory(root, id), job = await readJob(root, id);
        if (job?.engine !== 'node' || !job.save || !working(job) || busy.has(id)) continue;
        const queue = await fs.open(join(base, 'queue.lock'), 'a');
        try {
          if (!await lockFile(queue, 0).then(() => true, () => false)) { pending = true; continue; }
          const lock = await fs.open(join(dir, 'worker.lock'), 'a');
          let transferred = false;
          try {
            if (!await lockFile(lock, 0).then(() => true, () => false)) { pending = true; continue; }
            const current = await readJob(root, id);
            if (!current || !working(current)) continue;
            const { settings, allowed } = await db.run(async pool => {
              const docs = await documents(pool, ['settings','permission_roles']);
              const settings = docs.settings ?? {};
              const [row] = await q(pool, 'SELECT i.payload,u.payload AS owner FROM vaultcast_items i JOIN vaultcast_users u ON u.id=? WHERE i.id=?', [job.owner, job.item_id]);
              const user = row && parse(row.owner), item = row && parse(row.payload);
              const folder = Object.values(settings.sections ?? {}).find(s => s?.id === item?.section)?.path;
              const parental = user && await parentalStatus(root, pool, {}, user, now());
              const profile = user && publicUser(user, settings, docs, now());
              const allowed = settings.streaming?.directplay_only === false && enabled(settings.transcoding?.enabled) &&
                enabled(settings.transcoding?.allow_save ?? true) && profile?.access_state.active && profile.capabilities.save_copy &&
                item?.path === job.original && folder && areaAllowed(user, 'library', parental) &&
                await playbackVisible(pool, item, parental) && await libraryFile(item.path, folder).catch(() => null) === job.src;
              return { settings, allowed };
            });
            if (!allowed || await exists(join(dir, 'cancel'))) {
              current.status = 'cancelled';
              current.error = 'The saved conversion is no longer authorized or was stopped.';
              current.updated_at = now();
              await atomicJson(join(dir, 'session.json'), current);
              continue;
            }
            if (busy.size >= config(settings).max_sessions) { pending = true; continue; }
            busy.add(id);
            transferred = true;
            void perform(root, db, settings, current, lock).catch(error => console.error('Could not resume conversion', id, error.code));
          } finally { if (!transferred) await lock.close(); }
        } finally { await queue.close(); }
      }
      if (!pending) clearInterval(timer);
    } catch (error) { console.warn('Playback recovery will retry', error.code ?? error.name); }
    finally { recovering = false; }
  }, 10000);
  timer.unref();
  return () => busy.size > 0 || recovering;
};
