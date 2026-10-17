<?php
declare(strict_types=1);

/** Remote enrichment runs in the existing CLI maintenance worker, never from a card. */
final class MetadataWarmup
{
    public static function run(): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Metadata warmup requires the maintenance worker.');
        $remote = Posters::enabled();
        $out = ['ok' => true, 'processed' => 0, 'failed' => 0, 'artwork_prepared' => 0, 'artwork_failed' => 0];
        $seasons = [];
        $shows = [];
        foreach (Catalogue::get()['items'] ?? [] as $it) {
            Maintenance::checkpoint();
            try {
                $art = Posters::resolveItem($it, $remote);
                $type = $it['type'] ?? '';
                if (in_array($type, ['movies', 'disney'], true) || (($it['media_kind'] ?? '') === 'video' && empty($it['show']))) {
                    if ($art) Posters::adoptFile(Posters::movieCachePath((string)$it['id']), $art);
                    if ($remote) Posters::movieSynopsis((string)$it['id'], (string)$it['title'], isset($it['year']) ? (int)$it['year'] : null);
                } elseif ($type === 'roms' || ($it['media_kind'] ?? '') === 'rom') {
                    if ($art) Posters::adoptFile(Posters::gameCachePath((string)$it['id']), $art);
                } elseif (in_array($type, ['tv', 'anime'], true) && !empty($it['show'])) {
                    $key = Catalogue::showKey((string)$it['section'], (string)$it['show']);
                    $dir = Catalogue::showDirFromItem($it);
                    if (!isset($shows[$key])) {
                        $shows[$key] = true;
                        $showArt = Posters::resolveShow($key, (string)$it['show'], $it['show_poster'] ?? null, $remote, $dir);
                        if ($showArt) Posters::adoptFile(Posters::showCachePath($key), $showArt);
                    }
                    $season = $it['season'] ?? null;
                    if ($season !== null && !isset($seasons[$key . ':' . $season])) {
                        $seasons[$key . ':' . $season] = true;
                        $seasonArt = Posters::resolveSeason($key, (string)$it['show'], (int)$season, $it['season_poster'] ?? null, $remote, $dir);
                        if ($seasonArt) Posters::adoptFile(Posters::seasonCachePath($key, (int)$season), $seasonArt);
                    }
                }
                $out['processed']++;
            } catch (Throwable $e) {
                $out['failed']++;
                error_log('Vaultcast metadata ' . ($it['id'] ?? '') . ': ' . $e->getMessage());
            }
        }
        // Includes local sidecars adopted on previous page requests, not only downloads.
        foreach (PosterOptimise::listOriginals() as $file) {
            Maintenance::checkpoint();
            try {
                $status = PosterOptimise::statusForFile($file);
                if (empty($status['needs'])) continue;
                $result = PosterOptimise::optimiseOne($file);
                if (!empty($result['ok'])) $out['artwork_prepared']++;
                else { $out['artwork_failed']++; error_log('Vaultcast artwork ' . $file . ': ' . ($result['error'] ?? 'Conversion failed')); }
            } catch (Throwable $e) { $out['artwork_failed']++; error_log('Vaultcast artwork ' . $file . ': ' . $e->getMessage()); }
        }
        Catalogue::invalidateDerivedCaches();
        Catalogue::warmBrowseViews();
        Catalogue::homeLibrary();
        Catalogue::showPosterHint('');
        return $out;
    }
}
