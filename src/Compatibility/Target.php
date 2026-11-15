<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Compatibility;

use Mgrunder\RedisClientTester\Differential\DiffRuntime;
use Mgrunder\RedisClientTester\Harness\Fs;

/** Private servers owned by one comparison; never accepts an existing endpoint.
 * @phpstan-import-type Snapshot from DiffRuntime
 */
final class Target
{
    /** @var list<resource> */
    private array $processes = [];
    /** @var list<resource> */
    private array $sockets = [];
    /** @var list<int> */
    public array $ports = [];
    /** @var list<int> */
    private array $busPorts = [];
    public readonly string $password;

    public function __construct(private readonly string $directory, bool $cluster, string $binary)
    {
        $this->password = bin2hex(random_bytes(16));
        Fs::ensureDir($directory);
        try {
            for ($i = 0; $i < ($cluster ? 3 : 1); $i++) {
                $this->start($binary, $cluster, $i);
            }
            if ($cluster) {
                foreach ($this->sockets as $index => $socket) {
                    $start = intdiv($index * 16384, 3);
                    $end = intdiv(($index + 1) * 16384, 3) - 1;
                    if (DiffRuntime::command($socket, ['CLUSTER', 'ADDSLOTSRANGE', (string) $start, (string) $end]) !== 'OK') {
                        throw new \RuntimeException('Cannot assign private cluster slots (Redis 7+ required)');
                    }
                    foreach ($this->ports as $peer => $port) {
                        if ($port !== $this->ports[$index]) {
                            DiffRuntime::command($socket, ['CLUSTER', 'MEET', '127.0.0.1', (string) $port, (string) $this->busPorts[$peer]]);
                        }
                    }
                }
                $deadline = microtime(true) + 10;
                do {
                    $ready = true;
                    foreach ($this->sockets as $socket) {
                        $info = DiffRuntime::command($socket, ['CLUSTER', 'INFO']);
                        $ready = $ready && is_string($info) && str_contains($info, 'cluster_state:ok');
                    }
                    if ($ready) {
                        return;
                    }
                    usleep(20000);
                } while (microtime(true) < $deadline);
                throw new \RuntimeException('Private cluster did not become ready');
            }
        } catch (\Throwable $error) {
            $this->stop();
            throw $error;
        }
    }

    private function start(string $binary, bool $cluster, int $index): void
    {
        // Reserve both ports until immediately before exec; authentication and PID checks detect races.
        $reservations = [];
        $ports = [];
        for ($i = 0; $i < 2; $i++) {
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            if ($socket === false) {
                throw new \RuntimeException('Cannot reserve a private Redis port');
            }
            $reservations[] = $socket;
            $address = stream_socket_get_name($socket, false);
            if ($address === false) {
                throw new \RuntimeException('Cannot read reserved port');
            }
            $ports[] = (int) substr($address, strrpos($address, ':') + 1);
        }
        $args = [$binary, '--bind', '127.0.0.1', '--port', (string) $ports[0],
            '--save', '', '--appendonly', 'no', '--daemonize', 'no', '--requirepass', $this->password,
            '--dir', $this->directory, '--dbfilename', "unused-{$index}.rdb"];
        if ($cluster) {
            $args = [...$args, '--cluster-enabled', 'yes', '--cluster-port', (string) $ports[1],
                '--cluster-config-file', "nodes-{$index}.conf", '--cluster-node-timeout', '1000',
                '--masterauth', $this->password];
        }
        foreach ($reservations as $socket) {
            fclose($socket);
        }
        $process = proc_open($args, [0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->directory . "/server-{$index}.log", 'a'],
            2 => ['file', $this->directory . "/server-{$index}.log", 'a']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot launch redis-server');
        }
        $this->processes[] = $process;
        $deadline = microtime(true) + 5;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                throw new \RuntimeException('Private Redis exited; see ' . $this->directory);
            }
            try {
                $socket = @DiffRuntime::connect(['host' => '127.0.0.1', 'port' => $ports[0],
                    'timeout' => 0.1, 'read_timeout' => 1.0, 'auth' => $this->password,
                    'ttl_tolerance_ms' => 0, 'strict_messages' => true]);
                $info = DiffRuntime::command($socket, ['INFO', 'server']);
                if (!is_string($info) || !str_contains($info, 'process_id:' . $status['pid'] . "\r\n")) {
                    fclose($socket);
                    throw new \RuntimeException('Redis ownership check failed');
                }
                $this->ports[] = $ports[0];
                $this->busPorts[] = $ports[1];
                $this->sockets[] = $socket;
                return;
            } catch (\RuntimeException) {
                usleep(20000);
            }
        } while (microtime(true) < $deadline);
        throw new \RuntimeException('Private Redis did not become ready');
    }

    public function reset(): void
    {
        foreach ($this->sockets as $socket) {
            if (DiffRuntime::command($socket, ['FLUSHALL', 'SYNC']) !== 'OK') {
                throw new \RuntimeException('Cannot reset private Redis');
            }
        }
    }

    /** @return Snapshot */
    public function snapshot(): array
    {
        $state = [];
        foreach ($this->sockets as $socket) {
            $state = [...$state, ...DiffRuntime::snapshot($socket, '')];
        }
        usort($state, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        return $state;
    }

    public function stop(): void
    {
        foreach ($this->sockets as $socket) {
            fclose($socket);
        }
        $this->sockets = [];
        foreach ($this->processes as $process) {
            proc_terminate($process);
        }
        $deadline = microtime(true) + 2;
        foreach ($this->processes as $process) {
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(10000);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
        $this->processes = [];
    }

    public function __destruct()
    {
        $this->stop();
    }
}
