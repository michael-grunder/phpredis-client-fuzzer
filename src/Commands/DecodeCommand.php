<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Commands;

use Mgrunder\RedisClientTester\ScriptLogger;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

abstract class DecodeCommand extends Command implements FuzzInterface
{
    abstract protected function encoder(): string;

    public function type(): string
    {
        return self::NONE;
    }

    public function flags(): int
    {
        return self::LOCAL;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config): mixed
    {
        if (!method_exists($client, $this->name()) || !method_exists($client, $this->encoder())) {
            return false;
        }
        $value = $this->encoder() === '_compress'
            ? $config->getRandomValueString() : $config->getRandomValue($client, self::ANY);
        $encoded = $this->cmd($client, $this->encoder(), $value);
        if (!is_string($encoded)) {
            return $encoded;
        }
        $variant = rand(0, 5);
        $corrupt = match ($variant) {
            0 => substr($encoded, 0, rand(0, min(16, strlen($encoded)))),
            1 => substr($encoded, 0, intdiv(strlen($encoded), 2)),
            2 => "\xff\xff\xff\xff" . substr($encoded, 4),
            3 => $encoded . "\0trailing",
            4 => 'a:999999:{',
            5 => '{"wrong":"codec"}',
        };
        ScriptLogger::comment('Decode variant: ' . ['short-header', 'truncated', 'bad-header', 'trailing', 'bad-length', 'wrong-codec'][$variant]);
        try {
            return $this->exec($client, $corrupt);
        } finally {
            // Drain a valid decode after a failed one; do not swallow either exception.
            $this->exec($client, $encoded);
        }
    }
}
