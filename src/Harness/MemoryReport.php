<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/** A fatal allocation diagnostic recovered after the child has exited. */
final class MemoryReport
{
    public function __construct(
        public readonly string $message,
    ) {
    }

    /**
     * PHP can display errors on stdout or log them on stderr. Read in bounded
     * chunks so a large child log cannot exhaust the harness' own memory.
     */
    public static function scan(string ...$paths): ?self
    {
        foreach ($paths as $path) {
            $handle = @fopen($path, 'r');
            if ($handle === false) {
                continue;
            }
            try {
                $continuation = false;
                while (($line = fgets($handle, 16384)) !== false) {
                    $skip = $continuation;
                    $continuation = !str_ends_with($line, "\n");
                    if ($skip) {
                        continue;
                    }
                    $line = trim(strip_tags($line));
                    if (preg_match(
                        '/^(?:\[[^\]]+\]\s*)?(?:PHP\s+)?Fatal error:\s*('
                        . 'Allowed memory size of \d+ bytes exhausted\b'
                        . '|Out of memory\b'
                        . '|(?:Possible|Potential) integer overflow in memory allocation\b'
                        . ').*$/i',
                        $line,
                    ) === 1 || $line === 'Out of memory') {
                        return new self($line);
                    }
                }
            } finally {
                fclose($handle);
            }
        }

        return null;
    }
}
