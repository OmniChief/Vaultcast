<?php
declare(strict_types=1);

final class NodeEvents
{
    public static function issue(string $topic, bool $once = false): ?string
    {
        if (!preg_match('/^[a-z0-9-]{1,80}$/D', $topic)
            || rtrim(\Vaultcast\Database::basePath(), '/') !== '' || !NodeRuntime::eventsReady()) return null;
        $dir = STREAM_DATA . '/runtime/events';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) return null;
        $token = NodeMedia::writeTicket(['purpose'=>'events', 'topic'=>$topic, 'once'=>$once]);
        return $token === null ? null : '/netplay/runtime/events/' . $token;
    }

    public static function signal(string $topic): void
    {
        $dir = STREAM_DATA . '/runtime/events';
        if (!is_dir($dir) || !preg_match('/^[a-z0-9-]{1,80}$/D', $topic)) return;
        @file_put_contents($dir . '/' . $topic, '1', LOCK_EX);
    }
}
