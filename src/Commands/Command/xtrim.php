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

class xtrim extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::STREAM;
    }

    public function flags(): int {
        return self::WRITE;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $args = [
            $config->getRandomKey($this->type()),
            Events::instance()->randomId(),
        ];

        switch (rand() % 4) {
            case 3:
                $approx = rand() & 1;
                $args[] = $approx;
                $args[] = rand() & 1;
                if ($approx)
                    $args[] = rand(0, $config->randomMemberCount());
                break;
            case 2:
                $args[] = rand() & 1;
                $args[] = rand() & 1;
                break;
            case 1:
                $args[] = rand() & 1;
                break;
            default:
                // No extra args
        }

        return $this->exec($client, ...$args);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $rand = rand();

        $args = [
            $config->getRandomKey($this->type()),
        ];

        if ($rand & 1) {
            $args[] = 'MINID';
            $args[] = Events::instance()->randomId();
        } else {
            $args[] = 'MAXLEN';
            $args[] = rand(0, $config->getMembers());
        }

        $args[] = $rand & 2 ? '=' : '~';
        $args[] = Events::instance()->randomId();

        if ($rand & 4) {
            $args[] = 'LIMIT';
            $args[] = rand(0, $config->getMembers());
        }

        return $this->execRaw($client, ...$args);
    }
}
