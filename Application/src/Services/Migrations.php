<?php
/**
 * One-time, idempotent storage migrations for existing installs. Fresh installs get the
 * same end state from schema.sql. Every step is safe to re-run: a lost stamp file only
 * costs one extra pass.
 */
final class Migrations
{
    const VERSION = 3; // 2: library folder access pass for nginx (step 5); 3: re-run it, recording results

    public static function stampFile(): string
    {
        // Keyed on the app version too, so every update re-runs the (idempotent) steps once.
        $app = defined('STREAM_VERSION') ? preg_replace('/[^0-9A-Za-z.]/', '', STREAM_VERSION) : '0';
        return STREAM_SHM . '/migrations-v' . self::VERSION . '-app' . $app . '.done';
    }

    public static function runIfNeeded(): void
    {
        if (is_file(self::stampFile())) return;
        $pdo = \Vaultcast\Database::connection();
        // Serialise concurrent first requests across PHP workers (MariaDB named lock).
        $got = (int)$pdo->query("SELECT GET_LOCK('vaultcast_migrations', 30)")->fetchColumn();
        if ($got !== 1) return;
        try {
            if (!is_file(self::stampFile())) {
                self::run($pdo);
                @file_put_contents(self::stampFile(), date('c') . "\n", LOCK_EX);
            }
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('vaultcast_migrations')");
        }
    }

    /** One pass over configured library folders; returns counts, never throws. */
    public static function grantLibraryAccess(?array $sections = null, ?callable $grant = null): array
    {
        $out = ['granted' => 0, 'skipped' => 0, 'failed' => 0];
        try {
            $sections = $sections ?? (Store::settings()['sections'] ?? []);
            foreach ($sections as $sec) {
                $p = is_array($sec) ? (string)($sec['path'] ?? '') : '';
                if ($p === '' || !@is_dir($p)) { $out['skipped']++; continue; }
                $r = $grant !== null ? \Vaultcast\Paths::grantWebServerRead($p, $grant) : MediaAccel::grant($p);
                if (!$r['ok']) { $out['failed']++; error_log('Vaultcast library access: ' . $r['message']); }
                elseif (!empty($r['skipped'])) $out['skipped']++;
                else $out['granted']++;
            }
        } catch (\Throwable $e) {
            error_log('Vaultcast library access pass failed: ' . $e->getMessage());
            $out['error'] = $e->getMessage();
        }
        return $out;
    }

    /** @return array<string,mixed> what each step did */
    public static function run(PDO $pdo): array
    {
        $log = [];
        // 1. Fix Match pins: files -> vaultcast_metadata_pins.
        foreach (explode(';', (string)file_get_contents(dirname(__DIR__) . '/schema.sql')) as $sql) {
            if (stripos($sql, 'vaultcast_metadata_pins') !== false && trim($sql) !== '') $pdo->exec($sql);
        }
        $log['pins_imported'] = MetadataPins::importLegacy();
        // 2. IPTV probe status: data/live/iptv_status.json -> document live_iptv_status.
        if (!function_exists('iptv_status_import_legacy')) require_once dirname(__DIR__) . '/Live/iptv.php';
        $log['iptv_status_imported'] = iptv_status_import_legacy() !== null;
        // 3. TTL cache moved to data/cache/kv; drop the stale rows (all rebuildable).
        if ($pdo->query("SHOW TABLES LIKE 'vaultcast_cache'")->fetchColumn()) {
            $log['cache_rows_cleared'] = (int)$pdo->exec('DELETE FROM vaultcast_cache');
        }
        // 4. Legacy poster queue file already copied into its document on first read.
        $legacyQueue = STREAM_DATA . '/poster_optimise_queue.json';
        if (is_file($legacyQueue) && \Vaultcast\Repository::document('poster_optimise_queue', null) !== null) {
            @rename($legacyQueue, $legacyQueue . '.migrated');
            $log['poster_queue_file_retired'] = true;
        }
        // 5. Existing libraries: let nginx (LocalService on Windows) read each library folder.
        //    Idempotent; failures are logged and never block startup.
        $log['library_acl'] = self::grantLibraryAccess();
        error_log('Vaultcast storage migration v' . self::VERSION . ': ' . json_encode($log));
        return $log;
    }
}
