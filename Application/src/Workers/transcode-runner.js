'use strict';

const fs = require('fs').promises;
const { join, dirname } = require('path');
const { spawn } = require('child_process');
const { createInterface } = require('readline');
const { setPriority } = require('os');
const { takeTicket } = require('./media-relay');
const root = join(__dirname, '../../data/transcode');
let active = 0;

async function checkDisk(directory, job) {
  const minimum = 256 * 1024 * 1024;
  const available = async path => {
    const stat = await fs.statfs(path);
    return stat.bavail * stat.bsize;
  };
  if (await available(directory) < minimum) throw new Error('Temporary storage limit reached. Original kept.');
  if (job.output_part && await available(dirname(job.output_part)) < minimum) {
    throw new Error('The library disk is nearly full. Original kept.');
  }
  let bytes = 0;
  for (const name of await fs.readdir(directory)) {
    const stat = await fs.stat(join(directory, name)).catch(() => null);
    if (stat && stat.isFile()) bytes += stat.size;
  }
  if (job.output_part) {
    const part = await fs.stat(job.output_part).catch(() => null);
    if (part) bytes += part.size;
  }
  if (bytes > job.config.cache_gb * 1073741824) throw new Error('Temporary storage limit reached. Original kept.');
}

async function run(ticket, response) {
  const job = ticket.job;
  if (!job || !/^[a-f0-9]{32}$/.test(job.id)) throw new Error('Invalid playback session.');
  const directory = join(root, job.id);
  const session = join(directory, 'session.json');
  const cancel = join(directory, 'cancel');
  const [program, ...args] = ticket.arguments;
  const progressArg = args.indexOf('-progress');
  if (progressArg < 0) throw new Error('Invalid FFmpeg arguments.');
  args[progressArg + 1] = 'pipe:1';
  if (await fs.stat(cancel).catch(() => null)) throw new Error('Playback conversion stopped.');
  const log = await fs.open(join(directory, 'ffmpeg.log'), 'a');
  try {
    await checkDisk(directory, job);
    if (response.destroyed) throw new Error('Playback worker disconnected.');
    const child = spawn(program, args, { stdio: ['ignore', 'pipe', log.fd] });
    if (child.pid) {
      try { setPriority(child.pid, 10); } catch (error) {}
    }
    let failure;
    let lastProgress = Date.now();
    let lastDiskCheck = lastProgress;
    let updating = Promise.resolve();
    let closed = false;
    const stop = message => {
      failure = failure || message;
      if (!closed) child.kill('SIGKILL');
    };
    const disconnected = () => stop('Playback worker disconnected.');
    response.once('close', disconnected);
    const lines = createInterface({ input: child.stdout });
    lines.on('line', line => {
      const result = /^out_time_ms=(\d+)$/.exec(line);
      if (result) {
        job.prepared_seconds = Math.round(Number(result[1]) / 100000) / 10;
        lastProgress = Date.now();
      }
    });
    let updatingNow = false;
    const monitor = setInterval(() => {
      if (updatingNow || closed) return;
      updatingNow = true;
      updating = (async () => {
        const now = Date.now();
        if (await fs.stat(cancel).catch(() => null)) throw new Error('Playback conversion stopped.');
        const access = job.save ? null : await fs.stat(join(directory, 'access')).catch(() => null);
        if (!job.save && (!access || now - access.mtimeMs > job.config.idle_seconds * 1000)) {
          throw new Error('Playback session became idle.');
        }
        if (now - lastProgress > 180000) throw new Error('FFmpeg stopped making progress. Original kept.');
        if (now - lastDiskCheck > 5000) {
          await checkDisk(directory, job);
          lastDiskCheck = now;
        }
        if (!closed) {
          job.updated_at = Math.floor(now / 1000);
          const temporary = session + '.tmp';
          await fs.writeFile(temporary, JSON.stringify(job));
          await fs.rename(temporary, session);
        }
      })().catch(error => stop(error.message)).finally(() => { updatingNow = false; });
    }, 1000);
    const heartbeat = setInterval(() => response.write(' '), 15000);
    try {
      const exitCode = await new Promise(resolve => {
        child.once('error', () => resolve(-1));
        child.once('close', resolve);
      });
      closed = true;
      await updating;
      if (failure) throw new Error(failure);
      if (exitCode !== 0) throw new Error('FFmpeg could not prepare this video. Original kept; see the playback worker log.');
    } finally {
      clearInterval(monitor);
      clearInterval(heartbeat);
      response.removeListener('close', disconnected);
      lines.close();
    }
  } finally {
    await log.close();
  }
}

module.exports = async function transcode(request, response) {
  if (request.method !== 'GET') return response.sendStatus(405);
  const ticket = await takeTicket(request.params.token, 'transcode').catch(() => null);
  if (!ticket) return response.sendStatus(404);
  if (response.destroyed) return;
  active++;
  response.set({ 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
  response.flushHeaders();
  try {
    await run(ticket, response);
    if (!response.destroyed) response.end(JSON.stringify({ ok: true }));
  } catch (error) {
    if (!response.destroyed) response.end(JSON.stringify({ ok: false, error: error.message }));
  } finally {
    active--;
  }
};

module.exports.active = () => active > 0;
