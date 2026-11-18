'use strict';

const { readFile, stat } = require('fs').promises;
const { join } = require('path');
const { listOffline, sendOffline, validId, ready, controlOffline } = require('./stalker-offline');
const { areaAllowed } = require('./library');
const { authorized, parentalStatus } = require('./profile-policy');
const { documents, enabled, now, parse, publicUser, sha } = require('./account-data');

const kinds = ['live', 'vod', 'series'];
const secretFields = ['mac', 'username', 'password', 'serial', 'deviceId', 'deviceId2'];
const complete = status => ['complete', 'skipped'].includes(status);

function configHash(feed) {
  // Match PHP's json_encode and the field order produced by iptv_normalize_feed.
  const identity = Object.fromEntries([...secretFields, 'id', 'url'].map(key => [key, feed[key] ?? '']));
  const json = JSON.stringify(identity).replace(/\//g, '\\/').replace(/[\u0080-\uffff]/g,
    value => '\\u' + value.charCodeAt(0).toString(16).padStart(4, '0'));
  return sha(json);
}

function savedFeeds(state) {
  if (!state) return [];
  if (!Array.isArray(state.feeds)) return null;
  const ids = new Set();
  for (const feed of state.feeds) {
    if (!feed || typeof feed.id !== 'string' || !/^[\w.-]+$/.test(feed.id) || ids.has(feed.id)) return null;
    ids.add(feed.id);
    if (feed.type !== 'stalker') continue;
    if (typeof feed.name !== 'string' || !feed.name || feed.name !== feed.name.trim() ||
        typeof feed.url !== 'string' || !/^https?:\/\//i.test(feed.url) || feed.url !== feed.url.trim() ||
        typeof feed.mac !== 'string' || !/^(?:[A-F0-9]{2}:){5}[A-F0-9]{2}$/.test(feed.mac) ||
        secretFields.slice(1).some(key => typeof feed[key] !== 'string' || Buffer.byteLength(feed[key]) > 512 || /[\r\n\0]/.test(feed[key]))) {
      return null;
    }
    try {
      const url = new URL(feed.url);
      if (url.username || url.password || /[?#\r\n]/.test(feed.url)) return null;
    } catch (error) {
      return null;
    }
  }
  return state.feeds.filter(feed => feed.type === 'stalker');
}

module.exports = function mountStalkerStatus(app, prefix, { root, handle, unavailable, json }) {
  const version = readFile(join(root, 'VERSION'), 'utf8').then(text => text.trim()).catch(() => '');
  const runtime = join(root, 'data/runtime');
  const fresh = async name => {
    const info = await stat(join(runtime, name)).catch(() => null);
    return Boolean(info?.isFile() && Date.now() - info.mtimeMs < 180000);
  };
  async function workerRunning(name, legacy) {
    const marker = 'node-stalker-' + name + '.heartbeat';
    if (process.platform === 'linux' && await fresh(marker) &&
        (await readFile(join(runtime, marker), 'utf8').catch(() => '')).trim() === await version) return true;
    return fresh(legacy);
  }

  const operation = handle(async (request, response, pool, _bcrypt, settings, current) => {
      const { action } = request.params;
      if (enabled(settings.features_explicit?.live) && Object.hasOwn(settings.features ?? {}, 'live') &&
          !enabled(settings.features.live)) {
        return response.status(404).json({ ok: false, error: 'Live channels are disabled in Settings → Integrations.' });
      }
      if (!authorized(response, current)) return;
      const user = current.user;
      const parental = await parentalStatus(root, pool, current, user, now());
      if (!areaAllowed(user, 'live', parental)) {
        return response.status(403).json({ ok: false, code: 'area_denied', area: 'live',
          error: 'Your account does not have access to Live.' });
      }
      if (parental.restricted) {
        return response.status(403).json({ ok: false, error: 'Provider catalogues are unavailable for restricted profiles.' });
      }
      if (['stalker_offline', 'stalker_offline_file', 'stalker_cancel', 'stalker_delete'].includes(action)) {
        const { capabilities } = publicUser(user, settings, await documents(pool, ['permission_roles']), now());
        const denied = permission => response.status(403).json({ ok: false, error: 'This action requires the ' + permission + ' permission.' });
        if (!capabilities.ingest_view) return denied('ingest_view');
        if (!areaAllowed(user, 'library', parental)) {
          return response.status(403).json({ ok: false, error: 'Library access is required for offline downloads.' });
        }
        if (action === 'stalker_offline') return response.json({ ok: true, items: await listOffline(pool, root) });
        if (action === 'stalker_delete') {
          if (user.role !== 'admin') return response.status(403).json({ ok: false, error: 'Administrator access required.' });
        } else if (!capabilities.downloads_request) return denied('downloads_request');
        const id = request.method === 'POST' ? request.body?.id : request.query.id;
        if (!validId(id)) return response.status(422).json({ ok: false, error: 'Invalid offline download.' });
        if (request.method === 'POST') {
          response.locals.mutationStarted = true;
          const result = await controlOffline(pool, root, id, action);
          return result.error ? response.status(502).json({ ok: false, error: result.error }) : response.json(result);
        }
        const key = 'stalker-offline:' + id;
        const [rows] = await pool.execute('SELECT payload FROM vaultcast_documents WHERE name=?', [key]);
        const job = rows.length ? parse(rows[0].payload) : null;
        if (!job) return response.status(502).json({ ok: false, error: 'Offline download not found.' });
        if (job.id !== id || !ready(job.status)) return response.status(502).json({ ok: false,
          error: 'The video is not ready to download. Open Downloads to see its status.' });
        return () => sendOffline(request, response, root, job);
      }
      if (action !== 'stalker') {
        const docs = await documents(pool, ['permission_roles']);
        if (!publicUser(user, settings, docs, now()).capabilities.iptv_manage) {
          return response.status(403).json({ ok: false, error: 'This action requires the iptv_manage permission.' });
        }
      }
      if (action === 'stalker_update_status') {
        const { 'stalker-update-settings': saved = {} } = await documents(pool, ['stalker-update-settings']);
        const worker = await workerRunning('updates', 'stalker-updates.heartbeat');
        return response.json({ ok: true, status: { configured: enabled(saved?.url), paused: enabled(saved?.paused),
          mappings: saved?.mappings ?? [], last_check: saved?.last_check ?? null, next_check: saved?.next_check ?? null,
          message: saved?.message ?? 'Add your provider update page to discover its connections.', worker } });
      }

      const { live_iptv } = await documents(pool, ['live_iptv']);
      const feeds = savedFeeds(live_iptv);
      if (!feeds) return unavailable(response);
      const keys = new Map(feeds.map(feed => [feed.id, configHash(feed)]));
      const accounts = feeds.length ? await documents(pool, [...keys.values()].map(key => 'stalker-account-' + key)) : {};
      const expired = id => {
        const expiry = accounts['stalker-account-' + keys.get(id)]?.expires;
        return enabled(expiry) && Number(expiry) <= now();
      };
      if (action === 'stalker') {
        return response.json({ ok: true, connections: feeds.map(feed => ({
          type: 'stalker', id: feed.id, name: feed.name, url: feed.url,
          enabled: feed.enabled == null || enabled(feed.enabled), preinstalled: enabled(feed.preinstalled),
          configured: true, expired: expired(feed.id),
        })) });
      }

      const worker = await workerRunning('catalogue', 'stalker-library.heartbeat');
      // In mixed installations the old status route can relaunch a stalled worker.
      if (!worker && await stat(join(runtime, 'stalker-library.enabled')).catch(() => null)) return unavailable(response);
      const active = worker ? await readFile(join(runtime, 'stalker-library.active'), 'utf8').then(JSON.parse).catch(() => null) : null;
      const summary = enabled(request.query.summary);
      const activeFeeds = new Map(feeds.filter(feed => feed.enabled == null || enabled(feed.enabled)).map(feed => [feed.id, feed]));
      const [jobs] = await pool.execute(
        'SELECT feed_id,kind,config,generation,status,processed,error,next_run,last_hash FROM vaultcast_stalker_jobs');
      const selectionKey = 'stalker-catalogue-folder-selection', resetKey = 'stalker-catalogue-reset';
      const docs = await documents(pool, summary ? [resetKey] : [resetKey, selectionKey]);
      const foldersByJob = new Map();
      if (jobs.length) {
        const scope = ' FROM vaultcast_stalker_folders f JOIN vaultcast_stalker_jobs j ' +
          'ON j.feed_id=f.feed_id AND j.kind=f.kind AND j.generation=f.generation ';
        const [folders] = await pool.execute(summary
          ? "SELECT f.feed_id,f.kind,COUNT(*) AS total,SUM(f.status IN ('complete','skipped')) AS done" +
            scope + "WHERE f.folder_id<>'' GROUP BY f.feed_id,f.kind"
          : 'SELECT f.feed_id,f.kind,f.folder_id,f.title,f.status,f.next_page,f.processed,f.total,f.last_success,f.error,f.next_run' +
            scope + "ORDER BY f.folder_id='*',f.folder_id");
        for (const { feed_id, kind, ...data } of folders) {
          const key = feed_id + '/' + kind;
          if (!foldersByJob.has(key)) foldersByJob.set(key, []);
          foldersByJob.get(key).push(data);
        }
      }
      const sources = [], seen = new Set();
      for (const { config, generation, ...job } of jobs) {
        const id = job.feed_id;
        const feed = activeFeeds.get(id);
        const key = id + '/' + job.kind;
        seen.add(key);
        const source = { ...job, name: feed?.name ?? 'Removed connection', scanned: Number(job.processed) };
        if (feed && config !== keys.get(id)) source.status = 'scan_required';
        if (job.kind === 'series' && source.status === 'complete' && job.last_hash !== 'episodes_complete') source.status = 'scan_required';
        if (feed && expired(id)) source.status = 'expired';
        source.active = Array.isArray(active) && active.length === 3 && active[0] === id &&
          active[1] === job.kind && active[2] === generation &&
          ['queued', 'running', 'partial', 'waiting'].includes(source.status) && Number(job.next_run) <= now();
        const folders = foldersByJob.get(key) ?? [];
        if (summary) {
          source.foldersTotal = Number(folders[0]?.total ?? 0);
          source.foldersComplete = Number(folders[0]?.done ?? 0);
        } else {
          const selection = docs[selectionKey]?.sources?.[key];
          source.folderMode = selection?.mode === 'include' ? 'include' : 'all';
          source.folderIds = source.folderMode === 'include'
            ? Object.values(selection.ids ?? {}).filter(id => typeof id === 'string') : [];
          const selected = new Set(source.folderIds);
          source.folders = folders.filter(folder => folder.folder_id !== '').map(folder => ({ ...folder,
            selected: source.folderMode === 'all' || (folder.folder_id !== '*' && selected.has(folder.folder_id)) }));
          source.foldersSelected = source.folders.filter(folder => folder.selected).length;
          source.foldersTotal = source.folders.length;
          source.foldersComplete = source.folders.filter(folder => complete(folder.status)).length;
          source.currentFolder = folders.find(folder => !complete(folder.status)) ?? null;
        }
        sources.push(source);
      }
      for (const feed of activeFeeds.values()) {
        for (const kind of kinds) {
          if (!seen.has(feed.id + '/' + kind)) sources.push({ feed_id: feed.id, kind, name: feed.name, scanned: 0,
            status: expired(feed.id) ? 'expired' : 'scan_required' });
        }
      }
      return response.json({ ok: true, sources, reset: docs[resetKey] ?? { state: 'idle' }, worker });
    }, true);
  app.get(prefix + '/live/:action(stalker|stalker_catalogue_status|stalker_update_status|stalker_offline|stalker_offline_file)', operation);
  app.post(prefix + '/live/:action(stalker_cancel|stalker_delete)', (request, response, next) => {
    if (!request.is('application/json')) return unavailable(response);
    json(request, response, error => error ? unavailable(response) : next());
  }, operation);
};
