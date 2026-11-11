'use strict';

const fs = require('fs').promises;
const { join, dirname } = require('path');
const { takeTicket } = require('./media-relay');
const { flock, lockFile } = require('./file-lock');

module.exports = function startDropUploads(io) {
  if (!flock || !fs.statfs) return null;
  const channel = io.of('/drop-upload');
  let active = 0;
  channel.use(async (socket, next) => {
    const token = socket.handshake.auth?.ticket;
    const ticket = typeof token === 'string' ? await takeTicket(token, 'drop-upload').catch(() => null) : null;
    if (!ticket || !/^[a-f0-9]{32}$/.test(ticket.id) || channel.sockets.size >= 8) return next(new Error('Upload unavailable'));
    socket.data.ticket = ticket;
    next();
  });
  channel.on('connection', socket => {
    let busy = false;
    const expires = Date.now() + 120000;
    const timer = setTimeout(() => socket.disconnect(true), 120000);
    socket.once('disconnect', () => clearTimeout(timer));
    socket.on('chunk', async (offset, data, reply) => {
      if (typeof reply !== 'function') return;
      const ticket = socket.data.ticket;
      if (busy || active >= 2 || Date.now() >= expires || !Number.isSafeInteger(offset) || offset < 0
        || !Buffer.isBuffer(data) || !data.length || data.length > ticket.chunk || offset + data.length > ticket.bytes) return reply({ok:false});
      let file;
      busy = true;
      active++;
      try {
        const name = join(__dirname, '../../data/drop', ticket.id.slice(0, 2), ticket.id + '.bin');
        file = await fs.open(name, 'r+');
        await lockFile(file);
        const { size, nlink } = await file.stat();
        if (!nlink || Date.now() >= expires) return reply({ok:false});
        if (size !== offset) return reply({ok:false,status:409,received:size});
        const space = await fs.statfs(dirname(name));
        if (space.bavail * space.bsize < data.length) return reply({ok:false});
        let written = 0;
        try {
          while (written < data.length) {
            const result = await file.write(data, written, data.length - written, offset + written);
            if (!result.bytesWritten) throw new Error('Incomplete write');
            written += result.bytesWritten;
          }
        } catch (error) {
          await file.truncate(offset);
          throw error;
        }
        reply({ok:true,received:offset + written});
      } catch (error) {
        reply({ok:false});
      } finally {
        if (file) await file.close().catch(() => {});
        active--;
        busy = false;
      }
    });
  });
  return { active: () => active > 0 };
};
