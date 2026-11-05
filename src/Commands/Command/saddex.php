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

class saddex extends Command implements FuzzInterface, FuzzRawInterface {
    use FuzzGeneric;

    public function type(): string {
        return self::SET;
    }

    public function flags(): int {
        return self::WRITE | self::EXPIRE | self::INVALIDATING;
    }

    private function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                 FuzzConfig $config, string $fn): mixed
    {
        $key = $config->getRandomKey($this->type());
        $members = $config->getRandomMembers($this->type());
        $options = match (rand(0, 5)) {
            0 => [],
            1 => ['EX' => $config->getRandomExpire()],
            2 => ['PX' => $config->getRandomExpire(true)],
            3 => ['EXAT' => $config->getRandomExpireAt()],
            4 => ['PXAT' => $config->getRandomExpireAt(true)],
            5 => ['KEEPTTL'],
        };
        $condition = match (rand(0, 2)) {
            0 => null,
            1 => 'MNX',
            2 => 'MXX',
        };
        if ($condition !== null) {
            array_unshift($options, $condition);
        }

        if ($fn === 'execRaw') {
            $args = [$key];
            foreach ($options as $option => $value) {
                if (is_string($option)) {
                    $args[] = $option;
                }
                $args[] = $value;
            }
            return $this->execRaw($client, ...[...$args, 'MEMBERS', count($members), ...$members]);
        }

        return $this->exec($client, $key, $members, $options);
    }
}
