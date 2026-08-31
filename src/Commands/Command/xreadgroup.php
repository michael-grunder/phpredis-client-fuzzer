<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Mgrunder\PhpredisCommandFuzzer\Data\Events;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class xreadgroup extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::STREAM;
    }

    public function flags(): int {
        return self::WRITE | self::BLOCKING;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        [$keys, $count, $block] = $this->arguments($config);
        $args = ['fuzzer', $config->getConsumer(), $keys];

        if ($count !== null)
            $args[] = $count;
        if ($block !== null)
            $args[] = $block;

        return $this->exec($client, ...$args);
    }

    /** @return array{array<string, string>, ?int, ?int} */
    private function arguments(FuzzConfig $config): array {
        $keys = array_flip($config->getRandomKeys($this->type()));
        foreach ($keys as &$id) {
            $id = Events::instance()->randomReadId();
        }
        unset($id);

        $count = null;
        $block = null;
        switch (rand(0, 2)) {
            case 2:
                $count = $config->randomMemberCount();
                $block = $config->getRandomTimeoutMs();
                break;
            case 1:
                $count = $config->randomMemberCount();
                break;
            case 0:
                break;
        }

        return [$keys, $count, $block];
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        [$streams, $count, $block] = $this->arguments($config);
        $args = ['GROUP', 'fuzzer', $config->getConsumer()];

        if ($count !== null)
            array_push($args, 'COUNT', $count);
        if ($block !== null)
            array_push($args, 'BLOCK', $block);

        $args[] = 'STREAMS';
        array_push($args, ...array_keys($streams), ...array_values($streams));

        return $this->execRaw($client, ...$args);
    }
}
