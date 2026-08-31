<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\ScriptLogger;

use Relay\Relay;
use Relay\Cluster;

use Redis;
use RedisCluster;

class fullscan extends Command implements FuzzInterface {
    public function flags(): int {
        return self::READ | self::SCAN;
    }

    public function type(): string {
        return self::ANY;
    }

    /** @return array{batches: int, keys: int}|false */
    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): array|false
    {
        if (!$client instanceof Cluster || !method_exists($client, $this->name()))
            return false;

        if ($client->getMode() !== Redis::ATOMIC)
            return false;

        $rng = rand();
        $pattern = $rng & 1 ? $config->getRandomPattern() : null;
        $count = $rng & 2 ? rand(1, $config->getCmdMaxKeys()) : 0;
        $type = $rng & 4 ? $config->getRandomType() : null;
        $args = [$pattern, $count, $type];

        ScriptLogger::logIterable($client, $this->name(), $args);

        try {
            $result = $this->invokeClient($client, $this->name(), $args);
            if ($result === false) {
                $this->logRedisError($client, ...$args);

                return false;
            }
            if (!$result instanceof \Traversable) {
                throw new \UnexpectedValueException(sprintf(
                    'Relay\\Cluster::fullscan() returned %s instead of a generator',
                    get_debug_type($result),
                ));
            }

            $batches = 0;
            $keys = 0;
            foreach ($result as $batch) {
                if (!is_array($batch)) {
                    throw new \UnexpectedValueException(sprintf(
                        'Relay\\Cluster::fullscan() yielded %s instead of an array',
                        get_debug_type($batch),
                    ));
                }
                foreach ($batch as $key) {
                    if (!is_string($key)) {
                        throw new \UnexpectedValueException(sprintf(
                            'Relay\\Cluster::fullscan() yielded a %s key',
                            get_debug_type($key),
                        ));
                    }
                }
                $batches++;
                $keys += count($batch);
            }
        } catch (\Throwable $throwable) {
            try {
                $this->logRedisError($client, ...$args);
            } catch (\Throwable) {
            }
            throw $throwable;
        }

        $this->logRedisError($client, ...$args);

        return ['batches' => $batches, 'keys' => $keys];
    }
}
