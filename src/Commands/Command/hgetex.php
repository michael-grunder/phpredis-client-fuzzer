<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

class hgetex extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::HASH;
    }

    public function flags(): int {
        return self::READ | self::WRITE | self::EXPIRE | self::INVALIDATING;
    }

    /** @return list<mixed> */
    private function expiryArgument(Redis|RedisCluster|Relay|Cluster $client,
                                    FuzzConfig $config): array
    {
        $max = self::isRelay($client) ? 9 : 7;

        return match (rand(0, $max)) {
            0 => [],
            1 => [null],
            2 => [['EX' => $config->getRandomExpire()]],
            3 => [['PX' => $config->getRandomExpire(true)]],
            4 => [['EXAT' => $config->getRandomExpireAt()]],
            5 => [['PXAT' => $config->getRandomExpireAt(true)]],
            6 => ['PERSIST'],
            7 => [['PERSIST']],
            8 => [$config->getRandomExpire()],
            9 => [$config->getRandomExpire() + (mt_rand() / mt_getrandmax())],
        };
    }

    /** @return list<mixed> */
    private function rawExpiryArguments(FuzzConfig $config): array {
        return match (rand(0, 5)) {
            0 => [],
            1 => ['EX', $config->getRandomExpire()],
            2 => ['PX', $config->getRandomExpire(true)],
            3 => ['EXAT', $config->getRandomExpireAt()],
            4 => ['PXAT', $config->getRandomExpireAt(true)],
            5 => ['PERSIST'],
        };
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $args = [
            $config->getRandomKey($this->type()),
            $config->getRandomMembers($this->type()),
            ...$this->expiryArgument($client, $config),
        ];

        return $this->exec($client, ...$args);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $fields = $config->getRandomMembers($this->type());
        $args = [
            $config->getRandomKey($this->type()),
            ...$this->rawExpiryArguments($config),
            'FIELDS',
            count($fields),
            ...$fields,
        ];

        return $this->execRaw($client, ...$args);
    }
}
