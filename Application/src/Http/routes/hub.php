<?php
declare(strict_types=1);
if (strpos($path,'/hub/')===0) {
    header('Cache-Control: no-store');
    $viewer=current_or_guest();
    try {
        if ($path==='/hub/dashboard' && $method==='GET') json_out(HomeHub::dashboard($viewer));
        if ($path==='/hub/title' && $method==='GET') json_out(HomeHub::title((string)($_GET['key'] ?? ''),$viewer));
        if ($path==='/hub/rooms' && $method==='GET') json_out(HomeHub::rooms($viewer));
        if (preg_match('#^/hub/rooms/([a-f0-9]{16})$#',$path,$m) && $method==='GET') json_out(['ok'=>true,'room'=>HomeHub::room($m[1],$viewer)]);
        $user=Auth::requireUser();
        if ($path==='/hub/games' && $method==='GET') json_out(HomeHub::gameList($user,(int)($_GET['page'] ?? 1),(string)($_GET['q'] ?? '')));
        if ($path==='/hub/people' && $method==='GET') json_out(HomeHub::people($user,(string)($_GET['q'] ?? '')));
        if ($path==='/hub/profile' && $method==='GET') json_out(HomeHub::friendProfile($user,(string)($_GET['id'] ?? '')));
        if ($method==='POST') {
            $body=body_json();
            if ($path==='/hub/shelf') json_out(HomeHub::save($user,$body));
            if ($path==='/hub/review') json_out(HomeHub::review($user,$body));
            if ($path==='/hub/watched') json_out(HomeHub::watched($user,$body));
            if ($path==='/hub/game') json_out(HomeHub::game($user,$body));
            if ($path==='/hub/friend') json_out(HomeHub::friendship($user,$body));
            if ($path==='/hub/invite') json_out(HomeHub::invite($user,$body));
            if ($path==='/hub/dismiss') json_out(HomeHub::dismiss($user,(string)($body['id'] ?? '')));
            if ($path==='/hub/rooms') json_out(HomeHub::createRoom($user,$body));
            if (preg_match('#^/hub/rooms/([a-f0-9]{16})$#',$path,$m)) json_out(HomeHub::roomUpdate($m[1],$user,$body));
        }
    } catch (DomainException $e) { json_error($e->getMessage(),422); }
    json_error('Home endpoint not found.',404);
}
