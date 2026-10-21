<?php
declare(strict_types=1);

final class AreaAccess
{
    const AREAS = ['library','live','games'];
    private static $viewer;
    private static $loaded = false;

    public static function remember(?array $user): void { self::$viewer = $user; self::$loaded = true; }

    public static function permissions(?array $user): array
    {
        $out = [];
        foreach (self::AREAS as $area) $out[$area] = !$user || ($user['role'] ?? '') === 'admin' || !is_array($user['areas'] ?? null) || !empty($user['areas'][$area]);
        // Parental controls can switch Live and Play off on top of the admin's area choices.
        if ($user && ($user['role'] ?? '') !== 'admin' && class_exists('Parental')) {
            $limits = Parental::areaLimits($user);
            if (!$limits['live']) $out['live'] = false;
            if (!$limits['games']) $out['games'] = false;
        }
        return $out;
    }

    public static function allowed(?array $user, string $area): bool
    {
        return self::permissions($user)[$area] ?? false;
    }

    public static function viewer(): ?array
    {
        if (!self::$loaded) { $session = Auth::currentSession(); self::remember($session ? Auth::findById((string)$session['user_id']) : null); }
        return self::$viewer;
    }

    public static function itemArea(array $item): string
    {
        return ($item['media_kind'] ?? '') === 'rom' || ($item['type'] ?? '') === 'roms' ? 'games' : 'library';
    }

    public static function denied(?array $user, string $area): ?array
    {
        return self::allowed($user, $area) ? null : [403, ['ok'=>false, 'code'=>'area_denied', 'area'=>$area, 'error'=>'Your account does not have access to ' . ['library'=>'Library','live'=>'Live','games'=>'Games'][$area] . '.']];
    }

    public static function gate(string $path): ?array
    {
        if (in_array($path, Access::OPEN_PATHS, true) || strpos($path, '/admin/') === 0 || strpos($path, '/player-assets/') === 0) return null;
        $user = self::viewer();
        if (!$user || ($user['role'] ?? '') === 'admin') return null;
        if (count(array_filter(self::permissions($user))) === count(self::AREAS)) return null;
        $area = null;
        if (preg_match('#^/(?:live)(?:/|$)#', $path)) $area = 'live';
        elseif (preg_match('#^/(?:lobby|netplay|stream/bios|play)(?:/|$)#', $path)) $area = 'games';
        elseif (preg_match('#^/(?:shows|collections)(?:/|$)#', $path)) $area = 'library';
        elseif (preg_match('#^/items/([a-f0-9]+)(?:/|$)#', $path, $m)) {
            $item = Catalogue::item($m[1]);
            if ($item) $area = self::itemArea($item);
            else return [404, ['ok'=>false, 'error'=>'Item not found']];
        } elseif (preg_match('#^/sections/([^/]+)(?:/|$)#', $path, $m)) {
            foreach (Store::settings()['sections'] ?? [] as $section) if (($section['id'] ?? '') === $m[1]) { $area = ($section['type'] ?? '') === 'roms' ? 'games' : 'library'; break; }
            if ($area === null) return [404, ['ok'=>false, 'error'=>'Section not found']];
        } elseif ($path === '/resolve') {
            $target = '/' . trim((string)parse_url((string)($_GET['path'] ?? ''), PHP_URL_PATH), '/');
            $parts = array_values(array_filter(explode('/', $target), 'strlen'));
            $head = rawurldecode($parts[0] ?? '');
            if ($head === 'games') $area = 'games';
            elseif (in_array($head, ['movies','tvshows'], true)) $area = 'library';
        }
        return $area ? self::denied($user, $area) : null;
    }

    public static function home(array $home, ?array $user): array
    {
        $permissions = self::permissions($user);
        $home['areas'] = $permissions;
        $home['sections'] = array_values(array_filter($home['sections'] ?? [], static function(array $section) use ($permissions): bool { return $permissions[($section['type'] ?? '') === 'roms' ? 'games' : 'library']; }));
        $home['continue_watching'] = array_values(array_filter($home['continue_watching'] ?? [], static function(array $item) use ($permissions): bool { return $permissions[self::itemArea($item)]; }));
        if (!$permissions['live']) $home['features']['live'] = false;
        if (!$permissions['library'] || !$permissions['games']) {
            $home['item_count'] = array_sum(array_map(static function(array $section): int { return (int)($section['item_count'] ?? $section['count'] ?? 0); }, $home['sections']));
        }
        return $home;
    }
}
