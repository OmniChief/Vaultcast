<?php
/**
 * Fix Match pins (curated metadata chosen by an admin) live in the database table
 * vaultcast_metadata_pins. They used to be JSON files under data/synopsis/; a legacy file
 * is imported on first read (and in bulk by Migrations), so upgrades lose nothing.
 */
final class MetadataPins
{
    const KINDS = ['movie_synopsis', 'movie_poster', 'show_meta'];

    /** Test seam: when set to an array, pins live in memory instead of the database. */
    public static $memory = null;

    public static function legacyPath(string $kind, string $id): string
    {
        if ($kind === 'movie_synopsis') return Posters::movieSynopsisCachePath($id);
        if ($kind === 'movie_poster') return Posters::moviePosterPinPath($id);
        return Posters::showMetaPinPath($id);
    }

    public static function get(string $kind, string $id): ?array
    {
        $row = self::read($kind, $id);
        if ($row !== null) return !empty($row['cleared']) ? null : $row;
        $legacy = self::legacy($kind, $id);
        if ($legacy !== null) self::put($kind, $id, $legacy);
        return $legacy;
    }

    public static function put(string $kind, string $id, array $pin): void
    {
        $pin['pinned'] = true;
        self::write($kind, $id, $pin);
    }

    /** Remove a pin. A tombstone stops a stale legacy file from being re-imported. */
    public static function clear(string $kind, string $id): void
    {
        self::write($kind, $id, ['cleared' => true]);
        $f = self::legacyPath($kind, $id);
        if ($kind !== 'movie_synopsis') @unlink($f);
    }

    /** A pinned legacy file, or null. */
    public static function legacy(string $kind, string $id): ?array
    {
        $f = self::legacyPath($kind, $id);
        if (!is_file($f)) return null;
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j) || empty($j['pinned'])) return null;
        if ($kind === 'movie_synopsis' && (!is_string($j['summary'] ?? null) || $j['summary'] === '')) return null;
        return $j;
    }

    /** Import every pinned legacy file that has no row yet. Safe to run repeatedly. */
    public static function importLegacy(): int
    {
        $dir = STREAM_DATA . '/synopsis';
        $n = 0;
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            $base = basename($f);
            if (preg_match('/^movie_(.+)\.poster\.json$/', $base, $m)) { $kind = 'movie_poster'; }
            elseif (preg_match('/^show_(.+)\.meta\.json$/', $base, $m)) { $kind = 'show_meta'; }
            elseif (preg_match('/^movie_([^.]+)\.json$/', $base, $m)) { $kind = 'movie_synopsis'; }
            else continue;
            $id = $m[1];
            if (self::read($kind, $id) !== null) continue;
            $pin = self::legacy($kind, $id);
            if ($pin === null) continue;
            self::put($kind, $id, $pin);
            $n++;
        }
        return $n;
    }

    private static function read(string $kind, string $id): ?array
    {
        if (is_array(self::$memory)) return self::$memory[$kind . ':' . $id] ?? null;
        $s = \Vaultcast\Database::connection()->prepare('SELECT payload FROM vaultcast_metadata_pins WHERE kind = ? AND target_id = ?');
        $s->execute([$kind, $id]);
        $v = $s->fetchColumn();
        if ($v === false) return null;
        $j = json_decode((string)$v, true);
        return is_array($j) ? $j : null;
    }

    private static function write(string $kind, string $id, array $pin): void
    {
        if (!in_array($kind, self::KINDS, true)) throw new InvalidArgumentException('Unknown pin kind');
        if (is_array(self::$memory)) { self::$memory[$kind . ':' . $id] = $pin; return; }
        $s = \Vaultcast\Database::connection()->prepare('INSERT INTO vaultcast_metadata_pins (kind, target_id, payload, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = VALUES(updated_at)');
        $s->execute([$kind, $id, json_encode($pin, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), time()]);
    }
}
