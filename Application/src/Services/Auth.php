<?php
declare(strict_types=1);

class Auth {
    const COOKIE = 'vaultcast_session';

    public static function users(): array {
        $u = Store::get('users', []);
        return is_array($u) ? $u : [];
    }

    public static function findUser(string $username): ?array {
        return \Vaultcast\Repository::user($username);
    }
    public static function findById(string $id): ?array {
        return \Vaultcast\Repository::user($id, true);
    }
    public static function createSession(array $user): string {
        $settings = Store::settings();
        $ttl = (int)($settings['auth']['session_ttl_seconds'] ?? 604800);
        $token = bin2hex(random_bytes(32));
        $sess = [
            'token' => $token,
            'user_id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'] ?? 'user',
            'created_at' => time(),
            'expires_at' => time() + $ttl,
            'ip' => client_ip(),
        ];
        \Vaultcast\Repository::saveSession($token, $sess);
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        setcookie(self::COOKIE, $token, [
            'expires' => $sess['expires_at'],
            'path' => \Vaultcast\Database::basePath(),
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        return $token;
    }

    public static function destroySession(): void {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (preg_match('/^[a-f0-9]{64}$/', $token)) \Vaultcast\Repository::deleteSession($token);
        setcookie(self::COOKIE, '', [
            'expires' => time() - 3600,
            'path' => \Vaultcast\Database::basePath(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function currentSession(): ?array {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        return \Vaultcast\Repository::session($token);
    }

    public static function requireUser(): array {
        $sess = self::currentSession();
        if (!$sess) json_error('Unauthorized', 401);
        $user = self::findById($sess['user_id']);
        if (!$user) json_error('Unauthorized', 401);
        unset($user['password_hash']);
        $user['_session'] = $sess;
        return $user;
    }

    public static function requireAdmin(): array {
        $user = self::requireUser();
        if (($user['role'] ?? '') !== 'admin') json_error('Forbidden', 403);
        return $user;
    }

    public static function login(string $username, string $password): array {
        $user = self::findUser($username);
        if (!$user || !password_verify($password, $user['password_hash'] ?? '')) {
            json_error('Invalid username or password', 401);
        }
        $token = self::createSession($user);
        unset($user['password_hash']);
        // Sign-in works for gated users so the welcome page can show "awaiting access" / "expired";
        // every other request is refused by Access::gate until an admin grants access.
        $user['access_state'] = Access::state($user);
        return ['ok' => true, 'token' => $token, 'user' => $user];
    }
}
