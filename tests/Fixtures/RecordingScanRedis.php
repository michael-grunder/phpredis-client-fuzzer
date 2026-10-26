<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Tests\Fixtures;

final class RecordingScanRedis extends RecordingRedis
{
    /** @var list<array{cursor: int|string|null, pattern: ?string, count: int}> */
    public array $scans = [];
    public int|string|null $nextCursor = 123;
    /** @var list<string>|false */
    public array|false $scanReply = [];
    public bool $throwOnScan = false;
    public ?string $scanError = null;

    public function getDBNum(): int
    {
        return 0;
    }

    public function getPort(): int
    {
        return 6379;
    }

    /** @return list<string>|false */
    public function scan(
        int|string|null &$iterator, ?string $pattern = null, int $count = 0, ?string $type = null,
    ): array|false {
        InvocationTrace::record($this->identity, __FUNCTION__, [$iterator, $pattern, $count, $type]);
        $this->scans[] = ['cursor' => $iterator, 'pattern' => $pattern, 'count' => $count];
        $iterator = $this->nextCursor;
        if ($this->throwOnScan) {
            throw new \TypeError('synthetic scan failure');
        }
        return $this->scanReply;
    }

    public function getLastError(): ?string
    {
        return $this->scanError ?? parent::getLastError();
    }

    public function clearLastError(): bool
    {
        $this->scanError = null;
        return parent::clearLastError();
    }
}
