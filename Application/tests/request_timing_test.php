<?php
require __DIR__ . '/../src/Services/RequestTiming.php';
RequestTiming::begin(); $start = microtime(true); usleep(1000);
RequestTiming::add('db', $start); RequestTiming::count('cache_read'); RequestTiming::count('cache_hit');
RequestTiming::add("invalid\r\nheader", $start);
$value = RequestTiming::value();
if (!preg_match('/app;dur=[0-9.]+, db;dur=[0-9.]+, cache_read;desc="1", cache_hit;desc="1"$/', $value)) throw new RuntimeException($value);
echo "all passed\n";
