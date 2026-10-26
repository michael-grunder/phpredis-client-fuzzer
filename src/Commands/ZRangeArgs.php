<?php

declare(strict_types=1);

namespace Mgrunder\RedisClientTester\Commands;

class ZRangeArgs
{
    private string $by;
    private int|string $start;
    private int|string $end;
    private bool $rev;
    private bool $withScores;
    private ?int $offset = null;
    private int $limit = -1;

    public function __construct(FuzzConfig $config, bool $allowWithScores = true)
    {
        $this->by = ['BYRANK', 'BYSCORE', 'BYLEX'][rand(0, 2)];
        [$this->start, $this->end] = match ($this->by) {
            'BYRANK' => $config->randomRange(),
            'BYSCORE' => ZSetRange::scores($config),
            'BYLEX' => ZSetRange::lex($config),
        };
        $this->rev = (bool) rand(0, 1);
        // REV changes the rank traversal, not the order of rank indexes.
        if ($this->rev && $this->by !== 'BYRANK') {
            [$this->start, $this->end] = [$this->end, $this->start];
        }
        $this->withScores = $allowWithScores && (bool) rand(0, 1);
        if ($this->by !== 'BYRANK' && rand(0, 1)) {
            $this->offset = rand(0, $config->getMembers());
            $this->limit = rand(-1, $config->getMembers());
        }
    }

    /** @return array<int|string, string|bool|array{int, int}> */
    public function options(): array
    {
        $result = [];
        if ($this->by !== 'BYRANK') {
            $result[] = $this->by;
        }
        if ($this->rev) {
            $result[] = 'REV';
        }
        if ($this->withScores) {
            $result['WITHSCORES'] = true;
        }
        if ($this->offset !== null) {
            $result['LIMIT'] = [$this->offset, $this->limit];
        }

        return $result;
    }

    /** @return list<int|string> */
    public function optionsRaw(): array
    {
        $result = [];
        if ($this->by !== 'BYRANK') {
            $result[] = $this->by;
        }
        if ($this->rev) {
            $result[] = 'REV';
        }
        if ($this->offset !== null) {
            array_push($result, 'LIMIT', $this->offset, $this->limit);
        }
        if ($this->withScores) {
            $result[] = 'WITHSCORES';
        }

        return $result;
    }

    public function start(): int|string
    {
        return $this->start;
    }

    public function end(): int|string
    {
        return $this->end;
    }
}
