<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Harness\Fs;
use Mgrunder\PhpredisCommandFuzzer\Harness\RrTrace;
use PHPUnit\Framework\TestCase;

final class HarnessRrTraceTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            Fs::removeTree($this->root);
        }
    }

    public function testATraceStillHoldingTheSentinelIsNotComplete(): void
    {
        $trace = $this->trace(true);

        self::assertFalse(RrTrace::isComplete($trace));
        self::assertSame('rr trace never finalised (incomplete)', RrTrace::problem($trace));
    }

    public function testAFinalisedTraceHasNoProblem(): void
    {
        $trace = $this->trace(false);

        self::assertTrue(RrTrace::isComplete($trace));
        self::assertNull(RrTrace::problem($trace));
    }

    public function testAMissingTraceDirectoryIsReported(): void
    {
        self::assertSame('rr wrote no trace directory', RrTrace::problem($this->workspace() . '/nowhere'));
    }

    public function testARunThatWasNeverRecordedHasNoProblem(): void
    {
        self::assertNull(RrTrace::problem(null));
    }

    public function testAwaitCompleteGivesUpOnAnUnfinalisedTrace(): void
    {
        $trace = $this->trace(true);
        $started = microtime(true);

        self::assertFalse(RrTrace::awaitComplete($trace, 0.3));
        self::assertGreaterThanOrEqual(0.25, microtime(true) - $started);
    }

    public function testAwaitCompleteReturnsImmediatelyForAFinalisedTrace(): void
    {
        self::assertTrue(RrTrace::awaitComplete($this->trace(false), 30.0));
    }

    public function testRrsOwnFatalErrorIsExtractedFromStderr(): void
    {
        $log = $this->workspace() . '/stderr.log';
        file_put_contents($log, implode("\n", [
            'some fuzzer output',
            '[FATAL src/Task.cc:3887:spawn()] Unexpected stop 0x1c7f (STOP-SIGWINCH)',
            "Child's message: ",
            '=== Start rr backtrace:',
        ]) . "\n");

        self::assertSame(
            'rr: Unexpected stop 0x1c7f (STOP-SIGWINCH)',
            RrTrace::fatalError($log),
        );
    }

    public function testRrsBacktraceAloneIsEnoughToRecogniseItsOwnDeath(): void
    {
        $log = $this->workspace() . '/stderr.log';
        file_put_contents($log, "=== Start rr backtrace:\n/usr/local/bin/rr(+0xda34a)\n=== End rr backtrace\n");

        self::assertSame('rr: died with a backtrace of its own', RrTrace::fatalError($log));
    }

    public function testOrdinaryStderrHasNoFatalError(): void
    {
        $log = $this->workspace() . '/stderr.log';
        file_put_contents($log, "PHP Warning:  something\nrelay: reconnecting\n");

        self::assertNull(RrTrace::fatalError($log));
        self::assertNull(RrTrace::fatalError($this->workspace() . '/missing.log'));
    }

    private function trace(bool $incomplete): string
    {
        $dir = $this->workspace() . '/rr-trace';
        Fs::ensureDir($dir);
        file_put_contents($dir . '/events', '');
        if ($incomplete) {
            file_put_contents($dir . '/' . RrTrace::SENTINEL, '1');
        }

        return $dir;
    }

    private function workspace(): string
    {
        if ($this->root === '') {
            $this->root = sys_get_temp_dir() . '/phpredis-fuzz-rrtrace-' . getmypid() . '-' . mt_rand();
            Fs::ensureDir($this->root);
        }

        return $this->root;
    }
}
