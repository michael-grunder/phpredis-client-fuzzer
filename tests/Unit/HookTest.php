<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\OptionName;
use Mgrunder\PhpredisCommandFuzzer\Commands\OptionValue;
use Mgrunder\PhpredisCommandFuzzer\Fuzzer;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookDecision;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookExecutionFailed;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookLoader;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookRegistry;
use Mgrunder\PhpredisCommandFuzzer\Hooks\InvocationHook;
use Mgrunder\PhpredisCommandFuzzer\Hooks\InvocationRejected;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PendingInvocation;
use Mgrunder\PhpredisCommandFuzzer\RunConfiguration;
use PHPUnit\Framework\TestCase;

final class HookTest extends TestCase
{
    public function testExactDenyUsesResolvedScriptArgumentsBeforeClientDispatch(): void
    {
        $client = new class extends \Redis {
            /** @var list<array{int, mixed}> */
            public array $calls = [];

            public function setOption(int $option, mixed $value): bool
            {
                $this->calls[] = [$option, $value];

                return true;
            }
        };
        $hooks = new HookRegistry();
        $hooks->deny(
            id: 'deny-test-tuple',
            method: 'setOption',
            arguments: [2, 3],
            reason: 'synthetic unsafe tuple',
        );
        $command = Command::object('setoption');
        $command->setInvocationHook($hooks);

        try {
            $command->exec($client, new OptionName(2), new OptionValue(2, 3));
            self::fail('The denied invocation was not rejected');
        } catch (InvocationRejected $rejected) {
            self::assertSame('setoption', $rejected->command);
            self::assertSame('setoption', $rejected->method);
            self::assertSame('synthetic unsafe tuple', $rejected->reason);
        } finally {
            $command->setInvocationHook(null);
        }

        self::assertSame([], $client->calls);
        self::assertSame([
            'deny-test-tuple' => [
                'count' => 1,
                'reason' => 'synthetic unsafe tuple',
            ],
        ], $hooks->rejections());
    }

    public function testRejectedCandidatesDoNotConsumeTheStepBudget(): void
    {
        $hooks = new HookRegistry();
        $hooks->add('reject-first-two', new class implements InvocationHook {
            private int $calls = 0;

            public function beforeInvocation(PendingInvocation $invocation): HookDecision
            {
                $this->calls++;

                return $this->calls <= 2
                    ? HookDecision::reject('exercise resampling')
                    : HookDecision::allow();
            }
        });

        $result = (new Fuzzer($hooks))->run(
            [new \Redis()],
            new RunConfiguration(
                maxSteps: 3,
                seed: 42,
                commands: ['isconnected'],
                includeLocal: true,
            ),
        );

        self::assertSame(3, $result->steps);
        self::assertCount(3, $result->outcomes);
        self::assertSame(3, $result->commands['isconnected']['count']);
        self::assertSame([
            'reject-first-two' => [
                'count' => 2,
                'reason' => 'exercise resampling',
            ],
        ], $result->hookRejections);
    }

    public function testReplacementArgumentsFlowThroughSubsequentHooks(): void
    {
        $client = new \Redis();
        $hooks = new HookRegistry();
        $hooks->add('replace', new class implements InvocationHook {
            public function beforeInvocation(PendingInvocation $invocation): HookDecision
            {
                return HookDecision::replace([2, 3]);
            }
        });
        $hooks->deny(
            id: 'deny-replacement',
            method: 'setOption',
            arguments: [2, 3],
            reason: 'replacement was visible',
        );

        $decision = $hooks->beforeInvocation(new PendingInvocation(
            'setoption',
            $client,
            'setOption',
            [1, 0],
        ));

        self::assertSame('reject', $decision->action->value);
        self::assertSame('replacement was visible', $decision->reason);
    }

    public function testBundledNoMsgpackHookLoadsExplicitly(): void
    {
        $path = dirname(__DIR__, 2) . '/hooks/no-msgpack.php';
        $hooks = HookLoader::load([$path]);
        $metadata = $hooks->metadata();

        self::assertSame(realpath($path), $metadata['sources'][0]['path'] ?? null);
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $metadata['sources'][0]['sha256'],
        );
        if (defined('Redis::SERIALIZER_MSGPACK')) {
            self::assertSame(['no-msgpack-serializer'], $metadata['ids']);
            $client = new class extends \Redis {
                public int $calls = 0;

                public function setOption(int $option, mixed $value): bool
                {
                    $this->calls++;

                    return true;
                }
            };
            $command = Command::object('setoption');
            $command->setInvocationHook($hooks);
            try {
                $command->exec(
                    $client,
                    new OptionName(\Redis::OPT_SERIALIZER),
                    new OptionValue(
                        \Redis::OPT_SERIALIZER,
                        constant('Redis::SERIALIZER_MSGPACK'),
                    ),
                );
                self::fail('The bundled msgpack hook did not reject its tuple');
            } catch (InvocationRejected $rejected) {
                self::assertSame(
                    'Known memory leaks and crashes in the msgpack serializer',
                    $rejected->reason,
                );
            } finally {
                $command->setInvocationHook(null);
            }
            self::assertSame(0, $client->calls);

            return;
        }

        self::assertSame([], $metadata['ids']);
    }

    public function testHookFailuresAbortInsteadOfLookingLikeClientFailures(): void
    {
        $hooks = new HookRegistry();
        $hooks->add('broken-hook', new class implements InvocationHook {
            public function beforeInvocation(PendingInvocation $invocation): HookDecision
            {
                throw new \RuntimeException('broken hook body');
            }
        });

        $this->expectException(HookExecutionFailed::class);
        $this->expectExceptionMessage('Invocation hook broken-hook failed: broken hook body');

        (new Fuzzer($hooks))->run(
            [new \Redis()],
            new RunConfiguration(
                maxSteps: 1,
                commands: ['isconnected'],
                includeLocal: true,
            ),
        );
    }

    public function testHookFileMayReturnADirectHookObject(): void
    {
        $path = dirname(__DIR__) . '/Fixtures/direct-hook.php';
        $hooks = HookLoader::load([$path]);

        self::assertSame(['direct-hook'], $hooks->metadata()['ids']);
        $decision = $hooks->beforeInvocation(new PendingInvocation(
            'isconnected',
            new \Redis(),
            'isConnected',
            [],
        ));
        self::assertSame('reject', $decision->action->value);
        self::assertSame('direct hook fixture', $decision->reason);
    }
}
