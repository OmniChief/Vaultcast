<?php
require_once dirname(__DIR__) . '/bootstrap.php';
function relay_load_station() {
    $station = \Vaultcast\Repository::document('live_station', null);
    if (!is_array($station)) {
        require_once __DIR__ . '/seed.php';
        return relay_seed_station();
    }
    return $station;
}
function relay_save_station($station) {
    require_once __DIR__ . '/schedule.php';
    // Apply cached durations only; probing is an administrator-started maintenance task.
    require_once __DIR__ . '/durations.php';
    try {
        $dstats = null;
        $station = relay_fill_durations($station, 0.0, $dstats);

    } catch (Throwable $e) { /* never block a save on probing */ }
    $station = relay_rebuild_cycles($station);
    \Vaultcast\Repository::putDocument('live_station', $station);
    return $station;
}
