<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Harness;

/**
 * Parses the harness command line, which has the shape:
 *
 *   phpredis-fuzz-harness [harness options] -- <fuzzer command template...>
 *
 * Everything before the first bare `--` configures the harness; everything
 * after is the child command, with `{port}` / `{steps}` / `{seed}` style
 * placeholders resolved per run. Unlike {@see \Mgrunder\PhpredisCommandFuzzer\Cli\Options}
 * this parser supports options that take more than one value (`--port 7000 8000`).
 */
final class HarnessOptions
{
    /** @var list<string> */
    private const FLAGS = [
        'help', 'rr', 'rr-chaos', 'isolate-ports',
        'keep-work', 'no-tui', 'no-core-check', 'quiet',
    ];

    /** @var list<string> */
    private const SINGLE = [
        'jobs', 'php', 'php-ini', 'reduce', 'steps', 'seed', 'output', 'runs', 'seconds',
        'reproducers', 'trace-timeout', 'reduce-timeout', 'run-timeout', 'port-select',
        'capture',
    ];

    /**
     * Options that take one value per occurrence and accumulate across them.
     * Each value is split into argv tokens, so both
     * `--php-args -dopcache.enable=0` and `--php-args="-d a=1 -d b=2"` work.
     *
     * @var list<string>
     */
    private const APPEND = ['php-args'];

    /** Categories accepted by --capture. */
    public const CAPTURE_KINDS = ['crashes', 'leaks', 'timeouts', 'failures'];

    /** @var list<string> */
    private const MULTI = ['port'];

    /**
     * @param list<string> $phpArgs
     * @param list<int> $ports
     * @param list<'crashes'|'leaks'|'timeouts'|'failures'> $capture
     * @param list<string> $command
     */
    private function __construct(
        public readonly bool $help,
        public readonly int $jobs,
        public readonly string $php,
        public readonly array $phpArgs,
        public readonly ?string $phpIni,
        public readonly ?string $reduce,
        public readonly int $steps,
        public readonly ?int $seed,
        public readonly string $output,
        public readonly int $maxRuns,
        public readonly float $maxSeconds,
        public readonly int $maxReproducers,
        public readonly float $traceTimeout,
        public readonly float $reduceTimeout,
        public readonly float $runTimeout,
        public readonly string $portSelect,
        public readonly array $ports,
        public readonly bool $isolatePorts,
        public readonly bool $rr,
        public readonly bool $rrChaos,
        public readonly array $capture,
        public readonly bool $keepWork,
        public readonly bool $noTui,
        public readonly bool $noCoreCheck,
        public readonly bool $quiet,
        public readonly array $command,
    ) {
    }

    /** Whether crashing signals are captured as reproducers. */
    public function capturesCrashes(): bool
    {
        return in_array('crashes', $this->capture, true);
    }

    /** Whether debug-build Zend MM leak reports are captured as reproducers. */
    public function capturesLeaks(): bool
    {
        return in_array('leaks', $this->capture, true);
    }

    /** Whether runs the harness killed for exceeding --run-timeout are captured. */
    public function capturesTimeouts(): bool
    {
        return in_array('timeouts', $this->capture, true);
    }

    /** Whether non-crash, non-timeout failures (the fuzzer's own non-zero exits) are captured. */
    public function capturesFailures(): bool
    {
        return in_array('failures', $this->capture, true);
    }

    /** @param list<string> $argv Arguments after the program name. */
    public static function parse(array $argv): self
    {
        $separator = array_search('--', $argv, true);
        $harnessArgs = $separator === false ? $argv : array_slice($argv, 0, $separator);
        $command = $separator === false ? [] : array_slice($argv, $separator + 1);

        /** @var array<string, string> $values */
        $values = [];
        /** @var array<string, list<string>> $lists */
        $lists = [];
        /** @var array<string, true> $flags */
        $flags = [];

        $count = count($harnessArgs);
        for ($index = 0; $index < $count; $index++) {
            $token = $harnessArgs[$index];
            if (!str_starts_with($token, '--')) {
                throw new \InvalidArgumentException("unexpected argument before --: {$token}");
            }

            $name = substr($token, 2);
            $inlineValue = null;
            if (str_contains($name, '=')) {
                [$name, $inlineValue] = explode('=', $name, 2);
            }

            if (in_array($name, self::FLAGS, true)) {
                if ($inlineValue !== null) {
                    throw new \InvalidArgumentException("--{$name} does not accept a value");
                }
                $flags[$name] = true;
                continue;
            }

            if (in_array($name, self::APPEND, true)) {
                if ($inlineValue !== null) {
                    $lists[$name][] = $inlineValue;
                    continue;
                }
                if ($index + 1 >= $count || str_starts_with($harnessArgs[$index + 1], '--')) {
                    // A value of its own that starts with -- has to use the
                    // --name=value form, or a stray option would be swallowed.
                    throw new \InvalidArgumentException("--{$name} requires a value");
                }
                $lists[$name][] = $harnessArgs[++$index];
                continue;
            }

            if (in_array($name, self::MULTI, true)) {
                if ($inlineValue !== null) {
                    foreach (explode(',', $inlineValue) as $piece) {
                        $lists[$name][] = trim($piece);
                    }
                    continue;
                }
                $consumed = 0;
                while ($index + 1 < $count && !str_starts_with($harnessArgs[$index + 1], '--')) {
                    $lists[$name][] = $harnessArgs[++$index];
                    $consumed++;
                }
                if ($consumed === 0) {
                    throw new \InvalidArgumentException("--{$name} requires at least one value");
                }
                continue;
            }

            if (in_array($name, self::SINGLE, true)) {
                if ($inlineValue !== null) {
                    $values[$name] = $inlineValue;
                    continue;
                }
                if ($index + 1 >= $count || str_starts_with($harnessArgs[$index + 1], '--')) {
                    throw new \InvalidArgumentException("--{$name} requires a value");
                }
                $values[$name] = $harnessArgs[++$index];
                continue;
            }

            throw new \InvalidArgumentException("unknown harness option: --{$name}");
        }

        $help = isset($flags['help']);
        if (!$help && $separator === false) {
            throw new \InvalidArgumentException(
                'expected -- followed by the fuzzer command, e.g.  -- bin/phpredis-fuzz --steps {steps}',
            );
        }
        if (!$help && $command === []) {
            throw new \InvalidArgumentException('the fuzzer command after -- is empty');
        }

        $rrChaos = isset($flags['rr-chaos']);
        $reduce = $values['reduce'] ?? null;
        if ($reduce !== null && $reduce !== 'steps') {
            throw new \InvalidArgumentException('only --reduce steps is supported');
        }

        $portSelect = $values['port-select'] ?? 'cycle';
        if (!in_array($portSelect, ['cycle', 'random'], true)) {
            throw new \InvalidArgumentException('--port-select must be cycle or random');
        }

        $capture = self::captureList($values['capture'] ?? 'crashes');

        $jobs = self::intValue($values, 'jobs', 1);
        if ($jobs < 1) {
            throw new \InvalidArgumentException('--jobs must be at least 1');
        }
        $steps = self::intValue($values, 'steps', 5000);
        if ($steps < 1) {
            throw new \InvalidArgumentException('--steps must be at least 1');
        }

        $php = $values['php'] ?? PHP_BINARY;
        $phpArgs = self::argvList($lists['php-args'] ?? []);
        $phpIni = $values['php-ini'] ?? null;
        if ($phpIni !== null && array_filter($phpArgs, static fn (string $a): bool => str_starts_with($a, '-c')) !== []) {
            throw new \InvalidArgumentException('--php-ini and -c in --php-args set the same thing; use one');
        }

        return new self(
            help: $help,
            jobs: $jobs,
            php: $php,
            phpArgs: $phpArgs,
            phpIni: $phpIni,
            reduce: $reduce,
            steps: $steps,
            seed: isset($values['seed']) ? self::intValue($values, 'seed', 0) : null,
            output: $values['output'] ?? 'phpredis-fuzz-repros',
            maxRuns: self::intValue($values, 'runs', 0),
            maxSeconds: self::floatValue($values, 'seconds', 0.0),
            maxReproducers: self::intValue($values, 'reproducers', 0),
            traceTimeout: self::floatValue($values, 'trace-timeout', 60.0),
            reduceTimeout: self::floatValue($values, 'reduce-timeout', 120.0),
            runTimeout: self::floatValue($values, 'run-timeout', 0.0),
            portSelect: $portSelect,
            ports: self::portList($lists['port'] ?? []),
            isolatePorts: isset($flags['isolate-ports']),
            rr: isset($flags['rr']) || $rrChaos,
            rrChaos: $rrChaos,
            capture: $capture,
            keepWork: isset($flags['keep-work']),
            noTui: isset($flags['no-tui']),
            noCoreCheck: isset($flags['no-core-check']),
            quiet: isset($flags['quiet']),
            command: $command,
        );
    }

    /**
     * Split the raw `--php-args` values into argv tokens.
     *
     * @param list<string> $raw
     * @return list<string>
     */
    private static function argvList(array $raw): array
    {
        $tokens = [];
        foreach ($raw as $value) {
            foreach (self::tokenize($value) as $token) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * A minimal shell-style splitter: whitespace separates tokens, single and
     * double quotes group them, and a backslash escapes the next character.
     * The child is launched with an argv array rather than a shell, so this is
     * the only place quoting is interpreted.
     *
     * @return list<string>
     */
    private static function tokenize(string $raw): array
    {
        $tokens = [];
        $current = '';
        $started = false;
        $quote = null;

        $length = strlen($raw);
        for ($index = 0; $index < $length; $index++) {
            $character = $raw[$index];

            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                } elseif ($quote === '"' && $character === '\\' && $index + 1 < $length
                    && ($raw[$index + 1] === '"' || $raw[$index + 1] === '\\')) {
                    $current .= $raw[++$index];
                } else {
                    $current .= $character;
                }
                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
                $started = true;
                continue;
            }
            if ($character === '\\' && $index + 1 < $length) {
                $current .= $raw[++$index];
                $started = true;
                continue;
            }
            if (ctype_space($character)) {
                if ($started) {
                    $tokens[] = $current;
                    $current = '';
                    $started = false;
                }
                continue;
            }

            $current .= $character;
            $started = true;
        }

        if ($quote !== null) {
            throw new \InvalidArgumentException("--php-args has an unterminated quote: {$raw}");
        }
        if ($started) {
            $tokens[] = $current;
        }

        return $tokens;
    }

    /** @param array<string, string> $values */
    private static function intValue(array $values, string $name, int $default): int
    {
        if (!isset($values[$name])) {
            return $default;
        }
        if (filter_var($values[$name], FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException("--{$name} must be an integer");
        }

        return (int) $values[$name];
    }

    /** @param array<string, string> $values */
    private static function floatValue(array $values, string $name, float $default): float
    {
        if (!isset($values[$name])) {
            return $default;
        }
        if (!is_numeric($values[$name])) {
            throw new \InvalidArgumentException("--{$name} must be numeric");
        }
        $number = (float) $values[$name];
        if ($number < 0.0) {
            throw new \InvalidArgumentException("--{$name} must not be negative");
        }

        return $number;
    }

    /**
     * @return list<'crashes'|'leaks'|'timeouts'|'failures'>
     */
    private static function captureList(string $raw): array
    {
        $requested = array_map('trim', explode(',', $raw));

        foreach ($requested as $kind) {
            if ($kind !== '' && !in_array($kind, self::CAPTURE_KINDS, true)) {
                throw new \InvalidArgumentException(
                    '--capture values must be one of ' . implode(', ', self::CAPTURE_KINDS) . ", got: {$kind}",
                );
            }
        }

        // Take the canonical order from CAPTURE_KINDS; this also deduplicates.
        $kinds = [];
        foreach (self::CAPTURE_KINDS as $kind) {
            if (in_array($kind, $requested, true)) {
                $kinds[] = $kind;
            }
        }

        if ($kinds === []) {
            throw new \InvalidArgumentException(
                '--capture needs at least one of ' . implode(', ', self::CAPTURE_KINDS),
            );
        }

        return $kinds;
    }

    /**
     * @param list<string> $raw
     * @return list<int>
     */
    private static function portList(array $raw): array
    {
        $ports = [];
        foreach ($raw as $value) {
            if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                throw new \InvalidArgumentException("--port values must be integers, got: {$value}");
            }
            $port = (int) $value;
            if ($port < 1 || $port > 65535) {
                throw new \InvalidArgumentException("--port {$port} is out of range");
            }
            if (!in_array($port, $ports, true)) {
                $ports[] = $port;
            }
        }

        return $ports;
    }
}
