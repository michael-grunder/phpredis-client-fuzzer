<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

abstract class KeyMemValCommand extends Command implements ProxyInterface,
                                                           FuzzInterface,
                                                           FuzzRawInterface
{
    public function type(): string {
        return Command::HASH;
    }

    public function flags(): int {
        return Command::WRITE | self::INVALIDATING;
    }

    public function proxy(Redis|Relay $server, Redis|Relay $client,
                          FuzzConfig $config, KeySample $keys): mixed
    {
        $key = $keys->consumeKey($this->type());
        if ( ! $key)
            return false;

        $field = $this->cmd($server, 'hRandField', $key, ['withvalues' => true]);
        if ( ! is_array($field) || ! $field)
            return false;

        return $this->exec(
            $client,
            $key,
            $config->getRandomMember($this->type()),
            current($field)
        );
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            $config->getRandomMember($this->type()),
            $config->getRandomValue($client, $this->type()),
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        return $this->execRaw(
            $client,
            $config->getRandomKey($this->type()),
            $config->getRandomMember($this->type()),
            $config->getRandomString(),
        );
    }
}
