<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Values;

/** Execution-time permission: persisted fixtures are inert outside an opted-in call. */
final class CallbackContext
{
    /** @var (\Closure(string): void)|null */
    private static ?\Closure $mutation = null;
    private static bool $mutating = false;

    /** @param callable(): mixed $call
     *  @param (\Closure(string): void)|null $mutation
     */
    public static function invoke(callable $call, ?\Closure $mutation = null): mixed
    {
        $previous = self::$mutation;
        self::$mutation = self::$mutating ? null : $mutation;
        try {
            return $call();
        } finally {
            self::$mutation = $previous;
        }
    }

    public static function mutate(string $action): void
    {
        $mutation = self::$mutation;
        if ($mutation === null || self::$mutating) {
            return;
        }
        // At most one mutation per outer invocation, including recursive decoding.
        self::$mutation = null;
        self::$mutating = true;
        try {
            $mutation($action);
        } finally {
            self::$mutating = false;
        }
    }

    public static function isMutating(): bool
    {
        return self::$mutating;
    }

    /** @return array{string, list<mixed>} */
    public static function operation(string $action): array
    {
        return match ($action) {
            'close' => ['close', []],
            'serializer' => ['setOption', [\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE]],
            'delete' => ['del', ['fuzzer:{0}:deserialization-callback']],
            default => throw new \UnexpectedValueException('Unknown callback mutation'),
        };
    }

    /** @param callable(): mixed $call */
    public static function replay(object $client, callable $call): mixed
    {
        return self::invoke($call, static function (string $action) use ($client): void {
            [$method, $args] = self::operation($action);
            if (method_exists($client, $method)) {
                $client->{$method}(...$args);
            }
        });
    }
}
