<?php

declare(strict_types=1);

namespace Prometheus\Storage;

use InvalidArgumentException;
use Prometheus\Counter;
use Prometheus\Exception\StorageException;
use Prometheus\Gauge;
use Prometheus\Histogram;
use Prometheus\MetricFamilySamples;
use \Illuminate\Support\Facades\Redis as FacadeRedis;

class Redis implements Adapter
{
    const PROMETHEUS_METRIC_KEYS_SUFFIX = '_METRIC_KEYS';

    /**
     * @var array
     */
    private static $redisConfigName = "default";

    /**
     * @var string
     */
    private static $prefix = 'PROMETHEUS_';

    /**
     * @var FacadeRedis
     */
    private $redis;

    /**
     * @param string $configName
     */
    public static function setConfigName(string $configName): void
    {
        self::$redisConfigName = $configName;
    }

    /**
     * @param $prefix
     */
    public static function setPrefix($prefix): void
    {
        self::$prefix = $prefix;
    }

    /**
     * @throws StorageException
     */
    public function flushRedis(): void
    {
        $this->openConnection();
        $this->redis->flushdb();
    }

    /**
     * @return MetricFamilySamples[]
     * @throws StorageException
     */
    public function collect(): array
    {
        $this->openConnection();
        $metrics = $this->collectHistograms();
        $metrics = array_merge($metrics, $this->collectGauges());
        $metrics = array_merge($metrics, $this->collectCounters());
        return array_map(
            function (array $metric) {
                return new MetricFamilySamples($metric);
            },
            $metrics
        );
    }

    /**
     * @throws StorageException
     */
    private function openConnection(): void
    {
        if (!isset($this->redis)) {
            $this->redis = FacadeRedis::connection(Redis::$redisConfigName);
        }
    }

    /**
     * @param array $data
     * @throws StorageException
     */
    public function updateHistogram(array $data): void
    {
        $this->openConnection();
        $bucketToIncrease = '+Inf';
        foreach ($data['buckets'] as $bucket) {
            if ($data['value'] <= $bucket) {
                $bucketToIncrease = $bucket;
                break;
            }
        }
        $metaData = $data;
        unset($metaData['value']);
        unset($metaData['labelValues']);

        $hash = $this->toMetricKey($data);
        $increment = $this->redis->hincrbyfloat($hash, json_encode(['b' => 'sum', 'labelValues' => $data['labelValues']]), $data['value']);
        $this->redis->hincrby($hash, json_encode(['b' => $bucketToIncrease, 'labelValues' => $data['labelValues']]), 1);

        if ($increment == $data['value']) {
            $this->redis->hset($hash, '__meta', json_encode($metaData));
            $this->redis->sadd(self::$prefix . Histogram::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX, $hash);
        }
    }

    /**
     * @param array $data
     * @throws StorageException
     */
    public function updateGauge(array $data): void
    {
        $this->openConnection();
        $metaData = $data;
        unset($metaData['value']);
        unset($metaData['labelValues']);
        unset($metaData['command']);

        $hash = $this->toMetricKey($data);

        switch ($this->getRedisCommand($data['command'])) {
            case 'hIncrBy':
                $result = $this->redis->hincrby($hash, json_encode($data['labelValues']), $data['value']);
                if ($result == $data['value']) {
                    $this->redis->hset($hash, '__meta', json_encode($metaData));
                    $this->redis->sadd(self::$prefix . Gauge::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX, $hash);
                }
                break;
            case 'hIncrByFloat':
                $result = $this->redis->hincrbyfloat($hash, json_encode($data['labelValues']), $data['value']);
                if ($result == $data['value']) {
                    $this->redis->hset($hash, '__meta', json_encode($metaData));
                    $this->redis->sadd(self::$prefix . Gauge::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX, $hash);
                }
                break;
            case 'hSet':
                $result = $this->redis->hset($hash, json_encode($data['labelValues']), $data['value']);
                if ($result == 1) {
                    $this->redis->hset($hash, '__meta', json_encode($metaData));
                    $this->redis->sadd(self::$prefix . Gauge::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX, $hash);
                }
                break;
        }
    }

    /**
     * @param array $data
     * @throws StorageException
     */
    public function updateCounter(array $data): void
    {
        $this->openConnection();
        $metaData = $data;
        unset($metaData['value']);
        unset($metaData['labelValues']);
        unset($metaData['command']);

        $hash = $this->toMetricKey($data);
        $result = null;
        switch ($this->getRedisCommand($data['command'])) {
            case 'hIncrBy':
                $result = $this->redis->hincrby($hash, json_encode($data['labelValues']), $data['value']);
                break;
            case 'hIncrByFloat':
                $result = $this->redis->hincrbyfloat($hash, json_encode($data['labelValues']), $data['value']);
                break;
            case 'hSet':
                $result = $this->redis->hset($hash, json_encode($data['labelValues']), $data['value']);
                break;
        }
        if ($result == $data['value']) {
            $this->redis->hmset($hash, '__meta', json_encode($metaData));
            $this->redis->sadd(self::$prefix . Counter::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX, $hash);
        }
    }

    /**
     * @return array
     */
    private function collectHistograms(): array
    {
        $keys = $this->redis->smembers(self::$prefix . Histogram::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX);
        sort($keys);
        $histograms = [];
        foreach ($keys as $key) {
            $raw = $this->redis->hgetall($key);
            $histogram = json_decode($raw['__meta'], true);
            unset($raw['__meta']);
            $histogram['samples'] = [];

            // Add the Inf bucket so we can compute it later on
            $histogram['buckets'][] = '+Inf';

            $allLabelValues = [];
            foreach (array_keys($raw) as $k) {
                $d = json_decode($k, true);
                if ($d['b'] == 'sum') {
                    continue;
                }
                $allLabelValues[] = $d['labelValues'];
            }

            // We need set semantics.
            // This is the equivalent of array_unique but for arrays of arrays.
            $allLabelValues = array_map("unserialize", array_unique(array_map("serialize", $allLabelValues)));
            sort($allLabelValues);

            foreach ($allLabelValues as $labelValues) {
                // Fill up all buckets.
                // If the bucket doesn't exist fill in values from
                // the previous one.
                $acc = 0;
                foreach ($histogram['buckets'] as $bucket) {
                    $bucketKey = json_encode(['b' => $bucket, 'labelValues' => $labelValues]);
                    if (!isset($raw[$bucketKey])) {
                        $histogram['samples'][] = [
                            'name' => $histogram['name'] . '_bucket',
                            'labelNames' => ['le'],
                            'labelValues' => array_merge($labelValues, [$bucket]),
                            'value' => $acc,
                        ];
                    } else {
                        $acc += $raw[$bucketKey];
                        $histogram['samples'][] = [
                            'name' => $histogram['name'] . '_bucket',
                            'labelNames' => ['le'],
                            'labelValues' => array_merge($labelValues, [$bucket]),
                            'value' => $acc,
                        ];
                    }
                }

                // Add the count
                $histogram['samples'][] = [
                    'name' => $histogram['name'] . '_count',
                    'labelNames' => [],
                    'labelValues' => $labelValues,
                    'value' => $acc,
                ];

                // Add the sum
                $histogram['samples'][] = [
                    'name' => $histogram['name'] . '_sum',
                    'labelNames' => [],
                    'labelValues' => $labelValues,
                    'value' => $raw[json_encode(['b' => 'sum', 'labelValues' => $labelValues])],
                ];
            }
            $histograms[] = $histogram;
        }
        return $histograms;
    }

    /**
     * @return array
     */
    private function collectGauges(): array
    {
        $keys = $this->redis->smembers(self::$prefix . Gauge::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX);
        sort($keys);
        $gauges = [];
        foreach ($keys as $key) {
            $raw = $this->redis->hgetall($key);
            $gauge = json_decode($raw['__meta'], true);
            unset($raw['__meta']);
            $gauge['samples'] = [];
            foreach ($raw as $k => $value) {
                $gauge['samples'][] = [
                    'name' => $gauge['name'],
                    'labelNames' => [],
                    'labelValues' => json_decode($k, true),
                    'value' => $value,
                ];
            }
            usort($gauge['samples'], function ($a, $b) {
                return strcmp(implode("", $a['labelValues']), implode("", $b['labelValues']));
            });
            $gauges[] = $gauge;
        }
        return $gauges;
    }

    /**
     * @return array
     */
    private function collectCounters(): array
    {
        $keys = $this->redis->smembers(self::$prefix . Counter::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX);
        sort($keys);
        $counters = [];
        foreach ($keys as $key) {
            $raw = $this->redis->hgetall($key);
            $counter = json_decode($raw['__meta'], true);
            unset($raw['__meta']);
            $counter['samples'] = [];
            foreach ($raw as $k => $value) {
                $counter['samples'][] = [
                    'name' => $counter['name'],
                    'labelNames' => [],
                    'labelValues' => json_decode($k, true),
                    'value' => $value,
                ];
            }
            usort($counter['samples'], function ($a, $b) {
                return strcmp(implode("", $a['labelValues']), implode("", $b['labelValues']));
            });
            $counters[] = $counter;
        }
        return $counters;
    }

    /**
     * @param int $cmd
     * @return string
     */
    private function getRedisCommand(int $cmd): string
    {
        switch ($cmd) {
            case Adapter::COMMAND_INCREMENT_INTEGER:
                return 'hIncrBy';
            case Adapter::COMMAND_INCREMENT_FLOAT:
                return 'hIncrByFloat';
            case Adapter::COMMAND_SET:
                return 'hSet';
            default:
                throw new InvalidArgumentException("Unknown command");
        }
    }

    /**
     * @param array $data
     * @return string
     */
    private function toMetricKey(array $data): string
    {
        return implode(':', [self::$prefix, $data['type'], $data['name']]);
    }
}
