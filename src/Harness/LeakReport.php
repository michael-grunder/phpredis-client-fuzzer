<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * A parsed Zend memory-manager leak report. Debug builds of PHP print one to
 * stderr at shutdown when the request left allocations behind, e.g.
 *
 *   ext/relay/src/relay.c(5629) :  Freeing 0x00007f… (1024 bytes), script=…
 *   Last leak repeated 1 time
 *   === Total 2 memory leaks detected ===
 *
 * These do not change the process exit code, so the harness detects them by
 * scanning a finished child's captured stderr.
 */
final class LeakReport
{
    public function __construct(
        public readonly int $count,
        public readonly int $bytes,
        public readonly ?string $firstSite,
    ) {
    }

    /**
     * Scan a finished child's stderr log for the leak report. Returns null when
     * the log is missing, unreadable, or contains no leak summary line.
     */
    public static function scan(string $stderrPath): ?self
    {
        if (!is_file($stderrPath)) {
            return null;
        }

        $text = @file_get_contents($stderrPath);
        if (!is_string($text) || $text === '') {
            return null;
        }

        if (preg_match('/=== Total (\d+) memory leaks? detected ===/', $text, $summary) !== 1) {
            return null;
        }
        $count = (int) $summary[1];
        if ($count < 1) {
            return null;
        }

        $bytes = 0;
        if (preg_match_all('/Freeing 0x[0-9a-fA-F]+ \((\d+) bytes\)/', $text, $sizes) >= 1) {
            foreach ($sizes[1] as $size) {
                $bytes += (int) $size;
            }
        }

        $firstSite = null;
        if (preg_match('/^(.*\(\d+\)) :\s+Freeing 0x/m', $text, $site) === 1) {
            $firstSite = trim($site[1]);
        }

        return new self($count, $bytes, $firstSite);
    }
}
