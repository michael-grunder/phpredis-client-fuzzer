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

class bitrangeintarray extends Command implements FuzzInterface, FuzzRawInterface {
    use FuzzGeneric;

    public function type(): string {
        return self::STRING;
    }

    public function flags(): int {
        return self::READ | self::CACHED;
    }

    private function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                 FuzzConfig $config, string $fn): mixed
    {
        $key = $config->getRandomKey($this->type());
        $unit = rand(0, 2);
        $max = $config->getMaxLen() * ($unit === 2 ? 8 : 1);
        $args = [$key, rand(-$max, $max), rand(-$max, $max)];
        $options = match ($unit) {
            0 => [],
            1 => ['BYTE'],
            2 => ['BIT'],
        };
        if (rand() & 1) {
            $options[] = 'ZEROS';
        }
        if (rand() & 1) {
            $options['LIMIT'] = rand(0, $config->getMembers());
        }

        if ($fn === 'execRaw') {
            foreach ($options as $option => $value) {
                if (is_string($option)) {
                    $args[] = $option;
                }
                $args[] = $value;
            }
            return $this->execRaw($client, ...$args);
        }

        return $this->exec($client, ...[...$args, $options]);
    }
}
