'use strict';

const { readFile, writeFile } = require('fs').promises;
const { sha } = require('./account-data');

const stableId = (feed, channel) => 'stalker-' + sha(feed + ':' + channel).slice(0, 20);
function millis(value) {
  return value != null && String(value).trim() && Number.isFinite(Number(value))
    ? Math.trunc(Number(value)) * 1000
    : Date.parse(String(value));
}

function lineupGuide(guide, feed, selection) {
  const out = Object.create(null),
    keys = new Map();
  for (const [channel, rows] of Object.entries(guide ?? {})) {
    if (!rows || typeof rows !== 'object') continue;
    for (const row of Object.values(rows)) {
      if (!row || typeof row !== 'object') continue;
      const id = String(row.ch_id ?? channel);
      if (!keys.has(id)) {
        const key = stableId(feed, id);
        keys.set(id, !selection || selection.has(key) ? key : null);
      }
      const key = keys.get(id);
      if (!key) continue;
      const startUnix = millis(row.start_timestamp ?? row.time),
        endUnix = millis(row.stop_timestamp ?? row.time_to);
      if (!Number.isFinite(startUnix) || !startUnix || !Number.isFinite(endUnix) || endUnix <= startUnix)
        continue;
      (out[key] ??= []).push({
        startUnix,
        endUnix,
        title: String(row.name ?? row.title ?? ''),
        subtitle: String(row.descr ?? ''),
        kind: 'epg',
      });
    }
  }
  return out;
}

async function main() {
  const [snapshot, feed, exchange, timezone] = process.argv.slice(2);
  process.env.TZ = timezone;
  const wanted = JSON.parse(await readFile(exchange, 'utf8'));
  const { guide } = JSON.parse(await readFile(snapshot, 'utf8'));
  const result = lineupGuide(guide, feed, wanted === null ? null : new Set(wanted));
  await writeFile(exchange, JSON.stringify(result));
  process.stdout.write('indexed\n');
}
if (require.main === module)
  main().catch(() => {
    process.exitCode = 1;
  });
module.exports = { stableId, lineupGuide };
