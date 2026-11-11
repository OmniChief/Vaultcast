<?php
declare(strict_types=1);

header('Cache-Control: private, no-store');
if (preg_match('#^/linked-peer/([a-f0-9]{32})/(redeem|catalogue|ticket|media|copy-info|copy)$#D', $path, $peer)) {
    LinkedServers::peer($peer[1], $peer[2], $method);
}

$viewer = Auth::requireUser();
if (!LinkedServers::allowed($viewer)) json_error('Linked libraries are unavailable for this account.', 403);

try {
    if ($path === '/linked-servers/settings' && $method === 'GET') {
        Auth::requireAdmin();
        json_out(['ok' => true] + LinkedServers::settings());
    }
    if ($path === '/linked-servers/settings' && $method === 'POST') {
        Auth::requireAdmin();
        $body = body_json();
        $action = $body['action'] ?? '';
        if ($action === 'invite') json_out(['ok' => true] + LinkedServers::createInvite($body));
        if ($action === 'storage') {
            LinkedDownloads::setStorage((string)($body['path'] ?? ''));
            json_out(['ok' => true]);
        }
        if ($action === 'connect') {
            LinkedServers::connect($body);
            json_out(['ok' => true]);
        }
        if (in_array($action, ['incoming', 'outgoing'], true) && preg_match('/^[a-f0-9]{32}$/D', (string)($body['id'] ?? ''))) {
            LinkedServers::remove($action, $body['id']);
            json_out(['ok' => true]);
        }
        json_error('Unknown linked server action.', 422);
    }
    if ($path === '/linked-servers' && $method === 'GET') {
        json_out(['ok' => true, 'links' => LinkedServers::incoming()]);
    }
    if ($path === '/linked-servers/copies' && $method === 'GET') {
        json_out(['ok' => true, 'copies' => LinkedDownloads::list(($viewer['role'] ?? '') === 'admin')]);
    }
    if (preg_match('#^/linked-servers/copies/([a-f0-9]{24})/(file|retry|remove)$#D', $path, $copy)) {
        if ($copy[2] === 'file' && in_array($method, ['GET', 'HEAD'], true)) LinkedDownloads::serve($copy[1], isset($_GET['download']));
        if ($method === 'POST' && in_array($copy[2], ['retry', 'remove'], true)) {
            Auth::requireAdmin();
            if ($copy[2] === 'retry') LinkedDownloads::retry($copy[1]);
            else LinkedDownloads::remove($copy[1]);
            json_out(['ok' => true]);
        }
    }
    if (preg_match('#^/linked-servers/([a-f0-9]{32})/catalogue$#D', $path, $link) && $method === 'GET') {
        json_out(['ok' => true] + LinkedServers::browse(LinkedServers::incomingLink($link[1]), (string)($_GET['q'] ?? ''), LinkedServers::page($_GET['page'] ?? 1)));
    }
    if (preg_match('#^/linked-servers/([a-f0-9]{32})/media$#D', $path, $link) && in_array($method, ['GET', 'HEAD'], true)) {
        LinkedServers::relay(LinkedServers::incomingLink($link[1]), (string)$viewer['id'], $method);
    }
    if (preg_match('#^/linked-servers/([a-f0-9]{32})/(play|progress|download)$#D', $path, $link) && $method === 'POST') {
        $body = body_json();
        $item = $body['item'] ?? '';
        if (!LinkedServers::itemId($item)) json_error('Invalid linked video.', 422);
        $source = LinkedServers::incomingLink($link[1]);
        $viewerId = (string)$viewer['id'];
        if ($link[2] === 'play') json_out(['ok' => true] + LinkedServers::play($source, $item, $viewerId));
        if ($link[2] === 'download') {
            Auth::requireAdmin();
            LinkedDownloads::queue($source, $item);
            json_out(['ok' => true]);
        }
        LinkedServers::saveProgress($source, $item, $viewerId, $body);
        json_out(['ok' => true]);
    }
} catch (DomainException $error) {
    json_error($error->getMessage(), 422);
}
json_error('Linked server endpoint not found.', 404);
