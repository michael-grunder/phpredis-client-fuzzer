<?php

declare(strict_types=1);

/**
 * A stand-in fuzzer run for the harness tests: sleeps for $argv[2] seconds and
 * exits with $argv[1]. A real template runs a script too, so the harness'
 * trailing `--seed=N` lands where a script expects it.
 */
$code = (int) ($argv[1] ?? 0);
$sleep = (float) ($argv[2] ?? 0.0);
if ($sleep > 0.0) {
    usleep((int) ($sleep * 1000000));
}

exit($code);
