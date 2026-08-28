<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

use Mgrunder\PhpredisCommandFuzzer\Commands\Command;
use Mgrunder\PhpredisCommandFuzzer\Commands\Registry;
use Mgrunder\PhpredisCommandFuzzer\Coverage\ServerCommands;

/** @internal */
final class ProblematicCommandDetector
{
    /**
     * @param array<string, array{count: int, replies: array<string, int>, exceptions: array<string, int>}> $results
     * @param array<string, array<int, true>> $falseReplyClients
     * @param array<int, ServerCommands|null> $serverCommands
     * @return array<string, array{executions: int, false_replies: int}>
     */
    public function detect(
        array $results,
        array $falseReplyClients,
        array $serverCommands,
        Registry $registry,
    ): array {
        $problematic = [];
        foreach ($results as $name => $statistics) {
            if ($statistics['replies'] === []
                || array_keys($statistics['replies']) !== ['false']) {
                continue;
            }

            $command = $registry->get($name);
            if ($command === null) {
                throw new \LogicException("Result command is missing from registry: {$name}");
            }

            /* False is a documented terminal reply for the cursor-based scan
               APIs, so executing them still counts as useful reply coverage. */
            if (($command->flags() & Command::SCAN) !== 0) {
                continue;
            }

            $clientIds = array_keys($falseReplyClients[$name] ?? []);
            if ($clientIds === []) {
                continue;
            }

            foreach ($clientIds as $clientId) {
                $available = $serverCommands[$clientId] ?? null;
                if ($available === null || !$available->has($name)) {
                    continue 2;
                }
            }

            $problematic[$name] = [
                'executions' => $statistics['count'],
                'false_replies' => $statistics['replies']['false'],
            ];
        }
        ksort($problematic);

        return $problematic;
    }
}
