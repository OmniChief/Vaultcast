<?php
declare(strict_types=1);

/**
 * Offline downloads to devices (PWA). The server only hands out a browser-playable file that already
 * exists (a prepared DirectPlay copy or an original MP4/WebM that plays directly), never a new encode.
 * The browser stores it in IndexedDB on that device. Each device reports what it holds so the
 * optional per-person limit can be checked, and progress watched offline syncs back later.
 */
final class Offline
{
    const PLAYABLE = ['mp4', 'm4v', 'webm'];

    public static function settings(): array
    {
        $s = Store::settings();
        $o = is_array($s['offline'] ?? null) ? $s['offline'] : [];
        return ['enabled' => !empty($s['features']['offline']), 'max_items' => max(0, min(500, (int)($o['max_items'] ?? 0))), 'user_role' => !array_key_exists('user_role', $o) || !empty($o['user_role'])];
    }

    /** Whether this person may download at all (shown to the client so the button only appears when it works). */
    public static function allowedFor(?array $user): bool
    {
        if (!$user || empty($user['id']) || ($user['role'] ?? '') === 'guest') return false;
        return self::settings()['enabled'] && Permissions::allowed($user, 'offline.download') && AreaAccess::allowed($user, 'library');
    }

    private static function docName(string $uid): string { return 'offline-devices:' . hash('sha256', $uid); }

    /** device id => [item ids], kept for the per-person limit. */
    private static function devices(string $uid): array
    {
        $d = \Vaultcast\Repository::document(self::docName($uid), []);
        return is_array($d) ? $d : [];
    }

    private static function heldElsewhere(string $uid, string $device, string $except): int
    {
        $ids = [];
        foreach (self::devices($uid) as $dev => $row) {
            if (!is_array($row)) continue;
            foreach ((array)($row['items'] ?? []) as $id) if ((string)$id !== $except) $ids[(string)$id] = true;
        }
        return count($ids);
    }

    public static function status(array $user): array
    {
        $st = self::settings();
        $held = 0; foreach (self::devices((string)($user['id'] ?? '')) as $row) $held += is_array($row) ? count((array)($row['items'] ?? [])) : 0;
        return ['ok' => true, 'enabled' => $st['enabled'], 'allowed' => self::allowedFor($user), 'max_items' => $st['max_items'], 'held' => $held];
    }

    /** A download ticket for one title, or a friendly reason why not. */
    public static function prepare(array $user, string $itemId, string $device): array
    {
        if (!self::settings()['enabled']) throw new DomainException('Offline downloads are turned off on this server.');
        if (!self::allowedFor($user)) throw new DomainException('Your account can’t download titles for offline viewing. Ask an administrator.');
        $item = Catalogue::item($itemId);
        if (!$item || ($item['media_kind'] ?? '') !== 'video') throw new InvalidArgumentException('Only films and episodes can be downloaded.');
        if (class_exists('Parental') && Parental::restricted($user) && !Parental::allows($user, $item)) throw new DomainException('This title isn’t available on this profile.');
        $max = self::settings()['max_items'];
        $uid = (string)$user['id'];
        if ($max > 0 && self::heldElsewhere($uid, $device, $itemId) >= $max) throw new DomainException('You already have ' . $max . ' titles downloaded. Delete one on the Downloads page first.');
        $play = Stream::playbackItem($item);
        $path = (string)($play['path'] ?? '');
        if ($path === '' || !is_file($path)) throw new InvalidArgumentException('The file for this title is missing on the server.');
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $ready = !empty($play['prepared_copy']) || (in_array($ext, self::PLAYABLE, true) && !Catalogue::needsConvert($item, false));
        if (!$ready) {
            return ['ok' => false, 'code' => 'needs_copy', 'save_copy' => Permissions::allowed($user, 'save_copy'),
                'error' => 'This title needs a browser-ready copy before it can be downloaded. Play it with “Watch & save copy”, or ask an administrator to prepare it in Convert. Then try again.'];
        }
        $token = Stream::createToken($uid, $itemId, $path, 'video', null, (string)($item['caption_source'] ?? $item['path'] ?? $path));
        $base = rtrim(\Vaultcast\Database::basePath(), '/');
        $card = Catalogue::publicItem($item, false);
        $subs = [];
        try { foreach (Subtitles::tracks($path, $token, (string)($item['caption_source'] ?? $item['path'] ?? $path)) as $t) if (!empty($t['url'])) $subs[] = ['url' => (string)$t['url'], 'label' => (string)($t['label'] ?? 'Subtitles'), 'lang' => (string)($t['language'] ?? '')]; } catch (Throwable $e) {}
        $facts = MediaFacts::get($item) ?: [];
        return ['ok' => true, 'item_id' => $itemId, 'url' => $base . '/api/stream/play?t=' . $token . '&direct=1', 'size' => (int)@filesize($path), 'mime' => Stream::mimeFor($ext, 'video'),
            'title' => (string)($card['title'] ?? ''), 'show' => (string)($card['show'] ?? ''), 'show_key' => (string)($card['show_key'] ?? ''), 'year' => $card['year'] ?? null,
            'season' => $card['season'] ?? null, 'episode' => $card['episode'] ?? null, 'duration' => (float)($facts['duration'] ?? 0),
            'poster' => $base . '/api/items/' . rawurlencode($itemId) . '/poster?fetch=0&variant=medium', 'subtitles' => array_slice($subs, 0, 6),
            'resume' => (float)((\Vaultcast\Repository::progressItem($uid, $itemId) ?? [])['position'] ?? 0)];
    }

    /** The device tells us what it holds (replaces its previous list). */
    public static function inventory(array $user, string $device, array $items): array
    {
        if (!preg_match('/^[a-f0-9]{16,64}$/', $device)) throw new InvalidArgumentException('Unknown device.');
        $uid = (string)$user['id'];
        $ids = array_values(array_unique(array_filter(array_map('strval', array_slice($items, 0, 500)), static function ($id) { return (bool)preg_match('/^[a-f0-9]{8,64}$/', $id); })));
        $all = self::devices($uid);
        if ($ids) $all[$device] = ['items' => $ids, 'seen' => time()]; else unset($all[$device]);
        // Devices not heard from for 120 days no longer count.
        foreach ($all as $k => $row) if (!is_array($row) || (int)($row['seen'] ?? 0) < time() - 120 * 86400) unset($all[$k]);
        \Vaultcast\Repository::putDocument(self::docName($uid), $all);
        return self::status($user);
    }

    /** Progress recorded while offline. Only newer than what the server has is applied. */
    public static function syncProgress(array $user, array $entries): array
    {
        $uid = (string)$user['id']; $applied = 0;
        foreach (array_slice($entries, 0, 200) as $e) {
            if (!is_array($e) || !preg_match('/^[a-f0-9]{8,64}$/', (string)($e['item_id'] ?? ''))) continue;
            $at = min(time(), max(0, (int)($e['at'] ?? 0)));
            $cur = \Vaultcast\Repository::progressItem($uid, (string)$e['item_id']);
            if (is_array($cur) && (int)($cur['updated_at'] ?? 0) >= $at) continue;
            \Vaultcast\Repository::saveProgress($uid, (string)$e['item_id'], ['position' => max(0.0, (float)($e['position'] ?? 0)), 'duration' => max(0.0, (float)($e['duration'] ?? 0)), 'updated_at' => $at]);
            $applied++;
        }
        return ['ok' => true, 'applied' => $applied];
    }

    public static function purgeUser(string $uid): void
    {
        try { \Vaultcast\Repository::putDocument(self::docName($uid), []); } catch (Throwable $e) {}
    }
}
