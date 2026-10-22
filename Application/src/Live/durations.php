<?php
/** Fill missing Live media durations using the shared probe service. */

/** Catalogue id for a Relay media item (catalogueId field, else the api.php?action=file&id= URL). */
function relay_media_catalogue_id($m) {
    if (!empty($m['catalogueId']) && preg_match('/^[a-f0-9]+$/', (string) $m['catalogueId'])) return (string) $m['catalogueId'];
    if (preg_match('/action=file&id=([a-f0-9]+)/', (string) (isset($m['url']) ? $m['url'] : ''), $mm)) return $mm[1];
    if (preg_match('#/api/live/file\?id=([a-f0-9]+)#', (string) (isset($m['url']) ? $m['url'] : ''), $mm)) return $mm[1];
    return '';
}

/**
 * Fill durationMs for media with no duration.
 * $budgetSec: seconds allowed for new ffprobe calls (cache hits are always applied);
 *             < 0 = unlimited (CLI backfill), 0 = cache only.
 * $stats (out): missing / filled / failed / remaining.
 */
function relay_fill_durations($station, $budgetSec = 6.0, &$stats = null) {
    $stats = array('missing' => 0, 'filled' => 0, 'failed' => 0, 'remaining' => 0);
    if (empty($station['media']) || !is_array($station['media'])) return $station;
    $need = array();
    foreach ($station['media'] as $i => $m) {
        if (!is_array($m)) continue;
        if (!isset($m['durationMs']) || (int) $m['durationMs'] <= 0) $need[] = $i;
    }
    $stats['missing'] = count($need);
    if (!$need) return $station;

    require_once __DIR__ . '/library.php';
    relay_stream_boot();

    $t0 = microtime(true);

    foreach ($need as $i) {
        Maintenance::checkpoint();
        $m = $station['media'][$i];
        $cid = relay_media_catalogue_id($m);
        $item = $cid !== '' ? \Vaultcast\Repository::item($cid) : null;
        $path = $item ? relay_play_path($item) : '';
        if ($path === '' || !is_file($path)) { $stats['failed']++; continue; }
        $sig = $path . '|' . @filesize($path) . '|' . @filemtime($path);
        $cache = [$cid => \Vaultcast\Repository::cacheGet('duration|' . $sig)];
        $ms = 0;
        if (isset($cache[$cid]) && is_array($cache[$cid]) && (isset($cache[$cid]['sig']) ? $cache[$cid]['sig'] : '') === $sig) {
            $ms = (int) $cache[$cid]['ms'];
            // failed probe: retry after a day
            if ($ms < 0 && (int) (isset($cache[$cid]['at']) ? $cache[$cid]['at'] : 0) < time() - 86400) $ms = 0;
        }
        if ($ms === 0) {
            if ($budgetSec >= 0 && (microtime(true) - $t0) >= $budgetSec) { $stats['remaining']++; continue; }
            $ms = relay_duration_ms($path);
            if ($ms <= 0) $ms = -1;
            $cache[$cid] = array('sig' => $sig, 'ms' => $ms, 'at' => time());
            \Vaultcast\Repository::cachePut('duration|' . $sig, $cache[$cid], $ms > 0 ? 2592000 : 86400);
        }
        if ($ms > 0) {
            $station['media'][$i]['durationMs'] = $ms;
            if (empty($station['media'][$i]['catalogueId'])) $station['media'][$i]['catalogueId'] = $cid;
            $stats['filled']++;
        } else {
            $stats['failed']++;
        }
    }

    return $station;
}

/** Fill missing durations from the scheduled console command. */
function relay_run_duration_backfill(): void {
    require_once STREAM_ROOT . '/src/Live/store.php';

    $lock = STREAM_DATA . '/live/relay-durations.lock';
    $handle = fopen($lock, 'c+');
    if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) { if ($handle) fclose($handle); return; }
    ftruncate($handle, 0); fwrite($handle, (string)getmypid()); fflush($handle);
    register_shutdown_function(function () use ($handle) { flock($handle, LOCK_UN); fclose($handle); });

    $t = microtime(true);
    $station = relay_load_station();
    // Probe media while holding the worker lock.
    $need = 0;
    foreach ($station['media'] as $m) if ((int) (isset($m['durationMs']) ? $m['durationMs'] : 0) <= 0) $need++;
    echo date('c') . " missing durations: $need\n";
    $stats = null;
    for ($pass = 0; $pass < 200; $pass++) {
        touch($lock);
        $station = relay_fill_durations($station, 30.0, $stats);
        echo date('c') . ' pass ' . $pass . ' ' . json_encode($stats) . "\n";
        if ($stats['remaining'] <= 0) break;
    }
    // Reload the station before applying durations to retain concurrent edits.
    $fresh = relay_load_station();
    $saved = relay_save_station($fresh); // applies cache (budget) + rebuilds cycles
    $left = 0;
    foreach ($saved['media'] as $m) if ((int) (isset($m['durationMs']) ? $m['durationMs'] : 0) <= 0) $left++;
    echo date('c') . ' saved station; still without duration: ' . $left . ' (' . round(microtime(true) - $t, 1) . "s)\n";
}
