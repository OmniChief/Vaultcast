<?php
declare(strict_types=1);

/**
 * One inbox for everything that wants someone's attention: messages (as pointers to the chat
 * conversation), invitations and friend requests, library imports, convert requests and
 * maintenance that needs running. Rows are per person; deleting only hides it for that person.
 * The notification dot compares the newest visible item with the person's last visit (last_seen).
 */
final class Inbox
{
    const GROUPS = ['message' => 'Messages', 'invite' => 'Invites', 'library' => 'Library', 'request' => 'Requests', 'system' => 'System'];
    const SYNC_SECONDS = 60;

    private static function q(string $sql, array $args = []): PDOStatement { $s = \Vaultcast\Database::connection()->prepare($sql); $s->execute($args); return $s; }

    /**
     * Add or refresh one item. A newer created_at brings a deleted or read item back as new;
     * the same created_at (a system item that hasn't changed) leaves it exactly as the person left it.
     */
    public static function add(string $uid, string $type, string $subtype, string $dedupe, ?array $actor, string $title, string $body, array $action, array $payload = [], ?int $at = null): void
    {
        if ($uid === '' || !isset(self::GROUPS[$type])) return;
        $at = $at ?? time();
        try {
            self::q('INSERT INTO vaultcast_inbox (user_id,type,subtype,dedupe,actor,title,body,action,payload,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE deleted=IF(VALUES(created_at)>created_at,0,deleted), read_at=IF(VALUES(created_at)>created_at,0,read_at),
                type=VALUES(type), subtype=VALUES(subtype), actor=VALUES(actor), title=VALUES(title), body=VALUES(body), action=VALUES(action), payload=VALUES(payload), created_at=GREATEST(created_at,VALUES(created_at))',
                [$uid, $type, mb_substr($subtype, 0, 32), mb_substr($dedupe, 0, 120), $actor ? json_encode(self::slimActor($actor)) : '', mb_substr($title, 0, 250), mb_substr($body, 0, 600), json_encode($action), json_encode($payload), $at]);
        } catch (Throwable $e) { error_log('Vaultcast inbox: ' . $e->getMessage()); }
    }

    /** Remove a system item that is no longer true (it can come back as new later). */
    public static function resolve(string $uid, string $dedupe): void
    {
        try { self::q('DELETE FROM vaultcast_inbox WHERE user_id=? AND dedupe=?', [$uid, $dedupe]); } catch (Throwable $e) {}
    }

    private static function slimActor(array $a): array
    {
        return ['id' => (string)($a['id'] ?? ''), 'name' => (string)($a['name'] ?? $a['display_name'] ?? $a['username'] ?? ''), 'avatar' => is_string($a['avatar_url'] ?? null) ? $a['avatar_url'] : (is_string($a['avatar'] ?? null) ? $a['avatar'] : null)];
    }

    /** Which groups this person may see right now (permissions and kids profiles). */
    public static function visibleTypes(array $user): array
    {
        $admin = ($user['role'] ?? '') === 'admin';
        $types = ['message', 'invite', 'system'];
        if ($admin || Permissions::allowed($user, 'ingest_view')) $types[] = 'library';
        if ($admin) $types[] = 'request';
        if (class_exists('Parental') && Parental::restricted($user)) {
            $st = Parental::settingsFor($user);
            if (!empty($st['kid'])) $types = array_values(array_diff($types, ['library', 'request']));
            if (empty($st['social'])) $types = array_values(array_diff($types, ['message', 'invite']));
        }
        return $types;
    }

    private static function subtypeAllowed(array $user, array $row): bool
    {
        // Maintenance items are admin-only even if one was written earlier for someone who is no longer an admin.
        if ($row['subtype'] === 'maintenance' && ($user['role'] ?? '') !== 'admin') return false;
        if ($row['subtype'] === 'maintenance' && class_exists('Parental') && Parental::restricted($user)) return false;
        return true;
    }

    // ------------------------------------------------------------------ system producers (cheap, throttled)
    public static function sync(array $user, bool $force = false): void
    {
        $uid = (string)($user['id'] ?? '');
        if ($uid === '') return;
        $key = 'inbox-sync:' . hash('sha256', $uid);
        $hit = \Vaultcast\Repository::cacheGet($key);
        if (!$force && is_array($hit) && (int)($hit['at'] ?? 0) > time() - self::SYNC_SECONDS) return;
        \Vaultcast\Repository::cachePut($key, ['at' => time()], self::SYNC_SECONDS * 2);
        $admin = ($user['role'] ?? '') === 'admin';
        try {
            if ($admin) {
                $live = [];
                foreach ((array)(MaintenanceRequirements::status()['required'] ?? []) as $req) {
                    $task = (string)($req['task'] ?? ''); if ($task === '' || !isset(Maintenance::TASKS[$task])) continue;
                    $d = 'maint:' . mb_substr((string)($req['id'] ?? $task), 0, 100); $live[$d] = true;
                    self::add($uid, 'system', 'maintenance', $d, null, (string)($req['label'] ?? Maintenance::TASKS[$task]), (string)($req['reason'] ?? 'This maintenance task needs running.'), ['kind' => 'maintenance', 'task' => $task], ['task' => $task], (int)($req['at'] ?? time()));
                }
                foreach (self::q("SELECT dedupe FROM vaultcast_inbox WHERE user_id=? AND subtype='maintenance'", [$uid])->fetchAll(PDO::FETCH_COLUMN) as $d) if (!isset($live[$d])) self::resolve($uid, (string)$d);
                $live = [];
                foreach (Convert::pendingRequests() as $r) {
                    $d = 'convert:' . (string)($r['id'] ?? ''); $live[$d] = true;
                    self::add($uid, 'request', 'convert', $d, ['name' => (string)($r['requested_by'] ?? 'Someone')], 'Convert request: ' . (string)($r['title'] ?? 'a title'),
                        (string)($r['requested_by'] ?? 'Someone') . ' couldn’t play it on ' . (string)($r['device'] ?? 'a device') . '. Prepare a DirectPlay copy so it plays everywhere.', ['kind' => 'settings', 'section' => 'video-optimise'], ['request_id' => (string)($r['id'] ?? ''), 'item_id' => (string)($r['item_id'] ?? '')], strtotime((string)($r['created_at'] ?? '')) ?: time());
                }
                foreach (self::q("SELECT dedupe FROM vaultcast_inbox WHERE user_id=? AND subtype='convert'", [$uid])->fetchAll(PDO::FETCH_COLUMN) as $d) if (!isset($live[$d])) self::resolve($uid, (string)$d);
            }
            if (($admin || Permissions::allowed($user, 'ingest_view')) && !empty(Store::settings()['features']['downloads'])) {
                $n = Ingest::pendingCount();
                $row = self::q("SELECT payload,created_at FROM vaultcast_inbox WHERE user_id=? AND dedupe='ingest'", [$uid])->fetch(PDO::FETCH_ASSOC);
                $was = $row ? (int)((json_decode((string)$row['payload'], true) ?: [])['count'] ?? 0) : 0;
                if ($n <= 0) { if ($row) self::resolve($uid, 'ingest'); }
                else self::add($uid, 'library', 'ingest', 'ingest', null, $n === 1 ? '1 download is ready to import' : $n . ' downloads are ready to import', 'Review them in the import inbox and add them to your library.', ['kind' => 'settings', 'section' => 'ingest'], ['count' => $n], $n > $was || !$row ? time() : (int)$row['created_at']);
            }
        } catch (Throwable $e) { error_log('Vaultcast inbox sync: ' . $e->getMessage()); }
    }

    // ------------------------------------------------------------------ reading
    private static function lastSeen(string $uid): int
    {
        try { return (int)(self::q('SELECT last_seen FROM vaultcast_inbox_seen WHERE user_id=?', [$uid])->fetchColumn() ?: 0); } catch (Throwable $e) { return 0; }
    }

    /** The dot: anything visible newer than the last Inbox visit. */
    public static function badge(array $user): array
    {
        $uid = (string)($user['id'] ?? '');
        if ($uid === '' || ($user['role'] ?? '') === 'guest') return ['new' => false, 'latest' => 0];
        self::sync($user);
        $types = self::visibleTypes($user);
        try {
            $latest = (int)self::q('SELECT MAX(created_at) FROM vaultcast_inbox WHERE user_id=? AND deleted=0 AND type IN (' . implode(',', array_fill(0, count($types), '?')) . ")" . (($user['role'] ?? '') === 'admin' ? '' : " AND subtype<>'maintenance'"), array_merge([$uid], $types))->fetchColumn();
        } catch (Throwable $e) { $latest = 0; }
        return ['new' => $latest > self::lastSeen($uid), 'latest' => $latest];
    }

    public static function list(array $user, string $group = 'all', int $before = 0): array
    {
        $uid = (string)$user['id'];
        self::sync($user, $before === 0);
        $types = self::visibleTypes($user);
        if ($group !== 'all') $types = in_array($group, $types, true) ? [$group] : [];
        $seen = self::lastSeen($uid);
        $items = [];
        if ($types) {
            $args = array_merge([$uid], $types);
            $sql = 'SELECT id,type,subtype,dedupe,actor,title,body,action,payload,created_at,read_at FROM vaultcast_inbox WHERE user_id=? AND deleted=0 AND type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            if ($before > 0) { $sql .= ' AND created_at<?'; $args[] = $before; }
            foreach (self::q($sql . ' ORDER BY created_at DESC, id DESC LIMIT 60', $args) as $r) {
                if (!self::subtypeAllowed($user, $r)) continue;
                $actor = $r['actor'] !== '' ? json_decode((string)$r['actor'], true) : null;
                $items[] = ['id' => (int)$r['id'], 'type' => (string)$r['type'], 'subtype' => (string)$r['subtype'], 'actor' => is_array($actor) ? $actor : null, 'title' => (string)$r['title'], 'body' => (string)$r['body'],
                    'action' => json_decode((string)$r['action'], true) ?: ['kind' => 'none'], 'payload' => json_decode((string)$r['payload'], true) ?: new stdClass(), 'created_at' => (int)$r['created_at'], 'read' => (int)$r['read_at'] > 0, 'new' => (int)$r['created_at'] > $seen];
            }
        }
        $counts = [];
        try { foreach (self::q('SELECT type, COUNT(*) n FROM vaultcast_inbox WHERE user_id=? AND deleted=0 AND read_at=0 GROUP BY type', [$uid]) as $r) if (in_array($r['type'], self::visibleTypes($user), true)) $counts[(string)$r['type']] = (int)$r['n']; } catch (Throwable $e) {}
        return ['ok' => true, 'items' => $items, 'groups' => array_intersect_key(self::GROUPS, array_flip(self::visibleTypes($user))), 'unread' => (object)$counts, 'last_seen' => $seen, 'has_more' => count($items) >= 60, 'admin' => ($user['role'] ?? '') === 'admin'];
    }

    /** Visiting the Inbox clears the dot; individual items keep their own read state. */
    public static function seen(array $user): array
    {
        try { self::q('INSERT INTO vaultcast_inbox_seen (user_id,last_seen) VALUES (?,?) ON DUPLICATE KEY UPDATE last_seen=GREATEST(last_seen,VALUES(last_seen))', [(string)$user['id'], time()]); } catch (Throwable $e) {}
        return ['ok' => true, 'new' => false];
    }

    private static function ids(array $ids): array { return array_values(array_unique(array_filter(array_map('intval', array_slice($ids, 0, 500)), static function ($i) { return $i > 0; }))); }

    public static function markRead(array $user, array $ids, bool $read): array
    {
        $ids = self::ids($ids); if (!$ids) return ['ok' => true, 'changed' => 0];
        $n = self::q('UPDATE vaultcast_inbox SET read_at=? WHERE user_id=? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([$read ? time() : 0, (string)$user['id']], $ids))->rowCount();
        return ['ok' => true, 'changed' => $n];
    }

    /** Delete for this person only. A message item only leaves this inbox; the conversation is untouched. */
    public static function delete(array $user, array $ids): array
    {
        $ids = self::ids($ids); if (!$ids) return ['ok' => true, 'deleted' => 0];
        $n = self::q('UPDATE vaultcast_inbox SET deleted=1 WHERE user_id=? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([(string)$user['id']], $ids))->rowCount();
        return ['ok' => true, 'deleted' => $n];
    }

    public static function purgeUser(string $uid): void
    {
        try { self::q('DELETE FROM vaultcast_inbox WHERE user_id=?', [$uid]); self::q('DELETE FROM vaultcast_inbox_seen WHERE user_id=?', [$uid]); } catch (Throwable $e) {}
    }

    /** Trim old deleted rows now and then (called by the drop/cleanup cycle). */
    public static function cleanup(): void
    {
        try { self::q('DELETE FROM vaultcast_inbox WHERE (deleted=1 AND created_at<?) OR created_at<? LIMIT 2000', [time() - 30 * 86400, time() - 365 * 86400]); } catch (Throwable $e) {}
    }

    // ------------------------------------------------------------------ helpers for producers
    /** Map a Home hub / Play notification path to an inbox item. */
    public static function fromNotice(string $to, array $from, string $key, string $kind, string $title, string $text, string $path): void
    {
        $friend = strpos($key, 'friend') !== false;
        self::add($to, 'invite', $friend ? 'friend' : $kind, 'notice:' . $key . ':' . (string)($from['id'] ?? ''), $from, $title, $text, ['kind' => 'path', 'path' => $path]);
    }

    public static function message(string $to, array $from, string $roomId, string $roomKind, string $text, bool $image): void
    {
        $name = (string)($from['name'] ?? $from['display_name'] ?? $from['username'] ?? 'Someone');
        self::add($to, 'message', $roomKind === 'mail' ? 'mail' : 'dm', 'chat:' . $roomId, $from, 'Message from ' . $name, $image && $text === '' ? 'Sent an image' : mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 160), ['kind' => 'chat', 'room' => $roomId, 'mode' => $roomKind === 'mail' ? 'mail' : 'chat']);
    }
}
