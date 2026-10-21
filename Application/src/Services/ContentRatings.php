<?php
declare(strict_types=1);

/**
 * Age ratings for films and shows, normalised into five buckets so parental ceilings can compare them:
 * 1 = U / G (all ages), 2 = PG, 3 = 12, 4 = 15, 5 = 18. 0 means "no rating known".
 *
 * Targets: 'item:<id>' for films (and single files), 'show:<key>' for a whole TV / anime show.
 * Sources, strongest first: manual (set by an admin, never overwritten), nfo (Kodi-style sidecar),
 * omdb, tmdb. A row with source 'none' only records a failed lookup so the background task waits
 * before trying again.
 */
final class ContentRatings
{
    const LABELS = [1 => 'U', 2 => 'PG', 3 => '12', 4 => '15', 5 => '18'];
    const NAMES = [1 => 'U / G · all ages', 2 => 'PG · parental guidance', 3 => '12 and over', 4 => '15 and over', 5 => '18 and over'];
    private static $map = null;

    /** Normalise a rating string from any source (BBFC, MPAA, US TV, TMDB country certificates, FSK...) into a bucket. */
    public static function normalise($raw): int
    {
        $r = strtoupper(trim((string)$raw));
        $r = preg_replace('/^(?:RATED|CERTIFICATE|CERT|FSK|BBFC|MPAA|ACB|USK)\s*:?\s*/', '', $r);
        $r = trim(str_replace(["\u{2013}", "\u{2014}", '_'], '-', (string)$r));
        if ($r === '' || in_array($r, ['N/A', 'NA', 'NOT RATED', 'UNRATED', 'NR', 'APPROVED', 'PASSED', 'E', 'EXEMPT', '-', 'TBC', 'TBA', 'NONE'], true)) return 0;
        $known = [
            'G' => 1, 'U' => 1, 'UC' => 1, 'TV-Y' => 1, 'TV-Y7' => 1, 'TV-Y7-FV' => 1, 'TV-G' => 1, 'ALL' => 1, 'AL' => 1, 'L' => 1, 'TP' => 1, 'A' => 1,
            'PG' => 2, 'TV-PG' => 2, 'GP' => 2, 'M/PG' => 2,
            'PG-13' => 3, '12' => 3, '12A' => 3,
            'TV-14' => 4, '15' => 4, 'M' => 4, 'MA15+' => 4, 'MA 15+' => 4,
            'R' => 5, 'NC-17' => 5, 'X' => 5, '18' => 5, 'R18' => 5, 'R18+' => 5, 'TV-MA' => 5, 'R-RATED' => 5,
        ];
        if (isset($known[$r])) return $known[$r];
        if (preg_match('/(\d{1,2})/', $r, $m)) {
            $n = (int)$m[1];
            return $n <= 6 ? 1 : ($n <= 10 ? 2 : ($n <= 12 ? 3 : ($n <= 15 ? 4 : 5)));
        }
        return 0;
    }

    public static function label(int $bucket): string { return self::LABELS[$bucket] ?? 'Not rated'; }

    /** target => bucket for every rated title (one query per request, only used for restricted viewers). */
    public static function map(): array
    {
        if (self::$map !== null) return self::$map;
        self::$map = [];
        try {
            foreach (\Vaultcast\Database::connection()->query('SELECT target,bucket FROM vaultcast_content_ratings WHERE bucket>0') as $r) self::$map[(string)$r['target']] = (int)$r['bucket'];
        } catch (Throwable $e) { /* table not created yet: everything counts as unrated */ }
        return self::$map;
    }

    public static function forget(): void { self::$map = null; }

    /** Show key for a raw catalogue item or a public card, or '' when it is not part of a show. */
    public static function showKeyOf(array $it): string
    {
        if (($it['kind'] ?? '') === 'show') return (string)($it['show_key'] ?? $it['id'] ?? '');
        if (!empty($it['show_key']) && is_string($it['show_key'])) return $it['show_key'];
        if (in_array($it['type'] ?? '', ['tv', 'anime'], true) && (string)($it['show'] ?? '') !== '' && isset($it['section'])) return Catalogue::showKey((string)$it['section'], (string)$it['show']);
        return '';
    }

    /**
     * Rating targets for an item or card, strongest first. Empty when the title is not rated content at all
     * (music, games and other files are governed by area access, not age ratings).
     */
    public static function targetsOf(array $it): array
    {
        $kind = (string)($it['kind'] ?? '');
        $media = (string)($it['media_kind'] ?? '');
        if ($kind !== 'show' && $kind !== 'season' && $media !== 'video' && $media !== 'show') return [];
        $show = self::showKeyOf($it);
        $out = [];
        if ($show !== '') $out[] = 'show:' . $show;
        if ($kind !== 'show' && $kind !== 'season' && isset($it['id']) && is_scalar($it['id'])) $out[] = 'item:' . $it['id'];
        return $out;
    }

    /** Bucket for an item or card: the show's rating wins for episodes, else the item's own; 0 when unknown. */
    public static function bucketOf(array $it): int
    {
        $map = self::map();
        foreach (self::targetsOf($it) as $t) if (!empty($map[$t])) return $map[$t];
        return 0;
    }

    public static function validTarget(string $target): bool
    {
        return (bool)preg_match('/^(?:item:[A-Za-z0-9_-]{1,64}|show:[a-f0-9]{8,64})$/D', $target);
    }

    public static function get(string $target): ?array
    {
        if (!self::validTarget($target)) return null;
        $s = \Vaultcast\Database::connection()->prepare('SELECT target,bucket,raw,source,updated_at FROM vaultcast_content_ratings WHERE target=?');
        $s->execute([$target]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['bucket'] = (int)$r['bucket']; $r['label'] = self::label((int)$r['bucket']);
        return $r;
    }

    /** Store a looked-up rating. Never replaces a manual rating; a failed lookup never replaces a real one. */
    public static function capture(string $target, $raw, string $source): void
    {
        if (!self::validTarget($target)) return;
        $raw = mb_substr(trim((string)$raw), 0, 40);
        $bucket = self::normalise($raw);
        if ($bucket === 0 && $source !== 'none') $source = 'none';
        try {
            $sql = 'INSERT INTO vaultcast_content_ratings (target,bucket,raw,source,updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE '
                . "bucket=IF(source='manual' OR (VALUES(source)='none' AND bucket>0),bucket,VALUES(bucket)),"
                . "raw=IF(source='manual' OR (VALUES(source)='none' AND bucket>0),raw,VALUES(raw)),"
                . 'updated_at=VALUES(updated_at),'
                . "source=IF(source='manual' OR (VALUES(source)='none' AND bucket>0),source,VALUES(source))";
            \Vaultcast\Database::connection()->prepare($sql)->execute([$target, $bucket, $raw, $source, time()]);
            self::$map = null;
        } catch (Throwable $e) { error_log('Vaultcast age rating: ' . $e->getMessage()); }
    }

    /** Admin override. Bucket 0 removes the manual rating so lookups can fill it again. */
    public static function setManual(string $target, int $bucket): ?array
    {
        if (!self::validTarget($target)) throw new InvalidArgumentException('Choose a film or show.');
        if ($bucket < 0 || $bucket > 5) throw new InvalidArgumentException('Choose an age rating.');
        $pdo = \Vaultcast\Database::connection();
        if ($bucket === 0) $pdo->prepare('DELETE FROM vaultcast_content_ratings WHERE target=?')->execute([$target]);
        else $pdo->prepare("INSERT INTO vaultcast_content_ratings (target,bucket,raw,source,updated_at) VALUES (?,?,?,'manual',?) ON DUPLICATE KEY UPDATE bucket=VALUES(bucket),raw=VALUES(raw),source='manual',updated_at=VALUES(updated_at)")
            ->execute([$target, $bucket, self::LABELS[$bucket], time()]);
        self::$map = null;
        if (class_exists('SmartCollections')) SmartCollections::bump();
        return self::get($target);
    }

    /** Rating from a Kodi-style .nfo next to the file (or tvshow.nfo in a show folder). */
    private static function nfoRating(string $file): string
    {
        if (!is_file($file) || filesize($file) > 2000000) return '';
        $xml = (string)@file_get_contents($file);
        foreach (['mpaa', 'certification', 'contentrating', 'rating_age'] as $tag) {
            if (preg_match('#<' . $tag . '>\s*([^<]{1,60})\s*</' . $tag . '>#i', $xml, $m)) {
                $v = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                // "UK:15 / US:R" style lists: prefer the UK certificate, then the first one.
                if (preg_match('/\b(?:UK|GB)\s*:\s*([A-Z0-9+\-]+)/i', $v, $mm)) return $mm[1];
                if (preg_match('/^[A-Z]{2}\s*:\s*([^\/]+)/i', $v, $mm)) return trim($mm[1]);
                return $v;
            }
        }
        return '';
    }

    private static function imdbIdForMovie(array $it): string
    {
        foreach ([(string)($it['imdb_id'] ?? ''), (string)((MetadataPins::get('movie_synopsis', (string)$it['id']) ?? [])['provider_id'] ?? '')] as $id) if (preg_match('/^tt\d{5,10}$/D', $id)) return $id;
        $cache = Posters::movieSynopsisCachePath((string)$it['id']);
        if (is_file($cache)) { $j = json_decode((string)@file_get_contents($cache), true); if (is_array($j) && preg_match('/^omdb:(tt\d{5,10})$/D', (string)($j['source'] ?? ''), $m)) return $m[1]; }
        return '';
    }

    private static function tmdbCertification(string $imdb, bool $tv): string
    {
        $key = CollectionDiscovery::tmdbKey();
        if ($key === '' || $imdb === '') return '';
        $q = []; $headers = [];
        if (strlen($key) > 60 && substr_count($key, '.') >= 2) $headers[] = 'Authorization: Bearer ' . $key; else $q['api_key'] = $key;
        $find = Posters::remoteJson('https://api.themoviedb.org/3/find/' . rawurlencode($imdb) . '?' . http_build_query($q + ['external_source' => 'imdb_id']), $headers);
        $hit = $find[$tv ? 'tv_results' : 'movie_results'][0]['id'] ?? null;
        if (!$hit) return '';
        $data = Posters::remoteJson('https://api.themoviedb.org/3/' . ($tv ? 'tv/' : 'movie/') . (int)$hit . ($tv ? '/content_ratings' : '/release_dates') . ($q ? '?' . http_build_query($q) : ''), $headers);
        $best = ['GB' => '', 'US' => '', 'any' => ''];
        foreach ((array)($data['results'] ?? []) as $row) {
            $country = (string)($row['iso_3166_1'] ?? '');
            $cert = $tv ? (string)($row['rating'] ?? '') : '';
            if (!$tv) foreach ((array)($row['release_dates'] ?? []) as $d) if (trim((string)($d['certification'] ?? '')) !== '') { $cert = (string)$d['certification']; break; }
            if ($cert === '') continue;
            if (isset($best[$country]) && $best[$country] === '') $best[$country] = $cert;
            if ($best['any'] === '') $best['any'] = $cert;
        }
        return $best['GB'] ?: ($best['US'] ?: $best['any']);
    }

    /**
     * Background task: fill ratings for films and shows that have none yet. Time-budgeted; lookups that find
     * nothing are retried after 14 days. Returns counts and how many titles are still waiting.
     */
    public static function warm(?callable $checkpoint = null, float $budget = 120): array
    {
        $out = ['ok' => true, 'rated' => 0, 'unrated' => 0, 'deferred' => 0];
        $pdo = \Vaultcast\Database::connection();
        $known = [];
        foreach ($pdo->query('SELECT target,source,updated_at FROM vaultcast_content_ratings') as $r) {
            if ($r['source'] !== 'none' || (int)$r['updated_at'] > time() - 14 * 86400) $known[(string)$r['target']] = true;
        }
        $omdb = Posters::omdbKey() !== '';
        $started = microtime(true);
        $remote = Posters::enabled();
        // Films first (one row per file), then shows (one row per show key).
        $movies = $pdo->query("SELECT item_id FROM vaultcast_browse_items WHERE media_kind='video' AND show_key='' AND type IN ('movies','disney','movie')")->fetchAll(PDO::FETCH_COLUMN);
        $shows = $pdo->query("SELECT DISTINCT show_key FROM vaultcast_browse_items WHERE media_kind='video' AND show_key<>''")->fetchAll(PDO::FETCH_COLUMN);
        $todo = [];
        foreach ($movies as $id) if (!isset($known['item:' . $id])) $todo[] = ['item', (string)$id];
        foreach ($shows as $key) if (!isset($known['show:' . $key])) $todo[] = ['show', (string)$key];
        foreach ($todo as $i => $job) {
            if ($checkpoint) $checkpoint();
            if (microtime(true) - $started > $budget) { $out['deferred'] = count($todo) - $i; break; }
            [$kind, $id] = $job; $raw = ''; $source = 'none';
            try {
                if ($kind === 'item') {
                    $it = Catalogue::item($id); if (!$it) continue;
                    $nfo = preg_replace('/\.[^.\/\\\\]+$/', '.nfo', (string)($it['path'] ?? ''));
                    if ($nfo && ($raw = self::nfoRating((string)$nfo)) !== '') $source = 'nfo';
                    if ($raw === '' && $remote) {
                        Posters::beginRemoteBudget(6);
                        try {
                            $imdb = self::imdbIdForMovie($it);
                            if ($omdb) { $d = Posters::omdbById($imdb, (string)($it['title'] ?? ''), isset($it['year']) ? (int)$it['year'] : null); if ($d && self::normalise($d['Rated'] ?? '') > 0) { $raw = (string)$d['Rated']; $source = 'omdb'; } if ($imdb === '' && !empty($d['imdbID'])) $imdb = (string)$d['imdbID']; }
                            if ($raw === '' && $imdb !== '') { $c = self::tmdbCertification($imdb, false); if (self::normalise($c) > 0) { $raw = $c; $source = 'tmdb'; } }
                        } finally { Posters::endRemoteBudget(); }
                    }
                    self::capture('item:' . $id, $raw, $source);
                } else {
                    $items = BrowseIndex::items($id); $sample = $items[0] ?? null;
                    $dir = is_array($sample) ? Catalogue::showDirFromItem($sample) : null;
                    if ($dir && ($raw = self::nfoRating($dir . '/tvshow.nfo')) !== '') $source = 'nfo';
                    if ($raw === '' && $remote) {
                        $guide = \Vaultcast\Repository::document('tvmaze-guide:' . $id, null);
                        $imdb = is_array($guide) ? (string)($guide['externals']['imdb'] ?? '') : '';
                        if (preg_match('/^tt\d{5,10}$/D', $imdb)) {
                            Posters::beginRemoteBudget(6);
                            try {
                                if ($omdb) { $d = Posters::omdbById($imdb, '', null); if ($d && self::normalise($d['Rated'] ?? '') > 0) { $raw = (string)$d['Rated']; $source = 'omdb'; } }
                                if ($raw === '') { $c = self::tmdbCertification($imdb, true); if (self::normalise($c) > 0) { $raw = $c; $source = 'tmdb'; } }
                            } finally { Posters::endRemoteBudget(); }
                        }
                    }
                    self::capture('show:' . $id, $raw, $source);
                }
                if ($source === 'none') $out['unrated']++; else $out['rated']++;
            } catch (Throwable $e) {
                if ($e->getMessage() === 'Cancelled by administrator.') throw $e;
                error_log('Vaultcast age rating ' . $kind . ':' . $id . ': ' . $e->getMessage());
            }
        }
        return $out;
    }
}
