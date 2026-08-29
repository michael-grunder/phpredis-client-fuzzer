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

    public function testClientModeKillsRolledClientsOnAChosenNode(): void
    {
        $output = self::stream();
        $error = self::stream();
        $listed = [];
        $killed = [];
        $random = [0, 1, 999_999, 1, 1_000_000];

        $application = new FuzzKillerApplication(
            output: $output,
            error: $error,
            pidFinder: static fn (): array => [],
            signalSender: static fn (int $pid, int $signal): bool => true,
            sleeper: static function (int $microseconds): void {
            },
            randomInteger: static function (int $minimum, int $maximum) use (&$random): int {
                $value = array_shift($random);
                self::assertIsInt($value);
                self::assertGreaterThanOrEqual($minimum, $value);
                self::assertLessThanOrEqual($maximum, $value);
                return $value;
            },
            clock: static fn (): float => 0.0,
            nodeResolver: static function (string $address): array {
                self::assertSame('10.0.0.1:6379', $address);
                return ['10.0.0.1:6379'];
            },
            clientLister: static function (string $node) use (&$listed): array {
                $listed[] = $node;
                return [7, 9, 11];
            },
            clientKiller: static function (string $node, array $ids) use (&$killed): array {
                $killed[] = [$node, $ids];
                return [];
            },
            iterationLimit: 1,
        );

        $status = $application->run([
            '--mode=client',
            '--host=10.0.0.1',
            '--sleep=1-1',
            '--rate=50',
        ]);

        self::assertSame(0, $status);
        self::assertSame(['10.0.0.1:6379'], $listed);
        self::assertSame([['10.0.0.1:6379', [7, 11]]], $killed);
        self::assertSame([], $random);
        self::assertSame(
            "phpredis-fuzz-killer: targeting 1 Redis node(s): 10.0.0.1:6379\n"
            . "[00:00:00] Killing client 7 on 10.0.0.1:6379\n"
            . "[00:00:00] Not killing client 9 on 10.0.0.1:6379 (rate roll)\n"
            . "[00:00:00] Killing client 11 on 10.0.0.1:6379\n"
            . "[00:00:00] Sleeping for 1000000us\n",
            self::contents($output),
        );
        self::assertSame('', self::contents($error));
    }

    public function testBothModeSignalsProcessesAndKillsClientsEachIteration(): void
    {
        $output = self::stream();
        $sent = [];
        $killed = [];
        $random = [1, 0, 0, 999_999, 500_000];

        $application = new FuzzKillerApplication(
            output: $output,
            error: self::stream(),
            pidFinder: static fn (): array => [5],
            signalSender: static function (int $pid, int $signal) use (&$sent): bool {
                $sent[] = [$pid, $signal];
                return true;
            },
            sleeper: static function (int $microseconds): void {
            },
            randomInteger: static function (int $minimum, int $maximum) use (&$random): int {
                $value = array_shift($random);
                self::assertIsInt($value);
                self::assertGreaterThanOrEqual($minimum, $value);
                self::assertLessThanOrEqual($maximum, $value);
                return $value;
            },
            clock: static fn (): float => 0.0,
            nodeResolver: static fn (string $address): array => ['127.0.0.1:6379'],
            clientLister: static fn (string $node): array => [3],
            clientKiller: static function (string $node, array $ids) use (&$killed): array {
                $killed[] = [$node, $ids];
                return [];
            },
            iterationLimit: 1,
        );

        $status = $application->run([
            '--mode=both',
            '--signals=int,term',
            '--sleep=0-1',
            '--rate=50',
        ]);

        self::assertSame(0, $status);
        self::assertSame([[5, 2]], $sent);
        self::assertSame([], $killed);
        self::assertSame([], $random);
        self::assertSame(
            "phpredis-fuzz-killer: targeting 1 Redis node(s): 127.0.0.1:6379\n"
            . "[00:00:00] Sending SIGINT to 5\n"
            . "[00:00:00] Not killing client 3 on 127.0.0.1:6379 (rate roll)\n"
            . "[00:00:00] Sleeping for 500000us\n",
            self::contents($output),
        );
    }

    public function testPerActionRatesOverrideTheSharedRate(): void
    {
        $sent = [];
        $killed = [];
        $random = [0, 0, 0];

        $application = new FuzzKillerApplication(
            output: self::stream(),
            error: self::stream(),
            pidFinder: static fn (): array => [9],
            signalSender: static function (int $pid, int $signal) use (&$sent): bool {
                $sent[] = [$pid, $signal];
                return true;
            },
            sleeper: static function (int $microseconds): void {
            },
            randomInteger: static function (int $minimum, int $maximum) use (&$random): int {
                $value = array_shift($random);
                self::assertIsInt($value);
                return $value;
            },
            clock: static fn (): float => 0.0,
            nodeResolver: static fn (string $address): array => ['127.0.0.1:6379'],
            clientLister: static fn (string $node): array => [4],
            clientKiller: static function (string $node, array $ids) use (&$killed): array {
                $killed[] = [$node, $ids];
                return [];
            },
            iterationLimit: 1,
        );

        $status = $application->run([
            '--mode=both',
            '--rate=0',
            '--process-rate=100',
            '--client-rate=100',
            '--signals=term',
            '--sleep=0-0',
        ]);

        self::assertSame(0, $status);
        self::assertSame([[9, 15]], $sent);
        self::assertSame([['127.0.0.1:6379', [4]]], $killed);
        self::assertSame([], $random);
    }

    public function testClientListingFailureIsLoggedAndTheIterationContinues(): void
    {
        $output = self::stream();
        $error = self::stream();

        $application = new FuzzKillerApplication(
            output: $output,
            error: $error,
            pidFinder: static fn (): array => [],
            signalSender: static fn (int $pid, int $signal): bool => true,
            sleeper: static function (int $microseconds): void {
            },
            randomInteger: static fn (int $minimum, int $maximum): int => $minimum,
            clock: static fn (): float => 0.0,
            nodeResolver: static fn (string $address): array => ['127.0.0.1:6379'],
            clientLister: static function (string $node): array {
                throw new \RuntimeException('down');
            },
            clientKiller: static fn (string $node, array $ids): array => [],
            iterationLimit: 1,
        );

        $status = $application->run(['--mode=client', '--sleep=0-0', '--rate=100']);

        self::assertSame(0, $status);
        self::assertStringContainsString(
            'Failed to list clients on 127.0.0.1:6379: down',
            self::contents($error),
        );
        self::assertStringContainsString('Sleeping for 0us', self::contents($output));
    }

    public function testInvalidOptionsAreRejectedBeforeTheLoop(): void
    {
        $invalidOptions = [
            [['--signals=nope'], 'Unknown Linux signal: SIGNOPE'],
            [['--signals='], '--signals cannot be empty'],
            [['--sleep=1.2-0.8'], '--sleep minimum must not exceed maximum'],
            [['--sleep=soon'], '--sleep must be a MIN-MAX range in seconds'],
            [['--rate=100.01'], '--rate must be between 0.0 and 100.0'],
            [['--mode=bogus'], '--mode must be one of: process, client, both'],
            [['--client-rate=150'], '--client-rate must be between 0.0 and 100.0'],
            [['--process-rate=-1'], '--process-rate must be between 0.0 and 100.0'],
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
