<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Harness\ArgumentInjection;
use Mgrunder\PhpredisCommandFuzzer\Harness\HarnessOptions;
use Mgrunder\PhpredisCommandFuzzer\Harness\PhpStartupTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class ArgumentInjectionTest extends TestCase
{
    public function testListsAndMultipleExpressionsPreserveArgumentBoundaries(): void
    {
        $options = HarnessOptions::parse(['--php-args=-d "include_path={a b,c d}/{01-01}"', '--', 'fuzz']);
        self::assertCount(2, $options->phpArgs);
        $random = new Randomizer(new Mt19937(7));
        $seen = [];
        for ($i = 0; $i < 100; $i++) {
            $value = ArgumentInjection::expand($options->phpArgs[1], $random);
            self::assertContains($value, ['include_path=a b/1', 'include_path=c d/1']);
            $seen[$value] = true;
        }
        self::assertCount(2, $seen);
    }

    public function testIntegerRangesAreInclusiveAndSupportNegativeBounds(): void
    {
        $random = new Randomizer(new Mt19937(7));
        $seen = [];
        for ($i = 0; $i < 100; $i++) {
            $value = ArgumentInjection::expand('{-2-+2}', $random);
            self::assertMatchesRegularExpression('/^-?[0-2]$/', $value);
            $seen[$value] = true;
        }
        self::assertCount(5, $seen);
        self::assertSame('-5', ArgumentInjection::expand('{-5--5}', $random));
        self::assertSame((string) PHP_INT_MIN, ArgumentInjection::expand('{' . PHP_INT_MIN . '-' . PHP_INT_MIN . '}', $random));
        self::assertSame((string) PHP_INT_MAX, ArgumentInjection::expand('{' . PHP_INT_MAX . '-' . PHP_INT_MAX . '}', $random));
    }

    public function testDecimalBoundsProduceFloatsIncludingMixedAndEqualBounds(): void
    {
        $random = new Randomizer(new Mt19937(7));
        $fractional = false;
        for ($i = 0; $i < 100; $i++) {
            $value = ArgumentInjection::expand('{-1.5-2}', $random);
            $decoded = json_decode($value);
            self::assertIsFloat($decoded);
            self::assertGreaterThanOrEqual(-1.5, $decoded);
            self::assertLessThanOrEqual(2.0, $decoded);
            $fractional = $fractional || floor($decoded) !== $decoded;
        }
        self::assertTrue($fractional);
        self::assertSame('100.0', ArgumentInjection::expand('{100-100.0}', $random));
        self::assertSame('0.5', ArgumentInjection::expand('{.5-.5}', $random));
    }

    public function testOrdinaryBracesAndEnvironmentInterpolationAreUnchanged(): void
    {
        $text = 'key={tag} path=${HOME}/lib empty={}';
        self::assertFalse(ArgumentInjection::validate($text));
        self::assertSame($text, ArgumentInjection::expand($text, new Randomizer(new Mt19937(7))));
        self::assertTrue(ArgumentInjection::validate('{a,b} {0-100}'));
    }

    public function testFloatFormattingDoesNotDependOnTheHarnessIniPrecision(): void
    {
        $previous = ini_set('serialize_precision', '2');
        self::assertIsString($previous);
        try {
            $value = ArgumentInjection::expand('{1.2345-1.2345}', new Randomizer(new Mt19937(7)));
            self::assertSame(1.2345, (float) $value);
        } finally {
            ini_set('serialize_precision', $previous);
        }
    }

    public function testSeededStartupChoicesAreIndependentOfGlobalRandomnessAndRenderOrder(): void
    {
        $template = new PhpStartupTemplate(['-d', 'precision={1-100}', '-d', 'include_path={a,b,c,d}']);
        mt_srand(123);
        $expected = mt_rand();
        mt_srand(123);
        $first = $template->render(7, '/unused');
        self::assertSame($expected, mt_rand());
        $different = $template->render(8, '/unused');
        self::assertNotSame($first, $different);
        self::assertSame($first, $template->render(7, '/unused'));
    }

    #[DataProvider('invalidExpressions')]
    public function testInvalidRecognizedExpressionsFailValidation(string $text): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ArgumentInjection::validate($text);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidExpressions(): iterable
    {
        yield 'reversed integers' => ['{10-0}'];
        yield 'reversed floats' => ['{1.5--2.0}'];
        yield 'empty choice' => ['{a,,b}'];
        yield 'blank choice' => ['{a, }'];
        yield 'integer overflow' => ['{0-999999999999999999999999999}'];
        yield 'float overflow' => ['{0.0-' . str_repeat('9', 400) . '.0}'];
    }
}
