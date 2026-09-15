<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Cli;

use Mgrunder\PhpredisCommandFuzzer\ClientConfiguration;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\info;
use Mgrunder\PhpredisCommandFuzzer\Log\Log;

/** @internal CLI selection before workload clients and hooks are started. */
final class ClientTopologySelector
{
    public function __construct(private readonly ?\Redis $probe = null)
    {
    }

    /**
     * @param non-empty-list<ClientConfiguration> $configurations
     * @return non-empty-list<ClientConfiguration>
     */
    public function select(array $configurations): array
    {
        $first = $configurations[0];
        $mixed = false;
        foreach ($configurations as $configuration) {
            if ($configuration->type->isCluster() !== $first->type->isCluster()) {
                $mixed = true;
                break;
            }
        }
        // Preserve deliberate mismatches and avoid a needless connection.
        if (!$mixed) {
            return $configurations;
        }

        $cluster = $this->isCluster($first);
        $selected = array_values(array_filter(
            $configurations,
            static fn (ClientConfiguration $configuration): bool => $configuration->type->isCluster() === $cluster,
        ));
        if ($selected === []) {
            throw new \LogicException('A mixed client list must contain a topology match');
        }

        Log::debug('Selected clients matching target topology', [
            'host' => $first->host,
            'port' => $first->port,
            'topology' => $cluster ? 'cluster' : 'standalone',
            'clients' => array_map(
                static fn (ClientConfiguration $configuration): string => $configuration->type->value,
                $selected,
            ),
        ]);

        return $selected;
    }

    private function isCluster(ClientConfiguration $configuration): bool
    {
        $probe = $this->probe ?? new \Redis();
        $auth = is_string($configuration->auth) ? [$configuration->auth] : $configuration->auth;
        $connected = $probe->connect(
            $configuration->host,
            $configuration->port,
            $configuration->timeout,
            null,
            0,
            $configuration->readTimeout,
            $auth === null ? [] : ['auth' => $auth],
        );
        if (!$connected) {
            throw new \RuntimeException('--pick-client could not connect to the target');
        }

        try {
            $result = (new info())->exec($probe, 'cluster');
            $enabled = is_array($result) ? ($result['cluster_enabled'] ?? null) : null;
            return match ($enabled) {
                0, '0' => false,
                1, '1' => true,
                default => throw new \RuntimeException(
                    '--pick-client requires INFO cluster to return cluster_enabled=0 or 1',
                ),
            };
        } finally {
            $probe->close();
        }
    }
}
