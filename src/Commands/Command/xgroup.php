<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\ProxyInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeySample;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;
use Mgrunder\PhpredisCommandFuzzer\Data\Events;
use Mgrunder\PhpredisCommandFuzzer\Utilities;

use Redis;
use RedisCluster;

use Relay\Relay;
use Relay\Cluster;

class xgroup extends Command implements FuzzInterface {
    use FuzzGeneric;

    private const DESTRUCTIVE_PCT = .05;

    private const U_OPS = ['CREATE' => 1, 'SETID' => 1, 'CREATECONSUMER' => 1];
    private const D_OPS = ['DELCONSUMER' => 1, 'DESTROY' => 1];

    public function type(): string {
        return self::STREAM;
    }

    public function flags(): int {
        return self::WRITE;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        if (Utilities::randomChance(self::DESTRUCTIVE_PCT)) {
            $ops = self::D_OPS;
        } else {
            $ops = self::U_OPS;
        }

        $key = $config->getRandomKey($this->type());
        $id = Events::instance()->randomReadId();
        $op = array_rand($ops);

        $args = [$op, $key, 'fuzzer'];

        switch ($op) {
            case 'CREATE':
            case 'SETID':
            case 'CREATECONSUMER':
            case 'DELCONSUMER':
                $args[] = $id;
                $args[] = rand() & 1;
                break;
             case 'DESTROY':
                break;
            default:
                die("Unreachable op: " . $op . "\n");
        }

        return $this->$fn($client, $op, ...$args);
    }
}
