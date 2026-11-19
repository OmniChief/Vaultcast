'use strict';

const fs = require('fs');
const { open, mkdir, readFile, realpath, readdir, stat, statfs, rename, unlink, rmdir } = fs.promises;
const { join } = require('path');
const { spawn } = require('child_process');
const { tmpdir } = require('os');
const { Transform } = require('stream');
const { pipeline } = require('stream/promises');
const { setTimeout: delay } = require('timers/promises');
const { locksAvailable, lockFile } = require('./file-lock');
const { documents, enabled, parse, sha, now } = require('../NodeApp/account-data');
const { StalkerProvider, atomicJson, phpJson, configHash } = require('../NodeApp/stalker-provider');
const { cached } = require('../NodeApp/profile-policy');

const active = status => ['queued', 'downloading'].includes(status);
const validId = id => typeof id === 'string' && /^[a-f0-9]{24}$/.test(id);
let running = false;
const instances = new WeakMap();

async function ownedDirectory(path) {
  await mkdir(path, { recursive: true, mode: 0o700 });
  if (await realpath(path) !== path) throw new Error('Invalid offline storage directory.');
  return path;
}

async function jobLock(path) {
  const handle = await open(path, fs.constants.O_CREAT | fs.constants.O_RDWR | (fs.constants.O_NOFOLLOW ?? 0), 0o600);
  try {
    if (!(await handle.stat()).isFile()) throw new Error('Invalid offline lock.');
    await lockFile(handle, 0);
    return handle;
  } catch (error) { await handle.close(); return null; }
}

async function getJob(pool, id, locked = false) {
  const [rows] = await pool.execute('SELECT payload FROM vaultcast_documents WHERE name=?' + (locked ? ' FOR UPDATE' : ''), ['stalker-offline:' + id]);
  return rows.length ? parse(rows[0].payload) : null;
}

async function patchJob(db, directory, id, patch) {
  if (await stat(join(directory, id + '.delete')).catch(() => null)) return null;
  return db.run(async pool => {
    const connection = await pool.getConnection();
    let usable = true;
    try {
      await connection.beginTransaction();
      const current = await getJob(connection, id, true);
      if (!current || current.id !== id) { await connection.rollback(); return null; }
      if (current.status === 'cancelled' && patch.status !== 'cancelled') { await connection.rollback(); return current; }
      const updated = { ...current, ...patch, updated: new Date().toISOString() };
      delete updated.job_pid;
      if (['ready', 'downloading'].includes(patch.status)) delete updated.error;
      await connection.execute('UPDATE vaultcast_documents SET payload=?,revision=revision+1 WHERE name=?', [JSON.stringify(updated), 'stalker-offline:' + id]);
      await connection.commit();
      return updated;
    } catch (error) {
      try { await connection.rollback(); } catch (rollback) { usable = false; connection.destroy(); }
      throw error;
    } finally { if (usable) connection.release(); }
  });
}

async function fetchMedia(provider, url, target, limit, signal, fresh = false) {
  if (!fresh && (await stat(target).catch(() => null))?.isFile()) {
    return (await readFile(target + '.part.json', 'utf8').then(JSON.parse).catch(() => null))?.final_url ?? url;
  }
  const partial = target + '.part', metadataPath = partial + '.json';
  let last;
  for (let attempt = 0; attempt < 5; attempt++) {
    signal.throwIfAborted();
    try {
      let current = url, noRange = false;
      for (let hop = 0; hop < 7; hop++) {
        const previous = await readFile(metadataPath, 'utf8').then(JSON.parse).catch(() => ({}));
        const offset = fresh || noRange ? 0 : (await stat(partial).catch(() => null))?.size ?? 0;
        const response = await provider.response(current, { signal, attempt,
          range: offset ? 'bytes=' + offset + '-' : undefined, ifRange: offset ? previous.validator : undefined });
        try {
          const status = response.statusCode;
          let redirect = response.headers.location;
          if (!redirect && status >= 300 && status < 400) redirect = /^\s*\d+\s*;\s*url\s*=\s*["']?([^"']+)["']?\s*$/i.exec(response.headers.refresh ?? '')?.[1];
          if (status >= 300 && status < 400 && redirect) { current = new URL(redirect, current).href; continue; }
          if (!noRange && offset && [400,405,416,460].includes(status)) { noRange = true; continue; }
          if (![200,206].includes(status)) throw new Error('Download interrupted or refused (HTTP ' + status + '). Partial files are kept; use Retry.');
          if (response.headers['content-encoding'] && response.headers['content-encoding'] !== 'identity') throw new Error('Provider returned an unsupported encoded media response.');
          const range = /^bytes (\d+)-(\d+)\/(\d+)$/.exec(response.headers['content-range'] ?? '');
          const validator = response.headers.etag ?? response.headers['last-modified'] ?? '';
          if (status === 206 && (!range || Number(range[1]) !== offset || Number(range[2]) + 1 !== Number(range[3]) ||
            offset && previous.validator && validator && previous.validator !== validator)) {
            if (!noRange) { noRange = true; continue; }
            throw new Error('The provider changed the partial download. Partial files are kept; use Retry.');
          }
          const start = status === 206 ? offset : 0;
          const length = Number(response.headers['content-length'] ?? 0);
          const total = range ? Number(range[3]) : length ? start + length : 0;
          if (total > limit) throw Object.assign(new Error('This provider video exceeds the offline download size limit.'), { permanent: true });
          let received = 0, sample = start, sampleAt = Date.now(), metadataAt = 0;
          const progress = async () => {
            const time = Date.now(), bytes = start + received;
            await atomicJson(metadataPath, { final_url: current, validator: response.headers.etag ?? response.headers['last-modified'] ?? '',
              total_bytes: total, downloaded_bytes: bytes, http_status: status, progress_at: Math.floor(time / 1000),
              progress_sample_at: time / 1000, speed_bytes_per_second: Math.round(Math.max(0, bytes - sample) * 1000 / Math.max(1, time - sampleAt)) });
            sample = bytes; sampleAt = time;
            const space = await statfs(join(target, '..'));
            if (space.bavail * space.bsize < 100 * 1024 * 1024) throw Object.assign(new Error('Not enough free disk space to continue the download.'), { permanent: true });
          };
          await progress();
          const cap = new Transform({ transform(chunk, encoding, done) {
            received += chunk.length;
            if (start + received > limit) return done(Object.assign(new Error('This provider video exceeds the offline download size limit.'), { permanent: true }));
            if (Date.now() - metadataAt < 1000) return done(null, chunk);
            metadataAt = Date.now();
            progress().then(() => done(null, chunk), done);
          } });
          await pipeline(response, cap, fs.createWriteStream(partial, { flags: start ? 'a' : 'w', mode: 0o600 }), { signal });
          if (!received || length && received !== length || total && start + received !== total) throw new Error('Provider stopped before the download was complete. Partial files are kept; use Retry.');
          signal.throwIfAborted();
          await progress();
          await rename(partial, target);
          return current;
        } finally { response.destroy(); }
      }
      throw new Error('Too many media redirects.');
    } catch (error) {
      last = error;
      if (signal.aborted || error.permanent || ['ENOSPC','EACCES','EROFS'].includes(error.code)) throw error;
      if (attempt < 4) await delay(Math.min(30000, 1000 * 2 ** attempt), undefined, { signal });
    }
  }
  throw last;
}

async function playlist(provider, url, folder, signal, update, depth = 0) {
  if (depth > 4) throw new Error('Too many nested playlists.');
  const path = join(folder, 'manifest-' + depth);
  url = await fetchMedia(provider, url, path, 4 * 1024 * 1024, signal, true);
  const text = await readFile(path, 'utf8');
  if (!text.trimStart().startsWith('#EXTM3U') || text.includes('{$')) throw new Error('Unsupported provider playlist.');
  const lines = text.split(/\r?\n/).map(line => line.trim());
  let bandwidth = 0;
  const variants = [];
  for (const line of lines) {
    if (line.startsWith('#EXT-X-STREAM-INF:')) bandwidth = Number(/(?:^|,)BANDWIDTH=(\d+)/.exec(line.slice(18))?.[1] ?? 1);
    else if (bandwidth && line && !line.startsWith('#')) { variants.push({ bandwidth, url: new URL(line, url).href }); bandwidth = 0; }
  }
  if (variants.length) return playlist(provider, variants.sort((a,b) => b.bandwidth - a.bandwidth)[0].url, folder, signal, update, depth + 1);
  if (!text.includes('#EXT-X-ENDLIST')) throw new Error('This is a continuous stream, not downloadable VOD.');
  const output = [], segments = [], signatures = [];
  let duration = '', expected = 0;
  for (let line of lines) {
    if (!line) continue;
    if (line.startsWith('#EXTINF:')) { duration = line; expected += Number(/^#EXTINF:([0-9.]+)/.exec(line)?.[1] ?? 0); }
    if (/^#EXT-X-(?:BYTERANGE|GAP|PART|PRELOAD-HINT):/.test(line)) throw new Error('This provider uses an unsupported offline playlist layout.');
    if (line.startsWith('#EXT-X-KEY:') && !/METHOD=(?:AES-128|NONE)(?:,|$)/.test(line)) throw new Error('This provider uses unsupported protected video.');
    if (line.startsWith('#')) {
      if (line.includes('URI=')) {
        if (!/^#EXT-X-(?:KEY|MAP):/.test(line) || line.includes('BYTERANGE=')) throw new Error('Unsupported playlist resource.');
        const match = /URI="([^"]+)"/.exec(line);
        if (!match) throw new Error('Invalid playlist resource.');
        const resource = new URL(match[1], url).href, name = 'resource-' + sha(resource);
        await fetchMedia(provider, resource, join(folder, name), 64 * 1024 * 1024, signal);
        line = line.replace(match[0], 'URI="' + name + '"');
      }
      output.push(line); continue;
    }
    const resource = new URL(line, url);
    const params = Object.fromEntries([...resource.searchParams].filter(([key]) => !/^(?:token|expires|exp|signature|sig|auth|play_token|session)$/i.test(key)).sort(([a],[b]) => a < b ? -1 : a > b ? 1 : 0));
    signatures.push([resource.pathname, Object.keys(params).length ? params : [], duration]);
    output.push('segment-' + segments.length); segments.push(resource.href);
  }
  if (!segments.length) throw new Error('Provider returned an empty video playlist.');
  const signaturePath = join(folder, 'segments.json'), signature = phpJson(signatures);
  const previous = await readFile(signaturePath, 'utf8').catch(() => null);
  if (previous != null && previous !== signature) throw new Error('Provider changed this video while it was downloading. Existing partial files were preserved.');
  await writeFile(signaturePath, signature, { mode: 0o600 });
  await update({ is_hls: true, segments_total: segments.length, segments_done: 0 });
  for (let index = 0; index < segments.length; index++) {
    await fetchMedia(provider, segments[index], join(folder, 'segment-' + index), 512 * 1024 * 1024, signal);
    await update({ segments_done: index + 1 });
  }
  const local = join(folder, 'offline.m3u8');
  await writeFile(local, output.join('\n') + '\n', { mode: 0o600 });
  return { source: local, expected };
}

async function tool(program, args, signal, timeout, log) {
  const file = log ? await open(log, 'w', 0o600) : null;
  const controller = new AbortController();
  const combined = AbortSignal.any([signal, controller.signal]);
  const timer = setTimeout(() => controller.abort(new Error('Video preparation timed out. Downloaded media was preserved.')), timeout);
  try {
    return await new Promise((resolve, reject) => {
      const child = spawn(program, args, { windowsHide: true, stdio: ['ignore', file ? 'ignore' : 'pipe', file ? file.fd : 'ignore'], signal: combined });
      let output = '', failure;
      child.stdout?.on('data', chunk => { if (output.length + chunk.length <= 4 * 1024 * 1024) output += chunk; else child.kill('SIGKILL'); });
      child.once('error', error => { failure = error; });
      // Wait for close before releasing the job lock, including aborted children.
      const abort = () => {
        const kill = setTimeout(() => child.kill('SIGKILL'), 3000); kill.unref();
        child.once('close', () => clearTimeout(kill));
      };
      combined.addEventListener('abort', abort, { once: true });
      child.once('close', code => {
        combined.removeEventListener('abort', abort);
        if (failure || code !== 0 || combined.aborted) reject(failure ?? combined.reason ?? new Error('Video preparation failed. Downloaded media was preserved for retry.'));
        else resolve(output);
      });
    });
  } finally { clearTimeout(timer); if (file) await file.close(); }
}

async function purge(db, directory, id) {
  const folder = join(directory, id);
  const exists = await stat(folder).catch(() => null);
  if (exists) {
    if (!exists.isDirectory() || await realpath(folder) !== folder) throw new Error('Invalid offline storage directory.');
    for (const entry of await readdir(folder, { withFileTypes: true })) {
      if (entry.isFile() || entry.isSymbolicLink()) await unlink(join(folder, entry.name));
    }
    await rmdir(folder);
  }
  await db.run(pool => pool.execute('DELETE FROM vaultcast_documents WHERE name=?', ['stalker-offline:' + id]));
  await unlink(join(directory, id + '.delete')).catch(() => {});
}

async function run(db, root, directory, job) {
  const id = job.id, folder = join(directory, id);
  const controller = new AbortController();
  const signal = controller.signal;
  const update = patch => patchJob(db, directory, id, patch);
  let monitoring = false, pending = Promise.resolve();
  async function check() {
    if (await stat(join(folder, 'cancel')).catch(() => null) || await stat(join(directory, id + '.delete')).catch(() => null)) {
      throw new Error('Download cancelled. Partial media was preserved.');
    }
    const maintenance = await readFile(join(root, 'data/runtime/maintenance.json'), 'utf8').then(JSON.parse).catch(() => null);
    if (Number(maintenance?.since) > now() - 900) throw Object.assign(new Error('Download paused for the application update.'), { updating: true });
    const space = await statfs(folder);
    if (space.bavail * space.bsize < 100 * 1024 * 1024) throw new Error('Not enough free disk space to continue the download.');
    let bytes = 0;
    for (const entry of await readdir(folder, { withFileTypes: true })) {
      if (entry.isFile() && /^(?:source(?:\.part)?|segment-\d+(?:\.part)?)$/.test(entry.name)) {
        const info = await stat(join(folder, entry.name)).catch(error => { if (error.code === 'ENOENT') return null; throw error; });
        bytes += info?.size ?? 0;
      }
    }
    if (bytes > 20 * 1024 ** 3) throw new Error('This provider video exceeds the 20 GB offline download limit.');
  }
  const monitor = setInterval(() => {
    if (monitoring) return;
    monitoring = true;
    pending = check().then(() => update({})).catch(error => controller.abort(error)).finally(() => { monitoring = false; });
  }, 5000);
  try {
    await ownedDirectory(folder);
    await check();
    const docs = await db.run(pool => documents(pool, ['settings', 'live_iptv']));
    const settings = docs.settings ?? {};
    if (!enabled(settings.features?.live) || !enabled(settings.features?.downloads)) throw new Error('Live or downloads have been disabled.');
    const feed = docs.live_iptv?.feeds?.find(feed => feed.type === 'stalker' && feed.id === job.feed && enabled(feed.enabled));
    if (!feed) throw new Error('The saved provider connection is missing or disabled. Re-enable it in Live settings, then retry this download.');
    const accountKey = 'stalker-account-' + configHash(feed);
    const account = (await db.run(pool => documents(pool, [accountKey])))[accountKey];
    if (account?.expires && Number(account.expires) <= now()) throw new Error('This provider subscription has expired.');
    await update({ status: 'downloading', stage: 'Resolving provider stream', engine: 'node' });
    let cookieSource = join(tmpdir(), 'vaultcast_stalker', sha(phpJson(feed)) + '.cookies');
    if (/^[a-f0-9]{32}$/.test(job.playback_ticket ?? '') && job.playback_viewer) {
      const ticket = await cached(root, 'stalker_play_ticket_' + sha(job.playback_ticket));
      if (ticket?.viewer === job.playback_viewer && ticket.feed === feed.id && ticket.id === job.item_id && ticket.kind !== 'live') {
        const playback = await cached(root, 'stalker_playback_v3_' + sha(phpJson([job.playback_viewer, feed, job.item_id, job.playback_ticket])));
        if (typeof playback?.jar === 'string' && /^[a-zA-Z0-9]{8,40}$/.test(playback.jar)) {
          const path = join(tmpdir(), 'iptv_hls_cj', playback.jar + '.jar');
          if ((await stat(path).catch(() => null))?.isFile()) cookieSource = path;
        }
      }
    }
    // Copy into this job's private jar; never mutate the active player's cookies.
    if ((await stat(cookieSource).catch(() => null))?.isFile()) {
      const bytes = await readFile(cookieSource);
      if (bytes.length <= 1024 * 1024) await fs.promises.writeFile(join(folder, 'cookies'), bytes, { mode: 0o600 });
    }
    const provider = new StalkerProvider(root, feed, join(folder, 'cookies'), signal);
    await provider.loadCookies();
    let source = join(folder, 'source'), expected = 0;
    // Completed source bytes remain reusable even if the provider link has expired.
    let url;
    if (!(await stat(source).catch(() => null))?.isFile()) {
      url = await provider.resolve(job.item_id, job.item);
      signal.throwIfAborted();
      await update({ stage: 'Downloading from provider' });
      url = await fetchMedia(provider, url, source, 20 * 1024 ** 3, signal);
    }
    const handle = await open(source, 'r');
    const head = Buffer.alloc(16);
    try { await handle.read(head, 0, 16, 0); } finally { await handle.close(); }
    if (head.toString('utf8').trimStart().startsWith('#EXTM3U')) {
      if (!url) url = await provider.resolve(job.item_id, job.item);
      await update({ is_hls: true, stage: 'Downloading stream segments' });
      ({ source, expected } = await playlist(provider, url, folder, signal, update));
    }
    signal.throwIfAborted();
    const partial = join(folder, 'video.part.mkv'), destination = join(folder, 'video.mkv');
    await update({ stage: 'Preparing offline video' });
    const args = ['-nostdin', '-y', '-v', 'error', '-protocol_whitelist', 'file,crypto,data'];
    if (source.endsWith('.m3u8')) args.push('-allowed_extensions', 'ALL');
    args.push('-i', source, '-map', '0:v:0', '-map', '0:a?', '-c', 'copy', partial);
    await tool(settings.tools?.ffmpeg?.trim() || 'ffmpeg', args, signal, 7200000, join(folder, 'remux.error.log'));
    await update({ stage: 'Checking the completed video' });
    const info = JSON.parse(await tool(settings.tools?.ffprobe?.trim() || 'ffprobe',
      ['-v','error','-show_streams','-show_format','-of','json',partial], signal, 20000));
    if (!info.streams?.some(stream => stream.codec_type === 'video') || expected > 0 &&
      Math.abs(Number(info.format?.duration ?? 0) - expected) > Math.max(5, expected * 0.001)) throw new Error('The saved video is incomplete. Downloaded media was preserved for retry.');
    await check();
    signal.throwIfAborted();
    await rename(partial, destination);
    await update({ status: 'ready', stage: 'Complete', bytes: (await stat(destination)).size });
    await unlink(join(root, 'data/cache/ingest_count.json')).catch(() => {});
  } catch (error) {
    const reason = signal.aborted ? signal.reason : error;
    if (!await stat(join(directory, id + '.delete')).catch(() => null)) {
      const cancelled = Boolean(await stat(join(folder, 'cancel')).catch(() => null));
      // Transport error text can contain signed URLs. Store a fixed message except for our own errors.
      const safe = reason?.name === 'Error' && !reason.code && !/https?:\/\//i.test(reason.message ?? '');
      await update({ status: cancelled ? 'cancelled' : reason?.updating ? 'queued' : 'failed',
        stage: cancelled ? 'Cancelled' : reason?.updating ? 'Paused for application update' : 'Download stopped',
        error: cancelled ? 'Cancelled. Partial downloads were preserved.' : safe ? reason.message : 'The download worker failed. Partial files were preserved; use Retry.' });
    }
  } finally { clearInterval(monitor); await pending; }
}

function start(db, root) {
  if (instances.has(db)) return instances.get(db);
  let ticking = false;
  const version = readFile(join(root, 'VERSION'), 'utf8').then(value => value.trim()).catch(() => null);
  async function tick() {
    if (ticking || !locksAvailable() || !db.ready()) return;
    ticking = true;
    let own, bandwidth, directory, id;
    try {
      if ((await readFile(join(root, 'VERSION'), 'utf8')).trim() !== await version) return;
      const maintenance = await readFile(join(root, 'data/runtime/maintenance.json'), 'utf8').then(JSON.parse).catch(() => null);
      if (Number(maintenance?.since) > now() - 900) return;
      directory = await ownedDirectory(join(await realpath(join(root, 'data')), 'stalker-offline'));
      // The same bandwidth lock is used by legacy workers during an upgrade.
      bandwidth = await jobLock(join(directory, 'download.lock'));
      if (!bandwidth) return;
      for (const name of await readdir(directory)) {
        if (!/^[a-f0-9]{24}\.delete$/.test(name)) continue;
        const deletedId = name.slice(0,24), lock = await jobLock(join(directory, deletedId + '.lock'));
        if (lock) { try { await purge(db, directory, deletedId); } finally { await lock.close(); } }
      }
      const rows = await db.run(async pool => (await pool.execute("SELECT payload FROM vaultcast_documents WHERE name LIKE 'stalker-offline:%'"))[0]);
      const jobs = rows.map(row => parse(row.payload)).filter(job => validId(job?.id) && active(job.status))
        .sort((a,b) => String(a.created ?? '').localeCompare(String(b.created ?? '')));
      for (const candidate of jobs) {
        own = await jobLock(join(directory, candidate.id + '.lock'));
        if (own) { id = candidate.id; break; }
      }
      if (!own) return;
      const job = await db.run(pool => getJob(pool, id));
      if (!job || !active(job.status)) return;
      running = true;
      await run(db, root, directory, job);
    } catch (error) {
      // Retry database availability on the next tick; never lose queued work.
    } finally {
      if (own) {
        try { if (await stat(join(directory, id + '.delete')).catch(() => null)) await purge(db, directory, id); }
        catch (error) {} // A persistent tombstone will be retried on the next tick.
        await own.close();
      }
      if (bandwidth) await bandwidth.close();
      running = false; ticking = false;
    }
  }
  const worker = { wake: () => { void tick(); } };
  instances.set(db, worker);
  setInterval(tick, 3000).unref();
  return worker;
}

module.exports = { start, active: () => running, getJob, jobLock, ownedDirectory };
