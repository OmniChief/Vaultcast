<?php
declare(strict_types=1);
// Misc → Board, Drop and Ambient.
try {
    // Share links: the per-link setting decides whether a sign-in is needed (Access gate lets these through).
    if (preg_match('#^/drop/s/([a-f0-9]{32})(/download)?$#D', $path, $m) && in_array($method, ['GET', 'HEAD', 'POST'], true)) Drop::share($m[1], $method, !empty($m[2]));
    header('Cache-Control: no-store');
    // Anonymous Drop (admin opt-in, off by default): works without an account, even when guest browsing is off,
    // and reaches nothing but the visitor's own uploads. The Access gate lets /drop/anon through; everything is checked here.
    if (preg_match('#^/drop/anon(/.*)?$#D', $path, $m)) {
        $sub = $m[1] ?? '';
        if ($sub === '' && in_array($method, ['GET', 'HEAD'], true)) Drop::anonPage();
        Drop::requireAnon();
        Drop::ipLimit('req', 900, 600);
        $anon = Drop::anonUser(true);
        if ($sub === '/state' && $method === 'GET') json_out(Drop::state($anon));
        if ($sub === '/upload' && $method === 'POST') { Drop::ipLimit('init', 30, 3600); json_out(Drop::init($anon, body_json())); }
        if (preg_match('#^/upload/([a-f0-9]{32})$#D', $sub, $mm) && $method === 'GET') json_out(Drop::resume($anon, $mm[1]));
        if (preg_match('#^/upload/([a-f0-9]{32})$#D', $sub, $mm) && ($method === 'POST' || $method === 'PUT')) {
            Drop::ipLimit('chunk', 600, 3600);
            $r = Drop::chunk($anon, $mm[1], (int)($_GET['offset'] ?? -1));
            json_out($r, !empty($r['ok']) ? 200 : 409);
        }
        if (preg_match('#^/items/([a-f0-9]{32})$#D', $sub, $mm) && $method === 'DELETE') json_out(Drop::delete($anon, $mm[1]));
        if (preg_match('#^/items/([a-f0-9]{32})/download$#D', $sub, $mm) && in_array($method, ['GET', 'HEAD'], true)) { Drop::ipLimit('dl', 200, 3600); Drop::ownerDownload($anon, $mm[1], false); }
        if (preg_match('#^/items/([a-f0-9]{32})/links$#D', $sub, $mm) && $method === 'POST') { Drop::ipLimit('link', 30, 3600); json_out(Drop::link($anon, $mm[1], body_json())); }
        json_error('Drop endpoint not found.', 404);
    }
    // Calendar feeds for other apps (webcal/https): the secret token is the only key; revoked or unknown tokens answer 404.
    if (preg_match('#^/misc/cal/feed/([a-f0-9]{40})\.ics$#D', $path, $m) && in_array($method, ['GET', 'HEAD'], true)) Calendar::publicFeed($m[1]);
    $user = Auth::requireUser();

    if (strpos($path, '/misc/cal/') === 0) {
        if ($path === '/misc/cal/calendars' && $method === 'GET') json_out(Calendar::calendars($user));
        if ($path === '/misc/cal/calendars' && $method === 'POST') json_out(Calendar::saveCalendar($user, null, body_json()));
        if (preg_match('#^/misc/cal/calendars/([a-f0-9]{16})$#D', $path, $m) && $method === 'POST') json_out(Calendar::saveCalendar($user, $m[1], body_json()));
        if (preg_match('#^/misc/cal/calendars/([a-f0-9]{16})$#D', $path, $m) && $method === 'DELETE') json_out(Calendar::deleteCalendar($user, $m[1]));
        if (preg_match('#^/misc/cal/calendars/([a-f0-9]{16})/feed$#D', $path, $m) && $method === 'POST') json_out(Calendar::feed($user, $m[1], (string)(body_json()['action'] ?? 'create')));
        if (preg_match('#^/misc/cal/calendars/([a-f0-9]{16})/share$#D', $path, $m) && $method === 'POST') json_out(Calendar::share($user, $m[1], body_json()));
        if (preg_match('#^/misc/cal/calendars/([a-f0-9]{16})/export$#D', $path, $m) && in_array($method, ['GET', 'HEAD'], true)) Calendar::export($user, $m[1]);
        if (preg_match('#^/misc/cal/calendars/([a-f0-9]{16})/import$#D', $path, $m) && $method === 'POST') json_out(Calendar::import($user, $m[1], (string)(body_json()['ics'] ?? '')));
        if ($path === '/misc/cal/events' && $method === 'GET') json_out(Calendar::range($user, (int)($_GET['from'] ?? time()), (int)($_GET['to'] ?? time() + 30 * 86400), array_values(array_filter(explode(',', (string)($_GET['ids'] ?? '')), function ($x) { return (bool)preg_match('/^[a-f0-9]{16}$/D', $x); })), !empty($_GET['reminders'])));
        if ($path === '/misc/cal/events' && $method === 'POST') json_out(Calendar::createEvent($user, body_json()));
        if (preg_match('#^/misc/cal/events/([a-f0-9]{16})$#D', $path, $m) && $method === 'POST') json_out(Calendar::updateEvent($user, $m[1], body_json()));
        if (preg_match('#^/misc/cal/events/([a-f0-9]{16})$#D', $path, $m) && $method === 'DELETE') json_out(Calendar::deleteEvent($user, $m[1], (int)($_GET['occurrence'] ?? 0)));
        if ($path === '/misc/cal/alerts' && $method === 'GET') json_out(Calendar::alerts($user, (int)($_GET['window'] ?? 600)));
        if ($path === '/misc/cal/refresh-episodes' && $method === 'POST') { Calendar::queueEpisodes(true); json_out(['ok' => true]); }
        json_error('Calendar endpoint not found.', 404);
    }
    if (strpos($path, '/misc/reminders') === 0) {
        if ($path === '/misc/reminders' && $method === 'GET') json_out(Reminders::list($user));
        if ($path === '/misc/reminders' && $method === 'POST') json_out(Reminders::create($user, body_json()));
        if ($path === '/misc/reminders/import' && $method === 'POST') { $b = body_json(); json_out(Reminders::importLegacy($user, (array)($b['items'] ?? []), (string)($b['tz'] ?? ''))); }
        if (preg_match('#^/misc/reminders/([a-f0-9]{16})$#D', $path, $m) && $method === 'POST') json_out(Reminders::update($user, $m[1], body_json()));
        if (preg_match('#^/misc/reminders/([a-f0-9]{16})$#D', $path, $m) && $method === 'DELETE') json_out(Reminders::delete($user, $m[1]));
        if (preg_match('#^/misc/reminders/([a-f0-9]{16})/done$#D', $path, $m) && $method === 'POST') json_out(Reminders::done($user, $m[1]));
        if (preg_match('#^/misc/reminders/([a-f0-9]{16})/undo$#D', $path, $m) && $method === 'POST') json_out(Reminders::undo($user, $m[1], (string)(body_json()['through'] ?? '')));
        if (preg_match('#^/misc/reminders/([a-f0-9]{16})/snooze$#D', $path, $m) && $method === 'POST') json_out(Reminders::snooze($user, $m[1], (int)(body_json()['minutes'] ?? 60)));
        json_error('Reminders endpoint not found.', 404);
    }
    if (strpos($path, '/misc/pictures') === 0) {
        if ($path === '/misc/pictures' && $method === 'GET') json_out(Pictures::list($user));
        if ($path === '/misc/pictures' && $method === 'POST') json_out(Pictures::upload($user));
        if ($path === '/misc/pictures/delete' && $method === 'POST') json_out(Pictures::delete($user, (array)(body_json()['ids'] ?? [])));
        if (preg_match('#^/misc/pictures/([a-f0-9]{16})$#D', $path, $m) && in_array($method, ['GET', 'HEAD'], true)) Pictures::serve($user, $m[1]);
        if (preg_match('#^/misc/pictures/([a-f0-9]{16})$#D', $path, $m) && $method === 'POST') json_out(Pictures::rename($user, $m[1], (string)(body_json()['name'] ?? '')));
        if (preg_match('#^/misc/pictures/([a-f0-9]{16})$#D', $path, $m) && $method === 'DELETE') json_out(Pictures::delete($user, [$m[1]]));
        json_error('Pictures endpoint not found.', 404);
    }

    if (strpos($path, '/drop/') === 0 || $path === '/drop') {
        if ($path === '/drop/state' && $method === 'GET') json_out(Drop::state($user));
        if ($path === '/drop/text' && $method === 'POST') json_out(Drop::createText($user, body_json()));
        if ($path === '/drop/upload' && $method === 'POST') json_out(Drop::init($user, body_json()));
        if (preg_match('#^/drop/upload/([a-f0-9]{32})$#D', $path, $m) && $method === 'GET') json_out(Drop::resume($user, $m[1]));
        if (preg_match('#^/drop/upload/([a-f0-9]{32})$#D', $path, $m) && ($method === 'POST' || $method === 'PUT')) {
            $r = Drop::chunk($user, $m[1], (int)($_GET['offset'] ?? -1));
            json_out($r, !empty($r['ok']) ? 200 : 409);
        }
        if (preg_match('#^/drop/items/([a-f0-9]{32})$#D', $path, $m) && $method === 'POST') json_out(Drop::update($user, $m[1], body_json()));
        if (preg_match('#^/drop/items/([a-f0-9]{32})$#D', $path, $m) && $method === 'DELETE') json_out(Drop::delete($user, $m[1]));
        if (preg_match('#^/drop/items/([a-f0-9]{32})/download$#D', $path, $m) && in_array($method, ['GET', 'HEAD'], true)) Drop::ownerDownload($user, $m[1], !empty($_GET['inline']));
        if (preg_match('#^/drop/items/([a-f0-9]{32})/links$#D', $path, $m) && $method === 'POST') json_out(Drop::link($user, $m[1], body_json()));
        if (preg_match('#^/drop/links/([a-f0-9]{32})/revoke$#D', $path, $m) && $method === 'POST') json_out(Drop::revoke($user, $m[1]));
        if (preg_match('#^/drop/links/([a-f0-9]{32})/extend$#D', $path, $m) && $method === 'POST') json_out(Drop::extendLink($user, $m[1], body_json()));
        if ($path === '/drop/zip' && $method === 'GET') Drop::zip($user, explode(',', (string)($_GET['ids'] ?? '')));
        if (strpos($path, '/drop/admin') === 0) {
            Auth::requireAdmin();
            if ($path === '/drop/admin' && $method === 'GET') json_out(Drop::overview());
            if ($path === '/drop/admin/settings' && $method === 'POST') json_out(Drop::saveSettings(body_json()));
            if ($path === '/drop/admin/cleanup' && $method === 'POST') json_out(Maintenance::start('drop-cleanup', 'Administrator (' . ($user['username'] ?? 'admin') . ')'));
            if (preg_match('#^/drop/admin/items/([a-f0-9]{32})$#D', $path, $m) && $method === 'DELETE') json_out(Drop::delete($user, $m[1]));
        }
        json_error('Drop endpoint not found.', 404);
    }

    if ($path === '/misc/boards' && $method === 'GET') json_out(Misc::boards($user));
    if ($path === '/misc/boards' && $method === 'POST') json_out(Misc::saveBoards($user, body_json()));
    if ($path === '/misc/widget/geocode' && $method === 'GET') json_out(Misc::geocode($user, (string)($_GET['q'] ?? '')));
    if ($path === '/misc/widget/weather' && $method === 'GET') json_out(Misc::weather($user, (float)($_GET['lat'] ?? 999), (float)($_GET['lon'] ?? 999), (string)($_GET['units'] ?? 'c')));
    if ($path === '/misc/widget/rss' && $method === 'GET') json_out(Misc::rss($user, (string)($_GET['url'] ?? '')));
    if ($path === '/misc/widget/ics' && $method === 'GET') json_out(Misc::ics($user, (string)($_GET['url'] ?? ''), (int)($_GET['days'] ?? 14)));
    if ($path === '/misc/widget/health' && $method === 'GET') json_out(Misc::health($user));
    if ($path === '/misc/widget/recent' && $method === 'GET') json_out(Misc::recent($user, (int)($_GET['n'] ?? 12)));
    if ($path === '/misc/widget/nowplaying' && $method === 'GET') json_out(Misc::nowPlaying($user));
    if ($path === '/misc/widget/downloads' && $method === 'GET') json_out(Misc::downloads($user));
    if ($path === '/misc/widget/recordings' && $method === 'GET') json_out(Misc::recordings($user));
    if ($path === '/misc/widget/radio-now' && $method === 'GET') json_out(Misc::radioNow($user, (string)($_GET['id'] ?? ''), (string)($_GET['url'] ?? '')));
    if ($path === '/misc/movies' && $method === 'GET') json_out(Misc::movies($user, trim((string)($_GET['q'] ?? '')), (string)($_GET['source'] ?? ''), (int)($_GET['n'] ?? 24)));
    if ($path === '/misc/movies/sources' && $method === 'GET') json_out(Misc::movieSources($user));
    if ($path === '/misc/movies/next' && $method === 'POST') { $b = body_json(); json_out(Misc::nextMovie($user, (string)($b['after'] ?? ''), (string)($b['source'] ?? ''), (array)($b['queue'] ?? []))); }
    if ($path === '/misc/prefs' && $method === 'GET') json_out(Misc::prefs($user));
    if ($path === '/misc/prefs' && $method === 'POST') json_out(Misc::savePrefs($user, body_json()));
    if ($path === '/misc/ambient' && $method === 'GET') json_out(['ok' => true] + Misc::ambientSettings());
    if ($path === '/misc/ambient/stills' && $method === 'GET') json_out(Misc::stills($user, (string)($_GET['kind'] ?? 'mixed'), (int)($_GET['n'] ?? 30), (string)($_GET['type'] ?? 'both'), (string)($_GET['order'] ?? 'random'), (string)($_GET['variant'] ?? 'full')));
    if (preg_match('#^/misc/ambient/frame/([A-Za-z0-9_-]{1,64})$#D', $path, $m) && $method === 'GET') Misc::frame($user, $m[1]);
    if ($path === '/misc/admin/ambient' && $method === 'POST') { Auth::requireAdmin(); json_out(Misc::saveAmbient(body_json())); }
} catch (DomainException $e) { json_error($e->getMessage(), 422); }
json_error('Misc endpoint not found.', 404);
