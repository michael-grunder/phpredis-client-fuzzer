<?php

declare(strict_types=1);

// A recorder stand-in that only finalises if the supervisor leaves it alive.
if (($argv[1] ?? '') !== 'record' || !str_starts_with($argv[2] ?? '', '--output-trace-dir=')) {
    exit(78);
}
$directory = substr($argv[2], strlen('--output-trace-dir='));
$failure = getenv('SAPI_TEST_RR_FAILURE');
if ($failure === 'missing') {
    exit(23);
}
mkdir($directory, 0o700);
file_put_contents($directory . '/incomplete', '');
if ($failure === 'incomplete' || $failure === 'fatal') {
    if ($failure === 'fatal') {
        fwrite(STDERR, "[FATAL fixture:1] recorder failed\n");
    }
    exit(23);
}
file_put_contents($directory . '/argv.json', json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR));
$arguments = array_slice($argv, ($argv[3] ?? '') === '--chaos' ? 4 : 3);
$executable = array_shift($arguments) ?? throw new RuntimeException('missing tracee');
$pid = pcntl_fork();
if ($pid < 0) {
    throw new RuntimeException('cannot fork tracee');
}
if ($pid === 0) {
    pcntl_exec($executable, $arguments);
    exit(78);
}
if (pcntl_waitpid($pid, $status) !== $pid || !is_int($status)) {
    throw new RuntimeException('cannot reap tracee');
}
usleep(200000);
unlink($directory . '/incomplete');
exit(pcntl_wifsignaled($status) ? 128 + pcntl_wtermsig($status) : pcntl_wexitstatus($status));
