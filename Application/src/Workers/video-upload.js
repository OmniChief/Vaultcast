'use strict';

const fs = require('fs');
const { join } = require('path');
const { flock, lockFile } = require('./file-lock');
const { takeTicket } = require('./media-relay');
const root = join(__dirname, '../../data/ai-videos');
const chunkLimit = 2 * 1024 * 1024;
const uploadLimit = 64 * 1024 * 1024;

async function writeChunk(ticket, offset, bytes) {
  const base = join(root, ticket.id);
  const lock = await fs.promises.open(base + '.lock', 'a');
  try {
    await lockFile(lock);
    const jobPath = base + '.json';
    const job = JSON.parse(await fs.promises.readFile(jobPath, 'utf8'));
    if (job.status !== 'running' || job.worker_id !== ticket.worker || job.worker_slot !== ticket.slot
      || job.lease !== ticket.lease) throw new Error('Video lease expired');
    const upload = await fs.promises.open(base + '.upload', 'a+', 0o600);
    try {
      const { size } = await upload.stat();
      if (size !== offset) return size;
      if (size + bytes.length > uploadLimit) throw new Error('Video upload exceeds the allowed size');
      try {
        await upload.writeFile(bytes);
      } catch (error) {
        await upload.truncate(size);
        throw error;
      }
      job.updated_at = Math.floor(Date.now() / 1000);
      await fs.promises.writeFile(jobPath + '.tmp', JSON.stringify(job));
      await fs.promises.rename(jobPath + '.tmp', jobPath);
      return size + bytes.length;
    } finally {
      await upload.close();
    }
  } finally {
    await lock.close();
  }
}

module.exports = function startVideoUploads(io) {
  if (!flock) return null;
  const channel = io.of('/worker-video-upload');
  let processing = 0;
  channel.use(async (socket, next) => {
    try {
      const token = socket.handshake.auth?.ticket;
      const ticket = typeof token === 'string' ? await takeTicket(token, 'video-upload') : null;
      if (!ticket || !/^[a-f0-9]{16}$/.test(ticket.id) || channel.sockets.size >= 8) {
        return next(new Error('Video upload authorization expired'));
      }
      socket.data.ticket = ticket;
      next();
    } catch (error) {
      next(new Error('Video upload authorization expired'));
    }
  });
  channel.on('connection', socket => {
    let busy = false;
    const idle = setTimeout(() => socket.disconnect(true), 120000);
    socket.once('disconnect', () => clearTimeout(idle));
    socket.on('chunk', async (offset, bytes, reply) => {
      if (typeof reply !== 'function') return;
      if (busy || processing >= 2 || !Number.isSafeInteger(offset) || offset < 0
        || !Buffer.isBuffer(bytes) || !bytes.length || bytes.length > chunkLimit) {
        return reply({ ok: false });
      }
      idle.refresh();
      busy = true;
      processing++;
      try {
        const confirmed = await writeChunk(socket.data.ticket, offset, bytes);
        reply({ ok: true, offset: confirmed });
      } catch (error) {
        reply({ ok: false });
      } finally {
        processing--;
        busy = false;
      }
    });
  });
  return { active: () => processing > 0 || channel.sockets.size > 0 };
};
