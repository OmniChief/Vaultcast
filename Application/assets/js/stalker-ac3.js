(function () {
  'use strict';
  // Adapter for the pinned mpegts.js 1.7.3 MSE controller. Video remains in MSE;
  // unsupported Dolby audio fragments are decoded to PCM in a dedicated worker.
  window.VaultcastAc3Audio = function (video, player, options) {
    var mse = player._msectl, originalInit = mse.appendInitSegment, originalMedia = mse.appendMediaSegment;
    var active = false, closed = false, worker, context, gain, interval, packets = [], sources = [], scheduled = new Set();
    var pending = new Map(), pendingBytes = 0, pcmBytes = 0, nextId = 0, waiters = [], anchor;
    var frame = video.closest('#player-overlay') || video.parentElement;
    function notify() { var list = waiters; waiters = []; list.forEach(function (resolve) { resolve(); }); }
    function stopSources() {
      sources.forEach(function (source) { try { source.stop(); } catch (ignored) {} source.disconnect(); });
      sources = []; scheduled.clear(); anchor = null;
    }
    function releaseAudio() {
      active = false; clearInterval(interval); stopSources();
      if (worker) { worker.terminate(); worker = null; }
      if (context) { context.close().catch(function () {}); context = null; }
      packets = []; pending.clear(); pendingBytes = pcmBytes = 0; notify();
    }
    function fail(message) { if (!closed) options.error(new Error(message)); }
    function volume() { if (gain && context) gain.gain.setValueAtTime(video.muted ? 0 : video.volume, context.currentTime); }
    function onVolume() { volume(); resume(); }
    function resume() {
      if (!active || !context) return;
      if (context.state === 'suspended') context.resume().then(pump).catch(function () {});
      volume(); pump();
    }
    function reset() { stopSources(); pump(); }
    function pump() {
      if (!active || !context || closed) return;
      var time = video.currentTime || 0, rate = video.playbackRate || 1;
      packets = packets.filter(function (packet) {
        if (packet.time + packet.buffer.duration < time - 30) { pcmBytes -= packet.bytes; scheduled.delete(packet.id); return false; }
        return true;
      });
      notify();
      if (video.paused || video.seeking || video.readyState < 3 || context.state !== 'running') { stopSources(); return; }
      if (anchor && Math.abs(time - (anchor.video + (context.currentTime - anchor.audio) * rate)) > 0.12) stopSources();
      if (!anchor) anchor = { video: time, audio: context.currentTime };
      packets.forEach(function (packet) {
        if (scheduled.has(packet.id) || packet.time > time + 1 || packet.time + packet.buffer.duration <= time) return;
        var source = context.createBufferSource(), offset = Math.max(0, time - packet.time);
        source.buffer = packet.buffer; source.playbackRate.value = rate; source.connect(gain);
        scheduled.add(packet.id); sources.push(source);
        source.onended = function () { var index = sources.indexOf(source); if (index !== -1) sources.splice(index, 1); source.disconnect(); };
        source.start(context.currentTime + Math.max(0, (packet.time - time) / rate), offset);
      });
    }
    function activate() {
      if (active) return;
      var Audio = window.AudioContext || window.webkitAudioContext;
      if (!Audio || !window.Worker || !window.WebAssembly) { fail('This device cannot run the built-in AC-3 audio decoder.'); return; }
      active = true;
      try {
        context = new Audio(); gain = context.createGain();
        gain.channelCount = 2; gain.channelCountMode = 'explicit'; gain.channelInterpretation = 'speakers'; gain.connect(context.destination); volume();
        worker = new Worker(window.VaultcastDependencies.stalkerAc3Worker, { type: 'module' });
      } catch (error) { fail('The browser could not start the built-in AC-3 decoder.'); return; }
      worker.onerror = function () { fail('The built-in AC-3 audio decoder failed to load.'); };
      worker.onmessage = function (event) {
        if (closed || !active) return;
        var message = event.data;
        if (message.type === 'error') { fail(message.message); return; }
        if (message.type === 'ready') { resume(); return; }
        if (message.type !== 'pcm' || !pending.has(message.id)) return;
        pendingBytes -= pending.get(message.id); pending.delete(message.id);
        if (message.channels.length && message.channels[0].length) {
          if (!Number.isFinite(message.time) || !message.rate || message.channels.length > 8) { fail('Invalid decoded audio format.'); return; }
          var bytes = message.channels.reduce(function (n, c) { return n + c.byteLength; }, 0);
          while (packets.length && pcmBytes + bytes > 16777216 && packets[0].time + packets[0].buffer.duration < video.currentTime) { var oldest = packets.shift(); pcmBytes -= oldest.bytes; scheduled.delete(oldest.id); }
          if (pcmBytes + bytes > 16777216) { fail('The AC-3 audio buffer limit was reached. Reopen this channel to resynchronise.'); return; }
          var buffer = context.createBuffer(message.channels.length, message.channels[0].length, message.rate);
          message.channels.forEach(function (channel, i) { buffer.copyToChannel(channel, i); });
          packets.push({ id: message.id, time: message.time, buffer: buffer, bytes: bytes }); pcmBytes += bytes;
        }
        pump(); notify();
      };
      interval = setInterval(pump, 40); resume();
      if (options.note) options.note('AC-3 audio is decoded on this device.');
    }
    function audioData(segment) {
      var data = new Uint8Array(segment.data), view = new DataView(data.buffer, data.byteOffset, data.byteLength), offset = 0;
      while (offset + 8 <= data.length) {
        var size = view.getUint32(offset), header = 8;
        if (size === 1) { if (offset + 16 > data.length) break; size = view.getUint32(offset + 8) * 4294967296 + view.getUint32(offset + 12); header = 16; }
        if (!size) size = data.length - offset;
        if (size < header || size > data.length - offset) break;
        if (String.fromCharCode.apply(null, data.subarray(offset + 4, offset + 8)) === 'mdat') {
          var payload = data.slice(offset + header, offset + size), id = ++nextId, time = segment.info && segment.info.beginPts / 1000;
          if (!Number.isFinite(time) || pendingBytes + payload.length > 1048576) { fail('The live audio queue could not stay in sync. Reopen this channel.'); return; }
          pending.set(id, payload.length); pendingBytes += payload.length;
          worker.postMessage({ type: 'decode', id: id, time: time, data: payload.buffer }, [payload.buffer]);
          return;
        }
        offset += size;
      }
      fail('The player could not read the channel’s AC-3 audio fragment.');
    }
    mse.appendInitSegment = function (segment, deferred) {
      if (closed) return;
      if (segment.type === 'audio') {
        var dolby = /^(ac-3|ec-3)$/i.test(segment.codec || ''), Media = window.MediaSource || window.ManagedMediaSource;
        if (dolby && (!Media || !Media.isTypeSupported('audio/mp4;codecs="' + segment.codec + '"'))) { activate(); return; }
        if (active) releaseAudio();
      }
      return originalInit.call(mse, segment, deferred);
    };
    mse.appendMediaSegment = function (segment) {
      if (closed) return;
      if (active && segment.type === 'audio') { audioData(segment); return; }
      return originalMedia.call(mse, segment);
    };
    ['play', 'playing', 'canplay'].forEach(function (name) { video.addEventListener(name, resume); });
    ['pause', 'waiting', 'seeking', 'seeked', 'ratechange'].forEach(function (name) { video.addEventListener(name, reset); });
    video.addEventListener('volumechange', onVolume);
    // A real control interaction unlocks Web Audio on autoplay-restricted devices.
    frame.addEventListener('pointerdown', resume, true); frame.addEventListener('keydown', resume, true);
    return {
      wait: async function () {
        while (active && !closed && (pendingBytes > 131072 || packets.length && packets[packets.length - 1].time > video.currentTime + 8)) await new Promise(function (resolve) { waiters.push(resolve); });
      },
      destroy: function () {
        if (closed) return; closed = true; releaseAudio();
        mse.appendInitSegment = originalInit; mse.appendMediaSegment = originalMedia;
        ['play', 'playing', 'canplay'].forEach(function (name) { video.removeEventListener(name, resume); });
        ['pause', 'waiting', 'seeking', 'seeked', 'ratechange'].forEach(function (name) { video.removeEventListener(name, reset); });
        video.removeEventListener('volumechange', onVolume);
        frame.removeEventListener('pointerdown', resume, true); frame.removeEventListener('keydown', resume, true);
      }
    };
  };
}());
