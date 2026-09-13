<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

use Random\IntervalBoundary;
use Random\Randomizer;

/** Random substitutions inside already-tokenized startup arguments or INI text. */
final class ArgumentInjection
{
    private const NUMBER = '[+-]?(?:\d+(?:\.\d*)?|\.\d+)';

    /** Validate recognized expressions and report whether the text uses any. */
    public static function validate(string $text): bool
    {
        return self::replace($text, null) !== $text;
    }

    public static function expand(string $text, Randomizer $random): string
    {
        return self::replace($text, $random);
    }

    private static function replace(string $text, ?Randomizer $random): string
    {
        // Leave ordinary braces and PHP's ${ENV_VAR} interpolation alone.
        return preg_replace_callback('/(?<!\$)\{([^{}\r\n]*)\}/', static function (array $match) use ($random): string {
            $body = $match[1];
            if (str_contains($body, ',')) {
                $choices = array_map(trim(...), explode(',', $body));
                if (in_array('', $choices, true)) {
                    throw new \InvalidArgumentException("empty choice in startup injection: {$match[0]}");
                }

                return $choices[$random?->getInt(0, count($choices) - 1) ?? 0];
            }

            $number = self::NUMBER . '(?:\s*[a-zA-Z]+)?';
            if (preg_match('/^\s*(' . $number . ')\s*-\s*(' . $number . ')\s*$/D', $body, $bounds) !== 1) {
                return $match[0];
            }

            if (preg_match('/[a-zA-Z]$/', $bounds[1]) === 1 || preg_match('/[a-zA-Z]$/', $bounds[2]) === 1) {
                $min = self::size($bounds[1]);
                $max = self::size($bounds[2]);
                if ($min > $max) {
                    throw new \InvalidArgumentException("reversed startup size range: {$match[0]}");
                }

                return (string) ($random?->getInt($min, $max) ?? $min);
            }

            $floating = str_contains($bounds[1], '.') || str_contains($bounds[2], '.');
            if ($floating) {
                $min = (float) $bounds[1];
                $max = (float) $bounds[2];
                if (!is_finite($min) || !is_finite($max) || $min > $max) {
                    throw new \InvalidArgumentException("invalid floating startup range: {$match[0]}");
                }
                $value = $min === $max || $random === null
                    ? $min
                    : $random->getFloat($min, $max, IntervalBoundary::ClosedClosed);

                // H is locale-independent; explicit precision avoids depending
                // on the harness interpreter's precision/serialize_precision INI.
                $formatted = sprintf('%.17H', $value);
                return str_contains($formatted, '.') || str_contains($formatted, 'E')
                    ? $formatted
                    : $formatted . '.0';
            }

            $min = self::integer($bounds[1]);
            $max = self::integer($bounds[2]);
            if ($min > $max) {
                throw new \InvalidArgumentException("reversed startup range: {$match[0]}");
            }

            return (string) ($random?->getInt($min, $max) ?? $min);
        }, $text) ?? throw new \RuntimeException('cannot expand startup injections');
    }

    private static function size(string $text): int
    {
        // Use PHP's own INI quantity parser for K/M/G and overflow checks.
        // Reject warned quantities instead of accepting its legacy fallback.
        set_error_handler(static function (int $severity, string $message) use ($text): never {
            throw new \InvalidArgumentException("invalid startup size bound {$text}: {$message}");
        }, E_WARNING);
        try {
            return ini_parse_quantity($text);
        } finally {
            restore_error_handler();
        }
    }

    private static function integer(string $text): int
    {
        $digits = ltrim(ltrim($text, '+-'), '0');
        $normalized = ($text[0] === '-' && $digits !== '' ? '-' : '') . ($digits === '' ? '0' : $digits);
        $value = filter_var($normalized, FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new \InvalidArgumentException("startup range bound is outside the PHP integer range: {$text}");
        }

        return $value;
    }
}
