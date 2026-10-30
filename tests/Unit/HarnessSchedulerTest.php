<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Harness\CommandTemplate;
use Mgrunder\RedisClientTester\Harness\CorePattern;
use Mgrunder\RedisClientTester\Harness\DashboardState;
use Mgrunder\RedisClientTester\Harness\Fs;
use Mgrunder\RedisClientTester\Harness\HarnessOptions;
use Mgrunder\RedisClientTester\Harness\JobRunner;
use Mgrunder\RedisClientTester\Harness\PortPool;
use Mgrunder\RedisClientTester\Harness\Reducer;
use Mgrunder\RedisClientTester\Harness\ReproStore;
use Mgrunder\RedisClientTester\Harness\Scheduler;
use Mgrunder\RedisClientTester\Harness\Stats;
use Mgrunder\RedisClientTester\Harness\View;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HarnessSchedulerTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function unconfirmedReplays(): iterable
    {
        yield 'passing replay' => [0];
        yield 'different failure' => [124];
    }

    #[RunInSeparateProcess]
    #[DataProvider('unconfirmedReplays')]
    public function testFinalReplayMustConfirmTheReduction(int $exit): void
    {
        $root = sys_get_temp_dir() . '/scheduler-unconfirmed-' . getmypid();
        mkdir($root);
        file_put_contents($root . '/child.php', <<<'PHP'
<?php
$counter = __DIR__ . '/count';
$count = is_file($counter) ? (int) file_get_contents($counter) : 0;
file_put_contents($counter, (string) ($count + 1));
echo "replay {$count}\n";
exit($count === 3 ? (int) $argv[1] : 1);
PHP);
        $command = [$root . '/child.php', (string) $exit, '{steps}'];
        $options = HarnessOptions::parse([
            '--jobs=1', '--runs=1', '--steps=4', '--seed=42', '--capture=failures', '--reduce=steps',
            '--', ...$command,
        ]);
        $store = new ReproStore($root . '/out', new CorePattern('core.%e.%p', false), $root);
        $runner = new JobRunner($options, PHP_BINARY, [], $root, new CommandTemplate($command),
            $store, $root . '/work', null, PHP_VERSION);
        $stats = new Stats();
        $view = $this->createStub(View::class);
        $notes = [];
        $view->method('note')->willReturnCallback(static function (string $note) use (&$notes): void {
            $notes[] = $note;
        });
        $scheduler = new Scheduler($options, $root . '/out', $runner, new Reducer($runner, 10), $store,
            new PortPool([], 'cycle', true), $stats, $view, PHP_VERSION);
        try {
            self::assertSame(1, $scheduler->run());
            self::assertSame(4, $runner->launched());
            self::assertSame(0, $stats->reductions);
            self::assertStringContainsString('Final reduction replay did not reproduce', implode("\n", $notes));
            $captures = glob($root . '/out/failure/*') ?: [];
            self::assertCount(1, $captures);
            self::assertDirectoryDoesNotExist($captures[0] . '/minimized');
            self::assertSame("replay 2\n", file_get_contents($root . '/work/reduce-00003/stdout.log'));
            self::assertSame("replay 3\n", file_get_contents($root . '/work/reduce-00004/stdout.log'));
        } finally {
            $runner->cleanup();
            Fs::removeTree($root);
        }
    }

    /** @return iterable<string, array{string, int, int, int}> */
    public static function shutdownPhases(): iterable
    {
        yield 'normal completion' => ['none', 1, 4, 1];
        yield 'running inferior' => ['running', 0, 1, 0];
        yield 'before capture' => ['capture', 0, 1, 0];
        yield 'waiting for trace' => ['trace', 0, 1, 0];
        yield 'reduction replay' => ['reduce', 1, 2, 0];
        yield 'final replay' => ['final', 1, 4, 0];
    }

    #[RunInSeparateProcess]
    #[DataProvider('shutdownPhases')]
    public function testShutdownCancelsWorkAndKeepsArtifacts(string $phase, int $captures, int $launched, int $reductions): void
    {
        $root = sys_get_temp_dir() . '/redis-client-tester-scheduler-' . getmypid();
        $command = [dirname(__DIR__) . '/Fixtures/harness-child.php', '1', '0.3', '{steps}'];
        $options = HarnessOptions::parse([
            '--jobs=1', '--runs=' . ($phase === 'none' ? 1 : 2),
            '--steps=4', '--seed=42', '--capture=failures,crashes', '--reduce=steps',
            ...($phase === 'trace' ? ['--rr', '--trace-timeout=60'] : []),
            '--', ...$command,
        ]);
        $store = new ReproStore($root . '/out', new CorePattern('core.%e.%p', false), $root);
        $runner = new JobRunner(
            $options, PHP_BINARY, [], $root, new CommandTemplate($command),
            $store, $root . '/work',
            $phase === 'trace' ? dirname(__DIR__) . '/Fixtures/fake-rr-quiet-abort.sh' : null,
            PHP_VERSION,
        );
        $stats = new Stats();
        $view = $this->createMock(View::class);
        $view->expects(self::once())->method('start');
        $view->expects(self::once())->method('stop');
        $reducing = [];
        $recording = [];
        $quit = false;
        $quitSent = false;
        $capturing = 0;
        $view->method('render')->willReturnCallback(static function (Stats $stats, DashboardState $state) use (
            $runner, $phase, &$reducing, &$recording, &$quit, &$capturing,
        ): void {
            foreach ($state->active as $job) {
                if ($phase === 'running' && $job->isRunning()) {
                    $quit = true;
                }
                if ($job->note === 'capturing…') {
                    $capturing++;
                    $quit = $phase === 'capture' || ($phase === 'trace' && $capturing >= 3);
                }
                if ($job->note === 'reducing…' && $runner->launched() > 1) {
                    $reducing[] = $stats->elapsed();
                    $quit = $phase === 'reduce' && count($reducing) >= 3;
                }
                if ($job->note === 'recording minimum…' && $runner->launched() === 4) {
                    $recording[] = $stats->elapsed();
                    $quit = $phase === 'final' && count($recording) >= 3;
                }
            }
        });
        $view->method('quitRequested')->willReturnCallback(static function () use (&$quit, &$quitSent): bool {
            if (!$quit || $quitSent) {
                return false;
            }
            $quitSent = true;
            return true;
        });
        $scheduler = new Scheduler(
            $options, $root . '/out', $runner, new Reducer($runner, 10.0), $store,
            new PortPool([], 'cycle', true), $stats, $view, PHP_VERSION,
        );

        ob_start();
        try {
            self::assertSame($captures > 0 ? 1 : 0, $scheduler->run());
            self::assertSame($phase !== 'none', $scheduler->isStopping());
            self::assertSame($phase !== 'none', $quitSent);
            self::assertLessThan(5.0, $stats->elapsed(), 'shutdown must not wait for the trace timeout');
            if ($phase === 'none') {
                if ($reducing === [] || $recording === []) {
                    self::fail('expected refreshes during both reduction and the final replay');
                }
                self::assertGreaterThan(2, count($reducing));
                self::assertGreaterThan(0.2, max($reducing) - min($reducing));
                self::assertGreaterThan(2, count($recording));
                self::assertGreaterThan(0.2, max($recording) - min($recording));
            }
            self::assertSame(1, $stats->started);
            self::assertSame($captures, $stats->completed);
            self::assertSame($captures, $stats->reproducers);
            self::assertSame($reductions, $stats->reductions);
            self::assertSame($launched, $runner->launched());
            $directories = glob($root . '/out/failure/*') ?: [];
            self::assertCount($captures, $directories);
            foreach ($directories as $directory) {
                self::assertFileExists($directory . '/stderr.log');
                self::assertSame($reductions > 0, is_file($directory . '/minimized/meta.json'));
            }
            if ($phase !== 'none') {
                self::assertNotEmpty(glob($root . '/work/*/stderr.log'));
            }
        } finally {
            ob_end_clean();
            $runner->cleanup();
            Fs::removeTree($root);
        }
    }
}
