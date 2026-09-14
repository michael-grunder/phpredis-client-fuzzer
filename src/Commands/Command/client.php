<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;
use Mgrunder\PhpredisCommandFuzzer\Log\Log;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

class client extends Command implements FuzzInterface, FuzzRawInterface
{
    use FuzzGeneric;

    // These subcommands also work with PhpRedis's narrower RedisCluster API.
    private const SUBCOMMANDS = ['LIST', 'GETNAME', 'SETNAME', 'KILL'];

    public function flags(): int
    {
        return self::READ | self::WRITE | self::ADMIN;
    }

    public function type(): string
    {
        return self::NONE;
    }

    public function fuzzGeneric(Redis|RedisCluster|Relay|Cluster $client,
                                FuzzConfig $config, string $fn): mixed
    {
        $route = $this->clusterRawRoutingKey();
        $subcommand = self::SUBCOMMANDS[array_rand(self::SUBCOMMANDS)];
        $args = [];

        if ($subcommand === 'SETNAME') {
            // CLIENT names must not contain whitespace or binary bytes.
            $args[] = 'phpredis-fuzz-' . bin2hex($config->getRandomBytes(rand(1, 32)));
        } elseif ($subcommand === 'KILL') {
            return $this->kill($client, $config, $fn, $route);
        }

        $reply = $this->call($client, $fn, $route, $subcommand, ...$args);
        if ($subcommand === 'LIST') {
            $config->recentClients()->observe($client, $this->observationRoute($client, $route), $reply);
        }
        return $reply;
    }

    private function observationRoute(Redis|RedisCluster|Relay|Cluster $client, string $route): string
    {
        return $this->isCluster($client) ? $route : '';
    }

    private function kill(Redis|RedisCluster|Relay|Cluster $client, FuzzConfig $config,
                          string $fn, string $route): mixed
    {
        $observed = $config->recentClients()->sample($client, $this->observationRoute($client, $route));
        $record = $observed['record'] ?? [];

        // PhpRedis Cluster's method accepts only a legacy address, not filters.
        if ($client instanceof RedisCluster && $fn !== 'execRaw') {
            if (isset($record['addr'])) {
                $this->logObservation($client, $route, $observed);
                return $this->call($client, $fn, $route, 'KILL', $record['addr']);
            }
            // Seed a later step; never attempt to consume a queued LIST result.
            $reply = $this->call($client, $fn, $route, 'LIST');
            $config->recentClients()->observe($client, $route, $reply);
            return $reply;
        }

        /** @var list<string|array<string, string>> $targets */
        $targets = [];
        if (isset($record['addr'])) {
            $targets[] = $record['addr'];
            $targets[] = ['ADDR' => $record['addr']];
        }
        if (isset($record['id'])) {
            $targets[] = ['ID' => $record['id']];
            foreach (['addr', 'user', 'laddr'] as $field) {
                if (isset($record[$field])) {
                    $targets[] = ['ID' => $record['id'], strtoupper($field) => $record[$field]];
                }
            }
            // An intentionally contradictory address exercises the zero-match path.
            $targets[] = ['ID' => $record['id'], 'ADDR' => '127.0.0.1:0'];
        }

        if ($targets !== [] && rand(0, 4) !== 0) {
            $selection = $targets[array_rand($targets)];
            $this->logObservation($client, $route, $observed);
            if (is_string($selection)) {
                return $this->call($client, $fn, $route, 'KILL', $selection);
            }
            $filters = $selection;
        } else {
            // Omitting TYPE covers all types; TYPE all is not valid Redis syntax.
            $variants = [[], ['TYPE' => 'normal'], ['TYPE' => 'master'],
                ['TYPE' => 'replica'], ['TYPE' => 'slave'], ['TYPE' => 'pubsub']];
            foreach (['user', 'laddr'] as $field) {
                if (isset($record[$field])) {
                    $variants[] = [strtoupper($field) => $record[$field]];
                }
            }
            // PhpRedis's filter-array parser silently ignores MAXAGE. Exercise it
            // on the raw/Relay surfaces, where it reaches Redis (7.4+).
            if ($fn === 'execRaw' || self::isRelay($client)) {
                $variants[] = ['MAXAGE' => (string) rand(0, 300)];
            }
            $filters = $variants[array_rand($variants)];
            if (isset($filters['USER']) || isset($filters['LADDR'])) {
                $this->logObservation($client, $route, $observed);
            }
        }

        $skipme = rand(0, 2);
        if ($skipme !== 0 || $filters === []) {
            $filters['SKIPME'] = $skipme === 2 ? 'no' : 'yes';
        }
        if ($client instanceof Redis && $fn !== 'execRaw') {
            return $this->call($client, $fn, $route, 'KILL', $filters);
        }
        $args = [];
        foreach ($filters as $filter => $value) {
            $args[] = $filter;
            $args[] = $value;
        }
        return $this->call($client, $fn, $route, 'KILL', ...$args);
    }

    /** @param array{record: array<string, string>, age: int}|null $observed */
    private function logObservation(Redis|RedisCluster|Relay|Cluster $client, string $route,
                                    ?array $observed): void
    {
        Log::debug('CLIENT KILL observation', [
            'client' => $client::class,
            'route' => $this->observationRoute($client, $route),
            'observation' => $observed,
        ]);
    }

    private function call(Redis|RedisCluster|Relay|Cluster $client,
                          string $fn, string $route, string $subcommand,
                          mixed ...$args): mixed
    {
        if ($fn === 'execRaw') {
            return $this->execRaw($client, $subcommand, ...$args);
        }
        if ($this->isCluster($client)) {
            return $this->exec($client, $route, $subcommand, ...$args);
        }

        return $this->exec($client, $subcommand, ...$args);
    }
}
