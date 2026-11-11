'use strict';

const fs = require('fs');
const { get } = require('https');
const { join } = require('path');
const { Transform } = require('stream');
const { pipeline } = require('stream/promises');

async function download(url, path, limit, signal) {
  const source = await new Promise((resolve, reject) => {
    get(url, { signal, headers: { 'User-Agent': 'Vaultcast-Updater' } }, resolve)
      .once('error', reject);
  });
  if (source.statusCode !== 200) {
    source.destroy();
    return;
  }

  let bytes = 0;
  const capped = new Transform({
    transform(chunk, encoding, callback) {
      bytes += chunk.length;
      if (bytes > limit) callback(new Error('Update blob exceeds size limit'));
      else callback(null, chunk);
    },
  });

  const partial = path + '.part';
  try {
    await pipeline(source, capped, fs.createWriteStream(partial, { flags: 'wx' }), { signal });
    await fs.promises.rename(partial, path);
  } finally {
    await fs.promises.unlink(partial).catch(() => {});
  }
}

async function main() {
  const directory = process.argv[2];
  const { urls, limit } = JSON.parse(await fs.promises.readFile(join(directory, 'manifest.json'), 'utf8'));
  const signal = AbortSignal.timeout(28_000);
  let next = 0;
  async function fetchRemaining() {
    while (next < urls.length && !signal.aborted) {
      const index = next++;
      const url = new URL(urls[index]);
      if (url.origin !== 'https://raw.githubusercontent.com') continue;
      await download(url, join(directory, index + '.blob'), limit, signal).catch(() => {});
    }
  }
  await Promise.all(Array.from({ length: Math.min(urls.length, 3) }, fetchRemaining));
}

main().catch(() => { process.exitCode = 1; });
