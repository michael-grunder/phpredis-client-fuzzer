<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mgrunder\RedisClientTester\Sapi\Server;

$directory = $argv[1] ?? throw new RuntimeException('missing fixture directory');
$mode = $argv[2] ?? throw new RuntimeException('missing fixture mode');
$paths = [];
for ($index = 0; $index < ($mode === 'normal' ? 8 : 1); $index++) {
    $name = "request-{$index}.php";
    $paths[] = $name;
    $source = <<<'SOURCE'
<?php
header('X-Fuzzer-Exit: 0');
file_put_contents(__DIR__ . '/events.jsonl', json_encode(['start', getmypid(), microtime(true)]) . "\n", FILE_APPEND | LOCK_EX);
usleep(150000);
file_put_contents(__DIR__ . '/events.jsonl', json_encode(['end', getmypid(), microtime(true)]) . "\n", FILE_APPEND | LOCK_EX);
echo PHP_SAPI . ':' . ini_get('precision');
SOURCE;
    if ($mode === 'timeout') {
        $source = '<?php sleep(10);';
    } elseif ($mode === 'crash') {
        $source = '<?php posix_setrlimit(POSIX_RLIMIT_CORE, 0, 0); pcntl_signal(SIGSEGV, SIG_DFL); posix_kill(getmypid(), SIGSEGV);';
    } elseif ($mode === 'fatal') {
        $source = '<?php throw new RuntimeException("fixture fatal");';
    } elseif ($mode === 'startup') {
        $source = '<?php header("X-Fuzzer-Exit: 78"); echo "invalid workload";';
    } elseif ($mode === 'idle') {
        $source = '<?php header("X-Fuzzer-Exit: 0"); echo "idle";';
    } elseif ($mode === 'valgrind') {
        $source = '<?php header("X-Fuzzer-Exit: 0"); file_put_contents("php://stderr", "==123== ERROR SUMMARY: 1 errors from 1 contexts\n");';
    } elseif ($mode === 'shutdown-fatal') {
        $source = '<?php header("X-Fuzzer-Exit: 0"); echo "done"; flush(); register_shutdown_function(static function () { throw new RuntimeException("shutdown failed"); });';
    }
    Server::write($directory . '/' . $name, $source);
}
exit((new Server($directory, 2, 2, $mode === 'idle' ? 1000 : 40, $mode === 'timeout' ? 0.4 : 5.0))->run($paths, ['-dprecision=12']));
