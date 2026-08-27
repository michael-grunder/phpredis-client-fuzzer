<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\KeyIntCommand;

use Relay\Relay;
use Relay\Cluster;

use Redis;
use RedisCluster;

class move extends KeyIntCommand {
    private static ?int $databases = null;

    public function flags(): int {
        return self::WRITE | self::INVALIDATING;
    }

    public function type(): string {
        return self::ANY;
    }

    public function getInt(Redis|RedisCluster|Relay|Cluster $client): int {
        if (self::$databases === null) {
            $cfg = $this->cmd($client, 'config', 'get', 'databases');
            $value = is_array($cfg) ? ($cfg['databases'] ?? null) : null;
            self::$databases = is_scalar($value) && is_numeric($value) ? (int) $value : 16;
        }

        return rand(0, max(1, self::$databases) - 1);
    }
}
