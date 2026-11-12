'use strict';

const { execFile } = require('child_process');
const { createHash } = require('crypto');
const { promises: fs } = require('fs');
const { createRequire } = require('module');
const { dirname, join } = require('path');
const { promisify } = require('util');
const run = promisify(execFile);

module.exports = async function dependencies(root) {
  const manifest = await fs.readFile(join(__dirname, 'package.json'));
  const lock = await fs.readFile(join(__dirname, 'package-lock.json'));
  const id = createHash('sha256').update(manifest).update(lock).digest('hex').slice(0, 24);
  const path = join(root, 'data/runtime', 'node-app-' + id);
  const marker = join(path, '.ready');
  const load = createRequire(join(path, 'package.json'));
  function installed() {
    return { mysql: load('mysql2/promise'), bcrypt: load.resolve('bcryptjs') };
  }
  if (await fs.stat(marker).catch(() => null)) {
    try { return installed(); } catch (error) { /* Restore incomplete dependency storage. */ }
  }

  await fs.mkdir(path, { recursive: true, mode: 0o700 });
  await fs.writeFile(join(path, 'package.json'), manifest);
  await fs.writeFile(join(path, 'package-lock.json'), lock);
  const cli = join(dirname(process.execPath), '../lib/node_modules/npm/bin/npm-cli.js');
  const npmArgs = ['ci', '--omit=dev', '--ignore-scripts', '--no-audit', '--no-fund',
    '--fetch-retries=1', '--fetch-timeout=30000', '--cache', join(root, 'data/runtime/node-app-npm-cache')];
  const bundled = await fs.stat(cli).catch(() => null);
  await run(bundled?.isFile() ? process.execPath : 'npm', bundled?.isFile() ? [cli, ...npmArgs] : npmArgs,
    { cwd: path, timeout: 120000, maxBuffer: 65536, windowsHide: true });
  const result = installed();
  await fs.writeFile(marker, id, { mode: 0o600 });
  return result;
};
