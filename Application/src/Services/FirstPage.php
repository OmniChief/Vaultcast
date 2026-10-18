<?php
declare(strict_types=1);

/** Real first-response HTML. No media discovery, probes, or whole-section summaries. */
final class FirstPage {
    private static function esc($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function home(array $user): array {
        $home = Catalogue::cachedHome($user);
        if ($home !== null) return ['home'=>$home, 'refresh'=>false];
        $home = Catalogue::cachedHomeLibrary();
        if ($home !== null) return ['home'=>$home, 'refresh'=>true];

        // Even an empty cache gets real catalogue cards, using bounded indexed reads.
        // Full summaries and Continue Watching arrive independently after first paint.
        $settings = Store::settings();
        $meta = \Vaultcast\Repository::document('catalogue', ['sections'=>[]]);
        $sections = [];
        foreach ($meta['sections'] ?? [] as $section) {
            $id = (string)$section['id'];
            $type = (string)($section['type'] ?? 'movies');
            $shows = in_array($type, ['tv','anime'], true);
            if ($shows) {
                $rows = BrowseIndex::firstShows($id);
            } else {
                $rows = \Vaultcast\Repository::rows('SELECT payload FROM vaultcast_items WHERE section_id=? ORDER BY title,id LIMIT 8', [$id]);
            }
            $cards = [];
            foreach ($rows as $row) {
                $name = (string)($row['show'] ?? '');
                if ($shows && $name === '') $name = explode('/', str_replace('\\', '/', (string)($row['rel'] ?? '')))[0] ?: (string)$row['title'];
                $key = $shows ? Catalogue::showKey($id, $name) : (string)$row['id'];
                // Explicit public projection: never embed media paths in the page.
                $cards[] = ['id'=>$key, 'show_key'=>$shows ? $key : null,
                    'kind'=>$shows ? 'show' : null, 'title'=>$shows ? $name : (string)$row['title'],
                    'section'=>$id, 'type'=>$type, 'year'=>$row['year'] ?? null,
                    'media_kind'=>$shows ? 'show' : ($row['media_kind'] ?? 'video'),
                    'has_poster'=>true, 'system'=>$row['system'] ?? null];
            }
            $sections[] = ['id'=>$id, 'label'=>$section['label'], 'type'=>$type,
                'count'=>$shows ? ($section['show_count'] ?? 0) : ($section['count'] ?? 0), 'items'=>$cards];
        }
        return ['refresh'=>true, 'home'=>['ok'=>true, 'app_name'=>$settings['app_name'] ?? 'Vaultcast',
            'features'=>$settings['features'] ?? [], 'item_count'=>$meta['item_count'] ?? 0,
            'sections'=>$sections, 'continue_watching'=>[]]];
    }

    private static function cardUrl(array $item, string $base): string {
        if (($item['kind'] ?? '') === 'show') return $base . 'tvshows/' . rawurlencode((string)($item['slug'] ?? $item['show_key'] ?? $item['id']));
        if (!empty($item['show_key'])) return $base . 'tvshows/' . rawurlencode((string)($item['show_slug'] ?? $item['show_key']));
        if (($item['media_kind'] ?? '') === 'rom') return $base . 'games/' . (!empty($item['game_path'])
            ? implode('/', array_map('rawurlencode', explode('/', $item['game_path']))) : 'game/' . rawurlencode((string)$item['id']));
        if (($item['media_kind'] ?? '') === 'audio') return $base . 'library/' . rawurlencode((string)$item['section']);
        return $base . 'movies/' . rawurlencode((string)($item['slug'] ?? $item['id']));
    }

    private static function cards(string $title, array $items, string $base, ?string $more = null): string {
        if (!$items) return '';
        $html = '<section class="row"><div class="row-head"><h2>' . self::esc($title) . '</h2>';
        if ($more) $html .= '<a class="btn ghost sm" data-first-nav href="' . self::esc($more) . '">Browse all</a>';
        $html .= '</div><div class="scroller">';
        foreach ($items as $item) {
            $show = ($item['kind'] ?? '') === 'show';
            $name = (string)($item['show'] ?? $item['title'] ?? 'Untitled');
            if ($name === '') $name = (string)($item['title'] ?? 'Untitled');
            $poster = $base . 'api/' . ($show ? 'shows/' : 'items/') . rawurlencode((string)$item['id']) . '/poster?fetch=0&variant=medium&art=2';
            $html .= '<a class="card first-card" data-first-nav href="' . self::esc(self::cardUrl($item, $base)) . '">'
                . '<div class="poster"><span class="ph">' . self::esc($name) . '</span>'
                . '<img loading="lazy" decoding="async" width="160" height="240" data-first-src="' . self::esc($poster) . '" alt="" onerror="this.remove()"><noscript><img loading="lazy" width="160" height="240" src="' . self::esc($poster) . '" alt=""></noscript></div>'
                . '<div class="meta"><div class="title">' . self::esc($name) . '</div><div class="sub">'
                . self::esc($item['year'] ?? $item['system'] ?? '') . '</div></div></a>';
        }
        return $html . '</div></section>';
    }

    public static function render(array $startup, string $base, string $site, string $error = '', string $route = ''): string {
        $user = $startup['me']['user'] ?? null;
        if ($user && empty($user['access_state']['active'])) {
            return '<main class="login-wrap"><div class="login-card stack"><h1>' . self::esc($site) . '</h1><p>'
                . self::esc(Access::message((string)$user['access_state']['state'])) . '</p></div></main>';
        }
        if (!$startup['home']) {
            return '<main class="login-wrap"><form class="login-card stack" method="post" id="first-login">'
                . '<input type="hidden" name="first_action" value="login"><div class="brand">' . self::esc($site) . '</div>'
                . '<h1>Sign in</h1><p>Your films, shows, live TV and games.</p>'
                . '<label>Username<input name="username" autocomplete="username" required></label>'
                . '<label>Password<input name="password" type="password" autocomplete="current-password" required></label>'
                . '<button class="btn primary" type="submit">Sign in</button><p class="bad" role="alert">' . self::esc($error) . '</p></form></main>';
        }
        // Home artwork must not compete with a deep link's requested content.
        if (trim($route, '/') !== '' && trim($route, '/') !== 'index.php') {
            return '<div class="app-shell xshell" id="first-home"><main class="view" id="main" aria-busy="true"><p role="status">Opening your page…</p></main></div>';
        }
        $home = $startup['home'];
        $html = '<div class="app-shell xshell" id="first-home"><header class="topnav xnav">'
            . '<a class="brand" data-first-nav href="' . self::esc($base) . '"><span class="mark">▶</span><span class="brand-name">' . self::esc($site) . '</span></a>'
            . '<nav class="mode-switch" aria-label="Mode">';
        $html .= '<a class="mode-tab active" data-first-nav href="' . self::esc($base) . '">Home</a>';
        if (AreaAccess::allowed($user, 'library')) $html .= '<a class="mode-tab" data-first-nav href="' . self::esc($base . 'library') . '">Library</a>';
        if (!empty($home['features']['live'])) $html .= '<a class="mode-tab" data-first-nav href="' . self::esc($base . 'live') . '">Watch Live</a>';
        foreach ($home['sections'] ?? [] as $section) if ($section['type'] === 'roms') {
            $html .= '<a class="mode-tab" data-first-nav href="' . self::esc($base . 'games') . '">Play</a>'; break;
        }
        $html .= '</nav><div class="nav-right"><form class="search-wrap" action="' . self::esc($base . 'search') . '" method="get">'
            . '<input class="search-box" id="q" type="search" name="q" aria-label="Search library" placeholder="Search movies &amp; shows…"></form>'
            . '<a class="icon-btn avatar" title="Account &amp; settings" aria-label="Open account and settings" data-first-nav href="' . self::esc($base . 'settings') . '"><span class="avatar-initial">'
            . self::esc(mb_strtoupper(mb_substr(trim((string)($user['display_name'] ?? $user['username'] ?? '?')), 0, 1))) . '</span></a></div></header>'
            . '<nav class="first-sections" aria-label="Home"><a class="subnav-btn active" aria-current="page" data-first-nav href="' . self::esc($base) . '">For you</a>';
        foreach (['watchlist'=>'Watchlist','favourites'=>'Favourites','calendar'=>'Coming soon','friends'=>'Friends','cinema'=>'Watch together','history'=>'History','stats'=>'Game stats','inbox'=>'Inbox'] as $tab=>$label) $html .= '<a data-first-nav href="' . self::esc($base . 'home/' . $tab) . '">' . self::esc($label) . '</a>';
        $featured = $home['continue_watching'][0] ?? null;
        $html .= '</nav><main class="view" id="main"><div class="home-hub">'
            . '<section class="first-hero hub-hero"><span class="eyebrow">YOUR SPACE. YOUR STORIES.</span><h1>Welcome back, '
            . self::esc(($user['display_name'] ?? '') ?: ($user['username'] ?? 'friend')) . '.</h1><p>'
            . 'One more episode. A new high score. A night with your people. It all starts here.</p>';
        if ($featured) {
            $canPlay = ($featured['kind'] ?? '') !== 'show';
            $html .= '<a class="btn primary" data-first-nav' . ($canPlay ? ' data-first-play="' . self::esc($featured['id']) . '"' : '')
                . ' href="' . self::esc(self::cardUrl($featured, $base)) . '">' . ($canPlay ? (!empty($home['continue_watching']) ? 'Resume' : 'Play') : 'More info') . '</a>';
        }
        $html .= '</section>';
        $html .= '<div id="first-continue">' . self::cards('Continue watching', $home['continue_watching'] ?? [], $base) . '</div>';
        $html .= '<div class="hub-content"><div class="hub-two">';
        foreach (['Recently watched','Back in the game','Your next watch','Your circle'] as $title) $html .= '<section class="hub-panel"><div class="hub-panel-head"><h2>' . self::esc($title) . '</h2></div><div class="hub-panel-body"><p class="hub-empty">Opening your space…</p></div></section>';
        $html .= '</div></div>';
        return $html . '</div></main></div>';
    }
}
