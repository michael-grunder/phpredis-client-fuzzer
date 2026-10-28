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
use PHPUnit\Framework\TestCase;

final class HarnessSchedulerTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testReductionRefreshesTheViewAndAcceptsGracefulQuitBeforeItFinishes(): void
    {
        $root = sys_get_temp_dir() . '/redis-client-tester-scheduler-' . getmypid();
        $command = [dirname(__DIR__) . '/Fixtures/harness-child.php', '1', '0.3', '{steps}'];
        $options = HarnessOptions::parse([
            '--jobs=1', '--runs=2', '--steps=4', '--seed=42', '--capture=failures', '--reduce=steps',
            '--', ...$command,
        ]);
        $store = new ReproStore($root . '/out', new CorePattern('core.%e.%p', false), $root);
        $runner = new JobRunner(
            $options, PHP_BINARY, [], $root, new CommandTemplate($command),
            $store, $root . '/work', null, PHP_VERSION,
        );
        $stats = new Stats();
        $view = $this->createMock(View::class);
        $view->expects(self::once())->method('start');
        $view->expects(self::once())->method('stop');
        $reducing = [];
        $recording = [];
        $quit = false;
        $quitSent = false;
        $shutdownDuringReduction = false;
        $view->method('render')->willReturnCallback(static function (Stats $stats, DashboardState $state) use (
            $runner, &$reducing, &$recording, &$quit, &$shutdownDuringReduction,
        ): void {
            foreach ($state->active as $job) {
                if ($job->note === 'reducing…' && $runner->launched() > 1) {
                    $reducing[] = $stats->elapsed();
                    $quit = true;
                    $shutdownDuringReduction = $shutdownDuringReduction || $state->shutdown !== null;
                }
                if ($job->note === 'recording minimum…') {
                    $recording[] = $stats->elapsed();
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
            self::assertSame(1, $scheduler->run());
            if ($reducing === [] || $recording === []) {
                self::fail('expected refreshes during both reduction and the final replay');
            }
            self::assertGreaterThan(2, count($reducing));
            self::assertGreaterThan(0.2, max($reducing) - min($reducing));
            self::assertGreaterThan(2, count($recording));
            self::assertGreaterThan(0.2, max($recording) - min($recording));
            self::assertTrue($shutdownDuringReduction);
            self::assertSame(1, $stats->started, 'quit during reduction must prevent the second campaign run');
            self::assertSame(1, $stats->completed);
            self::assertSame(1, $stats->reproducers);
            self::assertSame(1, $stats->reductions);
            self::assertSame(4, $runner->launched(), 'original, two trials, and final minimized replay');
            $captures = glob($root . '/out/failure/*') ?: [];
            self::assertCount(1, $captures);
            self::assertFileExists($captures[0] . '/stderr.log');
            self::assertFileExists($captures[0] . '/minimized/meta.json');
        } finally {
            ob_end_clean();
            $runner->cleanup();
            Fs::removeTree($root);
        }
    }
}
