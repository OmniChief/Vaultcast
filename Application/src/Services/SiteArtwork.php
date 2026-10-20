<?php
declare(strict_types=1);

final class SiteArtwork {
    const THEMES=['dark','light','retro-scifi','steampunk','vintage-tv','after-hours','ninja-extreme','anime','manly-man'];
    const SECTIONS=['cinema','watchlist','favourites','calendar','arrivals','friends','history','stats'];
    private static function path(string $kind='home'): string {
        if (!in_array($kind,array_merge(['home','collections'],self::SECTIONS),true)) throw new InvalidArgumentException('Unknown artwork section.');
        return STREAM_DATA.'/site/'.$kind.'-poster.img';
    }
    public static function save(array $body): array {
        $path=self::path((string)($body['kind'] ?? 'home'));
        if (!empty($body['reset'])) {
            if (is_file($path) && !@unlink($path)) throw new RuntimeException('Could not restore the default poster.');
            return ['ok'=>true];
        }
        $data=(string)($body['poster_data'] ?? '');
        if (strlen($data)>3000000 || !preg_match('#^data:image/(?:jpeg|png|webp);base64,(.+)$#s',$data,$match)) throw new InvalidArgumentException('Choose a JPEG, PNG or WebP poster up to 2 MB.');
        $bytes=base64_decode($match[1],true);$info=$bytes!==false?@getimagesizefromstring($bytes):false;
        if (!$info || strlen($bytes)>2097152 || !in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true) || $info[0]*$info[1]>12000000) throw new InvalidArgumentException('Invalid poster: maximum 2 MB and 12 megapixels.');
        if (!is_dir(dirname($path))&&!@mkdir(dirname($path),0775,true)) throw new RuntimeException('Could not create site artwork storage.');
        $temp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (@file_put_contents($temp,$bytes,LOCK_EX)!==strlen($bytes)||!@rename($temp,$path)) {@unlink($temp);throw new RuntimeException('Could not save the poster.');}
        return ['ok'=>true];
    }
    public static function customKinds(): array {
        $custom=[];foreach(array_merge(['home','collections'],self::SECTIONS) as $kind)if(is_file(self::path($kind)))$custom[]=$kind;
        return $custom;
    }
    public static function optimise(?callable $progress=null): array {
        if(!PosterOptimise::ffmpegHasWebp()&&!function_exists('imagewebp'))throw new RuntimeException('WebP encoding is unavailable. Configure ffmpeg with libwebp or PHP GD WebP support under Advanced.');
        $directory=STREAM_DATA.'/site/webp';
        if(!is_dir($directory)&&!@mkdir($directory,0775,true))throw new RuntimeException('Could not create the system artwork cache. Check data folder permissions.');
        $sources=[];
        foreach(self::THEMES as $theme)foreach(array_merge(['home','collections'],self::SECTIONS) as $kind){
            $name=(in_array($kind,self::SECTIONS,true)?'home-'.$kind:$kind.'-poster').'.jpg';
            $file=STREAM_ROOT.'/assets/img/themes/'.$theme.'/'.$name;
            if(is_file($file))$sources[$theme.'/'.$kind]=$file;
        }
        foreach(self::customKinds() as $kind)$sources['custom/'.$kind]=self::path($kind);
        $result=['optimised'=>0,'already_ready'=>0,'smaller_original'=>0,'failed'=>0,'bytes_saved'=>0,'errors'=>[]];
        $total=count($sources);$done=0;
        foreach($sources as $label=>$source){
            Maintenance::checkpoint();if($progress)$progress($done++,$total,$label);
            $hash=@hash_file('sha256',$source);if(!$hash){$result['failed']++;continue;}
            $destination=$directory.'/'.$hash.'.webp';
            if(is_file($destination)&&filesize($destination)>40){
                $ready=@getimagesize($destination);if(($ready['mime']??'')==='image/webp'&&filesize($destination)<filesize($source)){$result['already_ready']++;continue;}
                @unlink($destination);
            }
            $info=@getimagesize($source);
            if(($info['mime']??'')==='image/webp'){$result['already_ready']++;continue;}
            $encoded=PosterOptimise::encodeSystemArtwork($source,$destination);
            if(empty($encoded['ok'])){$result['failed']++;if(count($result['errors'])<20)$result['errors'][]=$label.': '.($encoded['error']??'Could not encode image.');continue;}
            if(@hash_file('sha256',$source)!==$hash){@unlink($destination);$result['failed']++;if(count($result['errors'])<20)$result['errors'][]=$label.': Image changed during optimisation; run again.';continue;}
            clearstatcache(true,$destination);
            if(filesize($destination)>=filesize($source)){$result['smaller_original']++;@unlink($destination);continue;}
            $result['optimised']++;$result['bytes_saved']+=filesize($source)-filesize($destination);
        }
        if($progress)$progress($total,$total,'');
        if($result['failed'])$result['warning']=$result['failed'].' image(s) could not be optimised. Review the error details. Originals continue to work.';
        return $result;
    }
    public static function serve(string $kind='home'): void {
        $custom=self::path($kind);
        $name=(in_array($kind,self::SECTIONS,true)?'home-'.$kind:$kind.'-poster').'.jpg';
        $theme=is_string($_GET['theme']??null)?$_GET['theme']:'dark';
        $theme=in_array($theme,self::THEMES,true)?$theme:'dark';
        $themed=STREAM_ROOT.'/assets/img/themes/'.$theme.'/'.$name;
        // A site upload always wins, independently of the viewing user's theme.
        $path=is_file($custom)?$custom:(is_file($themed)?$themed:STREAM_ROOT.'/assets/img/themes/dark/'.$name);
        // Only serve a previously prepared, content-addressed derivative. No encoding on requests.
        $sourceHash=@hash_file('sha256',$path);if(!$sourceHash)json_error('Poster unavailable',404);
        $webp=STREAM_DATA.'/site/webp/'.$sourceHash.'.webp';
        header('Vary: Accept');
        $info=null;
        if(PosterOptimise::clientWantsWebp()&&is_file($webp)&&filesize($webp)>40&&filesize($webp)<filesize($path)){
            $candidate=@getimagesize($webp);if(($candidate['mime']??'')==='image/webp'){$path=$webp;$info=$candidate;}
        }
        if(!$info)$info=@getimagesize($path);if (!$info) json_error('Poster unavailable',404);
        $etag='"'.($path===$webp?hash_file('sha256',$path):$sourceHash).'"';
        header('Content-Type: '.$info['mime']);header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, must-revalidate');header('ETag: '.$etag);
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''))===$etag) {http_response_code(304);exit;}
        header('Content-Length: '.filesize($path));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='HEAD')readfile($path);exit;
    }
}
