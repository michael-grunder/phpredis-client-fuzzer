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
        $this->assertScriptExists($template);

        $php = $this->locate($options->php);
        if ($php === null) {
            throw new \RuntimeException("php binary not found or not executable: {$options->php}");
        }

        $phpIni = $options->phpIni === null ? null : $this->resolveIni($options->phpIni);
        $phpArgs = $phpIni === null ? $options->phpArgs : ['-c', $phpIni, ...$options->phpArgs];

        $rrBinary = null;
        if ($options->rr) {
            $rrBinary = $this->locate('rr');
            if ($rrBinary === null) {
                throw new \RuntimeException('--rr requires the rr binary on PATH');
            }
        }

        $setsid = $this->resolveDetach($php);
        if ($setsid === null && $options->rr) {
            $this->write(
                "warning: setsid not found or unusable; runs stay in the harness' process group, "
                . "where a terminal resize can abort an rr recording\n",
                true,
            );
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

        if ($options->capturesLeaks() && !$this->phpIsDebug($php, $phpArgs)) {
            $this->write(
                "warning: --capture leaks needs a debug PHP build; this php will not emit Zend MM leak reports\n",
                true,
            );
        }

        $phpVersion = $this->probePhp($php, $phpArgs);

        $store = new ReproStore($outputDir, $core, $baseDir, $phpIni);

        // Per-run work directories are named after the harness, the way capture
        // directories always have been. Several campaigns are meant to be able
        // to share one output directory, and a shared `.work` broke that
        // badly: two harnesses handed the same `.work/run-00042/rr-trace` to
        // two rr recorders, and each exiting harness deleted the whole tree,
        // including the live traces of a campaign still running. Either one
        // makes rr abort, which arrives as a SIGABRT that looks exactly like a
        // crash in the client under test.
        $workRoot = $outputDir . '/.work/' . $store->pid();
        Fs::ensureDir($workRoot);

        $this->writeRunInfo($options, $outputDir, $template, $phpVersion, $phpArgs, $rrBinary, $core, $store->pid(), $setsid, $workRoot);
        $runner = new JobRunner(
            $options,
            $php,
            $phpArgs,
            $baseDir,
            $template,
            $store,
            $workRoot,
            $rrBinary,
            $phpVersion,
            $setsid,
        );
        $reducer = new Reducer($runner, $options->reduceTimeout);
        $ports = new PortPool($options->ports, $options->portSelect, $options->isolatePorts);
        $stats = new Stats();
        $view = $this->makeView($options);

        $scheduler = new Scheduler($options, $outputDir, $runner, $reducer, $store, $ports, $stats, $view, $phpVersion);

        try {
            return $scheduler->run();
        } finally {
            if (!$options->keepWork) {
                // Only this campaign's own subtree: another harness may still
                // be recording into its own. The shared parent goes away with
                // the last campaign to leave, and stays if one is still there.
                Fs::removeTree($workRoot);
                @rmdir(dirname($workRoot));
            }
        }
    }

    /**
     * The script the template actually runs. {@see JobRunner} always launches it
     * through the chosen PHP binary, so a mistyped path would turn every run of
     * the campaign into "Could not open input file"; catch it before spawning
     * anything.
     */
    private function assertScriptExists(CommandTemplate $template): void
    {
        $tokens = $template->tokens();
        $script = $tokens[0] ?? '';
        if (preg_match('/^php\d*(?:\.\d+)?$/', basename($script)) === 1) {
            $script = $tokens[1] ?? '';
        }

        if ($script === '') {
            throw new \RuntimeException('the fuzzer command after -- has no script to run');
        }
        if (str_contains($script, '{')) {
            return; // built from placeholders; only resolvable per run
        }
        if (!is_file($script)) {
            throw new \RuntimeException("fuzzer script not found: {$script}");
        }
    }

    /**
     * A missing or unreadable `--php-ini` would silently change every run's
     * configuration — PHP warns about the path and then carries on with its
     * built-in defaults — so it is checked before anything is spawned. The
     * absolute path is what gets recorded with a reproducer.
     */
    private function resolveIni(string $path): string
    {
        if (!is_file($path)) {
            throw new \RuntimeException("php ini file not found: {$path}");
        }
        if (!is_readable($path)) {
            throw new \RuntimeException("php ini file is not readable: {$path}");
        }

        $resolved = realpath($path);

        return $resolved === false ? $this->absolute($path) : $resolved;
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

    /** @param list<string> $phpArgs */
    private function writeRunInfo(
        HarnessOptions $options,
        string $outputDir,
        CommandTemplate $template,
        string $phpVersion,
        array $phpArgs,
        ?string $rrBinary,
        CorePattern $core,
        int $pid,
        ?string $setsid,
        string $workRoot,
    ): void {
        $info = [
            'started: ' . date('c'),
            'harness pid: ' . $pid,
            'php: ' . $phpVersion,
            'php binary: ' . $options->php,
            'php args: ' . ($phpArgs === [] ? '(none)' : ReproStore::formatCommand($phpArgs)),
            'jobs: ' . $options->jobs,
            'steps: ' . $options->steps,
            'ports: ' . ($options->ports === [] ? '(none)' : implode(', ', $options->ports)),
            'port select: ' . $options->portSelect . ($options->isolatePorts ? ' (isolated)' : ' (shared)'),
            'rr: ' . ($rrBinary ?? 'disabled') . ($options->rrChaos ? ' --chaos' : ''),
            'detach: ' . ($setsid ?? 'no (runs share the harness process group)'),
            'capture: ' . implode(', ', $options->capture),
            'run timeout: ' . ($options->runTimeout > 0.0 ? $options->runTimeout . 's' : 'disabled'),
            'reduce: ' . ($options->reduce ?? 'disabled'),
            'core_pattern: ' . $core->raw,
            'work dir: ' . $workRoot,
            'command: ' . implode(' ', $template->tokens()),
        ];

        // Named after the pid, like the capture directories, so a second
        // harness sharing this output directory cannot overwrite the record of
        // what the first one was running.
        file_put_contents($outputDir . '/' . $pid . '.run-info.txt', implode("\n", $info) . "\n");
    }

    /**
     * A `setsid(1)` that is safe to prefix every child with, or null.
     *
     * Running each run in its own session keeps the terminal's signals — Ctrl+C
     * and, more damagingly, the SIGWINCH of a window resize that makes rr abort
     * mid-spawn — away from the children. `setsid` only forks when it is
     * already a process group leader, which a child of ours never is, so the
     * pid the harness tracks stays the run's own pid and its exit status and
     * fatal signal still arrive intact. That is the property everything else
     * here depends on, so it is verified rather than assumed: a setsid that
     * forks would report exit 0 immediately for every run.
     */
    private function resolveDetach(string $php): ?string
    {
        $setsid = $this->locate('setsid');
        if ($setsid === null) {
            return null;
        }

        $code = 0;
        $output = [];
        @exec(
            escapeshellarg($setsid) . ' ' . escapeshellarg($php)
            . ' -r ' . escapeshellarg('exit(42);') . ' 2>/dev/null',
            $output,
            $code,
        );

        return $code === 42 ? $setsid : null;
    }

    /** @param list<string> $phpArgs */
    private function probePhp(string $php, array $phpArgs): string
    {
        $binary = self::shellCommand($php, $phpArgs);
        $version = @shell_exec($binary . ' -v 2>/dev/null');
        $first = is_string($version) ? strtok($version, "\n") : false;
        $line = $first === false ? 'unknown php' : trim($first);

        $extensions = @shell_exec(
            $binary . ' -r '
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

    /**
     * Whether $php is a debug build (only those emit Zend MM leak reports).
     *
     * @param list<string> $phpArgs
     */
    private function phpIsDebug(string $php, array $phpArgs): bool
    {
        $out = @shell_exec(
            self::shellCommand($php, $phpArgs)
            . ' -r ' . escapeshellarg('echo PHP_DEBUG ? "1" : "0";') . ' 2>/dev/null',
        );

        return is_string($out) && trim($out) === '1';
    }

    /**
     * The php binary plus its startup arguments, escaped for a shell probe, so
     * a probe sees exactly the configuration the runs will use.
     *
     * @param list<string> $phpArgs
     */
    private static function shellCommand(string $php, array $phpArgs): string
    {
        return implode(' ', array_map(escapeshellarg(...), [$php, ...$phpArgs]));
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
dashboard, capturing crashes, timeouts, memory leaks, and non-zero exits as
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
  --php-args ARGS       Extra PHP startup arguments placed before the fuzzer
                        script, e.g. --php-args -drelay.maxmemory=1g. May be
                        repeated, and a value holding several arguments is
                        split like a shell would: --php-args="-d a=1 -d b=2"
  --php-ini FILE        Run each fuzzer with this php.ini (shorthand for
                        --php-args "-c FILE"). The file must exist, and a copy
                        of it is stored with every reproducer as php.ini
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
                        (leak reports from a debug PHP build or Relay's shared
                        allocator), timeouts (runs killed for exceeding
                        --run-timeout), failures (any other non-zero exit).
                        e.g. --capture crashes,leaks,timeouts
  --run-timeout N       Kill a run once it has run for N seconds (0: off);
                        SIGTERM then SIGKILL. Save these with --capture timeouts
  --rr                  Record each run with `rr record`
  --rr-chaos            Imply --rr and pass --chaos to it
  --trace-timeout N     Max seconds to wait for an rr trace to finalise (60).
                        A trace that never finalises is not a reproducer: the
                        run is parked under <output>/failed and counted as a
                        failed reproducer instead. A run rr died on of its own
                        accord is not a finding at all: it is discarded and
                        logged in <output>/rr-aborts.log
  --reduce-timeout N    Time budget for one reduction (default: 120)
  --output DIR          Where reproducers are written (default: ./phpredis-fuzz-repros)
  --keep-work           Keep per-run work directories and traces even on success
  --no-tui              Force plain line output
  --quiet               Plain output: only print failures and periodic summaries
  --no-core-check       Skip the core_pattern must-contain-%p check
  --help                Show this help

The kernel core_pattern must contain %p (so a core can be matched to the run
that produced it) unless --no-core-check is given.

A run that exits 78 is a startup failure: the fuzzer rejected its command line
and never executed a command. Because every later run would be rejected the same
way, the harness stops the campaign and prints the fuzzer's own error message
along with the command it was given.

Example:
  phpredis-fuzz-harness \
      --jobs 4 --reduce steps --rr --rr-chaos \
      --capture crashes,leaks,timeouts --run-timeout 120 \
      --php "$(farmroot)/sapi/cli/php" \
      --php-ini "$(farmroot)/php.ini" --php-args -drelay.maxmemory=1g \
      --port 7000 7001 7002 7003 --isolate-ports \
      -- \
      bin/phpredis-fuzz-coercive \
          --client=relay-cluster --seconds 2 --steps {steps} \
          --host=127.0.0.1 --port={port}

Only ever point this at a disposable Redis target.
HELP;
}
