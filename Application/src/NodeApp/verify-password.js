'use strict';

const { parentPort, workerData } = require('worker_threads');
const bcrypt = require(workerData.bcrypt);
const { password, hash } = workerData;
if (hash === undefined) {
  // PHP 7.4 PASSWORD_DEFAULT stores a bcrypt hash with the 2y prefix.
  parentPort.postMessage(bcrypt.hashSync(password, 10).replace(/^\$2b\$/, '$2y$'));
} else {
  parentPort.postMessage(bcrypt.compareSync(password, hash));
}
