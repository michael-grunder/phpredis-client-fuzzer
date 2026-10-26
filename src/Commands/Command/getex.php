<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\FuzzRawInterface;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class getex extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::STRING;
    }

    public function flags(): int {
        return self::WRITE | self::EXPIRE;
    }

    /**
     * @return array<mixed>
     */
    private function getOptions(FuzzConfig $config): array {
        return match (rand(0, 4)) {
            0 => ['EX'     => $config->getRandomExpire()],
            1 => ['PX'     => $config->getRandomExpire(true)],
            2 => ['EXAT'   => $config->getRandomExpireAt()],
            3 => ['PXAT'   => $config->getRandomExpireAt(true)],
            4 => ['PERSIST'],
        };
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed {
        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            $this->getOptions($config),
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $options = $this->getOptions($config);
        foreach ($options as $k => $v) {
            if (is_string($k)) {
                $options = [$k, $v];
            }
        }

        return $this->execRaw(
            $client,
            $config->getRandomKey($this->type()),
            ...$options,
        );
    }
}
