'use strict';

const { readFile, stat } = require('fs').promises;
const { join } = require('path');
const { listOffline, sendOffline, validId, ready, controlOffline, enqueueOffline, retryOffline } = require('./stalker-offline');
const { areaAllowed } = require('./library');
const { authorized, parentalStatus } = require('./profile-policy');
const { documents, enabled, now, parse, publicUser } = require('./account-data');

const { configHash } = require('./stalker-provider');
const { indexed, direct, categoryExport, detail } = require('./stalker-browse');
const { episodeBrowse } = require('./stalker-episodes');
const { bound, take } = require('./stalker-store');
const { guidePage } = require('./stalker-guide');
const { channelDetail, channelPage, channelPageFromProvider, liveCategories, livePageEntry, liveSnapshot, byCountry } = require('./stalker-live-browse');

const { takeTicket } = require('../Workers/media-relay');
const playback = require('./stalker-playback');
const connections = require('./stalker-connections');
const lineup = require('./stalker-lineup');
const { diagnostics } = require('./stalker-diagnostics');
const { provider } = require('./stalker-store');
const diagnosticActions = ['stalker_epg_fields','stalker_live_fields','stalker_vod_fields','stalker_vod_probe'];
const playbackActions = ['stalker_play','stalker_stream','stalker_check_media'];

const browseActions = ['stalker_vod_catalogue','stalker_vod_detail','stalker_browse','stalker_countries','stalker_live_categories','stalker_live_channels','stalker_live_guide','stalker_channel'];
const kinds = ['live', 'vod', 'series'];
const { savedFeeds } = require('./stalker-feeds');
const complete = status => ['complete', 'skipped'].includes(status);

async function viewerCatalogue(
  request,
  response,
  pool,
  root,
  feeds,
  expired,
  rawExport,
  libraryAllowed,
  decode,
  network,
) {
  const { action } = request.params;
  const opts = request.query;
  const active = feeds.filter((feed) => enabled(feed.enabled));
  const fail = (status, error) => response.status(status).json({ ok: false, error });
  if (action === 'stalker_vod_catalogue' && enabled(opts.indexed)) {
    return response.json(
      await indexed(
        pool,
        root,
        active.filter((feed) => !expired(feed.id)),
        opts,
        libraryAllowed,
        decode,
      ),
    );
  }
  const feed =
    active.find((feed) => feed.id === opts.feed) ??
    (action === 'stalker_vod_catalogue' && !opts.feed ? active[0] : null);
  if (action === 'stalker_vod_catalogue' && !active.length) {
    return response.json({
      ok: true,
      items: [],
      total: 0,
      hasMore: false,
      facets: { category: [] },
      connections: [],
      shared: false,
    });
  }
  if (action !== 'stalker_countries' && !feed) return fail(422, 'Select an enabled Stalker connection.');
  const kind = opts.kind ?? (action === 'stalker_browse' ? 'live' : 'vod');
  if (action === 'stalker_channel')
    return response.json(await channelDetail(pool, root, feed, opts, expired(feed.id)));
  if (action === 'stalker_vod_detail') {
    const id = String(opts.id ?? '');
    const item = await detail(pool, root, feed, id, decode);
    if (!item)
      return fail(502, 'This catalogue entry has expired. Return to Stalker and refresh the catalogue.');
    return response.json({ ok: true, item });
  }
  if (
    action === 'stalker_countries' ||
    (action === 'stalker_browse' && kind === 'live') ||
    action === 'stalker_live_categories' ||
    action === 'stalker_live_channels' ||
    action === 'stalker_live_guide'
  ) {
    // Snapshot processing needs neither a database connection nor the update lock.
    return network(response, async (signal) => {
      try {
        if (action === 'stalker_countries' || action === 'stalker_browse') {
          return response.json(
            await byCountry(root, action === 'stalker_countries' ? active : [feed], expired, opts),
          );
        }
        if (action === 'stalker_live_guide')
          return response.json(await guidePage(root, feed, opts, expired(feed.id), decode, signal));
        const data = expired(feed.id) ? null : await liveSnapshot(root, feed);
        if ((!data || enabled(opts.refresh)) && !expired(feed.id)) {
          try {
            if (action === 'stalker_live_categories')
              return response.json({ ok: true, categories: await liveCategories(root, feed, signal, enabled(opts.refresh)), catalogueLoading: false });
            return response.json(await channelPageFromProvider(root, feed, opts, signal));
          } catch (error) {
            // An explicit refresh must not hide an otherwise usable saved list.
            if (!data) throw error;
          }
        }
        return response.json(
          action === 'stalker_live_channels'
            ? channelPage(data, feed, opts)
            : { ok: true, categories: data?.categories ?? [], catalogueLoading: !data },
        );
      } catch (error) {
        if ([422, 502].includes(error.status)) return fail(error.status, error.message);
        throw error;
      }
    }, 60000);
  }
  if (!['vod', 'series'].includes(kind)) return fail(422, 'Choose Movies or Series.');
  if (rawExport)
    return async () => {
      try {
        return response.json(await categoryExport(root, feed, kind));
      } catch {
        return fail(502, 'Could not read categories from this provider.');
      }
    };
  if (expired(feed.id))
    return response.json({
      ok: true,
      items: [],
      total: 0,
      hasMore: false,
      categories: [],
      catalogueLoading: false,
    });
  if (kind === 'series' && opts.parent && !enabled(opts.categories_only))
    return episodeBrowse(pool, root, feed, take(opts.parent, 120),
      bound(opts.page ?? Math.floor(bound(opts.offset, 0, 600000) / 60) + 1, 1, 10000),
      enabled(opts.refresh), decode, network, response);
  return response.json(await direct(pool, root, feed, kind, opts));
}

module.exports = function mountStalkerStatus(app, prefix, { root, handle, unavailable, json, downloads, stalker, services, run }) {
  const version = readFile(join(root, 'VERSION'), 'utf8').then(text => text.trim()).catch(() => '');
  const runtime = join(root, 'data/runtime');
  const fresh = async name => {
    const info = await stat(join(runtime, name)).catch(() => null);
    return Boolean(info?.isFile() && Date.now() - info.mtimeMs < 180000);
  };
  async function workerRunning(name, legacy) {
    const marker = 'node-stalker-' + name + '.heartbeat';
    if (await fresh(marker) &&
        (await readFile(join(runtime, marker), 'utf8').catch(() => '')).trim() === await version) return true;
    return fresh(legacy);
  }

  let providerRequests = 0;
  function network(response, operation, timeout = 120000) {
    return async () => {
      if (response.destroyed) return;
      const controller = new AbortController(),
        stop = () => controller.abort();
      response.once('close', stop);
      providerRequests++;
      try {
        return await operation(AbortSignal.any([controller.signal, AbortSignal.timeout(timeout)]));
      } catch (error) {
        if (response.destroyed) return;
        if (response.headersSent) return response.destroy();
        if (error.retryAt) response.set('Retry-After', String(Math.max(1, error.retryAt - now())));
        const status = error.userError ? 422 : error.expose ? error.status : error.retryAt ? 429 : 502;
        return response
          .status(status)
          .type('application/json')
          .json({
            ok: false,
            error:
              error.userError || error.expose
                ? error.message
                : error.authentication
                  ? 'The provider rejected the saved device or account.'
                  : error.retryAt
                    ? 'The provider has rate limited requests.'
                    : 'The provider request could not be completed.',
          });
      } finally {
        response.removeListener('close', stop);
        providerRequests--;
      }
    };
  }
  async function feedOperation(request, response, pool, settings, user, feeds, live_iptv) {
    const { action } = request.params,
      opts = request.method === 'POST' ? (request.body ?? {}) : request.query;
    const feed = feeds.find((f) => f.id === (action === 'stalker_manage' ? opts.id : opts.feed));
    const fail = (status, error) => response.status(status).json({ ok: false, error });
    if (!feed || (action !== 'stalker_manage' && !feed.enabled))
      return fail(action === 'stalker_stream' ? 404 : 422, 'Select a saved Stalker connection.');
    response.locals.mutationStarted = true;
    if (action === 'stalker_manage')
      return network(response, async (signal) =>
        response.json(await connections.check(await provider(root, feed, signal))),
      );
    const accountKey = 'stalker-account-' + configHash(feed);
    const expiry = (await documents(pool, [accountKey]))[accountKey]?.expires;
    const expired = enabled(expiry) && Number(expiry) <= now();
    if (diagnosticActions.includes(action))
      return network(response, async (signal) =>
        response.json(
          await diagnostics(
            root,
            feed,
            await provider(root, feed, signal),
            action,
            opts,
            services().decode,
            expired,
          ),
        ),
      );
    if (expired) return fail(410, 'This provider subscription has expired.');
    const streaming = action === 'stalker_stream';
    const id = streaming ? opts.channel : opts.id,
      kind = streaming ? 'live' : (opts.kind ?? 'live');
    if (typeof id !== 'string' || !id || !['live', 'series', 'vod'].includes(kind))
      return fail(422, 'Select a valid catalogue entry.');
    if (streaming && opts.ticket != null) {
      const state = await playback.authorizedTicket(root, feed, id, opts.ticket, user.id);
      if (!state) return fail(403, 'Playback selection expired. Select the title again.');
      return network(response, () => playback.serve(root, feed, state, request, response));
    }
    if (streaming) {
      if (opts.resource != null) return fail(403, 'Invalid playback resource.');
      const channelUrl = lineup.channelUrl(feed.id, id);
      const saved = live_iptv?.channels?.some(
        (channel) => (channel.enabled == null || enabled(channel.enabled)) && (channel.url === channelUrl || channel.url === response.locals.base.slice(0,-1) + channelUrl),
      );
      if (
        !saved &&
        !publicUser(user, settings, await documents(pool, ['permission_roles']), now()).capabilities
          .iptv_manage
      )
        return fail(403, 'This action requires the iptv_manage permission.');
    }
    const item = await playback.entry(pool, root, feed, id, kind);
    const hinted = kind === 'live' && typeof opts.category === 'string' &&
      opts.category.length > 0 && opts.category.length <= 120 &&
      /^\d{1,5}$/.test(String(opts.page ?? '')) && Number(opts.page) >= 1 && Number(opts.page) <= 10000;
    if (!item?.cmd && !hinted)
      return fail(422, 'This catalogue entry is unavailable. Refresh the catalogue and select it again.');
    return network(response, async (signal) => {
      const selected = item?.cmd ? item : await livePageEntry(root, feed, opts, id, signal);
      if (!selected?.cmd)
        return fail(422, 'This channel is no longer on its provider page. Refresh Live TV and select it again.');
      if (action === 'stalker_check_media')
        return response.json(
          await playback.checkMedia(root, feed, id, kind, selected, opts.profile ?? 'stb', signal),
        );
      const result = await playback.issue(root, feed, id, kind, selected, user.id, signal, Boolean(response.locals.stalkerSessionHash));
      result.source.url = response.locals.base.slice(0, -1) + result.source.url;
      if (streaming) return response.redirect(307, result.source.url);
      return response.json(result);
    });
  }
  const operation = handle(async (request, response, pool, _bcrypt, settings, current) => {
      const { action } = request.params;
      if (enabled(settings.features_explicit?.live) && Object.hasOwn(settings.features ?? {}, 'live') &&
          !enabled(settings.features.live)) {
        return response.status(404).json({ ok: false, error: 'Live channels are disabled in Settings → Integrations.' });
      }
      if (!authorized(response, current)) return;
      const user = current.user;
      if (response.locals.stalkerSessionHash && String(user.id) !== response.locals.stalkerViewer)
        return response.status(403).json({ ok: false, error: 'TV session changed.' });
      const parental = await parentalStatus(root, pool, current, user, now());
      if (!areaAllowed(user, 'live', parental)) {
        return response.status(403).json({ ok: false, code: 'area_denied', area: 'live',
          error: 'Your account does not have access to Live.' });
      }
      if (parental.restricted) {
        return response.status(403).json({ ok: false, error: 'Provider catalogues are unavailable for restricted profiles.' });
      }
      if (action === 'stalker_art') return response.status(404).json({ ok:false, error:'No locally saved artwork is available.' });
      if (['stalker_offline', 'stalker_offline_file', 'stalker_cancel', 'stalker_delete', 'stalker_download', 'stalker_retry'].includes(action)) {
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
        if (['stalker_download', 'stalker_retry'].includes(action)) {
          if (!enabled(settings.features?.downloads)) return response.status(403).json({ ok: false, error: 'Enable downloads in Settings before saving provider videos.' });
          const body = request.body ?? {};
          if (action === 'stalker_retry' && !validId(body.id)) return response.status(422).json({ ok: false, error: 'Invalid offline download.' });
          let feed;
          if (action === 'stalker_download') {
            const { live_iptv } = await documents(pool, ['live_iptv']);
            const feeds = savedFeeds(live_iptv);
            if (!feeds) return unavailable(response);
            feed = feeds.find(feed => feed.id === body.feed && enabled(feed.enabled));
            if (!feed) return response.status(422).json({ ok: false, error: 'Select an enabled Stalker connection.' });
          }
          response.locals.mutationStarted = true;
          const result = action === 'stalker_retry' ? await retryOffline(pool, root, body.id) :
            await enqueueOffline(pool, root, feed, body, user.id);
          if (result.error) return response.status(502).json({ ok: false, error: result.error });
          response.json(result);
          return () => downloads.wake();
        }
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
      const browsing = browseActions.includes(action);
      const rawExport = ['stalker_vod_catalogue','stalker_browse'].includes(action) && enabled(request.query.raw_categories_export);
      if ((!browsing && action !== 'stalker' && !playbackActions.includes(action)) || rawExport) {
        const docs = await documents(pool, ['permission_roles']);
        if (!publicUser(user, settings, docs, now()).capabilities.iptv_manage) {
          return response.status(403).json({ ok: false, error: 'This action requires the iptv_manage permission.' });
        }
      }
      if (['stalker_updates','stalker_catalogue_control'].includes(action)) {
        response.locals.mutationStarted=true;
        return async () => {
          try {
            if(action==='stalker_updates')return response.json(await stalker.updates(request.body??{},user.id));
            await stalker.catalogueControl(request.body??{});
            request.params.action='stalker_catalogue_status';
            return operation(request,response);
          } catch(error) {
            if(response.headersSent||response.destroyed)return;
            if(error.userError)return response.status(422).json({ok:false,error:error.message});
            // Report a stable database error code without exposing SQL, MACs,
            // portal credentials or other private provider information.
            const code=typeof error.code==='string'&&/^[A-Z][A-Z0-9_]{2,63}$/.test(error.code)?error.code:'';
            const savingFolders=action==='stalker_catalogue_control'&&request.body?.operation==='folders';
            console.warn('Node Stalker operation failed', {operation:String(request.body?.operation||action).slice(0,40),code:code||'unknown'});
            return response.status(502).json({ok:false,error:savingFolders
              ?'Could not confirm the provider folder save'+(code?' ('+code+')':'')+'. Refresh the folder list before retrying.'
              :'The Stalker operation could not be completed'+(code?' ('+code+')':'')+'. Existing checkpoints and settings were retained.'});
          }
        };
      }
      if (action === 'stalker_update_status') {
        const { 'stalker-update-settings': saved = {} } = await documents(pool, ['stalker-update-settings']);
        const worker = await workerRunning('updates', 'stalker-updates.heartbeat');
        return response.json({ ok: true, status: { configured: enabled(saved?.url), paused: enabled(saved?.paused),
          mappings: saved?.mappings ?? [], last_check: saved?.last_check ?? null, next_check: saved?.next_check ?? null,
          message: saved?.message ?? 'Add your provider update page to discover its connections.', worker } });
      }

      if (action === 'stalker_manage' && request.body?.operation !== 'check') {
        response.locals.mutationStarted = true;
        try {
          return response.json(await connections.manage(pool, request.body ?? {}));
        } catch (error) {
          if (!error.userError) throw error;
          return response.status(422).json({ ok: false, error: error.message });
        }
      }
      const { live_iptv } = await documents(pool, ['live_iptv']);
      const feeds = savedFeeds(live_iptv);
      if (!feeds) return unavailable(response);
      if (playbackActions.includes(action) || diagnosticActions.includes(action) || action === 'stalker_manage')
        return feedOperation(request, response, pool, settings, user, feeds, live_iptv);
      const keys = new Map(feeds.map(feed => [feed.id, configHash(feed)]));
      const accounts = feeds.length ? await documents(pool, [...keys.values()].map(key => 'stalker-account-' + key)) : {};
      const expired = id => {
        const expiry = accounts['stalker-account-' + keys.get(id)]?.expires;
        return enabled(expiry) && Number(expiry) <= now();
      };
      if (action === 'iptv_preview') {
        const selected = lineup.selection(request.body?.url, response.locals.base);
        if (!selected) return unavailable(response);
        if (!feeds.some(feed => feed.id === selected.feed && feed.enabled)) {
          return response.status(404).json({ ok: false, error: 'Portal source is unavailable.' });
        }
        return response.json({ ok: true, jar: '', source: { url: request.body.url } });
      }
      if (action === 'iptv_probe') {
        const items = request.body?.channels;
        if (!Array.isArray(items) || !items.length || items.length > 25) return unavailable(response);
        const status = (await documents(pool, ['live_iptv_status'])).live_iptv_status;
        if (status == null && await stat(join(root,'data/live/iptv_status.json')).catch(() => null)) return unavailable(response);
        const prepared = await lineup.probeItems(pool, root, feeds, expired, items, response.locals.base);
        if (!prepared) return unavailable(response);
        response.locals.mutationStarted = true;
        return network(response, async signal => response.json(await lineup.check(root, run, prepared, signal)), 30000 * items.length);
      }
      if (action === 'stalker') {
        return response.json({ ok: true, connections: feeds.map(feed => ({
          type: 'stalker', id: feed.id, name: feed.name, url: feed.url,
          enabled: feed.enabled == null || enabled(feed.enabled), preinstalled: enabled(feed.preinstalled),
          configured: true, expired: expired(feed.id),
        })) });
      }

      if (browsing) return viewerCatalogue(request, response, pool, root, feeds, expired, rawExport,
        areaAllowed(user,'library',parental), services().decode, network);

      const worker = await workerRunning('catalogue', 'stalker-library.heartbeat');
      // A status request can wake the native scheduler after a service restart.
      if (!worker && await stat(join(runtime, 'stalker-library.enabled')).catch(() => null)) stalker.wake();
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
        if (job.kind === 'series' && source.status === 'complete' && !['episodes_complete', 'titles_complete'].includes(job.last_hash)) source.status = 'scan_required';
        if (feed && expired(id)) source.status = 'expired';
        const activeJobs = Array.isArray(active) && Array.isArray(active[0]) ? active : [active];
        source.active = activeJobs.some(jobKey => Array.isArray(jobKey) && jobKey.length === 3 &&
          jobKey[0] === id && jobKey[1] === job.kind && jobKey[2] === generation) &&
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
  // Server-rendered TV pages delegate only the exact selection in a single-use local ticket.
  app.get(prefix + '/compat/stalker/:ticket', async (request, response) => {
    const ticket = await takeTicket(request.params.ticket, 'stalker-compat').catch(() => null);
    if (!ticket || !/^[a-f0-9]{64}$/.test(ticket.sessionHash) ||
        !['stalker_live_categories','stalker_live_channels','stalker_vod_catalogue','stalker_play'].includes(ticket.action)) {
      return response.status(403).json({ ok: false, error: 'Invalid TV selection.' });
    }
    response.locals.stalkerSessionHash = ticket.sessionHash;
    response.locals.stalkerViewer = ticket.viewer;
    request.params.action = ticket.action;
    request.query = ticket.selection;
    return operation(request, response);
  });
  app.get(prefix + '/live/:action(stalker|stalker_catalogue_status|stalker_update_status|stalker_offline|stalker_offline_file|stalker_art|' + browseActions.concat(diagnosticActions).join('|') + ')', operation);
  app.get(prefix + '/live/:action(stalker_stream)', (request,response) => {
    if (request.headers['sec-fetch-site'] === 'cross-site') return response.sendStatus(403);
    return operation(request,response);
  });
  app.post(prefix + '/live/:action(stalker_cancel|stalker_delete|stalker_download|stalker_retry|stalker_updates|stalker_catalogue_control|stalker_manage|stalker_play|stalker_check_media|iptv_preview|iptv_probe)', (request, response, next) => {
    if (!request.is('application/json')) return unavailable(response);
    json(request, response, error => error ? unavailable(response) : next());
  }, operation);
  return () => providerRequests > 0;
};
