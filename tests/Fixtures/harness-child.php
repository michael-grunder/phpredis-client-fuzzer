<?php

declare(strict_types=1);

/**
 * A stand-in fuzzer run for the harness tests: sleeps for $argv[2] seconds and
 * exits with $argv[1]. A real template runs a script too, so the harness'
 * trailing `--seed=N` lands where a script expects it.
 *
 * The line on stdout stands in for a real run's result document, and is written
 * before the sleep so a run the harness kills has still printed something.
 */
$code = (int) ($argv[1] ?? 0);
$sleep = (float) ($argv[2] ?? 0.0);

fwrite(STDOUT, "harness-child exiting with {$code}\n");
// Flushed up front: a run the harness kills never reaches PHP's shutdown, and
// the harness tests need its output to be on disk by then.
fflush(STDOUT);

if ($sleep > 0.0) {
    usleep((int) ($sleep * 1000000));
}

exit($code);
