<?php
declare(strict_types=1);

class Lobby {
    const TTL_SECONDS = 90;
    const HEARTBEAT_SECONDS = 25;
    const MAX_PLAYERS_CAP = 4;

    public static function dir(): string {
        $d = STREAM_DATA . '/lobby';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }

    /** Structured JSONL lobby diagnostics → data/lobby/lobby.log (and PHP error_log). */
    public static function logEvent(string $event, array $ctx = []): void {
        $row = array_merge([
            'ts' => gmdate('c'),
            'event' => $event,
            'pid' => getmypid(),
        ], $ctx);
        $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) return;
        $path = self::dir() . '/lobby.log';
        @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        @chmod($path, 0664);
        // Also surface in PHP / nginx / php-fpm journal when available
        @error_log('[lobby] ' . $line);
    }



    /** Small, transactional room registry. Logs remain ordinary diagnostic files. */
    private static function readAll(): array {
        return \Vaultcast\Repository::document('lobby_rooms', []);
    }
    private static function writeAll(array $all): bool {
        \Vaultcast\Repository::putDocument('lobby_rooms', $all);
        return true;
    }
    private static function withLock(callable $fn) {
        return \Vaultcast\Database::transaction(function (PDO $pdo) use ($fn) {
            $pdo->exec("INSERT IGNORE INTO vaultcast_documents (name,payload) VALUES ('lobby_rooms','{}')");
            $pdo->query("SELECT name FROM vaultcast_documents WHERE name='lobby_rooms' FOR UPDATE")->fetch();
            return $fn(self::readAll());
        });
    }
    public static function numericGameId(string $itemId): int {
        $hex = preg_replace('/[^a-f0-9]/i', '', $itemId);
        if ($hex === '') return 1;
        // Stable positive 31-bit int from item id
        $h = substr(hash('sha256', strtolower($hex)), 0, 8);
        $n = (int)hexdec($h);
        if ($n < 0) $n = -$n;
        if ($n === 0) $n = 1;
        return $n & 0x7fffffff;
    }

    public static function capability(?array $item): ?array {
        if (!$item || (($item['media_kind'] ?? '') !== 'rom' && empty($item['emu_core']))) {
            // still allow roms missing media_kind if they have emu_core/system
            if (!$item || (empty($item['emu_core']) && empty($item['system']))) return null;
        }
        // Lobby seats are independent of a game's native multiplayer support.
        return [
            'multiplayer' => true,
            'max_players' => self::MAX_PLAYERS_CAP,
            'join_label' => 'Join as P{n}',
            'mp_label' => '4 lobby seats',
            'source' => 'all_games',
        ];
    }

    public static function joinLabelFor(int $nextSlot): string {
        return 'Join as P' . $nextSlot;
    }

    private static function nextGuestSlot(array $players): int {
        $usedSlots = array_map(function ($p) { return (int)($p['slot'] ?? 0); }, $players);
        $slot = 2;
        while (in_array($slot, $usedSlots, true)) $slot++;
        return $slot;
    }
    private static function purgeExpired(array &$all): void {
        $now = time();
        foreach ($all as $id => $s) {
            if (!is_array($s)) { unset($all[$id]); continue; }
            $exp = (int)($s['expires_at'] ?? 0);
            if ($exp > 0 && $exp < $now) unset($all[$id]);
        }
    }

    public static function publicSession(array $s): array {
        $players = is_array($s['players'] ?? null) ? $s['players'] : [];
        $filled = count($players);
        $max = max(2, min(self::MAX_PLAYERS_CAP, (int)($s['max_players'] ?? 2)));
        $next = self::nextGuestSlot($players);
        return [
            'id' => (string)($s['id'] ?? ''),
            'item_id' => (string)($s['item_id'] ?? ''),
            'title' => (string)($s['title'] ?? ''),
            'system' => (string)($s['system'] ?? ''),
            'emu_core' => (string)($s['emu_core'] ?? ''),
            'runtime' => $s['runtime'] ?? null,
            'host_name' => (string)($s['host_name'] ?? 'Host'),
            'visibility' => ($s['visibility'] ?? 'public')==='private'?'private':'public',
            'players_filled' => $filled,
            'max_players' => $max,
            'open_slots' => max(0, $max - $filled),
            'join_label' => self::joinLabelFor($next),
            'room_code' => (string)($s['room_code'] ?? ''),
            'game_id' => (int)($s['game_id'] ?? 0),
            'game_path' => $s['game_path'] ?? null,
            'mp_label' => $max . ' lobby seats',
            'created_at' => (int)($s['created_at'] ?? 0),
            'updated_at' => (int)($s['updated_at'] ?? 0),
            'has_poster' => !empty($s['has_poster']),
            'players' => array_values(array_map(static function(array $player): array {
                return ['slot'=>(int)($player['slot'] ?? 0), 'name'=>(string)($player['name'] ?? 'Player'), 'role'=>($player['role'] ?? '') === 'host' ? 'host' : 'guest', 'avatar_url'=>$player['avatar_url'] ?? null];
            }, $players)),
        ];
    }

    public static function listOpen(string $viewer=''): array {
        // Polls only filter their snapshot. Mutating operations prune under the lock.
        $all = self::readAll();
        self::purgeExpired($all);
        $out = [];
        foreach ($all as $s) {
            if (!is_array($s)) continue;
            if (($s['visibility'] ?? 'public')==='private' && ($viewer==='' || $viewer!==($s['host_user_id'] ?? ''))) continue;
            $pub = self::publicSession($s);
            unset($pub['runtime']); // Full boot options arrive with the join response.
            // Keep full sessions visible so host presence is not mistaken for "ended".
            $out[] = $pub;
        }
        usort($out, function ($a, $b) {
            return ($b['updated_at'] ?? 0) <=> ($a['updated_at'] ?? 0);
        });
        return $out;
    }

    private static function runtimeConfig(array $runtime): ?array {
        if (!$runtime) return null;
        $core = (string)($runtime['core'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_]{1,48}$/D', $core)) throw new DomainException('Invalid emulator core.');
        $variant = (string)($runtime['variant'] ?? '');
        if (!in_array($variant, ['legacy','modern'], true)) throw new DomainException('Invalid emulator build.');
        $options = [];
        foreach (array_slice((array)($runtime['options'] ?? []), 0, 128, true) as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_-]{1,96}$/D', $key) || !is_string($value) || strlen($value)>256 || preg_match('/[\r\n\x00]/', $value)) continue;
            $options[$key] = $value;
        }
        return ['core'=>$core, 'variant'=>$variant, 'threaded'=>!empty($runtime['threaded']), 'options'=>$options];
    }
    public static function create(array $item, string $hostName, string $hostKey, ?int $maxOverride = null, array $profile = [], array $runtime = [], string $visibility = 'public'): array {
        $cap = self::capability($item);
        if (!$cap) {
            return ['ok' => false, 'error' => 'Choose a game from the library'];
        }
        $max = $maxOverride !== null ? $maxOverride : (int)$cap['max_players'];
        $max = max(2, min(self::MAX_PLAYERS_CAP, $max));
        $id = bin2hex(random_bytes(8));
        $room = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $now = time();
        $hostName = trim($hostName) !== '' ? trim($hostName) : 'Host';
        $hostName = mb_substr($hostName, 0, 32);
        if (!empty($profile['name'])) $hostName = mb_substr((string)$profile['name'], 0, 32);
        $sess = [
            'id' => $id,
            'item_id' => (string)($item['id'] ?? ''),
            'title' => (string)($item['title'] ?? 'ROM'),
            'system' => (string)($item['system'] ?? ''),
            'emu_core' => (string)($item['emu_core'] ?? ''),
            'runtime' => self::runtimeConfig($runtime),
            'host_name' => $hostName,
            'host_user_id' => $profile['user_id'] ?? null,
            'visibility' => $visibility==='private'?'private':'public',
            'host_key' => hash('sha256', $hostKey),
            'max_players' => $max,
            'join_label_template' => (string)$cap['join_label'],
            'mp_label' => (string)$cap['mp_label'],
            'room_code' => $room,
            'game_id' => self::numericGameId((string)($item['id'] ?? $id)),
            'game_path' => Slugs::game($item),
            'has_poster' => !empty($item['has_poster']) || !empty($item['poster']),
            'players' => [
                [
                    'slot' => 1,
                    'name' => $hostName,
                    'role' => 'host',
                    'avatar_url' => $profile['avatar_url'] ?? null,
                    'joined_at' => $now,
                ],
            ],
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $now + self::TTL_SECONDS,
        ];
        $wrote = false;
        self::withLock(function (array $all) use ($sess, &$wrote) {
            self::purgeExpired($all);
            // One live host session per item+host to avoid duplicates
            foreach ($all as $oid => $os) {
                if (!is_array($os)) continue;
                if (($os['item_id'] ?? '') === $sess['item_id'] && ($os['host_key'] ?? '') === $sess['host_key']) {
                    unset($all[$oid]);
                }
            }
            $all[$sess['id']] = $sess;
            $wrote = self::writeAll($all);
            return $wrote;
        });
        if (!$wrote) {
            self::logEvent('create_fail', ['item_id' => $sess['item_id'], 'error' => 'write']);
            return ['ok' => false, 'error' => 'Could not save lobby session (check data/lobby write permissions)'];
        }
        // Confirm visible to listOpen readers
        $check = self::readAll();
        if (!isset($check[$sess['id']])) {
            return ['ok' => false, 'error' => 'Lobby session did not persist after create'];
        }
        self::logEvent('create', [
            'session_id' => $id,
            'item_id' => $sess['item_id'],
            'title' => $sess['title'],
            'host_name' => $hostName,
            'room_code' => $room,
            'game_id' => $sess['game_id'],
            'max_players' => $max,
        ]);
        return [
            'ok' => true,
            'session' => self::publicSession($sess),
            'host_key' => $hostKey,
            'role' => 'host',
            'player_slot' => 1,
        ];
    }

    public static function heartbeat(string $id, string $hostKey): array {
        return self::withLock(function (array $all) use ($id, $hostKey) {
            self::purgeExpired($all);
            if (!isset($all[$id]) || !is_array($all[$id])) {
                self::writeAll($all);
                return ['ok' => false, 'error' => 'Session not found'];
            }
            $s = $all[$id];
            if (!hash_equals((string)($s['host_key'] ?? ''), hash('sha256', $hostKey))) {
                return ['ok' => false, 'error' => 'Not the host'];
            }
            $now = time();
            $s['updated_at'] = $now;
            $s['expires_at'] = $now + self::TTL_SECONDS;
            $all[$id] = $s;
            self::writeAll($all);
            self::logEvent('heartbeat', ['session_id' => $id, 'players' => count(is_array($s['players'] ?? null) ? $s['players'] : [])]);
            return ['ok' => true, 'session' => self::publicSession($s)];
        });
    }

    public static function end(string $id, string $hostKey): array {
        return self::withLock(function (array $all) use ($id, $hostKey) {
            self::purgeExpired($all);
            if (!isset($all[$id]) || !is_array($all[$id])) {
                self::writeAll($all);
                return ['ok' => true, 'ended' => false];
            }
            $s = $all[$id];
            if (!hash_equals((string)($s['host_key'] ?? ''), hash('sha256', $hostKey))) {
                return ['ok' => false, 'error' => 'Not the host'];
            }
            unset($all[$id]);
            self::writeAll($all);
            self::logEvent('end', ['session_id' => $id, 'ended' => true]);
            return ['ok' => true, 'ended' => true];
        });
    }

    public static function join(string $id, string $playerName, array $profile = []): array {
        if (!empty($profile['name'])) $playerName = (string)$profile['name'];
        $playerName = trim($playerName) !== '' ? trim($playerName) : 'Player';
        $playerName = mb_substr($playerName, 0, 32);
        return self::withLock(function (array $all) use ($id, $playerName, $profile) {
            self::purgeExpired($all);
            if (!isset($all[$id]) || !is_array($all[$id])) {
                self::writeAll($all);
                self::logEvent('join_fail', ['session_id' => $id, 'error' => 'not_found']);
                return ['ok' => false, 'error' => 'Session not found or ended'];
            }
            $s = $all[$id];
            $players = is_array($s['players'] ?? null) ? $s['players'] : [];
            $max = max(2, min(self::MAX_PLAYERS_CAP, (int)($s['max_players'] ?? 2)));
            if (count($players) >= $max) {
                self::logEvent('join_fail', ['session_id' => $id, 'error' => 'full', 'players' => count($players), 'max' => $max]);
                return ['ok' => false, 'error' => 'Session is full'];
            }
            $slot = self::nextGuestSlot($players);
            $players[] = [
                'slot' => $slot,
                'name' => $playerName,
                'role' => 'guest',
                'avatar_url' => $profile['avatar_url'] ?? null,
                'joined_at' => time(),
            ];
            $s['players'] = $players;
            $s['updated_at'] = time();
            $s['expires_at'] = time() + self::TTL_SECONDS;
            $all[$id] = $s;
            self::writeAll($all);
            self::logEvent('join', [
                'session_id' => $id,
                'player_name' => $playerName,
                'player_slot' => $slot,
                'players_filled' => count($players),
                'max_players' => $max,
                'item_id' => (string)($s['item_id'] ?? ''),
                'room_code' => (string)($s['room_code'] ?? ''),
            ]);
            return [
                'ok' => true,
                'session' => self::publicSession($s),
                'role' => 'guest',
                'player_slot' => $slot,
                'join_label' => self::joinLabelFor($slot),
            ];
        });
    }

    public static function leave(string $id, int $slot): array {
        return self::withLock(function (array $all) use ($id, $slot) {
            self::purgeExpired($all);
            if (!isset($all[$id]) || !is_array($all[$id])) {
                self::writeAll($all);
                return ['ok' => true];
            }
            $s = $all[$id];
            $players = is_array($s['players'] ?? null) ? $s['players'] : [];
            if ($slot <= 1) {
                return ['ok' => false, 'error' => 'Host must end the session with the host key'];
            }
            $s['players'] = array_values(array_filter($players, function ($p) use ($slot) {
                return (int)($p['slot'] ?? 0) !== $slot;
            }));
            // Existing guests keep their controller and leave-request slot.
            $s['updated_at'] = time();
            $all[$id] = $s;
            self::writeAll($all);
            return ['ok' => true, 'session' => self::publicSession($s)];
        });
    }

    public static function get(string $id): ?array {
        $all = self::readAll();
        self::purgeExpired($all);
        if (!isset($all[$id]) || !is_array($all[$id])) return null;
        return $all[$id];
    }

    /** All catalogue ROM items available to host in the lobby (paginated). */
    public static function multiplayerItems(string $sectionId, string $q = '', string $system = '', int $page = 1, int $per = 48): array {
        $items = Catalogue::sectionItems($sectionId);
        $out = [];
        $ql = strtolower(trim($q));
        $sl = strtolower(trim($system));
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            if (($it['media_kind'] ?? '') !== 'rom') continue;
            $cap = self::capability($it);
            if (!$cap) continue;
            if ($sl !== '' && strtolower(trim((string)($it['system'] ?? ''))) !== $sl) continue;
            if ($ql !== '') {
                $hay = strtolower(($it['title'] ?? '') . ' ' . ($it['system'] ?? ''));
                if (strpos($hay, $ql) === false) continue;
            }
            $pub = Catalogue::publicItem($it);
            $pub['multiplayer'] = true;
            $pub['max_players'] = $cap['max_players'];
            $pub['join_label'] = $cap['join_label'];
            $pub['mp_label'] = $cap['mp_label'];
            $pub['game_id'] = self::numericGameId((string)($it['id'] ?? ''));
            $out[] = $pub;
        }
        usort($out, function ($a, $b) {
            return strcasecmp($a['title'] ?? '', $b['title'] ?? '');
        });
        $total = count($out);
        $page = max(1, $page);
        $per = max(1, min(100, $per));
        $slice = array_slice($out, ($page - 1) * $per, $per);
        return [
            'ok' => true,
            'page' => $page,
            'per' => $per,
            'total' => $total,
            'has_more' => ($page * $per) < $total,
            'items' => $slice,
        ];
    }

    /** EmulatorJS /list shape from open lobby sessions for a numeric game_id. */
    public static function netplayListForGameId($gameId): array {
        $gid = (int)$gameId;
        $rooms = [];
        foreach (self::listOpen() as $s) {
            if ((int)($s['game_id'] ?? 0) !== $gid) continue;
            if ((int)($s['open_slots'] ?? 0) <= 0) continue;
            $rooms[(string)$s['room_code']] = [
                'room_name' => (string)($s['title'] ?? 'Room') . ' ┬À ' . (string)($s['host_name'] ?? 'Host'),
                'current' => (int)($s['players_filled'] ?? 1),
                'max' => (int)($s['max_players'] ?? 2),
                'player_name' => (string)($s['host_name'] ?? 'Host'),
                'hasPassword' => false,
                'lobby_id' => (string)($s['id'] ?? ''),
            ];
        }
        return $rooms;
    }
}
