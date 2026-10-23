<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

abstract class MZSetCommand extends Command implements FuzzInterface,
                                                       FuzzRawInterface
{
    protected const AGGREGATE = ['MIN' => 1, 'SUM' => 1, 'MAX' => 1, 'COUNT' => 1];

    /**
     * @param string[] $keys
     * @return array<mixed>
     */
    protected function randomOptions(Redis|RedisCluster|Relay|Cluster $client,
                                     array $keys): array|string|null
    {
        $result = [];

        if (rand(1, 2) == 1)
            return rand(0, 1) ? null : [];

        if (rand(1, 2) == 1)
            $result['AGGREGATE'] = array_rand(self::AGGREGATE);

        if (!($this->flags() & self::WRITE) && rand(1, 2) == 1)
            $result['WITHSCORES'] = rand(1, 2) == 1;

        return $result;
    }

    private static function randomWeight(): float
    {
        return rand(0, 1)
            ? [-1.0, 0.0, 0.5, 1.0, 2.0][rand(0, 4)]
            : mt_rand() / mt_getrandmax();
    }

    /**
     * @param string[] $keys
     * @return float[]|null
     */
    private function randomWeights(array $keys): ?array {
        if (rand(1, 2) == 1)
            return array_map(static fn() => self::randomWeight(), $keys);

        return null;
    }

    public function type(): string {
        return self::ZSET;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $keys = $config->getRandomKeys($this->type());

        $args = ($this->flags() & self::WRITE)
            ? ['dst-' . $config->getRandomKey($this->type()), $keys]
            : [$keys];
        $optionalCount = rand(0, 2);
        if ($optionalCount >= 1) {
            $args[] = $this->randomWeights($keys);
        }
        if ($optionalCount === 2) {
            $args[] = $this->randomOptions($client, $keys);
        }

        return $this->exec($client, ...$args);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $args = [];

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

            $weights = array_map(static fn() => self::randomWeight(), $keys);
            foreach ($weights as $weight) {
                $args[] = $weight;
            }
        }

        if (rand() & 2) {
            $args[] = 'AGGREGATE';
            $args[] = array_rand(self::AGGREGATE);
        }

        if (!($this->flags() & self::WRITE) && rand(0, 1)) {
            $args[] = 'WITHSCORES';
        }

        return $this->execRaw($client, ...$args);
    }
}
