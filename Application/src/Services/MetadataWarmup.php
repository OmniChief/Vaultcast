<?php
declare(strict_types=1);

/** Bounded, resumable enrichment in the existing maintenance worker. */
final class MetadataWarmup
{
    public static function run(?callable $progress = null, float $remoteBudget = 120, float $artworkBudget = 120): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Metadata warmup requires the maintenance worker.');
        $remote = Posters::enabled();
        $targets = ArtworkLibrary::targets();
        $out = ['ok'=>true, 'processed'=>0, 'failed'=>0, 'deferred'=>0, 'artwork_prepared'=>0, 'artwork_failed'=>0, 'artwork_deferred'=>0];
        $attempts = \Vaultcast\Repository::document('metadata-attempts-v1', []);
        $attempts = is_array($attempts) ? array_intersect_key($attempts, $targets) : [];
        $remoteSeconds = 0.0; $lastSave = microtime(true);
        $pins = class_exists('Matching') ? Matching::overrides() : [];
        foreach ($targets as $key => $target) {
            Maintenance::checkpoint();
            $it = $target['item'];
            if ($progress) $progress('Artwork and synopses', $out['processed'], count($targets), (string)($it['show'] ?? $it['title'] ?? $key));
            $signature = sha1(json_encode([$target, $pins['shows'][$target['key'] ?? ''] ?? null]));
            $recent = ($attempts[$key]['signature'] ?? '') === $signature && ($attempts[$key]['at'] ?? 0) > time() - 86400;
            $fetch = $remote && !$recent && $remoteSeconds < min(120, max(0, $remoteBudget));
            if ($remote && !$recent && !$fetch) $out['deferred']++;
            $start = microtime(true);
            Posters::beginRemoteBudget($fetch ? 5.0 : 0.4);
            try {
                ArtworkLibrary::prepare($target, $fetch);
                if ($fetch && $target['kind'] === 'movies') {
                    // A slow poster provider must not consume the synopsis budget.
                    Posters::beginRemoteBudget(5.0);
                    Posters::movieSynopsis((string)$it['id'], (string)$it['title'], isset($it['year']) ? (int)$it['year'] : null);
                }
                $out['processed']++;
            } catch (Throwable $e) {
                $out['processed']++; $out['failed']++;
                error_log('Vaultcast metadata ' . $key . ': ' . $e->getMessage());
            } finally {
                Posters::endRemoteBudget();
                if ($fetch) {
                    $remoteSeconds += microtime(true) - $start;
                    $attempts[$key] = ['signature'=>$signature, 'at'=>time()];
                    if (microtime(true) - $lastSave >= 5) {
                        \Vaultcast\Repository::putDocument('metadata-attempts-v1', $attempts);
                        $lastSave = microtime(true);
                    }
                }
            }
        }
        \Vaultcast\Repository::putDocument('metadata-attempts-v1', $attempts);
        $files = PosterOptimise::listOriginals();
        $failures = \Vaultcast\Repository::document('metadata-artwork-failures-v1', []);
        $failures = is_array($failures) ? array_intersect_key($failures, array_flip($files)) : [];
        $started = microtime(true); $done = 0;
        foreach ($files as $file) {
            Maintenance::checkpoint();
            if ($progress) $progress('Optimising artwork', $done++, count($files), $file);
            $signature = filesize(Posters::dir() . '/' . $file) . ':' . filemtime(Posters::dir() . '/' . $file);
            try {
                $status = PosterOptimise::statusForFile($file, false, false);
                if (empty($status['needs'])) continue;
                if (($failures[$file]['signature'] ?? '') === $signature && ($failures[$file]['at'] ?? 0) > time() - 86400) { $out['artwork_failed']++; continue; }
                if (microtime(true) - $started >= min(120, max(0, $artworkBudget))) { $out['artwork_deferred']++; continue; }
                $result = PosterOptimise::optimiseOne($file, ['overwrite'=>false]);
                if (!empty($result['ok'])) $out['artwork_prepared']++;
                else {
                    $out['artwork_failed']++;
                    $failures[$file] = ['signature'=>$signature, 'at'=>time()];
                    \Vaultcast\Repository::putDocument('metadata-artwork-failures-v1', $failures);
                    error_log('Vaultcast artwork ' . $file . ': ' . ($result['error'] ?? 'Conversion failed'));
                }
            } catch (Throwable $e) {
                $out['artwork_failed']++;
                $failures[$file] = ['signature'=>$signature, 'at'=>time()];
                \Vaultcast\Repository::putDocument('metadata-artwork-failures-v1', $failures);
                error_log('Vaultcast artwork ' . $file . ': ' . $e->getMessage());
            }
        }
        if ($progress) $progress('Preparing library pages', count($targets), count($targets), '');
        Catalogue::invalidateDerivedCaches();
        Catalogue::warmBrowseViews();
        Catalogue::homeLibrary();
        Catalogue::showPosterHint('');
        return $out;
    }
}
