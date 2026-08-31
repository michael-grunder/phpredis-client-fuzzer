<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command\setoption;
use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;
use Mgrunder\PhpredisCommandFuzzer\Commands\OptionName;
use Mgrunder\PhpredisCommandFuzzer\Commands\OptionValue;
use PHPUnit\Framework\TestCase;

final class OptionCommandTest extends TestCase
{
    /** @var list<string> */
    private const RELAY_CLUSTER_OPTIONS = [
        'Relay\\Cluster::OPT_DISTRIBUTE',
        'Relay\\Cluster::OPT_FAILOVER',
        'Relay\\Cluster::OPT_NODE_READ_TIMEOUT',
        'Relay\\Cluster::OPT_MULTIKEY_REORDERING',
        'Relay\\Cluster::OPT_AVAILABILITY_ZONE',
    ];

    /** @var list<string> */
    private const DISTRIBUTE_VALUES = [
        'Relay\\Cluster::DISTRIBUTE_NONE',
        'Relay\\Cluster::DISTRIBUTE_RANDOM',
        'Relay\\Cluster::DISTRIBUTE_RANDOM_REPLICA',
        'Relay\\Cluster::DISTRIBUTE_REPLICAS',
        'Relay\\Cluster::DISTRIBUTE_ALL',
    ];

    /** @var list<string> */
    private const FAILOVER_VALUES = [
        'Relay\\Cluster::FAILOVER_NONE',
        'Relay\\Cluster::FAILOVER_PRIMARY',
        'Relay\\Cluster::FAILOVER_RANDOM_REPLICA',
        'Relay\\Cluster::FAILOVER_REPLICAS',
        'Relay\\Cluster::FAILOVER_ALL',
    ];

    /** @var list<string> */
    private const MULTIKEY_REORDERING_VALUES = [
        'Relay\\Cluster::MULTIKEY_REORDER_NONE',
        'Relay\\Cluster::MULTIKEY_REORDER_READS',
        'Relay\\Cluster::MULTIKEY_REORDER_WRITES',
        'Relay\\Cluster::MULTIKEY_REORDER_ALL',
    ];

    public function testSetOptionFuzzesRelayClusterRuntimeOptions(): void
    {
        $this->requireRelayClusterOptions();

        $client = new class extends \Relay\Cluster {
            /** @var list<array{int, mixed}> */
            public array $calls = [];

            public function __construct()
            {
            }

            public function setOption(int $option, mixed $value): bool
            {
                $this->calls[] = [$option, $value];

                return true;
            }

            public function getOption(int $option): mixed
            {
                return $option === \Redis::OPT_SERIALIZER ? \Redis::SERIALIZER_NONE : null;
            }

            public function getLastError(): ?string
            {
                return null;
            }

            /** @return list<array{0: string, 1: int}> */
            public function _masters(): array
            {
                return [];
            }
        };
        $command = new setoption();
        $config = new FuzzConfig();
        mt_srand(7429);

        for ($i = 0; $i < 2000; $i++) {
            $command->fuzz($client, $config);
        }

        $callsByOption = [];
        foreach ($client->calls as [$option, $value]) {
            $callsByOption[$option][] = $value;
        }

        foreach (self::RELAY_CLUSTER_OPTIONS as $option) {
            $id = $this->intConstant($option);
            self::assertArrayHasKey($id, $callsByOption, "No calls used {$option}");
        }

        $distribute = $this->intConstant('Relay\\Cluster::OPT_DISTRIBUTE');
        $failover = $this->intConstant('Relay\\Cluster::OPT_FAILOVER');
        $nodeReadTimeout = $this->intConstant('Relay\\Cluster::OPT_NODE_READ_TIMEOUT');
        $multikeyReordering = $this->intConstant('Relay\\Cluster::OPT_MULTIKEY_REORDERING');
        $availabilityZone = $this->intConstant('Relay\\Cluster::OPT_AVAILABILITY_ZONE');

        self::assertContains(
            true,
            array_map(
                fn (mixed $value): bool => in_array(
                    $value,
                    $this->intConstants(self::DISTRIBUTE_VALUES),
                    true,
                ),
                $callsByOption[$distribute],
            ),
        );
        self::assertContains(
            true,
            array_map(
                fn (mixed $value): bool => in_array(
                    $value,
                    $this->intConstants(self::FAILOVER_VALUES),
                    true,
                ),
                $callsByOption[$failover],
            ),
        );
        self::assertContains(
            true,
            array_map(
                static fn (mixed $value): bool => is_float($value) && $value >= 0.0 && $value <= 1.5,
                $callsByOption[$nodeReadTimeout],
            ),
        );
        self::assertContains(
            true,
            array_map(
                fn (mixed $value): bool => in_array(
                    $value,
                    $this->intConstants(self::MULTIKEY_REORDERING_VALUES),
                    true,
                ),
                $callsByOption[$multikeyReordering],
            ),
        );
        self::assertContains(
            true,
            array_map(
                static fn (mixed $value): bool => is_string($value),
                $callsByOption[$availabilityZone],
            ),
        );
    }

    public function testRelayClusterOptionsHaveReproductionScriptNames(): void
    {
        $this->requireRelayClusterOptions();

        self::assertSame(
            '\\Relay\\Cluster::OPT_DISTRIBUTE',
            (new OptionName($this->intConstant('Relay\\Cluster::OPT_DISTRIBUTE')))->code(),
        );
        self::assertSame(
            '\\Relay\\Cluster::DISTRIBUTE_RANDOM_REPLICA',
            (new OptionValue(
                $this->intConstant('Relay\\Cluster::OPT_DISTRIBUTE'),
                $this->intConstant('Relay\\Cluster::DISTRIBUTE_RANDOM_REPLICA'),
            ))->code(),
        );
        self::assertSame(
            '\\Relay\\Cluster::FAILOVER_ALL',
            (new OptionValue(
                $this->intConstant('Relay\\Cluster::OPT_FAILOVER'),
                $this->intConstant('Relay\\Cluster::FAILOVER_ALL'),
            ))->code(),
        );
        self::assertSame(
            '\\Relay\\Cluster::MULTIKEY_REORDER_WRITES',
            (new OptionValue(
                $this->intConstant('Relay\\Cluster::OPT_MULTIKEY_REORDERING'),
                $this->intConstant('Relay\\Cluster::MULTIKEY_REORDER_WRITES'),
            ))->code(),
        );
    }

    private function requireRelayClusterOptions(): void
    {
        $constants = array_merge(
            self::RELAY_CLUSTER_OPTIONS,
            self::DISTRIBUTE_VALUES,
            self::FAILOVER_VALUES,
            self::MULTIKEY_REORDERING_VALUES,
        );
        foreach ($constants as $constant) {
            if (!defined($constant)) {
                self::markTestSkipped("{$constant} is not available");
            }
        }
    }

    private function intConstant(string $name): int
    {
        $value = constant($name);
        if (!is_int($value)) {
            throw new \UnexpectedValueException("{$name} is not an integer");
        }

        return $value;
    }

    /**
     * @param list<string> $names
     * @return list<int>
     */
    private function intConstants(array $names): array
    {
        return array_map($this->intConstant(...), $names);
    }
}
