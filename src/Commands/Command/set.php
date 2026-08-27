<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class set extends Command implements ProxyInterface, FuzzInterface,
                                     FuzzRawInterface
{
    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }

    public function type(): string {
        return self::STRING;
    }

    public function proxy(Redis|Relay $server, Redis|Relay $client,
                          FuzzConfig $config, KeySample $keys): mixed {
        $key = $keys->consumeKey($this->type());
        if ( ! $key)
            return null;

        return $this->exec($client, $config->getRandomKey($this->type()),
                           $this->cmd($server, 'get', $key));
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            $config->getRandomValue($client, $this->type())
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        return $this->execRaw(
            $client,
            $config->getRandomKey($this->type()),
            $config->getRandomString()
        );
    }
}
