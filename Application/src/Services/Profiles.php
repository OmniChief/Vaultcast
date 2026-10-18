<?php
declare(strict_types=1);

final class Profiles
{
    public static function avatarUrl(array $user): ?string
    {
        if (empty($user['avatar_version']) || !preg_match('/^[a-f0-9]{16,64}$/D', (string)($user['id'] ?? ''))) return null;
        return \Vaultcast\Database::basePath() . 'api/profiles/' . $user['id'] . '/avatar?v=' . rawurlencode((string)$user['avatar_version']);
    }

    public static function saveAvatar(array $user, array $body): ?string
    {
        $id = (string)$user['id'];
        if (!preg_match('/^[a-f0-9]{16,64}$/D', $id)) throw new InvalidArgumentException('Invalid profile');
        $dir = STREAM_DATA . '/avatars'; $path = $dir . '/' . $id . '.img';
        if (!empty($body['remove_avatar'])) { if (is_file($path)) @unlink($path); return null; }
        if (empty($body['avatar_data'])) return $user['avatar_version'] ?? null;
        $data = (string)$body['avatar_data'];
        if (strlen($data) > 1500000 || !preg_match('#^data:image/(?:jpeg|png|webp);base64,(.+)$#s', $data, $match)) throw new InvalidArgumentException('Choose a JPEG, PNG or WebP avatar up to 1 MB');
        $bytes = base64_decode($match[1], true); $info = $bytes !== false ? @getimagesizefromstring($bytes) : false;
        if (!$info || strlen($bytes) > 1048576 || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] * $info[1] > 4000000) throw new InvalidArgumentException('Invalid avatar or image too large (maximum 1 MB / 4 megapixels)');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new InvalidArgumentException('Could not create avatar storage');
        $tmp = $path . '.' . bin2hex(random_bytes(5)) . '.tmp';
        if (file_put_contents($tmp, $bytes, LOCK_EX) === false || !@rename($tmp, $path)) { @unlink($tmp); throw new InvalidArgumentException('Could not save avatar'); }
        return substr(hash('sha256', $bytes), 0, 16);
    }

    public static function serve(string $id): void
    {
        $user = Auth::findById($id);
        if (!$user || empty($user['avatar_version'])) json_error('Avatar not found', 404);
        $path = STREAM_DATA . '/avatars/' . $id . '.img';
        $info = is_file($path) ? @getimagesize($path) : false;
        if (!$info) json_error('Avatar not found', 404);
        header('Content-Type: ' . $info['mime']); header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600'); header('Content-Length: ' . filesize($path));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($path);
        exit;
    }

    public static function lobbyIdentity(): array
    {
        $user = AreaAccess::viewer();
        return $user ? ['user_id'=>$user['id'], 'name'=>(string)($user['display_name'] ?? $user['username']), 'avatar_url'=>self::avatarUrl($user)] : [];
    }
}
