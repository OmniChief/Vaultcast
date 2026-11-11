'use strict';

const { spawn } = require('child_process');
const { join } = require('path');
const { takeTicket } = require('./media-relay');
const pending = new Set();
let batch = new Set();

function drain(php, ini) {
  if (batch.size || !pending.size) return;
  batch = new Set(Array.from(pending).slice(0, 40));
  for (const item of batch) pending.delete(item);
  const args = ini ? ['-c', ini] : [];
  args.push(join(__dirname, '../Console.php'), 'quiz-warmup', JSON.stringify(Array.from(batch)));
  const child = spawn(php, args, { stdio: 'ignore' });
  child.once('error', () => {});
  child.once('close', () => {
    batch.clear();
    drain(php, ini);
  });
}

module.exports = async function warmup(req, res) {
  const ticket = await takeTicket(req.params.token, 'quiz-warmup').catch(() => null);
  if (!ticket || !Array.isArray(ticket.items) || typeof ticket.php !== 'string' || !ticket.php.startsWith('/')) return res.sendStatus(404);
  for (const item of ticket.items.slice(0, 40)) {
    if (typeof item === 'string' && item.length <= 128 && !batch.has(item) && pending.size < 80) pending.add(item);
  }
  drain(ticket.php, ticket.ini);
  return res.end('queued');
};
module.exports.active = () => batch.size > 0;
