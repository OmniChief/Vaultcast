<?php
declare(strict_types=1);
// Achievements and badges. Each person only ever reads and changes their own; the profile
// showcase of others is served through the existing profile endpoints, which apply privacy.
if ($path === '/achievements' || strpos($path, '/achievements/') === 0) {
    header('Cache-Control: no-store');
    $user = Auth::requireUser();
    try {
        if ($path === '/achievements' && $method === 'GET') json_out(Achievements::view($user));
        if ($path === '/achievements/pending' && $method === 'GET') json_out(Achievements::pending($user));
        if ($path === '/achievements/toasted' && $method === 'POST') json_out(Achievements::toasted($user, body_json()));
        if ($path === '/achievements/showcase' && $method === 'POST') json_out(Achievements::saveShowcase($user, body_json()));
        if ($path === '/achievements/tick' && $method === 'POST') { release_request_locks(); json_out(Achievements::tick($user, body_json())); }
    } catch (DomainException $e) { json_error($e->getMessage(), 422); }
    json_error('Not found', 404);
}
