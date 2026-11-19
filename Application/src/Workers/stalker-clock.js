'use strict';

const fs = require('fs').promises;
const { join } = require('path');
const { locksAvailable } = require('./file-lock');
const { jobLock } = require('./stalker-download');
const { now } = require('../NodeApp/account-data');
const { doc, q, enabled } = require('../NodeApp/stalker-store');

module.exports = function startStalkerWorkers(root, db) {
  const runtime = join(root, 'data/runtime'),
    versionFile = join(root, 'VERSION');
  const workers = [
    {
      name: 'catalogue',
      flag: 'stalker-library.enabled',
      lock: 'stalker-library.worker.lock',
      delay: 30000,
      next: 0,
    },
    {
      name: 'updates',
      flag: 'stalker-updates.enabled-v2',
      lock: 'stalker-updates.worker.lock',
      delay: 60000,
      next: 0,
    },
  ];
  let engines,
    revision,
    running = false,
    first = 0,
    operations = 0;
  let flagsInitialized = false;
  const loadedVersion = fs
    .readFile(versionFile, 'utf8')
    .then((value) => value.trim())
    .catch(() => null);
  const guarded = {
    ...db,
    run: (operation) =>
      db.run(async (...args) => {
        if ((await fs.readFile(versionFile, 'utf8')).trim() !== (await loadedVersion))
          throw new Error('Stalker worker is waiting for the updated service.');
        return operation(...args);
      }),
  };
  const ensure = () =>
    (engines ??= {
      catalogue: require('./stalker-catalogue')(guarded, root),
      updates: require('../NodeApp/stalker-updates')(guarded, root),
    });
  async function heartbeats(version) {
    for (const worker of workers) {
      const path = join(runtime, 'node-stalker-' + worker.name + '.heartbeat');
      if (await fs.stat(join(runtime, worker.flag)).catch(() => null))
        await fs.writeFile(path, version, { mode: 0o600 });
      else await fs.unlink(path).catch(() => {});
    }
  }
  async function tick() {
    if (running || !locksAvailable() || !db?.ready()) return;
    running = true;
    try {
      const version = (await fs.readFile(versionFile, 'utf8')).trim();
      if (revision && revision !== version) return;
      revision = version;
      const maintenance = await fs
        .readFile(join(runtime, 'maintenance.json'), 'utf8')
        .then(JSON.parse)
        .catch(() => null);
      if (Number(maintenance?.since) > now() - 900) return;
      ensure();
      await fs.mkdir(runtime, { recursive: true, mode: 0o700 });
      if (!flagsInitialized) {
        const state = await guarded.run(async (pool) => ({
          settings: await doc(pool, 'stalker-update-settings'),
          jobs: await q(
            pool,
            "SELECT 1 FROM vaultcast_stalker_jobs WHERE status IN ('queued','running','partial','waiting') LIMIT 1",
          ),
        }));
        const updatesFlag = join(runtime, 'stalker-updates.enabled-v2');
        if (state.settings.url && !enabled(state.settings.paused)) {
          if (!(await fs.stat(updatesFlag).catch(() => null)))
            await fs.writeFile(updatesFlag, '1', { mode: 0o600 });
        } else await fs.unlink(updatesFlag).catch(() => {});
        await fs.unlink(join(runtime, 'stalker-updates.enabled')).catch(() => {});
        const catalogueFlag = join(runtime, 'stalker-library.enabled');
        if (state.jobs.length && !(await fs.stat(catalogueFlag).catch(() => null)))
          await fs.writeFile(catalogueFlag, '1', { mode: 0o600 });
        flagsInitialized = true;
      }
      await heartbeats(version);
      const start = first;
      for (let index = 0; index < workers.length; index++) {
        const slot = (start + index) % workers.length,
          worker = workers[slot],
          heartbeat = join(runtime, 'node-stalker-' + worker.name + '.heartbeat');
        const flag = await fs.stat(join(runtime, worker.flag)).catch(() => null);
        if (!flag) {
          worker.next = 0;
          await fs.unlink(heartbeat).catch(() => {});
          continue;
        }
        // Publishing ownership makes the legacy loop exit before its lock is claimed.
        await fs.writeFile(heartbeat, version, { mode: 0o600 });
        if (worker.changed !== flag.mtimeMs) {
          worker.changed = flag.mtimeMs;
          worker.next = 0;
        }
        if (worker.next > Date.now()) continue;
        first = (slot + 1) % workers.length;
        const lock = await jobLock(join(runtime, worker.lock));
        if (!lock) {
          worker.next = Date.now() + 3000;
          continue;
        }
        const controller = new AbortController();
        let checking = false,
          pending = Promise.resolve();
        const timer = setTimeout(
          () => controller.abort(new Error('Stalker worker slice timed out.')),
          120000,
        );
        const pulse = setInterval(() => {
          if (checking) return;
          checking = true;
          pending = (async () => {
            const maintenance = await fs
              .readFile(join(runtime, 'maintenance.json'), 'utf8')
              .then(JSON.parse)
              .catch(() => null);
            if (
              Number(maintenance?.since) > now() - 900 ||
              (await fs.readFile(versionFile, 'utf8')).trim() !== version
            )
              controller.abort(new Error('Stalker worker paused for the application update.'));
            else await heartbeats(version);
          })()
            .catch((error) => controller.abort(error))
            .finally(() => {
              checking = false;
            });
        }, 15000);
        try {
          const result = await engines[worker.name].tick(controller.signal);
          worker.next = Date.now() + (worker.name === 'catalogue' && result.busy ? 1000 : worker.delay);
        } catch (error) {
          worker.next = Date.now() + worker.delay;
        } finally {
          clearTimeout(timer);
          clearInterval(pulse);
          await pending;
          await lock.close();
        }
        return;
      }
    } catch (error) {
      /* Bootstrap and database availability are retried automatically. */
    } finally {
      running = false;
    }
  }
  setInterval(tick, 3000).unref();
  return {
    active: () => running || operations > 0,
    wake: () => {
      for (const worker of workers) worker.next = 0;
      void tick();
    },
    async updates(body, owner) {
      operations++;
      try {
        const result = await ensure().updates.operation(body, owner);
        for (const worker of workers) worker.next = 0;
        void tick();
        return result;
      } finally {
        operations--;
      }
    },
    async catalogueControl(body) {
      operations++;
      try {
        const result = await ensure().catalogue.control(body);
        for (const worker of workers) worker.next = 0;
        void tick();
        return result;
      } finally {
        operations--;
      }
    },
  };
};
