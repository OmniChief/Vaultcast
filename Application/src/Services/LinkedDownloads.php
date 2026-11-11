<?php
declare(strict_types=1);

use Vaultcast\Database;
use Vaultcast\Repository;

final class LinkedDownloads
{
    private static function query(string $sql, array $args = []): PDOStatement
    {
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);
        return $stmt;
    }

    private static function folder(): string
    {
        return STREAM_DATA . '/linked-downloads';
    }

    public static function storageSetting(): string
    {
        return (string)Repository::document('linked-download-storage', '');
    }

    public static function setStorage(string $path): void
    {
        $path = trim($path);
        if ($path === '') {
            Repository::putDocument('linked-download-storage', '');
            return;
        }
        $absolute = preg_match('#^(?:/|[A-Za-z]:[/\\\\]|\\\\\\\\)#D', $path);
        $real = $absolute ? realpath($path) : false;
        if ($real === false || !is_dir($real) || !is_writable($real)) {
            throw new DomainException('Choose an existing folder that this Vaultcast server can write to.');
        }
        $root = rtrim(str_replace('\\', '/', (string)realpath(STREAM_ROOT)), '/');
        $check = rtrim(str_replace('\\', '/', $real), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $root = strtolower($root);
            $check = strtolower($check);
        }
        if ($check === $root || strpos($check . '/', $root . '/') === 0 || strpos($root . '/', $check . '/') === 0) {
            throw new DomainException('Choose a separate storage folder, or leave it blank to use Vaultcast’s private data folder.');
        }
        Repository::putDocument('linked-download-storage', $real);
    }

    private static function destination(): string
    {
        $setting = self::storageSetting();
        if ($setting !== '' && !is_dir($setting)) throw new DomainException('The selected download storage is unavailable.');
        $directory = $setting === '' ? self::folder() : rtrim($setting, '/\\') . '/vaultcast-linked-copies';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new DomainException('Vaultcast cannot create the saved copies folder.');
        }
        if (!is_writable($directory)) throw new DomainException('The saved copies folder is not writable.');
        return $directory;
    }

    private static function job(string $id): array
    {
        $job = self::query('SELECT * FROM vaultcast_linked_downloads WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
        if (!$job) throw new DomainException('This download no longer exists.');
        return $job;
    }

    private static function state(string $id, string $state, string $error = ''): void
    {
        self::query('UPDATE vaultcast_linked_downloads SET state=?,error=?,updated_at=? WHERE id=?', [$state, mb_substr($error, 0, 240), time(), $id]);
    }

    private static function file(array $job): string
    {
        $name = trim((string)preg_replace('/[^\pL\pN _.-]+/u', '_', mb_substr($job['title'], 0, 48)), " .");
        return $job['storage_dir'] . '/' . $job['id'] . ($name === '' ? '' : ' - ' . $name) . '.' . $job['extension'];
    }

    private static function lock(string $id)
    {
        $dir = self::folder();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new DomainException('Vaultcast cannot create this install’s download folder.');
        $lock = @fopen($dir . '/' . $id . '.lock', 'c');
        if (!$lock) throw new DomainException('Vaultcast cannot open the download lock.');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return null;
        }
        return $lock;
    }

    public static function queue(array $link, string $item): void
    {
        $existing = self::query('SELECT id FROM vaultcast_linked_downloads WHERE link_id=? AND item_id=?', [$link['id'], $item])->fetchColumn();
        if ($existing) throw new DomainException('This title is already in Saved copies.');
        $info = LinkedServers::copyInfo($link, $item);
        $id = bin2hex(random_bytes(12));
        $insert = self::query('INSERT IGNORE INTO vaultcast_linked_downloads (id,link_id,item_id,title,storage_dir,extension,file_key,bytes,state,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$id, $link['id'], $item, $info['title'], self::destination(), $info['extension'], $info['file_key'], $info['bytes'], 'queued', time()]);
        if (!$insert->rowCount()) throw new DomainException('This title is already in Saved copies.');
        self::launch($id);
    }

    private static function launch(string $id): void
    {
        try {
            Process::startPhpWorker('linked-download', $id);
        } catch (Throwable $error) {
            self::state($id, 'failed', 'The download worker could not start. Retry from Saved copies.');
            throw new DomainException('The download worker could not start. Retry from Saved copies.');
        }
    }

    public static function retry(string $id): void
    {
        $lock = self::lock($id);
        if (!$lock) throw new DomainException('This download is already running.');
        try {
            $job = self::job($id);
            if ($job['state'] === 'ready' && is_file(self::file($job))) throw new DomainException('This copy is already saved.');
            self::state($id, 'queued');
        } finally {
            fclose($lock);
        }
        self::launch($id);
    }

    public static function run(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{24}$/D', $id)) return ['ok' => false];
        $lock = self::lock($id);
        if (!$lock) return ['ok' => true];
        try {
            $job = self::job($id);
            $file = self::file($job);
            if (is_file($file) && (int)filesize($file) === (int)$job['bytes']) {
                self::state($id, 'ready');
                return ['ok' => true];
            }
            $link = self::query('SELECT * FROM vaultcast_linked_servers WHERE id=? AND connected=1', [$job['link_id']])->fetch(PDO::FETCH_ASSOC);
            if (!$link) throw new DomainException('Reconnect the sharing server to start a new download.');
            self::state($id, 'downloading');
            for ($attempt = 0; ; $attempt++) {
                try {
                    LinkedMediaTransfer::download($link, $job, $file . '.part');
                    break;
                } catch (RuntimeException $error) {
                    if ($attempt === 2) throw new DomainException($error->getMessage());
                    sleep(3 * ($attempt + 1));
                }
            }
            if (!@rename($file . '.part', $file)) throw new DomainException('Vaultcast could not move the finished copy into place.');
            self::state($id, 'ready');
            return ['ok' => true];
        } catch (DomainException $error) {
            self::state($id, 'failed', $error->getMessage());
            return ['ok' => false];
        } catch (Throwable $error) {
            self::state($id, 'failed', 'The transfer stopped. Retry to continue from the saved bytes.');
            throw $error;
        } finally {
            fclose($lock);
        }
    }

    public static function list(bool $manage): array
    {
        $sql = 'SELECT id,title,storage_dir,extension,bytes,state,error,updated_at FROM vaultcast_linked_downloads';
        if (!$manage) $sql .= " WHERE state='ready'";
        $jobs = self::query($sql . ' ORDER BY updated_at DESC,id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($jobs as &$job) {
            $file = self::file($job);
            if ($job['state'] === 'ready' && !is_file($file)) {
                $job['state'] = 'failed';
                $job['error'] = 'The saved file is missing from this install.';
            } elseif ($manage && in_array($job['state'], ['queued', 'downloading'], true) && time() - (int)$job['updated_at'] > 20) {
                $lock = self::lock($job['id']);
                if ($lock) {
                    fclose($lock);
                    $job['state'] = 'failed';
                    $job['error'] = 'The download was interrupted. Retry to continue.';
                }
            }
            $job['received'] = $job['state'] === 'ready' ? (int)$job['bytes'] : (is_file($file . '.part') ? (int)filesize($file . '.part') : 0);
            unset($job['storage_dir']);
        }
        unset($job);
        return $manage ? $jobs : array_values(array_filter($jobs, static function (array $job): bool { return $job['state'] === 'ready'; }));
    }

    public static function serve(string $id, bool $attachment): void
    {
        $job = self::job($id);
        $file = self::file($job);
        if ($job['state'] !== 'ready' || !is_file($file)) json_error('The saved file is unavailable.', 404);
        if ($attachment) {
            header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($job['title'] . '.' . $job['extension']));
        }
        Stream::emitAccelOrSend($file, Stream::mimeFor($job['extension'], 'video'));
    }

    public static function remove(string $id): void
    {
        $lock = self::lock($id);
        if (!$lock) throw new DomainException('This download is still running.');
        try {
            $job = self::job($id);
            $file = self::file($job);
            foreach ([$file, $file . '.part'] as $path) {
                if (is_file($path) && !@unlink($path)) throw new DomainException('The saved file could not be deleted. Stop playback and try again.');
            }
            self::query('DELETE FROM vaultcast_linked_downloads WHERE id=?', [$id]);
        } finally {
            fclose($lock);
        }
    }
}
