<?php
declare(strict_types=1);

/**
 * Media requests: people ask for films and shows the library doesn't have, managers approve or decline,
 * and approved requests are handed to Acquisition (feed search + Transmission) when it is set up.
 *
 * One row per thing that can be fetched: a film, a whole show, or one season of a show. Asking for
 * something that is already requested joins the existing row, so duplicates merge ("also requested by N").
 * Status: pending -> approved -> downloading -> available, or declined (with an optional reason).
 * Availability is detected from the browse index whenever the catalogue is saved (scan, import, ingest).
 *
 * Parental controls: restricted profiles only see search results with a known age rating inside their
 * ceiling (provider titles are not vetted like the library, so unrated results are hidden), the server
 * re-checks the rating when they ask, and their requests always wait for a grown-up's approval.
 */
final class MediaRequests
{
    const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'downloading' => 'Downloading', 'available' => 'Available', 'declined' => 'Declined'];
    const OPEN = ['pending', 'approved', 'downloading'];
    const DEFAULTS = ['enabled' => true, 'limit_count' => 10, 'limit_days' => 7, 'auto_roles' => [], 'auto_users' => [], 'auto_direct' => true, 'acquire' => true, 'user_limits' => [], 'user_role' => true];
    const POSTER_HOSTS = '/^(?:image\.tmdb\.org|static\.tvmaze\.com|[a-z0-9-]+\.mzstatic\.com|upload\.wikimedia\.org|m\.media-amazon\.com|ia\.media-imdb\.com)$/iD';
    const SOURCES = ['tmdb' => 'TMDB', 'tvmaze' => 'TVmaze', 'omdb' => 'OMDb', 'itunes' => 'iTunes', 'wikipedia' => 'Wikipedia'];
    private static $config = null;
    private static $index = null;

    private static function q(string $sql, array $args = []): PDOStatement { $s = \Vaultcast\Database::connection()->prepare($sql); $s->execute($args); return $s; }
    private static function in(array $values): string { return implode(',', array_fill(0, max(1, count($values)), '?')); }

    // ------------------------------------------------------------------ settings and access
    public static function config(): array
    {
        if (self::$config !== null) return self::$config;
        $raw = [];
        try { $raw = \Vaultcast\Repository::document('media_requests_config', []); } catch (Throwable $e) { $raw = []; }
        return self::$config = self::cleanConfig(is_array($raw) ? $raw : []);
    }

    private static function cleanIds($v): array
    {
        $out = [];
        foreach (is_array($v) ? array_slice($v, 0, 500) : [] as $s) {
            $s = (string)$s;
            if ($s !== '' && strlen($s) <= 64 && preg_match('/^[A-Za-z0-9_.:-]+$/D', $s)) $out[$s] = true;
        }
        return array_keys($out);
    }

    public static function cleanConfig(array $in): array
    {
        $out = self::DEFAULTS;
        foreach (['enabled', 'auto_direct', 'acquire', 'user_role'] as $k) if (array_key_exists($k, $in)) $out[$k] = (bool)$in[$k];
        if (isset($in['limit_count'])) $out['limit_count'] = max(0, min(500, (int)$in['limit_count']));
        if (isset($in['limit_days'])) $out['limit_days'] = max(1, min(90, (int)$in['limit_days']));
        $out['auto_roles'] = self::cleanIds($in['auto_roles'] ?? []);
        $out['auto_users'] = self::cleanIds($in['auto_users'] ?? []);
        $limits = [];
        foreach ((array)($in['user_limits'] ?? []) as $uid => $n) {
            $uid = (string)$uid;
            if (count($limits) >= 500) break;
            if ($uid === '' || strlen($uid) > 64 || !preg_match('/^[A-Za-z0-9_.:-]+$/D', $uid) || !is_numeric($n)) continue;
            $limits[$uid] = max(0, min(500, (int)$n));
        }
        $out['user_limits'] = $limits;
        return $out;
    }

    public static function saveConfig(array $body): array
    {
        $cfg = self::cleanConfig(array_merge(self::config(), $body));
        \Vaultcast\Repository::putDocument('media_requests_config', $cfg);
        self::$config = $cfg;
        return $cfg;
    }

    /** Default for the built-in User role (Permissions::userRoleDefaults). */
    public static function userRoleDefault(): bool { return self::config()['user_role']; }

    public static function canCreate(?array $user): bool
    {
        if (!$user || empty($user['id']) || in_array($user['role'] ?? '', ['guest', ''], true)) return false;
        if (!self::config()['enabled'] || !AreaAccess::allowed($user, 'library')) return false;
        return Permissions::allowed($user, 'requests.create');
    }

    public static function canManage(?array $user): bool
    {
        if (!$user || empty($user['id'])) return false;
        if (($user['role'] ?? '') === 'admin') return true;
        // A restricted profile must never see every title people asked for.
        if (Parental::restricted($user)) return false;
        return Permissions::allowed($user, 'requests.manage') && AreaAccess::allowed($user, 'library');
    }

    /** Flags for /me so the client only offers what works. */
    public static function userStatus(array $user): array
    {
        try { return ['enabled' => self::config()['enabled'], 'create' => self::canCreate($user), 'manage' => self::canManage($user)]; }
        catch (Throwable $e) { return ['enabled' => false, 'create' => false, 'manage' => false]; }
    }

    public static function ratingsAvailable(): bool
    {
        return CollectionDiscovery::tmdbKey() !== '' || PublicMetadata::omdbKey() !== '';
    }

    public static function acquisitionReady(): array
    {
        $s = Store::settings();
        if (empty($s['features']['downloads'])) return ['ok' => false, 'reason' => 'Download integrations are turned off in Settings → Features.'];
        $acq = is_array($s['acquisition'] ?? null) ? $s['acquisition'] : [];
        if (empty($acq['enabled']) && empty($acq['transmission']['enabled'])) return ['ok' => false, 'reason' => 'Acquisition isn’t set up yet (Settings → Acquisition).'];
        return ['ok' => true, 'reason' => ''];
    }

    private static function name(?array $u): string
    {
        if (!$u) return 'Someone';
        $n = trim((string)($u['display_name'] ?? ''));
        return $n !== '' ? $n : (trim((string)($u['username'] ?? '')) ?: 'Someone');
    }

    private static function limitFor(array $user): int
    {
        $cfg = self::config();
        $uid = (string)$user['id'];
        return array_key_exists($uid, $cfg['user_limits']) ? (int)$cfg['user_limits'][$uid] : (int)$cfg['limit_count'];
    }

    /** Titles this person asked for inside the limit window (a show with three seasons counts once). */
    private static function usage(array $user): array
    {
        $cfg = self::config();
        $since = time() - $cfg['limit_days'] * 86400;
        $rows = self::q('SELECT r.title_key, MIN(u.created_at) AS at FROM vaultcast_media_request_users u JOIN vaultcast_media_requests r ON r.id=u.request_id WHERE u.user_id=? AND u.created_at>=? GROUP BY r.title_key', [(string)$user['id'], $since])->fetchAll(PDO::FETCH_ASSOC);
        $keys = []; $oldest = 0;
        foreach ($rows as $r) { $keys[(string)$r['title_key']] = true; $at = (int)$r['at']; if ($oldest === 0 || $at < $oldest) $oldest = $at; }
        return ['keys' => $keys, 'used' => count($keys), 'resets_at' => $oldest ? $oldest + $cfg['limit_days'] * 86400 : 0];
    }

    public static function status(array $user): array
    {
        $cfg = self::config();
        $out = ['ok' => true, 'enabled' => $cfg['enabled'], 'create' => self::canCreate($user), 'manage' => self::canManage($user), 'admin' => ($user['role'] ?? '') === 'admin',
            'restricted' => Parental::restricted($user), 'limit' => null, 'acquisition' => self::acquisitionReady(),
            'providers' => ['movie' => CollectionDiscovery::tmdbKey() !== '' ? 'TMDB' : (PublicMetadata::omdbKey() !== '' ? 'iTunes, Wikipedia and OMDb' : 'iTunes and Wikipedia'), 'tv' => 'TVmaze'], 'pending' => 0];
        $out['ratings_needed'] = $out['restricted'] && !self::ratingsAvailable();
        try {
            if ($out['create'] && !$out['manage']) {
                $limit = self::limitFor($user);
                if ($limit > 0) { $u = self::usage($user); $out['limit'] = ['count' => $limit, 'days' => $cfg['limit_days'], 'used' => $u['used'], 'resets_at' => $u['resets_at']]; }
            }
            if ($out['manage']) $out['pending'] = (int)self::q("SELECT COUNT(*) FROM vaultcast_media_requests WHERE status='pending'")->fetchColumn();
        } catch (Throwable $e) { /* not migrated yet */ }
        return $out;
    }

    // ------------------------------------------------------------------ providers
    private static function http(string $url, array $headers = [], int $ttl = 21600): ?array
    {
        $key = 'mreq-http:' . hash('sha256', $url);
        $hit = \Vaultcast\Repository::cacheGet($key);
        if ($hit !== null) return is_array($hit['v'] ?? null) ? $hit['v'] : null;
        $raw = false; $status = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8, CURLOPT_USERAGENT => 'Vaultcast/' . STREAM_VERSION, CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers)]);
            $raw = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true, 'header' => 'User-Agent: Vaultcast/' . STREAM_VERSION . "\r\nAccept: application/json\r\n" . ($headers ? implode("\r\n", $headers) . "\r\n" : '')]]);
            $raw = @file_get_contents($url, false, $ctx);
            if (isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$http_response_header[0], $m)) $status = (int)$m[1];
        }
        if (!is_string($raw) || $status < 200 || $status >= 300) {
            if ($status === 404) \Vaultcast\Repository::cachePut($key, ['v' => null], 3600);
            return null;
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) return null;
        \Vaultcast\Repository::cachePut($key, ['v' => $j], $ttl);
        return $j;
    }

    private static function tmdb(string $path, array $q = []): ?array
    {
        $key = CollectionDiscovery::tmdbKey();
        if ($key === '') return null;
        $headers = [];
        if (strlen($key) > 60 && substr_count($key, '.') >= 2) $headers[] = 'Authorization: Bearer ' . $key; else $q['api_key'] = $key;
        return self::http('https://api.themoviedb.org/3' . $path . ($q ? '?' . http_build_query($q) : ''), $headers);
    }

    private static function omdb(array $q): ?array
    {
        $key = PublicMetadata::omdbKey();
        if ($key === '') return null;
        $j = self::http('https://www.omdbapi.com/?' . http_build_query(['apikey' => $key] + $q));
        return is_array($j) && strcasecmp((string)($j['Response'] ?? 'True'), 'False') !== 0 ? $j : null;
    }

    private static function poster($url): string
    {
        $url = trim((string)$url);
        if ($url === '' || strlen($url) > 600 || !preg_match('#^https?://#i', $url)) return '';
        $host = (string)parse_url($url, PHP_URL_HOST);
        if (!preg_match(self::POSTER_HOSTS, $host)) return '';
        return (string)preg_replace('#^http://#i', 'https://', $url);
    }

    private static function plain($s, int $max): string
    {
        $s = html_entity_decode(strip_tags((string)$s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = trim((string)preg_replace('/\s+/u', ' ', $s));
        return mb_substr($s, 0, $max);
    }

    private static function row(string $type, string $source, string $id, $title, $year, $overview, $poster, array $extra = []): ?array
    {
        $title = self::plain($title, 250);
        if ($title === '' || $id === '' || !preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $id)) return null;
        $y = (int)$year;
        return ['media_type' => $type, 'source' => $source, 'ext_id' => $id, 'title' => $title, 'year' => $y >= 1870 && $y <= 2200 ? $y : 0,
            'overview' => self::plain($overview, 1200), 'poster' => self::poster($poster)] + $extra;
    }

    private static function searchMovies(string $query): array
    {
        $rows = [];
        if (CollectionDiscovery::tmdbKey() !== '') {
            $title = $query; $year = null;
            if (preg_match('/^(.*?)\s+\(?((?:19|20)\d{2})\)?$/u', $query, $m) && trim($m[1]) !== '') { $title = trim($m[1]); $year = (int)$m[2]; }
            $j = self::tmdb('/search/movie', ['query' => $title, 'include_adult' => 'false'] + ($year ? ['year' => $year] : []));
            foreach (array_slice((array)($j['results'] ?? []), 0, 16) as $r) {
                if (!is_array($r) || !empty($r['adult'])) continue;
                $row = self::row('movie', 'tmdb', (string)(int)($r['id'] ?? 0), $r['title'] ?? '', substr((string)($r['release_date'] ?? ''), 0, 4), $r['overview'] ?? '', !empty($r['poster_path']) ? 'https://image.tmdb.org/t/p/w342' . $r['poster_path'] : '');
                if ($row && $row['ext_id'] !== '0') $rows[] = $row;
            }
            // TMDB answered: use it alone so ids stay consistent. Fall back only when it failed.
            if (is_array($j)) return $rows;
        }
        $res = PublicMetadata::search('movie', $query);
        foreach (array_slice((array)($res['results'] ?? []), 0, 16) as $r) {
            if (!is_array($r) || !isset(self::SOURCES[(string)($r['source'] ?? '')])) continue;
            $extra = ($r['source'] ?? '') === 'omdb' ? ['imdb_id' => (string)$r['id']] : [];
            $row = self::row('movie', (string)$r['source'], (string)($r['id'] ?? ''), $r['title'] ?? '', $r['year'] ?? 0, $r['summary'] ?? '', $r['image'] ?? '', $extra);
            if ($row) $rows[] = $row;
        }
        return $rows;
    }

    private static function searchShows(string $query): array
    {
        $rows = [];
        foreach (array_slice(Matching::tvmazeSearch($query), 0, 16) as $r) {
            $row = self::row('tv', 'tvmaze', (string)(int)($r['id'] ?? 0), $r['title'] ?? '', $r['year'] ?? 0, $r['summary'] ?? '', $r['image'] ?? '',
                ['network' => self::plain($r['network'] ?? '', 80), 'show_status' => self::plain($r['status'] ?? '', 40)]);
            if ($row && $row['ext_id'] !== '0') $rows[] = $row;
        }
        return $rows;
    }

    private static function pickKey(string $type, string $source, string $id): string { return 'mreq-pick:' . hash('sha256', $type . '|' . $source . '|' . $id); }

    private static function remember(array $row): void { \Vaultcast\Repository::cachePut(self::pickKey($row['media_type'], $row['source'], $row['ext_id']), $row, 86400); }

    /** A search result the server itself fetched (the client only names it); refetched by id when the cache is gone. */
    private static function pick(string $type, string $source, string $id): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $id) || !isset(self::SOURCES[$source])) return null;
        $hit = \Vaultcast\Repository::cacheGet(self::pickKey($type, $source, $id));
        if ($hit !== null && ($hit['media_type'] ?? '') === $type) return $hit;
        $row = null;
        if ($type === 'tv' && $source === 'tvmaze' && ctype_digit($id)) {
            $j = self::http('https://api.tvmaze.com/shows/' . (int)$id);
            if ($j) $row = self::row('tv', 'tvmaze', (string)(int)$id, $j['name'] ?? '', substr((string)($j['premiered'] ?? ''), 0, 4), $j['summary'] ?? '', $j['image']['medium'] ?? ($j['image']['original'] ?? ''));
        } elseif ($type === 'movie' && $source === 'tmdb' && ctype_digit($id)) {
            $j = self::tmdb('/movie/' . (int)$id);
            if ($j && empty($j['adult'])) $row = self::row('movie', 'tmdb', (string)(int)$id, $j['title'] ?? '', substr((string)($j['release_date'] ?? ''), 0, 4), $j['overview'] ?? '', !empty($j['poster_path']) ? 'https://image.tmdb.org/t/p/w342' . $j['poster_path'] : '', ['imdb_id' => (string)($j['imdb_id'] ?? '')]);
        } elseif ($type === 'movie' && $source === 'omdb' && preg_match('/^tt\d{5,10}$/D', $id)) {
            $j = self::omdb(['i' => $id]);
            if ($j) $row = self::row('movie', 'omdb', $id, $j['Title'] ?? '', substr((string)($j['Year'] ?? ''), 0, 4), strcasecmp((string)($j['Plot'] ?? ''), 'N/A') ? ($j['Plot'] ?? '') : '', strcasecmp((string)($j['Poster'] ?? ''), 'N/A') ? ($j['Poster'] ?? '') : '', ['imdb_id' => $id]);
        }
        if ($row) self::remember($row);
        return $row;
    }

    private static function certification(array $results, bool $tv): string
    {
        $best = ['GB' => '', 'US' => '', 'any' => ''];
        foreach ($results as $row) {
            if (!is_array($row)) continue;
            $country = (string)($row['iso_3166_1'] ?? '');
            $cert = $tv ? trim((string)($row['rating'] ?? '')) : '';
            if (!$tv) foreach ((array)($row['release_dates'] ?? []) as $d) if (is_array($d) && trim((string)($d['certification'] ?? '')) !== '') { $cert = trim((string)$d['certification']); break; }
            if ($cert === '') continue;
            if (isset($best[$country]) && $best[$country] === '') $best[$country] = $cert;
            if ($best['any'] === '') $best['any'] = $cert;
        }
        return $best['GB'] ?: ($best['US'] ?: $best['any']);
    }

    /** Age rating for a search result: ['bucket' => 0..5, 'raw' => '...'], 0 when no provider knows it. */
    public static function rating(array $row): array
    {
        $key = 'mreq-rating:' . hash('sha256', $row['media_type'] . '|' . $row['source'] . '|' . $row['ext_id']);
        $hit = \Vaultcast\Repository::cacheGet($key);
        if ($hit !== null) return ['bucket' => (int)($hit['bucket'] ?? 0), 'raw' => (string)($hit['raw'] ?? '')];
        $raw = '';
        $tv = $row['media_type'] === 'tv';
        $imdb = preg_match('/^tt\d{5,10}$/D', (string)($row['imdb_id'] ?? '')) ? (string)$row['imdb_id'] : '';
        $tmdbId = $row['source'] === 'tmdb' ? (int)$row['ext_id'] : 0;
        if ($tv && $row['source'] === 'tvmaze') {
            $show = self::http('https://api.tvmaze.com/shows/' . (int)$row['ext_id']) ?: [];
            $imdb = preg_match('/^tt\d{5,10}$/D', (string)($show['externals']['imdb'] ?? '')) ? (string)$show['externals']['imdb'] : '';
            $tvdb = (int)($show['externals']['thetvdb'] ?? 0);
            if (CollectionDiscovery::tmdbKey() !== '' && ($imdb !== '' || $tvdb > 0)) {
                $find = $imdb !== '' ? self::tmdb('/find/' . rawurlencode($imdb), ['external_source' => 'imdb_id']) : self::tmdb('/find/' . $tvdb, ['external_source' => 'tvdb_id']);
                $tmdbId = (int)($find['tv_results'][0]['id'] ?? 0);
            }
        }
        if ($tmdbId > 0) {
            $j = self::tmdb('/' . ($tv ? 'tv/' : 'movie/') . $tmdbId . ($tv ? '/content_ratings' : '/release_dates'));
            $raw = self::certification((array)($j['results'] ?? []), $tv);
        }
        if ($raw === '' && !$tv && $tmdbId === 0 && CollectionDiscovery::tmdbKey() !== '') {
            // iTunes / Wikipedia results: find the same film on TMDB by title and year.
            $f = self::tmdb('/search/movie', ['query' => $row['title'], 'include_adult' => 'false'] + ($row['year'] ? ['year' => $row['year']] : []));
            foreach (array_slice((array)($f['results'] ?? []), 0, 3) as $r) {
                if (!is_array($r) || !empty($r['adult']) || Matching::titleScore($row['title'], (string)($r['title'] ?? '')) < 86) continue;
                $j = self::tmdb('/movie/' . (int)$r['id'] . '/release_dates');
                $raw = self::certification((array)($j['results'] ?? []), false);
                break;
            }
        }
        if ($raw === '' && PublicMetadata::omdbKey() !== '') {
            $j = $imdb !== '' ? self::omdb(['i' => $imdb]) : self::omdb(['t' => $row['title'], 'type' => $tv ? 'series' : 'movie'] + ($row['year'] ? ['y' => $row['year']] : []));
            $r = trim((string)($j['Rated'] ?? ''));
            if ($r !== '' && strcasecmp($r, 'N/A') !== 0) $raw = $r;
        }
        $raw = mb_substr($raw, 0, 40);
        $out = ['bucket' => ContentRatings::normalise($raw), 'raw' => $raw];
        \Vaultcast\Repository::cachePut($key, $out, $out['bucket'] > 0 ? 7 * 86400 : 86400);
        return $out;
    }

    /** Restricted profiles: only titles with a known rating inside the ceiling. */
    private static function allowedFor(array $user, array $row): bool
    {
        if (!Parental::restricted($user)) return true;
        $r = self::rating($row);
        return $r['bucket'] > 0 && Parental::allowsBucket(Parental::settingsFor($user), $r['bucket']);
    }

    // ------------------------------------------------------------------ library
    public static function norm(string $s): string
    {
        $s = mb_strtolower(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'UTF-8');
        $s = (string)preg_replace('/\s*[\(\[]((?:19|20)\d{2})[\)\]]\s*$/u', '', $s);
        $s = str_replace('&', ' and ', $s);
        $s = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        $s = trim((string)preg_replace('/\s+/u', ' ', $s));
        return (string)preg_replace('/^the /u', '', $s);
    }

    /** Films and shows in the library, keyed by normalised title (from the browse index; rebuilt per catalogue revision). */
    private static function libraryIndex(): array
    {
        if (self::$index !== null) return self::$index;
        // Straight from the database: the per-request Store revision can predate a save in this same request.
        $rev = (int)\Vaultcast\Repository::revision('catalogue');
        $hit = \Vaultcast\Repository::cacheGet('mreq-library-index');
        if (is_array($hit) && ($hit['rev'] ?? null) === $rev) return self::$index = $hit;
        $movies = []; $shows = [];
        try {
            foreach (self::q("SELECT item_id,title,year,type FROM vaultcast_browse_items WHERE show_key='' AND media_kind='video'") as $r) {
                if (in_array((string)$r['type'], ['tv', 'anime', 'music', 'roms'], true)) continue;
                $n = self::norm((string)$r['title']);
                if ($n !== '' && count($movies[$n] ?? []) < 8) $movies[$n][] = [(string)$r['item_id'], (int)$r['year']];
            }
            foreach (self::q("SELECT show_key, MIN(show_name) AS name, GROUP_CONCAT(DISTINCT season) AS seasons FROM vaultcast_browse_items WHERE show_key<>'' AND type IN ('tv','anime') GROUP BY show_key") as $r) {
                $n = self::norm((string)$r['name']);
                if ($n === '') continue;
                $seasons = array_values(array_unique(array_map('intval', array_filter(explode(',', (string)$r['seasons']), 'strlen'))));
                $shows[$n][] = [(string)$r['show_key'], $seasons];
            }
        } catch (Throwable $e) { /* browse index not built yet */ }
        self::$index = ['rev' => $rev, 'movies' => $movies, 'shows' => $shows];
        \Vaultcast\Repository::cachePut('mreq-library-index', self::$index, 0);
        return self::$index;
    }

    /** Where this title lives in the library, if it does. */
    private static function libraryMatch(string $type, string $title, int $year): ?array
    {
        $idx = self::libraryIndex();
        $n = self::norm($title);
        if ($n === '') return null;
        if ($type === 'movie') {
            $best = null;
            foreach ($idx['movies'][$n] ?? [] as $c) {
                if ($year && $c[1] && abs($c[1] - $year) > 1) continue;
                if ($best === null || ($year && $c[1] === $year)) $best = $c;
            }
            return $best ? ['type' => 'movie', 'item_id' => $best[0]] : null;
        }
        $all = $idx['shows'][$n] ?? [];
        if (!$all) return null;
        $seasons = [];
        foreach ($all as $s) $seasons = array_merge($seasons, $s[1]);
        $seasons = array_values(array_unique($seasons));
        sort($seasons);
        return ['type' => 'show', 'show_key' => $all[0][0], 'seasons' => $seasons];
    }

    /** $season: -1 for a film or the whole show. */
    private static function scopeInLibrary(?array $lib, string $type, int $season): bool
    {
        if (!$lib) return false;
        if ($type === 'movie' || $season < 0) return true;
        return in_array($season, $lib['seasons'] ?? [], true);
    }

    // ------------------------------------------------------------------ search
    public static function search(array $user, string $type, string $query): array
    {
        $type = $type === 'tv' ? 'tv' : 'movie';
        $query = trim(mb_substr((string)preg_replace('/\s+/u', ' ', $query), 0, 120));
        if (mb_strlen($query) < 2) return ['ok' => true, 'results' => [], 'query' => $query, 'media_type' => $type];
        $restricted = Parental::restricted($user);
        if ($restricted && !self::ratingsAvailable()) return ['ok' => true, 'results' => [], 'query' => $query, 'media_type' => $type, 'notice' => 'Asking for new titles isn’t available on this profile yet. Ask a grown-up to request it for you.'];
        $throttle = 'mreq-search:' . hash('sha256', (string)$user['id']);
        $t = \Vaultcast\Repository::cacheGet($throttle) ?: ['n' => 0, 'at' => time()];
        if ((int)($t['at'] ?? 0) < time() - 60) $t = ['n' => 0, 'at' => time()];
        if ((int)$t['n'] >= 30) return ['ok' => false, 'error' => 'That’s a lot of searches. Wait a moment and try again.'];
        $t['n'] = (int)$t['n'] + 1;
        \Vaultcast\Repository::cachePut($throttle, $t, 120);
        $rows = $type === 'movie' ? self::searchMovies($query) : self::searchShows($query);
        if ($restricted) {
            $rows = array_values(array_filter(array_slice($rows, 0, 10), function (array $r) use ($user) { return self::allowedFor($user, $r); }));
        }
        foreach ($rows as $r) self::remember($r);
        return ['ok' => true, 'query' => $query, 'media_type' => $type, 'results' => self::annotate($user, $rows), 'restricted' => $restricted];
    }

    private static function titleKey(string $type, string $source, string $id): string { return $type . ':' . $source . ':' . $id; }
    private static function matchKey(string $type, string $title, int $year): string { return mb_substr($type . ':' . self::norm($title) . ':' . $year, 0, 190); }

    /** In library / already requested state for search rows. */
    private static function annotate(array $user, array $rows): array
    {
        if (!$rows) return [];
        $keys = []; $matches = [];
        foreach ($rows as $r) { $keys[] = self::titleKey($r['media_type'], $r['source'], $r['ext_id']); $matches[] = self::matchKey($r['media_type'], $r['title'], $r['year']); }
        $byKey = []; $byMatch = [];
        try {
            $found = self::q('SELECT id,title_key,match_key,season,status FROM vaultcast_media_requests WHERE title_key IN (' . self::in($keys) . ') OR match_key IN (' . self::in($matches) . ')', array_merge($keys, $matches))->fetchAll(PDO::FETCH_ASSOC);
            $ids = array_map(function ($f) { return (int)$f['id']; }, $found);
            $counts = []; $mine = [];
            if ($ids) {
                foreach (self::q('SELECT request_id, COUNT(*) AS n, SUM(user_id=?) AS mine FROM vaultcast_media_request_users WHERE request_id IN (' . self::in($ids) . ') GROUP BY request_id', array_merge([(string)$user['id']], $ids)) as $c) {
                    $counts[(int)$c['request_id']] = (int)$c['n']; $mine[(int)$c['request_id']] = (int)$c['mine'] > 0;
                }
            }
            foreach ($found as $f) {
                $id = (int)$f['id'];
                $entry = ['id' => $id, 'season' => (int)$f['season'], 'status' => (string)$f['status'], 'status_label' => self::STATUSES[(string)$f['status']] ?? 'Pending', 'count' => $counts[$id] ?? 0, 'mine' => !empty($mine[$id])];
                $byKey[(string)$f['title_key']][$id] = $entry;
                $byMatch[(string)$f['match_key']][$id] = $entry;
            }
        } catch (Throwable $e) { /* not migrated yet */ }
        $out = [];
        foreach ($rows as $i => $r) {
            $lib = self::libraryMatch($r['media_type'], $r['title'], $r['year']);
            $reqs = $byKey[$keys[$i]] ?? [];
            if (!$reqs && $r['media_type'] === 'movie') $reqs = $byMatch[$matches[$i]] ?? [];
            $r['source_label'] = self::SOURCES[$r['source']] ?? $r['source'];
            $r['in_library'] = $lib !== null && ($r['media_type'] === 'movie' || !empty($lib['seasons']));
            $r['library'] = $lib ? self::publicLibrary($lib) : null;
            $r['library_seasons'] = $lib['seasons'] ?? [];
            $r['requests'] = array_values($reqs);
            unset($r['imdb_id']);
            $out[] = $r;
        }
        return $out;
    }

    private static function publicLibrary(array $lib): array
    {
        return $lib['type'] === 'movie' ? ['type' => 'movie', 'item_id' => $lib['item_id']] : ['type' => 'show', 'show_key' => $lib['show_key']];
    }

    /** Seasons of a show (TVmaze) with what the library and other requests already cover. */
    public static function seasons(array $user, string $source, string $id): array
    {
        if ($source !== 'tvmaze' || !ctype_digit($id)) throw new InvalidArgumentException('Choose a show from the search results.');
        $pick = self::pick('tv', 'tvmaze', $id);
        if (!$pick) throw new InvalidArgumentException('Search again to request this show.');
        if (!self::allowedFor($user, $pick)) throw new DomainException('This title isn’t available on this profile.');
        $list = self::http('https://api.tvmaze.com/shows/' . (int)$id . '/seasons') ?: [];
        $lib = self::libraryMatch('tv', $pick['title'], $pick['year']);
        $today = date('Y-m-d');
        $states = [];
        try {
            foreach (self::q('SELECT id,season,status FROM vaultcast_media_requests WHERE title_key=?', [self::titleKey('tv', 'tvmaze', (string)(int)$id)]) as $r) {
                $states[(int)$r['season']] = ['id' => (int)$r['id'], 'status' => (string)$r['status'], 'status_label' => self::STATUSES[(string)$r['status']] ?? 'Pending'];
            }
        } catch (Throwable $e) {}
        $out = [];
        foreach ($list as $s) {
            if (!is_array($s)) continue;
            $n = (int)($s['number'] ?? 0);
            if ($n <= 0 || $n > 200) continue;
            $premiere = preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string)($s['premiereDate'] ?? '')) ? (string)$s['premiereDate'] : '';
            $out[] = ['number' => $n, 'episodes' => (int)($s['episodeOrder'] ?? 0), 'premiere' => $premiere, 'aired' => $premiere !== '' && $premiere <= $today,
                'in_library' => self::scopeInLibrary($lib, 'tv', $n), 'request' => $states[$n] ?? null];
        }
        return ['ok' => true, 'show' => self::annotate($user, [$pick])[0], 'seasons' => $out, 'whole' => $states[-1] ?? null];
    }

    // ------------------------------------------------------------------ creating
    public static function create(array $user, array $b): array
    {
        if (!self::canCreate($user)) throw new DomainException('Your account can’t request titles. Ask an administrator.');
        $type = ($b['media_type'] ?? '') === 'tv' ? 'tv' : 'movie';
        $pick = self::pick($type, (string)($b['source'] ?? ''), (string)($b['ext_id'] ?? ''));
        if (!$pick) throw new InvalidArgumentException('Search again to request this title.');
        $restricted = Parental::restricted($user);
        $rating = ['bucket' => 0, 'raw' => ''];
        if ($restricted) {
            $rating = self::rating($pick);
            if ($rating['bucket'] <= 0 || !Parental::allowsBucket(Parental::settingsFor($user), $rating['bucket'])) throw new DomainException('This title isn’t available on this profile.');
        }
        // -1 = the film, or the whole show; otherwise season numbers.
        $scopes = [-1];
        if ($type === 'tv') {
            $want = $b['seasons'] ?? 'all';
            if (is_array($want) && $want) {
                $scopes = array_values(array_unique(array_filter(array_map('intval', array_slice($want, 0, 60)), function ($n) { return $n > 0 && $n <= 200; })));
                sort($scopes);
                if (!$scopes) throw new InvalidArgumentException('Choose at least one season.');
            }
        }
        $note = mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)($b['note'] ?? ''))), 0, 300);
        $titleKey = self::titleKey($type, $pick['source'], $pick['ext_id']);
        $matchKey = self::matchKey($type, $pick['title'], $pick['year']);
        if (!self::canManage($user)) {
            $limit = self::limitFor($user);
            if ($limit > 0) {
                $u = self::usage($user);
                if ($u['used'] >= $limit && !isset($u['keys'][$titleKey])) {
                    $when = $u['resets_at'] ? ' You can ask again from ' . date('j M', $u['resets_at']) . '.' : '';
                    throw new DomainException('You’ve used all ' . $limit . ' of your requests for the last ' . self::config()['limit_days'] . ' days.' . $when);
                }
            }
        }
        $lib = self::libraryMatch($type, $pick['title'], $pick['year']);
        $uid = (string)$user['id'];
        $now = time();
        $touched = []; $already = []; $pending = [];
        \Vaultcast\Database::transaction(function (PDO $pdo) use ($type, $pick, $scopes, $lib, $uid, $now, $note, $restricted, $rating, $titleKey, $matchKey, &$touched, &$already, &$pending) {
            foreach ($scopes as $season) {
                if (self::scopeInLibrary($lib, $type, $season)) { $already[] = $season; continue; }
                $scope = $type === 'movie' ? '' : ($season < 0 ? 'all' : 's' . $season);
                $ident = $titleKey . ($scope !== '' ? ':' . $scope : '');
                $s = $pdo->prepare('SELECT id,status FROM vaultcast_media_requests WHERE ident=? FOR UPDATE');
                $s->execute([$ident]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                if (!$row && $type === 'movie') {
                    // The same film found through another provider (no TMDB key): merge on title and year.
                    $s = $pdo->prepare("SELECT id,status FROM vaultcast_media_requests WHERE match_key=? AND media_type='movie' ORDER BY id LIMIT 1 FOR UPDATE");
                    $s->execute([$matchKey]);
                    $row = $s->fetch(PDO::FETCH_ASSOC);
                }
                if (!$row && $season > 0) {
                    // A season inside an open whole-show request joins that request.
                    $s = $pdo->prepare("SELECT id,status FROM vaultcast_media_requests WHERE ident=? AND status IN ('pending','approved','downloading') FOR UPDATE");
                    $s->execute([$titleKey . ':all']);
                    $row = $s->fetch(PDO::FETCH_ASSOC);
                }
                if ($row && $row['status'] === 'available') { $already[] = $season; continue; }
                if ($row) {
                    $id = (int)$row['id'];
                    if ($row['status'] === 'declined') {
                        $pdo->prepare("UPDATE vaultcast_media_requests SET status='pending', reason='', decided_by='', decided_at=0, note='', updated_at=? WHERE id=?")->execute([$now, $id]);
                        $pending[$id] = true;
                    } elseif ($row['status'] === 'pending') $pending[$id] = true;
                } else {
                    $pdo->prepare('INSERT INTO vaultcast_media_requests (ident,title_key,match_key,media_type,source,ext_id,scope,season,title,year,overview,poster,imdb_id,rating_bucket,rating_raw,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$ident, $titleKey, $matchKey, $type, $pick['source'], $pick['ext_id'], $scope, $season, $pick['title'], $pick['year'], $pick['overview'], $pick['poster'], mb_substr((string)($pick['imdb_id'] ?? ''), 0, 16), $rating['bucket'], $rating['raw'], 'pending', $now, $now]);
                    $id = (int)$pdo->lastInsertId();
                    $pending[$id] = true;
                }
                $pdo->prepare("INSERT INTO vaultcast_media_request_users (request_id,user_id,note,restricted,created_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE note=IF(VALUES(note)<>'',VALUES(note),note), restricted=GREATEST(restricted,VALUES(restricted))")
                    ->execute([$id, $uid, $note, $restricted ? 1 : 0, $now]);
                if ($restricted && $rating['bucket'] > 0) $pdo->prepare('UPDATE vaultcast_media_requests SET rating_bucket=?, rating_raw=? WHERE id=? AND rating_bucket=0')->execute([$rating['bucket'], $rating['raw'], $id]);
                $touched[$id] = true;
            }
        });
        // Kids profiles and other restricted profiles always wait for a grown-up.
        $auto = !$restricted && self::autoApproves($user);
        $approved = false;
        foreach (array_keys($pending) as $id) {
            if ($auto) { self::approve($user, (int)$id, null); $approved = true; }
            else self::notifyManagers((int)$id);
        }
        $rows = $touched ? self::rowsByIds(array_keys($touched)) : [];
        $label = function (int $s) use ($type): string { return $type === 'movie' ? 'This film' : ($s < 0 ? 'This show' : 'Season ' . $s); };
        return ['ok' => true, 'requests' => array_map(function ($r) use ($user) { return self::publicRow($r, $user, false); }, $rows),
            'already' => array_map($label, $already), 'auto_approved' => $approved, 'status' => self::status($user)];
    }

    private static function autoApproves(array $user): bool
    {
        if (self::canManage($user)) return true;
        $cfg = self::config();
        if (in_array((string)$user['id'], $cfg['auto_users'], true)) return true;
        if (in_array((string)($user['role'] ?? 'user'), $cfg['auto_roles'], true)) return true;
        // People who could already grab downloads directly (downloads_request) skip the queue when the setting is on.
        return $cfg['auto_direct'] && Permissions::allowed($user, 'downloads_request') && self::acquisitionReady()['ok'];
    }

    public static function cancel(array $user, int $id): array
    {
        $uid = (string)$user['id'];
        $gone = false;
        \Vaultcast\Database::transaction(function (PDO $pdo) use ($uid, $id, &$gone) {
            $s = $pdo->prepare('SELECT r.status FROM vaultcast_media_requests r JOIN vaultcast_media_request_users u ON u.request_id=r.id AND u.user_id=? WHERE r.id=? FOR UPDATE');
            $s->execute([$uid, $id]);
            $status = $s->fetchColumn();
            if ($status === false) throw new InvalidArgumentException('That request isn’t on your list any more.');
            if ($status !== 'pending') throw new DomainException('Only pending requests can be cancelled. This one is already ' . strtolower(self::STATUSES[(string)$status] ?? 'handled') . '.');
            $pdo->prepare('DELETE FROM vaultcast_media_request_users WHERE request_id=? AND user_id=?')->execute([$id, $uid]);
            $s = $pdo->prepare('SELECT COUNT(*) FROM vaultcast_media_request_users WHERE request_id=?');
            $s->execute([$id]);
            if ((int)$s->fetchColumn() === 0) { $pdo->prepare('DELETE FROM vaultcast_media_requests WHERE id=?')->execute([$id]); $gone = true; }
        });
        if ($gone) self::resolveManagers($id); else self::notifyManagers($id, false);
        return ['ok' => true, 'status' => self::status($user)];
    }

    // ------------------------------------------------------------------ reading
    private static function rowsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        return self::withRequesters(self::q('SELECT * FROM vaultcast_media_requests WHERE id IN (' . self::in($ids) . ') ORDER BY id', $ids)->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function withRequesters(array $rows): array
    {
        $ids = array_map(function ($r) { return (int)$r['id']; }, $rows);
        $by = [];
        if ($ids) foreach (self::q('SELECT request_id,user_id,note,restricted,created_at FROM vaultcast_media_request_users WHERE request_id IN (' . self::in($ids) . ') ORDER BY created_at, user_id', $ids) as $u) $by[(int)$u['request_id']][] = $u;
        foreach ($rows as &$r) $r['_users'] = $by[(int)$r['id']] ?? [];
        unset($r);
        return $rows;
    }

    private static function scopeLabel(array $r): string
    {
        if ($r['media_type'] === 'movie') return 'Film';
        return (int)$r['season'] > 0 ? 'Season ' . (int)$r['season'] : 'Whole show';
    }

    private static function publicRow(array $r, array $viewer, bool $manage): array
    {
        $uid = (string)$viewer['id'];
        $users = $r['_users'] ?? [];
        $mine = null;
        foreach ($users as $u) if ((string)$u['user_id'] === $uid) $mine = $u;
        $ref = (string)$r['library_ref'];
        $library = null;
        if (preg_match('/^movie:([A-Za-z0-9_-]{1,64})$/D', $ref, $m)) $library = ['type' => 'movie', 'item_id' => $m[1]];
        elseif (preg_match('/^show:([a-f0-9]{16})$/D', $ref, $m)) $library = ['type' => 'show', 'show_key' => $m[1]];
        $status = (string)$r['status'];
        $out = ['id' => (int)$r['id'], 'media_type' => (string)$r['media_type'], 'title' => (string)$r['title'], 'year' => (int)$r['year'], 'overview' => (string)($r['overview'] ?? ''),
            'poster' => (string)$r['poster'], 'scope_label' => self::scopeLabel($r), 'season' => (int)$r['season'], 'status' => $status, 'status_label' => self::STATUSES[$status] ?? 'Pending',
            'reason' => (string)$r['reason'], 'note' => (string)$r['note'], 'progress' => round((float)$r['progress'], 1), 'created_at' => (int)$r['created_at'], 'updated_at' => (int)$r['updated_at'],
            'requested_at' => $mine ? (int)$mine['created_at'] : (int)$r['created_at'], 'requesters' => count($users), 'also' => max(0, count($users) - ($mine ? 1 : 0)), 'mine' => $mine !== null,
            'library' => $library, 'rating' => (int)$r['rating_bucket'] > 0 ? ContentRatings::label((int)$r['rating_bucket']) : '', 'source_label' => self::SOURCES[(string)$r['source']] ?? (string)$r['source'],
            'can_cancel' => $mine !== null && $status === 'pending'];
        if ($manage) {
            $people = [];
            foreach ($users as $u) {
                $p = Auth::findById((string)$u['user_id']);
                $people[] = ['name' => self::name($p), 'note' => (string)$u['note'], 'kid' => (int)$u['restricted'] === 1, 'at' => (int)$u['created_at']];
            }
            $acq = json_decode((string)($r['acq'] ?? ''), true);
            $out['people'] = $people;
            $out['decided_by'] = (string)$r['decided_by'];
            $out['decided_at'] = (int)$r['decided_at'];
            $out['downloads'] = is_array($acq) ? count((array)($acq['entries'] ?? [])) : 0;
        }
        return $out;
    }

    public static function mine(array $user): array
    {
        self::reconcile(false);
        $rows = self::q('SELECT r.* FROM vaultcast_media_requests r JOIN vaultcast_media_request_users u ON u.request_id=r.id WHERE u.user_id=? ORDER BY u.created_at DESC, r.id DESC LIMIT 200', [(string)$user['id']])->fetchAll(PDO::FETCH_ASSOC);
        if (Parental::restricted($user)) {
            // A lowered ceiling hides older requests too.
            $st = Parental::settingsFor($user);
            $rows = array_values(array_filter($rows, function ($r) use ($st) { return (int)$r['rating_bucket'] > 0 && Parental::allowsBucket($st, (int)$r['rating_bucket']); }));
        }
        return ['ok' => true, 'requests' => array_map(function ($r) use ($user) { return self::publicRow($r, $user, false); }, self::withRequesters($rows)), 'status' => self::status($user)];
    }

    public static function manageList(array $user, string $status, string $query, int $page): array
    {
        self::reconcile(false);
        $where = []; $args = [];
        if ($status !== 'all' && isset(self::STATUSES[$status])) { $where[] = 'status=?'; $args[] = $status; }
        $query = trim(mb_substr($query, 0, 80));
        if ($query !== '') { $where[] = 'title LIKE ?'; $args[] = '%' . addcslashes($query, '%_\\') . '%'; }
        $per = 40; $page = max(1, min(500, $page));
        $sql = 'SELECT * FROM vaultcast_media_requests' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ' . ($status === 'pending' ? 'created_at ASC' : 'updated_at DESC') . ', id DESC LIMIT ' . ($per + 1) . ' OFFSET ' . (($page - 1) * $per);
        $rows = self::q($sql, $args)->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > $per;
        $rows = self::withRequesters(array_slice($rows, 0, $per));
        $counts = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach (self::q('SELECT status, COUNT(*) AS n FROM vaultcast_media_requests GROUP BY status') as $c) if (isset($counts[(string)$c['status']])) $counts[(string)$c['status']] = (int)$c['n'];
        return ['ok' => true, 'requests' => array_map(function ($r) use ($user) { return self::publicRow($r, $user, true); }, $rows), 'counts' => $counts, 'page' => $page, 'has_more' => $more,
            'acquisition' => self::acquisitionReady(), 'acquire_default' => self::config()['acquire'], 'admin' => ($user['role'] ?? '') === 'admin'];
    }

    // ------------------------------------------------------------------ managing
    public static function decide(array $actor, array $ids, string $action, string $reason, ?bool $acquire): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', array_slice($ids, 0, 200)), function ($i) { return $i > 0; })));
        if (!$ids) throw new InvalidArgumentException('Choose at least one request.');
        if (!in_array($action, ['approve', 'decline', 'delete', 'retry', 'available', 'reopen'], true)) throw new InvalidArgumentException('Unknown action.');
        $reason = mb_substr(trim((string)preg_replace('/\s+/u', ' ', $reason)), 0, 400);
        $done = 0; $skipped = 0; $notes = [];
        foreach ($ids as $id) {
            $row = self::q('SELECT id,media_type,title,year,status FROM vaultcast_media_requests WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $skipped++; continue; }
            $st = (string)$row['status'];
            if ($action === 'approve' && in_array($st, ['pending', 'declined'], true)) {
                $r = self::approve($actor, $id, $acquire);
                if (!empty($r['note'])) $notes[] = $r['note'];
            } elseif ($action === 'decline' && in_array($st, ['pending', 'approved', 'downloading'], true)) {
                self::setStatus($id, 'declined', ['reason' => $reason, 'decided_by' => self::name($actor), 'decided_at' => time(), 'note' => '']);
                self::resolveManagers($id);
                self::notifyRequesters($id, 'declined');
            } elseif ($action === 'retry' && in_array($st, ['approved', 'downloading'], true)) {
                $r = self::handOff($id);
                if (!empty($r['note'])) $notes[] = $r['note'];
            } elseif ($action === 'available' && $st !== 'available') {
                self::markAvailable($id, self::libraryRef(self::libraryMatch((string)$row['media_type'], (string)$row['title'], (int)$row['year'])));
            } elseif ($action === 'reopen' && in_array($st, ['declined', 'approved'], true)) {
                self::setStatus($id, 'pending', ['reason' => '', 'decided_by' => '', 'decided_at' => 0, 'note' => '']);
                self::notifyManagers($id);
            } elseif ($action === 'delete') {
                self::q('DELETE FROM vaultcast_media_request_users WHERE request_id=?', [$id]);
                self::q('DELETE FROM vaultcast_media_requests WHERE id=?', [$id]);
                self::resolveManagers($id);
            } else { $skipped++; continue; }
            $done++;
        }
        return ['ok' => true, 'done' => $done, 'skipped' => $skipped, 'notes' => array_values(array_unique($notes))];
    }

    private static function setStatus(int $id, string $status, array $fields = []): void
    {
        $sets = ['status=?', 'updated_at=?']; $args = [$status, time()];
        foreach ($fields as $k => $v) {
            if (!in_array($k, ['reason', 'decided_by', 'decided_at', 'note', 'progress', 'acq', 'library_ref', 'available_at'], true)) continue;
            $sets[] = $k . '=?';
            $args[] = $k === 'note' ? mb_substr((string)$v, 0, 250) : $v;
        }
        $args[] = $id;
        self::q('UPDATE vaultcast_media_requests SET ' . implode(',', $sets) . ' WHERE id=?', $args);
    }

    /** Approve, tell the requesters and, when wanted and possible, hand it to Acquisition. */
    private static function approve(array $actor, int $id, ?bool $acquire): array
    {
        self::setStatus($id, 'approved', ['reason' => '', 'decided_by' => self::name($actor), 'decided_at' => time(), 'note' => '', 'acq' => null, 'progress' => 0]);
        self::resolveManagers($id);
        self::notifyRequesters($id, 'approved');
        if (!($acquire === null ? self::config()['acquire'] : $acquire)) { self::setStatus($id, 'approved', ['note' => 'Approved for manual download.']); return ['ok' => true, 'note' => '']; }
        return self::handOff($id);
    }

    /** One Acquisition queue entry: searched now when allowed, otherwise a search intent for the downloads task. */
    private static function searchAndQueue(string $query, array $meta, bool $searchNow, int $season): ?array
    {
        $entry = ['title' => 'SEARCH: ' . $query, 'magnet' => '', 'link' => '', 'enclosure' => '', 'query' => $query, 'show' => $meta['show'] ?? null, 'season' => $meta['season'] ?? null, 'episode' => null, 'kind' => $meta['kind'], 'section_id' => null];
        if ($searchNow && Acquisition::countSearchFeeds() > 0) {
            $search = Acquisition::searchQuery($query);
            $picked = null;
            foreach ((array)($search['items'] ?? []) as $item) {
                if (!is_array($item) || (empty($item['magnet']) && empty($item['enclosure']) && empty($item['link']))) continue;
                if ($season <= 0) { $picked = $item; break; }
                // Prefer a season pack over a single episode.
                $name = (string)($item['title'] ?? '');
                if (!preg_match('/\bS\d{1,2}\s*E\d{1,3}\b/i', $name) && preg_match('/\bS0?' . $season . '(?!\d)|\bSeason\s*0?' . $season . '(?!\d)/i', $name)) { $picked = $item; break; }
                if ($picked === null) $picked = $item;
            }
            if ($picked) {
                $entry['title'] = (string)($picked['title'] ?? '') !== '' ? (string)$picked['title'] : $entry['title'];
                foreach (['magnet', 'link', 'enclosure'] as $k) $entry[$k] = (string)($picked[$k] ?? '');
            }
        }
        $res = Acquisition::enqueue($entry);
        return is_array($res['entry'] ?? null) ? $res['entry'] : null;
    }

    /** Send an approved request to Acquisition, or leave it approved for manual handling. */
    public static function handOff(int $id): array
    {
        $r = self::q('SELECT * FROM vaultcast_media_requests WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['ok' => false, 'note' => ''];
        $ready = self::acquisitionReady();
        if (!$ready['ok']) { self::setStatus($id, 'approved', ['note' => 'Approved for manual download. ' . $ready['reason']]); return ['ok' => true, 'note' => $ready['reason']]; }
        $title = (string)$r['title']; $year = (int)$r['year'];
        $jobs = [];
        if ($r['media_type'] === 'movie') $jobs[] = ['query' => trim($title . ($year ? ' ' . $year : '')), 'meta' => ['kind' => 'movie'], 'season' => -1];
        else {
            $seasons = (int)$r['season'] > 0 ? [(int)$r['season']] : [];
            if (!$seasons && $r['source'] === 'tvmaze') {
                $lib = self::libraryMatch('tv', $title, $year);
                foreach ((array)(self::http('https://api.tvmaze.com/shows/' . (int)$r['ext_id'] . '/seasons') ?: []) as $s) {
                    if (!is_array($s)) continue;
                    $n = (int)($s['number'] ?? 0); $p = (string)($s['premiereDate'] ?? '');
                    if ($n > 0 && $p !== '' && $p <= date('Y-m-d') && !self::scopeInLibrary($lib, 'tv', $n)) $seasons[] = $n;
                }
                $seasons = array_slice($seasons, 0, 40);
            }
            if (!$seasons) { self::setStatus($id, 'approved', ['note' => 'Approved. There are no aired seasons to download yet.']); return ['ok' => true, 'note' => '']; }
            foreach ($seasons as $n) $jobs[] = ['query' => $title . sprintf(' S%02d', $n), 'meta' => ['kind' => 'tv', 'show' => $title, 'season' => $n], 'season' => $n];
        }
        $entries = [];
        // Feed searches are rate limited (Settings → Acquisition): search the first now; the downloads task works through the rest.
        foreach ($jobs as $i => $job) {
            try { $e = self::searchAndQueue($job['query'], $job['meta'], $i === 0, $job['season']); }
            catch (Throwable $ex) { $e = null; error_log('Vaultcast request hand-off: ' . $ex->getMessage()); }
            if ($e && !empty($e['id'])) $entries[] = ['id' => (string)$e['id'], 'season' => $job['season']];
        }
        if (!$entries) { self::setStatus($id, 'approved', ['note' => 'Approved, but it couldn’t be sent to downloads. Try again from Requests.']); return ['ok' => true, 'note' => 'A download couldn’t be queued.']; }
        self::setStatus($id, 'approved', ['acq' => json_encode(['entries' => $entries, 'at' => time()]), 'note' => 'Looking for a download…', 'progress' => 0]);
        self::reconcile(true, false, [$id]);
        return ['ok' => true, 'note' => ''];
    }

    // ------------------------------------------------------------------ availability and progress
    /** Called after the catalogue is saved (scan, import, ingest). Never throws. */
    public static function catalogueChanged(): void
    {
        try {
            if (!(int)self::q("SELECT COUNT(*) FROM vaultcast_media_requests WHERE status IN ('pending','approved','downloading')")->fetchColumn()) return;
            self::$index = null;
            self::reconcile(true, false);
        } catch (Throwable $e) { /* not migrated yet */ }
    }

    private static function torrents(): array
    {
        $hit = \Vaultcast\Repository::cacheGet('mreq-torrents');
        if (is_array($hit) && isset($hit['t'])) return (array)$hit['t'];
        $out = [];
        try {
            $tr = Store::settings()['acquisition']['transmission'] ?? [];
            if (!empty($tr['enabled'])) foreach ((array)(Acquisition::transmissionStatus((array)$tr)['torrents'] ?? []) as $t) if (isset($t['id'])) $out[(string)(int)$t['id']] = (float)($t['percent'] ?? 0);
        } catch (Throwable $e) {}
        \Vaultcast\Repository::cachePut('mreq-torrents', ['t' => $out], 20);
        return $out;
    }

    /** Mark requests Available when they land in the library, and follow their linked downloads. */
    public static function reconcile(bool $force, bool $withTorrents = true, array $only = []): void
    {
        try {
            if (!$force && !$only) {
                $t = \Vaultcast\Repository::cacheGet('mreq-reconcile');
                if (is_array($t) && (int)($t['at'] ?? 0) > time() - 30) return;
                \Vaultcast\Repository::cachePut('mreq-reconcile', ['at' => time()], 120);
            }
            $sql = "SELECT id,media_type,title,year,season,status,acq,progress,note FROM vaultcast_media_requests WHERE status IN ('pending','approved','downloading')";
            $args = [];
            if ($only) { $args = array_values(array_map('intval', $only)); $sql .= ' AND id IN (' . self::in($args) . ')'; }
            $rows = self::q($sql . ' ORDER BY id LIMIT 500', $args)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return; }
        if (!$rows) return;
        $queue = null; $torrents = null;
        foreach ($rows as $r) {
            try {
                $id = (int)$r['id'];
                $lib = self::libraryMatch((string)$r['media_type'], (string)$r['title'], (int)$r['year']);
                $inLibrary = self::scopeInLibrary($lib, (string)$r['media_type'], (int)$r['season']);
                $acq = json_decode((string)($r['acq'] ?? ''), true);
                $entries = is_array($acq) ? (array)($acq['entries'] ?? []) : [];
                if (!$entries) { if ($inLibrary) self::markAvailable($id, self::libraryRef($lib)); continue; }
                if ($queue === null) { $queue = []; foreach ((array)Store::get('acquire_queue', []) as $e) if (is_array($e) && !empty($e['id'])) $queue[(string)$e['id']] = $e; }
                $sent = 0; $waiting = 0; $done = 0; $pct = [];
                foreach ($entries as $link) {
                    $e = is_array($link) ? ($queue[(string)($link['id'] ?? '')] ?? null) : null;
                    $st = $e ? (string)($e['status'] ?? '') : 'gone';
                    if ($st === 'sent_transmission') {
                        $sent++;
                        if ($withTorrents && !empty($e['torrent_id'])) {
                            if ($torrents === null) $torrents = self::torrents();
                            if (isset($torrents[(string)(int)$e['torrent_id']])) $pct[] = $torrents[(string)(int)$e['torrent_id']];
                        }
                    } elseif (in_array($st, ['queued', 'queued_local'], true)) $waiting++;
                    elseif ($st === 'done') $done++;
                }
                if ($inLibrary && $sent === 0 && $waiting === 0) { self::markAvailable($id, self::libraryRef($lib)); continue; }
                if ($inLibrary && $done > 0 && (string)$r['media_type'] === 'movie') { self::markAvailable($id, self::libraryRef($lib)); continue; }
                if ($sent > 0) {
                    $progress = $pct ? round(array_sum($pct) / count($pct), 1) : (float)$r['progress'];
                    $note = $waiting ? $sent . ' downloading, ' . $waiting . ' waiting to be searched' : ($pct && min($pct) >= 100 ? 'Downloaded. Waiting to be imported into the library.' : 'Downloading');
                    if ($r['status'] !== 'downloading' || abs($progress - (float)$r['progress']) >= 0.5 || $note !== $r['note']) self::setStatus($id, 'downloading', ['progress' => $progress, 'note' => $note]);
                    if ($r['status'] !== 'downloading') self::notifyRequesters($id, 'downloading');
                } elseif ($r['status'] !== 'pending') {
                    $note = $waiting ? 'Looking for a download…' : ($done ? 'Downloaded. Waiting for it to appear in the library.' : 'No download found yet. An admin can try again.');
                    if ($note !== $r['note'] || $r['status'] !== 'approved') self::setStatus($id, 'approved', ['note' => $note]);
                }
            } catch (Throwable $e) { error_log('Vaultcast request reconcile: ' . $e->getMessage()); }
        }
    }

    private static function libraryRef(?array $lib): string
    {
        if (!$lib) return '';
        return $lib['type'] === 'movie' ? 'movie:' . $lib['item_id'] : 'show:' . $lib['show_key'];
    }

    private static function markAvailable(int $id, string $ref): void
    {
        self::setStatus($id, 'available', ['library_ref' => mb_substr($ref, 0, 100), 'available_at' => time(), 'progress' => 100, 'note' => '']);
        self::resolveManagers($id);
        self::notifyRequesters($id, 'available');
    }

    // ------------------------------------------------------------------ inbox
    private static function managers(): array
    {
        $out = [];
        foreach (Auth::users() as $u) if (is_array($u) && !empty($u['id']) && self::canManage($u)) $out[] = $u;
        return $out;
    }

    private static function describe(array $r): string
    {
        $t = (string)$r['title'] . ((int)$r['year'] ? ' (' . (int)$r['year'] . ')' : '');
        if ($r['media_type'] === 'tv') $t .= (int)$r['season'] > 0 ? ' · Season ' . (int)$r['season'] : ' · Whole show';
        return $t;
    }

    /** A pending request: one Requests item per manager, refreshed (never duplicated) when someone else joins it. */
    private static function notifyManagers(int $id, bool $bump = true): void
    {
        try {
            $r = self::rowsByIds([$id])[0] ?? null;
            if (!$r || $r['status'] !== 'pending' || !$r['_users']) return;
            $users = $r['_users'];
            $last = $users[count($users) - 1];
            $who = Auth::findById((string)$last['user_id']);
            $n = count($users);
            $body = self::name($who) . ($last['restricted'] ? ' (kids profile)' : '') . ' asked for ' . ($r['media_type'] === 'movie' ? 'this film' : ((int)$r['season'] > 0 ? 'this season' : 'this show')) . '.';
            if ($n > 1) $body .= ' ' . $n . ' people want it.';
            if ((string)$last['note'] !== '') $body .= ' “' . mb_substr((string)$last['note'], 0, 140) . '”';
            $actor = $who ? ['id' => (string)$who['id'], 'name' => self::name($who), 'avatar_url' => $who['avatar_url'] ?? null] : null;
            foreach (self::managers() as $m) {
                Inbox::add((string)$m['id'], 'request', 'media', 'mreq:' . $id, $actor, 'Request: ' . self::describe($r), $body, ['kind' => 'path', 'path' => 'requests/manage'], ['request_id' => $id], $bump ? time() : (int)$r['created_at']);
            }
        } catch (Throwable $e) { error_log('Vaultcast request inbox: ' . $e->getMessage()); }
    }

    private static function resolveManagers(int $id): void
    {
        try { self::q("DELETE FROM vaultcast_inbox WHERE dedupe=? AND subtype='media'", ['mreq:' . $id]); } catch (Throwable $e) {}
    }

    private static function notifyRequesters(int $id, string $event): void
    {
        $titles = ['approved' => 'Request approved: ', 'declined' => 'Request declined: ', 'available' => 'Ready to watch: ', 'downloading' => 'On its way: '];
        if (!isset($titles[$event])) return;
        try {
            $r = self::rowsByIds([$id])[0] ?? null;
            if (!$r) return;
            $bodies = [
                'approved' => 'Good news: it’s been approved. We’ll let you know when it’s ready to watch.',
                'declined' => (string)$r['reason'] !== '' ? 'It won’t be added this time. Reason: ' . (string)$r['reason'] : 'It won’t be added this time.',
                'available' => 'It’s in the library now. Enjoy!',
                'downloading' => 'It’s downloading now and will be in the library soon.',
            ];
            foreach ($r['_users'] as $u) {
                $uid = (string)$u['user_id'];
                // One item per request: a newer status replaces the older one.
                foreach (array_keys($titles) as $old) if ($old !== $event) Inbox::resolve($uid, 'mreq-u:' . $id . ':' . $old);
                Inbox::add($uid, 'request', 'media_update', 'mreq-u:' . $id . ':' . $event, null, $titles[$event] . self::describe($r), $bodies[$event], ['kind' => 'path', 'path' => 'requests/mine'], ['request_id' => $id, 'status' => $event]);
                if ($event === 'available') Achievements::requestAvailable($uid, $id);
            }
        } catch (Throwable $e) { error_log('Vaultcast request inbox: ' . $e->getMessage()); }
    }

    // ------------------------------------------------------------------ admin settings
    public static function settingsView(): array
    {
        $roles = [['id' => 'user', 'name' => 'User']];
        foreach (Permissions::roles() as $id => $role) $roles[] = ['id' => (string)$id, 'name' => (string)($role['name'] ?? $id)];
        $users = [];
        foreach (Auth::users() as $u) if (is_array($u) && !empty($u['id']) && ($u['role'] ?? '') !== 'admin') $users[] = ['id' => (string)$u['id'], 'name' => self::name($u), 'role' => (string)($u['role'] ?? 'user')];
        usort($users, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        return ['ok' => true, 'config' => self::config(), 'roles' => $roles, 'users' => $users, 'acquisition' => self::acquisitionReady()];
    }

    /** Account deleted: drop their part; a pending request nobody else wants goes too. */
    public static function purgeUser(string $uid): void
    {
        try {
            $ids = self::q('SELECT request_id FROM vaultcast_media_request_users WHERE user_id=?', [$uid])->fetchAll(PDO::FETCH_COLUMN);
            self::q('DELETE FROM vaultcast_media_request_users WHERE user_id=?', [$uid]);
            foreach ($ids as $id) {
                $left = (int)self::q('SELECT COUNT(*) FROM vaultcast_media_request_users WHERE request_id=?', [(int)$id])->fetchColumn();
                if ($left === 0 && self::q("DELETE FROM vaultcast_media_requests WHERE id=? AND status='pending'", [(int)$id])->rowCount() > 0) self::resolveManagers((int)$id);
            }
        } catch (Throwable $e) {}
    }
}
