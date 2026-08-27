<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\ZRangeArgs;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class zrange extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::READ | self::CACHED;
    }

//    /**
//     * @return array<mixed>
//     */
//    private function baseArgs(FuzzConfig $config): array {
//        [$start, $end] = $this->randomRange($config->getMembers());
//
//        return [
//            $config->getRandomKey($this->type()),
//            $start,
//            $end,
//        ];
//    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $key = $config->getRandomKey($this->type());
        $args = new ZRangeArgs($config);

        $options = match (rand(0, 4)) {
            0 => [],
            1 => [false],
            2 => [true],
            3 => [null],
            4 => [$args->options()],
        };

        return $this->exec(
            $client, $key, $args->start(), $args->end(), ...$options
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $key = $config->getRandomKey($this->type());
        $args = new ZRangeArgs($config);

        return $this->execRaw(
            $client, $key, $args->start(), $args->end(), ...$args->optionsRaw()
        );
    }
}
