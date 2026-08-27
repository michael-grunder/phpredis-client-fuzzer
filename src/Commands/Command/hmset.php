<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Redis;
use RedisCluster;

use Relay\Relay;
use Relay\Cluster;


class hmset extends Command  implements ProxyInterface, FuzzInterface,
                                        FuzzRawInterface
{
    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }

    public function type(): string {
        return self::HASH;
    }

    /** @return array<mixed> */
    protected function proxiedValues(Redis|Relay $server, KeySample $keys): array {
        $key = $keys->consumeKey($this->type());
        if ( ! $key)
            return [];

        $values = $this->cmd($server, 'hvals', $key);
        if ( ! is_array($values) || ! count($values))
            return [];

        return $values;
    }

    public function proxy(Redis|Relay $server, Redis|Relay $client,
                          FuzzConfig $config, KeySample $keys): mixed
    {
        $vals = $this->proxiedValues($server, $keys);
        if ( ! $vals)
            return false;

        $vals = array_slice($vals, 0, $config->randomMemberCount());
        $keys = $config->getRandomMembers($this->type(), count($vals));

        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            array_combine($keys, $vals)
        );
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $mems = $config->getRandomMembers($this->type());
        $vals = $config->getRandomValues($client, $this->type(), count($mems));

        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            array_combine($mems, $vals)
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $args = [$config->getRandomKey($this->type())];

        $mems = rand(1, $config->getMembers());
        for ($i = 0; $i < $mems; $i++) {
            $args[] = $config->getRandomMember($this->type());
            $args[] = $config->getRandomValue($client, $this->type());
        }

        return $this->execRaw($client, ...$args);
    }
}
