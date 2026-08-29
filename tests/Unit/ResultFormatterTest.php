<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Cli\OutputMode;
use Mgrunder\PhpredisCommandFuzzer\Cli\ResultFormatter;
use Mgrunder\PhpredisCommandFuzzer\FuzzResult;
use Mgrunder\PhpredisCommandFuzzer\InvocationOutcome;
use PHPUnit\Framework\TestCase;

final class ResultFormatterTest extends TestCase
{
    public function testJsonModePreservesTheSerializedResult(): void
    {
        $result = $this->fuzzResult();

        self::assertSame(
            json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n",
            (new ResultFormatter())->format($result, OutputMode::Json),
        );
        self::assertSame([
            'seed',
            'steps',
            'cross_slot_steps',
            'elapsed_seconds',
            'selected_commands',
            'environment',
            'configuration',
            'commands',
            'outcomes',
            'problematic_commands',
            'warnings',
            'caught_diagnostic',
        ], array_keys($result->jsonSerialize()));
    }

    public function testSimpleModeContainsOnlyOverallStatistics(): void
    {
        $output = (new ResultFormatter())->format($this->fuzzResult(), OutputMode::Simple);

        self::assertStringContainsString('Commands processed:       5', $output);
        self::assertStringContainsString('Unique commands executed: 2', $output);
        self::assertStringContainsString('Warnings:                 2 (1 unique)', $output);
        self::assertStringContainsString('Redis errors:             3 (2 unique)', $output);
        self::assertStringContainsString('Exceptions:               2 (1 unique)', $output);
        self::assertStringNotContainsString('Per-command results', $output);
        self::assertStringNotContainsString('Problematic commands', $output);
        self::assertStringNotContainsString('bad warning', $output);
    }

    public function testDetailedModeContainsAlignedCommandsAndIssueMessages(): void
    {
        $output = (new ResultFormatter())->format($this->fuzzResult(), OutputMode::Detailed);

        self::assertStringContainsString('Per-command results', $output);
        self::assertMatchesRegularExpression('/get\s+3\s+string: 3\s+2\s+0\s+0/', $output);
        self::assertMatchesRegularExpression('/set\s+2\s+false: 1\s+0\s+1\s+2/', $output);
        self::assertStringContainsString('warning x2: PHP Warning: bad warning', $output);
        self::assertStringContainsString('Redis error x1: WRONGTYPE bad value', $output);
        self::assertStringContainsString(
            "Redis error x2: NOGROUP No such key '<key>' or consumer group 'fuzzer'",
            $output,
        );
        self::assertStringNotContainsString('stream:{7}:55', $output);
        self::assertStringNotContainsString('stream:{3}:48', $output);
        self::assertStringContainsString('exception x2: RuntimeException: broken', $output);
        self::assertStringContainsString(
            'Problematic commands (server-supported commands with only false replies)',
            $output,
        );
        self::assertStringContainsString('set: 2 executed, 1 false reply', $output);
    }

    public function testOutputModeRejectsUnknownValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expected json, simple, or detailed');

        OutputMode::parse('pretty');
    }

    private function fuzzResult(): FuzzResult
    {
        return new FuzzResult(
            seed: 42,
            steps: 5,
            elapsedSeconds: 0.25,
            commands: [
                'set' => [
                    'count' => 2,
                    'replies' => ['false' => 1],
                    'exceptions' => ['RuntimeException: broken' => 2],
                ],
                'get' => [
                    'count' => 3,
                    'replies' => ['string' => 3],
                    'exceptions' => [],
                ],
            ],
            warnings: ['PHP Warning: bad warning' => 2],
            selectedCommands: ['get', 'set'],
            environment: [],
            configuration: [],
            crossSlotSteps: 0,
            commandWarnings: ['get' => ['PHP Warning: bad warning' => 2]],
            caughtDiagnostic: 'RuntimeException: broken',
            problematicCommands: [
                'set' => ['executions' => 2, 'false_replies' => 1],
            ],
            outcomes: [new InvocationOutcome(
                sequence: 1,
                command: 'set',
                variant: null,
                clientId: 'Redis#0',
                clientIndex: 0,
                clientClass: \Redis::class,
                operation: 'normal',
                replyType: 'false',
                reply: ['type' => 'bool', 'value' => false],
                redisErrors: ['WRONGTYPE bad value'],
                warnings: [],
                exception: null,
                durationSeconds: 0.001,
                modeBefore: \Redis::ATOMIC,
                modeAfter: \Redis::ATOMIC,
                slotPolicy: 'same-slot',
            ), new InvocationOutcome(
                sequence: 2,
                command: 'xpending',
                variant: null,
                clientId: 'Redis#0',
                clientIndex: 0,
                clientClass: \Redis::class,
                operation: 'normal',
                replyType: 'false',
                reply: ['type' => 'bool', 'value' => false],
                redisErrors: [
                    "NOGROUP No such key 'stream:{7}:55' or consumer group 'fuzzer'",
                ],
                warnings: [],
                exception: null,
                durationSeconds: 0.001,
                modeBefore: \Redis::ATOMIC,
                modeAfter: \Redis::ATOMIC,
                slotPolicy: 'same-slot',
            ), new InvocationOutcome(
                sequence: 3,
                command: 'xpending',
                variant: null,
                clientId: 'Redis#0',
                clientIndex: 0,
                clientClass: \Redis::class,
                operation: 'normal',
                replyType: 'false',
                reply: ['type' => 'bool', 'value' => false],
                redisErrors: [
                    "NOGROUP No such key 'stream:{3}:48' or consumer group 'fuzzer'",
                ],
                warnings: [],
                exception: null,
                durationSeconds: 0.001,
                modeBefore: \Redis::ATOMIC,
                modeAfter: \Redis::ATOMIC,
                slotPolicy: 'same-slot',
            )],
        );
    }
}
