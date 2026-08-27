<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class watch extends Command implements FuzzInterface, FuzzRawInterface {
    use FuzzGeneric;

    public function type(): string {
        return self::ANY;
    }

    public function flags(): int {
        return self::READ;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        $keys = $config->getRandomKeys($this->type());

        if ($fn != 'execRaw' && rand() & 1) {
            return $this->$fn($client, $keys);
        } else {
            return $this->$fn($client, ...$keys);
        }
    }
}
