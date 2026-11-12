'use strict';

const fs = require('fs');
const { createHash } = require('crypto');
const { get } = require('https');
const { join } = require('path');
const { Transform } = require('stream');
const { pipeline } = require('stream/promises');

async function download(url, path, file, signal) {
  const source = await new Promise((resolve, reject) => {
    get(url, { signal, headers: { 'User-Agent': 'Vaultcast-Updater', 'Accept-Encoding': 'identity' } }, resolve)
      .once('error', reject);
  });
  if (source.statusCode !== 200) {
    source.destroy();
    return;
  }

  const hash = createHash('sha1');
  hash.update('blob ' + file.size + '\0');
  let bytes = 0;
  const verify = new Transform({
    transform(chunk, encoding, callback) {
      bytes += chunk.length;
      if (bytes > file.size) return callback(new Error('Update blob exceeds expected size'));
      hash.update(chunk);
      callback(null, chunk);
    },
    flush(callback) {
      if (bytes !== file.size || hash.digest('hex') !== file.sha) {
        return callback(new Error('Update blob does not match the checked tree'));
      }
      callback();
    },
  });

  const partial = path + '.part';
  try {
    await pipeline(source, verify, fs.createWriteStream(partial, { flags: 'wx', mode: 0o600 }), { signal });
    await fs.promises.rename(partial, path);
  } finally {
    await fs.promises.unlink(partial).catch(() => {});
  }
}

async function main() {
  const directory = process.argv[2];
  const { files, limit } = JSON.parse(await fs.promises.readFile(join(directory, 'manifest.json'), 'utf8'));
  if (!Array.isArray(files) || !Number.isSafeInteger(limit) || limit < 1) return;
  const signal = AbortSignal.timeout(28_000);
  let next = 0;
  async function fetchRemaining() {
    while (next < files.length && !signal.aborted) {
      const index = next++;
      const file = files[index];
      if (!file || !Number.isSafeInteger(file.size) || file.size < 0 || file.size > limit ||
          typeof file.sha !== 'string' || !/^[a-f0-9]{40}$/.test(file.sha)) continue;
      try {
        const url = new URL(file.url);
        if (url.origin !== 'https://raw.githubusercontent.com' || url.username || url.password) continue;
        await download(url, join(directory, index + '.verified'), file, signal);
      } catch (error) {
        // A missing verified file leaves the regular updater download path available.
      }
    }
  }
  await Promise.all(Array.from({ length: Math.min(files.length, 3) }, fetchRemaining));
}

main().catch(() => { process.exitCode = 1; });
