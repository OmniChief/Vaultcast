'use strict';

const { open } = require('fs').promises;
const { join } = require('path');
const { flock, lockFile } = require('../Workers/file-lock');
const installationConfig = require('./legacy-config');
const installDependencies = require('./dependencies');

module.exports = function applicationDatabase(root) {
  const operationLock = join(root, 'data/database-operation.lock');
  let packages;
  let current;
  let initializing = false;
  let ready = false;
  let dependencyRetry = 0;

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

  function poolFor(config) {
    const identity = JSON.stringify(config);
    if (current?.identity === identity) return current.pool;
    const previous = current;
    const pool = packages.mysql.createPool({
      ...config, host: config.host === 'localhost' ? '127.0.0.1' : config.host,
      charset: 'utf8mb4', connectionLimit: 2, maxIdle: 1, idleTimeout: 60000,
      waitForConnections: true, queueLimit: 4, connectTimeout: 5000, flags: '-LOCAL_FILES',
    });
    current = { identity, pool };
    if (previous) previous.pool.end().catch(() => {});
    return pool;
  }

  async function initialize() {
    if (!flock || initializing || ready) return;
    initializing = true;
    try {
      const config = await installationConfig(root);
      if (!packages) {
        if (Date.now() < dependencyRetry) return;
        dependencyRetry = Date.now() + 300000;
        packages = await installDependencies(root);
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
  if (flock) setInterval(initialize, 10000).unref();
  return {
    ready: () => ready,
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
