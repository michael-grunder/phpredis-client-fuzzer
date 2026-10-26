<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Cli;

use Mgrunder\RedisClientTester\Harness\PhpStartupTemplate;
use Mgrunder\RedisClientTester\Sapi\Server;
use Random\Engine\Mt19937;
use Random\Randomizer;

/** Runs the existing workload application inside real cli-server requests. */
final class SapiApplication
{
    public function __construct(private readonly string $autoload)
    {
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        $started = false;
        try {
            $separator = array_search('--', $arguments, true);
            $options = Options::parse(
                $separator === false ? $arguments : array_slice($arguments, 0, $separator),
                ['workers', 'concurrency', 'requests', 'idle-ms', 'request-timeout', 'directory', 'php-arg', 'php-ini', 'server-prefix'],
                ['help'],
                ['php-arg', 'server-prefix'],
            );
            if ($options->has('help')) {
                echo self::HELP;
                return 0;
            }
            if ($separator === false) {
                throw new \InvalidArgumentException('separate server options from workload options with --');
            }
            $workload = array_slice($arguments, $separator + 1);
            $fuzzer = Application::parseOptions($workload);
            foreach (['forks', 'script-log', 'help'] as $name) {
                if ($fuzzer->has($name)) {
                    throw new \InvalidArgumentException("--{$name} is managed by the SAPI runner or unavailable in HTTP requests");
                }
            }
            $workers = $options->integer('workers', 4);
            $concurrency = $options->integer('concurrency', 2);
            $requests = $options->integer('requests', 20);
            $idle = $options->integer('idle-ms', 100);
            $timeout = $options->number('request-timeout', 30.0);
            if ($workers < 2 || $workers > 128 || $concurrency < 1 || $concurrency > $workers
                || $requests < 1 || $requests > 100000 || $idle < 0 || $idle > 60000
                || !is_finite($timeout) || $timeout <= 0) {
                throw new \InvalidArgumentException('require workers 2..128, concurrency 1..workers, requests 1..100000, idle-ms 0..60000 and a positive finite request-timeout');
            }
            Server::assertSupported();
            $seed = $fuzzer->optionalInteger('seed') ?? random_int(0, mt_getrandmax());
            $root = getenv('REDIS_CLIENT_TESTER_WORK_DIR');
            if ($root !== false && $options->has('directory')) {
                throw new \InvalidArgumentException('--directory is owned by the harness');
            }
            $directory = $options->string('directory', $root === false
                ? sys_get_temp_dir() . '/redis-client-tester-sapi-' . getmypid() . '-' . $seed
                : $root . '/sapi');
            // Claim a fresh directory so scripts and diagnostics are never overwritten.
            if (!@mkdir($directory, 0o700, true)) {
                throw new \RuntimeException("cannot create fresh run directory: {$directory}");
            }
            $directory = realpath($directory);
            if ($directory === false) {
                throw new \RuntimeException('cannot resolve run directory');
            }
            $phpArgs = $options->repeated('php-arg', splitCommas: false);
            $inherited = getenv('REDIS_CLIENT_TESTER_PHP_ARGS');
            if ($inherited !== false) {
                $decoded = json_decode($inherited, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded) || !array_is_list($decoded)) {
                    throw new \InvalidArgumentException('invalid inherited PHP arguments');
                }
                foreach ($decoded as $arg) {
                    if (!is_string($arg)) {
                        throw new \InvalidArgumentException('invalid inherited PHP argument');
                    }
                }
                $phpArgs = [...$decoded, ...$phpArgs];
            }
            $startup = (new PhpStartupTemplate($phpArgs, $options->nullableString('php-ini')))->render($seed, $directory);
            $random = new Randomizer(new Mt19937($seed));
            $paths = [];
            $seeds = [];
            for ($index = 0; $index < $requests; $index++) {
                $requestSeed = $index === 0 ? $seed : $random->getInt(0, mt_getrandmax());
                $name = sprintf('request-%05d-seed-%d', $index + 1, $requestSeed);
                $paths[] = $name . '.php';
                $seeds[] = $requestSeed;
                $args = [...$workload, '--persistent', '--seed=' . $requestSeed];
                $source = "<?php\ndeclare(strict_types=1);\nrequire " . var_export(realpath($this->autoload), true) . ";\n"
                    . '$arguments = ' . var_export($args, true) . ";\n"
                    . '$arguments[] = "--script-log=" . __DIR__ . ' . var_export('/' . $name . '.calls.php', true) . ";\n"
                    . '$output = fopen("php://output", "w");' . "\n"
                    . '$error = fopen("php://stderr", "w");' . "\n"
                    . "ob_start();\n"
                    . '$code = (new \\Mgrunder\\RedisClientTester\\Cli\\Application($output, $error))->run($arguments);' . "\n"
                    . 'header("X-Fuzzer-Exit: " . $code);' . "\n"
                    . "ob_end_flush();\n";
                Server::write($directory . '/' . $name . '.php', $source);
            }
            Server::write($directory . '/workload.json', json_encode([
                'seed' => $seed, 'request_seeds' => $seeds, 'arguments' => $workload,
                'workers' => $workers, 'concurrency' => $concurrency, 'idle_ms' => $idle,
                'request_timeout' => $timeout, 'php' => PHP_BINARY, 'php_args' => $startup['args'],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
            fwrite(STDERR, "SAPI artifacts: {$directory}\n");
            $started = true;
            return (new Server($directory, $workers, $concurrency, $idle, $timeout))->run(
                $paths, $startup['args'], $options->repeated('server-prefix', splitCommas: false),
            );
        } catch (\Throwable $error) {
            fwrite(STDERR, 'redis-client-tester-sapi: ' . $error->getMessage() . "\n");
            return $started ? ExitCode::FAILURE : ExitCode::STARTUP;
        }
    }

    private const HELP = <<<'HELP'
redis-client-tester-sapi - fuzz through persistent PHP built-in server workers

Usage: redis-client-tester-sapi [server options] -- [redis-client-tester options]

  --workers N            PHP_CLI_SERVER_WORKERS (2..128, default: 4)
  --concurrency N        Requests per batch (1..workers, default: 2)
  --requests N           Total requests per server lifetime (default: 20)
  --idle-ms N            Idle interval between batches and after the last (100)
  --request-timeout N    Readiness / batch / shutdown deadline in seconds (30)
  --directory PATH       Fresh artifact directory (default: private temp directory)
  --php-arg ARG          One PHP startup token; repeat for multiple tokens
  --php-ini FILE         Server PHP ini file
  --server-prefix ARG    One wrapper token; e.g. valgrind, then --trace-children=yes
  --help                 Show this help

Linux, /proc, setsid, pcntl and posix are required. HTTP binds only 127.0.0.1
on an automatically selected port. Workload options, including --seed, --steps,
--commands and --weight, follow --. Persistent connections are enabled for all
four clients. --forks and --script-log are owned by this runner.

Each request gets a deterministic script, seed, concrete call log and HTTP
response. Workers survive requests and idle between batches. Scheduling and
worker assignment are intentionally nondeterministic. All standalone artifacts
are retained. The harness supplies its work directory and PHP startup arguments;
rr / ASAN wrap the whole process tree. Include failures and timeouts in the
harness --capture list to retain HTTP failures and request timeouts.

Example (only against your disposable Redis target):
  redis-client-tester-sapi --workers 4 --requests 30 -- \
    --client=relay --port=6390 --commands=get,set,del --steps=100 --seed=42

HELP;
}
