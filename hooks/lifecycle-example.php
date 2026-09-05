<?php

declare(strict_types=1);

/**
 * Lifecycle hook example.
 *
 *     bin/phpredis-fuzz --include=local --steps=50 \
 *         --hook=hooks/lifecycle-example.php
 *
 * Lifecycle hooks only observe: unlike an invocation hook they cannot reject
 * or rewrite a call, so loading this file never changes which commands run.
 * Everything it does is cheap enough to leave on; the noisier variants are
 * commented out below rather than removed.
 *
 * The active hooks below print one line per client and one summary line per
 * client at the end of the run. See hooks/no-msgpack.php for the invocation
 * hook form, which can live in the same file as these.
 */

use Mgrunder\PhpredisCommandFuzzer\Hooks\ClientEvent;
use Mgrunder\PhpredisCommandFuzzer\Hooks\CompletedInvocation;
use Mgrunder\PhpredisCommandFuzzer\Hooks\HookRegistry;
use Mgrunder\PhpredisCommandFuzzer\Hooks\PendingInvocation;

return static function (HookRegistry $hooks): void {
    /* Lifecycle hooks are the natural place to accumulate state across a run.
       Keep it here in the hook file: the fuzzer never reads it. */
    $stats = new class {
        public int $commands = 0;
        public int $failures = 0;
        public float $slowestSeconds = 0.0;
        public string $slowestMethod = '';
    };

    /* postConstructor: once per client object, before the fuzzer uses it. PHP
       already tells you everything about the client, so the hook decides for
       itself what a Relay cluster or a PhpRedis standalone means to it. */
    $hooks->onPostConstructor(
        'example-client-report',
        static function (ClientEvent $event) use ($stats): void {
            $client = $event->client;

            /* instanceof is safe against a class the running PHP does not
               have: a PhpRedis-only build simply never matches Relay. */
            $extension = $client instanceof \Relay\Relay || $client instanceof \Relay\Cluster
                ? 'relay'
                : 'phpredis';
            $topology = $client instanceof \RedisCluster || $client instanceof \Relay\Cluster
                ? 'cluster'
                : 'standalone';

            /* ClientEvent::isRelay() and ::isCluster() answer the same two
               questions if you would rather not repeat the instanceof list:
               $extension = $event->isRelay() ? 'relay' : 'phpredis';
               $topology = $event->isCluster() ? 'cluster' : 'standalone'; */

            fwrite(STDERR, sprintf(
                "[hook] client %d %s (%s, %s) serializer=%s compression=%s\n",
                $event->clientIndex ?? -1,
                $client::class,
                $extension,
                $topology,
                var_export($client->getOption(Redis::OPT_SERIALIZER), true),
                var_export($client->getOption(Redis::OPT_COMPRESSION), true),
            ));

            /* The client is fully constructed and connected here, so it can be
               configured or inspected before the workload starts. Anything set
               here is part of the run, so record it with the reproduction:

               $client->setOption(Redis::OPT_PREFIX, 'hooked:');
               $client->setOption(Redis::OPT_MAX_RETRIES, 0);

               Relay-only surfaces still need a capability check:

               if ($client instanceof \Relay\Relay
                   && defined('Relay\Relay::OPT_USE_CACHE')) {
                   $client->setOption(Relay\Relay::OPT_USE_CACHE, true);
               } */
        },
    );

    /* preCommand: the final arguments, after generated ScriptArg values are
       resolved and after any invocation hook replaced them. A rejected
       invocation never reaches this event. */
    $hooks->onPreCommand(
        'example-command-counter',
        static function (PendingInvocation $invocation) use ($stats): void {
            $stats->commands++;

            /* One line per call is far too noisy for a real run, but it is the
               quickest way to see what the fuzzer is about to do:

               fwrite(STDERR, sprintf(
                   "[hook] -> %s::%s(%s)\n",
                   $invocation->command,
                   $invocation->method,
                   implode(', ', array_map(
                       static fn (mixed $argument): string => get_debug_type($argument),
                       $invocation->arguments,
                   )),
               ));

               Watching one command is cheaper than watching all of them:

               if ($invocation->command === 'setoption') {
                   fwrite(STDERR, var_export($invocation->arguments, true) . "\n");
               } */
        },
    );

    /* postCommand: the same call once the client returned or threw. Exactly
       one of $result and $throwable is meaningful. */
    $hooks->onPostCommand(
        'example-slow-and-failed',
        static function (CompletedInvocation $invocation) use ($stats): void {
            /* $invocation->succeeded() answers the same question; reading
               the throwable directly keeps it non-null below. */
            $throwable = $invocation->throwable;
            if ($throwable !== null) {
                $stats->failures++;
                fwrite(STDERR, sprintf(
                    "[hook] %s::%s threw %s: %s\n",
                    $invocation->command,
                    $invocation->method,
                    $throwable::class,
                    $throwable->getMessage(),
                ));

                return;
            }

            if ($invocation->durationSeconds > $stats->slowestSeconds) {
                $stats->slowestSeconds = $invocation->durationSeconds;
                $stats->slowestMethod = $invocation->command . '::' . $invocation->method;
            }

            /* The reply is handed over exactly as the client returned it, so
               this is the place to assert on client behavior the catalog does
               not check. Throwing here aborts the run with HookExecutionFailed
               naming this hook, which is usually what you want from a check:

               if ($invocation->command === 'ping' && $invocation->result === false) {
                   throw new RuntimeException('PING returned false');
               } */
        },
    );

    /* preDestructor: the fuzzer is finished with the client. This is not a PHP
       destructor; the client is still connected and fully usable. */
    $hooks->onPreDestructor(
        'example-run-summary',
        static function (ClientEvent $event) use ($stats): void {
            fwrite(STDERR, sprintf(
                "[hook] done with client %d %s: %d commands, %d failures, slowest %s (%.6fs)\n",
                $event->clientIndex ?? -1,
                $event->client::class,
                $stats->commands,
                $stats->failures,
                $stats->slowestMethod === '' ? 'none' : $stats->slowestMethod,
                $stats->slowestSeconds,
            ));

            /* Commands issued here are not counted in the run report, which
               makes this the place for a final look at the server or client:

               $client = $event->client;
               if ($client instanceof Redis || $client instanceof \Relay\Relay) {
                   fwrite(STDERR, var_export($client->info('memory'), true) . "\n");
               }

               if ($client instanceof \Relay\Relay || $client instanceof \Relay\Cluster) {
                   fwrite(STDERR, var_export($client->stats(), true) . "\n");
               }

               ...or for cleaning up whatever the workload left behind, on a
               disposable target only:

               $client->flushdb(); */
        },
    );

    /* One object can carry several events instead of separate closures. It is
       registered under a single id with addLifecycleHook(), and may implement
       any combination of the four interfaces (and InvocationHook too):

       $hooks->addLifecycleHook('example-object', new class implements
           PostConstructorHook, PostCommandHook {
           public function postConstructor(ClientEvent $event): void
           {
               // ...
           }

           public function postCommand(CompletedInvocation $invocation): void
           {
               // ...
           }
       }); */
};
