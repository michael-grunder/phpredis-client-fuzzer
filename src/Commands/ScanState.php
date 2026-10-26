<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Commands;

/** @internal One client's iteration on one method/raw surface. */
final class ScanState
{
    /** @param list<mixed> $context */
    public function __construct(
        public readonly FuzzConfig $config,
        public readonly array $context,
        public readonly ?string $key,
        public readonly ?string $pattern,
        public readonly ?string $type,
        public readonly ?string $route,
        public int|string|null $cursor = null,
    ) {
    }
}
