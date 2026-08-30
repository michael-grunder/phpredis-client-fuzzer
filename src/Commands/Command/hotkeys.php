<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

class hotkeys extends Command implements FuzzInterface, FuzzRawInterface {
    public function type(): string {
        return self::NONE;
    }

    public function flags(): int {
        return self::READ | self::WRITE | self::ADMIN | self::STATEFUL;
    }

    /** @return array<string, int|string|list<int>|list<string>> */
    private function options(): array {
        $metrics = match (rand(0, 2)) {
            0 => 'CPU',
            1 => 'NET',
            2 => ['CPU', 'NET'],
        };
        $options = ['METRICS' => $metrics];
        $rng = rand();

        if ($rng & 1) {
            $options['COUNT'] = rand(1, 64);
        }
        if ($rng & 2) {
            $options['DURATION'] = rand(1, 5);
        }
        if ($rng & 4) {
            $options['SAMPLE'] = rand(1, 100);
        }
        if ($rng & 8) {
            $slots = [];
            $count = rand(1, 4);
            while (count($slots) < $count) {
                $slots[rand(0, 16383)] = true;
            }
            $options['SLOTS'] = array_keys($slots);
        }

        return $options;
    }

    /**
     * @param array<string, int|string|list<int>|list<string>> $options
     * @return list<mixed>
     */
    private function rawOptions(array $options): array {
        $metrics = $options['METRICS'];
        $metrics = is_array($metrics) ? $metrics : [$metrics];
        $args = ['METRICS', count($metrics), ...$metrics];

        foreach (['COUNT', 'DURATION', 'SAMPLE'] as $name) {
            if (isset($options[$name])) {
                array_push($args, $name, $options[$name]);
            }
        }

        $slots = $options['SLOTS'] ?? null;
        if (is_array($slots)) {
            array_push($args, 'SLOTS', count($slots), ...$slots);
        }

        return $args;
    }

    /** @param array<string, int|string|list<int>|list<string>>|null $options */
    private function call(Redis|RedisCluster|Relay|Cluster $client,
                          string $subcommand, bool $raw,
                          ?array $options = null): mixed
    {
        if ($raw) {
            $args = $options === null ? [] : $this->rawOptions($options);
            return $this->execRaw($client, $subcommand, ...$args);
        }

        if ($options === null) {
            return $this->exec($client, $subcommand);
        }

        return $this->exec($client, $subcommand, $options);
    }

    private function exercise(Redis|RedisCluster|Relay|Cluster $client,
                              bool $raw): mixed
    {
        $subcommand = ['HELP', 'GET', 'RESET', 'START', 'STOP'][rand(0, 4)];
        if ($subcommand !== 'START') {
            return $this->call($client, $subcommand, $raw);
        }

        try {
            return $this->call($client, 'START', $raw, $this->options());
        } finally {
            try {
                $this->call($client, 'STOP', $raw);
            } finally {
                $this->call($client, 'RESET', $raw);
            }
        }
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        return $this->exercise($client, false);
    }

    public function fuzzRaw(Redis|RedisCluster|Relay|Cluster $client,
                            FuzzConfig $config): mixed
    {
        return $this->exercise($client, true);
    }
}
