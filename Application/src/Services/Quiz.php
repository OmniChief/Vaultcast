<?php
declare(strict_types=1);

/**
 * Play → Quiz nights. The host screen (TV) owns a room; players join from phones with the room
 * code. State lives in one transactional document per room and clients poll it (no relay needed).
 */
final class Quiz {
    const ALPHA = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    const TEAMS = [['Red Rockets', '#ff6b6b'], ['Blue Comets', '#59a8ff'], ['Green Geckos', '#5ddf8f'], ['Gold Griffins', '#f5c84c']];
    const LIBRARY = ['lib-frame' => 'Name the film from a frame', 'lib-year' => 'Guess the year', 'lib-plot' => 'Guess the film from its synopsis'];
    const OTDB = [9 => 'General knowledge', 10 => 'Books', 11 => 'Film', 12 => 'Music', 14 => 'Television', 15 => 'Video games', 17 => 'Science & nature', 18 => 'Computers', 21 => 'Sports', 22 => 'Geography', 23 => 'History', 27 => 'Animals'];
    const REVEAL_SECONDS = 7;

    private static function key(string $code): string { return 'quiz-room:' . $code; }
    private static function code(string $code): string {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z]{4}$/D', $code)) throw new DomainException('Room codes are four letters.');
        return $code;
    }
    private static function room(string $code): array {
        $r = \Vaultcast\Repository::document(self::key(self::code($code)), null);
        if (!is_array($r) || empty($r['code'])) throw new DomainException('That quiz room is not open.');
        return $r;
    }

    public static function options(array $user): array {
        $cats = [];
        foreach (QuizBank::CATEGORIES as $id => $name) $cats[] = ['id' => $id, 'name' => $name, 'group' => 'Built-in'];
        if (AreaAccess::allowed($user, 'library')) foreach (self::LIBRARY as $id => $name) $cats[] = ['id' => $id, 'name' => $name, 'group' => 'Your library'];
        if (!empty(Store::settings()['play']['opentdb'] ?? true)) foreach (self::OTDB as $id => $name) $cats[] = ['id' => 'otdb-' . $id, 'name' => $name, 'group' => 'Open Trivia DB (online)'];
        return ['ok' => true, 'categories' => $cats, 'teams' => self::TEAMS];
    }

    // ---------------------------------------------------------------- question sources
    private static function films(int $limit): array {
        $rows = Arcade::query("SELECT item_id, title, year FROM vaultcast_browse_items WHERE media_kind='video' AND show_key='' AND type<>'roms' ORDER BY RAND() LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_ASSOC);
        $seen = []; $out = [];
        foreach ($rows as $r) { $k = mb_strtolower(trim($r['title'])); if ($k === '' || isset($seen[$k])) continue; $seen[$k] = 1; $out[] = $r; }
        return $out;
    }
    private static function others(array $films, array $not, int $n): array {
        $out = [];
        foreach ($films as $f) { if ($f['item_id'] === $not['item_id'] || in_array($f['title'], $out, true)) continue; $out[] = $f['title']; if (count($out) >= $n) break; }
        return $out;
    }
    private static function library(string $kind, int $count): array {
        $films = self::films(400); if (count($films) < 4) return [];
        $out = [];
        foreach ($films as $i => $f) {
            if (count($out) >= $count) break;
            $pool = array_merge(array_slice($films, $i + 1), array_slice($films, 0, $i)); shuffle($pool);
            if ($kind === 'lib-year') {
                $y = (int)$f['year']; if ($y < 1900) continue;
                $wrong = []; while (count($wrong) < 3) { $d = mt_rand(1, 9) * (mt_rand(0, 1) ? 1 : -1); $c = $y + $d; if ($c <= (int)date('Y') + 1 && !in_array($c, $wrong, true)) $wrong[] = $c; }
                $q = QuizBank::make('library', 'lib-year', 'In which year was “' . $f['title'] . '” released?', (string)$y, array_map('strval', $wrong), mt_rand());
            } elseif ($kind === 'lib-plot') {
                $s = Posters::movieSynopsisCached((string)$f['item_id']); $text = trim((string)($s['summary'] ?? ''));
                if (mb_strlen($text) < 60) continue;
                foreach (preg_split('/[\s:,\-–]+/u', $f['title']) as $w) if (mb_strlen($w) > 3) $text = preg_replace('/\b' . preg_quote($w, '/') . '\b/iu', '▒▒▒', $text);
                if (mb_strlen($text) > 360) $text = rtrim(mb_substr($text, 0, 357)) . '…';
                $q = QuizBank::make('library', 'lib-plot', 'Which film from your library is this?', $f['title'], self::others($pool, $f, 3), mt_rand()); $q['clue'] = $text;
            } else {
                $q = QuizBank::make('library', 'lib-frame', 'Which film is this frame from?', $f['title'], self::others($pool, $f, 3), mt_rand()); $q['item'] = (string)$f['item_id'];
            }
            if (count(array_unique($q['choices'])) === 4) $out[] = $q;
        }
        return $out;
    }
    private static function otdb(int $cat, int $count): array {
        $cache = \Vaultcast\Repository::cacheGet('quiz-otdb:' . $cat);
        $list = is_array($cache['q'] ?? null) ? $cache['q'] : [];
        if (count($list) < $count) {
            try {
                $j = WebFetch::json('https://opentdb.com/api.php?amount=40&type=multiple&encode=url3986&category=' . $cat, 5);
                foreach ((array)($j['results'] ?? []) as $r) $list[] = [rawurldecode($r['question']), rawurldecode($r['correct_answer']), array_map('rawurldecode', (array)$r['incorrect_answers'])];
                if ($list) \Vaultcast\Repository::cachePut('quiz-otdb:' . $cat, ['q' => array_slice($list, -200)], 86400 * 3);
            } catch (Throwable $e) {}
        }
        shuffle($list); $out = [];
        foreach (array_slice($list, 0, $count) as $r) if (count($r[2]) >= 3) $out[] = QuizBank::make('otdb', 'otdb-' . $cat, $r[0], $r[1], $r[2], mt_rand());
        return $out;
    }
    public static function build(array $cats, int $count, array $user): array {
        $out = [];
        if (substr($cats[0] ?? '', 0, 4) === 'lib-' && AreaAccess::allowed($user, 'library')) $out = self::library($cats[0], $count);
        elseif (preg_match('/^otdb-(\d+)$/', $cats[0] ?? '', $m) && isset(self::OTDB[(int)$m[1]])) $out = self::otdb((int)$m[1], $count);
        elseif (isset(QuizBank::CATEGORIES[$cats[0] ?? ''])) $out = QuizBank::questions([$cats[0]], $count, random_int(1, PHP_INT_MAX >> 1));
        // Top up from the built-in bank when a source runs short (small library, offline server).
        if (count($out) < $count) $out = array_merge($out, QuizBank::questions([], $count - count($out), random_int(1, PHP_INT_MAX >> 1)));
        return array_slice($out, 0, $count);
    }
    private static function categoryName(string $id): string {
        if (isset(QuizBank::CATEGORIES[$id])) return QuizBank::CATEGORIES[$id];
        if (isset(self::LIBRARY[$id])) return self::LIBRARY[$id];
        if (preg_match('/^otdb-(\d+)$/', $id, $m) && isset(self::OTDB[(int)$m[1]])) return self::OTDB[(int)$m[1]];
        return 'Mixed bag';
    }

    // ---------------------------------------------------------------- image questions
    /**
     * A still for $itemId, cached in STREAM_SHM. ffmpeg runs at most twice at once across all requests, once per item
     * (other requests for the same item wait for it), and a failed extraction is not retried for a day.
     * With $wait false the call never blocks on ffmpeg: it returns null when the frame is not ready and no slot is free.
     */
    public static function frame(string $itemId, bool $wait = true): ?string {
        $dir = STREAM_SHM . '/quiz-frames'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $itemId); $out = $dir . '/' . $safe . '.jpg';
        if (is_file($out) && filesize($out) > 1000) return $out;
        $lock = @fopen($out . '.lock', 'c');
        if ($lock) {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (!$wait) { fclose($lock); return null; }
                // Someone is extracting this frame: wait for them (bounded by their ffmpeg timeout).
                $until = microtime(true) + 25; $got = false;
                while (microtime(true) < $until) { usleep(150000); if (flock($lock, LOCK_EX | LOCK_NB)) { $got = true; break; } }
                if (!$got) { fclose($lock); return null; }
            }
            clearstatcache(true, $out);
            if (is_file($out) && filesize($out) > 1000) { flock($lock, LOCK_UN); fclose($lock); return $out; }
        }
        try { return self::extract($itemId, $out, $wait); }
        finally { if ($lock) { flock($lock, LOCK_UN); fclose($lock); } }
    }
    private static function extract(string $itemId, string $out, bool $wait): ?string {
        $item = \Vaultcast\Repository::item($itemId);
        if (!$item) return null;
        $path = (string)($item['path'] ?? '');
        $failed = is_file($out . '.fail') && filemtime($out . '.fail') > time() - 86400;
        if (!$failed && $path !== '' && is_file($path) && function_exists('proc_open')) {
            $slot = self::ffmpegSlot();
            if ($slot === null && !$wait) return null;
            if ($slot !== null) {
                try {
                    if (function_exists('set_time_limit')) @set_time_limit(60);
                    $duration = (float)Arcade::query('SELECT duration FROM vaultcast_media_facts WHERE item_id=?', [$itemId])->fetchColumn();
                    $at = $duration > 120 ? $duration * (0.25 + (crc32($itemId) % 40) / 100) : 300;
                    $tmp = $out . '.' . getmypid() . '.jpg';
                    Process::capture([Remux::ffmpegBin(), '-nostdin', '-hide_banner', '-loglevel', 'error', '-ss', (string)round($at, 1), '-i', $path, '-frames:v', '1', '-vf', 'scale=960:-2', '-q:v', '5', '-y', $tmp], 20);
                    if (is_file($tmp) && filesize($tmp) > 1000 && @rename($tmp, $out)) { @unlink($out . '.fail'); return $out; }
                    @unlink($tmp); @touch($out . '.fail');
                } finally { flock($slot, LOCK_UN); fclose($slot); }
            }
        }
        // Fallback: a zoomed crop of the poster's centre (titles usually sit at the top or bottom).
        $poster = Posters::resolveItem($item, false);
        if (!$poster || !is_file($poster)) return null;
        if (function_exists('imagecreatefromstring') && filesize($poster) < 12000000) {
            $img = @imagecreatefromstring((string)file_get_contents($poster));
            if ($img) {
                $w = imagesx($img); $h = imagesy($img); $cw = (int)($w * 0.7); $ch = (int)min($h * 0.4, $cw * 0.5625);
                $dst = imagecreatetruecolor(960, 540);
                imagecopyresampled($dst, $img, 0, 0, (int)(($w - $cw) / 2), (int)(($h - $ch) / 2), 960, 540, $cw, $ch);
                $tmp = $out . '.' . getmypid() . '.jpg';
                imagejpeg($dst, $tmp, 82); imagedestroy($dst); imagedestroy($img);
                if (is_file($tmp) && @rename($tmp, $out)) return $out;
                @unlink($tmp);
            }
        }
        return $poster;
    }
    /** One of two global ffmpeg slots for frame extraction, or null when both are busy. */
    private static function ffmpegSlot() {
        for ($i = 0; $i < 2; $i++) {
            $h = @fopen(STREAM_SHM . '/quiz-frames/slot-' . $i . '.lock', 'c');
            if ($h && flock($h, LOCK_EX | LOCK_NB)) return $h;
            if ($h) fclose($h);
        }
        return null;
    }
    /** After the response is sent (PHP-FPM), extract the frames a quiz will need so questions never wait on ffmpeg. */
    private static function prewarm(array $items): void {
        $items = array_values(array_unique(array_filter($items))); if (!$items || !function_exists('fastcgi_finish_request')) return;
        register_shutdown_function(function () use ($items) {
            @fastcgi_finish_request(); ignore_user_abort(true); if (function_exists('set_time_limit')) @set_time_limit(150);
            $until = time() + 120;
            foreach (array_slice($items, 0, 40) as $id) { if (time() > $until) break; try { self::frame((string)$id, true); } catch (Throwable $e) {} }
        });
    }
    private static function sendImage(?string $file): void {
        if (!$file) { http_response_code(404); exit; }
        $type = @mime_content_type($file) ?: 'image/jpeg';
        header('Content-Type: ' . $type); header('Cache-Control: private, max-age=3600'); header('Content-Length: ' . filesize($file));
        readfile($file); exit;
    }
    public static function roomImage(array $user, string $code, int $n): void {
        $r = self::room($code);
        $q = $r['questions'][$n] ?? null;
        if (!$q || empty($q['item']) || $n > (int)$r['index'] + 1) { http_response_code(404); exit; }
        if (!self::isHost($r, $user) && !self::playerFor($r, $user)) { http_response_code(403); exit; }
        self::sendImage(self::frame((string)$q['item']));
    }

    // ---------------------------------------------------------------- rooms
    private static function isHost(array $r, array $user, string $token = ''): bool {
        if ($token !== '' && hash_equals((string)$r['host_hash'], hash('sha256', $token))) return true;
        return ($user['id'] ?? 'public') !== 'public' && $user['id'] === $r['host'];
    }
    private static function playerFor(array $r, array $user, string $pid = '', string $token = ''): ?string {
        if ($pid !== '' && isset($r['players'][$pid]) && $token !== '' && hash_equals((string)$r['players'][$pid]['hash'], hash('sha256', $token))) return $pid;
        if (($user['id'] ?? 'public') !== 'public') foreach ((array)$r['players'] as $id => $p) if (($p['uid'] ?? '') === $user['id']) return (string)$id;
        return null;
    }
    private static function settings(array $in): array {
        $cats = array_values(array_filter(array_map('strval', (array)($in['categories'] ?? [])), function ($c) { return $c !== '' && strlen($c) < 20; }));
        return ['rounds' => max(1, min(8, (int)($in['rounds'] ?? 3))), 'per_round' => max(2, min(15, (int)($in['per_round'] ?? 5))), 'timer' => max(8, min(90, (int)($in['timer'] ?? 20))),
            'categories' => array_slice($cats ?: ['general', 'lib-frame', 'science'], 0, 8), 'mode' => ($in['mode'] ?? '') === 'teams' ? 'teams' : 'solo',
            'teams' => max(2, min(4, (int)($in['teams'] ?? 2))), 'auto' => !isset($in['auto']) || !empty($in['auto']), 'title' => mb_substr(trim((string)($in['title'] ?? '')), 0, 60) ?: 'Quiz night'];
    }
    public static function create(array $user, array $body): array {
        Arcade::signed($user);
        $index = (array)\Vaultcast\Repository::document('quiz-rooms', []);
        foreach ($index as $code => $at) if ($at < time() - 43200) { Arcade::query('DELETE FROM vaultcast_documents WHERE name=?', [self::key((string)$code)]); @unlink(self::pulseFile((string)$code)); unset($index[$code]); }
        $mine = 0; foreach ($index as $code => $at) if ((\Vaultcast\Repository::document(self::key((string)$code), [])['host'] ?? '') === $user['id']) $mine++;
        if ($mine >= 3) throw new DomainException('Close one of your open quiz rooms first.');
        do { $code = ''; for ($i = 0; $i < 4; $i++) $code .= self::ALPHA[random_int(0, strlen(self::ALPHA) - 1)]; } while (isset($index[$code]));
        $token = bin2hex(random_bytes(16));
        $room = ['code' => $code, 'host' => $user['id'], 'host_name' => Arcade::person($user)['name'], 'host_hash' => hash('sha256', $token), 'settings' => self::settings((array)($body['settings'] ?? [])),
            'status' => 'lobby', 'index' => -1, 'questions' => [], 'rounds' => [], 'players' => [], 'started_at' => 0, 'ends_at' => 0, 'version' => 1, 'created_at' => time(), 'awarded' => false];
        \Vaultcast\Repository::putDocument(self::key($code), $room);
        self::pulse($room);
        HomeHub::mutate('quiz-rooms', function (array $i) use ($code): array { $i[$code] = time(); return $i; });
        return ['ok' => true, 'code' => $code, 'host_token' => $token];
    }
    private static function mutate(string $code, callable $fn): array {
        $code = self::code($code);
        $r = HomeHub::mutate(self::key($code), function (array $r) use ($fn): array {
            if (empty($r['code'])) throw new DomainException('That quiz room is not open.');
            $r = $fn($r); $r['version'] = (int)$r['version'] + 1; $r['updated_at'] = time(); return $r;
        });
        self::pulse($r);
        return $r;
    }
    /**
     * A tiny per-room file with the version and the timestamps of the next timed transition. Polls compare against it
     * and answer "same" without reading the room document while nothing changed and nothing is due.
     */
    private static function pulseFile(string $code): string { return STREAM_SHM . '/quiz-pulse/' . $code . '.json'; }
    private static function pulse(array $r): void {
        $dir = STREAM_SHM . '/quiz-pulse'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $f = self::pulseFile((string)$r['code']); $tmp = $f . '.' . getmypid();
        $data = ['v' => (int)$r['version'], 'status' => (string)$r['status'], 'ends_at' => (int)($r['ends_at'] ?? 0), 'reveal_at' => (int)($r['reveal_at'] ?? 0), 'intro_at' => (int)($r['intro_at'] ?? 0), 'scores_at' => (int)($r['scores_at'] ?? 0), 'auto' => !empty($r['settings']['auto'])];
        if (@file_put_contents($tmp, json_encode($data)) !== false) @rename($tmp, $f);
    }
    /** True when a poll at version $v can be answered "same" from the pulse file alone. */
    private static function quiet(string $code, int $v): bool {
        if ($v <= 0) return false;
        $raw = @file_get_contents(self::pulseFile($code)); $p = $raw ? json_decode($raw, true) : null;
        if (!is_array($p) || (int)$p['v'] !== $v) return false;
        $now = time();
        if ($p['status'] === 'question' && $now >= (int)$p['ends_at']) return false;
        if ($p['auto'] && (($p['status'] === 'reveal' && $now - (int)$p['reveal_at'] >= self::REVEAL_SECONDS) || ($p['status'] === 'intro' && $now - (int)$p['intro_at'] >= 5) || ($p['status'] === 'scores' && $now - (int)$p['scores_at'] >= 10))) return false;
        return true;
    }
    private static function due(array $r): bool {
        if ($r['status'] !== 'question') return false;
        if (time() >= (int)$r['ends_at']) return true;
        $active = array_filter((array)$r['players'], function ($p) { return empty($p['left']); });
        if (!$active) return false;
        foreach ($active as $p) if (!isset($p['answers'][$r['index']])) return false;
        return true;
    }
    private static function reveal(array $r): array {
        $r['status'] = 'reveal'; $r['reveal_at'] = time(); $q = $r['questions'][$r['index']];
        foreach ($r['players'] as $pid => $p) {
            if (!isset($p['answers'][$r['index']])) { $r['players'][$pid]['streak'] = 0; $r['players'][$pid]['answers'][$r['index']] = ['c' => -1, 'ok' => false, 'pts' => 0]; continue; }
            $a = $p['answers'][$r['index']]; $ok = (int)$a['c'] === (int)$q['answer']; $pts = 0;
            if ($ok) {
                $streak = (int)($p['streak'] ?? 0) + 1; $r['players'][$pid]['streak'] = $streak;
                $pts = (int)(round((1000 - 500 * min(1, (float)$a['t'] / max(1, $r['settings']['timer']))) / 10) * 10) + 50 * min(4, $streak - 1);
                $r['players'][$pid]['correct'] = (int)($p['correct'] ?? 0) + 1;
            } else $r['players'][$pid]['streak'] = 0;
            $r['players'][$pid]['answers'][$r['index']] = $a + ['ok' => $ok, 'pts' => $pts];
            $r['players'][$pid]['last'] = (int)($p['score'] ?? 0);
            $r['players'][$pid]['score'] = (int)($p['score'] ?? 0) + $pts;
        }
        return $r;
    }
    private static function standings(array $r): array {
        $players = [];
        foreach ((array)$r['players'] as $pid => $p) $players[] = ['id' => (string)$pid, 'name' => $p['name'], 'avatar_url' => $p['avatar_url'] ?? null, 'team' => $p['team'] ?? null, 'score' => (int)($p['score'] ?? 0), 'previous' => (int)($p['last'] ?? 0), 'correct' => (int)($p['correct'] ?? 0), 'streak' => (int)($p['streak'] ?? 0), 'left' => !empty($p['left']), 'signed' => ($p['uid'] ?? '') !== ''];
        usort($players, function ($a, $b) { return [$b['score'], $b['correct']] <=> [$a['score'], $a['correct']]; });
        $teams = [];
        if ($r['settings']['mode'] === 'teams') {
            for ($t = 0; $t < $r['settings']['teams']; $t++) {
                $m = array_filter($players, function ($p) use ($t) { return $p['team'] === $t && !$p['left']; });
                $teams[] = ['id' => $t, 'name' => self::TEAMS[$t][0], 'colour' => self::TEAMS[$t][1], 'members' => count($m), 'score' => $m ? (int)round(array_sum(array_column($m, 'score')) / count($m)) : 0];
            }
            usort($teams, function ($a, $b) { return $b['score'] <=> $a['score']; });
        }
        return [$players, $teams];
    }
    private static function finish(array $r): array {
        $r['status'] = 'final';
        if (!empty($r['awarded'])) return $r;
        $r['awarded'] = true;
        list($players, $teams) = self::standings($r);
        $real = array_values(array_filter($players, function ($p) { return !$p['left']; }));
        $winTeam = $teams ? $teams[0]['id'] : null; $bonus = [25, 15, 10];
        foreach ($real as $place => $p) {
            $uid = (string)($r['players'][$p['id']]['uid'] ?? ''); if ($uid === '') continue;
            $win = count($real) > 1 && ($teams ? $p['team'] === $winTeam : $place === 0);
            $pts = min(80, 5 + 2 * $p['correct'] + (count($real) > 1 && !$teams ? ($bonus[$place] ?? 0) : ($win ? 20 : 0)));
            Arcade::query('INSERT INTO vaultcast_play_scores (user_id,game,score,duration_ms,meta,created_at) VALUES (?,?,?,?,?,?)', [$uid, 'quiz', $p['score'], 0, $win ? 'win' : 'played', time()]);
            Arcade::award($uid, 'quiz', $pts, $win ? 'won the quiz “' . $r['settings']['title'] . '”' : 'finished #' . ($place + 1) . ' in the quiz “' . $r['settings']['title'] . '”');
        }
        return $r;
    }
    private static function advance(array $r): array {
        $next = (int)$r['index'] + 1;
        if ($next >= count($r['questions'])) return self::finish($r);
        $round = (int)$r['questions'][$next]['round'];
        if ($r['status'] === 'reveal' && $r['index'] >= 0 && (int)$r['questions'][$r['index']]['round'] !== $round) { $r['status'] = 'scores'; $r['scores_at'] = time(); return $r; }
        $r['index'] = $next; $r['status'] = 'question'; $r['started_at'] = microtime(true); $r['ends_at'] = time() + (int)$r['settings']['timer'];
        return $r;
    }
    public static function host(array $user, string $code, array $body): array {
        $action = (string)($body['action'] ?? ''); $token = (string)($body['host_token'] ?? '');
        $r = self::room($code);
        if (!self::isHost($r, $user, $token)) throw new DomainException('Only the host screen can control this quiz.');
        $questions = null;
        if ($action === 'start' || $action === 'restart') {
            $s = $action === 'start' && isset($body['settings']) ? self::settings((array)$body['settings']) : $r['settings'];
            $questions = []; $rounds = [];
            for ($i = 0; $i < $s['rounds']; $i++) {
                $cat = $s['categories'][$i % count($s['categories'])];
                $rounds[] = ['category' => $cat, 'name' => self::categoryName($cat)];
                foreach (self::build([$cat], $s['per_round'], $user) as $q) { $q['round'] = $i; $questions[] = $q; }
            }
            $body['settings'] = $s; $body['rounds'] = $rounds;
        }
        $r = self::mutate($code, function (array $r) use ($action, $body, $questions): array {
            switch ($action) {
                case 'settings': if ($r['status'] !== 'lobby') throw new DomainException('Change settings before the quiz starts.'); $r['settings'] = self::settings((array)($body['settings'] ?? [])); break;
                case 'start': case 'restart':
                    if (count($r['players']) < 1) throw new DomainException('Wait for at least one player to join.');
                    $r['settings'] = $body['settings']; $r['rounds'] = $body['rounds']; $r['questions'] = $questions; $r['index'] = -1; $r['awarded'] = false;
                    foreach ($r['players'] as $pid => $p) $r['players'][$pid] = array_merge($p, ['score' => 0, 'last' => 0, 'correct' => 0, 'streak' => 0, 'answers' => []]);
                    $r['status'] = 'intro'; $r['intro_at'] = time(); break;
                case 'next':
                    if ($r['status'] === 'question') $r = self::reveal($r);
                    elseif ($r['status'] === 'scores') $r = self::advance(array_merge($r, ['status' => 'next']));
                    elseif (in_array($r['status'], ['intro', 'reveal'], true)) $r = self::advance($r);
                    break;
                case 'reveal': if ($r['status'] === 'question') $r = self::reveal($r); break;
                case 'end': if ($r['status'] === 'question') $r = self::reveal($r); $r = self::finish($r); break;
                case 'lobby': $r['status'] = 'lobby'; $r['index'] = -1; break;
                case 'kick': $pid = (string)($body['player'] ?? ''); if (isset($r['players'][$pid])) { $r['players'][$pid]['left'] = true; $r['players'][$pid]['kicked'] = true; } break;
                case 'transfer':
                    // Hand the room to a signed-in player; the old host token stops working.
                    $pid = (string)($body['player'] ?? ''); $p = $r['players'][$pid] ?? null;
                    if (!$p || ($p['uid'] ?? '') === '' || !empty($p['left'])) throw new DomainException('Choose a signed-in player who is still in the room.');
                    $r['host'] = (string)$p['uid']; $r['host_name'] = (string)$p['name']; $r['host_hash'] = hash('sha256', bin2hex(random_bytes(16)));
                    break;
                case 'team': $pid = (string)($body['player'] ?? ''); if (isset($r['players'][$pid])) $r['players'][$pid]['team'] = max(0, min($r['settings']['teams'] - 1, (int)($body['team'] ?? 0))); break;
                case 'close': $r['status'] = 'closed'; break;
                default: throw new DomainException('Unknown host action.');
            }
            return $r;
        });
        if ($action === 'close') HomeHub::mutate('quiz-rooms', function (array $i) use ($r): array { unset($i[$r['code']]); return $i; });
        if ($action === 'start' || $action === 'restart') self::prewarm(array_map(function ($q) { return (string)($q['item'] ?? ''); }, (array)$r['questions']));
        if ($action === 'transfer') return self::view($r, $user, false, self::playerFor($r, $user));
        return self::view($r, $user, true, null);
    }
    public static function join(array $user, string $code, array $body): array {
        $signed = ($user['id'] ?? 'public') !== 'public';
        $name = $signed ? Arcade::person($user)['name'] : trim(preg_replace('/\s+/u', ' ', (string)($body['name'] ?? '')));
        if (mb_strlen($name) < 1 || mb_strlen($name) > 24 || preg_match('/[<>]/', $name)) throw new DomainException('Enter a name of up to 24 characters.');
        $token = bin2hex(random_bytes(16)); $pid = null;
        $r = self::mutate($code, function (array $r) use ($user, $signed, $name, $body, $token, &$pid): array {
            if ($r['status'] === 'closed') throw new DomainException('This quiz has finished.');
            if ($signed) foreach ($r['players'] as $id => $p) if (($p['uid'] ?? '') === $user['id']) $pid = (string)$id;
            if ($pid === null && !empty($body['player']) && isset($r['players'][(string)$body['player']]) && hash_equals((string)($r['players'][(string)$body['player']]['hash'] ?? ''), hash('sha256', (string)($body['player_token'] ?? '')))) $pid = (string)$body['player'];
            if ($pid !== null && !empty($r['players'][$pid]['kicked'])) throw new DomainException('The host removed you from this room.');
            if ($pid === null) {
                if (count($r['players']) >= 40) throw new DomainException('This room is full.');
                foreach ($r['players'] as $p) if (mb_strtolower($p['name']) === mb_strtolower($name) && empty($p['left'])) throw new DomainException('Someone already uses that name here.');
                $pid = bin2hex(random_bytes(4));
                $team = null;
                if ($r['settings']['mode'] === 'teams') {
                    $want = isset($body['team']) ? (int)$body['team'] : -1;
                    if ($want < 0 || $want >= $r['settings']['teams']) { $counts = array_fill(0, $r['settings']['teams'], 0); foreach ($r['players'] as $p) if (isset($p['team']) && isset($counts[$p['team']])) $counts[$p['team']]++; $want = (int)array_search(min($counts), $counts, true); }
                    $team = $want;
                }
                $r['players'][$pid] = ['name' => $name, 'uid' => $signed ? $user['id'] : '', 'avatar_url' => $signed ? Profiles::avatarUrl($user) : null, 'team' => $team, 'score' => 0, 'correct' => 0, 'streak' => 0, 'answers' => [], 'joined' => time()];
            }
            $r['players'][$pid]['hash'] = hash('sha256', $token); unset($r['players'][$pid]['left']);
            if (isset($body['team']) && $r['settings']['mode'] === 'teams' && $r['status'] === 'lobby') $r['players'][$pid]['team'] = max(0, min($r['settings']['teams'] - 1, (int)$body['team']));
            return $r;
        });
        return ['ok' => true, 'player' => $pid, 'player_token' => $token] + self::view($r, $user, false, $pid);
    }
    public static function answer(array $user, string $code, array $body): array {
        $pidIn = (string)($body['player'] ?? ''); $token = (string)($body['player_token'] ?? ''); $choice = (int)($body['choice'] ?? -1); $pid = null;
        $r = self::mutate($code, function (array $r) use ($user, $pidIn, $token, $choice, &$pid): array {
            $pid = self::playerFor($r, $user, $pidIn, $token);
            if ($pid === null) throw new DomainException('Join the room first.');
            if ($r['status'] !== 'question' || time() > (int)$r['ends_at'] + 1) throw new DomainException('Too late — answers are locked.');
            if (!empty($r['players'][$pid]['left'])) throw new DomainException(!empty($r['players'][$pid]['kicked']) ? 'The host removed you from this room.' : 'Rejoin the room first.');
            if (isset($r['players'][$pid]['answers'][$r['index']])) throw new DomainException('You already answered.');
            if ($choice < 0 || $choice > 3) throw new DomainException('Choose one of the answers.');
            $r['players'][$pid]['answers'][$r['index']] = ['c' => $choice, 't' => round(microtime(true) - (float)$r['started_at'], 2)];
            if (self::due($r)) $r = self::reveal($r);
            return $r;
        });
        return self::view($r, $user, false, $pid);
    }
    public static function state(array $user, string $code, array $q): array {
        $code = self::code($code);
        if (self::quiet($code, (int)($q['v'] ?? 0))) return ['ok' => true, 'same' => true, 'version' => (int)$q['v'], 'now' => microtime(true)];
        $r = self::room($code);
        $host = self::isHost($r, $user, (string)($q['host_token'] ?? ''));
        $pid = self::playerFor($r, $user, (string)($q['player'] ?? ''), (string)($q['player_token'] ?? ''));
        $auto = $r['settings']['auto'] && (($r['status'] === 'reveal' && time() - (int)($r['reveal_at'] ?? 0) >= self::REVEAL_SECONDS) || ($r['status'] === 'intro' && time() - (int)($r['intro_at'] ?? 0) >= 5) || ($r['status'] === 'scores' && time() - (int)($r['scores_at'] ?? 0) >= 10));
        if (self::due($r) || ($host && $auto)) {
            $r = self::mutate($code, function (array $r) use ($host): array {
                if (self::due($r)) return self::reveal($r);
                if ($host && $r['settings']['auto'] && in_array($r['status'], ['reveal', 'intro'], true)) return self::advance($r);
                if ($host && $r['settings']['auto'] && $r['status'] === 'scores') return self::advance(array_merge($r, ['status' => 'next']));
                return $r;
            });
        } elseif ((int)($q['v'] ?? 0) === (int)$r['version']) return ['ok' => true, 'same' => true, 'version' => (int)$r['version'], 'now' => microtime(true)];
        return self::view($r, $user, $host, $pid);
    }
    private static function view(array $r, array $user, bool $host, ?string $pid): array {
        list($players, $teams) = self::standings($r);
        $out = ['ok' => true, 'code' => $r['code'], 'version' => (int)$r['version'], 'status' => $r['status'], 'settings' => $r['settings'], 'host_name' => $r['host_name'],
            'is_host' => $host, 'players' => $players, 'teams' => $teams, 'team_names' => self::TEAMS, 'rounds' => $r['rounds'], 'total' => count($r['questions']), 'index' => (int)$r['index'], 'now' => microtime(true)];
        $q = $r['questions'][$r['index']] ?? null;
        if ($q && in_array($r['status'], ['question', 'reveal', 'scores'], true)) {
            $answered = 0; foreach ($r['players'] as $p) if (isset($p['answers'][$r['index']])) $answered++;
            $out['question'] = ['n' => (int)$r['index'], 'round' => (int)$q['round'], 'round_name' => $r['rounds'][$q['round']]['name'] ?? '', 'number_in_round' => 1 + (int)$r['index'] - (int)array_search($q['round'], array_column($r['questions'], 'round'), true),
                'question' => $q['question'], 'clue' => $q['clue'] ?? null, 'choices' => $q['choices'], 'category' => $q['category'], 'source' => $q['source'],
                'image' => !empty($q['item']) ? '/api/play/quiz/rooms/' . $r['code'] . '/image/' . (int)$r['index'] : null,
                'ends_at' => (int)$r['ends_at'], 'timer' => (int)$r['settings']['timer'], 'answered' => $answered];
            if ($r['status'] !== 'question') {
                $out['question']['answer'] = (int)$q['answer'];
                $stats = [0, 0, 0, 0]; foreach ($r['players'] as $p) { $c = (int)($p['answers'][$r['index']]['c'] ?? -1); if ($c >= 0 && $c < 4) $stats[$c]++; }
                $out['question']['stats'] = $stats;
            }
            $nq = $r['questions'][$r['index'] + 1] ?? null;
            if ($host && $nq && !empty($nq['item'])) $out['prefetch'] = '/api/play/quiz/rooms/' . $r['code'] . '/image/' . ((int)$r['index'] + 1);
        }
        if ($r['status'] === 'intro' && !empty($r['questions'][0]['item']) && $host) $out['prefetch'] = '/api/play/quiz/rooms/' . $r['code'] . '/image/0';
        if ($pid !== null) {
            $p = $r['players'][$pid];
            $out['me'] = ['id' => $pid, 'name' => $p['name'], 'team' => $p['team'] ?? null, 'score' => (int)($p['score'] ?? 0), 'streak' => (int)($p['streak'] ?? 0), 'answer' => $p['answers'][$r['index']] ?? null, 'rank' => 1 + (int)array_search($pid, array_column($players, 'id'), true), 'left' => !empty($p['left']), 'kicked' => !empty($p['kicked'])];
        }
        return $out;
    }

    // ---------------------------------------------------------------- solo: daily quiz and practice
    private static function dailySet(array $user): array {
        $date = date('Y-m-d'); $hit = \Vaultcast\Repository::cacheGet('quiz-daily:' . $date);
        if (is_array($hit['q'] ?? null)) return $hit['q'];
        $seed = crc32('daily' . $date); $qs = QuizBank::questions([], 7, $seed);
        mt_srand($seed); $lib = array_merge(self::library('lib-year', 1), self::library('lib-plot', 1), self::library('lib-frame', 1)); mt_srand();
        $qs = array_slice(array_merge($qs, $lib), 0, 10);
        if (count($qs) < 10) $qs = array_merge($qs, QuizBank::questions([], 10 - count($qs), $seed + 7));
        usort($qs, function ($a, $b) use ($seed) { return crc32($seed . $a['question'] . implode('', $a['choices'])) <=> crc32($seed . $b['question'] . implode('', $b['choices'])); });
        \Vaultcast\Repository::cachePut('quiz-daily:' . $date, ['q' => $qs], 90000);
        return $qs;
    }
    public static function daily(array $user): array {
        Arcade::signed($user); $qs = self::dailySet($user); $date = date('Y-m-d');
        $doc = (array)\Vaultcast\Repository::document('play-quiz-daily:' . $user['id'], []);
        if (($doc['date'] ?? '') !== $date) $doc = ['date' => $date, 'answers' => []];
        $libraryOk = AreaAccess::allowed($user, 'library');
        $public = array_map(function ($q, $i) use ($doc, $libraryOk) {
            $p = ['n' => $i, 'question' => $q['question'], 'clue' => $q['clue'] ?? null, 'choices' => $q['choices'], 'category' => $q['category'], 'image' => !empty($q['item']) && $libraryOk ? '/api/play/quiz/daily/image/' . $i : null];
            if (isset($doc['answers'][$i])) $p += ['chosen' => $doc['answers'][$i], 'answer' => $q['answer']];
            return $p;
        }, $qs, array_keys($qs));
        $correct = 0; foreach ($doc['answers'] as $i => $c) if ((int)$c === (int)$qs[$i]['answer']) $correct++;
        return ['ok' => true, 'date' => $date, 'questions' => $public, 'answered' => count($doc['answers']), 'correct' => $correct, 'done' => count($doc['answers']) >= count($qs)];
    }
    public static function dailyAnswer(array $user, array $body): array {
        Arcade::signed($user); $qs = self::dailySet($user); $date = date('Y-m-d');
        $i = (int)($body['n'] ?? -1); $c = (int)($body['choice'] ?? -2); $finished = null;
        if (!isset($qs[$i]) || $c < -1 || $c > 3) throw new DomainException('Choose an answer.');
        HomeHub::mutate('play-quiz-daily:' . $user['id'], function (array $d) use ($date, $i, $c, $qs, &$finished): array {
            if (($d['date'] ?? '') !== $date) $d = ['date' => $date, 'answers' => []];
            if (isset($d['answers'][$i])) throw new DomainException('Already answered.');
            if ($i !== count($d['answers'])) throw new DomainException('Answer the questions in order.');
            $d['answers'][$i] = $c;
            if (count($d['answers']) === count($qs)) { $ok = 0; foreach ($d['answers'] as $k => $v) if ((int)$v === (int)$qs[$k]['answer']) $ok++; $finished = $ok; }
            return $d;
        });
        if ($finished !== null) {
            Arcade::query('INSERT INTO vaultcast_play_scores (user_id,game,score,duration_ms,meta,created_at) VALUES (?,?,?,?,?,?)', [$user['id'], 'quiz', $finished * 100, 0, 'daily', time()]);
            Arcade::award((string)$user['id'], 'quiz', 2 * $finished + ($finished === count($qs) ? 10 : 0), 'scored ' . $finished . '/' . count($qs) . ' in the daily quiz');
        }
        return ['ok' => true, 'correct' => $c === (int)$qs[$i]['answer'], 'answer' => (int)$qs[$i]['answer']] + self::daily($user);
    }
    public static function dailyImage(array $user, int $n): void {
        if (!AreaAccess::allowed($user, 'library')) { http_response_code(403); exit; }
        $qs = self::dailySet($user); self::sendImage(!empty($qs[$n]['item']) ? self::frame((string)$qs[$n]['item']) : null);
    }
    /** Practice rounds without points: answers come with the questions; library frames are excluded. */
    public static function practice(array $user, array $q): array {
        $cat = (string)($q['category'] ?? 'general'); if ($cat === 'lib-frame') $cat = 'lib-year';
        return ['ok' => true, 'questions' => self::build([$cat], max(5, min(20, (int)($q['count'] ?? 10))), $user)];
    }
}
