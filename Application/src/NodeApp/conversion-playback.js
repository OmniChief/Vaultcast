'use strict';

const { open, readFile, readdir, stat, utimes } = require('fs').promises;
const { join } = require('path');
const { now } = require('./account-data');

const jobId = id => typeof id === 'string' && /^[a-f0-9]{32}$/.test(id);
const directory = (root, id) => join(root, 'data/transcode', id);
const url = (job, base) => base + 'netplay/application/stream/transcode?t=' + job.token + '&s=' + job.id + '&file=';

async function readJob(root, id) {
  if (!jobId(id)) return null;
  const job = await readFile(join(directory(root, id), 'session.json'), 'utf8').then(JSON.parse).catch(() => null);
  return job?.id === id && /^[a-f0-9]{48}$/.test(job.token) ? job : null;
}

async function status(root, job, installation = '/') {
  const body = await readFile(join(directory(root, job.id), 'index.m3u8'), 'utf8').catch(() => '');
  const base = url(job, installation);
  return { ok: true, id: job.id, token: job.token, status: job.status, offset: job.offset, save: job.save,
    ready: (body.match(/^segment\d{6,}\.ts\s*$/gm) ?? []).length >= 3 || (body.includes('#EXT-X-ENDLIST') && body.includes('#EXTINF:')),
    duration: job.duration ?? 0, error: job.error ?? null, url: base + 'index.m3u8', status_url: base + 'status',
    stop_url: base + 'stop', startup_seconds: job.config.startup_seconds };
}

async function activeConversion(root, item, owner, installation) {
  const ids = await readdir(join(root, 'data/transcode')).catch(error => { if (error.code === 'ENOENT') return []; throw error; });
  for (const id of ids.filter(jobId)) {
    const job = await readJob(root, id);
    if (job?.item_id === item && job.owner === owner && ['queued', 'running', 'saving'].includes(job.status) &&
        now() - Number(job.updated_at) < 150 && !await stat(join(directory(root, id), 'cancel')).catch(() => null)) return status(root, job, installation);
  }
  return null;
}

async function serveConversion(root, media, request, response, installation) {
  const { s, file, t } = request.query;
  const job = await readJob(root, s);
  if (!job || job.token !== t || job.owner !== media.owner || job.item_id !== media.itemId)
    return response.status(403).json({ ok: false, error: 'Unknown playback session.' });
  const dir = directory(root, s);
  if (file === 'stop') {
    if (request.method !== 'POST') return response.sendStatus(405);
    if (!job.save) await (await open(join(dir, 'cancel'), 'a')).close();
    return response.json({ ok: true, continues: job.save });
  }
  if (request.method === 'POST') return response.sendStatus(405);
  if (file !== 'index.m3u8' && file !== 'status' && !(typeof file === 'string' && /^segment\d{6,}\.ts$/.test(file))) return response.sendStatus(404);
  const time = new Date();
  await utimes(join(dir, 'access'), time, time).catch(async error => {
    if (error.code !== 'ENOENT') throw error;
    await (await open(join(dir, 'access'), 'a')).close();
  });
  if (file === 'status') return response.json(await status(root, job, installation));
  if (file === 'index.m3u8') {
    const body = await readFile(join(dir, file), 'utf8').catch(() => null);
    if (body == null) return response.sendStatus(['cancelled','failed'].includes(job.status) ? 502 : 404);
    return response.type('application/vnd.apple.mpegurl').send(body.replace(/^segment\d{6,}\.ts\r?$/gm, name => url(job, installation) + name.trim()));
  }
  return new Promise(resolve => response.type('video/mp2t').sendFile(join(dir, file), { cacheControl: false }, error => {
    if (error && !response.headersSent && !response.destroyed) response.sendStatus(error.statusCode ?? 404);
    resolve();
  }));
}

module.exports = { activeConversion, serveConversion, readJob, status, jobId, directory };
