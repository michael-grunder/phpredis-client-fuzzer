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

class bitfield extends Command implements FuzzInterface, FuzzRawInterface {
    private const OPERATIONS = ['GET', 'SET', 'INCRBY'];
    private const OVERFLOW = ['WRAP', 'SAT', 'FAIL'];

    public function type(): string {
        return self::STRING;
    }

    public function flags(): int {
        return self::READ | self::WRITE | self::INVALIDATING;
    }

    private function encoding(): string {
        return rand() & 1
            ? 'i' . rand(1, 64)
            : 'u' . rand(1, 63);
    }

    private function offset(FuzzConfig $config): int|string {
        $offset = rand(0, $config->getMembers());

        return rand() & 1 ? $offset : '#' . $offset;
    }

    /** @return array<mixed> */
    private function arguments(FuzzConfig $config): array {
        $args = [$config->getRandomKey($this->type())];
        $operations = rand(0, 4);

        for ($i = 0; $i < $operations; $i++) {
            $operation = self::OPERATIONS[array_rand(self::OPERATIONS)];

            if ($operation !== 'GET' && rand(0, 3) === 0) {
                $args[] = 'OVERFLOW';
                $args[] = self::OVERFLOW[array_rand(self::OVERFLOW)];
            }

            $args[] = $operation;
            $args[] = $this->encoding();
            $args[] = $this->offset($config);

            if ($operation !== 'GET')
                $args[] = $config->getRandomInt();
        }

        return $args;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        return $this->exec($client, ...$this->arguments($config));
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        return $this->execRaw($client, ...$this->arguments($config));
    }
}
