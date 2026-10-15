<?php
/**
 * Stable, human-readable channel ids for external players: ?channel=ch-<number>.
 *
 * Every TV Studio (relay) channel gets `ch-<guide number>`. When two share a number, the lower
 * internal id keeps `ch-N` and the others get `ch-N-2`, `ch-N-3`, ... in a stable order.
 * Guide ids (M3U tvg-id / XMLTV channel id) stay `relay.<id>` / `iptv.<id>`: they are permanent, so
 * EPG mappings in clients survive renumbering. IPTV channels keep their original stream URLs;
 * live_channel_slugs() still accepts them so callers can rank both if ever needed.
 *
 * Old links keep working: the original random ids (`ch-u8f1qa`, `relay.<id>`, `iptv.<id>`) still
 * resolve, and every slug ever handed out is remembered (doc `live_channel_aliases`), so after a
 * renumber an old `ch-5` still opens the channel it pointed at unless another channel now owns 5.
 */

/** @return array<int,array{slug:string,kind:string,id:string,number:int}> */
function live_channel_slugs($relayChannels, $iptvChannels) {
    $all = array();
    foreach ((array)$relayChannels as $c) {
        if (!is_array($c) || !isset($c['id'])) continue;
        $all[] = array('kind' => 'relay', 'id' => (string)$c['id'], 'number' => (int)($c['number'] ?? 0));
    }
    foreach ((array)$iptvChannels as $c) {
        if (!is_array($c) || !isset($c['id'])) continue;
        $all[] = array('kind' => 'iptv', 'id' => (string)$c['id'], 'number' => (int)($c['number'] ?? 0));
    }
    usort($all, function ($a, $b) {
        if ($a['number'] !== $b['number']) return $a['number'] - $b['number'];
        if ($a['kind'] !== $b['kind']) return $a['kind'] === 'relay' ? -1 : 1;
        return strcmp($a['id'], $b['id']);
    });
    $taken = array();
    foreach ($all as &$c) {
        $n = max(0, $c['number']);
        $slug = 'ch-' . $n;
        for ($k = 2; isset($taken[$slug]); $k++) $slug = 'ch-' . $n . '-' . $k;
        $taken[$slug] = true;
        $c['slug'] = $slug;
    }
    unset($c);
    return $all;
}

/** Map of relay/iptv id => slug. */
function live_channel_slug_index($slugs) {
    $idx = array('relay' => array(), 'iptv' => array());
    foreach ($slugs as $s) $idx[$s['kind']][$s['id']] = $s['slug'];
    return $idx;
}

/** Remember every current slug (current owner wins). Returns the updated alias map. */
function live_channel_remember($aliases, $slugs) {
    $aliases = is_array($aliases) ? $aliases : array();
    $changed = false;
    foreach ($slugs as $s) {
        $cur = $aliases[$s['slug']] ?? null;
        if (!is_array($cur) || $cur['kind'] !== $s['kind'] || $cur['id'] !== $s['id']) {
            $aliases[$s['slug']] = array('kind' => $s['kind'], 'id' => $s['id']);
            $changed = true;
        }
    }
    if (count($aliases) > 5000) $aliases = array_slice($aliases, -5000, null, true);
    return array($aliases, $changed);
}

/**
 * Resolve a ?channel= value: current slug, then legacy raw/prefixed id, then remembered slug.
 * @return array{kind:string,id:string,slug:string}|null
 */
function live_channel_resolve($value, $slugs, $aliases = array()) {
    $v = trim((string)$value);
    if ($v === '') return null;
    $exists = array();
    foreach ($slugs as $s) {
        if (strcasecmp($s['slug'], $v) === 0) return array('kind' => $s['kind'], 'id' => $s['id'], 'slug' => $s['slug']);
        $exists[$s['kind'] . ':' . $s['id']] = $s['slug'];
    }
    // legacy ids: raw relay id first (old ?channel=<id> links), then relay./iptv. prefixed tvg-ids
    if (isset($exists['relay:' . $v])) return array('kind' => 'relay', 'id' => $v, 'slug' => $exists['relay:' . $v]);
    if (strpos($v, 'relay.') === 0 && isset($exists['relay:' . substr($v, 6)])) return array('kind' => 'relay', 'id' => substr($v, 6), 'slug' => $exists['relay:' . substr($v, 6)]);
    if (strpos($v, 'iptv.') === 0 && isset($exists['iptv:' . substr($v, 5)])) return array('kind' => 'iptv', 'id' => substr($v, 5), 'slug' => $exists['iptv:' . substr($v, 5)]);
    if (isset($exists['iptv:' . $v])) return array('kind' => 'iptv', 'id' => $v, 'slug' => $exists['iptv:' . $v]);
    // remembered slug from before a renumber
    foreach ((array)$aliases as $slug => $a) {
        if (strcasecmp((string)$slug, $v) !== 0 || !is_array($a)) continue;
        $key = ($a['kind'] ?? '') . ':' . ($a['id'] ?? '');
        if (isset($exists[$key])) return array('kind' => $a['kind'], 'id' => $a['id'], 'slug' => $exists[$key]);
    }
    return null;
}

/** Current relay-channel slugs, remembering them for future aliasing. */
function live_channel_slugs_current($station = null) {
    if ($station === null) { require_once __DIR__ . '/store.php'; $station = relay_load_station(); }
    $slugs = live_channel_slugs($station['channels'] ?? array(), array());
    try {
        $aliases = \Vaultcast\Repository::document('live_channel_aliases', array());
        list($aliases, $changed) = live_channel_remember($aliases, $slugs);
        if ($changed) \Vaultcast\Repository::putDocument('live_channel_aliases', $aliases);
    } catch (Throwable $e) { $aliases = array(); }
    return array($slugs, $aliases);
}
