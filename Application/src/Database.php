<?php
declare(strict_types=1);

namespace Vaultcast;
require_once __DIR__ . '/Paths.php';

/** One connection per request. No dependency manager or PHP extension beyond PDO MySQL. */
final class Database
{
    private static $pdo;
    private static $config;
    private static $operationLock;
    public static function configFile(): string {
        $legacy = dirname(__DIR__) . '/config.php';
        return is_file($legacy) ? $legacy : dirname(__DIR__) . '/data/config.php';
    }

    public static function reloadConfiguration(): void {
        clearstatcache();
        self::$config = null;
        self::$pdo = null;
    }

    public static function config(): array
    {
        if (self::$config !== null) return self::$config;
        $file = self::configFile();
        // Do not cache a missing file: setup creates it during the same request.
        if (!is_file($file)) return [];
        self::$config = require $file;
        return self::$config;
    }

    public static function installed(): bool
    {
        clearstatcache(true, self::configFile());
        return is_file(self::configFile());
    }

    public static function connect(array $config, bool $selectDatabase = true): \PDO
    {
        $db = $config['database'];
        foreach (['host', 'name'] as $key) {
            if (preg_match('/[;\x00-\x1f]/', (string)$db[$key])) {
                throw new \InvalidArgumentException('Invalid database address or name.');
            }
        }
        $dsn = 'mysql:host=' . $db['host'] . ';port=' . (int)$db['port'] . ';charset=utf8mb4';
        if ($selectDatabase) $dsn .= ';dbname=' . $db['name'];
        return new \PDO($dsn, $db['user'], $db['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    public static function connection(): \PDO
    {
        if (!self::$operationLock) {
            $lock = fopen(dirname(__DIR__) . '/data/database-operation.lock', 'c');
            if (!$lock || !flock($lock, LOCK_SH | LOCK_NB)) throw new \RuntimeException('A database reset is in progress. Try again shortly.');
            self::$operationLock = $lock;
        }
        if (!self::$pdo) self::$pdo = self::connect(self::config());
        return self::$pdo;
    }

    public static function lockForReset(): void
    {
        self::connection();
        if (!flock(self::$operationLock, LOCK_EX | LOCK_NB)) {
            flock(self::$operationLock, LOCK_SH | LOCK_NB);
            throw new \RuntimeException('Vaultcast is busy. Stop playback and background tasks, then retry the reset.');
        }
    }

    public static function basePath(): string
    {
        return self::config()['base_path'] ?? '/';
    }

    public static function transaction(callable $operation)
    {
        $pdo = self::connection();
        // Services may compose repository operations inside one transaction.
        if ($pdo->inTransaction()) return $operation($pdo);
        $pdo->beginTransaction();
        try {
            $result = $operation($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
