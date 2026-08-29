<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Cli;

/**
 * Repeatedly sends randomly selected Linux signals to running fuzzer workers.
 *
 * @internal This class exists to keep the Composer binary small and testable.
 */
final class FuzzKillerApplication
{
    private const VALUE_OPTIONS = ['signals', 'sleep', 'rate'];
    private const FLAG_OPTIONS = ['help'];

    /** @var array<string, int> */
    private const LINUX_SIGNALS = [
        'SIGHUP' => 1,
        'SIGINT' => 2,
        'SIGQUIT' => 3,
        'SIGILL' => 4,
        'SIGTRAP' => 5,
        'SIGABRT' => 6,
        'SIGIOT' => 6,
        'SIGBUS' => 7,
        'SIGFPE' => 8,
        'SIGKILL' => 9,
        'SIGUSR1' => 10,
        'SIGSEGV' => 11,
        'SIGUSR2' => 12,
        'SIGPIPE' => 13,
        'SIGALRM' => 14,
        'SIGTERM' => 15,
        'SIGSTKFLT' => 16,
        'SIGCHLD' => 17,
        'SIGCLD' => 17,
        'SIGCONT' => 18,
        'SIGSTOP' => 19,
        'SIGTSTP' => 20,
        'SIGTTIN' => 21,
        'SIGTTOU' => 22,
        'SIGURG' => 23,
        'SIGXCPU' => 24,
        'SIGXFSZ' => 25,
        'SIGVTALRM' => 26,
        'SIGPROF' => 27,
        'SIGWINCH' => 28,
        'SIGIO' => 29,
        'SIGPOLL' => 29,
        'SIGPWR' => 30,
        'SIGSYS' => 31,
        'SIGRTMIN' => 34,
        'SIGRTMAX' => 64,
    ];

    /** @var resource */
    private $output;

    /** @var resource */
    private $error;

    /** @var \Closure(): list<int> */
    private readonly \Closure $pidFinder;

    /** @var \Closure(int, int): bool */
    private readonly \Closure $signalSender;

    /** @var \Closure(int): void */
    private readonly \Closure $sleeper;

    /** @var \Closure(int, int): int */
    private readonly \Closure $randomInteger;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    private readonly bool $usesDefaultPidFinder;
    private readonly bool $usesDefaultSignalSender;

    /**
     * The callable arguments and iteration limit are test seams; the binary
     * uses the defaults and therefore runs until an external signal stops it.
     *
     * @param resource|null $output Defaults to STDOUT.
     * @param resource|null $error Defaults to STDERR.
     * @param (callable(): list<int>)|null $pidFinder
     * @param (callable(int, int): bool)|null $signalSender
     * @param (callable(int): void)|null $sleeper
     * @param (callable(int, int): int)|null $randomInteger
     * @param (callable(): float)|null $clock Monotonic seconds.
     */
    public function __construct(
        $output = null,
        $error = null,
        ?callable $pidFinder = null,
        ?callable $signalSender = null,
        ?callable $sleeper = null,
        ?callable $randomInteger = null,
        ?callable $clock = null,
        private readonly ?int $iterationLimit = null,
    ) {
        $this->output = $output ?? STDOUT;
        $this->error = $error ?? STDERR;
        $this->usesDefaultPidFinder = $pidFinder === null;
        $this->usesDefaultSignalSender = $signalSender === null;
        $this->pidFinder = $pidFinder === null
            ? $this->findFuzzerPids(...)
            : \Closure::fromCallable($pidFinder);
        $this->signalSender = $signalSender === null
            ? static fn (int $pid, int $signal): bool => posix_kill($pid, $signal)
            : \Closure::fromCallable($signalSender);
        $this->sleeper = $sleeper === null
            ? static function (int $microseconds): void {
                usleep($microseconds);
            }
            : \Closure::fromCallable($sleeper);
        $this->randomInteger = $randomInteger === null
            ? static fn (int $minimum, int $maximum): int => random_int($minimum, $maximum)
            : \Closure::fromCallable($randomInteger);
        $this->clock = $clock === null
            ? static fn (): float => hrtime(true) / 1_000_000_000
            : \Closure::fromCallable($clock);
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        try {
            $options = Options::parse($arguments, self::VALUE_OPTIONS, self::FLAG_OPTIONS);

            if ($options->has('help')) {
                $this->write(self::HELP);
                return 0;
            }

            $signals = $this->signals($options->string('signals', 'SIGINT,SIGTERM,SIGQUIT'));
            [$minimumSleep, $maximumSleep] = $this->sleepRange($options->string('sleep', '0.8-1.2'));
            $rate = $options->number('rate', 100.0);
            if ($rate < 0.0 || $rate > 100.0) {
                throw new \InvalidArgumentException('--rate must be between 0.0 and 100.0');
            }

            $this->checkCapabilities();
            $started = ($this->clock)();
            $iterations = 0;

            while (true) {
                $pids = ($this->pidFinder)();
                if ($pids === []) {
                    $this->log($started, 'No phpredis-fuzz processes found');
                }

                foreach ($pids as $pid) {
                    if (!$this->shouldSignal($rate)) {
                        $this->log($started, "Not signaling {$pid} (rate roll)");
                        continue;
                    }

                    $signal = $signals[($this->randomInteger)(0, count($signals) - 1)];
                    $this->log($started, "Sending {$signal['name']} to {$pid}");
                    if (!(($this->signalSender)($pid, $signal['number']))) {
                        $this->logSignalFailure($started, $signal['name'], $pid);
                    }
                }

                $sleep = ($this->randomInteger)($minimumSleep, $maximumSleep);
                $this->log($started, "Sleeping for {$sleep}us");
                ($this->sleeper)($sleep);

                $iterations++;
                if ($this->iterationLimit !== null && $iterations >= $this->iterationLimit) {
                    return 0;
                }
            }
        } catch (\Throwable $throwable) {
            $this->write('phpredis-fuzz-killer: ' . $throwable->getMessage() . "\n", true);
            return 1;
        }
    }

    /**
     * @return non-empty-list<array{name: string, number: int}>
     */
    private function signals(string $value): array
    {
        $signals = [];
        $numbers = [];
        foreach (Options::split($value) as $name) {
            $name = strtoupper($name);
            if (!str_starts_with($name, 'SIG')) {
                $name = 'SIG' . $name;
            }

            $number = self::LINUX_SIGNALS[$name] ?? null;
            if ($number === null) {
                throw new \InvalidArgumentException("Unknown Linux signal: {$name}");
            }
            if (isset($numbers[$number])) {
                continue;
            }

            $numbers[$number] = true;
            $signals[] = ['name' => $name, 'number' => $number];
        }

        if ($signals === []) {
            throw new \InvalidArgumentException('--signals cannot be empty');
        }

        return $signals;
    }

    /** @return array{int, int} */
    private function sleepRange(string $value): array
    {
        $number = '(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)';
        if (preg_match("/^({$number})-({$number})$/", trim($value), $matches) !== 1) {
            throw new \InvalidArgumentException('--sleep must be a MIN-MAX range in seconds');
        }

        $minimum = (float) $matches[1];
        $maximum = (float) $matches[2];
        if ($minimum > $maximum) {
            throw new \InvalidArgumentException('--sleep minimum must not exceed maximum');
        }
        if ($maximum > PHP_INT_MAX / 1_000_000) {
            throw new \InvalidArgumentException('--sleep range is too large');
        }

        return [(int) round($minimum * 1_000_000), (int) round($maximum * 1_000_000)];
    }

    private function shouldSignal(float $rate): bool
    {
        if ($rate <= 0.0) {
            return false;
        }
        if ($rate >= 100.0) {
            return true;
        }

        return ($this->randomInteger)(1, 1_000_000) <= $rate * 10_000;
    }

    private function checkCapabilities(): void
    {
        if ($this->usesDefaultPidFinder && (PHP_OS_FAMILY !== 'Linux' || !is_dir('/proc'))) {
            throw new \RuntimeException('process discovery requires Linux /proc');
        }
        if ($this->usesDefaultSignalSender && !function_exists('posix_kill')) {
            throw new \RuntimeException('sending signals requires the PHP POSIX extension');
        }
    }

    /** @return list<int> */
    private function findFuzzerPids(): array
    {
        $entries = scandir('/proc');
        if ($entries === false) {
            throw new \RuntimeException('unable to scan /proc');
        }

        $ownPid = getmypid();
        $pids = [];
        foreach ($entries as $entry) {
            if (!ctype_digit($entry)) {
                continue;
            }

            $pid = (int) $entry;
            if ($pid === $ownPid) {
                continue;
            }

            $commandLine = @file_get_contents("/proc/{$entry}/cmdline");
            if ($commandLine === false || $commandLine === '') {
                continue;
            }

            $arguments = array_filter(
                explode("\0", $commandLine),
                static fn (string $argument): bool => $argument !== '',
            );
            foreach ($arguments as $argument) {
                if (basename($argument) === 'phpredis-fuzz') {
                    $pids[] = $pid;
                    break;
                }
            }
        }

        sort($pids, SORT_NUMERIC);
        return $pids;
    }

    private function logSignalFailure(float $started, string $signal, int $pid): void
    {
        $detail = '';
        if ($this->usesDefaultSignalSender && function_exists('posix_get_last_error')) {
            $error = posix_get_last_error();
            if ($error !== 0) {
                $detail = ': ' . posix_strerror($error);
            }
        }

        $this->log($started, "Failed to send {$signal} to {$pid}{$detail}", true);
    }

    private function log(float $started, string $message, bool $error = false): void
    {
        $elapsed = max(0, (int) floor(($this->clock)() - $started));
        $hours = intdiv($elapsed, 3600);
        $minutes = intdiv($elapsed % 3600, 60);
        $seconds = $elapsed % 60;
        $this->write(sprintf('[%02d:%02d:%02d] %s', $hours, $minutes, $seconds, $message) . "\n", $error);
    }

    private function write(string $message, bool $error = false): void
    {
        fwrite($error ? $this->error : $this->output, $message);
    }

    private const HELP = <<<'HELP'
phpredis-fuzz-killer - randomly signal running phpredis-fuzz processes

Usage:
  phpredis-fuzz-killer [options]

Options:
  --signals=NAME,...        Linux signals to choose from (default: SIGINT,SIGTERM,SIGQUIT)
                             Names are case-insensitive and the SIG prefix is optional
  --sleep=MIN-MAX           Random sleep range in seconds (default: 0.8-1.2)
  --rate=PERCENT            Chance of signaling each discovered PID per iteration,
                             from 0.0 through 100.0 (default: 100.0)
  --help                     Show this help

The utility finds processes with a command-line argument whose basename is
exactly "phpredis-fuzz". It logs every signal, skipped PID, failure, empty scan,
and sleep. It runs until stopped.

SIGKILL, SIGSTOP, fatal signals, and realtime signals are accepted when named.
Use this only for disposable fuzzer workers; signals such as SIGQUIT may leave
core dumps or other diagnostic artifacts.
HELP;
}
