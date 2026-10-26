<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Unit;

use Mgrunder\RedisClientTester\Hooks\CompletedInvocation;
use Mgrunder\RedisClientTester\Hooks\EvilrespChaosOptions;
use Mgrunder\RedisClientTester\Hooks\EvilrespOptions;
use Mgrunder\RedisClientTester\Hooks\HookAction;
use Mgrunder\RedisClientTester\Hooks\HookExecutionFailed;
use Mgrunder\RedisClientTester\Hooks\HookLoader;
use Mgrunder\RedisClientTester\Hooks\HookRegistry;
use Mgrunder\RedisClientTester\Hooks\PendingInvocation;
use Mgrunder\RedisClientTester\ScriptLogger;
use Mgrunder\RedisClientTester\Tests\Fixtures\FakeEvilrespCluster;
use Mgrunder\RedisClientTester\Tests\Fixtures\FakeEvilrespProxy;
use Mgrunder\RedisClientTester\Tests\Fixtures\FakeEvilrespRedis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

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
        'EVILRESP_TRANSPORT_FAULT', 'EVILRESP_TRANSPORT_AT', 'EVILRESP_TRANSPORT_DURATION',
    ];

    private const DEFAULT_CONTROLS = [
        ['DEBUG', 'EVIL', 'STRATEGY', 'PRESERVE'],
        ['DEBUG', 'EVIL', 'MUTATIONS', 'MANY'],
        ['DEBUG', 'EVIL', 'GENERATOR', 'PROTOCOL', 'RESP2', 'CORPUS', 'BOUNDARY', 'VIOLATIONS', 'OFF'],
        ['DEBUG', 'EVIL', 'FRAMING', 'AUTO'],
        ['DEBUG', 'EVIL', 'TRANSPORT', 'OFF'],
    ];

    protected function setUp(): void
    {
        foreach (self::ENVIRONMENT as $variable) {
            putenv($variable);
        }
        // Existing lifecycle tests use a fixed transport control plan.
        putenv('EVILRESP_TRANSPORT_FAULT=OFF');
    }

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
            ['DEBUG', 'EVIL', 'TRANSPORT', 'TRUNCATE', '6', 'EXTRA', '2', 'CHUNKS', '1,3,4', 'PROBABILITY', '25.00',
                'FAULT', 'OFF', 'AT', 'AFTER', 'DURATION', '10000'],
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
            $sent = $client->sent;
            $hooks->recordScriptSetup();
            self::assertSame($sent, $client->sent, 'Writing saved setup must not re-arm the live target');
            ScriptLogger::comment('scenario setup starts here');
            $hooks->dispatchPreCommand($this->pending($client));
            ScriptLogger::finish();
            $script = file_get_contents($path);
            self::assertIsString($script);
            self::assertSame(1, substr_count($script, 'phase=setup fault-schedule'));
            $fault = strpos($script, 'phase=setup fault-schedule');
            $scenario = strpos($script, 'scenario setup starts here');
            self::assertIsInt($fault);
            self::assertIsInt($scenario);
            self::assertLessThan($scenario, $fault);
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
            'transport_fault', 'transport_at', 'transport_duration',
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
        $client->proxy->answer(['TRANSPORT', 'FAULT', 'STALL', 'AT', 'BEFORE', 'DURATION', '3600000']);
        $client->proxy->answer(['FRAMING', 'LENGTH']);
        $hooks = $this->load();
        $hooks->setSeed(123);
        $hooks->dispatchPostConstructor($client, 0);
        self::assertStringContainsString('strategy=PRESERVE', $client->proxy->status);
        self::assertStringContainsString('generator_violations=OFF', $client->proxy->status);
        self::assertStringContainsString('transport=OFF', $client->proxy->status);
        self::assertStringContainsString('transport_fault=OFF transport_at=AFTER transport_duration=10000', $client->proxy->status);
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
            'FAULT', 'OFF', 'AT', 'AFTER', 'DURATION', '10000',
        ], $client->sent);
    }

    public function testDefaultFaultPlansAreSeededAndNeverStall(): void
    {
        putenv('EVILRESP_TRANSPORT_FAULT');
        $options = new EvilrespOptions();
        $faults = $points = [];
        foreach (range(0, 99) as $seed) {
            $plan = $options->draw(new Randomizer(new Mt19937($seed)));
            self::assertSame($plan, $options->draw(new Randomizer(new Mt19937($seed))));
            self::assertContains($plan['transport_fault'], ['OFF', 'CLOSE', 'RESET']);
            self::assertContains($plan['transport_at'], ['BEFORE', 'AFTER', 'REPLY', 'RANDOM']);
            self::assertGreaterThanOrEqual(1, (float) $plan['transport_probability']);
            self::assertLessThanOrEqual(5, (float) $plan['transport_probability']);
            self::assertSame($plan['transport_fault'] === 'OFF' ? 'OFF' : 'ON', $plan['transport']);
            $faults[$plan['transport_fault']] = true;
            $points[$plan['transport_at']] = true;
        }
        self::assertCount(3, $faults);
        self::assertCount(4, $points);
    }

    public function testConnectionFaultsArmEveryMasterRearmAndAppearInTheScript(): void
    {
        putenv('EVILRESP_TRANSPORT_FAULT=close,reset');
        putenv('EVILRESP_TRANSPORT_AT=before,after,reply,random,0003');
        putenv('EVILRESP_TRANSPORT_PROBABILITY=2-8');
        $plans = [];
        foreach ([123, 123, 456] as $seed) {
            $hooks = $this->load();
            $hooks->setSeed($seed);
            $client = $this->standalone();
            $cluster = $this->cluster([['127.0.0.1', 7200], ['127.0.0.1', 7201]]);
            $hooks->dispatchPostConstructor($client, 0);
            $hooks->dispatchPostConstructor($cluster, 1);
            $sentCommands = $client->sent;
            $plan = array_pop($sentCommands);
            self::assertNotNull($plan);
            $plans[] = $plan;
            self::assertIsString($plan[12]);
            self::assertIsString($plan[14]);
            self::assertContains($plan[12], ['CLOSE', 'RESET']);
            self::assertContains($plan[14], ['BEFORE', 'AFTER', 'REPLY', 'RANDOM', '3']);
            foreach ($cluster->_masters() as $node) {
                self::assertContains([$node, ...$plan], $cluster->sent);
            }
            $sent = count($client->sent);
            $hooks->dispatchPostCommand($this->completed($client, new \RedisException('reset')));
            $hooks->dispatchPreCommand($this->pending($client));
            self::assertSame($sent + 1, count($client->sent), 'Matching fault STATUS must not re-arm');

            $client->close();
            $hooks->dispatchPostCommand($this->completed($client, new \RedisException('EOF')));
            $hooks->dispatchPreCommand($this->pending($client));
            $sentCommands = $client->sent;
            self::assertSame($plan, array_pop($sentCommands));

            $path = tempnam(sys_get_temp_dir(), 'evilresp-fault-script-');
            self::assertIsString($path);
            try {
                ScriptLogger::init($path, clients: [$client]);
                // A fresh run records constructor arming before its first command.
                $replay = $this->load();
                $replay->setSeed($seed);
                $replay->dispatchPostConstructor($client, 0);
                $replay->dispatchPreCommand($this->pending($client));
                ScriptLogger::finish();
                $script = file_get_contents($path);
                self::assertIsString($script);
                self::assertStringContainsString("transport_fault={$plan[12]}", $script);
                self::assertStringContainsString("'FAULT', '{$plan[12]}', 'AT', '{$plan[14]}', 'DURATION', '10000'", $script);
            } finally {
                ScriptLogger::finish();
                unlink($path);
            }
        }
        self::assertSame($plans[0], $plans[1]);
        self::assertNotSame($plans[0], $plans[2]);
    }

    public function testStallsRequireExplicitSelectionAndKeepTheirDurationAndOffset(): void
    {
        putenv('EVILRESP_TRANSPORT_FAULT=stall');
        putenv('EVILRESP_TRANSPORT_AT=0000,9223372036854775808,18446744073709551615');
        putenv('EVILRESP_TRANSPORT_DURATION=000123');
        $options = new EvilrespOptions();
        $offsets = [];
        foreach (range(0, 19) as $seed) {
            $plan = $options->draw(new Randomizer(new Mt19937($seed)));
            self::assertSame('STALL', $plan['transport_fault']);
            self::assertSame('123', $plan['transport_duration']);
            self::assertContains($plan['transport_at'], ['0', '9223372036854775808', '18446744073709551615']);
            $commands = EvilrespOptions::commands($plan);
            self::assertSame(['FAULT', 'STALL', 'AT', $plan['transport_at'], 'DURATION', '123'], array_slice($commands[4], -6));
            $offsets[$plan['transport_at']] = true;
        }
        self::assertCount(3, $offsets);
    }

    public function testLegacyTruncationAndExplicitTransportOffSuppressDefaultConnectionFaults(): void
    {
        putenv('EVILRESP_TRANSPORT_FAULT');
        putenv('EVILRESP_TRANSPORT_TRUNCATE=RANDOM');
        $plan = (new EvilrespOptions())->draw(new Randomizer(new Mt19937(123)));
        self::assertSame('OFF', $plan['transport_fault']);
        self::assertSame('RANDOM', $plan['transport_truncate']);
        self::assertSame('100.00', $plan['transport_probability']);
        putenv('EVILRESP_TRANSPORT_TRUNCATE');
        putenv('EVILRESP_TRANSPORT=OFF');
        $plan = (new EvilrespOptions())->draw(new Randomizer(new Mt19937(123)));
        self::assertSame('OFF', $plan['transport_fault']);
        self::assertSame(['TRANSPORT', 'OFF'], EvilrespOptions::commands($plan)[4]);
    }

    public function testExplicitConnectionFaultsRejectLegacyTruncation(): void
    {
        putenv('EVILRESP_TRANSPORT_TRUNCATE=0');
        putenv('EVILRESP_TRANSPORT_FAULT=OFF,CLOSE');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('EVILRESP_TRANSPORT_TRUNCATE=OFF');
        $this->load();
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
            ['EVILRESP_TRANSPORT_FAULT', 'RANDOM'],
            ['EVILRESP_TRANSPORT_FAULT', 'CLOSE,'],
            ['EVILRESP_TRANSPORT_FAULT', 'CLOSE,timeout'],
            ['EVILRESP_TRANSPORT_AT', '-1'],
            ['EVILRESP_TRANSPORT_AT', 'BEFORE,'],
            ['EVILRESP_TRANSPORT_AT', '18446744073709551616'],
            ['EVILRESP_TRANSPORT_DURATION', '0'],
            ['EVILRESP_TRANSPORT_DURATION', '3600001'],
            ['EVILRESP_TRANSPORT_DURATION', '-1'],
            ['EVILRESP_TRANSPORT_DURATION', '1000'],
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
        putenv('EVILRESP_TRANSPORT_FAULT');
        putenv('EVILRESP_TRANSPORT=OFF');
        putenv('EVILRESP_TRANSPORT_EXTRA=1');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('EVILRESP_TRANSPORT_EXTRA');
        $this->load();
    }

    /** @return list<array{string, string}> */
    public static function conflictingFaultOptions(): array
    {
        return [['FAULT', 'CLOSE'], ['AT', 'BEFORE'], ['DURATION', '1000']];
    }

    #[DataProvider('conflictingFaultOptions')]
    public function testTransportOffRejectsFaultOptions(string $option, string $value): void
    {
        putenv('EVILRESP_TRANSPORT_FAULT');
        putenv('EVILRESP_TRANSPORT=OFF');
        putenv("EVILRESP_TRANSPORT_{$option}={$value}");
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("EVILRESP_TRANSPORT_{$option}");
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

    public function testChaosUsesOneSeededPresetOnStandaloneAndEveryClusterMaster(): void
    {
        $sequences = [];
        foreach ([123, 123, 456] as $seed) {
            $hooks = $this->load(['mode' => 'chaos', 'chaos_min' => '5', 'chaos_max' => '75']);
            $hooks->setSeed($seed);
            $client = $this->standalone();
            $hooks->dispatchPostConstructor($client, 0);
            self::assertCount(4, $client->sent);
            $command = $client->sent[2];
            self::assertSame(['DEBUG', 'CHAOS'], array_slice($command, 0, 2));
            self::assertIsString($command[2]);
            self::assertGreaterThanOrEqual(5, (float) $command[2]);
            self::assertLessThanOrEqual(75, (float) $command[2]);
            self::assertSame(['SEED', (string) $seed, 'HASH', 'BLAKE3'], array_slice($command, 3));
            $sequences[] = $command;
            $cluster = $this->cluster([['127.0.0.1', 7200], ['127.0.0.1', 7201]]);
            $hooks->dispatchPostConstructor($cluster, 1);
            self::assertCount(6, $cluster->sent);
            foreach ($cluster->_masters() as $node) {
                self::assertContains([$node, ...$command], $cluster->sent);
            }

            // An unchanged snapshot only needs STATUS; a changed expansion
            // or a fresh connection must receive the original preset again.
            foreach ([false, true] as $changed) {
                if ($changed) {
                    $client->proxy->status .= ' future_fault=ON';
                }
                $client->sent = [];
                $hooks->dispatchPostCommand($this->completed($client, new \RedisException('protocol error')));
                $hooks->dispatchPreCommand($this->pending($client));
                self::assertCount($changed ? 3 : 1, $client->sent);
                if ($changed) {
                    $this->assertChaosPreset($command, $client);
                }
            }
            $client->close();
            $client->sent = [];
            $hooks->dispatchPostCommand($this->completed($client, method: 'close'));
            $hooks->dispatchPreCommand($this->pending($client));
            $this->assertChaosPreset($command, $client);
        }
        self::assertSame($sequences[0], $sequences[1]);
        self::assertNotSame($sequences[0][2], $sequences[2][2]);
    }

    /** @param list<mixed> $command */
    private function assertChaosPreset(array $command, FakeEvilrespRedis $client): void
    {
        self::assertSame($command, $client->sent[1]);
    }

    public function testFreshChaosSeedsVaryOnArmingAndReplayFromTheRunSeed(): void
    {
        $sequences = [];
        foreach ([123, 123, 456] as $seed) {
            $hooks = $this->load(['mode' => 'chaos', 'fresh_seed' => '1']);
            $hooks->setSeed($seed);
            $client = $this->standalone();
            $hooks->dispatchPostConstructor($client, 0);
            $first = $client->sent[2];
            self::assertIsString($first[4]);
            $draws = [$first[4]];
            if ($sequences === []) {
                $hooks->recordScriptSetup();
            }
            // The CLI seeds before construction, then Tester::run seeds again.
            $hooks->setSeed($seed);
            for ($i = 0; $i < 20; $i++) {
                $client->sent = [];
                $hooks->dispatchPostCommand($this->completed($client, new \RedisException('protocol error')));
                $hooks->dispatchPreCommand($this->pending($client));
                self::assertSame([['DEBUG', 'EVIL', 'STATUS']], $client->sent);

                $client->close();
                $client->sent = [];
                $hooks->dispatchPostCommand($this->completed($client, method: 'close'));
                $hooks->dispatchPreCommand($this->pending($client));
                $command = $this->sent($client)[1];
                self::assertSame($first[2], $command[2]);
                self::assertIsString($command[4]);
                $draws[] = $command[4];
            }
            self::assertCount(21, array_unique($draws));
            $sequences[] = $draws;
        }
        self::assertSame($sequences[0], $sequences[1]);
        self::assertNotSame($sequences[0], $sequences[2]);
    }

    public function testFreshChaosScriptSetupRetainsEachNodesSeedWithoutDrawingAgain(): void
    {
        $hooks = $this->load(['mode' => 'chaos', 'fresh_seed' => 'true']);
        $hooks->setSeed(123);
        $cluster = $this->cluster([['127.0.0.1', 7200], ['127.0.0.1', 7201]]);
        $hooks->dispatchPostConstructor($cluster, 0);
        $commands = array_values(array_filter($cluster->sent, static fn (array $command): bool => $command[2] === 'CHAOS'));
        self::assertCount(2, $commands);
        self::assertNotSame($commands[0][5], $commands[1][5]);
        $path = tempnam(sys_get_temp_dir(), 'evilresp-fresh-seed-script-');
        self::assertIsString($path);
        try {
            ScriptLogger::init($path, clients: [$cluster]);
            $hooks->recordScriptSetup();
            // A changed node gets a new seed; the other node's snapshot stays valid.
            $cluster->rawCommand(['127.0.0.1', 7200], 'DEBUG', 'EVIL', 'MODE', 'OFF');
            $cluster->sent = [];
            $hooks->dispatchPostCommand($this->completed($cluster, new \RedisClusterException('protocol error')));
            $hooks->dispatchPreCommand($this->pending($cluster));
            $rearmed = $this->sent($cluster);
            self::assertCount(4, $rearmed);
            self::assertNotSame($commands[0][5], $rearmed[1][5]);
            self::assertNotSame($commands[1][5], $rearmed[1][5]);
            $commands[] = $rearmed[1];
            ScriptLogger::finish();
            $script = file_get_contents($path);
            self::assertIsString($script);
            foreach ($commands as $command) {
                self::assertIsString($command[5]);
                self::assertStringContainsString("'SEED', '" . $command[5] . "'", $script);
            }
        } finally {
            ScriptLogger::finish();
            unlink($path);
        }
    }

    public function testFreshChaosSeedOverrideIsTheSequenceBase(): void
    {
        putenv('EVILRESP_SEED=99');
        $seeds = [];
        foreach ([123, 456] as $seed) {
            $hooks = $this->load(['mode' => 'chaos', 'fresh_seed' => '1']);
            $hooks->setSeed($seed);
            $client = $this->standalone();
            $hooks->dispatchPostConstructor($client, 0);
            $seeds[] = $client->sent[2][4];
        }
        self::assertSame($seeds[0], $seeds[1]);
        self::assertNotSame('99', $seeds[0]);
    }

    public function testZeroChaosAcceptsTlsHNullAndLogsConcreteArming(): void
    {
        $hooks = $this->load(['mode' => 'chaos', 'chaos_min' => '0', 'chaos_max' => '0', 'hash' => 'tlsh']);
        $hooks->setSeed(123);
        $client = $this->standalone();
        $hooks->dispatchPostConstructor($client, 0);
        self::assertStringContainsString('mode=OFF', $client->proxy->status);
        self::assertSame(['DEBUG', 'CHAOS', '0.00', 'SEED', '123', 'HASH', 'TLSH'], $client->sent[2]);
        $path = tempnam(sys_get_temp_dir(), 'evilresp-chaos-script-');
        self::assertIsString($path);
        try {
            ScriptLogger::init($path, clients: [$client]);
            $hooks->dispatchPreCommand($this->pending($client));
            ScriptLogger::finish();
            $script = file_get_contents($path);
            self::assertIsString($script);
            self::assertStringContainsString("'DEBUG', 'CHAOS', '0.00', 'SEED', '123', 'HASH', 'TLSH'", $script);
            self::assertStringContainsString('mode=CHAOS chaos=0.00 hash=TLSH', $script);
        } finally {
            ScriptLogger::finish();
            unlink($path);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function freshSeedOptions(): iterable
    {
        yield 'fixed' => ['0'];
        yield 'fresh' => ['1'];
    }

    #[DataProvider('freshSeedOptions')]
    public function testChaosDoesNotAcceptOkInPlaceOfAFingerprint(string $freshSeed): void
    {
        $hooks = $this->load(['mode' => 'chaos', 'fresh_seed' => $freshSeed]);
        $hooks->setSeed(123);
        $client = $this->standalone();
        $client->proxy->chaosReply = 'OK';
        $hooks->dispatchPostConstructor($client, 0);
        $firstSeed = $client->sent[2][4];
        // Failed arming is cooled down and retried, never recorded as armed.
        for ($i = 0; $i < 25; $i++) {
            $hooks->dispatchPostCommand($this->completed($client));
        }
        $client->proxy->chaosReply = null;
        $client->sent = [];
        $hooks->dispatchPreCommand($this->pending($client));
        self::assertSame('CHAOS', $this->sent($client)[1][1]);
        $nextSeed = $this->sent($client)[1][4];
        if ($freshSeed === '1') {
            self::assertNotSame($firstSeed, $nextSeed);
        } else {
            self::assertSame($firstSeed, $nextSeed);
        }
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidChaosOptions(): iterable
    {
        foreach (['-1', '101', 'NaN', 'INF', '1e2', '0.001', ''] as $value) {
            yield 'temperature ' . $value => [['mode' => 'chaos', 'chaos_min' => $value]];
        }
        yield 'reversed' => [['mode' => 'chaos', 'chaos_min' => '76', 'chaos_max' => '75']];
        yield 'mode' => [['mode' => 'oops']];
        yield 'typo' => [['mode' => 'chaos', 'choas_min' => '5']];
        yield 'hash' => [['mode' => 'chaos', 'hash' => 'SHA256']];
        yield 'manual' => [['chaos_min' => '5']];
        yield 'fresh seed without chaos' => [['fresh_seed' => '1']];
        yield 'invalid fresh seed' => [['mode' => 'chaos', 'fresh_seed' => 'random']];
    }

    /** @param array<string, string> $arguments */
    #[DataProvider('invalidChaosOptions')]
    public function testInvalidChaosOptionsFailAtLoad(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->load($arguments);
    }

    public function testChaosFingerprintValidation(): void
    {
        $options = new EvilrespChaosOptions(['mode' => 'chaos', 'hash' => 'TLSH']);
        self::assertTrue($options->acceptsFingerprint('TNULL'));
        self::assertTrue($options->acceptsFingerprint('T1' . str_repeat('A', 70)));
        self::assertFalse($options->acceptsFingerprint(true));
        self::assertFalse($options->acceptsFingerprint('OK'));
    }

    /** @param array<string, string> $arguments */
    private function load(array $arguments = []): HookRegistry
    {
        return HookLoader::load([dirname(__DIR__, 2) . '/hooks/evilresp.php'], ['evilresp' => $arguments]);
    }

    private function pending(\Redis|\RedisCluster $client, string $method = 'ping'): PendingInvocation
    {
        return new PendingInvocation($method, $client, $method, []);
    }

    private function completed(\Redis|\RedisCluster $client, ?\Throwable $throwable = null, string $method = 'ping'): CompletedInvocation
    {
        return new CompletedInvocation($method, $client, $method, [], $throwable === null ? true : null, $throwable, 0.0);
    }

    private function standalone(): FakeEvilrespRedis
    {
        return new FakeEvilrespRedis();
    }

    /** @return list<list<mixed>> */
    private function sent(FakeEvilrespRedis|FakeEvilrespCluster $client): array
    {
        return $client->sent;
    }

    /** @param list<array{string, int}> $masters */
    private function cluster(array $masters): FakeEvilrespCluster
    {
        return new FakeEvilrespCluster($masters);
    }
}
