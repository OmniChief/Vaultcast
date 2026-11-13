'use strict';

const { constants } = require('fs');
const { open, opendir, realpath, stat } = require('fs').promises;
const { basename, dirname, extname, join } = require('path');
const { TextDecoder } = require('util');
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
    let text;
    if (raw[0] === 255 && raw[1] === 254) text = new TextDecoder('utf-16le').decode(raw);
    else if (raw[0] === 254 && raw[1] === 255) text = new TextDecoder('utf-16be').decode(raw);
    else {
      try { text = new TextDecoder('utf-8', { fatal: true }).decode(raw); }
      catch (error) { text = new TextDecoder('windows-1252').decode(raw); }
    }
    text = text.replace(/\r\n?/g, '\n').replace(/\0/g, '');
    if (track.extension === 'vtt') return /^WEBVTT(?:[ \t\n]|$)/.test(text) ? text : null;
    const timing = /^[ \t]*(\d{1,3}):(\d{2}):(\d{2})[,.](\d{1,3})[ \t]*-->[ \t]*(\d{1,3}):(\d{2}):(\d{2})[,.](\d{1,3})[^\n]*$/gm;
    text = text.replace(timing, (_line, ah, am, as, af, bh, bm, bs, bf) =>
      String(Number(ah)).padStart(2, '0') + ':' + am + ':' + as + '.' + af.padEnd(3, '0') + ' --> ' +
      String(Number(bh)).padStart(2, '0') + ':' + bm + ':' + bs + '.' + bf.padEnd(3, '0'));
    return 'WEBVTT\n\n' + text.trim() + '\n';
  } finally {
    await file.close();
  }
}

module.exports = { subtitles, readSubtitle };
