<?php

namespace Mgrunder\PhpredisCommandFuzzer\Commands;

use Redis;
use RedisCluster;
use Relay\Relay;
use Relay\Cluster;

abstract class ScanCommand extends Command implements FuzzInterface {
    private int $cursor = 0;
    protected ?string $key = null;
    protected ?string $type = null;
    protected ?string $pattern = null;

    abstract protected function initArgs(FuzzConfig $config): void;

    public function flags(): int {
        return self::READ | self::SCAN;
    }

    private function resetValues(): void {
        $this->cursor = 0;
        $this->key = null;
        $this->type = null;
        $this->pattern = null;
    }

    public function fuzz(Redis|RedisCluster|Relay|Cluster $client,
                         FuzzConfig $config): mixed
    {
        try {
            $result = false;

            if ($client->getMode() != Redis::ATOMIC)
                return $result;

            /* Are we back to zero?  Reset random arguments */
            if ($this->cursor === 0) {
                $this->resetValues();
                $this->initArgs($config);
            }

            $count = rand(0, $config->getCmdMaxKeys());
            $fn = $this->name();

            /* We can't use the normal $this->exec indirection here because the
               scan cursor is sent as a reference. */
            if ($this->key) {
                $result = $client->$fn($this->key, $this->cursor, $this->pattern,
                                       $count);
            } else if ($client instanceOf Redis || $client instanceOf Relay) {
                $result = $client->$fn($this->cursor, $this->pattern, $count,
                                       $this->type);
            } else {
                $result = $client->$fn($this->cursor, $config->getRandomKey(self::ANY), $this->pattern,
                                       $count, $this->type);
            }
        } catch (\Exception $ex) {
            $client->clearLastError();
        }

        return $result;
    }
}
