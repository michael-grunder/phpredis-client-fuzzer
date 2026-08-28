<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

use Relay\Cluster;

/**
 * Named settings that accept one of a fixed list of values, plus the sentinel
 * that asks the run to pick one of them.
 *
 * Selection uses the process-global `mt_rand()` sequence, so a caller that
 * seeds with `mt_srand()` before resolving gets the same setting for the same
 * seed. Choices whose extension constant the loaded build does not define are
 * never picked, so `random` cannot select a serializer or compressor that is
 * not compiled in.
 */
final class OptionChoices
{
    /**
     * Always means "pick one of the valid settings", even for a setting that
     * has a value literally named `random`.
     */
    public const ANY = 'any';

    /**
     * Means the same as {@see self::ANY} unless the setting itself has a value
     * named `random`, in which case that literal value wins. `Relay\Cluster`
     * distribution is the only such setting today: `--relay-distribute=random`
     * still selects `DISTRIBUTE_RANDOM`, and `any` picks a mode at random.
     */
    public const RANDOM = 'random';

    /** @var array<string, string> Serializer name to PhpRedis constant. */
    public const SERIALIZER = [
        'none' => 'Redis::SERIALIZER_NONE',
        'php' => 'Redis::SERIALIZER_PHP',
        'igbinary' => 'Redis::SERIALIZER_IGBINARY',
        'msgpack' => 'Redis::SERIALIZER_MSGPACK',
        'json' => 'Redis::SERIALIZER_JSON',
    ];

    /** @var array<string, string> Compression name to PhpRedis constant. */
    public const COMPRESSION = [
        'none' => 'Redis::COMPRESSION_NONE',
        'lzf' => 'Redis::COMPRESSION_LZF',
        'zstd' => 'Redis::COMPRESSION_ZSTD',
        'lz4' => 'Redis::COMPRESSION_LZ4',
    ];

    /** @return array<string, string> */
    public static function relayFailover(): array
    {
        return self::qualify(RelayClusterOptions::FAILOVER);
    }

    /** @return array<string, string> */
    public static function relayDistribute(): array
    {
        return self::qualify(RelayClusterOptions::DISTRIBUTE);
    }

    /** @return array<string, string> */
    public static function relayMultikeyReordering(): array
    {
        return self::qualify(RelayClusterOptions::MULTIKEY_REORDERING);
    }

    /**
     * True when `$value` asks for a random setting rather than naming one.
     *
     * @param array<string, string> $choices Setting name to constant.
     */
    public static function isRandom(string $value, array $choices): bool
    {
        $name = self::normalize($value);

        return $name === self::ANY
            || ($name === self::RANDOM && !isset($choices[self::RANDOM]));
    }

    /**
     * Returns `$value` unchanged unless it asks for a random setting, in which
     * case one of the available names is chosen with `mt_rand()`. Names that
     * are not the sentinel are left alone so the setting's own validation
     * still reports unknown values.
     *
     * @param array<string, string> $choices Setting name to constant.
     * @param string $kind Human-readable setting name used in errors.
     */
    public static function resolve(string $value, array $choices, string $kind): string
    {
        if (!self::isRandom($value, $choices)) {
            return $value;
        }

        $available = self::available($choices);
        if ($available === []) {
            throw new \RuntimeException(
                "No {$kind} value is supported by the loaded extension, so one cannot be chosen at random",
            );
        }

        return $available[mt_rand(0, count($available) - 1)];
    }

    /**
     * The subset of `$choices` whose constant the loaded extension defines.
     *
     * @param array<string, string> $choices Setting name to constant.
     * @return list<string>
     */
    public static function available(array $choices): array
    {
        $names = [];
        foreach ($choices as $name => $constant) {
            if (defined($constant)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param array<string, string> $modes Mode name to `Relay\Cluster` constant name.
     * @return array<string, string>
     */
    private static function qualify(array $modes): array
    {
        $qualified = [];
        foreach ($modes as $name => $constant) {
            $qualified[$name] = Cluster::class . '::' . $constant;
        }

        return $qualified;
    }

    private static function normalize(string $value): string
    {
        return strtr(strtolower(trim($value)), '-', '_');
    }
}
