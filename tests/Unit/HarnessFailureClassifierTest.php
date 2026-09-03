<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Cli\ExitCode;
use Mgrunder\PhpredisCommandFuzzer\Harness\FailureClassifier;
use PHPUnit\Framework\TestCase;

final class HarnessFailureClassifierTest extends TestCase
{
    public function testCleanExitIsNotAFailure(): void
    {
        $verdict = FailureClassifier::fromExit(false, null, 0);

        self::assertFalse($verdict->failed);
        self::assertFalse($verdict->crashed);
    }

    public function testSignalledDeathIsACrash(): void
    {
        $verdict = FailureClassifier::fromExit(true, 11, -1);

        self::assertTrue($verdict->crashed);
        self::assertSame(11, $verdict->signal);
        self::assertSame('SIGSEGV', $verdict->signalName());
    }

    public function testShellStyle128PlusSignalIsACrash(): void
    {
        $verdict = FailureClassifier::fromExit(false, null, 134);

        self::assertTrue($verdict->crashed);
        self::assertSame(6, $verdict->signal);
    }

    public function testNonZeroExitIsAFailureButNotACrash(): void
    {
        $verdict = FailureClassifier::fromExit(false, null, 1);

        self::assertTrue($verdict->failed);
        self::assertFalse($verdict->crashed);
    }

    public function testTerminationBySigtermIsAFailureNotACrash(): void
    {
        $verdict = FailureClassifier::fromExit(true, 15, -1);

        self::assertTrue($verdict->failed);
        self::assertFalse($verdict->crashed);
    }

    public function testStartupExitCodeIsItsOwnKind(): void
    {
        $verdict = FailureClassifier::fromExit(false, null, ExitCode::STARTUP);

        self::assertTrue($verdict->failed);
        self::assertTrue($verdict->startupError);
        self::assertFalse($verdict->crashed);
        self::assertSame('startup', $verdict->kind());
    }

    public function testOrdinaryNonZeroExitIsNotAStartupError(): void
    {
        $verdict = FailureClassifier::fromExit(false, null, 1);

        self::assertFalse($verdict->startupError);
        self::assertSame('failure', $verdict->kind());
    }

    public function testKilledRunIsNotAStartupErrorEvenWithTheStartupExitCode(): void
    {
        $timedOut = FailureClassifier::fromExit(false, null, ExitCode::STARTUP, true);
        self::assertFalse($timedOut->startupError);
        self::assertSame('timeout', $timedOut->kind());

        $signalled = FailureClassifier::fromExit(true, 11, ExitCode::STARTUP);
        self::assertFalse($signalled->startupError);
        self::assertSame('crash', $signalled->kind());
    }

    public function testTimeoutIsAFailure(): void
    {
        $verdict = FailureClassifier::fromExit(false, null, null, true);

        self::assertTrue($verdict->failed);
        self::assertTrue($verdict->timedOut);
        self::assertFalse($verdict->crashed);
        self::assertSame('timeout', $verdict->kind());
    }

    public function testLeakOnAnOtherwiseCleanExitIsAFailure(): void
    {
        $verdict = FailureClassifier::fromExit(false, null, 0, false, true);

        self::assertTrue($verdict->failed);
        self::assertTrue($verdict->leaked);
        self::assertFalse($verdict->crashed);
        self::assertFalse($verdict->timedOut);
        self::assertSame('leak', $verdict->kind());
    }

    public function testCrashOutranksLeakInKind(): void
    {
        $verdict = FailureClassifier::fromExit(true, 11, -1, false, true);

        self::assertSame('crash', $verdict->kind());
    }

    public function testTimeoutOutranksLeakInKind(): void
    {
        $verdict = FailureClassifier::fromExit(false, null, null, true, true);

        self::assertSame('timeout', $verdict->kind());
    }

    public function testMatchesTreatsTimeoutAsItsOwnClass(): void
    {
        $timeout = FailureClassifier::fromExit(false, null, null, true);
        $timeoutRerun = FailureClassifier::fromExit(false, null, null, true);
        $exitRerun = FailureClassifier::fromExit(false, null, 1);

        self::assertTrue($timeout->matches($timeoutRerun));
        self::assertFalse($timeout->matches($exitRerun));
    }

    public function testMatchesTreatsLeakAsItsOwnClass(): void
    {
        $leak = FailureClassifier::fromExit(false, null, 0, false, true);
        $cleanRerun = FailureClassifier::fromExit(false, null, 0, false, false);
        $leakRerun = FailureClassifier::fromExit(false, null, 0, false, true);

        self::assertTrue($leak->matches($leakRerun));
        self::assertFalse($leak->matches($cleanRerun));
        self::assertFalse(FailureClassifier::fromExit(false, null, 1)->matches($leakRerun));
    }

    public function testMatchesComparesFailureClass(): void
    {
        $crashSegv = FailureClassifier::fromExit(true, 11, -1);
        $crashAbrt = FailureClassifier::fromExit(true, 6, -1);
        $exitThree = FailureClassifier::fromExit(false, null, 3);

        self::assertTrue($crashSegv->matches(FailureClassifier::fromExit(false, null, 139)));
        self::assertFalse($crashSegv->matches($crashAbrt));
        self::assertFalse($crashSegv->matches(FailureClassifier::fromExit(false, null, 0)));

        self::assertTrue($exitThree->matches(FailureClassifier::fromExit(false, null, 3)));
        self::assertFalse($exitThree->matches(FailureClassifier::fromExit(false, null, 4)));
    }
}
