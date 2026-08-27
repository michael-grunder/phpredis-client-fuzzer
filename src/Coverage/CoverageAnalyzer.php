<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Coverage;

use Mgrunder\PhpredisCommandFuzzer\ClientType;
use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\Registry;

/**
 * Compares a server command table against the concrete catalog in
 * src/Commands/Command/ to find commands the fuzzer does not exercise.
 *
 * Uncovered commands are split by method_exists() on the selected client class
 * so a real gap in the catalog is not confused with a command the client has no
 * API for at all.
 */
final class CoverageAnalyzer
{
    public function analyse(
        ServerCommands $server,
        ClientType $clientType,
        ?Registry $registry = null,
        ?IgnoreList $ignore = null,
    ): CoverageReport {
        $registry ??= new Registry();
        $ignore ??= IgnoreList::defaults();

        $class = $clientType->className();
        if (!class_exists($class)) {
            throw new \RuntimeException("Redis client class {$class} is not available");
        }

        $catalog = $registry->commands();

        $covered = [];
        $missing = [];
        $unsupported = [];
        $ignored = [];

        foreach ($server->names as $name) {
            if (isset($catalog[$name])) {
                $covered[] = $name;
                continue;
            }

            $pattern = $ignore->match($name);
            if ($pattern !== null) {
                $ignored[$name] = $pattern;
                continue;
            }

            if (method_exists($class, $name)) {
                $missing[] = $name;
            } else {
                $unsupported[] = $name;
            }
        }

        $clientApi = [];
        $unmatched = [];

        foreach ($catalog as $name => $command) {
            if ($server->has($name)) {
                continue;
            }

            /* A catalog entry with no server command is a client-side API when
             * it says so, or when the client exposes it as a method anyway
             * (connect(), pipeline(), rawCommand(), _pack(), ...).  Anything
             * left is a command this server and this client do not share, such
             * as a Relay extension checked against PhpRedis or a command newer
             * than the target. */
            if (($command->flags() & Command::LOCAL) !== 0 || method_exists($class, $name)) {
                $clientApi[] = $name;
            } else {
                $unmatched[] = $name;
            }
        }

        sort($clientApi);
        sort($unmatched);
        ksort($ignored);

        return new CoverageReport(
            clientType: $clientType,
            clientClass: $class,
            serverCommands: $server->count(),
            catalogCommands: count($catalog),
            covered: $covered,
            missing: $missing,
            unsupported: $unsupported,
            ignored: $ignored,
            clientApi: $clientApi,
            unmatched: $unmatched,
        );
    }
}
