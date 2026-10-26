<?php
/**
 * Bridge from Relay (Live) to the Vaultcast catalogue.
 * PHP 7.4. Does not scan disks; reads the catalogue Store already maintains.
 */
function relay_stream_boot() {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    require_once dirname(__DIR__) . '/bootstrap.php';
}

function relay_browser_ext($ext) {
    return in_array(strtolower((string)$ext), array('mp4', 'm4v', 'webm'), true);
}

function relay_play_path($item) {
    $path = isset($item['path']) ? (string)$item['path'] : '';
    $ext = isset($item['ext']) ? strtolower((string)$item['ext']) : '';
    if ($path !== '' && !relay_browser_ext($ext)) {
        $alt = preg_replace('/\.[^.]+$/', '', $path) . '.directplay.mp4';
        if (is_string($alt) && is_file($alt)) {
            return $alt;
        }
    }
    return $path;
}

function relay_duration_ms($path) {
    return (int)round((MediaProbe::duration((string)$path, 25) ?? 0) * 1000);
}
function relay_fix_text($s) {
    $s = (string)$s;
    if ($s === '') return $s;
    static $map = array(
        "\xC3\x94\xC3\x87\xC3\xB6" => "\xE2\x80\x94",
        "\xC3\x94\xC3\x87\xC3\xB4" => "\xE2\x80\x93",
        "\xC3\x94\xC3\x87\xC3\xA6" => "\xE2\x80\xA6",
    );
    $s = strtr($s, $map);
    $s = preg_replace('/(?:\s*[\x{2014}\x{2013}\-]\s*)+$/u', '', $s);
    $s = preg_replace('/\s{2,}/u', ' ', (string)$s);
    return trim((string)$s);
}

function relay_episode_label($title) {
    $title = relay_fix_text($title);
    $norm = str_replace(
        array("\xE2\x80\x94", "\xE2\x80\x93", "\xC3\x94\xC3\x87\xC3\xB6", "\xC3\x94\xC3\x87\xC3\xB4", " - "),
        " | ",
        $title
    );
    if (preg_match('/[Ss]\d{1,2}[Ee]\d{1,3}\s*\|\s*(.+)$/u', $norm, $m)) {
        $name = trim($m[1]);
        $name = preg_replace('/\s*\([^)]*\)\s*$/u', '', $name);
        $name = trim((string)$name, " \t|-");
        $name = relay_fix_text($name);
        if ($name !== '') {
            $name = preg_replace('/\s*\((?:\d{3,4}p|WEB|BluRay|HDTV|DSNP|AMZN)[^)]*\)\s*$/i', '', $name);
            $name = preg_replace('/\b(?:\d{3,4}p|WEB-?DL|x264|x265|DSNP|AMZN|MeM\.?GP)\b.*/i', '', $name);
            $name = trim(preg_replace('/[._]+/', ' ', (string)$name));
            $name = relay_fix_text($name);
            if ($name !== '') return $name;
        }
    }
    return $title;
}

function relay_is_tv($item) {
    $type = isset($item['type']) ? $item['type'] : '';
    if ($type === 'tv' || $type === 'anime') {
        return true;
    }
    return !empty($item['show']) && isset($item['season']) && $item['season'] !== null && $item['season'] !== '';
}


function relay_show_id($show) {
    return 'show:' . md5(strtolower(trim((string)$show)));
}

function relay_public_show_hit($show, $episodes) {
    $first = $episodes[0];
    $seasons = array();
    foreach ($episodes as $ep) {
        if (!array_key_exists('season', $ep) || $ep['season'] === null || $ep['season'] === '') {
            $key = 'x';
        } else {
            $key = (string)(int)$ep['season'];
        }
        if (!isset($seasons[$key])) {
            $seasons[$key] = 0;
        }
        $seasons[$key]++;
    }
    ksort($seasons, SORT_NATURAL);
    $seasonList = array();
    foreach ($seasons as $k => $n) {
        $seasonList[] = array(
            'season' => ($k === 'x' ? null : (int)$k),
            'episode_count' => $n,
        );
    }
    return array(
        'id' => relay_show_id($show),
        'group' => 'show',
        'title' => (string)$show,
        'episode_title' => (string)$show,
        'show' => (string)$show,
        'season' => null,
        'episode' => null,
        'year' => array_key_exists('year', $first) ? $first['year'] : null,
        'section' => isset($first['section']) ? $first['section'] : '',
        'type' => isset($first['type']) ? $first['type'] : '',
        'ext' => '',
        'rel' => '',
        'media_kind' => 'video',
        'needs_convert' => false,
        'url' => '',
        'episode_count' => count($episodes),
        'season_count' => count($seasonList),
        'seasons' => $seasonList,
    );
}

function relay_public_hit($item, $withDuration) {
    $id = (string)$item['id'];
    $tv = relay_is_tv($item);
    $path = relay_play_path($item);
    $ext = isset($item['ext']) ? strtolower((string)$item['ext']) : '';
    $playExt = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    $title = isset($item['title']) ? (string)$item['title'] : '';
    $epTitle = '';
    if ($tv) {
        if (class_exists('Catalogue') && method_exists('Catalogue', 'resolveEpisodeTitle')) {
            $epTitle = Catalogue::resolveEpisodeTitle($item);
        }
        if ($epTitle === '' && !empty($item['episode_title'])) {
            $epTitle = relay_fix_text((string)$item['episode_title']);
        }
        if ($epTitle === '') {
            $epTitle = relay_episode_label($title);
        }
        $epTitle = relay_fix_text($epTitle);
    }
    $hit = array(
        'id' => $id,
        'title' => $tv && $epTitle !== '' ? $epTitle : relay_fix_text($title),
        'episode_title' => $tv ? ($epTitle !== '' ? $epTitle : relay_episode_label($title)) : relay_fix_text($title),
        'show' => isset($item['show']) ? $item['show'] : null,
        'season' => array_key_exists('season', $item) ? $item['season'] : null,
        'episode' => array_key_exists('episode', $item) ? $item['episode'] : null,
        'year' => array_key_exists('year', $item) ? $item['year'] : null,
        'section' => isset($item['section']) ? $item['section'] : '',
        'type' => isset($item['type']) ? $item['type'] : '',
        'ext' => $ext,
        'rel' => isset($item['rel']) ? $item['rel'] : '',
        'media_kind' => isset($item['media_kind']) ? $item['media_kind'] : '',
        'needs_convert' => ($playExt !== '' && !relay_browser_ext($playExt)),
        'url' => \Vaultcast\Database::basePath() . 'api/live/file?id=' . rawurlencode($id),
    );
    if ($withDuration) {
        $hit['durationMs'] = relay_duration_ms($path);
        $hit['playable'] = ($path !== '' && is_file($path));
        $hit['play_ext'] = $playExt;
    }
    return $hit;
}

function relay_catalogue_query($get) {
    relay_stream_boot();
    $cat = Catalogue::get();
    $id = isset($get['id']) ? trim((string)$get['id']) : '';
    $showParam = isset($get['show']) ? trim((string)$get['show']) : '';

    if ($id !== '' && strpos($id, 'show:') === 0) {
        foreach (($cat['items'] ?? array()) as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ((isset($item['media_kind']) ? $item['media_kind'] : '') !== 'video') {
                continue;
            }
            if (!relay_is_tv($item)) {
                continue;
            }
            $show = isset($item['show']) ? trim((string)$item['show']) : '';
            if ($show !== '' && relay_show_id($show) === $id) {
                $showParam = $show;
                break;
            }
        }
        if ($showParam === '') {
            return array('ok' => false, 'error' => 'Show not in the video catalogue');
        }
    }

    if ($showParam !== '') {
        require_once __DIR__ . '/formats.php';
        $showFormats = relay_clean_formats(isset($get['formats']) ? $get['formats'] : '');
        $eps = array();
        foreach (($cat['items'] ?? array()) as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ($showFormats && !in_array(relay_item_format($item), $showFormats, true)) {
                continue;
            }
            if ((isset($item['media_kind']) ? $item['media_kind'] : '') !== 'video') {
                continue;
            }
            if (!relay_is_tv($item)) {
                continue;
            }
            $show = isset($item['show']) ? trim((string)$item['show']) : '';
            if ($show !== $showParam) {
                continue;
            }
            $eps[] = $item;
        }
        if (!$eps) {
            return array('ok' => false, 'error' => 'Show not in the video catalogue');
        }
        usort($eps, function ($a, $b) {
            $asea = (int)(isset($a['season']) ? $a['season'] : 0);
            $bsea = (int)(isset($b['season']) ? $b['season'] : 0);
            if ($asea !== $bsea) {
                return $asea - $bsea;
            }
            $aep = (int)(isset($a['episode']) ? $a['episode'] : 0);
            $bep = (int)(isset($b['episode']) ? $b['episode'] : 0);
            if ($aep !== $bep) {
                return $aep - $bep;
            }
            return strcasecmp((string)(isset($a['title']) ? $a['title'] : ''), (string)(isset($b['title']) ? $b['title'] : ''));
        });
        $out = array();
        foreach ($eps as $item) {
            $out[] = relay_public_hit($item, false);
        }
        return array(
            'ok' => true,
            'group' => 'show',
            'show' => $showParam,
            'id' => relay_show_id($showParam),
            'total' => count($out),
            'items' => $out,
        );
    }

    if ($id !== '') {
        $item = Catalogue::item($id);
        if (!$item || (isset($item['media_kind']) ? $item['media_kind'] : '') !== 'video') {
            return array('ok' => false, 'error' => 'Not in the video catalogue');
        }
        return array('ok' => true, 'item' => relay_public_hit($item, true));
    }

    $q = isset($get['q']) ? trim((string)$get['q']) : '';
    $section = isset($get['section']) ? trim((string)$get['section']) : '';
    $page = isset($get['page']) ? (int)$get['page'] : 1;
    if ($page < 1) {
        $page = 1;
    }
    $per = isset($get['per']) ? (int)$get['per'] : 30;
    if ($per < 1) {
        $per = 30;
    }
    if ($per > 60) {
        $per = 60;
    }

    $sections = array();
    foreach (($cat['sections'] ?? array()) as $sec) {
        if (!is_array($sec)) {
            continue;
        }
        $type = isset($sec['type']) ? $sec['type'] : '';
        if ($type === 'roms') {
            continue;
        }
        $sections[] = array(
            'id' => isset($sec['id']) ? $sec['id'] : '',
            'label' => isset($sec['label']) ? $sec['label'] : (isset($sec['id']) ? $sec['id'] : ''),
            'type' => $type,
            'count' => isset($sec['count']) ? $sec['count'] : 0,
        );
    }

    require_once __DIR__ . '/formats.php';
    $formats = relay_clean_formats(isset($get['formats']) ? $get['formats'] : '');
    $formatCounts = array();
    $ql = function_exists('mb_strtolower') ? mb_strtolower($q) : strtolower($q);
    $movies = array();
    $shows = array();
    foreach (($cat['items'] ?? array()) as $item) {
        if (!is_array($item)) {
            continue;
        }
        if ((isset($item['media_kind']) ? $item['media_kind'] : '') !== 'video') {
            continue;
        }
        if ((isset($item['type']) ? $item['type'] : '') === 'roms') {
            continue;
        }
        if ($section !== '' && (isset($item['section']) ? (string)$item['section'] : '') !== $section) {
            continue;
        }
        $ext = relay_item_format($item);
        if ($ext !== '') $formatCounts[$ext] = ($formatCounts[$ext] ?? 0) + 1;
        if ($formats && !in_array($ext, $formats, true)) {
            continue;
        }
        if ($ql !== '') {
            $hay = (isset($item['title']) ? $item['title'] : '') . ' '
                . (isset($item['show']) ? $item['show'] : '') . ' '
                . (isset($item['rel']) ? $item['rel'] : '');
            $hay = function_exists('mb_strtolower') ? mb_strtolower($hay) : strtolower($hay);
            if (strpos($hay, $ql) === false) {
                continue;
            }
        }
        if (relay_is_tv($item)) {
            $show = isset($item['show']) ? trim((string)$item['show']) : '';
            if ($show === '') {
                $show = isset($item['title']) ? trim((string)$item['title']) : 'Untitled show';
            }
            if (!isset($shows[$show])) {
                $shows[$show] = array();
            }
            $shows[$show][] = $item;
        } else {
            $movies[] = $item;
        }
    }

    $rows = array();
    foreach ($movies as $item) {
        $rows[] = array('sort' => strtolower((string)(isset($item['title']) ? $item['title'] : '')), 'hit' => relay_public_hit($item, false));
    }
    foreach ($shows as $showName => $eps) {
        $rows[] = array('sort' => strtolower((string)$showName), 'hit' => relay_public_show_hit($showName, $eps));
    }
    usort($rows, function ($a, $b) {
        if ($a['sort'] === $b['sort']) {
            return 0;
        }
        return $a['sort'] < $b['sort'] ? -1 : 1;
    });

    $total = count($rows);
    $slice = array_slice($rows, ($page - 1) * $per, $per);
    $out = array();
    foreach ($slice as $row) {
        $out[] = $row['hit'];
    }
    return array(
        'ok' => true,
        'query' => $q,
        'section' => $section,
        'page' => $page,
        'per' => $per,
        'total' => $total,
        'has_more' => ($page * $per) < $total,
        'sections' => $sections,
        'formats' => relay_format_list($formatCounts),
        'format_filter' => $formats,
        'items' => $out,
        'grouped' => true,
    );
}

function relay_catalogue_file() {
    relay_stream_boot();
    $id = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
    if (!preg_match('/^[a-f0-9]+$/', $id)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bad id';
        return;
    }
    $item = Catalogue::item($id);
    if (!$item || (isset($item['media_kind']) ? $item['media_kind'] : '') !== 'video') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        return;
    }
    $path = relay_play_path($item);
    if ($path === '' || !is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Missing';
        return;
    }
    $viewer = AreaAccess::viewer();
    $token = Stream::createToken(Access::liveFeedValid() ? 'live-feed' : ($viewer ? (string)$viewer['id'] : 'live'), $id, $path, 'video', 'live');
    header('Cache-Control: no-store');
    header('Location: ' . \Vaultcast\Database::basePath() . 'api/stream/play?t=' . $token, true, 302);
}
