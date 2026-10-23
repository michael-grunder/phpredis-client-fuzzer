<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Viewer;

/**
 * Parsed native stack frames from an ASAN/UBSan/LSan report or a gdb `bt`.
 *
 * Frames in the sanitizer runtime, libc, the allocator, and signal plumbing are
 * "noise": they are kept for display but skipped when naming where a defect is.
 *
 * @phpstan-type Frame array{function: ?string, location: ?string, module: ?string}
 */
final class Backtrace
{
    private const NOISE_FUNCTION = '/^(?:__interceptor_\w+|__asan_\w+|__ubsan_\w+|__sanitizer_\w+|__lsan_\w+'
        . '|malloc|calloc|realloc|free|reallocarray|posix_memalign|aligned_alloc|strdup|strndup|operator (?:new|delete).*'
        . '|__GI_\w+|__pthread_kill\w*|pthread_kill|raise|abort|__assert_fail\w*|__libc_\w+|_start|__restore_rt'
        . '|zend_signal_handler\w*|zend_mm_\w+|__zend_malloc|__zend_calloc|__zend_realloc|_e(?:malloc|calloc|realloc|free)\w*'
        . '|_safe_e(?:malloc|realloc)|zend_string_alloc|tracked_\w+)$/';

    private const NOISE_LOCATION = '#(?:libsanitizer|compiler-rt|/libasan|/libubsan|/liblsan|asan_[a-z_]+\.c|\.\./sysdeps|/nptl/|\.\./csu)#';

    /** @param list<Frame> $frames */
    public function __construct(public readonly array $frames)
    {
    }

    /**
     * Sanitizer frames: `#0 0x7be7 in fn /src/file.c:12:3`, `#4 0x55b0 in fn (/usr/bin/php+0x58)
     * (BuildId: ..)`, or the unsymbolized `#8 0x55b0  (/usr/bin/php+0x5e)`.
     *
     * @return Frame|null
     */
    public static function parseSanitizerFrame(string $line): ?array
    {
        if (preg_match('/^\s*#\d+ 0x[0-9a-f]+\s+(?:in (\S+)\s*)?(.*)$/', $line, $match) !== 1) {
            return null;
        }
        $rest = trim((string) preg_replace('/\s*\(BuildId: [0-9a-f]+\)$/', '', $match[2]));
        $module = preg_match('/^\((.+?)(?:\+0x[0-9a-f]+)?\)$/', $rest, $m) === 1 ? $m[1] : null;

        return [
            'function' => $match[1] !== '' ? $match[1] : null,
            'location' => $module === null && $rest !== '' ? $rest : null,
            'module' => $module,
        ];
    }

    /**
     * gdb frames: `#3  0x00007f in fn (a=1) at src/x.c:12`, `#4  fn (a=1) at src/x.c:12`,
     * or `#5  0x00007f in ?? () from /lib/libc.so.6`.
     *
     * @return Frame|null
     */
    public static function parseGdbFrame(string $line): ?array
    {
        if (preg_match('/^#\d+\s+(?:0x[0-9a-f]+ in )?(\S+) \(.*?\)(?: at (\S+))?(?: from (\S+))?\s*$/', $line, $match) !== 1) {
            return null;
        }

        return [
            'function' => $match[1] !== '??' ? $match[1] : null,
            'location' => ($match[2] ?? '') !== '' ? $match[2] : null,
            'module' => ($match[3] ?? '') !== '' ? $match[3] : null,
        ];
    }

    /** @return list<Frame> */
    public function interesting(): array
    {
        return array_values(array_filter($this->frames, static function (array $frame): bool {
            if ($frame['function'] !== null && preg_match(self::NOISE_FUNCTION, $frame['function']) === 1) {
                return false;
            }

            return preg_match(self::NOISE_LOCATION, ($frame['location'] ?? '') . ' ' . ($frame['module'] ?? '')) !== 1;
        }));
    }

    /**
     * `fn (file.c:12)`, `fn (php8.5)`, or `?? (php8.5)`.
     *
     * @param Frame $frame
     */
    public static function label(array $frame, bool $withLocation = true): string
    {
        $name = $frame['function'] ?? '??';
        if (!$withLocation) {
            return $name;
        }
        if ($frame['location'] !== null) {
            return $name . ' (' . self::shortLocation($frame['location']) . ')';
        }

        return $frame['module'] !== null ? $name . ' (' . basename($frame['module']) . ')' : $name;
    }

    /** `/build/relay/src/commands.c:2936:7` becomes `commands.c:2936`. */
    public static function shortLocation(string $location): string
    {
        return (string) preg_replace('/^([^:]+:\d+):\d+$/', '$1', basename($location));
    }

    /** The defect site and up to $callers interesting callers, e.g. `a (x.c:1) ← b ← c`. */
    public function site(int $callers = 1): ?string
    {
        $frames = $this->interesting();
        if ($frames === []) {
            return null;
        }
        $parts = [self::label($frames[0])];
        foreach (array_slice($frames, 1, $callers) as $frame) {
            $parts[] = self::label($frame, false);
        }

        return implode(' ← ', $parts);
    }

    /** Stable across runs and addresses: top interesting frames, the first with its line. */
    public function signature(int $depth = 3): string
    {
        $frames = array_slice($this->interesting(), 0, $depth);
        $parts = [];
        foreach ($frames as $index => $frame) {
            $parts[] = $index === 0 && $frame['location'] !== null
                ? ($frame['function'] ?? '??') . '@' . self::shortLocation($frame['location'])
                : self::label($frame, false);
        }

        return implode(' < ', $parts);
    }

    /** @return list<string> */
    public function render(int $limit = 20): array
    {
        $lines = [];
        foreach (array_slice($this->frames, 0, $limit) as $index => $frame) {
            $where = $frame['location'] ?? ($frame['module'] !== null ? '(' . $frame['module'] . ')' : '');
            $lines[] = sprintf('#%-2d %s %s', $index, $frame['function'] ?? '??', $where);
        }
        if (count($this->frames) > $limit) {
            $lines[] = sprintf('... %d more frames', count($this->frames) - $limit);
        }

        return $lines;
    }
}
