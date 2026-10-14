<?php
declare(strict_types=1);
if ($path === '/diagnostics' && $method === 'GET') {
    json_out(Diagnostics::listTests(current_or_guest()));
}

if ($path === '/diagnostics' && $method === 'POST') {
    $b = body_json();
    $id = trim((string)($b['id'] ?? $_GET['id'] ?? ''));
    if ($id !== '') {
        json_out(Diagnostics::runOne($id, current_or_guest()));
    }
    json_out(Diagnostics::run(current_or_guest()));
}

if (preg_match('#^/diagnostics/([a-z0-9_]+)$#', $path, $m) && $method === 'POST') {
    json_out(Diagnostics::runOne($m[1], current_or_guest()));
}
