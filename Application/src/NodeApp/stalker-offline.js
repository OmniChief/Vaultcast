'use strict';

const { constants } = require('fs');
const { lstat, open, readdir, readFile, realpath, stat } = require('fs').promises;
const { join } = require('path');
const { pipeline } = require('stream/promises');
const { flock, lockFile } = require('../Workers/file-lock');
const { enabled, now, parse } = require('./account-data');
const { mediaRange } = require('./stream');

const active = status => ['queued', 'downloading'].includes(status);
const ready = status => ['ready', 'imported'].includes(status);
const validId = id => typeof id === 'string' && id.length === 24 && /^[a-f0-9]{24}$/.test(id);
const int = value => Number.isFinite(Number(value)) ? Math.trunc(Number(value)) : 0;

async function refreshStopped(pool, directory, row, time) {
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

module.exports = { listOffline, sendOffline, validId, ready };
