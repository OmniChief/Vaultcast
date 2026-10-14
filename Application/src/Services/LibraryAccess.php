<?php
declare(strict_types=1);

/**
 * Lets the Windows web service read an administrator-chosen library folder.
 *
 * On Windows, PHP runs as LocalSystem, which can read any local folder. nginx
 * runs as LocalService and serves media files itself (X-Accel-Redirect), so it
 * needs read access too. Folders inside a user profile (Downloads, Videos and
 * so on) only grant access to that user, SYSTEM and Administrators, so playback
 * from them fails until LocalService is granted read. This adds a single
 * inheritable read-and-execute entry for LocalService (S-1-5-19) on the chosen
 * folder; nothing above it changes (Windows lets every account traverse parent
 * folders by default). On other systems it does nothing.
 *
 * Usage: $r = LibraryAccess::grantServiceRead($path);
 *   ['ok' => true]                              access granted or not needed
 *   ['ok' => false, 'code' => 'library_acl_failed', 'error' => '...']
 */
final class LibraryAccess {
    public const ERROR_CODE = 'library_acl_failed';

    public static function grantServiceRead(string $path): array {
        if (PHP_OS_FAMILY !== 'Windows') return ['ok' => true];
        $folder = rtrim(str_replace('/', '\\', $path), '\\');
        if (preg_match('/^[A-Za-z]:$/', $folder)) $folder .= '\\';
        if ($folder === '' || !is_dir($folder)) {
            return self::failure('The folder does not exist or Vaultcast cannot open it.');
        }
        // Network shares use their own share permissions; leave their ACLs alone.
        if (strncmp($folder, '\\\\', 2) === 0) return ['ok' => true];
        $icacls = (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\icacls.exe';
        $process = proc_open([$icacls, $folder, '/grant', '*S-1-5-19:(OI)(CI)RX', '/C', '/Q'],
            [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) return self::failure('Windows permission tool could not be started.');
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            return self::failure('Windows refused to change the folder permissions: ' . trim((string)$out));
        }
        return ['ok' => true];
    }

    private static function failure(string $detail): array {
        return ['ok' => false, 'code' => self::ERROR_CODE,
            'error' => 'Vaultcast could not give its media service read access to this folder. ' . $detail
                . ' Choose a folder that an administrator can open, or grant "LOCAL SERVICE" read access to it in the folder\'s Security settings.'];
    }
}
