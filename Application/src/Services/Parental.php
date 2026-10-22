<?php
declare(strict_types=1);

/**
 * Parental controls and kids profiles.
 *
 * Settings live in vaultcast_parental (never in the user record, so the PIN hash cannot travel with
 * user payloads). Admins are never restricted. A restricted viewer gets:
 *  - an age ceiling (ContentRatings buckets 1..5) with a rule for unrated titles (allow or hide);
 *  - Live and Play switched off through AreaAccess when those toggles are off;
 *  - chat, DMs and other social surfaces refused when social is off.
 * Enforcement is on the server: Parental::gate() refuses direct requests for a blocked title, and
 * Parental::filterPayload() removes blocked cards from every JSON response (json_out) and the first-page
 * snapshot, so listings, search, home rows, collections and widgets never carry their titles or art.
 * A PIN (4-6 digits, password_hash) unlocks the account for one hour on this device, and is required to
 * change self-managed settings.
 */
final class Parental
{
    const DEFAULTS = ['enabled' => false, 'kid' => false, 'ceiling' => 5, 'unrated' => 'allow', 'live' => true, 'play' => true, 'social' => true, 'self_manage' => false];
    const UNLOCK_SECONDS = 3600;
    const SOCIAL_PATHS = '#^/(?:hub/(?:chat|inbox|friend|people|invite|rooms|review|profile|saves/share|saves/shared)|play/(?:friends|people|challenge|profile|privacy)|chat)(?:/|$)#';
    private static $rows = [];
    private static $viewerChecked = false;
    private static $viewerFiltering = false;
    private static $sessionUser = null;
    private static $unlocks = [];

    // ------------------------------------------------------------------ storage
    public static function row(string $userId): ?array
    {
        if ($userId === '') return null;
        if (array_key_exists($userId, self::$rows)) return self::$rows[$userId];
        $row = null;
        try {
            $s = \Vaultcast\Database::connection()->prepare('SELECT settings,pin_hash,pin_failures,pin_locked_until,updated_at FROM vaultcast_parental WHERE user_id=?');
            $s->execute([$userId]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) $row = ['settings' => self::clean((array)(json_decode((string)$r['settings'], true) ?: [])), 'pin_hash' => (string)$r['pin_hash'], 'pin_failures' => (int)$r['pin_failures'], 'pin_locked_until' => (int)$r['pin_locked_until'], 'updated_at' => (int)$r['updated_at']];
        } catch (Throwable $e) { /* not migrated yet: no restrictions */ }
        return self::$rows[$userId] = $row;
    }

    public static function clean(array $in): array
    {
        $out = self::DEFAULTS;
        foreach (['enabled', 'kid', 'live', 'play', 'social', 'self_manage'] as $k) if (array_key_exists($k, $in)) $out[$k] = (bool)$in[$k];
        if (isset($in['ceiling'])) $out['ceiling'] = max(1, min(5, (int)$in['ceiling']));
        if (isset($in['unrated'])) $out['unrated'] = $in['unrated'] === 'hide' ? 'hide' : 'allow';
        return $out;
    }

    /** Effective settings; admins and signed-out visitors are never restricted. */
    public static function settingsFor(?array $user): array
    {
        if (!$user || ($user['role'] ?? '') === 'admin') return self::DEFAULTS;
        $row = self::row((string)($user['id'] ?? ''));
        return $row ? $row['settings'] : self::DEFAULTS;
    }

    private static function save(string $userId, array $settings, ?string $pinHash): void
    {
        $pdo = \Vaultcast\Database::connection();
        $json = json_encode(self::clean($settings));
        if ($pinHash === null) {
            $pdo->prepare('INSERT INTO vaultcast_parental (user_id,settings,pin_hash,updated_at) VALUES (?,?,\'\',?) ON DUPLICATE KEY UPDATE settings=VALUES(settings),updated_at=VALUES(updated_at)')->execute([$userId, $json, time()]);
        } else {
            $pdo->prepare('INSERT INTO vaultcast_parental (user_id,settings,pin_hash,pin_failures,pin_locked_until,updated_at) VALUES (?,?,?,0,0,?) ON DUPLICATE KEY UPDATE settings=VALUES(settings),pin_hash=VALUES(pin_hash),pin_failures=0,pin_locked_until=0,updated_at=VALUES(updated_at)')->execute([$userId, $json, $pinHash, time()]);
        }
        unset(self::$rows[$userId]);
        self::$viewerChecked = false;
        // Episode release calendars only list shows the owner may see.
        if (class_exists('Calendar')) Calendar::queueEpisodes(true);
    }

    public static function purgeUser(string $userId): void
    {
        try { \Vaultcast\Database::connection()->prepare('DELETE FROM vaultcast_parental WHERE user_id=?')->execute([$userId]); } catch (Throwable $e) {}
        unset(self::$rows[$userId]);
    }

    // ------------------------------------------------------------------ PIN
    public static function validPin(string $pin): bool { return (bool)preg_match('/^\d{4,6}$/D', $pin); }

    /** Check a PIN with a short lock-out after five wrong tries. Throws with a friendly message. */
    public static function checkPin(string $userId, string $pin): void
    {
        $row = self::row($userId);
        if (!$row || $row['pin_hash'] === '') throw new DomainException('No PIN is set for this profile yet.');
        $pdo = \Vaultcast\Database::connection();
        // Serialise attempts per profile so parallel requests cannot each read the same failure count.
        $lockName = 'vc-pin-' . substr(hash('sha256', $userId), 0, 32);
        $held = false;
        try { $g = $pdo->prepare('SELECT GET_LOCK(?, 5)'); $g->execute([$lockName]); $held = (int)$g->fetchColumn() === 1; } catch (Throwable $e) {}
        if (!$held) throw new DomainException('Another PIN check is in progress. Try again in a moment.');
        try {
            unset(self::$rows[$userId]);
            $row = self::row($userId);
            if (!$row || $row['pin_hash'] === '') throw new DomainException('No PIN is set for this profile yet.');
            if ($row['pin_locked_until'] > time()) throw new DomainException('Too many wrong PINs. Try again in ' . max(1, (int)ceil(($row['pin_locked_until'] - time()) / 60)) . ' min.');
            if (!password_verify($pin, $row['pin_hash'])) {
                // Failures stay cumulative until a correct PIN; every 5th wrong PIN locks for longer (5 min, 10, 20 ... up to 24 h).
                $fails = $row['pin_failures'] + 1;
                $wait = $fails % 5 === 0 ? (int)min(86400, 300 * (2 ** min(9, intdiv($fails, 5) - 1))) : 0;
                $pdo->prepare('UPDATE vaultcast_parental SET pin_failures=?, pin_locked_until=? WHERE user_id=?')->execute([min($fails, 1000000), $wait ? time() + $wait : 0, $userId]);
                throw new DomainException($wait ? 'Too many wrong PINs. Try again in ' . (int)ceil($wait / 60) . ' min.' : 'That PIN is not right. Try again.');
            }
            if ($row['pin_failures'] || $row['pin_locked_until']) $pdo->prepare('UPDATE vaultcast_parental SET pin_failures=0, pin_locked_until=0 WHERE user_id=?')->execute([$userId]);
        } finally {
            unset(self::$rows[$userId]);
            try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); } catch (Throwable $e) {}
        }
    }

    private static function sessionKey(): ?string
    {
        $token = (string)($_COOKIE[Auth::COOKIE] ?? '');
        return preg_match('/^[a-f0-9]{64}$/D', $token) ? 'parental-unlock:' . hash('sha256', $token) : null;
    }

    /** Seconds left on this device's grown-up unlock for $userId, 0 when locked. */
    public static function unlockedFor(string $userId): int
    {
        $key = self::sessionKey();
        if ($key === null) return 0;
        // restricted() runs for every area check, so read this device's unlock once per request.
        if (!array_key_exists($key, self::$unlocks)) self::$unlocks[$key] = \Vaultcast\Repository::cacheGet($key);
        $u = self::$unlocks[$key];
        return is_array($u) && ($u['user_id'] ?? '') === $userId ? max(0, (int)($u['until'] ?? 0) - time()) : 0;
    }

    public static function unlock(array $user, string $pin): array
    {
        self::checkPin((string)$user['id'], $pin);
        $key = self::sessionKey();
        if ($key === null) throw new DomainException('Sign in again to unlock.');
        \Vaultcast\Repository::cachePut($key, ['user_id' => (string)$user['id'], 'until' => time() + self::UNLOCK_SECONDS], self::UNLOCK_SECONDS);
        self::$unlocks = [];
        self::$viewerChecked = false;
        return self::status($user);
    }

    public static function lock(array $user): array
    {
        $key = self::sessionKey();
        if ($key !== null) \Vaultcast\Repository::cachePut($key, ['user_id' => '', 'until' => 0], 60);
        self::$unlocks = [];
        self::$viewerChecked = false;
        return self::status($user);
    }

    // ------------------------------------------------------------------ who is restricted
    private static function sessionUserId(): string
    {
        if (self::$sessionUser === null) { $s = Auth::currentSession(); self::$sessionUser = $s ? (string)$s['user_id'] : ''; }
        return self::$sessionUser;
    }

    /** True when $user is currently restricted (a grown-up unlock on this device lifts it for that user only). */
    public static function restricted(?array $user): bool
    {
        if (!$user || ($user['role'] ?? '') === 'admin') return false;
        $st = self::settingsFor($user);
        if (!$st['enabled'] && !$st['kid']) return false;
        $id = (string)($user['id'] ?? '');
        return !($id !== '' && $id === self::sessionUserId() && self::unlockedFor($id) > 0);
    }

    /** Whether responses for this request's viewer need filtering. */
    public static function filtering(): bool
    {
        if (!self::$viewerChecked) {
            self::$viewerChecked = true;
            try { self::$viewerFiltering = self::restricted(AreaAccess::viewer()); } catch (Throwable $e) { self::$viewerFiltering = false; }
        }
        return self::$viewerFiltering;
    }

    /** Live / Play toggles feed AreaAccess so every existing area check honours them. */
    public static function areaLimits(?array $user): array
    {
        if (!self::restricted($user)) return ['live' => true, 'games' => true];
        $st = self::settingsFor($user);
        return ['live' => $st['live'], 'games' => $st['play']];
    }

    // ------------------------------------------------------------------ titles
    public static function allowsBucket(array $settings, int $bucket): bool
    {
        if ($bucket === 0) return $settings['unrated'] !== 'hide';
        return $bucket <= $settings['ceiling'];
    }

    /** May $user see this item or card? Titles outside age ratings (music, games) are always allowed here. */
    public static function allows(?array $user, array $itemOrCard): bool
    {
        if (!self::restricted($user)) return true;
        if (!ContentRatings::targetsOf($itemOrCard)) return true;
        return self::allowsBucket(self::settingsFor($user), ContentRatings::bucketOf($itemOrCard));
    }

    public static function allowsShowKey(?array $user, string $showKey): bool
    {
        return self::allows($user, ['kind' => 'show', 'id' => $showKey, 'show_key' => $showKey]);
    }

    private static function blockedResponse(): array
    {
        return [403, ['ok' => false, 'code' => 'parental_blocked', 'error' => "This title isn\u{2019}t available on this profile."]];
    }

    /** Refuse direct requests for blocked titles and, when social is off, social surfaces. */
    public static function gate(string $path): ?array
    {
        if (!self::filtering()) return null;
        $user = AreaAccess::viewer();
        $st = self::settingsFor($user);
        if (!$st['social'] && preg_match(self::SOCIAL_PATHS, $path)) return [403, ['ok' => false, 'code' => 'parental_social', 'error' => 'Chat and friends are turned off on this profile.']];
        if (preg_match('#^/items/([A-Za-z0-9_-]{1,64})(?:/|$)#', $path, $m)) {
            $item = Catalogue::item($m[1]);
            if ($item && !self::allows($user, $item)) return self::blockedResponse();
        } elseif (preg_match('#^/shows/([a-f0-9]{8,64})(?:/|$)#', $path, $m)) {
            if (!self::allowsShowKey($user, $m[1])) return self::blockedResponse();
        } elseif ($path === '/featured/synopsis') {
            if (!empty($_GET['show']) && !self::allowsShowKey($user, (string)$_GET['show'])) return self::blockedResponse();
            if (!empty($_GET['item']) && ($it = Catalogue::item((string)$_GET['item'])) && !self::allows($user, $it)) return self::blockedResponse();
        } elseif ($path === '/hub/title' && preg_match('/^(item|show):([A-Za-z0-9_-]{1,64})$/D', (string)($_GET['key'] ?? ''), $m)) {
            if ($m[1] === 'show' ? !self::allowsShowKey($user, $m[2]) : (($it = Catalogue::item($m[2])) && !self::allows($user, $it))) return self::blockedResponse();
        }
        return null;
    }

    /** Stream tokens: the token owner's limits apply (their own unlock counts only on their own device). */
    public static function streamGate(?array $token): ?array
    {
        if (!$token || in_array((string)($token['user_id'] ?? ''), ['', 'live', 'public'], true)) {
            if (!self::filtering() || !$token) return null;
            $owner = AreaAccess::viewer();
        } else $owner = Auth::findById((string)$token['user_id']);
        if (!$owner || !self::restricted($owner)) return null;
        $itemId = (string)($token['item_id'] ?? '');
        $item = Catalogue::item($itemId);
        if (!$item && Extras::isId($itemId)) return Extras::tokenAllowed($owner, $itemId) ? null : self::blockedResponse();
        return $item && !self::allows($owner, $item) ? self::blockedResponse() : null;
    }

    /** A card-shaped array: an item or show as the public API renders it. */
    private static function isCard(array $a): bool
    {
        if (!isset($a['id']) || !is_scalar($a['id']) || !array_key_exists('title', $a)) return false;
        $kind = $a['kind'] ?? null;
        return in_array($kind, ['item', 'show', 'season'], true) || isset($a['media_kind']);
    }

    private static function blockedNode($v, array $settings): bool
    {
        if (!is_array($v)) return false;
        if (self::isCard($v)) return ContentRatings::targetsOf($v) && !self::allowsBucket($settings, ContentRatings::bucketOf($v));
        // Wrapper rows: continue watching {item, progress}, favourites {card}, activity {item}...
        foreach (['item', 'card', 'show', 'title_card'] as $k) if (isset($v[$k]) && is_array($v[$k]) && self::isCard($v[$k])) return self::blockedNode($v[$k], $settings);
        return false;
    }

    private static function walk($data, array $settings, int $depth = 0)
    {
        if (!is_array($data) || $depth > 12) return $data;
        $list = $data === [] || array_keys($data) === range(0, count($data) - 1);
        $out = [];
        foreach ($data as $k => $v) {
            if (self::blockedNode($v, $settings)) continue;
            $out[$k] = is_array($v) ? self::walk($v, $settings, $depth + 1) : $v;
        }
        return $list ? array_values($out) : $out;
    }

    /** Remove blocked titles from any response payload for the current viewer. */
    public static function filterPayload($data)
    {
        if (!self::filtering() || !is_array($data)) return $data;
        $settings = self::settingsFor(AreaAccess::viewer());
        if (isset($data['ok']) && $data['ok'] === false) return $data;
        $out = self::walk($data, $settings);
        if (!is_array($out)) return $out;
        if (isset($out['home']) && is_array($out['home'])) $out['home']['parental'] = self::publicSettings($settings);
        // Home/first-page feature flags: hide switched-off areas so the UI never offers them.
        $flags = function (array $node) use ($settings): array {
            if (!isset($node['features']) || !is_array($node['features'])) return $node;
            if (empty($settings['live'])) $node['features']['live'] = false;
            if (empty($settings['social'])) $node['features']['social'] = false;
            return $node;
        };
        $out = $flags($out);
        if (isset($out['home']) && is_array($out['home'])) $out['home'] = $flags($out['home']);
        return $out;
    }

    // ------------------------------------------------------------------ API views
    public static function publicSettings(array $st): array
    {
        return $st + ['ceiling_label' => ContentRatings::label((int)$st['ceiling'])];
    }

    /** What the signed-in person sees about their own profile (never the PIN hash). */
    public static function status(?array $user): array
    {
        if (!$user) return ['ok' => true, 'restricted' => false, 'admin' => false, 'settings' => self::publicSettings(self::DEFAULTS), 'has_pin' => false, 'unlocked_for' => 0, 'can_manage' => false, 'ratings' => self::ratingChoices()];
        $id = (string)$user['id'];
        $row = ($user['role'] ?? '') === 'admin' ? null : self::row($id);
        $st = $row ? $row['settings'] : self::DEFAULTS;
        return [
            'ok' => true,
            'admin' => ($user['role'] ?? '') === 'admin',
            'settings' => self::publicSettings($st),
            'restricted' => self::restricted($user),
            'kid' => ($st['kid'] ?? false) && ($user['role'] ?? '') !== 'admin',
            'has_pin' => $row && $row['pin_hash'] !== '',
            'unlocked_for' => self::unlockedFor($id),
            // A person may manage their own controls when nobody set them up for them, or when allowed to.
            'can_manage' => ($user['role'] ?? '') !== 'admin' && (!$row || !empty($st['self_manage'])),
            'ratings' => self::ratingChoices(),
        ];
    }

    public static function ratingChoices(): array
    {
        $out = []; foreach (ContentRatings::NAMES as $b => $n) $out[] = ['value' => $b, 'label' => ContentRatings::LABELS[$b], 'name' => $n];
        return $out;
    }

    /** Admin summary per user id for the user list. */
    public static function summaries(): array
    {
        $out = [];
        try {
            foreach (\Vaultcast\Database::connection()->query('SELECT user_id,settings,pin_hash FROM vaultcast_parental') as $r) {
                $out[(string)$r['user_id']] = self::publicSettings(self::clean((array)(json_decode((string)$r['settings'], true) ?: []))) + ['has_pin' => (string)$r['pin_hash'] !== ''];
            }
        } catch (Throwable $e) {}
        return $out;
    }

    /** Admin edit: no PIN needed. 'pin' sets a new PIN, 'clear_pin' removes it. */
    public static function adminSave(array $target, array $body): array
    {
        if (($target['role'] ?? '') === 'admin') throw new InvalidArgumentException('Administrators are never restricted. Change the role first.');
        $st = self::clean((array)($body['settings'] ?? []));
        $pin = trim((string)($body['pin'] ?? ''));
        if ($pin !== '' && !self::validPin($pin)) throw new InvalidArgumentException('Use a PIN of 4 to 6 digits.');
        $row = self::row((string)$target['id']);
        if ($st['kid'] && $pin === '' && (!$row || $row['pin_hash'] === '') && empty($body['clear_pin'])) throw new InvalidArgumentException('Set a PIN so a grown-up can leave kid mode.');
        $hash = !empty($body['clear_pin']) ? '' : ($pin !== '' ? password_hash($pin, PASSWORD_DEFAULT) : null);
        if ($hash === '' && $st['kid']) throw new InvalidArgumentException('A kids profile needs a PIN.');
        self::save((string)$target['id'], $st, $hash);
        return self::summaries()[(string)$target['id']] ?? self::publicSettings($st);
    }

    /** Self-service edit: needs the current PIN once one exists; a new profile must choose a PIN. */
    public static function selfSave(array $user, array $body): array
    {
        if (($user['role'] ?? '') === 'admin') throw new InvalidArgumentException('Administrators are never restricted.');
        $id = (string)$user['id'];
        $row = self::row($id);
        if ($row && empty($row['settings']['self_manage'])) throw new DomainException('Your parental controls are managed by an administrator.');
        if ($row && $row['pin_hash'] !== '') self::checkPin($id, trim((string)($body['current_pin'] ?? '')));
        $st = self::clean((array)($body['settings'] ?? []));
        $st['self_manage'] = true;
        $pin = trim((string)($body['new_pin'] ?? ''));
        if ($pin !== '' && !self::validPin($pin)) throw new InvalidArgumentException('Use a PIN of 4 to 6 digits.');
        if ((!$row || $row['pin_hash'] === '') && $pin === '') throw new InvalidArgumentException('Choose a 4 to 6 digit PIN to protect these settings.');
        self::save($id, $st, $pin !== '' ? password_hash($pin, PASSWORD_DEFAULT) : null);
        return self::status($user);
    }
}
