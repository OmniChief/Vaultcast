'use strict';

const { constants } = require('fs');
const { lstat, mkdir, open, readdir, readFile, realpath, rmdir, stat, unlink } = require('fs').promises;
const { join } = require('path');
const { pipeline } = require('stream/promises');
const { flock, lockFile } = require('../Workers/file-lock');
const { enabled, now, parse } = require('./account-data');
const { mediaRange } = require('./stream');
const { randomBytes } = require('crypto');
const { itemEntry } = require('./stalker-provider');
const { cached } = require('./profile-policy');
const { sha } = require('./account-data');
const { getJob, jobLock, ownedDirectory } = require('../Workers/stalker-download');

const active = status => ['queued', 'downloading'].includes(status);
const ready = status => ['ready', 'imported'].includes(status);
const validId = id => typeof id === 'string' && id.length === 24 && /^[a-f0-9]{24}$/.test(id);
const int = value => Number.isFinite(Number(value)) ? Math.trunc(Number(value)) : 0;

async function refreshStopped(pool, directory, row, time) {
  // Node owns the persistent queue and resumes it when the service restarts.
  if (row.status === 'queued' || row.engine === 'node') return row;
  const updated = Date.parse(row.updated ?? row.created ?? '');
  if (!Number.isFinite(updated) || time - updated / 1000 <= 90 || !flock) return row;
  const lock = await open(join(directory, row.id + '.lock'),
    constants.O_CREAT | constants.O_RDWR | (constants.O_NOFOLLOW ?? 0), 0o600).catch(() => null);
  if (!lock) return row;
  try {
    if (!await lockFile(lock, 0).then(() => true, () => false)) return row;
    const key = 'stalker-offline:' + row.id;
    const [rows] = await pool.execute('SELECT payload,revision FROM vaultcast_documents WHERE name=?', [key]);
    if (!rows.length) return null;
    const current = parse(rows[0].payload);
    const last = Date.parse(current?.updated ?? current?.created ?? '');
    if (!active(current?.status) || !Number.isFinite(last) || time - last / 1000 <= 90) return current;
    if (await lstat(join(directory, row.id + '.delete')).catch(() => null)) return current;
    const failed = { ...current, status: 'failed', error: 'The download worker stopped. Retry to resume saved data.' };
    const [result] = await pool.execute(
      'UPDATE vaultcast_documents SET payload=?,revision=revision+1 WHERE name=? AND revision=?',
      [JSON.stringify(failed), key, rows[0].revision]);
    return result.affectedRows ? failed : current;
  } finally {
    await lock.close();
  }
}

async function progress(directory, row, time) {
  const folder = join(directory, row.id);
  if (await realpath(folder).catch(() => null) !== folder) return row;
  let size = 0;
  for (const entry of await readdir(folder, { withFileTypes: true }).catch(() => [])) {
    if (!entry.isFile() || !/^(?:source(?:\.part)?|segment-\d+(?:\.part)?|video(?:\.part)?\.mkv)$/.test(entry.name)) continue;
    size += (await stat(join(folder, entry.name)).catch(() => null))?.size ?? 0;
  }
  const partial = join(folder, 'source.part.json');
  const path = (await lstat(partial).catch(() => null))?.isFile() ? partial : join(folder, 'source.json');
  const info = await lstat(path).catch(() => null);
  const metadata = info?.isFile() && info.size <= 65536 ?
    await readFile(path, 'utf8').then(JSON.parse).catch(() => null) : null;
  const bytes = Math.max(int(row.bytes), size, int(metadata?.downloaded_bytes));
  const totalBytes = enabled(row.is_hls) && int(row.segments_total) > 0 ? 0 : int(metadata?.total_bytes);
  const ratio = totalBytes > 0 ? bytes / totalBytes : enabled(row.is_hls) && int(row.segments_total) > 0
    ? int(row.segments_done) / int(row.segments_total) : null;
  return { ...row, bytes, totalBytes, percent: ratio == null ? null : Math.floor(Math.min(1, ratio) * 100),
    speedBytesPerSecond: time - int(metadata?.progress_at) <= 5 ? int(metadata?.speed_bytes_per_second) : 0,
    providerHttpStatus: int(metadata?.http_status) };
}

function publicJob(job, time) {
  const fields = ['id','name','status','bytes','error','created','updated','destination','stage','connection','delete_requested'];
  const safe = Object.fromEntries(fields.filter(field => Object.hasOwn(job, field)).map(field => [field, job[field]]));
  const item = job.item ?? {};
  const rawSeason = item.season;
  const season = (typeof rawSeason === 'number' || typeof rawSeason === 'string' && rawSeason.trim() !== '') &&
    Number.isFinite(Number(rawSeason)) ? int(rawSeason) : null;
  const created = Date.parse(job.created ?? '');
  return { ...safe, percent: ready(job.status) ? 100 : job.percent ?? null, totalBytes: int(job.totalBytes),
    speedBytesPerSecond: int(job.speedBytesPerSecond), providerHttpStatus: int(job.providerHttpStatus),
    elapsedSeconds: job.status === 'downloading' && Number.isFinite(created) ? Math.max(0, time - Math.floor(created / 1000)) : 0,
    showName: String(item.showName ?? item.seriesName ?? ''), season,
    seasonName: String(item.seasonName || (season === null ? '' : season === 0 ? 'Specials' : 'Season ' + season)),
    episode: String(item.episode ?? item.episode_number ?? ''), episodeTitle: String(item.episodeTitle ?? item.episode_title ?? '') };
}

async function listOffline(pool, root) {
  const directory = await realpath(join(root, 'data/stalker-offline')).catch(() => null);
  const [rows] = await pool.execute("SELECT payload FROM vaultcast_documents WHERE name LIKE 'stalker-offline:%'");
  const jobs = [], time = now();
  for (const { payload } of rows) {
    let job = parse(payload);
    if (!job || !validId(job.id)) continue;
    if (directory && active(job.status)) {
      job = await refreshStopped(pool, directory, job, time);
      if (job && active(job.status)) job = await progress(directory, job, time);
    }
    if (job) jobs.push(publicJob(job, time));
  }
  jobs.sort((a, b) => String(a.created ?? '') < String(b.created ?? '') ? 1 : String(a.created ?? '') > String(b.created ?? '') ? -1 : 0);
  return jobs;
}

async function sendOffline(request, response, root, job) {
  const notReady = () => response.status(502).json({ ok: false,
    error: 'The video is not ready to download. Open Downloads to see its status.' });
  let file;
  try {
    const directory = await realpath(join(root, 'data/stalker-offline'));
    const folder = join(directory, job.id);
    if (await realpath(folder) !== folder) return notReady();
    file = await open(join(folder, 'video.mkv'), constants.O_RDONLY | (constants.O_NONBLOCK ?? 0) | (constants.O_NOFOLLOW ?? 0));
    const info = await file.stat();
    if (!info.isFile() || !Number.isSafeInteger(info.size)) return notReady();
    const size = info.size;
    const name = String(job.name ?? 'Video').replace(/[\x00-\x1f\x7f/\\\"<>:|?*]/g, '_') + '.mkv';
    const encoded = encodeURIComponent(Buffer.from(name).toString('utf8')).replace(/['()*!]/g, ch => '%' + ch.charCodeAt(0).toString(16).toUpperCase());
    response.set({ 'Cache-Control': 'private, no-store', 'Content-Type': 'video/x-matroska',
      'Content-Disposition': "attachment; filename=\"video.mkv\"; filename*=UTF-8''" + encoded,
      'Accept-Ranges': 'bytes', 'X-Accel-Buffering': 'no' });
    let range;
    if (request.headers.range) {
      range = mediaRange(request.headers.range, size);
      if (!range) return response.status(416).set('Content-Range', 'bytes */' + size).end();
      response.status(206).set('Content-Range', 'bytes ' + range.start + '-' + range.end + '/' + size);
    }
    response.set('Content-Length', String(range ? range.end - range.start + 1 : size));
    if (request.method === 'HEAD' || !size) return response.end();
    if (!response.destroyed) await pipeline(file.createReadStream({ ...range, autoClose: false }), response);
  } catch (error) {
    if (response.destroyed) return;
    if (response.headersSent) response.destroy();
    else return notReady();
  } finally {
    if (file) await file.close().catch(() => {});
  }
}


async function lockedFile(path) {
  return open(path, constants.O_CREAT | constants.O_RDWR | (constants.O_NOFOLLOW ?? 0) | (constants.O_NONBLOCK ?? 0), 0o600);
}

async function marker(path) {
  const file = await lockedFile(path);
  try {
    if (!(await file.stat()).isFile()) throw new Error('Invalid download marker');
    await file.writeFile('1');
  } finally {
    await file.close();
  }
}

async function removeIfPresent(path) {
  try { await unlink(path); }
  catch (error) { if (error.code !== 'ENOENT') throw error; }
}

async function controlOffline(pool, root, id, action) {
  const directory = join(await realpath(join(root, 'data')), 'stalker-offline');
  const key = 'stalker-offline:' + id;
  const [rows] = await pool.execute('SELECT payload FROM vaultcast_documents WHERE name=?', [key]);
  if (!rows.length) return { error: 'Offline download not found.' };
  await mkdir(directory, { recursive: true, mode: 0o700 });
  if (await realpath(directory) !== directory) throw new Error('Invalid offline storage');
  const folder = join(directory, id);
  const deleted = join(directory, id + '.delete');

  let lock;
  if (action === 'stalker_delete') lock = await lockedFile(join(directory, id + '.lock'));
  let connection, usable = true;
  try {
    const locked = lock && (await lock.stat()).isFile() && await lockFile(lock, 0).then(() => true, () => false);
    connection = await pool.getConnection();
    await connection.beginTransaction();
    const [records] = await connection.execute('SELECT payload FROM vaultcast_documents WHERE name=? FOR UPDATE', [key]);
    const job = records.length ? parse(records[0].payload) : null;
    if (job?.id !== id) {
      await connection.rollback();
      return { error: 'Offline download not found.' };
    }
    const stop = async error => {
      await connection.rollback();
      return { error };
    };
    if (action === 'stalker_cancel') {
      if (ready(job.status)) return await stop('This download is already complete.');
      await mkdir(folder, { recursive: true, mode: 0o700 });
      if (await realpath(folder) !== folder) throw new Error('Invalid offline download folder');
      await marker(join(folder, 'cancel'));
      const cancelled = { ...job, status: 'cancelled', stage: 'Cancellation requested', updated: new Date().toISOString() };
      delete cancelled.job_pid;
      await connection.execute('UPDATE vaultcast_documents SET payload=?,revision=revision+1 WHERE name=?',
        [JSON.stringify(cancelled), key]);
    } else {
      if (active(job.status)) return await stop('Cancel the active download first, then delete it after it stops.');
      if (!locked && job.status !== 'cancelled') {
        return await stop('The download is still stopping. Wait a few seconds, then delete it again.');
      }
      await marker(deleted);
      if (locked) {
        const info = await lstat(folder).catch(error => {
          if (error.code === 'ENOENT') return null;
          throw error;
        });
        if (info) {
          if (!info.isDirectory() || await realpath(folder) !== folder) throw new Error('Invalid offline download folder');
          for (const entry of await readdir(folder, { withFileTypes: true })) {
            if (entry.isFile() || entry.isSymbolicLink()) await removeIfPresent(join(folder, entry.name));
          }
          await rmdir(folder);
        }
      }
      await connection.execute('DELETE FROM vaultcast_documents WHERE name=?', [key]);
    }
    await connection.commit();
    if (locked) await removeIfPresent(deleted).catch(() => {});
    return { ok: true };
  } catch (error) {
    if (connection) {
      try { await connection.rollback(); }
      catch (rollbackError) { usable = false; connection.destroy(); }
    }
    throw error;
  } finally {
    if (connection && usable) connection.release();
    if (lock) await lock.close();
  }
}

async function retryOffline(pool, root, id) {
  const directory = await ownedDirectory(join(await realpath(join(root, 'data')), 'stalker-offline'));
  const queue = await jobLock(join(directory, 'queue.lock'));
  if (!queue) return { error: 'The download queue is busy. Try again shortly.' };
  try { return await queueJob(pool, root, directory, id); }
  finally { await queue.close(); }
}

async function queueJob(pool, root, directory, id, replacement) {
  const lock = await jobLock(join(directory, id + '.lock'));
  if (!lock) return { error: 'This download is still running.' };
  let connection, usable = true;
  try {
    if (await lstat(join(directory, id + '.delete')).catch(() => null)) return { error: 'This download is being deleted.' };
    connection = await pool.getConnection();
    await connection.beginTransaction();
    const job = await getJob(connection, id, true);
    if (!job || job.id !== id) { await connection.rollback(); return { error: 'Offline download not found.' }; }
    if (ready(job.status)) { await connection.rollback(); return { error: 'This download is already complete.' }; }
    const [rows] = await connection.execute("SELECT payload FROM vaultcast_documents WHERE name LIKE 'stalker-offline:%'");
    if (!active(job.status) && rows.filter(row => active(parse(row.payload)?.status)).length >= 20) {
      await connection.rollback(); return { error: 'The download queue is full. Wait for a download to finish.' };
    }
    const folder = await ownedDirectory(join(directory, id));
    await removeIfPresent(join(folder, 'cancel'));
    const queued = { ...job, ...replacement, status: 'queued', engine: 'node', stage: 'Queued for download', updated: new Date().toISOString() };
    delete queued.error; delete queued.job_pid;
    await connection.execute('UPDATE vaultcast_documents SET payload=?,revision=revision+1 WHERE name=?', [JSON.stringify(queued), 'stalker-offline:' + id]);
    await connection.commit();
    return { ok: true, id, status: 'queued' };
  } catch (error) {
    if (connection) {
      try { await connection.rollback(); } catch (rollback) { usable = false; connection.destroy(); }
    }
    throw error;
  } finally { if (connection && usable) connection.release(); await lock.close(); }
}

async function enqueueOffline(pool, root, feed, body, viewer) {
  if (typeof body.id !== 'string' || !body.id || body.id.length > 512 || /[\x00-\x1f]/.test(body.id)) return { error: 'Invalid provider title.' };
  const item = await itemEntry(pool, root, feed, body.id);
  if (!item?.cmd) return { error: 'Refresh the provider catalogue and select the title again.' };
  const clean = (value, limit) => Array.from(String(value ?? '').replace(/<[^>]*>/g, '').replace(/[\x00-\x1f\x7f]/g, ' ').trim()).slice(0, limit).join('');
  if (!item.showName && typeof body.showName === 'string') item.showName = clean(body.showName, 200);
  let name = clean(item.name || item.metadata?.name || body.name || 'Provider video', 250);
  if (item.showName && item.season != null && item.episode != null && Number.isFinite(Number(item.season)) && Number.isFinite(Number(item.episode))) {
    name = clean(item.showName + ' S' + String(Math.trunc(Number(item.season))).padStart(2, '0') + 'E' + String(Math.trunc(Number(item.episode))).padStart(2, '0'), 250);
  }
  const replacement = { item, name, connection: feed.name, destination: body.destination === 'browser' ? 'browser' : 'library' };
  if (typeof body.playback_ticket === 'string' && /^[a-f0-9]{32}$/.test(body.playback_ticket)) {
    const ticket = await cached(root, 'stalker_play_ticket_' + sha(body.playback_ticket));
    if (ticket?.viewer === String(viewer) && ticket.feed === feed.id && ticket.id === body.id && ticket.kind !== 'live') {
      replacement.playback_ticket = body.playback_ticket;
      replacement.playback_viewer = String(viewer);
    }
  }
  const directory = await ownedDirectory(join(await realpath(join(root, 'data')), 'stalker-offline'));
  const queue = await jobLock(join(directory, 'queue.lock'));
  if (!queue) return { error: 'The download queue is busy. Try again shortly.' };
  try {
    const [rows] = await pool.execute("SELECT payload FROM vaultcast_documents WHERE name LIKE 'stalker-offline:%'");
    const jobs = rows.map(row => parse(row.payload));
    const existing = jobs.find(job => validId(job?.id) && job.feed === feed.id && job.item_id === body.id && job.status !== 'imported');
    if (existing) {
      if (existing.destination === 'library') replacement.destination = 'library';
      if (['downloading','ready'].includes(existing.status)) {
        // Promotion to a library import is safe while the transfer is in progress.
        if (replacement.destination === 'library' && existing.destination !== 'library') {
          await pool.execute("UPDATE vaultcast_documents SET payload=JSON_SET(payload,'$.destination','library'),revision=revision+1 WHERE name=?",
            ['stalker-offline:' + existing.id]);
        }
        return { ok: true, id: existing.id, status: existing.status };
      }
      const result = await queueJob(pool, root, directory, existing.id, replacement);
      if (existing.status === 'queued' && result.error === 'This download is still running.') return { ok: true, id: existing.id, status: 'queued' };
      return result;
    }
    if (jobs.filter(job => active(job?.status)).length >= 20) return { error: 'The download queue is full. Wait for a download to finish.' };
    const id = randomBytes(12).toString('hex');
    await ownedDirectory(join(directory, id));
    const job = { ...replacement, id, feed: feed.id, item_id: body.id, owner: String(viewer), engine: 'node',
      status: 'queued', stage: 'Queued for download', bytes: 0, created: new Date().toISOString(), updated: new Date().toISOString() };
    await pool.execute('INSERT INTO vaultcast_documents(name,payload,revision) VALUES (?,?,1)', ['stalker-offline:' + id, JSON.stringify(job)]);
    return { ok: true, id, status: 'queued' };
  } finally { await queue.close(); }
}

module.exports = { listOffline, sendOffline, validId, ready, controlOffline, enqueueOffline, retryOffline };
