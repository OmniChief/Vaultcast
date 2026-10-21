<?php
declare(strict_types=1);

/**
 * Board → Reminders: repeating personal reminders (bins, bills, medication, car, birthdays…) stored per user.
 * Schedules work on calendar dates in the reminder's zone; "done" advances to the next occurrence and
 * "snooze" hides the current one for a while. Reminders can also show on the calendar and raise alerts.
 */
final class Reminders
{
    const MAX = 200;
    const TYPES = ['once', 'daily', 'weekly', 'monthly', 'yearly'];

    private static function q(string $sql, array $p = []): PDOStatement { return Arcade::query($sql, $p); }
    private static function signed(array $user): array {
        if (($user['role'] ?? 'guest') === 'guest' || ($user['id'] ?? 'public') === 'public') throw new DomainException('Sign in to keep reminders.');
        return $user;
    }
    private static function tz(string $name): DateTimeZone { try { return new DateTimeZone($name !== '' ? $name : (date_default_timezone_get() ?: 'UTC')); } catch (Throwable $e) { return new DateTimeZone('UTC'); } }
    private static function today(DateTimeZone $tz): string { return (new DateTime('now', $tz))->format('Y-m-d'); }
    private static function d(string $ymd): DateTimeImmutable { return new DateTimeImmutable($ymd . ' 12:00:00', new DateTimeZone('UTC')); }
    private static function validDate(string $v): bool { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)); }

    /** Clean the schedule the app sent: { type, every, days[0-6], start (Y-m-d), monthly:'day'|'nth', nth (1-5 or -1), weekday }. */
    private static function schedule(array $s, DateTimeZone $tz): array {
        $type = in_array($s['type'] ?? '', self::TYPES, true) ? (string)$s['type'] : 'weekly';
        $start = self::validDate((string)($s['start'] ?? '')) ? (string)$s['start'] : self::today($tz);
        $out = ['type' => $type, 'start' => $start, 'every' => max(1, min(52, (int)($s['every'] ?? 1)))];
        if ($type === 'weekly') {
            $days = array_values(array_unique(array_filter(array_map('intval', (array)($s['days'] ?? [])), function ($d) { return $d >= 0 && $d <= 6; })));
            sort($days);
            $out['days'] = $days ?: [(int)self::d($start)->format('w')];
        }
        if ($type === 'monthly') {
            $out['monthly'] = ($s['monthly'] ?? 'day') === 'nth' ? 'nth' : 'day';
            if ($out['monthly'] === 'nth') {
                $nth = (int)($s['nth'] ?? 1); $out['nth'] = in_array($nth, [1, 2, 3, 4, -1], true) ? $nth : 1;
                $wd = (int)($s['weekday'] ?? self::d($start)->format('w')); $out['weekday'] = $wd >= 0 && $wd <= 6 ? $wd : 1;
            } else $out['day'] = max(1, min(31, (int)($s['day'] ?? self::d($start)->format('j'))));
        }
        if ($type === 'once') unset($out['every']);
        return $out;
    }
    private static function nthWeekday(int $y, int $m, int $nth, int $wd): ?string {
        $dim = (int)date('t', gmmktime(12, 0, 0, $m, 1, $y));
        if ($nth > 0) { $first = (int)date('w', gmmktime(12, 0, 0, $m, 1, $y)); $day = 1 + (($wd - $first + 7) % 7) + ($nth - 1) * 7; }
        else { $last = (int)date('w', gmmktime(12, 0, 0, $m, $dim, $y)); $day = $dim - (($last - $wd + 7) % 7); }
        return $day >= 1 && $day <= $dim ? sprintf('%04d-%02d-%02d', $y, $m, $day) : null;
    }
    /** Dates (Y-m-d) of the schedule from $from (inclusive) to $to (inclusive), at most $limit. */
    public static function dates(array $s, string $from, string $to, int $limit = 400): array {
        $start = (string)$s['start']; $every = max(1, (int)($s['every'] ?? 1)); $out = [];
        if ($from < $start) $from = $start;
        if ($from > $to) return [];
        $f = self::d($from); $st = self::d($start);
        switch ($s['type']) {
            case 'once': return $start >= $from && $start <= $to ? [$start] : [];
            case 'daily':
                $gap = (int)$st->diff($f)->days; $k = (int)ceil($gap / $every);
                for ($d = $st->modify('+' . ($k * $every) . ' day'); ($x = $d->format('Y-m-d')) <= $to && count($out) < $limit; $d = $d->modify('+' . $every . ' day')) $out[] = $x;
                return $out;
            case 'weekly':
                $monday = $st->modify('-' . (((int)$st->format('N')) - 1) . ' day');
                $weeks = intdiv((int)$monday->diff($f)->days, 7); $k = intdiv($weeks, $every) * $every;
                for ($guard = 0; $guard < 600 && count($out) < $limit; $guard++, $k += $every) {
                    $wk = $monday->modify('+' . ($k * 7) . ' day');
                    if ($wk->format('Y-m-d') > $to) break;
                    foreach ((array)$s['days'] as $wd) {
                        $x = $wk->modify('+' . (((int)$wd + 6) % 7) . ' day')->format('Y-m-d');
                        if ($x >= $from && $x <= $to) $out[] = $x;
                    }
                }
                sort($out); return array_slice($out, 0, $limit);
            case 'monthly':
                $y0 = (int)$st->format('Y'); $m0 = (int)$st->format('n');
                $gap = ((int)$f->format('Y') - $y0) * 12 + (int)$f->format('n') - $m0; $k = max(0, intdiv(max(0, $gap - 1), $every) * $every);
                for ($guard = 0; $guard < 600 && count($out) < $limit; $guard++, $k += $every) {
                    $idx = $m0 - 1 + $k; $y = $y0 + intdiv($idx, 12); $m = $idx % 12 + 1;
                    if (sprintf('%04d-%02d-01', $y, $m) > $to) break;
                    if (($s['monthly'] ?? 'day') === 'nth') $x = self::nthWeekday($y, $m, (int)$s['nth'], (int)$s['weekday']);
                    else { $dim = (int)date('t', gmmktime(12, 0, 0, $m, 1, $y)); $x = sprintf('%04d-%02d-%02d', $y, $m, min($dim, (int)($s['day'] ?? 1))); }
                    if ($x !== null && $x >= $from && $x <= $to) $out[] = $x;
                }
                return $out;
            case 'yearly':
                $y0 = (int)$st->format('Y'); $md = $st->format('m-d');
                $k = max(0, intdiv(max(0, (int)$f->format('Y') - $y0 - 1), $every) * $every);
                for ($guard = 0; $guard < 200 && count($out) < $limit; $guard++, $k += $every) {
                    $y = $y0 + $k; if ($y . '-01-01' > $to) break;
                    $x = $md === '02-29' && !checkdate(2, 29, $y) ? $y . '-02-28' : $y . '-' . $md;
                    if ($x >= $from && $x <= $to) $out[] = $x;
                }
                return $out;
        }
        return [];
    }
    private static function next(array $s, string $after): ?string {
        $from = self::d($after)->modify('+1 day')->format('Y-m-d');
        $r = self::dates($s, $from, self::d($from)->modify('+3000 day')->format('Y-m-d'), 1);
        return $r[0] ?? null;
    }
    private static function at(string $date, string $time, DateTimeZone $tz): int {
        $t = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $time) ? $time : '09:00';
        return (new DateTime($date . ' ' . $t . ':00', $tz))->getTimestamp();
    }

    private static function row(array $r): array {
        $r['schedule'] = json_decode((string)$r['schedule'], true) ?: ['type' => 'once', 'start' => date('Y-m-d')];
        return $r;
    }
    /** Shape for the app: next due date, flags for overdue / due soon, and a snooze state. */
    private static function present(array $r, ?int $now = null): array {
        $now = $now ?? time(); $tz = self::tz((string)$r['tz']); $today = self::today($tz);
        $due = (int)$r['active'] ? self::next($r['schedule'], (string)$r['done_through']) : null;
        $dueAt = $due ? self::at($due, (string)$r['at_time'], $tz) : 0;
        $lead = (int)$r['lead_minutes'];
        $snoozed = (int)$r['snooze_until'] > $now;
        $state = 'later';
        if ($due !== null) {
            if ($due < $today) $state = 'overdue';
            elseif ($due === $today) $state = 'today';
            elseif ($dueAt - $lead * 60 <= $now) $state = 'soon';
            elseif ($due === self::d($today)->modify('+1 day')->format('Y-m-d')) $state = 'tomorrow';
        }
        return ['id' => (string)$r['id'], 'title' => (string)$r['title'], 'emoji' => (string)$r['emoji'], 'colour' => (string)$r['colour'], 'note' => (string)$r['note'],
            'template' => (string)$r['template'], 'schedule' => $r['schedule'], 'time' => (string)$r['at_time'], 'lead' => $lead, 'tz' => $tz->getName(),
            'active' => (bool)(int)$r['active'], 'due' => $due, 'due_at' => $dueAt, 'state' => $snoozed ? 'snoozed' : $state, 'snooze_until' => $snoozed ? (int)$r['snooze_until'] : 0,
            'after' => $due ? self::next($r['schedule'], $due) : null, 'calendar' => (bool)(int)$r['on_calendar']];
    }
    private static function rows(string $uid): array {
        return array_map([self::class, 'row'], self::q('SELECT * FROM vaultcast_cal_reminders WHERE user_id=? ORDER BY created_at', [$uid])->fetchAll(PDO::FETCH_ASSOC));
    }
    private static function own(array $user, string $id): array {
        if (!preg_match('/^[a-f0-9]{16}$/D', $id)) throw new DomainException('Reminder not found.');
        $r = self::q('SELECT * FROM vaultcast_cal_reminders WHERE id=? AND user_id=?', [$id, $user['id']])->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new DomainException('Reminder not found.');
        return self::row($r);
    }
    public static function list(array $user): array {
        self::signed($user); $now = time();
        $items = array_map(function ($r) use ($now) { return self::present($r, $now); }, self::rows((string)$user['id']));
        $rank = ['overdue' => 0, 'today' => 1, 'soon' => 2, 'tomorrow' => 3, 'snoozed' => 4, 'later' => 5];
        usort($items, function ($a, $b) use ($rank) {
            if ($a['due'] === null || $b['due'] === null) return ($a['due'] === null) <=> ($b['due'] === null);
            return [$a['due'], $a['due_at'], $rank[$a['state']] ?? 9] <=> [$b['due'], $b['due_at'], $rank[$b['state']] ?? 9];
        });
        return ['ok' => true, 'reminders' => $items, 'now' => $now];
    }
    private static function fields(array $b): array {
        $title = mb_substr(trim((string)($b['title'] ?? '')), 0, 120); if ($title === '') throw new DomainException('Give the reminder a title.');
        $emoji = mb_substr(trim((string)($b['emoji'] ?? '')), 0, 8);
        $tzName = (string)($b['tz'] ?? ''); if (!in_array($tzName, DateTimeZone::listIdentifiers(), true)) $tzName = date_default_timezone_get() ?: 'UTC';
        $tz = self::tz($tzName);
        $time = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', (string)($b['time'] ?? '')) ? (string)$b['time'] : '';
        return ['title' => $title, 'emoji' => $emoji !== '' ? $emoji : '🔔', 'colour' => preg_match('/^#[0-9a-f]{6}$/i', (string)($b['colour'] ?? '')) ? strtolower((string)$b['colour']) : '#59e9ff',
            'note' => mb_substr(trim((string)($b['note'] ?? '')), 0, 200), 'template' => preg_match('/^[a-z0-9-]{0,32}$/D', (string)($b['template'] ?? '')) ? (string)($b['template'] ?? '') : '',
            'schedule' => self::schedule((array)($b['schedule'] ?? []), $tz), 'at_time' => $time, 'lead_minutes' => max(0, min(20160, (int)($b['lead'] ?? 0))), 'tz' => $tzName,
            'on_calendar' => !empty($b['calendar']) ? 1 : 0];
    }
    public static function create(array $user, array $b): array {
        self::signed($user);
        if ((int)self::q('SELECT COUNT(*) FROM vaultcast_cal_reminders WHERE user_id=?', [$user['id']])->fetchColumn() >= self::MAX) throw new DomainException('You can have up to ' . self::MAX . ' reminders.');
        $f = self::fields($b); $id = bin2hex(random_bytes(8)); $now = time();
        // Nothing before today counts as missed: the first due date is the first occurrence from today on.
        self::q('INSERT INTO vaultcast_cal_reminders (id,user_id,title,emoji,colour,note,template,schedule,at_time,lead_minutes,tz,done_through,snooze_until,active,on_calendar,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,1,?,?,?)',
            [$id, $user['id'], $f['title'], $f['emoji'], $f['colour'], $f['note'], $f['template'], json_encode($f['schedule']), $f['at_time'], $f['lead_minutes'], $f['tz'], self::firstDone($f), $f['on_calendar'], $now, $now]);
        self::changed();
        return self::list($user) + ['id' => $id];
    }
    private static function firstDone(array $f): string {
        $today = self::today(self::tz($f['tz']));
        $first = max($today, (string)$f['schedule']['start']);
        return self::d($first)->modify('-1 day')->format('Y-m-d');
    }
    public static function update(array $user, string $id, array $b): array {
        $old = self::own($user, $id); $f = self::fields($b);
        $reset = json_encode($f['schedule']) !== json_encode($old['schedule']) || $f['tz'] !== $old['tz'];
        self::q('UPDATE vaultcast_cal_reminders SET title=?,emoji=?,colour=?,note=?,template=?,schedule=?,at_time=?,lead_minutes=?,tz=?,done_through=?,snooze_until=?,active=1,on_calendar=?,updated_at=? WHERE id=?',
            [$f['title'], $f['emoji'], $f['colour'], $f['note'], $f['template'], json_encode($f['schedule']), $f['at_time'], $f['lead_minutes'], $f['tz'], $reset ? self::firstDone($f) : (string)$old['done_through'], $reset ? 0 : (int)$old['snooze_until'], $f['on_calendar'], time(), $id]);
        self::changed();
        return self::list($user);
    }
    public static function delete(array $user, string $id): array { self::own($user, $id); self::q('DELETE FROM vaultcast_cal_reminders WHERE id=?', [$id]); self::changed(); return self::list($user); }
    /** Done: the current occurrence (and anything overdue before today) is cleared and the next one comes up. */
    public static function done(array $user, string $id): array {
        $r = self::own($user, $id); $tz = self::tz((string)$r['tz']); $today = self::today($tz);
        $due = self::next($r['schedule'], (string)$r['done_through']);
        if ($due === null) return self::list($user);
        $through = $due < $today ? self::d($today)->modify('-1 day')->format('Y-m-d') : $due;
        if ($through < $due) $through = $due;
        $active = self::next($r['schedule'], $through) !== null ? 1 : 0;
        self::q('UPDATE vaultcast_cal_reminders SET done_through=?,snooze_until=0,active=?,updated_at=? WHERE id=?', [$through, $active, time(), $id]);
        self::changed();
        return self::list($user);
    }
    public static function undo(array $user, string $id, string $through): array {
        self::own($user, $id);
        if (!self::validDate($through)) throw new DomainException('Nothing to undo.');
        self::q('UPDATE vaultcast_cal_reminders SET done_through=?,active=1,updated_at=? WHERE id=?', [$through, time(), $id]);
        self::changed();
        return self::list($user);
    }
    public static function snooze(array $user, string $id, int $minutes): array {
        self::own($user, $id); $minutes = max(5, min(10080, $minutes));
        self::q('UPDATE vaultcast_cal_reminders SET snooze_until=?,updated_at=? WHERE id=?', [time() + $minutes * 60, time(), $id]);
        self::changed();
        return self::list($user);
    }
    /** One-off import of the reminders the old Bin day widget kept in its own settings. */
    public static function importLegacy(array $user, array $items, string $tz): array {
        self::signed($user); $n = 0;
        foreach (array_slice($items, 0, 50) as $x) {
            if (!is_array($x) || trim((string)($x['title'] ?? '')) === '') continue;
            $repeat = (string)($x['repeat'] ?? 'weekly');
            $start = self::validDate((string)($x['start'] ?? '')) ? (string)$x['start'] : date('Y-m-d');
            $sched = $repeat === 'once' ? ['type' => 'once', 'start' => $start] : ($repeat === 'monthly' ? ['type' => 'monthly', 'start' => $start, 'monthly' => 'day', 'day' => (int)substr($start, 8, 2)]
                : ['type' => 'weekly', 'start' => $start, 'every' => (int)($x['every'] ?? 1), 'days' => [(int)($x['weekday'] ?? 1)]]);
            try { self::create($user, ['title' => $x['title'], 'emoji' => $x['emoji'] ?? '🗑️', 'colour' => $x['colour'] ?? '', 'note' => $x['note'] ?? '', 'template' => 'bin', 'schedule' => $sched, 'lead' => 360, 'time' => '07:00', 'tz' => $tz]); $n++; }
            catch (DomainException $e) {}
        }
        return self::list($user) + ['imported' => $n];
    }
    private static function changed(): void { \Vaultcast\Repository::cachePut('cal-gen', ['v' => bin2hex(random_bytes(4))], 0); }

    /** Occurrences as calendar items (only reminders the user put on the calendar). */
    public static function calendarItems(array $user, int $from, int $to): array {
        $out = [];
        foreach (self::rows((string)$user['id']) as $r) {
            if (!(int)$r['on_calendar']) continue;
            $tz = self::tz((string)$r['tz']);
            $f = (new DateTime('@' . $from))->setTimezone($tz)->format('Y-m-d'); $t = (new DateTime('@' . max($from, $to - 1)))->setTimezone($tz)->format('Y-m-d');
            foreach (self::dates($r['schedule'], $f, $t, 120) as $date) {
                $timed = (string)$r['at_time'] !== '';
                $s = $timed ? self::at($date, (string)$r['at_time'], $tz) : (new DateTime($date . ' 00:00:00', $tz))->getTimestamp();
                $e = $timed ? $s + 900 : (new DateTime($date . ' 00:00:00', $tz))->modify('+1 day')->getTimestamp();
                $out[] = ['id' => 'rem:' . $r['id'], 'calendar_id' => 'reminders', 'colour' => (string)$r['colour'], 'title' => trim($r['emoji'] . ' ' . $r['title']), 'location' => '', 'notes' => (string)$r['note'],
                    'all_day' => !$timed, 'start' => $s, 'end' => $e, 'start_date' => $date, 'end_date' => $date, 'repeat' => null, 'reminders' => [], 'source' => 'reminder', 'editable' => false,
                    'done' => $date <= (string)$r['done_through'], 'reminder_id' => (string)$r['id']];
            }
        }
        return $out;
    }
    /** Alerts between $from and $to: the heads-up (lead time before) and the due moment itself. */
    public static function alerts(array $user, int $from, int $to): array {
        $out = [];
        foreach (self::rows((string)$user['id']) as $r) {
            if (!(int)$r['active']) continue;
            $tz = self::tz((string)$r['tz']);
            if ((int)$r['snooze_until'] >= $from && (int)$r['snooze_until'] <= $to) $out[] = ['id' => 'rem:' . $r['id'] . ':snooze:' . $r['snooze_until'], 'at' => (int)$r['snooze_until'], 'title' => trim($r['emoji'] . ' ' . $r['title']), 'body' => 'Snoozed reminder', 'colour' => $r['colour'], 'kind' => 'reminder', 'reminder_id' => $r['id']];
            $due = self::next($r['schedule'], (string)$r['done_through']); if ($due === null) continue;
            $at = self::at($due, (string)$r['at_time'], $tz); $lead = (int)$r['lead_minutes'];
            foreach (array_unique([$at - $lead * 60, $at]) as $i => $t) {
                if ($t < $from || $t > $to || (int)$r['snooze_until'] > $t) continue;
                $out[] = ['id' => 'rem:' . $r['id'] . ':' . $due . ':' . $t, 'at' => $t, 'title' => trim($r['emoji'] . ' ' . $r['title']), 'body' => $t < $at ? 'Coming up ' . date('D j M', $at) . ((string)$r['note'] !== '' ? ' · ' . $r['note'] : '') : ((string)$r['note'] !== '' ? $r['note'] : 'Due now'), 'colour' => $r['colour'], 'kind' => 'reminder', 'reminder_id' => $r['id']];
            }
        }
        return $out;
    }
}
