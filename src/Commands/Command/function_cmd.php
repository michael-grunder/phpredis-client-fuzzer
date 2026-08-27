<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\FunctionCommand;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class function_cmd extends FunctionCommand implements FuzzInterface {
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

    private function load(Redis|RedisCluster|Relay|Cluster $client) : mixed {
        $name = array_rand(self::LUA_LIBRARIES);
        $code = self::LUA_LIBRARIES[$name];

        return $this->exec($client, 'load', 'replace', $code);
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $scmd = array_rand(self::SUBCMDS);

        return match($scmd) {
            'load' => $this->load($client),
            'list' => $this->exec($client, 'list'),
            default => throw new \LogicException("Unknown FUNCTION subcommand: {$scmd}"),
        };
    }
}
