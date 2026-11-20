'use strict';

const fs = require('fs');
const { get } = require('https');
const { Readable, Transform } = require('stream');
const { pipeline } = require('stream/promises');
const { createGunzip } = require('zlib');
const { validate } = require('./epg-channels');

function capBytes(limit) {
  let received = 0;
  return new Transform({
    transform(chunk, encoding, callback) {
      received += chunk.length;
      if (received > limit) callback(new Error('Guide exceeds size limit'));
      else callback(null, chunk);
    },
  });
}

async function openGuide(url, origin, signal) {
  for (let hop = 0; hop < 4; hop++) {
    const sameSource = url.origin === origin ||
      (origin === 'https://www.open-epg.com' && url.origin === 'https://open-epg.com');
    if (!sameSource || url.username || url.password) throw new Error('Unsupported guide address');
    const response = await new Promise((resolve, reject) => {
      get(url, { signal, headers: { 'User-Agent': 'Vaultcast-EPG', 'Accept-Encoding': 'identity' } }, resolve)
        .once('error', reject);
    });
    if (response.statusCode === 200) return response;
    response.destroy();
    if (![301, 302, 303, 307, 308].includes(response.statusCode) || !response.headers.location) {
      throw new Error('Guide source returned HTTP ' + response.statusCode);
    }
    url = new URL(response.headers.location, url);
  }
  throw new Error('Too many guide redirects');
}

function requireXml() {
  let prefix = Buffer.alloc(0);
  let checked = false;
  return new Transform({
    transform(chunk, encoding, callback) {
      if (checked) return callback(null, chunk);
      prefix = Buffer.concat([prefix, chunk]);
      const start = prefix.toString('utf8', 0, 4096).trimStart();
      if (/^<(?:\?xml\s|tv[\s>]|!--|!DOCTYPE\s+tv[\s>])/.test(start)) {
        checked = true;
        return callback(null, prefix);
      }
      if (prefix.length >= 4096) return callback(new Error('Expected XMLTV data'));
      callback();
    },
    flush(callback) {
      callback(checked ? null : new Error('Expected XMLTV data'));
    },
  });
}

async function saveGuide(response, target, signal) {
  const input = response.iterator({ destroyOnReturn: false });
  let head = Buffer.alloc(0);
  while (head.length < 2) {
    const first = await input.next();
    if (first.done) throw new Error('Empty guide');
    head = Buffer.concat([head, first.value]);
  }
  async function* chunks() {
    yield head;
    yield* input;
  }
  const mib = 1024 * 1024;
  const streams = [Readable.from(chunks()), capBytes(128 * mib)];
  if (head[0] === 0x1f && head[1] === 0x8b) streams.push(createGunzip());
  streams.push(capBytes(256 * mib), requireXml(), fs.createWriteStream(target + '.part', { flags: 'wx' }));
  await pipeline(...streams, { signal });
  const validated = await validate(target + '.part').then(() => true, () => false);
  await fs.promises.rename(target + '.part', target);
  return validated;
}

async function main() {
  if (process.argv[2] === '--file') {
    const source = fs.createReadStream(process.argv[3]);
    const target = process.argv[4];
    try {
      return await saveGuide(source, target, AbortSignal.timeout(60_000));
    } finally {
      source.destroy();
      await fs.promises.unlink(target + '.part').catch(() => {});
    }
  }
  const url = new URL(process.argv[2]);
  const target = process.argv[3];
  if (!['https://www.open-epg.com', 'https://raw.githubusercontent.com'].includes(url.origin)) {
    throw new Error('Unsupported guide origin');
  }
  const signal = AbortSignal.timeout(60_000);
  const response = await openGuide(url, url.origin, signal);
  try {
    return await saveGuide(response, target, signal);
  } finally {
    response.destroy();
    await fs.promises.unlink(target + '.part').catch(() => {});
  }
}

main().then(validated => { if (validated) process.stdout.write('validated\n'); })
  .catch(() => { process.exitCode = 1; });
