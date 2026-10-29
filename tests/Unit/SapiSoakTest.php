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
            $event = json_encode(['event' => 'start', 'path' => '/request.php', 'pid' => 12], JSON_THROW_ON_ERROR);
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
        self::assertStringContainsString('SAPI ID: ' . $id, (string) file_get_contents($this->directory . '/server.out'));
        self::assertSame(0, proc_close($this->client('latest', '')));
        self::assertSame(0, proc_close($this->client('id', $id)));
        $this->stopServer();
        self::assertSame(78, proc_close($this->client('stale', '')));
        self::assertStringContainsString('unavailable or stopped', (string) file_get_contents($this->directory . '/stale.err'));
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
        $connection = $this->startServer(true);
        self::assertFileExists($this->directory . '/server/supervisor.log');
        self::assertSame($this->directory . '/server', (new ServerDiscovery($this->directory))->resolve());
        $this->daemon = $connection['pid'];
        self::assertSame(0, proc_close($this->client('daemon')));
        posix_kill($this->daemon, SIGTERM);
        $this->waitForFile($this->directory . '/server/exit.json');
        $this->daemon = 0;
        self::assertStringContainsString('"exit":143', (string) file_get_contents($this->directory . '/server/exit.json'));
        $this->assertWorkersStopped();
    }

    public function testIdleWorkerDeathStopsSupervisor(): void
    {
        $this->startServer();
        $pids = file($this->directory . '/server/pids.txt', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($pids);
        self::assertGreaterThanOrEqual(3, count($pids));
        posix_kill((int) $pids[1], SIGKILL);
        $this->waitForFile($this->directory . '/server/exit.json');
        self::assertIsResource($this->server);
        self::assertSame(137, proc_close($this->server));
        $this->server = null;
        $this->assertWorkersStopped();
    }

    public function testClientWithoutArgumentsLooksForLatest(): void
    {
        $process = $this->launch('missing-latest', [dirname(__DIR__, 2) . '/bin/redis-client-tester-sapi', 'client']);
        self::assertSame(78, proc_close($process));
        self::assertStringContainsString('no SAPI server pointer', (string) file_get_contents($this->directory . '/missing-latest.err'));
    }

    public function testExistingDirectoryIsNeverModified(): void
    {
        file_put_contents($this->directory . '/evidence', 'keep');
        $process = $this->launch('existing', [dirname(__DIR__, 2) . '/bin/redis-client-tester-sapi', 'serve', '--directory=' . $this->directory]);
        self::assertSame(78, proc_close($process));
        self::assertSame('keep', file_get_contents($this->directory . '/evidence'));
        self::assertFileDoesNotExist($this->directory . '/exit.json');
    }

    /** @return array{address: string, token: string, pid: int} */
    private function startServer(bool $daemon = false): array
    {
        $process = $this->launch('server', [dirname(__DIR__, 2) . '/bin/redis-client-tester-sapi', 'serve',
            '--directory=' . $this->directory . '/server', '--workers=2', '--no-tui',
            '--php-arg=-dprecision=12', '--request-timeout=3', ...($daemon ? ['--daemon'] : [])]);
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
