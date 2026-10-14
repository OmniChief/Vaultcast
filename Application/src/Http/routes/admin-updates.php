<?php
declare(strict_types=1);
// Admin-only (routes.php runs Auth::requireAdmin() for /admin/*): compare with main and update file by file.

if ($path === '/admin/updates' && $method === 'GET') {
    $r = Updater::check();
    unset($r['_files']);
    json_out($r);
}

if ($path === '/admin/updates/apply' && $method === 'POST') {
    $b = body_json();
    $c = Updater::check();
    if (!$c['ok']) json_error($c['error'] ?? 'Update check failed.', 502);
    $r = Updater::apply($c, (string)($b['tree'] ?? ''));
    error_log('Vaultcast update ' . ($r['ok'] ? 'applied ' . ($r['from'] ?? '') . ' -> ' . ($r['to'] ?? '') . ' (tree ' . ($r['tree'] ?? '') . ')' : 'failed: ' . ($r['error'] ?? '')));
    json_out($r, $r['ok'] ? 200 : (($r['code'] ?? '') === 'changed' ? 409 : 400));
}
