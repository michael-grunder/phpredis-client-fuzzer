<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Cli;

use Mgrunder\PhpredisCommandFuzzer\Harness\CommandTemplate;
use Mgrunder\PhpredisCommandFuzzer\Harness\CorePattern;
use Mgrunder\PhpredisCommandFuzzer\Harness\Fs;
use Mgrunder\PhpredisCommandFuzzer\Harness\HarnessOptions;
use Mgrunder\PhpredisCommandFuzzer\Harness\JobRunner;
use Mgrunder\PhpredisCommandFuzzer\Harness\PlainView;
use Mgrunder\PhpredisCommandFuzzer\Harness\PortPool;
use Mgrunder\PhpredisCommandFuzzer\Harness\Reducer;
use Mgrunder\PhpredisCommandFuzzer\Harness\ReproStore;
use Mgrunder\PhpredisCommandFuzzer\Harness\Scheduler;
use Mgrunder\PhpredisCommandFuzzer\Harness\Stats;
use Mgrunder\PhpredisCommandFuzzer\Harness\TuiView;
use Mgrunder\PhpredisCommandFuzzer\Harness\View;

/**
 * Entry point for `bin/phpredis-fuzz-harness`: validates the environment,
 * wires the campaign components together, and hands control to the
 * {@see Scheduler}.
 *
 * @internal Kept out of the binary so it can be unit tested.
 */
final class HarnessApplication
{
    /** @var resource */
    private $output;

    /** @var resource */
    private $error;

    /**
     * @param resource|null $output Defaults to STDOUT.
     * @param resource|null $error Defaults to STDERR.
     */
    public function __construct($output = null, $error = null)
    {
        $this->output = $output ?? STDOUT;
        $this->error = $error ?? STDERR;
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        try {
            $options = HarnessOptions::parse($arguments);

            if ($options->help) {
                $this->write(self::HELP);
                return 0;
            }

            return $this->execute($options);
        } catch (\Throwable $throwable) {
            $this->write('phpredis-fuzz-harness: ' . $throwable->getMessage() . "\n", true);
            return 1;
        }
    }

    private function execute(HarnessOptions $options): int
    {
        $template = new CommandTemplate($options->command);
        $template->validate();

        $php = $this->locate($options->php);
        if ($php === null) {
            throw new \RuntimeException("php binary not found or not executable: {$options->php}");
        }

        $rrBinary = null;
        if ($options->rr) {
            $rrBinary = $this->locate('rr');
            if ($rrBinary === null) {
                throw new \RuntimeException('--rr requires the rr binary on PATH');
            }
        }

        $core = $this->resolveCorePattern($options);

        if ($template->uses('port') && $options->ports === []) {
            throw new \RuntimeException('the command uses {port} but no --port was given');
        }
        if ($options->reduce === 'steps' && !$template->uses('steps')) {
            throw new \RuntimeException('--reduce steps requires the command to contain {steps}');
        }
        if ($options->ports !== [] && !$template->uses('port')) {
            $this->write("warning: --port is set but the command has no {port} placeholder\n", true);
        }

        $baseDir = getcwd();
        if ($baseDir === false) {
            throw new \RuntimeException('cannot determine the current working directory');
        }

        $outputDir = $this->absolute($options->output);
        Fs::ensureDir($outputDir);
        $workRoot = $outputDir . '/.work';
        Fs::ensureDir($workRoot);

        if ($options->capturesLeaks() && !$this->phpIsDebug($php)) {
            $this->write(
                "warning: --capture leaks needs a debug PHP build; this php will not emit Zend MM leak reports\n",
                true,
            );
        }

        $phpVersion = $this->probePhp($php);
        $this->writeRunInfo($options, $outputDir, $template, $phpVersion, $rrBinary, $core);

        $store = new ReproStore($outputDir, $core, $baseDir);
        $runner = new JobRunner($options, $php, $baseDir, $template, $store, $workRoot, $rrBinary, $phpVersion);
        $reducer = new Reducer($runner, $options->reduceTimeout);
        $ports = new PortPool($options->ports, $options->portSelect, $options->isolatePorts);
        $stats = new Stats();
        $view = $this->makeView($options);

        $scheduler = new Scheduler($options, $outputDir, $runner, $reducer, $store, $ports, $stats, $view, $phpVersion);

        try {
            return $scheduler->run();
        } finally {
            if (!$options->keepWork) {
                Fs::removeTree($workRoot);
            }
        }
    }

    private function resolveCorePattern(HarnessOptions $options): CorePattern
    {
        $core = CorePattern::fromSystem();

        if ($options->noCoreCheck) {
            return $core ?? new CorePattern('core', false);
        }

        if ($core === null) {
            throw new \RuntimeException(
                'cannot read /proc/sys/kernel/core_pattern; pass --no-core-check to skip core collection',
            );
        }
        if (!$core->isolatesByPid()) {
            throw new \RuntimeException(sprintf(
                'core_pattern (%s) must contain %%p so concurrent runs can be told apart; '
                . "set it with e.g.  echo 'core.%%e.%%p' | sudo tee /proc/sys/kernel/core_pattern  "
                . '(or pass --no-core-check)',
                $core->raw,
            ));
        }
        if ($core->isPiped()) {
            $this->write(
                "warning: core_pattern pipes to a handler; core files will not be collected automatically\n",
                true,
            );
        }

        return $core;
    }

    private function makeView(HarnessOptions $options): View
    {
        $interactive = !$options->noTui
            && \function_exists('stream_isatty')
            && stream_isatty($this->output)
            && stream_isatty(STDIN);

        if (!$interactive) {
            return new PlainView($options->quiet);
        }

        try {
            $view = new TuiView();
            $view->start();
            return $view;
        } catch (\Throwable $throwable) {
            $this->write('warning: could not start the TUI (' . $throwable->getMessage() . "), using plain output\n", true);
            return new PlainView($options->quiet);
        }
    }

    private function writeRunInfo(
        HarnessOptions $options,
        string $outputDir,
        CommandTemplate $template,
        string $phpVersion,
        ?string $rrBinary,
        CorePattern $core,
    ): void {
        $info = [
            'started: ' . date('c'),
            'php: ' . $phpVersion,
            'php binary: ' . $options->php,
            'jobs: ' . $options->jobs,
            'steps: ' . $options->steps,
            'ports: ' . ($options->ports === [] ? '(none)' : implode(', ', $options->ports)),
            'port select: ' . $options->portSelect . ($options->isolatePorts ? ' (isolated)' : ' (shared)'),
            'rr: ' . ($rrBinary ?? 'disabled') . ($options->rrChaos ? ' --chaos' : ''),
            'capture: ' . implode(', ', $options->capture),
            'reduce: ' . ($options->reduce ?? 'disabled'),
            'core_pattern: ' . $core->raw,
            'command: ' . implode(' ', $template->tokens()),
        ];

        file_put_contents($outputDir . '/run-info.txt', implode("\n", $info) . "\n");
    }

    private function probePhp(string $php): string
    {
        $version = @shell_exec(escapeshellarg($php) . ' -v 2>/dev/null');
        $first = is_string($version) ? strtok($version, "\n") : false;
        $line = $first === false ? 'unknown php' : trim($first);

        $extensions = @shell_exec(
            escapeshellarg($php) . ' -r '
            . escapeshellarg(
                'foreach (["redis","relay"] as $e) '
                . '{ echo $e, "=", extension_loaded($e) ? (string) phpversion($e) : "n/a", " "; }',
            )
            . ' 2>/dev/null',
        );

        return is_string($extensions) && trim($extensions) !== ''
            ? $line . '  (' . trim($extensions) . ')'
            : $line;
    }

    /** Whether $php is a debug build (only those emit Zend MM leak reports). */
    private function phpIsDebug(string $php): bool
    {
        $out = @shell_exec(
            escapeshellarg($php) . ' -r ' . escapeshellarg('echo PHP_DEBUG ? "1" : "0";') . ' 2>/dev/null',
        );

        return is_string($out) && trim($out) === '1';
    }

    private function locate(string $name): ?string
    {
        if (str_contains($name, '/')) {
            return is_file($name) && is_executable($name) ? $name : null;
        }

        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return null;
        }
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }
            $candidate = $directory . '/' . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function absolute(string $path): string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            $cwd = '.';
        }
        if ($path === '') {
            return $cwd;
        }
        if ($path[0] === '/') {
            return $path;
        }

        return $cwd . '/' . $path;
    }

    private function write(string $message, bool $error = false): void
    {
        fwrite($error ? $this->error : $this->output, $message);
    }

    private const HELP = <<<'HELP'
phpredis-fuzz-harness - run many phpredis-fuzz workers in parallel with a live
dashboard, capturing crashes, hangs, memory leaks, and non-zero exits as
reproducers.

Usage:
  phpredis-fuzz-harness [harness options] -- <fuzzer command template...>

The template is a normal phpredis-fuzz* command line. These placeholders are
substituted per run:
  {port}   a port chosen from --port
  {steps}  the current step budget (see --steps / --reduce)
  {seed}   the seed for this run (also appended as --seed=N when absent)
  {job}    the job slot index (0 .. jobs-1)
  {run}    the global run counter

Harness options:
  --jobs N              Concurrent runs (default: 1)
  --php PATH            PHP binary to run the fuzzer with (default: this PHP)
  --port P [P ...]      One or more Redis ports; also accepts --port=P,P
  --port-select MODE    cycle (round-robin, default) or random
  --isolate-ports       Never hand one port to two running jobs at once
  --steps N             Step budget substituted for {steps} (default: 5000)
  --seed N              Base seed; run i uses N+i (default: random per run)
  --reduce steps        On capture, binary-search the smallest {steps} that
                        still reproduces, and store it under <repro>/minimized
  --runs N              Stop after N runs have been started (default: unlimited)
  --seconds N           Stop after N seconds (default: unlimited)
  --reproducers N       Stop after N reproducers captured (default: unlimited)
  --capture LIST        Comma list of what to save as reproducers (default:
                        crashes). Kinds: crashes (crashing signals), leaks
                        (Zend MM leak reports from a debug PHP build), failures
                        (non-zero exits and --run-timeout hangs). e.g.
                        --capture crashes,leaks
  --run-timeout N       Kill and capture a single run after N seconds (0: off)
  --rr                  Record each run with `rr record`
  --rr-chaos            Imply --rr and pass --chaos to it
  --trace-timeout N     Max seconds to wait for an rr trace to finalise (60)
  --reduce-timeout N    Time budget for one reduction (default: 120)
  --output DIR          Where reproducers are written (default: ./phpredis-fuzz-repros)
  --keep-work           Keep per-run work directories and traces even on success
  --no-tui              Force plain line output
  --quiet               Plain output: only print failures and periodic summaries
  --no-core-check       Skip the core_pattern must-contain-%p check
  --help                Show this help

The kernel core_pattern must contain %p (so a core can be matched to the run
that produced it) unless --no-core-check is given.

Example:
  phpredis-fuzz-harness \
      --jobs 4 --reduce steps --rr --rr-chaos --capture crashes,leaks \
      --php "$(farmroot)/sapi/cli/php" \
      --port 7000 7001 7002 7003 --isolate-ports \
      -- \
      bin/phpredis-fuzz-coercive \
          --client=relay-cluster --seconds 2 --steps {steps} \
          --host=127.0.0.1 --port={port}

Only ever point this at a disposable Redis target.
HELP;
}
