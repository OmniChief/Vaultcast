<?php
declare(strict_types=1);

/** Version-pinned JavaScript-only Nintendo players, downloaded into data on first use. */
final class LegacyNintendo {
    private const ENGINES=[
        'nes'=>['repository'=>'Nintendo','revision'=>'7839b27dd80b2518483f2f157321d66c6497d7f9','sha256'=>'7713e13c036e6d1fceea9d96e0c06aee8cba48f8a97eb96dbfd0f56c4d32dedf'],
        'snes'=>['repository'=>'SuperNintendo','revision'=>'3a6bf2b16f91169a379a8d8863452423a0b2f84f','sha256'=>'4afdbf2f60cc5a9a4645e51cc6366ed62248e381f74f142da7cb460eb2ed9c97']
    ];
    public static function system(string $core): ?string {
        if(in_array($core,['nes','fceumm','nestopia'],true))return 'nes';
        if(in_array($core,['snes','snes9x','snes9x2005','snes9x2005_plus','snes9x2010','bsnes'],true))return 'snes';
        return null;
    }
    public static function url(string $system): string {
        if(!isset(self::ENGINES[$system]))throw new InvalidArgumentException('Unknown Nintendo system.');
        $engine=self::ENGINES[$system];return \Vaultcast\Database::basePath().'api/player-assets/'.$system.'-legacy/'.$engine['revision'].'/'.$engine['repository'].'.min.js';
    }
    public static function serve(string $system,string $revision,string $filename): void {
        $engine=self::ENGINES[$system] ?? null;
        if(!$engine||$revision!==$engine['revision']||$filename!==$engine['repository'].'.min.js')json_error('Legacy player not found.',404);
        if(!AreaAccess::allowed(current_or_guest(),'games'))json_error('Games access required.',403);
        $dir=STREAM_DATA.'/players/'.$system.'-legacy';$file=$dir.'/'.$revision.'.js';
        if(!is_file($file)||hash_file('sha256',$file)!==$engine['sha256']){
            if(!extension_loaded('curl'))json_error('The legacy player needs PHP cURL to download its engine.',503);
            if(!is_dir($dir)&&!@mkdir($dir,0775,true))json_error('Cannot create legacy player storage.',503);
            $body='';$curl=curl_init('https://raw.githubusercontent.com/lrusso/'.$engine['repository'].'/'.$revision.'/'.$filename);
            curl_setopt_array($curl,[CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,string $part)use(&$body):int{if(strlen($body)+strlen($part)>3145728)return 0;$body.=$part;return strlen($part);}]);
            $ok=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
            if(!$ok||$status!==200||hash('sha256',$body)!==$engine['sha256'])json_error('Could not download the verified legacy Nintendo engine. Check the server connection to raw.githubusercontent.com.',503);
            $temp=$file.'.'.bin2hex(random_bytes(8)).'.tmp';
            if(@file_put_contents($temp,$body,LOCK_EX)===false||!@rename($temp,$file)){@unlink($temp);json_error('Cannot store the legacy Nintendo engine.',503);}
        }
        header('Content-Type: application/javascript; charset=utf-8');header('Cache-Control: private, max-age=31536000, immutable');
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='HEAD')echo AssetDelivery::text((string)file_get_contents($file));exit;
    }
}
