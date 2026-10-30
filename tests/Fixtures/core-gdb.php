<?php

declare(strict_types=1);

fwrite(STDOUT, json_encode(array_slice($argv ?? throw new RuntimeException('CLI fixture requires argv'), 1), JSON_THROW_ON_ERROR) . "\n");
fwrite(STDERR, "fixture gdb diagnostic\n");
$mode = getenv('RCT_GDB_TEST_MODE');
if ($mode === 'slow') {
    usleep(500000);
} elseif ($mode === 'hang') {
    sleep(30);
} elseif ($mode === 'fail') {
    exit(7);
}
echo "Thread 2 (worker):\n#0 worker_call ()\n    local_value = 42\n";
echo "Thread 1 (main):\n#0 crash_call ()\n    local_value = 99\n";
