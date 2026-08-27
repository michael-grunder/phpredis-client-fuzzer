<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;


use Mgrunder\PhpredisCommandFuzzer\Utilities;

use Relay\Relay;
use Relay\Cluster;
use Redis;
use RedisCluster;

class Stats {
    /* Snapshot values for stats aggregation */
    private ?int $tx = null;
    private ?int $rx = null;
    private ?int $t1 = null;

    /**
     * @var array<string, Totals>
     */
    private array $stats  = [];

    public function __construct() {
    }

    public function increment(string $name, mixed $result, int $tx, int $rx, int $nanos): void {
        if ($tx == 0)
            return;

        $totals = ($this->stats[$name] ??= new Totals);
        $totals->increment($result, $tx, $rx, $nanos);
    }

    /** @return array{0: int, 1: int} */
    public static function getBytes(Redis|RedisCluster|Relay|Cluster $client): array {
        static $cmds = null;

        try {
            if (method_exists($client, 'getTransferredBytes')) {
                $txrx = $client->getTransferredBytes();
            } else if (method_exists($client, 'getBytes')) {
                $txrx = $client->getBytes();
            } else {
                return [0, 0];
            }
        } catch (\Exception $ex) {
            return [0, 0];
        }

        if (!is_array($txrx) || count($txrx) != 2 || ! is_int($txrx[0]) || ! is_int($txrx[1])) {
            return [0, 0];
        }

        return $txrx;
    }

    public function totalsLine(Redis|RedisCluster|Relay|Cluster|null $client = null): string {
        $totals = Totals::sum($this->stats);

        return sprintf("CMDS: %s, TX/RX: %s/%s",
                       number_format($totals->count()),
                       Utilities::bytesToSize($totals->tx()),
                       Utilities::bytesToSize($totals->rx()));
    }

    public function start(Redis|RedisCluster|Relay|Cluster $client): void {
        [$this->tx, $this->rx] = self::getBytes($client);
        $this->t1 = hrtime(true);
    }

    public function stop(Redis|RedisCluster|Relay|Cluster $client, Command $cmd,
                               mixed $result): int
    {
        assert($this->tx !== null && $this->rx !== null && $this->t1 !== null);

        [$t2, [$tx2, $rx2]] = [hrtime(true), self::getBytes($client)];

        $this->increment($cmd->name(), $result, $tx2 - $this->tx,
                         $rx2 - $this->rx, $t2 - $this->t1);

        $this->tx = $this->rx = $this->t1 = null;

        return $t2;
    }

    public function tick(): int {
        assert($this->t1 !== null);
        return $this->t1;
    }

    /**
     * @return Totals
     */
    public function totals(): Totals {
        return Totals::sum($this->stats);
    }

    /**
     * @return array<string, Totals>
     */
    public function stats(): array {
        return $this->stats;
    }
}
