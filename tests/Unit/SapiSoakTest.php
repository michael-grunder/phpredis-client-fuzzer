<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Cli\ExitCode;
use Mgrunder\RedisClientTester\Harness\Fs;
use Mgrunder\RedisClientTester\Sapi\Request;
use Mgrunder\RedisClientTester\Sapi\Server;
use Mgrunder\RedisClientTester\Sapi\ServerDiscovery;
use Mgrunder\RedisClientTester\Sapi\SoakMonitor;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PhpTui\Tui\Display\Backend\DummyBackend;
use PhpTui\Tui\DisplayBuilder;

final class SapiSoakTest extends TestCase
{
    private string $directory = '';
    /** @var resource|null */
    private $server = null;
    private int $daemon = 0;

    protected function setUp(): void
    {
        try {
            Server::assertSupported();
        } catch (\RuntimeException $error) {
            self::markTestSkipped($error->getMessage());
        }
        $this->directory = sys_get_temp_dir() . '/sapi-soak-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0o700);
    }

    protected function tearDown(): void
    {
        if ($this->directory === '') {
            return;
        }
        if ($this->daemon > 0) {
            posix_kill($this->daemon, SIGTERM);
            $this->waitForFile($this->directory . '/server/exit.json');
        }
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        Server::stopOrphans($this->directory . '/server', true);
        Fs::removeTree($this->directory);
    }

    public function testMonitorTailsAppendsAndPartialLinesAfterEof(): void
    {
        $monitor = new SoakMonitor($this->directory, 2, false);
        ob_start();
        try {
            $monitor->render('client', true);
            $event = json_encode(['event' => 'start', 'path' => '/request.php', 'pid' => 12, 'started' => microtime(true)], JSON_THROW_ON_ERROR);
            file_put_contents($this->directory . '/requests.jsonl', $event, FILE_APPEND);
            $monitor->render('client', true);
            file_put_contents($this->directory . '/requests.jsonl', "\n", FILE_APPEND);
            $monitor->render('client', true);
            self::assertStringContainsString('requests=1 completed=0 in-flight=1', (string) ob_get_contents());
        } finally {
            ob_end_clean();
            $monitor->stop();
        }
    }

    public function testDashboardRendersRunningRequestsAndBoundedHistoryAtDifferentSizes(): void
    {
        $monitor = new SoakMonitor($this->directory, 2, false);
        $events = [];
        foreach (['old', 'failed', 'passed', 'running'] as $index => $name) {
            $event = ['path' => '/' . $name . '.php', 'pid' => 100 + $index, 'started' => microtime(true)];
            $events[] = $event + ['event' => 'start'];
            if ($name !== 'running') {
                $events[] = $event + ['event' => 'finish', 'elapsed' => 0.125,
                    'exit' => $name === 'failed' ? 1 : 0, 'status' => 200];
            }
        }
        file_put_contents($this->directory . '/requests.jsonl', implode("\n", array_map(
            static fn (array $event): string => json_encode($event, JSON_THROW_ON_ERROR), $events,
        )) . "\n", FILE_APPEND);
        $processes = [];
        foreach ([[99, 'parent', 'running', null, null], [103, 'worker', 'running', null, null],
            [104, 'worker', 'exited', null, 9], [105, 'worker', 'exited', 0, null],
            [106, 'worker', 'missing', null, null]] as [$pid, $role, $state, $exit, $signal]) {
            $processes[] = ['pid' => $pid, 'role' => $role, 'state' => $state, 'exit_code' => $exit,
                'signal' => $signal, 'wait_status' => null, 'core_dumped' => null, 'source' => '/proc/stat',
                'phase' => 'running', 'observed_at' => microtime(true)];
        }
        $pool = ['workers_alive' => 1, 'workers_expected' => 4,
            'parent_alive' => true, 'processes' => $processes, 'reason' => null];
        try {
            $backend = new DummyBackend(120, 20);
            (new \ReflectionProperty($monitor, 'display'))->setValue($monitor, DisplayBuilder::default($backend)->build());
            $monitor->render('a1b2c3d4e5f6', true, $pool);
            $screen = $backend->toString();
            file_put_contents($this->directory . '/dashboard.txt', $screen);
            foreach (['sapi', 'Uptime', 'Completed', 'In flight',
                'Status', 'PID', 'Time', 'Exit', 'HTTP', 'RUN', 'OK', 'FAIL', '0.125s', 'Workers', '1/4', 'Parent'] as $text) {
                self::assertStringContainsString($text, $screen);
            }
            self::assertStringContainsString('a1b2c3d4e5f6', $screen);
            self::assertStringNotContainsString($this->directory, $screen);
            self::assertStringNotContainsString('Client:', $screen);
            self::assertStringNotContainsString('old.php', $screen);
            self::assertMatchesRegularExpression('/running\.php.*passed\.php.*failed\.php/s', $screen);
            self::assertMatchesRegularExpression('/Uptime.* workers .* requests /s', $screen);
            self::assertMatchesRegularExpression('/99\s+Parent\s+Running\s+-\s+-/', $screen);
            self::assertMatchesRegularExpression('/103\s+Worker\s+Running\s+-\s+-/', $screen);
            self::assertMatchesRegularExpression('/104\s+Worker\s+Exited\s+-\s+9/', $screen);
            self::assertMatchesRegularExpression('/105\s+Worker\s+Exited\s+0\s+-/', $screen);
            self::assertMatchesRegularExpression('/106\s+Worker\s+Missing\s+-\s+-/', $screen);

            $small = new DummyBackend(60, 12);
            (new \ReflectionProperty($monitor, 'display'))->setValue($monitor, DisplayBuilder::default($small)->build());
            $monitor->render('a1b2c3d4e5f6', true, $pool);
            self::assertCount(12, $small->toLines());
            self::assertStringContainsString('sapi', $small->toString());
            foreach ($small->toLines() as $line) {
                self::assertSame(60, mb_strlen($line));
            }
        } finally {
            $monitor->stop();
        }
    }

    public function testTwoClientsReuseServerAndPreserveSeededWorkloadOptions(): void
    {
        $connection = $this->startServer();
        $before = file_get_contents($this->directory . '/server/pids.txt');
        $first = $this->client('first');
        $second = $this->client('second');
        self::assertSame(0, proc_close($first));
        self::assertSame(0, proc_close($second));
        self::assertSame($before, file_get_contents($this->directory . '/server/pids.txt'));
        $clients = glob($this->directory . '/server/clients/*') ?: [];
        self::assertCount(2, $clients);
        $seeds = [];
        foreach ($clients as $client) {
            $scripts = array_values(array_filter(glob($client . '/0/request-*.php') ?: [],
                static fn (string $path): bool => !str_ends_with($path, '.calls.php')));
            self::assertCount(3, $scripts);
            $seeds[] = array_map(basename(...), $scripts);
            foreach ($scripts as $script) {
                $reply = (string) file_get_contents($script . '.http');
                self::assertStringContainsString('cli-server:12', $reply);
                foreach (['--commands=get,set', '--weight=get:3', '--persistent', '--include-blocking'] as $option) {
                    self::assertStringContainsString($option, $reply);
                }
                self::assertFileExists(substr($script, 0, -4) . '.calls.php');
            }
        }
        self::assertSame($seeds[0], $seeds[1]);
        self::assertStringContainsString('seed-42', $seeds[0][0]);
        usleep(200000);
        self::assertCount(12, file($this->directory . '/server/requests.jsonl') ?: []);
        self::assertStringContainsString('END pid=', (string) file_get_contents($this->directory . '/server.out'));
        self::assertTrue(posix_kill($connection['pid'], 0));
        $this->stopServer();
    }

    public function testClientsDiscoverLatestAndExplicitIdAndRejectStoppedServer(): void
    {
        $this->startServer();
        $discovery = new ServerDiscovery($this->directory);
        $directory = $this->directory . '/server';
        $id = $discovery->id($directory);
        self::assertSame($directory, $discovery->resolve());
        self::assertSame($directory, $discovery->resolve($id));
        self::assertStringContainsString('SAPI ID: ' . $discovery->tag($directory), (string) file_get_contents($this->directory . '/server.out'));
        self::assertSame(0, proc_close($this->client('latest', '')));
        self::assertSame(0, proc_close($this->client('id', $id)));
        self::assertSame(0, proc_close($this->client('tag', $discovery->tag($directory))));
        $this->stopServer();
        self::assertSame(78, proc_close($this->client('stale', '')));
        self::assertStringContainsString('unavailable or stopped', (string) file_get_contents($this->directory . '/stale.err'));
    }

    public function testInfoAndListInspectMultipleServersWithoutSendingRequests(): void
    {
        $first = $this->startServer();
        $secondDirectory = $this->directory . '/second-server';
        $second = $this->launch('second-server', [dirname(__DIR__, 2) . '/bin/sapi', 'serve',
            '--directory=' . $secondDirectory, '--workers=2', '--no-tui', '--request-timeout=3']);
        try {
            $this->waitForFile($secondDirectory . '/server.ready');
            $discovery = new ServerDiscovery($this->directory);
            $tag = $discovery->tag($this->directory . '/server');
            foreach ([['info'], ['info', $tag], ['info', '--server=' . $discovery->id($this->directory . '/server')], ['list']] as $index => $args) {
                $process = $this->launch('inspect-' . $index, [dirname(__DIR__, 2) . '/bin/sapi', ...$args]);
                self::assertSame(0, proc_close($process));
                $output = (string) file_get_contents($this->directory . '/inspect-' . $index . '.out');
                self::assertStringContainsString('(running)', $output);
                self::assertStringContainsString('Client:', $output);
                self::assertStringContainsString('PHP arguments:', $output);
                self::assertStringNotContainsString($first['token'], $output);
                self::assertStringNotContainsString('token', $output);
                if ($index === 0 || $index === 3) {
                    self::assertStringContainsString($secondDirectory, $output);
                }
                if ($index !== 0) {
                    self::assertStringContainsString('Server: ' . $tag, $output);
                    self::assertStringContainsString('--server=' . $tag, $output);
                }
            }
            self::assertSame('', file_get_contents($this->directory . '/server/requests.jsonl'));
            self::assertSame('', file_get_contents($secondDirectory . '/requests.jsonl'));
            self::assertSame([], glob($this->directory . '/server/clients/*'));
            $this->stopServer();
            $process = $this->launch('list-after-stop', [dirname(__DIR__, 2) . '/bin/sapi', 'list']);
            self::assertSame(0, proc_close($process));
            $output = (string) file_get_contents($this->directory . '/list-after-stop.out');
            self::assertStringNotContainsString('Server: ' . $tag, $output);
            self::assertStringContainsString($secondDirectory, $output);
            $process = $this->launch('info-stopped', [dirname(__DIR__, 2) . '/bin/sapi', 'info', $tag]);
            self::assertSame(0, proc_close($process));
            self::assertStringContainsString('(stopped)', (string) file_get_contents($this->directory . '/info-stopped.out'));
        } finally {
            proc_terminate($second);
            proc_close($second);
            Server::stopOrphans($secondDirectory, true);
        }
    }

    public function testListIgnoresStalePointersWithAnUnrelatedLivePid(): void
    {
        $directory = $this->directory . '/stale';
        mkdir($directory);
        file_put_contents($directory . '/connection.json', json_encode(['pid' => getmypid(), 'address' => '127.0.0.1:1'], JSON_THROW_ON_ERROR));
        (new ServerDiscovery($this->directory))->publish($directory);
        $process = $this->launch('list-stale', [dirname(__DIR__, 2) . '/bin/sapi', 'list']);
        self::assertSame(0, proc_close($process));
        self::assertStringContainsString('No running SAPI servers', (string) file_get_contents($this->directory . '/list-stale.out'));
    }

    public function testRouterRejectsAuthenticationTraversalAndReplay(): void
    {
        $connection = $this->startServer();
        $script = $this->fixtureScript('<?php header("X-Fuzzer-Exit: 0"); echo "ok";');
        foreach (['router.php', '../connection.json', $script . '?steps=1000'] as $path) {
            self::assertStringContainsString('404', $this->raw($connection, $path));
        }
        $unauthorized = $this->raw($connection, $script, false);
        self::assertStringContainsString('403', $unauthorized);
        self::assertStringNotContainsString($connection['token'], $unauthorized);
        self::assertSame(0, (new Request($connection['address'], $connection['token'], 2))
            ->run($script, $this->directory . '/success.http', static fn (): int => 0, false));
        self::assertStringContainsString('409', $this->raw($connection, $script));
        $this->stopServer();
    }

    public function testTimeoutRetainsPartialReplyAndDoesNotStopServer(): void
    {
        $connection = $this->startServer();
        $script = $this->fixtureScript('<?php header("X-Fuzzer-Exit: 0"); while (ob_get_level() > 0) { ob_end_flush(); } echo "partial"; flush(); usleep(800000);');
        try {
            (new Request($connection['address'], $connection['token'], 0.2))
                ->run($script, $this->directory . '/timeout.http', static fn (): int => 0, false);
            self::fail('expected HTTP timeout');
        } catch (\RuntimeException $error) {
            self::assertSame(ExitCode::TIMEOUT, $error->getCode());
        }
        self::assertStringContainsString('partial', (string) file_get_contents($this->directory . '/timeout.http'));
        self::assertTrue(posix_kill($connection['pid'], 0));
        $this->stopServer();
    }

    public function testDaemonPublishesConnectionAndStopsWorkersOnTerm(): void
    {
        $connection = $this->startServer(true, ['--json']);
        self::assertFileExists($this->directory . '/server/supervisor.log');
        self::assertSame($this->directory . '/server', (new ServerDiscovery($this->directory))->resolve());
        $this->daemon = $connection['pid'];
        self::assertSame(0, proc_close($this->client('daemon')));
        posix_kill($this->daemon, SIGTERM);
        $this->waitForFile($this->directory . '/server/exit.json');
        $this->daemon = 0;
        self::assertSame(143, $this->exitReport()['exit']);
        $launch = json_decode((string) file_get_contents($this->directory . '/server.out'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($launch);
        self::assertSame('daemon', $launch['type']);
        foreach (file($this->directory . '/server/supervisor.log', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            self::assertIsArray(json_decode($line, true, 512, JSON_THROW_ON_ERROR));
        }
        self::assertFileExists($this->directory . '/server/supervisor.stderr.log');
        $this->assertWorkersStopped();
    }

    public function testWorkerDeathsLeaveParentServingByDefault(): void
    {
        $connection = $this->startServer(extra: ['--json']);
        $pids = file($this->directory . '/server/pids.txt', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($pids);
        self::assertCount(3, $pids);
        foreach ([1, 2] as $index) {
            self::assertTrue(posix_kill((int) $pids[$index], SIGKILL));
            $deadline = microtime(true) + 5;
            $observed = false;
            do {
                foreach (file($this->directory . '/server.out', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    $event = json_decode($line, true);
                    if (is_array($event) && ($event['type'] ?? '') === 'status' && is_array($event['pool'])
                        && $event['pool']['workers_alive'] === 2 - $index && $event['pool']['parent_alive'] === true) {
                        $observed = true;
                    }
                }
                if (!$observed) {
                    usleep(10000);
                }
            } while (!$observed && microtime(true) < $deadline);
            self::assertTrue($observed, 'supervisor must keep reporting after a worker exits');
            self::assertFileDoesNotExist($this->directory . '/server/exit.json');
            self::assertStringContainsString('ready', $this->raw($connection, 'ready'));
        }
        self::assertTrue(posix_kill((int) $pids[0], SIGKILL));
        $this->waitForFile($this->directory . '/server/exit.json');
        self::assertIsResource($this->server);
        self::assertSame(137, proc_close($this->server));
        $this->server = null;
        $report = $this->exitReport();
        self::assertIsArray($report['pool']);
        self::assertSame('parent PID ' . $pids[0] . ': signal 9; stopping the remaining pool', $report['pool']['reason']);
        $this->assertDiagnosticGuidance();
        $this->assertWorkersStopped();
    }

    public function testIdleWorkerDeathStopsSupervisorWhenOptedIn(): void
    {
        $this->startServer(extra: ['--stop-on-worker-exit']);
        $pids = file($this->directory . '/server/pids.txt', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($pids);
        self::assertGreaterThanOrEqual(3, count($pids));
        posix_kill((int) $pids[1], SIGKILL);
        $this->waitForFile($this->directory . '/server/exit.json');
        self::assertIsResource($this->server);
        self::assertSame(137, proc_close($this->server));
        $this->server = null;
        $report = $this->exitReport();
        self::assertIsArray($report['pool']);
        self::assertIsString($report['pool']['reason']);
        self::assertStringContainsString('signal 9', $report['pool']['reason']);
        self::assertIsArray($report['before_shutdown']);
        self::assertSame(1, $report['before_shutdown']['workers_alive']);
        self::assertStringContainsString('SAPI stopped: exit 137', (string) file_get_contents($this->directory . '/server.err'));
        self::assertStringContainsString('signal=9', (string) file_get_contents($this->directory . '/server.err'));
        $this->assertDiagnosticGuidance();
        $this->assertWorkersStopped();
    }

    /** @return iterable<string, array{int}> */
    public static function workerExits(): iterable
    {
        yield 'clean exit is still unexpected' => [0];
        yield 'nonzero exit' => [23];
    }

    #[DataProvider('workerExits')]
    public function testJsonReportsNormalWorkerExitAndCleanupSeparately(int $exit): void
    {
        $this->startServer(extra: ['--json', '--stop-on-worker-exit', '--server-prefix=' . PHP_BINARY,
            '--server-prefix=' . dirname(__DIR__) . '/Fixtures/sapi-exit-wrapper.php',
            '--server-prefix=' . $exit, '--server-prefix=' . $this->directory . '/server']);
        $this->waitForFile($this->directory . '/server/exit.json');
        self::assertIsResource($this->server);
        self::assertSame(max(1, $exit), proc_close($this->server));
        $this->server = null;
        $report = $this->exitReport();
        self::assertIsArray($report['pool']);
        self::assertIsString($report['pool']['reason']);
        self::assertStringContainsString('exit code ' . $exit, $report['pool']['reason']);
        self::assertSame(0, $report['pool']['workers_alive']);
        self::assertFalse($report['pool']['parent_alive']);
        self::assertIsArray($report['pool']['processes']);
        $unexpected = [];
        foreach ($report['pool']['processes'] as $process) {
            self::assertIsArray($process);
            if ($process['phase'] === 'running') {
                $unexpected[] = $process;
            }
        }
        self::assertCount(1, $unexpected);
        self::assertSame($exit, $unexpected[0]['exit_code']);
        self::assertNull($unexpected[0]['signal']);
        self::assertSame($exit << 8, $unexpected[0]['wait_status']);
        self::assertFalse($unexpected[0]['core_dumped']);
        self::assertIsString($report['stderr_tail']);
        self::assertStringContainsString('fixture descendant exiting normally', $report['stderr_tail']);
        $lines = file($this->directory . '/server.out', FILE_IGNORE_NEW_LINES) ?: [];
        $types = [];
        foreach ($lines as $line) {
            $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($event);
            $types[] = $event['type'];
        }
        self::assertContains('ready', $types);
        self::assertContains('status', $types);
        self::assertSame('exit', end($types));
        self::assertStringContainsString('shutdown_signal', (string) file_get_contents($this->directory . '/server/processes.jsonl'));
        $this->assertWorkersStopped();
    }

    private function assertDiagnosticGuidance(): void
    {
        $output = (string) file_get_contents($this->directory . '/server.err');
        self::assertStringContainsString('Diagnostics retained in ' . $this->directory . '/server', $output);
        foreach (['exit.json', 'processes.jsonl', 'server.stderr.log', 'server.stdout.log', 'requests.jsonl'] as $file) {
            self::assertStringContainsString($this->directory . '/server/' . $file, $output);
        }
    }

    /** @return array<array-key, mixed> */
    private function exitReport(): array
    {
        $report = json_decode((string) file_get_contents($this->directory . '/server/exit.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertArrayHasKey('exit', $report);
        return $report;
    }

    public function testClientWithoutArgumentsLooksForLatest(): void
    {
        $process = $this->launch('missing-latest', [dirname(__DIR__, 2) . '/bin/sapi', 'client']);
        self::assertSame(78, proc_close($process));
        self::assertStringContainsString('no SAPI server pointer', (string) file_get_contents($this->directory . '/missing-latest.err'));
    }

    public function testExistingDirectoryIsNeverModified(): void
    {
        file_put_contents($this->directory . '/evidence', 'keep');
        $process = $this->launch('existing', [dirname(__DIR__, 2) . '/bin/sapi', 'serve', '--directory=' . $this->directory]);
        self::assertSame(78, proc_close($process));
        self::assertSame('keep', file_get_contents($this->directory . '/evidence'));
        self::assertFileDoesNotExist($this->directory . '/exit.json');
    }

    /** @param list<string> $extra
     * @return array{address: string, token: string, pid: int}
     */
    private function startServer(bool $daemon = false, array $extra = []): array
    {
        $process = $this->launch('server', [dirname(__DIR__, 2) . '/bin/sapi', 'serve',
            '--directory=' . $this->directory . '/server', '--workers=2', '--no-tui',
            '--php-arg=-dprecision=12', '--request-timeout=3', ...($daemon ? ['--daemon'] : []), ...$extra]);
        if ($daemon) {
            self::assertSame(0, proc_close($process));
        } else {
            $this->server = $process;
        }
        $this->waitForFile($this->directory . '/server/server.ready');
        $data = json_decode((string) file_get_contents($this->directory . '/server/connection.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsString($data['address']);
        self::assertIsString($data['token']);
        self::assertIsInt($data['pid']);
        return ['address' => $data['address'], 'token' => $data['token'], 'pid' => $data['pid']];
    }

    /** @return resource */
    private function client(string $name, ?string $selector = null)
    {
        $autoload = $this->directory . '/' . $name . '-autoload.php';
        file_put_contents($autoload, <<<'SOURCE'
<?php
namespace Mgrunder\RedisClientTester\Cli;
class Application {
    public function __construct($output, $error) {}
    public function run(array $arguments): int {
        foreach ($arguments as $arg) {
            if (str_starts_with($arg, '--script-log=')) {
                file_put_contents(substr($arg, 13), '<?php // fixture calls');
            }
        }
        usleep(150000);
        echo PHP_SAPI . ':' . ini_get('precision') . ':' . json_encode($arguments);
        return 0;
    }
}
SOURCE);
        return $this->launch($name, [dirname(__DIR__) . '/Fixtures/sapi-soak-client.php', $autoload,
            ...($selector === null ? ['--directory=' . $this->directory . '/server']
                : ($selector === '' ? [] : ['--server=' . $selector])),
            '--requests=3', '--idle-ms=0', '--',
            '--seed=42', '--commands=get,set', '--weight=get:3', '--include-blocking']);
    }

    private function fixtureScript(string $source): string
    {
        $path = 'clients/client-abcd/0/request-1-seed-42.php';
        mkdir(dirname($this->directory . '/server/' . $path), 0o700, true);
        file_put_contents($this->directory . '/server/' . $path, $source);
        return $path;
    }

    /** @param array{address: string, token: string, pid: int} $connection */
    private function raw(array $connection, string $path, bool $authenticate = true): string
    {
        $socket = stream_socket_client('tcp://' . $connection['address']);
        self::assertIsResource($socket);
        stream_set_timeout($socket, 2);
        fwrite($socket, "GET /{$path} HTTP/1.0\r\nX-Fuzzer-Token: " . ($authenticate ? $connection['token'] : 'bad') . "\r\n\r\n");
        $reply = stream_get_contents($socket);
        fclose($socket);
        self::assertIsString($reply);
        return $reply;
    }

    /** @param list<string> $arguments
     *  @return resource
     */
    private function launch(string $name, array $arguments)
    {
        $process = proc_open([PHP_BINARY, ...$arguments], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->directory . '/' . $name . '.out', 'w'],
            2 => ['file', $this->directory . '/' . $name . '.err', 'w'],
        ], $pipes, null, array_merge(getenv(), ['TMPDIR' => $this->directory]));
        self::assertIsResource($process);
        return $process;
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 8;
        do {
            clearstatcache();
            if (is_file($path)) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::fail('missing file: ' . $path . '; see ' . $this->directory);
    }

    private function stopServer(): void
    {
        self::assertIsResource($this->server);
        proc_terminate($this->server, SIGTERM);
        self::assertSame(143, proc_close($this->server));
        $this->server = null;
        $this->assertWorkersStopped();
    }

    private function assertWorkersStopped(): void
    {
        foreach (file($this->directory . '/server/pids.txt', FILE_IGNORE_NEW_LINES) ?: [] as $pid) {
            $stat = @file_get_contents('/proc/' . $pid . '/stat');
            if ($stat !== false) {
                self::assertStringContainsString(') Z ', $stat, 'live worker: ' . $pid);
            }
        }
    }
}
