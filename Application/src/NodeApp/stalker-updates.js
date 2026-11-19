'use strict';

const { join } = require('path');
const { mkdir, writeFile, unlink } = require('fs').promises;
const { randomBytes } = require('crypto');
const { providerResponse } = require('../Workers/media-relay');
const { jobLock } = require('../Workers/stalker-download');
const { mediaUrl, publicAddresses, phpJson, configHash, providerBody } = require('./stalker-provider');
const { cached } = require('./profile-policy');
const { sha, now, enabled } = require('./account-data');
const { q, doc, save, take, cache, transaction, provider, inbox, notify } = require('./stalker-store');

const bad = (message) => Object.assign(new Error(message), { userError: true });
function portal(value) {
  const url = mediaUrl(value);
  if (url.search || url.hash) throw bad('Enter a portal URL without a query or fragment.');
  return value;
}
function values(text, decode) {
  text = decode(text);
  return {
    urls: [
      ...new Set(
        (text.match(/https?:\/\/[^\s<>"']+/gi) ?? []).map((value) => value.replace(/[.,;)\]}]+$/g, '')),
      ),
    ],
    macs: [
      ...new Set(
        (text.match(/\b(?:[0-9a-f]{2}[:-]){5}[0-9a-f]{2}\b/gi) ?? []).map((value) =>
          value.replace(/-/g, ':').toUpperCase(),
        ),
      ),
    ],
  };
}
const attrs = (node) => Object.fromEntries((node.attrs ?? []).map((attr) => [attr.name, attr.value]));
const textContent = (root) => {
  const chunks = [],
    pending = [root];
  while (pending.length) {
    const node = pending.pop();
    if (node.nodeName === '#text') chunks.push(node.value);
    else for (let i = (node.childNodes?.length ?? 0) - 1; i >= 0; i--) pending.push(node.childNodes[i]);
  }
  return chunks.join('');
};
const elements = (root) => {
  const out = [],
    pending = [root];
  while (pending.length) {
    const node = pending.pop();
    if (node.tagName) out.push(node);
    if (out.length > 60000) throw bad('The provider update page has too many elements.');
    for (let i = (node.childNodes?.length ?? 0) - 1; i >= 0; i--) pending.push(node.childNodes[i]);
  }
  return out;
};
function fingerprint(entries, fields, mappings) {
  const byId = new Map([...fields.urls, ...fields.macs].map((field) => [field.id, field])),
    selected = {};
  for (const [key, mapping] of Object.entries(mappings))
    if (mapping.fields)
      selected[key] = {
        url: byId.get(mapping.fields.url)?.value ?? null,
        mac: byId.get(mapping.fields.mac)?.value ?? null,
      };
  return sha(phpJson([entries, Object.keys(selected).length ? selected : []]));
}
function shape(fields) {
  return sha(phpJson([fields.urls.map((field) => field.id), fields.macs.map((field) => field.id)]));
}
function sameSettings(a, b) {
  return a.url === b.url && phpJson(a.mappings ?? []) === phpJson(b.mappings ?? []) && !enabled(a.paused);
}

module.exports = function updatesWorker(db, root) {
  const { html, decode } = db.services(),
    runtime = join(root, 'data/runtime');
  async function enable() {
    await mkdir(runtime, { recursive: true, mode: 0o700 });
    const s = await db.run((pool) => doc(pool, 'stalker-update-settings'));
    await unlink(join(runtime, 'stalker-updates.enabled')).catch(() => {});
    if (s.url && !enabled(s.paused))
      await writeFile(join(runtime, 'stalker-updates.enabled-v2'), '1', { mode: 0o600 });
    else await unlink(join(runtime, 'stalker-updates.enabled-v2')).catch(() => {});
  }
  async function fetchPage(value, signal) {
    let url = mediaUrl(value);
    for (let hop = 0; hop < 5; hop++) {
      signal?.throwIfAborted();
      const ips = await publicAddresses(url);
      const response = await providerResponse(
        {
          ip: ips[0],
          purpose: 'metadata',
          ua: 'Vaultcast/Stalker',
          headers: { accept: 'text/html,application/xhtml+xml' },
        },
        url,
        'GET',
        signal ? AbortSignal.any([signal, AbortSignal.timeout(12000)]) : AbortSignal.timeout(12000),
      );
      try {
        if (response.statusCode >= 300 && response.statusCode < 400 && response.headers.location) {
          url = mediaUrl(new URL(response.headers.location, url).href);
          continue;
        }
        if (response.statusCode !== 200) throw bad('The provider update page could not be read.');
        return (await providerBody(response, 2 * 1024 * 1024)).toString('utf8');
      } finally {
        response.destroy();
      }
    }
    throw bad('Too many provider update-page redirects.');
  }
  async function discover(url, signal) {
    const body = await fetchPage(url, signal),
      document = html.parse(body);
    for (const element of elements(document))
      if (['script', 'style', 'noscript'].includes(element.tagName) && element.parentNode)
        element.parentNode.childNodes = element.parentNode.childNodes.filter((node) => node !== element);
    const all = elements(document),
      idCounts = new Map(),
      nameCounts = new Map();
    const paths = new WeakMap([[document, '']]),
      summaries = new WeakMap(),
      nodes = [],
      tree = [[document, 0]];
    const nameOf = (node) => node.tagName ?? (node.nodeName === '#text' ? 'text()' : node.nodeName);
    while (tree.length) {
      const [node, depth] = tree.pop();
      nodes.push(node);
      if (depth > 256 || nodes.length > 120000) throw bad('The provider update page is too complex.');
      const counts = new Map(),
        positions = new Map();
      for (const child of node.childNodes ?? [])
        counts.set(nameOf(child), (counts.get(nameOf(child)) ?? 0) + 1);
      for (const child of node.childNodes ?? []) {
        const name = nameOf(child),
          position = (positions.get(name) ?? 0) + 1;
        positions.set(name, position);
        const path = paths.get(node) + '/' + name + (counts.get(name) > 1 ? '[' + position + ']' : '');
        if (path.length > 4096) throw bad('The provider update page is too deeply nested.');
        paths.set(child, path);
      }
      for (let index = (node.childNodes?.length ?? 0) - 1; index >= 0; index--)
        tree.push([node.childNodes[index], depth + 1]);
    }
    const nodePath = (node) => paths.get(node) ?? '';
    for (const element of all) {
      const a = attrs(element);
      if (a.id != null) idCounts.set(a.id, (idCounts.get(a.id) ?? 0) + 1);
      if (['input', 'textarea'].includes(element.tagName) && a.name != null) {
        const key = element.tagName + '|' + a.name;
        nameCounts.set(key, (nameCounts.get(key) ?? 0) + 1);
      }
    }
    const fields = { urls: [], macs: [] },
      fieldAttrs = new Set(['href', 'value', 'data-clipboard-text', 'data-copy', 'data-url', 'data-mac']);
    let order = 0;
    const pending = [document];
    while (pending.length && order < 30000) {
      const node = pending.pop();
      const candidates = [];
      if (node.tagName)
        for (const attribute of node.attrs ?? [])
          if (fieldAttrs.has(attribute.name))
            candidates.push({
              element: node,
              text: attribute.value,
              suffix: '@' + attribute.name,
              path: nodePath(node) + '/@' + attribute.name,
            });
      if (node.nodeName === '#text' && node.value.trim() && node.parentNode?.tagName)
        candidates.push({
          element: node.parentNode,
          text: node.value,
          suffix: 'text()',
          path: nodePath(node),
        });
      for (const candidate of candidates) {
        order++;
        if (order > 30000) break;
        if (Buffer.byteLength(candidate.text) > 16000) continue;
        const element = candidate.element,
          a = attrs(element);
        let locator = candidate.path,
          stable = false,
          anchor = element;
        while (anchor?.tagName) {
          const attributes = attrs(anchor);
          if (attributes.id && idCounts.get(attributes.id) === 1) {
            locator = 'id:' + attributes.id + '|' + candidate.path.slice(nodePath(anchor).length);
            stable = anchor === element;
            break;
          }
          if (
            anchor === element &&
            ['input', 'textarea'].includes(anchor.tagName) &&
            attributes.name != null &&
            nameCounts.get(anchor.tagName + '|' + attributes.name) === 1
          ) {
            locator = 'name:' + anchor.tagName + ':' + attributes.name + '|' + candidate.suffix;
            stable = true;
            break;
          }
          anchor = anchor.parentNode;
        }
        const context = [a['aria-label'] ?? '', a.name ?? '', a.id ?? '']
            .join(' ')
            .replace(/\s+/gu, ' ')
            .trim(),
          found = values(candidate.text, decode);
        for (const type of ['urls', 'macs'])
          for (const [slot, value] of found[type].entries()) {
            if (fields[type].length >= 250) continue;
            let valid = true;
            if (type === 'urls')
              try {
                portal(value);
              } catch (error) {
                valid = false;
              }
            fields[type].push({
              id: sha(type + '|' + locator + '|' + slot),
              value,
              stable: stable && found[type].length === 1,
              order,
              context: take(context, 100),
              valid,
            });
          }
      }
      for (let index = (node.childNodes?.length ?? 0) - 1; index >= 0; index--)
        pending.push(node.childNodes[index]);
    }
    const blocks = [];
    for (let index = nodes.length - 1; index >= 0; index--) {
      const node = nodes[index];
      let text = node.nodeName === '#text' ? node.value : '',
        attributes = '';
      let hasContainers = false,
        hasInputs = false;
      for (const attribute of node.attrs ?? [])
        if (fieldAttrs.has(attribute.name)) attributes += ' ' + attribute.value;
      for (const child of node.childNodes ?? []) {
        const info = summaries.get(child);
        text = text == null || info.text == null ? null : text + info.text;
        attributes = attributes == null || info.attributes == null ? null : attributes + info.attributes;
        if (text != null && Buffer.byteLength(text) > 16000) text = null;
        if (attributes != null && Buffer.byteLength(attributes) > 16000) attributes = null;
        hasContainers ||= ['div', 'tr', 'li', 'p', 'pre'].includes(child.tagName) || info.hasContainers;
        hasInputs ||=
          ['input', 'textarea'].includes(child.tagName) ||
          (child.attrs ?? []).some((attribute) => attribute.name === 'data-clipboard-text') ||
          info.hasInputs;
      }
      summaries.set(node, { text, attributes, hasContainers, hasInputs });
    }
    // Preserve the legacy selector order, including overlapping containers.
    for (const selector of ['tr', 'li', 'pre', 'p', 'section', 'article', 'leaf-div', 'input-div'])
      for (const element of all) {
        const info = summaries.get(element);
        const matches =
          selector === element.tagName ||
          (selector === 'leaf-div' && element.tagName === 'div' && !info.hasContainers) ||
          (selector === 'input-div' && element.tagName === 'div' && info.hasInputs);
        if (!matches || blocks.length >= 5000) continue;
        if (info.text == null || info.attributes == null) continue;
        const text = info.text + info.attributes;
        if (Buffer.byteLength(text) <= 16000) {
          blocks.push(text);
        }
      }
    blocks.push(...textContent(document).split(/\r?\n/));
    const found = new Map();
    for (const block of blocks) {
      const pair = values(block, decode);
      if (pair.urls.length !== 1 || pair.macs.length !== 1) continue;
      const url = pair.urls[0],
        mac = pair.macs[0];
      try {
        portal(url);
      } catch (error) {
        continue;
      }
      const key = sha(url + '|' + mac);
      if (found.has(key)) continue;
      const escape = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      const label = block
        .replace(new RegExp(escape(url) + '|' + escape(mac), 'gi'), '')
        .replace(/\b(?:url|mac|portal|address|link)\s*:?/giu, '')
        .replace(/\s+/gu, ' ')
        .replace(/^[\s:|,\-]+|[\s:|,\-]+$/g, '');
      const position = body.toLowerCase().indexOf(mac.toLowerCase());
      found.set(key, {
        url,
        mac,
        label: take(label, 100),
        position: position < 0 ? Number.MAX_SAFE_INTEGER : position,
      });
    }
    const entries = [...found.values()].sort((a, b) => a.position - b.position),
      labels = new Map();
    for (const entry of entries) labels.set(entry.label, (labels.get(entry.label) ?? 0) + 1);
    for (const [index, entry] of entries.entries()) {
      entry.stable = Boolean(entry.label && labels.get(entry.label) === 1);
      entry.key = entry.stable ? 'label:' + sha(entry.label) : 'position:' + (index + 1);
      entry.name = entry.stable ? entry.label : 'Provider connection ' + (index + 1);
      delete entry.position;
    }
    return { entries, fields };
  }
  async function replaceFeed(connection, state, old, replacement, backup = true) {
    if (backup) await save(connection, 'stalker-update-backup:' + old.id, old);
    state.feeds = state.feeds.map((feed) => (feed.id === old.id ? replacement : feed));
    state.updatedAt = new Date().toISOString();
    await save(connection, 'live_iptv', state);
    if (configHash(old) !== configHash(replacement)) {
      await q(
        connection,
        "UPDATE vaultcast_stalker_jobs SET status='scan_required',next_run=0 WHERE feed_id=?",
        [old.id],
      );
      await notify(connection, replacement);
    }
  }
  async function check(signal) {
    const lock = await jobLock(join(runtime, 'stalker-updates.check.lock'));
    if (!lock) return { busy: true };
    let original;
    try {
      original = await db.run((pool) => doc(pool, 'stalker-update-settings'));
      if (!original.url || enabled(original.paused) || Number(original.next_check ?? 0) > now())
        return { nextCheck: Number(original.next_check ?? 0) };
      const { entries, fields } = await discover(original.url, signal),
        byKey = new Map(entries.map((entry) => [entry.key, entry])),
        urls = new Map(fields.urls.map((field) => [field.id, field])),
        macs = new Map(fields.macs.map((field) => [field.id, field])),
        pageShape = shape(fields);
      let updated = 0,
        review = 0;
      for (const [key, mapping] of Object.entries(original.mappings ?? {})) {
        const current = await db.run((pool) => doc(pool, 'stalker-update-settings'));
        if (!sameSettings(current, original)) return { skipped: true };
        if (!enabled(mapping.auto)) continue;
        let entry = byKey.get(key);
        if (mapping.fields) {
          const u = urls.get(mapping.fields.url),
            m = macs.get(mapping.fields.mac);
          if (
            !u ||
            !m ||
            !u.valid ||
            (mapping.stable && (!u.stable || !m.stable)) ||
            (!mapping.stable && (!mapping.allow_position || mapping.fields.shape !== pageShape))
          ) {
            review++;
            continue;
          }
          entry = { url: u.value, mac: m.value, stable: mapping.stable };
        } else if (
          !entry ||
          (!entry.stable && (!mapping.allow_position || Number(mapping.count) !== entries.length))
        ) {
          review++;
          continue;
        }
        const state = await db.run((pool) => doc(pool, 'live_iptv')),
          old = state.feeds?.find((feed) => feed.id === mapping.feed);
        if (
          !old ||
          old.type !== 'stalker' ||
          !enabled(old.enabled) ||
          (old.url === entry.url && old.mac === entry.mac)
        )
          continue;
        const replacement = { ...old, url: entry.url, mac: entry.mac };
        try {
          const client = await provider(root, replacement, signal);
          await client.session(true);
        } catch (error) {
          if (signal?.aborted) throw error;
          review++;
          continue;
        }
        const saved = await db.run((pool) =>
          transaction(pool, async (connection) => {
            await q(
              connection,
              "SELECT name FROM vaultcast_documents WHERE name IN ('live_iptv','stalker-update-settings') ORDER BY name FOR UPDATE",
            );
            if (!sameSettings(await doc(connection, 'stalker-update-settings'), original)) return false;
            const fresh = await doc(connection, 'live_iptv'),
              current = fresh.feeds?.find((feed) => feed.id === old.id);
            if (phpJson(current ?? null) !== phpJson(old)) return false;
            await replaceFeed(connection, fresh, old, replacement);
            return true;
          }),
        );
        if (saved) updated++;
        else review++;
      }
      const mapped = new Set(Object.keys(original.mappings ?? {}));
      for (const mapping of Object.values(original.mappings ?? {}))
        if (mapping.fields) {
          const u = urls.get(mapping.fields.url)?.value,
            m = macs.get(mapping.fields.mac)?.value;
          for (const entry of entries) if (entry.url === u && entry.mac === m) mapped.add(entry.key);
        }
      review += entries.filter((entry) => !mapped.has(entry.key)).length;
      const nextFingerprint = fingerprint(entries, fields, original.mappings ?? {});
      await db.run((pool) =>
        transaction(pool, async (connection) => {
          await q(connection, 'SELECT name FROM vaultcast_documents WHERE name=? FOR UPDATE', [
            'stalker-update-settings',
          ]);
          const current = await doc(connection, 'stalker-update-settings');
          if (!sameSettings(current, original)) return;
          if (
            (entries.length || Object.keys(original.mappings ?? {}).length) &&
            nextFingerprint !== original.fingerprint &&
            original.owner
          )
            await inbox(
              connection,
              original.owner,
              'stalker-provider-updates',
              updated ? 'Provider connections updated' : 'Provider connection details need review',
              updated
                ? updated + ' connections were updated. Review Stalker settings for details.'
                : 'The provider page or your selected fields changed. Review the discovered details in Stalker settings.',
            );
          await save(connection, 'stalker-update-settings', {
            ...current,
            fingerprint: nextFingerprint,
            last_check: now(),
            next_check: now() + 86400,
            message:
              'Checked ' +
              entries.length +
              ' discovered connections. Updated ' +
              updated +
              '.' +
              (review ? ' ' + review + ' need review; existing details were retained.' : ''),
          });
        }),
      );
      return { nextCheck: now() + 86400, updated, review };
    } catch (error) {
      if (original && !signal?.aborted)
        await db.run((pool) =>
          transaction(pool, async (connection) => {
            await q(connection, 'SELECT name FROM vaultcast_documents WHERE name=? FOR UPDATE', [
              'stalker-update-settings',
            ]);
            const current = await doc(connection, 'stalker-update-settings');
            if (sameSettings(current, original))
              await save(connection, 'stalker-update-settings', {
                ...current,
                last_check: now(),
                next_check: now() + 86400,
                message: 'Update page could not be checked. Existing connection details were retained.',
              });
          }),
        );
      if (signal?.aborted) throw error;
      return { nextCheck: now() + 86400 };
    } finally {
      await lock.close();
    }
  }
  async function operation(body, owner) {
    const op = String(body.operation ?? 'configure');
    if (op === 'discover') {
      const s = await db.run((pool) => doc(pool, 'stalker-update-settings')),
        url = String(body.url ?? '').trim() || s.url;
      if (!url) throw bad('Enter the provider update-page URL.');
      const result = await discover(url, AbortSignal.timeout(60000)),
        token = randomBytes(16).toString('hex');
      await cache(root, 'stalker-discovery:' + token, { url, ...result }, 3600);
      return {
        ok: true,
        token,
        ...result,
        mappings: s.url === url ? (s.mappings ?? []) : [],
        message:
          result.entries.length +
          ' suggested pairs, ' +
          result.fields.urls.length +
          ' URL fields and ' +
          result.fields.macs.length +
          ' MAC fields found. Review the suggestions or pair fields below. Textboxes and copy fields are included; pages populated only by JavaScript still require manual setup.',
      };
    }
    if (op === 'accept') {
      if (typeof body.token !== 'string' || !/^[a-f0-9]{32}$/.test(body.token))
        throw bad('Discovery expired. Check the page again.');
      const preview = await cached(root, 'stalker-discovery:' + body.token);
      if (!preview) throw bad('Discovery expired. Check the page again.');
      if (!Array.isArray(body.selected) || !body.selected.length || body.selected.length > 250)
        throw bad('Select at least one discovered connection.');
      const count = await db.run((pool) =>
        transaction(pool, async (connection) => {
          await q(
            connection,
            "SELECT name FROM vaultcast_documents WHERE name IN ('live_iptv','stalker-update-settings') ORDER BY name FOR UPDATE",
          );
          const s = await doc(connection, 'stalker-update-settings'),
            state = await doc(connection, 'live_iptv');
          if (!Array.isArray(state.feeds)) throw bad('Save your Live provider settings first.');
          const mappings = s.url === preview.url ? { ...s.mappings } : {},
            used = new Set(),
            usedKeys = new Set(),
            urls = new Map(preview.fields.urls.map((field) => [field.id, field])),
            macs = new Map(preview.fields.macs.map((field) => [field.id, field]));
          for (const selection of body.selected) {
            let entry = preview.entries.find((entry) => entry.key === selection.key),
              fieldMapping = null;
            if (selection.url_field != null && selection.mac_field != null) {
              const u = urls.get(selection.url_field),
                m = macs.get(selection.mac_field);
              if (!u || !m || !u.valid)
                throw bad('Choose a valid portal URL field and MAC field from this discovery.');
              portal(u.value);
              entry = {
                key: 'fields:' + sha(u.id + '|' + m.id),
                url: u.value,
                mac: m.value,
                stable: u.stable && m.stable,
                name: 'Provider connection',
              };
              fieldMapping = { url: u.id, mac: m.id, shape: shape(preview.fields) };
              if (selection.auto && !entry.stable && !selection.allow_position)
                throw bad(
                  'Confirm following these fields by page position, or turn off automatic updates for this pair.',
                );
            }
            if (!entry) throw bad('Unknown discovered connection.');
            if (usedKeys.has(entry.key)) throw bad('Select each page field pair only once.');
            usedKeys.add(entry.key);
            const id = String(selection.feed ?? '') || 'stalker-' + randomBytes(6).toString('hex');
            if (!/^[\w.-]{1,120}$/.test(id)) throw bad('Invalid Stalker connection.');
            if (used.has(id)) throw bad('Map each existing connection only once.');
            used.add(id);
            const old = state.feeds.find((feed) => feed.id === id);
            if (old && old.type !== 'stalker') throw bad('Choose a Stalker connection.');
            const next = old
              ? {
                  ...old,
                  name: take(String(selection.name ?? '').trim() || entry.name, 500),
                  url: entry.url,
                  mac: entry.mac,
                  enabled: true,
                }
              : {
                  type: 'stalker',
                  mac: entry.mac,
                  username: '',
                  password: '',
                  serial: '',
                  deviceId: '',
                  deviceId2: '',
                  id,
                  name: take(String(selection.name ?? '').trim() || entry.name, 500),
                  url: entry.url,
                  enabled: true,
                  preinstalled: false,
                };
            if (old) {
              await save(connection, 'stalker-update-backup:' + id, old);
              state.feeds = state.feeds.map((feed) => (feed.id === id ? next : feed));
            } else state.feeds.push(next);
            if (!old || configHash(old) !== configHash(next) || !enabled(old.enabled)) {
              await q(
                connection,
                "UPDATE vaultcast_stalker_jobs SET status='scan_required',next_run=0 WHERE feed_id=?",
                [id],
              );
              await notify(connection, next);
            }
            for (const [key, mapping] of Object.entries(mappings))
              if (mapping.feed === id) delete mappings[key];
            mappings[entry.key] = {
              feed: id,
              auto: enabled(selection.auto),
              stable: entry.stable,
              allow_position: enabled(selection.allow_position),
              count: preview.entries.length,
              ...(fieldMapping ? { fields: fieldMapping } : {}),
            };
          }
          state.updatedAt = new Date().toISOString();
          await save(connection, 'live_iptv', state);
          await save(connection, 'stalker-update-settings', {
            url: preview.url,
            owner: String(owner),
            mappings,
            paused: enabled(s.paused),
            fingerprint: fingerprint(preview.entries, preview.fields, mappings),
            next_check: now() + 86400,
            last_check: now(),
            message:
              'Connections and selected page fields saved.' +
              (enabled(s.paused) ? ' Daily checks remain paused.' : ' Daily checks are enabled.'),
          });
          return used.size;
        }),
      );
      await enable();
      return { ok: true, count };
    }
    if (!['configure', 'check', 'rollback'].includes(op)) throw bad('Unknown update-page operation.');
    await db.run((pool) =>
      transaction(pool, async (connection) => {
        await q(
          connection,
          "SELECT name FROM vaultcast_documents WHERE name IN ('live_iptv','stalker-update-settings') ORDER BY name FOR UPDATE",
        );
        const s = await doc(connection, 'stalker-update-settings');
        if (op === 'configure') {
          if (body.url != null && String(body.url).trim()) {
            const url = String(body.url).trim();
            mediaUrl(url);
            if (s.url !== url) s.mappings = {};
            s.url = url;
          }
          s.owner = String(owner);
          s.paused = enabled(body.paused);
          s.next_check ??= now();
          s.message = s.paused
            ? 'Daily provider checks are paused.'
            : 'Daily provider checks are enabled. Discover connections to map any entries you want updated automatically.';
        } else if (op === 'check') {
          if (!s.url) throw bad('Save an update-page URL first.');
          if (enabled(s.paused)) throw bad('Resume daily checks first.');
          s.next_check = now();
          s.message = 'Provider check queued. Refresh status shortly.';
        } else {
          const id = String(body.feed ?? '');
          if (!/^[\w.-]{1,120}$/.test(id)) throw bad('Invalid Stalker connection.');
          const prior = await doc(connection, 'stalker-update-backup:' + id, null);
          if (!prior) throw bad('No previous connection details are saved.');
          const state = await doc(connection, 'live_iptv'),
            old = state.feeds?.find((feed) => feed.id === id);
          if (!old) throw bad('Stalker connection no longer exists.');
          await replaceFeed(connection, state, old, prior, false);
          for (const mapping of Object.values(s.mappings ?? {}))
            if (mapping.feed === id) mapping.auto = false;
        }
        await save(connection, 'stalker-update-settings', s);
      }),
    );
    await enable();
    return { ok: true, ...(op === 'check' ? { queued: true } : {}) };
  }
  return { tick: check, operation, discover, enable };
};
