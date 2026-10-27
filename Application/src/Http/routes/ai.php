<?php
declare(strict_types=1);
header('Cache-Control: no-store');
try {
    if (preg_match('#^/ai/worker/register$#D', $path)) AIImages::worker('register');
    if (preg_match('#^/ai/worker/(heartbeat|claim)$#D', $path, $m)) AIImages::worker($m[1]);
    if (preg_match('#^/ai/worker/jobs/([a-f0-9]{16})/(progress|complete|fail)$#D', $path, $m)) AIImages::worker($m[2], $m[1]);

    $user = Auth::requireUser();
    if ($path === '/ai/state' && $method === 'GET') json_out(AIImages::state($user));
    if ($path === '/ai/pair' && $method === 'POST') json_out(AIImages::pair($user));
    if ($path === '/ai/jobs' && $method === 'POST') json_out(AIImages::createJob($user, body_json()), 201);
    if (preg_match('#^/ai/jobs/([a-f0-9]{16})/cancel$#D', $path, $m) && $method === 'POST') json_out(AIImages::cancel($user, $m[1]));
} catch (DomainException $e) { json_error($e->getMessage(), 422); }
json_error('AI endpoint not found.', 404);
