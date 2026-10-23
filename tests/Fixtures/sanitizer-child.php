<?php

declare(strict_types=1);

$steps = (int) ($argv[1] ?? 1);
if ($steps >= 3) {
    fwrite(STDERR, "==123==ERROR: AddressSanitizer: heap-use-after-free on address 0x123\n    #0 example.c:42\n");
}
exit((int) ($argv[2] ?? 1));
