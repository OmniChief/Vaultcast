<?php
/**
 * One-time, idempotent storage migrations for existing installs. Fresh installs get the
 * same end state from schema.sql. Every step is safe to re-run: a lost stamp file only
 * costs one extra pass.
 */
final class Migrations
{
    const VERSION = 13; // 13: smart collections and their facts index; 12: age ratings and parental controls / kids profiles; 11: site-wide webcam registry (Ambient); 10: calendars, events, reminders and personal pictures (Misc); 9: leaderboard covering indexes, Drop anonymous origin column and MEDIUMTEXT notes; 8: play scores/points and drop storage; 2: library folder access pass for nginx (step 5); 3: re-run it, recording results; 4: media facts table; 5: arrival timestamps and indexed running times; 6: chat storage; 7: prepared browsing

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
        if ($got !== 1) {
            if (is_file(self::stampFile())) return;
            throw new RuntimeException('Database upgrade is still running. Retry shortly.');
        }
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
    /**
     * v9: covering indexes for leaderboards and Drop columns. Idempotent and guarded with SHOW checks so it
     * works on MariaDB and MySQL alike (neither ADD INDEX IF NOT EXISTS nor ADD COLUMN IF NOT EXISTS is portable).
     */
    private static function playDropUpgrade(PDO $pdo): array {
        $done = [];
        $hasIndex = function (string $table, string $name) use ($pdo): bool { $s = $pdo->prepare('SHOW INDEX FROM ' . $table . ' WHERE Key_name = ?'); $s->execute([$name]); return (bool)$s->fetch(); };
        $column = function (string $table, string $name) use ($pdo): ?array { $s = $pdo->query('SHOW COLUMNS FROM ' . $table . " LIKE '" . $name . "'"); $r = $s ? $s->fetch(PDO::FETCH_ASSOC) : false; return $r ?: null; };
        if (!$hasIndex('vaultcast_play_scores', 'play_board_user')) { $pdo->exec('ALTER TABLE vaultcast_play_scores ADD INDEX play_board_user (game,created_at,user_id,score)'); $done[] = 'play_board_user'; }
        if (!$hasIndex('vaultcast_play_points', 'play_points_board')) { $pdo->exec('ALTER TABLE vaultcast_play_points ADD INDEX play_points_board (created_at,user_id,points)'); $done[] = 'play_points_board'; }
        if (!$column('vaultcast_drop_files', 'origin')) { $pdo->exec("ALTER TABLE vaultcast_drop_files ADD COLUMN origin VARCHAR(64) NOT NULL DEFAULT ''"); $done[] = 'drop_origin'; }
        $body = $column('vaultcast_drop_files', 'body');
        if ($body && strtolower((string)$body['Type']) === 'text') { $pdo->exec('ALTER TABLE vaultcast_drop_files MODIFY body MEDIUMTEXT NULL'); $done[] = 'drop_body_mediumtext'; }
        return $done;
    }

    public static function run(PDO $pdo): array
    {
        $log = [];
        foreach (explode(';', (string)file_get_contents(dirname(__DIR__) . '/schema.sql')) as $sql) {
            if (preg_match('/CREATE TABLE IF NOT EXISTS vaultcast_(chat_|browse_items|show_summaries|play_|drop_|cal_|pictures\b|webcams\b|content_ratings\b|parental\b|smart_)/i',$sql)) $pdo->exec($sql);
        }
        $upgrade = self::playDropUpgrade($pdo);
        // v11: the registry table only; existing webcams are imported lazily (Webcams::ensureBackfill) on first use.
        // v13: smart collection tables above; nothing to convert.
        if (is_file(STREAM_DATA.'/runtime/migrations-v12.done')) return ['smart_collections'=>true, 'play_drop_upgrade'=>$upgrade];
        // v12: the two new tables above (age ratings, parental settings); nothing to convert.
        if (is_file(STREAM_DATA.'/runtime/migrations-v11.done')) return ['parental_controls'=>true, 'play_drop_upgrade'=>$upgrade];
        if (is_file(STREAM_DATA.'/runtime/migrations-v10.done')) return ['webcam_registry'=>true, 'play_drop_upgrade'=>$upgrade];
        if (is_file(STREAM_DATA.'/runtime/migrations-v9.done')) return ['calendar_reminders_pictures'=>true, 'play_drop_upgrade'=>$upgrade];
        if (is_file(STREAM_DATA.'/runtime/migrations-v8.done')) return ['play_drop_upgrade'=>$upgrade];
        if (is_file(STREAM_DATA.'/runtime/migrations-v7.done')) return ['play_and_drop'=>true];
        if (is_file(STREAM_DATA.'/runtime/migrations-v6.done')) return ['prepared_browsing'=>true];
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
