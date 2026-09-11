<?php

declare(strict_types=1);

/**
 * evilresp hook: fuzz the client through a deliberately hostile RESP proxy.
 *
 * evilresp (https://github.com/michael-grunder/evilresp) sits between the
 * client and a real server. Until told otherwise it is a transparent proxy;
 * `DEBUG EVIL` commands switch a connection into returning deterministic
 * random frames (`RANDOM`), recursively mutated upstream replies (`MUTATE`),
 * or replies pushed toward overflow-prone lengths and counts (`OVERFLOW`),
 * and in cluster mode into lying about slot ownership (`TOPOLOGY`). This hook
 * turns that on for every client the fuzzer uses:
 *
 *     evilresp --proxy 127.0.0.1:6379 --listen 127.0.0.1:6390
 *     bin/phpredis-fuzz --port=6390 --seed=123 --hook=hooks/evilresp.php
 *
 * Point the fuzzer at the proxy, never at the upstream server. Both the RESP
 * mutations and the client's misbehavior on them are the point, so expect
 * exceptions, warnings and wrong replies in the report: the value is in
 * crashes, hangs, leaks and memory corruption, which is what the harness
 * captures.
 *
 * Reproducibility. evilresp derives each mutation from its own seed, a global
 * command index, the command bytes and the upstream reply. The hook seeds the
 * proxy with the run seed, resets the command index once per run, and chooses
 * the mode and probabilities from `HookRegistry::randomizer('evilresp')`,
 * which is itself derived from the run seed. Rerunning with the same --seed,
 * the same hook file and the same environment therefore replays the same
 * proxy behavior, provided the fuzzer is the proxy's only client. The chosen
 * plan is printed to stderr and written as a comment into --script-log.
 *
 * Connections. `DEBUG EVIL` state belongs to a proxy connection, and both
 * PhpRedis and Relay reconnect silently, so a connection replaced after a
 * corrupt reply would continue un-evil without anyone noticing. Neither
 * client reports that reliably (`isConnected()` stays true, Relay's
 * `socketId()` does not change), so the hook asks the proxy instead: before a
 * command it re-checks `DEBUG EVIL STATUS` on a client whose previous call
 * threw, which connected or closed, or which ran EVILRESP_PROBE_INTERVAL
 * commands since the last check, and re-arms whatever answered with a
 * different configuration. Cluster clients are checked on every master
 * `_masters()` reports. The check waits while the client is inside MULTI or
 * a pipeline.
 *
 * Every `DEBUG EVIL` the hook sends is recorded in --script-log through
 * ScriptLogger. The arming done from postConstructor precedes the script log
 * and is written into it, approximately, at the run's first command; the
 * exact reproduction path is the fuzzer with the same --seed and this hook,
 * which is what the harness reruns.
 *
 * Environment (all optional):
 *
 *   EVILRESP_MODES=MUTATE,OVERFLOW,RANDOM  Candidate modes; one is drawn per
 *                                          run. OFF is allowed as a control.
 *   EVILRESP_PROBABILITY=1-25              Reply mutation probability in
 *                                          percent, fixed (`10`) or a range
 *                                          to draw from (`0.5-40`).
 *   EVILRESP_TOPOLOGY=0-10                 MOVED/ASK lie probability, same
 *                                          syntax. Sent to cluster clients.
 *   EVILRESP_CANONICALIZE=ALL|UNORDERED|NONE  Leave unset for the proxy default.
 *   EVILRESP_INCLUDE=a,b,^GET.*,@write     Only mutate matching commands.
 *   EVILRESP_EXCLUDE=a,b                   Never mutate these, in addition to
 *                                          evilresp's own defaults.
 *   EVILRESP_SEED=N                        Proxy seed; defaults to the run seed.
 *   EVILRESP_RESET=0                       Skip `DEBUG EVIL MODE RESET`. The
 *                                          reset re-zeroes the proxy's command
 *                                          index but also drops every other
 *                                          client connection the proxy has,
 *                                          so disable it on a shared proxy and
 *                                          restart the proxy per run instead.
 *   EVILRESP_PROBE_INTERVAL=50             Commands between periodic STATUS
 *                                          checks per client; 0 disables them.
 *   EVILRESP_VERBOSE=1                     Log every DEBUG EVIL exchange.
 *
 * Limitations:
 *
 * - `--forks` shares one proxy command index between concurrent children, so
 *   a forked run is not reproducible through evilresp.
 * - A cluster connection the client opens between checks (a MOVED to a node
 *   it had not used yet) stays un-evil until the next check.
 * - Relay's client-side cache is invalidated by asynchronous server pushes,
 *   so two Relay runs with the same seed can differ in which replies came
 *   from the cache and therefore in which commands reached the proxy; the
 *   PhpRedis clients replay exactly.
 */

use Mgrunder\PhpredisCommandFuzzer\Hooks\ClientEvent;
use Mgrunder\PhpredisCommandFuzzer\Hooks\CompletedInvocation;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookDecision;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookRegistry;
use Mgrunder\PhpredisCommandFuzzer\Hooks\InvocationHook;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PendingInvocation;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PostCommandHook;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PostConstructorHook;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PreCommandHook;
use Mgrunder\PhpredisCommandFuzzer\ScriptLogger;
use Random\Randomizer;

return static function (HookRegistry $hooks): void {
    $env = static function (string $name): ?string {
        $value = getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    };

    /** @return list<string> */
    $list = static function (?string $value): array {
        if ($value === null) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $item): bool => $item !== '',
        ));
    };

    /* "10", "12.5" or "MIN-MAX", in percent, as hundredths so the drawn
       value has exactly the two decimals evilresp echoes back in STATUS. */
    /** @return array{int, int} */
    $range = static function (string $name, string $default): array {
        $value = getenv($name);
        $value = is_string($value) && trim($value) !== '' ? trim($value) : $default;
        $parts = explode('-', $value, 2);
        $bounds = [];
        foreach ($parts as $part) {
            if (!is_numeric($part)) {
                throw new InvalidArgumentException(
                    "{$name} must be a percentage or a MIN-MAX range, got \"{$value}\"",
                );
            }
            $percent = (float) $part;
            if ($percent < 0.0 || $percent > 100.0) {
                throw new InvalidArgumentException("{$name} must stay within 0-100, got \"{$value}\"");
            }
            $bounds[] = (int) round($percent * 100);
        }
        $low = $bounds[0];
        $high = $bounds[1] ?? $bounds[0];
        if ($low > $high) {
            [$low, $high] = [$high, $low];
        }

        return [$low, $high];
    };

    $modes = array_map('strtoupper', $list($env('EVILRESP_MODES') ?? 'MUTATE,OVERFLOW,RANDOM'));
    if ($modes === []) {
        throw new InvalidArgumentException('EVILRESP_MODES cannot be empty');
    }
    foreach ($modes as $mode) {
        if (!in_array($mode, ['OFF', 'RANDOM', 'MUTATE', 'OVERFLOW'], true)) {
            throw new InvalidArgumentException("EVILRESP_MODES contains an unknown mode \"{$mode}\"");
        }
    }

    $canonicalize = $env('EVILRESP_CANONICALIZE');
    if ($canonicalize !== null) {
        $canonicalize = strtoupper($canonicalize);
        if (!in_array($canonicalize, ['ALL', 'UNORDERED', 'NONE'], true)) {
            throw new InvalidArgumentException(
                "EVILRESP_CANONICALIZE must be ALL, UNORDERED or NONE, got \"{$canonicalize}\"",
            );
        }
    }

    $seed = $env('EVILRESP_SEED');
    if ($seed !== null) {
        $seed = filter_var($seed, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (!is_int($seed)) {
            throw new InvalidArgumentException('EVILRESP_SEED must be a non-negative integer');
        }
    }

    $interval = $env('EVILRESP_PROBE_INTERVAL') ?? '50';
    if (!ctype_digit($interval)) {
        throw new InvalidArgumentException("EVILRESP_PROBE_INTERVAL must be a non-negative integer, got \"{$interval}\"");
    }

    /* evilresp reserves DEBUG PROTOCOL for fingerprints, shadowing Redis's
       reply-type probes. Adapt only the catalog's known probe shapes. */
    $hooks->add('evilresp-protocol', new class($hooks) implements InvocationHook {
        public function __construct(private readonly HookRegistry $hooks)
        {
        }

        public function beforeInvocation(PendingInvocation $invocation): HookDecision
        {
            if (strtolower($invocation->method) !== 'rawcommand') {
                return HookDecision::allow();
            }

            $offset = $invocation->client instanceof RedisCluster
                || $invocation->client instanceof Relay\Cluster ? 1 : 0;
            $arguments = $invocation->arguments;
            $command = array_slice($arguments, $offset);
            if (count($command) !== 3
                || !is_string($command[0]) || strcasecmp($command[0], 'DEBUG') !== 0
                || !is_string($command[1]) || strcasecmp($command[1], 'PROTOCOL') !== 0
                || !is_string($command[2])
                || !in_array(strtolower($command[2]), [
                    'array', 'bignum', 'double', 'integer', 'map', 'null', 'push', 'set', 'verbatim',
                ], true)) {
                return HookDecision::allow();
            }

            $random = $this->hooks->randomizer('evilresp-protocol');

            return HookDecision::replace([
                ...array_slice($arguments, 0, $offset),
                'DEBUG', 'PROTOCOL',
                ['IN', 'OUT'][$random->getInt(0, 1)],
                ['BLAKE3', 'TLSH'][$random->getInt(0, 1)],
            ]);
        }
    });

    $hooks->addLifecycleHook(
        'evilresp',
        new class(
            $hooks,
            $modes,
            $range('EVILRESP_PROBABILITY', '1-25'),
            $range('EVILRESP_TOPOLOGY', '0-10'),
            $canonicalize,
            $list($env('EVILRESP_INCLUDE')),
            $list($env('EVILRESP_EXCLUDE')),
            $seed,
            $env('EVILRESP_RESET') !== '0',
            (int) $interval,
            $env('EVILRESP_VERBOSE') !== null,
        ) implements PostConstructorHook, PreCommandHook, PostCommandHook {
            /** DEBUG EVIL EXCLUDE replaces the proxy's list, so its defaults are restated. */
            private const DEFAULT_EXCLUDE = [
                'AUTH', 'HELLO', 'COMMAND', 'CLIENT', 'SELECT', 'ASKING', 'MULTI',
                'DISCARD', 'SUBSCRIBE', 'PSUBSCRIBE', 'SSUBSCRIBE', 'UNSUBSCRIBE', 'QUIT',
            ];

            /** Client methods after which the connection is known to be new or gone. */
            private const CONNECTION_METHODS = ['connect', 'pconnect', 'open', 'popen', 'close', 'pclose'];

            /** Consecutive checks reaching none of a client's connections before the run is aborted. */
            private const MAX_CONSECUTIVE_FAILURES = 5;

            /** Commands to leave a client alone after a check could not reach one of its connections. */
            private const FAILURE_COOLDOWN = 25;

            /** Server errors that mean the target is not evilresp or refused the configuration. */
            private const REJECTION_PATTERN = '/unknown subcommand|wrong number of arguments|DEBUG command not allowed|invalid evil configuration/i';

            /**
             * The configuration drawn for the current run seed.
             *
             * @var array{
             *     seed: int, serial: int, evilSeed: int, mode: string,
             *     probability: string, topology: string, summary: string,
             * }|null
             */
            private ?array $plan = null;

            private int $serial = 0;

            /** Plan serial whose MODE RESET has been sent, so it is sent once per run. */
            private int $resetSerial = 0;

            /** Plan serial whose arming has been written to the script log. */
            private int $scriptSerial = 0;

            /**
             * Per-client bookkeeping: the plan it was armed with, commands
             * since the last STATUS check, whether the next command must be
             * preceded by a check, commands to wait after a failed check,
             * and consecutive checks that reached no connection at all.
             *
             * @var WeakMap<object, array{plan: int, since: int, verify: bool, cooldown: int, failures: int}>
             */
            private WeakMap $clients;

            /**
             * @param list<string> $modes
             * @param array{int, int} $probability Hundredths of a percent.
             * @param array{int, int} $topology Hundredths of a percent.
             * @param list<string> $include
             * @param list<string> $exclude
             */
            public function __construct(
                private readonly HookRegistry $hooks,
                private readonly array $modes,
                private readonly array $probability,
                private readonly array $topology,
                private readonly ?string $canonicalize,
                private readonly array $include,
                private readonly array $exclude,
                private readonly ?int $seedOverride,
                private readonly bool $reset,
                private readonly int $probeInterval,
                private readonly bool $verbose,
            ) {
                /** @var WeakMap<object, array{plan: int, since: int, verify: bool, cooldown: int, failures: int}> $clients */
                $clients = new WeakMap();
                $this->clients = $clients;
            }

            public function postConstructor(ClientEvent $event): void
            {
                if ($this->atomic($event->client)) {
                    $this->ensureArmed($event->client);
                }
            }

            public function preCommand(PendingInvocation $invocation): void
            {
                $client = $invocation->client;
                if (!$this->atomic($client)) {
                    return;
                }

                $plan = $this->plan();
                if ($this->scriptSerial !== $plan['serial']) {
                    $this->scriptSerial = $plan['serial'];
                    $this->replayArming($plan);
                }

                $state = $this->clients[$client] ?? null;
                if ($state !== null && $state['cooldown'] > 0) {
                    return;
                }
                if ($state === null
                    || $state['plan'] !== $plan['serial']
                    || $state['verify']
                    || ($this->probeInterval > 0 && $state['since'] >= $this->probeInterval)) {
                    $this->ensureArmed($client);
                }
            }

            public function postCommand(CompletedInvocation $invocation): void
            {
                $client = $invocation->client;
                $state = $this->clients[$client] ?? null;
                if ($state === null) {
                    return;
                }

                $state['since']++;
                if ($state['cooldown'] > 0) {
                    $state['cooldown']--;
                } elseif ($invocation->throwable !== null
                    || in_array(strtolower($invocation->method), self::CONNECTION_METHODS, true)) {
                    $state['verify'] = true;
                }
                $this->clients[$client] = $state;
            }

            /**
             * Check every connection the client holds and re-arm the ones
             * whose configuration is not the plan's. A client exception on
             * one connection is that connection being replaced, the reply
             * stream being out of step after a corrupt reply, or a cluster
             * node the client has given up on; the others are still armed,
             * and the client is checked again after a cooldown. Only a
             * client none of whose connections answers, repeatedly, aborts
             * the run, because then nothing is being fuzzed through the
             * proxy at all.
             */
            private function ensureArmed(Redis|RedisCluster|Relay\Relay|Relay\Cluster $client): void
            {
                $plan = $this->plan();
                $state = $this->clients[$client]
                    ?? ['plan' => 0, 'since' => 0, 'verify' => true, 'cooldown' => 0, 'failures' => 0];

                $reached = 0;
                $failure = null;
                foreach ($this->nodes($client) as $node) {
                    try {
                        $status = $this->status($client, $node);
                        /* A connection already carrying the plan is left
                           alone, unless the run's reset is still owed: a
                           proxy left running by an earlier run with the same
                           seed reports the right plan but a stale index. */
                        if (!str_starts_with($status, $this->expectedStatus($client, $plan))
                            || $this->resetPending($plan)) {
                            $this->arm($client, $node, $plan);
                        }
                        $reached++;
                    } catch (UnexpectedValueException $rejected) {
                        /* The target answered, and it is not evilresp or it
                           refused the configuration: a setup error, not a
                           flaky connection. */
                        throw $rejected;
                    } catch (Throwable $throwable) {
                        $failure = $throwable;
                        $this->log(sprintf(
                            'check on %s failed: %s',
                            $this->describe($client, $node),
                            $throwable->getMessage(),
                        ));
                    }
                }

                if ($failure === null) {
                    $this->clients[$client] = [
                        'plan' => $plan['serial'], 'since' => 0, 'verify' => false, 'cooldown' => 0, 'failures' => 0,
                    ];

                    return;
                }

                $state['plan'] = $plan['serial'];
                $state['since'] = 0;
                $state['verify'] = true;
                $state['cooldown'] = self::FAILURE_COOLDOWN;
                $state['failures'] = $reached === 0 ? $state['failures'] + 1 : 0;
                $this->clients[$client] = $state;
                if ($state['failures'] >= self::MAX_CONSECUTIVE_FAILURES) {
                    throw new RuntimeException(sprintf(
                        'evilresp: no connection of %s answered DEBUG EVIL in %d consecutive checks: %s',
                        $client::class,
                        $state['failures'],
                        $failure->getMessage(),
                    ), 0, $failure);
                }
            }

            /**
             * Draw the run's configuration from the hook random stream. The
             * registry seed changes when a new run starts, which is when a
             * new plan (and a new proxy reset) is due.
             *
             * @return array{
             *     seed: int, serial: int, evilSeed: int, mode: string,
             *     probability: string, topology: string, summary: string,
             * }
             */
            private function plan(): array
            {
                $seed = $this->hooks->seed();
                if ($this->plan !== null && $this->plan['seed'] === $seed) {
                    return $this->plan;
                }

                $random = $this->hooks->randomizer('evilresp');
                /* randomizer() has thrown if the registry is unseeded. */
                assert($seed !== null);

                $mode = $this->modes[$random->getInt(0, count($this->modes) - 1)];
                $plan = [
                    'seed' => $seed,
                    'serial' => ++$this->serial,
                    'evilSeed' => $this->seedOverride ?? $seed,
                    'mode' => $mode,
                    'probability' => $mode === 'OFF' ? '0.00' : $this->percent($random, $this->probability),
                    'topology' => $this->percent($random, $this->topology),
                    'summary' => '',
                ];
                $plan['summary'] = sprintf(
                    'seed=%d mode=%s probability=%s topology=%s canonicalize=%s include=[%s] exclude=[%s] reset=%s',
                    $plan['evilSeed'],
                    $plan['mode'],
                    $plan['probability'],
                    $plan['topology'],
                    $this->canonicalize ?? 'default',
                    implode(',', $this->include),
                    implode(',', $this->exclude),
                    $this->reset ? 'yes' : 'no',
                );
                fwrite(STDERR, "evilresp: {$plan['summary']}\n");

                return $this->plan = $plan;
            }

            /**
             * Clients are armed from postConstructor, before the run opens
             * its script log, so the first command of a run writes that
             * arming into the script after the fact. The replay is close
             * rather than exact: in the script every client is connected
             * before the reset, so the ones not issuing it are closed first
             * and reconnect clean, and the proxy's command index will not
             * match a run in which the client sent anything before its
             * first logged command.
             *
             * @param array{serial: int, evilSeed: int, mode: string, probability: string, topology: string, summary: string} $plan
             */
            private function replayArming(array $plan): void
            {
                ScriptLogger::comment("evilresp: {$plan['summary']}");
                ScriptLogger::comment('evilresp: the run armed the proxy from postConstructor; replayed here');
                $first = true;
                foreach ($this->clients as $client => $state) {
                    if ($state['plan'] !== $plan['serial']) {
                        continue;
                    }
                    assert($client instanceof Redis || $client instanceof RedisCluster
                        || $client instanceof Relay\Relay || $client instanceof Relay\Cluster);
                    foreach ($this->nodes($client) as $node) {
                        if (!$first && $node === null) {
                            ScriptLogger::log($client, 'close', []);
                        }
                        foreach ($this->commands($client, $plan, $first && $this->reset) as $command) {
                            $arguments = ['DEBUG', 'EVIL', ...$command];
                            ScriptLogger::log($client, 'rawCommand', $node === null ? $arguments : [$node, ...$arguments]);
                        }
                        $first = false;
                    }
                }
            }

            /** @param array{int, int} $hundredths */
            private function percent(Randomizer $random, array $hundredths): string
            {
                return number_format($random->getInt($hundredths[0], $hundredths[1]) / 100, 2, '.', '');
            }

            /**
             * The connections to configure: one per master for a cluster
             * client, addressed the way rawCommand() accepts, or the single
             * connection of a standalone client as null.
             *
             * @return list<array{string, int}|null>
             */
            private function nodes(Redis|RedisCluster|Relay\Relay|Relay\Cluster $client): array
            {
                if ($client instanceof Redis || $client instanceof Relay\Relay) {
                    return [null];
                }

                $nodes = [];
                foreach ($client->_masters() as $master) {
                    if (!is_array($master) || !is_string($master[0] ?? null) || !is_int($master[1] ?? null)) {
                        throw new UnexpectedValueException(
                            'evilresp: ' . $client::class . '::_masters() returned an unexpected node: '
                            . var_export($master, true),
                        );
                    }
                    $nodes[] = [$master[0], $master[1]];
                }
                if ($nodes === []) {
                    throw new UnexpectedValueException('evilresp: ' . $client::class . ' reports no master nodes');
                }

                return $nodes;
            }

            /**
             * The STATUS prefix a connection armed with the plan reports.
             *
             * @param array{mode: string, evilSeed: int, probability: string, topology: string} $plan
             */
            private function expectedStatus(Redis|RedisCluster|Relay\Relay|Relay\Cluster $client, array $plan): string
            {
                return sprintf(
                    'mode=%s seed=%d probability=%s topology_probability=%s',
                    $plan['mode'],
                    $plan['evilSeed'],
                    $plan['probability'],
                    $this->cluster($client) ? $plan['topology'] : '0.00',
                );
            }

            /**
             * Ask the proxy what one connection is configured to do.
             *
             * The first attempt can fail in two ways that are not the
             * proxy's answer: the client throws because the proxy closed the
             * connection (a reset from another connection), or it returns
             * leftover bytes of the corrupt reply that made the previous
             * command throw. A standalone client is closed before the second
             * attempt so it reconnects with a clean stream; a cluster client
             * has no per-node close and is simply asked again.
             *
             * @param array{string, int}|null $node
             */
            private function status(Redis|RedisCluster|Relay\Relay|Relay\Cluster $client, ?array $node): string
            {
                $failure = new LogicException('unreachable');
                for ($attempt = 1; $attempt <= 2; $attempt++) {
                    if ($attempt === 2 && ($client instanceof Redis || $client instanceof Relay\Relay)) {
                        $client->close();
                        ScriptLogger::log($client, 'close', []);
                    }
                    try {
                        $reply = $this->send($client, $node, ['STATUS']);
                    } catch (Throwable $throwable) {
                        $failure = $throwable;
                        $this->log(sprintf(
                            'STATUS on %s attempt %d threw %s: %s',
                            $this->describe($client, $node),
                            $attempt,
                            $throwable::class,
                            $throwable->getMessage(),
                        ));
                        continue;
                    }
                    if (is_string($reply) && str_starts_with($reply, 'mode=')) {
                        return $reply;
                    }
                    /* On the clean stream after a close there is nothing
                       stale left to read, so false there is the answer. */
                    $clean = $attempt === 2 && ($client instanceof Redis || $client instanceof Relay\Relay);
                    $this->assertNotRejected($client, $node, ['STATUS'], $reply, $clean);
                    $failure = new RuntimeException(sprintf(
                        'evilresp: DEBUG EVIL STATUS on %s returned %s instead of a status; reply stream out of step?',
                        $this->describe($client, $node),
                        var_export($reply, true),
                    ));
                    $this->log($failure->getMessage());
                }

                throw $failure;
            }

            /**
             * A `false` reply carrying the server's complaint means the
             * target is not evilresp (Redis and Valkey answer DEBUG EVIL
             * with "unknown subcommand" or "DEBUG command not allowed") or
             * evilresp refused the arguments. That is a setup error and is
             * reported as one; any other wrong reply is the connection's
             * reply stream being out of step, which a later check sorts
             * out, unless the stream is known to be clean.
             *
             * @param array{string, int}|null $node
             * @param list<string> $arguments
             */
            private function assertNotRejected(
                Redis|RedisCluster|Relay\Relay|Relay\Cluster $client,
                ?array $node,
                array $arguments,
                mixed $reply,
                bool $cleanStream = false,
            ): void {
                if ($reply !== false) {
                    return;
                }
                $error = $client->getLastError();
                $error = is_string($error) && $error !== '' ? $error : 'no error reported';
                if (!$cleanStream && preg_match(self::REJECTION_PATTERN, $error) !== 1) {
                    return;
                }

                throw new UnexpectedValueException(sprintf(
                    'evilresp: %s rejected DEBUG EVIL %s: %s (is it an evilresp listener?)',
                    $this->describe($client, $node),
                    implode(' ', $arguments),
                    $error,
                ));
            }

            /**
             * @param array{string, int}|null $node
             * @param array{serial: int, evilSeed: int, mode: string, probability: string, topology: string} $plan
             */
            private function arm(Redis|RedisCluster|Relay\Relay|Relay\Cluster $client, ?array $node, array $plan): void
            {
                foreach ($this->commands($client, $plan, $this->resetPending($plan)) as $command) {
                    $reply = $this->send($client, $node, $command);
                    if ($reply !== true && $reply !== 'OK') {
                        $this->assertNotRejected($client, $node, $command, $reply);
                        throw new RuntimeException(sprintf(
                            'evilresp: DEBUG EVIL %s on %s returned %s instead of OK; reply stream out of step?',
                            implode(' ', $command),
                            $this->describe($client, $node),
                            var_export($reply, true),
                        ));
                    }
                    if ($command[0] === 'MODE' && ($command[1] ?? '') === 'RESET') {
                        $this->resetSerial = $plan['serial'];
                    }
                }
            }

            /**
             * The DEBUG EVIL subcommands that put one connection on the plan.
             *
             * @param array{evilSeed: int, mode: string, probability: string, topology: string} $plan
             * @param bool $reset Lead with MODE RESET: once per run, from
             *        whichever connection is armed first, it zeroes the
             *        proxy's command index so mutation choices line up on a
             *        rerun.
             * @return list<list<string>>
             */
            private function commands(Redis|RedisCluster|Relay\Relay|Relay\Cluster $client, array $plan, bool $reset): array
            {
                $commands = [];
                if ($reset) {
                    $commands[] = ['MODE', 'RESET'];
                }
                $commands[] = ['SEED', (string) $plan['evilSeed']];
                $commands[] = $plan['mode'] === 'OFF'
                    ? ['MODE', 'OFF']
                    : ['MODE', $plan['mode'], 'PROBABILITY', $plan['probability']];
                if ($this->cluster($client)) {
                    $commands[] = ['TOPOLOGY', $plan['topology']];
                }
                if ($this->canonicalize !== null) {
                    $commands[] = ['CANONICALIZE', $this->canonicalize];
                }
                if ($this->include !== []) {
                    $commands[] = ['INCLUDE', ...$this->include];
                }
                if ($this->exclude !== []) {
                    $commands[] = ['EXCLUDE', ...self::DEFAULT_EXCLUDE, ...$this->exclude];
                }

                return $commands;
            }

            /** @param array{serial: int} $plan */
            private function resetPending(array $plan): bool
            {
                return $this->reset && $this->resetSerial !== $plan['serial'];
            }

            /**
             * One DEBUG EVIL exchange, recorded in the script log so a
             * reproduction script re-arms the proxy where the run did.
             *
             * @param array{string, int}|null $node
             * @param list<string> $arguments
             */
            private function send(Redis|RedisCluster|Relay\Relay|Relay\Cluster $client, ?array $node, array $arguments): mixed
            {
                $arguments = ['DEBUG', 'EVIL', ...$arguments];
                /* Logged before the call, as the catalog logs its own, so a
                   call that throws is in the script too. */
                if ($client instanceof Redis || $client instanceof Relay\Relay) {
                    ScriptLogger::log($client, 'rawCommand', $arguments);
                    $reply = $client->rawCommand(...$arguments);
                } else {
                    if ($node === null) {
                        throw new LogicException('A cluster client needs a node address');
                    }
                    ScriptLogger::log($client, 'rawCommand', [$node, ...$arguments]);
                    $reply = $client->rawCommand($node, ...$arguments);
                }

                $this->log(sprintf(
                    '%s <- %s => %s',
                    $this->describe($client, $node),
                    implode(' ', $arguments),
                    is_string($reply) ? $reply : var_export($reply, true),
                ));

                return $reply;
            }

            private function atomic(Redis|RedisCluster|Relay\Relay|Relay\Cluster $client): bool
            {
                return $client->getMode() === Redis::ATOMIC;
            }

            private function cluster(Redis|RedisCluster|Relay\Relay|Relay\Cluster $client): bool
            {
                return $client instanceof RedisCluster || $client instanceof Relay\Cluster;
            }

            /** @param array{string, int}|null $node */
            private function describe(Redis|RedisCluster|Relay\Relay|Relay\Cluster $client, ?array $node): string
            {
                return $node === null ? $client::class : sprintf('%s %s:%d', $client::class, $node[0], $node[1]);
            }

            private function log(string $message): void
            {
                if ($this->verbose) {
                    fwrite(STDERR, "evilresp: {$message}\n");
                }
            }
        },
    );
};
