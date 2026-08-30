<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\OptionChoices;
use Mgrunder\PhpredisCommandFuzzer\RelayClusterOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OptionChoicesTest extends TestCase
{
    /** @return iterable<string, array{array<string, string>}> */
    public static function choiceLists(): iterable
    {
        yield 'serializer' => [OptionChoices::SERIALIZER];
        yield 'compression' => [OptionChoices::COMPRESSION];
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
        foreach (OptionChoices::available($choices) as $name) {
            self::assertArrayHasKey($name, $choices);
        }
    }
}
