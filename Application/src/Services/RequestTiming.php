<?php
declare(strict_types=1);

/** Numeric, request-local DevTools timings. Never exposes SQL, paths or identities. */
final class RequestTiming {
    private static $started = 0.0;
    private static $durations = [];
    private static $counts = [];

    public static function begin(): void {
        self::$started = microtime(true);
        if (PHP_SAPI !== 'cli') header_register_callback(function () { header('Server-Timing: ' . self::value()); });
    }
    public static function add(string $name, float $started): void {
        if (!preg_match('/^[a-z_]+$/D', $name)) return;
        self::$durations[$name] = (self::$durations[$name] ?? 0.0) + max(0, (microtime(true) - $started) * 1000);
    }
    public static function count(string $name): void {
        if (preg_match('/^[a-z_]+$/D', $name)) self::$counts[$name] = (self::$counts[$name] ?? 0) + 1;
    }
    public static function value(): string {
        $total = max(0, (microtime(true) - (self::$started ?: microtime(true))) * 1000);
        $parts = ['app;dur=' . number_format($total, 2, '.', '')];
        foreach (self::$durations as $name => $ms) $parts[] = $name . ';dur=' . number_format($ms, 2, '.', '');
        foreach (self::$counts as $name => $n) $parts[] = $name . ';desc="' . $n . '"';
        return implode(', ', $parts);
    }
}
