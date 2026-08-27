<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class debug extends Command implements FuzzRawInterface {
    private const MODES = [
        'OBJECT' => true, 'ERROR' => true, 'PROTOCOL' => true
    ];

    /* NOTE: The 'attrib' protocol debug command produces a protocol error (it
             sends an extra reply). This means it's not a great test. */
    private const TYPES = [
        'array', 'bignum', 'double', 'integer', 'map', 'null', 'push', 'set',
        'verbatim'
    ];

    public function type(): string {
        return self::ANY;
    }

    public function flags(): int {
        return self::READ;
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        $mode = array_rand(self::MODES);

        $arg = match ($mode) {
            'OBJECT' => $config->getRandomKey($this->type()),
            'ERROR' => bin2hex($config->getRandomBytes(rand(1, 1024))),
            'PROTOCOL' => self::TYPES[array_rand(self::TYPES)],
            default => throw new \LogicException("Unknown DEBUG mode: {$mode}"),
        };

        return $this->execRaw($client, $mode, $arg);
    }
}
