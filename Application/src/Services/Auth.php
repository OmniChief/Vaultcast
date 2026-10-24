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
        // Bearer credentials win; unrelated proxy authentication keeps the cookie flow.
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (is_string($authorization) && preg_match('/^Bearer(?:[ \t]|$)/i', trim($authorization))) {
            if (!preg_match('/^Bearer[ \t]+(vc_[a-f0-9]{64})$/iD', trim($authorization), $match)) json_error('Invalid API key.', 401);
            $session = \Vaultcast\Repository::session($match[1]);
            if (!$session || empty($session['api_key']) || !self::findById((string)($session['user_id'] ?? ''))) json_error('API key is invalid or expired.', 401);
            return $session;
        }
        $token = $_COOKIE[self::COOKIE] ?? '';
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        return \Vaultcast\Repository::session($token);
    }

    /** Key management requires a browser sign-in; a key cannot mint more credentials. */
    public static function requireKeyManager(): array {
        $user = self::requireUser();
        if (!empty($user['_session']['api_key']) || ($user['role'] ?? '') === 'guest') json_error('Sign in through your browser to manage API keys.', 403);
        return $user;
    }

    public static function apiKeys(array $user): array {
        $rows = \Vaultcast\Repository::rows("SELECT payload FROM vaultcast_sessions WHERE user_id=? AND expires_at>=? AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.api_key'))='true' ORDER BY expires_at LIMIT 10", [(string)$user['id'], time()]);
        return array_map(static function (array $row): array {
            return array_intersect_key($row, array_flip(['id', 'label', 'created_at', 'expires_at']));
        }, $rows);
    }

    public static function createApiKey(array $user, string $label, int $days = 365): array {
        $label = trim($label);
        if ($label === '' || strlen($label) > 80 || preg_match('/[\x00-\x1f\x7f]/', $label)) throw new InvalidArgumentException('Use a device name between 1 and 80 bytes.');
        if (!in_array($days, [30, 90, 365], true)) throw new InvalidArgumentException('Choose 30, 90 or 365 days.');
        return \Vaultcast\Database::transaction(static function (\PDO $pdo) use ($user, $label, $days): array {
            // Serialize key creation per account so concurrent requests cannot exceed the cap.
            $lock = $pdo->prepare('SELECT id FROM vaultcast_users WHERE id=? FOR UPDATE');
            $lock->execute([(string)$user['id']]);
            if (!$lock->fetchColumn()) throw new DomainException('This account no longer exists.');
            if (count(self::apiKeys($user)) >= 10) throw new DomainException('Revoke an existing key before adding another (maximum 10).');
            $key = 'vc_' . bin2hex(random_bytes(32));
            $row = ['id' => bin2hex(random_bytes(8)), 'api_key' => true, 'label' => $label, 'user_id' => (string)$user['id'], 'username' => $user['username'], 'role' => $user['role'] ?? 'user', 'created_at' => time(), 'expires_at' => time() + $days * 86400];
            \Vaultcast\Repository::saveSession($key, $row);
            return ['ok' => true, 'key' => $key, 'entry' => array_intersect_key($row, array_flip(['id', 'label', 'created_at', 'expires_at']))];
        });
    }

    public static function revokeApiKey(array $user, string $id): array {
        if (!preg_match('/^[a-f0-9]{16}$/D', $id)) throw new InvalidArgumentException('Invalid key ID.');
        $s = \Vaultcast\Database::connection()->prepare("DELETE FROM vaultcast_sessions WHERE user_id=? AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.api_key'))='true' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.id'))=?");
        $s->execute([(string)$user['id'], $id]);
        return ['ok' => true];
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
        $user = Permissions::publicUser($user);
        return ['ok' => true, 'token' => $token, 'user' => $user];
    }
}
