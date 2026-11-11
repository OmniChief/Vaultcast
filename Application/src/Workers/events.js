'use strict';

const fs = require('fs');
const { join } = require('path');
const { takeTicket } = require('./media-relay');
const listeners = new Map();
const directory = join(__dirname, '../../data/runtime/events');
let watcher;
let connected = 0;

function ready() {
  if (watcher) return true;
  try {
    watcher = fs.watch(directory, (_event, topic) => {
      for (const receive of listeners.get(topic) || []) receive();
    });
    watcher.on('error', () => {
      watcher.close();
      watcher = null;
    });
    watcher.unref();
    return true;
  } catch (error) {
    return false;
  }
}

module.exports = async function events(request, response) {
  try {
    if (request.method !== 'GET') return response.sendStatus(405);
    const ticket = await takeTicket(request.params.token, 'events');
    if (!ticket) return response.sendStatus(404);
    if (response.destroyed) return;
    if (connected >= 96 || !ready()) return response.sendStatus(503);
    const topic = ticket.topic;
    const group = listeners.get(topic) || new Set();
    listeners.set(topic, group);
    connected++;
    let last = 0;
    const receive = () => {
      if (response.writableEnded || response.destroyed) return;
      if (ticket.once) return response.json({ ok: true, changed: true });
      if (Date.now() - last < 200) return;
      last = Date.now();
      if (!response.write('event: change\ndata: 1\n\n')) response.end();
    };
    group.add(receive);
    response.set({ 'Cache-Control': 'no-store', 'X-Accel-Buffering': 'no' });
    const lifetime = setTimeout(() => {
      if (ticket.once) response.json({ ok: true, changed: false });
      else response.end();
    }, ticket.once ? 12000 : 180000);
    const keepAlive = ticket.once ? null : setInterval(() => response.write(': heartbeat\n\n'), 15000);
    response.once('close', () => {
      clearTimeout(lifetime);
      if (keepAlive) clearInterval(keepAlive);
      group.delete(receive);
      if (!group.size) listeners.delete(topic);
      connected--;
    });
    if (!ticket.once) {
      response.set('Content-Type', 'text/event-stream');
      response.flushHeaders();
      response.write(': ready\n\n');
    }
  } catch (error) {
    if (!response.headersSent) response.sendStatus(502);
    else response.end();
  }
};
