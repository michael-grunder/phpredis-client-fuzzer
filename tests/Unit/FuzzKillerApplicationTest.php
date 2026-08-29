<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Cli\FuzzKillerApplication;
use PHPUnit\Framework\TestCase;

final class FuzzKillerApplicationTest extends TestCase
{
    public function testIterationSignalsSelectedPidsAndLogsEveryDecision(): void
    {
        $output = self::stream();
        $error = self::stream();
        $sent = [];
        $sleeps = [];
        $random = [1, 1, 999_999, 900_000];

        $application = new FuzzKillerApplication(
            output: $output,
            error: $error,
            pidFinder: static fn (): array => [101, 202],
            signalSender: static function (int $pid, int $signal) use (&$sent): bool {
                $sent[] = [$pid, $signal];
                return true;
            },
            sleeper: static function (int $microseconds) use (&$sleeps): void {
                $sleeps[] = $microseconds;
            },
            randomInteger: static function (int $minimum, int $maximum) use (&$random): int {
                $value = array_shift($random);
                self::assertIsInt($value);
                self::assertGreaterThanOrEqual($minimum, $value);
                self::assertLessThanOrEqual($maximum, $value);
                return $value;
            },
            clock: static fn (): float => 10.0,
            iterationLimit: 1,
        );

        $status = $application->run([
            '--signals=int,term',
            '--sleep=.8-1.2',
            '--rate=50.0',
        ]);

        self::assertSame(0, $status);
        self::assertSame([[101, 15]], $sent);
        self::assertSame([900_000], $sleeps);
        self::assertSame([], $random);
        self::assertSame(
            "[00:00:00] Sending SIGTERM to 101\n"
            . "[00:00:00] Not signaling 202 (rate roll)\n"
            . "[00:00:00] Sleeping for 900000us\n",
            self::contents($output),
        );
        self::assertSame('', self::contents($error));
    }

    public function testSignalAliasesAreDeduplicatedByNumber(): void
    {
        $sent = [];
        $application = new FuzzKillerApplication(
            output: self::stream(),
            error: self::stream(),
            pidFinder: static fn (): array => [123],
            signalSender: static function (int $pid, int $signal) use (&$sent): bool {
                $sent[] = [$pid, $signal];
                return true;
            },
            sleeper: static function (int $microseconds): void {
            },
            randomInteger: static fn (int $minimum, int $maximum): int => $maximum,
            clock: static fn (): float => 0.0,
            iterationLimit: 1,
        );

        $status = $application->run(['--signals=IOT,SIGABRT', '--sleep=0-0']);

        self::assertSame(0, $status);
        self::assertSame([[123, 6]], $sent);
    }

    public function testInvalidOptionsAreRejectedBeforeTheLoop(): void
    {
        $invalidOptions = [
            [['--signals=nope'], 'Unknown Linux signal: SIGNOPE'],
            [['--signals='], '--signals cannot be empty'],
            [['--sleep=1.2-0.8'], '--sleep minimum must not exceed maximum'],
            [['--sleep=soon'], '--sleep must be a MIN-MAX range in seconds'],
            [['--rate=100.01'], '--rate must be between 0.0 and 100.0'],
        ];

        foreach ($invalidOptions as [$arguments, $message]) {
            $error = self::stream();
            $status = (new FuzzKillerApplication(self::stream(), $error))->run($arguments);

            self::assertSame(1, $status);
            self::assertStringContainsString($message, self::contents($error));
        }
    }

    public function testHelpDoesNotEnterTheLoop(): void
    {
        $output = self::stream();
        $status = (new FuzzKillerApplication($output))->run(['--help']);

        self::assertSame(0, $status);
        self::assertStringContainsString('phpredis-fuzz-killer', self::contents($output));
        self::assertStringContainsString('--sleep=MIN-MAX', self::contents($output));
    }

    /** @return resource */
    private static function stream()
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        return $stream;
    }

    /** @param resource $stream */
    private static function contents($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
