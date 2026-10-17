<?php
declare(strict_types=1);
/**
 * Drop PHP's native session file lock (if any) as soon as auth/session reads are done.
 * Vaultcast's own sessions are DB cookies, but installer pages and any future session_start
 * would otherwise hold the lock for the whole request — including multi-minute media streams.
 * Safe to call repeatedly; no-op when no native session is active.
 */
function release_request_locks(): void {
    if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
}

function json_out($data, int $code = 200): void {
    release_request_locks();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $started = microtime(true);
    $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (class_exists('RequestTiming', false)) RequestTiming::add('json', $started);
    echo class_exists('AssetDelivery', false) ? AssetDelivery::text((string)$encoded) : $encoded;
    exit;
}

function json_error(string $msg, int $code = 400): void {
    json_out(['ok' => false, 'error' => $msg], $code);
}

function body_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function request_path(): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);
    $prefix = rtrim(\Vaultcast\Database::basePath(), '/') . '/api';
    $path = is_string($path) ? $path : '/';
    if ($path === $prefix || strpos($path, $prefix . '/') === 0) $path = substr($path, strlen($prefix));
    return $path === '' || $path === false ? '/' : $path;
}

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function guest_user(): array {
    return [
        'id' => 'public',
        'username' => 'public',
        'role' => 'guest',
        'display_name' => 'Guest',
        'prefs' => ['theme'=>'dark','autoplay'=>false],
    ];
}

function current_or_guest(): array {
    $sess = Auth::currentSession();
    if ($sess) {
        $user = Auth::findById($sess['user_id']);
        if ($user) {
            unset($user['password_hash']);
            $user['_session'] = $sess;
            return $user;
        }
    }
    return guest_user();
}


function lobby_display_name(): string {
    $sess = Auth::currentSession();
    if ($sess) {
        $u = Auth::findById((string)($sess['user_id'] ?? ''));
        if ($u) {
            $n = trim((string)($u['display_name'] ?? ''));
            if ($n === '') $n = trim((string)($u['username'] ?? ''));
            if ($n !== '') return $n;
        }
    }
    return '';
}
