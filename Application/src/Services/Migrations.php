<?php
/**
 * One-time, idempotent storage migrations for existing installs. Fresh installs get the
 * same end state from schema.sql. Every step is safe to re-run: a lost stamp file only
 * costs one extra pass.
 */
final class Migrations
{
    const VERSION = 6; // 2: library folder access pass for nginx (step 5); 3: re-run it, recording results; 4: media facts table; 5: arrival timestamps and indexed running times; 6: chat storage

    public static function stampFile(): string
    {
        // Schema version only; clearing disposable caches or updating JS must not repeat ACL walks.
        return STREAM_DATA . '/runtime/migrations-v' . self::VERSION . '.done';
    }

    public static function runIfNeeded(): void
    {
        if (is_file(self::stampFile())) return;
        if (!is_dir(dirname(self::stampFile()))) @mkdir(dirname(self::stampFile()), 0775, true);
        // Adopt an already completed migration from any previous app version.
        if (glob(STREAM_SHM . '/migrations-v' . self::VERSION . '-app*.done')) {
            @file_put_contents(self::stampFile(), date('c') . "\n", LOCK_EX);
            return;
        }
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

    private static function homeFeatures(PDO $pdo): void {
        if (!$pdo->query("SHOW COLUMNS FROM vaultcast_items LIKE 'added_at'")->fetch()) {
            $pdo->exec('ALTER TABLE vaultcast_items ADD added_at BIGINT NOT NULL DEFAULT 0, ADD INDEX section_added (section_id,added_at)');
        }
        MediaFacts::ensureTable($pdo);
        if (!$pdo->query("SHOW COLUMNS FROM vaultcast_media_facts LIKE 'duration'")->fetch()) {
            $pdo->exec('ALTER TABLE vaultcast_media_facts ADD duration DOUBLE NOT NULL DEFAULT 0, ADD INDEX duration_index (duration)');
        }
    }

    /** @return array<string,mixed> what each step did */
    public static function run(PDO $pdo): array
    {
        $log = [];
        foreach (explode(';', (string)file_get_contents(dirname(__DIR__) . '/schema.sql')) as $sql) {
            if (stripos($sql, 'CREATE TABLE IF NOT EXISTS vaultcast_chat_') !== false) $pdo->exec($sql);
        }
        if (is_file(STREAM_DATA.'/runtime/migrations-v5.done')) return ['chat'=>true];
        if (is_file(STREAM_DATA.'/runtime/migrations-v4.done')) {self::homeFeatures($pdo);return ['home_features'=>true];}
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
        // 6. Codec / needs-convert facts table; the background 'facts' task fills it.
        MediaFacts::ensureTable($pdo);
        $log['media_facts_table'] = true;
        self::homeFeatures($pdo);
        error_log('Vaultcast storage migration v' . self::VERSION . ': ' . json_encode($log));
        return $log;
    }
}
