<?php
declare(strict_types=1);

/**
 * Misc → Calendar: calendars stored in the database (local, subscribed ICS, and automatic episode releases),
 * events with recurrence and reminders, sharing with other users, secret-token ICS feeds, export and import.
 *
 * Times: timed events are stored as UTC timestamps together with the IANA zone they were created in, so
 * recurrences keep their wall-clock time across daylight-saving changes. All-day events are stored as local
 * midnights (end exclusive) in that zone and exported as VALUE=DATE.
 */
final class Calendar
{
    const MAX_CALENDARS = 40;
    const MAX_EVENTS = 20000;
    const FREQS = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];
    const DAYS = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];

    private static function q(string $sql, array $p = []): PDOStatement { return Arcade::query($sql, $p); }
    private static function signed(array $user): array {
        if (($user['role'] ?? 'guest') === 'guest' || ($user['id'] ?? 'public') === 'public') throw new DomainException('Sign in to use calendars.');
        return $user;
    }
    private static function id(): string { return bin2hex(random_bytes(8)); }
    private static function serverTz(): string { return date_default_timezone_get() ?: 'UTC'; }
    private static function tz(string $name): DateTimeZone {
        try { return new DateTimeZone($name !== '' ? $name : self::serverTz()); } catch (Throwable $e) { return new DateTimeZone(self::serverTz()); }
    }
    private static function validTz(string $name): string {
        $name = trim($name);
        if ($name !== '' && in_array($name, DateTimeZone::listIdentifiers(), true)) return $name;
        return self::serverTz();
    }
    private static function colour(string $v): string { return preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtolower($v) : '#59e9ff'; }
    private static function text(string $v, int $max): string { return mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? ''), 0, $max); }

    // ------------------------------------------------------------------ calendars and access
    private static function calRow(string $id): ?array {
        if (!preg_match('/^[a-f0-9]{16}$/D', $id)) return null;
        $r = self::q('SELECT * FROM vaultcast_cal_calendars WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
    private static function shares(array $c): array { $s = json_decode((string)($c['shares'] ?? ''), true); return is_array($s) ? $s : []; }
    /** 'owner', 'edit', 'view' or null. */
    private static function role(array $c, array $user): ?string {
        $uid = (string)($user['id'] ?? '');
        if ($uid === '') return null;
        if ((string)$c['owner_id'] === $uid) return 'owner';
        $r = self::shares($c)[$uid] ?? null;
        return in_array($r, ['view', 'edit'], true) ? $r : null;
    }
    private static function need(string $id, array $user, string $level): array {
        $c = self::calRow($id); $role = $c ? self::role($c, $user) : null;
        if (!$c || !$role) throw new DomainException('Calendar not found.');
        $rank = ['view' => 1, 'edit' => 2, 'owner' => 3];
        if ($rank[$role] < $rank[$level]) throw new DomainException($level === 'owner' ? 'Only the owner can change this calendar.' : 'You can only view this calendar.');
        if ($level === 'edit' && $c['kind'] !== 'local') throw new DomainException('Events in this calendar update automatically and cannot be edited.');
        return $c + ['role' => $role];
    }
    /** Calendars the user owns or that were shared with them. */
    private static function accessible(array $user): array {
        $uid = (string)$user['id'];
        $rows = self::q("SELECT * FROM vaultcast_cal_calendars WHERE owner_id=? OR shares LIKE ? ORDER BY created_at", [$uid, '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], json_encode($uid)) . '%'])->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) { $role = self::role($r, $user); if ($role) $out[$r['id']] = $r + ['role' => $role]; }
        return $out;
    }
    public static function feedUrl(string $token): string { return rtrim(\Vaultcast\Database::basePath(), '/') . '/api/misc/cal/feed/' . $token . '.ics'; }
    private static function publicCal(array $c, array $people): array {
        $out = ['id' => $c['id'], 'name' => $c['name'], 'colour' => $c['colour'], 'kind' => $c['kind'], 'role' => $c['role'], 'episodes' => (string)$c['episodes'],
            'show_reminders' => (bool)$c['show_reminders'], 'owner' => $people[(string)$c['owner_id']] ?? Arcade::person(null), 'updated_at' => (int)$c['updated_at']];
        if ($c['role'] === 'owner') {
            $out['url'] = (string)($c['url'] ?? '');
            $out['feed'] = $c['feed_token'] ? self::feedUrl((string)$c['feed_token']) : null;
            $out['shares'] = [];
            foreach (self::shares($c) as $uid => $r) $out['shares'][] = ['user' => $people[(string)$uid] ?? Arcade::person(null), 'role' => $r];
        }
        if ($c['kind'] === 'episodes' || $c['episodes'] !== '') { $st = (array)\Vaultcast\Repository::document('cal-episodes-state', []); $out['episodes_updated_at'] = (int)($st['at'] ?? 0); }
        return $out;
    }
    public static function calendars(array $user): array {
        self::signed($user);
        $cals = self::accessible($user); $ids = [];
        foreach ($cals as $c) { $ids[] = (string)$c['owner_id']; foreach (array_keys(self::shares($c)) as $u) $ids[] = (string)$u; }
        $people = Arcade::people($ids);
        if ($cals && array_filter($cals, function ($c) { return $c['episodes'] !== ''; })) self::queueEpisodes(false);
        return ['ok' => true, 'calendars' => array_values(array_map(function ($c) use ($people) { return self::publicCal($c, $people); }, $cals)), 'timezone' => self::serverTz()];
    }
    public static function saveCalendar(array $user, ?string $id, array $b): array {
        self::signed($user); $now = time();
        $name = self::text((string)($b['name'] ?? ''), 80); if ($name === '') throw new DomainException('Give the calendar a name.');
        $colour = self::colour((string)($b['colour'] ?? ''));
        $episodes = in_array($b['episodes'] ?? '', ['', 'favourites', 'library'], true) ? (string)($b['episodes'] ?? '') : '';
        $showRem = !empty($b['show_reminders']) ? 1 : 0;
        if ($id === null) {
            $count = (int)self::q('SELECT COUNT(*) FROM vaultcast_cal_calendars WHERE owner_id=?', [$user['id']])->fetchColumn();
            if ($count >= self::MAX_CALENDARS) throw new DomainException('You can have up to ' . self::MAX_CALENDARS . ' calendars.');
            $kind = in_array($b['kind'] ?? 'local', ['local', 'subscribed'], true) ? (string)($b['kind'] ?? 'local') : 'local';
            $url = '';
            if ($kind === 'subscribed') { $url = self::subscriptionUrl((string)($b['url'] ?? '')); $episodes = ''; }
            $id = self::id();
            self::q('INSERT INTO vaultcast_cal_calendars (id,owner_id,name,colour,kind,url,feed_token,episodes,show_reminders,shares,version,created_at,updated_at) VALUES (?,?,?,?,?,?,NULL,?,?,?,0,?,?)',
                [$id, $user['id'], $name, $colour, $kind, $url, $episodes, $showRem, '{}', $now, $now]);
        } else {
            $c = self::need($id, $user, 'owner');
            $url = (string)$c['url'];
            if ($c['kind'] === 'subscribed') { if (array_key_exists('url', $b)) $url = self::subscriptionUrl((string)$b['url']); $episodes = ''; }
            self::q('UPDATE vaultcast_cal_calendars SET name=?,colour=?,url=?,episodes=?,show_reminders=?,version=version+1,updated_at=? WHERE id=?', [$name, $colour, $url, $episodes, $showRem, $now, $id]);
            if ($episodes === '' && $c['episodes'] !== '') self::q("DELETE FROM vaultcast_cal_events WHERE calendar_id=? AND source='ep'", [$id]);
        }
        if ($episodes !== '') self::queueEpisodes(true);
        self::bump($user);
        return self::calendars($user) + ['id' => $id];
    }
    private static function subscriptionUrl(string $url): string {
        $url = trim($url);
        if (stripos($url, 'webcal://') === 0) $url = 'https://' . substr($url, 9);
        if (!preg_match('#^https?://[^\s]+$#i', $url) || strlen($url) > 2000) throw new DomainException('Enter an http(s) or webcal calendar address.');
        return $url;
    }
    public static function deleteCalendar(array $user, string $id): array {
        self::need($id, $user, 'owner');
        self::q('DELETE FROM vaultcast_cal_events WHERE calendar_id=?', [$id]);
        self::q('DELETE FROM vaultcast_cal_calendars WHERE id=?', [$id]);
        self::bump($user);
        return self::calendars($user);
    }
    /** Secret feed: create, regenerate (old address stops working) or revoke. */
    public static function feed(array $user, string $id, string $action): array {
        self::need($id, $user, 'owner');
        $token = $action === 'revoke' ? null : bin2hex(random_bytes(20));
        if (!in_array($action, ['create', 'regenerate', 'revoke'], true)) throw new DomainException('Unknown feed action.');
        self::q('UPDATE vaultcast_cal_calendars SET feed_token=?,updated_at=? WHERE id=?', [$token, time(), $id]);
        return self::calendars($user);
    }
    public static function share(array $user, string $id, array $b): array {
        $c = self::need($id, $user, 'owner');
        $role = (string)($b['role'] ?? 'view');
        $target = !empty($b['user_id']) ? \Vaultcast\Repository::user((string)$b['user_id'], true) : \Vaultcast\Repository::user(ltrim(trim((string)($b['username'] ?? '')), '@'));
        if (!$target || ($target['role'] ?? '') === 'guest') throw new DomainException('No account with that username.');
        if ((string)$target['id'] === (string)$user['id']) throw new DomainException('This is already your calendar.');
        $shares = self::shares($c);
        if ($role === 'none') unset($shares[(string)$target['id']]);
        elseif (in_array($role, ['view', 'edit'], true)) { if (count($shares) >= 50 && !isset($shares[(string)$target['id']])) throw new DomainException('A calendar can be shared with up to 50 people.'); $shares[(string)$target['id']] = $c['kind'] === 'local' ? $role : 'view'; }
        else throw new DomainException('Choose view or edit.');
        self::q('UPDATE vaultcast_cal_calendars SET shares=?,updated_at=? WHERE id=?', [json_encode($shares, JSON_FORCE_OBJECT), time(), $id]);
        if ($role !== 'none') try { Social::notify((string)$target['id'], $user, 'calendar-' . $id, 'shared the calendar “' . $c['name'] . '” with you', 'Calendar shared', 'misc/calendar'); } catch (Throwable $e) {}
        return self::calendars($user);
    }
    /** Cache key part that changes whenever anything a user can see changes (alerts cache). */
    private static function bump(array $user): void { \Vaultcast\Repository::cachePut('cal-gen', ['v' => bin2hex(random_bytes(4))], 0); }
    private static function gen(): string { $g = \Vaultcast\Repository::cacheGet('cal-gen'); return (string)($g['v'] ?? '0'); }

    public static function purgeUser(string $uid): void {
        foreach (self::q('SELECT id FROM vaultcast_cal_calendars WHERE owner_id=?', [$uid])->fetchAll(PDO::FETCH_COLUMN) as $cid) self::q('DELETE FROM vaultcast_cal_events WHERE calendar_id=?', [$cid]);
        self::q('DELETE FROM vaultcast_cal_calendars WHERE owner_id=?', [$uid]);
        self::q('DELETE FROM vaultcast_cal_reminders WHERE user_id=?', [$uid]);
    }

    // ------------------------------------------------------------------ events
    private static function eventRow(string $id): ?array {
        if (!preg_match('/^[a-f0-9]{16}$/D', $id)) return null;
        $r = self::q('SELECT * FROM vaultcast_cal_events WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
    /** Body from the app: { calendar_id, title, all_day, start, end (unix, or 'YYYY-MM-DD' for all-day; end date inclusive), tz, location, notes, repeat:{freq,interval,until,count,days}, reminders:[minutes] } */
    private static function eventFields(array $b): array {
        $title = self::text((string)($b['title'] ?? ''), 200); if ($title === '') throw new DomainException('Give the event a title.');
        $tzName = self::validTz((string)($b['tz'] ?? '')); $tz = self::tz($tzName);
        $allDay = !empty($b['all_day']);
        if ($allDay) {
            $sd = (string)($b['start'] ?? ''); $ed = (string)($b['end'] ?? $sd);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $sd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $ed)) throw new DomainException('Choose the dates.');
            $s = new DateTime($sd . ' 00:00:00', $tz); $e = new DateTime($ed . ' 00:00:00', $tz);
            if ($e < $s) throw new DomainException('The event ends before it starts.');
            $e->modify('+1 day');
            $start = $s->getTimestamp(); $end = $e->getTimestamp();
        } else {
            $start = (int)($b['start'] ?? 0); $end = (int)($b['end'] ?? 0);
            if ($start < 86400 || $start > 4102444800) throw new DomainException('Choose a start time.');
            if ($end <= 0) $end = $start + 3600;
            if ($end < $start) throw new DomainException('The event ends before it starts.');
            if ($end - $start > 366 * 86400) throw new DomainException('Events can last up to a year.');
        }
        list($rrule, $until) = self::ruleFrom((array)($b['repeat'] ?? []), $tz, $allDay);
        $rem = array_values(array_unique(array_filter(array_map('intval', (array)($b['reminders'] ?? [])), function ($m) { return $m >= 0 && $m <= 40320; })));
        sort($rem);
        return ['title' => $title, 'location' => self::text((string)($b['location'] ?? ''), 200), 'notes' => self::text((string)($b['notes'] ?? ''), 4000),
            'all_day' => $allDay ? 1 : 0, 'start_at' => $start, 'end_at' => $end, 'tz' => $tzName, 'rrule' => $rrule, 'until_at' => $until, 'reminders' => implode(',', array_slice($rem, 0, 5))];
    }
    private static function ruleFrom(array $r, DateTimeZone $tz, bool $allDay): array {
        $freq = strtoupper((string)($r['freq'] ?? ''));
        if ($freq === '' || $freq === 'NONE') return ['', 0];
        if (!in_array($freq, self::FREQS, true)) throw new DomainException('Choose how often it repeats.');
        $parts = ['FREQ=' . $freq];
        $interval = max(1, min(99, (int)($r['interval'] ?? 1))); if ($interval > 1) $parts[] = 'INTERVAL=' . $interval;
        if ($freq === 'WEEKLY' && !empty($r['days']) && is_array($r['days'])) {
            $days = array_values(array_intersect(self::DAYS, array_map('strtoupper', array_map('strval', $r['days']))));
            if ($days) $parts[] = 'BYDAY=' . implode(',', $days);
        }
        $until = 0;
        if (!empty($r['until']) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string)$r['until'])) {
            $u = new DateTime($r['until'] . ' 23:59:59', $tz); $until = $u->getTimestamp();
            $parts[] = 'UNTIL=' . ($allDay ? $u->format('Ymd') : gmdate('Ymd\THis\Z', $until));
        } elseif (!empty($r['count'])) $parts[] = 'COUNT=' . max(1, min(999, (int)$r['count']));
        return [implode(';', $parts), $until];
    }
    public static function createEvent(array $user, array $b): array {
        $c = self::need((string)($b['calendar_id'] ?? ''), $user, 'edit');
        if ((int)self::q('SELECT COUNT(*) FROM vaultcast_cal_events WHERE calendar_id=?', [$c['id']])->fetchColumn() >= self::MAX_EVENTS) throw new DomainException('This calendar is full.');
        $f = self::eventFields($b); $id = self::id(); $now = time();
        self::q('INSERT INTO vaultcast_cal_events (id,calendar_id,uid,title,location,notes,all_day,start_at,end_at,tz,rrule,until_at,exdates,reminders,source,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$id, $c['id'], $id . '@vaultcast', $f['title'], $f['location'], $f['notes'], $f['all_day'], $f['start_at'], $f['end_at'], $f['tz'], $f['rrule'], $f['until_at'], '', $f['reminders'], '', $now, $now]);
        self::touch($c['id']);
        return ['ok' => true, 'id' => $id];
    }
    public static function updateEvent(array $user, string $id, array $b): array {
        $ev = self::eventRow($id); if (!$ev || $ev['source'] !== '') throw new DomainException('Event not found.');
        $c = self::need((string)$ev['calendar_id'], $user, 'edit');
        $target = (string)($b['calendar_id'] ?? $c['id']);
        if ($target !== $c['id']) self::need($target, $user, 'edit');
        $f = self::eventFields($b);
        self::q('UPDATE vaultcast_cal_events SET calendar_id=?,title=?,location=?,notes=?,all_day=?,start_at=?,end_at=?,tz=?,rrule=?,until_at=?,exdates=?,reminders=?,updated_at=? WHERE id=?',
            [$target, $f['title'], $f['location'], $f['notes'], $f['all_day'], $f['start_at'], $f['end_at'], $f['tz'], $f['rrule'], $f['until_at'], $f['rrule'] === $ev['rrule'] ? (string)$ev['exdates'] : '', $f['reminders'], time(), $id]);
        self::touch($c['id']); if ($target !== $c['id']) self::touch($target);
        return ['ok' => true, 'id' => $id];
    }
    /** Delete a whole series, or one occurrence of a repeating event (?occurrence=<start timestamp>). */
    public static function deleteEvent(array $user, string $id, int $occurrence = 0): array {
        $ev = self::eventRow($id); if (!$ev || $ev['source'] !== '') throw new DomainException('Event not found.');
        self::need((string)$ev['calendar_id'], $user, 'edit');
        if ($occurrence > 0 && $ev['rrule'] !== '') {
            $ex = array_filter(explode(',', (string)$ev['exdates'])); $ex[] = (string)$occurrence;
            self::q('UPDATE vaultcast_cal_events SET exdates=?,updated_at=? WHERE id=?', [implode(',', array_slice(array_unique($ex), -500)), time(), $id]);
        } else self::q('DELETE FROM vaultcast_cal_events WHERE id=?', [$id]);
        self::touch((string)$ev['calendar_id']);
        return ['ok' => true];
    }
    private static function touch(string $calId): void { self::q('UPDATE vaultcast_cal_calendars SET version=version+1,updated_at=? WHERE id=?', [time(), $calId]); self::bump([]); }

    // ------------------------------------------------------------------ recurrence
    private static function parseRule(string $rrule): array {
        $r = [];
        foreach (explode(';', $rrule) as $kv) { $x = explode('=', $kv, 2); if (count($x) === 2) $r[strtoupper(trim($x[0]))] = trim($x[1]); }
        return $r;
    }
    /**
     * Occurrences of one event overlapping [$from, $to): list of [start, end]. Expansion happens in the event's
     * zone with DateTime, so a weekly 09:00 meeting stays at 09:00 after the clocks change.
     */
    public static function occurrences(array $ev, int $from, int $to, int $limit = 1000): array {
        $start = (int)$ev['start_at']; $end = max($start, (int)$ev['end_at']); $dur = $end - $start; $allDay = (int)$ev['all_day'] === 1;
        $tz = self::tz((string)$ev['tz']);
        $exdates = [];
        foreach (array_filter(explode(',', (string)($ev['exdates'] ?? ''))) as $x) $exdates[(int)$x] = 1;
        $days = $allDay ? max(1, (int)round($dur / 86400)) : 0;
        $span = function (int $s) use ($allDay, $days, $dur, $tz): int { if (!$allDay) return $s + $dur; $d = (new DateTime('@' . $s))->setTimezone($tz); $d->modify('+' . $days . ' day'); return $d->getTimestamp(); };
        if ((string)$ev['rrule'] === '') return ($start < $to && $end > $from) || ($start >= $from && $start < $to) ? [[$start, $end]] : [];
        $rule = self::parseRule((string)$ev['rrule']);
        $freq = $rule['FREQ'] ?? ''; if (!in_array($freq, self::FREQS, true)) return $start < $to && $end > $from ? [[$start, $end]] : [];
        $interval = max(1, (int)($rule['INTERVAL'] ?? 1));
        $count = (int)($rule['COUNT'] ?? 0);
        $until = (int)($ev['until_at'] ?? 0);
        if (!$until && !empty($rule['UNTIL'])) { $u = self::icsTime($rule['UNTIL'], [], $allDay); if ($u) $until = $u + ($allDay || strlen($rule['UNTIL']) === 8 ? 86399 : 0); }
        $base = (new DateTime('@' . $start))->setTimezone($tz);
        $h = (int)$base->format('G'); $i = (int)$base->format('i'); $sec = (int)$base->format('s');
        $byday = [];
        foreach (array_filter(explode(',', (string)($rule['BYDAY'] ?? ''))) as $d) if (preg_match('/^([+-]?\d{1,2})?(SU|MO|TU|WE|TH|FR|SA)$/', strtoupper($d), $m)) $byday[] = [(int)($m[1] ?? 0), array_search($m[2], self::DAYS, true)];
        $bymonthday = (int)($rule['BYMONTHDAY'] ?? 0);
        $out = []; $n = 0; $guard = 0;
        $y0 = (int)$base->format('Y'); $mo0 = (int)$base->format('n'); $d0 = (int)$base->format('j');
        // Skip whole periods that end before $from when no COUNT needs every earlier occurrence.
        $k = 0;
        if (!$count && $from > $start + $dur) {
            $periodSec = ['DAILY' => 86400, 'WEEKLY' => 604800, 'MONTHLY' => 2419200, 'YEARLY' => 31536000][$freq] * $interval;
            $k = max(0, (int)floor(($from - $start - $dur) / $periodSec) - 2);
        }
        $mk = function (int $y, int $m, int $d) use ($tz, $h, $i, $sec): ?int { if (!checkdate($m, $d, $y)) return null; $x = new DateTime('now', $tz); $x->setDate($y, $m, $d); $x->setTime($h, $i, $sec); return $x->getTimestamp(); };
        while ($guard++ < 5000) {
            $cands = [];
            if ($freq === 'DAILY') { $x = clone $base; $x->modify('+' . ($k * $interval) . ' day'); $x->setTime($h, $i, $sec); $cands[] = $x->getTimestamp(); }
            elseif ($freq === 'WEEKLY') {
                $wk = clone $base; $wk->modify('-' . (((int)$wk->format('N')) - 1) . ' day'); $wk->modify('+' . ($k * $interval) . ' week');
                $list = $byday ? array_map(function ($b) { return $b[1]; }, $byday) : [(int)$base->format('w')];
                sort($list);
                foreach ($list as $wd) { $x = clone $wk; $x->modify('+' . (($wd + 6) % 7) . ' day'); $x->setTime($h, $i, $sec); $cands[] = $x->getTimestamp(); }
                sort($cands);
            } elseif ($freq === 'MONTHLY') {
                $mIdx = ($mo0 - 1) + $k * $interval; $y = $y0 + intdiv($mIdx, 12); $m = $mIdx % 12 + 1;
                if ($byday) {
                    foreach ($byday as $b) {
                        list($nth, $wd) = $b; if ($nth === 0) $nth = 1;
                        $dim = cal_days($y, $m);
                        if ($nth > 0) { $first = (int)date('w', gmmktime(12, 0, 0, $m, 1, $y)); $day = 1 + (($wd - $first + 7) % 7) + ($nth - 1) * 7; }
                        else { $last = (int)date('w', gmmktime(12, 0, 0, $m, $dim, $y)); $day = $dim - (($last - $wd + 7) % 7) + ($nth + 1) * 7; }
                        if ($day >= 1 && $day <= $dim && ($t = $mk($y, $m, $day)) !== null) $cands[] = $t;
                    }
                    sort($cands);
                } else { $day = $bymonthday > 0 ? $bymonthday : ($bymonthday < 0 ? cal_days($y, $m) + 1 + $bymonthday : $d0); if (($t = $mk($y, $m, $day)) !== null) $cands[] = $t; }
            } else { $y = $y0 + $k * $interval; if (($t = $mk($y, $mo0, $d0)) !== null) $cands[] = $t; }
            $stop = false;
            foreach ($cands as $t) {
                if ($t < $start) continue;
                if ($until && $t > $until) { $stop = true; break; }
                if ($count && $n >= $count) { $stop = true; break; }
                $n++;
                if ($t >= $to) { $stop = true; break; }
                if (isset($exdates[$t])) continue;
                $e = $span($t);
                if ($e > $from || $t >= $from) { $out[] = [$t, $e]; if (count($out) >= $limit) { $stop = true; break; } }
            }
            if ($stop) break;
            $k++;
        }
        return $out;
    }

    /**
     * Occurrences of every visible calendar in [$from, $to), plus reminders when asked.
     * $ids limits the calendars (empty = all accessible). Returns the app's event shape.
     */
    public static function range(array $user, int $from, int $to, array $ids = [], bool $withReminders = false): array {
        self::signed($user);
        if ($to <= $from) $to = $from + 86400;
        $to = min($to, $from + 400 * 86400);
        $cals = self::accessible($user);
        if ($ids) $cals = array_intersect_key($cals, array_flip(array_map('strval', $ids)));
        $events = []; $errors = [];
        $local = array_keys(array_filter($cals, function ($c) { return $c['kind'] !== 'subscribed'; }));
        if ($local) {
            $marks = implode(',', array_fill(0, count($local), '?'));
            $rows = self::q("SELECT * FROM vaultcast_cal_events WHERE calendar_id IN ($marks) AND start_at < ? AND ((rrule = '' AND end_at > ?) OR (rrule <> '' AND (until_at = 0 OR until_at >= ?))) LIMIT 20000", array_merge($local, [$to, $from, $from]))->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $ev) foreach (self::occurrences($ev, $from, $to, 500) as $o) $events[] = self::publicEvent($ev, $cals[$ev['calendar_id']], $o);
        }
        foreach ($cals as $c) {
            if ($c['kind'] !== 'subscribed') continue;
            try { foreach (self::subscribedEvents($c) as $ev) foreach (self::occurrences($ev, $from, $to, 300) as $o) $events[] = self::publicEvent($ev, $c, $o); }
            catch (DomainException $e) { $errors[] = ['calendar' => $c['id'], 'error' => $e->getMessage()]; }
        }
        $showRem = $withReminders || array_filter($cals, function ($c) { return (int)$c['show_reminders'] === 1 && $c['role'] === 'owner'; });
        if ($showRem) foreach (Reminders::calendarItems($user, $from, $to) as $r) $events[] = $r;
        usort($events, function ($a, $b) { return [$a['start'], $a['all_day'] ? 0 : 1, $a['title']] <=> [$b['start'], $b['all_day'] ? 0 : 1, $b['title']]; });
        return ['ok' => true, 'from' => $from, 'to' => $to, 'events' => array_slice($events, 0, 3000), 'errors' => $errors];
    }
    private static function publicEvent(array $ev, array $c, array $o): array {
        $allDay = (int)$ev['all_day'] === 1; $tz = self::tz((string)$ev['tz']);
        $out = ['id' => (string)($ev['id'] ?? ''), 'calendar_id' => $c['id'], 'colour' => $c['colour'], 'title' => (string)$ev['title'], 'location' => (string)$ev['location'], 'notes' => (string)($ev['notes'] ?? ''),
            'all_day' => $allDay, 'start' => (int)$o[0], 'end' => (int)$o[1], 'series_start' => (int)$ev['start_at'], 'series_end' => (int)$ev['end_at'], 'tz' => (string)$ev['tz'],
            'repeat' => self::ruleForApp((string)$ev['rrule'], $tz, $allDay, (int)($ev['until_at'] ?? 0)), 'reminders' => array_map('intval', array_filter(explode(',', (string)$ev['reminders']), 'strlen')),
            'source' => (string)($ev['source'] ?? ''), 'editable' => $c['kind'] === 'local' && ($ev['source'] ?? '') === '' && in_array($c['role'], ['owner', 'edit'], true)];
        if ($allDay) { $s = (new DateTime('@' . $o[0]))->setTimezone($tz); $e = (new DateTime('@' . $o[1]))->setTimezone($tz); $e->modify('-1 day'); $out['start_date'] = $s->format('Y-m-d'); $out['end_date'] = $e->format('Y-m-d'); }
        return $out;
    }
    private static function ruleForApp(string $rrule, DateTimeZone $tz, bool $allDay, int $until): ?array {
        if ($rrule === '') return null;
        $r = self::parseRule($rrule);
        $out = ['freq' => $r['FREQ'] ?? '', 'interval' => max(1, (int)($r['INTERVAL'] ?? 1)), 'count' => (int)($r['COUNT'] ?? 0), 'until' => null, 'days' => [], 'text' => $rrule];
        if ($until) $out['until'] = (new DateTime('@' . $until))->setTimezone($tz)->format('Y-m-d');
        if (!empty($r['BYDAY'])) $out['days'] = array_values(array_filter(explode(',', $r['BYDAY']), function ($d) { return in_array($d, self::DAYS, true); }));
        return $out;
    }

    // ------------------------------------------------------------------ alerts (event reminders and timed reminders)
    /** Alerts that fire in [now - 120 s, now + $window]: the app schedules them locally and shows a notification. */
    public static function alerts(array $user, int $window = 600): array {
        self::signed($user); $window = max(60, min(1800, $window)); $now = time();
        // One cache slot per user, valid while nothing changed and for four minutes (the list covers 30).
        $key = 'cal-alerts:' . $user['id']; $stamp = self::gen() . ':' . intdiv($now, 240);
        $hit = \Vaultcast\Repository::cacheGet($key);
        if (($hit['stamp'] ?? '') === $stamp && is_array($hit['alerts'] ?? null)) { $list = $hit['alerts']; }
        else {
            $list = []; $horizonFrom = $now - 300; $horizonTo = $now + 1800 + 40320 * 60;
            $cals = array_filter(self::accessible($user), function ($c) { return $c['kind'] === 'local'; });
            if ($cals) {
                $ids = array_keys($cals); $marks = implode(',', array_fill(0, count($ids), '?'));
                $rows = self::q("SELECT * FROM vaultcast_cal_events WHERE calendar_id IN ($marks) AND reminders <> '' AND start_at < ? AND ((rrule = '' AND start_at > ?) OR (rrule <> '' AND (until_at = 0 OR until_at >= ?))) LIMIT 5000", array_merge($ids, [$horizonTo, $horizonFrom, $horizonFrom]))->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $ev) {
                    $mins = array_map('intval', array_filter(explode(',', (string)$ev['reminders']), 'strlen')); if (!$mins) continue;
                    $maxBefore = max($mins) * 60;
                    foreach (self::occurrences($ev, $now - 300, $now + 1800 + $maxBefore, 50) as $o) {
                        foreach ($mins as $m) {
                            $at = $o[0] - $m * 60; if ($at < $now - 300 || $at > $now + 1800) continue;
                            $list[] = ['id' => 'ev:' . $ev['id'] . ':' . $o[0] . ':' . $m, 'at' => $at, 'title' => (string)$ev['title'], 'body' => self::alertWhen($o[0], (int)$ev['all_day'] === 1, $m) . ((string)$ev['location'] !== '' ? ' · ' . $ev['location'] : ''), 'colour' => $cals[$ev['calendar_id']]['colour'], 'kind' => 'event'];
                        }
                    }
                }
            }
            foreach (Reminders::alerts($user, $now - 300, $now + 1800) as $a) $list[] = $a;
            \Vaultcast\Repository::cachePut($key, ['stamp' => $stamp, 'alerts' => $list], 600);
        }
        $list = array_values(array_filter($list, function ($a) use ($now, $window) { return $a['at'] >= $now - 120 && $a['at'] <= $now + $window; }));
        return ['ok' => true, 'now' => $now, 'alerts' => $list];
    }
    private static function alertWhen(int $start, bool $allDay, int $mins): string {
        if ($mins === 0) return $allDay ? 'Today' : 'Starting now';
        if ($mins < 60) return 'In ' . $mins . ' minutes';
        if ($mins < 1440) return 'In ' . round($mins / 60) . ' hour' . ($mins >= 120 ? 's' : '');
        return date($allDay ? 'l j M' : 'l j M, H:i', $start);
    }

    // ------------------------------------------------------------------ ICS output
    private static function esc(string $v): string { return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ["\\\\", '\\;', '\\,', '\\n', '\\n', '\\n'], $v); }
    /** Fold to 75 octets per line without splitting a UTF-8 character (RFC 5545 §3.1). */
    private static function fold(string $line): string {
        if (strlen($line) <= 75) return $line . "\r\n";
        $out = ''; $cur = ''; $limit = 75;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            if (strlen($cur) + strlen($ch) > $limit) { $out .= $cur . "\r\n "; $cur = ''; $limit = 74; }
            $cur .= $ch;
        }
        return $out . $cur . "\r\n";
    }
    private static function vtimezone(string $name, int $from, int $to): array {
        $tz = self::tz($name); $lines = ['BEGIN:VTIMEZONE', 'TZID:' . $tz->getName()];
        $trans = $tz->getTransitions(max(0, $from - 366 * 86400), $to + 366 * 86400) ?: [];
        $prev = null; $added = 0;
        foreach ($trans as $t) {
            if ($prev === null) { $prev = $t; continue; }
            $kind = $t['isdst'] ? 'DAYLIGHT' : 'STANDARD';
            $local = (new DateTime('@' . $t['ts']))->setTimezone(new DateTimeZone('UTC'))->modify(($prev['offset'] >= 0 ? '+' : '-') . abs($prev['offset']) . ' seconds');
            $lines[] = 'BEGIN:' . $kind; $lines[] = 'DTSTART:' . $local->format('Ymd\THis');
            $lines[] = 'TZOFFSETFROM:' . self::offset((int)$prev['offset']); $lines[] = 'TZOFFSETTO:' . self::offset((int)$t['offset']);
            if (!empty($t['abbr'])) $lines[] = 'TZNAME:' . $t['abbr'];
            $lines[] = 'END:' . $kind; $prev = $t; $added++;
            if ($added > 60) break;
        }
        if (!$added) { $o = $tz->getOffset(new DateTime('@' . $from)); $lines = array_merge($lines, ['BEGIN:STANDARD', 'DTSTART:19700101T000000', 'TZOFFSETFROM:' . self::offset($o), 'TZOFFSETTO:' . self::offset($o), 'END:STANDARD']); }
        $lines[] = 'END:VTIMEZONE';
        return $lines;
    }
    private static function offset(int $s): string { $sign = $s < 0 ? '-' : '+'; $s = abs($s); return sprintf('%s%02d%02d', $sign, intdiv($s, 3600), intdiv($s % 3600, 60)); }
    private static function icsEvents(array $c): array {
        if ($c['kind'] === 'subscribed') return [];
        return self::q('SELECT * FROM vaultcast_cal_events WHERE calendar_id=? ORDER BY start_at LIMIT ' . self::MAX_EVENTS, [$c['id']])->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function ics(array $c): string {
        $rows = self::icsEvents($c); $stamp = gmdate('Ymd\THis\Z');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Vaultcast//Calendar//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:' . self::esc((string)$c['name']), 'X-WR-TIMEZONE:' . self::serverTz(), 'REFRESH-INTERVAL;VALUE=DURATION:PT1H', 'X-PUBLISHED-TTL:PT1H'];
        $zones = [];
        foreach ($rows as $ev) if ((int)$ev['all_day'] !== 1) { $z = self::tz((string)$ev['tz'])->getName(); if ($z !== 'UTC') { $zones[$z]['from'] = min($zones[$z]['from'] ?? PHP_INT_MAX, (int)$ev['start_at']); $zones[$z]['to'] = max($zones[$z]['to'] ?? 0, (int)($ev['until_at'] ?: $ev['end_at']), (int)$ev['start_at'] + 3 * 366 * 86400); } }
        foreach ($zones as $z => $span) $lines = array_merge($lines, self::vtimezone($z, $span['from'], $span['to']));
        foreach ($rows as $ev) {
            $tz = self::tz((string)$ev['tz']); $allDay = (int)$ev['all_day'] === 1;
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . self::esc((string)$ev['uid']);
            $lines[] = 'DTSTAMP:' . $stamp;
            $lines[] = 'LAST-MODIFIED:' . gmdate('Ymd\THis\Z', (int)$ev['updated_at']);
            $lines[] = 'CREATED:' . gmdate('Ymd\THis\Z', (int)$ev['created_at']);
            $fmt = function (int $t, string $prop) use ($allDay, $tz): string {
                $d = (new DateTime('@' . $t))->setTimezone($tz);
                if ($allDay) return $prop . ';VALUE=DATE:' . $d->format('Ymd');
                return $tz->getName() === 'UTC' ? $prop . ':' . $d->format('Ymd\THis\Z') : $prop . ';TZID=' . $tz->getName() . ':' . $d->format('Ymd\THis');
            };
            $lines[] = $fmt((int)$ev['start_at'], 'DTSTART');
            $lines[] = $fmt(max((int)$ev['end_at'], (int)$ev['start_at'] + ($allDay ? 86400 : 0)), 'DTEND');
            if ((string)$ev['rrule'] !== '') $lines[] = 'RRULE:' . $ev['rrule'];
            foreach (array_filter(explode(',', (string)$ev['exdates'])) as $x) $lines[] = $fmt((int)$x, 'EXDATE');
            $lines[] = 'SUMMARY:' . self::esc((string)$ev['title']);
            if ((string)$ev['location'] !== '') $lines[] = 'LOCATION:' . self::esc((string)$ev['location']);
            if ((string)$ev['notes'] !== '') $lines[] = 'DESCRIPTION:' . self::esc((string)$ev['notes']);
            if ($ev['source'] === 'ep') $lines[] = 'CATEGORIES:TV';
            $lines[] = 'TRANSP:' . ($allDay ? 'TRANSPARENT' : 'OPAQUE');
            foreach (array_filter(explode(',', (string)$ev['reminders']), 'strlen') as $m) {
                $lines[] = 'BEGIN:VALARM'; $lines[] = 'ACTION:DISPLAY'; $lines[] = 'DESCRIPTION:' . self::esc((string)$ev['title']);
                $lines[] = 'TRIGGER:' . ((int)$m === 0 ? 'PT0S' : '-PT' . (int)$m . 'M'); $lines[] = 'END:VALARM';
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        return implode('', array_map([self::class, 'fold'], $lines));
    }
    private static function sendIcs(string $body, string $name, bool $download): void {
        release_request_locks();
        header('Content-Type: text/calendar; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=300');
        $file = preg_replace('/[^A-Za-z0-9 ._-]+/', '', $name) ?: 'calendar';
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $file . '.ics"');
        header('Content-Length: ' . strlen($body));
        echo $body; exit;
    }
    public static function export(array $user, string $id): void { $c = self::need($id, $user, 'view'); if ($c['kind'] === 'subscribed') throw new DomainException('Subscribed calendars are exported by their own provider.'); self::sendIcs(self::ics($c), (string)$c['name'], true); }
    /** Public secret-token feed (webcal/https). Unknown or revoked tokens answer 404. */
    public static function publicFeed(string $token): void {
        $c = preg_match('/^[a-f0-9]{40}$/D', $token) ? self::q('SELECT * FROM vaultcast_cal_calendars WHERE feed_token=?', [$token])->fetch(PDO::FETCH_ASSOC) : null;
        if (!$c || $c['kind'] === 'subscribed') { http_response_code(404); header('Content-Type: text/plain'); echo 'Calendar not found.'; exit; }
        $key = 'cal-feed:' . $c['id'] . ':' . $c['version'];
        $hit = \Vaultcast\Repository::cacheGet($key);
        $body = is_string($hit['ics'] ?? null) ? $hit['ics'] : self::ics($c);
        if (!is_string($hit['ics'] ?? null)) \Vaultcast\Repository::cachePut($key, ['ics' => $body], 3600);
        self::sendIcs($body, (string)$c['name'], false);
    }

    // ------------------------------------------------------------------ ICS input (import and subscriptions)
    private static function unesc(string $v): string { return strtr($v, ['\\n' => "\n", '\\N' => "\n", '\\,' => ',', '\\;' => ';', '\\\\' => '\\']); }
    private static function icsTime(string $v, array $params, bool $forceDate = false): ?int {
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})(Z)?)?$/', trim($v), $m)) return null;
        $dateOnly = !isset($m[4]) || $m[4] === '' || $forceDate && (!isset($m[4]) || $m[4] === '');
        $tz = !empty($m[7]) ? new DateTimeZone('UTC') : self::tz(isset($params['TZID']) ? self::mapTz(trim($params['TZID'], '"')) : '');
        $d = new DateTime(sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $dateOnly ? '00' : $m[4], $dateOnly ? '00' : $m[5], $dateOnly ? '00' : $m[6]), $tz);
        return $d->getTimestamp();
    }
    /** Windows zone names in Outlook feeds → IANA (the common ones); anything unknown falls back to the server zone. */
    private static function mapTz(string $name): string {
        static $map = ['GMT Standard Time' => 'Europe/London', 'W. Europe Standard Time' => 'Europe/Berlin', 'Romance Standard Time' => 'Europe/Paris', 'Central Europe Standard Time' => 'Europe/Budapest',
            'Eastern Standard Time' => 'America/New_York', 'Central Standard Time' => 'America/Chicago', 'Mountain Standard Time' => 'America/Denver', 'Pacific Standard Time' => 'America/Los_Angeles',
            'AUS Eastern Standard Time' => 'Australia/Sydney', 'Tokyo Standard Time' => 'Asia/Tokyo', 'India Standard Time' => 'Asia/Kolkata', 'UTC' => 'UTC', 'Coordinated Universal Time' => 'UTC'];
        if (isset($map[$name])) return $map[$name];
        return in_array($name, DateTimeZone::listIdentifiers(), true) ? $name : self::serverTz();
    }
    /** Parse VEVENTs into the stored event shape (no database writes). */
    public static function parseIcs(string $text, int $max = 5000): array {
        if (strpos($text, 'BEGIN:VCALENDAR') === false) throw new DomainException('That is not an iCalendar (.ics) file.');
        $text = preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $text));
        $calTz = preg_match('/^X-WR-TIMEZONE:(.+)$/m', $text, $mm) ? self::mapTz(trim($mm[1])) : '';
        $out = [];
        foreach (explode('BEGIN:VEVENT', $text) as $idx => $chunk) {
            if ($idx === 0) continue;
            $chunk = explode('END:VEVENT', $chunk)[0];
            $chunk = preg_replace('/BEGIN:VALARM.*?END:VALARM\n?/s', '', $chunk, -1);
            $ev = []; $p = []; $alarms = [];
            if (preg_match_all('/TRIGGER(?:;[^:]*)?:-?P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?/', explode('END:VEVENT', explode('BEGIN:VEVENT', $text)[$idx] ?? '')[0] ?? '', $tm, PREG_SET_ORDER))
                foreach ($tm as $t) $alarms[] = (int)($t[1] ?? 0) * 1440 + (int)($t[2] ?? 0) * 60 + (int)($t[3] ?? 0);
            foreach (explode("\n", $chunk) as $line) {
                if (!preg_match('/^([A-Za-z-]+)((?:;[^:]*)?):(.*)$/', rtrim($line, "\r"), $m)) continue;
                $name = strtoupper($m[1]); $params = [];
                foreach (array_filter(explode(';', $m[2])) as $kv) { $x = explode('=', $kv, 2); if (count($x) === 2) $params[strtoupper($x[0])] = $x[1]; }
                if ($name === 'EXDATE') { $ev['EXDATE'][] = [$m[3], $params]; continue; }
                $ev[$name] = $m[3]; $p[$name] = $params;
            }
            if (empty($ev['DTSTART']) || !empty($ev['RECURRENCE-ID'])) continue;
            if (strtoupper((string)($ev['STATUS'] ?? '')) === 'CANCELLED') continue;
            $dp = $p['DTSTART'] ?? []; if (!isset($dp['TZID']) && $calTz !== '' && !preg_match('/Z$/', $ev['DTSTART'])) $dp['TZID'] = $calTz;
            $allDay = strtoupper($dp['VALUE'] ?? '') === 'DATE' || !preg_match('/T\d{6}/', $ev['DTSTART']);
            $tzName = $allDay ? ($calTz ?: self::serverTz()) : (preg_match('/Z$/', $ev['DTSTART']) ? ($calTz ?: self::serverTz()) : self::mapTz(trim((string)($dp['TZID'] ?? ''), '"')));
            if ($allDay) $dp = ['TZID' => $tzName];
            $start = self::icsTime($ev['DTSTART'], $dp); if ($start === null) continue;
            $ep = $p['DTEND'] ?? $dp; if ($allDay) $ep = ['TZID' => $tzName];
            $end = !empty($ev['DTEND']) ? self::icsTime($ev['DTEND'], $ep) : null;
            if ($end === null && !empty($ev['DURATION']) && preg_match('/^P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $ev['DURATION'], $dm))
                $end = $start + (int)($dm[1] ?? 0) * 604800 + (int)($dm[2] ?? 0) * 86400 + (int)($dm[3] ?? 0) * 3600 + (int)($dm[4] ?? 0) * 60 + (int)($dm[5] ?? 0);
            if ($end === null || $end < $start) $end = $start + ($allDay ? 86400 : 0);
            if ($allDay && $end === $start) $end = $start + 86400;
            $rrule = ''; $until = 0;
            if (!empty($ev['RRULE'])) {
                $r = self::parseRule($ev['RRULE']); $keep = [];
                if (in_array($r['FREQ'] ?? '', self::FREQS, true)) {
                    foreach (['FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'BYDAY', 'BYMONTHDAY'] as $k) if (isset($r[$k]) && preg_match('/^[A-Z0-9,+\-]+$/', $r[$k])) $keep[] = $k . '=' . $r[$k];
                    $rrule = implode(';', $keep);
                    if (!empty($r['UNTIL'])) { $u = self::icsTime($r['UNTIL'], ['TZID' => $tzName]); if ($u) $until = $u + (strlen($r['UNTIL']) === 8 ? 86399 : 0); }
                }
            }
            $ex = [];
            foreach ((array)($ev['EXDATE'] ?? []) as $pair) foreach (explode(',', $pair[0]) as $x) { $t = self::icsTime($x, $allDay ? ['TZID' => $tzName] : ($pair[1] + ['TZID' => $tzName])); if ($t) $ex[] = $t; }
            $out[] = ['uid' => mb_substr(self::unesc((string)($ev['UID'] ?? '')), 0, 190) ?: hash('sha1', $ev['DTSTART'] . ($ev['SUMMARY'] ?? '')) . '@import',
                'title' => mb_substr(trim(self::unesc((string)($ev['SUMMARY'] ?? ''))) ?: '(no title)', 0, 200), 'location' => mb_substr(self::unesc((string)($ev['LOCATION'] ?? '')), 0, 200),
                'notes' => mb_substr(self::unesc((string)($ev['DESCRIPTION'] ?? '')), 0, 4000), 'all_day' => $allDay ? 1 : 0, 'start_at' => $start, 'end_at' => $end, 'tz' => $tzName,
                'rrule' => $rrule, 'until_at' => $until, 'exdates' => implode(',', array_slice($ex, 0, 500)), 'reminders' => implode(',', array_slice(array_unique(array_filter($alarms, function ($m) { return $m >= 0 && $m <= 40320; })), 0, 5)), 'source' => ''];
            if (count($out) >= $max) break;
        }
        return $out;
    }
    /** Import into a local calendar; events with a UID already present are updated, not duplicated. */
    public static function import(array $user, string $id, string $text): array {
        $c = self::need($id, $user, 'edit');
        if (strlen($text) > 8000000) throw new DomainException('That file is too large (8 MB at most).');
        $events = self::parseIcs($text, 5000); $now = time(); $added = 0; $updated = 0;
        $s = self::q('SELECT COUNT(*) FROM vaultcast_cal_events WHERE calendar_id=?', [$id]); if ((int)$s->fetchColumn() + count($events) > self::MAX_EVENTS) throw new DomainException('This calendar would have too many events.');
        \Vaultcast\Database::transaction(function (PDO $pdo) use ($events, $id, $now, &$added, &$updated) {
            $find = $pdo->prepare('SELECT id FROM vaultcast_cal_events WHERE calendar_id=? AND uid=?');
            $ins = $pdo->prepare('INSERT INTO vaultcast_cal_events (id,calendar_id,uid,title,location,notes,all_day,start_at,end_at,tz,rrule,until_at,exdates,reminders,source,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $upd = $pdo->prepare('UPDATE vaultcast_cal_events SET title=?,location=?,notes=?,all_day=?,start_at=?,end_at=?,tz=?,rrule=?,until_at=?,exdates=?,reminders=?,updated_at=? WHERE id=?');
            foreach ($events as $e) {
                $find->execute([$id, $e['uid']]); $old = $find->fetchColumn();
                if ($old) { $upd->execute([$e['title'], $e['location'], $e['notes'], $e['all_day'], $e['start_at'], $e['end_at'], $e['tz'], $e['rrule'], $e['until_at'], $e['exdates'], $e['reminders'], $now, $old]); $updated++; }
                else { $ins->execute([self::id(), $id, $e['uid'], $e['title'], $e['location'], $e['notes'], $e['all_day'], $e['start_at'], $e['end_at'], $e['tz'], $e['rrule'], $e['until_at'], $e['exdates'], $e['reminders'], '', $now, $now]); $added++; }
            }
        });
        self::touch($id);
        return ['ok' => true, 'added' => $added, 'updated' => $updated];
    }
    /** Subscribed calendars: fetched through the SSRF-guarded client, parsed and cached for 15 minutes. */
    private static function subscribedEvents(array $c): array {
        $url = (string)$c['url']; if ($url === '') return [];
        $key = 'cal-sub:' . hash('sha256', $url);
        $hit = \Vaultcast\Repository::cacheGet($key);
        if (is_array($hit['events'] ?? null) && (int)($hit['at'] ?? 0) > time() - 900) return $hit['events'];
        try {
            $owner = \Vaultcast\Repository::user((string)$c['owner_id'], true);
            $r = WebFetch::get($url, 8, 6000000, ($owner['role'] ?? '') === 'admin');
            if ((int)$r['status'] !== 200) throw new DomainException('The subscribed calendar did not answer.');
            $events = self::parseIcs((string)$r['body'], 3000);
            \Vaultcast\Repository::cachePut($key, ['events' => $events, 'at' => time()], 7 * 86400);
            return $events;
        } catch (Throwable $e) {
            if (is_array($hit['events'] ?? null)) return $hit['events'];   // keep showing the last good copy
            throw new DomainException('“' . $c['name'] . '” could not be loaded: ' . ($e instanceof DomainException ? $e->getMessage() : 'the address did not answer.'));
        }
    }

    // ------------------------------------------------------------------ automatic episode releases (background task)
    /** Queue the refresh in the background: when asked ($force), or when the last run is older than a day. */
    public static function queueEpisodes(bool $force): void {
        $dir = STREAM_DATA . '/runtime'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $lock = @fopen($dir . '/cal-episodes-queue.lock', 'c'); if (!$lock) return;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) return;
            $st = (array)\Vaultcast\Repository::document('cal-episodes-state', []);
            if (!$force && time() - (int)($st['at'] ?? 0) < 86400) return;
            if (time() - (int)($st['queued_at'] ?? 0) < ($force ? 20 : 600)) return;
            $job = !empty($st['job']) ? \Vaultcast\Repository::document('maintenance-job:' . $st['job'], []) : [];
            if (in_array($job['status'] ?? '', ['queued'], true)) return;
            $r = Maintenance::start('calendar-episodes', 'Release calendar');
            \Vaultcast\Repository::putDocument('cal-episodes-state', ['at' => (int)($st['at'] ?? 0), 'queued_at' => time(), 'job' => $r['job']['id'] ?? '']);
        } catch (Throwable $e) { error_log('Vaultcast calendar episodes queue: ' . $e->getMessage()); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    /**
     * Maintenance task: write upcoming air dates (14 days back, 180 ahead) into every calendar with episodes on.
     * Reads only the episode guides the 'facts' task already stored; no network here either.
     */
    public static function refreshEpisodes(?callable $checkpoint = null): array {
        $cals = self::q("SELECT * FROM vaultcast_cal_calendars WHERE episodes <> '' AND kind='local'")->fetchAll(PDO::FETCH_ASSOC);
        $from = gmdate('Y-m-d', time() - 14 * 86400); $to = gmdate('Y-m-d', time() + 180 * 86400);
        $guides = []; $library = null; $written = 0;
        $guide = function (string $key) use (&$guides) { if (!array_key_exists($key, $guides)) { $g = \Vaultcast\Repository::document('tvmaze-guide:' . $key, null); $guides[$key] = is_array($g) ? $g : null; } return $guides[$key]; };
        foreach ($cals as $c) {
            if ($checkpoint) $checkpoint();
            $owner = \Vaultcast\Repository::user((string)$c['owner_id'], true);
            $keys = [];
            if ($owner && AreaAccess::allowed($owner, 'library')) {
                if ($c['episodes'] === 'library') {
                    if ($library === null) { $library = []; foreach (self::q("SELECT name FROM vaultcast_documents WHERE name LIKE 'tvmaze-guide:%'")->fetchAll(PDO::FETCH_COLUMN) as $n) $library[] = substr((string)$n, 13); }
                    $keys = $library;
                } else {
                    $hub = (array)\Vaultcast\Repository::document('home-hub:' . $owner['id'], []);
                    foreach (['favourites', 'watchlist'] as $shelf) foreach ((array)($hub[$shelf] ?? []) as $entry) { $card = (array)($entry['card'] ?? []); if (($card['kind'] ?? '') === 'show' && !empty($card['show_key'])) $keys[(string)$card['show_key']] = (string)($card['title'] ?? ''); }
                    $keys = array_keys($keys);
                }
                // Parental controls: never add shows above the owner's age ceiling.
                if (class_exists('Parental') && Parental::restricted($owner)) $keys = array_values(array_filter($keys, static function ($k) use ($owner) { return Parental::allowsShowKey($owner, (string)$k); }));
            }
            $rows = [];
            foreach ($keys as $key) {
                $g = $guide((string)$key); if (!$g) continue;
                $show = (string)($g['name'] ?? 'TV');
                foreach ((array)($g['episodes'] ?? []) as $ep) {
                    $date = (string)($ep['airdate'] ?? '');
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || $date < $from || $date > $to) continue;
                    $se = isset($ep['season'], $ep['number']) && $ep['number'] !== null ? sprintf('S%02dE%02d', (int)$ep['season'], (int)$ep['number']) : 'Special';
                    $name = trim((string)($ep['name'] ?? ''));
                    $uid = 'ep-' . ($ep['id'] ?? ($key . '-' . $se)) . '@vaultcast';
                    $rows[$uid] = ['uid' => $uid, 'title' => mb_substr($show . ' · ' . $se . ($name !== '' && !preg_match('/^Episode \d+$/i', $name) ? ' · ' . $name : ''), 0, 200), 'date' => $date, 'notes' => $name, 'show' => $key];
                }
            }
            $tz = self::tz(self::serverTz()); $now = time();
            \Vaultcast\Database::transaction(function (PDO $pdo) use ($c, $rows, $tz, $now, &$written) {
                $pdo->prepare("DELETE FROM vaultcast_cal_events WHERE calendar_id=? AND source='ep'")->execute([$c['id']]);
                $ins = $pdo->prepare('INSERT INTO vaultcast_cal_events (id,calendar_id,uid,title,location,notes,all_day,start_at,end_at,tz,rrule,until_at,exdates,reminders,source,created_at,updated_at) VALUES (?,?,?,?,?,?,1,?,?,?,\'\',0,\'\',\'\',\'ep\',?,?)');
                foreach (array_slice($rows, 0, 3000) as $r) {
                    $s = new DateTime($r['date'] . ' 00:00:00', $tz); $e = clone $s; $e->modify('+1 day');
                    $ins->execute([self::id(), $c['id'], $r['uid'], $r['title'], '', $r['notes'], $s->getTimestamp(), $e->getTimestamp(), $tz->getName(), $now, $now]); $written++;
                }
                $pdo->prepare('UPDATE vaultcast_cal_calendars SET version=version+1 WHERE id=?')->execute([$c['id']]);
            });
        }
        $st = (array)\Vaultcast\Repository::document('cal-episodes-state', []);
        \Vaultcast\Repository::putDocument('cal-episodes-state', ['at' => time(), 'queued_at' => (int)($st['queued_at'] ?? 0), 'job' => (string)($st['job'] ?? '')]);
        self::bump([]);
        return ['ok' => true, 'calendars' => count($cals), 'episodes' => $written];
    }
}

function cal_days(int $y, int $m): int { return (int)date('t', gmmktime(12, 0, 0, $m, 1, $y)); }
