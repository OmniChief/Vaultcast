<?php
declare(strict_types=1);

/**
 * Time-limited user access.
 *
 * user['access'] = ['mode' => 'unlimited'|'until'|'pending', 'until' => int|null, 'set_at' => int, 'set_by' => string]
 * Users without an 'access' key predate this feature and are treated as unlimited (migration).
 * Admins always have access, whatever is stored.
 */
final class Access
{
    const PERIODS = ['1w' => '+1 week', '1m' => '+1 month', '3m' => '+3 months', '1y' => '+1 year'];

    /** Paths an authenticated-but-gated user (or a signed-out visitor) can always reach. */
    const OPEN_PATHS = ['/status', '/me', '/login', '/logout'];

    public static function state(array $user, ?int $now = null): array
    {
        $now = $now ?? time();
        if (($user['role'] ?? '') === 'admin') return ['state' => 'admin', 'active' => true, 'expires_at' => null, 'remaining' => null];
        $a = $user['access'] ?? null;
        if (!is_array($a)) return ['state' => 'unlimited', 'active' => true, 'expires_at' => null, 'remaining' => null, 'migrated' => true];
        $mode = (string)($a['mode'] ?? 'pending');
        if ($mode === 'unlimited') return ['state' => 'unlimited', 'active' => true, 'expires_at' => null, 'remaining' => null];
        if ($mode === 'until' && isset($a['until'])) {
            $until = (int)$a['until'];
            $active = $until > $now;
            return ['state' => $active ? 'active' : 'expired', 'active' => $active, 'expires_at' => $until, 'remaining' => max(0, $until - $now)];
        }
        return ['state' => 'pending', 'active' => false, 'expires_at' => null, 'remaining' => null];
    }

    /** Access record for a brand-new user: awaiting access unless the admin picked a period. */
    public static function forNewUser(?string $period, ?string $date, string $by, ?int $now = null): array
    {
        if ($period === null || $period === '' || $period === 'pending') return ['mode' => 'pending', 'until' => null, 'set_at' => $now ?? time(), 'set_by' => $by];
        return self::apply(null, $period, $date, 'set', $by, $now);
    }

    /**
     * New access record. $period: 1w|1m|3m|1y|custom|unlimited|revoke. $mode 'extend' adds the
     * period to the current expiry (or now, if that has passed); 'set' counts from now.
     * @throws InvalidArgumentException
     */
    public static function apply(?array $current, string $period, ?string $date, string $mode, string $by, ?int $now = null): array
    {
        $now = $now ?? time();
        $rec = ['set_at' => $now, 'set_by' => $by];
        if ($period === 'unlimited') return ['mode' => 'unlimited', 'until' => null] + $rec;
        if ($period === 'revoke') return ['mode' => 'until', 'until' => $now] + $rec;
        if ($period === 'custom') {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$date);
            if (!$d || $d->format('Y-m-d') !== (string)$date) throw new InvalidArgumentException('Pick a valid expiry date.');
            $until = $d->setTime(23, 59, 59)->getTimestamp();
            if ($until <= $now) throw new InvalidArgumentException('The expiry date must be in the future.');
            return ['mode' => 'until', 'until' => $until] + $rec;
        }
        if (!isset(self::PERIODS[$period])) throw new InvalidArgumentException('Unknown access period.');
        $base = $now;
        if ($mode === 'extend' && is_array($current) && ($current['mode'] ?? '') === 'until' && (int)($current['until'] ?? 0) > $now) {
            $base = (int)$current['until'];
        }
        $until = (new DateTimeImmutable('@' . $base))->setTimezone(new DateTimeZone(date_default_timezone_get()))->modify(self::PERIODS[$period])->getTimestamp();
        return ['mode' => 'until', 'until' => $until] + $rec;
    }

    /** Why $actor may not delete $target, or null when allowed. */
    public static function deleteBlocker(array $actor, array $target, array $allUsers): ?string
    {
        if ((string)($actor['id'] ?? '') === (string)($target['id'] ?? '')) return 'You cannot delete your own account.';
        if (($target['role'] ?? '') === 'admin') {
            $admins = 0;
            foreach ($allUsers as $u) if (($u['role'] ?? '') === 'admin') $admins++;
            if ($admins <= 1) return 'You cannot delete the last admin.';
        }
        return null;
    }

    /**
     * Server-side gate, evaluated for every API request before any handler.
     * Returns null to allow, or [httpCode, payload] to refuse.
     *
     * @param array|null $session  current session (null when signed out)
     * @param callable   $findUser fn(string $id): ?array
     * @param callable   $token    fn(): ?array  playback-token payload for /stream/* paths
     */
    public static function gate(string $path, ?array $session, callable $findUser, bool $requireLogin, callable $token, ?int $now = null): ?array
    {
        if (in_array($path, self::OPEN_PATHS, true) || strpos($path, '/player-assets/') === 0) return null;
        // Drop share links decide for themselves whether a sign-in is needed (per-link setting).
        if (preg_match('#^/drop/s/[a-f0-9]{32}(?:/download)?$#D', $path)) return null;
        // Secret-token calendar feeds (webcal/https subscriptions from other apps) carry no cookie; the token is the key.
        if (preg_match('#^/misc/cal/feed/[a-f0-9]{40}\.ics$#D', $path)) return null;
        // Anonymous Drop answers for itself (refused unless the admin enabled it) and serves nothing else.
        if (preg_match('#^/drop/anon(?:/(?:state|upload(?:/[a-f0-9]{32})?|items/[a-f0-9]{32}(?:/download|/links)?))?$#D', $path)) return null;
        if (strpos($path, '/stream/') === 0 && strpos($path, '/stream/bios/') !== 0) {
            // Streams are token-addressed (players cannot always send cookies): check the token owner.
            $p = $token();
            if ($p === null) return null; // the handler answers invalid/expired tokens itself
            $uid = (string)($p['user_id'] ?? '');
            // Live/Relay channel files (api/live/file) issue tokens owned by 'live': Live is readable
            // signed out (external IPTV players), so treat them like a /live/ request instead of a
            // deleted account. A signed-in viewer is still checked below.
            if ($uid === 'live') {
                if ($session === null) return $requireLogin ? [401, ['ok'=>false, 'error'=>'Sign in to watch live.', 'code'=>'login_required']] : null;
                $user = $findUser((string)($session['user_id'] ?? ''));
                if (!$user) return [401, ['ok'=>false, 'error'=>'Sign in to continue.', 'code'=>'login_required']];
                $st = self::state($user, $now);
                return $st['active'] ? AreaAccess::denied($user, 'live') : [403, ['ok' => false, 'error' => self::message($st['state']), 'code' => 'access_denied', 'access' => $st]];
            }
            if ($uid === '' || $uid === 'public') {
                if ($requireLogin) return [403, ['ok' => false, 'error' => 'Sign in to play this.', 'code' => 'login_required']];
                $viewer = $session ? $findUser((string)($session['user_id'] ?? '')) : null;
                return $viewer ? AreaAccess::denied($viewer, ($p['area'] ?? '') === 'live' ? 'live' : (($p['media_kind'] ?? '') === 'rom' ? 'games' : 'library')) : null;
            }
            $owner = $findUser($uid);
            if (!$owner) return [403, ['ok' => false, 'error' => 'This account no longer exists.', 'code' => 'access_denied']];
            $st = self::state($owner, $now);
            $area = ($p['area'] ?? '') === 'live' ? 'live' : (($p['media_kind'] ?? '') === 'rom' ? 'games' : 'library');
            return $st['active'] ? AreaAccess::denied($owner, $area) : [403, ['ok' => false, 'error' => self::message($st['state']), 'code' => 'access_denied', 'access' => $st]];
        }
        if ($session !== null) {
            $user = $findUser((string)($session['user_id'] ?? ''));
            if (!$user) return [401, ['ok' => false, 'error' => 'Unauthorized']];
            $st = self::state($user, $now);
            return $st['active'] ? null : [403, ['ok' => false, 'error' => self::message($st['state']), 'code' => 'access_denied', 'access' => $st]];
        }
        if (!$requireLogin) return null;
        return [401, ['ok' => false, 'error' => 'Sign in to continue.', 'code' => 'login_required']];
    }

    public static function message(string $state): string
    {
        return $state === 'expired' ? 'Your access has expired. Ask an administrator to renew it.' : 'Your account is waiting for an administrator to grant access.';
    }
}
