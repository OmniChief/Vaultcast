<?php
declare(strict_types=1);

/** Shared library inventory for maintenance and Settings, independent of cache contents. */
final class ArtworkLibrary
{
    public static function targets(): array
    {
        $revision = \Vaultcast\Repository::revision('catalogue');
        $cached = \Vaultcast\Repository::cacheGet('artwork-library-v1');
        if (($cached['revision'] ?? null) === $revision && is_array($cached['targets'] ?? null)) return $cached['targets'];
        $out = [];
        foreach (Catalogue::get()['items'] ?? [] as $it) {
            if (empty($it['id'])) continue;
            $type = $it['type'] ?? '';
            if (in_array($type, ['tv', 'anime'], true) && !empty($it['show'])) {
                $key = Catalogue::showKey((string)($it['section'] ?? ''), (string)$it['show']);
                $out['show_' . $key] = $out['show_' . $key] ?? ['kind'=>'shows', 'key'=>$key, 'item'=>$it, 'path'=>Posters::showCachePath($key)];
                $season = $it['season'] ?? null;
                if ($season === null || $season === '') $season = Catalogue::seasonEpisodeFromRel((string)($it['rel'] ?? ''))['season'];
                if ($season !== null) {
                    $stem = 'season_' . $key . '_s' . (int)$season;
                    $out[$stem] = $out[$stem] ?? ['kind'=>'seasons', 'key'=>$key, 'season'=>(int)$season, 'item'=>$it, 'path'=>Posters::seasonCachePath($key, (int)$season)];
                }
            } elseif ($type === 'roms' || ($it['media_kind'] ?? '') === 'rom') {
                $out['game_' . $it['id']] = ['kind'=>'games', 'item'=>$it, 'path'=>Posters::gameCachePath((string)$it['id'])];
            } elseif (in_array($type, ['movies', 'disney'], true) || (($it['media_kind'] ?? '') === 'video' && empty($it['show']))) {
                $out['movie_' . $it['id']] = ['kind'=>'movies', 'item'=>$it, 'path'=>Posters::movieCachePath((string)$it['id'])];
            }
        }
        ksort($out, SORT_STRING);
        if (\Vaultcast\Repository::revision('catalogue') === $revision) \Vaultcast\Repository::cachePut('artwork-library-v1', ['revision'=>$revision, 'targets'=>$out], 0);
        return $out;
    }

    /** Local mode never downloads. TV work is once per show/season, not episode. */
    public static function prepare(array $target, bool $remote = false): ?string
    {
        // adoptFile already keeps an existing canonical original. Resolve it
        // first so continuations do not revisit thousands of NAS sidecar paths.
        if (is_file($target['path']) && filesize($target['path']) > 100) return $target['path'];
        $it = $target['item'];
        if ($target['kind'] === 'shows' || $target['kind'] === 'seasons') {
            $dir = Catalogue::showDirFromItem($it);
            $art = $target['kind'] === 'shows'
                ? Posters::resolveShow($target['key'], (string)$it['show'], $it['show_poster'] ?? null, $remote, $dir)
                : Posters::resolveSeason($target['key'], (string)$it['show'], $target['season'], $it['season_poster'] ?? null, $remote, $dir);
        } else $art = Posters::resolveItem($it, $remote);
        return $art ? Posters::adoptFile($target['path'], $art) : null;
    }
}
