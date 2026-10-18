<?php
declare(strict_types=1);
namespace Vaultcast;

final class SetupInputException extends \RuntimeException
{
    public $step;
    public function __construct(string $message, int $step)
    {
        parent::__construct($message);
        $this->step = $step;
    }
}

final class Installer
{
    /** Called only by the elevated native Windows installer, with secrets on stdin. */
    public static function prepareWindowsDatabase(array $input): void
    {
        if (PHP_SAPI !== 'cli') throw new \RuntimeException('Native setup only.');
        $connection = ['host' => '127.0.0.1', 'port' => (int)$input['port'],
            'name' => (string)$input['name'], 'user' => (string)$input['user'], 'password' => (string)$input['password']];
        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $connection['name']) || !preg_match('/^rk_[a-f0-9]{12}$/', $connection['user'])) {
            throw new \RuntimeException('Invalid managed database configuration.');
        }
        $root = $connection;
        $root['user'] = 'root';
        $root['password'] = (string)$input['rootPassword'];
        $pdo = null;
        for ($attempt = 0; $attempt < 30; $attempt++) {
            try { $pdo = Database::connect(['database' => $root], false); break; }
            catch (\PDOException $error) { if ($attempt === 29) throw new \RuntimeException('MariaDB did not become available for setup.'); sleep(1); }
        }
        $name = '`' . $connection['name'] . '`';
        $account = $pdo->quote($connection['user']) . "@'localhost'";
        $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . $name . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('CREATE USER IF NOT EXISTS ' . $account . ' IDENTIFIED BY ' . $pdo->quote($connection['password']));
        $pdo->exec('GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,INDEX ON ' . $name . '.* TO ' . $account);
        Database::connect(['database' => $connection]);
        $file = dirname(__DIR__) . '/data/setup-database.php';
        $temporary = $file . '.tmp';
        $body = "<?php return " . var_export($connection, true) . ";\n";
        if (file_put_contents($temporary, $body, LOCK_EX) !== strlen($body)) throw new \RuntimeException('Cannot save the database connection.');
        chmod($temporary, 0600);
        if (!rename($temporary, $file)) throw new \RuntimeException('Cannot activate the database connection.');
    }

    public static function checks(): array
    {
        $root = dirname(__DIR__);
        return [
            'PHP 7.4 or newer' => version_compare(PHP_VERSION, '7.4.0', '>='),
            'PDO MySQL / MariaDB driver' => extension_loaded('pdo_mysql'),
            'JSON extension' => extension_loaded('json'),
            'PHP sessions' => extension_loaded('session'),
            'Multibyte text extension' => extension_loaded('mbstring'),
            'File information extension' => extension_loaded('fileinfo'),
            'Writable configuration location' => is_writable(is_dir(dirname(Database::configFile())) ? dirname(Database::configFile()) : $root),
            'Writable storage' => is_dir($root . '/data') ? is_writable($root . '/data') : is_writable($root),
        ];
    }

    /** Optional features: label => true (working) or false (missing). Never blocks setup. */
    public static function optionalChecks(): array
    {
        $root = dirname(__DIR__);
        $runtime = $root . '/data/runtime';
        $node = self::findProgram('node', [$runtime . '/node/node.exe', $runtime . '/node-v*-win-x64/node.exe']);
        $ffmpeg = self::findProgram('ffmpeg', [$runtime . '/ffmpeg/bin/ffmpeg.exe', $runtime . '/ffmpeg-*/bin/ffmpeg.exe']);
        $ffprobe = self::findProgram('ffprobe', [$runtime . '/ffmpeg/bin/ffprobe.exe', $runtime . '/ffmpeg-*/bin/ffprobe.exe']);
        $managed = is_readable($runtime . '/transmission.ini') ? @parse_ini_file($runtime . '/transmission.ini', true, INI_SCANNER_RAW) : false;
        $transmission = self::findProgram('transmission-daemon', []) !== null || self::findProgram('transmission-qt', []) !== null;
        foreach ([getenv('ProgramFiles'), getenv('ProgramW6432')] as $programs) {
            if ($programs && is_file($programs . '/Transmission/transmission-qt.exe')) $transmission = true;
        }
        // The relay listens on loopback; the Windows installer records its port in setup.json.
        $saved = is_file($runtime . '/setup.json') ? json_decode((string)file_get_contents($runtime . '/setup.json'), true) : null;
        $port = (int)($saved['netplayPort'] ?? 3100);
        $relay = @fsockopen('127.0.0.1', $port, $code, $message, 0.5);
        if ($relay) fclose($relay);
        $checks = [
            'Node.js runtime for multiplayer' => $node !== null,
            'Multiplayer relay running on port ' . $port => (bool)$relay,
            'FFmpeg (media conversion)' => $ffmpeg !== null,
            'ffprobe (media analysis)' => $ffprobe !== null,
            'PHP cURL (metadata and downloads)' => extension_loaded('curl'),
            'PHP SimpleXML (download feeds)' => extension_loaded('simplexml'),
        ];
        if (is_array($managed) && !empty($managed['transmission']['port'])) {
            // Installed and connected by the Windows or Linux installer: check that RPC accepts the stored credentials.
            $checks['Downloads ready (Transmission)'] = self::transmissionAnswers($managed['transmission']);
        } else {
            $checks['Transmission (optional downloads)'] = $transmission;
        }
        return $checks;
    }

    /** True when Transmission RPC on loopback accepts the credentials (409 = auth ok, session id needed). */
    private static function transmissionAnswers(array $t): bool
    {
        $url = 'http://127.0.0.1:' . (int)$t['port'] . '/transmission/rpc';
        if (!function_exists('curl_init')) {
            $rpc = @fsockopen('127.0.0.1', (int)$t['port'], $code, $message, 0.5);
            if ($rpc) fclose($rpc);
            return (bool)$rpc;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}', CURLOPT_USERPWD => ($t['username'] ?? '') . ':' . ($t['password'] ?? '')]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 409 || $code === 200;
    }

    /** True when the Windows installer's nginx config serves /_vaultcast_media/ (X-Accel-Redirect). */
    private static function windowsMediaAccel(): bool
    {
        $file = dirname(__DIR__) . '/data/runtime/setup.json';
        $setup = is_readable($file) ? json_decode((string)file_get_contents($file), true) : null;
        return is_array($setup) && !empty($setup['mediaAccel']);
    }

    /** True when the bundled Windows installer set up this server's services. */
    public static function nativeManaged(): bool
    {
        // setup.json is written only by the Windows installer. (Linux setup.sh also
        // creates data/setup-database.php, so that file is not a Windows marker.)
        return PHP_OS_FAMILY === 'Windows' && is_file(dirname(__DIR__) . '/data/runtime/setup.json');
    }

    /** First match among bundled paths (globs allowed), then PATH. */
    private static function findProgram(string $name, array $candidates): ?string
    {
        foreach ($candidates as $pattern) {
            foreach (glob($pattern) ?: [] as $file) if (is_file($file)) return $file;
        }
        $suffixes = DIRECTORY_SEPARATOR === '\\' ? ['.exe', '.cmd'] : [''];
        // PHP-FPM clears the environment by default (clear_env), so PATH can be empty;
        // fall back to the standard system directories the services would use.
        $path = (string)getenv('PATH');
        if ($path === '' && DIRECTORY_SEPARATOR === '/') $path = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') continue;
            foreach ($suffixes as $suffix) {
                $file = rtrim($directory, '\\/') . DIRECTORY_SEPARATOR . $name . $suffix;
                if (is_file($file) && is_executable($file)) return $file;
            }
        }
        return null;
    }

    public static function defaults(array $sections): array
    {
        return [
            'app_name' => 'Vaultcast', 'base_path' => Database::basePath(),
            'sections' => $sections,
            'auth' => ['session_ttl_seconds' => 604800],
            'posters'=>['enabled'=>true,'libretro_enabled'=>true],
            // Windows: on when the installer's nginx config has the /_vaultcast_media/ location
            // (setup.json mediaAccel); Store::managedMediaAccel() also applies it to existing installs.
            'streaming' => ['nginx_accel' => PHP_OS_FAMILY !== 'Windows' || self::windowsMediaAccel(), 'token_ttl_seconds' => 28800],
            'scan' => [
                'max_depth' => 12,
                'video_extensions' => ['mp4','mkv','m4v','webm','avi','mov','ts'],
                'audio_extensions' => ['mp3','flac','aac','m4a','ogg','wav'],
                'rom_extensions' => ['nes','fds','sfc','smc','n64','z64','v64','gb','gbc','gba','nds','sms','gg','md','gen','smd','pce','zip','7z','cue','chd','pbp','iso','bin'],
            ],
            'acquisition' => ['enabled' => false],
            'features' => ['live' => true, 'downloads' => false, 'server_tools' => false],
        ];
    }

    public static function install(array $input): void
    {
        if (in_array(false, self::checks(), true)) throw new \RuntimeException('Resolve the required dependency checks first.');
        $root = dirname(__DIR__);
        if (!is_dir($root . '/data') && !mkdir($root . '/data', 0770, true)) throw new \RuntimeException('Cannot create application storage.');
        $lock = fopen($root . '/data/install.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Another installation is running.');
        $temporary = null;
        try {
            if (Database::installed()) throw new \RuntimeException('Vaultcast is already installed.');
            if (!empty($input['managed_database']) && is_file($root . '/data/setup-database.php')) {
                $connection = require $root . '/data/setup-database.php';
                foreach (['host','port','name','user','password'] as $field) $input['db_' . $field] = $connection[$field];
                $input['create_database'] = false;
            }
            $username = trim((string)($input['username'] ?? ''));
            $siteName = trim((string)($input['site_name'] ?? ''));
            if ($siteName === '' || mb_strlen($siteName) > 80) throw new SetupInputException('Choose a site name of 1–80 characters.', 0);
            $password = (string)($input['password'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9_.-]{3,80}$/', $username)) throw new SetupInputException('Use 3–80 letters, numbers, dots, dashes or underscores for the administrator name.', 0);
            if (strlen($password) < 12 || strlen($password) > 72) throw new SetupInputException('Use an administrator password between 12 and 72 bytes.', 0);
            if ($password !== ($input['password_confirm'] ?? '')) throw new SetupInputException('Administrator passwords do not match.', 0);
            $name = trim((string)($input['db_name'] ?? 'vaultcast'));
            if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name)) throw new SetupInputException('Database name may contain only letters, numbers and underscores.', 1);
            $base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
            $base = '/' . trim($base, '/') . '/';
            if ($base === '//') $base = '/';
            $config = ['base_path' => $base, 'database' => [
                'host' => trim((string)($input['db_host'] ?? '127.0.0.1')),
                'port' => max(1, min(65535, (int)($input['db_port'] ?? 3306))),
                'name' => $name, 'user' => (string)($input['db_user'] ?? ''),
                'password' => (string)($input['db_password'] ?? ''),
            ]];
            $sections = [];
            foreach (['movies' => 'Films', 'tv' => 'TV shows', 'anime' => 'Anime', 'music' => 'Music', 'roms' => 'Games'] as $type => $label) {
                if (empty($input['enable_' . $type])) continue;
                $check = \Vaultcast\Paths::check((string)($input['path_' . $type] ?? ''));
                if (!$check['ok']) {
                    throw new SetupInputException($label . ': ' . $check['message'] . ' Your entries have been kept; correct the path and try again.', 2);
                }
                $real = $check['path'];
                $acl = \Vaultcast\Paths::grantWebServerRead($real);
                if (!$acl['ok']) throw new SetupInputException($label . ': ' . $acl['message'] . ' Your entries have been kept; correct the path and try again.', 2);
                $sections[] = ['id' => $type, 'label' => $label, 'type' => $type, 'path' => str_replace('\\', '/', $real), 'enabled' => true, 'order' => count($sections), 'exclude_paths' => []];
            }
            // An empty library is valid; folders can be added in Settings later.
            try {
                $pdo = Database::connect($config, empty($input['create_database']));
                if (!empty($input['create_database'])) {
                    if (!in_array($config['database']['host'], ['localhost','127.0.0.1','::1'], true)) throw new SetupInputException('For a remote database, create its database account on that server and enter the account details here.', 1);
                    $pdo->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                    $pdo->exec('USE `' . $name . '`');
                    $appUser = 'rk_' . bin2hex(random_bytes(6));
                    $appPassword = bin2hex(random_bytes(32));
                    $account = $pdo->quote($appUser) . "@'localhost'";
                    $pdo->exec('CREATE USER ' . $account . ' IDENTIFIED BY ' . $pdo->quote($appPassword));
                    $pdo->exec('GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,INDEX ON `' . $name . '`.* TO ' . $account);
                    $config['database']['user'] = $appUser;
                    $config['database']['password'] = $appPassword;
                    $pdo = Database::connect($config);
                }
            } catch (\PDOException $e) {
                error_log('Vaultcast database setup: ' . $e->getMessage());
                throw new SetupInputException('Cannot connect to MariaDB or create the database. Check the address, credentials and database privileges.', 1);
            }
            $serverVersion = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
            if (!preg_match('/(\d+\.\d+\.\d+)-MariaDB/i', $serverVersion, $version) || version_compare($version[1], '10.2.0', '<')) {
                throw new SetupInputException('This installer requires MariaDB 10.2 or newer.', 1);
            }
            // An installer may recover empty tables from an interrupted attempt, never replace data.
            $existing = $pdo->query("SHOW TABLES LIKE 'vaultcast_documents'")->fetchColumn();
            if ($existing && (int)$pdo->query('SELECT COUNT(*) FROM vaultcast_documents')->fetchColumn() > 0) throw new SetupInputException('This database already contains Vaultcast data. Restore its config.php or choose an empty database.', 1);
            foreach (explode(';', (string)file_get_contents(__DIR__ . '/schema.sql')) as $sql) {
                if (trim($sql) !== '') $pdo->exec($sql);
            }
            foreach (['data', 'data/cache', 'data/lobby', 'data/bios/psx', 'data/live'] as $dir) {
                if (!is_dir($root . '/' . $dir) && !mkdir($root . '/' . $dir, 0770, true)) throw new \RuntimeException('Cannot create application storage: ' . $dir);
            }
            $temporary = dirname(Database::configFile()) . '/.install-' . bin2hex(random_bytes(8)) . '.php';
            $contents = "<?php\n// Private installation configuration. Back up separately from source.\nreturn " . var_export($config, true) . ";\n";
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) throw new \RuntimeException('Cannot save database configuration.');
            chmod($temporary, 0600);
            $settings = self::defaults($sections);
            $settings['app_name'] = $siteName;
            $settings['base_path'] = $base;
            $admin = ['id' => bin2hex(random_bytes(16)), 'username' => $username, 'display_name' => $username, 'role' => 'admin', 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'prefs' => ['theme' => 'dark', 'autoplay' => false]];
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('INSERT INTO vaultcast_documents (name,payload) VALUES (?,?)');
                foreach (['schema_version' => 1, 'settings' => $settings, 'users' => [], 'catalogue' => ['sections' => [], 'scanned_at' => null, 'item_count' => 0]] as $key => $value) $stmt->execute([$key, Repository::encode($value)]);
                $stmt = $pdo->prepare('INSERT INTO vaultcast_users (id,username,password_hash,role,payload) VALUES (?,?,?,?,?)');
                $stmt->execute([$admin['id'], $username, $admin['password_hash'], 'admin', Repository::encode($admin)]);
                // Publish config only after all inserts succeed. The install lock serializes setup.
                if (!rename($temporary, Database::configFile())) throw new \RuntimeException('Cannot activate installation configuration.');
                $temporary = null;
                $pdo->commit();
                Database::reloadConfiguration();
                if (is_file($root . '/data/setup-database.php')) @unlink($root . '/data/setup-database.php');
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if (is_file(Database::configFile())) unlink(Database::configFile());
                throw $e;
            }
        } finally {
            if ($temporary && is_file($temporary)) unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
