<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Mgrunder\PhpredisCommandFuzzer\Utilities;


class Totals {
    private const REPLY_TEMPLATE = ['count' => 0, 'total' => 0];

    private int $count = 0;
    private int $nanos = 0;
    private int $tx    = 0;
    private int $rx    = 0;

    /** @var array<string, array{count: int, total: int|float}> $replies */
    private array $replies;

    /**
     * @return array{0: string, 1: int|float}
     */
    private function replyInfo(mixed $reply): array {
        if (is_bool($reply)) {
            return [$reply ? 'true' : 'false', $reply ? 1 : 0];
        } else if (is_int($reply)) {
            return ['integer', $reply];
        } else if (is_float($reply)) {
            return ['float', $reply];
        } else if (is_string($reply)) {
            return ['string', strlen($reply)];
        } else if (is_array($reply)) {
            return ['array', count($reply)];
        } else {
            return ['other', 0];
        }
    }

    public function increment(mixed $result, int $tx, int $rx, int $nanos): void {
        $this->count++;
        $this->tx += $tx;
        $this->rx += $rx;
        $this->nanos += $nanos;

        [$type, $len] = $this->replyInfo($result);

        $this->replies[$type] ??= self::REPLY_TEMPLATE;
        $this->replies[$type]['count'] += 1;
        $this->replies[$type]['total'] += $len;
    }

    public function add(Totals $other): self {
        $this->count += $other->count();
        $this->tx    += $other->tx();
        $this->rx    += $other->rx();
        $this->nanos += $other->nanos();

        foreach ($other->replies() as $type => $data) {
            $this->replies[$type] ??= self::REPLY_TEMPLATE;
            $this->replies[$type]['count'] += $data['count'];
            $this->replies[$type]['total'] += $data['total'];
        }

        return $this;
    }

    /**
     * @param array<Totals> $stats
     * @return Totals
     */
    public static function sum(array $stats): Totals {
        $result = new Totals;

        foreach ($stats as $totals)
            $result->add($totals);

        return $result;
    }

    public function summary(): string {
        $msg = sprintf("CMDS: %s, TX/RX: %s/%s",
                       number_format($this->count()),
                       Utilities::bytesToSize($this->tx()),
                       Utilities::bytesToSize($this->rx()));

        return $msg;
    }

    public function details(bool $with_count = false, bool $short_types = true): string {
        $lines = [];

        foreach ($this->replies as $type => ['count' => $count,
                                             'total' => $total])
        {
            //if ($short_types)
                //$type = $this->toTypeChar($type);
            $line = sprintf("%s: %s", $type, number_format($count));
            if ($with_count)
                $line .= sprintf(" (%s)", number_format($total));
            $lines[] = $line;
        }

        return implode(', ', $lines);
    }

    public function count(): int {
        return $this->count;
    }

    public function tx(): int {
        return $this->tx;
    }

    public function rx(): int {
        return $this->rx;
    }

    public function nanos(): int {
        return $this->nanos;
    }

    /**
     * @return array<string, array{count: int, total: int|float}>
     */
    public function replies(): array {
        return $this->replies;
    }
}
