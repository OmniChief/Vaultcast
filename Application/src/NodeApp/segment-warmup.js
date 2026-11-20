'use strict';

const { randomBytes } = require('crypto');
const { open, stat } = require('fs').promises;
const { join } = require('path');
const { lockFile } = require('../Workers/file-lock');
const { q, transaction } = require('./queries');
const { documents, parse, sha } = require('./account-data');
const { authorized, cached, cacheResult } = require('./profile-policy');
const { mediaSignature } = require('./library');
const { libraryFile, probe } = require('./media-probe');
const { context, eligible, match } = require('./segment-match');

const slot = 'node-segment-warmup';
const jobKey = id => 'maintenance-job:' + id;
const cancelKey = id => 'maintenance-cancel:' + id;
const pending = job => job && ['queued','running'].includes(job.status);
const date = () => new Date().toISOString();
const write = (pool,key,value) => q(pool, 'INSERT INTO vaultcast_documents(name,payload) VALUES(?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),revision=revision+1', [key,JSON.stringify(value)]);

async function currentJob(pool) {
  const id = (await documents(pool,[slot]))[slot];
  return id ? (await documents(pool,[jobKey(id)]))[jobKey(id)] : null;
}

async function lockSlot(conn) {
  await q(conn,"INSERT INTO vaultcast_documents(name,payload) VALUES(?,'null') ON DUPLICATE KEY UPDATE name=name",[slot]);
  return currentJob(conn);
}

async function queueJob(conn,job) {
  const [count] = await q(conn,"SELECT COUNT(*) AS total FROM vaultcast_items WHERE media_kind='video'");
  Object.assign(job, { runner:'node', cursor:'', failures:0,
    progress:{phase:'Intro / credits timings',done:0,total:Number(count.total),file:''},
    result:{ok:true,processed:0,matched:0,preserved:0,unresolved:0,missing_duration:0,failed:0} });
  await write(conn,jobKey(job.id),job);
  await write(conn,slot,job.id);
  return job;
}

async function inspect(root, db, item, settings) {
  if (!eligible(item)) return 'unresolved';
  const { input, facts } = await db.run(async pool => ({
    input: await context(pool,item),
    facts: (await q(pool,'SELECT duration FROM vaultcast_media_facts WHERE item_id=? AND sig=?',[item.id,mediaSignature(item)]))[0],
  }));
  if (input.saved?.source === 'Admin') return 'preserved';
  let duration = Number(facts?.duration) || Number(input.saved?.duration) || 0;
  if (duration <= 0) {
    const key = 'segment-duration:' + sha(mediaSignature(item) + item.id);
    duration = Number((await cached(root,key))?.duration) || 0;
    if (duration <= 0) {
      const section = Object.values(settings.sections ?? {}).find(row => row?.id === item.section);
      const file = section?.path && await libraryFile(item.path,section.path).catch(() => null);
      if (file && (await stat(file)).isFile()) duration = Number((await probe(root,file,settings)).duration) || 0;
      if (duration > 0) await cacheResult(root,key,{duration},604800);
    }
  }
  if (!(duration > 0)) return 'missing_duration';
  const result = await match(root,db,item,input,duration);
  if (result.http_status === 429) return 'limited';
  if (result.http_status === 0 || result.http_status >= 500) return 'failed';
  if (result.source === 'Admin') return 'preserved';
  return result.segments.length ? 'matched' : 'unresolved';
}

module.exports = function mountWarmup(app,prefix,{root,db,handle,json,updating}) {
  let active = false, timer;
  function schedule(delay) {
    if (!timer) timer = setTimeout(() => { timer = null; void run(); },delay).unref();
  }
  async function run() {
    if (active) return;
    active = true;
    let lock, delay = 10000;
    try {
      if (!db.ready() || await updating()) return;
      if (!pending(await db.run(currentJob))) return;
      lock = await open(join(root,'data/maintenance.lock'),'a');
      try { await lockFile(lock,0); } catch { delay = 2500; return; }
      const job = await db.run(currentJob);
      if (!pending(job)) return;
      const key = jobKey(job.id);
      const checkpoint = async () => {
        // A reset may remove this job while a media probe or network lookup is running.
        const result = await db.run(pool => q(pool,
          'UPDATE vaultcast_documents SET payload=?,revision=revision+1 WHERE name=?',[JSON.stringify(job),key]));
        return result.affectedRows === 1;
      };
      const settings = (await db.run(pool => documents(pool,['settings']))).settings;
      if (!settings) throw new Error('Library settings unavailable');
      const rows = await db.run(pool => q(pool,"SELECT id,payload FROM vaultcast_items WHERE media_kind='video' AND id>? ORDER BY id LIMIT 12",[job.cursor]));
      job.started_at ??= date();
      job.status = 'running';
      if (!await checkpoint()) return;
      for (const row of rows) {
        if (await updating()) return;
        if ((await db.run(pool => documents(pool,[cancelKey(job.id)])))[cancelKey(job.id)]) {
          job.status = 'cancelled';
          break;
        }
        let item, outcome;
        try {
          item = parse(row.payload);
          outcome = item?.id === row.id && /^[a-f0-9]{8,64}$/.test(row.id) ? await inspect(root,db,item,settings) : 'failed';
        }
        catch { outcome = 'failed'; }
        job.cursor = row.id;
        job.progress.file = item?.title || row.id;
        job.progress.at = date();
        job.progress.done = ++job.result.processed;
        job.result[outcome === 'limited' ? 'unresolved' : outcome]++;
        job.failures = outcome === 'failed' ? job.failures + 1 : 0;
        if (outcome === 'limited' || job.failures >= 3) {
          job.result.partial = true;
          job.result.warning = outcome === 'limited' ? 'TheIntroDB rate limit reached. Run again later.' : 'The timing provider or library is unavailable. Run again later.';
          job.status = 'complete';
          break;
        }
        if (!await checkpoint()) return;
        await new Promise(resolve => setTimeout(resolve,300));
      }
      if (job.status === 'running' && rows.length < 12) job.status = 'complete';
      if (!pending(job)) job.finished_at = date();
      await checkpoint();
      delay = pending(job) ? 300 : 10000;
    } catch (error) {
      console.warn('Library timing preparation paused:', error.code || error.name);
    } finally {
      try { if (lock) await lock.close(); } finally { active = false; schedule(delay); }
    }
  }

  const endpoint = prefix + '/admin/segment-warmup';
  const operate = handle(async (req,res,pool,_bcrypt,_settings,current) => {
    if (!authorized(res,current)) return;
    if (current.user.role !== 'admin') return res.status(403).json({ok:false,error:'Administrator access required.'});
    if (req.method === 'GET') return res.json({ok:true,job:await currentJob(pool)});
    if (!req.is('application/json')) return res.sendStatus(415);
    const operation = req.body?.operation;
    if (!['start','cancel'].includes(operation)) return res.status(400).json({ok:false,error:'Unknown timing task action.'});
    res.locals.mutationStarted = true;
    const job = await transaction(pool,async conn => {
      const previous = await lockSlot(conn);
      if (operation === 'cancel') {
        if (pending(previous) && previous.id === req.body.id) await write(conn,cancelKey(previous.id),true);
        return previous;
      }
      if (pending(previous)) return previous;
      const id = date().replace(/\D/g,'').slice(0,14) + '-' + randomBytes(6).toString('hex');
      return queueJob(conn,{ id, task:'segments', status:'queued', requested_by:current.user.username, created_at:date() });
    });
    clearTimeout(timer); timer = null; schedule(0);
    res.json({ok:true,job});
  },true);
  app.get(endpoint,operate);
  app.post(endpoint,json,operate);
  schedule(1000);
  // Claim existing managed-queue requests under their original ID so existing pollers and cancellations keep working.
  async function adopt(id) {
    return db.run(async pool => {
      const key = jobKey(id);
      if ((await documents(pool,[key]))[key]?.task !== 'segments') return 'other';
      return transaction(pool,async conn => {
        const previous = await lockSlot(conn);
        const [row] = await q(conn,'SELECT payload FROM vaultcast_documents WHERE name=? FOR UPDATE',[key]);
        const job = row && parse(row.payload);
        if (!job || job.runner === 'node' || job.status !== 'queued') return 'claimed';
        if (pending(previous)) return 'busy';
        await queueJob(conn,job);
        return 'claimed';
      });
    });
  }
  return { active: () => active, adopt };
};
