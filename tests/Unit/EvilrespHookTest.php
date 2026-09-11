<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Tests\Unit;

use Mgrunder\PhpredisCommandFuzzer\Hooks\CompletedInvocation;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookAction;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookExecutionFailed;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookLoader;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookRegistry;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PendingInvocation;
use Mgrunder\PhpredisCommandFuzzer\ScriptLogger;
use Mgrunder\PhpredisCommandFuzzer\Tests\Fixtures\FakeEvilrespCluster;
use Mgrunder\PhpredisCommandFuzzer\Tests\Fixtures\FakeEvilrespProxy;
use Mgrunder\PhpredisCommandFuzzer\Tests\Fixtures\FakeEvilrespRedis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Drives hooks/evilresp.php with clients that answer DEBUG EVIL the way the
 * proxy does, so the arming, re-checking and failure handling are covered
 * without a proxy or a server.
 */
final class EvilrespHookTest extends TestCase
{
    private const ENVIRONMENT = [
        'EVILRESP_MODES', 'EVILRESP_PROBABILITY', 'EVILRESP_TOPOLOGY', 'EVILRESP_CANONICALIZE',
        'EVILRESP_INCLUDE', 'EVILRESP_EXCLUDE', 'EVILRESP_SEED', 'EVILRESP_RESET',
        'EVILRESP_PROBE_INTERVAL', 'EVILRESP_VERBOSE',
        'EVILRESP_STRATEGY', 'EVILRESP_MUTATIONS', 'EVILRESP_GENERATOR_PROTOCOL',
        'EVILRESP_GENERATOR_CORPUS', 'EVILRESP_GENERATOR_VIOLATIONS', 'EVILRESP_FRAMING',
        'EVILRESP_FRAMING_PROBABILITY', 'EVILRESP_FRAMING_TARGET', 'EVILRESP_FRAMING_KIND',
        'EVILRESP_TRANSPORT', 'EVILRESP_TRANSPORT_TRUNCATE', 'EVILRESP_TRANSPORT_EXTRA',
        'EVILRESP_TRANSPORT_CHUNKS', 'EVILRESP_TRANSPORT_PROBABILITY',
    ];

    private const DEFAULT_CONTROLS = [
        ['DEBUG', 'EVIL', 'STRATEGY', 'PRESERVE'],
        ['DEBUG', 'EVIL', 'MUTATIONS', 'MANY'],
        ['DEBUG', 'EVIL', 'GENERATOR', 'PROTOCOL', 'RESP2', 'CORPUS', 'BOUNDARY', 'VIOLATIONS', 'OFF'],
        ['DEBUG', 'EVIL', 'FRAMING', 'AUTO'],
        ['DEBUG', 'EVIL', 'TRANSPORT', 'OFF'],
    ];

    protected function tearDown(): void
    {
        foreach (self::ENVIRONMENT as $variable) {
            putenv($variable);
        }
    }

    public function testTheHookRegistersTheThreeEventsItNeeds(): void
    {
        $hooks = $this->load();

        self::assertSame([
            'postConstructor' => ['evilresp'],
            'preCommand' => ['evilresp'],
            'postCommand' => ['evilresp'],
        ], $hooks->metadata()['listeners']);
    }

    public function testPostConstructorResetsSeedsAndArmsTheFirstClientFromTheRunSeed(): void
    {
        $hooks = $this->load();
        $hooks->setSeed(4242);
        $client = $this->standalone();

        $hooks->dispatchPostConstructor($client, 0);

        self::assertSame(['DEBUG', 'EVIL', 'STATUS'], $client->sent[0]);
        self::assertSame(['DEBUG', 'EVIL', 'MODE', 'RESET'], $client->sent[1]);
        self::assertSame(['DEBUG', 'EVIL', 'SEED', '4242'], $client->sent[2]);
        self::assertSame('MODE', $client->sent[3][2]);
        self::assertContains($client->sent[3][3], ['MUTATE', 'OVERFLOW', 'RANDOM']);
        self::assertSame('PROBABILITY', $client->sent[3][4]);
        self::assertIsString($client->sent[3][5]);
        self::assertMatchesRegularExpression('/^\d+\.\d{2}$/', $client->sent[3][5]);
        self::assertSame(self::DEFAULT_CONTROLS, array_slice($client->sent, 4));
    }

    public function testProtocolProbesAreTranslatedReproduciblyWithoutChangingOtherRandomStreams(): void
    {
        $hooks = $this->load();
        $client = $this->standalone();
        $sequences = [];
        foreach ([1, 2] as $run) {
            $hooks->setSeed(4242);
            mt_srand(4242);
            $queries = [];
            foreach (['array', 'bignum', 'double', 'integer', 'map', 'null', 'push', 'set', 'verbatim'] as $type) {
                $decision = $hooks->beforeInvocation(new PendingInvocation(
                    'debug', $client, 'rawCommand', ['debug', 'PROTOCOL', $type],
                ));
                self::assertSame(HookAction::Replace, $decision->action);
                self::assertNotNull($decision->arguments);
                self::assertCount(4, $decision->arguments);
                self::assertSame(['DEBUG', 'PROTOCOL'], array_slice($decision->arguments, 0, 2));
                self::assertContains($decision->arguments[2], ['IN', 'OUT']);
                self::assertContains($decision->arguments[3], ['BLAKE3', 'TLSH']);
                $queries[] = $decision->arguments;
            }
            $sequences[] = $queries;
            $catalogRandom = mt_rand();
            mt_srand(4242);
            self::assertSame(mt_rand(), $catalogRandom);
            $planRandom = $hooks->randomizer('evilresp')->getInt(0, PHP_INT_MAX);
            $hooks->setSeed(4242);
            self::assertSame($hooks->randomizer('evilresp')->getInt(0, PHP_INT_MAX), $planRandom);
        }
        self::assertSame($sequences[0], $sequences[1]);
        self::assertContains('IN', array_column($sequences[0], 2));
        self::assertContains('OUT', array_column($sequences[0], 2));
        self::assertContains('BLAKE3', array_column($sequences[0], 3));
        self::assertContains('TLSH', array_column($sequences[0], 3));
    }

    public function testProtocolTranslationPreservesClusterRouting(): void
    {
        $hooks = $this->load();
        $hooks->setSeed(4242);
        $client = $this->cluster([['127.0.0.1', 10000]]);
        foreach (['{tag}:route', ['127.0.0.1', 10000]] as $route) {
            $decision = $hooks->beforeInvocation(new PendingInvocation(
                'debug', $client, 'rawCommand', [$route, 'DeBuG', 'pRoToCoL', 'MAP'],
            ));
            self::assertSame(HookAction::Replace, $decision->action);
            self::assertNotNull($decision->arguments);
            self::assertCount(5, $decision->arguments);
            self::assertSame($route, $decision->arguments[0]);
            self::assertSame(['DEBUG', 'PROTOCOL'], array_slice($decision->arguments, 1, 2));
        }
    }

    public function testOtherInvocationsPassThroughWithoutNeedingASeed(): void
    {
        $hooks = $this->load();
        foreach ([$this->standalone(), $this->cluster([['127.0.0.1', 10000]])] as $client) {
            foreach ([
                [], ['DEBUG'], ['DEBUG', 'PROTOCOL'],
                ['DEBUG', 'PROTOCOL', 'IN', 'BLAKE3'], ['DEBUG', 'PROTOCOL', 'OUT', 'TLSH'],
                ['DEBUG', 'EVIL', 'STATUS'], ['DEBUG', 'OBJECT', 'key'],
                ['DEBUG', 'ERROR', 'message'], ['DEBUG', 'PROTOCOL', 'bogus'],
                ['DEBUG', 'PROTOCOL', 'array', 'extra'], ['DEBUG', 'PROTOCOL', 123],
                ['DEBUG', [], 'array'], [null, 'PROTOCOL', 'array'],
            ] as $arguments) {
                if ($client instanceof \RedisCluster) {
                    array_unshift($arguments, '{tag}:route');
                }
                self::assertSame(HookAction::Allow, $hooks->beforeInvocation(new PendingInvocation(
                    'debug', $client, 'rawCommand', $arguments,
                ))->action);
            }
        }
        self::assertSame(HookAction::Allow, $hooks->beforeInvocation(new PendingInvocation(
            'debug', $this->standalone(), 'someMethod', ['DEBUG', 'PROTOCOL', 'array'],
        ))->action);
    }

    public function testTheSameSeedDrawsTheSamePlanAndDifferentSeedsNeedNot(): void
    {
        $plan = function (int $seed): array {
            $hooks = $this->load();
            $hooks->setSeed($seed);
            $client = $this->standalone();
            $hooks->dispatchPostConstructor($client, 0);

            return $client->sent;
        };

        self::assertSame($plan(1), $plan(1));
        $plans = array_map(static fn (array $sent): string => json_encode($sent[3], JSON_THROW_ON_ERROR), [
            $plan(1), $plan(2), $plan(3), $plan(4), $plan(5), $plan(6),
        ]);
        self::assertGreaterThan(1, count(array_unique($plans)), 'six seeds never varied the plan');
    }

    public function testLaterClientsOfTheSameRunAreArmedWithoutAnotherReset(): void
    {
        $hooks = $this->load();
        $hooks->setSeed(7);
        $first = $this->standalone();
        $second = $this->standalone();

        $hooks->dispatchPostConstructor($first, 0);
        $hooks->dispatchPostConstructor($second, 1);

        self::assertSame(['DEBUG', 'EVIL', 'MODE', 'RESET'], $first->sent[1]);
        self::assertSame(
            [['DEBUG', 'EVIL', 'STATUS'], ['DEBUG', 'EVIL', 'SEED', '7'], $first->sent[3], ...self::DEFAULT_CONTROLS],
            $second->sent,
        );
    }

    public function testAThrowingCallTriggersACheckAndAReplacedConnectionIsRearmed(): void
    {
        $hooks = $this->load();
        $hooks->setSeed(7);
        $client = $this->standalone();
        $hooks->dispatchPostConstructor($client, 0);
        $armed = count($client->sent);

        /* A healthy call: nothing to check before the next one. */
        $hooks->dispatchPreCommand($this->pending($client));
        $hooks->dispatchPostCommand($this->completed($client));
        $hooks->dispatchPreCommand($this->pending($client));
        self::assertCount($armed, $client->sent);

        /* The client threw; the proxy then reports the connection as new. */
        $hooks->dispatchPostCommand($this->completed($client, new \RedisException('protocol error')));
        $client->proxy->status = FakeEvilrespProxy::IDLE;
        $hooks->dispatchPreCommand($this->pending($client));

        self::assertSame(['DEBUG', 'EVIL', 'STATUS'], $client->sent[$armed]);
        self::assertSame(['DEBUG', 'EVIL', 'SEED', '7'], $client->sent[$armed + 1]);
        self::assertSame($client->sent[3], $client->sent[$armed + 2]);
        self::assertCount($armed + 8, $client->sent, 'a re-arm must not repeat MODE RESET');
        self::assertSame(0, $client->closed);
    }

    public function testAConnectionStillOnThePlanIsLeftAlone(): void
    {
        $hooks = $this->load();
        $hooks->setSeed(7);
        $client = $this->standalone();
        $hooks->dispatchPostConstructor($client, 0);
        $armed = count($client->sent);

        $hooks->dispatchPostCommand($this->completed($client, new \RedisException('read error')));
        $hooks->dispatchPreCommand($this->pending($client));

        self::assertSame([['DEBUG', 'EVIL', 'STATUS']], array_slice($client->sent, $armed));
    }

    public function testLeftoverBytesInTheReplyStreamAreClearedByReconnecting(): void
    {
        $hooks = $this->load();
        $hooks->setSeed(7);
        $client = $this->standalone();
        $hooks->dispatchPostConstructor($client, 0);
        $armed = count($client->sent);

        $client->garbage = ['IsdeERnDMepAXcwYVbHDa1MRy'];
        $hooks->dispatchPostCommand($this->completed($client, new \RedisException('protocol error')));
        $client->proxy->status = FakeEvilrespProxy::IDLE;
        $hooks->dispatchPreCommand($this->pending($client));

        self::assertSame(1, $client->closed);
        self::assertSame(
            [['DEBUG', 'EVIL', 'STATUS'], ['DEBUG', 'EVIL', 'STATUS'], ['DEBUG', 'EVIL', 'SEED', '7'], $client->sent[3], ...self::DEFAULT_CONTROLS],
            array_slice($client->sent, $armed),
        );
    }

    public function testNothingIsSentWhileTheClientIsInsideMultiOrAPipeline(): void
    {
        $hooks = $this->load();
        $hooks->setSeed(7);
        $client = $this->standalone();
        $hooks->dispatchPostConstructor($client, 0);
        $armed = count($client->sent);

        $client->mode = \Redis::MULTI;
        $hooks->dispatchPostCommand($this->completed($client, new \RedisException('inside multi')));
        $hooks->dispatchPreCommand($this->pending($client, 'exec'));
        self::assertCount($armed, $client->sent);

        /* Back in atomic mode the owed check happens before the next call. */
        $client->mode = \Redis::ATOMIC;
        $hooks->dispatchPreCommand($this->pending($client));
        self::assertSame(['DEBUG', 'EVIL', 'STATUS'], $client->sent[$armed]);
    }

    public function testConnectionMethodsForceACheck(): void
    {
        $hooks = $this->load();
        $hooks->setSeed(7);
        $client = $this->standalone();
        $hooks->dispatchPostConstructor($client, 0);
        $armed = count($client->sent);

        $hooks->dispatchPostCommand($this->completed($client, method: 'connect'));
        $hooks->dispatchPreCommand($this->pending($client));

        self::assertSame(['DEBUG', 'EVIL', 'STATUS'], $client->sent[$armed]);
    }

    public function testPeriodicChecksFollowTheConfiguredInterval(): void
    {
        putenv('EVILRESP_PROBE_INTERVAL=3');
        $hooks = $this->load();
        $hooks->setSeed(7);
        $client = $this->standalone();
        $hooks->dispatchPostConstructor($client, 0);
        $armed = count($client->sent);

        for ($step = 0; $step < 3; $step++) {
            $hooks->dispatchPreCommand($this->pending($client));
            $hooks->dispatchPostCommand($this->completed($client));
        }
        self::assertCount($armed, $client->sent);
        $hooks->dispatchPreCommand($this->pending($client));
        self::assertSame([['DEBUG', 'EVIL', 'STATUS']], array_slice($client->sent, $armed));
    }

    public function testATargetThatIsNotEvilrespFailsTheRunAtArming(): void
    {
        $hooks = $this->load();
        $hooks->setSeed(7);
        $client = $this->standalone();
        $client->rejection = "ERR unknown subcommand or wrong number of arguments for 'EVIL'. Try DEBUG HELP.";

        try {
            $hooks->dispatchPostConstructor($client, 0);
            self::fail('A real Redis server was accepted as evilresp');
        } catch (HookExecutionFailed $failure) {
            self::assertStringContainsString('rejected DEBUG EVIL STATUS', $failure->getMessage());
            self::assertStringContainsString('unknown subcommand', $failure->getMessage());
        }
    }

    public function testAClientThatNeverAnswersAbortsTheRunAfterRepeatedChecks(): void
    {
        $hooks = $this->load();
        $hooks->setSeed(7);
        $client = $this->standalone();
        $hooks->dispatchPostConstructor($client, 0);
        $client->throwing = new \RedisException('Connection refused');

        $armed = count($client->sent);
        try {
            for ($step = 0; $step < 1000; $step++) {
                $hooks->dispatchPostCommand($this->completed($client, new \RedisException('Connection refused')));
                $hooks->dispatchPreCommand($this->pending($client));
            }
            self::fail('The run kept going without the proxy');
        } catch (HookExecutionFailed $failure) {
            self::assertStringContainsString('5 consecutive checks', $failure->getMessage());
            /* Five checks of two STATUS attempts each, spaced by the
               failure cooldown rather than one per command. */
            self::assertCount($armed + 10, $client->sent);
            self::assertSame(5, $client->closed);
        }
    }

    public function testAClusterClientIsArmedOnEveryMasterWithOneResetAndTopology(): void
    {
        putenv('EVILRESP_MODES=MUTATE');
        putenv('EVILRESP_PROBABILITY=12.5');
        putenv('EVILRESP_TOPOLOGY=4');
        $hooks = $this->load();
        $hooks->setSeed(7);
        $client = $this->cluster([['127.0.0.1', 7200], ['127.0.0.1', 7201]]);

        $hooks->dispatchPostConstructor($client, 0);

        self::assertSame([
            [['127.0.0.1', 7200], 'DEBUG', 'EVIL', 'STATUS'],
            [['127.0.0.1', 7200], 'DEBUG', 'EVIL', 'MODE', 'RESET'],
            [['127.0.0.1', 7200], 'DEBUG', 'EVIL', 'SEED', '7'],
            [['127.0.0.1', 7200], 'DEBUG', 'EVIL', 'MODE', 'MUTATE', 'PROBABILITY', '12.50'],
            [['127.0.0.1', 7200], 'DEBUG', 'EVIL', 'TOPOLOGY', '4.00'],
            ...array_map(static fn (array $command): array => [['127.0.0.1', 7200], ...$command], self::DEFAULT_CONTROLS),
            [['127.0.0.1', 7201], 'DEBUG', 'EVIL', 'STATUS'],
            [['127.0.0.1', 7201], 'DEBUG', 'EVIL', 'SEED', '7'],
            [['127.0.0.1', 7201], 'DEBUG', 'EVIL', 'MODE', 'MUTATE', 'PROBABILITY', '12.50'],
            [['127.0.0.1', 7201], 'DEBUG', 'EVIL', 'TOPOLOGY', '4.00'],
            ...array_map(static fn (array $command): array => [['127.0.0.1', 7201], ...$command], self::DEFAULT_CONTROLS),
        ], $client->sent);
    }

    public function testOneUnreachableMasterDoesNotStopTheOthersFromBeingArmed(): void
    {
        $hooks = $this->load();
        $hooks->setSeed(7);
        $client = $this->cluster([['127.0.0.1', 7200], ['127.0.0.1', 7201]]);
        $client->unreachable = '127.0.0.1:7200';

        $hooks->dispatchPostConstructor($client, 0);

        $armed = array_values(array_filter(
            $client->sent,
            static fn (array $call): bool => $call[0] === ['127.0.0.1', 7201],
        ));
        /* The reset goes to whichever connection is armed first. */
        self::assertSame('STATUS', $armed[0][3]);
        self::assertSame(['MODE', 'RESET'], array_slice($armed[1], 3));
        self::assertSame('SEED', $armed[2][3]);
        self::assertCount(10, $armed);
    }

    public function testFiltersCanonicalizationAndAFixedSeedAreForwarded(): void
    {
        putenv('EVILRESP_MODES=off');
        putenv('EVILRESP_CANONICALIZE=all');
        putenv('EVILRESP_INCLUDE=^GET.*, @write');
        putenv('EVILRESP_EXCLUDE=ping');
        putenv('EVILRESP_SEED=99');
        putenv('EVILRESP_RESET=0');
        $hooks = $this->load();
        $hooks->setSeed(7);
        $client = $this->standalone();

        $hooks->dispatchPostConstructor($client, 0);

        self::assertSame([
            ['DEBUG', 'EVIL', 'STATUS'],
            ['DEBUG', 'EVIL', 'SEED', '99'],
            ['DEBUG', 'EVIL', 'MODE', 'OFF'],
            ['DEBUG', 'EVIL', 'CANONICALIZE', 'ALL'],
            ['DEBUG', 'EVIL', 'INCLUDE', '^GET.*', '@write'],
            [
                'DEBUG', 'EVIL', 'EXCLUDE', 'AUTH', 'HELLO', 'COMMAND', 'CLIENT', 'SELECT', 'ASKING', 'MULTI',
                'DISCARD', 'SUBSCRIBE', 'PSUBSCRIBE', 'SSUBSCRIBE', 'UNSUBSCRIBE', 'QUIT', 'ping',
            ],
            ...self::DEFAULT_CONTROLS,
        ], $client->sent);
    }

    public function testNewControlsAreAppliedToEveryNodeAndReplayedInTheScript(): void
    {
        putenv('EVILRESP_STRATEGY=replace');
        putenv('EVILRESP_MUTATIONS=one');
        putenv('EVILRESP_GENERATOR_PROTOCOL=resp3');
        putenv('EVILRESP_GENERATOR_CORPUS=random');
        putenv('EVILRESP_GENERATOR_VIOLATIONS=on');
        putenv('EVILRESP_FRAMING=length');
        putenv('EVILRESP_FRAMING_TARGET=ROOT.01.00.VALUE.2');
        putenv('EVILRESP_FRAMING_KIND=overflow');
        putenv('EVILRESP_FRAMING_PROBABILITY=12.5');
        putenv('EVILRESP_TRANSPORT_TRUNCATE=0006');
        putenv('EVILRESP_TRANSPORT_EXTRA=2');
        putenv('EVILRESP_TRANSPORT_CHUNKS=01, 3,4');
        putenv('EVILRESP_TRANSPORT_PROBABILITY=25');
        $expected = [
            ['DEBUG', 'EVIL', 'STRATEGY', 'REPLACE'],
            ['DEBUG', 'EVIL', 'MUTATIONS', 'ONE'],
            ['DEBUG', 'EVIL', 'GENERATOR', 'PROTOCOL', 'RESP3', 'CORPUS', 'RANDOM', 'VIOLATIONS', 'ON'],
            ['DEBUG', 'EVIL', 'FRAMING', 'LENGTH', 'PROBABILITY', '12.50', 'TARGET', 'root.1.0.value.2', 'KIND', 'OVERFLOW'],
            ['DEBUG', 'EVIL', 'TRANSPORT', 'TRUNCATE', '6', 'EXTRA', '2', 'CHUNKS', '1,3,4', 'PROBABILITY', '25.00'],
        ];
        $hooks = $this->load();
        $hooks->setSeed(123);
        $client = $this->standalone();
        $cluster = $this->cluster([['127.0.0.1', 7200], ['127.0.0.1', 7201]]);
        $hooks->dispatchPostConstructor($client, 0);
        $hooks->dispatchPostConstructor($cluster, 1);
        self::assertSame($expected, array_slice($client->sent, -5));
        foreach ($cluster->_masters() as $node) {
            foreach ($expected as $command) {
                self::assertContains([$node, ...$command], $cluster->sent);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'evilresp-hook-script-');
        self::assertIsString($path);
        try {
            ScriptLogger::init($path, clients: [$client]);
            $hooks->dispatchPreCommand($this->pending($client));
            ScriptLogger::finish();
            $script = file_get_contents($path);
            self::assertIsString($script);
            self::assertStringContainsString('framing_target=root.1.0.value.2', $script);
            self::assertStringContainsString('transport_probability=25.00', $script);
            self::assertStringContainsString("'FRAMING', 'LENGTH', 'PROBABILITY', '12.50'", $script);
            self::assertStringContainsString("'TRANSPORT', 'TRUNCATE', '6', 'EXTRA', '2', 'CHUNKS', '1,3,4'", $script);
        } finally {
            ScriptLogger::finish();
            unlink($path);
        }
    }

    public function testNewProbabilityRangesAreSeededAndRetainedAcrossReconnects(): void
    {
        putenv('EVILRESP_FRAMING=LENGTH');
        putenv('EVILRESP_FRAMING_PROBABILITY=0.5-40');
        putenv('EVILRESP_TRANSPORT_TRUNCATE=RANDOM');
        putenv('EVILRESP_TRANSPORT_CHUNKS=RANDOM');
        putenv('EVILRESP_TRANSPORT_PROBABILITY=70-20');
        $plans = [];
        foreach ([123, 123, 456] as $seed) {
            $hooks = $this->load();
            $hooks->setSeed($seed);
            $client = $this->standalone();
            $hooks->dispatchPostConstructor($client, 0);
            $plan = array_slice($client->sent, -5);
            $plans[] = $plan;
            self::assertIsString($plan[3][5]);
            self::assertIsString($plan[4][10]);
            self::assertGreaterThanOrEqual(0.5, (float) $plan[3][5]);
            self::assertLessThanOrEqual(40, (float) $plan[3][5]);
            self::assertGreaterThanOrEqual(20, (float) $plan[4][10]);
            self::assertLessThanOrEqual(70, (float) $plan[4][10]);
            $client->close();
            $hooks->dispatchPostCommand($this->completed($client, new \RedisException('EOF')));
            $hooks->dispatchPreCommand($this->pending($client));
            self::assertSame($plan, array_slice($client->sent, -5));
        }
        self::assertSame($plans[0], $plans[1]);
        self::assertNotSame($plans[0], $plans[2]);
    }

    public function testStatusDetectsChangesToEveryNewSettingAndRestoresThePlan(): void
    {
        putenv('EVILRESP_FRAMING=LENGTH');
        putenv('EVILRESP_TRANSPORT_EXTRA=1');
        $hooks = $this->load();
        $hooks->setSeed(123);
        $client = $this->standalone();
        $hooks->dispatchPostConstructor($client, 0);
        $expected = $client->proxy->status;
        foreach ([
            'strategy', 'mutations', 'generator_protocol', 'generator_corpus', 'generator_violations',
            'framing', 'framing_target', 'framing_kind', 'framing_probability',
            'transport', 'transport_truncate', 'transport_extra', 'transport_chunks', 'transport_probability',
        ] as $field) {
            $client->proxy->status = (string) preg_replace('/\b' . $field . '=\S+/', $field . '=stale', $expected);
            $armed = count($client->sent);
            $hooks->dispatchPostCommand($this->completed($client, new \RedisException('read error')));
            $hooks->dispatchPreCommand($this->pending($client));
            self::assertGreaterThan($armed + 1, count($client->sent), $field);
            self::assertSame($expected, $client->proxy->status, $field);
        }
    }

    public function testModeResetAlsoRestoresPersistentControlsToDefaults(): void
    {
        $client = $this->standalone();
        $client->proxy->answer(['STRATEGY', 'REPLACE']);
        $client->proxy->answer(['GENERATOR', 'VIOLATIONS', 'ON']);
        $client->proxy->answer(['TRANSPORT', 'EXTRA', '16']);
        $client->proxy->answer(['FRAMING', 'LENGTH']);
        $hooks = $this->load();
        $hooks->setSeed(123);
        $hooks->dispatchPostConstructor($client, 0);
        self::assertStringContainsString('strategy=PRESERVE', $client->proxy->status);
        self::assertStringContainsString('generator_violations=OFF', $client->proxy->status);
        self::assertStringContainsString('transport=OFF', $client->proxy->status);
        self::assertStringContainsString('framing=AUTO', $client->proxy->status);
    }

    public function testTransportCanOperateWithRespMutationOffAndKeepUnsignedOffsets(): void
    {
        putenv('EVILRESP_MODES=OFF');
        putenv('EVILRESP_FRAMING=OFF');
        putenv('EVILRESP_TRANSPORT_TRUNCATE=0');
        putenv('EVILRESP_TRANSPORT_CHUNKS=9223372036854775808,18446744073709551615');
        putenv('EVILRESP_TRANSPORT_PROBABILITY=0');
        $hooks = $this->load();
        $hooks->setSeed(123);
        $client = $this->standalone();
        $hooks->dispatchPostConstructor($client, 0);
        self::assertContains(['DEBUG', 'EVIL', 'MODE', 'OFF'], $client->sent);
        self::assertContains(['DEBUG', 'EVIL', 'FRAMING', 'OFF'], $client->sent);
        self::assertContains([
            'DEBUG', 'EVIL', 'TRANSPORT', 'TRUNCATE', '0', 'EXTRA', '0',
            'CHUNKS', '9223372036854775808,18446744073709551615', 'PROBABILITY', '0.00',
        ], $client->sent);
    }

    /** @return list<array{string, string}> */
    public static function invalidEnvironment(): array
    {
        return [
            ['EVILRESP_MODES', 'mutate,bogus'],
            ['EVILRESP_PROBABILITY', '5-200'],
            ['EVILRESP_PROBABILITY', 'lots'],
            ['EVILRESP_TOPOLOGY', '-1'],
            ['EVILRESP_CANONICALIZE', 'sometimes'],
            ['EVILRESP_SEED', '-3'],
            ['EVILRESP_PROBE_INTERVAL', 'often'],
            ['EVILRESP_STRATEGY', 'anything'],
            ['EVILRESP_MUTATIONS', 'two'],
            ['EVILRESP_GENERATOR_PROTOCOL', 'RESP4'],
            ['EVILRESP_GENERATOR_CORPUS', 'none'],
            ['EVILRESP_GENERATOR_VIOLATIONS', 'yes'],
            ['EVILRESP_FRAMING', 'sometimes'],
            ['EVILRESP_FRAMING_TARGET', 'root.0'],
            ['EVILRESP_FRAMING_KIND', 'LONGER'],
            ['EVILRESP_FRAMING_PROBABILITY', '50'],
            ['EVILRESP_TRANSPORT', 'ON'],
            ['EVILRESP_TRANSPORT_TRUNCATE', '-1'],
            ['EVILRESP_TRANSPORT_TRUNCATE', '18446744073709551616'],
            ['EVILRESP_TRANSPORT_EXTRA', '17'],
            ['EVILRESP_TRANSPORT_EXTRA', '1.5'],
            ['EVILRESP_TRANSPORT_CHUNKS', '0,1'],
            ['EVILRESP_TRANSPORT_CHUNKS', '1,1'],
            ['EVILRESP_TRANSPORT_CHUNKS', '2,1'],
            ['EVILRESP_TRANSPORT_CHUNKS', '1,'],
            ['EVILRESP_TRANSPORT_CHUNKS', implode(',', range(1, 65))],
            ['EVILRESP_TRANSPORT_PROBABILITY', '0-101'],
        ];
    }

    /** @return list<array{string, string}> */
    public static function invalidLengthOptions(): array
    {
        return [
            ['TARGET', 'root.key'], ['TARGET', 'root.0.value.key'], ['TARGET', 'root..0'],
            ['TARGET', 'root.-1'], ['TARGET', 'root.18446744073709551616'],
            ['KIND', 'invalid'], ['PROBABILITY', 'NaN'], ['PROBABILITY', '1e999'],
        ];
    }

    #[DataProvider('invalidLengthOptions')]
    public function testInvalidLengthOptionsAreRejected(string $option, string $value): void
    {
        putenv('EVILRESP_FRAMING=LENGTH');
        putenv("EVILRESP_FRAMING_{$option}={$value}");
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("EVILRESP_FRAMING_{$option}");
        $this->load();
    }

    public function testTransportOffRejectsConflictingOptions(): void
    {
        putenv('EVILRESP_TRANSPORT=OFF');
        putenv('EVILRESP_TRANSPORT_EXTRA=1');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('EVILRESP_TRANSPORT_EXTRA');
        $this->load();
    }

    #[DataProvider('invalidEnvironment')]
    public function testInvalidEnvironmentIsRejectedWhenTheHookLoads(string $variable, string $value): void
    {
        putenv("{$variable}={$value}");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($variable);
        $this->load();
    }

    public function testAnUnseededRegistryIsReportedRatherThanDrawnFrom(): void
    {
        $hooks = $this->load();

        $this->expectException(HookExecutionFailed::class);
        $this->expectExceptionMessage('setSeed()');
        $hooks->dispatchPostConstructor($this->standalone(), 0);
    }

    private function load(): HookRegistry
    {
        return HookLoader::load([dirname(__DIR__, 2) . '/hooks/evilresp.php']);
    }

    private function pending(\Redis $client, string $method = 'ping'): PendingInvocation
    {
        return new PendingInvocation($method, $client, $method, []);
    }

    private function completed(\Redis $client, ?\Throwable $throwable = null, string $method = 'ping'): CompletedInvocation
    {
        return new CompletedInvocation($method, $client, $method, [], $throwable === null ? true : null, $throwable, 0.0);
    }

    private function standalone(): FakeEvilrespRedis
    {
        return new FakeEvilrespRedis();
    }

    /** @param list<array{string, int}> $masters */
    private function cluster(array $masters): FakeEvilrespCluster
    {
        return new FakeEvilrespCluster($masters);
    }
}
