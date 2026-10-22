<?php
declare(strict_types=1);

/**
 * Achievements and badges.
 *
 * Everything is evaluated on the server from data Vaultcast already records. Activity hooks
 * (a finished film, an arcade score, a quiz win, a new friend…) bump small per-user counters in
 * vaultcast_achievement_counters, and one-off facts (this film was finished, this room was joined)
 * are deduplicated with INSERT IGNORE into vaultcast_achievement_marks, so no request ever has to
 * scan a user's history. History from before 1.3.0 is filled in once per user: by the
 * "achievements" maintenance task, or automatically the first time that person's achievements
 * (page or profile) are opened. Activity hooks never do that work themselves.
 *
 * An achievement is earned once, when its counter reaches the goal. New ones become an Inbox item
 * (Achievements group) and a celebratory toast the next time the person's app checks in.
 */
final class Achievements
{
    const SETTINGS_DOC = 'achievement-settings';
    const DEFAULTS = ['enabled' => true];
    const BACKFILL_VERSION = 1;
    const SHOWCASE_MAX = 3;
    const TIERS = ['bronze' => 'Bronze', 'silver' => 'Silver', 'gold' => 'Gold', 'platinum' => 'Platinum'];
    const CATEGORIES = ['watching' => 'Watching', 'live' => 'Live TV', 'play' => 'Play', 'social' => 'Social', 'library' => 'Library', 'fun' => 'Board & Ambient'];
    /** Sections whose videos are music or games rather than films and episodes. */
    const NOT_WATCHING = ['music', 'roms', 'games', 'audiobooks', 'podcasts', 'photos'];

    /** id => [category, tier, icon, name, description, counter, goal, secret] */
    const LIST = [
        // Watching
        'first-film'      => ['watching', 'bronze', '🎬', 'Opening night', 'Finish your first film', 'films', 1, false],
        'films-10'        => ['watching', 'bronze', '🍿', 'Film buff', 'Finish 10 films', 'films', 10, false],
        'films-50'        => ['watching', 'silver', '🎞️', 'Cinephile', 'Finish 50 films', 'films', 50, false],
        'films-100'       => ['watching', 'gold', '🏛️', 'Film scholar', 'Finish 100 films', 'films', 100, false],
        'films-500'       => ['watching', 'platinum', '📽️', 'Projectionist', 'Finish 500 films', 'films', 500, false],
        'first-episode'   => ['watching', 'bronze', '📺', 'Pilot', 'Finish your first episode', 'episodes', 1, false],
        'episodes-100'    => ['watching', 'silver', '🛋️', 'Box-set regular', 'Finish 100 episodes', 'episodes', 100, false],
        'episodes-1000'   => ['watching', 'gold', '🗓️', 'Seasoned viewer', 'Finish 1,000 episodes', 'episodes', 1000, false],
        'hours-10'        => ['watching', 'bronze', '⏳', 'Comfy chair', 'Watch 10 hours of films and episodes', 'watch_seconds', 36000, false],
        'hours-100'       => ['watching', 'silver', '🪑', 'Couch commander', 'Watch 100 hours of films and episodes', 'watch_seconds', 360000, false],
        'hours-500'       => ['watching', 'gold', '🏃', 'Marathoner', 'Watch 500 hours of films and episodes', 'watch_seconds', 1800000, false],
        'hours-1000'      => ['watching', 'platinum', '👑', 'Living room legend', 'Watch 1,000 hours of films and episodes', 'watch_seconds', 3600000, false],
        'weekend-binge'   => ['watching', 'silver', '🍕', 'Weekend binge', 'Watch every episode of a season over one weekend', 'binge', 1, false],
        'finale'          => ['watching', 'silver', '🏁', 'Finale', 'Finish every episode of a series', 'series', 1, false],
        'series-10'       => ['watching', 'gold', '🗂️', 'Completionist', 'Finish every episode of 10 series', 'series', 10, false],
        'decades-5'       => ['watching', 'silver', '🕰️', 'Time traveller', 'Finish films from 5 different decades', 'decades', 5, false],
        'decades-8'       => ['watching', 'gold', '📜', 'Through the ages', 'Finish films from 8 different decades', 'decades', 8, false],
        'genres-10'       => ['watching', 'silver', '🧭', 'Genre explorer', 'Finish films from 10 different genres', 'genres', 10, false],
        'genres-20'       => ['watching', 'gold', '🗺️', 'Genre globetrotter', 'Finish films from 20 different genres', 'genres', 20, false],
        'night-owl'       => ['watching', 'bronze', '🦉', 'Night owl', 'Finish a film between midnight and 5 am', 'night', 1, true],
        'triple-bill'     => ['watching', 'silver', '🎟️', 'Triple bill', 'Finish three films in one day', 'triple', 1, true],
        // Live TV
        'live-1h'         => ['live', 'bronze', '📡', 'Tuned in', 'Watch an hour of Live TV', 'live_seconds', 3600, false],
        'live-24h'        => ['live', 'silver', '📻', 'Channel surfer', 'Watch 24 hours of Live TV', 'live_seconds', 86400, false],
        'live-100h'       => ['live', 'gold', '🗼', 'Broadcast royalty', 'Watch 100 hours of Live TV', 'live_seconds', 360000, false],
        'relay'           => ['live', 'bronze', '📼', 'Relay runner', 'Watch a PseudoTV Relay channel for 10 minutes', 'relay_seconds', 600, false],
        'relay-10h'       => ['live', 'silver', '🔁', 'Always on air', 'Watch 10 hours of PseudoTV Relay channels', 'relay_seconds', 36000, false],
        // Play
        'first-game'      => ['play', 'bronze', '🎮', 'First steps', 'Finish your first game', 'games', 1, false],
        'regular'         => ['play', 'bronze', '🕹️', 'Regular', 'Play 25 games', 'games', 25, false],
        'devoted'         => ['play', 'silver', '💎', 'Devoted', 'Play 250 games', 'games', 250, false],
        'arcade-addict'   => ['play', 'gold', '🎰', 'Arcade addict', 'Play 1,000 games', 'games', 1000, false],
        'centurion'       => ['play', 'bronze', '💯', 'Centurion', 'Earn 100 points', 'points', 100, false],
        'high-roller'     => ['play', 'silver', '🏆', 'High roller', 'Earn 2,500 points', 'points', 2500, false],
        'jackpot'         => ['play', 'gold', '💰', 'Jackpot', 'Earn 10,000 points', 'points', 10000, false],
        'hall-of-fame'    => ['play', 'platinum', '🌟', 'Hall of fame', 'Earn 50,000 points', 'points', 50000, false],
        'line-clearer'    => ['play', 'silver', '🧱', 'Line clearer', 'Score 20,000 in Stackfall', 'best_blocks', 20000, false],
        'skyscraper'      => ['play', 'gold', '🏗️', 'Skyscraper', 'Score 100,000 in Stackfall', 'best_blocks', 100000, false],
        'long-snake'      => ['play', 'silver', '🐍', 'Long snake', 'Score 500 in Neon Snake', 'best_snake', 500, false],
        'serpent'         => ['play', 'gold', '🐉', 'Serpent supreme', 'Score 2,000 in Neon Snake', 'best_snake', 2000, false],
        'brick-master'    => ['play', 'silver', '🧨', 'Brick master', 'Score 5,000 in Brickbreak', 'best_breakout', 5000, false],
        'wrecking-ball'   => ['play', 'gold', '💥', 'Wrecking ball', 'Score 25,000 in Brickbreak', 'best_breakout', 25000, false],
        'tile-2048'       => ['play', 'gold', '🧩', '2048!', 'Reach the 2048 tile', 'tile', 2048, false],
        'tile-4096'       => ['play', 'platinum', '🧠', 'Beyond 2048', 'Reach the 4096 tile', 'tile', 4096, true],
        'all-rounder'     => ['play', 'silver', '🌈', 'All-rounder', 'Score in every arcade game', 'run_games', 4, false],
        'word-first'      => ['play', 'bronze', '✏️', 'Word up', 'Solve your first Daily Five', 'word_wins', 1, false],
        'wordsmith'       => ['play', 'silver', '🔤', 'Wordsmith', 'Solve Daily Five in three guesses or fewer', 'word_quality', 4, false],
        'mind-reader'     => ['play', 'platinum', '🎯', 'Mind reader', 'Solve Daily Five with your very first guess', 'word_quality', 6, true],
        'streak'          => ['play', 'silver', '🔥', 'On a roll', 'Solve Daily Five seven days in a row', 'word_streak', 7, false],
        'streak-30'       => ['play', 'gold', '☄️', 'Unstoppable', 'Solve Daily Five 30 days in a row', 'word_streak', 30, false],
        'grandmaster'     => ['play', 'bronze', '♛', 'Checkmate!', 'Win a chess game against a friend', 'chess_wins', 1, false],
        'chess-club'      => ['play', 'silver', '♜', 'Chess club', 'Win 10 chess games against friends', 'chess_wins', 10, false],
        'blitz'           => ['play', 'gold', '⚡', 'Blitz', 'Checkmate a friend in under 20 moves', 'chess_quick', 1, false],
        'beat-machine'    => ['play', 'silver', '🤖', 'Beat the machine', 'Beat the level 3 chess computer', 'chess_ai', 3, false],
        'quiz-champ'      => ['play', 'silver', '🧠', 'Quiz champion', 'Win a quiz night', 'quiz_wins', 1, false],
        'quiz-legend'     => ['play', 'gold', '🎓', 'Quiz legend', 'Win 10 quiz nights', 'quiz_wins', 10, false],
        'daily-quiz'      => ['play', 'bronze', '📅', 'Daily quizzer', 'Finish a daily quiz', 'quiz_daily', 1, false],
        'quiz-habit'      => ['play', 'silver', '🗞️', 'Quiz habit', 'Finish 30 daily quizzes', 'quiz_daily', 30, false],
        'team-player'     => ['play', 'bronze', '🤝', 'Team player', 'Join or found a team', 'team', 1, false],
        'squad-goals'     => ['play', 'silver', '🛡️', 'Squad goals', 'Be in a team with five or more players', 'team_size', 5, false],
        'retro'           => ['play', 'bronze', '👾', 'Retro gamer', 'Start a game from the Games library', 'emu_sessions', 1, false],
        'save-state'      => ['play', 'silver', '💾', 'Save state', 'Play 50 sessions from the Games library', 'emu_sessions', 50, false],
        // Social
        'first-friend'    => ['social', 'bronze', '👋', 'Say hello', 'Make your first friend on the server', 'friends', 1, false],
        'social'          => ['social', 'silver', '🦋', 'Social butterfly', 'Have five friends on the server', 'friends', 5, false],
        'life-of-party'   => ['social', 'gold', '🎉', 'Life of the party', 'Have 15 friends on the server', 'friends', 15, false],
        'watch-party'     => ['social', 'bronze', '🎥', 'Watch party', 'Join a Watch together session', 'cinema', 1, false],
        'front-row'       => ['social', 'silver', '🎪', 'Front row regular', 'Join 10 Watch together sessions', 'cinema', 10, false],
        'critic'          => ['social', 'bronze', '✍️', 'Critic', 'Write your first review', 'reviews', 1, false],
        'reviewer'        => ['social', 'silver', '📝', 'Reviewer', 'Review 10 titles', 'reviews', 10, false],
        'top-critic'      => ['social', 'gold', '🖋️', 'Top critic', 'Review 50 titles', 'reviews', 50, false],
        // Library
        'wish-granted'    => ['library', 'bronze', '📬', 'Wish granted', 'One of your requests arrived in the library', 'requests', 1, false],
        'curator'         => ['library', 'silver', '📚', 'Curator', '10 of your requests arrived in the library', 'requests', 10, false],
        'archivist'       => ['library', 'gold', '🗄️', 'Archivist', '50 of your requests arrived in the library', 'requests', 50, false],
        // Board and Ambient
        'pin-it'          => ['fun', 'bronze', '📌', 'Pin it', 'Add a widget to your Board', 'widgets', 1, false],
        'board-architect' => ['fun', 'silver', '🧷', 'Board architect', 'Have 12 widgets across your boards', 'widgets', 12, false],
        'many-boards'     => ['fun', 'silver', '🪟', 'Many boards', 'Make three boards', 'boards', 3, false],
        'mood-lighting'   => ['fun', 'bronze', '🌙', 'Mood lighting', 'Start Ambient mode', 'ambient', 1, false],
        'zen'             => ['fun', 'silver', '🧘', 'Zen', 'Spend an hour in Ambient mode', 'ambient_seconds', 3600, false],
        'screensaver'     => ['fun', 'gold', '🌌', 'Screensaver', 'Spend 10 hours in Ambient mode', 'ambient_seconds', 36000, true],
        'overachiever'    => ['fun', 'platinum', '🏅', 'Overachiever', 'Earn 40 achievements', 'earned', 40, false],
    ];
    /** How a counter is shown in progress text. */
    const UNITS = ['watch_seconds' => 'hours', 'live_seconds' => 'hours', 'relay_seconds' => 'minutes', 'ambient_seconds' => 'minutes'];

    private static $settings = null;
    private static $counters = [];
    private static $earned = [];
    private static $sessionUid = false;
    private static $backfilling = [];
    private static $dirty = false;

    private static function q(string $sql, array $args = []): PDOStatement { $s = \Vaultcast\Database::connection()->prepare($sql); $s->execute($args); return $s; }

    // ---------------------------------------------------------------- settings
    public static function settings(): array
    {
        if (self::$settings !== null) return self::$settings;
        $doc = \Vaultcast\Repository::document(self::SETTINGS_DOC, []);
        $out = self::DEFAULTS;
        if (is_array($doc)) foreach (self::DEFAULTS as $k => $v) if (array_key_exists($k, $doc)) $out[$k] = (bool)$doc[$k];
        return self::$settings = $out;
    }
    public static function enabled(): bool
    {
        try { return !empty(self::settings()['enabled']); } catch (Throwable $e) { return false; }
    }
    public static function settingsView(): array
    {
        $earned = 0; $people = 0;
        try {
            $earned = (int)self::q('SELECT COUNT(*) FROM vaultcast_achievements')->fetchColumn();
            $people = (int)self::q('SELECT COUNT(DISTINCT user_id) FROM vaultcast_achievements')->fetchColumn();
        } catch (Throwable $e) {}
        return ['ok' => true, 'settings' => self::settings(), 'total' => count(self::LIST), 'earned' => $earned, 'people' => $people];
    }
    public static function saveSettings(array $body): array
    {
        $doc = self::settings();
        foreach (self::DEFAULTS as $k => $v) if (array_key_exists($k, $body)) {
            if (!is_bool($body[$k])) throw new InvalidArgumentException('Choose on or off.');
            $doc[$k] = $body[$k];
        }
        \Vaultcast\Repository::putDocument(self::SETTINGS_DOC, $doc);
        self::$settings = null;
        return self::settingsView();
    }
    /** What the app needs to know for the signed-in person (part of /me). */
    public static function userStatus(array $user): array
    {
        return ['enabled' => self::enabled() && self::signedIn($user)];
    }

    // ---------------------------------------------------------------- who counts
    private static function signedIn(?array $user): bool
    {
        return $user && ($user['role'] ?? 'guest') !== 'guest' && (string)($user['id'] ?? '') !== '' && (string)($user['id'] ?? '') !== 'public';
    }
    private static function validUid(string $uid): bool { return $uid !== '' && $uid !== 'public' && strlen($uid) <= 64; }
    /** Kids and restricted profiles still earn achievements; this only decides whether others may see them. */
    public static function socialAllowed(array $user): bool
    {
        if (($user['role'] ?? '') === 'admin') return true;
        try { $st = Parental::settingsFor($user); } catch (Throwable $e) { return true; }
        if (empty($st['enabled']) && empty($st['kid'])) return true;
        return !empty($st['social']);
    }
    private static function sessionUid(): string
    {
        if (self::$sessionUid === false) {
            try { $s = Auth::currentSession(); self::$sessionUid = $s ? (string)($s['user_id'] ?? '') : ''; } catch (Throwable $e) { self::$sessionUid = ''; }
        }
        return (string)self::$sessionUid;
    }
    /**
     * Hooks must never break the activity they observe, and stay cheap: they never fill in history.
     * Counters only rise to recorded totals when history is filled in (GREATEST), so activity counted
     * here first is never counted twice.
     */
    private static function guard(string $uid, callable $fn): void
    {
        if (!self::validUid($uid) || !self::enabled()) return;
        try {
            self::$dirty = false;
            $fn();
            // Nothing changed (a repeated mark, a score below the best): no need to look at the list.
            if (self::$dirty) self::evaluate($uid);
        }
        catch (Throwable $e) { error_log('Vaultcast achievements: ' . $e->getMessage()); }
    }

    // ---------------------------------------------------------------- counters and marks
    private static function counters(string $uid): array
    {
        if (!isset(self::$counters[$uid])) {
            $out = [];
            foreach (self::q('SELECT counter, value FROM vaultcast_achievement_counters WHERE user_id=?', [$uid]) as $r) $out[(string)$r['counter']] = (int)$r['value'];
            self::$counters[$uid] = $out;
        }
        return self::$counters[$uid];
    }
    private static function bump(string $uid, string $counter, int $delta = 1): void
    {
        if ($delta === 0) return;
        self::q('INSERT INTO vaultcast_achievement_counters (user_id,counter,value,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE value=value+VALUES(value), updated_at=VALUES(updated_at)', [$uid, $counter, $delta, time()]);
        self::$dirty = true;
        if (isset(self::$counters[$uid])) self::$counters[$uid][$counter] = (self::$counters[$uid][$counter] ?? 0) + $delta;
    }
    private static function raise(string $uid, string $counter, int $value): void
    {
        $c = self::counters($uid);
        if (isset($c[$counter]) && $c[$counter] >= $value) return;
        self::q('INSERT INTO vaultcast_achievement_counters (user_id,counter,value,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE value=GREATEST(value,VALUES(value)), updated_at=VALUES(updated_at)', [$uid, $counter, $value, time()]);
        self::$dirty = true;
        self::$counters[$uid][$counter] = max($c[$counter] ?? 0, $value);
    }
    private static function setCounter(string $uid, string $counter, int $value): void
    {
        self::q('INSERT INTO vaultcast_achievement_counters (user_id,counter,value,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value), updated_at=VALUES(updated_at)', [$uid, $counter, $value, time()]);
        if (isset(self::$counters[$uid])) self::$counters[$uid][$counter] = $value;
    }
    /** True only the first time this fact is recorded for the person. */
    private static function mark(string $uid, string $mark, ?int $at = null): bool
    {
        $mark = substr(preg_replace('/[^\x21-\x7e]/', '', $mark), 0, 96);
        return self::q('INSERT IGNORE INTO vaultcast_achievement_marks (user_id,mark,created_at) VALUES (?,?,?)', [$uid, $mark, $at ?? time()])->rowCount() > 0;
    }
    private static function countMarks(string $uid, string $prefix): int
    {
        return (int)self::q('SELECT COUNT(*) FROM vaultcast_achievement_marks WHERE user_id=? AND mark LIKE ?', [$uid, str_replace(['%', '_'], ['\\%', '\\_'], $prefix) . '%'])->fetchColumn();
    }
    /** How many of these marks exist, optionally only those recorded since a time. */
    private static function countIn(string $uid, array $marks, int $since = 0): int
    {
        $n = 0;
        foreach (array_chunk(array_values(array_unique($marks)), 400) as $chunk) {
            $n += (int)self::q('SELECT COUNT(*) FROM vaultcast_achievement_marks WHERE user_id=? AND created_at>=? AND mark IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', array_merge([$uid, $since], $chunk))->fetchColumn();
        }
        return $n;
    }

    // ---------------------------------------------------------------- earning
    private static function earned(string $uid): array
    {
        if (!isset(self::$earned[$uid])) {
            $out = [];
            foreach (self::q('SELECT ach_id, earned_at, toasted, showcase FROM vaultcast_achievements WHERE user_id=?', [$uid]) as $r) $out[(string)$r['ach_id']] = ['earned_at' => (int)$r['earned_at'], 'toasted' => (int)$r['toasted'], 'showcase' => (int)$r['showcase']];
            self::$earned[$uid] = $out;
        }
        return self::$earned[$uid];
    }
    /** Award everything whose goal has been reached. Returns the ids that are new. */
    private static function evaluate(string $uid, bool $quiet = false): array
    {
        $new = [];
        for ($pass = 0; $pass < 2; $pass++) {
            $c = self::counters($uid); $have = self::earned($uid); $c['earned'] = count($have);
            foreach (self::LIST as $id => $a) {
                if (isset($have[$id]) || ($c[$a[5]] ?? 0) < $a[6]) continue;
                $now = time();
                if (self::q('INSERT IGNORE INTO vaultcast_achievements (user_id,ach_id,earned_at,toasted,showcase) VALUES (?,?,?,?,0)', [$uid, $id, $now, $quiet ? 1 : 0])->rowCount() > 0) {
                    self::$earned[$uid][$id] = ['earned_at' => $now, 'toasted' => $quiet ? 1 : 0, 'showcase' => 0];
                    $new[] = $id;
                }
            }
            if (!$new || $pass === 1) break; // a second pass picks up "earn N achievements"
        }
        if ($new && !$quiet) self::announce($uid, $new);
        if ($new) \Vaultcast\Repository::cachePut('achievements-rarity', [], 1);
        return $new;
    }
    private static function announce(string $uid, array $ids): void
    {
        foreach ($ids as $id) {
            $a = self::LIST[$id];
            Inbox::add($uid, 'achievement', $a[1], 'ach:' . $id, null, 'Achievement unlocked: ' . $a[3], $a[4] . ' · ' . self::TIERS[$a[1]], ['kind' => 'path', 'path' => 'play/achievements'], ['achievement' => $id, 'icon' => $a[2], 'tier' => $a[1]]);
        }
        // The person's own request: tell the app straight away so the toast does not wait for the next poll.
        if ($uid === self::sessionUid() && !headers_sent()) header('X-Vaultcast-Achievement: ' . count($ids));
    }

    // ---------------------------------------------------------------- activity hooks
    /** A film or episode was played to the end (progress past 92%). */
    public static function watched(array $user, string $itemId, float $duration): void
    {
        if (!self::signedIn($user) || !preg_match('/^[a-f0-9]{8,64}$/D', $itemId) || !self::enabled()) return;
        $uid = (string)$user['id'];
        // The player keeps reporting after the finish line: one INSERT IGNORE and out for every tick after the first.
        try { if (!self::mark($uid, 'w:' . $itemId)) return; } catch (Throwable $e) { return; }
        self::guard($uid, function () use ($uid, $itemId, $duration) { self::recordWatch($uid, $itemId, $duration, time(), true); });
    }
    private static function recordWatch(string $uid, string $itemId, float $duration, int $at, bool $live): bool
    {
        $row = self::q('SELECT media_kind, type, show_key, season, year FROM vaultcast_browse_items WHERE item_id=?', [$itemId])->fetch(PDO::FETCH_ASSOC);
        if (!$row || (string)$row['media_kind'] !== 'video' || in_array((string)$row['type'], self::NOT_WATCHING, true)) return false;
        $episode = (string)$row['show_key'] !== '';
        if (!self::mark($uid, ($episode ? 'e:' : 'f:') . $itemId, $at)) return false;
        self::bump($uid, $episode ? 'episodes' : 'films');
        $seconds = (int)round(max(0.0, min(14400.0, $duration)));
        if ($seconds > 0) self::bump($uid, 'watch_seconds', $seconds);
        if (!$episode) {
            $year = (int)($row['year'] ?? 0);
            if ($year >= 1880 && $year <= 2100 && self::mark($uid, 'd:' . (intdiv($year, 10) * 10), $at)) self::bump($uid, 'decades');
            $genres = (string)(self::q('SELECT genres FROM vaultcast_smart_facts WHERE item_id=?', [$itemId])->fetchColumn() ?: '');
            foreach (array_slice(array_filter(explode('|', $genres), 'strlen'), 0, 12) as $g) if (self::mark($uid, 'g:' . substr(sha1(mb_strtolower($g)), 0, 20), $at)) self::bump($uid, 'genres');
            if ($live) {
                if ((int)date('G', $at) < 5) self::raise($uid, 'night', 1);
                if ((int)self::q("SELECT COUNT(*) FROM vaultcast_achievement_marks WHERE user_id=? AND mark LIKE 'f:%' AND created_at>=?", [$uid, strtotime('today', $at)])->fetchColumn() >= 3) self::raise($uid, 'triple', 1);
            }
            return true;
        }
        if ($live) self::showProgress($uid, (string)$row['show_key'], $row['season'] === null ? null : (int)$row['season'], $at);
        return true;
    }
    /** Finished series and weekend season binges, checked only when an episode is finished. */
    private static function showProgress(string $uid, string $showKey, ?int $season, int $at): void
    {
        $eps = self::q("SELECT item_id, season FROM vaultcast_browse_items WHERE show_key=? AND media_kind='video'", [$showKey])->fetchAll(PDO::FETCH_ASSOC);
        if (count($eps) < 2) return;
        $marks = array_map(function ($r) { return 'e:' . $r['item_id']; }, $eps);
        if (self::countIn($uid, $marks) >= count($marks) && self::mark($uid, 's:' . $showKey, $at)) self::bump($uid, 'series');
        $dow = (int)date('N', $at);
        if ($season === null || $dow < 6) return;
        $inSeason = []; foreach ($eps as $r) if ($r['season'] !== null && (int)$r['season'] === $season) $inSeason[] = 'e:' . $r['item_id'];
        if (count($inSeason) < 3) return;
        $weekend = strtotime('today', $at) - ($dow - 6) * 86400;
        if (self::countIn($uid, $inSeason, $weekend) >= count($inSeason)) self::raise($uid, 'binge', 1);
    }
    public static function arcade(string $uid, string $game, int $score, string $meta): void
    {
        self::guard($uid, function () use ($uid, $game, $score, $meta) {
            self::bump($uid, 'games');
            if (in_array($game, Arcade::RUN_GAMES, true)) {
                if ($score > 0 && self::mark($uid, 'rg:' . $game)) self::bump($uid, 'run_games');
                self::raise($uid, 'best_' . $game, $score);
            }
            if ($game === 'merge' && preg_match_all('/tile:(\d{1,7})/', $meta, $m)) self::raise($uid, 'tile', max(array_map('intval', $m[1])));
        });
    }
    public static function points(string $uid, int $points): void
    {
        if ($points <= 0) return;
        self::guard($uid, function () use ($uid, $points) { self::bump($uid, 'points', $points); });
    }
    public static function word(string $uid, bool $won, int $guesses, int $streak): void
    {
        self::guard($uid, function () use ($uid, $won, $guesses, $streak) {
            self::bump($uid, 'games');
            if (!$won) return;
            self::bump($uid, 'word_wins');
            self::raise($uid, 'word_quality', max(0, 7 - $guesses));
            self::raise($uid, 'word_streak', $streak);
        });
    }
    /** $plies: half-moves in the finished game. */
    public static function chessWin(string $uid, int $plies, string $reason): void
    {
        self::guard($uid, function () use ($uid, $plies, $reason) {
            self::bump($uid, 'games'); self::bump($uid, 'chess_wins');
            if ($reason === 'checkmate' && $plies > 0 && (int)ceil($plies / 2) < 20) self::raise($uid, 'chess_quick', 1);
        });
    }
    public static function chessAi(string $uid, string $result, int $level): void
    {
        if ($result !== 'win') return;
        self::guard($uid, function () use ($uid, $level) { self::raise($uid, 'chess_ai', max(1, min(3, $level))); });
    }
    public static function quiz(string $uid, bool $win): void
    {
        self::guard($uid, function () use ($uid, $win) { self::bump($uid, 'games'); if ($win) self::bump($uid, 'quiz_wins'); });
    }
    public static function quizDaily(string $uid): void
    {
        self::guard($uid, function () use ($uid) { self::bump($uid, 'games'); self::bump($uid, 'quiz_daily'); });
    }
    public static function friends(string $uid): void
    {
        self::guard($uid, function () use ($uid) { self::raise($uid, 'friends', count(Social::friends($uid))); });
    }
    public static function review(string $uid, string $key): void
    {
        self::guard($uid, function () use ($uid, $key) { if (self::mark($uid, 'r:' . substr(sha1($key), 0, 24))) self::bump($uid, 'reviews'); });
    }
    public static function cinema(string $uid, string $room): void
    {
        if (!preg_match('/^[a-f0-9]{16}$/D', $room)) return;
        self::guard($uid, function () use ($uid, $room) { if (self::mark($uid, 'c:' . $room)) self::bump($uid, 'cinema'); });
    }
    public static function requestAvailable(string $uid, int $id): void
    {
        self::guard($uid, function () use ($uid, $id) { if (self::mark($uid, 'q:' . $id)) self::bump($uid, 'requests'); });
    }
    public static function boards(string $uid, array $boards): void
    {
        self::guard($uid, function () use ($uid, $boards) {
            $w = 0; foreach ($boards as $b) $w += count((array)($b['widgets'] ?? []));
            self::raise($uid, 'widgets', $w); self::raise($uid, 'boards', count($boards));
        });
    }
    public static function team(string $uid): void
    {
        self::guard($uid, function () use ($uid) {
            $t = Social::teamOf($uid); if (!$t) return;
            self::raise($uid, 'team', 1); self::raise($uid, 'team_size', count((array)($t['members'] ?? [])));
        });
    }
    /** Teammates also benefit when someone joins their team (teams hold up to 30 players). */
    public static function teamMembers(string $joiner, array $uids): void
    {
        if (!self::enabled()) return;
        self::team($joiner);
        foreach (array_slice(array_values(array_unique(array_map('strval', $uids))), 0, 40) as $uid) if ($uid !== $joiner) self::team($uid);
    }
    public static function emulator(string $uid): void
    {
        self::guard($uid, function () use ($uid) { self::bump($uid, 'emu_sessions'); });
    }
    /**
     * Time-based progress (Live TV, PseudoTV Relay, Ambient). The app reports once a minute while it
     * is actually playing or showing; the server credits at most one minute per 50 seconds of real time.
     */
    public static function tick(array $user, array $body): array
    {
        if (!self::signedIn($user)) throw new DomainException('Sign in to earn achievements.');
        if (!self::enabled()) return ['ok' => true, 'enabled' => false];
        $kind = (string)($body['kind'] ?? '');
        if (!in_array($kind, ['live', 'relay', 'ambient'], true)) throw new DomainException('Unknown activity.');
        $uid = (string)$user['id'];
        self::guard($uid, function () use ($uid, $kind, $body) {
            if ($kind === 'ambient') self::raise($uid, 'ambient', 1);
            if (!empty($body['start'])) return;
            $clock = $kind === 'ambient' ? 'tick_ambient' : 'tick_tv';
            $last = self::counters($uid)[$clock] ?? 0; $now = time();
            if ($now - $last < 50) return;
            self::setCounter($uid, $clock, $now);
            self::bump($uid, $kind === 'ambient' ? 'ambient_seconds' : ($kind === 'relay' ? 'relay_seconds' : 'live_seconds'), 60);
            // PseudoTV Relay is Live TV too.
            if ($kind === 'relay') self::bump($uid, 'live_seconds', 60);
        });
        return ['ok' => true, 'enabled' => true];
    }

    // ---------------------------------------------------------------- history (one-off per person)
    /** Fill in history the first time someone opens their achievements (or a profile shows them). */
    private static function ensureBackfilled(string $uid): void
    {
        if (isset(self::$backfilling[$uid])) return;
        if ((self::counters($uid)['backfilled'] ?? 0) >= self::BACKFILL_VERSION) return;
        self::backfillUser($uid);
    }
    /** Rebuild counters from recorded history. Counters only ever go up, so live activity is never lost. */
    public static function backfillUser(string $uid, ?callable $checkpoint = null): array
    {
        if (!self::validUid($uid)) return [];
        self::$backfilling[$uid] = true;
        try {
            // Watching: finished playback in the progress table.
            $finished = [];
            foreach (self::q('SELECT item_id, updated_at, payload FROM vaultcast_progress WHERE user_id=?', [$uid]) as $r) {
                $p = json_decode((string)$r['payload'], true); if (!is_array($p)) continue;
                $d = (float)($p['duration'] ?? 0); $pos = (float)($p['position'] ?? 0);
                if ((int)($p['viewings'] ?? 0) > 0 || ($d > 0 && $pos / $d > 0.92)) $finished[(string)$r['item_id']] = [$d, (int)$r['updated_at']];
            }
            $shows = [];
            foreach (array_chunk(array_keys($finished), 300, false) as $chunk) {
                if ($checkpoint) $checkpoint();
                $chunk = array_map('strval', $chunk);
                foreach (self::q('SELECT item_id, show_key FROM vaultcast_browse_items WHERE item_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $b) {
                    $id = (string)$b['item_id'];
                    if (self::recordWatch($uid, $id, $finished[$id][0], $finished[$id][1] ?: time(), false) && (string)$b['show_key'] !== '') $shows[(string)$b['show_key']] = true;
                }
            }
            foreach (array_keys($shows) as $key) {
                $eps = self::q("SELECT item_id FROM vaultcast_browse_items WHERE show_key=? AND media_kind='video'", [$key])->fetchAll(PDO::FETCH_COLUMN);
                if (count($eps) >= 2 && self::countIn($uid, array_map(function ($i) { return 'e:' . $i; }, $eps)) >= count($eps)) self::mark($uid, 's:' . $key);
            }
            foreach (['films' => 'f:', 'episodes' => 'e:', 'decades' => 'd:', 'genres' => 'g:', 'series' => 's:', 'cinema' => 'c:'] as $counter => $prefix) self::raise($uid, $counter, self::countMarks($uid, $prefix));
            // Play.
            $plays = 0;
            foreach (self::q('SELECT game, COUNT(*) n, MAX(score) best FROM vaultcast_play_scores WHERE user_id=? GROUP BY game', [$uid]) as $g) {
                $plays += (int)$g['n'];
                if (in_array((string)$g['game'], Arcade::RUN_GAMES, true)) { self::raise($uid, 'best_' . $g['game'], (int)$g['best']); if ((int)$g['best'] > 0) self::mark($uid, 'rg:' . $g['game']); }
            }
            self::raise($uid, 'games', $plays);
            self::raise($uid, 'run_games', self::countMarks($uid, 'rg:'));
            self::raise($uid, 'points', (int)self::q('SELECT COALESCE(SUM(points),0) FROM vaultcast_play_points WHERE user_id=?', [$uid])->fetchColumn());
            foreach (self::q("SELECT meta FROM vaultcast_play_scores WHERE user_id=? AND game='merge' AND meta LIKE '%tile:%' ORDER BY score DESC LIMIT 50", [$uid]) as $m) if (preg_match_all('/tile:(\d{1,7})/', (string)$m['meta'], $mm)) self::raise($uid, 'tile', max(array_map('intval', $mm[1])));
            self::raise($uid, 'chess_wins', (int)self::q("SELECT COUNT(*) FROM vaultcast_play_scores WHERE user_id=? AND game='chess' AND meta='win'", [$uid])->fetchColumn());
            self::raise($uid, 'quiz_wins', (int)self::q("SELECT COUNT(*) FROM vaultcast_play_scores WHERE user_id=? AND game='quiz' AND meta='win'", [$uid])->fetchColumn());
            self::raise($uid, 'quiz_daily', (int)self::q("SELECT COUNT(*) FROM vaultcast_play_scores WHERE user_id=? AND game='quiz' AND meta='daily'", [$uid])->fetchColumn());
            $hist = (array)(\Vaultcast\Repository::document('play-word:' . $uid, [])['history'] ?? []);
            ksort($hist); $wins = 0; $quality = 0; $run = 0; $best = 0; $wonDay = '';
            foreach ($hist as $day => $h) {
                $day = (string)$day;
                if (empty($h['won'])) { $run = 0; continue; }
                $wins++; $quality = max($quality, 7 - (int)($h['guesses'] ?? 7));
                // A streak continues only from a win on the calendar day before.
                $run = ($wonDay !== '' && $run > 0 && date('Y-m-d', strtotime($wonDay . ' 12:00:00') + 86400) === $day) ? $run + 1 : 1;
                $best = max($best, $run); $wonDay = $day;
            }
            self::raise($uid, 'word_wins', $wins); self::raise($uid, 'word_quality', $quality); self::raise($uid, 'word_streak', $best);
            $t = Social::teamOf($uid);
            if ($t || (bool)self::q("SELECT 1 FROM vaultcast_play_points WHERE user_id=? AND source='social' LIMIT 1", [$uid])->fetchColumn()) self::raise($uid, 'team', 1);
            if ($t) self::raise($uid, 'team_size', count((array)($t['members'] ?? [])));
            self::raise($uid, 'emu_sessions', (int)self::q('SELECT COALESCE(SUM(sessions),0) FROM vaultcast_play_emulator WHERE user_id=?', [$uid])->fetchColumn());
            // Social, library and boards.
            self::raise($uid, 'friends', count(Social::friends($uid)));
            foreach (self::q("SELECT r.id FROM vaultcast_media_request_users u JOIN vaultcast_media_requests r ON r.id=u.request_id WHERE u.user_id=? AND r.status='available'", [$uid]) as $r) self::mark($uid, 'q:' . (int)$r['id']);
            self::raise($uid, 'requests', self::countMarks($uid, 'q:'));
            $boards = (array)(\Vaultcast\Repository::document('misc-boards:' . $uid, [])['boards'] ?? []);
            $w = 0; foreach ($boards as $b) $w += count((array)($b['widgets'] ?? []));
            self::raise($uid, 'widgets', $w); self::raise($uid, 'boards', count($boards));
            self::raise($uid, 'reviews', self::countMarks($uid, 'r:'));
            self::setCounter($uid, 'backfilled', self::BACKFILL_VERSION);
            self::$counters[$uid]['backfilled'] = self::BACKFILL_VERSION;
            // Past achievements arrive quietly, with one Inbox note instead of a toast for each.
            $new = self::evaluate($uid, true);
            if ($new) Inbox::add($uid, 'achievement', 'history', 'ach-history', null, count($new) === 1 ? 'You already earned an achievement' : 'You already earned ' . count($new) . ' achievements',
                'Your history on this server counts. Have a look at what you have unlocked so far.', ['kind' => 'path', 'path' => 'play/achievements'], ['count' => count($new)]);
            return $new;
        } finally { unset(self::$backfilling[$uid]); }
    }
    /** Reviews live in per-title documents: one pass over all of them marks every author. */
    private static function backfillReviews(?callable $checkpoint): void
    {
        $last = '';
        do {
            $rows = self::q("SELECT name, payload FROM vaultcast_documents WHERE name LIKE 'hub-reviews:%' AND name>? ORDER BY name LIMIT 200", [$last])->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $last = (string)$r['name']; $key = substr($last, strlen('hub-reviews:'));
                $d = json_decode((string)$r['payload'], true); if (!is_array($d)) continue;
                foreach (array_keys($d) as $author) if (self::validUid((string)$author)) self::mark((string)$author, 'r:' . substr(sha1($key), 0, 24), (int)($d[$author]['updated_at'] ?? time()));
            }
            if ($checkpoint) $checkpoint();
        } while (count($rows) === 200);
    }
    /** Maintenance task: fill in history for everyone (safe to run again at any time). */
    public static function backfill(?callable $checkpoint = null, int $budget = 600): array
    {
        if (!self::enabled()) return ['enabled' => false, 'users' => 0];
        $started = time(); $done = 0; $earned = 0; $deferred = false;
        $state = (array)\Vaultcast\Repository::document('achievements-backfill', []);
        if (empty($state['reviews'])) { self::backfillReviews($checkpoint); $state['reviews'] = true; $state['after'] = ''; \Vaultcast\Repository::putDocument('achievements-backfill', $state); }
        $after = (string)($state['after'] ?? '');
        while (true) {
            $ids = self::q("SELECT id FROM vaultcast_users WHERE role<>'guest' AND id>? ORDER BY id LIMIT 50", [$after])->fetchAll(PDO::FETCH_COLUMN);
            if (!$ids) break;
            foreach ($ids as $uid) {
                $uid = (string)$uid; $after = $uid;
                // Force a fresh pass: reviews were just marked and counters may predate them.
                $earned += count(self::backfillUser($uid, $checkpoint)); $done++;
                unset(self::$counters[$uid], self::$earned[$uid]);
                if (time() - $started > $budget) { $deferred = true; break 2; }
            }
            $state['after'] = $after; \Vaultcast\Repository::putDocument('achievements-backfill', $state);
        }
        $state['after'] = $deferred ? $after : ''; if (!$deferred) $state['reviews'] = false;
        \Vaultcast\Repository::putDocument('achievements-backfill', $state);
        \Vaultcast\Repository::cachePut('achievements-rarity', [], 1);
        return ['users' => $done, 'earned' => $earned, 'deferred' => $deferred];
    }

    // ---------------------------------------------------------------- reading
    /** Share of people on the server who have each achievement, cached for a few minutes. */
    private static function rarity(): array
    {
        $c = \Vaultcast\Repository::cacheGet('achievements-rarity');
        if (is_array($c) && isset($c['people'])) return $c;
        $people = max(1, (int)self::q("SELECT COUNT(*) FROM vaultcast_users WHERE role<>'guest'")->fetchColumn());
        $counts = [];
        foreach (self::q('SELECT ach_id, COUNT(*) n FROM vaultcast_achievements GROUP BY ach_id') as $r) $counts[(string)$r['ach_id']] = (int)$r['n'];
        $out = ['people' => $people, 'pct' => []];
        foreach ($counts as $id => $n) $out['pct'][$id] = round(min(100, 100 * $n / $people), 1);
        \Vaultcast\Repository::cachePut('achievements-rarity', $out, 600);
        return $out;
    }
    private static function publicRow(string $id, ?array $earned, array $counters, array $rarity, bool $reveal): array
    {
        $a = self::LIST[$id]; $value = min((int)($counters[$a[5]] ?? 0), $a[6]);
        $hidden = $a[7] && !$earned && !$reveal;
        $unit = self::UNITS[$a[5]] ?? '';
        $fmt = function (int $n) use ($unit): string {
            if ($unit === 'hours') return number_format($n / 3600, $n < 36000 ? 1 : 0) . ' h';
            if ($unit === 'minutes') return number_format(intdiv($n, 60)) . ' min';
            return number_format($n);
        };
        return ['id' => $id, 'category' => $a[0], 'category_label' => self::CATEGORIES[$a[0]], 'tier' => $a[1], 'tier_label' => self::TIERS[$a[1]],
            'icon' => $hidden ? '❔' : $a[2], 'name' => $hidden ? 'Secret achievement' : $a[3], 'description' => $hidden ? 'Keep exploring to discover this one.' : $a[4],
            'secret' => $a[7], 'hidden' => $hidden, 'earned' => (bool)$earned, 'earned_at' => $earned ? (int)$earned['earned_at'] : 0,
            'progress' => $earned ? 1.0 : ($hidden || $a[6] <= 1 ? 0.0 : round($value / $a[6], 3)),
            'progress_text' => $earned || $hidden || $a[6] <= 1 ? '' : $fmt($value) . ' of ' . $fmt((int)$a[6]),
            'rarity' => (float)($rarity['pct'][$id] ?? 0), 'showcase' => $earned ? (int)$earned['showcase'] : 0];
    }
    /** The Achievements page for the signed-in person. */
    public static function view(array $user): array
    {
        if (!self::signedIn($user)) throw new DomainException('Sign in to see your achievements.');
        if (!self::enabled()) return ['ok' => true, 'enabled' => false, 'achievements' => [], 'categories' => (object)[], 'summary' => null];
        $uid = (string)$user['id'];
        self::ensureBackfilled($uid);
        $counters = self::counters($uid); $earned = self::earned($uid); $rarity = self::rarity();
        $counters['earned'] = count($earned);
        $rows = []; $points = 0; $byTier = array_fill_keys(array_keys(self::TIERS), 0);
        foreach (array_keys(self::LIST) as $id) {
            $row = self::publicRow($id, $earned[$id] ?? null, $counters, $rarity, false);
            if ($row['earned']) $byTier[$row['tier']]++;
            $rows[] = $row;
        }
        return ['ok' => true, 'enabled' => true, 'achievements' => $rows, 'categories' => self::CATEGORIES, 'tiers' => self::TIERS,
            'summary' => ['earned' => count($earned), 'total' => count(self::LIST), 'tiers' => $byTier, 'people' => (int)$rarity['people']],
            'showcase' => self::showcaseIds($uid), 'social' => self::socialAllowed($user), 'showcase_max' => self::SHOWCASE_MAX];
    }
    private static function showcaseIds(string $uid): array
    {
        $picked = [];
        foreach (self::earned($uid) as $id => $e) if ($e['showcase'] > 0 && isset(self::LIST[$id])) $picked[$id] = $e['showcase'];
        asort($picked);
        return array_slice(array_keys($picked), 0, self::SHOWCASE_MAX);
    }
    /** Up to three earned achievements, in order, for the profile. */
    public static function saveShowcase(array $user, array $body): array
    {
        if (!self::signedIn($user)) throw new DomainException('Sign in to choose your badges.');
        if (!self::enabled()) throw new DomainException('Achievements are turned off on this server.');
        $uid = (string)$user['id']; $have = self::earned($uid);
        $ids = [];
        foreach (array_slice((array)($body['ids'] ?? []), 0, 10) as $id) {
            if (!is_string($id) || !isset(self::LIST[$id])) continue;
            if (!isset($have[$id])) throw new DomainException('You can only show achievements you have earned.');
            if (!in_array($id, $ids, true)) $ids[] = $id;
        }
        if (count($ids) > self::SHOWCASE_MAX) throw new DomainException('Choose up to three achievements.');
        \Vaultcast\Database::transaction(function (PDO $pdo) use ($uid, $ids): void {
            $pdo->prepare('UPDATE vaultcast_achievements SET showcase=0 WHERE user_id=?')->execute([$uid]);
            foreach ($ids as $i => $id) $pdo->prepare('UPDATE vaultcast_achievements SET showcase=? WHERE user_id=? AND ach_id=?')->execute([$i + 1, $uid, $id]);
        });
        unset(self::$earned[$uid]);
        return ['ok' => true, 'showcase' => self::showcaseIds($uid)];
    }
    /** Showcase badges for a profile; the caller has already applied profile privacy. */
    public static function showcaseFor(array $target): array
    {
        if (!self::enabled() || !self::signedIn($target) || !self::socialAllowed($target)) return [];
        $uid = (string)$target['id']; $earned = self::earned($uid); $out = [];
        foreach (self::showcaseIds($uid) as $id) { $a = self::LIST[$id]; $out[] = ['id' => $id, 'name' => $a[3], 'detail' => $a[4], 'icon' => $a[2], 'tier' => $a[1], 'tier_label' => self::TIERS[$a[1]], 'earned_at' => (int)$earned[$id]['earned_at']]; }
        return $out;
    }
    /** Badges for Play profiles, in the shape the profile view already understands. Secret ones only once earned. */
    public static function profileBadges(array $target): array
    {
        $uid = (string)$target['id']; self::ensureBackfilled($uid); $earned = self::earned($uid); $out = [];
        foreach (self::LIST as $id => $a) {
            if ($a[7] && !isset($earned[$id])) continue;
            $out[] = ['id' => $id, 'name' => $a[3], 'detail' => $a[4], 'icon' => $a[2], 'tier' => $a[1], 'category' => $a[0], 'earned' => isset($earned[$id])];
        }
        return $out;
    }
    /** Achievements earned but not yet celebrated with a toast. */
    public static function pending(array $user): array
    {
        if (!self::signedIn($user) || !self::enabled()) return ['ok' => true, 'items' => []];
        $out = [];
        foreach (self::q('SELECT ach_id, earned_at FROM vaultcast_achievements WHERE user_id=? AND toasted=0 ORDER BY earned_at LIMIT 10', [(string)$user['id']]) as $r) {
            $id = (string)$r['ach_id']; if (!isset(self::LIST[$id])) continue; $a = self::LIST[$id];
            $out[] = ['id' => $id, 'name' => $a[3], 'description' => $a[4], 'icon' => $a[2], 'tier' => $a[1], 'tier_label' => self::TIERS[$a[1]], 'earned_at' => (int)$r['earned_at']];
        }
        return ['ok' => true, 'items' => $out];
    }
    public static function toasted(array $user, array $body): array
    {
        if (!self::signedIn($user)) throw new DomainException('Sign in first.');
        $ids = array_values(array_filter(array_slice((array)($body['ids'] ?? []), 0, 20), function ($id) { return is_string($id) && isset(self::LIST[$id]); }));
        if ($ids) self::q('UPDATE vaultcast_achievements SET toasted=1 WHERE user_id=? AND ach_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([(string)$user['id']], $ids));
        return ['ok' => true];
    }
    /** Cheap check for the Inbox badge poll. */
    public static function hasPending(string $uid): bool
    {
        if (!self::validUid($uid) || !self::enabled()) return false;
        try { return (bool)self::q('SELECT 1 FROM vaultcast_achievements WHERE user_id=? AND toasted=0 LIMIT 1', [$uid])->fetchColumn(); } catch (Throwable $e) { return false; }
    }
    public static function purgeUser(string $uid): void
    {
        foreach (['vaultcast_achievements', 'vaultcast_achievement_counters', 'vaultcast_achievement_marks'] as $t) self::q('DELETE FROM ' . $t . ' WHERE user_id=?', [$uid]);
        \Vaultcast\Repository::cachePut('achievements-rarity', [], 1);
    }
}
