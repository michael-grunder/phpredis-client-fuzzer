<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester;

use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

/**
 * The final dispatch boundary, shared by commands, scenarios and oracle reads.
 * Hooks and oracle evaluation belong to the caller: auxiliary invocations can
 * use this boundary without recursively scheduling another differential check.
 * Argument arrays are never rebuilt, so reference arguments reach the client.
 *
 * @internal
 */
final class InvocationRecorder
{
    /**
     * @param list<mixed> $arguments
     * @param array<mixed>|null $scriptArguments
     */
    public static function invoke(
        ClientInvoker $invoker,
        Redis|RedisCluster|Relay|Cluster $client,
        string $method,
        array $arguments,
        string $phase = 'scheduled-call',
        string $context = '',
        ?array $scriptArguments = null,
        bool $iterable = false,
    ): mixed {
        $record = ScriptLogger::beginInvocation($client, $method, $phase, $context);
        if ($iterable) {
            ScriptLogger::logIterable($client, $method, $scriptArguments ?? $arguments, catchExceptions: true);
        } else {
            ScriptLogger::log($client, $method, $scriptArguments ?? $arguments, catchExceptions: true);
        }
        try {
            $reply = $invoker->invoke($client, $method, $arguments);
        } catch (\Throwable $throwable) {
            ScriptLogger::endInvocation($record, 'threw ' . $throwable::class);
            throw $throwable;
        }
        ScriptLogger::endInvocation($record, 'returned');

        return $reply;
    }

    public static function sleep(int $microseconds, string $phase, string $context = ''): void
    {
        ScriptLogger::delay($microseconds, $phase, $context);
        usleep($microseconds);
    }
}
