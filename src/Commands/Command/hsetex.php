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

class hsetex extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::HASH;
    }

    public function flags(): int {
        return self::WRITE | self::EXPIRE | self::INVALIDATING;
    }

    /** @return array<string, mixed> */
    private function fields(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): array
    {
        $fields = [];
        $count = rand(1, $config->getMembers());
        for ($i = 0; $i < $count; $i++) {
            $fields[$config->getRandomMember($this->type())]
                = $config->getRandomValue($client, $this->type());
        }

        return $fields;
    }

    /** @return list<mixed> */
    private function expiryArgument(Redis|RedisCluster|Relay|Cluster $client,
                                    FuzzConfig $config): array
    {
        $max = self::isRelay($client) ? 9 : 7;
        $variant = rand(0, $max);

        if ($variant === 0) {
            return [];
        }
        if ($variant === 1) {
            return [null];
        }
        if ($variant === 8) {
            return [$config->getRandomExpire()];
        }
        if ($variant === 9) {
            return [$config->getRandomExpire() + (mt_rand() / mt_getrandmax())];
        }

        $options = match ($variant) {
            2 => [],
            3 => ['EX' => $config->getRandomExpire()],
            4 => ['PX' => $config->getRandomExpire(true)],
            5 => ['EXAT' => $config->getRandomExpireAt()],
            6 => ['PXAT' => $config->getRandomExpireAt(true)],
            7 => ['KEEPTTL'],
        };

        $condition = match (rand(0, 2)) {
            0 => null,
            1 => 'FNX',
            2 => 'FXX',
        };
        if ($condition !== null) {
            array_unshift($options, $condition);
        }

        return [$options];
    }

    /** @return list<mixed> */
    private function rawOptionArguments(FuzzConfig $config): array {
        $args = [];
        $condition = match (rand(0, 2)) {
            0 => null,
            1 => 'FNX',
            2 => 'FXX',
        };
        if ($condition !== null) {
            $args[] = $condition;
        }

        $expiry = match (rand(0, 5)) {
            0 => [],
            1 => ['EX', $config->getRandomExpire()],
            2 => ['PX', $config->getRandomExpire(true)],
            3 => ['EXAT', $config->getRandomExpireAt()],
            4 => ['PXAT', $config->getRandomExpireAt(true)],
            5 => ['KEEPTTL'],
        };

        return [...$args, ...$expiry];
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        return $this->exec(
            $client,
            $config->getRandomKey($this->type()),
            $this->fields($client, $config),
            ...$this->expiryArgument($client, $config),
        );
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $fields = $this->fields($client, $config);
        $args = [
            $config->getRandomKey($this->type()),
            ...$this->rawOptionArguments($config),
            'FIELDS',
            count($fields),
        ];

        foreach ($fields as $field => $value) {
            $args[] = $field;
            $args[] = $value;
        }

        return $this->execRaw($client, ...$args);
    }
}
