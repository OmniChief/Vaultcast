<?php
declare(strict_types=1);

final class XtreamImport {
    private static function settings(): array {return \Vaultcast\Repository::document('xtream-import-settings',[]);}
    public static function status(): array {
        $s=self::settings();return ['ok'=>true,'configured'=>!empty($s['url']),'width'=>$s['width']??'1270'];
    }
    private static function label(string $value): string {
        // Normalize labels only: never transliterate or case-fold account credentials.
        static $letters=null;
        if($letters===null){$letters=[];$ascii=str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz');
            foreach(['𝘼𝘽𝘾𝘿𝙀𝙁𝙂𝙃𝙄𝙅𝙆𝙇𝙈𝙉𝙊𝙋𝙌𝙍𝙎𝙏𝙐𝙑𝙒𝙓𝙔𝙕𝙖𝙗𝙘𝙙𝙚𝙛𝙜𝙝𝙞𝙟𝙠𝙡𝙢𝙣𝙤𝙥𝙦𝙧𝙨𝙩𝙪𝙫𝙬𝙭𝙮𝙯','𝗔𝗕𝗖𝗗𝗘𝗙𝗚𝗛𝗜𝗝𝗞𝗟𝗠𝗡𝗢𝗣𝗤𝗥𝗦𝗧𝗨𝗩𝗪𝗫𝗬𝗭𝗮𝗯𝗰𝗱𝗲𝗳𝗴𝗵𝗶𝗷𝗸𝗹𝗺𝗻𝗼𝗽𝗾𝗿𝘀𝘁𝘂𝘃𝘄𝘅𝘆𝘇'] as $alphabet)$letters+=array_combine(preg_split('//u',$alphabet,-1,PREG_SPLIT_NO_EMPTY),$ascii);
        }
        $value=strtr($value,$letters);
        if(class_exists('Normalizer'))$value=Normalizer::normalize($value,Normalizer::FORM_KC)?:$value;
        $value=strtolower((string)preg_replace('/[^a-zA-Z0-9]/','',$value));
        $map=['portal'=>'url','server'=>'url','serverurl'=>'url','url'=>'url','host'=>'url','username'=>'username','user'=>'username','password'=>'password','pass'=>'password','created'=>'created','createdat'=>'created','exp'=>'expiry','expiry'=>'expiry','expires'=>'expiry','expiration'=>'expiry','expirydate'=>'expiry','conn'=>'connections','connections'=>'connections','activeconnections'=>'connections','maxconn'=>'maximum','maxconnections'=>'maximum','port'=>'port','status'=>'status'];
        return $map[$value]??'';
    }
    private static function connection(array $fields,int $position): array {
        $result=['id'=>bin2hex(random_bytes(12)),'name'=>'Provider connection '.$position,'url'=>'','username'=>'','password'=>'','details'=>[],'valid'=>false,'issue'=>''];
        foreach(['created','expiry','connections','maximum','port','status'] as $key)$result['details'][$key]=mb_substr((string)($fields[$key]??''),0,160);
        try {
            $url=rtrim(trim((string)($fields['url']??'')),'/');
            $url=preg_replace('#/player_api\.php$#i','',$url);
            if(strpos($url,'*')!==false)throw new InvalidArgumentException('Server address is masked.');
            stalker_validate_url($url);$p=parse_url($url);
            $port=trim((string)($fields['port']??''));
            if($port!==''){
                if(!ctype_digit($port)||(int)$port<1||(int)$port>65535)throw new InvalidArgumentException('Invalid port.');
                if(isset($p['port'])&&(int)$p['port']!==(int)$port)throw new InvalidArgumentException('Server address and port field disagree.');
                if(!isset($p['port']))$url=$p['scheme'].'://'.$p['host'].':'.$port.($p['path']??'');
            }
            $result['url']=$url;
            foreach(['username','password'] as $key){$value=trim((string)($fields[$key]??''));
                if($value===''||preg_match('/^[*•●]+$/u',$value)||strlen($value)>512||preg_match('/[\r\n\x00]/',$value))throw new InvalidArgumentException('Username or password is missing, masked or invalid.');
                $result[$key]=$value;
            }
            $result['valid']=true;
        }catch(InvalidArgumentException $error){$result['issue']=$error->getMessage();}
        return $result;
    }
    private static function parse(string $html,string $width): array {
        if(!class_exists('DOMDocument'))throw new RuntimeException('PHP DOM support is required to read provider tables.');
        $doc=new DOMDocument();$prior=libxml_use_internal_errors(true);
        try{$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}
        finally{libxml_clear_errors();libxml_use_internal_errors($prior);}
        $xpath=new DOMXPath($doc);
        foreach($xpath->query('//script|//style|//noscript') as $node)$node->parentNode->removeChild($node);
        foreach($xpath->query('//br') as $node)$node->parentNode->replaceChild($doc->createTextNode("\n"),$node);
        $tables=$xpath->query($width===''?'//table':'//table[normalize-space(@width)="'.$width.'"]');
        if(!$tables->length)throw new RuntimeException('No matching table found. Check the table width, or leave it blank to search all tables.');
        $records=[];$examined=0;
        foreach($tables as $table){
            $columns=[];$headers=[];
            foreach($xpath->query('./tr|./tbody/tr|./thead/tr|./tfoot/tr',$table) as $row){
                $cells=[];$ambiguous=false;
                foreach($xpath->query('./td|./th',$row) as $cell){
                    if(++$examined>20000)throw new RuntimeException('The provider table is too large. Select a narrower table.');
                    $span=max(1,(int)$cell->getAttribute('colspan'));if($span>20||(int)$cell->getAttribute('rowspan')>1){$ambiguous=true;break;}
                    $text=trim(str_replace("\xc2\xa0",' ',$cell->textContent));if(strlen($text)>4096){$ambiguous=true;break;}
                    $cells[]=$text;for($i=1;$i<$span;$i++)$cells[]='';
                }
                if($ambiguous)throw new RuntimeException('The table has ambiguous merged cells. Enter these connections manually.');
                $possibleHeaders=array_map([self::class,'label'],$cells);
                if(in_array('url',$possibleHeaders,true)&&in_array('username',$possibleHeaders,true)&&in_array('password',$possibleHeaders,true)){$headers=$possibleHeaders;continue;}
                if($headers){
                    $fields=[];foreach($cells as $i=>$value)if(($headers[$i]??'')!=='')$fields[$headers[$i]]=$value;
                    if(!empty($fields['url']))$records[]=$fields;continue;
                }
                foreach($cells as $column=>$text){
                    foreach(preg_split('/\r?\n/',$text) as $line){
                        if(!preg_match('/^([^:：]+)[:：]\s*(.*)$/u',trim($line),$match))continue;
                        $key=self::label($match[1]);if($key==='')continue;
                        // Preserve empty spacer columns; flush when another account starts in a column.
                        if($key==='url'&&!empty($columns[$column]['url'])){$records[]=$columns[$column];$columns[$column]=[];}
                        if(isset($columns[$column][$key])&&$columns[$column][$key]!==trim($match[2]))throw new RuntimeException('Conflicting fields in a provider column. Enter that connection manually.');
                        $columns[$column][$key]=trim($match[2]);
                    }
                }
            }
            foreach($columns as $fields)if(!empty($fields['url']))$records[]=$fields;
        }
        $entries=[];$seen=[];
        foreach($records as $fields){$entry=self::connection($fields,count($entries)+1);$key=hash('sha256',json_encode($fields));if(isset($seen[$key]))continue;$seen[$key]=true;$entries[]=$entry;}
        return $entries;
    }
    private static function duplicate(array $entry,array $feeds): bool {
        $identity=static function(string $url): array {$p=parse_url($url);$scheme=strtolower($p['scheme']??'http');return [$scheme,strtolower($p['host']??''),(int)($p['port']??($scheme==='https'?443:80)),rtrim($p['path']??'','/')];};
        foreach($feeds as $feed)if($identity($feed['url'])===$identity($entry['url'])&&$feed['username']===$entry['username'])return true;
        return false;
    }
    private static function publicEntry(array $entry,array $feeds): array {
        return ['id'=>$entry['id'],'name'=>$entry['name'],'url'=>$entry['url'],'details'=>$entry['details'],'valid'=>$entry['valid'],'issue'=>$entry['issue'],'added'=>$entry['valid']&&self::duplicate($entry,$feeds),'credentials'=>$entry['valid']?'Stored privately':'Incomplete'];
    }
    public static function handle(array $body,array $viewer): array {
        Permissions::require('iptv_manage');$operation=(string)($body['operation']??'');$s=self::settings();
        if($operation==='configure'){
            if(!empty($body['clear'])){\Vaultcast\Repository::putDocument('xtream-import-settings',[]);return self::status();}
            $url=trim((string)($body['url']??''));if($url==='')$url=(string)($s['url']??'');$p=parse_url($url);
            if(strlen($url)>8000||!$p||!in_array(strtolower($p['scheme']??''),['http','https'],true)||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||preg_match('/[\x00-\x20]/',$url))throw new InvalidArgumentException('Enter a valid HTTP(S) provider page URL.');
            $width=trim((string)($body['width']??'1270'));if($width!==''&&!preg_match('/^[0-9]{1,5}$/D',$width))throw new InvalidArgumentException('Enter a numeric table width, or leave blank to search all tables.');
            \Vaultcast\Repository::putDocument('xtream-import-settings',['url'=>$url,'width'=>$width]);return self::status();
        }
        if($operation==='discover'){
            if(empty($s['url']))throw new InvalidArgumentException('Save a provider page URL first.');
            // Never return fetch exceptions containing a private source URL or provider body.
            try{$response=WebFetch::get($s['url'],12,2*1024*1024);}catch(Throwable $error){throw new RuntimeException('Could not fetch the provider page. Check its URL and whether it requires a browser login.');}
            if($response['status']!==200)throw new RuntimeException('Provider page returned HTTP '.(int)$response['status'].'.');
            $entries=self::parse($response['body'],$s['width']??'1270');$token=bin2hex(random_bytes(24));
            \Vaultcast\Repository::cachePut('xtream-import:'.$token,['viewer'=>(string)$viewer['id'],'settings'=>hash('sha256',json_encode($s)),'entries'=>$entries],1800);
            $feeds=xtream_connections();return ['ok'=>true,'token'=>$token,'entries'=>array_map(static function($entry)use($feeds){return self::publicEntry($entry,$feeds);},$entries)];
        }
        if(!in_array($operation,['check','add'],true))throw new InvalidArgumentException('Unknown provider import operation.');
        $preview=\Vaultcast\Repository::cacheGet('xtream-import:'.(string)($body['token']??''));
        if(!$preview||$preview['viewer']!==(string)$viewer['id']||$preview['settings']!==hash('sha256',json_encode($s)))throw new InvalidArgumentException('Discovery expired or the source changed. Fetch the table again.');
        $entry=null;foreach($preview['entries'] as $candidate)if($candidate['id']===($body['id']??'')){$entry=$candidate;break;}
        if(!$entry||!$entry['valid'])throw new InvalidArgumentException('Select a complete, unmasked connection.');
        if($operation==='check'){
            $data=xtream_api($entry);$account=$data['user_info']??[];
            if((int)($account['auth']??0)!==1)throw new RuntimeException('Provider rejected this account.');
            return ['ok'=>true,'account'=>array_intersect_key($account,array_flip(['status','exp_date','active_cons','max_connections','created_at']))];
        }
        $feeds=xtream_connections();if(self::duplicate($entry,$feeds))return ['ok'=>true,'added'=>false,'message'=>'This server and username are already saved.'];
        $name=trim((string)($body['name']??$entry['name']));if($name===''||strlen($name)>160)throw new InvalidArgumentException('Enter a connection name (up to 160 characters).');
        $id='xtream-'.bin2hex(random_bytes(6));$feeds[$id]=['id'=>$id,'name'=>$name,'url'=>$entry['url'],'username'=>$entry['username'],'password'=>$entry['password'],'enabled'=>true];
        \Vaultcast\Repository::putDocument('xtream-connections',['connections'=>$feeds]);return ['ok'=>true,'added'=>true,'message'=>'Connection added.'];
    }
}
