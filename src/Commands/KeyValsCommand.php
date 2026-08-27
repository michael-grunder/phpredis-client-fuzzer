<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

abstract class KeyValsCommand extends Command implements FuzzInterface,
                                                         FuzzRawInterface,
                                                         ProxyInterface
{
    /**
     * @return array<mixed>|null
     */
    abstract protected function getValues(Redis|Relay $server, string $key,
                                          FuzzConfig $config): ?array;

    public function proxy(Redis|Relay $server, Redis|Relay $client,
                          FuzzConfig $config, KeySample $keys): mixed
    {
        $key  = $keys->consumeKey($this->type());
        if ( ! $key)
            return false;

        $vals = $this->getValues($server, $key, $config);
        if ( ! $vals)
            return false;

        return $this->exec(
            $client,
            $config->toFuzzingKey($this->type(), $key), ...$vals)
        ;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            ...$config->getRandomValues($client, $this->type()),
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        return $this->execRaw(
            $client,
            $config->getRandomKey($this->type()),
            ...$config->getRandomStrings(),
        );
    }
}
