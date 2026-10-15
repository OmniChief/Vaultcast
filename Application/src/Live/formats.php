<?php
/** Media-format (file extension) helpers for Watch Live → Add from server library. */

/** Normalise a list ("mp4,mkv" or array) to unique lowercase extensions, max 30. */
function relay_clean_formats($v) {
    if (is_string($v)) $v = explode(',', $v);
    if (!is_array($v)) return array();
    $out = array();
    foreach ($v as $x) {
        $x = strtolower(ltrim(trim((string)$x), '.'));
        if ($x !== '' && preg_match('/^[a-z0-9]{1,8}$/', $x) && !in_array($x, $out, true)) $out[] = $x;
        if (count($out) >= 30) break;
    }
    sort($out);
    return $out;
}

/** Extension of a catalogue item (ext field, else from the file path). */
function relay_item_format($item) {
    $e = isset($item['ext']) ? (string)$item['ext'] : '';
    if ($e === '') $e = pathinfo((string)($item['path'] ?? ($item['rel'] ?? '')), PATHINFO_EXTENSION);
    return strtolower(ltrim($e, '.'));
}

/** [{ext, count}] sorted by count desc then name. */
function relay_format_list($counts) {
    $out = array();
    foreach ($counts as $ext => $n) $out[] = array('ext' => (string)$ext, 'count' => (int)$n);
    usort($out, function ($a, $b) { return $a['count'] !== $b['count'] ? $b['count'] - $a['count'] : strcmp($a['ext'], $b['ext']); });
    return $out;
}
