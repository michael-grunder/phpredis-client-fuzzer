<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Values;

/** Fixtures stored in Redis are inert unless the current request explicitly arms them. */
final class DeserializationFailure extends SerializedValue
{
    public const MODES = ['exception', 'error', 'type-error', 'value-error', 'parse-error',
        'fatal', 'compile-fatal', 'user-fatal', 'oom', 'timeout', 'exit'];
    private static ?string $mode = null;
    /** @var (\Closure(): mixed)|null */
    private static ?\Closure $read = null;

    /** @param (\Closure(): mixed)|null $read */
    public static function arm(?string $mode, ?\Closure $read = null): void
    {
        if ($mode !== null && !in_array($mode, [...self::MODES, 'recursion'], true)) {
            throw new \InvalidArgumentException('Unknown deserialization failure mode');
        }
        self::$mode = $mode;
        self::$read = $read;
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        parent::__unserialize($data);
        self::fire();
    }

    public static function fire(): void
    {
        switch (self::$mode) {
            case null:
                return;
            case 'exception':
                throw new \RuntimeException('Tester deserialization exception');
            case 'error':
                throw new \Error('Tester deserialization error');
            case 'type-error':
                eval('strlen([]);');
                return;
            case 'value-error':
                eval("str_repeat('x', -1);");
                return;
            case 'parse-error':
                eval('return );');
                return;
            case 'fatal':
                // A redeclaration is an engine fatal even inside try/catch and custom error handlers.
                eval('function tester_deserialization_fatal() {} function tester_deserialization_fatal() {}');
                return;
            case 'compile-fatal':
                eval('class TesterDeserializationFatal { abstract public function fail(); }');
                return;
            case 'user-fatal':
                eval("trigger_error('Tester deserialization user fatal', E_USER_ERROR);");
                return;
            case 'oom':
                // Exhaust PHP's allocator with one bounded allocation, never the host's memory.
                $previous = ini_set('memory_limit', (string) (memory_get_usage(true) + 8 * 1024 * 1024));
                if ($previous === false) {
                    throw new \RuntimeException('Cannot set memory_limit for deserialization OOM');
                }
                try {
                    $allocation = str_repeat('x', 16 * 1024 * 1024);
                } finally {
                    ini_set('memory_limit', $previous);
                }
                throw new \RuntimeException('Deserialization OOM survived allocating ' . strlen($allocation) . ' bytes');
            case 'timeout':
                if (!set_time_limit(1)) {
                    throw new \RuntimeException('Cannot set deserialization execution time limit');
                }
                for (;;) {
                    hash('sha256', 'deserialization timeout');
                }
            case 'exit':
                exit(86);
            case 'recursion':
                if (self::$read === null) {
                    throw new \LogicException('Recursive deserialization requires a read callback');
                }
                (self::$read)();
        }
    }
}
