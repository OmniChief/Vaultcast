'use strict';

const { sha } = require('./account-data');
const { groups, page, bound, take } = require('./stalker-store');
const { liveSnapshot } = require('./stalker-live-browse');
const { invalid } = require('./stalker-connections');

const words = (s) => new Set(s.split(' '));
const titleFields = words(
  'id imdb tmdb name o_name year movie_year release_date premiered category_id genre_id genre genres genres_str director actors cast description descr plot summary overview time duration country language original_language rating_imdb rating_mpaa rating age hd high_quality added tmdb_id imdb_id kinopoisk_id season season_number series_number episode episode_number episodes_count seasons_count audio_languages subtitle_languages',
);
const infoFields = new Set([
  ...titleFields,
  ...words('category genre_id_1 genre_id_2 genre_id_3 genre_id_4 is_series audio_tracks subtitles'),
]);
const liveFields = words(
  'name number tv_genre_id group_title xmltv_id epg_id epg_enabled has_archive archive archive_days archive_range tv_archive_duration enable_tv_archive allow_pvr allow_local_pvr allow_remote_pvr allow_local_timeshift country language hd quality aspect_ratio description tv_genre genre censored is_favorite status',
);
const sampleFields = words(
  'name number tv_genre_id xmltv_id epg_id archive archive_days tv_archive_duration enable_tv_archive allow_pvr allow_local_pvr allow_remote_pvr allow_local_timeshift hd quality censored',
);
const numericFields = words(
  'number archive archive_days tv_archive_duration enable_tv_archive allow_pvr allow_local_pvr allow_remote_pvr hd censored tv_genre_id',
);
const guideFields = words(
  'name title descr description start_timestamp stop_timestamp time time_to ch_id category genre duration',
);
const privateName =
  /(?:token|auth|pass|secret|cookie|key|mac|serial|device|url|link|cmd|stream|ticket|host|ip|login|account|user|pin|drm|license|proxy|path|file|play)/i;
const sections = ['info', 'movie_info', 'video_info'];
const record = (value) => value !== null && typeof value === 'object';
const sorted = (map) =>
  Object.fromEntries(Object.entries(map).sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0)));
const type = (value) =>
  value == null
    ? 'NULL'
    : record(value)
      ? 'array'
      : typeof value === 'number'
        ? Number.isInteger(value)
          ? 'integer'
          : 'double'
        : typeof value;

function inspector(feed, decode) {
  const secrets = ['mac', 'url', 'username', 'password', 'serial', 'deviceId', 'deviceId2']
    .map((key) => String(feed[key] ?? '').toLowerCase())
    .filter(Boolean);
  function safe(value, limit) {
    if (value == null || record(value)) return null;
    const text = decode(
      String(typeof value === 'boolean' ? (value ? '1' : '') : value).replace(/<[^>]*>/g, ''),
    ).trim();
    if (
      !text ||
      /^(?:n\/?a|none|null|unknown|undefined)$/i.test(text) ||
      /(?:[a-z][a-z0-9+.-]*:\/\/|(?:token|auth|pass(?:word)?|session|cookie|mac)[=:]|[?&][a-z_]+=|(?:[0-9a-f]{2}:){5}[0-9a-f]{2})/i.test(
        text,
      ) ||
      secrets.some((secret) => text.toLowerCase().includes(secret))
    )
      return null;
    return take(text.replace(/[\x00-\x1f\x7f]/g, ' '), limit);
  }
  function inspect(out, row, mode, prefix = '') {
    for (const [key, value] of Object.entries(row)) {
      if (!/^[a-z][a-z0-9_]{0,49}$/i.test(key) || privateName.test(key)) continue;
      const field = prefix + key,
        stat = (out[field] ??= { type: type(value), present: 0 });
      stat.present++;
      const sample = safe(value, mode === 'guide' ? 160 : 120);
      if (mode === 'guide') stat.nonempty = (stat.nonempty ?? 0) + Number(sample !== null);
      else {
        stat.populated =
          (stat.populated ?? 0) +
          Number(value != null && value !== '' && (!record(value) || Object.keys(value).length > 0));
        if (mode === 'live') stat.useful = (stat.useful ?? 0) + Number(sample !== null);
      }
      const allow = mode === 'guide' ? guideFields : mode === 'live' ? liveFields : titleFields;
      if (sample !== null && allow.has(key) && !Object.hasOwn(stat, 'example')) stat.example = sample;
      if (mode === 'live' && numericFields.has(key) && sample !== null && Number.isFinite(Number(sample))) {
        stat.values ??= Object.create(null);
        if (Object.hasOwn(stat.values, sample) || Object.keys(stat.values).length < 12)
          stat.values[sample] = (stat.values[sample] ?? 0) + 1;
      }
      if (mode === 'catalogue' && !prefix && sections.includes(key) && record(value))
        inspect(out, value, mode, key + '.');
    }
  }
  return { safe, inspect };
}

async function diagnostics(root, feed, client, action, opts, decode, expired) {
  const { safe, inspect } = inspector(feed, decode),
    inventory = Object.create(null);
  if (action === 'stalker_vod_probe' || action === 'stalker_vod_fields') {
    const kind = opts.kind ?? (action === 'stalker_vod_fields' ? 'vod' : '');
    if (!['series', 'vod'].includes(kind)) throw invalid('Choose movies or series.');
    if (action === 'stalker_vod_probe') {
      const id = opts.id ?? '';
      if (!/^(?:(?:vod:)?[0-9]{1,12}|[0-9]{1,12}:[0-9]{1,12})$/.test(id))
        throw invalid('Choose a valid catalogue item.');
      const result = await client.call(kind, 'get_info', {
        movie_id: String(id).replace(/^vod:/, '').split(':')[0],
      });
      const row = record(result.data) ? result.data : result,
        names = new Set();
      for (const section of [row, ...sections.map((key) => row[key]).filter(record)]) {
        for (const [key, value] of Object.entries(section)) {
          if (
            /^[a-z][a-z0-9_]{0,49}$/i.test(key) &&
            !/(?:token|auth|pass|secret|cookie|key|mac|serial|device|url|link|cmd|stream|ticket)/i.test(key)
          )
            names.add(key);
          if (!infoFields.has(key) || Object.hasOwn(inventory, key)) continue;
          const example = safe(value, ['description', 'plot', 'overview'].includes(key) ? 1200 : 250);
          if (example !== null) inventory[key] = example;
        }
      }
      return {
        ok: true,
        supported: Object.keys(inventory).length > 0,
        fields: inventory,
        field_names: [...names],
      };
    }
    let category = String(opts.category ?? '');
    if (category && !/^(?:[a-zA-Z0-9_-]{1,120}|\*)$/.test(category))
      throw invalid('Choose a valid provider category.');
    const folders = groups(await client.call(kind, 'get_categories'));
    category ||= [...folders.keys()].find((id) => id !== '*') ?? '*';
    const number = bound(opts.page, 1, 10000);
    const result = await client.ordered(kind, { sortby: 'name', category, search: '' }, number, page);
    const { rows, total } = page(result);
    for (const row of rows.slice(0, 15)) inspect(inventory, row, 'catalogue');
    return {
      ok: true,
      kind,
      connection: feed.name,
      category,
      rows_inspected: Math.min(rows.length, 15),
      fields: sorted(inventory),
      complete: false,
      sampleComplete: true,
      page: number,
      rows_returned: rows.length,
      total_items: total,
      folder_ids: [...folders.keys()],
      response_fields: ['data', 'total_items', 'max_page_items', 'total_pages', 'cur_page', 'page'].filter(
        (key) => Object.hasOwn(result, key),
      ),
      emptyConfirmed: !rows.length && total === 0,
      note: 'Sample covers one catalogue page and up to 15 rows.',
    };
  }
  if (action === 'stalker_live_fields') {
    const requested = String(opts.category ?? '');
    if (requested && !/^[0-9]{1,12}$/.test(requested)) throw invalid('Choose a valid channel category.');
    const data = expired ? null : await liveSnapshot(root, feed);
    const folder = data?.categories.find(
      (row) => /^[0-9]{1,12}$/.test(row.id) && (!requested || requested === row.id),
    );
    if (!folder) throw invalid('No usable Live TV category was found.');
    const number = bound(opts.page, 1, 20),
      result = await client.ordered(
        'itv',
        { sortby: 'number', genre: folder.id, search: '', fav: 0 },
        number,
        page,
      );
    const rows = Object.values(result.data ?? result).filter(record),
      samples = [];
    for (const row of rows) {
      inspect(inventory, row, 'live');
      if (samples.length >= 15) continue;
      const sample = {};
      for (const key of sampleFields) {
        const value = safe(row[key], key === 'name' ? 140 : 90);
        if (value !== null) sample[key] = value;
      }
      samples.push({
        ...sample,
        logo_present: typeof row.logo === 'string' && Boolean(row.logo.trim()),
        embedded_epg_entries: record(row.epg) ? Math.min(Object.keys(row.epg).length, 10000) : 0,
        separator_like: /^\s*[#═━-]{3,}|[#═━-]{3,}\s*$/u.test(row.name ?? ''),
      });
    }
    return {
      ok: true,
      connection: feed.name,
      category: folder.name,
      category_id: folder.id,
      page: number,
      rows_inspected: rows.length,
      provider_total:
        result.total_items != null && Number.isFinite(Number(result.total_items))
          ? Math.trunc(Number(result.total_items))
          : null,
      fields: sorted(inventory),
      channel_samples: samples,
      separator_samples: samples.filter((row) => row.separator_like).length,
      note: 'Sample covers one live channel page. Flags are supplied by the provider.',
    };
  }
  const period = bound(opts.period, 1, 2),
    response = await client.call('itv', 'get_epg_info', { period });
  const samples = [];
  let channels = 0,
    withEvents = 0,
    events = 0,
    earliest = Infinity,
    latest = 0;
  const timestamp = (value) =>
    value != null && String(value).trim() && Number.isFinite(Number(value))
      ? Number(value)
      : Date.parse(value) / 1000;
  const utc = (value) =>
    Number.isFinite(value) && value > 0 && value < 8640000000000
      ? new Date(value * 1000).toISOString().replace(/\.\d{3}Z$/, '+00:00')
      : null;
  for (const [channel, rows] of Object.entries(response.data ?? response)) {
    if (!record(rows)) continue;
    channels++;
    let found = false;
    for (const row of Object.values(rows)) {
      if (!record(row)) continue;
      inspect(inventory, row, 'guide');
      events++;
      found = true;
      const start = timestamp(row.start_timestamp ?? row.time),
        end = timestamp(row.stop_timestamp ?? row.time_to);
      if (utc(start)) earliest = Math.min(earliest, start);
      if (utc(end)) latest = Math.max(latest, end);
      if (samples.length < 12) {
        const sample = { channel_ref: sha(String(row.ch_id ?? channel)).slice(0, 12) };
        for (const key of ['name', 'title', 'descr', 'description', 'category', 'genre']) {
          const value = safe(row[key], ['descr', 'description'].includes(key) ? 240 : 150);
          if (value !== null) sample[key] = value;
        }
        if (utc(start)) sample.start_utc = utc(start);
        if (utc(end)) sample.end_utc = utc(end);
        samples.push(sample);
      }
      if (events >= 1500) break;
    }
    if (found) withEvents++;
    if (events >= 1500 || channels >= 5000) break;
  }
  return {
    ok: true,
    connection: feed.name,
    period,
    channels_seen: channels,
    channels_with_events: withEvents,
    events_inspected: events,
    truncated: events >= 1500 || channels >= 5000,
    earliest_start_utc: utc(earliest),
    latest_end_utc: utc(latest),
    fields: sorted(inventory),
    programme_samples: samples,
    note: 'Sample covers one guide response with up to 1,500 events.',
  };
}
module.exports = { diagnostics };
