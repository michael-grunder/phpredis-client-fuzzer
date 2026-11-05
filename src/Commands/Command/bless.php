<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\FuzzRawInterface;
use Mgrunder\RedisClientTester\Commands\Traits\FuzzGeneric;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

class bless extends Command implements FuzzInterface, FuzzRawInterface {
    use FuzzGeneric;

    public function type(): string {
        return self::ANY;
    }

    public function flags(): int {
        return self::READ | self::WRITE | self::ADMIN | self::SCAN;
    }

    private function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                 FuzzConfig $config, string $fn): mixed
    {
        $operation = ['SET', 'CLEAR', 'GET', 'SCAN'][rand(0, 3)];
        $key = $config->getRandomKey($this->type());

        if ($operation === 'SCAN') {
            $count = rand(1, $config->getCmdMaxKeys());
            if ($fn === 'execRaw') {
                return $this->execRaw($client, 'SCAN', 0, 'NO-EVICT', 'COUNT', $count);
            }
            if ($this->cmd($client, 'getMode') !== Redis::ATOMIC) {
                $operation = 'GET';
            } else {
                $args = ['SCAN', null, 'NO-EVICT', ['COUNT' => $count]];
                if ($this->isCluster($client)) {
                    $args[] = $key;
                }
                return $this->cmdArguments($client, $this->name(), $args, iterable: true);
            }
        }

        $args = [$operation, $key];
        if ($operation !== 'GET') {
            $args[] = 'NO-EVICT';
        }
        return $this->$fn($client, ...$args);
    }
}
