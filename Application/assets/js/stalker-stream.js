(function () {
  'use strict';
  var dependency, audioDependency;
  function loadAudioBridge() {
    if (window.VaultcastAc3Audio) return Promise.resolve();
    if (!audioDependency) audioDependency = new Promise(function (resolve, reject) {
      var script = document.createElement('script'); script.src = window.VaultcastDependencies.stalkerAc3;
      script.onload = function () { window.VaultcastAc3Audio ? resolve() : reject(new Error('The browser audio adapter is unavailable.')); };
      script.onerror = function () { audioDependency = null; script.remove(); reject(new Error('Could not load the browser audio adapter.')); };
      document.head.appendChild(script);
    });
    return audioDependency;
  }
  function loadTransport() {
    if (window.mpegts) return Promise.resolve(window.mpegts);
    if (!dependency) dependency = new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      script.src = window.VaultcastDependencies.mpegts;
      script.onload = function () { window.mpegts ? resolve(window.mpegts) : reject(new Error('MPEG-TS player unavailable.')); };
      script.onerror = function () { dependency = null; script.remove(); reject(new Error('Could not load the MPEG-TS player.')); };
      document.head.appendChild(script);
    });
    return dependency;
  }

  // Sniff and consume one request: a temporary link must not be probed, then reopened.
  window.attachStalkerStream = function (video, url, options, loadHls) {
    var controller = new AbortController(), reader, inner, audio, stopped = false, blob;
    var timer = setTimeout(function () { controller.abort(); }, 45000);
    function current() { return !stopped && options.current(); }
    function destroy() {
      if (stopped) return;
      stopped = true; clearTimeout(timer); controller.abort();
      if (reader) reader.cancel().catch(function () {});
      if (audio) { audio.destroy(); audio = null; }
      // Errors may be emitted from inside the parser's own call stack. Defer
      // disposal so it can finish without accessing an already-cleared emitter.
      if (inner) { var previous = inner; inner = null; setTimeout(function () { previous.destroy(); }, 0); }
      if (blob) URL.revokeObjectURL(blob);
      video.removeEventListener('canplay', play);
    }
    function play() { if (current()) options.play(); }
    options.stream({ destroy: destroy });
    function fail(error) {
      if (!current()) return;
      var message = error && error.name === 'AbortError' ? 'The live stream did not respond in time.' : (error.message || 'Live playback failed.');
      destroy(); options.error(message);
    }
    function tsPlayer(chunks) {
      return loadTransport().then(async function (M) {
        await loadAudioBridge();
        if (!current()) return;
        if (!M.isSupported()) throw new Error('This browser does not support MPEG-TS playback through MediaSource.');
        // This loader hands the already-open response to the transmuxer, including
        // every byte used for detection. No second provider connection is opened.
        class LiveLoader extends M.BaseLoader {
          constructor() { super('vaultcast-live'); this._needStash = true; this.cancelled = false; }
          open() {
            var self = this, received = 0;
            this._status = M.LoaderStatus.kBuffering;
            Promise.resolve().then(async function () {
              while (current() && !self.cancelled) {
                if (audio) await audio.wait();
                if (!current() || self.cancelled) return;
                var item = chunks.length ? { value: chunks.shift(), done: false } : await reader.read();
                if (item.done) {
                  self._status = M.LoaderStatus.kComplete;
                  if (self.onComplete) self.onComplete(0, received - 1);
                  return;
                }
                if (!current() || self.cancelled) return;
                var bytes = item.value, start = received; received += bytes.byteLength;
                if (self.onDataArrival) self.onDataArrival(bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength), start, received);
              }
            }).catch(function () {
              if (current() && !self.cancelled && self.onError) self.onError(M.LoaderErrors.EXCEPTION, { code: 0, msg: 'Live stream connection interrupted.' });
            });
          }
          abort() { this.cancelled = true; this._status = M.LoaderStatus.kIdle; controller.abort(); }
          destroy() { this.abort(); super.destroy(); }
        }
        inner = M.createPlayer({ type: 'mpegts', isLive: true, url: url }, {
          customLoader: LiveLoader, enableWorker: false, lazyLoad: false,
          autoCleanupSourceBuffer: true, autoCleanupMaxBackwardDuration: 30, autoCleanupMinBackwardDuration: 10
        });
        var audioCodec = '';
        inner.on(M.Events.MEDIA_INFO, function (info) { audioCodec = info.audioCodec || ''; });
        inner.on(M.Events.ERROR, function (type, detail, info) {
          var reason = audioCodec + ' ' + (info && (info.msg || info.message) || '');
          fail(new Error(/ac-3|ec-3|ac3|eac3/i.test(reason) ? 'This channel uses Dolby audio that this browser cannot decode. Try a browser or native player with Dolby audio support.' : 'The MPEG-TS stream could not be decoded. Its codecs may be unsupported by this browser.'));
        });
        video.addEventListener('canplay', play);
        inner.attachMediaElement(video);
        audio = window.VaultcastAc3Audio(video, inner, { error: fail, note: options.note });
        inner.load(); play();
        if (options.note) options.note('Live MPEG-TS stream');
      });
    }
    async function hlsPlayer(chunks) {
      var size = chunks.reduce(function (n, c) { return n + c.length; }, 0);
      while (current()) {
        var item = await reader.read();
        if (item.done) break;
        size += item.value.length;
        if (size > 5000000) throw new Error('Provider playlist is too large.');
        chunks.push(item.value);
      }
      if (!current()) return;
      var bytes = new Uint8Array(size), offset = 0;
      chunks.forEach(function (c) { bytes.set(c, offset); offset += c.length; });
      var manifest = new TextDecoder().decode(bytes), Hls = await loadHls();
      if (!current()) return;
      if (Hls && Hls.isSupported()) {
        var used = false, recovered = false;
        class ManifestLoader extends Hls.DefaultConfig.loader {
          load(context, config, callbacks) {
            if (context.url !== url || used) return super.load(context, config, callbacks);
            used = true;
            var stats = this.stats;
            stats.loading.start = stats.loading.first = stats.loading.end = performance.now();
            stats.loaded = stats.total = size;
            Promise.resolve().then(function () { if (current()) callbacks.onSuccess({ url: url, data: manifest }, stats, context, null); });
          }
        }
        inner = new Hls({ enableWorker: true, loader: ManifestLoader, fLoader: options.fragmentLoader(Hls, function () { return inner; }), manifestLoadingTimeOut: 45000, manifestLoadingMaxRetry: 2, levelLoadingTimeOut: 30000 });
        inner.on(Hls.Events.MANIFEST_PARSED, play);
        inner.on(Hls.Events.ERROR, function (event, data) {
          if (!data.fatal || !current()) return;
          if (data.type === Hls.ErrorTypes.MEDIA_ERROR && !recovered) { recovered = true; inner.recoverMediaError(); return; }
          fail(new Error('Live HLS playback failed (' + data.type + ' / ' + data.details + ').'));
        });
        inner.loadSource(url); inner.attachMedia(video);
      } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
        blob = URL.createObjectURL(new Blob([manifest], { type: 'application/vnd.apple.mpegurl' }));
        video.src = blob; play();
      } else throw new Error('This browser cannot play HLS streams.');
    }
    return fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal }).then(async function (response) {
      if (!response.ok) throw new Error('Live stream request failed: HTTP ' + response.status + (response.headers.get('X-Vaultcast-Error-Origin') === 'provider' ? ' from provider.' : '.'));
      if (!response.body || !response.body.getReader) throw new Error('This browser does not support streaming responses.');
      reader = response.body.getReader();
      var chunks = [], size = 0, prefix = '', type = response.headers.get('content-type') || '';
      while (current() && size < 564) {
        var item = await reader.read();
        if (item.done) break;
        chunks.push(item.value); size += item.value.length;
        prefix += new TextDecoder().decode(item.value.subarray(0, Math.max(0, 32 - prefix.length)));
        if (/^\s*#EXTM3U/.test(prefix)) break;
      }
      if (!current()) return;
      if (/mpegurl/i.test(type) || /^\s*#EXTM3U/.test(prefix)) return hlsPlayer(chunks);
      clearTimeout(timer);
      var sample = new Uint8Array(Math.min(size, 564)), copied = 0;
      chunks.forEach(function (c) { var part = c.subarray(0, sample.length - copied); sample.set(part, copied); copied += part.length; });
      if (/mp2t/i.test(type) || (sample.length > 188 && sample[0] === 71 && sample[188] === 71)) return tsPlayer(chunks);
      throw new Error('The provider returned an unsupported live-stream format.');
    }).then(function () { clearTimeout(timer); }).catch(fail);
  };
}());
