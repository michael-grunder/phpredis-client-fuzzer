<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class zrangestore extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::WRITE;
    }

    /**
     * @return array<mixed>
     */
    private function randomOptions(FuzzConfig $config): array {
        $options = null;
        $rng = rand();
        $by = null;

        if ($rng & 0x1) {
            if ($rng & 0x2) {
                $options[] = 'WITHSCORES';
            } else {
                $options['WITHSCORES'] = $rng & 0x4;
            }
        }

        if ($rng & 0x8) {
            $by = $rng & 0x10 ? 'BYLEX' : 'BYSCORE';
            $options[] = $by;

            if ($rng & 0x20) {
                $offset = rand(0, $config->getMembers());
                $limit  = rand(0, $config->getMembers());
                $options['LIMIT'] = [$offset, $limit];
            }
        }

        return [$options, $by];
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $args = [];

        [$options, $by] = $this->randomOptions($config);

        if ($by == 'BYLEX') {
            [$start, $end] = FuzzConfig::randomLexRange();
        } else {
            [$start, $end] = $config->randomRange();
        }

        $args = [
            $config->getRandomKey($this->type()),
            $config->getRandomKey($this->type()),
            $start,
            $end,
        ];

        if ($options !== null) {
            $args[] = $options;
        }

        return $this->exec($client, ...$args);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $key = 'dst-' . $config->getRandomKey($this->type());

        $mode = rand() % 3;
        [$options, [$min, $max]] = match($mode) {
            0 => [[], $config->randomRange()],
            1 => [['BYLEX'], $config->randomLexRange()],
            2 => [['BYSCORE'], $config->randomScoreRange()],
        };

        if (rand() &1)
            $options[] = 'REV';

        return $this->execRaw($client, $key, $min, $max, ...$options);
    }
}
