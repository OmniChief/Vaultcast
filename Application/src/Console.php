<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$command = $argv[1] ?? 'help';
if ($command === 'help') {
    echo "Usage: php src/Console.php <command>\n\n";
    echo "scan                       Index configured library folders\n";
    echo "collections [id]           Match collection entries to library items\n";
    echo "downloads                  Import completed Transmission downloads\n";
    echo "durations                  Fill missing Live channel media durations\n";
    echo "facts                      Probe new/changed media codecs, store show guides\n";
    echo "metadata                   Prepare artwork and synopses in the background\n";
    exit;
}
require __DIR__ . '/bootstrap.php';
try {
    set_time_limit(0);
    switch ($command) {
        case 'maintenance':
            $result = Maintenance::run($argv[2] ?? '');
            break;
        case 'reset-admin':
            $input = json_decode(stream_get_contents(STDIN), true);
            $user = Auth::findUser((string)($input['username'] ?? ''));
            $password = (string)($input['password'] ?? '');
            if (!$user || ($user['role'] ?? '') !== 'admin') throw new RuntimeException('Administrator account not found.');
            if (strlen($password) < 12 || strlen($password) > 72) throw new RuntimeException('Use a password of 12 to 72 bytes.');
            \Vaultcast\Database::transaction(function ($pdo) use ($user, $password) {
                \Vaultcast\Repository::updateUser($user['id'], function ($record) use ($password) { $record['password_hash'] = password_hash($password, PASSWORD_DEFAULT); return $record; });
                $stmt = $pdo->prepare('DELETE FROM vaultcast_sessions WHERE user_id=?'); $stmt->execute([$user['id']]);
            });
            $result = ['ok' => true];
            break;
        case 'scan':
        case 'collections':
        case 'downloads':
        case 'durations':
        case 'facts':
        case 'metadata':
            $tracked = Maintenance::runCommand($command, $argv[2] ?? null);
            $result = $tracked['ok'] ? ($tracked['job']['result'] ?? $tracked) : $tracked;
            break;
        default:
            throw new InvalidArgumentException('Unknown command. Run php src/Console.php help.');
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(isset($result['ok']) && !$result['ok'] ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
