<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

use Relay\Relay;

final class RelayStatsCollector
{
    private int $samples = 0;

    private int $peakActive = 0;

    private int $peakUsed = 0;

    /** @var array{hits: int, misses: int, oom: int, memory: array{total: int, limit: int, active: int, used: int}}|null */
    private ?array $latest = null;

    /** @param \Closure(): array<mixed> $reader */
    public function __construct(private readonly \Closure $reader)
    {
    }

    public static function forRelay(): self
    {
        return new self(static fn (): array => Relay::stats());
    }

    public function sample(): void
    {
        $snapshot = ($this->reader)();
        $stats = $this->section($snapshot, 'stats');
        $memory = $this->section($snapshot, 'memory');

        $this->latest = [
            'hits' => $this->integer($stats, 'hits'),
            'misses' => $this->integer($stats, 'misses'),
            'oom' => $this->integer($stats, 'oom'),
            'memory' => [
                'total' => $this->integer($memory, 'total'),
                'limit' => $this->integer($memory, 'limit'),
                'active' => $this->integer($memory, 'active'),
                'used' => $this->integer($memory, 'used'),
            ],
        ];
        $this->samples++;
        $this->peakActive = max($this->peakActive, $this->latest['memory']['active']);
        $this->peakUsed = max($this->peakUsed, $this->latest['memory']['used']);
    }

    /**
     * @return array{
     *     samples: int,
     *     hits: int,
     *     misses: int,
     *     oom: int,
     *     memory: array{total: int, limit: int, active: int, used: int, peak_active: int, peak_used: int}
     * }|null
     */
    public function statistics(): ?array
    {
        if ($this->latest === null) {
            return null;
        }

        return [
            'samples' => $this->samples,
            'hits' => $this->latest['hits'],
            'misses' => $this->latest['misses'],
            'oom' => $this->latest['oom'],
            'memory' => [
                ...$this->latest['memory'],
                'peak_active' => $this->peakActive,
                'peak_used' => $this->peakUsed,
            ],
        ];
    }

    /**
     * @param array<mixed> $snapshot
     * @return array<mixed>
     */
    private function section(array $snapshot, string $name): array
    {
        $section = $snapshot[$name] ?? null;
        if (!is_array($section)) {
            throw new \UnexpectedValueException("Relay stats are missing the {$name} section");
        }

        return $section;
    }

    /** @param array<mixed> $section */
    private function integer(array $section, string $name): int
    {
        $value = $section[$name] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException("Relay stats field {$name} is not an integer");
        }

        return $value;
    }
}
