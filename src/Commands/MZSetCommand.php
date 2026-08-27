<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

abstract class MZSetCommand extends Command implements FuzzInterface,
                                                       FuzzRawInterface
{
    protected const AGGREGATE = ['MIN' => 1, 'SUM' => 1, 'MAX' => 1];

    /**
     * @param string[] $keys
     * @return array<mixed>
     */
    protected function randomOptions(Redis|RedisCluster|Relay|Cluster $client,
                                     array $keys): array|string|null
    {
        $result = [];

        if (rand(1, 2) == 1)
            return self::isRelay($client) ? null : [];

        if (rand(1, 2) == 1)
            $result['AGGREGATE'] = array_rand(self::AGGREGATE);

        if (rand(1, 2) == 1)
            $result['WITHSCORES'] = rand(1, 2) == 1;

        return $result;
    }

    /**
     * @param string[] $keys
     * @return float[]|null
     */
    private function randomWeights(array $keys): ?array {
        if (rand(1, 2) == 1)
            return array_map(fn() => mt_rand() / mt_getrandmax(), $keys);

        return null;
    }

    public function type(): string {
        return self::ZSET;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $keys = $config->getRandomKeys($this->type());

        $options = $this->randomOptions($client, $keys);
        $weights = $this->randomWeights($keys);

        if ($this->flags() & self::WRITE) {
            $dst = 'dst-' . $config->getRandomKey($this->type());
            return $this->exec($client, $dst, $keys, $weights, $options);
        }

        return $this->exec($client, $keys, $weights, $options);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $args = [];
        $rand = rand();

        if ($this->flags() & self::WRITE) {
            $dst = 'dst-' . $config->getRandomKey($this->type());
            $args[] = $dst;
        }

        $keys = $config->getRandomKeys($this->type());
        $args[] = count($keys);
        foreach ($keys as $key) {
            $args[] = $key;
        }

        if (rand() & 1) {
            $args[] = 'WEIGHTS';

            $weights = array_map(fn() => mt_rand() / mt_getrandmax(), $keys);
            foreach ($weights as $weight) {
                $args[] = $weight;
            }
        }

        if (rand() & 2) {
            $args[] = 'AGGREGATE';
            $args[] = array_rand(self::AGGREGATE);
        }

        return $this->execRaw($client, ...$args);
    }
}
