<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Sapi;

/** Router body after Server's identity, authentication and readiness checks. */
final class SoakRouter
{
    public static function source(): string
    {
        return <<<'PHP'
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
    $exit = null;
    foreach (headers_list() as $header) {
        if (preg_match('/^X-Fuzzer-Exit: ([0-9]+)$/i', $header, $match)) {
            $exit = (int) $match[1];
        }
    }
    $record($event + ['event' => 'finish', 'elapsed' => microtime(true) - $started,
        'status' => http_response_code(), 'exit' => $exit, 'error_type' => error_get_last()['type'] ?? null]);
});
require __DIR__ . $path;
PHP;
    }
}
