'use strict';

const { randomBytes, createCipheriv, createDecipheriv } = require('crypto');
const { mediaUrl } = require('./stalker-provider');
const problem = (status, message) => Object.assign(new Error(message), { status, expose: true });

function resourceToken(value, key, playback) {
  const iv = randomBytes(12),
    cipher = createCipheriv('aes-256-gcm', Buffer.from(key, 'hex'), iv);
  cipher.setAAD(Buffer.from(playback));
  const body = Buffer.concat([cipher.update(value, 'utf8'), cipher.final()]);
  return Buffer.concat([iv, cipher.getAuthTag(), body]).toString('base64url');
}
function resourceUrl(token, key, playback) {
  try {
    if (typeof token !== 'string' || token.length > 12000 || !/^[\w-]+$/.test(token)) throw new Error();
    const bytes = Buffer.from(token, 'base64url');
    const cipher = createDecipheriv('aes-256-gcm', Buffer.from(key, 'hex'), bytes.subarray(0, 12));
    cipher.setAAD(Buffer.from(playback));
    cipher.setAuthTag(bytes.subarray(12, 28));
    return mediaUrl(Buffer.concat([cipher.update(bytes.subarray(28)), cipher.final()]).toString()).href;
  } catch {
    throw problem(403, 'Invalid playback resource.');
  }
}

function playlist(body, base, params, key, playback) {
  if (!body.trimStart().startsWith('#EXTM3U'))
    throw problem(502, 'The provider returned an invalid playlist.');
  const references = new Map();
  function child(value) {
    if (references.has(value)) return references.get(value);
    const target = mediaUrl(new URL(value, base).href);
    if (Buffer.byteLength(target.href) > 8192) throw problem(502, 'Provider resource URL exceeds the limit.');
    const query = new URLSearchParams(params);
    query.set('resource', resourceToken(target.href, key, playback));
    const path = '?' + query;
    references.set(value, path);
    return path;
  }
  const output = [];
  let length = 0;
  for (const line of body.split(/\r?\n/)) {
    const value = line.trim();
    const rewritten = !value
      ? line
      : value.startsWith('#')
        ? line.replace(/\bURI="([^"]+)"/gi, (_all, uri) => 'URI="' + child(uri) + '"')
        : child(value);
    length += Buffer.byteLength(rewritten) + 1;
    if (length > 20 * 1024 * 1024 || output.length >= 100000)
      throw problem(502, 'Provider playlist exceeds the size limit.');
    output.push(rewritten);
  }
  return output.join('\n');
}

module.exports = { playlist, resourceUrl };
