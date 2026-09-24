<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Differential;

/**
 * Divergence signatures already saved in an output directory, shared by every
 * worker writing there. The file is append-only and re-read on each lookup, so
 * concurrent workers see each other's findings; a race between two workers
 * can at worst save the same signature twice.
 *
 * File format: one `<signature>\t<reproducer path>` line per finding.
 */
final class SignatureIndex
{
    /** @var array<string, true> */
    private array $local = [];

    public function __construct(private readonly string $path, private readonly bool $enabled = true)
    {
    }

    public function seen(string $signature): bool
    {
        if (!$this->enabled) {
            return false;
        }
        if (isset($this->local[$signature])) {
            return true;
        }

        $lines = is_file($this->path) ? @file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        foreach ($lines ?: [] as $line) {
            $this->local[explode("\t", $line, 2)[0]] = true;
        }

        return isset($this->local[$signature]);
    }

    public function add(string $signature, string $where): void
    {
        $this->local[$signature] = true;
        if (!$this->enabled) {
            return;
        }

        $line = str_replace(["\t", "\n"], ' ', $signature) . "\t" . $where . "\n";
        if (@file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException("cannot append to {$this->path}");
        }
    }
}
