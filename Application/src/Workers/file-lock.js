'use strict';

const { existsSync } = require('fs');
const { spawn } = require('child_process');
const flock = process.platform === 'linux' && ['/usr/bin/flock', '/bin/flock'].find(existsSync);

function lockFile(handle, seconds = 3, signal, shared = false) {
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

module.exports = { flock, lockFile };
