<?php
declare(strict_types=1);

/**
 * Catalogue vs on-disk library diagnostics (missing files / orphan paths).
 * Diagnose configured library paths and apply unambiguous fixes.
 */
class PathDiagnose {
    public static function run(array $opts = []): array {
        $cat = Catalogue::get();
        $missing = [];
        $okCount = 0;
        $checked = 0;
        $limit = max(1, min(5000, (int)($opts['limit'] ?? 2000)));
        foreach ($cat['items'] ?? [] as $it) {
            if (!is_array($it)) continue;
            $path = (string)($it['path'] ?? '');
            if ($path === '') continue;
            $checked++;
            if (is_file($path)) { $okCount++; continue; }
            $missing[] = [
                'id' => $it['id'] ?? null,
                'title' => $it['title'] ?? '',
                'section' => $it['section'] ?? '',
                'type' => $it['type'] ?? '',
                'path' => $path,
                'rel' => $it['rel'] ?? '',
                'show' => $it['show'] ?? null,
                'issue' => 'missing_file',
                'suggestions' => self::suggestForMissing($it),
            ];
            if (count($missing) >= $limit) break;
        }

        $orphans = [];
        $scanOrphans = !empty($opts['include_orphans']);
        if ($scanOrphans) {
            $known = Library::knownPaths();
            $exts = Library::videoExtensions();
            $maxDepth = (int)($opts['orphan_max_depth'] ?? (Store::settings()['library']['orphan_max_depth'] ?? 4));
            $orphanLimit = max(1, min(500, (int)($opts['orphan_limit'] ?? 100)));
            foreach (Store::settings()['sections'] ?? [] as $sec) {
                if (empty($sec['enabled']) || empty($sec['path'])) continue;
                $root = \Vaultcast\Paths::real($sec['path']);
                if (!$root || !is_dir($root)) continue;
                self::walkOrphans($root, $root, $exts, $known, $orphans, $orphanLimit, 0, $maxDepth, $sec['id'] ?? '');
                if (count($orphans) >= $orphanLimit) break;
            }
        }

        return [
            'ok' => true,
            'checked' => $checked,
            'present' => $okCount,
            'missing_count' => count($missing),
            'missing' => $missing,
            'orphan_count' => count($orphans),
            'orphans' => $orphans,
            'note' => 'Safe fixes: remove_dead (catalogue only), re_ingest suggestion. Relink when basename unique under section.',
        ];
    }

    private static function suggestForMissing(array $it): array {
        $sugs = [];
        $path = (string)($it['path'] ?? '');
        $base = basename($path);
        $section = (string)($it['section'] ?? '');
        $secPath = null;
        foreach (Store::settings()['sections'] ?? [] as $sec) {
            if (($sec['id'] ?? '') === $section) { $secPath = $sec['path'] ?? null; break; }
        }
        $candidates = [];
        if ($secPath && is_dir($secPath) && $base !== '') {
            $cmd = 'find ' . escapeshellarg($secPath) . ' -maxdepth 6 -type f -iname ' . escapeshellarg($base) . ' 2>/dev/null | head -n 8';
            $out = shell_exec($cmd);
            if ($out) {
                foreach (preg_split('/\r?\n/', trim($out)) as $line) {
                    if ($line !== '' && is_file($line)) $candidates[] = $line;
                }
            }
        }
        if (count($candidates) === 1) {
            $sugs[] = [
                'action' => 'relink',
                'label' => 'Relink to unique match',
                'new_path' => $candidates[0],
                'safe' => true,
            ];
        } elseif (count($candidates) > 1) {
            $sugs[] = [
                'action' => 'relink_choices',
                'label' => 'Multiple path candidates — pick one',
                'candidates' => $candidates,
                'safe' => false,
            ];
        }
        $sugs[] = [
            'action' => 'remove_dead',
            'label' => 'Remove dead catalogue entry',
            'safe' => true,
        ];
        $sugs[] = [
            'action' => 're_ingest',
            'label' => 'Re-ingest after restoring/moving the file',
            'safe' => false,
        ];
        return $sugs;
    }

    private static function walkOrphans(string $root, string $dir, array $exts, array $known, array &$out, int $limit, int $depth, int $maxDepth, string $sectionId): void {
        if (count($out) >= $limit || $depth > $maxDepth) return;
        $entries = @scandir($dir);
        if (!$entries) return;
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') continue;
            $full = $dir . '/' . $e;
            if (is_dir($full)) {
                self::walkOrphans($root, $full, $exts, $known, $out, $limit, $depth + 1, $maxDepth, $sectionId);
                continue;
            }
            if (!is_file($full)) continue;
            $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
            if (!in_array($ext, $exts, true)) continue;
            $real = \Vaultcast\Paths::real($full) ?: $full;
            if (isset($known[$real]) || isset($known[$full])) continue;
            $out[] = [
                'path' => $full,
                'section' => $sectionId,
                'issue' => 'orphan_file',
                'suggestions' => [[
                    'action' => 'ingest',
                    'label' => 'Ingest into catalogue',
                    'safe' => true,
                ]],
            ];
            if (count($out) >= $limit) return;
        }
    }

    public static function applyFix(array $body): array {
        $action = (string)($body['action'] ?? '');
        if ($action === 'remove_dead') {
            $id = (string)($body['id'] ?? '');
            if ($id === '') return ['ok' => false, 'error' => 'id required'];
            return self::removeDead($id);
        }
        if ($action === 'relink') {
            $id = (string)($body['id'] ?? '');
            $newPath = (string)($body['new_path'] ?? '');
            if ($id === '' || $newPath === '' || !is_file($newPath)) {
                return ['ok' => false, 'error' => 'id and existing new_path required'];
            }
            return self::relink($id, $newPath);
        }
        if ($action === 'ingest') {
            return MediaManager::ingest(['path' => $body['path'] ?? '', 'section' => $body['section'] ?? '']);
        }
        return ['ok' => false, 'error' => 'Unknown action'];
    }

    private static function removeDead(string $id): array {
        $cat = Catalogue::get();
        $before = count($cat['items'] ?? []);
        $cat['items'] = array_values(array_filter($cat['items'] ?? [], function ($it) use ($id) {
            return !is_array($it) || ($it['id'] ?? '') !== $id;
        }));
        $removed = $before - count($cat['items']);
        if ($removed < 1) return ['ok' => false, 'error' => 'Item not found'];
        Catalogue::save($cat);
        Store::invalidate('catalogue');
        return ['ok' => true, 'removed' => $removed];
    }

    private static function relink(string $id, string $newPath): array {
        $cat = Catalogue::get();
        $found = false;
        foreach ($cat['items'] as &$it) {
            if (!is_array($it) || ($it['id'] ?? '') !== $id) continue;
            $real = \Vaultcast\Paths::real($newPath) ?: $newPath;
            $it['path'] = $real;
            // update rel under section root if possible
            foreach (Store::settings()['sections'] ?? [] as $sec) {
                if (($sec['id'] ?? '') !== ($it['section'] ?? '')) continue;
                $root = \Vaultcast\Paths::real($sec['path'] ?? '') ?: ($sec['path'] ?? '');
                if ($root && strpos($real, rtrim($root, '/') . '/') === 0) {
                    $it['rel'] = ltrim(substr($real, strlen($root)), '/');
                }
            }
            $it['size'] = @filesize($real) ?: ($it['size'] ?? 0);
            $it['ext'] = strtolower(pathinfo($real, PATHINFO_EXTENSION));
            $found = true;
            break;
        }
        unset($it);
        if (!$found) return ['ok' => false, 'error' => 'Item not found'];
        Catalogue::save($cat);
        Store::invalidate('catalogue');
        return ['ok' => true, 'path' => $newPath];
    }
}
