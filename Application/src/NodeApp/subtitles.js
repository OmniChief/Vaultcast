'use strict';

const { constants } = require('fs');
const { mkdir, open, opendir, realpath, rename, stat, unlink, writeFile } = require('fs').promises;
const { basename, dirname, extname, join } = require('path');
const { TextDecoder } = require('util');
const { randomBytes } = require('crypto');
const { sha } = require('./account-data');

const maxBytes = 2097152;
const sortLabels = new Intl.Collator('en', { numeric: true, sensitivity: 'base' });

async function directoryEntries(path, visit) {
  let directory;
  try {
    directory = await opendir(path);
  } catch (error) {
    if (['ENOENT', 'ENOTDIR', 'EACCES', 'EPERM'].includes(error.code)) return;
    throw error;
  }
  for await (const entry of directory) {
    if (await visit(entry) === false) break;
  }
}

async function subtitleFiles(root, media) {
  const mediaPath = await realpath(media).catch(() => media);
  const store = join(root, 'data/subtitles', sha(mediaPath.replace(/\\/g, '/')));
  const folder = await realpath(dirname(media)).catch(() => null);
  const name = basename(media);
  const dot = name.lastIndexOf('.');
  const stem = dot < 0 ? name : name.slice(0, dot);
  const tracks = new Map();
  const add = async (entry, directory, extension, local) => {
    const path = await realpath(join(directory, entry.name)).catch(() => null);
    if (!path || dirname(path) !== directory) return;
    const info = await stat(path).catch(() => null);
    if (!info?.isFile() || info.size > maxBytes) return;
    const fileStem = entry.name.slice(0, -(extension.length + 1));
    const suffix = local ? fileStem.slice(stem.length).replace(/^\.+|\.+$/g, '') : fileStem;
    const language = local ? suffix.split(/[._-]+/, 1)[0].toLowerCase() : suffix.split('.', 1)[0];
    const id = sha((local ? '' : 'store/') + entry.name);
    tracks.set(id, { path, extension, id, label: suffix.replace(/[._]/g, ' ') || 'Subtitles',
      language: /^[a-z]{2,3}$/.test(language) ? language : '' });
    return local && tracks.size === 50 ? false : undefined;
  };

  if (folder) {
    const lower = stem.toLowerCase();
    await directoryEntries(folder, entry => {
      const extension = extname(entry.name).slice(1).toLowerCase();
      if (!['vtt', 'srt'].includes(extension)) return;
      const label = entry.name.slice(0, -(extension.length + 1)).toLowerCase();
      if (label === lower || label.startsWith(lower + '.')) return add(entry, folder, extension, true);
    });
  }
  const storeFolder = await realpath(store).catch(() => null);
  if (storeFolder === store) {
    await directoryEntries(storeFolder, entry => {
      if (extname(entry.name).toLowerCase() === '.vtt') return add(entry, storeFolder, 'vtt', false);
    });
  }
  return [...tracks.values()].sort((a, b) => sortLabels.compare(a.label, b.label));
}

async function subtitles(root, media, alternate) {
  const files = new Map((await subtitleFiles(root, media)).map(track => [track.id, track]));
  if (alternate && alternate !== media) {
    for (const track of await subtitleFiles(root, alternate)) {
      if (!files.has(track.id)) files.set(track.id, track);
    }
  }
  return files;
}

async function readSubtitle(track) {
  const file = await open(track.path, constants.O_RDONLY | (constants.O_NONBLOCK ?? 0) | (constants.O_NOFOLLOW ?? 0));
  try {
    const info = await file.stat();
    if (!info.isFile() || info.size > maxBytes) return null;
    const chunks = [];
    let length = 0;
    for await (const chunk of file.createReadStream({ start: 0, end: maxBytes, autoClose: false })) {
      chunks.push(chunk);
      length += chunk.length;
    }
    if (length > maxBytes) return null;
    const raw = Buffer.concat(chunks, length);
    return toVtt(decodeSubtitle(raw), track.extension);
  } finally {
    await file.close();
  }
}

function decodeSubtitle(raw) {
  let text;
  if (raw[0] === 255 && raw[1] === 254) text = new TextDecoder('utf-16le').decode(raw);
  else if (raw[0] === 254 && raw[1] === 255) text = new TextDecoder('utf-16be').decode(raw);
  else {
    try { text = new TextDecoder('utf-8', { fatal: true }).decode(raw); }
    catch (error) { text = new TextDecoder('windows-1252').decode(raw); }
  }
  return text.replace(/\r\n?/g, '\n').replace(/\0/g, '');
}

function subtitleFormat(text) {
  const head = text.slice(0, 65536);
  if (/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/.test(head)) return null;
  if (/^WEBVTT(?:[ \t\n]|$)/.test(head) && head.includes('-->')) return 'vtt';
  if (/^\s*\[Script Info\]/i.test(head) || (/^\[Events\]/mi.test(text) && /^Dialogue:/mi.test(text))) {
    return /^Dialogue:/mi.test(text) ? 'ass' : null;
  }
  return /^\s*\d+\s*\n\s*\d{1,3}:\d{2}:\d{2}[,.]\d{1,3}\s*-->\s*\d{1,3}:\d{2}:\d{2}[,.]\d{1,3}/m.test(head) ? 'srt' : null;
}

function assTime(text) {
  const match = text.match(/^\s*(\d+):(\d{1,2}):(\d{1,2})(?:[.,](\d{1,3}))?\s*$/);
  return match ? ((Number(match[1]) * 60 + Number(match[2])) * 60 + Number(match[3])) * 1000 +
    Number((match[4] ?? '').padEnd(3, '0')) : null;
}

function vttTime(value) {
  return [Math.floor(value / 3600000), Math.floor(value / 60000) % 60, Math.floor(value / 1000) % 60]
    .map(part => String(part).padStart(2, '0')).join(':') + '.' + String(value % 1000).padStart(3, '0');
}

function assToVtt(text) {
  let format = ['layer', 'start', 'end', 'style', 'name', 'marginl', 'marginr', 'marginv', 'effect', 'text'];
  let events = false;
  const cues = [];
  for (const raw of text.split('\n')) {
    const line = raw.trim();
    if (!line || line.startsWith(';')) continue;
    const section = line.match(/^\[(.+)\]$/);
    if (section) { events = section[1].toLowerCase() === 'events'; continue; }
    if (!events) continue;
    if (/^Format:/i.test(line)) {
      format = line.slice(7).split(',').map(field => field.trim().toLowerCase());
      continue;
    }
    if (!/^Dialogue:/i.test(line) || !format.length) continue;
    const parts = line.slice(9).trimStart().split(',');
    if (parts.length < format.length) continue;
    const cells = [...parts.slice(0, format.length - 1), parts.slice(format.length - 1).join(',')];
    const row = Object.fromEntries(format.map((field, index) => [field, cells[index]]));
    const start = assTime(row.start ?? ''), end = assTime(row.end ?? '');
    if (start == null || end == null || end <= start) continue;
    let body = row.text ?? '';
    if (/\{[^}]*\\p[1-9][^}]*\}/.test(body)) continue;
    body = body.replace(/\{[^}]*\}/g, '').replace(/\\[Nn]/g, '\n').replace(/\\h/g, ' ')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').trim().replace(/\n{2,}/g, '\n');
    if (body) cues.push({ start, end, body });
  }
  if (!cues.length) throw new Error('The ASS/SSA file has no dialogue lines.');
  cues.sort((left, right) => left.start - right.start);
  return 'WEBVTT\n' + cues.map(cue => '\n' + vttTime(cue.start) + ' --> ' + vttTime(cue.end) + '\n' + cue.body + '\n').join('');
}

function toVtt(text, extension) {
  if (extension === 'vtt') return /^WEBVTT(?:[ \t\n]|$)/.test(text) ? text : null;
  if (extension === 'ass' || extension === 'ssa') return assToVtt(text);
  const timing = /^[ \t]*(\d{1,3}):(\d{2}):(\d{2})[,.](\d{1,3})[ \t]*-->[ \t]*(\d{1,3}):(\d{2}):(\d{2})[,.](\d{1,3})[^\n]*$/gm;
  const body = text.replace(timing, (_line, ah, am, as, af, bh, bm, bs, bf) =>
    String(Number(ah)).padStart(2, '0') + ':' + am + ':' + as + '.' + af.padEnd(3, '0') + ' --> ' +
    String(Number(bh)).padStart(2, '0') + ':' + bm + ':' + bs + '.' + bf.padEnd(3, '0'));
  return 'WEBVTT\n\n' + body.trim() + '\n';
}

async function saveSubtitle(root, media, body, language, beforeWrite) {
  const directory = await realpath(dirname(media));
  const stem = basename(media, extname(media));
  const storeRoot = join(await realpath(root), 'data/subtitles');
  const mediaPath = await realpath(media);
  const store = join(storeRoot, sha(mediaPath.replace(/\\/g, '/')));
  const targets = [
    { directory, filename: stem + '.' + language + '.uploaded.vtt' },
    { directory: store, filename: language + '.uploaded.vtt' },
  ];
  for (const target of targets) {
    let temporary;
    try {
      if (target.directory === store) {
        await mkdir(storeRoot, { recursive: true, mode: 0o775 });
        if (await realpath(storeRoot) !== storeRoot) continue;
        await mkdir(store, { recursive: true, mode: 0o775 });
      }
      if (await realpath(target.directory) !== target.directory) continue;
      temporary = join(target.directory, '.' + target.filename + '.tmp-' + randomBytes(12).toString('hex'));
      await writeFile(temporary, body, { flag: 'wx', mode: 0o664 });
      beforeWrite();
      await rename(temporary, join(target.directory, target.filename));
      return true;
    } catch (error) {
      if (temporary) await unlink(temporary).catch(() => {});
      if (!['EACCES', 'EPERM', 'EROFS', 'ENOSPC', 'ENOENT', 'ENOTDIR', 'EEXIST', 'ENAMETOOLONG'].includes(error.code)) throw error;
    }
  }
  return false;
}

module.exports = { maxBytes, subtitles, readSubtitle, decodeSubtitle, subtitleFormat, toVtt, saveSubtitle };
