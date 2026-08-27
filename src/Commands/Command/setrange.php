<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class setrange extends Command implements ProxyInterface, FuzzInterface,
                                          FuzzRawInterface
{
    use FuzzGeneric;

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

        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            rand(0, $config->getMaxLen()),
            $this->cmd($server, 'get', $key)
        );
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        if ($fn == 'exec' && self::isRelay($client)) {
            $value = $config->getRandomValue($client, $this->type());
        } else {
            $value = $config->getRandomString();
        }

        return $this->$fn(
            $client,
            $config->getRandomKey($this->type()),
            rand(0, $config->getMaxLen()),
            $value,
        );
    }
}
