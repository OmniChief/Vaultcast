<?php
declare(strict_types=1);

/**
 * Find & rename → "Query the library": find catalogue items by names/terms (wildcards * ?, fuzzy),
 * then preview and apply one fix to the selection:
 *   title    – movie title / episode title (DB label only)
 *   show     – rename the show for episodes (DB label only)
 *   match    – movie: title/year/IMDb id (Fix Match rematch); show: TVMaze id pin
 *   filename – rename the file on disk (same folder, extension kept) plus same-stem sidecars,
 *              using the PR #16 library-root / traversal / symlink protections.
 * Label fixes keep item ids, so watch progress stays. A disk rename gets a new item id; progress
 * and saved Fix Match overrides are moved to it. Re-applying the same fix is a no-op.
 */
final class LibraryQuery {
    const FIELDS = ['title' => 'Title', 'file' => 'File name', 'show' => 'Show', 'episode_title' => 'Episode'];
    const MAX_RESULTS = 500;

    /** @var callable|null test hook: fn(array $oldIds, string $newId): int */
    public static $migrateProgress = null;

    public static function norm(string $s): string {
        $s = strtolower(str_replace(['_', '.'], ' ', $s));
        $s = (string)preg_replace('/[^a-z0-9*?\s]+/', ' ', $s);
        return trim((string)preg_replace('/\s+/', ' ', $s));
    }

    /** Split the query box: one term per line (or ; separated); blank lines ignored. */
    public static function terms($q): array {
        $parts = is_array($q) ? $q : preg_split('/\r\n|\r|\n|;/', (string)$q);
        $out = [];
        foreach ($parts as $p) { $p = trim((string)$p); if ($p !== '' && !in_array($p, $out, true)) $out[] = $p; }
        return array_slice($out, 0, 100);
    }

    /** Score 0..100 for one term against one field value. */
    public static function score(string $term, string $value): int {
        $t = self::norm($term);
        $v = self::norm($value);
        if ($t === '' || $v === '') return 0;
        if (strpbrk($t, '*?') !== false) {
            foreach ([[$t, $v], [str_replace(' ', '', $t), str_replace(' ', '', $v)]] as $pair) {
                $re = str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pair[0], '/'));
                if (!preg_match('/' . $re . '/', $pair[1])) continue;
                return preg_match('/^' . $re . '$/', $pair[1]) ? 95 : 85;
            }
            return 0;
        }
        if ($t === $v) return 100;
        $ct = str_replace(' ', '', $t); $cv = str_replace(' ', '', $v);
        if ($ct === $cv) return 98;                       // "Dragonball" vs "Dragon Ball"
        if (strpos($v, $t) !== false || strpos($cv, $ct) !== false) return 90;
        // fuzzy: best window of the value with the term's word count, by similarity
        $tw = explode(' ', $t); $vw = explode(' ', $v);
        $n = count($tw); $best = 0.0;
        for ($i = 0; $i + $n <= count($vw) || ($i === 0 && count($vw) < $n); $i++) {
            $win = implode('', array_slice($vw, $i, $n));
            similar_text($ct, $win, $pct);
            $lev = levenshtein(substr($ct, 0, 255), substr($win, 0, 255));
            $allowed = max(1, (int)floor(strlen($ct) / 5));
            if ($lev <= $allowed) $pct = max($pct, 100 - 10 * $lev);
            $best = max($best, $pct);
            if (count($vw) < $n) break;
        }
        return $best >= 75 ? (int)min(84, round($best)) : 0;
    }

    private static function fieldsOf(array $it): array {
        $rel = str_replace('\\', '/', (string)($it['rel'] ?? ''));
        return ['title' => (string)($it['title'] ?? ''), 'file' => basename($rel), 'show' => (string)($it['show'] ?? ''), 'episode_title' => (string)($it['episode_title'] ?? '')];
    }

    public static function row(string $id, array $it): array {
        $rel = str_replace('\\', '/', (string)($it['rel'] ?? ''));
        return ['id' => $id, 'type' => (string)($it['type'] ?? ''), 'section' => (string)($it['section'] ?? ''), 'title' => $it['title'] ?? null,
            'show' => $it['show'] ?? null, 'season' => $it['season'] ?? null, 'episode' => $it['episode'] ?? null, 'episode_title' => $it['episode_title'] ?? null,
            'year' => $it['year'] ?? null, 'file' => basename($rel), 'rel' => $rel];
    }

    /**
     * body: q (string or list), fields? [title,file,show,episode_title], type? (movies|tv|anime|...), min_score? (default 75)
     */
    public static function search(array $b): array {
        $terms = self::terms($b['q'] ?? ($b['terms'] ?? ''));
        if (!$terms) return ['ok' => false, 'error' => 'Enter at least one name or term.'];
        $fields = isset($b['fields']) && is_array($b['fields']) ? array_values(array_intersect(array_keys(self::FIELDS), $b['fields'])) : array_keys(self::FIELDS);
        if (!$fields) $fields = array_keys(self::FIELDS);
        $type = (string)($b['type'] ?? '');
        $min = max(50, min(100, (int)($b['min_score'] ?? 75)));
        $cat = Catalogue::get();
        $rows = [];
        $perTerm = array_fill_keys($terms, 0);
        foreach ($cat['items'] ?? [] as $id => $it) {
            if (!is_array($it)) continue;
            if ($type !== '' && (string)($it['type'] ?? '') !== $type) continue;
            $f = self::fieldsOf($it);
            $best = 0; $bt = ''; $bf = '';
            foreach ($terms as $t) {
                foreach ($fields as $k) {
                    $s = self::score($t, $f[$k]);
                    if ($s > $best) { $best = $s; $bt = $t; $bf = $k; }
                }
            }
            if ($best < $min) continue;
            $perTerm[$bt]++;
            $rows[] = self::row((string)$id, $it) + ['score' => $best, 'term' => $bt, 'field' => $bf];
        }
        usort($rows, function ($a, $b) {
            $c = $b['score'] <=> $a['score'];
            return $c !== 0 ? $c : strnatcasecmp($a['rel'], $b['rel']);
        });
        $total = count($rows);
        return ['ok' => true, 'terms' => $terms, 'counts' => $perTerm, 'total' => $total, 'truncated' => $total > self::MAX_RESULTS, 'items' => array_slice($rows, 0, self::MAX_RESULTS)];
    }

    /* ------------------------------------------------------------------ fixes */

    /** Fill {title} {show} {season} {episode} {year} {name} (zero-padded season/episode). */
    public static function template(string $tpl, array $it): string {
        $rel = str_replace('\\', '/', (string)($it['rel'] ?? ''));
        $rep = [
            '{title}' => (string)($it['title'] ?? ''), '{show}' => (string)($it['show'] ?? ''),
            '{episode_title}' => (string)($it['episode_title'] ?? ''),
            '{season}' => isset($it['season']) && $it['season'] !== '' ? sprintf('%02d', (int)$it['season']) : '',
            '{episode}' => isset($it['episode']) && $it['episode'] !== '' ? sprintf('%02d', (int)$it['episode']) : '',
            '{year}' => isset($it['year']) && $it['year'] ? (string)$it['year'] : '',
            '{name}' => pathinfo($rel, PATHINFO_FILENAME),
        ];
        $s = strtr($tpl, $rep);
        $s = (string)preg_replace('/\(\s*\)|\[\s*\]/', '', $s);
        return trim((string)preg_replace('/\s+/', ' ', $s), " -.");
    }

    /** Safe new file stem: no separators, traversal, control or reserved characters. */
    public static function validStem(string $stem): ?string {
        if ($stem === '' || strlen($stem) > 200) return 'The new file name is empty or too long.';
        if (preg_match('#[\\\\/:*?"<>|\x00-\x1f]#', $stem)) return 'The new file name cannot contain \\ / : * ? " < > |.';
        if ($stem === '.' || $stem === '..' || strpos($stem, '..') === 0 || substr($stem, -1) === '.' || substr($stem, -1) === ' ') return 'That file name is not allowed.';
        if (preg_match('/^(con|prn|aux|nul|com\d|lpt\d)$/i', $stem)) return 'That file name is reserved on Windows.';
        return null;
    }

    private static function normFix($fix): array {
        $fix = is_array($fix) ? $fix : [];
        $kind = (string)($fix['kind'] ?? '');
        return ['kind' => $kind, 'value' => trim((string)($fix['value'] ?? '')), 'year' => isset($fix['year']) && $fix['year'] !== '' ? (int)$fix['year'] : null,
            'imdb_id' => trim((string)($fix['imdb_id'] ?? '')), 'tvmaze_id' => (int)($fix['tvmaze_id'] ?? 0)];
    }

    /** One planned change per selected item. */
    public static function plan(array $b): array {
        $ids = isset($b['ids']) && is_array($b['ids']) ? array_values(array_unique(array_map('strval', $b['ids']))) : [];
        if (!$ids) return ['ok' => false, 'error' => 'Select at least one item.'];
        $fix = self::normFix($b['fix'] ?? null);
        if (!in_array($fix['kind'], ['title', 'show', 'match', 'filename'], true)) return ['ok' => false, 'error' => 'Choose what to change.'];
        if ($fix['kind'] !== 'match' && $fix['value'] === '') return ['ok' => false, 'error' => 'Enter the new name.'];
        $cat = Catalogue::get();
        $rows = [];
        $targets = [];
        foreach ($ids as $id) {
            $it = $cat['items'][$id] ?? null;
            if (!is_array($it)) { $rows[] = ['id' => $id, 'ok' => false, 'error' => 'Item no longer in the library.', 'change' => false]; continue; }
            $type = (string)($it['type'] ?? '');
            $isEp = $type === 'tv' || $type === 'anime';
            $r = ['id' => $id, 'ok' => true, 'item' => self::row($id, $it), 'field' => '', 'before' => '', 'after' => '', 'change' => false, 'error' => null];
            switch ($fix['kind']) {
                case 'title':
                    $k = $isEp ? 'episode_title' : 'title';
                    $new = self::template($fix['value'], $it);
                    $r['field'] = $k; $r['before'] = (string)($it[$k] ?? ''); $r['after'] = $new;
                    if (!$isEp && $fix['year'] !== null) { $r['after_year'] = $fix['year']; }
                    $r['change'] = $new !== '' && ($new !== $r['before'] || (!$isEp && $fix['year'] !== null && (int)($it['year'] ?? 0) !== $fix['year']));
                    break;
                case 'show':
                    if (!$isEp) { $r['ok'] = false; $r['error'] = 'Only episodes have a show name.'; break; }
                    $r['field'] = 'show'; $r['before'] = (string)($it['show'] ?? ''); $r['after'] = self::template($fix['value'], $it);
                    $r['change'] = $r['after'] !== '' && $r['after'] !== $r['before'];
                    break;
                case 'match':
                    if ($isEp) {
                        if ($fix['tvmaze_id'] <= 0) { $r['ok'] = false; $r['error'] = 'Pick a TVMaze show to match episodes to.'; break; }
                        $key = Catalogue::showKey((string)($it['section'] ?? ''), (string)($it['show'] ?? ''));
                        $pin = Matching::showPin($key);
                        $r['field'] = 'tvmaze'; $r['show_key'] = $key; $r['before'] = $pin && !empty($pin['tvmaze_id']) ? '#' . (int)$pin['tvmaze_id'] : 'auto';
                        $r['after'] = '#' . $fix['tvmaze_id'] . ($fix['value'] !== '' ? ' ' . $fix['value'] : '');
                        $r['change'] = !($pin && (int)($pin['tvmaze_id'] ?? 0) === $fix['tvmaze_id']);
                        if (isset($targets[$key])) $r['change'] = false; // one pin per show
                        $targets[$key] = true;
                    } else {
                        if ($fix['value'] === '') { $r['ok'] = false; $r['error'] = 'Enter the movie title to match.'; break; }
                        $r['field'] = 'match';
                        $r['before'] = trim((string)($it['title'] ?? '') . (!empty($it['year']) ? ' (' . $it['year'] . ')' : ''));
                        $r['after'] = $fix['value'] . ($fix['year'] ? ' (' . $fix['year'] . ')' : '') . ($fix['imdb_id'] !== '' ? ' · ' . $fix['imdb_id'] : '');
                        $r['change'] = $fix['value'] !== (string)($it['title'] ?? '') || ($fix['year'] !== null && (int)($it['year'] ?? 0) !== $fix['year'])
                            || ($fix['imdb_id'] !== '' && (string)($it['imdb_id'] ?? '') !== $fix['imdb_id']);
                    }
                    break;
                case 'filename':
                    $path = (string)($it['path'] ?? '');
                    $v = LibraryDelete::resolve($path);
                    if (!$v['ok']) { $r['ok'] = false; $r['error'] = $v['error']; break; }
                    $ext = pathinfo($v['path'], PATHINFO_EXTENSION);
                    $stem = self::template($fix['value'], $it);
                    if (strcasecmp((string)pathinfo($stem, PATHINFO_EXTENSION), $ext) === 0) $stem = (string)pathinfo($stem, PATHINFO_FILENAME);
                    $err = self::validStem($stem);
                    if ($err) { $r['ok'] = false; $r['error'] = $err; break; }
                    $newPath = dirname($v['path']) . DIRECTORY_SEPARATOR . $stem . ($ext !== '' ? '.' . $ext : '');
                    $r['field'] = 'file'; $r['before'] = basename($v['path']); $r['after'] = basename($newPath);
                    $r['path'] = $v['path']; $r['new_path'] = $newPath;
                    $r['change'] = $r['before'] !== $r['after'];
                    $caseOnly = strcasecmp($r['before'], $r['after']) === 0;
                    if ($r['change'] && !$caseOnly && file_exists($newPath)) { $r['ok'] = false; $r['error'] = 'A file with that name already exists.'; $r['change'] = false; }
                    if ($r['change'] && isset($targets[strtolower($newPath)])) { $r['ok'] = false; $r['error'] = 'Two selected files would get the same name.'; $r['change'] = false; }
                    $targets[strtolower($newPath)] = true;
                    $r['sidecars'] = $r['change'] ? array_map('basename', LibraryDelete::sidecars($v['path'])) : [];
                    break;
            }
            $rows[] = $r;
        }
        $n = 0; foreach ($rows as $r) if (!empty($r['change']) && $r['ok']) $n++;
        return ['ok' => true, 'fix' => $fix, 'rows' => $rows, 'changes' => $n];
    }

    public static function preview(array $b): array { return self::plan($b); }

    public static function apply(array $b): array {
        $plan = self::plan($b);
        if (empty($plan['ok'])) return $plan;
        $fix = $plan['fix'];
        $done = 0; $failed = 0; $results = [];
        $labelRows = [];
        foreach ($plan['rows'] as $r) {
            if (!$r['ok']) { $failed++; $results[] = ['id' => $r['id'], 'ok' => false, 'error' => $r['error']]; continue; }
            if (empty($r['change'])) { $results[] = ['id' => $r['id'], 'ok' => true, 'changed' => false]; continue; }
            if ($fix['kind'] === 'title' || $fix['kind'] === 'show') {
                $row = ['id' => $r['id'], $r['field'] => $r['after']];
                if (isset($r['after_year'])) $row['year'] = $r['after_year'];
                $labelRows[] = $row;
                $results[] = ['id' => $r['id'], 'ok' => true, 'changed' => true];
            } elseif ($fix['kind'] === 'match') {
                if (!empty($r['show_key'])) $res = Matching::pinShow($r['show_key'], ['provider' => 'tvmaze', 'tvmaze_id' => $fix['tvmaze_id'], 'name' => $fix['value']]);
                else $res = Matching::rematchMovie($r['id'], ['title' => $fix['value'], 'year' => $fix['year'], 'imdb_id' => $fix['imdb_id']]);
                $ok = !empty($res['ok']);
                $ok ? $done++ : $failed++;
                $results[] = ['id' => $r['id'], 'ok' => $ok, 'changed' => $ok, 'error' => $ok ? null : ($res['error'] ?? 'Failed')];
            } else {
                $res = self::renameFile($r['id'], $r['path'], $r['new_path']);
                !empty($res['ok']) ? $done++ : $failed++;
                $results[] = $res + ['id' => $r['id']];
            }
        }
        if ($labelRows) {
            $res = Matching::saveItemFields($labelRows, 'manual', Matching::actor());
            $done += (int)($res['changed'] ?? 0);
        }
        if (class_exists('Matching')) Matching::log('LIBRARY QUERY ' . $fix['kind'] . ' changed=' . $done . ' failed=' . $failed);
        return ['ok' => true, 'changed' => $done, 'failed' => $failed, 'results' => $results];
    }

    /** Rename on disk (with sidecars), re-index, and move progress + overrides to the new item id. */
    private static function renameFile(string $id, string $path, string $newPath): array {
        $v = LibraryDelete::resolve($path); // re-check right before touching the disk
        if (!$v['ok']) return ['ok' => false, 'error' => $v['error']];
        if (LibraryDelete::rootFor(dirname($newPath)) === null && !self::sameDir($v['path'], $newPath)) return ['ok' => false, 'error' => 'Target is outside the library.'];
        $oldStem = pathinfo($v['path'], PATHINFO_FILENAME);
        $newStem = pathinfo($newPath, PATHINFO_FILENAME);
        $sidecars = LibraryDelete::sidecars($v['path']);
        $caseOnly = strcasecmp(basename($v['path']), basename($newPath)) === 0;
        if ($caseOnly) {
            $tmp = $v['path'] . '.vcrename';
            if (!@rename($v['path'], $tmp) || !@rename($tmp, $newPath)) return ['ok' => false, 'error' => 'Rename failed (permissions?).'];
        } elseif (!@rename($v['path'], $newPath)) {
            return ['ok' => false, 'error' => 'Rename failed (permissions?).'];
        }
        $renamed = [basename($newPath)];
        foreach ($sidecars as $sc) {
            $b = basename($sc);
            $nb = $newStem . substr($b, strlen($oldStem));
            $dst = dirname($sc) . DIRECTORY_SEPARATOR . $nb;
            if (!file_exists($dst) && @rename($sc, $dst)) $renamed[] = $nb;
        }
        // Re-index: drop the old entry, ingest the new path
        $cat = Catalogue::get();
        $old = $cat['items'][$id] ?? null;
        $section = (string)($old['section'] ?? '');
        unset($cat['items'][$id]);
        Catalogue::save($cat);
        $item = Catalogue::ingestFile($newPath, $section);
        $newId = is_array($item) ? (string)($item['id'] ?? '') : '';
        $moved = 0;
        if ($newId !== '' && $newId !== $id) {
            $moved = self::$migrateProgress ? (int)(self::$migrateProgress)([$id], $newId) : \Vaultcast\Repository::migrateProgress([$id], $newId);
            $o = Matching::overrides();
            if (isset($o['items'][$id])) {
                $ov = $o['items'][$id];
                $ov['rel'] = (string)($item['rel'] ?? ($ov['rel'] ?? ''));
                $o['items'][$newId] = $ov;
                unset($o['items'][$id]);
                Matching::saveOverrides($o);
                $cat = Catalogue::get();
                if (isset($cat['items'][$newId])) { $cat['items'][$newId] = Matching::applyOverride($cat['items'][$newId]); Catalogue::save($cat); }
            }
        }
        Store::invalidate('catalogue');
        return ['ok' => true, 'changed' => true, 'new_id' => $newId, 'files' => $renamed, 'progress_moved' => $moved];
    }

    private static function sameDir(string $a, string $b): bool {
        return str_replace('\\', '/', dirname($a)) === str_replace('\\', '/', dirname($b));
    }
}
