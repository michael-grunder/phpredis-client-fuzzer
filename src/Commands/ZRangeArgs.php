<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\Commands\FuzzConfig;

class ZRangeArgs {
    private const BY = ['BYRANK' => 1, 'BYSCORE' => 1, 'BYLEX' => 1];

    private FuzzConfig $config;

    private string $by;

    private mixed $start;
    private mixed $end;

    private bool $rev;
    private ?int $offset = null;
    private ?int $limit = null;

    /** @return array{mixed, mixed} */
    private function randomRange(string $by): array {
        $range = match ($by) {
            'BYRANK' => $this->config->randomRange(),
            'BYSCORE' => [
                (rand() & 1) ? '-inf' : rand(-32768, 0),
                (rand() & 1) ? '+inf' : rand(0, 32768),
            ],
            'BYLEX' => $this->config->randomLexRange(),
            default => throw new \LogicException("Unknown range mode: {$by}"),
        };

        return [$range[0], $range[1]];
    }

    public function __construct(FuzzConfig $config) {
        $this->config = $config;

        $this->by = array_rand(self::BY);
        [$this->start, $this->end] = $this->randomRange($this->by);

        $rng = rand();

        $this->rev = (bool)($rng & 1);
        if ($this->rev) {
            [$this->start, $this->end] = [$this->end, $this->start];
        }

        if ($rng & 3) {
            $this->offset = rand(0, $config->getMembers());
            $this->limit = rand(0, $config->getMembers());
        }
    }

    /** @return list<string|array<string, array{int, int|null}>>|null */
    public function options(): ?array {
        $result = null;

        if ($this->by !== 'BYRANK')
            $result[] = $this->by;
        if ($this->rev)
            $result[] = 'REV';
        if ($this->offset !== null)
            $result[] = ['LIMIT' => [$this->offset, $this->limit]];

        return $result;
    }

    /** @return list<int|string|null> */
    public function optionsRaw(): array {
        $result = [];

        if ($this->by !== 'BYRANK')
            $result[] = $this->by;

        if ($this->rev)
            $result[] = 'REV';

        if ($this->offset !== null) {
            $result[] = 'LIMIT';
            $result[] = $this->offset;
            $result[] = $this->limit;
        }

        return $result;
    }

    public function start(): mixed {
        return $this->start;
    }

    public function end(): mixed {
        return $this->end;
    }
}
