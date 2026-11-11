<?php
declare(strict_types=1);

use Vaultcast\Database;

final class LinkedServers
{
    private const PROTOCOL = 1;
    private const PAGE_SIZE = 24;
    public const MEDIA_TYPES = ['mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm'];
    private const COPY_EXTENSIONS = ['mp4', 'm4v', 'webm', 'mkv', 'avi', 'mov', 'mpeg', 'mpg', 'wmv', 'ts', 'm2ts', 'ogv', 'flv'];

    private static function query(string $sql, array $args = []): PDOStatement
    {
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);
        return $stmt;
    }

    public static function allowed(array $user): bool
    {
        $parental = Parental::settingsFor($user);
        return !in_array($user['role'] ?? 'guest', ['guest', ''], true)
            && AreaAccess::allowed($user, 'library')
            && !$parental['enabled'] && !$parental['kid'];
    }

    private static function sections(): array
    {
        $out = [];
        foreach (Store::settings()['sections'] ?? [] as $section) {
            if (!empty($section['enabled']) && !empty($section['id']) && !in_array($section['type'] ?? '', ['roms', 'music'], true)) {
                $out[(string)$section['id']] = (string)($section['label'] ?? $section['name'] ?? $section['id']);
            }
        }
        return $out;
    }

    public static function incoming(): array
    {
        return self::query('SELECT id,label FROM vaultcast_linked_servers WHERE connected=1 ORDER BY label,id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function settings(): array
    {
        $sections = [];
        foreach (self::sections() as $id => $label) $sections[] = ['id' => $id, 'label' => $label];
        return [
            'sections' => $sections,
            'public_url' => (string)(Store::settings()['public_base_url'] ?? ''),
            'download_storage' => LinkedDownloads::storageSetting(),
            'incoming' => self::query('SELECT id,base_url,label,connected FROM vaultcast_linked_servers ORDER BY label,id')->fetchAll(PDO::FETCH_ASSOC),
            'outgoing' => self::query('SELECT id,label,section_id,invite_until,allow_downloads,peer_hash IS NOT NULL AS claimed FROM vaultcast_linked_shares ORDER BY invite_until DESC')->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public static function createInvite(array $body): array
    {
        $section = (string)($body['section'] ?? '');
        $sections = self::sections();
        if (!isset($sections[$section])) throw new DomainException('Choose a film or TV library to share.');
        $label = self::label($body['label'] ?? '', $sections[$section]);
        $id = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));
        self::query('INSERT INTO vaultcast_linked_shares (id,label,section_id,invite_hash,invite_until,allow_downloads) VALUES (?,?,?,?,?,?)',
            [$id, $label, $section, hash('sha256', $secret), time() + 86400, empty($body['downloads']) ? 0 : 1]);
        return ['invite' => $id . '.' . $secret];
    }

    private static function label($value, string $fallback): string
    {
        $label = is_string($value) ? trim($value) : '';
        return mb_substr($label !== '' ? $label : $fallback, 0, 120);
    }

    public static function remove(string $direction, string $id): void
    {
        if ($direction === 'outgoing') {
            self::query('DELETE FROM vaultcast_linked_shares WHERE id=?', [$id]);
        } else {
            Database::transaction(static function () use ($id): void {
                self::query('DELETE FROM vaultcast_linked_servers WHERE id=?', [$id]);
                self::query('DELETE FROM vaultcast_linked_progress WHERE link_id=?', [$id]);
            });
        }
    }

    public static function connect(array $body): void
    {
        $base = self::baseUrl(trim((string)($body['url'] ?? '')), !empty($body['lan']));
        $invite = strtolower(trim((string)($body['invite'] ?? '')));
        if (!preg_match('/^([a-f0-9]{32})\.([a-f0-9]{64})$/D', $invite, $match)) {
            throw new DomainException('Paste the full invite code from the sharing server.');
        }
        $id = bin2hex(random_bytes(16));
        $peerKey = bin2hex(random_bytes(32));
        $fallback = (string)parse_url($base, PHP_URL_HOST);
        // Keep the same peer key when retrying after a timeout during redemption.
        self::query('INSERT INTO vaultcast_linked_servers (id,share_id,base_url,label,peer_key) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',
            [$id, $match[1], $base, $fallback, $peerKey]);
        $link = self::query('SELECT * FROM vaultcast_linked_servers WHERE base_url=? AND share_id=?', [$base, $match[1]])->fetch(PDO::FETCH_ASSOC);
        if (!$link) throw new DomainException('This connection was removed. Try linking again.');
        if ($link['connected']) throw new DomainException('This library is already linked.');
        $reply = self::request($link, 'redeem', ['invite' => $match[2], 'key' => $link['peer_key']]);
        $label = self::label($body['label'] ?? '', self::label($reply['label'] ?? '', $fallback));
        $updated = self::query('UPDATE vaultcast_linked_servers SET label=?, connected=1 WHERE id=?', [$label, $link['id']]);
        if (!$updated->rowCount() && !self::query('SELECT 1 FROM vaultcast_linked_servers WHERE id=? AND connected=1', [$link['id']])->fetchColumn()) {
            throw new DomainException('This connection was removed. Ask for a new invite.');
        }
    }

    private static function baseUrl(string $url, bool $allowLan): string
    {
        $url = rtrim($url, '/');
        if (strlen($url) > 255) throw new DomainException('The sharing server address is too long.');
        if ($allowLan && preg_match('#^http://([0-9.]+)(?::([0-9]{1,5}))?((?:/[a-zA-Z0-9_-][a-zA-Z0-9_.~-]*)*)$#D', $url, $lan)) {
            $port = $lan[2] !== '' ? (int)$lan[2] : 80;
            if (self::privateAddress($lan[1]) && $port >= 1 && $port <= 65535) {
                return 'http://' . $lan[1] . ($port === 80 ? '' : ':' . $port) . $lan[3];
            }
        }
        if (!preg_match('#^https://([a-z0-9.-]+)(?::443)?((?:/[a-zA-Z0-9_-][a-zA-Z0-9_.~-]*)*)$#iD', $url, $m)
            || strpos($m[1], '.') === false || !preg_match('/\.[a-z][a-z0-9-]*$/iD', $m[1])
            || !filter_var($m[1], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new DomainException($allowLan ? 'Enter a private LAN IPv4 address with http:// and the sharing server’s port.' :
                'Enter the sharing server’s public HTTPS address, or enable the home network option.');
        }
        return 'https://' . strtolower($m[1]) . $m[2];
    }

    private static function privateAddress(string $ip): bool
    {
        return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && (bool)preg_match('/^(?:10\.|192\.168\.|172\.(?:1[6-9]|2[0-9]|3[01])\.)/', $ip);
    }

    private static function lan(array $link): bool
    {
        return strpos($link['base_url'], 'http://') === 0;
    }

    private static function publicAddress(string $host): string
    {
        $addresses = @gethostbynamel($host);
        if (!$addresses) throw new DomainException('Could not find the sharing server’s public address.');
        foreach ($addresses as $ip) {
            // Only allow globally reachable IPv4. Reject the entire DNS answer if it includes a local address.
            if (!WebFetch::publicIp($ip)) {
                throw new DomainException('Linked servers need a publicly reachable HTTPS address.');
            }
        }
        return $addresses[0];
    }

    public static function peerConnection(array $link, string $action, array $params = [], bool $post = false)
    {
        if (!function_exists('curl_init')) {
            throw new DomainException('The Vaultcast web runtime needs cURL for server linking.');
        }
        $lan = self::lan($link);
        $base = self::baseUrl($link['base_url'], $lan);
        $host = (string)parse_url($base, PHP_URL_HOST);
        $url = $base . '/api/linked-peer/' . $link['share_id'] . '/' . $action;
        if (!$post && $params) $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $curl = curl_init($url);
        $headers = [];
        if ($action !== 'redeem') $headers[] = 'Authorization: Bearer ' . $link['peer_key'];
        if ($post) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($params)]);
        }
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROTOCOLS => $lan ? CURLPROTO_HTTP : CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        if (!$lan) curl_setopt($curl, CURLOPT_RESOLVE, [$host . ':443:' . self::publicAddress($host)]);
        return $curl;
    }

    private static function request(array $link, string $action, array $params = []): array
    {
        $buffer = '';
        $curl = self::peerConnection($link, $action, $params, in_array($action, ['redeem', 'ticket', 'copy-info'], true));
        curl_setopt_array($curl, [
            CURLOPT_TIMEOUT => 8,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$buffer): int {
                if (strlen($buffer) + strlen($chunk) > 131072) return 0;
                $buffer .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($ok === false) throw new DomainException(self::lan($link) ? 'Could not reach the sharing server on the home network.' : 'Could not reach the sharing server over HTTPS.');
        $reply = json_decode($buffer, true);
        if (!is_array($reply) || ($reply['protocol'] ?? null) !== self::PROTOCOL) {
            throw new DomainException('The sharing server must be running a Vaultcast version that supports server linking.');
        }
        if ($status !== 200 || empty($reply['ok'])) {
            throw new DomainException(self::label($reply['error'] ?? '', 'The sharing server refused this request.'));
        }
        return $reply;
    }

    public static function copyInfo(array $link, string $item): array
    {
        $reply = self::request($link, 'copy-info', ['item' => $item]);
        if (!in_array($reply['extension'] ?? null, self::COPY_EXTENSIONS, true) || !self::hex($reply['file_key'] ?? null)
            || !is_int($reply['bytes'] ?? null) || $reply['bytes'] <= 0) {
            throw new DomainException('The sharing server did not provide a valid file for download.');
        }
        return ['title' => self::label($reply['title'] ?? '', 'Linked video'), 'extension' => $reply['extension'],
            'file_key' => $reply['file_key'], 'bytes' => $reply['bytes']];
    }

    public static function peerError(string $message, int $status): void
    {
        json_out(['ok' => false, 'protocol' => self::PROTOCOL, 'error' => $message], $status);
    }

    private static function peerReply(array $reply): void
    {
        json_out(['ok' => true, 'protocol' => self::PROTOCOL] + $reply);
    }

    public static function peer(string $id, string $action, string $method): void
    {
        // Linked URLs must not change the host's DirectPlay delivery policy.
        unset($_GET['direct']);
        $share = self::query('SELECT * FROM vaultcast_linked_shares WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
        if (!$share || !isset(self::sections()[$share['section_id']])) self::peerError('This library share is no longer available.', 404);

        if ($action === 'redeem' && $method === 'POST') {
            $body = body_json();
            $invite = $body['invite'] ?? '';
            $key = $body['key'] ?? '';
            if (!self::hex($invite) || !self::hex($key) || !hash_equals($share['invite_hash'], hash('sha256', $invite))) {
                self::peerError('Invalid invite code.', 403);
            }
            $hash = hash('sha256', $key);
            // The conditional update allows exactly one peer to claim an unused invite.
            self::query('UPDATE vaultcast_linked_shares SET peer_hash=? WHERE id=? AND invite_until>=? AND peer_hash IS NULL', [$hash, $id, time()]);
            $current = self::query('SELECT peer_hash FROM vaultcast_linked_shares WHERE id=?', [$id])->fetchColumn();
            if (!is_string($current) || !hash_equals($current, $hash)) self::peerError('This invite has expired or has already been used.', 403);
            self::peerReply(['label' => $share['label']]);
        }

        if ($action === 'media' && isset($_GET['ticket']) && in_array($method, ['GET', 'HEAD'], true)) {
            self::serve($share);
        }
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/D', $auth, $m) || !$share['peer_hash']
            || !hash_equals($share['peer_hash'], hash('sha256', $m[1]))) self::peerError('This server is not linked.', 403);

        if ($action === 'catalogue' && $method === 'GET') {
            self::peerReply(['downloads' => (bool)$share['allow_downloads']] +
                self::catalogue($share['section_id'], (string)($_GET['q'] ?? ''), self::page($_GET['page'] ?? 1)));
        }
        if ($action === 'media' && in_array($method, ['GET', 'HEAD'], true)) {
            [$file, $mime] = self::mediaFile($share, (string)($_GET['item'] ?? ''));
            Stream::emitAccelOrSend($file, $mime);
        }
        if ($action === 'copy-info' || $action === 'copy') {
            if (!$share['allow_downloads']) self::peerError('The sharing server has disabled downloads for this library.', 403);
            $item = (string)($action === 'copy-info' ? (body_json()['item'] ?? '') : ($_GET['item'] ?? ''));
            [$file, $mime, $record] = self::mediaFile($share, $item, true);
            $stat = stat($file);
            if (!$stat || $stat['size'] <= 0) self::peerError('The file is unavailable.', 404);
            $fingerprint = self::sign($share['peer_hash'], 'copy', $item, $file, (string)$stat['size'], (string)$stat['mtime']);
            if ($action === 'copy-info' && $method === 'POST') {
                $name = implode(' · ', array_filter([$record['show'] ?? '', $record['title'] ?? '']));
                self::peerReply(['title' => $name, 'extension' => strtolower(pathinfo($file, PATHINFO_EXTENSION)), 'file_key' => $fingerprint, 'bytes' => $stat['size']]);
            }
            if ($action === 'copy' && $method === 'GET') {
                if (!hash_equals($fingerprint, (string)($_GET['file'] ?? ''))) self::peerError('The source file changed. Remove the partial copy and download it again.', 409);
                Stream::emitAccelOrSend($file, $mime);
            }
        }
        if ($action === 'ticket' && $method === 'POST') {
            $item = (string)(body_json()['item'] ?? '');
            self::mediaFile($share, $item);
            $until = time() + 8 * 3600;
            self::peerReply(['until' => $until, 'ticket' => self::sign($share['peer_hash'], 'media', $id, $item, (string)$until)]);
        }
        self::peerError('Unknown linked server action.', 404);
    }

    private static function catalogue(string $section, string $search, int $page): array
    {
        $search = mb_substr(trim($search), 0, 120);
        $sql = "SELECT id,title,show_title,payload FROM vaultcast_items WHERE section_id=? AND media_kind='video'";
        $args = [$section];
        if ($search !== '') {
            $needle = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $sql .= " AND (title LIKE ? ESCAPE '!' OR show_title LIKE ? ESCAPE '!')";
            array_push($args, $needle, $needle);
        }
        $offset = ($page - 1) * self::PAGE_SIZE;
        $rows = self::query($sql . ' ORDER BY title,id LIMIT ' . (self::PAGE_SIZE + 1) . ' OFFSET ' . $offset, $args)->fetchAll(PDO::FETCH_ASSOC);
        $items = [];
        foreach (array_slice($rows, 0, self::PAGE_SIZE) as $row) {
            $item = json_decode($row['payload'], true) ?: [];
            // Explicit allowlist: never expose local files, provider details, or settings.
            $items[] = [
                'id' => $row['id'],
                'title' => self::label(Catalogue::fixText($row['title']), 'Untitled'),
                'show' => self::label(Catalogue::fixText($row['show_title']), ''),
                'year' => (int)($item['year'] ?? 0),
                'season' => isset($item['season']) ? (int)$item['season'] : null,
                'episode' => isset($item['episode']) ? (int)$item['episode'] : null,
            ];
        }
        return ['items' => $items, 'more' => count($rows) > self::PAGE_SIZE && $page < 10000];
    }

    private static function hex($value): bool
    {
        return is_string($value) && (bool)preg_match('/^[a-f0-9]{64}$/D', $value);
    }

    public static function itemId($value): bool
    {
        return is_string($value) && (bool)preg_match('/^[a-f0-9]{8,64}$/D', $value);
    }

    public static function page($page): int
    {
        return min(10000, max(1, (int)$page));
    }

    private static function sign(string $key, string ...$parts): string
    {
        return hash_hmac('sha256', implode(':', $parts), $key);
    }

    private static function mediaFile(array $share, string $id, bool $original = false): array
    {
        $item = self::itemId($id) ? Catalogue::item($id) : null;
        if (!$item || ($item['section'] ?? '') !== $share['section_id'] || ($item['media_kind'] ?? '') !== 'video') {
            self::peerError('This video is not in the shared library.', 404);
        }
        $play = $original ? $item : Stream::playbackItem($item);
        $real = realpath((string)($play['path'] ?? ''));
        if ($real === false || !is_file($real) || !is_readable($real)) self::peerError('The video file is unavailable on the sharing server.', 404);
        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        if ($original && !in_array($ext, self::COPY_EXTENSIONS, true)) self::peerError('This video file type is not supported for saved copies.', 422);
        $mime = $original ? Stream::mimeFor($ext, 'video') : (self::MEDIA_TYPES[$ext] ?? null);
        if ($mime === null) self::peerError('This video needs a DirectPlay MP4 copy on the sharing server.', 422);
        return [$real, $mime, $item];
    }

    private static function serve(array $share): void
    {
        $item = (string)($_GET['item'] ?? '');
        $until = (int)($_GET['until'] ?? 0);
        $ticket = $_GET['ticket'] ?? '';
        if (!$share['peer_hash'] || !self::itemId($item) || $until < time() || !self::hex($ticket)
            || !hash_equals(self::sign($share['peer_hash'], 'media', $share['id'], $item, (string)$until), $ticket)) {
            self::peerError('Playback has expired. Open the video again.', 403);
        }
        [$path, $mime] = self::mediaFile($share, $item);
        header('Cross-Origin-Resource-Policy: cross-origin');
        header('Referrer-Policy: no-referrer');
        Stream::emitAccelOrSend($path, $mime);
    }

    public static function incomingLink(string $id): array
    {
        $link = self::query('SELECT * FROM vaultcast_linked_servers WHERE id=? AND connected=1', [$id])->fetch(PDO::FETCH_ASSOC);
        if (!$link) json_error('Linked library not found.', 404);
        return $link;
    }

    public static function browse(array $link, string $search, int $page): array
    {
        $reply = self::request($link, 'catalogue', ['q' => mb_substr(trim($search), 0, 120), 'page' => $page]);
        $items = [];
        foreach (array_slice((array)($reply['items'] ?? []), 0, self::PAGE_SIZE) as $item) {
            if (!is_array($item) || !self::itemId($item['id'] ?? null)) continue;
            $items[] = [
                'id' => $item['id'],
                'title' => self::label($item['title'] ?? '', 'Untitled'),
                'show' => self::label($item['show'] ?? '', ''),
                'year' => (int)($item['year'] ?? 0),
                'season' => isset($item['season']) ? (int)$item['season'] : null,
                'episode' => isset($item['episode']) ? (int)$item['episode'] : null,
            ];
        }
        return ['items' => $items, 'downloads' => !empty($reply['downloads']), 'more' => !empty($reply['more']) && $page < 10000];
    }

    public static function play(array $link, string $item, string $viewer): array
    {
        $reply = self::request($link, 'ticket', ['item' => $item]);
        $until = (int)($reply['until'] ?? 0);
        $ticket = $reply['ticket'] ?? '';
        if ($until < time() || $until > time() + 9 * 3600 || !self::hex($ticket)) {
            throw new DomainException('The sharing server sent an invalid playback ticket.');
        }
        $url = self::lan($link)
            ? rtrim(Database::basePath(), '/') . '/api/linked-servers/' . $link['id'] . '/media?'
                . http_build_query(['item' => $item, 'until' => $until, 'ticket' => self::sign($link['peer_key'], 'relay', $viewer, $item, (string)$until)])
            : $link['base_url'] . '/api/linked-peer/' . $link['share_id'] . '/media?'
                . http_build_query(['item' => $item, 'until' => $until, 'ticket' => $ticket]);
        $position = self::query('SELECT position FROM vaultcast_linked_progress WHERE user_id=? AND link_id=? AND item_id=?',
            [$viewer, $link['id'], $item])->fetchColumn();
        return ['url' => $url, 'position' => (float)$position, 'until' => $until,
            'progress_key' => self::sign($link['peer_key'], 'progress', $viewer, $item, (string)$until)];
    }

    public static function relay(array $link, string $viewer, string $method): void
    {
        $item = $_GET['item'] ?? '';
        $until = (int)($_GET['until'] ?? 0);
        $ticket = $_GET['ticket'] ?? '';
        if (!self::lan($link) || !self::itemId($item) || $until < time() || !self::hex($ticket)
            || !hash_equals(self::sign($link['peer_key'], 'relay', $viewer, $item, (string)$until), $ticket)) {
            json_error('Playback has expired. Open the video again.', 403);
        }
        LinkedMediaTransfer::relay($link, $item, $method);
    }

    public static function saveProgress(array $link, string $item, string $viewer, array $body): void
    {
        $until = (int)($body['until'] ?? 0);
        $key = $body['key'] ?? '';
        if ($until < time() || !self::hex($key)
            || !hash_equals(self::sign($link['peer_key'], 'progress', $viewer, $item, (string)$until), $key)) {
            json_error('Playback has expired. Open the video again.', 403);
        }
        $position = (float)($body['position'] ?? 0);
        if (!is_finite($position) || $position < 0 || $position > 86400) json_error('Invalid playback position.', 422);
        self::query('INSERT INTO vaultcast_linked_progress (user_id,link_id,item_id,position)
            SELECT u.id,s.id,?,? FROM vaultcast_users u JOIN vaultcast_linked_servers s ON s.id=? AND s.connected=1 WHERE u.id=?
            ON DUPLICATE KEY UPDATE position=VALUES(position)', [$item, $position, $link['id'], $viewer]);
    }
}
