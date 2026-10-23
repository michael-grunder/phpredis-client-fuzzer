<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

use Mgrunder\PhpredisCommandFuzzer\Cli\ExitCode;

/**
 * Turns a finished child's raw exit information into a verdict the scheduler
 * can act on: did the run fail, and what kind of failure was it — a hard
 * process crash, a run the harness killed for exceeding --run-timeout, a Zend
 * MM memory leak reported by a debug PHP build, a fatal allocation error,
 * the fuzzer exiting non-zero
 * after catching a diagnostic, or a startup failure where the fuzzer rejected
 * the command line and never ran a single command.
 */
final class FailureClassifier
{
    /**
     * Signals that mean "the process died", as opposed to being asked to stop.
     * SIGTRAP is included because a debug-build assertion or `__builtin_trap()`
     * lands there.
     *
     * @var list<int>
     */
    public const CRASH_SIGNALS = [
        4,  // SIGILL
        5,  // SIGTRAP
        6,  // SIGABRT
        7,  // SIGBUS
        8,  // SIGFPE
        11, // SIGSEGV
        31, // SIGSYS
    ];

    public function __construct(
        public readonly bool $failed,
        public readonly bool $crashed,
        public readonly bool $timedOut,
        public readonly bool $leaked,
        public readonly ?int $signal,
        public readonly ?int $exitCode,
        public readonly bool $startupError = false,
        public readonly bool $memory = false,
        public readonly bool $sanitizer = false,
    ) {
    }

    /**
     * @param bool $signaled proc_get_status()['signaled']
     * @param int|null $termSignal proc_get_status()['termsig'] when signaled
     * @param int|null $exitCode proc_get_status()['exitcode'] otherwise
     * @param bool $timedOut the harness killed the run for exceeding --run-timeout
     * @param bool $leaked a debug PHP build printed a Zend MM leak report
     * @param bool $memory the child printed a fatal allocation diagnostic
     * @param bool $sanitizer the child printed an ASAN, LSan, or UBSan report
     */
    public static function fromExit(
        bool $signaled,
        ?int $termSignal,
        ?int $exitCode,
        bool $timedOut = false,
        bool $leaked = false,
        bool $memory = false,
        bool $sanitizer = false,
    ): self {
        $signal = null;
        if ($signaled && $termSignal !== null && $termSignal > 0) {
            $signal = $termSignal;
        } elseif ($exitCode !== null && $exitCode > 128 && $exitCode < 128 + 65) {
            // Shells and rr surface a signal death as 128 + signal number.
            $signal = $exitCode - 128;
        }

        $crashed = $signal !== null && in_array($signal, self::CRASH_SIGNALS, true);
        $nonZeroExit = $exitCode !== null && $exitCode !== 0;
        $failed = $sanitizer || $crashed || $timedOut || $memory || $leaked || $nonZeroExit || $signal !== null;

        // The fuzzer binaries reserve ExitCode::STARTUP for "the command line
        // was rejected"; a run that never started cannot have died of anything
        // else, so a signal, timeout, or allocation diagnostic outranks the code.
        $startupError = $signal === null
            && !$timedOut
            && !$memory
            && !$sanitizer
            && $exitCode === ExitCode::STARTUP;

        return new self($failed, $crashed, $timedOut, $leaked, $signal, $exitCode, $startupError, $memory, $sanitizer);
    }

    /**
     * The single most significant category of this outcome, used to pick the
     * reproducer label and to gate capture against `--capture`. Precedence:
     * A sanitizer report identifies even an aborting sanitizer. Otherwise:
     * crash, timeout, memory, leak, rejected command line, non-zero exit.
     * A killed, timed-out run retains its crash/timeout classification.
     *
     * @return 'pass'|'sanitizer'|'crash'|'timeout'|'memory'|'leak'|'startup'|'failure'
     */
    public function kind(): string
    {
        if ($this->sanitizer && !$this->timedOut) {
            return 'sanitizer';
        }
        if ($this->crashed) {
            return 'crash';
        }
        if ($this->timedOut) {
            return 'timeout';
        }
        if ($this->memory) {
            return 'memory';
        }
        if ($this->leaked) {
            return 'leak';
        }
        if ($this->startupError) {
            return 'startup';
        }
        if ($this->failed) {
            return 'failure';
        }

        return 'pass';
    }

    public function signalName(): string
    {
        return self::signalLabel($this->signal);
    }

    public static function signalLabel(?int $signal): string
    {
        return match ($signal) {
            null => 'none',
            2 => 'SIGINT',
            3 => 'SIGQUIT',
            4 => 'SIGILL',
            5 => 'SIGTRAP',
            6 => 'SIGABRT',
            7 => 'SIGBUS',
            8 => 'SIGFPE',
            9 => 'SIGKILL',
            11 => 'SIGSEGV',
            13 => 'SIGPIPE',
            15 => 'SIGTERM',
            31 => 'SIGSYS',
            default => 'SIG' . $signal,
        };
    }

    /**
     * Whether $other reproduces the same class of failure as this verdict, used
     * by the reducer to decide whether a smaller step count still "counts".
     */
    public function matches(self $other): bool
    {
        if ($this->sanitizer || $other->sanitizer) {
            return $this->kind() === $other->kind();
        }
        if ($this->crashed || $other->crashed) {
            return $other->crashed && $other->signal === $this->signal;
        }
        if ($this->timedOut || $other->timedOut) {
            return $this->timedOut && $other->timedOut;
        }
        if ($this->memory || $other->memory) {
            return $this->memory && $other->memory;
        }
        if ($this->leaked || $other->leaked) {
            return $this->leaked && $other->leaked;
        }

        return $other->failed && $other->exitCode === $this->exitCode;
    }
}
