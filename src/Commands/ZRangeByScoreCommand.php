<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

abstract class ZRangeByScoreCommand extends Command implements FuzzInterface,
                                                               FuzzRawInterface
{
    abstract protected function reverse(): bool;

    /**
     * @return array{limit?: array{int, int}, withscores?: true}
     */
    protected function getRandomOptions(FuzzConfig $config): array {
        $options = [];

        if (rand(1, 2) == 1) {
            $options['limit'] = [
                rand(0, $config->getMembers()),
                rand(0, $config->getMembers())
            ];
        }

        if (rand(1, 2) == 1)
            $options['withscores'] = true;

        return $options;
    }

    /**
     * @return array<mixed>
     */
    protected function getRandomOptionsRaw(FuzzConfig $config): array {
        $result = [];

        $options = $this->getRandomOptions($config);

        if (isset($options['limit'])) {
            $result[] = 'LIMIT';
            $result[] = $options['limit'][0];
            $result[] = $options['limit'][1];
        }

        if (isset($options['withscores']))
            $result[] = 'WITHSCORES';

        return $result;
    }

    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::READ | self::CACHED;
    }

    /** @return array{string, float|string, float|string} */
    private function getPreambleArgs(FuzzConfig $config): array {
        $key = $config->getRandomKey($this->type());

        $scores = [$config->getRandomFloat(), $config->getRandomFloat()];
        sort($scores);

        if (rand(1, 2) == 1)
            $scores[0] = '-inf';
        if (rand(1, 2) == 1)
            $scores[1] = '+inf';

        if ($this->reverse())
            [$start, $end] = [$scores[1], $scores[0]];
        else
            [$start, $end] = [$scores[0], $scores[1]];

        return [$key, $start, $end];
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        [$key, $start, $end] = $this->getPreambleArgs($config);
        $options = $this->getRandomOptions($config);

        return $this->exec($client, $key, $start, $end, $options);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        [$key, $start, $end] = $this->getPreambleArgs($config);
        $options = $this->getRandomOptionsRaw($config);

        return $this->execRaw($client, $key, $start, $end, ...$options);
    }
}
