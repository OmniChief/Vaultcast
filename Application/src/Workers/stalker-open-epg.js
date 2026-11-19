'use strict';

const { createReadStream, promises: fs } = require('fs');
const { join } = require('path');
const { isIP } = require('net');
const { TextDecoder } = require('util');
const sax = require('./vendor/sax');
const { scanHeader } = require('./epg-channels');
const { guideNameMaps } = require('../NodeApp/stalker-guide');
const { htmlDecoder } = require('../NodeApp/dependencies');
const { publicAddresses } = require('../NodeApp/stalker-provider');

const maxIndex = 16 * 1024 * 1024;
const slice = (value, limit) => Array.from(value).slice(0, limit).join('');
const unsupported = () =>
  Object.assign(new Error('Use the compatibility XMLTV parser.'), { compatibility: true });

function timestamp(value) {
  if (!value) return null;
  const parts = /^(\d{4})(\d\d)(\d\d)(\d\d)(\d\d)(\d\d)\s*([+-]\d{4}|[A-Z]+)?$/.exec(value.trim());
  if (!parts) throw unsupported();
  const [, year, month, day, hour, minute, second, zone = '+0000'] = parts;
  if (!/^(?:UTC|GMT|Z|[+-]\d{4})$/.test(zone)) throw unsupported();
  const offset = /^[+-]/.test(zone)
    ? (Number(zone.slice(1, 3)) * 60 + Number(zone.slice(3))) * (zone[0] === '-' ? -1 : 1)
    : 0;
  return Date.UTC(+year, +month - 1, +day, +hour, +minute, +second) - offset * 60000;
}

async function artwork(value) {
  if (!value || value.length > 500 || !/^https:\/\/[a-z0-9.-]+(?::443)?\/[a-z0-9_./%+@-]+$/i.test(value))
    return '';
  try {
    const url = new URL(value);
    if (!url.hostname.includes('.') || url.hostname.endsWith('.local') || url.hostname.endsWith('.localhost'))
      return '';
    if (isIP(url.hostname)) await publicAddresses(url);
    return value;
  } catch {
    return '';
  }
}

const fields = new Map(
  Object.entries({
    title: ['title', 180],
    desc: ['description', 5000],
    'sub-title': ['episodeTitle', 180],
    language: ['language', 60],
    category: ['categories', 100, 8],
    'rating/value': ['rating', 40],
    'credits/actor': ['cast', 120, 20],
    'credits/director': ['director', 120, 20],
    'credits/presenter': ['presenter', 120, 20],
    'episode-num': ['number', 100],
  }),
);

async function programmes(path, ids, from, to, source, decode) {
  const schedule = Object.create(null),
    stack = [],
    seen = new Map(),
    sizes = new WeakMap();
  let retainedBytes = 0,
    perChannel = 60;
  function trim(list) {
    list.sort((a, b) => a.start - b.start);
    for (const removed of list.splice(perChannel)) retainedBytes -= sizes.get(removed);
  }
  function retain(row, channels) {
    const size = Buffer.byteLength(JSON.stringify(row)) + 1;
    sizes.set(row, size);
    for (const id of channels) {
      seen.set(id, (seen.get(id) ?? 0) + 1);
      const list = (schedule[id] ??= []);
      list.push(row);
      retainedBytes += size;
      if (list.length > perChannel) trim(list);
    }
    // Reduce all channels together so a large region cannot crowd out smaller stations.
    if (retainedBytes > maxIndex * 1.5 && perChannel >= 15) {
      perChannel = Math.floor(perChannel / 2);
      for (const list of Object.values(schedule)) trim(list);
    }
  }
  const clean = (value, limit) => slice(decode(value.replace(/<[^>]*>/g, '')).trim(), limit);
  const parser = sax.parser(true, { strictEntities: true, position: false });
  const decoder = new TextDecoder('utf-8', { fatal: true });
  let event,
    destinations,
    capture,
    captured = '',
    used = 0,
    bytes = 0;
  parser.onerror = (error) => {
    throw error;
  };
  parser.onopentag = (tag) => {
    const name = tag.name.slice(tag.name.lastIndexOf(':') + 1);
    stack.push(name);
    if (stack.length > 64) throw new Error('XMLTV nesting limit exceeded.');
    if (stack.length === 2 && name === 'programme') {
      const id = tag.attributes.channel || '',
        base = id.split('@', 1)[0];
      destinations = [...new Set([id, base])].filter((key) => ids.has(key) && (seen.get(key) ?? 0) < 60);
      if (!destinations.length) return;
      const start = timestamp(tag.attributes.start);
      if (start == null) return;
      const end = timestamp(tag.attributes.stop) ?? start + 3600000;
      if (end <= start || end < from || start > to) return;
      event = { start: start / 1000, end: end / 1000, source };
    } else if (event) {
      const key = stack.slice(2).join('/');
      if (key === 'subtitles') event.subtitles = true;
      if (key === 'rating' && event.ratingSystem == null)
        event.ratingSystem = clean(tag.attributes.system || '', 40);
      if (key === 'icon' && !event.icon) event.icon = (tag.attributes.src || '').slice(0, 501);
      const field = fields.get(key);
      if (!field || (key === 'episode-num' && tag.attributes.system !== 'xmltv_ns')) return;
      if (field[2] ? event[field[0]]?.length >= field[2] : event[field[0]] != null) return;
      capture = { field, depth: stack.length };
      captured = '';
    }
  };
  parser.ontext = parser.oncdata = (text) => {
    if (capture && stack.length === capture.depth && captured.length < 20000)
      captured += text.slice(0, 20000 - captured.length);
  };
  parser.onclosetag = () => {
    if (capture && stack.length === capture.depth) {
      const [key, limit, count] = capture.field;
      const value = clean(captured, limit);
      if (value && count) {
        const list = (event[key] ??= []);
        if (!list.includes(value)) list.push(value);
      } else if (value) event[key] = value;
      capture = null;
    }
    if (stack.length === 2 && event) {
      const { number, episodeTitle, icon, ...row } = event;
      const label = (number || '')
        .split('.')
        .slice(0, 2)
        .map((part, i) =>
          /^\d+$/.test(part.split('/')[0].trim()) ? (i ? 'E' : 'S') + (parseInt(part, 10) + 1) : '',
        )
        .filter(Boolean)
        .join(' ');
      if (episodeTitle || label) row.episode = [label, episodeTitle].filter(Boolean).join(' · ');
      if (!row.rating) delete row.ratingSystem;
      row.title ||= 'Programme';
      row.description ||= '';
      if (icon) row.poster = icon;
      retain(row, destinations);
      event = null;
    }
    stack.pop();
  };
  for await (const chunk of createReadStream(path)) {
    bytes += chunk.length;
    if (bytes > 256 * 1024 * 1024) throw new Error('XMLTV file exceeds size limit.');
    parser.write(decoder.decode(chunk, { stream: true }));
  }
  parser.write(decoder.decode()).close();
  for (const list of Object.values(schedule)) {
    for (const row of list) {
      if (row.poster) {
        const url = await artwork(row.poster);
        if (url) row.poster = url;
        else delete row.poster;
      }
    }
    used += list.length;
  }
  if (!used) throw new Error('No programmes found in the current 48-hour guide window.');
  return schedule;
}

async function main() {
  const [path, temporary, region, from, to] = process.argv.slice(2);
  if (
    !['GB', 'GB-freeview', 'US'].includes(region) ||
    !Number.isSafeInteger(+from) ||
    !Number.isSafeInteger(+to) ||
    +from >= +to
  )
    throw new Error('Invalid guide selection.');
  const decode = await htmlDecoder(join(__dirname, '../..')).catch(() => {
    throw unsupported();
  });
  const rows = new Map();
  await scanHeader(path, (channel) => {
    const id = channel.id.trim();
    if (id && Buffer.byteLength(id) <= 160 && !rows.has(id)) rows.set(id, channel);
    return rows.size < 1000;
  });
  if (!rows.size) throw new Error('No channels found in the XMLTV guide.');
  const channels = Object.create(null),
    logos = Object.create(null);
  for (const [id, channel] of rows) {
    const label = (channel.name || '').trim();
    if (label) channels[id] = slice(label, 160);
    const logo = await artwork((channel.icon || '').trim());
    if (logo) logos[id] = logo;
  }
  const source = region === 'GB-freeview' ? 'freeview' : 'open-epg';
  const schedule = await programmes(path, rows, +from, +to, source, decode);
  const updatedAt = Math.floor(Date.now() / 1000);
  const index = { schema: 2, updatedAt, channels, logos, programmes: schedule };
  // Size the serialized lists separately to avoid allocating an oversized combined payload.
  const lists = Object.entries(schedule);
  const size = () =>
    Buffer.byteLength(JSON.stringify({ ...index, programmes: {} })) +
    lists.reduce(
      (total, [id, events]) =>
        total + Buffer.byteLength(JSON.stringify(id)) + Buffer.byteLength(JSON.stringify(events)) + 2,
      0,
    );
  for (let limit = 30; size() > maxIndex; limit = Math.floor(limit / 2)) {
    if (limit < 6) throw new Error('Stalker guide index exceeds the 16 MiB cache limit.');
    for (const [, events] of lists) events.sort((a, b) => a.start - b.start).splice(limit);
  }
  await fs.writeFile(temporary, JSON.stringify(index), { flag: 'wx', mode: 0o600 });
  const offsets = Object.create(null),
    lines = [];
  let position = 0,
    total = 0;
  for (const [id, events] of lists) {
    offsets[id] = position;
    const line = JSON.stringify(events) + '\n';
    lines.push(line);
    position += Buffer.byteLength(line);
    total += events.length;
  }
  const header = JSON.stringify({ updatedAt, offsets, logos, ...guideNameMaps(channels, schedule, decode) });
  if (Buffer.byteLength(header) <= 2 * 1024 * 1024) {
    lines.push(header, String(position).padStart(12, '0'));
    await fs.writeFile(temporary + '.lookup', lines.join(''), { flag: 'wx', mode: 0o600 });
  }
  process.stdout.write(
    JSON.stringify({
      country: region.slice(0, 2),
      channelsInXmltv: rows.size,
      channelsWithProgrammes: lists.length,
      programmes: total,
      updatedAt,
    }),
  );
}
main().catch((error) => {
  if (error.compatibility || error.message === 'Unsupported XMLTV encoding')
    process.stdout.write('compatibility');
  else process.exitCode = 1;
});
