<?php
declare(strict_types=1);

final class Chat
{
    private const PUBLIC_USER_COLUMNS = "u.id,u.username,COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(u.payload,'$.display_name')),'null'),'') AS display_name,COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(u.payload,'$.avatar_version')),'null'),'') AS avatar_version";
    private static function db(): PDO { return \Vaultcast\Database::connection(); }
    private static function query(string $sql,array $args=[]): PDOStatement { $s=self::db()->prepare($sql);$s->execute($args);return $s; }
    private static function friends(array $user): array {
        $d=\Vaultcast\Repository::document('home-hub:'.$user['id'],[]);
        return array_keys(array_filter($d['friends'] ?? [],static function($v){return $v==='friend';}));
    }
    public static function identity(array $u): array {return ['id'=>$u['id'],'name'=>($u['display_name'] ?? '') ?: $u['username'],'username'=>$u['username'],'avatar_url'=>Profiles::avatarUrl($u)];}
    public static function profile(array $user,string $id): array {
        $other=Auth::findById($id);if (!$other) throw new DomainException('Account no longer exists.');
        $d=\Vaultcast\Repository::document('home-hub:'.$user['id'],[]);$relation=$id===$user['id']?'self':($d['friends'][$id] ?? 'none');
        $out=['ok'=>true,'profile'=>self::identity($other),'relationship'=>$relation,'full'=>$relation==='friend'||$relation==='self'];
        if ($out['full']) {$their=\Vaultcast\Repository::document('home-hub:'.$id,[]);$games=array_values($their['games'] ?? []);$out['stats']=['games'=>count($games),'seconds'=>array_sum(array_column($games,'seconds'))];}
        return $out;
    }
    private static function general(): void {
        self::query("INSERT IGNORE INTO vaultcast_chat_rooms (id,kind,title,owner_id,retention,updated_at) VALUES ('general','public','General','',86400,?)",[time()]);
    }
    private static function room(string $id,array $user,bool $member=true): array {
        $r=self::query('SELECT * FROM vaultcast_chat_rooms WHERE id=?',[$id])->fetch();if (!$r) throw new DomainException('Conversation no longer exists.');
        $m=self::query('SELECT * FROM vaultcast_chat_members WHERE room_id=? AND user_id=?',[$id,$user['id']])->fetch();
        if ($m && $m['banned']) throw new DomainException('You have been removed from this room.');
        if (in_array($r['kind'],['dm','mail'],true)) {
            $other=$r['owner_id']===$user['id']?$r['peer_id']:$r['owner_id'];
            if (!in_array($user['id'],[$r['owner_id'],$r['peer_id']],true)||!in_array($other,self::friends($user),true)||!Auth::findById($other)) throw new DomainException('Private conversations are only available between accepted friends.');
        } elseif (($member || $r['kind']==='private') && !$m) throw new DomainException('Join this room first, or ask its owner for an invitation.');
        $r['member']=$m ?: null;$r['can_manage']=($user['role'] ?? '')==='admin'||$r['owner_id']===$user['id'];return $r;
    }
    public static function pulse(array $user): array {
        self::cleanup();
        self::query('INSERT INTO vaultcast_chat_presence (user_id,seen_at) VALUES (?,?) ON DUPLICATE KEY UPDATE seen_at=VALUES(seen_at)',[$user['id'],time()]);return self::summary($user);
    }
    public static function summary(array $user): array {
        $friends=self::friends($user);if (!$friends) return ['ok'=>true,'updates'=>[]];
        $marks=implode(',',array_fill(0,count($friends),'?'));
        $args=[$user['id'],time(),$user['id'],$user['id']];$args=array_merge($args,$friends,[$user['id']],$friends);
        $rows=self::query("SELECT r.id,r.title,r.kind,(SELECT x.id FROM vaultcast_chat_messages x WHERE x.room_id=r.id AND x.id>m.read_id AND x.sender_id<>? AND (x.expires_at=0 OR x.expires_at>?) ORDER BY x.id DESC LIMIT 1) AS latest FROM vaultcast_chat_members m JOIN vaultcast_chat_rooms r ON r.id=m.room_id WHERE m.user_id=? AND m.muted=0 AND m.banned=0 AND r.kind IN ('dm','mail') AND ((r.owner_id=? AND r.peer_id IN ($marks)) OR (r.peer_id=? AND r.owner_id IN ($marks))) HAVING latest IS NOT NULL ORDER BY r.updated_at DESC LIMIT 10",$args)->fetchAll();
        return ['ok'=>true,'updates'=>$rows];
    }
    public static function removeUser(string $id): void {
        self::query('UPDATE vaultcast_chat_rooms r JOIN (SELECT DISTINCT room_id FROM vaultcast_chat_messages WHERE sender_id=?) x ON x.room_id=r.id SET r.revision=r.revision+1',[$id]);
        self::query('DELETE FROM vaultcast_chat_messages WHERE sender_id=?',[$id]);
        self::query("DELETE x FROM vaultcast_chat_messages x JOIN vaultcast_chat_rooms r ON r.id=x.room_id WHERE r.kind IN ('dm','mail') AND (r.owner_id=? OR r.peer_id=?)",[$id,$id]);
        self::query("DELETE m FROM vaultcast_chat_members m JOIN vaultcast_chat_rooms r ON r.id=m.room_id WHERE r.kind IN ('dm','mail') AND (r.owner_id=? OR r.peer_id=?)",[$id,$id]);
        self::query("DELETE FROM vaultcast_chat_rooms WHERE kind IN ('dm','mail') AND (owner_id=? OR peer_id=?)",[$id,$id]);
        self::query('DELETE FROM vaultcast_chat_members WHERE user_id=?',[$id]);self::query('DELETE FROM vaultcast_chat_presence WHERE user_id=?',[$id]);
        self::query("UPDATE vaultcast_chat_rooms SET owner_id='',revision=revision+1 WHERE owner_id=?",[$id]);
    }
    public static function listing(array $user,bool $mail=false,string $focus=''): array {
        self::general();$friends=self::friends($user);$people=[];
        if ($friends) {
            $marks=implode(',',array_fill(0,count($friends),'?'));
            foreach (self::query('SELECT '.self::PUBLIC_USER_COLUMNS.',COALESCE(p.seen_at,0) AS seen_at FROM vaultcast_users u LEFT JOIN vaultcast_chat_presence p ON p.user_id=u.id WHERE u.id IN ('.$marks.') ORDER BY u.username',$friends) as $r) {$people[]=array_merge(self::identity($r),['online'=>(int)$r['seen_at']>time()-75]);}
        }
        $rows=self::query("SELECT r.*,m.read_id,m.muted,m.banned,(SELECT COUNT(*) FROM vaultcast_chat_messages x WHERE x.room_id=r.id AND x.id>COALESCE(m.read_id,0) AND x.sender_id<>? AND (x.expires_at=0 OR x.expires_at>?)) AS unread FROM vaultcast_chat_rooms r LEFT JOIN vaultcast_chat_members m ON m.room_id=r.id AND m.user_id=? WHERE (r.kind='public' OR m.user_id IS NOT NULL) AND ".($mail?"r.kind='mail'":"r.kind<>'mail'")." AND COALESCE(m.banned,0)=0 ORDER BY (r.id='general') DESC,(r.id=?) DESC,(m.user_id IS NOT NULL) DESC,r.updated_at DESC LIMIT 150",[$user['id'],time(),$user['id'],$focus])->fetchAll();
        $rows=array_values(array_filter($rows,static function($r) use ($user,$friends){return !in_array($r['kind'],['dm','mail'],true)||in_array($r['owner_id']===$user['id']?$r['peer_id']:$r['owner_id'],$friends,true);}));
        foreach ($rows as &$r) { $r['joined']=$r['read_id']!==null;$r['unread']=(int)$r['unread'];$r['muted']=(bool)$r['muted'];unset($r['banned']); }
        return ['ok'=>true,'rooms'=>$rows,'friends'=>$people,'server_time'=>time()];
    }
    public static function create(array $user,array $body): array {
        $kind=(string)($body['kind'] ?? 'public');if (!in_array($kind,['public','private','dm','mail'],true)) throw new DomainException('Invalid conversation type.');
        $title=trim((string)($body['title'] ?? ''));$peer=(string)($body['peer_id'] ?? '');
        if (in_array($kind,['dm','mail'],true)) {if (!in_array($peer,self::friends($user),true)||!Auth::findById($peer)) throw new DomainException('Choose an accepted friend.');}
        if (in_array($kind,['public','private'],true)) $peer='';
        if ($kind==='dm') {$pair=[$user['id'],$peer];sort($pair);$id='dm-'.substr(hash('sha256',implode(':',$pair)),0,48);$title='Direct chat';}
        else {$token=(string)($body['nonce'] ?? '');$id=preg_match('/^[a-f0-9]{32}$/D',$token)?substr(hash('sha256',$user['id'].':'.$token),0,32):bin2hex(random_bytes(16));if ($title===''||mb_strlen($title)>100) throw new DomainException('Use a name or subject of 1–100 characters.');}
        $ttl=$kind==='mail'?0:self::retention($body['retention'] ?? 86400);
        \Vaultcast\Database::transaction(static function() use ($user,$kind,$title,$peer,$id,$ttl){
            // Serialize room creation per account, including concurrent browser tabs.
            if (!self::query('SELECT id FROM vaultcast_users WHERE id=? FOR UPDATE',[$user['id']])->fetchColumn()) throw new DomainException('Account no longer exists.');
            $existing=self::query('SELECT * FROM vaultcast_chat_rooms WHERE id=?',[$id])->fetch();
            if ($existing && ($existing['kind']!==$kind || ($kind!=='dm' && ($existing['owner_id']!==$user['id'] || $existing['peer_id']!==$peer)))) throw new DomainException('This creation token belongs to a different conversation. Reopen the form.');
            if ($kind!=='dm' && !self::query('SELECT id FROM vaultcast_chat_rooms WHERE id=? AND owner_id=?',[$id,$user['id']])->fetchColumn() && (int)self::query("SELECT COUNT(*) FROM vaultcast_chat_rooms WHERE owner_id=? AND kind=?",[$user['id'],$kind])->fetchColumn()>=($kind==='mail'?100:10)) throw new DomainException('Conversation limit reached. Remove an old room or thread first.');
            self::query('INSERT IGNORE INTO vaultcast_chat_rooms (id,kind,title,owner_id,peer_id,retention,updated_at) VALUES (?,?,?,?,?,?,?)',[$id,$kind,$title,$user['id'],$peer,$ttl,time()]);
            self::query('INSERT IGNORE INTO vaultcast_chat_members (room_id,user_id) VALUES (?,?)',[$id,$user['id']]);
            if ($peer!=='') self::query('INSERT IGNORE INTO vaultcast_chat_members (room_id,user_id) VALUES (?,?)',[$id,$peer]);
        });return ['ok'=>true,'id'=>$id];
    }
    private static function retention($value): int { $v=(int)$value;if (!in_array($v,[0,3600,21600,86400,604800,2592000],true)) throw new DomainException('Choose a supported expiry time.');return $v; }
    public static function join(array $user,string $id): array {
        $r=self::room($id,$user,false);
        \Vaultcast\Database::transaction(static function() use ($user,$id,$r){
            self::query('SELECT id FROM vaultcast_chat_rooms WHERE id=? FOR UPDATE',[$id]);
            if ($id!=='general' && (int)self::query('SELECT COUNT(*) FROM vaultcast_chat_members WHERE room_id=? AND banned=0',[$id])->fetchColumn()>=100 && !$r['member']) throw new DomainException('This room is full.');
            self::query('INSERT IGNORE INTO vaultcast_chat_members (room_id,user_id) VALUES (?,?)',[$id,$user['id']]);
        });return ['ok'=>true];
    }
    public static function history(array $user,string $id,int $revision,int $before): array {
        $r=self::room($id,$user);$members=[];
        $rows=self::query('SELECT '.self::PUBLIC_USER_COLUMNS.',m.seen_at,m.typing_until,m.read_id FROM vaultcast_chat_members m JOIN vaultcast_users u ON u.id=m.user_id WHERE m.room_id=? AND m.banned=0 ORDER BY m.seen_at DESC LIMIT 100',[$id]);
        foreach ($rows as $row) $members[]=array_merge(self::identity($row),['online'=>(int)$row['seen_at']>time()-50,'typing'=>(int)$row['typing_until']>time(),'read_id'=>$r['kind']==='dm'?(int)$row['read_id']:0]);
        unset($r['member']);$out=['ok'=>true,'room'=>$r,'members'=>$members,'server_time'=>time(),'unchanged'=>$before===0 && (int)$r['revision']===$revision];
        if ($out['unchanged']) return $out;
        $where=$before>0?' AND id<?':'';$args=[$id,time()];if ($before>0)$args[]=$before;
        $messages=self::query('SELECT * FROM vaultcast_chat_messages WHERE room_id=? AND (expires_at=0 OR expires_at>?)'.$where.' ORDER BY id DESC LIMIT 51',$args)->fetchAll();
        $out['has_more']=count($messages)>50;$messages=array_slice($messages,0,50);$authors=[];
        $ids=array_values(array_unique(array_column($messages,'sender_id')));
        if ($ids) foreach (self::query('SELECT '.self::PUBLIC_USER_COLUMNS.' FROM vaultcast_users u WHERE u.id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids) as $u) {$authors[$u['id']]=self::identity($u);}
        foreach ($messages as &$m) {$m['author']=$authors[$m['sender_id']] ?? ['id'=>$m['sender_id'],'name'=>'Deleted account','avatar_url'=>null];$m['id']=(int)$m['id'];$m['created_at']=(int)$m['created_at'];$m['expires_at']=(int)$m['expires_at'];unset($m['nonce']);}
        $out['messages']=array_reverse($messages);return $out;
    }
    public static function change(array $user,string $id,array $body): array {
        return \Vaultcast\Database::transaction(static function() use ($user,$id,$body){
            self::query('SELECT id FROM vaultcast_chat_rooms WHERE id=? FOR UPDATE',[$id]);
            if (($body['action'] ?? '')==='send') self::query('SELECT name FROM vaultcast_documents WHERE name=? FOR UPDATE',['home-hub:'.$user['id']]);
            $r=self::room($id,$user);$action=$body['action'] ?? '';
            if ($action==='pulse') {self::query('UPDATE vaultcast_chat_members SET seen_at=?,typing_until=?,read_id=GREATEST(read_id,?) WHERE room_id=? AND user_id=?',[time(),!empty($body['typing'])?time()+6:0,max(0,(int)($body['read_id'] ?? 0)),$id,$user['id']]);return ['ok'=>true];}
            if ($action==='mute') {self::query('UPDATE vaultcast_chat_members SET muted=? WHERE room_id=? AND user_id=?',[!empty($body['muted'])?1:0,$id,$user['id']]);return ['ok'=>true];}
            if ($action==='send') {
                $text=trim((string)($body['text'] ?? ''));$attachment=(string)($body['attachment'] ?? '');$nonce=(string)($body['nonce'] ?? '');
                if (mb_strlen($text)>4000 || ($text===''&&$attachment==='') || !preg_match('/^[a-f0-9]{32}$/D',$nonce)) throw new DomainException('Write a message of up to 4,000 characters or attach an image.');
                $prior=self::query('SELECT id,room_id FROM vaultcast_chat_messages WHERE sender_id=? AND nonce=?',[$user['id'],$nonce])->fetch();if ($prior) {if ($prior['room_id']!==$id) throw new DomainException('Duplicate message token.');return ['ok'=>true,'id'=>(int)$prior['id']];}
                if (!self::query('SELECT id FROM vaultcast_users WHERE id=? FOR UPDATE',[$user['id']])->fetchColumn()) throw new DomainException('Account no longer exists.');
                if ((int)self::query('SELECT COUNT(*) FROM vaultcast_chat_messages WHERE sender_id=? AND created_at>?',[$user['id'],time()-60])->fetchColumn()>=30) throw new DomainException('Please slow down: up to 30 messages per minute.');
                if ($attachment!=='') self::validateFile($user,$id,$attachment);
                self::query('INSERT INTO vaultcast_chat_messages (room_id,sender_id,body,attachment,nonce,created_at,expires_at) VALUES (?,?,?,?,?,?,?)',[$id,$user['id'],$text,$attachment,$nonce,time(),$r['retention']?time()+(int)$r['retention']:0]);$mid=(int)self::db()->lastInsertId();
                if ($attachment!=='') self::query('UPDATE vaultcast_chat_files SET message_id=? WHERE id=?',[$mid,$attachment]);
                self::query('UPDATE vaultcast_chat_rooms SET revision=revision+1,updated_at=? WHERE id=?',[time(),$id]);
                if (in_array($r['kind'],['dm','mail'],true)) foreach ([$r['owner_id'],$r['peer_id']] as $participant) self::query('INSERT IGNORE INTO vaultcast_chat_members (room_id,user_id) VALUES (?,?)',[$id,$participant]);
                $cutoff=self::query('SELECT id FROM vaultcast_chat_messages WHERE room_id=? ORDER BY id DESC LIMIT 1 OFFSET 1999',[$id])->fetchColumn();
                if ($cutoff) self::query('DELETE FROM vaultcast_chat_messages WHERE room_id=? AND id<? LIMIT 100',[$id,$cutoff]);
                return ['ok'=>true,'id'=>$mid];
            }
            if ($action==='edit'||$action==='delete') {
                $m=self::query('SELECT * FROM vaultcast_chat_messages WHERE id=? AND room_id=?',[(int)($body['id'] ?? 0),$id])->fetch();
                if (!$m || ($m['sender_id']!==$user['id'] && ($action==='edit'||!$r['can_manage']))) throw new DomainException('You cannot change this message.');
                if ($action==='delete') self::query('DELETE FROM vaultcast_chat_messages WHERE id=?',[$m['id']]);
                else {$text=trim((string)($body['text'] ?? ''));if (mb_strlen($text)>4000||($text===''&&$m['attachment']==='')) throw new DomainException('Invalid message text.');self::query('UPDATE vaultcast_chat_messages SET body=?,edited_at=? WHERE id=?',[$text,time(),$m['id']]);}
            } elseif ($action==='leave') {
                if ($id==='general') throw new DomainException('General is always available. You can mute it.');
                if ($r['owner_id']===$user['id'] && !in_array($r['kind'],['dm','mail'],true)) throw new DomainException('Delete your room instead of leaving it.');
                self::query('DELETE FROM vaultcast_chat_members WHERE room_id=? AND user_id=?',[$id,$user['id']]);return ['ok'=>true];
            } elseif ($action==='settings') {
                if (!$r['can_manage']) throw new DomainException('Only the owner or an administrator can manage this room.');
                $ttl=self::retention($body['retention'] ?? $r['retention']);$title=$id==='general'?'General':trim((string)($body['title'] ?? $r['title']));
                if ($title===''||mb_strlen($title)>100) throw new DomainException('Use a name of 1–100 characters.');
                self::query('DELETE FROM vaultcast_chat_messages WHERE room_id=? AND expires_at>0 AND expires_at<=?',[$id,time()]);
                self::query('UPDATE vaultcast_chat_rooms SET title=?,retention=? WHERE id=?',[$title,$ttl,$id]);
                self::query('UPDATE vaultcast_chat_messages SET expires_at=CASE WHEN ?=0 THEN 0 ELSE created_at+? END WHERE room_id=?',[$ttl,$ttl,$id]);
            } elseif ($action==='invite'||$action==='kick') {
                if (($id==='general' && $action==='kick')||!$r['can_manage']||in_array($r['kind'],['dm','mail'],true)) throw new DomainException('Only room managers can change members.');
                $target=(string)($body['user_id'] ?? '');if ($target===$user['id']||!Auth::findById($target)) throw new DomainException('Choose another account.');
                if ($action==='invite' && !in_array($target,self::friends($user),true)) throw new DomainException('You can invite accepted friends only.');
                if ($action==='invite' && (int)self::query('SELECT COUNT(*) FROM vaultcast_chat_members WHERE room_id=? AND banned=0',[$id])->fetchColumn()>=100) throw new DomainException('Room member limit reached.');
                self::query('INSERT INTO vaultcast_chat_members (room_id,user_id,banned) VALUES (?,?,?) ON DUPLICATE KEY UPDATE banned=VALUES(banned)',[$id,$target,$action==='kick'?1:0]);
            } elseif ($action==='delete-room') {
                if ($id==='general'||!$r['can_manage']) throw new DomainException('This room cannot be deleted.');
                self::query('DELETE FROM vaultcast_chat_messages WHERE room_id=?',[$id]);self::query('DELETE FROM vaultcast_chat_members WHERE room_id=?',[$id]);self::query('DELETE FROM vaultcast_chat_rooms WHERE id=?',[$id]);return ['ok'=>true];
            } else throw new DomainException('Unknown conversation action.');
            self::query('UPDATE vaultcast_chat_rooms SET revision=revision+1 WHERE id=?',[$id]);return ['ok'=>true];
        });
    }
    private static function filePath(string $id): string {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new DomainException('Invalid attachment.');
        $dir=STREAM_DATA.'/chat/'.substr($id,0,2);if (!is_dir($dir) && !@mkdir($dir,0770,true)) throw new DomainException('Cannot create attachment storage.');return $dir.'/'.$id.'.bin';
    }
    public static function upload(array $user,string $room,array $body): array {
        self::room($room,$user);$bytes=(int)($body['bytes'] ?? 0);if ($bytes<1||$bytes>4194304) throw new DomainException('Images and GIFs must be 4 MiB or smaller.');
        return \Vaultcast\Database::transaction(static function() use ($user,$room,$bytes){
            if (!self::query('SELECT id FROM vaultcast_users WHERE id=? FOR UPDATE',[$user['id']])->fetchColumn()) throw new DomainException('Account no longer exists.');
            if ((int)self::query('SELECT COALESCE(SUM(bytes),0) FROM vaultcast_chat_files WHERE user_id=? AND created_at>?',[$user['id'],time()-86400])->fetchColumn()+$bytes>33554432) throw new DomainException('Daily image allowance reached (32 MiB).');
            $id=bin2hex(random_bytes(16));self::query('INSERT INTO vaultcast_chat_files (id,room_id,user_id,bytes,created_at) VALUES (?,?,?,?,?)',[$id,$room,$user['id'],$bytes,time()]);return ['ok'=>true,'id'=>$id,'chunk_bytes'=>262144];
        });
    }
    public static function uploadedImage(array $user,string $room,array $file): string {
        if(!is_uploaded_file((string)($file['tmp_name'] ?? '')) || ($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new DomainException('Image upload failed.');
        $bytes=(int)filesize($file['tmp_name']);$info=@getimagesize($file['tmp_name']);
        if($bytes<1 || $bytes>4194304 || !$info || !in_array($info['mime'],['image/jpeg','image/png','image/gif','image/webp'],true) || $info[0]*$info[1]>16777216)throw new DomainException('Choose an image up to 4 MiB and 16 megapixels.');
        $upload=self::upload($user,$room,['bytes'=>$bytes]);$id=$upload['id'];
        if(!move_uploaded_file($file['tmp_name'],self::filePath($id))){self::query('DELETE FROM vaultcast_chat_files WHERE id=? AND user_id=?',[$id,$user['id']]);throw new DomainException('Could not store the image.');}
        self::query('UPDATE vaultcast_chat_files SET received=?,mime=? WHERE id=? AND user_id=?',[$bytes,$info['mime'],$id,$user['id']]);return $id;
    }
    public static function chunk(array $user,string $id,int $part): array {
        $data=file_get_contents('php://input',false,null,0,262145);if ($data===false||strlen($data)>262144) throw new DomainException('Invalid image chunk.');
        return \Vaultcast\Database::transaction(static function() use ($user,$id,$part,$data){
            $f=self::query('SELECT * FROM vaultcast_chat_files WHERE id=? AND user_id=? FOR UPDATE',[$id,$user['id']])->fetch();
            if (!$f||$f['message_id']||$f['created_at']<time()-3600) throw new DomainException('This upload expired.');self::room($f['room_id'],$user);
            $offset=$part*262144;$expected=min(262144,(int)$f['bytes']-$offset);
            if ($part<0||$expected<1||strlen($data)!==$expected||$offset>(int)$f['received']) throw new DomainException('Incomplete image upload.');
            $path=self::filePath($id);$h=fopen($path,'c+b');if (!$h) throw new DomainException('Cannot store image.');
            try {fseek($h,$offset);if (fwrite($h,$data)!==$expected) throw new DomainException('Cannot store image.');} finally {fclose($h);}
            self::query('UPDATE vaultcast_chat_files SET received=GREATEST(received,?) WHERE id=?',[$offset+$expected,$id]);return ['ok'=>true];
        });
    }
    private static function validateFile(array $user,string $room,string $id): void {
        $f=self::query('SELECT * FROM vaultcast_chat_files WHERE id=? FOR UPDATE',[$id])->fetch();
        if (!$f||$f['user_id']!==$user['id']||$f['room_id']!==$room||$f['message_id']||(int)$f['received']!==(int)$f['bytes']||$f['created_at']<time()-3600) throw new DomainException('Image upload is incomplete or expired.');
        $info=@getimagesize(self::filePath($id));if (!$info||!in_array($info['mime'],['image/jpeg','image/png','image/gif','image/webp'],true)||$info[0]*$info[1]>16777216) throw new DomainException('Choose a valid JPEG, PNG, GIF or WebP image up to 16 megapixels.');
        self::query('UPDATE vaultcast_chat_files SET mime=? WHERE id=?',[$info['mime'],$id]);
    }
    public static function file(array $user,string $id): void {
        $f=self::query('SELECT f.*,m.expires_at FROM vaultcast_chat_files f JOIN vaultcast_chat_messages m ON m.id=f.message_id WHERE f.id=?',[$id])->fetch();
        if (!$f||($f['expires_at'] && $f['expires_at']<=time())) json_error('Image no longer available.',404);self::room($f['room_id'],$user);
        $path=self::filePath($id);if (!is_file($path)) json_error('Image unavailable.',404);
        header('Content-Type: '.$f['mime']);header('Content-Length: '.filesize($path));header('Cache-Control: private, no-store');header("Content-Security-Policy: default-src 'none'; sandbox");header('X-Content-Type-Options: nosniff');if (($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='HEAD') readfile($path);exit;
    }
    public static function resetFiles(): void {
        $root=STREAM_DATA.'/chat';
        foreach (glob($root.'/*',GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_link($dir)||!preg_match('/^[a-f0-9]{2}$/D',basename($dir))) continue;
            foreach (glob($dir.'/*.bin') ?: [] as $file) if (!is_link($file) && preg_match('/^[a-f0-9]{32}\.bin$/D',basename($file))) @unlink($file);
            @rmdir($dir);
        }
    }
    public static function cleanup(): void {
        if (\Vaultcast\Repository::cacheGet('chat-gc')) return;
        $lock=@fopen(STREAM_DATA.'/chat-gc.lock','c');if (!$lock) return;
        try {
            if (!flock($lock,LOCK_EX|LOCK_NB) || \Vaultcast\Repository::cacheGet('chat-gc')) return;
            \Vaultcast\Repository::cachePut('chat-gc',['at'=>time()],60);
            self::query('DELETE FROM vaultcast_chat_messages WHERE expires_at>0 AND expires_at<=? LIMIT 200',[time()]);
            // Rotate through a bounded primary-key window instead of scanning all stored images.
            $cursor=\Vaultcast\Repository::cacheGet('chat-gc-cursor')['id'] ?? '';
            $files=self::query('SELECT f.*,m.id AS live_message FROM (SELECT id,message_id,created_at FROM vaultcast_chat_files WHERE id>? ORDER BY id LIMIT 100) f LEFT JOIN vaultcast_chat_messages m ON m.id=f.message_id ORDER BY f.id',[$cursor])->fetchAll();
            foreach ($files as $f) {
                if (($f['message_id'] && !$f['live_message']) || (!$f['message_id'] && $f['created_at']<time()-3600)) {
                    $path=self::filePath($f['id']);if (@unlink($path)||!is_file($path)) self::query('DELETE FROM vaultcast_chat_files WHERE id=?',[$f['id']]);
                }
            }
            $last=$files ? $files[count($files)-1]['id'] : '';
            \Vaultcast\Repository::cachePut('chat-gc-cursor',['id'=>$last],0);
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
}
