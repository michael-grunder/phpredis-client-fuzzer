<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

abstract class HashFieldExpiryCommand extends Command implements FuzzInterface,
                                                                 FuzzRawInterface
{
    abstract protected function expiry(FuzzConfig $config): int;

    public function type(): string {
        return self::HASH;
    }

    public function flags(): int {
        return self::WRITE | self::EXPIRE | self::INVALIDATING;
    }

    protected function mode(): ?string {
        return match (rand(0, 4)) {
            0 => null,
            1 => 'NX',
            2 => 'XX',
            3 => 'GT',
            4 => 'LT',
        };
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $args = [
            $config->getRandomKey($this->type()),
            $this->expiry($config),
            $config->getRandomMembers($this->type()),
        ];

        $mode = $this->mode();
        if ($mode !== null) {
            $args[] = $mode;
        }

        return $this->exec($client, ...$args);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $fields = $config->getRandomMembers($this->type());
        $args = [
            $config->getRandomKey($this->type()),
            $this->expiry($config),
        ];

        $mode = $this->mode();
        if ($mode !== null) {
            $args[] = $mode;
        }

        array_push($args, 'FIELDS', count($fields), ...$fields);

        return $this->execRaw($client, ...$args);
    }
}
