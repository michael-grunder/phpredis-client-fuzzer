<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * Turns a finished child's raw exit information into a verdict the scheduler
 * can act on: did the run fail, and was the failure a hard process crash as
 * opposed to the fuzzer exiting non-zero after catching a diagnostic.
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
        public readonly ?int $signal,
        public readonly ?int $exitCode,
    ) {
    }

    /**
     * @param bool $signaled proc_get_status()['signaled']
     * @param int|null $termSignal proc_get_status()['termsig'] when signaled
     * @param int|null $exitCode proc_get_status()['exitcode'] otherwise
     * @param bool $timedOut the harness killed the run for exceeding --run-timeout
     */
    public static function fromExit(
        bool $signaled,
        ?int $termSignal,
        ?int $exitCode,
        bool $timedOut = false,
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
        $failed = $crashed || $timedOut || $nonZeroExit || $signal !== null;

        return new self($failed, $crashed, $timedOut, $signal, $exitCode);
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
        if ($this->crashed || $other->crashed) {
            return $other->crashed && $other->signal === $this->signal;
        }
        if ($this->timedOut || $other->timedOut) {
            return $other->timedOut;
        }

        return $other->failed && $other->exitCode === $this->exitCode;
    }
}
