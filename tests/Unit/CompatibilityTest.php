<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Cli\CompatibilityApplication;
use Mgrunder\RedisClientTester\Cli\ExitCode;
use Mgrunder\RedisClientTester\ClientConfiguration;
use Mgrunder\RedisClientTester\Commands\Registry;
use Mgrunder\RedisClientTester\Compatibility\Minimizer;
use Mgrunder\RedisClientTester\Compatibility\Process;
use Mgrunder\RedisClientTester\Harness\Fs;
use Mgrunder\RedisClientTester\Compatibility\Recording;
use Mgrunder\RedisClientTester\Differential\DiffRuntime;
use Mgrunder\RedisClientTester\Harness\FailureClassifier;
use Mgrunder\RedisClientTester\Harness\HarnessOptions;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type Tape from Recording */
final class CompatibilityTest extends TestCase
{
    public function testOnlyDeclaredContainersIgnoreOrdering(): void
    {
        $registry = new Registry();
        foreach (['smembers', 'sinter', 'sunion', 'sdiff', 'hkeys', 'hvals'] as $name) {
            self::assertSame('set', $registry->get($name)?->comparisonMode());
        }
        self::assertSame('map', $registry->get('hgetall')?->comparisonMode());
        self::assertSame('exact', $registry->get('lrange')?->comparisonMode());
        self::assertSame(Recording::canonical(['b', 'a'], 'set'), Recording::canonical(['a', 'b'], 'set'));
        self::assertSame(Recording::canonical(['b' => 1, 'a' => 2], 'map'), Recording::canonical(['a' => 2, 'b' => 1], 'map'));
        self::assertNotSame(Recording::canonical(['b', 'a'], 'exact'), Recording::canonical(['a', 'b'], 'exact'));
        self::assertNotSame(Recording::canonical(['b' => 1, 'a' => 2], 'map'), Recording::canonical(['a' => 1, 'b' => 2], 'map'));
        self::assertSame(Recording::canonical(['01' => 'a', 1 => 'b'], 'map'), Recording::canonical([1 => 'b', '01' => 'a'], 'map'));
        self::assertSame(Recording::canonical(['b', 1, 'a', 2], 'pairs'), Recording::canonical(['a', 2, 'b', 1], 'pairs'));
    }

    public function testTypesMultiplicitiesAndObjectPropertiesAreEvidence(): void
    {
        self::assertNotSame(Recording::canonical([1], 'set'), Recording::canonical(['1'], 'set'));
        self::assertNotSame(Recording::canonical(['a', 'a'], 'set'), Recording::canonical(['a'], 'set'));
        self::assertNotSame(Recording::canonical(false, 'exact'), Recording::canonical(null, 'exact'));
        self::assertSame(Recording::canonical(NAN, 'exact'), Recording::canonical(NAN, 'exact'));
        self::assertNotSame(Recording::canonical(NAN, 'exact'), Recording::canonical(['float' => 'NAN'], 'exact'));
        self::assertNotSame(Recording::canonical((object) ['x' => 1], 'exact'), Recording::canonical((object) ['x' => 2], 'exact'));
        self::assertNotSame(Recording::canonical(0.0, 'exact'), Recording::canonical(-0.0, 'exact'));
    }

    public function testUnsupportedNondeterminismIsExcludedButFlushIsAvailable(): void
    {
        $registry = new Registry();
        foreach (['spop', 'scan', 'hscan', 'expire', 'msetex', 'time', 'info', 'xadd', 'move', 'copy', 'auth', 'eval_ro'] as $name) {
            self::assertFalse($registry->get($name)?->supportsCompatibilityReplay(), $name);
        }
        foreach (['set', 'hset', 'sadd', 'lpush', 'mset', 'flushall', 'flushdb'] as $name) {
            self::assertTrue($registry->get($name)?->supportsCompatibilityReplay(), $name);
        }
    }

    public function testComparisonFindsReplyDiagnosticsAndState(): void
    {
        $tape = $this->tape(['get']);
        $a = ['outcomes' => [['value' => 'a', 'exception' => null, 'warnings' => [], 'error' => null]], 'state' => []];
        $b = $a;
        self::assertNull(CompatibilityApplication::difference($tape, $a, $b));
        $b['outcomes'][0]['value'] = 'b';
        self::assertSame('get:value', CompatibilityApplication::difference($tape, $a, $b)['signature'] ?? null);
        $b = $a;
        $b['outcomes'][0]['warnings'] = ['warning'];
        self::assertSame('get:warnings', CompatibilityApplication::difference($tape, $a, $b)['signature'] ?? null);
        $b = $a;
        $b['state'] = [['key', 'string', 'data', -1]];
        self::assertNull(CompatibilityApplication::difference($tape, $a, $b)['index'] ?? null);
        self::assertStringContainsString('exists only', CompatibilityApplication::difference($tape, $a, $b)['detail'] ?? '');
    }

    public function testIncompleteResultsAreNotMatches(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        CompatibilityApplication::difference($this->tape(['get']), ['outcomes' => [], 'state' => []], ['outcomes' => [], 'state' => []]);
    }

    public function testRemovalMinimizerKeepsRequiredSetupAndFailure(): void
    {
        $tape = $this->tape(['noise', 'setup', 'noise', 'failure', 'noise']);
        $result = Minimizer::reduce($tape, static function (array $candidate): bool {
            $names = array_column($candidate['calls'], 'method');
            return in_array('setup', $names, true) && in_array('failure', $names, true);
        }, 1);
        self::assertSame(['setup', 'failure'], array_column($result['calls'], 'method'));
        self::assertSame($tape, Minimizer::reduce($tape, static fn (array $candidate): bool => true, 0));
    }

    public function testArgumentsFreezeReferencesAndDoNotRunSerializationCallbacks(): void
    {
        $payload = "binary\0value";
        $object = new \Mgrunder\RedisClientTester\Values\SerializedValue($payload, 'throw-serialize');
        $code = Recording::freeze([&$payload, &$payload, $object]);
        $payload = 'changed';
        $object->payload = 'changed';
        $arguments = Recording::arguments($code);
        self::assertSame("binary\0value", $arguments[0]);
        self::assertInstanceOf(\Mgrunder\RedisClientTester\Values\SerializedValue::class, $arguments[2]);
        self::assertSame("binary\0value", $arguments[2]->payload);
        $arguments[0] = 'alias';
        self::assertSame('alias', $arguments[1]);
    }

    public function testSplitLeavesWorkloadArgumentsIntact(): void
    {
        self::assertSame([
            ['--php-args', '-n -d extension=a.so', '--php-args2=-n', '--no-minimize'],
            ['--seed', '42', '--commands=set,get', '--client=redis'],
        ], CompatibilityApplication::split(['--php-args', '-n -d extension=a.so', '--seed', '42',
            '--php-args2=-n', '--commands=set,get', '--no-minimize', '--client=redis']));
    }

    public function testHarnessHasIndependentStartupAndClassification(): void
    {
        $options = HarnessOptions::parse(['--capture=compatibility', '--php-args=-n -d a=1',
            '--php-args2=-n -d a=2', '--php-ini2=second.ini', '--', 'bin/compatibility']);
        self::assertSame(['-n', '-d', 'a=1'], $options->phpArgs);
        self::assertSame(['-n', '-d', 'a=2'], $options->phpArgs2);
        self::assertSame('second.ini', $options->phpIni2);
        self::assertSame('compatibility', FailureClassifier::fromExit(false, null, ExitCode::COMPATIBILITY)->kind());
        self::assertSame('crash', FailureClassifier::fromExit(true, 11, ExitCode::COMPATIBILITY)->kind());
        self::assertSame('timeout', FailureClassifier::fromExit(false, null, ExitCode::COMPATIBILITY, true)->kind());
        self::assertSame('Client::get(): failure', DiffRuntime::normalizeMessage('Relay\\Cluster::get(): failure'));
    }

    public function testUnusableWorkerStartupFailsBeforeAnyServerIsStarted(): void
    {
        $root = sys_get_temp_dir() . '/compat-startup-' . bin2hex(random_bytes(8));
        Fs::ensureDir($root);
        try {
            $exit = Process::run([PHP_BINARY, dirname(__DIR__, 2) . '/bin/compatibility',
                '--php-args=-n', '--artifacts=' . $root . '/artifacts'], $root . '/run', 5);
            self::assertSame(ExitCode::STARTUP, $exit);
            self::assertSame([], glob($root . '/artifacts/*/redis'));
            $error = file_get_contents($root . '/run.stderr.log');
            self::assertIsString($error);
            self::assertStringContainsString('preflight failed', $error);
        } finally {
            Fs::removeTree($root);
        }
    }

    public function testReproducerSurvivesMovingArtifactsWithQuotedIniPaths(): void
    {
        $root = sys_get_temp_dir() . '/compat-repro-' . bin2hex(random_bytes(8));
        Fs::ensureDir($root . '/original');
        $script = $root . '/child.php';
        file_put_contents($script, '<?php echo json_encode(array_slice($argv, 1)); exit(2);');
        $argument = "--php-args='-c' '" . $root . "/original/a file.ini'";
        CompatibilityApplication::writeReproducer($root . '/original', [PHP_BINARY, $script, $argument]);
        rename($root . '/original', $root . '/moved');
        try {
            $exit = Process::run([PHP_BINARY, $root . '/moved/reproduce.php'], $root . '/run', 5);
            self::assertSame(2, $exit);
            $output = file_get_contents($root . '/run.stdout.log');
            self::assertIsString($output);
            self::assertSame([str_replace('/original/', '/moved/', $argument), '--replay=' . $root . '/moved/minimized.case'],
                json_decode($output, true, flags: JSON_THROW_ON_ERROR));
        } finally {
            Fs::removeTree($root);
        }
    }

    public function testHarnessRejectsSharedTargets(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HarnessOptions::parse(['--capture=compatibility', '--port=6379', '--', 'bin/compatibility']);
    }

    /** @param list<string> $methods
     * @return Tape
     */
    private function tape(array $methods): array
    {
        return ['configuration' => new ClientConfiguration(), 'calls' => array_map(
            static fn (string $method): array => ['method' => $method, 'command' => $method, 'args' => '[]', 'mode' => 'exact', 'coercive' => false], $methods)];
    }
}
