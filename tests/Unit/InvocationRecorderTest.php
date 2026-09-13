<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command\get;
use Mgrunder\PhpredisCommandFuzzer\DifferentialOracle;
use Mgrunder\PhpredisCommandFuzzer\InvocationMode;
use Mgrunder\PhpredisCommandFuzzer\InvocationRecorder;
use Mgrunder\PhpredisCommandFuzzer\ScriptLogger;
use Mgrunder\PhpredisCommandFuzzer\Stateful\CallableScenarioPeers;
use Mgrunder\PhpredisCommandFuzzer\Stateful\StatefulScenarioRunner;
use Mgrunder\PhpredisCommandFuzzer\Tests\Fixtures\InvocationTrace;
use Mgrunder\PhpredisCommandFuzzer\Tests\Fixtures\RecordingRedis;
use Mgrunder\PhpredisCommandFuzzer\Tests\Fixtures\RecordingRelay;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvocationRecorderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'invocation-recorder-');
        self::assertIsString($path);
        $this->path = $path;
        InvocationTrace::$calls = [];
        InvocationTrace::$referenceResults = [];
    }

    protected function tearDown(): void
    {
        ScriptLogger::finish();
        Command::setDifferentialOracle(null);
        Command::finishCapturedWarnings();
        InvocationTrace::$before = null;
        unlink($this->path);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function scenarios(): iterable
    {
        yield 'commit' => ['transaction-exec', false];
        yield 'discard' => ['transaction-discard', false];
        yield 'watch peer' => ['watch-abort', false];
        yield 'throw then cleanup' => ['transaction-exec', true];
    }

    #[DataProvider('scenarios')]
    public function testScenarioReplayMatchesActualCalls(string $scenario, bool $throws): void
    {
        $client = new RecordingRedis();
        $peer = new RecordingRedis($client->server());
        $peer->identity = 'peer';
        $invoker = InvocationMode::Strict->invoker();
        ScriptLogger::init($this->path, clients: [$client]);
        if ($throws) {
            InvocationRecorder::invoke($invoker, $client, 'failExec', [], 'setup');
        }
        $outcome = (new StatefulScenarioRunner($invoker, new CallableScenarioPeers(
            static fn (): RecordingRedis => $peer,
        )))->run([$client], [$scenario], 42)[0];
        self::assertSame($throws ? 'failed' : 'passed', $outcome->status, $outcome->failure ?? '');
        self::assertSame('setup', $outcome->steps[0]->phase);
        self::assertSame(RecordingRedis::class . '#0', $outcome->steps[0]->clientId);
        self::assertStringContainsString('fuzzer:state:42:', $outcome->steps[0]->argumentCode[0]);
        $script = $this->replayAndCompare();
        self::assertStringContainsString('phase=setup', $script);
        self::assertStringContainsString('phase=scheduled-call', $script);
        self::assertStringContainsString('phase=cleanup', $script);
        self::assertStringContainsString('scenario=' . $scenario . ' seed=42', $script);
        if ($throws) {
            self::assertStringContainsString('threw TypeError', $script);
            self::assertStringContainsString('->discard();', $script);
        }
        if ($scenario === 'watch-abort') {
            self::assertStringContainsString('#peer-', $script);
            self::assertStringContainsString('scenario-peer release', $script);
        }
    }

    public function testFuzzerWritesSavedFaultSetupBeforeScenarios(): void
    {
        $hooks = new \Mgrunder\PhpredisCommandFuzzer\Hooks\HookRegistry();
        $hooks->addLifecycleHook('fault', new class implements
            \Mgrunder\PhpredisCommandFuzzer\Hooks\PostConstructorHook,
            \Mgrunder\PhpredisCommandFuzzer\Hooks\ScriptSetupHook
        {
            public function postConstructor(\Mgrunder\PhpredisCommandFuzzer\Hooks\ClientEvent $event): void {}
            public function recordScriptSetup(): void
            {
                ScriptLogger::comment('saved fault schedule before scenario');
            }
        });
        $result = (new \Mgrunder\PhpredisCommandFuzzer\Fuzzer($hooks))->run(
            [new RecordingRedis()],
            new \Mgrunder\PhpredisCommandFuzzer\RunConfiguration(
                maxSteps: 1, seed: 42, commands: ['isconnected'], includeLocal: true,
                scenarios: ['transaction-exec'], scriptLog: $this->path,
            ),
        );
        self::assertSame('passed', $result->statefulOutcomes[0]->status);
        $script = file_get_contents($this->path);
        self::assertIsString($script);
        $setup = strpos($script, 'saved fault schedule before scenario');
        $scenario = strpos($script, 'scenario=transaction-exec');
        self::assertIsInt($setup);
        self::assertIsInt($scenario);
        self::assertLessThan($scenario, $setup);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function oracleReplies(): iterable
    {
        yield 'matched' => [['current', 'current'], 'matched'];
        yield 'stale warm cache' => [['stale', 'stale', 'current'], 'converged'];
        yield 'warm read throws' => [['current', 'throw', 'current'], 'converged'];
        yield 'scheduled read throws' => [['throw', 'current'], 'converged'];
    }

    /** @param list<string> $replies */
    #[DataProvider('oracleReplies')]
    public function testOracleReplayMatchesActualCalls(array $replies, string $status): void
    {
        if (!class_exists(\Relay\Relay::class)) {
            self::markTestSkipped('Relay is not installed');
        }
        $reference = new RecordingRedis();
        $subject = new RecordingRelay();
        $invoker = InvocationMode::Strict->invoker();
        ScriptLogger::init($this->path, clients: [$reference, $subject]);
        InvocationRecorder::invoke($invoker, $reference, 'set', ['key:{42}', 'current'], 'setup');
        InvocationRecorder::invoke($invoker, $subject, 'replies', [$replies], 'setup');
        $oracle = new DifferentialOracle($reference, $subject, 0, 1, 100.0, 0.1);
        Command::setDifferentialOracle($oracle);
        $oracle->beginStep(1, 'get', 'normal');
        try {
            (new get())->cmd($subject, 'get', 'key:{42}');
        } catch (\TypeError) {
            self::assertSame('throw', $replies[0]);
        }
        self::assertCount(1, $oracle->outcomes(), 'Auxiliary calls must not recursively evaluate the oracle');
        self::assertSame($status, $oracle->outcomes()[0]->status);
        $script = $this->replayAndCompare();
        self::assertStringContainsString('phase=reference-read', $script);
        self::assertStringContainsString('phase=warm-read', $script);
        self::assertStringContainsString('tolerance_ms=100 poll_interval_ms=0.1', $script);
        if (count($replies) === 3) {
            self::assertStringContainsString('phase=convergence-poll', $script);
            self::assertStringContainsString('usleep(100);', $script);
        }
        $reads = array_values(array_filter(InvocationTrace::$calls, static fn (array $call): bool => $call[1] === 'get'));
        self::assertSame('reference', $reads[0][0]);
        self::assertSame('subject', $reads[1][0]);
        self::assertSame('subject', $reads[2][0]);
    }

    /** @return iterable<string, array{InvocationMode}> */
    public static function modes(): iterable
    {
        yield 'strict' => [InvocationMode::Strict];
        yield 'coercive' => [InvocationMode::Coercive];
    }

    #[DataProvider('modes')]
    public function testReferencesAndAliasesSurviveRecordingAndReplay(InvocationMode $mode): void
    {
        $client = new RecordingRedis();
        ScriptLogger::init($this->path, clients: [$client], invocationMode: $mode);
        $value = 3;
        InvocationRecorder::invoke($mode->invoker(), $client, 'referencePair', [&$value, &$value]);
        self::assertSame(8, $value);
        $other = 5;
        InvocationRecorder::invoke($mode->invoker(), $client, 'referencePair', [&$value, &$other]);
        self::assertSame(9, $value);
        self::assertSame(10, $other);
        $results = InvocationTrace::$referenceResults;
        InvocationTrace::$referenceResults = [];
        $this->replayAndCompare();
        self::assertSame($results, InvocationTrace::$referenceResults);
    }

    public function testPhaseAndCallAreFlushedBeforeEnteringThrowingClient(): void
    {
        $client = new RecordingRedis();
        $client->throwOnExec = true;
        ScriptLogger::init($this->path, clients: [$client]);
        InvocationTrace::$before = function (string $identity, string $method): void {
            $script = file_get_contents($this->path);
            self::assertIsString($script);
            self::assertStringContainsString('phase=warm-read', $script);
            self::assertStringContainsString('->exec();', $script);
            self::assertStringNotContainsString('invocation=1 threw', $script);
        };
        $this->expectException(\TypeError::class);
        InvocationRecorder::invoke(InvocationMode::Strict->invoker(), $client, 'exec', [], 'warm-read');
    }

    private function replayAndCompare(): string
    {
        ScriptLogger::finish();
        $calls = InvocationTrace::$calls;
        InvocationTrace::$calls = [];
        $script = file_get_contents($this->path);
        self::assertIsString($script);
        $reporting = error_reporting();
        try {
            // Every constructed client is a named fake with a no-network connect().
            // Run the entire generated PHP, including waits and exception handlers.
            require $this->path;
        } finally {
            error_reporting($reporting);
        }
        self::assertSame($calls, InvocationTrace::$calls);
        return $script;
    }
}
