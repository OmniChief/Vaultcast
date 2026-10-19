<?php
declare(strict_types=1);

final class SiteArtwork {
    private static function path(): string {return STREAM_DATA.'/site/home-poster.img';}
    public static function save(array $body): array {
        $path=self::path();
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
    public static function serve(): void {
        $path=is_file(self::path())?self::path():STREAM_ROOT.'/assets/img/home-poster.jpg';
        $info=@getimagesize($path);if (!$info) json_error('Poster unavailable',404);
        $etag='"'.hash_file('sha256',$path).'"';
        header('Content-Type: '.$info['mime']);header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, must-revalidate');header('ETag: '.$etag);
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''))===$etag) {http_response_code(304);exit;}
        header('Content-Length: '.filesize($path));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='HEAD')readfile($path);exit;
    }
}
