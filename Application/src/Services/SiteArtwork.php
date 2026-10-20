<?php
declare(strict_types=1);

final class SiteArtwork {
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
    public static function serve(string $kind='home'): void {
        $custom=self::path($kind);
        $name=(in_array($kind,self::SECTIONS,true)?'home-'.$kind:$kind.'-poster').'.jpg';
        $theme=is_string($_GET['theme']??null)?$_GET['theme']:'dark';
        $themed=in_array($theme,['anime','manly-man'],true)?STREAM_ROOT.'/assets/img/themes/'.$theme.'/'.$name:'';
        // A site upload always wins, independently of the viewing user's theme.
        $path=is_file($custom)?$custom:($themed!==''&&is_file($themed)?$themed:STREAM_ROOT.'/assets/img/'.$name);
        $info=@getimagesize($path);if (!$info) json_error('Poster unavailable',404);
        $etag='"'.hash_file('sha256',$path).'"';
        header('Content-Type: '.$info['mime']);header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, must-revalidate');header('ETag: '.$etag);
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''))===$etag) {http_response_code(304);exit;}
        header('Content-Length: '.filesize($path));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='HEAD')readfile($path);exit;
    }
}
