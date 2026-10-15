<?php
declare(strict_types=1);

/**
 * Built-in list of legal, public-domain download sources for Settings → Download sources,
 * plus helpers for the stored acquisition.feeds list.
 *
 * Stored format is unchanged: a list of {url, name, type?}. Two optional keys are added:
 * `enabled` (missing = enabled, as before) and `builtin` (catalogue id). Existing user
 * entries are always preserved; normalisation is idempotent.
 */
final class FeedCatalogue {
    const IA_SEARCH = 'https://archive.org/advancedsearch.php?q=%28{query}%29+AND+mediatype%3Amovies+AND+licenseurl%3A%2Apublicdomain%2A&fl%5B%5D=identifier&fl%5B%5D=title&sort%5B%5D=downloads+desc&rows=50&output=rss';

    /** @return array<int,array{id:string,name:string,description:string,url:string,type:string}> */
    public static function builtins(): array {
        return [
            [
                'id' => 'ia-public-domain',
                'name' => 'Internet Archive: public-domain films',
                'description' => 'Searches Internet Archive videos marked Public Domain (classic films, shorts, newsreels). Downloads use the Archive\'s official torrents.',
                'url' => self::IA_SEARCH,
                'type' => 'search',
            ],
            [
                'id' => 'ia-prelinger',
                'name' => 'Prelinger Archives',
                'description' => 'Newest additions to the Prelinger collection of public-domain ephemeral, educational and industrial films.',
                'url' => 'https://archive.org/services/collection-rss.php?collection=prelinger',
                'type' => 'rss',
            ],
            [
                'id' => 'ia-classic-cartoons',
                'name' => 'Internet Archive: classic cartoons',
                'description' => 'Newest additions to the Archive\'s public-domain classic cartoons collection.',
                'url' => 'https://archive.org/services/collection-rss.php?collection=classic_cartoons',
                'type' => 'rss',
            ],
        ];
    }

    public static function isEnabled($feed): bool {
        return !(is_array($feed) && array_key_exists('enabled', $feed) && $feed['enabled'] === false);
    }

    /** Feeds the fetch/search code should use (enabled ones only). */
    public static function active($feeds): array {
        $out = [];
        foreach (is_array($feeds) ? $feeds : [] as $f) {
            if (self::isEnabled($f)) $out[] = $f;
        }
        return $out;
    }

    /** Validate a custom feed. Returns error string or null. */
    public static function validateCustom(string $name, string $url): ?string {
        if (trim($name) === '') return 'Give the feed a name.';
        if (strlen($name) > 120) return 'Feed name is too long.';
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) return 'Feed URL must start with http:// or https://';
        $test = str_replace(['{query}', '{q}'], 'x', $url);
        if (filter_var($test, FILTER_VALIDATE_URL) === false || (string)parse_url($test, PHP_URL_HOST) === '') return 'Feed URL is not a valid web address.';
        if (preg_match('/\s/', $url)) return 'Feed URL must not contain spaces.';
        return null;
    }

    /**
     * Normalise a submitted feeds list: drop junk, keep string entries as {url}, tag entries
     * matching a built-in URL with its id, dedupe by URL (first wins), booleanise `enabled`.
     * @return array{ok:bool,feeds?:array,error?:string}
     */
    public static function normalize($feeds): array {
        if (!is_array($feeds)) return ['ok' => false, 'error' => 'Feeds must be a list.'];
        $byUrl = [];
        foreach (self::builtins() as $b) $byUrl[$b['url']] = $b;
        $out = [];
        $seen = [];
        foreach (array_values($feeds) as $f) {
            if (is_string($f)) $f = ['url' => $f];
            if (!is_array($f)) continue;
            $url = trim((string)($f['url'] ?? ''));
            if ($url === '') continue;
            if (isset($seen[$url])) continue;
            $seen[$url] = true;
            $e = $f;
            $e['url'] = $url;
            if (isset($byUrl[$url])) {
                $e['builtin'] = $byUrl[$url]['id'];
                if (!isset($e['name']) || $e['name'] === '') $e['name'] = $byUrl[$url]['name'];
                if (!isset($e['type'])) $e['type'] = $byUrl[$url]['type'];
            } else {
                unset($e['builtin']);
                $err = self::validateCustom((string)($e['name'] ?? $url), $url);
                if ($err !== null) return ['ok' => false, 'error' => ($e['name'] ?? $url) . ': ' . $err];
            }
            if (array_key_exists('enabled', $e)) $e['enabled'] = (bool)$e['enabled'];
            $out[] = $e;
        }
        return ['ok' => true, 'feeds' => $out];
    }

    /** Internet Archive item page → its official torrent (only archive.org /details/ links). */
    public static function archiveTorrent(string $link): string {
        if (preg_match('#^https?://(?:www\.)?archive\.org/details/([A-Za-z0-9._-]+)/?$#', $link, $m)) {
            return 'https://archive.org/download/' . $m[1] . '/' . $m[1] . '_archive.torrent';
        }
        return '';
    }
}
