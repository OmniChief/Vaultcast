<?php
/**
 * Per-file facts gathered once in the background (ffprobe codecs, needs-convert) and the
 * TVMaze episode guide per show, so list/detail requests read only MariaDB.
 * Facts are keyed by item id and tagged with the file's mtime|size from the catalogue
 * payload; a rescan that sees a new mtime/size makes the row stale and the next pass re-probes it.
 * PHP 7.4 / MariaDB 10.3 safe.
 */
final class MediaFacts
{
    /** @var array<string,array>|null */
    private static $map = [];

    const TABLE_SQL = 'CREATE TABLE IF NOT EXISTS vaultcast_media_facts (
    item_id VARCHAR(64) NOT NULL PRIMARY KEY,
    sig VARCHAR(48) CHARACTER SET ascii NOT NULL,
    video_codec VARCHAR(32) NOT NULL DEFAULT \'\',
    audio_codec VARCHAR(32) NOT NULL DEFAULT \'\',
    needs_convert TINYINT(1) NOT NULL DEFAULT 0,
    present TINYINT(1) NOT NULL DEFAULT 1,
    probed_at BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public static function ensureTable(PDO $pdo): void
    {
        $pdo->exec(self::TABLE_SQL);
    }

    public static function sig(array $it): string
    {
        return (int)($it['mtime'] ?? 0) . '|' . (int)($it['size'] ?? 0);
    }

    /** Fetch only the facts needed by this response, in bounded primary-key batches. */
    public static function preload(array $items): void
    {
        $ids = [];
        foreach ($items as $it) {
            $id = (string)($it['id'] ?? '');
            if ($id !== '' && !array_key_exists($id, self::$map)) $ids[$id] = $id;
        }
        foreach (array_chunk(array_values($ids), 200) as $batch) {
            foreach ($batch as $id) self::$map[$id] = null;
            try {
                $s = \Vaultcast\Database::connection()->prepare('SELECT item_id,sig,needs_convert,present FROM vaultcast_media_facts WHERE item_id IN (' . implode(',', array_fill(0, count($batch), '?')) . ')');
                $s->execute($batch);
                foreach ($s as $row) self::$map[(string)$row['item_id']] = $row;
            } catch (\Throwable $e) { /* not migrated yet: extension fallback */ }
        }
    }

    /** Fresh facts for one item, without reading the entire library's facts table. */
    public static function get(array $it): ?array
    {
        self::preload([$it]);
        $row = self::$map[(string)($it['id'] ?? '')] ?? null;
        return ($row && (string)$row['sig'] === self::sig($it)) ? $row : null;
    }

    /** Audio-track details prepared by the existing facts worker. */
    public static function audio(array $it): array
    {
        $row = \Vaultcast\Repository::document('media-audio:' . (string)$it['id'], []);
        return ($row['sig'] ?? '') === self::sig($it) ? ($row['probe'] ?? []) : [];
    }

    public static function codecsNeedConvert(string $vc, string $ac): bool
    {
        $vc = strtolower($vc); $ac = strtolower($ac);
        $h264 = ($vc === 'h264' || $vc === 'avc' || strpos($vc, 'h264') === 0 || strpos($vc, 'avc') === 0);
        $aacOk = ($ac === '' || $ac === 'aac' || $ac === 'mp4a' || strpos($ac, 'aac') === 0);
        return !($h264 && $aacOk);
    }

    /** TVMaze guide stored by the background pass (DB document); never hits disk or network. */
    public static function guide(string $showKey): ?array
    {
        $g = \Vaultcast\Repository::document('tvmaze-guide:' . $showKey, null);
        return is_array($g) && !empty($g['episodes']) ? $g : null;
    }

    /** Background pass: probe new/changed video files, refresh show guides. */
    public static function refresh(?callable $checkpoint = null): array
    {
        $pdo = \Vaultcast\Database::connection();
        self::ensureTable($pdo);
        $known = [];
        foreach ($pdo->query('SELECT item_id, sig FROM vaultcast_media_facts') as $r) $known[(string)$r['item_id']] = (string)$r['sig'];
        $ins = $pdo->prepare('REPLACE INTO vaultcast_media_facts (item_id, sig, video_codec, audio_codec, needs_convert, present, probed_at) VALUES (?,?,?,?,?,?,?)');
        $out = ['ok' => true, 'probed' => 0, 'unchanged' => 0, 'missing' => 0, 'removed' => 0, 'guides' => 0];
        $seen = []; $shows = [];
        $t0 = microtime(true);
        foreach (\Vaultcast\Repository::rows("SELECT payload FROM vaultcast_items WHERE media_kind='video'") as $it) {
            if (!is_array($it) || empty($it['id'])) continue;
            $id = (string)$it['id']; $seen[$id] = true;
            $type = (string)($it['type'] ?? '');
            if (($type === 'tv' || $type === 'anime') && !empty($it['show'])) {
                $shows[Catalogue::showKey((string)($it['section'] ?? ''), (string)$it['show'])] = (string)$it['show'];
            }
            $sig = self::sig($it);
            if (($known[$id] ?? null) === $sig && self::audio($it)) { $out['unchanged']++; continue; }
            if ($checkpoint) $checkpoint();
            $path = (string)($it['path'] ?? '');
            if ($path === '' || !is_file($path)) {
                $ins->execute([$id, $sig, '', '', 0, 0, time()]); $out['missing']++; continue;
            }
            $p = MediaProbe::probeSummary($path, 15, true);
            \Vaultcast\Repository::putDocument('media-audio:' . $id, ['sig' => $sig, 'probe' => [
                'audio_tracks' => $p['audio_tracks'] ?? [], 'audio_count' => $p['audio_count'] ?? 0,
                'multi_audio' => !empty($p['multi_audio']),
            ]]);
            $vc = (string)($p['video_codec'] ?? '');
            $ac = (string)($p['audio_tracks'][0]['codec'] ?? '');
            if ($vc === '') {
                // probe failed: remember the extension verdict so requests stay DB-only
                $ext = strtolower((string)($it['ext'] ?? ''));
                $nc = $ext !== '' && !in_array($ext, ['mp4', 'm4v', 'webm', 'mp3', 'aac', 'flac'], true);
            } else {
                $nc = self::codecsNeedConvert($vc, $ac);
            }
            $ins->execute([$id, $sig, substr($vc, 0, 32), substr($ac, 0, 32), $nc ? 1 : 0, 1, time()]);
            $out['probed']++;
        }
        $stale = array_diff_key($known, $seen);
        if ($stale) {
            $del = $pdo->prepare('DELETE FROM vaultcast_media_facts WHERE item_id=?');
            $delAudio = $pdo->prepare('DELETE FROM vaultcast_documents WHERE name=?');
            foreach (array_keys($stale) as $id) { $del->execute([$id]); $delAudio->execute(['media-audio:' . $id]); $out['removed']++; }
        }
        $out['probe_seconds'] = round(microtime(true) - $t0, 1);
        // Episode guides: online/disk cache lookups happen here, never on a page request.
        if (class_exists('Posters')) {
            foreach ($shows as $key => $name) {
                if ($checkpoint) $checkpoint();
                try {
                    $g = Posters::tvMazeEpisodeGuide($key, $name);
                    if (is_array($g) && !empty($g['episodes'])) {
                        \Vaultcast\Repository::putDocument('tvmaze-guide:' . $key, $g);
                        $out['guides']++;
                    }
                } catch (\Throwable $e) { error_log('Vaultcast guide ' . $key . ': ' . $e->getMessage()); }
            }
        }
        self::$map = [];
        Catalogue::invalidateDerivedCaches();
        Catalogue::warmBrowseViews();
        Catalogue::homeLibrary();
        Catalogue::showPosterHint('');
        $out['seconds'] = round(microtime(true) - $t0, 1);
        return $out;
    }
}
