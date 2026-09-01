<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

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

    public function testTimeoutIsAFailure(): void
    {
        $verdict = FailureClassifier::fromExit(false, null, null, true);

        self::assertTrue($verdict->failed);
        self::assertTrue($verdict->timedOut);
        self::assertFalse($verdict->crashed);
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
