/* Vaultcast animated backgrounds for Ambient (loaded on demand). Canvas only, no libraries.
 * Every effect: requestAnimationFrame capped to ~30 fps, nothing while the tab is hidden, a single still frame
 * under prefers-reduced-motion, device pixel ratio capped (soft effects render at a fraction of the screen
 * and let the browser scale them up), so they stay light on a NAS-driven TV. */
(function(root){
  'use strict';
  var TAU = Math.PI * 2;
  function rnd(a, b){ return a + Math.random() * (b - a); }
  var FX = {};

  // Aurora: slow ribbons of light. Rendered at 1/4 size; the upscale is the blur.
  FX.aurora = { scale:0.25, init:function(st){ st.bands = [0, 1, 2, 3].map(function(i){ return { hue:[150, 175, 200, 285][i], ph:rnd(0, TAU), sp:rnd(0.05, 0.12), y:0.25 + i * 0.12 }; }); },
    draw:function(c, w, hgt, t, st){
      var g = c.createLinearGradient(0, 0, 0, hgt); g.addColorStop(0, '#020613'); g.addColorStop(1, '#06121c'); c.fillStyle = g; c.fillRect(0, 0, w, hgt);
      c.globalCompositeOperation = 'lighter';
      st.bands.forEach(function(b, i){
        c.beginPath();
        for (var x = 0; x <= w; x += 4) {
          var y = hgt * (b.y + 0.08 * Math.sin(x / w * 3 + t * b.sp + b.ph) + 0.04 * Math.sin(x / w * 7 - t * b.sp * 1.7));
          if (x === 0) c.moveTo(x, y); else c.lineTo(x, y);
        }
        c.lineWidth = hgt * (0.12 + 0.05 * Math.sin(t * 0.2 + i));
        c.strokeStyle = 'hsla(' + (b.hue + 20 * Math.sin(t * 0.03 + i)) + ',85%,55%,0.28)'; c.stroke();
      });
      c.globalCompositeOperation = 'source-over';
    } };

  // Starfield: stars drifting towards the viewer.
  FX.stars = { scale:1, init:function(st){ st.s = []; for (var i = 0; i < 420; i++) st.s.push({ x:rnd(-1, 1), y:rnd(-1, 1), z:rnd(0.05, 1) }); },
    draw:function(c, w, hgt, t, st, dt){
      c.fillStyle = 'rgba(2,4,12,0.55)'; c.fillRect(0, 0, w, hgt);
      var cx = w / 2, cy = hgt / 2, k = Math.max(w, hgt) * 0.6;
      st.s.forEach(function(p){
        p.z -= dt * 0.035; if (p.z <= 0.03) { p.x = rnd(-1, 1); p.y = rnd(-1, 1); p.z = 1; }
        var sx = cx + p.x / p.z * k * 0.5, sy = cy + p.y / p.z * k * 0.5; if (sx < 0 || sy < 0 || sx > w || sy > hgt) { p.z = 1; return; }
        var r = Math.max(0.4, (1 - p.z) * 2.2); c.globalAlpha = Math.min(1, (1 - p.z) * 1.4);
        c.fillStyle = '#dfe9ff'; c.beginPath(); c.arc(sx, sy, r, 0, TAU); c.fill();
      });
      c.globalAlpha = 1;
    } };

  // Gentle waves: layered swells under a dusk sky.
  FX.waves = { scale:0.5, init:function(){},
    draw:function(c, w, hgt, t){
      var g = c.createLinearGradient(0, 0, 0, hgt); g.addColorStop(0, '#0b1a33'); g.addColorStop(0.55, '#123a5c'); g.addColorStop(1, '#0a2238'); c.fillStyle = g; c.fillRect(0, 0, w, hgt);
      for (var i = 0; i < 5; i++) {
        var base = hgt * (0.5 + i * 0.1), amp = hgt * (0.025 + i * 0.008), sp = 0.25 + i * 0.08;
        c.beginPath(); c.moveTo(0, hgt);
        for (var x = 0; x <= w; x += 6) c.lineTo(x, base + amp * Math.sin(x / w * (2.5 + i) * TAU / 2 + t * sp + i) + amp * 0.4 * Math.sin(x / w * 11 - t * sp * 1.3));
        c.lineTo(w, hgt); c.closePath();
        c.fillStyle = 'hsla(' + (200 + i * 6) + ',60%,' + (28 + i * 6) + '%,' + (0.35 + i * 0.1) + ')'; c.fill();
      }
    } };

  // Bokeh: soft out-of-focus lights drifting upwards.
  FX.bokeh = { scale:0.5, init:function(st, w, hgt){ st.p = []; for (var i = 0; i < 46; i++) st.p.push({ x:rnd(0, 1), y:rnd(0, 1), r:rnd(0.02, 0.09), v:rnd(0.004, 0.015), hue:rnd(20, 60) + (Math.random() < 0.35 ? 160 : 0), ph:rnd(0, TAU) }); },
    draw:function(c, w, hgt, t, st, dt){
      c.fillStyle = '#07060c'; c.fillRect(0, 0, w, hgt);
      c.globalCompositeOperation = 'lighter';
      var m = Math.max(w, hgt);
      st.p.forEach(function(p){
        p.y -= p.v * dt; if (p.y < -0.15) { p.y = 1.15; p.x = rnd(0, 1); }
        var x = (p.x + 0.02 * Math.sin(t * 0.3 + p.ph)) * w, y = p.y * hgt, r = p.r * m, a = 0.22 + 0.12 * Math.sin(t * 0.6 + p.ph);
        var g = c.createRadialGradient(x, y, 0, x, y, r); g.addColorStop(0, 'hsla(' + p.hue + ',90%,70%,' + a + ')'); g.addColorStop(0.7, 'hsla(' + p.hue + ',90%,60%,' + a * 0.6 + ')'); g.addColorStop(1, 'hsla(' + p.hue + ',90%,50%,0)');
        c.fillStyle = g; c.beginPath(); c.arc(x, y, r, 0, TAU); c.fill();
      });
      c.globalCompositeOperation = 'source-over';
    } };

  // Rain on glass: a blurry city glow behind beads that gather and slide down.
  FX.rain = { scale:0.75, init:function(st){ st.beads = []; for (var i = 0; i < 260; i++) st.beads.push({ x:rnd(0, 1), y:rnd(0, 1), r:rnd(0.6, 2.4) }); st.drops = []; st.lights = []; for (var j = 0; j < 14; j++) st.lights.push({ x:rnd(0, 1), y:rnd(0.45, 1), r:rnd(0.06, 0.16), hue:[30, 45, 200, 340, 15][j % 5] }); },
    draw:function(c, w, hgt, t, st, dt){
      var g = c.createLinearGradient(0, 0, 0, hgt); g.addColorStop(0, '#0b1018'); g.addColorStop(1, '#141a24'); c.fillStyle = g; c.fillRect(0, 0, w, hgt);
      var m = Math.max(w, hgt);
      st.lights.forEach(function(l, i){ var x = l.x * w, y = l.y * hgt, r = l.r * m, rg = c.createRadialGradient(x, y, 0, x, y, r); rg.addColorStop(0, 'hsla(' + l.hue + ',80%,60%,' + (0.18 + 0.04 * Math.sin(t * 0.5 + i)) + ')'); rg.addColorStop(1, 'hsla(' + l.hue + ',80%,50%,0)'); c.fillStyle = rg; c.fillRect(x - r, y - r, r * 2, r * 2); });
      if (Math.random() < dt * 1.6 && st.drops.length < 28) st.drops.push({ x:rnd(0, 1), y:rnd(-0.1, 0.4), r:rnd(2.5, 5), v:rnd(0.05, 0.16), wob:rnd(0, TAU) });
      c.fillStyle = 'rgba(200,220,255,0.18)';
      st.beads.forEach(function(b){ c.beginPath(); c.arc(b.x * w, b.y * hgt, b.r, 0, TAU); c.fill(); });
      for (var i = st.drops.length - 1; i >= 0; i--) {
        var d = st.drops[i]; d.y += d.v * dt * (0.6 + 0.4 * Math.sin(t * 3 + d.wob)); d.x += 0.0008 * Math.sin(t + d.wob);
        var x = d.x * w, y = d.y * hgt;
        c.strokeStyle = 'rgba(190,210,255,0.10)'; c.lineWidth = d.r * 0.9; c.beginPath(); c.moveTo(x, y - d.r * 10); c.lineTo(x, y); c.stroke();
        var rg = c.createRadialGradient(x - d.r * 0.3, y - d.r * 0.3, 0, x, y, d.r); rg.addColorStop(0, 'rgba(255,255,255,0.55)'); rg.addColorStop(1, 'rgba(150,180,230,0.15)');
        c.fillStyle = rg; c.beginPath(); c.arc(x, y, d.r, 0, TAU); c.fill();
        if (d.y > 1.05) st.drops.splice(i, 1);
      }
    } };

  // Lava lamp: metaballs computed on a small grid (per pixel at ~1/6 size), coloured by field strength.
  FX.lava = { scale:0.16, init:function(st){ st.b = []; for (var i = 0; i < 7; i++) st.b.push({ x:rnd(0.2, 0.8), y:rnd(0.1, 0.9), r:rnd(0.08, 0.15), vx:rnd(-0.02, 0.02), vy:rnd(-0.05, 0.05) }); },
    draw:function(c, w, hgt, t, st, dt){
      st.b.forEach(function(b){ b.y += b.vy * dt; b.x += b.vx * dt; if (b.y < 0.1 || b.y > 0.9) b.vy *= -1; if (b.x < 0.15 || b.x > 0.85) b.vx *= -1; b.y = Math.max(0.09, Math.min(0.91, b.y)); });
      if (!st.img || st.img.width !== w || st.img.height !== hgt) st.img = c.createImageData(w, hgt);
      var d = st.img.data, ar = w / hgt, bs = st.b, n = bs.length;
      for (var y = 0; y < hgt; y++) {
        var ny = y / hgt;
        for (var x = 0; x < w; x++) {
          var nx = x / w, f = 0;
          for (var k = 0; k < n; k++) { var b = bs[k], dx = (nx - b.x) * ar, dy = ny - b.y; f += b.r * b.r / (dx * dx + dy * dy + 0.0001); }
          var o = (y * w + x) * 4, v = Math.min(1, Math.max(0, (f - 0.7) * 1.6));
          d[o] = 30 + v * 225; d[o + 1] = 8 + v * (90 + 60 * ny); d[o + 2] = 40 + (1 - v) * 40 + v * 30; d[o + 3] = 255;
        }
      }
      c.putImageData(st.img, 0, 0);
    } };

  // Gradient flow: slowly turning colour field (cheapest of all).
  FX.gradient = { scale:0.1, init:function(){},
    draw:function(c, w, hgt, t){
      var a = t * 0.05, g = c.createLinearGradient(w / 2 + Math.cos(a) * w, hgt / 2 + Math.sin(a) * hgt, w / 2 - Math.cos(a) * w, hgt / 2 - Math.sin(a) * hgt);
      g.addColorStop(0, 'hsl(' + ((t * 4) % 360) + ',55%,22%)'); g.addColorStop(0.5, 'hsl(' + ((t * 4 + 90) % 360) + ',55%,16%)'); g.addColorStop(1, 'hsl(' + ((t * 4 + 200) % 360) + ',55%,20%)');
      c.fillStyle = g; c.fillRect(0, 0, w, hgt);
    } };

  var LIST = [['aurora', 'Aurora', 'Ribbons of northern lights', '🌌'], ['stars', 'Starfield', 'Drifting through the stars', '✨'], ['waves', 'Waves', 'Gentle swells at dusk', '🌊'], ['bokeh', 'Bokeh lights', 'Soft floating lights', '🔆'], ['rain', 'Rain on glass', 'Raindrops on a window at night', '🌧️'], ['lava', 'Lava lamp', 'Slow, warm blobs', '🫧'], ['gradient', 'Colour flow', 'A slowly shifting gradient', '🎨']];

  /** Start an effect inside host (filled edge to edge). Returns { stop }. */
  function start(host, kind, opts){
    opts = opts || {};
    var fx = FX[kind] || FX.aurora, canvas = document.createElement('canvas'), c = canvas.getContext('2d', { alpha:false }), st = {}, raf = 0, last = 0, t0 = 0, stopped = false;
    canvas.className = 'vc-fx-canvas'; canvas.setAttribute('aria-hidden', 'true'); host.appendChild(canvas);
    var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    var dprCap = Math.min(window.devicePixelRatio || 1, opts.maxDpr || 1.25), fpsGap = 1000 / (opts.fps || 30), w = 0, hgt = 0, t = rnd(0, 100);
    function size(){
      var r = host.getBoundingClientRect(), s = fx.scale * (fx.scale >= 1 ? dprCap : 1);
      w = Math.max(16, Math.round(r.width * s)); hgt = Math.max(16, Math.round(r.height * s));
      // A very large screen gets proportionally fewer pixels for the per-pixel effect.
      if (kind === 'lava' && w * hgt > 90000) { var k = Math.sqrt(90000 / (w * hgt)); w = Math.round(w * k); hgt = Math.round(hgt * k); }
      canvas.width = w; canvas.height = hgt;
      if (!st.ready) { fx.init(st, w, hgt); st.ready = true; }
      fx.draw(c, w, hgt, t, st, 0);
    }
    function frame(now){
      raf = 0; if (stopped) return;
      if (!host.isConnected) { stop(); return; }
      if (now - last >= fpsGap) {
        var dt = last ? Math.min(0.1, (now - last) / 1000) : 0; last = now; t += dt;
        fx.draw(c, w, hgt, t, st, dt);
      }
      schedule();
    }
    function schedule(){ if (!stopped && !raf && !document.hidden && !reduce) raf = requestAnimationFrame(frame); }
    function vis(){ if (document.hidden) { if (raf) cancelAnimationFrame(raf); raf = 0; } else { last = 0; schedule(); } }
    var ro = window.ResizeObserver ? new ResizeObserver(function(){ size(); }) : null;
    if (ro) ro.observe(host); else window.addEventListener('resize', size);
    document.addEventListener('visibilitychange', vis);
    // Reduced motion: one still frame, refreshed rarely so the colours still change over the evening.
    var still = reduce ? setInterval(function(){ if (!document.hidden) { t += 20; fx.draw(c, w, hgt, t, st, 0); } }, 60000) : 0;
    size(); schedule();
    function stop(){ stopped = true; if (raf) cancelAnimationFrame(raf); raf = 0; clearInterval(still); document.removeEventListener('visibilitychange', vis); if (ro) ro.disconnect(); else window.removeEventListener('resize', size); canvas.remove(); }
    return { stop:stop };
  }
  root.VaultcastFx = { list:LIST, start:start, init:function(){} };
})(this);
