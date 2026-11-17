<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\ClientInvoker;
use Mgrunder\RedisClientTester\Commands\Command;
use Mgrunder\RedisClientTester\Compatibility\Process;
use Mgrunder\RedisClientTester\RunConfiguration;
use Mgrunder\RedisClientTester\ScriptLogger;
use Mgrunder\RedisClientTester\Stateful\StatefulScenarioRegistry;
use Mgrunder\RedisClientTester\Values\DeserializationFailure;
use Mgrunder\RedisClientTester\Values\DeserializationWakeupFailure;
use Mgrunder\RedisClientTester\Values\ValueCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisCluster;
use Relay\Cluster;
use Relay\Relay;

final class DeserializationFailureTest extends TestCase
{
    protected function tearDown(): void
    {
        DeserializationFailure::arm(null);
        ScriptLogger::finish();
        Command::finishCapturedWarnings();
    }

    public function testRequiresExplicitOptInAndNeverJoinsRandomScenarios(): void
    {
        foreach (range(0, 100) as $seed) {
            foreach (StatefulScenarioRegistry::resolve(['random'], $seed) as $name) {
                self::assertStringStartsNotWith('deserialization-', $name);
            }
        }
        new RunConfiguration(scenarios: ['deserialization-random'], includeCrashing: true);
        $this->expectException(\InvalidArgumentException::class);
        new RunConfiguration(scenarios: ['deserialization-random']);
    }

    /** @return iterable<string, array{string, class-string<Redis|RedisCluster|Relay|Cluster>}> */
    public static function clients(): iterable
    {
        foreach (['exception', 'error', 'type-error', 'value-error', 'parse-error'] as $mode) {
            foreach ([Redis::class, RedisCluster::class, Relay::class, Cluster::class] as $class) {
                yield $mode . '-' . $class => [$mode, $class];
            }
        }
    }

    /** @param class-string<Redis|RedisCluster|Relay|Cluster> $class */
    #[DataProvider('clients')]
    public function testWarmFailureAndRecoveryAreRepeatable(string $mode, string $class): void
    {
        if (!class_exists($class)) {
            self::markTestSkipped('Optional client unavailable');
        }
        $client = $this->createStub($class);
        $client->method('getMode')->willReturn(Redis::ATOMIC);
        $model = new DeserializationFixtureInvoker();
        $scenario = StatefulScenarioRegistry::create('deserialization-' . $mode, $model);
        $classes = [];
        foreach ([0, 4] as $seed) {
            $model->calls = [];
            $first = $scenario->run($client, $seed, 0);
            self::assertSame('passed', $first->status, $first->failure ?? '');
            $calls = $model->calls;
            $model->calls = [];
            $repeat = $scenario->run($client, $seed, 0);
            self::assertSame('passed', $repeat->status, $repeat->failure ?? '');
            self::assertSame($calls, $model->calls);
            self::assertGreaterThanOrEqual(3, count(array_filter($calls, static fn (array $call): bool => $call[0] === 'get')));
            $classes[$model->fixtureClass] = true;
            self::assertIsObject(unserialize($model->encoded));
        }
        self::assertCount(2, $classes, 'Both callback surfaces must be exercised');
    }

    public function testMixedWorkloadSchedulingIsSeededAndDisabledByDefault(): void
    {
        foreach ([0.0, 0.25, 1.0] as $chance) {
            $configuration = new RunConfiguration(maxSteps: 20, seed: 42, commands: ['isconnected'],
                includeLocal: true, includeCrashing: true, deserializationFailureChance: $chance);
            $first = (new \Mgrunder\RedisClientTester\Tester())->run([new Redis()], $configuration);
            $repeat = (new \Mgrunder\RedisClientTester\Tester())->run([new Redis()], $configuration);
            self::assertCount(count($first->statefulOutcomes), $repeat->statefulOutcomes);
            self::assertSame(20, $first->steps);
            if ($chance === 0.0 || $chance === 1.0) {
                self::assertCount($chance === 0.0 ? 0 : 20, $first->statefulOutcomes);
            } else {
                self::assertGreaterThan(0, count($first->statefulOutcomes));
                self::assertLessThan(20, count($first->statefulOutcomes));
            }
            foreach ($first->statefulOutcomes as $outcome) {
                self::assertSame('deserialization-random', $outcome->scenario);
                self::assertSame('skipped', $outcome->status, 'No serializer is enabled in this server-free test');
            }
        }
    }

    public function testRecursionIsDeliberatelyUnguardedButExplicitlyArmed(): void
    {
        $encoded = serialize(new DeserializationFailure('payload'));
        $reads = 0;
        DeserializationFailure::arm('recursion', static function () use (&$reads, $encoded): mixed {
            if (++$reads === 4) {
                throw new \RuntimeException('test recursion bound');
            }
            return unserialize($encoded);
        });
        try {
            unserialize($encoded);
            self::fail('Expected recursive read');
        } catch (\RuntimeException $error) {
            self::assertSame('test recursion bound', $error->getMessage());
            self::assertSame(4, $reads);
        } finally {
            DeserializationFailure::arm(null);
        }
        self::assertInstanceOf(DeserializationFailure::class, unserialize($encoded));
    }

    /** @return iterable<string, array{float, bool}> */
    public static function invalidChances(): iterable
    {
        yield 'negative' => [-0.1, true];
        yield 'too-large' => [1.1, true];
        yield 'nan' => [NAN, true];
        yield 'infinite' => [INF, true];
        yield 'missing-permission' => [0.5, false];
    }

    #[DataProvider('invalidChances')]
    public function testFailureChanceIsValidated(float $chance, bool $allowed): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RunConfiguration(deserializationFailureChance: $chance, includeCrashing: $allowed);
    }

    public function testHandledUserFatalDoesNotFallThroughToMemoryExhaustion(): void
    {
        $limit = ini_get('memory_limit');
        set_error_handler(static fn (): bool => true);
        try {
            DeserializationFailure::arm('user-fatal');
            DeserializationFailure::fire();
        } finally {
            DeserializationFailure::arm(null);
            restore_error_handler();
        }
        self::assertSame($limit, ini_get('memory_limit'));
    }

    public function testRelayOnlySerializerConstantIsRecognized(): void
    {
        if (!defined('Relay\\Relay::SERIALIZER_IGBINARY')) {
            self::markTestSkipped('Optional Relay serializer unavailable');
        }
        $client = $this->createStub(Relay::class);
        $client->method('getMode')->willReturn(Redis::ATOMIC);
        $model = new DeserializationFixtureInvoker();
        $model->serializer = Relay::SERIALIZER_IGBINARY;
        $outcome = StatefulScenarioRegistry::create('deserialization-exception', $model)->run($client, 42, 0);
        self::assertSame('passed', $outcome->status, $outcome->failure ?? '');
    }

    public function testFixturesAreInertOutsideScopeAndExportWithoutCallingThem(): void
    {
        foreach ([new DeserializationFailure('payload'), new DeserializationWakeupFailure('payload')] as $value) {
            DeserializationFailure::arm('exception');
            $code = ValueCode::export($value);
            self::assertInstanceOf($value::class, eval('return ' . $code . ';'));
            DeserializationFailure::arm(null);
            self::assertInstanceOf($value::class, unserialize(serialize($value)));
        }
    }

    public function testUnsupportedSerializerSkipsWithoutWriting(): void
    {
        $client = $this->createStub(Redis::class);
        $client->method('getMode')->willReturn(Redis::ATOMIC);
        $model = new DeserializationFixtureInvoker();
        $model->serializer = Redis::SERIALIZER_JSON;
        $outcome = StatefulScenarioRegistry::create('deserialization-fatal', $model)->run($client, 42, 0);
        self::assertSame('skipped', $outcome->status);
        self::assertSame([['getOption', '[0 => 1]']], $model->calls);
    }

    public function testScriptEmbedsFixturesAndArmingBeforeTheRead(): void
    {
        $client = $this->createStub(Redis::class);
        $client->method('getMode')->willReturn(Redis::ATOMIC);
        $client->method('getHost')->willReturn('server-free');
        $client->method('getPort')->willReturn(6379);
        $path = tempnam(sys_get_temp_dir(), 'deserialization-replay-');
        self::assertIsString($path);
        try {
            ScriptLogger::init($path, [], [$client]);
            $outcome = StatefulScenarioRegistry::create('deserialization-exception', new DeserializationFixtureInvoker())
                ->run($client, 42, 0);
            self::assertSame('passed', $outcome->status, $outcome->failure ?? '');
            ScriptLogger::finish();
            $script = file_get_contents($path);
            self::assertIsString($script);
            self::assertNotEmpty(token_get_all($script, TOKEN_PARSE));
            self::assertStringContainsString('class DeserializationFailure', $script);
            self::assertStringContainsString('class DeserializationWakeupFailure', $script);
            $armed = strpos($script, "::arm('exception',");
            self::assertIsInt($armed);
            self::assertStringContainsString('operation=deserialization-exception', substr($script, $armed));
            self::assertGreaterThanOrEqual(2, substr_count($script, '::arm(NULL,'));
        } finally {
            unlink($path);
        }
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function terminatingModes(): iterable
    {
        yield 'fatal' => ['fatal', 255, 'Cannot redeclare function'];
        yield 'compile-fatal' => ['compile-fatal', 255, 'abstract'];
        yield 'timeout' => ['timeout', 255, 'Maximum execution time'];
        yield 'user-fatal' => ['user-fatal', 255, 'Tester deserialization user fatal'];
        yield 'oom' => ['oom', 255, 'Allowed memory size'];
        yield 'exit' => ['exit', 86, ''];
    }

    #[DataProvider('terminatingModes')]
    public function testBailoutsEscapeCatchInAnIsolatedProcess(string $mode, int $exit, string $diagnostic): void
    {
        $path = tempnam(sys_get_temp_dir(), 'deserialization-child-');
        self::assertIsString($path);
        $source = 'require $argv[1]; '
            . '$class = $argv[3]; $encoded = serialize(new $class("payload")); '
            . 'Mgrunder\\RedisClientTester\\Values\\DeserializationFailure::arm($argv[2]); '
            . 'try { unserialize($encoded); } catch (Throwable $e) { echo "CAUGHT: ", $e; } echo "SURVIVED";';
        foreach ([DeserializationFailure::class, DeserializationWakeupFailure::class] as $class) {
            $log = $path . '-' . basename(str_replace('\\', '/', $class));
            $status = Process::run([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-r', $source,
                dirname(__DIR__, 2) . '/vendor/autoload.php', $mode, $class], $log, 5);
            self::assertSame($exit, $status, 'Diagnostics retained at ' . $log);
            $output = file_get_contents($log . '.stdout.log');
            $errors = file_get_contents($log . '.stderr.log');
            self::assertIsString($output);
            self::assertIsString($errors);
            self::assertStringNotContainsString('CAUGHT:', $output);
            self::assertStringNotContainsString('SURVIVED', $output);
            if ($diagnostic !== '') {
                self::assertStringContainsString($diagnostic, $errors);
            }
        }
    }
}

final class DeserializationFixtureInvoker implements ClientInvoker
{
    public int $serializer = Redis::SERIALIZER_PHP;
    public string $encoded = '';
    public string $fixtureClass = '';
    /** @var list<array{string, string}> */
    public array $calls = [];

    public function invoke(Redis|RedisCluster|Relay|Cluster $client, string $method, array $arguments): mixed
    {
        $this->calls[] = [$method, ValueCode::export($arguments)];
        if ($method === 'set') {
            $value = $arguments[1];
            if (!is_object($value)) {
                throw new \LogicException('Expected a callback fixture');
            }
            $this->fixtureClass = $value::class;
            $this->encoded = serialize($value);
            return true;
        }
        return match ($method) {
            'getOption' => $this->serializer,
            'get' => unserialize($this->encoded),
            'del' => 1,
            default => throw new \LogicException('Unexpected invocation ' . $method),
        };
    }
}
