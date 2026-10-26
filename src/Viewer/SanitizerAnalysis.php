<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Viewer;

/**
 * The first ASAN, LSan, or UBSan report in a stderr log, reduced to what the
 * defect is and where. A forked fuzzer can print one report per child; they are
 * counted, and the first is analysed.
 *
 * @phpstan-import-type Frame from Backtrace
 */
final class SanitizerAnalysis
{
    private const START = '/^==\d+==ERROR: (AddressSanitizer|LeakSanitizer): (.*)$/';

    private const UBSAN = '/^(\S+?:\d+(?::\d+)?): runtime error: (.*)$/';

    /** @param list<string> $excerpt */
    private function __construct(
        public readonly string $tool,
        public readonly string $type,
        public readonly ?string $access,
        public readonly ?string $location,
        public readonly Backtrace $stack,
        public readonly Backtrace $freedBy,
        public readonly Backtrace $allocatedBy,
        public readonly array $excerpt,
        public readonly int $reports,
    ) {
    }

    /** @param list<string> $lines */
    public static function parse(array $lines): ?self
    {
        $start = null;
        $reports = 0;
        foreach ($lines as $index => $line) {
            if (preg_match(self::START, $line) === 1 || preg_match(self::UBSAN, $line) === 1) {
                $start ??= $index;
                $reports++;
            }
        }
        if ($start === null) {
            return null;
        }

        $tool = 'UndefinedBehaviorSanitizer';
        $access = null;
        $location = null;
        if (preg_match(self::START, $lines[$start], $match) === 1) {
            $tool = $match[1];
            // `heap-use-after-free on address 0x..`, `SEGV on unknown address ..`,
            // `attempting double-free on 0x..`, `detected memory leaks`.
            $type = trim((string) preg_replace('/\s+(?:on (?:unknown )?address|on 0x|\(|at pc|for size).*$/', '', $match[2]), ' :');
        } elseif (preg_match(self::UBSAN, $lines[$start], $match) === 1) {
            $type = 'runtime error';
            $location = Backtrace::shortLocation($match[1]);
            $access = (string) preg_replace('/0x[0-9a-f]+/', '0x…', $match[2]);
        } else {
            return null;
        }

        /** @var array{stack: list<Frame>, freed: list<Frame>, allocated: list<Frame>} $sections */
        $sections = ['stack' => [], 'freed' => [], 'allocated' => []];
        $section = 'stack';
        $excerpt = [$lines[$start]];
        for ($index = $start + 1; $index < count($lines); $index++) {
            $line = $lines[$index];
            if (preg_match(self::START, $line) === 1 || preg_match(self::UBSAN, $line) === 1
                || str_starts_with($line, 'Shadow bytes around') || str_contains($line, '==ABORTING')) {
                break;
            }
            $excerpt[] = $line;
            if (str_starts_with($line, 'SUMMARY: ')) {
                break;
            }
            if (preg_match('/^(READ|WRITE) of size \d+/', $line, $m) === 1) {
                $access ??= $m[0];
            } elseif (preg_match('/The signal is caused by a (READ|WRITE) memory access/', $line, $m) === 1) {
                $access ??= $m[1];
            } elseif (str_starts_with($line, 'freed by thread')) {
                $section = 'freed';
            } elseif (str_starts_with($line, 'previously allocated by thread') || str_starts_with($line, 'allocated by thread')) {
                $section = 'allocated';
            } elseif (preg_match('/^(Direct|Indirect) leak of (\d+) byte\(s\) in (\d+) object\(s\)/', $line, $m) === 1) {
                // Only the first leak block is analysed; the rest stay in the excerpt.
                if ($access !== null) {
                    $section = 'ignore';
                    continue;
                }
                $access = "{$m[1]} leak of {$m[2]} bytes in {$m[3]} objects";
                $section = 'stack';
            } elseif ($section !== 'ignore' && ($frame = Backtrace::parseSanitizerFrame($line)) !== null) {
                $sections[$section][] = $frame;
            }
        }

        return new self(
            $tool,
            $type,
            $access,
            $location,
            new Backtrace($sections['stack']),
            new Backtrace($sections['freed']),
            new Backtrace($sections['allocated']),
            $excerpt,
            $reports,
        );
    }

    public function headline(): string
    {
        $parts = [$this->tool === 'UndefinedBehaviorSanitizer' ? 'UBSan' : $this->type];
        if ($this->access !== null) {
            $parts[] = $this->access;
        }
        $site = $this->stack->site();
        if ($site !== null) {
            $parts[] = 'in ' . $site;
        } elseif ($this->location !== null) {
            $parts[] = 'at ' . $this->location;
        }
        $freed = $this->freedBy->site(0);
        if ($freed !== null) {
            $parts[] = '| freed in ' . $freed;
        }

        return implode(' ', $parts);
    }

    public function signature(): string
    {
        $where = $this->stack->signature();

        return $this->tool . ': ' . $this->type . ' ' . ($where !== '' ? $where : (string) $this->location);
    }
}
