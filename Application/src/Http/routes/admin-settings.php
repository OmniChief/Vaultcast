<?php
declare(strict_types=1);
if ($path==='/admin/site-artwork' && $method==='POST') {
    try {json_out(SiteArtwork::save(body_json()));}
    catch (InvalidArgumentException $error) {json_error($error->getMessage(),422);}
    catch (RuntimeException $error) {json_error($error->getMessage(),400);}
}
if ($path === '/admin/factory-reset' && $method === 'POST') {
    try { json_out(FactoryReset::run(body_json())); }
    catch (RuntimeException $error) { json_error($error->getMessage(), 409); }
    catch (DomainException $error) { json_error($error->getMessage(), 400); }
}
if ($path === '/admin/player-assets') {
    try {
        if ($method === 'GET') json_out(PlayerAssets::status());
        if ($method === 'POST') {
            $body = body_json();
            if (($body['action'] ?? '') === 'download') json_out(PlayerAssets::downloadNext());
            if (($body['action'] ?? '') === 'source') json_out(PlayerAssets::setSource((string)($body['source'] ?? '')));
            if (($body['action'] ?? '') === 'reset') {
                json_out(PlayerAssets::resetDownload());
            }
            json_error('Unknown player storage action');
        }
    } catch (RuntimeException $error) {
        json_error($error->getMessage(), 400);
    }
}
// Folder picker for library paths (admin only: every /admin/ route passes Auth::requireAdmin()).
if ($path === '/admin/folders' && $method === 'GET') {
    json_out(\Vaultcast\Paths::browse((string)($_GET['path'] ?? '')));
}
if ($path === '/admin/folders/check' && $method === 'GET') {
    json_out(\Vaultcast\Paths::check((string)($_GET['path'] ?? '')));
}
if ($path === '/admin/settings' && $method === 'GET') {
    $out = Store::settings();
    // Conversion threads: stored value (existing installs: 1) and this server's CPU count as the limit.
    $cv = is_array($out['convert'] ?? null) ? $out['convert'] : [];
    $cv['max_threads'] = Convert::cpuCount();
    $cv['threads'] = Convert::sanitizeThreadCount($cv['threads'] ?? 1, $cv['max_threads']);
    $out['convert'] = $cv;
    $out['transcoding'] = Transcode::settings();
    $out['transcoding']['max_threads'] = Convert::cpuCount();
    json_out(['ok' => true, 'settings' => OpenSubtitles::maskSettings($out)]);
}

if ($path === '/admin/settings' && $method === 'POST') {
    $b = body_json();
    try {
        if(isset($b['transcoding'])) {
            if(!is_array($b['transcoding']))throw new InvalidArgumentException('Invalid transcoding settings.');
            unset($b['transcoding']['max_threads']);
            $b['transcoding']=Transcode::validate($b['transcoding']);
        }
        foreach(['directplay_only','allow_remux'] as $flag)if(isset($b['streaming']) && array_key_exists($flag,$b['streaming']) && !is_bool($b['streaming'][$flag]))throw new InvalidArgumentException('Choose on or off for '.$flag.'.');
    } catch(InvalidArgumentException $e){json_error($e->getMessage(),422);}
    if (isset($b['app_name'])) {
        $b['app_name'] = trim((string)$b['app_name']);
        if ($b['app_name'] === '' || mb_strlen($b['app_name']) > 80) json_error('Site name must contain 1–80 characters.');
    }
    if (isset($b['public_base_url'])) {
        $pub = trim((string)$b['public_base_url']);
        if ($pub !== '' && (!preg_match('#^https?://[^\s/?#]+(/[^\s?#]*)?$#i', $pub) || strlen($pub) > 300)) json_error('Public base URL must look like https://media.example.com/ (no query or fragment).');
        $b['public_base_url'] = $pub === '' ? '' : rtrim($pub, '/') . '/';
    }
    if (isset($b['convert']) && is_array($b['convert'])) {
        unset($b['convert']['max_threads']); // reported by the server, never stored
        try {$b['convert']=Convert::validateSettings($b['convert']);}catch(InvalidArgumentException $e){json_error($e->getMessage(),422);}
        if (array_key_exists('threads', $b['convert'])) {
            $max = Convert::cpuCount();
            $t = Convert::validThreadCount($b['convert']['threads'], $max);
            if ($t === null) json_error('Conversion threads must be a whole number from 1 to ' . $max . ' (this server\'s CPU count).');
            $b['convert']['threads'] = $t;
        }
    }
    if (isset($b['sections']) && is_array($b['sections'])) {
        // Validate only new or changed folders, so an offline share does not block other edits.
        $known = [];
        foreach (Store::settings()['sections'] ?? [] as $old) $known[(string)($old['id'] ?? '')] = (string)($old['path'] ?? '');
        foreach ($b['sections'] as $i => $sec) {
            if (!is_array($sec)) continue;
            $raw = (string)($sec['path'] ?? '');
            if (($known[(string)($sec['id'] ?? '')] ?? null) === $raw) continue;
            if (trim($raw) === '' && empty($sec['enabled'])) continue;
            $check = \Vaultcast\Paths::check($raw);
            if (!$check['ok']) json_error(((string)($sec['label'] ?? 'Library')) . ': ' . $check['message'], 400);
            $b['sections'][$i]['path'] = $check['path'];
            $acl = MediaAccel::grant($check['path']);
            if (!$acl['ok']) json_error(((string)($sec['label'] ?? 'Library')) . ': ' . $acl['message'], 400);
        }
    }
    $cur = Store::settings();
    if (isset($b['features']) && is_array($b['features']) && array_key_exists('live', $b['features'])) {
        $b['features']['live'] = (bool)$b['features']['live'];
        $cur['features_explicit'] = array_replace(is_array($cur['features_explicit'] ?? null) ? $cur['features_explicit'] : [], ['live' => true]);
    }
    if (isset($b['features']) && is_array($b['features']) && array_key_exists('downloads', $b['features'])) {
        $b['features']['downloads'] = (bool)$b['features']['downloads'];
        $cur['features_explicit'] = array_replace(is_array($cur['features_explicit'] ?? null) ? $cur['features_explicit'] : [], ['downloads' => true]);
    }
    unset($b['features_explicit']);
    if (isset($b['opensubtitles']) && is_array($b['opensubtitles'])) {
        $cur['opensubtitles'] = OpenSubtitles::mergeSettings($cur, $b['opensubtitles']);
        unset($b['opensubtitles']);
    }
    $newFeeds = null;
    if (isset($b['acquisition']) && is_array($b['acquisition']) && array_key_exists('feeds', $b['acquisition'])) {
        $nf = FeedCatalogue::normalize($b['acquisition']['feeds']);
        if (!$nf['ok']) json_error($nf['error'], 400);
        $newFeeds = $nf['feeds'];
        unset($b['acquisition']['feeds']); // replaced wholesale below (a recursive merge would keep removed entries)
    }
    foreach ($b as $k => $v) {
        if (is_string($k) && strlen($k) > 0 && $k[0] === '_') {
            continue; // client-only flags
        }
        if ($k === 'sections' && is_array($v)) {
            $cur['sections'] = $v;
        } elseif (is_array($v) && isset($cur[$k]) && is_array($cur[$k])) {
            $cur[$k] = array_replace_recursive($cur[$k], $v);
        } else {
            $cur[$k] = $v;
        }
    }
    // Sensible Transmission seeding defaults (no seeding unless explicitly allowed)
    if (!isset($cur['acquisition']) || !is_array($cur['acquisition'])) {
        $cur['acquisition'] = [];
    }
    if (!isset($cur['acquisition']['transmission']) || !is_array($cur['acquisition']['transmission'])) {
        $cur['acquisition']['transmission'] = [];
    }
    $tr = &$cur['acquisition']['transmission'];
    if (!array_key_exists('allow_seeding', $tr)) $tr['allow_seeding'] = false;
    if (!array_key_exists('seed_ratio_limit', $tr)) $tr['seed_ratio_limit'] = 0;
    if (!array_key_exists('seed_idle_minutes', $tr)) $tr['seed_idle_minutes'] = 0;
    if (!array_key_exists('apply_to_existing', $tr)) $tr['apply_to_existing'] = true;
    unset($tr);
    if ($newFeeds !== null) $cur['acquisition']['feeds'] = $newFeeds;
    Store::saveSettings($cur);
    Store::invalidate();
    $extra = [];
    // Keep Transmission's own default folder in step with the Download folder setting
    if (isset($b['acquisition']) && is_array($b['acquisition']) && array_key_exists('download_path', $b['acquisition'])) {
        $dl = trim((string)($cur['acquisition']['download_path'] ?? ''));
        $trs = $cur['acquisition']['transmission'] ?? [];
        if ($dl !== '' && !empty($trs['enabled'])) {
            $now = Acquisition::transmissionDownloadDir($trs);
            if ($now !== null && rtrim(str_replace('\\', '/', $now), '/') !== rtrim(str_replace('\\', '/', $dl), '/')) {
                $set = Acquisition::setTransmissionDownloadDir($dl, $trs);
                $extra['transmission_download_dir'] = $set['ok'] ? $dl : null;
                if (!$set['ok']) $extra['warning'] = 'Saved, but Transmission did not accept the download folder: ' . ($set['error'] ?? '');
            }
        }
    }
    json_out(['ok' => true, 'settings' => OpenSubtitles::maskSettings($cur)] + $extra);
}





if ($path === '/admin/users' && $method === 'GET') {
    $me = Auth::requireAdmin();
    $users = Auth::users();
    foreach ($users as &$u) {
        unset($u['password_hash']);
        $u['access_state'] = Access::state($u);
        $u['is_self'] = (string)$u['id'] === (string)$me['id'];
    }
    unset($u);
    json_out(['ok' => true, 'users' => $users, 'now' => time(), 'role_counts'=>array_count_values(array_column($users,'role')),'permission_choices'=>Permissions::CAPABILITIES,'roles'=>Permissions::roles()]);
}

if ($path==='/admin/roles' && $method==='POST') {
    Auth::requireAdmin();try{json_out(Permissions::saveRole(body_json()));}catch(InvalidArgumentException $e){json_error($e->getMessage());}
}
if (preg_match('#^/admin/users/([A-Za-z0-9_-]{1,64})/permissions$#', $path, $m) && $method === 'POST') {
    Auth::requireAdmin();$body=body_json();$role=(string)($body['role'] ?? 'user');
    try{$permissions=Permissions::validate($body['permissions'] ?? []);}catch(InvalidArgumentException $e){json_error($e->getMessage());}
    $areas=[];foreach(AreaAccess::AREAS as $area){if(!is_bool($body['areas'][$area] ?? null))json_error('Choose each library area.');$areas[$area]=$body['areas'][$area];}
    try{$saved=\Vaultcast\Database::transaction(static function(PDO $pdo)use($m,$role,$permissions,$areas):array{
        Permissions::lock($pdo);if($role!=='user'&&!isset(Permissions::roles()[$role]))throw new InvalidArgumentException('Choose an existing custom role or User.');
        return \Vaultcast\Repository::updateUser($m[1],static function(array $user)use($role,$permissions,$areas):array{
            if(($user['role'] ?? '')==='admin')throw new InvalidArgumentException('Administrator permissions cannot be changed here.');
            $user['role']=$role;$user['permissions']=$role==='user'?[]:$permissions;$user['areas']=$areas;return $user;
        });
    });}catch(InvalidArgumentException $e){json_error($e->getMessage());}
    Store::invalidate('users');unset($saved['password_hash']);json_out(['ok'=>true,'user'=>Permissions::publicUser($saved)]);
}

if (preg_match('#^/admin/users/([A-Za-z0-9_-]{1,64})/areas$#', $path, $m) && $method === 'POST') {
    $target = Auth::findById($m[1]);
    if (!$target) json_error('User not found', 404);
    if (($target['role'] ?? '') === 'admin') json_error('Admins always have access to every area.');
    $body = body_json();
    if (!is_array($body['areas'] ?? null)) json_error('Choose the areas this user can access.');
    $areas = [];
    foreach (AreaAccess::AREAS as $area) {
        if (!array_key_exists($area, $body['areas']) || !is_bool($body['areas'][$area])) json_error('Each area must be enabled or disabled.');
        $areas[$area] = $body['areas'][$area];
    }
    $saved = \Vaultcast\Repository::updateUser((string)$target['id'], static function(array $user) use ($areas): array { $user['areas'] = $areas; return $user; });
    Store::invalidate('users');
    unset($saved['password_hash']);
    json_out(['ok'=>true, 'user'=>$saved]);
}

if (preg_match('#^/admin/users/([A-Za-z0-9_-]{1,64})/access$#', $path, $m) && $method === 'POST') {
    $me = Auth::requireAdmin();
    $target = Auth::findById($m[1]);
    if (!$target) json_error('User not found', 404);
    if (($target['role'] ?? '') === 'admin') json_error('Admins always have access.');
    $b = body_json();
    try {
        $access = Access::apply(is_array($target['access'] ?? null) ? $target['access'] : null, (string)($b['period'] ?? ''),
            isset($b['date']) ? (string)$b['date'] : null, ($b['mode'] ?? 'set') === 'extend' ? 'extend' : 'set', (string)$me['username']);
    } catch (InvalidArgumentException $e) { json_error($e->getMessage()); }
    $saved = \Vaultcast\Repository::updateUser($target['id'], function (array $u) use ($access): array { $u['access'] = $access; return $u; });
    unset($saved['password_hash']);
    $saved['access_state'] = Access::state($saved);
    json_out(['ok' => true, 'user' => $saved]);
}

if (preg_match('#^/admin/users/([A-Za-z0-9_-]{1,64})$#', $path, $m) && $method === 'DELETE') {
    $me = Auth::requireAdmin();
    $target = Auth::findById($m[1]);
    if (!$target) json_error('User not found', 404);
    $why = Access::deleteBlocker($me, $target, Auth::users());
    if ($why !== null) json_error($why, 409);
    if (!\Vaultcast\Repository::deleteUser($target['id'])) json_error('User not found', 404);
    Store::invalidate();
    json_out(['ok' => true, 'deleted' => $target['username']]);
}

if ($path === '/admin/users' && $method === 'POST') {
    $b = body_json();
    $username = trim($b['username'] ?? '');
    if ($username === '' || empty($b['password'])) json_error('username and password required');
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,80}$/', $username)) json_error('Use 3–80 letters, numbers, dots, dashes or underscores for the username.');
    if (strlen($b['password']) < 12 || strlen($b['password']) > 72) json_error('Use a password between 12 and 72 bytes.');
    if (Auth::findUser($username)) json_error('User exists', 409);
    $created = [
        'id' => bin2hex(random_bytes(8)),
        'username' => $username,
        'password_hash' => password_hash($b['password'], PASSWORD_DEFAULT),
        'role' => (string)($b['role'] ?? 'user'),
        'permissions' => [],
        'display_name' => $b['display_name'] ?? $username,
        'prefs' => ['theme'=>'dark','autoplay'=>false],
        'created_at' => date('c'),
    ];
    if ($created['role'] !== 'admin') {
        try { $created['access'] = Access::forNewUser(isset($b['access']) ? (string)$b['access'] : null, isset($b['access_date']) ? (string)$b['access_date'] : null, (string)(Auth::requireAdmin()['username'] ?? 'admin')); }
        catch (InvalidArgumentException $e) { json_error($e->getMessage()); }
    }
    \Vaultcast\Database::transaction(static function(PDO $pdo)use($created):void{Permissions::lock($pdo);if(!in_array($created['role'],['admin','user'],true)&&!isset(Permissions::roles()[$created['role']]))json_error('Choose an existing role.');\Vaultcast\Repository::createUser($created);});
    json_out(['ok' => true]);
}
