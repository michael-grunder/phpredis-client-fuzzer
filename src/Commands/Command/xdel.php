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

class xdel extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::STREAM;
    }

    public function flags(): int {
        return self::WRITE;
    }

    // public function xdel(string $key, array $ids): Redis|int|false;

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $key = $config->getRandomKey($this->type());
        $num = rand(1, $config->getMembers());
        $ids = Events::instance()->previousIds($key, $num);

        return $this->exec($client, $key, $ids);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $key = $config->getRandomKey($this->type());
        $num = rand(1, $config->getMembers());
        $ids = Events::instance()->previousIds($key, $num);

        return $this->execRaw($client, $key, ...$ids);
    }
}
