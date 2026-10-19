<?php
declare(strict_types=1);

final class Radio {
    public static function stations(): array {
        return array_values(\Vaultcast\Repository::document('live_radio', []));
    }
    public static function save(array $body): array {
        $station=null;
        $id=(string)($body['id'] ?? '');
        $url=trim((string)($body['url'] ?? ''));
        if (empty($body['delete'])) {
            if (!preg_match('#^https?://#i',$url) || !filter_var($url,FILTER_VALIDATE_URL) || parse_url($url,PHP_URL_USER) || parse_url($url,PHP_URL_PASS)) throw new InvalidArgumentException('Choose an HTTP or HTTPS audio stream URL.');
            $name=trim((string)($body['name'] ?? ''));
            if ($name==='') throw new InvalidArgumentException('Give the station a name.');
            if ($id==='') $id=substr(hash('sha256',$url),0,24);
            if (!preg_match('/^[a-zA-Z0-9-]{1,64}$/D',$id)) throw new InvalidArgumentException('Invalid station ID.');
            $station=['id'=>$id,'name'=>mb_substr($name,0,120),'url'=>$url];
            foreach (['country','language','tags','description','homepage','logo','codec','stationuuid'] as $field) $station[$field]=mb_substr(trim((string)($body[$field] ?? '')),0,$field==='description'?2000:1000);
            foreach (['logo','homepage'] as $field) if ($station[$field]!=='' && !preg_match('#^https?://#i',$station[$field])) $station[$field]='';
        }
        \Vaultcast\Database::transaction(function(PDO $pdo) use ($id,$body,$station): void {
            $pdo->exec("INSERT IGNORE INTO vaultcast_documents (name,payload) VALUES ('live_radio','[]')");
            $pdo->query("SELECT name FROM vaultcast_documents WHERE name='live_radio' FOR UPDATE")->fetch();
            $rows=self::stations();$found=false;
            foreach ($rows as $key=>$row) if ($row['id']===$id) { $found=true;if (!empty($body['delete'])) unset($rows[$key]);else $rows[$key]=$station; }
            if (!$found && empty($body['delete'])) {if(count($rows)>=500)throw new InvalidArgumentException('The station limit is 500.');$rows[]=$station;}
            \Vaultcast\Repository::putDocument('live_radio',array_values($rows));
        });
        return ['ok'=>true,'stations'=>self::stations()];
    }
    public static function search(array $query): array {
        $params=['limit'=>30,'offset'=>max(0,min(3000,(int)($query['offset'] ?? 0))),'hidebroken'=>'true','order'=>'votes','reverse'=>'true'];
        foreach (['name','country','language','tag'] as $field) if (!empty($query[$field])) $params[$field]=mb_substr(trim((string)$query[$field]),0,100);
        $key='radio-directory:'.hash('sha256',json_encode($params));
        $cached=\Vaultcast\Repository::cacheGet($key);if(is_array($cached))return $cached;
        if (!function_exists('curl_init')) throw new RuntimeException('Radio directory search requires PHP curl.');
        $result=null;
        foreach (['https://de1.api.radio-browser.info','https://fi1.api.radio-browser.info'] as $host) {
            $ch=curl_init($host.'/json/stations/search?'.http_build_query($params));$bytes=0;
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>7,CURLOPT_USERAGENT=>'Vaultcast/1.1.0 (Internet radio)',CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,$chunk) use (&$bytes,&$result){$bytes+=strlen($chunk);if($bytes>2097152)return 0;$result=($result ?? '').$chunk;return strlen($chunk);}]);
            curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
            $rows=$code===200?json_decode((string)$result,true):null;
            if(is_array($rows))break;$result=null;
        }
        if(!is_array($rows ?? null))throw new RuntimeException('The public radio directory is unavailable. Try again or add a stream manually.');
        $stations=[];
        foreach ($rows as $row) {
            $url=(string)($row['url_resolved'] ?? $row['url'] ?? '');if(!preg_match('#^https?://#i',$url))continue;
            $stations[]=['id'=>substr(hash('sha256',$url),0,24),'stationuuid'=>(string)($row['stationuuid'] ?? ''),'name'=>(string)($row['name'] ?? 'Radio'),'url'=>$url,'logo'=>(string)($row['favicon'] ?? ''),'country'=>(string)($row['country'] ?? ''),'language'=>(string)($row['language'] ?? ''),'tags'=>(string)($row['tags'] ?? ''),'homepage'=>(string)($row['homepage'] ?? ''),'codec'=>(string)($row['codec'] ?? ''),'bitrate'=>(int)($row['bitrate'] ?? 0)];
        }
        $response=['ok'=>true,'stations'=>$stations,'has_more'=>count($rows)===30];
        \Vaultcast\Repository::cachePut($key,$response,600);return $response;
    }
}
