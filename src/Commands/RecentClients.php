<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Commands;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

/**
 * Run-owned CLIENT LIST observations, shared by the method and raw surfaces.
 *
 * @internal
 * @phpstan-type ClientRecord array{id?: string, addr?: string, user?: string, laddr?: string}
 * @phpstan-type Snapshot array{step: int, records: non-empty-list<ClientRecord>}
 */
final class RecentClients
{
    private const MAX_AGE = 256;
    private const MAX_RECORDS = 64;
    private const MAX_ROUTES = 16;

    private int $step = 0;

    /** @var \WeakMap<Redis|RedisCluster|Relay|Cluster, array<string, Snapshot>> */
    private \WeakMap $snapshots;

    public function __construct()
    {
        $this->snapshots = new \WeakMap();
    }

    public function advanceStep(): void
    {
        $this->step++;
    }

    public function observe(Redis|RedisCluster|Relay|Cluster $client, string $route, mixed $reply): void
    {
        $records = $this->records($reply);
        if ($records === null) {
            return;
        }

        $routes = $this->snapshots[$client] ?? [];
        unset($routes[$route]);
        if ($records !== []) {
            $routes[$route] = ['step' => $this->step, 'records' => $records];
        }
        if (count($routes) > self::MAX_ROUTES) {
            array_shift($routes);
        }
        $this->snapshots[$client] = $routes;
    }

    /** @return array{record: ClientRecord, age: int}|null */
    public function sample(Redis|RedisCluster|Relay|Cluster $client, string $route): ?array
    {
        $routes = $this->snapshots[$client] ?? [];
        $snapshot = $routes[$route] ?? null;
        if ($snapshot === null) {
            return null;
        }
        $age = $this->step - $snapshot['step'];
        if ($age >= self::MAX_AGE) {
            unset($routes[$route]);
            $this->snapshots[$client] = $routes;
            return null;
        }

        return ['record' => $snapshot['records'][array_rand($snapshot['records'])], 'age' => $age];
    }

    /** @return list<ClientRecord>|null Null means this was not a usable LIST reply. */
    private function records(mixed $reply): ?array
    {
        if (is_string($reply)) {
            if ($reply === '') {
                return [];
            }
            $entries = [];
            foreach (explode("\n", $reply) as $line) {
                $entry = [];
                foreach (explode(' ', trim($line)) as $field) {
                    $pair = explode('=', $field, 2);
                    if (count($pair) === 2) {
                        $entry[$pair[0]] = $pair[1];
                    }
                }
                $entries[] = $entry;
            }
        } elseif (is_array($reply)) {
            if ($reply === []) {
                return [];
            }
            $entries = $reply;
        } else {
            return null;
        }

        $records = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $record = [];
            $id = $entry['id'] ?? null;
            if ((is_string($id) || is_int($id)) && preg_match('/^[1-9][0-9]*$/D', (string) $id) === 1) {
                // Preserve decimal IDs without overflowing PHP integers.
                $record['id'] = (string) $id;
            }
            foreach (['addr', 'user', 'laddr'] as $field) {
                $value = $entry[$field] ?? null;
                if (is_string($value) && $value !== '' && preg_match('/[\s\x00]/', $value) === 0) {
                    $record[$field] = $value;
                }
            }
            if (!isset($record['id']) && !isset($record['addr'])) {
                continue;
            }
            $records[] = $record;
            if (count($records) === self::MAX_RECORDS) {
                break;
            }
        }

        return $records === [] ? null : $records;
    }
}
