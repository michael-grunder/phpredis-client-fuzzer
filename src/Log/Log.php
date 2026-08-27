<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Log;

/**
 * Tiny opt-in logger used by commands without imposing a logging dependency.
 */
final class Log
{
    /** @var (callable(string, string, array<string, mixed>): void)|null */
    private static $logger = null;

    /** @param (callable(string, string, array<string, mixed>): void)|null $logger */
    public static function setLogger(?callable $logger): void
    {
        self::$logger = $logger;
    }

    /** @param array<string, mixed> $context */
    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function debugFmt(string $format, mixed ...$values): void
    {
        self::debug(sprintf($format, ...self::scalarValues($values)));
    }

    public static function infoFmt(string $format, mixed ...$values): void
    {
        self::info(sprintf($format, ...self::scalarValues($values)));
    }

    public static function warningFmt(string $format, mixed ...$values): void
    {
        self::warning(sprintf($format, ...self::scalarValues($values)));
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<bool|float|int|string|null>
     */
    private static function scalarValues(array $values): array
    {
        return array_values(array_map(
            static fn (mixed $value): bool|float|int|string|null =>
                is_scalar($value) || $value === null ? $value : var_export($value, true),
            $values,
        ));
    }

    /** @param array<string, mixed> $context */
    private static function write(string $level, string $message, array $context): void
    {
        if (self::$logger !== null) {
            (self::$logger)($level, $message, $context);
        }
    }
}
