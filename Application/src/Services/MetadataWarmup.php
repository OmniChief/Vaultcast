<?php
declare(strict_types=1);

/** Remote enrichment runs in the existing CLI maintenance worker, never from a card. */
final class MetadataWarmup
{
    public static function run(): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Metadata warmup requires the maintenance worker.');
        if (!Posters::enabled()) return ['ok' => true, 'skipped' => 'Posters disabled'];
        $out = ['ok' => true, 'processed' => 0, 'failed' => 0];
        $seasons = [];
        foreach (Catalogue::get()['items'] ?? [] as $it) {
            Maintenance::checkpoint();
            try {
                Posters::resolveItem($it, true);
                $type = $it['type'] ?? '';
                if (in_array($type, ['movies', 'disney'], true)) {
                    Posters::movieSynopsis((string)$it['id'], (string)$it['title'], isset($it['year']) ? (int)$it['year'] : null);
                } elseif (in_array($type, ['tv', 'anime'], true) && !empty($it['show'])) {
                    $key = Catalogue::showKey((string)$it['section'], (string)$it['show']);
                    $season = $it['season'] ?? null;
                    if ($season !== null && !isset($seasons[$key . ':' . $season])) {
                        $seasons[$key . ':' . $season] = true;
                        Posters::resolveSeason($key, (string)$it['show'], (int)$season, $it['season_poster'] ?? null, true, Catalogue::showDirFromItem($it));
                    }
                }
                $out['processed']++;
            } catch (Throwable $e) {
                $out['failed']++;
                error_log('Vaultcast metadata ' . ($it['id'] ?? '') . ': ' . $e->getMessage());
            }
        }
        Catalogue::invalidateDerivedCaches();
        // Build expensive section summaries here, before a viewer opens the library.
        foreach (\Vaultcast\Repository::document('catalogue', ['sections' => []])['sections'] ?? [] as $section) {
            Maintenance::checkpoint();
            $type = $section['type'] ?? '';
            $id = (string)$section['id'];
            if (in_array($type, ['tv', 'anime'], true)) Catalogue::sectionShows($id);
            elseif (in_array($type, ['movies', 'disney'], true)) Catalogue::movieFacetsCached($id, Catalogue::sectionItems($id));
        }
        Catalogue::homeLibrary();
        Catalogue::showPosterHint('');
        return $out;
    }
}
