<?php
declare(strict_types=1);

/** Three server slots per user/game. Binary chunks never enter the document store. */
final class GameSaves {
    const MAX_BYTES=33554432;
    const QUOTA=536870912;
    private static $locked=false;
    private static function locked(array $user,callable $operation): array {
        $handle=@fopen(self::directory($user).'/saves.lock','c');
        if (!$handle) throw new DomainException('Cannot lock save storage.');
        try {
            if (!flock($handle,LOCK_EX|LOCK_NB)) throw new DomainException('Another save is being stored. Retry shortly.');
            self::$locked=true;return $operation();
        } finally {self::$locked=false;flock($handle,LOCK_UN);fclose($handle);}
    }
    private static function key(array $user): string {return 'game-saves:'.$user['id'];}
    private static function directory(array $user): string {
        if (!preg_match('/^[a-f0-9]{16,64}$/D',(string)$user['id'])) throw new DomainException('Invalid account.');
        $dir=STREAM_DATA.'/game-saves/'.$user['id'];
        if (!is_dir($dir) && !@mkdir($dir,0775,true)) throw new DomainException('Cannot create save storage.');
        return $dir;
    }
    private static function item(array $user,string $id): array {
        $item=Catalogue::item($id);
        if (!$item || ($item['media_kind'] ?? '')!=='rom' || !AreaAccess::allowed($user,'games')) throw new DomainException('This game is not available to your account.');
        return $item;
    }
    private static function slot(string $slot): void {if (!in_array($slot,['1','2','auto'],true)) throw new DomainException('Choose manual slot 1, 2 or autosave.');}
    public static function removeUser(string $id): void {
        if (!preg_match('/^[a-f0-9]{16,64}$/D',$id)) return;
        $dir=STREAM_DATA.'/game-saves/'.$id;if (!is_dir($dir)) return;
        foreach (glob($dir.'/_uploads/*',GLOB_ONLYDIR) ?: [] as $upload) if (preg_match('/^[a-f0-9]{32}$/D',basename($upload))) self::clearUpload($upload);
        @rmdir($dir.'/_uploads');
        foreach (glob($dir.'/*') ?: [] as $file) if (is_file($file) && preg_match('/^(?:[a-f0-9]{64}\.state|saves\.lock)$/D',basename($file))) @unlink($file);
        @rmdir($dir);
    }
    public static function listing(array $user,string $id=''): array {
        $data=\Vaultcast\Repository::document(self::key($user),[]);$games=$data['games'] ?? [];
        if ($id!=='') {$games=isset($games[$id])?[$id=>$games[$id]]:[];}
        $bytes=self::used($data);$shares=[];foreach ($data['shares'] ?? [] as $token=>$share) if ($share['expires_at']>time()) $shares[]=array_merge($share,['token'=>$token]);
        return ['ok'=>true,'games'=>$games,'bytes'=>$bytes,'quota'=>self::QUOTA,'shares'=>$shares];
    }
    public static function begin(array $user,array $body): array {
        if (!self::$locked) return self::locked($user,static function() use ($user,$body){return self::begin($user,$body);});
        $id=(string)($body['item_id'] ?? '');$item=self::item($user,$id);$slot=(string)($body['slot'] ?? '');self::slot($slot);
        $bytes=(int)($body['bytes'] ?? 0);$core=(string)($body['core'] ?? '');$engine=(string)($body['engine'] ?? '');
        if ($bytes<1 || $bytes>self::MAX_BYTES || !preg_match('/^[a-zA-Z0-9_.-]{1,80}$/D',$core) || strlen($engine)>80) throw new DomainException('Unsupported save state (maximum 32 MiB).');
        if ((string)($body['signature'] ?? '')!==MediaFacts::sig($item)) throw new DomainException('The game file changed. Reopen this game before creating another save.');
        self::collect($user);
        $dir=self::directory($user).'/_uploads';if (!is_dir($dir)) @mkdir($dir,0775,true);
        $pending=0;
        foreach (glob($dir.'/*',GLOB_ONLYDIR) ?: [] as $old) {
            if (!preg_match('/^[a-f0-9]{32}$/D',basename($old))) continue;
            if (filemtime($old)<time()-21600) self::clearUpload($old);else $pending++;
        }
        if ($pending>=8) throw new DomainException('Too many unfinished uploads. Retry after old uploads expire.');
        $upload=bin2hex(random_bytes(16));$path=$dir.'/'.$upload;
        if (!@mkdir($path,0775)) throw new DomainException('Cannot start save upload.');
        $current=\Vaultcast\Repository::document(self::key($user),[]);
        $previousHash=$current['games'][$id]['slots'][$slot]['hash'] ?? '';
        $meta=['previous_hash'=>$previousHash,'item_id'=>$id,'slot'=>$slot,'bytes'=>$bytes,'core'=>$core,'engine'=>$engine,'name'=>mb_substr(trim((string)($body['name'] ?? '')),0,80),
            'variant'=>($body['variant'] ?? '')==='legacy'?'legacy':'modern','threaded'=>!empty($body['threaded']),
            'signature'=>MediaFacts::sig($item),'title'=>Catalogue::fixText($item['title']),'system'=>$item['system'] ?? '', 'created_at'=>time()];
        if (@file_put_contents($path.'/meta.json',json_encode($meta),LOCK_EX)===false) throw new DomainException('Cannot write save upload.');
        return ['ok'=>true,'upload'=>$upload,'chunk_bytes'=>262144];
    }
    private static function upload(array $user,string $token): array {
        if (!preg_match('/^[a-f0-9]{32}$/D',$token)) throw new DomainException('Invalid save upload.');
        $dir=self::directory($user).'/_uploads/'.$token;
        $meta=is_file($dir.'/meta.json')?json_decode((string)file_get_contents($dir.'/meta.json'),true):null;
        if (!$meta || $meta['created_at']<time()-21600) throw new DomainException('This save upload expired.');
        return [$dir,$meta];
    }
    public static function chunk(array $user,string $token,int $part): array {
        [$dir,$meta]=self::upload($user,$token);
        $count=(int)ceil($meta['bytes']/262144);
        if ($part<0 || $part>=$count) throw new DomainException('Invalid save chunk.');
        $raw=file_get_contents('php://input',false,null,0,262145);
        $expected=min(262144,$meta['bytes']-$part*262144);
        if ($raw===false || strlen($raw)!==$expected) throw new DomainException('Incomplete save chunk.');
        if (@file_put_contents($dir.'/chunk'.$part,$raw,LOCK_EX)!==$expected) throw new DomainException('Cannot write save chunk.');
        return ['ok'=>true];
    }
    private static function clearUpload(string $dir): void {
        foreach (glob($dir.'/*') ?: [] as $file) if (preg_match('/^(?:chunk\d+|meta\.json|assembled\.state)$/D',basename($file)) && is_file($file)) @unlink($file);
        @rmdir($dir);
    }
    public static function abort(array $user,string $token): array {
        if (!self::$locked) return self::locked($user,static function() use ($user,$token){return self::abort($user,$token);});
        [$dir]=self::upload($user,$token);self::clearUpload($dir);return ['ok'=>true];
    }
    public static function finish(array $user,string $token): array {
        if (!self::$locked) return self::locked($user,static function() use ($user,$token){return self::finish($user,$token);});
        [$dir,$meta]=self::upload($user,$token);$item=self::item($user,$meta['item_id']);
        if (MediaFacts::sig($item)!==$meta['signature']) throw new DomainException('The game file changed during upload.');
        $tmp=$dir.'/assembled.state';$out=@fopen($tmp,'wb');if (!$out) throw new DomainException('Cannot assemble save state.');
        try {
            for ($i=0;$i<(int)ceil($meta['bytes']/262144);$i++) {
                $file=$dir.'/chunk'.$i;$expected=min(262144,$meta['bytes']-$i*262144);
                if (!is_file($file) || filesize($file)!==$expected) throw new DomainException('A save chunk is missing. Retry the upload.');
                $in=fopen($file,'rb');try {if (stream_copy_to_stream($in,$out)!==$expected) throw new DomainException('Cannot assemble save state.');}finally {fclose($in);}
            }
        }finally {fclose($out);}
        $hash=hash_file('sha256',$tmp);$dest=self::directory($user).'/'.$hash.'.state';
        $current=\Vaultcast\Repository::document(self::key($user),[]);$currentHash=$current['games'][$meta['item_id']]['slots'][$meta['slot']]['hash'] ?? '';
        if (array_key_exists('previous_hash',$meta) && $currentHash!==$meta['previous_hash'] && $currentHash!==$hash) throw new DomainException('This save slot changed during upload. The local save is retained; review the server slot before retrying.');
        if (is_file($dest) && hash_file('sha256',$dest)===$hash) @unlink($tmp);
        elseif (!@rename($tmp,$dest)) throw new DomainException('Cannot install save state.');
        $save=['hash'=>$hash,'bytes'=>$meta['bytes'],'core'=>$meta['core'],'engine'=>$meta['engine'],'signature'=>$meta['signature'],
            'variant'=>$meta['variant'],'threaded'=>$meta['threaded'],
            'name'=>$meta['slot']==='auto'?'Exit autosave':($meta['name'] ?: 'Save '.$meta['slot']),'updated_at'=>time()];
        try {self::install($user,$meta['item_id'],$meta['slot'],$save,$meta['title'],$meta['system']);}
        catch (Throwable $e) {self::collect($user);throw $e;}
        finally {self::clearUpload($dir);}
        return ['ok'=>true,'save'=>$save];
    }
    private static function install(array $user,string $id,string $slot,array $save,string $title,string $system): void {
        if (!\Vaultcast\Repository::user((string)$user['id'],true)) throw new DomainException('This account no longer exists.');
        HomeHub::mutate(self::key($user),static function($data) use ($id,$slot,$save,$title,$system){
            $data['games'][$id]['title']=$title;$data['games'][$id]['system']=$system;$data['games'][$id]['slots'][$slot]=$save;
            if (self::used($data)>self::QUOTA) throw new DomainException('Cloud save storage is full. Delete older saves in Account.');
            return $data;
        });self::collect($user);
    }
    private static function used(array $data): int {
        $files=[];foreach ($data['games'] ?? [] as $game) foreach ($game['slots'] as $save) $files[$save['hash']]=(int)$save['bytes'];
        foreach ($data['shares'] ?? [] as $share) if ($share['expires_at']>time()) $files[$share['hash']]=(int)($share['bytes'] ?? 0);
        return array_sum($files);
    }
    public static function revoke(array $user,string $token): array {
        if (!self::$locked) return self::locked($user,static function() use ($user,$token){return self::revoke($user,$token);});
        if (!preg_match('/^[a-f0-9]{32}$/D',$token)) throw new DomainException('Invalid shared save.');
        HomeHub::mutate(self::key($user),static function($data) use ($token){unset($data['shares'][$token]);return $data;});
        self::collect($user);return ['ok'=>true];
    }
    private static function collect(array $user): void {
        $data=\Vaultcast\Repository::document(self::key($user),[]);$keep=[];
        foreach ($data['games'] ?? [] as $game) foreach ($game['slots'] as $save) $keep[$save['hash']]=true;
        foreach ($data['shares'] ?? [] as $share) if ($share['expires_at']>time()) $keep[$share['hash']]=true;
        foreach (glob(self::directory($user).'/*.state') ?: [] as $file) {
            $hash=basename($file,'.state');if (!isset($keep[$hash])) @unlink($file);
        }
    }
    public static function edit(array $user,array $body): array {
        if (!self::$locked) return self::locked($user,static function() use ($user,$body){return self::edit($user,$body);});
        $id=(string)($body['item_id'] ?? '');$slot=(string)($body['slot'] ?? '');self::slot($slot);
        HomeHub::mutate(self::key($user),static function($data) use ($id,$slot,$body){
            if (!isset($data['games'][$id]['slots'][$slot])) throw new DomainException('Save state not found.');
            if (!empty($body['delete'])) {unset($data['games'][$id]['slots'][$slot]);if (!$data['games'][$id]['slots']) unset($data['games'][$id]);}
            elseif ($slot!=='auto') {$name=mb_substr(trim((string)($body['name'] ?? '')),0,80);if ($name==='') throw new DomainException('Choose a save name.');$data['games'][$id]['slots'][$slot]['name']=$name;}
            return $data;
        });self::collect($user);return ['ok'=>true];
    }
    private static function own(array $user,string $id,string $slot): array {
        self::slot($slot);$data=\Vaultcast\Repository::document(self::key($user),[]);$save=$data['games'][$id]['slots'][$slot] ?? null;
        if (!$save) throw new DomainException('Save state not found.');return $save;
    }
    public static function share(array $user,array $body): array {
        if (!self::$locked) return self::locked($user,static function() use ($user,$body){return self::share($user,$body);});
        $id=(string)($body['item_id'] ?? '');$slot=(string)($body['slot'] ?? '');$item=self::item($user,$id);$save=self::own($user,$id,$slot);
        $data=\Vaultcast\Repository::document(self::key($user),[]);foreach ($data['shares'] ?? [] as $token=>$shared) if ($shared['hash']===$save['hash'] && ($shared['item_id'] ?? '')===$id && $shared['expires_at']>time()) return ['ok'=>true,'token'=>$token,'expires_at'=>$shared['expires_at']];
        $token=bin2hex(random_bytes(16));$expires=time()+7*86400;
        HomeHub::mutate(self::key($user),static function($data) use ($token,$save,$expires,$id){
            $data['shares']=array_filter($data['shares'] ?? [],static function($share){return $share['expires_at']>time();});
            if (count($data['shares'])>=40) throw new DomainException('You already have 40 active shared saves.');
            $data['shares'][$token]=['hash'=>$save['hash'],'expires_at'=>$expires,'bytes'=>$save['bytes'],'item_id'=>$id,'name'=>$save['name']];return $data;
        });
        \Vaultcast\Repository::putDocument('shared-save:'.$token,['owner'=>$user['id'],'item_id'=>$id,'save'=>$save,'expires_at'=>$expires,'title'=>Catalogue::fixText($item['title'])]);
        return ['ok'=>true,'token'=>$token,'expires_at'=>$expires];
    }
    public static function shared(array $user,string $token): array {
        if (!preg_match('/^[a-f0-9]{32}$/D',$token)) throw new DomainException('Invalid shared save.');
        $share=\Vaultcast\Repository::document('shared-save:'.$token,[]);
        if (!$share || $share['expires_at']<time()) throw new DomainException('This shared save expired.');
        self::item($user,$share['item_id']);
        $owner=\Vaultcast\Repository::document('game-saves:'.$share['owner'],[]);
        if (empty($owner['shares'][$token])) throw new DomainException('This shared save was revoked.');
        return $share;
    }
    public static function import(array $user,array $body): array {
        if (!self::$locked) return self::locked($user,static function() use ($user,$body){return self::import($user,$body);});
        $share=self::shared($user,(string)($body['token'] ?? ''));$slot=(string)($body['slot'] ?? '');
        if (!in_array($slot,['1','2'],true)) throw new DomainException('Import into manual slot 1 or 2.');
        $item=self::item($user,$share['item_id']);$save=$share['save'];
        if ($save['signature']!==MediaFacts::sig($item)) throw new DomainException('This save belongs to a different version of the game file.');
        $source=self::directory(['id'=>$share['owner']]).'/'.$save['hash'].'.state';$dest=self::directory($user).'/'.$save['hash'].'.state';
        if (!is_file($source)) throw new DomainException('Shared save file is unavailable.');
        if ($source!==$dest && (!is_file($dest) || hash_file('sha256',$dest)!==$save['hash'])) {
            $temporary=$dest.'.import-'.bin2hex(random_bytes(8));
            try {
                if (!@copy($source,$temporary) || hash_file('sha256',$temporary)!==$save['hash'] || !@rename($temporary,$dest)) throw new DomainException('Cannot copy this shared save. Retry the import.');
            } finally {if (is_file($temporary)) @unlink($temporary);}
        }
        $save['updated_at']=time();try {self::install($user,$share['item_id'],$slot,$save,$share['title'],(string)($item['system'] ?? ''));}catch (Throwable $e) {self::collect($user);throw $e;}return ['ok'=>true];
    }
    public static function download(array $user,string $id,string $slot,string $share=''): void {
        $owner=$user;
        if ($share!=='') {$shared=self::shared($user,$share);$owner=['id'=>$shared['owner']];$save=$shared['save'];}
        else {$save=self::own($user,$id,$slot);if (!empty($_GET['hash']) && $_GET['hash']!==$save['hash']) throw new DomainException('This save slot changed. Refresh the save list before loading.');}
        $path=self::directory($owner).'/'.$save['hash'].'.state';$stream=@fopen($path,'rb');if (!$stream) throw new DomainException('Save file missing.');
        header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="vaultcast-'.$save['hash'].'.state"');
        header('Cache-Control: private, no-store');header('Content-Length: '.fstat($stream)['size']);header('X-Content-Type-Options: nosniff');
        fpassthru($stream);fclose($stream);exit;
    }
}
