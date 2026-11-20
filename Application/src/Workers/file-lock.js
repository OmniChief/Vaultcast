'use strict';

const { existsSync } = require('fs');
const { spawn } = require('child_process');
const flock = process.platform === 'linux' && ['/usr/bin/flock', '/bin/flock'].find(existsSync);
let windowsLocks;

function configureWindowsLocks(extension) { windowsLocks = extension; }

async function windowsLock(handle, seconds, signal, shared) {
  const until = Date.now() + seconds * 1000;
  for (;;) {
    signal?.throwIfAborted();
    if (windowsLocks.tryLock(handle.fd, { shared })) return;
    if (Date.now() >= until) throw new Error('Transfer lock unavailable');
    await new Promise(resolve => setTimeout(resolve, 50));
  }
}

function lockFile(handle, seconds = 3, signal, shared = false) {
  if (windowsLocks) return windowsLock(handle, seconds, signal, shared);
  return new Promise((resolve, reject) => {
    const child = spawn(flock, [shared ? '-s' : '-x', '-w', String(seconds), '3'], {
      stdio: ['ignore', 'ignore', 'ignore', handle.fd], signal,
    });
    child.on('error', () => {});
    child.once('close', code => {
      if (code === 0) resolve();
      else reject(new Error('Transfer lock unavailable'));
    });
  });
}

module.exports = { flock, lockFile, configureWindowsLocks, locksAvailable: () => Boolean(flock || windowsLocks) };
