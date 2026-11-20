'use strict';

const { createHash } = require('crypto');
const { access, lstat, mkdir, open, readFile, unlink } = require('fs').promises;
const { join } = require('path');
const { lockFile } = require('../Workers/file-lock');

// The updater prunes src/ but does not prune removed files at the installation root.
module.exports = async function retireRouter(root) {
  const router = join(root, 'router.php');
  let lock;
  try {
    await access(router);
    const updates = join(root, 'data/updates');
    await mkdir(updates, { recursive: true });
    lock = await open(join(updates, 'update.lock'), 'a+');
    await lockFile(lock, 0);
    // A downgrade removes this module. An interrupted swap must finish recovery first.
    await access(__filename);
    try { await access(join(root, 'data/runtime/maintenance.json')); return false; }
    catch (error) { if (error.code !== 'ENOENT') throw error; }
    const info = await lstat(router);
    if (!info.isFile() || info.size > 2048) return true;
    const source = (await readFile(router, 'utf8')).replace(/\r\n/g, '\n');
    if (createHash('sha256').update(source).digest('hex') ===
        '30e743ad337af4b5ef727780ae2b04fb3f24ead89b0d4ac225131ca38e89ccab') await unlink(router);
    return true;
  } catch (error) {
    if (error.code === 'ENOENT') return true;
    throw error;
  } finally {
    if (lock) await lock.close();
  }
};
