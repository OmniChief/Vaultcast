<?php
declare(strict_types=1);

/** Shared, cached ffprobe results for playback, conversion and Live scheduling. */
class MediaProbe {
    public static function ffprobeBin(): string {
        $configured = trim((string)(Store::settings()['tools']['ffprobe'] ?? ''));
        if ($configured !== '') return $configured;
        foreach (['ffprobe'] as $binary) {
            if ($binary === 'ffprobe' || is_executable($binary)) return $binary;
        }
        return 'ffprobe';
    }

    private static function inspect(string $path, int $timeout, bool $useCache = true): ?array {
        if (!is_file($path)) return null;
        $stat = @stat($path);
        $cache = 'probe|' . hash('sha256', $path . '|' . ($stat['mtime'] ?? 0) . '|' . ($stat['size'] ?? 0));
        if ($useCache) {
            $data = \Vaultcast\Repository::cacheGet($cache);
            if ($data !== null) { $data['_cached'] = true; return $data; }
        }
        $output = Process::capture([self::ffprobeBin(), '-v','error','-print_format','json','-show_streams','-show_format',$path], max(1,min(25,$timeout)));
        $data = is_string($output) ? json_decode($output, true) : null;
        if (!is_array($data) || !isset($data['streams'])) return null;
        \Vaultcast\Repository::cachePut($cache, $data, 86400);
        $data['_cached'] = false;
        return $data;
    }

    private static function tracks(array $data): array {
        $tracks = [];
        foreach ($data['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') !== 'audio') continue;
            $tags = $stream['tags'] ?? [];
            $tracks[] = [
                'index' => (int)($stream['index'] ?? count($tracks)),
                'relative' => count($tracks),
                'codec' => $stream['codec_name'] ?? null,
                'language' => $tags['language'] ?? $tags['LANGUAGE'] ?? null,
                'title' => $tags['title'] ?? $tags['TITLE'] ?? null,
                'channels' => isset($stream['channels']) ? (int)$stream['channels'] : null,
                'default' => !empty($stream['disposition']['default']),
            ];
        }
        return $tracks;
    }

    private static function codec(array $data): ?string {
        foreach ($data['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'video') return $stream['codec_name'] ?? null;
        }
        return null;
    }

    public static function duration(string $path, int $timeout = 8): ?float {
        $data = self::inspect($path, $timeout);
        $seconds = (float)($data['format']['duration'] ?? 0);
        return $seconds > 0 ? $seconds : null;
    }

    public static function probeSummary(string $path, int $timeout = 3, bool $useCache = true): array {
        $data = self::inspect($path, $timeout, $useCache);
        $tracks = self::tracks($data ?? []);
        return [
            'ok' => true,
            'path_exists' => is_file($path),
            'audio_tracks' => $tracks,
            'audio_count' => count($tracks),
            'multi_audio' => count($tracks) > 1,
            'video_codec' => self::codec($data ?? []),
            'duration' => ($d = (float)($data['format']['duration'] ?? 0)) > 0 ? $d : null,
            'probed' => $data !== null,
            'cached' => $data['_cached'] ?? false,
        ];
    }
}
