<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester;

use Relay\Cluster;
use Relay\Relay;

/**
 * Named settings that accept one of a fixed list of values, plus the sentinel
 * that asks the run to pick one of them. A comma-separated list restricts the
 * random choice to that subset.
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
        'phpser' => 'Redis::SERIALIZER_PHPSER',
    ];

    /** @var array<string, string> Compression name to PhpRedis constant. */
    public const COMPRESSION = [
        'none' => 'Redis::COMPRESSION_NONE',
        'lzf' => 'Redis::COMPRESSION_LZF',
        'zstd' => 'Redis::COMPRESSION_ZSTD',
        'lz4' => 'Redis::COMPRESSION_LZ4',
    ];

    /** @var array<string, string> Legacy cluster failover mode to PhpRedis constant. */
    public const SLAVE_FAILOVER = [
        'none' => 'RedisCluster::FAILOVER_NONE',
        'error' => 'RedisCluster::FAILOVER_ERROR',
        'distribute' => 'RedisCluster::FAILOVER_DISTRIBUTE',
        'distribute_slaves' => 'RedisCluster::FAILOVER_DISTRIBUTE_SLAVES',
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
     * Returns `$value` unchanged unless it asks for a random setting or names
     * a comma-separated subset, in which case one of the available names is
     * chosen with `mt_rand()`. A single name that is not the sentinel is left
     * alone so the setting's own validation still reports unknown values.
     *
     * @param array<string, string> $choices Setting name to constant.
     * @param string $kind Human-readable setting name used in errors.
     * @param list<ClientType> $clientTypes Restrict automatic codec choices to support shared by these clients.
     */
    public static function resolve(string $value, array $choices, string $kind, array $clientTypes = []): string
    {
        if (!str_contains($value, ',')) {
            if (!self::isRandom($value, $choices)) {
                return $value;
            }

            return self::choose(self::available($choices, $clientTypes), $kind);
        }

        $subset = [];
        foreach (explode(',', $value) as $item) {
            $name = self::normalize($item);
            if ($name === '') {
                throw new \InvalidArgumentException("Empty {$kind} value in subset");
            }
            if (!array_key_exists($name, $choices)) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown %s value "%s" in subset; expected one of %s',
                    $kind,
                    trim($item),
                    implode(', ', array_keys($choices)),
                ));
            }

            $subset[$name] = $choices[$name];
        }

        return self::choose(self::available($subset, $clientTypes), $kind);
    }

    /**
     * @param list<string> $available
     */
    private static function choose(array $available, string $kind): string
    {
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
     * @param list<ClientType> $clientTypes
     * @return list<string>
     */
    public static function available(array $choices, array $clientTypes = []): array
    {
        if ($clientTypes !== []) {
            $names = array_keys($choices);
            foreach ($clientTypes as $type) {
                $names = array_values(array_intersect(
                    $names,
                    self::available(self::forClient($choices, $type->className())),
                ));
            }
            return $names;
        }

        $names = [];
        foreach ($choices as $name => $constant) {
            if (defined($constant) && self::codecAvailable($constant)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Relay's codecs do not depend on how PhpRedis was compiled.
     * @param array<string, string> $choices
     * @return array<string, string>
     */
    public static function forClient(array $choices, string $class): array
    {
        if (is_a($class, Relay::class, true) || is_a($class, Cluster::class, true)) {
            foreach ($choices as &$constant) {
                $constant = str_replace('Redis::', Relay::class . '::', $constant);
            }
        }
        return $choices;
    }

    private static function codecAvailable(string $constant): bool
    {
        if (!str_starts_with($constant, Relay::class . '::SERIALIZER_')
            && !str_starts_with($constant, Relay::class . '::COMPRESSION_')) {
            return true;
        }

        // Relay declares optional codecs even when their runtime libraries are absent.
        $option = str_starts_with($constant, Relay::class . '::SERIALIZER_')
            ? Relay::OPT_SERIALIZER : Relay::OPT_COMPRESSION;
        $value = constant($constant);
        if (!is_int($value)) {
            return false;
        }
        try {
            return (new Relay())->setOption($option, $value);
        } catch (\Relay\Exception) {
            return false;
        }
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
