<?php

declare(strict_types=1);

$code = (int) ($argv[1] ?? throw new RuntimeException('missing exit code'));
$directory = $argv[2] ?? throw new RuntimeException('missing directory');
$php = $argv[3] ?? throw new RuntimeException('missing PHP executable');
$pid = pcntl_fork();
if ($pid < 0) {
    throw new RuntimeException('cannot fork exit fixture');
}
if ($pid === 0) {
    $deadline = microtime(true) + 5;
    while (!is_file($directory . '/server.ready') && microtime(true) < $deadline) {
        usleep(10000);
    }
    usleep(200000);
    fwrite(STDERR, 'fixture descendant exiting normally with code ' . $code . "\n");
    exit($code);
}
pcntl_exec($php, array_slice($argv, 4));
throw new RuntimeException('cannot exec PHP server');
