<?php
declare(strict_types=1);

/** Parses clean app paths into a view description. Pure, so it is shared by the API and tests. */
final class UrlRoutes
{
    /**
     * @param callable $lookup fn(string $kind, string $slug): ?string  ('movie'|'show') => id
     */
    public static function resolve(string $path, callable $lookup): array
    {
        $path = '/' . trim((string)parse_url($path, PHP_URL_PATH), '/');
        $parts = array_values(array_filter(explode('/', $path), 'strlen'));
        $parts = array_map(function ($p) { return rawurldecode($p); }, $parts);
        $head = $parts[0] ?? '';
        if ($head === 'movies' && isset($parts[1])) {
            $id = $lookup('movie', strtolower($parts[1]));
            return $id ? ['ok' => true, 'view' => 'movie', 'movie_id' => $id] : ['ok' => false, 'error' => 'Movie not found', 'view' => 'notfound'];
        }
        if ($head === 'tvshows' && isset($parts[1])) {
            $key = $lookup('show', strtolower($parts[1]));
            if (!$key) return ['ok' => false, 'error' => 'Show not found', 'view' => 'notfound'];
            $out = ['ok' => true, 'view' => 'show', 'show_key' => $key, 'season' => null, 'episode' => null, 'item_id' => null];
            if (isset($parts[2]) && preg_match('/^season-(\d{1,3})$/', $parts[2], $m)) {
                $out['season'] = (int)$m[1];
                if (isset($parts[3])) {
                    if (preg_match('/^episode-(\d{1,4})(?:-|$)/', $parts[3], $e)) $out['episode'] = (int)$e[1];
                    elseif (preg_match('/^[a-f0-9]{8,64}$/', $parts[3])) $out['item_id'] = $parts[3];
                }
            }
            return $out;
        }
        if ($head === 'games' && isset($parts[1]) && $parts[1] !== 'lobby') {
            $sys = strtolower($parts[1]);
            if (isset($parts[2])) {
                $id = $lookup('game', $sys . '/' . strtolower($parts[2]));
                return $id ? ['ok' => true, 'view' => 'game', 'item_id' => $id] : ['ok' => false, 'error' => 'Game not found', 'view' => 'notfound'];
            }
            $label = $lookup('system', $sys);
            return $label !== null ? ['ok' => true, 'view' => 'games_system', 'system' => $label, 'system_slug' => $sys] : ['ok' => false, 'error' => 'Console not found', 'view' => 'notfound'];
        }
        return ['ok' => false, 'error' => 'Not a slug route', 'view' => 'unknown'];
    }
}
