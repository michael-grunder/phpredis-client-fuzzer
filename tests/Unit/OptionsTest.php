<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Cli\Options;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function byteSizes(): iterable
    {
        yield 'bytes' => ['100', 100];
        yield 'kilobytes' => ['100k', 100 * 1024];
        yield 'megabytes' => ['100M', 100 * 1024 ** 2];
        yield 'gigabytes' => ['2g', 2 * 1024 ** 3];
        yield 'terabytes' => ['1t', 1024 ** 4];
    }

    #[DataProvider('byteSizes')]
    public function testByteSizesUseOptionalBinarySuffixes(string $value, int $expected): void
    {
        $options = Options::parse(["--target={$value}"], ['target'], []);

        self::assertSame($expected, $options->optionalByteSize('target'));
    }

    public function testMissingByteSizeIsNull(): void
    {
        $options = Options::parse([], ['target'], []);

        self::assertNull($options->optionalByteSize('target'));
    }

    public function testInvalidByteSizeIsRejected(): void
    {
        $options = Options::parse(['--target=1.5m'], ['target'], []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a byte size');

        $options->optionalByteSize('target');
    }

    public function testOverflowingByteSizeIsRejected(): void
    {
        $options = Options::parse(['--target=' . PHP_INT_MAX . 't'], ['target'], []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('too large');

        $options->optionalByteSize('target');
    }
}
