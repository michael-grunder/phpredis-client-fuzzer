<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * A parsed leak report recovered from a finished child's captured stderr.
 *
 * Two allocators can report a leak, and neither changes the process exit code,
 * so the harness detects both by scanning the log after the run:
 *
 * 1. The Zend memory manager, on a debug build of PHP, at request shutdown:
 *
 *      ext/relay/src/relay.c(5629) :  Freeing 0x00007f… (1024 bytes), script=…
 *      Last leak repeated 1 time
 *      === Total 2 memory leaks detected ===
 *
 * 2. Relay's own shared allocator (src/shmalloc.c), when built with
 *    RELAY_SH_TRACK_LEAKS, on arena destruction — one line per distinct site:
 *
 *      relay.c:5636 leaked block of 112 bytes at 0x7fe161617810 allocated by pid 3844901 at 1788310743.315590 (leak repeated 2 times)
 */
final class LeakReport
{
    public const SOURCE_ZEND_MM = 'zend-mm';
    public const SOURCE_RELAY_SHM = 'relay-shm';

    /**
     * @param 'zend-mm'|'relay-shm' $source which allocator reported the leak
     */
    public function __construct(
        public readonly int $count,
        public readonly int $bytes,
        public readonly ?string $firstSite,
        public readonly string $source = self::SOURCE_ZEND_MM,
    ) {
    }

    /**
     * Scan a finished child's stderr log for a leak report. Returns null when
     * the log is missing, unreadable, or contains no recognised leak summary.
     *
     * The Zend MM report is checked first so existing behaviour is unchanged;
     * a run that only tripped Relay's shared allocator is reported as
     * {@see SOURCE_RELAY_SHM}.
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

        return self::scanZendMm($text) ?? self::scanRelayShm($text);
    }

    private static function scanZendMm(string $text): ?self
    {
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

        return new self($count, $bytes, $firstSite, self::SOURCE_ZEND_MM);
    }

    private static function scanRelayShm(string $text): ?self
    {
        $pattern = '/^([^\s:]+:\d+) leaked block of (\d+) bytes at 0x[0-9a-fA-F]+'
            . ' allocated by pid \d+ at \d+\.\d+(?: \(leak repeated (\d+) times\))?/m';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) < 1) {
            return null;
        }

        $count = 0;
        $bytes = 0;
        foreach ($matches as $match) {
            $repeats = isset($match[3]) ? (int) $match[3] : 1;
            $count += $repeats;
            $bytes += (int) $match[2] * $repeats;
        }

        return new self($count, $bytes, $matches[0][1], self::SOURCE_RELAY_SHM);
    }
}
