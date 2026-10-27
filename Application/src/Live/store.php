<?php
require_once dirname(__DIR__) . '/bootstrap.php';
function relay_strip_legacy_schedule($station) {
    if (!is_array($station)) return array();
    if (!isset($station['channels']) || !is_array($station['channels'])) $station['channels'] = array();
    foreach ($station['channels'] as &$channel) {
        if (!is_array($channel)) continue;
        foreach (array('playlist', 'rollingSchedule', 'fillHours', 'commercialEveryMs', 'commercialsPerBreak', 'bumperBefore', 'seed') as $legacy) unset($channel[$legacy]);
    }
    unset($channel);
    unset($station['cycles'], $station['playlists']);
    return $station;
}

function relay_load_station() {
    $station = \Vaultcast\Repository::document('live_station', null);
    if (!is_array($station)) {
        require_once __DIR__ . '/seed.php';
        return relay_seed_station();
    }
    return relay_strip_legacy_schedule($station);
}
function relay_save_station($station) {
    require_once __DIR__ . '/schedule.php';
    // Apply cached durations only; probing is an administrator-started maintenance task.
    require_once __DIR__ . '/durations.php';
    try {
        $dstats = null;
        $station = relay_fill_durations($station, 0.0, $dstats);

    } catch (Throwable $e) { /* never block a save on probing */ }
    $station = relay_enrich_station_episodes($station);
    $station = relay_strip_legacy_schedule($station);
    \Vaultcast\Repository::putDocument('live_station', $station);
    return $station;
}
