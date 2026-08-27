<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class ZRangeByLexCommand extends Command implements FuzzInterface, FuzzRawInterface {
    use Traits\FuzzGeneric;

    public function type(): string {
        return self::ZSET;
    }

    public function flags(): int {
        return self::READ;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        $key  = $config->getRandomKey($this->type());
        $min  = FuzzConfig::randomLexArg();
        $max  = FuzzConfig::randomLexArg();

        $args = [];

        if (rand() & 1) {
            if ($fn == 'execRaw')
                $args[] = 'LIMIT';
            $args[] = rand(-1, $config->getMembers());
            $args[] = rand(-1, $config->getMembers());
        }

        return $this->$fn($client, $key, $min, $max, ...$args);
    }
}
