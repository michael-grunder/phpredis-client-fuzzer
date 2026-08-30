<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

abstract class SetCardinalityCommand extends Command implements FuzzInterface,
                                                              FuzzRawInterface
{
    abstract protected function supportsApprox(): bool;

    public function type(): string {
        return self::SET;
    }

    public function flags(): int {
        return self::READ | self::CACHED;
    }

    /** @return array<int|string, int|string> */
    private function options(FuzzConfig $config): array {
        $options = [];

        if (rand() & 1)
            $options['LIMIT'] = rand(0, $config->getMembers());

        if ($this->supportsApprox() && rand() & 1)
            $options[] = 'APPROX';

        return $options;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $keys = $config->getRandomKeys($this->type());

        return match (rand(0, 3)) {
            0 => $this->exec($client, $keys),
            1 => $this->exec($client, $keys, null),
            2 => $this->exec($client, $keys, []),
            3 => $this->exec($client, $keys, $this->options($config)),
        };
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $keys = $config->getRandomKeys($this->type());
        $args = [count($keys), ...$keys];

        foreach ($this->options($config) as $key => $value) {
            if (is_string($key))
                $args[] = $key;
            $args[] = $value;
        }

        return $this->execRaw($client, ...$args);
    }
}
