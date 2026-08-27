<?php

namespace Mgrunder\PhpredisCommandFuzzer;

/**
 * Collects PHP warnings so we can summarize them at the end of a fuzzing run.
 */
final class WarningCollector
{
    /** @var array<string, int> */
    private array $warnings = [];

    private bool $registered = false;

    public function __construct()
    {
        $this->register();
    }

    public function __destruct()
    {
        $this->restore();
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        set_error_handler(
            [$this, 'handle'],
            E_WARNING | E_USER_WARNING
        );
        $this->registered = true;
    }

    public function restore(): void
    {
        if (! $this->registered) {
            return;
        }

        restore_error_handler();

        $this->registered = false;
    }

    public function reset(): void
    {
        $this->warnings = [];
    }

    /**
     * @return array<string, int>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function uniqueCount(): int
    {
        return count($this->warnings);
    }

    public function occurrenceCount(): int
    {
        return array_sum($this->warnings);
    }

    /**
     * Suppress the warning output while counting occurrences.
     *
     * @param array<string, mixed>|null $context
     */
    public function handle(
        int $severity,
        string $message,
        ?string $file = null,
        ?int $line = null,
        ?array $context = null
    ): bool {
        if (($severity & (E_WARNING | E_USER_WARNING)) === 0) {
            return false;
        }

        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        $formatted = $this->formatMessage($message, $file, $line);
        $this->warnings[$formatted] = ($this->warnings[$formatted] ?? 0) + 1;

        return true;
    }

    private function formatMessage(string $message, ?string $file, ?int $line): string
    {
        $displayFile = $this->normalizePath($file);
        $displayLine = $line ?? 0;

        return sprintf('PHP Warning: %s in %s on line %d', $message, $displayFile, $displayLine);
    }

    private function normalizePath(?string $file): string
    {
        if ($file === null || $file === '') {
            return 'unknown file';
        }

        $cwd = getcwd();
        if ($cwd !== false) {
            $cwd = rtrim($cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (str_starts_with($file, $cwd)) {
                return substr($file, strlen($cwd));
            }
        }

        return $file;
    }
}
