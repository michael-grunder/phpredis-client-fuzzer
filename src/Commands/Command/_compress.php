<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\ValueCommand;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class _compress extends ValueCommand {
    public function flags(): int {
        return self::LOCAL;
    }

    public function type(): string {
        return self::NONE;
    }

    protected function randomValue(Redis|RedisCluster|Relay|Cluster $client,
                                   FuzzConfig $config): mixed
    {
        return $config->getRandomString();
    }
}
