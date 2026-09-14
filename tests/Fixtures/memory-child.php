<?php

declare(strict_types=1);

// No Redis or large allocation is needed: Zend rejects this request against
// the small memory limit before allocating. Shorter runs mimic an unrelated
// fatal error with PHP's same exit code, to exercise the reducer's matching.
$steps = (int) ($argv[1] ?? 3);
if ($steps < 3) {
    fwrite(STDERR, "PHP Fatal error: Unrelated failure in memory-child.php on line 10\n");
    exit(255);
}

ini_set('memory_limit', '32M');
echo str_repeat('x', 64 * 1024 * 1024);
