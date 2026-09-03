<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Cli;

/**
 * Repeatedly disrupts a running fuzzer workload by signalling the fuzzer
 * workers, by running `CLIENT KILL` against a Redis target, or both.
 *
 * @internal This class exists to keep the Composer binary small and testable.
 */
final class FuzzKillerApplication
{
    private const VALUE_OPTIONS = [
        'signals',
        'sleep',
        'rate',
        'mode',
        'host',
        'port',
        'auth',
        'process-rate',
        'client-rate',
    ];
    private const FLAG_OPTIONS = ['help'];

    private const MODES = ['process', 'client', 'both'];

    private const CONNECT_TIMEOUT = 1.0;

    /** @var array<string, int> */
    private const LINUX_SIGNALS = [
        'SIGHUP' => 1,
        'SIGINT' => 2,
        'SIGQUIT' => 3,
        'SIGILL' => 4,
        'SIGTRAP' => 5,
        'SIGABRT' => 6,
        'SIGIOT' => 6,
        'SIGBUS' => 7,
        'SIGFPE' => 8,
        'SIGKILL' => 9,
        'SIGUSR1' => 10,
        'SIGSEGV' => 11,
        'SIGUSR2' => 12,
        'SIGPIPE' => 13,
        'SIGALRM' => 14,
        'SIGTERM' => 15,
        'SIGSTKFLT' => 16,
        'SIGCHLD' => 17,
        'SIGCLD' => 17,
        'SIGCONT' => 18,
        'SIGSTOP' => 19,
        'SIGTSTP' => 20,
        'SIGTTIN' => 21,
        'SIGTTOU' => 22,
        'SIGURG' => 23,
        'SIGXCPU' => 24,
        'SIGXFSZ' => 25,
        'SIGVTALRM' => 26,
        'SIGPROF' => 27,
        'SIGWINCH' => 28,
        'SIGIO' => 29,
        'SIGPOLL' => 29,
        'SIGPWR' => 30,
        'SIGSYS' => 31,
        'SIGRTMIN' => 34,
        'SIGRTMAX' => 64,
    ];

    /** @var resource */
    private $output;

    /** @var resource */
    private $error;

    /** @var \Closure(): list<int> */
    private readonly \Closure $pidFinder;

    /** @var \Closure(int, int): bool */
    private readonly \Closure $signalSender;

    /** @var \Closure(int): void */
    private readonly \Closure $sleeper;

    /** @var \Closure(int, int): int */
    private readonly \Closure $randomInteger;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @var \Closure(string): list<string> */
    private readonly \Closure $nodeResolver;

    /** @var \Closure(string): list<int> */
    private readonly \Closure $clientLister;

    /** @var \Closure(string, list<int>): list<int> */
    private readonly \Closure $clientKiller;

    private readonly bool $usesDefaultPidFinder;
    private readonly bool $usesDefaultSignalSender;
    private readonly bool $usesDefaultRedis;

    /** @var string|array{0: string, 1: string}|null */
    private string|array|null $auth = null;

    /**
     * The callable arguments and iteration limit are test seams; the binary
     * uses the defaults and therefore runs until an external signal stops it.
     *
     * @param resource|null $output Defaults to STDOUT.
     * @param resource|null $error Defaults to STDERR.
     * @param (callable(): list<int>)|null $pidFinder
     * @param (callable(int, int): bool)|null $signalSender
     * @param (callable(int): void)|null $sleeper
     * @param (callable(int, int): int)|null $randomInteger
     * @param (callable(): float)|null $clock Monotonic seconds.
     * @param (callable(string): list<string>)|null $nodeResolver Seed host:port to every target host:port.
     * @param (callable(string): list<int>)|null $clientLister host:port to killable client ids.
     * @param (callable(string, list<int>): list<int>)|null $clientKiller host:port plus ids to the ids that survived.
     */
    public function __construct(
        $output = null,
        $error = null,
        ?callable $pidFinder = null,
        ?callable $signalSender = null,
        ?callable $sleeper = null,
        ?callable $randomInteger = null,
        ?callable $clock = null,
        ?callable $nodeResolver = null,
        ?callable $clientLister = null,
        ?callable $clientKiller = null,
        private readonly ?int $iterationLimit = null,
    ) {
        $this->output = $output ?? STDOUT;
        $this->error = $error ?? STDERR;
        $this->usesDefaultPidFinder = $pidFinder === null;
        $this->usesDefaultSignalSender = $signalSender === null;
        $this->usesDefaultRedis = $nodeResolver === null || $clientLister === null || $clientKiller === null;
        $this->pidFinder = $pidFinder === null
            ? $this->findFuzzerPids(...)
            : \Closure::fromCallable($pidFinder);
        $this->signalSender = $signalSender === null
            ? static fn (int $pid, int $signal): bool => posix_kill($pid, $signal)
            : \Closure::fromCallable($signalSender);
        $this->sleeper = $sleeper === null
            ? static function (int $microseconds): void {
                usleep($microseconds);
            }
            : \Closure::fromCallable($sleeper);
        $this->randomInteger = $randomInteger === null
            ? static fn (int $minimum, int $maximum): int => random_int($minimum, $maximum)
            : \Closure::fromCallable($randomInteger);
        $this->clock = $clock === null
            ? static fn (): float => hrtime(true) / 1_000_000_000
            : \Closure::fromCallable($clock);
        $this->nodeResolver = $nodeResolver === null
            ? $this->discoverNodes(...)
            : \Closure::fromCallable($nodeResolver);
        $this->clientLister = $clientLister === null
            ? $this->listClientIds(...)
            : \Closure::fromCallable($clientLister);
        $this->clientKiller = $clientKiller === null
            ? $this->killClients(...)
            : \Closure::fromCallable($clientKiller);
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        // Flipped once the command line has been fully validated, so a
        // rejected invocation can exit with ExitCode::STARTUP.
        $configured = false;

        try {
            $options = Options::parse($arguments, self::VALUE_OPTIONS, self::FLAG_OPTIONS);

            if ($options->has('help')) {
                $this->write(self::HELP);
                return 0;
            }

            $mode = strtolower($options->string('mode', 'process'));
            if (!in_array($mode, self::MODES, true)) {
                throw new \InvalidArgumentException('--mode must be one of: ' . implode(', ', self::MODES));
            }
            $signalProcesses = $mode === 'process' || $mode === 'both';
            $killClients = $mode === 'client' || $mode === 'both';

            $signals = $this->signals($options->string('signals', 'SIGINT,SIGTERM,SIGQUIT'));
            [$minimumSleep, $maximumSleep] = $this->sleepRange($options->string('sleep', '0.8-1.2'));

            $rate = $options->number('rate', 100.0);
            $this->assertRate($rate, '--rate');
            $processRate = $options->has('process-rate') ? $options->number('process-rate', 0.0) : $rate;
            $clientRate = $options->has('client-rate') ? $options->number('client-rate', 0.0) : $rate;
            $this->assertRate($processRate, '--process-rate');
            $this->assertRate($clientRate, '--client-rate');

            $this->auth = $this->credentials($options->nullableString('auth'));

            $this->checkCapabilities($signalProcesses, $killClients);

            $host = $options->string('host', '127.0.0.1');
            $port = $options->integer('port', 6379);
            if ($killClients && ($port < 1 || $port > 65535)) {
                throw new \InvalidArgumentException('--port must be between 1 and 65535');
            }

            // Everything the command line asked for is valid; contacting the
            // target and signalling workers is runtime work.
            $configured = true;

            $nodes = [];
            if ($killClients) {
                $nodes = ($this->nodeResolver)($this->formatAddress($host, $port));
                $this->reportNodes($nodes);
            }

            $started = ($this->clock)();
            $iterations = 0;

            while (true) {
                if ($signalProcesses) {
                    $this->signalProcesses($started, $signals, $processRate);
                }
                if ($killClients) {
                    $this->killRedisClients($started, $nodes, $clientRate);
                }

                $sleep = ($this->randomInteger)($minimumSleep, $maximumSleep);
                $this->log($started, "Sleeping for {$sleep}us");
                ($this->sleeper)($sleep);

                $iterations++;
                if ($this->iterationLimit !== null && $iterations >= $this->iterationLimit) {
                    return 0;
                }
            }
        } catch (\Throwable $throwable) {
            $this->write('phpredis-fuzz-killer: ' . $throwable->getMessage() . "\n", true);
            return $configured ? ExitCode::FAILURE : ExitCode::STARTUP;
        }
    }

    /**
     * @param non-empty-list<array{name: string, number: int}> $signals
     */
    private function signalProcesses(float $started, array $signals, float $rate): void
    {
        $pids = ($this->pidFinder)();
        if ($pids === []) {
            $this->log($started, 'No phpredis-fuzz processes found');
        }

        foreach ($pids as $pid) {
            if (!$this->shouldRoll($rate)) {
                $this->log($started, "Not signaling {$pid} (rate roll)");
                continue;
            }

            $signal = $signals[($this->randomInteger)(0, count($signals) - 1)];
            $this->log($started, "Sending {$signal['name']} to {$pid}");
            if (!(($this->signalSender)($pid, $signal['number']))) {
                $this->logSignalFailure($started, $signal['name'], $pid);
            }
        }
    }

    /**
     * @param list<string> $nodes
     */
    private function killRedisClients(float $started, array $nodes, float $rate): void
    {
        if ($nodes === []) {
            $this->log($started, 'No Redis nodes available for CLIENT KILL');
            return;
        }

        $node = $nodes[($this->randomInteger)(0, count($nodes) - 1)];

        try {
            $ids = ($this->clientLister)($node);
        } catch (\Throwable $throwable) {
            $this->log($started, "Failed to list clients on {$node}: {$throwable->getMessage()}", true);
            return;
        }

        if ($ids === []) {
            $this->log($started, "No killable clients on {$node}");
            return;
        }

        $targets = [];
        foreach ($ids as $id) {
            if (!$this->shouldRoll($rate)) {
                $this->log($started, "Not killing client {$id} on {$node} (rate roll)");
                continue;
            }
            $this->log($started, "Killing client {$id} on {$node}");
            $targets[] = $id;
        }

        if ($targets === []) {
            return;
        }

        try {
            $failed = ($this->clientKiller)($node, $targets);
        } catch (\Throwable $throwable) {
            $this->log($started, "Failed to kill clients on {$node}: {$throwable->getMessage()}", true);
            return;
        }

        foreach ($failed as $id) {
            $this->log($started, "Failed to kill client {$id} on {$node}", true);
        }
    }

    /**
     * @return non-empty-list<array{name: string, number: int}>
     */
    private function signals(string $value): array
    {
        $signals = [];
        $numbers = [];
        foreach (Options::split($value) as $name) {
            $name = strtoupper($name);
            if (!str_starts_with($name, 'SIG')) {
                $name = 'SIG' . $name;
            }

            $number = self::LINUX_SIGNALS[$name] ?? null;
            if ($number === null) {
                throw new \InvalidArgumentException("Unknown Linux signal: {$name}");
            }
            if (isset($numbers[$number])) {
                continue;
            }

            $numbers[$number] = true;
            $signals[] = ['name' => $name, 'number' => $number];
        }

        if ($signals === []) {
            throw new \InvalidArgumentException('--signals cannot be empty');
        }

        return $signals;
    }

    /** @return array{int, int} */
    private function sleepRange(string $value): array
    {
        $number = '(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)';
        if (preg_match("/^({$number})-({$number})$/", trim($value), $matches) !== 1) {
            throw new \InvalidArgumentException('--sleep must be a MIN-MAX range in seconds');
        }

        $minimum = (float) $matches[1];
        $maximum = (float) $matches[2];
        if ($minimum > $maximum) {
            throw new \InvalidArgumentException('--sleep minimum must not exceed maximum');
        }
        if ($maximum > PHP_INT_MAX / 1_000_000) {
            throw new \InvalidArgumentException('--sleep range is too large');
        }

        return [(int) round($minimum * 1_000_000), (int) round($maximum * 1_000_000)];
    }

    private function assertRate(float $rate, string $option): void
    {
        if ($rate < 0.0 || $rate > 100.0) {
            throw new \InvalidArgumentException("{$option} must be between 0.0 and 100.0");
        }
    }

    private function shouldRoll(float $rate): bool
    {
        if ($rate <= 0.0) {
            return false;
        }
        if ($rate >= 100.0) {
            return true;
        }

        return ($this->randomInteger)(1, 1_000_000) <= $rate * 10_000;
    }

    private function checkCapabilities(bool $needsProcesses, bool $needsClients): void
    {
        if ($needsProcesses && $this->usesDefaultPidFinder && (PHP_OS_FAMILY !== 'Linux' || !is_dir('/proc'))) {
            throw new \RuntimeException('process discovery requires Linux /proc');
        }
        if ($needsProcesses && $this->usesDefaultSignalSender && !function_exists('posix_kill')) {
            throw new \RuntimeException('sending signals requires the PHP POSIX extension');
        }
        if ($needsClients && $this->usesDefaultRedis && !class_exists('Redis')) {
            throw new \RuntimeException('killing Redis clients requires the PHP redis extension');
        }
    }

    /**
     * @return string|array{0: string, 1: string}|null
     */
    private function credentials(?string $raw = null): string|array|null
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $colon = strpos($raw, ':');
        if ($colon === false) {
            return $raw;
        }

        return [substr($raw, 0, $colon), substr($raw, $colon + 1)];
    }

    /** @return list<int> */
    private function findFuzzerPids(): array
    {
        $entries = scandir('/proc');
        if ($entries === false) {
            throw new \RuntimeException('unable to scan /proc');
        }

        $ownPid = getmypid();
        $pids = [];
        foreach ($entries as $entry) {
            if (!ctype_digit($entry)) {
                continue;
            }

            $pid = (int) $entry;
            if ($pid === $ownPid) {
                continue;
            }

            $commandLine = @file_get_contents("/proc/{$entry}/cmdline");
            if ($commandLine === false || $commandLine === '') {
                continue;
            }

            $arguments = array_filter(
                explode("\0", $commandLine),
                static fn (string $argument): bool => $argument !== '',
            );
            foreach ($arguments as $argument) {
                if (basename($argument) === 'phpredis-fuzz') {
                    $pids[] = $pid;
                    break;
                }
            }
        }

        sort($pids, SORT_NUMERIC);
        return $pids;
    }

    /**
     * Maps a seed host:port to every host:port that should be targeted. A
     * standalone server maps to itself; a cluster member maps to every
     * reachable primary and replica.
     *
     * @return list<string>
     */
    private function discoverNodes(string $address): array
    {
        [$host, $port] = $this->splitAddress($address);
        $redis = $this->connect($host, $port);

        try {
            try {
                $info = $redis->rawCommand('INFO', 'CLUSTER');
            } catch (\Throwable) {
                $info = null;
            }

            if (is_string($info) && str_contains($info, 'cluster_enabled:1')) {
                return $this->clusterNodeAddresses($redis, $host);
            }

            return [$this->formatAddress($host, $port)];
        } finally {
            $this->closeQuietly($redis);
        }
    }

    /**
     * @return list<string>
     */
    private function clusterNodeAddresses(\Redis $redis, string $seedHost): array
    {
        $raw = $redis->rawCommand('CLUSTER', 'NODES');
        if (!is_string($raw) || trim($raw) === '') {
            throw new \RuntimeException('CLUSTER NODES returned no usable data');
        }

        $addresses = [];
        foreach (explode("\n", trim($raw)) as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if (!is_array($fields) || count($fields) < 8) {
                continue;
            }

            $flags = $fields[2];
            if (str_contains($flags, 'fail') || str_contains($flags, 'noaddr') || str_contains($flags, 'handshake')) {
                continue;
            }
            if ($fields[7] !== 'connected') {
                continue;
            }

            $endpoint = $fields[1];
            $marker = strpos($endpoint, '@');
            if ($marker !== false) {
                $endpoint = substr($endpoint, 0, $marker);
            }

            [$nodeHost, $nodePort] = $this->splitAddress($endpoint);
            if ($nodeHost === '') {
                $nodeHost = $seedHost;
            }

            $addresses[$this->formatAddress($nodeHost, $nodePort)] = true;
        }

        if ($addresses === []) {
            throw new \RuntimeException('CLUSTER NODES listed no reachable nodes');
        }

        $list = array_keys($addresses);
        sort($list);

        return $list;
    }

    /**
     * @return list<int>
     */
    private function listClientIds(string $address): array
    {
        [$host, $port] = $this->splitAddress($address);
        $redis = $this->connect($host, $port);

        try {
            $ownId = $redis->rawCommand('CLIENT', 'ID');
            $list = $redis->rawCommand('CLIENT', 'LIST', 'TYPE', 'normal');
            if (!is_string($list) || $list === '') {
                return [];
            }

            $ids = [];
            foreach (explode("\n", $list) as $line) {
                if (preg_match('/(?:^|\s)id=(\d+)/', $line, $matches) !== 1) {
                    continue;
                }

                $id = (int) $matches[1];
                if (is_int($ownId) && $id === $ownId) {
                    continue;
                }

                $ids[$id] = true;
            }

            $unique = array_keys($ids);
            sort($unique, SORT_NUMERIC);

            return $unique;
        } finally {
            $this->closeQuietly($redis);
        }
    }

    /**
     * @param list<int> $ids
     * @return list<int> The ids that were still connected afterwards.
     */
    private function killClients(string $address, array $ids): array
    {
        [$host, $port] = $this->splitAddress($address);
        $redis = $this->connect($host, $port);

        $failed = [];
        try {
            foreach ($ids as $id) {
                try {
                    $result = $redis->rawCommand('CLIENT', 'KILL', 'ID', (string) $id);
                } catch (\Throwable) {
                    $failed[] = $id;
                    continue;
                }

                if (is_int($result)) {
                    if ($result < 1) {
                        $failed[] = $id;
                    }
                    continue;
                }
                if ($result === true) {
                    continue;
                }
                if (is_string($result) && strtoupper($result) === 'OK') {
                    continue;
                }

                $failed[] = $id;
            }
        } finally {
            $this->closeQuietly($redis);
        }

        return $failed;
    }

    private function connect(string $host, int $port): \Redis
    {
        $redis = new \Redis();

        try {
            $connected = @$redis->connect($host, $port, self::CONNECT_TIMEOUT, null, 0, self::CONNECT_TIMEOUT);
        } catch (\RedisException $exception) {
            throw new \RuntimeException("could not connect to {$host}:{$port}: {$exception->getMessage()}", 0, $exception);
        }

        if ($connected !== true) {
            throw new \RuntimeException("could not connect to {$host}:{$port}");
        }

        if ($this->auth !== null) {
            $redis->auth($this->auth);
        }

        return $redis;
    }

    private function closeQuietly(\Redis $redis): void
    {
        try {
            $redis->close();
        } catch (\Throwable) {
        }
    }

    /** @return array{0: string, 1: int} */
    private function splitAddress(string $address): array
    {
        $position = strrpos($address, ':');
        if ($position === false) {
            throw new \InvalidArgumentException("Address must be in host:port form: {$address}");
        }

        $host = substr($address, 0, $position);
        $port = substr($address, $position + 1);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if ($port === '' || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new \InvalidArgumentException("Address has an invalid port: {$address}");
        }

        return [$host, (int) $port];
    }

    private function formatAddress(string $host, int $port): string
    {
        if (str_contains($host, ':')) {
            return "[{$host}]:{$port}";
        }

        return "{$host}:{$port}";
    }

    /** @param list<string> $nodes */
    private function reportNodes(array $nodes): void
    {
        $summary = $nodes === []
            ? 'no Redis nodes'
            : count($nodes) . ' Redis node(s): ' . implode(', ', $nodes);
        $this->write("phpredis-fuzz-killer: targeting {$summary}\n");
    }

    private function logSignalFailure(float $started, string $signal, int $pid): void
    {
        $detail = '';
        if ($this->usesDefaultSignalSender && function_exists('posix_get_last_error')) {
            $error = posix_get_last_error();
            if ($error !== 0) {
                $detail = ': ' . posix_strerror($error);
            }
        }

        $this->log($started, "Failed to send {$signal} to {$pid}{$detail}", true);
    }

    private function log(float $started, string $message, bool $error = false): void
    {
        $elapsed = max(0, (int) floor(($this->clock)() - $started));
        $hours = intdiv($elapsed, 3600);
        $minutes = intdiv($elapsed % 3600, 60);
        $seconds = $elapsed % 60;
        $this->write(sprintf('[%02d:%02d:%02d] %s', $hours, $minutes, $seconds, $message) . "\n", $error);
    }

    private function write(string $message, bool $error = false): void
    {
        fwrite($error ? $this->error : $this->output, $message);
    }

    private const HELP = <<<'HELP'
phpredis-fuzz-killer - randomly disrupt a running phpredis-fuzz workload

Usage:
  phpredis-fuzz-killer [options]

Modes:
  --mode=MODE              What to disrupt each iteration (default: process)
                             process  send signals to phpredis-fuzz workers
                             client   run CLIENT KILL against a Redis target
                             both     do both every iteration

Signal options (process and both modes):
  --signals=NAME,...        Linux signals to choose from (default: SIGINT,SIGTERM,SIGQUIT)
                             Names are case-insensitive and the SIG prefix is optional

Redis options (client and both modes):
  --host=HOST               Redis host to inspect (default: 127.0.0.1)
  --port=PORT               Redis port to inspect (default: 6379)
  --auth=SECRET             Password, or user:pass, for the inspection connections

                             If the target belongs to a cluster, every reachable
                             primary and replica is mapped once at startup and one
                             node is chosen at random each iteration; a standalone
                             target always uses the single given node. Only normal
                             client connections are considered.

Shared options:
  --sleep=MIN-MAX           Random sleep range in seconds (default: 0.8-1.2)
  --rate=PERCENT            Chance, from 0.0 through 100.0, of acting on each discovered
                             PID or client per iteration (default: 100.0)
  --process-rate=PERCENT   Override --rate when signalling processes
  --client-rate=PERCENT    Override --rate when killing clients
  --help                     Show this help

Process discovery finds processes with a command-line argument whose basename is
exactly "phpredis-fuzz" and requires Linux /proc and PHP's POSIX extension.
Client killing requires PHP's redis extension. Every action, skipped target,
failure, empty scan, and sleep is logged with elapsed time. The utility runs
until stopped.

SIGKILL, SIGSTOP, fatal signals, and realtime signals are accepted when named.
Use this only against disposable fuzzer workers and Redis targets; signals such
as SIGQUIT may leave core dumps and CLIENT KILL disconnects live connections.
HELP;
}
