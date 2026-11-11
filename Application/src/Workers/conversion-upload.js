'use strict';

const fs = require('fs').promises;
const { join, dirname } = require('path');
const { takeTicket } = require('./media-relay');
const { flock, lockFile } = require('./file-lock');

const dataRoot = join(__dirname, '../../data');
async function grantFor(ticket) {
  const grants = JSON.parse(await fs.readFile(join(dataRoot, 'runtime/convert-upload-grants.json'), 'utf8'));
  const grant = grants[ticket.id];
  const version = (await fs.readFile(join(dataRoot, '../VERSION'), 'utf8')).trim();
  if (!grant || grant.lease !== ticket.lease || grant.key !== ticket.key || grant.version !== version
    || grant.expires <= Date.now() / 1000) throw new Error('Conversion upload lease expired');
  return grant;
}

async function append(ticket, offset, bytes) {
  const lock = await fs.open(join(dataRoot, 'convert_upload_' + ticket.id + '.lock'), 'a');
  try {
    await lockFile(lock);
    const grant = await grantFor(ticket);
    const space = await fs.statfs(dirname(grant.part));
    if (offset + bytes.length > grant.limit || space.bavail * space.bsize < bytes.length + 16777216) {
      throw new Error('Conversion storage limit reached');
    }
    const file = await fs.open(grant.part, 'a+', 0o600);
    try {
      const { size } = await file.stat();
      if (size !== offset) {
        if (offset + bytes.length !== size) throw new Error('Conversion offset changed');
        const previous = Buffer.alloc(bytes.length);
        const result = await file.read(previous, 0, previous.length, offset);
        if (result.bytesRead !== bytes.length || !previous.equals(bytes)) throw new Error('Conversion chunk changed');
        return size;
      }
      try {
        await file.writeFile(bytes);
        const current = await grantFor(ticket);
        if (current.part !== grant.part) throw new Error('Conversion upload target changed');
        return size + bytes.length;
      } catch (error) {
        await file.truncate(size);
        throw error;
      }
    } finally {
      await file.close();
    }
  } finally {
    await lock.close();
  }
}

module.exports = function startConversionUploads(io) {
  if (!flock || !fs.statfs) return null;
  const channel = io.of('/conversion-upload');
  let active = 0;
  channel.use(async (socket, next) => {
    const token = socket.handshake.auth?.ticket;
    const ticket = typeof token === 'string' ? await takeTicket(token, 'conversion-upload').catch(() => null) : null;
    if (!ticket || !/^[a-f0-9]{16}$/.test(ticket.id) || channel.sockets.size >= 8) return next(new Error('Conversion upload unavailable'));
    socket.data.ticket = ticket;
    next();
  });
  channel.on('connection', socket => {
    let busy = false;
    const timer = setTimeout(() => socket.disconnect(true), 65000);
    socket.once('disconnect', () => clearTimeout(timer));
    socket.on('chunk', async (offset, bytes, reply) => {
      if (typeof reply !== 'function') return;
      if (busy || active >= 2 || !Number.isSafeInteger(offset) || offset < 0
        || !Buffer.isBuffer(bytes) || !bytes.length || bytes.length > 4194304) return reply({ok:false});
      active++;
      busy = true;
      try {
        const received = await append(socket.data.ticket, offset, bytes);
        reply({ok:true,received});
      } catch (error) {
        reply({ok:false});
      } finally {
        active--;
        busy = false;
      }
    });
  });
  return {active:() => active > 0};
};
