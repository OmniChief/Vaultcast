<?php
declare(strict_types=1);

final class LibraryEditor
{
    public static function filterCatalogue(array $cat): array
    {
        $removed = Store::get('library_removed', []);
        if (!$removed) return $cat;
        foreach ($cat['items'] ?? [] as $id => $item) if (isset($removed[$id])) unset($cat['items'][$id]);
        if (!isset($cat['sections'])) $cat['sections'] = [];
        foreach ($cat['sections'] as &$section) {
            $count = 0;
            foreach ($cat['items'] ?? [] as $item) if (($item['section'] ?? '') === ($section['id'] ?? '')) $count++;
            $section['count'] = $count;
        }
        unset($section);
        $cat['item_count'] = count($cat['items'] ?? []);
        return $cat;
    }

    public static function save(array $body): array
    {
        Permissions::require(!empty($body['remove'])?'library_remove':'metadata_edit');
        $showKey = (string)($body['show_key'] ?? '');
        $id = (string)($body['id'] ?? '');
        $items = [];
        foreach (Catalogue::get()['items'] ?? [] as $item) {
            if ($showKey !== '' ? (!empty($item['show']) && Catalogue::showKey((string)$item['section'], (string)$item['show']) === $showKey) : (string)$item['id'] === $id) $items[] = $item;
        }
        if (!$items) return ['ok'=>false, 'error'=>'Library entry not found'];
        if (!empty($body['remove'])) {
            if (($body['confirm'] ?? '') !== 'REMOVE') return ['ok'=>false, 'error'=>'Removal confirmation required'];
            $removed = Store::get('library_removed', []);
            $cat = Catalogue::get();
            foreach ($items as $item) { $removed[$item['id']] = ['at'=>date('c')]; unset($cat['items'][$item['id']]); }
            Store::set('library_removed', $removed);
            Catalogue::save($cat);
            return ['ok'=>true, 'removed'=>count($items)];
        }
        $title = trim((string)($body['title'] ?? ''));
        if ($title === '' || strlen($title) > 500) return ['ok'=>false, 'error'=>'A title of up to 500 bytes is required'];
        $year = ($body['year'] ?? '') === '' || ($body['year'] ?? null) === null ? null : (int)$body['year'];
        if ($year !== null && ($year < 1800 || $year > 2200)) return ['ok'=>false, 'error'=>'Invalid year'];
        $summary = trim((string)($body['synopsis'] ?? ''));
        if (strlen($summary) > 20000) return ['ok'=>false, 'error'=>'Synopsis is too long'];
        $isGame = ($items[0]['media_kind'] ?? '') === 'rom';
        $target = $showKey !== '' ? Catalogue::showKey((string)$items[0]['section'], $title) : $id;
        if ($showKey !== '') MetadataSidecars::invalidateShow($showKey);
        $poster = null;
        if (!empty($body['poster_data'])) {
            $encoded = (string)$body['poster_data'];
            if (strlen($encoded) > 11500000 || !preg_match('#^data:image/(?:jpeg|png|webp);base64,(.+)$#s', $encoded, $m)) return ['ok'=>false, 'error'=>'Choose a JPEG, PNG or WebP poster up to 8 MB'];
            $bytes = base64_decode($m[1], true);
            $info = $bytes !== false ? @getimagesizefromstring($bytes) : false;
            if (!$info || strlen($bytes) > 8388608 || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] * $info[1] > 40000000) return ['ok'=>false, 'error'=>'Invalid or oversized poster image'];
            $poster = $showKey !== '' ? Posters::showCachePath($target) : ($isGame ? Posters::gameCachePath($id) : Posters::movieCachePath($id));
            $tmp = $poster . '.upload-' . bin2hex(random_bytes(5));
            if (file_put_contents($tmp, $bytes, LOCK_EX) === false) return ['ok'=>false, 'error'=>'Could not store poster'];
            Posters::clearCachedArtwork($poster);
            if (!rename($tmp, $poster)) { @unlink($tmp); return ['ok'=>false, 'error'=>'Could not replace poster']; }
        }
        $rows = [];
        foreach ($items as $item) {
            $row = ['id'=>$item['id'], 'year'=>$year];
            if ($showKey !== '') $row['show'] = $title; else $row['title'] = $title;
            if ($poster) $row[$showKey !== '' ? 'show_poster' : 'poster'] = $poster;
            $rows[] = $row;
        }
        $result = Matching::saveItemFields($rows, 'manual', (string)(Permissions::require('metadata_edit')['username'] ?? 'admin'), false, false);
        if (empty($result['ok'])) return $result;
        if ($showKey !== '') {
            if ($target !== $showKey) {
                $overrides = Matching::overrides();
                if (isset($overrides['shows'][$showKey])) { $overrides['shows'][$target] = $overrides['shows'][$showKey]; Matching::saveOverrides($overrides); }
                foreach (['_episodes.json','_seasons.json','_miss.json'] as $suffix) {
                    $old = STREAM_DATA . '/tvmaze/show_' . $showKey . $suffix;
                    $new = STREAM_DATA . '/tvmaze/show_' . $target . $suffix;
                    if (is_file($old) && !is_file($new)) @copy($old, $new);
                }
            }
            $pin = MetadataPins::get('show_meta', $showKey) ?? [];
            $pin['summary'] = $summary; $pin['manual'] = true;
            MetadataPins::put('show_meta', $target, $pin);
            MetadataSidecars::invalidateShow($showKey);
            MetadataSidecars::invalidateShow($target);
        } else {
            $pin = ['summary'=>$summary, 'source'=>'Manual', 'provider'=>'Manual', 'manual'=>true, 'fetched_at'=>date('c')];
            MetadataPins::put('movie_synopsis', $id, $pin);
            @file_put_contents(Posters::movieSynopsisCachePath($id), json_encode($pin, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
            MetadataSidecars::invalidateMovie($items[0]);
        }
        Catalogue::invalidateDerivedCaches();
        $backup = MetadataSidecars::schedule();
        return ['ok'=>true, 'id'=>$id, 'show_key'=>$showKey !== '' ? $target : null, 'warning'=>$backup['warning'] ?? null];
    }
}
