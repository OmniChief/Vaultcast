'use strict';

const http = require('http');
const https = require('https');
const fs = require('fs').promises;
const path = require('path');
const { isIP } = require('net');
const { pipeline } = require('stream/promises');

const ticketDirectory = path.join(__dirname, '../../data/runtime/node-media');
let active = 0;
let playlists = 0;

async function takeTicket(token) {
  if (!/^[a-f0-9]{48}$/.test(token)) return null;
  const file = path.join(ticketDirectory, token + '.json');
  const claimed = file + '.used';
  try {
    await fs.rename(file, claimed);
  } catch (error) {
    return null;
  }
  try {
    const ticket = JSON.parse(await fs.readFile(claimed, 'utf8'));
    return ticket.expires >= Date.now() / 1000 ? ticket : null;
  } finally {
    await fs.unlink(claimed).catch(() => {});
  }
}

function providerResponse(ticket, url, method, signal) {
  const family = isIP(ticket.ip);
  const urlIp = isIP(url.hostname);
  if (!family || (urlIp && url.hostname !== ticket.ip)) throw new Error('Invalid pinned address');
  const headers = { accept: '*/*', 'user-agent': ticket.ua, 'accept-encoding': 'identity' };
  if (ticket.ref) headers.referer = ticket.ref;
  if (ticket.range) headers.range = ticket.range;
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
    if (/\.(?:ts|m4s|mp4|m4a|aac|vtt|srt|key)$/i.test(target.pathname)) return target.href;
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

async function playlistBody(source) {
  const chunks = [];
  let length = 0;
  for await (const chunk of source) {
    length += chunk.length;
    if (length > 5_000_000) throw new Error('Playlist exceeds the size limit');
    chunks.push(chunk);
  }
  return Buffer.concat(chunks, length).toString('utf8');
}

async function serveMedia(request, response) {
  let ticket;
  let upstream;
  let entered = false;
  let readingPlaylist = false;
  const abort = new AbortController();
  response.once('close', () => abort.abort());
  try {
    ticket = await takeTicket(request.params.token);
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
      body = rewritePlaylist(await playlistBody(upstream), url, ticket);
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
    if (ticket && typeof ticket.fallback === 'string' && ticket.fallback.startsWith('/api/live/iptv_hls?')) {
      return response.set('X-Accel-Redirect', ticket.fallback).end();
    }
    response.sendStatus(502);
  } finally {
    if (upstream) upstream.destroy();
    if (entered) active--;
    if (readingPlaylist) playlists--;
  }
}

module.exports = serveMedia;
