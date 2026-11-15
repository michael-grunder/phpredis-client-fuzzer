<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Compatibility;

use Mgrunder\RedisClientTester\ClientConfiguration;
use Mgrunder\RedisClientTester\ClientFactory;
use Mgrunder\RedisClientTester\ClientInvoker;
use Mgrunder\RedisClientTester\CoerciveClientInvoker;
use Mgrunder\RedisClientTester\Commands\Registry;
use Mgrunder\RedisClientTester\Differential\DiffRuntime;
use Mgrunder\RedisClientTester\InvocationRecorder;
use Mgrunder\RedisClientTester\StrictClientInvoker;
use Mgrunder\RedisClientTester\ClientType;
use Mgrunder\RedisClientTester\Values\ValueCode;
use Mgrunder\RedisClientTester\Harness\Fs;
use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

/** @phpstan-type Call array{method: string, command: string, args: string, mode: string, coercive: bool}
 * @phpstan-type Tape array{configuration: ClientConfiguration, calls: list<Call>}
 * @phpstan-import-type Outcome from DiffRuntime
 */
final class Recording
{
    /** @var list<Call> */
    private array $calls = [];
    private ?ClientConfiguration $configuration = null;
    private readonly Registry $registry;

    public function __construct(private readonly string $path)
    {
        $this->registry = new Registry();
    }

    /**
     * @param list<mixed> $arguments */
    public function record(Redis|RedisCluster|Relay|Cluster $client, string $method, array $arguments, ClientInvoker $invoker): void
    {
        if (in_array(strtolower($method), ['getlasterror', 'clearlasterror'], true)) {
            return;
        }
        $this->configuration ??= ClientFactory::configurationFor($client);
        if ($this->configuration === null) {
            throw new \RuntimeException('Compatibility requires a configured client');
        }
        $name = strtolower($method);
        $command = $this->registry->get($method);
        $mode = $command?->comparisonMode() ?? 'exact';
        if (strtolower($method) === 'rawcommand') {
            $name = $arguments[$this->configuration->type->isCluster() ? 1 : 0] ?? null;
            $mode = is_string($name) ? ($this->registry->get($name)?->comparisonMode() ?? 'exact') : 'exact';
            if ($mode === 'map') {
                $mode = (is_string($name) ? strtolower($name) : '') === 'hgetall' ? 'pairs' : 'exact';
            }
        }
        $this->calls[] = ['method' => $method,
            'command' => is_string($name) ? strtolower($name) : strtolower($method),
            'args' => self::freeze($arguments), 'mode' => $mode,
            'coercive' => $invoker instanceof CoerciveClientInvoker];
    }

    /** Snapshot inputs without running user serialization callbacks.
     * @param list<mixed> $arguments
     */
    public static function freeze(array $arguments): string
    {
        return ValueCode::export($arguments);
    }

    /** @return list<mixed> */
    public static function arguments(string $code): array
    {
        $value = eval('return ' . $code . ';');
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('Recorded arguments must be a list');
        }
        return $value;
    }

    public function save(): void
    {
        if ($this->configuration === null || $this->calls === []) {
            throw new \RuntimeException('No compatibility calls recorded');
        }
        self::write($this->path, ['configuration' => $this->configuration, 'calls' => $this->calls]);
    }

    /** Trusted local artifact, like a reproducer PHP script.
     * @return Tape */
    public static function read(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read {$path}");
        }
        $value = unserialize($contents);
        if (!is_array($value) || !($value['configuration'] ?? null) instanceof ClientConfiguration
            || !is_array($value['calls'] ?? null) || !array_is_list($value['calls'])) {
            throw new \UnexpectedValueException('Invalid compatibility recording');
        }
        foreach ($value['calls'] as $call) {
            if (!is_array($call) || !is_string($call['method'] ?? null) || !is_string($call['command'] ?? null) || !is_string($call['args'] ?? null) || !is_string($call['mode'] ?? null) || !is_bool($call['coercive'] ?? null)) {
                throw new \UnexpectedValueException('Invalid recorded call');
            }
        }
        /** @var Tape $value */
        return $value;
    }

    public static function write(string $path, mixed $value): void
    {
        Fs::write($path, serialize($value));
    }

    /**
     * @param Tape $tape
     *
     * @return list<Outcome>
     */
    public static function replay(array $tape, ClientType $type, int $port, string $password): array
    {
        $c = $tape['configuration'];
        $client = (new ClientFactory())->create(new ClientConfiguration(
            type: $type, host: '127.0.0.1', port: $port, seeds: ['127.0.0.1:' . $port],
            timeout: $c->timeout, readTimeout: $c->readTimeout, auth: $password,
            prefix: $c->prefix, serializer: $c->serializer, compression: $c->compression,
            relayCompatibility: $c->relayCompatibility, relayCluster: $c->relayCluster,
            slaveFailover: $c->slaveFailover,
        ));
        $outcomes = [];
        foreach ($tape['calls'] as $call) {
            $outcomes[] = self::invoke($client, $call);
        }
        return $outcomes;
    }

    /**
     * @param Call $call
     * @return Outcome */
    private static function invoke(Redis|RedisCluster|Relay|Cluster $client, array $call): array
    {
        $client->clearLastError();
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = DiffRuntime::normalizeMessage($message);
            return true;
        });
        $value = null;
        $exception = null;
        try {
            $value = InvocationRecorder::invoke($call['coercive'] ? new CoerciveClientInvoker() : new StrictClientInvoker(),
                $client, $call['method'], self::arguments($call['args']));
        } catch (\Throwable $error) {
            $class = in_array($error::class, ['RedisException', 'RedisClusterException', 'Relay\\Exception'], true)
                ? 'ClientException' : $error::class;
            $exception = $class . ': ' . DiffRuntime::normalizeMessage($error->getMessage());
        } finally {
            restore_error_handler();
        }
        $error = $client->getLastError();
        return ['value' => self::canonical($value, $call['mode']), 'exception' => $exception,
            'warnings' => $warnings, 'error' => $error];
    }

    public static function canonical(mixed $value, string $mode): mixed
    {
        if ($value instanceof Redis || $value instanceof RedisCluster || $value instanceof Relay || $value instanceof Cluster) {
            return ['client' => true];
        }
        if ($mode === 'pairs' && is_array($value) && array_is_list($value) && count($value) % 2 === 0) {
            $pairs = array_chunk($value, 2);
            usort($pairs, static fn (array $a, array $b): int => strcmp(serialize($a[0]), serialize($b[0])));
            return self::canonical($pairs, 'exact');
        }
        // Keep object properties, PHP types and numeric keys; only declared unordered containers sort.
        if (is_object($value)) {
            return ['type' => 'object', 'class' => $value::class, 'properties' => ValueCode::export($value)];
        }
        if (is_array($value)) {
            $result = array_map(static fn (mixed $item): mixed => self::canonical($item, 'exact'), $value);
            if ($mode === 'set') {
                $result = array_values($result);
                usort($result, static fn (mixed $a, mixed $b): int => strcmp(serialize($a), serialize($b)));
            } elseif ($mode === 'map') {
                ksort($result, SORT_STRING);
            }
            return ['type' => 'array', 'value' => $result];
        }
        return ['type' => get_debug_type($value), 'value' => is_float($value) ? DiffRuntime::floatText($value) : $value];
    }
}
