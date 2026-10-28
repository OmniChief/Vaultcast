<?php
declare(strict_types=1);
header('Cache-Control: no-store');
try {
    if (preg_match('#^/ai/worker/register$#D', $path)) AIImages::worker('register');
    if (preg_match('#^/ai/worker/(heartbeat|claim)$#D', $path, $m)) AIImages::worker($m[1]);
    if ($path==='/ai/worker/encoding/claim') AIImages::worker('encoding-claim');
    if (preg_match('#^/ai/worker/encoding/jobs/([a-f0-9]{16})/(source|status|progress|chunk|complete|fail)$#D',$path,$m)) AIImages::worker('encoding-'.$m[2],$m[1]);
    if (preg_match('#^/ai/worker/jobs/([a-f0-9]{16})/(progress|complete|fail)$#D', $path, $m)) AIImages::worker($m[2], $m[1]);

    $user = Auth::requireUser();
    if ($path === '/ai/moderation/library' && $method === 'GET') json_out(AIImages::moderationLibrary(Auth::requireAdmin(), $_GET));
    if (preg_match('#^/ai/moderation/pictures/([a-f0-9]{16})/delete$#D', $path, $m) && $method === 'POST') json_out(AIImages::moderationDelete(Auth::requireAdmin(), $m[1]));
    if (preg_match('#^/ai/moderation/pictures/([a-f0-9]{16})$#D', $path, $m) && in_array($method, ['GET','HEAD'], true)) AIImages::moderationPicture(Auth::requireAdmin(), $m[1]);
    if ($path === '/ai/state' && $method === 'GET') json_out(AIImages::state($user));
    if ($path === '/ai/library' && $method === 'GET') json_out(AIImages::library($user, $_GET));
    if (preg_match('#^/ai/library/([a-f0-9]{16})/favorite$#D', $path, $m) && $method === 'POST') json_out(AIImages::favorite($user, $m[1], filter_var(body_json()['favorite'] ?? false, FILTER_VALIDATE_BOOLEAN)));
    if ($path === '/ai/library/delete' && $method === 'POST') json_out(AIImages::deleteLibrary($user, (array)(body_json()['ids'] ?? [])));
    if ($path === '/ai/pair' && $method === 'POST') json_out(AIImages::pair($user));
    if ($path === '/ai/jobs' && $method === 'POST') json_out(AIImages::createJob($user, body_json()), 201);
    if (preg_match('#^/ai/jobs/([a-f0-9]{16})/cancel$#D', $path, $m) && $method === 'POST') json_out(AIImages::cancel($user, $m[1]));
} catch (DomainException $e) { json_error($e->getMessage(), 422); }
json_error('AI endpoint not found.', 404);
