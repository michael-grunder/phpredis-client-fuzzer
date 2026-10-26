<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

use Mgrunder\PhpredisCommandFuzzer\Hooks\HookRegistry;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class ClientFactory
{
    /** @var \WeakMap<object, ClientConfiguration>|null */
    private static ?\WeakMap $configurations = null;

    /** @internal Connection options unavailable through client getters, needed for reconnects and replay. */
    public static function configurationFor(object $client): ?ClientConfiguration
    {
        return self::$configurations[$client] ?? null;
    }

    /**
     * @param HookRegistry|null $hooks Optional; when supplied, postConstructor
     *        hooks see every client this factory builds, once it is connected
     *        and fully configured.
     */
    public function __construct(private readonly ?HookRegistry $hooks = null)
    {
    }

    /** @return Redis|RedisCluster|Relay|Cluster */
    public function create(ClientConfiguration $configuration): object
    {
        $class = $configuration->type->className();
        if (!class_exists($class)) {
            throw new \RuntimeException("Redis client class {$class} is not available");
        }

        if ($configuration->type->isCluster()) {
            /** @var RedisCluster|Cluster $client */
            $client = new $class(
                null,
                array_map($configuration->tls->endpoint(...), $configuration->seeds),
                $configuration->timeout,
                $configuration->readTimeout,
                false,
                $configuration->auth,
                $configuration->clusterContext(),
            );
        } else {
            /** @var Redis|Relay $client */
            $client = new $class();
            $context = $configuration->auth === null ? [] : ['auth' => $configuration->auth];
            $context += $configuration->tls->context();
            $connected = $client->connect(
                $configuration->tls->endpoint($configuration->host),
                $configuration->port,
                $configuration->timeout,
                null,
                0,
                $configuration->readTimeout,
                $context,
            );
            if (!$connected) {
                throw new \RuntimeException('The Redis client could not connect');
            }
        }

        $this->setNamedOption($client, Redis::OPT_PREFIX, $configuration->prefix);
        $this->setNamedOption($client, Redis::OPT_SERIALIZER, $this->serializer($configuration->serializer));
        $this->setNamedOption($client, Redis::OPT_COMPRESSION, $this->compression($configuration->compression));

        if (($client instanceof Relay || $client instanceof Cluster)
            && defined(Relay::class . '::OPT_PHPREDIS_COMPATIBILITY')) {
            $this->setNamedOption(
                $client,
                Relay::OPT_PHPREDIS_COMPATIBILITY,
                $configuration->relayCompatibility,
            );
        }

        if ($configuration->slaveFailover !== null) {
            $this->setNamedOption(
                $client,
                $this->namedConstant('slave_failover', ['slave_failover' => $class . '::OPT_SLAVE_FAILOVER'], 'option'),
                $this->namedConstant($configuration->slaveFailover, OptionChoices::SLAVE_FAILOVER, 'slave failover'),
            );
        }

        // Modern Relay options override the corresponding parts of the legacy mode.
        if (!$configuration->relayCluster->isEmpty()) {
            if (!$client instanceof Cluster) {
                throw new \RuntimeException('Relay cluster options require a Relay\\Cluster client');
            }
            $configuration->relayCluster->applyTo($client);
        }

        self::$configurations ??= new \WeakMap();
        self::$configurations[$client] = $configuration;
        $this->hooks?->dispatchPostConstructor($client);

        return $client;
    }

    private function setNamedOption(Redis|RedisCluster|Relay|Cluster $client, int $option, mixed $value): void
    {
        if (!$client->setOption($option, $value)) {
            throw new \RuntimeException("Failed to set Redis client option {$option}");
        }
    }

    private function serializer(string $name): int
    {
        return $this->namedConstant($name, OptionChoices::SERIALIZER, 'serializer');
    }

    private function compression(string $name): int
    {
        return $this->namedConstant($name, OptionChoices::COMPRESSION, 'compression');
    }

    /** @param array<string, string> $constants */
    private function namedConstant(string $name, array $constants, string $kind): int
    {
        $constant = $constants[strtolower($name)] ?? null;
        if ($constant === null) {
            throw new \InvalidArgumentException("Unknown {$kind}: {$name}");
        }
        if (!defined($constant)) {
            throw new \RuntimeException("{$kind} {$name} is not supported by the loaded PhpRedis extension");
        }

        $value = constant($constant);
        if (!is_int($value)) {
            throw new \UnexpectedValueException("{$constant} is not an integer");
        }

        return $value;
    }
}
