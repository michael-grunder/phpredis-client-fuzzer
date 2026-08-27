<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Data\Events;
use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class xadd extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::STREAM;
    }

    public function flags(): int {
        return self::WRITE;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $key = $config->getRandomKey($this->type());
        $fields = Events::instance()->randomEvent();

        $args = [$key, Events::instance()->nextId($key), $fields];

        $rng = rand();

        if ($rng & 0x1) {
            $args[] = $rng % $config->getMembers();
            if ($rng & 4)
                $args[] = ($rng & 0x8) != 0;
            if ($rng & 0x10)
                $args[] = ($rng & 0x20) != 0;
        }

        return $this->exec($client, ...$args);
    }

    // key [NOMKSTREAM] [MAXLEN|MINID [=|~] threshold [LIMIT count]] *|id \
    // field value [field va   l
    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $args = [$config->getRandomKey($this->type())];
        $rand = rand();

        if (rand() & 1)
            $args[] = 'NOMKSTREAM';

        if (rand() & 2) {
            $args[] = rand() & 4 ? 'MAXLEN' : 'MINID';
            if (rand() & 8)
                $args[] = rand() & 16 ? '=' : '~';
            $args[] = rand(1, $config->getMembers());
            if (rand() & 32) {
                $args[] = 'LIMIT';
                $args[] = rand(1, $config->getMembers());
            }
        }

        $args[] = Events::instance()->randomId();
        foreach (Events::instance()->randomEvent() as $field => $value) {
            $args[] = $field;
            $args[] = $value;
        }

        return $this->execRaw($client, ...$args);
    }
}
