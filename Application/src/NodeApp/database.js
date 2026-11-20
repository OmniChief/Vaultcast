'use strict';

const { open } = require('fs').promises;
const { join } = require('path');
const { flock, lockFile, configureWindowsLocks } = require('../Workers/file-lock');
const installationConfig = require('./legacy-config');
const installDependencies = require('./dependencies');
const retireRouter = require('./retire-router');

module.exports = function applicationDatabase(root) {
  const operationLock = join(root, 'data/database-operation.lock');
  let packages;
  let current;
  let initializing = false;
  let ready = false;
  let dependencyRetry = 0;
  let routerRetired = false;
  let retireRetry = 0;

  async function withLock(operation) {
    const file = await open(operationLock, 'r').catch(error => {
      if (error.code !== 'ENOENT') throw error;
      return open(operationLock, 'a', 0o644);
    });
    try {
      await lockFile(file, 0, undefined, true);
      return await operation();
    } finally {
      await file.close();
    }
  }

  function poolFor({ base, ...config }) {
    const identity = JSON.stringify(config);
    if (current?.identity === identity) { current.base = base; return current.pool; }
    const previous = current;
    const pool = packages.mysql.createPool({
      ...config, host: config.host === 'localhost' ? '127.0.0.1' : config.host,
      charset: 'utf8mb4', connectionLimit: 2, maxIdle: 1, idleTimeout: 60000,
      waitForConnections: true, queueLimit: 4, connectTimeout: 5000, flags: '-LOCAL_FILES',
    });
    current = { identity, pool, base };
    if (previous) previous.pool.end().catch(() => {});
    return pool;
  }

  async function initialize() {
    if (initializing || (!flock && process.platform !== 'win32')) return;
    if (ready) {
      if (!routerRetired && Date.now() >= retireRetry) {
        retireRetry = Date.now() + 60000;
        try { routerRetired = await retireRouter(root); }
        catch (error) { console.warn('Could not retire the old development router:', error.message); }
      }
      return;
    }
    initializing = true;
    try {
      const config = await installationConfig(root);
      if (!packages) {
        if (Date.now() < dependencyRetry) return;
        dependencyRetry = Date.now() + 300000;
        packages = await installDependencies(root);
        if (process.platform === 'win32') configureWindowsLocks(packages.fileLocks);
      }
      await withLock(async () => {
        const pool = poolFor(config);
        await pool.execute('SELECT name FROM vaultcast_documents WHERE name=?', ['settings']);
        await pool.execute('SELECT token_hash FROM vaultcast_sessions LIMIT 0');
        await pool.execute('SELECT password_hash FROM vaultcast_users LIMIT 0');
        ready = true;
      });
    } catch (error) {
      // An unconfigured or updating instance can continue to use its existing routes.
      ready = false;
    } finally {
      initializing = false;
    }
  }

  void initialize();
  if (flock || process.platform === 'win32') setInterval(initialize, 10000).unref();
  return {
    ready: () => ready,
    base: () => current?.base ?? '/',
    services: () => packages,
    async run(operation) {
      if (!ready) throw new Error('Node application database unavailable');
      return withLock(async () => {
        let config;
        try { config = await installationConfig(root); }
        catch (error) { ready = false; throw error; }
        return operation(poolFor(config), packages.bcrypt);
      });
    },
  };
};
