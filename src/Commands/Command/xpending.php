<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;
use Mgrunder\PhpredisCommandFuzzer\Data\Events;

use Redis;
use RedisCluster;

use Relay\Relay;
use Relay\Cluster;

class xpending extends Command implements FuzzInterface, FuzzRawInterface {
    use FuzzGeneric;

    public function type(): string {
        return self::STREAM;
    }

    public function flags(): int {
        return self::READ;
    }

    /**
     * @return list<string|int>
     */
    private function randomArgs(FuzzConfig $config): array {
        $rng = rand(0, 2);
        if ($rng == 0)
            return [];

        [$start, $end] = Events::instance()->randomIdRange();

        $result[] = $start;
        $result[] = $end;
        $result[] = $config->randomMemberCount();

        if ($rng == 2)
            $result[] = $config->getConsumer();

        return $result;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        $key = $config->getRandomKey($this->type());

        return $this->$fn($client, $key, 'fuzzer', ...$this->randomArgs($config));
    }

// public function xpending(
//     string $key,
//     string $group,
//     ?string $start = null,
//     ?string $end = null,
//     int $count = -1,
//     ?string $consumer = null,
// ): Redis|array|false;

}
