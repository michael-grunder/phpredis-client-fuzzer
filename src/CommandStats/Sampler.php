<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\CommandStats;

use Mgrunder\PhpredisCommandFuzzer\ClientConfiguration;
use Mgrunder\PhpredisCommandFuzzer\ClientFactory;
use Mgrunder\PhpredisCommandFuzzer\ClientType;
use Redis;
use Relay\Relay;

final class Sampler
{
    /** @var array<string, Redis|Relay> */
    private array $clients = [];

    /** @var list<Node> */
    private array $nodes;

    /**
     * @param non-empty-list<string> $seeds
     * @param string|array{0: string, 1: string}|null $auth
     */
    public function __construct(
        private readonly ClientType $type,
        private readonly string $host,
        private readonly int $port,
        private readonly array $seeds,
        private readonly float $timeout,
        private readonly float $readTimeout,
        private readonly string|array|null $auth,
        private readonly ClientFactory $factory = new ClientFactory(),
        private readonly ClusterNodesParser $topologyParser = new ClusterNodesParser(),
    ) {
        $this->nodes = $type->isCluster()
            ? []
            : [new Node($host, $port, NodeRole::Primary)];
    }

    public function sample(): Sample
    {
        $errors = [];
        if ($this->type->isCluster()) {
            try {
                $this->nodes = $this->discover();
            } catch (\Throwable $throwable) {
                if ($this->nodes === []) {
                    throw $throwable;
                }
                $errors[] = 'topology: ' . $throwable->getMessage();
            }
        }

        $readings = [];
        foreach ($this->nodes as $node) {
            try {
                $reply = $this->client($node)->info('commandstats');
                if (!is_array($reply)) {
                    throw new \RuntimeException('INFO commandstats returned no data');
                }
                $readings[] = new Reading($node, self::calls($reply));
            } catch (\Throwable $throwable) {
                $this->forget($node->key());
                $errors[] = $node->key() . ': ' . $throwable->getMessage();
            }
        }

        if ($readings === []) {
            throw new \RuntimeException('No Redis nodes returned INFO commandstats');
        }

        return new Sample($readings, $errors);
    }

    public function close(): void
    {
        foreach (array_keys($this->clients) as $key) {
            $this->forget($key);
        }
    }

    /**
     * @param array<mixed> $reply
     * @return array<string, int>
     */
    public static function calls(array $reply): array
    {
        $calls = [];
        foreach ($reply as $name => $statistics) {
            if (!is_string($name) || !str_starts_with($name, 'cmdstat_') || !is_string($statistics)) {
                continue;
            }
            if (preg_match('/(?:^|,)calls=(\d+)(?:,|$)/', $statistics, $matches) !== 1) {
                continue;
            }

            $value = filter_var($matches[1], FILTER_VALIDATE_INT);
            if (is_int($value)) {
                $calls[substr($name, strlen('cmdstat_'))] = $value;
            }
        }

        return $calls;
    }

    /** @return list<Node> */
    private function discover(): array
    {
        $failures = [];
        foreach ($this->seeds as $address) {
            $seed = null;
            try {
                $seed = Node::fromAddress($address, NodeRole::Primary);
                $reply = $this->client($seed)->rawCommand('CLUSTER', 'NODES');
                if (!is_string($reply)) {
                    throw new \RuntimeException('CLUSTER NODES returned no topology');
                }
                $nodes = $this->topologyParser->parse($reply);
                if ($nodes === []) {
                    throw new \RuntimeException('CLUSTER NODES contained no connected data nodes');
                }

                return $nodes;
            } catch (\Throwable $throwable) {
                if ($seed !== null) {
                    $this->forget($seed->key());
                }
                $failures[] = "{$address}: {$throwable->getMessage()}";
            }
        }

        throw new \RuntimeException('Cluster discovery failed (' . implode('; ', $failures) . ')');
    }

    private function client(Node $node): Redis|Relay
    {
        $key = $node->key();
        if (isset($this->clients[$key])) {
            return $this->clients[$key];
        }

        $client = $this->factory->create(new ClientConfiguration(
            type: match ($this->type) {
                ClientType::Relay, ClientType::RelayCluster => ClientType::Relay,
                default => ClientType::Redis,
            },
            host: $node->host,
            port: $node->port,
            seeds: [$key],
            timeout: $this->timeout,
            readTimeout: $this->readTimeout,
            auth: $this->auth,
        ));
        if (!$client instanceof Redis && !$client instanceof Relay) {
            throw new \LogicException('Command stats requires a standalone Redis client');
        }

        return $this->clients[$key] = $client;
    }

    private function forget(string $key): void
    {
        $client = $this->clients[$key] ?? null;
        unset($this->clients[$key]);
        if ($client === null) {
            return;
        }

        try {
            $client->close();
        } catch (\Throwable) {
            // The connection is already unusable.
        }
    }
}
