'use strict';

const { realpath } = require('fs').promises;
const { join, relative, isAbsolute, sep } = require('path');

// Public entry point for running the installed Node service without a reverse proxy.
module.exports = function siteRouter(express, backend, root, base) {
  const site = express();
  const routes = express.Router();
  site.disable('x-powered-by');
  if (base !== '/') site.get('/', (_req, res) => res.redirect(302, base));
  site.use(base, routes);

  routes.use((req, res, next) => {
    res.locals.directHttp = true;
    res.set('X-Content-Type-Options', 'nosniff');
    let path;
    try { path = decodeURIComponent(req.path); }
    catch { return res.sendStatus(400); }
    if (/[\\\x00-\x1f]/.test(path) || path.split('/').some(part => part.startsWith('.')) ||
        /^\/(?:src|data|deploy|docs|tests)(?:\/|$)/i.test(path) ||
        /\.(?:php\d*|phtml|sql|md|log|sh|ps1|cmd|ya?ml|lock|bak)(?:\/|$)/i.test(path)) return res.sendStatus(403);
    next();
  });

  const missing = (_req, res) => res.status(404).json({ ok: false, error: 'No Node route for this request.' });
  function native(path) {
    return (req, res, next) => {
      const query = req.url.indexOf('?');
      req.url = path(req.path) + (query < 0 ? '' : req.url.slice(query));
      backend.handle(req, res, error => error ? next(error) : missing(req, res));
    };
  }
  routes.use('/api', native(path => '/application' + (path === '/' ? '/status' : path)));
  routes.use('/netplay', (req, res, next) => {
    if (!/^\/(?:application|watch|web-assets)(?:\/|$)/.test(req.path)) return missing(req, res);
    next();
  }, native(path => path));

  const assets = join(root, 'assets');
  routes.get('/assets/*', async (req, res, next) => {
    const name = req.params[0];
    if (!/\.(?:js|css|svg|png|jpe?g|webp|ico|woff2?)$/i.test(name)) return res.sendStatus(404);
    try {
      const [directory, file] = await Promise.all([realpath(assets), realpath(join(assets, name))]);
      const child = relative(directory, file);
      if (!child || isAbsolute(child) || child === '..' || child.startsWith('..' + sep)) return res.sendStatus(403);
      res.sendFile(file, { dotfiles: 'deny' }, error => { if (error) next(error); });
    } catch (error) {
      if (['ENOENT', 'ENOTDIR'].includes(error.code)) return res.sendStatus(404);
      next(error);
    }
  });
  routes.get('/sw.js', (_req, res) => res.set('Cache-Control', 'no-cache').sendFile(join(root, 'sw.js')));
  routes.get('/manifest.json', native(() => '/application/manifest'));
  routes.get('/', (_req, res) => res.redirect(302, base + 'library'));
  routes.get('*', native(path => '/watch' + path));
  site.use(missing);
  site.use((error, _req, res, next) => {
    if (res.headersSent) return next(error);
    return res.sendStatus(error.statusCode || 500);
  });
  return site;
};
