<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\Data\Events;

use Redis;
use RedisCluster;

use Relay\Relay;
use Relay\Cluster;

abstract class XRangeCommand extends Command implements FuzzInterface,
                                                        FuzzRawInterface
{
    use Traits\FuzzGeneric;

    /**
     * @return array{0: string, 1: string}
     */
    abstract protected function randomIdRange(): array;

    public function type(): string {
        return self::STREAM;
    }

    public function flags(): int {
        return self::READ;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        $key = $config->getRandomKey($this->type());

        [$a, $b] = $this->randomIdRange();

        $args = [$key, $a, $b];

        if (rand() & 1) {
            if ($fn == 'execRaw')
                $args[] = 'COUNT';
            $args[] = rand(1, $config->getMembers());
        }

        return $this->$fn($client, ...$args);
    }
}
