'use strict';

const { createHash } = require('crypto');
const { constants } = require('fs');
const { open, readFile, realpath } = require('fs').promises;
const { extname, join, relative, isAbsolute, sep } = require('path');
const { pipeline } = require('stream/promises');
const { documents, enabled } = require('./account-data');

const installation = 'player_assets_4_2_1_hls_1_5_17';
const types = new Map(Object.entries({
  '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json',
  '.wasm': 'application/wasm', '.svg': 'image/svg+xml', '.png': 'image/png',
  '.woff': 'font/woff', '.woff2': 'font/woff2', '.ttf': 'font/ttf',
}));

const escapeHtml = value => String(value).replace(/[&<>"']/g, character =>
  ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]);
const inlineJson = value => JSON.stringify(value).replace(/</g, '\\u003c');

function shellConfig(settings, installed, base) {
  const local = settings.player_assets?.source === 'local' && enabled(installed?.complete);
  const player = base + 'netplay/application/player-assets/';
  const dependencies = {
    source: local ? 'local' : 'cdn',
    emulatorVersion: '4.2.1',
    emulatorData: local ? player + 'emulatorjs/4.2.1/data/' : 'https://cdn.emulatorjs.org/4.2.1/data/',
    hls: local ? player + 'hls/1.5.17/hls.min.js' : 'https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js',
  };
  for (const [key, name] of Object.entries({
    stalkerVod: 'stalker-vod', stalkerCountries: 'stalker-countries', stalkerStream: 'stalker-stream',
    mpegts: 'mpegts-1-7-3', stalkerAc3: 'stalker-ac3', stalkerAc3Worker: 'stalker-ac3-worker',
  })) dependencies[key] = base + 'assets/js/' + name + '.js';
  const publicBase = String(settings.public_base_url ?? '').trim();
  return {
    __STREAM_BASE__: base,
    __VAULTCAST_APPLICATION_BASE__: base + 'netplay/application',
    __SITE_NAME__: String(settings.app_name ?? 'Vaultcast'),
    __PUBLIC_BASE_URL__: /^https?:\/\//i.test(publicBase) ? publicBase.replace(/\/+$/, '') + '/' : '',
    VaultcastDependencies: dependencies,
  };
}

function mountBrowserAssets(app, prefix, { root, handle, unavailable }) {
  const shell = readFile(join(root, 'src/Views/app.html'), 'utf8').catch(() => null);
  const version = readFile(join(root, 'VERSION'), 'utf8').then(value => value.trim()).catch(() => '');
  app.get([prefix + '/shell', '/watch/*'], handle(async (_request, response, pool, _bcrypt, settings, current) => {
    const [template, build] = await Promise.all([shell, version]);
    if (!template || !/^\d+\.\d+\.\d+$/.test(build)) return unavailable(response);
    const { [installation]: installed } = await documents(pool, [installation]);
    const config = shellConfig(settings, installed, response.locals.base);
    config.__VAULTCAST_VIEWER__ = _request.path.startsWith('/watch/');
    if (!current.user && _request.path === prefix + '/shell' && settings.auth?.lan_guest_access !== false) return response.sendStatus(404);
    if (!current.user) config.__STREAM_STARTUP__ = { me: { ok: true, authenticated: false, user: null, guest_browsing: false } };
    const cacheKey = createHash('sha256').update(build).digest('hex').slice(0, 16);
    for (const key of ['stalkerVod', 'stalkerCountries', 'stalkerStream', 'mpegts', 'stalkerAc3', 'stalkerAc3Worker']) {
      config.VaultcastDependencies[key] += '?v=' + cacheKey;
    }
    const fields = {
      '{{BASE_HREF}}': response.locals.base,
      '{{SITE_NAME}}': escapeHtml(config.__SITE_NAME__),
      '{{VERSION}}': build,
      '{{ASSET_VERSION}}': cacheKey,
      '{{ASSET_BOOTSTRAP}}': response.locals.base + 'assets/js/browser-bootstrap.js?v=' + cacheKey,
      '<!-- VAULTCAST_CONFIG -->': '<script>Object.assign(window,' + inlineJson(config) + ');</script>',
      '<!-- VAULTCAST_PAGE -->': '',
    };
    const html = template.replace(/{{[A-Z_]+}}|<!-- VAULTCAST_(?:CONFIG|PAGE) -->/g, token => fields[token] ?? token);
    return response.set({
      'X-Frame-Options': 'SAMEORIGIN',
      'Referrer-Policy': 'same-origin',
    }).type('html').send(html);
  }, true));
  const manifest = readFile(join(root, 'manifest.json'), 'utf8').then(JSON.parse).catch(() => null);
  app.get(prefix + '/manifest', handle(async (_request, response, _pool, _bcrypt, settings) => {
    const template = await manifest;
    if (!template) return unavailable(response);
    const name = String(settings.app_name ?? 'Vaultcast');
    response.type('application/manifest+json').send(JSON.stringify({
      ...template, name, short_name: Array.from(name).slice(0, 24).join(''), start_url: response.locals.base, scope: response.locals.base,
      icons: (template.icons ?? []).map(icon => ({ ...icon, src: response.locals.base + icon.src.replace(/^\/?/, '') })),
    }));
  }));

  app.get(prefix + '/player-assets/*', handle(async (request, response, pool, _bcrypt, settings) => {
    const name = request.params[0];
    if (name.length > 512 || !name.split('/').every(part => /^[A-Za-z0-9_-][A-Za-z0-9_.-]*$/.test(part))) {
      return response.status(404).json({ ok: false, error: 'Player file not found' });
    }
    const { [installation]: job } = await documents(pool, [installation]);
    if (!enabled(job?.complete) || !Object.values(job.files ?? {}).some(file => file?.path === name)) {
      return response.status(404).json({ ok: false, error: 'Player file not found' });
    }
    return async () => {
      let file;
      try {
        const data = await realpath(join(root, 'data'));
        const directory = join(data, 'players');
        if (await realpath(directory) !== directory) return unavailable(response);
        const resolved = await realpath(join(directory, name));
        const child = relative(directory, resolved);
        if (!child || child === '..' || child.startsWith('..' + sep) || isAbsolute(child)) return unavailable(response);
        file = await open(resolved, constants.O_RDONLY | (constants.O_NONBLOCK ?? 0) | (constants.O_NOFOLLOW ?? 0));
        const info = await file.stat();
        if (!info.isFile()) return unavailable(response);
        if (response.destroyed) return;
        const type = types.get(extname(name)) ?? 'application/octet-stream';
        if (request.method !== 'HEAD' && process.platform === 'linux' && enabled(settings.streaming?.nginx_accel)) {
          const uri = resolved.split('/').map(encodeURIComponent).join('/');
          return response.set({ 'Content-Type': type, 'Cache-Control': 'public, max-age=31536000, immutable',
            'X-Accel-Redirect': '/_vaultcast_media' + uri }).end();
        }
        response.set({
          'Content-Type': type,
          'Content-Length': String(info.size),
          'Cache-Control': 'public, max-age=31536000, immutable',
          'X-Accel-Buffering': 'no',
        });
        if (request.method === 'HEAD' || !info.size) return response.end();
        await pipeline(file.createReadStream({ autoClose: false }), response);
      } catch (error) {
        if (!response.headersSent && !response.destroyed) {
          if (error.code === 'ENOENT' || error.code === 'ENOTDIR') {
            return response.status(404).json({ ok: false, error: 'Player file missing; download the dependencies again.' });
          }
          return unavailable(response);
        }
      } finally {
        if (file) await file.close().catch(() => {});
      }
    };
  }));
}

module.exports = mountBrowserAssets;
