/* Vaultcast QR codes: a small self-contained encoder (byte mode, error correction M, versions 1–15).
   Used for quiz room codes and Drop share links; no network service and no dependency. */
(function(root){
  'use strict';
  // [ec codewords per block, [blocks, data codewords] ...] for level M
  var BLOCKS = [null,[10,[1,16]],[16,[1,28]],[26,[1,44]],[18,[2,32]],[24,[2,43]],[16,[4,27]],[18,[4,31]],[22,[2,38],[2,39]],[22,[3,36],[2,37]],
    [26,[4,43],[1,44]],[30,[1,50],[4,51]],[22,[6,36],[2,37]],[22,[8,37],[1,38]],[24,[4,40],[5,41]],[24,[5,41],[5,42]]];
  var ALIGN = [null,[],[6,18],[6,22],[6,26],[6,30],[6,34],[6,22,38],[6,24,42],[6,26,46],[6,28,50],[6,30,54],[6,32,58],[6,34,62],[6,26,46,66],[6,26,48,70]];
  function capacity(v){ var b = BLOCKS[v], n = 0; for (var i = 1; i < b.length; i++) n += b[i][0] * b[i][1]; return n; }
  function utf8(text){ var out = [], s = unescape(encodeURIComponent(String(text))); for (var i = 0; i < s.length; i++) out.push(s.charCodeAt(i)); return out; }
  function mul(x, y){ var z = 0; for (var i = 7; i >= 0; i--) { z = (z << 1) ^ ((z >>> 7) * 0x11D); z ^= ((y >>> i) & 1) * x; } return z & 255; }
  function divisor(degree){
    var r = []; for (var i = 0; i < degree; i++) r.push(0); r[degree - 1] = 1; var root = 1;
    for (i = 0; i < degree; i++) { for (var j = 0; j < degree; j++) { r[j] = mul(r[j], root); if (j + 1 < degree) r[j] ^= r[j + 1]; } root = mul(root, 2); }
    return r;
  }
  function remainder(data, div){
    var r = div.map(function(){ return 0; });
    data.forEach(function(b){ var f = b ^ r.shift(); r.push(0); for (var i = 0; i < r.length; i++) r[i] ^= mul(div[i], f); });
    return r;
  }
  function encode(text){
    var bytes = utf8(text), v;
    for (v = 1; v <= 15; v++) if (4 + (v < 10 ? 8 : 16) + bytes.length * 8 <= capacity(v) * 8) break;
    if (v > 15) throw new Error('Text too long for a QR code');
    var bits = [];
    function put(val, len){ for (var i = len - 1; i >= 0; i--) bits.push((val >>> i) & 1); }
    put(4, 4); put(bytes.length, v < 10 ? 8 : 16); bytes.forEach(function(b){ put(b, 8); });
    var cap = capacity(v) * 8;
    put(0, Math.min(4, cap - bits.length)); while (bits.length % 8) bits.push(0);
    var data = []; for (var i = 0; i < bits.length; i += 8) { var b = 0; for (var j = 0; j < 8; j++) b = (b << 1) | bits[i + j]; data.push(b); }
    for (var pad = 0xEC; data.length < capacity(v); pad ^= 0xEC ^ 0x11) data.push(pad);
    // split into blocks, add error correction, interleave
    var spec = BLOCKS[v], ec = spec[0], div = divisor(ec), blocks = [], k = 0;
    for (i = 1; i < spec.length; i++) for (j = 0; j < spec[i][0]; j++) { var d = data.slice(k, k + spec[i][1]); k += spec[i][1]; blocks.push({ d:d, e:remainder(d, div) }); }
    var out = [], max = 0; blocks.forEach(function(bl){ max = Math.max(max, bl.d.length); });
    for (i = 0; i < max; i++) blocks.forEach(function(bl){ if (i < bl.d.length) out.push(bl.d[i]); });
    for (i = 0; i < ec; i++) blocks.forEach(function(bl){ out.push(bl.e[i]); });
    return matrix(v, out);
  }
  function matrix(v, words){
    var size = v * 4 + 17, m = [], fn = [], x, y, i;
    for (y = 0; y < size; y++) { m.push([]); fn.push([]); for (x = 0; x < size; x++) { m[y].push(false); fn[y].push(false); } }
    function set(x, y, dark){ m[y][x] = !!dark; fn[y][x] = true; }
    for (i = 0; i < size; i++) { set(6, i, i % 2 === 0); set(i, 6, i % 2 === 0); }
    [[3,3],[size-4,3],[3,size-4]].forEach(function(c){
      for (var dy = -4; dy <= 4; dy++) for (var dx = -4; dx <= 4; dx++) { var xx = c[0] + dx, yy = c[1] + dy; if (xx < 0 || yy < 0 || xx >= size || yy >= size) continue; var dist = Math.max(Math.abs(dx), Math.abs(dy)); set(xx, yy, dist !== 2 && dist !== 4); }
    });
    var al = ALIGN[v], n = al.length;
    for (i = 0; i < n; i++) for (var j = 0; j < n; j++) {
      if ((i === 0 && j === 0) || (i === 0 && j === n - 1) || (i === n - 1 && j === 0)) continue;
      for (var ay = -2; ay <= 2; ay++) for (var ax = -2; ax <= 2; ax++) set(al[i] + ax, al[j] + ay, Math.max(Math.abs(ax), Math.abs(ay)) !== 1);
    }
    function format(mask){
      var data = (0 << 3) | mask, rem = data; for (var i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
      var bits = ((data << 10) | rem) ^ 0x5412; function bit(i){ return ((bits >>> i) & 1) !== 0; }
      for (i = 0; i <= 5; i++) set(8, i, bit(i));
      set(8, 7, bit(6)); set(8, 8, bit(7)); set(7, 8, bit(8));
      for (i = 9; i < 15; i++) set(14 - i, 8, bit(i));
      for (i = 0; i < 8; i++) set(size - 1 - i, 8, bit(i));
      for (i = 8; i < 15; i++) set(8, size - 15 + i, bit(i));
      set(8, size - 8, true);
    }
    format(0);
    if (v >= 7) {
      var rem = v; for (i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1F25);
      var vb = (v << 12) | rem;
      for (i = 0; i < 18; i++) { var bt = ((vb >>> i) & 1) !== 0, a = size - 11 + i % 3, b = Math.floor(i / 3); set(a, b, bt); set(b, a, bt); }
    }
    // data
    var bi = 0, total = words.length * 8;
    for (var right = size - 1; right >= 1; right -= 2) {
      if (right === 6) right = 5;
      for (var vert = 0; vert < size; vert++) for (var jj = 0; jj < 2; jj++) {
        x = right - jj; var up = ((right + 1) & 2) === 0; y = up ? size - 1 - vert : vert;
        if (!fn[y][x] && bi < total) { m[y][x] = ((words[bi >>> 3] >>> (7 - (bi & 7))) & 1) !== 0; bi++; }
      }
    }
    function maskFn(k, x, y){
      switch (k) { case 0: return (x + y) % 2 === 0; case 1: return y % 2 === 0; case 2: return x % 3 === 0; case 3: return (x + y) % 3 === 0;
        case 4: return (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0; case 5: return x * y % 2 + x * y % 3 === 0; case 6: return (x * y % 2 + x * y % 3) % 2 === 0; default: return ((x + y) % 2 + x * y % 3) % 2 === 0; }
    }
    function apply(k){ for (var y = 0; y < size; y++) for (var x = 0; x < size; x++) if (!fn[y][x] && maskFn(k, x, y)) m[y][x] = !m[y][x]; }
    function penalty(){
      var p = 0, x, y, dark = 0;
      for (y = 0; y < size; y++) { var run = 1; for (x = 1; x < size; x++) { if (m[y][x] === m[y][x-1]) { run++; if (run === 5) p += 3; else if (run > 5) p++; } else run = 1; } }
      for (x = 0; x < size; x++) { run = 1; for (y = 1; y < size; y++) { if (m[y][x] === m[y-1][x]) { run++; if (run === 5) p += 3; else if (run > 5) p++; } else run = 1; } }
      for (y = 0; y < size - 1; y++) for (x = 0; x < size - 1; x++) { var c = m[y][x]; if (c === m[y][x+1] && c === m[y+1][x] && c === m[y+1][x+1]) p += 3; }
      var pat = [true,false,true,true,true,false,true];
      for (y = 0; y < size; y++) for (x = 0; x + 6 < size; x++) { var okH = true, okV = true; for (var t = 0; t < 7; t++) { if (m[y][x+t] !== pat[t]) okH = false; if (m[x+t] && m[x+t][y] !== pat[t]) okV = false; } if (okH) p += 40; if (okV) p += 40; }
      for (y = 0; y < size; y++) for (x = 0; x < size; x++) if (m[y][x]) dark++;
      p += Math.floor(Math.abs(dark * 20 - size * size * 10) / (size * size)) * 10;
      return p;
    }
    var best = 0, bestP = Infinity;
    for (var k = 0; k < 8; k++) { apply(k); format(k); var pp = penalty(); if (pp < bestP) { bestP = pp; best = k; } apply(k); }
    apply(best); format(best);
    return m;
  }
  function svg(text, opts){
    opts = opts || {};
    var m = encode(text), n = m.length, margin = opts.margin == null ? 4 : opts.margin, dim = n + margin * 2, d = '';
    for (var y = 0; y < n; y++) for (var x = 0; x < n; x++) if (m[y][x]) d += 'M' + (x + margin) + ' ' + (y + margin) + 'h1v1h-1z';
    var ns = 'http://www.w3.org/2000/svg', el = document.createElementNS(ns, 'svg');
    el.setAttribute('viewBox', '0 0 ' + dim + ' ' + dim); el.setAttribute('shape-rendering', 'crispEdges'); el.setAttribute('role', 'img'); el.setAttribute('aria-label', opts.label || 'QR code');
    el.setAttribute('class', 'vc-qr');
    var bg = document.createElementNS(ns, 'rect'); bg.setAttribute('width', dim); bg.setAttribute('height', dim); bg.setAttribute('fill', opts.light || '#fff'); el.appendChild(bg);
    var p = document.createElementNS(ns, 'path'); p.setAttribute('d', d); p.setAttribute('fill', opts.dark || '#000'); el.appendChild(p);
    return el;
  }
  root.VaultcastQR = { matrix: encode, svg: svg };
})(this);
