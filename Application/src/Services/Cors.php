<?php
declare(strict_types=1);

final class Cors {
    private static $explicit = false;
    const METHODS = ['GET','HEAD','POST','PUT','PATCH','DELETE'];
    const HEADERS = ['authorization','content-type','range','x-requested-with'];

    public static function origin(string $url): ?string {
        if (preg_match('/[\x00-\x20\x7f]/', $url)) return null;
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'],$p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || !in_array($p['path'] ?? '', ['', '/'], true)) return null;
        $scheme=strtolower($p['scheme']);
        if (!in_array($scheme,['http','https','capacitor','ionic'],true)) return null;
        $port=(int)($p['port'] ?? 0);
        if (($scheme==='http' && $port===80) || ($scheme==='https' && $port===443)) $port=0;
        return $scheme.'://'.strtolower($p['host']).($port?':'.$port:'');
    }

    public static function validate($origins): array {
        if (!is_array($origins) || count($origins)>20) throw new InvalidArgumentException('Use up to 20 exact app origins.');
        $out=[];
        foreach($origins as $value){
            if(!is_string($value)||strlen($value)>300||($origin=self::origin(trim($value)))===null) throw new InvalidArgumentException('Each origin needs a scheme and host, without a path, wildcard or credentials.');
            $out[]=$origin;
        }
        return array_values(array_unique($out));
    }

    /** Pure policy decision; cookie sessions are never enabled for cross-origin apps. */
    public static function policy(array $server, array $allowed, string $publicBase, string $path, bool $api = true): array {
        $method=strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
        $raw=(string)($server['HTTP_ORIGIN'] ?? '');
        $write=!in_array($method,['GET','HEAD','OPTIONS'],true);
        // The updater keeps its pre-existing origin behaviour and gets no new CORS access.
        if(preg_match('#^/admin/updates(?:/|$)#',$path)){
            $host=$raw!==''?parse_url($raw,PHP_URL_HOST):null;$port=$raw!==''?parse_url($raw,PHP_URL_PORT):null;
            $authority=$host?strtolower($host.($port?':'.$port:'')):'';
            return ['status'=>$write&&(($raw!==''&&$authority!==strtolower((string)($server['HTTP_HOST']??'')))||($server['HTTP_SEC_FETCH_SITE']??'')==='cross-site')?403:0,'headers'=>[],'explicit'=>false];
        }
        $origin=self::origin($raw);
        $scheme=!empty($server['HTTPS'])&&$server['HTTPS']!=='off'?'https':'http';
        $requestOrigin=self::origin($scheme.'://'.($server['HTTP_HOST']??''));
        $publicParts=parse_url($publicBase);
        $publicOrigin=is_array($publicParts)&&isset($publicParts['scheme'],$publicParts['host'])?self::origin($publicParts['scheme'].'://'.$publicParts['host'].(isset($publicParts['port'])?':'.$publicParts['port']:'')):null;
        $same=$origin!==null&&($origin===$requestOrigin||$origin===$publicOrigin);
        // Browser-generated same-origin metadata also covers TLS terminated at a proxy.
        if(!$same&&$origin!==null&&($server['HTTP_SEC_FETCH_SITE']??'')==='same-origin'){
            $p=parse_url($origin);$host=self::origin(($p['scheme']??'http').'://'.($server['HTTP_HOST']??''));$same=$host===$origin;
        }

        $explicit=$api&&!$same&&$origin!==null&&in_array($origin,$allowed,true);
        $headers=[];
        if($explicit){
            $headers['Access-Control-Allow-Origin']=$raw;
            $headers['Access-Control-Expose-Headers']='Content-Type, Content-Length, Content-Range, Accept-Ranges, X-Vaultcast-Achievement, X-Vaultcast-Save-CRC32';
        }
        $preflight=$method==='OPTIONS'&&isset($server['HTTP_ACCESS_CONTROL_REQUEST_METHOD']);
        if($preflight){
            $requested=strtoupper((string)$server['HTTP_ACCESS_CONTROL_REQUEST_METHOD']);
            $requestedHeaders=array_filter(array_map('trim',explode(',',strtolower((string)($server['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']??'')))));
            if(!$api||!($same||$explicit)||!in_array($requested,self::METHODS,true)||array_diff($requestedHeaders,self::HEADERS))return ['status'=>403,'headers'=>$headers,'explicit'=>$explicit];
            $headers['Access-Control-Allow-Origin']=$raw;
            $headers['Access-Control-Allow-Methods']=implode(', ',self::METHODS);
            $headers['Access-Control-Allow-Headers']='Authorization, Content-Type, Range, X-Requested-With';
            $headers['Access-Control-Max-Age']='600';
            return ['status'=>204,'headers'=>$headers,'explicit'=>$explicit];
        }
        if($explicit){
            // Bearer keys or existing stream/share tokens, never ambient browser cookies.
            $auth=(string)($server['HTTP_AUTHORIZATION']??$server['REDIRECT_HTTP_AUTHORIZATION']??'');
            $tokenRead=in_array($method,['GET','HEAD'],true)&&(strpos($path,'/stream/')===0||strpos($path,'/live/')===0||strpos($path,'/drop/s/')===0);
            if(!$tokenRead&&!preg_match('/^Bearer[ \t]+vc_[a-f0-9]{64}$/iD',trim($auth)))return ['status'=>401,'headers'=>$headers,'explicit'=>true];
        } elseif($write&&(($raw!==''&&!$same)||($server['HTTP_SEC_FETCH_SITE']??'')==='cross-site'))return ['status'=>403,'headers'=>[],'explicit'=>false];
        // Token-based media responses already support public cross-origin reads.
        if(!$headers&&in_array($method,['GET','HEAD'],true)&&(strpos($path,'/stream/')===0||strpos($path,'/live/')===0))$headers['Access-Control-Allow-Origin']='*';
        return ['status'=>0,'headers'=>$headers,'explicit'=>$explicit];
    }

    public static function handle(): void {
        $path=request_path();
        if(preg_match('#^/admin/updates(?:/|$)#',$path)){
            $legacy=self::policy($_SERVER,[],'',$path);
            if($legacy['status']){http_response_code(403);header('Content-Type: application/json');exit('{"ok":false,"error":"Cross-site write rejected."}');}
            return;
        }
        $settings=Store::settings();
        $uri=(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
        $prefix=rtrim(\Vaultcast\Database::basePath(),'/').'/api';
        $api=$uri===$prefix||strpos($uri,$prefix.'/')===0;
        $p=self::policy($_SERVER,(array)($settings['cors_allowed_origins']??[]),(string)($settings['public_base_url']??''),request_path(),$api);
        self::$explicit=$p['explicit'];
        if(isset($_SERVER['HTTP_ORIGIN']))header('Vary: Origin',false);
        foreach($p['headers'] as $name=>$value)header($name.': '.$value);
        if($p['status']===204){http_response_code(204);exit;}
        if($p['status']){
            http_response_code($p['status']);header('Content-Type: application/json');header('Cache-Control: no-store');
            echo json_encode(['ok'=>false,'error'=>$p['status']===401?'A mobile API key is required for this app origin.':'Cross-site request rejected.']);exit;
        }
    }

    public static function media(): void {
        if(!self::$explicit)header('Access-Control-Allow-Origin: *');
    }
}
