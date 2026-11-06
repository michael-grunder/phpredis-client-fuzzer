<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\OptionChoices;
use Mgrunder\RedisClientTester\ClientFactory;
use Mgrunder\RedisClientTester\ClientType;
use Mgrunder\RedisClientTester\Commands\OptionValue;
use Mgrunder\RedisClientTester\RelayClusterOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OptionChoicesTest extends TestCase
{
    public function testRelayCodecsDoNotDependOnPhpRedisBuildOptions(): void
    {
        if (!class_exists(\Relay\Relay::class)) {
            self::markTestSkipped('Relay is optional');
        }
        foreach ([ClientType::Relay, ClientType::RelayCluster] as $type) {
            foreach (['serializer' => OptionChoices::SERIALIZER, 'compression' => OptionChoices::COMPRESSION] as $kind => $choices) {
                $supported = OptionChoices::available($choices, [$type]);
                self::assertContains('none', $supported);
                foreach ($supported as $name) {
                    $constants = OptionChoices::forClient($choices, $type->className());
                    $value = constant($constants[$name]);
                    self::assertIsInt($value);
                    self::assertSame($name, OptionChoices::resolve($name . ',' . $name, $choices, $kind, [$type]));
                    self::assertSame($value, (new \ReflectionMethod(ClientFactory::class, $kind))->invoke(
                        new ClientFactory(), $name, $type->className(),
                    ));
                    $option = $kind === 'serializer' ? \Redis::OPT_SERIALIZER : \Redis::OPT_COMPRESSION;
                    $code = (new OptionValue($option, $value))->code();
                    self::assertTrue(defined(ltrim($code, '\\')));
                    self::assertSame($value, constant(ltrim($code, '\\')));
                }
                $mixed = OptionChoices::available($choices, [ClientType::Redis, $type]);
                self::assertSame(array_values(array_intersect(OptionChoices::available($choices), $supported)), $mixed);
                mt_srand(42);
                $first = OptionChoices::resolve('random', $choices, $kind, [$type]);
                mt_srand(42);
                self::assertSame($first, OptionChoices::resolve('random', $choices, $kind, [$type]));
            }
        }
        if (defined('Relay\\Relay::COMPRESSION_LZF')) {
            self::assertContains('lzf', OptionChoices::available(OptionChoices::COMPRESSION, [ClientType::Relay]));
        }
        if (!extension_loaded('phpser')) {
            self::assertNotContains('phpser', OptionChoices::available(OptionChoices::SERIALIZER, [ClientType::Relay]));
        }
    }

    public function testUnsupportedCodecLoggingFallsBackToTheConcreteValue(): void
    {
        foreach ([\Redis::OPT_SERIALIZER, \Redis::OPT_COMPRESSION] as $option) {
            self::assertSame('99999', (new OptionValue($option, 99999))->code());
        }
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function choiceLists(): iterable
    {
        yield 'serializer' => [OptionChoices::SERIALIZER];
        yield 'compression' => [OptionChoices::COMPRESSION];
        yield 'slave failover' => [OptionChoices::SLAVE_FAILOVER];
        yield 'relay failover' => [OptionChoices::relayFailover()];
        yield 'relay distribute' => [OptionChoices::relayDistribute()];
        yield 'relay multikey reordering' => [OptionChoices::relayMultikeyReordering()];
    }

    /**
     * Relay is optional, so a list whose constants this build does not define
     * skips rather than failing.
     *
     * @param array<string, string> $choices
     * @return list<string>
     */
    private static function supported(array $choices): array
    {
        $available = OptionChoices::available($choices);
        if ($available === []) {
            self::markTestSkipped('No value in this list is supported by the loaded extensions');
        }

        return $available;
    }

    /** @param array<string, string> $choices */
    #[DataProvider('choiceLists')]
    public function testNamedValuesArePassedThroughUnchanged(array $choices): void
    {
        foreach (array_keys($choices) as $name) {
            self::assertFalse(OptionChoices::isRandom($name, $choices));
            self::assertSame($name, OptionChoices::resolve($name, $choices, 'setting'));
        }
    }

    /** @param array<string, string> $choices */
    #[DataProvider('choiceLists')]
    public function testAnyAlwaysAsksForARandomChoice(array $choices): void
    {
        self::assertTrue(OptionChoices::isRandom('any', $choices));
        self::assertTrue(OptionChoices::isRandom(' ANY ', $choices));
    }

    /** @param array<string, string> $choices */
    #[DataProvider('choiceLists')]
    public function testRandomOnlyResolvesToASupportedValue(array $choices): void
    {
        $available = self::supported($choices);

        for ($attempt = 0; $attempt < 25; $attempt++) {
            $chosen = OptionChoices::resolve('any', $choices, 'setting');
            self::assertContains($chosen, $available);
            self::assertTrue(defined($choices[$chosen]));
        }
    }

    /** @param array<string, string> $choices */
    #[DataProvider('choiceLists')]
    public function testTheSameSeedChoosesTheSameValue(array $choices): void
    {
        self::supported($choices);

        mt_srand(20260827);
        $first = OptionChoices::resolve('any', $choices, 'setting');

        mt_srand(20260827);
        $second = OptionChoices::resolve('any', $choices, 'setting');

        self::assertSame($first, $second);
    }

    public function testCsvChoosesOnlyFromTheRequestedSupportedSubset(): void
    {
        $requested = ['none', 'php', 'json'];
        $supported = array_values(array_intersect(
            $requested,
            OptionChoices::available(OptionChoices::SERIALIZER),
        ));
        if ($supported === []) {
            self::markTestSkipped('No requested serializer is supported by the loaded extension');
        }

        for ($attempt = 0; $attempt < 25; $attempt++) {
            self::assertContains(
                OptionChoices::resolve('none,php,json', OptionChoices::SERIALIZER, 'serializer'),
                $supported,
            );
        }
    }

    public function testSlaveFailoverRandomVariesWithSeedAndSupportsSubsets(): void
    {
        $seen = [];
        for ($seed = 0; $seed < 100; $seed++) {
            mt_srand($seed);
            $mode = OptionChoices::resolve('random', OptionChoices::SLAVE_FAILOVER, 'slave failover');
            $seen[$mode] = true;
            self::assertContains(
                OptionChoices::resolve('none,error', OptionChoices::SLAVE_FAILOVER, 'slave failover'),
                ['none', 'error'],
            );
        }

        self::assertEqualsCanonicalizing(array_keys(OptionChoices::SLAVE_FAILOVER), array_keys($seen));
    }

    public function testCsvChoiceIsNormalizedAndReproducible(): void
    {
        self::supported(OptionChoices::SERIALIZER);

        mt_srand(20260829);
        $first = OptionChoices::resolve(' NONE, Php,none ', OptionChoices::SERIALIZER, 'serializer');

        mt_srand(20260829);
        $second = OptionChoices::resolve('none,php', OptionChoices::SERIALIZER, 'serializer');

        self::assertSame($first, $second);
    }

    public function testCsvCanIncludeAChoiceLiterallyNamedRandom(): void
    {
        $choices = [
            'none' => 'PHP_VERSION_ID',
            'random' => 'PHP_INT_MAX',
        ];

        for ($attempt = 0; $attempt < 25; $attempt++) {
            self::assertContains(
                OptionChoices::resolve('none,random', $choices, 'distribution'),
                ['none', 'random'],
            );
        }
    }

    public function testCsvRejectsAnUnknownValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown serializer value "bogus" in subset');

        OptionChoices::resolve('none,bogus', OptionChoices::SERIALIZER, 'serializer');
    }

    public function testCsvRejectsAnEmptyValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Empty serializer value in subset');

        OptionChoices::resolve('none,,php', OptionChoices::SERIALIZER, 'serializer');
    }

    public function testCsvRejectsASubsetWithNoSupportedValues(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No serializer value is supported/');

        OptionChoices::resolve(
            'first,second',
            [
                'first' => 'Redis::NOT_A_REAL_CONSTANT',
                'second' => 'Redis::ALSO_NOT_A_REAL_CONSTANT',
            ],
            'serializer',
        );
    }

    public function testRandomIsTheSentinelWhenNoValueIsNamedRandom(): void
    {
        self::assertTrue(OptionChoices::isRandom('random', OptionChoices::SERIALIZER));
        self::assertTrue(OptionChoices::isRandom('RANDOM', OptionChoices::COMPRESSION));
        self::assertTrue(OptionChoices::isRandom('random', OptionChoices::relayFailover()));
        self::assertTrue(OptionChoices::isRandom('random', OptionChoices::SLAVE_FAILOVER));
        self::assertTrue(OptionChoices::isRandom('random', OptionChoices::relayMultikeyReordering()));

        self::assertContains(
            OptionChoices::resolve('random', OptionChoices::SERIALIZER, 'serializer'),
            OptionChoices::available(OptionChoices::SERIALIZER),
        );
    }

    public function testALiteralValueNamedRandomWinsOverTheSentinel(): void
    {
        $distribute = OptionChoices::relayDistribute();

        self::assertArrayHasKey('random', $distribute);
        self::assertFalse(OptionChoices::isRandom('random', $distribute));
        self::assertSame('random', OptionChoices::resolve('random', $distribute, 'distribute'));
        self::assertSame(
            'random',
            (new RelayClusterOptions(distribute: 'random'))->distribute,
        );
    }

    public function testAnUnknownValueIsLeftToTheSettingToReject(): void
    {
        self::assertSame('nonsense', OptionChoices::resolve('nonsense', OptionChoices::SERIALIZER, 'serializer'));
    }

    public function testAChoiceListWithNoSupportedValueIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No serializer value is supported/');

        OptionChoices::resolve('random', ['nope' => 'Redis::NOT_A_REAL_CONSTANT'], 'serializer');
    }

    /** @param array<string, string> $choices */
    #[DataProvider('choiceLists')]
    public function testEveryChoiceResolvesToAValueTheSettingAccepts(array $choices): void
    {
        foreach (self::supported($choices) as $name) {
            self::assertArrayHasKey($name, $choices);
        }
    }
}
