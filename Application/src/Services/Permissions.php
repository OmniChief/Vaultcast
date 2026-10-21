<?php
declare(strict_types=1);

final class Permissions {
    const CAPABILITIES = ['ingest_view'=>'View the import inbox','ingest_import'=>'Import inbox downloads (includes viewing; moves files into the library)','rss_search'=>'Search RSS sources','downloads_request'=>'Request downloads','fix_match'=>'Fix movie, show and game matches','metadata_edit'=>'Edit titles, synopsis and posters','library_remove'=>'Remove entries from the library (media files kept)','timing_edit'=>'Search and edit intro / credits timings','artwork_manage'=>'Check and repair artwork integrity','iptv_manage'=>'Manage IPTV feeds and shared channels','radio_manage'=>'Manage shared radio stations','drop.upload'=>'Upload to Drop (files, text and share links)','pictures.upload'=>'Upload pictures for Ambient and the Picture frame widget','save_copy'=>'Watch & save copy (saves a reusable direct-play copy while watching)'];
    /** Capabilities the built-in User role may receive: an admin default plus per-user overrides. */
    const USER_ROLE_CAPABILITIES = ['drop.upload','pictures.upload'];
    public static function userRoleDefaults(): array {
        $drop=(array)(Store::settings()['drop'] ?? []);
        $pictures=(array)(Store::settings()['pictures'] ?? []);
        return ['drop.upload'=>!empty($drop['user_role_upload']),'pictures.upload'=>!array_key_exists('user_role_upload',$pictures)||!empty($pictures['user_role_upload'])];
    }
    private static $roles;
    public static function roles(): array {
        if(self::$roles===null)self::$roles=\Vaultcast\Repository::document('permission_roles',['curator'=>['name'=>'Curator','permissions'=>[]]]);
        return self::$roles;
    }
    public static function allowed(?array $user,string $permission): bool {
        if(!isset(self::CAPABILITIES[$permission])||!$user)return false;
        $role=$user['role'] ?? 'user';if($role==='admin')return true;
        if($role==='user'){if(!in_array($permission,self::USER_ROLE_CAPABILITIES,true))return false;$own=$user['permissions'][$permission] ?? null;return is_bool($own)?$own:(self::userRoleDefaults()[$permission] ?? false);}
        if(!isset(self::roles()[$role]))return false;
        if($permission==='ingest_view'&&self::allowed($user,'ingest_import'))return true;
        return ($user['permissions'][$permission] ?? self::roles()[$role]['permissions'][$permission] ?? false)===true;
    }
    public static function publicUser(array $user): array {
        $user['capabilities']=[];foreach(self::CAPABILITIES as $key=>$label)$user['capabilities'][$key]=self::allowed($user,$key);return $user;
    }
    public static function require(string $permission): array {
        $user=Auth::requireUser();if(!self::allowed($user,$permission))json_error('This action requires the '.$permission.' permission.',403);return $user;
    }
    public static function validate($permissions): array {
        if(!is_array($permissions))throw new InvalidArgumentException('Permissions must be a set of enabled or disabled capabilities.');
        foreach($permissions as $key=>$value)if(!isset(self::CAPABILITIES[$key])||!is_bool($value))throw new InvalidArgumentException('Unknown permission or invalid permission value.');
        return $permissions;
    }
    public static function saveRole(array $body): array {
        return \Vaultcast\Database::transaction(static function(PDO $pdo)use($body):array{
            self::lock($pdo);self::$roles=null;$roles=self::roles();$id=(string)($body['id'] ?? '');
            if($id==='admin'||$id==='user')throw new InvalidArgumentException('Admin and User are protected roles.');
            if($id!==''&&!isset($roles[$id]))throw new InvalidArgumentException('Role no longer exists.');
            if(!empty($body['delete'])){
                if($id==='')throw new InvalidArgumentException('Choose an existing role to delete.');
                $s=$pdo->prepare('SELECT payload FROM vaultcast_users WHERE role=? FOR UPDATE');$s->execute([$id]);$users=$s->fetchAll(PDO::FETCH_COLUMN);
                $replacement=(string)($body['replacement_role'] ?? '');
                if($users&&($replacement===$id||($replacement!=='user'&&!isset($roles[$replacement]))))throw new InvalidArgumentException('Choose a replacement role for assigned users.');
                foreach($users as $raw){$user=json_decode($raw,true);\Vaultcast\Repository::updateUser($user['id'],static function(array $record)use($replacement):array{$record['role']=$replacement;$record['permissions']=[];return $record;});}
                Store::invalidate('users');
                unset($roles[$id]);
            }else{
                $name=trim((string)($body['name'] ?? ''));if($name===''||mb_strlen($name)>60)throw new InvalidArgumentException('Use a role name of 1–60 characters.');
                if(in_array(mb_strtolower($name),['admin','administrator','user','guest'],true))throw new InvalidArgumentException('Choose a different role name.');
                foreach($roles as $key=>$role)if($key!==$id&&mb_strtolower($role['name'])===mb_strtolower($name))throw new InvalidArgumentException('A role already has this name.');
                if($id===''){if(count($roles)>=30)throw new InvalidArgumentException('Up to 30 custom roles are supported.');$id='role_'.bin2hex(random_bytes(6));}
                $roles[$id]=['name'=>$name,'permissions'=>self::validate($body['permissions'] ?? [])];
            }
            \Vaultcast\Repository::putDocument('permission_roles',$roles);self::$roles=$roles;return ['ok'=>true,'roles'=>$roles];
        });
    }
    public static function lock(PDO $pdo): void {
        $pdo->exec("INSERT IGNORE INTO vaultcast_documents(name,payload) VALUES ('permission_roles','{\"curator\":{\"name\":\"Curator\",\"permissions\":{}}}')");
        $pdo->query("SELECT name FROM vaultcast_documents WHERE name='permission_roles' FOR UPDATE")->fetch();self::$roles=null;
    }
    private static function selectionKey(array $user,array $match): string {return 'role-match:'.$user['id'].':'.hash('sha256',json_encode([$match['source'] ?? '',(string)($match['id'] ?? '')]));}
    public static function rememberSelections(array $result): void {
        $user=AreaAccess::viewer();if(!$user||($user['role'] ?? '')==='admin')return;
        foreach($result['results'] ?? [] as $match)\Vaultcast\Repository::cachePut(self::selectionKey($user,$match),$match,3600);
    }
    public static function selection(array $body): array {
        $user=AreaAccess::viewer();if(!$user||($user['role'] ?? '')==='admin')return $body;
        if(!empty($body['match'])){
            if(!is_array($body['match']))json_error('Invalid metadata selection.');
            $match=\Vaultcast\Repository::cacheGet(self::selectionKey($user,$body['match']));if(!$match)json_error('Search again to refresh this metadata selection.');
            $body['match']=$match;$body['image']=$match['image'] ?? null;
        }elseif(!empty($body['image']))json_error('Choose artwork from a metadata search or upload a poster.',403);
        return $body;
    }
    public static function ingestSelection(array $body): array {
        $user=AreaAccess::viewer();if(($user['role'] ?? '')==='admin')return $body;
        if(($body['match']['source'] ?? '')==='manual'){
            $body['match']=['source'=>'manual','id'=>null,'title'=>(string)($body['match']['title'] ?? ''),'year'=>$body['match']['year'] ?? null];
        }else $body=self::selection($body);
        $body['tvmaze_id']=($body['match']['source'] ?? '')==='tvmaze'?(int)($body['match']['id'] ?? 0):0;
        return $body;
    }
    private static function item(string $id,array $user): void {
        $item=Catalogue::item($id);if(!$item)json_error('Item not found',404);
        if(!AreaAccess::allowed($user,AreaAccess::itemArea($item)))json_error('This media area is not available to your account.',403);
    }
    public static function adminRoute(string $path,string $method): void {
        $user=Auth::requireUser();if(($user['role'] ?? '')==='admin')return;
        $permission=null;
        $showRead=$method==='GET'&&($path==='/admin/match/shows'||preg_match('#^/admin/match/show/[a-f0-9]{16}$#D',$path));
        $matchWrite=$method==='POST'&&(in_array($path,['/admin/match/search','/admin/match/items','/admin/match/items/clear'],true)||preg_match('#^/admin/match/show/[a-f0-9]{16}/(?:propose|apply|pin|numbering(?:/preview)?)$#D',$path)||preg_match('#^/admin/match/movie/[a-f0-9]{16}$#D',$path));
        if($showRead||$matchWrite)$permission='fix_match';
        if($method==='GET'&&in_array($path,['/admin/ingest','/admin/ingest/progress','/admin/match/counts'],true))$permission='ingest_view';
        if($method==='POST'&&in_array($path,['/admin/ingest/search','/admin/ingest/plan','/admin/ingest/commit','/admin/ingest/status'],true))$permission='ingest_import';
        if($path==='/admin/media/edit'&&$method==='POST')$permission=!empty(body_json()['remove'])?'library_remove':'metadata_edit';
        if(in_array($path,['/admin/posters/audit','/admin/posters/audit/status','/admin/posters/audit/repair'],true)&&$method==='POST')$permission='artwork_manage';
        if(!$permission||!self::allowed($user,$permission))json_error('Forbidden',403);
        if(($permission==='ingest_view'||$permission==='ingest_import')&&!AreaAccess::allowed($user,'library'))json_error('Library access required',403);
        if(strpos($path,'/admin/match/show')===0&&!AreaAccess::allowed($user,'library'))json_error('Library access required',403);
        if(preg_match('#^/admin/match/movie/([a-f0-9]{16})$#D',$path,$m))self::item($m[1],$user);
        if($method==='POST'){
            $body=body_json();
            if($path==='/admin/match/search'&&!AreaAccess::allowed($user,($body['kind'] ?? '')==='game'?'games':'library'))json_error('Media area access required',403);
            if($path==='/admin/media/edit'){
                if(!empty($body['remove'])&&!self::allowed($user,'library_remove'))json_error('Library removal permission required.',403);
                if(!empty($body['show_key'])){if(!AreaAccess::allowed($user,'library'))json_error('Library access required',403);}else self::item((string)($body['id'] ?? ''),$user);
            }
            if($path==='/admin/match/items')foreach((array)($body['rows'] ?? []) as $row){if(!is_array($row))json_error('Invalid metadata row.');self::item((string)($row['id'] ?? ''),$user);if(array_key_exists('poster',$row)||array_key_exists('show_poster',$row))json_error('Use the poster upload editor to change artwork.',403);}
            if($path==='/admin/match/items/clear')foreach((array)($body['ids'] ?? []) as $id)self::item((string)$id,$user);
        }
    }
}
