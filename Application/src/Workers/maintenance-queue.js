'use strict';

const fs = require('fs').promises;
const { join } = require('path');
const { spawn } = require('child_process');
const { flock, lockFile } = require('./file-lock');

module.exports = function startMaintenanceQueue(root) {
  if (!flock) return null;
  const data = join(root, 'data');
  const dir = join(data, 'runtime', 'maintenance-queue');
  const maintenance = join(data, 'runtime', 'maintenance.json');
  let running = false;
  let nextCheck = 0;
  const retry = new Map();

  async function check() {
    if (running || Date.now() < nextCheck) return;
    running = true;
    try {
      if (await fs.stat(maintenance).catch(() => null)) return;
      const names = await fs.readdir(dir).catch(() => []);
      const pending = [];
      for (const name of names) {
        if (!/^\d{14}-[a-f0-9]{12}\.json$/.test(name) || (retry.get(name) || 0) > Date.now()) continue;
        const path = join(dir, name);
        const job = await fs.readFile(path, 'utf8').then(JSON.parse).catch(() => null);
        if (job && typeof job.php === 'string' && job.php.startsWith('/')) pending.push({ name, path, job });
      }
      pending.sort((a, b) => a.job.created - b.job.created || a.name.localeCompare(b.name));
      const selected = pending[0];
      if (!selected) return;

      // Avoid starting a PHP process while a manual CLI job already holds the
      // maintenance lock. PHP checks it again without waiting after startup.
      const lock = await fs.open(join(data, 'maintenance.lock'), 'a');
      try { await lockFile(lock, 0); }
      catch (error) { nextCheck = Date.now() + 3000; return; }
      finally { await lock.close(); }
      if (await fs.stat(maintenance).catch(() => null)) return;
      const args = selected.job.ini ? ['-c', selected.job.ini] : [];
      args.push(join(root, 'src/Console.php'), 'maintenance-relay', selected.name.slice(0, -5));
      const log = await fs.open(join(data, 'maintenance.log'), 'a');
      let code;
      try {
        code = await new Promise(resolve => {
          const child = spawn(selected.job.php, args, { cwd: root, stdio: ['ignore', log.fd, log.fd] });
          child.once('error', () => {});
          child.once('close', resolve);
        });
      } finally { await log.close(); }
      if (code === 76) {
        await fs.unlink(selected.path);
        retry.delete(selected.name);
      } else if (code === 75) {
        nextCheck = Date.now() + 3000;
      } else {
        retry.set(selected.name, Date.now() + 30000);
      }
    } catch (error) {
      nextCheck = Date.now() + 5000;
      console.warn('Vaultcast maintenance dispatch failed:', error.code || error.name);
    } finally { running = false; }
  }

  setInterval(check, 2000).unref();
  return { active: () => running };
};
