<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Sapi;

/** Router body after Server's identity, authentication and readiness checks. */
final class SoakRouter
{
    public static function source(int $healWorkers = 0): string
    {
        return ($healWorkers > 0 ? self::healSource($healWorkers) : '') . <<<'PHP'
$path = $_SERVER['REQUEST_URI'];
if ($_SERVER['REQUEST_METHOD'] !== 'GET'
    || preg_match('~^/clients/client-[a-f0-9]+/[0-9]+/request-[0-9]+-seed-[0-9]+\.php$~D', $path) !== 1
    || !is_file(__DIR__ . $path)) {
    http_response_code(404);
    return;
}
// Never replay a request into the same concrete call log.
$claim = @fopen(__DIR__ . $path . '.executed', 'x');
if ($claim === false) {
    http_response_code(409);
    return;
}
fclose($claim);
$started = microtime(true);
$event = ['path' => $path, 'pid' => getmypid(), 'started' => $started];
$record = static function (array $row): void {
    $line = json_encode($row, JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents(__DIR__ . '/requests.jsonl', $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
        throw new RuntimeException('cannot record request event');
    }
};
$record($event + ['event' => 'start']);
register_shutdown_function(static function () use ($event, $started, $record): void {
    // Confirm only after the workload's already registered shutdown callbacks.
    register_shutdown_function(static function () use ($event, $started, $record): void {
        $exit = null;
        foreach (headers_list() as $header) {
            if (preg_match('/^X-Fuzzer-Exit: ([0-9]+)$/i', $header, $match)) {
                $exit = (int) $match[1];
            }
        }
        $error = error_get_last()['type'] ?? null;
        $record($event + ['event' => 'finish', 'elapsed' => microtime(true) - $started,
            'status' => http_response_code(), 'exit' => $exit, 'error_type' => $error]);
        if (in_array($exit, [0, 1], true) && http_response_code() === 200
            && !in_array($error, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            if (file_put_contents(__DIR__ . $event['path'] . '.executed', $exit . "\n", LOCK_EX) !== 2) {
                throw new RuntimeException('cannot confirm request completion');
            }
        }
    });
});
require __DIR__ . $path;
PHP;
    }

    private static function healSource(int $workers): string
    {
        return '$healWorkers = ' . $workers . ";\n" . <<<'PHP'
if ($_SERVER['REQUEST_URI'] === '/heal' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $lock = fopen(__DIR__ . '/heal.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        http_response_code(500);
        return;
    }
    // Count under the lock: even delayed or repeated requests may only fill a deficit.
    $group = posix_getpgrp();
    $alive = 0;
    foreach (glob('/proc/[0-9]*/stat') ?: [] as $path) {
        $stat = @file_get_contents($path);
        if ($stat === false || $stat === '') {
            continue;
        }
        $fields = explode(' ', substr($stat, (int) strrpos($stat, ')') + 2));
        if ($fields[0] !== 'Z' && ($fields[2] ?? '') === (string) $group
            && (int) basename(dirname($path)) !== $group) {
            $alive++;
        }
    }
    $pid = $alive < $healWorkers ? pcntl_fork() : 0;
    if ($pid !== 0) {
        flock($lock, LOCK_UN);
    }
    fclose($lock);
    if ($pid < 0) {
        http_response_code(500);
        echo 'pcntl_fork failed';
        return;
    }
    header('X-Fuzzer-Exit: 0');
    // Both processes finish this request and return to the inherited server loop.
    return;
}

PHP;
    }
}
