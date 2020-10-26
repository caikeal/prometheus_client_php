<?php

require __DIR__ . '/../vendor/autoload.php';

$adapter = $_GET['adapter'];

if ($adapter === 'redis') {
    $redisAdapter = new Prometheus\Storage\Redis();
    $redisAdapter->flushRedis();
} elseif ($adapter === 'apc') {
    $apcAdapter = new Prometheus\Storage\APC();
    $apcAdapter->flushAPC();
} elseif ($adapter === 'in-memory') {
    $inMemoryAdapter = new Prometheus\Storage\InMemory();
    $inMemoryAdapter->flushMemory();
}
