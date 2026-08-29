<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Cli\OutputMode;
use Mgrunder\PhpredisCommandFuzzer\Cli\ResultFormatter;
use Mgrunder\PhpredisCommandFuzzer\Fuzzer;
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;
use PHPUnit\Framework\TestCase;

final class DifferentialOracleTest extends TestCase
{
    public function testMatchingInitialAndRepeatedReadsAreMatched(): void
    {
        $this->requireRelay();
        $result = (new Fuzzer())->run(
            [$this->reference('current'), $this->subject(['current', 'current'])],
            $this->configuration(),
        );

        self::assertCount(1, $result->differentialOutcomes);
        $outcome = $result->differentialOutcomes[0];
        self::assertSame('matched', $outcome->status);
        self::assertTrue($outcome->initialMatch);
        self::assertSame([], $outcome->finalDifferences);
        self::assertSame(2, $outcome->subjectAttempts);
        self::assertFalse($result->hasDifferentialDivergence());
    }

    public function testTransientMismatchIsSeparatedFromDivergence(): void
    {
        $this->requireRelay();
        $result = (new Fuzzer())->run(
            [$this->reference('current'), $this->subject(['stale', 'stale', 'current'])],
            $this->configuration(toleranceMilliseconds: 20.0),
        );

        $outcome = $result->differentialOutcomes[0];
        self::assertSame('converged', $outcome->status);
        self::assertFalse($outcome->initialMatch);
        self::assertSame(['reply'], $outcome->initialDifferences);
        self::assertSame([], $outcome->finalDifferences);
        self::assertSame(3, $outcome->subjectAttempts);
        self::assertNotNull($outcome->convergenceSeconds);
        self::assertFalse($result->hasDifferentialDivergence());

        /* The ordinary invocation outcome remains the initially observed value. */
        self::assertSame(
            base64_encode('stale'),
            $result->outcomes[0]->reply['preview_base64'] ?? null,
        );

        $output = (new ResultFormatter())->format($result, OutputMode::Detailed);
        self::assertStringContainsString('Differential checks:      1', $output);
        self::assertStringContainsString('Differential converged:   1', $output);
        self::assertStringContainsString('Differential divergent:   0', $output);
        self::assertStringContainsString('#1 get: converged (reply, 3 subject attempts', $output);
    }

    public function testMismatchPastToleranceIsDivergent(): void
    {
        $this->requireRelay();
        $result = (new Fuzzer())->run(
            [$this->reference('current'), $this->subject(['stale'])],
            $this->configuration(toleranceMilliseconds: 0.0),
        );

        $outcome = $result->differentialOutcomes[0];
        self::assertSame('divergent', $outcome->status);
        self::assertSame(['reply'], $outcome->finalDifferences);
        self::assertSame(2, $outcome->subjectAttempts);
        self::assertTrue($result->hasDifferentialDivergence());
    }

    public function testDifferentialModeRequiresAnOrderedPair(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly two clients');

        (new Fuzzer())->run(
            [new \Redis()],
            $this->configuration(),
        );
    }

    public function testNondeterministicCachedReadsAreNotCompared(): void
    {
        $this->requireRelay();
        $result = (new Fuzzer())->run(
            [$this->reference('reference'), $this->subject(['subject'])],
            new RunConfiguration(
                maxSteps: 1,
                seed: 42,
                commands: ['srandmember'],
                differential: true,
            ),
        );

        self::assertCount(1, $result->outcomes);
        self::assertSame([], $result->differentialOutcomes);
    }

    private function configuration(float $toleranceMilliseconds = 10.0): RunConfiguration
    {
        return new RunConfiguration(
            maxSteps: 1,
            seed: 42,
            commands: ['get'],
            differential: true,
            differentialToleranceMs: $toleranceMilliseconds,
            differentialPollIntervalMs: 0.1,
        );
    }

    private function reference(mixed $reply): \Redis
    {
        return new class ($reply) extends \Redis {
            public function __construct(private mixed $reply)
            {
            }

            public function rawCommand(string $command, mixed ...$arguments): mixed
            {
                return [['get']];
            }

            public function get(string $key): mixed
            {
                return $this->reply;
            }

            public function sRandMember(string $key, int $count = 0): string
            {
                return 'reference';
            }

            public function getMode(): int
            {
                return \Redis::ATOMIC;
            }

            public function getLastError(): ?string
            {
                return null;
            }

            public function clearLastError(): bool
            {
                return true;
            }

            public function getHost(): string
            {
                return 'reference';
            }

            public function getPort(): int
            {
                return 0;
            }

            public function getOption(int $option): mixed
            {
                return 0;
            }
        };
    }

    /** @param non-empty-list<mixed> $replies */
    private function subject(array $replies): \Relay\Relay
    {
        return new class ($replies) extends \Relay\Relay {
            private int $call = 0;

            /** @param non-empty-list<mixed> $replies */
            public function __construct(private array $replies)
            {
            }

            public function rawCommand(string $command, mixed ...$arguments): mixed
            {
                return [['get']];
            }

            public function get(mixed $key): mixed
            {
                $index = min($this->call++, count($this->replies) - 1);

                return $this->replies[$index];
            }

            public function srandmember(mixed $set, int $count = 1): mixed
            {
                return $this->get($set);
            }

            public function getMode(bool $masked = false): int
            {
                return \Redis::ATOMIC;
            }

            public function getLastError(): ?string
            {
                return null;
            }

            public function clearLastError(): bool
            {
                return true;
            }

            public function getHost(): string
            {
                return 'subject';
            }

            public function getPort(): int
            {
                return 0;
            }

            public function getOption(int $option): mixed
            {
                return 0;
            }
        };
    }

    private function requireRelay(): void
    {
        if (!class_exists(\Relay\Relay::class)) {
            self::markTestSkipped('Relay is not installed');
        }
    }
}
