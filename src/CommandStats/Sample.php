<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\CommandStats;

final readonly class Sample
{
    /**
     * @param list<Reading> $readings
     * @param list<string> $errors
     */
    public function __construct(
        public array $readings,
        public array $errors = [],
    ) {
    }

    public function nodes(NodeRole $role): int
    {
        return count(array_filter(
            $this->readings,
            static fn (Reading $reading): bool => $reading->node->role === $role,
        ));
    }
}
