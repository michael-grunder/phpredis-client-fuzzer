<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

abstract class MSetCommand extends Command implements ProxyInterface,
                                                      FuzzInterface,
                                                      FuzzRawInterface
{
    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }

    public function type(): string {
        return self::STRING;
    }

    public function proxy(Redis|Relay $server, Redis|Relay $client,
                          FuzzConfig $config, KeySample $keys): mixed
    {
        $keys = $keys->consumeKeys($this->type());
        if ( ! $keys)
            return false;

        $vals = $this->cmd($client, 'mget', $keys);
        if ( ! is_array($vals) || ! $vals)
            return false;

        $keys = $config->getRandomKeys($this->type(), count($vals));

        return $this->exec($client, array_combine($keys, $vals));
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $keys = $config->getRandomKeys($this->type());

        assert(count($keys) > 0);

        for ($i = 0; $i < count($keys); $i++) {
            $vals[] = $config->getRandomValue($client, $this->type());
        }

        return $this->exec(
            $client,
            array_combine($keys, $vals)
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $args = [];

        $keys = $config->getRandomKeys($this->type());

        assert(count($keys) > 0);

        foreach ($keys as $key) {
            $args[] = $key;
            $args[] = $config->getRandomString();
        }

        return $this->execRaw($client, ...$args);
    }
}
