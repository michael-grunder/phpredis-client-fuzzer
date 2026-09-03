<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\CommandStats;

final readonly class Reading
{
    /** @param array<string, int> $calls */
    public function __construct(
        public Node $node,
        public array $calls,
    ) {
    }
}
