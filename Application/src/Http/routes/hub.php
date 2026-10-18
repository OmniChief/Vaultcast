<?php
declare(strict_types=1);
if (strpos($path,'/hub/')===0) {
    header('Cache-Control: no-store');
    $viewer=current_or_guest();
    try {
        if ($path==='/hub/dashboard' && $method==='GET') {$started=microtime(true);$out=HomeHub::dashboard($viewer);RequestTiming::add('hub_dashboard',$started);json_out($out);}
        if ($path==='/hub/title' && $method==='GET') {$started=microtime(true);$out=HomeHub::title((string)($_GET['key'] ?? ''),$viewer);RequestTiming::add('hub_title',$started);json_out($out);}
        if ($path==='/hub/rooms' && $method==='GET') json_out(HomeHub::rooms($viewer));
        if (preg_match('#^/hub/rooms/([a-f0-9]{16})$#',$path,$m) && $method==='GET') json_out(['ok'=>true,'room'=>HomeHub::room($m[1],$viewer)]);
        if ($path==='/hub/tonight' && $method==='GET') json_out(HomeExtras::tonight($viewer,(int)($_GET['minutes'] ?? 90)));
        $user=Auth::requireUser();
        if ($path==='/hub/chat' || strpos($path,'/hub/chat/')===0) require __DIR__.'/chat.php';
        if ($path==='/hub/extras' && $method==='GET') json_out(HomeExtras::state($user));
        if (preg_match('#^/hub/lists/([a-f0-9]{16,64})/([a-f0-9]{16})$#',$path,$collection) && $method==='GET') json_out(HomeExtras::collection($user,$collection[1],$collection[2]));
        if ($path==='/hub/lists' && $method==='GET') json_out(HomeExtras::lists($user,(int)($_GET['page'] ?? 1),(string)($_GET['q'] ?? '')));
        if ($path==='/hub/lists' && $method==='POST') json_out(HomeExtras::change($user,body_json()));
        if ($path==='/hub/arrivals' && $method==='GET') json_out(HomeExtras::arrivals($user));
        if ($path==='/hub/saves' && $method==='GET') json_out(GameSaves::listing($user,(string)($_GET['item_id'] ?? '')));
        if ($path==='/hub/saves/shared' && $method==='GET') { $share=GameSaves::shared($user,(string)($_GET['token'] ?? ''));unset($share['owner']);json_out(['ok'=>true,'share'=>$share]); }
        if ($path==='/hub/saves/download' && $method==='GET') GameSaves::download($user,(string)($_GET['item_id'] ?? ''),(string)($_GET['slot'] ?? ''),(string)($_GET['share'] ?? ''));
        if (preg_match('#^/hub/saves/upload/([a-f0-9]{32})/(\d+)$#',$path,$upload) && $method==='POST') json_out(GameSaves::chunk($user,$upload[1],(int)$upload[2]));
        if ($path==='/hub/saves/begin' && $method==='POST') json_out(GameSaves::begin($user,body_json()));
        if (preg_match('#^/hub/saves/abort/([a-f0-9]{32})$#',$path,$upload) && $method==='POST') json_out(GameSaves::abort($user,$upload[1]));
        if (preg_match('#^/hub/saves/finish/([a-f0-9]{32})$#',$path,$upload) && $method==='POST') json_out(GameSaves::finish($user,$upload[1]));
        if ($path==='/hub/saves/edit' && $method==='POST') json_out(GameSaves::edit($user,body_json()));
        if ($path==='/hub/saves/revoke' && $method==='POST') json_out(GameSaves::revoke($user,(string)(body_json()['token'] ?? '')));
        if ($path==='/hub/saves/share' && $method==='POST') json_out(GameSaves::share($user,body_json()));
        if ($path==='/hub/saves/import' && $method==='POST') json_out(GameSaves::import($user,body_json()));
        if ($path==='/hub/inbox' && $method==='GET') json_out(HomeHub::inbox($user));
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
