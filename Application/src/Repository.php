<?php
declare(strict_types=1);

namespace Vaultcast;

/** SQL persistence for catalogue records, users and application documents. */
final class Repository
{
    public static function savePlaybackToken(string $token, array $payload): void {
        unset($payload['token']);
        $s = Database::connection()->prepare('INSERT INTO vaultcast_playback_tokens(token_hash,expires_at,payload) VALUES (?,?,?) ON DUPLICATE KEY UPDATE expires_at=VALUES(expires_at),payload=VALUES(payload)');
        $s->execute([hash('sha256',$token),$payload['expires'],self::encode($payload)]);
        if (random_int(1,100) === 1) Database::connection()->exec('DELETE FROM vaultcast_playback_tokens WHERE expires_at<' . time() . ' LIMIT 500');
    }
    public static function playbackToken(string $token): ?array {
        return self::rows('SELECT payload FROM vaultcast_playback_tokens WHERE token_hash=? AND expires_at>?', [hash('sha256',$token),time()])[0] ?? null;
    }

    /**
     * Disposable TTL cache. File-backed (data/cache/kv) since the storage audit: it is
     * rebuildable, never queried relationally, and kept database writes off hot paths.
     */
    public static function cacheDir(): string {
        return (defined('STREAM_SHM') ? STREAM_SHM : dirname(__DIR__) . '/data/cache') . '/kv';
    }

    private static function cacheFile(string $key): string {
        $h = hash('sha256', $key);
        return self::cacheDir() . '/' . substr($h, 0, 2) . '/' . $h . '.json';
    }

    public static function cacheGet(string $key): ?array {
        $f = self::cacheFile($key);
        $raw = is_file($f) ? @file_get_contents($f) : false;
        if ($raw === false || $raw === '') return null;
        $j = json_decode($raw, true);
        if (!is_array($j) || !isset($j['e']) || (int)$j['e'] <= time()) return null;
        return is_array($j['v'] ?? null) ? $j['v'] : null;
    }

    public static function cachePut(string $key, array $value, int $ttl = 3600): void {
        $f = self::cacheFile($key);
        $dir = dirname($f);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $body = self::encode(['e' => time() + max(1, $ttl), 'v' => $value]);
        if (@file_put_contents($tmp, $body) === false) return;
        // rename() replaces atomically on Linux and Windows (MoveFileEx) in PHP 7.4+.
        if (!@rename($tmp, $f)) @unlink($tmp);
        // Directory cleanup belongs to maintenance, never a random browsing request.
        if (PHP_SAPI === 'cli' && random_int(1, 200) === 1) self::cacheSweep($dir);
    }

    /** Remove every cache entry. @return int files deleted */
    public static function cacheClear(): int {
        $n = 0;
        foreach (glob(self::cacheDir() . '/*/*') ?: [] as $f) if (is_file($f) && @unlink($f)) $n++;
        return $n;
    }

    /** @return array{files:int,bytes:int} */
    public static function cacheStats(): array {
        $n = 0; $b = 0;
        foreach (glob(self::cacheDir() . '/*/*.json') ?: [] as $f) { $n++; $b += (int)@filesize($f); }
        return ['files' => $n, 'bytes' => $b];
    }

    /** Delete expired entries in one shard directory. */
    public static function cacheSweep(string $dir): int {
        $n = 0;
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            $j = json_decode((string)@file_get_contents($f), true);
            if (!is_array($j) || (int)($j['e'] ?? 0) <= time()) { if (@unlink($f)) $n++; }
        }
        return $n;
    }

    public static function encode($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function document(string $name, $default = null)
    {
        $s = Database::connection()->prepare('SELECT payload FROM vaultcast_documents WHERE name = ?');
        $s->execute([$name]);
        $value = $s->fetchColumn();
        return $value === false ? $default : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function putDocument(string $name, $value): void
    {
        $s = Database::connection()->prepare('INSERT INTO vaultcast_documents (name, payload) VALUES (?, ?) ON DUPLICATE KEY UPDATE payload = VALUES(payload), revision = revision + 1');
        $s->execute([$name, self::encode($value)]);
        if (class_exists('Store', false)) \Store::invalidate($name);
    }

    public static function revision(string $name): int
    {
        $s = Database::connection()->prepare('SELECT revision FROM vaultcast_documents WHERE name = ?');
        $s->execute([$name]);
        return (int)$s->fetchColumn();
    }

    public static function read(string $name, $default = null)
    {
        if ($name === 'catalogue') {
            $meta = self::document('catalogue', ['sections' => [], 'scanned_at' => null]);
            $meta['items'] = [];
            foreach (Database::connection()->query('SELECT id, payload FROM vaultcast_items') as $row) {
                $meta['items'][$row['id']] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
            }
            return $meta;
        }
        if ($name === 'users') return self::rows('SELECT payload FROM vaultcast_users ORDER BY username');
        if ($name === 'progress') {
            $all = [];
            foreach (Database::connection()->query('SELECT user_id,item_id,payload FROM vaultcast_progress') as $row) {
                $all[$row['user_id']][$row['item_id']] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
            }
            return $all;
        }
        return self::document($name, $default);
    }

    public static function write(string $name, $value): bool
    {
        Database::transaction(function (\PDO $pdo) use ($name, $value): void {
            if ($name === 'catalogue') {
                // Serialize catalogue replacements without exposing a half-written scan.
                $pdo->exec("INSERT IGNORE INTO vaultcast_documents (name,payload) VALUES ('catalogue','{}')");
                $pdo->query("SELECT name FROM vaultcast_documents WHERE name='catalogue' FOR UPDATE")->fetch();
                $generation = bin2hex(random_bytes(16));
                $upsert = $pdo->prepare('INSERT INTO vaultcast_items (id,section_id,media_kind,title,show_title,system_name,initial,modified_at,generation,payload) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE section_id=VALUES(section_id), media_kind=VALUES(media_kind), title=VALUES(title), show_title=VALUES(show_title), system_name=VALUES(system_name), initial=VALUES(initial), modified_at=VALUES(modified_at), generation=VALUES(generation), payload=VALUES(payload)');
                foreach ($value['items'] ?? [] as $item) {
                    $initial = mb_strtoupper(mb_substr(trim($item['title'] ?? ''), 0, 1, 'UTF-8'), 'UTF-8');
                    if (!preg_match('/^[A-Z]$/D', $initial)) $initial = '#';
                    $upsert->execute([$item['id'], $item['section'], $item['media_kind'] ?? '', $item['title'] ?? '', $item['show'] ?? '', trim($item['system'] ?? ''), $initial, (int)($item['mtime'] ?? 0), $generation, self::encode($item)]);
                }
                $delete = $pdo->prepare('DELETE FROM vaultcast_items WHERE generation <> ?');
                $delete->execute([$generation]);
                unset($value['items']);
            } elseif ($name === 'progress') {
                // Compatibility path for bulk maintenance/import; playback uses saveProgress.
                foreach ($value as $userId => $items) foreach ($items as $itemId => $progress) self::saveProgress((string)$userId, (string)$itemId, $progress);
                $value = [];
            } elseif ($name === 'users') {
                $pdo->exec("INSERT IGNORE INTO vaultcast_documents (name,payload) VALUES ('users','[]')");
                $pdo->query("SELECT name FROM vaultcast_documents WHERE name='users' FOR UPDATE")->fetch();
                // Replace atomically: a duplicate username must never update another identity.
                $pdo->exec('DELETE FROM vaultcast_users');
                $upsert = $pdo->prepare('INSERT INTO vaultcast_users (id,username,password_hash,role,payload) VALUES (?,?,?,?,?)');
                foreach ($value as $user) {
                    $upsert->execute([$user['id'], $user['username'], $user['password_hash'], $user['role'] ?? 'user', self::encode($user)]);
                }
                $value = []; // Only a revision marker; user records are stored individually.
            }
            self::putDocument($name, $value);
        });
        return true;
    }

    public static function rows(string $sql, array $params = []): array
    {
        $s = Database::connection()->prepare($sql);
        $s->execute($params);
        $rows = [];
        while ($row = $s->fetch()) $rows[] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        return $rows;
    }

    public static function item(string $id): ?array
    {
        return self::rows('SELECT payload FROM vaultcast_items WHERE id = ?', [$id])[0] ?? null;
    }

    public static function section(string $id): array
    {
        return self::rows('SELECT payload FROM vaultcast_items WHERE section_id = ? ORDER BY title, id', [$id]);
    }

    public static function browse(string $section, string $query, string $system, string $letter, int $page, int $per): array
    {
        $pdo = Database::connection();
        $s = $pdo->prepare("SELECT system_name,COUNT(*) AS n FROM vaultcast_items WHERE section_id=? AND system_name<>'' GROUP BY system_name ORDER BY system_name");
        $s->execute([$section]);
        $systems = [];
        foreach ($s as $row) $systems[$row['system_name']] = (int)$row['n'];
        $where = 'section_id=?';
        $params = [$section];
        if ($system !== '') { $where .= ' AND system_name=?'; $params[] = $system; }
        if ($query !== '') {
            $needle = '%' . strtr($query, ['!' => '!!','%' => '!%','_' => '!_']) . '%';
            $where .= " AND (title LIKE ? ESCAPE '!' OR show_title LIKE ? ESCAPE '!' OR system_name LIKE ? ESCAPE '!' OR JSON_UNQUOTE(JSON_EXTRACT(payload,'$.rel')) LIKE ? ESCAPE '!')";
            array_push($params, $needle, $needle, $needle, $needle);
        }
        $s = $pdo->prepare('SELECT initial,COUNT(*) AS n FROM vaultcast_items WHERE ' . $where . ' GROUP BY initial');
        $s->execute($params);
        $letters = array_fill_keys(array_merge(['#'], range('A','Z')), 0);
        foreach ($s as $row) $letters[$row['initial']] = (int)$row['n'];
        if (preg_match('/^[A-Z#]$/D', $letter)) { $where .= ' AND initial=?'; $params[] = $letter; }
        $total = preg_match('/^[A-Z#]$/D', $letter) ? (int)($letters[$letter] ?? 0) : array_sum($letters);
        $per = max(1, min(100, $per));
        $offset = (max(1, $page) - 1) * $per;
        $items = self::rows('SELECT payload FROM vaultcast_items WHERE ' . $where . ' ORDER BY title,id LIMIT ' . $per . ' OFFSET ' . $offset, $params);
        return ['ok'=>true,'page'=>$page,'per'=>$per,'total'=>$total,'has_more'=>$page*$per<$total,'letter'=>$letter ?: null,'letters'=>$letters,'system'=>$system ?: null,'systems'=>$systems,'facets'=>new \stdClass(),'filters'=>['genre'=>null,'year'=>null,'decade'=>null,'actor'=>null],'items'=>$items,'cached'=>false];
    }

    public static function user(string $value, bool $byId = false): ?array
    {
        return self::rows('SELECT payload FROM vaultcast_users WHERE ' . ($byId ? 'id' : 'username') . ' = ?', [$value])[0] ?? null;
    }

    public static function createUser(array $user): void
    {
        Database::transaction(function (\PDO $pdo) use ($user): void {
            $s = $pdo->prepare('INSERT INTO vaultcast_users (id,username,password_hash,role,payload) VALUES (?,?,?,?,?)');
            $s->execute([$user['id'],$user['username'],$user['password_hash'],$user['role'],self::encode($user)]);
            self::putDocument('users', []);
        });
    }

    public static function updateUser(string $id, callable $update): array
    {
        return Database::transaction(function (\PDO $pdo) use ($id, $update): array {
            $s = $pdo->prepare('SELECT payload FROM vaultcast_users WHERE id=? FOR UPDATE');
            $s->execute([$id]);
            $payload = $s->fetchColumn();
            if ($payload === false) throw new \RuntimeException('User no longer exists.');
            $user = $update(json_decode($payload, true, 512, JSON_THROW_ON_ERROR));
            $s = $pdo->prepare('UPDATE vaultcast_users SET username=?,password_hash=?,role=?,payload=? WHERE id=?');
            $s->execute([$user['username'],$user['password_hash'],$user['role'],self::encode($user),$id]);
            self::putDocument('users', []);
            return $user;
        });
    }

    /** Remove a user with their sessions, playback tokens and watch progress. */
    public static function deleteUser(string $id): bool
    {
        return Database::transaction(function (\PDO $pdo) use ($id): bool {
            $s = $pdo->prepare('DELETE FROM vaultcast_users WHERE id=?');
            $s->execute([$id]);
            if ($s->rowCount() < 1) return false;
            $pdo->prepare('DELETE FROM vaultcast_sessions WHERE user_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM vaultcast_progress WHERE user_id=?')->execute([$id]);
            $pdo->prepare("DELETE FROM vaultcast_playback_tokens WHERE JSON_UNQUOTE(JSON_EXTRACT(payload,'$.user_id'))=?")->execute([$id]);
            self::putDocument('users', []);
            self::putDocument('progress', []);
            return true;
        });
    }

    public static function progress(string $userId): array
    {
        $s = Database::connection()->prepare('SELECT item_id,payload FROM vaultcast_progress WHERE user_id=? ORDER BY updated_at DESC');
        $s->execute([$userId]);
        $items = [];
        foreach ($s as $row) $items[$row['item_id']] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        return $items;
    }

    /** One item's progress — used by play-token issue (avoid loading the whole map). */
    public static function progressItem(string $userId, string $itemId): ?array
    {
        $s = Database::connection()->prepare('SELECT payload FROM vaultcast_progress WHERE user_id=? AND item_id=? LIMIT 1');
        $s->execute([$userId, $itemId]);
        $v = $s->fetchColumn();
        if ($v === false) return null;
        $j = json_decode((string)$v, true);
        return is_array($j) ? $j : null;
    }

    /**
     * Cheap upsert. Does not rewrite the progress document on every tick — that used to
     * bump Store revisions and invalidate the home cache every 5s while a video played.
     * Revision bumps at most about once every 20s so Continue Watching still refreshes.
     */
    public static function saveProgress(string $userId, string $itemId, array $progress): void
    {
        $s = Database::connection()->prepare('INSERT INTO vaultcast_progress (user_id,item_id,updated_at,payload) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE updated_at=VALUES(updated_at),payload=VALUES(payload)');
        $s->execute([$userId, $itemId, (int)($progress['updated_at'] ?? time()), self::encode($progress)]);
        $stamp = (defined('STREAM_SHM') ? STREAM_SHM : dirname(__DIR__) . '/data/cache') . '/progress-rev.stamp';
        $now = time();
        $mtime = is_file($stamp) ? (int)@filemtime($stamp) : 0;
        if ($mtime === 0 || ($now - $mtime) >= 20) {
            if (!is_dir(dirname($stamp))) @mkdir(dirname($stamp), 0775, true);
            @touch($stamp);
            self::putDocument('progress', []);
        }
    }

    public static function searchItems(string $query): array
    {
        $needle = '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        return self::rows("SELECT payload FROM vaultcast_items WHERE title LIKE ? ESCAPE '!' OR show_title LIKE ? ESCAPE '!' OR JSON_UNQUOTE(JSON_EXTRACT(payload,'$.rel')) LIKE ? ESCAPE '!' ORDER BY title,id", [$needle,$needle,$needle]);
    }

    public static function migrateProgress(array $oldIds, string $newId): int
    {
        return Database::transaction(function (\PDO $pdo) use ($oldIds, $newId): int {
            $count = 0;
            foreach ($oldIds as $oldId) {
                if ($oldId === $newId) continue;
                $s = $pdo->prepare('SELECT user_id,payload FROM vaultcast_progress WHERE item_id=? FOR UPDATE');
                $s->execute([$oldId]);
                foreach ($s->fetchAll() as $row) {
                    $progress = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
                    $current = $pdo->prepare('SELECT updated_at FROM vaultcast_progress WHERE user_id=? AND item_id=? FOR UPDATE');
                    $current->execute([$row['user_id'],$newId]);
                    $time = $current->fetchColumn();
                    if ($time === false || (int)$time < (int)$progress['updated_at']) self::saveProgress($row['user_id'], $newId, $progress);
                    $delete = $pdo->prepare('DELETE FROM vaultcast_progress WHERE user_id=? AND item_id=?');
                    $delete->execute([$row['user_id'],$oldId]);
                    $count++;
                }
            }
            if ($count) self::putDocument('progress', []);
            return $count;
        });
    }

    public static function saveSession(string $token, array $session): void
    {
        unset($session['token']);
        $s = Database::connection()->prepare('INSERT INTO vaultcast_sessions (token_hash,user_id,expires_at,payload) VALUES (?,?,?,?)');
        $s->execute([hash('sha256', $token), $session['user_id'], $session['expires_at'], self::encode($session)]);
        Database::connection()->exec('DELETE FROM vaultcast_sessions WHERE expires_at < ' . time());
    }

    public static function session(string $token): ?array
    {
        return self::rows('SELECT payload FROM vaultcast_sessions WHERE token_hash=? AND expires_at>=?', [hash('sha256', $token), time()])[0] ?? null;
    }

    public static function deleteSession(string $token): void
    {
        $s = Database::connection()->prepare('DELETE FROM vaultcast_sessions WHERE token_hash=?');
        $s->execute([hash('sha256', $token)]);
    }
}
