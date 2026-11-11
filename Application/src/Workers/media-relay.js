'use strict';

const http = require('http');
const https = require('https');
const fs = require('fs').promises;
const path = require('path');
const { isIP } = require('net');
const { pipeline } = require('stream/promises');

const ticketDirectory = path.join(__dirname, '../../data/runtime/node-media');
const consumedTickets = new Set();
const metadataRequests = new Map();
let active = 0;
let playlists = 0;

async function takeTicket(token, purpose) {
  if (!/^[a-f0-9]{48}$/.test(token)) return null;
  const file = path.join(ticketDirectory, token + '.json');
  if (consumedTickets.has(token)) return null;
  let ticket;
  try {
    ticket = JSON.parse(await fs.readFile(file, 'utf8'));
  } catch (error) {
    return null;
  }
  if (consumedTickets.has(token) || ticket.purpose !== purpose || ticket.expires < Date.now() / 1000) return null;
  consumedTickets.add(token);
  setTimeout(() => consumedTickets.delete(token), 16000).unref();
  // On Windows the relay can read the ticket while PHP retains cleanup ownership.
  fs.unlink(file).catch(() => {});
  return ticket;
}

function providerResponse(ticket, url, method, signal) {
  const family = isIP(ticket.ip);
  const urlIp = isIP(url.hostname);
  if (!family || (urlIp && url.hostname !== ticket.ip)) throw new Error('Invalid pinned address');
  const headers = { accept: '*/*', 'user-agent': ticket.ua, 'accept-encoding': 'identity' };
  if (ticket.ref) headers.referer = ticket.ref;
  if (ticket.range) headers.range = ticket.range;
  if (ticket.ifRange) headers['if-range'] = ticket.ifRange;
  if (ticket.cookies) headers.cookie = ticket.cookies;
  return new Promise((resolve, reject) => {
    const transport = url.protocol === 'https:' ? https : http;
    const request = transport.request(url, {
      method, headers, signal, agent: false, family, autoSelectFamily: false,
      lookup: (_host, _options, callback) => callback(null, ticket.ip, family),
    }, response => {
      clearTimeout(connectTimer);
      resolve(response);
    });
    const connectTimer = setTimeout(() => request.destroy(new Error('Provider did not respond')), 8000);
    request.setTimeout(45000, () => request.destroy(new Error('Provider stalled')));
    request.once('error', reject);
    request.once('close', () => clearTimeout(connectTimer));
    request.end();
  });
}

function rewritePlaylist(body, base, ticket) {
  if (!body.trimStart().startsWith('#EXTM3U') || body.includes('{$')) throw new Error('Unsupported playlist');
  function rewrite(value) {
    const target = new URL(value, base);
    if (target.origin !== base.origin || target.username || target.password) throw new Error('Playlist changes provider');
    if (!ticket.jar.startsWith('st') && /\.(?:ts|m4s|mp4|m4a|aac|vtt|srt|key)$/i.test(target.pathname)) return target.href;
    const parts = { u: target.href, jar: ticket.jar, ua: ticket.ua };
    if (ticket.ref) parts.ref = ticket.ref;
    return '/api/live/iptv_hls?' + new URLSearchParams(parts);
  }
  return body.split(/\r?\n/).map(line => {
    const value = line.trim();
    if (!value) return line;
    if (value[0] !== '#') return rewrite(value);
    return line.replace(/URI="([^"]+)"/gi, (_attribute, uri) => 'URI="' + rewrite(uri) + '"');
  }).join('\n');
}

async function readBody(source, limit) {
  const chunks = [];
  let length = 0;
  for await (const chunk of source) {
    length += chunk.length;
    if (length > limit) throw new Error('Provider response exceeds the size limit');
    chunks.push(chunk);
  }
  return Buffer.concat(chunks, length);
}

async function serveMedia(request, response) {
  let ticket;
  let upstream;
  let entered = false;
  let readingPlaylist = false;
  const abort = new AbortController();
  response.once('close', () => abort.abort());
  try {
    ticket = await takeTicket(request.params.token, 'media');
    if (!ticket) return response.sendStatus(404);
    if (active >= 12) return response.sendStatus(503);
    active++;
    entered = true;
    const url = new URL(ticket.url);
    if (!['http:', 'https:'].includes(url.protocol) || url.hostname !== ticket.host
      || url.username || url.password) throw new Error('Unsupported provider URL');
    upstream = await providerResponse(ticket, url, request.method, abort.signal);
    const type = upstream.headers['content-type'] || '';
    const encoding = upstream.headers['content-encoding'] || 'identity';
    const playlist = /mpegurl/i.test(type) || /\.m3u8$/i.test(url.pathname);
    if (![200, 206].includes(upstream.statusCode) || upstream.headers['set-cookie'] || encoding !== 'identity'
      || (!playlist && !/^(?:video\/|audio\/|application\/octet-stream(?:;|$))/i.test(type))) {
      throw new Error('Provider response requires the compatibility transport');
    }
    let body = null;
    if (playlist && request.method !== 'HEAD') {
      if (playlists >= 2) throw new Error('Playlist readers are busy');
      playlists++;
      readingPlaylist = true;
      body = rewritePlaylist((await readBody(upstream, 5_000_000)).toString('utf8'), url, ticket);
    }
    response.set({
      'Cache-Control': 'private, no-store',
      'Content-Type': playlist ? 'application/vnd.apple.mpegurl; charset=utf-8' : type,
      'Access-Control-Allow-Origin': ticket.origin,
      'Access-Control-Expose-Headers': 'Content-Length, Content-Range, Accept-Ranges',
      'Vary': 'Origin',
      'X-Content-Type-Options': 'nosniff',
      'X-Accel-Buffering': 'no',
    });
    if (!playlist) {
      for (const header of ['content-length', 'content-range', 'accept-ranges']) {
        if (upstream.headers[header]) response.set(header, upstream.headers[header]);
      }
    }
    response.status(playlist ? 200 : upstream.statusCode);
    if (request.method === 'HEAD') return response.end();
    if (body !== null) return response.end(body);
    await pipeline(upstream, response, { signal: abort.signal });
  } catch (error) {
    if (response.destroyed) return;
    if (response.headersSent) return response.destroy();
    if (ticket && typeof ticket.fallback === 'string' && /^\/api\/live\/(?:iptv_hls|stalker_stream)\?/.test(ticket.fallback)) {
      return response.set('X-Accel-Redirect', ticket.fallback).end();
    }
    response.sendStatus(502);
  } finally {
    if (upstream) upstream.destroy();
    if (entered) active--;
    if (readingPlaylist) playlists--;
  }
}

async function providerMetadata(ticket, url, signal) {
  const upstream = await providerResponse(ticket, url, 'GET', signal);
  try {
    const status = upstream.statusCode;
    const location = upstream.headers.location;
    if (status < 200 || status >= 300) return { status, body: location };
    const encoding = upstream.headers['content-encoding'];
    if (encoding && encoding !== 'identity') throw new Error('Encoded API response');
    return { status, body: await readBody(upstream, 32 * 1024 * 1024) };
  } finally {
    upstream.destroy();
  }
}

async function serveMetadata(request, response) {
  if (request.method !== 'GET') return response.sendStatus(405);
  let release;
  try {
    const ticket = await takeTicket(request.params.token, 'metadata');
    if (!ticket) return response.sendStatus(404);
    if (response.destroyed) return;
    const url = new URL(ticket.url);
    if (!['http:', 'https:'].includes(url.protocol) || url.hostname !== ticket.host
      || url.username || url.password) return response.sendStatus(400);
    const key = JSON.stringify([ticket.url, ticket.ip, ticket.ua]);
    let entry = metadataRequests.get(key);
    if (!entry) {
      if (metadataRequests.size >= 2) return response.sendStatus(503);
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), 30000);
      entry = { controller, clients: 0, work: null };
      entry.work = providerMetadata(ticket, url, controller.signal).finally(() => {
        clearTimeout(timer);
        if (metadataRequests.get(key) === entry) metadataRequests.delete(key);
      });
      metadataRequests.set(key, entry);
    }
    entry.clients++;
    let released = false;
    release = () => {
      if (released) return;
      released = true;
      entry.clients--;
      if (entry.clients === 0 && metadataRequests.get(key) === entry) {
        metadataRequests.delete(key);
        entry.controller.abort();
      }
    };
    response.once('close', release);
    const result = await entry.work;
    if (response.destroyed) return;
    response.set({
      'Cache-Control': 'private, no-store',
      'X-Vaultcast-Upstream-Status': String(result.status),
    });
    response.end(result.body);
  } catch (error) {
    if (!response.destroyed) response.sendStatus(502);
  } finally {
    if (release) {
      response.removeListener('close', release);
      release();
    }
  }
}

module.exports = serveMedia;
module.exports.metadata = serveMetadata;

module.exports.takeTicket = takeTicket;

module.exports.providerResponse = providerResponse;
