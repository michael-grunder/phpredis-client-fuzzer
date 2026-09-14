<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Commands\Command;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzRawInterface;
use Mgrunder\PhpredisCommandFuzzer\Commands\Traits\FuzzGeneric;
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
        // Reuse one node for the lookup and kill, on both execution surfaces.
        $route = $this->clusterRawRoutingKey();
        $subcommand = self::SUBCOMMANDS[array_rand(self::SUBCOMMANDS)];
        $args = [];

        if ($subcommand === 'SETNAME') {
            // CLIENT names must not contain whitespace or binary bytes.
            $args[] = 'phpredis-fuzz-' . bin2hex($config->getRandomBytes(rand(1, 32)));
        } elseif ($subcommand === 'KILL') {
            $reply = $this->call($client, $fn, $route, 'LIST');
            $addresses = $this->addresses($reply);
            if ($addresses === []) {
                return $reply;
            }
            $args[] = $addresses[array_rand($addresses)];
        }

        return $this->call($client, $fn, $route, $subcommand, ...$args);
    }

    private function call(Redis|RedisCluster|Relay|Cluster $client,
                          string $fn, string $route, string $subcommand,
                          string ...$args): mixed
    {
        if ($fn === 'execRaw') {
            return $this->execRaw($client, $subcommand, ...$args);
        }
        if ($this->isCluster($client)) {
            return $this->exec($client, $route, $subcommand, ...$args);
        }

        return $this->exec($client, $subcommand, ...$args);
    }

    /** @return list<string> */
    private function addresses(mixed $reply): array
    {
        if (is_string($reply)) {
            $addresses = [];
            foreach (explode("\n", $reply) as $line) {
                if (preg_match('/(?:^| )addr=([^\s]+)/', $line, $matches) === 1) {
                    $addresses[] = $matches[1];
                }
            }
            return $addresses;
        }

        $addresses = [];
        if (is_array($reply)) {
            foreach ($reply as $entry) {
                if (is_array($entry) && isset($entry['addr'])
                    && is_string($entry['addr']) && $entry['addr'] !== '') {
                    $addresses[] = $entry['addr'];
                }
            }
        }

        return $addresses;
    }
}
