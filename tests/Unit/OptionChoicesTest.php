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
