<?php
declare(strict_types=1);

final class ArtworkAudit
{
    private static function targets(): array {return array_filter(self::allTargets(),static function(array $target):bool{return AreaAccess::allowed(AreaAccess::viewer(),AreaAccess::itemArea($target['item']));});}
    private static function allTargets(): array
    {
        $revision = Store::revision('catalogue');
        $cached = \Vaultcast\Repository::cacheGet('artwork-audit-targets-v2');
        if (($cached['revision'] ?? null) === $revision && is_array($cached['targets'] ?? null)) return $cached['targets'];
        $targets = ArtworkLibrary::targets();
        foreach ($targets as $key => $target) {
            if ($target['kind'] !== 'seasons') continue;
            $show = 'show_' . $target['key'];
            if (isset($targets[$show])) $targets[$show]['seasons'][] = $target;
            unset($targets[$key]);
        }
        foreach (Catalogue::get()['items'] ?? [] as $id => $item) {
            if (!empty($item['show']) && in_array($item['type'] ?? '', ['tv','anime'], true)) {
                $show = 'show_' . Catalogue::showKey((string)$item['section'], (string)$item['show']);
                if (isset($targets[$show])) {
                    foreach (['show_poster','season_poster'] as $field) if (!empty($item[$field])) $targets[$show]['references'][$field][(string)$item[$field]] = true;
                }
                continue;
            }
            if (empty($item['id']) || (string)$item['id'] !== (string)$id || empty($item['path'])) {
                $targets['record_' . $id] = ['kind'=>'records', 'record_id'=>(string)$id, 'item'=>$item, 'path'=>Posters::movieCachePath((string)$id)];
                continue;
            }
        }
        ksort($targets);
        if ($revision === \Vaultcast\Repository::revision('catalogue')) \Vaultcast\Repository::cachePut('artwork-audit-targets-v2', ['revision'=>$revision, 'targets'=>$targets], 0);
        return $targets;
    }

    public static function image(?string $path): bool
    {
        return $path && is_file($path) && is_readable($path) && @filesize($path) > 40 && @getimagesize($path) !== false;
    }

    private static function resolved(array $target): ?string
    {
        $paths = PosterOptimise::pathsForStem(pathinfo($target['path'], PATHINFO_FILENAME));
        foreach ([$paths['original'], $paths['webp']] as $path) if (self::image($path)) return $path;
        if ($target['kind'] === 'records') return null;
        $item = Catalogue::enrichItemArt($target['item']);
        $dir = Catalogue::showDirFromItem($item);
        if ($target['kind'] === 'shows') return Posters::resolveShow($target['key'], (string)$item['show'], $item['show_poster'] ?? null, false, $dir);
        if ($target['kind'] === 'seasons') return Posters::resolveSeason($target['key'], (string)$item['show'], $target['season'], $item['season_poster'] ?? null, false, $dir)
            ?: Posters::resolveShow($target['key'], (string)$item['show'], $item['show_poster'] ?? null, false, $dir);
        return Posters::resolveItem($item, false);
    }

    private static function inspect(string $key, array $target): ?array
    {
        if ($target['kind'] !== 'shows') return self::inspectSingle($key, $target);
        $result = self::inspectSingle($key, $target);
        foreach ($target['seasons'] ?? [] as $season) {
            $row = self::inspectSingle($key, $season);
            if (!$row) continue;
            if (!$result) { $result = $row; $result['problems'] = []; $result['missing'] = false; }
            foreach ($row['problems'] as $problem) $result['problems'][] = 'Season ' . $season['season'] . ': ' . $problem;
            $result['missing'] = $result['missing'] || $row['missing'];
        }
        foreach ($target['references'] ?? [] as $field => $paths) foreach (array_keys($paths) as $path) {
            if (self::image($path)) continue;
            if (!$result) $result = ['problems'=>[], 'missing'=>false];
            $result['problems'][] = 'Missing, unreadable or invalid database reference: ' . $field;
        }
        if (!$result) return null;
        $result['key'] = $key; $result['id'] = $target['item']['id']; $result['show_key'] = $target['key'];
        $result['kind'] = 'shows'; $result['title'] = $target['item']['show']; $result['season'] = null;
        $result['problems'] = array_values(array_unique($result['problems']));
        return $result;
    }

    private static function inspectSingle(string $key, array $target): ?array
    {
        $item = $target['item']; $problems = [];
        if (empty($item['id']) || empty($item['path']) || !preg_match('/^[a-f0-9]{16}$/D', (string)($item['id'] ?? '')) || (isset($target['record_id']) && (string)$item['id'] !== $target['record_id'])) $problems[] = 'Bad library record: missing or invalid id/path';
        $fields = $target['kind'] === 'shows' ? ['show_poster'] : ($target['kind'] === 'seasons' ? ['season_poster'] : ['poster']);
        foreach ($fields as $field) {
            if (!empty($item[$field]) && !self::image((string)$item[$field])) $problems[] = 'Missing, unreadable or invalid database reference: ' . $field;
        }
        $paths = PosterOptimise::pathsForStem(pathinfo($target['path'], PATHINFO_FILENAME));
        foreach (['original', 'webp', 'medium', 'thumb'] as $variant) {
            if ($paths[$variant] && file_exists($paths[$variant]) && !self::image($paths[$variant])) $problems[] = 'Invalid cached image: ' . $variant;
        }
        $resolved = self::resolved($target);
        $missing = !self::image($resolved);
        if ($missing) $problems[] = 'No usable artwork: poster request would return 404 or an invalid image';
        if (!$problems) return null;
        return ['key'=>$key, 'id'=>$target['record_id'] ?? $item['id'] ?? '', 'show_key'=>in_array($target['kind'], ['shows','seasons'], true) ? $target['key'] : null,
            'kind'=>$target['kind'], 'title'=>in_array($target['kind'], ['shows','seasons'], true) ? ($item['show'] ?? '') : ($item['title'] ?? ''),
            'year'=>$item['year'] ?? null, 'media_kind'=>$item['media_kind'] ?? '', 'type'=>$item['type'] ?? '',
            'season'=>$target['season'] ?? null, 'missing'=>$missing, 'problems'=>array_values(array_unique($problems))];
    }

    public static function scan(array $body): array
    {
        $revision = \Vaultcast\Repository::revision('catalogue');
        if (isset($body['revision']) && $body['revision'] !== $revision) return ['ok'=>false, 'error'=>'Library changed during the check. Run the check again.'];
        $types = $body['types'] ?? ['movies','tv','anime','games'];
        if (!is_array($types)) return ['ok'=>false, 'error'=>'Choose at least one artwork type'];
        $types = array_values(array_intersect(['movies','tv','anime','games'], $types));
        if (!$types) return ['ok'=>false, 'error'=>'Choose at least one artwork type'];
        $targets = array_filter(self::targets(), static function(array $target) use ($types): bool {
            $item = $target['item'];
            $type = (string)($item['type'] ?? '');
            if (($item['media_kind'] ?? '') === 'rom' || $type === 'roms') $type = 'games';
            elseif ($type === 'disney' || (!in_array($type, ['tv','anime'], true) && ($item['media_kind'] ?? '') === 'video')) $type = 'movies';
            return in_array($type, $types, true);
        });
        $keys = array_keys($targets); $offset = max(0, (int)($body['offset'] ?? 0));
        $end = min(count($keys), $offset + 40); $start = microtime(true); $rows = [];
        $i = $offset;
        for (; $i < $end; $i++) {
            $row = self::inspect($keys[$i], $targets[$keys[$i]]);
            if ($row) $rows[] = $row;
            if (microtime(true) - $start > 2) { $i++; break; }
        }
        return ['ok'=>true, 'items'=>$rows, 'offset'=>$i, 'total'=>count($keys), 'more'=>$i < count($keys), 'revision'=>$revision];
    }

    public static function status(array $body): array
    {
        $key = (string)($body['key'] ?? '');
        $targets = self::targets();
        $row = isset($targets[$key]) ? self::inspect($key, $targets[$key]) : null;
        return ['ok'=>true, 'resolved'=>$row === null, 'item'=>$row];
    }

    public static function repair(array $body): array
    {
        $key = (string)($body['key'] ?? ''); $targets = self::targets();
        if (!isset($targets[$key])) return ['ok'=>false, 'error'=>'Library entry no longer exists; run the check again'];
        $target = $targets[$key]; $cat = Catalogue::get(); $changed = false;
        if ($target['kind'] === 'records') {
            $recordId = $target['record_id'];
            if (preg_match('/^[a-f0-9]{16}$/D', $recordId) && isset($cat['items'][$recordId])) {
                $cat['items'][$recordId]['id'] = $recordId;
                Catalogue::save($cat);
            }
            return ['ok'=>true, 'resolved'=>false, 'item'=>self::inspect($key, $target), 'message'=>'Record id repaired where possible. Run the check again; missing media paths require a library rescan or removal.'];
        }
        $id = (string)($target['item']['id'] ?? '');
        $showKey = $target['key'] ?? null;
        $overrides = Matching::overrides();
        foreach ($cat['items'] ?? [] as $itemId => $item) {
            if ($itemId !== $id && !($showKey && !empty($item['show']) && Catalogue::showKey((string)$item['section'], (string)$item['show']) === $showKey)) continue;
            foreach (['poster','show_poster','season_poster'] as $field) if (!empty($item[$field]) && !self::image((string)$item[$field])) {
                $cat['items'][$itemId][$field] = null;
                if (isset($overrides['items'][$itemId][$field])) unset($overrides['items'][$itemId][$field]);
                $changed = true;
            }
        }
        if ($changed) { Matching::saveOverrides($overrides); Catalogue::save($cat); $targets = self::targets(); $target = $targets[$key]; }
        $members = array_merge([$target], $target['seasons'] ?? []);
        $haveArtwork = false;
        Posters::beginRemoteBudget(3);
        try {
            foreach ($members as $member) {
                $paths = PosterOptimise::pathsForStem(pathinfo($member['path'], PATHINFO_FILENAME));
                foreach (['original','webp','medium','thumb'] as $variant) if ($paths[$variant] && is_file($paths[$variant]) && !self::image($paths[$variant])) @unlink($paths[$variant]);
                $path = self::resolved($member);
                if (!self::image($path)) {
                    Posters::clearFailed(in_array($member['kind'], ['shows','seasons'], true) ? 'show' : ($member['kind'] === 'games' ? 'game' : 'movie'), (string)($member['key'] ?? $id));
                    $path = ArtworkLibrary::prepare($member, true);
                } elseif ($member['kind'] !== 'seasons') Posters::adoptFile($member['path'], $path);
                if (self::image($path)) $haveArtwork = true;
            }
        } finally { Posters::endRemoteBudget(); }
        if ($haveArtwork) {
            Catalogue::invalidateDerivedCaches();
            MetadataSidecars::schedule();
        }
        $remaining = self::inspect($key, $target);
        return ['ok'=>true, 'resolved'=>$remaining === null, 'item'=>$remaining, 'message'=>$remaining ? 'Still needs attention. Use Fix match or Manual edit to choose or upload artwork.' : 'Artwork repaired'];
    }
}
