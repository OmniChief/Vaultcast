<?php
declare(strict_types=1);
// Admin-only (routes.php runs Auth::requireAdmin() for /admin/*): compare with main and update file by file.

if ($path === '/admin/updates' && $method === 'GET') {
    $branch = $_GET['branch'] ?? null;
    if ($branch !== null && (!is_string($branch) || !Updater::validBranch($branch))) json_error('Invalid update branch.', 400);
    $r = Updater::check($branch);
    unset($r['_files']);
    json_out($r);
}

if ($path === '/admin/updates/apply' && $method === 'POST') {
    $b = body_json();
    $branch = $b['branch'] ?? null;
    if ($branch !== null && (!is_string($branch) || !Updater::validBranch($branch))) json_error('Invalid update branch.', 400);
    if (!is_string($b['tree'] ?? null) || !preg_match('/^[0-9a-f]{40}$/', $b['tree'])) json_error('Check the selected branch before updating.', 400);
    $c = Updater::check($branch);
    if (!$c['ok']) json_error($c['error'] ?? 'Update check failed.', 502);
    $r = Updater::apply($c, $b['tree'], $branch);
    error_log('Vaultcast update ' . ($r['ok'] ? 'applied ' . ($r['from'] ?? '') . ' -> ' . ($r['to'] ?? '') . ' (tree ' . ($r['tree'] ?? '') . ')' : 'failed: ' . ($r['error'] ?? '')));
    json_out($r, $r['ok'] ? 200 : (($r['code'] ?? '') === 'changed' ? 409 : 400));
}
