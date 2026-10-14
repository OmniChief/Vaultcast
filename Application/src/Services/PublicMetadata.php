<?php
declare(strict_types=1);

/** Public metadata lookups; catalogue browsing never calls these providers. */
final class PublicMetadata
{
    private static function get(string $url): array
    {
        $key = 'public-metadata-url:' . $url;
        $cached = \Vaultcast\Repository::cacheGet($key);
        if ($cached !== null) return $cached;
        $context = stream_context_create(['http' => ['timeout' => 6, 'header' => "User-Agent: Vaultcast/" . STREAM_VERSION . "\r\nAccept: application/json\r\n"]]);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6, CURLOPT_USERAGENT => 'Vaultcast/' . STREAM_VERSION . '']);
            $raw = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($status !== 200) return [];
        } else { $raw = @file_get_contents($url, false, $context); }
        $value = $raw ? json_decode($raw, true) : null;
        if (!is_array($value)) return [];
        \Vaultcast\Repository::cachePut($key, $value, 3600);
        return $value;
    }

    /** Metadata sources Fix Match can search, per kind. */
    const SOURCES = [
        'tvmaze'    => ['label' => 'TVmaze',    'kinds' => ['tv', 'anime']],
        'wikipedia' => ['label' => 'Wikipedia', 'kinds' => ['movie', 'tv', 'anime']],
        'itunes'    => ['label' => 'iTunes',    'kinds' => ['movie', 'tv']],
        'omdb'      => ['label' => 'OMDb',      'kinds' => ['movie', 'tv'], 'needs_key' => true],
    ];

    /** Optional injected OMDb key (tests); null = read Settings. */
    public static $omdbKey = null;
    /** Optional fetcher override (tests): fn(string $url): array. */
    public static $fetch = null;

    public static function omdbKey(): string {
        if (self::$omdbKey !== null) return (string)self::$omdbKey;
        if (!class_exists('Store')) return '';
        $p = Store::settings()['posters'] ?? [];
        return trim((string)($p['omdb_api_key'] ?? ''));
    }

    /** Sources for a kind with availability, e.g. OMDb without a key is unavailable with a hint. */
    public static function sources(string $kind): array {
        $kind = $kind === 'anime' ? 'anime' : ($kind === 'tv' ? 'tv' : 'movie');
        $out = [];
        foreach (self::SOURCES as $id => $def) {
            if (!in_array($kind, $def['kinds'], true)) continue;
            $ok = empty($def['needs_key']) || self::omdbKey() !== '';
            $out[] = ['id' => $id, 'label' => $def['label'], 'available' => $ok,
                'hint' => $ok ? null : 'Needs an OMDb API key. Add it in Settings > Posters.'];
        }
        return $out;
    }

    private static function fetchJson(string $url): array {
        return self::$fetch ? (array)call_user_func(self::$fetch, $url) : self::get($url);
    }

    /** @param string|null $source one source id, or null/'all' for every available source. */
    public static function search(string $kind, string $query, ?string $source = null): array
    {
        $query = mb_substr(trim($query), 0, 200);
        $year = null;
        $title = $query;
        if (preg_match('/^(.*?)\s+\(?((?:19|20)\d{2})\)?$/', $query, $m) && trim($m[1]) !== '') {
            $title = trim($m[1]); $year = (int)$m[2];
        }
        $sources = self::sources($kind);
        $want = [];
        foreach ($sources as $src) {
            if ($source !== null && $source !== '' && $source !== 'all' && $src['id'] !== $source) continue;
            if ($src['available']) $want[] = $src['id'];
        }
        if ($source && $source !== 'all' && !$want) {
            foreach ($sources as $src) if ($src['id'] === $source) {
                return ['ok' => true, 'kind' => $kind, 'query' => $query, 'source' => $source, 'sources' => $sources,
                    'results' => [], 'unavailable' => $src['hint'] ?? 'Source not available for this kind'];
            }
        }
        // Default "All" keeps the previous behaviour: TVmaze for shows, iTunes + Wikipedia for movies.
        if (!$source || $source === 'all') {
            $want = array_values(array_intersect($want, ($kind === 'movie' ? ['itunes', 'wikipedia', 'omdb'] : ['tvmaze', 'omdb'])));
        }
        $rows = [];
        foreach ($want as $sid) {
            foreach (self::searchSource($sid, $kind, $title, $year) as $row) $rows[] = $row;
        }
        $unique = [];
        foreach ($rows as $row) {
            $row['source_label'] = self::SOURCES[$row['source']]['label'] ?? $row['source'];
            $row['match_score'] = Matching::titleScore($title, (string)$row['title']);
            if ($year && !empty($row['year'])) $row['match_score'] -= min(35, abs($year - (int)$row['year']) * 5);
            $unique[$row['source'] . ':' . $row['id']] = $row;
        }
        $rows = array_values($unique);
        usort($rows, function ($a, $b) { return $b['match_score'] <=> $a['match_score']; });
        return ['ok' => true, 'kind' => $kind, 'query' => $query, 'source' => $source ?: 'all', 'sources' => $sources, 'results' => array_slice($rows, 0, 20)];
    }

    private static function searchSource(string $sid, string $kind, string $title, ?int $year): array
    {
        $isTv = $kind === 'tv' || $kind === 'anime';
        $rows = [];
        if ($sid === 'tvmaze') {
            $rows = Matching::tvmazeSearch($title);
            if ($kind === 'anime' && count($rows) < 3) {
                $anime = self::fetchJson('https://api.jikan.moe/v4/anime?limit=3&q=' . rawurlencode($title));
                foreach ($anime['data'] ?? [] as $show) {
                    $alias = $show['title_english'] ?? $show['title'] ?? '';
                    if ($alias !== '' && strcasecmp($alias, $title) !== 0) {
                        foreach (Matching::tvmazeSearch($alias) as $row) { $row['matched_via'] = 'MyAnimeList / Jikan'; $rows[] = $row; }
                    }
                }
            }
        } elseif ($sid === 'itunes') {
            $entity = $isTv ? 'tvSeason' : 'movie';
            $apple = self::fetchJson('https://itunes.apple.com/search?entity=' . $entity . '&limit=12&term=' . rawurlencode($title));
            foreach ($apple['results'] ?? [] as $r) {
                $id = $isTv ? ($r['collectionId'] ?? null) : ($r['trackId'] ?? null);
                $name = $isTv ? ($r['artistName'] ?? $r['collectionName'] ?? '') : ($r['trackName'] ?? '');
                if (empty($id) || $name === '') continue;
                $img = $r['artworkUrl100'] ?? null;
                if ($img) $img = str_replace('100x100bb', '600x600bb', $img);
                $rows[] = ['kind' => $isTv ? 'tv' : 'movie', 'source' => 'itunes', 'id' => $id, 'title' => $name,
                    'year' => (int)substr($r['releaseDate'] ?? '', 0, 4) ?: null,
                    'summary' => $r['longDescription'] ?? $r['shortDescription'] ?? $r['description'] ?? '',
                    'image' => $img, 'url' => $r['trackViewUrl'] ?? $r['collectionViewUrl'] ?? null];
            }
        } elseif ($sid === 'wikipedia') {
            $word = $isTv ? 'TV series' : 'film';
            // One request returns titles, descriptions and thumbnails for all results.
            $wiki = self::fetchJson('https://en.wikipedia.org/w/api.php?' . http_build_query([
                'action' => 'query', 'format' => 'json', 'formatversion' => 2, 'generator' => 'search',
                'gsrsearch' => $title . ' ' . $word, 'gsrlimit' => 8, 'gsrnamespace' => 0,
                'prop' => 'pageimages|extracts', 'piprop' => 'thumbnail', 'pithumbsize' => 240,
                'exintro' => 1, 'explaintext' => 1, 'exchars' => 600,
            ]));
            $re = $isTv ? '/\b(series|television|TV|sitcom|show)\b/i' : '/\bfilm\b/i';
            foreach ($wiki['query']['pages'] ?? [] as $page) {
                $name = $page['title'] ?? '';
                $summary = $page['extract'] ?? '';
                if (!preg_match($re, $name . ' ' . $summary)) continue;
                preg_match('/\b(?:19|20)\d{2}\b/', $name . ' ' . $summary, $date);
                $rows[] = ['kind' => $isTv ? 'tv' : 'movie', 'source' => 'wikipedia', 'id' => $page['pageid'],
                    'title' => preg_replace('/\s*\([^)]*\)$/', '', $name), 'page_title' => $name,
                    'year' => isset($date[0]) ? (int)$date[0] : null, 'summary' => $summary,
                    'image' => $page['thumbnail']['source'] ?? null,
                    'url' => 'https://en.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $name))];
            }
        } elseif ($sid === 'omdb') {
            $key = self::omdbKey();
            if ($key === '') return [];
            $params = ['apikey' => $key, 's' => $title, 'type' => $isTv ? 'series' : 'movie'];
            if ($year) $params['y'] = $year;
            $res = self::fetchJson('https://www.omdbapi.com/?' . http_build_query($params));
            foreach (array_slice($res['Search'] ?? [], 0, 6) as $hit) {
                if (empty($hit['imdbID'])) continue;
                // Detail call gives the plot so the preview and pinned synopsis match the pick.
                $d = self::fetchJson('https://www.omdbapi.com/?' . http_build_query(['apikey' => $key, 'i' => $hit['imdbID'], 'plot' => 'full']));
                $poster = (string)($hit['Poster'] ?? '');
                $plot = (string)($d['Plot'] ?? '');
                $rows[] = ['kind' => $isTv ? 'tv' : 'movie', 'source' => 'omdb', 'id' => (string)$hit['imdbID'],
                    'title' => (string)($hit['Title'] ?? ''), 'year' => (int)substr((string)($hit['Year'] ?? ''), 0, 4) ?: null,
                    'summary' => strcasecmp($plot, 'N/A') === 0 ? '' : $plot,
                    'image' => ($poster !== '' && strcasecmp($poster, 'N/A') !== 0) ? $poster : null,
                    'genres' => array_filter(array_map('trim', explode(',', (string)($d['Genre'] ?? '')))),
                    'url' => 'https://www.imdb.com/title/' . $hit['imdbID'] . '/'];
            }
        }
        return $rows;
    }
}
