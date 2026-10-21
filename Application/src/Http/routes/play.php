<?php
declare(strict_types=1);
// Play → Arcade, Quiz and Teams & ranks. The games area gate (AreaAccess) already applies to /play/.
header('Cache-Control: no-store');
$viewer = current_or_guest();
try {
    // Quiz rooms accept signed-in players and, when guest browsing is on, guests with a name.
    if (preg_match('#^/play/quiz/rooms/([A-Za-z]{4})$#', $path, $m) && $method === 'GET') json_out(Quiz::state($viewer, $m[1], $_GET));
    if (preg_match('#^/play/quiz/rooms/([A-Za-z]{4})/join$#', $path, $m) && $method === 'POST') json_out(Quiz::join($viewer, $m[1], body_json()));
    if (preg_match('#^/play/quiz/rooms/([A-Za-z]{4})/answer$#', $path, $m) && $method === 'POST') json_out(Quiz::answer($viewer, $m[1], body_json()));
    if (preg_match('#^/play/quiz/rooms/([A-Za-z]{4})/host$#', $path, $m) && $method === 'POST') json_out(Quiz::host($viewer, $m[1], body_json()));
    if (preg_match('#^/play/quiz/rooms/([A-Za-z]{4})/image/(\d{1,3})$#', $path, $m) && $method === 'GET') Quiz::roomImage($viewer, $m[1], (int)$m[2]);
    if ($path === '/play/quiz/options' && $method === 'GET') json_out(Quiz::options($viewer));
    if ($path === '/play/quiz/practice' && $method === 'GET') json_out(Quiz::practice($viewer, $_GET));
    if ($path === '/play/leaderboard' && $method === 'GET') json_out(Social::leaderboard($viewer, $_GET));
    if ($path === '/play/games' && $method === 'GET') json_out(['ok' => true, 'games' => Arcade::GAMES, 'run_games' => Arcade::RUN_GAMES]);

    $user = Auth::requireUser();
    if ($path === '/play/quiz/rooms' && $method === 'POST') json_out(Quiz::create($user, body_json()));
    if ($path === '/play/quiz/daily' && $method === 'GET') json_out(Quiz::daily($user));
    if ($path === '/play/quiz/daily/answer' && $method === 'POST') json_out(Quiz::dailyAnswer($user, body_json()));
    if (preg_match('#^/play/quiz/daily/image/(\d{1,2})$#', $path, $m) && $method === 'GET') Quiz::dailyImage($user, (int)$m[1]);

    if ($path === '/play/arcade/summary' && $method === 'GET') {
        $bests = []; foreach (array_merge(Arcade::RUN_GAMES, ['word']) as $g) $bests[$g] = Arcade::best((string)$user['id'], $g);
        json_out(['ok' => true, 'bests' => $bests, 'games' => Arcade::GAMES]);
    }
    if ($path === '/play/arcade/start' && $method === 'POST') json_out(Arcade::start($user, (string)(body_json()['game'] ?? '')));
    if ($path === '/play/arcade/score' && $method === 'POST') json_out(Arcade::submit($user, body_json()));
    if ($path === '/play/word' && $method === 'GET') json_out(Arcade::word($user));
    if ($path === '/play/word' && $method === 'POST') json_out(Arcade::guess($user, (string)(body_json()['guess'] ?? '')));
    if ($path === '/play/chess' && $method === 'GET') json_out(Arcade::chessList($user));
    if ($path === '/play/chess' && $method === 'POST') json_out(Arcade::chessNew($user, body_json()));
    if ($path === '/play/chess/ai' && $method === 'POST') json_out(Arcade::chessAi($user, body_json()));
    if (preg_match('#^/play/chess/([a-f0-9]{16})$#', $path, $m) && $method === 'GET') json_out(Arcade::chessGet($user, $m[1]));
    if (preg_match('#^/play/chess/([a-f0-9]{16})/(move|resign|draw)$#', $path, $m) && $method === 'POST') json_out(Arcade::chessAction($user, $m[1], $m[2], body_json()));

    if ($path === '/play/teams' && $method === 'GET') json_out(Social::teamsState($user));
    if ($path === '/play/teams' && $method === 'POST') json_out(Social::teamAction($user, body_json()));
    if ($path === '/play/friends' && $method === 'GET') json_out(Social::friendsState($user));
    if ($path === '/play/friends' && $method === 'POST') {
        $b = body_json(); $action = (string)($b['action'] ?? '');
        if ($action === 'decline') $b['action'] = 'remove';
        if (($action === 'request' || $action === 'accept') && Social::blockedEither((string)$user['id'], (string)($b['user_id'] ?? ''))) throw new DomainException('You cannot add this player.');
        $r = HomeHub::friendship($user, $b);
        if ($action === 'request') { $u = \Vaultcast\Repository::user((string)($b['user_id'] ?? ''), true); if ($u) Social::notify((string)$u['id'], $user, 'friend', 'sent you a friend request', 'Friend request', 'play/social/friends'); }
        if ($action === 'accept') Social::notify((string)($b['user_id'] ?? ''), $user, 'friend', 'accepted your friend request', 'You are now friends', 'play/social/friends');
        json_out(Social::friendsState($user));
    }
    if ($path === '/play/privacy' && $method === 'GET') json_out(Social::privacyState($user));
    if ($path === '/play/privacy' && $method === 'POST') json_out(Social::savePrivacy($user, body_json()));
    if ($path === '/play/people' && $method === 'GET') json_out(HomeHub::people($user, (string)($_GET['q'] ?? '')));
    if ($path === '/play/challenge' && $method === 'POST') json_out(Social::challenge($user, body_json()));
    if ($path === '/play/activity' && $method === 'GET') json_out(Social::activity($user));
    if ($path === '/play/profile' && $method === 'GET') json_out(Social::profile($user, (string)($_GET['id'] ?? '')));

    if (strpos($path, '/play/admin/') === 0) {
        Auth::requireAdmin();
        if ($path === '/play/admin/reset' && $method === 'POST') json_out(Social::reset(body_json()));
        if ($path === '/play/admin/team' && $method === 'POST') json_out(Social::moderate($user, body_json()));
        if ($path === '/play/admin/settings' && $method === 'GET') json_out(['ok' => true, 'play' => (array)(Store::settings()['play'] ?? []), 'resets' => \Vaultcast\Repository::document('play-resets', [])]);
        if ($path === '/play/admin/settings' && $method === 'POST') {
            $b = body_json(); $all = Store::settings();
            $all['play'] = ['banned_words' => mb_substr((string)($b['banned_words'] ?? ''), 0, 2000), 'opentdb' => !empty($b['opentdb'])];
            Store::saveSettings($all);
            json_out(['ok' => true, 'play' => $all['play']]);
        }
    }
} catch (DomainException $e) { json_error($e->getMessage(), 422); }
json_error('Play endpoint not found.', 404);
