<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\ScriptLogger;
use Mgrunder\PhpredisCommandFuzzer\ScriptClosure;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

class listen extends Command implements FuzzInterface {
    /** @var list<ScriptClosure|null> */
    private array $closures = [];

    public function __construct() {
        $this->closures = [
            new ScriptClosure(fn($e, $p) => false, 'fn($e, $p) => false'),
            new ScriptClosure(
                fn(\Relay\Event $e, ?string $p) => false,
                'fn(Relay\Event $event, ?string $pattern) => false'
            ),
            new ScriptCLosure(function (\Relay\Event $e, ?string $p) {
                return $e->key !== null && $e->client;
            },
            'function (\Relay\Event $e, ?string $p) { return $e->key !== null && $e->client; }'),
            null
        ];
    }

    public function flags(): int {
        return self::READ | self::LOCAL;
    }

    public function type(): string {
        return self::NONE;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        $closure = $this->closures[array_rand($this->closures)];

        return $this->exec($client, $closure);
    }
}
