<?php
declare(strict_types=1);
/** Lightweight, network-free acquisition scheduling regression checks. */
require dirname(__DIR__) . '/src/Services/Acquisition.php';

function checkAcquisition(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$elig = new ReflectionMethod('Acquisition', 'episodeEligibleAt');
$elig->setAccessible(true);
$expect = (new DateTimeImmutable('2026-11-05 00:00:00', new DateTimeZone('UTC')))->getTimestamp();
checkAcquisition($elig->invoke(null, '2026-11-04') === $expect, 'Premiere must start searching next calendar day');
checkAcquisition($elig->invoke(null, '2026-02-30') === null, 'Impossible date must be unknown');
checkAcquisition($elig->invoke(null, null) === null, 'Unknown airdate must be accepted');
checkAcquisition($elig->invoke(null, '2026-11-04T20:00:00Z') === null, 'Date-only rule must reject timestamps');

$retry = new ReflectionMethod('Acquisition', 'postponeNoHit');
$retry->setAccessible(true);
$job = ['search_attempts' => 1];
$args = [&$job];
$before = time();
$retry->invokeArgs(null, $args);
checkAcquisition($job['status'] === 'retry_wait', 'First no-hit should retry');
checkAcquisition($job['next_attempt_at'] >= $before + 3600 && $job['next_attempt_at'] <= time() + 3600, 'First retry is one hour');
$job = ['search_attempts' => 8];
$args = [&$job];
$retry->invokeArgs(null, $args);
checkAcquisition($job['status'] === 'exhausted' && $job['next_attempt_at'] === null, 'Eight searches must exhaust');
echo "Acquisition scheduling checks passed\n";
