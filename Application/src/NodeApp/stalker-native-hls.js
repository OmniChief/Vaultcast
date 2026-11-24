'use strict';

// A deliberately limited, streaming HLS-to-MPEG-TS adapter for native TV
// players. Only ordinary, unencrypted MPEG-TS segments are supported. All
// URL requests go through the existing authenticated/pinned Stalker client.
const { once } = require('events');
const { setTimeout: sleep } = require('timers/promises');
const problem = (status, message) => Object.assign(new Error(message), { status, expose: true });
const unsupported = () => problem(415, 'This HLS source requires a format or encryption that native TV streaming cannot support.');
const MAX_PLAYLIST = 2 * 1024 * 1024;
const MAX_SEGMENT = 32 * 1024 * 1024;

function parse(body, base) {
  const lines = body.split(/\r?\n/).map(line => line.trim()).filter(Boolean);
  if (lines[0] !== '#EXTM3U' || lines.length > 12000) throw problem(502, 'Invalid provider playlist.');
  const variants = [], segments = [];
  let sequence = 0, target = 6, end = false, pending = null, duration = false;
  for (const line of lines.slice(1)) {
    if (line.startsWith('#EXT-X-STREAM-INF:')) {
      const attrs = line.slice(18);
      const bandwidth = Number(/(?:^|,)BANDWIDTH=(\d+)/.exec(attrs)?.[1]) || 0;
      const height = Number(/RESOLUTION=\d+x(\d+)/i.exec(attrs)?.[1]) || 0;
      const codecs = /CODECS="([^"]+)"/i.exec(attrs)?.[1] || '';
      pending = { bandwidth, height, codecs };
    } else if (line.startsWith('#EXT-X-MEDIA-SEQUENCE:')) {
      sequence = Number(line.slice(22));
      if (!Number.isSafeInteger(sequence) || sequence < 0) throw problem(502, 'Invalid provider sequence.');
    } else if (line.startsWith('#EXT-X-TARGETDURATION:')) {
      target = Math.max(2, Math.min(20, Number(line.slice(22)) || 6));
    } else if (line.startsWith('#EXT-X-KEY:') || line.startsWith('#EXT-X-SESSION-KEY:')) {
      if (!/(?:^|[:,])METHOD=NONE(?:,|$)/.test(line)) throw unsupported();
    } else if (line.startsWith('#EXT-X-MAP:') || line.startsWith('#EXT-X-BYTERANGE:') || line === '#EXT-X-GAP') {
      throw unsupported();
    } else if (line.startsWith('#EXTINF:')) {
      duration = true;
    } else if (line === '#EXT-X-ENDLIST') {
      end = true;
    } else if (!line.startsWith('#')) {
      if (line.includes('{$')) throw unsupported();
      const url = new URL(line, base).href;
      if (pending) {
        variants.push({ ...pending, url }); pending = null;
      } else if (duration) {
        segments.push({ url, sequence: sequence + segments.length }); duration = false;
      }
    }
  }
  return { variants, segments, target, end };
}

async function readPlaylist(fetch, url, signal) {
  signal.throwIfAborted();
  const { res, url: resolved } = await fetch(url);
  try {
    if (res.statusCode !== 200 || !/^(?:identity)?$/i.test(String(res.headers['content-encoding'] || '')))
      throw problem(502, 'The provider refused the TV playlist.');
    const chunks = [];
    let size = 0;
    for await (const chunk of res) {
      size += chunk.length;
      if (size > MAX_PLAYLIST) throw problem(502, 'Provider playlist exceeds the TV limit.');
      chunks.push(chunk);
    }
    return { body: Buffer.concat(chunks, size).toString(), url: resolved };
  } finally { res.destroy(); }
}

async function segment(fetch, url, response, signal) {
  signal.throwIfAborted();
  const { res } = await fetch(url);
  try {
    if (res.statusCode !== 200 || !/^(?:identity)?$/i.test(String(res.headers['content-encoding'] || '')))
      throw problem(502, 'The provider refused a TV stream segment.');
    let size = 0, head = [], verified = false;
    async function write(chunk) {
      signal.throwIfAborted();
      if (!response.write(chunk)) await once(response, 'drain', { signal });
    }
    for await (const chunk of res) {
      size += chunk.length;
      if (size > MAX_SEGMENT) throw problem(502, 'Provider segment exceeds the TV limit.');
      if (!verified) {
        head.push(chunk);
        if (size < 377) continue;
        const bytes = Buffer.concat(head, size);
        if (bytes[0] !== 0x47 || bytes[188] !== 0x47 || bytes[376] !== 0x47) throw unsupported();
        verified = true; head = [];
        await write(bytes);
      } else await write(chunk);
    }
    if (!verified) throw unsupported();
  } finally { res.destroy(); }
}

async function nativeHls(body, base, response, signal, fetch) {
  let list = parse(body, base), url = base.href;
  for (let depth = 0; list.variants.length; depth++) {
    if (depth >= 3) throw problem(502, 'Provider playlist nesting exceeds the TV limit.');
    // Prefer an ordinary AVC rendition within the capabilities of older TVs.
    const choices = list.variants.filter(v => (!v.height || v.height <= 1080) &&
      (!v.codecs || /avc[13]/i.test(v.codecs)));
    const chosen = choices.sort((a,b) => b.bandwidth - a.bandwidth)[0] ||
      list.variants.sort((a,b) => a.bandwidth - b.bandwidth)[0];
    const next = await readPlaylist(fetch, chosen.url, signal);
    url = next.url.href; list = parse(next.body, next.url);
  }
  if (!list.segments.length) throw problem(502, 'The provider has no playable TV segments.');
  // Live starts near the edge instead of replaying the whole manifest.
  let nextSequence = list.end ? list.segments[0].sequence : list.segments[Math.max(0,list.segments.length-3)].sequence;
  let lastProgress = Date.now();
  for (;;) {
    for (const part of list.segments) {
      if (part.sequence < nextSequence) continue;
      await segment(fetch, part.url, response, signal);
      nextSequence = part.sequence + 1; lastProgress = Date.now();
    }
    if (list.end) { response.end(); return; }
    if (Date.now() - lastProgress > 45000) throw problem(504, 'The provider TV playlist stopped updating.');
    await sleep(Math.round(list.target * 500), undefined, { signal });
    const next = await readPlaylist(fetch, url, signal);
    url = next.url.href; list = parse(next.body, next.url);
    if (list.variants.length) throw problem(502, 'The provider changed its TV playlist.');
  }
}

module.exports = { nativeHls };
