<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Mgrunder\PhpredisCommandFuzzer\Data\Events;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class xreadgroup extends Command implements FuzzInterface {
    public function type(): string {
        return self::STREAM;
    }

    public function flags(): int {
        return self::WRITE | self::BLOCKING;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $keys = array_flip($config->getRandomKeys($this->type()));
        foreach ($keys as &$id) {
            $id = Events::instance()->randomReadId();
        }

        $args = ['fuzzer', $config->getConsumer(), $keys];

        switch (rand() % 2) {
            case 2:
                $args[] = $config->randomMemberCount();
                $args[] = $config->getRandomTimeoutMs();
                break;
            case 1:
                $args[] = $config->randomMemberCount();
                break;
            case 0:
                break;
        }

        return $this->exec($client, ...$args);
    }
}
