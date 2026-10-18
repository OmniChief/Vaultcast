<?php
declare(strict_types=1);
if ($path==='/hub/chat' && $method==='GET') json_out(Chat::listing($user,($_GET['mode'] ?? '')==='mail',(string)($_GET['focus'] ?? '')));
if ($path==='/hub/chat' && $method==='POST') {Chat::cleanup();json_out(Chat::create($user,body_json()));}
if ($path==='/hub/chat/presence' && $method==='POST') json_out(Chat::pulse($user));
if ($path==='/hub/chat/profile' && $method==='GET') json_out(Chat::profile($user,(string)($_GET['id'] ?? '')));
if (preg_match('#^/hub/chat/files/([a-f0-9]{32})$#',$path,$m) && in_array($method,['GET','HEAD'],true)) Chat::file($user,$m[1]);
if (preg_match('#^/hub/chat/files/([a-f0-9]{32})/(\d+)$#',$path,$m) && $method==='POST') json_out(Chat::chunk($user,$m[1],(int)$m[2]));
if (preg_match('#^/hub/chat/([a-z0-9-]{1,64})(/join|/upload)?$#',$path,$m)) {
    $suffix=$m[2] ?? '';
    if ($method==='GET' && $suffix==='') json_out(Chat::history($user,$m[1],(int)($_GET['revision'] ?? -1),(int)($_GET['before'] ?? 0)));
    if ($method==='POST') {
        Chat::cleanup();
        if ($suffix==='/join') json_out(Chat::join($user,$m[1]));
        if ($suffix==='/upload') json_out(Chat::upload($user,$m[1],body_json()));
        json_out(Chat::change($user,$m[1],body_json()));
    }
}
json_error('Chat endpoint not found.',404);
