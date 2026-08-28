<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class ClientFactory
{
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
                $configuration->seeds,
                $configuration->timeout,
                $configuration->readTimeout,
                false,
                $configuration->auth,
            );
        } else {
            /** @var Redis|Relay $client */
            $client = new $class();
            $context = $configuration->auth === null ? [] : ['auth' => $configuration->auth];
            $connected = $client->connect(
                $configuration->host,
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

        if (!$configuration->relayCluster->isEmpty()) {
            if (!$client instanceof Cluster) {
                throw new \RuntimeException('Relay cluster options require a Relay\\Cluster client');
            }
            $configuration->relayCluster->applyTo($client);
        }

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
