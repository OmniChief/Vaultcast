<?php
declare(strict_types=1);

/**
 * Human-readable, stable URL slugs for movies and shows.
 *
 * The registry (DB document "slugs") maps kind => slug => id. A slug is assigned once and kept, so
 * links never change when another title with the same name is added later; collisions get -2, -3, ...
 * The resolver also accepts raw ids, so a link built before a slug existed keeps working.
 */
final class Slugs
{
    const DOC = 'slugs';
    const KINDS = ['movie', 'show'];

    /** @var array|null kind => slug => id */
    private static $reg = null;
    /** @var array kind => id => slug */
    private static $byId = [];
    /** @var array kind => slug => id assigned this request, merged on flush */
    private static $pending = [];
    private static $hooked = false;
    /** Test seams: fn(): array and fn(array): void */
    public static $load = null;
    public static $save = null;

    public static function slugify(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($t) && $t !== '') $s = $t;
        }
        $s = strtolower(strtr($s, ['&' => ' and ', '+' => ' plus ', "'" => '', '’' => '', '"' => '']));
        $s = (string)preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim($s, '-');
        if (strlen($s) > 80) $s = rtrim(substr($s, 0, 80), '-');
        return $s;
    }

    public static function base(string $title, $year = null): string
    {
        $b = self::slugify($title);
        if ($b === '') $b = 'untitled';
        $y = (int)$year;
        if ($y >= 1870 && $y <= 2200 && !preg_match('/-' . $y . '$/', $b)) $b .= '-' . $y;
        return $b;
    }

    private static function load(): void
    {
        if (self::$reg !== null) return;
        $doc = self::$load ? call_user_func(self::$load) : \Vaultcast\Repository::document(self::DOC, []);
        self::$reg = [];
        foreach (self::KINDS as $k) self::$reg[$k] = is_array($doc[$k] ?? null) ? $doc[$k] : [];
        self::reindex();
    }

    private static function reindex(): void
    {
        self::$byId = [];
        foreach (self::$reg as $k => $map) {
            self::$byId[$k] = [];
            foreach ($map as $slug => $id) if (!isset(self::$byId[$k][(string)$id])) self::$byId[$k][(string)$id] = (string)$slug;
        }
    }

    /** Pure assignment on a registry map (slug => id): returns the stable slug for $id. */
    public static function assignIn(array &$map, string $id, string $base, ?string $alt = null): string
    {
        foreach ($map as $slug => $owner) if ((string)$owner === $id) return (string)$slug;
        $candidates = [$base];
        if ($alt !== null && $alt !== '' && $alt !== $base) $candidates[] = $alt;
        foreach ($candidates as $c) if (!isset($map[$c]) && !self::reservedLike($c)) { $map[$c] = $id; return $c; }
        for ($n = 2; ; $n++) {
            $c = $base . '-' . $n;
            if (!isset($map[$c])) { $map[$c] = $id; return $c; }
        }
    }

    /** A slug that looks like a raw id would be ambiguous with the id fallback. */
    private static function reservedLike(string $slug): bool
    {
        return (bool)preg_match('/^[a-f0-9]{12,}$/', $slug);
    }

    public static function forId(string $kind, string $id, string $base, ?string $alt = null): string
    {
        if ($id === '') return '';
        self::load();
        if (isset(self::$byId[$kind][$id])) return self::$byId[$kind][$id];
        $slug = self::assignIn(self::$reg[$kind], $id, $base, $alt);
        self::$byId[$kind][$id] = $slug;
        self::$pending[$kind][$slug] = $id;
        if (!self::$hooked && self::$save === null) { self::$hooked = true; register_shutdown_function([self::class, 'flush']); }
        return $slug;
    }

    public static function movie(array $it): string
    {
        return self::forId('movie', (string)($it['id'] ?? ''), self::base((string)($it['title'] ?? ''), $it['year'] ?? null));
    }

    public static function show(string $showKey, string $title, $year = null): string
    {
        $b = self::base($title);
        return self::forId('show', $showKey, $b, $year ? self::base($title, $year) : null);
    }

    /** slug (or raw id) => id */
    public static function resolve(string $kind, string $slug): ?string
    {
        self::load();
        $slug = strtolower(trim($slug));
        if (isset(self::$reg[$kind][$slug])) return (string)self::$reg[$kind][$slug];
        return null;
    }

    /** Write new assignments, merging with whatever another request saved meanwhile. */
    public static function flush(): void
    {
        if (!self::$pending) return;
        $fresh = self::$load ? call_user_func(self::$load) : \Vaultcast\Repository::document(self::DOC, []);
        foreach (self::KINDS as $k) {
            $map = is_array($fresh[$k] ?? null) ? $fresh[$k] : [];
            foreach (self::$pending[$k] ?? [] as $slug => $id) {
                if (!isset($map[$slug])) { $map[$slug] = $id; continue; }
                if ((string)$map[$slug] === (string)$id) continue;
                // someone else took it concurrently: keep theirs, re-assign ours
                self::assignIn($map, (string)$id, (string)$slug);
            }
            $fresh[$k] = $map;
        }
        self::$pending = [];
        if (self::$save) call_user_func(self::$save, $fresh); else \Vaultcast\Repository::putDocument(self::DOC, $fresh);
        self::$reg = null;
    }

    /** Test helper. */
    public static function reset(): void { self::$reg = null; self::$byId = []; self::$pending = []; self::$hooked = false; }
}
