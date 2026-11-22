<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Cli\Application;
use Mgrunder\RedisClientTester\Cli\CompatibilityApplication;
use Mgrunder\RedisClientTester\Cli\Options;
use Mgrunder\RedisClientTester\Cli\YamlConfiguration;
use Mgrunder\RedisClientTester\Compatibility\Process;
use Mgrunder\RedisClientTester\Harness\CommandTemplate;
use Mgrunder\RedisClientTester\Harness\HarnessOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YamlConfigurationTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            unlink($file);
        }
    }

    private function yaml(string $text): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tester-yaml-');
        self::assertNotFalse($path);
        $this->files[] = $path;
        file_put_contents($path, $text);
        return $path;
    }

    public function testHarnessMatchesCliAndExpandsBeforeTemplateValidation(): void
    {
        $file = $this->yaml(<<<'YAML'
harness:
  asan: true
  jobs: 4
  steps: 25000
  reduce: steps
  port: [6379, 8000]
  php-args: ['-n', '-drelay.maxmemory={2m-16m}']
  php-ini: conf.d/php.ini
runner: coercive
workload:
  client: redis:2,relay:2,redis-cluster:2,relay-cluster:2
  pick-client: true
  raw: false
  commands: ['@common', '@cached,@invalidating']
  hook: [hooks/no-msgpack.php, hooks/clamp-read-timeout.php]
  port: '{port}'
  steps: '{steps}'
YAML);
        $actual = HarnessOptions::parse(['--config', $file]);
        $expected = HarnessOptions::parse([
            '--asan', '--jobs=4', '--steps=25000', '--reduce=steps',
            '--port=6379', '--port=8000', '--php-args=-n',
            '--php-args=-drelay.maxmemory={2m-16m}', '--php-ini=conf.d/php.ini',
            '--', dirname(__DIR__, 2) . '/bin/coercive',
            '--client=redis:2,relay:2,redis-cluster:2,relay-cluster:2',
            '--pick-client', '--commands=@common', '--commands=@cached,@invalidating',
            '--hook=hooks/no-msgpack.php', '--hook=hooks/clamp-read-timeout.php',
            '--port={port}', '--steps={steps}',
        ]);
        self::assertEquals($expected, $actual);
        $template = new CommandTemplate($actual->command);
        $template->validate();
        self::assertTrue($template->uses('port'));
        self::assertTrue($template->uses('steps'));
    }

    public function testCliScalarsWinRegardlessOfConfigPositionAndListsAppend(): void
    {
        $file = $this->yaml(<<<'YAML'
workload:
  keys: 50
  timeout: 0.5
  raw: false
  commands: ['@common', '@cached,@invalidating']
  hook: [hooks/no-msgpack.php]
  password: '--literal $HOME {not-a-shell-expression}'
YAML);
        $args = YamlConfiguration::expand([
            '--keys=100', '--config=' . $file, '--commands=-get',
            '--hook=hooks/clamp-read-timeout.php', '--raw',
        ], 'tester');
        $options = Application::parseOptions($args);
        self::assertSame(100, $options->integer('keys', 0));
        self::assertSame(0.5, $options->number('timeout', 0));
        self::assertTrue($options->has('raw'));
        self::assertSame([['@common'], ['@cached', '@invalidating'], ['-get']],
            $options->csvGroups('commands'));
        self::assertSame(['hooks/no-msgpack.php', 'hooks/clamp-read-timeout.php'],
            $options->repeated('hook', splitCommas: false));
        self::assertSame('--literal $HOME {not-a-shell-expression}',
            $options->string('password', ''));
    }

    public function testSapiOverridesStayInTheirOwnLayers(): void
    {
        $file = $this->yaml(<<<'YAML'
harness: {jobs: 4, steps: 1000, seed: 42}
runner: sapi
sapi: {workers: 4, requests: 10, step-jitter: 50}
workload: {steps: '{steps}', seconds: 30, invocation-mode: coercive}
YAML);
        $options = HarnessOptions::parse([
            '--config=' . $file, '--jobs=2', '--', '--workers=8',
            '--', '--seconds=0',
        ]);
        self::assertSame(2, $options->jobs);
        self::assertSame(42, $options->seed);
        self::assertSame([
            dirname(__DIR__, 2) . '/bin/sapi', '--workers=4', '--requests=10',
            '--step-jitter=50', '--workers=8', '--', '--steps={steps}',
            '--seconds=30', '--invocation-mode=coercive', '--seconds=0',
        ], $options->command);
    }

    public function testChildConfigIsSnapshottedEvenWithoutHarnessConfig(): void
    {
        $file = $this->yaml("workload: {port: '{port}', steps: '{steps}', seed: 123, password: secret}\n");
        $options = HarnessOptions::parse([
            '--port=8000', '--reduce=steps', '--',
            'bin/tester', '--config=' . $file,
        ]);
        file_put_contents($file, "workload: {seed: 999}\n");
        self::assertSame(['bin/tester', '--port={port}', '--steps={steps}',
            '--seed=123', '--password=secret'], $options->command);
        self::assertTrue((new CommandTemplate($options->command))->hasOption('seed'));
    }

    public function testSapiExpandsWorkloadFileBeforeGeneratingRequests(): void
    {
        $file = $this->yaml('workload: {keys: 16, seed: 42}');
        self::assertSame(['--workers=2', '--', '--keys=16', '--seed=42'],
            YamlConfiguration::expand([
                '--workers=2', '--', '--config=' . $file,
            ], 'sapi'));
    }

    public function testExplicitPhpInterpreterStillExpandsChildConfiguration(): void
    {
        $file = $this->yaml('workload: {steps: "{steps}", seed: 42}');
        $options = HarnessOptions::parse([
            '--', PHP_BINARY, 'bin/tester', '--config=' . $file,
        ]);
        self::assertSame([PHP_BINARY, 'bin/tester', '--steps={steps}', '--seed=42'],
            $options->command);
    }

    public function testUnrelatedScriptWithSameNameKeepsItsOwnConfigOption(): void
    {
        $args = ['--', '/other/project/tester', '--config=custom-format.conf'];
        self::assertSame($args, YamlConfiguration::expand($args, 'harness'));
    }

    public function testHarnessOnlyFilePreservesArbitraryChildCommand(): void
    {
        $file = $this->yaml('harness: {jobs: 2}');
        $options = HarnessOptions::parse([
            '--config=' . $file, '--', '/tmp/custom-fuzzer', '--custom=value',
        ]);
        self::assertSame(2, $options->jobs);
        self::assertSame(['/tmp/custom-fuzzer', '--custom=value'], $options->command);
    }

    public function testCompatibilityAndDiffUseTheirOwnOptionCatalogs(): void
    {
        $file = $this->yaml(<<<'YAML'
harness:
  capture: compatibility,crashes,timeouts,failures
  php-args: ['-n', '-d extension=first.so']
  php-args2: ['-n', '-d extension=second.so']
runner: compatibility
compatibility: {client2: relay, worker-timeout: 45}
workload: {client: redis, steps: '{steps}', commands: 'set,get'}
YAML);
        $options = HarnessOptions::parse(['--config=' . $file]);
        self::assertSame(['-n', '-d', 'extension=second.so'], $options->phpArgs2);
        [$own, $workload] = CompatibilityApplication::split(array_slice($options->command, 1));
        self::assertSame(['--client2=relay', '--worker-timeout=45'], $own);
        self::assertSame('redis', Application::parseOptions($workload)->string('client', ''));

        $file = $this->yaml('runner: diff' . "\nworkload: {cases: 5, no-minimize: true, commands: ['@hash', '-hgetall']}");
        self::assertSame(['--cases=5', '--no-minimize', '--commands=@hash', '--commands=-hgetall'],
            YamlConfiguration::expand(['--config=' . $file], 'diff'));
    }

    public function testStandaloneSapiAndEmptySections(): void
    {
        $file = $this->yaml("runner: sapi\nsapi: {}\nworkload: {keys: 10}");
        self::assertSame(['--workers=2', '--', '--keys=10', '--keys=20'],
            YamlConfiguration::expand([
                '--config=' . $file, '--workers=2', '--', '--keys=20',
            ], 'sapi'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'root list' => ['[]', 'mapping'];
        yield 'empty file' => ['', 'mapping'];
        yield 'section list' => ['workload: []', 'mapping'];
        yield 'section typo' => ['worklod: {}', 'Unexpected configuration key'];
        yield 'unknown false flag' => ['workload: {bogus: false}', 'Unknown option'];
        yield 'unknown empty list' => ['workload: {bogus: []}', 'Unknown option'];
        yield 'value as flag' => ['workload: {steps: true}', 'string or finite number'];
        yield 'string as flag' => ['workload: {raw: "false"}', 'true or false'];
        yield 'scalar list' => ['workload: {steps: [1, 2]}', 'does not accept a list'];
        yield 'nested map' => ['workload: {hook: {path: x}}', 'string or finite number'];
        yield 'nested list' => ['workload: {hook: [[x]]}', 'string or finite number'];
        yield 'null' => ['workload: {keys: null}', 'string or finite number'];
        yield 'infinite' => ['workload: {timeout: .inf}', 'string or finite number'];
        yield 'date' => ['workload: {prefix: 2026-01-01}', 'string or finite number'];
        yield 'wrong runner' => ['runner: sapi', 'runner must be tester'];
        yield 'unknown runner' => ['runner: bogus', 'Unknown runner'];
        yield 'null runner' => ['runner: null', 'runner must be a string'];
        yield 'wrong section' => ['sapi: {}', 'Unexpected configuration key'];
        yield 'nested config' => ['workload: {config: other.yaml}', 'Unknown option'];
        yield 'duplicate' => ["workload: {keys: 1, keys: 2}", 'Duplicate key'];
        yield 'syntax' => ['workload: [', 'Malformed inline YAML'];
        yield 'numeric key' => ['workload: {1: 2}', 'keys must be strings'];
    }

    #[DataProvider('invalidConfigurations')]
    public function testInvalidYamlIsRejectedWithFilename(string $yaml, string $message): void
    {
        $file = $this->yaml($yaml);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($file . ':');
        $this->expectExceptionMessage($message);
        YamlConfiguration::expand(['--config=' . $file], 'tester');
    }

    public function testDuplicateConfigRejected(): void
    {
        $file = $this->yaml('workload: {}');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('one --config');
        YamlConfiguration::expand(['--config=' . $file, '--config', $file], 'tester');
    }

    public function testMissingConfigValueRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a file path');
        YamlConfiguration::expand(['--config', '--help'], 'tester');
    }

    public function testUnreadableConfigRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot read configuration file');
        YamlConfiguration::expand(['--config=/nonexistent/tester.yaml'], 'tester');
    }

    public function testExistingCliStillRejectsConfigInEmbeddedOptionParser(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Options::parse(['--config=file.yaml'], ['steps'], []);
    }

    /** @return iterable<string, array{string, string}> */
    public static function helpConfigurations(): iterable
    {
        foreach (['tester', 'coercive', 'diff', 'compatibility'] as $binary) {
            yield $binary => [$binary, 'workload: {help: true}'];
        }
        yield 'sapi' => ['sapi', 'sapi: {help: true}'];
        yield 'harness' => ['harness', 'harness: {help: true}'];
    }

    #[DataProvider('helpConfigurations')]
    public function testBinariesHonorYamlHelpWithoutStartingWork(
        string $binary, string $yaml,
    ): void {
        // An invalid client also prevents a regressed compatibility runner
        // from provisioning Redis before this assertion can fail.
        if ($binary === 'compatibility') {
            $yaml = 'workload: {help: true, client: invalid}';
        }
        $file = $this->yaml($yaml);
        $log = $this->yaml('');
        $status = Process::run([
            PHP_BINARY, dirname(__DIR__, 2) . '/bin/' . $binary,
            '--config=' . $file,
        ], $log, 5);
        $this->files[] = $log . '.stdout.log';
        $this->files[] = $log . '.stderr.log';
        self::assertSame(0, $status);
        self::assertStringContainsString('Usage:',
            (string) file_get_contents($log . '.stdout.log'));
        self::assertSame('', file_get_contents($log . '.stderr.log'));
    }
}
