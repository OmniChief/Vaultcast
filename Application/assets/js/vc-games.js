/* Vaultcast Arcade engines: original canvas games with no external assets.
   Each create() returns { pause, resume, restart, destroy, input(action, down) } and reports through ctx:
   ctx.score(n), ctx.over({ score, meta }), ctx.status(text), ctx.sound() -> bool. */
(function(root){
  'use strict';
  var audioCtx = null;
  function beep(ctx, freq, dur, type, vol){
    if (!ctx.sound || !ctx.sound()) return;
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      if (audioCtx.state === 'suspended') audioCtx.resume();
      var o = audioCtx.createOscillator(), g = audioCtx.createGain(), t = audioCtx.currentTime;
      o.type = type || 'square'; o.frequency.setValueAtTime(freq, t);
      g.gain.setValueAtTime(vol || 0.05, t); g.gain.exponentialRampToValueAtTime(0.0001, t + (dur || 0.08));
      o.connect(g); g.connect(audioCtx.destination); o.start(t); o.stop(t + (dur || 0.08) + 0.02);
    } catch (e) {}
  }
  function palette(host){
    var cs = getComputedStyle(host), v = function(n, d){ var x = cs.getPropertyValue(n).trim(); return x || d; };
    return { accent: v('--accent', '#59e9ff'), accent2: v('--accent-2', '#a7a0ff'), text: v('--text', '#edf8ff'), muted: v('--muted', '#a7b9d2'), bg: v('--bg', '#070a13'), danger: v('--danger', '#f2a097'), ok: v('--ok', '#7ee2a8'), warn: v('--warn', '#f5dc7a') };
  }
  /** Canvas that fills its host at device pixel ratio; draw(ctx2d, w, h) works in CSS pixels. */
  function stage(host, aspect){
    var canvas = document.createElement('canvas'); canvas.className = 'vc-game-canvas'; host.appendChild(canvas);
    var g = canvas.getContext('2d'), w = 0, hgt = 0, api = { dirty:true };
    function fit(){
      api.dirty = true; // resizing clears the canvas, so loops that skip idle frames must redraw
      // clientWidth/Height exclude the stage border, so the canvas never overhangs it and stays truly centred.
      var bw = host.clientWidth, bh = host.clientHeight, maxW = Math.max(160, bw), maxH = Math.max(160, bh || window.innerHeight * 0.7);
      if (aspect) { w = Math.min(maxW, maxH * aspect); hgt = w / aspect; } else { w = maxW; hgt = maxH; }
      // Backing store = CSS box × device pixel ratio (capped), so lines and text stay crisp on HiDPI and TVs.
      w = Math.floor(w); hgt = Math.floor(hgt);
      var dpr = Math.min(3, window.devicePixelRatio || 1);
      canvas.width = Math.round(w * dpr); canvas.height = Math.round(hgt * dpr); canvas.style.width = w + 'px'; canvas.style.height = hgt + 'px';
      g.setTransform(dpr, 0, 0, dpr, 0, 0);
    }
    fit();
    var ro = window.ResizeObserver ? new ResizeObserver(function(){ fit(); }) : null; if (ro) ro.observe(host); else window.addEventListener('resize', fit);
    api.canvas = canvas; api.g = g; api.size = function(){ return { w:w, h:hgt }; };
    api.destroy = function(){ if (ro) ro.disconnect(); else window.removeEventListener('resize', fit); canvas.remove(); };
    return api;
  }
  function roundRect(g, x, y, w, h, r){ g.beginPath(); g.moveTo(x + r, y); g.arcTo(x + w, y, x + w, y + h, r); g.arcTo(x + w, y + h, x, y + h, r); g.arcTo(x, y + h, x, y, r); g.arcTo(x, y, x + w, y, r); g.closePath(); }
  /**
   * A requestAnimationFrame loop. Engines stop it while paused or over (one last frame is drawn), so a paused
   * or finished game costs nothing; browsers also stop rAF in hidden tabs and the host pauses the game there.
   */
  function loop(step){
    var raf = 0, last = 0, running = false;
    function frame(t){ if (!running) return; var dt = last ? Math.min(100, t - last) : 16; last = t; raf = requestAnimationFrame(frame); step(dt); }
    return { start:function(){ if (running) return; running = true; last = 0; raf = requestAnimationFrame(frame); }, stop:function(){ running = false; cancelAnimationFrame(raf); }, get running(){ return running; } };
  }

  // ------------------------------------------------------------------ Stackfall (falling blocks)
  var SHAPES = {
    I:[[0,1],[1,1],[2,1],[3,1]], O:[[1,0],[2,0],[1,1],[2,1]], T:[[1,0],[0,1],[1,1],[2,1]], S:[[1,0],[2,0],[0,1],[1,1]],
    Z:[[0,0],[1,0],[1,1],[2,1]], J:[[0,0],[0,1],[1,1],[2,1]], L:[[2,0],[0,1],[1,1],[2,1]]
  };
  var HUES = { I:190, O:48, T:280, S:140, Z:350, J:220, L:28 };
  function blocks(host, ctx){
    var st = stage(host, 0.62), COLS = 10, ROWS = 20, pal = palette(host);
    var grid, piece, queue, hold, canHold, score, lines, level, dropMs, acc, over, paused, lockT, bag;
    function newBag(){ var b = Object.keys(SHAPES); for (var i = b.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)); var t = b[i]; b[i] = b[j]; b[j] = t; } return b; }
    function next(){ if (bag.length < 7) bag = bag.concat(newBag()); return bag.shift(); }
    function make(k){ return { k:k, cells:SHAPES[k].map(function(c){ return c.slice(); }), x:3, y:k === 'I' ? -1 : 0 }; }
    function reset(){
      grid = []; for (var r = 0; r < ROWS; r++) { grid.push([]); for (var c = 0; c < COLS; c++) grid[r].push(null); }
      bag = []; queue = [next(), next(), next()]; hold = null; canHold = true; score = 0; lines = 0; level = 1; dropMs = 800; acc = 0; over = false; paused = false; lockT = 0;
      spawn(); ctx.score(0);
    }
    function spawn(){ piece = make(queue.shift()); queue.push(next()); canHold = true; if (collide(piece, 0, 0, piece.cells)) { over = true; beep(ctx, 110, .5, 'sawtooth', .06); ctx.over({ score:score, meta:'lines:' + lines + ',level:' + level }); } }
    function collide(p, dx, dy, cells){
      for (var i = 0; i < cells.length; i++) { var x = p.x + cells[i][0] + dx, y = p.y + cells[i][1] + dy; if (x < 0 || x >= COLS || y >= ROWS) return true; if (y >= 0 && grid[y][x]) return true; }
      return false;
    }
    function rotate(dir){
      if (piece.k === 'O') return;
      var size = piece.k === 'I' ? 4 : 3, cells = piece.cells.map(function(c){ return dir > 0 ? [size - 1 - c[1], c[0]] : [c[1], size - 1 - c[0]]; });
      var kicks = [0, -1, 1, -2, 2];
      for (var i = 0; i < kicks.length; i++) for (var up = 0; up <= 1; up++) if (!collide(piece, kicks[i], -up, cells)) { piece.cells = cells; piece.x += kicks[i]; piece.y -= up; lockT = 0; beep(ctx, 520, .03, 'triangle', .03); return; }
    }
    function lock(){
      piece.cells.forEach(function(c){ var y = piece.y + c[1]; if (y >= 0) grid[y][piece.x + c[0]] = piece.k; });
      var cleared = 0;
      for (var r = ROWS - 1; r >= 0; r--) if (grid[r].every(Boolean)) { grid.splice(r, 1); grid.unshift(new Array(COLS).fill(null)); cleared++; r++; }
      if (cleared) {
        score += [0, 100, 300, 500, 800][cleared] * level; lines += cleared;
        var nl = Math.floor(lines / 10) + 1; if (nl > level) { level = nl; beep(ctx, 880, .2, 'triangle', .05); }
        dropMs = Math.max(70, 800 - (level - 1) * 65);
        beep(ctx, 300 + cleared * 140, .12, 'square', .05);
      } else beep(ctx, 160, .04, 'triangle', .03);
      ctx.score(score); spawn();
    }
    function move(dx){ if (!collide(piece, dx, 0, piece.cells)) { piece.x += dx; lockT = 0; } }
    function soft(){ if (!collide(piece, 0, 1, piece.cells)) { piece.y++; score += 1; ctx.score(score); acc = 0; } }
    function hard(){ var d = 0; while (!collide(piece, 0, 1, piece.cells)) { piece.y++; d++; } score += d * 2; ctx.score(score); lock(); }
    function doHold(){ if (!canHold) return; var k = piece.k; if (hold) { piece = make(hold); } else spawn(); hold = k; canHold = false; }
    function ghostY(){ var d = 0; while (!collide(piece, 0, d + 1, piece.cells)) d++; return piece.y + d; }
    function cell(g, x, y, s, k, alpha){
      var hue = HUES[k]; g.globalAlpha = alpha || 1;
      var grad = g.createLinearGradient(x, y, x + s, y + s); grad.addColorStop(0, 'hsl(' + hue + ',85%,66%)'); grad.addColorStop(1, 'hsl(' + hue + ',75%,46%)');
      g.fillStyle = grad; roundRect(g, x + 1, y + 1, s - 2, s - 2, Math.max(2, s * .18)); g.fill();
      g.fillStyle = 'rgba(255,255,255,.22)'; g.fillRect(x + 3, y + 3, s - 6, Math.max(2, s * .14)); g.globalAlpha = 1;
    }
    function draw(){
      var sz = st.size(), g = st.g, s = Math.floor(Math.min(sz.h / ROWS, (sz.w * 0.66) / COLS)), ox = 8, oy = Math.floor((sz.h - s * ROWS) / 2);
      g.clearRect(0, 0, sz.w, sz.h);
      g.fillStyle = 'rgba(0,0,0,.28)'; roundRect(g, ox - 4, oy - 4, s * COLS + 8, s * ROWS + 8, 10); g.fill();
      g.strokeStyle = 'rgba(255,255,255,.05)'; g.lineWidth = 1;
      for (var c = 1; c < COLS; c++) { g.beginPath(); g.moveTo(ox + c * s + .5, oy); g.lineTo(ox + c * s + .5, oy + ROWS * s); g.stroke(); }
      for (var r = 1; r < ROWS; r++) { g.beginPath(); g.moveTo(ox, oy + r * s + .5); g.lineTo(ox + COLS * s, oy + r * s + .5); g.stroke(); }
      for (r = 0; r < ROWS; r++) for (c = 0; c < COLS; c++) if (grid[r][c]) cell(g, ox + c * s, oy + r * s, s, grid[r][c]);
      if (piece && !over) {
        var gy = ghostY(); piece.cells.forEach(function(p){ if (gy + p[1] >= 0) { g.strokeStyle = pal.accent; g.globalAlpha = .45; g.lineWidth = 2; roundRect(g, ox + (piece.x + p[0]) * s + 2, oy + (gy + p[1]) * s + 2, s - 4, s - 4, 4); g.stroke(); g.globalAlpha = 1; } });
        piece.cells.forEach(function(p){ if (piece.y + p[1] >= 0) cell(g, ox + (piece.x + p[0]) * s, oy + (piece.y + p[1]) * s, s, piece.k); });
      }
      var px = ox + COLS * s + 18, ps = Math.max(10, Math.floor(s * .62)), y = oy;
      g.fillStyle = pal.muted; g.font = '600 ' + Math.max(11, s * .5) + 'px system-ui, sans-serif'; g.fillText('NEXT', px, y + 12); y += 22;
      queue.forEach(function(k){ SHAPES[k].forEach(function(p){ cell(g, px + p[0] * ps, y + p[1] * ps, ps, k); }); y += ps * 2.8; });
      y += 8; g.fillStyle = pal.muted; g.fillText('HOLD', px, y + 12); y += 22;
      if (hold) SHAPES[hold].forEach(function(p){ cell(g, px + p[0] * ps, y + p[1] * ps, ps, hold, canHold ? 1 : .4); });
      y += ps * 3;
      g.fillStyle = pal.muted; g.fillText('LEVEL', px, y + 12); g.fillStyle = pal.text; g.font = '700 ' + Math.max(14, s * .8) + 'px system-ui, sans-serif'; g.fillText(String(level), px, y + 14 + s * .8);
      y += s * 2; g.fillStyle = pal.muted; g.font = '600 ' + Math.max(11, s * .5) + 'px system-ui, sans-serif'; g.fillText('LINES', px, y + 12); g.fillStyle = pal.text; g.font = '700 ' + Math.max(14, s * .8) + 'px system-ui, sans-serif'; g.fillText(String(lines), px, y + 14 + s * .8);
    }
    var lp = loop(function(dt){
      if (paused || over) { draw(); lp.stop(); return; }
      acc += dt;
      if (acc >= dropMs) { acc = 0; if (!collide(piece, 0, 1, piece.cells)) piece.y++; else { lockT += dropMs; } }
      if (collide(piece, 0, 1, piece.cells)) { lockT += dt; if (lockT > 500) { lockT = 0; lock(); } }
      draw();
    });
    reset(); lp.start();
    return {
      pause:function(){ paused = true; }, resume:function(){ paused = false; if (!over) lp.start(); }, restart:function(){ reset(); lp.start(); },
      destroy:function(){ lp.stop(); st.destroy(); },
      input:function(a, down){ if (!down || paused || over) return; if (a === 'left') move(-1); else if (a === 'right') move(1); else if (a === 'down') soft(); else if (a === 'up' || a === 'rotate') rotate(1); else if (a === 'rotateL') rotate(-1); else if (a === 'drop' || a === 'action') hard(); else if (a === 'hold') doHold(); },
      repeat:['left','right','down'], controls:[['left','◀'],['rotate','⟳'],['right','▶'],['down','▼'],['drop','⤓'],['hold','Hold']],
      help:'← → move · ↑ rotate · ↓ soft drop · Space hard drop · C hold'
    };
  }

  // ------------------------------------------------------------------ Neon Snake
  function snake(host, ctx){
    var st = stage(host, 1), N = 22, pal = palette(host);
    var body, dir, nextDir, food, bonus, score, stepMs, acc, over, paused, grow, eaten;
    function free(){ var x, y, n = 0; do { x = Math.floor(Math.random() * N); y = Math.floor(Math.random() * N); n++; } while (n < 500 && (body.some(function(b){ return b[0] === x && b[1] === y; }) || (food && food[0] === x && food[1] === y))); return [x, y]; }
    function reset(){ body = [[10, 11], [9, 11], [8, 11]]; dir = [1, 0]; nextDir = [1, 0]; food = null; food = free(); bonus = null; score = 0; stepMs = 140; acc = 0; over = false; paused = false; grow = 0; eaten = 0; ctx.score(0); }
    function step(){
      dir = nextDir; var head = [body[0][0] + dir[0], body[0][1] + dir[1]];
      if (head[0] < 0 || head[1] < 0 || head[0] >= N || head[1] >= N || body.some(function(b, i){ return i < body.length - (grow ? 0 : 1) && b[0] === head[0] && b[1] === head[1]; })) {
        over = true; beep(ctx, 120, .45, 'sawtooth', .06); ctx.over({ score:score, meta:'length:' + body.length }); return;
      }
      body.unshift(head);
      if (head[0] === food[0] && head[1] === food[1]) { score += 10; grow += 2; eaten++; food = free(); beep(ctx, 660, .06, 'square', .04); stepMs = Math.max(60, 140 - eaten * 2.2); if (eaten % 5 === 0) bonus = { at:free(), ttl:6000 }; ctx.score(score); }
      else if (bonus && head[0] === bonus.at[0] && head[1] === bonus.at[1]) { score += 50; grow += 3; bonus = null; beep(ctx, 990, .15, 'triangle', .05); ctx.score(score); }
      if (grow) grow--; else body.pop();
    }
    function draw(){
      var sz = st.size(), g = st.g, s = Math.max(4, Math.floor(Math.min(sz.w, sz.h) / N)), ox = Math.round((sz.w - s * N) / 2), oy = Math.round((sz.h - s * N) / 2);
      g.clearRect(0, 0, sz.w, sz.h); g.fillStyle = 'rgba(0,0,0,.3)'; roundRect(g, ox, oy, s * N, s * N, 12); g.fill();
      g.fillStyle = 'rgba(255,255,255,.035)'; for (var y = 0; y < N; y++) for (var x = (y % 2); x < N; x += 2) g.fillRect(ox + x * s, oy + y * s, s, s);
      g.shadowColor = pal.danger; g.shadowBlur = 6; g.fillStyle = pal.danger; g.beginPath(); g.arc(ox + (food[0] + .5) * s, oy + (food[1] + .5) * s, s * .36, 0, Math.PI * 2); g.fill();
      if (bonus) { g.shadowColor = pal.warn; g.fillStyle = pal.warn; g.globalAlpha = .5 + .5 * Math.sin(Date.now() / 120); g.beginPath(); g.arc(ox + (bonus.at[0] + .5) * s, oy + (bonus.at[1] + .5) * s, s * .42, 0, Math.PI * 2); g.fill(); g.globalAlpha = 1; }
      g.shadowColor = pal.accent; g.shadowBlur = 5;
      body.forEach(function(b, i){ var t = i / Math.max(1, body.length - 1); g.fillStyle = i === 0 ? pal.text : (t < .5 ? pal.accent : pal.accent2); roundRect(g, ox + b[0] * s + 1.5, oy + b[1] * s + 1.5, s - 3, s - 3, s * .3); g.fill(); });
      g.shadowBlur = 0;
    }
    var lp = loop(function(dt){
      if (!paused && !over) { acc += dt; if (bonus) { bonus.ttl -= dt; if (bonus.ttl <= 0) bonus = null; } while (acc >= stepMs && !over) { acc -= stepMs; step(); } }
      draw(); if (paused || over) lp.stop();
    });
    reset(); lp.start();
    function turn(d){ if (d[0] === -dir[0] && d[1] === -dir[1]) return; nextDir = d; }
    return { pause:function(){ paused = true; }, resume:function(){ paused = false; if (!over) lp.start(); }, restart:function(){ reset(); lp.start(); }, destroy:function(){ lp.stop(); st.destroy(); },
      input:function(a, down){ if (!down || paused || over) return; if (a === 'left') turn([-1, 0]); else if (a === 'right') turn([1, 0]); else if (a === 'up') turn([0, -1]); else if (a === 'down') turn([0, 1]); },
      swipe:true, controls:[['up','▲'],['left','◀'],['down','▼'],['right','▶']], help:'Arrow keys, WASD, swipe or D-pad to steer' };
  }

  // ------------------------------------------------------------------ 2048 Merge
  function merge(host, ctx){
    var st = stage(host, 1), pal = palette(host), N = 4;
    var tiles, score, over, paused, anim, won, idSeq;
    function emptyCells(){ var out = []; for (var y = 0; y < N; y++) for (var x = 0; x < N; x++) if (!at(x, y)) out.push([x, y]); return out; }
    function at(x, y){ for (var i = 0; i < tiles.length; i++) if (tiles[i].x === x && tiles[i].y === y && !tiles[i].dead) return tiles[i]; return null; }
    function add(){ var e = emptyCells(); if (!e.length) return; var c = e[Math.floor(Math.random() * e.length)]; tiles.push({ id:++idSeq, x:c[0], y:c[1], px:c[0], py:c[1], v:Math.random() < .9 ? 2 : 4, born:Date.now() }); }
    var busyUntil = 0; function busy(){ busyUntil = Date.now() + 260; if (!lp.running && !paused) lp.start(); }
    function reset(){ tiles = []; score = 0; over = false; paused = false; won = false; idSeq = 0; add(); add(); anim = 0; ctx.score(0); busy(); }
    function maxTile(){ return tiles.reduce(function(m, t){ return t.dead ? m : Math.max(m, t.v); }, 0); }
    function canMove(){ if (emptyCells().length) return true; for (var y = 0; y < N; y++) for (var x = 0; x < N; x++) { var t = at(x, y); if ((x + 1 < N && at(x + 1, y).v === t.v) || (y + 1 < N && at(x, y + 1).v === t.v)) return true; } return false; }
    function move(dx, dy){
      if (anim && Date.now() - anim < 90) return;
      tiles = tiles.filter(function(t){ return !t.dead; }); tiles.forEach(function(t){ t.px = t.x; t.py = t.y; t.merged = false; });
      var moved = false, gained = 0, order = [];
      for (var i = 0; i < N; i++) order.push(i); if (dx > 0 || dy > 0) order.reverse();
      order.forEach(function(a){ order.forEach(function(b){
        var x = dx ? a : b, y = dx ? b : a, t = at(x, y); if (!t) return;
        var nx = x, ny = y;
        while (true) {
          var tx = nx + dx, ty = ny + dy; if (tx < 0 || ty < 0 || tx >= N || ty >= N) break;
          var o = at(tx, ty);
          if (!o) { nx = tx; ny = ty; continue; }
          if (o.v === t.v && !o.merged && !t.merged) { o.v *= 2; o.merged = true; o.pop = Date.now(); gained += o.v; t.dead = true; t.x = tx; t.y = ty; moved = true; }
          break;
        }
        if (!t.dead && (nx !== x || ny !== y)) { t.x = nx; t.y = ny; moved = true; }
      }); });
      if (!moved) return;
      score += gained; anim = Date.now(); busy(); if (gained) beep(ctx, 300 + Math.log2(gained) * 40, .07, 'triangle', .04); else beep(ctx, 200, .03, 'triangle', .02);
      add(); ctx.score(score);
      if (!won && maxTile() >= 2048) { won = true; ctx.status('2048! Keep going for a higher score.'); beep(ctx, 1046, .3, 'triangle', .06); }
      if (!canMove()) { over = true; setTimeout(function(){ ctx.over({ score:score, meta:'tile:' + maxTile() }); }, 300); }
    }
    function colour(v){ var l = Math.log2(v), hue = (200 - l * 18 + 360) % 360; return 'hsl(' + hue + ',' + Math.min(90, 40 + l * 5) + '%,' + Math.max(38, 70 - l * 2.4) + '%)'; }
    function draw(){
      var sz = st.size(), g = st.g, size = Math.floor(Math.min(sz.w, sz.h)), pad = Math.max(2, Math.round(size * .025)), s = Math.floor((size - pad * (N + 1)) / N), ox = Math.round((sz.w - size) / 2), oy = Math.round((sz.h - size) / 2), now = Date.now(), k = anim ? Math.min(1, (now - anim) / 100) : 1;
      g.clearRect(0, 0, sz.w, sz.h); g.fillStyle = 'rgba(0,0,0,.3)'; roundRect(g, ox, oy, size, size, 14); g.fill();
      for (var y = 0; y < N; y++) for (var x = 0; x < N; x++) { g.fillStyle = 'rgba(255,255,255,.05)'; roundRect(g, ox + pad + x * (s + pad), oy + pad + y * (s + pad), s, s, 10); g.fill(); }
      tiles.forEach(function(t){
        var fx = t.px + (t.x - t.px) * k, fy = t.py + (t.y - t.py) * k, scale = 1;
        if (t.born && now - t.born < 140) scale = .5 + .5 * (now - t.born) / 140;
        if (t.pop && now - t.pop < 160 && k >= 1) scale = 1 + .12 * Math.sin(Math.PI * (now - t.pop) / 160);
        if (t.dead && k >= 1) return;
        var cx = ox + pad + fx * (s + pad) + s / 2, cy = oy + pad + fy * (s + pad) + s / 2, w = s * scale;
        g.fillStyle = colour(t.v); roundRect(g, cx - w / 2, cy - w / 2, w, w, 10); g.fill();
        g.fillStyle = t.v <= 4 ? '#0b1220' : '#fff'; g.textAlign = 'center'; g.textBaseline = 'middle';
        g.font = '800 ' + Math.round(s * (t.v < 100 ? .42 : t.v < 1000 ? .34 : t.v < 10000 ? .27 : .22)) + 'px system-ui, sans-serif'; g.fillText(String(t.v), cx, cy + 1);
      });
      g.textAlign = 'start'; g.textBaseline = 'alphabetic';
    }
    // 2048 only changes when a move animates, so the loop sleeps between moves instead of redrawing 60 times a second.
    var lp = loop(function(){ if (st.dirty || Date.now() < busyUntil) { st.dirty = false; draw(); } else { lp.stop(); idleWatch(); } });
    var idleTimer = 0; function idleWatch(){ clearTimeout(idleTimer); idleTimer = setTimeout(function(){ if (st.dirty && !lp.running && st.canvas.isConnected) { st.dirty = false; draw(); } if (st.canvas.isConnected) idleWatch(); }, 250); }
    reset(); lp.start();
    return { pause:function(){ paused = true; }, resume:function(){ paused = false; busy(); }, restart:function(){ reset(); }, destroy:function(){ clearTimeout(idleTimer); lp.stop(); st.destroy(); },
      snapshot:function(){ return over ? null : { tiles:tiles.filter(function(t){ return !t.dead; }).map(function(t){ return [t.x, t.y, t.v]; }), score:score, won:won }; },
      restore:function(snap){
        if (!snap || !Array.isArray(snap.tiles) || !snap.tiles.length) return false;
        var ok = snap.tiles.every(function(t){ return Array.isArray(t) && t[0] >= 0 && t[0] < N && t[1] >= 0 && t[1] < N && t[2] >= 2 && (t[2] & (t[2] - 1)) === 0; });
        if (!ok) return false;
        tiles = snap.tiles.map(function(t){ return { id:++idSeq, x:t[0], y:t[1], px:t[0], py:t[1], v:t[2] }; }); score = Math.max(0, Number(snap.score) || 0); won = !!snap.won; over = false; anim = 0; ctx.score(score); busy(); return true;
      },
      input:function(a, down){ if (!down || paused || over) return; if (a === 'left') move(-1, 0); else if (a === 'right') move(1, 0); else if (a === 'up') move(0, -1); else if (a === 'down') move(0, 1); },
      swipe:true, controls:[['up','▲'],['left','◀'],['down','▼'],['right','▶']], help:'Arrow keys, WASD or swipe to slide the tiles' };
  }

  // ------------------------------------------------------------------ Brickbreak
  function breakout(host, ctx){
    var st = stage(host, 4 / 3), pal = palette(host), W = 800, H = 600;
    var paddle, balls, bricks, score, lives, level, over, paused, keys, launched, power, particles;
    function build(){
      bricks = []; var rows = Math.min(8, 4 + level), cols = 12, bw = (W - 60) / cols, pattern = level % 3;
      for (var r = 0; r < rows; r++) for (var c = 0; c < cols; c++) {
        if (pattern === 1 && (r + c) % 4 === 3) continue;
        if (pattern === 2 && Math.abs(c - (cols - 1) / 2) < r / 2 - 1) continue;
        bricks.push({ x:30 + c * bw, y:70 + r * 26, w:bw - 4, h:20, hp:r < Math.floor(level / 2) ? 2 : 1, row:r, pts:(rows - r) * 10 });
      }
    }
    function newBall(){ return { x:paddle.x + paddle.w / 2, y:paddle.y - 10, vx:0, vy:0, r:7 }; }
    function reset(){ level = 1; score = 0; lives = 3; over = false; paused = false; keys = {}; power = null; particles = []; paddle = { x:W / 2 - 60, y:H - 40, w:120, h:14 }; build(); balls = [newBall()]; launched = false; ctx.score(0); ctx.status('Lives ' + lives + ' · Level ' + level); }
    function launch(){ if (launched) return; launched = true; var sp = 5.2 + level * .35; balls.forEach(function(b){ var a = -Math.PI / 2 + (Math.random() - .5) * .8; b.vx = Math.cos(a) * sp; b.vy = Math.sin(a) * sp; }); beep(ctx, 440, .05, 'square', .04); }
    function burst(x, y, colour){ for (var i = 0; i < 8; i++) particles.push({ x:x, y:y, vx:(Math.random() - .5) * 6, vy:(Math.random() - .7) * 6, life:30, c:colour }); }
    function update(dt){
      var f = dt / 16.67;
      if (keys.left) paddle.x -= 9 * f; if (keys.right) paddle.x += 9 * f;
      paddle.x = Math.max(0, Math.min(W - paddle.w, paddle.x));
      if (!launched) { balls[0].x = paddle.x + paddle.w / 2; balls[0].y = paddle.y - 10; }
      if (power && Date.now() > power.until) { paddle.w = 120; power = null; }
      balls.forEach(function(b){
        if (!launched) return;
        var steps = Math.ceil(Math.max(Math.abs(b.vx), Math.abs(b.vy)) * f / 4);
        for (var s = 0; s < steps; s++) {
          b.x += b.vx * f / steps; b.y += b.vy * f / steps;
          if (b.x < b.r) { b.x = b.r; b.vx = Math.abs(b.vx); } if (b.x > W - b.r) { b.x = W - b.r; b.vx = -Math.abs(b.vx); }
          if (b.y < b.r) { b.y = b.r; b.vy = Math.abs(b.vy); }
          if (b.vy > 0 && b.y + b.r >= paddle.y && b.y + b.r <= paddle.y + paddle.h + 8 && b.x >= paddle.x - b.r && b.x <= paddle.x + paddle.w + b.r) {
            var hit = (b.x - (paddle.x + paddle.w / 2)) / (paddle.w / 2), sp = Math.min(11, Math.hypot(b.vx, b.vy) * 1.015), ang = -Math.PI / 2 + hit * 1.05;
            b.vx = Math.cos(ang) * sp; b.vy = Math.sin(ang) * sp; b.y = paddle.y - b.r; beep(ctx, 330, .04, 'square', .03);
          }
          for (var i = 0; i < bricks.length; i++) {
            var k = bricks[i];
            if (b.x + b.r < k.x || b.x - b.r > k.x + k.w || b.y + b.r < k.y || b.y - b.r > k.y + k.h) continue;
            var ox = Math.min(b.x + b.r - k.x, k.x + k.w - (b.x - b.r)), oy = Math.min(b.y + b.r - k.y, k.y + k.h - (b.y - b.r));
            if (ox < oy) b.vx = -b.vx; else b.vy = -b.vy;
            k.hp--; if (k.hp <= 0) { score += k.pts * level; burst(k.x + k.w / 2, k.y + k.h / 2, 'hsl(' + (k.row * 40 + 180) % 360 + ',80%,62%)'); bricks.splice(i, 1);
              if (Math.random() < .08) { if (Math.random() < .5) { paddle.w = 180; power = { until:Date.now() + 12000 }; ctx.status('Wide paddle!'); } else if (balls.length < 4) { var nb = { x:b.x, y:b.y, vx:-b.vx, vy:b.vy, r:7 }; balls.push(nb); ctx.status('Multi-ball!'); } }
            } else score += 5;
            beep(ctx, 520 + (k.row || 0) * 40, .04, 'triangle', .035); ctx.score(score); break;
          }
        }
      });
      balls = balls.filter(function(b){ return b.y < H + 20; });
      if (!balls.length) {
        lives--; beep(ctx, 150, .3, 'sawtooth', .05);
        if (lives <= 0) { over = true; ctx.over({ score:score, meta:'level:' + level }); return; }
        balls = [newBall()]; launched = false; paddle.w = 120; power = null; ctx.status('Lives ' + lives + ' · Level ' + level);
      }
      if (!bricks.length) { level++; score += 500; build(); balls = [newBall()]; launched = false; ctx.score(score); ctx.status('Level ' + level + '!'); beep(ctx, 880, .3, 'triangle', .06); }
      particles.forEach(function(p){ p.x += p.vx * f; p.y += p.vy * f; p.vy += .25 * f; p.life -= f; }); particles = particles.filter(function(p){ return p.life > 0; });
    }
    function draw(){
      var sz = st.size(), g = st.g, sc = sz.w / W; g.setTransform(1, 0, 0, 1, 0, 0); var dpr = st.canvas.width / sz.w; g.setTransform(dpr * sc, 0, 0, dpr * sc, 0, 0);
      g.clearRect(0, 0, W, H); g.fillStyle = 'rgba(0,0,0,.32)'; roundRect(g, 0, 0, W, H, 16); g.fill();
      bricks.forEach(function(k){ var hue = (k.row * 40 + 180) % 360; var gr = g.createLinearGradient(k.x, k.y, k.x, k.y + k.h); gr.addColorStop(0, 'hsl(' + hue + ',85%,' + (k.hp > 1 ? 50 : 64) + '%)'); gr.addColorStop(1, 'hsl(' + hue + ',75%,' + (k.hp > 1 ? 36 : 48) + '%)'); g.fillStyle = gr; roundRect(g, k.x, k.y, k.w, k.h, 5); g.fill(); if (k.hp > 1) { g.strokeStyle = 'rgba(255,255,255,.6)'; g.lineWidth = 2; g.stroke(); } });
      particles.forEach(function(p){ g.globalAlpha = p.life / 30; g.fillStyle = p.c; g.fillRect(p.x, p.y, 4, 4); }); g.globalAlpha = 1;
      var pg = g.createLinearGradient(paddle.x, 0, paddle.x + paddle.w, 0); pg.addColorStop(0, pal.accent); pg.addColorStop(1, pal.accent2);
      g.shadowColor = pal.accent; g.shadowBlur = 8; g.fillStyle = pg; roundRect(g, paddle.x, paddle.y, paddle.w, paddle.h, 7); g.fill();
      g.fillStyle = '#fff'; balls.forEach(function(b){ g.beginPath(); g.arc(b.x, b.y, b.r, 0, Math.PI * 2); g.fill(); }); g.shadowBlur = 0;
      g.fillStyle = pal.text; g.font = '600 18px system-ui, sans-serif'; g.fillText('♥ '.repeat(Math.max(0, lives)).trim(), 18, 34); g.textAlign = 'right'; g.fillText('Level ' + level, W - 18, 34); g.textAlign = 'start';
      if (!launched && !over) { g.fillStyle = pal.muted; g.textAlign = 'center'; g.font = '600 20px system-ui, sans-serif'; g.fillText('Press Space or tap to launch', W / 2, H / 2 + 60); g.textAlign = 'start'; }
    }
    function pointer(e){ var r = st.canvas.getBoundingClientRect(); paddle.x = ((e.clientX - r.left) / r.width) * W - paddle.w / 2; }
    st.canvas.addEventListener('pointermove', pointer); st.canvas.addEventListener('pointerdown', function(e){ pointer(e); if (!paused && !over) launch(); });
    st.canvas.style.touchAction = 'none';
    var lp = loop(function(dt){ if (!paused && !over) update(dt); draw(); if (paused || over) lp.stop(); });
    reset(); lp.start();
    return { pause:function(){ paused = true; keys = {}; }, resume:function(){ paused = false; if (!over) lp.start(); }, restart:function(){ reset(); lp.start(); }, destroy:function(){ lp.stop(); st.destroy(); },
      input:function(a, down){ if (a === 'left' || a === 'right') keys[a] = down; else if (down && (a === 'action' || a === 'drop' || a === 'up') && !paused && !over) launch(); },
      hold:true, controls:[['left','◀'],['action','Launch'],['right','▶']], help:'← → or mouse / touch to move · Space to launch' };
  }

  // ------------------------------------------------------------------ Chess rules + a small engine (local games)
  var KN = [[1,2],[2,1],[2,-1],[1,-2],[-1,-2],[-2,-1],[-2,1],[-1,2]], KG = [[1,0],[1,1],[0,1],[-1,1],[-1,0],[-1,-1],[0,-1],[1,-1]], RK = [[1,0],[-1,0],[0,1],[0,-1]], BS = [[1,1],[1,-1],[-1,1],[-1,-1]];
  var START = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';
  function sqName(s){ return 'abcdefgh'[s % 8] + (Math.floor(s / 8) + 1); }
  function sqOf(n){ return (n.charCodeAt(1) - 49) * 8 + (n.charCodeAt(0) - 97); }
  function colourOf(p){ return p === '.' ? '' : (p === p.toUpperCase() ? 'w' : 'b'); }
  function parse(fen){
    var parts = fen.trim().split(/\s+/), rows = parts[0].split('/'), b = [];
    for (var i = 0; i < 64; i++) b.push('.');
    rows.forEach(function(row, i){ var rank = 7 - i, file = 0; row.split('').forEach(function(ch){ if (/\d/.test(ch)) file += +ch; else { b[rank * 8 + file] = ch; file++; } }); });
    return { b:b, turn:parts[1] === 'b' ? 'b' : 'w', castle:parts[2] === '-' ? '' : parts[2], ep:parts[3] === '-' ? -1 : sqOf(parts[3]), half:+(parts[4] || 0), full:+(parts[5] || 1) };
  }
  function fen(s){
    var rows = [];
    for (var r = 7; r >= 0; r--) { var row = '', e = 0; for (var f = 0; f < 8; f++) { var p = s.b[r * 8 + f]; if (p === '.') { e++; continue; } if (e) { row += e; e = 0; } row += p; } rows.push(row + (e ? e : '')); }
    return rows.join('/') + ' ' + s.turn + ' ' + (s.castle || '-') + ' ' + (s.ep >= 0 ? sqName(s.ep) : '-') + ' ' + s.half + ' ' + s.full;
  }
  function attacked(b, sq, by){
    var f = sq % 8, r = sq >> 3, pd = by === 'w' ? -1 : 1, pawn = by === 'w' ? 'P' : 'p', i, ff, rr;
    for (i = -1; i <= 1; i += 2) { ff = f + i; rr = r + pd; if (ff >= 0 && ff < 8 && rr >= 0 && rr < 8 && b[rr * 8 + ff] === pawn) return true; }
    var n = by === 'w' ? 'N' : 'n', k = by === 'w' ? 'K' : 'k';
    for (i = 0; i < 8; i++) { ff = f + KN[i][0]; rr = r + KN[i][1]; if (ff >= 0 && ff < 8 && rr >= 0 && rr < 8 && b[rr * 8 + ff] === n) return true; ff = f + KG[i][0]; rr = r + KG[i][1]; if (ff >= 0 && ff < 8 && rr >= 0 && rr < 8 && b[rr * 8 + ff] === k) return true; }
    var sets = [[RK, by === 'w' ? 'RQ' : 'rq'], [BS, by === 'w' ? 'BQ' : 'bq']];
    for (var s = 0; s < 2; s++) for (i = 0; i < 4; i++) { var d = sets[s][0][i]; ff = f + d[0]; rr = r + d[1]; while (ff >= 0 && ff < 8 && rr >= 0 && rr < 8) { var p = b[rr * 8 + ff]; if (p !== '.') { if (sets[s][1].indexOf(p) >= 0) return true; break; } ff += d[0]; rr += d[1]; } }
    return false;
  }
  function inCheck(s, side){ var k = s.b.indexOf(side === 'w' ? 'K' : 'k'); return k < 0 || attacked(s.b, k, side === 'w' ? 'b' : 'w'); }
  function pseudo(s){
    var b = s.b, me = s.turn, them = me === 'w' ? 'b' : 'w', out = [];
    function pm(from, to, promo){ if (!promo) out.push([from, to, '']); else ['q','r','b','n'].forEach(function(p){ out.push([from, to, p]); }); }
    for (var sq = 0; sq < 64; sq++) {
      var p = b[sq]; if (colourOf(p) !== me) continue;
      var f = sq % 8, r = sq >> 3, t = p.toLowerCase();
      if (t === 'p') {
        var dir = me === 'w' ? 1 : -1, start = me === 'w' ? 1 : 6, last = me === 'w' ? 7 : 0, to = sq + 8 * dir;
        if (to >= 0 && to < 64 && b[to] === '.') { pm(sq, to, (to >> 3) === last); if (r === start && b[sq + 16 * dir] === '.') out.push([sq, sq + 16 * dir, '']); }
        [-1, 1].forEach(function(df){ var ff = f + df; if (ff < 0 || ff > 7) return; var t2 = (r + dir) * 8 + ff; if (t2 < 0 || t2 > 63) return; if (colourOf(b[t2]) === them || t2 === s.ep) pm(sq, t2, (t2 >> 3) === last); });
        continue;
      }
      var dirs = t === 'n' ? KN : t === 'k' ? KG : t === 'r' ? RK : t === 'b' ? BS : RK.concat(BS), slide = 'rbq'.indexOf(t) >= 0;
      for (var i = 0; i < dirs.length; i++) { var ff = f + dirs[i][0], rr = r + dirs[i][1]; while (ff >= 0 && ff < 8 && rr >= 0 && rr < 8) { var tt = rr * 8 + ff, c = colourOf(b[tt]); if (c === me) break; out.push([sq, tt, '']); if (c || !slide) break; ff += dirs[i][0]; rr += dirs[i][1]; } }
      if (t === 'k') {
        var home = me === 'w' ? 4 : 60;
        if (sq === home && !attacked(b, sq, them)) {
          var R = me === 'w' ? 'R' : 'r';
          if (s.castle.indexOf(me === 'w' ? 'K' : 'k') >= 0 && b[sq + 1] === '.' && b[sq + 2] === '.' && b[sq + 3] === R && !attacked(b, sq + 1, them) && !attacked(b, sq + 2, them)) out.push([sq, sq + 2, '']);
          if (s.castle.indexOf(me === 'w' ? 'Q' : 'q') >= 0 && b[sq - 1] === '.' && b[sq - 2] === '.' && b[sq - 3] === '.' && b[sq - 4] === R && !attacked(b, sq - 1, them) && !attacked(b, sq - 2, them)) out.push([sq, sq - 2, '']);
        }
      }
    }
    return out;
  }
  function apply(s, m){
    var b = s.b.slice(), from = m[0], to = m[1], p = b[from], t = p.toLowerCase(), me = s.turn, capture = b[to] !== '.';
    if (t === 'p' && to === s.ep && !capture) { b[to + (me === 'w' ? -8 : 8)] = '.'; capture = true; }
    b[to] = m[2] ? (me === 'w' ? m[2].toUpperCase() : m[2]) : p; b[from] = '.';
    if (t === 'k' && Math.abs(to - from) === 2) { if (to > from) { b[from + 1] = b[from + 3]; b[from + 3] = '.'; } else { b[from - 1] = b[from - 4]; b[from - 4] = '.'; } }
    var castle = s.castle; [[4,'KQ'],[60,'kq'],[0,'Q'],[7,'K'],[56,'q'],[63,'k']].forEach(function(rule){ if (from === rule[0] || to === rule[0]) rule[1].split('').forEach(function(c){ castle = castle.replace(c, ''); }); });
    return { b:b, turn:me === 'w' ? 'b' : 'w', castle:castle, ep:t === 'p' && Math.abs(to - from) === 16 ? (from + to) / 2 : -1, half:(t === 'p' || capture) ? 0 : s.half + 1, full:s.full + (me === 'b' ? 1 : 0), cap:capture };
  }
  function legalMoves(s){ return pseudo(s).filter(function(m){ return !inCheck(apply(s, m), s.turn); }); }
  function uci(m){ return sqName(m[0]) + sqName(m[1]) + m[2]; }
  function outcome(s, history){
    if (!legalMoves(s).length) return inCheck(s, s.turn) ? 'checkmate' : 'stalemate';
    if (s.half >= 100) return 'fifty';
    var rest = s.b.join('').replace(/[kK.]/g, ''); if (rest === '' || /^[nNbB]$/.test(rest)) return 'material';
    if (history) { var key = fen(s).split(' ').slice(0, 4).join(' '), n = 0; history.forEach(function(h){ if (h === key) n++; }); if (n >= 3) return 'repetition'; }
    return '';
  }
  var VAL = { p:100, n:320, b:330, r:500, q:900, k:0 };
  var PST = {
    p:[0,0,0,0,0,0,0,0,5,10,10,-20,-20,10,10,5,5,-5,-10,0,0,-10,-5,5,0,0,0,20,20,0,0,0,5,5,10,25,25,10,5,5,10,10,20,30,30,20,10,10,50,50,50,50,50,50,50,50,0,0,0,0,0,0,0,0],
    n:[-50,-40,-30,-30,-30,-30,-40,-50,-40,-20,0,5,5,0,-20,-40,-30,5,10,15,15,10,5,-30,-30,0,15,20,20,15,0,-30,-30,5,15,20,20,15,5,-30,-30,0,10,15,15,10,0,-30,-40,-20,0,0,0,0,-20,-40,-50,-40,-30,-30,-30,-30,-40,-50],
    b:[-20,-10,-10,-10,-10,-10,-10,-20,-10,5,0,0,0,0,5,-10,-10,10,10,10,10,10,10,-10,-10,0,10,10,10,10,0,-10,-10,5,5,10,10,5,5,-10,-10,0,5,10,10,5,0,-10,-10,0,0,0,0,0,0,-10,-20,-10,-10,-10,-10,-10,-10,-20],
    r:[0,0,0,5,5,0,0,0,-5,0,0,0,0,0,0,-5,-5,0,0,0,0,0,0,-5,-5,0,0,0,0,0,0,-5,-5,0,0,0,0,0,0,-5,-5,0,0,0,0,0,0,-5,5,10,10,10,10,10,10,5,0,0,0,0,0,0,0,0],
    q:[-20,-10,-10,-5,-5,-10,-10,-20,-10,0,5,0,0,0,0,-10,-10,5,5,5,5,5,0,-10,0,0,5,5,5,5,0,-5,-5,0,5,5,5,5,0,-5,-10,0,5,5,5,5,0,-10,-10,0,0,0,0,0,0,-10,-20,-10,-10,-5,-5,-10,-10,-20],
    k:[20,30,10,0,0,10,30,20,20,20,0,0,0,0,20,20,-10,-20,-20,-20,-20,-20,-20,-10,-20,-30,-30,-40,-40,-30,-30,-20,-30,-40,-40,-50,-50,-40,-40,-30,-30,-40,-40,-50,-50,-40,-40,-30,-30,-40,-40,-50,-50,-40,-40,-30,-30,-40,-40,-50,-50,-40,-40,-30]
  };
  function evaluate(s){
    var score = 0;
    for (var sq = 0; sq < 64; sq++) { var p = s.b[sq]; if (p === '.') continue; var t = p.toLowerCase(), w = p !== t, idx = w ? sq : (7 - (sq >> 3)) * 8 + (sq % 8); var v = VAL[t] + PST[t][idx]; score += w ? v : -v; }
    return s.turn === 'w' ? score : -score;
  }
  function ordered(s, moves){ return moves.map(function(m){ var victim = s.b[m[1]]; return { m:m, k:(victim !== '.' ? 10 * VAL[victim.toLowerCase()] - VAL[s.b[m[0]].toLowerCase()] / 10 : 0) + (m[2] ? 800 : 0) }; }).sort(function(a, b){ return b.k - a.k; }).map(function(x){ return x.m; }); }
  function quiesce(s, alpha, beta, depth, budget){
    var stand = evaluate(s); if (stand >= beta) return beta; if (alpha < stand) alpha = stand; if (depth <= 0 || budget.n-- <= 0) return alpha;
    var caps = ordered(s, legalMoves(s).filter(function(m){ return s.b[m[1]] !== '.' || m[2]; }));
    for (var i = 0; i < caps.length; i++) { var v = -quiesce(apply(s, caps[i]), -beta, -alpha, depth - 1, budget); if (v >= beta) return beta; if (v > alpha) alpha = v; }
    return alpha;
  }
  function search(s, depth, alpha, beta, budget){
    var moves = legalMoves(s);
    if (!moves.length) return inCheck(s, s.turn) ? -100000 - depth : 0;
    if (depth === 0) return quiesce(s, alpha, beta, 3, budget);
    moves = ordered(s, moves);
    for (var i = 0; i < moves.length; i++) { var v = -search(apply(s, moves[i]), depth - 1, -beta, -alpha, budget); if (v >= beta) return beta; if (v > alpha) alpha = v; if (budget.n <= 0) break; }
    return alpha;
  }
  /** level 1: shallow with some randomness; 2: depth 2; 3: depth 3. */
  function bestMove(s, level){
    var moves = ordered(s, legalMoves(s)); if (!moves.length) return null;
    var depth = level >= 3 ? 3 : level === 2 ? 2 : 1, budget = { n:level >= 3 ? 60000 : 20000 }, best = null, bestV = -Infinity, scored = [];
    for (var i = 0; i < moves.length; i++) { var v = -search(apply(s, moves[i]), depth - 1, -Infinity, -bestV + (level === 1 ? 120 : 0), budget); scored.push([v, moves[i]]); if (v > bestV) { bestV = v; best = moves[i]; } }
    if (level === 1) { scored.sort(function(a, b){ return b[0] - a[0]; }); var pool = scored.filter(function(x){ return x[0] >= scored[0][0] - 90; }); best = pool[Math.floor(Math.random() * pool.length)][1]; }
    return best;
  }
  var chess = { START:START, parse:parse, fen:fen, legal:legalMoves, apply:apply, uci:uci, outcome:outcome, inCheck:inCheck, bestMove:bestMove, sq:sqOf, name:sqName,
    perft:function(s, d){ if (!d) return 1; var n = 0; legalMoves(s).forEach(function(m){ n += chess.perft(apply(s, m), d - 1); }); return n; } };

  root.VaultcastGames = {
    blocks:blocks, snake:snake, merge:merge, breakout:breakout, chess:chess, beep:beep, palette:palette
  };
})(this);
