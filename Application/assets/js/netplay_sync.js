/* Vaultcast netplay frame-sync helpers: pure functions shared by app.js and the node tests.
   The host runs ahead and sends one input bucket per emulated frame; guests run a frame only
   once its bucket has arrived. */
(function(root){
  'use strict';
  // Bound host lead to avoid building a long input-to-display queue on guests.
  var ACK_WINDOW = 8;
  var BUFFER_FRAMES = 2;
  var ACK_EVERY = 1;
  var CHECK_INTERVAL = 600;

  function hostMayRun(currentFrame, players, selfId, acks, windowFrames){
    var w = windowFrames || ACK_WINDOW;
    var cur = currentFrame || 0;
    return Object.keys(players || {}).every(function(id){
      return id === selfId || cur - ((acks || {})[id] || 0) < w;
    });
  }

  /* Every bucket from lastSent+1 to currentFrame-1, oldest first. A core may advance its
     frame counter by more than one per main-loop tick (catch-up after a slow tick); sending
     only the newest bucket left guests waiting forever for the skipped frames. */
  function hostBucketsToSend(inputsData, lastSent, currentFrame){
    var out = [];
    var from = (typeof lastSent === 'number' ? lastSent : -1) + 1;
    for (var f = Math.max(0, from); f <= currentFrame - 1; f++) {
      var bucket = (inputsData || {})[f];
      if (bucket && bucket.length) bucket.forEach(function(v){ out.push(v); });
      else out.push({ frame: f });
    }
    return out;
  }

  /* Buckets safe to drop: host keeps anything not yet sent; guest keeps the last two. */
  function pruneBefore(isHost, currentFrame, lastSent){
    if (isHost) return Math.min(currentFrame - 2, (typeof lastSent === 'number' ? lastSent : -1) - 1);
    return currentFrame - 2;
  }

  // Ignore RetroArch container metadata and alignment padding; compare the core's
  // serialized memory, not achievements, replay bookkeeping or unused bytes.
  function coreState(bytes){
    if (!(bytes instanceof Uint8Array) || !bytes.length) throw new Error('Empty emulator state');
    if (bytes.length < 8 || String.fromCharCode.apply(null, bytes.subarray(0, 7)) !== 'RASTATE') return bytes;
    if (bytes[7] !== 1) throw new Error('Unsupported emulator state format');
    for (var offset = 8; offset + 8 <= bytes.length;) {
      var size = new DataView(bytes.buffer, bytes.byteOffset + offset + 4, 4).getUint32(0, true);
      var start = offset + 8;
      if (size > bytes.length - start) throw new Error('Truncated emulator state');
      if (String.fromCharCode.apply(null, bytes.subarray(offset, offset + 4)) === 'MEM ') {
        if (!size) throw new Error('Empty emulator core state');
        return bytes.subarray(start, start + size);
      }
      offset = start + Math.ceil(size / 8) * 8;
    }
    throw new Error('Emulator state has no core memory');
  }
  function stateHash(bytes){
    var core = coreState(bytes), a = 2166136261, b = 5381;
    for (var i = 0; i < core.length; i++) {
      a = Math.imul(a ^ core[i], 16777619);
      b = Math.imul(b, 33) ^ core[i];
    }
    return core.length + ':' + (a >>> 0).toString(16) + ':' + (b >>> 0).toString(16);
  }

  var api = { ACK_WINDOW: ACK_WINDOW, BUFFER_FRAMES:BUFFER_FRAMES, ACK_EVERY:ACK_EVERY, CHECK_INTERVAL:CHECK_INTERVAL, hostMayRun: hostMayRun, hostBucketsToSend: hostBucketsToSend, pruneBefore: pruneBefore, stateHash: stateHash };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.VaultcastNetplaySync = api;
})(this);
