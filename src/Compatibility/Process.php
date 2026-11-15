<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Compatibility;

use Mgrunder\RedisClientTester\Cli\ExitCode;

final class Process
{
    /** @param list<string> $argv */
    public static function run(array $argv, string $log, float $timeout): int
    {
        $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log . '.stdout.log', 'a'], 2 => ['file', $log . '.stderr.log', 'a']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot launch compatibility worker');
        }
        $deadline = microtime(true) + $timeout;
        try {
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    return $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
                }
                if (microtime(true) >= $deadline) {
                    return ExitCode::TIMEOUT;
                }
                usleep(10000);
            } while (true);
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
    }
}
