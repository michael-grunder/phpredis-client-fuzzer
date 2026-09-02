<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Harness\HarnessOptions;
use PHPUnit\Framework\TestCase;

final class HarnessOptionsTest extends TestCase
{
    public function testSplitsHarnessOptionsFromTheFuzzerCommand(): void
    {
        $options = HarnessOptions::parse([
            '--jobs', '4',
            '--port', '7000', '7001', '7002',
            '--rr-chaos',
            '--',
            'bin/phpredis-fuzz-coercive', '--steps', '{steps}', '--port={port}',
        ]);

        self::assertSame(4, $options->jobs);
        self::assertSame([7000, 7001, 7002], $options->ports);
        self::assertTrue($options->rr, 'rr-chaos implies rr');
        self::assertTrue($options->rrChaos);
        self::assertSame(
            ['bin/phpredis-fuzz-coercive', '--steps', '{steps}', '--port={port}'],
            $options->command,
        );
    }

    public function testPortAcceptsCommaSeparatedAndDeduplicates(): void
    {
        $options = HarnessOptions::parse(['--port=7000,7001,7000', '--', 'x']);

        self::assertSame([7000, 7001], $options->ports);
    }

    public function testDefaultsAreApplied(): void
    {
        $options = HarnessOptions::parse(['--', 'bin/phpredis-fuzz']);

        self::assertSame(1, $options->jobs);
        self::assertSame(5000, $options->steps);
        self::assertNull($options->seed);
        self::assertNull($options->reduce);
        self::assertSame('cycle', $options->portSelect);
        self::assertFalse($options->rr);
        self::assertSame(['crashes'], $options->capture);
        self::assertTrue($options->capturesCrashes());
        self::assertFalse($options->capturesLeaks());
        self::assertFalse($options->capturesTimeouts());
        self::assertFalse($options->capturesFailures());
        self::assertSame([], $options->ports);
    }

    public function testCaptureParsesACommaListAndNormalisesOrder(): void
    {
        $options = HarnessOptions::parse(['--capture', 'failures,crashes,timeouts,leaks,crashes', '--', 'x']);

        self::assertSame(['crashes', 'leaks', 'timeouts', 'failures'], $options->capture);
        self::assertTrue($options->capturesCrashes());
        self::assertTrue($options->capturesLeaks());
        self::assertTrue($options->capturesTimeouts());
        self::assertTrue($options->capturesFailures());
    }

    public function testCaptureTimeoutsIsIndependentOfFailures(): void
    {
        $options = HarnessOptions::parse(['--capture', 'timeouts', '--', 'x']);

        self::assertSame(['timeouts'], $options->capture);
        self::assertTrue($options->capturesTimeouts());
        self::assertFalse($options->capturesFailures());
        self::assertFalse($options->capturesCrashes());
    }

    public function testRunTimeoutDefaultsToOffAndParsesSeconds(): void
    {
        self::assertSame(0.0, HarnessOptions::parse(['--', 'x'])->runTimeout);
        self::assertSame(90.0, HarnessOptions::parse(['--run-timeout', '90', '--', 'x'])->runTimeout);
    }

    public function testRunTimeoutRejectsANegativeValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HarnessOptions::parse(['--run-timeout', '-1', '--', 'x']);
    }

    public function testCaptureRejectsAnUnknownKind(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HarnessOptions::parse(['--capture', 'crashes,segfaults', '--', 'x']);
    }

    public function testCaptureRejectsAnEmptyList(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HarnessOptions::parse(['--capture', ',', '--', 'x']);
    }

    public function testUnknownOptionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HarnessOptions::parse(['--wat', '--', 'x']);
    }

    public function testMissingSeparatorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HarnessOptions::parse(['--jobs', '2']);
    }

    public function testMissingSeparatorIsAllowedForHelp(): void
    {
        $options = HarnessOptions::parse(['--help']);

        self::assertTrue($options->help);
    }

    public function testReduceOnlyAcceptsSteps(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HarnessOptions::parse(['--reduce', 'keys', '--', 'x']);
    }

    public function testFlagRejectsInlineValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HarnessOptions::parse(['--rr=1', '--', 'x']);
    }

    public function testMultiValueOptionRequiresAtLeastOneValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HarnessOptions::parse(['--port', '--rr', '--', 'x']);
    }

    public function testPortRangeIsValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HarnessOptions::parse(['--port', '70000', '--', 'x']);
    }
}
