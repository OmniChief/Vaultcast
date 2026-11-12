'use strict';

const { parentPort, workerData } = require('worker_threads');
const bcrypt = require(workerData.bcrypt);
parentPort.postMessage(bcrypt.compareSync(workerData.password, workerData.hash));
