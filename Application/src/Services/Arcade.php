<?php
declare(strict_types=1);

/**
 * Play → Arcade: score runs with server-measured durations, plausibility checks and rate limits,
 * the points ledger shared with Quiz and Teams, the daily word puzzle and async chess.
 */
final class Arcade {
    /** id => [name, points per score unit divisor, max score per second, absolute cap] */
    const GAMES = [
        'blocks'   => ['name' => 'Stackfall',   'div' => 400, 'rate' => 400, 'cap' => 5000000],
        'snake'    => ['name' => 'Neon Snake',  'div' => 20,  'rate' => 40,  'cap' => 200000],
        'merge'    => ['name' => '2048 Merge',  'div' => 400, 'rate' => 3000,'cap' => 4000000],
        'breakout' => ['name' => 'Brickbreak',  'div' => 100, 'rate' => 200, 'cap' => 2000000],
        'word'     => ['name' => 'Daily Five',  'div' => 0,   'rate' => 0,   'cap' => 700],
        'chess'    => ['name' => 'Chess',       'div' => 0,   'rate' => 0,   'cap' => 0],
        'quiz'     => ['name' => 'Quiz',        'div' => 0,   'rate' => 0,   'cap' => 0],
    ];
    const RUN_GAMES = ['blocks', 'snake', 'merge', 'breakout'];
    const POINTS_CAP = 60;

    public static function query(string $sql, array $params = []): PDOStatement {
        $s = \Vaultcast\Database::connection()->prepare($sql); $s->execute($params); return $s;
    }
    public static function person(?array $user): array {
        if (!$user) return ['id' => '', 'name' => 'Former player', 'avatar_url' => null];
        return ['id' => (string)$user['id'], 'name' => (string)(($user['display_name'] ?? '') ?: $user['username']), 'username' => (string)$user['username'], 'avatar_url' => Profiles::avatarUrl($user)];
    }
    /** Public cards for many users in one query (missing users become "Former player"). */
    public static function people(array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), function ($v) { return $v !== '' && strpos($v, 'anon:') !== 0; })));
        if (!$ids) return [];
        $found = [];
        foreach (array_chunk($ids, 200) as $part)
            foreach (\Vaultcast\Repository::rows('SELECT payload FROM vaultcast_users WHERE id IN (' . implode(',', array_fill(0, count($part), '?')) . ')', $part) as $u) if (isset($u['id'])) $found[(string)$u['id']] = $u;
        $out = []; foreach ($ids as $id) $out[$id] = self::person($found[$id] ?? null);
        return $out;
    }
    public static function signed(array $user): array {
        if (($user['role'] ?? 'guest') === 'guest' || ($user['id'] ?? 'public') === 'public') throw new DomainException('Sign in to keep scores and points.');
        return $user;
    }

    /** Add points to the shared ledger; the user's current team also earns them. */
    public static function award(string $uid, string $source, int $points, string $label): void {
        $team = Social::teamOf($uid);
        self::query('INSERT INTO vaultcast_play_points (user_id,team_id,source,points,label,created_at) VALUES (?,?,?,?,?,?)',
            [$uid, $team ? $team['id'] : '', substr($source, 0, 24), $points, mb_substr($label, 0, 200), time()]);
    }

    private static function limit(string $key, int $seconds, int $daily): void {
        $now = time(); $state = \Vaultcast\Repository::cacheGet('play-limit:' . $key) ?: ['last' => 0, 'day' => date('Y-m-d'), 'count' => 0];
        if ($state['day'] !== date('Y-m-d')) $state = ['last' => 0, 'day' => date('Y-m-d'), 'count' => 0];
        if ($now - (int)$state['last'] < $seconds) throw new DomainException('Slow down a moment and try again.');
        if ((int)$state['count'] >= $daily) throw new DomainException('Daily limit reached for this game. Come back tomorrow.');
        $state['last'] = $now; $state['count']++;
        \Vaultcast\Repository::cachePut('play-limit:' . $key, $state, 90000);
    }

    /** Begin a timed run; the score is checked against the time the server saw. */
    public static function start(array $user, string $game): array {
        self::signed($user);
        if (!in_array($game, self::RUN_GAMES, true)) throw new DomainException('Unknown game.');
        self::limit('start:' . $user['id'], 2, 600);
        $run = bin2hex(random_bytes(16));
        \Vaultcast\Repository::cachePut('play-run:' . $run, ['user' => $user['id'], 'game' => $game, 'at' => microtime(true)], 6 * 3600);
        return ['ok' => true, 'run' => $run, 'best' => self::best((string)$user['id'], $game)];
    }

    public static function best(string $uid, string $game): int {
        return (int)self::query('SELECT COALESCE(MAX(score),0) FROM vaultcast_play_scores WHERE user_id=? AND game=?', [$uid, $game])->fetchColumn();
    }

    public static function submit(array $user, array $body): array {
        self::signed($user);
        $runId = (string)($body['run'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/D', $runId)) throw new DomainException('Start a game before submitting a score.');
        $run = \Vaultcast\Repository::cacheGet('play-run:' . $runId);
        if (!$run || $run['user'] !== $user['id']) throw new DomainException('This game session has expired.');
        // Claim the run atomically so two parallel submissions cannot both count.
        $claims = STREAM_SHM . '/play-runs'; if (!is_dir($claims)) @mkdir($claims, 0775, true);
        $claim = @fopen($claims . '/' . $runId, 'x');
        if (!$claim) throw new DomainException('This game session has expired.');
        fclose($claim);
        if (random_int(1, 50) === 1) foreach ((array)glob($claims . '/*') as $old) if (is_file($old) && filemtime($old) < time() - 7 * 3600) @unlink($old);
        \Vaultcast\Repository::cachePut('play-run:' . $runId, ['used' => true, 'user' => '', 'game' => ''], 60);
        $game = (string)$run['game']; $cfg = self::GAMES[$game];
        $score = (int)($body['score'] ?? -1);
        $seconds = max(0.0, microtime(true) - (float)$run['at']);
        $claimed = max(0, (int)($body['duration_ms'] ?? 0)) / 1000;
        if ($score < 0 || $score > $cfg['cap']) throw new DomainException('That score is not possible.');
        if ($seconds < 3 && $score > 0) throw new DomainException('That game was too short to count.');
        // Paused time may make the client claim less than the server saw, never more.
        if ($claimed > $seconds + 5) throw new DomainException('Game time did not match.');
        if ($score > (int)ceil($seconds * $cfg['rate']) + 1000) throw new DomainException('That score is not plausible for the time played.');
        self::limit('score:' . $user['id'] . ':' . $game, 3, 400);
        $previous = self::best((string)$user['id'], $game);
        $meta = mb_substr(preg_replace('/[^\w:,. -]/u', '', (string)($body['meta'] ?? '')), 0, 200);
        self::query('INSERT INTO vaultcast_play_scores (user_id,game,score,duration_ms,meta,created_at) VALUES (?,?,?,?,?,?)', [$user['id'], $game, $score, (int)round(min($claimed ?: $seconds, $seconds) * 1000), $meta, time()]);
        $points = min(self::POINTS_CAP, $cfg['div'] ? intdiv($score, $cfg['div']) : 0);
        $pb = $score > $previous && $score > 0;
        if ($pb) $points += 10;
        if ($points > 0) self::award((string)$user['id'], $game, $points, ($pb ? 'set a personal best of ' : 'scored ') . number_format($score) . ' in ' . $cfg['name']);
        return ['ok' => true, 'score' => $score, 'best' => max($score, $previous), 'personal_best' => $pb, 'points' => $points, 'rank' => self::rankOf((string)$user['id'], $game)];
    }

    public static function rankOf(string $uid, string $game): ?int {
        $best = self::best($uid, $game); if ($best <= 0) return null;
        $since = Social::resetSince($game);
        return 1 + (int)self::query('SELECT COUNT(*) FROM (SELECT user_id FROM vaultcast_play_scores WHERE game=? AND created_at>=? GROUP BY user_id HAVING MAX(score)>?) better', [$game, $since, $best])->fetchColumn();
    }

    // ---------------------------------------------------------------- Daily Five (word puzzle)
    const WORDS = 'about above actor adapt adult after again agent agree ahead alarm album alert alien align alive alley allow alone along alter amber amuse angel anger angle angry apple apply apron arena argue arise armor aroma arrow aside asset audio audit avoid awake award aware bacon badge baker basic basin batch beach beard beast begin being bench berry birth black blade blame blank blast blaze blend bless blind block bloom blown board boost booth bound brain brand brave bread break brick bride brief bring broad brown brush build bunch burst buyer cabin cable camel candy canoe cargo carry catch cause chain chair chalk charm chart chase cheap check cheer chess chest chief child chill choir civic claim class clean clear clerk click cliff climb clock close cloth cloud coach coast cocoa coral couch count court cover craft crane crash cream crisp crowd crown crumb crust curve cycle daily dance dealt death delta dense depot depth diary dizzy dough draft drain drama dream dress drift drink drive eager eagle early earth eight elbow elder elite empty enjoy enter entry equal error essay event every exact exist extra fable faint fairy faith false fancy feast fence ferry fever fiber field fifty fight final flame flash fleet flock flood floor flour fluid flute focus force forge forum found frame fresh front frost fruit fudge funny gauge ghost giant given glass gleam globe glove grace grade grain grand grape graph grass great green greet grill guard guess guest guide habit happy harsh haven heart heavy hedge hello hinge hobby honey honor horse hotel house human humor ideal image index inner input issue ivory jelly jewel joint judge juice karma kayak knife knock label laser later laugh layer learn lemon level light limit linen liver local lodge logic loose lucky lunar lunch magic major maker mango maple march marsh match mayor medal melon mercy merit metal meter midst minor mirth mixer model money month moral motor mount mouse mouth movie music naval nerve never night noble noise north novel nurse oasis ocean offer often olive onion opera orbit order organ other otter outer owner oxide paint panel paper party pasta patch pause peace peach pearl pedal penny phone photo piano pilot pitch pixel pizza place plain plane plant plate plaza point polar porch pound power press price pride prime print prize proof proud pulse punch puppy quest quick quiet quilt quota radar radio rainy rally ranch range rapid raven reach react ready realm rebel relax reply rhyme rider ridge rifle right rival river roast robin robot rocky rough round route royal rugby ruler rural salad sauce scale scarf scene scent scope score scout sense serve seven shade shake shape share shark sharp sheep shelf shell shift shine shirt shock shore short shout sight skill skirt sleep slice slide slope small smart smile smoke snack snake solar solid sound south space spark speak spice spine spoon sport spray squad stack staff stage stair stamp stand start state steam steel stick still stone storm story stove straw strip study style sugar suite sunny super swamp sweet swift table taste teach thank theme thick thing think three throw thumb tiger timer title toast topic torch total tough tower track trade trail train treat trend trial tribe trick truck trunk trust truth tulip tutor twist ultra uncle under union unity upper urban usage usual valid value vapor vault venue verse video vigor vinyl viola visit vital vivid vocal voice wagon waltz waste watch water whale wheat wheel while white whole world worry worth woven wrist write yacht yearn yield young youth zebra';

    private static function words(): array { return explode(' ', self::WORDS); }
    private static function today(): string { return date('Y-m-d'); }
    private static function answer(string $date): string {
        $list = self::words();
        $day = (int)floor((strtotime($date . ' 12:00:00') - strtotime('2026-01-01 12:00:00')) / 86400);
        // Fixed shuffle: the same date gives everyone the same word.
        usort($list, function ($a, $b) { return crc32('vc5' . $a) <=> crc32('vc5' . $b); });
        return $list[(($day % count($list)) + count($list)) % count($list)];
    }
    public static function marks(string $guess, string $answer): string {
        $marks = array_fill(0, 5, 'x'); $left = [];
        for ($i = 0; $i < 5; $i++) { if ($guess[$i] === $answer[$i]) $marks[$i] = 'g'; else $left[$answer[$i]] = ($left[$answer[$i]] ?? 0) + 1; }
        for ($i = 0; $i < 5; $i++) { if ($marks[$i] === 'g') continue; if (!empty($left[$guess[$i]])) { $marks[$i] = 'y'; $left[$guess[$i]]--; } }
        return implode('', $marks);
    }
    public static function word(array $user): array {
        self::signed($user);
        $doc = \Vaultcast\Repository::document('play-word:' . $user['id'], []);
        $date = self::today();
        $rows = ($doc['date'] ?? '') === $date ? (array)($doc['guesses'] ?? []) : [];
        $done = !empty($rows) && (end($rows)['marks'] === 'ggggg' || count($rows) >= 6);
        $won = !empty($rows) && end($rows)['marks'] === 'ggggg';
        $number = (int)floor((strtotime($date . ' 12:00:00') - strtotime('2026-01-01 12:00:00')) / 86400) + 1;
        return ['ok' => true, 'date' => $date, 'number' => $number, 'guesses' => $rows, 'done' => $done, 'won' => $won,
            'answer' => $done ? self::answer($date) : null, 'streak' => self::wordStreak($doc), 'history' => array_slice((array)($doc['history'] ?? []), -30, null, true)];
    }
    private static function wordStreak(array $doc): int {
        $hist = (array)($doc['history'] ?? []); $n = 0; $day = strtotime(self::today() . ' 12:00:00');
        if (empty($hist[date('Y-m-d', $day)]['won'])) $day -= 86400;
        while (!empty($hist[date('Y-m-d', $day)]['won'])) { $n++; $day -= 86400; }
        return $n;
    }
    public static function guess(array $user, string $guess): array {
        self::signed($user);
        $guess = strtolower(trim($guess));
        if (!preg_match('/^[a-z]{5}$/D', $guess)) throw new DomainException('Use a five-letter word.');
        if (!in_array($guess, self::words(), true) && !preg_match('/[aeiouy]/', $guess)) throw new DomainException('Not in the word list.');
        $date = self::today(); $answer = self::answer($date); $finish = null;
        HomeHub::mutate('play-word:' . $user['id'], function (array $doc) use ($date, $guess, $answer, &$finish): array {
            if (($doc['date'] ?? '') !== $date) { $doc['date'] = $date; $doc['guesses'] = []; }
            $rows = (array)$doc['guesses'];
            if ($rows && (end($rows)['marks'] === 'ggggg' || count($rows) >= 6)) throw new DomainException('Today\'s puzzle is finished. A new one arrives tomorrow.');
            foreach ($rows as $r) if ($r['word'] === $guess) throw new DomainException('You already tried that word.');
            $marks = self::marks($guess, $answer);
            $rows[] = ['word' => $guess, 'marks' => $marks];
            $doc['guesses'] = $rows;
            if ($marks === 'ggggg' || count($rows) >= 6) {
                $doc['history'][$date] = ['won' => $marks === 'ggggg', 'guesses' => count($rows)];
                $doc['history'] = array_slice((array)$doc['history'], -400, null, true);
                $finish = ['won' => $marks === 'ggggg', 'guesses' => count($rows)];
            }
            return $doc;
        });
        if ($finish) {
            $score = $finish['won'] ? (7 - $finish['guesses']) * 100 : 0;
            self::query('INSERT INTO vaultcast_play_scores (user_id,game,score,duration_ms,meta,created_at) VALUES (?,?,?,?,?,?)', [$user['id'], 'word', $score, 0, 'guesses:' . $finish['guesses'], time()]);
            if ($finish['won']) self::award((string)$user['id'], 'word', 5 + 5 * (7 - $finish['guesses']), 'solved Daily Five in ' . $finish['guesses'] . ($finish['guesses'] === 1 ? ' guess' : ' guesses'));
        }
        return self::word($user);
    }

    // ---------------------------------------------------------------- Chess (async between users)
    private static function chessDoc(string $id): array {
        if (!preg_match('/^[a-f0-9]{16}$/D', $id)) throw new DomainException('Game not found.');
        $g = \Vaultcast\Repository::document('play-chess:' . $id, null);
        if (!is_array($g)) throw new DomainException('Game not found.');
        return $g;
    }
    private static function chessPublic(array $g, string $uid, ?array $people = null, bool $legal = true): array {
        // Older documents may lack newer fields; never send null where the client expects text.
        $g['white'] = (string)($g['white'] ?? ''); $g['black'] = (string)($g['black'] ?? '');
        foreach (['winner', 'reason', 'draw_offer', 'status'] as $k) $g[$k] = (string)($g[$k] ?? ($k === 'status' ? 'active' : ''));
        $g['moves'] = array_values(array_map('strval', (array)($g['moves'] ?? [])));
        $g['updated_at'] = (int)($g['updated_at'] ?? 0); $g['created_at'] = (int)($g['created_at'] ?? 0);
        $s = Chess::parse((string)($g['fen'] ?? Chess::START));
        $g['colour'] = $g['white'] === $uid ? 'w' : ($g['black'] === $uid ? 'b' : '');
        $g['turn'] = $s['turn'];
        $g['your_turn'] = $g['status'] === 'active' && $g['colour'] === $s['turn'];
        if ($legal) {
            $g['legal'] = $g['your_turn'] ? Chess::legal($s) : [];
            $san = array_values(array_map('strval', (array)($g['san'] ?? [])));
            $g['san'] = count($san) === count($g['moves']) ? $san : Chess::sanList($g['moves']);
        } elseif (!isset($g['san'])) $g['san'] = [];
        $g['last_move'] = $g['moves'] ? end($g['moves']) : '';
        $g['check'] = $g['status'] === 'active' && Chess::inCheck($s, $s['turn']);
        $g['players'] = (object)($people !== null ? [$g['white'] => $people[$g['white']] ?? self::person(null), $g['black'] => $people[$g['black']] ?? self::person(null)] : self::people([$g['white'], $g['black']]));
        return $g;
    }
    public static function chessList(array $user): array {
        self::signed($user);
        $ids = (array)\Vaultcast\Repository::document('play-chess-index:' . $user['id'], []);
        $games = []; $ids = array_values(array_filter(array_slice(array_reverse($ids), 0, 40), function ($id) { return is_string($id) && preg_match('/^[a-f0-9]{16}$/D', $id); }));
        if (!$ids) return ['ok' => true, 'games' => []];
        // One query for the games and one for everyone in them, instead of one per game and two per player.
        $docs = [];
        foreach (self::query('SELECT name, payload FROM vaultcast_documents WHERE name IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_map(function ($id) { return 'play-chess:' . $id; }, $ids))->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $g = json_decode((string)$row['payload'], true); if (is_array($g) && !empty($g['white'])) $docs[] = $g;
        }
        $uids = []; foreach ($docs as $g) { $uids[] = $g['white']; $uids[] = $g['black']; }
        $people = self::people($uids);
        foreach ($docs as $g) $games[] = self::chessPublic($g, (string)$user['id'], $people, false);
        usort($games, function ($a, $b) { return [$b['your_turn'], $b['updated_at']] <=> [$a['your_turn'], $a['updated_at']]; });
        return ['ok' => true, 'games' => $games];
    }
    public static function chessGet(array $user, string $id): array {
        self::signed($user); $g = self::chessDoc($id);
        if ((string)$g['white'] !== (string)$user['id'] && (string)$g['black'] !== (string)$user['id']) throw new DomainException('This game belongs to other players.');
        return ['ok' => true, 'game' => self::chessPublic($g, (string)$user['id'])];
    }
    public static function chessNew(array $user, array $body): array {
        self::signed($user);
        $other = \Vaultcast\Repository::user((string)($body['opponent'] ?? ''), true);
        if (!$other || $other['id'] === $user['id'] || !Access::state($other)['active']) throw new DomainException('Choose another player on this server.');
        if (Social::blockedEither((string)$user['id'], (string)$other['id'])) throw new DomainException('You cannot challenge this player.');
        self::limit('chess-new:' . $user['id'], 5, 40);
        $me = (string)$user['id']; $them = (string)$other['id'];
        $choice = (string)($body['colour'] ?? (!empty($body['play_black']) ? 'black' : 'white'));
        if (!in_array($choice, ['white', 'black', 'random'], true)) $choice = 'white';
        // Coin toss: decided here on the server, once, when the game is created.
        $coin = $choice === 'random';
        if ($coin) $choice = random_int(0, 1) === 1 ? 'black' : 'white';
        $id = bin2hex(random_bytes(8)); $white = $choice === 'black' ? $them : $me;
        $g = ['id' => $id, 'white' => $white, 'black' => $white === $me ? $them : $me, 'fen' => Chess::START, 'moves' => [], 'san' => [], 'coin_toss' => $coin,
            'status' => 'active', 'winner' => '', 'reason' => '', 'draw_offer' => '', 'created_by' => $user['id'], 'created_at' => time(), 'updated_at' => time()];
        \Vaultcast\Repository::putDocument('play-chess:' . $id, $g);
        foreach ([$user['id'], $other['id']] as $uid) HomeHub::mutate('play-chess-index:' . $uid, function (array $ids) use ($id): array { $ids[] = $id; return array_slice(array_values($ids), -200); });
        Social::notify($them, $user, 'chess:' . $id, 'challenged you to chess', ($coin ? 'Coin toss: you\'re ' . ($white === $them ? 'White. ' : 'Black. ') : '') . 'Your move' . ($white === $them ? '' : ' after theirs'), 'play/arcade/chess/' . $id);
        return ['ok' => true, 'game' => self::chessPublic($g, $me), 'coin_toss' => $coin, 'colour' => $white === $me ? 'w' : 'b'];
    }
    public static function chessAction(array $user, string $id, string $action, array $body): array {
        self::signed($user); $uid = (string)$user['id']; $done = null;
        $g = HomeHub::mutate('play-chess:' . $id, function (array $g) use ($uid, $action, $body, &$done): array {
            if (!$g || ((string)($g['white'] ?? '') !== $uid && (string)($g['black'] ?? '') !== $uid)) throw new DomainException('Game not found.');
            if (($g['status'] ?? 'active') !== 'active') throw new DomainException('This game has finished.');
            $g['white'] = (string)$g['white']; $g['black'] = (string)$g['black'];
            $me = $g['white'] === $uid ? 'w' : 'b'; $other = $me === 'w' ? $g['black'] : $g['white'];
            if ($action === 'move') {
                $s = Chess::parse($g['fen']);
                if ($s['turn'] !== $me) throw new DomainException('Wait for your opponent\'s move.');
                $u = Chess::normalise($s, (string)($body['move'] ?? ''));
                $prior = array_values((array)($g['moves'] ?? []));
                $san = array_values((array)($g['san'] ?? []));
                if (count($san) !== count($prior)) $san = Chess::sanList($prior);
                $san[] = Chess::san($s, $u);
                $s = Chess::play($s, $u);
                $g['fen'] = Chess::fen($s); $prior[] = $u; $g['moves'] = $prior; $g['san'] = $san; $g['draw_offer'] = '';
                $end = Chess::outcome($s);
                if ($end === 'checkmate') { $g['status'] = 'finished'; $g['winner'] = $uid; $g['reason'] = 'checkmate'; }
                elseif ($end !== '') { $g['status'] = 'finished'; $g['winner'] = ''; $g['reason'] = $end; }
            } elseif ($action === 'resign') { $g['status'] = 'finished'; $g['winner'] = $other; $g['reason'] = 'resignation'; }
            elseif ($action === 'draw') {
                if ($g['draw_offer'] === $other) { $g['status'] = 'finished'; $g['winner'] = ''; $g['reason'] = 'agreement'; }
                else $g['draw_offer'] = $uid;
            } else throw new DomainException('Unknown action.');
            $g['updated_at'] = time();
            $done = ['other' => $other, 'finished' => $g['status'] !== 'active', 'winner' => $g['winner'], 'reason' => $g['reason'], 'move' => count($g['moves'])];
            return $g;
        });
        $note = $done['finished'] ? ($done['winner'] === '' ? 'Game drawn (' . $done['reason'] . ')' : ($done['winner'] === $uid ? 'You lost by ' . $done['reason'] : 'You won by ' . $done['reason'])) : ($action === 'draw' ? 'offers a draw' : 'Your move (move ' . $done['move'] . ')');
        Social::notify((string)$done['other'], $user, 'chess:' . $id, 'played chess with you', $note, 'play/arcade/chess/' . $id);
        if ($done['finished']) {
            $p = self::people([$g['white'], $g['black']]);
            if ($done['winner'] !== '') {
                $loser = $done['winner'] === $g['white'] ? $g['black'] : $g['white'];
                self::award($done['winner'], 'chess', 30, 'won at chess against ' . ($p[$loser]['name'] ?? 'a friend'));
                self::query('INSERT INTO vaultcast_play_scores (user_id,game,score,duration_ms,meta,created_at) VALUES (?,?,?,?,?,?)', [$done['winner'], 'chess', 1, 0, 'win', time()]);
            } else foreach ([$g['white'], $g['black']] as $who) self::award($who, 'chess', 10, 'drew a chess game');
        }
        return ['ok' => true, 'game' => self::chessPublic($g, $uid)];
    }
    /** Local games against the built-in engine run in the browser: small, rate-limited reward. */
    public static function chessAi(array $user, array $body): array {
        self::signed($user);
        $result = (string)($body['result'] ?? ''); $level = max(1, min(3, (int)($body['level'] ?? 1)));
        if (!in_array($result, ['win', 'draw', 'loss'], true)) throw new DomainException('Unknown result.');
        $moves = (int)($body['moves'] ?? 0);
        if ($result === 'win' && $moves < 4) throw new DomainException('That game was too short to count.');
        self::limit('chess-ai:' . $user['id'], 60, 30);
        $pts = $result === 'win' ? 4 * $level : ($result === 'draw' ? 2 : 0);
        if ($pts) self::award((string)$user['id'], 'chess', $pts, ($result === 'win' ? 'beat' : 'drew with') . ' the level ' . $level . ' chess computer');
        return ['ok' => true, 'points' => $pts];
    }
}
