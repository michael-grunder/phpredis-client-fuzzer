<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * Presentation surface for the harness: either the full-screen TUI or a plain
 * line log. The scheduler drives it and never assumes which one is active.
 */
interface View
{
    public function start(): void;

    public function render(Stats $stats, DashboardState $state): void;

    /** A one-line, human-readable event (run finished, reproducer captured, ...). */
    public function note(string $message): void;

    /** Whether the operator has asked to quit (a key press in the TUI). */
    public function quitRequested(): bool;

    public function stop(): void;
}
