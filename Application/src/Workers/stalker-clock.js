'use strict';

const fs = require('fs').promises;
const { join } = require('path');
const { spawn } = require('child_process');

module.exports = function startStalkerWorkers(root) {
  if (process.platform !== 'linux') return;
  const runtime = join(root, 'data/runtime');
  const versionFile = join(root, 'VERSION');
  const workers = [
    { name: 'catalogue', enabled: 'stalker-library.enabled', next: 0, delay: 30000 },
    { name: 'updates', enabled: 'stalker-updates.enabled-v2', next: 0, delay: 60000, changed: 0 },
  ];
  let running = false;
  let revision;
  let first = 0;

  async function tick() {
    if (running) return;
    running = true;
    try {
      const version = (await fs.readFile(versionFile, 'utf8')).trim();
      if (revision && revision !== version) return;
      revision = version;
      if (await fs.stat(join(runtime, 'maintenance.json')).catch(() => null)) return;
      const settings = JSON.parse(await fs.readFile(join(runtime, 'node-stalker.json'), 'utf8'));
      if (settings.version !== version || typeof settings.php !== 'string' || !settings.php.startsWith('/')) return;
      const now = Date.now();
      for (let index = 0; index < workers.length; index++) {
        const slot = (first + index) % workers.length;
        const worker = workers[slot];
        const enabled = await fs.stat(join(runtime, worker.enabled)).catch(() => null);
        if (!enabled) {
          worker.next = 0;
          continue;
        }
        if (worker.name === 'updates' && worker.changed !== enabled.mtimeMs) {
          worker.changed = enabled.mtimeMs;
          worker.next = 0;
        }
        if (worker.next > now) {
          if (worker.ready) await fs.writeFile(join(runtime, 'node-stalker-' + worker.name + '.heartbeat'), version);
          continue;
        }
        first = (slot + 1) % workers.length;
        try {
          const result = await runSlice(root, settings, worker.name);
          worker.ready = true;
          worker.next = Date.now() + (worker.name === 'catalogue' && result.busy ? 1000 : worker.delay);
          if (worker.name === 'updates' && result.nextCheck > 0) {
            worker.next = Math.max(worker.next, result.nextCheck * 1000);
          }
          await fs.writeFile(join(runtime, 'node-stalker-' + worker.name + '.heartbeat'), version);
        } catch (error) {
          worker.ready = false;
          worker.next = Date.now() + worker.delay;
        }
        return;
      }
    } catch (error) {
      // The app may not have enabled or configured Stalker yet.
    } finally {
      running = false;
    }
  }

  setInterval(tick, 3000).unref();
};

function runSlice(root, settings, name) {
  const args = settings.ini ? ['-c', settings.ini] : [];
  args.push(join(root, 'src/Console.php'), 'stalker-' + name + '-slice');
  return new Promise((resolve, reject) => {
    const child = spawn(settings.php, args, { cwd: root, stdio: ['ignore', 'pipe', 'ignore'] });
    let output = '';
    child.stdout.setEncoding('utf8');
    child.stdout.on('data', chunk => {
      if (output.length < 16384) output += chunk;
    });
    child.once('error', reject);
    child.once('close', code => {
      try {
        const result = JSON.parse(output);
        if (code !== 0 || !result.ok) throw new Error('Stalker slice failed');
        resolve(result);
      } catch (error) {
        reject(error);
      }
    });
  });
}
