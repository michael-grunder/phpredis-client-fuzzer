<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\SaturationTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SaturationTargetTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function byteTargets(): iterable
    {
        yield 'bytes' => ['100', 100];
        yield 'kilobytes' => ['100k', 100 * 1024];
        yield 'megabytes' => ['100M', 100 * 1024 ** 2];
        yield 'gigabytes' => ['4g', 4 * 1024 ** 3];
    }

    #[DataProvider('byteTargets')]
    public function testByteTargetsResolveWithoutReadingRelayStats(
        string $value,
        int $expected,
    ): void {
        $target = SaturationTarget::parse($value);

        self::assertFalse($target->isPercentage());
        self::assertSame(
            $expected,
            $target->resolve(static function (): array {
                throw new \LogicException('Byte targets must not read Relay stats');
            }),
        );
    }

    /** @return iterable<string, array{string, int, int}> */
    public static function percentageTargets(): iterable
    {
        yield 'integer' => ['10%', 4096, 410];
        yield 'one decimal place' => ['95.2%', 10_000, 9520];
        yield 'two decimal places' => ['32.67%', 12_345, 4033];
        yield 'full cache' => ['100%', 8192, 8192];
    }

    #[DataProvider('percentageTargets')]
    public function testPercentagesResolveAgainstRelayMemoryTotal(
        string $value,
        int $total,
        int $expected,
    ): void {
        $target = SaturationTarget::parse($value);

        self::assertTrue($target->isPercentage());
        self::assertSame($expected, $target->resolve(
            static fn (): array => ['memory' => ['total' => $total]],
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPercentages(): iterable
    {
        yield 'zero' => ['0%'];
        yield 'negative' => ['-1%'];
        yield 'over one hundred' => ['100.01%'];
        yield 'missing integer part' => ['.5%'];
        yield 'missing fraction' => ['10.%'];
        yield 'double percent' => ['10%%'];
        yield 'exponent' => ['1e2%'];
    }

    #[DataProvider('invalidPercentages')]
    public function testInvalidPercentagesAreRejected(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SaturationTarget::parse($value);
    }

    public function testPercentageRequiresPositiveIntegerRelayMemoryTotal(): void
    {
        $target = SaturationTarget::parse('50%');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Relay stats memory.total must be a positive integer');

        $target->resolve(static fn (): array => ['memory' => ['total' => null]]);
    }
}
