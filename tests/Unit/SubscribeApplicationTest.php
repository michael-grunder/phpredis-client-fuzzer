<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Cli\SubscribeApplication;
use Mgrunder\PhpredisCommandFuzzer\Cli\SubscribeCommands;
use Mgrunder\PhpredisCommandFuzzer\Tests\Fixtures\InvocationTrace;
use Mgrunder\PhpredisCommandFuzzer\Tests\Fixtures\RecordingRedis;
use Mgrunder\PhpredisCommandFuzzer\Tests\Fixtures\SubscribeRedis;
use PHPUnit\Framework\TestCase;

final class SubscribeApplicationTest extends TestCase
{
    protected function tearDown(): void
    {
        InvocationTrace::$calls = [];
    }

    public function testCatalogCommandsRunInsideNullAndMessageCallbacksAndStopAtLimit(): void
    {
        $client = new SubscribeRedis();
        [$status, $output, $error] = $this->runApplication($client, ['--command-steps=3', '--commands=get', '--verbose']);
        self::assertSame(0, $status);
        self::assertSame('', $error);
        self::assertSame(8, $client->callbacks);
        self::assertSame(21, $client->commandCalls);
        self::assertTrue($client->commandsInsideCallback);
        self::assertCount(21, explode("\n", trim($output)));
        self::assertSame([['unsubscribe', []]], array_slice($client->subscriptionCalls, -1));
    }

    public function testSameSeedRepeatsSubscriptionActionsAndConcreteCommandArguments(): void
    {
        $first = new SubscribeRedis();
        InvocationTrace::$calls = [];
        $this->runApplication($first, ['--commands=get,set', '--command-steps=3']);
        $calls = InvocationTrace::$calls;
        self::assertNotEmpty($calls);

        mt_srand(9876);
        $second = new SubscribeRedis();
        InvocationTrace::$calls = [];
        $this->runApplication($second, ['--commands=get,set', '--command-steps=3']);
        self::assertSame($calls, InvocationTrace::$calls);
        self::assertSame($first->subscriptionCalls, $second->subscriptionCalls);

        $third = new SubscribeRedis();
        InvocationTrace::$calls = [];
        $this->runApplication($third, ['--commands=get,set', '--command-steps=3', '--seed=43']);
        self::assertNotSame($calls, InvocationTrace::$calls);
    }

    public function testCommandBatchSizeDoesNotReseedSubscriptionSequencing(): void
    {
        $first = new SubscribeRedis();
        $this->runApplication($first, ['--commands=get', '--command-steps=1']);
        $second = new SubscribeRedis();
        $this->runApplication($second, ['--commands=get,set', '--command-steps=4']);
        self::assertSame($first->subscriptionCalls, $second->subscriptionCalls);
    }

    public function testTerminalCallbackDoesNotRunCommands(): void
    {
        $client = new SubscribeRedis();
        [$status] = $this->runApplication($client, ['--commands=get', '--steps=1']);
        self::assertSame(0, $status);
        self::assertSame(1, $client->callbacks);
        self::assertSame(0, $client->commandCalls);
    }

    public function testCommandExceptionsKeepClientAndSeedDiagnosticsWithoutStoppingSequencing(): void
    {
        $client = new SubscribeRedis();
        $client->throwOnGet = true;
        [$status, $output, $error] = $this->runApplication($client, ['--commands=get']);
        self::assertSame(0, $status);
        self::assertSame('', $output);
        self::assertSame(8, $client->callbacks);
        self::assertStringContainsString('fake callback GET failure', $error);
        self::assertStringContainsString('"class":"TypeError"', $error);
        self::assertStringContainsString('"client_class":', $error);
        self::assertStringContainsString('"seed":', $error);
    }

    public function testUnsafeSelectionsAreRejectedBeforeClientCreation(): void
    {
        $application = new SubscribeApplication(static function (): never {
            self::fail('Invalid command selection must not create a connection');
        });
        $this->expectException(\UnderflowException::class);
        $application->run(['--no-fork', '--commands=flushall']);
    }

    public function testZeroCommandStepsAreRejectedBeforeClientCreation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SubscribeApplication())->run(['--no-fork', '--command-steps=0']);
    }

    public function testBatchesUseDifferentRepeatableSeedsAndKeepSafetyGates(): void
    {
        $first = new SubscribeCommands(42, 2, ['get', 'flushall', 'blpop', 'multi', 'rawcommand', 'isconnected']);
        $second = new SubscribeCommands(42, 2, ['get']);
        $result = $first->run(new RecordingRedis());
        self::assertSame(['get'], $result->selectedCommands);
        self::assertSame(2, $result->steps);
        self::assertSame($result->seed, $second->run(new RecordingRedis())->seed);
        $next = $first->run(new RecordingRedis());
        self::assertNotSame($result->seed, $next->seed);
        self::assertSame($next->seed, $second->run(new RecordingRedis())->seed);
    }

    public function testExistingScriptEvidenceIsNotOverwrittenAndStopsTheLoop(): void
    {
        $prefix = tempnam(sys_get_temp_dir(), 'subscribe-test-');
        self::assertIsString($prefix);
        $path = $prefix . '.000001.php';
        file_put_contents($path, 'existing evidence');
        try {
            $client = new SubscribeRedis();
            [$status, , $error] = $this->runApplication($client, ['--commands=get', '--script-log=' . $prefix]);
            self::assertSame(1, $status);
            self::assertSame('existing evidence', file_get_contents($path));
            self::assertStringContainsString('Cannot create subscription command log', $error);
            self::assertSame(0, $client->commandCalls);
        } finally {
            unlink($path);
            unlink($prefix);
        }
    }

    public function testScriptLogsPreserveEachBatchSeedAndConcreteArguments(): void
    {
        $prefix = tempnam(sys_get_temp_dir(), 'subscribe-test-');
        self::assertIsString($prefix);
        $paths = [$prefix . '.000001.php', $prefix . '.000002.php'];
        try {
            $commands = new SubscribeCommands(42, 2, ['get'], $prefix);
            foreach ($paths as $path) {
                InvocationTrace::$calls = [];
                $result = $commands->run(new RecordingRedis());
                $script = file_get_contents($path);
                self::assertIsString($script);
                self::assertStringContainsString('// seed: ' . $result->seed, $script);
                self::assertNotEmpty(token_get_all($script, TOKEN_PARSE));
                $arguments = $this->recordedGetArguments();
                self::assertCount(2, $arguments);
                foreach ($arguments as $argument) {
                    self::assertStringContainsString('->get(' . $argument . ');', $script);
                }
            }
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) unlink($path);
            }
            unlink($prefix);
        }
    }

    /** @return list<string> */
    private function recordedGetArguments(): array
    {
        $arguments = [];
        foreach (InvocationTrace::$calls as $call) {
            if ($call[1] === 'get') {
                self::assertCount(1, $call[2]);
                $arguments[] = $call[2][0];
            }
        }
        return $arguments;
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private function runApplication(SubscribeRedis $client, array $arguments): array
    {
        $output = fopen('php://memory', 'w+');
        $error = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        self::assertIsResource($error);
        try {
            $application = new SubscribeApplication(static fn (): SubscribeRedis => $client, $output, $error);
            $status = $application->run(['--no-fork', '--seed=42', '--steps=8', ...$arguments]);
            rewind($output);
            rewind($error);
            return [$status, (string) stream_get_contents($output), (string) stream_get_contents($error)];
        } finally {
            fclose($output);
            fclose($error);
        }
    }
}
