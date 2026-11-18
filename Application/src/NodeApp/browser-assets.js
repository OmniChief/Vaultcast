'use strict';

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

function mountBrowserAssets(app, prefix, { root, handle, unavailable }) {
  const manifest = readFile(join(root, 'manifest.json'), 'utf8').then(JSON.parse).catch(() => null);
  app.get(prefix + '/manifest', handle(async (_request, response, _pool, _bcrypt, settings) => {
    const template = await manifest;
    if (!template) return unavailable(response);
    const name = String(settings.app_name ?? 'Vaultcast');
    response.type('application/manifest+json').send(JSON.stringify({
      ...template, name, short_name: Array.from(name).slice(0, 24).join(''), start_url: '/', scope: '/',
      icons: (template.icons ?? []).map(icon => ({ ...icon, src: '/' + icon.src.replace(/^\/?/, '') })),
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
