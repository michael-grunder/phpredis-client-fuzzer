<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class hget extends Command implements ProxyInterface, FuzzInterface,
                                      FuzzRawInterface
{
    use FuzzGeneric;

    public function type(): string {
        return self::HASH;
    }

    public function flags(): int {
        return self::READ | self::CACHED;
    }

    public function proxy(Redis|Relay $server, Redis|Relay $client,
                          FuzzConfig $config, KeySample $keys): mixed
    {
        $src_key = $keys->consumeKey($this->type());
        if ( ! $src_key)
            return false;

        $mem = $this->cmd($server, 'HRANDFIELD', $src_key);
        if ( ! $mem || is_object($mem))
            return false;

        $dst_key = $config->toFuzzingKey($this->type(), $src_key);

        return $this->exec($client, $dst_key, $mem);
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        return $this->$fn(
            $client,
            $config->getRandomKey($this->type()),
            $config->getRandomMember($this->type()),
        );
    }
}
