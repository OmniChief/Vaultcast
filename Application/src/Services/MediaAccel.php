<?php
declare(strict_types=1);

/**
 * Decides, per library folder, whether nginx can serve media via X-Accel-Redirect.
 *
 * On Windows nginx runs as LocalService and needs read access to each library folder
 * (granted by LibraryAccess::grantServiceRead). If that grant has not succeeded for the
 * account PHP runs as now, or a player recently had to fall back, media is streamed by
 * PHP instead (byte ranges, session locks released), so playback works either way.
 * Results are kept in the library_access_status document and retried automatically.
 */
final class MediaAccel {
    public const DOC = 'library_access_status';
    public const OK_TTL = 604800;      // re-check a good folder weekly (cheap, idempotent)
    public const RETRY_AFTER = 600;    // retry a failed folder after 10 minutes

    /** @var callable|null fn(): array  (tests) */
    public static $load = null;
    /** @var callable|null fn(array): void  (tests) */
    public static $save = null;
    /** @var callable|null fn(string): array  grant override (tests) */
    public static $grant = null;
    /** @var string|null OS override (tests) */
    public static $os = null;

    private static function status(): array {
        try {
            $s = self::$load ? (self::$load)() : \Vaultcast\Repository::document(self::DOC, []);
        } catch (\Throwable $e) { $s = []; }
        return is_array($s) ? $s : [];
    }

    private static function put(string $root, array $rec): void {
        try {
            $s = self::status();
            $s[self::key($root)] = $rec;
            if (self::$save) (self::$save)($s); else \Vaultcast\Repository::putDocument(self::DOC, $s);
        } catch (\Throwable $e) {
            error_log('Vaultcast media access status not saved: ' . $e->getMessage());
        }
    }

    public static function key(string $root): string {
        $p = rtrim(str_replace('\\', '/', $root), '/');
        return (self::$os ?? PHP_OS_FAMILY) === 'Windows' ? strtolower($p) : $p;
    }

    private static function windows(): bool {
        return (self::$os ?? PHP_OS_FAMILY) === 'Windows';
    }

    private static function grantAvailable(): bool {
        if (self::$grant) return true;
        if (!class_exists('LibraryAccess', true) && is_file(__DIR__ . '/LibraryAccess.php')) require_once __DIR__ . '/LibraryAccess.php';
        return class_exists('LibraryAccess', false) && method_exists('LibraryAccess', 'grantServiceRead');
    }

    /** Grant nginx read access to $root now and record the result. */
    public static function grant(string $root): array {
        $r = \Vaultcast\Paths::grantWebServerRead($root, self::$grant);
        self::put($root, [
            'ok' => !empty($r['ok']), 'skipped' => !empty($r['skipped']), 'at' => time(),
            'account' => \Vaultcast\Paths::serviceAccount(),
            'error' => empty($r['ok']) ? (string)($r['message'] ?? '') : '',
        ]);
        if (empty($r['ok'])) error_log('Vaultcast media access: ' . ($r['message'] ?? 'grant failed'));
        return $r;
    }

    /** A player could not use the nginx path for this folder: stream via PHP for a while, then re-grant. */
    public static function markFailed(string $root, string $why = 'player fallback'): void {
        $rec = self::status()[self::key($root)] ?? [];
        $rec = (is_array($rec) ? $rec : []);
        if (!empty($rec['client_failed_at']) && time() - (int)$rec['client_failed_at'] < 60) return; // one write per minute (range requests)
        $rec['client_failed_at'] = time();
        $rec['client_failed_reason'] = $why;
        self::put($root, $rec);
    }

    /** May nginx serve files under $root? Grants (and records) when unknown or stale. */
    public static function rootUsable(string $root, ?int $now = null): bool {
        if (!self::windows()) return true;
        if (!self::grantAvailable()) return true; // no ACL helper: keep the installer's behaviour
        $now = $now ?? time();
        $rec = self::status()[self::key($root)] ?? null;
        $account = \Vaultcast\Paths::serviceAccount();
        if (is_array($rec)) {
            $failedRecently = !empty($rec['client_failed_at']) && $now - (int)$rec['client_failed_at'] < self::RETRY_AFTER;
            if ($failedRecently) return false;
            $fresh = ($rec['account'] ?? '') === $account;
            $age = $now - (int)($rec['at'] ?? 0);
            $grantedAfterFailure = empty($rec['client_failed_at']) || (int)($rec['at'] ?? 0) > (int)$rec['client_failed_at'];
            if ($fresh && !empty($rec['ok']) && $age < self::OK_TTL && $grantedAfterFailure) return true;
            if ($fresh && empty($rec['ok']) && $age < self::RETRY_AFTER) return false;
        }
        return !empty(self::grant($root)['ok']);
    }
}
