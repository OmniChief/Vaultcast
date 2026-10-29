<?php
declare(strict_types=1);

final class StartupFailure
{
    public static function register(): void
    {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        set_exception_handler([self::class, 'handle']);
    }

    public static function handle(Throwable $error): void
    {
        $unavailable = $error instanceof PDOException && (
            in_array((int)($error->errorInfo[1] ?? 0), [2002, 2003, 2006, 2013], true)
            || in_array((string)$error->getCode(), ['2002', '2003', '2006', '2013'], true)
            || strpos((string)$error->getCode(), '08') === 0
        );
        $busy=$error instanceof PDOException && (in_array((int)($error->errorInfo[1] ?? 0),[1213,1205],true) || (string)$error->getCode()==='40001');
        // Never put a connection exception's trace or constructor arguments in the response/log.
        error_log('Vaultcast: ' . ($unavailable ? 'database unavailable' : ($busy ? 'database busy' : 'request failed')) . ' (' . get_class($error) . ', code ' . $error->getCode() . ') ' . json_encode(['driver_code'=>$error instanceof PDOException ? ($error->errorInfo[1] ?? null) : null,'path'=>parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'),PHP_URL_PATH),'at'=>basename($error->getFile()).':'.$error->getLine()]));
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $unavailable ? "Vaultcast cannot reach MariaDB. Start the database service and retry.\n" : "Vaultcast could not complete this operation. Check the server log.\n");
            exit(1);
        }
        if (headers_sent()) exit;
        http_response_code($unavailable || $busy ? 503 : 500);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
        if ($unavailable || $busy) header('Retry-After: '.($busy ? '1' : '5'));
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string)parse_url($uri, PHP_URL_PATH);
        if (preg_match('#/api(?:/|$)#', $path)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false, 'code'=>$unavailable ? 'database_unavailable' : ($busy ? 'database_busy' : 'server_error'),
                'error'=>$unavailable ? 'MariaDB is unavailable. It may still be starting after reboot. Try again shortly.' : ($busy ? 'Database transaction was busy. Please retry shortly.' : 'The server could not complete this operation. Check the server log.'),
                'retry_after'=>$busy ? 1 : ($unavailable ? 5 : null)]);
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        $retry = $unavailable && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','HEAD'], true);
        $title = $unavailable ? 'Waiting for the database' : 'Vaultcast is unavailable';
        $message = $unavailable
            ? 'Vaultcast cannot reach MariaDB. It may still be starting after reboot. If this continues, check that the MariaDB service is running.'
            : 'The server could not complete this operation. Check the server log.';
        $url = substr($uri, 0, 1) === '/' && substr($uri, 0, 2) !== '//' && !preg_match('/[\x00-\x1f\\\\]/', $uri) ? $uri : '/';
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . ($retry ? '<meta http-equiv="refresh" content="5">' : '') . '<title>' . $title . '</title>'
            . '<body style="font-family:system-ui;background:#0b1018;color:#edf2f7;display:grid;place-items:center;min-height:100vh;margin:0;padding:24px;box-sizing:border-box">'
            . '<main style="max-width:560px"><h1>' . $title . '</h1><p>' . $message . '</p>'
            . ($retry ? '<p>This page retries automatically every five seconds.</p>' : '')
            . '<p><a style="color:#9dc7ff" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Try again</a></p></main></body></html>';
        exit;
    }
}
