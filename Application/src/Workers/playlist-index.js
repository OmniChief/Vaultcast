'use strict';

const { createHash } = require('crypto');
const { createReadStream, createWriteStream } = require('fs');
const { pipeline } = require('stream/promises');

function trim(value) {
  return value.replace(/^[ \t\n\r\0\v]+|[ \t\n\r\0\v]+$/g, '');
}

const attributes = {
  'tvg-id': /tvg-id="([^"]*)"/,
  'tvg-logo': /tvg-logo="([^"]*)"/,
  'group-title': /group-title="([^"]*)"/,
  'http-user-agent': /http-user-agent="([^"]*)"/,
  'http-referrer': /http-referrer="([^"]*)"/,
  referrer: /referrer="([^"]*)"/,
};

function channelInfo(line) {
  const attribute = name => line.match(attributes[name])?.[1] || '';
  const comma = line.lastIndexOf(',');
  const name = comma < 0 ? '' : trim(line.slice(comma + 1));
  const tvgId = attribute('tvg-id');
  const separator = tvgId.indexOf('@');
  const channelId = separator < 0 ? tvgId : tvgId.slice(0, separator);
  const logo = attribute('tvg-logo');
  const group = attribute('group-title');
  const id = channelId || 'stream-' + createHash('sha1').update(name + logo).digest('hex').slice(0, 10);
  const country = (id.match(/\.([a-z]{2})$/i)?.[1] || '').toUpperCase();
  return {
    id,
    tvgId,
    feed: separator < 0 ? '' : tvgId.slice(separator + 1),
    name,
    logo,
    group,
    userAgent: attribute('http-user-agent') || null,
    referrer: attribute('http-referrer') || attribute('referrer') || null,
    url: '',
    quality: name.match(/\((\d+p|HD|SD|FHD|4K)\)/i)?.[1] || '',
    labels: Array.from(name.matchAll(/\[([^\]]+)\]/g), match => match[1]),
    country: country === 'UK' ? 'GB' : country,
    categories: group.split(';').map(trim).filter(label => label && label.toLowerCase() !== 'undefined'),
  };
}

async function* playlistLines(source) {
  let tail = '';
  for await (const chunk of createReadStream(source, { encoding: 'utf8' })) {
    const lines = (tail + chunk).split(/[\r\n]/);
    tail = lines.pop();
    if (tail.length > 1024 * 1024) throw new Error('Playlist line exceeds size limit');
    yield* lines;
  }
  if (tail) yield tail;
}

async function* playlistIndex(source, sig) {
  const builtAt = new Date().toISOString().replace(/\.\d{3}Z$/, '+00:00');
  yield '{"sig":' + JSON.stringify(sig) + ',"builtAt":' + JSON.stringify(builtAt) + ',"channels":[';
  const epgUrls = new Set();
  let pending = null;
  let first = true;
  for await (const value of playlistLines(source)) {
    const line = trim(value);
    if (!line) continue;
    if (/^#EXTM3U/i.test(line)) {
      const urls = line.match(/(?:url-tvg|x-tvg-url)="([^"]+)"/i)?.[1];
      if (urls) for (const url of urls.split(',').map(trim)) if (url) epgUrls.add(url);
    } else if (/^#EXTINF:/i.test(line)) {
      pending = channelInfo(line);
    } else if (line[0] !== '#' && pending) {
      pending.url = line;
      yield (first ? '' : ',') + JSON.stringify(pending);
      pending = null;
      first = false;
    }
  }
  yield '],"epgUrls":' + JSON.stringify([...epgUrls]) + '}';
}

const [source, destination, sig] = process.argv.slice(2);
pipeline(playlistIndex(source, sig), createWriteStream(destination, { flags: 'wx', mode: 0o600 }))
  .then(() => process.stdout.write('indexed\n'))
  .catch(() => { process.exitCode = 1; });
