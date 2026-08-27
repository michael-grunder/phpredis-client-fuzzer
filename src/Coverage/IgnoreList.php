<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Coverage;

/**
 * Case-insensitive shell globs naming server commands the catalog is not
 * expected to cover.
 *
 * Patterns are matched against uncovered command names only, so an ignore
 * pattern can never hide a command the catalog already exercises.
 */
final class IgnoreList
{
    public const DEFAULT_FILE = __DIR__ . '/../../data/coverage-ignore.txt';

    /** @var list<string> */
    private array $patterns;

    /** @param list<string> $patterns */
    public function __construct(array $patterns = [])
    {
        $normalized = [];
        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim($pattern));
            if ($pattern !== '' && !in_array($pattern, $normalized, true)) {
                $normalized[] = $pattern;
            }
        }

        $this->patterns = $normalized;
    }

    /** The patterns shipped in data/coverage-ignore.txt. */
    public static function defaults(): self
    {
        return self::fromFile(self::DEFAULT_FILE);
    }

    /** Reads one pattern per line; blank lines and "#" comments are skipped. */
    public static function fromFile(string $path): self
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read ignore file: {$path}");
        }

        $patterns = [];
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $patterns[] = $line;
            }
        }

        return new self($patterns);
    }

    /** @param list<string> $patterns */
    public function with(array $patterns): self
    {
        return new self([...$this->patterns, ...$patterns]);
    }

    /** Returns the first pattern matching $command, or null when none does. */
    public function match(string $command): ?string
    {
        $command = strtolower($command);
        foreach ($this->patterns as $pattern) {
            if (fnmatch($pattern, $command)) {
                return $pattern;
            }
        }

        return null;
    }

    public function matches(string $command): bool
    {
        return $this->match($command) !== null;
    }

    /** @return list<string> */
    public function patterns(): array
    {
        return $this->patterns;
    }
}
