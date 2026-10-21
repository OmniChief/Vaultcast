<?php
declare(strict_types=1);

/**
 * Play → Teams & ranks: teams with captains, invites and colours; leaderboards over the shared
 * points ledger and score table; profiles with achievements; activity; friend challenges.
 * Friends reuse the Home hub friend list, notifications reuse the Home inbox.
 */
final class Social {
    const DOC = 'play-teams';
    const COLOURS = ['#5df4c1', '#59e9ff', '#a7a0ff', '#ff7cc4', '#f5dc7a', '#ff9f6b', '#7ee2a8', '#f2a097'];
    const EMBLEMS = ['🚀', '🦊', '🐉', '⚡', '🎯', '👾', '🦉', '🔥', '🌊', '🍀', '🎲', '👑', '🐺', '🌟', '🛸', '🎸'];

    private static $teams = null;
    public static function teams(): array {
        if (self::$teams === null) self::$teams = (array)(\Vaultcast\Repository::document(self::DOC, [])['teams'] ?? []);
        return self::$teams;
    }
    public static function teamOf(string $uid): ?array {
        foreach (self::teams() as $t) if (isset($t['members'][$uid])) return $t;
        return null;
    }
    private static function change(callable $fn): array {
        $doc = HomeHub::mutate(self::DOC, function (array $doc) use ($fn): array { $doc['teams'] = $fn((array)($doc['teams'] ?? [])); return $doc; });
        self::$teams = (array)$doc['teams'];
        return self::$teams;
    }
    public static function friends(string $uid, string $state = 'friend'): array {
        $f = (array)(\Vaultcast\Repository::document('home-hub:' . $uid, [])['friends'] ?? []);
        return array_keys(array_filter($f, function ($v) use ($state) { return $state === '*' || $v === $state; }));
    }

    // ---------------------------------------------------------------- privacy and blocking
    const PRIVACY_DOC = 'play-privacy';
    private static $privacy = null;
    private static function privacyAll(): array {
        if (self::$privacy === null) self::$privacy = (array)\Vaultcast\Repository::document(self::PRIVACY_DOC, []);
        return self::$privacy;
    }
    public static function privacy(string $uid): array {
        $p = (array)(self::privacyAll()[$uid] ?? []);
        return ['hide' => !empty($p['hide']), 'profile' => in_array($p['profile'] ?? '', ['everyone', 'friends', 'private'], true) ? $p['profile'] : 'everyone', 'blocked' => (array)($p['blocked'] ?? [])];
    }
    /** Users who asked to stay off public leaderboards. */
    public static function hidden(): array {
        $out = []; foreach (self::privacyAll() as $uid => $p) if (!empty($p['hide'])) $out[(string)$uid] = true;
        return $out;
    }
    public static function blockedEither(string $a, string $b): bool {
        if ($a === '' || $b === '') return false;
        return isset(self::privacy($a)['blocked'][$b]) || isset(self::privacy($b)['blocked'][$a]);
    }
    public static function privacyState(array $user): array {
        Arcade::signed($user); $p = self::privacy((string)$user['id']);
        $people = Arcade::people(array_map('strval', array_keys($p['blocked'])));
        return ['ok' => true, 'hide' => $p['hide'], 'profile' => $p['profile'], 'blocked' => array_values(array_filter($people, function ($x) { return $x['id'] !== ''; }))];
    }
    public static function savePrivacy(array $user, array $body): array {
        Arcade::signed($user); $uid = (string)$user['id']; $action = (string)($body['action'] ?? 'save'); $other = (string)($body['user_id'] ?? '');
        if (($action === 'block' || $action === 'unblock') && ($other === '' || $other === $uid || !\Vaultcast\Repository::user($other, true))) throw new DomainException('Choose another player.');
        $doc = HomeHub::mutate(self::PRIVACY_DOC, function (array $d) use ($uid, $action, $body, $other): array {
            $p = (array)($d[$uid] ?? []);
            if ($action === 'save') {
                if (array_key_exists('hide', $body)) $p['hide'] = !empty($body['hide']);
                if (in_array($body['profile'] ?? '', ['everyone', 'friends', 'private'], true)) $p['profile'] = $body['profile'];
            } elseif ($action === 'block') {
                $p['blocked'] = (array)($p['blocked'] ?? []);
                if (count($p['blocked']) >= 500) throw new DomainException('Your block list is full.');
                $p['blocked'][$other] = time();
            } elseif ($action === 'unblock') unset($p['blocked'][$other]);
            else throw new DomainException('Unknown privacy action.');
            $d[$uid] = $p; return $d;
        });
        self::$privacy = $doc;
        if ($action === 'block') {
            // Blocking ends the friendship and any pending request, and withdraws team invites between the two.
            try { HomeHub::friendship($user, ['action' => 'remove', 'user_id' => $other]); } catch (Throwable $e) {}
            self::change(function (array $teams) use ($uid, $other): array { foreach ($teams as $id => $t) { if (isset($t['members'][$uid])) unset($teams[$id]['invites'][$other], $teams[$id]['requests'][$other]); if (isset($t['members'][$other])) unset($teams[$id]['invites'][$uid], $teams[$id]['requests'][$uid]); } return $teams; });
            self::$lbCache = [];
        }
        \Vaultcast\Repository::cachePut('play-lb-gen', ['g' => bin2hex(random_bytes(4))], 86400 * 30);
        return self::privacyState($user);
    }

    /** Inbox notification reusing the Home hub (one per $key, newest replaces older). Blocked senders are dropped. */
    public static function notify(string $to, array $from, string $key, string $verb, string $title, string $path): void {
        if ($to === '' || $to === ($from['id'] ?? '')) return;
        if (self::blockedEither($to, (string)($from['id'] ?? ''))) return;
        $person = Arcade::person($from);
        HomeHub::mutate('home-hub:' . $to, function (array $d) use ($key, $person, $verb, $title, $path): array {
            $nid = 'play:' . $key . ':' . $person['id'];
            unset($d['notifications'][$nid]);
            $d['notifications'][$nid] = ['id' => $nid, 'from' => $person, 'title' => mb_substr($title, 0, 160), 'text' => $person['name'] . ' ' . $verb, 'kind' => 'play', 'action' => 'Open', 'path' => $path, 'created_at' => time()];
            $d['notifications'] = array_slice((array)$d['notifications'], -40, null, true);
            return $d;
        });
    }

    // ---------------------------------------------------------------- teams
    private static function cleanName(string $name): string {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 32) throw new DomainException('Team names use 2–32 characters.');
        if (preg_match('/[<>{}]/', $name)) throw new DomainException('Team names cannot contain < > { }.');
        $banned = array_filter(array_map('trim', explode(',', strtolower((string)(Store::settings()['play']['banned_words'] ?? '')))));
        foreach ($banned as $w) if ($w !== '' && strpos(mb_strtolower($name), $w) !== false) throw new DomainException('Choose a different team name.');
        return $name;
    }
    /** $people and $points come from one batched lookup for every team on the page (see teamsState). */
    private static function publicTeam(array $t, ?string $viewer, array $people, array $points): array {
        $ids = array_map('strval', array_keys((array)$t['members']));
        $card = function ($id) use ($people) { return $people[(string)$id] ?? Arcade::person(null); };
        $member = $viewer !== null && isset($t['members'][$viewer]); $captain = $member && $t['members'][$viewer] === 'captain';
        $out = ['id' => $t['id'], 'name' => $t['name'], 'colour' => $t['colour'], 'emblem' => $t['emblem'], 'motto' => $t['motto'] ?? '', 'created_at' => $t['created_at'], 'points' => (int)($points[$t['id']] ?? 0),
            'members' => array_map(function ($id) use ($t, $card) { return $card($id) + ['role' => $t['members'][$id]]; }, $ids)];
        if ($member) {
            $out['invites'] = array_map(function ($id) use ($card, $t) { return $card($id) + ['invited_at' => $t['invites'][$id]['at'] ?? 0]; }, array_keys((array)($t['invites'] ?? [])));
            $out['wall'] = array_map(function ($w) use ($card, $viewer, $captain) { return ['id' => $w['id'], 'user' => $card($w['uid']), 'text' => $w['text'], 'at' => (int)$w['at'], 'can_delete' => $captain || $w['uid'] === $viewer]; }, array_reverse((array)($t['wall'] ?? [])));
        }
        if ($viewer !== null && isset($t['invites'][$viewer])) $out['invited_by'] = $card($t['invites'][$viewer]['by']);
        if ($viewer !== null && isset($t['requests'][$viewer])) $out['requested'] = true;
        if ($captain) { $out['requests'] = array_map($card, array_keys((array)($t['requests'] ?? []))); $out['link'] = (string)($t['link'] ?? ''); }
        return $out;
    }
    public static function teamsState(array $user): array {
        $uid = (string)$user['id']; $mine = self::teamOf($uid); $invites = []; $all = [];
        $teams = self::teams(); $ids = [];
        foreach ($teams as $t) {
            $ids = array_merge($ids, array_keys((array)$t['members']));
            if (isset($t['members'][$uid])) { $ids = array_merge($ids, array_keys((array)($t['invites'] ?? [])), array_keys((array)($t['requests'] ?? [])), array_column((array)($t['wall'] ?? []), 'uid')); }
            if (isset($t['invites'][$uid])) $ids[] = $t['invites'][$uid]['by'];
        }
        $people = Arcade::people(array_map('strval', $ids)); $points = [];
        foreach (Arcade::query('SELECT team_id, SUM(points) p FROM vaultcast_play_points WHERE team_id<>\'\' AND created_at>=? GROUP BY team_id', [self::resetSince('')]) as $r) $points[$r['team_id']] = (int)$r['p'];
        foreach ($teams as $t) { $pub = self::publicTeam($t, $uid, $people, $points); if (isset($t['invites'][$uid])) $invites[] = $pub; $all[] = $pub; }
        usort($all, function ($a, $b) { return $b['points'] <=> $a['points']; });
        return ['ok' => true, 'team' => $mine ? self::publicTeam($mine, $uid, $people, $points) : null, 'invites' => $invites, 'teams' => $all, 'colours' => self::COLOURS, 'emblems' => self::EMBLEMS];
    }
    public static function teamAction(array $user, array $body): array {
        Arcade::signed($user); $uid = (string)$user['id']; $action = (string)($body['action'] ?? ''); $notify = [];
        $target = (string)($body['team_id'] ?? ''); $other = (string)($body['user_id'] ?? '');
        $teamName = null;
        self::change(function (array $teams) use ($uid, $action, $body, $target, $other, &$notify, &$teamName): array {
            $mineId = null; foreach ($teams as $id => $t) if (isset($t['members'][$uid])) $mineId = $id;
            $captain = $mineId && ($teams[$mineId]['members'][$uid] ?? '') === 'captain';
            switch ($action) {
                case 'create':
                    if ($mineId) throw new DomainException('Leave your current team first.');
                    if (count($teams) >= 200) throw new DomainException('This server has the maximum number of teams.');
                    $name = self::cleanName((string)($body['name'] ?? ''));
                    foreach ($teams as $t) if (mb_strtolower($t['name']) === mb_strtolower($name)) throw new DomainException('That team name is taken.');
                    $id = bin2hex(random_bytes(6));
                    $teams[$id] = ['id' => $id, 'name' => $name, 'colour' => in_array($body['colour'] ?? '', self::COLOURS, true) ? $body['colour'] : self::COLOURS[0],
                        'emblem' => in_array($body['emblem'] ?? '', self::EMBLEMS, true) ? $body['emblem'] : self::EMBLEMS[0], 'motto' => mb_substr(trim((string)($body['motto'] ?? '')), 0, 80),
                        'members' => [$uid => 'captain'], 'invites' => [], 'requests' => [], 'created_at' => time(), 'created_by' => $uid];
                    $teamName = $name; break;
                case 'edit':
                    if (!$captain) throw new DomainException('Only a captain can change the team.');
                    $t = &$teams[$mineId];
                    if (isset($body['name'])) { $name = self::cleanName((string)$body['name']); foreach ($teams as $id => $o) if ($id !== $mineId && mb_strtolower($o['name']) === mb_strtolower($name)) throw new DomainException('That team name is taken.'); $t['name'] = $name; }
                    if (in_array($body['colour'] ?? '', self::COLOURS, true)) $t['colour'] = $body['colour'];
                    if (in_array($body['emblem'] ?? '', self::EMBLEMS, true)) $t['emblem'] = $body['emblem'];
                    if (isset($body['motto'])) $t['motto'] = mb_substr(trim((string)$body['motto']), 0, 80);
                    unset($t); break;
                case 'invite':
                    if (!$mineId) throw new DomainException('Create or join a team first.');
                    $u = \Vaultcast\Repository::user($other, true);
                    if (!$u || $other === $uid) throw new DomainException('Choose another player.');
                    if (self::blockedEither($uid, $other)) throw new DomainException('You cannot invite this player.');
                    foreach ($teams as $t) if (isset($t['members'][$other])) throw new DomainException('That player is already in a team.');
                    if (count($teams[$mineId]['members']) >= 30) throw new DomainException('Teams hold up to 30 players.');
                    $teams[$mineId]['invites'][$other] = ['by' => $uid, 'at' => time()];
                    $notify[] = [$other, 'invited you to join a team', $teams[$mineId]['name']]; break;
                case 'cancel-invite':
                    if (!$mineId) throw new DomainException('You are not in a team.');
                    unset($teams[$mineId]['invites'][$other]); break;
                case 'accept':
                    if ($mineId) throw new DomainException('Leave your current team first.');
                    if (!isset($teams[$target]['invites'][$uid])) throw new DomainException('This invitation is no longer open.');
                    $teams[$target]['members'][$uid] = 'member'; unset($teams[$target]['invites'][$uid], $teams[$target]['requests'][$uid]);
                    foreach ($teams as $id => $t) unset($teams[$id]['invites'][$uid], $teams[$id]['requests'][$uid]);
                    $teamName = $teams[$target]['name'];
                    foreach ($teams[$target]['members'] as $m => $role) if ($role === 'captain') $notify[] = [$m, 'joined your team', $teamName];
                    break;
                case 'decline':
                    unset($teams[$target]['invites'][$uid]); break;
                case 'request':
                    if ($mineId) throw new DomainException('Leave your current team first.');
                    if (!isset($teams[$target])) throw new DomainException('Team not found.');
                    // Only the first request notifies the captains; repeating it does not spam them.
                    if (!isset($teams[$target]['requests'][$uid])) foreach ($teams[$target]['members'] as $m => $role) if ($role === 'captain') $notify[] = [$m, 'asked to join your team', $teams[$target]['name']];
                    $teams[$target]['requests'][$uid] = time();
                    break;
                case 'link': case 'unlink':
                    if (!$captain) throw new DomainException('Only a captain can manage the invite link.');
                    if ($action === 'link') $teams[$mineId]['link'] = bin2hex(random_bytes(10)); else unset($teams[$mineId]['link']);
                    break;
                case 'join-link':
                    $token = (string)($body['token'] ?? ''); $found = null;
                    if (preg_match('/^[a-f0-9]{20}$/D', $token)) foreach ($teams as $id => $t) if (isset($t['link']) && hash_equals((string)$t['link'], $token)) $found = $id;
                    if ($found === null) throw new DomainException('This invite link is no longer valid.');
                    if ($mineId === $found) break;
                    if ($mineId) throw new DomainException('Leave your current team first.');
                    if (count($teams[$found]['members']) >= 30) throw new DomainException('Teams hold up to 30 players.');
                    foreach ($teams[$found]['members'] as $m => $role) if (self::blockedEither($uid, (string)$m)) throw new DomainException('You cannot join this team.');
                    $teams[$found]['members'][$uid] = 'member';
                    foreach ($teams as $id => $t) unset($teams[$id]['invites'][$uid], $teams[$id]['requests'][$uid]);
                    $teamName = $teams[$found]['name'];
                    foreach ($teams[$found]['members'] as $m => $role) if ($role === 'captain') $notify[] = [$m, 'joined your team with the invite link', $teamName];
                    break;
                case 'post':
                    if (!$mineId) throw new DomainException('Join a team first.');
                    $text = trim(preg_replace('/[ \t]+/u', ' ', (string)($body['text'] ?? '')));
                    if ($text === '' || mb_strlen($text) > 280) throw new DomainException('Posts use 1–280 characters.');
                    $wall = (array)($teams[$mineId]['wall'] ?? []); $last = end($wall);
                    if ($last && $last['uid'] === $uid && time() - (int)$last['at'] < 5) throw new DomainException('Slow down a moment and try again.');
                    $wall[] = ['id' => bin2hex(random_bytes(5)), 'uid' => $uid, 'text' => $text, 'at' => time()];
                    $teams[$mineId]['wall'] = array_slice($wall, -40);
                    break;
                case 'unpost':
                    if (!$mineId) throw new DomainException('Join a team first.');
                    $teams[$mineId]['wall'] = array_values(array_filter((array)($teams[$mineId]['wall'] ?? []), function ($w) use ($body, $uid, $captain) { return !($w['id'] === (string)($body['post_id'] ?? '') && ($captain || $w['uid'] === $uid)); }));
                    break;
                case 'approve': case 'reject':
                    if (!$captain) throw new DomainException('Only a captain can answer requests.');
                    if (!isset($teams[$mineId]['requests'][$other])) throw new DomainException('Request not found.');
                    unset($teams[$mineId]['requests'][$other]);
                    if ($action === 'approve') {
                        foreach ($teams as $t) if (isset($t['members'][$other])) throw new DomainException('That player already joined a team.');
                        $teams[$mineId]['members'][$other] = 'member'; $notify[] = [$other, 'accepted you into', $teams[$mineId]['name']];
                    }
                    break;
                case 'promote': case 'demote': case 'remove':
                    if (!$captain) throw new DomainException('Only a captain can manage members.');
                    if (!isset($teams[$mineId]['members'][$other]) || $other === $uid) throw new DomainException('Choose another member.');
                    if ($action === 'remove') unset($teams[$mineId]['members'][$other]); else $teams[$mineId]['members'][$other] = $action === 'promote' ? 'captain' : 'member';
                    break;
                case 'leave':
                    if (!$mineId) throw new DomainException('You are not in a team.');
                    unset($teams[$mineId]['members'][$uid]);
                    if (!$teams[$mineId]['members']) unset($teams[$mineId]);
                    elseif (!in_array('captain', $teams[$mineId]['members'], true)) { $first = array_key_first($teams[$mineId]["members"]); $teams[$mineId]['members'][$first] = 'captain'; }
                    break;
                default: throw new DomainException('Unknown team action.');
            }
            return $teams;
        });
        foreach ($notify as $n) self::notify($n[0], $user, 'team:' . $n[2], $n[1], $n[2], 'play/social/teams');
        if ($teamName !== null) Arcade::award($uid, 'social', 0, ($action === 'create' ? 'founded team ' : 'joined team ') . $teamName);
        return self::teamsState($user);
    }
    public static function moderate(array $admin, array $body): array {
        $id = (string)($body['team_id'] ?? '');
        self::change(function (array $teams) use ($id, $body): array {
            if (!isset($teams[$id])) throw new DomainException('Team not found.');
            if (!empty($body['delete'])) unset($teams[$id]);
            else $teams[$id]['name'] = self::cleanName((string)($body['name'] ?? ''));
            return $teams;
        });
        return ['ok' => true];
    }

    // ---------------------------------------------------------------- leaderboards
    public static function resetSince(string $game): int {
        $r = (array)\Vaultcast\Repository::document('play-resets', []);
        return max((int)($r['all'] ?? 0), $game !== '' ? (int)($r['games'][$game] ?? 0) : 0);
    }
    private static function periodStart(string $period): int {
        if ($period === 'week') return strtotime('monday this week 00:00');
        if ($period === 'month') return strtotime(date('Y-m-01 00:00:00'));
        if ($period === 'day') return strtotime('today');
        return 0;
    }
    public static function leaderboard(array $user, array $q): array {
        $kind = (string)($q['board'] ?? 'points'); $period = in_array($q['period'] ?? '', ['day', 'week', 'month', 'all'], true) ? $q['period'] : 'week';
        $scope = in_array($q['scope'] ?? '', ['friends', 'team'], true) ? $q['scope'] : 'all'; $uid = (string)($user['id'] ?? '');
        if ($scope === 'team') { $mine = self::teamOf($uid); if (!$mine) return ['ok' => true, 'board' => $kind, 'period' => $period, 'scope' => 'team', 'rows' => [], 'note' => 'Join a team to see your team board.']; }
        $since = max(self::periodStart($period), self::resetSince($kind === 'points' || $kind === 'teams' ? '' : $kind));
        $filter = ''; $params = [$since];
        if ($scope !== 'all' && $uid !== '') { $ids = $scope === 'team' ? array_map('strval', array_keys((array)$mine['members'])) : array_merge([$uid], self::friends($uid)); $filter = ' AND user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; $params = array_merge($params, $ids); }
        $cacheKey = 'play-lb:' . self::lbGen() . ':' . $kind . ':' . $period . ':' . $since;
        if ($scope === 'all' && $kind !== 'teams' && $kind !== 'emu-time' && $kind !== 'emu-sessions') {
            // The public board is the same for everyone: aggregate at most every 30 s, then mark the viewer's row.
            $hit = self::$lbCache[$cacheKey] ?? \Vaultcast\Repository::cacheGet($cacheKey);
            if (is_array($hit) && ($hit['at'] ?? 0) > time() - 30) {
                $out = array_map(function ($r) use ($uid) { $r['me'] = $r['user']['id'] === $uid; return $r; }, (array)$hit['rows']);
                return ['ok' => true, 'board' => $kind, 'period' => $period, 'scope' => $scope, 'rows' => $out];
            }
        }
        if ($kind === 'teams') {
            $rows = Arcade::query('SELECT team_id id, SUM(points) value, COUNT(DISTINCT user_id) players FROM vaultcast_play_points WHERE team_id<>\'\' AND created_at>=? GROUP BY team_id ORDER BY value DESC LIMIT 50', [$since])->fetchAll(PDO::FETCH_ASSOC);
            $teams = self::teams(); $out = [];
            foreach ($rows as $r) if (isset($teams[$r['id']])) $out[] = ['team' => ['id' => $r['id'], 'name' => $teams[$r['id']]['name'], 'colour' => $teams[$r['id']]['colour'], 'emblem' => $teams[$r['id']]['emblem']], 'value' => (int)$r['value'], 'players' => (int)$r['players']];
            return ['ok' => true, 'board' => 'teams', 'period' => $period, 'rows' => $out];
        }
        if ($kind === 'emu-time' || $kind === 'emu-sessions') return self::emulatorBoard($uid, $period, $scope === 'all' ? null : ($scope === 'team' ? array_map('strval', array_keys((array)$mine['members'])) : array_merge([$uid], self::friends($uid))), $kind);
        if ($kind === 'points') $sql = 'SELECT user_id, SUM(points) value FROM vaultcast_play_points WHERE created_at>=?' . $filter . ' GROUP BY user_id HAVING value>0 ORDER BY value DESC LIMIT 50';
        else {
            if (!isset(Arcade::GAMES[$kind])) throw new DomainException('Unknown leaderboard.');
            $params[] = $kind;
            // Chess rows are one per win, so the board counts wins; every other game ranks the best score.
            $sql = 'SELECT user_id, ' . ($kind === 'chess' ? 'SUM(score)' : 'MAX(score)') . ' value, COUNT(*) plays FROM vaultcast_play_scores WHERE created_at>=?' . $filter . ' AND game=? GROUP BY user_id HAVING value>0 ORDER BY value DESC LIMIT 50';
        }
        $hidden = self::hidden(); unset($hidden[$uid]);
        if ($hidden) { $sql = str_replace(' GROUP BY user_id', ' AND user_id NOT IN (' . implode(',', array_fill(0, count($hidden), '?')) . ') GROUP BY user_id', $sql); $params = array_merge($params, array_map('strval', array_keys($hidden))); }
        $rows = Arcade::query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
        $people = Arcade::people(array_column($rows, 'user_id')); $out = [];
        foreach ($rows as $i => $r) { $t = self::teamOf((string)$r['user_id']); $out[] = ['rank' => $i + 1, 'user' => $people[$r['user_id']] ?? Arcade::person(null), 'value' => (int)$r['value'], 'plays' => (int)($r['plays'] ?? 0), 'me' => $r['user_id'] === $uid, 'team' => $t ? ['name' => $t['name'], 'colour' => $t['colour'], 'emblem' => $t['emblem']] : null]; }
        if ($scope === 'all' && !isset(self::hidden()[$uid])) { self::$lbCache[$cacheKey] = ['at' => time(), 'rows' => $out]; \Vaultcast\Repository::cachePut($cacheKey, self::$lbCache[$cacheKey], 120); }
        return ['ok' => true, 'board' => $kind, 'period' => $period, 'scope' => $scope, 'rows' => $out];
    }
    private static $lbCache = [];
    /** Bumped when privacy settings change so cached public boards drop hidden players at once. */
    private static function lbGen(): string { return (string)((\Vaultcast\Repository::cacheGet('play-lb-gen') ?: ['g' => '0'])['g']); }

    // ---------------------------------------------------------------- emulator (Play → Games) play stats
    /** Daily roll-up written next to the Home hub's per-game totals whenever the emulator reports play time. */
    public static function logEmulator(string $uid, int $seconds, int $sessions): void {
        try {
            Arcade::query('INSERT INTO vaultcast_play_emulator (user_id,day,seconds,sessions) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE seconds=seconds+VALUES(seconds), sessions=sessions+VALUES(sessions)', [$uid, date('Y-m-d'), max(0, $seconds), max(0, $sessions)]);
        } catch (Throwable $e) {}
    }
    /** All-time totals come from the Home hub game history (complete since play time was first tracked). */
    private static function emulatorTotals(): array {
        $hit = \Vaultcast\Repository::cacheGet('play-emu-totals');
        if (is_array($hit)) return $hit;
        $out = [];
        $s = \Vaultcast\Database::connection()->query("SELECT name, payload FROM vaultcast_documents WHERE name LIKE 'home-hub:%'");
        foreach ($s as $row) {
            $d = json_decode((string)$row['payload'], true); if (!is_array($d) || empty($d['games'])) continue;
            $sec = 0; $ses = 0; foreach ((array)$d['games'] as $g) { $sec += max(0, (int)($g['seconds'] ?? 0)); $ses += max(0, (int)($g['sessions'] ?? 0)); }
            $out[substr((string)$row['name'], 9)] = ['seconds' => $sec, 'sessions' => $ses, 'games' => count((array)$d['games'])];
        }
        \Vaultcast\Repository::cachePut('play-emu-totals', $out, 600);
        return $out;
    }
    private static function emulatorBoard(string $uid, string $period, ?array $only, string $kind): array {
        $field = $kind === 'emu-time' ? 'seconds' : 'sessions'; $since = max(self::periodStart($period), self::resetSince($kind));
        $totals = [];
        if ($period === 'all' && $since === 0) foreach (self::emulatorTotals() as $id => $t) $totals[$id] = $t[$field];
        else foreach (Arcade::query('SELECT user_id, SUM(seconds) seconds, SUM(sessions) sessions FROM vaultcast_play_emulator WHERE day>=? GROUP BY user_id', [date('Y-m-d', $since)]) as $r) $totals[$r['user_id']] = (int)$r[$field];
        if ($only !== null) $totals = array_intersect_key($totals, array_flip($only));
        $hidden = self::hidden(); unset($hidden[$uid]); if ($hidden) $totals = array_diff_key($totals, $hidden);
        $totals = array_filter($totals); arsort($totals); $totals = array_slice($totals, 0, 50, true);
        $people = Arcade::people(array_map('strval', array_keys($totals))); $out = []; $i = 0;
        foreach ($totals as $id => $v) { $id = (string)$id; if (!isset($people[$id]) || $people[$id]['id'] === '') continue; $t = self::teamOf($id); $out[] = ['rank' => ++$i, 'user' => $people[$id], 'value' => (int)$v, 'me' => $id === $uid, 'team' => $t ? ['name' => $t['name'], 'colour' => $t['colour'], 'emblem' => $t['emblem']] : null]; }
        return ['ok' => true, 'board' => $kind, 'period' => $period, 'unit' => $field, 'rows' => $out];
    }
    /** Team board for emulator stats: members' totals added together. */
    public static function emulatorTeams(string $period, string $kind): array {
        $b = self::emulatorBoard('', $period, null, $kind); $teams = [];
        foreach ($b['rows'] as $r) if ($r['team']) { $k = $r['team']['name']; $teams[$k] = ($teams[$k] ?? ['team' => $r['team'], 'value' => 0]); $teams[$k]['value'] += $r['value']; }
        usort($teams, function ($a, $b) { return $b['value'] <=> $a['value']; });
        return array_values($teams);
    }

    // ---------------------------------------------------------------- profile, achievements, activity
    const BADGES = [
        'first-game' => ['First steps', 'Finish your first arcade game', '🎮'],
        'regular' => ['Regular', 'Play 25 games', '🕹️'],
        'devoted' => ['Devoted', 'Play 250 games', '💎'],
        'centurion' => ['Centurion', 'Earn 100 points', '💯'],
        'high-roller' => ['High roller', 'Earn 2,500 points', '🏆'],
        'quiz-champ' => ['Quiz champion', 'Win a quiz night', '🧠'],
        'quiz-legend' => ['Quiz legend', 'Win 10 quiz nights', '🎓'],
        'grandmaster' => ['Checkmate!', 'Win a chess game against a friend', '♛'],
        'wordsmith' => ['Wordsmith', 'Solve Daily Five in three guesses or fewer', '🔤'],
        'streak' => ['On a roll', 'Solve Daily Five seven days in a row', '🔥'],
        'tile-2048' => ['2048!', 'Reach the 2048 tile', '🧩'],
        'line-clearer' => ['Line clearer', 'Score 20,000 in Stackfall', '🧱'],
        'long-snake' => ['Long snake', 'Score 500 in Neon Snake', '🐍'],
        'brick-master' => ['Brick master', 'Score 5,000 in Brickbreak', '🧨'],
        'team-player' => ['Team player', 'Join or found a team', '🤝'],
        'social' => ['Social butterfly', 'Have five friends on the server', '🦋'],
        'all-rounder' => ['All-rounder', 'Score in every arcade game', '🌈'],
    ];
    public static function profile(array $viewer, string $id): array {
        $target = $id === '' || $id === ($viewer['id'] ?? '') ? $viewer : \Vaultcast\Repository::user($id, true);
        if (!$target) throw new DomainException('Player not found.');
        $uid = (string)$target['id']; $vid = (string)($viewer['id'] ?? '');
        if ($uid !== $vid && ($viewer['role'] ?? '') !== 'admin') {
            if (self::blockedEither($uid, $vid)) throw new DomainException('Player not found.');
            $vis = self::privacy($uid)['profile'];
            if ($vis === 'private' || ($vis === 'friends' && !in_array($vid, self::friends($uid), true))) return ['ok' => true, 'person' => Arcade::person($target), 'me' => false, 'friend' => in_array($uid, self::friends($vid), true), 'private' => true, 'stats' => null, 'bests' => [], 'badges' => [], 'team' => null, 'activity' => []];
        }
        $games = Arcade::query('SELECT game, COUNT(*) plays, MAX(score) best, MAX(created_at) last FROM vaultcast_play_scores WHERE user_id=? GROUP BY game', [$uid])->fetchAll(PDO::FETCH_ASSOC);
        $bests = []; $plays = 0;
        foreach ($games as $g) { $plays += (int)$g['plays']; if (isset(Arcade::GAMES[$g['game']])) $bests[$g['game']] = ['name' => Arcade::GAMES[$g['game']]['name'], 'best' => (int)$g['best'], 'plays' => (int)$g['plays'], 'rank' => in_array($g['game'], Arcade::RUN_GAMES, true) ? Arcade::rankOf($uid, $g['game']) : null]; }
        $points = (int)Arcade::query('SELECT COALESCE(SUM(points),0) FROM vaultcast_play_points WHERE user_id=?', [$uid])->fetchColumn();
        $week = (int)Arcade::query('SELECT COALESCE(SUM(points),0) FROM vaultcast_play_points WHERE user_id=? AND created_at>=?', [$uid, self::periodStart('week')])->fetchColumn();
        $since = self::resetSince('');
        $mine = (int)Arcade::query('SELECT COALESCE(SUM(points),0) FROM vaultcast_play_points WHERE user_id=? AND created_at>=?', [$uid, $since])->fetchColumn();
        $rank = $mine > 0 ? 1 + (int)Arcade::query('SELECT COUNT(*) FROM (SELECT user_id FROM vaultcast_play_points WHERE created_at>=? GROUP BY user_id HAVING SUM(points)>?) x', [$since, $mine])->fetchColumn() : null;
        $quizWins = (int)Arcade::query("SELECT COUNT(*) FROM vaultcast_play_scores WHERE user_id=? AND game='quiz' AND meta='win'", [$uid])->fetchColumn();
        $chessWins = (int)Arcade::query("SELECT COUNT(*) FROM vaultcast_play_scores WHERE user_id=? AND game='chess' AND meta='win'", [$uid])->fetchColumn();
        $word = (array)\Vaultcast\Repository::document('play-word:' . $uid, []);
        $wordBest = 7; foreach ((array)($word['history'] ?? []) as $h) if (!empty($h['won'])) $wordBest = min($wordBest, (int)$h['guesses']);
        $streak = 0; $day = strtotime('today 12:00'); $hist = (array)($word['history'] ?? []); if (empty($hist[date('Y-m-d', $day)]['won'])) $day -= 86400;
        while (!empty($hist[date('Y-m-d', $day)]['won'])) { $streak++; $day -= 86400; }
        $metaHas = function (string $game, string $needle) use ($uid): bool { return (bool)Arcade::query('SELECT 1 FROM vaultcast_play_scores WHERE user_id=? AND game=? AND meta LIKE ? LIMIT 1', [$uid, $game, '%' . $needle . '%'])->fetchColumn(); };
        $team = self::teamOf($uid); $friends = self::friends($uid);
        $b = function (string $k) use ($bests) { return (int)($bests[$k]['best'] ?? 0); };
        $earned = ['first-game' => $plays > 0, 'regular' => $plays >= 25, 'devoted' => $plays >= 250, 'centurion' => $points >= 100, 'high-roller' => $points >= 2500,
            'quiz-champ' => $quizWins >= 1, 'quiz-legend' => $quizWins >= 10, 'grandmaster' => $chessWins >= 1, 'wordsmith' => $wordBest <= 3, 'streak' => $streak >= 7,
            'tile-2048' => $metaHas('merge', 'tile:2048') || $metaHas('merge', 'tile:4096') || $metaHas('merge', 'tile:8192'), 'line-clearer' => $b('blocks') >= 20000, 'long-snake' => $b('snake') >= 500, 'brick-master' => $b('breakout') >= 5000,
            'team-player' => $team !== null || (bool)Arcade::query("SELECT 1 FROM vaultcast_play_points WHERE user_id=? AND source='social' LIMIT 1", [$uid])->fetchColumn(),
            'social' => count($friends) >= 5, 'all-rounder' => count(array_intersect(array_keys($bests), Arcade::RUN_GAMES)) === count(Arcade::RUN_GAMES)];
        $badges = []; foreach (self::BADGES as $k => $info) $badges[] = ['id' => $k, 'name' => $info[0], 'detail' => $info[1], 'icon' => $info[2], 'earned' => !empty($earned[$k])];
        $isFriend = in_array($uid, self::friends((string)($viewer['id'] ?? '')), true);
        return ['ok' => true, 'person' => Arcade::person($target), 'me' => $uid === ($viewer['id'] ?? ''), 'friend' => $isFriend,
            'stats' => ['games_played' => $plays, 'points' => $points, 'points_week' => $week, 'rank' => $rank, 'quiz_wins' => $quizWins, 'chess_wins' => $chessWins, 'word_streak' => $streak, 'friends' => count($friends)],
            'bests' => $bests, 'badges' => $badges, 'team' => $team ? ['id' => $team['id'], 'name' => $team['name'], 'colour' => $team['colour'], 'emblem' => $team['emblem'], 'role' => $team['members'][$uid]] : null,
            'activity' => self::activityFor([$uid], 15)];
    }
    private static function activityFor(array $ids, int $limit): array {
        if (!$ids) return [];
        $rows = Arcade::query('SELECT user_id, source, points, label, created_at FROM vaultcast_play_points WHERE user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id DESC LIMIT ' . (int)$limit, $ids)->fetchAll(PDO::FETCH_ASSOC);
        $people = Arcade::people(array_column($rows, 'user_id'));
        return array_map(function ($r) use ($people) { return ['user' => $people[$r['user_id']], 'source' => $r['source'], 'points' => (int)$r['points'], 'label' => $r['label'], 'at' => (int)$r['created_at']]; }, $rows);
    }
    public static function activity(array $user): array {
        $uid = (string)$user['id']; $ids = array_merge([$uid], self::friends($uid));
        $t = self::teamOf($uid); if ($t) $ids = array_merge($ids, array_keys((array)$t['members']));
        return ['ok' => true, 'activity' => self::activityFor(array_values(array_unique($ids)), 40)];
    }
    public static function friendsState(array $user): array {
        $uid = (string)$user['id']; $f = (array)(\Vaultcast\Repository::document('home-hub:' . $uid, [])['friends'] ?? []);
        $ids = array_map('strval', array_keys($f)); $people = Arcade::people($ids); $out = []; $week = [];
        if ($ids) foreach (Arcade::query('SELECT user_id, SUM(points) p FROM vaultcast_play_points WHERE created_at>=? AND user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY user_id', array_merge([self::periodStart('week')], $ids)) as $r) $week[(string)$r['user_id']] = (int)$r['p'];
        foreach ($f as $id => $state) {
            $id = (string)$id; if (!isset($people[$id]) || $people[$id]['id'] === '') continue;
            $t = self::teamOf($id);
            $out[] = $people[$id] + ['state' => $state, 'points_week' => $week[$id] ?? 0, 'team' => $t ? ['name' => $t['name'], 'emblem' => $t['emblem'], 'colour' => $t['colour']] : null];
        }
        usort($out, function ($a, $b) { return [$a['state'] !== 'incoming', -$a['points_week']] <=> [$b['state'] !== 'incoming', -$b['points_week']]; });
        return ['ok' => true, 'friends' => $out];
    }
    public static function challenge(array $user, array $body): array {
        Arcade::signed($user); $uid = (string)$user['id'];
        $to = (string)($body['user_id'] ?? ''); $game = (string)($body['game'] ?? '');
        if (!in_array($to, self::friends($uid), true) || self::blockedEither($uid, $to)) throw new DomainException('Challenge an accepted friend.');
        if (!in_array($game, Arcade::RUN_GAMES, true) && $game !== 'word') throw new DomainException('Choose an arcade game.');
        $best = Arcade::best($uid, $game);
        $name = Arcade::GAMES[$game]['name'];
        self::notify($to, $user, 'challenge:' . $game, 'challenged you', $best > 0 ? 'Beat my ' . number_format($best) . ' in ' . $name : 'Play ' . $name . ' with me', 'play/arcade/' . $game);
        return ['ok' => true];
    }
    public static function reset(array $body): array {
        $game = (string)($body['game'] ?? '');
        if ($game !== '' && !isset(Arcade::GAMES[$game]) && !in_array($game, ['emu-time', 'emu-sessions'], true)) throw new DomainException('Unknown game.');
        HomeHub::mutate('play-resets', function (array $r) use ($game): array { if ($game === '') $r['all'] = time(); else $r['games'][$game] = time(); return $r; });
        return ['ok' => true];
    }
}
