<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Redis;
use RedisCluster;

use Relay\Relay;
use Relay\Cluster;

class xinfo extends Command implements FuzzInterface {
    private const OP = ['CONSUMERS' => 1, 'GROUPS' => 2, 'STREAM' => 3];

    public function type(): string {
        return self::STREAM;
    }

    public function flags(): int {
        return self::READ;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed {
        $op = array_rand(self::OP);

        $args = [$op, $config->getRandomKey($this->type())];
        if ($op == 'STREAM' && rand() & 1) {
            $args[] = $config->randomMemberCount();
        }

        return $this->exec($client, ...$args);
    }
}
