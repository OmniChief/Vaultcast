<?php
/*
 * Vaultcast Windows installer helper.
 *
 * The NSIS installer runs this file with the PHP runtime it has just unpacked,
 * for the few jobs NSIS has no native command for (TCP port probes, random
 * secrets and calls into the application's own PHP setup code). It replaces
 * the PowerShell bootstrap that the previous installer used.
 *
 *   php -n setup-helper.php free <port> <address>       exit 0 when the port looks free (loopback only)
 *   php -n setup-helper.php wait <host> <port>          exit 0 once something listens (~30 s max)
 *   php -n setup-helper.php secret                      prints 64 random hex characters
 *   php -n setup-helper.php ipv4 <address>              exit 0 for a valid IPv4 address
 *   php -c php.ini setup-helper.php provision <root> <database.ini>
 *   php -c php.ini setup-helper.php reset-admin <root> <php.ini> <credentials file>
 *
 * Exit code 0 means success; anything printed goes to the installer log.
 */
declare(strict_types=1);

$command = $argv[1] ?? '';
try {
    switch ($command) {
        case 'free':
            // Never listen on a non-loopback address: that makes Windows Firewall ask
            // the user about "PHP CLI". Instead, a port counts as taken when something
            // answers on loopback or on the target address, or loopback can't be bound.
            $port = (int)($argv[2] ?? 0);
            $address = (string)($argv[3] ?? '0.0.0.0');
            foreach (array_unique(['127.0.0.1', $address]) as $host) {
                if ($host === '0.0.0.0') continue;
                $client = @fsockopen($host, $port, $code, $message, 0.5);
                if ($client) { fclose($client); exit(1); }
            }
            $server = @stream_socket_server('tcp://127.0.0.1:' . $port, $code, $message);
            if ($server === false) exit(1);
            fclose($server);
            exit(0);

        case 'wait':
            for ($attempt = 0; $attempt < 40; $attempt++) {
                $client = @fsockopen((string)$argv[2], (int)$argv[3], $code, $message, 0.5);
                if ($client) { fclose($client); exit(0); }
                usleep(250000);
            }
            exit(1);

        case 'secret':
            echo bin2hex(random_bytes(32));
            exit(0);

        case 'ipv4':
            exit(filter_var($argv[2] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? 1 : 0);

        case 'provision':
            // Creates the private database and account through the application's own code.
            $root = rtrim((string)$argv[2], '\\/');
            require $root . '/src/Database.php';
            require $root . '/src/Installer.php';
            $settings = parse_ini_file((string)$argv[3], false, INI_SCANNER_RAW);
            if (!is_array($settings)) throw new RuntimeException('Cannot read the saved database settings.');
            \Vaultcast\Installer::prepareWindowsDatabase($settings);
            echo "Private library database is ready.\n";
            exit(0);

        case 'reset-admin':
            // The installer writes "username\npassword" as UTF-16LE (NSIS native text) to a
            // temporary file so the password never appears on a command line.
            [$root, $ini, $file] = [rtrim((string)$argv[2], '\\/'), (string)$argv[3], (string)$argv[4]];
            $raw = (string)file_get_contents($file);
            @unlink($file);
            if (strncmp($raw, "\xFF\xFE", 2) === 0) $raw = substr($raw, 2);
            $text = function_exists('mb_convert_encoding') ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : iconv('UTF-16LE', 'UTF-8', $raw);
            [$username, $password] = array_pad(explode("\n", (string)$text, 2), 2, '');
            $process = proc_open([PHP_BINARY, '-c', $ini, $root . '/src/Console.php', 'reset-admin'],
                [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $root);
            if (!is_resource($process)) throw new RuntimeException('Cannot start the Vaultcast console.');
            fwrite($pipes[0], json_encode(['username' => rtrim($username, "\r"), 'password' => $password]));
            fclose($pipes[0]);
            exit(proc_close($process) === 0 ? 0 : 1);
    }
    fwrite(STDERR, "Unknown command.\n");
    exit(2);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
