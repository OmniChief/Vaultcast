'use strict';

const { execFile, spawn } = require('child_process');
const { promisify } = require('util');
const { isAbsolute, relative, sep } = require('path');
const { realpath, stat } = require('fs').promises;
const { pipeline } = require('stream/promises');
const { once } = require('events');
const { availableParallelism } = require('os');
const { cached, cacheResult } = require('./profile-policy');
const { sha, enabled } = require('./account-data');

const capture = promisify(execFile);
const pending = new Map(), remuxes = new Map();
const compatibleAudio = codec => ['aac', 'mp3', 'opus', 'flac'].includes(String(codec).toLowerCase());

async function libraryFile(file, folder) {
  if (typeof file !== 'string' || !isAbsolute(file)) return null;
  const [resolved, directory] = await Promise.all([realpath(file), realpath(folder)]);
  const child = relative(directory, resolved);
  return child && child !== '..' && !child.startsWith('..' + sep) && !isAbsolute(child) ? resolved : null;
}

async function probeOutput(root, file, settings, kind, args, parse) {
  const info = await stat(file);
  const key = kind + '|' + sha([file, Math.floor(info.mtimeMs / 1000), info.size, ...args].join('|'));
  const hit = await cached(root, key);
  if (hit) return { value: hit, cached: true };
  if (!pending.has(key)) {
    if (pending.size >= 8) throw Object.assign(new Error('Media probe busy'), { status: 503 });
    const work = capture(settings.tools?.ffprobe || 'ffprobe',
      ['-v', 'error', '-protocol_whitelist', 'file,pipe', ...args, file],
      { timeout: 5000, killSignal: 'SIGKILL', maxBuffer: 4 * 1024 * 1024, windowsHide: true }).then(async ({ stdout }) => {
        const value = parse(stdout);
        await cacheResult(root, key, value, 86400);
        return value;
      }).catch(() => null).finally(() => pending.delete(key));
    pending.set(key, work);
  }
  return { value: await pending.get(key), cached: false };
}

async function probe(root, file, settings) {
  const { value, cached } = file ? await probeOutput(root, file, settings, 'library-probe',
    ['-print_format', 'json', '-show_streams', '-show_format'], JSON.parse) : {};
  const streams = Array.isArray(value?.streams) ? value.streams : [];
  const tracks = streams.filter(s => s.codec_type === 'audio').map((s, relative) => ({
    index: Number(s.index ?? relative), relative, codec: s.codec_name ?? null, channels: s.channels ?? null,
    language: s.tags?.language ?? s.tags?.LANGUAGE ?? null, title: s.tags?.title ?? s.tags?.TITLE ?? null,
    default: enabled(s.disposition?.default),
  }));
  const video = streams.find(s => s.codec_type === 'video');
  const duration = Number(value?.format?.duration);
  return { ok: true, path_exists: Boolean(file), audio_tracks: tracks, audio_count: tracks.length,
    multi_audio: tracks.length > 1, video_codec: video?.codec_name ?? null, pixel_format: video?.pix_fmt ?? null,
    duration: Number.isFinite(duration) && duration > 0 ? duration : null, probed: Array.isArray(value?.streams), cached: !!cached };
}

async function seekPlan(root, file, settings, target) {
  if (!target) return { start: 0, seek: 0 };
  const interval = Math.max(0, target - 20).toFixed(3) + '%' + (target + 10).toFixed(3);
  const { value } = await probeOutput(root, file, settings, 'remux-keyframes', ['-select_streams', 'v:0',
    '-skip_frame', 'nokey', '-read_intervals', interval, '-show_entries', 'frame=pts_time,best_effort_timestamp_time',
    '-of', 'csv=p=0'], text => text.split(/\r?\n/).map(line => line.split(',').find(v => v.trim() && Number.isFinite(Number(v))))
      .filter(v => v != null).map(Number).sort((a, b) => a - b));
  const start = value?.filter(t => t <= target + 0.001).pop();
  if (start == null) return { start: target, seek: target };
  const next = value.find(t => t > target + 0.001);
  return { start: Math.max(0, start), seek: start <= 0 ? 0 : start + Math.min(0.25, Math.max(0.01, ((next ?? start + 0.5) - start) / 2)) };
}

async function remux(root, file, settings, request, response, token) {
  if (settings.streaming?.directplay_only !== false || settings.streaming.allow_remux === false)
    return response.status(403).json({ ok: false, error: 'Audio remuxing is disabled in DirectPlay Settings.' });
  const start = Math.min(604800, Math.max(0, Number(request.query.ss) || 0));
  const data = await probe(root, file, settings);
  if (enabled(request.query.info)) return response.json({ ok: true, ...await seekPlan(root, file, settings, start), duration: data.duration ?? 0 });
  const audio = String(request.query.a ?? '0');
  if (!/^\d{1,3}$/.test(audio) || Number(audio) >= Math.max(1, data.audio_count)) return response.status(400).send('Unknown audio track');
  response.set({ 'Content-Type': 'video/mp4', 'X-Accel-Buffering': 'no', 'Accept-Ranges': 'none' });
  if (request.method === 'HEAD' || response.destroyed) return response.end();
  const previous = remuxes.get(token);
  if (!previous && remuxes.size >= 16) return response.sendStatus(503);
  previous?.kill('SIGKILL');
  const config = settings.transcoding ?? {};
  const threads = String(Math.min(availableParallelism(), Math.max(1, Math.floor(Number(config.threads) || 1))));
  const args = ['-hide_banner', '-loglevel', 'error', '-nostdin', '-threads', threads, '-protocol_whitelist', 'file,pipe'];
  if (start) args.push('-noaccurate_seek', '-ss', start.toFixed(3));
  args.push('-i', file, '-map', '0:v:0?', '-map', '0:a:' + Number(audio), '-c:v', 'copy');
  if (compatibleAudio(data.audio_tracks[Number(audio)]?.codec)) args.push('-c:a', 'copy');
  else {
    args.push('-af', start ? 'aresample=async=1' : 'aresample=async=1:first_pts=0', '-c:a', 'aac', '-b:a', config.audio_bitrate || '192k');
    if ((config.audio_channels ?? '2') === '2') args.push('-ac', '2');
  }
  args.push('-threads:a', threads, '-sn', '-dn', '-map_metadata', '-1', '-f', 'mp4',
    '-movflags', 'frag_keyframe+empty_moov+default_base_moof', '-frag_duration', '1000000', 'pipe:1');
  const child = spawn(settings.tools?.ffmpeg || 'ffmpeg', args, { windowsHide: true, stdio: ['ignore', 'pipe', 'ignore'] });
  remuxes.set(token, child);
  const stop = () => child.kill('SIGKILL');
  response.once('close', stop);
  try {
    const finished = once(child, 'close');
    await Promise.all([finished.then(([code]) => { if (code !== 0) throw new Error('FFmpeg stopped'); }),
      pipeline(child.stdout, response)]);
  } finally {
    stop();
    response.removeListener('close', stop);
    if (remuxes.get(token) === child) remuxes.delete(token);
  }
}

module.exports = { probe, remux, compatibleAudio, libraryFile };
