<?php
declare(strict_types=1);

/** Optional standalone PicoDrive build; downloaded into data, never shipped in the application. */
final class LegacyGenesis {
    const REVISION='8d240a7382dfc5dbc983b7164d71ade342bbdfd0';
    const SHA256='0a6d90e60648276ab96eca21acc68c1a80edd8cfcd608d3fe2e950233e352fb7';
    public static function url(): string {return \Vaultcast\Database::basePath().'api/player-assets/genesis-legacy/'.self::REVISION.'/Genesis.min.js';}
    public static function serve(): void {
        if(!AreaAccess::allowed(current_or_guest(),'games'))json_error('Games access required.',403);
        $dir=STREAM_DATA.'/players/genesis-legacy';$file=$dir.'/'.self::REVISION.'.js';
        if(!is_file($file)||hash_file('sha256',$file)!==self::SHA256){
            if(!extension_loaded('curl'))json_error('The legacy Genesis player needs PHP cURL to download its engine.',503);
            if(!is_dir($dir)&&!@mkdir($dir,0775,true))json_error('Cannot create legacy player storage.',503);
            $body='';$curl=curl_init('https://raw.githubusercontent.com/lrusso/Genesis/'.self::REVISION.'/Genesis.min.js');
            curl_setopt_array($curl,[CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,string $part)use(&$body):int{if(strlen($body)+strlen($part)>3145728)return 0;$body.=$part;return strlen($part);}]);
            $ok=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
            if(!$ok||$status!==200||hash('sha256',$body)!==self::SHA256)json_error('Could not download the verified legacy Genesis engine. Check the server connection to raw.githubusercontent.com.',503);
            $temp=$file.'.'.bin2hex(random_bytes(8)).'.tmp';
            if(@file_put_contents($temp,$body,LOCK_EX)===false||!@rename($temp,$file)){@unlink($temp);json_error('Cannot store the legacy Genesis engine.',503);}
        }
        header('Content-Type: application/javascript; charset=utf-8');header('Cache-Control: private, max-age=31536000, immutable');
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='HEAD')echo AssetDelivery::text((string)file_get_contents($file));exit;
    }
}
