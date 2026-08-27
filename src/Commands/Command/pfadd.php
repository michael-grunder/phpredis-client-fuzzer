<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class pfadd extends Command implements ProxyInterface, FuzzInterface,
                                       FuzzRawInterface
{
    public function type(): string {
        return self::HLL;
    }

    public function flags(): int {
        return self::WRITE;
    }

    public function proxy(Redis|Relay $server, Redis|Relay $client,
                          FuzzConfig $config, KeySample $keys): mixed
    {
        $keys = $keys->consumeKeys(self::STRING);
        if ( ! $keys)
            return false;

        $vals = $this->cmd($client, 'mget', $keys);
        if ( ! $vals || ! is_array($vals))
            return false;

        return $this->exec($client, $config->getRandomKey($this->type()), $vals);
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                               FuzzConfig $config): mixed
    {
        $values = array_map(
            fn() => $config->getRandomValue($client, $this->type()),
            range(0, rand(1, $config->getMembers()))
        );

        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            $values
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        return $this->execRaw(
            $client,
            $config->getRandomKey($this->type()),
            ...$config->getRandomStrings()
        );
    }
}
