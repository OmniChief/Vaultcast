'use strict';

const { spawn } = require('child_process');
const fs = require('fs').promises;

async function convert(job) {
  const log = await fs.open(job.log, 'w');
  try {
    const status = await new Promise((resolve, reject) => {
      const ffmpeg = spawn(job.ffmpeg, job.arguments, {
        stdio: ['ignore', log.fd, log.fd],
        windowsHide: true,
      });
      ffmpeg.once('error', reject);
      ffmpeg.once('close', code => resolve(code === null ? 1 : code));
    });
    if (status === 0) await fs.rename(job.part, job.dest);
    return status;
  } finally {
    await log.close();
  }
}

async function main() {
  const job = JSON.parse(Buffer.from(process.argv[2], 'base64').toString('utf8'));
  let status = 1;
  try {
    status = await convert(job);
  } catch (error) {
    await fs.appendFile(job.log, error.message + '\n').catch(() => {});
  }
  await fs.writeFile(job.exit, String(status) + '\n');
}

main().catch(error => {
  console.error(error.message);
  process.exitCode = 1;
});
