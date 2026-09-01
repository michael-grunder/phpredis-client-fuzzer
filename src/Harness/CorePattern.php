<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * A read-only view of the kernel `core_pattern` (and `core_uses_pid`) sysctls.
 *
 * The harness insists the pattern contain `%p` so that a core produced by one
 * concurrent run can be told apart from every other run's core by PID.
 */
final class CorePattern
{
    public function __construct(
        public readonly string $raw,
        public readonly bool $usesPidSysctl,
    ) {
    }

    public static function fromSystem(): ?self
    {
        $pattern = @file_get_contents('/proc/sys/kernel/core_pattern');
        if ($pattern === false) {
            return null;
        }

        $usesPid = trim((string) @file_get_contents('/proc/sys/kernel/core_uses_pid')) === '1';

        return new self(trim($pattern), $usesPid);
    }

    /** The pattern pipes the core to a handler program rather than a file. */
    public function isPiped(): bool
    {
        return str_starts_with($this->raw, '|');
    }

    /** The pattern embeds the crashing PID, so cores are per-run distinguishable. */
    public function isolatesByPid(): bool
    {
        return str_contains($this->raw, '%p');
    }

    /**
     * Glob patterns that match a core file written for $pid. Relative patterns
     * resolve against $fallbackDir (the crashing process' working directory).
     *
     * @return list<string>
     */
    public function globsForPid(int $pid, string $fallbackDir): array
    {
        if ($this->isPiped()) {
            return [];
        }

        $directory = str_starts_with($this->raw, '/')
            ? \dirname($this->raw)
            : rtrim($fallbackDir, '/');

        $name = basename($this->raw);
        $glob = preg_replace_callback(
            '/%(.)/',
            static fn (array $matches): string => match ($matches[1]) {
                'p' => (string) $pid,
                '%' => '%',
                default => '*',
            },
            $name,
        ) ?? $name;

        if ($this->usesPidSysctl && !str_contains($this->raw, '%p')) {
            $glob .= '.' . $pid;
        }

        return [$directory . '/' . $glob];
    }
}
