<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

abstract class SInterUnionDiffCommand extends Command
                                      implements FuzzInterface, FuzzRawInterface
{
    use FuzzGeneric;

    public function type(): string {
        return Command::SET;
    }

    public function flags(): int {
        return Command::READ | self::CACHED;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed {
        return $this->$fn(
            $client,
            ...$config->getRandomKeys($this->type()),
        );
    }
}
