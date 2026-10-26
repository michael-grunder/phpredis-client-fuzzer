<?php

namespace Mgrunder\RedisClientTester\Commands\Command;

use Mgrunder\RedisClientTester\Commands\FunctionCommand;
use Mgrunder\RedisClientTester\Commands\FuzzInterface;
use Mgrunder\RedisClientTester\Commands\FuzzRawInterface;
use Mgrunder\RedisClientTester\Commands\FuzzConfig;
use Mgrunder\RedisClientTester\Commands\Traits\FuzzGeneric;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class function_cmd extends FunctionCommand implements FuzzInterface, FuzzRawInterface {
    use FuzzGeneric;

    /* TODO: The other ones */
    private const SUBCMDS = [
        'load' => true,
        'list' => true,
    ];

    public function name(): string {
        return 'function';
    }

    public function flags(): int {
        return self::WRITE;
    }

    public function type(): string {
        return self::ANY;
    }

    private function load(Redis|RedisCluster|Relay|Cluster $client,
                          string $fn): mixed
    {
        $name = array_rand(self::LUA_LIBRARIES);
        $code = self::LUA_LIBRARIES[$name];

        return $this->$fn($client, 'load', 'replace', $code);
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        $scmd = array_rand(self::SUBCMDS);

        return match($scmd) {
            'load' => $this->load($client, $fn),
            'list' => $this->$fn($client, 'list'),
        };
    }
}
