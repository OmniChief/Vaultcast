<?php
declare(strict_types=1);

/**
 * Per-show episode numbering for Fix Match: Season/Episode (SxxEyy) or Absolute (overall
 * episode number, common for anime fansub releases).
 *
 * Store doc `episode_numbering`:
 *   modes: { <showKey>: 'auto'|'absolute'|'season' }
 *   prev:  { <itemId>: {override: ?array, season, episode, episode_title, title} }   (restored on switch back)
 *
 * Absolute → SxxEyy uses the provider's absolute order when available (TMDB "Absolute" episode
 * group, needs the optional TMDB key), else cumulative regular-season episode counts from the
 * TVMaze guide. Specials (season 0 / non-regular) are never counted. Item ids are untouched,
 * so watch progress stays attached to the same files.
 */
final class AbsoluteNumbering {
    const STORE = 'episode_numbering';
    const MODES = ['auto', 'absolute', 'season'];

    /** @var callable|null test hook: fn(string $url): ?string */
    public static $http = null;

    /* ------------------------------------------------------------ parsing */

    /** True when the name carries an explicit season+episode code. */
    public static function hasSeasonCode(string $base): bool {
        return (bool)(preg_match('/(?<![A-Za-z0-9])[Ss]\d{1,2}[\s._-]?[Ee]\d{1,4}(?!\d)/', $base)
            || preg_match('/(?<![0-9A-Za-z])\d{1,2}[xX]\d{2,3}(?![0-9])/', $base)
            || preg_match('/\b(?:Season|Series)[\s._-]*\d{1,2}[\s._-]*(?:Episode|Ep)[\s._-]*\d{1,4}\b/i', $base));
    }

    /** Lowercase letters+digits only ("Dragon Ball Z: Kai" → "dragonballzkai"). */
    public static function compact(string $s): string {
        return strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '', $s));
    }

    /** Show-name variants to try as a filename prefix (year suffix and leading article dropped). */
    public static function titleVariants(array $titles): array {
        $out = [];
        foreach ($titles as $t) {
            $t = trim((string)$t);
            if ($t === '') continue;
            $noYear = trim((string)preg_replace('/\s*[\(\[]?(?:19|20)\d{2}[\)\]]?\s*$/', '', $t));
            foreach ([$t, $noYear, (string)preg_replace('/^(?:the|a|an)\s+/i', '', $noYear)] as $v) {
                $c = self::compact($v);
                if ($c !== '' && !in_array($c, $out, true)) $out[] = $c;
            }
        }
        usort($out, function ($a, $b) { return strlen($b) <=> strlen($a); }); // longest first
        return $out;
    }

    /** Clean a basename for number hunting: drop brackets, tags, codecs, CRCs; null when it must not be parsed. */
    private static function clean(string $base): ?string {
        $s = str_replace('_', ' ', $base);
        if (preg_match('/\b(batch|complete|全集)\b/iu', $s)) return null;
        // drop bracketed tags: [Group] [1080p] [ABCD1234] (BD 720p) (2004)
        $s = (string)preg_replace('/\[[^\]]*\]|\{[^}]*\}|\((?:[^()]*?(?:\d{3,4}p|bd|dvd|web|hevc|x26[45]|\b(?:19|20)\d{2}\b|[0-9a-f]{8})[^()]*)\)/i', ' ', $s);
        // dots used as spaces (keep "5.1" audio from becoming numbers by removing it first)
        $s = (string)preg_replace('/\b(?:aac|ac3|eac3|ddp?|dts|flac|opus)?\s*[257]\.[01]\b/i', ' ', $s);
        $s = str_replace('.', ' ', $s);
        $s = (string)preg_replace('/\b(?:\d{3,4}[pi]|[48]k|uhd|x26[45]|h\s?26[45]|hevc|avc|av1|xvid|\d{1,2}\s?bits?|hi10p?|bd(?:rip)?|blu\s?ray|web(?:\s?dl|rip)?|dvd(?:rip)?|hdtv|aac|flac|opus|ac3|dual\s?audio|multi\s?subs?|[0-9A-F]{8})\b/i', ' ', $s);
        $s = trim((string)preg_replace('/\s+/', ' ', $s));
        // ranges ("Title - 01-12", "01 ~ 26", "Ep 1-3")
        if (preg_match('/(?:^|\s|\(|#|ep\s?)\d{1,4}(?:v\d+)?(?:[-–]|\s*(?:~|\bto\b)\s*)\d{1,4}(?:v\d+)?(?:\s|\)|$)/iu', $s)) return null;
        return $s;
    }

    /** Text after a known show-title prefix (compared ignoring case, spaces and punctuation), or null. */
    private static function afterPrefix(string $s, string $compactTitle) {
        $acc = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if (!ctype_alnum($ch)) continue;
            $acc .= strtolower($ch);
            if (strpos($compactTitle, $acc) !== 0) return null;
            if ($acc === $compactTitle) {
                $rest = (string)substr($s, $i + 1);
                // the title must end at a word boundary ("Kai" must not match "Kaiju")
                if ($rest !== '' && ctype_alnum($rest[0]) && ctype_alnum($ch) && (ctype_digit($rest[0]) === ctype_digit($ch))) return null;
                return $rest;
            }
        }
        return null;
    }

    /**
     * Longest common prefix of the cleaned file names of one show, cut back to a word boundary and
     * never ending inside a number ("Show 01", "Show 02" → "Show "). Empty when < 2 files.
     */
    public static function commonPrefix(array $bases): string {
        $c = [];
        foreach ($bases as $b) {
            $b = trim((string)preg_replace('/\.(mkv|mp4|avi|m4v|webm|ts|mov|wmv)$/i', '', (string)$b));
            $x = self::clean($b);
            if ($x !== null && $x !== '') $c[] = $x;
        }
        if (count($c) < 2) return '';
        $p = $c[0];
        foreach ($c as $x) {
            $n = min(strlen($p), strlen($x));
            $i = 0;
            while ($i < $n && strtolower($p[$i]) === strtolower($x[$i])) $i++;
            $p = substr($p, 0, $i);
            if ($p === '') return '';
        }
        // back off out of a number and to the last word boundary
        $p = (string)preg_replace('/\d+$/', '', $p);
        $p = (string)preg_replace('/[A-Za-z0-9]+$/', '', $p);
        return trim($p) === '' ? '' : $p;
    }

    /**
     * Absolute episode number from a file basename (no extension).
     * Ignores resolution, CRC hashes, years, codecs and version suffixes (12v2); returns null for
     * SxxEyy names and for ranges/batches ("01-12", "01~26", "Batch", "Complete").
     * $titles: known show names/aliases; the first standalone number after the title is used
     *   ("Dragonball Z Kai 01 Prologue…", "Mob Psycho 100 05 …"), digits inside the title never count.
     * $prefix: common prefix of all files in the show (see commonPrefix) when the title is unknown.
     * @return array{number:int,method:string,fansub:bool}|null
     */
    public static function parse(string $base, array $titles = [], string $prefix = ''): ?array {
        $base = trim((string)preg_replace('/\.(mkv|mp4|avi|m4v|webm|ts|mov|wmv)$/i', '', $base));
        if ($base === '' || self::hasSeasonCode($base)) return null;
        $fansub = (bool)preg_match('/^\s*\[[^\]]+\]/', $base);
        $s = self::clean($base);
        if ($s === null || $s === '') return null;
        $pick = function (string $raw, string $method, bool $allowYear = false) use ($fansub): ?array {
            $n = (int)$raw;
            if ($n < 1 || $n > 4999) return null;
            if (!$allowYear && strlen(ltrim($raw, '0')) === 4 && $n >= 1900 && $n <= 2099) return null; // a year
            return ['number' => $n, 'method' => $method, 'fansub' => $fansub];
        };
        $v = '(?:v\d{1,2})?';
        if (preg_match('/(?:^|\s)(?:Episode|Ep|E)\s?0*(\d{1,4})' . $v . '(?=\s|$)/i', $s, $m) && ($r = $pick($m[1], 'ep'))) return $r;
        if (preg_match('/#\s?0*(\d{1,4})' . $v . '(?=\s|$)/', $s, $m) && ($r = $pick($m[1], 'hash'))) return $r;
        if (preg_match_all('/\s[-–—]\s+0*(\d{1,4})' . $v . '(?=\s|$)/u', $s, $mm)) {
            for ($i = count($mm[1]) - 1; $i >= 0; $i--) if ($r = $pick($mm[1][$i], 'dash')) return $r;
        }
        // "Show Title NN Episode title": first standalone number after the known show name
        $first = '/^[^A-Za-z0-9]*(?:(?:Episode|Ep|E|#)\s?)?(\d{1,4})' . $v . '(?![A-Za-z0-9])/i';
        foreach (self::titleVariants($titles) as $ct) {
            $rest = self::afterPrefix($s, $ct);
            if ($rest === null) continue;
            if (preg_match($first, $rest, $m) && ($r = $pick($m[1], 'title', true))) return $r;
        }
        if (preg_match('/(?:^|\s)(\d{1,5})' . $v . '$/', $s, $m) && ($r = $pick($m[1], 'trailing'))) {
            // a bare trailing number needs a fansub-style name or a zero-padded/large number
            if ($fansub || strlen($m[1]) >= 2) return $r;
        }
        // consistent prefix across the show's files: leading number of the remainder
        if ($prefix !== '' && stripos($s, $prefix) === 0) {
            if (preg_match($first, (string)substr($s, strlen($prefix)), $m) && ($r = $pick($m[1], 'prefix'))) return $r;
        }
        // general fallback: first standalone number that is not a year or part of the show name
        $skip = [];
        foreach ($titles as $t) if (preg_match_all('/\d+/', (string)$t, $tm)) foreach ($tm[0] as $d) $skip[(int)$d] = true;
        if ($prefix !== '' && preg_match_all('/\d+/', $prefix, $tm)) foreach ($tm[0] as $d) $skip[(int)$d] = true;
        if (preg_match_all('/(?<![A-Za-z0-9\'])(\d{1,4})' . $v . '(?![A-Za-z0-9\'])/', $s, $mm)) {
            foreach ($mm[1] as $raw) {
                if (isset($skip[(int)$raw])) continue;
                if ($r = $pick($raw, 'first')) return $r;
            }
        }
        return null;
    }

    /* ---------------------------------------------------------- detection */

    /**
     * Auto-detect: absolute when the show is anime by metadata or fansub-style names, and the files
     * do not use SxxEyy.
     * @param string[] $bases
     */
    public static function detect(array $bases, string $type = '', array $genres = [], array $titles = []): array {
        $total = count($bases);
        $sxe = 0; $abs = 0; $fan = 0;
        $prefix = self::commonPrefix($bases);
        foreach ($bases as $b) {
            if (self::hasSeasonCode((string)$b)) { $sxe++; continue; }
            $p = self::parse((string)$b, $titles, $prefix);
            if ($p) { $abs++; if ($p['fansub'] || in_array($p['method'], ['ep', 'hash', 'dash'], true)) $fan++; }
        }
        $reasons = [];
        $anime = false;
        if (strtolower($type) === 'anime') { $anime = true; $reasons[] = 'anime library'; }
        foreach ($genres as $g) if (strcasecmp((string)$g, 'anime') === 0) { $anime = true; $reasons[] = 'genre Anime'; break; }
        if ($total > 0 && $fan >= max(1, (int)ceil($total * 0.6))) { $anime = true; $reasons[] = 'fansub-style numbering'; }
        $mostlySxe = $total > 0 && $sxe >= (int)ceil($total * 0.5);
        if ($mostlySxe) $reasons[] = 'files use SxxEyy';
        $absolute = $anime && !$mostlySxe && $abs > 0;
        return ['mode' => $absolute ? 'absolute' : 'season', 'reasons' => array_values(array_unique($reasons)), 'sxe' => $sxe, 'absolute' => $abs, 'total' => $total];
    }

    /* ---------------------------------------------------------- conversion */

    /**
     * abs => {season, number, name, id}. Provider absolute order wins; else cumulative regular seasons.
     * @return array{source:string,map:array<int,array>}
     */
    public static function table(?array $guide): array {
        if (!is_array($guide)) return ['source' => 'none', 'map' => []];
        $byCode = [];
        foreach ($guide['episodes'] ?? [] as $e) {
            if (is_array($e) && $e['number'] !== null) $byCode[(int)$e['season'] . 'x' . (int)$e['number']] = $e;
        }
        $map = [];
        if (!empty($guide['absolute_order']) && is_array($guide['absolute_order'])) {
            foreach ($guide['absolute_order'] as $a) {
                $s = (int)($a['season'] ?? 0); $n = (int)($a['number'] ?? 0); $abs = (int)($a['absolute'] ?? 0);
                if ($s <= 0 || $n <= 0 || $abs <= 0 || isset($map[$abs])) continue;
                $g = $byCode[$s . 'x' . $n] ?? [];
                $map[$abs] = ['season' => $s, 'number' => $n, 'name' => $g['name'] ?? ($a['name'] ?? null), 'id' => $g['id'] ?? null];
            }
            if ($map) { ksort($map); return ['source' => (string)($guide['absolute_source'] ?? 'provider absolute order'), 'map' => $map]; }
        }
        $reg = [];
        foreach ($guide['episodes'] ?? [] as $e) {
            if (!is_array($e) || $e['number'] === null || (int)($e['season'] ?? 0) <= 0) continue;
            $t = (string)($e['type'] ?? 'regular');
            if ($t !== '' && $t !== 'regular') continue;
            $reg[] = $e;
        }
        usort($reg, function ($a, $b) {
            $c = ((int)$a['season']) <=> ((int)$b['season']);
            return $c !== 0 ? $c : (((int)$a['number']) <=> ((int)$b['number']));
        });
        $i = 0;
        foreach ($reg as $e) $map[++$i] = ['season' => (int)$e['season'], 'number' => (int)$e['number'], 'name' => $e['name'] ?? null, 'id' => $e['id'] ?? null];
        return ['source' => $map ? 'cumulative season counts' : 'none', 'map' => $map];
    }

    public static function toSeasonEpisode(array $table, int $abs): ?array {
        return $table['map'][$abs] ?? null;
    }

    public static function toAbsolute(array $table, $season, $episode): ?int {
        if ($season === null || $episode === null || $season === '' || $episode === '') return null;
        foreach ($table['map'] as $abs => $e) {
            if ($e['season'] === (int)$season && $e['number'] === (int)$episode) return (int)$abs;
        }
        return null;
    }

    /* ------------------------------------------------- provider absolute order */

    private static function http(string $url): ?string {
        if (self::$http) return (self::$http)($url);
        $ctx = stream_context_create(['http' => ['timeout' => 12, 'ignore_errors' => true, 'header' => "User-Agent: Vaultcast\r\nAccept: application/json\r\n"]]);
        $r = @file_get_contents($url, false, $ctx);
        return $r === false ? null : $r;
    }

    private static function json(string $url): ?array {
        $b = self::http($url);
        $j = $b !== null ? json_decode($b, true) : null;
        return is_array($j) ? $j : null;
    }

    public static function tmdbKey(): string {
        $p = Store::settings()['posters'] ?? [];
        return is_array($p) ? trim((string)($p['tmdb_api_key'] ?? '')) : '';
    }

    /**
     * TMDB "Absolute" episode group for a TVMaze show (via its TheTVDB/IMDb id). Cached 7 days.
     * @return array<int,array{absolute:int,season:int,number:int,name:?string}>|null
     */
    public static function tmdbAbsoluteOrder(int $tvmazeId, bool $refresh = false): ?array {
        $key = self::tmdbKey();
        if ($tvmazeId <= 0 || $key === '') return null;
        $dir = STREAM_DATA . '/tvmaze';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $cache = $dir . '/absorder_' . $tvmazeId . '.json';
        if (!$refresh && is_file($cache) && (time() - filemtime($cache)) < 7 * 86400) {
            $c = json_decode((string)@file_get_contents($cache), true);
            if (is_array($c)) return $c['order'] ?: null;
        }
        $order = [];
        $show = self::json('https://api.tvmaze.com/shows/' . $tvmazeId);
        $ext = is_array($show['externals'] ?? null) ? $show['externals'] : [];
        $tmdb = 'https://api.themoviedb.org/3';
        $q = 'api_key=' . rawurlencode($key);
        $tid = 0;
        foreach ([['thetvdb', 'tvdb_id'], ['imdb', 'imdb_id']] as $p) {
            if (empty($ext[$p[0]])) continue;
            $f = self::json($tmdb . '/find/' . rawurlencode((string)$ext[$p[0]]) . '?external_source=' . $p[1] . '&' . $q);
            if (!empty($f['tv_results'][0]['id'])) { $tid = (int)$f['tv_results'][0]['id']; break; }
        }
        if ($tid > 0) {
            $groups = self::json($tmdb . '/tv/' . $tid . '/episode_groups?' . $q);
            $gid = '';
            foreach ($groups['results'] ?? [] as $g) if ((int)($g['type'] ?? 0) === 2 && !empty($g['id'])) { $gid = (string)$g['id']; break; }
            if ($gid !== '') {
                $eg = self::json($tmdb . '/tv/episode_group/' . rawurlencode($gid) . '?' . $q);
                $gs = $eg['groups'] ?? [];
                usort($gs, function ($a, $b) { return ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0)); });
                $abs = 0;
                foreach ($gs as $g) {
                    $eps = $g['episodes'] ?? [];
                    usort($eps, function ($a, $b) { return ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0)); });
                    foreach ($eps as $e) {
                        if ((int)($e['season_number'] ?? 0) <= 0) continue; // specials never count
                        $order[] = ['absolute' => ++$abs, 'season' => (int)$e['season_number'], 'number' => (int)($e['episode_number'] ?? 0), 'name' => $e['name'] ?? null];
                    }
                }
            }
        }
        @file_put_contents($cache, json_encode(['order' => $order, 'at' => date('c')]), LOCK_EX);
        return $order ?: null;
    }

    /* ------------------------------------------------------------ storage */

    public static function doc(): array {
        $d = Store::get(self::STORE, null);
        if (!is_array($d)) $d = [];
        if (!isset($d['modes']) || !is_array($d['modes'])) $d['modes'] = [];
        if (!isset($d['prev']) || !is_array($d['prev'])) $d['prev'] = [];
        return $d;
    }

    public static function storedMode(string $showKey): string {
        $m = self::doc()['modes'][$showKey] ?? 'auto';
        return in_array($m, self::MODES, true) ? $m : 'auto';
    }

    /* ------------------------------------------------------- preview/apply */

    private static function context(string $showKey, array $opts): ?array {
        $items = Matching::showItems($showKey);
        if (!$items) return null;
        $showName = (string)($items[0]['show'] ?? '');
        $guide = array_key_exists('_guide', $opts) ? $opts['_guide'] : Matching::guideForShow($showKey, $showName, !empty($opts['refresh']));
        if (is_array($guide) && !empty($guide['id']) && empty($guide['absolute_order']) && !array_key_exists('_guide', $opts)) {
            $ord = self::tmdbAbsoluteOrder((int)$guide['id']);
            if ($ord) { $guide['absolute_order'] = $ord; $guide['absolute_source'] = 'TMDB absolute order'; }
        }
        $bases = [];
        foreach ($items as $it) $bases[] = (string)preg_replace('/\.[^.]+$/', '', basename(str_replace('\\', '/', (string)($it['rel'] ?? ''))));
        $titles = self::showTitles($showKey, $showName, is_array($guide) ? $guide : null);
        $det = self::detect($bases, (string)($items[0]['type'] ?? ''), is_array($guide['genres'] ?? null) ? $guide['genres'] : [], $titles);
        return ['titles' => $titles, 'prefix' => self::commonPrefix($bases), 'items' => $items, 'show' => $showName, 'guide' => $guide, 'bases' => $bases, 'detect' => $det, 'table' => self::table(is_array($guide) ? $guide : null)];
    }

    /** Known names for a show: library name, its folder name, the guide title and the Fix Match pin name / aliases. */
    public static function showTitles(string $showKey, string $showName, ?array $guide): array {
        $t = [$showName];
        if (is_array($guide)) {
            if (!empty($guide['name'])) $t[] = (string)$guide['name'];
            foreach ((array)($guide['aliases'] ?? []) as $a) $t[] = is_array($a) ? (string)($a['name'] ?? '') : (string)$a;
        }
        if (class_exists('Matching')) {
            $pin = Matching::showPin($showKey);
            if (is_array($pin) && !empty($pin['name'])) $t[] = (string)$pin['name'];
        }
        $out = [];
        foreach ($t as $x) { $x = trim($x); if ($x !== '' && !in_array($x, $out, true)) $out[] = $x; }
        return $out;
    }

    /** Effective mode for a show (stored mode, or detection when auto). */
    public static function effectiveMode(string $showKey, array $opts = []): string {
        $m = isset($opts['mode']) && in_array($opts['mode'], self::MODES, true) ? $opts['mode'] : self::storedMode($showKey);
        if ($m !== 'auto') return $m;
        $ctx = self::context($showKey, $opts);
        return $ctx ? $ctx['detect']['mode'] : 'season';
    }

    /**
     * Preview: each file with its detected absolute number, the mapped SxxEyy and title.
     * opts: mode?, numbers? {itemId: int|null} per-file overrides, refresh?
     */
    public static function preview(string $showKey, array $opts = []): array {
        $ctx = self::context($showKey, $opts);
        if (!$ctx) return ['ok' => false, 'error' => 'Show not found'];
        $stored = self::storedMode($showKey);
        $mode = isset($opts['mode']) && in_array($opts['mode'], self::MODES, true) ? $opts['mode'] : $stored;
        $effective = $mode === 'auto' ? $ctx['detect']['mode'] : $mode;
        $numbers = isset($opts['numbers']) && is_array($opts['numbers']) ? $opts['numbers'] : [];
        $table = $ctx['table'];
        $hasMap = !empty($table['map']);
        $rows = [];
        $seen = [];
        foreach ($ctx['items'] as $i => $it) {
            $id = (string)$it['id'];
            $p = self::parse($ctx['bases'][$i], $ctx['titles'], $ctx['prefix']);
            $detected = $p ? $p['number'] : null;
            $n = array_key_exists($id, $numbers) ? (($numbers[$id] === null || $numbers[$id] === '') ? null : (int)$numbers[$id]) : $detected;
            $cur = ['season' => $it['season'] ?? null, 'episode' => $it['episode'] ?? null, 'episode_title' => $it['episode_title'] ?? null];
            $cur['absolute'] = self::toAbsolute($table, $cur['season'], $cur['episode']);
            $row = ['id' => $id, 'file' => basename(str_replace('\\', '/', (string)($it['rel'] ?? ''))), 'rel' => (string)($it['rel'] ?? ''),
                'detected' => $detected, 'method' => $p ? $p['method'] : null, 'absolute' => $n,
                'season' => null, 'episode' => null, 'episode_title' => null, 'tvmaze_episode_id' => null, 'current' => $cur, 'note' => '', 'changed' => false];
            if ($n !== null && $n > 0) {
                $g = $hasMap ? self::toSeasonEpisode($table, $n) : null;
                if ($g) {
                    $row['season'] = $g['season']; $row['episode'] = $g['number']; $row['episode_title'] = $g['name']; $row['tvmaze_episode_id'] = $g['id'];
                } elseif (!$hasMap) {
                    $row['season'] = 1; $row['episode'] = $n; $row['note'] = 'no episode guide: numbered as season 1';
                } else {
                    $row['note'] = 'number ' . $n . ' is beyond the episode guide (' . count($table['map']) . ' episodes)';
                }
                if (isset($seen[$n])) { $row['note'] = 'same number as ' . $seen[$n]; $row['season'] = null; $row['episode'] = null; }
                else $seen[$n] = $row['file'];
            } elseif ($n === null) {
                $row['note'] = 'no absolute number found';
            }
            $row['changed'] = $row['season'] !== null && ((string)$cur['season'] !== (string)$row['season'] || (string)$cur['episode'] !== (string)$row['episode']
                || ($row['episode_title'] !== null && (string)$cur['episode_title'] !== (string)$row['episode_title']));
            $rows[] = $row;
        }
        $compact = [];
        foreach ($table['map'] as $abs => $e) $compact[$abs] = [$e['season'], $e['number'], $e['name']];
        return ['ok' => true, 'show_key' => $showKey, 'show' => $ctx['show'], 'mode' => $mode, 'stored_mode' => $stored, 'effective' => $effective,
            'detected' => $ctx['detect'], 'source' => $table['source'], 'map' => (object)$compact, 'rows' => $rows];
    }

    /**
     * Save the mode and, for absolute, write the mapped SxxEyy (selected/overridden rows or all).
     * Switching to season/episode restores what each file had before the absolute pass.
     * body: mode, rows? [{id, absolute}] (only these files; absolute overrides detection)
     */
    public static function apply(string $showKey, array $b): array {
        $mode = (string)($b['mode'] ?? 'auto');
        if (!in_array($mode, self::MODES, true)) return ['ok' => false, 'error' => 'Unknown numbering mode'];
        $doc = self::doc();
        $doc['modes'][$showKey] = $mode;
        Store::set(self::STORE, $doc);
        $opts = ['mode' => $mode];
        if (array_key_exists('_guide', $b)) $opts['_guide'] = $b['_guide'];
        $only = null;
        if (isset($b['rows']) && is_array($b['rows'])) {
            $only = []; $opts['numbers'] = [];
            foreach ($b['rows'] as $r) {
                if (!is_array($r) || empty($r['id'])) continue;
                $only[(string)$r['id']] = true;
                if (array_key_exists('absolute', $r)) $opts['numbers'][(string)$r['id']] = $r['absolute'];
            }
        }
        $pr = self::preview($showKey, $opts);
        if (empty($pr['ok'])) return $pr;
        if ($pr['effective'] !== 'absolute') return self::restore($showKey, $pr, $mode);
        $o = Matching::overrides();
        $save = [];
        foreach ($pr['rows'] as $r) {
            if ($only !== null && !isset($only[$r['id']])) continue;
            if (empty($r['changed'])) continue;
            if (!isset($doc['prev'][$r['id']])) {
                $prevOv = $o['items'][$r['id']] ?? null;
                $doc['prev'][$r['id']] = ['override' => is_array($prevOv) ? $prevOv : null, 'season' => $r['current']['season'], 'episode' => $r['current']['episode'], 'episode_title' => $r['current']['episode_title']];
            }
            $save[] = ['id' => $r['id'], 'season' => $r['season'], 'episode' => $r['episode'], 'episode_title' => $r['episode_title'], 'tvmaze_episode_id' => $r['tvmaze_episode_id']];
        }
        Store::set(self::STORE, $doc);
        $res = $save ? Matching::saveItemFields($save, 'absolute', Matching::actor(), false) : ['ok' => true, 'changed' => 0, 'skipped' => 0, 'ids' => []];
        $res['mode'] = $mode;
        $res['effective'] = 'absolute';
        Matching::log('NUMBERING absolute ' . $pr['show'] . ' changed=' . $res['changed']);
        return $res;
    }

    /** Undo the absolute pass for this show's files (back to their earlier labels/overrides). */
    private static function restore(string $showKey, array $pr, string $mode): array {
        $doc = self::doc();
        $o = Matching::overrides();
        $cat = Catalogue::get();
        $changed = 0;
        foreach ($pr['rows'] as $r) {
            $id = $r['id'];
            if (!isset($doc['prev'][$id])) continue;
            $p = $doc['prev'][$id];
            $isAbs = (($o['items'][$id]['source'] ?? '') === 'absolute');
            if ($isAbs) {
                if (is_array($p['override'])) $o['items'][$id] = $p['override']; else unset($o['items'][$id]);
                if (isset($cat['items'][$id]) && is_array($cat['items'][$id])) {
                    $it = $cat['items'][$id];
                    $it['season'] = $p['season']; $it['episode'] = $p['episode']; $it['episode_title'] = $p['episode_title'];
                    $it['title'] = Catalogue::formatEpisodeDisplayTitle($it['show'] ?? null, $it['season'], $it['episode'], $it['episode_title']);
                    if (is_array($p['override'])) $it['match_source'] = (string)($p['override']['source'] ?? 'manual'); else unset($it['match_source']);
                    $cat['items'][$id] = $it;
                }
                $changed++;
            }
            unset($doc['prev'][$id]);
        }
        Store::set(self::STORE, $doc);
        if ($changed) {
            Matching::saveOverrides($o);
            $cat['updated_at'] = date('c');
            Catalogue::save($cat);
            Store::invalidate('catalogue');
        }
        return ['ok' => true, 'changed' => $changed, 'skipped' => 0, 'mode' => $mode, 'effective' => 'season', 'restored' => $changed];
    }
}
