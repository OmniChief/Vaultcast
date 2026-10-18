<?php
declare(strict_types=1);

final class GameMetadataRefresh
{
    public static function run(?string $target, callable $progress): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Game metadata refresh requires the maintenance worker.');
        if (!Posters::enabled()) throw new RuntimeException('Enable remote posters in Settings first.');
        $where = 'media_kind=?'; $params = ['rom'];
        if ($target !== null) { $where .= ' AND id=?'; $params[] = $target; }
        $count = \Vaultcast\Database::connection()->prepare('SELECT COUNT(*) FROM vaultcast_items WHERE ' . $where);
        $count->execute($params); $total = (int)$count->fetchColumn();
        $out = ['ok'=>true, 'processed'=>0, 'posters'=>0, 'synopses'=>0, 'unresolved'=>0, 'failed'=>0];
        $cursor = ''; $progress(0, $total, '');
        do {
            $items = \Vaultcast\Repository::rows('SELECT payload FROM vaultcast_items WHERE ' . $where . ' AND id>? ORDER BY id LIMIT 25', array_merge($params, [$cursor]));
            foreach ($items as $item) {
                Maintenance::checkpoint(); $cursor = (string)$item['id'];
                try {
                    Posters::clearFailed('game', $cursor);
                    $cached = Posters::gameSynopsisCached($cursor);
                    if ($cached['summary'] === null && MetadataPins::get('movie_synopsis', $cursor) === null) @unlink(Posters::movieSynopsisCachePath($cursor));
                    Posters::beginRemoteBudget(8);
                    $art = ArtworkLibrary::prepare(['kind'=>'games', 'item'=>$item, 'path'=>Posters::gameCachePath($cursor)], true);
                    Posters::beginRemoteBudget(8); $summary = Posters::gameSynopsis($item);
                    if ($art) $out['posters']++;
                    if ($summary) $out['synopses']++;
                    if (!$art || !$summary) $out['unresolved']++;
                } catch (Throwable $e) { $out['failed']++; error_log('Vaultcast game metadata ' . $cursor . ': ' . $e->getMessage()); }
                finally { Posters::endRemoteBudget(); }
                $out['processed']++; $progress($out['processed'], $total, (string)($item['title'] ?? $cursor));
            }
        } while (count($items) === 25);
        return $out;
    }
}
