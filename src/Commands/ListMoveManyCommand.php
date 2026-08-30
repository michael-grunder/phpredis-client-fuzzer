<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

abstract class ListMoveManyCommand extends Command implements FuzzInterface,
                                                            FuzzRawInterface
{
    private const POSITIONS = ['LEFT' => true, 'RIGHT' => true];
    private const COUNT_TYPES = ['COUNT' => true, 'EXACTLY' => true];
    private const MODES = ['OBO' => true, 'BULK' => true];

    abstract protected function blocking(): bool;

    public function type(): string {
        return self::LIST;
    }

    public function flags(): int {
        return self::WRITE | self::INVALIDATING
            | ($this->blocking() ? self::BLOCKING : 0);
    }

    /** @return array<string, array{int, string}>|null */
    private function options(FuzzConfig $config): ?array {
        if (rand(0, 3) === 0)
            return null;

        return [
            array_rand(self::COUNT_TYPES) => [
                rand(1, $config->getMembers()),
                array_rand(self::MODES),
            ],
        ];
    }

    /** @return array<mixed> */
    private function baseArguments(FuzzConfig $config): array {
        $args = [
            $config->getRandomKey($this->type()),
            $config->getRandomKey($this->type()),
            array_rand(self::POSITIONS),
            array_rand(self::POSITIONS),
        ];

        if ($this->blocking())
            $args[] = $config->getTimeoutFloat();

        return $args;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $args = $this->baseArguments($config);
        $variant = rand(0, 4);

        if ($variant === 1) {
            $args[] = null;
        } else if ($variant === 2) {
            $args[] = [];
        } else if ($variant >= 3) {
            $args[] = $this->options($config) ?? [];
        }

        return $this->exec($client, ...$args);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $args = $this->baseArguments($config);
        $options = $this->options($config);

        if ($options !== null) {
            $type = array_key_first($options);
            if ($type === null)
                throw new \LogicException('LMOVEM options cannot be empty');

            array_push($args, $type, ...$options[$type]);
        }

        return $this->execRaw($client, ...$args);
    }
}
