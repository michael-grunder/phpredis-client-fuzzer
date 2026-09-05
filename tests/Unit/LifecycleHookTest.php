<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\OptionName;
use Mgrunder\PhpredisCommandFuzzer\Fuzzer;
use Mgrunder\PhpredisCommandFuzzer\Hooks\ClientEvent;
use Mgrunder\PhpredisCommandFuzzer\Hooks\CompletedInvocation;
use Mgrunder\PhpredisCommandFuzzer\Hooks\ExactInvocationDenyHook;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookEvent;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookExecutionFailed;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookLoader;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookRegistry;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PendingInvocation;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PostCommandHook;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PostConstructorHook;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PreCommandHook;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PreDestructorHook;
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;
use PHPUnit\Framework\TestCase;

final class LifecycleHookTest extends TestCase
{
    protected function tearDown(): void
    {
        Command::setLifecycleHooks(null);
    }

    public function testARunAnnouncesAndReleasesEveryClient(): void
    {
        $clients = [new \Redis(), new \Redis()];
        $events = [];
        $hooks = new HookRegistry();
        $hooks->onPostConstructor(
            'record-construction',
            function (ClientEvent $event) use (&$events): void {
                $events[] = ['postConstructor', $event->clientIndex, $event->isCluster(), $event->isRelay()];
            },
        );
        $hooks->onPreDestructor(
            'record-release',
            function (ClientEvent $event) use (&$events): void {
                $events[] = ['preDestructor', $event->clientIndex, $event->isCluster(), $event->isRelay()];
            },
        );

        (new Fuzzer($hooks))->run(
            $clients,
            new RunConfiguration(maxSteps: 4, seed: 7, commands: ['isconnected'], includeLocal: true),
        );

        self::assertSame([
            ['postConstructor', 0, false, false],
            ['postConstructor', 1, false, false],
            ['preDestructor', 0, false, false],
            ['preDestructor', 1, false, false],
        ], $events);
    }

    public function testPostConstructorFiresOncePerClientObject(): void
    {
        $client = new \Redis();
        $calls = 0;
        $hooks = new HookRegistry();
        $hooks->onPostConstructor('count', function (ClientEvent $event) use (&$calls): void {
            $calls++;
        });

        /* A client the factory already announced is not announced again by the
           run that receives it. */
        $hooks->dispatchPostConstructor($client);
        $hooks->dispatchPostConstructor($client, 0);
        (new Fuzzer($hooks))->run(
            [$client],
            new RunConfiguration(maxSteps: 1, seed: 7, commands: ['isconnected'], includeLocal: true),
        );

        self::assertSame(1, $calls);
    }

    public function testCommandHooksSeeTheDispatchedArgumentsAndReply(): void
    {
        $client = new class extends \Redis {
            public function setOption(int $option, mixed $value): bool
            {
                return true;
            }
        };
        $pre = [];
        $post = [];
        $hooks = new HookRegistry();
        $hooks->onPreCommand('pre', function (PendingInvocation $invocation) use (&$pre): void {
            $pre[] = [$invocation->command, $invocation->method, $invocation->arguments];
        });
        $hooks->onPostCommand('post', function (CompletedInvocation $invocation) use (&$post): void {
            $post[] = $invocation;
        });
        Command::setLifecycleHooks($hooks);

        $command = Command::object('setoption');
        $command->exec($client, new OptionName(2), 1);

        self::assertSame([['setoption', 'setoption', [2, 1]]], $pre);
        self::assertCount(1, $post);
        self::assertSame('setoption', $post[0]->command);
        self::assertSame([2, 1], $post[0]->arguments);
        self::assertTrue($post[0]->result);
        self::assertTrue($post[0]->succeeded());
        self::assertGreaterThanOrEqual(0.0, $post[0]->durationSeconds);
    }

    public function testPostCommandReceivesTheThrownException(): void
    {
        $client = new class extends \Redis {
            public function setOption(int $option, mixed $value): bool
            {
                throw new \RedisException('synthetic client failure');
            }
        };
        $post = [];
        $hooks = new HookRegistry();
        $hooks->onPostCommand('post', function (CompletedInvocation $invocation) use (&$post): void {
            $post[] = $invocation;
        });
        Command::setLifecycleHooks($hooks);

        $command = Command::object('setoption');

        try {
            $command->exec($client, new OptionName(2), 1);
            self::fail('The client exception was swallowed');
        } catch (\RedisException $exception) {
            self::assertSame('synthetic client failure', $exception->getMessage());
        }

        self::assertCount(1, $post);
        self::assertFalse($post[0]->succeeded());
        self::assertSame('synthetic client failure', $post[0]->throwable?->getMessage());
        self::assertNull($post[0]->result);
    }

    public function testNoRegisteredListenerLeavesTheCommandPathUnarmed(): void
    {
        $hooks = new HookRegistry();
        $hooks->add('invocation-only', new ExactInvocationDenyHook('setOption', [2, 3], 'unused'));

        self::assertFalse($hooks->hasListeners(HookEvent::PreCommand));
        self::assertFalse($hooks->hasListeners(HookEvent::PostCommand));
        self::assertFalse($hooks->hasListeners(HookEvent::PostConstructor));
        self::assertFalse($hooks->hasListeners(HookEvent::PreDestructor));
        self::assertFalse($hooks->isEmpty());
    }

    public function testLifecycleHookObjectRegistersEverySurfaceItImplements(): void
    {
        $hook = new class implements PostConstructorHook, PreCommandHook, PostCommandHook, PreDestructorHook {
            /** @var list<string> */
            public array $seen = [];

            public function postConstructor(ClientEvent $event): void
            {
                $this->seen[] = 'postConstructor';
            }

            public function preCommand(PendingInvocation $invocation): void
            {
                $this->seen[] = 'preCommand';
            }

            public function postCommand(CompletedInvocation $invocation): void
            {
                $this->seen[] = 'postCommand';
            }

            public function preDestructor(ClientEvent $event): void
            {
                $this->seen[] = 'preDestructor';
            }
        };
        $hooks = new HookRegistry();
        $hooks->addLifecycleHook('every-surface', $hook);

        self::assertSame([
            'postConstructor' => ['every-surface'],
            'preCommand' => ['every-surface'],
            'postCommand' => ['every-surface'],
            'preDestructor' => ['every-surface'],
        ], $hooks->metadata()['listeners']);

        (new Fuzzer($hooks))->run(
            [new \Redis()],
            new RunConfiguration(maxSteps: 1, seed: 7, commands: ['isconnected'], includeLocal: true),
        );

        self::assertSame(
            ['postConstructor', 'preCommand', 'postCommand', 'preDestructor'],
            $hook->seen,
        );
    }

    public function testAnObjectWithoutALifecycleSurfaceIsRejected(): void
    {
        $hooks = new HookRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A lifecycle hook must implement at least one of');

        $hooks->addLifecycleHook('not-a-hook', new \stdClass());
    }

    public function testDuplicateListenerIdsAreRejectedPerEvent(): void
    {
        $hooks = new HookRegistry();
        $hooks->onPreCommand('shared', static function (PendingInvocation $invocation): void {});
        /* The same id under a different event is fine. */
        $hooks->onPostCommand('shared', static function (CompletedInvocation $invocation): void {});

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate preCommand hook id: shared');

        $hooks->onPreCommand('shared', static function (PendingInvocation $invocation): void {});
    }

    public function testAFailingListenerAbortsTheRunWithItsEventNamed(): void
    {
        $hooks = new HookRegistry();
        $hooks->onPreCommand('broken', static function (PendingInvocation $invocation): void {
            throw new \RuntimeException('broken listener body');
        });

        $this->expectException(HookExecutionFailed::class);
        $this->expectExceptionMessage('preCommand hook broken failed: broken listener body');

        (new Fuzzer($hooks))->run(
            [new \Redis()],
            new RunConfiguration(maxSteps: 1, seed: 7, commands: ['isconnected'], includeLocal: true),
        );
    }

    public function testAFailingPreDestructorDoesNotMaskTheRunFailure(): void
    {
        $hooks = new HookRegistry();
        $hooks->onPreCommand('broken', static function (PendingInvocation $invocation): void {
            throw new \RuntimeException('the real failure');
        });
        $hooks->onPreDestructor('also-broken', static function (ClientEvent $event): void {
            throw new \RuntimeException('release failure');
        });

        $this->expectException(HookExecutionFailed::class);
        $this->expectExceptionMessage('preCommand hook broken failed: the real failure');

        (new Fuzzer($hooks))->run(
            [new \Redis()],
            new RunConfiguration(maxSteps: 1, seed: 7, commands: ['isconnected'], includeLocal: true),
        );
    }

    public function testBundledLifecycleExampleRegistersEveryEvent(): void
    {
        $path = dirname(__DIR__, 2) . '/hooks/lifecycle-example.php';
        $hooks = HookLoader::load([$path]);

        self::assertSame([
            'postConstructor' => ['example-client-report'],
            'preCommand' => ['example-command-counter'],
            'postCommand' => ['example-slow-and-failed'],
            'preDestructor' => ['example-run-summary'],
        ], $hooks->metadata()['listeners']);
        self::assertSame([], $hooks->metadata()['ids']);
        self::assertSame($path, $hooks->metadata()['sources'][0]['path']);
    }

    public function testHookFileMayReturnALifecycleHookObject(): void
    {
        $path = dirname(__DIR__) . '/Fixtures/lifecycle-hook.php';
        $hooks = HookLoader::load([$path]);

        self::assertSame([
            'postConstructor' => ['lifecycle-hook'],
            'postCommand' => ['lifecycle-hook'],
        ], $hooks->metadata()['listeners']);
        self::assertSame(
            ['postConstructor:lifecycle-hook', 'postCommand:lifecycle-hook'],
            $hooks->allIds(),
        );
    }
}
