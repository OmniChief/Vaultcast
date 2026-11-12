'use strict';

const fs = require('fs').promises;
const { spawn } = require('child_process');
const { randomBytes } = require('crypto');
const { setPriority } = require('os');
const { isAbsolute, join } = require('path');
const { takeTicket } = require('./media-relay');
const { flock, lockFile } = require('./file-lock');

const pending = new Map();
let batch = new Map();

async function readyFrame(path) {
  const stat = await fs.stat(path).catch(() => null);
  return stat && stat.isFile() && stat.size > 1000;
}

async function tryLock(path) {
  const file = await fs.open(path, 'a');
  try {
    await lockFile(file, 0);
    return file;
  } catch (error) {
    await file.close();
    return null;
  }
}

async function runFrame(job, temporary) {
  await new Promise(resolve => {
    const child = spawn(job.ffmpeg, [
      '-nostdin', '-hide_banner', '-loglevel', 'error', '-ss', String(job.at),
      '-i', job.path, '-frames:v', '1', '-vf', 'scale=960:-2', '-q:v', '5', '-y', temporary,
    ], { stdio: 'ignore', timeout: 20000, killSignal: 'SIGKILL' });
    if (child.pid) {
      try { setPriority(child.pid, 10); } catch (error) { /* A fast child may already have exited. */ }
    }
    child.once('error', () => {});
    child.once('close', resolve);
  });
}

async function extract(job) {
  const out = join(job.directory, job.id + '.jpg');
  if (await readyFrame(out)) return;
  const source = await fs.stat(job.path).catch(() => null);
  if (!source?.isFile()) return;

  await fs.mkdir(job.directory, { recursive: true, mode: 0o775 });
  const itemLock = await tryLock(out + '.lock');
  if (!itemLock) return;
  const failed = out + '.fail';
  try {
    if (await readyFrame(out)) return;
    const lastFailure = await fs.stat(failed).catch(() => null);
    if (lastFailure && Date.now() - lastFailure.mtimeMs < 86400000) return;

    let slot = null;
    for (let index = 0; index < 2 && !slot; index++) {
      slot = await tryLock(join(job.directory, 'slot-' + index + '.lock'));
    }
    if (!slot) return;
    const temporary = out + '.' + randomBytes(6).toString('hex') + '.jpg';
    try {
      await runFrame(job, temporary);
      if (await readyFrame(temporary)) {
        await fs.rename(temporary, out);
        await fs.unlink(failed).catch(() => {});
      } else {
        await fs.writeFile(failed, '', { mode: 0o660 });
      }
    } finally {
      await fs.unlink(temporary).catch(() => {});
      await slot.close();
    }
  } finally {
    await itemLock.close();
  }
}

async function drain() {
  if (batch.size || !pending.size) return;
  batch = new Map(Array.from(pending).slice(0, 40));
  for (const key of batch.keys()) pending.delete(key);
  try {
    const deadline = Date.now() + 120000;
    for (const job of batch.values()) {
      if (Date.now() >= deadline) break;
      await extract(job).catch(() => {});
    }
  } finally {
    batch.clear();
    if (pending.size) void drain();
  }
}

module.exports = async function warmup(req, res) {
  if (!flock) return res.sendStatus(503);
  const ticket = await takeTicket(req.params.token, 'quiz-warmup').catch(() => null);
  if (!ticket || !Array.isArray(ticket.frames) || typeof ticket.directory !== 'string' ||
      !isAbsolute(ticket.directory) || typeof ticket.ffmpeg !== 'string' || !ticket.ffmpeg) return res.sendStatus(404);
  for (const frame of ticket.frames.slice(0, 40)) {
    if (!frame || typeof frame.id !== 'string' || !/^[A-Za-z0-9_-]{1,128}$/.test(frame.id) ||
        typeof frame.path !== 'string' || !isAbsolute(frame.path) || !Number.isFinite(frame.at) || frame.at < 0) continue;
    const key = join(ticket.directory, frame.id);
    if (!batch.has(key) && pending.size < 80) {
      pending.set(key, { ...frame, directory: ticket.directory, ffmpeg: ticket.ffmpeg });
    }
  }
  void drain();
  return res.end('queued');
};
module.exports.active = () => batch.size > 0;
module.exports.ready = Boolean(flock);
