<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

use Relay\Cluster;

/**
 * Relay-only `Relay\Cluster` tuning options.
 *
 * Modes are stored as lowercase names so a configuration can be constructed and
 * validated without the Relay extension being loaded. The matching
 * `Relay\Cluster` constants are resolved in {@see self::applyTo()}, which fails
 * loudly when the loaded Relay build does not define a constant or refuses the
 * value, rather than leaving the caller believing an option took effect.
 */
final readonly class RelayClusterOptions
{
    /**
     * Retry strategy for `Cluster::OPT_FAILOVER`.
     *
     * @var array<string, string>
     */
    public const FAILOVER = [
        'none' => 'FAILOVER_NONE',
        'primary' => 'FAILOVER_PRIMARY',
        'random_replica' => 'FAILOVER_RANDOM_REPLICA',
        'replicas' => 'FAILOVER_REPLICAS',
        'all' => 'FAILOVER_ALL',
    ];

    /**
     * Readonly command distribution for `Cluster::OPT_DISTRIBUTE`.
     *
     * @var array<string, string>
     */
    public const DISTRIBUTE = [
        'none' => 'DISTRIBUTE_NONE',
        'random' => 'DISTRIBUTE_RANDOM',
        'random_replica' => 'DISTRIBUTE_RANDOM_REPLICA',
        'replicas' => 'DISTRIBUTE_REPLICAS',
        'all' => 'DISTRIBUTE_ALL',
    ];

    /**
     * Multi-key slot grouping for `Cluster::OPT_MULTIKEY_REORDERING`.
     *
     * @var array<string, string>
     */
    public const MULTIKEY_REORDERING = [
        'none' => 'MULTIKEY_REORDER_NONE',
        'reads' => 'MULTIKEY_REORDER_READS',
        'writes' => 'MULTIKEY_REORDER_WRITES',
        'all' => 'MULTIKEY_REORDER_ALL',
    ];

    /** Option constants read back for reporting, in application order. */
    public const REPORTED = [
        'OPT_FAILOVER',
        'OPT_DISTRIBUTE',
        'OPT_NODE_READ_TIMEOUT',
        'OPT_MULTIKEY_REORDERING',
    ];

    public ?string $failover;

    public ?string $distribute;

    public ?string $multikeyReordering;

    /**
     * Every argument is optional; a `null` mode leaves the Relay default in
     * place instead of setting the option.
     *
     * @param ?string $failover One of {@see self::FAILOVER}.
     * @param ?string $distribute One of {@see self::DISTRIBUTE}.
     * @param ?float $nodeReadTimeout Per-node read timeout in seconds; `0.0` disables the override.
     * @param ?string $multikeyReordering One of {@see self::MULTIKEY_REORDERING}.
     */
    public function __construct(
        ?string $failover = null,
        ?string $distribute = null,
        public ?float $nodeReadTimeout = null,
        ?string $multikeyReordering = null,
    ) {
        $this->failover = $this->mode($failover, self::FAILOVER, 'failover');
        $this->distribute = $this->mode($distribute, self::DISTRIBUTE, 'distribute');
        $this->multikeyReordering = $this->mode(
            $multikeyReordering,
            self::MULTIKEY_REORDERING,
            'multikey reordering',
        );

        if ($nodeReadTimeout !== null && $nodeReadTimeout < 0.0) {
            throw new \InvalidArgumentException('Node read timeout cannot be negative');
        }
    }

    /** True when nothing would be sent to `setOption()`. */
    public function isEmpty(): bool
    {
        return $this->failover === null
            && $this->distribute === null
            && $this->nodeReadTimeout === null
            && $this->multikeyReordering === null;
    }

    /**
     * Sets every configured option, verifying each `setOption()` return value.
     *
     * @return array<string, int|float> Applied `Relay\Cluster` constant name to value.
     */
    public function applyTo(Cluster $client): array
    {
        $applied = [];
        foreach ($this->assignments() as [$option, $value]) {
            if ($client->setOption($this->constant($option), $value) !== true) {
                throw new \RuntimeException(sprintf(
                    'Relay\Cluster::setOption(%s, %s) returned false; the loaded Relay (%s) rejected it',
                    $option,
                    var_export($value, true),
                    phpversion('relay') === false ? 'unknown' : phpversion('relay'),
                ));
            }
            $applied[$option] = $value;
        }

        return $applied;
    }

    /**
     * Reads the current values back from a client for diagnostics. Constants the
     * loaded Relay does not define are omitted.
     *
     * @return array<string, mixed>
     */
    public static function describe(Cluster $client): array
    {
        $described = [];
        foreach (self::REPORTED as $option) {
            $name = Cluster::class . '::' . $option;
            if (defined($name)) {
                /** @var int $id */
                $id = constant($name);
                $described[strtolower($option)] = $client->getOption($id);
            }
        }

        return $described;
    }

    /**
     * @return list<array{string, int|float}>
     */
    private function assignments(): array
    {
        $assignments = [];
        if ($this->failover !== null) {
            $assignments[] = ['OPT_FAILOVER', $this->constant(self::FAILOVER[$this->failover])];
        }
        if ($this->distribute !== null) {
            $assignments[] = ['OPT_DISTRIBUTE', $this->constant(self::DISTRIBUTE[$this->distribute])];
        }
        if ($this->nodeReadTimeout !== null) {
            $assignments[] = ['OPT_NODE_READ_TIMEOUT', $this->nodeReadTimeout];
        }
        if ($this->multikeyReordering !== null) {
            $assignments[] = [
                'OPT_MULTIKEY_REORDERING',
                $this->constant(self::MULTIKEY_REORDERING[$this->multikeyReordering]),
            ];
        }

        return $assignments;
    }

    /**
     * @param array<string, string> $modes
     */
    private function mode(?string $value, array $modes, string $kind): ?string
    {
        if ($value === null) {
            return null;
        }

        $name = strtr(strtolower(trim($value)), '-', '_');
        if (!isset($modes[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown %s mode "%s"; expected one of %s',
                $kind,
                $value,
                implode(', ', array_keys($modes)),
            ));
        }

        return $name;
    }

    private function constant(string $option): int
    {
        $name = Cluster::class . '::' . $option;
        if (!defined($name)) {
            throw new \RuntimeException("{$name} is not supported by the loaded Relay extension");
        }

        $value = constant($name);
        if (!is_int($value)) {
            throw new \UnexpectedValueException("{$name} is not an integer");
        }

        return $value;
    }
}
